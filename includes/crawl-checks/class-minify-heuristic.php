<?php
/**
 * Heuristic: does a JS/CSS sample look minified?
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Estimates whether a JS/CSS asset sample looks minified based on average line length.
 */
class SWPS_Minify_Heuristic {

	/**
	 * Bytes examined from the start of the sample.
	 */
	private const SAMPLE_BYTES = 20480;

	/**
	 * Average line length, in characters, above which a sample looks minified.
	 */
	private const AVG_LINE_MIN = 200;

	/**
	 * Determine whether a sample of JS/CSS source looks minified.
	 *
	 * Looks at the first 20 KB and compares the average line length against
	 * a threshold. An empty sample is treated as minified so a failed or
	 * empty fetch never produces a false "not minified" verdict.
	 *
	 * @param string $sample Asset source sample.
	 * @return bool True when the sample looks minified.
	 */
	public static function looks_minified( string $sample ): bool {
		$sample = substr( $sample, 0, self::SAMPLE_BYTES );
		$len    = strlen( $sample );
		if ( 0 === $len ) {
			return true;
		}
		$lines = substr_count( $sample, "\n" ) + 1;
		return ( $len / $lines ) > self::AVG_LINE_MIN;
	}
}
