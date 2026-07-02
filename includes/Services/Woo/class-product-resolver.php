<?php
/**
 * Single source of truth for matching sheet rows to WooCommerce products.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Product_Resolver' ) ) :

class SheetSync_Product_Resolver {

	/** Sentinel stored in SKU warmup cache when a SKU maps to multiple products. */
	public const SKU_AMBIGUOUS = -1;

	/**
	 * Resolve a WooCommerce product for an import row.
	 *
	 * Priority: product_id → map keys → key field → SKU → title → validated sheet_row.
	 *
	 * @param array<string, string>                                           $data
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 */
	public static function resolve_for_import(
		int $connection_id,
		array $data,
		array $maps,
		int $sheet_row = 0,
		?SheetSync_Product_Updater $updater = null
	): ?WC_Product {
		if ( ! empty( $data['product_id'] ) ) {
			$product = wc_get_product( absint( $data['product_id'] ) );
			if ( $product ) {
				return $product;
			}
		}

		if ( $connection_id > 0 && class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			$map_repo = new SheetSync_Product_Map_Repository();

			$external_key = SheetSync_Product_Map_Repository::external_key_from_import_data( $data, $maps, $sheet_row );
			if ( $external_key !== '' ) {
				$product = self::product_from_map( $map_repo, $connection_id, $external_key );
				if ( $product ) {
					return $product;
				}
			}

			if ( ! empty( $data['post_title'] ) ) {
				$title_key = SheetSync_Product_Map_Repository::title_external_key( (string) $data['post_title'] );
				if ( $title_key !== '' ) {
					$product = self::product_from_map( $map_repo, $connection_id, $title_key );
					if ( $product ) {
						return $product;
					}
				}

				$legacy_title = function_exists( 'sheetsync_normalize_sheet_title' )
					? sanitize_text_field( sheetsync_normalize_sheet_title( (string) $data['post_title'] ) )
					: sanitize_text_field( (string) $data['post_title'] );
				if ( $legacy_title !== '' ) {
					$product = self::product_from_map( $map_repo, $connection_id, $legacy_title );
					if ( $product ) {
						return $product;
					}
				}
			}
		}

		foreach ( $maps as $wc_field => $map_info ) {
			if ( empty( $map_info['is_key_field'] ) || empty( $data[ $wc_field ] ) ) {
				continue;
			}

			$key_value = sanitize_text_field( (string) $data[ $wc_field ] );

			if ( '_sku' === $wc_field ) {
				$id = self::resolve_product_id_by_sku( $key_value, $data, $updater );
				if ( $id > 0 ) {
					$product = wc_get_product( $id );
					if ( $product ) {
						return $product;
					}
				}
			}

			if ( 'post_title' === $wc_field && class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
				$id = SheetSync_Product_Map_Repository::resolve_product_id_by_title( $key_value, $data, $connection_id );
				if ( $id > 0 ) {
					$product = wc_get_product( $id );
					if ( $product ) {
						return $product;
					}
				}
			}

			break;
		}

		if ( ! empty( $data['_sku'] ) ) {
			$id = self::resolve_product_id_by_sku( sanitize_text_field( (string) $data['_sku'] ), $data, $updater );
			if ( $id > 0 ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					return $product;
				}
			}
		}

		if ( ! empty( $data['post_title'] ) && class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			$title = function_exists( 'sheetsync_normalize_sheet_title' )
				? sheetsync_normalize_sheet_title( (string) $data['post_title'] )
				: sanitize_text_field( (string) $data['post_title'] );
			$id    = SheetSync_Product_Map_Repository::resolve_product_id_by_title( $title, $data, $connection_id );
			if ( $id > 0 ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					return $product;
				}
			}
		}

		if ( $connection_id > 0 && $sheet_row > 0 && class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			$map_repo = new SheetSync_Product_Map_Repository();
			$map      = $map_repo->find_by_sheet_row( $connection_id, $sheet_row );
			if ( $map && (int) $map->product_id > 0 && self::validate_sheet_row_binding( $map, $data ) ) {
				$product = wc_get_product( (int) $map->product_id );
				if ( $product ) {
					return $product;
				}
			}
		}

		return null;
	}

	/**
	 * Resolve variable-product parent ID by parent SKU (with ambiguity guard).
	 *
	 * @param array<string, string> $data Optional row data for product_id disambiguation.
	 */
	public static function resolve_parent_id_for_variation(
		int $connection_id,
		string $parent_sku,
		array $data = array()
	): int {
		$parent_sku = sanitize_text_field( $parent_sku );
		if ( $parent_sku === '' ) {
			return 0;
		}

		if ( ! empty( $data['product_id'] ) ) {
			$pid = absint( $data['product_id'] );
			if ( $pid > 0 ) {
				$product = wc_get_product( $pid );
				if ( $product && $product->is_type( 'variable' ) ) {
					return $pid;
				}
			}
		}

		if ( $connection_id > 0 && class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			$map_repo = new SheetSync_Product_Map_Repository();
			$map      = $map_repo->find_by_external_key( $connection_id, $parent_sku );
			if ( $map && (int) $map->product_id > 0 ) {
				$product = wc_get_product( (int) $map->product_id );
				if ( $product && $product->is_type( 'variable' ) ) {
					return (int) $map->product_id;
				}
			}
		}

		if ( class_exists( 'SheetSync_Product_Map_Repository', false )
			&& SheetSync_Product_Map_Repository::sku_is_ambiguous_in_catalog( $parent_sku ) ) {
			return 0;
		}

		$parent_id = (int) wc_get_product_id_by_sku( $parent_sku );
		if ( $parent_id <= 0 ) {
			return 0;
		}

		$product = wc_get_product( $parent_id );
		return ( $product && $product->is_type( 'variable' ) ) ? $parent_id : 0;
	}

	/**
	 * Whether a stored sheet_row map still describes this row's product identity.
	 *
	 * @param object                $map  product_map row.
	 * @param array<string, string> $data Mapped row values.
	 */
	public static function validate_sheet_row_binding( object $map, array $data ): bool {
		$pid = (int) ( $map->product_id ?? 0 );
		if ( $pid <= 0 ) {
			return false;
		}

		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return false;
		}

		if ( ! empty( $data['product_id'] ) ) {
			$row_pid = absint( $data['product_id'] );
			if ( $row_pid > 0 && $row_pid !== $pid ) {
				return false;
			}
		}

		if ( ! empty( $data['_sku'] ) ) {
			$row_sku  = sanitize_text_field( (string) $data['_sku'] );
			$prod_sku = (string) $product->get_sku();
			if ( $prod_sku !== '' && strcasecmp( $row_sku, $prod_sku ) !== 0 ) {
				return false;
			}
		}

		if ( ! empty( $data['post_title'] ) && empty( $data['_sku'] ) ) {
			$row_key = function_exists( 'sheetsync_title_match_key' )
				? sheetsync_title_match_key( (string) $data['post_title'] )
				: strtolower( trim( (string) $data['post_title'] ) );
			$prod_key = function_exists( 'sheetsync_title_match_key' )
				? sheetsync_title_match_key( $product->get_name() )
				: strtolower( trim( $product->get_name() ) );
			if ( $row_key !== '' && $prod_key !== '' && $row_key !== $prod_key ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Refresh product_map external_key after a successful SKU rename.
	 */
	public static function rekey_on_sku_change(
		int $connection_id,
		int $product_id,
		string $old_sku,
		string $new_sku
	): void {
		if ( $connection_id <= 0 || $product_id <= 0 || ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return;
		}

		$old_sku = sanitize_text_field( $old_sku );
		$new_sku = sanitize_text_field( $new_sku );
		if ( $new_sku === '' || $old_sku === $new_sku ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$repo    = new SheetSync_Product_Map_Repository();
		$new_key = SheetSync_Product_Map_Repository::external_key_for_product( $product );
		$map     = $repo->find_by_product_id( $connection_id, $product_id );

		$payload = array(
			'product_id'   => $product_id,
			'external_key' => $new_key,
		);
		if ( $map && isset( $map->sheet_row ) && (int) $map->sheet_row > 0 ) {
			$payload['sheet_row'] = (int) $map->sheet_row;
		}

		$repo->upsert( $connection_id, $payload );
	}

	/**
	 * @param array<string, string> $data
	 */
	private static function resolve_product_id_by_sku(
		string $sku,
		array $data,
		?SheetSync_Product_Updater $updater
	): int {
		if ( $updater instanceof SheetSync_Product_Updater ) {
			return $updater->lookup_product_id_by_sku( $sku, $data );
		}

		$sku = sanitize_text_field( $sku );
		if ( $sku === '' ) {
			return 0;
		}

		if ( class_exists( 'SheetSync_Product_Map_Repository', false )
			&& SheetSync_Product_Map_Repository::sku_is_ambiguous_in_catalog( $sku ) ) {
			if ( ! empty( $data['product_id'] ) ) {
				$pid = absint( $data['product_id'] );
				if ( $pid > 0 ) {
					return $pid;
				}
			}
			return 0;
		}

		return (int) wc_get_product_id_by_sku( $sku );
	}

	private static function product_from_map(
		SheetSync_Product_Map_Repository $map_repo,
		int $connection_id,
		string $external_key
	): ?WC_Product {
		$map = $map_repo->find_by_external_key( $connection_id, $external_key );
		if ( ! $map || (int) $map->product_id <= 0 ) {
			return null;
		}
		$product = wc_get_product( (int) $map->product_id );
		return $product instanceof WC_Product ? $product : null;
	}
}

endif;
