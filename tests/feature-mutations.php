<?php
/**
 * Proves the focused tests for the new creation and metadata paths can go red.
 *
 *     php tests/feature-mutations.php
 *
 * Each edit is required to occur exactly once, change the file's digest, fail the named test,
 * and print the predicted failing assertion. Every file is restored byte for byte.
 *
 * @package Lichtbild\Tests
 */

ini_set( 'display_errors', 'stderr' );

$root = dirname( __DIR__ ) . '/';

$mutations = array(
	array(
		'id'      => 'FC1',
		'file'    => 'includes/class-lichtbild-block.php',
		'find'    => "\t\t\tif ( ! wp_attachment_is_image( \$id ) ) {",
		'replace' => "\t\t\tif ( false ) {",
		'test'    => 'block-create-test.php',
		'expect'  => 'a PDF attachment refuses the whole request',
	),
	array(
		'id'      => 'FC2',
		'file'    => 'includes/class-lichtbild-block.php',
		'find'    => "\t\tif ( false === \$stored ) {",
		'replace' => "\t\tif ( false ) {",
		'test'    => 'block-create-test.php',
		'expect'  => 'a record that will not store answers an error, not an id',
	),
	array(
		'id'      => 'FC3',
		'file'    => 'includes/class-lichtbild-block.php',
		'find'    => "\t\t\twp_delete_post( \$post_id, true );",
		'replace' => "\t\t\t// orphan deliberately left behind.",
		'test'    => 'block-create-test.php',
		'expect'  => 'the draft it could not record is force-deleted, not left behind',
	),
	array(
		'id'      => 'FC4',
		'file'    => 'assets/js/blocks.js',
		'find'    => "\t\t\t\t\tdisabled: busy,\n\t\t\t\t\toptions:",
		'replace' => "\t\t\t\t\tdisabled: false,\n\t\t\t\t\toptions:",
		'test'    => 'blocks-js-test.js',
		'expect'  => 'the chooser is disabled while the request is in flight',
	),
	array(
		'id'      => 'FM1',
		'file'    => 'includes/class-lichtbild-gallery.php',
		'find'    => "\t\t\t\$item->use_live_metadata( \$live );",
		'replace' => "\t\t\t\$item->use_live_metadata( false );",
		'test'    => 'live-metadata-test.php',
		'expect'  => 'a gallery with the setting on switches every one of its items on',
	),
	array(
		'id'      => 'FM2',
		'file'    => 'includes/class-lichtbild-config.php',
		'find'    => "\t\t\t'live_metadata'       => false,",
		'replace' => "\t\t\t'live_metadata'       => true,",
		'test'    => 'live-metadata-test.php',
		'expect'  => 'the setting exists and defaults to off',
	),
	array(
		'id'      => 'FM3',
		'file'    => 'includes/class-lichtbild-config.php',
		'find'    => "\t\t\t'live_metadata',\n\t\t\t'lazy_loading',",
		'replace' => "\t\t\t'lazy_loading',",
		'test'    => 'live-metadata-test.php',
		'expect'  => 'a ticked checkbox switches the setting on, as a boolean',
	),
);

/**
 * Runs one focused test.
 *
 * @param string $file Test filename under tests/.
 *
 * @return array{status:int,output:string}
 */
function lichtbild_feature_test( $file ) {
	$command = 'blocks-js-test.js' === $file
		? 'node ' . escapeshellarg( __DIR__ . '/' . $file )
		: escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/' . $file );
	$lines   = array();
	$status  = 0;

	exec( $command . ' 2>&1', $lines, $status );

	return array( 'status' => $status, 'output' => implode( "\n", $lines ) );
}

$tests = array_values( array_unique( array_column( $mutations, 'test' ) ) );

foreach ( $tests as $test ) {
	$baseline = lichtbild_feature_test( $test );

	if ( 0 !== $baseline['status'] ) {
		printf( "[ERROR] baseline is red: %s\n%s\n", $test, $baseline['output'] );
		exit( 1 );
	}
}

$problems = 0;

foreach ( $mutations as $mutation ) {
	$path     = $root . $mutation['file'];
	$original = (string) file_get_contents( $path );
	$before   = md5( $original );
	$found    = substr_count( $original, $mutation['find'] );

	if ( 1 !== $found ) {
		printf( "[FAIL] %s target occurs %d times in %s\n", $mutation['id'], $found, $mutation['file'] );
		$problems++;
		continue;
	}

	$changed = str_replace( $mutation['find'], $mutation['replace'], $original );
	file_put_contents( $path, $changed );

	$result = lichtbild_feature_test( $mutation['test'] );

	file_put_contents( $path, $original );

	$restored = md5( (string) file_get_contents( $path ) ) === $before;
	$landed   = md5( $changed ) !== $before;
	$killed   = 0 !== $result['status']
		&& false !== strpos( $result['output'], '[FAIL]' )
		&& false !== strpos( $result['output'], $mutation['expect'] );

	if ( $landed && $restored && $killed ) {
		printf( "[OK]   %s killed: %s\n", $mutation['id'], $mutation['expect'] );
		continue;
	}

	printf( "[FAIL] %s %s\n", $mutation['id'], $mutation['expect'] );
	printf( "       landed=%s restored=%s status=%d\n", $landed ? 'yes' : 'no', $restored ? 'yes' : 'no', $result['status'] );
	$problems++;
}

printf( "\nmutations: %d, problems: %d\n", count( $mutations ), $problems );
exit( $problems > 0 ? 1 : 0 );
