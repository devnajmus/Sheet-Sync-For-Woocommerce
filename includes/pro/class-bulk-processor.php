<?php
/**
 * PRO: Bulk import and export — import products from sheet, export WC products to sheet.
 */

/**
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 * @copyright 2024 MD Najmus Shadat
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Bulk_Processor {

    /**
     * Pro capability for bulk import/export (matches core save + sync engine).
     */
    private static function current_user_has_pro_export(): bool {
        return ( function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro() )
            || ( defined( 'SHEETSYNC_IS_PRO' ) && SHEETSYNC_IS_PRO );
    }

    public function __construct() {
        // Legacy bulk AJAX endpoints removed — sync uses the job engine (sheetsync_manual_sync / sheetsync_drain_job).
    }

    /**
     * Fetch and return the header row from the Sheet.
     */
    public function ajax_get_sheet_headers(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) || ! self::current_user_has_pro_export() ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $conn          = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );
        }

        try {
            $client   = new SheetSync_Sheets_Client();
            $range    = "{$conn->sheet_name}!A{$conn->header_row}:Z{$conn->header_row}";
            $rows     = $client->get_rows( $conn->spreadsheet_id, $range );
            $headers  = $rows[0] ?? array();

            // Filter out empty headers.
            $headers = array_values( array_filter( $headers, fn($h) => trim($h) !== '' ) );

            wp_send_json_success( array(
                'headers'    => $headers,
                'sheet_name' => $conn->sheet_name,
                'header_row' => $conn->header_row,
            ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    // ── Export: WooCommerce → Sheets ──────────────────────────────────────────

    /**
     * Export all mapped WooCommerce products to the connection’s Google Sheet.
     * Clears the tab and product row maps first (same as Full Sync) so re-runs never leave orphan rows.
     *
     * @return array{success: bool, processed: int, skipped: int, errors: int, message: string}
     */
    public static function export_connection_to_sheet( int $connection_id ): array {
        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            $msg = __( 'Connection not found.', 'sheetsync-for-woocommerce' );
            SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, $msg );
            return array(
                'success'   => false,
                'processed' => 0,
                'skipped'   => 0,
                'errors'    => 0,
                'message'   => $msg,
            );
        }

        $prefer_job_above = (int) apply_filters( 'sheetsync_legacy_export_max_products', 500 );
        if ( $prefer_job_above > 0 && function_exists( 'sheetsync_count_exportable_products' ) ) {
            $export_rows = sheetsync_count_exportable_products( $connection_id );
            if ( $export_rows > $prefer_job_above
                && sheetsync_use_job_engine()
                && class_exists( 'SheetSync_Sync_Coordinator', false ) ) {
                return self::delegate_export_to_job_engine( $connection_id );
            }
        }

        $deadline = time() + max( 30, (int) apply_filters( 'sheetsync_legacy_export_deadline_seconds', 240 ) );

        return self::run_catalog_export_batches(
            $connection_id,
            array(
                'deadline'              => $deadline,
                'resume_from_transient' => true,
            )
        );
    }

    /**
     * Transient key for resumable inline/AJAX catalog exports (job engine off or single-request timeout).
     */
    private static function catalog_export_resume_transient_key( int $connection_id ): string {
        return 'sheetsync_catalog_export_resume_' . max( 0, $connection_id );
    }

    /**
     * Export WooCommerce catalog to Google Sheet in bounded batches (shared by sync, AJAX, and legacy paths).
     *
     * @param array<string, mixed> $options {
     *     @type bool              $single_batch          Process one batch per call (AJAX).
     *     @type bool              $init_export           Call begin_catalog_export on a fresh run.
     *     @type bool              $resume_from_transient Restore cursor from a prior partial run.
     *     @type int               $deadline              Unix timestamp stop (0 = no limit).
     *     @type array<string,mixed> $export_cursor        Sheet export sort cursor.
     *     @type int               $start_row             Next 1-based sheet row to write.
     * }
     * @return array{success: bool, processed: int, skipped: int, errors: int, message: string, partial?: bool, done?: bool, export_cursor?: array<string,mixed>, start_row?: int, has_more?: bool}
     */
    public static function run_catalog_export_batches( int $connection_id, array $options = array() ): array {
        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            $msg = __( 'Connection not found.', 'sheetsync-for-woocommerce' );
            SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, $msg );
            return array(
                'success'   => false,
                'processed' => 0,
                'skipped'   => 0,
                'errors'    => 0,
                'message'   => $msg,
                'done'      => true,
            );
        }

        $raw_maps = SheetSync_Field_Mapper::get_maps( $connection_id );
        if ( empty( $raw_maps ) ) {
            $msg = __( 'No field mappings configured for this connection.', 'sheetsync-for-woocommerce' );
            SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, $msg );
            return array(
                'success'   => false,
                'processed' => 0,
                'skipped'   => 0,
                'errors'    => 0,
                'message'   => $msg,
                'done'      => true,
            );
        }

        $maps = self::normalize_maps_for_export( $raw_maps );
        if ( empty( $maps ) ) {
            $msg = __( 'Field mapping has no valid sheet column letters (A–Z). Re-save Field Mapping or run Import Headers.', 'sheetsync-for-woocommerce' );
            SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, $msg );
            return array(
                'success'   => false,
                'processed' => 0,
                'skipped'   => 0,
                'errors'    => 0,
                'message'   => $msg,
                'done'      => true,
            );
        }

        $single_batch          = ! empty( $options['single_batch'] );
        $deadline              = isset( $options['deadline'] ) ? (int) $options['deadline'] : 0;
        $resume_from_transient = ! empty( $options['resume_from_transient'] );
        $resume_key            = self::catalog_export_resume_transient_key( $connection_id );
        $stored                = $resume_from_transient ? get_transient( $resume_key ) : false;
        $stored                = is_array( $stored ) ? $stored : null;

        $export_maps = self::ensure_export_helper_columns( $maps );
        $header_row  = max( 1, (int) $conn->header_row );
        $first_row   = $header_row + 1;
        $per_page    = max( 10, min( 100, (int) apply_filters( 'sheetsync_export_batch_size', 50 ) ) );

        $export_cursor = array();
        $start_row     = 0;
        $run_processed = 0;
        $run_errors    = 0;

        if ( ! empty( $options['export_cursor'] ) && is_array( $options['export_cursor'] ) ) {
            $export_cursor = class_exists( 'SheetSync_Export_Order', false )
                ? SheetSync_Export_Order::normalize_cursor( $options['export_cursor'] )
                : (array) $options['export_cursor'];
            $start_row = max( 0, (int) ( $options['start_row'] ?? 0 ) );
            if ( $stored ) {
                $run_processed = max( 0, (int) ( $stored['processed'] ?? 0 ) );
                $run_errors    = max( 0, (int) ( $stored['errors'] ?? 0 ) );
            }
        } elseif ( $stored ) {
            $export_cursor = class_exists( 'SheetSync_Export_Order', false )
                ? SheetSync_Export_Order::normalize_cursor( (array) ( $stored['export_cursor'] ?? array() ) )
                : (array) ( $stored['export_cursor'] ?? array() );
            $start_row     = max( 0, (int) ( $stored['start_row'] ?? 0 ) );
            $run_processed = max( 0, (int) ( $stored['processed'] ?? 0 ) );
            $run_errors    = max( 0, (int) ( $stored['errors'] ?? 0 ) );
        } else {
            $export_cursor = class_exists( 'SheetSync_Export_Order', false )
                ? SheetSync_Export_Order::normalize_cursor( array() )
                : array();
        }

        $init_export = ! empty( $options['init_export'] );
        if ( ! $init_export ) {
            $init_export = null === $stored
                && empty( $options['export_cursor'] )
                && (int) ( $options['start_row'] ?? 0 ) <= 0;
        }

        if ( $start_row <= 0 ) {
            if ( class_exists( 'SheetSync_Product_Map_Repository', false ) && ! $init_export ) {
                $max_row = ( new SheetSync_Product_Map_Repository() )->get_max_sheet_row( $connection_id );
                $start_row = $max_row >= $first_row ? $max_row + 1 : $first_row;
            } else {
                $start_row = $first_row;
            }
        }

        try {
            if ( $init_export ) {
                delete_transient( $resume_key );
                self::begin_catalog_export( $connection_id, $export_maps );
                $export_cursor = class_exists( 'SheetSync_Export_Order', false )
                    ? SheetSync_Export_Order::normalize_cursor( array() )
                    : array();
                $start_row     = $first_row;
                $run_processed = 0;
                $run_errors    = 0;
            }

            $batch_processed = 0;
            $batch_errors    = 0;
            $has_more        = false;
            $last_success    = true;

            do {
                if ( $deadline > 0 && time() >= $deadline ) {
                    break;
                }

                $result = self::export_connection_batch_to_sheet(
                    $connection_id,
                    $export_cursor,
                    $per_page,
                    $start_row
                );

                $batch_processed = (int) ( $result['processed'] ?? 0 );
                $batch_errors    = (int) ( $result['errors'] ?? 0 );
                $has_more        = ! empty( $result['has_more'] );
                $last_success    = ! empty( $result['success'] );
                $export_cursor   = is_array( $result['export_cursor'] ?? null )
                    ? $result['export_cursor']
                    : $export_cursor;

                $run_processed += $batch_processed;
                $run_errors    += $batch_errors;
                $start_row     += $batch_processed;

                if ( ! $last_success && $batch_processed === 0 && $batch_errors > 0 ) {
                    $has_more = false;
                    break;
                }
            } while ( $has_more && ! $single_batch && ( $deadline <= 0 || time() < $deadline ) );

            $done = ! $has_more && $last_success;

            if ( $done && $run_processed > 0 ) {
                delete_transient( $resume_key );
                if ( class_exists( 'SheetSync_Sync_State_Repository', false ) ) {
                    ( new SheetSync_Sync_State_Repository() )->mark_push_complete( $connection_id, current_time( 'mysql', true ) );
                }
                self::finalize_catalog_export( $connection_id, $export_maps );
                self::finish_export_sheet_styling( $connection_id, $run_processed );
                if ( function_exists( 'sheetsync_log_stale_product_maps' ) ) {
                    sheetsync_log_stale_product_maps( $connection_id );
                }
                SheetSync_Logger::log(
                    $connection_id,
                    'export',
                    $run_errors > 0 ? 'partial' : 'success',
                    $run_processed,
                    0,
                    sprintf(
                        /* translators: %d: number of rows written */
                        __( 'Exported %d products to Google Sheet.', 'sheetsync-for-woocommerce' ),
                        $run_processed
                    ),
                    $run_errors
                );
                return array(
                    'success'       => true,
                    'processed'     => $run_processed,
                    'skipped'       => 0,
                    'errors'        => $run_errors,
                    'message'       => sprintf(
                        /* translators: %d: number of products */
                        __( 'Exported %d products to Google Sheet.', 'sheetsync-for-woocommerce' ),
                        $run_processed
                    ),
                    'done'          => true,
                    'has_more'      => false,
                    'export_cursor' => $export_cursor,
                    'start_row'     => $start_row,
                );
            }

            if ( $has_more ) {
                set_transient(
                    $resume_key,
                    array(
                        'export_cursor' => $export_cursor,
                        'start_row'     => $start_row,
                        'processed'     => $run_processed,
                        'errors'        => $run_errors,
                    ),
                    DAY_IN_SECONDS
                );

                $total_est = function_exists( 'sheetsync_count_exportable_products' )
                    ? sheetsync_count_exportable_products( $connection_id )
                    : 0;
                $remaining = $total_est > 0 ? max( 0, $total_est - $run_processed ) : 0;
                $msg       = $remaining > 0
                    ? sprintf(
                        /* translators: 1: rows exported so far, 2: estimated rows remaining */
                        __( 'Exported %1$d rows so far (%2$d remaining). Run export again to continue.', 'sheetsync-for-woocommerce' ),
                        $run_processed,
                        $remaining
                    )
                    : sprintf(
                        /* translators: %d: rows exported so far */
                        __( 'Exported %1$d rows so far. Run export again to continue.', 'sheetsync-for-woocommerce' ),
                        $run_processed
                    );

                SheetSync_Logger::log( $connection_id, 'export', 'partial', $run_processed, 0, $msg, $run_errors );

                return array(
                    'success'       => $run_processed > 0,
                    'partial'       => true,
                    'processed'     => $run_processed,
                    'skipped'       => 0,
                    'errors'        => $run_errors,
                    'message'       => $msg,
                    'done'          => false,
                    'has_more'      => true,
                    'export_cursor' => $export_cursor,
                    'start_row'     => $start_row,
                );
            }

            if ( $run_processed < 1 && ! $last_success ) {
                $msg = __( 'Export failed — no rows were written to the sheet.', 'sheetsync-for-woocommerce' );
                SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, $msg, max( 1, $run_errors ) );
                return array(
                    'success'   => false,
                    'processed' => 0,
                    'skipped'   => 0,
                    'errors'    => max( 1, $run_errors ),
                    'message'   => $msg,
                    'done'      => true,
                );
            }

            if ( $run_processed < 1 ) {
                delete_transient( $resume_key );
                $msg = __( 'No products to export.', 'sheetsync-for-woocommerce' );
                SheetSync_Logger::log( $connection_id, 'export', 'success', 0, 0, $msg );
                return array(
                    'success'   => true,
                    'processed' => 0,
                    'skipped'   => 0,
                    'errors'    => 0,
                    'message'   => $msg,
                    'done'      => true,
                    'has_more'  => false,
                );
            }

            delete_transient( $resume_key );
            return array(
                'success'       => true,
                'processed'     => $run_processed,
                'skipped'       => 0,
                'errors'        => $run_errors,
                'message'       => sprintf(
                    /* translators: %d: number of products */
                    __( 'Exported %d products to Google Sheet.', 'sheetsync-for-woocommerce' ),
                    $run_processed
                ),
                'done'          => true,
                'has_more'      => false,
                'export_cursor' => $export_cursor,
                'start_row'     => $start_row,
            );
        } catch ( \Throwable $e ) {
            $detail = $e->getMessage();
            SheetSync_Logger::log( $connection_id, 'export', 'error', $run_processed, 0, $detail, max( 1, $run_errors ) );
            if ( $run_processed > 0 || $has_more ) {
                set_transient(
                    $resume_key,
                    array(
                        'export_cursor' => $export_cursor,
                        'start_row'     => $start_row,
                        'processed'     => $run_processed,
                        'errors'        => $run_errors,
                    ),
                    DAY_IN_SECONDS
                );
            }
            return array(
                'success'   => false,
                'partial'   => $run_processed > 0,
                'processed' => $run_processed,
                'skipped'   => 0,
                'errors'    => max( 1, $run_errors ),
                'message'   => $detail,
                'done'      => false,
                'has_more'  => $has_more,
            );
        }
    }

    /**
     * Route large catalog exports through the background job engine.
     *
     * @return array{success: bool, processed: int, skipped: int, errors: int, message: string, queued?: bool}
     */
    private static function delegate_export_to_job_engine( int $connection_id ): array {
        $result = ( new SheetSync_Sync_Coordinator() )->start(
            $connection_id,
            array(
                'triggered_by'  => 'manual',
                'mode'          => 'full',
                'sync_strategy' => 'full',
            )
        );

        $queued  = ! empty( $result['async'] );
        $message = (string) ( $result['message'] ?? __( 'Export queued.', 'sheetsync-for-woocommerce' ) );
        if ( $queued ) {
            $message .= ' ' . __( 'Large catalogs export in the background — check Sync Logs for progress.', 'sheetsync-for-woocommerce' );
        }

        return array(
            'success'   => (bool) ( $result['success'] ?? false ),
            'processed' => (int) ( $result['processed'] ?? 0 ),
            'skipped'   => (int) ( $result['skipped'] ?? 0 ),
            'errors'    => (int) ( $result['errors'] ?? 0 ),
            'message'   => $message,
            'queued'    => $queued,
        );
    }

    /**
     * Product IDs for export — parent (Sorting order) then variations grouped under each parent.
     *
     * @param array<string, mixed> $cursor Export position (see SheetSync_Export_Order).
     * @return int[]
     */
    public static function get_exportable_product_ids( int $connection_id, array $cursor, int $limit ): array {
        if ( ! class_exists( 'SheetSync_Export_Order', false ) ) {
            return array();
        }
        $batch = SheetSync_Export_Order::get_next_export_ids( $connection_id, $cursor, $limit );
        return $batch['ids'];
    }

    /**
     * Export the next batch of products (sort cursor + sheet row from job progress).
     *
     * @param array<string, mixed> $export_cursor
     * @return array{success: bool, processed: int, errors: int, has_more: bool, export_cursor: array<string, mixed>}
     */
    public static function export_connection_batch_to_sheet(
        int $connection_id,
        array $export_cursor,
        int $per_page,
        int $start_row,
        int $deadline = 0
    ): array {
        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            return array(
                'success'        => false,
                'processed'      => 0,
                'errors'         => 1,
                'has_more'       => false,
                'export_cursor'  => $export_cursor,
            );
        }

        $raw_maps = SheetSync_Field_Mapper::get_maps( $connection_id );
        $maps     = self::ensure_export_helper_columns( self::normalize_maps_for_export( $raw_maps ) );
        if ( empty( $maps ) ) {
            return array(
                'success'       => false,
                'processed'     => 0,
                'errors'        => 1,
                'has_more'      => false,
                'export_cursor' => $export_cursor,
            );
        }

        $export_cursor = class_exists( 'SheetSync_Export_Order', false )
            ? SheetSync_Export_Order::normalize_cursor( $export_cursor )
            : $export_cursor;
        $cursor_started = (int) ( $export_cursor['parent_after_menu_order'] ?? -1 ) >= 0
            || (int) ( $export_cursor['pending_parent_id'] ?? 0 ) > 0;
        $cursor_before = $export_cursor;

        if ( $deadline > 0 ) {
            $seconds_left = max( 2, $deadline - time() - 3 );
            $per_page     = min( $per_page, max( 3, (int) floor( $seconds_left / 2 ) ) );
        }

        $batch         = class_exists( 'SheetSync_Export_Order', false )
            ? SheetSync_Export_Order::get_next_export_ids( $connection_id, $export_cursor, $per_page )
            : array( 'ids' => self::get_exportable_product_ids( $connection_id, $export_cursor, $per_page ), 'cursor' => $export_cursor );
        $ids            = $batch['ids'];
        $export_cursor  = $batch['cursor'];
        if ( empty( $ids ) ) {
            return array(
                'success'       => true,
                'processed'     => 0,
                'errors'        => 0,
                'has_more'      => false,
                'export_cursor' => $export_cursor,
            );
        }

        $updater        = new SheetSync_Product_Updater( $maps );
        $client         = new SheetSync_Sheets_Client();
        $end_col        = SheetSync_Field_Mapper::max_column_letter( $maps );
        $map_repo       = new SheetSync_Product_Map_Repository();
        $hasher         = new SheetSync_Hash_Normalizer();
        $hash_maps      = $hasher->maps_for_hash( $maps );
        $values   = array();
        $map_rows = array();
        $row_ids  = array();
        $row_num  = max( (int) $conn->header_row + 1, $start_row );
        $missing_ids = array();

        $products_by_id = function_exists( 'sheetsync_load_products_batch' )
            ? sheetsync_load_products_batch( $ids )
            : array();

        $external_keys = array();
        foreach ( $ids as $product_id ) {
            $product = $products_by_id[ (int) $product_id ] ?? null;
            if ( ! $product instanceof WC_Product ) {
                $product = wc_get_product( $product_id );
            }
            if ( $product instanceof WC_Product ) {
                $external_keys[] = SheetSync_Product_Map_Repository::external_key_for_product( $product );
            }
        }
        if ( ! empty( $external_keys ) ) {
            $map_repo->prefetch_by_external_keys( $connection_id, $external_keys );
        }

        foreach ( $ids as $product_id ) {
            $product = $products_by_id[ (int) $product_id ] ?? wc_get_product( $product_id );
            if ( ! $product ) {
                $missing_ids[] = (int) $product_id;
                continue;
            }

            $row_data   = $updater->product_to_row( $product );
            $values[]   = $row_data;
            $row_ids[]  = $product_id;
            $map_rows[] = array(
                'product_id'      => $product_id,
                'external_key'    => SheetSync_Product_Map_Repository::external_key_for_product( $product ),
                'sheet_row'       => $row_num,
                'wc_hash'         => $hasher->wc_hash( $product, $maps ),
                'sheet_hash'      => $hasher->sheet_hash( $row_data, $hash_maps ),
                'wc_modified_gmt' => sheetsync_get_product_modified_gmt( $product ),
                'last_pushed_at'  => current_time( 'mysql', true ),
            );
            ++$row_num;
        }

        if ( ! empty( $missing_ids ) && function_exists( 'sheetsync_log_skipped_export_products' ) ) {
            sheetsync_log_skipped_export_products( $connection_id, $missing_ids );
        }

        $has_more  = count( $ids ) === $per_page;
        $col_count = SheetSync_Field_Mapper::col_to_index( $end_col ) + 1;
        $last_row  = $start_row + max( 0, count( $values ) - 1 );
        if ( ! empty( $values ) ) {
            $client->ensure_sheet_grid_capacity(
                $conn->spreadsheet_id,
                $conn->sheet_name,
                $last_row + 100,
                $col_count
            );
        }

        $write = self::write_export_batch_with_retry(
            $client,
            $conn,
            $end_col,
            $start_row,
            $values,
            $map_rows,
            $row_ids,
            $connection_id,
            $map_repo
        );

        if ( $write['written'] > 0 && ! apply_filters( 'sheetsync_skip_per_batch_cell_clip', false ) ) {
            $col_count = SheetSync_Field_Mapper::col_to_index( $end_col ) + 1;
            self::apply_export_cell_clip_for_rows(
                $client,
                $conn,
                $start_row,
                (int) $write['written'],
                $col_count
            );
        }

        $batch_failed  = $write['written'] === 0 && $write['errors'] > 0;
        $written_ids   = isset( $write['written_product_ids'] ) && is_array( $write['written_product_ids'] )
            ? array_map( 'intval', $write['written_product_ids'] )
            : array();
        $expected_rows = count( $row_ids );
        $fully_written = ! $batch_failed
            && $write['errors'] === 0
            && $write['written'] === $expected_rows
            && $expected_rows > 0
            && count( $written_ids ) === $expected_rows;

        if ( $batch_failed ) {
            $final_cursor = $cursor_before;
        } elseif ( $fully_written && empty( $missing_ids ) ) {
            $final_cursor = $export_cursor;
        } elseif ( ! empty( $written_ids ) && class_exists( 'SheetSync_Export_Order', false ) ) {
            $final_cursor = SheetSync_Export_Order::cursor_after_written_products(
                $connection_id,
                $cursor_before,
                $ids,
                $written_ids
            );
        } else {
            $final_cursor = $cursor_before;
        }

        return array(
            'success'       => ! $batch_failed,
            'processed'     => $write['written'],
            'errors'        => $write['errors'],
            'has_more'      => $has_more,
            'export_cursor' => $final_cursor,
        );
    }

    /**
     * Write rows to Google Sheets; split batch on API failure; count one error per product that still fails.
     *
     * @param array<int, array<int, mixed>>           $values
     * @param array<int, array<string, mixed>>        $map_rows
     * @param int[]                                     $product_ids
     * @return array{written: int, errors: int, written_product_ids: int[]}
     */
    private static function write_export_batch_with_retry(
        SheetSync_Sheets_Client $client,
        object $conn,
        string $end_col,
        int $start_row,
        array $values,
        array $map_rows,
        array $product_ids,
        int $connection_id,
        SheetSync_Product_Map_Repository $map_repo
    ): array {
        $count = count( $values );
        if ( $count === 0 ) {
            return array( 'written' => 0, 'errors' => 0, 'written_product_ids' => array() );
        }

        try {
            return self::flush_export_rows_to_sheet(
                $client,
                $conn,
                $end_col,
                $start_row,
                $values,
                $map_rows,
                $connection_id,
                $map_repo
            );
        } catch ( Exception $e ) {
            $msg = $e->getMessage();

            if ( $count > 1 && ( sheetsync_api_error_is_grid_limits( $msg ) || sheetsync_api_error_is_quota( $msg ) ) ) {
                $end_row   = $start_row + $count - 1;
                $min_cols  = SheetSync_Field_Mapper::col_to_index( $end_col ) + 1;

                if ( sheetsync_api_error_is_grid_limits( $msg ) ) {
                    $expanded = $client->ensure_sheet_grid_capacity(
                        $conn->spreadsheet_id,
                        $conn->sheet_name,
                        $end_row + 100,
                        $min_cols
                    );
                    if ( $expanded ) {
                        SheetSync_Logger::log(
                            $connection_id,
                            'export',
                            'success',
                            0,
                            0,
                            sprintf(
                                /* translators: 1: row count, 2: column count */
                                __( 'Expanded Google Sheet tab to fit at least %1$d rows and %2$d columns.', 'sheetsync-for-woocommerce' ),
                                $end_row + 100,
                                $min_cols
                            )
                        );
                    }
                }

                if ( sheetsync_api_error_is_quota( $msg ) ) {
                    sheetsync_pause_for_sheets_quota();
                }

                try {
                    return self::flush_export_rows_to_sheet(
                        $client,
                        $conn,
                        $end_col,
                        $start_row,
                        $values,
                        $map_rows,
                        $connection_id,
                        $map_repo
                    );
                } catch ( Exception $retry_e ) {
                    $msg = $retry_e->getMessage();
                }
            }

            if ( sheetsync_api_error_is_quota( $msg ) ) {
                throw new SheetSync_Rate_Limit_Exception( $msg );
            }

            if ( $count === 1 ) {
                self::log_export_product_failure( $connection_id, $start_row, $product_ids, $msg );
                return array( 'written' => 0, 'errors' => 1, 'written_product_ids' => array() );
            }

            if ( sheetsync_api_error_is_grid_limits( $msg ) ) {
                self::log_export_product_failure(
                    $connection_id,
                    $start_row,
                    $product_ids,
                    __( 'Sheet tab is still too small after auto-expand. In Google Sheets: add rows to this tab, or use a new tab, then run Full Sync again.', 'sheetsync-for-woocommerce' )
                );
                return array( 'written' => 0, 'errors' => $count, 'written_product_ids' => array() );
            }

            $mid   = (int) ceil( $count / 2 );
            $left  = self::write_export_batch_with_retry(
                $client,
                $conn,
                $end_col,
                $start_row,
                array_slice( $values, 0, $mid ),
                array_slice( $map_rows, 0, $mid ),
                array_slice( $product_ids, 0, $mid ),
                $connection_id,
                $map_repo
            );
            $right = self::write_export_batch_with_retry(
                $client,
                $conn,
                $end_col,
                $start_row + $mid,
                array_slice( $values, $mid ),
                array_slice( $map_rows, $mid ),
                array_slice( $product_ids, $mid ),
                $connection_id,
                $map_repo
            );

            return array(
                'written'             => $left['written'] + $right['written'],
                'errors'              => $left['errors'] + $right['errors'],
                'written_product_ids' => array_merge(
                    (array) ( $left['written_product_ids'] ?? array() ),
                    (array) ( $right['written_product_ids'] ?? array() )
                ),
            );
        }
    }

    /**
     * @param array<int, array<int, mixed>>    $values
     * @param array<int, array<string, mixed>> $map_rows
     * @return array{written: int, errors: int, written_product_ids: int[]}
     */
    private static function flush_export_rows_to_sheet(
        SheetSync_Sheets_Client $client,
        object $conn,
        string $end_col,
        int $start_row,
        array $values,
        array $map_rows,
        int $connection_id,
        SheetSync_Product_Map_Repository $map_repo
    ): array {
        if ( class_exists( 'SheetSync_Rate_Limiter', false ) ) {
            ( new SheetSync_Rate_Limiter() )->acquire_write();
        }

        $count   = count( $values );
        $end_row = $start_row + $count - 1;
        $range   = "{$conn->sheet_name}!A{$start_row}:{$end_col}{$end_row}";
        $client->set_rows( $conn->spreadsheet_id, $range, $values );
        $map_repo->upsert_many( $connection_id, $map_rows );
        $written_product_ids = array();
        foreach ( $map_rows as $row ) {
            if ( is_array( $row ) && ! empty( $row['product_id'] ) ) {
                $written_product_ids[] = (int) $row['product_id'];
            }
        }
        return array(
            'written'             => $count,
            'errors'              => 0,
            'written_product_ids' => $written_product_ids,
        );
    }

    /**
     * Prevent long description text from visually spilling into empty image columns.
     */
    private static function apply_export_cell_clip_for_rows(
        SheetSync_Sheets_Client $client,
        object $conn,
        int $start_row,
        int $row_count,
        int $col_count
    ): void {
        if ( $row_count < 1 || $col_count < 1 ) {
            return;
        }
        try {
            $client->apply_export_data_cell_clip(
                $conn->spreadsheet_id,
                $conn->sheet_name,
                $start_row,
                $row_count,
                $col_count
            );
        } catch ( \Throwable $e ) {
            // Non-fatal — styling pass at end will retry.
        }
    }

    /**
     * @param int[] $product_ids
     */
    private static function log_export_product_failure(
        int $connection_id,
        int $sheet_row,
        array $product_ids,
        string $api_message
    ): void {
        $sku_hint = self::export_batch_sku_preview( $product_ids, 1 );
        $detail   = sprintf(
            'Product not exported to sheet (row %1$d). %2$s%3$s',
            $sheet_row,
            $api_message,
            $sku_hint ? ' SKU: ' . $sku_hint : ''
        );
        SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, $detail, 1 );
    }

    /**
     * Field maps for writing rows to Google Sheets (includes export helper columns).
     *
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    public static function maps_for_sheet_export( int $connection_id ): array {
        return self::normalize_maps_for_export(
            SheetSync_Field_Mapper::get_maps_for_export( $connection_id )
        );
    }

    /**
     * @deprecated Use SheetSync_Field_Mapper::ensure_export_maps()
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    private static function ensure_export_helper_columns( array $maps ): array {
        return SheetSync_Field_Mapper::ensure_export_maps( $maps );
    }

    /**
     * @deprecated Use export_connection_batch_to_sheet()
     */
    public static function export_connection_page_to_sheet( int $connection_id, int $page, int $per_page = 50 ): array {
        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        $start_row = $conn ? (int) $conn->header_row + 1 + ( ( max( 1, $page ) - 1 ) * $per_page ) : 2;
        $cursor    = SheetSync_Export_Order::normalize_cursor( array() );
        if ( $page > 1 && class_exists( 'SheetSync_Export_Order', false ) ) {
            $prior = SheetSync_Export_Order::get_next_export_ids(
                $connection_id,
                SheetSync_Export_Order::normalize_cursor( array() ),
                ( $page - 1 ) * $per_page
            );
            if ( ! empty( $prior['ids'] ) ) {
                $cursor = $prior['cursor'];
            }
        }
        $result = self::export_connection_batch_to_sheet( $connection_id, $cursor, $per_page, $start_row );
        return array(
            'success'   => $result['success'],
            'processed' => $result['processed'],
            'errors'    => $result['errors'],
            'has_more'  => $result['has_more'],
        );
    }

    /**
     * Begin a full catalog export: expand grid + write headers. Does NOT clear the sheet or maps
     * until {@see finalize_catalog_export()} runs after a successful export.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     */
    public static function begin_catalog_export( int $connection_id, array $maps ): void {
        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            return;
        }

        $maps = self::normalize_maps_for_export( $maps );
        $pre_max_row = 0;
        if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
            $pre_max_row = ( new SheetSync_Product_Map_Repository() )->get_max_sheet_row( $connection_id );
        }

        set_transient(
            'sheetsync_catalog_export_' . $connection_id,
            array(
                'started_at'  => current_time( 'mysql', true ),
                'pre_max_row' => $pre_max_row,
            ),
            DAY_IN_SECONDS
        );

        self::prepare_export_sheet_headers( $connection_id, $maps, true );

        SheetSync_Logger::log(
            $connection_id,
            'export',
            'success',
            0,
            0,
            sprintf(
                /* translators: %s: sheet tab name */
                __( 'Export started on “%s”. Existing sheet data is kept until the export completes successfully.', 'sheetsync-for-woocommerce' ),
                $conn->sheet_name
            )
        );
    }

    /**
     * After a successful full export: remove stale sheet rows and row maps from prior runs.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     */
    public static function finalize_catalog_export( int $connection_id, array $maps ): void {
        $state = get_transient( 'sheetsync_catalog_export_' . $connection_id );
        if ( ! is_array( $state ) ) {
            return;
        }

        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            delete_transient( 'sheetsync_catalog_export_' . $connection_id );
            return;
        }

        $maps       = self::normalize_maps_for_export( $maps );
        $header_row = max( 1, (int) $conn->header_row );
        $pre_max    = max( 0, (int) ( $state['pre_max_row'] ?? 0 ) );
        $started_at = (string) ( $state['started_at'] ?? '' );

        if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
            $map_repo  = new SheetSync_Product_Map_Repository();
            $last_row  = $map_repo->get_max_sheet_row( $connection_id );

            if ( $last_row > 0 && $pre_max > $last_row && $pre_max > $header_row && ! empty( $maps ) ) {
                try {
                    $end_col = SheetSync_Field_Mapper::max_column_letter( $maps );
                    $client  = new SheetSync_Sheets_Client();
                    $range   = SheetSync_Sheets_Client::tab_range(
                        $conn->sheet_name,
                        'A' . ( $last_row + 1 ) . ':' . $end_col . $pre_max
                    );
                    $client->clear_range( $conn->spreadsheet_id, $range );
                } catch ( \Throwable $e ) {
                    SheetSync_Logger::log( $connection_id, 'export', 'partial', 0, 0, $e->getMessage() );
                }
            }

            if ( $started_at !== '' ) {
                $removed = $map_repo->delete_maps_not_pushed_since( $connection_id, $started_at );
                if ( $removed > 0 ) {
                    SheetSync_Logger::log(
                        $connection_id,
                        'export',
                        'success',
                        0,
                        0,
                        sprintf(
                            /* translators: %d: number of stale mappings removed */
                            __( 'Removed %d stale product row mapping(s) from prior exports.', 'sheetsync-for-woocommerce' ),
                            $removed
                        )
                    );
                }
            }
        }

        delete_transient( 'sheetsync_catalog_export_' . $connection_id );

        SheetSync_Logger::log(
            $connection_id,
            'export',
            'success',
            0,
            0,
            sprintf(
                /* translators: %s: sheet tab name */
                __( 'Full export finalized on “%s”.', 'sheetsync-for-woocommerce' ),
                $conn->sheet_name
            )
        );
    }

    /**
     * Drop in-progress export state when a background job fails or is abandoned (keeps existing sheet data).
     */
    public static function abort_catalog_export( int $connection_id ): void {
        delete_transient( 'sheetsync_catalog_export_' . $connection_id );
    }

    /**
     * @deprecated Use begin_catalog_export() — kept for callers that still reference this name.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     */
    public static function reset_sheet_tab_for_catalog_export( int $connection_id, array $maps ): void {
        self::begin_catalog_export( $connection_id, $maps );
    }

    /**
     * Write human-readable styled headers on the configured header row (SKU, Title, Price…).
     * Call before the first export batch so row 1 is not overwritten with product data.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     * @param bool $defer_heavy_styling Skip filters/formatting during export start (applied at finalize).
     */
    public static function prepare_export_sheet_headers( int $connection_id, array $maps, bool $defer_heavy_styling = false ): void {
        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            return;
        }

        $maps = self::normalize_maps_for_export( $maps );
        if ( empty( $maps ) ) {
            return;
        }

        $header = SheetSync_Field_Mapper::build_header_row_from_maps( $maps );
        if ( empty( $header ) ) {
            return;
        }

        $max_col   = count( $header ) - 1;
        $col_count = $max_col + 1;
        $client    = new SheetSync_Sheets_Client();
        $header_row = max( 1, (int) $conn->header_row );
        $col_end    = SheetSync_Field_Mapper::index_to_col( $max_col );
        $range      = SheetSync_Sheets_Client::tab_range( $conn->sheet_name, 'A' . $header_row . ':' . $col_end . $header_row );

        $client->ensure_sheet_exists( $conn->spreadsheet_id, $conn->sheet_name );

        $export_rows = function_exists( 'sheetsync_count_exportable_products' )
            ? sheetsync_count_exportable_products( $connection_id )
            : 5000;
        $grid_cap    = (int) apply_filters( 'sheetsync_header_grid_expand_cap', $defer_heavy_styling ? 500 : 5000 );
        if ( $grid_cap > 0 ) {
            $export_rows = min( $export_rows, $grid_cap );
        }
        $client->ensure_sheet_grid_capacity(
            $conn->spreadsheet_id,
            $conn->sheet_name,
            $header_row + $export_rows + 200,
            $col_count
        );

        $client->set_rows( $conn->spreadsheet_id, $range, array( $header ) );

        if ( $defer_heavy_styling ) {
            return;
        }

        // Same professional layout as “Write template to Google Sheet” (Sheet → WC).
        $client->format_product_template_sheet(
            $conn->spreadsheet_id,
            $conn->sheet_name,
            $col_count,
            0,
            0,
            $header_row
        );

        $filter_rows = function_exists( 'sheetsync_count_exportable_products' )
            ? sheetsync_count_exportable_products( $connection_id )
            : 500;
        if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
            $max_row = ( new SheetSync_Product_Map_Repository() )->get_max_sheet_row( $connection_id );
            $filter_rows = max( $filter_rows, max( 0, $max_row - $header_row ) );
        }
        $filter_rows = max( 1, $filter_rows );
        try {
            $client->apply_export_sheet_filters(
                $conn->spreadsheet_id,
                $conn->sheet_name,
                $header_row,
                $filter_rows,
                $maps,
                $connection_id
            );
        } catch ( \Throwable $e ) {
            SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, $e->getMessage() );
        }
    }

    /**
     * Best row count for styling (job progress vs product_map on sheet).
     */
    public static function resolve_export_data_row_count( int $connection_id, int $fallback = 0 ): int {
        $count = max( 0, $fallback );
        $conn  = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn || ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
            return $count;
        }
        $max_row  = ( new SheetSync_Product_Map_Repository() )->get_max_sheet_row( $connection_id );
        $from_map = max( 0, $max_row - max( 1, (int) $conn->header_row ) );
        return max( $count, $from_map );
    }

    /**
     * Re-apply filters, banding, parent/variation colors, and row groups (manual or after sync).
     *
     * @return array{success: bool, message: string, rows: int}
     */
    public static function apply_sheet_formatting_for_connection( int $connection_id, int $data_row_count = 0 ): array {
        $rows = self::resolve_export_data_row_count( $connection_id, $data_row_count );
        if ( $rows < 1 ) {
            return array(
                'success' => false,
                'message' => __( 'No product rows on the sheet yet. Export products first, then apply formatting.', 'sheetsync-for-woocommerce' ),
                'rows'    => 0,
            );
        }
        try {
            self::finish_export_sheet_styling( $connection_id, $rows );
        } catch ( \Throwable $e ) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'rows'    => $rows,
            );
        }
        return array(
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of data rows styled */
                __( 'Sheet filters, colors, and variation groups applied to %d product rows.', 'sheetsync-for-woocommerce' ),
                $rows
            ),
            'rows'    => $rows,
        );
    }

    /**
     * Apply filters, banding, highlights, and row groups after export (or via “Apply sheet formatting”).
     */
    public static function finish_export_sheet_styling( int $connection_id, int $data_row_count ): void {
        $data_row_count = self::resolve_export_data_row_count( $connection_id, $data_row_count );
        if ( $data_row_count < 1 ) {
            return;
        }
        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            return;
        }
        $maps = self::ensure_export_helper_columns(
            self::normalize_maps_for_export( SheetSync_Field_Mapper::get_maps( $connection_id ) )
        );
        if ( empty( $maps ) ) {
            return;
        }
        $col_count  = SheetSync_Field_Mapper::col_to_index( SheetSync_Field_Mapper::max_column_letter( $maps ) ) + 1;
        $client     = new SheetSync_Sheets_Client();
        $first_row  = max( 1, (int) $conn->header_row ) + 1;
        $header_row = max( 1, (int) $conn->header_row );

        try {
            $client->format_product_template_sheet(
                $conn->spreadsheet_id,
                $conn->sheet_name,
                $col_count,
                0,
                0,
                $header_row
            );
        } catch ( \Throwable $e ) {
            SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, 'Header format: ' . $e->getMessage() );
        }

        try {
            $client->apply_export_data_cell_clip(
                $conn->spreadsheet_id,
                $conn->sheet_name,
                $first_row,
                $data_row_count,
                $col_count
            );
        } catch ( \Throwable $e ) {
            SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, 'Cell clip: ' . $e->getMessage() );
        }

        // Filters first — large catalogs used to fail on row groups before filters were applied.
        try {
            $client->apply_export_sheet_filters(
                $conn->spreadsheet_id,
                $conn->sheet_name,
                $header_row,
                $data_row_count,
                $maps,
                $connection_id
            );
        } catch ( \Throwable $e ) {
            SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, 'Sheet filters: ' . $e->getMessage() );
        }

        try {
            $client->apply_row_colors(
                $conn->spreadsheet_id,
                $conn->sheet_name,
                $first_row,
                $data_row_count,
                $col_count
            );
        } catch ( \Throwable $e ) {
            SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, 'Row colors: ' . $e->getMessage() );
        }

        if ( class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
            try {
                $client->apply_export_role_row_highlights(
                    $conn->spreadsheet_id,
                    $conn->sheet_name,
                    $first_row,
                    $col_count,
                    $connection_id
                );
            } catch ( \Throwable $e ) {
                SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, 'Row highlights: ' . $e->getMessage() );
            }
            try {
                $client->apply_variable_product_row_groups(
                    $conn->spreadsheet_id,
                    $conn->sheet_name,
                    $first_row,
                    $connection_id
                );
            } catch ( \Throwable $e ) {
                SheetSync_Logger::log( $connection_id, 'export', 'error', 0, 0, 'Row groups: ' . $e->getMessage() );
            }
        }
    }

    /**
     * First few SKUs/IDs in a failed export batch (for sync logs).
     *
     * @param int[] $product_ids
     */
    private static function export_batch_sku_preview( array $product_ids, int $limit = 5 ): string {
        $parts = array();
        foreach ( array_slice( $product_ids, 0, $limit ) as $product_id ) {
            $product = wc_get_product( (int) $product_id );
            if ( ! $product ) {
                $parts[] = '#' . (int) $product_id;
                continue;
            }
            $sku = (string) $product->get_sku();
            $parts[] = $sku !== '' ? $sku : '#' . (int) $product_id;
        }
        $out = implode( ', ', $parts );
        if ( count( $product_ids ) > $limit ) {
            $out .= sprintf( ' … +%d more', count( $product_ids ) - $limit );
        }
        return $out;
    }

    /**
     * Drop invalid / empty sheet columns so export never fatals (e.g. array_fill length 0 on PHP 8+).
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    private static function normalize_maps_for_export( array $maps ): array {
        $out = array();
        foreach ( $maps as $field => $info ) {
            if ( ! is_string( $field ) || $field === '' || ! is_array( $info ) ) {
                continue;
            }
            $col = SheetSync_Field_Mapper::sanitize_column_letter( (string) ( $info['sheet_column'] ?? '' ) );
            if ( ! SheetSync_Field_Mapper::is_valid_sheet_column( $col ) ) {
                continue;
            }
            $out[ $field ] = array(
                'sheet_column' => $col,
                'is_key_field' => ! empty( $info['is_key_field'] ),
            );
        }
        return $out;
    }

    public function ajax_bulk_export(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) || ! self::current_user_has_pro_export() ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.discouraged
        }
        wp_raise_memory_limit( 'admin' );

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $raw_cursor    = wp_unslash( $_POST['export_cursor'] ?? '' );
        $export_cursor = is_array( $raw_cursor ) ? $raw_cursor : json_decode( (string) $raw_cursor, true );
        if ( ! is_array( $export_cursor ) ) {
            $export_cursor = array();
        }
        $start_row  = isset( $_POST['start_row'] ) ? max( 0, absint( $_POST['start_row'] ) ) : 0;
        $init_batch = empty( $export_cursor ) && $start_row <= 0;

        $result = self::run_catalog_export_batches(
            $connection_id,
            array(
                'single_batch'          => true,
                'init_export'           => $init_batch,
                'export_cursor'         => $export_cursor,
                'start_row'             => $start_row,
                'resume_from_transient' => true,
            )
        );

        if ( ! empty( $result['success'] ) || ! empty( $result['partial'] ) ) {
            wp_send_json_success( $result );
        }

        wp_send_json_error( array( 'message' => esc_html( (string) ( $result['message'] ?? __( 'Export failed.', 'sheetsync-for-woocommerce' ) ) ) ) );
    }

    // ── Import: Sheets → WooCommerce (with create support) ────────────────────

    public function ajax_bulk_import(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) || ! self::current_user_has_pro_export() ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.discouraged
        }
        wp_raise_memory_limit( 'admin' );

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        $create_new      = ! empty( $_POST['create_new'] );
        $batch_offset    = isset( $_POST['batch_offset'] ) ? max( 0, absint( $_POST['batch_offset'] ) ) : 0;
        $batch_size      = isset( $_POST['batch_size'] ) ? max( 1, min( 80, absint( $_POST['batch_size'] ) ) ) : 40;
        $import_session  = sanitize_text_field( wp_unslash( $_POST['import_session_id'] ?? '' ) );
        $is_first_batch  = ( 0 === $batch_offset );

        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );
        }

        if ( function_exists( 'sheetsync_acquire_bulk_import_session' ) ) {
            $lock = sheetsync_acquire_bulk_import_session( $connection_id, $import_session, $is_first_batch );
            if ( empty( $lock['ok'] ) ) {
                wp_send_json_error(
                    array( 'message' => __( 'Another import is already running for this connection.', 'sheetsync-for-woocommerce' ) )
                );
            }
            $import_session = (string) ( $lock['session_id'] ?? '' );
        }

        $maps = SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn );

        if ( $is_first_batch && class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            SheetSync_Import_Row_Service::begin_import_run( $connection_id );
        } elseif ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            SheetSync_Import_Row_Service::continue_import_run( $connection_id );
        }

        $first_data_row_1based = (int) $conn->header_row + 1;
        $start_row             = $first_data_row_1based + $batch_offset;
        $end_row               = $start_row + $batch_size - 1;

        try {
            $client   = new SheetSync_Sheets_Client();
            $last_col  = SheetSync_Field_Mapper::max_column_letter( $maps );
            $range     = "{$conn->sheet_name}!A{$start_row}:{$last_col}{$end_row}";
            $data_rows = function_exists( 'sheetsync_get_sheet_rows_with_retry' )
                ? sheetsync_get_sheet_rows_with_retry( $client, $conn->spreadsheet_id, $range )
                : $client->get_rows( $conn->spreadsheet_id, $range );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }

        $updater = new SheetSync_Product_Updater( $maps );
        $tagged  = array();
        foreach ( $data_rows as $i => $row ) {
            $tagged[] = array(
                'sheet_row' => $start_row + $i,
                'row'       => $row,
            );
        }
        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            $tagged = SheetSync_Import_Row_Service::prepare_tagged_rows_for_import( $tagged, $updater, $start_row );
            SheetSync_Import_Row_Service::warm_sku_lookup_for_tagged_rows( $updater, $tagged );
        } elseif ( class_exists( 'SheetSync_Variation_Sync', false ) ) {
            $tagged = SheetSync_Variation_Sync::sort_rows_parents_first( $data_rows, $updater, $start_row );
        }

        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            SheetSync_Import_Row_Service::begin_batch();
        }
        $created = $updated = $skipped = $errors = 0;
        $map_repo = class_exists( 'SheetSync_Product_Map_Repository', false )
            ? new SheetSync_Product_Map_Repository()
            : null;

        foreach ( $tagged as $item ) {
            $row           = $item['row'];
            $sheet_row_num = (int) ( $item['sheet_row'] ?? 0 );

            if ( empty( array_filter( $row, fn( $v ) => $v !== '' && $v !== null ) ) ) {
                continue;
            }

            if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                $result = SheetSync_Import_Row_Service::import_sheet_row(
                    $conn,
                    $connection_id,
                    $maps,
                    $row,
                    $sheet_row_num,
                    $updater,
                    $map_repo,
                    null,
                    $create_new,
                    false
                );
            } else {
                $result = $updater->update( $row, $sheet_row_num );
            }

            match ( $result ) {
                'created' => ++$created,
                'updated' => ++$updated,
                'skipped', 'queued' => ++$skipped,
                'error'   => ++$errors,
                default   => ++$skipped,
            };
        }

        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            SheetSync_Import_Row_Service::end_batch();
            SheetSync_Import_Row_Service::run_mid_batch_deferred_retries( $connection_id, $maps );
            SheetSync_Import_Row_Service::flush_import_run_state( $connection_id );
        }

        $fetched = count( $data_rows );
        $done    = ( $fetched < $batch_size );
        $next    = $batch_offset + $fetched;

        if ( $done ) {
            if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
                $retry   = SheetSync_Import_Row_Service::retry_deferred_import_passes( $connection_id, $maps, false, $updater );
                $updated += (int) ( $retry['processed'] ?? 0 );
                $skipped += (int) ( $retry['skipped'] ?? 0 );
                $errors  += (int) ( $retry['errors'] ?? 0 );
                SheetSync_Import_Row_Service::end_import_run( $connection_id );
            }
            SheetSync_Logger::log(
                $connection_id,
                'import',
                $errors > 0 ? 'partial' : 'success',
                $updated + $created,
                $skipped,
                "Bulk import finished: {$created} created, {$updated} updated, {$skipped} skipped, {$errors} errors",
                $errors
            );
        }

        wp_send_json_success(
            array(
                'created'           => $created,
                'updated'           => $updated,
                'skipped'           => $skipped,
                'errors'            => $errors,
                'next_offset'       => $next,
                'done'              => $done,
                'batch_size'        => $batch_size,
                'import_session_id' => $import_session,
            )
        );
    }

    /**
     * Create a new WooCommerce product from row data.
     */
    private function create_product_from_row( array $row, array $maps ): string {
        return self::create_product_from_row_static( $row, $maps );
    }

    /**
     * Shared product creation for bulk import + admin import flows.
     */
    public static function create_product_from_row_static( array $row, array $maps ): string {
        if ( class_exists( 'SheetSync_Variation_Sync', false )
            && function_exists( 'sheetsync_is_pro' )
            && sheetsync_is_pro() ) {
            $data = SheetSync_Variation_Sync::extract_row_data( $row, $maps );
            if ( ! SheetSync_Variation_Sync::should_use_simple_create_fallback( $data ) ) {
                return SheetSync_Variation_Sync::create_from_row_static( $row, $maps );
            }
        }

        $data = class_exists( 'SheetSync_Variation_Sync', false )
            ? SheetSync_Variation_Sync::extract_row_data( $row, $maps )
            : array();
        if ( empty( $data ) ) {
            foreach ( $maps as $field => $info ) {
                $idx            = SheetSync_Field_Mapper::col_to_index( $info['sheet_column'] );
                $data[ $field ] = $row[ $idx ] ?? '';
            }
        }

        $product = new WC_Product_Simple();
        $product->set_status( 'draft' );

        $updater = new SheetSync_Product_Updater( $maps );

        try {
            $updater->apply_updates( $product, $data );
            if ( '' === $product->get_name() && ! empty( $data['_sku'] ) ) {
                $product->set_name( sanitize_text_field( $data['_sku'] ) );
            }
            $product->save();
            return 'created';
        } catch ( Exception $e ) {
            return 'error';
        }
    }

    private function get_max_col( array $maps ): int {
        $max = 0;
        foreach ( $maps as $info ) {
            $max = max( $max, SheetSync_Field_Mapper::col_to_index( $info['sheet_column'] ) );
        }
        return $max;
    }
}