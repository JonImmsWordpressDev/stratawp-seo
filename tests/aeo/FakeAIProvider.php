<?php
/**
 * Pure-PHP stand-in for SWPS_AI_Provider used by AEO unit tests.
 *
 * Matches the real SWPS_AI_Provider::chat_json(string, string, int): array|WP_Error
 * signature. Configurable response (next_response) + failure mode (should_fail) +
 * captured prompts (last_system / last_user) for assertions.
 *
 * @package StrataWP_SEO
 */

final class FakeAIProvider {
	/** @var array<string, mixed>|WP_Error */
	public $next_response = array();
	public bool $should_fail = false;
	public string $last_system = '';
	public string $last_user   = '';

	/**
	 * @param string $system_prompt
	 * @param string $user_message
	 * @param int    $max_tokens
	 * @return array<string, mixed>|WP_Error
	 */
	public function chat_json( string $system_prompt, string $user_message, int $max_tokens = 4096 ) {
		$this->last_system = $system_prompt;
		$this->last_user   = $user_message;
		if ( $this->should_fail ) {
			return new WP_Error( 'ai_failure', 'AI provider error' );
		}
		return $this->next_response;
	}
}
