<?php
/**
 * Abstract base class for image providers.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SWPS_Image_Provider {

	/**
	 * Search for and attach a featured image to a post.
	 *
	 * @param int    $post_id The WordPress post ID.
	 * @param string $query   Search query (usually the post title or focus keyword).
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	abstract public function set_featured_image( int $post_id, string $query ): int|WP_Error;

	/**
	 * Get the provider slug (e.g., 'unsplash', 'pexels').
	 */
	abstract public function get_slug(): string;

	/**
	 * Get the provider display name (e.g., 'Unsplash').
	 */
	abstract public function get_name(): string;

	/**
	 * Get the URL where users can obtain an API key.
	 */
	abstract public function get_api_key_url(): string;

	/**
	 * Whether this provider requires an API key.
	 */
	abstract public function requires_api_key(): bool;

	/**
	 * Get the stored API key for this provider.
	 */
	public function get_api_key(): string {
		return (string) get_option( $this->get_api_key_option(), '' );
	}

	/**
	 * Get the option name for this provider's API key.
	 */
	public function get_api_key_option(): string {
		return 'swps_' . $this->get_slug() . '_api_key';
	}

	/**
	 * Simplify a post title into a better image search query.
	 *
	 * @param string $query The original query (usually post title).
	 * @return string Simplified search query.
	 */
	protected function simplify_query( string $query ): string {
		$stop_words = array( 'how', 'to', 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'what', 'why', 'when', 'where', 'which', 'who', 'your', 'our', 'their', 'this', 'that', 'for', 'and', 'but', 'with', 'from', 'about', 'into', 'best', 'top', 'guide', 'ultimate', 'complete', 'tips', 'tricks' );

		$words = explode( ' ', strtolower( $query ) );
		$words = array_filter(
			$words,
			function ( $word ) use ( $stop_words ) {
				return ! in_array( $word, $stop_words, true ) && strlen( $word ) > 2;
			}
		);

		return implode( ' ', array_slice( array_values( $words ), 0, 4 ) );
	}

	/**
	 * Download an image URL and attach it to a post as featured image.
	 *
	 * @param string $image_url  The URL to download.
	 * @param int    $post_id    Post to attach to.
	 * @param string $filename   Desired filename (without extension).
	 * @param string $alt_text   Alt text for the image.
	 * @param string $caption    Caption/excerpt for the attachment.
	 * @return int|WP_Error Attachment ID or error.
	 */
	protected function download_and_attach_image( string $image_url, int $post_id, string $filename, string $alt_text = '', string $caption = '' ): int|WP_Error {
		if ( empty( $image_url ) ) {
			return new WP_Error( 'swps_no_image_url', __( 'No image URL found.', 'stratawp-seo' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $image_url );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Convert to WebP for optimized file size.
		$webp_tmp = $this->convert_to_webp( $tmp );

		if ( $webp_tmp !== $tmp ) {
			@unlink( $tmp );
			$tmp = $webp_tmp;
			$ext = '.webp';
		} else {
			$ext = '.jpg';
		}

		$file_array = array(
			'name'     => sanitize_title( $filename ) . $ext,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		if ( ! empty( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		}

		if ( ! empty( $caption ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => $caption,
				)
			);
		}

		set_post_thumbnail( $post_id, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Convert an image file to WebP format.
	 *
	 * @param string $file_path Path to the source image file.
	 * @return string Path to the WebP file on success, or the original path on failure.
	 */
	protected function convert_to_webp( string $file_path ): string {
		if ( ! function_exists( 'imagewebp' ) ) {
			return $file_path;
		}

		$image_data = file_get_contents( $file_path );
		if ( false === $image_data ) {
			return $file_path;
		}

		$image = @imagecreatefromstring( $image_data );
		if ( false === $image ) {
			return $file_path;
		}

		// Preserve transparency (for PNG sources).
		imagepalettetotruecolor( $image );
		imagealphablending( $image, true );
		imagesavealpha( $image, true );

		$webp_path = $file_path . '.webp';

		if ( ! imagewebp( $image, $webp_path, 85 ) ) {
			imagedestroy( $image );
			return $file_path;
		}

		imagedestroy( $image );

		return $webp_path;
	}
}
