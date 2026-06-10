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
	 * The prompt asks for a fan-out set of sub-queries an answer engine would
	 * derive from the topic, each classified as answered/partial/missing based
	 * on the heading outline.  The response shape is extended:
	 *   - sub_queries : [{"q": "...", "status": "answered|partial|missing"}, ...]
	 *   - coverage_gaps (BC) : q-strings for missing sub-queries only
	 *   - entity_issues : vague entity mentions as before
	 *   - score : 0-100
	 *
	 * Context uses heading outline (H2/H3) + optional focus keyword instead of
	 * full HTML, keeping token usage comparable to the previous prompt.
	 *
	 * @param string $title         Post title.
	 * @param string $html          Raw post HTML.
	 * @param string $focus_keyword Optional focus keyword for extra context.
	 * @return array{score:?int, sub_queries:array<int,array{q:string,status:string}>, coverage_gaps:string[], entity_issues:string[], error:?string}
	 */
	public function score( string $title, string $html, string $focus_keyword = '' ): array {
		if ( null === $this->provider ) {
			return array(
				'score'         => null,
				'sub_queries'   => array(),
				'coverage_gaps' => array(),
				'entity_issues' => array(),
				'error'         => 'no_provider',
			);
		}

		$outline = $this->build_outline( $html );
		$kw_line = '' !== $focus_keyword ? "\nFocus keyword: {$focus_keyword}" : '';
		$system  = 'You evaluate how completely a blog post covers its topic for AI search citation.';
		$user    = sprintf(
			"Topic: %s%s\n\nHeading outline:\n%s\n\n" .
			'Fan out 5-10 sub-questions an answer engine would ask about this topic. ' .
			'For each, judge whether the outline answers it fully (answered), partially (partial), or not at all (missing). ' .
			'Return JSON with exactly these keys: ' .
			'{"sub_queries": [{"q": "...", "status": "answered|partial|missing"}], ' .
			'"entity_issues": [up to 5 entities mentioned vaguely that should be named explicitly], ' .
			'"score": integer 0-100 reflecting overall coverage}',
			$title,
			$kw_line,
			$outline
		);

		$response = $this->provider->chat_json( $system, $user, 600 );

		if ( $response instanceof WP_Error ) {
			return array(
				'score'         => null,
				'sub_queries'   => array(),
				'coverage_gaps' => array(),
				'entity_issues' => array(),
				'error'         => $response->get_error_message(),
			);
		}
		if ( ! is_array( $response ) ) {
			return array(
				'score'         => null,
				'sub_queries'   => array(),
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

		// Normalise sub_queries.
		$valid_statuses = array( 'answered', 'partial', 'missing' );
		$sub_queries    = array();
		foreach ( (array) ( $response['sub_queries'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q      = trim( (string) ( $item['q'] ?? '' ) );
			$status = (string) ( $item['status'] ?? '' );
			if ( '' === $q || ! in_array( $status, $valid_statuses, true ) ) {
				continue;
			}
			$sub_queries[] = array(
				'q'      => $q,
				'status' => $status,
			);
		}

		// BC: coverage_gaps = missing sub-queries' q strings.
		$coverage_gaps = array();
		foreach ( $sub_queries as $item ) {
			if ( 'missing' === $item['status'] ) {
				$coverage_gaps[] = $item['q'];
			}
		}

		return array(
			'score'         => $score,
			'sub_queries'   => $sub_queries,
			'coverage_gaps' => $coverage_gaps,
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
				$lines[] = '## ' . $h2;

				// H3 sub-headings within this section keep nested structure visible.
				if ( preg_match_all( '#<h3[^>]*>(.*?)</h3>#is', $match[2], $h3m ) ) {
					foreach ( $h3m[1] as $h3 ) {
						$lines[] = '### ' . trim( wp_strip_all_tags( $h3 ) );
					}
				}

				$body  = trim( wp_strip_all_tags( $match[2] ) );
				$first = '';
				if ( preg_match( '/^([^.?!]*[.?!])/u', $body, $sm ) ) {
					$first = trim( $sm[1] );
				}
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
