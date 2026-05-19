<?php
/**
 * Meta Robots audit module.
 *
 * Checks for conflicting noindex/nofollow on published posts.
 * No auto-fix — flags only (too dangerous to auto-change indexing).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Meta_Robots_Module extends SWPS_Audit_Module {

	public function get_id(): string {
		return 'meta_robots';
	}

	public function get_label(): string {
		return __( 'Meta Robots', 'stratawp-seo' );
	}

	public function can_auto_fix(): bool {
		return false;
	}

	public function run(): array {
		$posts  = $this->get_public_posts();
		$issues = array();

		foreach ( $posts as $post ) {
			$problems = array();

			// Yoast noindex check.
			$yoast_noindex = get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
			if ( '1' === $yoast_noindex ) {
				$problems[] = 'Yoast noindex';
			}

			// RankMath robots check.
			$rm_robots = get_post_meta( $post->ID, 'rank_math_robots', true );
			if ( is_array( $rm_robots ) ) {
				if ( in_array( 'noindex', $rm_robots, true ) ) {
					$problems[] = 'RankMath noindex';
				}
				if ( in_array( 'nofollow', $rm_robots, true ) ) {
					$problems[] = 'RankMath nofollow';
				}
			}

			// WordPress core noindex (WP 5.7+).
			$wp_noindex = get_post_meta( $post->ID, '_wp_page_template_noindex', true );
			if ( $wp_noindex ) {
				$problems[] = 'WP noindex';
			}

			if ( ! empty( $problems ) ) {
				$issues[] = array(
					'post_id' => $post->ID,
					'message' => sprintf(
						__( '"%1$s" has restrictive meta robots: %2$s.', 'stratawp-seo' ),
						$post->post_title,
						implode( ', ', $problems )
					),
					'fixable' => false,
				);
			}
		}

		$total   = count( $posts );
		$failing = count( $issues );
		$score   = $total > 0 ? (int) round( ( ( $total - $failing ) / $total ) * 100 ) : 100;

		return array(
			'score'   => $score,
			'status'  => $this->status_from_score( $score ),
			'issues'  => $issues,
			'summary' => 0 === $failing
				? __( 'No conflicting meta robots directives found.', 'stratawp-seo' )
				: sprintf( __( '%d published posts have restrictive robots settings.', 'stratawp-seo' ), $failing ),
		);
	}
}
