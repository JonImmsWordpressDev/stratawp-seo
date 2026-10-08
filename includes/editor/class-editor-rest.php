<?php
/**
 * REST routes for the editor sidebar's deep tier.
 *
 * Every route requires edit_post on the post and reads the editor state from
 * the request body, never the saved copy, so unsaved and auto-draft posts work.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Rest {

	private const NS = 'swps/v1';

	private SWPS_AEO_Optimizer $aeo;
	private SWPS_Citation_Tracker $citations;
	private SWPS_Search_Console $gsc;
	private SWPS_Keyword_Tracker $keywords;

	public function __construct(
		SWPS_AEO_Optimizer $aeo,
		SWPS_Citation_Tracker $citations,
		SWPS_Search_Console $gsc,
		SWPS_Keyword_Tracker $keywords
	) {
		$this->aeo       = $aeo;
		$this->citations = $citations;
		$this->gsc       = $gsc;
		$this->keywords  = $keywords;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	// ---------------------------------------------------------------------
	// Pure helpers (unit tested without WordPress).
	// ---------------------------------------------------------------------

	/**
	 * @param mixed $raw Raw request value.
	 * @return string[]
	 */
	public static function normalize_keywords( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $kw ) {
			$kw = trim( (string) $kw );
			if ( '' === $kw ) {
				continue;
			}
			$key = mb_strtolower( $kw );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $kw;
			}
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return array_values( $out );
	}

	/**
	 * JSON-LD nodes found in post content with their missing required props.
	 *
	 * @param string               $html     Post content.
	 * @param array<string,mixed>  $manifest Rules manifest (type => {required: []}).
	 * @return array<int,array{type:string,missing:string[]}>
	 */
	public static function schema_nodes( string $html, array $manifest ): array {
		$out = array();
		foreach ( SWPS_Schema_Validator::extract_jsonld( $html ) as $decoded ) {
			$list = isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ? $decoded['@graph'] : array( $decoded );
			foreach ( $list as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				foreach ( (array) ( $node['@type'] ?? array() ) as $type ) {
					$type     = (string) $type;
					$required = isset( $manifest[ $type ]['required'] ) ? (array) $manifest[ $type ]['required'] : array();
					$out[]    = array(
						'type'    => $type,
						'missing' => SWPS_Schema_Validator::validate_node( $node, $required ),
					);
				}
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Routes.
	// ---------------------------------------------------------------------

	public function register_routes(): void {
		$post_id = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => static function ( $value ): bool {
				return (int) $value > 0;
			},
		);

		register_rest_route(
			self::NS,
			'/editor/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'analyze' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'post_id'      => $post_id,
					'mode'         => array(
						'type'    => 'string',
						'enum'    => array( 'cached', 'rescore' ),
						'default' => 'cached',
					),
					'keywords'     => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array(),
					),
					'focus'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'content_html' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/editor/keyword-suggestions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'keyword_suggestions' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'post_id' => $post_id,
					'use_ai'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'seed'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/editor/citation-track',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'citation_track' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'post_id' => $post_id,
					'prompt'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public function can_edit( WP_REST_Request $request ): bool {
		$post_id = (int) $request['post_id'];
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public function can_manage( WP_REST_Request $request ): bool {
		return $this->can_edit( $request ) && current_user_can( 'manage_options' );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function analyze( WP_REST_Request $request ) {
		$post_id  = (int) $request['post_id'];
		$mode     = 'rescore' === $request['mode'] ? 'rescore' : 'cached';
		$keywords = self::normalize_keywords( $request['keywords'] );
		$focus    = trim( (string) $request['focus'] );

		$unique = array();
		foreach ( $keywords as $kw ) {
			$unique[ $kw ] = SWPS_Editor_Input::keyword_used_elsewhere( $kw, $post_id );
		}

		return rest_ensure_response(
			array(
				'unique'    => (object) $unique,
				'aeo'       => $this->aeo_snapshot( $post_id, $mode ),
				'schema'    => array( 'nodes' => self::schema_nodes( (string) $request['content_html'], SWPS_Schema_Validator::load_rules_manifest() ) ),
				'citations' => $this->citation_snapshot( $post_id, $focus ),
			)
		);
	}

	/**
	 * Build the AEO response shape from already-read values. The shape is
	 * identical in every case; error and code are only added when given.
	 *
	 * @param array<string,mixed> $meta  Keys: enabled, scanned, total, subscores, sub_queries, stale.
	 * @param string|null         $error Budget or scoring message.
	 * @param string|null         $code  Error code.
	 * @return array<string,mixed>
	 */
	public static function snapshot_shape( array $meta, ?string $error, ?string $code ): array {
		$raw       = is_array( $meta['subscores'] ?? null ) ? $meta['subscores'] : array();
		$subscores = array();
		foreach ( array( 'extractability', 'markup', 'authority', 'coverage' ) as $dim ) {
			$v                 = $raw[ $dim ] ?? '';
			$subscores[ $dim ] = '' === $v ? null : (int) $v;
		}
		$total   = $meta['total'] ?? '';
		$scanned = (int) ( $meta['scanned'] ?? 0 );

		$out = array(
			'enabled'     => (bool) ( $meta['enabled'] ?? false ),
			'scanned'     => $scanned > 0 ? $scanned : null,
			'total'       => '' === $total ? null : (int) $total,
			'subscores'   => $subscores,
			'sub_queries' => is_array( $meta['sub_queries'] ?? null ) ? array_values( $meta['sub_queries'] ) : array(),
			'stale'       => (bool) ( $meta['stale'] ?? false ),
		);
		if ( null !== $error ) {
			$out['error'] = $error;
			$out['code']  = (string) $code;
		}
		return $out;
	}

	/**
	 * Cached AEO values. A rescore is the only path that can spend AI budget
	 * (coverage), so it is explicit and guarded by the monthly cap. When the
	 * cap refuses, the cached snapshot is still returned with the message.
	 *
	 * @return array<string,mixed>
	 */
	private function aeo_snapshot( int $post_id, string $mode ): array {
		$error = null;
		$code  = null;
		if ( 'rescore' === $mode ) {
			$budget = SWPS_Autopilot_Guardian::check_budget();
			if ( is_wp_error( $budget ) ) {
				$error = $budget->get_error_message();
				$code  = (string) $budget->get_error_code();
			} else {
				$this->aeo->do_score( $post_id );
			}
		}

		$post      = get_post( $post_id );
		$payload   = get_post_meta( $post_id, SWPS_AEO_Scorer::META_COVERAGE_PAYLOAD, true );
		$subscores = array();
		foreach ( array( 'extractability', 'markup', 'authority', 'coverage' ) as $dim ) {
			$subscores[ $dim ] = get_post_meta( $post_id, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . $dim, true );
		}

		return self::snapshot_shape(
			array(
				'enabled'     => (bool) get_option( SWPS_AEO_Scorer::OPTION_COVERAGE_ENABLED ),
				'scanned'     => get_post_meta( $post_id, SWPS_AEO_Scorer::META_LAST_SCAN, true ),
				'total'       => get_post_meta( $post_id, SWPS_AEO_Scorer::META_TOTAL, true ),
				'subscores'   => $subscores,
				'sub_queries' => is_array( $payload ) ? (array) ( $payload['sub_queries'] ?? array() ) : array(),
				'stale'       => $post instanceof WP_Post && is_array( $payload ) && ( $payload['hash'] ?? '' ) !== md5( $post->post_content ),
			),
			$error,
			$code
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function citation_snapshot( int $post_id, string $focus ): array {
		foreach ( $this->citations->prompt_states() as $entry ) {
			if ( (int) ( $entry['post_id'] ?? 0 ) === $post_id ) {
				return array(
					'tracked'          => true,
					'prompt'           => (string) ( $entry['prompt'] ?? '' ),
					'states'           => (object) (array) ( $entry['states'] ?? array() ),
					'suggested_prompt' => $focus,
				);
			}
		}
		return array(
			'tracked'          => false,
			'prompt'           => null,
			'states'           => (object) array(),
			'suggested_prompt' => $focus,
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function keyword_suggestions( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$gsc     = array();

		if ( $this->gsc->is_connected() ) {
			$url = get_permalink( $post_id );
			if ( $url ) {
				foreach ( $this->gsc->get_page_queries( $url, 90 ) as $row ) {
					$query = (string) ( $row['keys'][0] ?? '' );
					if ( '' !== $query ) {
						$gsc[] = array(
							'query'    => $query,
							'clicks'   => (int) ( $row['clicks'] ?? 0 ),
							'position' => round( (float) ( $row['position'] ?? 0 ), 1 ),
						);
					}
				}
			}
		}

		$ai = array();
		if ( $request['use_ai'] ) {
			$budget = SWPS_Autopilot_Guardian::check_budget();
			if ( is_wp_error( $budget ) ) {
				return new WP_Error( $budget->get_error_code(), $budget->get_error_message(), array( 'status' => 402 ) );
			}
			$seed = trim( (string) $request['seed'] );
			if ( '' === $seed ) {
				$seed = (string) get_the_title( $post_id );
			}
			$result = $this->keywords->suggest_keywords( $seed );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
			}
			foreach ( $result as $row ) {
				if ( ! empty( $row['keyword'] ) ) {
					$ai[] = array(
						'keyword'    => (string) $row['keyword'],
						'intent'     => (string) ( $row['intent'] ?? '' ),
						'difficulty' => (string) ( $row['difficulty'] ?? '' ),
					);
				}
			}
		}

		return rest_ensure_response(
			array(
				'gsc' => $gsc,
				'ai'  => $ai,
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function citation_track( WP_REST_Request $request ) {
		$result = $this->citations->prompts()->add_prompt( trim( (string) $request['prompt'] ), (int) $request['post_id'] );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => (bool) $result ) );
	}
}
