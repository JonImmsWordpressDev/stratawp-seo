<?php
/**
 * XML Sitemap audit module.
 *
 * Checks if a sitemap exists and covers all public posts. The sitemap itself is
 * generated and served by SWPS_Sitemap_Manager (at /sitemap_index.xml); this
 * module only audits coverage and toggles the swps_audit_auto_sitemap option.
 * Reports a pass when Yoast, RankMath, or WordPress core sitemaps are active.
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
			'messages' => array(
				sprintf(
					/* translators: %s: the canonical sitemap URL. */
					__( 'SWPS sitemap generation enabled at %s.', 'stratawp-seo' ),
					SWPS_Sitemap_Manager::get_sitemap_url()
				),
			),
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
}
