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
 * Meta description draft fixer. Inherits generation from SWPS_Fixer_Meta_Title;
 * overrides constants and sanitizer for description-specific handling.
 */
class SWPS_Fixer_Meta_Description extends SWPS_Fixer_Meta_Title {

	protected const FIELD        = 'meta_description';
	protected const META_KEY     = '_swps_meta_description';
	protected const RESPONSE_KEY = 'description';
	protected const MIN_LEN      = 120;
	protected const MAX_LEN      = 160;

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		return array( 'missing_meta_description', 'desc_too_long', 'duplicate_meta_description' );
	}

	/**
	 * Descriptions are textarea-sanitized (multi-sentence).
	 *
	 * @param string $value Raw value.
	 */
	protected function sanitize( string $value ): string {
		return sanitize_textarea_field( $value );
	}
}
