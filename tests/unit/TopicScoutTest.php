<?php
/**
 * Tests for SWPS_Topic_Scout pure static helpers.
 *
 * Pure-PHP, no WordPress. Covers rank_signals(), build_scout_prompt(),
 * and normalize_proposal() as required by the v4.19 implementation plan.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-topic-scout.php';

/**
 * @covers SWPS_Topic_Scout
 */
class TopicScoutTest extends TestCase {

	// -------------------------------------------------------------------------
	// rank_signals — basic shape
	// -------------------------------------------------------------------------

	public function test_rank_signals_returns_array(): void {
		$result = SWPS_Topic_Scout::rank_signals( array(), array(), array() );
		$this->assertIsArray( $result );
	}

	public function test_rank_signals_result_has_required_keys(): void {
		$questions = array(
			array( 'q' => 'How to bake sourdough?', 'impressions' => 500 ),
		);
		$result = SWPS_Topic_Scout::rank_signals( $questions, array(), array() );
		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 'type', $result[0] );
		$this->assertArrayHasKey( 'text', $result[0] );
		$this->assertArrayHasKey( 'score', $result[0] );
		$this->assertArrayHasKey( 'meta', $result[0] );
	}

	// -------------------------------------------------------------------------
	// rank_signals — question signal scoring
	// -------------------------------------------------------------------------

	public function test_question_signal_score_equals_impressions(): void {
		$questions = array(
			array( 'q' => 'What is sourdough?', 'impressions' => 1200 ),
		);
		$result = SWPS_Topic_Scout::rank_signals( $questions, array(), array() );
		$this->assertSame( 'question', $result[0]['type'] );
		$this->assertEqualsWithDelta( 1200.0, $result[0]['score'], 0.01 );
	}

	// -------------------------------------------------------------------------
	// rank_signals — striking-distance signal scoring
	// -------------------------------------------------------------------------

	public function test_striking_signal_score_formula(): void {
		// score = impressions * (21 - position) / 13
		// position=14, impressions=260 → 260 * (21-14) / 13 = 260 * 7 / 13 = 140
		$striking = array(
			array( 'query' => 'sourdough starter tips', 'impressions' => 260, 'position' => 14 ),
		);
		$result = SWPS_Topic_Scout::rank_signals( array(), $striking, array() );
		$this->assertSame( 'striking', $result[0]['type'] );
		$this->assertEqualsWithDelta( 140.0, $result[0]['score'], 0.01 );
	}

	public function test_striking_signal_outside_range_excluded(): void {
		$striking = array(
			array( 'query' => 'too-high query', 'impressions' => 500, 'position' => 3 ),
			array( 'query' => 'too-low query',  'impressions' => 500, 'position' => 25 ),
		);
		$result = SWPS_Topic_Scout::rank_signals( array(), $striking, array() );
		$this->assertCount( 0, $result );
	}

	// -------------------------------------------------------------------------
	// rank_signals — orphan fixed weight
	// -------------------------------------------------------------------------

	public function test_orphan_signal_has_fixed_weight(): void {
		$orphans = array(
			array( 'post_title' => 'My Orphan Post' ),
		);
		$result = SWPS_Topic_Scout::rank_signals( array(), array(), $orphans );
		$this->assertSame( 'orphan', $result[0]['type'] );
		$this->assertEqualsWithDelta( 5.0, $result[0]['score'], 0.01 );
	}

	// -------------------------------------------------------------------------
	// rank_signals — ordering and limit
	// -------------------------------------------------------------------------

	public function test_rank_signals_ordered_by_score_descending(): void {
		$questions = array(
			array( 'q' => 'Low impressions query', 'impressions' => 10 ),
			array( 'q' => 'High impressions query', 'impressions' => 1000 ),
		);
		$result = SWPS_Topic_Scout::rank_signals( $questions, array(), array() );
		$this->assertGreaterThan( $result[1]['score'], $result[0]['score'] );
		$this->assertSame( 'High impressions query', $result[0]['text'] );
	}

	public function test_rank_signals_respects_limit(): void {
		$questions = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$questions[] = array( 'q' => "Query $i", 'impressions' => $i * 10 );
		}
		$result = SWPS_Topic_Scout::rank_signals( $questions, array(), array(), 5 );
		$this->assertCount( 5, $result );
	}

	// -------------------------------------------------------------------------
	// rank_signals — de-duplication
	// -------------------------------------------------------------------------

	public function test_duplicate_text_deduped_highest_score_wins(): void {
		// Same normalised text ("sourdough tips") from two signal types.
		$questions = array(
			array( 'q' => 'Sourdough tips', 'impressions' => 800 ),
		);
		$orphans = array(
			array( 'post_title' => 'Sourdough Tips' ), // same text, score=5
		);
		$result = SWPS_Topic_Scout::rank_signals( $questions, array(), $orphans );
		$this->assertCount( 1, $result );
		$this->assertEqualsWithDelta( 800.0, $result[0]['score'], 0.01 );
	}

	// -------------------------------------------------------------------------
	// build_scout_prompt
	// -------------------------------------------------------------------------

	public function test_build_scout_prompt_contains_site_context(): void {
		$ranked  = array(
			array( 'type' => 'question', 'text' => 'How to bake bread?', 'score' => 100, 'meta' => array( 'impressions' => 100 ) ),
		);
		$context = 'artisan baking';
		$prompt  = SWPS_Topic_Scout::build_scout_prompt( $ranked, $context );
		$this->assertStringContainsString( 'artisan baking', $prompt );
	}

	public function test_build_scout_prompt_contains_signal_text(): void {
		$ranked = array(
			array( 'type' => 'striking', 'text' => 'best bread recipes', 'score' => 200, 'meta' => array( 'impressions' => 400, 'position' => 12.0 ) ),
		);
		$prompt = SWPS_Topic_Scout::build_scout_prompt( $ranked, '' );
		$this->assertStringContainsString( 'best bread recipes', $prompt );
	}

	public function test_build_scout_prompt_requests_json_array(): void {
		$ranked = array(
			array( 'type' => 'orphan', 'text' => 'Orphan Page', 'score' => 5, 'meta' => array() ),
		);
		$prompt = SWPS_Topic_Scout::build_scout_prompt( $ranked, '' );
		$this->assertStringContainsString( 'JSON array', $prompt );
	}

	// -------------------------------------------------------------------------
	// normalize_proposal — valid rows
	// -------------------------------------------------------------------------

	public function test_normalize_proposal_valid_row_returned(): void {
		$row       = array(
			'title'     => 'How to Bake Perfect Sourdough',
			'keyword'   => 'sourdough baking',
			'template'  => 'how-to',
			'rationale' => 'Query has 500 impressions at position 12.',
		);
		$templates = array( 'auto', 'how-to', 'listicle' );
		$result    = SWPS_Topic_Scout::normalize_proposal( $row, $templates );
		$this->assertNotNull( $result );
		$this->assertSame( 'How to Bake Perfect Sourdough', $result['title'] );
		$this->assertSame( 'how-to', $result['template'] );
		$this->assertSame( 'sourdough baking', $result['keyword'] );
	}

	// -------------------------------------------------------------------------
	// normalize_proposal — junk rows return null
	// -------------------------------------------------------------------------

	public function test_normalize_proposal_missing_title_returns_null(): void {
		$row    = array( 'rationale' => 'Has impressions.', 'template' => 'auto' );
		$result = SWPS_Topic_Scout::normalize_proposal( $row, array( 'auto' ) );
		$this->assertNull( $result );
	}

	public function test_normalize_proposal_missing_rationale_returns_null(): void {
		$row    = array( 'title' => 'Great Post Title' );
		$result = SWPS_Topic_Scout::normalize_proposal( $row, array( 'auto' ) );
		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// normalize_proposal — title truncation
	// -------------------------------------------------------------------------

	public function test_normalize_proposal_title_truncated_to_120(): void {
		$row    = array(
			'title'     => str_repeat( 'A', 150 ),
			'rationale' => 'Some rationale.',
		);
		$result = SWPS_Topic_Scout::normalize_proposal( $row, array( 'auto' ) );
		$this->assertNotNull( $result );
		$this->assertSame( 120, strlen( $result['title'] ) );
	}

	// -------------------------------------------------------------------------
	// normalize_proposal — unknown template falls back to 'auto'
	// -------------------------------------------------------------------------

	public function test_normalize_proposal_unknown_template_falls_back_to_auto(): void {
		$row = array(
			'title'     => 'Great Post Title',
			'rationale' => 'Some rationale.',
			'template'  => 'nonexistent-template',
		);
		$result = SWPS_Topic_Scout::normalize_proposal( $row, array( 'auto', 'listicle' ) );
		$this->assertNotNull( $result );
		$this->assertSame( 'auto', $result['template'] );
	}
}
