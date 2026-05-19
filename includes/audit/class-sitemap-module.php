<?php
/**
 * XML Sitemap audit module.
 *
 * Checks if a sitemap exists and covers all public posts.
 * Auto-fix: Generates /swps-sitemap.xml via rewrite rule.
 * Skips generation if Yoast, RankMath, or WordPress core sitemaps are active.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Sitemap_Module extends SWPS_Audit_Module {

	public function get_id(): string {
		return 'sitemap';
	}

	public function get_label(): string {
		return __( 'XML Sitemap', 'stratawp-seo' );
	}

	public function can_auto_fix(): bool {
		return true;
	}

	public function run(): array {
		$issues = array();

		$has_other_sitemap = $this->detect_other_sitemap();

		if ( $has_other_sitemap ) {
			return array(
				'score'   => 100,
				'status'  => 'pass',
				'issues'  => array(),
				'summary' => __( 'Sitemap provided by another plugin.', 'stratawp-seo' ),
			);
		}

		$our_sitemap_enabled = (bool) get_option( 'swps_audit_auto_sitemap', 1 );

		if ( ! $our_sitemap_enabled ) {
			$issues[] = array(
				'post_id' => null,
				'message' => __( 'No sitemap detected and SWPS sitemap generation is disabled.', 'stratawp-seo' ),
				'fixable' => true,
			);

			return array(
				'score'   => 0,
				'status'  => 'fail',
				'issues'  => $issues,
				'summary' => __( 'No sitemap found.', 'stratawp-seo' ),
			);
		}

		// Sitemap is enabled — check that posts are covered.
		$posts         = $this->get_public_posts();
		$noindex_count = 0;

		foreach ( $posts as $post ) {
			$noindex   = get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
			$rm_robots = get_post_meta( $post->ID, 'rank_math_robots', true );

			if ( '1' === $noindex || ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) ) {
				++$noindex_count;
			}
		}

		$indexed = count( $posts ) - $noindex_count;

		return array(
			'score'   => 100,
			'status'  => 'pass',
			'issues'  => array(),
			'summary' => sprintf( __( 'SWPS sitemap active with %d URLs.', 'stratawp-seo' ), $indexed ),
		);
	}

	public function auto_fix( array $issues ): array {
		update_option( 'swps_audit_auto_sitemap', 1 );
		flush_rewrite_rules();

		return array(
			'fixed'    => 1,
			'messages' => array( __( 'SWPS sitemap generation enabled at /swps-sitemap.xml.', 'stratawp-seo' ) ),
		);
	}

	/**
	 * Detect if another sitemap provider is active.
	 */
	private function detect_other_sitemap(): bool {
		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) && class_exists( 'WPSEO_Sitemaps' ) ) {
			return true;
		}

		// RankMath.
		if ( class_exists( 'RankMath' ) ) {
			return true;
		}

		// WordPress core sitemaps (WP 5.5+).
		if ( function_exists( 'wp_sitemaps_get_server' ) ) {
			$server = wp_sitemaps_get_server();
			if ( $server && method_exists( $server, 'sitemaps_enabled' ) && $server->sitemaps_enabled() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register sitemap rewrite rules.
	 * Called from main plugin on init.
	 */
	public static function register_rewrite_rules(): void {
		if ( ! get_option( 'swps_audit_auto_sitemap', 1 ) ) {
			return;
		}

		add_rewrite_rule( 'swps-sitemap\.xml$', 'index.php?swps_sitemap=1', 'top' );
		add_filter(
			'query_vars',
			function ( array $vars ): array {
				$vars[] = 'swps_sitemap';
				return $vars;
			}
		);
	}

	/**
	 * Handle the sitemap request.
	 * Hooked to template_redirect from the main plugin class.
	 */
	public static function serve_sitemap(): void {
		if ( ! get_query_var( 'swps_sitemap' ) ) {
			return;
		}

		// Skip if another provider is active.
		$module = new self();
		if ( $module->detect_other_sitemap() ) {
			return;
		}

		if ( ! get_option( 'swps_audit_auto_sitemap', 1 ) ) {
			return;
		}

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

		// Homepage.
		printf(
			"<url>\n  <loc>%s</loc>\n  <lastmod>%s</lastmod>\n  <priority>1.0</priority>\n</url>\n",
			esc_url( home_url( '/' ) ),
			gmdate( 'Y-m-d\TH:i:s+00:00' )
		);

		$posts = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 2000,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$now = time();

		foreach ( $posts as $post ) {
			// Skip noindex posts.
			$noindex   = get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
			$rm_robots = get_post_meta( $post->ID, 'rank_math_robots', true );
			if ( '1' === $noindex || ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) ) {
				continue;
			}

			$modified = get_post_modified_time( 'U', true, $post );
			$age_days = ( $now - $modified ) / DAY_IN_SECONDS;
			$priority = $age_days < 30 ? '0.8' : '0.6';

			echo "<url>\n";
			printf( "  <loc>%s</loc>\n", esc_url( get_permalink( $post ) ) );
			printf( "  <lastmod>%s</lastmod>\n", gmdate( 'Y-m-d\TH:i:s+00:00', $modified ) );
			printf( "  <priority>%s</priority>\n", $priority );

			// Image entry for featured image.
			$thumb_id = get_post_thumbnail_id( $post->ID );
			if ( $thumb_id ) {
				$img_url = wp_get_attachment_url( $thumb_id );
				if ( $img_url ) {
					echo "  <image:image>\n";
					printf( "    <image:loc>%s</image:loc>\n", esc_url( $img_url ) );
					$alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
					if ( $alt ) {
						printf( "    <image:title>%s</image:title>\n", esc_xml( $alt ) );
					}
					echo "  </image:image>\n";
				}
			}

			echo "</url>\n";
		}

		echo '</urlset>';
		exit;
	}

	/**
	 * Ping search engines after a post is published.
	 */
	public static function ping_search_engines(): void {
		if ( ! get_option( 'swps_audit_auto_sitemap', 1 ) ) {
			return;
		}

		$sitemap_url = home_url( '/swps-sitemap.xml' );

		wp_remote_get(
			'https://www.google.com/ping?sitemap=' . urlencode( $sitemap_url ),
			array(
				'timeout'   => 5,
				'blocking'  => false,
				'sslverify' => false,
			)
		);

		wp_remote_get(
			'https://www.bing.com/ping?sitemap=' . urlencode( $sitemap_url ),
			array(
				'timeout'   => 5,
				'blocking'  => false,
				'sslverify' => false,
			)
		);
	}
}
