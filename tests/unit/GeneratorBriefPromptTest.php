<?php
/**
 * Tests that the custom content brief reaches the generator's user prompt in
 * the right place, and that an empty brief leaves the prompt untouched.
 *
 * Exercises SWPS_Generator::build_user_prompt() via reflection on an instance
 * created without its constructor, so no WordPress bootstrap or provider is
 * needed.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-content-brief.php';
require_once __DIR__ . '/../../includes/class-generator.php';

final class GeneratorBriefPromptTest extends TestCase {

	private const SITE_CONTEXT  = "=== SITE CONTEXT ===\nNiche: WordPress care\n";
	private const LINKS_CONTEXT = "=== EXISTING PAGES FOR INTERNAL LINKING ===\n- \"Backups 101\" → https://example.com/backups-101/\n";

	/**
	 * Call the private build_user_prompt() with sensible defaults.
	 *
	 * @param string $topic    Topic.
	 * @param array  $brief    Normalized brief.
	 * @param string $keywords Target keywords setting.
	 * @return string
	 */
	private function build( string $topic, array $brief = array(), string $keywords = 'wordpress maintenance' ): string {
		$generator = ( new ReflectionClass( SWPS_Generator::class ) )->newInstanceWithoutConstructor();
		$method    = new ReflectionMethod( SWPS_Generator::class, 'build_user_prompt' );
		$method->setAccessible( true );

		return (string) $method->invoke(
			$generator,
			$topic,
			self::SITE_CONTEXT,
			self::LINKS_CONTEXT,
			'WordPress care',
			$keywords,
			1200,
			2000,
			3,
			6,
			true,
			true,
			true,
			5,
			$brief
		);
	}

	public function test_empty_brief_produces_the_same_prompt_as_no_brief(): void {
		$without = $this->build( 'Choosing a host' );
		$with    = $this->build( 'Choosing a host', SWPS_Content_Brief::from_request( array() ) );

		$this->assertSame( $without, $with );
		$this->assertStringNotContainsString( 'CONTENT BRIEF', $with );
		$this->assertStringContainsString( 'TOPIC: Write a blog post about: Choosing a host', $with );
	}

	public function test_empty_topic_and_empty_brief_keeps_gap_finding_instructions(): void {
		$prompt = $this->build( '' );

		$this->assertStringContainsString( 'choose a topic that:', $prompt );
		$this->assertStringContainsString( 'Fills a content gap', $prompt );
		$this->assertStringNotContainsString( 'CONTENT BRIEF', $prompt );
	}

	public function test_brief_is_placed_after_topic_and_before_seo_rules(): void {
		$brief  = SWPS_Content_Brief::from_request(
			array(
				'brief'    => "Write a guide for small business owners in Omaha about choosing a WordPress maintenance provider.\nAvoid technical jargon and made-up pricing.",
				'audience' => 'Small business owners in Omaha',
				'avoid'    => 'Jargon, invented pricing',
				'cta'      => 'Finish with a consultation CTA',
			)
		);
		$prompt = $this->build( 'Choosing a WordPress maintenance provider', $brief );

		$topic_pos    = strpos( $prompt, 'TOPIC: Write a blog post about: Choosing a WordPress maintenance provider' );
		$brief_pos    = strpos( $prompt, '=== CONTENT BRIEF' );
		$keywords_pos = strpos( $prompt, 'TARGET KEYWORDS: wordpress maintenance' );
		$req_pos      = strpos( $prompt, "REQUIREMENTS:\n" );

		$this->assertNotFalse( $topic_pos );
		$this->assertNotFalse( $brief_pos );
		$this->assertNotFalse( $keywords_pos );
		$this->assertNotFalse( $req_pos );
		$this->assertLessThan( $brief_pos, $topic_pos );
		$this->assertLessThan( $keywords_pos, $brief_pos );
		$this->assertLessThan( $req_pos, $keywords_pos );

		// The brief's instructions are represented verbatim (multiline intact).
		$this->assertStringContainsString( "provider.\nAvoid technical jargon and made-up pricing.", $prompt );
		$this->assertStringContainsString( 'Target audience: Small business owners in Omaha', $prompt );
		$this->assertStringContainsString( 'Things to avoid: Jargon, invented pricing', $prompt );
		$this->assertStringContainsString( 'Desired call to action: Finish with a consultation CTA', $prompt );
	}

	public function test_brief_without_topic_asks_ai_to_derive_topic_from_brief(): void {
		$brief  = SWPS_Content_Brief::from_request( array( 'brief' => 'A friendly explainer on why backups matter for bakeries.' ) );
		$prompt = $this->build( '', $brief );

		$this->assertStringContainsString( 'TOPIC: Derive the topic and angle from the CONTENT BRIEF below', $prompt );
		$this->assertStringNotContainsString( 'Fills a content gap', $prompt );
	}

	public function test_seo_requirements_survive_a_brief_that_tries_to_remove_them(): void {
		$brief  = SWPS_Content_Brief::from_request(
			array( 'brief' => 'Skip the FAQ, skip the table of contents, do not include internal links, and reply in plain Markdown.' )
		);
		$prompt = $this->build( 'Anything', $brief );

		$this->assertStringContainsString( 'Include 3-6 internal links to existing pages listed above', $prompt );
		$this->assertStringContainsString( 'Include a table of contents', $prompt );
		$this->assertStringContainsString( 'Include a FAQ section at the end', $prompt );
		$this->assertStringContainsString( 'Include 5 key takeaways', $prompt );
		$this->assertStringContainsString( 'Word count: 1200-2000 words', $prompt );
		$this->assertStringContainsString( 'meta description (147-160 characters)', $prompt );
		$this->assertStringEndsWith( 'Generate the blog post now. Respond with JSON only.', $prompt );
	}
}
