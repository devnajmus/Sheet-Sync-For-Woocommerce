<?php
/**
 * Resolves sync mode (smart/full) for a connection.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sync_Mode' ) ) :

class SheetSync_Sync_Mode {

	/**
	 * @return 'full'|'incremental'
	 */
	public static function resolve( int $connection_id, ?string $override = null ): string {
		if ( in_array( $override, array( 'full', 'incremental' ), true ) ) {
			$map_repo = new SheetSync_Product_Map_Repository();
			if ( 'incremental' === $override && 0 === $map_repo->count_for_connection( $connection_id ) ) {
				return 'full';
			}
			return $override;
		}

		$strategy = get_option( 'sheetsync_sync_strategy_' . $connection_id, 'smart' );
		$strategy = apply_filters( 'sheetsync_sync_strategy_' . $connection_id, $strategy );

		if ( 'full' === $strategy ) {
			return 'full';
		}

		$map_repo = new SheetSync_Product_Map_Repository();
		if ( 0 === $map_repo->count_for_connection( $connection_id ) ) {
			return 'full';
		}

		return 'incremental';
	}

	/**
	 * Map connection sync_direction to job direction.
	 */
	public static function job_direction_from_connection( string $sync_direction, string $mode ): string {
		if ( 'full' === $mode && in_array( $sync_direction, array( 'wc_to_sheets', 'two_way' ), true ) ) {
			return 'bootstrap';
		}
		if ( 'wc_to_sheets' === $sync_direction ) {
			return 'push';
		}
		if ( 'sheets_to_wc' === $sync_direction ) {
			return 'pull';
		}
		return 'two_way';
	}
}

endif;
