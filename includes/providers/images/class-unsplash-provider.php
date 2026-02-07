<?php
/**
 * Unsplash image provider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Unsplash_Provider extends SWPS_Image_Provider {

    private const API_URL = 'https://api.unsplash.com';

    public function get_slug(): string {
        return 'unsplash';
    }

    public function get_name(): string {
        return 'Unsplash';
    }

    public function get_api_key_url(): string {
        return 'https://unsplash.com/developers';
    }

    public function requires_api_key(): bool {
        return true;
    }

    public function set_featured_image( int $post_id, string $query ): int|WP_Error {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'swps_no_unsplash_key', __( 'Unsplash API key not configured.', 'stratawp-seo' ) );
        }

        $photo = $this->search_photo( $query, $api_key );

        if ( is_wp_error( $photo ) ) {
            return $photo;
        }

        $image_url = $photo['urls']['regular'] ?? $photo['urls']['small'];
        $slug      = $photo['slug'] ?? $photo['id'];
        $alt_text  = $photo['alt_description'] ?? $photo['description'] ?? '';

        $photographer     = $photo['user']['name'] ?? 'Unknown';
        $photographer_url = $photo['user']['links']['html'] ?? '';

        $caption = sprintf(
            'Photo by <a href="%s?utm_source=stratawp_seo&utm_medium=referral">%s</a> on <a href="https://unsplash.com/?utm_source=stratawp_seo&utm_medium=referral">Unsplash</a>',
            esc_url( $photographer_url ),
            esc_html( $photographer )
        );

        $attachment_id = $this->download_and_attach_image( $image_url, $post_id, $slug, $alt_text, $caption );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        // Store Unsplash attribution as attachment meta.
        update_post_meta( $attachment_id, '_swps_unsplash_photographer', $photographer );
        update_post_meta( $attachment_id, '_swps_unsplash_photographer_url', $photographer_url );
        update_post_meta( $attachment_id, '_swps_unsplash_url', $photo['links']['html'] ?? '' );

        // Trigger Unsplash download endpoint (required by their API guidelines).
        if ( ! empty( $photo['links']['download_location'] ) ) {
            wp_remote_get( $photo['links']['download_location'], [
                'timeout'  => 5,
                'blocking' => false,
                'headers'  => [
                    'Authorization' => 'Client-ID ' . $api_key,
                ],
            ] );
        }

        return $attachment_id;
    }

    /**
     * Search Unsplash for a photo matching the query.
     */
    private function search_photo( string $query, string $api_key ): array|WP_Error {
        $query = $this->simplify_query( $query );

        $response = wp_remote_get(
            add_query_arg( [
                'query'          => $query,
                'per_page'       => 5,
                'orientation'    => 'landscape',
                'content_filter' => 'high',
            ], self::API_URL . '/search/photos' ),
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization'  => 'Client-ID ' . $api_key,
                    'Accept-Version' => 'v1',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'swps_unsplash_request_failed', $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status ) {
            $error = $body['errors'][0] ?? 'Unknown Unsplash API error.';
            return new WP_Error( 'swps_unsplash_error', $error );
        }

        if ( empty( $body['results'] ) ) {
            return new WP_Error( 'swps_no_photos', __( 'No photos found for this topic.', 'stratawp-seo' ) );
        }

        return $body['results'][ array_rand( $body['results'] ) ];
    }
}
