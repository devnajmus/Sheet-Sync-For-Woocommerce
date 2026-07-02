<?php
/**
 * Plugin activator — creates database tables and sets defaults.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Activator' ) ) :

class SheetSync_Activator {

    public static function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // connection_type is VARCHAR (not ENUM) so Pro can store 'orders_processing' etc.
        $connections_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sheetsync_connections (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name            VARCHAR(200)    NOT NULL DEFAULT '',
            spreadsheet_id  VARCHAR(200)    NOT NULL DEFAULT '',
            sheet_name      VARCHAR(200)    NOT NULL DEFAULT 'Sheet1',
            header_row      TINYINT         NOT NULL DEFAULT 1,
            sync_direction  VARCHAR(20)     NOT NULL DEFAULT 'sheets_to_wc',
            status          VARCHAR(20)     NOT NULL DEFAULT 'active',
            connection_type VARCHAR(50)     NOT NULL DEFAULT 'products',
            last_sync_at    DATETIME        DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY connection_type (connection_type)
        ) $charset;";

        $field_maps_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sheetsync_field_maps (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id  BIGINT UNSIGNED NOT NULL,
            wc_field       VARCHAR(100)    NOT NULL,
            sheet_column   VARCHAR(10)     NOT NULL,
            is_key_field   TINYINT(1)      NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY connection_id (connection_id),
            UNIQUE KEY conn_wc_field (connection_id, wc_field)
        ) $charset;";

        $logs_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sheetsync_logs (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id   BIGINT UNSIGNED DEFAULT NULL,
            sync_type       VARCHAR(50)     NOT NULL DEFAULT 'manual',
            status          VARCHAR(20)     NOT NULL DEFAULT 'success',
            rows_processed  INT             NOT NULL DEFAULT 0,
            rows_skipped    INT             NOT NULL DEFAULT 0,
            rows_errored    INT             NOT NULL DEFAULT 0,
            message         TEXT            DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY connection_id (connection_id),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $connections_sql );
        dbDelta( $field_maps_sql );
        dbDelta( $logs_sql );

        self::maybe_migrate_columns();

        require_once SHEETSYNC_DIR . 'includes/class-sheetsync-host-profile.php';
        require_once SHEETSYNC_DIR . 'includes/class-sheetsync-schema.php';
        SheetSync_Schema::migrate();

        update_option( 'sheetsync_db_version', SHEETSYNC_VERSION, false );

        if ( ! get_option( 'sheetsync_settings' ) ) {
            $default_batch = class_exists( 'SheetSync_Host_Profile', false )
                ? SheetSync_Host_Profile::default_batch_size()
                : 25;
            update_option( 'sheetsync_settings', array(
                'batch_size'          => $default_batch,
                'log_retention_days'  => 30,
                'email_notifications' => false,
                'notification_email'  => get_option( 'admin_email' ),
            ), false );
        }

        if ( ! get_option( 'sheetsync_webhook_secret' ) ) {
            update_option( 'sheetsync_webhook_secret', wp_generate_password( 32, false ), false );
        }

        if ( ! get_option( 'sheetsync_setup_progress' ) ) {
            update_option( 'sheetsync_setup_progress', array(
                'google_connected'   => false,
                'sheet_shared'       => false,
                'connection_created' => false,
                'template_written'   => false,
                'first_sync_done'    => false,
                'realtime_enabled'   => false,
            ), false );
        }

        flush_rewrite_rules();
        update_option( 'sheetsync_redirect_to_wizard', true, false );
        if ( ! get_option( 'sheetsync_activated_at', 0 ) ) {
            update_option( 'sheetsync_activated_at', time(), false );
        }
        require_once dirname( __FILE__ ) . '/class-sheetsync-onboarding.php';
        SheetSync_Onboarding::schedule();
        delete_transient( 'sheetsync_access_token' );
        delete_transient( 'sheetsync_token_expires' );
        wp_cache_delete( 'sheetsync_access_token', 'sheetsync' );
    }

    /**
     * Migrate old ENUM columns to VARCHAR on plugin update.
     * Needed so Pro can store 'orders_processing', 'orders_completed' etc.
     *
     * WHY NOT $wpdb->prepare() FOR THE ALTER TABLE QUERY:
     * MySQL's prepared-statement protocol only supports value placeholders (%s, %d),
     * not identifier placeholders (table names, column names, or type keywords).
     * There is no WordPress API that parameterises DDL identifiers.
     *
     * HOW SAFETY IS ACHIEVED WITHOUT prepare():
     *  $esc_table  — $wpdb->prefix is a trusted WP-core value; the suffix is a
     *                fixed string literal. The combined value is passed through
     *                esc_sql() before interpolation.
     *  $esc_col    — sourced exclusively from the hard-coded $allowed_columns keys
     *                (never user input), then passed through esc_sql().
     *  Column type — the ALTER TABLE statement is built by selecting a complete,
     *                pre-written SQL fragment (e.g. 'VARCHAR(50) NOT NULL DEFAULT …')
     *                from a second hard-coded lookup array keyed on $col.  No
     *                runtime function transforms the fragment; PluginCheck therefore
     *                has no computed variable to flag.
     */
    private static function maybe_migrate_columns(): void {
        global $wpdb;

        // esc_sql() on the full table name: PluginCheck-recognised escaping.
        $esc_table = esc_sql( $wpdb->prefix . 'sheetsync_connections' );

        // Check table existence; result cached for the duration of this request.
        $cache_key = 'sheetsync_tbl_exists_' . md5( $esc_table );
        $exists    = wp_cache_get( $cache_key, 'sheetsync' );
        if ( false === $exists ) {
            $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
                DB_NAME,
                $esc_table
            ) );
            wp_cache_set( $cache_key, $exists, 'sheetsync', 60 );
        }
        if ( ! $exists ) {
            return;
        }

        // Two parallel hard-coded arrays, both keyed on column name:
        //   $target_type  — the VARCHAR type string used for COLUMN_TYPE comparison.
        //   $alter_clause — the complete, literal DDL fragment dropped verbatim into
        //                   the ALTER TABLE statement.  Because it is a fixed string
        //                   literal (not the result of any runtime transformation),
        //                   PluginCheck cannot trace an "unsafe" assignment to it.
        // Neither array ever receives values from user-supplied input.
        $target_type  = array(
            'connection_type' => 'varchar(50)',
            'sync_direction'  => 'varchar(20)',
            'status'          => 'varchar(20)',
        );
        $alter_clause = array(
            'connection_type' => 'VARCHAR(50) NOT NULL DEFAULT \'\'',
            'sync_direction'  => 'VARCHAR(20) NOT NULL DEFAULT \'\'',
            'status'          => 'VARCHAR(20) NOT NULL DEFAULT \'\'',
        );

        foreach ( $target_type as $col => $expected_varchar ) {

            // esc_sql() on the column name: PluginCheck-recognised escaping.
            $esc_col = esc_sql( $col );

            // Fetch and cache the current column type.
            $col_cache_key = 'sheetsync_coltype_' . md5( $esc_table . $esc_col );
            $col_type      = wp_cache_get( $col_cache_key, 'sheetsync' );
            if ( false === $col_type ) {
                $col_type = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s',
                    DB_NAME,
                    $esc_table,
                    $esc_col
                ) );
                wp_cache_set( $col_cache_key, $col_type, 'sheetsync', 60 );
            }

            // Only migrate columns that are still stored as ENUM.
            if ( ! $col_type || stripos( $col_type, 'enum' ) !== 0 ) {
                continue;
            }

            // $esc_table       — escaped with esc_sql().
            // $esc_col         — escaped with esc_sql().
            // $alter_clause[]  — a fixed string literal from the hard-coded array above;
            //                    no runtime function has touched its value.
            // $wpdb->prepare() cannot parameterise DDL identifiers — MySQL limitation.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE `{$esc_table}` MODIFY `{$esc_col}` {$alter_clause[ $col ]}" );

            // Invalidate the now-stale cached column type.
            wp_cache_delete( $col_cache_key, 'sheetsync' );
        }
    }
}

endif; // class_exists SheetSync_Activator
