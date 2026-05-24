<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-extractability-scorer.php';
require_once __DIR__ . '/../../includes/aeo/class-markup-scorer.php';
require_once __DIR__ . '/../../includes/aeo/class-authority-scorer.php';
require_once __DIR__ . '/../../includes/aeo/class-coverage-scorer.php';
require_once __DIR__ . '/../../includes/class-aeo-scorer.php';

final class AeoScorerTest extends TestCase {

	private function make_scorer(): SWPS_AEO_Scorer {
		return new SWPS_AEO_Scorer(
			new SWPS_AEO_Extractability_Scorer(),
			new SWPS_AEO_Markup_Scorer(),
			new SWPS_AEO_Authority_Scorer(),
			new SWPS_AEO_Coverage_Scorer()
		);
	}

	public function test_orchestrator_returns_total_and_subscores(): void {
		$scorer = $this->make_scorer();
		$html   = '<p>Sourdough is a naturally leavened bread.</p>';
		$result = $scorer->score_html( $html, array(
			'title'           => 'Sourdough basics',
			'author'          => 'Jon Imms',
			'published_unix'  => time(),
			'modified_unix'   => time(),
			'word_count'      => 8,
			'existing_schema' => null,
			'coverage_cached' => 60,
		) );

		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'subscores', $result );
		$this->assertArrayHasKey( 'extractability', $result['subscores'] );
		$this->assertArrayHasKey( 'markup',         $result['subscores'] );
		$this->assertArrayHasKey( 'authority',      $result['subscores'] );
		$this->assertArrayHasKey( 'coverage',       $result['subscores'] );

		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 0, $result['total'] );
		$this->assertLessThanOrEqual( 100, $result['total'] );
	}

	public function test_weights_redistribute_when_coverage_missing(): void {
		$scorer = $this->make_scorer();
		$html   = '<p>Sourdough is a naturally leavened bread.</p>';
		$ctx    = array(
			'title'           => 't',
			'author'          => 'Jon',
			'published_unix'  => time(),
			'modified_unix'   => time(),
			'word_count'      => 8,
			'existing_schema' => null,
			'coverage_cached' => null,
		);

		$result = $scorer->score_html( $html, $ctx );
		$this->assertNull( $result['subscores']['coverage'] );
		$this->assertIsInt( $result['total'] );
		// Total should still be in 0-100 range despite null coverage.
		$this->assertGreaterThanOrEqual( 0, $result['total'] );
		$this->assertLessThanOrEqual( 100, $result['total'] );
	}

	public function test_custom_weights_passed_in(): void {
		$scorer = $this->make_scorer();
		$ctx    = array(
			'title'           => 't',
			'author'          => '',
			'published_unix'  => 0,
			'modified_unix'   => 0,
			'word_count'      => 2,
			'existing_schema' => null,
			'coverage_cached' => 80,
		);

		// 100% weight on Coverage → total should equal Coverage sub-score (80).
		$r_coverage_only = $scorer->score_html( '<p>Test content.</p>', $ctx, array(
			'extractability' => 0,
			'markup'         => 0,
			'authority'      => 0,
			'coverage'       => 1.0,
		) );
		$this->assertSame( 80, $r_coverage_only['total'] );

		// 100% weight on Extractability → total should equal Extractability sub-score.
		$r_ext_only = $scorer->score_html( '<p>Test content with enough words to score reasonably.</p>', $ctx, array(
			'extractability' => 1.0,
			'markup'         => 0,
			'authority'      => 0,
			'coverage'       => 0,
		) );
		$this->assertSame( $r_ext_only['subscores']['extractability'], $r_ext_only['total'] );
	}
}
