<?php
/**
 * Trait SWPS_Ability_Defs — ability definitions and callbacks for SWPS_Abilities.
 *
 * Separated to keep class-abilities.php under 500 lines.
 * Used exclusively by SWPS_Abilities.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability definitions and callback implementations.
 */
trait SWPS_Ability_Defs {

	// =========================================================================
	// Ability definitions
	// =========================================================================

	/**
	 * Build and return the canonical ability definitions array.
	 *
	 * @return array[]
	 */
	private function build_defs(): array {
		return array(

			// ------------------------------------------------------------------
			// queue-topic (WRITE)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/queue-topic',
				'label'         => __( 'Queue Topic', 'stratawp-seo' ),
				'description'   => __( 'Add a new content topic to the generation queue.', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'required'   => array( 'title' ),
					'properties' => array(
						'title'    => array(
							'type'        => 'string',
							'description' => __( 'Topic title / headline.', 'stratawp-seo' ),
						),
						'date'     => array(
							'type'        => 'string',
							'description' => __( 'Scheduled publish date (YYYY-MM-DD HH:MM:SS).', 'stratawp-seo' ),
						),
						'template' => array(
							'type'        => 'string',
							'description' => __( 'Template slug or "auto".', 'stratawp-seo' ),
						),
						'notes'    => array(
							'type'        => 'string',
							'description' => __( 'Optional editorial notes.', 'stratawp-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'topic_id' => array( 'type' => 'integer' ),
						'title'    => array( 'type' => 'string' ),
					),
				),
				'callback'      => array( $this, 'cb_queue_topic' ),
			),

			// ------------------------------------------------------------------
			// generate-post (WRITE, async)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/generate-post',
				'label'         => __( 'Generate Post', 'stratawp-seo' ),
				'description'   => __( 'Schedule async AI generation for a queued topic. Returns "queued" immediately; generation runs in the background.', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'required'   => array( 'topic_id' ),
					'properties' => array(
						'topic_id' => array(
							'type'        => 'integer',
							'description' => __( 'Topic post ID to generate from.', 'stratawp-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string' ),
						'topic_id' => array( 'type' => 'integer' ),
					),
				),
				'callback'      => array( $this, 'cb_generate_post' ),
			),

			// ------------------------------------------------------------------
			// run-audit (WRITE, async-queued)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/run-audit',
				'label'         => __( 'Run SEO Audit', 'stratawp-seo' ),
				'description'   => __( 'Schedule a full site SEO audit. Returns "queued"; results available via get-audit-results after the cron event fires.', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'       => array( 'type' => 'string' ),
						'scheduled_at' => array( 'type' => 'string' ),
					),
				),
				'callback'      => array( $this, 'cb_run_audit' ),
			),

			// ------------------------------------------------------------------
			// get-audit-results (READ)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/get-audit-results',
				'label'         => __( 'Get Audit Results', 'stratawp-seo' ),
				'description'   => __( 'Return the most recent cached SEO audit results.', 'stratawp-seo' ),
				'write'         => false,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'overall_score' => array( 'type' => 'integer' ),
						'last_run'      => array( 'type' => 'string' ),
						'modules'       => array( 'type' => 'object' ),
					),
				),
				'callback'      => array( $this, 'cb_get_audit_results' ),
			),

			// ------------------------------------------------------------------
			// aeo-scan-chunk (WRITE)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/aeo-scan-chunk',
				'label'         => __( 'AEO Scan Chunk', 'stratawp-seo' ),
				'description'   => __( 'Run AEO extractability scoring for a batch of posts (offset + limit).', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'offset' => array( 'type' => 'integer' ),
						'limit'  => array( 'type' => 'integer' ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'scored' => array( 'type' => 'integer' ),
					),
				),
				'callback'      => array( $this, 'cb_aeo_scan_chunk' ),
			),

			// ------------------------------------------------------------------
			// aeo-propose (WRITE)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/aeo-propose',
				'label'         => __( 'AEO Propose Edits', 'stratawp-seo' ),
				'description'   => __( 'Generate AI-driven AEO edit proposals for a post.', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'proposals' => array( 'type' => 'array' ),
					),
				),
				'callback'      => array( $this, 'cb_aeo_propose' ),
			),

			// ------------------------------------------------------------------
			// aeo-apply (WRITE)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/aeo-apply',
				'label'         => __( 'AEO Apply Edits', 'stratawp-seo' ),
				'description'   => __( 'Apply accepted AEO edit proposals to a post. Snapshot saved for undo.', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id'          => array( 'type' => 'integer' ),
						'selected_edits'   => array( 'type' => 'array' ),
						'selected_inserts' => array( 'type' => 'array' ),
						'apply_schema'     => array( 'type' => 'boolean' ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'applied' => array( 'type' => 'boolean' ),
					),
				),
				'callback'      => array( $this, 'cb_aeo_apply' ),
			),

			// ------------------------------------------------------------------
			// aeo-undo (WRITE)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/aeo-undo',
				'label'         => __( 'AEO Undo Edits', 'stratawp-seo' ),
				'description'   => __( 'Revert AEO edits for a post to its pre-apply snapshot.', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'required'   => array( 'post_id' ),
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'reverted' => array( 'type' => 'boolean' ),
					),
				),
				'callback'      => array( $this, 'cb_aeo_undo' ),
			),

			// ------------------------------------------------------------------
			// add-redirect (WRITE)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/add-redirect',
				'label'         => __( 'Add Redirect', 'stratawp-seo' ),
				'description'   => __( 'Add a URL redirect. The redirect ID is returned so delete-redirect can undo it.', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'required'   => array( 'source', 'target' ),
					'properties' => array(
						'source'   => array( 'type' => 'string' ),
						'target'   => array( 'type' => 'string' ),
						'type'     => array(
							'type' => 'integer',
							'enum' => array( 301, 302, 307, 410 ),
						),
						'is_regex' => array( 'type' => 'boolean' ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'redirect_id' => array( 'type' => 'integer' ),
						'source'      => array( 'type' => 'string' ),
						'target'      => array( 'type' => 'string' ),
						'undo_note'   => array( 'type' => 'string' ),
					),
				),
				'callback'      => array( $this, 'cb_add_redirect' ),
			),

			// ------------------------------------------------------------------
			// delete-redirect (WRITE) — undo affordance for add-redirect
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/delete-redirect',
				'label'         => __( 'Delete Redirect', 'stratawp-seo' ),
				'description'   => __( 'Delete a redirect by ID. Use the redirect_id from add-redirect output to undo.', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'required'   => array( 'redirect_id' ),
					'properties' => array(
						'redirect_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'deleted' => array( 'type' => 'boolean' ),
					),
				),
				'callback'      => array( $this, 'cb_delete_redirect' ),
			),

			// ------------------------------------------------------------------
			// get-bot-stats (READ)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/get-bot-stats',
				'label'         => __( 'Get Bot Stats', 'stratawp-seo' ),
				'description'   => __( 'Return AI crawler hit summary for the requested time window.', 'stratawp-seo' ),
				'write'         => false,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'days' => array(
							'type'        => 'integer',
							'description' => __( 'Number of days to look back (default 30).', 'stratawp-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'totals'  => array( 'type' => 'object' ),
						'summary' => array( 'type' => 'array' ),
					),
				),
				'callback'      => array( $this, 'cb_get_bot_stats' ),
			),

			// ------------------------------------------------------------------
			// get-keyword-positions (READ)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/get-keyword-positions',
				'label'         => __( 'Get Keyword Positions', 'stratawp-seo' ),
				'description'   => __( 'Return tracked keyword positions from Search Console.', 'stratawp-seo' ),
				'write'         => false,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer' ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'keywords' => array( 'type' => 'array' ),
					),
				),
				'callback'      => array( $this, 'cb_get_keyword_positions' ),
			),

			// ------------------------------------------------------------------
			// get-decay-queue (READ)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/get-decay-queue',
				'label'         => __( 'Get Decay Queue', 'stratawp-seo' ),
				'description'   => __( 'Return posts flagged by the content decay watchdog.', 'stratawp-seo' ),
				'write'         => false,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'posts' => array( 'type' => 'array' ),
					),
				),
				'callback'      => array( $this, 'cb_get_decay_queue' ),
			),
		);
	}

	// =========================================================================
	// Ability callbacks
	// =========================================================================

	/**
	 * Callback: queue-topic — add a topic to the generation queue.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function cb_queue_topic( array $input ) {
		$plugin = stratawp_seo();
		$title  = sanitize_text_field( $input['title'] ?? '' );
		$date   = sanitize_text_field( $input['date'] ?? '' );
		$tpl    = sanitize_text_field( $input['template'] ?? 'auto' );
		$notes  = sanitize_textarea_field( $input['notes'] ?? '' );

		$result = $plugin->topic_queue->create_topic( $title, $date, $tpl, $notes );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'topic_id' => (int) $result,
			'title'    => $title,
		);
	}

	/**
	 * Callback: generate-post — schedule async generation (NEVER calls generator directly).
	 *
	 * ASYNC ONLY — schedules a single background event; never calls generator directly.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function cb_generate_post( array $input ) {
		$topic_id = (int) ( $input['topic_id'] ?? 0 );

		if ( $topic_id <= 0 ) {
			return new WP_Error( 'swps_invalid_topic', __( 'Invalid topic_id.', 'stratawp-seo' ) );
		}

		$topic = get_post( $topic_id );
		if ( ! $topic || SWPS_Topic_Queue::POST_TYPE !== $topic->post_type ) {
			return new WP_Error( 'swps_topic_not_found', __( 'Topic not found.', 'stratawp-seo' ) );
		}

		stratawp_seo()->background_processor->schedule_generation( $topic_id );

		return array(
			'status'   => 'queued',
			'topic_id' => $topic_id,
		);
	}

	/**
	 * Callback: run-audit — schedule a background site audit.
	 *
	 * Schedules a single cron event instead of running synchronously.
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public function cb_run_audit( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$hook      = 'swps_audit_ability_run';
		$timestamp = time() + 5;
		wp_schedule_single_event( $timestamp, $hook );

		return array(
			'status'       => 'queued',
			'scheduled_at' => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
		);
	}

	/**
	 * Callback: get-audit-results — return cached audit data.
	 *
	 * @param array $input Validated input (unused).
	 * @return array
	 */
	public function cb_get_audit_results( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$audit = stratawp_seo()->seo_audit;
		return array(
			'overall_score' => (int) ( $audit->get_cached_results()['overall_score'] ?? 0 ),
			'last_run'      => $audit->get_last_run(),
			'modules'       => $audit->get_cached_results()['modules'] ?? array(),
		);
	}

	/**
	 * Callback: aeo-scan-chunk — score a batch of posts for AEO extractability.
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public function cb_aeo_scan_chunk( array $input ): array {
		$offset = (int) ( $input['offset'] ?? 0 );
		$limit  = (int) ( $input['limit'] ?? 10 );
		$result = stratawp_seo()->aeo_optimizer->do_scan_chunk( $offset, $limit );

		return array(
			'scored' => count( $result ),
			'items'  => $result,
		);
	}

	/**
	 * Callback: aeo-propose — generate AEO edit proposals for a post.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function cb_aeo_propose( array $input ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		return stratawp_seo()->aeo_optimizer->do_propose( $post_id );
	}

	/**
	 * Callback: aeo-apply — apply accepted AEO edits to a post.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function cb_aeo_apply( array $input ) {
		$post_id          = (int) ( $input['post_id'] ?? 0 );
		$selected_edits   = (array) ( $input['selected_edits'] ?? array() );
		$selected_inserts = (array) ( $input['selected_inserts'] ?? array() );
		$apply_schema     = (bool) ( $input['apply_schema'] ?? false );

		return stratawp_seo()->aeo_optimizer->do_apply(
			$post_id,
			$selected_edits,
			$selected_inserts,
			$apply_schema
		);
	}

	/**
	 * Callback: aeo-undo — revert AEO edits for a post to its pre-apply snapshot.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function cb_aeo_undo( array $input ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		return stratawp_seo()->aeo_optimizer->do_undo( $post_id );
	}

	/**
	 * Callback: add-redirect — insert a URL redirect and return its ID.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function cb_add_redirect( array $input ) {
		$source   = sanitize_text_field( $input['source'] ?? '' );
		$target   = sanitize_text_field( $input['target'] ?? '' );
		$type     = (int) ( $input['type'] ?? 301 );
		$is_regex = (bool) ( $input['is_regex'] ?? false );

		$result = stratawp_seo()->redirect_manager->add_redirect( $source, $target, $type, $is_regex );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new WP_Error( 'swps_redirect_failed', __( 'Failed to insert redirect.', 'stratawp-seo' ) );
		}

		return array(
			'redirect_id' => (int) $result,
			'source'      => $source,
			'target'      => $target,
			'undo_note'   => __( 'Call delete-redirect with this redirect_id to undo.', 'stratawp-seo' ),
		);
	}

	/**
	 * Callback: delete-redirect — remove a redirect by ID (undo affordance for add-redirect).
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public function cb_delete_redirect( array $input ): array {
		global $wpdb;

		$id    = (int) ( $input['redirect_id'] ?? 0 );
		$table = $wpdb->prefix . 'swps_redirects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		delete_transient( 'swps_redirect_cache' );

		return array( 'deleted' => ( false !== $deleted && $deleted > 0 ) );
	}

	/**
	 * Callback: get-bot-stats — return AI crawler hit summary.
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public function cb_get_bot_stats( array $input ): array {
		$days    = max( 1, (int) ( $input['days'] ?? 30 ) );
		$tracker = stratawp_seo()->bot_analytics_tracker;

		return array(
			'totals'  => $tracker->get_totals( $days ),
			'summary' => $tracker->get_bot_summary( $days ),
		);
	}

	/**
	 * Callback: get-keyword-positions — return tracked keyword positions.
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public function cb_get_keyword_positions( array $input ): array {
		$limit    = max( 1, (int) ( $input['limit'] ?? 50 ) );
		$keywords = stratawp_seo()->keyword_tracker->get_tracked_keywords( $limit );

		return array( 'keywords' => $keywords );
	}

	/**
	 * Callback: get-decay-queue — return posts flagged by the decay watchdog.
	 *
	 * @param array $input Validated input (unused).
	 * @return array
	 */
	public function cb_get_decay_queue( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$posts = stratawp_seo()->refresh_queue_admin->get_queue();
		return array( 'posts' => $posts );
	}
}
