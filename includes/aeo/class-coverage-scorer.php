<?php
/**
 * Coverage Scorer — LLM-based 4th AEO sub-scorer. Evaluates topic
 * completeness (sub-topics a reader would expect that are missing) and
 * entity clarity (entities mentioned vaguely that should be named).
 *
 * Cost: 1 AI call per scored post (caller decides when to invoke).
 * The score result includes the raw `coverage_gaps` and `entity_issues`
 * lists so the Optimizer's proposal generator can use them as input
 * for find/replace suggestions.
 *
 * Provider is dependency-injected (any object with
 * `chat_json(string $system, string $user, int $max_tokens): array|WP_Error`)
 * so tests use a pure-PHP FakeAIProvider — no AI calls in CI.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Coverage_Scorer {

	/** @var object|null Anything with chat_json(string, string, int): array|WP_Error */
	private $provider;

	public function __construct( $provider = null ) {
		$this->provider = $provider;
	}

	/**
	 * Score the post for coverage and entity clarity by querying the AI provider.
	 *
	 * @param string $title Post title.
	 * @param string $html  Raw post HTML.
	 * @return array{score:?int, coverage_gaps:string[], entity_issues:string[], error:?string}
	 */
	public function score( string $title, string $html ): array {
		if ( null === $this->provider ) {
			return array(
				'score'         => null,
				'coverage_gaps' => array(),
				'entity_issues' => array(),
				'error'         => 'no_provider',
			);
		}

		$outline = $this->build_outline( $html );
		$system  = 'You evaluate how completely a blog post covers its topic for AI search citation.';
		$user    = sprintf(
			"Topic: %s\n\nOutline (H2s + opening sentence of each section):\n%s\n\n" .
			'Return JSON with these exact keys: ' .
			'{"coverage_gaps": [up to 5 sub-topics a reader would expect that are missing], ' .
			'"entity_issues": [up to 5 entities mentioned vaguely or generically that should be named explicitly], ' .
			'"score": integer 0-100 reflecting overall coverage and entity clarity}',
			$title,
			$outline
		);

		$response = $this->provider->chat_json( $system, $user, 512 );

		if ( $response instanceof WP_Error ) {
			return array(
				'score'         => null,
				'coverage_gaps' => array(),
				'entity_issues' => array(),
				'error'         => $response->get_error_message(),
			);
		}
		if ( ! is_array( $response ) ) {
			return array(
				'score'         => null,
				'coverage_gaps' => array(),
				'entity_issues' => array(),
				'error'         => 'invalid_response',
			);
		}

		$raw_score = $response['score'] ?? null;
		$score     = null;
		if ( is_int( $raw_score ) || ( is_string( $raw_score ) && ctype_digit( $raw_score ) ) ) {
			$score = max( 0, min( 100, (int) $raw_score ) );
		}

		return array(
			'score'         => $score,
			'coverage_gaps' => array_values( array_filter( (array) ( $response['coverage_gaps'] ?? array() ), 'is_string' ) ),
			'entity_issues' => array_values( array_filter( (array) ( $response['entity_issues'] ?? array() ), 'is_string' ) ),
			'error'         => null,
		);
	}

	/**
	 * Build a compact outline string (H2s + first sentence of each section).
	 *
	 * @param string $html Raw post HTML.
	 * @return string Outline lines joined by newlines.
	 */
	public function build_outline( string $html ): string {
		$lines = array();
		if ( preg_match_all( '#<h2[^>]*>(.*?)</h2>(.*?)(?=<h2|$)#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$h2   = trim( wp_strip_all_tags( $match[1] ) );
				$body = trim( wp_strip_all_tags( $match[2] ) );
				$first = '';
				if ( preg_match( '/^([^.?!]*[.?!])/u', $body, $sm ) ) {
					$first = trim( $sm[1] );
				}
				$lines[] = '## ' . $h2;
				if ( '' !== $first ) {
					$lines[] = $first;
				}
			}
		}
		if ( empty( $lines ) ) {
			$lines[] = trim( wp_strip_all_tags( substr( $html, 0, 1200 ) ) );
		}
		return implode( "\n", $lines );
	}
}
