<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-coverage-scorer.php';
require_once __DIR__ . '/FakeAIProvider.php';

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
}
