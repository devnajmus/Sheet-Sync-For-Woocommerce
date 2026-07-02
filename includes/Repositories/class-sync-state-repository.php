<?php
/**
 * Per-connection sync state and locking.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sync_State_Repository' ) ) :

class SheetSync_Sync_State_Repository {

	private string $table;

	public function __construct() {
		$this->table = SheetSync_Schema::table( 'sync_state' );
	}

	/**
	 * @return object|null
	 */
	public function get( int $connection_id ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE connection_id = %d",
				$connection_id
			)
		);
		if ( ! $row ) {
			$this->ensure_row( $connection_id );
			return $this->get( $connection_id );
		}
		return $row;
	}

	public function ensure_row( int $connection_id ): void {
		global $wpdb;
		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT connection_id FROM {$this->table} WHERE connection_id = %d",
				$connection_id
			)
		);
		if ( $exists ) {
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'connection_id' => $connection_id,
				'status'        => 'idle',
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Try to acquire lock. Returns false if another job is running.
	 */
	public function acquire_lock( int $connection_id, int $ttl_seconds = 300 ): bool {
		global $wpdb;
		$this->ensure_row( $connection_id );
		$token   = wp_generate_uuid4();
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET lock_token = %s, lock_expires_at = %s, status = 'running'
				WHERE connection_id = %d
				AND ( status != 'running' OR lock_expires_at IS NULL OR lock_expires_at < UTC_TIMESTAMP() )",
				$token,
				$expires,
				$connection_id
			)
		);

		if ( $updated ) {
			wp_cache_set( 'sheetsync_lock_' . $connection_id, $token, 'sheetsync', $ttl_seconds );
			return true;
		}

		return false;
	}

	public function release_lock( int $connection_id, ?int $job_id = null ): bool {
		global $wpdb;

		if ( $job_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table}
					SET lock_token = NULL, lock_expires_at = NULL, status = 'idle', current_job_id = NULL
					WHERE connection_id = %d AND current_job_id = %d",
					$connection_id,
					$job_id
				)
			);
			if ( ! $updated ) {
				return false;
			}
		} else {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->table,
				array(
					'lock_token'      => null,
					'lock_expires_at' => null,
					'status'          => 'idle',
					'current_job_id'  => null,
				),
				array( 'connection_id' => $connection_id ),
				array( '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);
		}

		wp_cache_delete( 'sheetsync_lock_' . $connection_id, 'sheetsync' );
		return true;
	}

	public function set_current_job( int $connection_id, int $job_id ): void {
		global $wpdb;
		$this->ensure_row( $connection_id );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'current_job_id' => $job_id,
				'status'         => 'running',
			),
			array( 'connection_id' => $connection_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings( int $connection_id ): array {
		$row = $this->get( $connection_id );
		if ( ! $row || empty( $row->settings_json ) ) {
			return self::default_settings();
		}
		$decoded = json_decode( (string) $row->settings_json, true );
		return is_array( $decoded ) ? array_merge( self::default_settings(), $decoded ) : self::default_settings();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'pull_batch_size'  => 200,
			'push_batch_size'  => 100,
			'field_policies'   => array(
				'_stock'         => 'sheet',
				'_regular_price' => 'sheet',
				'_sale_price'    => 'sheet',
				'post_title'     => 'wc',
				'_product_image' => 'wc',
				'default'        => 'newest',
			),
			'on_conflict'           => 'merge',
			'two_way_phase_order'   => 'pull_push',
			'require_sku'           => true,
			'webhook_dedup_seconds' => 3,
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function update_settings( int $connection_id, array $settings ): void {
		global $wpdb;
		$this->ensure_row( $connection_id );
		$merged = array_merge( $this->get_settings( $connection_id ), $settings );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array( 'settings_json' => wp_json_encode( $merged ) ),
			array( 'connection_id' => $connection_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Job phases for product two-way sync (pull→push or push→pull).
	 *
	 * @return string[]
	 */
	public function get_two_way_phases( int $connection_id ): array {
		$order = (string) ( $this->get_settings( $connection_id )['two_way_phase_order'] ?? 'pull_push' );
		if ( 'push_pull' === $order ) {
			return array( 'push', 'pull', 'finalize' );
		}
		return array( 'pull', 'push', 'finalize' );
	}

	public function extend_lock( int $connection_id, int $ttl_seconds = 300, ?int $job_id = null ): bool {
		global $wpdb;
		$this->ensure_row( $connection_id );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds );

		if ( $job_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table}
					SET lock_expires_at = %s, status = 'running'
					WHERE connection_id = %d AND current_job_id = %d",
					$expires,
					$connection_id,
					$job_id
				)
			);
			if ( ! $updated ) {
				return false;
			}
		} else {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->table,
				array(
					'lock_expires_at' => $expires,
					'status'          => 'running',
				),
				array( 'connection_id' => $connection_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		wp_cache_set( 'sheetsync_lock_' . $connection_id, 1, 'sheetsync', $ttl_seconds );
		return true;
	}

	/**
	 * True when a background sync job holds the connection lock.
	 */
	public function is_locked( int $connection_id ): bool {
		$row = $this->get( $connection_id );
		if ( ! $row || 'running' !== (string) ( $row->status ?? '' ) ) {
			return false;
		}
		if ( empty( $row->lock_expires_at ) ) {
			return true;
		}
		return strtotime( (string) $row->lock_expires_at . ' UTC' ) > time();
	}

	/**
	 * Short-lived mutex for webhook / deferred push (avoids overlapping real-time writes).
	 */
	public function try_realtime_slot( int $connection_id, int $ttl_seconds = 60 ): bool {
		global $wpdb;
		$this->ensure_row( $connection_id );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET realtime_slot_expires_at = %s
				WHERE connection_id = %d
				AND ( realtime_slot_expires_at IS NULL OR realtime_slot_expires_at < UTC_TIMESTAMP() )",
				$expires,
				$connection_id
			)
		);

		if ( $updated ) {
			delete_transient( 'sheetsync_rt_' . $connection_id );
			return true;
		}

		return false;
	}

	public function release_realtime_slot( int $connection_id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array( 'realtime_slot_expires_at' => null ),
			array( 'connection_id' => $connection_id ),
			array( '%s' ),
			array( '%d' )
		);
		delete_transient( 'sheetsync_rt_' . $connection_id );
	}

	public function set_watermark( int $connection_id, string $gmt ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array( 'wc_watermark_gmt' => $gmt ),
			array( 'connection_id' => $connection_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function mark_pull_complete( int $connection_id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array( 'last_pull_at' => current_time( 'mysql', true ) ),
			array( 'connection_id' => $connection_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function mark_push_complete( int $connection_id, ?string $watermark_gmt = null ): void {
		global $wpdb;
		$data = array( 'last_push_at' => current_time( 'mysql', true ) );
		if ( $watermark_gmt ) {
			$data['wc_watermark_gmt'] = $watermark_gmt;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			$data,
			array( 'connection_id' => $connection_id ),
			array_fill( 0, count( $data ), '%s' ),
			array( '%d' )
		);
	}
}

endif;
