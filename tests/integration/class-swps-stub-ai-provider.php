<?php
/**
 * Scriptable AI provider for integration tests: no network, no API key.
 *
 * @package StrataWP_SEO
 */

/**
 * Returns a valid generated-post JSON payload by default; set $next to an
 * array of 'ok' | 'error' | 'throw' to script successive calls.
 */
class SWPS_Stub_AI_Provider extends SWPS_AI_Provider {

	/** @var string[] Scripted outcomes for upcoming chat() calls. */
	public array $next = array();

	/** @var int Number of chat() calls made. */
	public int $calls = 0;

	public function chat( string $system_prompt, string $user_message, int $max_tokens = 4096 ): string|WP_Error {
		++$this->calls;
		$mode = array_shift( $this->next ) ?? 'ok';

		if ( 'error' === $mode ) {
			return new WP_Error( 'stub_error', 'Stub provider failure' );
		}
		if ( 'throw' === $mode ) {
			throw new RuntimeException( 'Stub provider exception' );
		}

		$n = $this->calls;
		return (string) wp_json_encode(
			array(
				'title'            => "Stub generated post number {$n} about lighthouse keeping",
				'content_html'     => '<p>' . str_repeat( "Lighthouse keeping paragraph {$n}. ", 40 ) . '</p>',
				'meta_description' => "Stub meta description {$n}.",
				'slug'             => "stub-generated-post-{$n}",
				'focus_keyword'    => 'lighthouse keeping',
				'tags'             => array(),
			)
		);
	}

	public function test_key( string $api_key ): bool|WP_Error {
		return true;
	}

	public function get_available_models(): array {
		return array( 'stub-model' => 'Stub model' );
	}

	public function get_slug(): string {
		return 'stub';
	}

	public function get_name(): string {
		return 'Stub';
	}

	public function get_api_key_url(): string {
		return 'https://example.org/';
	}
}
