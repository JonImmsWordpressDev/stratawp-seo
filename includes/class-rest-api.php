<?php
/**
 * REST API endpoints for StrataWP SEO.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_REST_API {

	private const NAMESPACE = 'swps/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_post' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'topic'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'template' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'auto',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_queue' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_to_queue' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'title'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'date'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'template' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => 'auto',
						),
						'notes'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'default'           => '',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_from_queue' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Score endpoints.
		register_rest_route(
			self::NAMESPACE,
			'/score/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_score' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'score_post' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Voice profile endpoints.
		register_rest_route(
			self::NAMESPACE,
			'/voice-profiles',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_voice_profiles' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_voice_profile' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'name'              => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tone'              => array(
							'type'              => 'string',
							'default'           => 'professional',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'formality'         => array(
							'type'              => 'integer',
							'default'           => 5,
							'sanitize_callback' => 'absint',
						),
						'sentence_length'   => array(
							'type'              => 'string',
							'default'           => 'varied',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'vocabulary_level'  => array(
							'type'              => 'string',
							'default'           => 'moderate',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'person'            => array(
							'type'              => 'string',
							'default'           => 'second',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'example_content'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'avoid_phrases'     => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'preferred_phrases' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/voice-profiles/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_voice_profile' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id'   => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'name' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_voice_profile' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Audit endpoints.
		register_rest_route(
			self::NAMESPACE,
			'/audit',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_audit' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'run_audit' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit/fix/(?P<module_id>[a-z_]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'fix_audit_module' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'module_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// User preferences (v4.0 — theme toggle).
		register_rest_route(
			self::NAMESPACE,
			'/user-prefs/theme',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_theme' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'theme' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array( 'dark', 'light' ),
					),
				),
			)
		);

		// Bot Analytics (v4.5).
		register_rest_route(
			self::NAMESPACE,
			'/bot-analytics/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'bot_analytics_summary' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'days' => array(
						'type'              => 'integer',
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bot-analytics/top-pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'bot_analytics_top_pages' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'days'  => array(
						'type'              => 'integer',
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'bot'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bot-analytics/gaps',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'bot_analytics_gaps' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'days'  => array(
						'type'              => 'integer',
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 25,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Modules registry — toggle a module on/off.
		register_rest_route(
			self::NAMESPACE,
			'/modules/(?P<slug>[a-z0-9-]+)/toggle',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'toggle_module' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'slug'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		// AEO Optimizer endpoints (v4.6).
		register_rest_route(
			self::NAMESPACE,
			'/aeo/scan-batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_aeo_scan_batch' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'offset' => array( 'type' => 'integer', 'default' => 0,  'sanitize_callback' => 'absint' ),
					'limit'  => array( 'type' => 'integer', 'default' => 10, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/aeo/score/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_aeo_score' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/aeo/proposal/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_aeo_proposal' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/aeo/apply/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_aeo_apply' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'edits'   => array( 'type' => 'array', 'default' => array() ),
					'inserts' => array( 'type' => 'array', 'default' => array() ),
					'schema'  => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/aeo/undo/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_aeo_undo' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/aeo/dismiss/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_aeo_dismiss' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'dismissed' => array( 'type' => 'boolean', 'default' => true ),
				),
			)
		);
	}

	/**
	 * Looser permission check for per-user preferences — any logged-in user.
	 */
	public function check_logged_in(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'stratawp-seo' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * POST /user-prefs/theme — set the current user's admin theme.
	 */
	public function update_theme( WP_REST_Request $request ): WP_REST_Response {
		$theme = $request->get_param( 'theme' );
		$prefs = stratawp_seo()->user_prefs;
		$ok    = $prefs->set_theme( $theme );

		return new WP_REST_Response(
			array(
				'success' => $ok,
				'theme'   => $prefs->get_theme(),
			),
			$ok ? 200 : 400
		);
	}

	/**
	 * POST /modules/{slug}/toggle — enable or disable a module.
	 */
	public function toggle_module( WP_REST_Request $request ): WP_REST_Response {
		$slug    = $request->get_param( 'slug' );
		$enabled = (bool) $request->get_param( 'enabled' );
		$modules = stratawp_seo()->modules;

		$ok = $modules->set_enabled( $slug, $enabled );
		if ( ! $ok ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Unknown or locked module.',
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'slug'    => $slug,
				'enabled' => $modules->is_enabled( $slug ),
			)
		);
	}

	/**
	 * Permission check — requires manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permissions(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'stratawp-seo' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * POST /generate - Generate a blog post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function generate_post( WP_REST_Request $request ): WP_REST_Response {
		$topic    = $request->get_param( 'topic' );
		$template = $request->get_param( 'template' );

		$plugin = stratawp_seo();
		$result = $plugin->generator->generate_post( $topic, $template );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			201
		);
	}

	/**
	 * GET /status - Get plugin status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {
		$schedule = SWPS_Cron::get_schedule_info();

		$data = array(
			'version'     => SWPS_VERSION,
			'ai_provider' => get_option( 'swps_ai_provider', 'anthropic' ),
			'model'       => get_option( 'swps_model', '' ),
			'schedule'    => $schedule,
		);

		if ( get_option( 'swps_cost_tracking', false ) ) {
			$tracker       = new SWPS_Cost_Tracker();
			$data['costs'] = $tracker->get_monthly_stats();
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * GET /queue - List queued topics.
	 *
	 * @return WP_REST_Response
	 */
	public function get_queue(): WP_REST_Response {
		$queue  = new SWPS_Topic_Queue();
		$topics = $queue->get_queued_topics();

		$data = array_map(
			function ( $topic ) {
				return array(
					'id'       => $topic->ID,
					'title'    => $topic->post_title,
					'date'     => $topic->post_date,
					'status'   => $topic->post_status,
					'template' => get_post_meta( $topic->ID, '_swps_template', true ) ?: 'auto',
					'notes'    => $topic->post_content,
				);
			},
			$topics
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
				'count'   => count( $data ),
			)
		);
	}

	/**
	 * POST /queue - Add a topic to the queue.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function add_to_queue( WP_REST_Request $request ): WP_REST_Response {
		$queue = new SWPS_Topic_Queue();

		$result = $queue->create_topic(
			$request->get_param( 'title' ),
			$request->get_param( 'date' ),
			$request->get_param( 'template' ),
			$request->get_param( 'notes' )
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'topic_id' => $result,
			),
			201
		);
	}

	/**
	 * DELETE /queue/{id} - Remove a topic from the queue.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function delete_from_queue( WP_REST_Request $request ): WP_REST_Response {
		$queue    = new SWPS_Topic_Queue();
		$topic_id = $request->get_param( 'id' );

		$success = $queue->delete_topic( $topic_id );

		return new WP_REST_Response(
			array(
				'success' => $success,
			)
		);
	}

	/**
	 * GET /score/{id} - Get stored score for a post.
	 */
	public function get_score( WP_REST_Request $request ): WP_REST_Response {
		$post_id = $request->get_param( 'id' );

		$score   = get_post_meta( $post_id, '_swps_content_score', true );
		$details = get_post_meta( $post_id, '_swps_score_details', true );
		$recs    = get_post_meta( $post_id, '_swps_score_recommendations', true );

		if ( '' === $score ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'No score found for this post.',
				),
				404
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'overall_score'   => (int) $score,
					'details'         => $details ?: array(),
					'recommendations' => $recs ?: array(),
				),
			)
		);
	}

	/**
	 * POST /score/{id} - Score an existing post.
	 */
	public function score_post( WP_REST_Request $request ): WP_REST_Response {
		$post_id = $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Post not found or not a blog post.',
				),
				404
			);
		}

		// Build a minimal ai_result from the existing post.
		$ai_result = array(
			'title'                => $post->post_title,
			'content_html'         => $post->post_content,
			'meta_description'     => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
									?: get_post_meta( $post_id, 'rank_math_description', true )
									?: '',
			'focus_keyword'        => get_post_meta( $post_id, '_swps_focus_keyword', true )
									?: get_post_meta( $post_id, '_yoast_wpseo_focuskw', true )
									?: get_post_meta( $post_id, 'rank_math_focus_keyword', true )
									?: '',
			'secondary_keywords'   => get_post_meta( $post_id, '_swps_secondary_keywords', true ) ?: array(),
			'internal_links_used'  => get_post_meta( $post_id, '_swps_internal_links', true ) ?: array(),
			'estimated_word_count' => str_word_count( wp_strip_all_tags( $post->post_content ) ),
		);

		$scorer  = stratawp_seo()->content_scorer;
		$results = $scorer->score( $post_id, $ai_result );

		update_post_meta( $post_id, '_swps_content_score', $results['overall_score'] );
		update_post_meta( $post_id, '_swps_score_details', $results['details'] );
		update_post_meta( $post_id, '_swps_score_recommendations', $results['recommendations'] );

		SWPS_Hooks::do_score_complete( $results, $post_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $results,
			)
		);
	}

	/**
	 * GET /voice-profiles - List all voice profiles.
	 */
	public function get_voice_profiles(): WP_REST_Response {
		$profiles = stratawp_seo()->voice_profile->get_all();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $profiles,
				'count'   => count( $profiles ),
			)
		);
	}

	/**
	 * POST /voice-profiles - Create a voice profile.
	 */
	public function create_voice_profile( WP_REST_Request $request ): WP_REST_Response {
		$vp     = stratawp_seo()->voice_profile;
		$result = $vp->create( $request->get_param( 'name' ), $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $vp->get( $result ),
			),
			201
		);
	}

	/**
	 * PUT /voice-profiles/{id} - Update a voice profile.
	 */
	public function update_voice_profile( WP_REST_Request $request ): WP_REST_Response {
		$vp     = stratawp_seo()->voice_profile;
		$result = $vp->update( $request->get_param( 'id' ), $request->get_param( 'name' ), $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $vp->get( $result ),
			)
		);
	}

	/**
	 * DELETE /voice-profiles/{id} - Delete a voice profile.
	 */
	public function delete_voice_profile( WP_REST_Request $request ): WP_REST_Response {
		$vp      = stratawp_seo()->voice_profile;
		$success = $vp->delete( $request->get_param( 'id' ) );

		return new WP_REST_Response(
			array(
				'success' => $success,
			)
		);
	}

	/**
	 * GET /audit - Get cached audit results.
	 */
	public function get_audit(): WP_REST_Response {
		$audit = stratawp_seo()->seo_audit;

		return new WP_REST_Response(
			array(
				'success'  => true,
				'data'     => $audit->get_cached_results(),
				'last_run' => $audit->get_last_run(),
			)
		);
	}

	/**
	 * POST /audit - Run a fresh audit.
	 */
	public function run_audit(): WP_REST_Response {
		$audit   = stratawp_seo()->seo_audit;
		$results = $audit->run_all();

		return new WP_REST_Response(
			array(
				'success'  => true,
				'data'     => $results,
				'last_run' => $audit->get_last_run(),
			)
		);
	}

	/**
	 * GET /bot-analytics/summary - Headline totals + per-bot summary.
	 */
	public function bot_analytics_summary( WP_REST_Request $request ): WP_REST_Response {
		$days    = max( 1, min( 365, (int) $request->get_param( 'days' ) ) );
		$tracker = stratawp_seo()->bot_analytics_tracker;

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'totals' => $tracker->get_totals( $days ),
					'bots'   => $tracker->get_bot_summary( $days ),
				),
			)
		);
	}

	/**
	 * GET /bot-analytics/top-pages - Top crawled pages.
	 */
	public function bot_analytics_top_pages( WP_REST_Request $request ): WP_REST_Response {
		$days    = max( 1, min( 365, (int) $request->get_param( 'days' ) ) );
		$limit   = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
		$bot     = (string) $request->get_param( 'bot' );
		$tracker = stratawp_seo()->bot_analytics_tracker;

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $tracker->get_top_pages( $days, $limit, '' !== $bot ? $bot : null ),
			)
		);
	}

	/**
	 * GET /bot-analytics/gaps - Posts never crawled in the window.
	 */
	public function bot_analytics_gaps( WP_REST_Request $request ): WP_REST_Response {
		$days    = max( 1, min( 365, (int) $request->get_param( 'days' ) ) );
		$limit   = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
		$tracker = stratawp_seo()->bot_analytics_tracker;

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $tracker->get_gap_posts( $days, $limit ),
			)
		);
	}

	/**
	 * POST /audit/fix/{module_id} - Auto-fix a specific module.
	 */
	public function fix_audit_module( WP_REST_Request $request ): WP_REST_Response {
		$audit     = stratawp_seo()->seo_audit;
		$module_id = $request->get_param( 'module_id' );

		$fix_result = $audit->fix_module( $module_id );

		if ( null === $fix_result ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Module not found or does not support auto-fix.',
				),
				404
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'fix'     => $fix_result,
					'results' => $audit->get_cached_results(),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// AEO Optimizer REST callbacks (v4.6)
	// -------------------------------------------------------------------------

	/**
	 * Resolve SWPS_AEO_Optimizer from the singleton, or return a 503 response.
	 *
	 * @return SWPS_AEO_Optimizer|WP_REST_Response
	 */
	private function aeo_optimizer(): mixed {
		$optimizer = stratawp_seo()->aeo_optimizer ?? null;
		if ( null === $optimizer ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 503 );
		}
		return $optimizer;
	}

	/**
	 * POST /aeo/scan-batch — Score a paginated chunk of published posts.
	 */
	public function rest_aeo_scan_batch( WP_REST_Request $request ): WP_REST_Response {
		$optimizer = $this->aeo_optimizer();
		if ( $optimizer instanceof WP_REST_Response ) {
			return $optimizer;
		}
		$offset = (int) $request->get_param( 'offset' );
		$limit  = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 || $limit > 100 ) {
			$limit = 10;
		}
		return new WP_REST_Response( $optimizer->do_scan_chunk( $offset, $limit ), 200 );
	}

	/**
	 * GET /aeo/score/{id} — Re-score a single post.
	 */
	public function rest_aeo_score( WP_REST_Request $request ): WP_REST_Response {
		$optimizer = $this->aeo_optimizer();
		if ( $optimizer instanceof WP_REST_Response ) {
			return $optimizer;
		}
		$post_id = (int) $request->get_param( 'id' );
		if ( $post_id <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post_id' ), 400 );
		}
		return new WP_REST_Response( $optimizer->do_score( $post_id ), 200 );
	}

	/**
	 * POST /aeo/proposal/{id} — Generate an AI proposal for a post.
	 */
	public function rest_aeo_proposal( WP_REST_Request $request ): WP_REST_Response {
		$optimizer = $this->aeo_optimizer();
		if ( $optimizer instanceof WP_REST_Response ) {
			return $optimizer;
		}
		$post_id = (int) $request->get_param( 'id' );
		if ( $post_id <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post_id' ), 400 );
		}
		$result = $optimizer->do_propose( $post_id );
		if ( isset( $result['error'] ) ) {
			return new WP_REST_Response( $result, $result['http_status'] ?? 500 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /aeo/apply/{id} — Apply selected edits/inserts/schema from the stored proposal.
	 */
	public function rest_aeo_apply( WP_REST_Request $request ): WP_REST_Response {
		$optimizer = $this->aeo_optimizer();
		if ( $optimizer instanceof WP_REST_Response ) {
			return $optimizer;
		}
		$post_id  = (int) $request->get_param( 'id' );
		if ( $post_id <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post_id' ), 400 );
		}
		$edits   = array_map( 'intval', (array) ( $request->get_param( 'edits' )   ?? array() ) );
		$inserts = array_map( 'intval', (array) ( $request->get_param( 'inserts' ) ?? array() ) );
		$schema  = (bool) $request->get_param( 'schema' );
		$result  = $optimizer->do_apply( $post_id, $edits, $inserts, $schema );
		if ( isset( $result['error'] ) ) {
			return new WP_REST_Response( $result, $result['http_status'] ?? 500 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /aeo/undo/{id} — Restore the last snapshot for a post.
	 */
	public function rest_aeo_undo( WP_REST_Request $request ): WP_REST_Response {
		$optimizer = $this->aeo_optimizer();
		if ( $optimizer instanceof WP_REST_Response ) {
			return $optimizer;
		}
		$post_id = (int) $request->get_param( 'id' );
		if ( $post_id <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post_id' ), 400 );
		}
		$result = $optimizer->do_undo( $post_id );
		if ( isset( $result['error'] ) ) {
			return new WP_REST_Response( $result, $result['http_status'] ?? 500 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /aeo/dismiss/{id} — Set or clear the dismissed flag for a post.
	 */
	public function rest_aeo_dismiss( WP_REST_Request $request ): WP_REST_Response {
		$optimizer = $this->aeo_optimizer();
		if ( $optimizer instanceof WP_REST_Response ) {
			return $optimizer;
		}
		$post_id   = (int) $request->get_param( 'id' );
		if ( $post_id <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post_id' ), 400 );
		}
		$dismissed = (bool) $request->get_param( 'dismissed' );
		return new WP_REST_Response( $optimizer->do_dismiss( $post_id, $dismissed ), 200 );
	}
}
