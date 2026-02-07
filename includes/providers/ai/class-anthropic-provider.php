<?php
/**
 * Anthropic (Claude) AI provider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Anthropic_Provider extends SWPS_AI_Provider {

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    public function get_slug(): string {
        return 'anthropic';
    }

    public function get_name(): string {
        return 'Anthropic (Claude)';
    }

    public function get_api_key_url(): string {
        return 'https://console.anthropic.com/';
    }

    public function get_available_models(): array {
        return [
            'claude-opus-4-6'            => 'Claude Opus 4.6 (Most powerful, higher cost)',
            'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5 (Great balance of quality & cost)',
            'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5 (Fastest, lowest cost)',
        ];
    }

    public function chat( string $system_prompt, string $user_message, int $max_tokens = 4096 ): string|WP_Error {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'swps_no_api_key', __( 'Please enter your Anthropic API key in StrataWP SEO settings.', 'stratawp-seo' ) );
        }

        $body = [
            'model'      => $this->get_validated_model(),
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
            'timeout' => 120,
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

    public function test_key( string $api_key ): bool|WP_Error {
        $response = wp_remote_post( self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'        => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( [
                'model'      => $this->get_validated_model(),
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
