<?php
/**
 * Pexels image provider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Pexels_Provider extends SWPS_Image_Provider {

	private const API_URL = 'https://api.pexels.com/v1/search';

	public function get_slug(): string {
		return 'pexels';
	}

	public function get_name(): string {
		return 'Pexels';
	}

	public function get_api_key_url(): string {
		return 'https://www.pexels.com/api/';
	}

	public function requires_api_key(): bool {
		return true;
	}

	public function set_featured_image( int $post_id, string $query ): int|WP_Error {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'swps_no_pexels_key', __( 'Pexels API key not configured.', 'stratawp-seo' ) );
		}

		$photo = $this->search_photo( $query, $api_key );

		if ( is_wp_error( $photo ) ) {
			return $photo;
		}

		$image_url    = $photo['src']['large2x'] ?? $photo['src']['large'] ?? $photo['src']['original'];
		$photographer = $photo['photographer'] ?? 'Unknown';
		$photo_url    = $photo['url'] ?? '';
		$alt_text     = $photo['alt'] ?? '';

		$caption = sprintf(
			'Photo by <a href="%s">%s</a> on <a href="https://www.pexels.com">Pexels</a>',
			esc_url( $photo_url ),
			esc_html( $photographer )
		);

		$filename = 'pexels-' . sanitize_title( $photographer ) . '-' . $photo['id'];

		$attachment_id = $this->download_and_attach_image( $image_url, $post_id, $filename, $alt_text, $caption );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_swps_pexels_photographer', $photographer );
		update_post_meta( $attachment_id, '_swps_pexels_url', $photo_url );

		return $attachment_id;
	}

	/**
	 * Search Pexels for a photo matching the query.
	 */
	private function search_photo( string $query, string $api_key ): array|WP_Error {
		$query = $this->simplify_query( $query );

		$response = wp_remote_get(
			add_query_arg(
				array(
					'query'       => $query,
					'per_page'    => 5,
					'orientation' => 'landscape',
				),
				self::API_URL
			),
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'swps_pexels_request_failed', $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$error = $body['error'] ?? 'Unknown Pexels API error.';
			return new WP_Error( 'swps_pexels_error', $error );
		}

		if ( empty( $body['photos'] ) ) {
			return new WP_Error( 'swps_no_photos', __( 'No photos found for this topic.', 'stratawp-seo' ) );
		}

		return $body['photos'][ array_rand( $body['photos'] ) ];
	}
}
