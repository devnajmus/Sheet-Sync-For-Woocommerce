<?php
/**
 * Pro-only: exclude mapped fields whose sheet column is listed in connection option
 * `sheetsync_hidden_sheet_columns_{id}` (comma-separated letters, e.g. B,D).
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Pro_Sheet_Column_Visibility {

	public function __construct() {
		add_filter( 'sheetsync_field_maps', array( $this, 'filter_hidden_columns' ), 10, 2 );
	}

	/**
	 * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
	 * @param int                                                           $connection_id
	 * @return array<string, array{sheet_column: string, is_key_field: bool}>
	 */
	public function filter_hidden_columns( array $maps, int $connection_id ): array {
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return $maps;
		}

		$hidden = $this->get_hidden_letters( $connection_id );
		if ( empty( $hidden ) ) {
			return $maps;
		}

		$out = array();
		foreach ( $maps as $wc_field => $info ) {
			$letter = strtoupper( (string) ( $info['sheet_column'] ?? '' ) );
			$letter = preg_replace( '/[^A-Z]/', '', $letter );
			if ( '' !== $letter && isset( $hidden[ $letter ] ) ) {
				continue;
			}
			$out[ $wc_field ] = $info;
		}

		return $out;
	}

	/**
	 * @return array<string, true> Uppercase column letters to hide.
	 */
	private function get_hidden_letters( int $connection_id ): array {
		$raw = (string) get_option( 'sheetsync_hidden_sheet_columns_' . $connection_id, '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$parts = preg_split( '/[\s,;]+/', str_replace( ' ', '', $raw ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $parts as $p ) {
			$u = strtoupper( preg_replace( '/[^A-Za-z]/', '', $p ) );
			if ( '' !== $u ) {
				$out[ $u ] = true;
			}
		}
		return $out;
	}
}
