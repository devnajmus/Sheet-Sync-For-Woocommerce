<?php
/**
 * Shared Sheet → WooCommerce import helpers (skip rules, product resolve, retries, logging).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Import_Row_Service' ) ) :

class SheetSync_Import_Row_Service {

	/** @var array<int, array<string, int>> */
	private static array $seen_skus = array();

	private static int $current_connection_id = 0;

	private static int $batch_depth = 0;

	public static function set_current_connection_id( int $connection_id ): void {
		self::$current_connection_id = max( 0, $connection_id );
	}

	public static function get_current_connection_id(): int {
		return self::$current_connection_id;
	}

	/** @var array<int, array<string, int>> */
	private static array $seen_titles = array();

	/** @var array<int, array<string, int>> parent-scoped variation dedupe keys => first sheet row */
	private static array $seen_variation_rows = array();

	/** @var array<int, array<int, string>> product_id => raw child SKU list */
	private static array $grouped_link_retries = array();

	/** @var array<int, array<int, array{row: array<int, string>, data: array<string, string>}>> sheet_row => payload */
	private static array $grouped_parent_retries = array();

	/** @var array<string, true> Deduped import issue codes per sync run */
	private static array $logged_import_issue_keys = array();

	private static function import_state_transient_key( int $connection_id ): string {
		return 'sheetsync_import_seen_' . max( 0, $connection_id );
	}

	private static function grouped_retry_transient_key( int $connection_id ): string {
		return 'sheetsync_grouped_retry_' . max( 0, $connection_id );
	}

	private static function grouped_parent_retry_transient_key( int $connection_id ): string {
		return 'sheetsync_grouped_parent_retry_' . max( 0, $connection_id );
	}

	private static function load_grouped_retry_queue( int $connection_id ): array {
		if ( isset( self::$grouped_link_retries[ $connection_id ] ) ) {
			return self::$grouped_link_retries[ $connection_id ];
		}
		$stored = get_transient( self::grouped_retry_transient_key( $connection_id ) );
		if ( ! is_array( $stored ) ) {
			self::$grouped_link_retries[ $connection_id ] = array();
			return array();
		}
		$queue = array();
		foreach ( $stored as $product_id => $raw ) {
			$product_id = absint( $product_id );
			$raw        = is_string( $raw ) ? $raw : '';
			if ( $product_id > 0 && $raw !== '' ) {
				$queue[ $product_id ] = $raw;
			}
		}
		self::$grouped_link_retries[ $connection_id ] = $queue;
		return $queue;
	}

	private static function flush_grouped_retry_queue( int $connection_id ): void {
		if ( $connection_id <= 0 ) {
			return;
		}
		$queue = self::$grouped_link_retries[ $connection_id ] ?? array();
		if ( empty( $queue ) ) {
			delete_transient( self::grouped_retry_transient_key( $connection_id ) );
			return;
		}
		set_transient( self::grouped_retry_transient_key( $connection_id ), $queue, DAY_IN_SECONDS );
	}

	private static function load_grouped_parent_retry_queue( int $connection_id ): array {
		if ( isset( self::$grouped_parent_retries[ $connection_id ] ) ) {
			return self::$grouped_parent_retries[ $connection_id ];
		}
		$stored = get_transient( self::grouped_parent_retry_transient_key( $connection_id ) );
		if ( ! is_array( $stored ) ) {
			self::$grouped_parent_retries[ $connection_id ] = array();
			return array();
		}
		$queue = array();
		foreach ( $stored as $sheet_row => $payload ) {
			$sheet_row = absint( $sheet_row );
			if ( $sheet_row <= 0 || ! is_array( $payload ) ) {
				continue;
			}
			$row  = is_array( $payload['row'] ?? null ) ? $payload['row'] : array();
			$data = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
			if ( empty( $row ) || empty( $data ) ) {
				continue;
			}
			$queue[ $sheet_row ] = array(
				'row'  => array_map( 'strval', $row ),
				'data' => array_map( 'strval', $data ),
			);
		}
		self::$grouped_parent_retries[ $connection_id ] = $queue;
		return $queue;
	}

	private static function flush_grouped_parent_retry_queue( int $connection_id ): void {
		if ( $connection_id <= 0 ) {
			return;
		}
		$queue = self::$grouped_parent_retries[ $connection_id ] ?? array();
		if ( empty( $queue ) ) {
			delete_transient( self::grouped_parent_retry_transient_key( $connection_id ) );
			return;
		}
		set_transient( self::grouped_parent_retry_transient_key( $connection_id ), $queue, DAY_IN_SECONDS );
	}

	/**
	 * Restore duplicate SKU/title tracking from a multi-batch import (AJAX / job pull).
	 */
	private static function load_persisted_duplicate_state( int $connection_id ): void {
		$stored = get_transient( self::import_state_transient_key( $connection_id ) );
		if ( ! is_array( $stored ) ) {
			return;
		}
		$skus   = $stored['skus'] ?? array();
		$titles = $stored['titles'] ?? array();
		if ( is_array( $skus ) ) {
			self::$seen_skus[ $connection_id ] = array_map( 'absint', $skus );
		}
		if ( is_array( $titles ) ) {
			self::$seen_titles[ $connection_id ] = array_map( 'absint', $titles );
		}
		$variations = $stored['variations'] ?? array();
		if ( is_array( $variations ) ) {
			self::$seen_variation_rows[ $connection_id ] = array_map( 'absint', $variations );
		}
	}

	/**
	 * Persist duplicate tracking so later import batches honour "first row wins".
	 */
	public static function flush_import_run_state( int $connection_id ): void {
		if ( $connection_id <= 0 ) {
			return;
		}
		set_transient(
			self::import_state_transient_key( $connection_id ),
			array(
				'skus'       => self::$seen_skus[ $connection_id ] ?? array(),
				'titles'     => self::$seen_titles[ $connection_id ] ?? array(),
				'variations' => self::$seen_variation_rows[ $connection_id ] ?? array(),
			),
			DAY_IN_SECONDS
		);
	}

	public static function begin_import_run( int $connection_id ): void {
		self::set_current_connection_id( $connection_id );
		self::$seen_skus[ $connection_id ]            = array();
		self::$seen_titles[ $connection_id ]          = array();
		self::$seen_variation_rows[ $connection_id ]  = array();
		self::$grouped_link_retries[ $connection_id ] = array();
		self::$grouped_parent_retries[ $connection_id ] = array();
		self::$logged_import_issue_keys = array();
		self::clear_variation_retry_queue( $connection_id );
		delete_transient( self::import_state_transient_key( $connection_id ) );
		delete_transient( self::grouped_retry_transient_key( $connection_id ) );
		delete_transient( self::grouped_parent_retry_transient_key( $connection_id ) );
		if ( class_exists( 'SheetSync_Attribute_Bootstrap', false )
			&& SheetSync_Attribute_Bootstrap::auto_create_enabled() ) {
			$boot = SheetSync_Attribute_Bootstrap::bootstrap_connection( $connection_id );
			if ( ( $boot['attributes'] ?? 0 ) > 0 || ( $boot['terms'] ?? 0 ) > 0 ) {
				SheetSync_Logger::log(
					$connection_id,
					'import',
					'success',
					0,
					0,
					sprintf(
						/* translators: 1: attributes created, 2: terms created */
						__( 'Auto-created %1$d WooCommerce attribute(s) and %2$d term(s) from the sheet before import.', 'sheetsync-for-woocommerce' ),
						(int) ( $boot['attributes'] ?? 0 ),
						(int) ( $boot['terms'] ?? 0 )
					)
				);
			}
		}
		self::begin_batch();
	}

	/**
	 * Continue a multi-batch import without resetting duplicate SKU/title tracking.
	 */
	public static function continue_import_run( int $connection_id ): void {
		self::set_current_connection_id( $connection_id );
		if ( ! isset( self::$seen_skus[ $connection_id ] ) || ! isset( self::$seen_titles[ $connection_id ] ) ) {
			self::$seen_skus[ $connection_id ]   = array();
			self::$seen_titles[ $connection_id ] = array();
			self::load_persisted_duplicate_state( $connection_id );
		}
		self::load_grouped_retry_queue( $connection_id );
		self::load_grouped_parent_retry_queue( $connection_id );
	}

	public static function end_import_run( int $connection_id ): void {
		self::end_batch();
		if ( $connection_id > 0 ) {
			self::finalize_grouped_link_queue( $connection_id );
		}
		unset(
			self::$seen_skus[ $connection_id ],
			self::$seen_titles[ $connection_id ],
			self::$seen_variation_rows[ $connection_id ],
			self::$grouped_link_retries[ $connection_id ],
			self::$grouped_parent_retries[ $connection_id ]
		);
		if ( function_exists( 'sheetsync_release_bulk_import_session' ) ) {
			sheetsync_release_bulk_import_session( $connection_id );
		}
		delete_transient( self::import_state_transient_key( $connection_id ) );
		delete_transient( self::grouped_retry_transient_key( $connection_id ) );
		delete_transient( self::grouped_parent_retry_transient_key( $connection_id ) );
		if ( self::$current_connection_id === $connection_id ) {
			self::$current_connection_id = 0;
		}
		if ( $connection_id > 0 && class_exists( 'SheetSync_Media_Queue', false ) ) {
			SheetSync_Media_Queue::schedule_processing( $connection_id );
			if ( function_exists( 'sheetsync_media_queue_maybe_process_inline' ) ) {
				sheetsync_media_queue_maybe_process_inline( $connection_id );
			}
		}
	}

	public static function begin_batch(): void {
		if ( 0 === self::$batch_depth ) {
			wp_defer_term_counting( true );
		}
		++self::$batch_depth;
	}

	public static function end_batch(): void {
		self::$batch_depth = max( 0, self::$batch_depth - 1 );
		if ( 0 === self::$batch_depth ) {
			wp_defer_term_counting( false );
			if ( class_exists( 'SheetSync_Variation_Sync', false ) ) {
				SheetSync_Variation_Sync::clear_variation_lookup_cache();
			}
		}
	}

	/**
	 * Sheet is the source of truth (not two-way).
	 */
	public static function sheet_is_authoritative( object $conn ): bool {
		$direction = isset( $conn->sync_direction ) ? (string) $conn->sync_direction : 'sheets_to_wc';
		return 'sheets_to_wc' === $direction;
	}

	/**
	 * Skip duplicate SKU rows in the sheet (keep the first occurrence only).
	 */
	public static function is_later_duplicate_sheet_sku( int $connection_id, int $sheet_row, array $peek_data ): bool {
		if ( ! empty( $peek_data['parent_sku'] ) ) {
			return false;
		}
		$sku = sanitize_text_field( $peek_data['_sku'] ?? '' );
		if ( $sku === '' ) {
			return false;
		}
		if ( ! isset( self::$seen_skus[ $connection_id ] ) ) {
			self::$seen_skus[ $connection_id ] = array();
		}
		$first = self::$seen_skus[ $connection_id ][ $sku ] ?? 0;
		if ( 0 === $first ) {
			self::$seen_skus[ $connection_id ][ $sku ] = $sheet_row;
			self::flush_import_run_state( $connection_id );
			return false;
		}
		return $first !== $sheet_row;
	}

	/**
	 * Skip duplicate title rows when SKU is empty (title-only products).
	 */
	public static function is_later_duplicate_sheet_title( int $connection_id, int $sheet_row, array $peek_data ): bool {
		if ( ! empty( $peek_data['parent_sku'] ) || ! empty( $peek_data['_sku'] ) ) {
			return false;
		}
		$title = function_exists( 'sheetsync_normalize_sheet_title' )
			? sheetsync_normalize_sheet_title( (string) ( $peek_data['post_title'] ?? '' ) )
			: sanitize_text_field( $peek_data['post_title'] ?? '' );
		if ( $title === '' ) {
			return false;
		}
		$key = function_exists( 'sheetsync_title_match_key' )
			? sheetsync_title_match_key( $title )
			: strtolower( $title );
		if ( ! isset( self::$seen_titles[ $connection_id ] ) ) {
			self::$seen_titles[ $connection_id ] = array();
		}
		$first = self::$seen_titles[ $connection_id ][ $key ] ?? 0;
		if ( 0 === $first ) {
			self::$seen_titles[ $connection_id ][ $key ] = $sheet_row;
			self::flush_import_run_state( $connection_id );
			return false;
		}
		return $first !== $sheet_row;
	}

	/**
	 * Skip duplicate variation rows in the sheet (first row wins per parent + SKU or attrs).
	 *
	 * @param array<string, string> $peek_data
	 */
	public static function is_later_duplicate_variation_row( int $connection_id, int $sheet_row, array $peek_data ): bool {
		if ( empty( $peek_data['parent_sku'] ) ) {
			return false;
		}
		$key = self::variation_dedupe_key( $peek_data );
		if ( $key === '' ) {
			return false;
		}
		if ( ! isset( self::$seen_variation_rows[ $connection_id ] ) ) {
			self::$seen_variation_rows[ $connection_id ] = array();
		}
		$first = self::$seen_variation_rows[ $connection_id ][ $key ] ?? 0;
		if ( 0 === $first ) {
			self::$seen_variation_rows[ $connection_id ][ $key ] = $sheet_row;
			self::flush_import_run_state( $connection_id );
			return false;
		}
		return $first !== $sheet_row;
	}

	/**
	 * @param array<string, string> $peek_data
	 */
	private static function variation_dedupe_key( array $peek_data ): string {
		$parent = function_exists( 'sheetsync_normalize_sheet_sku' )
			? sheetsync_normalize_sheet_sku( (string) ( $peek_data['parent_sku'] ?? '' ) )
			: strtolower( trim( (string) ( $peek_data['parent_sku'] ?? '' ) ) );
		if ( $parent === '' ) {
			return '';
		}

		$attr_sig = self::variation_attribute_signature( $peek_data );
		if ( $attr_sig !== '' ) {
			return 'attr:' . $parent . '|' . $attr_sig;
		}

		$sku = sanitize_text_field( $peek_data['_sku'] ?? '' );
		if ( $sku !== '' && strcasecmp( $sku, $parent ) !== 0 ) {
			return 'sku:' . $parent . '|' . strtolower( $sku );
		}

		return '';
	}

	/**
	 * Canonical attribute fingerprint for variation dedupe (Color/Size or variation_attrs).
	 *
	 * @param array<string, string> $peek_data
	 */
	private static function variation_attribute_signature( array $peek_data ): string {
		$raw = '';
		if ( class_exists( 'SheetSync_Attribute_Bootstrap', false ) ) {
			$raw = SheetSync_Attribute_Bootstrap::variation_attrs_for_row( $peek_data );
		}
		if ( $raw === '' ) {
			$raw = trim( (string) ( $peek_data['variation_attrs'] ?? '' ) );
		}
		if ( $raw === '' ) {
			return '';
		}
		$parts = array();
		foreach ( array_filter( array_map( 'trim', explode( '|', $raw ) ) ) as $segment ) {
			if ( ! str_contains( $segment, ':' ) ) {
				continue;
			}
			list( $key, $val ) = array_pad( explode( ':', $segment, 2 ), 2, '' );
			$key = strtolower( sanitize_key( trim( $key ) ) );
			$val = strtolower( sanitize_title( trim( $val ) ) );
			if ( $key !== '' && $val !== '' ) {
				$parts[] = $key . '=' . $val;
			}
		}
		sort( $parts );
		return implode( ';', $parts );
	}

	/**
	 * Error message when duplicate SKUs in WooCommerce prevent safe import, or null when OK.
	 *
	 * @param array<string, string> $peek_data
	 */
	public static function ambiguous_sku_import_message( array $peek_data, int $connection_id = 0 ): ?string {
		if ( ! empty( $peek_data['parent_sku'] ) ) {
			return null;
		}
		$sku = sanitize_text_field( $peek_data['_sku'] ?? '' );
		if ( $sku === '' || ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return null;
		}
		if ( ! SheetSync_Product_Map_Repository::sku_is_ambiguous_in_catalog( $sku ) ) {
			return null;
		}
		if ( ! empty( $peek_data['product_id'] ) && absint( $peek_data['product_id'] ) > 0 ) {
			return null;
		}
		if ( $connection_id > 0 ) {
			$map_repo = new SheetSync_Product_Map_Repository();
			$map      = $map_repo->find_by_external_key( $connection_id, $sku );
			if ( $map && (int) $map->product_id > 0 ) {
				return null;
			}
		}
		if ( SheetSync_Product_Map_Repository::resolve_import_product_id( $peek_data, null, $connection_id ) > 0 ) {
			return null;
		}
		return sprintf(
			/* translators: %s: SKU value */
			__( 'Multiple WooCommerce products share SKU “%s” — add Product ID to the sheet or remove duplicate SKUs in WooCommerce before importing.', 'sheetsync-for-woocommerce' ),
			$sku
		);
	}

	/**
	 * True when the sheet row cannot be matched safely (ambiguous SKU in WooCommerce).
	 *
	 * @param array<string, string> $peek_data
	 */
	public static function is_ambiguous_sku_import( array $peek_data, int $connection_id = 0 ): bool {
		return null !== self::ambiguous_sku_import_message( $peek_data, $connection_id );
	}

	/**
	 * Sort import rows: variable parents → grouped child SKUs → other rows → variations.
	 *
	 * @param array<int, array{sheet_row: int, row: array<int, string>}> $tagged
	 * @return array<int, array{sheet_row: int, row: array<int, string>}>
	 */
	public static function prepare_tagged_rows_for_import(
		array $tagged,
		SheetSync_Product_Updater $updater,
		int $start_row_1based = 0
	): array {
		if ( empty( $tagged ) ) {
			return $tagged;
		}

		if ( class_exists( 'SheetSync_Variation_Sync', false ) ) {
			$plain = array_map(
				static fn( array $item ): array => $item['row'],
				$tagged
			);
			$sorted = SheetSync_Variation_Sync::sort_rows_parents_first( $plain, $updater, $start_row_1based );
			if ( $start_row_1based > 0 ) {
				$tagged = $sorted;
			} else {
				$row_to_sheet = array();
				foreach ( $tagged as $orig ) {
					$row_key = md5( implode( '|', array_map( 'strval', $orig['row'] ?? array() ) ) );
					$row_to_sheet[ $row_key ] = (int) ( $orig['sheet_row'] ?? 0 );
				}
				$tagged = array();
				foreach ( $sorted as $row ) {
					$plain = is_array( $row ) && isset( $row['row'] ) ? $row['row'] : $row;
					$row_key = md5( implode( '|', array_map( 'strval', (array) $plain ) ) );
					$tagged[] = array(
						'sheet_row' => $row_to_sheet[ $row_key ] ?? 0,
						'row'       => (array) $plain,
					);
				}
			}
		}

		return self::sort_grouped_children_before_parents( $tagged, $updater );
	}

	/**
	 * Move simple-product rows referenced by grouped_child_skus before grouped parent rows.
	 *
	 * @param array<int, array{sheet_row: int, row: array<int, string>}> $tagged
	 * @return array<int, array{sheet_row: int, row: array<int, string>}>
	 */
	public static function sort_grouped_children_before_parents( array $tagged, SheetSync_Product_Updater $updater ): array {
		$child_skus = array();
		foreach ( $tagged as $item ) {
			$data = $updater->extract_data( $item['row'] );
			if ( 'grouped' !== strtolower( trim( (string) ( $data['_product_type'] ?? '' ) ) ) ) {
				continue;
			}
			$raw = trim( (string) ( $data['grouped_child_skus'] ?? '' ) );
			if ( $raw === '' ) {
				continue;
			}
			foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $token ) {
				if ( preg_match( '/^id:(\d+)$/i', $token ) ) {
					continue;
				}
				$sku = function_exists( 'sheetsync_normalize_sheet_sku' )
					? sheetsync_normalize_sheet_sku( $token )
					: sanitize_text_field( $token );
				if ( $sku !== '' ) {
					$child_skus[ strtolower( $sku ) ] = true;
				}
			}
		}

		if ( empty( $child_skus ) ) {
			return $tagged;
		}

		$children_first = array();
		$rest           = array();
		foreach ( $tagged as $item ) {
			$data = $updater->extract_data( $item['row'] );
			$type = strtolower( trim( (string) ( $data['_product_type'] ?? '' ) ) );
			$sku  = strtolower(
				function_exists( 'sheetsync_normalize_sheet_sku' )
					? sheetsync_normalize_sheet_sku( (string) ( $data['_sku'] ?? '' ) )
					: sanitize_text_field( (string) ( $data['_sku'] ?? '' ) )
			);
			if ( $sku !== '' && isset( $child_skus[ $sku ] ) && 'grouped' !== $type ) {
				$children_first[] = $item;
			} else {
				$rest[] = $item;
			}
		}

		return array_merge( $children_first, $rest );
	}

	/**
	 * Batch-resolve SKUs in one query to avoid N× wc_get_product_id_by_sku() per row.
	 *
	 * @param array<int, array{sheet_row: int, row: array<int, string>}> $tagged
	 */
	public static function warm_sku_lookup_for_tagged_rows( SheetSync_Product_Updater $updater, array $tagged ): void {
		$skus = array();
		foreach ( $tagged as $item ) {
			$data = $updater->extract_data( $item['row'] );
			foreach ( array( '_sku', 'parent_sku' ) as $key ) {
				$sku = function_exists( 'sheetsync_normalize_sheet_sku' )
					? sheetsync_normalize_sheet_sku( (string) ( $data[ $key ] ?? '' ) )
					: sanitize_text_field( (string) ( $data[ $key ] ?? '' ) );
				if ( $sku !== '' ) {
					$skus[] = $sku;
				}
			}
		}
		$updater->warm_sku_lookup_cache( $skus );
	}

	/**
	 * Retry queued variations / grouped links between pull batches (not only at job end).
	 *
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 */
	public static function run_mid_batch_deferred_retries( int $connection_id, array $maps ): array {
		if ( $connection_id <= 0 ) {
			return array( 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
		}
		return self::retry_deferred_import_passes( $connection_id, $maps );
	}

	/**
	 * Log a coded import issue once per sync run (deduped by connection, code, row).
	 *
	 * @param string $severity info|warn|error
	 */
	public static function log_import_issue(
		int $connection_id,
		string $code,
		string $message,
		string $severity = 'warn',
		int $sheet_row = 0,
		string $context_key = ''
	): void {
		if ( $connection_id <= 0 || $code === '' || ! class_exists( 'SheetSync_Logger', false ) ) {
			return;
		}

		$dedupe_key = $connection_id . ':' . $code . ':' . $sheet_row . ':' . $context_key;
		if ( isset( self::$logged_import_issue_keys[ $dedupe_key ] ) ) {
			return;
		}
		self::$logged_import_issue_keys[ $dedupe_key ] = true;

		$prefixed = '[' . strtoupper( $code ) . '] ' . $message;
		switch ( $severity ) {
			case 'error':
				SheetSync_Logger::log( $connection_id, 'import', 'error', 0, 0, $prefixed, 1 );
				break;
			case 'info':
				SheetSync_Logger::debug( $prefixed, $connection_id );
				break;
			case 'warn':
			default:
				SheetSync_Logger::log( $connection_id, 'import', 'partial', 0, 0, $prefixed, 0 );
				break;
		}
	}

	/**
	 * Log non-fatal import quality warnings (missing price, title-only rows, long text).
	 *
	 * @param array<string, string> $peek_data
	 */
	public static function log_import_quality_warnings(
		int $connection_id,
		int $sheet_row,
		array $peek_data,
		bool $is_new_product = false
	): void {
		if ( $connection_id <= 0 || $sheet_row <= 0 || ! class_exists( 'SheetSync_Logger', false ) ) {
			return;
		}

		$sku   = sanitize_text_field( $peek_data['_sku'] ?? '' );
		$title = sanitize_text_field( $peek_data['post_title'] ?? '' );
		$label = $sku !== '' ? $sku : ( $title !== '' ? $title : __( 'row', 'sheetsync-for-woocommerce' ) );

		if ( $is_new_product && SheetSync_Import_Rules::should_warn_missing_sku( $peek_data ) ) {
			self::log_import_issue(
				$connection_id,
				'MISSING_SKU',
				sprintf(
					/* translators: 1: sheet row, 2: product title */
					__( 'Row %1$d (%2$s): no SKU — matching relies on title only; add a SKU to avoid duplicates.', 'sheetsync-for-woocommerce' ),
					$sheet_row,
					$title
				),
				'warn',
				$sheet_row
			);
		}

		if ( ! empty( $peek_data['_price_downgraded'] ) || ( $is_new_product && empty( $peek_data['parent_sku'] ) ) ) {
			$regular = trim( (string) ( $peek_data['_regular_price'] ?? '' ) );
			$sale    = trim( (string) ( $peek_data['_sale_price'] ?? '' ) );
			if ( ! empty( $peek_data['_price_downgraded'] ) || ( $regular === '' && $sale === '' ) ) {
				self::log_import_issue(
					$connection_id,
					'MISSING_PRICE',
					sprintf(
						/* translators: 1: sheet row, 2: SKU or title */
						__( 'Row %1$d (%2$s): no price — product saved as draft until a price is set.', 'sheetsync-for-woocommerce' ),
						$sheet_row,
						$label
					),
					'warn',
					$sheet_row
				);
			}
		}
	}

	/**
	 * True when the sheet row cannot be matched safely (ambiguous title in WooCommerce).
	 *
	 * @param array<string, string> $peek_data
	 */
	public static function is_ambiguous_title_match( array $peek_data, int $connection_id = 0 ): bool {
		if ( $connection_id <= 0 ) {
			$connection_id = self::get_current_connection_id();
		}
		$title = function_exists( 'sheetsync_normalize_sheet_title' )
			? sheetsync_normalize_sheet_title( (string) ( $peek_data['post_title'] ?? '' ) )
			: sanitize_text_field( $peek_data['post_title'] ?? '' );
		if ( $title === '' || ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return false;
		}
		if ( $connection_id > 0 ) {
			$mapped_id = SheetSync_Product_Map_Repository::resolve_product_id_from_connection_map( $connection_id, $title );
			if ( $mapped_id > 0 ) {
				return false;
			}
		}
		if ( ! SheetSync_Product_Map_Repository::title_has_multiple_matches( $title ) ) {
			return false;
		}
		return SheetSync_Product_Map_Repository::resolve_product_id_by_title( $title, $peek_data, $connection_id ) <= 0;
	}

	/**
	 * Resolve WooCommerce product for a sheet row.
	 */
	public static function resolve_wc_product(
		int $connection_id,
		string $external_key,
		int $sheet_row_num,
		array $row,
		SheetSync_Product_Updater $updater,
		?SheetSync_Product_Map_Repository $map_repo = null
	): ?WC_Product {
		unset( $external_key, $map_repo );

		$data = $updater->extract_data( $row );
		if ( class_exists( 'SheetSync_Product_Resolver', false ) ) {
			return SheetSync_Product_Resolver::resolve_for_import(
				$connection_id,
				$data,
				$updater->get_maps(),
				$sheet_row_num,
				$updater
			);
		}

		if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			$id = SheetSync_Product_Map_Repository::resolve_import_product_id( $data, null, $connection_id );
			if ( $id > 0 ) {
				return wc_get_product( $id );
			}
		} elseif ( ! empty( $data['_sku'] ) ) {
			$id = (int) wc_get_product_id_by_sku( $data['_sku'] );
			if ( $id > 0 ) {
				return wc_get_product( $id );
			}
		}

		return null;
	}

	/**
	 * Incremental skip evaluation.
	 *
	 * @return string|null skip reason, or null to process the row
	 */
	public static function evaluate_incremental_skip(
		string $mode,
		object $conn,
		string $external_key,
		string $row_hash,
		array $peek_data,
		array $row,
		?WC_Product $wc_product,
		SheetSync_Product_Updater $updater,
		SheetSync_Product_Map_Repository $map_repo,
		array $maps,
		int $connection_id
	): ?string {
		if ( 'full' === $mode ) {
			return null;
		}

		if ( $external_key !== '' ) {
			$stored_hash = $map_repo->get_sheet_hash( $connection_id, $external_key );
			if ( $stored_hash !== null && $stored_hash !== $row_hash ) {
				return null;
			}
		}

		if ( $wc_product && $updater->row_matches_product( $row, $wc_product ) ) {
			if ( ! sheetsync_row_needs_media_sync( $peek_data, $wc_product, $maps ) ) {
				return 'matched';
			}
			return null;
		}

		if ( $external_key === '' ) {
			return null;
		}

		if ( ! $wc_product ) {
			$map = $map_repo->find_by_external_key( $connection_id, $external_key );
			if ( $map && (int) $map->product_id > 0 ) {
				$wc_product = wc_get_product( (int) $map->product_id );
			}
		}

		$prev = $map_repo->get_sheet_hash( $connection_id, $external_key );
		if ( $prev !== $row_hash || ! $map_repo->mapped_product_still_exists( $connection_id, $external_key, $peek_data ) ) {
			return null;
		}

		$matches_wc = $wc_product && $updater->row_matches_product( $row, $wc_product );
		if ( $matches_wc && ! sheetsync_row_needs_media_sync( $peek_data, $wc_product, $maps ) ) {
			return 'unchanged';
		}

		if ( ! $matches_wc ) {
			if ( self::sheet_is_authoritative( $conn ) ) {
				return null;
			}
			return 'wc_edited';
		}

		return null;
	}

	/**
	 * Skip unchanged sheet rows without loading WooCommerce products (Smart Diff on Sheet → WC).
	 *
	 * @param array<string, string> $peek_data
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @return string|null unchanged when safe to skip, null to continue full evaluation
	 */
	public static function try_fast_incremental_row_skip(
		string $mode,
		object $conn,
		string $external_key,
		string $row_hash,
		array $peek_data,
		SheetSync_Product_Map_Repository $map_repo,
		array $maps,
		int $connection_id
	): ?string {
		if ( 'full' === $mode || $external_key === '' ) {
			return null;
		}

		$stored = $map_repo->get_sheet_hash( $connection_id, $external_key );
		if ( $stored === null || $stored !== $row_hash ) {
			return null;
		}
		if ( ! $map_repo->mapped_product_still_exists( $connection_id, $external_key, $peek_data ) ) {
			return null;
		}

		$map = $map_repo->find_by_external_key( $connection_id, $external_key );
		if ( ! $map || (int) $map->product_id <= 0 ) {
			return null;
		}

		$needs_wc_load = false;
		if ( class_exists( 'SheetSync_Hash_Normalizer', false ) ) {
			$stored_wc_hash = (string) ( $map->wc_hash ?? '' );
			if ( $stored_wc_hash === '' || $stored_wc_hash !== $row_hash ) {
				$needs_wc_load = true;
			}
		}

		if ( self::row_maps_include_media( $maps ) && self::peek_row_has_media_urls( $peek_data, $maps ) ) {
			$needs_wc_load = true;
		}

		if ( $needs_wc_load ) {
			$product = wc_get_product( (int) $map->product_id );
			if ( ! $product instanceof WC_Product ) {
				return null;
			}
			if ( class_exists( 'SheetSync_Hash_Normalizer', false ) ) {
				$stored_wc_hash = (string) ( $map->wc_hash ?? '' );
				if ( $stored_wc_hash === '' || $stored_wc_hash !== $row_hash ) {
					$hasher  = new SheetSync_Hash_Normalizer();
					$wc_hash = $hasher->wc_hash( $product, $maps );
					if ( $wc_hash !== $row_hash ) {
						return null;
					}
				}
			}
			if ( self::row_maps_include_media( $maps ) && function_exists( 'sheetsync_row_needs_media_sync' )
				&& sheetsync_row_needs_media_sync( $peek_data, $product, $maps ) ) {
				return null;
			}
		}

		return 'unchanged';
	}

	/**
	 * @param array<string, string> $peek_data
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 */
	private static function peek_row_has_media_urls( array $peek_data, array $maps ): bool {
		foreach ( array( '_product_image', '_gallery_images' ) as $field ) {
			if ( empty( $maps[ $field ]['sheet_column'] ) ) {
				continue;
			}
			$val = trim( (string) ( $peek_data[ $field ] ?? '' ) );
			if ( $val !== '' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 */
	private static function row_maps_include_media( array $maps ): bool {
		return ! empty( $maps['_product_image']['sheet_column'] )
			|| ! empty( $maps['_gallery_images']['sheet_column'] );
	}

	/**
	 * Refresh product_map hash when a row is skipped as unchanged (fast or full incremental path).
	 *
	 * @param array<string, string> $peek_data
	 */
	public static function refresh_map_on_incremental_skip(
		int $connection_id,
		SheetSync_Product_Map_Repository $map_repo,
		string $external_key,
		string $row_hash,
		int $sheet_row_num,
		array $peek_data,
		bool $set_pulled_at = false
	): void {
		if ( $external_key === '' ) {
			return;
		}
		$product = null;
		$map     = $map_repo->find_by_external_key( $connection_id, $external_key );
		if ( $map && (int) $map->product_id > 0 ) {
			$loaded = wc_get_product( (int) $map->product_id );
			if ( $loaded instanceof WC_Product ) {
				$product = $loaded;
			}
		}
		self::store_row_hash(
			$connection_id,
			$map_repo,
			$external_key,
			$row_hash,
			$sheet_row_num,
			$peek_data,
			$set_pulled_at,
			$product
		);
	}

	/**
	 * Two-way pull: WC changed but sheet row did not — queue push instead of leaving edits stranded.
	 *
	 * @param array<string, string> $peek_data
	 */
	public static function handle_wc_edited_pull_skip(
		object $conn,
		int $connection_id,
		int $sheet_row_num,
		array $peek_data,
		?WC_Product $wc_product,
		int $job_id = 0
	): void {
		$is_two_way = 'two_way' === (string) ( $conn->sync_direction ?? '' );
		$pid        = 0;
		if ( $wc_product instanceof WC_Product ) {
			$pid = (int) $wc_product->get_id();
		} elseif ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			$pid = SheetSync_Product_Map_Repository::resolve_import_product_id( $peek_data, null, $connection_id );
		}

		if ( $is_two_way && $pid > 0 ) {
			$queued = false;
			if ( $job_id > 0 && class_exists( 'SheetSync_Job_Repository', false ) ) {
				$job_repo = new SheetSync_Job_Repository();
				$job      = $job_repo->get( $job_id );
				if ( $job && 'two_way' === (string) ( $job->direction ?? '' ) ) {
					$job_repo->queue_wc_edited_push( $job_id, $pid );
					$queued = true;
				}
			}
			if ( ! $queued && function_exists( 'sheetsync_mark_product_dirty' ) ) {
				sheetsync_mark_product_dirty( $connection_id, $pid );
			}
		}

		$detail = $is_two_way
			? __( 'WooCommerce was edited but the sheet row is unchanged — queued for push to sheet.', 'sheetsync-for-woocommerce' )
			: __( 'WooCommerce was edited but the sheet row is unchanged — use WC→Sheet sync or change the sheet.', 'sheetsync-for-woocommerce' );

		self::log_row_result( $connection_id, $sheet_row_num, $peek_data, 'skipped', $detail );
	}

	/**
	 * Store product map hash after a successful match or update.
	 *
	 * @param array<string, string> $peek_data
	 */
	public static function store_row_hash(
		int $connection_id,
		SheetSync_Product_Map_Repository $map_repo,
		string $external_key,
		string $row_hash,
		int $sheet_row_num,
		array $peek_data,
		bool $set_pulled_at = false,
		?WC_Product $product = null
	): void {
		if ( $external_key === '' ) {
			if ( $product instanceof WC_Product ) {
				$external_key = SheetSync_Product_Map_Repository::external_key_for_product( $product );
			} else {
				$pid = SheetSync_Product_Map_Repository::resolve_import_product_id( $peek_data, $product, $connection_id );
				$external_key = SheetSync_Product_Map_Repository::normalize_external_key(
					array(
						'product_id' => $pid,
						'sheet_row'  => $sheet_row_num,
					)
				);
			}
			if ( $external_key === '' ) {
				return;
			}
		}
		$extra = array(
			'sheet_row'  => $sheet_row_num,
			'product_id' => SheetSync_Product_Map_Repository::resolve_import_product_id( $peek_data, $product, $connection_id ),
		);
		if ( $set_pulled_at ) {
			$extra['last_pulled_at'] = current_time( 'mysql', true );
		}
		$map_repo->set_sheet_hash( $connection_id, $external_key, $row_hash, $extra );
	}

	/**
	 * Persist sheet_row ↔ product binding after a successful AJAX/manual import row.
	 *
	 * @param array<int, string>    $row
	 * @param array<string, string> $peek_data
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 */
	public static function persist_import_row_map(
		int $connection_id,
		array $maps,
		array $row,
		array $peek_data,
		int $sheet_row
	): void {
		if ( $connection_id <= 0 || $sheet_row <= 0 || ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return;
		}

		$map_repo = new SheetSync_Product_Map_Repository();
		$hasher   = class_exists( 'SheetSync_Hash_Normalizer', false ) ? new SheetSync_Hash_Normalizer() : null;
		$external_key = $hasher
			? $hasher->external_key_from_row_data( $peek_data, $maps, $sheet_row )
			: SheetSync_Product_Map_Repository::external_key_from_import_data( $peek_data, $maps, $sheet_row );
		if ( $external_key === '' ) {
			return;
		}

		$row_hash  = $hasher ? $hasher->sheet_hash( $row, $maps, $peek_data ) : md5( implode( '|', $row ) );
		$pid       = SheetSync_Product_Map_Repository::resolve_import_product_id( $peek_data, null, $connection_id );
		$product   = $pid > 0 ? wc_get_product( $pid ) : null;

		self::store_row_hash(
			$connection_id,
			$map_repo,
			$external_key,
			$row_hash,
			$sheet_row,
			$peek_data,
			true,
			$product instanceof WC_Product ? $product : null
		);
	}

	/**
	 * Defer a grouped parent row until child SKUs exist in WooCommerce (cross-batch import).
	 *
	 * @param array<int, string>    $row
	 * @param array<string, string> $data
	 */
	public static function queue_grouped_parent_row( int $connection_id, array $row, array $data, int $sheet_row ): void {
		if ( $connection_id <= 0 || $sheet_row <= 0 || empty( $row ) ) {
			return;
		}
		if ( ! isset( self::$grouped_parent_retries[ $connection_id ] ) ) {
			self::load_grouped_parent_retry_queue( $connection_id );
		}
		self::$grouped_parent_retries[ $connection_id ][ $sheet_row ] = array(
			'row'  => $row,
			'data' => $data,
		);
		self::flush_grouped_parent_retry_queue( $connection_id );
	}

	/**
	 * @param array<int, string>    $row
	 * @param array<string, string> $peek_data
	 * @return string|null queued when deferred, null to continue import
	 */
	public static function maybe_defer_grouped_parent_import(
		int $connection_id,
		array $row,
		array $peek_data,
		int $sheet_row
	): ?string {
		if ( $connection_id <= 0 || $sheet_row <= 0 ) {
			return null;
		}
		if ( 'grouped' !== strtolower( trim( (string) ( $peek_data['_product_type'] ?? '' ) ) ) ) {
			return null;
		}
		$raw = trim( (string) ( $peek_data['grouped_child_skus'] ?? '' ) );
		if ( $raw === '' || ! function_exists( 'sheetsync_analyze_grouped_child_list' ) ) {
			return null;
		}
		$analysis = sheetsync_analyze_grouped_child_list( $raw );
		if ( empty( $analysis['pending'] ) ) {
			return null;
		}
		self::queue_grouped_parent_row( $connection_id, $row, $peek_data, $sheet_row );
		self::log_row_result(
			$connection_id,
			$sheet_row,
			$peek_data,
			'skipped',
			__( 'Grouped parent deferred — waiting for child SKU rows to import first.', 'sheetsync-for-woocommerce' )
		);
		return 'queued';
	}

	/**
	 * Whether variation / grouped deferred queues still have pending rows.
	 */
	public static function has_pending_deferred_imports( int $connection_id ): bool {
		if ( $connection_id <= 0 ) {
			return false;
		}
		if ( ! empty( self::load_variation_retry_queue( $connection_id ) ) ) {
			return true;
		}
		if ( ! empty( self::load_grouped_parent_retry_queue( $connection_id ) ) ) {
			return true;
		}
		if ( ! empty( self::load_grouped_retry_queue( $connection_id ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Import grouped parent rows queued until child products exist.
	 *
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @return array{processed: int, skipped: int, errors: int}
	 */
	public static function retry_queued_grouped_parents( int $connection_id, array $maps ): array {
		$queue = self::load_grouped_parent_retry_queue( $connection_id );
		if ( empty( $queue ) ) {
			return array( 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		$updater   = new SheetSync_Product_Updater( $maps );
		$processed = $skipped = $errors = 0;
		$remaining = array();

		self::begin_batch();
		foreach ( $queue as $sheet_row => $payload ) {
			$row  = is_array( $payload['row'] ?? null ) ? $payload['row'] : array();
			$data = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
			if ( empty( $row ) || empty( $data ) ) {
				++$skipped;
				continue;
			}
			if ( function_exists( 'sheetsync_analyze_grouped_child_list' ) ) {
				$raw = trim( (string) ( $data['grouped_child_skus'] ?? '' ) );
				if ( $raw !== '' ) {
					$analysis = sheetsync_analyze_grouped_child_list( $raw );
					if ( ! empty( $analysis['pending'] ) ) {
						$remaining[ (int) $sheet_row ] = $payload;
						++$skipped;
						continue;
					}
				}
			}
			$result = $updater->update( $row, (int) $sheet_row );
			if ( 'updated' === $result ) {
				++$processed;
			} elseif ( 'error' === $result ) {
				++$errors;
				$remaining[ (int) $sheet_row ] = $payload;
				self::log_row_result( $connection_id, (int) $sheet_row, $data, 'error' );
			} else {
				++$skipped;
				if ( 'queued' === $result ) {
					$remaining[ (int) $sheet_row ] = $payload;
				}
			}
		}
		self::end_batch();

		self::$grouped_parent_retries[ $connection_id ] = $remaining;
		self::flush_grouped_parent_retry_queue( $connection_id );

		return array(
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	/**
	 * Queue grouped-product child linking when child SKUs are not in WooCommerce yet.
	 */
	public static function queue_grouped_link_retry( int $connection_id, int $product_id, string $child_list_raw ): void {
		if ( $connection_id <= 0 || $product_id <= 0 || trim( $child_list_raw ) === '' ) {
			return;
		}

		if ( ! isset( self::$grouped_link_retries[ $connection_id ] ) ) {
			self::load_grouped_retry_queue( $connection_id );
		}

		self::$grouped_link_retries[ $connection_id ][ $product_id ] = trim( $child_list_raw );
		self::flush_grouped_retry_queue( $connection_id );
	}

	/**
	 * Retry grouped-product child links after child rows have been imported.
	 *
	 * @return array{processed: int, skipped: int, errors: int}
	 */
	public static function retry_queued_grouped_links( int $connection_id, bool $finalize = false ): array {
		if ( $connection_id <= 0 || ! function_exists( 'sheetsync_analyze_grouped_child_list' ) ) {
			return array( 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		$queue = self::load_grouped_retry_queue( $connection_id );
		if ( empty( $queue ) ) {
			return array( 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		$processed = $skipped = $errors = 0;
		$remaining = array();

		self::begin_batch();
		foreach ( $queue as $product_id => $raw ) {
			$product_id = absint( $product_id );
			$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
			if ( ! $product || ! $product->is_type( 'grouped' ) ) {
				++$skipped;
				continue;
			}

			$analysis = sheetsync_analyze_grouped_child_list( (string) $raw );
			if ( empty( $analysis['ids'] ) && ! empty( $analysis['pending'] ) ) {
				if ( $finalize ) {
					++$errors;
					if ( class_exists( 'SheetSync_Logger', false ) ) {
						SheetSync_Logger::log(
							$connection_id,
							'import',
							'error',
							0,
							0,
							sprintf(
								/* translators: 1: grouped product ID, 2: unresolved child SKU list */
								__( 'Grouped product #%1$d still missing child SKU(s): %2$s — place child rows above the grouped parent or import children first.', 'sheetsync-for-woocommerce' ),
								$product_id,
								(string) $raw
							),
							1
						);
					}
				} else {
					$remaining[ $product_id ] = (string) $raw;
					++$skipped;
				}
				continue;
			}

			if ( ! empty( $analysis['ids'] ) ) {
				$product->set_children( $analysis['ids'] );
				try {
					SheetSync_Product_Updater::flag_internal_update( true );
					$product->save();
					SheetSync_Product_Updater::flag_internal_update( false );
					++$processed;
				} catch ( Exception $e ) {
					++$errors;
					$remaining[ $product_id ] = (string) $raw;
					if ( class_exists( 'SheetSync_Logger', false ) ) {
						SheetSync_Logger::log(
							$connection_id,
							'import',
							'error',
							0,
							0,
							sprintf(
								/* translators: 1: product ID, 2: error message */
								__( 'Grouped product %1$d child link retry failed: %2$s', 'sheetsync-for-woocommerce' ),
								$product_id,
								$e->getMessage()
							),
							1
						);
					}
				}
			}

			if ( ! empty( $analysis['pending'] ) ) {
				if ( $finalize ) {
					++$errors;
					if ( class_exists( 'SheetSync_Logger', false ) ) {
						SheetSync_Logger::log(
							$connection_id,
							'import',
							'error',
							0,
							0,
							sprintf(
								/* translators: 1: grouped product ID, 2: unresolved child SKU list */
								__( 'Grouped product #%1$d could not link all children: %2$s', 'sheetsync-for-woocommerce' ),
								$product_id,
								(string) $raw
							),
							1
						);
					}
				} else {
					$remaining[ $product_id ] = (string) $raw;
				}
			}
		}
		self::end_batch();

		self::$grouped_link_retries[ $connection_id ] = $finalize ? array() : $remaining;
		self::flush_grouped_retry_queue( $connection_id );

		if ( $processed > 0 && class_exists( 'SheetSync_Logger', false ) ) {
			SheetSync_Logger::log(
				$connection_id,
				'import',
				$errors > 0 ? 'partial' : 'success',
				$processed,
				$skipped,
				sprintf(
					/* translators: 1: processed count, 2: error count */
					__( 'Grouped child link retry: %1$d updated, %2$d errors', 'sheetsync-for-woocommerce' ),
					$processed,
					$errors
				),
				$errors
			);
		}

		return array(
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	/**
	 * Last-chance grouped child linking at end of import / job pull phase.
	 *
	 * @return array{processed: int, skipped: int, errors: int}
	 */
	public static function finalize_grouped_link_queue( int $connection_id ): array {
		if ( $connection_id <= 0 ) {
			return array( 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
		}
		self::retry_queued_grouped_links( $connection_id, false );
		return self::retry_queued_grouped_links( $connection_id, true );
	}

	/**
	 * Run deferred variation + grouped link passes at end of import.
	 *
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @return array{processed: int, skipped: int, errors: int}
	 */
	public static function retry_deferred_import_passes( int $connection_id, array $maps, bool $finalize = false, ?SheetSync_Product_Updater $updater = null ): array {
		$max_passes = max( 1, (int) apply_filters( 'sheetsync_import_deferred_retry_passes', 3 ) );
		$totals     = array(
			'processed' => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);
		$shared_updater = $updater ?? new SheetSync_Product_Updater( $maps );
		if ( null === $updater ) {
			self::warm_updater_from_variation_queue( $connection_id, $shared_updater );
		}

		for ( $pass = 0; $pass < $max_passes; $pass++ ) {
			$is_last = ( $pass === $max_passes - 1 ) || $finalize;

			$parents   = self::retry_queued_grouped_parents( $connection_id, $maps );
			$variation = self::retry_queued_variations( $connection_id, $maps, $shared_updater );
			$grouped   = self::retry_queued_grouped_links( $connection_id, $finalize && $is_last );

			foreach ( array( $parents, $variation, $grouped ) as $chunk ) {
				$totals['processed'] += (int) ( $chunk['processed'] ?? 0 );
				$totals['skipped']   += (int) ( $chunk['skipped'] ?? 0 );
				$totals['errors']    += (int) ( $chunk['errors'] ?? 0 );
			}

			$pass_processed = (int) ( $parents['processed'] ?? 0 )
				+ (int) ( $variation['processed'] ?? 0 )
				+ (int) ( $grouped['processed'] ?? 0 );

			if ( 0 === $pass_processed && ! self::has_pending_deferred_imports( $connection_id ) ) {
				break;
			}
		}

		return $totals;
	}

	/**
	 * Log per-row import issues with connection + row context.
	 *
	 * @param array<string, string> $peek_data
	 */
	public static function log_row_result(
		int $connection_id,
		int $sheet_row,
		array $peek_data,
		string $result,
		?string $detail = null
	): void {
		if ( ! in_array( $result, array( 'error', 'skipped' ), true ) && $detail === null ) {
			return;
		}

		$sku   = sanitize_text_field( $peek_data['_sku'] ?? '' );
		$title = sanitize_text_field( $peek_data['post_title'] ?? '' );
		$label = $sku !== '' ? $sku : ( $title !== '' ? $title : __( '(no SKU/title)', 'sheetsync-for-woocommerce' ) );

		if ( $detail === null && class_exists( 'SheetSync_Variation_Sync', false ) ) {
			$detail = SheetSync_Variation_Sync::consume_last_message();
		}

		$message = sprintf(
			/* translators: 1: sheet row number, 2: SKU or title, 3: result */
			__( 'Row %1$d (%2$s): %3$s', 'sheetsync-for-woocommerce' ),
			$sheet_row,
			$label,
			$result
		);
		if ( is_string( $detail ) && $detail !== '' ) {
			$message .= ' — ' . $detail;
		}

		if ( 'error' === $result ) {
			SheetSync_Logger::log( $connection_id, 'import', 'error', 0, 0, $message, 1 );
			return;
		}

		if ( 'skipped' === $result ) {
			SheetSync_Logger::log( $connection_id, 'import', 'partial', 0, 1, $message, 0 );
		}
	}

	/**
	 * Queue a variation row whose parent was not found yet (cross-batch retry).
	 *
	 * @param array<int, string>    $row
	 * @param array<string, string> $data
	 */
	public static function queue_variation_row( int $connection_id, array $row, array $data, int $sheet_row ): void {
		if ( $connection_id <= 0 || empty( $data['parent_sku'] ) || $sheet_row <= 0 ) {
			return;
		}

		if ( ! self::can_enqueue_variation_retry( $connection_id ) ) {
			$parent_sku = sanitize_text_field( $data['parent_sku'] ?? '' );
			$var_sku    = sanitize_text_field( $data['_sku'] ?? '' );
			if ( class_exists( 'SheetSync_Logger', false ) ) {
				SheetSync_Logger::log(
					$connection_id,
					'import',
					'error',
					0,
					0,
					sprintf(
						/* translators: 1: sheet row, 2: variation SKU, 3: parent SKU */
						__( 'Row %1$d: variation retry queue is full — could not queue variation (SKU: %2$s, parent: %3$s). Import parent products first, then sync again.', 'sheetsync-for-woocommerce' ),
						$sheet_row,
						$var_sku !== '' ? $var_sku : '—',
						$parent_sku
					),
					1
				);
			}
			return;
		}

		global $wpdb;
		$table = SheetSync_Schema::table( 'variation_retry' );
		$row_json  = wp_json_encode( array_values( $row ) );
		$data_json = wp_json_encode( $data );
		if ( ! is_string( $row_json ) || ! is_string( $data_json ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (connection_id, sheet_row, row_json, data_json, created_at)
				VALUES (%d, %d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE row_json = VALUES(row_json), data_json = VALUES(data_json), created_at = VALUES(created_at)",
				$connection_id,
				$sheet_row,
				$row_json,
				$data_json,
				current_time( 'mysql', true )
			)
		);

	}

	/**
	 * Whether another variation row can be queued (never silently drop queued rows).
	 */
	private static function can_enqueue_variation_retry( int $connection_id ): bool {
		global $wpdb;

		$max   = (int) apply_filters( 'sheetsync_variation_retry_queue_max', 5000 );
		$max   = max( 200, min( 20000, $max ) );
		$table = SheetSync_Schema::table( 'variation_retry' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE connection_id = %d",
				$connection_id
			)
		);

		if ( $count < $max ) {
			return true;
		}

		$warn_key = 'sheetsync_var_queue_full_' . $connection_id;
		if ( ! get_transient( $warn_key ) && class_exists( 'SheetSync_Logger', false ) ) {
			set_transient( $warn_key, 1, HOUR_IN_SECONDS );
			SheetSync_Logger::log(
				$connection_id,
				'import',
				'error',
				0,
				0,
				sprintf(
					/* translators: %d: queue cap */
					__( 'Variation retry queue is full (%d rows). New variation rows will not be queued until parents are imported and the queue drains. Re-import parent products first, then sync again.', 'sheetsync-for-woocommerce' ),
					$max
				),
				1
			);
		}

		return false;
	}

	/**
	 * @return array<int, array{sheet_row: int, row: array<int, string>, data: array<string, string>}>
	 */
	private static function load_variation_retry_queue( int $connection_id ): array {
		global $wpdb;

		$table = SheetSync_Schema::table( 'variation_retry' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sheet_row, row_json, data_json FROM {$table} WHERE connection_id = %d ORDER BY sheet_row ASC",
				$connection_id
			),
			ARRAY_A
		);

		$queue = array();
		foreach ( (array) $rows as $row ) {
			$decoded_row  = json_decode( (string) ( $row['row_json'] ?? '' ), true );
			$decoded_data = json_decode( (string) ( $row['data_json'] ?? '' ), true );
			if ( ! is_array( $decoded_row ) || ! is_array( $decoded_data ) ) {
				continue;
			}
			$queue[] = array(
				'sheet_row' => (int) ( $row['sheet_row'] ?? 0 ),
				'row'       => array_map( 'strval', $decoded_row ),
				'data'      => array_map( 'strval', $decoded_data ),
			);
		}

		return $queue;
	}

	private static function delete_variation_retry_rows( int $connection_id, array $sheet_rows ): void {
		$sheet_rows = array_values( array_filter( array_map( 'absint', $sheet_rows ) ) );
		if ( $connection_id <= 0 || empty( $sheet_rows ) ) {
			return;
		}

		global $wpdb;
		$table        = SheetSync_Schema::table( 'variation_retry' );
		$placeholders = implode( ',', array_fill( 0, count( $sheet_rows ), '%d' ) );
		$params       = array_merge( array( $connection_id ), $sheet_rows );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			sheetsync_wpdb_prepare(
				"DELETE FROM {$table} WHERE connection_id = %d AND sheet_row IN ({$placeholders})",
				$params
			)
		);
	}

	public static function clear_variation_retry_queue( int $connection_id ): void {
		if ( $connection_id <= 0 ) {
			return;
		}
		global $wpdb;
		$table = SheetSync_Schema::table( 'variation_retry' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'connection_id' => $connection_id ), array( '%d' ) );
		delete_transient( 'sheetsync_var_retry_' . $connection_id );
	}

	/**
	 * Whether a failed variation retry should stay queued (parent not ready yet).
	 */
	private static function variation_retry_error_is_transient( string $message ): bool {
		if ( $message === '' ) {
			return false;
		}
		$needles = array(
			'not found',
			'Import parent row first',
			'not a variable product',
		);
		foreach ( $needles as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Retry variation rows queued during pull (parents imported in later batches).
	 *
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @return array{processed: int, skipped: int, errors: int}
	 */
	public static function retry_queued_variations( int $connection_id, array $maps, ?SheetSync_Product_Updater $updater = null ): array {
		$queue = self::load_variation_retry_queue( $connection_id );
		$batch_limit = max( 25, min( 500, (int) apply_filters( 'sheetsync_variation_retry_batch_size', 150 ) ) );
		if ( count( $queue ) > $batch_limit ) {
			$queue = array_slice( $queue, 0, $batch_limit );
		}
		if ( empty( $queue ) ) {
			$key = 'sheetsync_var_retry_' . $connection_id;
			$legacy = get_transient( $key );
			delete_transient( $key );
			if ( is_array( $legacy ) && ! empty( $legacy ) ) {
				foreach ( $legacy as $item ) {
					$row       = is_array( $item['row'] ?? null ) ? $item['row'] : array();
					$data      = is_array( $item['data'] ?? null ) ? $item['data'] : array();
					$sheet_row = (int) ( $item['sheet_row'] ?? 0 );
					if ( $sheet_row > 0 && ! empty( $data['parent_sku'] ) ) {
						self::queue_variation_row( $connection_id, $row, $data, $sheet_row );
					}
				}
				$queue = self::load_variation_retry_queue( $connection_id );
			}
		}

		if ( empty( $queue ) || ! class_exists( 'SheetSync_Variation_Sync', false ) ) {
			return array( 'processed' => 0, 'skipped' => 0, 'errors' => 0 );
		}

		$updater   = $updater ?? new SheetSync_Product_Updater( $maps );
		$sync      = new SheetSync_Variation_Sync();
		$processed = $skipped = $errors = 0;
		$remaining = array();
		$resolved  = array();

		self::begin_batch();
		foreach ( $queue as $item ) {
			$row       = is_array( $item['row'] ?? null ) ? $item['row'] : array();
			$data      = is_array( $item['data'] ?? null ) ? $item['data'] : array();
			$sheet_row = (int) ( $item['sheet_row'] ?? 0 );

			if ( empty( $data ) || ! SheetSync_Variation_Sync::is_variation_row( $data ) ) {
				if ( $sheet_row > 0 ) {
					$resolved[] = $sheet_row;
				}
				++$skipped;
				continue;
			}

			$result = $sync->sync_variation( $data, $maps, $row, $sheet_row );
			if ( 'updated' === $result ) {
				++$processed;
				$resolved[] = $sheet_row;
			} elseif ( 'queued' === $result ) {
				++$skipped;
				$remaining[] = $item;
			} elseif ( 'error' === $result ) {
				++$errors;
				$msg = SheetSync_Variation_Sync::consume_last_message();
				if ( self::variation_retry_error_is_transient( is_string( $msg ) ? $msg : '' ) ) {
					$remaining[] = $item;
				} elseif ( $sheet_row > 0 ) {
					$resolved[] = $sheet_row;
				}
				self::log_row_result( $connection_id, $sheet_row, $data, 'error', is_string( $msg ) ? $msg : null );
			} elseif ( 'skipped' === $result ) {
				++$skipped;
				$resolved[] = $sheet_row;
			} else {
				++$skipped;
				$resolved[] = $sheet_row;
			}
		}
		self::end_batch();

		if ( ! empty( $resolved ) ) {
			self::delete_variation_retry_rows( $connection_id, $resolved );
		}

		if ( ! empty( $remaining ) ) {
			foreach ( $remaining as $item ) {
				$row       = is_array( $item['row'] ?? null ) ? $item['row'] : array();
				$data      = is_array( $item['data'] ?? null ) ? $item['data'] : array();
				$sheet_row = (int) ( $item['sheet_row'] ?? 0 );
				if ( $sheet_row > 0 && ! empty( $data['parent_sku'] ) ) {
					self::queue_variation_row( $connection_id, $row, $data, $sheet_row );
				}
			}
		}

		if ( $processed > 0 ) {
			SheetSync_Logger::log(
				$connection_id,
				'import',
				$errors > 0 ? 'partial' : 'success',
				$processed,
				$skipped,
				sprintf(
					/* translators: 1: processed count, 2: error count */
					__( 'Variation retry pass: %1$d updated, %2$d errors', 'sheetsync-for-woocommerce' ),
					$processed,
					$errors
				),
				$errors
			);
		}

		return array(
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	/**
	 * Import one sheet row through the shared pull/manual pipeline (guards + apply_sheet_row_to_wc).
	 *
	 * @param array<int, string>                                              $row
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @return string updated|skipped|error|queued|created
	 */
	public static function import_sheet_row(
		object $conn,
		int $connection_id,
		array $maps,
		array $row,
		int $sheet_row_num,
		SheetSync_Product_Updater $updater,
		?SheetSync_Product_Map_Repository $map_repo = null,
		?string $mode = null,
		bool $create_new = false,
		bool $skip_existing = false
	): string {
		$map_repo = $map_repo ?? new SheetSync_Product_Map_Repository();
		$mode     = $mode ?? ( class_exists( 'SheetSync_Sync_Mode', false )
			? SheetSync_Sync_Mode::resolve( $connection_id )
			: 'incremental' );
		$peek_data = $updater->extract_data( $row );

		$import_skip = function_exists( 'sheetsync_importable_product_row_skip_reason' )
			? sheetsync_importable_product_row_skip_reason( $peek_data )
			: null;
		if ( is_string( $import_skip ) && $import_skip !== '' ) {
			self::log_row_result( $connection_id, $sheet_row_num, $peek_data, 'error', $import_skip );
			return 'error';
		}

		if ( self::is_later_duplicate_sheet_sku( $connection_id, $sheet_row_num, $peek_data ) ) {
			self::log_row_result(
				$connection_id,
				$sheet_row_num,
				$peek_data,
				'skipped',
				__( 'Duplicate SKU in sheet — first row wins.', 'sheetsync-for-woocommerce' )
			);
			return 'skipped';
		}

		if ( self::is_later_duplicate_sheet_title( $connection_id, $sheet_row_num, $peek_data ) ) {
			self::log_row_result(
				$connection_id,
				$sheet_row_num,
				$peek_data,
				'skipped',
				__( 'Duplicate title in sheet — first row wins.', 'sheetsync-for-woocommerce' )
			);
			return 'skipped';
		}

		if ( self::is_later_duplicate_variation_row( $connection_id, $sheet_row_num, $peek_data ) ) {
			self::log_row_result(
				$connection_id,
				$sheet_row_num,
				$peek_data,
				'skipped',
				__( 'Duplicate variation in sheet — first row wins.', 'sheetsync-for-woocommerce' )
			);
			return 'skipped';
		}

		$ambiguous_sku_msg = self::ambiguous_sku_import_message( $peek_data, $connection_id );
		if ( is_string( $ambiguous_sku_msg ) && $ambiguous_sku_msg !== '' ) {
			self::log_row_result( $connection_id, $sheet_row_num, $peek_data, 'error', $ambiguous_sku_msg );
			return 'error';
		}

		if ( self::is_ambiguous_title_match( $peek_data, $connection_id ) ) {
			self::log_row_result(
				$connection_id,
				$sheet_row_num,
				$peek_data,
				'error',
				__( 'Multiple WooCommerce products share this title — map Product ID or use a unique SKU.', 'sheetsync-for-woocommerce' )
			);
			return 'error';
		}

		if ( $skip_existing && self::should_skip_existing_product( $connection_id, $sheet_row_num, $peek_data ) ) {
			return 'skipped';
		}

		$hasher       = class_exists( 'SheetSync_Hash_Normalizer', false ) ? new SheetSync_Hash_Normalizer() : null;
		$external_key = $hasher
			? $hasher->external_key_from_row( $row, $maps, $updater, $sheet_row_num )
			: SheetSync_Product_Map_Repository::external_key_from_import_data( $peek_data, $maps, $sheet_row_num );
		$row_hash     = $hasher ? $hasher->sheet_hash( $row, $maps, $peek_data ) : md5( implode( '|', $row ) );

		$fast_skip = self::try_fast_incremental_row_skip(
			$mode,
			$conn,
			$external_key,
			$row_hash,
			$peek_data,
			$map_repo,
			$maps,
			$connection_id
		);
		if ( 'unchanged' === $fast_skip ) {
			self::refresh_map_on_incremental_skip(
				$connection_id,
				$map_repo,
				$external_key,
				$row_hash,
				$sheet_row_num,
				$peek_data,
				true
			);
			return 'skipped';
		}

		$wc_product = self::resolve_wc_product(
			$connection_id,
			$external_key,
			$sheet_row_num,
			$row,
			$updater,
			$map_repo
		);

		$skip_reason = self::evaluate_incremental_skip(
			$mode,
			$conn,
			$external_key,
			$row_hash,
			$peek_data,
			$row,
			$wc_product,
			$updater,
			$map_repo,
			$maps,
			$connection_id
		);

		if ( in_array( $skip_reason, array( 'matched', 'unchanged' ), true ) ) {
			if ( $external_key !== '' && $wc_product ) {
				self::store_row_hash(
					$connection_id,
					$map_repo,
					$external_key,
					$row_hash,
					$sheet_row_num,
					$peek_data,
					true,
					$wc_product
				);
			}
			return 'skipped';
		}

		if ( 'wc_edited' === $skip_reason ) {
			self::handle_wc_edited_pull_skip( $conn, $connection_id, $sheet_row_num, $peek_data, $wc_product );
			return 'skipped';
		}

		$result = self::apply_sheet_row_to_wc(
			$conn,
			$connection_id,
			$mode,
			$external_key,
			$row_hash,
			$peek_data,
			$row,
			$sheet_row_num,
			$wc_product,
			$updater,
			$map_repo
		);

		if ( 'skipped' === $result && $create_new ) {
			if ( class_exists( 'SheetSync_Variation_Sync', false )
				&& function_exists( 'sheetsync_is_pro' )
				&& sheetsync_is_pro()
				&& ! SheetSync_Variation_Sync::should_use_simple_create_fallback( $peek_data ) ) {
				$result = SheetSync_Variation_Sync::create_from_row_static( $row, $maps );
			} elseif ( class_exists( 'SheetSync_Bulk_Processor', false ) ) {
				$result = SheetSync_Bulk_Processor::create_product_from_row_static( $row, $maps );
			}
			if ( 'created' === $result ) {
				self::persist_import_row_map( $connection_id, $maps, $row, $peek_data, $sheet_row_num );
				return 'created';
			}
		}

		if ( 'updated' === $result ) {
			self::persist_import_row_map( $connection_id, $maps, $row, $peek_data, $sheet_row_num );
		}

		return $result;
	}

	/**
	 * @param array<string, string> $peek_data
	 */
	private static function should_skip_existing_product( int $connection_id, int $sheet_row_num, array $peek_data ): bool {
		if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			$resolved = SheetSync_Product_Map_Repository::resolve_import_product_id( $peek_data, null, $connection_id );
			if ( $resolved > 0 && wc_get_product( $resolved ) ) {
				return true;
			}
		}

		$sku   = sanitize_text_field( $peek_data['_sku'] ?? '' );
		$title = sanitize_text_field( $peek_data['post_title'] ?? '' );
		$pid   = 0;
		if ( $sku !== '' ) {
			$pid = (int) wc_get_product_id_by_sku( $sku );
		} elseif ( $title !== '' && class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			if ( $sheet_row_num > 0 ) {
				$map_repo = new SheetSync_Product_Map_Repository();
				$map      = $map_repo->find_by_sheet_row( $connection_id, $sheet_row_num );
				if ( $map && (int) $map->product_id > 0 ) {
					$pid = (int) $map->product_id;
				}
			}
			if ( ! $pid ) {
				$normalized_title = function_exists( 'sheetsync_normalize_sheet_title' )
					? sheetsync_normalize_sheet_title( $title )
					: $title;
				$pid = SheetSync_Product_Map_Repository::resolve_product_id_by_title( $normalized_title, $peek_data, $connection_id );
			}
		}
		return $pid > 0;
	}

	/**
	 * Warm SKU lookup cache from queued variation rows before a retry pass.
	 */
	private static function warm_updater_from_variation_queue( int $connection_id, SheetSync_Product_Updater $updater ): void {
		$queue = self::load_variation_retry_queue( $connection_id );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		$skus = array();
		foreach ( $queue as $item ) {
			$data = is_array( $item['data'] ?? null ) ? $item['data'] : array();
			$sku  = sanitize_text_field( $data['_sku'] ?? '' );
			if ( $sku !== '' ) {
				$skus[] = $sku;
			}
			$parent_sku = sanitize_text_field( $data['parent_sku'] ?? '' );
			if ( $parent_sku !== '' ) {
				$skus[] = $parent_sku;
			}
		}
		if ( ! empty( $skus ) && method_exists( $updater, 'warm_sku_lookup_cache' ) ) {
			$updater->warm_sku_lookup_cache( array_values( array_unique( $skus ) ) );
		}
	}

	/**
	 * Import one sheet row with optional two-way conflict resolution.
	 *
	 * @param array<string, string> $peek_data
	 * @param array<int, string>    $row
	 * @return string updated|skipped|error|queued
	 */
	public static function apply_sheet_row_to_wc(
		object $conn,
		int $connection_id,
		string $mode,
		string $external_key,
		string $row_hash,
		array $peek_data,
		array $row,
		int $sheet_row_num,
		?WC_Product $wc_product,
		SheetSync_Product_Updater $updater,
		SheetSync_Product_Map_Repository $map_repo
	): string {
		$defer = self::maybe_defer_grouped_parent_import( $connection_id, $row, $peek_data, $sheet_row_num );
		if ( 'queued' === $defer ) {
			return 'queued';
		}

		if ( $wc_product instanceof WC_Product
			&& class_exists( 'SheetSync_Conflict_Resolver', false )
			&& SheetSync_Conflict_Resolver::is_simultaneous_edit(
				$mode,
				$conn,
				$external_key,
				$row_hash,
				$row,
				$wc_product,
				$updater,
				$map_repo,
				$connection_id
			) ) {
			$resolved = SheetSync_Conflict_Resolver::resolve_pull_row(
				$conn,
				$connection_id,
				$mode,
				$external_key,
				$row_hash,
				$peek_data,
				$row,
				$sheet_row_num,
				$wc_product,
				$updater,
				$map_repo
			);
			$result = (string) ( $resolved['result'] ?? 'skipped' );
			if ( ! empty( $resolved['detail'] ) ) {
				self::log_row_result( $connection_id, $sheet_row_num, $peek_data, 'skipped', (string) $resolved['detail'] );
			}
			return $result;
		}

		return $updater->update( $row, $sheet_row_num );
	}
}

endif;
