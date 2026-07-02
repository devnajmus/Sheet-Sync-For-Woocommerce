<?php
/**
 * Deferred product image sideload queue (bounded background processing).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Media_Queue' ) ) :

class SheetSync_Media_Queue {

	private const ACTION_HOOK = 'sheetsync_process_media_queue';

	/**
	 * Queue image field(s) for a product (splits gallery into one row per URL).
	 *
	 * @param array<string, string> $image_data
	 */
	public static function enqueue_product_images( int $connection_id, int $product_id, array $image_data ): int {
		if ( $product_id <= 0 || empty( $image_data ) ) {
			return 0;
		}

		$queued = 0;
		foreach ( $image_data as $field => $value ) {
			$value = trim( (string) $value );
			if ( $value === '' ) {
				continue;
			}
			if ( '_gallery_images' === $field && class_exists( 'SheetSync_Sheet_Image_Resolver', false ) ) {
				$tokens = SheetSync_Sheet_Image_Resolver::split_image_tokens( $value );
				$max    = max( 1, (int) apply_filters( 'sheetsync_max_gallery_images_per_product', 30 ) );
				if ( count( $tokens ) > $max ) {
					$tokens = array_slice( $tokens, 0, $max );
				}
				foreach ( $tokens as $token ) {
					if ( self::enqueue_row( $connection_id, $product_id, '_gallery_image', $token ) ) {
						++$queued;
					}
				}
				continue;
			}
			if ( in_array( $field, array( '_product_image', '_gallery_image' ), true ) ) {
				if ( self::enqueue_row( $connection_id, $product_id, $field, $value ) ) {
					++$queued;
				}
			}
		}

		if ( $queued > 0 ) {
			self::schedule_processing( $connection_id );
		}

		return $queued;
	}

	public static function schedule_processing( int $connection_id = 0 ): void {
		$connection_id = max( 0, $connection_id );

		if ( function_exists( 'as_next_scheduled_action' )
			&& as_next_scheduled_action( self::ACTION_HOOK, array( $connection_id ), 'sheetsync' ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + (int) apply_filters( 'sheetsync_media_queue_schedule_delay_seconds', 3 ),
				self::ACTION_HOOK,
				array( $connection_id ),
				'sheetsync'
			);
			return;
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event(
				time() + 3,
				self::ACTION_HOOK,
				array( $connection_id )
			);
		}
	}

	/**
	 * Process a few queued images when Action Scheduler is unavailable (shared hosting fallback).
	 */
	public static function maybe_process_inline( int $connection_id = 0, int $limit = 0 ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$limit = $limit > 0 ? $limit : (int) apply_filters( 'sheetsync_media_queue_inline_batch_size', 2 );
		self::process_batch( $connection_id, $limit, time() + 20 );
	}

	/**
	 * @return array{processed: int, errors: int, remaining: int}
	 */
	public static function process_batch( int $connection_id = 0, int $limit = 0, int $deadline = 0 ): array {
		global $wpdb;

		$limit    = max( 1, min( 25, $limit > 0 ? $limit : (int) apply_filters( 'sheetsync_media_queue_batch_size', 6 ) ) );
		$deadline = $deadline > 0 ? $deadline : ( time() + (int) apply_filters( 'sheetsync_media_queue_deadline_seconds', 22 ) );
		$table    = SheetSync_Schema::table( 'media_queue' );
		$max_attempts = max( 1, min( 10, (int) apply_filters( 'sheetsync_media_queue_max_attempts', 5 ) ) );
		$max_loops  = max( 1, min( 6, (int) apply_filters( 'sheetsync_media_queue_loops_per_tick', 3 ) ) );

		$processed = 0;
		$errors    = 0;

		for ( $loop = 0; $loop < $max_loops && time() < $deadline; ++$loop ) {
			$params = array();
			$where  = 'attempts < %d';
			$params[] = $max_attempts;

			if ( $connection_id > 0 ) {
				$where   .= ' AND connection_id = %d';
				$params[] = $connection_id;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT id, connection_id, product_id, field_key, field_value, attempts
				FROM {$table}
				WHERE {$where}
				ORDER BY attempts ASC, id ASC
				LIMIT %d";
			$params[] = $limit;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( sheetsync_wpdb_prepare( $sql, $params ), ARRAY_A );

			if ( empty( $rows ) ) {
				break;
			}

			$loop_processed = 0;
			foreach ( (array) $rows as $row ) {
				if ( time() >= $deadline ) {
					break 2;
				}

				$id         = (int) ( $row['id'] ?? 0 );
				$product_id = (int) ( $row['product_id'] ?? 0 );
				$field_key  = (string) ( $row['field_key'] ?? '' );
				$value      = (string) ( $row['field_value'] ?? '' );
				$conn_id    = (int) ( $row['connection_id'] ?? 0 );
				$attempts   = (int) ( $row['attempts'] ?? 0 );

				if ( self::is_in_backoff( $id, $attempts ) ) {
					continue;
				}

				if ( $id <= 0 || $product_id <= 0 || $value === '' ) {
					self::delete_row( $id );
					continue;
				}

				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					self::delete_row( $id );
					++$errors;
					continue;
				}

				$ok = self::apply_queue_row( $product, $field_key, $value );
				if ( $ok ) {
					self::clear_backoff( $id );
					self::delete_row( $id );
					++$processed;
					++$loop_processed;
					continue;
				}

				$attempts = $attempts + 1;
				if ( $attempts >= $max_attempts ) {
					self::clear_backoff( $id );
					self::delete_row( $id );
					++$errors;
					if ( $conn_id > 0 && class_exists( 'SheetSync_Logger', false ) ) {
						SheetSync_Logger::log(
							$conn_id,
							'import',
							'error',
							0,
							0,
							sprintf(
								/* translators: 1: product ID, 2: image field, 3: image URL or token */
								__( 'Deferred image failed for product #%1$d (%2$s) after %3$d attempts.', 'sheetsync-for-woocommerce' ),
								$product_id,
								$field_key,
								$max_attempts
							),
							1
						);
					}
				} else {
					self::set_backoff( $id, $attempts );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update(
						$table,
						array( 'attempts' => $attempts ),
						array( 'id' => $id ),
						array( '%d' ),
						array( '%d' )
					);
					++$errors;
				}
			}

			if ( 0 === $loop_processed ) {
				break;
			}
		}

		$remaining = self::count_pending( $connection_id );

		if ( $remaining > 0 ) {
			self::schedule_processing( $connection_id );
		}

		return array(
			'processed' => $processed,
			'errors'    => $errors,
			'remaining' => $remaining,
		);
	}

	public static function count_pending( int $connection_id = 0 ): int {
		global $wpdb;

		$table = SheetSync_Schema::table( 'media_queue' );
		if ( $connection_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE connection_id = %d",
					$connection_id
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	private static function enqueue_row( int $connection_id, int $product_id, string $field_key, string $value ): bool {
		global $wpdb;

		$field_key = sanitize_key( $field_key );
		$value     = trim( $value );
		if ( $field_key === '' || $value === '' ) {
			return false;
		}

		$value_hash = md5( $value );
		$table      = SheetSync_Schema::table( 'media_queue' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (connection_id, product_id, field_key, field_value, value_hash, attempts, created_at)
				VALUES (%d, %d, %s, %s, %s, 0, %s)
				ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), attempts = 0, created_at = VALUES(created_at)",
				$connection_id,
				$product_id,
				$field_key,
				$value,
				$value_hash,
				current_time( 'mysql', true )
			)
		);

		return false !== $result;
	}

	private static function delete_row( int $id ): void {
		if ( $id <= 0 ) {
			return;
		}
		self::clear_backoff( $id );
		global $wpdb;
		$table = SheetSync_Schema::table( 'media_queue' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	private static function backoff_transient_key( int $queue_id ): string {
		return 'sheetsync_media_bo_' . max( 0, $queue_id );
	}

	private static function is_in_backoff( int $queue_id, int $attempts ): bool {
		if ( $attempts <= 0 || $queue_id <= 0 ) {
			return false;
		}
		$until = (int) get_transient( self::backoff_transient_key( $queue_id ) );
		return $until > time();
	}

	private static function set_backoff( int $queue_id, int $attempts ): void {
		if ( $queue_id <= 0 || $attempts <= 0 ) {
			return;
		}
		$delay = min( 600, max( 5, (int) pow( 2, min( 8, $attempts ) ) * 5 ) );
		$delay = (int) apply_filters( 'sheetsync_media_queue_retry_delay_seconds', $delay, $queue_id, $attempts );
		set_transient( self::backoff_transient_key( $queue_id ), time() + $delay, $delay + 30 );
	}

	private static function clear_backoff( int $queue_id ): void {
		if ( $queue_id <= 0 ) {
			return;
		}
		delete_transient( self::backoff_transient_key( $queue_id ) );
	}

	private static function apply_queue_row( WC_Product $product, string $field_key, string $value ): bool {
		if ( ! class_exists( 'SheetSync_Sheet_Image_Resolver', false ) ) {
			return false;
		}

		try {
			SheetSync_Product_Updater::flag_internal_update( true );

			if ( '_gallery_image' === $field_key ) {
				$att_id = SheetSync_Sheet_Image_Resolver::import_attachment( $value, $product->get_id() );
				if ( $att_id <= 0 ) {
					SheetSync_Product_Updater::flag_internal_update( false );
					return false;
				}
				$gallery = $product->get_gallery_image_ids();
				if ( ! in_array( $att_id, $gallery, true ) ) {
					$gallery[] = $att_id;
					$product->set_gallery_image_ids( array_values( array_unique( array_map( 'intval', $gallery ) ) ) );
				}
			} else {
				SheetSync_Sheet_Image_Resolver::apply_to_product( $product, $field_key, $value );
			}

			$product->save();
			SheetSync_Product_Updater::flag_internal_update( false );
			return true;
		} catch ( Throwable $e ) {
			SheetSync_Product_Updater::flag_internal_update( false );
			if ( class_exists( 'SheetSync_Logger', false ) ) {
				SheetSync_Logger::error( 'SheetSync deferred image: ' . $e->getMessage() );
			}
			return false;
		}
	}
}

endif;
