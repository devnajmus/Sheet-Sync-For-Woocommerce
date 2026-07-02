<?php
/**
 * Google OAuth 2.0 — optional alternative to Service Account JSON.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Google_OAuth', false ) ) :

class SheetSync_Google_OAuth {

    private static ?string $access_token  = null;
    private static int     $token_expires = 0;

    public static function is_configured(): bool {
        return self::get_client_id() !== '' && self::get_client_secret() !== '';
    }

    public static function is_available(): bool {
        return self::is_configured();
    }

    public static function is_connected(): bool {
        return self::get_refresh_token() !== '';
    }

    public static function is_active(): bool {
        return 'oauth' === get_option( 'sheetsync_auth_method', 'service_account' ) && self::is_connected();
    }

    public static function get_client_id(): string {
        return trim( (string) get_option( 'sheetsync_oauth_client_id', '' ) );
    }

    public static function get_client_secret(): string {
        $enc = get_option( 'sheetsync_oauth_client_secret', '' );
        if ( ! is_string( $enc ) || $enc === '' ) {
            return '';
        }
        return SheetSync_Encryptor::decrypt( $enc );
    }

    public static function save_client_credentials( string $client_id, string $client_secret ): void {
        update_option( 'sheetsync_oauth_client_id', sanitize_text_field( $client_id ), false );
        if ( $client_secret !== '' ) {
            update_option( 'sheetsync_oauth_client_secret', SheetSync_Encryptor::encrypt( $client_secret ), false );
        }
    }

    public static function get_redirect_uri(): string {
        return admin_url( 'admin.php?page=sheetsync-settings&sheetsync_oauth=callback' );
    }

    public static function get_connected_email(): string {
        return (string) get_option( 'sheetsync_oauth_user_email', '' );
    }

    public static function get_authorize_url(): string {
        if ( ! self::is_configured() ) {
            return '';
        }

        $state = wp_create_nonce( 'sheetsync_oauth_state' );
        set_transient( 'sheetsync_oauth_state_' . get_current_user_id(), $state, 600 );

        return add_query_arg(
            array(
                'client_id'     => self::get_client_id(),
                'redirect_uri'  => self::get_redirect_uri(),
                'response_type' => 'code',
                'scope'         => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.file',
                'access_type'   => 'offline',
                'prompt'        => 'consent',
                'state'         => $state,
            ),
            'https://accounts.google.com/o/oauth2/v2/auth'
        );
    }

    /**
     * Handle ?sheetsync_oauth=start|callback|disconnect on Settings page.
     */
    public static function maybe_handle_admin_request(): void {
        if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $oauth_action = isset( $_GET['sheetsync_oauth'] ) ? sanitize_key( wp_unslash( $_GET['sheetsync_oauth'] ) ) : '';
        if ( $oauth_action === '' ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( $page !== 'sheetsync-settings' ) {
            return;
        }

        if ( $oauth_action === 'start' ) {
            $url = self::get_authorize_url();
            if ( $url === '' ) {
                wp_die( esc_html__( 'Configure OAuth Client ID and Secret first.', 'sheetsync-for-woocommerce' ), 400 );
            }
            wp_safe_redirect( $url );
            exit;
        }

        if ( $oauth_action === 'disconnect' ) {
            check_admin_referer( 'sheetsync_oauth_disconnect' );
            self::disconnect();
            wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-settings&oauth_disconnected=1' ) );
            exit;
        }

        if ( $oauth_action === 'callback' ) {
            self::handle_callback();
        }
    }

    public static function handle_callback(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $saved = get_transient( 'sheetsync_oauth_state_' . get_current_user_id() );
        delete_transient( 'sheetsync_oauth_state_' . get_current_user_id() );

        if ( ! $state || ! $saved || ! hash_equals( $saved, $state ) ) {
            wp_die( esc_html__( 'Invalid OAuth state. Please try again.', 'sheetsync-for-woocommerce' ), 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        if ( $error !== '' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-settings&oauth_error=' . rawurlencode( $error ) ) );
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        if ( $code === '' ) {
            wp_die( esc_html__( 'No authorization code received.', 'sheetsync-for-woocommerce' ), 400 );
        }

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 30,
                'body'    => array(
                    'code'          => $code,
                    'client_id'     => self::get_client_id(),
                    'client_secret' => self::get_client_secret(),
                    'redirect_uri'  => self::get_redirect_uri(),
                    'grant_type'    => 'authorization_code',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_die( esc_html( $response->get_error_message() ), 500 );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            $msg = $body['error_description'] ?? __( 'Token exchange failed.', 'sheetsync-for-woocommerce' );
            wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-settings&oauth_error=' . rawurlencode( (string) $msg ) ) );
            exit;
        }

        if ( ! empty( $body['refresh_token'] ) ) {
            update_option( 'sheetsync_oauth_refresh_token', SheetSync_Encryptor::encrypt( (string) $body['refresh_token'] ), false );
        }

        self::$access_token  = (string) $body['access_token'];
        self::$token_expires = time() + (int) ( $body['expires_in'] ?? 3600 );
        set_transient( 'sheetsync_oauth_access_token', self::$access_token, (int) ( $body['expires_in'] ?? 3600 ) - 60 );

        $email = self::fetch_user_email( self::$access_token );
        if ( $email !== '' ) {
            update_option( 'sheetsync_oauth_user_email', $email, false );
        }

        update_option( 'sheetsync_auth_method', 'oauth', false );
        sheetsync_update_setup_progress( 'google_connected', true );

        wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-settings&oauth_connected=1' ) );
        exit;
    }

    public static function disconnect(): void {
        delete_option( 'sheetsync_oauth_refresh_token' );
        delete_option( 'sheetsync_oauth_user_email' );
        delete_transient( 'sheetsync_oauth_access_token' );
        self::$access_token  = null;
        self::$token_expires = 0;
        if ( 'oauth' === get_option( 'sheetsync_auth_method', 'service_account' ) ) {
            update_option( 'sheetsync_auth_method', 'service_account', false );
        }
    }

    public static function get_access_token(): string {
        if ( self::$access_token && time() < self::$token_expires - 60 ) {
            return self::$access_token;
        }

        $cached = get_transient( 'sheetsync_oauth_access_token' );
        if ( is_string( $cached ) && $cached !== '' ) {
            self::$access_token = $cached;
            return $cached;
        }

        $refresh = self::get_refresh_token();
        if ( $refresh === '' ) {
            throw new RuntimeException( esc_html__( 'Google OAuth is not connected.', 'sheetsync-for-woocommerce' ) );
        }

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 30,
                'body'    => array(
                    'client_id'     => self::get_client_id(),
                    'client_secret' => self::get_client_secret(),
                    'refresh_token' => $refresh,
                    'grant_type'    => 'refresh_token',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( esc_html( $response->get_error_message() ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            $msg = $body['error_description'] ?? __( 'Could not refresh OAuth token.', 'sheetsync-for-woocommerce' );
            throw new RuntimeException( esc_html( (string) $msg ) );
        }

        self::$access_token  = (string) $body['access_token'];
        self::$token_expires = time() + (int) ( $body['expires_in'] ?? 3600 );
        set_transient( 'sheetsync_oauth_access_token', self::$access_token, (int) ( $body['expires_in'] ?? 3600 ) - 60 );

        return self::$access_token;
    }

    private static function get_refresh_token(): string {
        $enc = get_option( 'sheetsync_oauth_refresh_token', '' );
        if ( ! is_string( $enc ) || $enc === '' ) {
            return '';
        }
        return SheetSync_Encryptor::decrypt( $enc );
    }

    private static function fetch_user_email( string $access_token ): string {
        $response = wp_remote_get(
            'https://www.googleapis.com/oauth2/v2/userinfo',
            array(
                'timeout' => 15,
                'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? (string) ( $body['email'] ?? '' ) : '';
    }
}

endif;
