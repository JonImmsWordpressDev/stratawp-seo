<?php
/**
 * Sitemap Admin page and AJAX handlers.
 *
 * Provides the dashboard for managing XML sitemaps with exclusion toggles,
 * search engine pinging, and IndexNow configuration.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Sitemap_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_swps_get_sitemaps', [ $this, 'ajax_get_sitemaps' ] );
		add_action( 'wp_ajax_swps_toggle_sitemap', [ $this, 'ajax_toggle_sitemap' ] );
		add_action( 'wp_ajax_swps_ping_search_engines', [ $this, 'ajax_ping_search_engines' ] );
	}

	/**
	 * Register the Sitemaps submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Sitemaps', 'stratawp-seo' ),
			__( 'Sitemaps', 'stratawp-seo' ),
			'manage_options',
			'swps-sitemaps',
			[ $this, 'render' ]
		);
	}

	/**
	 * Register sitemap settings.
	 */
	public function register_settings(): void {
		register_setting(
			'swps_sitemap_settings',
			'swps_sitemap_exclude_images',
			[
				'sanitize_callback' => function ( $value ) {
					return $value ? 1 : 0;
				},
			]
		);

		register_setting(
			'swps_sitemap_settings',
			'swps_sitemap_exclude_author',
			[
				'sanitize_callback' => function ( $value ) {
					return $value ? 1 : 0;
				},
			]
		);

		register_setting(
			'swps_sitemap_settings',
			'swps_auto_redirect_slug_change',
			[
				'sanitize_callback' => function ( $value ) {
					return $value ? 1 : 0;
				},
			]
		);

		register_setting(
			'swps_sitemap_settings',
			'swps_sitemap_urls_per_page',
			[
				'sanitize_callback' => function ( $value ) {
					$value = absint( $value );
					if ( $value < 100 ) {
						$value = 100;
					}
					if ( $value > 50000 ) {
						$value = 50000;
					}
					return $value;
				},
			]
		);
	}

	/**
	 * Render the Sitemaps admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
		}

		include SWPS_PLUGIN_DIR . 'templates/sitemaps-page.php';
	}

	/**
	 * AJAX: Get all sitemaps with metadata.
	 */
	public function ajax_get_sitemaps(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$sitemaps = [];

		// Post type sitemaps.
		$post_types = get_post_types( [ 'public' => true ] );
		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt ) {
				continue;
			}

			$count      = $this->get_post_type_count( $pt );
			$lastmod    = $this->get_post_type_lastmod( $pt );
			$excluded   = (bool) get_option( "swps_sitemap_exclude_{$pt}", 0 );
			$post_type_obj = get_post_type_object( $pt );
			$label      = $post_type_obj ? $post_type_obj->label : $pt;

			$sitemaps[] = [
				'key'       => $pt,
				'name'      => $label,
				'type'      => 'post_type',
				'url'       => home_url( "/{$pt}-sitemap.xml" ),
				'count'     => $count,
				'lastmod'   => $lastmod,
				'excluded'  => $excluded,
			];
		}

		// Taxonomy sitemaps.
		$taxonomies = get_taxonomies( [ 'public' => true ] );
		foreach ( $taxonomies as $tax ) {
			if ( 'post_format' === $tax ) {
				continue;
			}

			$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => true, 'number' => 1 ] );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			$excluded   = (bool) get_option( "swps_sitemap_exclude_{$tax}", 0 );
			$tax_obj    = get_taxonomy( $tax );
			$label      = $tax_obj ? $tax_obj->label : $tax;
			$term_count = wp_count_terms( [ 'taxonomy' => $tax, 'hide_empty' => true ] );

			$sitemaps[] = [
				'key'       => $tax,
				'name'      => $label,
				'type'      => 'taxonomy',
				'url'       => home_url( "/{$tax}-sitemap.xml" ),
				'count'     => is_wp_error( $term_count ) ? 0 : (int) $term_count,
				'lastmod'   => gmdate( 'Y-m-d\TH:i:s+00:00' ),
				'excluded'  => $excluded,
			];
		}

		// Author sitemap.
		$author_excluded = (bool) get_option( 'swps_sitemap_exclude_author', 0 );
		$author_count    = count( get_users( [ 'has_published_posts' => true, 'fields' => 'ID' ] ) );

		$sitemaps[] = [
			'key'       => 'author',
			'name'      => __( 'Authors', 'stratawp-seo' ),
			'type'      => 'author',
			'url'       => home_url( '/author-sitemap.xml' ),
			'count'     => $author_count,
			'lastmod'   => gmdate( 'Y-m-d\TH:i:s+00:00' ),
			'excluded'  => $author_excluded,
		];

		$index_url   = home_url( '/sitemap_index.xml' );
		$indexnow_key = get_option( 'swps_indexnow_key', '' );

		wp_send_json_success( [
			'sitemaps'     => $sitemaps,
			'index_url'    => $index_url,
			'indexnow_key' => $indexnow_key,
		] );
	}

	/**
	 * AJAX: Toggle sitemap exclusion status.
	 */
	public function ajax_toggle_sitemap(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$key = sanitize_text_field( $_POST['key'] ?? '' );
		if ( empty( $key ) ) {
			wp_send_json_error( [ 'message' => 'Missing sitemap key.' ] );
		}

		$option_name = "swps_sitemap_exclude_{$key}";
		$current      = (bool) get_option( $option_name, 0 );
		$new_value    = $current ? 0 : 1;

		update_option( $option_name, $new_value );

		wp_send_json_success( [
			'key'      => $key,
			'excluded' => (bool) $new_value,
		] );
	}

	/**
	 * AJAX: Ping search engines and IndexNow.
	 */
	public function ajax_ping_search_engines(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$sitemap_manager = StrataWP_SEO::instance()->sitemap_manager;
		if ( ! $sitemap_manager ) {
			wp_send_json_error( [ 'message' => 'Sitemap manager not available.' ] );
		}

		$sitemap_manager->ping_search_engines();

		wp_send_json_success( [
			'message' => __( 'Pinging search engines...', 'stratawp-seo' ),
		] );
	}

	/**
	 * Get post count for a post type.
	 */
	private function get_post_type_count( string $post_type ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$post_type
		) );
	}

	/**
	 * Get the most recent lastmod for a post type.
	 */
	private function get_post_type_lastmod( string $post_type ): string {
		global $wpdb;
		$date = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$post_type
		) );
		return $date ? gmdate( 'Y-m-d\TH:i:s+00:00', strtotime( $date ) ) : gmdate( 'Y-m-d\TH:i:s+00:00' );
	}
}
