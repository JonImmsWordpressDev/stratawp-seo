<?php
/**
 * Tests for SWPS_Cannibalization pure static helpers.
 *
 * Pure-PHP, no WordPress dependency.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-cannibalization.php';

/**
 * @covers SWPS_Cannibalization
 */
class CannibalizationTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a GSC row in the format returned by get_query_page_rows().
	 *
	 * @param string $query
	 * @param string $page
	 * @param int    $clicks
	 * @param int    $impressions
	 * @param float  $position
	 * @return array
	 */
	private function row( string $query, string $page, int $clicks = 10, int $impressions = 100, float $position = 5.0 ): array {
		return array(
			'keys'        => array( $query, $page ),
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'position'    => $position,
		);
	}

	// =========================================================================
	// find_candidates
	// =========================================================================

	/**
	 * Two pages splitting impressions 60/40 should produce a candidate.
	 */
	public function test_find_candidates_real_split_produces_candidate(): void {
		$rows = array(
			$this->row( 'seo tools', 'https://example.com/a', 10, 600 ),
			$this->row( 'seo tools', 'https://example.com/b', 8, 400 ),
		);
		$candidates = SWPS_Cannibalization::find_candidates( $rows );
		$this->assertCount( 1, $candidates );
		$this->assertSame( 'seo tools', $candidates[0]['query'] );
		$this->assertCount( 2, $candidates[0]['pages'] );
	}

	/**
	 * Only one page for a query → not a candidate.
	 */
	public function test_find_candidates_single_page_excluded(): void {
		$rows       = array( $this->row( 'seo tools', 'https://example.com/a', 10, 500 ) );
		$candidates = SWPS_Cannibalization::find_candidates( $rows );
		$this->assertCount( 0, $candidates );
	}

	/**
	 * Total impressions below min_impressions floor → not a candidate.
	 */
	public function test_find_candidates_below_min_impressions(): void {
		$rows = array(
			$this->row( 'seo tools', 'https://example.com/a', 5, 30 ),
			$this->row( 'seo tools', 'https://example.com/b', 5, 30 ),
		);
		$candidates = SWPS_Cannibalization::find_candidates( $rows, array( 'min_impressions' => 100 ) );
		$this->assertCount( 0, $candidates );
	}

	/**
	 * 99/1 impression split (noise) should not produce a candidate.
	 */
	public function test_find_candidates_noise_split_not_candidate(): void {
		$rows = array(
			$this->row( 'seo tools', 'https://example.com/a', 99, 990 ),
			$this->row( 'seo tools', 'https://example.com/b', 1, 10 ),
		);
		$candidates = SWPS_Cannibalization::find_candidates( $rows );
		$this->assertCount( 0, $candidates );
	}

	/**
	 * Position deeper than max_position should be excluded.
	 */
	public function test_find_candidates_deep_position_excluded(): void {
		$rows = array(
			$this->row( 'seo tools', 'https://example.com/a', 10, 500, 35.0 ),
			$this->row( 'seo tools', 'https://example.com/b', 8, 500, 35.0 ),
		);
		$candidates = SWPS_Cannibalization::find_candidates( $rows, array( 'max_position' => 30 ) );
		$this->assertCount( 0, $candidates );
	}

	// =========================================================================
	// classify_finding
	// =========================================================================

	/**
	 * Brand token in query → excluded.
	 */
	public function test_classify_brand_query_excluded(): void {
		$finding = array( 'query' => 'acme seo tools', 'pages' => array() );
		$result  = SWPS_Cannibalization::classify_finding( $finding, array( 'acme' ) );
		$this->assertSame( 'excluded', $result );
	}

	/**
	 * Pagination URL in pages → excluded.
	 */
	public function test_classify_pagination_url_excluded(): void {
		$finding = array(
			'query' => 'seo tips',
			'pages' => array(
				array( 'page' => 'https://example.com/seo-tips/', 'clicks' => 10, 'impressions' => 200, 'position' => 4.0 ),
				array( 'page' => 'https://example.com/seo-tips/page/2/', 'clicks' => 5, 'impressions' => 100, 'position' => 8.0 ),
			),
		);
		$result = SWPS_Cannibalization::classify_finding( $finding, array() );
		$this->assertSame( 'excluded', $result );
	}

	/**
	 * Archive URL in pages → downgraded to review.
	 */
	public function test_classify_archive_url_downgraded_to_review(): void {
		$finding = array(
			'query' => 'seo tips',
			'pages' => array(
				array( 'page' => 'https://example.com/category/seo/', 'clicks' => 10, 'impressions' => 200, 'position' => 5.0 ),
				array( 'page' => 'https://example.com/best-seo-tips/', 'clicks' => 8, 'impressions' => 150, 'position' => 7.0 ),
			),
		);
		$result = SWPS_Cannibalization::classify_finding( $finding, array() );
		$this->assertSame( 'review', $result );
	}

	/**
	 * Tag archive URL → review.
	 */
	public function test_classify_tag_archive_is_review(): void {
		$finding = array(
			'query' => 'seo guide',
			'pages' => array(
				array( 'page' => 'https://example.com/tag/seo/', 'clicks' => 10, 'impressions' => 200, 'position' => 5.0 ),
				array( 'page' => 'https://example.com/seo-guide/', 'clicks' => 12, 'impressions' => 250, 'position' => 4.0 ),
			),
		);
		$result = SWPS_Cannibalization::classify_finding( $finding, array() );
		$this->assertSame( 'review', $result );
	}

	/**
	 * Non-brand, non-paginated, non-archive → cannibal.
	 */
	public function test_classify_real_cannibalization(): void {
		$finding = array(
			'query' => 'best seo plugins',
			'pages' => array(
				array( 'page' => 'https://example.com/seo-plugins/', 'clicks' => 20, 'impressions' => 400, 'position' => 3.0 ),
				array( 'page' => 'https://example.com/top-seo-tools/', 'clicks' => 15, 'impressions' => 300, 'position' => 6.0 ),
			),
		);
		$result = SWPS_Cannibalization::classify_finding( $finding, array( 'mybrand' ) );
		$this->assertSame( 'cannibal', $result );
	}

	// =========================================================================
	// pick_winner
	// =========================================================================

	/**
	 * Higher clicks wins.
	 */
	public function test_pick_winner_higher_clicks_wins(): void {
		$pages = array(
			array( 'page' => 'https://example.com/a', 'clicks' => 10, 'impressions' => 200, 'position' => 5.0 ),
			array( 'page' => 'https://example.com/b', 'clicks' => 20, 'impressions' => 200, 'position' => 8.0 ),
		);
		$this->assertSame( 1, SWPS_Cannibalization::pick_winner( $pages ) );
	}

	/**
	 * Equal clicks → better (lower) position wins.
	 */
	public function test_pick_winner_tiebreak_by_position(): void {
		$pages = array(
			array( 'page' => 'https://example.com/a', 'clicks' => 15, 'impressions' => 200, 'position' => 6.0 ),
			array( 'page' => 'https://example.com/b', 'clicks' => 15, 'impressions' => 200, 'position' => 3.0 ),
		);
		$this->assertSame( 1, SWPS_Cannibalization::pick_winner( $pages ) );
	}

	/**
	 * First page wins if all equal.
	 */
	public function test_pick_winner_all_equal_returns_first(): void {
		$pages = array(
			array( 'page' => 'https://example.com/a', 'clicks' => 5, 'impressions' => 100, 'position' => 5.0 ),
			array( 'page' => 'https://example.com/b', 'clicks' => 5, 'impressions' => 100, 'position' => 5.0 ),
		);
		$this->assertSame( 0, SWPS_Cannibalization::pick_winner( $pages ) );
	}

	// =========================================================================
	// expected_ctr
	// =========================================================================

	/**
	 * Position 1 should return approximately 28.5 %.
	 */
	public function test_expected_ctr_position_1(): void {
		$ctr = SWPS_Cannibalization::expected_ctr( 1.0 );
		$this->assertEqualsWithDelta( 0.285, $ctr, 0.001 );
	}

	/**
	 * Position 10 from the curve.
	 */
	public function test_expected_ctr_position_10(): void {
		$ctr = SWPS_Cannibalization::expected_ctr( 10.0 );
		$this->assertEqualsWithDelta( 0.027, $ctr, 0.001 );
	}

	/**
	 * Beyond the curve (position 20) should still return a positive value.
	 */
	public function test_expected_ctr_beyond_curve_positive(): void {
		$ctr = SWPS_Cannibalization::expected_ctr( 20.0 );
		$this->assertGreaterThan( 0.0, $ctr );
		$this->assertLessThan( 0.027, $ctr );
	}

	/**
	 * Floating-point position rounds to nearest integer bucket.
	 */
	public function test_expected_ctr_fractional_position_rounds(): void {
		$ctr_3  = SWPS_Cannibalization::expected_ctr( 3.0 );
		$ctr_35 = SWPS_Cannibalization::expected_ctr( 3.5 );
		$ctr_4  = SWPS_Cannibalization::expected_ctr( 4.0 );
		// 3.5 rounds to 4.
		$this->assertEqualsWithDelta( $ctr_4, $ctr_35, 0.001 );
		// Not the same as position 3.
		$this->assertNotEqualsWithDelta( $ctr_3, $ctr_35, 0.001 );
	}

	/**
	 * Surviving candidates resolve only vanished findings (partial).
	 */
	public function test_resolve_mode_partial_when_candidates_seen(): void {
		$this->assertSame( 'partial', SWPS_Cannibalization::resolve_mode( true, true ) );
	}

	/**
	 * All-excluded weeks (brand/pagination only) must leave open findings untouched.
	 */
	public function test_resolve_mode_none_when_all_candidates_excluded(): void {
		$this->assertSame( 'none', SWPS_Cannibalization::resolve_mode( true, false ) );
	}

	/**
	 * Genuinely zero candidates resolves everything open.
	 */
	public function test_resolve_mode_all_when_no_candidates(): void {
		$this->assertSame( 'all', SWPS_Cannibalization::resolve_mode( false, false ) );
	}
}
