<?php
/**
 * Topic Scout — pure signal-ranking and prompt-building helpers.
 *
 * Provides three pure static methods (no WP dependencies, fully unit-testable):
 *   - rank_signals()       — score and rank raw signals into a unified list.
 *   - build_scout_prompt() — format the AI prompt payload from ranked signals.
 *   - normalize_proposal() — validate/normalise one AI proposal row.
 *
 * Runtime wiring (cron, AJAX, settings) lives in SWPS_Topic_Scout_Cron.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWPS_Topic_Scout
 *
 * Pure helpers for the Data-Driven Topic Autopilot feature.
 */
class SWPS_Topic_Scout {

	/**
	 * Score and rank raw signals into a unified candidate list.
	 *
	 * Scoring weights:
	 *   question:  impressions (raw)
	 *   striking:  impressions × (21 − position) / 13   (position 8-20)
	 *   orphan:    fixed weight 5 (low but surfaces coverage gaps)
	 *
	 * Candidates with the same normalised text are de-duplicated (highest score kept).
	 *
	 * @param array<int,array<string,mixed>> $question_candidates Rows from swps_question_topic_candidates option: {q, impressions, …}.
	 * @param array<int,array<string,mixed>> $striking            GSC rows filtered to position 8-20: {query, impressions, position}.
	 * @param array<int,array<string,mixed>> $orphans             Orphan-page rows: {post_title, …}.
	 * @param int                            $limit               Maximum candidates to return (default 10).
	 * @return array<int,array<string,mixed>> Ranked list of {type, text, score, meta}.
	 */
	public static function rank_signals(
		array $question_candidates,
		array $striking,
		array $orphans,
		int $limit = 10
	): array {
		$pool = array();

		// Signal 1 — question candidates.
		foreach ( $question_candidates as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$text = trim( (string) ( $row['q'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$impressions = (float) ( $row['impressions'] ?? 0 );
			$pool[]      = array(
				'type'  => 'question',
				'text'  => $text,
				'score' => $impressions,
				'meta'  => array( 'impressions' => (int) $impressions ),
			);
		}

		// Signal 2 — striking-distance queries.
		foreach ( $striking as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$text = trim( (string) ( $row['query'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$impressions = (float) ( $row['impressions'] ?? 0 );
			$position    = (float) ( $row['position'] ?? 0 );
			if ( $position < 8 || $position > 20 ) {
				continue;
			}
			$score  = $impressions * ( 21 - $position ) / 13;
			$pool[] = array(
				'type'  => 'striking',
				'text'  => $text,
				'score' => $score,
				'meta'  => array(
					'impressions' => (int) $impressions,
					'position'    => round( $position, 1 ),
				),
			);
		}

		// Signal 3 — orphan pages.
		foreach ( $orphans as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$text = trim( (string) ( $row['post_title'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$pool[] = array(
				'type'  => 'orphan',
				'text'  => $text,
				'score' => 5.0,
				'meta'  => array(),
			);
		}

		// De-duplicate by normalised text (highest score wins).
		$seen   = array();
		$unique = array();
		foreach ( $pool as $item ) {
			$key = self::normalize_text( $item['text'] );
			if ( ! isset( $seen[ $key ] ) || $item['score'] > $seen[ $key ]['score'] ) {
				$seen[ $key ]   = $item;
				$unique[ $key ] = $item;
			}
		}

		// Sort descending by score.
		$ranked = array_values( $unique );
		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $ranked, 0, max( 1, $limit ) );
	}

	/**
	 * Group per-page GSC rows by query: SUM impressions, AVERAGE position.
	 *
	 * Pure aggregation — no WP dependencies. A query flagged 'covered' on ANY
	 * of its rows (it already has a dedicated post) is excluded entirely. The
	 * 8-20 striking-distance window is applied to the AVERAGED position.
	 *
	 * @param array<int,array<string,mixed>> $rows Per-page rows: {query, impressions, position, covered?}.
	 * @return array<int,array<string,mixed>> Grouped rows of {query, impressions, position}.
	 */
	public static function group_striking_rows( array $rows ): array {
		$by_query = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$query = trim( (string) ( $row['query'] ?? '' ) );
			if ( '' === $query ) {
				continue;
			}

			if ( ! isset( $by_query[ $query ] ) ) {
				$by_query[ $query ] = array(
					'impressions'  => 0,
					'position_sum' => 0.0,
					'count'        => 0,
					'covered'      => false,
				);
			}

			if ( ! empty( $row['covered'] ) ) {
				$by_query[ $query ]['covered'] = true;
				continue;
			}

			$by_query[ $query ]['impressions']  += (int) ( $row['impressions'] ?? 0 );
			$by_query[ $query ]['position_sum'] += (float) ( $row['position'] ?? 0 );
			++$by_query[ $query ]['count'];
		}

		$out = array();
		foreach ( $by_query as $query => $agg ) {
			if ( $agg['covered'] || 0 === $agg['count'] ) {
				continue;
			}
			$avg_position = $agg['position_sum'] / $agg['count'];
			if ( $avg_position < 8 || $avg_position > 20 ) {
				continue;
			}
			$out[] = array(
				'query'       => (string) $query,
				'impressions' => $agg['impressions'],
				'position'    => $avg_position,
			);
		}

		return $out;
	}

	/**
	 * Build the AI prompt payload from ranked signals and site context.
	 *
	 * Pure formatting — no external calls.
	 *
	 * @param array<int,array<string,mixed>> $ranked       Output of rank_signals().
	 * @param string                         $site_context Brief site description / niche.
	 * @return string Prompt string for the AI scout call.
	 */
	public static function build_scout_prompt( array $ranked, string $site_context ): string {
		$site_context = trim( $site_context );
		$intro        = 'You are an SEO content strategist. Analyse the following search signals for a website'
			. ( '' !== $site_context ? ' in the niche: ' . $site_context : '' )
			. '.';

		$lines = array();
		foreach ( $ranked as $i => $item ) {
			$label = match ( (string) ( $item['type'] ?? '' ) ) {
				'question' => 'Unanswered question query',
				'striking' => 'Striking-distance query',
				'orphan'   => 'Orphan page (no inbound links)',
				default    => 'Signal',
			};
			$meta_parts = array();
			if ( isset( $item['meta']['impressions'] ) ) {
				$meta_parts[] = (int) $item['meta']['impressions'] . ' impressions';
			}
			if ( isset( $item['meta']['position'] ) ) {
				$meta_parts[] = 'position ' . $item['meta']['position'];
			}
			$meta_str = empty( $meta_parts ) ? '' : ' (' . implode( ', ', $meta_parts ) . ')';
			$lines[]  = sprintf( '%d. [%s] %s%s', $i + 1, $label, $item['text'], $meta_str );
		}

		$signals_block = implode( "\n", $lines );

		return $intro . "\n\n"
			. "Signals:\n" . $signals_block . "\n\n"
			. "Return a JSON array of up to 5 topic proposals. Each proposal must have:\n"
			. "- title: compelling blog post title (≤120 chars)\n"
			. "- keyword: primary target keyword\n"
			. "- template: one of auto|listicle|how-to|comparison|faq|review|roundup\n"
			. "- rationale: one sentence citing the specific signal (impressions, position, or orphan status)\n\n"
			. 'Return ONLY a valid JSON array. No markdown, no explanation.';
	}

	/**
	 * Validate and normalise one AI proposal row.
	 *
	 * Rules:
	 *   - title required, trimmed, ≤120 chars
	 *   - rationale required, non-empty after trim
	 *   - template whitelisted against $templates; falls back to 'auto' if missing/unknown
	 *   - keyword optional; trimmed to '' if absent
	 *
	 * Returns null for completely junk rows (no title or no rationale).
	 *
	 * @param array<string,mixed> $row       Raw AI proposal row.
	 * @param string[]            $templates Whitelist of valid template slugs.
	 * @return array<string,mixed>|null Normalised proposal or null on junk.
	 */
	public static function normalize_proposal( array $row, array $templates ): ?array {
		$title     = trim( (string) ( $row['title'] ?? '' ) );
		$rationale = trim( (string) ( $row['rationale'] ?? '' ) );

		if ( '' === $title || '' === $rationale ) {
			return null;
		}

		// Truncate over-length titles.
		if ( strlen( $title ) > 120 ) {
			$title = substr( $title, 0, 120 );
		}

		$template = trim( (string) ( $row['template'] ?? 'auto' ) );
		if ( '' === $template || ! in_array( $template, $templates, true ) ) {
			$template = 'auto';
		}

		$keyword = trim( (string) ( $row['keyword'] ?? '' ) );

		return array(
			'title'     => $title,
			'keyword'   => $keyword,
			'template'  => $template,
			'rationale' => $rationale,
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalise a text string for de-duplication comparison.
	 *
	 * Lowercases, strips punctuation, collapses whitespace.
	 *
	 * @param string $text Input string.
	 * @return string Normalised string.
	 */
	private static function normalize_text( string $text ): string {
		$text = strtolower( $text );
		$text = (string) preg_replace( '/[^a-z0-9\s]/', '', $text );
		$text = (string) preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}
}
