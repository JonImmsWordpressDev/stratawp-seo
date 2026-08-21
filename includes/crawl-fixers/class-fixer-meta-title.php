<?php
/**
 * Fix-It fixer: rewrite post titles and meta tags to fix title issues.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta title draft fixer.
 */
class SWPS_Fixer_Meta_Title extends SWPS_Crawl_Fixer {

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		return array(
			'missing_title',
			'title_too_long',
			'title_too_short',
			'duplicate_title',
		);
	}

	/**
	 * Fixer kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'draft';
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
