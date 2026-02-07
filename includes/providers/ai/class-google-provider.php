<?php
/**
 * Google Gemini AI provider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Google_Provider extends SWPS_AI_Provider {

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function get_slug(): string {
        return 'google';
    }

    public function get_name(): string {
        return 'Google (Gemini)';
    }

    public function get_api_key_url(): string {
        return 'https://aistudio.google.com/apikey';
    }

    public function get_available_models(): array {
        return [
            'gemini-2.0-flash'      => 'Gemini 2.0 Flash (Fast & capable)',
            'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash Lite (Fastest, lowest cost)',
            'gemini-1.5-pro'        => 'Gemini 1.5 Pro (Most capable)',
            'gemini-1.5-flash'      => 'Gemini 1.5 Flash (Balanced)',
        ];
    }

    public function chat( string $system_prompt, string $user_message, int $max_tokens = 4096 ): string|WP_Error {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'swps_no_api_key', __( 'Please enter your Google API key in StrataWP SEO settings.', 'stratawp-seo' ) );
        }

        $model = $this->get_validated_model();
        $url   = self::API_BASE . $model . ':generateContent?key=' . $api_key;

        $body = [
            'system_instruction' => [
                'parts' => [
                    [ 'text' => $system_prompt ],
                ],
            ],
            'contents' => [
                [
                    'parts' => [
                        [ 'text' => $user_message ],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $max_tokens,
            ],
        ];

        $response = wp_remote_post( $url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
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
                sprintf( __( 'Gemini API error (%d): %s', 'stratawp-seo' ), $status_code, $error_message )
            );
        }

        if ( empty( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return new WP_Error( 'swps_empty_response', __( 'Received empty response from Gemini.', 'stratawp-seo' ) );
        }

        // Store usage data for cost tracking.
        if ( ! empty( $body['usageMetadata'] ) ) {
            $this->last_usage = [
                'input_tokens'  => (int) ( $body['usageMetadata']['promptTokenCount'] ?? 0 ),
                'output_tokens' => (int) ( $body['usageMetadata']['candidatesTokenCount'] ?? 0 ),
            ];
        }

        return $body['candidates'][0]['content']['parts'][0]['text'];
    }

    public function test_key( string $api_key ): bool|WP_Error {
        $model = $this->get_validated_model();
        $url   = self::API_BASE . $model . ':generateContent?key=' . $api_key;

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode( [
                'contents' => [
                    [
                        'parts' => [
                            [ 'text' => 'Say "ok"' ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 10,
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
