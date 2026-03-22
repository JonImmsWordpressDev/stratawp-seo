<?php
/**
 * Content quality scoring for AI-generated posts.
 *
 * Analyzes generated content across 7 dimensions (keyword density, readability,
 * heading structure, meta quality, internal links, content depth, engagement)
 * and produces a weighted overall score with actionable recommendations.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Content_Scorer {

	/**
	 * Default weights for each scoring dimension (must sum to 100).
	 */
	public const DEFAULT_WEIGHTS = [
		'keyword_density'   => 15,
		'readability'       => 20,
		'heading_structure' => 15,
		'meta_quality'      => 15,
		'internal_links'    => 10,
		'content_depth'     => 15,
		'engagement'        => 10,
	];

	/**
	 * Score a generated post across all quality dimensions.
	 *
	 * @param int   $post_id   The WordPress post ID.
	 * @param array $ai_result The parsed AI response containing content and metadata.
	 * @return array {
	 *     @type int      $overall_score   Weighted score 0-100.
	 *     @type array    $details         Per-dimension scores keyed by dimension name.
	 *     @type string[] $recommendations Actionable improvement suggestions.
	 * }
	 */
	public function score( int $post_id, array $ai_result ): array {
		$content_html       = $ai_result['content_html'] ?? '';
		$title              = $ai_result['title'] ?? '';
		$meta_description   = $ai_result['meta_description'] ?? '';
		$focus_keyword      = $ai_result['focus_keyword'] ?? '';
		$secondary_keywords = $ai_result['secondary_keywords'] ?? [];
		$internal_links     = $ai_result['internal_links_used'] ?? [];
		$estimated_words    = (int) ( $ai_result['estimated_word_count'] ?? 0 );
		$post_type          = get_post_type( $post_id );

		// Note: passes $post_id as extra arg beyond the design doc's ($weights, $post_type) for per-post customization.
		$weights = apply_filters( 'swps_score_weights', self::DEFAULT_WEIGHTS, $post_id, $post_type );

		$recommendations = [];
		$plain_text      = wp_strip_all_tags( $content_html );

		$details = [
			'keyword_density'   => $this->analyze_keyword_density( $plain_text, $focus_keyword, $secondary_keywords, $recommendations ),
			'readability'       => $this->analyze_readability( $plain_text, $recommendations ),
			'heading_structure' => $this->analyze_heading_structure( $content_html, $focus_keyword, $recommendations ),
			'meta_quality'      => $this->analyze_meta_quality( $title, $meta_description, $focus_keyword, $recommendations ),
			'internal_links'    => $this->analyze_internal_links( $content_html, $recommendations ),
			'content_depth'     => $this->analyze_content_depth( $plain_text, $content_html, $estimated_words, $recommendations ),
			'engagement'        => $this->analyze_engagement( $content_html, $plain_text, $recommendations ),
		];

		$weighted_sum   = 0;
		$weight_total   = 0;

		foreach ( $details as $dimension => $dimension_score ) {
			$weight       = $weights[ $dimension ] ?? 0;
			$weighted_sum += $dimension_score * $weight;
			$weight_total += $weight;
		}

		$overall_score = ( $weight_total > 0 ) ? (int) round( $weighted_sum / $weight_total ) : 0;
		$overall_score = max( 0, min( 100, $overall_score ) );

		return [
			'overall_score'   => $overall_score,
			'details'         => $details,
			'recommendations' => $recommendations,
		];
	}

	/**
	 * Analyze keyword density for the focus keyword and secondary keywords.
	 *
	 * Computes density as (keyword occurrences / total words) * 100.
	 * Penalizes density below 0.5% or above 3%. Checks secondary keyword presence.
	 *
	 * @param string   $plain_text         Stripped content text.
	 * @param string   $focus_keyword      The primary focus keyword.
	 * @param array    $secondary_keywords List of secondary keywords.
	 * @param string[] $recs               Recommendations array (passed by reference).
	 * @return int Score 0-100.
	 */
	private function analyze_keyword_density( string $plain_text, string $focus_keyword, array $secondary_keywords, array &$recs ): int {
		if ( empty( $focus_keyword ) ) {
			$recs[] = 'No focus keyword provided. Set a focus keyword for better SEO targeting.';
			return 0;
		}

		$text_lower    = mb_strtolower( $plain_text );
		$keyword_lower = mb_strtolower( $focus_keyword );
		$word_count    = str_word_count( $plain_text );

		if ( 0 === $word_count ) {
			$recs[] = 'Content is empty. Add content to evaluate keyword density.';
			return 0;
		}

		// Count focus keyword occurrences.
		$keyword_count = mb_substr_count( $text_lower, $keyword_lower );
		$density       = ( $keyword_count / $word_count ) * 100;

		$score = 100;

		if ( $density < 0.5 ) {
			$penalty = (int) ( ( 0.5 - $density ) * 100 );
			$score  -= min( $penalty, 50 );
			$recs[]  = sprintf(
				'Focus keyword density is too low (%.1f%%). Aim for 0.5%%-3%% density.',
				$density
			);
		} elseif ( $density > 3.0 ) {
			$penalty = (int) ( ( $density - 3.0 ) * 20 );
			$score  -= min( $penalty, 50 );
			$recs[]  = sprintf(
				'Focus keyword density is too high (%.1f%%). Reduce to avoid keyword stuffing.',
				$density
			);
		}

		// Check secondary keywords.
		$missing_secondary = [];
		foreach ( $secondary_keywords as $secondary ) {
			if ( ! is_string( $secondary ) || empty( $secondary ) ) {
				continue;
			}
			if ( false === mb_strpos( $text_lower, mb_strtolower( $secondary ) ) ) {
				$missing_secondary[] = $secondary;
			}
		}

		if ( ! empty( $missing_secondary ) && ! empty( $secondary_keywords ) ) {
			$missing_ratio = count( $missing_secondary ) / count( $secondary_keywords );
			$score        -= (int) ( $missing_ratio * 30 );
			$recs[]        = sprintf(
				'Missing secondary keywords: %s.',
				implode( ', ', array_slice( $missing_secondary, 0, 5 ) )
			);
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Analyze readability using Flesch-Kincaid grade level.
	 *
	 * Target grade level: 6-10. Penalizes average sentence length over 25 words.
	 *
	 * @param string   $plain_text Stripped content text.
	 * @param string[] $recs       Recommendations array (passed by reference).
	 * @return int Score 0-100.
	 */
	private function analyze_readability( string $plain_text, array &$recs ): int {
		$word_count = str_word_count( $plain_text );

		if ( $word_count < 10 ) {
			$recs[] = 'Content is too short to assess readability.';
			return 0;
		}

		// Count sentences by splitting on sentence-ending punctuation.
		$sentences      = preg_split( '/[.!?]+/', $plain_text, -1, PREG_SPLIT_NO_EMPTY );
		$sentence_count = count( $sentences );

		if ( 0 === $sentence_count ) {
			$sentence_count = 1;
		}

		$syllable_count = $this->count_syllables( $plain_text );

		// Flesch-Kincaid Grade Level formula.
		$avg_sentence_length = $word_count / $sentence_count;
		$avg_syllables       = $syllable_count / $word_count;
		$grade_level         = ( 0.39 * $avg_sentence_length ) + ( 11.8 * $avg_syllables ) - 15.59;
		$grade_level         = max( 0, $grade_level );

		$score = 100;

		// Penalize grade level outside 6-10 range.
		if ( $grade_level < 6 ) {
			$penalty = (int) ( ( 6 - $grade_level ) * 10 );
			$score  -= min( $penalty, 30 );
			$recs[]  = sprintf(
				'Content reads at a grade %.1f level. Consider using slightly more sophisticated language.',
				$grade_level
			);
		} elseif ( $grade_level > 10 ) {
			$penalty = (int) ( ( $grade_level - 10 ) * 10 );
			$score  -= min( $penalty, 50 );
			$recs[]  = sprintf(
				'Content reads at a grade %.1f level. Simplify sentences and use shorter words for broader accessibility.',
				$grade_level
			);
		}

		// Penalize long average sentence length.
		if ( $avg_sentence_length > 25 ) {
			$penalty = (int) ( ( $avg_sentence_length - 25 ) * 5 );
			$score  -= min( $penalty, 30 );
			$recs[]  = sprintf(
				'Average sentence length is %.0f words. Aim for under 25 words per sentence.',
				$avg_sentence_length
			);
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Analyze heading structure for proper hierarchy and keyword usage.
	 *
	 * Checks for: H1 in content (bad), minimum H2 count, skipped heading levels,
	 * and focus keyword presence in headings.
	 *
	 * @param string   $content_html  The HTML content.
	 * @param string   $focus_keyword The focus keyword.
	 * @param string[] $recs          Recommendations array (passed by reference).
	 * @return int Score 0-100.
	 */
	private function analyze_heading_structure( string $content_html, string $focus_keyword, array &$recs ): int {
		// Extract all headings H1-H6.
		preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content_html, $matches );

		if ( empty( $matches[0] ) ) {
			$recs[] = 'No headings found. Add H2 and H3 headings to structure your content.';
			return 20;
		}

		$levels = array_map( 'intval', $matches[1] );
		$texts  = $matches[2];

		$score = 100;

		// Penalize H1 in content body (-20).
		$h1_count = count( array_filter( $levels, function ( $level ) {
			return 1 === $level;
		} ) );

		if ( $h1_count > 0 ) {
			$score -= 20;
			$recs[] = 'Avoid using H1 tags in post content. The post title serves as the H1.';
		}

		// Count H2s, penalize if fewer than 2 (-20).
		$h2_count = count( array_filter( $levels, function ( $level ) {
			return 2 === $level;
		} ) );

		if ( $h2_count < 2 ) {
			$score -= 20;
			$recs[] = sprintf(
				'Only %d H2 heading(s) found. Use at least 2 H2 headings to organize content sections.',
				$h2_count
			);
		}

		// Check for skipped heading levels (-10).
		$non_h1_levels = array_values( array_filter( $levels, function ( $level ) {
			return $level > 1;
		} ) );

		$has_skipped = false;
		for ( $i = 0; $i < count( $non_h1_levels ) - 1; $i++ ) {
			$current = $non_h1_levels[ $i ];
			$next    = $non_h1_levels[ $i + 1 ];
			if ( $next > $current + 1 ) {
				$has_skipped = true;
				break;
			}
		}

		if ( $has_skipped ) {
			$score -= 10;
			$recs[] = 'Heading levels are skipped (e.g., H2 to H4). Maintain a proper heading hierarchy.';
		}

		// Check focus keyword in headings (-15).
		if ( ! empty( $focus_keyword ) ) {
			$keyword_lower     = mb_strtolower( $focus_keyword );
			$keyword_in_heading = false;

			foreach ( $texts as $heading_text ) {
				$heading_plain = wp_strip_all_tags( $heading_text );
				if ( false !== mb_strpos( mb_strtolower( $heading_plain ), $keyword_lower ) ) {
					$keyword_in_heading = true;
					break;
				}
			}

			if ( ! $keyword_in_heading ) {
				$score -= 15;
				$recs[] = 'Focus keyword is missing from all headings. Include it in at least one H2 or H3.';
			}
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Analyze meta title and description quality.
	 *
	 * Checks title length (ideal 50-60 chars), meta description length
	 * (ideal 150-160 chars), and keyword presence in both.
	 *
	 * @param string   $title            The post title.
	 * @param string   $meta_description The meta description.
	 * @param string   $focus_keyword    The focus keyword.
	 * @param string[] $recs             Recommendations array (passed by reference).
	 * @return int Score 0-100.
	 */
	private function analyze_meta_quality( string $title, string $meta_description, string $focus_keyword, array &$recs ): int {
		$score        = 100;
		$title_length = mb_strlen( $title );
		$meta_length  = mb_strlen( $meta_description );

		// Title length check.
		if ( 0 === $title_length ) {
			$score -= 30;
			$recs[] = 'Title is missing. Add a descriptive title.';
		} elseif ( $title_length < 50 ) {
			$score -= 15;
			$recs[] = sprintf(
				'Title is too short (%d chars). Aim for 50-60 characters.',
				$title_length
			);
		} elseif ( $title_length > 60 ) {
			$score -= 10;
			$recs[] = sprintf(
				'Title is too long (%d chars). Search engines may truncate it. Aim for 50-60 characters.',
				$title_length
			);
		}

		// Meta description length check.
		if ( 0 === $meta_length ) {
			$score -= 30;
			$recs[] = 'Meta description is missing. Add a compelling meta description.';
		} elseif ( $meta_length < 150 ) {
			$score -= 15;
			$recs[] = sprintf(
				'Meta description is too short (%d chars). Aim for 150-160 characters.',
				$meta_length
			);
		} elseif ( $meta_length > 160 ) {
			$score -= 10;
			$recs[] = sprintf(
				'Meta description is too long (%d chars). It may be truncated. Aim for 150-160 characters.',
				$meta_length
			);
		}

		// Keyword presence checks.
		if ( ! empty( $focus_keyword ) ) {
			$keyword_lower = mb_strtolower( $focus_keyword );

			if ( false === mb_strpos( mb_strtolower( $title ), $keyword_lower ) ) {
				$score -= 15;
				$recs[] = 'Focus keyword is missing from the title. Include it for better SEO.';
			}

			if ( false === mb_strpos( mb_strtolower( $meta_description ), $keyword_lower ) ) {
				$score -= 10;
				$recs[] = 'Focus keyword is missing from the meta description. Include it for relevance.';
			}
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Analyze internal linking quality.
	 *
	 * Checks link count against the swps_internal_links_min option,
	 * and penalizes generic anchor text on internal links.
	 *
	 * @param string   $content_html The HTML content.
	 * @param string[] $recs         Recommendations array (passed by reference).
	 * @return int Score 0-100.
	 */
	private function analyze_internal_links( string $content_html, array &$recs ): int {
		// Extract all <a> tags with their hrefs and anchor text.
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', $content_html, $matches );

		$site_url       = home_url();
		$internal_hrefs = [];
		$internal_texts = [];

		foreach ( $matches[1] as $index => $href ) {
			$href_trimmed = trim( $href );

			// Relative paths (starting with /) are internal.
			$is_internal = false;
			if ( '' !== $href_trimmed && '/' === $href_trimmed[0] && ( 1 === strlen( $href_trimmed ) || '/' !== $href_trimmed[1] ) ) {
				$is_internal = true;
			} elseif ( 0 === mb_strpos( $href_trimmed, $site_url ) ) {
				// Absolute URL matching the site domain.
				$is_internal = true;
			} elseif ( ! preg_match( '/^https?:\/\//i', $href_trimmed ) && ! preg_match( '/^#/', $href_trimmed ) && ! preg_match( '/^mailto:/i', $href_trimmed ) ) {
				// Non-protocol relative path (e.g., "about-us").
				$is_internal = true;
			}

			if ( $is_internal ) {
				$internal_hrefs[] = $href_trimmed;
				$internal_texts[] = $matches[2][ $index ];
			}
		}

		$link_count = count( $internal_hrefs );

		if ( 0 === $link_count ) {
			$recs[] = 'No internal links found. Add links to related content on your site.';
			return 20;
		}

		$min_links = (int) get_option( 'swps_internal_links_min', 3 );
		$score     = 100;

		if ( $link_count < $min_links ) {
			$penalty = (int) ( ( ( $min_links - $link_count ) / $min_links ) * 40 );
			$score  -= $penalty;
			$recs[]  = sprintf(
				'Only %d internal link(s) found. Aim for at least %d internal links.',
				$link_count,
				$min_links
			);
		}

		// Check for generic anchor text on internal links.
		$generic_anchors = [ 'click here', 'read more', 'here', 'this', 'link', 'this link', 'learn more', 'more' ];
		$generic_count   = 0;

		foreach ( $internal_texts as $anchor_text ) {
			$anchor_clean = mb_strtolower( trim( wp_strip_all_tags( $anchor_text ) ) );
			if ( in_array( $anchor_clean, $generic_anchors, true ) ) {
				$generic_count++;
			}
		}

		if ( $generic_count > 0 ) {
			$score -= min( $generic_count * 10, 30 );
			$recs[] = sprintf(
				'%d link(s) use generic anchor text (e.g., "click here"). Use descriptive anchor text instead.',
				$generic_count
			);
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Analyze content depth based on word count, paragraphs, and heading count.
	 *
	 * Checks word count against the swps_word_count_min option, penalizes
	 * fewer than 5 paragraphs and fewer than 3 H2/H3 headings.
	 *
	 * @param string   $plain_text      Stripped content text.
	 * @param string   $content_html    The HTML content.
	 * @param int      $estimated_words Word count reported by the AI.
	 * @param string[] $recs            Recommendations array (passed by reference).
	 * @return int Score 0-100.
	 */
	private function analyze_content_depth( string $plain_text, string $content_html, int $estimated_words, array &$recs ): int {
		$min_words  = (int) get_option( 'swps_word_count_min', 1200 );
		$word_count = str_word_count( $plain_text );

		// Use actual word count; fall back to estimated if plain text is empty.
		if ( 0 === $word_count && $estimated_words > 0 ) {
			$word_count = $estimated_words;
		}

		$score = 100;

		// Check word count against minimum.
		if ( $word_count < $min_words ) {
			$ratio   = ( $min_words > 0 ) ? $word_count / $min_words : 0;
			$penalty = (int) ( ( 1 - $ratio ) * 50 );
			$score  -= $penalty;
			$recs[]  = sprintf(
				'Content is %d words. Aim for at least %d words for comprehensive coverage.',
				$word_count,
				$min_words
			);
		}

		// Check paragraph count (penalize < 5).
		preg_match_all( '/<p[\s>]/i', $content_html, $p_matches );
		$paragraph_count = count( $p_matches[0] );

		if ( $paragraph_count < 5 ) {
			$score -= 20;
			$recs[] = sprintf(
				'Only %d paragraph(s) found. Use at least 5 paragraphs for better content structure.',
				$paragraph_count
			);
		}

		// Check H2/H3 heading count (penalize < 3).
		preg_match_all( '/<h[23][^>]*>/i', $content_html, $h_matches );
		$heading_count = count( $h_matches[0] );

		if ( $heading_count < 3 ) {
			$score -= 20;
			$recs[] = sprintf(
				'Only %d H2/H3 heading(s) found. Use at least 3 to break up content into clear sections.',
				$heading_count
			);
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Analyze engagement signals in the content.
	 *
	 * Checks for: questions, lists (ul/ol), bold/emphasis (strong/em/b),
	 * and external links.
	 *
	 * @param string   $content_html The HTML content.
	 * @param string   $plain_text   Stripped content text.
	 * @param string[] $recs         Recommendations array (passed by reference).
	 * @return int Score 0-100.
	 */
	private function analyze_engagement( string $content_html, string $plain_text, array &$recs ): int {
		$score   = 0;
		$signals = 0;

		// Check for questions.
		$question_count = mb_substr_count( $plain_text, '?' );
		if ( $question_count > 0 ) {
			$signals += 25;
		} else {
			$recs[] = 'No questions found in content. Questions help engage readers and improve dwell time.';
		}

		// Check for lists (ul/ol).
		$has_lists = ( preg_match( '/<[uo]l[\s>]/i', $content_html ) === 1 );
		if ( $has_lists ) {
			$signals += 25;
		} else {
			$recs[] = 'No lists found. Add bulleted or numbered lists to improve scannability.';
		}

		// Check for bold/emphasis (strong/em/b).
		$has_emphasis = ( preg_match( '/<(strong|em|b)[\s>]/i', $content_html ) === 1 );
		if ( $has_emphasis ) {
			$signals += 25;
		} else {
			$recs[] = 'No bold or emphasis formatting found. Use <strong> or <em> to highlight key points.';
		}

		// Check for external links (links not matching the site URL).
		$site_url = home_url();
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/is', $content_html, $link_matches );

		$external_count = 0;
		foreach ( $link_matches[1] as $href ) {
			// External if it starts with http and does not start with site URL.
			if ( preg_match( '/^https?:\/\//i', $href ) && 0 !== mb_strpos( $href, $site_url ) ) {
				$external_count++;
			}
		}

		if ( $external_count > 0 ) {
			$signals += 25;
		} else {
			$recs[] = 'No external links found. Link to authoritative sources to boost credibility.';
		}

		$score = $signals;

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Count syllables in a block of text.
	 *
	 * Simple heuristic: for each word, if 3 chars or fewer return 1 syllable,
	 * else remove trailing 'e', count vowel groups [aeiouy]+, minimum 1.
	 *
	 * @param string $text The text to count syllables in.
	 * @return int Total syllable count.
	 */
	private function count_syllables( string $text ): int {
		$words  = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$total  = 0;

		foreach ( $words as $word ) {
			$word = mb_strtolower( trim( $word ) );
			// Strip non-alpha characters.
			$word = preg_replace( '/[^a-z]/', '', $word );

			if ( empty( $word ) ) {
				continue;
			}

			if ( strlen( $word ) <= 3 ) {
				$total += 1;
				continue;
			}

			// Remove trailing 'e'.
			if ( 'e' === substr( $word, -1 ) ) {
				$word = substr( $word, 0, -1 );
			}

			// Count vowel groups.
			preg_match_all( '/[aeiouy]+/', $word, $vowel_matches );
			$syllables = count( $vowel_matches[0] );

			$total += max( 1, $syllables );
		}

		return $total;
	}

	/**
	 * Score an existing post's SEO quality across 14 checks.
	 *
	 * Unlike score() which evaluates AI-generated content at creation time,
	 * this method evaluates any published/drafted post against SEO best practices.
	 *
	 * @param int $post_id The WordPress post ID.
	 * @return array {
	 *     @type int    $score   Overall score 0-100.
	 *     @type string $status  'good' | 'needs_work' | 'poor' | 'no_keyword'
	 *     @type array  $checks  Keyed array of check results.
	 * }
	 */
	public function score_post( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return [
				'score'  => 0,
				'status' => 'poor',
				'checks' => [],
			];
		}

		$focus_keyword   = get_post_meta( $post_id, '_swps_focus_keyword', true );
		$meta_title      = get_post_meta( $post_id, '_swps_meta_title', true );
		$meta_desc       = get_post_meta( $post_id, '_swps_meta_description', true );
		$social_image    = get_post_meta( $post_id, '_swps_social_image', true );
		$content_html    = $post->post_content;
		$plain_text      = wp_strip_all_tags( $content_html );
		$keyword_lower   = mb_strtolower( trim( $focus_keyword ) );
		$min_words       = (int) get_option( 'swps_seo_score_content_min', 300 );

		// Check 1: Focus keyword set.
		$has_keyword = ! empty( $keyword_lower );
		$checks = [];

		$checks['focus_keyword_set'] = [
			'pass'  => $has_keyword,
			'label' => __( 'Focus keyword set', 'stratawp-seo' ),
		];

		if ( ! $has_keyword ) {
			$result = [
				'score'  => 0,
				'status' => 'no_keyword',
				'checks' => $checks,
			];
			update_post_meta( $post_id, '_swps_seo_score', $result );
			update_post_meta( $post_id, '_swps_seo_score_value', 0 );
			return $result;
		}

		// Check 2: Keyword in meta title.
		$checks['keyword_in_title'] = [
			'pass'  => ! empty( $meta_title ) && false !== mb_strpos( mb_strtolower( $meta_title ), $keyword_lower ),
			'label' => __( 'Keyword in meta title', 'stratawp-seo' ),
		];

		// Check 3: Keyword in meta description.
		$checks['keyword_in_desc'] = [
			'pass'  => ! empty( $meta_desc ) && false !== mb_strpos( mb_strtolower( $meta_desc ), $keyword_lower ),
			'label' => __( 'Keyword in meta description', 'stratawp-seo' ),
		];

		// Check 4: Title length (50-60 chars).
		$title_len = mb_strlen( $meta_title );
		$checks['title_length'] = [
			'pass'  => $title_len >= 50 && $title_len <= 60,
			'label' => __( 'Meta title length (50-60 chars)', 'stratawp-seo' ),
			'value' => sprintf( '%d chars', $title_len ),
		];

		// Check 5: Description length (150-160 chars).
		$desc_len = mb_strlen( $meta_desc );
		$checks['desc_length'] = [
			'pass'  => $desc_len >= 150 && $desc_len <= 160,
			'label' => __( 'Meta description length (150-160 chars)', 'stratawp-seo' ),
			'value' => sprintf( '%d chars', $desc_len ),
		];

		// Check 6: Keyword in first paragraph.
		$first_para_pass = false;
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content_html, $p_match ) ) {
			$first_para_text = mb_strtolower( wp_strip_all_tags( $p_match[1] ) );
			$first_para_pass = false !== mb_strpos( $first_para_text, $keyword_lower );
		}
		$checks['keyword_first_para'] = [
			'pass'  => $first_para_pass,
			'label' => __( 'Keyword in first paragraph', 'stratawp-seo' ),
		];

		// Check 7: Keyword in at least one H2.
		$h2_pass = false;
		if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $content_html, $h2_matches ) ) {
			foreach ( $h2_matches[1] as $h2_text ) {
				if ( false !== mb_strpos( mb_strtolower( wp_strip_all_tags( $h2_text ) ), $keyword_lower ) ) {
					$h2_pass = true;
					break;
				}
			}
		}
		$checks['keyword_in_h2'] = [
			'pass'  => $h2_pass,
			'label' => __( 'Keyword in an H2 heading', 'stratawp-seo' ),
		];

		// Check 8: Content length.
		$word_count = str_word_count( $plain_text );
		$checks['content_length'] = [
			'pass'  => $word_count >= $min_words,
			'label' => sprintf( __( 'Content length (min %d words)', 'stratawp-seo' ), $min_words ),
			'value' => sprintf( '%s words', number_format_i18n( $word_count ) ),
		];

		// Check 9: Internal links present.
		$site_url        = home_url();
		$has_internal     = false;
		$has_external     = false;
		$has_alt_keyword  = false;

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>/is', $content_html, $link_matches ) ) {
			foreach ( $link_matches[1] as $href ) {
				$href = trim( $href );
				if ( empty( $href ) || '#' === $href[0] || 0 === strpos( $href, 'mailto:' ) ) {
					continue;
				}
				$is_external = preg_match( '/^https?:\/\//i', $href ) && 0 !== mb_strpos( $href, $site_url );
				if ( $is_external ) {
					$has_external = true;
				} else {
					$has_internal = true;
				}
			}
		}

		$checks['internal_links'] = [
			'pass'  => $has_internal,
			'label' => __( 'Internal link present', 'stratawp-seo' ),
		];

		// Check 10: External links present.
		$checks['external_links'] = [
			'pass'  => $has_external,
			'label' => __( 'External link present', 'stratawp-seo' ),
		];

		// Check 11: Image alt text contains keyword.
		if ( preg_match_all( '/<img\s[^>]*alt=["\']([^"\']*)["\'][^>]*>/is', $content_html, $img_matches ) ) {
			foreach ( $img_matches[1] as $alt ) {
				if ( false !== mb_strpos( mb_strtolower( $alt ), $keyword_lower ) ) {
					$has_alt_keyword = true;
					break;
				}
			}
		}
		$checks['image_alt_keyword'] = [
			'pass'  => $has_alt_keyword,
			'label' => __( 'Image alt text contains keyword', 'stratawp-seo' ),
		];

		// Check 12: Slug contains keyword.
		$slug_words   = explode( ' ', $keyword_lower );
		$slug         = $post->post_name;
		$slug_pass    = true;
		foreach ( $slug_words as $word ) {
			$word = trim( $word );
			if ( ! empty( $word ) && false === mb_strpos( $slug, sanitize_title( $word ) ) ) {
				$slug_pass = false;
				break;
			}
		}
		$checks['slug_keyword'] = [
			'pass'  => $slug_pass,
			'label' => __( 'Slug contains keyword', 'stratawp-seo' ),
		];

		// Check 13: OG image set.
		$checks['og_image_set'] = [
			'pass'  => ! empty( $social_image ),
			'label' => __( 'OG image is set', 'stratawp-seo' ),
		];

		// Check 14: Readability (Flesch-Kincaid grade 6-10).
		$readability_pass = false;
		$grade_value      = '';
		if ( str_word_count( $plain_text ) >= 10 ) {
			$recs = [];
			$readability_score = $this->analyze_readability( $plain_text, $recs );
			$sentences      = preg_split( '/[.!?]+/', $plain_text, -1, PREG_SPLIT_NO_EMPTY );
			$sentence_count = max( count( $sentences ), 1 );
			$wc             = str_word_count( $plain_text );
			$syllables      = $this->count_syllables( $plain_text );
			$grade          = max( 0, ( 0.39 * ( $wc / $sentence_count ) ) + ( 11.8 * ( $syllables / $wc ) ) - 15.59 );
			$readability_pass = ( $grade >= 6 && $grade <= 10 );
			$grade_value      = sprintf( 'Grade %.0f', $grade );
		}
		$checks['readability'] = [
			'pass'  => $readability_pass,
			'label' => __( 'Readability score OK', 'stratawp-seo' ),
			'value' => $grade_value,
		];

		// Calculate score: each check worth equal weight.
		$passed = 0;
		foreach ( $checks as $check ) {
			if ( $check['pass'] ) {
				$passed++;
			}
		}
		$total_checks = count( $checks );
		$score        = ( $total_checks > 0 ) ? (int) round( ( $passed / $total_checks ) * 100 ) : 0;

		// Determine status.
		if ( $score >= 80 ) {
			$status = 'good';
		} elseif ( $score >= 50 ) {
			$status = 'needs_work';
		} else {
			$status = 'poor';
		}

		$result = [
			'score'  => $score,
			'status' => $status,
			'checks' => $checks,
		];

		// Cache the result.
		update_post_meta( $post_id, '_swps_seo_score', $result );
		update_post_meta( $post_id, '_swps_seo_score_value', $score );

		return $result;
	}
}
