<?php
/**
 * Keyword Cannibalization Admin — findings page, resolution AJAX, and undo chain.
 *
 * HTML rendering is delegated to templates/cannibalization-finding.php.
 * Actions: Consolidate (301 via SWPS_Redirect_Manager + undo), Differentiate
 * (?swps_focus_kw prefill), Canonicalize (guidance only), Dismiss.
 * All AJAX endpoints: nonce 'swps_cannibal' + manage_options.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cannibalization admin page and AJAX handlers.
 */
class SWPS_Cannibalization_Admin {

	/** AJAX nonce action. */
	private const NONCE = 'swps_cannibal';

	/** Admin page slug. */
	public const PAGE_SLUG = 'swps-cannibalization';

	/**
	 * Register hooks.
	 *
	 * @param SWPS_Cannibalization $engine Detection engine (reserved for future instance calls).
	 */
	public function __construct( SWPS_Cannibalization $engine ) {
		unset( $engine ); // All SWPS_Cannibalization calls below use static methods.

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_swps_cannibal_consolidate', array( $this, 'ajax_consolidate' ) );
		add_action( 'wp_ajax_swps_cannibal_undo', array( $this, 'ajax_undo' ) );
		add_action( 'wp_ajax_swps_cannibal_dismiss', array( $this, 'ajax_dismiss' ) );
		// Focus-keyword prefill: ?swps_focus_kw on an edit screen pre-fills the meta box field.
		add_action( 'add_meta_boxes', array( $this, 'maybe_schedule_focus_kw_prefill' ) );
	}

	// =========================================================================
	// MENU
	// =========================================================================

	/**
	 * Register the submenu page under stratawp-seo.
	 */
	public function register_menu(): void {
		$count = SWPS_Cannibalization::count_open();
		$label = $count > 0
			? sprintf(
				/* translators: %d: open finding count */
				__( 'Cannibalization <span class="update-plugins count-%1$d"><span class="update-count">%1$d</span></span>', 'stratawp-seo' ),
				$count
			)
			: __( 'Cannibalization', 'stratawp-seo' );

		add_submenu_page(
			'stratawp-seo',
			__( 'Keyword Cannibalization', 'stratawp-seo' ),
			$label,
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	// =========================================================================
	// ASSETS
	// =========================================================================

	/**
	 * Enqueue page-specific assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'swps-cannibalization',
			SWPS_PLUGIN_URL . 'admin/css/pages/cannibalization.css',
			array( 'swps-tokens', 'swps-shell', 'swps-components' ),
			SWPS_VERSION
		);
		wp_enqueue_script(
			'swps-cannibalization',
			SWPS_PLUGIN_URL . 'admin/js/cannibalization.js',
			array( 'jquery', 'swps-shell' ),
			SWPS_VERSION,
			true
		);
		wp_localize_script(
			'swps-cannibalization',
			'swpsCannibalization',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'confirm_consolidate' => __( 'Create a 301 redirect from the loser URL to the winner URL?', 'stratawp-seo' ),
					'confirm_undo'        => __( 'Remove the redirect and restore the original post status?', 'stratawp-seo' ),
					'processing'          => __( 'Processing…', 'stratawp-seo' ),
					'error'               => __( 'An error occurred. Please try again.', 'stratawp-seo' ),
				),
			)
		);
	}

	// =========================================================================
	// PAGE RENDER
	// =========================================================================

	/**
	 * Render the cannibalization findings page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stratawp-seo' ) );
		}

		$findings = SWPS_Cannibalization::get_open_findings();
		$title    = __( 'Keyword Cannibalization', 'stratawp-seo' );
		$subtitle = __( 'Queries where multiple pages split impressions — review and resolve below.', 'stratawp-seo' );
		$actions  = array();
		require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';

		echo '<div class="wrap swps-cannibalization-wrap">';

		if ( empty( $findings ) ) {
			echo '<div class="swps-empty-state" style="text-align:center;padding:40px 0;">';
			echo '<span style="font-size:48px;">✓</span>';
			echo '<h2>' . esc_html__( 'No cannibalization detected', 'stratawp-seo' ) . '</h2>';
			echo '<p style="color:var(--swps-text-muted)">' . esc_html__( 'All queries are mapping cleanly to individual pages.', 'stratawp-seo' ) . '</p>';
			echo '</div></div>';
			return;
		}

		$by_severity = array(
			'cannibal' => array(),
			'review'   => array(),
		);
		foreach ( $findings as $finding ) {
			$sev = $finding->severity ?? 'cannibal';
			if ( isset( $by_severity[ $sev ] ) ) {
				$by_severity[ $sev ][] = $finding;
			}
		}

		foreach ( $by_severity as $severity => $group ) {
			if ( empty( $group ) ) {
				continue;
			}
			$group_label = 'cannibal' === $severity
				? esc_html__( 'Confirmed Cannibalization', 'stratawp-seo' )
				: esc_html__( 'Needs Review (archive/post pairs)', 'stratawp-seo' );

			printf( '<h2>%s</h2>', esc_html( $group_label ) );

			foreach ( $group as $row ) {
				$id         = (int) $row->id;
				$query      = (string) ( $row->query ?? '' );
				$pages      = json_decode( (string) ( $row->pages ?? '[]' ), true );
				$pages      = is_array( $pages ) ? $pages : array();
				$has_undo   = ! empty( $row->undo_json );
				$winner_idx = SWPS_Cannibalization::pick_winner( $pages );
				require SWPS_PLUGIN_DIR . 'templates/cannibalization-finding.php';
			}
		}

		echo '</div>';
	}

	// =========================================================================
	// FOCUS KEYWORD PREFILL
	// =========================================================================

	/**
	 * When a post edit screen carries ?swps_focus_kw=<term>, enqueue inline JS
	 * that pre-fills the focus keyword field if it is currently empty.
	 *
	 * Runs on add_meta_boxes (admin only, authenticated context; no explicit
	 * nonce needed — reading read-only query args).
	 */
	public function maybe_schedule_focus_kw_prefill(): void {
		if ( ! is_admin() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$focus_kw = sanitize_text_field( wp_unslash( $_GET['swps_focus_kw'] ?? '' ) );
		if ( empty( $focus_kw ) ) {
			return;
		}
		$post_id = absint( wp_unslash( $_GET['post'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			return;
		}

		$existing = get_post_meta( $post_id, '_swps_focus_keyword', true );
		wp_add_inline_script(
			'jquery',
			sprintf(
				"jQuery(function($){
					var existing = %s;
					var field = $('#_swps_focus_keyword');
					if (!existing && field.length && !field.val().trim()) {
						field.val(%s);
						field.trigger('input');
					}
				});",
				wp_json_encode( $existing ),
				wp_json_encode( $focus_kw )
			)
		);
	}

	// =========================================================================
	// AJAX HANDLERS
	// =========================================================================

	/**
	 * AJAX: Create 301 redirect from loser to winner; store undo payload.
	 *
	 * POST: nonce, finding_id, winner_url, loser_url, draft_loser (0|1).
	 */
	public function ajax_consolidate(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$finding_id  = absint( $_POST['finding_id'] ?? 0 );
		$winner_url  = esc_url_raw( wp_unslash( $_POST['winner_url'] ?? '' ) );
		$loser_url   = esc_url_raw( wp_unslash( $_POST['loser_url'] ?? '' ) );
		$draft_loser = ! empty( $_POST['draft_loser'] );

		if ( ! $finding_id || empty( $winner_url ) || empty( $loser_url ) ) {
			wp_send_json_error( array( 'message' => 'Missing required fields.' ) );
		}

		// Server-side double-consolidate guard: only open findings may be consolidated.
		$finding = SWPS_Cannibalization::get_finding( $finding_id );
		if ( ! $finding || 'open' !== $finding->status ) {
			wp_send_json_error( array( 'message' => 'Finding is not open — it may already be consolidated or dismissed.' ) );
		}

		$loser_path  = (string) wp_parse_url( $loser_url, PHP_URL_PATH );
		$winner_path = (string) wp_parse_url( $winner_url, PHP_URL_PATH );
		if ( empty( $loser_path ) || empty( $winner_path ) ) {
			wp_send_json_error( array( 'message' => 'Could not parse URL paths.' ) );
		}

		$redirect_manager = stratawp_seo()->redirect_manager;
		$redirect_id      = $redirect_manager->add_redirect( $loser_path, $winner_path, 301 );

		if ( is_wp_error( $redirect_id ) ) {
			wp_send_json_error( array( 'message' => $redirect_id->get_error_message() ) );
		}
		if ( false === $redirect_id ) {
			wp_send_json_error( array( 'message' => 'Redirect could not be created.' ) );
		}

		$undo_payload = array(
			'redirect_id'  => (int) $redirect_id,
			'post_id'      => 0,
			'prior_status' => '',
		);

		if ( $draft_loser ) {
			$loser_post_id = url_to_postid( $loser_url );
			if ( $loser_post_id ) {
				$prior_status = get_post_status( $loser_post_id );
				if ( 'publish' === $prior_status ) {
					wp_update_post(
						array(
							'ID'          => $loser_post_id,
							'post_status' => 'draft',
						)
					);
					$undo_payload['post_id']      = $loser_post_id;
					$undo_payload['prior_status'] = $prior_status;
				}
			}
		}

		SWPS_Cannibalization::store_undo( $finding_id, $undo_payload );
		wp_send_json_success(
			array(
				'message'     => __( 'Redirect created. Finding marked resolved.', 'stratawp-seo' ),
				'finding_id'  => $finding_id,
				'redirect_id' => (int) $redirect_id,
			)
		);
	}

	/**
	 * AJAX: Delete the redirect and restore post status (undo consolidation).
	 *
	 * POST: nonce, finding_id.
	 */
	public function ajax_undo(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$finding_id = absint( $_POST['finding_id'] ?? 0 );
		if ( ! $finding_id ) {
			wp_send_json_error( array( 'message' => 'Missing finding_id.' ) );
		}

		$payload = SWPS_Cannibalization::get_undo( $finding_id );
		if ( ! $payload ) {
			wp_send_json_error( array( 'message' => 'No undo payload found.' ) );
		}

		global $wpdb;
		$redirect_id = (int) ( $payload['redirect_id'] ?? 0 );
		if ( $redirect_id > 0 ) {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'swps_redirects',
				array( 'id' => $redirect_id ),
				array( '%d' )
			);
			delete_transient( 'swps_redirects_cache' );
		}

		$post_id      = (int) ( $payload['post_id'] ?? 0 );
		$prior_status = (string) ( $payload['prior_status'] ?? '' );
		if ( $post_id > 0 && ! empty( $prior_status ) ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $prior_status,
				)
			);
		}

		SWPS_Cannibalization::clear_undo( $finding_id );
		wp_send_json_success(
			array(
				'message'    => __( 'Undo complete. Finding re-opened.', 'stratawp-seo' ),
				'finding_id' => $finding_id,
			)
		);
	}

	/**
	 * AJAX: Dismiss a finding.
	 *
	 * POST: nonce, finding_id.
	 */
	public function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$finding_id = absint( $_POST['finding_id'] ?? 0 );
		if ( ! $finding_id ) {
			wp_send_json_error( array( 'message' => 'Missing finding_id.' ) );
		}

		if ( ! SWPS_Cannibalization::set_status( $finding_id, 'dismissed' ) ) {
			wp_send_json_error( array( 'message' => 'Could not dismiss finding.' ) );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Finding dismissed.', 'stratawp-seo' ),
				'finding_id' => $finding_id,
			)
		);
	}
}
