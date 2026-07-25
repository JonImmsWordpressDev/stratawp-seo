<?php
/**
 * AI-powered link analysis engine.
 *
 * Takes keyword-matched candidates and uses the configured AI provider
 * to score semantic relevance and suggest optimal anchor text.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Link_AI_Engine {

	private SWPS_AI_Provider $api;
	private SWPS_Cost_Tracker $cost_tracker;

	public function __construct( SWPS_AI_Provider $api, SWPS_Cost_Tracker $cost_tracker ) {
		$this->api          = $api;
		$this->cost_tracker = $cost_tracker;
	}

	/**
	 * Analyze candidates for a source post using AI.
	 *
	 * @param int   $source_post_id Source post ID.
	 * @param array $candidates     Array of ['post_id' => int, 'score' => float].
	 * @return array|WP_Error Array of enriched results or error.
	 */
	public function analyze( int $source_post_id, array $candidates ): array|WP_Error {
		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			return new WP_Error( 'swps_invalid_post', 'Source post not found.' );
		}

		$batch_size = (int) get_option( 'swps_link_ai_batch_size', 10 );
		$candidates = array_slice( $candidates, 0, $batch_size );

		$candidate_data = array();
		foreach ( $candidates as $candidate ) {
			// Cross-site candidates (owned/partner domains) have no local
			// post ID — they're identified by URL and carry their own
			// title/excerpt from the remote inventory.
			if ( ! empty( $candidate['cross_site'] ) ) {
				if ( empty( $candidate['url'] ) ) {
					continue;
				}
				$candidate_data[] = array(
					'url'        => (string) $candidate['url'],
					'title'      => (string) ( $candidate['title'] ?? '' ),
					'excerpt'    => (string) ( $candidate['excerpt'] ?? '' ),
					'cross_site' => true,
				);
				continue;
			}

			$post = get_post( $candidate['post_id'] );
			if ( ! $post ) {
				continue;
			}
			$focus_kw         = get_post_meta( $post->ID, '_swps_focus_keyword', true );
			$candidate_data[] = array(
				'post_id'       => $post->ID,
				'title'         => $post->post_title,
				'excerpt'       => wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 ),
				'url'           => get_permalink( $post->ID ),
				'focus_keyword' => $focus_kw ?: '',
			);
		}

		if ( empty( $candidate_data ) ) {
			return array();
		}

		$source_focus_kw = get_post_meta( $source_post_id, '_swps_focus_keyword', true );

		$system_prompt = <<<'PROMPT'
You are an SEO internal linking specialist. Analyze the relationship between a source post and candidate target posts. For each candidate, provide:
1. A relevance score from 0.0 to 1.0 (how relevant is linking from the source to this target)
2. Suggested anchor text (2-6 words, natural phrasing that would fit in the source post)
3. A one-line rationale explaining why these posts are related

Candidates are identified by either a numeric "post_id" (same site) or a "url" with "cross_site": true (a partner site run by the same publisher). In each result, echo back exactly the identifier the candidate had: "post_id" for post_id candidates, "url" for url candidates.

Respond with JSON only. No markdown fences.

Required JSON structure:
[
  {
    "post_id": 123,
    "relevance_score": 0.85,
    "anchor_text": "suggested anchor text",
    "rationale": "Both posts discuss WordPress caching strategies"
  },
  {
    "url": "https://partner-site.com/example-post/",
    "relevance_score": 0.7,
    "anchor_text": "suggested anchor text",
    "rationale": "The partner post expands on this topic"
  }
]
PROMPT;

		$candidates_json = wp_json_encode( $candidate_data, JSON_PRETTY_PRINT );

		$user_prompt  = "SOURCE POST:\n";
		$user_prompt .= "Title: {$source_post->post_title}\n";
		$user_prompt .= 'Focus Keyword: ' . ( $source_focus_kw ?: 'none' ) . "\n";
		$user_prompt .= 'Excerpt: ' . wp_trim_words( wp_strip_all_tags( $source_post->post_content ), 80 ) . "\n\n";
		$user_prompt .= "CANDIDATE TARGET POSTS:\n{$candidates_json}\n\n";
		$user_prompt .= 'Analyze each candidate and respond with the JSON array.';

		$result = $this->api->chat_json( $system_prompt, $user_prompt, 2048 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Track cost.
		$model = get_option( 'swps_model', '' );
		if ( ! empty( $result['_usage'] ) ) {
			$this->cost_tracker->track(
				$model,
				$result['_usage']['input_tokens'] ?? 0,
				$result['_usage']['output_tokens'] ?? 0
			);
		}

		// Parse response — handle different JSON structures.
		$analysis = $result;
		if ( isset( $result[0] ) && is_array( $result[0] ) ) {
			$analysis = $result;
		} elseif ( isset( $result['results'] ) ) {
			$analysis = $result['results'];
		} elseif ( isset( $result['candidates'] ) ) {
			$analysis = $result['candidates'];
		}

		return self::parse_enriched_items( $analysis );
	}

	/**
	 * Parse AI response items into enriched results.
	 *
	 * Local candidates are identified by post_id, cross-site candidates
	 * (owned/partner domains) by url. Items missing an identifier or a
	 * relevance score are skipped; scores are clamped to [0, 1].
	 *
	 * @param array $analysis Decoded AI response items.
	 * @return array<int, array> Enriched results; cross-site entries carry
	 *                           'url' and 'cross_site' => true.
	 */
	public static function parse_enriched_items( array $analysis ): array {
		$enriched = array();
		foreach ( $analysis as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['relevance_score'] ) ) {
				continue;
			}

			$base = array(
				'relevance_score' => max( 0.0, min( 1.0, (float) $item['relevance_score'] ) ),
				'anchor_text'     => sanitize_text_field( $item['anchor_text'] ?? '' ),
				'rationale'       => sanitize_text_field( $item['rationale'] ?? '' ),
			);

			if ( isset( $item['post_id'] ) ) {
				$enriched[] = array_merge(
					array( 'post_id' => (int) $item['post_id'] ),
					$base,
					array( 'cross_site' => false )
				);
			} elseif ( ! empty( $item['url'] ) && is_string( $item['url'] ) ) {
				$enriched[] = array_merge(
					array( 'url' => $item['url'] ),
					$base,
					array( 'cross_site' => true )
				);
			}
		}

		return $enriched;
	}
}
