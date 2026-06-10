<?php
/**
 * REST endpoints for the StrataWP SEO Abilities API.
 *
 * Namespace: swps/v1/abilities
 *   GET  /abilities               — discovery: name, label, description, write flag, enabled state, input/output schemas.
 *   POST /abilities/{name}/run    — execute an ability; auth via standard WP REST (application passwords).
 *
 * Permission: manage_options + per-ability enabled check on run.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the abilities discovery and execution endpoints.
 */
class SWPS_Abilities_Rest {

	private const NAMESPACE = 'swps/v1';

	/**
	 * Abilities registry.
	 *
	 * @var SWPS_Abilities
	 */
	private SWPS_Abilities $abilities;

	/**
	 * Constructor.
	 *
	 * @param SWPS_Abilities $abilities Registry instance.
	 */
	public function __construct( SWPS_Abilities $abilities ) {
		$this->abilities = $abilities;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/abilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_abilities' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/abilities/(?P<name>[a-zA-Z0-9\-\/]+)/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_ability' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'name' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET /abilities — discovery endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public function list_abilities(): WP_REST_Response {
		$out = array();

		foreach ( $this->abilities->get_defs() as $def ) {
			$out[] = array(
				'name'          => $def['name'],
				'label'         => $def['label'],
				'description'   => $def['description'],
				'write'         => $def['write'],
				'enabled'       => $this->abilities->is_enabled( $def['name'] ),
				'input_schema'  => $def['input_schema'] ?? array(),
				'output_schema' => $def['output_schema'] ?? array(),
			);
		}

		return new WP_REST_Response( array( 'abilities' => $out ) );
	}

	/**
	 * POST /abilities/{name}/run — execute an ability.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function run_ability( WP_REST_Request $request ): WP_REST_Response {
		$name  = $this->decode_ability_name( $request->get_param( 'name' ) );
		$input = (array) $request->get_json_params();

		$result = $this->abilities->execute( $name, $input );

		if ( is_wp_error( $result ) ) {
			$status = 'swps_ability_disabled' === $result->get_error_code() ? 403 : 400;
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Permission check — manage_options only; no nopriv.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permissions() {
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
	 * Decode an ability name from a URL segment.
	 *
	 * The route regex captures '/' so "stratawp-seo/get-bot-stats" comes through as-is.
	 * URL-encoded slashes (%2F) are also handled.
	 *
	 * @param string $raw Raw route parameter value.
	 * @return string Decoded ability name.
	 */
	private function decode_ability_name( string $raw ): string {
		return rawurldecode( $raw );
	}
}
