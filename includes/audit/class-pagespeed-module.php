<?php
/**
 * Page Speed Hints audit module.
 *
 * Checks for common performance issues: missing lazy loading,
 * missing image dimensions, render-blocking resources.
 * Advisory only -- no auto-fix.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_PageSpeed_Module extends SWPS_Audit_Module {

	public function get_id(): string {
		return 'pagespeed';
	}

	public function get_label(): string {
		return __( 'Page Speed Hints', 'stratawp-seo' );
	}

	public function can_auto_fix(): bool {
		return false;
	}

	public function run(): array {
		$issues = array();

		// Check recent posts for image issues in content.
		$posts = $this->get_public_posts( 50 );

		$missing_dimensions = 0;
		$missing_lazy       = 0;

		foreach ( $posts as $post ) {
			// Scan what visitors get: WordPress adds width/height and
			// loading="lazy" to attachment images at render time, so raw
			// post_content under-reports both.
			$content = wp_filter_content_tags( $post->post_content, 'the_content' );

			// Find all img tags.
			if ( preg_match_all( '/<img[^>]*>/i', $content, $matches ) ) {
				foreach ( $matches[0] as $img_tag ) {
					// Check for width/height attributes.
					if ( ! preg_match( '/\bwidth\s*=/', $img_tag ) || ! preg_match( '/\bheight\s*=/', $img_tag ) ) {
						++$missing_dimensions;
					}

					// Check for lazy loading. A fetchpriority="high" image is the
					// LCP candidate and is meant to load eagerly.
					if ( false === stripos( $img_tag, 'loading=' ) && false === stripos( $img_tag, 'fetchpriority' ) ) {
						++$missing_lazy;
					}
				}
			}
		}

		if ( $missing_dimensions > 0 ) {
			$issues[] = array(
				'post_id' => null,
				'message' => sprintf(
					__( '%d images across recent posts are missing width/height attributes (causes layout shift).', 'stratawp-seo' ),
					$missing_dimensions
				),
				'fixable' => false,
			);
		}

		if ( $missing_lazy > 0 ) {
			$issues[] = array(
				'post_id' => null,
				'message' => sprintf(
					__( '%d images are missing loading="lazy" attribute.', 'stratawp-seo' ),
					$missing_lazy
				),
				'fixable' => false,
			);
		}

		// Counts above are post-render, so what remains are images WordPress
		// cannot size: manually inserted ones with no attachment ID.

		$score = 100;
		if ( $missing_dimensions > 10 ) {
			$score -= 30;
		} elseif ( $missing_dimensions > 0 ) {
			$score -= 15;
		}

		if ( $missing_lazy > 10 ) {
			$score -= 20;
		} elseif ( $missing_lazy > 0 ) {
			$score -= 10;
		}

		$score = max( 0, $score );

		return array(
			'score'   => $score,
			'status'  => $this->status_from_score( $score ),
			'issues'  => $issues,
			'summary' => empty( $issues )
				? __( 'No page speed issues detected in recent content.', 'stratawp-seo' )
				: sprintf( __( '%d performance issue(s) found.', 'stratawp-seo' ), count( $issues ) ),
		);
	}
}
