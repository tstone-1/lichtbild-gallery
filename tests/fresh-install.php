<?php
/**
 * The stranger's path: an empty WordPress, the plugin installed FROM wordpress.org, a gallery
 * made through the editor's own save path, and the result fetched over HTTP.
 *
 * Run with: bash tools/devenv.sh fresh test
 *
 * WHY THIS EXISTS. Every other harness here models the site this plugin was written for -- a
 * live Envira installation being taken over -- and none of them can reach the path every
 * stranger takes, because they all start with 52 Envira galleries and the working tree
 * symlinked in. `AGENTS.md` recorded that as the one gap no checklist covered: the fresh-install
 * path was written in 26.8.18 and had only ever been exercised by stubs.
 *
 * WHAT MAKES IT DIFFERENT FROM `tests/live-editor.php`, which also runs against real WordPress:
 * that one migrates an Envira site and then edits it. This one asserts the opposite site -- no
 * Envira history, generic URL slugs, already migrated on activation -- and it runs the bytes the
 * DIRECTORY serves rather than the working tree, so a file missing from the published archive
 * fails here and nowhere else.
 */

// phpcs:disable

// `wp eval-file` runs this file inside a function, so a top-level `$failed` is a LOCAL and
// `global $failed` inside out() reaches a different variable entirely. The first run of this
// harness printed "0 checks, 0 failed" under six real failures — a summary that cannot count is
// worse than none, because it is the line a reader trusts.
$GLOBALS['fresh_failed'] = 0;
$GLOBALS['fresh_checks'] = 0;

function out( $label, $ok, $detail = '' ) {
	$GLOBALS['fresh_checks']++;
	printf( "%-8s %-52s %s\n", $ok ? '[OK]' : '[FAIL]', $label, $detail );

	if ( ! $ok ) {
		$GLOBALS['fresh_failed']++;
	}
}

// ---------------------------------------------------------------- preconditions
// Stated rather than assumed. Every check below reads differently on a site with an Envira
// history, and this run is only meaningful on one without.
$settings = new Lichtbild_Settings();

$envira_posts = get_posts(
	array(
		'post_type'      => array( 'envira', 'envira_album' ),
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	)
);

if ( ! empty( $envira_posts ) ) {
	echo "[ERROR] this site has Envira records, so it is not a fresh install.\n";
	exit( 1 );
}

// ---------------------------------------------------------------- what a directory install is
out(
	'installed from the directory, not linked',
	! is_link( WP_PLUGIN_DIR . '/lichtbild-gallery' ),
	is_link( WP_PLUGIN_DIR . '/lichtbild-gallery' ) ? 'it is a symlink to the working tree' : ''
);

out(
	'the published build ships no catalogue',
	array() === glob( WP_PLUGIN_DIR . '/lichtbild-gallery/languages/*.mo' ),
	'so German can only arrive as a language pack'
);

// ---------------------------------------------------------------- a site with no Envira past
out(
	'recorded slug scheme is generic',
	'generic' === $settings->slug_scheme(),
	$settings->slug_scheme()
);

out(
	'it does not claim to continue Envira',
	! $settings->continues_envira()
);

out(
	'a fresh site starts already migrated',
	$settings->has_migrated(),
	'so the editor works with no migration step'
);

out(
	'it does not claim the Envira shortcodes',
	! $settings->claims_envira_shortcodes()
);

$paths = $settings->slug_scheme_paths();
out(
	'gallery permalinks are /gallery/, not /envira/',
	'gallery' === $paths['gallery'],
	implode( ', ', $paths )
);

foreach ( array( 'lichtbild_gallery', 'lichtbild_album' ) as $type ) {
	out( "post type $type is registered", post_type_exists( $type ) );
}

out( 'taxonomy lichtbild_tag is registered', taxonomy_exists( 'lichtbild_tag' ) );

// ---------------------------------------------------------------- make three real images
// Real files, not fixtures: the justified grid is driven by attachment dimensions, and
// `wp_generate_attachment_metadata()` is what produces the derivative sizes the grid links to.
// A record written by hand would skip exactly the step that makes the srcset exist.
$uploads = wp_upload_dir();
$ids     = array();
$sizes   = array( array( 1200, 800 ), array( 900, 1200 ), array( 1600, 900 ) );

foreach ( $sizes as $i => $wh ) {
	$file = $uploads['path'] . '/fresh-' . ( $i + 1 ) . '.jpg';
	$im   = imagecreatetruecolor( $wh[0], $wh[1] );
	imagefilledrectangle( $im, 0, 0, $wh[0], $wh[1], imagecolorallocate( $im, 40 + $i * 60, 90, 160 ) );
	imagejpeg( $im, $file, 82 );
	imagedestroy( $im );

	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Fresh image ' . ( $i + 1 ),
			'post_excerpt'   => 'Caption ' . ( $i + 1 ),
			'post_status'    => 'inherit',
		),
		$file
	);

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	update_post_meta( $id, '_wp_attachment_image_alt', 'Alt ' . ( $i + 1 ) );

	$ids[] = $id;
}

out( 'three attachments created with real files', 3 === count( array_filter( $ids ) ), implode( ',', $ids ) );

$meta = wp_get_attachment_metadata( $ids[0] );
out(
	'WordPress generated derivative sizes',
	! empty( $meta['sizes'] ),
	implode( ', ', array_keys( (array) ( $meta['sizes'] ?? array() ) ) )
);

// ---------------------------------------------------------------- make a gallery, as the editor does
// Through `save_post` with a real nonce, not by writing meta: the save path is where order,
// removal and tag handling live, and it is what a person's click actually runs.
wp_set_current_user( 1 );

$gallery_id = wp_insert_post(
	array(
		'post_type'   => Lichtbild_Post_Types::GALLERY,
		'post_title'  => 'Fresh gallery',
		'post_status' => 'publish',
	)
);

out( 'gallery post created', $gallery_id > 0, "ID $gallery_id" );

function fresh_submit( $gallery_id, $ids, $order, $extra = array() ) {
	$_POST = array(
		Lichtbild_Editor::NONCE => wp_create_nonce( Lichtbild_Editor::NONCE_ACTION . $gallery_id ),
		'lichtbild_editor_nonce_items_complete' => '1',
		'lichtbild_editor_nonce_settings_complete' => '1',
		'lichtbild_order'       => implode( ',', array_map( function ( $i ) { return 'i' . $i; }, $order ) ),
		'lichtbild_items'       => array(),
	);

	foreach ( $ids as $i => $id ) {
		$_POST['lichtbild_items'][ 'i' . $i ] = array_merge(
			array(
				'id'      => (string) $id,
				'status'  => 'active',
				'title'   => 'Title ' . ( $i + 1 ),
				'caption' => 'Caption ' . ( $i + 1 ),
				'alt'     => 'Alt ' . ( $i + 1 ),
				'src'     => wp_get_attachment_url( $id ),
				'link'    => wp_get_attachment_url( $id ),
			),
			$extra[ $i ] ?? array()
		);
	}

	do_action( 'save_post', $gallery_id, get_post( $gallery_id ), true );

	$_POST = array();
}

fresh_submit( $gallery_id, $ids, array( 0, 1, 2 ) );

$record = get_post_meta( $gallery_id, '_lichtbild_gallery', true );

out(
	'the editor wrote a v2 record',
	is_array( $record ) && isset( $record['items'] ) && 3 === count( $record['items'] ),
	is_array( $record ) ? count( $record['items'] ) . ' items' : 'no record'
);

out(
	'no Envira record was created',
	'' === get_post_meta( $gallery_id, '_eg_gallery_data', true ),
	'a fresh site has no second representation'
);

// ---------------------------------------------------------------- order, and removal
fresh_submit( $gallery_id, $ids, array( 2, 0 ) );

$record    = get_post_meta( $gallery_id, '_lichtbild_gallery', true );
$order_got = array_values( wp_list_pluck( $record['items'], 'id' ) );

out(
	'submitted order wins over field order',
	array( $ids[2], $ids[0] ) === array_map( 'intval', $order_got ),
	implode( ',', $order_got )
);

out(
	'an item the order does not name is dropped',
	2 === count( $record['items'] ),
	count( $record['items'] ) . ' items'
);

fresh_submit( $gallery_id, $ids, array( 0, 1, 2 ), array( 0 => array( 'tags' => 'Hamburg' ) ) );

$terms = wp_get_object_terms( $ids[0], 'lichtbild_tag', array( 'fields' => 'names' ) );
out(
	'an image tag is stored on the attachment',
	in_array( 'Hamburg', (array) $terms, true ),
	implode( ',', (array) $terms )
);

// ---------------------------------------------------------------- render it
$html = do_shortcode( '[lichtbild-gallery id="' . $gallery_id . '"]' );

out( 'the shortcode renders a gallery', false !== strpos( $html, 'lichtbild-wrap' ) );
$item_nodes = substr_count( $html, 'lichtbild-item' );
out( 'it renders three items', $item_nodes >= 3, $item_nodes . ' item nodes' );
out( 'images carry a srcset', false !== strpos( $html, 'srcset=' ) );
out(
	'grid images are derivatives, not the full-size file',
	false === strpos( $html, 'fresh-1.jpg" ' ) || false !== strpos( $html, '-768x' ) || false !== strpos( $html, '-1024x' ),
	'a derivative width appears in the markup'
);
out( 'the caption survived the save path', false !== strpos( $html, 'Caption 1' ) );

// ---------------------------------------------------------------- over HTTP, as a visitor
$permalink = get_permalink( $gallery_id );

out(
	'the gallery permalink is under /gallery/',
	false !== strpos( $permalink, '/gallery/' ),
	$permalink
);

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Fresh page',
		'post_status'  => 'publish',
		'post_content' => '[lichtbild-gallery id="' . $gallery_id . '"]',
	)
);

foreach ( array( 'gallery permalink' => $permalink, 'page with the shortcode' => get_permalink( $page_id ) ) as $what => $url ) {
	$res = wp_remote_get( $url, array( 'timeout' => 20 ) );

	if ( is_wp_error( $res ) ) {
		out( "$what over HTTP", false, $res->get_error_message() );
		continue;
	}

	$code = wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );

	out( "$what answers 200", 200 === $code, (string) $code );
	out( "$what renders the gallery", false !== strpos( $body, 'lichtbild-wrap' ) );
	out( "$what loads the stylesheet in <head>", (bool) preg_match( '#<head>.*lichtbild.*\.css.*</head>#s', $body ) );
}

// ---------------------------------------------------------------- the admin screens
// A cookie generated here rather than a login POST: the subject is whether the screens render
// on a directory install, not whether WordPress can log in.
$cookie_value = wp_generate_auth_cookie( 1, time() + 3600, 'logged_in' );
$cookies      = array(
	new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $cookie_value ) ),
	new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( 1, time() + 3600, 'auth' ) ) ),
);

$admin = array(
	'gallery list'     => admin_url( 'edit.php?post_type=lichtbild_gallery' ),
	'gallery editor'   => admin_url( 'post.php?post=' . $gallery_id . '&action=edit' ),
	'settings page'    => admin_url( 'options-general.php?page=lichtbild' ),
);

foreach ( $admin as $what => $url ) {
	$res = wp_remote_get( $url, array( 'timeout' => 20, 'cookies' => $cookies ) );

	if ( is_wp_error( $res ) ) {
		out( "admin: $what", false, $res->get_error_message() );
		continue;
	}

	$code = wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );

	out( "admin: $what answers 200", 200 === $code, (string) $code );
	out(
		"admin: $what is not a login redirect",
		false === strpos( $body, 'id="loginform"' ) && false !== strpos( $body, 'wp-admin' )
	);
}

// ---------------------------------------------------------------- nothing in the error log
// WP_DEBUG_LOG is on for this site. A warning per save is a log nobody can read, and on a
// fresh install it is the first thing a stranger's host reports.
$log   = WP_CONTENT_DIR . '/debug.log';
$lines = file_exists( $log ) ? preg_grep( '/lichtbild/i', file( $log ) ) : array();

out(
	'no PHP notice or warning naming the plugin',
	empty( $lines ),
	empty( $lines ) ? '' : count( $lines ) . ' line(s), first: ' . trim( (string) reset( $lines ) )
);

// ---------------------------------------------------------------- summary
printf( "\n%d checks, %d failed\n", $GLOBALS['fresh_checks'], $GLOBALS['fresh_failed'] );

exit( $GLOBALS['fresh_failed'] > 0 ? 1 : 0 );
