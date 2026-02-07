<?php
/**
 * Pixabay image provider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Pixabay_Provider extends SWPS_Image_Provider {

    private const API_URL = 'https://pixabay.com/api/';

    public function get_slug(): string {
        return 'pixabay';
    }

    public function get_name(): string {
        return 'Pixabay';
    }

    public function get_api_key_url(): string {
        return 'https://pixabay.com/api/docs/';
    }

    public function requires_api_key(): bool {
        return true;
    }

    public function set_featured_image( int $post_id, string $query ): int|WP_Error {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'swps_no_pixabay_key', __( 'Pixabay API key not configured.', 'stratawp-seo' ) );
        }

        $hit = $this->search_photo( $query, $api_key );

        if ( is_wp_error( $hit ) ) {
            return $hit;
        }

        $image_url = $hit['largeImageURL'] ?? $hit['webformatURL'];
        $alt_text  = $hit['tags'] ?? '';
        $filename  = 'pixabay-' . $hit['id'];

        $caption = sprintf(
            'Image by <a href="%s">%s</a> on <a href="https://pixabay.com">Pixabay</a>',
            esc_url( $hit['pageURL'] ?? 'https://pixabay.com' ),
            esc_html( $hit['user'] ?? 'Unknown' )
        );

        $attachment_id = $this->download_and_attach_image( $image_url, $post_id, $filename, $alt_text, $caption );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        update_post_meta( $attachment_id, '_swps_pixabay_user', $hit['user'] ?? '' );
        update_post_meta( $attachment_id, '_swps_pixabay_url', $hit['pageURL'] ?? '' );

        return $attachment_id;
    }

    /**
     * Search Pixabay for a photo matching the query.
     */
    private function search_photo( string $query, string $api_key ): array|WP_Error {
        $query = $this->simplify_query( $query );

        $response = wp_remote_get(
            add_query_arg( [
                'key'         => $api_key,
                'q'           => $query,
                'per_page'    => 5,
                'orientation' => 'horizontal',
                'image_type'  => 'photo',
                'safesearch'  => 'true',
            ], self::API_URL ),
            [
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'swps_pixabay_request_failed', $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status ) {
            return new WP_Error( 'swps_pixabay_error', __( 'Pixabay API error.', 'stratawp-seo' ) );
        }

        if ( empty( $body['hits'] ) ) {
            return new WP_Error( 'swps_no_photos', __( 'No photos found for this topic.', 'stratawp-seo' ) );
        }

        return $body['hits'][ array_rand( $body['hits'] ) ];
    }
}
