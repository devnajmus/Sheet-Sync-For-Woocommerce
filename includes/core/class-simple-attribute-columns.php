<?php
/**
 * Build variation_attrs from simple "Color" and "Size" sheet columns.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Simple_Attribute_Columns', false ) ) :

class SheetSync_Simple_Attribute_Columns {

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_filter( 'sheetsync_row_mapped_data', array( __CLASS__, 'normalize_variable_row_fields' ), 5, 3 );
		add_filter( 'sheetsync_row_mapped_data', array( __CLASS__, 'apply_simple_columns' ), 10, 3 );
	}

	/**
	 * Fix common WC-export sheet patterns before variation row detection runs.
	 *
	 * @param array<string, string> $data Mapped row.
	 * @param array<int, string>    $row  Raw row (unused).
	 * @param array<string, mixed>  $maps Field maps.
	 * @return array<string, string>
	 */
	public static function normalize_variable_row_fields( array $data, array $row, array $maps ): array {
		unset( $row, $maps );

		$sku = function_exists( 'sheetsync_normalize_sheet_sku' )
			? sheetsync_normalize_sheet_sku( (string) ( $data['_sku'] ?? '' ) )
			: sanitize_text_field( $data['_sku'] ?? '' );
		$parent = function_exists( 'sheetsync_normalize_sheet_sku' )
			? sheetsync_normalize_sheet_sku( (string) ( $data['parent_sku'] ?? '' ) )
			: sanitize_text_field( $data['parent_sku'] ?? '' );
		$type   = strtolower( trim( (string) ( $data['_product_type'] ?? '' ) ) );

		// Parent row: Parent SKU must be empty. Variation row reusing parent SKU in column A: drop SKU only.
		if ( $parent !== '' && $sku !== '' && strcasecmp( $parent, $sku ) === 0 ) {
			if ( 'variable' === $type ) {
				$data['parent_sku'] = '';
			} else {
				$data['_sku'] = '';
			}
		}

		return $data;
	}

	/**
	 * @param array<string, string> $data Mapped row.
	 * @param array<int, string>    $row  Raw row (unused).
	 * @param array<string, mixed>  $maps Field maps.
	 * @return array<string, string>
	 */
	public static function apply_simple_columns( array $data, array $row, array $maps ): array {
		unset( $row, $maps );

		$color = trim( (string) ( $data['sheet_color'] ?? '' ) );
		$size  = trim( (string) ( $data['sheet_size'] ?? '' ) );
		if ( $color === '' && $size === '' ) {
			return $data;
		}

		$is_var = class_exists( 'SheetSync_Variation_Sync', false )
			&& SheetSync_Variation_Sync::is_variation_row( $data );

		$existing = trim( (string) ( $data['variation_attrs'] ?? '' ) );

		$segments = array();
		if ( $color !== '' ) {
			$segments[] = self::format_attribute_segment( 'pa_color', $color, $data );
		}
		if ( $size !== '' ) {
			$segments[] = self::format_attribute_segment( 'pa_size', $size, $data );
		}

		if ( ! empty( $segments ) ) {
			$overlay = implode( '|', $segments );
			$data['variation_attrs'] = $existing !== ''
				? self::merge_variation_attr_strings( $existing, $overlay )
				: $overlay;
		}

		// Infer variable parent when Color lists multiple values (red,black) without technical type column.
		if (
			'' === trim( (string) ( $data['parent_sku'] ?? '' ) )
			&& '' === trim( (string) ( $data['_product_type'] ?? '' ) )
			&& $color !== ''
			&& str_contains( $color, ',' )
			&& class_exists( 'SheetSync_Variation_Sync', false )
		) {
			$data['_product_type'] = 'variable';
		}

		unset( $data['sheet_color'], $data['sheet_size'] );
		return $data;
	}

	/**
	 * Parent rows may list multiple values (red,blue); variation rows use one value.
	 *
	 * @param array<string, string> $data Row data.
	 */
	private static function format_attribute_segment( string $pa_key, string $value, array $data ): string {
		$is_parent = class_exists( 'SheetSync_Variation_Sync', false )
			&& SheetSync_Variation_Sync::is_variable_parent_row( $data );
		$is_var    = class_exists( 'SheetSync_Variation_Sync', false )
			&& SheetSync_Variation_Sync::is_variation_row( $data );

		if ( $is_var ) {
			$slug = sanitize_title( $value );
			return $pa_key . ':' . $slug;
		}

		if ( $is_parent || str_contains( $value, ',' ) ) {
			$parts = array_filter( array_map( static function ( $v ) {
				return sanitize_title( trim( (string) $v ) );
			}, explode( ',', $value ) ) );
			return $pa_key . ':' . implode( ',', $parts );
		}

		return $pa_key . ':' . sanitize_title( $value );
	}

	/**
	 * Merge two pipe-delimited variation attribute strings ($overlay wins on duplicate keys).
	 */
	private static function merge_variation_attr_strings( string $base, string $overlay ): string {
		$map = array();
		foreach ( array( $base, $overlay ) as $raw ) {
			foreach ( array_filter( array_map( 'trim', explode( '|', $raw ) ) ) as $segment ) {
				if ( ! str_contains( $segment, ':' ) ) {
					continue;
				}
				list( $key, $val ) = array_pad( explode( ':', $segment, 2 ), 2, '' );
				$key = strtolower( sanitize_key( trim( $key ) ) );
				$val = trim( $val );
				if ( $key !== '' && $val !== '' ) {
					$map[ $key ] = $key . ':' . $val;
				}
			}
		}
		return implode( '|', array_values( $map ) );
	}
}

endif;
