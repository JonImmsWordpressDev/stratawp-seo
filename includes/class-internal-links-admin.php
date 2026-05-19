<?php
/**
 * Internal Links admin overview page.
 *
 * Renders the site-wide link health dashboard, opportunities table,
 * orphan pages view, and handles rebuild/bulk AJAX.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Internal_Links_Admin {

	private SWPS_Internal_Links $engine;

	public function __construct( SWPS_Internal_Links $engine ) {
		$this->engine = $engine;

		if ( ! get_option( 'swps_internal_links_enabled', 1 ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'wp_ajax_swps_link_rebuild', array( $this, 'ajax_rebuild' ) );
		add_action( 'wp_ajax_swps_link_bulk_dismiss', array( $this, 'ajax_bulk_dismiss' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the Internal Links submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Internal Links', 'stratawp-seo' ),
			__( 'Internal Links', 'stratawp-seo' ),
			'manage_options',
			'swps-internal-links',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
		}

		$stats = $this->get_stats();
		include SWPS_PLUGIN_DIR . 'templates/internal-links-page.php';
	}

	/**
	 * Get link health statistics.
	 *
	 * @return array Stats array with counts.
	 */
	public function get_stats(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'swps_link_graph';

		$total_links = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status IN ('existing', 'inserted')"
		);

		$pending_suggestions = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'suggested'"
		);

		$post_types  = (array) get_option( 'swps_internal_links_post_types', array( 'post', 'page' ) );
		$total_posts = 0;
		foreach ( $post_types as $pt ) {
			$counts       = wp_count_posts( $pt );
			$total_posts += (int) ( $counts->publish ?? 0 );
		}

		$avg_links = $total_posts > 0 ? round( $total_links / $total_posts, 1 ) : 0;

		// Orphan pages: published posts with zero inbound links.
		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
                 WHERE p.post_type IN ({$type_placeholders})
                 AND p.post_status = 'publish'
                 AND p.ID NOT IN (
                     SELECT DISTINCT target_post_id FROM {$table}
                     WHERE status IN ('existing', 'inserted')
                 )",
				$post_types
			)
		);

		// Most linked posts (top 5 by inbound).
		$most_linked = $wpdb->get_results(
			"SELECT target_post_id, COUNT(*) as link_count
             FROM {$table}
             WHERE status IN ('existing', 'inserted')
             GROUP BY target_post_id
             ORDER BY link_count DESC
             LIMIT 5",
			ARRAY_A
		) ?: array();

		// Opportunities (paginated).
		$page   = max( 1, absint( $_GET['link_page'] ?? 1 ) );
		$per    = 20;
		$offset = ( $page - 1 ) * $per;

		$opportunities = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'suggested' ORDER BY relevance_score DESC LIMIT %d OFFSET %d",
				$per,
				$offset
			),
			ARRAY_A
		) ?: array();

		$total_pages = (int) ceil( $pending_suggestions / $per );

		// Orphan pages list.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_date FROM {$wpdb->posts} p
                 WHERE p.post_type IN ({$type_placeholders})
                 AND p.post_status = 'publish'
                 AND p.ID NOT IN (
                     SELECT DISTINCT target_post_id FROM {$table}
                     WHERE status IN ('existing', 'inserted')
                 )
                 ORDER BY p.post_date DESC
                 LIMIT 50",
				$post_types
			),
			ARRAY_A
		) ?: array();

		return array(
			'total_links'         => $total_links,
			'avg_links'           => $avg_links,
			'orphan_count'        => $orphan_count,
			'pending_suggestions' => $pending_suggestions,
			'most_linked'         => $most_linked,
			'opportunities'       => $opportunities,
			'orphan_posts'        => $orphan_posts,
			'current_page'        => $page,
			'total_pages'         => $total_pages,
		);
	}

	/**
	 * AJAX: Rebuild link index in batches.
	 */
	public function ajax_rebuild(): void {
		check_ajax_referer( 'swps_internal_links', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$offset = absint( $_POST['offset'] ?? 0 );
		$result = $this->engine->rebuild_batch( $offset );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Bulk dismiss suggestions.
	 */
	public function ajax_bulk_dismiss(): void {
		check_ajax_referer( 'swps_internal_links', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$ids = array_map( 'absint', $_POST['ids'] ?? array() );
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => 'No IDs provided.' ) );
		}

		global $wpdb;
		$table        = $wpdb->prefix . 'swps_link_graph';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'dismissed', updated_at = %s WHERE id IN ({$placeholders})",
				array_merge( array( current_time( 'mysql' ) ), $ids )
			)
		);

		wp_send_json_success();
	}

	/**
	 * Enqueue admin page JS.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'stratawp-seo_page_swps-internal-links' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'swps-internal-links-admin',
			SWPS_PLUGIN_URL . 'admin/js/internal-links-admin.js',
			array( 'jquery' ),
			SWPS_VERSION,
			true
		);

		wp_localize_script(
			'swps-internal-links-admin',
			'swpsLinksAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'swps_internal_links' ),
				'i18n'    => array(
					'rebuilding' => __( 'Rebuilding index...', 'stratawp-seo' ),
					'progress'   => __( 'Processed %1$d of %2$d posts...', 'stratawp-seo' ),
					'complete'   => __( 'Rebuild complete!', 'stratawp-seo' ),
					'error'      => __( 'Error during rebuild.', 'stratawp-seo' ),
				),
			)
		);
	}
}
