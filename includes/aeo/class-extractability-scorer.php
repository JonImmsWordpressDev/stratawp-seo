<?php
/**
 * Extractability Scorer — rates how easily AI search engines can extract,
 * quote, and cite content from a post.
 *
 * Scoring weights (sum to 1.0):
 *   - self_contained_paragraph_rate : 0.30
 *   - declarative_ratio             : 0.30
 *   - structural_density (cap 4/kw) : 0.25
 *   - has_definitional_lead         : 0.15
 *
 * Pure HTML/string analysis — no WP runtime required.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Extractability_Scorer {

	/** Hedge phrases that lower the declarative-ratio score. */
	private const HEDGES = array(
		'may be', 'might be', 'could be', 'perhaps', 'maybe',
		'we think', 'i think', 'in our opinion', 'arguably',
		'somewhat', 'sort of', 'kind of', 'probably', 'possibly',
	);

	/**
	 * Score the post HTML for AEO extractability.
	 *
	 * @param string $html Raw post HTML.
	 * @return int 0-100.
	 */
	public function score( string $html ): int {
		$html = trim( $html );
		if ( '' === $html ) {
			return 0;
		}
		$text       = trim( wp_strip_all_tags( $html ) );
		$word_count = str_word_count( $text );
		if ( $word_count < 20 ) {
			return 0;
		}
		$self_contained = $this->self_contained_paragraph_rate( $html );
		$declarative    = $this->declarative_ratio( $html );
		$structural     = min( 1.0, $this->structural_density( $html, $word_count ) / 4 );
		$definitional   = $this->has_definitional_lead( $html ) ? 1.0 : 0.0;

		$score = ( $self_contained * 0.30
				+ $declarative    * 0.30
				+ $structural     * 0.25
				+ $definitional   * 0.15 ) * 100;

		return (int) round( max( 0, min( 100, $score ) ) );
	}

	/**
	 * Rate of <p> blocks whose first sentence opens with a noun phrase
	 * (not a pronoun/conjunction/hedge).
	 *
	 * @param string $html Raw post HTML.
	 * @return float 0.0-1.0.
	 */
	public function self_contained_paragraph_rate( string $html ): float {
		if ( ! preg_match_all( '#<p[^>]*>(.*?)</p>#is', $html, $matches ) ) {
			return 0.0;
		}
		$opens_bad = array(
			'i ', 'we ', 'they ', 'it ', 'this ', 'that ', 'these ', 'those ',
			'so ', 'but ', 'and ', 'or ', 'however ', 'anyway ', 'meanwhile ',
			'well ', 'now ', 'okay ', 'right ', 'um ', 'uh ',
		);
		$good  = 0;
		$total = count( $matches[1] );
		foreach ( $matches[1] as $p ) {
			$first = strtolower( ltrim( wp_strip_all_tags( $p ) ) );
			if ( '' === $first ) {
				--$total;
				continue;
			}
			// Extract the first word (letters and apostrophes only).
			$first_word = '';
			if ( preg_match( '/^([a-z\']+)/', $first, $word_match ) ) {
				$first_word = $word_match[1];
			}
			$bad = false;
			foreach ( $opens_bad as $w ) {
				$w_trim = rtrim( $w ); // strip trailing space.
				if ( $first_word === $w_trim ) {
					$bad = true;
					break;
				}
				// Also catch contractions: "we" matches "we've", "we're", "we'll", etc.
				if ( str_starts_with( $first_word, $w_trim . "'" ) ) {
					$bad = true;
					break;
				}
			}
			if ( ! $bad ) {
				++$good;
			}
		}
		return $total > 0 ? $good / $total : 0.0;
	}

	/**
	 * % of sentences ending in '.' (not '?' or hedge patterns).
	 *
	 * @param string $html Raw post HTML.
	 * @return float 0.0-1.0.
	 */
	public function declarative_ratio( string $html ): float {
		$text      = trim( wp_strip_all_tags( $html ) );
		$sentences = preg_split( '/(?<=[.?!])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $sentences ) ) {
			return 0.0;
		}
		$declarative = 0;
		foreach ( $sentences as $s ) {
			$s = strtolower( trim( $s ) );
			if ( '' === $s || ! str_ends_with( $s, '.' ) ) {
				continue;
			}
			$has_hedge = false;
			foreach ( self::HEDGES as $h ) {
				if ( false !== strpos( $s, $h ) ) {
					$has_hedge = true;
					break;
				}
			}
			if ( ! $has_hedge ) {
				++$declarative;
			}
		}
		return $declarative / count( $sentences );
	}

	/**
	 * Count of <ul>/<ol>/<table>/<dl>/<blockquote> normalized per 1000 words.
	 *
	 * @param string $html       Raw post HTML.
	 * @param int    $word_count Word count of stripped text.
	 * @return float Density per 1000 words.
	 */
	public function structural_density( string $html, int $word_count ): float {
		if ( $word_count <= 0 ) {
			return 0.0;
		}
		$count = preg_match_all( '#<(ul|ol|table|dl|blockquote)[\s>]#i', $html );
		return ( $count / max( 1, $word_count ) ) * 1000;
	}

	/**
	 * Does the first paragraph match "X is/are Y" or "X refers to Y" / "X means Y"?
	 *
	 * @param string $html Raw post HTML.
	 * @return bool
	 */
	public function has_definitional_lead( string $html ): bool {
		if ( ! preg_match( '#<p[^>]*>(.*?)</p>#is', $html, $m ) ) {
			return false;
		}
		$first = trim( wp_strip_all_tags( $m[1] ) );
		return (bool) preg_match( '/^[A-Z][^.?!]{1,80}\s+(is|are|refers to|means)\s+[a-z]/u', $first );
	}
}
