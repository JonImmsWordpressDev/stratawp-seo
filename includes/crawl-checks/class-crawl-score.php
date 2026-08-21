<?php
/**
 * Health score calculation for a finished crawl run.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a run's severity counts into a 0-100 health score.
 */
class SWPS_Crawl_Score {

	/**
	 * Calculate the health score for a run.
	 *
	 * Errors weigh 5x, warnings 1x, notices are free. The denominator assumes
	 * the worst case of one error per page, keeping the scale comparable to
	 * Semrush's site health score.
	 *
	 * @param array $severity_counts Severity counts keyed by error/warning/notice.
	 * @param int   $pages           Total pages in the run.
	 * @return int Score clamped to [0, 100].
	 */
	public static function calculate( array $severity_counts, int $pages ): int {
		$weighted = 5 * (int) ( $severity_counts['error'] ?? 0 ) + (int) ( $severity_counts['warning'] ?? 0 );
		$max      = 5 * max( 1, $pages );
		return (int) round( max( 0.0, 100 * ( 1 - $weighted / $max ) ) );
	}

	/**
	 * Score the run would have if the fixed issues vanish. Display-only —
	 * the real score always comes from the next crawl.
	 *
	 * @param array $severity_counts Current counts keyed by severity.
	 * @param array $fixed_counts    Fixed-issue counts keyed by severity.
	 * @param int   $pages           Total pages in the run.
	 * @return int Projected score clamped to [0, 100].
	 */
	public static function project( array $severity_counts, array $fixed_counts, int $pages ): int {
		$remaining = array();
		foreach ( array( 'error', 'warning', 'notice' ) as $sev ) {
			$remaining[ $sev ] = max(
				0,
				(int) ( $severity_counts[ $sev ] ?? 0 ) - (int) ( $fixed_counts[ $sev ] ?? 0 )
			);
		}
		return self::calculate( $remaining, $pages );
	}
}
