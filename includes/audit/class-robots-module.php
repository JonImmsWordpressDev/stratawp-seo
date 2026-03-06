<?php
/**
 * Robots.txt audit module.
 *
 * Checks that robots.txt exists, doesn't block important paths, includes sitemap.
 * Auto-fix: Creates default robots.txt via do_robots filter.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Robots_Module extends SWPS_Audit_Module {

	public function get_id(): string {
		return 'robots';
	}

	public function get_label(): string {
		return __( 'Robots.txt', 'stratawp-seo' );
	}

	public function can_auto_fix(): bool {
		return true;
	}

	public function run(): array {
		$issues = [];

		// Check for a physical robots.txt.
		$has_physical = file_exists( ABSPATH . 'robots.txt' );

		if ( $has_physical ) {
			$content = file_get_contents( ABSPATH . 'robots.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $content ) {
				$content = '';
			}

			// Check for blocking /wp-content/.
			if ( false !== stripos( $content, 'disallow: /wp-content/' ) ) {
				$issues[] = [
					'post_id' => null,
					'message' => __( 'robots.txt blocks /wp-content/ which may prevent image indexing.', 'stratawp-seo' ),
					'fixable' => false, // Don't auto-modify a physical file.
				];
			}

			// Check for sitemap line.
			if ( false === stripos( $content, 'sitemap:' ) ) {
				$issues[] = [
					'post_id' => null,
					'message' => __( 'robots.txt does not include a Sitemap directive.', 'stratawp-seo' ),
					'fixable' => false,
				];
			}
		}
		// WordPress virtual robots.txt is always present if no physical file exists.
		// We enhance it via filter if needed.

		$failing = count( $issues );
		$score   = 0 === $failing ? 100 : ( 1 === $failing ? 60 : 30 );

		return [
			'score'   => $score,
			'status'  => $this->status_from_score( $score ),
			'issues'  => $issues,
			'summary' => 0 === $failing
				? __( 'robots.txt is properly configured.', 'stratawp-seo' )
				: sprintf( __( '%d robots.txt issue(s) found.', 'stratawp-seo' ), $failing ),
		];
	}

	public function auto_fix( array $issues ): array {
		// We can't modify a physical file, but we can ensure our filter is active.
		return [
			'fixed'    => 0,
			'messages' => [ __( 'SWPS enhances the virtual robots.txt with sitemap directives automatically.', 'stratawp-seo' ) ],
		];
	}

	/**
	 * Enhance the virtual robots.txt output.
	 * Hooked to robots_txt filter.
	 */
	public static function filter_robots_txt( string $output, bool $public ): string {
		if ( ! $public ) {
			return $output;
		}

		// Add sitemap URL if not already present.
		if ( false === stripos( $output, 'sitemap:' ) ) {
			// Prefer our sitemap if active, otherwise WP core.
			if ( get_option( 'swps_audit_auto_sitemap', 1 ) ) {
				$sitemap_url = home_url( '/swps-sitemap.xml' );
			} else {
				$sitemap_url = home_url( '/wp-sitemap.xml' );
			}

			$output .= "\nSitemap: " . esc_url( $sitemap_url ) . "\n";
		}

		return $output;
	}
}
