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
     * Stop reason from the last API call (e.g., 'end_turn', 'max_tokens').
     * Set by providers after successful calls.
     */
    protected ?string $last_stop_reason = null;

    /**
     * Whether the current request expects a JSON response.
     * Providers should check this to enable JSON mode when available.
     */
    protected bool $requesting_json = false;

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
        $this->last_usage       = null;
        $this->last_stop_reason = null;
        $this->requesting_json  = true;

        $response = $this->chat( $system_prompt, $user_message, $max_tokens );

        $this->requesting_json = false;

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Detect truncation before parsing — the AI hit max_tokens.
        if ( $this->last_stop_reason === 'max_tokens' ) {
            return new WP_Error(
                'swps_response_truncated',
                sprintf(
                    __( 'AI response was truncated (hit %d token limit). Try reducing word count or increasing the token budget.', 'stratawp-seo' ),
                    $max_tokens
                )
            );
        }

        $decoded = $this->parse_json_response( $response );

        if ( is_wp_error( $decoded ) ) {
            return $decoded;
        }

        // Inject usage data from the provider if available.
        if ( ! empty( $this->last_usage ) ) {
            $decoded['_usage'] = $this->last_usage;
        }

        return $decoded;
    }

    /**
     * Parse an AI response string as JSON with multiple fallback strategies.
     *
     * @param string $response The raw AI response text.
     * @return array|WP_Error Parsed JSON array or error.
     */
    protected function parse_json_response( string $response ): array|WP_Error {
        $cleaned = trim( $response );

        // Strip BOM if present.
        $cleaned = ltrim( $cleaned, "\xEF\xBB\xBF" );

        // Strip markdown code fences if present.
        $cleaned = preg_replace( '/^```(?:json)?\s*/s', '', $cleaned );
        $cleaned = preg_replace( '/\s*```\s*$/s', '', $cleaned );
        $cleaned = trim( $cleaned );

        // Strip any text before the first { or after the last }.
        $first_brace = strpos( $cleaned, '{' );
        $last_brace  = strrpos( $cleaned, '}' );
        if ( $first_brace !== false && $last_brace !== false && $last_brace > $first_brace ) {
            $cleaned = substr( $cleaned, $first_brace, $last_brace - $first_brace + 1 );
        }

        // Attempt 1: Direct parse.
        $decoded = json_decode( $cleaned, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $decoded;
        }

        // Attempt 2: Sanitize control characters inside JSON string values.
        // Replace literal control chars (0x00-0x1F) that aren't already escaped.
        $sanitized = preg_replace_callback(
            '/"((?:[^"\\\\]|\\\\.)*)"/s',
            function ( $m ) {
                $val = $m[1];
                // Escape literal control characters that break JSON.
                $val = str_replace(
                    [ "\n", "\r", "\t", "\x08", "\x0C" ],
                    [ '\\n', '\\r', '\\t', '\\b', '\\f' ],
                    $val
                );
                // Remove any remaining control characters (0x00-0x1F) except already-escaped ones.
                $val = preg_replace( '/[\x00-\x1F]/', '', $val );
                return '"' . $val . '"';
            },
            $cleaned
        );

        $decoded = json_decode( $sanitized, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $decoded;
        }

        // Attempt 3: Try with JSON_INVALID_UTF8_SUBSTITUTE to handle encoding issues.
        $decoded = json_decode( $sanitized, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $decoded;
        }

        // Attempt 4: Fix unescaped double quotes inside JSON string values.
        // Walk the string character-by-character, tracking whether we're inside a
        // string value, and escape any unescaped quotes that aren't structural.
        $repaired = $this->repair_json_quotes( $cleaned );
        if ( $repaired !== $cleaned ) {
            $decoded = json_decode( $repaired, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                return $decoded;
            }
        }

        // All attempts failed — return error with diagnostic info.
        $len  = strlen( $cleaned );
        $tail = $len > 120 ? substr( $cleaned, -120 ) : $cleaned;

        return new WP_Error(
            'swps_json_parse_error',
            sprintf(
                __( 'Failed to parse AI response as JSON: %s [len=%d, tail="%s"]', 'stratawp-seo' ),
                json_last_error_msg(),
                $len,
                $tail
            )
        );
    }

    /**
     * Attempt to repair unescaped double quotes inside JSON string values.
     *
     * Walks the string character-by-character to find quotes that appear inside
     * a JSON string value but aren't escaped, then escapes them.
     *
     * @param string $json The JSON string to repair.
     * @return string The repaired JSON string.
     */
    protected function repair_json_quotes( string $json ): string {
        $len    = strlen( $json );
        $result = '';
        $i      = 0;

        while ( $i < $len ) {
            $ch = $json[ $i ];

            // Outside a string: copy everything until we hit a quote.
            if ( $ch !== '"' ) {
                $result .= $ch;
                $i++;
                continue;
            }

            // Opening quote of a string value — find the proper closing quote.
            $result .= '"';
            $i++;
            $str_content = '';

            while ( $i < $len ) {
                $ch = $json[ $i ];

                if ( $ch === '\\' && $i + 1 < $len ) {
                    // Already-escaped character — keep as-is.
                    $str_content .= $ch . $json[ $i + 1 ];
                    $i += 2;
                    continue;
                }

                if ( $ch === '"' ) {
                    // Is this the real closing quote?
                    // Look ahead: after skipping whitespace, the next char should be
                    // a structural JSON character: , ] } or :
                    $ahead = ltrim( substr( $json, $i + 1, 10 ) );
                    if ( $ahead === '' || strpos( ',]}:', $ahead[0] ) !== false ) {
                        // Real closing quote.
                        break;
                    }
                    // Unescaped quote inside the value — escape it.
                    $str_content .= '\\"';
                    $i++;
                    continue;
                }

                $str_content .= $ch;
                $i++;
            }

            $result .= $str_content . '"';
            $i++; // Skip the closing quote.
        }

        return $result;
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
