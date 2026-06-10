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
		return array(
			'gpt-4.1'      => 'GPT-4.1',
			'gpt-4.1-mini' => 'GPT-4.1 Mini',
			'gpt-4.1-nano' => 'GPT-4.1 Nano',
			'gpt-4o'       => 'GPT-4o',
			'gpt-4o-mini'  => 'GPT-4o Mini',
			'o3-mini'      => 'o3-mini',
		);
	}

	public function chat( string $system_prompt, string $user_message, int $max_tokens = 4096 ): string|WP_Error {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'swps_no_api_key', __( 'Please enter your OpenAI API key in StrataWP SEO settings.', 'stratawp-seo' ) );
		}

		$body = array(
			'model'      => $this->get_validated_model(),
			'max_tokens' => $max_tokens,
			'messages'   => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_message,
				),
			),
		);

		if ( $this->requesting_json ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 180,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
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
				sprintf( __( 'OpenAI API error (%1$d): %2$s', 'stratawp-seo' ), $status_code, $error_message ),
				array( 'status' => (int) $status_code )
			);
		}

		if ( empty( $body['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'swps_empty_response', __( 'Received empty response from OpenAI.', 'stratawp-seo' ) );
		}

		// Store usage data for cost tracking.
		if ( ! empty( $body['usage'] ) ) {
			$this->last_usage = array(
				'input_tokens'  => (int) ( $body['usage']['prompt_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $body['usage']['completion_tokens'] ?? 0 ),
			);
		}

		// Store stop reason for truncation detection.
		// OpenAI: 'stop', 'length' (truncated), 'content_filter'.
		$finish_reason          = $body['choices'][0]['finish_reason'] ?? null;
		$this->last_stop_reason = ( $finish_reason === 'length' ) ? 'max_tokens' : $finish_reason;

		return $body['choices'][0]['message']['content'];
	}

	/**
	 * Fetch live models from the OpenAI /v1/models endpoint.
	 *
	 * @return array<string, string> Model ID => display name (empty on error).
	 */
	public function fetch_remote_models(): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		return self::parse_models_response( (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Keep chat-capable models only (gpt-* / o-series), excluding embeddings,
	 * audio, image, realtime, and moderation models.
	 *
	 * @param array $body Decoded response body.
	 * @return array<string, string>
	 */
	public static function parse_models_response( array $body ): array {
		$models = array();
		foreach ( $body['data'] ?? array() as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$is_chat = (bool) preg_match( '/^(gpt-|o\d|chatgpt-)/', $id );
			$exclude = (bool) preg_match( '/(embedding|whisper|tts|dall-e|realtime|moderation|audio|image|transcribe|search|sora)/', $id );
			if ( $is_chat && ! $exclude ) {
				$models[ $id ] = $id;
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
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
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
