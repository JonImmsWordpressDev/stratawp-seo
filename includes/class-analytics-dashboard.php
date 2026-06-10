<?php
/**
 * Analytics dashboard admin page, metabox, and post list column.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Analytics_Dashboard {

	private SWPS_Analytics_Tracker $tracker;
	private SWPS_Search_Console $search_console;

	public function __construct( SWPS_Analytics_Tracker $tracker, SWPS_Search_Console $search_console ) {
		$this->tracker        = $tracker;
		$this->search_console = $search_console;

		// Admin menu — priority 20 so parent menu (registered at 10) exists first.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );

		// AJAX endpoints.
		add_action( 'wp_ajax_swps_analytics_overview', array( $this, 'ajax_overview' ) );
		add_action( 'wp_ajax_swps_analytics_top_pages', array( $this, 'ajax_top_pages' ) );
		add_action( 'wp_ajax_swps_analytics_top_queries', array( $this, 'ajax_top_queries' ) );
		add_action( 'wp_ajax_swps_analytics_post_stats', array( $this, 'ajax_post_stats' ) );
		add_action( 'wp_ajax_swps_analytics_ai_referrals', array( $this, 'ajax_ai_referrals' ) );
		add_action( 'wp_ajax_swps_gsc_disconnect', array( $this, 'ajax_disconnect_gsc' ) );
		add_action( 'wp_ajax_swps_gsc_refresh', array( $this, 'ajax_refresh_gsc' ) );
		add_action( 'wp_ajax_swps_gsc_save_property', array( $this, 'ajax_save_property' ) );

		// Post list column.
		add_filter( 'manage_posts_columns', array( $this, 'add_views_column' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_views_column' ), 10, 2 );
		add_filter( 'manage_edit-post_sortable_columns', array( $this, 'sortable_views_column' ) );
		add_filter( 'posts_clauses', array( $this, 'handle_views_orderby' ), 10, 2 );

		// Post metabox.
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
	}

	/**
	 * Register the Analytics submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Analytics', 'stratawp-seo' ),
			__( 'Analytics', 'stratawp-seo' ),
			'manage_options',
			'swps-analytics',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the analytics page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$gsc_connected = $this->search_console->is_connected();
		$gsc_property  = get_option( 'swps_gsc_property', '' );
		$gsc_auth_url  = $this->search_console->get_auth_url();
		$properties    = $gsc_connected ? $this->search_console->get_properties() : array();

		// AI Bot Analytics — server-side crawler insights.
		$bot_tracker = stratawp_seo()->bot_analytics_tracker ?? null;
		$bot_data    = array();
		if ( $bot_tracker instanceof SWPS_Bot_Analytics_Tracker ) {
			$bot_data = array(
				'totals'    => $bot_tracker->get_totals( 30 ),
				'bots'      => $bot_tracker->get_bot_summary( 30 ),
				'top_pages' => $bot_tracker->get_top_pages( 30, 15 ),
				'gaps'      => $bot_tracker->get_gap_posts( 30, 10, isset( $_GET['gap_sort'] ) && 'score' === $_GET['gap_sort'] ? 'score' : 'date' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'top_404s'  => $bot_tracker->get_top_404s( 30, 10 ),
			);
		}

		include SWPS_PLUGIN_DIR . 'templates/analytics-page.php';
	}

	/**
	 * AJAX: Get overview data for the dashboard.
	 */
	public function ajax_overview(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$days = absint( $_POST['days'] ?? 30 );
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;

		$daily_stats = $this->tracker->get_daily_stats( $days );

		// Calculate totals.
		$total_views   = array_sum( array_column( $daily_stats, 'views' ) );
		$avg_time      = $total_views > 0 ? round( array_sum( array_column( $daily_stats, 'avg_time' ) ) / max( count( $daily_stats ), 1 ) ) : 0;
		$total_bounces = array_sum( array_column( $daily_stats, 'bounces' ) );
		$bounce_rate   = $total_views > 0 ? round( ( $total_bounces / $total_views ) * 100 ) : 0;

		// Previous period for comparison.
		$prev_stats = $this->tracker->get_daily_stats( $days * 2 );
		$prev_views = 0;

		foreach ( $prev_stats as $row ) {
			if ( $row['date'] < gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) ) {
				$prev_views += (int) $row['views'];
			}
		}

		$views_change = $prev_views > 0 ? round( ( ( $total_views - $prev_views ) / $prev_views ) * 100 ) : 0;

		$result = array(
			'daily'        => $daily_stats,
			'total_views'  => $total_views,
			'avg_time'     => $avg_time,
			'bounce_rate'  => $bounce_rate,
			'views_change' => $views_change,
		);

		// Add GSC data if connected.
		if ( $this->search_console->is_connected() ) {
			$gsc_data = $this->search_console->get_search_data( $days );

			$total_clicks      = 0;
			$total_impressions = 0;

			foreach ( $gsc_data['daily'] ?? array() as $row ) {
				$total_clicks      += $row['clicks'] ?? 0;
				$total_impressions += $row['impressions'] ?? 0;
			}

			$result['gsc_clicks']      = $total_clicks;
			$result['gsc_impressions'] = $total_impressions;
			$result['gsc_daily']       = $gsc_data['daily'] ?? array();
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Get top pages.
	 */
	public function ajax_top_pages(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$days  = absint( $_POST['days'] ?? 30 );
		$pages = $this->tracker->get_top_pages( $days );

		// Enrich with post titles.
		foreach ( $pages as &$page ) {
			$post                = get_post( (int) $page['post_id'] );
			$page['title']       = $post ? $post->post_title : __( '(Unknown)', 'stratawp-seo' );
			$page['url']         = $post ? get_permalink( $post ) : '';
			$page['bounce_rate'] = $page['views'] > 0
				? round( ( $page['bounces'] / $page['views'] ) * 100 )
				: 0;
		}

		// Merge GSC data if connected.
		if ( $this->search_console->is_connected() ) {
			$gsc_data = $this->search_console->get_search_data( $days );

			$gsc_by_url = array();
			foreach ( $gsc_data['pages'] ?? array() as $row ) {
				$url                = $row['keys'][0] ?? '';
				$gsc_by_url[ $url ] = $row;
			}

			foreach ( $pages as &$page ) {
				if ( ! empty( $page['url'] ) && isset( $gsc_by_url[ $page['url'] ] ) ) {
					$gsc_row                 = $gsc_by_url[ $page['url'] ];
					$page['gsc_clicks']      = $gsc_row['clicks'] ?? 0;
					$page['gsc_impressions'] = $gsc_row['impressions'] ?? 0;
					$page['gsc_position']    = round( $gsc_row['position'] ?? 0, 1 );
				}
			}
		}

		wp_send_json_success( $pages );
	}

	/**
	 * AJAX: Get top queries (GSC only).
	 */
	public function ajax_top_queries(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		if ( ! $this->search_console->is_connected() ) {
			wp_send_json_error( array( 'message' => 'Search Console not connected.' ) );
		}

		$days     = absint( $_POST['days'] ?? 30 );
		$gsc_data = $this->search_console->get_search_data( $days );
		$queries  = array();

		foreach ( $gsc_data['queries'] ?? array() as $row ) {
			$queries[] = array(
				'query'       => $row['keys'][0] ?? '',
				'clicks'      => $row['clicks'] ?? 0,
				'impressions' => $row['impressions'] ?? 0,
				'ctr'         => round( ( $row['ctr'] ?? 0 ) * 100, 1 ),
				'position'    => round( $row['position'] ?? 0, 1 ),
			);
		}

		wp_send_json_success( $queries );
	}

	/**
	 * AJAX: Get stats for a single post (used by metabox).
	 */
	public function ajax_post_stats(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
		}

		$stats_7d  = $this->tracker->get_post_stats( $post_id, 7 );
		$stats_30d = $this->tracker->get_post_stats( $post_id, 30 );

		$result = array(
			'views_7d'         => $stats_7d['views'],
			'views_30d'        => $stats_30d['views'],
			'avg_time_on_page' => $stats_30d['avg_time_on_page'],
			'avg_scroll_depth' => $stats_30d['avg_scroll_depth'],
			'bounce_rate'      => $stats_30d['bounce_rate'],
		);

		// Bot crawler stats for this post.
		$bot_tracker = stratawp_seo()->bot_analytics_tracker ?? null;
		if ( $bot_tracker instanceof SWPS_Bot_Analytics_Tracker ) {
			$bot_stats               = $bot_tracker->get_post_stats( $post_id );
			$result['bot_hits_7d']   = $bot_stats['hits_7d'];
			$result['bot_hits_30d']  = $bot_stats['hits_30d'];
			$result['bot_last_seen'] = $bot_stats['last_seen']
				? human_time_diff( strtotime( $bot_stats['last_seen'] ), time() ) . ' ago'
				: null;
		}

		// AI visibility funnel fields — one batched call each.
		$crawl_map = SWPS_Visibility_Funnel::crawl_stats_for_posts( array( $post_id ), 30 );
		$visit_map = SWPS_Visibility_Funnel::ai_visits_for_posts( array( $post_id ), 30 );

		$crawl_entry                     = $crawl_map[ $post_id ] ?? null;
		$result['funnel_last_crawl_ago'] = $crawl_entry && $crawl_entry['last_crawl_at']
			? human_time_diff( strtotime( (string) $crawl_entry['last_crawl_at'] ), time() ) . ' ago'
			: null;
		$result['funnel_last_bot']       = $crawl_entry ? (string) ( $crawl_entry['last_bot'] ?? '' ) : null;
		$result['funnel_ai_visits_30d']  = isset( $visit_map[ $post_id ] ) ? (int) $visit_map[ $post_id ]['ai_visits'] : 0;

		// GSC queries for this post.
		if ( $this->search_console->is_connected() ) {
			$url     = get_permalink( $post_id );
			$queries = $this->search_console->get_page_queries( $url, 90 );

			$result['gsc_queries'] = array_slice(
				array_map(
					function ( $row ) {
						return array(
							'query'    => $row['keys'][0] ?? '',
							'clicks'   => $row['clicks'] ?? 0,
							'position' => round( $row['position'] ?? 0, 1 ),
						);
					},
					$queries
				),
				0,
				5
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: AI referral attribution data (summary, landing posts,
	 * engagement vs organic, crawl-to-visit funnel).
	 */
	public function ajax_ai_referrals(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$days = absint( $_POST['days'] ?? 30 );
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;

		$summary       = SWPS_AI_Referrals_Report::get_summary( $days );
		$landing_posts = SWPS_AI_Referrals_Report::get_landing_posts( $days );
		$engagement    = SWPS_AI_Referrals_Report::get_engagement_vs_organic( $days );
		$funnel        = SWPS_AI_Referrals_Report::get_funnel( $days );

		// Enrich post rows with titles and links (same pattern as ajax_top_pages).
		foreach ( $landing_posts as &$row ) {
			$post             = get_post( $row['post_id'] );
			$row['title']     = $post ? $post->post_title : __( '(Unknown)', 'stratawp-seo' );
			$row['url']       = $post ? get_permalink( $post ) : '';
			$row['edit_link'] = get_edit_post_link( $row['post_id'], 'raw' ) ?? '';
		}
		unset( $row );

		foreach ( $funnel['rows'] as &$row ) {
			$post             = get_post( $row['post_id'] );
			$row['title']     = $post ? $post->post_title : __( '(Unknown)', 'stratawp-seo' );
			$row['edit_link'] = get_edit_post_link( $row['post_id'], 'raw' ) ?? '';
		}
		unset( $row );

		foreach ( $funnel['crawled_no_visits'] as &$row ) {
			$post             = get_post( $row['post_id'] );
			$row['title']     = $post ? $post->post_title : __( '(Unknown)', 'stratawp-seo' );
			$row['edit_link'] = get_edit_post_link( $row['post_id'], 'raw' ) ?? '';
		}
		unset( $row );

		wp_send_json_success(
			array(
				'summary'       => $summary,
				'landing_posts' => $landing_posts,
				'engagement'    => $engagement,
				'funnel'        => $funnel,
			)
		);
	}

	/**
	 * AJAX: Disconnect GSC.
	 */
	public function ajax_disconnect_gsc(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$this->search_console->disconnect();

		wp_send_json_success();
	}

	/**
	 * AJAX: Refresh GSC data.
	 */
	public function ajax_refresh_gsc(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$this->search_console->clear_cache();

		wp_send_json_success();
	}

	/**
	 * AJAX: Save selected GSC property.
	 */
	public function ajax_save_property(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$property = esc_url_raw( $_POST['property'] ?? '' );
		update_option( 'swps_gsc_property', $property );

		$this->search_console->clear_cache();

		wp_send_json_success();
	}

	/**
	 * Add Views column to posts list.
	 */
	public function add_views_column( array $columns ): array {
		$columns['swps_views'] = __( 'Views (30d)', 'stratawp-seo' );
		return $columns;
	}

	/**
	 * Render the Views column.
	 */
	public function render_views_column( string $column, int $post_id ): void {
		if ( 'swps_views' !== $column ) {
			return;
		}

		$stats = $this->tracker->get_post_stats( $post_id, 30 );
		echo esc_html( number_format_i18n( $stats['views'] ) );
	}

	/**
	 * Make Views column sortable.
	 */
	public function sortable_views_column( array $columns ): array {
		$columns['swps_views'] = 'swps_views';
		return $columns;
	}

	/**
	 * Sort the posts list by 30-day views when the Views column is clicked.
	 *
	 * Views live in the analytics tables (raw + daily), not postmeta, so
	 * this joins an aggregated subquery instead of using meta_value_num.
	 * Posts with no recorded views sort as zero.
	 *
	 * @param array    $clauses SQL clauses for the query.
	 * @param WP_Query $query   The current query.
	 * @return array Modified clauses.
	 */
	public function handle_views_orderby( array $clauses, WP_Query $query ): array {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $clauses;
		}

		if ( 'swps_views' !== $query->get( 'orderby' ) ) {
			return $clauses;
		}

		global $wpdb;

		$order = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';

		$clauses['join']   .= $this->tracker->get_views_orderby_join( 30 );
		$clauses['orderby'] = "COALESCE(swps_views_sort.views, 0) {$order}, {$wpdb->posts}.ID {$order}";

		return $clauses;
	}

	/**
	 * Register the analytics metabox on post edit screens.
	 */
	public function register_metabox(): void {
		add_meta_box(
			'swps_analytics_metabox',
			__( 'StrataWP Analytics', 'stratawp-seo' ),
			array( $this, 'render_metabox' ),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Render the analytics metabox.
	 */
	public function render_metabox( WP_Post $post ): void {
		$nonce = wp_create_nonce( 'swps_nonce' );
		printf(
			'<div id="swps-analytics-metabox" data-post-id="%d" data-nonce="%s">
                <p class="swps-loading">%s</p>
            </div>',
			$post->ID,
			esc_attr( $nonce ),
			esc_html__( 'Loading analytics...', 'stratawp-seo' )
		);
	}
}
