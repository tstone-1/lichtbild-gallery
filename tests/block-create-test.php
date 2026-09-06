<?php
/**
 * Exercises `Lichtbild_Block::handle_create()`, the endpoint the block-first create flow posts to.
 *
 *     php tests/block-create-test.php
 *
 * WHY THIS IS A FILE OF ITS OWN
 * =============================
 *
 * Every other PHP check in this repository reads a gallery and asserts markup. This one writes:
 * it is the only path in the plugin where a *visitor-facing entity is created from a request*,
 * and the guards that decide whether that request may create one — the nonce, two capabilities,
 * the migration state and the attachment validation — produce no markup at all. They are
 * invisible to a rendering test by construction.
 *
 * It builds its own site rather than loading a fixture, and that is deliberate: nothing here
 * reads an existing gallery, so the whole world this endpoint needs is a handful of attachments
 * and a schema option, which is four lines to state and one fewer thing that has to be
 * generated before the check can run.
 *
 * **Each check drives the endpoint through the public method and reads its JSON**, rather than
 * calling the private helpers. A guard is only covered when a mutation that removes the *call*
 * turns something red, and a test aimed at the helper leaves the call site untested — which is
 * how this project once shipped a capability check nothing invoked.
 *
 * @package Lichtbild\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.NonceVerification

// Diagnostics to stderr, for the reason `tests/render-test.php` gives at length: a warning on
// stdout is body output, and every later check that reads a JSON body would then be reading the
// warning as well.
ini_set( 'display_errors', 'stderr' );

define( 'ABSPATH', __DIR__ );
define( 'LICHTBILD_VERSION', 'test' );
define( 'LICHTBILD_FILE', dirname( __DIR__ ) . '/lichtbild-gallery.php' );
define( 'LICHTBILD_DIR', dirname( __DIR__ ) . '/' );
define( 'LICHTBILD_URL', 'https://example.com/wp-content/plugins/lichtbild-gallery/' );

require __DIR__ . '/wp-stubs.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-assets.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-config.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-album-config.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-item.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-gallery.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-album.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-exif.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-repository.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-renderer.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-post-types.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-settings.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-shortcode.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-block.php';

// ---------------------------------------------------------------------------------------------
// The stubs `tests/wp-stubs.php` does not carry, because nothing else in the suite writes or
// deletes a post. They live here rather than there so that this file adds nothing to the shared
// surface every other check loads.
// ---------------------------------------------------------------------------------------------

/**
 * The minimum of `WP_Error` this endpoint can distinguish.
 */
class WP_Error {

	/**
	 * The error code.
	 *
	 * @var string
	 */
	public $code;

	/**
	 * Builds the error.
	 *
	 * @param string $code Error code.
	 */
	public function __construct( $code = 'error' ) {
		$this->code = $code;
	}
}

/**
 * Reports whether a value is a `WP_Error`.
 *
 * @param mixed $thing Value to test.
 *
 * @return bool True for a WP_Error.
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Inserts a post, recording what it was asked for.
 *
 * Models the one thing about core's version that this endpoint's correctness depends on: it
 * **unslashes** what it is handed, because WordPress normally hands it raw `$_POST`. A stub that
 * stored the title verbatim would make the `wp_slash()` in the endpoint impossible to test — the
 * two ends would agree whatever happened to the backslashes in between.
 *
 * @param array $postarr  Post fields.
 * @param bool  $wp_error Whether to return a WP_Error on failure.
 *
 * @return int|WP_Error New post ID, or the failure the fixture asked for.
 */
function wp_insert_post( $postarr, $wp_error = false ) {
	$GLOBALS['lichtbild_inserted'] = $postarr;

	if ( ! empty( $GLOBALS['lichtbild_insert_fails'] ) ) {
		return $wp_error ? new WP_Error( 'db_insert_error' ) : 0;
	}

	$postarr = wp_unslash( $postarr );
	$site    = Lichtbild_Test_Site::$instance;
	$id      = 9000 + count( $site->posts );

	$site->posts[ $id ] = array(
		'post_type'   => $postarr['post_type'],
		'post_status' => $postarr['post_status'],
		'post_title'  => $postarr['post_title'],
	);

	$site->galleries[ $id ] = array(
		'id'     => $id,
		'title'  => $postarr['post_title'],
		'status' => $postarr['post_status'],
	);

	return $id;
}

/**
 * Deletes a post, recording that it was asked for and how.
 *
 * `$force_delete` is recorded rather than ignored, because the two answers mean different things
 * to the site owner: a trashed draft is litter on the Galleries screen carrying no explanation,
 * and a force-deleted one is gone. A stub that dropped the argument would let either pass.
 *
 * @param int  $post_id      Post to delete.
 * @param bool $force_delete Whether to bypass the trash.
 *
 * @return bool Whether a post was deleted.
 */
function wp_delete_post( $post_id = 0, $force_delete = false ) {
	$site = Lichtbild_Test_Site::$instance;
	$id   = (int) $post_id;

	$GLOBALS['lichtbild_deleted'][] = array(
		'id'    => $id,
		'force' => (bool) $force_delete,
	);

	if ( ! isset( $site->posts[ $id ] ) ) {
		return false;
	}

	unset( $site->posts[ $id ], $site->galleries[ $id ] );

	return true;
}

/**
 * Reports whether an attachment is an image.
 *
 * Answered from the fixture's own mime type, which is how real WordPress answers it. A missing
 * mime is **not** read as an image: a stub that assumed one would answer yes for every
 * attachment, and the whole point of the guard this models is that some attachments are not
 * images.
 *
 * @param int $post_id Attachment ID.
 *
 * @return bool True for an attachment whose mime type is an image type.
 */
function wp_attachment_is_image( $post_id = 0 ) {
	$site = Lichtbild_Test_Site::$instance;
	$id   = (int) $post_id;

	if ( ! isset( $site->attachments[ $id ]['mime'] ) ) {
		return false;
	}

	return 0 === strpos( (string) $site->attachments[ $id ]['mime'], 'image/' );
}

// ---------------------------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------------------------

$failures = 0;
$checks   = 0;
$report   = array();

/**
 * Records one check.
 *
 * **Collected and printed at the end rather than printed as it goes, and that is not tidiness.**
 * Every check here drives an endpoint that answers through `wp_send_json_*`, which calls
 * `header()`. A line printed to stdout is body output, so `headers_sent()` becomes true, so every
 * endpoint call after the first check warns "Cannot modify header information" — a warning per
 * check, about the harness, saying nothing about the code. It is the same mechanism
 * `tests/render-test.php` routes its diagnostics to stderr for, arriving from the other side:
 * there the warning caused the output, here the output causes the warning.
 *
 * @param string $label  What is being asserted.
 * @param bool   $ok     Whether it holds.
 * @param string $detail Context, printed either way.
 *
 * @return void
 */
function check( $label, $ok, $detail = '' ) {
	global $failures, $checks, $report;

	++$checks;

	$report[] = sprintf( "%-6s %-56s %s", $ok ? '[OK]' : '[FAIL]', $label, $detail );

	if ( ! $ok ) {
		++$failures;
	}
}

/**
 * Builds the site every check runs against.
 *
 * Rebuilt per check rather than shared, so that a check cannot pass because an earlier one left
 * the right option or the right capability behind.
 *
 * @param bool $migrated Whether the site is on Lichtbild's own storage.
 *
 * @return Lichtbild_Test_Site The site.
 */
function site( $migrated = true ) {
	$site          = new Lichtbild_Test_Site();
	$site->siteurl = 'https://example.com';

	// Five real attachments, and two of them are deliberately not images. `get_post_type()`
	// answers `attachment` for all five and `false` for anything else, so 104 and 105 are the
	// only arrangement in which the endpoint's post-type check and its image check can be told
	// apart: both are attachments this user may read, and neither belongs in a gallery.
	$site->attachments = array(
		101 => array( 'title' => 'Alps', 'url' => 'https://example.com/alps.jpg', 'mime' => 'image/jpeg' ),
		102 => array( 'title' => 'Zoo', 'url' => 'https://example.com/zoo.jpg', 'mime' => 'image/jpeg' ),
		103 => array( 'title' => 'Sea', 'url' => 'https://example.com/sea.jpg', 'mime' => 'image/png' ),
		104 => array( 'title' => 'Prospekt', 'url' => 'https://example.com/prospekt.pdf', 'mime' => 'application/pdf' ),
		105 => array( 'title' => 'Interview', 'url' => 'https://example.com/interview.mp3', 'mime' => 'audio/mpeg' ),
	);

	// Both options set explicitly, so `Lichtbild_Settings::initialise()` reaches no query: it
	// short-circuits on a schema of 2, and a recorded slug scheme stops it deriving one.
	$site->options = array(
		Lichtbild_Settings::OPTION_SCHEMA      => $migrated ? Lichtbild_Settings::SCHEMA_MIGRATED : 1,
		Lichtbild_Settings::OPTION_SLUG_SCHEME => 'envira',
	);

	// Everything the create flow needs, and nothing else. Each check that is about a refusal
	// takes exactly one of these away, so the check's own arrangement says which capability it
	// is about.
	$site->capability_overrides = array(
		'create_galleries' => true,
		'upload_files'     => true,
		'read_post'        => true,
	);

	$GLOBALS['lichtbild_no_post_type'] = false;
	$GLOBALS['lichtbild_post_type_create_cap'] = 'create_galleries';
	$GLOBALS['lichtbild_insert_fails'] = false;
	$GLOBALS['lichtbild_inserted']     = array();
	$GLOBALS['lichtbild_deleted']      = array();
	Lichtbild_Test_Site::$instance     = $site;

	return $site;
}

/**
 * Builds a block registrar against the current site.
 *
 * @return Lichtbild_Block The registrar.
 */
function registrar() {
	$settings   = new Lichtbild_Settings();
	$repository = new Lichtbild_Repository(
		Lichtbild_Post_Types::gallery_type( $settings ),
		Lichtbild_Post_Types::album_type( $settings ),
		Lichtbild_Post_Types::tag_taxonomy( $settings ),
		$settings->has_migrated()
	);

	$renderer = new Lichtbild_Renderer( new Lichtbild_Assets( $settings ) );

	return new Lichtbild_Block(
		new Lichtbild_Shortcode( $repository, $renderer, $settings ),
		$repository,
		$settings
	);
}

/**
 * Posts a request to the endpoint and returns what it answered.
 *
 * **The body is slashed on the way in, because WordPress slashes `$_POST` and this endpoint is
 * written against that.** Core adds slashes to every superglobal before a single plugin runs, so
 * production reads `C:\\Photos` out of `$_POST` and `wp_unslash()`es it back to `C:\Photos`. A
 * harness handing it the unslashed string instead makes that `wp_unslash()` a *destructive* step
 * that nothing in the endpoint asked for — the title arrives as `C:PhotosAlps` — and the check
 * that exists to prove backslashes survive fails against correct code. Modelling the platform
 * agreeably is the recurring shape of defect in this suite; this is the same one, in the input.
 *
 * @param array $post Request body, unslashed. A `nonce` key is supplied unless the caller sets one.
 *
 * @return array{halt:string,payload:mixed} How the request ended, and the decoded JSON.
 */
function post( array $post ) {
	$post += array( 'images_complete' => '1' );
	if ( ! array_key_exists( 'nonce', $post ) ) {
		$post['nonce'] = wp_create_nonce( Lichtbild_Block::CREATE_ACTION );
	}

	$_POST    = wp_slash( $post );
	$_REQUEST = $_POST;

	$block = registrar();

	ob_start();

	try {
		$block->handle_create();

		return array(
			'halt'    => 'none',
			'payload' => json_decode( (string) ob_get_clean(), true ),
		);
	} catch ( Lichtbild_Test_Halt $halt ) {
		return array(
			'halt'    => $halt->getMessage(),
			'payload' => json_decode( (string) ob_get_clean(), true ),
		);
	}
}

/**
 * Returns the record the endpoint stored for a gallery.
 *
 * @param int $post_id Gallery post ID.
 *
 * @return array The stored record, or an empty array.
 */
function stored( $post_id ) {
	$record = get_post_meta( $post_id, Lichtbild_Repository::GALLERY_META_V2, true );

	return is_array( $record ) ? $record : array();
}

// ---------------------------------------------------------------------------------------------
// The happy path, and what it stored
// ---------------------------------------------------------------------------------------------

site();

$made = post(
	array(
		'title'  => 'Trip to the Alps',
		'images' => array( '101', '103' ),
	)
);

$id     = isset( $made['payload']['data']['id'] ) ? (int) $made['payload']['data']['id'] : 0;
$record = stored( $id );

check(
	'a valid request creates a gallery and answers with its id',
	'success' === $made['halt'] && true === $made['payload']['success'] && $id > 0,
	sprintf( 'halt %s, id %d', $made['halt'], $id )
);

// The status is the whole reason the editor shows a draft notice afterwards, and it is the one
// field of the insert that a reader would most reasonably assume said `publish`.
check(
	'the gallery is a draft of the migrated post type',
	isset( $GLOBALS['lichtbild_inserted']['post_status'] )
		&& 'draft' === $GLOBALS['lichtbild_inserted']['post_status']
		&& Lichtbild_Post_Types::GALLERY === $GLOBALS['lichtbild_inserted']['post_type'],
	sprintf(
		'inserted %s as %s',
		isset( $GLOBALS['lichtbild_inserted']['post_type'] ) ? $GLOBALS['lichtbild_inserted']['post_type'] : '(nothing)',
		isset( $GLOBALS['lichtbild_inserted']['post_status'] ) ? $GLOBALS['lichtbild_inserted']['post_status'] : '(nothing)'
	)
);

// The record has to be the shape the editor writes, or the gallery is created and then cannot be
// edited: `Lichtbild_Repository::build_gallery()` ignores a v2 record with empty settings, and
// falls through to an Envira record this gallery has never had.
check(
	'it stored a v2 record the repository will accept',
	isset( $record['version'], $record['settings'], $record['items'] )
		&& Lichtbild_Config::VERSION === $record['version']
		&& ! empty( $record['settings'] )
		&& Lichtbild_Config::defaults() === $record['settings'],
	'keys: ' . implode( ', ', array_keys( $record ) )
);

// Order is the gallery's own property, and the request is the only thing that knows it.
check(
	'the items are the chosen attachments, in the chosen order',
	isset( $record['items'] )
		&& 2 === count( $record['items'] )
		&& 101 === $record['items'][0]['id']
		&& 103 === $record['items'][1]['id'],
	'ids: ' . implode( ', ', array_column( isset( $record['items'] ) ? $record['items'] : array(), 'id' ) )
);

// An item carrying only an ID is complete — every URL, title and dimension is resolved from the
// attachment at render time — but it still has to carry every key the record declares, or the
// reader indexes a key that is not there.
check(
	'each item carries every key a record declares',
	isset( $record['items'][0] )
		&& array() === array_diff( Lichtbild_Item::record_keys(), array_keys( $record['items'][0] ) )
		&& 'active' === $record['items'][0]['status'],
	'keys: ' . implode( ', ', isset( $record['items'][0] ) ? array_keys( $record['items'][0] ) : array() )
);

// The control for the cleanup check further down. Without it "the failed write deleted a post"
// is satisfied by an endpoint that deletes the post it just made on every request, which would
// pass that check and break every gallery anyone creates.
check(
	'a gallery whose record stored cleanly is left alone',
	array() === $GLOBALS['lichtbild_deleted'],
	'deletions: ' . count( $GLOBALS['lichtbild_deleted'] )
);

check(
	'the answer names the gallery so the editor can link to it',
	isset( $made['payload']['data']['editUrl'], $made['payload']['data']['images'] )
		&& false !== strpos( (string) $made['payload']['data']['editUrl'], 'post=' . $id )
		&& 2 === $made['payload']['data']['images'],
	'editUrl: ' . ( isset( $made['payload']['data']['editUrl'] ) ? $made['payload']['data']['editUrl'] : '(none)' )
);

// ---------------------------------------------------------------------------------------------
// Titles
// ---------------------------------------------------------------------------------------------

site();
post(
	array(
		'title'  => 'C:\\Photos\\Alps',
		'images' => array( 101 ),
	)
);

// The endpoint unslashes what it reads and re-slashes what it writes, because core unslashes
// again on the way in. Get that wrong and a title loses a backslash per save, silently.
check(
	'a backslash in the name survives the write',
	isset( $GLOBALS['lichtbild_inserted']['post_title'] )
		&& 'C:\\Photos\\Alps' === wp_unslash( $GLOBALS['lichtbild_inserted']['post_title'] ),
	'stored: ' . ( isset( $GLOBALS['lichtbild_inserted']['post_title'] ) ? $GLOBALS['lichtbild_inserted']['post_title'] : '(nothing)' )
);

site();
$unnamed = post( array( 'images' => array( 101 ) ) );

check(
	'an unnamed gallery gets a name rather than none',
	'success' === $unnamed['halt']
		&& isset( $GLOBALS['lichtbild_inserted']['post_title'] )
		&& '' !== trim( (string) $GLOBALS['lichtbild_inserted']['post_title'] ),
	'title: ' . ( isset( $GLOBALS['lichtbild_inserted']['post_title'] ) ? $GLOBALS['lichtbild_inserted']['post_title'] : '(nothing)' )
);

// `title[]=x` is a request nothing stops anyone sending. Cast, it becomes a gallery literally
// named "Array", with a PHP warning to go with it.
site();
$arrayed = post(
	array(
		'title'  => array( 'a', 'b' ),
		'images' => array( 101 ),
	)
);

check(
	'an array-shaped name is read as no name, not as "Array"',
	'success' === $arrayed['halt']
		&& isset( $GLOBALS['lichtbild_inserted']['post_title'] )
		&& false === strpos( (string) $GLOBALS['lichtbild_inserted']['post_title'], 'Array' ),
	'title: ' . ( isset( $GLOBALS['lichtbild_inserted']['post_title'] ) ? $GLOBALS['lichtbild_inserted']['post_title'] : '(nothing)' )
);

// ---------------------------------------------------------------------------------------------
// The guards. Each takes exactly one thing away from the request that just succeeded.
// ---------------------------------------------------------------------------------------------

site();
$forged = post(
	array(
		'nonce'  => 'nonce:something_else',
		'title'  => 'Forged',
		'images' => array( 101 ),
	)
);

check(
	'a nonce made for another action is refused',
	'die:403' === $forged['halt'] && array() === $GLOBALS['lichtbild_inserted'],
	'halt: ' . $forged['halt']
);

site();
$no_nonce = post(
	array(
		'nonce'  => '',
		'title'  => 'No nonce',
		'images' => array( 101 ),
	)
);

check(
	'an absent nonce is refused too',
	'die:403' === $no_nonce['halt'] && array() === $GLOBALS['lichtbild_inserted'],
	'halt: ' . $no_nonce['halt']
);

$site = site();
$site->capability_overrides['create_galleries'] = false;

$uncapable = post(
	array(
		'title'  => 'Not mine to make',
		'images' => array( 101 ),
	)
);

check(
	'someone who may not create posts is refused, with a reason',
	'error 403' === $uncapable['halt']
		&& array() === $GLOBALS['lichtbild_inserted']
		&& ! empty( $uncapable['payload']['data']['message'] ),
	'halt: ' . $uncapable['halt']
);

// The second capability, and it needs its own check: one boolean covering both would pass with
// either half deleted, which is the conjunction trap this project has already paid for once.
$site = site();
$site->capability_overrides['upload_files'] = false;

$no_media = post(
	array(
		'title'  => 'No media rights',
		'images' => array( 101 ),
	)
);

check(
	'someone who may not upload files is refused',
	'error 403' === $no_media['halt'] && array() === $GLOBALS['lichtbild_inserted'],
	'halt: ' . $no_media['halt']
);

// The rule `Lichtbild_Editor` enforces, seen from the writing side. A v2 record on an unmigrated
// site is authoritative for nobody: it would save perfectly and change nothing a visitor sees.
site( false );
$early = post(
	array(
		'title'  => 'Too early',
		'images' => array( 101 ),
	)
);

check(
	'an unmigrated site refuses, and says how to fix it',
	'error 403' === $early['halt']
		&& array() === $GLOBALS['lichtbild_inserted']
		&& false !== stripos( (string) $early['payload']['data']['message'], 'migration' ),
	'message: ' . ( isset( $early['payload']['data']['message'] ) ? $early['payload']['data']['message'] : '(none)' )
);

site();
$GLOBALS['lichtbild_no_post_type'] = true;

$unregistered = post(
	array(
		'title'  => 'No such type',
		'images' => array( 101 ),
	)
);

check(
	'an unregistered post type refuses rather than warning',
	'error 403' === $unregistered['halt'] && array() === $GLOBALS['lichtbild_inserted'],
	'halt: ' . $unregistered['halt']
);

// ---------------------------------------------------------------------------------------------
// What may be put in a gallery
// ---------------------------------------------------------------------------------------------

site();
$not_an_image = post(
	array(
		'title'  => 'Not an attachment',
		'images' => array( 101, 4242 ),
	)
);

check(
	'an ID that is not an attachment refuses the whole request',
	'error 400' === $not_an_image['halt'] && array() === $GLOBALS['lichtbild_inserted'],
	'halt: ' . $not_an_image['halt']
);

// A PDF is an attachment, it is readable, and it is not an image. Stored, it becomes an item
// record that resolves to no dimensions and no srcset — a grid cell with nothing in it and a
// lightbox slide PhotoSwipe cannot size. Refused with one good image alongside it, so the check
// is about the offending ID rather than about an empty request.
site();
$pdf = post(
	array(
		'title'  => 'A brochure',
		'images' => array( 101, 104 ),
	)
);

check(
	'a PDF attachment refuses the whole request',
	'error 400' === $pdf['halt'] && array() === $GLOBALS['lichtbild_inserted'],
	'halt: ' . $pdf['halt']
);

// The second media type is not redundant: it is what says the guard asks whether the attachment
// IS an image, rather than whether it is a PDF. A denylist would pass the check above and let
// every sound file on the site into a gallery.
site();
$audio = post(
	array(
		'title'  => 'An interview',
		'images' => array( 105 ),
	)
);

check(
	'an audio attachment is refused by the same rule',
	'error 400' === $audio['halt'] && array() === $GLOBALS['lichtbild_inserted'],
	'halt: ' . $audio['halt']
);

$site = site();
$site->capability_overrides['read_post:102'] = false;

$unreadable = post(
	array(
		'title'  => 'Someone else\'s',
		'images' => array( 101, 102 ),
	)
);

// Refused rather than quietly dropped: a gallery created with fewer images than were chosen,
// and nothing on the screen saying which or why, is the worse of the two failures.
check(
	'an attachment this user may not read refuses the whole request',
	'error 400' === $unreadable['halt'] && array() === $GLOBALS['lichtbild_inserted'],
	'halt: ' . $unreadable['halt']
);

site();
$nested = post(
	array(
		'title'  => 'Nested',
		'images' => array( array( 101 ) ),
	)
);

check(
	'a nested images value is refused rather than cast',
	'error 400' === $nested['halt'] && array() === $GLOBALS['lichtbild_inserted'],
	'halt: ' . $nested['halt']
);

site();
$scalar = post(
	array(
		'title'  => 'Scalar',
		'images' => '101',
	)
);

check(
	'a scalar where a list belongs is refused',
	'error 400' === $scalar['halt'] && array() === $GLOBALS['lichtbild_inserted'],
	'halt: ' . $scalar['halt']
);

site();
$empty = post( array( 'title' => 'Nothing chosen' ) );

check(
	'choosing nothing is refused, with a reason',
	'error 400' === $empty['halt']
		&& array() === $GLOBALS['lichtbild_inserted']
		&& ! empty( $empty['payload']['data']['message'] ),
	'halt: ' . $empty['halt']
);

site();
$repeated = post(
	array(
		'title'  => 'Twice',
		'images' => array( 101, 101, 102 ),
	)
);

$repeated_record = stored( isset( $repeated['payload']['data']['id'] ) ? (int) $repeated['payload']['data']['id'] : 0 );

check(
	'a repeated attachment is stored once',
	'success' === $repeated['halt']
		&& isset( $repeated_record['items'] )
		&& 2 === count( $repeated_record['items'] ),
	'items: ' . ( isset( $repeated_record['items'] ) ? count( $repeated_record['items'] ) : 0 )
);

// ---------------------------------------------------------------------------------------------
// The write failing
// ---------------------------------------------------------------------------------------------

site();
$GLOBALS['lichtbild_insert_fails'] = true;

$failed = post(
	array(
		'title'  => 'Will not insert',
		'images' => array( 101 ),
	)
);

check(
	'a failed insert answers an error rather than an id',
	'error 500' === $failed['halt'] && ! empty( $failed['payload']['data']['message'] ),
	'halt: ' . $failed['halt']
);

// The half-finished write, which is the worse of the two failures and the one that leaves
// something behind. The post exists and its record does not, so `Lichtbild_Repository` finds no
// v2 record, falls through to an Envira record this gallery has never had, and answers nothing:
// a draft on the Galleries screen that nobody made on purpose and that previews as empty.
$site                   = site();
$site->fail_meta_writes = true;

$half = post(
	array(
		'title'  => 'Inserted, never recorded',
		'images' => array( 101, 102 ),
	)
);

// Two checks rather than one compound. Deleting the `wp_delete_post()` call reddens only the
// second, and removing the whole failure branch reddens only the first — so each says which
// half of the fix is missing instead of both going red together and naming neither.
check(
	'a record that will not store answers an error, not an id',
	'error 500' === $half['halt']
		&& ! empty( $half['payload']['data']['message'] )
		&& ! isset( $half['payload']['data']['id'] ),
	'halt: ' . $half['halt']
);

$removed = $GLOBALS['lichtbild_deleted'];

// The site starts with no posts at all, so "no posts remain" is the whole property: the one row
// this request created is the only one that could be there, and it is gone. Asserted against the
// site rather than against the deletion log, because a log records that the call was made and
// says nothing about whether it did anything.
check(
	'the draft it could not record is force-deleted, not left behind',
	1 === count( $removed )
		&& true === $removed[0]['force']
		&& $removed[0]['id'] > 0
		&& array() === Lichtbild_Test_Site::$instance->posts,
	'deleted: ' . wp_json_encode( $removed ) . ', posts left: ' . count( Lichtbild_Test_Site::$instance->posts )
);

// Real PHP input parsing drops a trailing completion marker at max_input_vars.
site();
$limit = (int) ini_get( 'max_input_vars' );
$large = array( 'images' => array_fill( 0, max( 1100, $limit + 10 ), '101' ), 'images_complete' => '1' );
set_error_handler( static function () { return true; } );
parse_str( http_build_query( $large ), $parsed );
restore_error_handler();
check( 'the oversized request control was actually truncated', $limit > 0 && ! isset( $parsed['images_complete'] ) );
$parsed['images_complete'] = null;
$partial = post( $parsed );
check( 'a truncated image selection creates no partial gallery', 'error 400' === $partial['halt'] && array() === Lichtbild_Test_Site::$instance->posts );

printf( "%s\n", implode( "\n", $report ) );
printf( "\nchecks: %d, failing: %d\n", $checks, $failures );

exit( $failures > 0 ? 1 : 0 );
