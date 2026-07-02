<?php
/**
 * Pro-only: apply premium WooCommerce fields that are no-ops in the free core updater.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles `sheetsync_handle_premium_field` and sheet export for custom meta keys.
 */
class SheetSync_Pro_Product_Fields {

	public function __construct() {
		add_filter( 'sheetsync_handle_premium_field', array( $this, 'handle_premium_field' ), 10, 5 );
		add_filter( 'sheetsync_product_field_export_value', array( $this, 'export_field_value' ), 10, 3 );
	}

	/**
	 * @param mixed           $handled Prior handler result.
	 * @param WC_Product      $product Product.
	 * @param string          $field   Mapped field key.
	 * @param string          $value   Raw cell value.
	 * @param array<string,mixed> $maps Field maps.
	 * @return bool True if handled.
	 */
	public function handle_premium_field( $handled, WC_Product $product, string $field, string $value, array $maps ): bool { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
		if ( true === $handled || ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return (bool) $handled;
		}

		switch ( $field ) {
			case '_sale_price':
				if ( trim( $value ) === '' ) {
					$product->set_sale_price( '' );
					$product->set_price( $product->get_regular_price() );
					return true;
				}
				$p = function_exists( 'sheetsync_normalize_sheet_price' )
					? sheetsync_normalize_sheet_price( $value )
					: wc_format_decimal( $value );
				if ( $p !== '' && is_numeric( $p ) ) {
					$product->set_sale_price( $p );
				}
				return true;

			case 'post_excerpt':
				$product->set_short_description( trim( $value ) === '' ? '' : wp_kses_post( $value ) );
				return true;

			case '_stock_status':
				$status = strtolower( trim( $value ) );
				if ( in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
					$product->set_stock_status( $status );
				}
				return true;

			case '_weight':
				$product->set_weight( wc_format_decimal( $value ) );
				return true;

			case '_length':
				$product->set_length( wc_format_decimal( $value ) );
				return true;

			case '_width':
				$product->set_width( wc_format_decimal( $value ) );
				return true;

			case '_height':
				$product->set_height( wc_format_decimal( $value ) );
				return true;

			case 'post_content':
				$product->set_description( trim( $value ) === '' ? '' : wp_kses_post( $value ) );
				return true;

			case '_product_type':
				if ( ! $product->get_id() ) {
					$t = strtolower( trim( $value ) );
					if ( in_array( $t, array( 'simple', 'variable', 'grouped', 'external' ), true ) ) {
						$product->set_name( $product->get_name() ?: 'Product' );
					}
				}
				return true;

			case '_product_url':
				if ( $product instanceof WC_Product_External ) {
					$url = esc_url_raw( trim( $value ) );
					if ( $url !== '' ) {
						$product->set_product_url( $url );
					}
				}
				return true;

			case '_product_cats':
				$this->set_categories_from_string( $product, $value );
				return true;

			case '_product_tags':
				$this->set_tags_from_string( $product, $value );
				return true;

			case 'grouped_child_skus':
				$this->set_grouped_children_from_string( $product, $value );
				return true;

			case '_product_image':
			case '_gallery_images':
				if ( class_exists( 'SheetSync_Sheet_Image_Resolver', false ) ) {
					SheetSync_Sheet_Image_Resolver::apply_to_product( $product, $field, $value );
				}
				return true;

			default:
				return false;
		}
	}

	/**
	 * @param string $current Default empty from core.
	 */
	public function export_field_value( string $current, WC_Product $product, string $field ): string {
		if ( $current !== '' || ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return $current;
		}

		if ( ! preg_match( '/^meta__(.+)$/', $field, $m ) ) {
			return $current;
		}

		$key = sanitize_key( $m[1] );
		if ( '' === $key || ! $this->is_meta_key_allowed( $key ) ) {
			return $current;
		}

		$val = $product->get_meta( $key, true );
		if ( is_array( $val ) || is_object( $val ) ) {
			return wp_json_encode( is_object( $val ) ? (array) $val : $val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return is_scalar( $val ) ? (string) $val : '';
	}

	private function is_meta_key_allowed( string $meta_key ): bool {
		$raw   = (string) get_option( 'sheetsync_allowed_product_meta_keys', '' );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		foreach ( $lines as $line ) {
			if ( sanitize_key( $line ) === $meta_key ) {
				return true;
			}
		}
		return false;
	}

	private function set_categories_from_string( WC_Product $product, string $cats_string ): void {
		$cat_names = array_filter( array_map( 'trim', explode( ',', $cats_string ) ) );
		$term_ids  = array();

		foreach ( $cat_names as $name ) {
			$term = function_exists( 'sheetsync_resolve_term_by_name' )
				? sheetsync_resolve_term_by_name( $name, 'product_cat' )
				: get_term_by( 'name', $name, 'product_cat' );
			if ( ! $term ) {
				$clean  = function_exists( 'sheetsync_decode_sheet_text' )
					? sheetsync_decode_sheet_text( $name )
					: $name;
				$result = wp_insert_term( $clean, 'product_cat' );
				if ( ! is_wp_error( $result ) ) {
					$term_ids[] = (int) $result['term_id'];
				}
			} else {
				$term_ids[] = (int) $term->term_id;
			}
		}

		if ( ! empty( $term_ids ) ) {
			$product->set_category_ids( array_unique( $term_ids ) );
		}
	}

	private function set_tags_from_string( WC_Product $product, string $tags_string ): void {
		$tag_names = array_filter( array_map( 'trim', explode( ',', $tags_string ) ) );
		$term_ids  = array();

		foreach ( $tag_names as $name ) {
			$term = get_term_by( 'name', $name, 'product_tag' );
			if ( ! $term ) {
				$result = wp_insert_term( $name, 'product_tag' );
				if ( ! is_wp_error( $result ) ) {
					$term_ids[] = (int) $result['term_id'];
				}
			} else {
				$term_ids[] = (int) $term->term_id;
			}
		}

		if ( ! empty( $term_ids ) ) {
			$product->set_tag_ids( array_unique( $term_ids ) );
		}
	}

	private function set_grouped_children_from_string( WC_Product $product, string $raw ): void {
		if ( ! function_exists( 'sheetsync_analyze_grouped_child_list' ) ) {
			return;
		}

		$analysis = sheetsync_analyze_grouped_child_list( $raw );
		if ( empty( $analysis['ids'] ) ) {
			return;
		}

		if ( ! $product->is_type( 'grouped' ) && ! $product instanceof WC_Product_Grouped ) {
			return;
		}

		$product->set_children( $analysis['ids'] );
	}
}
