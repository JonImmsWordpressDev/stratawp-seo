<?php
/**
 * Citation prompt management — tracked prompt list (option-backed CRUD),
 * seed-candidate builder, enabled engines, and domain configuration for the
 * AI citation tracker.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prompt list, engines, and domain configuration for citation checks.
 */
class SWPS_Citation_Prompts {

	public const OPTION_PROMPTS = 'swps_citation_prompts';
	public const OPTION_ENGINES = 'swps_citation_engines';

	/** Maximum tracked prompts (filterable via swps_citation_prompts_cap). */
	private const PROMPTS_CAP = 25;

	/**
	 * Keyword tracker — seed-candidate source.
	 *
	 * @var SWPS_Keyword_Tracker
	 */
	private SWPS_Keyword_Tracker $keyword_tracker;

	/**
	 * Search Console — GSC question-query seed source.
	 *
	 * @var SWPS_Search_Console
	 */
	private SWPS_Search_Console $search_console;

	/**
	 * Inject the seed-candidate sources.
	 *
	 * @param SWPS_Keyword_Tracker $keyword_tracker Keyword tracker instance.
	 * @param SWPS_Search_Console  $search_console  Search Console instance.
	 */
	public function __construct( SWPS_Keyword_Tracker $keyword_tracker, SWPS_Search_Console $search_console ) {
		$this->keyword_tracker = $keyword_tracker;
		$this->search_console  = $search_console;
	}

	/**
	 * Get the tracked prompts list.
	 *
	 * @return array[] Entries: { prompt: string, post_id: int, added: int }.
	 */
	public function get_prompts(): array {
		$raw = get_option( self::OPTION_PROMPTS, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['prompt'] ) ) {
				continue;
			}
			$out[] = array(
				'prompt'  => (string) $entry['prompt'],
				'post_id' => (int) ( $entry['post_id'] ?? 0 ),
				'added'   => (int) ( $entry['added'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Add a prompt to the tracked list (sanitized, deduped, capped).
	 *
	 * @param string $prompt  The prompt text.
	 * @param int    $post_id Optional linked post ID.
	 * @return true|WP_Error
	 */
	public function add_prompt( string $prompt, int $post_id = 0 ): bool|WP_Error {
		$prompt = sanitize_text_field( trim( $prompt ) );
		$prompt = mb_substr( $prompt, 0, 500 );

		if ( '' === $prompt ) {
			return new WP_Error( 'swps_citation_empty_prompt', __( 'Prompt cannot be empty.', 'stratawp-seo' ) );
		}

		$prompts = $this->get_prompts();

		// Dedupe (case-insensitive).
		foreach ( $prompts as $entry ) {
			if ( strtolower( $entry['prompt'] ) === strtolower( $prompt ) ) {
				return true; // Already tracked.
			}
		}

		/** Filter the maximum number of tracked citation prompts. */
		$cap = (int) apply_filters( 'swps_citation_prompts_cap', self::PROMPTS_CAP );
		if ( count( $prompts ) >= $cap ) {
			return new WP_Error(
				'swps_citation_prompts_cap',
				/* translators: %d: maximum number of prompts */
				sprintf( __( 'Prompt limit reached (%d). Remove a prompt before adding another.', 'stratawp-seo' ), $cap )
			);
		}

		$prompts[] = array(
			'prompt'  => $prompt,
			'post_id' => max( 0, $post_id ),
			'added'   => time(),
		);
		update_option( self::OPTION_PROMPTS, $prompts, false );

		return true;
	}

	/**
	 * Remove a prompt from the tracked list (matched case-insensitively).
	 *
	 * @param string $prompt The prompt text to remove.
	 * @return bool True when a prompt was removed.
	 */
	public function remove_prompt( string $prompt ): bool {
		$prompt  = strtolower( sanitize_text_field( trim( $prompt ) ) );
		$prompts = $this->get_prompts();
		$kept    = array();

		foreach ( $prompts as $entry ) {
			if ( strtolower( $entry['prompt'] ) !== $prompt ) {
				$kept[] = $entry;
			}
		}

		if ( count( $kept ) === count( $prompts ) ) {
			return false;
		}

		update_option( self::OPTION_PROMPTS, $kept, false );
		return true;
	}

	/**
	 * Build seed candidates from tracked keywords and GSC question queries.
	 * Returns candidates only — never auto-adds.
	 *
	 * @param int $limit Max candidates returned.
	 * @return array[] Entries: { prompt: string, post_id: int, source: 'keyword'|'gsc' }.
	 */
	public function seed_candidates( int $limit = 20 ): array {
		$candidates = array();
		$seen       = array();
		$tracked    = array();

		foreach ( $this->get_prompts() as $entry ) {
			$tracked[ strtolower( $entry['prompt'] ) ] = true;
		}

		// 1. Tracked keywords, as-is (already impression-sorted).
		foreach ( $this->keyword_tracker->get_tracked_keywords( 50 ) as $row ) {
			$keyword = strtolower( trim( (string) ( $row['keyword'] ?? '' ) ) );
			if ( '' === $keyword || isset( $seen[ $keyword ] ) || isset( $tracked[ $keyword ] ) ) {
				continue;
			}
			$seen[ $keyword ] = true;
			$candidates[]     = array(
				'prompt'  => $keyword,
				'post_id' => (int) ( $row['post_id'] ?? 0 ),
				'source'  => 'keyword',
			);
		}

		// 2. GSC question-form queries, top by impressions.
		// Row shape: { keys: [query], clicks, impressions, ctr, position }.
		$gsc     = $this->search_console->get_search_data( 90 );
		$queries = is_array( $gsc['queries'] ?? null ) ? $gsc['queries'] : array();
		usort(
			$queries,
			static function ( $a, $b ) {
				return (int) ( $b['impressions'] ?? 0 ) <=> (int) ( $a['impressions'] ?? 0 );
			}
		);
		foreach ( $queries as $row ) {
			$query = strtolower( trim( (string) ( $row['keys'][0] ?? '' ) ) );
			if ( '' === $query || isset( $seen[ $query ] ) || isset( $tracked[ $query ] ) ) {
				continue;
			}
			if ( ! SWPS_Citation_Tracker::is_question_query( $query ) ) {
				continue;
			}
			$seen[ $query ] = true;
			$candidates[]   = array(
				'prompt'  => $query,
				'post_id' => 0,
				'source'  => 'gsc',
			);
		}

		return array_slice( $candidates, 0, $limit );
	}

	/**
	 * Engines enabled for citation checks: the swps_citation_engines selection
	 * (default: the current AI provider) intersected with providers that have
	 * a saved API key.
	 *
	 * @return string[] Provider slugs.
	 */
	public function enabled_engines(): array {
		$selected = get_option( self::OPTION_ENGINES, array( (string) get_option( 'swps_ai_provider', 'anthropic' ) ) );
		if ( ! is_array( $selected ) ) {
			$selected = array();
		}

		$out = array();
		foreach ( SWPS_Provider_Factory::all_ai_providers() as $slug => $provider ) {
			if ( in_array( $slug, $selected, true ) && '' !== $provider->get_api_key() ) {
				$out[] = $slug;
			}
		}

		return $out;
	}

	/**
	 * Our domain aliases: home_url() host plus swps_citation_our_domains filter.
	 *
	 * @return string[] Lowercased, www-stripped domains.
	 */
	public function our_domains(): array {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		/** Filter the list of domains considered "ours" for citation matching. */
		$domains = (array) apply_filters( 'swps_citation_our_domains', array( $host ) );

		return array_values( array_unique( array_filter( array_map( 'strval', $domains ) ) ) );
	}

	/**
	 * Competitor domains parsed from the swps_competitors option entries' URLs.
	 *
	 * @return string[] Lowercased, www-stripped domains.
	 */
	public function competitor_domains(): array {
		$list = get_option( 'swps_competitors', array() );
		if ( ! is_array( $list ) ) {
			return array();
		}

		$urls = array();
		foreach ( $list as $competitor ) {
			if ( is_array( $competitor ) && ! empty( $competitor['url'] ) ) {
				$urls[] = (string) $competitor['url'];
			}
		}

		return SWPS_Citation_Tracker::extract_domains( $urls );
	}
}
