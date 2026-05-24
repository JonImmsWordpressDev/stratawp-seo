<?php
/**
 * AEO Editor Panel — Gutenberg sidebar control for AEO optimization UI.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Editor_Panel {

	private SWPS_AEO_Scorer $scorer;

	public function __construct( SWPS_AEO_Scorer $scorer ) {
		$this->scorer = $scorer;
	}

	// Full implementation: Task 17.
}
