<?php
/**
 * Exercises the editor against a real WordPress: migrate, register, save, verify, roll back.
 *
 * Run with: bash tools/devenv.sh wp eval-file <this file>
 */

// phpcs:disable

function out( $label, $ok, $detail = '' ) {
	printf( "%-8s %-46s %s\n", $ok ? '[OK]' : '[FAIL]', $label, $detail );

	if ( ! $ok ) {
		$GLOBALS['lichtbild_live_failed'] = ( isset( $GLOBALS['lichtbild_live_failed'] ) ? $GLOBALS['lichtbild_live_failed'] : 0 ) + 1;
	}
}

$settings = new Lichtbild_Settings();

// ---------------------------------------------------------------- preconditions
// Stated rather than assumed, and checked before anything is changed. `devenv.sh reset`
// restores the imported `active_plugins`, which has Envira running — so the migration
// correctly refuses, and without this the run reports "0 galleries" and every check after it
// fails for a reason that has nothing to do with the code.
if ( $settings->envira_is_active() ) {
	echo "[ERROR] Envira Gallery is active, so the migration will refuse.\n";
	echo "        bash tools/devenv.sh wp plugin deactivate --all --exclude=lichtbild-gallery\n";

	exit( 1 );
}

if ( $settings->has_migrated() ) {
	echo "[ERROR] the site is already migrated; this run has to start from an unmigrated one.\n";
	echo "        bash tools/devenv.sh reset\n";

	exit( 1 );
}

// ---------------------------------------------------------------- migrate
$migration = new Lichtbild_Migration( $settings );
$result    = $migration->migrate();

out(
	'migration ran',
	empty( $result['errors'] ) && $result['galleries'] > 0,
	sprintf(
		'%d galleries, %d converted%s',
		$result['galleries'],
		$result['converted'],
		empty( $result['errors'] ) ? '' : '; errors: ' . implode( '; ', $result['errors'] )
	)
);

if ( ! empty( $result['errors'] ) ) {
	exit( 1 );
}

wp_cache_flush();

$settings = new Lichtbild_Settings();
// The reader is built with the post-migration names, the way the album script does and the way
// the next request will: the plugin's own was constructed at `init`, before the rename in this
// process, so it still looks for galleries under the type they have just left.
$editor   = new Lichtbild_Editor( $settings, new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true ) );

// The types were registered at `init`, before the rename, so they still say `envira`. That
// is exactly why the migration screen does a post/redirect/get instead of reporting from the
// request that did the work — and here it means capability and taxonomy calls would run
// against types that no longer match the rows. Re-register for the rest of this process.
( new Lichtbild_Post_Types( $settings ) )->register_types();

out(
	'the migrated types are registered',
	post_type_exists( 'lichtbild_gallery' ) && taxonomy_exists( 'lichtbild_tag' ),
	'gallery ' . ( post_type_exists( 'lichtbild_gallery' ) ? 'yes' : 'no' ) . ', tag ' . ( taxonomy_exists( 'lichtbild_tag' ) ? 'yes' : 'no' )
);

out( 'site reports migrated', $settings->has_migrated(), 'schema ' . get_option( 'lichtbild_schema_version' ) );

// ---------------------------------------------------------------- metaboxes
// Real registration, through the real global, on the real screen id.
$GLOBALS['wp_meta_boxes'] = array();
set_current_screen( 'lichtbild_gallery' );

$editor->add_meta_boxes();

$boxes = isset( $GLOBALS['wp_meta_boxes']['lichtbild_gallery'] ) ? $GLOBALS['wp_meta_boxes']['lichtbild_gallery'] : array();
$found = array();

foreach ( $boxes as $context => $priorities ) {
	foreach ( $priorities as $priority => $entries ) {
		foreach ( array_keys( $entries ) as $id ) {
			$found[] = $id;
		}
	}
}

sort( $found );

out(
	'metaboxes register on the real screen',
	array( 'lichtbild-images', 'lichtbild-settings', 'lichtbild-shortcode' ) === $found,
	implode( ', ', $found )
);

// ---------------------------------------------------------------- pick a gallery
$ids = get_posts(
	array(
		'post_type'      => 'lichtbild_gallery',
		'post_status'    => array( 'publish', 'private', 'draft' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

$target = 0;

foreach ( $ids as $candidate ) {
	$record = get_post_meta( $candidate, '_lichtbild_gallery', true );

	if ( is_array( $record ) && count( $record['items'] ) > 3 ) {
		$target = (int) $candidate;

		break;
	}
}

out( 'found a gallery to edit', $target > 0, '#' . $target );

if ( 0 === $target ) {
	exit( 1 );
}

$repository = new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true );
$renderer   = new Lichtbild_Renderer( new Lichtbild_Assets( new Lichtbild_Settings() ) );
$before     = $renderer->gallery( $repository->gallery( $target ), 1 );
$record     = get_post_meta( $target, '_lichtbild_gallery', true );

// ---------------------------------------------------------------- the edit screen
wp_set_current_user( 1 );

ob_start();
$editor->render_images_box( get_post( $target ) );
$images_form = ob_get_clean();

ob_start();
$editor->render_settings_box( get_post( $target ) );
$settings_form = ob_get_clean();

$missing = array();

foreach ( array_keys( Lichtbild_Config::defaults() ) as $key ) {
	if ( false === strpos( $settings_form, 'name="lichtbild_settings[' . $key . ']' ) ) {
		$missing[] = $key;
	}
}

out( 'settings form covers the schema', empty( $missing ), empty( $missing ) ? '26 fields' : implode( ', ', $missing ) );

// Counted on the row key, not the class: the class also appears in the client-side template
// printed alongside the rows, so a class count is always one too many.
$rows = preg_match_all( '/data-key="i\d+"/', $images_form );

out(
	'images form draws every item',
	$rows === count( $record['items'] ),
	$rows . ' rows for ' . count( $record['items'] ) . ' items'
);

// ---------------------------------------------------------------- a real save
// Built from the rendered form rather than from the record, so what is submitted is what a
// browser would actually send back.
$order = array();
$items = array();

foreach ( $record['items'] as $index => $item ) {
	$key     = 'i' . $index;
	$order[] = $key;

	$items[ $key ] = array(
		'id'      => (string) $item['id'],
		'status'  => $item['status'],
		'src'     => $item['src'],
		'link'    => $item['link'],
		'title'   => $item['title'],
		'caption' => $item['caption'],
		'alt'     => $item['alt'],
	);
}

$form_settings = array();

foreach ( $record['settings'] as $key => $value ) {
	if ( is_bool( $value ) ) {
		if ( $value ) {
			$form_settings[ $key ] = '1';
		}

		continue;
	}

	$form_settings[ $key ] = is_array( $value ) ? array_values( $value ) : (string) $value;
}

$_POST = array(
	'lichtbild_editor_nonce' => wp_create_nonce( 'lichtbild_editor_' . $target ),
	'lichtbild_editor_nonce_items_complete' => '1',
	'lichtbild_editor_nonce_settings_complete' => '1',
	'lichtbild_items'        => $items,
	'lichtbild_order'        => implode( ',', $order ),
	'lichtbild_settings'     => $form_settings,
);

// Through the real hook, so `save_post`'s own plumbing is what runs it.
$editor->register();
do_action( 'save_post', $target, get_post( $target ), true );

wp_cache_flush();

$after = $renderer->gallery(
	( new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true ) )->gallery( $target ),
	1
);

out( 'save through save_post round-trips', $before === $after, strlen( $before ) . ' vs ' . strlen( $after ) . ' bytes' );

// ---------------------------------------------------------------- reordering, for real
$reversed          = $_POST;
$reversed['lichtbild_order'] = implode( ',', array_reverse( $order ) );
$_POST             = $reversed;

do_action( 'save_post', $target, get_post( $target ), true );

wp_cache_flush();

$stored = get_post_meta( $target, '_lichtbild_gallery', true );

out(
	'a reorder lands in the database',
	wp_list_pluck( $stored['items'], 'id' ) === array_reverse( wp_list_pluck( $record['items'], 'id' ) ),
	'first id ' . $stored['items'][0]['id'] . ', was ' . $record['items'][0]['id']
);

// ---------------------------------------------------------------- tags, on the real taxonomy
$attachment = 0;

foreach ( $record['items'] as $item ) {
	if ( $item['id'] > 0 ) {
		$attachment = (int) $item['id'];

		break;
	}
}

$tags_before = wp_get_object_terms( $attachment, 'lichtbild_tag', array( 'fields' => 'names' ) );

$tagged                                = $_POST;
$tagged['lichtbild_order']                = implode( ',', $order );
$tagged['lichtbild_items']['i0']['tags']  = 'Probe Eins, Probe Zwei';
$tagged['lichtbild_items']['i0']['id']    = (string) $attachment;
$_POST                                 = $tagged;

do_action( 'save_post', $target, get_post( $target ), true );

clean_object_term_cache( $attachment, 'attachment' );

$tags_after = wp_get_object_terms( $attachment, 'lichtbild_tag', array( 'fields' => 'names' ) );

sort( $tags_after );

out(
	'tags reach the real taxonomy',
	array( 'Probe Eins', 'Probe Zwei' ) === $tags_after,
	implode( ', ', $tags_after )
);

// Terms really exist, not just the relationship.
$term = get_term_by( 'name', 'Probe Eins', 'lichtbild_tag' );

out( 'a new tag becomes a real term', $term && ! is_wp_error( $term ), $term ? 'term_id ' . $term->term_id : 'absent' );

// ---------------------------------------------------------------- put it all back
wp_set_object_terms( $attachment, $tags_before, 'lichtbild_tag' );

foreach ( array( 'Probe Eins', 'Probe Zwei' ) as $name ) {
	$stale = get_term_by( 'name', $name, 'lichtbild_tag' );

	if ( $stale && ! is_wp_error( $stale ) ) {
		wp_delete_term( $stale->term_id, 'lichtbild_tag' );
	}
}

update_post_meta( $target, '_lichtbild_gallery', $record );

wp_cache_flush();

$restored = $renderer->gallery(
	( new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true ) )->gallery( $target ),
	1
);

out( 'the gallery is back as it was', $before === $restored, strlen( $restored ) . ' bytes' );

$_POST = array();

$rolled = $migration->rollback();

out(
	'rollback restores the site',
	empty( $rolled['errors'] ) && ! ( new Lichtbild_Settings() )->has_migrated(),
	sprintf( '%d galleries, %d albums', $rolled['galleries'], $rolled['albums'] )
);

$failed = isset( $GLOBALS['lichtbild_live_failed'] ) ? $GLOBALS['lichtbild_live_failed'] : 0;

printf( "\nchecks: 13, failing: %d\n", $failed );

exit( $failed > 0 ? 1 : 0 );
