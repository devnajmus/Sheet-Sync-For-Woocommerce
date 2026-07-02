<?php
/**
 * Google Drive / Sheets — create spreadsheet from plugin.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Drive_Client', false ) ) :

class SheetSync_Drive_Client {

    public static function is_available(): bool {
        if ( class_exists( 'SheetSync_Google_OAuth', false ) && SheetSync_Google_OAuth::is_active() ) {
            return true;
        }
        if ( class_exists( 'SheetSync_Google_Auth', false ) && SheetSync_Google_Auth::get_account_email() !== '' ) {
            return true;
        }
        return false;
    }

    /**
     * @return array{success: bool, spreadsheet_id?: string, url?: string, message?: string}
     */
    public static function create_spreadsheet( string $title ): array {
        if ( ! self::is_available() ) {
            return array(
                'success' => false,
                'message' => __( 'Connect Google first (Service Account JSON or OAuth).', 'sheetsync-for-woocommerce' ),
            );
        }

        $title = trim( $title ) !== '' ? trim( $title ) : __( 'SheetSync Products', 'sheetsync-for-woocommerce' );

        try {
            $response = SheetSync_Google_Auth::api_post(
                'https://sheets.googleapis.com/v4/spreadsheets',
                array(
                    'properties' => array( 'title' => $title ),
                    'sheets'     => array(
                        array( 'properties' => array( 'title' => 'Sheet1' ) ),
                    ),
                )
            );

            $id = (string) ( $response['spreadsheetId'] ?? '' );
            if ( $id === '' ) {
                return array(
                    'success' => false,
                    'message' => __( 'Google did not return a spreadsheet ID.', 'sheetsync-for-woocommerce' ),
                );
            }

            return array(
                'success'        => true,
                'spreadsheet_id' => $id,
                'url'            => 'https://docs.google.com/spreadsheets/d/' . $id . '/edit',
                'message'        => __( 'Spreadsheet created.', 'sheetsync-for-woocommerce' ),
            );
        } catch ( Exception $e ) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }
    }
}

endif;
