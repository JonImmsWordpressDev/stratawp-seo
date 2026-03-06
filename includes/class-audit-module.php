<?php
/**
 * Abstract base class for SEO audit modules.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SWPS_Audit_Module {

	/**
	 * Unique module identifier (e.g. 'canonical', 'sitemap').
	 */
	abstract public function get_id(): string;

	/**
	 * Human-readable module name.
	 */
	abstract public function get_label(): string;

	/**
	 * Run the audit check.
	 *
	 * @return array {
	 *     @type int    $score   0-100 score.
	 *     @type string $status  'pass' | 'warning' | 'fail'.
	 *     @type array  $issues  Array of issue arrays with 'post_id', 'message', 'fixable'.
	 *     @type string $summary One-line human summary.
	 * }
	 */
	abstract public function run(): array;

	/**
	 * Whether this module supports auto-fix.
	 */
	abstract public function can_auto_fix(): bool;

	/**
	 * Run auto-fix for the given issues.
	 *
	 * @param array $issues Issues from a prior run().
	 * @return array { @type int $fixed Count of fixed issues. @type string[] $messages Log messages. }
	 */
	public function auto_fix( array $issues ): array {
		return [ 'fixed' => 0, 'messages' => [] ];
	}

	/**
	 * Determine status string from a score.
	 *
	 * @param int $score 0-100 score.
	 * @return string 'pass' | 'warning' | 'fail'.
	 */
	protected function status_from_score( int $score ): string {
		if ( $score >= 80 ) {
			return 'pass';
		}
		if ( $score >= 50 ) {
			return 'warning';
		}
		return 'fail';
	}

	/**
	 * Helper: Get all published public posts/pages.
	 *
	 * @param int $limit Max posts to query. Default 500.
	 * @return WP_Post[]
	 */
	protected function get_public_posts( int $limit = 500 ): array {
		return get_posts( [
			'post_type'      => get_post_types( [ 'public' => true ] ),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );
	}
}
