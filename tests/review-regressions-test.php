<?php
/** Regression checks for repeated images and cropped lightbox sources. Run: php tests/review-regressions-test.php */
ini_set( 'display_errors', 'stderr' );
define( 'ABSPATH', __DIR__ );
define( 'LICHTBILD_DIR', dirname( __DIR__ ) . '/' );
define( 'LICHTBILD_URL', 'https://example.test/plugin/' );
define( 'LICHTBILD_VERSION', 'test' );
define( 'LICHTBILD_FILE', LICHTBILD_DIR . 'lichtbild-gallery.php' );
require __DIR__ . '/wp-stubs.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-metabox-editor.php';
foreach ( glob( LICHTBILD_DIR . 'includes/class-*.php' ) as $file ) {
	require_once $file;
}
$site = new Lichtbild_Test_Site();
Lichtbild_Test_Site::$instance = $site;
$site->siteurl = 'https://example.test';
$site->capabilities = true;
$site->taxonomies = array( array( 'taxonomy' => 'lichtbild_tag' ) );
$site->options = array( 'lichtbild_schema_version' => 2, 'lichtbild_slug_scheme' => 'generic' );
$site->attachments[7] = array( 'data' => array(
	'file' => 'photo.jpg', 'width' => 2000, 'height' => 1000,
	'sizes' => array(
		'thumbnail' => array( 'file' => 'square.jpg', 'width' => 150, 'height' => 150 ),
		'large' => array( 'file' => 'large.jpg', 'width' => 1000, 'height' => 500 ),
	),
) );
$site->posts[1] = array( 'post_type' => 'lichtbild_gallery', 'post_status' => 'publish' );
$site->galleries[1] = array( 'status' => 'publish', 'lichtbild' => array() );
$failures = 0;
$checks = 0;
function review_check( $name, $condition ) {
	global $checks, $failures;
	++$checks;
	if ( ! $condition ) { ++$failures; }
	fwrite( STDERR, ( $condition ? '[OK] ' : '[FAIL] ' ) . $name . "\n" );
}
$record = array( 'id' => 7, 'status' => 'active', 'caption' => 'first' );
$a = new Lichtbild_Item( 7, $record );
$b = new Lichtbild_Item( 7, array_merge( $record, array( 'caption' => 'second' ) ) );
$gallery = new Lichtbild_Gallery( 1, array( 'pagination' => true, 'per_page' => 1, 'row_height' => 350 ), array( $a, $b ) );
review_check( 'duplicate occurrences have distinct keys', $gallery->item_key( $a ) !== $gallery->item_key( $b ) );
review_check( 'page two retains its occurrence key', $gallery->item_key( $gallery->page_items( 2 )[0] ) === $gallery->item_key( $b ) );
$renderer = new Lichtbild_Renderer( new Lichtbild_Assets( new Lichtbild_Settings() ) );
$html = $renderer->items( $gallery, array( $b ) );
review_check( 'rendered occurrence key is carried to the browser', false !== strpos( $html, 'data-lichtbild-key="7:1"' ) );
$crop = $a->lightbox_source( 'thumbnail' );
$normal = $a->lightbox_source( 'large' );
review_check( 'cropped source falls back to the uncropped image', str_ends_with( $crop['url'], '/photo.jpg' ) && 2000 === $crop['width'] && 1000 === $crop['height'] );
review_check( 'uncropped derivative retains full viewport geometry', str_ends_with( $normal['url'], '/large.jpg' ) && 2000 === $normal['width'] );
review_check( 'srcset stub excludes uncropped images from square selection', false === wp_get_attachment_image_srcset( 7, 'thumbnail' ) );
review_check( 'uncropped selection still has responsive candidates', false !== strpos( $normal['srcset'], '2000w' ) && false === strpos( $normal['srcset'], 'square' ) );

$editor = new Lichtbild_Editor( new Lichtbild_Settings(), new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true ) );
$_POST = array(
	'lichtbild_editor_nonce' => wp_create_nonce( 'lichtbild_editor_1' ),
	'lichtbild_editor_nonce_items_complete' => 1,
	'lichtbild_editor_nonce_settings_complete' => 1,
	'lichtbild_order' => 'a,b', 'lichtbild_settings' => array(),
	'lichtbild_items' => array( 'a' => $record + array( 'tags' => 'new' ), 'b' => $record + array( 'tags' => 'new' ) ),
);
$editor->save( 1 );
$saved = $site->galleries[1]['lichtbild'];
review_check( 'synchronized repeated rows are both saved', count( $saved['items'] ?? array() ) === 2 );
review_check( 'synchronized tag edit is written', ( get_the_terms( 7, 'lichtbild_tag' )[0]->name ?? '' ) === 'new' );
$_POST['lichtbild_order'] = 'b,a';
$editor->save( 1 );
review_check( 'reordering keeps the edited tags', ( get_the_terms( 7, 'lichtbild_tag' )[0]->name ?? '' ) === 'new' );
$saved = $site->galleries[1]['lichtbild'];
$_POST['lichtbild_items']['a']['tags'] = 'conflicting';
$refused = false;
try { $editor->save( 1 ); } catch ( Lichtbild_Test_Halt $e ) { $refused = str_starts_with( $e->getMessage(), 'die:' ); }
review_check( 'conflicting tags refuse the save before any write', $refused && $saved === $site->galleries[1]['lichtbild'] && ( get_the_terms( 7, 'lichtbild_tag' )[0]->name ?? '' ) === 'new' );

// The AJAX payload must use the same occurrence keys as the grid, including pagination.
$site->galleries[1]['lichtbild']['items'][1]['caption'] = 'second';
$_REQUEST = array( 'gallery' => 1, 'nonce' => 'expired' );
$_GET = $_REQUEST;
$ajax = new Lichtbild_Ajax( new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true ), $renderer );
ob_start();
try { $ajax->handle_items(); } catch ( Lichtbild_Test_Halt $e ) { /* inspect the actual response below */ }
$response = json_decode( ob_get_clean(), true );
$entries = $response['data']['items'] ?? array();
review_check( 'AJAX preserves both occurrence keys', count( $entries ) === 2 && '7:0' === $entries[0]['key'] && '7:1' === $entries[1]['key'] );
fwrite( STDERR, "checks: $checks, failing: $failures\n" );
if ( '--html' === ( $argv[1] ?? '' ) ) {
	file_put_contents( $argv[2], $renderer->gallery( $gallery ) );
}
exit( $failures ? 1 : 0 );
