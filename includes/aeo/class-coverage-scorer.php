<?php
/**
 * Coverage Scorer — rates topic coverage breadth and depth.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Coverage_Scorer {

	/** @var object|null AI provider — required by Task 10 implementation. */
	private $provider;

	public function __construct( $provider = null ) {
		$this->provider = $provider;
	}

	// Full implementation: Task 10.
}
