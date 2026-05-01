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

		$candidate_data = [];
		foreach ( $candidates as $candidate ) {
			$post = get_post( $candidate['post_id'] );
			if ( ! $post ) {
				continue;
			}
			$focus_kw         = get_post_meta( $post->ID, '_swps_focus_keyword', true );
			$candidate_data[] = [
				'post_id'       => $post->ID,
				'title'         => $post->post_title,
				'excerpt'       => wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 ),
				'url'           => get_permalink( $post->ID ),
				'focus_keyword' => $focus_kw ?: '',
			];
		}

		if ( empty( $candidate_data ) ) {
			return [];
		}

		$source_focus_kw = get_post_meta( $source_post_id, '_swps_focus_keyword', true );

		$system_prompt = <<<'PROMPT'
You are an SEO internal linking specialist. Analyze the relationship between a source post and candidate target posts. For each candidate, provide:
1. A relevance score from 0.0 to 1.0 (how relevant is linking from the source to this target)
2. Suggested anchor text (2-6 words, natural phrasing that would fit in the source post)
3. A one-line rationale explaining why these posts are related

Respond with JSON only. No markdown fences.

Required JSON structure:
[
  {
    "post_id": 123,
    "relevance_score": 0.85,
    "anchor_text": "suggested anchor text",
    "rationale": "Both posts discuss WordPress caching strategies"
  }
]
PROMPT;

		$candidates_json = wp_json_encode( $candidate_data, JSON_PRETTY_PRINT );

		$user_prompt  = "SOURCE POST:\n";
		$user_prompt .= "Title: {$source_post->post_title}\n";
		$user_prompt .= "Focus Keyword: " . ( $source_focus_kw ?: 'none' ) . "\n";
		$user_prompt .= "Excerpt: " . wp_trim_words( wp_strip_all_tags( $source_post->post_content ), 80 ) . "\n\n";
		$user_prompt .= "CANDIDATE TARGET POSTS:\n{$candidates_json}\n\n";
		$user_prompt .= "Analyze each candidate and respond with the JSON array.";

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

		$enriched = [];
		foreach ( $analysis as $item ) {
			if ( ! isset( $item['post_id'], $item['relevance_score'] ) ) {
				continue;
			}
			$enriched[] = [
				'post_id'         => (int) $item['post_id'],
				'relevance_score' => max( 0.0, min( 1.0, (float) $item['relevance_score'] ) ),
				'anchor_text'     => sanitize_text_field( $item['anchor_text'] ?? '' ),
				'rationale'       => sanitize_text_field( $item['rationale'] ?? '' ),
			];
		}

		return $enriched;
	}
}
