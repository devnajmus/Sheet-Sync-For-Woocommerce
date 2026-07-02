<?php
/**
 * AES-256-CBC encryption for storing sensitive credentials.
 * Uses AUTH_KEY + SECURE_AUTH_KEY from wp-config.php as key material.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Encryptor' ) ) :

class SheetSync_Encryptor {

    private static function get_key(): string {
        // Both keys must be defined — throw rather than fall back to a known weak key.
        // An attacker who knows the fallback string could decrypt stored Google credentials.
        if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
            throw new RuntimeException(
                'WordPress security keys (AUTH_KEY, SECURE_AUTH_KEY) are not configured. Cannot encrypt credentials.'
            );
        }
        return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
    }

    /**
     * Encrypt a string. Returns base64-encoded IV + ciphertext.
     */
    public static function encrypt( string $data ): string {
        if ( empty( $data ) ) return '';

        $iv         = random_bytes( 16 );
        $ciphertext = openssl_encrypt( $data, 'aes-256-cbc', self::get_key(), OPENSSL_RAW_DATA, $iv );

        if ( $ciphertext === false ) {
            return '';
        }

        return base64_encode( $iv . $ciphertext );
    }

    /**
     * Decrypt a previously encrypted string.
     */
    public static function decrypt( string $data ): string {
        if ( empty( $data ) ) return '';

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- used for AES-256 decryption, not obfuscation
        $raw = base64_decode( $data, true );
        if ( $raw === false || strlen( $raw ) < 16 ) return '';

        $iv         = substr( $raw, 0, 16 );
        $ciphertext = substr( $raw, 16 );
        $decrypted  = openssl_decrypt( $ciphertext, 'aes-256-cbc', self::get_key(), OPENSSL_RAW_DATA, $iv );

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Check if a string looks like it's already encrypted by us.
     */
    public static function is_encrypted( string $data ): bool {
        if ( empty( $data ) ) return false;
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- used to detect AES-256 ciphertext, not obfuscation
        $decoded = base64_decode( $data, true );
        return $decoded !== false && strlen( $decoded ) > 16;
    }
}

endif; // class_exists SheetSync_Encryptor
