<?php
/**
 * Question Coverage — the AEO question-coverage engine.
 *
 * Phase A: pure formatting utility consumed by SWPS_AEO_Optimizer::do_propose()
 * to inject coverage gaps as qa-insert instructions into the proposal prompt.
 *
 * Phase B: GSC question demand mining. A weekly cron pulls query+page rows
 * from Search Console, filters question-form queries, matches them against
 * each post's headings (fuzzy token overlap), and stores:
 *   - per-post meta `_swps_unanswered_questions` (≤10, with impressions)
 *   - site-wide option `swps_question_topic_candidates` (≤20) for questions
 *     landing on non-post pages or posts ranking >20.
 * An AJAX handler queues a candidate as a topic via SWPS_Topic_Queue.
 *
 * All matchers are pure statics (unit-tested, no WP). Runtime methods are
 * fully no-op when GSC is unconnected — no network calls, no AI calls.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWPS_Question_Coverage
 *
 * Pure matchers + GSC demand miner for the AEO question-coverage engine.
 */
class SWPS_Question_Coverage {

	public const CRON_HOOK         = 'swps_question_mine';
	public const META_UNANSWERED   = '_swps_unanswered_questions';
	public const OPTION_CANDIDATES = 'swps_question_topic_candidates';

	public const MAX_PER_POST   = 10;
	public const MAX_CANDIDATES = 20;

	/** Similarity threshold for a question to count as "answered by a heading". */
	private const MATCH_THRESHOLD = 0.6;

	/** Stopwords stripped before token comparison. */
	private const STOPWORDS = array(
		'a',
		'an',
		'and',
		'are',
		'be',
		'can',
		'do',
		'does',
		'for',
		'how',
		'i',
		'in',
		'is',
		'it',
		'my',
		'of',
		'on',
		'or',
		'should',
		'the',
		'to',
		'vs',
		'what',
		'when',
		'where',
		'which',
		'who',
		'why',
		'will',
		'with',
		'you',
		'your',
	);

	/**
	 * Search Console client (page+query rows).
	 *
	 * @var SWPS_Search_Console
	 */
	private SWPS_Search_Console $search_console;

	/**
	 * Topic queue (candidate → queued topic).
	 *
	 * @var SWPS_Topic_Queue
	 */
	private SWPS_Topic_Queue $topic_queue;

	/**
	 * Wire the weekly mining cron + the queue-topic AJAX handler.
	 *
	 * @param SWPS_Search_Console $search_console GSC client.
	 * @param SWPS_Topic_Queue    $topic_queue    Topic queue.
	 */
	public function __construct( SWPS_Search_Console $search_console, SWPS_Topic_Queue $topic_queue ) {
		$this->search_console = $search_console;
		$this->topic_queue    = $topic_queue;

		add_action( self::CRON_HOOK, array( $this, 'mine' ) );
		add_action( 'wp_ajax_swps_queue_question_topic', array( $this, 'ajax_queue_topic' ) );

		// Self-schedule (AEO-optimizer precedent) so existing installs pick the
		// cron up without re-activation.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the mining cron (deactivation hook).
	 */
	public static function unschedule_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// -------------------------------------------------------------------------
	// Pure helpers (unit-tested, no WP)
	// -------------------------------------------------------------------------

	/**
	 * Format a compact prompt instruction from sub-query gap data.
	 *
	 * Missing questions are listed before partial questions. Answered
	 * questions are excluded entirely. Returns an empty string when there
	 * are no missing or partial queries (so callers can do a simple
	 * `if ( '' !== $instruction )` guard).
	 *
	 * @param array<int, array<string, mixed>> $sub_queries Sub-query list from coverage scorer.
	 * @param int                              $cap         Maximum number of questions to include (default 3).
	 * @return string Prompt fragment, or '' when nothing to add.
	 */
	public static function format_gap_instruction( array $sub_queries, int $cap = 3 ): string {
		$missing = array();
		$partial = array();

		foreach ( $sub_queries as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$status = (string) ( $item['status'] ?? '' );
			$q      = trim( (string) ( $item['q'] ?? '' ) );
			if ( '' === $q ) {
				continue;
			}
			if ( 'missing' === $status ) {
				$missing[] = $q;
			} elseif ( 'partial' === $status ) {
				$partial[] = $q;
			}
			// 'answered' is skipped intentionally.
		}

		// Missing questions first, then partial.
		$ordered = array_merge( $missing, $partial );

		if ( empty( $ordered ) ) {
			return '';
		}

		$capped = array_slice( $ordered, 0, max( 1, $cap ) );

		$list = implode(
			'; ',
			array_map(
				static function ( string $q ): string {
					return '"' . $q . '"';
				},
				$capped
			)
		);

		return 'Also add qa inserts answering these searcher questions the post does not yet fully cover: ' . $list . '.';
	}

	/**
	 * Format a prompt instruction from GSC-mined unanswered questions.
	 *
	 * Sibling of format_gap_instruction() with the Search Console source
	 * labeled and impressions included for prioritisation context.
	 *
	 * @param array<int, array<string, mixed>> $questions Rows of {q, impressions}.
	 * @param int                              $cap       Maximum questions to include (default 3).
	 * @return string Prompt fragment, or '' when nothing to add.
	 */
	public static function format_unanswered_instruction( array $questions, int $cap = 3 ): string {
		$parts = array();
		foreach ( $questions as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$q = trim( (string) ( $row['q'] ?? '' ) );
			if ( '' === $q ) {
				continue;
			}
			$impressions = (int) ( $row['impressions'] ?? 0 );
			$parts[]     = '"' . $q . '" (' . $impressions . ' impressions)';
			if ( count( $parts ) >= max( 1, $cap ) ) {
				break;
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return 'Google Search Console shows real searchers ask these questions this post does not answer — add qa inserts for them: '
			. implode( '; ', $parts ) . '.';
	}

	/**
	 * Normalized token-overlap similarity between two short strings.
	 *
	 * Both strings are lowercased, punctuation-stripped, tokenised, stopword-
	 * filtered, and lightly stemmed (trailing ing/ed/es/s). Tokens match when
	 * equal or when one is a ≥4-char prefix of the other (store/storing).
	 * Returns Jaccard overlap (intersection / union) in [0, 1].
	 *
	 * @param string $a First string.
	 * @param string $b Second string.
	 * @return float Similarity 0.0–1.0.
	 */
	public static function tokens_similar( string $a, string $b ): float {
		$tokens_a = self::normalize_tokens( $a );
		$tokens_b = self::normalize_tokens( $b );

		if ( empty( $tokens_a ) || empty( $tokens_b ) ) {
			return 0.0;
		}

		$matched_b = array();
		$intersect = 0;
		foreach ( $tokens_a as $ta ) {
			foreach ( $tokens_b as $i => $tb ) {
				if ( isset( $matched_b[ $i ] ) ) {
					continue;
				}
				if ( self::tokens_match( $ta, $tb ) ) {
					$matched_b[ $i ] = true;
					++$intersect;
					break;
				}
			}
		}

		$union = count( $tokens_a ) + count( $tokens_b ) - $intersect;
		if ( $union <= 0 ) {
			return 0.0;
		}

		return $intersect / $union;
	}

	/**
	 * Return the questions NOT answered by any heading (similarity < 0.6).
	 *
	 * @param string[] $questions Question strings.
	 * @param string[] $headings  Heading strings extracted from post content.
	 * @return string[] Unanswered questions (original order preserved).
	 */
	public static function questions_unanswered( array $questions, array $headings ): array {
		$unanswered = array();
		foreach ( $questions as $question ) {
			$question = (string) $question;
			$answered = false;
			foreach ( $headings as $heading ) {
				if ( self::tokens_similar( $question, (string) $heading ) >= self::MATCH_THRESHOLD ) {
					$answered = true;
					break;
				}
			}
			if ( ! $answered ) {
				$unanswered[] = $question;
			}
		}
		return array_values( $unanswered );
	}

	/**
	 * Lowercase, strip punctuation, tokenise, drop stopwords, light-stem.
	 *
	 * @param string $text Input string.
	 * @return string[] Normalised tokens.
	 */
	private static function normalize_tokens( string $text ): array {
		$text   = strtolower( $text );
		$text   = (string) preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		$tokens = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $tokens ) {
			return array();
		}

		$out = array();
		foreach ( $tokens as $token ) {
			if ( in_array( $token, self::STOPWORDS, true ) ) {
				continue;
			}
			$out[] = self::stem( $token );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Crude suffix-stripping stem: ing, ed, es, s (keeps stems ≥3 chars).
	 *
	 * @param string $token Lowercase token.
	 * @return string Stemmed token.
	 */
	private static function stem( string $token ): string {
		foreach ( array( 'ing', 'ed', 'es', 's' ) as $suffix ) {
			$len = strlen( $suffix );
			if ( strlen( $token ) - $len >= 3 && str_ends_with( $token, $suffix ) ) {
				return substr( $token, 0, -$len );
			}
		}
		return $token;
	}

	/**
	 * Two stemmed tokens match when equal, or one is a ≥4-char prefix of the other.
	 *
	 * @param string $a Token.
	 * @param string $b Token.
	 * @return bool Whether the tokens count as the same word.
	 */
	private static function tokens_match( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		$min = min( strlen( $a ), strlen( $b ) );
		if ( $min < 4 ) {
			return false;
		}
		return str_starts_with( $a, $b ) || str_starts_with( $b, $a );
	}

	// -------------------------------------------------------------------------
	// Demand miner (weekly cron)
	// -------------------------------------------------------------------------

	/**
	 * Cron handler: mine GSC question queries and store unanswered lists.
	 *
	 * Fully no-op when GSC is unconnected (no network, no AI).
	 */
	public function mine(): void {
		if ( ! $this->search_console->is_connected() ) {
			return;
		}

		$rows = $this->search_console->get_query_page_rows( 90 );
		if ( empty( $rows ) ) {
			return;
		}

		$per_post   = array();
		$candidates = array();

		foreach ( $rows as $row ) {
			$query = (string) ( $row['keys'][0] ?? '' );
			$page  = (string) ( $row['keys'][1] ?? '' );
			if ( '' === $query || '' === $page ) {
				continue;
			}
			if ( ! SWPS_Citation_Tracker::is_question_query( $query ) ) {
				continue;
			}

			$impressions = (int) ( $row['impressions'] ?? 0 );
			$position    = (float) ( $row['position'] ?? 0 );
			$post_id     = url_to_postid( $page );

			if ( $post_id > 0 && 'post' === get_post_type( $post_id ) ) {
				$per_post[ $post_id ][] = array(
					'q'           => $query,
					'impressions' => $impressions,
				);
				// Posts ranking deep are also topic-gap signals.
				if ( $position > 20 ) {
					$candidates[ $query ] = max( $candidates[ $query ] ?? 0, $impressions );
				}
			} else {
				// Non-post page (or unmappable URL): site-wide topic candidate.
				$candidates[ $query ] = max( $candidates[ $query ] ?? 0, $impressions );
			}
		}

		foreach ( $per_post as $post_id => $questions ) {
			$this->store_unanswered_for_post( (int) $post_id, $questions );
		}

		$this->store_candidates( $candidates );
	}

	/**
	 * Match a post's mined questions against its headings; persist ≤10 unanswered.
	 *
	 * @param int                              $post_id   Post ID.
	 * @param array<int, array<string, mixed>> $questions Rows of {q, impressions}.
	 */
	private function store_unanswered_for_post( int $post_id, array $questions ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$headings   = SWPS_AEO_Markup_Scorer::extract_headings( $post->post_content );
		$headings[] = $post->post_title;

		$q_strings  = array_column( $questions, 'q' );
		$unanswered = self::questions_unanswered( $q_strings, $headings );

		if ( empty( $unanswered ) ) {
			delete_post_meta( $post_id, self::META_UNANSWERED );
			return;
		}

		$keep = array();
		foreach ( $questions as $row ) {
			if ( in_array( $row['q'], $unanswered, true ) ) {
				$keep[] = $row;
			}
		}
		usort(
			$keep,
			static function ( array $x, array $y ): int {
				return (int) $y['impressions'] <=> (int) $x['impressions'];
			}
		);
		$keep = array_slice( $keep, 0, self::MAX_PER_POST );

		update_post_meta( $post_id, self::META_UNANSWERED, $keep );
	}

	/**
	 * Persist site-wide topic candidates (≤20, impressions-ordered).
	 *
	 * @param array<string, int> $candidates Map of query → impressions.
	 */
	private function store_candidates( array $candidates ): void {
		if ( empty( $candidates ) ) {
			// A successful run that found nothing must clear last week's list.
			update_option( self::OPTION_CANDIDATES, array(), false );
			return;
		}
		arsort( $candidates );
		$rows = array();
		foreach ( array_slice( $candidates, 0, self::MAX_CANDIDATES, true ) as $q => $impressions ) {
			$rows[] = array(
				'q'           => (string) $q,
				'impressions' => (int) $impressions,
			);
		}
		update_option( self::OPTION_CANDIDATES, $rows, false );
	}

	// -------------------------------------------------------------------------
	// AJAX: queue a candidate as a topic
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: create a queued topic from a question candidate and
	 * remove it from the candidates list.
	 */
	public function ajax_queue_topic(): void {
		check_ajax_referer( 'swps_question_coverage', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'unauthorized' ), 403 );
		}

		$question = isset( $_POST['question'] ) ? sanitize_text_field( wp_unslash( $_POST['question'] ) ) : '';
		if ( '' === $question ) {
			wp_send_json_error( array( 'message' => 'missing_question' ), 400 );
		}

		$candidates = (array) get_option( self::OPTION_CANDIDATES, array() );
		$found      = false;
		$remaining  = array();
		foreach ( $candidates as $row ) {
			if ( is_array( $row ) && (string) ( $row['q'] ?? '' ) === $question ) {
				$found = true;
				continue;
			}
			$remaining[] = $row;
		}

		if ( ! $found ) {
			wp_send_json_error( array( 'message' => 'candidate_not_found' ), 404 );
		}

		$topic_id = $this->topic_queue->create_topic(
			ucfirst( $question ),
			'',
			'auto',
			__( 'Suggested by StrataWP SEO question mining (Google Search Console question with no answering post).', 'stratawp-seo' )
		);

		if ( is_wp_error( $topic_id ) ) {
			wp_send_json_error( array( 'message' => $topic_id->get_error_message() ), 500 );
		}

		update_option( self::OPTION_CANDIDATES, $remaining, false );

		wp_send_json_success(
			array(
				'topic_id'  => $topic_id,
				'remaining' => count( $remaining ),
			)
		);
	}
}
