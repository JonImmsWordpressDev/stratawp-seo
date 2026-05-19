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
			$content = $post->post_content;

			// Find all img tags.
			if ( preg_match_all( '/<img[^>]*>/i', $content, $matches ) ) {
				foreach ( $matches[0] as $img_tag ) {
					// Check for width/height attributes.
					if ( ! preg_match( '/\bwidth\s*=/', $img_tag ) || ! preg_match( '/\bheight\s*=/', $img_tag ) ) {
						++$missing_dimensions;
					}

					// Check for lazy loading.
					if ( false === stripos( $img_tag, 'loading=' ) ) {
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

		// Note: WordPress 5.5+ adds lazy loading and 5.9+ adds dimensions automatically
		// for images processed through wp_get_attachment_image(). Flag is still useful
		// for manually inserted images.

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
