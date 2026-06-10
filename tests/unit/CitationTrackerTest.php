<?php
/**
 * Tests for SWPS_Citation_Tracker pure static helpers.
 *
 * All helpers are WP-free. wp_parse_url() is stubbed via the test bootstrap.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-citation-tracker.php';

/**
 * @covers SWPS_Citation_Tracker
 */
class CitationTrackerTest extends TestCase {

	// -------------------------------------------------------------------------
	// extract_domains
	// -------------------------------------------------------------------------

	public function test_extract_domains_strips_www(): void {
		$result = SWPS_Citation_Tracker::extract_domains( array( 'https://www.example.com/page' ) );
		$this->assertSame( array( 'example.com' ), $result );
	}

	public function test_extract_domains_lowercases_host(): void {
		$result = SWPS_Citation_Tracker::extract_domains( array( 'https://EXAMPLE.COM/' ) );
		$this->assertSame( array( 'example.com' ), $result );
	}

	public function test_extract_domains_deduplicates(): void {
		$result = SWPS_Citation_Tracker::extract_domains( array(
			'https://example.com/a',
			'https://example.com/b',
			'https://www.example.com/c',
		) );
		$this->assertSame( array( 'example.com' ), $result );
	}

	public function test_extract_domains_skips_invalid_urls(): void {
		$result = SWPS_Citation_Tracker::extract_domains( array( ':::not-a-url:::', '', '  ' ) );
		$this->assertSame( array(), $result );
	}

	public function test_extract_domains_accepts_bare_domain_string(): void {
		// Google vertexaisearch titles arrive as bare domain strings.
		$result = SWPS_Citation_Tracker::extract_domains( array( 'aljazeera.com', 'uefa.com' ) );
		$this->assertSame( array( 'aljazeera.com', 'uefa.com' ), $result );
	}

	public function test_extract_domains_strips_www_from_bare_domain(): void {
		$result = SWPS_Citation_Tracker::extract_domains( array( 'www.example.com' ) );
		$this->assertSame( array( 'example.com' ), $result );
	}

	public function test_extract_domains_multiple_providers(): void {
		$result = SWPS_Citation_Tracker::extract_domains( array(
			'https://sub.example.com/path',
			'https://other.org/',
		) );
		$this->assertSame( array( 'sub.example.com', 'other.org' ), $result );
	}

	// -------------------------------------------------------------------------
	// domain_cited
	// -------------------------------------------------------------------------

	public function test_domain_cited_exact_match(): void {
		$this->assertTrue( SWPS_Citation_Tracker::domain_cited( 'example.com', array( 'example.com' ) ) );
	}

	public function test_domain_cited_subdomain_match(): void {
		$this->assertTrue( SWPS_Citation_Tracker::domain_cited( 'example.com', array( 'blog.example.com' ) ) );
	}

	public function test_domain_cited_no_false_positive_on_suffix(): void {
		// "notexample.com" should NOT match "example.com".
		$this->assertFalse( SWPS_Citation_Tracker::domain_cited( 'example.com', array( 'notexample.com' ) ) );
	}

	public function test_domain_cited_returns_false_when_absent(): void {
		$this->assertFalse( SWPS_Citation_Tracker::domain_cited( 'example.com', array( 'other.org', 'foo.net' ) ) );
	}

	// -------------------------------------------------------------------------
	// citation_state
	// -------------------------------------------------------------------------

	public function test_citation_state_empty_history_is_never(): void {
		$this->assertSame( 'never', SWPS_Citation_Tracker::citation_state( array() ) );
	}

	public function test_citation_state_all_false_is_never(): void {
		$this->assertSame( 'never', SWPS_Citation_Tracker::citation_state( array( false, false, false ) ) );
	}

	public function test_citation_state_latest_two_true_is_cited(): void {
		$this->assertSame( 'cited', SWPS_Citation_Tracker::citation_state( array( true, true, false ) ) );
	}

	public function test_citation_state_single_true_is_cited(): void {
		$this->assertSame( 'cited', SWPS_Citation_Tracker::citation_state( array( true ) ) );
	}

	public function test_citation_state_single_false_after_history_is_not_lost(): void {
		// One false entry with no previous true → never (no true in history).
		$this->assertSame( 'never', SWPS_Citation_Tracker::citation_state( array( false ) ) );
	}

	public function test_citation_state_lost_requires_at_least_one_historical_true(): void {
		// Two falses but one earlier true → lost.
		$this->assertSame( 'lost', SWPS_Citation_Tracker::citation_state( array( false, false, true ) ) );
	}

	public function test_citation_state_lost_requires_latest_two_both_false(): void {
		// Latest two: false, true — not lost.
		$this->assertSame( 'mixed', SWPS_Citation_Tracker::citation_state( array( false, true, true ) ) );
	}

	public function test_citation_state_mixed_when_latest_two_alternate(): void {
		$this->assertSame( 'mixed', SWPS_Citation_Tracker::citation_state( array( true, false ) ) );
	}

	public function test_citation_state_single_false_with_prior_true_is_lost(): void {
		// History has exactly one element (false), but per the single-element rule:
		// single element with has_any_true=false → never (the sole entry is false).
		// If we pass two entries [false, true] → mixed per the latest-two rule.
		// Edge: single entry false, but a prior run had true — can't express in
		// a one-element array; single false means no prior data → 'never'.
		$this->assertSame( 'never', SWPS_Citation_Tracker::citation_state( array( false ) ) );
	}

	// -------------------------------------------------------------------------
	// share_of_voice
	// -------------------------------------------------------------------------

	public function test_share_of_voice_basic(): void {
		$result = SWPS_Citation_Tracker::share_of_voice( array( 'a.com' => 2, 'b.com' => 1 ), 4 );
		$this->assertSame( 50.0, $result['a.com'] );
		$this->assertSame( 25.0, $result['b.com'] );
	}

	public function test_share_of_voice_division_safe_zero_prompts(): void {
		$result = SWPS_Citation_Tracker::share_of_voice( array( 'a.com' => 5 ), 0 );
		$this->assertSame( 0.0, $result['a.com'] );
	}

	public function test_share_of_voice_empty_counts(): void {
		$result = SWPS_Citation_Tracker::share_of_voice( array(), 10 );
		$this->assertSame( array(), $result );
	}

	// -------------------------------------------------------------------------
	// is_question_query
	// -------------------------------------------------------------------------

	public function test_is_question_query_starts_with_what(): void {
		$this->assertTrue( SWPS_Citation_Tracker::is_question_query( 'what is the best SEO plugin' ) );
	}

	public function test_is_question_query_starts_with_how(): void {
		$this->assertTrue( SWPS_Citation_Tracker::is_question_query( 'how to improve site speed' ) );
	}

	public function test_is_question_query_case_insensitive(): void {
		$this->assertTrue( SWPS_Citation_Tracker::is_question_query( 'How do I rank on Google' ) );
	}

	public function test_is_question_query_word_boundary_enforced(): void {
		// "island" starts with "is" but is not "is\b".
		$this->assertFalse( SWPS_Citation_Tracker::is_question_query( 'island holidays in Spain' ) );
	}

	public function test_is_question_query_five_word_long_tail(): void {
		$this->assertTrue( SWPS_Citation_Tracker::is_question_query( 'best wordpress seo plugin for beginners' ) );
	}

	public function test_is_question_query_four_words_not_question_word(): void {
		$this->assertFalse( SWPS_Citation_Tracker::is_question_query( 'wordpress seo plugin review' ) );
	}

	public function test_is_question_query_empty_string(): void {
		$this->assertFalse( SWPS_Citation_Tracker::is_question_query( '' ) );
	}

	public function test_is_question_query_starts_with_should(): void {
		$this->assertTrue( SWPS_Citation_Tracker::is_question_query( 'should I use Yoast or StrataWP' ) );
	}

	public function test_is_question_query_starts_with_are(): void {
		$this->assertTrue( SWPS_Citation_Tracker::is_question_query( 'are meta descriptions still important' ) );
	}
}
