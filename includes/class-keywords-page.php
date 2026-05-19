<?php
/**
 * Keywords admin page — AI suggestions, tracked keywords, opportunities.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Keywords_Page {

	private SWPS_Keyword_Tracker $tracker;

	public function __construct( SWPS_Keyword_Tracker $tracker ) {
		$this->tracker = $tracker;

		// Register after parent menu (priority 20).
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );

		// AJAX endpoints.
		add_action( 'wp_ajax_swps_suggest_keywords', array( $this, 'ajax_suggest' ) );
		add_action( 'wp_ajax_swps_track_keyword', array( $this, 'ajax_track' ) );
		add_action( 'wp_ajax_swps_untrack_keyword', array( $this, 'ajax_untrack' ) );
		add_action( 'wp_ajax_swps_link_keyword', array( $this, 'ajax_link' ) );
		add_action( 'wp_ajax_swps_keyword_history', array( $this, 'ajax_history' ) );
		add_action( 'wp_ajax_swps_get_keywords', array( $this, 'ajax_get_keywords' ) );
		add_action( 'wp_ajax_swps_get_opportunities', array( $this, 'ajax_get_opportunities' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Keywords', 'stratawp-seo' ),
			__( 'Keywords', 'stratawp-seo' ),
			'manage_options',
			'swps-keywords',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$gsc_connected = stratawp_seo()->search_console->is_connected();
		include SWPS_PLUGIN_DIR . 'templates/keywords-page.php';
	}

	public function ajax_suggest(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$seed = sanitize_text_field( $_POST['seed_topic'] ?? '' );
		if ( empty( $seed ) ) {
			wp_send_json_error( array( 'message' => 'Please enter a seed topic.' ) );
		}

		$suggestions = $this->tracker->suggest_keywords( $seed );
		if ( is_wp_error( $suggestions ) ) {
			wp_send_json_error( array( 'message' => $suggestions->get_error_message() ) );
		}

		wp_send_json_success( $suggestions );
	}

	public function ajax_track(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( empty( $keyword ) ) {
			wp_send_json_error( array( 'message' => 'Keyword is required.' ) );
		}

		$this->tracker->track_keyword( $keyword, $post_id );
		wp_send_json_success();
	}

	public function ajax_untrack(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
		if ( empty( $keyword ) ) {
			wp_send_json_error( array( 'message' => 'Keyword is required.' ) );
		}
		$this->tracker->untrack_keyword( $keyword );
		wp_send_json_success();
	}

	public function ajax_link(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( empty( $keyword ) || ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Keyword and post ID are required.' ) );
		}
		$this->tracker->link_to_post( $keyword, $post_id );
		wp_send_json_success();
	}

	public function ajax_history(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
		if ( empty( $keyword ) ) {
			wp_send_json_error( array( 'message' => 'Keyword is required.' ) );
		}
		$days    = max( 7, absint( $_POST['days'] ?? 90 ) );
		$history = $this->tracker->get_keyword_history( $keyword, $days );
		wp_send_json_success( $history );
	}

	public function ajax_get_keywords(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$keywords = $this->tracker->get_tracked_keywords();

		// Enrich with post titles.
		foreach ( $keywords as &$kw ) {
			$kw['post_title'] = '';
			$kw['post_url']   = '';
			if ( ! empty( $kw['post_id'] ) ) {
				$post             = get_post( (int) $kw['post_id'] );
				$kw['post_title'] = $post ? $post->post_title : '';
				$kw['post_url']   = $post ? get_edit_post_link( $post->ID, 'raw' ) : '';
			}
		}
		unset( $kw );

		wp_send_json_success( $keywords );
	}

	public function ajax_get_opportunities(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$opportunities = $this->tracker->get_opportunities();

		foreach ( $opportunities as &$opp ) {
			$opp['post_title'] = '';
			if ( ! empty( $opp['post_id'] ) ) {
				$post              = get_post( (int) $opp['post_id'] );
				$opp['post_title'] = $post ? $post->post_title : '';
			}
		}
		unset( $opp );

		wp_send_json_success( $opportunities );
	}
}
