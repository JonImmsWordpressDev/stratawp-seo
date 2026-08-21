<?php
/**
 * Fix-It fixer: remove noindex posts from sitemap.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sitemap exclusion mechanical fixer.
 */
class SWPS_Fixer_Sitemap_Exclude extends SWPS_Crawl_Fixer {

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		return array( 'noindex_in_sitemap' );
	}

	/**
	 * Fixer kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'mechanical';
	}

	/**
	 * Prefer the post id the check stored in detail; fall back to the
	 * resolved object target.
	 *
	 * @param array $issue Decoded issue row.
	 */
	private function post_id_for( array $issue ): int {
		$detail_id = (int) ( $issue['detail']['post_id'] ?? 0 );
		if ( $detail_id > 0 ) {
			return $detail_id;
		}
		return 'post' === ( $issue['object_type'] ?? '' ) ? (int) $issue['object_id'] : 0;
	}

	/**
	 * Fixable when a post id is recoverable from the row.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function can_fix( array $issue ): bool {
		return $this->post_id_for( $issue ) > 0;
	}

	/**
	 * Exclude the noindexed post from the sitemap.
	 *
	 * @param array $issue    Decoded issue row.
	 * @param array $accepted Unused.
	 * @return array|WP_Error {changed: bool, message: string}
	 */
	public function apply( array $issue, array $accepted ): array|WP_Error {
		$post_id = $this->post_id_for( $issue );
		if ( 0 === $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'swps_fixit_gone', __( 'The post no longer exists.', 'stratawp-seo' ) );
		}

		update_post_meta( $post_id, '_swps_sitemap_exclude', 1 );

		return array(
			'changed' => true,
			'message' => __( 'Excluded from the sitemap.', 'stratawp-seo' ),
		);
	}

	/**
	 * Re-include the post in the sitemap.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function undo( array $issue ): bool {
		$post_id = $this->post_id_for( $issue );
		if ( 0 === $post_id ) {
			return false;
		}
		delete_post_meta( $post_id, '_swps_sitemap_exclude' );
		return true;
	}
}
