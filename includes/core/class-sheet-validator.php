<?php
/**
 * Pre-sync validation: read Google Sheet product rows and report issues before sync.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sheet_Validator', false ) ) :

class SheetSync_Sheet_Validator {

	/**
	 * @return array{
	 *   ok: bool,
	 *   rows_checked: int,
	 *   stats: array{simple: int, variable_parents: int, variations: int},
	 *   issues: list<array{level: string, message: string, row: int}>
	 * }
	 */
	public function validate_connection( int $connection_id ): array {
		$issues = array();
		$stats  = array(
			'connection_id'     => $connection_id,
			'simple'            => 0,
			'variable_parents'  => 0,
			'variations'        => 0,
		);

		$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
		if ( ! $conn ) {
			return $this->result( false, 0, $stats, array(
				array(
					'level'   => 'error',
					'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ),
					'row'     => 0,
				),
			) );
		}

		if ( SheetSync_Sync_Engine::is_orders_type( $conn->connection_type ?? 'products' ) ) {
			return $this->result( true, 0, $stats, array(
				array(
					'level'   => 'ok',
					'message' => __( 'Sheet check is for product connections only.', 'sheetsync-for-woocommerce' ),
					'row'     => 0,
				),
			) );
		}

		$maps = SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn );
		$sku_col = trim( (string) ( $maps['_sku']['sheet_column'] ?? '' ) );
		$pid_col = trim( (string) ( $maps['product_id']['sheet_column'] ?? '' ) );
		if ( empty( $maps ) || ( $sku_col === '' && $pid_col === '' ) ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => SheetSync_Field_Mapper::identity_requirement_message( $maps ),
				'row'     => 0,
			);
			return $this->result( false, 0, $stats, $issues );
		}

		if ( empty( $maps['_product_image']['sheet_column'] ?? '' ) ) {
			$issues[] = array(
				'level'   => 'warn',
				'message' => __( 'Featured Image URL is not mapped — products will sync without a main image.', 'sheetsync-for-woocommerce' ),
				'row'     => 0,
			);
		}

		foreach ( array(
			'parent_sku'    => __( 'Parent SKU', 'sheetsync-for-woocommerce' ),
			'_product_type' => __( 'Product Type', 'sheetsync-for-woocommerce' ),
			'sheet_color'   => __( 'Color', 'sheetsync-for-woocommerce' ),
			'sheet_size'    => __( 'Size', 'sheetsync-for-woocommerce' ),
		) as $field => $label ) {
			$variable_map_warnings[ $field ] = $label;
		}

		$updater    = new SheetSync_Product_Updater( $maps );
		$client     = new SheetSync_Sheets_Client();
		$last_col   = SheetSync_Field_Mapper::max_column_letter( $maps );
		$header_row = max( 1, (int) $conn->header_row );
		$first_row  = $header_row + 1;
		$batch      = 200;
		$max_rows   = function_exists( 'sheetsync_sheet_validation_max_rows' )
			? sheetsync_sheet_validation_max_rows()
			: 500;
		$offset     = 0;
		$rows_data  = array();
		$hit_limit  = false;

		while ( $offset < $max_rows ) {
			$start_row = $first_row + $offset;
			$end_row   = min( $start_row + $batch - 1, $first_row + $max_rows - 1 );
			$range     = "{$conn->sheet_name}!A{$start_row}:{$last_col}{$end_row}";

			try {
				$chunk = $client->get_rows( $conn->spreadsheet_id, $range );
			} catch ( Exception $e ) {
				$issues[] = array(
					'level'   => 'error',
					'message' => $e->getMessage(),
					'row'     => 0,
				);
				return $this->result( false, count( $rows_data ), $stats, $issues );
			}

			if ( empty( $chunk ) ) {
				break;
			}

			foreach ( $chunk as $i => $row ) {
				if ( empty( array_filter( $row, static fn( $v ) => $v !== '' && $v !== null ) ) ) {
					continue;
				}
				$sheet_row = $start_row + $i;
				$data      = $updater->extract_data( $row );
				$skip_reason = function_exists( 'sheetsync_importable_product_row_skip_reason' )
					? sheetsync_importable_product_row_skip_reason( $data )
					: null;
				if ( is_string( $skip_reason ) && $skip_reason !== '' ) {
					$issues[] = array(
						'level'   => 'error',
						'message' => $skip_reason,
						'row'     => $sheet_row,
					);
					continue;
				}
				$rows_data[] = array(
					'row'  => $sheet_row,
					'data' => $data,
				);
			}

			if ( count( $chunk ) < $batch ) {
				break;
			}
			$offset += $batch;
			if ( $offset >= $max_rows ) {
				$hit_limit = true;
			}
		}

		if ( $hit_limit && ! empty( $rows_data ) ) {
			$issues[] = array(
				'level'   => 'warn',
				'message' => sprintf(
					/* translators: %d: maximum rows scanned */
					__( 'Validation scanned the first %d product rows only — run sync on a staging copy or split very large sheets.', 'sheetsync-for-woocommerce' ),
					$max_rows
				),
				'row'     => 0,
			);
		}

		if ( empty( $rows_data ) ) {
			$issues[] = array(
				'level'   => 'warn',
				'message' => __( 'No product rows found below the header row.', 'sheetsync-for-woocommerce' ),
				'row'     => 0,
			);
			return $this->result( false, 0, $stats, $issues );
		}

		if ( empty( $maps['_regular_price']['sheet_column'] ?? '' )
			&& empty( $maps['_sale_price']['sheet_column'] ?? '' ) ) {
			foreach ( $rows_data as $item ) {
				$data = $item['data'];
				if ( class_exists( 'SheetSync_Variation_Sync', false )
					&& ( SheetSync_Variation_Sync::is_variation_row( $data ) || SheetSync_Variation_Sync::is_variable_parent_row( $data ) ) ) {
					continue;
				}
				if ( ! empty( $data['_sku'] ) || ! empty( $data['post_title'] ) ) {
					$issues[] = array(
						'level'   => 'warn',
						'message' => __( 'No price column mapped (D/H) — new products without a price may save as draft until you add Regular Price.', 'sheetsync-for-woocommerce' ),
						'row'     => 0,
					);
					break;
				}
			}
		}

		$skus_in_sheet    = array();
		$parents_in_sheet = array();

		foreach ( $rows_data as $item ) {
			$data = $item['data'];
			$sku  = sanitize_text_field( $data['_sku'] ?? '' );
			if ( $sku !== '' && ! isset( $skus_in_sheet[ $sku ] ) ) {
				$skus_in_sheet[ $sku ] = array(
					'row'  => (int) $item['row'],
					'data' => $data,
				);
			}
			if ( class_exists( 'SheetSync_Variation_Sync', false ) && SheetSync_Variation_Sync::is_variable_parent_row( $data ) && $sku !== '' ) {
				$parents_in_sheet[ $sku ] = (int) $item['row'];
			}
		}

		$sheet_has_variable_rows = ! empty( $parents_in_sheet );
		foreach ( $rows_data as $item ) {
			if ( class_exists( 'SheetSync_Variation_Sync', false )
				&& SheetSync_Variation_Sync::is_variation_row( $item['data'] ) ) {
				$sheet_has_variable_rows = true;
				break;
			}
		}

		if ( $sheet_has_variable_rows ) {
			foreach ( $variable_map_warnings as $field => $label ) {
				if ( empty( $maps[ $field ]['sheet_column'] ?? '' ) ) {
					$issues[] = array(
						'level'   => 'warn',
						'message' => sprintf(
							/* translators: %s: field label */
							__( '%s is not mapped — variable rows in your sheet may import as simple products.', 'sheetsync-for-woocommerce' ),
							$label
						),
						'row'     => 0,
					);
				}
			}
			if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
				$issues[] = array(
					'level'   => 'error',
					'message' => __( 'Your sheet contains variable product rows — SheetSync Pro is required to import parents and variations.', 'sheetsync-for-woocommerce' ),
					'row'     => 0,
				);
			}
		}

		foreach ( $rows_data as $item ) {
			$row_num = (int) $item['row'];
			$data    = $item['data'];
			$sku     = sanitize_text_field( $data['_sku'] ?? '' );
			$title   = sanitize_text_field( $data['post_title'] ?? '' );

			if ( $sku === '' && $title === '' ) {
				$issues[] = array(
					'level'   => 'warn',
					'message' => __( 'Row has no SKU and no title — will be skipped.', 'sheetsync-for-woocommerce' ),
					'row'     => $row_num,
				);
				continue;
			}

			$status = strtolower( trim( (string) ( $data['post_status'] ?? 'publish' ) ) );
			$is_var_parent = class_exists( 'SheetSync_Variation_Sync', false )
				&& SheetSync_Variation_Sync::is_variable_parent_row( $data );
			if ( ! $is_var_parent && empty( $data['parent_sku'] ) && in_array( $status, array( 'publish', '' ), true ) ) {
				$regular = trim( (string) ( $data['_regular_price'] ?? '' ) );
				$sale    = trim( (string) ( $data['_sale_price'] ?? '' ) );
				if ( $regular === '' && $sale === '' ) {
					$issues[] = array(
						'level'   => 'warn',
						'message' => __( 'No price on a publish row — product will be saved as draft until price is set.', 'sheetsync-for-woocommerce' ),
						'row'     => $row_num,
					);
				}
			}

			if ( $sku !== '' ) {
				$first_entry = $skus_in_sheet[ $sku ] ?? null;
				$first_row   = is_array( $first_entry ) ? (int) ( $first_entry['row'] ?? 0 ) : (int) $first_entry;
				if ( $first_row > 0 && $first_row !== $row_num && is_array( $first_entry )
					&& self::is_real_duplicate_sku( $data, $first_entry ) ) {
					$issues[] = self::make_issue(
						'error',
						'duplicate_sku',
						sprintf(
							/* translators: 1: SKU, 2: first row number */
							__( 'Duplicate SKU "%1$s" — another simple or parent product already uses this SKU on row %2$d.', 'sheetsync-for-woocommerce' ),
							$sku,
							$first_row
						),
						$row_num,
						array(
							'group_key' => 'duplicate_sku|' . strtolower( $sku ),
							'field'     => '_sku',
						)
					);
				}

				if ( class_exists( 'SheetSync_Product_Map_Repository', false )
					&& SheetSync_Product_Map_Repository::sku_is_ambiguous_in_catalog( $sku )
					&& empty( $data['product_id'] ) ) {
					$issues[] = array(
						'level'   => 'warn',
						'message' => sprintf(
							/* translators: %s: SKU */
							__( 'SKU "%s" matches multiple WooCommerce products — add Product ID column or fix duplicate SKUs in WooCommerce before import.', 'sheetsync-for-woocommerce' ),
							$sku
						),
						'row'     => $row_num,
					);
				}

				$wc_id = (int) wc_get_product_id_by_sku( $sku );
				if ( $wc_id > 0 && class_exists( 'SheetSync_Variation_Sync', false ) ) {
					$wc_product = wc_get_product( $wc_id );
					if ( SheetSync_Variation_Sync::is_variation_row( $data ) && $wc_product && ! $wc_product instanceof WC_Product_Variation ) {
						$issues[] = array(
							'level'    => 'error',
							'message'  => sprintf(
								/* translators: 1: SKU, 2: product ID */
								__( 'SKU "%1$s" already exists as a normal product (#%2$d). Trash or delete it in WooCommerce → Products, then sync again as a variation row.', 'sheetsync-for-woocommerce' ),
								$sku,
								$wc_id
							),
							'row'      => $row_num,
							'edit_url' => get_edit_post_link( $wc_id, 'raw' ),
						);
					}
				}
			}

			if ( $sku === '' && $title !== '' ) {
				$issues = array_merge( $issues, SheetSync_Import_Rules::identity_row_issues( $data, $row_num ) );
			}

			$issues = array_merge( $issues, self::validate_long_text_fields( $data, $row_num ) );
			$issues = array_merge( $issues, SheetSync_Import_Rules::validate_catalog_fields( $data, $row_num, $maps ) );

			if ( ! class_exists( 'SheetSync_Variation_Sync', false ) || ! SheetSync_Variation_Sync::is_variation_row( $data ) ) {
				$regular = trim( (string) ( $data['_regular_price'] ?? '' ) );
				$sale    = trim( (string) ( $data['_sale_price'] ?? '' ) );
				$is_parent = class_exists( 'SheetSync_Variation_Sync', false )
					&& SheetSync_Variation_Sync::is_variable_parent_row( $data );
				if ( ! $is_parent && $regular === '' && $sale === '' && ( $sku !== '' || $title !== '' ) ) {
					$issues[] = array(
						'level'   => 'warn',
						'message' => __( 'No price — product may be unpurchasable until a price is set.', 'sheetsync-for-woocommerce' ),
						'row'     => $row_num,
					);
				}
			}

			if ( 'grouped' === strtolower( trim( (string) ( $data['_product_type'] ?? '' ) ) ) ) {
				$raw = trim( (string) ( $data['grouped_child_skus'] ?? '' ) );
				if ( $raw !== '' ) {
					foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $token ) {
						if ( preg_match( '/^id:(\d+)$/i', $token ) ) {
							continue;
						}
						$child_sku = function_exists( 'sheetsync_normalize_sheet_sku' )
							? sheetsync_normalize_sheet_sku( $token )
							: sanitize_text_field( $token );
						if ( $child_sku === '' ) {
							continue;
						}
						$child_entry = $skus_in_sheet[ $child_sku ] ?? null;
						$child_row   = is_array( $child_entry ) ? (int) ( $child_entry['row'] ?? 0 ) : (int) $child_entry;
						if ( $child_row > $row_num ) {
							$issues[] = array(
								'level'   => 'warn',
								'message' => sprintf(
									/* translators: 1: child SKU, 2: child row number, 3: grouped parent row number */
									__( 'Grouped child SKU "%1$s" is on row %2$d (after this grouped parent on row %3$d) — move child rows above the grouped parent for reliable import.', 'sheetsync-for-woocommerce' ),
									$child_sku,
									$child_row,
									$row_num
								),
								'row'     => $row_num,
							);
						}
					}
				}
			}

			if ( ! class_exists( 'SheetSync_Variation_Sync', false ) ) {
				++$stats['simple'];
				continue;
			}

			if ( SheetSync_Variation_Sync::is_variable_parent_row( $data ) ) {
				++$stats['variable_parents'];
				$attrs = trim( (string) ( $data['variation_attrs'] ?? '' ) );
				if ( $attrs === '' ) {
					$issues[] = array(
						'level'   => 'warn',
						'message' => __( 'Variable parent needs Color and/or Size (e.g. Color = red,black and Size = s,m), or Variation Attributes.', 'sheetsync-for-woocommerce' ),
						'row'     => $row_num,
					);
				} else {
					$issues = array_merge( $issues, self::validate_attribute_string( $attrs, $row_num ) );
				}
				continue;
			}

			if ( SheetSync_Variation_Sync::is_variation_row( $data ) ) {
				++$stats['variations'];
				$parent_sku = sanitize_text_field( $data['parent_sku'] ?? '' );
				$attrs      = trim( (string) ( $data['variation_attrs'] ?? '' ) );

				if ( $parent_sku === '' ) {
					$issues[] = array(
						'level'   => 'error',
						'message' => __( 'Variation row is missing Parent SKU.', 'sheetsync-for-woocommerce' ),
						'row'     => $row_num,
					);
				} elseif ( ! isset( $parents_in_sheet[ $parent_sku ] ) ) {
					$parent_id = (int) wc_get_product_id_by_sku( $parent_sku );
					$parent    = $parent_id ? wc_get_product( $parent_id ) : null;
					if ( ! $parent instanceof WC_Product_Variable ) {
						$issues[] = array(
							'level'   => 'error',
							'message' => sprintf(
								/* translators: %s: parent SKU */
								__( 'Parent SKU "%s" not found in sheet (variable parent row) or as a variable product in WooCommerce.', 'sheetsync-for-woocommerce' ),
								$parent_sku
							),
							'row'     => $row_num,
						);
					}
				} else {
					$parent_row = (int) ( $parents_in_sheet[ $parent_sku ] ?? 0 );
					if ( $parent_row > $row_num ) {
						$issues[] = array(
							'level'   => 'warn',
							'message' => sprintf(
								/* translators: 1: parent SKU, 2: parent row number */
								__( 'Variation row appears above its variable parent (parent SKU "%1$s" is on row %2$d). Sync reorders rows automatically, but placing the parent row first is easier to read.', 'sheetsync-for-woocommerce' ),
								$parent_sku,
								$parent_row
							),
							'row'     => $row_num,
						);
					}
				}

				if ( $attrs === '' ) {
					$issues[] = array(
						'level'   => 'error',
						'message' => __( 'Variation row needs Parent SKU plus Color and/or Size (one value each), or Variation Attributes.', 'sheetsync-for-woocommerce' ),
						'row'     => $row_num,
					);
				} else {
					$issues = array_merge( $issues, self::validate_attribute_string( $attrs, $row_num ) );
				}
				continue;
			}

			++$stats['simple'];
		}

		if ( class_exists( 'SheetSync_Sheet_Validation_Report', false ) ) {
			return SheetSync_Sheet_Validation_Report::build( $issues, $stats, count( $rows_data ) );
		}

		$has_errors = false;
		foreach ( $issues as $issue ) {
			if ( 'error' === ( $issue['level'] ?? '' ) ) {
				$has_errors = true;
				break;
			}
		}

		return array(
			'ok'           => ! $has_errors,
			'rows_checked' => count( $rows_data ),
			'stats'        => $stats,
			'issues'       => $issues,
		);
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private static function make_issue( string $level, string $code, string $message, int $row, array $extra = array() ): array {
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
	 * True when a repeated SKU is a real catalog conflict (not parent/variation inheritance).
	 *
	 * @param array<string, string> $data
	 * @param array{row: int, data: array<string, string>} $first_entry
	 */
	private static function is_real_duplicate_sku( array $data, array $first_entry ): bool {
		if ( class_exists( 'SheetSync_Variation_Sync', false ) && SheetSync_Variation_Sync::is_variation_row( $data ) ) {
			return false;
		}
		if ( ! empty( $data['product_id'] ) && (int) $data['product_id'] > 0 ) {
			return false;
		}

		$sku        = sanitize_text_field( $data['_sku'] ?? '' );
		$parent_sku = sanitize_text_field( $data['parent_sku'] ?? '' );
		if ( $parent_sku !== '' && strcasecmp( $parent_sku, $sku ) === 0 ) {
			return false;
		}

		$first_data = is_array( $first_entry['data'] ?? null ) ? $first_entry['data'] : array();
		if ( class_exists( 'SheetSync_Variation_Sync', false ) ) {
			if ( SheetSync_Variation_Sync::is_variable_parent_row( $first_data )
				&& $parent_sku !== ''
				&& strcasecmp( $parent_sku, sanitize_text_field( $first_data['_sku'] ?? '' ) ) === 0 ) {
				return false;
			}
			if ( SheetSync_Variation_Sync::is_variation_row( $first_data ) ) {
				return true;
			}
		}

		return true;
	}

	/**
	 * Warn when mapped long-text fields exceed Google Sheets cell limits.
	 *
	 * @param array<string, string> $data
	 * @return list<array{level: string, message: string, row: int}>
	 */
	private static function validate_long_text_fields( array $data, int $row_num ): array {
		$issues = array();
		$max    = function_exists( 'sheetsync_google_sheets_cell_char_limit' )
			? sheetsync_google_sheets_cell_char_limit()
			: 50000;
		$labels = array(
			'post_content'     => __( 'Description', 'sheetsync-for-woocommerce' ),
			'post_excerpt'     => __( 'Short description', 'sheetsync-for-woocommerce' ),
			'_gallery_images'  => __( 'Gallery images', 'sheetsync-for-woocommerce' ),
		);
		foreach ( $labels as $field => $label ) {
			if ( empty( $data[ $field ] ) ) {
				continue;
			}
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $data[ $field ] ) : strlen( (string) $data[ $field ] );
			if ( $len > $max ) {
				$issues[] = array(
					'level'   => 'warn',
					'message' => sprintf(
						/* translators: 1: field label, 2: character count, 3: limit */
						__( '%1$s exceeds Google Sheets limit (%2$d chars; max %3$d) — text will be truncated on export.', 'sheetsync-for-woocommerce' ),
						$label,
						$len,
						$max
					),
					'row'     => $row_num,
				);
			}
		}
		return $issues;
	}

	/**
	 * @return list<array{level: string, message: string, row: int}>
	 */
	private static function validate_attribute_string( string $attrs, int $row_num ): array {
		$issues = array();
		$parts  = explode( '|', $attrs );
		foreach ( $parts as $segment ) {
			$segment = trim( $segment );
			if ( $segment === '' || ! str_contains( $segment, ':' ) ) {
				continue;
			}
			list( $key, $val ) = array_pad( explode( ':', $segment, 2 ), 2, '' );
			$key = trim( $key );
			$val = trim( $val );
			if ( $key === '' || $val === '' ) {
				continue;
			}
			if ( 0 !== strpos( $key, 'pa_' ) ) {
				continue;
			}

			$label    = self::attribute_friendly_label( $key );
			$resolved = self::resolve_wc_attribute_taxonomy( $key );

			if ( null === $resolved ) {
				if ( class_exists( 'SheetSync_Attribute_Bootstrap', false )
					&& SheetSync_Attribute_Bootstrap::auto_create_enabled() ) {
					continue;
				}
				$issues[] = self::make_issue(
					'warn',
					'missing_attribute',
					sprintf(
						/* translators: %s: attribute label e.g. Color */
						__( 'Missing WooCommerce attribute "%s" — create it under Products → Attributes before import.', 'sheetsync-for-woocommerce' ),
						$label
					),
					$row_num,
					array(
						'group_key' => 'missing_attribute|' . $key,
						'field'     => $key,
					)
				);
				continue;
			}

			if ( $resolved !== $key ) {
				$issues[] = self::make_issue(
					'warn',
					'attribute_slug_mismatch',
					sprintf(
						/* translators: 1: sheet slug, 2: WooCommerce slug */
						__( 'Attribute "%1$s" exists in WooCommerce as %2$s — sheet values should use the WooCommerce slug.', 'sheetsync-for-woocommerce' ),
						$label,
						$resolved
					),
					$row_num,
					array(
						'group_key' => 'attribute_slug|' . $resolved,
						'field'     => $key,
					)
				);
				$key = $resolved;
			}

			foreach ( self::parse_attribute_term_slugs( $val ) as $slug ) {
				if ( term_exists( $slug, $key ) ) {
					continue;
				}
				if ( class_exists( 'SheetSync_Attribute_Bootstrap', false )
					&& SheetSync_Attribute_Bootstrap::auto_create_enabled() ) {
					continue;
				}
				$issues[] = self::make_issue(
					'warn',
					'missing_attribute_term',
					sprintf(
						/* translators: 1: term slug, 2: attribute label e.g. Color */
						__( 'Value "%1$s" is not configured under WooCommerce → Products → Attributes → %2$s.', 'sheetsync-for-woocommerce' ),
						$slug,
						$label
					),
					$row_num,
					array(
						'group_key' => 'missing_attribute_term|' . $key . '|' . $slug,
						'field'     => $key,
						'value'     => $slug,
					)
				);
			}
		}
		return $issues;
	}

	/**
	 * Resolve global attribute taxonomy, including label-based fallback.
	 */
	private static function resolve_wc_attribute_taxonomy( string $pa_key ): ?string {
		if ( taxonomy_exists( $pa_key ) ) {
			return $pa_key;
		}
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return null;
		}
		$label = self::attribute_friendly_label( $pa_key );
		foreach ( wc_get_attribute_taxonomies() as $attr ) {
			if ( strcasecmp( (string) $attr->attribute_label, $label ) === 0 ) {
				$slug = 'pa_' . (string) $attr->attribute_name;
				return taxonomy_exists( $slug ) ? $slug : null;
			}
		}
		return null;
	}

	/**
	 * Split "red,black" or "s,m" into separate term slugs (never "redblack" / "sm").
	 *
	 * @return list<string>
	 */
	private static function parse_attribute_term_slugs( string $val ): array {
		$slugs = array();
		foreach ( explode( ',', $val ) as $part ) {
			$slug = sanitize_title( trim( (string) $part ) );
			if ( $slug !== '' ) {
				$slugs[] = $slug;
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * User-facing label — sheet uses "Color" / "Size", not pa_color.
	 */
	private static function attribute_friendly_label( string $pa_key ): string {
		$labels = array(
			'pa_color'    => 'Color',
			'pa_size'     => 'Size',
			'pa_material' => 'Material',
			'pa_style'    => 'Style',
		);
		if ( isset( $labels[ $pa_key ] ) ) {
			return $labels[ $pa_key ];
		}
		$name = str_replace( 'pa_', '', $pa_key );
		return ucwords( str_replace( array( '-', '_' ), ' ', $name ) );
	}
}

endif;
