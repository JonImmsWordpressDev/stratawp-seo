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
				wp_enqueue_style( 'swps-aeo', SWPS_PLUGIN_URL . 'admin/css/aeo.css', array(), SWPS_VERSION );
			}
			if ( file_exists( SWPS_PLUGIN_DIR . 'admin/js/aeo-optimizer.js' ) ) {
				wp_enqueue_script(
					'swps-aeo-optimizer',
					SWPS_PLUGIN_URL . 'admin/js/aeo-optimizer.js',
					array( 'jquery' ),
					SWPS_VERSION,
					true
				);
				wp_localize_script( 'swps-aeo-optimizer', 'swpsAeo', $this->localize_data() );
			}
		}

		// Editor-panel assets (Task 17/18). Optimizer enqueues them on post edit screens.
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& file_exists( SWPS_PLUGIN_DIR . 'admin/js/aeo-editor-panel.js' ) ) {
			wp_enqueue_style( 'swps-aeo', SWPS_PLUGIN_URL . 'admin/css/aeo.css', array(), SWPS_VERSION );
			wp_enqueue_script(
				'swps-aeo-editor-panel',
				SWPS_PLUGIN_URL . 'admin/js/aeo-editor-panel.js',
				array( 'jquery', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ),
				SWPS_VERSION,
				true
			);
			wp_localize_script( 'swps-aeo-editor-panel', 'swpsAeo', $this->localize_data() );
		}
	}

	/** @return array<string, mixed> */
	private function localize_data(): array {
		return array(
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
		$limit  = 10;
		$types  = (array) get_option( 'swps_aeo_post_types', array( 'post', 'page' ) );

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
		$done  = $next >= $total;

		wp_send_json_success( array(
			'scored'      => count( $results ),
			'next_offset' => $next,
			'total'       => $total,
			'done'        => $done,
			'results'     => $results,
		) );
	}

	public function ajax_score(): void {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'invalid post_id' ), 400 );
		}
		$r = $this->scorer->score_post( $post_id );
		wp_send_json_success( $r );
	}

	public function ajax_propose(): void {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'invalid post_id' ), 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'post_not_found' ), 404 );
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
			'"schema":{"type":"howto|recipe|product|review|qapage|null","reason":"..."}, ' .
			'"projected_score":<int 0-100>}.',
			$post->post_title,
			$expected_type ?? 'none',
			mb_substr( $post->post_content, 0, 8000 )
		);

		$result = $this->ai_provider->chat_json( $system, $user, 4096 );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
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

		update_post_meta( $post_id, self::META_PROPOSAL, wp_json_encode( $proposal ) );

		wp_send_json_success( array( 'proposal' => $proposal ) );
	}

	public function ajax_apply(): void {
		$this->verify_request();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		$post_id          = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$selected_edits   = isset( $_POST['edits'] )   ? array_map( 'intval', (array) wp_unslash( $_POST['edits'] ) )   : array();
		$selected_inserts = isset( $_POST['inserts'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['inserts'] ) ) : array();
		$apply_schema     = ! empty( $_POST['schema'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'post_not_found' ), 404 );
		}

		$raw = (string) get_post_meta( $post_id, self::META_PROPOSAL, true );
		if ( '' === $raw ) {
			wp_send_json_error( array( 'message' => 'no_proposal' ), 400 );
		}
		$proposal = json_decode( $raw, true );
		if ( ! is_array( $proposal ) ) {
			wp_send_json_error( array( 'message' => 'invalid_proposal' ), 500 );
		}

		// Snapshot for undo.
		update_post_meta( $post_id, self::META_SNAPSHOT, wp_json_encode( array(
			'content'     => $post->post_content,
			'schema_type' => get_post_meta( $post_id, self::META_SCHEMA_TYPE, true ),
			'schema_json' => get_post_meta( $post_id, self::META_SCHEMA_JSON, true ),
			'taken_at'    => time(),
		) ) );

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
			update_post_meta( $post_id, self::META_SCHEMA_JSON, wp_json_encode( $proposal['schema']['json'] ) );
			++$applied;
		}

		// Clear cached proposal and re-score.
		delete_post_meta( $post_id, self::META_PROPOSAL );
		$rescore = $this->scorer->score_post( $post_id );

		wp_send_json_success( array(
			'applied_count' => $applied,
			'new_score'     => $rescore['total'],
			'new_subscores' => $rescore['subscores'],
		) );
	}

	public function ajax_undo(): void {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		$raw     = (string) get_post_meta( $post_id, self::META_SNAPSHOT, true );
		if ( '' === $raw ) {
			wp_send_json_error( array( 'message' => 'no_snapshot' ), 404 );
		}
		$snap = json_decode( $raw, true );
		if ( ! is_array( $snap ) ) {
			wp_send_json_error( array( 'message' => 'invalid_snapshot' ), 500 );
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
			update_post_meta( $post_id, self::META_SCHEMA_JSON, (string) $snap['schema_json'] );
		} else {
			delete_post_meta( $post_id, self::META_SCHEMA_JSON );
		}

		delete_post_meta( $post_id, self::META_SNAPSHOT );
		$r = $this->scorer->score_post( $post_id );

		wp_send_json_success( array(
			'restored'  => true,
			'score'     => $r['total'],
			'subscores' => $r['subscores'],
		) );
	}

	public function ajax_dismiss(): void {
		$this->verify_request();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request()
		$post_id   = isset( $_POST['post_id'] )   ? (int) $_POST['post_id']    : 0;
		$dismissed = isset( $_POST['dismissed'] ) ? (bool) $_POST['dismissed'] : true;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $dismissed ) {
			update_post_meta( $post_id, self::META_DISMISSED, 1 );
		} else {
			delete_post_meta( $post_id, self::META_DISMISSED );
		}
		wp_send_json_success( array( 'ok' => true ) );
	}
}
