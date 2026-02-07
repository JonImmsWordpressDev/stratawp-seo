<?php
/**
 * DALL-E image provider (AI-generated images via OpenAI).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_DALLE_Provider extends SWPS_Image_Provider {

    private const API_URL = 'https://api.openai.com/v1/images/generations';

    public function get_slug(): string {
        return 'dalle';
    }

    public function get_name(): string {
        return 'DALL-E (AI Generated)';
    }

    public function get_api_key_url(): string {
        return 'https://platform.openai.com/api-keys';
    }

    public function requires_api_key(): bool {
        return true;
    }

    /**
     * Get the API key — falls back to OpenAI key if DALL-E-specific key is empty.
     */
    public function get_api_key(): string {
        $key = (string) get_option( 'swps_dalle_api_key', '' );

        if ( empty( $key ) ) {
            $key = (string) get_option( 'swps_openai_api_key', '' );
        }

        return $key;
    }

    /**
     * DALL-E benefits from descriptive prompts, so we don't strip words.
     */
    protected function simplify_query( string $query ): string {
        return $query;
    }

    public function set_featured_image( int $post_id, string $query ): int|WP_Error {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'swps_no_dalle_key', __( 'DALL-E API key not configured. Add an OpenAI or DALL-E API key in settings.', 'stratawp-seo' ) );
        }

        $prompt = $this->build_image_prompt( $query );

        $response = wp_remote_post( self::API_URL, [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'model'  => 'dall-e-3',
                'prompt' => $prompt,
                'size'   => '1792x1024',
                'n'      => 1,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'swps_dalle_request_failed', $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status ) {
            $error = $body['error']['message'] ?? __( 'DALL-E API error.', 'stratawp-seo' );
            return new WP_Error( 'swps_dalle_error', $error );
        }

        if ( empty( $body['data'][0]['url'] ) ) {
            return new WP_Error( 'swps_dalle_no_image', __( 'DALL-E did not return an image.', 'stratawp-seo' ) );
        }

        $image_url      = $body['data'][0]['url'];
        $revised_prompt = $body['data'][0]['revised_prompt'] ?? '';
        $filename       = 'dalle-' . $post_id . '-' . time();

        $attachment_id = $this->download_and_attach_image(
            $image_url,
            $post_id,
            $filename,
            $query,
            'AI-generated image by DALL-E'
        );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        if ( ! empty( $revised_prompt ) ) {
            update_post_meta( $attachment_id, '_swps_dalle_revised_prompt', $revised_prompt );
        }

        return $attachment_id;
    }

    /**
     * Build a descriptive prompt for DALL-E image generation.
     */
    private function build_image_prompt( string $query ): string {
        return sprintf(
            'A professional, high-quality blog featured image for an article about: %s. ' .
            'Photorealistic style, well-lit, visually appealing, suitable for a professional blog header. ' .
            'No text or watermarks in the image.',
            $query
        );
    }
}
