<?php
/**
 * Exercises the album editor against a real WordPress: migrate, register, save, verify, roll back.
 *
 * Run with: bash tools/devenv.sh wp eval-file <this file>
 *
 * The stub suite proves the album editor's logic; this proves the parts a stub cannot model —
 * real `save_post` plumbing, the real metabox globals, the real post-type registration the
 * migration renames underneath, and a real `$wpdb` holding the site's own albums.
 */

// phpcs:disable

function out( $label, $ok, $detail = '' ) {
	printf( "%-8s %-50s %s\n", $ok ? '[OK]' : '[FAIL]', $label, $detail );

	if ( ! $ok ) {
		$GLOBALS['lichtbild_live_failed'] = ( isset( $GLOBALS['lichtbild_live_failed'] ) ? $GLOBALS['lichtbild_live_failed'] : 0 ) + 1;
	}
}

$settings = new Lichtbild_Settings();

// ---------------------------------------------------------------- preconditions
// Checked before anything is changed. `devenv.sh reset` restores the imported
// `active_plugins`, which has Envira running — so the migration correctly refuses, and every
// check after it then fails for a reason that has nothing to do with the code.
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
	empty( $result['errors'] ) && $result['albums'] > 0,
	sprintf(
		'%d albums, %d converted%s',
		$result['albums'],
		$result['albums_converted'],
		empty( $result['errors'] ) ? '' : '; errors: ' . implode( '; ', $result['errors'] )
	)
);

if ( ! empty( $result['errors'] ) ) {
	exit( 1 );
}

wp_cache_flush();

$settings = new Lichtbild_Settings();

// Registered at `init`, before the rename, so the types still say `envira_album`. That is why
// the migration screen does a post/redirect/get; here it means every capability check would
// run against a type no row is under.
( new Lichtbild_Post_Types( $settings ) )->register_types();

out(
	'the migrated album type is registered',
	post_type_exists( 'lichtbild_album' ),
	post_type_exists( 'lichtbild_album' ) ? 'lichtbild_album' : 'absent'
);

$repository   = new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true );
$renderer     = new Lichtbild_Renderer( new Lichtbild_Assets( $settings ) );
$album_editor = new Lichtbild_Album_Editor( $settings, $repository );

// ---------------------------------------------------------------- metaboxes
$GLOBALS['wp_meta_boxes'] = array();
set_current_screen( 'lichtbild_album' );

$album_editor->add_meta_boxes();

$boxes = isset( $GLOBALS['wp_meta_boxes']['lichtbild_album'] ) ? $GLOBALS['wp_meta_boxes']['lichtbild_album'] : array();
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
	array( 'lichtbild-album-galleries', 'lichtbild-album-settings', 'lichtbild-album-shortcode' ) === $found,
	implode( ', ', $found )
);

// ---------------------------------------------------------------- pick an album
$ids = get_posts(
	array(
		'post_type'      => 'lichtbild_album',
		'post_status'    => array( 'publish', 'private', 'draft' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

$target = 0;

foreach ( $ids as $candidate ) {
	$record = get_post_meta( $candidate, '_lichtbild_album', true );

	if ( is_array( $record ) && ! empty( $record['items'] ) ) {
		$target = (int) $candidate;

		break;
	}
}

out( 'found an album to edit', $target > 0, '#' . $target );

if ( 0 === $target ) {
	exit( 1 );
}

$record = get_post_meta( $target, '_lichtbild_album', true );
$before = $renderer->album( $repository->album( $target ), $repository );

// ---------------------------------------------------------------- the edit screen
wp_set_current_user( 1 );

ob_start();
$album_editor->render_galleries_box( get_post( $target ) );
$galleries_form = ob_get_clean();

ob_start();
$album_editor->render_settings_box( get_post( $target ) );
$settings_form = ob_get_clean();

$missing = array();

foreach ( array_keys( Lichtbild_Album_Config::defaults() ) as $key ) {
	if ( false === strpos( $settings_form, 'name="lichtbild_album_settings[' . $key . ']' ) ) {
		$missing[] = $key;
	}
}

out( 'settings form covers the schema', empty( $missing ), empty( $missing ) ? '3 fields' : implode( ', ', $missing ) );

// Counted on the row key rather than the class, because the class also appears in the
// client-side template printed alongside the rows.
$rows = preg_match_all( '/data-key="i\d+"/', $galleries_form );

out(
	'galleries form draws every member',
	$rows === count( $record['items'] ),
	$rows . ' rows for ' . count( $record['items'] ) . ' members'
);

// The picker is a real `get_posts` against the renamed post type. Counted inside its own
// select: every member row carries a cover chooser too, so a count over the whole form is
// dominated by images and stays high however empty the picker is.
//
// And counted as the *reader* counts them, not as the query does. Envira keeps its site-wide
// defaults in a gallery of its own and the migration renames that row like any other, so a raw
// row count includes a pseudo-gallery the picker must not offer -- and so does any empty row.
$gallery_rows = get_posts(
	array(
		'post_type'      => 'lichtbild_gallery',
		'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

$reader        = new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true );
$gallery_count = 0;

foreach ( $gallery_rows as $row_id ) {
	if ( null !== $reader->gallery( (int) $row_id ) ) {
		$gallery_count++;
	}
}

$opens   = strpos( $galleries_form, '<select id="lichtbild-album-add">' );
$offered = false === $opens
	? 0
	: substr_count( substr( $galleries_form, $opens, strpos( $galleries_form, '</select>', $opens ) - $opens ), '<option value="' );

out(
	'the picker lists the renamed galleries',
	$offered > 0 && $offered === $gallery_count,
	$offered . ' offered for ' . $gallery_count . ' real galleries of ' . count( $gallery_rows ) . ' rows'
);

// And never Envira's defaults row, by name. A count alone is satisfied by excluding the wrong
// one, and on this database the defaults row happens to be absent -- Envira deletes its own
// stored defaults when its addons are bulk-deactivated -- so the count check would pass here
// even with the guard removed. The live site does have one.
out(
	'the picker never offers the envira defaults row',
	false === strpos( $galleries_form, 'Envira Default Settings' ),
	false === strpos( $galleries_form, 'Envira Default Settings' ) ? 'absent' : 'OFFERED'
);

// ---------------------------------------------------------------- a real save
$order    = array();
$items    = array();
$settings_payload = array();

foreach ( $record['items'] as $index => $item ) {
	$key     = 'i' . $index;
	$order[] = $key;

	$items[ $key ] = array(
		'id'       => (string) $item['id'],
		'cover_id' => (string) $item['cover_id'],
		'caption'  => (string) $item['caption'],
	);
}

foreach ( $record['settings'] as $key => $value ) {
	if ( is_bool( $value ) ) {
		if ( $value ) {
			$settings_payload[ $key ] = '1';
		}

		continue;
	}

	$settings_payload[ $key ] = (string) $value;
}

$_POST = array(
	'lichtbild_album_editor_nonce' => wp_create_nonce( 'lichtbild_album_editor_' . $target ),
	'lichtbild_album_editor_nonce_items_complete' => '1',
	'lichtbild_album_editor_nonce_settings_complete' => '1',
	'lichtbild_album_items'        => $items,
	'lichtbild_album_order'        => implode( ',', $order ),
	'lichtbild_album_settings'     => $settings_payload,
);

// Through the real hook, so `save_post`'s own plumbing is what runs it.
$album_editor->register();
do_action( 'save_post', $target, get_post( $target ), true );

wp_cache_flush();

$after = $renderer->album(
	( new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true ) )->album( $target ),
	new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true )
);

out( 'save through save_post round-trips', $before === $after, strlen( $before ) . ' vs ' . strlen( $after ) . ' bytes' );

// ---------------------------------------------------------------- reordering, for real
//
// The control the round trip needs: an identical render is also what a save that never ran
// produces.
$_POST['lichtbild_album_order'] = implode( ',', array_reverse( $order ) );

do_action( 'save_post', $target, get_post( $target ), true );

wp_cache_flush();

$stored = get_post_meta( $target, '_lichtbild_album', true );

out(
	'a reorder lands in the database',
	wp_list_pluck( $stored['items'], 'id' ) === array_reverse( wp_list_pluck( $record['items'], 'id' ) ),
	'first id ' . $stored['items'][0]['id'] . ', was ' . $record['items'][0]['id']
);

$_POST['lichtbild_album_order'] = implode( ',', $order );

// ---------------------------------------------------------------- covers, against real rows
$member_id    = (int) $record['items'][0]['id'];
$member_items = ( new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true ) )->gallery( $member_id )->items();
$own_cover    = $member_items[ count( $member_items ) - 1 ]->id();

$_POST['lichtbild_album_items']['i0']['cover_id'] = (string) $own_cover;

do_action( 'save_post', $target, get_post( $target ), true );

wp_cache_flush();

$kept = get_post_meta( $target, '_lichtbild_album', true );

out(
	'a cover from the gallery is kept',
	(int) $kept['items'][0]['cover_id'] === $own_cover,
	'stored ' . $kept['items'][0]['cover_id'] . ', submitted ' . $own_cover
);

// A cover that is a real attachment but belongs to a different gallery. Invisible from the
// front end — the renderer falls back to the gallery's first image — so this is the check.
$foreign_cover = 0;

foreach ( $record['items'] as $other ) {
	if ( (int) $other['id'] === $member_id ) {
		continue;
	}

	$other_items = ( new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true ) )->gallery( (int) $other['id'] )->items();

	if ( ! empty( $other_items ) ) {
		$foreign_cover = $other_items[0]->id();

		break;
	}
}

$_POST['lichtbild_album_items']['i0']['cover_id'] = (string) $foreign_cover;

do_action( 'save_post', $target, get_post( $target ), true );

wp_cache_flush();

$refused = get_post_meta( $target, '_lichtbild_album', true );

out(
	'a cover from another gallery is refused',
	$foreign_cover > 0 && 0 === (int) $refused['items'][0]['cover_id'],
	'attachment ' . $foreign_cover . ' stored as ' . $refused['items'][0]['cover_id']
);

// ---------------------------------------------------------------- put it all back
$_POST = array();

update_post_meta( $target, '_lichtbild_album', $record );

wp_cache_flush();

$restored = $renderer->album(
	( new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true ) )->album( $target ),
	new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true )
);

out( 'the album is back as it was', $before === $restored, strlen( $restored ) . ' bytes' );

$rolled = $migration->rollback();

out(
	'rollback restores the site',
	empty( $rolled['errors'] ) && ! ( new Lichtbild_Settings() )->has_migrated(),
	sprintf( '%d galleries, %d albums', $rolled['galleries'], $rolled['albums'] )
);

$failed = isset( $GLOBALS['lichtbild_live_failed'] ) ? $GLOBALS['lichtbild_live_failed'] : 0;

printf( "\nchecks: 13, failing: %d\n", $failed );

exit( $failed > 0 ? 1 : 0 );
