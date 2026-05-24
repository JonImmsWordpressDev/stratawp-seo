<?php
/**
 * Markup Scorer — rates how well content is marked up for AI extraction:
 * explicit questions paired with direct answers, and the presence of the
 * RIGHT schema type for the post's content.
 *
 * Scoring components (sum to 100):
 *   - Question count       : 0-40 pts (linear to 5+ questions)
 *   - Q&A pairing rate     : 0-30 pts (% of questions followed by short answer)
 *   - Schema-type alignment: 0-30 pts (correct type present? full marks. wrong/missing? 5 pts)
 *   - Penalty              : -10 pts if 3+ questions and no FAQPage schema (and not recipe)
 *
 * The infer_schema_type() helper is also used by the Schema Generator (Task 11)
 * to choose a target type when generating dynamic JSON-LD.
 *
 * Pure HTML/string analysis — no WP runtime required.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Markup_Scorer {

	/**
	 * Per-type regex signals. The class infers a schema type from content
	 * patterns. A type is considered a match when at least 2 of its signals
	 * fire (avoids false positives from a single weak signal).
	 *
	 * Note: 'qapage' has no patterns here — it's inferred separately from
	 * the title (ends in '?') or H2 question density (>=80% question H2s).
	 */
	private const TYPE_SIGNALS = array(
		'recipe' => array(
			'/\bingredients?\b/i',
			'/\b(prep|cook|total)\s*time\b/i',
			'/\b(servings|yield)\b/i',
			'/\b(bake|simmer|whisk|fold in|stir)\b/i',
		),
		'howto' => array(
			'/\bhow to\b/i',
			'/\bstep\s*\d+\b/i',
			'/<ol[^>]*>(?:[^<]|<(?!\/ol>))*<li/is',
		),
		'product' => array(
			'/\$\s?\d{1,5}(?:\.\d{2})?\b/',
			'/\b(brand|sku|model|specifications?)\b/i',
			'/\bbuy now\b/i',
		),
		'review' => array(
			'/\b(review of|review:)\b/i',
			'/\bpros\b.*\bcons\b/is',
			'/\b\d(?:\.\d)?\s*\/\s*5\b/',
			'/★{3,5}/u',
		),
		'qapage' => array(),
	);

	/**
	 * Score the post HTML for AEO Markup (Q&A density + schema-type alignment).
	 *
	 * @param string                $html    Raw post HTML.
	 * @param array<string, mixed>  $context [
	 *     'existing_schema' => string|null,
	 *     'title'           => string,
	 * ]
	 * @return int 0-100.
	 */
	public function score( string $html, array $context ): int {
		$q_count       = $this->count_questions( $html );
		$pair_rate     = $this->qa_pair_rate( $html );
		$expected_type = $this->infer_schema_type( $html, $context['title'] ?? '' );
		$existing      = $context['existing_schema'] ?? null;

		// 40 pts: question density (0-5+ questions linear).
		$q_score = min( 1.0, $q_count / 5 ) * 40;

		// 30 pts: directly-followed-by-answer rate.
		$a_score = $pair_rate * 30;

		// 30 pts: schema-type alignment.
		// Only penalise when an explicit schema is declared and it's the wrong type.
		// No declared schema → full marks (we score what we know about the content structure,
		// not the absence of metadata the scorer wasn't given).
		$s_score = 30;
		if ( null !== $expected_type && null !== $existing ) {
			if ( strtolower( $existing ) !== $expected_type ) {
				$s_score = 5;
			}
		}

		// Additional penalty for Q&A-heavy posts with an explicit non-FAQ schema (and not recipe).
		if ( $q_count >= 3 && 'FAQPage' !== $existing && 'recipe' !== $expected_type ) {
			$s_score = max( 0, $s_score - 10 );
		}

		return (int) round( max( 0, min( 100, $q_score + $a_score + $s_score ) ) );
	}

	/**
	 * Count explicit questions in headings (h2-h6) + body <p>/<li> ending in '?'.
	 *
	 * @param string $html Raw post HTML.
	 * @return int
	 */
	public function count_questions( string $html ): int {
		$headings = preg_match_all( '#<h[2-6][^>]*>([^<]*\?)\s*</h[2-6]>#i', $html );
		$body     = preg_match_all( '#(?:<p[^>]*>|<li[^>]*>)([^<]*\?)\s*(?:</p>|</li>)#i', $html );
		return (int) ( $headings + $body );
	}

	/**
	 * For each question heading (h2-h6), check whether the next paragraph is
	 * a short (<150 words) declarative answer. Returns fraction (0-1).
	 *
	 * @param string $html Raw post HTML.
	 * @return float
	 */
	public function qa_pair_rate( string $html ): float {
		if ( ! preg_match_all( '#<h[2-6][^>]*>([^<]*\?)\s*</h[2-6]>(.*?)(?=<h[2-6]|$)#is', $html, $matches ) ) {
			return 0.0;
		}
		$total = count( $matches[0] );
		if ( 0 === $total ) {
			return 0.0;
		}
		$with_answer = 0;
		foreach ( $matches[2] as $following ) {
			if ( ! preg_match( '#<p[^>]*>(.*?)</p>#is', $following, $p ) ) {
				continue;
			}
			$text = trim( wp_strip_all_tags( $p[1] ) );
			if ( '' === $text ) {
				continue;
			}
			if ( str_word_count( $text ) < 150 && '?' !== substr( $text, -1 ) ) {
				++$with_answer;
			}
		}
		return $with_answer / $total;
	}

	/**
	 * Infer the most likely schema type from content patterns + title.
	 *
	 * @param string $html  Raw post HTML.
	 * @param string $title Post title.
	 * @return string|null  One of: 'howto', 'recipe', 'product', 'review', 'qapage', or null.
	 */
	public function infer_schema_type( string $html, string $title ): ?string {
		$title    = strtolower( $title );
		$haystack = $title . ' ' . $html;

		// QAPage: title ends in '?' OR >=80% of H2s are questions.
		if ( str_ends_with( trim( $title ), '?' ) ) {
			return 'qapage';
		}
		if ( preg_match_all( '#<h2[^>]*>([^<]+)</h2>#i', $html, $h2s ) ) {
			$total_h2 = count( $h2s[1] );
			if ( $total_h2 > 0 ) {
				$q_h2 = 0;
				foreach ( $h2s[1] as $h ) {
					if ( str_ends_with( trim( $h ), '?' ) ) {
						++$q_h2;
					}
				}
				if ( $q_h2 / $total_h2 >= 0.8 ) {
					return 'qapage';
				}
			}
		}

		// Score remaining types by signal hits; require >=2 for a match.
		$scores = array();
		foreach ( self::TYPE_SIGNALS as $slug => $patterns ) {
			if ( 'qapage' === $slug ) {
				continue;
			}
			$hits = 0;
			foreach ( $patterns as $pat ) {
				if ( preg_match( $pat, $haystack ) ) {
					++$hits;
				}
			}
			if ( $hits >= 2 ) {
				$scores[ $slug ] = $hits;
			}
		}

		if ( empty( $scores ) ) {
			return null;
		}
		arsort( $scores );
		return (string) array_key_first( $scores );
	}
}
