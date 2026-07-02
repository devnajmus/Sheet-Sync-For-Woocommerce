<?php
/**
 * Normalized content hashes for change detection.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Hash_Normalizer' ) ) :

class SheetSync_Hash_Normalizer {

	/** @deprecated Use SheetSync_Field_Mapper::IMPORT_SKIP_FIELDS */
	public const EXPORT_ONLY_FIELDS = array(
		'product_id',
		'sheet_row_role',
		'sheet_product_group',
		'sheet_option_summary',
		'sheet_belongs_to',
	);

	/**
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @param array<string, string>|null                                     $row_data Mapped row values for row-type exclusions.
	 * @return array<string, array{sheet_column: string, is_key_field: bool}>
	 */
	public function maps_for_hash( array $maps, ?array $row_data = null ): array {
		if ( class_exists( 'SheetSync_Field_Mapper', false ) ) {
			foreach ( SheetSync_Field_Mapper::IMPORT_SKIP_FIELDS as $field ) {
				unset( $maps[ $field ] );
			}
			if ( is_array( $row_data ) ) {
				foreach ( SheetSync_Field_Mapper::import_excluded_fields_for_row( $row_data ) as $field ) {
					unset( $maps[ $field ] );
				}
			}
		} else {
			foreach ( self::EXPORT_ONLY_FIELDS as $field ) {
				unset( $maps[ $field ] );
			}
		}
		return $maps;
	}

	/**
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @param array<string, string>|null                                     $row_data Pre-extracted mapped values.
	 */
	public function sheet_hash( array $row, array $maps, ?array $row_data = null ): string {
		if ( ! is_array( $row_data ) && class_exists( 'SheetSync_Product_Updater', false ) ) {
			$row_data = ( new SheetSync_Product_Updater( $maps ) )->extract_data( $row );
		}
		$maps    = $this->maps_for_hash( $maps, $row_data );
		$payload = array();
		foreach ( $maps as $field => $info ) {
			$idx               = SheetSync_Field_Mapper::col_to_index( $info['sheet_column'] );
			$payload[ $field ] = $this->normalize_field_value( $field, (string) ( $row[ $idx ] ?? '' ) );
		}
		ksort( $payload );
		return md5( wp_json_encode( $payload ) );
	}

	/**
	 * Normalize cell text so WC export and Google Sheets read-back produce the same hash.
	 */
	public function normalize_field_value( string $field, string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}

		if ( in_array( $field, array( '_regular_price', '_sale_price', '_weight', '_length', '_width', '_height' ), true ) ) {
			if ( class_exists( 'SheetSync_Field_Mapper', false )
				&& SheetSync_Field_Mapper::is_display_price_range( $value ) ) {
				return '';
			}
			$value = preg_replace( '/[^\d.\-]/', '', $value );
			if ( $value !== '' && is_numeric( $value ) ) {
				return rtrim( rtrim( sprintf( '%.4f', (float) $value ), '0' ), '.' );
			}
			return '';
		}

		if ( in_array( $field, array( '_stock', 'menu_order' ), true ) && is_numeric( $value ) ) {
			return (string) (int) $value;
		}

		if ( 'post_title' === $field && class_exists( 'SheetSync_Export_Order', false ) ) {
			return SheetSync_Export_Order::sanitize_import_title( $value );
		}

		return $value;
	}

	/**
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 */
	public function wc_hash( WC_Product $product, array $maps ): string {
		$updater = new SheetSync_Product_Updater( $maps );
		$row     = $updater->product_to_row( $product );
		return $this->sheet_hash( $row, $maps );
	}

	/**
	 * WooCommerce was saved after the last successful push to the sheet.
	 */
	public function product_modified_since_last_push( WC_Product $product, ?object $map ): bool {
		if ( ! $map || empty( $map->last_pushed_at ) ) {
			return true;
		}
		$mod_gmt = sheetsync_get_product_modified_gmt( $product );
		if ( ! $mod_gmt ) {
			return false;
		}
		$mod_ts    = strtotime( $mod_gmt . ' UTC' );
		$pushed_ts = strtotime( (string) $map->last_pushed_at . ' UTC' );
		if ( false === $mod_ts || false === $pushed_ts ) {
			return false;
		}
		return $mod_ts > $pushed_ts;
	}

	/**
	 * Whether WooCommerce was saved after the last successful pull or push for this map row.
	 */
	public function wc_modified_since_last_sync( WC_Product $product, ?object $map ): bool {
		if ( ! $map ) {
			return true;
		}

		$mod_gmt = sheetsync_get_product_modified_gmt( $product );
		if ( ! $mod_gmt ) {
			return false;
		}
		$mod_ts = strtotime( $mod_gmt . ' UTC' );
		if ( false === $mod_ts ) {
			return false;
		}

		$sync_ts = 0;
		foreach ( array( 'last_pulled_at', 'last_pushed_at' ) as $column ) {
			if ( empty( $map->$column ) ) {
				continue;
			}
			$ts = strtotime( (string) $map->$column . ' UTC' );
			if ( false !== $ts && $ts > $sync_ts ) {
				$sync_ts = $ts;
			}
		}

		if ( 0 === $sync_ts ) {
			return $this->product_modified_since_last_push( $product, $map );
		}

		return $mod_ts > $sync_ts;
	}

	/**
	 * Resolve external key from mapped row data.
	 *
	 * @param array<string, string> $data
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 */
	public function external_key_from_row_data( array $data, array $maps, int $sheet_row = 0 ): string {
		if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return SheetSync_Product_Map_Repository::external_key_from_import_data( $data, $maps, $sheet_row );
		}
		foreach ( $maps as $field => $info ) {
			if ( ! empty( $info['is_key_field'] ) && ! empty( $data[ $field ] ) ) {
				return sanitize_text_field( (string) $data[ $field ] );
			}
		}
		if ( ! empty( $data['_sku'] ) ) {
			return sanitize_text_field( (string) $data['_sku'] );
		}
		if ( ! empty( $data['product_id'] ) ) {
			return 'pid:' . absint( $data['product_id'] );
		}
		return '';
	}

	/**
	 * @param array<int, string> $row
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 */
	public function external_key_from_row( array $row, array $maps, SheetSync_Product_Updater $updater, int $sheet_row = 0 ): string {
		$data = $updater->extract_data( $row );
		return $this->external_key_from_row_data( $data, $maps, $sheet_row );
	}
}

endif;
