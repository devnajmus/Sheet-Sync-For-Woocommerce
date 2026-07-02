<?php
/**
 * Pro-only: optional stock fan-out to other WordPress sites over REST.
 *
 * Configure targets in Settings → "Remote stock sync targets" (JSON array), e.g.:
 * [{"url":"https://othersite.com/wp-json/sheetsync/v1/remote-stock","secret":"shared-secret"}]
 *
 * Each target site must run SheetSync Pro and set option `sheetsync_remote_stock_incoming_secret`
 * to the same shared secret used to sign requests (`X-SheetSync-Remote-Signature` HMAC-SHA256 of raw body).
 * Trim whitespace in secrets on both sites so signatures match.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Pro_Multisite_Stock {

	private const RETRY_ATTEMPTS = 3;

	private const RETRY_DELAY_MS = 500;

	public function __construct() {
		add_action( 'woocommerce_product_set_stock', array( $this, 'broadcast' ), 99, 1 );
	}

	public function broadcast( WC_Product $product ): void {
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return;
		}

		if ( defined( 'SHEETSYNC_DOING_REMOTE_STOCK' ) && SHEETSYNC_DOING_REMOTE_STOCK ) {
			return;
		}

		$pid = $product->get_id();
		if ( $pid && get_transient( 'sheetsync_skip_stock_broadcast_' . $pid ) ) {
			delete_transient( 'sheetsync_skip_stock_broadcast_' . $pid );
			return;
		}

		$sku = $product->get_sku();
		if ( '' === $sku || ! $product->managing_stock() ) {
			return;
		}

		$targets = $this->get_targets();
		if ( empty( $targets ) ) {
			return;
		}

		$qty  = (int) $product->get_stock_quantity();
		$body = wp_json_encode(
			array(
				'sku'      => $sku,
				'quantity' => $qty,
			)
		);

		foreach ( $targets as $t ) {
			$url    = isset( $t['url'] ) ? esc_url_raw( (string) $t['url'] ) : '';
			$secret = isset( $t['secret'] ) ? (string) $t['secret'] : '';
			if ( '' === $url || '' === $secret ) {
				continue;
			}

			$this->post_with_retries(
				$url,
				array(
					'timeout' => 12,
					'headers' => array(
						'Content-Type'                 => 'application/json',
						'X-SheetSync-Remote-Signature' => hash_hmac( 'sha256', (string) $body, $secret ),
					),
					'body'    => $body,
				)
			);
		}
	}

	/**
	 * POST with small retry/backoff for transport errors and 5xx.
	 *
	 * @param string               $url  Full URL.
	 * @param array<string, mixed> $args wp_remote_post args.
	 */
	private function post_with_retries( string $url, array $args ): void {
		$attempts = (int) apply_filters( 'sheetsync_remote_stock_retry_attempts', self::RETRY_ATTEMPTS );
		$attempts = max( 1, min( 6, $attempts ) );

		for ( $i = 0; $i < $attempts; $i++ ) {
			$response = wp_remote_post( $url, $args );
			if ( is_wp_error( $response ) ) {
				if ( $i + 1 >= $attempts ) {
					return;
				}
				$this->sleep_ms( (int) apply_filters( 'sheetsync_remote_stock_retry_delay_ms', self::RETRY_DELAY_MS ) * ( $i + 1 ) );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 500 && $code <= 599 ) {
				if ( $i + 1 >= $attempts ) {
					return;
				}
				$this->sleep_ms( (int) apply_filters( 'sheetsync_remote_stock_retry_delay_ms', self::RETRY_DELAY_MS ) * ( $i + 1 ) );
				continue;
			}
			return;
		}
	}

	private function sleep_ms( int $ms ): void {
		if ( $ms <= 0 ) {
			return;
		}
		usleep( $ms * 1000 );
	}

	/**
	 * @return list<array{url:string, secret:string}>
	 */
	private function get_targets(): array {
		$raw = get_option( 'sheetsync_multisite_stock_targets', '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $row ) {
			if ( is_array( $row ) && ! empty( $row['url'] ) && ! empty( $row['secret'] ) ) {
				$out[] = array(
					'url'    => (string) $row['url'],
					'secret' => trim( (string) $row['secret'] ),
				);
			}
		}
		return $out;
	}
}
