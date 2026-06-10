<?php
/**
 * Tests for SWPS_Question_Coverage pure static helpers: format_gap_instruction,
 * tokens_similar, questions_unanswered, format_unanswered_instruction.
 *
 * Pure-PHP, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-question-coverage.php';

/**
 * @covers SWPS_Question_Coverage
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

	// -------------------------------------------------------------------------
	// tokens_similar
	// -------------------------------------------------------------------------

	public function test_tokens_similar_exact_match_is_one(): void {
		$score = SWPS_Question_Coverage::tokens_similar(
			'How long does sourdough last?',
			'How long does sourdough last'
		);
		$this->assertSame( 1.0, $score );
	}

	public function test_tokens_similar_paraphrase_above_threshold(): void {
		$score = SWPS_Question_Coverage::tokens_similar(
			'how to store sourdough bread',
			'Storing sourdough bread'
		);
		$this->assertGreaterThanOrEqual( 0.6, $score );
	}

	public function test_tokens_similar_unrelated_below_threshold(): void {
		$score = SWPS_Question_Coverage::tokens_similar(
			'best running shoes for marathons',
			'sourdough starter feeding schedule'
		);
		$this->assertLessThan( 0.6, $score );
	}

	public function test_tokens_similar_empty_inputs_return_zero(): void {
		$this->assertSame( 0.0, SWPS_Question_Coverage::tokens_similar( '', 'anything here' ) );
		$this->assertSame( 0.0, SWPS_Question_Coverage::tokens_similar( 'anything here', '' ) );
		$this->assertSame( 0.0, SWPS_Question_Coverage::tokens_similar( '', '' ) );
	}

	public function test_tokens_similar_stopword_robustness(): void {
		// Stopwords ("what is the ... for") must not dilute the overlap.
		$score = SWPS_Question_Coverage::tokens_similar(
			'what is the best flour for sourdough',
			'Best flour for sourdough'
		);
		$this->assertGreaterThanOrEqual( 0.6, $score );
	}

	public function test_tokens_similar_only_stopwords_returns_zero(): void {
		$this->assertSame( 0.0, SWPS_Question_Coverage::tokens_similar( 'what is the', 'how do I' ) );
	}

	// -------------------------------------------------------------------------
	// questions_unanswered
	// -------------------------------------------------------------------------

	public function test_questions_unanswered_excludes_matched_heading(): void {
		$questions = array( 'how long does sourdough last' );
		$headings  = array( 'How long does sourdough last?' );
		$this->assertSame( array(), SWPS_Question_Coverage::questions_unanswered( $questions, $headings ) );
	}

	public function test_questions_unanswered_includes_unmatched_question(): void {
		$questions = array( 'can you freeze sourdough starter' );
		$headings  = array( 'Choosing the right flour', 'Bulk fermentation timing' );
		$result    = SWPS_Question_Coverage::questions_unanswered( $questions, $headings );
		$this->assertSame( $questions, $result );
	}

	public function test_questions_unanswered_empty_headings_returns_all(): void {
		$questions = array( 'q one here', 'q two here' );
		$this->assertSame( $questions, SWPS_Question_Coverage::questions_unanswered( $questions, array() ) );
	}

	public function test_questions_unanswered_empty_questions_returns_empty(): void {
		$this->assertSame( array(), SWPS_Question_Coverage::questions_unanswered( array(), array( 'A heading' ) ) );
	}

	public function test_questions_unanswered_mixed(): void {
		$questions = array(
			'how long does sourdough last',
			'can you freeze sourdough starter',
		);
		$headings = array( 'How long does sourdough last?' );
		$result   = SWPS_Question_Coverage::questions_unanswered( $questions, $headings );
		$this->assertSame( array( 'can you freeze sourdough starter' ), $result );
	}

	// -------------------------------------------------------------------------
	// format_unanswered_instruction
	// -------------------------------------------------------------------------

	public function test_format_unanswered_empty_returns_empty_string(): void {
		$this->assertSame( '', SWPS_Question_Coverage::format_unanswered_instruction( array() ) );
	}

	public function test_format_unanswered_contains_question_and_impressions(): void {
		$result = SWPS_Question_Coverage::format_unanswered_instruction( array(
			array(
				'q'           => 'how long does sourdough last',
				'impressions' => 420,
			),
		) );
		$this->assertStringContainsString( 'how long does sourdough last', $result );
		$this->assertStringContainsString( '420', $result );
		$this->assertStringContainsStringIgnoringCase( 'qa', $result );
	}

	public function test_format_unanswered_cap_respected(): void {
		$questions = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$questions[] = array(
				'q'           => "question number {$i}",
				'impressions' => 100 - $i,
			);
		}
		$result = SWPS_Question_Coverage::format_unanswered_instruction( $questions, 3 );
		$this->assertStringContainsString( 'question number 3', $result );
		$this->assertStringNotContainsString( 'question number 4', $result );
	}

	public function test_format_unanswered_mentions_search_console_source(): void {
		$result = SWPS_Question_Coverage::format_unanswered_instruction( array(
			array(
				'q'           => 'real searcher question',
				'impressions' => 10,
			),
		) );
		$this->assertStringContainsStringIgnoringCase( 'search console', $result );
	}
}
