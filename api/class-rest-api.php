<?php
/**
 * REST API endpoints for SheetSync.
 * Namespace: sheetsync/v1
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_REST_API' ) ) :

class SheetSync_REST_API {

    public function register_routes(): void {
        // Webhook endpoint — receives push from Google Apps Script
        register_rest_route( 'sheetsync/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => '__return_true', // auth done inside
        ) );

        // Sync status endpoint
        register_rest_route( 'sheetsync/v1', '/sync-status/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_sync_status' ),
            'permission_callback' => array( $this, 'auth_manage_woocommerce' ),
            'args'                => array(
                'id' => array(
                    'validate_callback' => fn( $v ) => is_numeric( $v ),
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        register_rest_route( 'sheetsync/v1', '/jobs/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_job_status' ),
            'permission_callback' => array( $this, 'auth_manage_woocommerce' ),
            'args'                => array(
                'id' => array(
                    'validate_callback' => fn( $v ) => is_numeric( $v ),
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        register_rest_route( 'sheetsync/v1', '/health', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_health' ),
            'permission_callback' => array( $this, 'auth_manage_woocommerce' ),
        ) );

        register_rest_route( 'sheetsync/v1', '/connections/(?P<id>\d+)/active-job', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_active_job' ),
            'permission_callback' => array( $this, 'auth_manage_woocommerce' ),
            'args'                => array(
                'id' => array(
                    'validate_callback' => fn( $v ) => is_numeric( $v ),
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        register_rest_route( 'sheetsync/v1', '/connections/(?P<id>\d+)/sync', array(
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'post_connection_sync' ),
                'permission_callback' => array( $this, 'auth_manage_woocommerce' ),
                'args'                => array(
                    'id' => array(
                        'validate_callback' => fn( $v ) => is_numeric( $v ),
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_connection_sync_tick' ),
                'permission_callback' => array( $this, 'auth_manage_woocommerce' ),
                'args'                => array(
                    'id' => array(
                        'validate_callback' => fn( $v ) => is_numeric( $v ),
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
        ) );

        // External cron — server/EasyCron hits this when WP-Cron is unreliable.
        register_rest_route( 'sheetsync/v1', '/queue-run', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_queue_run' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function get_active_job( WP_REST_Request $request ): WP_REST_Response {
        $connection_id = (int) $request->get_param( 'id' );
        if ( ! class_exists( 'SheetSync_Job_Repository', false ) ) {
            return new WP_REST_Response( array( 'job' => null ), 200 );
        }

        $job = ( new SheetSync_Job_Repository() )->get_running_for_connection( $connection_id );
        if ( ! $job ) {
            return new WP_REST_Response( array( 'job' => null ), 200 );
        }

        $progress = function_exists( 'sheetsync_job_progress_numbers' )
            ? sheetsync_job_progress_numbers( $job )
            : array(
                'done'  => (int) $job->processed_count,
                'total' => (int) ( $job->total_estimate ?? 0 ),
                'pct'   => null,
            );

        $payload = function_exists( 'sheetsync_job_rest_payload' )
            ? sheetsync_job_rest_payload( $job )
            : array(
                'id'              => (int) $job->id,
                'status'          => $job->status,
                'phase'           => $job->phase,
                'direction'       => $job->direction,
                'mode'            => $job->mode,
                'cursor_offset'   => (int) ( $job->cursor_offset ?? 0 ),
                'processed_count' => (int) $progress['done'],
                'error_count'     => (int) $job->error_count,
                'total_estimate'  => (int) $progress['total'],
                'progress_pct'    => $progress['pct'],
                'last_error'      => $job->last_error,
                'updated_count'   => (int) ( $job->processed_count ?? 0 ),
                'eta_minutes'     => null,
            );

        return $this->job_json_response( array( 'job' => $payload ), 200 );
    }

    public function get_health( WP_REST_Request $request ): WP_REST_Response {
        $health = function_exists( 'sheetsync_get_plugin_health' )
            ? sheetsync_get_plugin_health()
            : array( 'ok' => false, 'error' => __( 'Health check unavailable.', 'sheetsync-for-woocommerce' ) );

        $status = ! empty( $health['ok'] ) ? 200 : 503;

        return new WP_REST_Response( $health, $status );
    }

    public function get_job_status( WP_REST_Request $request ): WP_REST_Response {
        $job_id = (int) $request->get_param( 'id' );
        if ( ! class_exists( 'SheetSync_Job_Repository', false ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Job engine not available.', 'sheetsync-for-woocommerce' ) ), 503 );
        }

        $job = ( new SheetSync_Job_Repository() )->get( $job_id );
        if ( ! $job ) {
            return new WP_REST_Response( array( 'error' => __( 'Job not found.', 'sheetsync-for-woocommerce' ) ), 404 );
        }

        $progress = function_exists( 'sheetsync_job_progress_numbers' )
            ? sheetsync_job_progress_numbers( $job )
            : array(
                'done'  => (int) $job->processed_count,
                'total' => (int) ( $job->total_estimate ?? 0 ),
                'pct'   => null,
            );

        $payload = function_exists( 'sheetsync_job_rest_payload' )
            ? sheetsync_job_rest_payload( $job )
            : array(
                'id'              => (int) $job->id,
                'connection_id'   => (int) $job->connection_id,
                'status'          => $job->status,
                'phase'           => $job->phase,
                'direction'       => $job->direction,
                'mode'            => $job->mode,
                'cursor_offset'   => (int) ( $job->cursor_offset ?? 0 ),
                'processed_count' => (int) $progress['done'],
                'skipped_count'   => (int) $job->skipped_count,
                'error_count'     => (int) $job->error_count,
                'last_error'      => $job->last_error,
                'total_estimate'  => (int) $progress['total'],
                'progress_pct'    => in_array( $job->status, array( 'completed', 'failed' ), true ) ? 100 : ( $progress['pct'] ?? 0 ),
                'updated_count'   => (int) ( $job->processed_count ?? 0 ),
                'eta_minutes'     => null,
            );

        return $this->job_json_response( array( 'job' => $payload ), 200 );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function job_json_response( array $payload, int $status = 200 ): WP_REST_Response {
        $response = new WP_REST_Response( $payload, $status );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }

    // ── Webhook handler ────────────────────────────────────────────────────────

    /**
     * External cron / queue runner (token auth).
     */
    public function handle_queue_run( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->check_cron_rate_limit() ) {
            return new WP_REST_Response( array( 'error' => __( 'Rate limit exceeded.', 'sheetsync-for-woocommerce' ) ), 429 );
        }

        if ( ! class_exists( 'SheetSync_External_Cron', false ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Cron runner unavailable.', 'sheetsync-for-woocommerce' ) ), 503 );
        }

        $token = sanitize_text_field( (string) $request->get_header( 'X-SheetSync-Cron-Token' ) );
        if ( $token === '' ) {
            // Legacy: query param still accepted for existing cron jobs.
            $token = sanitize_text_field( (string) $request->get_param( 'token' ) );
        }

        if ( ! SheetSync_External_Cron::verify_token( $token ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Unauthorized.', 'sheetsync-for-woocommerce' ) ), 401 );
        }

        if ( $request->get_param( 'ping' ) ) {
            $payload = SheetSync_External_Cron::ping();
            return new WP_REST_Response( $payload, 200 );
        }

        $seconds = (int) $request->get_param( 'seconds' );
        if ( $seconds < 1 ) {
            $seconds = 25;
        }

        $result = SheetSync_External_Cron::run( $seconds );
        return new WP_REST_Response( $result, 200 );
    }

    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        // Rate limit: max 60 requests / minute per IP
        if ( ! $this->check_rate_limit() ) {
            return new WP_REST_Response( array( 'error' => __( 'Rate limit exceeded.', 'sheetsync-for-woocommerce' ) ), 429 );
        }

        // Parse and validate payload (connection_id needed for per-connection secret).
        $payload = $request->get_json_params();
        if ( empty( $payload ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Empty payload.', 'sheetsync-for-woocommerce' ) ), 400 );
        }

        $connection_id = absint( $payload['connection_id'] ?? 0 );
        $header        = (string) $request->get_header( 'X-SheetSync-Secret' );

        if ( ! function_exists( 'sheetsync_verify_webhook_secret' )
            || ! sheetsync_verify_webhook_secret( $header, $connection_id ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Unauthorized.', 'sheetsync-for-woocommerce' ) ), 401 );
        }

        // Ping from WP admin or Apps Script testWebhook() — no row processing.
        if ( ! empty( $payload['test'] ) ) {
            if ( ! $connection_id ) {
                return new WP_REST_Response( array( 'error' => __( 'Missing connection_id.', 'sheetsync-for-woocommerce' ) ), 400 );
            }
            update_option( 'sheetsync_webhook_verified_' . $connection_id, time(), false );
            return new WP_REST_Response(
                array(
                    'result'        => 'test_ok',
                    'connection_id' => $connection_id,
                    'message'       => __( 'Webhook endpoint and secret are valid.', 'sheetsync-for-woocommerce' ),
                ),
                200
            );
        }

        if ( ! $connection_id ) {
            return new WP_REST_Response( array( 'error' => __( 'Missing connection_id.', 'sheetsync-for-woocommerce' ) ), 400 );
        }

        // Get the row data
        $row = array_map( function( $cell ) {
            // Apps Script sends numbers as numeric types, booleans as bool.
            // sanitize_text_field() on a number/bool works but preserve numeric strings
            // for fields like price, stock quantity.
            if ( is_bool( $cell ) ) return $cell ? 'true' : 'false';
            if ( is_numeric( $cell ) ) return (string) $cell;
            return sanitize_text_field( (string) $cell );
        }, (array) ( $payload['row'] ?? array() ) );
        if ( empty( $row ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Empty row data.', 'sheetsync-for-woocommerce' ) ), 400 );
        }

        // Check connection type — Orders or Products.
        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            return new WP_REST_Response( array( 'error' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ), 404 );
        }

        // If Orders connection, update order status (Pro only — matches product line: orders are a Pro feature).
        // FIX: Check for ALL order connection types (orders, orders_processing, orders_completed, etc.)
        if ( SheetSync_Sync_Engine::is_orders_type( $conn->connection_type ) ) {
            if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
                return new WP_REST_Response( array( 'error' => __( 'Order sync via webhook requires SheetSync Pro.', 'sheetsync-for-woocommerce' ) ), 403 );
            }
            if ( function_exists( 'sheetsync_realtime_sync_enabled' ) && ! sheetsync_realtime_sync_enabled( $connection_id ) ) {
                return new WP_REST_Response(
                    array(
                        'result' => 'skipped',
                        'reason' => __( 'Real-time sync is disabled for this connection. Enable it in the Sync tab.', 'sheetsync-for-woocommerce' ),
                    ),
                    200
                );
            }
            if ( ! sheetsync_connection_allows_pull( $conn ) ) {
                return new WP_REST_Response(
                    array(
                        'result' => 'skipped',
                        'reason' => __( 'Connection direction does not allow Sheet → WooCommerce import.', 'sheetsync-for-woocommerce' ),
                    ),
                    200
                );
            }
            $order_id = absint( $row[0] ?? 0 );
            $status   = str_replace( 'wc-', '', strtolower( trim( (string) ( $payload['status'] ?? $row[2] ?? '' ) ) ) );

            $allowed = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'draft' );

            if ( ! $order_id || ! in_array( $status, $allowed, true ) ) {
                return new WP_REST_Response( array( 'result' => 'skipped', 'reason' => __( 'Invalid order ID or status.', 'sheetsync-for-woocommerce' ) ), 200 );
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return new WP_REST_Response( array( 'result' => 'skipped', 'reason' => __( 'Order not found.', 'sheetsync-for-woocommerce' ) ), 200 );
            }

            if ( function_exists( 'sheetsync_order_in_webhook_scope' )
                && ! sheetsync_order_in_webhook_scope( $order, $conn ) ) {
                SheetSync_Logger::log(
                    $connection_id,
                    'webhook_order',
                    'partial',
                    0,
                    1,
                    sprintf( 'Order #%d skipped: outside connection date scope.', $order_id )
                );
                return new WP_REST_Response(
                    array(
                        'result' => 'skipped',
                        'reason' => __( 'Order outside this connection\'s date filter.', 'sheetsync-for-woocommerce' ),
                    ),
                    200
                );
            }

            if ( $order->get_status() === $status ) {
                // Status already matches — still reconcile sheets (row may be on wrong tab).
                if ( class_exists( 'SheetSync_Order_Sync', false ) ) {
                    try {
                        ( new SheetSync_Order_Sync() )->sync_order_rows_for_status( $order_id, $status, $connection_id );
                    } catch ( Exception $e ) {
                        return $this->webhook_processing_error( $e, $connection_id, 'Webhook reconcile failed', $order_id );
                    }
                }
                update_option( 'sheetsync_webhook_verified_' . $connection_id, time(), false );
                if ( class_exists( 'SheetSync_Sync_Engine', false ) ) {
                    SheetSync_Sync_Engine::touch_connection_sync_time( $connection_id );
                }
                return new WP_REST_Response( array( 'result' => 'reconciled', 'order_id' => $order_id, 'status' => $status ), 200 );
            }

            set_transient( 'sheetsync_status_changing_' . $order_id, 'sheet', 30 );

            try {
                $order->update_status( $status, __( 'Updated by SheetSync Auto-Sync.', 'sheetsync-for-woocommerce' ), true );

                if ( class_exists( 'SheetSync_Order_Sync', false ) ) {
                    $order_sync = new SheetSync_Order_Sync();
                    $order_sync->sync_order_rows_for_status( $order_id, $status, $connection_id );
                    delete_transient( 'sheetsync_status_changing_' . $order_id );
                }
            } catch ( Exception $e ) {
                delete_transient( 'sheetsync_status_changing_' . $order_id );
                return $this->webhook_processing_error( $e, $connection_id, 'Webhook order update failed', $order_id );
            }

            update_option( 'sheetsync_webhook_verified_' . $connection_id, time(), false );
            if ( class_exists( 'SheetSync_Sync_Engine', false ) ) {
                SheetSync_Sync_Engine::touch_connection_sync_time( $connection_id );
            }
            SheetSync_Logger::log( $connection_id, 'webhook_order', 'success', 1, 0, "Order #{$order_id} → {$status} (webhook)" );
            return new WP_REST_Response( array( 'result' => 'updated', 'order_id' => $order_id, 'status' => $status ), 200 );
        }

        // Products connection — real-time webhook is a Pro feature.
        if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
            return new WP_REST_Response( array( 'error' => __( 'Real-time product sync via webhook requires SheetSync Pro.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        if ( ! sheetsync_realtime_sync_enabled( $connection_id ) ) {
            return new WP_REST_Response( array( 'result' => 'skipped', 'reason' => __( 'Real-time sync is disabled for this connection.', 'sheetsync-for-woocommerce' ) ), 200 );
        }

        if ( ! sheetsync_connection_allows_pull( $conn ) ) {
            return new WP_REST_Response( array( 'result' => 'skipped', 'reason' => __( 'Connection direction does not allow Sheet → WooCommerce import.', 'sheetsync-for-woocommerce' ) ), 200 );
        }

        if ( sheetsync_is_sync_locked( $connection_id ) ) {
            return new WP_REST_Response( array( 'result' => 'deferred', 'reason' => __( 'Background sync in progress — retry on next edit.', 'sheetsync-for-woocommerce' ) ), 202 );
        }

        $sheet_row = absint( $payload['sheet_row'] ?? 0 );

        $dedupe_key = $sheet_row > 0 ? 'row:' . $sheet_row : md5( wp_json_encode( $row ) );
        if ( function_exists( 'sheetsync_webhook_is_duplicate' )
            && sheetsync_webhook_is_duplicate( $connection_id, $dedupe_key ) ) {
            return new WP_REST_Response( array( 'result' => 'deduped' ), 200 );
        }

        $state_repo = class_exists( 'SheetSync_Sync_State_Repository', false )
            ? new SheetSync_Sync_State_Repository()
            : null;
        if ( $state_repo && ! $state_repo->try_realtime_slot( $connection_id ) ) {
            return new WP_REST_Response( array( 'result' => 'deferred', 'reason' => __( 'Another real-time update is in progress.', 'sheetsync-for-woocommerce' ) ), 202 );
        }

        $maps    = SheetSync_Field_Mapper::get_maps_for_sync( $connection_id, $conn );
        $updater = new SheetSync_Product_Updater( $maps );

        if ( class_exists( 'SheetSync_Import_Row_Service', false ) ) {
            SheetSync_Import_Row_Service::set_current_connection_id( $connection_id );
        }

        try {
            $hasher        = class_exists( 'SheetSync_Hash_Normalizer', false ) ? new SheetSync_Hash_Normalizer() : null;
            $map_repo      = class_exists( 'SheetSync_Product_Map_Repository', false ) ? new SheetSync_Product_Map_Repository() : null;
            $hash_maps     = $hasher ? $hasher->maps_for_hash( $maps ) : $maps;
            $external_key  = $hasher ? $hasher->external_key_from_row( $row, $maps, $updater, $sheet_row ) : '';
            $row_hash      = $hasher ? $hasher->sheet_hash( $row, $hash_maps ) : '';
            $peek_data     = $updater->extract_data( $row );
            $wc_product    = null;

            if ( $map_repo ) {
                if ( $sheet_row < 1 ) {
                    $map = $external_key !== '' ? $map_repo->find_by_external_key( $connection_id, $external_key ) : null;
                    if ( $map && (int) $map->sheet_row > 0 ) {
                        $sheet_row = (int) $map->sheet_row;
                    }
                }
                $wc_product = class_exists( 'SheetSync_Import_Row_Service', false )
                    ? SheetSync_Import_Row_Service::resolve_wc_product(
                        $connection_id,
                        $external_key,
                        $sheet_row,
                        $row,
                        $updater,
                        $map_repo
                    )
                    : null;
            }

            $mode   = class_exists( 'SheetSync_Sync_Mode', false )
                ? SheetSync_Sync_Mode::resolve( $connection_id )
                : 'incremental';
            $result = ( $map_repo && class_exists( 'SheetSync_Import_Row_Service', false ) )
                ? SheetSync_Import_Row_Service::apply_sheet_row_to_wc(
                    $conn,
                    $connection_id,
                    $mode,
                    $external_key,
                    $row_hash,
                    $peek_data,
                    $row,
                    $sheet_row,
                    $wc_product,
                    $updater,
                    $map_repo
                )
                : $updater->update( $row, $sheet_row );

            if ( 'updated' === $result && $map_repo && $external_key !== '' && $hasher ) {
                $pid           = SheetSync_Product_Map_Repository::resolve_import_product_id( $peek_data, $wc_product );
                $product_after = $pid > 0 ? wc_get_product( $pid ) : $wc_product;
                SheetSync_Import_Row_Service::store_row_hash(
                    $connection_id,
                    $map_repo,
                    $external_key,
                    $row_hash,
                    $sheet_row,
                    $peek_data,
                    true,
                    $product_after instanceof WC_Product ? $product_after : null
                );
            }
        } finally {
            if ( $state_repo ) {
                $state_repo->release_realtime_slot( $connection_id );
            }
        }

        SheetSync_Logger::log(
            $connection_id,
            'webhook',
            $result === 'updated' ? 'success' : ( $result === 'error' ? 'error' : 'partial' ),
            $result === 'updated' ? 1 : 0,
            $result === 'skipped' ? 1 : 0,
            "Webhook: {$result}",
            $result === 'error' ? 1 : 0
        );

        return new WP_REST_Response( array( 'result' => $result ), 200 );
    }

    // ── Sync status ────────────────────────────────────────────────────────────

    public function get_sync_status( WP_REST_Request $request ): WP_REST_Response {
        $conn = SheetSync_Sync_Engine::get_connection( $request->get_param( 'id' ) );
        if ( ! $conn ) {
            return new WP_REST_Response( array( 'error' => __( 'Not found.', 'sheetsync-for-woocommerce' ) ), 404 );
        }

        $logs = SheetSync_Logger::get_logs( 5, $conn->id );
        return new WP_REST_Response( array(
            'connection'   => array(
                'id'           => $conn->id,
                'name'         => $conn->name,
                'status'       => $conn->status,
                'last_sync_at' => $conn->last_sync_at,
            ),
            'recent_logs'  => $logs,
        ), 200 );
    }

    // ── Sync orchestrator ────────────────────────────────────────────────────

    public function post_connection_sync( WP_REST_Request $request ): WP_REST_Response {
        if ( ! class_exists( 'SheetSync_Sync_Orchestrator', false ) ) {
            return new WP_REST_Response(
                array( 'error' => __( 'Sync orchestrator unavailable.', 'sheetsync-for-woocommerce' ) ),
                503
            );
        }

        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $connection_id = (int) $request->get_param( 'id' );
        $body          = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = array();
        }

        $intent = sanitize_key( (string) ( $body['intent'] ?? $request->get_param( 'intent' ) ?? '' ) );

        $options = array(
            'triggered_by'        => 'manual',
            'confirm_full_export' => ! empty( $body['confirm_full_export'] ),
            'pull_before_export'  => ! empty( $body['pull_before_export'] ),
            'force_degraded_sync' => ! empty( $body['force_degraded_sync'] ),
            'sync_strategy'       => sanitize_text_field( (string) ( $body['sync_strategy'] ?? '' ) ),
            'sync_job_direction'  => sanitize_text_field( (string) ( $body['sync_job_direction'] ?? $body['job_direction'] ?? '' ) ),
            'sync_pull_mode'      => sanitize_text_field( (string) ( $body['sync_pull_mode'] ?? $body['pull_mode'] ?? 'default' ) ),
        );

        SheetSync_Logger::info(
            sprintf( 'Orchestrator sync (intent: %s).', $intent !== '' ? $intent : 'default' ),
            $connection_id
        );

        $result = SheetSync_Sync_Orchestrator::start( $connection_id, $intent, $options );

        if ( ! empty( $result['success'] ) || ! empty( $result['job_id'] ) ) {
            return new WP_REST_Response( $result, 200 );
        }

        $status = 400;
        if ( ! empty( $result['scheduler_blocked'] ) ) {
            $status = 409;
        }

        return new WP_REST_Response( $result, $status );
    }

    public function get_connection_sync_tick( WP_REST_Request $request ): WP_REST_Response {
        if ( ! class_exists( 'SheetSync_Sync_Orchestrator', false ) ) {
            return new WP_REST_Response( array( 'job' => null, 'drained' => false ), 200 );
        }

        $connection_id = (int) $request->get_param( 'id' );
        $job_id        = absint( $request->get_param( 'job_id' ) );
        $inline_drain  = rest_sanitize_boolean( $request->get_param( 'inline_drain' ) );

        $payload = SheetSync_Sync_Orchestrator::tick( $connection_id, $job_id, $inline_drain );
        return $this->job_json_response( $payload, 200 );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function auth_manage_woocommerce(): bool {
        return current_user_can( 'manage_woocommerce' );
    }

    private function check_rate_limit(): bool {
        return $this->check_rate_limit_bucket( 'sheetsync_rl_', 60, 60 );
    }

    private function check_cron_rate_limit(): bool {
        return $this->check_rate_limit_bucket( 'sheetsync_cron_rl_', 30, 60 );
    }

    private function check_rate_limit_bucket( string $prefix, int $max_hits, int $window_seconds ): bool {
        $ip  = $this->get_client_ip();
        $key = $prefix . md5( $ip );

        $data = get_transient( $key );

        if ( false === $data ) {
            set_transient( $key, array( 'count' => 1, 'start' => time() ), $window_seconds );
            return true;
        }

        $count = isset( $data['count'] ) ? (int) $data['count'] : 0;

        if ( $count >= $max_hits ) {
            return false;
        }

        $start     = isset( $data['start'] ) ? (int) $data['start'] : time();
        $remaining = max( 1, $window_seconds - ( time() - $start ) );
        set_transient( $key, array( 'count' => $count + 1, 'start' => $start ), $remaining );

        return true;
    }

    private function get_client_ip(): string {
        // REMOTE_ADDR is the only value that cannot be forged by a client.
        // HTTP_CF_CONNECTING_IP and HTTP_X_FORWARDED_FOR are plain HTTP headers
        // that any attacker can set to any value, trivially bypassing rate limits.
        // If this site runs behind a trusted reverse proxy, configure the real client
        // IP at the server/infrastructure layer — never in application code.
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
    }

    /**
     * Log webhook failure details server-side; return a generic message to the client.
     */
    private function webhook_processing_error( Exception $e, int $connection_id, string $log_context, int $order_id = 0 ): WP_REST_Response {
        SheetSync_Logger::error( $log_context . ': ' . $e->getMessage(), $connection_id );

        $body = array(
            'result' => 'error',
            'error'  => __( 'Webhook processing failed.', 'sheetsync-for-woocommerce' ),
        );
        if ( $order_id > 0 ) {
            $body['order_id'] = $order_id;
        }

        return new WP_REST_Response( $body, 500 );
    }
}

endif; // class_exists SheetSync_REST_API
