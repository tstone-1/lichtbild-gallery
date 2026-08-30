<?php
/**
 * Exercises the two blocks against a real WordPress.
 *
 * Run with: bash tools/devenv.sh wp eval-file <this file>
 *
 * The stub suite proves the render callbacks delegate and that the metadata says what it should.
 * What it structurally cannot reach is everything on the other side of `register_block_type()`:
 * whether real WordPress accepts the `block.json`, whether `do_blocks()` on real post content
 * produces the gallery, and whether the editor stylesheet's dependency resolves in a `WP_Styles`
 * rather than in an array this repository wrote. That last one is the whole reason asset
 * registration moved to `init`, and a stub cannot fail it convincingly because a stub has no
 * concept of a hook not firing.
 */

// phpcs:disable

function out( $label, $ok, $detail = '' ) {
	printf( "%-8s %-46s %s\n", $ok ? '[OK]' : '[FAIL]', $label, $detail );
	$GLOBALS['lichtbild_live_checked'] = ( isset( $GLOBALS['lichtbild_live_checked'] ) ? $GLOBALS['lichtbild_live_checked'] : 0 ) + 1;

	if ( ! $ok ) {
		$GLOBALS['lichtbild_live_failed'] = ( isset( $GLOBALS['lichtbild_live_failed'] ) ? $GLOBALS['lichtbild_live_failed'] : 0 ) + 1;
	}
}

$settings = new Lichtbild_Settings();

// ---------------------------------------------------------------- preconditions
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

// WP-CLI starts eval-file with no logged-in user. The picker deliberately asks `read_post` for
// every choice, so an anonymous run offers nothing and makes a correct capability boundary look
// like a broken query. Select a real administrator here rather than requiring an invocation flag
// whose omission produces three unrelated-looking failures later in the report.
$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );

if ( empty( $administrators ) ) {
	echo "[ERROR] the snapshot has no administrator for the block-editor capability checks.\n";

	exit( 1 );
}

wp_set_current_user( (int) $administrators[0] );

// ---------------------------------------------------------------- migrate
$migration = new Lichtbild_Migration( $settings );
$result    = $migration->migrate();

if ( ! empty( $result['errors'] ) ) {
	echo '[ERROR] migration failed: ' . implode( '; ', $result['errors'] ) . "\n";

	exit( 1 );
}

wp_cache_flush();

$settings = new Lichtbild_Settings();

// Post types are registered at `init`, so the rename above left them naming the rows they used
// to name. Production gets the fresh registration from the migration screen's redirect; here it
// has to be asked for, or every capability check below warns and every query misses.
( new Lichtbild_Post_Types( $settings ) )->register_types();

$repository = new Lichtbild_Repository(
	Lichtbild_Post_Types::gallery_type( $settings ),
	Lichtbild_Post_Types::album_type( $settings ),
	Lichtbild_Post_Types::tag_taxonomy( $settings ),
	$settings->has_migrated()
);

$renderer  = new Lichtbild_Renderer( new Lichtbild_Assets( $settings ) );
$shortcode = new Lichtbild_Shortcode( $repository, $renderer, $settings );
$block     = new Lichtbild_Block( $shortcode, $repository, $settings );

// ---------------------------------------------------------------- registration
// `register_block_type()` is given a directory, so real WordPress reads the metadata, applies
// its own i18n schema and validates what it found. A block.json this repository is happy with
// and core is not simply does not appear.
$assets = new Lichtbild_Assets( $settings );
$assets->register_assets();

$registry = WP_Block_Type_Registry::get_instance();

// The plugin's own `init` already registered both blocks, and it did so with a `Lichtbild_Block`
// built at `plugins_loaded` -- before this script renamed anything -- so its render callback
// holds a repository still reading `envira`. Core keeps the FIRST registration and warns about
// the second, so `do_blocks()` below would quietly use the stale one and every render would
// come back empty. Unregistering first is the same correction this file already makes for the
// post types, and for exactly the same reason; production gets it from the screen's redirect.
foreach ( array( 'lichtbild/gallery', 'lichtbild/album' ) as $stale_block ) {
	if ( $registry->is_registered( $stale_block ) ) {
		unregister_block_type( $stale_block );
	}
}

$block->register_blocks();

$gallery_type = $registry->get_registered( 'lichtbild/gallery' );
$album_type   = $registry->get_registered( 'lichtbild/album' );

out(
	'core registered both blocks from block.json',
	$gallery_type instanceof WP_Block_Type && $album_type instanceof WP_Block_Type,
	'registered: ' . implode( ', ', array_keys( array_filter( array(
		'lichtbild/gallery' => $gallery_type,
		'lichtbild/album'   => $album_type,
	) ) ) )
);

if ( ! $gallery_type instanceof WP_Block_Type ) {
	exit( 1 );
}

// Core translates title/description/keywords through its own i18n schema, which it only does
// when the metadata names a textdomain. Asserted against core's parsed value rather than
// against the file, because the file is what the stub suite already reads.
out(
	'core read the metadata, not just the name',
	'media' === $gallery_type->category
		&& isset( $gallery_type->attributes['id']['type'] )
		&& 'number' === $gallery_type->attributes['id']['type']
		&& is_callable( $gallery_type->render_callback ),
	sprintf( 'category %s, %d attributes', (string) $gallery_type->category, count( (array) $gallery_type->attributes ) )
);

// ---------------------------------------------------------------- the editor assets
// The dependency that fails silently. `WP_Styles` drops an unregistered dependency without a
// word, so the failure mode is an unstyled preview and a clean error log -- and the registry
// this asks is core's, populated by the same `init` callbacks production uses.
$styles     = wp_styles();
$editor_css = $styles->query( 'lichtbild-blocks' );
$front_css  = $styles->query( 'lichtbild' );

out(
	'the editor stylesheet resolves its dependency',
	false !== $editor_css && false !== $front_css && in_array( 'lichtbild', (array) $editor_css->deps, true ),
	false === $editor_css
		? 'lichtbild-blocks was never registered'
		: 'deps: ' . implode( ', ', (array) $editor_css->deps ) . '; lichtbild registered: ' . ( false !== $front_css ? 'yes' : 'NO' )
);

// And the handle block.json names for its editor script, resolved against core's own registry
// rather than against the string in the PHP.
$editor_js = wp_scripts()->query( 'lichtbild-blocks' );

out(
	'the editor script handle exists',
	false !== $editor_js && $gallery_type->editor_script_handles === array( 'lichtbild-blocks' ),
	'block.json names: ' . implode( ', ', (array) $gallery_type->editor_script_handles )
);

// ---------------------------------------------------------------- rendering real block content
$target = 0;

foreach ( get_posts( array( 'post_type' => 'lichtbild_gallery', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $candidate ) {
	$gallery = $repository->gallery( (int) $candidate );

	if ( null !== $gallery && $gallery->count() > 0 ) {
		$target = (int) $candidate;

		break;
	}
}

out( 'found a gallery to render', $target > 0, 'gallery ' . $target );

if ( 0 === $target ) {
	exit( 1 );
}

// The whole round trip a post actually takes: serialised block comment -> `parse_blocks()` ->
// `render_block()` -> the render callback. Nothing here calls the callback directly, which is
// the point: an attribute that does not survive serialisation would be invisible to a test
// that hands the callback an array it built itself.
$content  = '<!-- wp:lichtbild/gallery {"id":' . $target . '} /-->';
$rendered = do_blocks( $content );
$direct   = $shortcode->gallery( array( 'id' => (string) $target ) );

out(
	'a serialised block renders through core',
	'' !== $direct && false !== strpos( $rendered, 'id="lichtbild-' . $target . '-wrap"' ),
	sprintf( 'block produced %d bytes, shortcode %d', strlen( $rendered ), strlen( $direct ) )
);

// The parse leg on its own, because `do_blocks()` succeeding says the attribute arrived and not
// that it arrived as a number. A block whose id round-trips as "12" is one core re-serialises
// differently on every save.
$parsed = parse_blocks( $content );

out(
	'the id survives as a number',
	isset( $parsed[0]['attrs']['id'] ) && $target === $parsed[0]['attrs']['id'] && is_int( $parsed[0]['attrs']['id'] ),
	'parsed: ' . var_export( $parsed[0]['attrs']['id'] ?? null, true )
);

// A block with no gallery chosen is the state every freshly inserted one is in. On a real
// WordPress `get_post_status( 0 )` can answer for the post being rendered, so "renders nothing"
// is worth asserting here rather than only against a stub that returns false for 0.
out(
	'an unchosen block renders nothing',
	'' === trim( do_blocks( '<!-- wp:lichtbild/gallery {"id":0} /-->' ) )
		&& '' === trim( do_blocks( '<!-- wp:lichtbild/gallery /-->' ) ),
	'id 0 and an absent id, both rendered'
);

// ---------------------------------------------------------------- the picker
$choices = $repository->gallery_choices();
$rows    = get_posts( array( 'post_type' => 'lichtbild_gallery', 'post_status' => array( 'publish', 'private', 'draft', 'pending' ), 'numberposts' => -1, 'fields' => 'ids' ) );

// The pre-migration type name has to find nothing, or the picker would look populated on a site
// where the rename never happened -- and `<` is what stops "offered nothing" passing as
// "excluded the defaults row".
$stale = get_posts( array( 'post_type' => 'envira', 'post_status' => array( 'publish', 'private', 'draft', 'pending' ), 'numberposts' => -1, 'fields' => 'ids' ) );

out(
	'the picker offers the renamed galleries',
	count( $choices ) > 0 && count( $choices ) <= count( $rows ) && 0 === count( $stale ),
	sprintf( '%d offered of %d rows; the old type name finds %d', count( $choices ), count( $rows ), count( $stale ) )
);

$album_choices = $repository->album_choices();

out(
	'the picker offers the renamed albums',
	count( $album_choices ) > 0,
	sprintf( '%d albums offered', count( $album_choices ) )
);

// ---------------------------------------------------------------- visibility, through core
// The gate that matters, asserted against real `post_password_required()` rather than a stub:
// a protected gallery placed in a block by an author renders nothing to a logged-out visitor.
// The production snapshot currently has no password-protected gallery, so searching for one is
// an assertion that can never pass. Protect the known renderable gallery for this check and put
// its original password back before reporting the result.
$target_post       = get_post( $target );
$original_password = $target_post instanceof WP_Post ? (string) $target_post->post_password : '';
$was_user          = wp_get_current_user()->ID;
$protected_update  = wp_update_post( array( 'ID' => $target, 'post_password' => 'lichtbild-live-block-control' ), true );

clean_post_cache( $target );
wp_set_current_user( 0 );

$locked = do_blocks( '<!-- wp:lichtbild/gallery {"id":' . $target . '} /-->' );

wp_set_current_user( $was_user );
wp_update_post( array( 'ID' => $target, 'post_password' => $original_password ) );
clean_post_cache( $target );

$open     = do_blocks( '<!-- wp:lichtbild/gallery {"id":' . $target . '} /-->' );
$restored = get_post( $target );

out(
	'a protected gallery renders nothing through the block',
	! is_wp_error( $protected_update )
		&& '' === trim( $locked )
		&& '' !== trim( $open )
		&& $restored instanceof WP_Post
		&& $original_password === (string) $restored->post_password,
	sprintf( 'protected %d bytes, control %d bytes; password restored: %s', strlen( trim( $locked ) ), strlen( trim( $open ) ), $restored instanceof WP_Post && $original_password === (string) $restored->post_password ? 'yes' : 'NO' )
);

// ---------------------------------------------------------------- roll back
$rolled = $migration->rollback();

out(
	'rollback restores the site',
	empty( $rolled['errors'] ) && ! ( new Lichtbild_Settings() )->has_migrated(),
	sprintf( '%d galleries, %d albums', $rolled['galleries'], $rolled['albums'] )
);

$failed = isset( $GLOBALS['lichtbild_live_failed'] ) ? $GLOBALS['lichtbild_live_failed'] : 0;
$checked = isset( $GLOBALS['lichtbild_live_checked'] ) ? $GLOBALS['lichtbild_live_checked'] : 0;

printf( "\nchecks: %d, failing: %d\n", $checked, $failed );

exit( $failed > 0 ? 1 : 0 );
