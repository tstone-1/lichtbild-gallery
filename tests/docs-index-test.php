<?php
/**
 * Asserts AGENTS.md is still an index rather than a corpus.
 *
 *     php tests/docs-index-test.php
 *
 * AGENTS.md is read in full before every task, by every agent, and appending is always the
 * cheapest move — so it only ever grows. It reached 158,707 bytes before it was split, at which
 * point the harness reading it started refusing to load it. The entries did not shrink; they
 * moved, verbatim, into docs/lessons.md and docs/deploys.md, and what stayed behind is one line
 * each. This is the guard on that arrangement, and it exists because the arrangement is a habit
 * rather than a mechanism: nothing else would notice the file drifting back.
 *
 * Two things are checked beyond the size, and the second is the one that would actually catch a
 * mistake made in a hurry:
 *
 * - Every corpus file the index links to exists and is not empty. AGENTS.md is tracked and the
 *   corpus files were not, so committing the index alone would leave a fresh clone with an index
 *   pointing at nothing — a failure that is invisible on the machine where the split was made.
 * - Every entry named in an index line exists as a heading in exactly one corpus file. That is
 *   what stops an entry being summarised into its own hook and deleted: the hook is a claim, and
 *   this asserts the thing it is a claim about is still there to read.
 *
 * The population examined is printed for the same reason the render suite prints it. A check
 * that silently examines nothing is indistinguishable from one that passes, so an index with no
 * entries is reported as a failure rather than a clean run.
 *
 * @package Lichtbild\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

ini_set( 'display_errors', 'stderr' );

$root = dirname( __DIR__ );

/**
 * The ceiling, in bytes. Not the harness's own limit: the point is to be told while there is
 * still room to think, rather than when the file has already stopped loading. At the time of
 * writing the shared instructions reserve 30 KB for this repository. Keep the local limit
 * inside that allowance rather than passing locally while the combined load fails.
 */
const AGENTS_MAX_BYTES = 30000;

$agents = $root . '/AGENTS.md';
$text   = file_get_contents( $agents );
$ok     = true;

if ( false === $text ) {
	printf( "  [FAIL] AGENTS.md is unreadable\n" );
	exit( 1 );
}

// ---------------------------------------------------------------------------
// 1. the size
// ---------------------------------------------------------------------------
$bytes = strlen( $text );

printf( "AGENTS.md:           %d bytes of %d (%d%%)\n",
	$bytes, AGENTS_MAX_BYTES, (int) round( $bytes / AGENTS_MAX_BYTES * 100 ) );

if ( $bytes > AGENTS_MAX_BYTES ) {
	printf( "  [FAIL] AGENTS.md is %d bytes over the ceiling; move an entry to docs/ and\n"
		. "         leave one line in its place, rather than raising this\n",
		$bytes - AGENTS_MAX_BYTES );
	$ok = false;
}

// ---------------------------------------------------------------------------
// 2. the corpus files the index points at
// ---------------------------------------------------------------------------
preg_match_all( '#\((docs/[A-Za-z0-9._-]+\.md)\)#', $text, $links );
$corpus = array_values( array_unique( $links[1] ) );

printf( "corpus files linked: %d\n", count( $corpus ) );

if ( empty( $corpus ) ) {
	printf( "  [FAIL] the index links to no corpus file at all\n" );
	$ok = false;
}

$bodies = array();

foreach ( $corpus as $rel ) {
	$path = $root . '/' . $rel;

	if ( ! is_readable( $path ) ) {
		printf( "  [FAIL] %s is linked from AGENTS.md but is not here; a committed index\n"
			. "         pointing at an uncommitted corpus is the whole failure mode\n", $rel );
		$ok = false;
		continue;
	}

	$body = file_get_contents( $path );

	if ( '' === trim( (string) $body ) ) {
		printf( "  [FAIL] %s is empty\n", $rel );
		$ok = false;
		continue;
	}

	$bodies[ $rel ] = $body;
}

// ---------------------------------------------------------------------------
// 3. every index line names an entry that is still there to read
// ---------------------------------------------------------------------------
/**
 * Collects the index lines, reassembling those that wrap.
 *
 * An index line is `- *Title* — hook`, wrapped to the file's own column, so the title itself can
 * straddle a line break. Matching per line finds most of them and silently misses the ones that
 * wrap, which is the same defect the i18n extractor was written to avoid: an extraction that
 * looks complete is worse than one that fails.
 *
 * @param string $text AGENTS.md.
 * @return string[] Entry titles, in the order they appear.
 */
function lichtbild_index_titles( $text ) {
	$lines  = explode( "\n", $text );
	$blocks = array();
	$cur    = null;

	// `- *Title*` and never `- **Bold**`: the file is full of ordinary bullets opening on a
	// bold run, and matching those reported five entries that were never entries at all.
	foreach ( $lines as $line ) {
		if ( preg_match( '/^\s*- \*(?!\*)/', $line ) ) {
			if ( null !== $cur ) {
				$blocks[] = $cur;
			}
			$cur = $line;
			continue;
		}

		// A continuation line is indented and is not itself a list item.
		if ( null !== $cur && preg_match( '/^\s+\S/', $line ) && ! preg_match( '/^\s*[-*] /', $line ) ) {
			$cur .= ' ' . trim( $line );
			continue;
		}

		if ( null !== $cur ) {
			$blocks[] = $cur;
			$cur      = null;
		}
	}

	if ( null !== $cur ) {
		$blocks[] = $cur;
	}

	$titles = array();

	foreach ( $blocks as $block ) {
		$flat = trim( preg_replace( '/\s+/', ' ', $block ) );

		if ( preg_match( '/^- \*(?!\*)(.+?)\*(?!\*) \x{2014} /u', $flat, $m ) ) {
			$titles[] = $m[1];
		}
	}

	return $titles;
}

$titles = lichtbild_index_titles( $text );

printf( "index entries:       %d\n", count( $titles ) );

if ( empty( $titles ) ) {
	printf( "  [FAIL] the index names no entries; either the format changed or every entry\n"
		. "         was folded back into this file\n" );
	$ok = false;
}

foreach ( $titles as $title ) {
	$found = 0;

	foreach ( $bodies as $body ) {
		$found += preg_match_all( '/^#{2,6} ' . preg_quote( $title, '/' ) . '\s*$/m', $body );
	}

	if ( 1 !== $found ) {
		printf( "  [FAIL] the index names an entry that is in the corpus %d times (want 1): %s\n",
			$found, $title );
		$ok = false;
	}
}

// ---------------------------------------------------------------------------
// 4. the corpus files' OWN index lists, in both directions
//
// The deploy records' index used to live in AGENTS.md and was a seventh of it — eighteen
// releases with thirty-two hooks is a corpus, not an index line — so it moved into
// docs/deploys.md and opens that file instead. Moving it out of AGENTS.md moved it out of
// every check above with it, which would have left the largest index in the repository as the
// only unguarded one. That is the failure this file exists to prevent, merely relocated.
//
// Both directions, because each catches something the other cannot:
//
//   forward   a hook naming a record that is gone. This is the one a hurried edit produces:
//             deleting or retitling a section and not touching the list above it.
//   reverse   a record with no hook. Invisible forever otherwise — the record is perfectly
//             readable, it is simply unreachable from the list people actually scan.
//
// `####` headings are deliberately exempt from the reverse direction. They are structure
// WITHIN a record (three exist today: "Cleanup", and two stages of one deploy), not entries in
// their own right, and requiring a hook for each would push detail back into the index that the
// split just took out of it. `###` is the record level and is required to be reachable.
// ---------------------------------------------------------------------------
$self_indexed = 0;

foreach ( $bodies as $rel => $body ) {
	$titles = lichtbild_index_titles( $body );

	if ( empty( $titles ) ) {
		continue;
	}

	printf( "%s: %d hooks\n", $rel, count( $titles ) );

	$heads = array();

	foreach ( $titles as $title ) {
		$found = 0;

		foreach ( $bodies as $other ) {
			$found += preg_match_all( '/^#{2,6} ' . preg_quote( $title, '/' ) . '\s*$/m', $other );
		}

		if ( 1 !== $found ) {
			printf( "  [FAIL] %s hooks an entry that is in the corpus %d times (want 1): %s\n",
				$rel, $found, $title );
			$ok = false;
		}

		$heads[ $title ] = true;
	}

	// Cross-references inside historical lessons are not an index of every section in
	// that corpus. Only the explicitly self-indexed deploy records need the reverse check;
	// all references above are still validated, wherever they occur.
	if ( 'docs/deploys.md' !== $rel ) {
		continue;
	}
	++$self_indexed;
	preg_match_all( '/^### (.+?)\s*$/m', $body, $records );

	foreach ( $records[1] as $record ) {
		if ( ! isset( $heads[ $record ] ) ) {
			printf( "  [FAIL] %s has a record no hook names, so nothing scanning the list\n"
				. "         above will ever find it: %s\n", $rel, $record );
			$ok = false;
		}
	}

	printf( "  %d records, all reachable from the list\n", count( $records[1] ) );
}

// The emptiness control. Every loop above is over a set that can be empty, and an empty set
// passes exactly like a full one that found nothing wrong — the defect this suite's own render
// harness declares its checks up front to avoid. If no corpus file carries an index list, the
// section covered nothing and must say so rather than falling silent.
if ( 0 === $self_indexed ) {
	printf( "  [FAIL] no corpus file carries an index list of its own, so section 4 examined\n"
		. "         nothing; either the format changed or the list was folded back in\n" );
	$ok = false;
}

printf( "\n%s\n", $ok ? 'docs index: intact' : 'docs index: BROKEN' );

exit( $ok ? 0 : 1 );
