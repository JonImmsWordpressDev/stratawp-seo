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
		return array(
			'gemini-2.5-pro'        => 'Gemini 2.5 Pro',
			'gemini-2.5-flash'      => 'Gemini 2.5 Flash',
			'gemini-2.0-flash'      => 'Gemini 2.0 Flash',
			'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash Lite',
		);
	}

	public function chat( string $system_prompt, string $user_message, int $max_tokens = 4096 ): string|WP_Error {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'swps_no_api_key', __( 'Please enter your Google API key in StrataWP SEO settings.', 'stratawp-seo' ) );
		}

		$model = $this->get_validated_model();
		$url   = self::API_BASE . $model . ':generateContent?key=' . $api_key;

		$body = array(
			'system_instruction' => array(
				'parts' => array(
					array( 'text' => $system_prompt ),
				),
			),
			'contents'           => array(
				array(
					'parts' => array(
						array( 'text' => $user_message ),
					),
				),
			),
			'generationConfig'   => array(
				'maxOutputTokens'  => $max_tokens,
				'responseMimeType' => $this->requesting_json ? 'application/json' : 'text/plain',
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 180,
				'headers' => array(
					'Content-Type' => 'application/json',
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
				sprintf( __( 'Gemini API error (%1$d): %2$s', 'stratawp-seo' ), $status_code, $error_message ),
				array( 'status' => (int) $status_code )
			);
		}

		if ( empty( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error( 'swps_empty_response', __( 'Received empty response from Gemini.', 'stratawp-seo' ) );
		}

		// Store usage data for cost tracking.
		if ( ! empty( $body['usageMetadata'] ) ) {
			$this->last_usage = array(
				'input_tokens'  => (int) ( $body['usageMetadata']['promptTokenCount'] ?? 0 ),
				'output_tokens' => (int) ( $body['usageMetadata']['candidatesTokenCount'] ?? 0 ),
			);
		}

		// Store stop reason for truncation detection.
		// Gemini: 'STOP', 'MAX_TOKENS', 'SAFETY', 'RECITATION'.
		$finish_reason          = $body['candidates'][0]['finishReason'] ?? null;
		$this->last_stop_reason = ( $finish_reason === 'MAX_TOKENS' ) ? 'max_tokens' : $finish_reason;

		return $body['candidates'][0]['content']['parts'][0]['text'];
	}

	/**
	 * Fetch live models from the Google /v1beta/models endpoint.
	 *
	 * @return array<string, string> Model ID => display name (empty on error).
	 */
	public function fetch_remote_models(): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200&key=' . rawurlencode( $api_key ),
			array( 'timeout' => 15 )
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		return self::parse_models_response( (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Keep text-generation models (supportedGenerationMethods contains
	 * generateContent), excluding embedding/image-only models.
	 *
	 * @param array $body Decoded response body.
	 * @return array<string, string>
	 */
	public static function parse_models_response( array $body ): array {
		$models = array();
		foreach ( $body['models'] ?? array() as $m ) {
			$name    = (string) ( $m['name'] ?? '' );
			$methods = (array) ( $m['supportedGenerationMethods'] ?? array() );
			if ( '' === $name || ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}
			$id = preg_replace( '#^models/#', '', $name );
			// Drop non-text-generation models that still report generateContent
			// (image, TTS, music, robotics, agents, etc.).
			if ( preg_match( '/(image|tts|lyria|veo|imagen|robotics|computer-use|deep-research|antigravity|nano-banana|aqa)/i', $id ) ) {
				continue;
			}
			$models[ $id ] = (string) ( $m['displayName'] ?? $id );
		}
		return $models;
	}

	public function test_key( string $api_key ): bool|WP_Error {
		$model = $this->get_validated_model();
		$url   = self::API_BASE . $model . ':generateContent?key=' . $api_key;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'contents'         => array(
							array(
								'parts' => array(
									array( 'text' => 'Say "ok"' ),
								),
							),
						),
						'generationConfig' => array(
							'maxOutputTokens' => 10,
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
