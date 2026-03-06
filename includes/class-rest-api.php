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
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register REST API routes.
     */
    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_post' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => [
                'topic'    => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
                'template' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => 'auto',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( self::NAMESPACE, '/queue', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_queue' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'add_to_queue' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'title'    => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'date'     => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ],
                    'template' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => 'auto',
                    ],
                    'notes'    => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                        'default'           => '',
                    ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/queue/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_from_queue' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // Score endpoints.
        register_rest_route( self::NAMESPACE, '/score/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_score' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'score_post' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                ],
            ],
        ] );

        // Voice profile endpoints.
        register_rest_route( self::NAMESPACE, '/voice-profiles', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_voice_profiles' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_voice_profile' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'name'             => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                    'tone'             => [ 'type' => 'string', 'default' => 'professional', 'sanitize_callback' => 'sanitize_text_field' ],
                    'formality'        => [ 'type' => 'integer', 'default' => 5, 'sanitize_callback' => 'absint' ],
                    'sentence_length'  => [ 'type' => 'string', 'default' => 'varied', 'sanitize_callback' => 'sanitize_text_field' ],
                    'vocabulary_level' => [ 'type' => 'string', 'default' => 'moderate', 'sanitize_callback' => 'sanitize_text_field' ],
                    'person'           => [ 'type' => 'string', 'default' => 'second', 'sanitize_callback' => 'sanitize_text_field' ],
                    'example_content'  => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
                    'avoid_phrases'    => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
                    'preferred_phrases' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/voice-profiles/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_voice_profile' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'id'   => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                    'name' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_voice_profile' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
                ],
            ],
        ] );

        // Audit endpoints.
        register_rest_route( self::NAMESPACE, '/audit', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_audit' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'run_audit' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/audit/fix/(?P<module_id>[a-z_]+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'fix_audit_module' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => [
                'module_id' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );
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
                [ 'status' => 403 ]
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
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 500 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $result,
        ], 201 );
    }

    /**
     * GET /status - Get plugin status.
     *
     * @return WP_REST_Response
     */
    public function get_status(): WP_REST_Response {
        $schedule = SWPS_Cron::get_schedule_info();

        $data = [
            'version'     => SWPS_VERSION,
            'ai_provider' => get_option( 'swps_ai_provider', 'anthropic' ),
            'model'       => get_option( 'swps_model', '' ),
            'schedule'    => $schedule,
        ];

        if ( get_option( 'swps_cost_tracking', false ) ) {
            $tracker = new SWPS_Cost_Tracker();
            $data['costs'] = $tracker->get_monthly_stats();
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $data,
        ] );
    }

    /**
     * GET /queue - List queued topics.
     *
     * @return WP_REST_Response
     */
    public function get_queue(): WP_REST_Response {
        $queue  = new SWPS_Topic_Queue();
        $topics = $queue->get_queued_topics();

        $data = array_map( function ( $topic ) {
            return [
                'id'       => $topic->ID,
                'title'    => $topic->post_title,
                'date'     => $topic->post_date,
                'status'   => $topic->post_status,
                'template' => get_post_meta( $topic->ID, '_swps_template', true ) ?: 'auto',
                'notes'    => $topic->post_content,
            ];
        }, $topics );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $data,
            'count'   => count( $data ),
        ] );
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
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 500 );
        }

        return new WP_REST_Response( [
            'success'  => true,
            'topic_id' => $result,
        ], 201 );
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

        return new WP_REST_Response( [
            'success' => $success,
        ] );
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
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'No score found for this post.',
            ], 404 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'overall_score'   => (int) $score,
                'details'         => $details ?: [],
                'recommendations' => $recs ?: [],
            ],
        ] );
    }

    /**
     * POST /score/{id} - Score an existing post.
     */
    public function score_post( WP_REST_Request $request ): WP_REST_Response {
        $post_id = $request->get_param( 'id' );
        $post    = get_post( $post_id );

        if ( ! $post || 'post' !== $post->post_type ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Post not found or not a blog post.',
            ], 404 );
        }

        // Build a minimal ai_result from the existing post.
        $ai_result = [
            'title'               => $post->post_title,
            'content_html'        => $post->post_content,
            'meta_description'    => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
                                  ?: get_post_meta( $post_id, 'rank_math_description', true )
                                  ?: '',
            'focus_keyword'       => get_post_meta( $post_id, '_swps_focus_keyword', true )
                                  ?: get_post_meta( $post_id, '_yoast_wpseo_focuskw', true )
                                  ?: get_post_meta( $post_id, 'rank_math_focus_keyword', true )
                                  ?: '',
            'secondary_keywords'  => get_post_meta( $post_id, '_swps_secondary_keywords', true ) ?: [],
            'internal_links_used' => get_post_meta( $post_id, '_swps_internal_links', true ) ?: [],
            'estimated_word_count' => str_word_count( wp_strip_all_tags( $post->post_content ) ),
        ];

        $scorer  = stratawp_seo()->content_scorer;
        $results = $scorer->score( $post_id, $ai_result );

        update_post_meta( $post_id, '_swps_content_score', $results['overall_score'] );
        update_post_meta( $post_id, '_swps_score_details', $results['details'] );
        update_post_meta( $post_id, '_swps_score_recommendations', $results['recommendations'] );

        SWPS_Hooks::do_score_complete( $results, $post_id );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $results,
        ] );
    }

    /**
     * GET /voice-profiles - List all voice profiles.
     */
    public function get_voice_profiles(): WP_REST_Response {
        $profiles = stratawp_seo()->voice_profile->get_all();

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $profiles,
            'count'   => count( $profiles ),
        ] );
    }

    /**
     * POST /voice-profiles - Create a voice profile.
     */
    public function create_voice_profile( WP_REST_Request $request ): WP_REST_Response {
        $vp     = stratawp_seo()->voice_profile;
        $result = $vp->create( $request->get_param( 'name' ), $request->get_params() );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 500 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $vp->get( $result ),
        ], 201 );
    }

    /**
     * PUT /voice-profiles/{id} - Update a voice profile.
     */
    public function update_voice_profile( WP_REST_Request $request ): WP_REST_Response {
        $vp     = stratawp_seo()->voice_profile;
        $result = $vp->update( $request->get_param( 'id' ), $request->get_param( 'name' ), $request->get_params() );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 500 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $vp->get( $result ),
        ] );
    }

    /**
     * DELETE /voice-profiles/{id} - Delete a voice profile.
     */
    public function delete_voice_profile( WP_REST_Request $request ): WP_REST_Response {
        $vp      = stratawp_seo()->voice_profile;
        $success = $vp->delete( $request->get_param( 'id' ) );

        return new WP_REST_Response( [
            'success' => $success,
        ] );
    }

    /**
     * GET /audit - Get cached audit results.
     */
    public function get_audit(): WP_REST_Response {
        $audit = stratawp_seo()->seo_audit;

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $audit->get_cached_results(),
            'last_run' => $audit->get_last_run(),
        ] );
    }

    /**
     * POST /audit - Run a fresh audit.
     */
    public function run_audit(): WP_REST_Response {
        $audit   = stratawp_seo()->seo_audit;
        $results = $audit->run_all();

        return new WP_REST_Response( [
            'success'  => true,
            'data'     => $results,
            'last_run' => $audit->get_last_run(),
        ] );
    }

    /**
     * POST /audit/fix/{module_id} - Auto-fix a specific module.
     */
    public function fix_audit_module( WP_REST_Request $request ): WP_REST_Response {
        $audit     = stratawp_seo()->seo_audit;
        $module_id = $request->get_param( 'module_id' );

        $fix_result = $audit->fix_module( $module_id );

        if ( null === $fix_result ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Module not found or does not support auto-fix.',
            ], 404 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'fix'     => $fix_result,
                'results' => $audit->get_cached_results(),
            ],
        ] );
    }
}
