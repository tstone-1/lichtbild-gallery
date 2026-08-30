<?php
/**
 * Asserts what the `live_metadata` setting does, and — the half that matters more — what it
 * does not do while it is off.
 *
 *     php tests/live-metadata-test.php
 *
 * This needs no fixture and no database. Every case here is a state somebody has to construct
 * deliberately: an attachment whose library values disagree with the gallery's frozen ones, an
 * attachment that has been deleted, one whose fields were left blank, and one holding nothing
 * but whitespace. A corpus exported from a real site has none of those on demand, which is why
 * this is a file of its own rather than more cases in the render suite.
 *
 * The controls are the point rather than a courtesy. The dangerous direction of this feature is
 * not that it fails to read the library — that shows up on the first page load — it is that it
 * reads the library for a gallery whose owner never asked, silently rewording pages that have
 * been right for years. So every reading assertion has a twin with the setting off, asserting
 * the frozen value verbatim; if the flag were ignored in either direction, one of each pair
 * goes red.
 *
 * WHAT COUNTS AS "THE LIBRARY SAID SOMETHING"
 * ===========================================
 *
 * A field that is empty, or holds only whitespace, is not a value — it is read as the library
 * having nothing for that image, and the gallery's own row answers instead. That is asserted
 * per field here rather than once, because the three fields are three separate reads and the
 * alt text is the one where getting it wrong is worst: the renderer puts the `<img>` alone
 * inside the anchor, so an empty alt leaves a link with no accessible name at all.
 *
 * THE ASSERTION COUNT IS PART OF THE REPORT
 * =========================================
 *
 * This file is straight-line code, so a check that stops running does not fail — it vanishes,
 * and a shorter green run looks exactly like a healthy one. The total is therefore declared and
 * compared, which is the same property `Checks::expect()` buys in tests/render-test.php at a
 * fraction of the machinery. Adding a check means updating one number, deliberately.
 *
 * @package Lichtbild\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

// Diagnostics to stderr, for the reason recorded at the top of tests/render-test.php: a warning
// on stdout is body output, and the checks after it stop measuring what they name.
ini_set( 'display_errors', 'stderr' );

define( 'ABSPATH', __DIR__ );
define( 'LICHTBILD_VERSION', 'test' );
define( 'LICHTBILD_FILE', dirname( __DIR__ ) . '/lichtbild-gallery.php' );
define( 'LICHTBILD_DIR', dirname( __DIR__ ) . '/' );
define( 'LICHTBILD_URL', 'https://example.com/wp-content/plugins/lichtbild-gallery/' );

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

/** Every assertion this file makes when all of it runs. */
define( 'LICHTBILD_EXPECTED_ASSERTIONS', 30 );

require __DIR__ . '/wp-stubs.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-exif.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-config.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-item.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-gallery.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-album-config.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-album.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-repository.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-settings.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-post-types.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-metabox-editor.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-editor.php';

$failures   = 0;
$assertions = 0;

/**
 * Records one assertion and prints it either way.
 *
 * @param string $label What is being asserted.
 * @param bool   $ok    Whether it holds.
 * @param string $note  Context, printed only on failure.
 *
 * @return bool The condition.
 */
function lichtbild_check( $label, $ok, $note = '' ) {
	global $failures, $assertions;

	$assertions++;

	printf( "%s %s\n", $ok ? '[OK]   ' : '[FAIL] ', $label );

	if ( ! $ok ) {
		$failures++;

		if ( '' !== $note ) {
			printf( "         %s\n", $note );
		}
	}

	return (bool) $ok;
}

/**
 * Renders three values into one failure note.
 *
 * @param Lichtbild_Item $item The item read.
 *
 * @return string The note.
 */
function lichtbild_read( Lichtbild_Item $item ) {
	return sprintf(
		'title %s, caption %s, alt %s',
		var_export( $item->title(), true ),
		var_export( $item->caption(), true ),
		var_export( $item->alt(), true )
	);
}

// ---------------------------------------------------------------------------
// The media library these items are read against.
//
// Constructed rather than loaded: each attachment is one shape of the question "what does the
// library hold", and none of them can be relied on to exist in an exported corpus.
// ---------------------------------------------------------------------------

$site = new Lichtbild_Test_Site();

$site->siteurl     = 'https://example.com';
$site->attachments = array(
	// Everything present, and everything different from the frozen record, so a reader that
	// took the wrong side is visible in the value rather than only in a boolean.
	9001 => array(
		'title'   => 'Library title',
		'excerpt' => 'Library caption',
		'alt'     => 'Library alt',
	),
	// The attachment exists and the library has nothing to say about it. This is the ordinary
	// case on a site whose photographs were uploaded years before anyone filled the fields in.
	9002 => array(),
	// Fields saved empty rather than never filled in. WordPress can tell those two apart — the
	// meta row exists holding `''` — and this plugin deliberately does not: both mean "nothing
	// to show", because the alternative is stripping the accessible name off an image whose
	// owner simply never typed one. The title is present so a reader that honoured the empty
	// alt produces a value this file can name rather than an indistinguishable empty string.
	9003 => array(
		'title'   => 'Blank-alt file',
		'excerpt' => '',
		'alt'     => '',
	),
	// Whitespace, which is not a value either. Stored this way by a paste that picked up a
	// newline, and it has to read as "the library said nothing" rather than blank a real row.
	9004 => array(
		'title'   => '   ',
		'excerpt' => "\n\t",
		'alt'     => ' ',
	),
	// A library caption carrying markup: some the lightbox may have, some it may not.
	9005 => array(
		'excerpt' => 'Library <em>caption</em><a href="https://example.com/" onclick="steal()">x</a><script>alert(1)</script>',
	),
);

Lichtbild_Test_Site::$instance = $site;

/** The frozen values every item below carries unless it says otherwise. */
$frozen = array(
	'status'  => 'active',
	'src'     => 'https://example.com/wp-content/uploads/photo.jpg',
	'link'    => 'https://example.com/wp-content/uploads/photo.jpg',
	'title'   => 'Frozen title',
	'caption' => 'Frozen caption',
	'alt'     => 'Frozen alt',
);

/**
 * Builds one item over the frozen record, with the live reading switched either way.
 *
 * @param int   $id      Attachment ID; one absent from the library models a deleted attachment.
 * @param bool  $live    Whether the media library wins.
 * @param array $changes Frozen fields to override.
 *
 * @return Lichtbild_Item The item.
 */
function lichtbild_live_item( $id, $live, array $changes = array() ) {
	global $frozen;

	$item = new Lichtbild_Item( $id, array_merge( $frozen, $changes ) );

	$item->use_live_metadata( $live );

	return $item;
}

// ---------------------------------------------------------------------------
// Off, which is every gallery until its owner says otherwise.
//
// The population is the attachment whose library values disagree with the frozen ones in all
// three fields. An item whose two copies say the same thing cannot tell the two readings apart,
// so it would be an assertion that holds by construction.
// ---------------------------------------------------------------------------

$off = lichtbild_live_item( 9001, false );

lichtbild_check(
	'off, the frozen title, caption and alt all win over the library',
	'Frozen title' === $off->title()
		&& 'Frozen caption' === $off->caption()
		&& 'Frozen alt' === $off->alt(),
	lichtbild_read( $off )
);

// The v1 fallback, which predates this setting and must survive it: with the frozen field
// empty, the reader has always reached the attachment. Different rule, same three functions,
// and easy to break while adding one.
$blank_frozen = lichtbild_live_item(
	9001,
	false,
	array(
		'title'   => '',
		'caption' => '',
		'alt'     => '',
	)
);

lichtbild_check(
	'off, an empty frozen field still falls back to the library',
	'Library title' === $blank_frozen->title()
		&& 'Library caption' === $blank_frozen->caption()
		&& 'Library alt' === $blank_frozen->alt(),
	lichtbild_read( $blank_frozen )
);

// ---------------------------------------------------------------------------
// On.
// ---------------------------------------------------------------------------

$live = lichtbild_live_item( 9001, true );

lichtbild_check(
	'on, the library title wins over the frozen one',
	'Library title' === $live->title(),
	lichtbild_read( $live )
);

lichtbild_check(
	'on, the library caption wins over the frozen one',
	'Library caption' === $live->caption(),
	lichtbild_read( $live )
);

lichtbild_check(
	'on, the library alt wins over the frozen one',
	'Library alt' === $live->alt(),
	lichtbild_read( $live )
);

// Four shapes of "the library cannot answer", asserted separately because they arrive by
// different routes: no value stored at all, a value stored empty, a value that is only
// whitespace, and an attachment deleted out from under the gallery. Each leaves the frozen row
// as the only copy.
//
// #9003 is the one worth reading twice. Its title is a real library value and its caption and
// alt are stored empty, so the expectation is mixed — which is the assertion that the three
// fields are three independent reads rather than one decision applied three times.
foreach ( array(
	array( 9002, 'a library with nothing stored', 'Frozen title', 'Frozen caption', 'Frozen alt' ),
	array( 9003, 'a library field saved empty', 'Blank-alt file', 'Frozen caption', 'Frozen alt' ),
	array( 9004, 'a library field holding only whitespace', 'Frozen title', 'Frozen caption', 'Frozen alt' ),
	array( 9099, 'an attachment that has been deleted', 'Frozen title', 'Frozen caption', 'Frozen alt' ),
) as $case ) {
	list( $id, $what, $title, $caption, $alt ) = $case;

	$quiet = lichtbild_live_item( $id, true );

	lichtbild_check(
		'on, ' . $what . ' falls back to the frozen row',
		$title === $quiet->title()
			&& $caption === $quiet->caption()
			&& $alt === $quiet->alt(),
		'#' . $id . ' ' . lichtbild_read( $quiet )
	);
}

// The consequence that decides the empty-alt reading, asserted as itself rather than left to be
// inferred from the check above: no image ever comes back with no accessible name because of
// this setting. `alt()` ends at `title()`, so the last resort is a name rather than nothing.
$named = true;

foreach ( array( 9001, 9002, 9003, 9004, 9005, 9099 ) as $id ) {
	if ( '' === lichtbild_live_item( $id, true )->alt() ) {
		$named = false;
	}
}

lichtbild_check(
	'on, no image is left with an empty alt attribute',
	$named,
	'at least one image would render as an unnamed link'
);

// A library caption reaches the lightbox through `innerHTML` exactly as a frozen one does, so it
// goes through the same allowlist. Three directions, because "no script" is also what a reader
// that dropped the caption entirely produces, and because `strip_tags()` alone keeps the
// attribute that matters.
$markup = lichtbild_live_item( 9005, true )->caption();

lichtbild_check(
	'on, a library caption keeps its markup, loses its script and loses its handlers',
	false !== strpos( $markup, '<em>' )
		&& false === stripos( $markup, '<script' )
		&& false === stripos( $markup, 'onclick' ),
	'read ' . var_export( $markup, true )
);

// ---------------------------------------------------------------------------
// The wiring.
//
// The setting is stored per gallery and read per item, and `Lichtbild_Gallery` is the only
// object holding both. These are the checks that would go red if it were stored and never
// consulted, which is the failure this whole feature is one line away from.
// ---------------------------------------------------------------------------

$wired = new Lichtbild_Gallery(
	1,
	array( 'live_metadata' => true ),
	array( lichtbild_live_item( 9001, false ), lichtbild_live_item( 9005, false ) )
);

lichtbild_check(
	'a gallery with the setting on switches every one of its items on',
	'Library title' === $wired->items()[0]->title()
		&& false !== strpos( $wired->items()[1]->caption(), '<em>' ),
	'first ' . lichtbild_read( $wired->items()[0] ) . '; second ' . lichtbild_read( $wired->items()[1] )
);

// The control, and the one that catches a constructor that enables rather than sets: the items
// handed in have already been switched ON, and a gallery whose setting is off must switch them
// back. Without this, a reader that only ever calls `use_live_metadata( true )` passes.
$unwired = new Lichtbild_Gallery(
	1,
	array( 'live_metadata' => false ),
	array( lichtbild_live_item( 9001, true ), lichtbild_live_item( 9005, true ) )
);

lichtbild_check(
	'a gallery with the setting off switches every one of its items off',
	'Frozen title' === $unwired->items()[0]->title()
		&& 'Frozen caption' === $unwired->items()[1]->caption(),
	'first ' . lichtbild_read( $unwired->items()[0] ) . '; second ' . lichtbild_read( $unwired->items()[1] )
);

// A record written before this key existed has to behave as one written after it with the box
// unticked. `fill()` is what makes that true, and this is the check that says so end to end
// rather than at the schema.
$older = new Lichtbild_Gallery( 1, array( 'layout' => 'columns' ), array( lichtbild_live_item( 9001, true ) ) );

lichtbild_check(
	'a record predating the setting reads as off',
	'Frozen title' === $older->items()[0]->title(),
	lichtbild_read( $older->items()[0] )
);

// ---------------------------------------------------------------------------
// Nothing here writes.
//
// The claim on the settings screen is that Lichtbild never writes to the media library, and it
// is the claim an owner is trusting when they tick the box. Asserted against recorded CALLS
// rather than against stored rows: this stub keeps only the meta keys it is asked about, so a
// write to an attachment's alt text would land nowhere and a before/after comparison of the
// fixture could not fail.
// ---------------------------------------------------------------------------

$site->writes = array();

foreach ( array( 9001, 9002, 9003, 9004, 9005, 9099 ) as $id ) {
	$reading = lichtbild_live_item( $id, true );

	$reading->title();
	$reading->caption();
	$reading->alt();
	$reading->tags();
}

lichtbild_check(
	'reading a gallery with the setting on writes nothing',
	array() === $site->writes,
	count( $site->writes ) . ' write(s): ' . wp_json_encode( $site->writes )
);

// The control for the check above, and without it that one is satisfied by a recorder that
// never records. One deliberate write must show up.
$site->writes = array();

wp_set_object_terms( 9001, array( 'probe' ), $site->tag_taxonomy() );

lichtbild_check(
	'the write recorder can see a write at all',
	1 === count( $site->writes ) && 'wp_set_object_terms' === $site->writes[0]['fn'],
	wp_json_encode( $site->writes )
);

unset( $site->attachments[9001]['tags'] );
$site->writes = array();

// ---------------------------------------------------------------------------
// The schema, and what a submitted form does to it.
// ---------------------------------------------------------------------------

$defaults = Lichtbild_Config::defaults();

lichtbild_check(
	'the setting exists and defaults to off',
	array_key_exists( 'live_metadata', $defaults ) && false === $defaults['live_metadata'],
	array_key_exists( 'live_metadata', $defaults )
		? 'defaulted to ' . var_export( $defaults['live_metadata'], true )
		: 'the key is not in the schema at all'
);

lichtbild_check(
	'a stored record missing the key fills to off',
	false === Lichtbild_Config::fill( array( 'layout' => 'columns' ) )['live_metadata'],
	'filled to ' . var_export( Lichtbild_Config::fill( array( 'layout' => 'columns' ) )['live_metadata'], true )
);

// An unticked checkbox sends nothing, which is why the sanitiser reads absence as false rather
// than as "use the default" — see the docblock on `Lichtbild_Config::sanitize()`. Both
// directions, because a sanitiser hard-coded to false satisfies the first alone.
$submitted_off = Lichtbild_Config::sanitize( array() );
$submitted_on  = Lichtbild_Config::sanitize( array( 'live_metadata' => '1' ) );

lichtbild_check(
	'an unsubmitted checkbox switches the setting off',
	false === $submitted_off['live_metadata'],
	'read ' . var_export( $submitted_off['live_metadata'], true )
);

lichtbild_check(
	'a ticked checkbox switches the setting on, as a boolean',
	true === $submitted_on['live_metadata'],
	'read ' . var_export( $submitted_on['live_metadata'], true )
);

// Nothing Envira stores may switch this on. Envira has no equivalent setting, so a key of this
// name in one of its config arrays is either a hand-edit or a collision, and a conversion that
// honoured it would change the words on a migrated page without anybody choosing to.
lichtbild_check(
	'no envira config can switch the setting on',
	false === Lichtbild_Config::from_envira( array( 'live_metadata' => 1 ) )['live_metadata']
		&& false === Lichtbild_Config::from_envira( array( 'live_metadata' => 'True' ) )['live_metadata']
		&& false === Lichtbild_Config::from_envira( array() )['live_metadata'],
	'a converted gallery came back with the setting on'
);

// ---------------------------------------------------------------------------
// The stub this file reads the library through.
//
// `get_post_meta()`'s two shapes are not interchangeable in WordPress, and a stub that ignores
// `$single` models a WordPress that cannot tell an absent meta row from one holding an empty
// string. That is the distinction this feature decided NOT to act on — which is a decision only
// as long as the harness can still express it, so it is asserted here rather than assumed.
// ---------------------------------------------------------------------------

lichtbild_check(
	'the stub returns a scalar for $single and a list otherwise',
	'Library alt' === get_post_meta( 9001, '_wp_attachment_image_alt', true )
		&& array( 'Library alt' ) === get_post_meta( 9001, '_wp_attachment_image_alt', false ),
	'single ' . var_export( get_post_meta( 9001, '_wp_attachment_image_alt', true ), true )
		. ', list ' . var_export( get_post_meta( 9001, '_wp_attachment_image_alt', false ), true )
);

lichtbild_check(
	'the stub separates an absent meta row from one stored empty',
	array() === get_post_meta( 9002, '_wp_attachment_image_alt', false )
		&& array( '' ) === get_post_meta( 9003, '_wp_attachment_image_alt', false )
		&& '' === get_post_meta( 9002, '_wp_attachment_image_alt', true )
		&& '' === get_post_meta( 9003, '_wp_attachment_image_alt', true ),
	'absent ' . var_export( get_post_meta( 9002, '_wp_attachment_image_alt', false ), true )
		. ', empty ' . var_export( get_post_meta( 9003, '_wp_attachment_image_alt', false ), true )
);

// The gallery and album records are read with `$single` true everywhere in the plugin, and that
// path had to keep behaving exactly as before. Asserted because the change above touched it.
$site->galleries[7] = array(
	'id'        => 7,
	'lichtbild' => array(
		'settings' => array( 'live_metadata' => true ),
		'items'    => array(),
	),
);

lichtbild_check(
	'gallery meta still reads as a record, and an absent one as an empty string',
	is_array( get_post_meta( 7, '_lichtbild_gallery', true ) )
		&& '' === get_post_meta( 8, '_lichtbild_gallery', true )
		&& '' === get_post_meta( 8, '_lichtbild_album', true ),
	var_export( get_post_meta( 7, '_lichtbild_gallery', true ), true )
);

// ---------------------------------------------------------------------------
// The edit screen.
//
// The checkbox and the note are two halves of one explanation, and they are on different
// metaboxes — the note above the image rows, the box that governs it under Gallery Settings.
// Rendered rather than read, so what is asserted is what an editor is shown.
// ---------------------------------------------------------------------------

$editor_id = 4242;

$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = Lichtbild_Settings::SCHEMA_MIGRATED;
$site->posts[ $editor_id ]                          = array( 'post_type' => 'lichtbild_gallery' );

/**
 * Renders both metaboxes for a gallery whose setting is in the given position.
 *
 * @param bool $live Whether the gallery reads the media library.
 *
 * @return array{settings:string,images:string} The two rendered forms.
 */
function lichtbild_render_editor( $live ) {
	global $site, $editor_id, $frozen;

	$site->galleries[ $editor_id ] = array(
		'id'        => $editor_id,
		'lichtbild' => array(
			'settings' => array( 'live_metadata' => (bool) $live ),
			'items'    => array( array_merge( $frozen, array( 'id' => 9001 ) ) ),
		),
	);

	$editor = new Lichtbild_Editor( new Lichtbild_Settings(), new Lichtbild_Repository() );
	$post   = (object) array( 'ID' => $editor_id );

	ob_start();
	$editor->render_settings_box( $post );
	$settings = (string) ob_get_clean();

	ob_start();
	$editor->render_images_box( $post );
	$images = (string) ob_get_clean();

	return array(
		'settings' => $settings,
		'images'   => $images,
	);
}

$screen_off = lichtbild_render_editor( false );
$screen_on  = lichtbild_render_editor( true );

// The id is what the script binds to and the name is what the save path reads; a form carrying
// one without the other is half-wired in a way neither side reports.
lichtbild_check(
	'the settings form offers the checkbox, by id and by name',
	false !== strpos( $screen_off['settings'], 'id="lichtbild-live_metadata"' )
		&& false !== strpos( $screen_off['settings'], 'name="lichtbild_settings[live_metadata]"' ),
	'the checkbox is not on the form'
);

lichtbild_check(
	'the checkbox reflects the stored position, both ways',
	false === strpos( $screen_off['settings'], 'name="lichtbild_settings[live_metadata]" value="1" checked' )
		&& false !== strpos( $screen_on['settings'], 'name="lichtbild_settings[live_metadata]" value="1" checked' ),
	'off form checked: ' . var_export( false !== strpos( $screen_off['settings'], 'value="1" checked' ), true )
);

// The description belongs to the group rather than to the screen, so it has to appear next to
// the box it explains and nowhere else. Its content is asserted because the wording is the
// whole point: it must say the rows are a fallback, not that they are dead.
lichtbild_check(
	'the checkbox carries the explanation of what it does',
	false !== strpos( $screen_off['settings'], 'falls back to the row below' )
		&& false !== strpos( $screen_off['settings'], 'never writes to the Media Library' ),
	'the group description is missing or says something else'
);

lichtbild_check(
	'the note above the rows is printed either way and hidden when the setting is off',
	false !== strpos( $screen_off['images'], 'id="lichtbild-editor-live-note" style="display:none"' )
		&& false !== strpos( $screen_on['images'], 'id="lichtbild-editor-live-note"' )
		&& false === strpos( $screen_on['images'], 'id="lichtbild-editor-live-note" style="display:none"' ),
	'the note is absent, or shown in the wrong position'
);

lichtbild_check(
	'the note says the rows are still used where the library is empty',
	false !== strpos( $screen_on['images'], 'shown only where the Media Library field is empty' ),
	'the note says something else about what the rows are for'
);

// Whether the browser actually toggles the note is not observable from here, and saying so is
// the point: what IS observable is that the two ids the script binds to are the two ids the
// forms above emit. Both are read out of the rendered markup rather than written down, so a
// rename on either side leaves this check with nothing to find.
$script = (string) file_get_contents( LICHTBILD_DIR . 'assets/js/editor.js' );

lichtbild_check(
	'the editor script binds the ids the forms actually emit',
	false !== strpos( $script, "'#lichtbild-live_metadata'" )
		&& false !== strpos( $script, "'#lichtbild-editor-live-note'" )
		&& false !== strpos( $screen_off['settings'], 'id="lichtbild-live_metadata"' )
		&& false !== strpos( $screen_off['images'], 'id="lichtbild-editor-live-note"' ),
	'the script and the form name different elements'
);

// ---------------------------------------------------------------------------

lichtbild_check(
	'every declared assertion ran',
	// Counting itself, which is why this is one more than the assertions above it.
	LICHTBILD_EXPECTED_ASSERTIONS === $assertions,
	$assertions . ' assertions ran, ' . LICHTBILD_EXPECTED_ASSERTIONS . ' expected'
);

printf(
	"\n%d assertions, %d failing\n",
	$assertions,
	$failures
);

exit( $failures > 0 ? 1 : 0 );
