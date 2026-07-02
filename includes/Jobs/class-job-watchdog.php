<?php
/**
 * Keeps background sync jobs moving when Action Scheduler or WP-Cron stalls.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Job_Watchdog' ) ) :

class SheetSync_Job_Watchdog {

	private const HOOK     = 'sheetsync_job_watchdog';
	private const INTERVAL = 120;

	public function __construct() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 30 );
		add_action( 'admin_init', array( $this, 'maybe_resume_on_admin' ), 40 );
	}

	public function ensure_scheduled(): void {
		if ( ! sheetsync_use_job_engine() || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_unschedule_all_actions' ) ) {
			$pending = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'status'   => 'pending',
					'group'    => 'sheetsync',
					'per_page' => 20,
				),
				'ids'
			);
			if ( is_array( $pending ) && count( $pending ) > 1 ) {
				as_unschedule_all_actions( self::HOOK, array(), 'sheetsync' );
			}
		}

		if ( as_next_scheduled_action( self::HOOK, array(), 'sheetsync' ) ) {
			return;
		}
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action( time() + 30, self::INTERVAL, self::HOOK, array(), 'sheetsync' );
		}
	}

	public function run(): void {
		if ( function_exists( 'sheetsync_resume_active_sync_jobs' ) ) {
			sheetsync_resume_active_sync_jobs( 15, 2 );
		}
	}

	public function maybe_resume_on_admin(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page === '' || ! str_starts_with( $page, 'sheetsync' ) ) {
			return;
		}
		$key = 'sheetsync_admin_resume_' . get_current_user_id();
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 45 );
		if ( function_exists( 'sheetsync_resume_active_sync_jobs' ) ) {
			sheetsync_resume_active_sync_jobs( 8, 1 );
		}
	}
}

endif;
