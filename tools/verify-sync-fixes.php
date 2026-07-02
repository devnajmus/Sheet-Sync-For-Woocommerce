<?php
/**
 * Verify sync-engine audit fixes on a live WordPress site.
 *
 * Usage (from site root):
 *   wp eval-file wp-content/plugins/sheetsync-for-woocommerce/tools/verify-sync-fixes.php
 *
 * Optional: pass connection ID
 *   wp eval-file .../verify-sync-fixes.php -- 3
 *
 * @package SheetSync_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI: wp eval-file tools/verify-sync-fixes.php\n" );
	exit( 1 );
}

$connection_id = isset( $args[0] ) ? absint( $args[0] ) : 0;

echo "SheetSync sync-engine verification\n";
echo str_repeat( '-', 40 ) . "\n";

// 1. AJAX tick action registered.
$tick_registered = has_action( 'wp_ajax_sheetsync_sync_tick' );
echo $tick_registered
	? "[OK] wp_ajax_sheetsync_sync_tick is registered\n"
	: "[FAIL] sheetsync_sync_tick AJAX action missing\n";

// 2. Adaptive bootstrap delay.
if ( function_exists( 'sheetsync_export_batch_delay_seconds' ) ) {
	$small_job = (object) array( 'total_estimate' => 50, 'direction' => 'bootstrap' );
	$large_job = (object) array( 'total_estimate' => 800, 'direction' => 'bootstrap' );
	$small     = sheetsync_export_batch_delay_seconds( 0, 'bootstrap', $small_job );
	$large     = sheetsync_export_batch_delay_seconds( 0, 'bootstrap', $large_job );
	$error     = sheetsync_export_batch_delay_seconds( 1, 'bootstrap', $large_job );

	echo ( 2 === $small )
		? "[OK] Bootstrap delay small catalog = {$small}s\n"
		: "[WARN] Expected small-catalog delay 2s, got {$small}s\n";
	echo ( 15 === $large )
		? "[OK] Bootstrap delay large catalog = {$large}s\n"
		: "[WARN] Expected large-catalog delay 15s, got {$large}s\n";
	echo ( 60 === $error )
		? "[OK] Bootstrap delay on error = {$error}s\n"
		: "[WARN] Expected error delay 60s, got {$error}s\n";
} else {
	echo "[FAIL] sheetsync_export_batch_delay_seconds() missing\n";
}

// 3. Grid cache API surface.
if ( class_exists( 'SheetSync_Sheets_Client', false ) ) {
	$client   = new SheetSync_Sheets_Client();
	$has_load = ( new ReflectionClass( $client ) )->hasMethod( 'load_sheet_properties' );
	$has_inv  = method_exists( $client, 'invalidate_sheet_grid_cache' );
	echo ( $has_load && $has_inv )
		? "[OK] Sheet grid request cache present\n"
		: "[FAIL] Sheet grid cache methods missing\n";
} else {
	echo "[SKIP] SheetSync_Sheets_Client not loaded\n";
}

// 4. Export completion uses cursor meta (not map count shortcut).
if ( class_exists( 'SheetSync_Job_Repository', false ) ) {
	$repo   = new SheetSync_Job_Repository();
	$ref    = new ReflectionClass( $repo );
	$method = $ref->getMethod( 'should_auto_close_completed_export' );
	$method->setAccessible( true );

	$fake_job = (object) array(
		'direction'       => 'bootstrap',
		'phase'           => 'push',
		'mode'            => 'incremental',
		'total_estimate'  => 500,
		'processed_count' => 490,
		'error_count'     => 0,
		'cursor_meta'     => wp_json_encode( array() ),
	);
	$early_close = $method->invoke( $repo, $fake_job );
	echo ! $early_close
		? "[OK] Stale-job auto-close ignores processed≈total without export_complete\n"
		: "[FAIL] Still auto-closing on processed count alone\n";

	$fake_job->cursor_meta = wp_json_encode( array( 'export_complete' => 1, 'catalog_finalized' => 1 ) );
	$cursor_close          = $method->invoke( $repo, $fake_job );
	echo $cursor_close
		? "[OK] Stale-job auto-close accepts export_complete + catalog_finalized cursor\n"
		: "[FAIL] export_complete + catalog_finalized cursor not honored\n";
} else {
	echo "[SKIP] SheetSync_Job_Repository not loaded\n";
}

// 5. Active connection / job snapshot.
if ( $connection_id < 1 && class_exists( 'SheetSync_Sync_Engine', false ) ) {
	global $wpdb;
	$table = $wpdb->prefix . 'sheetsync_connections';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$connection_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT 1",
			'active'
		)
	);
}

if ( $connection_id > 0 && class_exists( 'SheetSync_Job_Repository', false ) ) {
	$repo = new SheetSync_Job_Repository();
	$job  = $repo->get_running_for_connection( $connection_id );
	echo "\nConnection #{$connection_id}\n";
	if ( $job ) {
		$progress = function_exists( 'sheetsync_job_progress_numbers' )
			? sheetsync_job_progress_numbers( $job )
			: array();
		$meta     = $repo->get_cursor_meta( $job );
		echo "  Job #{$job->id} status={$job->status} phase={$job->phase} direction={$job->direction}\n";
		echo '  processed_count=' . (int) $job->processed_count
			. ' progress_done=' . (int) ( $progress['done'] ?? 0 )
			. '/' . (int) ( $progress['total'] ?? 0 ) . "\n";
		echo '  export_complete=' . ( ! empty( $meta['export_complete'] ) ? 'yes' : 'no' ) . "\n";
	} else {
		echo "  No running job (start Export/Import to test live progress).\n";
	}
} else {
	echo "\n[INFO] Pass connection ID: wp eval-file tools/verify-sync-fixes.php -- <id>\n";
}

echo str_repeat( '-', 40 ) . "\n";
echo "Manual UI checks:\n";
echo "  1. DevTools → Network: only admin-ajax.php?action=sheetsync_sync_tick (no parallel REST poll + drain).\n";
echo "  2. First export: progress climbs by batch size; does NOT jump to ~100% early.\n";
echo "  3. Sync tab: Automatic sync card with 3 collapsible sections.\n";
echo "  4. Smart Diff shows 'First sync (full link)' badge when catalog unlinked.\n";
