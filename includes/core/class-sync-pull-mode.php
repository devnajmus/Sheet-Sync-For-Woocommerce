<?php
/**
 * Temporary pull mode for manual sync (create new only / update only).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sync_Pull_Mode', false ) ) :

class SheetSync_Sync_Pull_Mode {

	private const ALLOWED = array( 'default', 'create_new', 'update_only' );

	private static bool $filter_registered = false;

	/**
	 * @param string $mode create_new|update_only|default
	 */
	public static function run_with_mode( int $connection_id, string $mode, callable $callback ) {
		$mode = in_array( $mode, self::ALLOWED, true ) ? $mode : 'default';
		if ( 'default' === $mode ) {
			$callback();
			return;
		}

		self::register_filter();
		set_transient( 'sheetsync_pull_mode_' . $connection_id, $mode, HOUR_IN_SECONDS );
		try {
			$callback();
		} finally {
			// Background jobs keep pull_mode on the job record; Job_Runner clears the transient on finalize.
			if ( ! sheetsync_use_job_engine() ) {
				delete_transient( 'sheetsync_pull_mode_' . $connection_id );
			}
		}
	}

	private static function register_filter(): void {
		if ( self::$filter_registered ) {
			return;
		}
		self::$filter_registered = true;
		add_filter( 'sheetsync_should_process_sheet_row', array( __CLASS__, 'filter_row' ), 5, 5 );
	}

	/**
	 * @param array<string, string> $data Mapped row.
	 */
	public static function filter_row( bool $process, array $data, array $row, object $conn, int $connection_id ): bool {
		unset( $row, $conn );
		if ( ! $process ) {
			return false;
		}

		$mode = get_transient( 'sheetsync_pull_mode_' . $connection_id );
		if ( ! is_string( $mode ) || 'default' === $mode ) {
			return $process;
		}

		$exists = self::row_exists_in_woocommerce( $data, $connection_id );

		if ( 'create_new' === $mode && $exists ) {
			return false;
		}
		if ( 'update_only' === $mode && ! $exists ) {
			return false;
		}

		return $process;
	}

	/**
	 * Whether a mapped sheet row already links to a WooCommerce product.
	 *
	 * @param array<string, string> $data Mapped row values.
	 */
	private static function row_exists_in_woocommerce( array $data, int $connection_id ): bool {
		if ( ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return false;
		}

		$product_id = SheetSync_Product_Map_Repository::resolve_import_product_id( $data, null, $connection_id );
		return $product_id > 0 && (bool) wc_get_product( $product_id );
	}
}

endif;
