<?php
/**
 * Plugin uninstall cleanup — runs on Freemius after_uninstall hook.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Uninstaller' ) ) :

class SheetSync_Uninstaller {

	public static function uninstall(): void {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'sheetsync_connections',
			$wpdb->prefix . 'sheetsync_field_maps',
			$wpdb->prefix . 'sheetsync_logs',
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		$options = array(
			'sheetsync_settings',
			'sheetsync_webhook_secret',
			'sheetsync_pro_test_mode',
			'sheetsync_auto_sync_settings',
			'sheetsync_db_version',
			'sheetsync_service_account',
			'sheetsync_google_token_cache',
			'sheetsync_allowed_product_meta_keys',
			'sheetsync_multisite_stock_targets',
			'sheetsync_remote_stock_incoming_secret',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		wp_clear_scheduled_hook( 'sheetsync_scheduled_sync' );

		$transients = array(
			'sheetsync_admin_notices',
			'sheetsync_all_connections',
			'sheetsync_active_product_connections',
			'sheetsync_google_token',
		);

		foreach ( $transients as $transient ) {
			delete_transient( $transient );
		}

		$wpdb->query(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
				 OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
				 OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
				 OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
				 OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_sheetsync_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_sheetsync_' ) . '%',
				$wpdb->esc_like( 'sheetsync_dismissed_' ) . '%',
				$wpdb->esc_like( '_transient_sheetsync_rl_' ) . '%',
				$wpdb->esc_like( 'sheetsync_date_filter_type_' ) . '%',
				$wpdb->esc_like( 'sheetsync_date_filter_single_' ) . '%',
				$wpdb->esc_like( 'sheetsync_date_filter_from_' ) . '%',
				$wpdb->esc_like( 'sheetsync_date_filter_to_' ) . '%',
				$wpdb->esc_like( 'sheetsync_schedule_' ) . '%',
				$wpdb->esc_like( 'sheetsync_sync_category_ids_' ) . '%',
				$wpdb->esc_like( 'sheetsync_hidden_sheet_columns_' ) . '%',
				$wpdb->esc_like( 'sheetsync_category_block_unknown_' ) . '%',
				$wpdb->esc_like( 'sheetsync_product_sheet_mode_' ) . '%',
				$wpdb->esc_like( 'sheetsync_webhook_verified_' ) . '%',
				$wpdb->esc_like( 'sheetsync_webhook_secret_' ) . '%',
				$wpdb->esc_like( 'sheetsync_auto_sync_' ) . '%',
				$wpdb->esc_like( 'sheetsync_dashboard_' ) . '%'
			)
		);

		$wpdb->query(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( 'sheetsync_dismissed_' ) . '%'
			)
		);
	}
}

endif;
