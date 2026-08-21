<?php
/**
 * Fix-It fixer: rewrite http:// asset URLs to https:// in post content.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mixed-content mechanical fixer.
 */
class SWPS_Fixer_Mixed_Content extends SWPS_Crawl_Fixer {

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		return array( 'mixed_content' );
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
	 * Apply the fix.
	 *
	 * @param array $issue    Decoded issue row.
	 * @param array $accepted User-accepted draft.
	 * @return array|WP_Error
	 */
	public function apply( array $issue, array $accepted ): array|WP_Error {
		return new WP_Error( 'swps_not_implemented', 'Not implemented' );
	}

	/**
	 * Undo the fix.
	 *
	 * @param array $issue Decoded issue row.
	 * @return bool
	 */
	public function undo( array $issue ): bool {
		return false;
	}
}
