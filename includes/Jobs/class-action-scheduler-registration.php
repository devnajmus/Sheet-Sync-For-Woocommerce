<?php
/**
 * Registers Action Scheduler hooks for SheetSync jobs.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Action_Scheduler_Registration' ) ) :

class SheetSync_Action_Scheduler_Registration {

	private const DEFERRED_PUSH_BATCH = 50;

	public function __construct() {
		add_action( 'sheetsync_run_job', array( $this, 'run_job' ), 10, 1 );
		add_action( 'sheetsync_batch_pull', array( $this, 'run_job' ), 10, 1 );
		add_action( 'sheetsync_batch_push', array( $this, 'run_job' ), 10, 1 );
		add_action( 'sheetsync_deferred_push', array( $this, 'deferred_push' ), 10, 1 );
		add_action( 'sheetsync_process_media_queue', array( $this, 'process_media_queue' ), 10, 1 );
	}

	public function run_job( int $job_id ): void {
		if ( ! defined( 'SHEETSYNC_BG_SYNC' ) ) {
			define( 'SHEETSYNC_BG_SYNC', true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( max( 30, (int) apply_filters( 'sheetsync_as_job_time_limit', 45 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.discouraged
		}
		wp_raise_memory_limit( 'admin' );
		( new SheetSync_Job_Runner() )->tick( $job_id );
	}

	public function deferred_push( int $connection_id ): void {
		$key   = 'sheetsync_dirty_products_' . $connection_id;
		$dirty = get_transient( $key );
		if ( ! is_array( $dirty ) || empty( $dirty ) ) {
			return;
		}

		$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
		if ( ! $conn || ! sheetsync_connection_allows_push( $conn ) ) {
			delete_transient( $key );
			return;
		}

		if ( function_exists( 'sheetsync_is_sync_locked' ) && sheetsync_is_sync_locked( $connection_id ) ) {
			$this->reschedule_deferred_push( $connection_id, 30 );
			return;
		}

		$state_repo = class_exists( 'SheetSync_Sync_State_Repository', false )
			? new SheetSync_Sync_State_Repository()
			: null;
		if ( $state_repo && ! $state_repo->try_realtime_slot( $connection_id, 120 ) ) {
			$this->reschedule_deferred_push( $connection_id, 15 );
			return;
		}

		try {
			$pusher      = new SheetSync_Push_To_Sheet_Service();
			$product_ids = array_slice( array_keys( $dirty ), 0, self::DEFERRED_PUSH_BATCH );

			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( (int) $product_id );
				if ( ! $product ) {
					unset( $dirty[ $product_id ] );
					continue;
				}
				$pushed = $pusher->push_single_product_unlocked( $product, $conn );
				if ( $pushed ) {
					unset( $dirty[ $product_id ] );
				}
			}

			if ( ! empty( $dirty ) ) {
				set_transient( $key, $dirty, 15 * MINUTE_IN_SECONDS );
				$this->reschedule_deferred_push( $connection_id, 5 );
			} else {
				delete_transient( $key );
			}
		} finally {
			if ( $state_repo ) {
				$state_repo->release_realtime_slot( $connection_id );
			}
		}
	}

	private function reschedule_deferred_push( int $connection_id, int $delay_seconds ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( as_next_scheduled_action( 'sheetsync_deferred_push', array( $connection_id ), 'sheetsync' ) ) {
			return;
		}
		as_schedule_single_action(
			time() + max( 1, $delay_seconds ),
			'sheetsync_deferred_push',
			array( $connection_id ),
			'sheetsync'
		);
	}

	public function process_media_queue( int $connection_id = 0 ): void {
		if ( ! class_exists( 'SheetSync_Media_Queue', false ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( max( 30, (int) apply_filters( 'sheetsync_media_queue_time_limit', 45 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.discouraged
		}
		wp_raise_memory_limit( 'admin' );
		$deadline = time() + (int) apply_filters( 'sheetsync_media_queue_deadline_seconds', 40 );
		SheetSync_Media_Queue::process_batch( $connection_id, 0, $deadline );
	}
}

endif;
