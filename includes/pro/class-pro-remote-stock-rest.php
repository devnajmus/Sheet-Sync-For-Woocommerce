<?php
/**
 * Pro-only: registers REST routes that must not live in a duplicate `SheetSync_REST_API`
 * class (Free loads that class first).
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers `POST /sheetsync/v1/remote-stock` when Pro is licensed.
 */
class SheetSync_Pro_Remote_Stock_REST {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 20 );
	}

	public static function register_routes(): void {
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return;
		}

		register_rest_route(
			'sheetsync/v1',
			'/remote-stock',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_remote_stock' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Apply SKU stock quantity from a trusted remote site (HMAC-signed JSON body).
	 */
	public static function handle_remote_stock( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::check_rate_limit() ) {
			return new WP_REST_Response( array( 'error' => __( 'Rate limit exceeded.', 'sheetsync-for-woocommerce' ) ), 429 );
		}

		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return new WP_REST_Response( array( 'error' => __( 'Pro license required.', 'sheetsync-for-woocommerce' ) ), 403 );
		}

		$incoming_secret = trim( (string) get_option( 'sheetsync_remote_stock_incoming_secret', '' ) );
		if ( '' === $incoming_secret ) {
			return new WP_REST_Response( array( 'error' => __( 'Remote stock receiver is not configured.', 'sheetsync-for-woocommerce' ) ), 503 );
		}

		$raw      = $request->get_body();
		$sig      = trim( (string) $request->get_header( 'X-SheetSync-Remote-Signature' ) );
		$expected = hash_hmac( 'sha256', $raw, $incoming_secret );
		if ( '' === $sig || ! hash_equals( $expected, $sig ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Unauthorized.', 'sheetsync-for-woocommerce' ) ), 401 );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['sku'] ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Invalid payload.', 'sheetsync-for-woocommerce' ) ), 400 );
		}

		$sku = sanitize_text_field( (string) $data['sku'] );
		if ( ! isset( $data['quantity'] ) || ! is_numeric( $data['quantity'] ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Missing quantity.', 'sheetsync-for-woocommerce' ) ), 400 );
		}

		$qty = (int) $data['quantity'];

		$product_id = wc_get_product_id_by_sku( $sku );
		if ( ! $product_id ) {
			return new WP_REST_Response( array( 'result' => 'skipped', 'reason' => __( 'SKU not found.', 'sheetsync-for-woocommerce' ) ), 200 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->managing_stock() ) {
			return new WP_REST_Response( array( 'result' => 'skipped', 'reason' => __( 'Product not managing stock.', 'sheetsync-for-woocommerce' ) ), 200 );
		}

		set_transient( 'sheetsync_skip_stock_broadcast_' . $product_id, 1, 30 );

		if ( ! defined( 'SHEETSYNC_DOING_REMOTE_STOCK' ) ) {
			define( 'SHEETSYNC_DOING_REMOTE_STOCK', true );
		}

		wc_update_product_stock( $product, $qty, 'set' );

		return new WP_REST_Response(
			array(
				'result'     => 'updated',
				'product_id' => $product_id,
				'sku'        => $sku,
				'quantity'   => $qty,
			),
			200
		);
	}

	private static function check_rate_limit(): bool {
		$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
		$key = 'sheetsync_rs_rl_' . md5( $ip );
		$data = get_transient( $key );
		if ( false === $data ) {
			set_transient( $key, array( 'count' => 1, 'start' => time() ), 60 );
			return true;
		}
		$count = isset( $data['count'] ) ? (int) $data['count'] : 0;
		if ( $count >= 120 ) {
			return false;
		}
		$start     = isset( $data['start'] ) ? (int) $data['start'] : time();
		$remaining = max( 1, 60 - ( time() - $start ) );
		set_transient( $key, array( 'count' => $count + 1, 'start' => $start ), $remaining );
		return true;
	}
}
