<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-extractability-scorer.php';

final class ExtractabilityScorerTest extends TestCase {

	private SWPS_AEO_Extractability_Scorer $scorer;

	protected function setUp(): void {
		$this->scorer = new SWPS_AEO_Extractability_Scorer();
	}

	public function test_high_quality_content_scores_above_75(): void {
		$html  = file_get_contents( __DIR__ . '/../fixtures/aeo/extractable-high.html' );
		$score = $this->scorer->score( $html );
		$this->assertGreaterThanOrEqual( 75, $score,
			"High-extractability fixture scored {$score}, expected >= 75" );
	}

	public function test_low_quality_content_scores_below_40(): void {
		$html  = file_get_contents( __DIR__ . '/../fixtures/aeo/extractable-low.html' );
		$score = $this->scorer->score( $html );
		$this->assertLessThan( 40, $score,
			"Low-extractability fixture scored {$score}, expected < 40" );
	}

	public function test_empty_content_returns_zero(): void {
		$this->assertSame( 0, $this->scorer->score( '' ) );
	}

	public function test_definitional_lead_pattern_detected(): void {
		$html = '<p>Sourdough is a naturally leavened bread.</p>';
		$this->assertTrue( $this->scorer->has_definitional_lead( $html ) );

		$html2 = '<p>So I was thinking about bread.</p>';
		$this->assertFalse( $this->scorer->has_definitional_lead( $html2 ) );
	}

	public function test_declarative_ratio_calculation(): void {
		// 3 declarative, 1 question.
		$html  = '<p>Sourdough is bread. Flour matters. Hydration is key. Why does it taste sour?</p>';
		$ratio = $this->scorer->declarative_ratio( $html );
		$this->assertEqualsWithDelta( 0.75, $ratio, 0.01 );
	}

	public function test_structural_density_counts_lists_and_tables(): void {
		$html    = '<ul><li>a</li></ul><ol><li>b</li></ol><table><tr><td>c</td></tr></table>';
		$density = $this->scorer->structural_density( $html, 100 );
		// 3 structural elements per 100 words = 30 per 1000 words.
		$this->assertEqualsWithDelta( 30.0, $density, 0.01 );
	}

	public function test_bad_openers_with_punctuation_reduce_self_contained_rate(): void {
		// All 3 paragraphs open with bad words followed by punctuation/contraction.
		$html = '<p>So, I think this works.</p>' .
				'<p>We\'ve been trying this for a while.</p>' .
				'<p>Anyway, here is what happened.</p>';
		$this->assertSame( 0.0, $this->scorer->self_contained_paragraph_rate( $html ) );
	}

	public function test_good_openers_pass_self_contained(): void {
		$html = '<p>Sourdough is naturally leavened bread.</p>' .
				'<p>Bread flour produces better gluten.</p>';
		$this->assertSame( 1.0, $this->scorer->self_contained_paragraph_rate( $html ) );
	}

	public function test_empty_paragraph_does_not_inflate_denominator(): void {
		// 1 empty + 1 good = rate of 1.0, not 0.5.
		$html = '<p></p><p>Sourdough is bread.</p>';
		$this->assertSame( 1.0, $this->scorer->self_contained_paragraph_rate( $html ) );
	}
}
