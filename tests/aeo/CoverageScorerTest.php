<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-coverage-scorer.php';
require_once __DIR__ . '/FakeAIProvider.php';
require_once __DIR__ . '/../../includes/class-model-catalog.php';
require_once __DIR__ . '/../../includes/class-cost-tracker.php';

final class CoverageScorerTest extends TestCase {

	public function test_score_returns_0_to_100_from_ai_response(): void {
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'sub_queries'   => array(
				array( 'q' => 'How to store sourdough', 'status' => 'missing' ),
			),
			'entity_issues' => array(),
			'score'         => 72,
		);
		$scorer = new SWPS_AEO_Coverage_Scorer( $provider );
		$result = $scorer->score( 'Sourdough basics', '<p>Sourdough is bread.</p>' );

		$this->assertSame( 72, $result['score'] );
		$this->assertCount( 1, $result['coverage_gaps'] );
		$this->assertCount( 1, $result['sub_queries'] );
		$this->assertSame( 'How to store sourdough', $result['sub_queries'][0]['q'] );
		$this->assertSame( 'missing', $result['sub_queries'][0]['status'] );
	}

	public function test_sub_queries_answered_excluded_from_coverage_gaps(): void {
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'sub_queries' => array(
				array( 'q' => 'What is sourdough?',    'status' => 'answered' ),
				array( 'q' => 'How to store it?',      'status' => 'missing' ),
				array( 'q' => 'Best flour to use?',    'status' => 'partial' ),
			),
			'entity_issues' => array(),
			'score'         => 60,
		);
		$scorer = new SWPS_AEO_Coverage_Scorer( $provider );
		$result = $scorer->score( 'Sourdough basics', '<p>Body.</p>' );

		// BC: coverage_gaps = only missing questions.
		$this->assertCount( 1, $result['coverage_gaps'] );
		$this->assertSame( 'How to store it?', $result['coverage_gaps'][0] );
		// sub_queries includes all three.
		$this->assertCount( 3, $result['sub_queries'] );
	}

	public function test_focus_keyword_included_in_prompt(): void {
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'sub_queries'   => array(),
			'entity_issues' => array(),
			'score'         => 80,
		);
		$scorer = new SWPS_AEO_Coverage_Scorer( $provider );
		$scorer->score( 'Bread baking', '<p>Body.</p>', 'sourdough starter' );

		$this->assertStringContainsString( 'sourdough starter', $provider->last_user );
	}

	public function test_score_falls_back_on_provider_failure(): void {
		$provider = new FakeAIProvider();
		$provider->should_fail = true;
		$scorer = new SWPS_AEO_Coverage_Scorer( $provider );
		$result = $scorer->score( 'Sourdough basics', '<p>Body.</p>' );

		$this->assertNull( $result['score'] );
		$this->assertSame( 'AI provider error', $result['error'] );
		$this->assertSame( array(), $result['sub_queries'] );
	}

	public function test_score_clamps_out_of_range_values(): void {
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'sub_queries'   => array(),
			'entity_issues' => array(),
			'score'         => 150,
		);
		$scorer = new SWPS_AEO_Coverage_Scorer( $provider );
		$result = $scorer->score( 'X', '<p>Y.</p>' );

		$this->assertSame( 100, $result['score'] );
	}

	public function test_score_handles_missing_score_field(): void {
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'sub_queries'   => array(),
			'entity_issues' => array(),
		);
		$scorer = new SWPS_AEO_Coverage_Scorer( $provider );
		$result = $scorer->score( 'X', '<p>Y.</p>' );

		$this->assertNull( $result['score'] );
	}

	public function test_outline_includes_h2s_and_first_sentences(): void {
		$html = '<h2>Choosing flour</h2><p>Bread flour with 12-14% protein produces the best gluten.</p>' .
				'<h2>Fermentation</h2><p>Bulk runs 4-6 hours.</p>';
		$scorer = new SWPS_AEO_Coverage_Scorer( new FakeAIProvider() );
		$outline = $scorer->build_outline( $html );

		$this->assertStringContainsString( 'Choosing flour', $outline );
		$this->assertStringContainsString( 'Bread flour with 12-14% protein', $outline );
		$this->assertStringContainsString( 'Fermentation', $outline );
	}

	public function test_records_usage_through_injected_tracker(): void {
		$provider                = new FakeAIProvider();
		$provider->next_response = array(
			'sub_queries'   => array(),
			'entity_issues' => array(),
			'score'         => 60,
			'_usage'        => array( 'input_tokens' => 500, 'output_tokens' => 120 ),
		);
		$tracker = new RecordingCostTracker();
		$scorer  = new SWPS_AEO_Coverage_Scorer( $provider, $tracker );
		$result  = $scorer->score( 'Title', '<h2>A</h2>' );

		$this->assertSame( 60, $result['score'] );
		$this->assertCount( 1, $tracker->calls );
		$this->assertSame( 500, $tracker->calls[0][1] );
		$this->assertSame( 120, $tracker->calls[0][2] );
	}

	public function test_does_not_track_when_usage_absent_or_zero(): void {
		$provider = new FakeAIProvider();
		$tracker  = new RecordingCostTracker();
		$scorer   = new SWPS_AEO_Coverage_Scorer( $provider, $tracker );

		$provider->next_response = array( 'score' => 50 );
		$scorer->score( 'Title', '<h2>A</h2>' );
		$provider->next_response = array(
			'score'  => 50,
			'_usage' => array( 'input_tokens' => 0, 'output_tokens' => 0 ),
		);
		$scorer->score( 'Title', '<h2>A</h2>' );

		$this->assertSame( array(), $tracker->calls );
	}

	public function test_null_tracker_with_usage_changes_nothing(): void {
		$provider                = new FakeAIProvider();
		$provider->next_response = array(
			'score'  => 77,
			'_usage' => array( 'input_tokens' => 10, 'output_tokens' => 10 ),
		);
		$scorer = new SWPS_AEO_Coverage_Scorer( $provider );
		$this->assertSame( 77, $scorer->score( 'Title', '<h2>A</h2>' )['score'] );
	}
}

/**
 * Test double that records track() calls instead of touching WordPress options.
 */
final class RecordingCostTracker extends SWPS_Cost_Tracker {
	/** @var array<int, array{0:string,1:int,2:int,3:int}> */
	public array $calls = array();

	public function track( string $model, int $input_tokens, int $output_tokens, int $post_id = 0 ): void {
		$this->calls[] = array( $model, $input_tokens, $output_tokens, $post_id );
	}
}
