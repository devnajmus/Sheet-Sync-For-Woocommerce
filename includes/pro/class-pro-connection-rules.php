<?php
/**
 * Pro-only: per-connection sync rules (e.g. limit sheet rows to products in selected categories).
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Pro_Connection_Rules {

	public function __construct() {
		add_filter( 'sheetsync_should_process_sheet_row', array( $this, 'filter_by_category' ), 10, 5 );
	}

	/**
	 * @param bool                   $process       Whether to process the row.
	 * @param array<string,string> $peek_data     Mapped values.
	 * @param array<int,string>    $row           Raw row.
	 * @param object               $conn          Connection row.
	 * @param int                  $connection_id Connection ID.
	 */
	public function filter_by_category( bool $process, array $peek_data, array $row, object $conn, int $connection_id ): bool {
		if ( ! $process || ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return $process;
		}

		$allowed = $this->get_allowed_category_ids( $connection_id );
		if ( empty( $allowed ) ) {
			return true;
		}

		$product_id = $this->resolve_product_id_from_peek( $peek_data );
		if ( ! $product_id ) {
			$block_unknown = get_option( 'sheetsync_category_block_unknown_' . $connection_id, '' ) === '1';
			if ( $block_unknown ) {
				return false;
			}
			return true;
		}

		$cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $cats ) || empty( $cats ) ) {
			return false;
		}

		return (bool) array_intersect( array_map( 'intval', $cats ), $allowed );
	}

	/**
	 * @return int[]
	 */
	private function get_allowed_category_ids( int $connection_id ): array {
		$raw = get_option( 'sheetsync_sync_category_ids_' . $connection_id, '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$parts = array_filter( array_map( 'absint', explode( ',', str_replace( ' ', '', $raw ) ) ) );
		return array_values( array_unique( $parts ) );
	}

	private function resolve_product_id_from_peek( array $peek_data ): int {
		if ( ! empty( $peek_data['_sku'] ) ) {
			$id = wc_get_product_id_by_sku( sanitize_text_field( $peek_data['_sku'] ) );
			return $id ? (int) $id : 0;
		}
		if ( ! empty( $peek_data['post_title'] ) ) {
			$posts = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'title'          => sanitize_text_field( $peek_data['post_title'] ),
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			return ! empty( $posts ) ? (int) $posts[0] : 0;
		}
		return 0;
	}
}
