<?php
/**
 * Sync job repository.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Job_Repository' ) ) :

class SheetSync_Job_Repository {

	private string $table;

	public function __construct() {
		$this->table = SheetSync_Schema::table( 'sync_jobs' );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): int {
		global $wpdb;
		$row = array(
			'connection_id' => (int) ( $data['connection_id'] ?? 0 ),
			'direction'     => sanitize_key( (string) ( $data['direction'] ?? 'two_way' ) ),
			'mode'          => in_array( $data['mode'] ?? 'incremental', array( 'full', 'incremental' ), true )
				? $data['mode']
				: 'incremental',
			'status'        => 'pending',
			'phase'         => 'init',
			'triggered_by'  => sanitize_key( (string) ( $data['triggered_by'] ?? 'manual' ) ),
			'max_attempts'  => max( 5, min( 20, (int) apply_filters( 'sheetsync_job_max_attempts', 10 ) ) ),
			'cursor_meta'   => isset( $data['cursor_meta'] ) ? wp_json_encode( $data['cursor_meta'] ) : null,
		);
		if ( isset( $data['total_estimate'] ) && is_numeric( $data['total_estimate'] ) ) {
			$row['total_estimate'] = max( 0, (int) $data['total_estimate'] );
		}
		$wpdb->insert( $this->table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	public function set_total_estimate( int $job_id, int $total ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array( 'total_estimate' => max( 0, $total ) ),
			array( 'id' => $job_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * @return object|null
	 */
	public function get( int $job_id ) {
		global $wpdb;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $job_id )
		);
	}

	/**
	 * Active jobs across all connections (watchdog / admin resume).
	 *
	 * @return object[]
	 */
	public function get_all_active( int $limit = 8 ): array {
		global $wpdb;
		$limit = max( 1, min( 20, $limit ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE status IN ('pending','running')
				ORDER BY updated_at ASC, id ASC
				LIMIT %d",
				$limit
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$active = array();
		foreach ( $rows as $job ) {
			$job = $this->reconcile_stale_job( $job );
			if ( $job && in_array( (string) $job->status, array( 'pending', 'running' ), true ) ) {
				$active[] = $job;
			}
		}
		return $active;
	}

	/**
	 * @return object|null
	 */
	public function get_running_for_connection( int $connection_id ) {
		global $wpdb;
		$job = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE connection_id = %d AND status IN ('pending','running')
				ORDER BY id DESC LIMIT 1",
				$connection_id
			)
		);
		if ( ! $job ) {
			return null;
		}

		$job = $this->reconcile_stale_job( $job );
		if ( ! $job || ! in_array( (string) $job->status, array( 'pending', 'running' ), true ) ) {
			return null;
		}

		return $job;
	}

	/**
	 * Close export jobs that finished writing rows but never reached finalize.
	 *
	 * @return object|null Job row after reconciliation (may be completed).
	 */
	public function reconcile_stale_job( object $job ): ?object {
		$job_id        = (int) $job->id;
		$connection_id = (int) $job->connection_id;
		$status        = (string) ( $job->status ?? '' );
		$age           = $this->job_stale_seconds( $job );

		$autoclose_min_age = (int) apply_filters( 'sheetsync_export_autoclose_min_age_seconds', 90 );
		$recover_finalize  = $this->should_recover_stalled_catalog_finalize( $job );
		if ( $age >= $autoclose_min_age && ( $this->should_auto_close_completed_export( $job ) || $recover_finalize ) ) {
			if ( class_exists( 'SheetSync_Job_Runner', false ) && class_exists( 'SheetSync_Sync_Engine', false ) ) {
				$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
				if ( $conn ) {
					SheetSync_Job_Runner::finalize_stalled_job( $job_id, $conn );
					return $this->get( $job_id );
				}
			}
			$this->auto_close_job(
				$job_id,
				$connection_id,
				__( 'Export finished — closed a stale background job so new syncs can run.', 'sheetsync-for-woocommerce' ),
				$job
			);
			return $this->get( $job_id );
		}

		// Job row is pending but Action Scheduler never started it — re-queue instead of blocking the connection.
		if ( 'pending' === $status && empty( $job->started_at ) && $age >= 90
			&& function_exists( 'sheetsync_reschedule_stuck_job' ) ) {
			if ( sheetsync_reschedule_stuck_job( $job_id, 0 ) ) {
				if ( function_exists( 'sheetsync_kick_background_queue' ) ) {
					sheetsync_kick_background_queue( 8 );
				}
				if ( class_exists( 'SheetSync_Logger', false ) ) {
					SheetSync_Logger::log(
						$connection_id,
						'job',
						'partial',
						0,
						0,
						sprintf(
							/* translators: %d: job ID */
							__( 'Re-queued stuck pending job #%d (Action Scheduler had not run it yet).', 'sheetsync-for-woocommerce' ),
							$job_id
						),
						0,
						$job_id
					);
				}
				return $this->get( $job_id );
			}
		}

		if ( class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
			$state     = new SheetSync_Sync_State_Repository();
			$lock_dead = ! $state->is_locked( $connection_id );

			// Lock expired while job still open — resume processing or fail after a long stall.
			if ( $lock_dead && $age >= 120 ) {
				if ( $age >= 900 && (int) ( $job->processed_count ?? 0 ) === 0 ) {
					$this->fail_stuck_job(
						$job_id,
						$connection_id,
						__( 'Background sync timed out with no progress. Check Action Scheduler (WooCommerce → Status) and try again.', 'sheetsync-for-woocommerce' )
					);
					return $this->get( $job_id );
				}

				if ( function_exists( 'sheetsync_reschedule_stuck_job' ) && sheetsync_reschedule_stuck_job( $job_id, 2 ) ) {
					$state->extend_lock(
						$connection_id,
						function_exists( 'sheetsync_lock_ttl_for_job' )
							? sheetsync_lock_ttl_for_job( $job )
							: 300,
						$job_id
					);
					$state->set_current_job( $connection_id, $job_id );
					if ( function_exists( 'sheetsync_kick_background_queue' ) ) {
						sheetsync_kick_background_queue( 6 );
					}
					return $this->get( $job_id );
				}
			}

			if ( $age > ( function_exists( 'sheetsync_stale_job_close_seconds' )
				? sheetsync_stale_job_close_seconds( $job )
				: 600 ) && $lock_dead ) {
				if ( $this->is_incomplete_catalog_export_job( $job ) ) {
					$this->fail_incomplete_catalog_export(
						$job_id,
						$connection_id,
						__( 'Export stalled and was marked incomplete. Run Sync again to continue.', 'sheetsync-for-woocommerce' )
					);
				} else {
					$this->auto_close_job(
						$job_id,
						$connection_id,
						__( 'Stale sync job auto-closed (lock expired). You can sync again.', 'sheetsync-for-woocommerce' ),
						$job
					);
				}
				return $this->get( $job_id );
			}
		}

		return $job;
	}

	/**
	 * Fail a job that never made progress and release connection resources.
	 */
	private function fail_stuck_job( int $job_id, int $connection_id, string $message ): void {
		$job = $this->get( $job_id );
		if ( class_exists( 'SheetSync_Bulk_Processor', false ) ) {
			SheetSync_Bulk_Processor::abort_catalog_export( $connection_id );
		}
		$this->fail( $job_id, $message );
		if ( class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
			( new SheetSync_Sync_State_Repository() )->release_lock( $connection_id, $job_id );
		}
		if ( class_exists( 'SheetSync_Logger', false ) ) {
			SheetSync_Logger::log( $connection_id, 'job', 'error', 0, 0, $message, 1, $job_id );
		}
	}

	/**
	 * Force-close a stuck export job when the user starts a sheet → WooCommerce pull.
	 */
	public function yield_export_job_for_pull( object $job ): void {
		$direction = (string) ( $job->direction ?? '' );
		if ( ! in_array( $direction, array( 'bootstrap', 'push' ), true ) ) {
			return;
		}
		if ( class_exists( 'SheetSync_Bulk_Processor', false ) ) {
			$meta = $this->get_cursor_meta( $job );
			if ( empty( $meta['catalog_finalized'] ) ) {
				SheetSync_Bulk_Processor::abort_catalog_export( (int) $job->connection_id );
			}
		}
		$this->auto_close_job(
			(int) $job->id,
			(int) $job->connection_id,
			__( 'Previous export job closed — starting sheet import.', 'sheetsync-for-woocommerce' ),
			$job
		);
	}

	/**
	 * Whether a bootstrap/full export job is still in progress (sheet cleanup not done).
	 */
	public function is_incomplete_catalog_export_job( object $job ): bool {
		$direction = (string) ( $job->direction ?? '' );
		if ( ! in_array( $direction, array( 'bootstrap', 'push', 'two_way' ), true ) ) {
			return false;
		}
		if ( 'push' !== (string) ( $job->phase ?? '' ) ) {
			return false;
		}
		$meta = $this->get_cursor_meta( $job );
		if ( ! empty( $meta['catalog_finalized'] ) ) {
			return false;
		}
		$connection_id = (int) ( $job->connection_id ?? 0 );
		if ( $connection_id > 0 && get_transient( 'sheetsync_catalog_export_' . $connection_id ) ) {
			return true;
		}
		return empty( $meta['export_complete'] );
	}

	/**
	 * Fail an interrupted catalog export and release the connection lock.
	 */
	private function fail_incomplete_catalog_export( int $job_id, int $connection_id, string $message ): void {
		if ( class_exists( 'SheetSync_Bulk_Processor', false ) ) {
			SheetSync_Bulk_Processor::abort_catalog_export( $connection_id );
		}
		$job       = $this->get( $job_id );
		$processed = $job ? (int) ( $job->processed_count ?? 0 ) : 0;
		$fail_msg  = $processed > 0
			? sprintf(
				/* translators: 1: reason, 2: rows processed, 3: job id */
				__( '%1$s %2$d rows were written — run Sync again to continue (Job #%3$d).', 'sheetsync-for-woocommerce' ),
				$message . ' ',
				$processed,
				$job_id
			)
			: $message;
		$this->fail( $job_id, $fail_msg );
		if ( class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
			( new SheetSync_Sync_State_Repository() )->release_lock( $connection_id, $job_id );
		}
		if ( class_exists( 'SheetSync_Logger', false ) ) {
			SheetSync_Logger::log( $connection_id, 'job', 'error', $processed, 0, $fail_msg, 1, $job_id );
		}
	}

	private function auto_close_job( int $job_id, int $connection_id, string $log_message, ?object $job = null ): void {
		if ( null === $job ) {
			$job = $this->get( $job_id );
		}
		if ( $job && $this->is_incomplete_catalog_export_job( $job ) ) {
			$this->fail_incomplete_catalog_export( $job_id, $connection_id, $log_message );
			return;
		}
		$this->complete( $job_id );
		if ( class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
			( new SheetSync_Sync_State_Repository() )->release_lock( $connection_id, $job_id );
		}
		if ( class_exists( 'SheetSync_Logger', false ) && $log_message !== '' ) {
			SheetSync_Logger::log( $connection_id, 'job', 'success', 0, 0, $log_message, 0, $job_id );
		}
	}

	/**
	 * Seconds since the job row last changed (updated, started, or created).
	 */
	private function job_stale_seconds( object $job ): int {
		$updated_ts = ! empty( $job->updated_at ) ? strtotime( (string) $job->updated_at . ' UTC' ) : 0;
		$started_ts = ! empty( $job->started_at ) ? strtotime( (string) $job->started_at . ' UTC' ) : 0;
		$created_ts = ! empty( $job->created_at ) ? strtotime( (string) $job->created_at . ' UTC' ) : 0;
		$age_base   = max( $updated_ts, $started_ts, $created_ts );

		return $age_base > 0 ? time() - $age_base : 0;
	}

	/**
	 * Whether a stalled export likely finished writing but never reached finalize().
	 *
	 * Uses job phase + counters — not connection-wide product_map alone (avoids false positives).
	 */
	private function should_auto_close_completed_export( object $job ): bool {
		$direction = (string) ( $job->direction ?? '' );
		$phase     = (string) ( $job->phase ?? '' );
		$mode      = (string) ( $job->mode ?? 'incremental' );
		$total     = (int) ( $job->total_estimate ?? 0 );
		$processed = (int) ( $job->processed_count ?? 0 );
		$errors    = (int) ( $job->error_count ?? 0 );

		if ( ! in_array( $direction, array( 'bootstrap', 'push', 'two_way' ), true ) ) {
			return false;
		}

		// Push batches finished; runner stalled before finalize() cleanup.
		if ( 'finalize' === $phase ) {
			return true;
		}

		if ( 'push' !== $phase ) {
			return false;
		}

		// Incremental exports can finish with few processed rows while total_estimate is catalog-wide.
		if ( 'push' === $direction && 'incremental' === $mode ) {
			return false;
		}

		if ( $total <= 0 ) {
			return false;
		}

		if ( $errors > 0 ) {
			return false;
		}

		$meta = $this->get_cursor_meta( $job );
		return ! empty( $meta['export_complete'] ) && ! empty( $meta['catalog_finalized'] );
	}

	/**
	 * Export cursor finished but catalog sheet cleanup did not run (crash between flags).
	 */
	private function should_recover_stalled_catalog_finalize( object $job ): bool {
		if ( 'push' !== (string) ( $job->phase ?? '' ) ) {
			return false;
		}
		$direction = (string) ( $job->direction ?? '' );
		if ( ! in_array( $direction, array( 'bootstrap', 'push', 'two_way' ), true ) ) {
			return false;
		}
		if ( (int) ( $job->error_count ?? 0 ) > 0 ) {
			return false;
		}
		$meta = $this->get_cursor_meta( $job );
		if ( ! empty( $meta['catalog_finalized'] ) ) {
			return false;
		}
		if ( ! empty( $meta['export_complete'] ) ) {
			return true;
		}
		$connection_id = (int) ( $job->connection_id ?? 0 );
		return $connection_id > 0
			&& (int) ( $job->processed_count ?? 0 ) > 0
			&& (bool) get_transient( 'sheetsync_catalog_export_' . $connection_id );
	}

	public function mark_running( int $job_id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'status'     => 'running',
				'started_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function update_phase( int $job_id, string $phase ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array( 'phase' => sanitize_key( $phase ) ),
			array( 'id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function update_cursor( int $job_id, int $offset, ?array $meta = null ): void {
		global $wpdb;
		$data = array( 'cursor_offset' => $offset );
		if ( null !== $meta ) {
			$data['cursor_meta'] = wp_json_encode( $meta );
		}
		$wpdb->update( $this->table, $data, array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_cursor_meta( object $job ): array {
		if ( empty( $job->cursor_meta ) ) {
			return array();
		}
		$decoded = json_decode( (string) $job->cursor_meta, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Align processed_count with product_map rows (export jobs — avoids inflated batch counters).
	 */
	public function sync_processed_from_map( int $job_id, int $connection_id ): int {
		if ( ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return 0;
		}
		$count = ( new SheetSync_Product_Map_Repository() )->count_for_connection( $connection_id );
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array( 'processed_count' => $count ),
			array( 'id' => $job_id ),
			array( '%d' ),
			array( '%d' )
		);
		return $count;
	}

	/**
	 * Track two-way pull rows skipped because WooCommerce changed but the sheet did not.
	 */
	public function increment_wc_edited_skips( int $job_id, int $count = 1 ): void {
		if ( $count <= 0 ) {
			return;
		}
		$job = $this->get( $job_id );
		if ( ! $job ) {
			return;
		}
		$meta                      = $this->get_cursor_meta( $job );
		$meta['wc_edited_skips']  = (int) ( $meta['wc_edited_skips'] ?? 0 ) + $count;
		$this->update_cursor( $job_id, (int) $job->cursor_offset, $meta );
	}

	/**
	 * @return int
	 */
	public function get_wc_edited_skips( object $job ): int {
		$meta = $this->get_cursor_meta( $job );
		return (int) ( $meta['wc_edited_skips'] ?? 0 );
	}

	/**
	 * Queue a product for priority push after two-way pull detected WC-only edits.
	 */
	public function queue_wc_edited_push( int $job_id, int $product_id ): void {
		if ( $job_id <= 0 || $product_id <= 0 ) {
			return;
		}
		$job = $this->get( $job_id );
		if ( ! $job ) {
			return;
		}
		$meta = $this->get_cursor_meta( $job );
		if ( ! isset( $meta['wc_edited_push_ids'] ) || ! is_array( $meta['wc_edited_push_ids'] ) ) {
			$meta['wc_edited_push_ids'] = array();
		}
		$meta['wc_edited_push_ids'][ $product_id ] = $product_id;
		$this->update_cursor( $job_id, (int) $job->cursor_offset, $meta );
	}

	/**
	 * @return int[]
	 */
	public function pop_wc_edited_push_ids( int $job_id, int $limit ): array {
		if ( $job_id <= 0 || $limit <= 0 ) {
			return array();
		}
		$job = $this->get( $job_id );
		if ( ! $job ) {
			return array();
		}
		$meta  = $this->get_cursor_meta( $job );
		$queue = is_array( $meta['wc_edited_push_ids'] ?? null ) ? $meta['wc_edited_push_ids'] : array();
		if ( empty( $queue ) ) {
			return array();
		}
		$ids = array_slice( array_map( 'intval', array_values( $queue ) ), 0, $limit );
		foreach ( $ids as $id ) {
			unset( $meta['wc_edited_push_ids'][ $id ] );
		}
		$this->update_cursor( $job_id, (int) $job->cursor_offset, $meta );
		return $ids;
	}

	public function has_wc_edited_push_queue( object $job ): bool {
		$meta = $this->get_cursor_meta( $job );
		return ! empty( $meta['wc_edited_push_ids'] ) && is_array( $meta['wc_edited_push_ids'] );
	}

	/**
	 * @return int
	 */
	public function get_wc_edited_pushed( object $job ): int {
		$meta = $this->get_cursor_meta( $job );
		return (int) ( $meta['wc_edited_pushed'] ?? 0 );
	}

	public function increment_wc_edited_pushed( int $job_id, int $count = 1 ): void {
		if ( $count <= 0 || $job_id <= 0 ) {
			return;
		}
		$job = $this->get( $job_id );
		if ( ! $job ) {
			return;
		}
		$meta                       = $this->get_cursor_meta( $job );
		$meta['wc_edited_pushed']  = (int) ( $meta['wc_edited_pushed'] ?? 0 ) + $count;
		$this->update_cursor( $job_id, (int) $job->cursor_offset, $meta );
	}

	public function increment_stats( int $job_id, int $processed = 0, int $skipped = 0, int $errors = 0 ): void {
		global $wpdb;
		if ( $processed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET processed_count = processed_count + %d WHERE id = %d", $processed, $job_id ) );
		}
		if ( $skipped ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET skipped_count = skipped_count + %d WHERE id = %d", $skipped, $job_id ) );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET error_count = error_count + %d WHERE id = %d", $errors, $job_id ) );
		}
	}

	public function increment_attempts( int $job_id, string $error = '' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET attempts = attempts + 1, last_error = %s WHERE id = %d", $error, $job_id ) );
	}

	public function set_last_error( int $job_id, string $error = '' ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array( 'last_error' => $error ),
			array( 'id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function reset_attempts( int $job_id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'attempts'    => 0,
				'last_error'  => '',
			),
			array( 'id' => $job_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	public function complete( int $job_id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'status'      => 'completed',
				'phase'       => 'finalize',
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function fail( int $job_id, string $message ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'status'      => 'failed',
				'last_error'  => $message,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Job counts grouped by status (for health / monitoring).
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		global $wpdb;

		$defaults = array(
			'pending'   => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'cancelled' => 0,
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status, COUNT(*) AS cnt FROM {$this->table} GROUP BY status",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $defaults;
		}

		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( array_key_exists( $status, $defaults ) ) {
				$defaults[ $status ] = (int) ( $row['cnt'] ?? 0 );
			}
		}

		return $defaults;
	}
}

endif;
