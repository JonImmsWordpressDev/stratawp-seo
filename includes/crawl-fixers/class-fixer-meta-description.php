<?php
/**
 * Fix-It fixer: rewrite meta descriptions to fix description issues.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta description draft fixer.
 */
class SWPS_Fixer_Meta_Description extends SWPS_Crawl_Fixer {

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		return array(
			'missing_meta_description',
			'desc_too_long',
			'duplicate_meta_description',
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
