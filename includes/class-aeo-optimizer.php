<?php
/**
 * AEO Optimizer — admin page + AJAX backend for the AEO Optimize feature.
 *
 * Mirrors the SWPS_Auto_Optimize pattern. Provides 6 AJAX handlers:
 *   - scan_chunk : score 10 posts at a time (chunked re-scan)
 *   - score      : re-score a single post
 *   - propose    : generate AI proposal (edits + inserts + schema) for one post
 *   - apply      : apply selected edits/inserts/schema, snapshot for undo, re-score
 *   - undo       : restore the last snapshot
 *   - dismiss    : hide a post from the queue
 *
 * Dependencies are constructor-injected.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Optimizer {

	public const META_PROPOSAL    = '_swps_aeo_proposal';
	public const META_SNAPSHOT    = '_swps_aeo_snapshot';
	public const META_DISMISSED   = '_swps_aeo_dismissed';
	public const META_SCHEMA_TYPE = '_swps_aeo_schema_type';
	public const META_SCHEMA_JSON = '_swps_aeo_schema_json';

	private SWPS_AEO_Scorer           $scorer;
	private SWPS_AEO_Schema_Generator $schema_gen;
	private SWPS_AI_Provider          $ai_provider;
	private SWPS_Cost_Tracker         $cost;
	private SWPS_Rate_Limiter         $rate;

	public function __construct(
		SWPS_AEO_Scorer $scorer,
		SWPS_AEO_Schema_Generator $schema_gen,
		SWPS_AI_Provider $ai_provider,
		SWPS_Cost_Tracker $cost,
		SWPS_Rate_Limiter $rate
	) {
		$this->scorer      = $scorer;
		$this->schema_gen  = $schema_gen;
		$this->ai_provider = $ai_provider;
		$this->cost        = $cost;
		$this->rate        = $rate;

		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_swps_aeo_scan_chunk', array( $this, 'ajax_scan_chunk' ) );
		add_action( 'wp_ajax_swps_aeo_propose',    array( $this, 'ajax_propose' ) );
		add_action( 'wp_ajax_swps_aeo_apply',      array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_swps_aeo_undo',       array( $this, 'ajax_undo' ) );
		add_action( 'wp_ajax_swps_aeo_dismiss',    array( $this, 'ajax_dismiss' ) );
		add_action( 'wp_ajax_swps_aeo_score',      array( $this, 'ajax_score' ) );

		add_action( 'swps_aeo_sweep_proposals', array( $this, 'sweep_proposals' ) );
		if ( ! wp_next_scheduled( 'swps_aeo_sweep_proposals' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'swps_aeo_sweep_proposals' );
		}
	}

	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'AEO Optimize', 'stratawp-seo' ),
			__( 'AEO Optimize', 'stratawp-seo' ),
			'manage_options',
			'swps-aeo-optimize',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
		}
		$threshold  = (int) get_option( 'swps_aeo_threshold', SWPS_AEO_Scorer::DEFAULT_THRESHOLD );
		$post_types = (array) get_option( 'swps_aeo_post_types', array( 'post', 'page' ) );
		// Question topic candidates mined from GSC (template renders the card).
		$question_candidates = (array) get_option( SWPS_Question_Coverage::OPTION_CANDIDATES, array() );
		// Make vars available to template (Task 15 creates the actual template).
		if ( file_exists( SWPS_PLUGIN_DIR . 'templates/aeo-page.php' ) ) {
			require SWPS_PLUGIN_DIR . 'templates/aeo-page.php';
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'AEO Optimize', 'stratawp-seo' ) . '</h1>';
			echo '<p>' . esc_html__( 'Template pending (Task 15).', 'stratawp-seo' ) . '</p></div>';
		}
	}

	public function enqueue_assets( string $hook ): void {
		// Admin page assets (Task 15/16 provide aeo.css and aeo-optimizer.js).
		if ( 'stratawp-seo_page_swps-aeo-optimize' === $hook ) {
			if ( file_exists( SWPS_PLUGIN_DIR . 'admin/css/aeo.css' ) ) {
				wp_enqueue_style( 'swps-aeo', SWPS_PLUGIN_URL . 'admin/css/aeo.css', array(), $this->asset_ver( 'admin/css/aeo.css' ) );
			}
			if ( file_exists( SWPS_PLUGIN_DIR . 'admin/js/aeo-optimizer.js' ) ) {
				wp_enqueue_script(
					'swps-aeo-optimizer',
					SWPS_PLUGIN_URL . 'admin/js/aeo-optimizer.js',
					array( 'jquery' ),
					$this->asset_ver( 'admin/js/aeo-optimizer.js' ),
					true
				);
				wp_localize_script( 'swps-aeo-optimizer', 'swpsAeo', $this->localize_data( true ) );
			}
		}

		// Editor-panel assets (Task 17/18). Optimizer enqueues them on post edit screens.
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& file_exists( SWPS_PLUGIN_DIR . 'admin/js/aeo-editor-panel.js' ) ) {
			wp_enqueue_style( 'swps-aeo', SWPS_PLUGIN_URL . 'admin/css/aeo.css', array(), $this->asset_ver( 'admin/css/aeo.css' ) );
			wp_enqueue_script(
				'swps-aeo-editor-panel',
				SWPS_PLUGIN_URL . 'admin/js/aeo-editor-panel.js',
				array( 'jquery', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ),
				$this->asset_ver( 'admin/js/aeo-editor-panel.js' ),
				true
			);
			wp_localize_script( 'swps-aeo-editor-panel', 'swpsAeo', $this->localize_data( false ) );
		}
	}

	/**
	 * Build a cache-busting asset version from the file's mtime, falling
	 * back to SWPS_VERSION if the file isn't readable.
	 *
	 * Using mtime means any code change to an AEO asset auto-invalidates
	 * the browser cache without requiring a SWPS_VERSION bump — fixing the
	 * pattern that caused v4.6.0/4.6.1/4.6.2 to each need a version bump
	 * just to ship a bugfix. PHP's stat cache makes filemtime() ~free on
	 * repeated calls within the same request.
	 *
	 * Scope: AEO assets only. Other plugin assets keep SWPS_VERSION until
	 * a broader cleanup adopts the same pattern.
	 *
	 * @param string $relative_path Path relative to SWPS_PLUGIN_DIR (no leading slash).
	 */
	private function asset_ver( string $relative_path ): string {
		$full  = SWPS_PLUGIN_DIR . $relative_path;
		$mtime = is_readable( $full ) ? filemtime( $full ) : false;
		return false !== $mtime ? (string) $mtime : SWPS_VERSION;
	}

	/**
	 * @param bool $include_initial_results When true, includes the persisted queue
	 *   data so the JS can render the queue on page load without re-scanning.
	 *   Only the optimizer page needs this; the editor panel doesn't.
	 * @return array<string, mixed>
	 */
	private function localize_data( bool $include_initial_results = false ): array {
		$data = array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'swps_nonce' ),
			'threshold' => (int) get_option( 'swps_aeo_threshold', SWPS_AEO_Scorer::DEFAULT_THRESHOLD ),
			'i18n'      => array(
				'scanning'       => __( 'Scoring posts...', 'stratawp-seo' ),
				'proposing'      => __( 'Generating proposal — calling AI...', 'stratawp-seo' ),
				'applying'       => __( 'Applying changes...', 'stratawp-seo' ),
				'undoing'        => __( 'Undoing last apply...', 'stratawp-seo' ),
				'generate'       => __( 'Generate proposal', 'stratawp-seo' ),
				'review'         => __( 'Review', 'stratawp-seo' ),
				'apply'          => __( 'Apply selected', 'stratawp-seo' ),
				'cancel'         => __( 'Cancel', 'stratawp-seo' ),
				'dismiss'        => __( 'Dismiss', 'stratawp-seo' ),
				'noPosts'        => __( 'Nothing below the threshold. Lower the threshold or re-scan.', 'stratawp-seo' ),
				'genericFail'    => __( 'Request failed.', 'stratawp-seo' ),
				'projected'      => __( 'Projected score', 'stratawp-seo' ),
				'schemaSection'  => __( 'Schema (new)', 'stratawp-seo' ),
				'insertsSection' => __( 'Structural inserts', 'stratawp-seo' ),
				'editsSection'   => __( 'Edits', 'stratawp-seo' ),
			),
		);
		if ( $include_initial_results ) {
			$data['initialResults'] = $this->get_scored_posts();
		}
		return $data;
	}

	/**
	 * Load the persisted AEO queue from post meta — used to populate the
	 * optimizer page on load (no AJAX, no re-scoring).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_scored_posts(): array {
		global $wpdb;
		$types = (array) get_option( 'swps_aeo_post_types', array( 'post', 'page' ) );
		if ( empty( $types ) ) {
			return array();
		}
		// Build a safe IN(...) clause for post_type — types come from a sanitized option.
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only queue load, no caching benefit.
		$sql  = "SELECT p.ID, p.post_title, p.post_type, m.meta_value AS score
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE m.meta_key = %s
				  AND p.post_status = 'publish'
				  AND p.post_type IN ( {$placeholders} )
				ORDER BY CAST(m.meta_value AS UNSIGNED) ASC
				LIMIT 500";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( SWPS_AEO_Scorer::META_TOTAL ), $types ) ) );

		if ( empty( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$post_id = (int) $row->ID;
			if ( get_post_meta( $post_id, self::META_DISMISSED, true ) ) {
				continue;
			}
			$coverage  = get_post_meta( $post_id, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'coverage', true );
			$results[] = array(
				'post_id'      => $post_id,
				'title'        => (string) $row->post_title,
				'permalink'    => get_permalink( $post_id ) ?: '',
				'edit_url'     => get_edit_post_link( $post_id, 'raw' ) ?: '',
				'post_type'    => (string) $row->post_type,
				'score'        => (int) $row->score,
				'subscores'    => array(
					'extractability' => (int) get_post_meta( $post_id, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'extractability', true ),
					'markup'         => (int) get_post_meta( $post_id, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'markup', true ),
					'authority'      => (int) get_post_meta( $post_id, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'authority', true ),
					'coverage'       => '' === $coverage ? null : (int) $coverage,
				),
				'has_proposal' => '' !== get_post_meta( $post_id, self::META_PROPOSAL, true ),
			);
		}

		// Enrich with crawl recency and AI-visit counts — two batched queries.
		$post_ids    = array_column( $results, 'post_id' );
		$crawl_map   = SWPS_Visibility_Funnel::crawl_stats_for_posts( $post_ids, 30 );
		$visit_map   = SWPS_Visibility_Funnel::ai_visits_for_posts( $post_ids, 30 );

		foreach ( $results as &$r ) {
			$pid   = $r['post_id'];
			$crawl = $crawl_map[ $pid ] ?? null;

			$r['last_crawl_at']  = $crawl ? (string) $crawl['last_crawl_at'] : null;
			$r['last_crawl_ago'] = $crawl && $crawl['last_crawl_at']
				? human_time_diff( strtotime( (string) $crawl['last_crawl_at'] ), time() ) . ' ago'
				: null;
			$r['last_bot']       = $crawl ? (string) $crawl['last_bot'] : null;
			$r['ai_visits_30d']  = isset( $visit_map[ $pid ] ) ? (int) $visit_map[ $pid ]['ai_visits'] : 0;
		}
		unset( $r );

		return $results;
	}

	private function verify_request(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'unauthorized' ), 403 );
		}
	}

	public function ajax_scan_chunk(): void {
		$this->verify_request();
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		wp_send_json_success( $this->do_scan_chunk( $offset, 10 ) );
	}

	/**
	 * Score a chunk of posts starting at $offset.
	 *
	 * @param int $offset Zero-based post offset.
	 * @param int $limit  Number of posts to process (1–100).
	 * @return array{scored:int,next_offset:int,total:int,done:bool,results:array<int,array<string,mixed>>}
	 */
	public function do_scan_chunk( int $offset, int $limit = 10 ): array {
		$types = (array) get_option( 'swps_aeo_post_types', array( 'post', 'page' ) );

		$query = new WP_Query( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'offset'         => $offset,
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		) );

		$results = array();
		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( get_post_meta( $post_id, self::META_DISMISSED, true ) ) {
				continue;
			}
			$r         = $this->scorer->score_post( $post_id );
			$post      = get_post( $post_id );
			$results[] = array(
				'post_id'      => $post_id,
				'title'        => $post ? $post->post_title : '',
				'permalink'    => get_permalink( $post_id ) ?: '',
				'edit_url'     => get_edit_post_link( $post_id, 'raw' ) ?: '',
				'post_type'    => $post ? $post->post_type : '',
				'score'        => $r['total'],
				'subscores'    => $r['subscores'],
				'has_proposal' => '' !== get_post_meta( $post_id, self::META_PROPOSAL, true ),
			);
		}

		$total = (int) $query->found_posts;
		$next  = $offset + count( $query->posts );

		return array(
			'scored'      => count( $results ),
			'next_offset' => $next,
			'total'       => $total,
			'done'        => $next >= $total,
			'results'     => $results,
		);
	}

	public function ajax_score(): void {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'invalid post_id' ), 400 );
		}
		wp_send_json_success( $this->do_score( $post_id ) );
	}

	/**
	 * Re-score a single post and return the score data.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public function do_score( int $post_id ): array {
		return $this->scorer->score_post( $post_id );
	}

	public function ajax_propose(): void {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'invalid_post_id' ), 400 );
		}
		$result = $this->do_propose( $post_id );
		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ), $result['http_status'] ?? 500 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Generate an AI proposal for a post.
	 *
	 * On success returns array with key 'proposal'.
	 * On error returns array with keys 'error' (string) and 'http_status' (int).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public function do_propose( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found', 'http_status' => 404 );
		}

		// Detect expected schema type from content.
		$markup        = new SWPS_AEO_Markup_Scorer();
		$expected_type = $markup->infer_schema_type( $post->post_content, $post->post_title );

		$system = 'You optimize blog posts for AI search citation (AEO). ' .
			'Return concrete find/replace edits, structural inserts, and schema if applicable.';
		$user   = sprintf(
			"Title: %s\nDetected schema type: %s\n\nPost HTML (truncated):\n%s\n\n" .
			'Return JSON with exactly these keys: ' .
			'{"edits":[{"find":"...","replace":"...","reason":"..."}], ' .
			'"inserts":[{"kind":"qa|tldr|list|defn","anchor":"<h2 text> or top","html":"<...>","reason":"..."}], ' .
			'"schema":{"type":"howto|recipe|product|review|faqpage|null","reason":"..."}, ' .
			'"projected_score":<int 0-100>}.',
			$post->post_title,
			$expected_type ?? 'none',
			mb_substr( $post->post_content, 0, 8000 )
		);

		// Append coverage gap instruction when a cached payload has actionable gaps.
		$coverage_payload = get_post_meta( $post_id, SWPS_AEO_Scorer::META_COVERAGE_PAYLOAD, true );
		if ( is_array( $coverage_payload ) && ! empty( $coverage_payload['sub_queries'] ) ) {
			$gap_instruction = SWPS_Question_Coverage::format_gap_instruction(
				(array) $coverage_payload['sub_queries'],
				3
			);
			if ( '' !== $gap_instruction ) {
				$user .= "\n\n" . $gap_instruction;
			}
		}

		// Append GSC-mined unanswered searcher questions (set by the weekly
		// question-mining cron; meta read only — no GSC call here).
		$unanswered = get_post_meta( $post_id, SWPS_Question_Coverage::META_UNANSWERED, true );
		if ( is_array( $unanswered ) && ! empty( $unanswered ) ) {
			$gsc_instruction = SWPS_Question_Coverage::format_unanswered_instruction( $unanswered, 3 );
			if ( '' !== $gsc_instruction ) {
				$user .= "\n\n" . $gsc_instruction;
			}
		}

		// Refresh-queue context: when the decay watchdog staged a refresh for
		// this post (15-min transient), steer the proposal toward freshness.
		$refresh_key = SWPS_Refresh_Queue_Admin::CTX_TRANSIENT_PREFIX . $post_id;
		if ( get_transient( $refresh_key ) ) {
			delete_transient( $refresh_key );
			$user .= "\n\n" . __( 'This post is losing search traffic. Prioritize a freshness refresh: update outdated facts and year references cautiously - never invent statistics or data; add a brief updated note near the top; refresh the TL;DR/summary if present.', 'stratawp-seo' );
		}

		// 8192 (not 4096) output budget: an AEO proposal bundles find/replace
		// edits, structural inserts AND a schema block, which overflows a 4096
		// cap on longer posts. When the response truncates, chat_json() returns
		// a "hit token limit" error *after* the provider has already billed the
		// call — so the user is charged for a failed proposal (see issue #75).
		$result = $this->ai_provider->chat_json( $system, $user, 8192 );
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message(), 'http_status' => 500 );
		}
		$proposal = $result;

		// Generate schema JSON if proposed.
		if ( ! empty( $proposal['schema']['type'] ) && 'null' !== $proposal['schema']['type'] ) {
			$sg = $this->schema_gen->generate(
				(string) $proposal['schema']['type'],
				$post->post_title,
				$post->post_content
			);
			$proposal['schema']['validation_error'] = $sg['error'];
			$proposal['schema']['json']             = $sg['json'];
		}

		$proposal['_generated_at'] = time();

		/** Filter the AI-returned proposal. */
		$proposal = (array) apply_filters( 'swps_aeo_proposal', $proposal, $post_id );

		// IMPORTANT: wp_slash() before update_post_meta(). WordPress's
		// update_metadata() internally calls wp_unslash() on the value, which
		// would strip the \" escapes inside our JSON string and corrupt it.
		// Pre-slashing means the round-trip leaves the stored value as valid
		// JSON. The same pattern is applied to META_SNAPSHOT and
		// META_SCHEMA_JSON below.
		update_post_meta( $post_id, self::META_PROPOSAL, wp_slash( (string) wp_json_encode( $proposal ) ) );

		return array( 'proposal' => $proposal );
	}

	public function ajax_apply(): void {
		$this->verify_request();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		$post_id          = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$selected_edits   = isset( $_POST['edits'] )   ? array_map( 'intval', (array) wp_unslash( $_POST['edits'] ) )   : array();
		$selected_inserts = isset( $_POST['inserts'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['inserts'] ) ) : array();
		$apply_schema     = ! empty( $_POST['schema'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->do_apply( $post_id, $selected_edits, $selected_inserts, $apply_schema );
		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ), $result['http_status'] ?? 500 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Apply selected edits, inserts, and/or schema from a stored proposal.
	 *
	 * On success returns array with keys 'applied_count', 'new_score', 'new_subscores'.
	 * On error returns array with keys 'error' (string) and 'http_status' (int).
	 *
	 * @param int   $post_id          Post ID.
	 * @param int[] $selected_edits   Indexes into proposal['edits'] to apply.
	 * @param int[] $selected_inserts Indexes into proposal['inserts'] to apply.
	 * @param bool  $apply_schema     Whether to apply the proposed schema block.
	 * @return array<string,mixed>
	 */
	public function do_apply( int $post_id, array $selected_edits, array $selected_inserts, bool $apply_schema ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found', 'http_status' => 404 );
		}

		$raw = (string) get_post_meta( $post_id, self::META_PROPOSAL, true );
		if ( '' === $raw ) {
			return array(
				'error'       => __( 'No proposal cached. Click "Generate proposal" again — the previous one may have expired (proposals are cleared after 24h) or never been saved.', 'stratawp-seo' ),
				'http_status' => 400,
			);
		}
		$proposal = json_decode( $raw, true );
		if ( ! is_array( $proposal ) ) {
			// Likely a stale proposal stored under the pre-v4.6.6 wp_unslash bug
			// (JSON's \" escapes were stripped, breaking json_decode here). Auto-
			// clear so the next "Generate proposal" overwrites cleanly.
			delete_post_meta( $post_id, self::META_PROPOSAL );
			return array(
				'error'       => __( 'Cached proposal could not be parsed (likely corrupted by a pre-v4.6.6 storage bug). The bad proposal has been cleared — please click "Generate proposal" again.', 'stratawp-seo' ),
				'http_status' => 400,
			);
		}

		// Snapshot for undo. wp_slash for the same reason as META_PROPOSAL above.
		update_post_meta( $post_id, self::META_SNAPSHOT, wp_slash( (string) wp_json_encode( array(
			'content'     => $post->post_content,
			'schema_type' => get_post_meta( $post_id, self::META_SCHEMA_TYPE, true ),
			'schema_json' => get_post_meta( $post_id, self::META_SCHEMA_JSON, true ),
			'taken_at'    => time(),
		) ) ) );

		$new_content = $post->post_content;
		$applied     = 0;

		// Apply edits (only when find string appears exactly once — ambiguity-safe).
		foreach ( $selected_edits as $idx ) {
			if ( ! isset( $proposal['edits'][ $idx ] ) ) {
				continue;
			}
			$find    = (string) ( $proposal['edits'][ $idx ]['find']    ?? '' );
			$replace = (string) ( $proposal['edits'][ $idx ]['replace'] ?? '' );
			if ( '' === $find ) {
				continue;
			}
			if ( 1 === substr_count( $new_content, $find ) ) {
				$new_content = str_replace( $find, $replace, $new_content );
				++$applied;
			}
		}

		// Apply structural inserts.
		foreach ( $selected_inserts as $idx ) {
			if ( ! isset( $proposal['inserts'][ $idx ] ) ) {
				continue;
			}
			$ins    = $proposal['inserts'][ $idx ];
			$anchor = (string) ( $ins['anchor'] ?? '' );
			$html   = (string) ( $ins['html']   ?? '' );
			if ( '' === $html ) {
				continue;
			}
			if ( 'top' === $anchor ) {
				$new_content = $html . "\n\n" . $new_content;
				++$applied;
				continue;
			}
			$pattern = '#(<h2[^>]*>[^<]*' . preg_quote( $anchor, '#' ) . '[^<]*</h2>)#i';
			if ( preg_match( $pattern, $new_content ) ) {
				$new_content = (string) preg_replace( $pattern, "$1\n\n" . $html, $new_content, 1 );
				++$applied;
			}
		}

		wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		) );

		// Apply schema if accepted and valid.
		if ( $apply_schema
			&& ! empty( $proposal['schema']['type'] )
			&& ! empty( $proposal['schema']['json'] )
			&& empty( $proposal['schema']['validation_error'] )
		) {
			update_post_meta( $post_id, self::META_SCHEMA_TYPE, (string) $proposal['schema']['type'] );
			// wp_slash for the same reason as META_PROPOSAL above. Without it
			// the JSON-LD stored here would be corrupted by update_metadata's
			// internal wp_unslash, and the frontend schema renderer would
			// silently fail to emit the JSON-LD.
			update_post_meta( $post_id, self::META_SCHEMA_JSON, wp_slash( (string) wp_json_encode( $proposal['schema']['json'] ) ) );
			++$applied;
		}

		// Clear cached proposal and re-score.
		delete_post_meta( $post_id, self::META_PROPOSAL );
		$rescore = $this->scorer->score_post( $post_id );

		return array(
			'applied_count' => $applied,
			'new_score'     => $rescore['total'],
			'new_subscores' => $rescore['subscores'],
		);
	}

	public function ajax_undo(): void {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		$result  = $this->do_undo( $post_id );
		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ), $result['http_status'] ?? 500 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Restore the last snapshot for a post.
	 *
	 * On success returns array with keys 'restored', 'score', 'subscores'.
	 * On error returns array with keys 'error' (string) and 'http_status' (int).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public function do_undo( int $post_id ): array {
		$raw = (string) get_post_meta( $post_id, self::META_SNAPSHOT, true );
		if ( '' === $raw ) {
			return array(
				'error'       => __( 'No snapshot available to undo (snapshots are taken automatically when you apply a proposal).', 'stratawp-seo' ),
				'http_status' => 404,
			);
		}
		$snap = json_decode( $raw, true );
		if ( ! is_array( $snap ) ) {
			delete_post_meta( $post_id, self::META_SNAPSHOT );
			return array(
				'error'       => __( 'Snapshot could not be parsed (likely corrupted by a pre-v4.6.6 storage bug). The bad snapshot has been cleared.', 'stratawp-seo' ),
				'http_status' => 400,
			);
		}

		wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => (string) ( $snap['content'] ?? '' ),
		) );

		if ( ! empty( $snap['schema_type'] ) ) {
			update_post_meta( $post_id, self::META_SCHEMA_TYPE, (string) $snap['schema_type'] );
		} else {
			delete_post_meta( $post_id, self::META_SCHEMA_TYPE );
		}
		if ( ! empty( $snap['schema_json'] ) ) {
			// wp_slash so update_metadata's internal wp_unslash doesn't strip
			// the JSON-LD's \" escapes (same issue as the store sites above).
			update_post_meta( $post_id, self::META_SCHEMA_JSON, wp_slash( (string) $snap['schema_json'] ) );
		} else {
			delete_post_meta( $post_id, self::META_SCHEMA_JSON );
		}

		delete_post_meta( $post_id, self::META_SNAPSHOT );
		$r = $this->scorer->score_post( $post_id );

		return array(
			'restored'  => true,
			'score'     => $r['total'],
			'subscores' => $r['subscores'],
		);
	}

	public function ajax_dismiss(): void {
		$this->verify_request();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		$post_id   = isset( $_POST['post_id'] )   ? (int) $_POST['post_id']    : 0;
		$dismissed = isset( $_POST['dismissed'] ) ? (bool) $_POST['dismissed'] : true;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		wp_send_json_success( $this->do_dismiss( $post_id, $dismissed ) );
	}

	/**
	 * Set or clear the dismissed flag for a post.
	 *
	 * @param int  $post_id   Post ID.
	 * @param bool $dismissed True to dismiss, false to un-dismiss.
	 * @return array{ok:bool}
	 */
	public function do_dismiss( int $post_id, bool $dismissed ): array {
		if ( $dismissed ) {
			update_post_meta( $post_id, self::META_DISMISSED, 1 );
		} else {
			delete_post_meta( $post_id, self::META_DISMISSED );
		}
		return array( 'ok' => true );
	}

	/**
	 * Cron: delete cached AEO proposals older than 24 hours.
	 *
	 * Proposals are stored in post meta (META_PROPOSAL) by ajax_propose().
	 * Each proposal includes a `_generated_at` UNIX timestamp.
	 */
	public function sweep_proposals(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cron sweep, no caching concern.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 500",
			self::META_PROPOSAL
		) );
		if ( ! $rows ) {
			return;
		}
		foreach ( $rows as $row ) {
			$proposal = json_decode( (string) $row->meta_value, true );
			$age      = is_array( $proposal ) && isset( $proposal['_generated_at'] )
				? time() - (int) $proposal['_generated_at']
				: DAY_IN_SECONDS + 1;
			if ( $age > DAY_IN_SECONDS ) {
				delete_post_meta( (int) $row->post_id, self::META_PROPOSAL );
			}
		}
	}
}
