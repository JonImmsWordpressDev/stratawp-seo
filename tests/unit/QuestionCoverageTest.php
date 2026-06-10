<?php
/**
 * Tests for SWPS_Question_Coverage::format_gap_instruction().
 *
 * Pure-PHP, no WordPress. The formatter is the only public surface tested here.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-question-coverage.php';

/**
 * @covers SWPS_Question_Coverage::format_gap_instruction
 */
class QuestionCoverageTest extends TestCase {

	// -------------------------------------------------------------------------
	// format_gap_instruction — empty input
	// -------------------------------------------------------------------------

	public function test_empty_sub_queries_returns_empty_string(): void {
		$result = SWPS_Question_Coverage::format_gap_instruction( array() );
		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// format_gap_instruction — answered excluded
	// -------------------------------------------------------------------------

	public function test_answered_sub_queries_excluded(): void {
		$sub_queries = array(
			array( 'q' => 'What is sourdough?', 'status' => 'answered' ),
			array( 'q' => 'How to store it?',   'status' => 'answered' ),
		);
		$result = SWPS_Question_Coverage::format_gap_instruction( $sub_queries );
		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// format_gap_instruction — missing prioritised over partial
	// -------------------------------------------------------------------------

	public function test_missing_listed_before_partial(): void {
		$sub_queries = array(
			array( 'q' => 'Partial question one?', 'status' => 'partial' ),
			array( 'q' => 'Missing question one?', 'status' => 'missing' ),
			array( 'q' => 'Missing question two?', 'status' => 'missing' ),
			array( 'q' => 'Partial question two?', 'status' => 'partial' ),
		);
		$result = SWPS_Question_Coverage::format_gap_instruction( $sub_queries );
		$pos_missing = strpos( $result, 'Missing question one?' );
		$pos_partial = strpos( $result, 'Partial question one?' );
		$this->assertNotFalse( $pos_missing );
		$this->assertNotFalse( $pos_partial );
		$this->assertLessThan( $pos_partial, $pos_missing );
	}

	// -------------------------------------------------------------------------
	// format_gap_instruction — cap respected
	// -------------------------------------------------------------------------

	public function test_cap_limits_output(): void {
		$sub_queries = array(
			array( 'q' => 'Q1?', 'status' => 'missing' ),
			array( 'q' => 'Q2?', 'status' => 'missing' ),
			array( 'q' => 'Q3?', 'status' => 'missing' ),
			array( 'q' => 'Q4?', 'status' => 'missing' ),
			array( 'q' => 'Q5?', 'status' => 'missing' ),
		);
		$result = SWPS_Question_Coverage::format_gap_instruction( $sub_queries, 3 );
		$this->assertStringContainsString( 'Q1?', $result );
		$this->assertStringContainsString( 'Q2?', $result );
		$this->assertStringContainsString( 'Q3?', $result );
		$this->assertStringNotContainsString( 'Q4?', $result );
		$this->assertStringNotContainsString( 'Q5?', $result );
	}

	public function test_default_cap_is_three(): void {
		$sub_queries = array(
			array( 'q' => 'A1?', 'status' => 'missing' ),
			array( 'q' => 'A2?', 'status' => 'missing' ),
			array( 'q' => 'A3?', 'status' => 'missing' ),
			array( 'q' => 'A4?', 'status' => 'missing' ),
		);
		$result = SWPS_Question_Coverage::format_gap_instruction( $sub_queries );
		$this->assertStringNotContainsString( 'A4?', $result );
	}

	// -------------------------------------------------------------------------
	// format_gap_instruction — only partial questions present
	// -------------------------------------------------------------------------

	public function test_partial_only_produces_output(): void {
		$sub_queries = array(
			array( 'q' => 'Partial only?', 'status' => 'partial' ),
		);
		$result = SWPS_Question_Coverage::format_gap_instruction( $sub_queries );
		$this->assertNotSame( '', $result );
		$this->assertStringContainsString( 'Partial only?', $result );
	}

	// -------------------------------------------------------------------------
	// format_gap_instruction — result contains a qa-insert instruction hint
	// -------------------------------------------------------------------------

	public function test_result_contains_qa_instruction(): void {
		$sub_queries = array(
			array( 'q' => 'How long does it last?', 'status' => 'missing' ),
		);
		$result = SWPS_Question_Coverage::format_gap_instruction( $sub_queries );
		// The instruction should direct the AI towards qa inserts.
		$this->assertStringContainsStringIgnoringCase( 'qa', $result );
	}
}
