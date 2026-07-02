<?php
/**
 * Shared import validation and row-quality rules for Check Sheet and sync.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Import_Rules', false ) ) :

class SheetSync_Import_Rules {

	/**
	 * Whether a row should warn that SKU is missing (title-only matching).
	 *
	 * @param array<string, string> $data Mapped row values.
	 */
	public static function should_warn_missing_sku( array $data ): bool {
		$sku   = sanitize_text_field( $data['_sku'] ?? '' );
		$title = sanitize_text_field( $data['post_title'] ?? '' );
		if ( $sku !== '' || $title === '' ) {
			return false;
		}
		if ( class_exists( 'SheetSync_Variation_Sync', false )
			&& SheetSync_Variation_Sync::is_variation_row( $data ) ) {
			return false;
		}
		if ( ! empty( $data['product_id'] ) && (int) $data['product_id'] > 0 ) {
			return false;
		}
		return true;
	}

	/**
	 * Identity / matching warnings shared by Check Sheet and import quality logs.
	 *
	 * @param array<string, string> $data
	 * @return list<array{level: string, code: string, message: string, row: int}>
	 */
	public static function identity_row_issues( array $data, int $row_num ): array {
		if ( ! self::should_warn_missing_sku( $data ) ) {
			return array();
		}
		return array(
			self::make_issue(
				'warn',
				'missing_sku',
				__( 'No SKU — product matching relies on title only; duplicates are possible.', 'sheetsync-for-woocommerce' ),
				$row_num
			),
		);
	}

	/**
	 * Fields that should be cleared in WooCommerce when the sheet cell is blank (clear policy only).
	 *
	 * @param list<string>           $empty_mapped Fields mapped but empty in the sheet row.
	 * @param array<string, string>  $data         Mapped row values (for row-type exclusions).
	 * @return list<string>
	 */
	public static function fields_to_clear_in_wc( array $empty_mapped, array $data = array() ): array {
		if ( function_exists( 'sheetsync_empty_cell_policy' )
			&& 'clear' !== sheetsync_empty_cell_policy() ) {
			return array();
		}
		if ( ! class_exists( 'SheetSync_Field_Mapper', false ) ) {
			return array();
		}

		$allowed = SheetSync_Field_Mapper::EMPTY_CELL_CLEAR_FIELDS;
		if ( ! empty( $data ) ) {
			$skip    = SheetSync_Field_Mapper::import_excluded_fields_for_row( $data );
			$allowed = array_values( array_diff( $allowed, $skip ) );
		}

		return array_values( array_intersect( $empty_mapped, $allowed ) );
	}

	/**
	 * Validate pricing, inventory, images, and taxonomy cell values.
	 *
	 * @param array<string, string>                                            $data
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @return list<array<string, mixed>>
	 */
	public static function validate_catalog_fields( array $data, int $row_num, array $maps ): array {
		$issues = array();

		$is_var_parent = class_exists( 'SheetSync_Variation_Sync', false )
			&& SheetSync_Variation_Sync::is_variable_parent_row( $data );
		$is_variation  = class_exists( 'SheetSync_Variation_Sync', false )
			&& SheetSync_Variation_Sync::is_variation_row( $data );

		if ( ! empty( $data['_regular_price'] ) && ! $is_var_parent ) {
			$raw   = trim( (string) $data['_regular_price'] );
			$price = function_exists( 'sheetsync_normalize_sheet_price' )
				? sheetsync_normalize_sheet_price( $raw )
				: $raw;
			if ( $price === '' || ! is_numeric( $price ) ) {
				$issues[] = self::make_issue(
					'error',
					'invalid_price',
					__( 'Regular price must be a plain number for import.', 'sheetsync-for-woocommerce' ),
					$row_num,
					array(
						'group_key' => 'invalid_price|regular',
						'field'     => '_regular_price',
						'column'    => self::mapped_column_label( $maps, '_regular_price', __( 'Regular Price', 'sheetsync-for-woocommerce' ) ),
						'value'     => self::truncate_cell_preview( $raw ),
						'expected'  => $is_variation
							? __( '29.99', 'sheetsync-for-woocommerce' )
							: __( '29.99 (variable parents may use a price range on export — leave blank or use one number)', 'sheetsync-for-woocommerce' ),
					)
				);
			}
		}

		if ( ! empty( $data['_sale_price'] ) && ! $is_var_parent ) {
			$raw  = trim( (string) $data['_sale_price'] );
			$sale = function_exists( 'sheetsync_normalize_sheet_price' )
				? sheetsync_normalize_sheet_price( $raw )
				: $raw;
			if ( $sale === '' || ! is_numeric( $sale ) ) {
				$issues[] = self::make_issue(
					'error',
					'invalid_price',
					__( 'Sale price must be a plain number for import.', 'sheetsync-for-woocommerce' ),
					$row_num,
					array(
						'group_key' => 'invalid_price|sale',
						'field'     => '_sale_price',
						'column'    => self::mapped_column_label( $maps, '_sale_price', __( 'Sale Price', 'sheetsync-for-woocommerce' ) ),
						'value'     => self::truncate_cell_preview( $raw ),
						'expected'  => __( '24.99', 'sheetsync-for-woocommerce' ),
					)
				);
			} elseif ( ! empty( $data['_regular_price'] ) ) {
				$regular = function_exists( 'sheetsync_normalize_sheet_price' )
					? sheetsync_normalize_sheet_price( (string) $data['_regular_price'] )
					: trim( (string) $data['_regular_price'] );
				if ( is_numeric( $regular ) && is_numeric( $sale ) && (float) $sale > (float) $regular ) {
					$issues[] = self::make_issue(
						'warn',
						'sale_above_regular',
						__( 'Sale price is higher than regular price — WooCommerce may ignore the sale.', 'sheetsync-for-woocommerce' ),
						$row_num
					);
				}
			}
		}

		if ( isset( $data['_stock'] ) && trim( (string) $data['_stock'] ) !== '' && ! is_numeric( trim( (string) $data['_stock'] ) ) ) {
			$issues[] = self::make_issue(
				'error',
				'invalid_stock',
				__( 'Stock quantity must be a whole number.', 'sheetsync-for-woocommerce' ),
				$row_num
			);
		}

		if ( ! empty( $data['_stock_status'] ) ) {
			$status = strtolower( trim( (string) $data['_stock_status'] ) );
			if ( ! in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				$issues[] = self::make_issue(
					'warn',
					'invalid_stock_status',
					__( 'Stock status should be instock, outofstock, or onbackorder.', 'sheetsync-for-woocommerce' ),
					$row_num
				);
			}
		}

		if ( ! empty( $data['_product_image'] ) ) {
			$image_issue = self::validate_image_cell_value( (string) $data['_product_image'] );
			if ( is_string( $image_issue ) && $image_issue !== '' ) {
				$issues[] = self::make_issue(
					'warn',
					'invalid_featured_image',
					sprintf(
						/* translators: %s: validation detail */
						__( 'Featured image: %s', 'sheetsync-for-woocommerce' ),
						$image_issue
					),
					$row_num
				);
			}
		}

		if ( ! empty( $data['_gallery_images'] ) ) {
			$tokens = class_exists( 'SheetSync_Sheet_Image_Resolver', false )
				? SheetSync_Sheet_Image_Resolver::split_image_tokens( (string) $data['_gallery_images'] )
				: array_filter( array_map( 'trim', explode( ',', (string) $data['_gallery_images'] ) ) );
			foreach ( array_slice( $tokens, 0, 3 ) as $token ) {
				$image_issue = self::validate_image_cell_value( $token );
				if ( is_string( $image_issue ) && $image_issue !== '' ) {
					$issues[] = self::make_issue(
						'warn',
						'invalid_gallery_image',
						sprintf(
							/* translators: %s: validation detail */
							__( 'Gallery image: %s', 'sheetsync-for-woocommerce' ),
							$image_issue
						),
						$row_num
					);
					break;
				}
			}
		}

		foreach ( array( '_product_cats' => __( 'Categories', 'sheetsync-for-woocommerce' ), '_product_tags' => __( 'Tags', 'sheetsync-for-woocommerce' ) ) as $field => $label ) {
			if ( empty( $data[ $field ] ) ) {
				continue;
			}
			$raw = (string) $data[ $field ];
			if ( str_contains( $raw, '|' ) || str_contains( $raw, ';' ) ) {
				$issues[] = self::make_issue(
					'warn',
					'invalid_taxonomy_separator',
					sprintf(
						/* translators: 1: field label */
						__( '%1$s should be comma-separated names (e.g. Hoodies, Sale) — not pipes or semicolons.', 'sheetsync-for-woocommerce' ),
						$label
					),
					$row_num
				);
			}
		}

		return $issues;
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function make_issue( string $level, string $code, string $message, int $row, array $extra = array() ): array {
		return array_merge(
			array(
				'level'   => $level,
				'code'    => $code,
				'message' => $message,
				'row'     => $row,
			),
			$extra
		);
	}

	/**
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 */
	private static function mapped_column_label( array $maps, string $field, string $fallback ): string {
		$col = trim( (string) ( $maps[ $field ]['sheet_column'] ?? '' ) );
		if ( $col === '' ) {
			return $fallback;
		}
		return sprintf(
			/* translators: 1: column letter, 2: field label */
			__( 'column %1$s (%2$s)', 'sheetsync-for-woocommerce' ),
			$col,
			$fallback
		);
	}

	private static function truncate_cell_preview( string $value, int $max = 48 ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $max ) {
			return mb_substr( $value, 0, $max ) . '…';
		}
		if ( strlen( $value ) > $max ) {
			return substr( $value, 0, $max ) . '…';
		}
		return $value;
	}

	/**
	 * @return string|null Error message when invalid.
	 */
	private static function validate_image_cell_value( string $value ): ?string {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}
		if ( preg_match( '/^\d+$/', $value ) ) {
			$attachment_id = (int) $value;
			if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
				return null;
			}
			return __( 'attachment ID not found in Media Library', 'sheetsync-for-woocommerce' );
		}
		if ( class_exists( 'SheetSync_Sheet_Image_Resolver', false ) ) {
			$url = SheetSync_Sheet_Image_Resolver::normalize_image_url( $value );
			if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return null;
			}
		} elseif ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return null;
		}
		return __( 'use a full image URL or Media Library attachment ID', 'sheetsync-for-woocommerce' );
	}
}

endif;
