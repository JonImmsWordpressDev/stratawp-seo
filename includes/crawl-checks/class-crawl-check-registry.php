<?php
/**
 * Registry + runner for crawl checks.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Crawl_Check_Registry {

	public const OPT_EXCLUDED = 'swps_crawl_excluded_checks';

	/**
	 * Ordered check class names. Populated by later tasks; order matters only
	 * for display. Empty entries are skipped defensively.
	 */
	private const CHECKS = array(
		// Task 4: migrated checks.
		SWPS_Check_Redirect_Loop::class,
		SWPS_Check_Broken_Link::class,
		SWPS_Check_Redirect_Chain::class,
		SWPS_Check_Canonical_Mismatch::class,
		SWPS_Check_Missing_H1::class,
		SWPS_Check_Duplicate_H1::class,
		SWPS_Check_Mixed_Content::class,
		SWPS_Check_Noindex_In_Sitemap::class,
		// Tasks 5-6 add per-page checks, Task 8 adds aggregate checks.
	);

	/**
	 * Instantiate all registered checks, filtering by exclusion list.
	 *
	 * @param array|null $excluded Check IDs to exclude. Null reads from option.
	 * @return SWPS_Crawl_Check[] Instantiated check objects.
	 */
	public static function all( ?array $excluded = null ): array {
		if ( null === $excluded ) {
			$excluded = (array) get_option( self::OPT_EXCLUDED, array() );
		}
		$out = array();
		foreach ( self::CHECKS as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$check = new $class();
			if ( ! in_array( $check->id(), $excluded, true ) ) {
				$out[] = $check;
			}
		}
		return $out;
	}

	/**
	 * Run per-page checks with short-circuit rules:
	 * - broken responses (>=400 or 0) skip all checks (broken_link check runs at fetch time);
	 * - challenge pages run only challenge_page_detected check.
	 *
	 * @param array             $page   Merged fact array.
	 * @param SWPS_Crawl_Check[] $checks Checks to run.
	 * @return array[] Issue rows.
	 */
	public static function run_page_checks( array $page, array $checks ): array {
		$status = (int) ( $page['status_code'] ?? 0 );
		if ( $status >= 400 || 0 === $status ) {
			return array();
		}

		if ( ! empty( $page['is_challenge'] ) ) {
			foreach ( $checks as $check ) {
				if ( 'challenge_page_detected' === $check->id() ) {
					$issue = $check->check_page( $page );
					return null !== $issue ? array( $issue ) : array();
				}
			}
			return array();
		}

		$issues = array();
		foreach ( $checks as $check ) {
			$issue = $check->check_page( $page );
			if ( null !== $issue ) {
				$issues[] = $issue;
			}
		}
		return $issues;
	}

	/**
	 * Get check IDs for per-page checks (those that override check_page).
	 *
	 * @return string[] Check IDs.
	 */
	public static function page_check_ids(): array {
		$ids = array();
		foreach ( self::all( array() ) as $check ) {
			try {
				$method = new ReflectionMethod( $check, 'check_page' );
				if ( SWPS_Crawl_Check::class !== $method->getDeclaringClass()->getName() ) {
					$ids[] = $check->id();
				}
			} catch ( ReflectionException $e ) {
				// Skip checks without check_page (shouldn't happen).
				continue;
			}
		}
		return $ids;
	}

	/**
	 * Get check IDs for aggregate checks (those that override check_run).
	 *
	 * @return string[] Check IDs.
	 */
	public static function aggregate_check_ids(): array {
		$ids = array();
		foreach ( self::all( array() ) as $check ) {
			try {
				$method = new ReflectionMethod( $check, 'check_run' );
				if ( SWPS_Crawl_Check::class !== $method->getDeclaringClass()->getName() ) {
					$ids[] = $check->id();
				}
			} catch ( ReflectionException $e ) {
				// Skip checks without check_run (shouldn't happen).
				continue;
			}
		}
		return $ids;
	}
}
