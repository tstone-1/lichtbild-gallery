<?php
/**
 * Breaks the code on purpose, one edit at a time, and reports which check noticed.
 *
 * Usage: php tests/mutations.php [--names] [id ...]
 *
 * `--names` prints the full set of checks each mutation turned red rather than `(+N more)`,
 * which is what makes a coverage inventory possible without patching this file by hand.
 *
 * A green suite is worth nothing until each check has been shown capable of going red, and
 * "shown" has to mean shown — a check that cannot fail passes exactly like one that can. Each
 * entry below names the single edit it makes and, in advance, the check it expects to kill.
 * Getting that prediction wrong is a result: either the check does not cover what it claims,
 * or the mutation changes nothing, and both are worth knowing.
 *
 * The harness is written to the same standard it enforces, because a mutation harness that
 * lies is worse than none — `SURVIVED` reads as a gap in the tests rather than a gap in the
 * harness, which is the most misleading verdict it can produce:
 *
 * - **Files are restored by BYTES and the restore is verified by digest.** Reading and writing
 *   as text rewrites line endings on some platforms, and the comparison that would catch it is
 *   read back through the same translation, so it passes for the reason it broke.
 * - **A mutation whose target text is not found, or is found twice, is BROKEN, not SURVIVED.**
 *   Refactoring moves the lines these point at, and a mutation that silently patched nothing
 *   would report the tests as fine.
 * - **A run with no summary line is BROKEN.** A fatal error produces no failures either, which
 *   is indistinguishable from a surviving mutation.
 * - **The parsed failures are cross-checked against the printed total.** The report pads the
 *   check name to a fixed width, so a parser that assumed the padding would stop matching the
 *   day a check got a longer name — silently, and in the direction that looks like good news.
 *
 * @package Lichtbild\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

$root = dirname( __DIR__ ) . '/';

$editor     = 'includes/class-lichtbild-editor.php';
$config     = 'includes/class-lichtbild-config.php';
$item       = 'includes/class-lichtbild-item.php';
$repository = 'includes/class-lichtbild-repository.php';
$renderer   = 'includes/class-lichtbild-renderer.php';
$assets     = 'includes/class-lichtbild-assets.php';
$album      = 'includes/class-lichtbild-album-config.php';
$migration  = 'includes/class-lichtbild-migration.php';
$standalone = 'includes/class-lichtbild-standalone.php';
$ajax       = 'includes/class-lichtbild-ajax.php';
$shortcode  = 'includes/class-lichtbild-shortcode.php';
$album_editor = 'includes/class-lichtbild-album-editor.php';
$editor       = 'includes/class-lichtbild-editor.php';
$metabox      = 'includes/class-lichtbild-metabox-editor.php';
$screen       = 'includes/class-lichtbild-migration-screen.php';
$block        = 'includes/class-lichtbild-block.php';
$settings_php = 'includes/class-lichtbild-settings.php';

/**
 * The mutations, each with the check it is predicted to kill.
 *
 * @var array<int,array{id:string,file:string,find:string,replace:string,expect:string,why:string}>
 */
$mutations = array(
	// The guard chain and the ordered collect are one copy now, shared by both editors, so these
	// split the way the visibility predicate's did: two that delete an editor's *call* into the
	// shared code, proving each twin still consults it, and one per *leg* of the shared code
	// itself. Neither kind implies the other — a twin that stopped asking would survive every leg
	// mutation, and a broken leg would survive every call-site mutation in the twin that does not
	// exercise it. The leg mutations each go red in both editors, which is the coverage the six
	// copies claimed and did not have.
	array(
		'id'      => 'E1',
		'file'    => $editor,
		'find'    => "\t\tif ( ! \$this->authorised_save( \$post_id ) ) {\n\t\t\treturn;\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn;\n\t\t}",
		'expect'  => 'a save with no nonce field changes nothing',
		'why'     => 'quick edit carries no images, so a save that proceeds empties the gallery',
	),
	array(
		'id'      => 'E1b',
		'file'    => $metabox,
		'find'    => "\t\tif ( ! isset( \$_POST[ static::NONCE ] ) ) {\n\t\t\treturn false;\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn false;\n\t\t}",
		'expect'  => 'a save with no nonce field changes nothing',
		'why'     => 'the verification below refuses an absent nonce too; only this leg stops the warning',
	),
	array(
		'id'      => 'E2',
		'file'    => $metabox,
		'find'    => "if ( ! wp_verify_nonce( \$nonce, static::NONCE_ACTION . \$post_id ) ) {",
		'replace' => "if ( false ) {",
		'expect'  => 'a nonce for another gallery is refused',
		'why'     => 'the nonce is bound to the post being edited',
	),
	array(
		'id'      => 'E3',
		'file'    => $metabox,
		'find'    => "if ( ! current_user_can( 'edit_post', \$post_id ) ) {",
		'replace' => "if ( false ) {",
		'expect'  => 'a save without the capability is refused',
		'why'     => 'authorisation is the handler\'s job, not the screen\'s',
	),
	array(
		'id'      => 'E4',
		'file'    => $metabox,
		'find'    => "\t\tif ( get_post_type( \$post_id ) !== \$this->post_type() ) {\n\t\t\treturn false;\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn false;\n\t\t}",
		'expect'  => 'a save aimed at another post type is refused',
		'why'     => 'galleries and albums are saved by the same hook and live in the same table',
	),
	array(
		'id'      => 'E5',
		'file'    => $metabox,
		// Both legs of the chain that end in `has_migrated()` read alike, so the comment above
		// this one is what makes the target unique -- `editing_our_type()` ends the same way.
		'find'    => "\t\t// anywhere else produces a record nothing reads and an edit that appears not to save.\n\t\treturn \$this->settings->has_migrated();",
		'replace' => "\t\t// anywhere else produces a record nothing reads and an edit that appears not to save.\n\t\treturn true;",
		'expect'  => 'an unmigrated site refuses to save',
		'why'     => 'a v2 record on an unmigrated site is a record nothing reads; red for both editors',
	),
	array(
		'id'      => 'E6',
		'file'    => $metabox,
		'find'    => "\t\tforeach ( \$keys as \$key ) {",
		'replace' => "\t\tforeach ( array_keys( \$submitted ) as \$key ) {",
		'expect'  => 'stored order follows the submitted order',
		'why'     => 'order is submitted explicitly, not inferred from the field order',
	),
	array(
		'id'      => 'E6b',
		'file'    => $editor,
		'find'    => "\t\tforeach ( \$this->ordered_rows( \$submitted, \$order ) as \$row ) {",
		'replace' => "\t\tforeach ( array_values( \$submitted ) as \$row ) {",
		'expect'  => 'stored order follows the submitted order',
		'why'     => 'the gallery editor has to route its rows through the shared collect, not walk the map',
	),
	array(
		'id'      => 'E7',
		'file'    => $metabox,
		'find'    => "|| isset( \$seen[ \$key ] ) ) {",
		'replace' => "|| false ) {",
		'expect'  => 'a row named twice is stored once',
		'why'     => 'a duplicated key would silently double an image',
	),
	array(
		'id'      => 'E8',
		'file'    => $editor,
		'find'    => "if ( \$record['id'] > 0 && array_key_exists( 'tags', \$row ) ) {",
		'replace' => "if ( \$record['id'] > 0 ) {",
		'expect'  => 'a row with no tag field leaves tags alone',
		'why'     => 'a row without the field would clear that image\'s tags everywhere',
	),
	array(
		'id'      => 'E9',
		'file'    => $editor,
		'find'    => "\t\twp_set_object_terms(\n\t\t\t\$attachment_id,",
		'replace' => "\t\t\$attachment_id = 0;\n\n\t\twp_set_object_terms(\n\t\t\t\$attachment_id,",
		'expect'  => 'tags submitted by the editor are stored',
		'why'     => 'the tag write has to reach the attachment it names',
	),
	array(
		'id'      => 'E10',
		'file'    => $editor,
		'find'    => "if ( \$parent <= 0 || get_post_type( \$parent ) !== \$this->post_type() ) {",
		'replace' => "if ( false ) {",
		'expect'  => 'the media library carries an image\'s tags',
		'why'     => 'the extra term lookup is gated on the frame belonging to one of our galleries',
	),
	array(
		'id'      => 'E11',
		'file'    => $editor,
		'find'    => "\t\tif ( ! \$this->settings->has_migrated() ) {\n\t\t\t\$this->render_unavailable(",
		'replace' => "\t\tif ( false ) {\n\t\t\t\$this->render_unavailable(",
		'expect'  => 'an unmigrated edit screen offers no fields',
		'why'     => 'a form that cannot save must not be drawn',
	),
	array(
		'id'      => 'E12',
		'file'    => $editor,
		'find'    => "\t\t\$type = \$this->post_type();\n\n\t\tadd_meta_box(",
		'replace' => "\t\t\$type = Lichtbild_Repository::GALLERY_POST_TYPE;\n\n\t\tadd_meta_box(",
		'expect'  => 'metaboxes attach to the post type that exists',
		'why'     => 'the post type changes at migration',
	),
	array(
		'id'      => 'E13',
		'file'    => $editor,
		'find'    => "\t\techo null === \$gallery ? 0 : (int) \$gallery->count();",
		'replace' => "\t\techo 0;",
		'expect'  => 'the list column counts the stored images',
		'why'     => 'the column reports the gallery, not a constant',
	),
	array(
		'id'      => 'E18',
		'file'    => $editor,
		'find'    => "\t\t\$gallery = \$this->repository->gallery( (int) \$post_id );\n\n\t\techo null === \$gallery ? 0 : (int) \$gallery->count();",
		'replace' => "\t\t\$record = get_post_meta( (int) \$post_id, Lichtbild_Repository::GALLERY_META_V2, true );\n\t\techo (int) ( is_array( \$record ) && isset( \$record['items'] ) ? count( \$record['items'] ) : 0 );",
		'expect'  => 'the list column counts the stored images',
		'why'     => 'which record is authoritative is the thing that changes at migration',
	),
	array(
		'id'      => 'E13b',
		'file'    => $metabox,
		'find'    => "\t\tforeach ( \$ours as \$key => \$label ) {\n\t\t\tif ( ! isset( \$out[ \$key ] ) ) {\n\t\t\t\t\$out[ \$key ] = \$label;\n\t\t\t}\n\t\t}\n\n\t\treturn \$out;",
		'replace' => "\t\treturn \$out;",
		'expect'  => 'the list columns survive a table with no date',
		'why'     => 'any plugin can remove the date column, and then both of ours vanish -- on both screens',
	),
	array(
		'id'      => 'E14',
		'file'    => $editor,
		'find'    => "\t\t\$this->row_number( \$settings, 'gutter', __( 'Gutter', 'lichtbild-gallery' ), 0, 100,",
		'replace' => "\t\t\$this->row_number( \$settings, 'guttr', __( 'Gutter', 'lichtbild-gallery' ), 0, 100,",
		'expect'  => 'the settings form has a field for every setting',
		'why'     => 'a setting with no field on the form saves as its default forever',
	),
	array(
		'id'      => 'E15',
		'file'    => $editor,
		'find'    => "\t\t\t\t<input type=\"hidden\" name=\"lichtbild_items[{{ data.key }}][link]\" value=\"{{ data.link }}\" />\n",
		'replace' => "",
		'expect'  => 'both item templates carry every record field',
		'why'     => 'a field missing from one template silently stops surviving a save',
	),
	array(
		'id'      => 'E16',
		'file'    => $editor,
		'find'    => "\t\t\t\t'settings' => Lichtbild_Config::sanitize( \$settings ),",
		'replace' => "\t\t\t\t'settings' => Lichtbild_Config::defaults(),",
		'expect'  => 'a save round-trips the gallery byte for byte',
		'why'     => 'saving a gallery unchanged has to leave the page unchanged',
	),
	array(
		'id'      => 'C1',
		'file'    => $config,
		'find'    => "\t\t\t\$out[ \$key ] = ! empty( \$input[ \$key ] );",
		'replace' => "\t\t\t\$out[ \$key ] = array_key_exists( \$key, \$input ) ? ! empty( \$input[ \$key ] ) : \$defaults[ \$key ];",
		'expect'  => 'an unchecked box switches its setting off',
		'why'     => 'read with the stored-record rule, a box defaulting to true could never be unticked',
	),
	array(
		'id'      => 'C2',
		'file'    => $config,
		'find'    => "\t\treturn in_array( \$value, \$allowed, true ) ? \$value : \$default;",
		'replace' => "\t\treturn '' !== \$value ? \$value : \$default;",
		'expect'  => 'an unknown choice falls back to the default',
		'why'     => 'a select is not a promise about what arrives',
	),
	array(
		'id'      => 'C3',
		'file'    => $config,
		'find'    => "\t\treturn (int) min( \$max, max( \$min, (int) \$input[ \$key ] ) );",
		'replace' => "\t\t\$given = (int) \$input[ \$key ];\n\n\t\treturn ( \$given < \$min || \$given > \$max ) ? (int) \$default : \$given;",
		'expect'  => 'a number out of range is clamped, not reset',
		'why'     => 'reverting to the default leaves the editor showing a number nobody typed',
	),
	array(
		'id'      => 'C4',
		'file'    => $config,
		'find'    => "\t\tif ( 0 === \$out['per_page'] ) {\n\t\t\t\$out['pagination'] = false;\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\t\$out['pagination'] = false;\n\t\t}",
		'expect'  => 'pagination without a page size is off',
		'why'     => 'pagination with no page size renders nothing',
	),
	array(
		'id'      => 'C5',
		'file'    => $config,
		'find'    => "\t\treturn array_values( array_filter( \$allowed, function ( \$field ) use ( \$given ) {\n\t\t\treturn in_array( \$field, \$given, true );\n\t\t} ) );",
		'replace' => "\t\treturn array_values( array_intersect( \$given, \$allowed ) );",
		'expect'  => 'a list setting keeps its canonical order',
		'why'     => 'the stored order must not depend on the order the browser sent',
	),
	array(
		'id'      => 'I1',
		'file'    => $item,
		'find'    => "\t\tif ( \$id <= 0 && '' === \$src ) {\n\t\t\treturn null;\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn null;\n\t\t}",
		'expect'  => 'a row with nothing to show is dropped',
		'why'     => 'a row naming no image renders as an empty box',
	),
	array(
		'id'      => 'I2',
		'file'    => $item,
		'find'    => "\t\treturn '' === \$url ? '' : (string) esc_url_raw( \$url, array( 'http', 'https' ) );",
		'replace' => "\t\treturn \$url;",
		'expect'  => 'a dangerous url does not survive a save',
		'why'     => 'a stored javascript: url reaches an anchor href and the JSON endpoint',
	),
	array(
		'id'      => 'I3',
		'file'    => $item,
		'find'    => "\t\t\t'caption' => wp_kses_post( self::text( \$input, 'caption' ) ),",
		'replace' => "\t\t\t'caption' => self::text( \$input, 'caption' ),",
		'expect'  => 'a caption keeps its markup and loses its script',
		'why'     => 'the lightbox inserts captions with innerHTML',
	),
	array(
		'id'      => 'I4',
		'file'    => $item,
		'find'    => "\t\t\t'alt'     => sanitize_text_field( self::text( \$input, 'alt' ) ),\n",
		'replace' => "",
		'expect'  => 'the editor and the migration agree on the record shape',
		'why'     => 'a field the converter writes and the editor drops vanishes on the first save',
	),
	array(
		'id'      => 'P0b',
		'file'    => 'includes/class-lichtbild-standalone.php',
		'find'    => "\t\t} elseif ( is_singular( Lichtbild_Post_Types::album_type( \$this->settings ) ) ) {\n\t\t\t\$kind = 'album';",
		'replace' => "\t\t} elseif ( false ) {\n\t\t\t\$kind = 'album';",
		'expect'  => 'an album is appended to its own page',
		'why'     => 'an album permalink answers 200 and renders nothing without this',
	),
	// The visibility rule used to be copied into each publishing path, which is how the album
	// grid came to be missing it. It is one predicate now, so the mutations split in two: three
	// that delete a *call site*, proving each path still consults it, and two that break a *leg*
	// of the predicate itself. Neither kind implies the other — a path that stopped asking would
	// survive every leg mutation, and a leg that stopped working would survive every call-site
	// mutation in the paths that do not exercise it.
	array(
		'id'      => 'P1',
		'file'    => $standalone,
		'find'    => "\t\treturn \$this->repository->is_viewable( get_the_ID() ) ? \$kind : '';",
		'replace' => "\t\treturn \$kind;",
		'expect'  => 'a password-protected gallery is not appended to its page',
		'why'     => 'the filter runs after WordPress has swapped in the password form',
	),
	array(
		'id'      => 'P0',
		'file'    => 'includes/class-lichtbild-standalone.php',
		'find'    => "\t\tif ( ! \$this->settings->should_take_over() ) {\n\t\t\treturn '';\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn '';\n\t\t}",
		'expect'  => 'standalone defers to envira on the gallery\'s own page',
		'why'     => 'both plugins have a standalone filter, so the permalink renders twice',
	),
	array(
		'id'      => 'P2',
		'file'    => $ajax,
		'find'    => "\t\tif ( ! \$this->repository->is_viewable( \$gallery->id() ) ) {",
		'replace' => "\t\tif ( false ) {",
		'expect'  => 'the ajax endpoints refuse a password-protected gallery',
		'why'     => 'the endpoints answer anyone who knows a gallery id, rendered page or not',
	),
	array(
		'id'      => 'P3',
		'file'    => 'includes/class-lichtbild-standalone.php',
		'find'    => "\t\t\treturn null === \$gallery ? \$content : \$content . \$this->renderer->gallery( \$gallery, 1 );",
		'replace' => "\t\t\treturn \$content;",
		'expect'  => 'a gallery is appended to its own page',
		'why'     => 'the control: without it, "nothing was appended" would prove nothing',
	),
	array(
		'id'      => 'P4',
		'file'    => 'includes/class-lichtbild-ajax.php',
		'find'    => "\t\twp_send_json_success( array( 'items' => \$items ) );",
		'replace' => "\t\twp_send_json_success( array( 'items' => array() ) );",
		'expect'  => 'the ajax endpoints serve a public gallery',
		'why'     => 'the control for the endpoint guard above',
	),
	array(
		'id'      => 'P5',
		'file'    => $renderer,
		'find'    => "\t\t\tif ( ! \$repository->is_viewable( \$gallery_id ) ) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'replace' => "\t\t\tif ( false ) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'expect'  => 'an album omits a password-protected gallery',
		'why'     => 'the album cover grid is the third place that can publish a gallery',
	),
	array(
		'id'      => 'V1',
		'file'    => $repository,
		'find'    => "\t\treturn ! post_password_required( \$post_id );",
		'replace' => "\t\treturn true;",
		'expect'  => 'an album omits a password-protected gallery',
		'why'     => 'a protected gallery is publish status, so the status leg cannot catch it',
	),
	array(
		'id'      => 'V2',
		'file'    => $repository,
		'find'    => "\t\tif ( 'publish' !== \$status && ! current_user_can( 'read_post', \$post_id ) ) {\n\t\t\treturn false;\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn false;\n\t\t}",
		'expect'  => 'a gallery that is not public is not appended to its page',
		'why'     => 'a draft gallery carries no password, so the password leg cannot catch it',
	),
	array(
		'id'      => 'P8',
		'file'    => $renderer,
		'find'    => "\t\tforeach ( \$album->items() as \$item ) {",
		'replace' => "\t\tforeach ( array() as \$item ) {",
		'expect'  => 'an album shows a gallery nobody protected',
		'why'     => 'the control: "the protected one is absent" is also true of an empty album',
	),
	array(
		'id'      => 'E17',
		'file'    => $editor,
		'find'    => "\t\tif ( ! current_user_can( 'edit_post', \$attachment_id ) ) {\n\t\t\treturn;\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn;\n\t\t}",
		'expect'  => 'tags are not written to an image the user cannot edit',
		'why'     => 'tags are shared, so gallery rights would confer media-library rights',
	),
	array(
		'id'      => 'A2',
		'file'    => $assets,
		'find'    => "\t\tif ( \$this->settings->claims_envira_shortcodes() ) {\n\t\t\t\$shortcodes[] = 'envira-gallery';",
		'replace' => "\t\tif ( true ) {\n\t\t\t\$shortcodes[] = 'envira-gallery';",
		'expect'  => 'the early scan claims envira shortcodes only while taking over',
		'why'     => 'assets on a page Envira renders make "change nothing" false on a fresh install',
	),
	array(
		'id'      => 'A3',
		'file'    => $assets,
		'find'    => "\t\t\$shortcodes = array( 'lichtbild-gallery', 'lichtbild-album' );",
		'replace' => "\t\t\$shortcodes = array();",
		'expect'  => 'the early scan still claims its own shortcodes',
		'why'     => 'the control: a scan matching nothing also stops enqueueing where it should not',
	),
	array(
		'id'      => 'A1',
		'file'    => 'includes/class-lichtbild-ajax.php',
		'find'    => "\t\t\$gallery->prime( \$gallery->items() );",
		'replace' => "\t\t// primed nothing.",
		'expect'  => 'the lightbox endpoint primes its attachments in one call',
		'why'     => 'priming changes no output, so only its absence can be asserted directly',
	),
	// The nonce, whose two endpoints want opposite answers. A front-end page can be served from
	// a cache long after its nonce expired, so refusing there breaks the gallery for a reason
	// its owner cannot see; an admin screen is never cached, so refusing there is free. The two
	// mutations below are each other's control: each turns the *other* endpoint's rule into this
	// one's and checks that the difference is asserted rather than incidental.
	array(
		'id'      => 'NC1',
		'file'    => 'includes/class-lichtbild-ajax.php',
		'find'    => "\t\tcheck_ajax_referer( 'lichtbild', 'nonce', false );",
		'replace' => "\t\tcheck_ajax_referer( 'lichtbild', 'nonce' );",
		'expect'  => 'a stale nonce does not stop a cached page loading its gallery',
		'why'     => 'dropping the third argument is the whole defect, and it looks like tidying',
	),
	array(
		'id'      => 'NC2',
		'file'    => $album_editor,
		'find'    => "\t\tcheck_ajax_referer( self::COVERS_NONCE_ACTION, 'nonce' );",
		'replace' => "\t\tcheck_ajax_referer( self::COVERS_NONCE_ACTION, 'nonce', false );",
		'expect'  => 'the cover endpoint refuses a missing or wrong nonce',
		'why'     => 'the admin endpoint must keep refusing; the front-end rule does not travel',
	),
	array(
		'id'      => 'NC3',
		'file'    => 'includes/class-lichtbild-ajax.php',
		'find'    => "\t\tif ( ! \$this->repository->is_viewable( \$gallery->id() ) ) {",
		'replace' => "\t\tif ( false ) {",
		'expect'  => 'a stale nonce does not lift the authorization it never carried',
		'why'     => 'not refusing on the nonce is only defensible while this is what refuses',
	),
	// Albums, which gained a converted record of their own in 26.8.3. The two halves need
	// separate mutations for the same reason the visibility predicate did: a reader that stopped
	// consulting the v2 record would survive every mutation of the converter, because the Envira
	// record it falls back to is deliberately left in place by the migration.
	array(
		'id'      => 'AL1',
		'file'    => $repository,
		'find'    => "\t\t\t\treturn new Lichtbild_Album( \$post_id, \$own['settings'], self::clean_album_items( \$items ) );",
		'replace' => "\t\t\t\treturn new Lichtbild_Album( \$post_id, array(), array() );",
		'expect'  => 'migrated album renders identically',
		'why'     => 'the post-migration reader must prefer the album record it wrote',
	),
	array(
		'id'      => 'AL2',
		'file'    => $repository,
		'find'    => "\t\tif ( \$this->owns_data ) {\n\t\t\t\$own = get_post_meta( \$post_id, self::ALBUM_META_V2, true );",
		'replace' => "\t\tif ( true ) {\n\t\t\t\$own = get_post_meta( \$post_id, self::ALBUM_META_V2, true );",
		'expect'  => 'a rolled back site ignores the converted album',
		'why'     => 'a rollback restores Envira post types and must restore its authority too',
	),
	array(
		'id'      => 'AL3',
		'file'    => $album,
		'find'    => "\t\tif ( ! empty( \$entry['cover_image_id'] ) && is_numeric( \$entry['cover_image_id'] ) ) {\n\t\t\t\$cover = (int) \$entry['cover_image_id'];\n\t\t}",
		'replace' => "\t\tif ( ! empty( \$entry['id'] ) && is_numeric( \$entry['id'] ) ) {\n\t\t\t\$cover = (int) \$entry['id'];\n\t\t}",
		'expect'  => 'an album cover is the cover, never the gallery id',
		'why'     => 'the old fallback chain ended at the gallery id, which is never an attachment',
	),
	array(
		'id'      => 'AL4',
		'file'    => $album,
		'find'    => "\t\t\$settings['show_titles'] = ( '' !== \$position && '0' !== \$position );",
		'replace' => "\t\t\$settings['show_titles'] = true;",
		'expect'  => 'album settings convert from envira',
		'why'     => 'display_titles is a position string, and "0" means off rather than a value',
	),
	array(
		'id'      => 'AL5',
		'file'    => $album,
		'find'    => "\t\t\t'show_titles' => ! empty( \$input['show_titles'] ),",
		'replace' => "\t\t\t'show_titles' => isset( \$input['show_titles'] ) ? (bool) \$input['show_titles'] : true,",
		'expect'  => 'an unchecked album box switches its setting off',
		'why'     => 'an unchecked box submits nothing, so a true default makes it unswitchable',
	),
	array(
		'id'      => 'AL6',
		'file'    => $renderer,
		'find'    => "\t\t\tif ( \$album->has_titles() ) {",
		'replace' => "\t\t\tif ( true ) {",
		'expect'  => 'album settings switch the caption parts off',
		'why'     => 'a setting the renderer stores but never consults is what these replaced',
	),
	array(
		'id'      => 'AL7',
		'file'    => $migration,
		'find'    => "\t\t\tarray( self::class, 'build_album_record' ),",
		'replace' => "\t\t\tstatic function () { return null; },",
		'expect'  => 'migration converts every real album',
		'why'     => 'renaming album rows without converting them is the bug this release fixes',
	),
	array(
		'id'      => 'AL8',
		'file'    => $album,
		'find'    => "\t\t\$settings = self::defaults();\n\n\t\t\$columns             = isset( \$envira['columns'] )",
		'replace' => "\t\t\$settings = self::defaults();\n\n\t\t\$settings['title'] = isset( \$envira['title'] ) ? trim( (string) \$envira['title'] ) : '';\n\n\t\t\$columns             = isset( \$envira['columns'] )",
		'expect'  => 'album settings convert from envira',
		'why'     => 'envira freezes the album title into its config, and a stored copy is an override',
	),
	array(
		'id'      => 'AL9',
		'file'    => 'includes/class-lichtbild-album.php',
		'find'    => "\t\treturn (string) get_the_title( \$this->id );",
		'replace' => "\t\treturn '';",
		'expect'  => 'an album titles itself from its post',
		'why'     => 'the accessor nothing renders is the one that can rot unnoticed',
	),
	array(
		'id'      => 'H1',
		'file'    => $repository,
		'find'    => "\t\tif ( 'defaults' === ( isset( \$config['type'] ) ? (string) \$config['type'] : '' ) ) {\n\t\t\treturn null;\n\t\t}\n\n\t\t\$items = array();",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn null;\n\t\t}\n\n\t\t\$items = array();",
		'expect'  => 'the album defaults row is not an album',
		'why'     => 'envira keeps its album defaults in an album row, exactly as for galleries',
	),
	// The album editor, 26.8.4. Two of these guard things no front-end check can see: a member
	// that is not a gallery, and a cover that belongs to a different one -- the renderer falls
	// back to the gallery's first image in both cases, so the page looks right either way.
	array(
		'id'      => 'AE1',
		'file'    => $album_editor,
		'find'    => "\t\tif ( ! \$this->authorised_save( \$post_id ) ) {\n\t\t\treturn;\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn;\n\t\t}",
		'expect'  => 'an album save with no nonce changes nothing',
		'why'     => 'the album\'s half of the call-site pair: it has to consult the shared chain too',
	),
	array(
		'id'      => 'AE13',
		'file'    => $album_editor,
		'find'    => "\t\tforeach ( \$this->ordered_rows( \$submitted, \$order ) as \$row ) {",
		'replace' => "\t\tforeach ( array_values( \$submitted ) as \$row ) {",
		'expect'  => 'stored album order follows the submitted order',
		'why'     => 'the album\'s half of the collect pair; AE5 proves the order argument, not the route',
	),
	array(
		'id'      => 'AE3',
		'file'    => $album_editor,
		'find'    => "\t\t\tif ( ! \$gallery instanceof Lichtbild_Gallery ) {\n\t\t\t\tcontinue;\n\t\t\t}\n\n\t\t\t\$items[] = array(\n\t\t\t\t'id'       => \$gallery_id,\n\t\t\t\t'cover_id' => \$this->clean_cover( \$gallery, isset( \$row['cover_id'] ) ? \$row['cover_id'] : 0 ),",
		'replace' => "\t\t\t\$items[] = array(\n\t\t\t\t'id'       => \$gallery_id,\n\t\t\t\t'cover_id' => 0,",
		'expect'  => 'a member that is not a gallery is dropped',
		'why'     => 'an album is an ordered set of galleries, so a row naming anything else is not a member',
	),
	array(
		'id'      => 'AE4',
		'file'    => $album_editor,
		'find'    => "\t\tforeach ( \$gallery->items() as \$item ) {\n\t\t\tif ( \$item->id() === \$cover_id ) {\n\t\t\t\treturn \$cover_id;\n\t\t\t}\n\t\t}\n\n\t\treturn 0;",
		'replace' => "\t\treturn \$cover_id;",
		'expect'  => 'a cover outside its gallery is refused',
		'why'     => 'the renderer falls back to the first image, so a wrong cover is invisible',
	),
	array(
		'id'      => 'AE5',
		'file'    => $album_editor,
		'find'    => "\t\t\t\t'items'    => \$this->collect_items( \$submitted, \$order, \$post_id ),",
		'replace' => "\t\t\t\t'items'    => \$this->collect_items( \$submitted, implode( ',', array_reverse( array_keys( \$submitted ) ) ), \$post_id ),",
		'expect'  => 'stored album order follows the submitted order',
		'why'     => 'order is submitted explicitly rather than inferred from the field order',
	),
	array(
		'id'      => 'AE6',
		'file'    => $album_editor,
		'find'    => "\t\t\t&& current_user_can( 'edit_post', \$album_id )\n",
		'replace' => "\t\t\t&& current_user_can( 'read' )\n",
		'expect'  => 'the cover endpoint refuses someone who cannot edit the album',
		'why'     => 'the endpoint reports a gallery\'s contents, so being logged in is not enough',
	),
	array(
		// Re-anchored in 26.8.14 along with AE11: the query moved into
		// `Lichtbild_Repository::rows()`, where the type arrives as an argument rather than being
		// read from the settings. Same defect stated at the new site — a picker naming the type
		// the rows *left* rather than the one they are under now, which after a migration finds
		// nothing and reads as "this site has no galleries".
		'id'      => 'AE7',
		'file'    => $repository,
		'find'    => "\t\t\t\t'post_type'   => \$post_type,",
		'replace' => "\t\t\t\t'post_type'   => self::GALLERY_POST_TYPE,",
		'expect'  => 'the album screen offers every real gallery',
		'why'     => 'the picker has to name the type the rows are under now, not the one they left',
	),
	// 26.8.5, all seven from an independent review. Five of these break something no rendered
	// page can show: a flag that was not written, a capability asked about the wrong post, a
	// backslash eaten by the metadata layer.
	array(
		'id'      => 'SC1',
		'file'    => $migration,
		'find'    => "\t\tif ( (int) get_option( Lichtbild_Settings::OPTION_SCHEMA, 0 ) === (int) \$value ) {\n\t\t\treturn true;\n\t\t}",
		'replace' => "\t\tif ( true ) {\n\t\t\treturn true;\n\t\t}",
		'expect'  => 'a migration that cannot write the schema reports it',
		'why'     => 'the flag decides which post types the next request queries; a silent failure hides every gallery',
	),
	array(
		'id'      => 'AE8',
		'file'    => $album_editor,
		'find'    => "\t\t\t&& \$gallery_id > 0\n\t\t\t&& current_user_can( 'edit_post', \$gallery_id );",
		'replace' => "\t\t\t&& \$gallery_id > 0;",
		'expect'  => 'the cover endpoint refuses someone who cannot edit the gallery',
		'why'     => 'edit_post is per-post, so permission on the album says nothing about the gallery',
	),
	array(
		'id'      => 'AE9',
		'file'    => $album_editor,
		'find'    => "\t\t\t&& get_post_type( \$album_id ) === Lichtbild_Post_Types::album_type( \$this->settings )\n",
		'replace' => "",
		'expect'  => 'the cover endpoint refuses an album id that is not an album',
		'why'     => 'without it, edit_post on any post the user authored is the key',
	),
	// Reintroduces the first-match-wins lookup the renderer was moved off in 26.8.5.
	//
	// It used to do that by calling `Lichtbild_Album::cover_id( $id )` and `::caption( $id )`,
	// which is why those two methods survived having no production caller: a mutation needed
	// them, so deleting them "broke a test". They are gone now and the lookup is inlined here,
	// where the bug being reintroduced actually belongs. A mutation is meant to reproduce a
	// defect, not to keep an API alive that only it and one assertion still use.
	array(
		'id'      => 'AE10',
		'file'    => $renderer,
		'find'    => "\t\tforeach ( \$album->items() as \$item ) {\n\t\t\t\$gallery_id = (int) \$item['id'];\n\t\t\t\$gallery    = \$repository->gallery( \$gallery_id );",
		'replace' => "\t\tforeach ( \$album->gallery_ids() as \$gallery_id ) {\n\t\t\t\$item = array( 'cover_id' => 0, 'caption' => '' );\n\n\t\t\tforeach ( \$album->items() as \$candidate ) {\n\t\t\t\tif ( (int) \$candidate['id'] === (int) \$gallery_id ) {\n\t\t\t\t\t\$item = \$candidate;\n\n\t\t\t\t\tbreak;\n\t\t\t\t}\n\t\t\t}\n\n\t\t\t\$gallery = \$repository->gallery( \$gallery_id );",
		'expect'  => 'a repeated member keeps its own cover and caption',
		'why'     => 'looking a member up by id returns the first match for every position',
	),
	array(
		// Re-anchored in 26.8.14: the loop moved to `Lichtbild_Repository::gallery_choices()` when
		// the block editor's picker needed the same answer. It is the same defect and the same
		// prediction; what changed is that one edit now goes red in two pickers rather than one,
		// which is the coverage the duplicated version claimed and did not have.
		'id'      => 'AE11',
		'file'    => $repository,
		'find'    => "\t\t\tif ( null === \$this->gallery( \$post_id ) ) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'replace' => "\t\t\tif ( false ) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'expect'  => 'the album screen offers every real gallery',
		'why'     => 'the migration renames envira defaults row too, so a query finds it',
	),
	array(
		'id'      => 'AE12',
		'file'    => $album_editor,
		'find'    => "\t\t\$album = \$this->repository->album( (int) \$post_id );\n\n\t\techo null === \$album ? 0 : (int) \$album->count();",
		'replace' => "\t\t\$record = get_post_meta( (int) \$post_id, Lichtbild_Repository::ALBUM_META_V2, true );\n\t\techo (int) ( is_array( \$record ) && isset( \$record['items'] ) ? count( \$record['items'] ) : 0 );",
		'expect'  => 'the album list column counts its galleries',
		'why'     => 'which record is authoritative is the thing that changes at migration',
	),
	array(
		'id'      => 'SL1',
		'file'    => $album_editor,
		'find'    => "\t\t\twp_slash(\n\t\t\t\tarray(\n\t\t\t\t\t'version'  => Lichtbild_Album_Config::VERSION,",
		'replace' => "\t\t\t( function ( \$v ) { return \$v; } )(\n\t\t\t\tarray(\n\t\t\t\t\t'version'  => Lichtbild_Album_Config::VERSION,",
		'expect'  => 'a backslash in a caption survives the save',
		'why'     => 'the metadata layer unslashes what it stores, so an unslashed value loses a level',
	),
	array(
		'id'      => 'SL2',
		'file'    => $editor,
		'find'    => "\t\t\twp_slash(\n\t\t\t\tarray(\n\t\t\t\t\t'version'  => Lichtbild_Config::VERSION,",
		'replace' => "\t\t\t( function ( \$v ) { return \$v; } )(\n\t\t\t\tarray(\n\t\t\t\t\t'version'  => Lichtbild_Config::VERSION,",
		'expect'  => 'a save round-trips the gallery byte for byte',
		'why'     => 'the same defect on the gallery side, and it shipped',
	),
	array(
		'id'      => 'SL3',
		'file'    => $migration,
		'find'    => "\t\t\tupdate_post_meta( \$id, \$target_key, wp_slash( \$record ) );",
		'replace' => "\t\t\tupdate_post_meta( \$id, \$target_key, \$record );",
		'expect'  => 'a backslash survives the migration',
		'why'     => 'one backslash anywhere would otherwise stop the whole migration',
	),
	// 26.8.6: the fourth publishing path, and the settings a rename orphans.
	array(
		'id'      => 'S1',
		'file'    => $shortcode,
		'find'    => "\t\tif ( null === \$gallery || ! \$this->repository->is_viewable( \$gallery->id() ) ) {",
		'replace' => "\t\tif ( null === \$gallery ) {",
		'expect'  => 'the shortcode refuses a password-protected gallery',
		'why'     => 'it was the last path that could publish a gallery without asking',
	),
	array(
		'id'      => 'S2',
		'file'    => $shortcode,
		'find'    => "\t\tif ( null === \$album || ! \$this->repository->is_viewable( \$album->id() ) ) {",
		'replace' => "\t\tif ( null === \$album ) {",
		'expect'  => 'the album shortcode refuses a protected album',
		'why'     => 'the renderer checks each member; nothing else checked the album itself',
	),
	array(
		'id'      => 'SEO1',
		'file'    => $migration,
		// Re-anchored in 26.8.25: the call now hands `carry_seo_settings()` the warnings array.
		'find'    => "\t\t\t\$result['seo_keys'] = \$this->carry_seo_settings( \$result['warnings'] );",
		'replace' => "\t\t\t\$result['seo_keys'] = 0;",
		'expect'  => 'the migration carries seo settings onto the new names',
		'why'     => 'a post type is a public identifier and other plugins have written it down',
	),
	array(
		'id'      => 'SEO2',
		'file'    => $migration,
		'find'    => "\t\t\t\tlist( \$head, \$tax ) = explode( '-tax-', \$key, 2 );\n\n\t\t\t\tif ( isset( \$taxes[ \$tax ] ) ) {",
		'replace' => "\t\t\t\tlist( \$head, \$tax ) = explode( '-tax-', \$key, 2 );\n\n\t\t\t\tif ( 0 === strpos( \$tax, 'envira' ) ) {\n\t\t\t\t\t\$taxes[ \$tax ] = 'lichtbild_' . \$tax;\n\t\t\t\t}\n\n\t\t\t\tif ( isset( \$taxes[ \$tax ] ) ) {",
		'expect'  => 'it never invents settings for types it does not register',
		'why'     => 'a loose match writes settings describing archives that no longer exist',
	),
	array(
		'id'      => 'SEO3',
		'file'    => $migration,
		'find'    => "\t\t\tif ( null !== \$new && ! array_key_exists( \$new, \$titles ) ) {",
		'replace' => "\t\t\tif ( null !== \$new ) {\n\t\t\t\tunset( \$titles[ \$key ] );\n\t\t\t}\n\n\t\t\tif ( null !== \$new ) {",
		'expect'  => 'a rollback needs no inverse of that',
		'why'     => 'keys are added, never moved -- removing envira\'s strands a rolled back site',
	),
	// A cast is what these two replace, and the cast is what they restore: `if ( false )` would
	// hand an array to `explode()`, which is a TypeError on PHP 8 and a fatal rather than a
	// failing check -- and a fatal reports as BROKEN, which says nothing about the tests.
	array(
		'id'      => 'N1',
		'file'    => $editor,
		'find'    => "\t\tif ( ! is_string( \$value ) ) {\n\t\t\treturn;\n\t\t}",
		'replace' => "\t\t\$value = (string) \$value;",
		'expect'  => 'a non-string field is ignored rather than cast',
		'why'     => 'an array tag field casts to a term named "Array", written to every gallery holding the image',
	),
	array(
		'id'      => 'N2',
		'file'    => $item,
		'find'    => "\t\treturn isset( \$input[ \$key ] ) && is_string( \$input[ \$key ] ) ? \$input[ \$key ] : '';",
		'replace' => "\t\treturn isset( \$input[ \$key ] ) ? (string) \$input[ \$key ] : '';",
		'expect'  => 'a non-string field is ignored rather than cast',
		'why'     => 'the cast fires before any array-aware sanitiser downstream can help',
	),
	array(
		'id'      => 'R1',
		'file'    => $repository,
		'find'    => "\t\t\t\treturn \$this->build_from_own( \$post_id, \$own );",
		'replace' => "\t\t\t\treturn new Lichtbild_Gallery( \$post_id, array(), array() );",
		'expect'  => 'migrated gallery renders identically',
		'why'     => 'the post-migration reader has to prefer the record the migration wrote',
	),
	// A request parameter is attacker-shaped, a stored value can be any shape at all, and one
	// piece of data emitted twice is one place too many.
	array(
		'id'      => 'W1',
		'file'    => $ajax,
		'find'    => "\t\treturn is_string( \$raw ) ? sanitize_title( \$raw ) : '';",
		'replace' => "\t\treturn sanitize_title( \$raw );",
		'expect'  => 'an array-shaped tag reads as no tag',
		'why'     => 'tag[]=x is an uncaught TypeError on a public endpoint, so an http 500 at will',
	),
	array(
		'id'      => 'AF1',
		'file'    => $album,
		'find'    => "\t\tif ( is_string( \$value ) ) {\n\t\t\treturn in_array( strtolower( trim( \$value ) ), array( '1', 'true', 'yes', 'on' ), true );\n\t\t}\n\n\t\treturn \$default;",
		'replace' => "\t\treturn in_array( strtolower( trim( (string) \$value ) ), array( '1', 'true', 'yes', 'on' ), true );",
		'expect'  => 'an unreadable album flag takes its default',
		'why'     => 'casting an array is a php warning and then a flat false, whatever was asked for',
	),
	array(
		'id'      => 'RT1',
		'file'    => $renderer,
		'find'    => "\t\t\t\$item_class = 'lichtbild-item';",
		'replace' => "\t\t\tif ( ! empty( \$tags ) ) {\n\t\t\t\t\$attributes['data-lichtbild-tags'] = implode( ' ', wp_list_pluck( \$tags, 'slug' ) );\n\t\t\t}\n\n\t\t\t\$item_class = 'lichtbild-item';",
		'expect'  => 'the tag list is emitted once per item',
		'why'     => 'the anchor copy is read by nothing and can disagree with the one that is',
	),
	// A failed rename, and what is left of it once the tab is closed. The pair points in opposite
	// directions on purpose: one deletes the only line this plugin logs, the other adds a second
	// one. "It logged something" and "it logs nothing it should not" are different properties,
	// and a single mutation cannot ask about both.
	array(
		'id'      => 'LOG1',
		'file'    => $migration,
		// The message is still composed and then dropped, rather than the whole block deleted, so
		// what this asks about is the logging alone and not the sprintf beside it.
		'find'    => "\t\t\terror_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log\n\t\t\t\tsprintf(\n\t\t\t\t\t'Lichtbild: could not rename %1\$s \"%2\$s\" to \"%3\$s\" in %4\$s: %5\$s',\n\t\t\t\t\t\$column,\n\t\t\t\t\t\$from,\n\t\t\t\t\t\$to,\n\t\t\t\t\t\$table,\n\t\t\t\t\t\$wpdb->last_error\n\t\t\t\t)\n\t\t\t);",
		'replace' => "\t\t\tsprintf(\n\t\t\t\t'Lichtbild: could not rename %1\$s \"%2\$s\" to \"%3\$s\" in %4\$s: %5\$s',\n\t\t\t\t\$column,\n\t\t\t\t\$from,\n\t\t\t\t\$to,\n\t\t\t\t\$table,\n\t\t\t\t\$wpdb->last_error\n\t\t\t);",
		'expect'  => 'a failed rename says why in the log',
		'why'     => 'the admin notice lives five minutes; the log is what is still there tomorrow',
	),
	array(
		'id'      => 'LOG2',
		'file'    => $migration,
		'find'    => "\t\t\$changed = \$wpdb->update( \$table, array( \$column => \$to ), array( \$column => \$from ), array( '%s' ), array( '%s' ) );",
		'replace' => "\t\t\$changed = \$wpdb->update( \$table, array( \$column => \$to ), array( \$column => \$from ), array( '%s' ), array( '%s' ) );\n\n\t\terror_log( 'Lichtbild: renamed ' . \$from ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log",
		'expect'  => 'an ordinary rename logs nothing',
		'why'     => 'a line per rename is the log nobody reads, and it buries the one that matters',
	),
	// The screen is the only place a migration's outcome reaches the person who ran it.
	array(
		'id'      => 'MS1',
		'file'    => $screen,
		'find'    => "\t\t\tif ( 1 === count( \$errors ) ) {\n\t\t\t\techo '<p>' . esc_html( reset( \$errors ) ) . '</p>';\n\t\t\t} else {",
		'replace' => "\t\t\tif ( true ) {\n\t\t\t\techo '<p>' . esc_html( implode( ' ', \$errors ) ) . '</p>';\n\t\t\t} elseif ( false ) {",
		'expect'  => 'a half-failed migration lists its errors',
		'why'     => 'run-together sentences are what a half-failed migration hands someone to read',
	),
	array(
		'id'      => 'MS2',
		'file'    => $screen,
		'find'    => "\t\t\tif ( 1 === count( \$errors ) ) {",
		'replace' => "\t\t\tif ( 0 === count( \$errors ) ) {",
		'expect'  => 'a single error reads as a sentence',
		'why'     => 'one bullet for one sentence is a list pretending there is more to read',
	),
	array(
		'id'      => 'MS3',
		'file'    => $screen,
		'find'    => "\t\t\t\tisset( \$result['albums_converted'] ) ? (int) \$result['albums_converted'] : 0,",
		'replace' => "\t\t\t\t0,",
		'expect'  => 'the migration notice reports every count',
		'why'     => 'albums went a release unconverted, and the notice is where that would show',
	),
	array(
		'id'      => 'MS4',
		'file'    => $screen,
		'find'    => "\t\t\t\tisset( \$result['seo_keys'] ) ? (int) \$result['seo_keys'] : 0",
		'replace' => "\t\t\t\t0",
		'expect'  => 'the migration notice reports every count',
		'why'     => 'orphaned seo keys took the canonical links off 58 indexed archives, silently',
	),
	// The v1 areas, which the entries above deliberately do not touch: the Envira conversion, the
	// pagination and filter arithmetic, the markup a visitor actually receives, the registrations
	// the URLs rest on, and the migration's own plan and recovery paths. They were proved once by
	// a hand-run pass whose definitions are gone, which is the same as not having been proved.
	//
	// Chosen by measurement rather than by memory: every check the suite reports was compared
	// against every check the entries above turn red, and these close the difference.
	array(
		'id'      => 'B1',
		'file'    => $config,
		'find'    => "\t\t\treturn in_array( strtolower( trim( \$value ) ), array( '1', 'true', 'yes', 'on' ), true );",
		'replace' => "\t\t\treturn in_array( strtolower( trim( \$value ) ), array( '1', 'yes', 'on' ), true );",
		'expect'  => 'truthy config spellings read as true',
		'why'     => 'keyboard alone is split between \'1\' and \'True\' on this one site',
	),
	array(
		'id'      => 'B2',
		'file'    => $config,
		'find'    => "\t\t\treturn (int) \$value > 0;",
		'replace' => "\t\t\treturn true;",
		'expect'  => 'falsy config spellings read as false',
		'why'     => 'a switched-off flag stored as 0 must not read as on',
	),
	array(
		'id'      => 'B3',
		'file'    => $config,
		'find'    => "\t\tif ( array_key_exists( \$newer, \$config ) ) {\n\t\t\treturn self::flag( \$config, \$newer, \$default );\n\t\t}",
		'replace' => "\t\tif ( self::flag( \$config, \$newer, \$default ) ) {\n\t\t\treturn true;\n\t\t}",
		'expect'  => 'a newer toggle overrides an older one',
		'why'     => 'read as new || old, a newer toggle switched off can never override an older one left on',
	),
	array(
		'id'      => 'B4',
		'file'    => $config,
		'find'    => "\t\treturn self::flag( \$config, \$older, \$default );",
		'replace' => "\t\treturn \$default;",
		'expect'  => 'an older toggle still applies when alone',
		'why'     => 'a gallery predating the lightbox toggles carries only the older generation',
	),
	array(
		'id'      => 'B5',
		'file'    => $config,
		'find'    => "\t\t\$settings['columns']  = \$columns > 0 ? \$columns : 3;",
		'replace' => "\t\t\$settings['columns']  = \$columns > 0 ? \$columns : 0;",
		'expect'  => 'an empty envira config yields the defaults',
		'why'     => 'an absent key takes the documented default, not whatever the branch returned',
	),
	array(
		'id'      => 'B6',
		'file'    => $config,
		'find'    => "\t\t\$filled = array_merge( self::defaults(), \$settings );",
		'replace' => "\t\t\$filled = array_merge( \$settings, self::defaults() );",
		'expect'  => 'sparse settings are filled',
		'why'     => 'filling a stored record must add the missing keys, not overwrite the stored ones',
	),
	array(
		'id'      => 'CSS1',
		'file'    => $renderer,
		'find'    => "\t\t// any case -- `Lichtbild_Assets` enqueues every stylesheet this plugin has.\n\t\t\$out = sprintf(",
		'replace' => "\t\t\$out  = '<style>' . \$gallery->title() . '</style>';\n\t\t\$out .= sprintf(",
		'expect'  => 'no gallery emits a style element',
		'why'     => 'the directory does not permit a plugin to print CSS it stored from its own UI, and the check has to see an inline style element however it got there -- not only through the setting that used to feed one',
	),
	array(
		'id'      => 'CSS2',
		'file'    => $config,
		'find'    => "\t\t\t'tags_all_label'      => '',\n\t\t);",
		'replace' => "\t\t\t'tags_all_label'      => '',\n\t\t\t'custom_css'          => '',\n\t\t);",
		'expect'  => 'the settings form has a field for every setting',
		'why'     => 'putting the setting back in the schema alone is the half-removal that leaves a stored value nothing reads; the drift check is what refuses it',
	),
	array(
		'id'      => 'CSS3',
		'file'    => $config,
		'find'    => "\t\t// Envira's `custom_css` is deliberately NOT converted.",
		'replace' => "\t\t\$settings['custom_css'] = (string) self::get( \$envira, 'custom_css', '' );\n\t\t// Envira's `custom_css` is deliberately NOT converted.",
		'expect'  => 'conversion drops envira custom css',
		'why'     => 'Envira\'s record still holds the CSS on sixteen galleries, so the conversion is where it would come back in',
	),
	array(
		'id'      => 'CSS4',
		'file'    => $config,
		'find'    => "\t\t// A submitted `custom_css` is dropped rather than sanitised.",
		'replace' => "\t\t\$out['custom_css'] = isset( \$input['custom_css'] ) ? (string) \$input['custom_css'] : '';\n\t\t// A submitted `custom_css` is dropped rather than sanitised.",
		'expect'  => 'a submitted custom css is not stored',
		'why'     => 'the allowlisted record is what stops a hand-posted field being stored; without this check the allowlist could grow one back',
	),
	array(
		'id'      => 'CSS5',
		'file'    => $editor,
		'find'    => "\t\techo '</table>';",
		'replace' => "\t\techo '<tr><td><textarea name=\"lichtbild_settings[custom_css]\"></textarea></td></tr>';\n\t\techo '</table>';",
		'expect'  => 'the settings form offers no custom css field',
		'why'     => 'the UI is the half the directory actually objected to, and the drift check above cannot see a field for a setting that does not exist',
	),
	array(
		'id'      => 'SLUG1',
		'file'    => 'includes/class-lichtbild-settings.php',
		'find'    => "\t\t\$scheme = get_option( self::OPTION_SLUG_SCHEME, '' );\n\n\t\tif ( 'envira' === \$scheme || 'generic' === \$scheme ) {\n\t\t\treturn;\n\t\t}\n\n",
		'replace' => "",
		'expect'  => 'the scheme is recorded, not re-derived',
		'why'     => 'deriving the scheme on every request moves 57 indexed urls the day somebody deletes an old meta key -- an action that looks nothing like a permalink change',
	),
	array(
		'id'      => 'SLUG2',
		'file'    => 'includes/class-lichtbild-post-types.php',
		'find'    => "\tconst SLUGS_GENERIC = array(\n\t\t'gallery' => 'gallery',",
		'replace' => "\tconst SLUGS_GENERIC = array(\n\t\t'gallery' => 'envira',",
		'expect'  => 'a site with no envira history serves generic paths',
		'why'     => 'a fresh install publishing its galleries under a path named after another company\'s product is the likeliest thing a plugin reviewer objects to',
	),
	array(
		'id'      => 'FRESH1',
		'file'    => 'includes/class-lichtbild-settings.php',
		'find'    => "\t\tif ( ! \$history && null === \$schema ) {\n\t\t\tupdate_option( self::OPTION_SCHEMA, self::SCHEMA_MIGRATED );\n\t\t}",
		'replace' => "",
		'expect'  => 'a site that never had envira starts on lichtbild storage',
		'why'     => 'without it a brand new install registers post types literally named envira and every editor screen refuses to work, telling the owner their gallery is in a format the site has never had',
	),
	array(
		'id'      => 'FRESH2',
		'file'    => 'includes/class-lichtbild-migration.php',
		'find'    => "\t\tif ( ! \$this->settings->continues_envira() ) {",
		'replace' => "\t\tif ( false ) {",
		'expect'  => 'a site with no envira history cannot roll back',
		'why'     => 'a fresh install is now already migrated, so an unguarded rollback would move the owner galleries onto post types named after a plugin they never installed',
	),
	array(
		'id'      => 'B8',
		'file'    => $config,
		'find'    => "\t\t\$out['gutter']     = self::bounded( \$input, 'gutter', 0, 100, \$defaults['gutter'] );",
		'replace' => "\t\tunset( \$out['gutter'] );",
		'expect'  => 'every setting survives a save',
		'why'     => 'a schema key the sanitiser drops is a setting that reverts on every save',
	),
	array(
		'id'      => 'B9',
		'file'    => 'includes/class-lichtbild-gallery.php',
		'find'    => "\t\treturn max( 1, (int) ceil( count( \$this->filtered_items( \$tag ) ) / \$this->per_page() ) );",
		'replace' => "\t\treturn max( 1, (int) floor( count( \$this->filtered_items( \$tag ) ) / \$this->per_page() ) );",
		'expect'  => 'page count arithmetic',
		'why'     => 'rounding down leaves the last images on a page nothing can reach',
	),
	array(
		'id'      => 'B10',
		'file'    => 'includes/class-lichtbild-gallery.php',
		'find'    => "\t\treturn max( 1, (int) ceil( count( \$this->filtered_items( \$tag ) ) / \$this->per_page() ) );",
		'replace' => "\t\treturn max( 1, (int) ceil( count( \$this->items ) / \$this->per_page() ) );",
		'expect'  => 'filtered page count follows the filter',
		'why'     => 'filtering spans the gallery, so it changes how many pages there are',
	),
	array(
		'id'      => 'B11',
		'file'    => 'includes/class-lichtbild-gallery.php',
		'find'    => "\t\treturn array_slice( \$items, ( \$page - 1 ) * \$this->per_page(), \$this->per_page() );",
		'replace' => "\t\treturn array_slice( \$items, \$page * \$this->per_page(), \$this->per_page() );",
		'expect'  => 'page one is the first slice of the gallery',
		'why'     => 'every count in the suite is derived from this method, so only the item list can catch it',
	),
	array(
		'id'      => 'B12',
		'file'    => 'includes/class-lichtbild-gallery.php',
		'find'    => "\t\t\$items = \$this->filtered_items( \$tag );",
		'replace' => "\t\t\$items = \$this->items;",
		'expect'  => 'a filtered page holds only matching items',
		'why'     => 'a page sliced out of the whole gallery is still non-empty and still counts right',
	),
	array(
		'id'      => 'B13',
		'file'    => 'includes/class-lichtbild-gallery.php',
		'find'    => "\t\t\t\tif ( \$item_tag['slug'] === \$tag ) {",
		'replace' => "\t\t\t\tif ( \$item_tag['slug'] !== \$tag ) {",
		'expect'  => 'an unknown tag yields nothing',
		'why'     => 'a tag nobody carries must not be presentable as a page of results',
	),
	array(
		'id'      => 'B14',
		'file'    => 'includes/class-lichtbild-gallery.php',
		'find'    => "\t\treturn '' !== \$label ? \$label : __( 'All', 'lichtbild-gallery' );",
		'replace' => "\t\treturn __( 'All', 'lichtbild-gallery' );",
		'expect'  => 'tag bar uses the stored all label',
		'why'     => 'the label is translated by the site owner and stored per gallery',
	),
	array(
		'id'      => 'B15',
		'file'    => $renderer,
		'find'    => "\t\tif ( \$gallery->tags_all_enabled() ) {",
		'replace' => "\t\tif ( true ) {",
		'expect'  => 'all button can be disabled',
		'why'     => 'the everything button is a per-gallery setting',
	),
	array(
		'id'      => 'B16',
		'file'    => $renderer,
		'find'    => "\t\tforeach ( \$gallery->items() as \$item ) {",
		'replace' => "\t\tforeach ( \$gallery->page_items( 1 ) as \$item ) {",
		'expect'  => 'the tag bar lists every tag in the gallery',
		'why'     => 'a bar built from the rendered page is why filtering cannot be done in the DOM',
	),
	array(
		'id'      => 'B17',
		'file'    => $renderer,
		'find'    => "\t\tif ( empty( \$tags ) ) {\n\t\t\treturn '';\n\t\t}",
		'replace' => "\t\tif ( true ) {\n\t\t\treturn '';\n\t\t}",
		'expect'  => 'tag bar renders buttons',
		'why'     => 'the control: the checks below say nothing about a bar that is never drawn',
	),
	array(
		'id'      => 'B18',
		'file'    => $renderer,
		'find'    => "\t\t\t\tesc_attr( \$slug ),",
		'replace' => "\t\t\t\tesc_attr( \$slug . '-x' ),",
		'expect'  => 'every filter button matches an item',
		'why'     => 'a button naming a slug nothing carries filters the grid down to nothing',
	),
	array(
		'id'      => 'B19',
		'file'    => $renderer,
		'find'    => "\t\t\t\t! empty( \$tags ) ? ' data-lichtbild-tags=\"' . esc_attr( implode( ' ', wp_list_pluck( \$tags, 'slug' ) ) ) . '\"' : ''",
		'replace' => "\t\t\t\t''",
		'expect'  => 'tagged items carry their tag slugs',
		'why'     => 'the client marks the current filter from these',
	),
	array(
		'id'      => 'B20',
		'file'    => $renderer,
		'find'    => "\t\t\tif ( '' !== \$srcset ) {\n\t\t\t\t\$img['srcset'] = \$srcset;",
		'replace' => "\t\t\tif ( false ) {\n\t\t\t\t\$img['srcset'] = \$srcset;",
		'expect'  => 'image has srcset',
		'why'     => 'serving a derivative rather than the original is the performance argument',
	),
	array(
		'id'      => 'B21',
		'file'    => $renderer,
		'find'    => "\t\t\tif ( \$width > 0 && \$height > 0 ) {\n\t\t\t\t\$img['width']  = (string) \$width;",
		'replace' => "\t\t\tif ( false ) {\n\t\t\t\t\$img['width']  = (string) \$width;",
		'expect'  => 'image has intrinsic size',
		'why'     => 'without it the page shifts as the images arrive',
	),
	array(
		'id'      => 'B22',
		'file'    => $renderer,
		'find'    => "\t\t\t\t'alt'      => \$item->alt(),",
		'replace' => "\t\t\t\t'alt'      => '',",
		'expect'  => 'image has alt attribute',
		'why'     => 'an empty value is dropped from the attribute list, so the image goes unlabelled',
	),
	array(
		'id'      => 'B23',
		'file'    => $renderer,
		'find'    => "\t\t\t\t'data-pswp-width'  => \$lightbox['width'] > 0 ? (string) \$lightbox['width'] : '',",
		'replace' => "\t\t\t\t'data-pswp-width'  => (string) \$lightbox['width'],",
		'expect'  => 'orphan item carries no lightbox dimensions',
		'why'     => 'photoswipe divides by these, so a zero is worse than an absent slide',
	),
	array(
		'id'      => 'B24',
		'file'    => $renderer,
		'find'    => "\t\t\t\$aspect   = \$item->aspect();",
		'replace' => "\t\t\t\$aspect   = 0.0;",
		'expect'  => 'aspect ratio is usable',
		'why'     => 'the justified row geometry is this number and nothing else',
	),
	array(
		'id'      => 'B25',
		'file'    => $renderer,
		'find'    => "\t\t\t\t'class'            => 'lichtbild-link',",
		'replace' => "\t\t\t\t'class'            => 'lichtbild-anchor',",
		'expect'  => 'item has a link',
		'why'     => 'the class is what the lightbox binds to',
	),
	array(
		'id'      => 'B26',
		'file'    => $renderer,
		'find'    => "\t\t\t\$out .= '<img' . \$this->attributes( \$img ) . ' />';",
		'replace' => "\t\t\t\$out .= '';",
		'expect'  => 'item has an image',
		'why'     => 'the control every per-image check below it depends on',
	),
	array(
		'id'      => 'B27',
		'file'    => $renderer,
		'find'    => "\t\t\t\t'href'             => \$lightbox['url'],",
		'replace' => "\t\t\t\t'href'             => '',",
		'expect'  => 'link has href',
		'why'     => 'the anchor is the fallback for a visitor with no javascript',
	),
	array(
		'id'      => 'B28',
		'file'    => $renderer,
		'find'    => "\t\t\tlist( \$width, \$height ) = \$item->dimensions();",
		'replace' => "\t\t\tlist( \$width, \$height ) = array();",
		'expect'  => 'renders without PHP notices',
		'why'     => 'a warning per image is a log nobody can read',
	),
	array(
		'id'      => 'B29',
		'file'    => $renderer,
		'find'    => "\t\t\t\tesc_attr( \$item_class ),",
		'replace' => "\t\t\t\t\$item_class . ' <raw',",
		'expect'  => 'no raw angle brackets in attribute values',
		'why'     => 'a stray angle bracket inside an attribute parses without complaint',
	),
	array(
		'id'      => 'B30',
		'file'    => $renderer,
		'find'    => "\t\t\$dom_id = 'lichtbild-' . \$gallery->id();",
		'replace' => "\t\t\$dom_id = 'envira-gallery-' . \$gallery->id();",
		'expect'  => 'no envira identifiers in output',
		'why'     => 'a drop-in must not leak the old plugin\'s names into its own markup',
	),
	array(
		'id'      => 'B31',
		'file'    => $renderer,
		'find'    => "\t\t\tesc_attr( wp_json_encode( \$this->client_config( \$gallery, \$page ) ) )",
		'replace' => "\t\t\tesc_attr( 'not json' )",
		'expect'  => 'client config is valid JSON',
		'why'     => 'the front-end script reads every option out of this one attribute',
	),
	array(
		'id'      => 'B32',
		'file'    => $renderer,
		'find'    => "\t\t\t'pages'      => \$gallery->page_count(),",
		'replace' => "\t\t\t'pages'      => 1,",
		'expect'  => 'client config page count matches',
		'why'     => 'the client stops paging at whatever this says',
	),
	array(
		'id'      => 'B33',
		'file'    => $renderer,
		'find'    => "\t\tforeach ( \$items as \$item ) {",
		'replace' => "\t\tforeach ( array_slice( \$items, 1 ) as \$item ) {",
		'expect'  => 'item count matches page',
		'why'     => 'an image silently missing from a page is the failure nobody reports',
	),
	array(
		'id'      => 'B34',
		'file'    => $renderer,
		'find'    => "\t\tif ( 0 === \$gallery->count() ) {\n\t\t\treturn '';\n\t\t}",
		'replace' => "\t\tif ( true ) {\n\t\t\treturn '';\n\t\t}",
		'expect'  => 'non-empty gallery renders markup',
		'why'     => 'the most basic property there is, and the one nothing else asserts',
	),
	array(
		'id'      => 'B35',
		'file'    => $renderer,
		'find'    => "\t\tfor ( \$number = 1; \$number <= \$total; \$number++ ) {",
		'replace' => "\t\tfor ( \$number = 1; \$number < \$total; \$number++ ) {",
		'expect'  => 'one button per page',
		'why'     => 'the last page would have no button to reach it',
	),
	array(
		'id'      => 'B36',
		'file'    => $renderer,
		'find'    => "\t\t\t\$caption = \$item->caption();\n\n\t\t\tif ( '' !== \$caption ) {",
		'replace' => "\t\t\t\$caption = \$item->caption();\n\n\t\t\tif ( false ) {",
		'expect'  => 'synthetic caption reaches the markup',
		'why'     => 'no real item carries a caption, so only the synthetic gallery can see this',
	),
	array(
		'id'      => 'B37',
		'file'    => $item,
		'find'    => "\tpublic function url( \$size ) {\n\t\tif ( \$this->id > 0 ) {",
		'replace' => "\tpublic function url( \$size ) {\n\t\tif ( false ) {",
		'expect'  => 'grid image is not the original',
		'why'     => 'envira\'s frozen src is the full-size file, which is what made its galleries heavy',
	),
	array(
		'id'      => 'B38',
		'file'    => $item,
		'find'    => "\t\treturn isset( \$this->record['src'] ) ? \$this->safe_url( \$this->record['src'] ) : '';",
		'replace' => "\t\treturn '';",
		'expect'  => 'orphan item still renders in the grid',
		'why'     => 'an item whose attachment was deleted has nothing but the frozen url',
	),
	array(
		'id'      => 'B39',
		'file'    => $item,
		'find'    => "\t\treturn 1.5;",
		'replace' => "\t\treturn 3.0;",
		'expect'  => 'orphan item falls back to a sane aspect',
		'why'     => 'an unknown aspect must not collapse the justified row it sits in',
	),
	array(
		'id'      => 'B40',
		'file'    => $item,
		'find'    => "\t\treturn (string) esc_url_raw( \$url, array( 'http', 'https' ) );",
		'replace' => "\t\treturn \$url;",
		'expect'  => 'hostile url schemes are rejected',
		'why'     => 'the frozen url is a database string, and the JSON endpoint emits it unescaped',
	),
	array(
		'id'      => 'B41',
		'file'    => $item,
		'find'    => "\t\treturn (string) esc_url_raw( \$url, array( 'http', 'https' ) );",
		'replace' => "\t\treturn '';",
		'expect'  => 'ordinary urls are preserved',
		'why'     => 'the control: rejecting everything satisfies the check above just as well',
	),
	array(
		'id'      => 'B42',
		'file'    => $item,
		'find'    => "\t\treturn '' !== \$caption ? wp_kses_post( \$caption ) : '';",
		'replace' => "\t\treturn \$caption;",
		'expect'  => 'caption markup is filtered',
		'why'     => 'the lightbox inserts the caption with innerHTML, so escaping the attribute does nothing',
	),
	array(
		'id'      => 'B43',
		'file'    => $item,
		'find'    => "\t\treturn '' !== \$caption ? wp_kses_post( \$caption ) : '';",
		'replace' => "\t\treturn '' !== \$caption ? wp_strip_all_tags( \$caption ) : '';",
		'expect'  => 'legitimate caption markup survives',
		'why'     => 'stripping everything would remove the feature the allowlist exists to support',
	),
	array(
		'id'      => 'B44',
		'file'    => $item,
		'find'    => "\t\t\$alt = isset( \$this->record['alt'] ) ? trim( (string) \$this->record['alt'] ) : '';",
		'replace' => "\t\t\$alt = '';",
		'expect'  => 'synthetic alt reaches the markup',
		'why'     => 'no real item carries alt text, so the fallback chain hides a dropped field',
	),
	array(
		'id'      => 'B45',
		'file'    => $item,
		'find'    => "\t\treturn 'pending' !== \$status;",
		'replace' => "\t\treturn true;",
		'expect'  => 'pending items are excluded',
		'why'     => 'envira queues imported items as pending and does not display them',
	),
	array(
		'id'      => 'B46',
		'file'    => $item,
		'find'    => "\t\t\t\t\t'width'  => \$full_width > 0 ? \$full_width : (int) \$src[1],\n\t\t\t\t\t'height' => \$full_height > 0 ? \$full_height : (int) \$src[2],",
		'replace' => "\t\t\t\t\t'width'  => \$full_height > 0 ? \$full_height : (int) \$src[2],\n\t\t\t\t\t'height' => \$full_width > 0 ? \$full_width : (int) \$src[1],",
		'expect'  => 'grid and lightbox aspect agree',
		'why'     => 'the same photograph described two ways is what catches a wrong size lookup',
	),
	array(
		'id'      => 'B47',
		'file'    => $item,
		'find'    => "\t\t\t\t\t'height' => \$full_height > 0 ? \$full_height : (int) \$src[2],",
		'replace' => "\t\t\t\t\t'height' => 0,",
		'expect'  => 'lightbox dimensions are known',
		'why'     => 'photoswipe opens at the wrong zoom without the exact size of the file it shows',
	),
	array(
		'id'      => 'B48',
		'file'    => 'includes/class-lichtbild-exif.php',
		'find'    => "\t\tif ( in_array( 'make', \$enabled, true ) || in_array( 'model', \$enabled, true ) ) {",
		'replace' => "\t\tif ( true ) {",
		'expect'  => 'exif respects per-field toggles',
		'why'     => 'camera make and model are off on all 52 galleries and printing them ignores that',
	),
	array(
		'id'      => 'B49',
		'file'    => 'includes/class-lichtbild-exif.php',
		'find'    => "\t\tif ( \$attachment_id <= 0 || empty( \$enabled ) ) {\n\t\t\treturn array();\n\t\t}",
		'replace' => "\t\tif ( true ) {\n\t\t\treturn array();\n\t\t}",
		'expect'  => 'exif gallery emits some exif',
		'why'     => 'the control: "nothing switched on was printed" is also true of printing nothing',
	),
	array(
		'id'      => 'B50',
		'file'    => $config,
		'find'    => "\t\t\tif ( self::prefer( \$envira, 'exif_lightbox_' . \$field, 'exif_' . \$field, false ) ) {",
		'replace' => "\t\t\tif ( false ) {",
		'expect'  => 'exif gallery enables at least one field',
		'why'     => 'a gallery with exif on and no fields is a setting that converted to nothing',
	),
	array(
		'id'      => 'B51',
		'file'    => $renderer,
		'find'    => "\t\t\t\t\t\$attributes['data-lichtbild-exif'] = wp_json_encode( \$exif );",
		'replace' => "\t\t\t\t\t\$attributes['data-lichtbild-exif'] = 'x' . wp_json_encode( \$exif );",
		'expect'  => 'exif payload is valid JSON',
		'why'     => 'the lightbox parses this attribute and shows nothing when it cannot',
	),
	array(
		'id'      => 'B52',
		'file'    => $repository,
		'find'    => "\t\tif ( 'defaults' === ( isset( \$config['type'] ) ? \$config['type'] : '' ) ) {\n\t\t\treturn null;\n\t\t}\n\n\t\t\$records",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn null;\n\t\t}\n\n\t\t\$records",
		'expect'  => 'the defaults gallery is not rendered',
		'why'     => 'envira keeps its site-wide defaults in a gallery of its own',
	),
	array(
		'id'      => 'B53',
		'file'    => $repository,
		'find'    => "\t\tif ( \$this->owns_data ) {\n\t\t\t\$own = get_post_meta( \$post_id, self::GALLERY_META_V2, true );",
		'replace' => "\t\tif ( true ) {\n\t\t\t\$own = get_post_meta( \$post_id, self::GALLERY_META_V2, true );",
		'expect'  => 'a rolled back site ignores the converted record',
		'why'     => 'the rollback leaves the converted record behind, so preferring it splits the truth in two',
	),
	array(
		'id'      => 'B54',
		'file'    => $repository,
		'find'    => "\t\tif ( \$this->owns_data ) {\n\t\t\t\$own = get_post_meta( \$post_id, self::GALLERY_META_V2, true );",
		'replace' => "\t\tif ( false ) {\n\t\t\t\$own = get_post_meta( \$post_id, self::GALLERY_META_V2, true );",
		'expect'  => 'a migrated site does use the converted record',
		'why'     => 'the control: falling back to envira\'s record renders identically and proves nothing',
	),
	array(
		'id'      => 'B55',
		'file'    => $repository,
		'find'    => "\t\tif ( \$this->owns_data ) {\n\t\t\t\$own = get_post_meta( \$post_id, self::ALBUM_META_V2, true );",
		'replace' => "\t\tif ( false ) {\n\t\t\t\$own = get_post_meta( \$post_id, self::ALBUM_META_V2, true );",
		'expect'  => 'a migrated site does use the converted album',
		'why'     => 'albums spent a release looking migrated while the reader still read envira\'s record',
	),
	array(
		'id'      => 'B56',
		'file'    => $repository,
		'find'    => "\t\t\$data = get_post_meta( \$post_id, self::ALBUM_META, true );\n\n\t\tif ( ! is_array( \$data ) ) {\n\t\t\treturn null;\n\t\t}",
		'replace' => "\t\t\$data = get_post_meta( \$post_id, self::ALBUM_META, true );\n\n\t\tif ( true ) {\n\t\t\treturn null;\n\t\t}",
		'expect'  => 'a real album still loads',
		'why'     => 'the control for the defaults guard: "it returned null" is also true of a reader that loads nothing',
	),
	array(
		'id'      => 'B57',
		'file'    => $renderer,
		'find'    => "\t\t\tif ( \$album->has_titles() ) {",
		'replace' => "\t\t\tif ( false ) {",
		'expect'  => 'an album shows titles and counts by default',
		'why'     => 'the control for the setting above: neither arm proves the other',
	),
	array(
		'id'      => 'B58',
		'file'    => $assets,
		'find'    => "\tpublic function need_gallery() {\n\t\tif ( \$this->needed ) {\n\t\t\treturn;\n\t\t}",
		'replace' => "\tpublic function need_gallery() {\n\t\tif ( false ) {\n\t\t\treturn;\n\t\t}",
		'expect'  => 'assets enqueued exactly once',
		'why'     => 'a page of galleries must not register the stylesheet once per gallery',
	),
	array(
		'id'      => 'B59',
		'file'    => 'includes/class-lichtbild-settings.php',
		'find'    => "\t\tif ( \$this->has_migrated() ) {\n\t\t\treturn true;\n\t\t}\n\n\t\t\$mode = \$this->takeover();",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn true;\n\t\t}\n\n\t\t\$mode = \$this->takeover();",
		'expect'  => 'migrated site always takes the shortcode over',
		'why'     => 'after the rename envira cannot read the rows, so deferring renders nothing at all',
	),
	array(
		'id'      => 'B60',
		'file'    => 'includes/class-lichtbild-settings.php',
		'find'    => "\t\tif ( null !== \$own && '' !== \$own ) {\n\t\t\treturn (bool) \$own;\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn (bool) \$own;\n\t\t}",
		'expect'  => 'standalone setting follows envira before migration',
		'why'     => 'lichtbild\'s own value has to win, or uninstalling envira blanks every gallery page',
	),
	array(
		'id'      => 'B61',
		'file'    => 'includes/class-lichtbild-post-types.php',
		'find'    => "\t\tif ( ! \$migrated && \$this->settings->envira_is_active() ) {\n\t\t\treturn;\n\t\t}",
		'replace' => "\t\tif ( \$this->settings->envira_is_active() ) {\n\t\t\treturn;\n\t\t}",
		'expect'  => 'a migrated site registers its types even with envira active',
		'why'     => 'nobody else registers lichtbild_gallery, so standing aside takes every url off the site',
	),
	array(
		'id'      => 'B62',
		'file'    => 'includes/class-lichtbild-post-types.php',
		'find'    => "\t\tif ( ! \$migrated && \$this->settings->envira_is_active() ) {\n\t\t\treturn;\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn;\n\t\t}",
		'expect'  => 'an unmigrated site stands aside for envira',
		'why'     => 'registering the same name twice means the last call silently wins',
	),
	array(
		'id'      => 'B63',
		'file'    => 'includes/class-lichtbild-post-types.php',
		'find'    => "\t\tregister_post_type(\n\t\t\t\$migrated ? self::GALLERY : Lichtbild_Repository::GALLERY_POST_TYPE,\n\t\t\t\$this->gallery_args()\n\t\t);",
		'replace' => "\t\tregister_post_type(\n\t\t\tself::GALLERY,\n\t\t\t\$this->gallery_args()\n\t\t);",
		'expect'  => 'an unmigrated site registers envira\'s types',
		'why'     => 'before the migration the rows still say envira, and a type nobody registers has no url',
	),
	array(
		'id'      => 'B64',
		'file'    => 'includes/class-lichtbild-post-types.php',
		'find'    => "\t\t\t\t'slug'       => \$this->slug( 'gallery', self::GALLERY_SLUG ),",
		'replace' => "\t\t\t\t'slug'       => self::GALLERY,",
		'expect'  => 'registration keeps envira url paths',
		'why'     => 'the type name changes at migration and the indexed url must not',
	),
	array(
		'id'      => 'B65',
		'file'    => 'includes/class-lichtbild-post-types.php',
		'find'    => "\t\t\$parent = \$this->settings->has_migrated() ? self::GALLERY : Lichtbild_Repository::GALLERY_POST_TYPE;",
		'replace' => "\t\t\$parent = self::GALLERY;",
		'expect'  => 'the album menu hangs off the type that exists',
		'why'     => 'a submenu under a type that is not registered yet is dropped entirely',
	),
	array(
		'id'      => 'B66',
		'file'    => 'includes/class-lichtbild-migration-screen.php',
		'find'    => "\t\tif ( 'POST' !== strtoupper( isset( \$_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( \$_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {",
		'replace' => "\t\tif ( false ) {",
		'expect'  => 'the migrate action refuses a GET',
		'why'     => 'admin_post fires for GET too, so a prefetcher could run the migration',
	),
	array(
		'id'      => 'B67',
		'file'    => 'includes/class-lichtbild-migration-screen.php',
		'find'    => "\t\tif ( empty( \$_POST['lichtbild_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing",
		'replace' => "\t\tif ( false ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing",
		'expect'  => 'the migrate action requires the confirmation',
		'why'     => 'a required attribute is a hint to a browser, not a guard',
	),
	array(
		'id'      => 'B68',
		'file'    => 'includes/class-lichtbild-migration-screen.php',
		'find'    => "\t\tif ( ! current_user_can( 'manage_options' ) ) {\n\t\t\twp_die( esc_html__( 'You are not allowed to migrate galleries.', 'lichtbild-gallery' ), '', array( 'response' => 403 ) );\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\twp_die( esc_html__( 'You are not allowed to migrate galleries.', 'lichtbild-gallery' ), '', array( 'response' => 403 ) );\n\t\t}",
		'expect'  => 'the migrate action requires the capability',
		'why'     => 'the button the screen declines to draw is still reachable by url',
	),
	array(
		'id'      => 'B69',
		'file'    => $migration,
		'find'    => "\t\tif ( \$this->settings->envira_is_active() ) {\n\t\t\t\$result['errors'][] = __( 'Deactivate Envira Gallery before migrating.', 'lichtbild-gallery' );",
		'replace' => "\t\tif ( false ) {\n\t\t\t\$result['errors'][] = __( 'Deactivate Envira Gallery before migrating.', 'lichtbild-gallery' );",
		'expect'  => 'migration refuses while envira is active',
		'why'     => 'renaming the rows out from under envira leaves its shortcode rendering nothing',
	),
	array(
		'id'      => 'B70',
		'file'    => $migration,
		'find'    => "\t\t\t\$result['errors'][] = __( 'Deactivate Envira Gallery before migrating.', 'lichtbild-gallery' );\n\n\t\t\treturn \$result;\n\t\t}",
		'replace' => "\t\t\t\$result['errors'][] = __( 'Deactivate Envira Gallery before migrating.', 'lichtbild-gallery' );\n\t\t}",
		'expect'  => 'a refused migration changes nothing',
		'why'     => 'reporting the refusal and doing the work anyway is the worst of both',
	),
	array(
		'id'      => 'B71',
		'file'    => $migration,
		'find'    => "\t\tif ( false === \$changed ) {",
		'replace' => "\t\tif ( null === \$changed ) {",
		'expect'  => 'a failing statement is reported',
		'why'     => 'update() returns false on error and 0 when nothing matched; collapsing them hides the first',
	),
	array(
		'id'      => 'B72',
		'file'    => $migration,
		'find'    => "\t\tif ( empty( \$result['errors'] ) ) {\n\t\t\t// Carried across before the flag flips",
		'replace' => "\t\tif ( true ) {\n\t\t\t// Carried across before the flag flips",
		'expect'  => 'a failed migration does not claim to have migrated',
		'why'     => 'the flag decides which post types the next request queries',
	),
	array(
		'id'      => 'B73',
		'file'    => $migration,
		'find'    => "\t\t\tif ( wp_json_encode( \$stored ) !== wp_json_encode( \$record ) ) {",
		'replace' => "\t\t\tif ( false ) {",
		'expect'  => 'an unwritten conversion stops the migration',
		'why'     => 'update_post_meta returns false for a failed write and for an identical one alike',
	),
	array(
		'id'      => 'B74',
		'file'    => $migration,
		'find'    => "\t\tif ( ! \$this->settings->has_migrated() && 0 === \$stranded ) {",
		'replace' => "\t\tif ( false ) {",
		'expect'  => 'rollback refuses when there is nothing to roll back',
		'why'     => 'a second rollback must not move rows back again',
	),
	array(
		'id'      => 'B75',
		'file'    => $migration,
		'find'    => "\t\tif ( ! \$this->settings->has_migrated() && 0 === \$stranded ) {",
		'replace' => "\t\tif ( ! \$this->settings->has_migrated() ) {",
		'expect'  => 'an interrupted migration is still recoverable',
		'why'     => 'rows renamed with the flag never written is the one state where rollback is the only way back',
	),
	array(
		'id'      => 'B76',
		'file'    => $migration,
		'find'    => "\t\t\$gallery_type = \$migrated ? Lichtbild_Post_Types::GALLERY : Lichtbild_Repository::GALLERY_POST_TYPE;",
		'replace' => "\t\t\$gallery_type = Lichtbild_Repository::GALLERY_POST_TYPE;",
		'expect'  => 'plan follows the direction',
		'why'     => 'a plan counting only envira\'s types reports three zeroes on the screen offering the rollback',
	),
	array(
		'id'      => 'B77',
		'file'    => $migration,
		'find'    => "\t\t\$stranded = \$migrated ? 0 : \$this->lichtbild_rows();",
		'replace' => "\t\t\$stranded = 0;",
		'expect'  => 'a mixed state is reported as one',
		'why'     => 'the state a directional plan structurally cannot describe is the one it must',
	),
	array(
		'id'      => 'B78',
		'file'    => $migration,
		'find'    => "\t\t\t\$survey['convertible']++;",
		'replace' => "\t\t\t\$survey['defaults']++;",
		'expect'  => 'plan classifies every gallery',
		'why'     => 'the confirmation screen explains the two counts by how they add up',
	),
	array(
		'id'      => 'B79',
		'file'    => $migration,
		'find'    => "\t\t\t\t'galleries' => \$counts['galleries'],",
		'replace' => "\t\t\t\t'galleries' => 0,",
		'expect'  => 'plan counts the rows that exist',
		// Aimed at what `plan()` reports rather than at `rows_under()`, which `lichtbild_rows()`
		// also counts on: breaking the shared counter would take the rollback gating with it and
		// ask about two things at once.
		'why'     => 'the plan has to describe the site rather than a constant',
	),
	array(
		'id'      => 'B80',
		'file'    => $migration,
		'find'    => "\t\treturn (int) \$changed;",
		'replace' => "\t\treturn 0;",
		'expect'  => 'migration moves every row',
		'why'     => 'the screen reports these numbers as what happened',
	),
	array(
		'id'      => 'B81',
		'file'    => $migration,
		'find'    => "\t\t\$result['terms'] = \$this->move(\n\t\t\t\$wpdb->term_taxonomy,\n\t\t\t'taxonomy',\n\t\t\tLichtbild_Post_Types::TAG,\n\t\t\tLichtbild_Repository::TAG_TAXONOMY,\n\t\t\t\$result['errors']\n\t\t);",
		'replace' => "\t\t\$result['terms'] = \$this->move(\n\t\t\t\$wpdb->term_taxonomy,\n\t\t\t'taxonomy',\n\t\t\tLichtbild_Post_Types::TAG,\n\t\t\tLichtbild_Post_Types::TAG,\n\t\t\t\$result['errors']\n\t\t);",
		'expect'  => 'rollback restores every row',
		'why'     => 'reversible has to mean the taxonomy too, or every image comes back untagged',
	),
	array(
		'id'      => 'B82',
		'file'    => $migration,
		'find'    => "\t\t\$result['converted'] = \$converted;",
		'replace' => "\t\t\$result['converted'] = 0;",
		'expect'  => 'migration converts every real gallery',
		'why'     => 'a renamed row with no record of its own renders only from the record it was meant to leave behind',
	),
	array(
		'id'      => 'B83',
		'file'    => $migration,
		'find'    => "\t\treturn array(\n\t\t\t'version'  => Lichtbild_Config::VERSION,\n\t\t\t'settings' => Lichtbild_Config::from_envira( \$config, \$post_id ),\n\t\t\t'items'    => \$items,\n\t\t);",
		'replace' => "\t\t\$items[] = array(\n\t\t\t'id'      => 0,\n\t\t\t'status'  => 'active',\n\t\t\t'src'     => '',\n\t\t\t'link'    => '',\n\t\t\t'title'   => '',\n\t\t\t'caption' => '',\n\t\t\t'alt'     => '',\n\t\t);\n\n\t\treturn array(\n\t\t\t'version'  => Lichtbild_Config::VERSION,\n\t\t\t'settings' => Lichtbild_Config::from_envira( \$config, \$post_id ),\n\t\t\t'items'    => \$items,\n\t\t);",
		'expect'  => 'conversion preserves the item count',
		'why'     => 'a converted gallery has to carry the images it had and no others',
	),
	array(
		'id'      => 'B84',
		'file'    => $migration,
		'find'    => "\t\t\t'settings' => Lichtbild_Config::from_envira( \$config, \$post_id ),",
		'replace' => "\t\t\t'settings' => array(),",
		'expect'  => 'converted settings carry every key',
		'why'     => 'a record missing a key is one the reader fills from a default nobody chose',
	),
	array(
		'id'      => 'B85',
		'file'    => $migration,
		'find'    => "\t\tforeach ( Lichtbild_Repository::envira_album_entries( \$data ) as \$gallery_id => \$entry ) {\n\t\t\t\$items[] = Lichtbild_Album_Config::item_from_envira( \$gallery_id, is_array( \$entry ) ? \$entry : array() );\n\t\t}\n\n\t\treturn array(\n\t\t\t'version'  => Lichtbild_Album_Config::VERSION,",
		'replace' => "\t\tforeach ( array() as \$gallery_id => \$entry ) {\n\t\t\t\$items[] = Lichtbild_Album_Config::item_from_envira( \$gallery_id, is_array( \$entry ) ? \$entry : array() );\n\t\t}\n\n\t\treturn array(\n\t\t\t'version'  => Lichtbild_Album_Config::VERSION,",
		'expect'  => 'album conversion preserves the member count',
		'why'     => 'an album with no members renders as nothing at all',
	),
	array(
		'id'      => 'B86',
		'file'    => $migration,
		// Re-anchored in 26.8.25: the value is read into a variable first so the write can be
		// verified against it, so the mutation now targets that read rather than the call.
		'find'    => "\t\t\t\$standalone = (int) (bool) get_option( Lichtbild_Settings::OPTION_STANDALONE_ENVIRA, false );",
		'replace' => "\t\t\t\$standalone = 1;",
		'expect'  => 'migration takes ownership of the standalone setting',
		'why'     => 'the choice has to outlive the plugin that recorded it, whichever way it was set',
	),
	array(
		'id'      => 'B87',
		'file'    => $shortcode,
		'find'    => "\t\tif ( \$this->settings->claims_envira_shortcodes() ) {",
		'replace' => "\t\tif ( true ) {",
		'expect'  => 'the shortcode registry follows the takeover setting',
		'why'     => 'claiming envira\'s tags while envira is rendering them is the state the setting exists for',
	),
	array(
		'id'      => 'B98',
		'file'    => $config,
		'find'    => "\t\treturn (array) apply_filters( 'lichtbild_config_sanitize', \$out );",
		'replace' => "\t\treturn (array) apply_filters( 'lichtbild_config_sanitize', \$out, \$input );",
		'expect'  => 'the config sanitize filter is handed no raw input',
		'why'     => 'the return value is identical either way, so nothing else in the suite can see this',
	),
	array(
		'id'      => 'B99',
		'file'    => $config,
		'find'    => "\t\treturn (array) apply_filters( 'lichtbild_config_sanitize', \$out );",
		'replace' => "\t\treturn \$out;",
		'expect'  => 'the config sanitize filter is handed no raw input',
		'why'     => 'the control for B98: a check reading a recorder must fail when nothing records, or it is passing on a stale value from an earlier call rather than on this one',
	),
	array(
		'id'      => 'B97',
		'file'    => 'includes/class-lichtbild-settings.php',
		'find'    => "\t\treturn \$this->should_take_over() && 'envira' === \$this->slug_scheme();",
		'replace' => "\t\treturn \$this->should_take_over();",
		'expect'  => 'a site with no envira history claims no envira shortcodes',
		'why'     => 'the takeover mode alone is true on a fresh install, which is the case the scheme half exists for',
	),
	array(
		'id'      => 'B88',
		'file'    => $shortcode,
		'find'    => "\t\treturn \$this->renderer->gallery( \$gallery, max( 1, (int) \$atts['page'] ) );",
		'replace' => "\t\treturn '';",
		'expect'  => 'the gallery shortcode renders its gallery',
		'why'     => 'the control the two refusal checks below it rest on',
	),
	array(
		'id'      => 'B89',
		'file'    => 'includes/class-lichtbild.php',
		'find'    => "\t\t\$this->standalone->register();",
		'replace' => "\t\t// standalone left unregistered.",
		'expect'  => 'the container constructs and registers its hooks',
		'why'     => 'this is where every hook the plugin uses is actually registered',
	),
	// The three below pin checks that replaced, or were found by, the vanished-check verdict.
	// `image has src` used to sit in the per-item loop and no mutation could kill it: the
	// renderer drops an item with no src, so a figure without one cannot exist and the assertion
	// held by construction. These state the same property as a count, which is falsifiable.
	array(
		'id'      => 'B90',
		'file'    => $renderer,
		'find'    => "\t\t\tif ( '' === \$src ) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'replace' => "\t\t\tif ( false ) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'expect'  => 'every renderable item became a figure',
		'why'     => 'an item with no image to show must not become a figure with an empty src',
	),
	array(
		'id'      => 'B91',
		'file'    => $renderer,
		'find'    => "\t\tif ( 0 === \$gallery->count() ) {\n\t\t\treturn '';\n\t\t}",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn '';\n\t\t}",
		'expect'  => 'empty gallery renders nothing',
		'why'     => 'the check this pins had never run once; every gallery on the site has items',
	),
	// The two below could not be run at all before the guards went into `render-test.php`. Both
	// make a row unreachable, and the suite indexed the reader's result unconditionally a hundred
	// lines past the checks that handle a missing row correctly — so they fataled the run, which
	// produces no report, no failures, and the verdict BROKEN. "The reader can no longer find
	// this row" is the single most important thing the migration's reader does, and it was the
	// one class of mutation this harness could not express.
	array(
		'id'      => 'B93',
		'file'    => $repository,
		'find'    => "\t\t\t\treturn \$this->build_from_own( \$post_id, \$own );",
		'replace' => "\t\t\t\treturn null;",
		'expect'  => 'the reader finds the row it is asked for',
		'why'     => 'a migrated site reads its own record; returning nothing loses every gallery',
	),
	// The screen's three states. All three branches ran during the suite with nothing asserted
	// about any of them, so each of these would have survived until the checks existed.
	array(
		'id'      => 'S3',
		'file'    => $screen,
		'find'    => "\t\tif ( ! empty( \$plan['mixed'] ) ) {",
		'replace' => "\t\tif ( false ) {",
		'expect'  => 'a half-migrated site is told so and offered the way back',
		'why'     => 'an interrupted migration would be shown the migrate form over its stranded rows',
	),
	array(
		'id'      => 'S4',
		'file'    => $screen,
		'find'    => "\t\tif ( \$plan['migrated'] ) {",
		'replace' => "\t\tif ( false ) {",
		'expect'  => 'the migrated screen offers the rollback',
		'why'     => 'a migrated site offered the migration again, and no way back',
	),
	array(
		'id'      => 'S5',
		'file'    => $screen,
		'find'    => "\t\t\$this->render_pending( \$plan );",
		'replace' => "\t\t// nothing rendered.",
		'expect'  => 'the pending screen offers the migration',
		'why'     => 'the screen that offers the migration is the only way to start one',
	),
	array(
		'id'      => 'B95',
		'file'    => $repository,
		'find'    => "\t\t\t\treturn new Lichtbild_Album( \$post_id, \$own['settings'], self::clean_album_items( \$items ) );",
		'replace' => "\t\t\t\treturn null;",
		'expect'  => 'the reader finds the album it is asked for',
		'why'     => 'the album twin of B93; twelve call sites fataled on this before they were guarded',
	),
	array(
		'id'      => 'B94',
		'file'    => $migration,
		'find'    => "\t\treturn array(\n\t\t\t'version'  => Lichtbild_Config::VERSION,\n\t\t\t'settings' => Lichtbild_Config::from_envira( \$config, \$post_id ),\n\t\t\t'items'    => \$items,\n\t\t);",
		'replace' => "\t\treturn array(\n\t\t\t'version'  => Lichtbild_Config::VERSION,\n\t\t\t'settings' => Lichtbild_Config::from_envira( \$config, \$post_id ),\n\t\t\t'items'    => array(),\n\t\t);",
		'expect'  => 'the converter emits an item for a gallery that has one',
		'why'     => 'a converted record with no items is a gallery that survives the rename empty',
	),
	array(
		'id'      => 'B92',
		'file'    => $item,
		'find'    => "\t\treturn isset( \$this->record['src'] ) ? \$this->safe_url( \$this->record['src'] ) : '';",
		'replace' => "\t\treturn isset( \$this->record['src'] ) ? \$this->safe_url( \$this->record['src'] ) : 'https://example.com/invented.jpg';",
		'expect'  => 'an item with no src has no url',
		'why'     => 'the premise the figure-count pair rests on; inventing a url would hide the drop',
	),
	// Hands PhotoSwipe a 0x0 slide for an item whose attachment has been deleted, which is a
	// real bug rather than a contrived one: its zoom arithmetic divides by those.
	//
	// Worth knowing what this mutation measures on each corpus, because they differ.
	// `orphan item carries no lightbox dimensions` is built from a hand-made item and goes red
	// on both. `every unmeasurable item was kept out of the lightbox` compares two per-gallery
	// counts that are both 0 on every real gallery — nothing there is orphaned — so this
	// mutation turns it red only on the synthetic corpus.
	//
	// It does NOT follow that the check is unpinned on the real one, and the first draft of
	// this comment said it was. Measured instead: `B6` pins it there, by reversing `fill()` so
	// the default lightbox size is forced onto the one real attachment that has no such size,
	// which drops a link's dimensions without anything being orphaned. Different mutation,
	// different mechanism, same check. **A claim about what a corpus fails to cover is a
	// measurement, not an inference from the mutation in front of you** — it needs the full
	// red set of every other one, which is what `--names` is for.
	// Reinstates the pre-26.8.11 lightbox: declare the configured size's dimensions rather than
	// the full-size ones. PhotoSwipe caps `fit` at 1, so this silently limits every slide to
	// 1024px on the 1,563 attachments that have a `large` -- and nothing about the page looks
	// broken, which is why it took a person opening a photograph to notice.
	array(
		'id'      => 'L1',
		'file'    => $item,
		'find'    => "\t\t\t\t\t'width'  => \$full_width > 0 ? \$full_width : (int) \$src[1],\n\t\t\t\t\t'height' => \$full_height > 0 ? \$full_height : (int) \$src[2],",
		'replace' => "\t\t\t\t\t'width'  => (int) \$src[1],\n\t\t\t\t\t'height' => (int) \$src[2],",
		'expect'  => 'lightbox declares the full-size dimensions',
		'why'     => 'declaring the configured size caps the slide at it; photoswipe never upscales',
	),
	// And the other half: keeping the full-size box while offering no candidate that large is a
	// straight bandwidth regression, since the browser then fetches the original on every screen.
	array(
		'id'      => 'L2',
		'file'    => $item,
		'find'    => "\t\t\t\t\t'srcset' => \$this->srcset( \$size ),",
		'replace' => "\t\t\t\t\t'srcset' => '',",
		'expect'  => 'lightbox srcset reaches the declared width',
		'why'     => 'without candidates the browser has nothing to choose from and pays full size',
	),
	array(
		'id'      => 'B96',
		'file'    => $renderer,
		'find'    => "\t\t\t\t'data-pswp-width'  => \$lightbox['width'] > 0 ? (string) \$lightbox['width'] : '',",
		'replace' => "\t\t\t\t'data-pswp-width'  => (string) \$lightbox['width'],",
		'expect'  => 'orphan item carries no lightbox dimensions',
		'why'     => 'a 0x0 slide is what photoswipe divides by; an unmeasurable item must not reach it',
	),

	// --- the blocks (26.8.14) ------------------------------------------------------------
	//
	// Two of the nine block checks have no mutation here, and it is worth saying why rather than
	// contriving one. Neither visibility check can be killed by any edit to `Lichtbild_Block`,
	// because the class holds no copy of the rule to break — it hands to the shortcode, which
	// asks `is_viewable()`. `V1` and `V2` are what pin them, and measured with `--names` they
	// now go red in six and four places respectively, one more each than before the blocks
	// existed. That is the extraction paying out rather than a gap: a path that cannot forget a
	// rule is a path with nothing to mutate.
	//
	// The status leg nearly went unwritten on exactly that reasoning, which is right about the
	// code and wrong about the coverage — with only the password leg checked, `V2` went red in
	// three places and none of them was the block.
	//
	// `a block naming nothing renders nothing` is a control and is pinned by nothing, for the
	// same reason its shortcode twin is: it asserts emptiness, which is every other check's
	// failure mode, so only a block that *invented* a gallery could kill it.
	array(
		'id'      => 'BK1',
		'file'    => $block,
		'find'    => "\t\treturn \$this->shortcode->gallery( array( 'id' => \$this->reference( \$attributes ) ) );",
		'replace' => "\t\treturn \$this->shortcode->album( array( 'id' => \$this->reference( \$attributes ) ) );",
		'expect'  => 'the block renders exactly what the shortcode renders',
		'why'     => 'the two callbacks differ by one word, which is how a copy-paste slip looks',
	),
	array(
		'id'      => 'BK2',
		'file'    => $block,
		'find'    => "\t\tregister_block_type(\n\t\t\tLICHTBILD_DIR . 'blocks/album',\n\t\t\tarray( 'render_callback' => array( \$this, 'render_album' ) )\n\t\t);",
		'replace' => "\t\t// the album block was never registered.",
		'expect'  => 'both blocks are registered from their metadata',
		'why'     => 'a block absent from the inserter is invisible; nothing errors',
	),
	// The one that is a real bug rather than a slip. `after` prints the data below the script
	// that reads it, so `window.LichtbildBlocks` is undefined, the editor script returns at its
	// first line, and BOTH blocks vanish from the inserter with no error anywhere.
	array(
		'id'      => 'BK3',
		'file'    => $block,
		'find'    => "\t\t\t'window.LichtbildBlocks = ' . wp_json_encode( \$this->editor_data() ) . ';',\n\t\t\t'before'",
		'replace' => "\t\t\t'window.LichtbildBlocks = ' . wp_json_encode( \$this->editor_data() ) . ';',\n\t\t\t'after'",
		'expect'  => 'the editor script carries the picker data',
		'why'     => 'data printed after the script that reads it is data nothing reads',
	),
	// Found by measuring on a real WordPress before the deploy, not by a check — and this is the
	// check. Building the picker's choices reads every gallery row: 111 queries and 11ms on a
	// cold cache, for data only the block editor looks at. Called from `register_blocks()`, which
	// runs on `init`, that is 111 queries on every front-end page view, and not one rendered byte
	// changes either way.
	array(
		'id'      => 'BK8',
		'file'    => $block,
		'find'    => "\t\t// Depends on `lichtbild`, so the preview is laid out by the same stylesheet the visitor",
		'replace' => "\t\twp_add_inline_script(\n\t\t\tself::HANDLE,\n\t\t\t'window.LichtbildBlocks = ' . wp_json_encode( \$this->editor_data() ) . ';',\n\t\t\t'before'\n\t\t);\n\n\t\t// Depends on `lichtbild`, so the preview is laid out by the same stylesheet the visitor",
		'expect'  => 'registering the blocks reads nothing',
		'why'     => 'a cost paid on every request that changes no output is invisible to every other check',
	),
	array(
		'id'      => 'BK4',
		'file'    => $block,
		'find'    => "\t\t\tLICHTBILD_URL . 'assets/css/blocks.css',\n\t\t\tarray( 'lichtbild' ),",
		'replace' => "\t\t\tLICHTBILD_URL . 'assets/css/blocks.css',\n\t\t\tarray(),",
		'expect'  => 'the editor stylesheet is laid out by the front-end one',
		'why'     => 'the preview then renders unstyled, and WP_Styles says nothing either way',
	),
	array(
		'id'      => 'BK5',
		'file'    => $assets,
		'find'    => "\t\tadd_action( 'init', array( \$this, 'register_assets' ) );",
		'replace' => "\t\tadd_action( 'wp_enqueue_scripts', array( \$this, 'register_assets' ) );",
		'expect'  => 'the front-end assets are registered on init',
		'why'     => 'wp_enqueue_scripts never fires in the admin, so the editor dependency is dropped',
	),
	// The picker's other half. AE11 breaks the reader filter; this breaks the status list, which
	// fails in the direction nobody checks: every draft gallery silently missing from both
	// pickers, on a site where a gallery is routinely prepared before it is published.
	array(
		'id'      => 'BK6',
		'file'    => $repository,
		'find'    => "\t\t\t\t'post_status' => array( 'publish', 'private', 'draft', 'pending' ),",
		'replace' => "\t\t\t\t'post_status' => array( 'publish' ),",
		'expect'  => 'the picker offers the galleries the reader can read',
		'why'     => 'get_posts defaults to publish alone, so the omission looks like the default',
	),
	array(
		'id'      => 'BK7',
		'file'    => $repository,
		'find'    => "\t\t\tif ( null === \$this->album( \$post_id ) ) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'replace' => "\t\t\tif ( false ) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'expect'  => 'the picker offers the albums the reader can read',
		'why'     => 'the album twin of AE11: envira keeps its album defaults in an album too',
	),
	// The lifecycle findings from the 2026-08-22 review, and the reason they are three mutations
	// rather than one: the same rule -- ask before you resolve, list or draw somebody else's post
	// -- is enforced at three separate boundaries, and a fix applied to only one of them leaves
	// the other two open while every check that names the rule still passes.
	array(
		'id'      => 'LC1',
		'file'    => $settings_php,
		'find'    => "\t\tif ( null === \$schema && \$this->has_owned_content() ) {",
		'replace' => "\t\tif ( false && null === \$schema && \$this->has_owned_content() ) {",
		'expect'  => 'reinstalling after uninstall finds the content it kept',
		'why'     => 'uninstall keeps the galleries and deletes the options that find them',
	),
	array(
		'id'      => 'CAP1',
		'file'    => $album_editor,
		'find'    => "\t\t\tif ( \$gallery_id > 0 && ! current_user_can( 'edit_post', \$gallery_id ) ) {",
		'replace' => "\t\t\tif ( false && \$gallery_id > 0 && ! current_user_can( 'edit_post', \$gallery_id ) ) {",
		'expect'  => 'a gallery the person cannot edit is not stored as a member',
		'why'     => 'the save guard authorises the album and says nothing about its members',
	),
	array(
		'id'      => 'CAP2',
		'file'    => $repository,
		'find'    => "\t\t\tif ( ! current_user_can( \$capability, \$post_id ) ) {\n\t\t\t\tcontinue;\n\t\t\t}\n\n\t\t\tif ( null === \$this->gallery( \$post_id ) ) {",
		'replace' => "\t\t\tif ( null === \$this->gallery( \$post_id ) ) {",
		'expect'  => 'the gallery picker omits what the person cannot edit',
		'why'     => 'the picker lists private, draft and pending objects by design',
	),
	array(
		'id'      => 'CAP3',
		'file'    => $album_editor,
		'find'    => "\t\t\$gallery    = \$gallery_id > 0 && current_user_can( 'edit_post', \$gallery_id )\n\t\t\t? \$this->repository->gallery( \$gallery_id )\n\t\t\t: null;",
		'replace' => "\t\t\$gallery    = \$gallery_id > 0 ? \$this->repository->gallery( \$gallery_id ) : null;",
		'expect'  => 'a stored member the person cannot edit is not drawn',
		'why'     => 'a member stored before the rule existed still names the gallery',
	),

	// The auxiliary writes the migration makes beside the rows. `update_option()` answers false
	// both for "the write failed" and for "the value was already that", so only a readback can
	// tell them apart -- the same reasoning `set_schema()` carries, applied to the two copies
	// that did not have it.
	array(
		'id'      => 'AUX1',
		'file'    => $migration,
		'find'    => "\t\t\tif ( (int) get_option( Lichtbild_Settings::OPTION_STANDALONE, -1 ) !== \$standalone ) {",
		'replace' => "\t\t\tif ( false ) {",
		'expect'  => 'a failed standalone copy is reported and the galleries stay findable',
		'why'     => 'a lost permalink setting blanks every gallery page once Envira is removed',
	),
	array(
		'id'      => 'AUX2',
		'file'    => $migration,
		'find'    => "\t\t\tif ( ! array_key_exists( \$key, \$stored ) || \$stored[ \$key ] !== \$value ) {",
		'replace' => "\t\t\tif ( false ) {",
		'expect'  => 'a failed seo copy is reported and claims no copied keys',
		'why'     => 'the success notice would report keys copied that never landed',
	),

	// Two things that change no rendered byte and are asserted through the stub's counters:
	// what `initialise()` asks the database, and what the gallery editor primes. Each kill
	// proves the counter is wired to the code and not merely declared beside it.
	array(
		'id'      => 'MEMO1',
		'file'    => $settings_php,
		'find'    => "\t\tif ( \$this->initialised ) {\n\t\t\treturn;\n\t\t}\n",
		'replace' => "\t\t// re-asked every time.\n",
		'expect'  => 'the schema question is asked at most once per request',
		'why'     => 'an un-migrated Envira site has no schema option, so without the memo every predicate call counts the posts table',
	),
	array(
		'id'      => 'PRIME2',
		'file'    => $editor,
		'find'    => "\t\t\$this->prime_items( \$record['items'] );",
		'replace' => "\t\t// primed nothing.",
		'expect'  => 'the gallery editor primes its attachments in one call',
		'why'     => 'priming changes no output, so only its absence can be asserted directly',
	),

);

/**
 * Runs the suite and returns what it reported.
 *
 * @return array{ok:bool,checks:int,failing:int,names:string[],reported:array<string,int>,note:string}
 */
function lichtbild_run_suite() {
	$output = array();
	$status = 0;

	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/render-test.php' );

	if ( '' !== $GLOBALS['lichtbild_mutation_fixture'] ) {
		$command .= ' ' . escapeshellarg( $GLOBALS['lichtbild_mutation_fixture'] );
	}

	exec( $command . ' 2>&1', $output, $status );

	$names   = array();
	$checks  = 0;
	$failing = -1;

	$reported = array();

	foreach ( $output as $line ) {
		foreach ( array( '[FAIL] ', '[EMPTY] ' ) as $marker ) {
			if ( 0 === strpos( $line, $marker ) ) {
				// Split on the marker and strip the trailing counts, rather than reading a
				// fixed column: the report pads the name, and a check name longer than the
				// padding leaves one space instead of several.
				$names[] = trim( (string) preg_replace( '/\s+\d+\s+\d+\s+\d+$/', '', substr( $line, strlen( $marker ) ) ) );
			}
		}

		// Every check the report listed, whatever its verdict, mapped to the population it
		// examined. The names answer "which checks left the report"; the populations answer a
		// question the name set structurally cannot — see `--names` below. Keyed by name, which
		// is safe because `Checks` aggregates every assertion sharing a name into one row.
		if ( preg_match( '/^\[(?:OK|FAIL|EMPTY)\]\s+(.*?)\s+(\d+)\s+\d+\s+\d+$/', $line, $reported_match ) ) {
			$reported[ $reported_match[1] ] = (int) $reported_match[2];
		}

		if ( preg_match( '/^checks: (\d+), failing: (\d+)$/', trim( $line ), $matches ) ) {
			$checks  = (int) $matches[1];
			$failing = (int) $matches[2];
		}
	}

	// No summary line means the run did not finish, and a run that produced no failures for
	// that reason looks exactly like a surviving mutation.
	if ( $failing < 0 ) {
		return array(
			'ok'       => false,
			'checks'   => 0,
			'failing'  => 0,
			'names'    => array(),
			'reported' => array(),
			'note'     => 'no summary line; last output: ' . trim( (string) end( $output ) ),
		);
	}

	// The same fact derived two ways. A parser is the half that breaks silently, so the
	// disagreement is a broken run rather than either answer.
	if ( count( $names ) !== $failing ) {
		return array(
			'ok'       => false,
			'checks'   => $checks,
			'failing'  => $failing,
			'names'    => $names,
			'reported' => $reported,
			'note'     => sprintf( 'parsed %d failing names but the summary said %d', count( $names ), $failing ),
		);
	}

	// And the same again for the whole report. This parser is the newer of the two and the one
	// the vanished-check diff rests on, so it gets the same treatment rather than being trusted
	// because it is short.
	if ( count( $reported ) !== $checks ) {
		return array(
			'ok'       => false,
			'checks'   => $checks,
			'failing'  => $failing,
			'names'    => $names,
			'reported' => $reported,
			'note'     => sprintf( 'parsed %d check names but the summary said %d', count( $reported ), $checks ),
		);
	}

	return array(
		'ok'       => true,
		'checks'   => $checks,
		'failing'  => $failing,
		'names'    => $names,
		'reported' => $reported,
		'note'     => '',
	);
}

$only  = array_slice( $argv, 1 );
$names = false;

// `--names` prints every check a mutation turned red, not just the predicted one. The default
// report says `(+3 more)`, which is enough to read a run and not enough to measure one: working
// out which checks are pinned by no mutation at all means set-differencing the full red set of
// every mutation against the checks the suite reports, and that inventory was first built by
// patching this file by hand. Matching `expect` names against check names is the obvious method
// and it undercounts badly, because most mutations turn several checks red and the names never
// say so — the collateral is real coverage.
// `--fixture=<path>` runs every mutation against a fixture other than the default. The point is
// not convenience: the committed synthetic fixture passes all 206 checks, and a corpus that
// passes while pinning nothing is exactly the empty-filter-reads-as-a-pass trap this file
// exists to catch. Measuring how many mutations it kills is the only thing that says whether it
// covers anything, and that measurement is impossible without this flag.
$GLOBALS['lichtbild_mutation_fixture'] = '';

foreach ( $only as $index => $argument ) {
	if ( '--names' === $argument ) {
		$names = true;

		unset( $only[ $index ] );
	}

	if ( 0 === strpos( (string) $argument, '--fixture=' ) ) {
		$GLOBALS['lichtbild_mutation_fixture'] = substr( $argument, strlen( '--fixture=' ) );

		if ( ! is_readable( $GLOBALS['lichtbild_mutation_fixture'] ) ) {
			printf( "[ERROR] fixture not readable: %s\n", $GLOBALS['lichtbild_mutation_fixture'] );
			exit( 1 );
		}

		unset( $only[ $index ] );
	}
}

$only = array_values( $only );

// Two entries sharing an id makes `php tests/mutations.php E5` run both of them, and the report
// then prints one id twice with different verdicts beside it — which reads as a flake rather
// than as two mutations. Worse, the summary counts more mutations than were asked for, and the
// obvious reading of that is that the harness is doing something clever. It is not.
//
// Found by colliding with an existing id while adding one. The check is four lines and the
// alternative is a targeted run that quietly means something other than what was typed.
$seen_ids = array();

foreach ( $mutations as $mutation ) {
	if ( isset( $seen_ids[ $mutation['id'] ] ) ) {
		printf( "[ERROR] mutation id %s is used twice; ids address a single edit.\n", $mutation['id'] );
		exit( 1 );
	}

	$seen_ids[ $mutation['id'] ] = true;
}

// An id asked for that does not exist would otherwise run nothing and report a clean sweep.
foreach ( $only as $requested ) {
	if ( ! isset( $seen_ids[ $requested ] ) ) {
		printf( "[ERROR] no mutation with id %s; known ids: %s\n", $requested, implode( ', ', array_keys( $seen_ids ) ) );
		exit( 1 );
	}
}

$baseline = lichtbild_run_suite();

if ( ! $baseline['ok'] || $baseline['failing'] > 0 ) {
	// Establish green before editing anything: every later failure is then a signal about the
	// mutation rather than about the tree it was applied to.
	echo "[ERROR] the suite is not green before mutating.\n";
	echo '        ' . ( '' !== $baseline['note'] ? $baseline['note'] : implode( '; ', $baseline['names'] ) ) . "\n";

	exit( 1 );
}

printf( "baseline: %d checks, all passing\n\n", $baseline['checks'] );
printf( "%-5s %-9s %s\n", 'ID', 'VERDICT', 'PREDICTED CHECK' );
echo str_repeat( '-', 78 ) . "\n";

$killed   = 0;
$problems = array();

foreach ( $mutations as $mutation ) {
	if ( ! empty( $only ) && ! in_array( $mutation['id'], $only, true ) ) {
		continue;
	}

	$path     = $root . $mutation['file'];
	$original = file_get_contents( $path );
	$before   = md5( $original );
	$found    = substr_count( $original, $mutation['find'] );

	if ( 1 !== $found ) {
		printf( "%-5s %-9s %s\n", $mutation['id'], 'BROKEN', 'target text occurs ' . $found . ' times in ' . $mutation['file'] );
		$problems[] = $mutation['id'];

		continue;
	}

	// Bytes in, bytes out. Text mode would rewrite line endings on some platforms and the
	// comparison that should catch it reads back through the same translation.
	file_put_contents( $path, str_replace( $mutation['find'], $mutation['replace'], $original ) );

	$result = lichtbild_run_suite();

	file_put_contents( $path, $original );

	if ( md5( (string) file_get_contents( $path ) ) !== $before ) {
		printf( "%-5s %-9s %s\n", $mutation['id'], 'BROKEN', 'the restore did not reproduce ' . $mutation['file'] . ' byte for byte' );
		$problems[] = $mutation['id'];

		continue;
	}

	if ( ! $result['ok'] ) {
		printf( "%-5s %-9s %s\n", $mutation['id'], 'BROKEN', $result['note'] );
		$problems[] = $mutation['id'];

		continue;
	}

	// A mutation must make checks go RED. One that makes them go AWAY has found the hazard the
	// whole `expect()` mechanism exists for: a check only exists once an assertion runs, so a
	// check behind an `assert-and-continue` short-circuit does not fail when the short-circuit
	// fires — it disappears, the total quietly drops, and the report reads as though that area
	// were never applicable. That is indistinguishable from coverage lapsing.
	//
	// Comparing the count against baseline catches the whole class generically, which is the
	// point: the alternative is noticing by hand, one check at a time, and the nine found that
	// way had a tenth sitting beside them that nobody had listed.
	// Compared as SETS, not as totals. A mutation that removes one check and adds another nets to
	// zero, and a count would report that as unchanged — the same "derived two ways" habit as the
	// parser cross-checks above, applied to the thing being measured rather than the measurement.
	// Both directions matter: `B52` was found by a check APPEARING, not by one going missing.
	$gone = array_keys( array_diff_key( $baseline['reported'], $result['reported'] ) );
	$new  = array_keys( array_diff_key( $result['reported'], $baseline['reported'] ) );

	if ( ! empty( $gone ) || ! empty( $new ) ) {
		printf(
			"%-5s %-9s %s\n",
			$mutation['id'],
			'VANISHED',
			sprintf(
				'%d checks reported, baseline %d — %d left the report instead of failing, %d appeared',
				$result['checks'],
				$baseline['checks'],
				count( $gone ),
				count( $new )
			)
		);

		foreach ( $gone as $missing ) {
			echo '                gone: ' . $missing . "\n";
		}

		foreach ( $new as $added ) {
			echo '                new:  ' . $added . "\n";
		}

		echo "                declare them with Checks::expect() so they go [EMPTY] and red\n";
		$problems[] = $mutation['id'];

		continue;
	}

	if ( 0 === $result['failing'] ) {
		printf( "%-5s %-9s %s\n", $mutation['id'], 'SURVIVED', $mutation['expect'] );
		echo '                went red for nothing; ' . $mutation['why'] . "\n";
		$problems[] = $mutation['id'];

		continue;
	}

	if ( ! in_array( $mutation['expect'], $result['names'], true ) ) {
		printf( "%-5s %-9s %s\n", $mutation['id'], 'MISSED', $mutation['expect'] );
		echo '                red instead: ' . implode( ', ', $names ? $result['names'] : array_slice( $result['names'], 0, 4 ) ) . "\n";
		$problems[] = $mutation['id'];

		continue;
	}

	$killed++;

	printf(
		"%-5s %-9s %s%s\n",
		$mutation['id'],
		'KILLED',
		$mutation['expect'],
		count( $result['names'] ) > 1 ? ' (+' . ( count( $result['names'] ) - 1 ) . ' more)' : ''
	);

	// The whole red set, predicted check included, one per line and uniformly labelled — this is
	// an inventory input, so it is parsed more often than it is read.
	if ( $names ) {
		foreach ( $result['names'] as $red ) {
			echo '                red: ' . $red . "\n";
		}

		// Population deltas, which the name set structurally cannot show. A check can keep its
		// row and lose most of what it examined: `every renderable item became a figure` runs
		// once per gallery plus twice synthetically, so a mutation that stops the 51 real
		// galleries reaching it leaves the name in place, backed by 2 assertions instead of 53.
		// `[EMPTY]` catches a population that falls to zero; nothing catches a partial collapse.
		//
		// Reported rather than made a verdict, deliberately: a mutation that legitimately changes
		// what renders also changes populations — `B52` shifts three of them by making one more
		// gallery render, and it is doing exactly what it should. Treating that as a failure
		// would cry wolf on correct mutations, which is how a signal stops being read at all.
		foreach ( $result['reported'] as $name => $population ) {
			if ( isset( $baseline['reported'][ $name ] ) && $baseline['reported'][ $name ] !== $population ) {
				printf( "                population: %s %d -> %d\n", $name, $baseline['reported'][ $name ], $population );
			}
		}
	}
}

$ran = $killed + count( $problems );

echo str_repeat( '-', 78 ) . "\n";
printf( "mutations: %d, killed by the predicted check: %d, unresolved: %d\n", $ran, $killed, count( $problems ) );

if ( ! empty( $problems ) ) {
	echo 'unresolved: ' . implode( ', ', $problems ) . "\n";
}

exit( empty( $problems ) ? 0 : 1 );
