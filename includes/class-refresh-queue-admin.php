<?php
/**
 * Refresh Queue — admin page listing decay-flagged posts with sparklines and
 * a one-click path into the reviewed AEO propose/apply/undo pipeline.
 *
 * "Propose refresh" sets a short-lived transient (swps_refresh_ctx_{post_id})
 * then deep-links to the AEO Optimize page with ?focus_post=N. The AEO page's
 * own propose flow runs SWPS_AEO_Optimizer::do_propose(), which reads the
 * transient and appends the refresh instruction to the prompt. Apply/undo
 * always go through the existing reviewed-diff flow — never auto-applied.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin surface for the content decay refresh queue.
 */
class SWPS_Refresh_Queue_Admin {

	public const NONCE = 'swps_refresh_queue';

	/** Transient prefix read by SWPS_AEO_Optimizer::do_propose(). */
	public const CTX_TRANSIENT_PREFIX = 'swps_refresh_ctx_';

	/** Transient lifetime: long enough to land on the AEO page and propose. */
	private const CTX_TTL = 900; // 15 minutes.

	/**
	 * Refresh instruction appended to the AEO propose prompt.
	 * Conservative by design: never invent data, always reviewed before apply.
	 */
	public const REFRESH_INSTRUCTION =
		'REFRESH CONTEXT: This post was flagged for content decay (declining search clicks). ' .
		'Prioritize freshness: update outdated facts and year references cautiously — NEVER invent statistics or data; ' .
		"add a short 'Updated' note near the top; refresh the TL;DR/summary if present. " .
		'Prefer minimal, verifiable changes over rewrites.';

	/**
	 * Metric history store (sparkline series).
	 *
	 * @var SWPS_Metric_History
	 */
	private SWPS_Metric_History $history;

	/**
	 * Wire menu, assets, and AJAX handlers.
	 *
	 * @param SWPS_Metric_History $history Metric history store.
	 */
	public function __construct( SWPS_Metric_History $history ) {
		$this->history = $history;

		add_action( 'admin_menu', array( $this, 'register_menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_swps_refresh_propose', array( $this, 'ajax_propose' ) );
		add_action( 'wp_ajax_swps_refresh_dismiss', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Enqueue jQuery for the inline page script.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'stratawp-seo_page_swps-refresh-queue' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
	}

	/**
	 * Register the Refresh Queue submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Refresh Queue', 'stratawp-seo' ),
			__( 'Refresh Queue', 'stratawp-seo' ),
			'manage_options',
			'swps-refresh-queue',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the queue page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
		}
		require SWPS_PLUGIN_DIR . 'templates/refresh-queue-page.php';
	}

	// --- Queue ---

	/**
	 * Posts currently flagged for decay, sorted by traffic-at-risk
	 * (prior-window clicks × click-change fraction, descending).
	 *
	 * @return array<int, array<string, mixed>> Queue rows.
	 */
	public function get_queue(): array {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'meta_key'       => SWPS_Decay_Watchdog::META_DECAY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$rows = array();

		foreach ( $posts as $post ) {
			$decay = get_post_meta( $post->ID, SWPS_Decay_Watchdog::META_DECAY, true );
			if ( ! is_array( $decay ) || empty( $decay['reason'] ) ) {
				continue;
			}

			$prior_clicks  = (int) ( $decay['prior']['clicks'] ?? 0 );
			$recent_clicks = (int) ( $decay['recent']['clicks'] ?? 0 );
			$click_change  = (float) ( $decay['click_change'] ?? 0 );

			$clicks_series = array_map(
				static fn( array $p ): float => $p['value'],
				$this->history->series( $post->ID, 'gsc_clicks', 180 )
			);
			$aeo_series    = array_map(
				static fn( array $p ): float => $p['value'],
				$this->history->series( $post->ID, 'aeo_score', 180 )
			);

			$rows[] = array(
				'post_id'         => $post->ID,
				'title'           => $post->post_title,
				'edit_url'        => get_edit_post_link( $post->ID, 'raw' ),
				'permalink'       => get_permalink( $post->ID ),
				'reason'          => (string) $decay['reason'],
				'click_change'    => $click_change,
				'prior_clicks'    => $prior_clicks,
				'recent_clicks'   => $recent_clicks,
				'staleness'       => (array) ( $decay['staleness'] ?? array() ),
				'flagged_at'      => (int) ( $decay['flagged_at'] ?? 0 ),
				'traffic_at_risk' => $prior_clicks * abs( $click_change ),
				'clicks_svg'      => self::sparkline( $clicks_series ),
				'aeo_svg'         => self::sparkline( $aeo_series ),
			);
		}

		usort( $rows, static fn( array $a, array $b ): int => $b['traffic_at_risk'] <=> $a['traffic_at_risk'] );

		return $rows;
	}

	// --- Sparkline (pure static, unit-tested) ---

	/**
	 * Build a tiny inline SVG sparkline polyline from a value series.
	 *
	 * Returns '' when fewer than two points (nothing to draw). A flat series
	 * renders as a horizontal midline. Coordinates are rounded to 2 decimals.
	 *
	 * @param float[] $values Ordered series values.
	 * @param int     $w      SVG width (viewBox units).
	 * @param int     $h      SVG height (viewBox units).
	 * @return string SVG markup or empty string.
	 */
	public static function sparkline( array $values, int $w = 120, int $h = 28 ): string {
		$values = array_values( array_map( 'floatval', $values ) );
		$n      = count( $values );
		if ( $n < 2 ) {
			return '';
		}

		$pad   = 2.0;
		$min   = min( $values );
		$max   = max( $values );
		$range = $max - $min;

		$points = array();
		foreach ( $values as $i => $v ) {
			$x = $pad + ( $i / ( $n - 1 ) ) * ( $w - 2 * $pad );
			if ( $range > 0.0 ) {
				$y = $pad + ( 1 - ( $v - $min ) / $range ) * ( $h - 2 * $pad );
			} else {
				$y = $h / 2;
			}
			$points[] = round( $x, 2 ) . ',' . round( $y, 2 );
		}

		return sprintf(
			'<svg viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
			. '<polyline fill="none" stroke="currentColor" stroke-width="1.5" points="%3$s"/></svg>',
			$w,
			$h,
			implode( ' ', $points )
		);
	}

	// --- AJAX ---

	/**
	 * Prepare a refresh proposal: set the refresh-context transient and return
	 * the AEO deep-link. The AEO page's focus_post flow performs the actual
	 * do_propose() call (one AI call, existing reviewed-diff modal).
	 */
	public function ajax_propose(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'stratawp-seo' ) ) );
		}

		set_transient( self::CTX_TRANSIENT_PREFIX . $post_id, 1, self::CTX_TTL );

		$redirect = add_query_arg(
			array(
				'page'       => 'swps-aeo-optimize',
				'focus_post' => $post_id,
			),
			admin_url( 'admin.php' )
		);

		wp_send_json_success( array( 'redirect' => $redirect ) );
	}

	/**
	 * Dismiss a flagged post: clear the decay payload and start the cooldown
	 * so the next scan does not immediately re-flag it.
	 */
	public function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'stratawp-seo' ) ) );
		}

		delete_post_meta( $post_id, SWPS_Decay_Watchdog::META_DECAY );
		update_post_meta( $post_id, SWPS_Decay_Watchdog::META_FLAGGED, time() );

		wp_send_json_success();
	}

	// --- Labels ---

	/**
	 * Human label for a decay reason code.
	 *
	 * @param string $reason Reason code from assess().
	 * @return string Translated label.
	 */
	public static function reason_label( string $reason ): string {
		switch ( $reason ) {
			case 'position_drop':
				return __( 'Ranking dropped', 'stratawp-seo' );
			case 'demand_drop':
				return __( 'Search demand fell', 'stratawp-seo' );
			case 'ctr_drop':
				return __( 'CTR fell', 'stratawp-seo' );
			default:
				return __( 'Cause unclear', 'stratawp-seo' );
		}
	}

	/**
	 * Human label for a staleness flag code.
	 *
	 * @param string $flag Flag from staleness_flags().
	 * @return string Translated label.
	 */
	public static function staleness_label( string $flag ): string {
		switch ( $flag ) {
			case 'not_fresh':
				return __( 'Not updated in 12+ months', 'stratawp-seo' );
			case 'no_current_year':
				return __( 'No current-year mention', 'stratawp-seo' );
			default:
				return $flag;
		}
	}
}
