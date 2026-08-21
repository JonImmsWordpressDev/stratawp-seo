<?php
/**
 * Tests for the AI meta fixers' pure prompt/response helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-meta-title.php';

final class FixerMetaPromptTest extends TestCase {

	public function test_prompt_contains_constraint_and_content(): void {
		$prompt = SWPS_Fixer_Meta_Title::build_prompt(
			array(
				'kind'       => 'title',
				'page_title' => 'Hello World',
				'excerpt'    => 'A post about greetings.',
				'keyword'    => 'greetings',
				'min'        => 30,
				'max'        => 60,
				'siblings'   => array(),
			)
		);
		$this->assertStringContainsString( 'Hello World', $prompt );
		$this->assertStringContainsString( 'greetings', $prompt );
		$this->assertStringContainsString( '30', $prompt );
		$this->assertStringContainsString( '60', $prompt );
	}

	public function test_prompt_lists_siblings_for_duplicate_checks(): void {
		// Siblings are resolved text values (meta key content or title/name fallback),
		// not URLs. The AI must differentiate from these actual competing values.
		$prompt = SWPS_Fixer_Meta_Title::build_prompt(
			array(
				'kind'       => 'title',
				'page_title' => 'Best Widgets',
				'excerpt'    => '',
				'keyword'    => '',
				'min'        => 30,
				'max'        => 60,
				'siblings'   => array( 'Best Widgets — Site', 'Best Widgets Guide' ),
			)
		);
		$this->assertStringContainsString( 'Best Widgets Guide', $prompt );
		$this->assertStringContainsString( 'differ', $prompt );
	}

	public function test_normalize_response_extracts_and_trims(): void {
		$out = SWPS_Fixer_Meta_Title::normalize_response(
			array( 'title' => '  A Fine Title  ' ),
			'title',
			60
		);
		$this->assertSame( 'A Fine Title', $out );
	}

	public function test_normalize_response_rejects_missing_key(): void {
		$this->assertNull( SWPS_Fixer_Meta_Title::normalize_response( array( 'nope' => 'x' ), 'title', 60 ) );
	}

	public function test_normalize_response_hard_truncates_overlong_value(): void {
		$long = str_repeat( 'word ', 40 );
		$out  = SWPS_Fixer_Meta_Title::normalize_response( array( 'title' => $long ), 'title', 60 );
		$this->assertNotNull( $out );
		$this->assertLessThanOrEqual( 60, mb_strlen( $out ) );
	}
}
