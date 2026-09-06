<?php
/**
 * Renders every gallery in a fixture and asserts the markup is sound.
 *
 * Usage: php tests/render-test.php [path/to/fixture.json]
 *
 * The fixture is exported from a real site by tests/export-fixture.py and is deliberately
 * not committed, because it contains that site's content. Regenerate it before running.
 *
 * Each assertion is reported by name with the population it examined, so a check that
 * silently stopped matching anything reads as `0 examined` rather than as a pass.
 *
 * @package Lichtbild\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

// PHP's CLI writes diagnostics to STDOUT, and one warning anywhere in this file silently
// re-scores the checks that come after it.
//
// The mechanism is worth stating because it is not the obvious one. A warning printed to
// stdout is body output, so `headers_sent()` becomes true, so every later check that exercises
// an AJAX endpoint dies on "Cannot modify header information" instead of on its own merits.
// Three checks are affected — the two endpoint ones and the array-shaped tag — and they turned
// red together under a dozen unrelated mutations, inflating the red sets that `--names`
// reports and that the coverage inventory is built from. Under `SEO3`, which touches Yoast
// settings and nothing else, the output came from an `assert()` whose own expression indexed a
// key that mutation had removed.
//
// It fails in the direction that looks like more coverage, which is why it went unnoticed:
// nobody checks whether a mutation killed *too much*. Routing diagnostics to STDERR keeps them
// visible, keeps them out of the body, and is the same fix `tools/devenv.sh` already applies
// to wp-cli for the same reason.
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
require LICHTBILD_DIR . 'includes/class-lichtbild-repository.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-exif.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-renderer.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-post-types.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-settings.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-migration.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-migration-screen.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-metabox-editor.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-editor.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-album-editor.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-standalone.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-ajax.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-shortcode.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-block.php';
require LICHTBILD_DIR . 'includes/class-lichtbild.php';

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

/**
 * The real asset manager, with the enqueue calls recorded rather than performed.
 *
 * Subclassing the production class rather than substituting a stand-in keeps the
 * renderer's type hint honest and means `need_gallery()`'s own guard is what is exercised.
 */
class Lichtbild_Test_Assets extends Lichtbild_Assets {

	/**
	 * Number of times assets were actually enqueued.
	 *
	 * @var int
	 */
	public static $calls = 0;
}

/**
 * Collects assertion results and reports them grouped by check name.
 */
class Checks {

	/**
	 * Per-check tallies keyed by check name.
	 *
	 * @var array<string,array{pass:int,fail:int,notes:string[]}>
	 */
	private $results = array();

	/**
	 * Declares a check up front so it is reported even if nothing ever exercises it.
	 *
	 * Without this a conditional area — EXIF, say — simply vanishes from the report when it
	 * stops matching, which reads as "not applicable" and is indistinguishable from a check
	 * that silently stopped covering anything.
	 *
	 * @param string ...$names Check names to declare.
	 *
	 * @return void
	 */
	public function expect( ...$names ) {
		foreach ( $names as $name ) {
			if ( ! isset( $this->results[ $name ] ) ) {
				$this->results[ $name ] = array(
					'pass'  => 0,
					'fail'  => 0,
					'notes' => array(),
				);
			}
		}
	}

	/**
	 * Records one assertion.
	 *
	 * @param string $name      Check name.
	 * @param bool   $condition Whether the assertion held.
	 * @param string $note      Context to print when it did not.
	 *
	 * @return bool The condition, so callers can short-circuit.
	 */
	public function assert( $name, $condition, $note = '' ) {
		if ( ! isset( $this->results[ $name ] ) ) {
			$this->results[ $name ] = array(
				'pass'  => 0,
				'fail'  => 0,
				'notes' => array(),
			);
		}

		if ( $condition ) {
			$this->results[ $name ]['pass']++;
		} else {
			$this->results[ $name ]['fail']++;

			if ( count( $this->results[ $name ]['notes'] ) < 5 ) {
				$this->results[ $name ]['notes'][] = $note;
			}
		}

		return (bool) $condition;
	}

	/**
	 * Prints the report and returns the process exit code.
	 *
	 * @return int Zero when everything passed.
	 */
	public function report() {
		$failed = 0;

		echo str_repeat( '-', 78 ) . "\n";
		printf( "%-46s %8s %8s %8s\n", 'CHECK', 'EXAMINED', 'PASS', 'FAIL' );
		echo str_repeat( '-', 78 ) . "\n";

		foreach ( $this->results as $name => $result ) {
			$examined = $result['pass'] + $result['fail'];
			$status   = 0 === $result['fail'] ? '[OK]  ' : '[FAIL]';

			// A declared check that examined nothing has not passed; it has not run.
			if ( 0 === $examined ) {
				$status = '[EMPTY]';
			}

			printf( "%s %-39s %8d %8d %8d\n", $status, $name, $examined, $result['pass'], $result['fail'] );

			if ( $result['fail'] > 0 || 0 === $examined ) {
				$failed++;

				foreach ( $result['notes'] as $note ) {
					echo '         ' . $note . "\n";
				}
			}
		}

		echo str_repeat( '-', 78 ) . "\n";
		printf( "checks: %d, failing: %d\n", count( $this->results ), $failed );

		return $failed > 0 ? 1 : 0;
	}

	/**
	 * Returns how many assertions a named check made.
	 *
	 * @param string $name Check name.
	 *
	 * @return int Assertion count.
	 */
	public function examined( $name ) {
		if ( ! isset( $this->results[ $name ] ) ) {
			return 0;
		}

		return $this->results[ $name ]['pass'] + $this->results[ $name ]['fail'];
	}
}

/**
 * Renders a gallery the repository has just returned, without fataling when it returned none.
 *
 * `Lichtbild_Renderer::gallery()` takes a `Lichtbild_Gallery`, so handing it the `null` a repository
 * returns for a row it cannot find is a TypeError. These call sites sit a hundred lines below the
 * checks that handle a missing row correctly, so that fatal pre-empted the whole report — and a
 * suite that produces no report produces no failures either, which is why the mutations that hit
 * it came back BROKEN. Being BROKEN rather than SURVIVED is the only reason this read as a defect
 * in the harness instead of as a gap in the tests.
 *
 * The cost of not having this was specific: "the reader can no longer find this row" could not be
 * mutated here at all, which is the single most important thing a migration's reader does.
 *
 * @param Checks              $checks   Check recorder.
 * @param Lichtbild_Renderer     $renderer Renderer under test.
 * @param Lichtbild_Gallery|null $gallery  Whatever the repository returned.
 * @param string              $label    Context printed when the row was not found.
 *
 * @return string Rendered markup, or '' when the row was not found.
 */
function lichtbild_render_found( Checks $checks, Lichtbild_Renderer $renderer, $gallery, $label ) {
	if ( ! $checks->assert( 'the reader finds the row it is asked for', $gallery instanceof Lichtbild_Gallery, $label ) ) {
		return '';
	}

	return $renderer->gallery( $gallery, 1 );
}

/**
 * The album twin of `lichtbild_render_found()`, for the identical reason.
 *
 * `Lichtbild_Renderer::album()` is typed the same way, and there were twelve unguarded album call
 * sites against one unguarded gallery site — the gallery one was found because a mutation hit it,
 * and the album ones only because the sweep was widened afterwards. A defect that has been
 * described once and left standing in twelve places is the shape this project has paid for
 * before.
 *
 * @param Checks             $checks     Check recorder.
 * @param Lichtbild_Renderer    $renderer   Renderer under test.
 * @param Lichtbild_Album|null  $album      Whatever the repository returned.
 * @param Lichtbild_Repository  $repository Reader the renderer resolves members through.
 * @param string             $label      Context printed when the row was not found.
 *
 * @return string Rendered markup, or '' when the row was not found.
 */
function lichtbild_render_album_found( Checks $checks, Lichtbild_Renderer $renderer, $album, Lichtbild_Repository $repository, $label ) {
	if ( ! $checks->assert( 'the reader finds the album it is asked for', $album instanceof Lichtbild_Album, $label ) ) {
		return '';
	}

	return $renderer->album( $album, $repository );
}

/**
 * Returns an album the repository has just returned, or an empty one when it returned nothing.
 *
 * The chained reads below — `$reader->album( $id )->gallery_ids()` and its kin — fatal on a null
 * exactly as the renderer calls did, and there is no natural place to put a guard without
 * splitting each one into three statements. An empty album keeps the chain intact and lets the
 * checks that follow say what is actually wrong: `gallery_ids()` comes back empty and each
 * member lookup finds nothing, so each of those turns its own check red.
 *
 * The assertion is what stops this being a way of hiding the failure — but only at the level of
 * the whole run, and the distinction is worth stating because the obvious reading is stronger
 * than the truth. What it guarantees is that the suite cannot go green: this check goes red, so
 * the run fails. What it does NOT guarantee is that every check below is still earning its pass.
 * Two absent renders compare `'' === ''`, so a byte-for-byte round-trip check can pass on
 * nothing.
 *
 * The cover and caption checks used to be in that category and no longer are. They read
 * `Lichtbild_Album::cover_id( $id )`, which answers 0 for a gallery the album does not contain —
 * indistinguishable from a cover that was correctly refused, which is exactly what
 * `a cover outside its gallery is refused` asserts. They go through `lichtbild_album_member()`
 * now, which returns null for an absent member, so "the album has no such row" and "the row
 * stores 0" are different answers and only the second one passes.
 *
 * That is still better than the fatal it replaces, which reported nothing whatever. It is worse
 * than it looks for reading an individual row of the report, so when this check is red, treat
 * the populations below it as suspect rather than as results.
 *
 * @param Checks            $checks Check recorder.
 * @param Lichtbild_Album|null $album  Whatever the repository returned.
 * @param string            $label  Context printed when the row was not found.
 *
 * @return Lichtbild_Album The album, or an empty stand-in.
 */
function lichtbild_album_found( Checks $checks, $album, $label ) {
	if ( $checks->assert( 'the reader finds the album it is asked for', $album instanceof Lichtbild_Album, $label ) ) {
		return $album;
	}

	return new Lichtbild_Album( 0, array(), array() );
}

/**
 * Returns one album member's stored record, or null when the album does not contain it.
 *
 * `Lichtbild_Album` used to carry `cover_id( $id )` and `caption( $id )` for this, with no
 * production caller: the renderer was moved off them in 26.8.5 because looking a member up by
 * ID returns the first match for every position, and an album may legitimately list a gallery
 * twice. What kept them alive afterwards was this file and one mutation, which is not a reason
 * for production code to exist.
 *
 * The lookup is the same first-match-wins one, which is correct here — the checks that use it
 * pass a member appearing once — but it now returns the record rather than a field of it, so an
 * absent member reads as null instead of as a plausible 0 or ''. That distinction is the whole
 * point: `a cover outside its gallery is refused` asserts the stored cover is 0, and 0 is also
 * what the old accessor answered for a gallery the album had never heard of.
 *
 * @param Lichtbild_Album $album      The album.
 * @param int          $gallery_id Member gallery post ID.
 *
 * @return array|null The member record, or null when it is not in this album.
 */
function lichtbild_album_member( Lichtbild_Album $album, $gallery_id ) {
	foreach ( $album->items() as $item ) {
		if ( (int) $item['id'] === (int) $gallery_id ) {
			return $item;
		}
	}

	return null;
}

$fixture = isset( $argv[1] ) ? $argv[1] : __DIR__ . '/fixture.json';

if ( ! is_readable( $fixture ) ) {
	fwrite( STDERR, "[ERROR] fixture not readable: {$fixture}\n" );
	fwrite( STDERR, "        the real fixture comes from the live database, and needs credentials:\n" );
	fwrite( STDERR, "            uv run --with pymysql --with phpserialize python tests/export-fixture.py\n" );
	fwrite( STDERR, "        the synthetic one needs nothing, and is what a fresh clone should use:\n" );
	fwrite( STDERR, "            php tests/make-fixture.php && php tests/render-test.php tests/fixture-synthetic.json\n" );
	exit( 2 );
}

$site       = Lichtbild_Test_Site::load( $fixture );
// ============================================================================
// Does this harness load the whole plugin?
//
// The require list above duplicates the one in `lichtbild-gallery.php`, and it has to: that file also
// defines constants and registers hooks. So a new class has to be added in three places, and
// forgetting one here means the suite silently covers less of the plugin than it appears to --
// or, if the class is reached, dies with a fatal that reads as a code bug rather than a harness
// one. Adding `Lichtbild_Album_Config` did exactly that.
//
// Reported before any check runs, and as a hard exit rather than a check, because a harness
// that is not loading the code under test cannot report anything trustworthy about it.
// ============================================================================

$declared = array();

foreach ( (array) glob( LICHTBILD_DIR . 'includes/class-*.php' ) as $path ) {
	$declared[] = basename( $path );
}

$loaded = array();

foreach ( get_included_files() as $path ) {
	if ( false !== strpos( $path, '/includes/class-' ) ) {
		$loaded[] = basename( $path );
	}
}

$missing = array_diff( $declared, $loaded );

if ( ! empty( $missing ) ) {
	fwrite( STDERR, "[ERROR] the harness does not load every plugin class:\n" );

	foreach ( $missing as $file ) {
		fwrite( STDERR, "        includes/{$file}\n" );
	}

	fwrite( STDERR, "        add it to the require list in tests/render-test.php\n" );
	exit( 2 );
}

$assets     = new Lichtbild_Test_Assets( new Lichtbild_Settings() );
$repository = new Lichtbild_Repository();
$renderer   = new Lichtbild_Renderer( $assets );
$checks     = new Checks();

// Declared up front: these live inside conditional branches, and a branch that stops being
// taken would otherwise remove its checks from the report rather than fail.
$checks->expect(
	'exif gallery enables at least one field',
	'exif payload is valid JSON',
	'exif respects per-field toggles',
	'exif gallery emits some exif',
	'page count arithmetic',
	'one button per page',
	'page one is the first slice of the gallery',
	'tag bar renders buttons',
	'the tag bar announces which filter is applied',
	'tagged items carry their tag slugs',
	'tag bar uses the stored all label',
	'filtered page one is not empty',
	'filtered page count follows the filter',
	'an unknown tag yields nothing',
	'all button can be disabled',
	'the tag list is emitted once per item',
	'every filter button matches an item',
	'a filtered page holds only matching items',
	'the tag bar lists every tag in the gallery',
	'grid image is not the original'
);

// The same hazard one step further in, and it took a mutation to see it. The per-item checks
// below sit behind `if ( ! $checks->assert( ... ) ) { continue; }`, so when the short-circuit
// fires the checks after it do not fail — they never run, and a check that never runs is a
// check that is not in the report. Renaming the link class (mutation B25) took NINE of these
// off the report and turned one red; the total dropped from 197 to 188 and nothing said so.
//
// These are declared for the same reason as the block above, but they were found differently:
// by comparing the reported check count against baseline for every mutation, which is now a
// verdict of its own in `tests/mutations.php`. Doing it by hand had named nine of the thirteen.
$checks->expect(
	'aspect ratio is usable',
	'item has a link',
	'link has href',
	'lightbox dimensions are known',
	'grid and lightbox aspect agree',
	'item has an image',
	'image has alt attribute',
	'image has srcset',
	'image has intrinsic size',
	'client config is valid JSON',
	'client config page count matches',
	'orphan item carries no lightbox dimensions',
	'lightbox declares the full-size dimensions',
	'lightbox srcset reaches the declared width',
	'every renderable item became a figure',
	'empty gallery renders nothing',
	'the reader finds the row it is asked for',
	'the reader finds the album it is asked for',
	'the converter emits an item for a gallery that has one',
	'the migrated album carries its members'
);

$total_rendered = 0;
$total_expected = 0;
$galleries_seen = 0;
$skipped        = array();

foreach ( array_keys( $site->galleries ) as $gallery_id ) {
	$gallery = $repository->gallery( $gallery_id );

	if ( null === $gallery ) {
		$skipped[] = $gallery_id;
		continue;
	}

	$galleries_seen++;

	$errors = array();
	set_error_handler(
		static function ( $severity, $message ) use ( &$errors ) {
			$errors[] = $message;

			return true;
		}
	);

	$html = $renderer->gallery( $gallery, 1 );

	restore_error_handler();

	$label = "#{$gallery_id} " . substr( $gallery->title(), 0, 34 );

	$checks->assert( 'renders without PHP notices', empty( $errors ), $label . ' -> ' . implode( '; ', $errors ) );

	// Nothing this plugin renders may carry an inline `<style>` element.
	//
	// Through 26.8.21 a gallery could store a free-text CSS block that was printed here; the
	// wordpress.org guidelines do not permit a plugin to store and print arbitrary CSS entered
	// through its own UI, so the setting and the output were removed together. This is the guard
	// that they stay removed, and it is a whole-population check rather than a check on the one
	// gallery someone remembered to look at.
	//
	// It is worth nothing on its own -- a corpus in which no gallery ever HAD custom CSS would
	// satisfy it by having nothing to print. `envira css in the corpus is not zero` below is the
	// control that says the input this guards against is actually present.
	$checks->assert(
		'no gallery emits a style element',
		false === stripos( $html, '<style' ),
		$label . ' emitted a style element'
	);

	if ( 0 === $gallery->count() ) {
		$checks->assert( 'empty gallery renders nothing', '' === $html, $label . ' emitted ' . strlen( $html ) . ' bytes' );
		continue;
	}

	$checks->assert( 'non-empty gallery renders markup', '' !== $html, $label );

	// --- parse -----------------------------------------------------------------

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	libxml_clear_errors();
	$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
	$parse_errors = array_filter(
		libxml_get_errors(),
		static function ( $error ) {
			return LIBXML_ERR_FATAL === $error->level;
		}
	);
	libxml_clear_errors();

	$checks->assert( 'markup parses', empty( $parse_errors ), $label );

	$xpath = new DOMXPath( $dom );

	// --- item count ------------------------------------------------------------

	$figures  = $xpath->query( '//figure[contains(@class,"lichtbild-item")]' );
	$expected = count( $gallery->page_items( 1 ) );

	$checks->assert(
		'item count matches page',
		$figures->length === $expected,
		$label . " expected {$expected}, rendered {$figures->length}"
	);

	// The invariant the renderer actually implements: an item is dropped when, and only when,
	// it has no usable src. `item count matches page` states the accident rather than the rule —
	// it happens to be the same number on this fixture because every real item has a src, so the
	// two agree here and would part company the moment one did not. Both are kept: that one
	// notices a dropped photograph, this one says why a drop would be legitimate.
	$renderable = 0;

	foreach ( $gallery->page_items( 1 ) as $page_item ) {
		if ( '' !== $page_item->url( $gallery->grid_size() ) ) {
			$renderable++;
		}
	}

	$checks->assert(
		'every renderable item became a figure',
		$figures->length === $renderable,
		$label . " {$renderable} items carry a usable src, {$figures->length} figures rendered"
	);

	// PhotoSwipe divides by the slide dimensions, so an item whose attachment is gone must
	// reach the grid as an ordinary link and must NOT reach the lightbox carrying zeroes.
	//
	// Stated as an equality between two independently derived counts, per gallery, rather than
	// as a per-item assertion — because the per-item form cannot exist on a corpus with no
	// orphans without being [EMPTY], and a check that disappears on the real fixture is the
	// hazard this suite spent 26.8.9 removing. Both sides are 0 on every real gallery, which is
	// vacuous there and is exactly why the synthetic corpus carries a gallery where they are 1.
	$unmeasurable = 0;

	foreach ( $gallery->page_items( 1 ) as $page_item ) {
		if ( '' === $page_item->url( $gallery->grid_size() ) ) {
			continue;
		}

		$page_meta = wp_get_attachment_metadata( $page_item->id() );

		if ( ! is_array( $page_meta ) || empty( $page_meta['file'] ) ) {
			$unmeasurable++;
		}
	}

	$dimensionless = 0;

	foreach ( $xpath->query( '//a[contains(@class,"lichtbild-link")]' ) as $grid_link ) {
		if ( '' === $grid_link->getAttribute( 'data-pswp-width' ) ) {
			$dimensionless++;
		}
	}

	$checks->assert(
		'every unmeasurable item was kept out of the lightbox',
		$dimensionless === $unmeasurable,
		$label . " {$unmeasurable} items cannot be measured, {$dimensionless} links carry no lightbox size"
	);

	$total_rendered += $figures->length;
	$total_expected += $expected;

	// --- per-item geometry and attributes --------------------------------------

	foreach ( $figures as $figure ) {
		$style  = $figure->getAttribute( 'style' );
		$aspect = 0.0;

		if ( preg_match( '/--lichtbild-aspect:\s*([0-9.]+)/', $style, $match ) ) {
			$aspect = (float) $match[1];
		}

		$checks->assert(
			'aspect ratio is usable',
			$aspect > 0.05 && $aspect < 20.0 && is_finite( $aspect ),
			$label . " aspect={$aspect}"
		);

		$link = $xpath->query( './/a[contains(@class,"lichtbild-link")]', $figure )->item( 0 );

		if ( ! $checks->assert( 'item has a link', null !== $link, $label ) ) {
			continue;
		}

		$checks->assert( 'link has href', '' !== $link->getAttribute( 'href' ), $label );

		$width  = (int) $link->getAttribute( 'data-pswp-width' );
		$height = (int) $link->getAttribute( 'data-pswp-height' );

		// Whether this photograph can be measured at all, which is the line the four checks
		// below are really drawn along.
		//
		// They used to assert a srcset, an intrinsic size, lightbox dimensions and a
		// non-original grid src for *every* item — true of all 2,264 real ones, and false by
		// design for an item whose attachment has been deleted from the media library. Such an
		// item keeps Envira's frozen full-size URL and is deliberately rendered as a plain
		// link, kept out of the lightbox rather than handed to PhotoSwipe as a 0x0 slide for
		// its zoom arithmetic to divide by. So the checks quietly encoded "every item has a
		// live attachment", and deleting one photograph from the live site would have turned
		// four of them red on the next export — reported as a regression in a renderer that
		// was doing exactly what it was built to do.
		//
		// The population does not move on the real corpus, where nothing is orphaned. What
		// replaces the lost coverage is the per-gallery equality below, which states the rule
		// the renderer actually implements rather than the accident this corpus happens to be.
		$item_meta   = wp_get_attachment_metadata( (int) $link->getAttribute( 'data-lichtbild-item' ) );
		$measurable  = is_array( $item_meta ) && ! empty( $item_meta['file'] );

		if ( $measurable ) {
			$checks->assert(
				'lightbox dimensions are known',
				$width > 0 && $height > 0,
				$label . " {$width}x{$height} for " . $link->getAttribute( 'data-lichtbild-item' )
			);
		}

		// The grid aspect and the lightbox aspect describe the same photograph, so they
		// have to agree. Deriving the same number two ways is what would catch a size
		// lookup silently returning a cropped derivative.
		if ( $width > 0 && $height > 0 && $aspect > 0 ) {
			$checks->assert(
				'grid and lightbox aspect agree',
				abs( ( $width / $height ) - $aspect ) < 0.05,
				$label . ' grid=' . round( $aspect, 3 ) . ' lightbox=' . round( $width / $height, 3 )
			);
		}

		$img = $xpath->query( './/img', $link )->item( 0 );

		if ( ! $checks->assert( 'item has an image', null !== $img, $label ) ) {
			continue;
		}

		// `image has src` was asserted here and could not fail. The renderer `continue`s when the
		// src is empty, so a figure without one cannot exist — the assertion held by
		// construction, which is the pattern this project argues elsewhere for deleting. The
		// honest statement of the same property counts figures against items, and it is made
		// once per gallery rather than once per image; see 'every renderable item became a
		// figure' above, and the synthetic pair below that makes it capable of failing.
		// Deliberately unguarded: alt survives an attachment being deleted, because the item
		// record carries its own alt and title. An orphan is still owed one.
		$checks->assert( 'image has alt attribute', $img->hasAttribute( 'alt' ), $label );

		if ( ! $measurable ) {
			continue;
		}

		$checks->assert( 'image has srcset', '' !== $img->getAttribute( 'srcset' ), $label );
		$checks->assert(
			'image has intrinsic size',
			(int) $img->getAttribute( 'width' ) > 0 && (int) $img->getAttribute( 'height' ) > 0,
			$label
		);

		// Envira served the full-size original in the grid. Serving a derivative is the
		// whole performance argument for the replacement, so assert it rather than assume.
		$checks->assert(
			'grid image is not the original',
			false === strpos( $img->getAttribute( 'src' ), '/' . basename( (string) $item_meta['file'] ) ),
			$label . ' grid src=' . basename( $img->getAttribute( 'src' ) )
		);

		// --- the lightbox has to be able to fill the viewport ----------------------------
		//
		// PhotoSwipe's `fit` zoom level is `Math.min( 1, viewport / natural )`, capped at 1, so
		// it never scales an image up: whatever dimensions the anchor declares are the largest
		// the photograph will ever be shown at. Declaring the configured lightbox size therefore
		// capped every slide at that size -- 1024px for the 1,563 attachments here that have a
		// `large`, on any display.
		//
		// Stated as two properties rather than by recomputing what the renderer did. The first
		// is what lets the lightbox fill a big screen; the second is what stops that costing a
		// phone the original file, and neither is satisfied by the other.
		$checks->assert(
			'lightbox declares the full-size dimensions',
			(int) $link->getAttribute( 'data-pswp-width' ) === (int) $item_meta['width'],
			$label . ' declared ' . $link->getAttribute( 'data-pswp-width' ) . ', full is ' . $item_meta['width']
		);

		// The srcset must reach the declared box. A candidate list topping out below it means
		// the browser has nothing large enough to fetch and the slide is upscaled and soft --
		// which is the bug this change exists to fix, arrived at from the other direction.
		$lightbox_srcset = $link->getAttribute( 'data-pswp-srcset' );
		$widest          = 0;

		if ( preg_match_all( '/\s(\d+)w/', $lightbox_srcset, $candidates ) ) {
			$widest = max( array_map( 'intval', $candidates[1] ) );
		}

		if ( '' !== $lightbox_srcset ) {
			$checks->assert(
				'lightbox srcset reaches the declared width',
				$widest >= (int) $link->getAttribute( 'data-pswp-width' ),
				$label . ' widest candidate ' . $widest . 'w for a ' .
					$link->getAttribute( 'data-pswp-width' ) . 'px slide'
			);
		}
	}

	// --- EXIF respects the per-field toggles -----------------------------------

	if ( $gallery->has_exif() ) {
		$enabled = $gallery->exif_fields();
		$allowed = array();

		// Map the config's field keys onto the labels the renderer emits.
		$labels = array(
			'make'         => 'Camera',
			'model'        => 'Camera',
			'focal_length' => 'Focal length',
			'aperture'     => 'Aperture',
			'shutter_speed' => 'Shutter speed',
			'iso'          => 'ISO',
			'capture_time' => 'Taken',
		);

		foreach ( $enabled as $field ) {
			if ( isset( $labels[ $field ] ) ) {
				$allowed[ $labels[ $field ] ] = true;
			}
		}

		$checks->assert(
			'exif gallery enables at least one field',
			! empty( $enabled ),
			$label . ' has_exif() true but no fields enabled'
		);

		$seen_any = false;

		foreach ( $xpath->query( '//a[@data-lichtbild-exif]' ) as $link ) {
			$exif = json_decode( $link->getAttribute( 'data-lichtbild-exif' ), true );

			if ( ! is_array( $exif ) ) {
				$checks->assert( 'exif payload is valid JSON', false, $label );
				continue;
			}

			$checks->assert( 'exif payload is valid JSON', true, $label );
			$seen_any = true;

			foreach ( $exif as $field ) {
				$checks->assert(
					'exif respects per-field toggles',
					isset( $allowed[ $field['label'] ] ),
					$label . ' emitted "' . $field['label'] . '" which is switched off'
				);
			}
		}

		$checks->assert( 'exif gallery emits some exif', $seen_any, $label . ' no item carried exif' );
	}

	// --- gallery-level markup --------------------------------------------------

	$root = $xpath->query( '//div[contains(@class,"lichtbild ")or contains(@class," lichtbild")or @class="lichtbild"]' )->item( 0 );
	$root = null !== $root ? $root : $xpath->query( '//div[@data-lichtbild-id]' )->item( 0 );

	if ( $checks->assert( 'grid element present', null !== $root, $label ) ) {
		$config = json_decode( $root->getAttribute( 'data-lichtbild-config' ), true );

		$checks->assert( 'client config is valid JSON', is_array( $config ), $label );

		if ( is_array( $config ) ) {
			$checks->assert(
				'client config page count matches',
				isset( $config['pages'] ) && (int) $config['pages'] === $gallery->page_count(),
				$label . ' config=' . ( isset( $config['pages'] ) ? $config['pages'] : 'missing' ) .
					' gallery=' . $gallery->page_count()
			);
		}
	}

	// Pagination arithmetic, derived independently of the gallery object.
	if ( $gallery->has_pagination() ) {
		$expected_pages = (int) ceil( $gallery->count() / $gallery->per_page() );

		$checks->assert(
			'page count arithmetic',
			$gallery->page_count() === $expected_pages,
			$label . " {$gallery->page_count()} vs {$expected_pages}"
		);

		if ( $expected_pages > 1 ) {
			$buttons = $xpath->query( '//nav[contains(@class,"lichtbild-pagination")]//span[contains(@class,"lichtbild-page-list")]/button' );

			$checks->assert(
				'one button per page',
				$buttons->length === $expected_pages,
				$label . " {$buttons->length} buttons for {$expected_pages} pages"
			);
		}

		// Which page the first page *is*. Every count above is derived from `page_items()`
		// itself — the rendered figures are compared against it, and the totals are summed from
		// it — so an offset that skipped a whole page would leave all of them agreeing with each
		// other and none of them able to notice. The item list is the one thing pagination is a
		// view onto, so that is what it is compared against.
		$checks->assert(
			'page one is the first slice of the gallery',
			$gallery->page_items( 1 ) === array_slice( $gallery->items(), 0, $gallery->per_page() ),
			$label . ' page one is not the first ' . $gallery->per_page() . ' items'
		);
	}

	// A drop-in must not leak the old plugin's names into its own ids and classes.
	$checks->assert(
		'no envira identifiers in output',
		false === stripos( $html, 'id="envira' ) && false === stripos( $html, 'class="envira' ) &&
			false === stripos( $html, '#envira-gallery-' ),
		$label
	);

	// Attribute hygiene: an unescaped quote would have broken parsing above, but a stray
	// raw `<` inside an attribute would not, so check the source text too.
	$checks->assert(
		'no raw angle brackets in attribute values',
		0 === preg_match( '/="[^"]*<[^"]*"/', $html ),
		$label
	);
}

// --- whole-run controls --------------------------------------------------------

// Counting `seen + skipped` against the total is true however the loop behaves, because
// every iteration increments exactly one of them. What is worth asserting is that the
// number rendered matches an independent count of the galleries that should have rendered.
$should_render = 0;

foreach ( $site->galleries as $record ) {
	$config = isset( $record['data']['config'] ) ? $record['data']['config'] : array();

	if ( 'defaults' !== ( isset( $config['type'] ) ? $config['type'] : '' ) ) {
		$should_render++;
	}
}

$checks->assert(
	'renderable gallery count is independently derived',
	$galleries_seen === $should_render,
	"rendered={$galleries_seen} independently counted={$should_render}"
);

// And every skipped record must be a defaults record — not merely something that failed
// to load, which the old count-based check would have accepted just as happily.
//
// Declared, because it is asserted once per skipped row and nothing skipped means it vanishes
// from the report rather than failing — which is exactly the state a reader that stopped
// skipping the defaults record leaves behind, and it reads as "not applicable".
$checks->expect( 'each skipped gallery is the defaults record' );

foreach ( $skipped as $skipped_id ) {
	$config = isset( $site->galleries[ $skipped_id ]['data']['config'] )
		? $site->galleries[ $skipped_id ]['data']['config']
		: array();

	$checks->assert(
		'each skipped gallery is the defaults record',
		'defaults' === ( isset( $config['type'] ) ? $config['type'] : '' ),
		"#{$skipped_id} was skipped but its type is '" .
			( isset( $config['type'] ) ? $config['type'] : 'missing' ) . "'"
	);
}

$checks->assert(
	'the defaults gallery is not rendered',
	count( $skipped ) >= 1,
	'nothing was skipped; the Envira defaults record should have been'
);

$checks->assert(
	'rendered item total matches expectation',
	$total_rendered === $total_expected,
	"rendered={$total_rendered} expected={$total_expected}"
);

// need_gallery() guards against repeat work, so many galleries must still enqueue once.
$checks->assert(
	'assets enqueued exactly once',
	isset( $GLOBALS['lichtbild_test_enqueued']['style:lichtbild'] ) &&
		1 === $GLOBALS['lichtbild_test_enqueued']['style:lichtbild'] &&
		1 === $GLOBALS['lichtbild_test_enqueued']['script:lichtbild'],
	'enqueued: ' . wp_json_encode( $GLOBALS['lichtbild_test_enqueued'] )
);

// A run that examined nothing prints an unbroken column of [OK]; require a population.
$checks->assert(
	'population is plausible',
	$checks->examined( 'aspect ratio is usable' ) > 100,
	'only ' . $checks->examined( 'aspect ratio is usable' ) . ' items examined'
);

// --- synthetic edge cases ------------------------------------------------------

// An item whose attachment was deleted from the media library: no metadata, no sizes.
$orphan = new Lichtbild_Item(
	999999999,
	array(
		'status'  => 'active',
		'src'     => 'https://example.com/wp-content/uploads/gone.jpg',
		'link'    => 'https://example.com/wp-content/uploads/gone.jpg',
		'title'   => 'Orphan',
		'caption' => '',
		'alt'     => '',
		'thumb'   => '',
	)
);

$checks->assert( 'orphan item keeps a usable url', '' !== $orphan->url( 'medium_large' ), 'orphan url empty' );
$checks->assert( 'orphan item falls back to a sane aspect', abs( $orphan->aspect() - 1.5 ) < 0.001, 'aspect=' . $orphan->aspect() );
$checks->assert( 'orphan item is still active', $orphan->is_active(), 'orphan inactive' );

$pending = new Lichtbild_Item( 1, array( 'status' => 'pending', 'src' => 'x' ) );
$checks->assert( 'pending items are excluded', ! $pending->is_active(), 'pending item reported active' );

// An orphan has no dimensions, so it must reach the grid as an ordinary link and must NOT
// reach the lightbox carrying zeroes — PhotoSwipe divides by those. Rendering it is the
// point: asserting on the item object alone never exercised the markup.
$orphan_gallery = new Lichtbild_Gallery( 424242, array( 'layout' => 'justified' ), array( $orphan ) );
$orphan_html = $renderer->gallery( $orphan_gallery, 1 );

$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $orphan_html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
libxml_clear_errors();
$xpath       = new DOMXPath( $dom );
$orphan_link = $xpath->query( '//a[contains(@class,"lichtbild-link")]' )->item( 0 );

$checks->assert( 'orphan item still renders in the grid', null !== $orphan_link, 'no link rendered' );

if ( null !== $orphan_link ) {
	$checks->assert(
		'orphan item carries no lightbox dimensions',
		'' === $orphan_link->getAttribute( 'data-pswp-width' ) &&
			'' === $orphan_link->getAttribute( 'data-pswp-height' ),
		'width="' . $orphan_link->getAttribute( 'data-pswp-width' ) . '" height="' .
			$orphan_link->getAttribute( 'data-pswp-height' ) . '" — zeroes would break PhotoSwipe'
	);
}

// An item with no attachment and no frozen src has no image to show, so the renderer drops it.
// That is the one legitimate reason a figure may be missing, and on the real fixture it never
// happens — every one of the 2,264 items carries a src — so 'every renderable item became a
// figure' agrees with 'item count matches page' on all 51 galleries and neither can tell the
// two apart. This pair is where they part company: one item usable, one not, one figure.
$srcless = new Lichtbild_Item( 999999998, array( 'status' => 'active' ) );
$usable  = new Lichtbild_Item(
	999999997,
	array(
		'status' => 'active',
		'src'    => 'https://example.com/wp-content/uploads/usable.jpg',
	)
);

$checks->assert( 'an item with no src has no url', '' === $srcless->url( 'medium_large' ), 'url=' . $srcless->url( 'medium_large' ) );

$mixed_html = $renderer->gallery(
	new Lichtbild_Gallery( 424243, array( 'layout' => 'justified' ), array( $usable, $srcless ) ),
	1
);

$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $mixed_html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
libxml_clear_errors();
$mixed_figures = ( new DOMXPath( $dom ) )->query( '//figure[contains(@class,"lichtbild-item")]' );

$checks->assert(
	'every renderable item became a figure',
	1 === $mixed_figures->length,
	'one of two items has a usable src, rendered ' . $mixed_figures->length . ' figures'
);

// The control. Without it the check above is satisfied by a renderer that drops the usable item
// instead of the src-less one, and by one that renders nothing at all whenever it sees a gap.
$both_html = $renderer->gallery(
	new Lichtbild_Gallery( 424244, array( 'layout' => 'justified' ), array( $usable, $usable ) ),
	1
);

$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $both_html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
libxml_clear_errors();

$checks->assert(
	'every renderable item became a figure',
	2 === ( new DOMXPath( $dom ) )->query( '//figure[contains(@class,"lichtbild-item")]' )->length,
	'both items carry a usable src but the grid did not render two figures'
);

// 'empty gallery renders nothing' had NEVER RUN. It lives behind `0 === $gallery->count()` in
// the loop above, and every gallery on this site has items — so the check did not fail, it was
// absent, and the report was one line shorter than anyone had counted. Found by a mutation that
// made the defaults record renderable (B52): the check APPEARED, which is the same hazard
// showing up in the one direction nobody watches for.
$checks->assert(
	'empty gallery renders nothing',
	'' === $renderer->gallery( new Lichtbild_Gallery( 424245, array( 'layout' => 'justified' ), array() ), 1 ),
	'a gallery with no items emitted markup'
);

// --- URLs frozen in the gallery record are validated, not trusted ---------------------

$hostile_schemes = array(
	'javascript:alert(1)',
	'JaVaScRiPt:alert(1)',
	"java\nscript:alert(1)",
	" javascript:alert(1)",
	'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
	'vbscript:msgbox(1)',
);

foreach ( $hostile_schemes as $hostile ) {
	$evil = new Lichtbild_Item(
		0,
		array( 'status' => 'active', 'src' => $hostile, 'link' => $hostile, 'title' => 'x' )
	);

	$checks->assert(
		'hostile url schemes are rejected',
		'' === $evil->url( 'medium_large' ),
		'url() returned ' . var_export( $evil->url( 'medium_large' ), true ) . ' for ' . var_export( $hostile, true )
	);

	$source = $evil->lightbox_source( 'large' );

	$checks->assert(
		'hostile url schemes are rejected',
		'' === $source['url'],
		'lightbox_source() returned ' . var_export( $source['url'], true ) . ' for ' . var_export( $hostile, true )
	);
}

// The control: an ordinary URL must survive the same path untouched, or the check above
// would pass just as well on a method that rejected everything.
$benign = new Lichtbild_Item(
	0,
	array(
		'status' => 'active',
		'src'    => 'https://example.com/wp-content/uploads/x.jpg',
		'link'   => 'https://example.com/wp-content/uploads/x.jpg',
	)
);

$checks->assert(
	'ordinary urls are preserved',
	'https://example.com/wp-content/uploads/x.jpg' === $benign->url( 'medium_large' ),
	'benign url became ' . var_export( $benign->url( 'medium_large' ), true )
);

// --- Captions are filtered on the way out of the database -----------------------------

$payloads = array(
	'<img src=x onerror=alert(1)>'                  => 'onerror',
	'<script>alert(1)</script>'                     => '<script',
	'<svg onload=alert(1)></svg>'                   => 'onload',
	'<a href="#" onclick="alert(1)">x</a>'          => 'onclick',
);

foreach ( $payloads as $payload => $forbidden ) {
	$dirty = new Lichtbild_Item(
		0,
		array( 'status' => 'active', 'src' => 'https://example.com/a.jpg', 'caption' => $payload )
	);

	$checks->assert(
		'caption markup is filtered',
		false === stripos( $dirty->caption(), $forbidden ),
		'caption kept "' . $forbidden . '": ' . $dirty->caption()
	);
}

// Control: legitimate inline markup must survive, or the filter is just strip_tags and the
// feature it exists to support is gone.
$rich = new Lichtbild_Item(
	0,
	array( 'status' => 'active', 'src' => 'https://example.com/a.jpg', 'caption' => 'a <em>b</em> c' )
);

$checks->assert(
	'legitimate caption markup survives',
	false !== strpos( $rich->caption(), '<em>' ),
	'caption became ' . $rich->caption()
);

// Envira's boolean spellings vary by gallery vintage. Exercised through the converter and
// a real setting, so the check follows the value all the way to what a reader would see
// rather than testing a helper in isolation.
foreach ( array( 1, '1', 'True', 'true', true, 'YES', 'on' ) as $truthy ) {
	$converted = Lichtbild_Config::from_envira( array( 'protection' => $truthy ) );

	$checks->assert(
		'truthy config spellings read as true',
		true === $converted['protection'],
		'spelling ' . var_export( $truthy, true ) . ' became ' . var_export( $converted['protection'], true )
	);
}

foreach ( array( 0, '0', 'False', 'false', false, '', 'off', 'nonsense' ) as $falsy ) {
	$converted = Lichtbild_Config::from_envira( array( 'protection' => $falsy ) );

	$checks->assert(
		'falsy config spellings read as false',
		false === $converted['protection'],
		'spelling ' . var_export( $falsy, true ) . ' became ' . var_export( $converted['protection'], true )
	);
}

// An absent key must take the documented default, not whatever the last branch returned.
$empty_conversion = Lichtbild_Config::from_envira( array() );

foreach ( Lichtbild_Config::defaults() as $key => $default ) {
	$checks->assert(
		'an empty envira config yields the defaults',
		$empty_conversion[ $key ] === $default,
		"{$key} became " . var_export( $empty_conversion[ $key ], true ) . ' not ' . var_export( $default, true )
	);
}

// Every stored record must come back with every key, however old the writer was.
$sparse = Lichtbild_Config::fill( array( 'layout' => 'columns' ) );

$checks->assert(
	'sparse settings are filled',
	count( $sparse ) === count( Lichtbild_Config::defaults() ) && 'columns' === $sparse['layout'],
	'filled to ' . count( $sparse ) . ' keys'
);

// --- the migration produces a record that renders identically -------------------------
//
// This is the property the whole migration rests on, and it is the only one worth asserting
// directly: whatever the conversion does to the 281 keys, a visitor must not be able to tell
// that it happened. Comparing the rendered markup byte for byte is a stronger statement than
// comparing settings, because it covers the reader as well as the converter.
$checks->expect( 'converted record renders identically' );

foreach ( array_keys( $site->galleries ) as $gallery_id ) {
	$from_envira = $repository->gallery( $gallery_id );

	if ( null === $from_envira || 0 === $from_envira->count() ) {
		continue;
	}

	$before = $renderer->gallery( $from_envira, 1 );

	// Convert, hand it back to the stub as WordPress would store it, and read it again
	// through the repository's own v2 path rather than by constructing a gallery by hand.
	$site->galleries[ $gallery_id ]['lichtbild'] = Lichtbild_Migration::build_record(
		$site->galleries[ $gallery_id ]['data'],
		$gallery_id
	);

	$after = lichtbild_render_found(
		$checks,
		$renderer,
		( new Lichtbild_Repository( Lichtbild_Repository::GALLERY_POST_TYPE, Lichtbild_Repository::ALBUM_POST_TYPE, Lichtbild_Repository::TAG_TAXONOMY, true ) )->gallery( $gallery_id ),
		"#{$gallery_id} unreadable through the v2 path after conversion"
	);

	$checks->assert(
		'converted record renders identically',
		$before === $after,
		"#{$gallery_id} differs: " . strlen( $before ) . ' vs ' . strlen( $after ) . ' bytes'
	);

	// Leave the fixture as it was so later checks still exercise the Envira path.
	unset( $site->galleries[ $gallery_id ]['lichtbild'] );
}

// --- the same property, for albums ----------------------------------------------------
//
// Albums had no converted record at all until 26.8.3: their rows were renamed and the reader
// went on reading `_eg_album_data` whatever the schema said, so a "migrated" site's albums were
// still Envira's. The equivalence is asserted exactly as it is for galleries -- render through
// the Envira path, convert, render through the v2 path, compare byte for byte -- because that
// covers the reader as well as the converter.
$checks->expect(
	'converted album renders identically',
	'album conversion preserves the member count',
	'album settings carry every key'
);

foreach ( array_keys( $site->albums ) as $album_id ) {
	$from_envira = $repository->album( $album_id );

	if ( null === $from_envira || 0 === $from_envira->count() ) {
		continue;
	}

	$before = $renderer->album( $from_envira, $repository );
	$record = Lichtbild_Migration::build_album_record( $site->albums[ $album_id ]['data'], $album_id );

	$checks->assert(
		'album settings carry every key',
		count( $record['settings'] ) === count( Lichtbild_Album_Config::defaults() ),
		"#{$album_id} has " . count( $record['settings'] ) . ' settings'
	);

	$checks->assert(
		'album conversion preserves the member count',
		count( $record['items'] ) === $from_envira->count(),
		"#{$album_id} " . $from_envira->count() . ' members became ' . count( $record['items'] )
	);

	$site->albums[ $album_id ]['lichtbild'] = $record;

	$owning = new Lichtbild_Repository(
		Lichtbild_Repository::GALLERY_POST_TYPE,
		Lichtbild_Repository::ALBUM_POST_TYPE,
		Lichtbild_Repository::TAG_TAXONOMY,
		true
	);

	$after = lichtbild_render_album_found( $checks, $renderer, $owning->album( $album_id ), $owning, "album #{$album_id} unreadable after conversion" );

	$checks->assert(
		'converted album renders identically',
		$before === $after,
		"#{$album_id} differs: " . strlen( $before ) . ' vs ' . strlen( $after ) . ' bytes'
	);

	// Leave the fixture as it was so later checks still exercise the Envira path.
	unset( $site->albums[ $album_id ]['lichtbild'] );
}

// --- envira's own album defaults row is not an album -------------------------------------
//
// Envira keeps its site-wide album defaults in an album row of its own, exactly as it does for
// galleries, and `config.type` is `defaults` rather than `default` -- one character apart.
// Rendering it produces an empty cover grid on any page that names it.
//
// This check exists because a mutation removing the guard SURVIVED. Nothing noticed, because on
// this site that row happens to be a draft and every loop here skips albums with no members --
// so the guard was unreachable by accident of the data rather than by anything in the code. That
// is the case the project's own rule says not to delete: the impossibility lives in one site's
// fixture, and a published defaults row on any other Envira site would render.
$checks->expect( 'the album defaults row is not an album', 'a real album still loads' );

$defaults_album = 0;
$real_album     = 0;

foreach ( $site->albums as $album_id => $record ) {
	$type = isset( $record['data']['config']['type'] ) ? (string) $record['data']['config']['type'] : '';

	if ( 'defaults' === $type ) {
		$defaults_album = (int) $album_id;
	} elseif ( 0 === $real_album ) {
		$real_album = (int) $album_id;
	}
}

if ( $defaults_album > 0 ) {
	$checks->assert(
		'the album defaults row is not an album',
		null === ( new Lichtbild_Repository() )->album( $defaults_album ),
		"album {$defaults_album} is Envira's defaults row and loaded as a real album"
	);
}

// The control. "It returned null" is also what a reader that can no longer load any album at all
// produces, which is exactly what a mutation gutting `build_album()` would look like.
$checks->assert(
	'a real album still loads',
	$real_album > 0 && null !== ( new Lichtbild_Repository() )->album( $real_album ),
	"album {$real_album} did not load, so the defaults check above proves nothing"
);

// --- the album settings actually reach the markup ---------------------------------------
//
// `has_titles()` and `has_counts()` are new guards, and a setting the renderer stores but never
// consults is exactly what they replaced. Both arms, because "the title is absent" is also true
// of an album that rendered nothing at all.
$checks->expect( 'album settings switch the caption parts off', 'an album shows titles and counts by default' );

$caption_album = array_key_first(
	array_filter(
		$site->albums,
		static function ( $record ) {
			return ! empty( $record['data']['galleries'] ) || ! empty( $record['data']['gallery'] );
		}
	)
);

if ( null !== $caption_album ) {
	$owning_caption = new Lichtbild_Repository(
		Lichtbild_Repository::GALLERY_POST_TYPE,
		Lichtbild_Repository::ALBUM_POST_TYPE,
		Lichtbild_Repository::TAG_TAXONOMY,
		true
	);

	$base = Lichtbild_Migration::build_album_record( $site->albums[ $caption_album ]['data'], $caption_album );

	$site->albums[ $caption_album ]['lichtbild'] = $base;
	$shown                                    = lichtbild_render_album_found( $checks, $renderer, $owning_caption->album( $caption_album ), $owning_caption, "album #{$caption_album} unreadable while owning its data" );

	$checks->assert(
		'an album shows titles and counts by default',
		false !== strpos( $shown, 'lichtbild-album-title' ) && false !== strpos( $shown, 'lichtbild-album-count' ),
		'the control rendered neither a title nor a count, so the check below is untested'
	);

	$base['settings']['show_titles'] = false;
	$base['settings']['show_counts'] = false;

	$site->albums[ $caption_album ]['lichtbild'] = $base;

	// A fresh repository: the one above cached the album from its first read.
	$owning_caption = new Lichtbild_Repository(
		Lichtbild_Repository::GALLERY_POST_TYPE,
		Lichtbild_Repository::ALBUM_POST_TYPE,
		Lichtbild_Repository::TAG_TAXONOMY,
		true
	);

	$hidden = lichtbild_render_album_found( $checks, $renderer, $owning_caption->album( $caption_album ), $owning_caption, "album #{$caption_album} unreadable while owning its data" );

	$checks->assert(
		'album settings switch the caption parts off',
		false === strpos( $hidden, 'lichtbild-album-title' ) && false === strpos( $hidden, 'lichtbild-album-count' )
			&& false !== strpos( $hidden, 'lichtbild-album-item' ),
		'switching both off changed ' . ( strlen( $shown ) - strlen( $hidden ) ) . ' bytes'
	);

	unset( $site->albums[ $caption_album ]['lichtbild'] );
}

// --- the album converter, directly -----------------------------------------------------
//
// The equivalence check above cannot see a *symmetric* converter bug. Both the Envira path and
// the v2 path now run through `Lichtbild_Album_Config::from_envira()`, so a mutation that makes it
// wrong makes both sides wrong in the same way and the byte comparison still passes. The gallery
// schema has the same shape and the same answer: check the converter against crafted input.
$checks->expect(
	'album settings convert from envira',
	'an unreadable album flag takes its default',
	'an album cover is the cover, never the gallery id',
	'an unchecked album box switches its setting off'
);

$off = Lichtbild_Album_Config::from_envira(
	array(
		'title'               => '  Spaced  ',
		'columns'             => '4',
		'display_titles'      => '0',
		'display_image_count' => 0,
	)
);

$on = Lichtbild_Album_Config::from_envira(
	array(
		'columns'             => 'nonsense',
		'display_titles'      => 'below',
		'display_image_count' => 'True',
	)
);

$checks->assert(
	'album settings convert from envira',
	4 === $off['columns'] && false === $off['show_titles'] && false === $off['show_counts']
		// The input carries a title and the record must not: an album's title is the post's, and
		// a stored copy would be an override that survives a rename of the post.
		&& ! array_key_exists( 'title', $off ),
	'switched-off album converted to ' . wp_json_encode( $off )
);

// The other direction, and the reason `flag()` exists at all: Envira spells true four ways, and
// `display_titles` is a *position* string rather than a boolean.
$checks->assert(
	'album settings convert from envira',
	3 === $on['columns'] && true === $on['show_titles'] && true === $on['show_counts'],
	'switched-on album converted to ' . wp_json_encode( $on )
);

// A value that is not a scalar at all -- an array left by a hand-edit or by an older writer --
// says nothing about the setting, so the setting keeps what an absent key would have given it.
// That is what the gallery twin has always done and what this one did not: it cast to a string,
// which is a PHP warning and then a flat false whatever default the caller asked for. Two twins
// deliberately kept apart still have to be checked apart, or one of them drifts unwatched.
$unreadable_warnings = array();

set_error_handler(
	static function ( $number, $message ) use ( &$unreadable_warnings ) {
		$unreadable_warnings[] = $message;

		return true;
	}
);

$unreadable = Lichtbild_Album_Config::from_envira(
	array(
		'columns'             => '2',
		'display_image_count' => array( 'nonsense' ),
	)
);

restore_error_handler();

$checks->assert(
	'an unreadable album flag takes its default',
	true === $unreadable['show_counts'] && array() === $unreadable_warnings,
	'converted to ' . wp_json_encode( $unreadable ) . '; diagnostics: ' . implode( '; ', $unreadable_warnings )
);

// `id` in an album entry is the **gallery's** ID. The old cover lookup fell back to it, which
// could only ever name the wrong attachment; it was masked because every real entry sets
// `cover_image_id` and because a gallery ID handed to the image lookup returns nothing.
$with_cover = Lichtbild_Album_Config::item_from_envira( 951, array( 'id' => '951', 'cover_image_id' => '952', 'caption' => 'c' ) );
$no_cover   = Lichtbild_Album_Config::item_from_envira( 951, array( 'id' => '951' ) );

$checks->assert(
	'an album cover is the cover, never the gallery id',
	952 === $with_cover['cover_id'] && 951 === $with_cover['id'] && 'c' === $with_cover['caption'],
	'entry with a cover converted to ' . wp_json_encode( $with_cover )
);

$checks->assert(
	'an album cover is the cover, never the gallery id',
	0 === $no_cover['cover_id'],
	'an entry with no cover took ' . $no_cover['cover_id'] . ' as its cover'
);

// The album's title is the post's, and never a copy inside the record. Nothing in the plugin
// renders it today, which is exactly why it is asserted: an untested accessor that prefers a
// frozen copy is how renaming an album post comes to have no visible effect. Envira stored one
// and `Lichtbild_Album_Config` drops it; this is the reading half of that decision.
if ( $real_album > 0 ) {
	$titled = ( new Lichtbild_Repository() )->album( $real_album );

	$checks->assert(
		'an album titles itself from its post',
		null !== $titled && '' !== $titled->title() && $titled->title() === get_the_title( $real_album ),
		'the album called itself "' . ( null === $titled ? '(missing)' : $titled->title() ) .
			'" where the post is "' . get_the_title( $real_album ) . '"'
	);
}

// `sanitize()` is not `fill()` with sanitising bolted on: an absent key means the default to one
// and an unchecked checkbox to the other, and defaulting a checkbox to true makes it unswitchable.
$submitted = Lichtbild_Album_Config::sanitize( array( 'columns' => '5' ) );
$stored    = Lichtbild_Album_Config::fill( array( 'columns' => 5 ) );

$checks->assert(
	'an unchecked album box switches its setting off',
	false === $submitted['show_titles'] && false === $submitted['show_counts']
		&& true === $stored['show_titles'] && true === $stored['show_counts'],
	'submitted ' . wp_json_encode( $submitted ) . ' vs stored ' . wp_json_encode( $stored )
);

// The conversion must not silently drop images.
foreach ( array_keys( $site->galleries ) as $gallery_id ) {
	$data   = $site->galleries[ $gallery_id ]['data'];
	$record = Lichtbild_Migration::build_record( $data, $gallery_id );

	// Envira's defaults row now returns null from the builder rather than being filtered out by
	// the migration loop, so that galleries and albums can share one walker. It is not a gallery
	// and has no item count to preserve.
	if ( null === $record ) {
		continue;
	}

	$source = isset( $data['gallery'] ) && is_array( $data['gallery'] ) ? $data['gallery'] : array();

	$checks->assert(
		'conversion preserves the item count',
		count( $record['items'] ) === count( $source ),
		"#{$gallery_id} " . count( $source ) . ' items became ' . count( $record['items'] )
	);

	$checks->assert(
		'converted settings carry every key',
		count( $record['settings'] ) === count( Lichtbild_Config::defaults() ),
		"#{$gallery_id} has " . count( $record['settings'] ) . ' settings'
	);
}

// Not one of the 2,264 real items carries a caption or alt text, and no attachment has an
// excerpt to fall back to — so the equivalence check above, run over the fixture alone,
// cannot notice a conversion that drops those fields. A synthetic gallery supplies them.
$carrier = null;

foreach ( $site->galleries as $candidate_id => $candidate ) {
	foreach ( array_keys( (array) ( $candidate['data']['gallery'] ?? array() ) ) as $attachment_id ) {
		if ( isset( $site->attachments[ (int) $attachment_id ]['data'] ) ) {
			$carrier = (int) $attachment_id;
			break 2;
		}
	}
}

if ( null !== $carrier ) {
	$synthetic_id = 999001;

	$site->galleries[ $synthetic_id ] = array(
		'id'     => $synthetic_id,
		'title'  => 'Synthetic caption carrier',
		'status' => 'publish',
		'name'   => 'synthetic',
		'data'   => array(
			'config'  => array( 'columns' => 0, 'title_display' => 'below' ),
			'gallery' => array(
				$carrier => array(
					'status'  => 'active',
					'src'     => 'https://example.com/a.jpg',
					'link'    => 'https://example.com/a.jpg',
					'title'   => 'Carrier title',
					'caption' => 'Caption with <em>markup</em>',
					'alt'     => 'Carrier alt text',
				),
			),
		),
	);

	// The stub answers post types from its own rows, so a gallery added after load needs one.
	$site->build_tables();

	$synthetic_before = lichtbild_render_found(
		$checks,
		$renderer,
		( new Lichtbild_Repository() )->gallery( $synthetic_id ),
		"#{$synthetic_id} synthetic gallery unreadable through the envira path"
	);

	// Control first: unless these actually reach the markup, comparing before and after
	// proves nothing, exactly as the fixture-only run did.
	$checks->assert(
		'synthetic caption reaches the markup',
		false !== strpos( $synthetic_before, 'Caption with' ),
		'caption absent from rendered output'
	);

	$checks->assert(
		'synthetic alt reaches the markup',
		false !== strpos( $synthetic_before, 'Carrier alt text' ),
		'alt absent from rendered output'
	);

	$site->galleries[ $synthetic_id ]['lichtbild'] = Lichtbild_Migration::build_record(
		$site->galleries[ $synthetic_id ]['data'],
		$synthetic_id
	);

	$synthetic_after = lichtbild_render_found(
		$checks,
		$renderer,
		( new Lichtbild_Repository( Lichtbild_Repository::GALLERY_POST_TYPE, Lichtbild_Repository::ALBUM_POST_TYPE, Lichtbild_Repository::TAG_TAXONOMY, true ) )->gallery( $synthetic_id ),
		"#{$synthetic_id} synthetic gallery unreadable through the v2 path"
	);

	$checks->assert(
		'conversion preserves captions and alt text',
		$synthetic_before === $synthetic_after,
		'synthetic gallery differs after conversion'
	);

	unset( $site->galleries[ $synthetic_id ] );
	$site->build_tables();
}

// The tag filter is switched off on every gallery in the fixture, so the only way to know
// the bar works is to build a gallery that enables it, reusing the real items of whichever
// gallery carries the most tagged images.
//
// That gallery used to be named by ID -- `$repository->gallery( 951 )`, which is a fact about
// one database written into the suite. It cost nothing while there was one fixture and made
// eleven checks report [EMPTY] against the first other one, because the ID resolved to
// nothing and the whole block was skipped. Selecting by the property the block needs is both
// fixture-agnostic and a better statement of what it is looking for.
$tagged_source = null;
$tagged_best   = 0;

foreach ( array_keys( $site->galleries ) as $candidate_id ) {
	$candidate = $repository->gallery( $candidate_id );

	if ( null === $candidate ) {
		continue;
	}

	$candidate_tags = array();

	foreach ( $candidate->items() as $candidate_item ) {
		foreach ( $candidate_item->tags() as $candidate_tag ) {
			$candidate_tags[ $candidate_tag['slug'] ] = true;
		}
	}

	if ( count( $candidate_tags ) > $tagged_best ) {
		$tagged_best   = count( $candidate_tags );
		$tagged_source = $candidate;
	}
}

if ( null !== $tagged_source ) {
	$tag_config                     = $tagged_source->settings();
	$tag_config['tags']             = true;
	$tag_config['tags_all_label']   = 'Alle';
	$tag_config['tags_all_enabled'] = true;
	$tag_config['tags_position']    = 'above';

	$tagged = new Lichtbild_Gallery( $tagged_source->id(), $tag_config, $tagged_source->items() );
	$html   = $renderer->gallery( $tagged, 1 );

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
	libxml_clear_errors();
	$xpath = new DOMXPath( $dom );

	$buttons = $xpath->query( '//div[contains(@class,"lichtbild-tags")]/button' );

	$checks->assert( 'tag bar renders buttons', $buttons->length > 1, 'buttons=' . $buttons->length );
	$checks->assert(
		'tag bar uses the stored all label',
		$buttons->length > 0 && 'Alle' === trim( $buttons->item( 0 )->textContent ),
		'first button was "' . ( $buttons->length ? $buttons->item( 0 )->textContent : '' ) . '"'
	);

	// The selected filter has to be announced, not merely drawn. `is-current` is a CSS hook and
	// carries nothing to a screen reader, so which tag was applied was unavailable -- on a
	// control group whose sibling pagination has exposed its active page with `aria-current`
	// since it was written. Both halves are asserted: exactly one button pressed, and every
	// other one explicitly not, because a group where the inactive buttons simply omit the
	// attribute reads as unknown state rather than as one of several selected.
	$pressed     = $xpath->query( '//div[contains(@class,"lichtbild-tags")]/button[@aria-pressed="true"]' );
	$not_pressed = $xpath->query( '//div[contains(@class,"lichtbild-tags")]/button[@aria-pressed="false"]' );

	$pressed_is_current = 1 === $pressed->length
		&& false !== strpos( (string) $pressed->item( 0 )->getAttribute( 'class' ), 'is-current' );

	$checks->assert(
		'the tag bar announces which filter is applied',
		1 === $pressed->length
			&& $buttons->length === $pressed->length + $not_pressed->length
			&& $pressed_is_current,
		$pressed->length . ' pressed and ' . $not_pressed->length . ' unpressed of ' . $buttons->length
			. ' buttons; the pressed one ' . ( $pressed_is_current ? 'is' : 'is NOT' ) . ' the drawn one'
	);

	$tagged_items = $xpath->query( '//figure[@data-lichtbild-tags]' );

	$checks->assert(
		'tagged items carry their tag slugs',
		$tagged_items->length > 0,
		'no figure carried data-lichtbild-tags'
	);

	// One copy, on the figure, which is the only one the filter reads. It was emitted on the
	// anchor as well: the same list twice on every tagged image of every page, and two places
	// that have to stay identical for as long as anyone remembers they both exist.
	$tag_attributes = substr_count( $html, 'data-lichtbild-tags=' );

	$checks->assert(
		'the tag list is emitted once per item',
		$tagged_items->length > 0 && $tagged_items->length === $tag_attributes,
		$tagged_items->length . ' tagged figures against ' . $tag_attributes . ' attributes'
	);

	// The filter bar lists every tag in the gallery, not just those on the current page,
	// so each button has to resolve against the whole gallery — and the first page of that
	// filtered set has to be non-empty, or the button leads to a blank grid. Checking the
	// rendered page instead would have passed while doing exactly that.
	foreach ( $buttons as $button ) {
		$slug = $button->getAttribute( 'data-lichtbild-tag' );

		if ( '' === $slug ) {
			continue;
		}

		$matching = $tagged->filtered_items( $slug );

		$checks->assert(
			'every filter button matches an item',
			count( $matching ) > 0,
			'slug "' . $slug . '" matches nothing in the gallery'
		);

		$checks->assert(
			'filtered page one is not empty',
			count( $tagged->page_items( 1, $slug ) ) > 0,
			'slug "' . $slug . '" yields an empty first page'
		);

		// Filtering changes how many pages there are; the arithmetic has to follow.
		//
		// The `has_pagination()` branch is not defensive padding: `per_page()` is 0 on a
		// gallery with pagination off, and this divided by it unconditionally — so the suite
		// fataled rather than failed the moment the most-tagged gallery was one without it.
		// The slice fifteen lines down already branched the same way, which is what says the
		// unguarded division was an oversight rather than an assumption worth keeping.
		$checks->assert(
			'filtered page count follows the filter',
			$tagged->page_count( $slug ) === ( $tagged->has_pagination()
				? max( 1, (int) ceil( count( $matching ) / $tagged->per_page() ) )
				: 1 ),
			'slug "' . $slug . '" pages=' . $tagged->page_count( $slug ) . ' matching=' . count( $matching )
		);

		// And the page that button leads to has to be a page of *that* filter. "Not empty" and
		// the page count above are both satisfied by a page sliced out of the whole gallery,
		// which is exactly the DOM-hiding filter this one replaced.
		$expected_page = $tagged->has_pagination()
			? array_slice( $matching, 0, $tagged->per_page() )
			: $matching;

		$checks->assert(
			'a filtered page holds only matching items',
			$tagged->page_items( 1, $slug ) === $expected_page,
			'slug "' . $slug . '" page one is not the first page of its own matches'
		);
	}

	// The bar names every tag in the gallery, not the tags of the page on screen — which is the
	// whole reason filtering happens on the server rather than in the DOM. A bar built from the
	// rendered page is a strict subset here, so the second clause is what makes the comparison
	// discriminating rather than a restatement of whatever the renderer just did.
	$gallery_slugs = array();
	$page_slugs    = array();
	$button_slugs  = array();

	foreach ( $tagged->items() as $tagged_item ) {
		foreach ( $tagged_item->tags() as $tagged_tag ) {
			$gallery_slugs[ $tagged_tag['slug'] ] = true;
		}
	}

	foreach ( $tagged->page_items( 1 ) as $tagged_item ) {
		foreach ( $tagged_item->tags() as $tagged_tag ) {
			$page_slugs[ $tagged_tag['slug'] ] = true;
		}
	}

	foreach ( $buttons as $button ) {
		$bar_slug = $button->getAttribute( 'data-lichtbild-tag' );

		if ( '' !== $bar_slug ) {
			$button_slugs[ $bar_slug ] = true;
		}
	}

	ksort( $gallery_slugs );
	ksort( $button_slugs );

	$checks->assert(
		'the tag bar lists every tag in the gallery',
		$button_slugs === $gallery_slugs && count( $gallery_slugs ) > count( $page_slugs ),
		count( $button_slugs ) . ' buttons for ' . count( $gallery_slugs ) . ' tags in the gallery, ' .
			count( $page_slugs ) . ' of them on the rendered page'
	);

	// A tag nobody carries must not be presentable as a page of results.
	$checks->assert(
		'an unknown tag yields nothing',
		array() === $tagged->filtered_items( 'no-such-tag-exists' ),
		'unknown tag returned items'
	);

	// And the "all" button can be switched off.
	$tag_config['tags_all_enabled'] = 0;
	$without_all                    = new Lichtbild_Gallery( $tagged_source->id(), $tag_config, $tagged_source->items() );

	$checks->assert(
		'all button can be disabled',
		false === strpos( $renderer->gallery( $without_all, 1 ), '>Alle<' ),
		'the Alle button survived tags_all_enabled=0'
	);
}

// Envira has two generations of the EXIF and social toggles, and the newer one is documented
// as winning. "Winning" has to mean present rather than true: written as `new || old`, a
// newer toggle explicitly switched off can never override an older one left on — which is the
// only case where the precedence is load-bearing at all. No gallery in the fixture is in that
// state, so nothing above would notice.
$precedence = Lichtbild_Config::from_envira(
	array(
		'exif'                     => 1,
		'exif_lightbox'            => 0,
		'exif_model'               => 1,
		'exif_lightbox_model'      => 0,
		'social'                   => 1,
		'social_lightbox'          => 0,
		'social_facebook'          => 1,
		'social_lightbox_facebook' => 0,
	)
);

$checks->assert(
	'a newer toggle overrides an older one',
	false === $precedence['exif']
		&& false === $precedence['social']
		&& ! in_array( 'model', $precedence['exif_fields'], true )
		&& ! in_array( 'facebook', $precedence['social_networks'], true ),
	'exif=' . wp_json_encode( $precedence['exif'] ) . ' social=' . wp_json_encode( $precedence['social'] )
		. ' fields=' . wp_json_encode( $precedence['exif_fields'] )
		. ' networks=' . wp_json_encode( $precedence['social_networks'] )
);

// The control, in the other direction: with the newer key absent, the older one still decides.
$fallback = Lichtbild_Config::from_envira(
	array(
		'exif'            => 1,
		'exif_model'      => 1,
		'social'          => 1,
		'social_facebook' => 1,
	)
);

$checks->assert(
	'an older toggle still applies when alone',
	true === $fallback['exif']
		&& true === $fallback['social']
		&& in_array( 'model', $fallback['exif_fields'], true )
		&& in_array( 'facebook', $fallback['social_networks'], true ),
	'exif=' . wp_json_encode( $fallback['exif'] ) . ' fields=' . wp_json_encode( $fallback['exif_fields'] )
);

// Custom CSS is not converted, not stored, and not rendered -- three separate guards, because
// the feature had three separate halves and removing any two of them would leave the third.
//
// Envira's own record still holds `custom_css` on the galleries that had it, and that is
// deliberate: nothing was destroyed, and `tools/export-custom-css.py` is how it comes back out
// for pasting into the Customizer. What must not happen is Lichtbild reading it again.
$styled = Lichtbild_Config::from_envira(
	array( 'custom_css' => '#envira-gallery-2423 { margin: 0 } #envira-gallery-wrap-2423 { padding: 0 }' ),
	2423
);

$checks->assert(
	'conversion drops envira custom css',
	! array_key_exists( 'custom_css', $styled ),
	'converted keys: ' . implode( ', ', array_keys( $styled ) )
);

// The submitted-form side of the same rule. `sanitize()` builds an allowlisted record, so this
// asserts the allowlist has no entry rather than that some stripping ran.
//
// The recorder is cleared first, and the honest description of why is narrower than it looks.
// `$GLOBALS['lichtbild_test_filters']` is written by whatever called the hook last, so a check
// reading it afterwards cannot tell "this call passed two arguments" from "this call never
// reached `apply_filters()` and I am reading an older call's value". Today no earlier call
// writes this key, so the check already fails when the filter is deleted outright — mutation
// `B99` is killed with this line and without it, measured both ways rather than reasoned about.
// The line is here so that the property holds BY CONSTRUCTION rather than by the accident of
// this being the first `Lichtbild_Config::sanitize()` call in the file, which the next check
// inserted above it would quietly end.
unset( $GLOBALS['lichtbild_test_filters']['lichtbild_config_sanitize'] );

$css_in = Lichtbild_Config::sanitize(
	array( 'custom_css' => '#lichtbild-1 > figure { color: red } </style><script>alert(1)</script>' )
);

$checks->assert(
	'a submitted custom css is not stored',
	! array_key_exists( 'custom_css', $css_in ),
	'sanitised keys: ' . implode( ', ', array_keys( $css_in ) )
);

// A sanitising function must not offer a way around itself. `lichtbild_config_sanitize` used to
// receive the raw submission as a second argument, for context, which hands every callback on
// the hook an unsanitised `$_POST` array and a reason to reach for it — the wordpress.org
// review is what named it. Removing it changes no return value, so this is the only shape of
// check that can see it come back: hook name plus value is two arguments, and a third is the
// raw input by any other name.
//
// The count comes from the call `$css_in` just made, which is why this sits here rather than
// with the other filter-adjacent checks.
$sanitize_filter_args = $GLOBALS['lichtbild_test_filters']['lichtbild_config_sanitize'] ?? 0;

$checks->assert(
	'the config sanitize filter is handed no raw input',
	2 === $sanitize_filter_args,
	sprintf( 'apply_filters( "lichtbild_config_sanitize", ... ) received %d arguments', $sanitize_filter_args )
);

// The editor must no longer offer the field either; that check lives beside the schema/form
// drift section further down, which is where the settings form is already rendered.
$checks->expect( 'the settings form offers no custom css field' );

// The control for `no gallery emits a style element`, which that check needs and cannot supply
// for itself: at least one gallery in whichever corpus is loaded must actually carry custom CSS
// in its Envira record. Without this, a corpus with none would satisfy the guard by having
// nothing to print, and the guard would go on passing after the feature came back.
// Read through `get_post_meta()` rather than off the fixture record: that is the door the
// plugin itself uses, so this cannot pass by reading a shape the reader never sees.
$envira_css = 0;
foreach ( array_keys( $site->galleries ) as $css_id ) {
	$css_record = get_post_meta( $css_id, '_eg_gallery_data', true );
	$css_config = is_array( $css_record ) && isset( $css_record['config'] ) ? (array) $css_record['config'] : array();

	if ( '' !== trim( (string) ( isset( $css_config['custom_css'] ) ? $css_config['custom_css'] : '' ) ) ) {
		$envira_css++;
	}
}

$checks->assert(
	'envira css in the corpus is not zero',
	$envira_css > 0,
	'galleries carrying envira custom_css: ' . $envira_css
);

// --- running the migration, for real, over the whole site -----------------------------
//
// Everything above converts a record and compares markup. This runs `migrate()` itself: the
// post types and the taxonomy are renamed in the stub's own tables, the option is written,
// and then every gallery is rendered again through a repository built the way the plugin
// builds it after a migration. That covers what conversion checks cannot — that the renamed
// rows are still reachable, that the taxonomy rename carried the tags with it, and that the
// reader consults the right names afterwards.
//
// It goes last because it is the only section that changes global state.
$checks->expect(
	'migrated gallery renders identically',
	'migrated album renders identically',
	'migration converts every real album',
	'plan counts the rows that exist',
	'plan classifies every gallery',
	'migration moves every row',
	'migration converts every real gallery',
	'plan follows the direction',
	'rollback restores every row',
	'rollback leaves the converted records in place',
	'a rolled back site ignores the converted album',
	'a migrated site does use the converted album',
	'the migration carries seo settings onto the new names',
	'it never invents settings for types it does not register',
	'a rollback needs no inverse of that'
);

$settings  = new Lichtbild_Settings();
$migration = new Lichtbild_Migration( $settings );

// A backslash, put into a real gallery before anything is rendered or converted.
//
// Not decoration: the migration reads a record out of one meta key and writes it to another,
// and `update_post_meta()` unslashes what it stores. Without `wp_slash()` on the way in, the
// stored record differs from the intended one -- so `convert()`'s read-back comparison fails
// and the whole migration stops, on data no one would think of as unusual. The live site
// happens to contain no backslash anywhere, which is precisely why this has to be injected
// rather than waited for. Removed again after the migration section.
$slash_gallery = 0;

foreach ( $site->galleries as $slash_candidate => $slash_record ) {
	foreach ( array_keys( (array) ( $slash_record['data']['gallery'] ?? array() ) ) as $slash_item ) {
		$site->galleries[ $slash_candidate ]['data']['gallery'][ $slash_item ]['title'] = 'Backslash C:\\Photos test';
		$slash_gallery = (int) $slash_candidate;

		break 2;
	}
}

$checks->expect( 'a backslash survives the migration' );

// Yoast's option, in the shape the live site actually had it: settings keyed on the post type
// and taxonomy NAMES, which this migration is about to rename out from under them. The two
// `envira-category` keys are the trap -- a different Envira taxonomy that Lichtbild never
// registered, so a substring match would invent settings describing an archive that no longer
// exists. That mistake was made by hand on the real site and caught only by printing the list.
$site->options['wpseo_titles'] = array(
	'title-envira'                  => '%%title%% %%sep%% %%sitename%%',
	'noindex-envira'                => false,
	'title-envira_album'            => '%%title%% %%sep%% %%sitename%%',
	'title-tax-envira-tag'          => '%%term_title%% Archive %%sep%% %%sitename%%',
	'noindex-tax-envira-tag'        => false,
	'taxonomy-envira-tag-ptparent'  => 0,
	'post_types-envira-maintax'     => 0,
	'title-tax-envira-category'     => '%%term_title%% Archive %%sep%% %%sitename%%',
	'noindex-tax-envira-category'   => false,
	'title-post'                    => '%%title%%',
);

$seo_before = $site->options['wpseo_titles'];

// Render everything first, through the Envira path, to compare against afterwards.
$pre_migration = array();

// A fresh reader, not the one at the top of the file. That one has been answering checks since
// line 233 and memoises every gallery it built, so a snapshot taken through it can predate an
// edit made to the fixture since -- which is exactly what the backslash injection above is. The
// symptom was three equivalence checks failing while the value they disagreed about was
// demonstrably correct on both sides.
$pre_migration_reader = new Lichtbild_Repository();

foreach ( array_keys( $site->galleries ) as $gallery_id ) {
	$gallery = $pre_migration_reader->gallery( $gallery_id );

	if ( null !== $gallery && $gallery->count() > 0 ) {
		$pre_migration[ $gallery_id ] = $renderer->gallery( $gallery, 1 );
	}
}

// The same, for albums. They were absent from this whole section until 26.8.3, which is exactly
// why nobody noticed that the migration renamed album rows without converting album records: the
// post-migration render was never asked about an album.
$pre_migration_albums = array();

foreach ( array_keys( $site->albums ) as $album_id ) {
	$album = $repository->album( $album_id );

	if ( null !== $album && $album->count() > 0 ) {
		$pre_migration_albums[ $album_id ] = $renderer->album( $album, $repository );
	}
}

$before_plan = $migration->plan();

// The plan is only useful if it describes the site rather than a constant. Every count is
// compared against the fixture worked out independently of the migration's own queries.
$expected_defaults = 0;
$expected_real     = 0;

foreach ( $site->galleries as $record ) {
	$type = isset( $record['data']['config']['type'] ) ? $record['data']['config']['type'] : '';

	if ( 'defaults' === $type ) {
		$expected_defaults++;
	} else {
		$expected_real++;
	}
}

$checks->assert(
	'plan counts the rows that exist',
	count( $site->galleries ) === $before_plan['galleries']
		&& count( $site->albums ) === $before_plan['albums']
		&& count( $site->taxonomies ) === $before_plan['terms']
		&& false === $before_plan['migrated'],
	sprintf(
		'plan said %d/%d/%d, fixture holds %d/%d/%d',
		$before_plan['galleries'],
		$before_plan['albums'],
		$before_plan['terms'],
		count( $site->galleries ),
		count( $site->albums ),
		count( $site->taxonomies )
	)
);

// The three classes must partition the galleries: anything else means the confirmation
// screen would show a breakdown that does not add up to what it says it will move.
$checks->assert(
	'plan classifies every gallery',
	$before_plan['convertible'] + $before_plan['defaults'] + $before_plan['unreadable'] === $before_plan['galleries']
		&& $before_plan['defaults'] === $expected_defaults
		&& $before_plan['convertible'] === $expected_real,
	sprintf(
		'convertible=%d defaults=%d unreadable=%d, expected %d real and %d defaults',
		$before_plan['convertible'],
		$before_plan['defaults'],
		$before_plan['unreadable'],
		$expected_real,
		$expected_defaults
	)
);

$migrated = $migration->migrate();

$checks->assert(
	'migration moves every row',
	empty( $migrated['errors'] )
		&& count( $site->galleries ) === $migrated['galleries']
		&& count( $site->albums ) === $migrated['albums']
		&& count( $site->taxonomies ) === $migrated['terms'],
	sprintf(
		'moved %d/%d/%d with errors: %s',
		$migrated['galleries'],
		$migrated['albums'],
		$migrated['terms'],
		implode( '; ', $migrated['errors'] )
	)
);

$checks->assert(
	'migration converts every real gallery',
	$migrated['converted'] === $expected_real,
	$migrated['converted'] . ' converted, expected ' . $expected_real
);

// Build the reader the way the plugin does after a migration, and render it all again.
//
// The fourth argument is the whole point of this check and was missing until a mutation
// exposed it: without it the reader falls back to `_eg_gallery_data`, which the migration
// deliberately leaves in place — so all 51 galleries rendered identically for the reason
// that nothing had moved as far as the reader was concerned. Gutting `build_from_own()`
// left this check green. The conversion checks above cover that function directly; what
// nothing covered was that the *post-migration* reader is wired to prefer its own record.
$migrated_repository = new Lichtbild_Repository(
	Lichtbild_Post_Types::gallery_type( $settings ),
	Lichtbild_Post_Types::album_type( $settings ),
	Lichtbild_Post_Types::tag_taxonomy( $settings ),
	$settings->has_migrated()
);

foreach ( $pre_migration as $gallery_id => $before_markup ) {
	$gallery = $migrated_repository->gallery( $gallery_id );

	$checks->assert(
		'migrated gallery renders identically',
		null !== $gallery && $renderer->gallery( $gallery, 1 ) === $before_markup,
		null === $gallery
			? "#{$gallery_id} is unreachable after the migration"
			: "#{$gallery_id} renders differently after the migration"
	);
}

foreach ( $pre_migration_albums as $album_id => $before_markup ) {
	$album = $migrated_repository->album( $album_id );

	$checks->assert(
		'migrated album renders identically',
		null !== $album && $renderer->album( $album, $migrated_repository ) === $before_markup,
		null === $album
			? "#{$album_id} is unreachable after the migration"
			: "#{$album_id} renders differently after the migration"
	);
}

$checks->assert(
	'migration converts every real album',
	$migrated['albums_converted'] === count( $pre_migration_albums ),
	$migrated['albums_converted'] . ' converted, expected ' . count( $pre_migration_albums )
);

$seo_after = $site->options['wpseo_titles'];

$checks->assert(
	'the migration carries seo settings onto the new names',
	7 === $migrated['seo_keys']
		&& '%%term_title%% Archive %%sep%% %%sitename%%' === $seo_after['title-tax-' . Lichtbild_Post_Types::TAG ]
		&& '%%title%% %%sep%% %%sitename%%' === $seo_after['title-' . Lichtbild_Post_Types::GALLERY ]
		&& '%%title%% %%sep%% %%sitename%%' === $seo_after['title-' . Lichtbild_Post_Types::ALBUM ]
		&& false === $seo_after['noindex-' . Lichtbild_Post_Types::GALLERY ]
		&& 0 === $seo_after[ 'taxonomy-' . Lichtbild_Post_Types::TAG . '-ptparent' ]
		&& 0 === $seo_after[ 'post_types-' . Lichtbild_Post_Types::GALLERY . '-maintax' ],
	'reported ' . $migrated['seo_keys'] . ' keys; option now holds ' . count( $seo_after )
);

// The trap, asserted directly. `envira-category` is a taxonomy this plugin never registered, so
// its archive does not exist after the migration and a setting describing one is a lie in the
// database. Nothing keyed on `post` may move either.
// Asserted as the EXACT set of added keys, not as the absence of the particular wrong ones.
// Naming the keys a known mistake produced only catches that mistake: a mutation that invented
// `title-tax-lichtbild_envira-category` instead sailed through a check written that way, which is
// how this check was first written and why it is not any more.
$seo_added = array_keys( array_diff_key( $seo_after, $seo_before ) );
sort( $seo_added );

$seo_expected = array(
	'noindex-' . Lichtbild_Post_Types::GALLERY,
	'noindex-tax-' . Lichtbild_Post_Types::TAG,
	'post_types-' . Lichtbild_Post_Types::GALLERY . '-maintax',
	'taxonomy-' . Lichtbild_Post_Types::TAG . '-ptparent',
	'title-' . Lichtbild_Post_Types::ALBUM,
	'title-' . Lichtbild_Post_Types::GALLERY,
	'title-tax-' . Lichtbild_Post_Types::TAG,
);
sort( $seo_expected );

$checks->assert(
	'it never invents settings for types it does not register',
	$seo_added === $seo_expected
		// And nothing that was there before may move: `envira-category` is a taxonomy this
		// plugin never registered, so its archive is gone and a setting for one would be a lie.
		&& array_key_exists( 'title-tax-envira-category', $seo_after )
		&& '%%title%%' === $seo_after['title-post'],
	'added: ' . implode( ', ', $seo_added )
);

if ( $slash_gallery > 0 ) {
	$slash_record_after = get_post_meta( $slash_gallery, Lichtbild_Repository::GALLERY_META_V2, true );
	$slash_titles       = is_array( $slash_record_after ) && isset( $slash_record_after['items'] )
		? wp_list_pluck( $slash_record_after['items'], 'title' )
		: array();

	$checks->assert(
		'a backslash survives the migration',
		in_array( 'Backslash C:\\Photos test', $slash_titles, true ),
		'#' . $slash_gallery . ' stored ' . wp_json_encode( array_slice( $slash_titles, 0, 3 ) )
	);
}

// ============================================================================
// The editor.
//
// This runs where it does on purpose: the site has just been migrated for real, so the rows,
// the taxonomy and the schema option all say what they say in production. The editor writes
// v2 records and only a migrated site reads them, so exercising it anywhere else would prove
// nothing about whether a save reaches a visitor.
// ============================================================================

$editor = new Lichtbild_Editor( $settings, $migrated_repository );

$checks->expect(
	'a save with no nonce field changes nothing',
	'a nonce for another gallery is refused',
	'a save without the capability is refused',
	'a save aimed at another post type is refused',
	'an unmigrated site refuses to save',
	'a save round-trips the gallery byte for byte',
	'stored order follows the submitted order',
	'a row the order does not name is dropped',
	'a row named twice is stored once',
	'a row with nothing to show is dropped',
	'a dangerous url does not survive a save',
	'a caption keeps its markup and loses its script',
	'every setting survives a save',
	'an unchecked box switches its setting off',
	'an unknown choice falls back to the default',
	'a number out of range is clamped, not reset',
	'pagination without a page size is off',
	'a list setting keeps its canonical order',
	'the settings form has a field for every setting',
	'both item templates carry every record field',
	'the editor and the migration agree on the record shape',
	'tags submitted by the editor are stored',
	'a row with no tag field leaves tags alone',
	'tags are not written to an image the user cannot edit',
	'the tag guard is the only thing that stopped the write',
	'a non-string field is ignored rather than cast',
	'the media library carries an image\'s tags',
	'an unmigrated edit screen offers no fields',
	'metaboxes attach to the post type that exists',
	'the list column counts the stored images',
	'the list columns survive a table with no date'
);

/**
 * Runs one save against the editor.
 *
 * @param Lichtbild_Editor $editor  The editor.
 * @param int           $id      Gallery post ID.
 * @param array         $payload The `$_POST` to run it with.
 *
 * @return void
 */
$editor_save = function ( Lichtbild_Editor $editor, $id, array $payload ) {
	$_POST = $payload;
	$editor->save( $id );
	$_POST = array();
};

/**
 * Turns a stored settings array into what the settings form would submit.
 *
 * Booleans appear only when true, because that is what an unchecked checkbox does.
 *
 * @param array $settings Stored settings.
 *
 * @return array Form payload.
 */
$editor_settings_payload = function ( array $settings ) {
	$out = array();

	foreach ( $settings as $key => $value ) {
		if ( is_bool( $value ) ) {
			if ( $value ) {
				$out[ $key ] = '1';
			}

			continue;
		}

		$out[ $key ] = is_array( $value ) ? array_values( $value ) : (string) $value;
	}

	return $out;
};

/**
 * Turns a stored item list into what the images form would submit.
 *
 * @param array $items Stored items.
 *
 * @return array{items:array,order:string} Form payload.
 */
$editor_items_payload = function ( array $items ) {
	$rows  = array();
	$order = array();

	foreach ( $items as $index => $item ) {
		$key     = 'i' . $index;
		$order[] = $key;

		$rows[ $key ] = array(
			'id'      => (string) $item['id'],
			'status'  => $item['status'],
			'src'     => $item['src'],
			'link'    => $item['link'],
			'title'   => $item['title'],
			'caption' => $item['caption'],
			'alt'     => $item['alt'],
		);
	}

	return array(
		'items' => $rows,
		'order' => implode( ',', $order ),
	);
};

/**
 * Builds a complete, valid submission for one gallery from its stored record.
 *
 * @param int $id Gallery post ID.
 *
 * @return array The `$_POST` a faithful round trip would produce.
 */
$editor_payload = function ( $id ) use ( $site, $editor_items_payload, $editor_settings_payload ) {
	$record = $site->galleries[ $id ]['lichtbild'];
	$images = $editor_items_payload( $record['items'] );

	// `wp_slash()`, because that is the state `$_POST` is actually in when WordPress hands it to
	// a save hook -- it is why the save path unslashes at all. Submitting raw values models a
	// request that cannot happen and makes the save's `wp_unslash()` look like a bug: the first
	// piece of test data containing a backslash turned four unrelated checks red, none of them
	// pointing at the harness. A payload builder is part of the code under test.
	return wp_slash(
		array(
			Lichtbild_Editor::NONCE => wp_create_nonce( Lichtbild_Editor::NONCE_ACTION . $id ),
			'lichtbild_editor_nonce_items_complete' => '1',
			'lichtbild_editor_nonce_settings_complete' => '1',
			'lichtbild_items'       => $images['items'],
			'lichtbild_order'       => $images['order'],
			'lichtbild_settings'    => $editor_settings_payload( $record['settings'] ),
		)
	);
};

/**
 * Builds a reader the way the plugin builds one at the start of a request.
 *
 * A fresh one per read-back, and that is load-bearing rather than tidy: the repository
 * memoises each gallery for the life of the object, so reusing one after a save answers with
 * the object built before it and every check compares the record with itself.
 *
 * @return Lichtbild_Repository A reader wired the way the plugin wires one after a migration.
 */
$editor_reader = function () use ( $settings ) {
	return new Lichtbild_Repository(
		Lichtbild_Post_Types::gallery_type( $settings ),
		Lichtbild_Post_Types::album_type( $settings ),
		Lichtbild_Post_Types::tag_taxonomy( $settings ),
		$settings->has_migrated()
	);
};

/**
 * Reads a gallery back through a reader configured the way the plugin configures one.
 *
 * @param int $id Gallery post ID.
 *
 * @return string Rendered markup.
 */
$editor_render = function ( $id ) use ( $editor_reader, $renderer ) {
	$gallery = $editor_reader()->gallery( $id );

	return null === $gallery ? '' : $renderer->gallery( $gallery, 1 );
};

$editor_id     = array_key_first( $pre_migration );
$editor_before = $editor_render( $editor_id );

$site->capabilities = true;

// The destructive case, and the reason the nonce field is checked before anything else.
// `save_post` fires for quick edit, bulk edit, status changes and autosaves, none of which
// carry a single image — so a handler that got past this point would read "no items" as
// "the user removed every image" and empty the gallery on a quick edit of its title.
//
// The assertion is "ignored *silently*", and the adverb is what makes it capable of failing.
// Removing the presence check does not actually let such a request through — the nonce
// verification two statements later refuses an absent nonce as readily as a wrong one — so
// the property this check names is enforced twice and a mutation of the first survives. What
// only the first enforces is that an unrelated save produces no PHP warning, and `save_post`
// fires often enough that a warning per quick edit is a log nobody can read.
$editor_warnings = array();

set_error_handler(
	static function ( $number, $message ) use ( &$editor_warnings ) {
		$editor_warnings[] = $message;

		return true;
	}
);

$editor_save( $editor, $editor_id, array( 'post_title' => 'Renamed' ) );

restore_error_handler();

$checks->assert(
	'a save with no nonce field changes nothing',
	$editor_before === $editor_render( $editor_id ) && array() === $editor_warnings,
	'a request with no editor fields in it rewrote the gallery or complained: ' . implode( '; ', $editor_warnings )
);

// The nonce is bound to the post being edited, so one lifted from another gallery's form
// must not authorise a write here.
$other_nonce                        = $editor_payload( $editor_id );
$other_nonce[ Lichtbild_Editor::NONCE ] = wp_create_nonce( Lichtbild_Editor::NONCE_ACTION . ( $editor_id + 1 ) );
$other_nonce['lichtbild_order']        = '';

$editor_save( $editor, $editor_id, $other_nonce );

$checks->assert(
	'a nonce for another gallery is refused',
	$editor_before === $editor_render( $editor_id ),
	'a nonce made for a different post was accepted'
);

$site->capabilities = false;

$no_cap                  = $editor_payload( $editor_id );
$no_cap['lichtbild_order']  = '';

$editor_save( $editor, $editor_id, $no_cap );

$checks->assert(
	'a save without the capability is refused',
	$editor_before === $editor_render( $editor_id ),
	'a user without edit_post emptied the gallery'
);

$site->capabilities = true;

// An album is saved by the same `save_post`, and its rows live in the same table. A handler
// that did not check the type would write a gallery record onto one.
$album_id = array_key_first( $site->albums );

$wrong_type                        = $editor_payload( $editor_id );
$wrong_type[ Lichtbild_Editor::NONCE ] = wp_create_nonce( Lichtbild_Editor::NONCE_ACTION . $album_id );

$editor_save( $editor, $album_id, $wrong_type );

$checks->assert(
	'a save aimed at another post type is refused',
	! is_array( get_post_meta( $album_id, Lichtbild_Repository::GALLERY_META_V2, true ) ),
	'an album row was given a gallery record'
);

// And a v2 record written on an unmigrated site is a record nothing reads, so the editor
// declines rather than saving happily and appearing to change nothing.
//
// The row has to go back to Envira's post type as well as the flag, and that is the whole
// check rather than tidiness. Clearing the flag alone leaves a `lichtbild_gallery` row on a site
// whose `gallery_type()` now says `envira`, so the *post type* guard refuses first and this
// check passes without the migration guard existing at all — which is exactly what a mutation
// removing it demonstrated. An unmigrated site is one where both agree.
$unmigrated_record = $site->galleries[ $editor_id ]['lichtbild'];

// Schema 1, not an absent option. Since 26.8.25 an absent schema on a site that still holds
// `lichtbild_gallery` rows means "uninstalled and reinstalled" and is repaired back to 2, so
// unsetting it here stopped producing an unmigrated site: `has_migrated()` stayed true, the
// post-type guard did all the refusing, and mutation E5 survived because the guard it removes
// was never the one being exercised. That is the failure the paragraph above warns about,
// arriving from the other direction.
$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = 1;
$site->posts[ $editor_id ]['post_type'] = Lichtbild_Repository::GALLERY_POST_TYPE;

$unmigrated                 = $editor_payload( $editor_id );
$unmigrated['lichtbild_order'] = '';

$editor_save( $editor, $editor_id, $unmigrated );

$site->posts[ $editor_id ]['post_type']          = Lichtbild_Post_Types::GALLERY;
$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = Lichtbild_Settings::SCHEMA_MIGRATED;

$checks->assert(
	'an unmigrated site refuses to save',
	$unmigrated_record === $site->galleries[ $editor_id ]['lichtbild']
		&& $editor_before === $editor_render( $editor_id ),
	'an unmigrated site wrote a record anyway'
);

// The equivalence property, applied to the editor: loading a gallery into the form and
// saving it without touching anything must produce the same page. That covers the settings
// sanitiser, the item sanitiser and the ordering in one assertion, and it is capable of
// failing on any field the form drops.
foreach ( array_keys( $pre_migration ) as $round_trip_id ) {
	// A gallery the migration failed to convert has no record for the editor to load, so this
	// would die on a null rather than report anything. Skipped rather than guessed at: the
	// conversion count and the equivalence checks above are what report that failure, and these
	// checks are declared with `expect()`, so skipping every one of them is reported [EMPTY]
	// and fails instead of quietly examining nothing.
	if ( ! isset( $site->galleries[ $round_trip_id ]['lichtbild'] ) ) {
		continue;
	}

	$before = $editor_render( $round_trip_id );

	$editor_save( $editor, $round_trip_id, $editor_payload( $round_trip_id ) );

	// `'' !== $before` is the control, not defensive padding. `$editor_render()` answers ''
	// for a gallery the reader cannot find, so two absent renders compare equal and this
	// check passes without a gallery having been rendered at all -- which is exactly what
	// mutation B93 produced, by making the reader return null for every migrated record.
	// Requiring the before-render to be non-empty is enough: if it is, an equal after-render
	// is too.
	$checks->assert(
		'a save round-trips the gallery byte for byte',
		'' !== $before && $before === $editor_render( $round_trip_id ),
		'' === $before
			? "#{$round_trip_id} rendered nothing before the save, so nothing round-tripped"
			: "#{$round_trip_id} renders differently after being saved unchanged"
	);
}

// Order is submitted explicitly rather than inferred from the order of the fields, so the
// check submits the two in *opposite* orders. A handler that iterated the items map would
// pass a same-order test and fail this one.
$order_record = $site->galleries[ $editor_id ]['lichtbild'];

if ( count( $order_record['items'] ) > 1 ) {
	$reversed          = $editor_payload( $editor_id );
	$reversed['lichtbild_order'] = implode( ',', array_reverse( explode( ',', $reversed['lichtbild_order'] ) ) );

	$editor_save( $editor, $editor_id, $reversed );

	$stored_ids = wp_list_pluck( $site->galleries[ $editor_id ]['lichtbild']['items'], 'id' );
	$wanted_ids = array_reverse( wp_list_pluck( $order_record['items'], 'id' ) );

	$checks->assert(
		'stored order follows the submitted order',
		$stored_ids === $wanted_ids,
		'stored ' . implode( ',', array_slice( $stored_ids, 0, 5 ) ) . ' wanted ' . implode( ',', array_slice( $wanted_ids, 0, 5 ) )
	);

	// Each of these starts from the gallery as it was, because the previous one deliberately
	// changed it — comparing a count against a record two saves old measures the wrong thing.
	$site->galleries[ $editor_id ]['lichtbild'] = $order_record;

	// A row the order list does not name is a row the browser left behind, and dropping it is
	// how removal works — so it has to be the order that decides, not the presence of fields.
	$dropped                 = $editor_payload( $editor_id );
	$dropped_keys            = explode( ',', $dropped['lichtbild_order'] );
	$kept_key                = array_shift( $dropped_keys );
	$dropped['lichtbild_order'] = implode( ',', $dropped_keys );

	$editor_save( $editor, $editor_id, $dropped );

	$checks->assert(
		'a row the order does not name is dropped',
		count( $site->galleries[ $editor_id ]['lichtbild']['items'] ) === count( $order_record['items'] ) - 1,
		'dropping ' . $kept_key . ' left ' . count( $site->galleries[ $editor_id ]['lichtbild']['items'] ) . ' items'
	);

	$site->galleries[ $editor_id ]['lichtbild'] = $order_record;

	// A duplicated key is a malformed submission, not an instruction to show the image twice:
	// the two rows are the same fields, so storing both would silently double an image.
	$twice                 = $editor_payload( $editor_id );
	$twice_keys            = explode( ',', $twice['lichtbild_order'] );
	$twice['lichtbild_order'] = implode( ',', array_merge( $twice_keys, array( $twice_keys[0] ) ) );

	$editor_save( $editor, $editor_id, $twice );

	$checks->assert(
		'a row named twice is stored once',
		count( $site->galleries[ $editor_id ]['lichtbild']['items'] ) === count( $order_record['items'] ),
		'a duplicated key stored ' . count( $site->galleries[ $editor_id ]['lichtbild']['items'] ) . ' items'
	);
}

// Put it back, so everything after this reads the gallery it started with.
$editor_save( $editor, $editor_id, $editor_payload( $editor_id ) );
$site->galleries[ $editor_id ]['lichtbild'] = $order_record;

// A row naming neither an attachment nor a URL cannot produce an image, so it is dropped
// rather than stored as an empty box.
$junk = array(
	Lichtbild_Editor::NONCE => wp_create_nonce( Lichtbild_Editor::NONCE_ACTION . $editor_id ),
	'lichtbild_editor_nonce_items_complete' => '1',
	'lichtbild_editor_nonce_settings_complete' => '1',
	'lichtbild_order'       => 'a,b,c',
	'lichtbild_settings'    => array(),
	'lichtbild_items'       => array(
		'a' => array( 'id' => '0', 'src' => '', 'title' => 'nothing' ),
		'b' => array( 'id' => '0', 'src' => 'javascript:alert(1)', 'link' => 'javascript:alert(1)' ),
		'c' => array(
			'id'      => '0',
			'src'     => 'https://example.com/real.jpg',
			'caption' => '<em>kept</em><script>alert(1)</script>',
		),
	),
);

$editor_save( $editor, $editor_id, $junk );

$junk_items = $site->galleries[ $editor_id ]['lichtbild']['items'];

$checks->assert(
	'a row with nothing to show is dropped',
	1 === count( $junk_items ),
	count( $junk_items ) . ' rows survived, expected 1'
);

$checks->assert(
	'a dangerous url does not survive a save',
	1 === count( $junk_items ) && 'https://example.com/real.jpg' === $junk_items[0]['src'],
	'stored src: ' . ( isset( $junk_items[0]['src'] ) ? $junk_items[0]['src'] : '(none)' )
);

$checks->assert(
	'a caption keeps its markup and loses its script',
	1 === count( $junk_items )
		&& false !== strpos( $junk_items[0]['caption'], '<em>' )
		&& false === stripos( $junk_items[0]['caption'], '<script' ),
	'stored caption: ' . ( isset( $junk_items[0]['caption'] ) ? $junk_items[0]['caption'] : '(none)' )
);

$site->galleries[ $editor_id ]['lichtbild'] = $order_record;

// ---------------------------------------------------------------------------
// The settings sanitiser.
// ---------------------------------------------------------------------------

$sanitised = Lichtbild_Config::sanitize( $editor_settings_payload( Lichtbild_Config::defaults() ) );

foreach ( array_keys( Lichtbild_Config::defaults() ) as $setting_key ) {
	$checks->assert(
		'every setting survives a save',
		array_key_exists( $setting_key, $sanitised ),
		$setting_key . ' is in the schema but not in what sanitize() returns'
	);
}

// A checkbox that is off sends nothing, so absence has to read as false. Written with the
// stored-record rule — absent means "use the default" — a box whose default is true could
// never be switched off, which is the only interesting direction.
$unchecked = Lichtbild_Config::sanitize( array( 'per_page' => '10', 'pagination' => '1' ) );

$checks->assert(
	'an unchecked box switches its setting off',
	true === Lichtbild_Config::defaults()['keyboard']
		&& false === $unchecked['keyboard']
		&& false === $unchecked['lazy_loading'],
	'keyboard came back as ' . wp_json_encode( $unchecked['keyboard'] )
);

$checks->assert(
	'an unknown choice falls back to the default',
	'justified' === Lichtbild_Config::sanitize( array( 'layout' => 'spiral' ) )['layout']
		&& 'medium_large' === Lichtbild_Config::sanitize( array( 'image_size' => 'no_such_size' ) )['image_size'],
	'an unknown choice was stored'
);

// Clamped rather than reset: a row height of 40000 is a typo, and reverting to the default
// leaves the editor showing a number nobody typed.
$clamped = Lichtbild_Config::sanitize( array( 'row_height' => '40000', 'columns' => '-3' ) );

$checks->assert(
	'a number out of range is clamped, not reset',
	800 === $clamped['row_height'] && 1 === $clamped['columns'],
	'row_height ' . $clamped['row_height'] . ', columns ' . $clamped['columns']
);

$checks->assert(
	'pagination without a page size is off',
	false === Lichtbild_Config::sanitize( array( 'pagination' => '1', 'per_page' => '0' ) )['pagination'],
	'pagination survived a page size of zero'
);

// Ordered by the allowlist rather than by the submission, so two galleries with the same
// fields ticked produce the same record whatever order the browser sent them in.
$listed = Lichtbild_Config::sanitize(
	array(
		'exif_fields'     => array( 'iso', 'nonsense', 'make' ),
		'social_networks' => array( 'email', 'facebook' ),
	)
);

$checks->assert(
	'a list setting keeps its canonical order',
	array( 'make', 'iso' ) === $listed['exif_fields']
		&& array( 'facebook', 'email' ) === $listed['social_networks'],
	'exif ' . wp_json_encode( $listed['exif_fields'] ) . ' social ' . wp_json_encode( $listed['social_networks'] )
);

// ---------------------------------------------------------------------------
// Drift between the schema, the form and the record shape.
//
// These are the checks that exist because nothing else would notice: a setting added to
// `defaults()` and forgotten in the form saves as its default forever, and a field added to
// the item record and forgotten in one of the two templates silently stops surviving a save.
// ---------------------------------------------------------------------------

ob_start();
$editor->render_settings_box( (object) array( 'ID' => $editor_id ) );
$settings_form = (string) ob_get_clean();

foreach ( array_keys( Lichtbild_Config::defaults() ) as $setting_key ) {
	$checks->assert(
		'the settings form has a field for every setting',
		false !== strpos( $settings_form, 'name="lichtbild_settings[' . $setting_key . ']' ),
		$setting_key . ' has no field on the settings form'
	);
}

// The drift check above runs over the settings that EXIST, so it is structurally incapable of
// noticing a field for one that does not -- put `custom_css` back on the form alone and it stays
// green. This is the other direction, and it is the one the wordpress.org guidelines care about:
// the plugin must offer no way to type CSS into it. A `<textarea>` anywhere on this form is
// treated as that way back, because a free-text control is what the guideline is about rather
// than the particular field name it once had.
$checks->assert(
	'the settings form offers no custom css field',
	false === strpos( $settings_form, 'lichtbild_settings[custom_css]' )
		&& false === stripos( $settings_form, '<textarea' ),
	'the settings form still carries a free-text CSS control'
);

$site->primed = array();

ob_start();
$editor->render_images_box( (object) array( 'ID' => $editor_id ) );
$images_form = (string) ob_get_clean();

// Priming changes no byte of the form, so only the shape of the call can be asserted: one call
// naming every attachment the record carries, rather than none and rather than one per row.
// The album editor's twin already primed; this screen was the last reader of a whole gallery
// that did not, at three queries per image.
$checks->expect( 'the gallery editor primes its attachments in one call' );

$editor_stored = get_post_meta( $editor_id, Lichtbild_Repository::GALLERY_META_V2, true );
$editor_wanted = array();

foreach ( ( is_array( $editor_stored ) && isset( $editor_stored['items'] ) ? $editor_stored['items'] : array() ) as $stored_item ) {
	if ( is_array( $stored_item ) && ! empty( $stored_item['id'] ) ) {
		$editor_wanted[ (int) $stored_item['id'] ] = (int) $stored_item['id'];
	}
}

$editor_primed = empty( $site->primed ) ? array() : array_map( 'intval', $site->primed[0]['ids'] );
sort( $editor_wanted );
sort( $editor_primed );

$checks->assert(
	'the gallery editor primes its attachments in one call',
	1 === count( $site->primed ) && count( $editor_wanted ) > 1 && $editor_primed === array_values( $editor_wanted ),
	sprintf(
		'record has %d attachments; priming calls: %d, ids in the first: %d',
		count( $editor_wanted ),
		count( $site->primed ),
		count( $editor_primed )
	)
);

$site->primed = array();

foreach ( Lichtbild_Item::record_keys() as $record_key ) {
	$server_side = false !== strpos( $images_form, '][' . $record_key . ']' );
	$client_side = false !== strpos( $images_form, '{{ data.key }}][' . $record_key . ']' );

	$checks->assert(
		'both item templates carry every record field',
		$server_side && $client_side,
		$record_key . ' is missing from the ' . ( $server_side ? 'client' : 'server' ) . ' template'
	);
}

// The record shape is asserted in two places — the migration's converter and the editor's
// sanitiser — and a field added to one and not the other is a field that survives a
// migration and then vanishes the first time someone saves.
$shape_source = array(
	'config'  => array(),
	'gallery' => array( 7 => array( 'status' => 'active', 'src' => 'https://example.com/a.jpg' ) ),
);

// Indexed unconditionally until now, which fatals the suite rather than failing this check when
// a mutation makes the converter produce no items at all — the same harness defect as the
// unguarded renderer calls above, and it hides the same class of mutation.
$shape_record = Lichtbild_Migration::build_record( $shape_source, $editor_id );

if ( $checks->assert( 'the converter emits an item for a gallery that has one', ! empty( $shape_record['items'] ), 'converted record carries no items' ) ) {
	$from_migration = $shape_record['items'][0];
	$from_editor    = Lichtbild_Item::sanitize_record( array( 'id' => 7, 'src' => 'https://example.com/a.jpg' ) );

	$checks->assert(
		'the editor and the migration agree on the record shape',
		array_keys( $from_migration ) === Lichtbild_Item::record_keys()
			&& array_keys( $from_editor ) === Lichtbild_Item::record_keys(),
		'migration ' . implode( ',', array_keys( $from_migration ) ) . ' / editor ' . implode( ',', array_keys( $from_editor ) )
	);
}

// ---------------------------------------------------------------------------
// Image tags.
// ---------------------------------------------------------------------------

$tag_item = null;

foreach ( $order_record['items'] as $candidate ) {
	if ( $candidate['id'] > 0 ) {
		$tag_item = $candidate;

		break;
	}
}

if ( null !== $tag_item ) {
	// Tags live on the attachment, not on the gallery, so writing one here changes what every
	// gallery holding that image renders — including the pre-migration markup the rollback
	// checks below compare against. Restoring the record is not enough; the terms have to go
	// back too, and forgetting that failed two checks a hundred lines away from the cause.
	$tag_backup = isset( $site->attachments[ $tag_item['id'] ]['tags'] )
		? $site->attachments[ $tag_item['id'] ]['tags']
		: null;

	$tag_payload = $editor_payload( $editor_id );
	$tag_key     = array_key_first( $tag_payload['lichtbild_items'] );

	foreach ( $tag_payload['lichtbild_items'] as $key => $row ) {
		if ( (int) $row['id'] === $tag_item['id'] ) {
			$tag_key = $key;

			break;
		}
	}

	$tag_payload['lichtbild_items'][ $tag_key ]['tags'] = 'Leipzig, Zoo';

	$editor_save( $editor, $editor_id, $tag_payload );

	$stored_tags = get_the_terms( $tag_item['id'], Lichtbild_Post_Types::tag_taxonomy( $settings ) );

	$checks->assert(
		'tags submitted by the editor are stored',
		is_array( $stored_tags ) && array( 'Leipzig', 'Zoo' ) === wp_list_pluck( $stored_tags, 'name' ),
		'stored: ' . wp_json_encode( is_array( $stored_tags ) ? wp_list_pluck( $stored_tags, 'name' ) : $stored_tags )
	);

	// A row with no tag field at all must leave them alone. This is the shape a row added
	// from the media library would have if the picker did not know the image's tags, and
	// getting it wrong clears them everywhere that image appears, not only here.
	//
	// The absence of a warning is asserted for the same reason as on the nonce guard, and it
	// became necessary for the same reason: the property is now enforced twice. A row that
	// submitted no tags reads back as null, which the tag writer's own string check refuses
	// anyway -- so removing the key check leaves the tags alone and announces itself only as
	// "Undefined array key" on every save of every gallery.
	$no_tag_field = $editor_payload( $editor_id );
	unset( $no_tag_field['lichtbild_items'][ $tag_key ]['tags'] );

	$no_tag_warnings = array();

	set_error_handler(
		static function ( $number, $message ) use ( &$no_tag_warnings ) {
			$no_tag_warnings[] = $message;

			return true;
		}
	);

	$editor_save( $editor, $editor_id, $no_tag_field );

	restore_error_handler();

	$after_tags = get_the_terms( $tag_item['id'], Lichtbild_Post_Types::tag_taxonomy( $settings ) );

	$checks->assert(
		'a row with no tag field leaves tags alone',
		is_array( $after_tags ) && array( 'Leipzig', 'Zoo' ) === wp_list_pluck( $after_tags, 'name' )
			&& array() === $no_tag_warnings,
		'tags became: ' . wp_json_encode( is_array( $after_tags ) ? wp_list_pluck( $after_tags, 'name' ) : $after_tags ) .
			'; warnings: ' . implode( '; ', $no_tag_warnings )
	);

	// Tags are written to the *image*, and they are shared, so the right to write them is a
	// question about the attachment rather than about the gallery the form was submitted from.
	// The user here may edit the gallery — the save has to go through, or this would pass on
	// the gallery's capability check instead of on the one being tested — and may not edit this
	// one image. `wp_set_object_terms()` checks nothing itself and invents any term it is given
	// a name for, so without the guard this retags the media library on a gallery edit.
	$site->capability_overrides[ 'edit_post:' . $tag_item['id'] ] = false;

	$foreign_tags = $editor_payload( $editor_id );

	// Names chosen so submission order and alphabetical order agree, so the assertion below
	// cannot fail over an ordering neither the guard nor this check has any opinion about.
	$foreign_tags['lichtbild_items'][ $tag_key ]['tags'] = 'Elbe, Hamburg';

	$editor_save( $editor, $editor_id, $foreign_tags );

	$guarded_tags = get_the_terms( $tag_item['id'], Lichtbild_Post_Types::tag_taxonomy( $settings ) );

	$site->capability_overrides = array();

	$checks->assert(
		'tags are not written to an image the user cannot edit',
		is_array( $guarded_tags ) && array( 'Leipzig', 'Zoo' ) === wp_list_pluck( $guarded_tags, 'name' ),
		'tags became: ' . wp_json_encode( is_array( $guarded_tags ) ? wp_list_pluck( $guarded_tags, 'name' ) : $guarded_tags )
	);

	// The control, and it is not optional: the assertion above is equally satisfied by a save
	// that never ran at all. Same payload, same user, capability restored — the tags must move.
	$editor_save( $editor, $editor_id, $foreign_tags );

	$allowed_tags = get_the_terms( $tag_item['id'], Lichtbild_Post_Types::tag_taxonomy( $settings ) );

	$checks->assert(
		'the tag guard is the only thing that stopped the write',
		is_array( $allowed_tags ) && array( 'Elbe', 'Hamburg' ) === wp_list_pluck( $allowed_tags, 'name' ),
		'tags became: ' . wp_json_encode( is_array( $allowed_tags ) ? wp_list_pluck( $allowed_tags, 'name' ) : $allowed_tags )
	);

	// Put the fixture back, so later checks see the tags they were written for.
	$restore_tags = $editor_payload( $editor_id );

	$restore_tags['lichtbild_items'][ $tag_key ]['tags'] = 'Leipzig, Zoo';

	$editor_save( $editor, $editor_id, $restore_tags );

	// A form field is a string or it is absent -- except that `lichtbild_items[i0][tags][]` is an
	// array, and nothing stops a request carrying one. Cast, each of these becomes the literal
	// word "Array": stored as the title and the caption, and written to the taxonomy as a tag on
	// an image every gallery holding it shares -- with a PHP warning per field on the way. The
	// row still saves; a field submitted as something that is not text is a field the row has
	// said nothing about, which for tags is the same "leave them alone" the check above asserts.
	$array_fields                                        = $editor_payload( $editor_id );
	$array_fields['lichtbild_items'][ $tag_key ]['tags']    = wp_slash( array( 'Elbe' ) );
	$array_fields['lichtbild_items'][ $tag_key ]['title']   = wp_slash( array( 'Elbe' ) );
	$array_fields['lichtbild_items'][ $tag_key ]['caption'] = wp_slash( array( 'Elbe' ) );

	$array_warnings = array();

	set_error_handler(
		static function ( $number, $message ) use ( &$array_warnings ) {
			$array_warnings[] = $message;

			return true;
		}
	);

	$editor_save( $editor, $editor_id, $array_fields );

	restore_error_handler();

	$array_tags   = get_the_terms( $tag_item['id'], Lichtbild_Post_Types::tag_taxonomy( $settings ) );
	$array_stored = array();

	foreach ( $site->galleries[ $editor_id ]['lichtbild']['items'] as $stored_row ) {
		if ( (int) $stored_row['id'] === $tag_item['id'] ) {
			$array_stored = $stored_row;
		}
	}

	$checks->assert(
		'a non-string field is ignored rather than cast',
		array() === $array_warnings
			&& is_array( $array_tags )
			&& array( 'Leipzig', 'Zoo' ) === wp_list_pluck( $array_tags, 'name' )
			&& isset( $array_stored['title'] ) && '' === $array_stored['title']
			&& isset( $array_stored['caption'] ) && '' === $array_stored['caption'],
		'warnings: ' . implode( '; ', $array_warnings ) . '; stored ' . wp_json_encode( $array_stored ) .
			'; tags ' . wp_json_encode( is_array( $array_tags ) ? wp_list_pluck( $array_tags, 'name' ) : $array_tags )
	);

	// That save deliberately submitted a broken row, so the record goes back before anything
	// reads it again.
	$site->galleries[ $editor_id ]['lichtbild'] = $order_record;

	// Which is why the media library is told the tags in the first place.
	$_REQUEST['post_id'] = $editor_id;
	$prepared            = $editor->attachment_tags( array(), (object) array( 'ID' => $tag_item['id'] ) );

	$_REQUEST['post_id'] = 0;
	$unrelated           = $editor->attachment_tags( array(), (object) array( 'ID' => $tag_item['id'] ) );

	unset( $_REQUEST['post_id'] );

	$checks->assert(
		'the media library carries an image\'s tags',
		'Leipzig, Zoo' === ( isset( $prepared['lichtbildTags'] ) ? $prepared['lichtbildTags'] : '' )
			&& ! isset( $unrelated['lichtbildTags'] ),
		'prepared: ' . wp_json_encode( $prepared ) . ' unrelated: ' . wp_json_encode( $unrelated )
	);

	if ( null === $tag_backup ) {
		unset( $site->attachments[ $tag_item['id'] ]['tags'] );
	} else {
		$site->attachments[ $tag_item['id'] ]['tags'] = $tag_backup;
	}
}

$site->galleries[ $editor_id ]['lichtbild'] = $order_record;

// ---------------------------------------------------------------------------
// The edit screen itself.
// ---------------------------------------------------------------------------

// Schema 1 explicitly, rather than deleting the option. On a fixture the suite has already
// migrated, an ABSENT schema no longer means "unmigrated" -- it is the uninstall/reinstall
// state, and `initialise()` now correctly repairs it back to 2 from the rows themselves.
// Deleting it here would therefore assert the defect this release fixed.
$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = 1;

ob_start();
$editor->render_images_box( (object) array( 'ID' => $editor_id ) );
$unmigrated_form = (string) ob_get_clean();

$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = Lichtbild_Settings::SCHEMA_MIGRATED;

$checks->assert(
	'an unmigrated edit screen offers no fields',
	false === strpos( $unmigrated_form, 'lichtbild_items[' )
		&& false === strpos( $unmigrated_form, 'name="' . Lichtbild_Editor::NONCE . '"' )
		&& false !== strpos( $unmigrated_form, 'options-general.php?page=lichtbild' ),
	'the unmigrated screen rendered ' . strlen( $unmigrated_form ) . ' bytes of form'
);

// The post type changes at migration, so a metabox nailed to one name attaches to a screen
// that does not exist on the other side of it.
$site->meta_boxes = array();
$editor->add_meta_boxes();

$checks->assert(
	'metaboxes attach to the post type that exists',
	isset( $site->meta_boxes['lichtbild-images'] )
		&& Lichtbild_Post_Types::GALLERY === $site->meta_boxes['lichtbild-images']['screen']
		&& isset( $site->meta_boxes['lichtbild-settings'] )
		&& isset( $site->meta_boxes['lichtbild-shortcode'] ),
	'registered: ' . wp_json_encode( $site->meta_boxes )
);

// Ours go in before the date column. Any plugin may remove that column, and the impossibility
// is therefore enforced in someone else's code rather than in ours — so the fallback stays,
// and stays checked.
$with_date = $editor->columns( array( 'title' => 'Title', 'date' => 'Date' ) );
$no_date   = $editor->columns( array( 'title' => 'Title' ) );

$checks->assert(
	'the list columns survive a table with no date',
	array( 'title', 'lichtbild_images', 'lichtbild_shortcode', 'date' ) === array_keys( $with_date )
		&& array( 'title', 'lichtbild_images', 'lichtbild_shortcode' ) === array_keys( $no_date ),
	'with date: ' . implode( ',', array_keys( $with_date ) ) . ' / without: ' . implode( ',', array_keys( $no_date ) )
);

// What the column has to report is what a visitor would count on the page, which is not the
// same as the number of rows in the record: a `pending` row is stored and never displayed, and
// so is one the reader drops. Counted here rather than asked of the reader, so the check is not
// the column agreeing with the object it is built on.
$column_expected = 0;

foreach ( $order_record['items'] as $stored_item ) {
	if ( 'pending' !== $stored_item['status'] ) {
		$column_expected++;
	}
}

// A reader of its own, not the one built at the top of the file: that one has been memoising
// this gallery since before the editor saves above, and a column check that reads through a
// stale memo is reporting on a record two hundred lines old.
ob_start();
( new Lichtbild_Editor( $settings, $editor_reader() ) )->column( 'lichtbild_images', $editor_id );
$column = (string) ob_get_clean();

$checks->assert(
	'the list column counts the stored images',
	(string) $column_expected === $column,
	'the column said "' . $column . '" for ' . $column_expected . ' displayable items'
);

// And on an un-migrated site, which is where this plugin spent its whole life before the
// migration and where a rollback puts it back. The authoritative record there is Envira's, so a
// column reading `_lichtbild_gallery` directly reports 0 for every gallery -- visibly wrong on the
// one screen that summarises them, and the exact defect its album twin was fixed for in 26.8.5.
//
// The v2 record is removed for the duration, and that is the check rather than tidiness: a
// migrate-then-rollback leaves the converted record behind deliberately, so counting it gives
// the right answer by accident and passes whether or not the column consults the right source.
$gallery_column_record = $site->galleries[ $editor_id ]['lichtbild'];

unset( $site->galleries[ $editor_id ]['lichtbild'] );
// Schema 1, not an absent option. Since 26.8.25 an absent schema on a site that still holds
// `lichtbild_gallery` rows means "uninstalled and reinstalled" and is repaired back to 2, so
// unsetting it here stopped producing an unmigrated site: `has_migrated()` stayed true, the
// post-type guard did all the refusing, and mutation E5 survived because the guard it removes
// was never the one being exercised. That is the failure the paragraph above warns about,
// arriving from the other direction.
$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = 1;
$site->posts[ $editor_id ]['post_type'] = Lichtbild_Repository::GALLERY_POST_TYPE;

ob_start();
( new Lichtbild_Editor( new Lichtbild_Settings(), new Lichtbild_Repository() ) )->column( 'lichtbild_images', $editor_id );
$unmigrated_gallery_column = (string) ob_get_clean();

$site->posts[ $editor_id ]['post_type']          = Lichtbild_Post_Types::GALLERY;
$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = Lichtbild_Settings::SCHEMA_MIGRATED;
$site->galleries[ $editor_id ]['lichtbild']         = $gallery_column_record;

$checks->assert(
	'the list column counts the stored images',
	(string) $column_expected === $unmigrated_gallery_column,
	'an un-migrated site\'s column said "' . $unmigrated_gallery_column . '" for ' .
		$column_expected . ' displayable items'
);

// ============================================================================
// The album editor.
//
// Here for the same reason the gallery editor is here: it writes v2 records, and only a
// migrated site reads them. Two things it has that the gallery editor does not need are what
// most of these checks are about -- a member has to resolve to a gallery, and a cover has to
// be one of that gallery's own images.
// ============================================================================

$album_editor = new Lichtbild_Album_Editor( $settings, $migrated_repository );

$checks->expect(
	'an album save with no nonce changes nothing',
	'an unmigrated site refuses to save an album',
	'an album save round-trips byte for byte',
	'stored album order follows the submitted order',
	'a member that is not a gallery is dropped',
	'a cover outside its gallery is refused',
	'a cover inside its gallery is kept',
	'album settings survive a save',
	'an unchecked album box switches its setting off',
	'the album screen offers every real gallery',
	'both album row templates carry every record field',
	'an unmigrated album screen offers no fields',
	'album metaboxes attach to the post type that exists',
	'the album list column counts its galleries',
	'the album list columns survive a table with no date',
	'the cover endpoint refuses a missing or wrong nonce',
	'the cover endpoint refuses someone who cannot edit the album',
	'the cover endpoint refuses someone who cannot edit the gallery',
	'the cover endpoint refuses an album id that is not an album',
	'the cover endpoint answers with the gallery\'s own images',
	'a backslash in a caption survives the save',
	'a repeated member keeps its own cover and caption'
);

/**
 * Runs one save against the album editor.
 *
 * @param Lichtbild_Album_Editor $editor  The editor.
 * @param int                 $id      Album post ID.
 * @param array               $payload The `$_POST` to run it with.
 *
 * @return void
 */
$album_save = function ( Lichtbild_Album_Editor $editor, $id, array $payload ) {
	$_POST = $payload;
	$editor->save( $id );
	$_POST = array();
};

/**
 * Builds a reader the way the plugin does at the start of a request.
 *
 * A fresh one per read-back, and that is load-bearing rather than tidy: `Lichtbild_Repository`
 * memoises each album for the life of the object, so reusing one after a save returns the
 * object built before it. Every check below would then compare the record with itself and
 * pass -- which is the direction nobody re-reads.
 *
 * @return Lichtbild_Repository A reader wired the way the plugin wires one after a migration.
 */
$album_reader = function () use ( $settings ) {
	return new Lichtbild_Repository(
		Lichtbild_Post_Types::gallery_type( $settings ),
		Lichtbild_Post_Types::album_type( $settings ),
		Lichtbild_Post_Types::tag_taxonomy( $settings ),
		$settings->has_migrated()
	);
};

/**
 * Builds a complete, valid submission for one album from its stored record.
 *
 * @param array $record Stored `_lichtbild_album` record.
 * @param int   $id     Album post ID.
 *
 * @return array Form payload.
 */
$album_payload = function ( array $record, $id ) {
	$rows     = array();
	$order    = array();
	$settings = array();

	foreach ( $record['items'] as $index => $item ) {
		$key     = 'i' . $index;
		$order[] = $key;

		$rows[ $key ] = array(
			'id'       => (string) $item['id'],
			'cover_id' => (string) $item['cover_id'],
			'caption'  => (string) $item['caption'],
		);
	}

	foreach ( $record['settings'] as $key => $value ) {
		if ( is_bool( $value ) ) {
			// Only when true: an unchecked checkbox submits nothing at all.
			if ( $value ) {
				$settings[ $key ] = '1';
			}

			continue;
		}

		$settings[ $key ] = (string) $value;
	}

	// Slashed for the same reason as the gallery payload above: this is the state WordPress
	// delivers `$_POST` in, and the save path's `wp_unslash()` is written for it.
	return wp_slash(
		array(
			Lichtbild_Album_Editor::NONCE => wp_create_nonce( Lichtbild_Album_Editor::NONCE_ACTION . $id ),
			'lichtbild_album_editor_nonce_items_complete' => '1',
			'lichtbild_album_editor_nonce_settings_complete' => '1',
			'lichtbild_album_items'       => $rows,
			'lichtbild_album_order'       => implode( ',', $order ),
			'lichtbild_album_settings'    => $settings,
		)
	);
};

// The album to work on is the first real one the migration converted, and its record is read
// back from the meta the migration wrote -- not rebuilt here, so the editor is exercised
// against exactly what production would hand it.
$album_edit_id = 0;

foreach ( array_keys( $pre_migration_albums ) as $candidate_id ) {
	$candidate_record = get_post_meta( $candidate_id, Lichtbild_Repository::ALBUM_META_V2, true );

	if ( is_array( $candidate_record ) && ! empty( $candidate_record['items'] ) ) {
		$album_edit_id = (int) $candidate_id;

		break;
	}
}

// Declared at top level, outside every conditional below, because these three checks sit inside
// two nested ones -- `if ( $album_edit_id > 0 )` and the search for a gallery outside the album.
// A check that stops running VANISHES from the report rather than failing, which reads as "not
// applicable" and is indistinguishable from coverage lapsing. Four mutations demonstrated that
// while this declaration was one level too deep: they reported 240 checks against a baseline of
// 243 and killed nothing.
$checks->expect(
	'a gallery the person cannot edit is not stored as a member',
	'the gallery picker omits what the person cannot edit',
	'a stored member the person cannot edit is not drawn'
);

if ( $album_edit_id > 0 ) {
	$album_record = get_post_meta( $album_edit_id, Lichtbild_Repository::ALBUM_META_V2, true );
	$album_before = lichtbild_render_album_found( $checks, $renderer, $album_reader()->album( $album_edit_id ), $album_reader(), "album #{$album_edit_id} unreadable through the editing reader" );

	$album_caps                                        = $site->capabilities;
	$site->capabilities                                = true;
	$site->options[ Lichtbild_Settings::OPTION_SCHEMA ]    = Lichtbild_Settings::SCHEMA_MIGRATED;

	// --- the guards ---------------------------------------------------------------------

	// `save_post` fires for quick edit, bulk edit and status changes, none of which carry the
	// members. The nonce *field* is the marker that this is our form at all -- and, exactly as
	// on the gallery side, removing this guard alone does not let such a request through,
	// because the verification below refuses an absent nonce too. What only this guard
	// prevents is a PHP warning on every one of those saves, so that is asserted as well.
	$album_warnings = array();

	set_error_handler(
		function ( $errno, $message ) use ( &$album_warnings ) {
			$album_warnings[] = $message;

			return true;
		}
	);

	$album_save( $album_editor, $album_edit_id, array( 'post_title' => 'Renamed by quick edit' ) );

	restore_error_handler();

	$checks->assert(
		'an album save with no nonce changes nothing',
		get_post_meta( $album_edit_id, Lichtbild_Repository::ALBUM_META_V2, true ) === $album_record
			&& empty( $album_warnings ),
		'a request with no album fields rewrote the album or complained: ' . implode( '; ', $album_warnings )
	);

	// A v2 record written before the migration is a record nothing reads, so a save that looks
	// to have worked would change nothing a visitor sees.
	//
	// The row goes back to Envira's post type as well as the flag, and that is the check rather
	// than tidiness — the same trap the gallery editor's twin fell into. Clearing the flag alone
	// leaves a `lichtbild_album` row on a site whose `album_type()` now says `envira_album`, so the
	// *post type* guard refuses first and this passes whether or not the migration guard exists.
	unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );
	$site->posts[ $album_edit_id ]['post_type'] = Lichtbild_Repository::ALBUM_POST_TYPE;

	$unmigrated_payload                       = $album_payload( $album_record, $album_edit_id );
	$unmigrated_payload['lichtbild_album_order'] = '';

	$album_save( $album_editor, $album_edit_id, $unmigrated_payload );

	$site->posts[ $album_edit_id ]['post_type']      = Lichtbild_Post_Types::ALBUM;
	$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = Lichtbild_Settings::SCHEMA_MIGRATED;

	$checks->assert(
		'an unmigrated site refuses to save an album',
		get_post_meta( $album_edit_id, Lichtbild_Repository::ALBUM_META_V2, true ) === $album_record,
		'an unmigrated site emptied the album'
	);

	// --- the round trip -----------------------------------------------------------------

	$album_save( $album_editor, $album_edit_id, $album_payload( $album_record, $album_edit_id ) );

	$album_after = lichtbild_render_album_found( $checks, $renderer, $album_reader()->album( $album_edit_id ), $album_reader(), "album #{$album_edit_id} unreadable through the editing reader" );

	$checks->assert(
		'an album save round-trips byte for byte',
		'' !== $album_before && $album_after === $album_before,
		'' === $album_before
			? 'the album rendered nothing before the save, so nothing round-tripped'
			: 'the album changed by ' . ( strlen( $album_after ) - strlen( $album_before ) ) . ' bytes on a no-op save'
	);

	// The control the round trip needs: an identical render is also what a save that never ran
	// produces. Reversing the order has to change it, and putting it back has to restore it.
	$reversed          = $album_record;
	$reversed['items'] = array_reverse( $album_record['items'] );

	$album_save( $album_editor, $album_edit_id, $album_payload( $reversed, $album_edit_id ) );

	$reversed_ids = lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable through the editing reader" )->gallery_ids();

	$album_save( $album_editor, $album_edit_id, $album_payload( $album_record, $album_edit_id ) );

	$restored_ids = lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable through the editing reader" )->gallery_ids();

	$checks->assert(
		'stored album order follows the submitted order',
		$reversed_ids === array_reverse( wp_list_pluck( $album_record['items'], 'id' ) )
			&& $restored_ids === array_map( 'intval', wp_list_pluck( $album_record['items'], 'id' ) ),
		'reversed to ' . implode( ',', $reversed_ids ) . ' and back to ' . implode( ',', $restored_ids )
	);

	// And the leg that makes the order *field* the thing being tested, which is what the gallery
	// side has always done: reverse the field and leave the rows where they are. Reversing the
	// record instead re-keys the rows in the new order too, so the order list and the map agree
	// and a handler walking the map passes -- a mutation that made the album walk the map
	// survived the leg above for exactly that reason.
	$order_only                       = $album_payload( $album_record, $album_edit_id );
	$order_only['lichtbild_album_order'] = implode( ',', array_reverse( explode( ',', $order_only['lichtbild_album_order'] ) ) );

	$album_save( $album_editor, $album_edit_id, $order_only );

	$order_only_ids = lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable through the editing reader" )->gallery_ids();

	$album_save( $album_editor, $album_edit_id, $album_payload( $album_record, $album_edit_id ) );

	$checks->assert(
		'stored album order follows the submitted order',
		$order_only_ids === array_reverse( array_map( 'intval', wp_list_pluck( $album_record['items'], 'id' ) ) ),
		'reversing only the order field stored ' . implode( ',', $order_only_ids )
	);

	// --- a member has to be a gallery ---------------------------------------------------

	$intruder                                      = $album_payload( $album_record, $album_edit_id );
	$intruder['lichtbild_album_items']['x']           = array( 'id' => (string) $album_edit_id, 'cover_id' => '0', 'caption' => '' );
	$intruder['lichtbild_album_order']               .= ',x';

	$album_save( $album_editor, $album_edit_id, $intruder );

	$intruder_ids = lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable through the editing reader" )->gallery_ids();

	$checks->assert(
		'a member that is not a gallery is dropped',
		! in_array( $album_edit_id, $intruder_ids, true )
			&& $intruder_ids === array_map( 'intval', wp_list_pluck( $album_record['items'], 'id' ) ),
		'the album stored ' . implode( ',', $intruder_ids ) . ' when handed its own id as a member'
	);

	// --- a member the person cannot edit ------------------------------------------------
	//
	// The save guard authorises the ALBUM and says nothing about the galleries the request
	// names. Until 26.8.25 nothing else asked either, so an author who could edit one album
	// could POST another author's private or draft gallery id and have the editor render its
	// title and cover thumbnail back to them. `handle_covers()` checks the album AND the
	// gallery, and the two checks below it in this file prove it; this path did not share the
	// rule, and no check reached it.
	//
	// Three boundaries, because a picker is markup and markup is a suggestion: the list must
	// not offer it, the save must not store it, and the screen must not draw one that is
	// already stored. Each has an allowed-object control in the same assertion, so a fix that
	// simply refused everything would redden them rather than pass.

	// Chosen from the FIXTURE, not through `gallery_choices()`. Deriving the subject of a test
	// from the code under test means any mutation of that code silently removes the test: the
	// first version of this block read the picker, and six unrelated mutations made all three
	// checks disappear instead of fail.
	$outsider = 0;

	foreach ( $site->posts as $candidate_id => $candidate_row ) {
		if ( Lichtbild_Post_Types::GALLERY !== ( $candidate_row['post_type'] ?? '' ) ) {
			continue;
		}

		if ( ! in_array( (int) $candidate_id, $intruder_ids, true ) ) {
			$outsider = (int) $candidate_id;

			break;
		}
	}

	if ( $outsider > 0 ) {
		$adding                                        = $album_payload( $album_record, $album_edit_id );
		$adding['lichtbild_album_items']['new']        = array( 'id' => (string) $outsider, 'cover_id' => '0', 'caption' => '' );
		$adding['lichtbild_album_order']              .= ',new';

		// Control first: with the capability, the same payload stores it. Without this the
		// refusal below would be satisfied by a payload that never worked.
		$album_save( $album_editor, $album_edit_id, $adding );
		$allowed_ids = lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable" )->gallery_ids();

		$album_save( $album_editor, $album_edit_id, $album_payload( $album_record, $album_edit_id ) );

		$site->capability_overrides[ 'edit_post:' . $outsider ] = false;

		$album_save( $album_editor, $album_edit_id, $adding );
		$refused_ids = lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable" )->gallery_ids();

		$denied_choices = $album_reader()->gallery_choices( 'edit_post' );

		ob_start();
		$album_editor->render_galleries_box( (object) array( 'ID' => $album_edit_id ) );
		$denied_form = (string) ob_get_clean();

		unset( $site->capability_overrides[ 'edit_post:' . $outsider ] );

		$checks->assert(
			'a gallery the person cannot edit is not stored as a member',
			in_array( $outsider, $allowed_ids, true ) && ! in_array( $outsider, $refused_ids, true ),
			'with the capability the album stored ' . implode( ',', $allowed_ids )
				. '; without it, ' . implode( ',', $refused_ids )
		);

		$checks->assert(
			'the gallery picker omits what the person cannot edit',
			! array_key_exists( $outsider, $denied_choices ) && ! empty( $denied_choices ),
			'the picker offered ' . count( $denied_choices ) . ' galleries and '
				. ( array_key_exists( $outsider, $denied_choices ) ? 'included' : 'omitted' ) . " #{$outsider}"
		);

		// A member stored before the rule existed still names it, so the screen has to refuse
		// too. Restore it as a member with the capability, then take the capability away.
		$album_save( $album_editor, $album_edit_id, $adding );
		$stored_title = (string) get_the_title( $outsider );

		$site->capability_overrides[ 'edit_post:' . $outsider ] = false;

		ob_start();
		$album_editor->render_galleries_box( (object) array( 'ID' => $album_edit_id ) );
		$stored_denied_form = (string) ob_get_clean();

		unset( $site->capability_overrides[ 'edit_post:' . $outsider ] );

		ob_start();
		$album_editor->render_galleries_box( (object) array( 'ID' => $album_edit_id ) );
		$stored_allowed_form = (string) ob_get_clean();

		$album_save( $album_editor, $album_edit_id, $album_payload( $album_record, $album_edit_id ) );

		$checks->assert(
			'a stored member the person cannot edit is not drawn',
			'' !== $stored_title
				&& false !== strpos( $stored_allowed_form, esc_html( $stored_title ) )
				&& false === strpos( $stored_denied_form, esc_html( $stored_title ) ),
			'title "' . $stored_title . '" present with the capability: '
				. ( false !== strpos( $stored_allowed_form, esc_html( $stored_title ) ) ? 'yes' : 'no' )
				. ', without: ' . ( false !== strpos( $stored_denied_form, esc_html( $stored_title ) ) ? 'yes' : 'no' )
		);
	} else {
		foreach ( array(
			'a gallery the person cannot edit is not stored as a member',
			'the gallery picker omits what the person cannot edit',
			'a stored member the person cannot edit is not drawn',
		) as $unreachable ) {
			$checks->assert( $unreachable, false, 'no gallery outside this album to use as the unauthorised one' );
		}
	}

	// --- a cover has to belong to the gallery it covers ----------------------------------
	//
	// This is the one that cannot be seen from the front end: the renderer falls back to the
	// gallery's first image whenever a cover will not resolve, so a cover pointing at another
	// gallery's photograph looks exactly like a cover nobody chose.
	// Indexed unconditionally until now. A mutation that empties the converted album fataled the
	// suite here instead of failing anything, so the report never appeared; with the guard the
	// two cover checks below go red, which is what they are for.
	$checks->assert( 'the migrated album carries its members', ! empty( $album_record['items'] ), 'the converted album record holds no members' );

	$member_id     = empty( $album_record['items'] ) ? 0 : (int) $album_record['items'][0]['id'];
	$member_gallery = $migrated_repository->gallery( $member_id );
	$member_items  = null === $member_gallery ? array() : $member_gallery->items();
	$own_cover     = empty( $member_items ) ? 0 : $member_items[ count( $member_items ) - 1 ]->id();
	$foreign_cover = 0;

	foreach ( $album_record['items'] as $other ) {
		if ( (int) $other['id'] === $member_id ) {
			continue;
		}

		$other_gallery = $migrated_repository->gallery( (int) $other['id'] );
		$other_items   = null === $other_gallery ? array() : $other_gallery->items();

		if ( ! empty( $other_items ) ) {
			$foreign_cover = $other_items[0]->id();

			break;
		}
	}

	// Guarded, because both members having images is a fact about the fixture rather than about
	// the code: with no images there is no cover to choose and no wrong one to refuse. The two
	// checks are declared above, so skipping them is reported [EMPTY] and fails -- never as the
	// silence that reads like coverage.
	$with_foreign                                        = $album_payload( $album_record, $album_edit_id );
	$with_foreign['lichtbild_album_items']['i0']['cover_id'] = (string) $foreign_cover;

	$album_save( $album_editor, $album_edit_id, $with_foreign );

	$foreign_row    = lichtbild_album_member( lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable through the editing reader" ), $member_id );
	$stored_foreign = null === $foreign_row ? null : (int) $foreign_row['cover_id'];

	$with_own                                        = $album_payload( $album_record, $album_edit_id );
	$with_own['lichtbild_album_items']['i0']['cover_id'] = (string) $own_cover;

	$album_save( $album_editor, $album_edit_id, $with_own );

	$own_row    = lichtbild_album_member( lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable through the editing reader" ), $member_id );
	$stored_own = null === $own_row ? null : (int) $own_row['cover_id'];

	$checks->assert(
		'a cover outside its gallery is refused',
		$foreign_cover > 0 && 0 === $stored_foreign,
		null === $stored_foreign
			? 'the album has no member ' . $member_id . ' at all, so nothing was refused'
			: 'attachment ' . $foreign_cover . ' from another gallery was stored as ' . $stored_foreign
	);

	// The control, and it is what makes the check above mean anything: a `clean_cover()` that
	// returned 0 for everything would satisfy the refusal and break the editor completely.
	$checks->assert(
		'a cover inside its gallery is kept',
		$own_cover > 0 && $stored_own === $own_cover,
		null === $stored_own
			? 'the album has no member ' . $member_id . ' at all, so nothing was kept'
			: 'the gallery\'s own attachment ' . $own_cover . ' was stored as ' . $stored_own
	);

	$album_save( $album_editor, $album_edit_id, $album_payload( $album_record, $album_edit_id ) );

	// --- settings ------------------------------------------------------------------------

	$loud                                          = $album_payload( $album_record, $album_edit_id );
	$loud['lichtbild_album_settings']                 = array( 'columns' => '5', 'show_titles' => '1', 'show_counts' => '1' );

	$album_save( $album_editor, $album_edit_id, $loud );

	$loud_album = lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable through the editing reader" );

	$quiet                         = $album_payload( $album_record, $album_edit_id );
	$quiet['lichtbild_album_settings'] = array( 'columns' => '5' );

	$album_save( $album_editor, $album_edit_id, $quiet );

	$quiet_album = lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable through the editing reader" );

	$checks->assert(
		'album settings survive a save',
		5 === $loud_album->columns() && $loud_album->has_titles() && $loud_album->has_counts(),
		'submitted 5/on/on and read back ' . $loud_album->columns() . '/' .
			( $loud_album->has_titles() ? 'on' : 'off' ) . '/' . ( $loud_album->has_counts() ? 'on' : 'off' )
	);

	// `sanitize()` is not `fill()`: an unchecked box submits nothing, and a default of true
	// would make it impossible to switch off.
	$checks->assert(
		'an unchecked album box switches its setting off',
		! $quiet_album->has_titles() && ! $quiet_album->has_counts() && 5 === $quiet_album->columns(),
		'an omitted checkbox read back as ' . ( $quiet_album->has_titles() ? 'on' : 'off' )
	);

	// Put the album back the way the migration left it, so everything below sees production.
	$album_save( $album_editor, $album_edit_id, $album_payload( $album_record, $album_edit_id ) );

	$checks->assert(
		'an album save round-trips byte for byte',
		'' !== $album_before
			&& lichtbild_render_album_found( $checks, $renderer, $album_reader()->album( $album_edit_id ), $album_reader(), "album #{$album_edit_id} unreadable through the editing reader" ) === $album_before,
		'' === $album_before
			? 'the album rendered nothing to begin with, so nothing round-tripped'
			: 'the album did not survive the settings checks'
	);

	// --- the screen ----------------------------------------------------------------------

	ob_start();
	$album_editor->render_galleries_box( (object) array( 'ID' => $album_edit_id ) );
	$album_form = (string) ob_get_clean();

	// The picker lists galleries under the type they are stored under *now*. A list built for
	// the pre-migration name comes back empty, which reads as "this site has no galleries"
	// rather than as a bug in the query.
	//
	// Counted inside the picker's own select rather than across the form, and that is the whole
	// check: every member row also carries a cover chooser holding one option per image, so a
	// count over the whole form is in the thousands and stays above any threshold however empty
	// the picker is. A mutation naming the pre-migration post type survived exactly that.
	$picker  = '';
	$opens   = strpos( $album_form, '<select id="lichtbild-album-add">' );
	$offered = 0;

	if ( false !== $opens ) {
		$picker  = substr( $album_form, $opens, strpos( $album_form, '</select>', $opens ) - $opens );
		$offered = substr_count( $picker, '<option value="' );
	}

	// "Every row" was the wrong property, and asserting it wrote the bug into the suite: Envira's
	// defaults pseudo-gallery is renamed by the migration like any other row, so a query finds it
	// and a picker built on the query offers "Envira Default Settings" as a member. The right
	// count is every gallery the reader will actually return.
	$pickable = 0;

	foreach ( array_keys( $site->galleries ) as $candidate_gallery ) {
		if ( null !== $album_reader()->gallery( (int) $candidate_gallery ) ) {
			$pickable++;
		}
	}

	$checks->assert(
		'the album screen offers every real gallery',
		$offered === $pickable && $pickable < count( $site->galleries ),
		'the picker offered ' . $offered . ' options for ' . $pickable . ' real galleries of ' .
			count( $site->galleries ) . ' rows'
	);

	// And names the defaults row explicitly, because a count alone is satisfied by excluding the
	// wrong one. `$pickable < rows` above is the control that stops this passing on a fixture
	// with no defaults row at all -- which is the state the local WordPress is in, and would
	// have made the whole check vacuous there.
	$checks->assert(
		'the album screen offers every real gallery',
		false === strpos( $picker, 'Envira Default Settings' ),
		'the picker offered Envira\'s defaults row as a member gallery'
	);

	// The server row and the client template are written separately -- one needs escaped PHP
	// values and the other `{{ }}` placeholders -- so the thing that drifts is a field being
	// in one and not the other.
	$album_fields = array( '[id]', '[cover_id]', '[caption]' );
	$template_ok  = true;
	$row_ok       = true;

	foreach ( $album_fields as $field ) {
		if ( false === strpos( $album_form, 'lichtbild_album_items[{{ data.key }}]' . $field ) ) {
			$template_ok = false;
		}

		if ( false === strpos( $album_form, 'lichtbild_album_items[i0]' . $field ) ) {
			$row_ok = false;
		}
	}

	$checks->assert(
		'both album row templates carry every record field',
		$template_ok && $row_ok,
		'server row: ' . ( $row_ok ? 'ok' : 'incomplete' ) . ', client template: ' . ( $template_ok ? 'ok' : 'incomplete' )
	);

	// Schema 1 explicitly, rather than deleting the option. On a fixture the suite has already
	// migrated, an ABSENT schema no longer means "unmigrated" -- it is the uninstall/reinstall
	// state, and `initialise()` now correctly repairs it back to 2 from the rows themselves.
	// Deleting it here would therefore assert the defect this release fixed.
	$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = 1;

	ob_start();
	$album_editor->render_galleries_box( (object) array( 'ID' => $album_edit_id ) );
	$unmigrated_album_form = (string) ob_get_clean();

	$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = Lichtbild_Settings::SCHEMA_MIGRATED;

	$checks->assert(
		'an unmigrated album screen offers no fields',
		false === strpos( $unmigrated_album_form, 'lichtbild_album_items[' )
			&& false === strpos( $unmigrated_album_form, 'name="' . Lichtbild_Album_Editor::NONCE . '"' )
			&& false !== strpos( $unmigrated_album_form, 'options-general.php?page=lichtbild' ),
		'the unmigrated album screen rendered ' . strlen( $unmigrated_album_form ) . ' bytes of form'
	);

	$site->meta_boxes = array();
	$album_editor->add_meta_boxes();

	$checks->assert(
		'album metaboxes attach to the post type that exists',
		isset( $site->meta_boxes['lichtbild-album-galleries'] )
			&& Lichtbild_Post_Types::ALBUM === $site->meta_boxes['lichtbild-album-galleries']['screen']
			&& isset( $site->meta_boxes['lichtbild-album-settings'] )
			&& isset( $site->meta_boxes['lichtbild-album-shortcode'] ),
		'registered: ' . wp_json_encode( $site->meta_boxes )
	);

	ob_start();
	$album_editor->column( 'lichtbild_galleries', $album_edit_id );
	$album_column = (string) ob_get_clean();

	$checks->assert(
		'the album list column counts its galleries',
		(string) count( $album_record['items'] ) === $album_column,
		'the column said "' . $album_column . '" for ' . count( $album_record['items'] ) . ' members'
	);

	// And on an un-migrated site, which is where this plugin has spent its whole life so far.
	// The authoritative record there is Envira's, so a column reading `_lichtbild_album` directly
	// reports 0 for every album -- visibly wrong on the one screen that summarises them.
	// The v2 record is removed for the duration, and that is the check rather than tidiness.
	// A migrate-then-rollback leaves the converted record behind deliberately, so counting it
	// gives the right answer by accident and the check passes whether or not the column consults
	// the right source. A site that has never migrated -- which is every site running v1, and
	// the live one today -- has no such record at all.
	$column_record = $site->albums[ $album_edit_id ]['lichtbild'];

	unset( $site->albums[ $album_edit_id ]['lichtbild'] );
	unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );
	$site->posts[ $album_edit_id ]['post_type'] = Lichtbild_Repository::ALBUM_POST_TYPE;

	ob_start();
	( new Lichtbild_Album_Editor( new Lichtbild_Settings(), new Lichtbild_Repository() ) )
		->column( 'lichtbild_galleries', $album_edit_id );
	$unmigrated_column = (string) ob_get_clean();

	$site->posts[ $album_edit_id ]['post_type']      = Lichtbild_Post_Types::ALBUM;
	$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = Lichtbild_Settings::SCHEMA_MIGRATED;
	$site->albums[ $album_edit_id ]['lichtbild']        = $column_record;

	$checks->assert(
		'the album list column counts its galleries',
		(string) count( $album_record['items'] ) === $unmigrated_column,
		'an un-migrated site\'s column said "' . $unmigrated_column . '" for ' .
			count( $album_record['items'] ) . ' members'
	);

	$album_with_date = $album_editor->columns( array( 'title' => 'Title', 'date' => 'Date' ) );
	$album_no_date   = $album_editor->columns( array( 'title' => 'Title' ) );

	$checks->assert(
		'the album list columns survive a table with no date',
		array( 'title', 'lichtbild_galleries', 'lichtbild_shortcode', 'date' ) === array_keys( $album_with_date )
			&& array( 'title', 'lichtbild_galleries', 'lichtbild_shortcode' ) === array_keys( $album_no_date ),
		'with date: ' . implode( ',', array_keys( $album_with_date ) ) . ' / without: ' .
			implode( ',', array_keys( $album_no_date ) )
	);

	// --- the cover endpoint ----------------------------------------------------------------
	//
	// It reports the contents of a gallery, so being logged in is not enough: the question is
	// whether this user may edit *this album*.
	//
	// The nonce is supplied because this endpoint refuses without one, and every check below is
	// about a *later* gate. It is asserted in its own right first, so that a valid nonce here is
	// a precondition being met rather than a formality nobody has looked at.
	$_REQUEST['album']   = $album_edit_id;
	$_REQUEST['gallery'] = $member_id;

	ob_start();
	$covers_no_nonce = '';

	try {
		$album_editor->handle_covers();
	} catch ( Lichtbild_Test_Halt $e ) {
		$covers_no_nonce = $e->getMessage();
	}

	$covers_no_nonce_body = (string) ob_get_clean();

	$_REQUEST['nonce'] = 'nonce:' . Lichtbild_Album_Editor::COVERS_NONCE_ACTION . '-not-this-one';

	ob_start();
	$covers_bad_nonce = '';

	try {
		$album_editor->handle_covers();
	} catch ( Lichtbild_Test_Halt $e ) {
		$covers_bad_nonce = $e->getMessage();
	}

	$covers_bad_nonce_body = (string) ob_get_clean();

	$_REQUEST['nonce'] = wp_create_nonce( Lichtbild_Album_Editor::COVERS_NONCE_ACTION );

	// `die:403` rather than `error 403`: this is the nonce gate answering, not the capability
	// check below it, and the two are worth being able to tell apart. An admin screen is never
	// served from a page cache, so refusing here costs nothing and is the right answer -- which
	// is precisely what the front-end endpoints cannot do, and the contrast the check below the
	// AJAX section is about.
	$checks->assert(
		'the cover endpoint refuses a missing or wrong nonce',
		'die:403' === $covers_no_nonce && 'die:403' === $covers_bad_nonce
			&& '' === $covers_no_nonce_body && '' === $covers_bad_nonce_body,
		'absent said "' . $covers_no_nonce . '", wrong said "' . $covers_bad_nonce . '"'
	);

	$site->capability_overrides[ 'edit_post:' . $album_edit_id ] = false;

	ob_start();
	$covers_halt = '';

	try {
		$album_editor->handle_covers();
	} catch ( Lichtbild_Test_Halt $e ) {
		$covers_halt = $e->getMessage();
	}

	$covers_refused = (string) ob_get_clean();

	unset( $site->capability_overrides[ 'edit_post:' . $album_edit_id ] );

	ob_start();
	$covers_allowed_halt = '';

	try {
		$album_editor->handle_covers();
	} catch ( Lichtbild_Test_Halt $e ) {
		$covers_allowed_halt = $e->getMessage();
	}

	$covers_allowed = (string) ob_get_clean();

	unset( $_REQUEST['album'], $_REQUEST['gallery'] );

	$checks->assert(
		'the cover endpoint refuses someone who cannot edit the album',
		'error 403' === $covers_halt
			&& false === strpos( $covers_refused, '"covers":[{' ),
		'the endpoint answered "' . $covers_halt . '" with ' . strlen( $covers_refused ) . ' bytes'
	);

	// The control, and the part that says the refusal above was the guard rather than an empty
	// gallery: the same request from someone who may edit the album returns its images.
	$covers_payload = json_decode( $covers_allowed, true );

	$checks->assert(
		'the cover endpoint answers with the gallery\'s own images',
		'success' === $covers_allowed_halt
			&& ! empty( $member_items )
			&& isset( $covers_payload['data']['covers'] )
			&& count( $covers_payload['data']['covers'] ) === count( $member_items )
			&& (int) $covers_payload['data']['covers'][0]['id'] === $member_items[0]->id(),
		'the endpoint answered "' . $covers_allowed_halt . '" with ' .
			( isset( $covers_payload['data']['covers'] ) ? count( $covers_payload['data']['covers'] ) : 'no' ) .
			' covers for ' . count( $member_items ) . ' images'
	);

	// --- the endpoint's second gate ------------------------------------------------------
	//
	// `edit_post` on the album says nothing about the gallery whose every image title and
	// thumbnail this returns; they are different posts and the capability is per-post. An
	// author with one album of their own could otherwise name any gallery on the site.
	$_REQUEST['album']   = $album_edit_id;
	$_REQUEST['gallery'] = $member_id;

	$site->capability_overrides[ 'edit_post:' . $member_id ] = false;

	ob_start();
	$gallery_denied = '';

	try {
		$album_editor->handle_covers();
	} catch ( Lichtbild_Test_Halt $e ) {
		$gallery_denied = $e->getMessage();
	}

	$gallery_denied_body = (string) ob_get_clean();

	unset( $site->capability_overrides[ 'edit_post:' . $member_id ] );

	$checks->assert(
		'the cover endpoint refuses someone who cannot edit the gallery',
		'error 403' === $gallery_denied && false === strpos( $gallery_denied_body, '"covers":[{' ),
		'the endpoint answered "' . $gallery_denied . '" with ' . strlen( $gallery_denied_body ) . ' bytes'
	);

	// And the album id has to be an album. Without that, `edit_post` on any post at all -- a
	// page the user wrote -- is the key, because the capability itself does not care what type
	// the object is.
	$_REQUEST['album'] = $member_id;

	ob_start();
	$wrong_type_halt = '';

	try {
		$album_editor->handle_covers();
	} catch ( Lichtbild_Test_Halt $e ) {
		$wrong_type_halt = $e->getMessage();
	}

	$wrong_type_body = (string) ob_get_clean();

	$_REQUEST['album'] = $album_edit_id;

	$checks->assert(
		'the cover endpoint refuses an album id that is not an album',
		'error 403' === $wrong_type_halt && false === strpos( $wrong_type_body, '"covers":[{' ),
		'a gallery id passed as the album answered "' . $wrong_type_halt . '"'
	);

	unset( $_REQUEST['album'], $_REQUEST['gallery'], $_REQUEST['nonce'] );

	// --- backslashes -----------------------------------------------------------------------
	//
	// `update_post_meta()` unslashes what it stores, because WordPress normally hands it raw
	// `$_POST`. A save path that has already unslashed therefore loses one level of backslashes
	// per write, silently. Only visible with a stub that models the metadata layer honestly --
	// one storing the value verbatim makes both ends agree whatever the code does.
	// Submitted the way WordPress delivers it -- `$_POST` arrives slashed, which is why the save
	// path unslashes at all -- so the payload is `wp_slash()`ed here rather than written raw.
	// Getting that wrong tests the fixture instead of the code: the value would arrive already
	// stripped and the check would fail against correct production code.
	$slash_intended = "C:\\Photos 100% 'quoted'";
	$slashy         = $album_payload( $album_record, $album_edit_id );

	$slashy['lichtbild_album_items']['i0']['caption'] = wp_slash( $slash_intended );

	$album_save( $album_editor, $album_edit_id, $slashy );

	$slash_row    = lichtbild_album_member( lichtbild_album_found( $checks, $album_reader()->album( $album_edit_id ), "album #{$album_edit_id} unreadable through the editing reader" ), $member_id );
	$slash_stored = null === $slash_row ? null : (string) $slash_row['caption'];

	$album_save( $album_editor, $album_edit_id, $album_payload( $album_record, $album_edit_id ) );

	$checks->assert(
		'a backslash in a caption survives the save',
		$slash_intended === $slash_stored,
		'submitted ' . wp_json_encode( $slash_intended ) . ', stored ' . wp_json_encode( $slash_stored )
	);

	// --- the same gallery twice --------------------------------------------------------------
	//
	// The storage allows it and the editor can create it, so the renderer has to walk positions
	// rather than look each member up by id -- which returns the first match for every position
	// and renders one photograph twice.
	if ( $own_cover > 0 && count( $member_items ) > 1 ) {
		$twice = $album_payload( $album_record, $album_edit_id );

		$twice['lichtbild_album_items']['i0']['cover_id'] = (string) $member_items[0]->id();
		$twice['lichtbild_album_items']['i0']['caption']  = 'first appearance';
		$twice['lichtbild_album_items']['dup']            = array(
			'id'       => (string) $member_id,
			'cover_id' => (string) $own_cover,
			'caption'  => 'second appearance',
		);
		$twice['lichtbild_album_order']                  .= ',dup';

		$album_save( $album_editor, $album_edit_id, $twice );

		$twice_markup = lichtbild_render_album_found( $checks, $renderer, $album_reader()->album( $album_edit_id ), $album_reader(), "album #{$album_edit_id} unreadable through the editing reader" );

		$album_save( $album_editor, $album_edit_id, $album_payload( $album_record, $album_edit_id ) );

		$checks->assert(
			'a repeated member keeps its own cover and caption',
			false !== strpos( $twice_markup, 'first appearance' )
				&& false !== strpos( $twice_markup, 'second appearance' )
				&& substr_count( $twice_markup, 'lichtbild-album-item' ) === count( $album_record['items'] ) + 1,
			'the album rendered ' . substr_count( $twice_markup, 'lichtbild-album-item' ) . ' items, captions: ' .
				( false !== strpos( $twice_markup, 'first appearance' ) ? 'first ' : '' ) .
				( false !== strpos( $twice_markup, 'second appearance' ) ? 'second' : '' )
		);
	}

	$site->capabilities = $album_caps;
}

// The plan is directional, so after migrating it has to describe the rollback. A plan that
// kept counting the Envira types would report three zeroes here, which reads as "nothing to
// undo" on the very screen offering to undo it.
$after_plan = $migration->plan();

$checks->assert(
	'plan follows the direction',
	true === $after_plan['migrated']
		&& $after_plan['galleries'] === $before_plan['galleries']
		&& $after_plan['albums'] === $before_plan['albums']
		&& $after_plan['terms'] === $before_plan['terms'],
	sprintf(
		'after migrating the plan said %d/%d/%d',
		$after_plan['galleries'],
		$after_plan['albums'],
		$after_plan['terms']
	)
);

// Takeover stops being a choice once the rows have moved, because Envira cannot read them.
// Forced to `never` first: on `auto` with Envira absent the answer is true anyway, so the
// check would pass whether or not the migrated case is handled at all.
$site->options['lichtbild_takeover'] = 'never';

$checks->assert(
	'migrated site always takes the shortcode over',
	'never' === $settings->takeover() && $settings->should_take_over(),
	'a migrated site honoured the takeover setting'
);

unset( $site->options['lichtbild_takeover'] );

$rolled_back = $migration->rollback();

// Envira's own Yoast keys were never removed, so a rolled back site finds them under the names
// its rows are back under, and the ones this migration added simply describe types nothing
// registers. That is why there is no inverse pass to write -- asserted rather than argued,
// because "nothing to do" is the easiest kind of claim to be wrong about.
$checks->assert(
	'a rollback needs no inverse of that',
	'%%term_title%% Archive %%sep%% %%sitename%%' === $site->options['wpseo_titles']['title-tax-envira-tag']
		&& '%%title%% %%sep%% %%sitename%%' === $site->options['wpseo_titles']['title-envira'],
	'envira keys after rollback: ' . implode(
		', ',
		array_filter( array_keys( $site->options['wpseo_titles'] ), function ( $k ) {
			return false !== strpos( $k, 'envira' );
		} )
	)
);

$checks->assert(
	'rollback restores every row',
	empty( $rolled_back['errors'] )
		&& count( $site->galleries ) === $rolled_back['galleries']
		&& count( $site->albums ) === $rolled_back['albums']
		&& count( $site->taxonomies ) === $rolled_back['terms']
		&& false === $settings->has_migrated(),
	sprintf(
		'restored %d/%d/%d',
		$rolled_back['galleries'],
		$rolled_back['albums'],
		$rolled_back['terms']
	)
);

// And the Envira path works again, unchanged, which is what "reversible" has to mean.
foreach ( $pre_migration as $gallery_id => $before_markup ) {
	$gallery = ( new Lichtbild_Repository() )->gallery( $gallery_id );

	$checks->assert(
		'rollback restores every row',
		null !== $gallery && $renderer->gallery( $gallery, 1 ) === $before_markup,
		"#{$gallery_id} does not render as it did before the migration"
	);
}

// Rollback deliberately keeps the converted records: migrating again must not have to
// rebuild them, and a record left behind cannot be read while the post type does not match.
$kept = 0;

foreach ( array_keys( $site->galleries ) as $gallery_id ) {
	if ( is_array( get_post_meta( $gallery_id, '_lichtbild_gallery', true ) ) ) {
		$kept++;
	}
}

$checks->assert(
	'rollback leaves the converted records in place',
	$kept === $expected_real,
	$kept . ' records survived the rollback, expected ' . $expected_real
);

// A second rollback has nothing to undo and must say so rather than moving rows back again.
$checks->assert(
	'rollback refuses when there is nothing to roll back',
	! empty( $migration->rollback()['errors'] ),
	'a second rollback reported success'
);

// Rolling back has to restore Envira's *authority*, not merely its post types. The converted
// records are deliberately left behind, so a reader that prefers them whenever they exist
// would keep rendering the pre-rollback snapshot — and an edit made in Envira afterwards
// would silently have no effect. Two sources of truth, in the state that exists precisely
// because someone wanted to undo the migration.
//
// Proved by making the two records disagree: the retained record says fixed columns, the
// Envira record says justified rows, and an unmigrated reader must show the second.
$authority_id = array_key_first( $pre_migration );

$site->galleries[ $authority_id ]['lichtbild']['settings']['layout']  = 'columns';
$site->galleries[ $authority_id ]['lichtbild']['settings']['columns'] = 7;

$authority_markup = lichtbild_render_found(
	$checks,
	$renderer,
	( new Lichtbild_Repository() )->gallery( $authority_id ),
	"#{$authority_id} unreadable through the envira path after a rollback"
);

$checks->assert(
	'a rolled back site ignores the converted record',
	$authority_markup === $pre_migration[ $authority_id ],
	"#{$authority_id} rendered the retained Lichtbild record after a rollback"
);

// The control: that record does win once the site owns its data, or the check above would
// pass just as happily against a reader that could never see a converted record at all.
$authority_owned = lichtbild_render_found(
	$checks,
	$renderer,
	( new Lichtbild_Repository( Lichtbild_Repository::GALLERY_POST_TYPE, Lichtbild_Repository::ALBUM_POST_TYPE, Lichtbild_Repository::TAG_TAXONOMY, true ) )->gallery( $authority_id ),
	"#{$authority_id} unreadable through the v2 path while owning its data"
);

$checks->assert(
	'a migrated site does use the converted record',
	$authority_owned !== $pre_migration[ $authority_id ],
	"#{$authority_id} ignored its own converted record while owning its data"
);

// The same pair for albums, because they gained a converted record of their own in 26.8.3 and
// inherit the identical hazard: the rollback leaves `_lichtbild_album` behind, so a reader that
// preferred it unconditionally would render the pre-rollback album for ever after.
$album_authority = array_key_first( $pre_migration_albums );

if ( null !== $album_authority ) {
	$site->albums[ $album_authority ]['lichtbild']['settings']['columns'] = 7;

	$checks->assert(
		'a rolled back site ignores the converted album',
		lichtbild_render_album_found( $checks, $renderer, ( new Lichtbild_Repository() )->album( $album_authority ), new Lichtbild_Repository(), "album #{$album_authority} unreadable through the envira path after a rollback" )
			=== $pre_migration_albums[ $album_authority ],
		"#{$album_authority} rendered the retained Lichtbild record after a rollback"
	);

	$owning_album = new Lichtbild_Repository(
		Lichtbild_Repository::GALLERY_POST_TYPE,
		Lichtbild_Repository::ALBUM_POST_TYPE,
		Lichtbild_Repository::TAG_TAXONOMY,
		true
	);

	// The control, for the same reason as the gallery pair above.
	$checks->assert(
		'a migrated site does use the converted album',
		lichtbild_render_album_found( $checks, $renderer, $owning_album->album( $album_authority ), $owning_album, "album #{$album_authority} unreadable while owning its data" )
			!== $pre_migration_albums[ $album_authority ],
		"#{$album_authority} ignored its own converted record while owning its data"
	);
}

$site->galleries[ $authority_id ]['lichtbild'] = Lichtbild_Migration::build_record(
	$site->galleries[ $authority_id ]['data'],
	$authority_id
);

// A failing statement must not read as "nothing needed doing". $wpdb->update() returns false
// on error and 0 when no row matched, and casting both to int makes a failed migration look
// like a successful one — it would go on to write the schema option and report success.
//
// The same failure is also the only thing this plugin logs, and the log is read back rather
// than assumed: `error_log()` writes to the SAPI log, so a check that merely calls the code and
// finds the migration reported an error proves nothing about what was written anywhere. The
// destination is redirected to a file for the length of this section and restored afterwards.
$checks->expect( 'a failed rename says why in the log', 'an ordinary rename logs nothing' );

$log_path       = (string) tempnam( sys_get_temp_dir(), 'lichtbild-log-' );
$log_target_was = (string) ini_get( 'error_log' );
$log_errors_was = (string) ini_get( 'log_errors' );

ini_set( 'error_log', $log_path );
ini_set( 'log_errors', '1' );

$GLOBALS['wpdb']->fail_updates_on = $GLOBALS['wpdb']->posts;
$failed                           = $migration->migrate();
$GLOBALS['wpdb']->fail_updates_on = '';

$logged = (string) file_get_contents( $log_path );

$checks->assert(
	'a failing statement is reported',
	! empty( $failed['errors'] ),
	'the migration reported success while every posts update failed'
);

// Named parts, not "something was logged": the reason a half-failed migration is diagnosable
// tomorrow is that the line says which table, which value and what the database objected to.
// The value is matched quoted, because `envira` is a substring of `envira_album` and the album
// rename fails in the same breath — an unquoted match would be satisfied by the wrong line.
$checks->assert(
	'a failed rename says why in the log',
	false !== strpos( $logged, $GLOBALS['wpdb']->posts )
		&& false !== strpos( $logged, '"' . Lichtbild_Repository::GALLERY_POST_TYPE . '"' )
		&& false !== strpos( $logged, Lichtbild_Test_wpdb::SIMULATED_ERROR ),
	'logged: ' . trim( $logged )
);

// And it must not have written the schema option. The option is what every read consults, so
// claiming a state the rows are not in is worse than the failed statement itself: the reader
// would look for `lichtbild_gallery` while the rows still say `envira`, and every gallery on the
// site would be unreachable.
$checks->assert(
	'a failed migration does not claim to have migrated',
	! $settings->has_migrated(),
	'the schema option was written despite a failed rename'
);

// The taxonomy update was not blocked, so the tags did move — which is the mixed state.
$mixed_plan = $migration->plan();

$checks->assert(
	'a mixed state is reported as one',
	! empty( $mixed_plan['mixed'] ) && $mixed_plan['stranded'] > 0,
	'plan reported a clean state over stranded rows: ' . wp_json_encode(
		array( $mixed_plan['mixed'], $mixed_plan['stranded'] )
	)
);

$migration->rollback();

// The control, and it is what keeps the logging where it was put. A rollback that succeeds is
// an ordinary operation, and this plugin deliberately has no logging outside the branch above —
// a line per rename is a log nobody can read, which is how the one line that matters gets lost.
// Compared against the failure's own output rather than against an empty file, so the check
// says "nothing further was written" instead of "nothing was ever written".
$logged_after = (string) file_get_contents( $log_path );

ini_set( 'error_log', $log_target_was );
ini_set( 'log_errors', $log_errors_was );
unlink( $log_path );

$checks->assert(
	'an ordinary rename logs nothing',
	$logged_after === $logged,
	'a successful rollback added: ' . trim( substr( $logged_after, strlen( $logged ) ) )
);

// An interrupted migration is the state with no transaction to protect it: rows renamed,
// schema option never written. Rollback has to work from there, because it is the only way
// back, and a rollback gated on the option would refuse in precisely that case.
$GLOBALS['wpdb']->update(
	$GLOBALS['wpdb']->posts,
	array( 'post_type' => Lichtbild_Post_Types::GALLERY ),
	array( 'post_type' => Lichtbild_Repository::GALLERY_POST_TYPE ),
	array( '%s' ),
	array( '%s' )
);

$checks->assert(
	'an interrupted migration is still recoverable',
	! $settings->has_migrated()
		&& empty( $migration->rollback()['errors'] )
		&& 'envira' === get_post_type( array_key_first( $site->galleries ) ),
	'rollback refused, or failed, on rows stranded under Lichtbild\'s types'
);

// A meta write that silently does not land is the case the read-back exists for. Trusting
// `update_post_meta()`'s return cannot cover it — false means "failed" and "already identical"
// alike — so the conversion is verified by reading the record back. Without that, a gallery
// would be counted as converted, renamed, and left with no record of its own: it would still
// render, but only from the Envira record it was supposed to stop depending on.
// The records from the earlier successful migration are still there, and they are identical
// to what this conversion would write — so with them in place the read-back is satisfied and
// proceeding is correct. Clearing them first is what makes the failed write actually missing,
// which is the only version of this scenario that is a defect.
foreach ( array_keys( $site->galleries ) as $gallery_id ) {
	unset( $site->galleries[ $gallery_id ]['lichtbild'] );
}

$site->fail_meta_writes = true;
$unwritten              = $migration->migrate();
$site->fail_meta_writes = false;

$checks->assert(
	'an unwritten conversion stops the migration',
	! empty( $unwritten['errors'] )
		&& 0 === $unwritten['converted']
		&& 'envira' === get_post_type( array_key_first( $site->galleries ) ),
	'converted=' . $unwritten['converted'] . ' errors=' . count( $unwritten['errors'] )
);

// A gallery's own permalink carries its images in post meta, not in post_content, so without
// a content filter the URL resolves, the theme renders and the page is empty — which an HTTP
// 200 reports as success. Envira gates the same behaviour on a site option, and the setting
// has to survive Envira being uninstalled or every gallery page on the site goes blank.
$checks->expect( 'standalone setting follows envira before migration', 'migration takes ownership of the standalone setting' );

unset( $site->options[ Lichtbild_Settings::OPTION_STANDALONE ] );

foreach ( array( array( 1, true ), array( 0, false ) ) as $case ) {
	$site->options[ Lichtbild_Settings::OPTION_STANDALONE_ENVIRA ] = $case[0];

	$checks->assert(
		'standalone setting follows envira before migration',
		$settings->standalone() === $case[1],
		'envira option ' . wp_json_encode( $case[0] ) . ' read as ' . wp_json_encode( $settings->standalone() )
	);
}

// A site that never had Envira has no option to fall back TO, and inherited Envira's default of
// off by accident: a brand-new gallery's permalink answered 200 with the title and none of its
// photographs. Found on a real fresh install, not here, because every fixture in this suite has
// an Envira history -- which is exactly why the check below sets the scheme explicitly and then
// asserts the OTHER direction as its own control.
$checks->expect( 'a site with no envira history renders galleries on their permalink', 'and one with an envira history still defers to envira' );

unset( $site->options[ Lichtbild_Settings::OPTION_STANDALONE ] );
unset( $site->options[ Lichtbild_Settings::OPTION_STANDALONE_ENVIRA ] );

$scheme_before = $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] ?? null;

$site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] = 'generic';

$checks->assert(
	'a site with no envira history renders galleries on their permalink',
	true === $settings->standalone(),
	'a fresh install read standalone as ' . wp_json_encode( $settings->standalone() )
);

$site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] = 'envira';

$checks->assert(
	'and one with an envira history still defers to envira',
	false === $settings->standalone(),
	'a site continuing Envira, with Envira\'s option absent, read standalone as '
		. wp_json_encode( $settings->standalone() )
);

if ( null === $scheme_before ) {
	unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );
} else {
	$site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] = $scheme_before;
}

// Lichtbild's own value wins once it exists, which is what makes uninstalling Envira safe.
$site->options[ Lichtbild_Settings::OPTION_STANDALONE_ENVIRA ] = 0;
$site->options[ Lichtbild_Settings::OPTION_STANDALONE ]        = 1;

$checks->assert(
	'standalone setting follows envira before migration',
	true === $settings->standalone(),
	'Lichtbild\'s own standalone option did not win over Envira\'s'
);

// The migration copies the value across, so the choice outlives the plugin that recorded it.
foreach ( array( 1, 0 ) as $envira_value ) {
	$migration->rollback();
	unset( $site->options[ Lichtbild_Settings::OPTION_STANDALONE ] );
	$site->options[ Lichtbild_Settings::OPTION_STANDALONE_ENVIRA ] = $envira_value;

	$migration->migrate();

	$checks->assert(
		'migration takes ownership of the standalone setting',
		(int) $site->options[ Lichtbild_Settings::OPTION_STANDALONE ] === $envira_value,
		'envira had ' . $envira_value . ', Lichtbild stored ' . wp_json_encode( $site->options[ Lichtbild_Settings::OPTION_STANDALONE ] ?? null )
	);
}

$migration->rollback();
unset( $site->options[ Lichtbild_Settings::OPTION_STANDALONE ], $site->options[ Lichtbild_Settings::OPTION_STANDALONE_ENVIRA ] );

// ============================================================================
// The last statement of a migration, and the one whose failure is invisible.
//
// The schema option is what every read consults, so it decides which post types the *next*
// request registers and queries. A migration that renames every row and then fails to write it
// reports success while the site goes looking for its galleries under names they no longer have
// -- every gallery and album unreachable, from a write nobody read the return of.
//
// This runs last, and rolls back after itself, because it deliberately leaves the site in the
// broken state for the length of two assertions.
// ============================================================================

$checks->expect(
	'a migration that cannot write the schema reports it',
	'the rows are still recoverable after that failure'
);

if ( ! $settings->has_migrated() && ! $settings->envira_is_active() ) {
	$site->fail_options_on = array( Lichtbild_Settings::OPTION_SCHEMA );

	$stuck = ( new Lichtbild_Migration( $settings ) )->migrate();

	$site->fail_options_on = array();

	$checks->assert(
		'a migration that cannot write the schema reports it',
		! empty( $stuck['errors'] ) && false === ( new Lichtbild_Settings() )->has_migrated(),
		'the migration reported ' . count( $stuck['errors'] ) . ' errors while the flag said ' .
			( ( new Lichtbild_Settings() )->has_migrated() ? 'migrated' : 'not migrated' )
	);

	// And the state it leaves has to be the recoverable one. `rollback()` is gated on the rows
	// rather than on the flag precisely so that it still works here -- this is the state where
	// it is the only way back, and a rollback that consulted `has_migrated()` would refuse.
	$recovered = ( new Lichtbild_Migration( $settings ) )->rollback();

	$checks->assert(
		'the rows are still recoverable after that failure',
		empty( $recovered['errors'] ) && $recovered['galleries'] === count( $site->galleries ),
		'rollback recovered ' . $recovered['galleries'] . ' of ' . count( $site->galleries ) . ' galleries'
	);
}

// ============================================================================
// An auxiliary write that fails must be REPORTED, and must not hide the galleries.
//
// `migrate()` copies two things beside the rows: Envira's standalone setting, and Yoast's title
// and canonical settings onto the new post-type names. Neither write was read back, so a
// targeted failure left the schema at 2, the success notice claiming a copied-key count that
// never landed, and the site owner with no way to know.
//
// The obvious fix -- treat it as an error and refuse to advance the schema -- is worse than the
// defect, and that is why this block asserts the opposite. By this point the rows are already
// named `lichtbild_gallery`; a site whose schema says otherwise registers `envira` and finds
// none of them, which was measured on a full copy of the live site as 52 galleries present and
// 0 findable. Losing a Yoast title is a bad day. Hiding every gallery is a broken site. So the
// schema advances, and the failure arrives as a warning beside the success rather than instead
// of it -- the part that was genuinely missing, because it used to arrive as nothing at all.
// ============================================================================

$checks->expect(
	'a failed standalone copy is reported and the galleries stay findable',
	'a failed seo copy is reported and claims no copied keys',
	'neither is reported when both writes land'
);

if ( ! $settings->has_migrated() && ! $settings->envira_is_active() ) {
	$aux_rollback = static function () use ( $settings ) {
		( new Lichtbild_Migration( $settings ) )->rollback();
	};

	// Both copies are no-ops on a repeat migration: the standalone option already holds the
	// value about to be written, and `carry_seo_settings()` adds only keys that are not already
	// there. A readback cannot catch a write that had nothing to change, and a copied-key count
	// is 0 when there was nothing to copy -- so every assertion below would pass or fail for the
	// wrong reason without putting the two options back the way a first migration finds them.
	// The first draft of this block did exactly that and reported `warnings: 0` three times.
	$aux_reset = static function () use ( $site ) {
		unset( $site->options[ Lichtbild_Settings::OPTION_STANDALONE ] );

		$titles = isset( $site->options['wpseo_titles'] ) && is_array( $site->options['wpseo_titles'] )
			? $site->options['wpseo_titles']
			: array();

		foreach ( array_keys( $titles ) as $key ) {
			if ( false !== strpos( (string) $key, 'lichtbild_' ) ) {
				unset( $titles[ $key ] );
			}
		}

		$site->options['wpseo_titles'] = $titles;
	};

	$aux_reset();
	$site->fail_options_on = array( Lichtbild_Settings::OPTION_STANDALONE );
	$standalone_failed     = ( new Lichtbild_Migration( $settings ) )->migrate();
	$site->fail_options_on = array();
	$standalone_migrated   = ( new Lichtbild_Settings() )->has_migrated();

	$aux_rollback();

	$checks->assert(
		'a failed standalone copy is reported and the galleries stay findable',
		! empty( $standalone_failed['warnings'] )
			&& empty( $standalone_failed['errors'] )
			&& true === $standalone_migrated,
		'warnings: ' . count( $standalone_failed['warnings'] ) . ', errors: '
			. count( $standalone_failed['errors'] ) . ', migrated: '
			. var_export( $standalone_migrated, true )
	);

	$aux_reset();
	$site->fail_options_on = array( 'wpseo_titles' );
	$seo_failed            = ( new Lichtbild_Migration( $settings ) )->migrate();
	$site->fail_options_on = array();
	$seo_migrated          = ( new Lichtbild_Settings() )->has_migrated();

	$aux_rollback();

	$checks->assert(
		'a failed seo copy is reported and claims no copied keys',
		! empty( $seo_failed['warnings'] )
			&& 0 === (int) $seo_failed['seo_keys']
			&& empty( $seo_failed['errors'] )
			&& true === $seo_migrated,
		'warnings: ' . count( $seo_failed['warnings'] ) . ', seo_keys: '
			. (int) $seo_failed['seo_keys'] . ', migrated: ' . var_export( $seo_migrated, true )
	);

	// The control, and without it both assertions above are satisfied by a migration that always
	// warns. A clean run has to warn about nothing and report the keys it really copied.
	$aux_reset();
	$aux_clean = ( new Lichtbild_Migration( $settings ) )->migrate();

	$aux_rollback();

	$checks->assert(
		'neither is reported when both writes land',
		empty( $aux_clean['warnings'] )
			&& empty( $aux_clean['errors'] )
			&& (int) $aux_clean['seo_keys'] > 0,
		'warnings: ' . count( $aux_clean['warnings'] ) . ', seo_keys: ' . (int) $aux_clean['seo_keys']
	);
}


// The screen's guards are asserted against the handler, not the markup it draws. A control
// the screen declines to render is still reachable by anyone holding the URL, so a guard that
// lives in a `required` attribute or a `disabled` button is not a guard at all.
$screen               = new Lichtbild_Migration_Screen( $migration, $settings );
$site->capabilities   = true;

/**
 * Runs the migrate handler under a given request and reports how the request ended.
 *
 * @param Lichtbild_Migration_Screen $screen The screen.
 * @param string                  $method Request method.
 * @param array                   $post   POST body.
 *
 * @return string Either `die:<code>`, `redirect`, or `completed`.
 */
function lichtbild_test_submit( $screen, $method, $post ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_POST                     = $post;

	try {
		$screen->handle_migrate();
	} catch ( Lichtbild_Test_Halt $halt ) {
		return $halt->getMessage();
	}

	return 'completed';
}

// GET must not reach the work. admin_post_<action> fires for GET too, and a state-changing
// action reachable that way is one a prefetcher or a bookmark can trigger.
$checks->assert(
	'the migrate action refuses a GET',
	'die:405' === lichtbild_test_submit( $screen, 'GET', array( 'lichtbild_confirm' => '1' ) ),
	'a GET reached the migration'
);

// And the backup confirmation is enforced where the work happens, not by the checkbox.
$before_confirm = get_post_type( array_key_first( $site->galleries ) );

$checks->assert(
	'the migrate action requires the confirmation',
	'redirect' === lichtbild_test_submit( $screen, 'POST', array() )
		&& get_post_type( array_key_first( $site->galleries ) ) === $before_confirm,
	'a submission without the confirmation was accepted'
);

// And without the capability, even a correctly-formed POST is refused.
$site->capabilities = false;

$checks->assert(
	'the migrate action requires the capability',
	'die:403' === lichtbild_test_submit( $screen, 'POST', array( 'lichtbild_confirm' => '1' ) ),
	'a submission without manage_options was accepted'
);

$_POST                     = array();
$_SERVER['REQUEST_METHOD'] = 'GET';

// ============================================================================
// What the screen actually says afterwards.
//
// The outcome of a migration reaches the person who ran it as one notice and nothing else, and
// until now nothing here read it. Both properties below are about that notice rather than about
// the migration: several errors joined by a space are a paragraph of run-together sentences at
// the moment someone has to act on each of them, and a count the migration works out but never
// prints is a regression class that stays invisible — album records went a release unconverted,
// and the orphaned SEO settings took the canonical links off 58 indexed archives.
//
// The notice is extracted from the rest of the screen before anything is asserted about it. The
// plan below it prints the same numbers, so a check run against the whole page would be
// satisfied by the confirmation form while the notice said nothing at all.
// ============================================================================

$checks->expect(
	'a half-failed migration lists its errors',
	'a single error reads as a sentence',
	'the migration notice reports every count'
);

$site->capabilities = true;

/**
 * Renders the screen for a stored outcome and returns the notice it produced.
 *
 * @param Lichtbild_Migration_Screen $screen The screen.
 * @param array                   $result The outcome to report.
 * @param string                  $class  Notice class to extract.
 *
 * @return string The notice markup, or an empty string when none was rendered.
 */
function lichtbild_test_notice( $screen, $result, $class ) {
	set_transient( Lichtbild_Migration_Screen::RESULT . get_current_user_id(), $result, 300 );

	ob_start();
	$screen->render();
	$markup = (string) ob_get_clean();

	if ( ! preg_match( '~<div class="' . preg_quote( $class, '~' ) . '">(.*?)</div>~s', $markup, $found ) ) {
		return '';
	}

	return $found[1];
}

$two_errors = lichtbild_test_notice(
	$screen,
	array(
		'action' => 'migrate',
		'errors' => array( 'Renaming the first thing failed.', 'Renaming the second thing failed.' ),
	),
	'notice notice-error'
);

$checks->assert(
	'a half-failed migration lists its errors',
	2 === substr_count( $two_errors, '<li>' )
		&& false !== strpos( $two_errors, '<li>Renaming the first thing failed.</li>' )
		&& false !== strpos( $two_errors, '<li>Renaming the second thing failed.</li>' )
		// The shape it must not have, asserted directly: joined with a space, the second error
		// is still present and every "does it mention both" check passes over the wall.
		&& false === strpos( $two_errors, 'failed. Renaming' ),
	'notice was: ' . $two_errors
);

$one_error = lichtbild_test_notice(
	$screen,
	array(
		'action' => 'migrate',
		'errors' => array( 'Renaming the only thing failed.' ),
	),
	'notice notice-error'
);

$checks->assert(
	'a single error reads as a sentence',
	false !== strpos( $one_error, '<p>Renaming the only thing failed.</p>' )
		&& false === strpos( $one_error, '<li>' ),
	'notice was: ' . $one_error
);

// The six numbers `migrate()` returns, each distinct so no fragment can match another's digits,
// and each read out of the success notice alone.
$success = lichtbild_test_notice(
	$screen,
	array(
		'action'           => 'migrate',
		'galleries'        => 53,
		'albums'           => 3,
		'terms'            => 58,
		'converted'        => 51,
		'albums_converted' => 2,
		'seo_keys'         => 31,
		'errors'           => array(),
	),
	'notice notice-success'
);

$checks->assert(
	'the migration notice reports every count',
	false !== strpos( $success, '53 galleries' )
		&& false !== strpos( $success, '3 albums' )
		&& false !== strpos( $success, '58 image tags' )
		&& false !== strpos( $success, '51 gallery records' )
		&& false !== strpos( $success, '2 album records' )
		&& false !== strpos( $success, '31 Yoast SEO settings' ),
	'notice was: ' . $success
);

// ============================================================================
// The three states the screen can be in.
//
// `render()` dispatches to one of `render_mixed()`, `render_migrated()` and `render_pending()`,
// and all three executed during this suite with nothing asserted about any of them — the
// notices above are drawn by `render_result()`, which runs before the dispatch and is a
// different question. So the branch an operator actually reads was exercised and unchecked.
//
// `render_mixed()` is the one that matters. It is the screen left by a migration that died
// between the first rename and the schema option, which is the state the whole recovery design
// exists for, and the only screen that offers the way back. Its rule is that the rollback is
// offered on the strength of the ROWS and never the flag: gated on `has_migrated()` it would
// hide in precisely the state where it is the only way out.
// ============================================================================

$checks->expect(
	'the pending screen offers the migration',
	'the migrated screen offers the rollback',
	'a half-migrated site is told so and offered the way back'
);

/**
 * Renders the whole screen, with no stored outcome, and returns its markup.
 *
 * The transient is cleared first: `render_result()` draws a notice above the dispatch, and a
 * leftover outcome from the checks above would put its counts on the page — where a check
 * looking for a number in the plan would find it and pass for the wrong reason.
 *
 * @param Lichtbild_Migration_Screen $screen The screen.
 *
 * @return string The rendered markup.
 */
function lichtbild_test_screen( $screen ) {
	delete_transient( Lichtbild_Migration_Screen::RESULT . get_current_user_id() );

	ob_start();
	$screen->render();

	return (string) ob_get_clean();
}

// The site is unmigrated here: the rollback above put every row back under Envira's names.
$pending_screen = lichtbild_test_screen( $screen );

$checks->assert(
	'the pending screen offers the migration',
	false !== strpos( $pending_screen, 'value="' . Lichtbild_Migration_Screen::ACTION_MIGRATE . '"' )
		&& false !== strpos( $pending_screen, 'name="lichtbild_confirm"' )
		// And it must NOT offer the rollback, or "offers the migration" is satisfied by a screen
		// that offers both and has not dispatched anywhere.
		&& false === strpos( $pending_screen, 'value="' . Lichtbild_Migration_Screen::ACTION_ROLLBACK . '"' ),
	'the unmigrated screen did not offer the migration alone'
);

$migration->migrate();

$migrated_screen = lichtbild_test_screen( $screen );

$checks->assert(
	'the migrated screen offers the rollback',
	false !== strpos( $migrated_screen, 'value="' . Lichtbild_Migration_Screen::ACTION_ROLLBACK . '"' )
		&& false === strpos( $migrated_screen, 'value="' . Lichtbild_Migration_Screen::ACTION_MIGRATE . '"' )
		&& false === strpos( $migrated_screen, 'The migration did not finish.' ),
	'the migrated screen did not offer the rollback alone'
);

$migration->rollback();

// The interrupted state: rows renamed, option never written. Renaming by hand rather than
// calling `migrate()` is the point — the two halves have to be able to disagree, or the state
// under test cannot be constructed at all.
//
// One row, though, is smaller than a real interruption: `move()` renames every gallery in a
// single `$wpdb->update()`, so a request that dies mid-migration strands a whole category —
// all the galleries, or all the albums — rather than one gallery among fifty unmigrated ones.
// It is sufficient here because the property under test is the dispatch rule, and that reads
// `lichtbild_rows() > 0`; any non-zero count exercises it identically. Stated rather than glossed,
// because "built the way a dying request builds it" is what this comment said first, and that
// was a claim about fidelity the fixture does not support.
$stranded_id = array_key_first( $site->galleries );

$site->posts[ $stranded_id ]['post_type'] = Lichtbild_Post_Types::GALLERY;

$mixed_screen = lichtbild_test_screen( $screen );

$checks->assert(
	'a half-migrated site is told so and offered the way back',
	false !== strpos( $mixed_screen, 'The migration did not finish.' )
		&& false !== strpos( $mixed_screen, 'value="' . Lichtbild_Migration_Screen::ACTION_ROLLBACK . '"' )
		// The schema option still says unmigrated, so a screen that read the flag would be
		// showing the migrate form over the stranded rows. That is the defect this branch exists
		// to prevent, and it is the half worth asserting.
		&& ! $settings->has_migrated()
		&& false === strpos( $mixed_screen, 'value="' . Lichtbild_Migration_Screen::ACTION_MIGRATE . '"' ),
	'a mixed site was not offered the rollback'
);

$site->posts[ $stranded_id ]['post_type'] = Lichtbild_Repository::GALLERY_POST_TYPE;

// ============================================================================
// The URL slug scheme.
//
// Envira's paths are correct for exactly one kind of site and wrong for every other, so the
// default had to become generic without moving a single URL on a site that already publishes
// them. The property that makes that safe is that the answer is RECORDED rather than derived on
// demand: a site's Envira history is a fact that changes -- the records can be deleted years
// later -- while its published URLs must not.
//
// The fourth check is the one worth having. The first three could all pass against an
// implementation that re-derives every time, which would move 57 indexed URLs on the day
// somebody cleaned up an old meta key.
// ============================================================================

$checks->expect(
	'a site with envira records serves envira paths',
	'a site with no envira history serves generic paths',
	'a migrated site serves envira paths regardless of records',
	'the scheme is recorded, not re-derived'
);

$slug_paths = function ( $settings ) {
	$site             = Lichtbild_Test_Site::$instance;
	$site->registered = array();
	$args             = null;

	( new Lichtbild_Post_Types( $settings ) )->register_types();

	return $site->registered_args ?? array();
};

// 1. This fixture carries `_eg_gallery_data`, so it is an Envira-history site.
unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );
$checks->assert(
	'a site with envira records serves envira paths',
	'envira' === ( new Lichtbild_Settings() )->slug_scheme(),
	'scheme: ' . ( new Lichtbild_Settings() )->slug_scheme()
);

// 2. Strip every Envira record and the site becomes a fresh install.
$saved_galleries = $site->galleries;
$saved_albums    = $site->albums;
$saved_schema    = $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] ?? null;

foreach ( $site->galleries as $gid => $row ) {
	$site->galleries[ $gid ]['data'] = '';
}
foreach ( $site->albums as $aid => $row ) {
	$site->albums[ $aid ]['data'] = '';
}
unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );
unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );

$fresh = new Lichtbild_Settings();
$checks->assert(
	'a site with no envira history serves generic paths',
	'generic' === $fresh->slug_scheme()
		&& 'gallery' === $fresh->slug_scheme_paths()['gallery']
		&& 'album' === $fresh->slug_scheme_paths()['album']
		&& 'gallery-tag' === $fresh->slug_scheme_paths()['tag'],
	'scheme: ' . $fresh->slug_scheme() . ', paths: ' . wp_json_encode( $fresh->slug_scheme_paths() )
);

// 3. A migrated site kept no Envira records here, and must STILL serve Envira's paths: it was
//    serving them before it migrated, which is the whole reason the schema alone settles it.
unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );
$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = Lichtbild_Settings::SCHEMA_MIGRATED;

$checks->assert(
	'a migrated site serves envira paths regardless of records',
	'envira' === ( new Lichtbild_Settings() )->slug_scheme(),
	'scheme: ' . ( new Lichtbild_Settings() )->slug_scheme()
);

// 4. Pinning. Record `envira`, then remove every signal that would derive it, and the answer
//    must not move. A re-deriving implementation answers `generic` here.
$site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] = 'envira';
unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );

$checks->assert(
	'the scheme is recorded, not re-derived',
	'envira' === ( new Lichtbild_Settings() )->slug_scheme(),
	'scheme after removing every signal: ' . ( new Lichtbild_Settings() )->slug_scheme()
);

// 5. The state above -- scheme recorded, schema absent -- is every Envira site from the day
//    this plugin is installed until the day it migrates, because nothing writes schema 1 and
//    only the migration writes 2. In that state `initialise()` cannot resolve the schema from
//    the options, so it asks the posts table -- and it used to ask on EVERY predicate call:
//    measured at 17 uncached `COUNT(*)` queries for one minimal request. The answer cannot
//    change within a request, so it is asked at most once per instance. Priming and memoising
//    change no output, which is why the instrument is the stub's query counter.
$checks->expect( 'the schema question is asked at most once per request' );

$counted_settings = new Lichtbild_Settings();
$queries_before   = $GLOBALS['wpdb']->get_var_calls;

$counted_settings->has_migrated();
$counted_settings->slug_scheme();
$counted_settings->continues_envira();
$counted_settings->slug_scheme_paths();
$counted_settings->has_migrated();

$queries_asked = $GLOBALS['wpdb']->get_var_calls - $queries_before;

$checks->assert(
	'the schema question is asked at most once per request',
	$queries_asked <= 1,
	'five predicate calls on one instance issued ' . $queries_asked . ' get_var queries'
);

// A site that never had Envira starts on Lichtbild's own storage, and this is the check the whole
// change exists for.
//
// Schema 1 means "still on Envira's storage", not "new". Left at 1 a fresh install registered
// post types literally NAMED `envira`, and every editor screen refused to work — telling the
// owner their gallery was still in Envira's format on a site where no such record has ever
// existed. The only route to a first gallery was a migration of zero rows.
//
// The second half is the one that matters and the one a "does it say migrated" check would miss:
// what the site actually REGISTERS. `has_migrated()` returning true is a claim; registering
// `lichtbild_gallery` is the consequence anybody would notice.
$checks->expect(
	'a site that never had envira starts on lichtbild storage',
	'a fresh site registers lichtbild types, not envira ones',
	'an unmigrated envira site is not flipped by that'
);

foreach ( $site->galleries as $gid => $row ) {
	$site->galleries[ $gid ]['data'] = '';
}
foreach ( $site->albums as $aid => $row ) {
	$site->albums[ $aid ]['data'] = '';
}
unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );
unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );

$virgin = new Lichtbild_Settings();

$checks->assert(
	'a site that never had envira starts on lichtbild storage',
	$virgin->has_migrated() && 'generic' === $virgin->slug_scheme(),
	'has_migrated: ' . var_export( $virgin->has_migrated(), true ) . ', scheme: ' . $virgin->slug_scheme()
);

$site->registered = array();
( new Lichtbild_Post_Types( $virgin ) )->register_types();

$checks->assert(
	'a fresh site registers lichtbild types, not envira ones',
	in_array( Lichtbild_Post_Types::GALLERY, $site->registered, true )
		&& ! in_array( Lichtbild_Repository::GALLERY_POST_TYPE, $site->registered, true ),
	'registered: ' . implode( ', ', $site->registered )
);

// The control, and it is the half that stops the fix being a blunt "always migrated": a site
// that HAS Envira records and has not migrated must stay exactly where it is. Getting this
// wrong would tell 53 galleries' worth of live data that it had already moved.
$site->galleries = $saved_galleries;
$site->albums    = $saved_albums;
unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );
unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );

$waiting = new Lichtbild_Settings();

$checks->assert(
	'an unmigrated envira site is not flipped by that',
	! $waiting->has_migrated() && 'envira' === $waiting->slug_scheme(),
	'has_migrated: ' . var_export( $waiting->has_migrated(), true ) . ', scheme: ' . $waiting->slug_scheme()
);

// Deleting the plugin and installing it again must not hide the galleries it deliberately kept.
//
// `uninstall.php` removes the schema and the slug scheme while retaining the migrated posts,
// their v2 meta and Envira's `_eg_*` rollback records -- settings are a plugin's to delete,
// photographs are not, and its docblock promises reinstalling brings them back. It did not.
// With no schema, Envira's retained meta made the site look like one that had never migrated:
// `has_migrated()` answered false and `register_types()` registered `envira`, so every retained
// row -- still named `lichtbild_gallery` -- matched nothing. Measured on a full copy of the live
// site before the fix: 52 galleries in the table, 0 under the registered type, and the gallery
// permalink returning the same byte count as a deliberately bogus slug.
//
// The control for this is the check directly above: an Envira site with NO Lichtbild rows must
// still answer "not migrated" after the same two deletions. One of these two checks going red
// alone is meaningful; a fix that made both pass by always claiming migrated would redden that
// one, which is why they belong together.
$reinstall_posts = $site->posts;

foreach ( $site->posts as $rid => $row ) {
	if ( Lichtbild_Repository::GALLERY_POST_TYPE === ( $row['post_type'] ?? '' ) ) {
		$site->posts[ $rid ]['post_type'] = Lichtbild_Post_Types::GALLERY;
	}
	if ( 'envira_album' === ( $row['post_type'] ?? '' ) ) {
		$site->posts[ $rid ]['post_type'] = Lichtbild_Post_Types::ALBUM;
	}
}

unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );
unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );

$reinstalled = new Lichtbild_Settings();

$site->registered = array();
( new Lichtbild_Post_Types( $reinstalled ) )->register_types();

$checks->assert(
	'reinstalling after uninstall finds the content it kept',
	$reinstalled->has_migrated()
		&& in_array( Lichtbild_Post_Types::GALLERY, $site->registered, true )
		&& ! in_array( Lichtbild_Repository::GALLERY_POST_TYPE, $site->registered, true ),
	'has_migrated: ' . var_export( $reinstalled->has_migrated(), true )
		. ', registered: ' . implode( ', ', $site->registered )
);

// The literals in `Lichtbild_Settings::has_owned_content()` are hand-written, because reading
// them through `Lichtbild_Post_Types::gallery_type()` would ask `has_migrated()` and close a
// loop. A literal that drifts from the constant it copies would answer "no owned content" on a
// site full of galleries -- the same failure, by another route -- and no other check would see
// it, so the two are asserted against each other directly.
$owned_sql = file_get_contents( LICHTBILD_DIR . 'includes/class-lichtbild-settings.php' );

$checks->assert(
	'the owned-content query names the post types the plugin registers',
	false !== strpos( $owned_sql, "'" . Lichtbild_Post_Types::GALLERY . "', '" . Lichtbild_Post_Types::ALBUM . "'" ),
	'looked for the pair ' . Lichtbild_Post_Types::GALLERY . '/' . Lichtbild_Post_Types::ALBUM
);

$site->posts = $reinstall_posts;
unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );
unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );

// Making a fresh site "migrated" hands it a rollback it must never be offered: there is no
// Envira installation behind it, so rolling back would move the owner's galleries onto post
// types named after a plugin they have never installed. Refused in the handler, because the
// screen not drawing a button is not a rule.
$checks->expect( 'a site with no envira history cannot roll back' );

foreach ( $site->galleries as $gid => $row ) {
	$site->galleries[ $gid ]['data'] = '';
}
foreach ( $site->albums as $aid => $row ) {
	$site->albums[ $aid ]['data'] = '';
}
unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );
unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );

$fresh_rollback = ( new Lichtbild_Migration( new Lichtbild_Settings() ) )->rollback();

$checks->assert(
	'a site with no envira history cannot roll back',
	0 === $fresh_rollback['galleries'] && 0 === $fresh_rollback['albums']
		&& count( $fresh_rollback['errors'] ) > 0,
	'moved ' . $fresh_rollback['galleries'] . '/' . $fresh_rollback['albums']
		. ', errors: ' . implode( ' | ', $fresh_rollback['errors'] )
);

$site->galleries = $saved_galleries;
$site->albums    = $saved_albums;
unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );
unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );

$site->galleries = $saved_galleries;
$site->albums    = $saved_albums;
if ( null !== $saved_schema ) {
	$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = $saved_schema;
} else {
	unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );
}
$site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] = 'envira';

// Registration before migration: Lichtbild must stand aside while Envira is the one registering
// these names, or the second call silently wins and the two plugins fight over the type.
$site->registered = array();
( new Lichtbild_Post_Types( $settings ) )->register_types();

$checks->assert(
	'an unmigrated site registers envira\'s types',
	in_array( Lichtbild_Repository::GALLERY_POST_TYPE, $site->registered, true ),
	'registered: ' . implode( ', ', $site->registered )
);

// The album submenu has to hang off the gallery type registered on *this* request. Naming the
// post-migration type unconditionally attaches it to a type that does not exist yet, and
// WordPress then drops the menu entry entirely.
$checks->assert(
	'the album menu hangs off the type that exists',
	'edit.php?post_type=' . Lichtbild_Repository::GALLERY_POST_TYPE === ( $site->menu_parents[ Lichtbild_Repository::ALBUM_POST_TYPE ] ?? '' ),
	'album parent: ' . wp_json_encode( $site->menu_parents[ Lichtbild_Repository::ALBUM_POST_TYPE ] ?? null )
);

// The Envira-is-active guard is checked last because declaring the class cannot be undone.
//
// Declared through eval() rather than written out, and that is not a style choice: PHP hoists
// an unconditional class declaration to compile time, so `class Envira_Gallery {}` written
// here would exist from the first line of the file — the migration above would have refused,
// and these two checks would have passed for a reason that had nothing to do with ordering.
// It is the failure mode a guard test cannot afford, because a refusal is what it asserts.
eval( 'class Envira_Gallery {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

$checks->assert(
	'migration refuses while envira is active',
	! empty( $migration->migrate()['errors'] ),
	'the migration ran with Envira active'
);

$checks->assert(
	'a refused migration changes nothing',
	'envira' === get_post_type( array_key_first( $site->galleries ) ),
	'a refused migration renamed rows anyway'
);

// With Envira active and nothing migrated, Lichtbild must not register anything at all.
$site->registered = array();
( new Lichtbild_Post_Types( $settings ) )->register_types();

$checks->assert(
	'an unmigrated site stands aside for envira',
	array() === $site->registered,
	'registered alongside Envira: ' . implode( ', ', $site->registered )
);

// But once the data has moved, standing aside would leave `lichtbild_gallery` registered by
// nobody — which takes every gallery, album and tag URL off the site rather than merely
// deferring the shortcode. Envira registering `envira` alongside is harmless by then.
$site->options[ Lichtbild_Settings::OPTION_SCHEMA ] = Lichtbild_Settings::SCHEMA_MIGRATED;
$site->registered                                = array();
$site->rewrite_slugs                             = array();
( new Lichtbild_Post_Types( $settings ) )->register_types();

$checks->assert(
	'a migrated site registers its types even with envira active',
	in_array( Lichtbild_Post_Types::GALLERY, $site->registered, true )
		&& in_array( Lichtbild_Post_Types::ALBUM, $site->registered, true )
		&& in_array( Lichtbild_Post_Types::TAG, $site->registered, true ),
	'registered: ' . implode( ', ', $site->registered )
);

// And the paths stay Envira's in both directions, because they are indexed and canonical.
$checks->assert(
	'registration keeps envira url paths',
	'envira' === ( $site->rewrite_slugs[ Lichtbild_Post_Types::GALLERY ] ?? '' )
		&& 'envira_album' === ( $site->rewrite_slugs[ Lichtbild_Post_Types::ALBUM ] ?? '' )
		&& 'envira-tag' === ( $site->rewrite_slugs[ Lichtbild_Post_Types::TAG ] ?? '' ),
	'slugs: ' . wp_json_encode( $site->rewrite_slugs )
);

unset( $site->options[ Lichtbild_Settings::OPTION_SCHEMA ] );


// ============================================================================
// Password-protected galleries.
//
// WordPress enforces a post password by replacing `post_content` — and a gallery keeps its
// images in post meta, so that protection reaches none of them. Both places that can publish
// a gallery without the post's own content therefore have to check for themselves. Neither
// class had a single check before this; the hole was found by looking at the live database
// during a deployment pre-flight, where exactly one gallery is protected this way.
// ============================================================================

$checks->expect(
	'a gallery is appended to its own page',
	'an album is appended to its own page',
	'standalone defers to envira on the gallery\'s own page',
	'a password-protected gallery is not appended to its page',
	'a gallery that is not public is not appended to its page',
	'the ajax endpoints serve a public gallery',
	'the ajax endpoints refuse a password-protected gallery',
	'an array-shaped tag reads as no tag',
	'an album omits a password-protected gallery',
	'an album shows a gallery nobody protected',
	'an album omits a gallery that is not public',
	'the lightbox endpoint primes its attachments in one call'
);

// Chosen by the property these checks depend on rather than by whichever gallery sorts first:
// every assertion below starts from "this one renders for an anonymous visitor", and the first
// key of `$pre_migration` is only publish by luck. The fixture holds one private and two draft
// galleries, so the luck would run out on a differently-ordered export.
$locked_id = 0;

foreach ( array_keys( $pre_migration ) as $candidate ) {
	if ( 'publish' === $site->galleries[ $candidate ]['status'] ) {
		$locked_id = $candidate;

		break;
	}
}

if ( 0 === $locked_id ) {
	fwrite( STDERR, "[ERROR] fixture holds no published gallery with items\n" );
	fwrite( STDERR, "        the standalone and endpoint checks cannot run against it\n" );
	exit( 2 );
}

$standalone = new Lichtbild_Standalone( new Lichtbild_Repository(), $renderer, $settings );

$site->options[ Lichtbild_Settings::OPTION_STANDALONE ] = 1;
$site->current_post = $locked_id;
$site->protected    = array();

// Envira is active from here on (the eval above), which is exactly the state a fresh install
// is in. On `auto` Lichtbild must stand aside on the gallery's own permalink too, not only for
// the shortcode -- Envira has a standalone filter of its own, so both running means the page
// renders the gallery twice. That was live for about a minute before the deployment control
// caught it.
$site->options['lichtbild_takeover'] = 'auto';

$checks->assert(
	'standalone defers to envira on the gallery\'s own page',
	'CONTENT' === $standalone->insert( 'CONTENT' ),
	'appended a second copy of the gallery alongside Envira\'s'
);

// The rest of this section is about what Lichtbild does when it *is* the one rendering.
$site->options['lichtbild_takeover'] = 'always';

// The control. "Nothing was appended" is also what a standalone renderer that does not work
// at all produces, so the guard below proves nothing until this has been seen to append.
$open = $standalone->insert( 'CONTENT' );

$checks->assert(
	'a gallery is appended to its own page',
	0 === strpos( $open, 'CONTENT' ) && strlen( $open ) > strlen( 'CONTENT' ),
	'the control appended nothing, so the guard below is untested'
);

$site->protected = array( $locked_id );

$closed = $standalone->insert( 'PASSWORD FORM' );

$checks->assert(
	'a password-protected gallery is not appended to its page',
	'PASSWORD FORM' === $closed,
	sprintf( 'published %d bytes of gallery below the password form', strlen( $closed ) - strlen( 'PASSWORD FORM' ) )
);

// The predicate's other leg, through this path. WordPress would not serve a draft page to a
// visitor at all, so in production this is belt to core's braces — but that impossibility lives
// in core's query rather than in `Lichtbild_Standalone`, and a guard whose only defence is another
// module's behaviour is one nobody notices losing. Here the filter is called directly, so the
// leg can be made to fail.
$standalone_status = $site->galleries[ $locked_id ]['status'];
$standalone_caps   = $site->capabilities;

$site->protected                         = array();
$site->galleries[ $locked_id ]['status'] = 'draft';
$site->capabilities                      = false;

$draft_page = $standalone->insert( 'CONTENT' );

// Both knobs put back. Everything downstream of here shares one mutable site object, so a check
// that leaves one flipped decides an unrelated check's answer somewhere below it.
$site->galleries[ $locked_id ]['status'] = $standalone_status;
$site->capabilities                      = $standalone_caps;

$checks->assert(
	'a gallery that is not public is not appended to its page',
	'CONTENT' === $draft_page,
	sprintf( 'published %d bytes of a draft gallery', strlen( $draft_page ) - strlen( 'CONTENT' ) )
);

// And the endpoints, which are reachable by anyone who knows the gallery ID whether or not
// the page was ever rendered.
$ajax = new Lichtbild_Ajax( new Lichtbild_Repository(), $renderer );

/**
 * Calls an AJAX endpoint and returns what it sent.
 *
 * @param Lichtbild_Ajax $ajax   The handler.
 * @param string      $method Endpoint method name.
 *
 * @return array{halt:string,body:string} How it ended, and the JSON it emitted.
 */
$call_ajax = function ( Lichtbild_Ajax $ajax, $method ) {
	ob_start();
	$halt = '';

	try {
		$ajax->$method();
	} catch ( Lichtbild_Test_Halt $e ) {
		$halt = $e->getMessage();
	}

	return array( 'halt' => $halt, 'body' => (string) ob_get_clean() );
};

$_REQUEST['gallery'] = $locked_id;
$site->protected     = array();

$public_call = $call_ajax( $ajax, 'handle_items' );
$decoded     = json_decode( $public_call['body'], true );

$checks->assert(
	'the ajax endpoints serve a public gallery',
	'success' === $public_call['halt'] && ! empty( $decoded['data']['items'] ),
	'the control returned: ' . substr( $public_call['body'], 0, 120 )
);

$site->protected = array( $locked_id );

$locked_call = $call_ajax( $ajax, 'handle_items' );
$locked_page = $call_ajax( $ajax, 'handle_page' );

$checks->assert(
	'the ajax endpoints refuse a password-protected gallery',
	'error 404' === $locked_call['halt'] && 'error 404' === $locked_page['halt'],
	'items said "' . $locked_call['halt'] . '", page said "' . $locked_page['halt'] . '"'
);

// --- the nonce, which must not be able to refuse -----------------------------------------
//
// The two checks above already ran without a nonce, and passed -- but for an unstated reason,
// which is the same as no reason at all: nothing said whether they passed because the endpoint
// tolerates that or because the harness did. It was the harness until the stub above was made
// to model WordPress, and this pins the behaviour rather than the accident.
//
// A nonce says when a page was generated and expires twelve hours later, while a full-page
// cache serves pages generated days ago. So on a cached site the nonce a logged-out visitor
// holds is *routinely* stale, and refusing on it would break pagination and filtering at an
// unpredictable hour with no error anyone could act on. A wrong nonce is used here rather than
// an absent one because it is the state a real cached page produces -- present, well-formed,
// and no longer valid.
$site->protected     = array();
$_REQUEST['nonce']   = 'nonce:lichtbild-generated-before-the-tick-rolled';

$stale_items = $call_ajax( $ajax, 'handle_items' );
$stale_page  = $call_ajax( $ajax, 'handle_page' );
$stale_data  = json_decode( $stale_items['body'], true );

$checks->assert(
	'a stale nonce does not stop a cached page loading its gallery',
	'success' === $stale_items['halt'] && 'success' === $stale_page['halt']
		&& ! empty( $stale_data['data']['items'] ),
	'items said "' . $stale_items['halt'] . '", page said "' . $stale_page['halt'] . '"'
);

// And the half that makes the above safe rather than merely convenient. Not refusing on the
// nonce is only defensible while the authorization is somewhere else entirely, so the same
// unusable nonce must still get nowhere near a gallery it may not see. Without this the check
// above reads as "the gate was removed" instead of "the gate was never the one holding".
$site->protected = array( $locked_id );

$stale_locked = $call_ajax( $ajax, 'handle_items' );

$checks->assert(
	'a stale nonce does not lift the authorization it never carried',
	'error 404' === $stale_locked['halt']
		&& false === strpos( $stale_locked['body'], '"items":[{' ),
	'the protected gallery answered "' . $stale_locked['halt'] . '"'
);

unset( $_REQUEST['nonce'] );

// The other thing a logged-out visitor fully controls is the *shape* of a parameter, and
// `tag[]=x` gets past both gates above before anything looks at it. `sanitize_title()` reaches
// a `preg_match()` on the value, which is a TypeError on PHP 8 -- an uncaught fatal and an
// HTTP 500 on a public endpoint, repeatable at will. It has to read as no tag.
//
// A fatal is captured as data rather than left to propagate: it would otherwise end the run
// before the summary line, and the mutation restoring the bare call would then be reported
// BROKEN -- a verdict about the harness -- instead of killed.
$site->protected = array();

/**
 * Calls an endpoint and reports a fatal as data rather than ending the run.
 *
 * @param Lichtbild_Ajax $ajax   The handler.
 * @param string      $method Endpoint method name.
 *
 * @return array{halt:string,body:string,fatal:string} How it ended, what it sent, what it threw.
 */
$call_ajax_hard = function ( Lichtbild_Ajax $ajax, $method ) {
	ob_start();
	$halt  = '';
	$fatal = '';

	try {
		$ajax->$method();
	} catch ( Lichtbild_Test_Halt $stopped ) {
		$halt = $stopped->getMessage();
	} catch ( Throwable $thrown ) {
		$fatal = get_class( $thrown ) . ': ' . $thrown->getMessage();
	}

	return array( 'halt' => $halt, 'body' => (string) ob_get_clean(), 'fatal' => $fatal );
};

// The control, and the thing the array case is compared against: "no tag" is a specific
// answer, not merely the absence of a crash. Without it the assertion below is satisfied by
// an endpoint that filtered the gallery down to nothing quite calmly.
unset( $_REQUEST['tag'] );

$no_tag_page = $call_ajax_hard( $ajax, 'handle_page' );

$_REQUEST['tag'] = array( 'x' );

$tag_diagnostics = array();

set_error_handler(
	static function ( $number, $message ) use ( &$tag_diagnostics ) {
		$tag_diagnostics[] = $message;

		return true;
	}
);

$array_tag_page  = $call_ajax_hard( $ajax, 'handle_page' );
$array_tag_items = $call_ajax_hard( $ajax, 'handle_items' );

restore_error_handler();

unset( $_REQUEST['tag'] );

$checks->assert(
	'an array-shaped tag reads as no tag',
	'' === $array_tag_page['fatal']
		&& '' === $array_tag_items['fatal']
		&& array() === $tag_diagnostics
		&& 'success' === $array_tag_page['halt']
		&& 'success' === $array_tag_items['halt']
		&& $no_tag_page['body'] === $array_tag_page['body'],
	'page: "' . $array_tag_page['halt'] . '" ' . $array_tag_page['fatal']
		. ', items: "' . $array_tag_items['halt'] . '" ' . $array_tag_items['fatal']
		. ', diagnostics: ' . implode( '; ', $tag_diagnostics )
		. ', body matched the untagged control: ' . ( $no_tag_page['body'] === $array_tag_page['body'] ? 'yes' : 'no' )
);

// Albums have the same shape of problem and were missed: an album keeps its galleries in post
// meta too, so `/envira_album/<slug>/` answered 200 and rendered nothing. Envira's albums
// addon does render those pages, so leaving this out is a regression, not a gap — and it went
// live on two URLs before a comparison against the un-switched site caught it.
// Chosen by a property independent of what is being checked -- an album that *has* galleries
// -- rather than by the first one that loads. The fixture's first album holds none and
// renders nothing quite correctly, which failed this check for a reason that was about the
// specimen and not about the code.
$album_id = 0;

foreach ( array_keys( $site->albums ) as $candidate ) {
	$candidate_album = ( new Lichtbild_Repository() )->album( $candidate );

	if ( null !== $candidate_album && $candidate_album->count() > 0 ) {
		$album_id = $candidate;

		break;
	}
}

if ( $album_id > 0 ) {
	$site->current_post = $album_id;
	$appended           = $standalone->insert( 'CONTENT' );

	$checks->assert(
		'an album is appended to its own page',
		0 === strpos( $appended, 'CONTENT' ) && strlen( $appended ) > strlen( 'CONTENT' ),
		'an album permalink rendered nothing but the theme'
	);

	// An album is the third place that publishes gallery content, and the last to learn the
	// rule the two above it already enforce. The cover, the title and the image count all come
	// from post meta and the gallery row, so a post password — which WordPress applies to
	// `post_content` — reaches none of them. The gallery's own permalink refuses it and the
	// AJAX endpoints refuse it; the album cover grid published it.
	//
	// Asserted on the member's *title*, because that is content rather than a length: an
	// earlier version of the album check compared `strlen()` against the input and passed just
	// as happily on markup that leaked.
	$album_members = lichtbild_album_found( $checks, ( new Lichtbild_Repository() )->album( $album_id ), "album #{$album_id} unreadable through the envira path" )->gallery_ids();
	$victim        = 0;

	foreach ( $album_members as $member ) {
		$member_gallery = ( new Lichtbild_Repository() )->gallery( $member );

		if ( null !== $member_gallery && $member_gallery->count() > 0 && '' !== get_the_title( $member ) ) {
			$victim = $member;

			break;
		}
	}

	if ( $victim > 0 ) {
		$victim_title = get_the_title( $victim );

		$site->protected = array();
		$public_album    = lichtbild_render_album_found( $checks, $renderer, ( new Lichtbild_Repository() )->album( $album_id ), new Lichtbild_Repository(), "album #{$album_id} unreadable through the envira path" );

		$site->protected = array( $victim );
		$locked_album    = lichtbild_render_album_found( $checks, $renderer, ( new Lichtbild_Repository() )->album( $album_id ), new Lichtbild_Repository(), "album #{$album_id} unreadable through the envira path" );

		$site->protected = array();

		$checks->assert(
			'an album omits a password-protected gallery',
			false === strpos( $locked_album, $victim_title ),
			'gallery ' . $victim . ' ("' . $victim_title . '") was published on an album page'
		);

		// The control. Without it the assertion above is satisfied by an album that renders
		// nothing at all — which is exactly what a mutation gutting the loop would produce.
		$checks->assert(
			'an album shows a gallery nobody protected',
			false !== strpos( $public_album, $victim_title ),
			'the unprotected control did not render gallery ' . $victim
		);

		// The other half of the guard, and not covered by the password leg: a protected post is
		// `publish`, and a draft one carries no password, so neither check implies the other.
		// `Lichtbild_Repository::resolve_id()` matches a numeric ID on post type alone.
		$victim_status = $site->galleries[ $victim ]['status'];

		$site->galleries[ $victim ]['status'] = 'draft';
		$site->capabilities                   = false;

		$draft_album = lichtbild_render_album_found( $checks, $renderer, ( new Lichtbild_Repository() )->album( $album_id ), new Lichtbild_Repository(), "album #{$album_id} unreadable through the envira path" );

		$site->galleries[ $victim ]['status'] = $victim_status;
		$site->capabilities                   = true;

		$checks->assert(
			'an album omits a gallery that is not public',
			false === strpos( $draft_album, $victim_title ),
			'draft gallery ' . $victim . ' was published on an album page'
		);
	}
}

$site->current_post = 0;
$site->protected    = array();

// ============================================================================
// Cache priming.
//
// Reading an item touches the attachment row, its meta and its terms — three queries each,
// unprimed. The lightbox endpoint walks every item in the gallery, so on the 504-image gallery
// that was roughly fifteen hundred queries in one request while a visitor waited.
//
// This is the awkward kind of fix to test: priming changes no output whatsoever, so deleting
// it leaves every other check green. What makes it checkable is asserting the *shape* of the
// call — one call covering every attachment, rather than none, and rather than one per image.
// ============================================================================

$prime_target = 0;
$prime_size   = 0;

foreach ( array_keys( $site->galleries ) as $candidate ) {
	$candidate_gallery = ( new Lichtbild_Repository() )->gallery( $candidate );

	if ( null !== $candidate_gallery && $candidate_gallery->count() > $prime_size ) {
		$prime_size   = $candidate_gallery->count();
		$prime_target = $candidate;
	}
}

if ( $prime_target > 0 ) {
	$_REQUEST['gallery'] = $prime_target;
	$_REQUEST['nonce']   = 'lichtbild';

	$site->primed = array();

	// Captured, not merely halted. The endpoint sends its JSON before throwing, so calling it
	// without an output buffer printed the whole lightbox payload -- 80 KB of it -- above the
	// report. It never showed up here because this suite is always read through `tail` or
	// `grep`, which is the same reason a suite that discards its output cannot test its output.
	ob_start();

	try {
		( new Lichtbild_Ajax( new Lichtbild_Repository(), $renderer ) )->handle_items();
	} catch ( Exception $stopped ) {
		unset( $stopped );
	}

	ob_end_clean();

	$primed_ids = empty( $site->primed ) ? array() : $site->primed[0]['ids'];

	$checks->assert(
		'the lightbox endpoint primes its attachments in one call',
		1 === count( $site->primed ) && count( $primed_ids ) > 1,
		sprintf(
			'gallery %d has %d items; priming calls: %d, ids in the first: %d',
			$prime_target,
			$prime_size,
			count( $site->primed ),
			count( $primed_ids )
		)
	);

	$site->primed = array();
}

// ============================================================================
// Early enqueue, and whose shortcodes it answers to.
//
// The early scan exists so the stylesheet reaches the head rather than the footer. It used to
// match `[envira-gallery]` unconditionally, which meant that with both plugins active and the
// takeover on `auto` — the state a fresh install is in, and the one the setting exists for —
// Lichtbild loaded a stylesheet and two scripts onto a page Envira was rendering. About 940 bytes
// and three requests on the live site, no visual change, and enough to make "install Lichtbild and
// change nothing" false.
//
// Both directions are asserted. "It did not enqueue" is also what a scan that matches nothing
// at all produces, and that is precisely what the fix could have broken.
// ============================================================================

$checks->expect(
	'the early scan claims envira shortcodes only while taking over',
	'the early scan still claims its own shortcodes'
);

$site->current_post               = $locked_id;
$site->post_content[ $locked_id ] = 'text [envira-gallery id="' . $locked_id . '"] more text';

/**
 * Runs the early scan under one takeover mode and reports whether it enqueued.
 *
 * A fresh handler each time: `need_gallery()` latches, so a single instance would carry the
 * first case's answer into the second.
 *
 * @param string $mode Takeover mode to run under.
 *
 * @return bool Whether the stylesheet was enqueued.
 */
$scan_enqueues = function ( $mode ) {
	$site = Lichtbild_Test_Site::$instance;

	$site->options['lichtbild_takeover'] = $mode;
	$GLOBALS['lichtbild_test_enqueued']  = array();

	( new Lichtbild_Assets( new Lichtbild_Settings() ) )->maybe_enqueue_early();

	return isset( $GLOBALS['lichtbild_test_enqueued']['style:lichtbild'] );
};

$aside = $scan_enqueues( 'never' );

$checks->assert(
	'the early scan claims envira shortcodes only while taking over',
	false === $aside,
	'loaded assets onto a page Envira is the one rendering'
);

$claimed = $scan_enqueues( 'always' );

$checks->assert(
	'the early scan claims envira shortcodes only while taking over',
	true === $claimed,
	'stood aside from an envira shortcode it was configured to take over'
);

// The control for the control: our own shortcode is never conditional, so a scan that had simply
// stopped matching anything would pass the `never` case above and fail here.
$site->post_content[ $locked_id ] = 'text [lichtbild-gallery id="' . $locked_id . '"] more text';

$checks->assert(
	'the early scan still claims its own shortcodes',
	true === $scan_enqueues( 'never' ),
	'[lichtbild-gallery] is ours in every mode, and the scan missed it'
);

$site->current_post              = 0;
$GLOBALS['lichtbild_test_enqueued'] = array();

unset( $site->post_content[ $locked_id ] );

// ============================================================================
// The shortcode registry, and the container that wires everything.
//
// Both classes were loaded by this suite for the first time in 26.8.3, because until the
// harness-completeness guard above existed nothing noticed they were absent. That is worth
// stating plainly: `Lichtbild_Shortcode` is a publishing path -- the one that deliberately does not
// consult the visibility predicate -- and it had no coverage at all, while `Lichtbild` is where
// every constructor signature in the plugin is actually called.
//
// Requiring the files without exercising them would have satisfied the guard and covered
// nothing, which is the same empty-filter-reads-as-a-pass trap this file is full of.
// ============================================================================

$checks->expect(
	'the shortcode registry follows the takeover setting',
	'the gallery shortcode renders its gallery',
	'a shortcode naming nothing renders nothing',
	'the container constructs and registers its hooks'
);

/**
 * Registers the shortcodes under one takeover mode and reports which tags Lichtbild claimed.
 *
 * The slug scheme is restored afterwards, and restoring it means *unsetting* it when it was
 * unset: `slug_scheme()` records its answer on first read, so a call made to observe the site
 * also changes it, and a later check reading the option would see this closure's work rather
 * than the fixture's.
 *
 * @param string      $mode   Takeover mode.
 * @param string|null $scheme Slug scheme to force, or null to leave the site's own.
 *
 * @return string[] Claimed tags, sorted.
 */
$claimed_tags = function ( $mode, $scheme = null ) use ( $renderer ) {
	$site = Lichtbild_Test_Site::$instance;

	$before = $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] ?? null;

	if ( null !== $scheme ) {
		$site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] = $scheme;
	}

	$site->options['lichtbild_takeover']   = $mode;
	$GLOBALS['lichtbild_test_shortcodes'] = array();

	$settings = new Lichtbild_Settings();
	( new Lichtbild_Shortcode( new Lichtbild_Repository(), $renderer, $settings ) )->register_shortcodes();

	$tags = array_keys( $GLOBALS['lichtbild_test_shortcodes'] );
	sort( $tags );

	if ( null === $before ) {
		unset( $site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] );
	} else {
		$site->options[ Lichtbild_Settings::OPTION_SLUG_SCHEME ] = $before;
	}

	return $tags;
};

$never  = $claimed_tags( 'never' );
$always = $claimed_tags( 'always' );

$checks->assert(
	'the shortcode registry follows the takeover setting',
	array( 'lichtbild-album', 'lichtbild-gallery' ) === $never,
	'on "never" it claimed: ' . implode( ', ', $never )
);

// The control. "It did not claim envira's tags" is also true of a registry that claims nothing,
// which is exactly what a mutation gutting register_shortcodes() produces.
//
// The expected array is in `sort()` order, not in the order the tags are registered, and that
// distinction had never mattered until the plugin was renamed: under the old name the two orders
// happened to coincide, so the literal could be read either way and nobody had to decide. `lichtbild`
// sorts BEFORE `envira` where the old name sorted after, and the mechanical rename -- correctly --
// left the order alone, so this check went red. It was the only one of 220 that did.
//
// Worth keeping as a note rather than fixing "properly" by sorting the expectation too: a literal
// that encodes a DERIVED property is exactly what a rename cannot update, and the red was the
// system working. The alternative -- comparing sets -- would have been blind to it and blind to a
// real ordering regression as well.
$checks->assert(
	'the shortcode registry follows the takeover setting',
	array( 'envira-album', 'envira-gallery', 'lichtbild-album', 'lichtbild-gallery' ) === $always,
	'on "always" it claimed: ' . implode( ', ', $always )
);

// The takeover mode answers "is Envira running?", which on a site that never had Envira is a
// question with a misleading answer: no, so take over — and the site gets another plugin's tag
// registered for a shortcode that appears in none of its posts. The slug scheme is the second
// half, and it is the same observation the URL paths are pinned to.
//
// `always` for both legs deliberately, because it is the mode most likely to claim the tags: a
// generic site refusing under `always` cannot be a generic site refusing because it was not
// asked. And the envira leg below is the control — "claimed no envira tags" is equally true of a
// registry that claimed nothing at all, which is what a gutted register_shortcodes() produces.
$generic_scheme = $claimed_tags( 'always', 'generic' );
$envira_scheme  = $claimed_tags( 'always', 'envira' );

$checks->assert(
	'a site with no envira history claims no envira shortcodes',
	array( 'lichtbild-album', 'lichtbild-gallery' ) === $generic_scheme,
	'on a generic site with takeover "always" it claimed: ' . implode( ', ', $generic_scheme )
);

$checks->assert(
	'a site with an envira history still claims them',
	array( 'envira-album', 'envira-gallery', 'lichtbild-album', 'lichtbild-gallery' ) === $envira_scheme,
	'on an envira site with takeover "always" it claimed: ' . implode( ', ', $envira_scheme )
);

$shortcode = new Lichtbild_Shortcode( new Lichtbild_Repository(), $renderer, new Lichtbild_Settings() );
$rendered  = $shortcode->gallery( array( 'id' => (string) $locked_id ) );

$checks->assert(
	'the gallery shortcode renders its gallery',
	false !== strpos( $rendered, 'class="lichtbild-wrap"' )
		&& false !== strpos( $rendered, 'id="lichtbild-' . $locked_id . '-wrap"' ),
	sprintf( 'shortcode for gallery %d produced %d bytes', $locked_id, strlen( $rendered ) )
);

// Both arms, because "empty" is the failure mode of a shortcode that cannot find anything at
// all -- so a check that only asserts emptiness would pass on a completely broken renderer.
$checks->assert(
	'a shortcode naming nothing renders nothing',
	'' === $shortcode->gallery( array() ) && '' === $shortcode->gallery( array( 'id' => '99999999' ) ),
	'an absent id or an unknown one produced markup'
);

// --- the fourth publishing path, closed in 26.8.6 --------------------------------------
//
// It was the last one that could put a gallery in front of a visitor without asking whether
// that visitor may see it. The control above is what makes these mean anything: the same
// shortcode, on the same gallery, renders when nothing is protecting it.
$checks->expect(
	'the shortcode refuses a password-protected gallery',
	'the shortcode refuses a gallery that is not public',
	'the album shortcode refuses a protected album'
);

$site->protected = array( $locked_id );

$protected_shortcode = $shortcode->gallery( array( 'id' => (string) $locked_id ) );

$site->protected = array();

$checks->assert(
	'the shortcode refuses a password-protected gallery',
	'' === $protected_shortcode,
	'a protected gallery produced ' . strlen( $protected_shortcode ) . ' bytes through the shortcode'
);

// The status leg, which is a different gate: a protected post is `publish`, so the password
// check alone would let a draft through and vice versa.
$shortcode_status        = $site->galleries[ $locked_id ]['status'];
$shortcode_caps          = $site->capabilities;
$site->galleries[ $locked_id ]['status'] = 'draft';
$site->capabilities                      = false;
$site->build_tables();

$draft_shortcode = ( new Lichtbild_Shortcode( new Lichtbild_Repository(), $renderer, new Lichtbild_Settings() ) )
	->gallery( array( 'id' => (string) $locked_id ) );

$site->galleries[ $locked_id ]['status'] = $shortcode_status;
$site->capabilities                      = $shortcode_caps;
$site->build_tables();

$checks->assert(
	'the shortcode refuses a gallery that is not public',
	'' === $draft_shortcode,
	'a draft gallery produced ' . strlen( $draft_shortcode ) . ' bytes through the shortcode'
);

// And the album handler checks the ALBUM. The renderer checks each member separately; neither
// implies the other, and only one of them is in this file.
$shortcode_album = 0;

foreach ( array_keys( $site->albums ) as $album_candidate ) {
	if ( null !== ( new Lichtbild_Repository() )->album( (int) $album_candidate ) ) {
		$shortcode_album = (int) $album_candidate;

		break;
	}
}

if ( $shortcode_album > 0 ) {
	$album_open = $shortcode->album( array( 'id' => (string) $shortcode_album ) );

	$site->protected = array( $shortcode_album );

	$album_locked = ( new Lichtbild_Shortcode( new Lichtbild_Repository(), $renderer, new Lichtbild_Settings() ) )
		->album( array( 'id' => (string) $shortcode_album ) );

	$site->protected = array();

	$checks->assert(
		'the album shortcode refuses a protected album',
		'' === $album_locked && '' !== $album_open,
		'protected produced ' . strlen( $album_locked ) . ' bytes, unprotected ' . strlen( $album_open )
	);
}

// ============================================================================
// The blocks, which are the fifth publishing path -- and render none of it themselves.
//
// There is exactly one property worth asserting about `Lichtbild_Block`, and it is stated as an
// equality rather than as a list of behaviours: what a block renders is byte-for-byte what the
// shortcode renders. Everything the four earlier paths had to be taught separately -- the
// password gate, the status gate, the empty answer for a row that is not there -- follows from
// that one fact, and cannot drift from it, because there is no second implementation to drift.
//
// Every equality below carries the guard the 26.8.10 round trips needed: the shortcode's own
// answer must be non-empty. Two absent renders compare equal, so a block that returned nothing
// at all would satisfy an unguarded comparison on every gallery on the site.
// ============================================================================

$checks->expect(
	'both blocks are registered from their metadata',
	'the block renders exactly what the shortcode renders',
	'a block naming nothing renders nothing',
	'the block refuses a password-protected gallery',
	'the block refuses a gallery that is not public',
	'the editor script carries the picker data',
	'registering the blocks reads nothing',
	'the editor stylesheet is laid out by the front-end one',
	'the front-end assets are registered on init',
	'the picker offers the galleries the reader can read',
	'the picker offers the albums the reader can read'
);

$GLOBALS['lichtbild_test_blocks']     = array();
$GLOBALS['lichtbild_test_inline']     = array();
$GLOBALS['lichtbild_test_registered'] = array(
	'style'  => array(),
	'script' => array(),
);
$GLOBALS['lichtbild_test_hooks']      = array();

// Both halves of what `init` does in production, in the order `Lichtbild::boot()` puts them in.
// Registering the blocks alone would leave `lichtbild` unregistered and the dependency check below
// would fail for a reason that has nothing to do with the blocks.
$block_assets = new Lichtbild_Test_Assets( new Lichtbild_Settings() );
$block_assets->register();
$block_assets->register_assets();

$block = new Lichtbild_Block( $shortcode, new Lichtbild_Repository(), new Lichtbild_Settings() );

// Registration on its own must read nothing. It runs on `init`, so on every request including
// every front-end page view -- and building the picker's choices reads each gallery row through
// the reader, measured at 111 queries and 11ms on the live site's cold cache for data only the
// block editor looks at. It cost nothing visible: no rendered byte changes either way, which is
// why the only way to state it is as a count. Found by measuring before deploying, not by a
// check, and this is the check.
$GLOBALS['lichtbild_test_queries'] = 0;

$block->register();
$block->register_blocks();

$register_reads = $GLOBALS['lichtbild_test_queries'];

$block->enqueue_editor_data();

$editor_reads = $GLOBALS['lichtbild_test_queries'] - $register_reads;

$block_meta  = json_decode( (string) file_get_contents( LICHTBILD_DIR . 'blocks/gallery/block.json' ), true );
$album_meta  = json_decode( (string) file_get_contents( LICHTBILD_DIR . 'blocks/album/block.json' ), true );
$registered  = $GLOBALS['lichtbild_test_blocks'];

$checks->assert(
	'both blocks are registered from their metadata',
	isset( $registered['lichtbild/gallery'], $registered['lichtbild/album'] )
		&& 'number' === ( $registered['lichtbild/gallery']['attributes']['id']['type'] ?? '' )
		&& 'number' === ( $registered['lichtbild/album']['attributes']['id']['type'] ?? '' )
		&& is_callable( $registered['lichtbild/gallery']['render_callback'] ?? null )
		&& is_callable( $registered['lichtbild/album']['render_callback'] ?? null ),
	'registered: ' . ( empty( $registered ) ? '(nothing)' : implode( ', ', array_keys( $registered ) ) )
);

$block_markup     = $block->render_gallery( array( 'id' => $locked_id ) );
$shortcode_markup = $shortcode->gallery( array( 'id' => (string) $locked_id ) );

$checks->assert(
	'the block renders exactly what the shortcode renders',
	'' !== $shortcode_markup && $block_markup === $shortcode_markup,
	sprintf( 'block produced %d bytes, shortcode %d', strlen( $block_markup ), strlen( $shortcode_markup ) )
);

if ( $shortcode_album > 0 ) {
	$album_block     = $block->render_album( array( 'id' => $shortcode_album ) );
	$album_shortcode = $shortcode->album( array( 'id' => (string) $shortcode_album ) );

	$checks->assert(
		'the block renders exactly what the shortcode renders',
		'' !== $album_shortcode && $album_block === $album_shortcode,
		sprintf( 'album block produced %d bytes, shortcode %d', strlen( $album_block ), strlen( $album_shortcode ) )
	);
}

// A block whose id was never chosen must not be looked up at all. `0` is the attribute default,
// so this is the state every freshly inserted block is in -- and `get_post_status( 0 )` on a
// real WordPress answers for the *current* post, which is how a block with no gallery chosen
// could render the page it sits on.
$checks->assert(
	'a block naming nothing renders nothing',
	'' === $block->render_gallery( array() )
		&& '' === $block->render_gallery( array( 'id' => 0 ) )
		&& '' === $block->render_gallery( array( 'id' => 99999999 ) )
		&& '' === $block->render_album( array( 'id' => 0 ) )
		&& '' === $block->render_gallery( 'not an array at all' ),
	'an absent, zero or unknown id produced markup'
);

$site->protected = array( $locked_id );

$block_protected = $block->render_gallery( array( 'id' => $locked_id ) );

$site->protected = array();

// The control is the whole check: "it rendered nothing" is also true of a block that renders
// nothing ever, which is precisely what a mutation gutting the render callback produces.
$checks->assert(
	'the block refuses a password-protected gallery',
	'' === $block_protected && '' !== $block->render_gallery( array( 'id' => $locked_id ) ),
	'a protected gallery produced ' . strlen( $block_protected ) . ' bytes through the block'
);

// The status leg, which is a different gate and was very nearly left out on the grounds that a
// path with no copy of the rule cannot forget it. That reasoning is right about the *code* and
// wrong about the *coverage*: with only the password leg checked here, `V2` went red in three
// places and this was not one of them, so nothing said the block consulted the status gate at
// all. This is the same gap the standalone path turned out to have when the predicate was first
// extracted -- belt to somebody else's braces is exactly the guard nobody notices losing.
$block_status                            = $site->galleries[ $locked_id ]['status'];
$block_caps                              = $site->capabilities;
$site->galleries[ $locked_id ]['status'] = 'draft';
$site->capabilities                      = false;
$site->build_tables();

$block_draft = ( new Lichtbild_Block( $shortcode, new Lichtbild_Repository(), new Lichtbild_Settings() ) )
	->render_gallery( array( 'id' => $locked_id ) );

$site->galleries[ $locked_id ]['status'] = $block_status;
$site->capabilities                      = $block_caps;
$site->build_tables();

$checks->assert(
	'the block refuses a gallery that is not public',
	'' === $block_draft && '' !== $block->render_gallery( array( 'id' => $locked_id ) ),
	'a draft gallery produced ' . strlen( $block_draft ) . ' bytes through the block'
);

// --- the editor wiring, which fails silently in both directions -------------------------
//
// The handles are read out of `block.json` rather than repeated here, so this compares the two
// files that have to agree instead of comparing each against a third copy of the same string.
// Get it wrong and `window.LichtbildBlocks` is never printed, the editor script returns at its
// first line, and both blocks are simply absent from the inserter -- with no error anywhere.
$block_inline = null;

foreach ( $GLOBALS['lichtbild_test_inline'] as $entry ) {
	if ( 0 === strpos( $entry['data'], 'window.LichtbildBlocks' ) ) {
		$block_inline = $entry;
	}
}

$block_data = null;

if ( null !== $block_inline && preg_match( '/^window\.LichtbildBlocks = (.*);$/s', $block_inline['data'], $block_json ) ) {
	$block_data = json_decode( $block_json[1], true );
}

$checks->assert(
	'the editor script carries the picker data',
	null !== $block_inline
		&& $block_inline['handle'] === ( $block_meta['editorScript'] ?? '' )
		&& $block_meta['editorScript'] === ( $album_meta['editorScript'] ?? '' )
		&& 'before' === $block_inline['position']
		&& isset( $GLOBALS['lichtbild_test_registered']['script'][ $block_meta['editorScript'] ] )
		&& is_array( $block_data ),
	null === $block_inline
		? 'no window.LichtbildBlocks was printed at all'
		: sprintf( 'attached to "%s" %s, block.json names "%s"', $block_inline['handle'], $block_inline['position'], $block_meta['editorScript'] )
);

// The hook is the other half of the same property: `admin_enqueue_scripts` would pay it on every
// admin page, `init` on every request at all. Only `enqueue_block_editor_assets` charges it to
// the one screen that reads the answer.
$editor_data_hook = '';

foreach ( $GLOBALS['lichtbild_test_hooks'] as $entry ) {
	if ( is_array( $entry['callback'] ) && 'enqueue_editor_data' === ( $entry['callback'][1] ?? '' ) ) {
		$editor_data_hook = $entry['hook'];
	}
}

// The control is the second clause, and without it this passes on a picker that reads nothing
// ever -- which is also what a block editor with an empty dropdown looks like.
$checks->assert(
	'registering the blocks reads nothing',
	0 === $register_reads && $editor_reads > 0 && 'enqueue_block_editor_assets' === $editor_data_hook,
	sprintf(
		'register_blocks() made %d reads, enqueue_editor_data() %d, hooked on %s',
		$register_reads,
		$editor_reads,
		'' === $editor_data_hook ? '(nothing)' : $editor_data_hook
	)
);

// `WP_Styles` drops a dependency that was never registered without a word, so the failure mode
// is an unstyled preview and a clean error log. Asserted against the registry rather than
// against the literal string `lichtbild`: what matters is that every dependency it names exists.
$block_style = $GLOBALS['lichtbild_test_registered']['style'][ $block_meta['editorStyle'] ] ?? null;
$style_deps  = null === $block_style ? array() : $block_style['deps'];
$missing_dep = array_values( array_diff( $style_deps, array_keys( $GLOBALS['lichtbild_test_registered']['style'] ) ) );

$checks->assert(
	'the editor stylesheet is laid out by the front-end one',
	null !== $block_style && ! empty( $style_deps ) && empty( $missing_dep ),
	null === $block_style
		? 'the editorStyle handle block.json names was never registered'
		: 'depends on: ' . implode( ', ', $style_deps ) . '; never registered: ' . ( empty( $missing_dep ) ? '(none)' : implode( ', ', $missing_dep ) )
);

// And the hook that makes the dependency above resolvable at all. `wp_enqueue_scripts` never
// fires in the admin, so registering there leaves `lichtbild` unknown in the editor and the
// stylesheet the check above just proved is depended upon is dropped anyway. The two together
// are the property; either alone is satisfied by an unstyled preview.
$asset_hook = '';

foreach ( $GLOBALS['lichtbild_test_hooks'] as $entry ) {
	if ( is_array( $entry['callback'] ) && 'register_assets' === ( $entry['callback'][1] ?? '' ) ) {
		$asset_hook = $entry['hook'];
	}
}

$checks->assert(
	'the front-end assets are registered on init',
	'init' === $asset_hook,
	'register_assets is hooked on: ' . ( '' === $asset_hook ? '(nothing)' : $asset_hook )
);

// --- the picker -------------------------------------------------------------------------
//
// Envira keeps its site-wide defaults in a gallery of its own, and the migration renames that
// row like any other -- so a picker built from the query rather than from the reader offers
// "Envira Default Settings" as a choice. The `<` is what makes this non-vacuous: without it a
// picker that offered *nothing* would satisfy "the defaults row is not offered".
$pickable_ids = array();

foreach ( (array) ( $block_data['galleries'] ?? array() ) as $option ) {
	$pickable_ids[] = (int) $option['value'];
}

$readable_ids = array();

foreach ( array_keys( $site->galleries ) as $candidate ) {
	if ( null !== ( new Lichtbild_Repository() )->gallery( (int) $candidate ) ) {
		$readable_ids[] = (int) $candidate;
	}
}

sort( $pickable_ids );
sort( $readable_ids );

$checks->assert(
	'the picker offers the galleries the reader can read',
	! empty( $pickable_ids )
		&& $pickable_ids === $readable_ids
		&& count( $pickable_ids ) < count( $site->galleries ),
	sprintf(
		'%d offered, %d readable, %d rows in the fixture',
		count( $pickable_ids ),
		count( $readable_ids ),
		count( $site->galleries )
	)
);

$pickable_albums = array();

foreach ( (array) ( $block_data['albums'] ?? array() ) as $option ) {
	$pickable_albums[] = (int) $option['value'];
}

$readable_albums = array();

foreach ( array_keys( $site->albums ) as $candidate ) {
	if ( null !== ( new Lichtbild_Repository() )->album( (int) $candidate ) ) {
		$readable_albums[] = (int) $candidate;
	}
}

sort( $pickable_albums );
sort( $readable_albums );

$checks->assert(
	'the picker offers the albums the reader can read',
	! empty( $pickable_albums ) && $pickable_albums === $readable_albums,
	sprintf( '%d offered, %d readable', count( $pickable_albums ), count( $readable_albums ) )
);

$GLOBALS['lichtbild_test_hooks']      = array();
$GLOBALS['lichtbild_test_shortcodes'] = array();

$container = new Lichtbild();
$container->boot();

$hooked = array();

foreach ( $GLOBALS['lichtbild_test_hooks'] as $entry ) {
	$hooked[] = $entry['hook'];
}

// Named individually rather than counted: a total rots the first time a hook is legitimately
// added, and then someone reconciles the code to the number. The set is the stable fact.
$wanted  = array( 'init', 'wp_enqueue_scripts', 'the_content', 'save_post', 'admin_menu' );
$absent  = array_values( array_diff( $wanted, $hooked ) );

$checks->assert(
	'the container constructs and registers its hooks',
	empty( $absent ) && $container->repository() instanceof Lichtbild_Repository,
	'hooks never registered: ' . ( empty( $absent ) ? '(none)' : implode( ', ', $absent ) )
);

$GLOBALS['lichtbild_test_hooks']      = array();
$GLOBALS['lichtbild_test_shortcodes'] = array();

unset( $_REQUEST['gallery'], $_REQUEST['nonce'], $site->options[ Lichtbild_Settings::OPTION_STANDALONE ], $site->options['lichtbild_takeover'] );

printf( "\nfixture: %s\n", basename( $fixture ) );
printf( "galleries in fixture: %d, rendered: %d, skipped: %d\n", count( $site->galleries ), $galleries_seen, count( $skipped ) );
printf( "items rendered on page 1 across all galleries: %d\n\n", $total_rendered );

// Provenance, not a check. A fixture exported before `post_password` was added cannot tell a
// protected gallery from a public one, so the password checks above are driven entirely by
// states constructed here. That is not the same as being driven by the real site, and the
// difference is invisible unless it is printed: "no protected galleries found" would otherwise
// read as coverage. Reported the way the PHP matrix reports a missing interpreter — a gap that
// is stated, never a pass.
if ( $site->fixture_has_password_data ) {
	printf(
		"fixture carries password data: yes (%d protected)\n\n",
		count( $site->fixture_protected )
	);
} else {
	printf(
		"fixture carries password data: NO - exported before post_password was added.\n" .
		"  The password checks ran against constructed states only. Re-export with\n" .
		"  tests/export-fixture.py to exercise the real distribution.\n\n"
	);
}

// Security and lifecycle regressions use synthetic posts, independent of fixture populations.
$audit_site = $site;
$site = new Lichtbild_Test_Site();
Lichtbild_Test_Site::$instance = $site;
$site->capabilities = true;
$site->options = array( 'lichtbild_schema_version' => 2, 'lichtbild_slug_scheme' => 'generic' );
$site->posts = array(
	9001 => array( 'ID' => 9001, 'post_type' => 'lichtbild_gallery', 'post_status' => 'publish' ),
	9002 => array( 'ID' => 9002, 'post_type' => 'post', 'post_status' => 'private' ),
	9003 => array( 'ID' => 9003, 'post_type' => 'lichtbild_album', 'post_status' => 'publish' ),
	501 => array( 'ID' => 501, 'post_type' => 'attachment', 'post_status' => 'inherit' ),
);
$site->attachments[501] = array( 'title' => 'SYNTHETIC IMAGE', 'excerpt' => 'SYNTHETIC CAPTION', 'alt' => 'SYNTHETIC IMAGE ALT' );
// The stubs store excerpts and alt metadata here; the explicit post row still decides type.
$site->attachments[9002] = array( 'excerpt' => 'SYNTHETIC NON-ATTACHMENT EXCERPT', 'alt' => 'SYNTHETIC NON-ATTACHMENT ALT' );
$site->galleries[9002] = array( 'title' => 'SYNTHETIC PRIVATE TITLE' );
$site->capability_overrides['read_post:9002'] = false;
$audit_settings = new Lichtbild_Settings();
$audit_repository = new Lichtbild_Repository( 'lichtbild_gallery', 'lichtbild_album', 'lichtbild_tag', true );
$audit_editor = new Lichtbild_Editor( $audit_settings, $audit_repository );
$audit_album_editor = new Lichtbild_Album_Editor( $audit_settings, $audit_repository );
$audit_record = array( 'version' => 2, 'settings' => Lichtbild_Config::defaults(), 'items' => array( array( 'id' => 501, 'src' => 'https://example.test/image.jpg' ) ) );
$site->galleries[9001] = array( 'lichtbild' => $audit_record );
$site->albums[9003] = array( 'lichtbild' => array( 'version' => 2, 'settings' => Lichtbild_Album_Config::defaults(), 'items' => array( array( 'id' => 9001, 'cover_id' => 0, 'caption' => '' ) ) ) );

foreach ( array( array( $audit_editor, 9001, 'lichtbild', 'galleries' ), array( $audit_album_editor, 9003, 'lichtbild_album', 'albums' ) ) as $case ) {
	list( $writer, $post_id, $prefix, $bucket ) = $case;
	$nonce_name = $writer::NONCE;
	$before = $site->{$bucket}[$post_id]['lichtbild'];
	$rows = 'albums' === $bucket ? array( 'i0' => array( 'id' => 9001 ) ) : array( 'i0' => array( 'id' => 501, 'src' => 'https://example.test/image.jpg' ) );
	$payload = array(
		$nonce_name => wp_create_nonce( $writer::NONCE_ACTION . $post_id ),
		$prefix . '_items' => $rows,
		$prefix . '_order' => 'i0',
		$nonce_name . '_items_complete' => '1',
		$prefix . '_settings' => array( 'columns' => '4' ),
		$nonce_name . '_settings_complete' => '1',
	);
	foreach ( array( 'items', 'settings' ) as $section ) {
		$_POST = $payload;
		unset( $_POST[$nonce_name . '_' . $section . '_complete'] );
		$site->writes = array();
		$writer->save( $post_id );
		$checks->assert( 'incomplete editor forms preserve images and settings', empty( $site->writes ) && $before === $site->{$bucket}[$post_id]['lichtbild'], $prefix . ': ' . $section );
	}
	$site->current_screen = (object) array( 'post_type' => $site->posts[$post_id]['post_type'] );
	ob_start();
	$writer->render_save_notice();
	$notice = ob_get_clean();
	$checks->assert( 'incomplete editor forms report the refused save', false !== strpos( $notice, 'incomplete form' ) && false !== strpos( $notice, 'notice-error' ), $prefix );

	// Empty rows with BOTH markers are a deliberate remove-all, not a truncated request.
	$_POST = $payload;
	unset( $_POST[$prefix . '_items'] );
	$_POST[$prefix . '_order'] = '';
	$writer->save( $post_id );
	$checks->assert( 'complete editor forms can remove every item', array() === $site->{$bucket}[$post_id]['lichtbild']['items'], $prefix );
	$site->{$bucket}[$post_id]['lichtbild'] = $before;

	ob_start();
	if ( 'galleries' === $bucket ) {
		$writer->render_images_box( (object) array( 'ID' => $post_id ) );
	} else {
		$writer->render_galleries_box( (object) array( 'ID' => $post_id ) );
	}
	$items_form = ob_get_clean();
	ob_start();
	$writer->render_settings_box( (object) array( 'ID' => $post_id ) );
	$settings_form = ob_get_clean();
	$checks->assert( 'editor completion markers follow their fields',
		strpos( $items_form, $nonce_name . '_items_complete' ) > strpos( $items_form, 'name="' . $prefix . '_order"' )
		&& strpos( $settings_form, $nonce_name . '_settings_complete' ) > strrpos( $settings_form, '</table>' ), $prefix );
}

$_POST = array(
	Lichtbild_Editor::NONCE => wp_create_nonce( Lichtbild_Editor::NONCE_ACTION . 9001 ),
	'lichtbild_editor_nonce_items_complete' => '1',
	'lichtbild_editor_nonce_settings_complete' => '1',
	'lichtbild_items' => array( 'i0' => array( 'id' => 9002, 'src' => 'https://example.test/dummy.jpg' ) ),
	'lichtbild_order' => 'i0',
);
$audit_editor->save( 9001 );
$checks->assert( 'gallery saves refuse private posts as images', empty( $site->galleries[9001]['lichtbild']['items'] ) );
$site->capability_overrides['read_post:501'] = false;
$_POST['lichtbild_items']['i0']['id'] = 501;
$audit_editor->save( 9001 );
$checks->assert( 'gallery saves refuse unreadable attachments', empty( $site->galleries[9001]['lichtbild']['items'] ) );
$site->capability_overrides['read_post:501'] = true;
$audit_editor->save( 9001 );
$checks->assert( 'gallery saves accept readable shared attachments', 501 === ( $site->galleries[9001]['lichtbild']['items'][0]['id'] ?? 0 ) );
$_POST['lichtbild_items']['i0']['id'] = 77777;
$audit_editor->save( 9001 );
$checks->assert( 'gallery saves retain deleted attachment fallback URLs', 'https://example.test/dummy.jpg' === ( $site->galleries[9001]['lichtbild']['items'][0]['src'] ?? '' ) );

// A poisoned legacy record must also be harmless before its owner next edits it.
$poisoned = new Lichtbild_Item( 9002, array( 'src' => 'https://example.test/dummy.jpg' ) );
$valid = new Lichtbild_Item( 501, array() );
foreach ( array( false, true ) as $live_metadata ) {
	$poisoned->use_live_metadata( $live_metadata );
	$valid->use_live_metadata( $live_metadata );
	$mode = $live_metadata ? 'live metadata' : 'stored metadata';
	$checks->assert( 'image metadata cannot expose other post types',
		array( '', '', '' ) === array( $poisoned->title(), $poisoned->caption(), $poisoned->alt() ), $mode );
	$checks->assert( 'real attachment metadata still supplies titles and captions',
		array( 'SYNTHETIC IMAGE', 'SYNTHETIC CAPTION', 'SYNTHETIC IMAGE ALT' ) === array( $valid->title(), $valid->caption(), $valid->alt() ), $mode );
}

// Exercise uninstall.php itself, not a duplicated list of option deletions.
$old_wpdb = $wpdb;
$wpdb = new class extends Lichtbild_Test_wpdb {
	public $options = 'wp_options';
	public $fail_counts = false;
	public function get_var( $sql ) {
		return $this->fail_counts ? null : parent::get_var( $sql );
	}
	public function get_col( $sql ) {
		return false !== strpos( $sql, 'FROM wp_options' ) ? array() : parent::get_col( $sql );
	}
};
defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'lichtbild-gallery/lichtbild-gallery.php' );
foreach ( array( 'generic', 'envira' ) as $scheme ) {
	$site->options = array( 'lichtbild_schema_version' => 2, 'lichtbild_slug_scheme' => $scheme, 'lichtbild_standalone' => 1, 'lichtbild_takeover' => 'always' );
	require LICHTBILD_DIR . 'uninstall.php';
	$again = new Lichtbild_Settings();
	$checks->assert( 'reinstall keeps retained content URLs and standalone behavior', $scheme === $again->slug_scheme() && $again->has_migrated() && $again->standalone(), $scheme );
}
$site->posts = array();
$site->taxonomies = array();
$wpdb->fail_counts = true;
require LICHTBILD_DIR . 'uninstall.php';
$checks->assert( 'uninstall preserves identity when content cannot be counted', isset( $site->options['lichtbild_schema_version'], $site->options['lichtbild_slug_scheme'], $site->options['lichtbild_standalone'] ) );
$wpdb->fail_counts = false;
require LICHTBILD_DIR . 'uninstall.php';
$checks->assert( 'uninstall without owned content removes plugin options', array() === $site->options );
$wpdb = $old_wpdb;
$site = $audit_site;
Lichtbild_Test_Site::$instance = $site;

exit( $checks->report() );
