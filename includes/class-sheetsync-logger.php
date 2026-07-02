<?php
/**
 * Logs sync activity to the sheetsync_logs database table.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Logger' ) ) :

class SheetSync_Logger {

    /**
     * Log a sync event.
     */
    public static function log(
        ?int   $connection_id,
        string $sync_type,
        string $status,
        int    $rows_processed = 0,
        int    $rows_skipped   = 0,
        string $message        = '',
        int    $rows_errored   = 0,
        ?int   $job_id         = null,
        string $level          = 'info'
    ): void {
        if ( ! self::should_persist( $sync_type, $status, $level ) ) {
            return;
        }

        if ( $job_id > 0 && $message !== '' && ! str_starts_with( $message, '[Job #' ) ) {
            $message = sprintf( '[Job #%d] %s', $job_id, $message );
        }

        global $wpdb;

        $row = array(
            'connection_id'  => $connection_id,
            'sync_type'      => sanitize_text_field( $sync_type ),
            'status'         => in_array( $status, array( 'success', 'error', 'partial' ), true ) ? $status : 'error',
            'rows_processed' => absint( $rows_processed ),
            'rows_skipped'   => absint( $rows_skipped ),
            'rows_errored'   => absint( $rows_errored ),
            'message'        => sanitize_textarea_field( $message ),
            'created_at'     => current_time( 'mysql', true ),
        );
        $formats = array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' );

        if ( $job_id > 0 ) {
            $row['job_id'] = $job_id;
            $formats[]     = '%d';
        }

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "{$wpdb->prefix}sheetsync_logs",
            $row,
            $formats
        );

        wp_cache_delete( 'sheetsync_logs_all', 'sheetsync' );
        if ( $connection_id ) {
            wp_cache_delete( "sheetsync_logs_{$connection_id}", 'sheetsync' );
        }

        self::prune_old_logs();
    }

    /**
     * Verbose diagnostics (only stored when log_level = debug).
     */
    public static function debug( string $message, ?int $connection_id = null, ?int $job_id = null ): void {
        self::log( $connection_id, 'debug', 'success', 0, 0, $message, 0, $job_id, 'debug' );
    }

    /**
     * Whether a log line should be written for the current log_level setting.
     */
    private static function should_persist( string $sync_type, string $status, string $level ): bool {
        $settings = get_option( 'sheetsync_settings', array() );
        $min      = (string) ( $settings['log_level'] ?? 'info' );
        if ( ! in_array( $min, array( 'error', 'info', 'debug' ), true ) ) {
            $min = 'info';
        }

        if ( 'error' === $status || 'error' === $sync_type ) {
            return true;
        }
        // Always keep partial completions and export/job activity visible in Sync Logs.
        if ( 'partial' === $status ) {
            return true;
        }
        if ( in_array( $sync_type, array( 'job', 'export', 'manual' ), true ) ) {
            return true;
        }
        if ( 'debug' === $level ) {
            return 'debug' === $min;
        }
        if ( 'error' === $min ) {
            return false;
        }

        return true;
    }

    /**
     * Log an error message only.
     */
    public static function error( string $message, ?int $connection_id = null, ?int $job_id = null ): void {
        self::log( $connection_id, 'error', 'error', 0, 0, $message, 0, $job_id, 'error' );
    }

    /**
     * Log an informational success event (exports, reports, etc.).
     */
    public static function info( string $message, ?int $connection_id = null, int $rows_processed = 0, ?int $job_id = null ): void {
        self::log( $connection_id, 'export', 'success', $rows_processed, 0, $message, 0, $job_id, 'info' );
    }

    /**
     * Strip internal job prefix for merchant-facing display.
     */
    public static function display_message( string $message ): string {
        if ( preg_match( '/^\[Job #\d+\]\s*/', $message ) ) {
            return (string) preg_replace( '/^\[Job #\d+\]\s*/', '', $message );
        }
        return $message;
    }

    /**
     * Merchant-friendly issue category for a log row.
     *
     * @param array<string, mixed> $log
     */
    public static function human_category( array $log ): string {
        $message   = strtolower( (string) ( $log['message'] ?? '' ) );
        $status    = (string) ( $log['status'] ?? '' );
        $sync_type = (string) ( $log['sync_type'] ?? '' );
        $skipped   = (int) ( $log['rows_skipped'] ?? 0 );
        $errored   = (int) ( $log['rows_errored'] ?? 0 );

        if ( str_contains( $message, 'scheduled action' )
            || str_contains( $message, 'background queue' )
            || str_contains( $message, 'action scheduler' ) ) {
            return 'scheduler';
        }
        if ( str_contains( $message, '503' )
            || str_contains( $message, 'service unavailable' )
            || str_contains( $message, 'timeout' )
            || str_contains( $message, 'connection pause' )
            || str_contains( $message, 'max execution' )
            || str_contains( $message, 'memory limit' )
            || str_contains( $message, 'host' ) ) {
            return 'server';
        }
        if ( str_contains( $message, 'google' )
            || str_contains( $message, 'oauth' )
            || str_contains( $message, 'authentication' )
            || str_contains( $message, 'token' )
            || str_contains( $message, 'permission denied' )
            || str_contains( $message, 'quota' )
            || str_contains( $message, 'invalid_grant' ) ) {
            return 'google';
        }
        if ( preg_match( '/\brow\s+\d+/i', $message )
            || str_contains( $message, 'sku' )
            || str_contains( $message, 'ambiguous' )
            || str_contains( $message, 'variation' )
            || str_contains( $message, 'parent product' )
            || str_contains( $message, 'product id' )
            || str_contains( $message, 'no matching product' )
            || ( $skipped > 0 && 'error' === $status )
            || ( $errored > 0 && preg_match( '/\b(product|sku|variation)\b/i', $message ) ) ) {
            return 'product';
        }
        if ( str_contains( $message, 'sheet' )
            || str_contains( $message, 'header' )
            || str_contains( $message, 'cell' )
            || str_contains( $message, 'column' )
            || str_contains( $message, 'spreadsheet' ) ) {
            return 'sheet';
        }
        if ( 'success' === $status && in_array( $sync_type, array( 'export', 'import', 'manual', 'job' ), true ) ) {
            return 'sync';
        }

        return 'system';
    }

    /**
     * @param array<string, mixed> $log
     */
    public static function human_category_label( array $log ): string {
        $labels = array(
            'product'   => __( 'Product issue', 'sheetsync-for-woocommerce' ),
            'sheet'     => __( 'Sheet error', 'sheetsync-for-woocommerce' ),
            'google'    => __( 'Google connection', 'sheetsync-for-woocommerce' ),
            'server'    => __( 'Server busy', 'sheetsync-for-woocommerce' ),
            'scheduler' => __( 'Background tasks', 'sheetsync-for-woocommerce' ),
            'sync'      => __( 'Sync completed', 'sheetsync-for-woocommerce' ),
            'system'    => __( 'System', 'sheetsync-for-woocommerce' ),
        );
        $key = self::human_category( $log );
        return $labels[ $key ] ?? $labels['system'];
    }

    /**
     * Plain-language sync type (replaces export/import/manual in the table).
     */
    public static function human_sync_type_label( string $sync_type ): string {
        $labels = array(
            'export' => __( 'Export to sheet', 'sheetsync-for-woocommerce' ),
            'import' => __( 'Import from sheet', 'sheetsync-for-woocommerce' ),
            'manual' => __( 'Manual sync', 'sheetsync-for-woocommerce' ),
            'job'    => __( 'Background job', 'sheetsync-for-woocommerce' ),
            'error'  => __( 'Error', 'sheetsync-for-woocommerce' ),
            'debug'  => __( 'Debug', 'sheetsync-for-woocommerce' ),
        );
        return $labels[ $sync_type ] ?? ucfirst( $sync_type );
    }

    /**
     * Plain-language status label.
     */
    public static function human_status_label( string $status ): string {
        $labels = array(
            'success' => __( 'OK', 'sheetsync-for-woocommerce' ),
            'error'   => __( 'Failed', 'sheetsync-for-woocommerce' ),
            'partial' => __( 'Partial', 'sheetsync-for-woocommerce' ),
        );
        return $labels[ $status ] ?? ucfirst( $status );
    }

    /**
     * Suggested recovery links for a log row.
     *
     * @param array<string, mixed> $log
     * @return array<int, array{label: string, url: string, external?: bool}>
     */
    public static function recovery_actions( array $log ): array {
        $category      = self::human_category( $log );
        $connection_id = (int) ( $log['connection_id'] ?? 0 );
        $actions       = array();

        $conn_edit = $connection_id > 0
            ? admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$connection_id}" )
            : '';
        $conn_logs = $connection_id > 0
            ? admin_url( "admin.php?page=sheetsync-logs&connection_id={$connection_id}" )
            : admin_url( 'admin.php?page=sheetsync-logs' );

        switch ( $category ) {
            case 'scheduler':
                $health = function_exists( 'sheetsync_get_action_scheduler_health' )
                    ? sheetsync_get_action_scheduler_health()
                    : array();
                $actions[] = array(
                    'label'    => __( 'Fix Scheduled Actions', 'sheetsync-for-woocommerce' ),
                    'url'      => (string) ( $health['tools_url'] ?? admin_url( 'admin.php?page=wc-status&tab=action-scheduler' ) ),
                    'external' => true,
                );
                $actions[] = array(
                    'label' => __( 'Background Cron setup', 'sheetsync-for-woocommerce' ),
                    'url'   => admin_url( 'admin.php?page=sheetsync-settings' ),
                );
                break;

            case 'server':
                $actions[] = array(
                    'label' => __( 'Background Cron setup', 'sheetsync-for-woocommerce' ),
                    'url'   => admin_url( 'admin.php?page=sheetsync-settings' ),
                );
                if ( $conn_edit !== '' ) {
                    $actions[] = array(
                        'label' => __( 'Retry sync', 'sheetsync-for-woocommerce' ),
                        'url'   => $conn_edit . '#tab-sync',
                    );
                }
                break;

            case 'google':
                $actions[] = array(
                    'label' => __( 'Reconnect Google', 'sheetsync-for-woocommerce' ),
                    'url'   => admin_url( 'admin.php?page=sheetsync-settings' ),
                );
                break;

            case 'product':
                if ( $conn_edit !== '' ) {
                    $message = (string) ( $log['message'] ?? '' );
                    if ( preg_match( '/\brow\s+(\d+)/i', $message, $m ) ) {
                        $actions[] = array(
                            'label' => sprintf(
                                /* translators: %d: sheet row number */
                                __( 'Check row %d', 'sheetsync-for-woocommerce' ),
                                (int) $m[1]
                            ),
                            'url' => $conn_edit . '#tab-field-mapping',
                        );
                    }
                    $actions[] = array(
                        'label' => __( 'Review field mapping', 'sheetsync-for-woocommerce' ),
                        'url'   => $conn_edit . '#tab-field-mapping',
                    );
                    $actions[] = array(
                        'label' => __( 'Validate sheet', 'sheetsync-for-woocommerce' ),
                        'url'   => $conn_edit . '#tab-field-mapping',
                    );
                }
                break;

            case 'sheet':
                if ( $conn_edit !== '' ) {
                    $message = (string) ( $log['message'] ?? '' );
                    $tab_err = function_exists( 'sheetsync_is_sheet_tab_error' )
                        && sheetsync_is_sheet_tab_error( $message );
                    if ( $tab_err ) {
                        $actions[] = array(
                            'label' => __( 'Fix connection', 'sheetsync-for-woocommerce' ),
                            'url'   => $conn_edit . '#tab-connection',
                        );
                    }
                    $actions[] = array(
                        'label' => __( 'Open connection', 'sheetsync-for-woocommerce' ),
                        'url'   => $conn_edit . '#tab-sync',
                    );
                }
                break;

            default:
                break;
        }

        if ( 'error' === (string) ( $log['status'] ?? '' ) || 'partial' === (string) ( $log['status'] ?? '' ) ) {
            $message   = (string) ( $log['message'] ?? '' );
            $tab_error = function_exists( 'sheetsync_is_sheet_tab_error' )
                && sheetsync_is_sheet_tab_error( $message );
            if ( $conn_edit !== '' && 'sync' !== $category && ! $tab_error ) {
                $actions[] = array(
                    'label' => __( 'Retry sync', 'sheetsync-for-woocommerce' ),
                    'url'   => $conn_edit . '#tab-sync',
                );
            }
        }

        $actions[] = array(
            'label' => __( 'View all logs', 'sheetsync-for-woocommerce' ),
            'url'   => $conn_logs,
        );

        // De-duplicate by URL.
        $seen = array();
        $out  = array();
        foreach ( $actions as $action ) {
            $url = $action['url'] ?? '';
            if ( $url === '' || isset( $seen[ $url ] ) ) {
                continue;
            }
            $seen[ $url ] = true;
            $out[]        = $action;
        }

        return $out;
    }

    /**
     * Recent error or partial logs for a connection (merchant-facing summary).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent_issues( ?int $connection_id, int $limit = 5 ): array {
        if ( ! $connection_id ) {
            return array();
        }

        global $wpdb;
        $limit = max( 1, min( 20, $limit ) );

        $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT l.*, c.name as connection_name
                 FROM {$wpdb->prefix}sheetsync_logs l
                 LEFT JOIN {$wpdb->prefix}sheetsync_connections c ON l.connection_id = c.id
                 WHERE l.connection_id = %d AND l.status IN ('error', 'partial')
                 ORDER BY l.created_at DESC
                 LIMIT %d",
                $connection_id,
                $limit
            ),
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Get recent logs, optionally filtered by connection.
     */
    public static function get_logs( int $limit = 50, ?int $connection_id = null ): array {
        global $wpdb;

        if ( $connection_id ) {
            $cache_key = "sheetsync_logs_{$connection_id}";
            $results   = wp_cache_get( $cache_key, 'sheetsync' );

            if ( false === $results ) {
                $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->prepare(
                        "SELECT l.*, c.name as connection_name
                         FROM {$wpdb->prefix}sheetsync_logs l
                         LEFT JOIN {$wpdb->prefix}sheetsync_connections c ON l.connection_id = c.id
                         WHERE l.connection_id = %d
                         ORDER BY l.created_at DESC
                         LIMIT %d",
                        $connection_id,
                        $limit
                    ),
                    ARRAY_A
                );
                wp_cache_set( $cache_key, $results, 'sheetsync', MINUTE_IN_SECONDS * 5 );
            }

            return $results ?: array();
        }

        $cache_key = 'sheetsync_logs_all';
        $results   = wp_cache_get( $cache_key, 'sheetsync' );

        if ( false === $results ) {
            $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prepare(
                    "SELECT l.*, c.name as connection_name
                     FROM {$wpdb->prefix}sheetsync_logs l
                     LEFT JOIN {$wpdb->prefix}sheetsync_connections c ON l.connection_id = c.id
                     ORDER BY l.created_at DESC
                     LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );
            wp_cache_set( $cache_key, $results, 'sheetsync', MINUTE_IN_SECONDS * 5 );
        }

        return $results ?: array();
    }

    /**
     * Unix timestamp for a log row (site timezone).
     */
    public static function log_timestamp( string $created_at ): int {
        if ( '' === $created_at ) {
            return 0;
        }
        $local = get_date_from_gmt( $created_at, 'Y-m-d H:i:s' );
        return (int) strtotime( $local ?: $created_at );
    }

    /**
     * Count log rows (optionally per connection).
     */
    public static function count_logs( ?int $connection_id = null ): int {
        global $wpdb;
        $table = "{$wpdb->prefix}sheetsync_logs";
        if ( $connection_id ) {
            return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE connection_id = %d", $connection_id )
            );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Delete all logs, or only logs for one connection.
     *
     * @return int Rows deleted.
     */
    public static function clear_logs( ?int $connection_id = null ): int {
        global $wpdb;
        $table = "{$wpdb->prefix}sheetsync_logs";

        if ( $connection_id ) {
            $deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $table,
                array( 'connection_id' => $connection_id ),
                array( '%d' )
            );
            wp_cache_delete( "sheetsync_logs_{$connection_id}", 'sheetsync' );
        } else {
            $deleted = $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        }

        wp_cache_delete( 'sheetsync_logs_all', 'sheetsync' );
        delete_transient( 'sheetsync_log_pruned' );

        return is_int( $deleted ) ? $deleted : 0;
    }

    /**
     * Delete logs older than the retention period.
     */
    private static function prune_old_logs(): void {
        $settings       = get_option( 'sheetsync_settings', array() );
        $retention_days = absint( $settings['log_retention_days'] ?? 30 );

        if ( $retention_days < 1 ) {
            return;
        }

        if ( get_transient( 'sheetsync_log_pruned' ) ) {
            return;
        }

        global $wpdb;
        $wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->prefix}sheetsync_logs
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ) );

        set_transient( 'sheetsync_log_pruned', true, DAY_IN_SECONDS );
    }

    /**
     * Aggregate sync log stats since a UTC datetime (for email reports).
     *
     * @return array{runs:int,success:int,partial:int,error:int,rows_processed:int,rows_errored:int}
     */
    public static function get_period_stats( string $since_utc ): array {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS runs,
                    SUM( CASE WHEN status = 'success' THEN 1 ELSE 0 END ) AS success_cnt,
                    SUM( CASE WHEN status = 'partial' THEN 1 ELSE 0 END ) AS partial_cnt,
                    SUM( CASE WHEN status = 'error' THEN 1 ELSE 0 END ) AS error_cnt,
                    COALESCE( SUM( rows_processed ), 0 ) AS rows_processed,
                    COALESCE( SUM( rows_errored ), 0 ) AS rows_errored
                 FROM {$wpdb->prefix}sheetsync_logs
                 WHERE created_at >= %s
                   AND sync_type IN ('job', 'manual', 'export', 'cron')",
                $since_utc
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return array(
                'runs'           => 0,
                'success'        => 0,
                'partial'        => 0,
                'error'          => 0,
                'rows_processed' => 0,
                'rows_errored'   => 0,
            );
        }

        return array(
            'runs'           => (int) ( $row['runs'] ?? 0 ),
            'success'        => (int) ( $row['success_cnt'] ?? 0 ),
            'partial'        => (int) ( $row['partial_cnt'] ?? 0 ),
            'error'          => (int) ( $row['error_cnt'] ?? 0 ),
            'rows_processed' => (int) ( $row['rows_processed'] ?? 0 ),
            'rows_errored'   => (int) ( $row['rows_errored'] ?? 0 ),
        );
    }

    /**
     * Per-connection activity since a UTC datetime.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_period_by_connection( string $since_utc, int $limit = 12 ): array {
        global $wpdb;
        $limit = max( 1, min( 50, $limit ) );

        $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT l.connection_id,
                        COALESCE( c.name, CONCAT( 'Connection #', l.connection_id ) ) AS connection_name,
                        COUNT(*) AS runs,
                        SUM( CASE WHEN l.status = 'error' THEN 1 ELSE 0 END ) AS errors,
                        COALESCE( SUM( l.rows_processed ), 0 ) AS rows_processed
                 FROM {$wpdb->prefix}sheetsync_logs l
                 LEFT JOIN {$wpdb->prefix}sheetsync_connections c ON c.id = l.connection_id
                 WHERE l.created_at >= %s
                   AND l.sync_type IN ('job', 'manual', 'export', 'cron')
                 GROUP BY l.connection_id, c.name
                 ORDER BY runs DESC, rows_processed DESC
                 LIMIT %d",
                $since_utc,
                $limit
            ),
            ARRAY_A
        );

        return is_array( $results ) ? $results : array();
    }

    /**
     * Site-wide recent issues for report digests.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_period_issues( string $since_utc, int $limit = 5 ): array {
        global $wpdb;
        $limit = max( 1, min( 20, $limit ) );

        $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT l.*, c.name AS connection_name
                 FROM {$wpdb->prefix}sheetsync_logs l
                 LEFT JOIN {$wpdb->prefix}sheetsync_connections c ON l.connection_id = c.id
                 WHERE l.created_at >= %s
                   AND l.status IN ('error', 'partial')
                 ORDER BY l.created_at DESC
                 LIMIT %d",
                $since_utc,
                $limit
            ),
            ARRAY_A
        );

        return is_array( $results ) ? $results : array();
    }
}

endif; // class_exists SheetSync_Logger
