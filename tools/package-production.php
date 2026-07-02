<?php
/**
 * Create a production ZIP (excludes dev-only paths).
 *
 * Run from plugin root:
 *   php tools/package-production.php
 *
 * Output: ../sheetsync-for-woocommerce-production.zip
 *
 * @package SheetSync_For_WooCommerce
 */

$plugin_root = dirname( __DIR__ );
$plugin_slug = basename( $plugin_root );
$out_dir     = dirname( $plugin_root );
$zip_path    = $out_dir . DIRECTORY_SEPARATOR . $plugin_slug . '-production.zip';

$exclude_dirs = array(
	'.git',
	'.github',
	'.cursor',
	'node_modules',
	'tests',
	'tools',
);

$exclude_files = array(
	'.gitignore',
	'.editorconfig',
	'phpunit.xml',
	'phpunit.xml.dist',
	'composer.json',
	'composer.lock',
	'package.json',
	'package-lock.json',
);

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ZipArchive PHP extension required.\n" );
	exit( 1 );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Cannot create zip: {$zip_path}\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin_root, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

$added = 0;
foreach ( $iterator as $file ) {
	/** @var SplFileInfo $file */
	$path     = $file->getPathname();
	$relative = substr( $path, strlen( $plugin_root ) + 1 );
	$relative = str_replace( '\\', '/', $relative );

	if ( '' === $relative ) {
		continue;
	}

	$parts = explode( '/', $relative );
	if ( in_array( $parts[0], $exclude_dirs, true ) ) {
		continue;
	}

	if ( ! $file->isDir() && in_array( basename( $relative ), $exclude_files, true ) ) {
		continue;
	}

	if ( $file->isDir() ) {
		continue;
	}

	$zip_name = $plugin_slug . '/' . $relative;
	if ( $zip->addFile( $path, $zip_name ) ) {
		++$added;
	}
}

$zip->close();

echo "Production package created.\n";
echo "  Files: {$added}\n";
echo "  Path:  {$zip_path}\n";
echo "\nExcluded: tools/, .git, tests, composer/package manifests.\n";
echo "Before shipping: run production-readiness.php on staging with debug OFF.\n";
