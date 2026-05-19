<?php
/**
 * Keyword-based link matching engine.
 *
 * Extracts significant terms from post content (title, headings, focus keyword, body)
 * and matches posts by term overlap for internal link suggestions.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Link_Keyword_Engine {

	private const TABLE = 'swps_link_index';

	private const WEIGHTS = array(
		'title'    => 1.0,
		'focus_kw' => 0.9,
		'h2'       => 0.8,
		'h3'       => 0.6,
		'body'     => 0.3,
	);

	private const STOP_WORDS = array(
		'the',
		'a',
		'an',
		'and',
		'or',
		'but',
		'in',
		'on',
		'at',
		'to',
		'for',
		'of',
		'with',
		'by',
		'from',
		'is',
		'are',
		'was',
		'were',
		'be',
		'been',
		'being',
		'have',
		'has',
		'had',
		'do',
		'does',
		'did',
		'will',
		'would',
		'could',
		'should',
		'may',
		'might',
		'shall',
		'can',
		'this',
		'that',
		'these',
		'those',
		'it',
		'its',
		'not',
		'no',
		'nor',
		'so',
		'if',
		'then',
		'than',
		'too',
		'very',
		'just',
		'about',
		'above',
		'after',
		'again',
		'all',
		'also',
		'am',
		'as',
		'because',
		'before',
		'between',
		'both',
		'each',
		'few',
		'get',
		'got',
		'he',
		'her',
		'here',
		'him',
		'his',
		'how',
		'i',
		'into',
		'me',
		'more',
		'most',
		'my',
		'new',
		'now',
		'only',
		'other',
		'our',
		'out',
		'over',
		'own',
		'same',
		'she',
		'some',
		'such',
		'their',
		'them',
		'there',
		'they',
		'through',
		'under',
		'up',
		'us',
		'use',
		'what',
		'when',
		'where',
		'which',
		'while',
		'who',
		'whom',
		'why',
		'you',
		'your',
	);

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . self::TABLE;

		$sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            term VARCHAR(100) NOT NULL,
            source ENUM('title','h2','h3','focus_kw','body') NOT NULL,
            weight FLOAT NOT NULL DEFAULT 0.3,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_post_id (post_id),
            KEY idx_term (term),
            KEY idx_term_weight (term, weight)
        ) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function index_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			$this->clear_post( $post_id );
			return;
		}
		$terms = $this->extract_terms( $post );
		$this->clear_post( $post_id );
		$this->store_terms( $post_id, $terms );
	}

	public function clear_post( int $post_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );
	}

	public function extract_terms( WP_Post $post ): array {
		$terms = array();

		$title_terms = $this->tokenize( $post->post_title );
		foreach ( $title_terms as $term ) {
			$terms[] = array(
				'term'   => $term,
				'source' => 'title',
				'weight' => self::WEIGHTS['title'],
			);
		}

		$focus_kw = get_post_meta( $post->ID, '_swps_focus_keyword', true );
		if ( ! empty( $focus_kw ) ) {
			$normalized = $this->normalize( $focus_kw );
			if ( strlen( $normalized ) >= 3 ) {
				$terms[] = array(
					'term'   => $normalized,
					'source' => 'focus_kw',
					'weight' => self::WEIGHTS['focus_kw'],
				);
			}
		}

		$h2s = $this->extract_headings( $post->post_content, 'h2' );
		foreach ( $h2s as $heading ) {
			foreach ( $this->tokenize( $heading ) as $term ) {
				$terms[] = array(
					'term'   => $term,
					'source' => 'h2',
					'weight' => self::WEIGHTS['h2'],
				);
			}
		}

		$h3s = $this->extract_headings( $post->post_content, 'h3' );
		foreach ( $h3s as $heading ) {
			foreach ( $this->tokenize( $heading ) as $term ) {
				$terms[] = array(
					'term'   => $term,
					'source' => 'h3',
					'weight' => self::WEIGHTS['h3'],
				);
			}
		}

		$plain      = wp_strip_all_tags( $post->post_content );
		$body_terms = $this->extract_top_body_terms( $plain, 10 );
		foreach ( $body_terms as $term ) {
			$terms[] = array(
				'term'   => $term,
				'source' => 'body',
				'weight' => self::WEIGHTS['body'],
			);
		}

		$unique = array();
		foreach ( $terms as $entry ) {
			$key = $entry['term'];
			if ( ! isset( $unique[ $key ] ) || $entry['weight'] > $unique[ $key ]['weight'] ) {
				$unique[ $key ] = $entry;
			}
		}

		return array_values( $unique );
	}

	public function find_related( int $post_id, float $threshold = 0.3, int $limit = 20 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$source_terms = $wpdb->get_results(
			$wpdb->prepare( "SELECT term, weight FROM {$table} WHERE post_id = %d", $post_id ),
			ARRAY_A
		);

		if ( empty( $source_terms ) ) {
			return array();
		}

		$term_weights = array();
		foreach ( $source_terms as $row ) {
			$term_weights[ $row['term'] ] = (float) $row['weight'];
		}

		$placeholders = implode( ',', array_fill( 0, count( $term_weights ), '%s' ) );
		$term_values  = array_keys( $term_weights );

		$matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, term, weight FROM {$table} WHERE term IN ({$placeholders}) AND post_id != %d",
				array_merge( $term_values, array( $post_id ) )
			),
			ARRAY_A
		);

		if ( empty( $matches ) ) {
			return array();
		}

		$scores = array();
		foreach ( $matches as $row ) {
			$pid = (int) $row['post_id'];
			if ( ! isset( $scores[ $pid ] ) ) {
				$scores[ $pid ] = 0.0;
			}
			$scores[ $pid ] += $term_weights[ $row['term'] ] * (float) $row['weight'];
		}

		$max_score = max( $scores );
		if ( $max_score > 0 ) {
			foreach ( $scores as &$score ) {
				$score = round( $score / $max_score, 3 );
			}
			unset( $score );
		}

		$results = array();
		foreach ( $scores as $pid => $score ) {
			if ( $score >= $threshold ) {
				$results[] = array(
					'post_id' => $pid,
					'score'   => $score,
				);
			}
		}

		usort( $results, fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $results, 0, $limit );
	}

	public function tokenize_public( string $text ): array {
		return $this->tokenize( $text );
	}

	private function store_terms( int $post_id, array $terms ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );

		foreach ( $terms as $entry ) {
			$wpdb->insert(
				$table,
				array(
					'post_id'    => $post_id,
					'term'       => mb_substr( $entry['term'], 0, 100 ),
					'source'     => $entry['source'],
					'weight'     => $entry['weight'],
					'updated_at' => $now,
				),
				array( '%d', '%s', '%s', '%f', '%s' )
			);
		}
	}

	private function extract_headings( string $html, string $tag ): array {
		$headings = array();
		if ( preg_match_all( "/<{$tag}[^>]*>(.*?)<\/{$tag}>/is", $html, $matches ) ) {
			foreach ( $matches[1] as $heading ) {
				$headings[] = wp_strip_all_tags( $heading );
			}
		}
		return $headings;
	}

	private function extract_top_body_terms( string $plain_text, int $count = 10 ): array {
		$words = $this->tokenize( $plain_text );
		if ( empty( $words ) ) {
			return array();
		}
		$freq = array_count_values( $words );
		arsort( $freq );
		return array_slice( array_keys( $freq ), 0, $count );
	}

	private function tokenize( string $text ): array {
		$normalized = $this->normalize( $text );
		$words      = preg_split( '/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY );
		return array_values(
			array_filter(
				$words,
				function ( string $word ): bool {
					return strlen( $word ) >= 3 && ! in_array( $word, self::STOP_WORDS, true );
				}
			)
		);
	}

	private function normalize( string $text ): string {
		$text = mb_strtolower( wp_strip_all_tags( $text ) );
		$text = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $text );
		return trim( $text );
	}
}
