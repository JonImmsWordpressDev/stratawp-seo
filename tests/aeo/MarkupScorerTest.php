<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-markup-scorer.php';

final class MarkupScorerTest extends TestCase {

	private SWPS_AEO_Markup_Scorer $scorer;

	protected function setUp(): void {
		$this->scorer = new SWPS_AEO_Markup_Scorer();
	}

	public function test_qa_heavy_content_scores_high(): void {
		$html  = file_get_contents( __DIR__ . '/../fixtures/aeo/markup-qa-heavy.html' );
		$score = $this->scorer->score( $html, array() );
		$this->assertGreaterThan( 70, $score,
			"QA-heavy fixture scored {$score}, expected > 70" );
	}

	public function test_no_questions_scores_low(): void {
		$html  = file_get_contents( __DIR__ . '/../fixtures/aeo/markup-no-questions.html' );
		$score = $this->scorer->score( $html, array() );
		$this->assertLessThan( 35, $score,
			"No-questions fixture scored {$score}, expected < 35" );
	}

	public function test_count_questions_in_headings_and_body(): void {
		$html = '<h2>Why?</h2><p>Because.</p><p>What about now?</p><h3>How?</h3>';
		$this->assertSame( 3, $this->scorer->count_questions( $html ) );
	}

	public function test_questions_with_answers_pair_rate(): void {
		// 3 question headings; 2 immediately followed by a short answer paragraph.
		$html = '<h2>What?</h2><p>This is the answer.</p><h2>Why?</h2><p>Because reasons.</p><h2>Where?</h2>';
		$this->assertEqualsWithDelta( 0.67, $this->scorer->qa_pair_rate( $html ), 0.02 );
	}

	public function test_detect_recipe_pattern(): void {
		$html = file_get_contents( __DIR__ . '/../fixtures/aeo/markup-recipe-like.html' );
		$this->assertSame( 'recipe', $this->scorer->infer_schema_type( $html, 'My favorite cookies' ) );
	}

	public function test_detect_no_special_type_for_generic_post(): void {
		$this->assertNull( $this->scorer->infer_schema_type( '<p>Generic post.</p>', 'Generic post' ) );
	}

	public function test_existing_schema_mismatch_penalizes_score(): void {
		$html_recipe = file_get_contents( __DIR__ . '/../fixtures/aeo/markup-recipe-like.html' );
		$score_wrong = $this->scorer->score( $html_recipe, array( 'existing_schema' => 'Article', 'title' => 'My favorite cookies' ) );
		$score_right = $this->scorer->score( $html_recipe, array( 'existing_schema' => 'Recipe',  'title' => 'My favorite cookies' ) );
		$this->assertLessThan( $score_right, $score_wrong );
	}

	public function test_extract_headings_returns_heading_texts_and_body_questions(): void {
		$html = '<h2>Choosing flour</h2><p>Use bread flour.</p>' .
				'<h3>How much water?</h3><p>About 75% hydration.</p>' .
				'<p>Can you freeze the dough?</p><p>Yes, after bulk.</p>';
		$result = SWPS_AEO_Markup_Scorer::extract_headings( $html );
		$this->assertContains( 'Choosing flour', $result );
		$this->assertContains( 'How much water?', $result );
		$this->assertContains( 'Can you freeze the dough?', $result );
		$this->assertNotContains( 'Use bread flour.', $result );
	}

	public function test_extract_headings_empty_html_returns_empty(): void {
		$this->assertSame( array(), SWPS_AEO_Markup_Scorer::extract_headings( '' ) );
	}
}
