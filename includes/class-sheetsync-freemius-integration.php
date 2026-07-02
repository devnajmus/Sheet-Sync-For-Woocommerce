<?php
/**
 * Freemius + WordPress admin integration (menus, activation redirect, account).
 *
 * Menus register on Freemius `before_admin_menu_init` so submenus exist before
 * the SDK adds Account/Pricing and before post-activation redirects run.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Freemius_Integration', false ) ) :

class SheetSync_Freemius_Integration {

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_to_wizard' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'register_account_submenu' ), self::after_freemius_menu_priority() );
	}

	/**
	 * Hook a callback on Freemius before_admin_menu_init (official SDK integration point).
	 */
	public static function on_before_admin_menu_init( callable $callback, int $priority = 10 ): void {
		if ( ! function_exists( 'sfw_fs' ) ) {
			add_action( 'admin_menu', $callback, 5 );
			return;
		}

		$fs = sfw_fs();
		if ( ! is_object( $fs ) || ! method_exists( $fs, 'add_action' ) ) {
			add_action( 'admin_menu', $callback, 5 );
			return;
		}

		$fs->add_action( 'before_admin_menu_init', $callback, $priority );
	}

	/**
	 * Run immediately after Freemius admin_menu (account submenu override).
	 */
	public static function after_freemius_menu_priority(): int {
		return defined( 'WP_FS__LOWEST_PRIORITY' ) ? WP_FS__LOWEST_PRIORITY + 1 : 1000000;
	}

	/**
	 * Post-activation wizard redirect (WooCommerce-style admin_init pattern).
	 */
	public static function maybe_redirect_to_wizard(): void {
		if ( ! function_exists( 'sheetsync_should_redirect_to_wizard' ) || ! sheetsync_should_redirect_to_wizard() ) {
			return;
		}

		if ( self::is_freemius_activation_flow() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( in_array( $page, array( 'sheetsync-setup', 'sheetsync-settings' ), true ) ) {
			return;
		}

		delete_option( 'sheetsync_redirect_to_wizard' );
		wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-setup' ) );
		exit;
	}

	/**
	 * Re-register Account with WooCommerce-friendly capability and safe render callback.
	 */
	public static function register_account_submenu(): void {
		if ( ! function_exists( 'sfw_fs' ) || ! function_exists( 'sheetsync_render_freemius_account_page' ) ) {
			return;
		}

		$fs = sfw_fs();
		if ( ! is_object( $fs ) ) {
			return;
		}

		remove_submenu_page( 'sheetsync', 'sheetsync-account' );
		remove_submenu_page( 'sheetsync', 'sheetsync-fs-account' );

		$cap = function_exists( 'sheetsync_admin_menu_capability' )
			? sheetsync_admin_menu_capability()
			: 'manage_woocommerce';

		$hook = add_submenu_page(
			'sheetsync',
			__( 'Account', 'sheetsync-for-woocommerce' ),
			__( 'Account', 'sheetsync-for-woocommerce' ),
			$cap,
			'sheetsync-account',
			static function () use ( $fs ) : void {
				sheetsync_render_freemius_account_page( $fs );
			}
		);

		if ( is_string( $hook ) && method_exists( $fs, '_account_page_load' ) ) {
			add_action( "load-{$hook}", array( $fs, '_account_page_load' ) );
		}
	}

	/**
	 * Requirements-failed admin pages (WooCommerce missing, etc.).
	 *
	 * @param array<int, string> $errors
	 */
	public static function register_requirements_menus( array $errors ): void {
		$render_stub = static function () use ( $errors ) : void {
			echo '<div class="wrap"><h1>' . esc_html__( 'SheetSync', 'sheetsync-for-woocommerce' ) . '</h1>';
			foreach ( $errors as $error ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
			}
			echo '</div>';
		};

		$register = static function () use ( $render_stub ) : void {
			add_menu_page(
				__( 'SheetSync', 'sheetsync-for-woocommerce' ),
				__( 'SheetSync', 'sheetsync-for-woocommerce' ),
				'manage_options',
				'sheetsync',
				$render_stub,
				'dashicons-media-spreadsheet',
				56
			);

			add_submenu_page(
				'sheetsync',
				__( 'Setup Wizard', 'sheetsync-for-woocommerce' ),
				__( 'Setup Wizard', 'sheetsync-for-woocommerce' ),
				'manage_options',
				'sheetsync-setup',
				$render_stub
			);
		};

		self::on_before_admin_menu_init( $register );
	}

	private static function is_freemius_activation_flow(): bool {
		if ( ! function_exists( 'sfw_fs' ) ) {
			return false;
		}

		$fs = sfw_fs();
		if ( ! is_object( $fs ) || ! method_exists( $fs, 'is_activation_mode' ) ) {
			return false;
		}

		return (bool) $fs->is_activation_mode();
	}
}

endif;
