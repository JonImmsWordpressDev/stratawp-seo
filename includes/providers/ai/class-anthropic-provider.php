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
			'claude-opus-4-7'            => 'Claude Opus 4.7',
			'claude-opus-4-6'            => 'Claude Opus 4.6',
			'claude-sonnet-4-6'          => 'Claude Sonnet 4.6',
			'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5',
			'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5',
		);
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
		// Note: Claude 4.6+ models (opus-4-6, sonnet-4-6, and newer 4-7+) do not support assistant prefill.
		$model            = $this->get_validated_model();
		$supports_prefill = ! preg_match( '/-(4-6|4-7|4-8|4-9|5-)/', $model );

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

		if ( empty( $body['content'][0]['text'] ) ) {
			return new WP_Error( 'swps_empty_response', __( 'Received empty response from Claude.', 'stratawp-seo' ) );
		}

		// Store usage data for cost tracking.
		if ( ! empty( $body['usage'] ) ) {
			$this->last_usage = array(
				'input_tokens'  => (int) ( $body['usage']['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ),
			);
		}

		// Store stop reason for truncation detection.
		// Anthropic: 'end_turn', 'max_tokens', 'stop_sequence'.
		$this->last_stop_reason = $body['stop_reason'] ?? null;

		$text = $body['content'][0]['text'];

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
