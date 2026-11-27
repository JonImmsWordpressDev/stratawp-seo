<?php
/**
 * Unit tests for SWPS_Overlap_Checker's pure matching.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-overlap-checker.php';

class OverlapCheckerTest extends TestCase {

	private function posts(): array {
		return array(
			array(
				'id'            => 11,
				'title'         => 'How to Speed Up a WordPress Site',
				'url'           => 'https://example.com/speed-up-wordpress/',
				'focus_keyword' => 'WordPress speed',
			),
			array(
				'id'            => 12,
				'title'         => 'Our Favourite Coffee Shops',
				'url'           => 'https://example.com/coffee/',
				'focus_keyword' => 'coffee shops',
			),
		);
	}

	public function test_tokens_drop_stopwords_and_fold_plurals(): void {
		$this->assertSame( array( 'wordpress', 'plugin' ), SWPS_Overlap_Checker::tokens( 'The Best WordPress Plugins for You' ) );
		$this->assertSame( array( 'class' ), SWPS_Overlap_Checker::tokens( 'Class' ) );
	}

	public function test_overlap_is_shared_over_smaller_set(): void {
		$result = SWPS_Overlap_Checker::overlap( array( 'a', 'b' ), array( 'a', 'b', 'c', 'd' ) );
		$this->assertSame( 1.0, $result['score'] );
		$this->assertSame( 2, $result['shared'] );
		$this->assertSame( 0.0, SWPS_Overlap_Checker::overlap( array(), array( 'a' ) )['score'] );
	}

	public function test_similar_title_is_reported(): void {
		$found = SWPS_Overlap_Checker::find_overlaps( 'Speed up your WordPress site', $this->posts(), array() );

		$this->assertCount( 1, $found );
		$this->assertSame( 'title', $found[0]['type'] );
		$this->assertSame( 11, $found[0]['post_id'] );
		$this->assertSame( 'https://example.com/speed-up-wordpress/', $found[0]['url'] );
	}

	public function test_focus_keyword_contained_in_topic_is_reported(): void {
		$posts = array(
			array(
				'id'            => 20,
				'title'         => 'Making Pages Load Faster',
				'url'           => 'https://example.com/faster/',
				'focus_keyword' => 'WordPress speed',
			),
		);
		$found = SWPS_Overlap_Checker::find_overlaps( 'WordPress speed checklist for agencies', $posts, array() );

		$this->assertCount( 1, $found );
		$this->assertSame( 'keyword', $found[0]['type'] );
		$this->assertSame( 'WordPress speed', $found[0]['query'] );
	}

	public function test_unrelated_topic_has_no_overlap(): void {
		$this->assertSame( array(), SWPS_Overlap_Checker::find_overlaps( 'Choosing a headless CMS', $this->posts(), array() ) );
	}

	public function test_single_shared_word_is_not_enough(): void {
		$this->assertSame( array(), SWPS_Overlap_Checker::find_overlaps( 'WordPress', $this->posts(), array() ) );
	}

	public function test_ranking_query_within_position_is_reported(): void {
		$rows  = array(
			array( 'query' => 'wordpress caching plugins', 'page' => 'https://example.com/caching/', 'impressions' => 400, 'position' => 6.24 ),
			array( 'query' => 'best wordpress caching plugin', 'page' => 'https://example.com/caching/', 'impressions' => 90, 'position' => 9.0 ),
		);
		$found = SWPS_Overlap_Checker::find_overlaps( 'WordPress caching plugins compared', array(), $rows );

		$this->assertCount( 1, $found, 'one entry per URL' );
		$this->assertSame( 'ranking', $found[0]['type'] );
		$this->assertSame( 'wordpress caching plugins', $found[0]['query'], 'the best-ranking query wins' );
		$this->assertSame( 6.2, $found[0]['position'] );
	}

	public function test_ranking_rows_outside_thresholds_are_ignored(): void {
		$rows = array(
			array( 'query' => 'wordpress caching plugins', 'page' => 'https://example.com/a/', 'impressions' => 400, 'position' => 35.0 ),
			array( 'query' => 'wordpress caching plugins', 'page' => 'https://example.com/b/', 'impressions' => 3, 'position' => 2.0 ),
			array( 'query' => 'wordpress caching plugins', 'page' => '', 'impressions' => 400, 'position' => 2.0 ),
		);
		$this->assertSame( array(), SWPS_Overlap_Checker::find_overlaps( 'WordPress caching plugins', array(), $rows ) );
	}

	public function test_thresholds_are_respected(): void {
		$rows = array(
			array( 'query' => 'wordpress caching plugins', 'page' => 'https://example.com/a/', 'impressions' => 400, 'position' => 35.0 ),
		);
		$found = SWPS_Overlap_Checker::find_overlaps( 'WordPress caching plugins', array(), $rows, array( 'max_position' => 50 ) );
		$this->assertCount( 1, $found );
	}

	public function test_post_and_ranking_for_same_url_reported_once(): void {
		$rows  = array(
			array( 'query' => 'speed up wordpress site', 'page' => 'https://example.com/speed-up-wordpress', 'impressions' => 200, 'position' => 4.0 ),
		);
		$found = SWPS_Overlap_Checker::find_overlaps( 'Speed up your WordPress site', $this->posts(), $rows );

		$this->assertCount( 1, $found );
		$this->assertSame( 'title', $found[0]['type'] );
	}

	public function test_duplicate_checker_match_is_reported(): void {
		$found = SWPS_Overlap_Checker::find_overlaps( 'Coffee shop guide', $this->posts(), array(), array(), 'Our Favourite Coffee Shops' );
		$this->assertSame( 'duplicate', $found[0]['type'] );
		$this->assertSame( 12, $found[0]['post_id'] );

		$remote = SWPS_Overlap_Checker::find_overlaps( 'Headless CMS', array(), array(), array(), 'Headless CMS options' );
		$this->assertSame( 'duplicate', $remote[0]['type'] );
		$this->assertSame( '', $remote[0]['url'] );
	}

	public function test_results_are_capped(): void {
		$rows = array();
		for ( $i = 1; $i <= 9; $i++ ) {
			$rows[] = array( 'query' => 'wordpress caching plugins', 'page' => "https://example.com/p{$i}/", 'impressions' => 100, 'position' => (float) $i );
		}
		$this->assertCount( 5, SWPS_Overlap_Checker::find_overlaps( 'WordPress caching plugins', array(), $rows ) );
		$this->assertCount( 2, SWPS_Overlap_Checker::find_overlaps( 'WordPress caching plugins', array(), $rows, array( 'max_results' => 2 ) ) );
	}

	public function test_error_carries_overlaps_and_409(): void {
		$overlaps = SWPS_Overlap_Checker::find_overlaps( 'Speed up your WordPress site', $this->posts(), array() );
		$error    = SWPS_Overlap_Checker::to_error( 'Speed up your WordPress site', $overlaps );

		$this->assertSame( SWPS_Overlap_Checker::ERROR_CODE, $error->get_error_code() );
		$this->assertStringContainsString( 'How to Speed Up a WordPress Site', $error->get_error_message() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( $overlaps, $error->get_error_data()['overlaps'] );
	}
}
