<?php
/**
 * Pro-only: map arbitrary product meta (SCF / ACF-style storage on post meta) from sheet columns.
 *
 * Field map keys must look like: meta__your_meta_key (double underscore after "meta").
 * Keys must appear in Settings → "Allowed custom meta keys" (one per line).
 *
 * Values that are valid JSON objects or arrays are decoded and stored as structured meta
 * (WooCommerce CRUD). All scalar leaves are sanitized; nesting depth is capped.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Pro_Custom_Meta_Sync {

	private const META_MAX_DEPTH = 8;

	public function __construct() {
		add_filter( 'sheetsync_apply_product_meta_field', array( $this, 'apply_meta_field' ), 10, 5 );
		add_filter( 'sheetsync_field_map_all_fields', array( $this, 'append_allowed_meta_map_fields' ), 20, 1 );
	}

	/**
	 * Add Settings → "Allowed custom meta keys" as mappable sheet columns (meta__key).
	 *
	 * @param array<string, string> $fields Field key => admin label.
	 * @return array<string, string>
	 */
	public function append_allowed_meta_map_fields( array $fields ): array {
		$raw   = (string) get_option( 'sheetsync_allowed_product_meta_keys', '' );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		foreach ( $lines as $line ) {
			$mk = sanitize_key( $line );
			if ( '' === $mk ) {
				continue;
			}
			$map_key             = 'meta__' . $mk;
			$fields[ $map_key ] = sprintf(
				/* translators: %s: product meta key */
				__( 'Meta: %s', 'sheetsync-for-woocommerce' ),
				$mk
			);
		}
		return $fields;
	}

	/**
	 * @param bool                   $handled Prior value.
	 * @param WC_Product             $product Product.
	 * @param string                 $field   Map key.
	 * @param string                 $value   Cell value.
	 * @param array<string,mixed>    $maps    Maps.
	 */
	public function apply_meta_field( $handled, WC_Product $product, string $field, string $value, array $maps ): bool { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
		if ( true === $handled || ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return (bool) $handled;
		}

		if ( ! preg_match( '/^meta__(.+)$/', $field, $m ) ) {
			return false;
		}

		$meta_key = sanitize_key( $m[1] );
		if ( '' === $meta_key || ! $this->is_allowed( $meta_key ) ) {
			return false;
		}

		$blocked = array( '_sku', '_price', '_regular_price', '_sale_price', '_stock', '_stock_status', '_manage_stock', '_wc_average_rating', '_wc_review_count' );
		if ( in_array( $meta_key, $blocked, true ) ) {
			return false;
		}

		$stored = $this->normalize_meta_value( $value );
		$product->update_meta_data( $meta_key, wp_slash( $stored ) );
		return true;
	}

	/**
	 * @param string $value Raw cell.
	 * @return string|array<int|string, mixed>
	 */
	private function normalize_meta_value( string $value ) {
		$trim = trim( $value );
		if ( '' === $trim ) {
			return '';
		}
		$first = $trim[0];
		if ( '{' !== $first && '[' !== $first ) {
			return $value;
		}
		$decoded = json_decode( $trim, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return $value;
		}
		return $this->sanitize_meta_structure( $decoded, 0 );
	}

	/**
	 * @param array<int|string, mixed> $data
	 * @return array<int|string, mixed>
	 */
	private function sanitize_meta_structure( array $data, int $depth ) {
		if ( $depth > self::META_MAX_DEPTH ) {
			return array();
		}
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		$out      = array();
		if ( $is_list ) {
			foreach ( $data as $v ) {
				$out[] = $this->sanitize_meta_value_inner( $v, $depth + 1 );
			}
			return $out;
		}
		foreach ( $data as $k => $v ) {
			$nk = sanitize_key( (string) $k );
			if ( '' === $nk ) {
				continue;
			}
			$out[ $nk ] = $this->sanitize_meta_value_inner( $v, $depth + 1 );
		}
		return $out;
	}

	/**
	 * @param mixed $data Value.
	 * @return mixed
	 */
	private function sanitize_meta_value_inner( $data, int $depth ) {
		if ( $depth > self::META_MAX_DEPTH ) {
			return '';
		}
		if ( is_array( $data ) ) {
			return $this->sanitize_meta_structure( $data, $depth );
		}
		if ( is_float( $data ) || is_int( $data ) ) {
			return $data;
		}
		if ( is_bool( $data ) ) {
			return $data;
		}
		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}
		return '';
	}

	private function is_allowed( string $meta_key ): bool {
		$raw   = (string) get_option( 'sheetsync_allowed_product_meta_keys', '' );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		foreach ( $lines as $line ) {
			if ( sanitize_key( $line ) === $meta_key ) {
				return true;
			}
		}
		return false;
	}
}
