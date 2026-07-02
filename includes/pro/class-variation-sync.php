<?php
/**
 * PRO: Variable products — parent rows + variation rows from sheet data.
 *
 * Parent row: `_product_type` = variable, no `parent_sku`. Optional `variation_attrs` defines
 * global/custom options using commas for multiple values per attribute, e.g.:
 *   pa_color:red,blue|pa_size:s,m|Brand:Acme
 * Variation row: `parent_sku` set, `variation_attrs` single slug per attribute, e.g.:
 *   pa_color:red|pa_size:s|Brand:acme
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Variation_Sync {

	/** @var bool */
	private static bool $hooks_registered = false;

	/** @var string|null Last skip/error reason for import UI. */
	private static ?string $last_message = null;

	/**
	 * Per-parent variation lookup: normalized attribute hash => WC_Product_Variation.
	 *
	 * @var array<int, array<string, WC_Product_Variation>>
	 */
	private static array $variation_index_cache = array();

	/** @var array<int, true> Parents already logged for duplicate variation attributes. */
	private static array $duplicate_variation_logged = array();

	public function __construct() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		add_filter( 'sheetsync_product_row_handled', array( $this, 'maybe_handle_variable_parent' ), 5, 5 );
		add_filter( 'sheetsync_product_row_handled', array( $this, 'maybe_handle_variation' ), 10, 5 );
	}

	/**
	 * @param array<string, string> $data Mapped row data.
	 */
	public static function is_variation_row( array $data ): bool {
		$parent = trim( (string) ( $data['parent_sku'] ?? '' ) );
		if ( $parent === '' ) {
			return false;
		}
		$sku = trim( (string) ( $data['_sku'] ?? '' ) );
		if ( $sku !== '' && strcasecmp( $parent, $sku ) === 0 ) {
			return false;
		}
		if ( 'variable' === strtolower( trim( (string) ( $data['_product_type'] ?? '' ) ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * @param array<string, string> $data Mapped row data.
	 */
	public static function is_variable_parent_row( array $data ): bool {
		if ( self::is_variation_row( $data ) ) {
			return false;
		}
		return 'variable' === strtolower( trim( (string) ( $data['_product_type'] ?? '' ) ) );
	}

	/**
	 * Variation / variable-parent rows must not fall through to WC_Product_Simple creation.
	 *
	 * @param array<string, string> $data Mapped row data.
	 */
	public static function should_use_simple_create_fallback( array $data ): bool {
		return ! self::is_variation_row( $data ) && ! self::is_variable_parent_row( $data );
	}

	/**
	 * Sort sheet rows: variable parents → other products → variation rows.
	 *
	 * When $start_row_1based > 0, each item is `{ sheet_row: int, row: string[] }` so callers
	 * keep the real Google Sheet row number after reordering. When 0, returns plain row arrays.
	 *
	 * @param array<int, array<int, string>>     $rows              Raw sheet rows in fetch order.
	 * @param SheetSync_Product_Updater          $updater           Updater with field maps.
	 * @param int                                $start_row_1based  1-based sheet row for $rows[0]; 0 = plain rows.
	 * @return array<int, array<int, string>|array{sheet_row: int, row: array<int, string>}>
	 */
	public static function sort_rows_parents_first( array $rows, SheetSync_Product_Updater $updater, int $start_row_1based = 0 ): array {
		$tagged = array();
		foreach ( $rows as $i => $row ) {
			$tagged[] = array(
				'sheet_row' => $start_row_1based > 0 ? $start_row_1based + $i : 0,
				'row'       => $row,
			);
		}

		$parents    = array();
		$others     = array();
		$variations = array();

		foreach ( $tagged as $item ) {
			$row = $item['row'];
			if ( empty( array_filter( $row, static fn( $v ) => $v !== '' && $v !== null ) ) ) {
				continue;
			}
			$data = $updater->extract_data( $row );
			if ( self::is_variable_parent_row( $data ) ) {
				$parents[] = $item;
			} elseif ( self::is_variation_row( $data ) ) {
				$variations[] = $item;
			} else {
				$others[] = $item;
			}
		}

		if ( empty( $variations ) ) {
			$sorted = array_merge( $parents, $others );
			return self::unwrap_sorted_rows( $sorted, $start_row_1based );
		}

		$by_parent_sku = array();
		foreach ( $variations as $item ) {
			$data  = $updater->extract_data( $item['row'] );
			$p_sku = strtolower( trim( (string) ( $data['parent_sku'] ?? '' ) ) );
			if ( $p_sku === '' ) {
				$others[] = $item;
				continue;
			}
			$by_parent_sku[ $p_sku ][] = $item;
		}

		$ordered = array();
		foreach ( $parents as $item ) {
			$ordered[] = $item;
			$data      = $updater->extract_data( $item['row'] );
			$p_sku     = strtolower( trim( (string) ( $data['_sku'] ?? '' ) ) );
			if ( $p_sku !== '' && ! empty( $by_parent_sku[ $p_sku ] ) ) {
				foreach ( $by_parent_sku[ $p_sku ] as $var_item ) {
					$ordered[] = $var_item;
				}
				unset( $by_parent_sku[ $p_sku ] );
			}
		}

		foreach ( $others as $item ) {
			$ordered[] = $item;
		}

		foreach ( $by_parent_sku as $orphan_items ) {
			foreach ( $orphan_items as $item ) {
				$ordered[] = $item;
			}
		}

		return self::unwrap_sorted_rows( $ordered, $start_row_1based );
	}

	/**
	 * @param array<int, array{sheet_row: int, row: array<int, string>}> $tagged
	 * @return array<int, array<int, string>|array{sheet_row: int, row: array<int, string>}>
	 */
	private static function unwrap_sorted_rows( array $tagged, int $start_row_1based ): array {
		if ( $start_row_1based <= 0 ) {
			return array_map(
				static fn( array $item ): array => $item['row'],
				$tagged
			);
		}
		return $tagged;
	}

	/**
	 * Consume and clear the last import/sync message (for admin logs).
	 */
	public static function consume_last_message(): ?string {
		$msg               = self::$last_message;
		self::$last_message = null;
		return $msg;
	}

	/**
	 * Release in-memory variation indexes (call at end of import batches).
	 */
	public static function clear_variation_lookup_cache(): void {
		self::$variation_index_cache      = array();
		self::$duplicate_variation_logged = array();
	}

	/**
	 * @param array<int, string>              $row  Raw sheet row.
	 * @param array<string, array<string,mixed>> $maps Field maps.
	 * @return array<string, string>
	 */
	public static function extract_row_data( array $row, array $maps ): array {
		$data = array();
		foreach ( $maps as $field => $info ) {
			$idx = SheetSync_Field_Mapper::col_to_index( $info['sheet_column'] );
			$val = $row[ $idx ] ?? '';
			if ( $val !== '' && $val !== null ) {
				$data[ $field ] = (string) $val;
			}
		}
		return $data;
	}

	/**
	 * Create path for import when update() returned skipped (variable parent or variation).
	 *
	 * @param array<int, string>              $row  Raw sheet row.
	 * @param array<string, array<string,mixed>> $maps Field maps.
	 * @return string created|error|skipped
	 */
	public static function create_from_row_static( array $row, array $maps ): string {
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return 'skipped';
		}

		$updater = new SheetSync_Product_Updater( $maps );
		$data    = $updater->extract_data( $row );
		$sync    = new self();

		if ( self::is_variation_row( $data ) ) {
			$result = $sync->sync_variation( $data, $maps, $row );
			return 'updated' === $result ? 'created' : $result;
		}

		if ( self::is_variable_parent_row( $data ) ) {
			$result = $sync->sync_variable_parent( $data, $maps );
			return 'updated' === $result ? 'created' : $result;
		}

		return 'skipped';
	}

	/**
	 * Apply parent `variation_attrs` when creating a variable product via bulk/import helpers.
	 */
	public static function apply_parent_definitions_for_product( WC_Product_Variable $parent, string $raw_attrs ): void {
		$tmp = new self();
		$def = $tmp->parse_parent_attribute_definitions( $raw_attrs );
		if ( ! empty( $def ) ) {
			$tmp->merge_parent_variable_attributes( $parent, $def );
		}
	}

	/**
	 * Export value for parent_sku column.
	 */
	public static function export_parent_sku( WC_Product $product ): string {
		if ( ! $product instanceof WC_Product_Variation ) {
			return '';
		}
		$parent = wc_get_product( $product->get_parent_id() );
		return $parent ? (string) $parent->get_sku() : '';
	}

	/**
	 * Export variation_attrs for sheet round-trip.
	 */
	public static function export_variation_attrs( WC_Product $product ): string {
		if ( $product instanceof WC_Product_Variable ) {
			return self::format_parent_attribute_string( $product );
		}
		if ( $product instanceof WC_Product_Variation ) {
			return self::format_variation_attribute_string( $product );
		}
		return '';
	}

	/**
	 * @param WC_Product_Variable $parent Variable parent product.
	 */
	public static function format_parent_attribute_string( WC_Product_Variable $parent ): string {
		$segments = array();
		foreach ( $parent->get_attributes() as $attr_key => $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$key = (string) $attr_key;
			$opts = $attribute->get_options();
			if ( empty( $opts ) ) {
				continue;
			}
			$slugs = array();
			foreach ( $opts as $opt ) {
				if ( 0 === strpos( $key, 'pa_' ) ) {
					$term = is_numeric( $opt ) ? get_term( (int) $opt, $key ) : get_term_by( 'slug', (string) $opt, $key );
					$slugs[] = ( $term && ! is_wp_error( $term ) ) ? $term->slug : sanitize_title( (string) $opt );
				} else {
					$slugs[] = sanitize_title( (string) $opt );
				}
			}
			$slugs = array_values( array_unique( array_filter( $slugs ) ) );
			if ( ! empty( $slugs ) ) {
				$segments[] = $key . ':' . implode( ',', $slugs );
			}
		}
		return implode( '|', $segments );
	}

	/**
	 * @param WC_Product_Variation $variation Variation product.
	 */
	public static function format_variation_attribute_string( WC_Product_Variation $variation ): string {
		$segments = array();
		foreach ( $variation->get_attributes() as $attr_key => $slug ) {
			$key = (string) $attr_key;
			$val = sanitize_title( (string) $slug );
			if ( $key === '' || $val === '' ) {
				continue;
			}
			$segments[] = $key . ':' . $val;
		}
		return implode( '|', $segments );
	}

	/**
	 * Human-readable variation line for the sheet (not used on import).
	 */
	public static function export_variation_human_summary( WC_Product_Variation $variation ): string {
		$parts = array();
		foreach ( $variation->get_attributes() as $attr_key => $slug ) {
			$key = (string) $attr_key;
			$val = (string) $slug;
			if ( $key === '' || $val === '' ) {
				continue;
			}
			$label   = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $key ) : $key;
			$display = $val;
			if ( 0 === strpos( $key, 'pa_' ) ) {
				$term = get_term_by( 'slug', sanitize_title( $val ), $key );
				if ( $term && ! is_wp_error( $term ) ) {
					$display = function_exists( 'sheetsync_decode_sheet_text' )
						? sheetsync_decode_sheet_text( $term->name )
						: $term->name;
				}
			} else {
				$display = function_exists( 'sheetsync_decode_sheet_text' )
					? sheetsync_decode_sheet_text( $val )
					: $val;
			}
			$parts[] = $label . ': ' . $display;
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Sync parent min/max prices after variation changes.
	 */
	public static function sync_parent_price_range( WC_Product_Variable $parent ): void {
		if ( class_exists( 'WC_Product_Variable', false ) && $parent->get_id() > 0 ) {
			WC_Product_Variable::sync( $parent->get_id() );
		}
	}

	/**
	 * @param string|null            $handled Prior handler result.
	 * @param array<string, string>  $data    Mapped data.
	 * @param array<int, string>     $row     Raw row.
	 * @param array<string, mixed>   $maps    Field maps.
	 * @return string|null
	 */
	public function maybe_handle_variable_parent( $handled, array $data, array $row, array $maps ) {
		unset( $row );
		if ( is_string( $handled ) && in_array( $handled, array( 'updated', 'skipped', 'queued', 'error' ), true ) ) {
			return $handled;
		}
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return null;
		}
		if ( ! self::is_variable_parent_row( $data ) ) {
			return null;
		}

		return $this->sync_variable_parent( $data, $maps, $row );
	}

	/**
	 * @param string|null            $handled Prior handler result.
	 * @param array<string, string>  $data    Mapped data.
	 * @param array<int, string>     $row     Raw row.
	 * @param array<string, mixed>   $maps    Field maps.
	 * @return string|null
	 */
	public function maybe_handle_variation( $handled, array $data, array $row, array $maps, int $sheet_row = 0 ) {
		if ( is_string( $handled ) && in_array( $handled, array( 'updated', 'skipped', 'queued', 'error' ), true ) ) {
			return $handled;
		}
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return null;
		}
		if ( ! self::is_variation_row( $data ) ) {
			return null;
		}

		return $this->sync_variation( $data, $maps, $row, $sheet_row );
	}

	/**
	 * Create or update a variable parent from a sheet row.
	 *
	 * @param array<string, string>              $data Mapped values.
	 * @param array<string, array<string,mixed>> $maps Field maps.
	 */
	public function sync_variable_parent( array $data, array $maps, array $row = array() ): string {
		$sku   = sanitize_text_field( $data['_sku'] ?? '' );
		$title = sanitize_text_field( $data['post_title'] ?? '' );
		if ( '' === $sku && '' === $title ) {
			self::set_message( __( 'Variable parent skipped: SKU or title required.', 'sheetsync-for-woocommerce' ) );
			return 'skipped';
		}

		if ( $sku !== ''
			&& class_exists( 'SheetSync_Product_Map_Repository', false )
			&& SheetSync_Product_Map_Repository::sku_is_ambiguous_in_catalog( $sku ) ) {
			$msg = sprintf(
				/* translators: %s: SKU */
				__( 'Variable parent skipped: SKU "%s" matches multiple products — map Product ID.', 'sheetsync-for-woocommerce' ),
				$sku
			);
			self::set_message( $msg );
			SheetSync_Logger::error( 'SheetSync: ' . $msg );
			return 'error';
		}

		$product = $this->resolve_product( $sku, $title, $maps );
		if ( $product && ! $product instanceof WC_Product_Variable ) {
			$upgraded = $this->maybe_upgrade_simple_to_variable( $product, $sku ?: $title );
			if ( $upgraded instanceof WC_Product_Variable ) {
				$product = $upgraded;
			} else {
				$msg = sprintf(
					/* translators: %s: SKU or title */
					__( 'SKU/title exists but product is not variable: %s', 'sheetsync-for-woocommerce' ),
					$sku ?: $title
				);
				self::set_message( $msg );
				SheetSync_Logger::error( 'SheetSync: ' . $msg );
				return 'error';
			}
		}

		$is_new = false;
		if ( ! $product ) {
			$product = new WC_Product_Variable();
			$is_new  = true;
		}

		$raw_attrs = (string) ( $data['variation_attrs'] ?? '' );
		$def_map   = $this->parse_parent_attribute_definitions( $raw_attrs );
		if ( $raw_attrs !== '' && empty( $def_map ) ) {
			self::set_message(
				sprintf(
					/* translators: %s: SKU or title */
					__( 'Invalid variation attributes on variable parent: %s', 'sheetsync-for-woocommerce' ),
					$sku ?: $title
				)
			);
			SheetSync_Logger::error( 'SheetSync: ' . self::$last_message );
		}
		if ( ! empty( $def_map ) ) {
			$this->merge_parent_variable_attributes( $product, $def_map );
		}

		$apply = $this->strip_variable_only_keys( $data );
		if ( class_exists( 'SheetSync_Field_Mapper', false ) ) {
			$apply = SheetSync_Field_Mapper::strip_non_importable_fields( $apply, true );
		}
		try {
			$updater    = new SheetSync_Product_Updater( $maps );
			$image_data = array();
			foreach ( array( '_product_image', '_gallery_images' ) as $img_field ) {
				if ( isset( $apply[ $img_field ] ) && $apply[ $img_field ] !== '' ) {
					$image_data[ $img_field ] = $apply[ $img_field ];
					unset( $apply[ $img_field ] );
				}
			}
			$updater->apply_updates( $product, $apply, SheetSync_Import_Rules::fields_to_clear_in_wc( $updater->empty_mapped_fields( $row ) ) );

			if ( $is_new && empty( $data['post_status'] ) ) {
				$product->set_status( 'publish' );
			}
			if ( $is_new && '' === $product->get_name() ) {
				$product->set_name( $title !== '' ? $title : ( $sku !== '' ? $sku : __( 'Variable product', 'sheetsync-for-woocommerce' ) ) );
			}

			SheetSync_Product_Updater::flag_internal_update( true );
			$product->save();
			if ( ! empty( $image_data ) ) {
				$updater->apply_images_to_product( $product, $image_data );
			}
			self::sync_parent_price_range( $product );
			SheetSync_Product_Updater::flag_internal_update( false );
			return 'updated';
		} catch ( Exception $e ) {
			self::set_message( 'Variable parent sync: ' . $e->getMessage() );
			SheetSync_Logger::error( self::$last_message );
			return 'error';
		}
	}

	/**
	 * @param array<string, string>              $data Mapped values.
	 * @param array<string, array<string,mixed>> $maps Field maps.
	 * @param array<int, string>                 $raw_row Raw sheet row for retries.
	 * @param int                                $sheet_row 1-based sheet row number.
	 */
	public function sync_variation( array $data, array $maps = array(), array $raw_row = array(), int $sheet_row = 0 ): string {
		$parent_sku = sanitize_text_field( $data['parent_sku'] ?? '' );
		$conn_id    = class_exists( 'SheetSync_Import_Row_Service', false )
			? SheetSync_Import_Row_Service::get_current_connection_id()
			: 0;

		if ( $parent_sku !== ''
			&& class_exists( 'SheetSync_Product_Map_Repository', false )
			&& SheetSync_Product_Map_Repository::sku_is_ambiguous_in_catalog( $parent_sku ) ) {
			$msg = sprintf(
				/* translators: %s: parent SKU */
				__( 'Variation skipped: parent SKU "%s" matches multiple products — map Product ID.', 'sheetsync-for-woocommerce' ),
				$parent_sku
			);
			self::set_message( $msg );
			SheetSync_Logger::log( $conn_id > 0 ? $conn_id : null, 'import', 'error', 0, 0, 'SheetSync: ' . $msg, 1 );
			return 'error';
		}

		$parent_id = class_exists( 'SheetSync_Product_Resolver', false )
			? SheetSync_Product_Resolver::resolve_parent_id_for_variation( $conn_id, $parent_sku, $data )
			: (int) wc_get_product_id_by_sku( $parent_sku );

		if ( ! $parent_id ) {
			$msg = sprintf(
				/* translators: %s: parent SKU */
				__( 'Variation skipped: parent SKU "%s" not found. Import parent row first.', 'sheetsync-for-woocommerce' ),
				$parent_sku
			);
			self::set_message( $msg );
			if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
				$conn_id = SheetSync_Import_Row_Service::get_current_connection_id();
				$queued  = false;
				if ( $conn_id > 0 && ! empty( $raw_row ) ) {
					SheetSync_Import_Row_Service::queue_variation_row( $conn_id, $raw_row, $data, $sheet_row );
					$queued = true;
				}
				SheetSync_Logger::log( $conn_id > 0 ? $conn_id : null, 'import', 'partial', 0, 1, 'SheetSync: ' . $msg );
				if ( $queued ) {
					return 'queued';
				}
			} else {
				SheetSync_Logger::error( 'SheetSync: ' . $msg );
			}
			return 'error';
		}

		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof WC_Product_Variable ) {
			$msg = sprintf(
				/* translators: %s: parent SKU */
				__( 'Variation skipped: parent "%s" is not a variable product.', 'sheetsync-for-woocommerce' ),
				$parent_sku
			);
			self::set_message( $msg );
			$conn_id = class_exists( 'SheetSync_Import_Row_Service', false )
				? SheetSync_Import_Row_Service::get_current_connection_id()
				: 0;
			SheetSync_Logger::log( $conn_id > 0 ? $conn_id : null, 'import', 'error', 0, 0, 'SheetSync: ' . $msg, 1 );
			return 'error';
		}

		$attrs = $this->variation_attributes_from_row( $data );
		if ( empty( $attrs ) ) {
			$var_sku = sanitize_text_field( $data['_sku'] ?? '' );
			$msg     = sprintf(
				/* translators: 1: variation SKU, 2: parent SKU */
				__( 'Variation skipped: invalid or empty variation attributes (SKU: %1$s, parent: %2$s).', 'sheetsync-for-woocommerce' ),
				$var_sku ?: '—',
				$parent_sku
			);
			self::set_message( $msg );
			$conn_id = class_exists( 'SheetSync_Import_Row_Service', false )
				? SheetSync_Import_Row_Service::get_current_connection_id()
				: 0;
			SheetSync_Logger::log( $conn_id > 0 ? $conn_id : null, 'import', 'error', 0, 0, 'SheetSync: ' . $msg, 1 );
			return 'error';
		}

		try {
			SheetSync_Product_Updater::flag_internal_update( true );
			$this->merge_parent_variable_attributes( $parent, $this->variation_attrs_to_parent_definitions( $attrs ) );
			if ( ! empty( $parent->get_changes() ) ) {
				$parent->save();
			}

			$variation = $this->find_or_create_variation(
				$parent,
				$attrs,
				sanitize_text_field( $data['_sku'] ?? '' )
			);

			if ( isset( $data['_regular_price'] ) ) {
				$regular = function_exists( 'sheetsync_normalize_sheet_price' )
					? sheetsync_normalize_sheet_price( (string) $data['_regular_price'] )
					: wc_format_decimal( $data['_regular_price'] );
				if ( $regular !== '' && is_numeric( $regular ) ) {
					$variation->set_regular_price( $regular );
					if ( '' === $variation->get_sale_price() ) {
						$variation->set_price( $regular );
					}
				}
			}
			if ( isset( $data['_sale_price'] ) ) {
				$sale = function_exists( 'sheetsync_normalize_sheet_price' )
					? sheetsync_normalize_sheet_price( (string) $data['_sale_price'] )
					: wc_format_decimal( $data['_sale_price'] );
				if ( $sale !== '' && is_numeric( $sale ) ) {
					$variation->set_sale_price( $sale );
				}
			}
			if ( isset( $data['_stock'] ) ) {
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( (int) $data['_stock'] );
				if ( ! isset( $maps['_stock_status'] ) ) {
					$variation->set_stock_status( (int) $data['_stock'] > 0 ? 'instock' : 'outofstock' );
				}
			}
			if ( isset( $data['_sku'] ) ) {
				$var_sku = sanitize_text_field( $data['_sku'] );
				if ( $var_sku !== '' && strcasecmp( $var_sku, $parent_sku ) === 0 ) {
					$var_sku = '';
				}
				if ( $var_sku !== '' ) {
					$conflict_id = (int) wc_get_product_id_by_sku( $var_sku );
					if ( $conflict_id > 0 && $conflict_id !== (int) $variation->get_id() ) {
						$conflict = wc_get_product( $conflict_id );
						// Parent variable product legitimately owns this SKU — leave variation without SKU.
						if ( $conflict instanceof WC_Product_Variable && (int) $conflict->get_id() === (int) $parent->get_id() ) {
							$var_sku = '';
						} else {
						$msg      = sprintf(
							/* translators: 1: SKU, 2: product ID, 3: product name */
							__( 'Variation SKU "%1$s" already used by product #%2$d (%3$s). Delete that product or change the SKU, then sync again.', 'sheetsync-for-woocommerce' ),
							$var_sku,
							$conflict_id,
							$conflict ? $conflict->get_name() : '—'
						);
						self::set_message( $msg );
						SheetSync_Logger::error( 'SheetSync: ' . $msg );
						SheetSync_Product_Updater::flag_internal_update( false );
						return 'error';
						}
					}
					if ( $var_sku !== '' ) {
						$variation->set_sku( $var_sku );
					}
				}
			}

			if ( ! empty( $maps ) ) {
				$apply      = $this->strip_variable_only_keys( $data );
				$up         = new SheetSync_Product_Updater( $maps );
				$image_data = array();
				foreach ( array( '_product_image', '_gallery_images' ) as $img_field ) {
					if ( isset( $apply[ $img_field ] ) && $apply[ $img_field ] !== '' ) {
						$image_data[ $img_field ] = $apply[ $img_field ];
						unset( $apply[ $img_field ] );
					}
				}
				$up->apply_updates(
					$variation,
					$apply,
					class_exists( 'SheetSync_Import_Rules', false )
						? SheetSync_Import_Rules::fields_to_clear_in_wc( $up->empty_mapped_fields( $row ) )
						: array()
				);
				SheetSync_Product_Updater::flag_internal_update( true );
				$variation->save();
				if ( ! empty( $image_data ) ) {
					$up->apply_images_to_product( $variation, $image_data );
				}
			} else {
				SheetSync_Product_Updater::flag_internal_update( true );
				$variation->save();
			}

			$parent->save();
			self::sync_parent_price_range( $parent );
			SheetSync_Product_Updater::flag_internal_update( false );

			$conn_id = class_exists( 'SheetSync_Import_Row_Service', false )
				? SheetSync_Import_Row_Service::get_current_connection_id()
				: 0;
			if ( $conn_id > 0 && $sheet_row > 0 && class_exists( 'SheetSync_Import_Row_Service', false ) ) {
				SheetSync_Import_Row_Service::persist_import_row_map( $conn_id, $maps, $raw_row, $data, $sheet_row );
			}

			return 'updated';
		} catch ( Exception $e ) {
			self::set_message( 'Variation sync error: ' . $e->getMessage() );
			SheetSync_Logger::error( self::$last_message );
			return 'error';
		}
	}

	private static function set_message( string $message ): void {
		self::$last_message = $message;
	}

	/**
	 * @return array<string, list<string>> taxonomy or custom slug => list of option slugs
	 */
	private function variation_attrs_to_parent_definitions( array $single_attrs ): array {
		$out = array();
		foreach ( $single_attrs as $key => $slug ) {
			$k         = (string) $key;
			$out[ $k ] = array( (string) $slug );
		}
		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private function strip_variable_only_keys( array $data ): array {
		foreach ( array( 'parent_sku', 'variation_attrs', '_product_type' ) as $k ) {
			unset( $data[ $k ] );
		}
		return $data;
	}

	/**
	 * Convert a simple product to variable when the sheet declares a variable parent row.
	 */
	private function maybe_upgrade_simple_to_variable( WC_Product $product, string $label ): ?WC_Product_Variable {
		if ( ! $product instanceof WC_Product_Simple ) {
			return null;
		}
		$id = $product->get_id();
		if ( $id < 1 ) {
			return null;
		}
		if ( ! (bool) apply_filters( 'sheetsync_allow_simple_to_variable_upgrade', true, $product ) ) {
			return null;
		}
		if ( count( $product->get_children() ) > 0 ) {
			return null;
		}

		wp_set_object_terms( $id, 'variable', 'product_type', false );
		wc_delete_product_transients( $id );
		clean_post_cache( $id );

		$variable = wc_get_product( $id );
		if ( ! $variable instanceof WC_Product_Variable ) {
			return null;
		}

		SheetSync_Logger::log(
			class_exists( 'SheetSync_Import_Row_Service', false )
				? SheetSync_Import_Row_Service::get_current_connection_id()
				: null,
			'import',
			'success',
			1,
			0,
			sprintf(
				/* translators: %s: SKU or title */
				__( 'Upgraded simple product to variable for sheet import: %s', 'sheetsync-for-woocommerce' ),
				$label
			)
		);

		return $variable;
	}

	private function resolve_product( string $sku, string $title, array $maps, int $sheet_row = 0 ): ?WC_Product {
		$conn_id = class_exists( 'SheetSync_Import_Row_Service', false )
			? SheetSync_Import_Row_Service::get_current_connection_id()
			: 0;

		$data = array();
		if ( $sku !== '' ) {
			$data['_sku'] = $sku;
		}
		if ( $title !== '' ) {
			$data['post_title'] = $title;
		}

		if ( $conn_id > 0 && class_exists( 'SheetSync_Product_Resolver', false ) ) {
			return SheetSync_Product_Resolver::resolve_for_import( $conn_id, $data, $maps, $sheet_row, null );
		}

		if ( $sku !== '' ) {
			if ( class_exists( 'SheetSync_Product_Map_Repository', false )
				&& SheetSync_Product_Map_Repository::sku_is_ambiguous_in_catalog( $sku ) ) {
				return null;
			}
			$id = wc_get_product_id_by_sku( $sku );
			if ( $id ) {
				$p = wc_get_product( $id );
				return $p instanceof WC_Product ? $p : null;
			}
		}
		if ( $title !== '' ) {
			$posts = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'title'          => $title,
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			if ( ! empty( $posts ) ) {
				$p = wc_get_product( (int) $posts[0] );
				return $p instanceof WC_Product ? $p : null;
			}
		}
		return null;
	}

	/**
	 * Ensure global attribute taxonomy exists (e.g. pa_color).
	 */
	private function ensure_pa_taxonomy( string $pa_key ): bool {
		if ( class_exists( 'SheetSync_Attribute_Bootstrap', false ) ) {
			return SheetSync_Attribute_Bootstrap::ensure_global_attribute( $pa_key );
		}
		if ( taxonomy_exists( $pa_key ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Parent definitions: `pa_color:red,blue|Size:xl` (comma = multiple option slugs; Size = custom attr slug).
	 *
	 * @return array<string, list<string>> Key = `pa_*` or custom slug (no pa_ prefix).
	 */
	private function parse_parent_attribute_definitions( string $raw ): array {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( '|', $raw ) ) ) as $segment ) {
			$parts = explode( ':', $segment, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$name = trim( $parts[0] );
			$vals = array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', $parts[1] ) ) ) );
			if ( $name === '' || empty( $vals ) ) {
				continue;
			}
			if ( 0 === stripos( $name, 'pa_' ) ) {
				$key = sanitize_key( $name );
				if ( ! $this->ensure_pa_taxonomy( $key ) ) {
					SheetSync_Logger::error(
						sprintf(
							/* translators: %s: attribute taxonomy */
							__( 'Could not register attribute taxonomy: %s', 'sheetsync-for-woocommerce' ),
							$key
						)
					);
					continue;
				}
			} else {
				$key = sanitize_title( $name );
			}
			if ( $key === '' ) {
				continue;
			}
			$out[ $key ] = array_values( array_unique( array_merge( $out[ $key ] ?? array(), $vals ) ) );
		}
		return $out;
	}

	/**
	 * Single-value attributes for a variation row (variation_attrs + Color/Size columns).
	 *
	 * @param array<string, string> $data
	 * @return array<string, string> attr key => slug
	 */
	private function variation_attributes_from_row( array $data ): array {
		$raw = '';
		if ( class_exists( 'SheetSync_Attribute_Bootstrap', false ) ) {
			$raw = SheetSync_Attribute_Bootstrap::variation_attrs_for_row( $data );
		}
		if ( $raw === '' ) {
			$raw = trim( (string) ( $data['variation_attrs'] ?? '' ) );
		}
		return $this->parse_variation_row_attributes( $raw );
	}

	/**
	 * Single-value attributes for a variation row.
	 *
	 * @return array<string, string> attr key => slug
	 */
	private function parse_variation_row_attributes( string $raw ): array {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( '|', $raw ) ) ) as $segment ) {
			$parts = explode( ':', $segment, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$name = trim( $parts[0] );
			$val  = sanitize_title( trim( $parts[1] ) );
			if ( $name === '' || $val === '' ) {
				continue;
			}
			if ( 0 === stripos( $name, 'pa_' ) ) {
				$key = sanitize_key( $name );
				if ( ! $this->ensure_pa_taxonomy( $key ) ) {
					continue;
				}
			} else {
				$key = sanitize_title( $name );
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	/**
	 * @param array<string, list<string>> $definitions Attribute key => option slugs.
	 */
	private function merge_parent_variable_attributes( WC_Product_Variable $parent, array $definitions ): void {
		if ( empty( $definitions ) ) {
			return;
		}

		$existing = $parent->get_attributes();
		$changed  = false;

		foreach ( $definitions as $attr_key => $slugs ) {
			$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', $slugs ) ) ) );
			if ( empty( $slugs ) ) {
				continue;
			}

			if ( 0 === strpos( $attr_key, 'pa_' ) ) {
				$this->ensure_pa_taxonomy( $attr_key );
				foreach ( $slugs as $slug ) {
					if ( ! term_exists( (string) $slug, $attr_key ) ) {
						wp_insert_term(
							(string) $slug,
							$attr_key,
							array( 'slug' => (string) $slug )
						);
					}
				}
			}

			$attribute = isset( $existing[ $attr_key ] ) ? $existing[ $attr_key ] : new WC_Product_Attribute();
			if ( ! ( $attribute instanceof WC_Product_Attribute ) ) {
				$attribute = new WC_Product_Attribute();
			}

			if ( 0 === strpos( $attr_key, 'pa_' ) ) {
				$attr_id = function_exists( 'wc_attribute_taxonomy_id_by_name' )
					? (int) wc_attribute_taxonomy_id_by_name( str_replace( 'pa_', '', $attr_key ) )
					: 0;
				$attribute->set_id( $attr_id );
				$attribute->set_name( $attr_key );
				$prev  = $attribute->get_options();
				$prev  = is_array( $prev ) ? $prev : array();
				$merge = array_values( array_unique( array_merge( $prev, $slugs ) ) );
				$attribute->set_options( $merge );
			} else {
				$attribute->set_id( 0 );
				$attribute->set_name( $attr_key );
				$prev  = $attribute->get_options();
				$prev  = is_array( $prev ) ? $prev : array();
				$merge = array_values( array_unique( array_merge( $prev, $slugs ) ) );
				$attribute->set_options( $merge );
			}

			$attribute->set_variation( true );
			$attribute->set_visible( true );
			$existing[ $attr_key ] = $attribute;
			$changed               = true;
		}

		if ( $changed ) {
			$parent->set_attributes( $existing );
		}
	}

	/**
	 * @param array<string, string> $attrs variation get_attributes shape.
	 */
	private function find_or_create_variation( WC_Product_Variable $parent, array $attrs, string $preferred_sku = '' ): WC_Product_Variation {
		$target_key = $this->attr_set_cache_key( $this->normalize_attr_set( $attrs ) );
		$parent_id  = (int) $parent->get_id();

		$existing = $this->resolve_variation_for_attr_key( $parent, $target_key, $preferred_sku );
		if ( $existing ) {
			if ( ! isset( self::$variation_index_cache[ $parent_id ] ) ) {
				self::$variation_index_cache[ $parent_id ] = array();
			}
			self::$variation_index_cache[ $parent_id ][ $target_key ] = $existing;
			return $existing;
		}

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( $attrs );
		$variation->save();

		if ( ! isset( self::$variation_index_cache[ $parent_id ] ) ) {
			self::$variation_index_cache[ $parent_id ] = array();
		}
		self::$variation_index_cache[ $parent_id ][ $target_key ] = $variation;

		return $variation;
	}

	/**
	 * Find the best variation for a normalized attribute key (handles duplicate attribute sets).
	 */
	private function resolve_variation_for_attr_key(
		WC_Product_Variable $parent,
		string $target_key,
		string $preferred_sku = ''
	): ?WC_Product_Variation {
		$best    = null;
		$matches = 0;

		foreach ( $parent->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$key = $this->attr_set_cache_key( $this->normalize_attr_set( $variation->get_attributes() ) );
			if ( $key !== $target_key ) {
				continue;
			}

			++$matches;
			$best = $best
				? $this->select_preferred_variation( $best, $variation, $preferred_sku )
				: $variation;
		}

		if ( $matches > 1 ) {
			$this->log_duplicate_variation_attributes( $parent, $matches - 1 );
		}

		return $best;
	}

	/**
	 * When WooCommerce has multiple variations with identical attributes, pick the best match.
	 */
	private function select_preferred_variation(
		WC_Product_Variation $current,
		WC_Product_Variation $candidate,
		string $preferred_sku = ''
	): WC_Product_Variation {
		$preferred_sku = trim( $preferred_sku );

		if ( $preferred_sku !== '' ) {
			$current_sku   = (string) $current->get_sku();
			$candidate_sku = (string) $candidate->get_sku();
			if ( $candidate_sku === $preferred_sku && $current_sku !== $preferred_sku ) {
				return $candidate;
			}
			if ( $current_sku === $preferred_sku && $candidate_sku !== $preferred_sku ) {
				return $current;
			}
		}

		$current_published   = 'publish' === $current->get_status();
		$candidate_published = 'publish' === $candidate->get_status();
		if ( $candidate_published && ! $current_published ) {
			return $candidate;
		}
		if ( $current_published && ! $candidate_published ) {
			return $current;
		}

		return (int) $candidate->get_id() > (int) $current->get_id() ? $candidate : $current;
	}

	private function log_duplicate_variation_attributes( WC_Product_Variable $parent, int $duplicate_count ): void {
		$parent_id = (int) $parent->get_id();
		if ( isset( self::$duplicate_variation_logged[ $parent_id ] ) ) {
			return;
		}
		self::$duplicate_variation_logged[ $parent_id ] = true;

		$conn_id = class_exists( 'SheetSync_Import_Row_Service', false )
			? SheetSync_Import_Row_Service::get_current_connection_id()
			: 0;

		$msg = sprintf(
			/* translators: 1: parent product name, 2: parent ID, 3: duplicate attribute sets */
			__(
				'Variable product "%1$s" (#%2$d) has %3$d duplicate variation attribute set(s). SheetSync is using the published / SKU-matched / newest variation.',
				'sheetsync-for-woocommerce'
			),
			$parent->get_name(),
			$parent_id,
			$duplicate_count
		);

		SheetSync_Logger::log( $conn_id > 0 ? $conn_id : null, 'import', 'partial', 0, 0, 'SheetSync: ' . $msg );
	}

	/**
	 * @param array<string, string> $attrs Normalized attribute set (keys lowercased, values sanitized).
	 */
	private function attr_set_cache_key( array $attrs ): string {
		$encoded = wp_json_encode( $attrs );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @param array<string, string> $attrs Raw attributes.
	 * @return array<string, string>
	 */
	private function normalize_attr_set( array $attrs ): array {
		$out = array();
		foreach ( $attrs as $key => $val ) {
			$out[ strtolower( (string) $key ) ] = sanitize_title( (string) $val );
		}
		ksort( $out );
		return $out;
	}
}
