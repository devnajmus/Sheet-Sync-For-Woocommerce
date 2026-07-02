<?php
/**
 * Google API rate limiter (token bucket per minute).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Rate_Limit_Exception' ) ) :
class SheetSync_Rate_Limit_Exception extends Exception {}
endif;

if ( ! class_exists( 'SheetSync_Rate_Limiter' ) ) :

class SheetSync_Rate_Limiter {

	private int $read_limit;
	private int $write_limit;

	public function __construct( ?int $read_limit = null, ?int $write_limit = null ) {
		$this->read_limit  = $read_limit ?? (int) apply_filters( 'sheetsync_google_read_limit_per_minute', 45 );
		$this->write_limit = $write_limit ?? (int) apply_filters( 'sheetsync_google_write_limit_per_minute', 35 );
	}

	public function acquire_read( int $cost = 1 ): void {
		$this->acquire( 'read', $this->read_limit, $cost );
	}

	public function acquire_write( int $cost = 1 ): void {
		$this->acquire( 'write', $this->write_limit, $cost );
	}

	private function acquire( string $bucket, int $limit, int $cost ): void {
		$minute    = gmdate( 'YmdHi' );
		$cache_key = 'sheetsync_rl_' . $bucket . '_' . $minute;
		$ttl       = 70;

		$count = wp_cache_get( $cache_key, 'sheetsync_rate_limit', false, $found );
		if ( ! $found ) {
			$count = (int) get_transient( $cache_key );
		}

		$count += $cost;

		if ( $count > $limit ) {
			throw new SheetSync_Rate_Limit_Exception(
				__( 'Google Sheets API rate limit reached. Retrying shortly.', 'sheetsync-for-woocommerce' )
			);
		}

		wp_cache_set( $cache_key, $count, 'sheetsync_rate_limit', $ttl );
		set_transient( $cache_key, $count, $ttl );
	}
}

endif;
