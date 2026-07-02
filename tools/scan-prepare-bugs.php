<?php
/**
 * Static scan: wpdb->prepare() called with a bare array variable (missing spread).
 *
 * Usage: php tools/scan-prepare-bugs.php
 */

$roots = array(
	dirname( __DIR__ ) . '/includes',
	dirname( __DIR__ ) . '/admin',
);
$issues = array();

foreach ( $roots as $root ) {
if ( ! is_dir( $root ) ) {
	continue;
}
$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

foreach ( $rii as $file ) {
	if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
		continue;
	}
	$path = $file->getPathname();
	if ( str_contains( $path, 'vendor' ) ) {
		continue;
	}
	$src = file_get_contents( $path );
	if ( ! is_string( $src ) || ! str_contains( $src, '->prepare(' ) ) {
		continue;
	}

	if ( ! preg_match_all( '/->prepare\s*\((?:[^()]++|\([^()]*\))*+\)/s', $src, $matches ) ) {
		continue;
	}

	foreach ( $matches[0] as $call ) {
		if ( preg_match( '/\.\.\.\s*\$/', $call ) ) {
			continue;
		}
		if ( preg_match( '/,\s*\$(\w+)\s*\)/', $call, $vm ) ) {
			$var = $vm[1];
			if ( preg_match( '/\$' . preg_quote( $var, '/' ) . '\s*=\s*array\s*\(/', $src ) ) {
				$issues[] = str_replace( dirname( __DIR__ ) . '/', '', $path ) . ' :: $' . $var;
			}
		}
	}
}
}

if ( empty( $issues ) ) {
	echo "OK: no bare-array prepare() calls found in includes/ and admin/\n";
	exit( 0 );
}

echo "FOUND " . count( $issues ) . " potential issue(s):\n";
foreach ( $issues as $issue ) {
	echo "  - {$issue}\n";
}
exit( 1 );
