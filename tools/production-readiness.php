<?php
/**
 * Production readiness audit — run before shipping a release.
 *
 * Usage (from WordPress site root):
 *   wp eval-file wp-content/plugins/sheetsync-for-woocommerce/tools/production-readiness.php
 *
 * Optional connection ID for deeper checks:
 *   wp eval-file .../production-readiness.php -- 3
 *
 * @package SheetSync_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI from site root:\n  wp eval-file wp-content/plugins/sheetsync-for-woocommerce/tools/production-readiness.php\n" );
	exit( 1 );
}

$connection_id = isset( $args[0] ) ? absint( $args[0] ) : 0;

$fail = 0;
$warn = 0;
$ok   = 0;

/**
 * @param 'ok'|'warn'|'fail'|'info' $level
 */
$report = static function ( string $level, string $message ) use ( &$fail, &$warn, &$ok ): void {
	$prefix = match ( $level ) {
		'ok'   => '[OK]  ',
		'warn' => '[WARN]',
		'fail' => '[FAIL]',
		default => '[INFO]',
	};
	echo $prefix . ' ' . $message . "\n";
	if ( 'fail' === $level ) {
		++$fail;
	} elseif ( 'warn' === $level ) {
		++$warn;
	} elseif ( 'ok' === $level ) {
		++$ok;
	}
};

echo "\n";
echo "SheetSync Production Readiness Audit\n";
echo str_repeat( '=', 50 ) . "\n\n";

// ── 1. Environment ───────────────────────────────────────────────────────────
echo "--- Environment ---\n";

$php_min = '8.0.0';
$report( version_compare( PHP_VERSION, $php_min, '>=' ) ? 'ok' : 'fail', 'PHP ' . PHP_VERSION . ' (requires ' . $php_min . '+)' );
$report( defined( 'ABSPATH' ) ? 'ok' : 'fail', 'WordPress loaded' );
$report( class_exists( 'WooCommerce', false ) ? 'ok' : 'fail', 'WooCommerce active' );

global $wp_version;
$report( version_compare( (string) $wp_version, '6.0', '>=' ) ? 'ok' : 'warn', 'WordPress ' . (string) $wp_version );

$mem = ini_get( 'memory_limit' );
$report( 'info', 'memory_limit=' . (string) $mem );
$max_exec = (int) ini_get( 'max_execution_time' );
$report( $max_exec >= 60 || 0 === $max_exec ? 'ok' : 'warn', 'max_execution_time=' . $max_exec . 's (0=unlimited)' );

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	$report( 'warn', 'WP_DEBUG is ON — turn off before production release on live stores' );
} else {
	$report( 'ok', 'WP_DEBUG is off' );
}

if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
	$report( 'info', 'WP_DEBUG_LOG is on (good for staging audit)' );
} else {
	$report( 'ok', 'WP_DEBUG_LOG is off (production default)' );
}

if ( function_exists( 'sheetsync_perf_log_enabled' ) && sheetsync_perf_log_enabled() ) {
	$report( 'warn', 'SHEETSYNC_PERF_LOG is on — disable before production zip' );
} else {
	$report( 'ok', 'SHEETSYNC_PERF_LOG is off' );
}

$expected_build = '20260630-prepare-lock';
$actual_build   = defined( 'SHEETSYNC_BUILD' ) ? (string) SHEETSYNC_BUILD : '';
$report(
	$actual_build === $expected_build ? 'ok' : 'fail',
	'SHEETSYNC_BUILD=' . ( $actual_build !== '' ? $actual_build : '(undefined)' ) . ' expected=' . $expected_build
);

// ── 2. Database schema ───────────────────────────────────────────────────────
echo "\n--- Database ---\n";

global $wpdb;

$tables = array(
	'sheetsync_connections',
	'sheetsync_field_maps',
	'sheetsync_logs',
);
if ( class_exists( 'SheetSync_Schema', false ) ) {
	$tables[] = str_replace( $wpdb->prefix, '', SheetSync_Schema::table( 'product_map' ) );
	$tables[] = str_replace( $wpdb->prefix, '', SheetSync_Schema::table( 'sync_jobs' ) );
}

foreach ( $tables as $table_suffix ) {
	$full = $wpdb->prefix . $table_suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
	$report( $exists === $full ? 'ok' : 'fail', "Table {$full}" );
}

if ( class_exists( 'SheetSync_Schema', false ) ) {
	$installed = (string) get_option( 'sheetsync_schema_version', '' );
	$expected  = (string) SheetSync_Schema::DB_VERSION;
	$report(
		$installed === $expected ? 'ok' : 'warn',
		"Schema version installed={$installed} expected={$expected}"
	);
}

// ── 3. Critical SQL prepare fix (product_map prefetch) ───────────────────────
echo "\n--- SQL / product_map ---\n";

if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
	$ref    = new ReflectionClass( 'SheetSync_Product_Map_Repository' );
	$method = $ref->getMethod( 'prefetch_by_external_keys' );
	$source = file_get_contents( $method->getFileName() );
	$report(
		is_string( $source ) && str_contains( $source, '...$params' ) ? 'ok' : 'fail',
		'product_map prefetch uses spread operator (...$params) for wpdb::prepare'
	);

	if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
		$import_ref = new ReflectionClass( 'SheetSync_Import_Row_Service' );
		if ( $import_ref->hasMethod( 'delete_variation_retry_rows' ) ) {
			$import_method = $import_ref->getMethod( 'delete_variation_retry_rows' );
			$import_source = file_get_contents( $import_method->getFileName() );
			$bad_import    = is_string( $import_source )
				&& preg_match( '/delete_variation_retry_rows[\s\S]*?prepare\([\s\S]*?,\s*\$params\s*\)/', $import_source )
				&& ! preg_match( '/delete_variation_retry_rows[\s\S]*?\.\.\.\$params/', $import_source );
			$report(
				! $bad_import ? 'ok' : 'fail',
				'variation_retry delete uses spread operator (...$params)'
			);
		}
	}

	if ( $connection_id < 1 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$connection_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}sheetsync_connections WHERE status = %s ORDER BY id ASC LIMIT 1",
				'active'
			)
		);
	}

	if ( $connection_id > 0 ) {
		try {
			$repo = new SheetSync_Product_Map_Repository();
			$repo->prefetch_by_external_keys( $connection_id, array( '__sheetsync_probe__' ) );
			$report( 'ok', "prefetch_by_external_keys() runtime OK (connection #{$connection_id})" );
		} catch ( Throwable $e ) {
			$report( 'fail', 'prefetch_by_external_keys() threw: ' . $e->getMessage() );
		}

		$conflicts = $repo->list_conflicts( $connection_id );
		$report( empty( $conflicts ) ? 'ok' : 'warn', 'Queued conflicts: ' . count( $conflicts ) );

		$orphans = $repo->count_orphaned_maps( $connection_id );
		$report( 0 === $orphans ? 'ok' : 'warn', "Orphaned product maps: {$orphans}" );
	} else {
		$report( 'info', 'No active connection — skip live prefetch test' );
	}
} else {
	$report( 'fail', 'SheetSync_Product_Map_Repository not loaded' );
}

// ── 4. Background tasks ──────────────────────────────────────────────────────
echo "\n--- Action Scheduler / Cron ---\n";

if ( function_exists( 'sheetsync_get_action_scheduler_health' ) ) {
	$as = sheetsync_get_action_scheduler_health();
	$report( ! empty( $as['ok'] ) ? 'ok' : 'warn', (string) ( $as['message'] ?? '' ) );
} else {
	$report( 'warn', 'sheetsync_get_action_scheduler_health() unavailable' );
}

if ( class_exists( 'SheetSync_External_Cron', false ) ) {
	$operational = SheetSync_External_Cron::is_operational();
	$report( $operational ? 'ok' : 'warn', $operational ? 'Background cron URL operational' : 'Background cron not operational (enable in Settings)' );
}

if ( class_exists( 'SheetSync_Order_Sheet_Poller', false ) ) {
	$report(
		SheetSync_Order_Sheet_Poller::is_needed() ? ( SheetSync_Order_Sheet_Poller::is_scheduled() ? 'ok' : 'warn' ) : 'ok',
		SheetSync_Order_Sheet_Poller::is_needed()
			? ( SheetSync_Order_Sheet_Poller::is_scheduled() ? 'Order sheet poll scheduled' : 'Order auto-sync on but poll not scheduled' )
			: 'Order sheet poll not required'
	);
	$last = SheetSync_Order_Sheet_Poller::get_last_run_label();
	if ( $last ) {
		$report( 'info', $last );
	}
}

// ── 5. Google & connections ────────────────────────────────────────────────────
echo "\n--- Connections ---\n";

$google_email = class_exists( 'SheetSync_Google_Auth', false ) ? SheetSync_Google_Auth::get_account_email() : '';
$report( $google_email ? 'ok' : 'warn', $google_email ? 'Google connected: ' . $google_email : 'Google not connected' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$connections = $wpdb->get_results( "SELECT id, name, connection_type, sync_direction, status FROM {$wpdb->prefix}sheetsync_connections ORDER BY id ASC" );
$report( 'info', 'Total connections: ' . count( (array) $connections ) );

foreach ( (array) $connections as $conn ) {
	$report(
		'active' === ( $conn->status ?? '' ) ? 'ok' : 'info',
		sprintf(
			'#%d %s | %s | %s | %s',
			(int) $conn->id,
			(string) $conn->name,
			(string) $conn->connection_type,
			(string) $conn->sync_direction,
			(string) $conn->status
		)
	);
}

// ── 6. Catalog breakdown (stress sizing) ─────────────────────────────────────
echo "\n--- Catalog size ---\n";

if ( $connection_id > 0 && function_exists( 'sheetsync_count_exportable_breakdown' ) ) {
	$bd = sheetsync_count_exportable_breakdown( $connection_id );
	$report( 'info', sprintf(
		'Connection #%d: %d sheet rows (%d parents + %d variations)',
		$connection_id,
		(int) ( $bd['sheet_rows'] ?? 0 ),
		(int) ( $bd['parent_products'] ?? 0 ),
		(int) ( $bd['variations'] ?? 0 )
	) );
	if ( (int) ( $bd['export_duplicate_ids'] ?? 0 ) > 0 ) {
		$report( 'warn', 'Export duplicate product IDs detected: ' . (int) $bd['export_duplicate_ids'] );
	}
}

if ( class_exists( 'SheetSync_Host_Profile', false ) ) {
	$batch = SheetSync_Host_Profile::max_pull_batch();
	$report( 'info', 'Adaptive pull batch size: ' . $batch );
}

// ── 7. Recent plugin errors ────────────────────────────────────────────────────
echo "\n--- Recent SheetSync log errors ---\n";

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$recent_errors = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT connection_id, message, created_at FROM {$wpdb->prefix}sheetsync_logs
		WHERE status IN ('error', 'partial') ORDER BY created_at DESC LIMIT %d",
		10
	)
);

if ( empty( $recent_errors ) ) {
	$report( 'ok', 'No recent error/partial log entries' );
} else {
	foreach ( $recent_errors as $row ) {
		$report( 'warn', sprintf( '[conn %d] %s — %s', (int) $row->connection_id, (string) $row->created_at, wp_strip_all_tags( (string) $row->message ) ) );
	}
}

// ── 8. Sync engine smoke (from verify-sync-fixes) ─────────────────────────────
echo "\n--- Sync engine checks ---\n";

$report(
	has_action( 'wp_ajax_sheetsync_sync_tick' ) ? 'ok' : 'fail',
	'wp_ajax_sheetsync_sync_tick registered'
);

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
	$report( ! $method->invoke( $repo, $fake_job ) ? 'ok' : 'fail', 'Export job does not auto-close on processed≈total alone' );
}

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n" . str_repeat( '=', 50 ) . "\n";
echo "Summary: {$ok} passed, {$warn} warnings, {$fail} failures\n";

if ( $fail > 0 ) {
	echo "\nFix FAIL items before production release.\n";
	exit( 1 );
}

if ( $warn > 0 ) {
	echo "\nReview WARN items (may be environment-specific).\n";
}

echo "\nManual regression checklist (run on staging):\n";
echo "  [ ] Product WC→Sheet export (114 rows / variable parents)\n";
echo "  [ ] Product Sheet→WC import (full + update-only + add-new)\n";
echo "  [ ] Round-trip: export → delete WC → import all\n";
echo "  [ ] Order Processing→Completed (sheet column C + row move)\n";
echo "  [ ] Automatic sync ON + Smart Poll / Sync now\n";
echo "  [ ] Two-way conflict merge / queue\n";
echo "  [ ] debug.log: no PHP Notice/Warning from SheetSync\n";
echo "  [ ] Disable WP_DEBUG_LOG + SHEETSYNC_PERF_LOG → re-run this script\n";
echo "  [ ] Package: php tools/package-production.php\n";
echo "\n";

exit( 0 );
