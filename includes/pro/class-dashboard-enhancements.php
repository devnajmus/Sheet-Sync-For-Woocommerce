<?php
/**
 * Dashboard enhancements: scheduled exports, export log, presets, alerts, onboarding.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Dashboard_Enhancements' ) ) :

class SheetSync_Dashboard_Enhancements {

    public const HOOK_SALES  = 'sheetsync_scheduled_sales_export';
    public const HOOK_INV    = 'sheetsync_scheduled_inventory_export';
    public const HOOK_ORDERS = 'sheetsync_scheduled_order_export';
    public const LOG_OPTION  = 'sheetsync_dashboard_export_log';
    public const PRESETS_OPT = 'sheetsync_boe_export_presets';

    public function __construct() {
        add_action( self::HOOK_SALES,  array( $this, 'run_scheduled_sales_export' ) );
        add_action( self::HOOK_INV,    array( $this, 'run_scheduled_inventory_export' ) );
        add_action( self::HOOK_ORDERS,  array( $this, 'run_scheduled_order_export' ) );

        add_action( 'admin_init', array( $this, 'ensure_cron_from_settings' ), 20 );

        add_action( 'wp_ajax_sheetsync_get_export_log',       array( $this, 'ajax_get_export_log' ) );
        add_action( 'wp_ajax_sheetsync_save_boe_preset',      array( $this, 'ajax_save_boe_preset' ) );
        add_action( 'wp_ajax_sheetsync_delete_boe_preset',    array( $this, 'ajax_delete_boe_preset' ) );
        add_action( 'wp_ajax_sheetsync_get_boe_presets',      array( $this, 'ajax_get_boe_presets' ) );
        add_action( 'wp_ajax_sheetsync_update_onboarding',   array( $this, 'ajax_update_onboarding' ) );
        add_action( 'wp_ajax_sheetsync_get_onboarding_state', array( $this, 'ajax_get_onboarding_state' ) );

        add_action( 'wp_dashboard_setup', array( $this, 'register_wp_dashboard_widget' ) );
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
    }

    public function ensure_cron_from_settings(): void {
        if ( get_option( 'sheetsync_dashboard_cron_synced' ) === '3' ) {
            return;
        }
        self::reschedule_all( self::get_settings() );
        update_option( 'sheetsync_dashboard_cron_synced', '3', false );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_settings(): array {
        $defaults = array(
            'sd_spreadsheet_id'       => '',
            'sd_sheet_name'           => 'Sales Dashboard',
            'sd_period'               => '7days',
            'sd_schedule_enabled'     => '0',
            'sd_schedule_interval'    => 'daily',
            'sd_monthly_goal'         => '',
            'inv_spreadsheet_id'      => '',
            'inv_sheet_name'          => 'Inventory Status',
            'inv_low_stock'           => 5,
            'inv_schedule_enabled'    => '0',
            'inv_schedule_interval'   => 'daily',
            'inv_email_low_stock'     => '1',
            'boe_spreadsheet_id'      => '',
            'boe_sheet_name'          => 'Orders Export',
            'boe_schedule_enabled'    => '0',
            'boe_schedule_interval'   => 'weekly',
            'boe_schedule_preset_id'  => '',
            'export_notify_email'     => '',
            'last_export_sales'       => '',
            'last_export_inventory'   => '',
            'last_export_orders'      => '',
            'demo_mode'               => '0',
            'access_sales_roles'      => 'administrator,shop_manager',
            'access_inventory_roles'  => 'administrator,shop_manager',
            'access_orders_roles'     => 'administrator,shop_manager',
        );

        if ( class_exists( 'SheetSync_Dashboard_Phase3', false ) ) {
            $defaults = wp_parse_args( $defaults, SheetSync_Dashboard_Phase3::settings_defaults() );
        }

        return wp_parse_args(
            get_option( 'sheetsync_dashboard_settings', array() ),
            class_exists( 'SheetSync_Dashboard_Phase2', false )
                ? wp_parse_args( $defaults, SheetSync_Dashboard_Phase2::settings_defaults() )
                : $defaults
        );
    }

    /**
     * @param array<string, mixed> $incoming
     */
    public static function sanitize_settings( array $incoming ): array {
        $current = self::get_settings();

        $clean = array(
            'sd_spreadsheet_id'    => sanitize_text_field( $incoming['sd_spreadsheet_id'] ?? $current['sd_spreadsheet_id'] ),
            'sd_sheet_name'        => sanitize_text_field( $incoming['sd_sheet_name'] ?? $current['sd_sheet_name'] ),
            'sd_period'            => sanitize_text_field( $incoming['sd_period'] ?? $current['sd_period'] ),
            'sd_schedule_enabled'  => ! empty( $incoming['sd_schedule_enabled'] ) ? '1' : '0',
            'sd_schedule_interval' => sanitize_key( $incoming['sd_schedule_interval'] ?? $current['sd_schedule_interval'] ),
            'sd_monthly_goal'      => sanitize_text_field( $incoming['sd_monthly_goal'] ?? $current['sd_monthly_goal'] ),
            'inv_spreadsheet_id'   => sanitize_text_field( $incoming['inv_spreadsheet_id'] ?? $current['inv_spreadsheet_id'] ),
            'inv_sheet_name'       => sanitize_text_field( $incoming['inv_sheet_name'] ?? $current['inv_sheet_name'] ),
            'inv_low_stock'        => absint( $incoming['inv_low_stock'] ?? $current['inv_low_stock'] ),
            'inv_schedule_enabled' => ! empty( $incoming['inv_schedule_enabled'] ) ? '1' : '0',
            'inv_schedule_interval'=> sanitize_key( $incoming['inv_schedule_interval'] ?? $current['inv_schedule_interval'] ),
            'inv_email_low_stock'  => ! empty( $incoming['inv_email_low_stock'] ) ? '1' : '0',
            'boe_spreadsheet_id'   => sanitize_text_field( $incoming['boe_spreadsheet_id'] ?? $current['boe_spreadsheet_id'] ),
            'boe_sheet_name'       => sanitize_text_field( $incoming['boe_sheet_name'] ?? $current['boe_sheet_name'] ),
            'boe_schedule_enabled' => ! empty( $incoming['boe_schedule_enabled'] ) ? '1' : '0',
            'boe_schedule_interval'=> sanitize_key( $incoming['boe_schedule_interval'] ?? $current['boe_schedule_interval'] ),
            'boe_schedule_preset_id' => sanitize_key( $incoming['boe_schedule_preset_id'] ?? $current['boe_schedule_preset_id'] ?? '' ),
            'export_notify_email'  => self::normalize_notify_email( $incoming['export_notify_email'] ?? $current['export_notify_email'] ),
            'last_export_sales'    => sanitize_text_field( $incoming['last_export_sales'] ?? $current['last_export_sales'] ),
            'last_export_inventory'=> sanitize_text_field( $incoming['last_export_inventory'] ?? $current['last_export_inventory'] ),
            'last_export_orders'   => sanitize_text_field( $incoming['last_export_orders'] ?? $current['last_export_orders'] ),
            'demo_mode'              => ! empty( $incoming['demo_mode'] ) ? '1' : '0',
            'access_sales_roles'     => self::sanitize_role_list( $incoming['access_sales_roles'] ?? $current['access_sales_roles'] ?? 'administrator,shop_manager' ),
            'access_inventory_roles' => self::sanitize_role_list( $incoming['access_inventory_roles'] ?? $current['access_inventory_roles'] ?? 'administrator,shop_manager' ),
            'access_orders_roles'    => self::sanitize_role_list( $incoming['access_orders_roles'] ?? $current['access_orders_roles'] ?? 'administrator,shop_manager' ),
        );

        if ( class_exists( 'SheetSync_Dashboard_Phase3', false ) ) {
            $clean = array_merge( $clean, SheetSync_Dashboard_Phase3::sanitize_settings( $incoming, $current ) );
        }

        $notices = self::collect_settings_notices( $clean, $incoming );

        update_option( 'sheetsync_dashboard_settings', $clean );
        self::reschedule_all( $clean );

        if ( ! empty( $notices ) ) {
            $clean['notices'] = $notices;
        }

        return self::settings_for_client( $clean );
    }

    /**
     * Settings payload for admin UI (includes runtime schedule info, not persisted).
     *
     * @param array<string, mixed>|null $settings
     * @return array<string, mixed>
     */
    public static function settings_for_client( ?array $settings = null ): array {
        $settings = $settings ?? self::get_settings();
        $settings['schedule_status'] = self::get_schedule_status();
        $notices                     = self::get_persistent_notices( $settings );
        if ( ! empty( $notices ) ) {
            $settings['notices'] = $notices;
        }
        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    public static function get_persistent_notices( array $settings ): array {
        $notices = array();

        if ( ! empty( $settings['sd_schedule_enabled'] ) && empty( $settings['sd_spreadsheet_id'] ) ) {
            $notices[] = __( 'Sales auto-export is enabled but no Spreadsheet ID is saved yet.', 'sheetsync-for-woocommerce' );
        }
        if ( ! empty( $settings['inv_schedule_enabled'] ) && empty( $settings['inv_spreadsheet_id'] ) ) {
            $notices[] = __( 'Inventory auto-export is enabled but no Spreadsheet ID is saved yet.', 'sheetsync-for-woocommerce' );
        }
        if ( ! empty( $settings['boe_schedule_enabled'] ) && empty( $settings['boe_spreadsheet_id'] ) ) {
            $notices[] = __( 'Order auto-export is enabled but no Spreadsheet ID is saved yet.', 'sheetsync-for-woocommerce' );
        }

        if ( ! empty( $settings['multistore_enabled'] ) && $settings['multistore_enabled'] === '1' && ! is_multisite() ) {
            $notices[] = __( 'Multi-store rollup requires a WordPress multisite network. Site IDs are ignored on single-site installs.', 'sheetsync-for-woocommerce' );
        }

        if ( class_exists( 'SheetSync_Dashboard_Phase3', false ) ) {
            $notices = array_merge( $notices, SheetSync_Dashboard_Phase3::get_persistent_notices( $settings ) );
        }

        return array_values( array_unique( $notices ) );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_schedule_status(): array {
        $settings = self::get_settings();
        $map      = array(
            'sales'     => array( 'hook' => self::HOOK_SALES, 'enabled' => 'sd_schedule_enabled', 'interval' => 'sd_schedule_interval' ),
            'inventory' => array( 'hook' => self::HOOK_INV, 'enabled' => 'inv_schedule_enabled', 'interval' => 'inv_schedule_interval' ),
            'orders'    => array( 'hook' => self::HOOK_ORDERS, 'enabled' => 'boe_schedule_enabled', 'interval' => 'boe_schedule_interval' ),
        );

        $uses_as = self::uses_action_scheduler();

        $status = array(
            'wp_cron_enabled' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            'scheduler'       => $uses_as ? 'action_scheduler' : 'wp_cron',
            'cron_command'    => self::get_system_cron_command(),
        );

        foreach ( $map as $type => $meta ) {
            $enabled = ! empty( $settings[ $meta['enabled'] ] ) && $settings[ $meta['enabled'] ] === '1';
            $next    = $enabled ? self::get_next_scheduled_time( $meta['hook'] ) : false;
            $status[ $type ] = array(
                'enabled'  => $enabled,
                'interval' => (string) ( $settings[ $meta['interval'] ] ?? 'daily' ),
                'next_run' => $next ? wp_date( 'M j, Y g:i A', $next ) : '',
            );
        }

        return $status;
    }

    /**
     * Prefer WooCommerce Action Scheduler when available (standard for commercial Woo plugins).
     */
    public static function uses_action_scheduler(): bool {
        return function_exists( 'as_schedule_recurring_action' )
            && function_exists( 'as_unschedule_all_actions' )
            && function_exists( 'as_next_scheduled_action' );
    }

    /**
     * @return int|false Unix timestamp for next run.
     */
    private static function get_next_scheduled_time( string $hook ) {
        if ( self::uses_action_scheduler() ) {
            $next = as_next_scheduled_action( $hook, array(), 'sheetsync-dashboard' );
            if ( $next ) {
                return (int) $next;
            }
        }

        $wp_next = wp_next_scheduled( $hook );
        return $wp_next ? (int) $wp_next : false;
    }

    /**
     * Suggested server cron for hosts that disable WP-Cron.
     */
    public static function get_system_cron_command(): string {
        $url = add_query_arg( 'doing_wp_cron', '', site_url( 'wp-cron.php' ) );
        return '*/15 * * * * curl -s ' . $url . ' > /dev/null 2>&1';
    }

    /**
     * @return int Interval in seconds.
     */
    private static function interval_to_seconds( string $interval ): int {
        return match ( $interval ) {
            'hourly'     => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'weekly'     => WEEK_IN_SECONDS,
            default      => DAY_IN_SECONDS,
        };
    }

    private static function clear_schedule( string $hook ): void {
        wp_clear_scheduled_hook( $hook );
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( $hook, array(), 'sheetsync-dashboard' );
        }
    }

    /**
     * Cap heavy dashboard periods for unattended scheduled exports.
     */
    public static function resolve_scheduled_sales_period( string $period ): string {
        $heavy = array( '6months', '12months', 'ytd', 'all', 'custom' );
        if ( in_array( $period, $heavy, true ) ) {
            return '30days';
        }
        return $period !== '' ? $period : '7days';
    }

    /**
     * @return string|null Skip reason, or null when export may proceed.
     */
    private static function scheduled_export_skip_reason(): ?string {
        if ( class_exists( 'SheetSync_Dashboard_Phase2', false ) && SheetSync_Dashboard_Phase2::is_demo_mode() ) {
            return __( 'Scheduled export skipped: turn off Demo Mode to export live store data.', 'sheetsync-for-woocommerce' );
        }
        return null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function reschedule_all( array $settings ): void {
        self::reschedule_one( self::HOOK_SALES, ! empty( $settings['sd_schedule_enabled'] ), $settings['sd_schedule_interval'] ?? 'daily' );
        self::reschedule_one( self::HOOK_INV, ! empty( $settings['inv_schedule_enabled'] ), $settings['inv_schedule_interval'] ?? 'daily' );
        self::reschedule_one( self::HOOK_ORDERS, ! empty( $settings['boe_schedule_enabled'] ), $settings['boe_schedule_interval'] ?? 'weekly' );
    }

    private static function reschedule_one( string $hook, bool $enabled, string $interval ): void {
        self::clear_schedule( $hook );
        if ( ! $enabled ) {
            return;
        }
        $allowed = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
        if ( ! in_array( $interval, $allowed, true ) ) {
            $interval = 'daily';
        }

        $start = time() + HOUR_IN_SECONDS;

        if ( self::uses_action_scheduler() ) {
            as_schedule_recurring_action(
                $start,
                self::interval_to_seconds( $interval ),
                $hook,
                array(),
                'sheetsync-dashboard'
            );
            return;
        }

        wp_schedule_event( $start, $interval, $hook );
    }

    /**
     * @param array<string, string> $schedules
     * @return array<string, string>
     */
    public function add_cron_schedules( array $schedules ): array {
        $schedules['weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Once Weekly', 'sheetsync-for-woocommerce' ),
        );
        return $schedules;
    }

    public function run_scheduled_sales_export(): void {
        $settings = self::get_settings();
        $skip     = self::scheduled_export_skip_reason();
        if ( $skip ) {
            if ( ! empty( $settings['sd_schedule_enabled'] ) ) {
                self::log_export( 'sales', false, $skip, array() );
                self::send_export_failure_email( __( 'Sales Dashboard', 'sheetsync-for-woocommerce' ), $skip );
            }
            return;
        }

        if ( empty( $settings['sd_spreadsheet_id'] ) || ! class_exists( 'SheetSync_Sales_Dashboard', false ) ) {
            if ( ! empty( $settings['sd_schedule_enabled'] ) ) {
                self::log_export(
                    'sales',
                    false,
                    __( 'Scheduled sales export skipped: add a Spreadsheet ID on the Sales Dashboard tab.', 'sheetsync-for-woocommerce' ),
                    array()
                );
            }
            return;
        }

        if ( function_exists( 'wc_set_time_limit' ) ) {
            wc_set_time_limit( 300 );
        } elseif ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        try {
            $dash   = new SheetSync_Sales_Dashboard();
            $period = self::resolve_scheduled_sales_period( (string) ( $settings['sd_period'] ?? '7days' ) );
            $result = $dash->export_to_sheets(
                $settings['sd_spreadsheet_id'],
                $settings['sd_sheet_name'] ?: 'Sales Dashboard',
                $period,
                array()
            );
            self::log_export( 'sales', true, $result['message'] ?? __( 'Sales export completed.', 'sheetsync-for-woocommerce' ), $result );
            self::send_export_email( __( 'Sales Dashboard', 'sheetsync-for-woocommerce' ), $result );
        } catch ( Exception $e ) {
            self::log_export( 'sales', false, $e->getMessage(), array() );
            self::send_export_failure_email( __( 'Sales Dashboard', 'sheetsync-for-woocommerce' ), $e->getMessage() );
        }
    }

    public function run_scheduled_inventory_export(): void {
        $settings = self::get_settings();
        $skip     = self::scheduled_export_skip_reason();
        if ( $skip ) {
            if ( ! empty( $settings['inv_schedule_enabled'] ) ) {
                self::log_export( 'inventory', false, $skip, array() );
                self::send_export_failure_email( __( 'Inventory Dashboard', 'sheetsync-for-woocommerce' ), $skip );
            }
            return;
        }

        if ( empty( $settings['inv_spreadsheet_id'] ) || ! class_exists( 'SheetSync_Inventory_Dashboard', false ) ) {
            if ( ! empty( $settings['inv_schedule_enabled'] ) ) {
                self::log_export(
                    'inventory',
                    false,
                    __( 'Scheduled inventory export skipped: add a Spreadsheet ID on the Inventory Dashboard tab.', 'sheetsync-for-woocommerce' ),
                    array()
                );
            }
            return;
        }

        if ( function_exists( 'wc_set_time_limit' ) ) {
            wc_set_time_limit( 300 );
        } elseif ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $threshold = absint( $settings['inv_low_stock'] ?: 5 );

        try {
            $dash   = new SheetSync_Inventory_Dashboard();
            $result = $dash->export_to_sheets(
                $settings['inv_spreadsheet_id'],
                $settings['inv_sheet_name'] ?: 'Inventory Status',
                $threshold
            );
            self::log_export( 'inventory', true, $result['message'] ?? __( 'Inventory export completed.', 'sheetsync-for-woocommerce' ), $result );

            if ( ! empty( $settings['inv_email_low_stock'] ) && (int) ( $result['low_stock'] ?? 0 ) > 0 ) {
                self::send_low_stock_alert( (int) $result['low_stock'], (int) ( $result['out_of_stock'] ?? 0 ) );
            }

            self::send_export_email( __( 'Inventory Dashboard', 'sheetsync-for-woocommerce' ), $result );
        } catch ( Exception $e ) {
            self::log_export( 'inventory', false, $e->getMessage(), array() );
            self::send_export_failure_email( __( 'Inventory Dashboard', 'sheetsync-for-woocommerce' ), $e->getMessage() );
        }
    }

    public function run_scheduled_order_export(): void {
        $settings = self::get_settings();
        $skip     = self::scheduled_export_skip_reason();
        if ( $skip ) {
            if ( ! empty( $settings['boe_schedule_enabled'] ) ) {
                self::log_export( 'orders', false, $skip, array() );
                self::send_export_failure_email( __( 'Bulk Order Export', 'sheetsync-for-woocommerce' ), $skip );
            }
            return;
        }

        if ( empty( $settings['boe_spreadsheet_id'] ) || ! class_exists( 'SheetSync_Bulk_Order_Export', false ) ) {
            if ( ! empty( $settings['boe_schedule_enabled'] ) ) {
                self::log_export(
                    'orders',
                    false,
                    __( 'Scheduled order export skipped: add a Spreadsheet ID on the Bulk Order Export tab.', 'sheetsync-for-woocommerce' ),
                    array()
                );
            }
            return;
        }

        if ( function_exists( 'wc_set_time_limit' ) ) {
            wc_set_time_limit( 300 );
        } elseif ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $filters = self::get_boe_schedule_filters( $settings );

        try {
            $exporter = new SheetSync_Bulk_Order_Export();
            $result   = $exporter->export_orders_to_sheets(
                $settings['boe_spreadsheet_id'],
                $settings['boe_sheet_name'] ?: 'Orders Export',
                $filters
            );
            self::log_export( 'orders', true, $result['message'] ?? __( 'Order export completed.', 'sheetsync-for-woocommerce' ), $result );
            self::send_export_email( __( 'Bulk Order Export', 'sheetsync-for-woocommerce' ), $result );
        } catch ( Exception $e ) {
            self::log_export( 'orders', false, $e->getMessage(), array() );
            self::send_export_failure_email( __( 'Bulk Order Export', 'sheetsync-for-woocommerce' ), $e->getMessage() );
        }
    }

    /**
     * Resolve scheduled bulk-order export filters from saved preset or interval window.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function get_boe_schedule_filters( array $settings ): array {
        $preset_id = sanitize_key( (string) ( $settings['boe_schedule_preset_id'] ?? '' ) );
        if ( $preset_id !== '' ) {
            $presets = get_option( self::PRESETS_OPT, array() );
            if ( is_array( $presets ) ) {
                foreach ( $presets as $preset ) {
                    if ( ( $preset['id'] ?? '' ) === $preset_id ) {
                        return ( new SheetSync_Bulk_Order_Export() )->normalize_export_filters(
                            array(
                                'statuses'   => $preset['statuses'] ?? array(),
                                'date_from'  => $preset['date_from'] ?? '',
                                'date_to'    => $preset['date_to'] ?? '',
                                'customer'   => $preset['customer'] ?? '',
                                'min_total'  => $preset['min_total'] ?? 0,
                                'max_total'  => $preset['max_total'] ?? 0,
                                'product_id' => 0,
                                'fields'     => $preset['fields'] ?? array(),
                            )
                        );
                    }
                }
            }
        }

        $interval = sanitize_key( (string) ( $settings['boe_schedule_interval'] ?? 'weekly' ) );
        $days     = match ( $interval ) {
            'hourly', 'twicedaily', 'daily' => 1,
            'weekly' => 7,
            default => 7,
        };

        return ( new SheetSync_Bulk_Order_Export() )->normalize_export_filters(
            array(
                'statuses'  => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
                'date_from' => wp_date( 'Y-m-d', strtotime( '-' . $days . ' days' ) ),
                'date_to'   => wp_date( 'Y-m-d' ),
                'customer'  => '',
                'min_total' => 0,
                'max_total' => 0,
                'product_id'=> 0,
                'fields'    => array(),
            )
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function log_export( string $type, bool $success, string $message, array $meta = array() ): void {
        $log = get_option( self::LOG_OPTION, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }

        if ( $success ) {
            self::mark_last_export( $type );
        }

        array_unshift(
            $log,
            array(
                'type'      => sanitize_key( $type ),
                'success'   => $success,
                'message'   => sanitize_text_field( $message ),
                'meta'      => $meta,
                'user_id'   => get_current_user_id(),
                'user_name' => wp_get_current_user()->display_name ?: 'System',
                'time'      => wp_date( 'M j, Y g:i A' ),
                'timestamp' => time(),
            )
        );

        $log = array_slice( $log, 0, 50 );
        update_option( self::LOG_OPTION, $log, false );

        do_action( 'sheetsync_export_logged', $type, $success, $message, $meta );
    }

    private static function mark_last_export( string $type ): void {
        $settings = self::get_settings();
        $key      = 'last_export_' . sanitize_key( $type );
        if ( array_key_exists( $key, $settings ) ) {
            $settings[ $key ] = wp_date( 'M j, Y g:i A' );
            update_option( 'sheetsync_dashboard_settings', $settings );
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function send_export_email( string $label, array $result ): void {
        $settings = self::get_settings();
        $to       = self::resolve_notify_email( $settings );
        if ( ! $to ) {
            return;
        }

        $subject = sprintf(
            /* translators: %s dashboard name */
            __( '[SheetSync] %s exported to Google Sheets', 'sheetsync-for-woocommerce' ),
            $label
        );

        $body = sprintf(
            "<p>%s</p><p>%s</p><p><a href='%s'>%s</a></p>",
            esc_html( $result['message'] ?? __( 'Export completed successfully.', 'sheetsync-for-woocommerce' ) ),
            esc_html( wp_date( 'M j, Y g:i A' ) ),
            esc_url( admin_url( 'admin.php?page=sheetsync-dashboards' ) ),
            esc_html__( 'Open Dashboards', 'sheetsync-for-woocommerce' )
        );

        wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    private static function send_export_failure_email( string $label, string $error ): void {
        $settings = self::get_settings();
        $to       = self::resolve_notify_email( $settings );
        if ( ! $to ) {
            return;
        }

        $subject = sprintf(
            /* translators: %s dashboard name */
            __( '[SheetSync] %s export failed', 'sheetsync-for-woocommerce' ),
            $label
        );

        $body = sprintf(
            '<p>%s</p><p>%s</p><p><a href="%s">%s</a></p>',
            esc_html( $error ),
            esc_html( wp_date( 'M j, Y g:i A' ) ),
            esc_url( admin_url( 'admin.php?page=sheetsync-dashboards' ) ),
            esc_html__( 'Open Dashboards', 'sheetsync-for-woocommerce' )
        );

        wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function resolve_notify_email( array $settings ): string {
        $to = sanitize_email( (string) ( $settings['export_notify_email'] ?? '' ) );
        if ( ! $to ) {
            $to = sanitize_email( (string) get_option( 'admin_email' ) );
        }
        return $to;
    }

    /**
     * Trim spaces and validate notification email; empty string falls back to admin email at send time.
     */
    private static function normalize_notify_email( $raw ): string {
        $email = sanitize_email( trim( str_replace( ' ', '', (string) $raw ) ) );
        return $email;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $incoming
     * @return list<string>
     */
    private static function collect_settings_notices( array $clean, array $incoming ): array {
        $notices = array();

        $raw_email = trim( (string) ( $incoming['export_notify_email'] ?? '' ) );
        if ( $raw_email !== '' ) {
            $normalized = self::normalize_notify_email( $raw_email );
            if ( $normalized === '' ) {
                $notices[] = __( 'Notification email looks invalid — using the WordPress admin email instead.', 'sheetsync-for-woocommerce' );
            } elseif ( str_contains( $raw_email, ' ' ) ) {
                $notices[] = __( 'Spaces were removed from the notification email address.', 'sheetsync-for-woocommerce' );
            }
        }

        if ( ! empty( $clean['sd_schedule_enabled'] ) && empty( $clean['sd_spreadsheet_id'] ) ) {
            $notices[] = __( 'Sales auto-export is enabled but no Spreadsheet ID is saved yet.', 'sheetsync-for-woocommerce' );
        }
        if ( ! empty( $clean['inv_schedule_enabled'] ) && empty( $clean['inv_spreadsheet_id'] ) ) {
            $notices[] = __( 'Inventory auto-export is enabled but no Spreadsheet ID is saved yet.', 'sheetsync-for-woocommerce' );
        }
        if ( ! empty( $clean['boe_schedule_enabled'] ) && empty( $clean['boe_spreadsheet_id'] ) ) {
            $notices[] = __( 'Order auto-export is enabled but no Spreadsheet ID is saved yet.', 'sheetsync-for-woocommerce' );
        }

        if ( class_exists( 'SheetSync_Dashboard_Phase3', false ) && isset( $incoming['webhook_export_urls'] ) ) {
            $parsed = SheetSync_Dashboard_Phase3::normalize_webhook_urls( (string) $incoming['webhook_export_urls'] );
            if ( $parsed['invalid_count'] > 0 ) {
                $notices[] = sprintf(
                    _n(
                        '%d invalid webhook URL was removed. Use a full https:// address.',
                        '%d invalid webhook URLs were removed. Use full https:// addresses.',
                        (int) $parsed['invalid_count'],
                        'sheetsync-for-woocommerce'
                    ),
                    (int) $parsed['invalid_count']
                );
            }
        }

        return $notices;
    }

    private static function sanitize_role_list( string $raw ): string {
        $roles   = array_map( 'trim', explode( ',', (string) $raw ) );
        $valid   = array_keys( wp_roles()->roles );
        $allowed = array_values(
            array_filter(
                array_map( 'sanitize_key', $roles ),
                static function ( $role ) use ( $valid ) {
                    return $role !== '' && in_array( $role, $valid, true );
                }
            )
        );

        if ( empty( $allowed ) ) {
            return 'administrator,shop_manager';
        }

        return implode( ',', $allowed );
    }

    private static function send_low_stock_alert( int $low, int $out ): void {
        $settings = self::get_settings();
        $to       = self::resolve_notify_email( $settings );
        if ( ! $to ) {
            return;
        }

        $subject = __( '[SheetSync] Low stock alert', 'sheetsync-for-woocommerce' );
        $body    = sprintf(
            __( 'Your store has %1$d low-stock and %2$d out-of-stock products. Open the Inventory Dashboard to review.', 'sheetsync-for-woocommerce' ),
            $low,
            $out
        );

        wp_mail( $to, $subject, wpautop( esc_html( $body ) ) );
    }

    public static function sheets_url( string $spreadsheet_id ): string {
        $id = trim( $spreadsheet_id );
        if ( $id === '' ) {
            return '';
        }
        return 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $id ) . '/edit';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_boe_templates(): array {
        return array(
            'accounting' => array(
                'label'   => __( 'Accounting', 'sheetsync-for-woocommerce' ),
                'fields'  => array( 'order_id', 'date', 'status', 'customer_name', 'customer_email', 'subtotal', 'shipping_cost', 'tax', 'discount', 'total', 'payment_method' ),
            ),
            'shipping' => array(
                'label'   => __( 'Shipping / Courier', 'sheetsync-for-woocommerce' ),
                'fields'  => array( 'order_id', 'date', 'customer_name', 'phone', 'shipping_address', 'products', 'items_count', 'customer_note' ),
            ),
            'marketing' => array(
                'label'   => __( 'Marketing', 'sheetsync-for-woocommerce' ),
                'fields'  => array( 'order_id', 'date', 'customer_name', 'customer_email', 'products', 'total', 'payment_method' ),
            ),
            'full' => array(
                'label'   => __( 'Full export', 'sheetsync-for-woocommerce' ),
                'fields'  => array_keys( ( new SheetSync_Bulk_Order_Export() )->get_export_field_labels_public() ),
            ),
        );
    }

    public function ajax_get_export_log(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }
        $log = get_option( self::LOG_OPTION, array() );
        wp_send_json_success( is_array( $log ) ? $log : array() );
    }

    public function ajax_get_boe_presets(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }
        $presets = get_option( self::PRESETS_OPT, array() );
        wp_send_json_success( array(
            'presets'      => is_array( $presets ) ? $presets : array(),
            'templates'    => self::get_boe_templates(),
            'field_labels' => ( new SheetSync_Bulk_Order_Export() )->get_export_field_labels_public(),
        ) );
    }

    public function ajax_save_boe_preset(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( $name === '' ) {
            wp_send_json_error( array( 'message' => __( 'Preset name is required.', 'sheetsync-for-woocommerce' ) ) );
        }

        $preset = array(
            'id'         => sanitize_key( wp_unslash( $_POST['id'] ?? uniqid( 'p_', true ) ) ),
            'name'       => $name,
            'statuses'   => isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['statuses'] ) ) : array(),
            'date_from'  => sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) ),
            'date_to'    => sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) ),
            'customer'   => sanitize_text_field( wp_unslash( $_POST['customer'] ?? '' ) ),
            'min_total'  => sanitize_text_field( wp_unslash( $_POST['min_total'] ?? '' ) ),
            'max_total'  => sanitize_text_field( wp_unslash( $_POST['max_total'] ?? '' ) ),
            'fields'     => isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['fields'] ) ) : array(),
        );

        $presets = get_option( self::PRESETS_OPT, array() );
        if ( ! is_array( $presets ) ) {
            $presets = array();
        }

        $updated = false;
        foreach ( $presets as $i => $row ) {
            if ( ( $row['id'] ?? '' ) === $preset['id'] ) {
                $presets[ $i ] = $preset;
                $updated       = true;
                break;
            }
        }
        if ( ! $updated ) {
            $presets[] = $preset;
        }

        update_option( self::PRESETS_OPT, $presets, false );
        wp_send_json_success( array( 'presets' => $presets ) );
    }

    public function ajax_delete_boe_preset(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $id      = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) );
        $presets = get_option( self::PRESETS_OPT, array() );
        if ( ! is_array( $presets ) ) {
            $presets = array();
        }

        $presets = array_values( array_filter( $presets, fn( $p ) => ( $p['id'] ?? '' ) !== $id ) );
        update_option( self::PRESETS_OPT, $presets, false );
        wp_send_json_success( array( 'presets' => $presets ) );
    }

    public function ajax_get_onboarding_state(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }
        wp_send_json_success( self::get_onboarding_state() );
    }

    public function ajax_update_onboarding(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $key   = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );
        $value = ! empty( $_POST['value'] );
        $state = self::get_onboarding_state();

        if ( $key === 'dismissed' ) {
            $state['dismissed'] = $value;
        } elseif ( array_key_exists( $key, $state['steps'] ) ) {
            $state['steps'][ $key ] = $value;
        }

        update_user_meta( get_current_user_id(), 'sheetsync_dashboard_onboarding', $state );
        wp_send_json_success( $state );
    }

    /**
     * @return array{dismissed:bool,steps:array<string,bool>}
     */
    public static function get_onboarding_state(): array {
        $defaults = array(
            'dismissed' => false,
            'steps'     => array(
                'sheet_connected' => false,
                'sales_exported'  => false,
                'inventory_viewed'=> false,
                'orders_exported' => false,
            ),
        );

        $saved = get_user_meta( get_current_user_id(), 'sheetsync_dashboard_onboarding', true );
        if ( ! is_array( $saved ) ) {
            return $defaults;
        }

        return wp_parse_args( $saved, $defaults );
    }

    public function register_wp_dashboard_widget(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'SheetSync_Sales_Dashboard', false ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'sheetsync_today_sales',
            __( 'SheetSync — Today\'s Sales', 'sheetsync-for-woocommerce' ),
            array( $this, 'render_wp_dashboard_widget' )
        );
    }

    public function render_wp_dashboard_widget(): void {
        $cache_key = 'sheetsync_widget_7d_' . get_current_blog_id() . '_v' . SheetSync_Sales_Dashboard::dashboard_cache_version();
        $payload   = get_transient( $cache_key );

        if ( ! is_array( $payload ) && class_exists( 'SheetSync_Sales_Dashboard', false ) ) {
            $dash    = new SheetSync_Sales_Dashboard();
            $data    = $dash->get_dashboard_data( '7days', array(), true );
            $daily   = $data['daily'] ?? array();
            $payload = array(
                'today_revenue' => (float) ( end( $daily )['revenue'] ?? 0 ),
                'net_sales'     => (float) ( $data['summary']['net_sales'] ?? 0 ),
                'total_orders'  => (int) ( $data['summary']['total_orders'] ?? 0 ),
            );
            set_transient( $cache_key, $payload, 300 );
        }

        $sym     = html_entity_decode( get_woocommerce_currency_symbol() );
        $today   = (float) ( $payload['today_revenue'] ?? 0 );
        $week    = (float) ( $payload['net_sales'] ?? 0 );
        $orders  = (int) ( $payload['total_orders'] ?? 0 );

        echo '<p style="font-size:24px;font-weight:700;margin:0 0 4px;">' . esc_html( $sym . $this->format_widget_amount( $today ) ) . '</p>';
        echo '<p style="margin:0 0 8px;color:#666;">' . esc_html__( 'Revenue today (store timezone)', 'sheetsync-for-woocommerce' ) . '</p>';
        echo '<p style="margin:0 0 12px;color:#444;">'
            . esc_html( sprintf( __( '7-day: %1$s · %2$d orders', 'sheetsync-for-woocommerce' ), $sym . $this->format_widget_amount( $week ), $orders ) )
            . '</p>';
        echo '<p style="margin:0;"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=sheetsync-dashboards' ) ) . '">' . esc_html__( 'Open Sales Dashboard', 'sheetsync-for-woocommerce' ) . '</a></p>';
    }

    private function format_widget_amount( float $amount ): string {
        if ( $amount >= 1000000 ) {
            return rtrim( rtrim( number_format( $amount / 1000000, 1 ), '0' ), '.' ) . 'M';
        }
        if ( $amount >= 10000 ) {
            return rtrim( rtrim( number_format( $amount / 1000, 1 ), '0' ), '.' ) . 'K';
        }
        return number_format( $amount, 2 );
    }
}

endif;
