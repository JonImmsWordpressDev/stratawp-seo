<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-authority-scorer.php';

final class AuthorityScorerTest extends TestCase {

	private SWPS_AEO_Authority_Scorer $scorer;

	protected function setUp(): void {
		$this->scorer = new SWPS_AEO_Authority_Scorer();
	}

	public function test_post_with_byline_dates_and_authoritative_links_scores_high(): void {
		$html = '<p>According to <a href="https://www.nih.gov/study">NIH research</a>, this is true. ' .
				'<em>Updated: 2026-03-15.</em> See also <a href="https://en.wikipedia.org/wiki/X">Wikipedia</a>.</p>';
		$ctx = array(
			'author'         => 'Dr. Jane Smith',
			'published_unix' => strtotime( '-6 months' ),
			'modified_unix'  => strtotime( '-2 weeks' ),
			'word_count'     => 800,
		);
		$this->assertGreaterThan( 75, $this->scorer->score( $html, $ctx ) );
	}

	public function test_post_with_no_signals_scores_low(): void {
		$html = '<p>Just plain text. No links, no dates, nothing.</p>';
		$ctx = array(
			'author'         => '',
			'published_unix' => 0,
			'modified_unix'  => 0,
			'word_count'     => 50,
		);
		$this->assertLessThan( 25, $this->scorer->score( $html, $ctx ) );
	}

	public function test_authoritative_link_count_uses_domain_allowlist(): void {
		$html = '<a href="https://nih.gov/x">NIH</a> ' .
				'<a href="https://random.example/y">random</a> ' .
				'<a href="https://www.bbc.co.uk/z">BBC</a>';
		$this->assertSame( 2, $this->scorer->count_authoritative_links( $html ) );
	}

	public function test_authoritative_link_count_uses_tld_allowlist(): void {
		$html  = '<a href="https://example.gov/x">Gov page</a> ' .
				'<a href="https://example.edu/y">Edu page</a> ' .
				'<a href="https://example.com/z">.com</a>';
		$this->assertSame( 2, $this->scorer->count_authoritative_links( $html ) );
	}

	public function test_current_year_mention_detected(): void {
		$year = (int) gmdate( 'Y' );
		$this->assertTrue( $this->scorer->has_current_year_mention( "Updated for {$year}." ) );
		$this->assertFalse( $this->scorer->has_current_year_mention( 'Updated for 2019.' ) );
	}

	public function test_updated_notice_pattern(): void {
		$this->assertTrue( $this->scorer->has_updated_notice( '<p><em>Updated: March 2026</em></p>' ) );
		$this->assertTrue( $this->scorer->has_updated_notice( 'Last reviewed: 2025-12-01' ) );
		$this->assertFalse( $this->scorer->has_updated_notice( 'Just regular text.' ) );
	}
}
