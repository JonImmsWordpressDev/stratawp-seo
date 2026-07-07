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
		return array(
			'claude-opus-4-8'            => 'Claude Opus 4.8',
			'claude-sonnet-5'            => 'Claude Sonnet 5',
			'claude-opus-4-7'            => 'Claude Opus 4.7',
			'claude-opus-4-6'            => 'Claude Opus 4.6',
			'claude-sonnet-4-6'          => 'Claude Sonnet 4.6',
			'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5',
			'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5',
		);
	}

	/**
	 * Whether a Claude model accepts assistant-message prefill.
	 *
	 * Prefill was removed in the Claude 4.6 family, and every model released
	 * since (Opus 4.7/4.8, Sonnet 5, ...) rejects it with a 400. The previous
	 * denylist regex required a hyphen after the version number ("5-"), so
	 * bare model aliases like `claude-sonnet-5` slipped through and the whole
	 * request failed with "This model does not support assistant message
	 * prefill". Allowlisting the known prefill-capable generations instead is
	 * future-proof: an unknown or newer model simply skips the prefill (the
	 * JSON parser copes fine without it) rather than erroring the request.
	 *
	 * Matches: claude-2.x, claude-3.x, and the 4.0/4.1/4.5 generations —
	 * aliases (claude-opus-4-1, claude-haiku-4-5), dated minor IDs
	 * (claude-sonnet-4-5-20250929), and dated 4.0 IDs (claude-opus-4-20250514).
	 *
	 * @param string $model Anthropic model ID.
	 * @return bool
	 */
	public static function model_supports_prefill( string $model ): bool {
		return (bool) preg_match( '/^claude-(2|3)[.-]|-4-[015](-|$)|-4-\d{8}/', $model );
	}

	/**
	 * Concatenate the text content blocks of a Messages API response body.
	 *
	 * Newer Claude models (Sonnet 5 and later) run adaptive thinking by
	 * default, so the `content` array may open with one or more `thinking`
	 * blocks before the `text` block — `content[0]['text']` is not a safe
	 * read. Non-text blocks (thinking, tool_use, ...) are skipped.
	 *
	 * @param array $content Decoded `content` array from the response body.
	 * @return string Concatenated text ('' when the response has no text).
	 */
	public static function extract_text( array $content ): string {
		$parts = array();
		foreach ( $content as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$parts[] = (string) ( $block['text'] ?? '' );
			}
		}
		return implode( '', $parts );
	}

	public function chat( string $system_prompt, string $user_message, int $max_tokens = 4096 ): string|WP_Error {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'swps_no_api_key', __( 'Please enter your Anthropic API key in StrataWP SEO settings.', 'stratawp-seo' ) );
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $user_message,
			),
		);

		// Prefill with opening brace to guide Claude toward valid JSON output.
		$model            = $this->get_validated_model();
		$supports_prefill = self::model_supports_prefill( $model );

		if ( $this->requesting_json && $supports_prefill ) {
			$messages[] = array(
				'role'    => 'assistant',
				'content' => '{',
			);
		}

		$body = array(
			'model'      => $this->get_validated_model(),
			'max_tokens' => $max_tokens,
			'system'     => $system_prompt,
			'messages'   => $messages,
		);

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 180,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

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
				sprintf( __( 'Claude API error (%1$d): %2$s', 'stratawp-seo' ), $status_code, $error_message ),
				array( 'status' => (int) $status_code )
			);
		}

		// Store usage data for cost tracking.
		if ( ! empty( $body['usage'] ) ) {
			$this->last_usage = array(
				'input_tokens'  => (int) ( $body['usage']['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ),
			);
		}

		// Store stop reason for truncation detection.
		// Anthropic: 'end_turn', 'max_tokens', 'stop_sequence', 'refusal'.
		$this->last_stop_reason = $body['stop_reason'] ?? null;

		// Newer Claude models (Sonnet 5 and later) run adaptive thinking by
		// default, so the response content can start with `thinking` blocks
		// before the `text` block — reading content[0]['text'] directly made
		// every such response look empty ("Received empty response from
		// Claude"). Collect every text block instead.
		$text = self::extract_text( (array) ( $body['content'] ?? array() ) );

		if ( '' === $text ) {
			// A thinking-enabled model can burn the whole output budget on
			// thinking and stop before emitting any text — surface that as
			// truncation (actionable) rather than a generic empty response.
			if ( 'max_tokens' === $this->last_stop_reason ) {
				return new WP_Error(
					'swps_response_truncated',
					sprintf(
						/* translators: %d: max_tokens budget for the request */
						__( 'Claude used the entire %d-token response budget before producing any text (newer models spend part of the budget on internal reasoning). Try again or increase the token budget.', 'stratawp-seo' ),
						$max_tokens
					)
				);
			}
			return new WP_Error( 'swps_empty_response', __( 'Received empty response from Claude.', 'stratawp-seo' ) );
		}

		// When using JSON prefill, prepend the opening brace we sent as assistant content.
		if ( $this->requesting_json && $supports_prefill ) {
			$text = '{' . $text;
		}

		return $text;
	}

	/**
	 * Fetch live text models from the Anthropic /v1/models endpoint.
	 *
	 * @return array<string, string> Model ID => display name (empty on error).
	 */
	public function fetch_remote_models(): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			'https://api.anthropic.com/v1/models?limit=100',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		return self::parse_models_response( (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Map an Anthropic /v1/models body to [ id => display_name ].
	 *
	 * @param array $body Decoded response body.
	 * @return array<string, string>
	 */
	public static function parse_models_response( array $body ): array {
		$models = array();
		foreach ( $body['data'] ?? array() as $m ) {
			if ( ! empty( $m['id'] ) ) {
				$models[ (string) $m['id'] ] = (string) ( $m['display_name'] ?? $m['id'] );
			}
		}
		return $models;
	}

	/**
	 * Run a search-grounded query via the Anthropic web_search_20250305 tool.
	 *
	 * API: POST /v1/messages with tools[{type:"web_search_20250305",name:"web_search"}].
	 * Citations surface on text content blocks as citations[]{type:"web_search_result_location",url:string}.
	 * Ref: https://platform.claude.com/docs/en/api/cli/beta/messages
	 *
	 * @param string $query      The search query / prompt.
	 * @param int    $max_tokens Maximum tokens in the response.
	 * @return array{text: string, citations: string[]}|WP_Error
	 */
	public function search_grounded( string $query, int $max_tokens = 1024 ): array|WP_Error {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'swps_no_api_key', __( 'Please enter your Anthropic API key in StrataWP SEO settings.', 'stratawp-seo' ) );
		}

		$body = array(
			'model'      => $this->get_validated_model(),
			'max_tokens' => $max_tokens,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $query,
				),
			),
			'tools'      => array(
				array(
					'type'     => 'web_search_20250305',
					'name'     => 'web_search',
					'max_uses' => 3,
				),
			),
		);

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 180,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'swps_api_request_failed',
				/* translators: %s: error message */
				sprintf( __( 'API request failed: %s', 'stratawp-seo' ), $response->get_error_message() )
			);
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			$error_message = $response_body['error']['message'] ?? __( 'Unknown API error.', 'stratawp-seo' );
			$error_type    = $response_body['error']['type'] ?? '';
			// 4xx indicating tool/search unsupported for this model.
			if ( $status_code >= 400 && $status_code < 500 &&
				( false !== strpos( $error_message, 'web_search' ) || false !== strpos( $error_type, 'invalid_request' ) ) ) {
				return new WP_Error(
					'swps_search_unsupported',
					/* translators: 1: HTTP status code, 2: error message */
					sprintf( __( 'Claude API error (%1$d): %2$s', 'stratawp-seo' ), $status_code, $error_message ),
					array( 'status' => (int) $status_code )
				);
			}
			return new WP_Error(
				'swps_api_error',
				/* translators: 1: HTTP status code, 2: error message */
				sprintf( __( 'Claude API error (%1$d): %2$s', 'stratawp-seo' ), $status_code, $error_message ),
				array( 'status' => (int) $status_code )
			);
		}

		// Collect text from all text content blocks; gather citation URLs.
		$text_parts = array();
		$urls       = array();
		foreach ( $response_body['content'] ?? array() as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text_parts[] = (string) ( $block['text'] ?? '' );
				foreach ( $block['citations'] ?? array() as $citation ) {
					if ( 'web_search_result_location' === ( $citation['type'] ?? '' ) && ! empty( $citation['url'] ) ) {
						$urls[] = (string) $citation['url'];
					}
				}
			}
		}

		$text = implode( '', $text_parts );
		if ( '' === $text ) {
			return new WP_Error( 'swps_empty_response', __( 'Received empty response from Claude.', 'stratawp-seo' ) );
		}

		return array(
			'text'      => $text,
			'citations' => array_values( array_unique( $urls ) ),
		);
	}

	public function test_key( string $api_key ): bool|WP_Error {
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $this->get_validated_model(),
						'max_tokens' => 10,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Say "ok"',
							),
						),
					)
				),
			)
		);

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
