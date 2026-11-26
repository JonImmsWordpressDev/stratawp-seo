<?php
/**
 * Pre-generation overlap check.
 *
 * Before the generator spends money on a topic, look for content the site
 * already has on it: a near-identical title (SWPS_Duplicate_Checker), a
 * published post or page whose title or focus keyword closely matches the
 * topic, or, when Search Console is connected, a URL already ranking for a
 * query that closely matches the topic.
 *
 * The matching itself is pure (find_overlaps()) so it can be unit tested;
 * check() only gathers the inputs.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds existing posts, pages and ranking URLs that a new topic would compete with.
 */
class SWPS_Overlap_Checker {

	/** WP_Error code returned by the generator when a topic overlaps. */
	public const ERROR_CODE = 'swps_overlap';

	/** Topic queue meta holding the overlaps that stopped a topic. */
	public const META_KEY = '_swps_overlap';

	/** Topic/title token overlap (0..1) that counts as a match. */
	public const TITLE_THRESHOLD = 0.8;

	/** Share of the focus keyword's tokens that must appear in the topic. */
	public const KEYWORD_THRESHOLD = 1.0;

	/** Topic/query token overlap (0..1) that counts as a match. */
	public const QUERY_THRESHOLD = 0.8;

	/** Worst Search Console average position that still counts as ranking. */
	public const MAX_POSITION = 20.0;

	/** Search Console rows with fewer impressions are ignored as noise. */
	public const MIN_IMPRESSIONS = 10;

	/** Fewest shared significant tokens for any token-based match. */
	public const MIN_SHARED_TOKENS = 2;

	/** Most overlaps reported per check. */
	public const MAX_RESULTS = 5;

	/** Published posts/pages compared per check. */
	private const POST_LIMIT = 500;

	/** Search Console window, same as the cannibalization scan (shares its cache). */
	private const GSC_DAYS = 90;

	/** Search Console row cap, same as the cannibalization scan (shares its cache). */
	private const GSC_ROW_LIMIT = 5000;

	/** Words too common to signal what a topic is about (space separated). */
	private const STOPWORDS = 'a an and are as at be best by can do does for from guide how in is it its of on or the this to top vs what when where which who why with you your';

	/**
	 * Thresholds after the swps_overlap_thresholds filter.
	 *
	 * @return array{title: float, keyword: float, query: float, max_position: float, min_impressions: int, min_shared: int, max_results: int}
	 */
	public static function thresholds(): array {
		$defaults = array(
			'title'           => self::TITLE_THRESHOLD,
			'keyword'         => self::KEYWORD_THRESHOLD,
			'query'           => self::QUERY_THRESHOLD,
			'max_position'    => self::MAX_POSITION,
			'min_impressions' => self::MIN_IMPRESSIONS,
			'min_shared'      => self::MIN_SHARED_TOKENS,
			'max_results'     => self::MAX_RESULTS,
		);

		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'swps_overlap_thresholds', $defaults ) : $defaults;

		return self::normalize_thresholds( is_array( $filtered ) ? $filtered : array() );
	}

	/**
	 * Fill and type-cast a thresholds array.
	 *
	 * @param array $opts Partial thresholds.
	 * @return array{title: float, keyword: float, query: float, max_position: float, min_impressions: int, min_shared: int, max_results: int}
	 */
	public static function normalize_thresholds( array $opts ): array {
		return array(
			'title'           => (float) ( $opts['title'] ?? self::TITLE_THRESHOLD ),
			'keyword'         => (float) ( $opts['keyword'] ?? self::KEYWORD_THRESHOLD ),
			'query'           => (float) ( $opts['query'] ?? self::QUERY_THRESHOLD ),
			'max_position'    => (float) ( $opts['max_position'] ?? self::MAX_POSITION ),
			'min_impressions' => (int) ( $opts['min_impressions'] ?? self::MIN_IMPRESSIONS ),
			'min_shared'      => max( 1, (int) ( $opts['min_shared'] ?? self::MIN_SHARED_TOKENS ) ),
			'max_results'     => max( 1, (int) ( $opts['max_results'] ?? self::MAX_RESULTS ) ),
		);
	}

	/**
	 * Significant tokens of a phrase: lowercase words, stopwords dropped,
	 * a trailing plural "s" folded so "plugin" and "plugins" match.
	 *
	 * @param string $text Phrase.
	 * @return string[] Unique tokens.
	 */
	public static function tokens( string $text ): array {
		$text  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$stop  = array_flip( explode( ' ', self::STOPWORDS ) );
		$out   = array();

		foreach ( (array) $words as $word ) {
			if ( isset( $stop[ $word ] ) ) {
				continue;
			}
			if ( strlen( $word ) > 3 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) ) {
				$word = substr( $word, 0, -1 );
			}
			$out[ $word ] = true;
		}

		return array_keys( $out );
	}

	/**
	 * Overlap coefficient of two token sets: shared / size of the smaller set.
	 *
	 * @param string[] $a Tokens.
	 * @param string[] $b Tokens.
	 * @return array{score: float, shared: int}
	 */
	public static function overlap( array $a, array $b ): array {
		if ( empty( $a ) || empty( $b ) ) {
			return array(
				'score'  => 0.0,
				'shared' => 0,
			);
		}

		$shared = count( array_intersect( $a, $b ) );

		return array(
			'score'  => (float) ( $shared / min( count( $a ), count( $b ) ) ),
			'shared' => $shared,
		);
	}

	/**
	 * Find existing content that overlaps a topic.
	 *
	 * @param string $topic           Proposed topic.
	 * @param array  $posts           Existing content: list of {id?, title, url?, focus_keyword?}.
	 * @param array  $gsc_rows        Search Console rows: list of {query, page, clicks?, impressions, position}.
	 * @param array  $opts            Thresholds (see thresholds()).
	 * @param string $duplicate_title Title SWPS_Duplicate_Checker matched, or ''.
	 * @return array<int, array{type: string, title: string, url: string, post_id: int, query: string, position: float, score: float}>
	 */
	public static function find_overlaps( string $topic, array $posts, array $gsc_rows, array $opts = array(), string $duplicate_title = '' ): array {
		$opts         = self::normalize_thresholds( $opts );
		$topic_tokens = self::tokens( $topic );
		$found        = array();
		$seen_urls    = array();

		$add = static function ( array $item ) use ( &$found, &$seen_urls ): void {
			$key = '' !== $item['url'] ? strtolower( rtrim( $item['url'], '/' ) ) : 'title:' . strtolower( $item['title'] );
			if ( isset( $seen_urls[ $key ] ) ) {
				return;
			}
			$seen_urls[ $key ] = true;
			$found[]           = $item;
		};

		$item = static function ( string $type, string $title, string $url, int $post_id, float $score, string $query = '', float $position = 0.0 ): array {
			return array(
				'type'     => $type,
				'title'    => $title,
				'url'      => $url,
				'post_id'  => $post_id,
				'query'    => $query,
				'position' => round( $position, 1 ),
				'score'    => round( $score, 2 ),
			);
		};

		$duplicate_title = trim( $duplicate_title );

		foreach ( $posts as $post ) {
			$title   = trim( (string) ( $post['title'] ?? '' ) );
			$url     = (string) ( $post['url'] ?? '' );
			$post_id = (int) ( $post['id'] ?? 0 );

			if ( '' !== $duplicate_title && 0 === strcasecmp( $title, $duplicate_title ) ) {
				$add( $item( 'duplicate', $title, $url, $post_id, 1.0 ) );
				$duplicate_title = '';
				continue;
			}

			$title_match = self::overlap( $topic_tokens, self::tokens( $title ) );
			if ( $title_match['shared'] >= $opts['min_shared'] && $title_match['score'] >= $opts['title'] ) {
				$add( $item( 'title', $title, $url, $post_id, $title_match['score'] ) );
				continue;
			}

			$keyword_tokens = self::tokens( (string) ( $post['focus_keyword'] ?? '' ) );
			if ( count( $keyword_tokens ) >= $opts['min_shared'] ) {
				$keyword_share = count( array_intersect( $keyword_tokens, $topic_tokens ) ) / count( $keyword_tokens );
				if ( $keyword_share >= $opts['keyword'] ) {
					$add( $item( 'keyword', $title, $url, $post_id, $keyword_share, (string) $post['focus_keyword'] ) );
				}
			}
		}

		// The duplicate checker can match a remote title with no local post.
		if ( '' !== $duplicate_title ) {
			$add( $item( 'duplicate', $duplicate_title, '', 0, 1.0 ) );
		}

		// Best-ranking page first, so each URL keeps its strongest query.
		usort(
			$gsc_rows,
			static function ( $a, $b ): int {
				return (float) ( $a['position'] ?? 999 ) <=> (float) ( $b['position'] ?? 999 );
			}
		);

		foreach ( $gsc_rows as $row ) {
			$position    = (float) ( $row['position'] ?? 0 );
			$impressions = (int) ( $row['impressions'] ?? 0 );
			$page        = (string) ( $row['page'] ?? '' );
			$query       = (string) ( $row['query'] ?? '' );

			if ( '' === $page || $position <= 0 || $position > $opts['max_position'] || $impressions < $opts['min_impressions'] ) {
				continue;
			}

			$match = self::overlap( $topic_tokens, self::tokens( $query ) );
			if ( $match['shared'] >= $opts['min_shared'] && $match['score'] >= $opts['query'] ) {
				$add( $item( 'ranking', '', $page, 0, $match['score'], $query, $position ) );
			}
		}

		return array_slice( $found, 0, $opts['max_results'] );
	}

	/**
	 * Run the check for a topic against this site.
	 *
	 * @param string                      $topic             Proposed topic.
	 * @param SWPS_Duplicate_Checker|null $duplicate_checker Title duplicate checker.
	 * @return array Overlaps (see find_overlaps()); empty when none or the check is off.
	 */
	public static function check( string $topic, ?SWPS_Duplicate_Checker $duplicate_checker = null ): array {
		$topic = trim( $topic );
		if ( '' === $topic || ! apply_filters( 'swps_overlap_check_enabled', true, $topic ) ) {
			return array();
		}

		$duplicate = $duplicate_checker ? $duplicate_checker->is_duplicate( $topic ) : false;

		return self::find_overlaps( $topic, self::existing_content(), self::ranking_rows(), self::thresholds(), false === $duplicate ? '' : (string) $duplicate );
	}

	/**
	 * Published posts and pages with their focus keywords.
	 *
	 * @return array<int, array{id: int, title: string, url: string, focus_keyword: string}>
	 */
	private static function existing_content(): array {
		// Full objects (not ids) so posts and their meta load in two queries.
		$posts = get_posts(
			array(
				'post_type'              => array( 'post', 'page' ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::POST_LIMIT,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'            => (int) $post->ID,
				'title'         => html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'url'           => (string) get_permalink( $post ),
				'focus_keyword' => (string) get_post_meta( $post->ID, '_swps_focus_keyword', true ),
			);
		}

		return $out;
	}

	/**
	 * Search Console query/page rows, or none when it is not connected.
	 *
	 * @return array
	 */
	private static function ranking_rows(): array {
		if ( ! function_exists( 'stratawp_seo' ) ) {
			return array();
		}
		$plugin = stratawp_seo();
		if ( ! isset( $plugin->search_console ) || ! $plugin->search_console instanceof SWPS_Search_Console || ! $plugin->search_console->is_connected() ) {
			return array();
		}

		return $plugin->search_console->get_query_page_rows( self::GSC_DAYS, self::GSC_ROW_LIMIT );
	}

	/**
	 * One-line, human-readable summary of overlaps for logs and errors.
	 *
	 * @param array $overlaps Overlaps from find_overlaps().
	 * @return string
	 */
	public static function summarize( array $overlaps ): string {
		$parts = array();
		foreach ( $overlaps as $o ) {
			switch ( $o['type'] ) {
				case 'ranking':
					/* translators: 1: URL, 2: search query, 3: average position */
					$parts[] = sprintf( __( '%1$s ranks #%3$s for "%2$s"', 'stratawp-seo' ), $o['url'], $o['query'], $o['position'] );
					break;
				case 'keyword':
					/* translators: 1: post title, 2: focus keyword */
					$parts[] = sprintf( __( '"%1$s" targets "%2$s"', 'stratawp-seo' ), $o['title'], $o['query'] );
					break;
				default:
					/* translators: %s: post title */
					$parts[] = sprintf( __( '"%s" has a similar title', 'stratawp-seo' ), $o['title'] );
			}
		}

		return implode( '; ', $parts );
	}

	/**
	 * Mark a queued topic as stopped by an overlap so no run retries it.
	 *
	 * Uses the existing "failed" status with the overlap summary as the error
	 * message, and keeps the overlaps in META_KEY. Re-queueing the topic by
	 * hand still runs the check; generate it from the Generate screen or
	 * `wp swps generate --force` to override.
	 *
	 * @param int              $topic_id Topic post ID.
	 * @param WP_Error         $error    Overlap error from the generator.
	 * @param SWPS_Topic_Queue $queue    Topic queue.
	 */
	public static function park_topic( int $topic_id, WP_Error $error, SWPS_Topic_Queue $queue ): void {
		$data = (array) $error->get_error_data();
		update_post_meta( $topic_id, self::META_KEY, $data['overlaps'] ?? array() );
		delete_post_meta( $topic_id, '_swps_attempt_count' );
		$queue->update_status( $topic_id, 'failed', $error->get_error_message() );
	}

	/**
	 * The WP_Error the generator returns for an overlapping topic.
	 *
	 * @param string $topic    Topic.
	 * @param array  $overlaps Overlaps.
	 * @return WP_Error
	 */
	public static function to_error( string $topic, array $overlaps ): WP_Error {
		return new WP_Error(
			self::ERROR_CODE,
			sprintf(
				/* translators: 1: topic, 2: summary of existing content */
				__( 'Not generated: "%1$s" overlaps existing content: %2$s', 'stratawp-seo' ),
				$topic,
				self::summarize( $overlaps )
			),
			array(
				'status'   => 409,
				'overlaps' => $overlaps,
			)
		);
	}
}
