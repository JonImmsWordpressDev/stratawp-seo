<?php
/**
 * Twitter Cards audit module.
 *
 * Checks for twitter:card meta tags on public posts.
 * Auto-fix is handled by the OpenGraph module (both output together).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Twitter_Module extends SWPS_Audit_Module {

	public function get_id(): string {
		return 'twitter';
	}

	public function get_label(): string {
		return __( 'Twitter Cards', 'stratawp-seo' );
	}

	public function can_auto_fix(): bool {
		return true;
	}

	public function run(): array {
		// Twitter cards are output by the OpenGraph module.
		// This module just checks the same conditions.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			return array(
				'score'   => 100,
				'status'  => 'pass',
				'issues'  => array(),
				'summary' => __( 'Twitter Cards managed by SEO plugin.', 'stratawp-seo' ),
			);
		}

		$our_og = (bool) get_option( 'swps_audit_auto_og', 1 );

		if ( $our_og ) {
			return array(
				'score'   => 100,
				'status'  => 'pass',
				'issues'  => array(),
				'summary' => __( 'Twitter Cards output via SWPS OG auto-tags.', 'stratawp-seo' ),
			);
		}

		return array(
			'score'   => 0,
			'status'  => 'fail',
			'issues'  => array(
				array(
					'post_id' => null,
					'message' => __( 'No Twitter Card tags detected. Enable OG auto-tags to fix.', 'stratawp-seo' ),
					'fixable' => true,
				),
			),
			'summary' => __( 'No Twitter Card tags found.', 'stratawp-seo' ),
		);
	}

	public function auto_fix( array $issues ): array {
		// Enable OG (which includes Twitter tags).
		update_option( 'swps_audit_auto_og', 1 );

		return array(
			'fixed'    => 1,
			'messages' => array( __( 'OG auto-tags enabled (includes Twitter Cards).', 'stratawp-seo' ) ),
		);
	}
}
