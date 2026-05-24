<?php
/**
 * Pure-PHP stand-in for SWPS_AI_Provider used by AEO unit tests.
 *
 * Implements only what the AEO scorers call: chat_json(array, int): array.
 * Configurable response (next_response) + failure mode (should_fail) +
 * captured input (last_messages) for assertions.
 *
 * @package StrataWP_SEO
 */

final class FakeAIProvider {
	/** @var array<string, mixed> */
	public array $next_response = array();
	public bool $should_fail = false;
	/** @var array<int, array{role:string, content:string}> */
	public array $last_messages = array();

	/**
	 * @param array<int, array{role:string, content:string}> $messages
	 * @return array<string, mixed>
	 */
	public function chat_json( array $messages, int $max_tokens = 1024 ): array {
		$this->last_messages = $messages;
		if ( $this->should_fail ) {
			throw new RuntimeException( 'AI provider error' );
		}
		return $this->next_response;
	}
}
