<?php

/**
 * Plugin Name: SheetSync for WooCommerce Pro
 * Plugin URI:        https://devnajmus.com/sheetsync
 * Description:       Sync WooCommerce products and orders with Google Sheets — two-way sync, orders, bulk export, webhooks, dashboards.
 * Version:           1.0.0
 * Update URI: https://api.freemius.com
 * Author:            MD Najmus Shadat
 * Author URI:        https://devnajmus.com
 * Text Domain:       sheetsync-for-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @fs_premium_only /includes/pro/
 */
defined( 'ABSPATH' ) || exit;
/**
 * Freemius SDK — official integration (Developer Dashboard → SDK Integration).
 * Must load before the rest of the plugin bootstrap.
 */
if ( !function_exists( 'sfw_fs' ) ) {
    function sfw_fs() {
        global $sfw_fs;
        if ( !isset( $sfw_fs ) ) {
            require_once __DIR__ . '/vendor/freemius/start.php';
            $sfw_fs = fs_dynamic_init( array(
                'id'                => 26705,
                'slug'              => 'sheetsync-for-woocommerce',
                'type'              => 'plugin',
                'public_key'        => 'pk_3bb51306d3a82102a268cc87e8d6d',
                'is_premium'        => true,
                'is_premium_only'   => false,
                'has_addons'        => false,
                'has_paid_plans'    => true,
                'is_org_compliant'  => true,
                'wp_org_gatekeeper' => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'menu'              => array(
                    'slug'       => 'sheetsync',
                    'first-path' => 'admin.php?page=sheetsync-setup',
                    'account'    => true,
                    'pricing'    => true,
                    'contact'    => true,
                    'support'    => false,
                ),
                'is_live'           => true,
            ) );
            do_action( 'sfw_fs_loaded' );
        }
        return $sfw_fs;
    }

    sfw_fs();
}
add_action( 'sfw_fs_loaded', static function () : void {
    require_once __DIR__ . '/includes/class-sheetsync-uninstaller.php';
    if ( function_exists( 'sfw_fs' ) && is_object( sfw_fs() ) ) {
        sfw_fs()->add_action( 'after_uninstall', array('SheetSync_Uninstaller', 'uninstall') );
    }
} );
if ( !function_exists( 'sheetsync_fs' ) ) {
    function sheetsync_fs() {
        return ( function_exists( 'sfw_fs' ) ? sfw_fs() : null );
    }

}
/**
 * Safely render Freemius account page (avoids fatal when user data is missing).
 */
if ( !function_exists( 'sheetsync_render_freemius_account_page' ) ) {
    function sheetsync_render_freemius_account_page(  $fs  ) : void {
        if ( !is_object( $fs ) || !method_exists( $fs, '_account_page_render' ) ) {
            wp_die( esc_html__( 'Freemius is not available.', 'sheetsync-for-woocommerce' ) );
        }
        if ( method_exists( $fs, 'is_registered' ) && !$fs->is_registered() ) {
            if ( method_exists( $fs, 'get_activation_url' ) ) {
                wp_safe_redirect( $fs->get_activation_url() );
                exit;
            }
        }
        if ( !$fs->get_user() ) {
            wp_die( wp_kses_post( sprintf( 
                /* translators: %s: plugins list URL */
                __( 'SheetSync is not connected to Freemius yet. Deactivate and reactivate the plugin from <a href="%s">Plugins</a>, complete the opt-in, then open Account again.', 'sheetsync-for-woocommerce' ),
                esc_url( admin_url( 'plugins.php' ) )
             ) ), esc_html__( 'Account unavailable', 'sheetsync-for-woocommerce' ), array(
                'back_link' => true,
            ) );
        }
        $fs->_account_page_render();
    }

}
// ── Paths ──
define( 'SHEETSYNC_PRO_FILE', __FILE__ );
define( 'SHEETSYNC_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHEETSYNC_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'SHEETSYNC_PRO_BASENAME', plugin_basename( __FILE__ ) );
if ( !defined( 'SHEETSYNC_VERSION' ) ) {
    define( 'SHEETSYNC_VERSION', '1.0.0' );
}
/** Deploy fingerprint — changes when critical fixes ship; verify on live via WP-CLI or plugin file search. */
if ( ! defined( 'SHEETSYNC_BUILD' ) ) {
    define( 'SHEETSYNC_BUILD', '20260701-deploy-integrity' );
}
if ( !defined( 'SHEETSYNC_FILE' ) ) {
    define( 'SHEETSYNC_FILE', __FILE__ );
}
if ( !defined( 'SHEETSYNC_DIR' ) ) {
    define( 'SHEETSYNC_DIR', SHEETSYNC_PRO_DIR );
}
if ( !defined( 'SHEETSYNC_URL' ) ) {
    define( 'SHEETSYNC_URL', SHEETSYNC_PRO_URL );
}
if ( !defined( 'SHEETSYNC_BASENAME' ) ) {
    define( 'SHEETSYNC_BASENAME', SHEETSYNC_PRO_BASENAME );
}
if ( !defined( 'SHEETSYNC_IS_PRO' ) ) {
    define( 'SHEETSYNC_IS_PRO', defined( 'SHEETSYNC_DEV_PRO' ) && SHEETSYNC_DEV_PRO );
}
require_once SHEETSYNC_PRO_DIR . 'includes/class-sheetsync-freemius-integration.php';
if ( class_exists( 'SheetSync_Freemius_Integration', false ) ) {
    SheetSync_Freemius_Integration::init();
}
$sheetsync_pro_loader_paths = array(SHEETSYNC_PRO_DIR . 'includes/class-sheetsync-pro-loader__premium_only.php', SHEETSYNC_PRO_DIR . 'includes/class-sheetsync-pro-loader.php');
foreach ( $sheetsync_pro_loader_paths as $sheetsync_pro_loader_path ) {
    if ( file_exists( $sheetsync_pro_loader_path ) ) {
        require_once $sheetsync_pro_loader_path;
        break;
    }
}
add_action( 'sheetsync_before_load_admin', static function () : void {
    require_once SHEETSYNC_PRO_DIR . 'admin/class-sheetsync-admin.php';
}, 5 );
add_filter(
    'sheetsync_admin_class',
    static function ( string $class ) : string {
        return ( class_exists( 'SheetSync_Pro_Admin', false ) ? 'SheetSync_Pro_Admin' : $class );
    },
    10,
    1
);
add_action( 'sheetsync_loaded', static function () : void {
    if ( !function_exists( 'sheetsync_is_pro' ) || !sheetsync_is_pro() ) {
        return;
    }
    if ( class_exists( 'SheetSync_Pro_Loader', false ) ) {
        ( new SheetSync_Pro_Loader() )->run();
    }
}, 20 );
if ( !function_exists( 'sheetsync_check_requirements' ) ) {
    function sheetsync_check_requirements() : array {
        $errors = array();
        if ( !class_exists( 'WooCommerce' ) ) {
            $errors[] = __( 'WooCommerce is not active. SheetSync requires WooCommerce 7.0+.', 'sheetsync-for-woocommerce' );
        }
        if ( !extension_loaded( 'openssl' ) ) {
            $errors[] = __( 'PHP OpenSSL extension is required for Google authentication.', 'sheetsync-for-woocommerce' );
        }
        if ( !extension_loaded( 'json' ) ) {
            $errors[] = __( 'PHP JSON extension is required.', 'sheetsync-for-woocommerce' );
        }
        return $errors;
    }

}
if ( ! function_exists( 'sheetsync_register_requirements_stub_menu' ) ) {
    /**
     * Minimal admin page when requirements fail (e.g. WooCommerce inactive).
     */
    function sheetsync_register_requirements_stub_menu( array $errors ) : void {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;

        add_action( 'admin_notices', static function () use ( $errors ) : void {
            foreach ( $errors as $error ) {
                echo '<div class="notice notice-error"><p><strong>SheetSync:</strong> ' . esc_html( $error ) . '</p></div>';
            }
        } );

        if ( class_exists( 'SheetSync_Freemius_Integration', false ) ) {
            SheetSync_Freemius_Integration::register_requirements_menus( $errors );
        }
    }
}

if ( ! function_exists( 'sheetsync_bootstrap_plugin' ) ) {
    /**
     * Load SheetSync after WooCommerce is available.
     * SheetSync loads alphabetically before WooCommerce, so plugins_loaded priority 10 is too early.
     */
    function sheetsync_bootstrap_plugin() : void {
        static $bootstrapped = false;
        if ( $bootstrapped ) {
            return;
        }
        $bootstrapped = true;

        $errors = sheetsync_check_requirements();
        if ( ! empty( $errors ) ) {
            sheetsync_register_requirements_stub_menu( $errors );
            return;
        }

        require_once SHEETSYNC_PRO_DIR . 'includes/class-sheetsync-loader.php';
        if ( ! class_exists( 'SheetSync_Loader', false ) ) {
            add_action( 'admin_notices', static function () : void {
                echo '<div class="notice notice-error"><p><strong>SheetSync:</strong> ' . esc_html__( 'Core loader missing. Please reinstall the plugin.', 'sheetsync-for-woocommerce' ) . '</p></div>';
            } );
            return;
        }

        ( new SheetSync_Loader() )->run();

        if ( class_exists( 'SheetSync_Enterprise_Loader', false ) && ! SheetSync_Enterprise_Loader::is_ready() ) {
            return;
        }

        do_action( 'sheetsync_loaded' );
    }
}

/**
 * Bootstrap on init so WooCommerce translations load at the correct lifecycle point (WP 6.7+).
 */
add_action( 'init', 'sheetsync_bootstrap_plugin', 5 );
add_action( 'before_woocommerce_init', static function () : void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
register_activation_hook( __FILE__, static function () : void {
    require_once SHEETSYNC_PRO_DIR . 'includes/class-sheetsync-activator.php';
    SheetSync_Activator::activate();
} );
register_deactivation_hook( __FILE__, static function () : void {
    require_once SHEETSYNC_PRO_DIR . 'includes/class-sheetsync-deactivator.php';
    SheetSync_Deactivator::deactivate();
} );