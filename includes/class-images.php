<?php
/**
 * Legacy alias — SWPS_Images now extends the Unsplash provider.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Images extends SWPS_Unsplash_Provider {}
