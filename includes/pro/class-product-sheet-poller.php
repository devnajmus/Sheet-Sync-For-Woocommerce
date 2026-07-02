<?php
/**
 * PRO: Poll Google Sheets for product edits (incremental hash, no inbound webhook).
 *
 * Reuses Pull_From_Sheet_Service windowed reads — only changed rows update WooCommerce.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Product_Sheet_Poller {

	public const HOOK             = 'sheetsync_product_sheet_poll';
	public const GROUP            = 'sheetsync';
	public const DEFAULT_INTERVAL = 180; // 3 minutes.
	public const MIN_INTERVAL     = 60;
	public const MAX_INTERVAL     = 600;
	public const DEFAULT_BATCH    = 75;
	public const RUN_DEADLINE_SEC = 25;

	public static function init(): void {
		new self();
		add_action( 'init', array( __CLASS__, 'maybe_sync_schedule' ), 25 );
	}

	public function __construct() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
	}

	public static function get_interval_seconds(): int {
		$interval = (int) apply_filters( 'sheetsync_product_poll_interval_seconds', self::DEFAULT_INTERVAL );
		return max( self::MIN_INTERVAL, min( self::MAX_INTERVAL, $interval ) );
	}

	public static function get_batch_size(): int {
		$size = (int) apply_filters( 'sheetsync_product_poll_batch_size', self::DEFAULT_BATCH );
		return max( 25, min( 200, $size ) );
	}

	public static function get_interval_label(): string {
		$seconds = self::get_interval_seconds();
		if ( $seconds < 60 ) {
			return sprintf(
				/* translators: %d: number of seconds */
				_n( 'every %d second', 'every %d seconds', $seconds, 'sheetsync-for-woocommerce' ),
				$seconds
			);
		}
		if ( 0 === $seconds % 60 ) {
			$minutes = (int) ( $seconds / 60 );
			return sprintf(
				/* translators: %d: number of minutes */
				_n( 'every %d minute', 'every %d minutes', $minutes, 'sheetsync-for-woocommerce' ),
				$minutes
			);
		}
		return sprintf(
			/* translators: %d: number of seconds */
			__( 'every %d seconds', 'sheetsync-for-woocommerce' ),
			$seconds
		);
	}

	/**
	 * @param array<string, array{interval:int,display:string}> $schedules
	 * @return array<string, array{interval:int,display:string}>
	 */
	public function add_cron_intervals( array $schedules ): array {
		$interval = self::get_interval_seconds();
		$schedules['sheetsync_product_poll'] = array(
			'interval' => $interval,
			'display'  => sprintf(
				/* translators: %s: human interval e.g. every 3 minutes */
				__( 'SheetSync product poll (%s)', 'sheetsync-for-woocommerce' ),
				self::get_interval_label()
			),
		);
		return $schedules;
	}

	/**
	 * Product connections with real-time enabled and sheet → WC direction.
	 *
	 * @return array<int, object>
	 */
	public static function product_realtime_connections(): array {
		$auto_sync = get_option( 'sheetsync_auto_sync_settings', array() );
		if ( ! is_array( $auto_sync ) ) {
			return array();
		}

		$connections = array();
		foreach ( SheetSync_Sync_Engine::get_active_connections( 'products' ) as $conn ) {
			if ( empty( $auto_sync[ (int) $conn->id ] ) ) {
				continue;
			}
			if ( function_exists( 'sheetsync_connection_allows_pull' ) && ! sheetsync_connection_allows_pull( $conn ) ) {
				continue;
			}
			$connections[] = $conn;
		}

		return $connections;
	}

	public static function is_needed(): bool {
		return ! empty( self::product_realtime_connections() );
	}

	public static function sync_schedule(): void {
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			self::clear_schedule();
			return;
		}

		if ( self::is_needed() ) {
			self::clear_schedule();
			update_option( 'sheetsync_product_poll_interval', self::get_interval_seconds(), false );
			self::ensure_scheduled();
		} else {
			self::clear_schedule();
		}
	}

	public static function maybe_sync_schedule(): void {
		$wanted = self::get_interval_seconds();
		$stored = (int) get_option( 'sheetsync_product_poll_interval', 0 );

		if ( self::is_needed() ) {
			if ( ! self::is_scheduled() || $stored !== $wanted ) {
				self::clear_schedule();
				update_option( 'sheetsync_product_poll_interval', $wanted, false );
				self::ensure_scheduled();
			}
		} elseif ( self::is_scheduled() ) {
			self::clear_schedule();
		}
	}

	public static function is_scheduled(): bool {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			if ( as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
				return true;
			}
		}
		return (bool) wp_next_scheduled( self::HOOK );
	}

	public static function get_next_run_timestamp(): ?int {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::HOOK, array(), self::GROUP );
			if ( $next ) {
				return (int) $next;
			}
		}
		$wp = wp_next_scheduled( self::HOOK );
		return $wp ? (int) $wp : null;
	}

	public static function get_next_run_label(): string {
		$ts = self::get_next_run_timestamp();
		if ( ! $ts ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	private static function ensure_scheduled(): void {
		$interval = self::get_interval_seconds();

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action(
				time() + $interval,
				$interval,
				self::HOOK,
				array(),
				self::GROUP
			);
			return;
		}

		wp_schedule_event( time() + $interval, 'sheetsync_product_poll', self::HOOK );
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		delete_option( 'sheetsync_product_poll_interval' );
	}

	/**
	 * @return array<int, int>
	 */
	private static function get_cursors(): array {
		$cursors = get_option( 'sheetsync_product_poll_cursors', array() );
		return is_array( $cursors ) ? $cursors : array();
	}

	/**
	 * @param array<int, int> $cursors
	 */
	private static function save_cursors( array $cursors ): void {
		update_option( 'sheetsync_product_poll_cursors', $cursors, false );
	}

	public static function reset_connection_cursor( int $connection_id ): void {
		if ( $connection_id < 1 ) {
			return;
		}
		$cursors = self::get_cursors();
		unset( $cursors[ $connection_id ] );
		self::save_cursors( $cursors );
	}

	/**
	 * Incremental pull for all real-time product connections.
	 */
	public function run(): void {
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			self::clear_schedule();
			return;
		}

		if ( ! self::is_needed() ) {
			self::clear_schedule();
			return;
		}

		if ( ! class_exists( 'SheetSync_Pull_From_Sheet_Service', false ) ) {
			return;
		}

		$service     = new SheetSync_Pull_From_Sheet_Service();
		$connections = self::product_realtime_connections();
		$deadline    = time() + self::RUN_DEADLINE_SEC;
		$batch_size  = self::get_batch_size();
		$cursors     = self::get_cursors();

		$total_processed = 0;
		$total_skipped   = 0;
		$total_errors    = 0;

		foreach ( $connections as $conn ) {
			if ( time() >= $deadline ) {
				break;
			}

			$connection_id = (int) $conn->id;
			if ( function_exists( 'sheetsync_is_bulk_import_active' ) && sheetsync_is_bulk_import_active( $connection_id ) ) {
				continue;
			}
			if ( function_exists( 'sheetsync_is_sync_locked' ) && sheetsync_is_sync_locked( $connection_id ) ) {
				continue;
			}

			$poll_slot_held = false;
			$state_repo     = class_exists( 'SheetSync_Sync_State_Repository', false )
				? new SheetSync_Sync_State_Repository()
				: null;
			if ( $state_repo && ! $state_repo->try_realtime_slot( $connection_id, 90 ) ) {
				continue;
			}
			$poll_slot_held = (bool) $state_repo;

			$cursor = (int) ( $cursors[ $connection_id ] ?? 0 );
			$mode   = 'incremental';
			if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
				$map_repo   = new SheetSync_Product_Map_Repository();
				$map_total  = $map_repo->count_for_connection( $connection_id );
				$orphaned   = $map_repo->count_orphaned_maps( $connection_id );
				if ( $map_total > 0 && $orphaned >= max( 1, (int) floor( $map_total * 0.5 ) ) ) {
					$mode = 'full';
				} elseif ( $map_total < 1 ) {
					$mode = 'full';
				}
			}
			$job    = (object) array(
				'connection_id' => $connection_id,
				'cursor_offset' => $cursor,
				'mode'          => $mode,
			);

			$conn_processed  = 0;
			$full_pass_done  = false;

			try {
				do {
					if ( time() >= $deadline ) {
						break;
					}

					try {
						$batch = $service->process_batch( $job, $conn, $batch_size, $deadline );
					} catch ( SheetSync_Rate_Limit_Exception $e ) {
						SheetSync_Logger::error( 'Product sheet poll: Google API rate limit — will retry on next poll.' );
						break 2;
					}

					$total_processed += (int) ( $batch['processed'] ?? 0 );
					$total_skipped   += (int) ( $batch['skipped'] ?? 0 );
					$total_errors    += (int) ( $batch['errors'] ?? 0 );
					$conn_processed  += (int) ( $batch['processed'] ?? 0 );

					$advance = (int) ( $batch['advance'] ?? 0 );
					if ( $advance > 0 ) {
						$job->cursor_offset += $advance;
					}

					if ( ! empty( $batch['done'] ) ) {
						$job->cursor_offset = 0;
						$full_pass_done     = true;
						break;
					}
				} while ( time() < $deadline );
			} finally {
				if ( $poll_slot_held && $state_repo ) {
					$state_repo->release_realtime_slot( $connection_id );
				}
			}

			$cursors[ $connection_id ] = (int) $job->cursor_offset;

			if ( $full_pass_done && class_exists( 'SheetSync_Import_Row_Service', false ) ) {
				SheetSync_Import_Row_Service::end_import_run( $connection_id );
			}

			if ( $conn_processed > 0 ) {
				SheetSync_Sync_Engine::touch_connection_sync_time( $connection_id );
			}
		}

		self::save_cursors( $cursors );

		// Drain variation rows queued when a parent was not ready in an earlier poll tick.
		if ( class_exists( 'SheetSync_Import_Row_Service', false ) && class_exists( 'SheetSync_Field_Mapper', false ) ) {
			foreach ( $connections as $conn ) {
				if ( time() >= $deadline ) {
					break;
				}
				$connection_id = (int) $conn->id;
				if ( ! SheetSync_Import_Row_Service::has_pending_deferred_imports( $connection_id ) ) {
					continue;
				}
				$maps = SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn );
				if ( empty( $maps ) ) {
					continue;
				}
				SheetSync_Import_Row_Service::set_current_connection_id( $connection_id );
				SheetSync_Import_Row_Service::begin_batch();
				$retry = SheetSync_Import_Row_Service::retry_deferred_import_passes( $connection_id, $maps, true );
				SheetSync_Import_Row_Service::end_batch();
				$total_processed += (int) ( $retry['processed'] ?? 0 );
				$total_skipped   += (int) ( $retry['skipped'] ?? 0 );
				$total_errors    += (int) ( $retry['errors'] ?? 0 );
			}
		}

		$result = array(
			'processed' => $total_processed,
			'skipped'   => $total_skipped,
			'errors'    => $total_errors,
		);

		update_option( 'sheetsync_product_poll_last_run', time(), false );
		update_option( 'sheetsync_product_poll_last_result', $result, false );

		if ( $total_errors > 0 ) {
			SheetSync_Logger::error(
				sprintf(
					'Product sheet poll: processed %d, errors %d',
					$total_processed,
					$total_errors
				)
			);
		}
	}
}
