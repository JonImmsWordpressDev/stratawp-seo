<?php
/**
 * Fix-It fixer: add alt text to images missing it.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image alt text mechanical fixer.
 */
class SWPS_Fixer_Image_Alt extends SWPS_Crawl_Fixer {

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		return array( 'image_missing_alt' );
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
