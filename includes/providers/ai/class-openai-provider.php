<?php
/**
 * OpenAI (GPT) AI provider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_OpenAI_Provider extends SWPS_AI_Provider {

    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function get_slug(): string {
        return 'openai';
    }

    public function get_name(): string {
        return 'OpenAI (GPT)';
    }

    public function get_api_key_url(): string {
        return 'https://platform.openai.com/api-keys';
    }

    public function get_available_models(): array {
        return [
            'gpt-4o'      => 'GPT-4o (Most capable)',
            'gpt-4o-mini' => 'GPT-4o Mini (Fast & affordable)',
            'gpt-4-turbo' => 'GPT-4 Turbo (High quality)',
            'o3-mini'     => 'o3-mini (Reasoning model)',
        ];
    }

    public function chat( string $system_prompt, string $user_message, int $max_tokens = 4096 ): string|WP_Error {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'swps_no_api_key', __( 'Please enter your OpenAI API key in StrataWP SEO settings.', 'stratawp-seo' ) );
        }

        $body = [
            'model'      => $this->get_validated_model(),
            'max_tokens' => $max_tokens,
            'messages'   => [
                [
                    'role'    => 'system',
                    'content' => $system_prompt,
                ],
                [
                    'role'    => 'user',
                    'content' => $user_message,
                ],
            ],
        ];

        $response = wp_remote_post( self::API_URL, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
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
                sprintf( __( 'OpenAI API error (%d): %s', 'stratawp-seo' ), $status_code, $error_message )
            );
        }

        if ( empty( $body['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'swps_empty_response', __( 'Received empty response from OpenAI.', 'stratawp-seo' ) );
        }

        return $body['choices'][0]['message']['content'];
    }

    public function test_key( string $api_key ): bool|WP_Error {
        $response = wp_remote_post( self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
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
