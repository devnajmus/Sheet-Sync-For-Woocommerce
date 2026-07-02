<?php
/**
 * Create WooCommerce global attributes and terms before sheet import.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Attribute_Bootstrap', false ) ) :

class SheetSync_Attribute_Bootstrap {

	/**
	 * Whether missing pa_* attributes/terms are created automatically on import.
	 */
	public static function auto_create_enabled(): bool {
		return (bool) apply_filters( 'sheetsync_auto_create_attributes', true );
	}

	/**
	 * Register a global WooCommerce attribute taxonomy (e.g. pa_color).
	 */
	public static function ensure_global_attribute( string $pa_key, ?string $label = null ): bool {
		if ( 0 !== strpos( $pa_key, 'pa_' ) ) {
			return false;
		}
		if ( taxonomy_exists( $pa_key ) ) {
			return true;
		}
		if ( ! function_exists( 'wc_create_attribute' ) ) {
			return false;
		}

		$slug = substr( $pa_key, 3 );
		if ( $slug === '' ) {
			return false;
		}

		$name = $label !== null && $label !== ''
			? $label
			: ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );

		$result = wc_create_attribute(
			array(
				'name'         => $name,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);

		if ( is_wp_error( $result ) ) {
			return taxonomy_exists( $pa_key );
		}

		delete_transient( 'wc_attribute_taxonomies' );
		if ( class_exists( 'WC_Cache_Helper', false ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		}
		if ( class_exists( 'WC_Post_Types', false ) ) {
			WC_Post_Types::register_taxonomies();
		}

		return taxonomy_exists( $pa_key );
	}

	/**
	 * Ensure a term exists on a product attribute taxonomy.
	 */
	public static function ensure_term( string $taxonomy, string $slug ): bool {
		$slug = sanitize_title( $slug );
		if ( $slug === '' || $taxonomy === '' ) {
			return false;
		}
		if ( 0 === strpos( $taxonomy, 'pa_' ) && ! taxonomy_exists( $taxonomy ) ) {
			if ( ! self::ensure_global_attribute( $taxonomy ) ) {
				return false;
			}
		}
		if ( term_exists( $slug, $taxonomy ) ) {
			return true;
		}
		$insert = wp_insert_term(
			$slug,
			$taxonomy,
			array( 'slug' => $slug )
		);
		return ! is_wp_error( $insert );
	}

	/**
	 * Scan the connection sheet and create missing attributes/terms.
	 *
	 * @return array{attributes: int, terms: int, skipped: bool}
	 */
	public static function bootstrap_connection( int $connection_id ): array {
		$result = array(
			'attributes' => 0,
			'terms'      => 0,
			'skipped'    => false,
		);

		if ( $connection_id <= 0 || ! self::auto_create_enabled() ) {
			$result['skipped'] = true;
			return $result;
		}

		$conn = class_exists( 'SheetSync_Sync_Engine', false )
			? SheetSync_Sync_Engine::get_connection( $connection_id )
			: null;
		if ( ! $conn ) {
			$result['skipped'] = true;
			return $result;
		}

		$maps = SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn );
		if ( empty( $maps ) ) {
			$result['skipped'] = true;
			return $result;
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
		$seen_terms = array();

		while ( $offset < $max_rows ) {
			$start_row = $first_row + $offset;
			$end_row   = min( $start_row + $batch - 1, $first_row + $max_rows - 1 );
			$range     = "{$conn->sheet_name}!A{$start_row}:{$last_col}{$end_row}";

			try {
				$chunk = $client->get_rows( $conn->spreadsheet_id, $range );
			} catch ( Exception $e ) {
				break;
			}

			if ( empty( $chunk ) ) {
				break;
			}

			foreach ( $chunk as $row ) {
				if ( empty( array_filter( $row, static fn( $v ) => $v !== '' && $v !== null ) ) ) {
					continue;
				}
				$data  = $updater->extract_data( $row );
				$attrs = self::variation_attrs_for_row( $data );
				if ( $attrs === '' ) {
					continue;
				}
				foreach ( self::parse_attribute_segments( $attrs ) as $segment ) {
					$key = $segment['taxonomy'];
					if ( ! taxonomy_exists( $key ) && self::ensure_global_attribute( $key, $segment['label'] ) ) {
						++$result['attributes'];
					}
					foreach ( $segment['terms'] as $slug ) {
						$term_key = $key . '|' . $slug;
						if ( isset( $seen_terms[ $term_key ] ) ) {
							continue;
						}
						$seen_terms[ $term_key ] = true;
						$had_term = (bool) term_exists( $slug, $key );
						if ( self::ensure_term( $key, $slug ) && ! $had_term ) {
							++$result['terms'];
						}
					}
				}
			}

			if ( count( $chunk ) < $batch ) {
				break;
			}
			$offset += $batch;
		}

		return $result;
	}

	/**
	 * @return list<array{taxonomy: string, label: string, terms: string[]}>
	 */
	public static function parse_attribute_segments( string $attrs ): array {
		$segments = array();
		foreach ( explode( '|', $attrs ) as $segment ) {
			$segment = trim( $segment );
			if ( $segment === '' || ! str_contains( $segment, ':' ) ) {
				continue;
			}
			list( $key, $val ) = array_pad( explode( ':', $segment, 2 ), 2, '' );
			$key = sanitize_key( trim( $key ) );
			$val = trim( $val );
			if ( $key === '' || $val === '' || 0 !== strpos( $key, 'pa_' ) ) {
				continue;
			}
			$terms = array();
			foreach ( explode( ',', $val ) as $part ) {
				$slug = sanitize_title( trim( $part ) );
				if ( $slug !== '' ) {
					$terms[] = $slug;
				}
			}
			if ( empty( $terms ) ) {
				continue;
			}
			$segments[] = array(
				'taxonomy' => $key,
				'label'    => self::attribute_label( $key ),
				'terms'    => array_values( array_unique( $terms ) ),
			);
		}
		return $segments;
	}

	/**
	 * Variation attribute string from mapped row (variation_attrs or Color/Size columns).
	 */
	public static function variation_attrs_for_row( array $data ): string {
		$attrs = trim( (string) ( $data['variation_attrs'] ?? '' ) );
		if ( $attrs !== '' ) {
			return $attrs;
		}

		$color = trim( (string) ( $data['sheet_color'] ?? '' ) );
		$size  = trim( (string) ( $data['sheet_size'] ?? '' ) );
		if ( $color === '' && $size === '' ) {
			return '';
		}

		$segments = array();
		if ( $color !== '' ) {
			$segments[] = self::format_simple_attribute_segment( 'pa_color', $color, $data );
		}
		if ( $size !== '' ) {
			$segments[] = self::format_simple_attribute_segment( 'pa_size', $size, $data );
		}

		return implode( '|', array_filter( $segments ) );
	}

	/**
	 * @param array<string, string> $data
	 */
	private static function format_simple_attribute_segment( string $pa_key, string $value, array $data ): string {
		$is_parent = class_exists( 'SheetSync_Variation_Sync', false )
			&& SheetSync_Variation_Sync::is_variable_parent_row( $data );
		$is_var    = class_exists( 'SheetSync_Variation_Sync', false )
			&& SheetSync_Variation_Sync::is_variation_row( $data );

		if ( $is_var ) {
			return $pa_key . ':' . sanitize_title( $value );
		}
		if ( $is_parent || str_contains( $value, ',' ) ) {
			$parts = array_filter( array_map( static function ( $v ) {
				return sanitize_title( trim( (string) $v ) );
			}, explode( ',', $value ) ) );
			return $pa_key . ':' . implode( ',', $parts );
		}

		return $pa_key . ':' . sanitize_title( $value );
	}

	private static function attribute_label( string $pa_key ): string {
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
