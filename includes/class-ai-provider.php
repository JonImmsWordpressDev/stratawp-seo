<?php
/**
 * Abstract base class for AI providers.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class SWPS_AI_Provider {

    /**
     * Last API call usage data (set by providers after successful calls).
     *
     * @var array{input_tokens: int, output_tokens: int}|null
     */
    protected ?array $last_usage = null;

    /**
     * Send a message to the AI and get a text response.
     *
     * @param string $system_prompt The system instructions.
     * @param string $user_message  The user message/prompt.
     * @param int    $max_tokens    Maximum tokens in response.
     * @return string|WP_Error The response text or error.
     */
    abstract public function chat( string $system_prompt, string $user_message, int $max_tokens = 4096 ): string|WP_Error;

    /**
     * Test the API key by making a minimal request.
     *
     * @param string $api_key The key to test.
     * @return bool|WP_Error True if valid, WP_Error if not.
     */
    abstract public function test_key( string $api_key ): bool|WP_Error;

    /**
     * Get available models for this provider.
     *
     * @return array<string, string> Model ID => display name.
     */
    abstract public function get_available_models(): array;

    /**
     * Get the provider slug (e.g., 'anthropic', 'openai').
     */
    abstract public function get_slug(): string;

    /**
     * Get the provider display name (e.g., 'Anthropic (Claude)').
     */
    abstract public function get_name(): string;

    /**
     * Get the URL where users can obtain an API key.
     */
    abstract public function get_api_key_url(): string;

    /**
     * Send a message and parse the response as JSON.
     *
     * @param string $system_prompt The system instructions.
     * @param string $user_message  The user message/prompt.
     * @param int    $max_tokens    Maximum tokens in response.
     * @return array|WP_Error Parsed JSON array or error.
     */
    public function chat_json( string $system_prompt, string $user_message, int $max_tokens = 4096 ): array|WP_Error {
        $this->last_usage = null;

        $response = $this->chat( $system_prompt, $user_message, $max_tokens );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Strip markdown code fences if present.
        $cleaned = preg_replace( '/^```(?:json)?\s*/', '', trim( $response ) );
        $cleaned = preg_replace( '/\s*```$/', '', $cleaned );

        $decoded = json_decode( $cleaned, true );

        // If JSON parsing fails, try sanitizing control characters inside string values.
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $sanitized = preg_replace_callback(
                '/"((?:[^"\\\\]|\\\\.)*)"/s',
                function ( $m ) {
                    $val = $m[1];
                    $val = str_replace( [ "\n", "\r", "\t" ], [ '\\n', '\\r', '\\t' ], $val );
                    return '"' . $val . '"';
                },
                $cleaned
            );
            $decoded = json_decode( $sanitized, true );
        }

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error(
                'swps_json_parse_error',
                sprintf( __( 'Failed to parse AI response as JSON: %s', 'stratawp-seo' ), json_last_error_msg() )
            );
        }

        // Inject usage data from the provider if available.
        if ( ! empty( $this->last_usage ) ) {
            $decoded['_usage'] = $this->last_usage;
        }

        return $decoded;
    }

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
     * Get the validated model — falls back to the first available model
     * if the stored model is not valid for this provider.
     */
    public function get_validated_model(): string {
        $stored = get_option( 'swps_model', '' );
        $models = $this->get_available_models();

        if ( ! empty( $stored ) && array_key_exists( $stored, $models ) ) {
            return $stored;
        }

        // Fall back to the first model for this provider.
        return array_key_first( $models );
    }
}
