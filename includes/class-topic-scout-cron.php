<?php
/**
 * Topic Scout weekly cron — gathers signals, calls AI, creates 'proposed' topics.
 *
 * Gathers GSC question candidates, striking-distance queries (pos 8-20), and orphan
 * pages; ranks signals; budget-guards; makes ONE chat_json call (cap 5 proposals/run);
 * normalises and deduplicates; creates swps_topic posts with status 'proposed'.
 * Also registers the 'proposed' post status and settings fields (Schedule tab).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWPS_Topic_Scout_Cron
 *
 * Runtime wiring for the weekly topic scout.
 */
class SWPS_Topic_Scout_Cron {

	/** WP-Cron hook name. */
	public const CRON_HOOK = 'swps_topic_scout';

	/** Backlog cap: skip run when proposed count ≥ this. */
	public const BACKLOG_CAP = 10;

	/** Max proposals stored per run. */
	public const MAX_PROPOSALS = 5;

	/** Meta key — primary target keyword. */
	public const META_KEYWORD = '_swps_target_keyword';

	/** Meta key — one-line rationale from AI. */
	public const META_RATIONALE = '_swps_rationale';

	/** Meta key — originating signal type (question|striking|orphan). */
	public const META_SIGNAL = '_swps_signal';

	/** Option — toggle the scout on/off. */
	public const OPT_ENABLED = 'swps_topic_scout_enabled';

	/** Option — auto-promote when queue runs dry. */
	public const OPT_AUTOPROMOTE = 'swps_topic_autopromote';

	/**
	 * GSC client.
	 *
	 * @var SWPS_Search_Console
	 */
	private SWPS_Search_Console $search_console;

	/**
	 * Topic queue.
	 *
	 * @var SWPS_Topic_Queue
	 */
	private SWPS_Topic_Queue $topic_queue;

	/**
	 * Duplicate checker.
	 *
	 * @var SWPS_Duplicate_Checker
	 */
	private SWPS_Duplicate_Checker $duplicate_checker;

	/**
	 * Wire cron, settings, and the 'proposed' post status.
	 *
	 * @param SWPS_Search_Console    $search_console    GSC client.
	 * @param SWPS_Topic_Queue       $topic_queue       Topic queue.
	 * @param SWPS_Duplicate_Checker $duplicate_checker Duplicate checker.
	 */
	public function __construct(
		SWPS_Search_Console $search_console,
		SWPS_Topic_Queue $topic_queue,
		SWPS_Duplicate_Checker $duplicate_checker
	) {
		$this->search_console    = $search_console;
		$this->topic_queue       = $topic_queue;
		$this->duplicate_checker = $duplicate_checker;

		add_action( self::CRON_HOOK, array( $this, 'run_scout' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Self-schedule (question-coverage precedent) so existing installs pick
		// the cron up on first load after the update.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Register the 'proposed' custom post status (matches existing status pattern).
	 */
	public static function register_proposed_status(): void {
		register_post_status(
			'proposed',
			array(
				'label'       => __( 'Proposed', 'stratawp-seo' ),
				'public'      => false,
				'internal'    => true,
				// translators: %s: count of proposed topics.
				'label_count' => _n_noop(
					'Proposed <span class="count">(%s)</span>',
					'Proposed <span class="count">(%s)</span>',
					'stratawp-seo'
				),
			)
		);
	}

	/** Schedule the weekly scout cron (activation hook). */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	/** Unschedule the cron (deactivation hook). */
	public static function unschedule_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Run the weekly topic scout.
	 *
	 * Gathers signals, ranks, budget-guards, calls AI once, creates proposals.
	 * Fully no-op when the feature is disabled or the backlog is full.
	 */
	public function run_scout(): void {
		if ( ! get_option( self::OPT_ENABLED, 1 ) ) {
			return;
		}

		// Backlog cap.
		$existing_proposed = $this->count_proposed();
		if ( $existing_proposed >= self::BACKLOG_CAP ) {
			SWPS_Generator::append_log( 'Topic Scout: backlog full (' . $existing_proposed . '/' . self::BACKLOG_CAP . '), skipping run.' );
			return;
		}

		// Gather signals.
		$question_candidates = $this->get_question_candidates();
		$striking            = $this->get_striking_distance_queries();
		$orphans             = $this->get_orphan_pages();

		if ( empty( $question_candidates ) && empty( $striking ) && empty( $orphans ) ) {
			SWPS_Generator::append_log( 'Topic Scout: no signals available, skipping run.' );
			return;
		}

		// Rank signals.
		$ranked = SWPS_Topic_Scout::rank_signals( $question_candidates, $striking, $orphans );
		if ( empty( $ranked ) ) {
			return;
		}

		// Budget guard.
		$budget_check = SWPS_Autopilot_Guardian::check_budget();
		if ( is_wp_error( $budget_check ) ) {
			SWPS_Generator::append_log( 'Topic Scout: budget exceeded, skipping AI call — ' . $budget_check->get_error_message() );
			return;
		}

		// Build prompt and call AI.
		$site_context = trim( (string) get_option( 'swps_site_niche', '' ) . ' ' . (string) get_option( 'swps_site_description', '' ) );
		$prompt       = SWPS_Topic_Scout::build_scout_prompt( $ranked, $site_context );
		$provider     = SWPS_Provider_Factory::create_ai_provider();
		$system       = 'You are an SEO content strategist. Return only valid JSON arrays.';

		$response = $provider->chat_json( $system, $prompt, 2048 );
		if ( is_wp_error( $response ) ) {
			SWPS_Generator::append_log( 'Topic Scout: AI call failed — ' . $response->get_error_message() );
			return;
		}

		// Parse proposals.
		$raw_proposals = $this->parse_proposals( $response );
		if ( empty( $raw_proposals ) ) {
			SWPS_Generator::append_log( 'Topic Scout: no valid proposals returned by AI.' );
			return;
		}

		$templates = array_keys( SWPS_Templates::get_templates() );
		$created   = 0;
		$skipped   = 0;

		foreach ( array_slice( $raw_proposals, 0, self::MAX_PROPOSALS ) as $row ) {
			$proposal = SWPS_Topic_Scout::normalize_proposal( (array) $row, $templates );
			if ( null === $proposal ) {
				++$skipped;
				continue;
			}

			// Dedupe against existing posts and existing queue topics (all statuses).
			$title = $proposal['title'];
			if ( $this->duplicate_checker->is_duplicate( $title ) ) {
				++$skipped;
				continue;
			}
			if ( $this->title_exists_in_queue( $title ) ) {
				++$skipped;
				continue;
			}

			// Enforce backlog cap per-iteration.
			if ( ( $existing_proposed + $created ) >= self::BACKLOG_CAP ) {
				break;
			}

			$topic_id = $this->topic_queue->create_topic( $title, '', $proposal['template'] );
			if ( is_wp_error( $topic_id ) ) {
				SWPS_Generator::append_log( 'Topic Scout: failed to create topic — ' . $topic_id->get_error_message() );
				continue;
			}

			// Set proposed status (overrides default 'queued').
			wp_update_post(
				array(
					'ID'          => $topic_id,
					'post_status' => 'proposed',
				)
			);

			// Persist signal metas.
			update_post_meta( $topic_id, self::META_KEYWORD, sanitize_text_field( $proposal['keyword'] ) );
			update_post_meta( $topic_id, self::META_RATIONALE, sanitize_textarea_field( $proposal['rationale'] ) );

			// Best-fit signal type from ranked list.
			$signal_type = $this->find_signal_type( $title, $ranked );
			update_post_meta( $topic_id, self::META_SIGNAL, sanitize_text_field( $signal_type ) );

			++$created;
		}

		SWPS_Generator::append_log(
			sprintf(
				'Topic Scout: created %d proposal(s), skipped %d (duplicates/junk).',
				$created,
				$skipped
			)
		);
	}

	/**
	 * Get question topic candidates (Feature 7 miner output, no re-mining).
	 *
	 * @return array<int,array<string,mixed>> Rows of {q, impressions}.
	 */
	private function get_question_candidates(): array {
		return (array) get_option( SWPS_Question_Coverage::OPTION_CANDIDATES, array() );
	}

	/**
	 * Get striking-distance queries (position 8-20). No-op when GSC unconnected.
	 *
	 * @return array<int,array<string,mixed>> Rows of {query, impressions, position}.
	 */
	private function get_striking_distance_queries(): array {
		if ( ! $this->search_console->is_connected() ) {
			return array();
		}

		$raw = $this->search_console->get_query_page_rows( 90 );
		if ( empty( $raw ) ) {
			return array();
		}

		// Group by query, keep best position per query.
		$by_query = array();
		foreach ( $raw as $row ) {
			$query    = (string) ( $row['keys'][0] ?? '' );
			$page     = (string) ( $row['keys'][1] ?? '' );
			$position = (float) ( $row['position'] ?? 0 );

			if ( '' === $query || '' === $page ) {
				continue;
			}
			if ( $position < 8 || $position > 20 ) {
				continue;
			}

			$impressions = (int) ( $row['impressions'] ?? 0 );
			$post_id     = url_to_postid( $page );

			// "No dedicated post" = not mapping to a post, OR low title-overlap.
			if ( $post_id > 0 && 'post' === get_post_type( $post_id ) ) {
				$post = get_post( $post_id );
				if ( $post instanceof WP_Post ) {
					$similarity = SWPS_Question_Coverage::tokens_similar( $query, $post->post_title );
					if ( $similarity >= 0.6 ) {
						// Query already has a dedicated post — skip.
						continue;
					}
				}
			}

			if ( ! isset( $by_query[ $query ] ) || $impressions > $by_query[ $query ]['impressions'] ) {
				$by_query[ $query ] = array(
					'query'       => $query,
					'impressions' => $impressions,
					'position'    => $position,
				);
			}
		}

		return array_values( $by_query );
	}

	/**
	 * Get orphan pages (SQL reuses SWPS_Internal_Links_Admin::get_stats() :84-140 pattern).
	 *
	 * @return array<int,array<string,mixed>> Rows of {ID, post_title, post_date}.
	 */
	private function get_orphan_pages(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'swps_link_graph';
		$sql   = "SELECT p.ID, p.post_title, p.post_date FROM {$wpdb->posts} p
				  WHERE p.post_type = 'post'
				  AND p.post_status = 'publish'
				  AND p.ID NOT IN (
				      SELECT DISTINCT target_post_id FROM {$table}
				      WHERE status IN ('existing', 'inserted')
				  )
				  ORDER BY p.post_date DESC
				  LIMIT 20";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphans = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $orphans ) ? $orphans : array();
	}

	/**
	 * Count existing 'proposed' topics in the queue.
	 *
	 * @return int Count of proposed topics.
	 */
	private function count_proposed(): int {
		$posts = get_posts(
			array(
				'post_type'      => SWPS_Topic_Queue::POST_TYPE,
				'post_status'    => 'proposed',
				'posts_per_page' => self::BACKLOG_CAP + 1,
				'fields'         => 'ids',
			)
		);
		return count( $posts );
	}

	/**
	 * Whether a title already exists in the topic queue (any status).
	 *
	 * @param string $title Proposed title.
	 * @return bool True when a similar title (≥85% similarity) exists.
	 */
	private function title_exists_in_queue( string $title ): bool {
		$existing = get_posts(
			array(
				'post_type'      => SWPS_Topic_Queue::POST_TYPE,
				'post_status'    => array( 'queued', 'generating', 'retrying', 'proposed' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		foreach ( $existing as $post_id ) {
			$existing_title = get_the_title( (int) $post_id );
			$similarity     = 0;
			similar_text( strtolower( $title ), strtolower( $existing_title ), $similarity );
			if ( $similarity >= 85 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse a chat_json() response into raw proposal rows.
	 *
	 * @param array<string,mixed> $response Decoded chat_json() response.
	 * @return array<int,mixed>   Raw proposal items.
	 */
	private function parse_proposals( array $response ): array {
		// chat_json returns a decoded array already; check for a nested 'proposals' key
		// or treat the array itself as the list.
		if ( isset( $response['proposals'] ) && is_array( $response['proposals'] ) ) {
			return array_values( $response['proposals'] );
		}

		// If it's an array of objects (direct JSON array response), use it as-is.
		// chat_json may wrap the array in ['0' => ..., '1' => ...] or return it flat.
		if ( ! empty( $response ) ) {
			$first = reset( $response );
			if ( is_array( $first ) && isset( $first['title'] ) ) {
				return array_values( $response );
			}
		}

		return array();
	}

	/**
	 * Find the best-matching signal type for a proposed title.
	 *
	 * @param string                         $title  Proposed title.
	 * @param array<int,array<string,mixed>> $ranked Ranked signals.
	 * @return string Signal type (question|striking|orphan) or 'ai'.
	 */
	private function find_signal_type( string $title, array $ranked ): string {
		$best_score = 0.0;
		$best_type  = 'ai';
		foreach ( $ranked as $signal ) {
			$sim = SWPS_Question_Coverage::tokens_similar( $title, (string) ( $signal['text'] ?? '' ) );
			if ( $sim > $best_score ) {
				$best_score = $sim;
				$best_type  = (string) ( $signal['type'] ?? 'ai' );
			}
		}
		return $best_type;
	}

	/**
	 * Register Topic Autopilot settings section on the Schedule tab.
	 */
	public function register_settings(): void {
		add_settings_section(
			'swps_topic_scout_section',
			__( 'Topic Autopilot', 'stratawp-seo' ),
			array( $this, 'render_section_intro' ),
			'stratawp-seo'
		);

		register_setting(
			'stratawp-seo',
			self::OPT_ENABLED,
			array( 'sanitize_callback' => 'absint' )
		);

		register_setting(
			'stratawp-seo',
			self::OPT_AUTOPROMOTE,
			array( 'sanitize_callback' => 'absint' )
		);

		add_settings_field(
			self::OPT_ENABLED,
			__( 'Enable Topic Scout', 'stratawp-seo' ),
			array( $this, 'render_enabled_field' ),
			'stratawp-seo',
			'swps_topic_scout_section',
			array( 'label_for' => self::OPT_ENABLED )
		);

		add_settings_field(
			self::OPT_AUTOPROMOTE,
			__( 'Auto-promote top proposal', 'stratawp-seo' ),
			array( $this, 'render_autopromote_field' ),
			'stratawp-seo',
			'swps_topic_scout_section',
			array( 'label_for' => self::OPT_AUTOPROMOTE )
		);
	}

	/** Section intro text. */
	public function render_section_intro(): void {
		echo '<p>' . esc_html__( 'Weekly scout mines Search Console signals and orphan pages to suggest data-backed topics for your queue.', 'stratawp-seo' ) . '</p>';
	}

	/** Enabled toggle field. */
	public function render_enabled_field(): void {
		$val = (int) get_option( self::OPT_ENABLED, 1 );
		printf(
			'<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> ',
			esc_attr( self::OPT_ENABLED ),
			checked( 1, $val, false )
		);
		echo '<label for="' . esc_attr( self::OPT_ENABLED ) . '">'
			. esc_html__( 'Run the topic scout weekly (inert without GSC or question candidates)', 'stratawp-seo' )
			. '</label>';
	}

	/** Auto-promote toggle field. */
	public function render_autopromote_field(): void {
		$val = (int) get_option( self::OPT_AUTOPROMOTE, 0 );
		printf(
			'<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> ',
			esc_attr( self::OPT_AUTOPROMOTE ),
			checked( 1, $val, false )
		);
		echo '<label for="' . esc_attr( self::OPT_AUTOPROMOTE ) . '">'
			. esc_html__( 'When the queue runs dry, automatically promote the top proposal instead of using a random AI topic', 'stratawp-seo' )
			. '</label>';
		echo ' <p class="description">' . esc_html__( 'Default: OFF. Promoted topics are auditable via the Rationale shown in the topic queue.', 'stratawp-seo' ) . '</p>';
	}
}
