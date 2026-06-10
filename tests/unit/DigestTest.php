<?php
/**
 * Unit tests for SWPS_Digest pure computation helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-digest.php';

/**
 * Tests for SWPS_Digest static helpers (no WordPress required).
 */
class DigestTest extends TestCase {

	// ---- parse_recipients() ----

	/**
	 * Mixed separators (comma, space, semicolon, newline) all split correctly.
	 */
	public function test_parse_recipients_mixed_separators(): void {
		$result = SWPS_Digest::parse_recipients( "a@b.com, c@d.com;e@f.com\ng@h.io" );
		$this->assertCount( 4, $result );
		$this->assertContains( 'a@b.com', $result );
		$this->assertContains( 'c@d.com', $result );
		$this->assertContains( 'e@f.com', $result );
		$this->assertContains( 'g@h.io', $result );
	}

	/**
	 * Invalid entries (bare words, malformed addresses) are dropped.
	 */
	public function test_parse_recipients_invalid_dropped(): void {
		$result = SWPS_Digest::parse_recipients( 'good@test.com notanemail bad@ @nope.com' );
		$this->assertCount( 1, $result );
		$this->assertSame( 'good@test.com', $result[0] );
	}

	/**
	 * Case-insensitive deduplication: A@B.com and a@b.com are the same address.
	 */
	public function test_parse_recipients_case_dedup(): void {
		$result = SWPS_Digest::parse_recipients( 'A@B.com a@b.com' );
		$this->assertCount( 1, $result );
	}

	/**
	 * Empty string returns empty array.
	 */
	public function test_parse_recipients_empty_string(): void {
		$this->assertSame( array(), SWPS_Digest::parse_recipients( '' ) );
	}

	// ---- compute_movers() ----

	/**
	 * Posts with positive delta end up in risers, negative in fallers.
	 */
	public function test_compute_movers_rising_falling_split(): void {
		$previous = array(
			1 => 40,
			2 => 60,
			3 => 50,
		);
		$current  = array(
			1 => 50,
			2 => 45,
			3 => 50,
		);
		$result   = SWPS_Digest::compute_movers( $previous, $current );
		$this->assertArrayHasKey( 1, $result['risers'] );
		$this->assertSame( 10, $result['risers'][1] );
		$this->assertArrayHasKey( 2, $result['fallers'] );
		$this->assertSame( -15, $result['fallers'][2] );
		$this->assertArrayNotHasKey( 3, $result['risers'] );
		$this->assertArrayNotHasKey( 3, $result['fallers'] );
	}

	/**
	 * Limit parameter caps the number of risers and fallers returned.
	 */
	public function test_compute_movers_limit_respected(): void {
		$previous = array();
		$current  = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$previous[ $i ] = 10;
			$current[ $i ]  = 10 + $i;
		}
		$result = SWPS_Digest::compute_movers( $previous, $current, 3 );
		$this->assertCount( 3, $result['risers'] );
		$this->assertCount( 0, $result['fallers'] );
	}

	/**
	 * Posts absent from previous are skipped (not treated as 0).
	 */
	public function test_compute_movers_absent_from_previous_skipped(): void {
		$previous = array( 1 => 50 );
		$current  = array(
			1 => 60,
			2 => 80,
		);
		$result   = SWPS_Digest::compute_movers( $previous, $current );
		$this->assertArrayNotHasKey( 2, $result['risers'] );
		$this->assertArrayNotHasKey( 2, $result['fallers'] );
		$this->assertArrayHasKey( 1, $result['risers'] );
	}

	/**
	 * Posts with unchanged scores are excluded entirely.
	 */
	public function test_compute_movers_unchanged_skipped(): void {
		$previous = array(
			1 => 50,
			2 => 70,
		);
		$current  = array(
			1 => 50,
			2 => 70,
		);
		$result   = SWPS_Digest::compute_movers( $previous, $current );
		$this->assertEmpty( $result['risers'] );
		$this->assertEmpty( $result['fallers'] );
	}

	// ---- keyword_deltas() ----

	/**
	 * Improvement (lower position) produces a negative delta in winners.
	 */
	public function test_keyword_deltas_improvement_in_winners(): void {
		$pairs  = array( 'seo tips' => array( 10.0, 5.0 ) );
		$result = SWPS_Digest::keyword_deltas( $pairs );
		$this->assertArrayHasKey( 'seo tips', $result['winners'] );
		$this->assertSame( -5.0, $result['winners']['seo tips'] );
		$this->assertEmpty( $result['losers'] );
	}

	/**
	 * Null old or new position causes keyword to be skipped.
	 */
	public function test_keyword_deltas_null_skipped(): void {
		$pairs  = array(
			'kw_null_old' => array( null, 5.0 ),
			'kw_null_new' => array( 5.0, null ),
			'kw_good'     => array( 8.0, 3.0 ),
		);
		$result = SWPS_Digest::keyword_deltas( $pairs );
		$this->assertCount( 1, $result['winners'] );
		$this->assertArrayHasKey( 'kw_good', $result['winners'] );
	}

	/**
	 * Zero delta is excluded from both winners and losers.
	 */
	public function test_keyword_deltas_zero_skipped(): void {
		$pairs  = array( 'same kw' => array( 5.0, 5.0 ) );
		$result = SWPS_Digest::keyword_deltas( $pairs );
		$this->assertEmpty( $result['winners'] );
		$this->assertEmpty( $result['losers'] );
	}

	/**
	 * Limit parameter caps movers per direction.
	 */
	public function test_keyword_deltas_limit_respected(): void {
		$pairs = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$pairs[ "kw_{$i}" ] = array( (float) ( 10 + $i ), (float) $i ); // all improve.
		}
		$result = SWPS_Digest::keyword_deltas( $pairs, 3 );
		$this->assertCount( 3, $result['winners'] );
		$this->assertEmpty( $result['losers'] );
	}
}
