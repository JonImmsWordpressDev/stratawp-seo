<?php
/**
 * Legacy alias — SWPS_API now extends the Anthropic provider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_API extends SWPS_Anthropic_Provider {}
