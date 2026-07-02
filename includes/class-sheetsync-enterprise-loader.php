<?php
/**
 * Loads enterprise sync infrastructure (schema, repositories, jobs).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Enterprise_Loader' ) ) :

class SheetSync_Enterprise_Loader {

	private static bool $loaded = false;

	/** @var list<string> */
	private static array $missing_files = array();

	/**
	 * Core PHP files required for sync (relative to includes/).
	 *
	 * @return list<string>
	 */
	public static function required_relative_paths(): array {
		return array(
			'class-sheetsync-schema.php',
			'class-sheetsync-host-profile.php',
			'class-sheetsync-functions.php',
			'Repositories/class-product-map-repository.php',
			'Repositories/class-sync-state-repository.php',
			'Repositories/class-job-repository.php',
			'Services/Woo/class-hash-normalizer.php',
			'Services/Woo/class-product-resolver.php',
			'Services/Google/class-rate-limiter.php',
			'Services/Sync/class-sync-mode.php',
			'Services/Sync/class-import-row-service.php',
			'Services/Sync/class-conflict-resolver.php',
			'Services/Sync/class-pull-from-sheet-service.php',
			'Services/Sync/class-push-to-sheet-service.php',
			'Services/Sync/class-order-push-service.php',
			'Services/Sync/class-sync-coordinator.php',
			'Services/Sync/class-sync-orchestrator.php',
			'Services/class-match-diagnostics.php',
			'Services/Media/class-media-queue.php',
			'Jobs/class-job-runner.php',
			'Jobs/class-action-scheduler-registration.php',
			'Jobs/class-job-watchdog.php',
			'Jobs/class-external-cron.php',
		);
	}

	public static function init(): void {
		if ( self::$loaded ) {
			return;
		}

		$base = trailingslashit( SHEETSYNC_DIR ) . 'includes/';

		if ( ! self::load_required_files( $base ) ) {
			self::register_missing_files_notice();
			return;
		}

		self::$loaded = true;

		add_action( 'init', array( SheetSync_Schema::class, 'migrate' ), 5 );

		if ( class_exists( 'WooCommerce' ) ) {
			new SheetSync_Action_Scheduler_Registration();
			new SheetSync_Job_Watchdog();
			SheetSync_External_Cron::init();
			add_action( 'before_delete_post', 'sheetsync_handle_product_delete_for_maps', 10, 2 );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			self::require_file( $base . 'class-sheetsync-cli.php' );
		}
	}

	/**
	 * @return list<string> Paths missing from disk on last load attempt.
	 */
	public static function get_missing_files(): array {
		return self::$missing_files;
	}

	public static function is_ready(): bool {
		return self::$loaded;
	}

	/**
	 * @return bool True when every required file was loaded.
	 */
	private static function load_required_files( string $base ): bool {
		self::$missing_files = array();
		$ok                  = true;

		foreach ( self::required_relative_paths() as $relative ) {
			$path = $base . $relative;
			if ( ! is_readable( $path ) ) {
				self::$missing_files[] = $relative;
				$ok                    = false;
				continue;
			}
			require_once $path;
		}

		if ( function_exists( 'sheetsync_perf_log_enabled' ) && sheetsync_perf_log_enabled() ) {
			self::require_file( $base . 'class-sheetsync-sync-tick-perf-log.php' );
		}

		return $ok;
	}

	private static function require_file( string $path ): void {
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	private static function register_missing_files_notice(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'manage_woocommerce' ) || empty( self::$missing_files ) ) {
					return;
				}
				$list = implode( ', ', array_map( 'esc_html', array_slice( self::$missing_files, 0, 5 ) ) );
				echo '<div class="notice notice-error"><p><strong>SheetSync:</strong> ';
				echo esc_html__(
					'Plugin files are missing or the upload is incomplete. Reinstall the full plugin zip (do not upload only changed files).',
					'sheetsync-for-woocommerce'
				);
				echo ' <code>' . $list . '</code></p></div>';
			}
		);
	}
}

endif;
