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
}
