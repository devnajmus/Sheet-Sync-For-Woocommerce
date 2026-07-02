<?php
/**
 * Inspect a SheetSync zip before upload.
 * Usage: php tools/inspect-zip.php [path-to.zip]
 */

$path = $argv[1] ?? dirname( __DIR__, 2 ) . '/sheetsync-for-woocommerce.zip';
if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "Zip not found: {$path}\n" );
	exit( 1 );
}

$z = new ZipArchive();
if ( true !== $z->open( $path ) ) {
	fwrite( STDERR, "Cannot open zip\n" );
	exit( 1 );
}

echo "Zip: {$path}\n";
echo 'Total entries: ' . $z->numFiles . "\n\n";

$main_path = 'sheetsync-for-woocommerce/sheetsync-for-woocommerce.php';
$map_path  = 'sheetsync-for-woocommerce/includes/Repositories/class-product-map-repository.php';

$main = $z->getFromName( $main_path );
$map  = $z->getFromName( $map_path );

echo "--- Structure ---\n";
echo ( false !== $main ? '[OK]' : '[FAIL]' ) . " {$main_path}\n";
echo ( false !== $map ? '[OK]' : '[FAIL]' ) . " {$map_path}\n";

$tools = 0;
for ( $i = 0; $i < $z->numFiles; $i++ ) {
	$name = (string) $z->getNameIndex( $i );
	if ( str_starts_with( $name, 'sheetsync-for-woocommerce/tools/' ) ) {
		++$tools;
	}
}
echo "tools/ entries: {$tools} (OK if 0 for production; harmless if present)\n\n";

echo "--- Fixes in zip ---\n";
if ( is_string( $main ) && preg_match( "/define\\(\\s*'SHEETSYNC_BUILD',\\s*'([^']+)'/", $main, $m ) ) {
	echo '[OK] SHEETSYNC_BUILD=' . $m[1] . "\n";
} else {
	echo "[FAIL] SHEETSYNC_BUILD missing in main plugin file\n";
}

if ( is_string( $map ) ) {
	$spread_count = substr_count( $map, '...$params' );
	echo '[OK] product-map ...$params count=' . $spread_count . ( $spread_count >= 2 ? "\n" : " (expected >= 2)\n" );
	if ( preg_match( '/external_key IN \(\{\$placeholders\}\)",\s*\$params\s*\)/', $map ) ) {
		echo "[FAIL] prefetch_by_external_keys still uses bare \$params\n";
	} else {
		echo "[OK] prefetch_by_external_keys uses spread\n";
	}
} else {
	echo "[FAIL] product-map file missing\n";
}

$state = $z->getFromName( 'sheetsync-for-woocommerce/includes/Repositories/class-sync-state-repository.php' );
if ( is_string( $state ) && str_contains( $state, 'current_job_id = %d' ) ) {
	echo "[OK] job-aware lock release in sync-state-repository\n";
} else {
	echo "[WARN] job-aware lock not found in sync-state-repository\n";
}

$z->close();
echo "\nUpload this zip to: wp-content/plugins/ (extract so path is plugins/sheetsync-for-woocommerce/sheetsync-for-woocommerce.php)\n";
