<?php
/**
 * PRO: Two-way sync — debounced push using product_map (no column scans).
 */

/**
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 * @copyright 2024 MD Najmus Shadat
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Two_Way_Sync {

    public function __construct() {
        add_action( 'woocommerce_update_product', array( $this, 'on_product_updated' ), 20, 1 );
        add_action( 'woocommerce_new_product', array( $this, 'on_product_updated' ), 20, 1 );
        add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_changed' ), 20, 1 );
    }

    public function on_product_updated( int $product_id ): void {
        if ( SheetSync_Product_Updater::is_internal_update() ) {
            return;
        }
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }
        $this->schedule_push( $product );
    }

    public function on_stock_changed( WC_Product $product ): void {
        if ( SheetSync_Product_Updater::is_internal_update() ) {
            return;
        }
        $this->schedule_push( $product );
    }

    private function schedule_push( WC_Product $product ): void {
        $connections = SheetSync_Sync_Engine::get_active_connections( 'products' );

        foreach ( $connections as $conn ) {
            if ( ! function_exists( 'sheetsync_connection_allows_push' ) || ! sheetsync_connection_allows_push( $conn ) ) {
                continue;
            }

            $auto_on_save = (bool) get_option( 'sheetsync_auto_on_save_' . $conn->id, false );
            if ( ! $auto_on_save ) {
                continue;
            }

            if ( function_exists( 'sheetsync_is_sync_locked' ) && sheetsync_is_sync_locked( (int) $conn->id ) ) {
                if ( function_exists( 'sheetsync_mark_product_dirty' ) ) {
                    sheetsync_mark_product_dirty( (int) $conn->id, $product->get_id() );
                }
                continue;
            }

            if ( function_exists( 'sheetsync_mark_product_dirty' ) ) {
                sheetsync_mark_product_dirty( (int) $conn->id, $product->get_id() );
                continue;
            }

            try {
                if ( class_exists( 'SheetSync_Push_To_Sheet_Service', false ) ) {
                    ( new SheetSync_Push_To_Sheet_Service() )->push_single_product( $product, $conn );
                }
            } catch ( Exception $e ) {
                SheetSync_Logger::error(
                    sprintf( 'Auto sync failed for product %d: %s', $product->get_id(), $e->getMessage() ),
                    (int) $conn->id
                );
            }
        }
    }
}
