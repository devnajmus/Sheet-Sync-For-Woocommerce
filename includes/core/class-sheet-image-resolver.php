<?php
/**
 * Resolve sheet image values to WooCommerce attachments (Media Library or remote URL).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sheet_Image_Resolver', false ) ) :

class SheetSync_Sheet_Image_Resolver {

	private const ALLOWED_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Apply featured or gallery field from a sheet cell value.
	 */
	public static function apply_to_product( WC_Product $product, string $field, string $value ): void {
		$value = trim( $value );
		if ( $value === '' ) {
			return;
		}

		if ( ! $product->get_id() ) {
			$product->save();
		}

		if ( '_product_image' === $field ) {
			self::set_featured_image( $product, $value );
			return;
		}

		if ( '_gallery_images' === $field ) {
			if ( $product instanceof WC_Product_Variation ) {
				$first = self::first_token( $value );
				if ( $first !== '' ) {
					self::set_featured_image( $product, $first );
				}
				return;
			}
			self::set_gallery_images( $product, $value );
		}
	}

	/**
	 * @return list<string> URLs or attachment ID strings from a cell.
	 */
	public static function split_image_tokens( string $value ): array {
		$value = str_replace( array( "\r\n", "\n", "\r" ), ',', $value );
		$parts = preg_split( '/\s*,\s*/', $value ) ?: array();
		return array_values( array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * @return string First image token.
	 */
	public static function first_token( string $value ): string {
		$tokens = self::split_image_tokens( $value );
		return $tokens[0] ?? '';
	}

	/**
	 * Normalize relative / protocol-less paths to a full URL.
	 */
	public static function normalize_image_url( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}

		if ( preg_match( '/^\d+$/', $value ) ) {
			return '';
		}

		if ( str_starts_with( $value, '//' ) ) {
			$value = 'https:' . $value;
		} elseif ( str_starts_with( $value, '/' ) ) {
			$value = home_url( $value );
		}

		if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		return esc_url_raw( $value );
	}

	/**
	 * Resolve Media Library attachment ID from URL, attachment ID, or upload path.
	 */
	public static function resolve_attachment_id( string $value ): int {
		$value = trim( $value );
		if ( $value === '' ) {
			return 0;
		}

		if ( preg_match( '/^\d+$/', $value ) ) {
			$id = (int) $value;
			return ( 'attachment' === get_post_type( $id ) ) ? $id : 0;
		}

		$url = self::normalize_image_url( $value );
		if ( $url === '' ) {
			return 0;
		}

		$att_id = (int) attachment_url_to_postid( $url );
		if ( $att_id > 0 ) {
			return $att_id;
		}

		// Scaled/thumbnail filename e.g. photo-300x300.jpg → photo.jpg
		$base_url = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z]{2,5}$)/', '', $url );
		if ( is_string( $base_url ) && $base_url !== $url ) {
			$att_id = (int) attachment_url_to_postid( $base_url );
			if ( $att_id > 0 ) {
				return $att_id;
			}
		}

		// Query by _wp_attached_file meta (handles some CDN rewrites).
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $path !== '' && str_contains( $path, '/wp-content/uploads/' ) ) {
			$relative = ltrim( (string) preg_replace( '#^.*?/wp-content/uploads/#', '', $path ), '/' );
			if ( $relative !== '' ) {
				$att_id = self::attachment_id_by_upload_path( $relative );
				if ( $att_id > 0 ) {
					return $att_id;
				}
				$relative_base = (string) preg_replace( '/-\d+x\d+(?=\.[a-zA-Z]{2,5}$)/', '', $relative );
				if ( $relative_base !== $relative ) {
					$att_id = self::attachment_id_by_upload_path( $relative_base );
					if ( $att_id > 0 ) {
						return $att_id;
					}
				}
			}
		}

		return 0;
	}

	/**
	 * Import or reuse an image; returns attachment ID or 0.
	 */
	public static function import_attachment( string $value, int $parent_post_id = 0 ): int {
		$existing = self::resolve_attachment_id( $value );
		if ( $existing > 0 && self::is_allowed_attachment( $existing ) ) {
			return $existing;
		}

		$url = self::normalize_image_url( $value );
		if ( $url === '' ) {
			return 0;
		}

		return self::sideload_url( $url, $parent_post_id );
	}

	private static function set_featured_image( WC_Product $product, string $value ): void {
		$att_id = self::import_attachment( $value, $product->get_id() ?: 0 );
		if ( $att_id > 0 ) {
			$product->set_image_id( $att_id );
		}
	}

	private static function set_gallery_images( WC_Product $product, string $value ): void {
		$attach_ids = array();
		foreach ( self::split_image_tokens( $value ) as $token ) {
			$att_id = self::import_attachment( $token, $product->get_id() ?: 0 );
			if ( $att_id > 0 ) {
				$attach_ids[] = $att_id;
			}
		}
		$attach_ids = array_values( array_unique( $attach_ids ) );
		if ( ! empty( $attach_ids ) ) {
			$product->set_gallery_image_ids( $attach_ids );
		}
	}

	private static function attachment_id_by_upload_path( string $relative_path ): int {
		global $wpdb;
		$relative_path = ltrim( $relative_path, '/' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relative_path
			)
		);
		if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
			return $id;
		}
		return 0;
	}

	private static function is_allowed_attachment( int $attachment_id ): bool {
		$mime = (string) get_post_mime_type( $attachment_id );
		return in_array( $mime, self::ALLOWED_MIMES, true );
	}

	private static function sideload_url( string $url, int $parent_post_id ): int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return 0;
		}

		if ( function_exists( 'sheetsync_is_safe_remote_url' ) && ! sheetsync_is_safe_remote_url( $url ) ) {
			SheetSync_Logger::error( 'SheetSync image blocked (private/reserved URL) — ' . $url );
			return 0;
		}

		$max_attempts = max( 1, min( 5, (int) apply_filters( 'sheetsync_image_download_attempts', 3 ) ) );
		$allow_sleep  = ( defined( 'SHEETSYNC_BG_SYNC' ) && SHEETSYNC_BG_SYNC )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );

		for ( $try = 0; $try < $max_attempts; ++$try ) {
			$head = wp_remote_head(
				$url,
				array(
					'timeout'     => 12,
					'redirection' => 5,
				)
			);
			if ( ! is_wp_error( $head ) ) {
				$len = (int) wp_remote_retrieve_header( $head, 'content-length' );
				if ( $len > 5 * MB_IN_BYTES ) {
					return 0;
				}
			}

			$att_id = function_exists( 'sheetsync_sideload_image_from_url' )
				? sheetsync_sideload_image_from_url( $url, $parent_post_id )
				: media_sideload_image( $url, $parent_post_id, null, 'id' );
			if ( is_wp_error( $att_id ) ) {
				if ( $try < $max_attempts - 1 && $allow_sleep ) {
					sleep( min( 4, $try + 1 ) );
					continue;
				}
				SheetSync_Logger::error( 'SheetSync image sideload failed: ' . $att_id->get_error_message() . ' — ' . $url );
				return 0;
			}

			$att_id = (int) $att_id;
			if ( ! self::is_allowed_attachment( $att_id ) ) {
				wp_delete_attachment( $att_id, true );
				SheetSync_Logger::error( 'SheetSync image rejected (MIME) — ' . $url );
				return 0;
			}

			return $att_id;
		}

		return 0;
	}
}

endif;
