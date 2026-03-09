<?php
/**
 * Gemini (Nano Banana) image provider — AI-generated images via Google Gemini API.
 *
 * Uses the same Google API key as the Gemini AI text provider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Gemini_Image_Provider extends SWPS_Image_Provider {

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const MODEL    = 'gemini-2.5-flash-image';

    public function get_slug(): string {
        return 'gemini';
    }

    public function get_name(): string {
        return 'Gemini (AI Generated)';
    }

    public function get_api_key_url(): string {
        return 'https://aistudio.google.com/apikey';
    }

    public function requires_api_key(): bool {
        return false; // Reuses the Google AI API key.
    }

    /**
     * Get the API key — reuses the Google AI key.
     */
    public function get_api_key(): string {
        return (string) get_option( 'swps_google_api_key', '' );
    }

    /**
     * Gemini benefits from descriptive prompts, so we don't strip words.
     */
    protected function simplify_query( string $query ): string {
        return $query;
    }

    public function set_featured_image( int $post_id, string $query ): int|WP_Error {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'swps_no_google_key', __( 'Google API key not configured. Add your Google API key in settings.', 'stratawp-seo' ) );
        }

        $prompt = sprintf(
            'Generate a professional, high-quality blog featured image for an article about: %s. '
            . 'Photorealistic style, well-lit, visually appealing, suitable for a professional blog header. '
            . 'No text or watermarks in the image.',
            $query
        );

        $tmp_file = $this->generate_image( $prompt, $api_key, '16:9' );
        if ( is_wp_error( $tmp_file ) ) {
            return $tmp_file;
        }

        $filename = 'gemini-' . $post_id . '-' . time();

        $attachment_id = $this->attach_temp_file( $tmp_file, $post_id, $filename, $query, 'AI-generated image by Gemini' );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        set_post_thumbnail( $post_id, $attachment_id );

        return $attachment_id;
    }

    /**
     * Generate an image via Gemini and return a temp file path.
     *
     * @param string $prompt  The image generation prompt.
     * @param string $api_key Google API key.
     * @param string $aspect  Aspect ratio (e.g., '16:9', '1:1').
     * @return string|WP_Error Temp file path on success.
     */
    public function generate_image( string $prompt, string $api_key, string $aspect = '1:1' ): string|WP_Error {
        $url = self::API_BASE . self::MODEL . ':generateContent?key=' . $api_key;

        $response = wp_remote_post( $url, [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode( [
                'contents' => [
                    [
                        'parts' => [
                            [ 'text' => $prompt ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseModalities' => [ 'TEXT', 'IMAGE' ],
                    'imageConfig' => [
                        'aspectRatio' => $aspect,
                    ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'swps_gemini_image_request_failed', $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status ) {
            $error = $body['error']['message'] ?? __( 'Gemini API error.', 'stratawp-seo' );
            return new WP_Error( 'swps_gemini_image_error', $error );
        }

        // Find the image part in the response.
        $candidates = $body['candidates'] ?? [];

        if ( empty( $candidates ) ) {
            // Log block reason if present (safety filter, etc.).
            $block_reason = $body['promptFeedback']['blockReason'] ?? 'unknown';
            error_log( '[StrataWP SEO] Gemini image: No candidates returned. Block reason: ' . $block_reason );
            error_log( '[StrataWP SEO] Gemini image: Full response: ' . wp_json_encode( $body ) );
            return new WP_Error( 'swps_gemini_no_image', __( 'Gemini did not return an image. The prompt may have been blocked.', 'stratawp-seo' ) );
        }

        $parts = $candidates[0]['content']['parts'] ?? [];
        $image_data = null;
        $mime_type  = 'image/png';

        foreach ( $parts as $part ) {
            // Check both camelCase and snake_case field names.
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if ( ! empty( $inline['data'] ) ) {
                $image_data = $inline['data'];
                $mime_type  = $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png';
                break;
            }
        }

        if ( empty( $image_data ) ) {
            error_log( '[StrataWP SEO] Gemini image: Response has candidates but no image data. Parts: ' . wp_json_encode( $parts ) );
            return new WP_Error( 'swps_gemini_no_image', __( 'Gemini did not return an image.', 'stratawp-seo' ) );
        }

        // Decode base64 and write to temp file.
        $decoded = base64_decode( $image_data );
        if ( false === $decoded ) {
            return new WP_Error( 'swps_gemini_decode_failed', __( 'Failed to decode Gemini image data.', 'stratawp-seo' ) );
        }

        $ext = match ( $mime_type ) {
            'image/png'  => '.png',
            'image/jpeg' => '.jpg',
            'image/webp' => '.webp',
            default      => '.png',
        };

        $tmp_file = wp_tempnam( 'gemini_img' ) . $ext;
        if ( false === file_put_contents( $tmp_file, $decoded ) ) {
            return new WP_Error( 'swps_gemini_write_failed', __( 'Failed to write Gemini image to temp file.', 'stratawp-seo' ) );
        }

        return $tmp_file;
    }

    /**
     * Attach a local temp file as a WordPress media attachment.
     *
     * @param string $tmp_file  Path to temp file.
     * @param int    $post_id   Post to attach to.
     * @param string $filename  Desired filename (without extension).
     * @param string $alt_text  Alt text for the image.
     * @param string $caption   Caption for the attachment.
     * @return int|WP_Error Attachment ID or error.
     */
    private function attach_temp_file( string $tmp_file, int $post_id, string $filename, string $alt_text = '', string $caption = '' ): int|WP_Error {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Convert to WebP for optimized file size.
        $webp_tmp = $this->convert_to_webp( $tmp_file );
        if ( $webp_tmp !== $tmp_file ) {
            @unlink( $tmp_file );
            $tmp_file = $webp_tmp;
            $ext = '.webp';
        } else {
            $ext = '.' . pathinfo( $tmp_file, PATHINFO_EXTENSION );
        }

        $file_array = [
            'name'     => sanitize_title( $filename ) . $ext,
            'tmp_name' => $tmp_file,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp_file );
            return $attachment_id;
        }

        if ( ! empty( $alt_text ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
        }

        if ( ! empty( $caption ) ) {
            wp_update_post( [
                'ID'           => $attachment_id,
                'post_excerpt' => $caption,
            ] );
        }

        return $attachment_id;
    }
}
