<?php
/**
 * Canonical URLs audit module.
 *
 * Checks for missing canonical tags on public posts/pages.
 * Auto-fix: Injects self-referencing canonical via wp_head.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Canonical_Module extends SWPS_Audit_Module {

	public function get_id(): string {
		return 'canonical';
	}

	public function get_label(): string {
		return __( 'Canonical URLs', 'stratawp-seo' );
	}

	public function can_auto_fix(): bool {
		return true;
	}

	public function run(): array {
		$posts  = $this->get_public_posts();
		$issues = [];

		foreach ( $posts as $post ) {
			// Check if Yoast or RankMath provides a canonical.
			$yoast_canonical     = get_post_meta( $post->ID, '_yoast_wpseo_canonical', true );
			$rankmath_canonical  = get_post_meta( $post->ID, 'rank_math_canonical_url', true );
			$has_seo_canonical   = ! empty( $yoast_canonical ) || ! empty( $rankmath_canonical );

			// Check if our auto-canonical is enabled.
			$our_canonical = (bool) get_option( 'swps_audit_auto_canonical', 1 );

			if ( ! $has_seo_canonical && ! $our_canonical ) {
				$issues[] = [
					'post_id' => $post->ID,
					'message' => sprintf(
						__( '"%s" has no canonical tag.', 'stratawp-seo' ),
						$post->post_title
					),
					'fixable' => true,
				];
			}
		}

		$total   = count( $posts );
		$failing = count( $issues );
		$score   = $total > 0 ? (int) round( ( ( $total - $failing ) / $total ) * 100 ) : 100;

		return [
			'score'   => $score,
			'status'  => $this->status_from_score( $score ),
			'issues'  => $issues,
			'summary' => 0 === $failing
				? __( 'All posts have canonical tags.', 'stratawp-seo' )
				: sprintf( __( '%d posts missing canonical tags.', 'stratawp-seo' ), $failing ),
		];
	}

	public function auto_fix( array $issues ): array {
		// Enable the auto-canonical option.
		update_option( 'swps_audit_auto_canonical', 1 );

		return [
			'fixed'    => count( $issues ),
			'messages' => [ __( 'Auto-canonical injection enabled. Canonical tags will be output via wp_head.', 'stratawp-seo' ) ],
		];
	}

	/**
	 * Output canonical tag on singular pages when enabled.
	 * Hooked to wp_head at priority 1 from the main plugin class.
	 */
	public static function output_canonical(): void {
		if ( ! is_singular() ) {
			return;
		}

		if ( ! get_option( 'swps_audit_auto_canonical', 1 ) ) {
			return;
		}

		// Skip if Yoast or RankMath is handling canonicals.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			return;
		}

		$url = wp_get_canonical_url();
		if ( $url ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		}
	}
}
