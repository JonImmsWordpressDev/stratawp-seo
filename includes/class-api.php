<?php
/**
 * Claude API wrapper.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_API {

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * Get the selected AI model.
     */
    private function get_model(): string {
        return get_option( 'swps_model', 'claude-opus-4-6' );
    }

    /**
     * Send a message to Claude and get a response.
     *
     * @param string $system_prompt The system instructions.
     * @param string $user_message  The user message/prompt.
     * @param int    $max_tokens    Maximum tokens in response.
     * @return string|WP_Error The response text or error.
     */
    public function chat( string $system_prompt, string $user_message, int $max_tokens = 4096 ): string|WP_Error {
        $api_key = get_option( 'swps_api_key' );

        if ( empty( $api_key ) ) {
            return new WP_Error( 'swps_no_api_key', __( 'Please enter your Anthropic API key in StrataWP SEO settings.', 'stratawp-seo' ) );
        }

        $body = [
            'model'      => $this->get_model(),
            'max_tokens' => $max_tokens,
            'system'     => $system_prompt,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $user_message,
                ],
            ],
        ];

        $response = wp_remote_post( self::API_URL, [
            'timeout' => 120, // Content generation can take a while.
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'        => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'swps_api_request_failed',
                sprintf( __( 'API request failed: %s', 'stratawp-seo' ), $response->get_error_message() )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status_code ) {
            $error_message = $body['error']['message'] ?? __( 'Unknown API error.', 'stratawp-seo' );
            return new WP_Error(
                'swps_api_error',
                sprintf( __( 'Claude API error (%d): %s', 'stratawp-seo' ), $status_code, $error_message )
            );
        }

        if ( empty( $body['content'][0]['text'] ) ) {
            return new WP_Error( 'swps_empty_response', __( 'Received empty response from Claude.', 'stratawp-seo' ) );
        }

        return $body['content'][0]['text'];
    }

    /**
     * Send a message and parse the response as JSON.
     *
     * @param string $system_prompt The system instructions.
     * @param string $user_message  The user message/prompt.
     * @param int    $max_tokens    Maximum tokens in response.
     * @return array|WP_Error Parsed JSON array or error.
     */
    public function chat_json( string $system_prompt, string $user_message, int $max_tokens = 4096 ): array|WP_Error {
        $response = $this->chat( $system_prompt, $user_message, $max_tokens );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Strip markdown code fences if present.
        $cleaned = preg_replace( '/^```(?:json)?\s*/', '', trim( $response ) );
        $cleaned = preg_replace( '/\s*```$/', '', $cleaned );

        $decoded = json_decode( $cleaned, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error(
                'swps_json_parse_error',
                sprintf( __( 'Failed to parse AI response as JSON: %s', 'stratawp-seo' ), json_last_error_msg() )
            );
        }

        return $decoded;
    }

    /**
     * Test the API key by making a minimal request.
     *
     * @param string $api_key The key to test.
     * @return bool|WP_Error True if valid, WP_Error if not.
     */
    public function test_key( string $api_key ): bool|WP_Error {
        $response = wp_remote_post( self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'        => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( [
                'model'      => $this->get_model(),
                'max_tokens' => 10,
                'messages'   => [
                    [ 'role' => 'user', 'content' => 'Say "ok"' ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( 200 === $status_code ) {
            return true;
        }

        $body          = json_decode( wp_remote_retrieve_body( $response ), true );
        $error_message = $body['error']['message'] ?? 'Invalid API key.';

        return new WP_Error( 'swps_invalid_key', $error_message );
    }
}
