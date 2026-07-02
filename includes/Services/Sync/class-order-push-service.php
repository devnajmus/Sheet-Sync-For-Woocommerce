<?php
/**
 * Batched WooCommerce order export to Google Sheets (job engine).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Order_Push_Service' ) ) :

class SheetSync_Order_Push_Service {

	/**
	 * @return array{done: bool, processed: int, skipped: int, errors: int}
	 */
	public function process_batch( object $job, object $conn, int $batch_size, int $deadline ): array {
		if ( ! class_exists( 'SheetSync_Order_Sync', false ) ) {
			return array(
				'done'      => true,
				'processed' => 0,
				'skipped'   => 0,
				'errors'    => 1,
			);
		}

		return ( new SheetSync_Order_Sync() )->process_push_batch( $job, $conn, $batch_size, $deadline );
	}
}

endif;
