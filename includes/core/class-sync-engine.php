<?php
/**
 * Core sync engine — reads from Google Sheets and updates WooCommerce products.
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sync_Engine' ) ) :

class SheetSync_Sync_Engine {

    private const BATCH_SIZE = 50;

    /**
     * Run a full sync for a connection.
     *
     * @return array{success: bool, processed: int, skipped: int, errors: int, message: string}
     */
    public function run( int $connection_id, array $start_args = array() ): array {
        global $wpdb;

        // ── Load connection ───────────────────────────────────────────────
        $conn = $wpdb->get_row( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT * FROM {$wpdb->prefix}sheetsync_connections WHERE id = %d AND status = 'active'",
            $connection_id
        ) );

        if ( ! $conn ) {
            $msg = __( 'Connection not found or inactive.', 'sheetsync-for-woocommerce' );
            SheetSync_Logger::log( $connection_id, 'manual', 'error', 0, 0, $msg );
            return array(
                'success' => false,
                'message' => $msg,
            );
        }

        $direction = isset( $conn->sync_direction ) ? (string) $conn->sync_direction : 'sheets_to_wc';

        // Order connections: route to order-specific sync based on direction.
        if ( self::is_orders_type( $conn->connection_type ?? 'products' ) ) {
            return $this->run_order_sync( $conn, $connection_id, $direction );
        }

        // Enterprise job queue for product connections (10k+ scalable path).
        if ( sheetsync_use_job_engine() && class_exists( 'SheetSync_Sync_Coordinator', false ) ) {
            $strategy_raw = apply_filters(
                'sheetsync_sync_strategy_' . $connection_id,
                get_option( 'sheetsync_sync_strategy_' . $connection_id, 'smart' )
            );
            $mode_override = null;
            if ( is_string( $strategy_raw ) && in_array( $strategy_raw, array( 'smart', 'full' ), true ) ) {
                $mode_override = ( 'full' === $strategy_raw ) ? 'full' : 'incremental';
            }

            $pull_mode = get_transient( 'sheetsync_pull_mode_' . $connection_id );
            if ( ! is_string( $pull_mode ) || ! in_array( $pull_mode, array( 'create_new', 'update_only', 'default' ), true ) ) {
                $pull_mode = 'default';
            }
            if ( 'default' === $pull_mode ) {
                delete_transient( 'sheetsync_pull_mode_' . $connection_id );
            }

            $result = ( new SheetSync_Sync_Coordinator() )->start(
                $connection_id,
                array_merge(
                    array(
                        'triggered_by'  => $start_args['triggered_by'] ?? 'manual',
                        'mode'          => $mode_override,
                        'sync_strategy' => is_string( $strategy_raw ) ? $strategy_raw : 'smart',
                        'pull_mode'     => $pull_mode,
                    ),
                    $start_args
                )
            );
            if ( ! empty( $result['async'] ) || ! empty( $result['show_progress'] ) ) {
                return array(
                    'success'             => (bool) ( $result['success'] ?? false ),
                    'processed'           => (int) ( $result['processed'] ?? 0 ),
                    'skipped'             => (int) ( $result['skipped'] ?? 0 ),
                    'errors'              => (int) ( $result['errors'] ?? 0 ),
                    'message'             => (string) ( $result['message'] ?? '' ),
                    'job_id'              => (int) ( $result['job_id'] ?? 0 ),
                    'mode'                => (string) ( $result['mode'] ?? 'incremental' ),
                    'async'               => ! empty( $result['async'] ),
                    'show_progress'       => ! empty( $result['show_progress'] ),
                    'total_estimate'      => (int) ( $result['total_estimate'] ?? 0 ),
                    'progress_pct'        => $result['progress_pct'] ?? null,
                    'eta_minutes'         => $result['eta_minutes'] ?? null,
                    'scheduler_warning'   => (bool) ( $result['scheduler_warning'] ?? false ),
                    'scheduler_message'   => (string) ( $result['scheduler_message'] ?? '' ),
                    'scheduler_tools_url' => (string) ( $result['scheduler_tools_url'] ?? '' ),
                );
            }
            if ( isset( $result['processed'] ) ) {
                return array(
                    'success'   => (bool) ( $result['success'] ?? false ),
                    'processed' => (int) ( $result['processed'] ?? 0 ),
                    'skipped'   => (int) ( $result['skipped'] ?? 0 ),
                    'errors'    => (int) ( $result['errors'] ?? 0 ),
                    'message'   => (string) ( $result['message'] ?? '' ),
                    'job_id'    => (int) ( $result['job_id'] ?? 0 ),
                    'async'     => false,
                );
            }
            if ( ! ( $result['success'] ?? false ) ) {
                $fail = array(
                    'success' => false,
                    'message' => (string) ( $result['message'] ?? __( 'Sync failed.', 'sheetsync-for-woocommerce' ) ),
                );
                foreach ( array( 'job_id', 'async', 'show_progress', 'processed', 'total_estimate', 'errors', 'mode', 'requires_confirmation', 'confirmation_type', 'scheduler_warning', 'scheduler_message', 'scheduler_tools_url' ) as $key ) {
                    if ( isset( $result[ $key ] ) ) {
                        $fail[ $key ] = $result[ $key ];
                    }
                }
                return $fail;
            }
        }

        $is_pro_install = ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() )
            || ( defined( 'SHEETSYNC_IS_PRO' ) && SHEETSYNC_IS_PRO );

        // WooCommerce → Google Sheets (and the push half of two-way) is implemented in Pro (bulk export).
        if ( in_array( $direction, array( 'wc_to_sheets', 'two_way' ), true ) ) {
            if ( ! $is_pro_install || ! class_exists( 'SheetSync_Bulk_Processor' ) ) {
                $msg = __( 'Pushing WooCommerce products to Google Sheets requires SheetSync Pro.', 'sheetsync-for-woocommerce' );
                SheetSync_Logger::log( $connection_id, 'manual', 'error', 0, 0, $msg );
                return array(
                    'success'   => false,
                    'processed' => 0,
                    'skipped'   => 0,
                    'errors'    => 0,
                    'message'   => $msg,
                );
            }

            if ( 'two_way' === $direction ) {
                // Legacy full push after pull overwrote sheet data — opt-in only.
                if ( sheetsync_legacy_two_way_full_push() ) {
                    $pull = $this->sync_sheet_to_wc( $conn, $connection_id );
                    if ( ! $pull['success'] ) {
                        return $pull;
                    }
                    $push = SheetSync_Bulk_Processor::export_connection_to_sheet( $connection_id );
                    return array(
                        'success'   => $push['success'],
                        'processed' => (int) ( $pull['processed'] ?? 0 ) + (int) ( $push['processed'] ?? 0 ),
                        'skipped'   => (int) ( $pull['skipped'] ?? 0 ) + (int) ( $push['skipped'] ?? 0 ),
                        'errors'    => (int) ( $pull['errors'] ?? 0 ) + (int) ( $push['errors'] ?? 0 ),
                        'message'   => trim( (string) ( $pull['message'] ?? '' ) . ' | ' . (string) ( $push['message'] ?? '' ) ),
                    );
                }
                // Job engine handles two-way; legacy path disabled by default.
                return array(
                    'success' => false,
                    'message' => __( 'Enable the SheetSync job engine (default) or legacy full push for two-way sync.', 'sheetsync-for-woocommerce' ),
                );
            }

            return SheetSync_Bulk_Processor::export_connection_to_sheet( $connection_id );
        }

        // ══════════════════════════════════════════════════════════════════
        // PRODUCTS: Google Sheets → WooCommerce (default / free tier path)
        // ══════════════════════════════════════════════════════════════════

        return $this->sync_sheet_to_wc( $conn, $connection_id );
    }

    /**
     * Route order sync to the correct method based on sync direction.
     *
     * - wc_to_sheets: Push all matching orders to Google Sheet
     * - sheets_to_wc: Read sheet and update order statuses in WooCommerce
     * - two_way:      Pull sheet edits first, then push orders to sheet
     */
    private function run_order_sync( object $conn, int $connection_id, string $direction ): array {
        if ( ! class_exists( 'SheetSync_Order_Sync' ) ) {
            return array(
                'success' => false,
                'message' => __( 'Order sync module not loaded.', 'sheetsync-for-woocommerce' ),
            );
        }

        if ( sheetsync_order_job_engine() && class_exists( 'SheetSync_Sync_Coordinator', false ) ) {
            $result = ( new SheetSync_Sync_Coordinator() )->start(
                $connection_id,
                array(
                    'triggered_by'         => 'manual',
                    'order_sync_direction' => $direction,
                )
            );

            if ( ! empty( $result['async'] ) || ! empty( $result['show_progress'] ) ) {
                return array(
                    'success'        => (bool) ( $result['success'] ?? false ),
                    'processed'      => (int) ( $result['processed'] ?? 0 ),
                    'skipped'        => (int) ( $result['skipped'] ?? 0 ),
                    'errors'         => (int) ( $result['errors'] ?? 0 ),
                    'message'        => (string) ( $result['message'] ?? '' ),
                    'job_id'         => (int) ( $result['job_id'] ?? 0 ),
                    'async'          => ! empty( $result['async'] ),
                    'show_progress'  => ! empty( $result['show_progress'] ),
                    'total_estimate' => (int) ( $result['total_estimate'] ?? 0 ),
                );
            }

            $errors = (int) ( $result['errors'] ?? 0 );
            if ( 0 === $errors || (int) ( $result['processed'] ?? 0 ) > 0 ) {
                self::touch_connection_sync_time( $connection_id );
            }

            return array(
                'success'   => (bool) ( $result['success'] ?? false ),
                'partial'   => $errors > 0 && (int) ( $result['processed'] ?? 0 ) > 0,
                'processed' => (int) ( $result['processed'] ?? 0 ),
                'skipped'   => (int) ( $result['skipped'] ?? 0 ),
                'errors'    => $errors,
                'message'   => (string) ( $result['message'] ?? '' ),
                'job_id'    => (int) ( $result['job_id'] ?? 0 ),
            );
        }

        $order_sync = new SheetSync_Order_Sync();

        if ( 'wc_to_sheets' === $direction ) {
            $result = $order_sync->sync_orders_to_sheet( $conn );
            $errors = (int) ( $result['errors'] ?? 0 );
            $msg    = sprintf(
                'Orders → Sheet: Processed %d | Skipped %d | Errors %d',
                (int) ( $result['processed'] ?? 0 ),
                (int) ( $result['skipped'] ?? 0 ),
                $errors
            );
            if ( ! empty( $result['timed_out'] ) ) {
                $msg .= ' | ' . __( 'Stopped early (time limit). Run Sync again to continue.', 'sheetsync-for-woocommerce' );
            }
            SheetSync_Logger::log( $connection_id, 'manual', $errors > 0 ? 'partial' : 'success', (int) ( $result['processed'] ?? 0 ), (int) ( $result['skipped'] ?? 0 ), $msg, $errors );
            if ( 0 === $errors || (int) ( $result['processed'] ?? 0 ) > 0 ) {
                self::touch_connection_sync_time( $connection_id );
            }
            self::maybe_refresh_order_sheet_ux( $conn );
            return array(
                'success'   => 0 === $errors && empty( $result['timed_out'] ),
                'partial'   => ! empty( $result['partial'] ) || ! empty( $result['timed_out'] ) || ( $errors > 0 && (int) ( $result['processed'] ?? 0 ) > 0 ),
                'processed' => (int) ( $result['processed'] ?? 0 ),
                'skipped'   => (int) ( $result['skipped'] ?? 0 ),
                'errors'    => $errors,
                'message'   => $msg,
            );
        }

        if ( 'sheets_to_wc' === $direction ) {
            $result = $order_sync->pull_all_order_statuses_from_sheets();
            $msg    = sprintf(
                'Sheet → Orders: Processed %d | Skipped %d | Errors %d',
                (int) ( $result['processed'] ?? 0 ),
                (int) ( $result['skipped'] ?? 0 ),
                (int) ( $result['errors'] ?? 0 )
            );
            $errors = (int) ( $result['errors'] ?? 0 );
            SheetSync_Logger::log( $connection_id, 'manual', $errors > 0 ? 'partial' : 'success', (int) ( $result['processed'] ?? 0 ), (int) ( $result['skipped'] ?? 0 ), $msg, $errors );
            if ( 0 === $errors || (int) ( $result['processed'] ?? 0 ) > 0 ) {
                self::touch_connection_sync_time( $connection_id );
            }
            self::maybe_refresh_order_sheet_ux( $conn );
            return array(
                'success'   => 0 === $errors,
                'partial'   => $errors > 0 && (int) ( $result['processed'] ?? 0 ) > 0,
                'processed' => (int) ( $result['processed'] ?? 0 ),
                'skipped'   => (int) ( $result['skipped'] ?? 0 ),
                'errors'    => $errors,
                'message'   => $msg,
            );
        }

        // two_way: pull ALL order tabs first (sheet edits + row moves), then refresh this tab from WooCommerce.
        $pull_result = $order_sync->pull_all_order_statuses_from_sheets();
        $push_result = $order_sync->sync_orders_to_sheet( $conn );

        $total_processed = (int) ( $push_result['processed'] ?? 0 ) + (int) ( $pull_result['processed'] ?? 0 );
        $total_skipped   = (int) ( $push_result['skipped'] ?? 0 ) + (int) ( $pull_result['skipped'] ?? 0 );
        $total_errors    = (int) ( $push_result['errors'] ?? 0 ) + (int) ( $pull_result['errors'] ?? 0 );
        $msg = sprintf(
            'Two-Way: Pull %d + Push %d | Skipped %d | Errors %d',
            (int) ( $pull_result['processed'] ?? 0 ),
            (int) ( $push_result['processed'] ?? 0 ),
            $total_skipped,
            $total_errors
        );
        SheetSync_Logger::log( $connection_id, 'manual', $total_errors > 0 ? 'partial' : 'success', $total_processed, $total_skipped, $msg, $total_errors );

        if ( 0 === $total_errors || $total_processed > 0 ) {
            self::touch_connection_sync_time( $connection_id );
        }

        self::maybe_refresh_order_sheet_ux( $conn );

        return array(
            'success'         => 0 === $total_errors,
            'partial'         => $total_errors > 0 && $total_processed > 0,
            'processed'       => $total_processed,
            'skipped'         => $total_skipped,
            'errors'          => $total_errors,
            'message'         => $msg,
            'connection_type' => $conn->connection_type ?? 'orders',
            'push_processed'  => (int) ( $push_result['processed'] ?? 0 ),
            'pull_processed'  => (int) ( $pull_result['processed'] ?? 0 ),
        );
    }

    /**
     * Record last manual / scheduled sync time on a connection.
     */
    public static function touch_connection_sync_time( int $connection_id ): void {
        global $wpdb;

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "{$wpdb->prefix}sheetsync_connections",
            array( 'last_sync_at' => current_time( 'mysql' ) ),
            array( 'id' => $connection_id ),
            array( '%s' ),
            array( '%d' )
        );
        wp_cache_delete( "sheetsync_connection_{$connection_id}", 'sheetsync' );
    }

    /**
     * Refresh column C dropdown and info row after order sync (non-fatal).
     *
     * @param object $conn Connection row.
     */
    private static function maybe_refresh_order_sheet_ux( object $conn ): void {
        if ( ! class_exists( 'SheetSync_Order_Sync', false ) ) {
            return;
        }
        try {
            ( new SheetSync_Order_Sync() )->ensure_sheet_user_experience( $conn );
        } catch ( \Throwable $e ) {
            // Non-fatal.
        }
    }

    /**
     * BUG FIX: This was the missing function.
     * Sheet → WooCommerce: read rows from Google Sheet and update products.
     * Previously this logic was inline in run() but ONLY reached when
     * sync_direction was NOT wc_to_sheets/two_way — which was correct,
     * but the early-return blocks for wc_to_sheets products were placed
     * BEFORE the field map load, causing the sheets_to_wc path to be
     * skipped entirely when the first block matched a different condition.
     *
     * Now cleanly separated into its own method.
     */
    private function sync_sheet_to_wc( object $conn, int $connection_id ): array {

        // Increase PHP limits for large sheets — silently ignored on restricted hosts.
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.discouraged
        }
        wp_raise_memory_limit( 'admin' );

        // ── Load field maps ───────────────────────────────────────────────
        $maps = SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn );
        if ( empty( $maps ) ) {
            $msg = __( 'No field mappings configured for this connection.', 'sheetsync-for-woocommerce' );
            SheetSync_Logger::log( $connection_id, 'manual', 'error', 0, 0, $msg );
            return array(
                'success' => false,
                'message' => $msg,
            );
        }

        $map_repo = class_exists( 'SheetSync_Product_Map_Repository', false )
            ? new SheetSync_Product_Map_Repository()
            : null;
        if ( $map_repo ) {
            $map_repo->migrate_legacy_hashes( $connection_id );
        }

        $offset_key = 'sheetsync_legacy_pull_offset_' . $connection_id;
        $offset     = (int) get_transient( $offset_key );
        if ( $offset < 0 ) {
            $offset = 0;
        }

        $state_repo  = class_exists( 'SheetSync_Sync_State_Repository', false )
            ? new SheetSync_Sync_State_Repository()
            : null;
        $legacy_lock = false;
        $hold_lock   = false;

        if ( $state_repo ) {
            $state_row = $state_repo->get( $connection_id );
            if ( $state_repo->is_locked( $connection_id ) && ! empty( $state_row->current_job_id ) ) {
                $msg = __( 'A background sync is already running for this connection. Please wait for it to finish.', 'sheetsync-for-woocommerce' );
                SheetSync_Logger::log( $connection_id, 'manual', 'error', 0, 0, $msg );
                return array(
                    'success' => false,
                    'message' => $msg,
                );
            }

            $is_resume = $offset > 0;
            if ( $is_resume && $state_repo->is_locked( $connection_id ) ) {
                $state_repo->extend_lock( $connection_id, 360 );
                $legacy_lock = true;
            } elseif ( ! $state_repo->acquire_lock( $connection_id, 360 ) ) {
                $msg = __( 'Another sync is already running for this connection.', 'sheetsync-for-woocommerce' );
                SheetSync_Logger::log( $connection_id, 'manual', 'error', 0, 0, $msg );
                return array(
                    'success' => false,
                    'message' => $msg,
                );
            } else {
                $legacy_lock = true;
            }
        }

        try {

        $mode       = SheetSync_Sync_Mode::resolve( $connection_id );
        $hasher     = class_exists( 'SheetSync_Hash_Normalizer', false ) ? new SheetSync_Hash_Normalizer() : null;
        $client     = new SheetSync_Sheets_Client();
        $updater    = new SheetSync_Product_Updater( $maps );
        $last_col   = SheetSync_Field_Mapper::max_column_letter( $maps );
        $header_row = max( 1, (int) $conn->header_row );
        $first_row  = $header_row + 1;
        $batch_rows = 200;
        $processed  = $skipped = $errors = 0;
        $sync_start = time();
        $deadline   = $sync_start + 240;
        $timed_out  = false;
        $row_stats  = array(
            'simple'    => 0,
            'parent'    => 0,
            'variation' => 0,
        );

        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            if ( $offset > 0 ) {
                SheetSync_Import_Row_Service::continue_import_run( $connection_id );
            } else {
                SheetSync_Import_Row_Service::begin_import_run( $connection_id );
            }
        }

        $hash_maps = $hasher ? $hasher->maps_for_hash( $maps ) : $maps;

        while ( true ) {
            if ( time() >= $deadline ) {
                $timed_out = true;
                break;
            }

            $start_row = $first_row + $offset;
            $end_row   = $start_row + $batch_rows - 1;
            $range     = "{$conn->sheet_name}!A{$start_row}:{$last_col}{$end_row}";

            $chunk          = null;
            $read_attempts  = max( 1, min( 5, (int) apply_filters( 'sheetsync_legacy_sheet_read_attempts', 3 ) ) );
            $read_error     = null;
            for ( $read_try = 0; $read_try < $read_attempts; ++$read_try ) {
                try {
                    $chunk = $client->get_rows( $conn->spreadsheet_id, $range );
                    $read_error = null;
                    break;
                } catch ( Exception $e ) {
                    $read_error = $e;
                    if ( $read_try < $read_attempts - 1 ) {
                        sleep( min( 8, 2 * ( $read_try + 1 ) ) );
                    }
                }
            }

            if ( null === $chunk ) {
                $detail = $read_error instanceof Exception ? $read_error->getMessage() : __( 'Could not read Google Sheet.', 'sheetsync-for-woocommerce' );
                SheetSync_Logger::log( $connection_id, 'manual', 'error', 0, 0, $detail );
                if ( $offset > 0 ) {
                    set_transient( $offset_key, $offset, HOUR_IN_SECONDS );
                    if ( $state_repo && $legacy_lock ) {
                        $state_repo->extend_lock( $connection_id, 360 );
                        $hold_lock = true;
                    }
                    return array(
                        'success'      => false,
                        'partial'      => true,
                        'processed'    => $processed,
                        'skipped'      => $skipped,
                        'errors'       => $errors + 1,
                        'message'      => $detail . ' | ' . __( 'Stopped early (sheet read failed). Run Sync again to continue.', 'sheetsync-for-woocommerce' ),
                        'user_message' => sheetsync_format_sync_result_message( $processed, $skipped, $errors + 1, $row_stats, $pull_mode ),
                        'row_stats'    => $row_stats,
                        'pull_mode'    => $pull_mode,
                        'mode'         => $mode,
                    );
                }
                return array(
                    'success' => false,
                    'message' => $detail,
                );
            }

            if ( empty( $chunk ) ) {
                break;
            }

            $fetched = count( $chunk );
            $tagged    = array();
            foreach ( $chunk as $i => $row ) {
                $tagged[] = array(
                    'sheet_row' => $start_row + $i,
                    'row'       => $row,
                );
            }
            if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                $tagged = SheetSync_Import_Row_Service::prepare_tagged_rows_for_import( $tagged, $updater, $start_row );
                SheetSync_Import_Row_Service::warm_sku_lookup_for_tagged_rows( $updater, $tagged );
            } elseif ( class_exists( 'SheetSync_Variation_Sync', false ) ) {
                $tagged = SheetSync_Variation_Sync::sort_rows_parents_first( $chunk, $updater, $start_row );
            }

            $chunk_advance = 0;

            foreach ( $tagged as $item ) {
                if ( time() >= $deadline ) {
                    $timed_out = true;
                    break 2;
                }

                $row           = $item['row'];
                $sheet_row_num = (int) $item['sheet_row'];

                if ( empty( array_filter( $row, static fn( $v ) => $v !== '' && $v !== null ) ) ) {
                    $chunk_advance = max( $chunk_advance, $sheet_row_num - $start_row + 1 );
                    continue;
                }
                $external_key = $hasher
                    ? $hasher->external_key_from_row( $row, $maps, $updater, $sheet_row_num )
                    : '';
                $row_hash  = $hasher ? $hasher->sheet_hash( $row, $hash_maps ) : md5( implode( '|', $row ) );
                $peek_data = $updater->extract_data( $row );

                $import_skip = function_exists( 'sheetsync_importable_product_row_skip_reason' )
                    ? sheetsync_importable_product_row_skip_reason( $peek_data )
                    : null;
                if ( is_string( $import_skip ) && $import_skip !== '' ) {
                    if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                        SheetSync_Import_Row_Service::log_row_result(
                            $connection_id,
                            $sheet_row_num,
                            $peek_data,
                            'error',
                            $import_skip
                        );
                    }
                    ++$errors;
                    $chunk_advance = max( $chunk_advance, $sheet_row_num - $start_row + 1 );
                    continue;
                }

                if ( class_exists( 'SheetSync_Import_Row_Service', false )
                    && SheetSync_Import_Row_Service::is_later_duplicate_sheet_sku( $connection_id, $sheet_row_num, $peek_data ) ) {
                    SheetSync_Import_Row_Service::log_row_result(
                        $connection_id,
                        $sheet_row_num,
                        $peek_data,
                        'skipped',
                        __( 'Duplicate SKU in sheet — first row wins.', 'sheetsync-for-woocommerce' )
                    );
                    ++$skipped;
                    continue;
                }

                if ( class_exists( 'SheetSync_Import_Row_Service', false )
                    && SheetSync_Import_Row_Service::is_later_duplicate_sheet_title( $connection_id, $sheet_row_num, $peek_data ) ) {
                    SheetSync_Import_Row_Service::log_row_result(
                        $connection_id,
                        $sheet_row_num,
                        $peek_data,
                        'skipped',
                        __( 'Duplicate title in sheet — first row wins.', 'sheetsync-for-woocommerce' )
                    );
                    ++$skipped;
                    continue;
                }

                $ambiguous_sku_msg = class_exists( 'SheetSync_Import_Row_Service', false )
                    ? SheetSync_Import_Row_Service::ambiguous_sku_import_message( $peek_data, $connection_id )
                    : null;
                if ( is_string( $ambiguous_sku_msg ) && $ambiguous_sku_msg !== '' ) {
                    SheetSync_Import_Row_Service::log_row_result(
                        $connection_id,
                        $sheet_row_num,
                        $peek_data,
                        'error',
                        $ambiguous_sku_msg
                    );
                    ++$errors;
                    continue;
                }

                if ( class_exists( 'SheetSync_Import_Row_Service', false )
                    && SheetSync_Import_Row_Service::is_ambiguous_title_match( $peek_data, $connection_id ) ) {
                    SheetSync_Import_Row_Service::log_row_result(
                        $connection_id,
                        $sheet_row_num,
                        $peek_data,
                        'error',
                        __( 'Multiple WooCommerce products share this title — map Product ID or use a unique SKU.', 'sheetsync-for-woocommerce' )
                    );
                    ++$errors;
                    continue;
                }

                $wc_product = ( $map_repo && class_exists( 'SheetSync_Import_Row_Service', false ) )
                    ? SheetSync_Import_Row_Service::resolve_wc_product(
                        $connection_id,
                        $external_key,
                        $sheet_row_num,
                        $row,
                        $updater,
                        $map_repo
                    )
                    : ( ! empty( $peek_data['_sku'] ) ? wc_get_product( (int) wc_get_product_id_by_sku( $peek_data['_sku'] ) ) : null );

                $skip_reason = ( $map_repo && class_exists( 'SheetSync_Import_Row_Service', false ) )
                    ? SheetSync_Import_Row_Service::evaluate_incremental_skip(
                        $mode,
                        $conn,
                        $external_key,
                        $row_hash,
                        $peek_data,
                        $row,
                        $wc_product instanceof WC_Product ? $wc_product : null,
                        $updater,
                        $map_repo,
                        $maps,
                        $connection_id
                    )
                    : null;

                if ( in_array( $skip_reason, array( 'matched', 'unchanged' ), true ) ) {
                    if ( $external_key !== '' && $map_repo && $wc_product instanceof WC_Product ) {
                        SheetSync_Import_Row_Service::store_row_hash(
                            $connection_id,
                            $map_repo,
                            $external_key,
                            $row_hash,
                            $sheet_row_num,
                            $peek_data,
                            false,
                            $wc_product
                        );
                    }
                    ++$skipped;
                    continue;
                }

                if ( 'wc_edited' === $skip_reason ) {
                    SheetSync_Import_Row_Service::handle_wc_edited_pull_skip(
                        $conn,
                        $connection_id,
                        $sheet_row_num,
                        $peek_data,
                        $wc_product instanceof WC_Product ? $wc_product : null,
                        0
                    );
                    ++$skipped;
                    continue;
                }

                if ( ! apply_filters( 'sheetsync_should_process_sheet_row', true, $peek_data, $row, $conn, $connection_id ) ) {
                    ++$skipped;
                    continue;
                }

                $result = SheetSync_Import_Row_Service::apply_sheet_row_to_wc(
                    $conn,
                    $connection_id,
                    $mode,
                    $external_key,
                    $row_hash,
                    $peek_data,
                    $row,
                    $sheet_row_num,
                    $wc_product instanceof WC_Product ? $wc_product : null,
                    $updater,
                    $map_repo
                );
                match ( $result ) {
                    'updated' => ++$processed,
                    'queued'  => ++$skipped,
                    'skipped' => ++$skipped,
                    'error'   => ++$errors,
                    default   => ++$skipped,
                };

                if ( 'error' === $result && class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                    SheetSync_Import_Row_Service::log_row_result( $connection_id, $sheet_row_num, $peek_data, 'error' );
                }

                if ( 'updated' === $result ) {
                    $kind = sheetsync_classify_sync_row( $peek_data );
                    if ( isset( $row_stats[ $kind ] ) ) {
                        ++$row_stats[ $kind ];
                    }
                }

                if ( 'updated' === $result && $external_key !== '' && $map_repo ) {
                    if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                        $pid           = SheetSync_Product_Map_Repository::resolve_import_product_id( $peek_data, null, $connection_id );
                        $product_after = $pid > 0 ? wc_get_product( $pid ) : null;
                        SheetSync_Import_Row_Service::store_row_hash(
                            $connection_id,
                            $map_repo,
                            $external_key,
                            $row_hash,
                            $sheet_row_num,
                            $peek_data,
                            true,
                            $product_after instanceof WC_Product ? $product_after : null
                        );
                    } else {
                        $pid = ! empty( $peek_data['_sku'] ) ? (int) wc_get_product_id_by_sku( $peek_data['_sku'] ) : 0;
                        $map_repo->set_sheet_hash(
                            $connection_id,
                            $external_key,
                            $row_hash,
                            array(
                                'sheet_row'      => $sheet_row_num,
                                'product_id'     => $pid,
                                'last_pulled_at' => current_time( 'mysql', true ),
                            )
                        );
                    }
                }

                $chunk_advance = max( $chunk_advance, $sheet_row_num - $start_row + 1 );
            }

            if ( $timed_out ) {
                $offset += max( 0, $chunk_advance );
                break;
            }

            if ( $fetched < $batch_rows ) {
                break;
            }
            $offset += $batch_rows;

            if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                SheetSync_Import_Row_Service::run_mid_batch_deferred_retries( $connection_id, $maps );
                SheetSync_Import_Row_Service::flush_import_run_state( $connection_id );
            }

            if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
                wp_cache_flush_group( 'sheetsync' );
            }
        }

        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            $retry = SheetSync_Import_Row_Service::retry_deferred_import_passes( $connection_id, $maps, true );
            $processed += (int) ( $retry['processed'] ?? 0 );
            $skipped   += (int) ( $retry['skipped'] ?? 0 );
            $errors    += (int) ( $retry['errors'] ?? 0 );
            if ( $timed_out ) {
                SheetSync_Import_Row_Service::flush_import_run_state( $connection_id );
            } else {
                SheetSync_Import_Row_Service::end_import_run( $connection_id );
            }
        }

        if ( $timed_out ) {
            set_transient( $offset_key, $offset, HOUR_IN_SECONDS );
            if ( $state_repo ) {
                $state_repo->extend_lock( $connection_id, 360 );
                $hold_lock = true;
            }
        } else {
            delete_transient( $offset_key );
        }

        $next_sheet_row = $timed_out ? ( $first_row + $offset ) : 0;

        // ── Update last sync timestamp ────────────────────────────────────
        global $wpdb;
        $wpdb->update(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "{$wpdb->prefix}sheetsync_connections",
            array( 'last_sync_at' => current_time( 'mysql' ) ),
            array( 'id' => $connection_id ),
            array( '%s' ),
            array( '%d' )
        );
        wp_cache_delete( "sheetsync_connection_{$connection_id}", 'sheetsync' );
        wp_cache_delete( 'sheetsync_active_connections_products', 'sheetsync' );

        // ── Log result ────────────────────────────────────────────────────
        $status    = $errors > 0 && $processed === 0 ? 'error' : ( $errors > 0 ? 'partial' : 'success' );
        if ( $timed_out ) {
            $status = 'partial';
        }
        $pull_mode = get_transient( 'sheetsync_pull_mode_' . $connection_id );
        if ( ! is_string( $pull_mode ) || ! in_array( $pull_mode, array( 'default', 'create_new', 'update_only' ), true ) ) {
            $pull_mode = 'default';
        }
        $message = sprintf(
            'Processed: %d | Skipped: %d | Errors: %d',
            $processed,
            $skipped,
            $errors
        );
        if ( $timed_out ) {
            $message .= ' | ' . sprintf(
                /* translators: 1: approximate next sheet row, 2: row offset processed */
                __( 'Partial sync — stopped at time limit (next sheet row ~%1$d, offset %2$d). Run Sync again to continue, or use background sync for large catalogs.', 'sheetsync-for-woocommerce' ),
                $next_sheet_row,
                $offset
            );
        }
        $user_message = sheetsync_format_sync_result_message(
            $processed,
            $skipped,
            $errors,
            $row_stats,
            $pull_mode,
            array(
                'timed_out' => $timed_out,
                'next_row'  => $next_sheet_row,
            )
        );

        SheetSync_Logger::log( $connection_id, 'manual', $status, $processed, $skipped, $message, $errors );

        return array(
            'success'      => 'success' === $status,
            'partial'      => 'partial' === $status,
            'processed'    => $processed,
            'skipped'      => $skipped,
            'errors'       => $errors,
            'message'      => $message,
            'user_message' => $user_message,
            'row_stats'    => $row_stats,
            'pull_mode'    => $pull_mode,
            'mode'         => $mode,
            'next_row'     => $next_sheet_row,
            'resume_offset'=> $timed_out ? $offset : 0,
        );

        } finally {
            if ( $legacy_lock && $state_repo && ! $hold_lock ) {
                $state_repo->release_lock( $connection_id );
            }
        }
    }

    /**
     * Sync ALL WooCommerce products → Google Sheet.
     * Available in SheetSync Pro — https://devnajmus.com/sheetsync/pricing
     *
     * @deprecated This method is not available in the free version.
     */
    private function sync_wc_to_sheet( object $conn ): array {
        return array(
            'success' => false,
            'message' => __( 'WC → Sheets sync is available in SheetSync Pro.', 'sheetsync-for-woocommerce' ),
        );
    }

    /**
     * Placeholder kept for reference — actual implementation in Pro.
     * @codeCoverageIgnore
     */
    public static function get_active_connections( string $type = 'products' ): array {
        global $wpdb;

        $cache_key = "sheetsync_active_connections_{$type}";
        $results   = wp_cache_get( $cache_key, 'sheetsync' );

        if ( false === $results ) {
            if ( $type === 'orders' ) {
                $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}sheetsync_connections
                         WHERE status = %s
                         AND (connection_type = %s OR connection_type LIKE %s)",
                        'active',
                        'orders',
                        $wpdb->esc_like( 'orders_' ) . '%'
                    )
                );
            } else {
                $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}sheetsync_connections
                         WHERE status = 'active' AND connection_type = %s",
                        $type
                    )
                );
            }
            wp_cache_set( $cache_key, $results, 'sheetsync', MINUTE_IN_SECONDS * 5 );
        }

        return $results ?: array();
    }

    /**
     * Validate a connection type value.
     */
    public static function is_valid_connection_type( string $type ): bool {
        $valid_statuses = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'draft' );
        if ( in_array( $type, array( 'products', 'orders' ), true ) ) return true;
        if ( str_starts_with( $type, 'orders_' ) ) {
            $status = substr( $type, 7 );
            return in_array( $status, $valid_statuses, true );
        }
        return false;
    }

    public static function get_order_status_filter( string $connection_type ): ?string {
        if ( $connection_type === 'orders' ) return null;
        if ( str_starts_with( $connection_type, 'orders_' ) ) {
            return substr( $connection_type, 7 );
        }
        return null;
    }

    public static function is_orders_type( string $connection_type ): bool {
        return $connection_type === 'orders' || str_starts_with( $connection_type, 'orders_' );
    }

    public static function get_connection( int $id ): ?object {
        global $wpdb;

        $cache_key = "sheetsync_connection_{$id}";
        $result    = wp_cache_get( $cache_key, 'sheetsync' );

        if ( false === $result ) {
            $result = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}sheetsync_connections WHERE id = %d",
                    $id
                )
            );
            wp_cache_set( $cache_key, $result, 'sheetsync', MINUTE_IN_SECONDS * 5 );
        }

        return $result ?: null;
    }

    public static function save_connection( array $data, ?int $id = null ): int {
        global $wpdb;
        $table = "{$wpdb->prefix}sheetsync_connections";

        /*
         * Connection type & sync direction come from $data (admin form).
         * Premium directions (wc_to_sheets, two_way) and non-product types are
         * only persisted when Pro is active — otherwise the free tier keeps
         * products + sheets_to_wc (WordPress.org behaviour).
         */
        if (
            ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() )
            || ( defined( 'SHEETSYNC_IS_PRO' ) && SHEETSYNC_IS_PRO )
        ) {
            $raw_type = sanitize_text_field( $data['connection_type'] ?? 'products' );
            $requested_type      = self::is_valid_connection_type( $raw_type ) ? $raw_type : 'products';
            $raw_dir               = sanitize_text_field( $data['sync_direction'] ?? 'sheets_to_wc' );
            $requested_direction = in_array( $raw_dir, array( 'sheets_to_wc', 'wc_to_sheets', 'two_way' ), true )
                ? $raw_dir
                : 'sheets_to_wc';
        } else {
            $requested_type      = 'products';
            $requested_direction = 'sheets_to_wc';
        }

        $clean = array(
            'name'            => sanitize_text_field( $data['name'] ?? '' ),
            'spreadsheet_id'  => sanitize_text_field( $data['spreadsheet_id'] ?? '' ),
            'sheet_name'      => trim( sanitize_text_field( $data['sheet_name'] ?? 'Sheet1' ) ),
            'header_row'      => max( 1, absint( $data['header_row'] ?? 1 ) ),
            'status'          => in_array( $data['status'] ?? 'active', array( 'active', 'inactive' ), true )
                ? $data['status']
                : 'active',
            'connection_type' => $requested_type,
            'sync_direction'  => $requested_direction,
        );

        if ( $id ) {
            $wpdb->update( $table, $clean, array( 'id' => $id ), null, array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            wp_cache_delete( "sheetsync_connection_{$id}", 'sheetsync' );
            wp_cache_delete( 'sheetsync_active_connections_products', 'sheetsync' );
            wp_cache_delete( 'sheetsync_active_connections_orders', 'sheetsync' );
            return $id;
        }

        $wpdb->insert( $table, $clean );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        wp_cache_delete( 'sheetsync_active_connections_products', 'sheetsync' );
        wp_cache_delete( 'sheetsync_active_connections_orders', 'sheetsync' );
        return (int) $wpdb->insert_id;
    }

    public static function delete_connection( int $id ): void {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}sheetsync_field_maps", array( 'connection_id' => $id ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // Clean up smart-diff hashes and per-connection options for this connection
        delete_option( 'sheetsync_row_hashes_' . $id );
        if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
            ( new SheetSync_Product_Map_Repository() )->delete_for_connection( $id );
        }
        if ( class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
            global $wpdb;
            $wpdb->delete( SheetSync_Schema::table( 'sync_state' ), array( 'connection_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }
        delete_option( 'sheetsync_date_filter_type_'   . $id );
        delete_option( 'sheetsync_date_filter_single_' . $id );
        delete_option( 'sheetsync_date_filter_from_'   . $id );
        delete_option( 'sheetsync_date_filter_to_'     . $id );
        // Remove entries from consolidated sync options and schedules (backwards compatible with older per-connection rows)
        $sync_opts = get_option( 'sheetsync_sync_options', array() );
        if ( isset( $sync_opts[ $id ] ) ) {
            unset( $sync_opts[ $id ] );
            update_option( 'sheetsync_sync_options', $sync_opts, false );
        }
        $schedules = get_option( 'sheetsync_schedules', array() );
        if ( isset( $schedules[ $id ] ) ) {
            unset( $schedules[ $id ] );
            update_option( 'sheetsync_schedules', $schedules, false );
        }
        // Legacy per-connection options cleanup for older installs
        delete_option( 'sheetsync_sync_strategy_' . $id );
        delete_option( 'sheetsync_auto_on_save_'  . $id );
        delete_option( 'sheetsync_schedule_'      . $id );
        delete_option( 'sheetsync_automatic_sync_' . $id );
        delete_option( 'sheetsync_automatic_sync_prefs_' . $id );
        delete_option( 'sheetsync_off_peak_publish_' . $id );
        if ( class_exists( 'SheetSync_Off_Peak_Publish', false ) ) {
            SheetSync_Off_Peak_Publish::unschedule( $id );
        }
        delete_option( 'sheetsync_sync_category_ids_' . $id );
        delete_option( 'sheetsync_hidden_sheet_columns_' . $id );
        delete_option( 'sheetsync_category_block_unknown_' . $id );
        $wpdb->delete( "{$wpdb->prefix}sheetsync_connections", array( 'id' => $id ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        wp_cache_delete( "sheetsync_connection_{$id}", 'sheetsync' );
        wp_cache_delete( 'sheetsync_active_connections_products', 'sheetsync' );
        wp_cache_delete( 'sheetsync_active_connections_orders', 'sheetsync' );
        SheetSync_Field_Mapper::invalidate_cache( $id );
    }

    public static function save_field_maps( int $connection_id, array $field_map ): void {
        global $wpdb;
        $table = "{$wpdb->prefix}sheetsync_field_maps";

        $wpdb->delete( $table, array( 'connection_id' => $connection_id ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        // All features available — use full field list.
        $use_full_field_list = ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() )
            || ( defined( 'SHEETSYNC_IS_PRO' ) && SHEETSYNC_IS_PRO );
        $allowed_defs      = $use_full_field_list
            ? SheetSync_Field_Mapper::get_available_fields( true )
            : SheetSync_Field_Mapper::FREE_FIELDS;
        $allowed_lookup = array_fill_keys( array_keys( $allowed_defs ), true );

        $allowed_lookup['parent_sku']      = true;
        $allowed_lookup['variation_attrs'] = true;

        foreach ( $field_map as $wc_field => $map_data ) {
            $wc_key = is_string( $wc_field ) ? trim( $wc_field ) : '';
            if ( $wc_key === '' || ! isset( $allowed_lookup[ $wc_key ] ) ) {
                continue;
            }

            $column = strtoupper( preg_replace( '/[^A-Za-z]/', '', $map_data['column'] ?? '' ) );

            if ( empty( $column ) && ! empty( $map_data['key'] ) ) {
                $column = 'A';
            }

            if ( empty( $column ) ) {
                continue;
            }

            $wpdb->insert(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $table,
                array(
                    'connection_id' => $connection_id,
                    'wc_field'      => $wc_key,
                    'sheet_column'  => $column,
                    'is_key_field'  => ! empty( $map_data['key'] ) ? 1 : 0,
                ),
                array( '%d', '%s', '%s', '%d' )
            );
        }

        SheetSync_Field_Mapper::invalidate_cache( $connection_id );
    }
}

endif; // class_exists SheetSync_Sync_Engine
