<?php
/**
 * Database schema v2 — enterprise sync tables.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Schema' ) ) :

class SheetSync_Schema {

	public const DB_VERSION = '2.0.8';

	/**
	 * Run all migrations (activation + plugins_loaded upgrade).
	 */
	public static function migrate(): void {
		global $wpdb;

		$installed = (string) get_option( 'sheetsync_schema_version', '' );

		if ( version_compare( $installed, '2.0.0', '<' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset = $wpdb->get_charset_collate();

			$state_sql = "CREATE TABLE {$wpdb->prefix}sheetsync_sync_state (
			connection_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'idle',
			current_job_id BIGINT UNSIGNED DEFAULT NULL,
			last_full_sync_at DATETIME DEFAULT NULL,
			last_pull_at DATETIME DEFAULT NULL,
			last_push_at DATETIME DEFAULT NULL,
			wc_watermark_gmt DATETIME DEFAULT NULL,
			sheet_revision VARCHAR(64) DEFAULT NULL,
			lock_token VARCHAR(36) DEFAULT NULL,
			lock_expires_at DATETIME DEFAULT NULL,
			realtime_slot_expires_at DATETIME DEFAULT NULL,
			settings_json LONGTEXT DEFAULT NULL,
			PRIMARY KEY (connection_id),
			KEY status (status)
		) $charset;";

		$jobs_sql = "CREATE TABLE {$wpdb->prefix}sheetsync_sync_jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id BIGINT UNSIGNED NOT NULL,
			direction VARCHAR(20) NOT NULL,
			mode VARCHAR(20) NOT NULL DEFAULT 'incremental',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			phase VARCHAR(30) NOT NULL DEFAULT 'init',
			cursor_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cursor_meta LONGTEXT DEFAULT NULL,
			total_estimate INT UNSIGNED DEFAULT NULL,
			processed_count INT UNSIGNED NOT NULL DEFAULT 0,
			skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
			error_count INT UNSIGNED NOT NULL DEFAULT 0,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
			last_error TEXT DEFAULT NULL,
			triggered_by VARCHAR(30) NOT NULL DEFAULT 'manual',
			started_at DATETIME DEFAULT NULL,
			finished_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY connection_status (connection_id, status),
			KEY created_at (created_at)
		) $charset;";

		$map_sql = "CREATE TABLE {$wpdb->prefix}sheetsync_product_map (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			external_key VARCHAR(191) NOT NULL DEFAULT '',
			sheet_row INT UNSIGNED DEFAULT NULL,
			wc_hash CHAR(32) DEFAULT NULL,
			sheet_hash CHAR(32) DEFAULT NULL,
			wc_modified_gmt DATETIME DEFAULT NULL,
			sheet_updated_at DATETIME DEFAULT NULL,
			last_pulled_at DATETIME DEFAULT NULL,
			last_pushed_at DATETIME DEFAULT NULL,
			sync_status VARCHAR(20) NOT NULL DEFAULT 'ok',
			conflict_json LONGTEXT DEFAULT NULL,
			meta_json LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY conn_external (connection_id, external_key),
			KEY conn_product (connection_id, product_id),
			KEY conn_sheet_row (connection_id, sheet_row),
			KEY conn_status (connection_id, sync_status),
			KEY conn_wc_modified (connection_id, wc_modified_gmt)
		) $charset;";

		$conflicts_sql = "CREATE TABLE {$wpdb->prefix}sheetsync_conflicts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id BIGINT UNSIGNED NOT NULL,
			map_id BIGINT UNSIGNED DEFAULT NULL,
			product_id BIGINT UNSIGNED DEFAULT NULL,
			external_key VARCHAR(191) DEFAULT NULL,
			sheet_row INT UNSIGNED DEFAULT NULL,
			field_key VARCHAR(100) NOT NULL,
			wc_value LONGTEXT DEFAULT NULL,
			sheet_value LONGTEXT DEFAULT NULL,
			resolution VARCHAR(20) NOT NULL DEFAULT 'pending',
			detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			resolved_at DATETIME DEFAULT NULL,
			job_id BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY (id),
			KEY connection_pending (connection_id, resolution),
			KEY map_id (map_id)
		) $charset;";

		dbDelta( $state_sql );
		dbDelta( $jobs_sql );
		dbDelta( $map_sql );
		dbDelta( $conflicts_sql );

			self::maybe_add_logs_job_id_column();

			if ( ! get_option( 'sheetsync_use_job_engine' ) ) {
				update_option( 'sheetsync_use_job_engine', true, false );
			}
			if ( get_option( 'sheetsync_legacy_two_way_full_push' ) === false ) {
				update_option( 'sheetsync_legacy_two_way_full_push', false, false );
			}

			$installed = '2.0.0';
		}

		if ( version_compare( $installed, '2.0.1', '<' ) ) {
			self::maybe_add_realtime_slot_column();
			if ( ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
				require_once SHEETSYNC_DIR . 'includes/Repositories/class-product-map-repository.php';
			}
			SheetSync_Product_Map_Repository::repair_empty_external_keys();
			$installed = '2.0.1';
		}

		if ( version_compare( $installed, '2.0.2', '<' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset = $wpdb->get_charset_collate();
			$var_retry_sql = "CREATE TABLE {$wpdb->prefix}sheetsync_variation_retry (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id BIGINT UNSIGNED NOT NULL,
			sheet_row INT UNSIGNED NOT NULL,
			row_json LONGTEXT NOT NULL,
			data_json LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY conn_sheet_row (connection_id, sheet_row),
			KEY connection_id (connection_id)
		) $charset;";
			dbDelta( $var_retry_sql );
			$installed = '2.0.2';
		}

		if ( version_compare( $installed, '2.0.3', '<' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset = $wpdb->get_charset_collate();
			$media_sql = "CREATE TABLE {$wpdb->prefix}sheetsync_media_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL,
			field_key VARCHAR(32) NOT NULL,
			field_value TEXT NOT NULL,
			value_hash CHAR(32) NOT NULL DEFAULT '',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY conn_product_field_hash (connection_id, product_id, field_key, value_hash),
			KEY connection_id (connection_id),
			KEY product_id (product_id)
		) $charset;";
			dbDelta( $media_sql );
			$installed = '2.0.3';
		}

		if ( version_compare( $installed, '2.0.4', '<' ) ) {
			if ( ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
				require_once SHEETSYNC_DIR . 'includes/Repositories/class-product-map-repository.php';
			}
			SheetSync_Product_Map_Repository::dedupe_sheet_row_mappings();
			SheetSync_Product_Map_Repository::ensure_unique_sheet_row_index();
			$installed = '2.0.4';
		}

		if ( version_compare( $installed, '2.0.5', '<' ) ) {
			if ( function_exists( 'sheetsync_migrate_connection_webhook_secrets' ) ) {
				sheetsync_migrate_connection_webhook_secrets();
			}
			$installed = '2.0.5';
		}

		if ( version_compare( $installed, '2.0.6', '<' ) ) {
			if ( function_exists( 'sheetsync_cleanup_legacy_rate_limit_options' ) ) {
				sheetsync_cleanup_legacy_rate_limit_options();
			}
			$installed = '2.0.6';
		}

		if ( version_compare( $installed, '2.0.7', '<' ) ) {
			// Unused since v2.0.0 — job progress is tracked via sync_jobs counters only.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}sheetsync_sync_job_items`" );
			$installed = '2.0.7';
		}

		if ( version_compare( $installed, '2.0.8', '<' ) ) {
			if ( ! class_exists( 'SheetSync_Host_Profile', false ) ) {
				require_once dirname( __FILE__ ) . '/class-sheetsync-host-profile.php';
			}
			$settings = get_option( 'sheetsync_settings', array() );
			if ( is_array( $settings ) && (int) ( $settings['batch_size'] ?? 0 ) === 50 ) {
				$settings['batch_size'] = class_exists( 'SheetSync_Host_Profile', false )
					? SheetSync_Host_Profile::default_batch_size()
					: 25;
				update_option( 'sheetsync_settings', $settings, false );
			}
			$installed = '2.0.8';
		}

		if ( $installed !== (string) get_option( 'sheetsync_schema_version', '' ) ) {
			update_option( 'sheetsync_schema_version', self::DB_VERSION, false );
		}
	}

	/**
	 * Add realtime_slot_expires_at for atomic webhook mutex (2.0.1).
	 */
	private static function maybe_add_realtime_slot_column(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sheetsync_sync_state';
		$col   = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			DB_NAME,
			$table,
			'realtime_slot_expires_at'
		) );
		if ( empty( $col ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN realtime_slot_expires_at DATETIME DEFAULT NULL AFTER lock_expires_at" );
		}
	}

	/**
	 * Add job_id to logs if missing.
	 */
	private static function maybe_add_logs_job_id_column(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sheetsync_logs';
		$col   = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			DB_NAME,
			$table,
			'job_id'
		) );
		if ( empty( $col ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN job_id BIGINT UNSIGNED DEFAULT NULL AFTER connection_id" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY job_id (job_id)" );
		}
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'sheetsync_' . $name;
	}
}

endif;
