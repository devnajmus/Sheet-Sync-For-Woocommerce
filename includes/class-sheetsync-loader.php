<?php
/**
 * Plugin loader — bootstraps all core classes.
 *
 * Core bootstrap: loads shared services, admin (filterable), and REST API.
 * The unified Pro package ships this file; `SheetSync_Pro_Loader` hooks `sheetsync_loaded`.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

$sheetsync_base_dir              = dirname( dirname( __FILE__ ) ) . DIRECTORY_SEPARATOR;
$GLOBALS['sheetsync_base_dir']   = $sheetsync_base_dir;

if ( ! class_exists( 'SheetSync_Loader' ) ) :

class SheetSync_Loader {

    public function run(): void {
        $this->load_dependencies();
        $this->load_core();
        if ( is_admin() ) {
            $this->load_admin();
        }
        $this->load_api();
    }

    private function load_dependencies(): void {
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/class-sheetsync-enterprise-loader.php';
        SheetSync_Enterprise_Loader::init();

        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/class-sheetsync-encryptor.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/class-sheetsync-onboarding.php';
        SheetSync_Onboarding::init();
        add_action( 'admin_init', array( 'SheetSync_Google_OAuth', 'maybe_handle_admin_request' ), 5 );

        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/class-sheetsync-logger.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-google-auth.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-google-oauth.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-drive-client.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-sheets-client.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-field-mapper.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-import-rules.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-product-updater.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-sync-engine.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-sheet-validation-report.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-attribute-bootstrap.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-sheet-validator.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-simple-attribute-columns.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-sync-pull-mode.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-sheet-template-writer.php';
        require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-sheet-image-resolver.php';
        SheetSync_Simple_Attribute_Columns::init();
    }

    private function load_core(): void {
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
    }

    private function load_admin(): void {
        require_once $GLOBALS['sheetsync_base_dir'] . 'admin/class-sheetsync-admin.php';
        /**
         * Allow the Pro plugin to swap in SheetSync_Pro_Admin after the base class file is loaded.
         */
        do_action( 'sheetsync_before_load_admin' );
        $admin_class = apply_filters( 'sheetsync_admin_class', 'SheetSync_Admin' );
        if ( is_string( $admin_class ) && class_exists( $admin_class ) ) {
            new $admin_class();
        }
    }

    private function load_api(): void {
        require_once $GLOBALS['sheetsync_base_dir'] . 'api/class-rest-api.php';
        $api = new SheetSync_REST_API();
        add_action( 'rest_api_init', array( $api, 'register_routes' ) );
    }

    public function add_cron_schedules( array $schedules ): array {
        $schedules['sheetsync_15min'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 Minutes (SheetSync)', 'sheetsync-for-woocommerce' ),
        );
        $schedules['sheetsync_30min'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 30 Minutes (SheetSync)', 'sheetsync-for-woocommerce' ),
        );
        $schedules['sheetsync_1hour'] = array(
            'interval' => HOUR_IN_SECONDS,
            'display'  => __( 'Every Hour (SheetSync)', 'sheetsync-for-woocommerce' ),
        );
        return $schedules;
    }
}

endif; // class_exists SheetSync_Loader

/**
 * Is a Pro license active?
 *
 * The free plugin defines this helper to return `apply_filters( 'sheetsync_is_pro', false )`.
 * The Pro add-on registers on `plugins_loaded` (priority 1) and sets the `sheetsync_is_pro`
 * filter from Freemius / `SHEETSYNC_IS_PRO` so licensed sites see `true`.
 *
 * Free still ships core product sync (Sheets → WooCommerce); Pro-only modules check
 * `sheetsync_is_pro()` before running.
 */
if ( ! function_exists( 'sheetsync_is_pro' ) ) {
    function sheetsync_is_pro(): bool {
        if ( defined( 'SHEETSYNC_DEV_PRO' ) && SHEETSYNC_DEV_PRO && get_option( 'sheetsync_pro_test_mode', false ) ) {
            return true;
        }
        if ( defined( 'SHEETSYNC_IS_PRO' ) && SHEETSYNC_IS_PRO ) {
            return true;
        }
        if ( function_exists( 'sfw_fs' ) ) {
            $fs = sfw_fs();
            if ( is_object( $fs ) && method_exists( $fs, 'can_use_premium_code' ) && $fs->can_use_premium_code() ) {
                return true;
            }
        }
        return (bool) apply_filters( 'sheetsync_is_pro', false );
    }
}

/**
 * Returns the Freemius checkout / upgrade URL.
 */
if ( ! function_exists( 'sheetsync_upgrade_url' ) ) {
    function sheetsync_upgrade_url(): string {
        if ( function_exists( 'sfw_fs' ) ) {
            $fs = sfw_fs();
            if ( is_object( $fs ) && method_exists( $fs, 'get_upgrade_url' ) ) {
                return $fs->get_upgrade_url();
            }
        }
        return '#';
    }
}

/**
 * Freemius license / plan status (for admin UI and debugging).
 *
 * @return array{connected:bool,trial:bool,licensed:bool,can_use_pro:bool,plan:string}
 */
if ( ! function_exists( 'sheetsync_get_freemius_status' ) ) {
    function sheetsync_get_freemius_status(): array {
        $status = array(
            'connected'   => false,
            'trial'       => false,
            'licensed'    => false,
            'can_use_pro' => function_exists( 'sheetsync_is_pro' ) && sheetsync_is_pro(),
            'plan'        => __( 'Free', 'sheetsync-for-woocommerce' ),
        );

        if ( ! function_exists( 'sfw_fs' ) ) {
            return $status;
        }

        $fs = sfw_fs();
        if ( ! is_object( $fs ) ) {
            return $status;
        }

        $status['connected'] = method_exists( $fs, 'is_registered' ) && $fs->is_registered();
        $status['trial']     = method_exists( $fs, 'is_trial' ) && $fs->is_trial();
        $status['licensed']  = method_exists( $fs, 'is_paying' ) && $fs->is_paying();

        if ( $status['trial'] ) {
            $status['plan'] = __( 'Trial (Pro unlocked)', 'sheetsync-for-woocommerce' );
        } elseif ( $status['licensed'] ) {
            $status['plan'] = __( 'Pro (license active)', 'sheetsync-for-woocommerce' );
        } elseif ( $status['can_use_pro'] ) {
            $status['plan'] = __( 'Pro unlocked (check Account — stale trial or dev license?)', 'sheetsync-for-woocommerce' );
        }

        return $status;
    }
}
