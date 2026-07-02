<?php
/**
 * Google Service Account authentication — uses WordPress HTTP API only.
 * No Composer or external libraries needed. Works out of the box.
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Google_Auth' ) ) :

class SheetSync_Google_Auth {

    private static ?string $access_token   = null;
    private static int     $token_expires  = 0;

    /**
     * Get a valid OAuth2 access token using Service Account JWT flow.
     * Caches token in memory and in transient until expiry.
     */
    public static function get_access_token(): string {
        if ( class_exists( 'SheetSync_Google_OAuth', false ) && SheetSync_Google_OAuth::is_active() ) {
            return SheetSync_Google_OAuth::get_access_token();
        }

        // Return cached token if still valid (with 60s buffer)
        if ( self::$access_token && time() < self::$token_expires - 60 ) {
            return self::$access_token;
        }

        // Check transient cache
        $cached = get_transient( 'sheetsync_access_token' );
        if ( $cached ) {
            self::$access_token  = $cached;
            self::$token_expires = (int) get_transient( 'sheetsync_token_expires' );
            return self::$access_token;
        }

        // Generate new token
        $credentials = self::get_credentials();
        $token_data  = self::request_access_token( $credentials );

        self::$access_token  = $token_data['access_token'];
        self::$token_expires = time() + (int) $token_data['expires_in'];

        // Cache it
        set_transient( 'sheetsync_access_token',   self::$access_token,  (int) $token_data['expires_in'] - 60 );
        set_transient( 'sheetsync_token_expires',  self::$token_expires, (int) $token_data['expires_in'] - 60 );

        return self::$access_token;
    }

    /**
     * Reset cached token (call after credentials change).
     * Call this method after uploading a new plugin or saving credentials.
     */
    public static function reset(): void {
        self::$access_token  = null;
        self::$token_expires = 0;
        delete_transient( 'sheetsync_access_token' );
        delete_transient( 'sheetsync_token_expires' );
        // BUG FIX: Also clear object cache — old token may be stuck in persistent cache (Redis/Memcached).
        wp_cache_delete( 'sheetsync_access_token', 'sheetsync' );
    }

    /**
     * Test Google auth only (token fetch) — no spreadsheet required.
     */
    public static function test_auth(): array {
        try {
            $token = self::get_access_token();
            if ( empty( $token ) ) {
                return array(
                    'success' => false,
                    'message' => esc_html__( 'Could not obtain an access token. Check your Service Account JSON.', 'sheetsync-for-woocommerce' ),
                );
            }

            return array(
                'success' => true,
                'email'   => self::get_account_email(),
                'message' => sprintf(
                    /* translators: %s: service account email */
                    esc_html__( 'Connected as %s', 'sheetsync-for-woocommerce' ),
                    self::get_account_email()
                ),
            );
        } catch ( Exception $e ) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }
    }

    /**
     * Test connectivity by fetching spreadsheet metadata.
     */
    public static function test_connection( string $spreadsheet_id ): array {
        $share_email = self::get_account_email();

        try {
            $url      = "https://sheets.googleapis.com/v4/spreadsheets/" . rawurlencode( $spreadsheet_id );
            $response = self::api_get( $url );
            $sheets   = array_map(
                fn( $s ) => $s['properties']['title'],
                $response['sheets'] ?? []
            );
            return [
                'success'     => true,
                'title'       => $response['properties']['title'] ?? '',
                'sheets'      => $sheets,
                'share_email' => $share_email,
            ];
        } catch ( Exception $e ) {
            $msg    = $e->getMessage();
            $result = [
                'success'     => false,
                'message'     => $msg,
                'share_email' => $share_email,
            ];

            if ( self::is_share_permission_error( $msg ) && $share_email !== '' ) {
                $result['error_type']  = 'share_required';
                $result['message']     = sprintf(
                    /* translators: %s: service account email address */
                    __( 'This spreadsheet is not shared with your service account. In Google Sheets, click Share and add %s with Editor access, then test again.', 'sheetsync-for-woocommerce' ),
                    $share_email
                );
                $result['share_steps'] = self::get_share_instructions( $share_email );
            }

            return $result;
        }
    }

    /**
     * Step-by-step share instructions for UI (settings, connection test, wizard).
     *
     * @return array<int, string>
     */
    public static function get_share_instructions( string $share_email ): array {
        return array(
            __( 'Open your Google Sheet.', 'sheetsync-for-woocommerce' ),
            __( 'Click the green Share button (top right).', 'sheetsync-for-woocommerce' ),
            sprintf(
                /* translators: %s: service account email */
                __( 'Add %s as an Editor.', 'sheetsync-for-woocommerce' ),
                $share_email
            ),
            __( 'Click Send or Done, then return here and test again.', 'sheetsync-for-woocommerce' ),
        );
    }

    /**
     * Detect Google Sheets permission / share errors from API message text.
     */
    private static function is_share_permission_error( string $message ): bool {
        $lower = strtolower( $message );
        return str_contains( $lower, '403' )
            || str_contains( $lower, 'permission' )
            || str_contains( $lower, 'caller does not have' )
            || str_contains( $lower, 'forbidden' )
            || str_contains( $lower, 'insufficient' );
    }

    /**
     * Save (validate + encrypt + store) Service Account JSON.
     */
    public static function save_credentials( string $json ): bool {
        $decoded = json_decode( $json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) return false;

        $required = [ 'type', 'project_id', 'private_key', 'client_email' ];
        foreach ( $required as $field ) {
            if ( empty( $decoded[ $field ] ) ) return false;
        }
        if ( $decoded['type'] !== 'service_account' ) return false;

        $encrypted = SheetSync_Encryptor::encrypt( $json );
        if ( empty( $encrypted ) ) return false;

        update_option( 'sheetsync_service_account', $encrypted, false );
        self::reset();
        return true;
    }

    /**
     * Get the service account email (safe to display — no private key).
     */
    public static function get_account_email(): string {
        $creds = self::get_credentials_array();
        return $creds['client_email'] ?? '';
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Decode and return credentials array.
     */
    private static function get_credentials(): array {
        $creds = self::get_credentials_array();
        if ( empty( $creds ) ) {
            throw new RuntimeException( esc_html__( 'No Google Service Account configured.', 'sheetsync-for-woocommerce' ) );
        }
        return $creds;
    }

    private static function get_credentials_array(): array {
        $encrypted = get_option( 'sheetsync_service_account', '' );
        if ( empty( $encrypted ) ) return [];

        $json = SheetSync_Encryptor::decrypt( $encrypted );
        if ( empty( $json ) ) return [];

        return json_decode( $json, true ) ?: [];
    }

    /**
     * Request a new access token from Google using JWT.
     * See: https://developers.google.com/identity/protocols/oauth2/service-account
     */
    private static function request_access_token( array $creds ): array {
        $now = time();

        // Build JWT header + payload
        $header  = self::base64url_encode( json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $payload = self::base64url_encode( json_encode( [
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ] ) );

        $signing_input = $header . '.' . $payload;

        // Sign with private key
        $private_key = $creds['private_key'];
        $key_resource = openssl_pkey_get_private( $private_key );
        if ( ! $key_resource ) {
            throw new RuntimeException( esc_html__( 'Invalid private key in Service Account JSON.', 'sheetsync-for-woocommerce' ) );
        }

        $signature = '';
        if ( ! openssl_sign( $signing_input, $signature, $key_resource, 'SHA256' ) ) {
            throw new RuntimeException( esc_html__( 'Failed to sign JWT.', 'sheetsync-for-woocommerce' ) );
        }

        $jwt = $signing_input . '.' . self::base64url_encode( $signature );

        // Exchange JWT for access token
        // BUG FIX: Increased timeout 15→30s — Google OAuth endpoint can be slow on shared hosting.
        if ( class_exists( 'SheetSync_Sync_Tick_Perf_Log', false ) ) {
            SheetSync_Sync_Tick_Perf_Log::note_google_api_call();
        }
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'      => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ] );

        // Do NOT retry with sleep() here — blocking the PHP process harms all site visitors.
        // On timeout the caller (cron/AJAX) will handle the WP_Error gracefully.

        // FIX Line 192: esc_html() applied to WP_Error message before throwing.
        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( esc_html( $response->get_error_message() ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = wp_remote_retrieve_response_code( $response );

        // FIX Line 200: esc_html() applied to API error message before throwing.
        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            $msg = $body['error_description'] ?? $body['error'] ?? 'Token request failed.';
            throw new RuntimeException( esc_html( $msg ) );
        }

        return $body;
    }

    /**
     * Authenticated GET to Google APIs.
     */
    public static function api_get( string $url, ?bool $allow_sleep = null ): array {
        if ( $allow_sleep === null ) {
            $allow_sleep = self::should_retry_with_sleep();
        }
        return self::api_request_with_retry( 'GET', $url, null, $allow_sleep );
    }

    /**
     * Authenticated PUT to Google APIs.
     */
    public static function api_put( string $url, array $body, ?bool $allow_sleep = null ): array {
        if ( $allow_sleep === null ) {
            $allow_sleep = self::should_retry_with_sleep();
        }
        return self::api_request_with_retry( 'PUT', $url, $body, $allow_sleep );
    }

    /**
     * Authenticated POST to Google APIs.
     */
    /**
     * @param array<string, mixed>|object|null $body JSON body (use `new stdClass()` for `{}`).
     */
    public static function api_post( string $url, array|object|null $body = null, ?bool $allow_sleep = null ): array {
        if ( $allow_sleep === null ) {
            $allow_sleep = self::should_retry_with_sleep();
        }
        return self::api_request_with_retry( 'POST', $url, $body, $allow_sleep );
    }

    /**
     * Background sync jobs may sleep between Google API retries (Action Scheduler).
     */
    private static function should_retry_with_sleep(): bool {
        return ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
            || ( defined( 'WP_CLI' ) && WP_CLI )
            || ( defined( 'SHEETSYNC_BG_SYNC' ) && SHEETSYNC_BG_SYNC )
            || ( defined( 'DOING_AJAX' ) && DOING_AJAX );
    }

    /**
     * Short pause before retrying Google API calls during admin AJAX (no long cron sleeps).
     */
    private static function retry_pause_seconds( int $attempt, bool $allow_sleep ): void {
        if ( $attempt <= 0 ) {
            return;
        }
        if ( $allow_sleep ) {
            sleep( min( 60, 15 * ( 2 ** ( $attempt - 1 ) ) ) );
            return;
        }
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            sleep( min( 4, $attempt * 2 ) );
        }
    }

    /**
     * Unified API request with automatic retry on quota errors (HTTP 429).
     * Retries up to 3 times with 1s, 2s, 4s delays (exponential backoff).
     * Avoids sleep() on first attempt to keep normal requests fast.
     *
     * @param string                           $method  GET | POST | PUT
     * @param string                           $url
     * @param array<string, mixed>|object|null $body    JSON body for POST/PUT
     */
    private static function api_request_with_retry( string $method, string $url, array|object|null $body = null, bool $allow_sleep = false ): array {
        $max_retries = $allow_sleep ? 5 : ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? 4 : 3 );

        for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
            self::retry_pause_seconds( $attempt, $allow_sleep );

            $args = array(
                'method'    => $method,
                'timeout'   => 30,
                'sslverify' => true,
                'headers'   => array(
                    'Authorization' => 'Bearer ' . self::get_access_token(),
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
            );

            if ( $body !== null ) {
                $args['body'] = wp_json_encode( $body );
            }

            if ( class_exists( 'SheetSync_Sync_Tick_Perf_Log', false ) ) {
                SheetSync_Sync_Tick_Perf_Log::note_google_api_call();
            }
            $response = wp_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                if ( $attempt === $max_retries ) {
                    throw new RuntimeException( esc_html( $response->get_error_message() ) );
                }
                self::retry_pause_seconds( $attempt + 1, $allow_sleep );
                continue;
            }

            $code      = wp_remote_retrieve_response_code( $response );
            $body_data = json_decode( wp_remote_retrieve_body( $response ), true );

            $msg       = $body_data['error']['message'] ?? "API error (HTTP {$code})";
            $is_quota  = function_exists( 'sheetsync_api_error_is_quota' )
                ? sheetsync_api_error_is_quota( (string) $msg )
                : str_contains( strtolower( (string) $msg ), 'quota' );

            // HTTP 429 or 403 with quota message — retry with backoff (Google Sheets write limit).
            if ( ( 429 === $code || ( 403 === $code && $is_quota ) ) && $attempt < $max_retries ) {
                $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
                if ( $allow_sleep ) {
                    $wait = $retry_after > 0 ? min( 90, $retry_after ) : min( 90, 20 * ( 2 ** $attempt ) );
                    sleep( $wait );
                }
                continue;
            }

            if ( $code < 200 || $code >= 300 ) {
                throw new RuntimeException( esc_html( $msg ) );
            }

            return $body_data ?? array();
        }

        throw new RuntimeException( esc_html__( 'Google API quota exceeded. Please wait a moment and try again.', 'sheetsync-for-woocommerce' ) );
    }

    /**
     * Parse a WP_HTTP response and throw on error.
     */
    private static function parse_response( $response ): array {
        // FIX Line 262: esc_html() applied to WP_Error message before throwing.
        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( esc_html( $response->get_error_message() ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // FIX Line 270: esc_html() applied to API error message before throwing.
        if ( $code < 200 || $code >= 300 ) {
            $msg = $body['error']['message'] ?? "API error (HTTP {$code})";
            throw new RuntimeException( esc_html( $msg ) );
        }

        return $body ?? [];
    }

    /**
     * URL-safe base64 encode (RFC 4648).
     */
    private static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}

endif; // class_exists SheetSync_Google_Auth
