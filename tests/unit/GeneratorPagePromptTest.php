<?php
/**
 * Tests the page variant of the generator's user prompt and the placement of
 * the source-material block, via reflection on build_user_prompt().
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-content-brief.php';
require_once __DIR__ . '/../../includes/class-source-material.php';
require_once __DIR__ . '/../../includes/class-templates.php';
require_once __DIR__ . '/../../includes/class-generator.php';

final class GeneratorPagePromptTest extends TestCase {

	private const SITE_CONTEXT  = "=== SITE CONTEXT ===\nNiche: WordPress care\n";
	private const LINKS_CONTEXT = "=== EXISTING PAGES FOR INTERNAL LINKING ===\n- \"Backups 101\" → https://example.com/backups-101/\n";

	/**
	 * Call the private build_user_prompt() with sensible defaults.
	 *
	 * @param string $topic        Topic.
	 * @param string $content_type post|page.
	 * @param array  $brief        Normalized brief.
	 * @param string $sources      Rendered source block.
	 * @param bool   $include_faq  FAQ flag (pages take this from the template).
	 * @return string
	 */
	private function build( string $topic, string $content_type, array $brief = array(), string $sources = '', bool $include_faq = true ): string {
		$generator = ( new ReflectionClass( SWPS_Generator::class ) )->newInstanceWithoutConstructor();
		$method    = new ReflectionMethod( SWPS_Generator::class, 'build_user_prompt' );
		$method->setAccessible( true );

		return (string) $method->invoke(
			$generator,
			$topic,
			self::SITE_CONTEXT,
			self::LINKS_CONTEXT,
			'WordPress care',
			'wordpress maintenance',
			800,
			1400,
			3,
			6,
			$include_faq,
			true,   // include_toc — pages must ignore this.
			true,   // include_takeaways — pages must ignore this.
			5,
			$brief,
			$content_type,
			$sources
		);
	}

	public function test_post_prompt_is_identical_with_the_new_defaults(): void {
		$generator = ( new ReflectionClass( SWPS_Generator::class ) )->newInstanceWithoutConstructor();
		$method    = new ReflectionMethod( SWPS_Generator::class, 'build_user_prompt' );
		$method->setAccessible( true );

		$legacy = (string) $method->invoke( $generator, 'Topic', self::SITE_CONTEXT, self::LINKS_CONTEXT, 'WordPress care', 'wordpress maintenance', 800, 1400, 3, 6, true, true, true, 5, array() );
		$now    = $this->build( 'Topic', 'post' );

		$this->assertSame( $legacy, $now );
		$this->assertStringContainsString( 'TOPIC: Write a blog post about: Topic', $now );
	}

	public function test_page_prompt_has_page_topic_line_and_no_post_only_requirements(): void {
		$prompt = $this->build( 'WordPress maintenance in Omaha', 'page', array(), '', true );

		$this->assertStringContainsString( 'TOPIC: Write a website page about: WordPress maintenance in Omaha', $prompt );
		$this->assertStringNotContainsString( 'blog post', $prompt );
		$this->assertStringNotContainsString( 'table of contents', $prompt );
		$this->assertStringNotContainsString( 'key takeaways', $prompt );
		$this->assertStringNotContainsString( 'Suggest the best existing category', $prompt );
		$this->assertStringContainsString( 'Word count: 800-1400 words', $prompt );
		$this->assertStringContainsString( 'Include 3-6 internal links to existing pages listed above', $prompt );
		$this->assertStringContainsString( 'Include a FAQ section at the end', $prompt );
		$this->assertStringContainsString( 'External links: only where genuinely useful to the visitor, at most 2', $prompt );
		$this->assertStringEndsWith( 'Generate the page now. Respond with JSON only.', $prompt );
	}

	public function test_page_prompt_omits_faq_when_template_says_so(): void {
		$prompt = $this->build( 'About us', 'page', array(), '', false );

		$this->assertStringNotContainsString( 'FAQ section', $prompt );
	}

	public function test_page_without_topic_derives_from_brief_or_asks_for_a_page_topic(): void {
		$brief = SWPS_Content_Brief::from_request( array( 'brief' => 'A page about our care plans for bakeries.' ) );

		$from_brief = $this->build( '', 'page', $brief );
		$this->assertStringContainsString( 'TOPIC: Derive the page subject and angle from the CONTENT BRIEF below', $from_brief );

		$bare = $this->build( '', 'page' );
		$this->assertStringContainsString( 'TOPIC: Based on the site\'s niche (WordPress care) and existing content above, choose a page the site is missing', $bare );
		$this->assertStringNotContainsString( 'Fills a content gap', $bare );
	}

	public function test_sources_block_sits_after_brief_and_before_keywords_and_changes_external_link_rule(): void {
		$brief   = SWPS_Content_Brief::from_request( array( 'brief' => 'Explain backups.' ) );
		$sources = SWPS_Source_Material::to_prompt_block(
			array(
				array(
					'url'   => 'https://example.com/backups',
					'ok'    => true,
					'title' => 'Backups',
					'text'  => 'Backups matter.',
					'error' => '',
				),
			),
			''
		);

		foreach ( array( 'post', 'page' ) as $type ) {
			$prompt = $this->build( 'Backups', $type, $brief, $sources );

			$brief_pos    = strpos( $prompt, '=== CONTENT BRIEF' );
			$sources_pos  = strpos( $prompt, '=== SOURCE MATERIAL' );
			$keywords_pos = strpos( $prompt, 'TARGET KEYWORDS:' );

			$this->assertNotFalse( $brief_pos, $type );
			$this->assertNotFalse( $sources_pos, $type );
			$this->assertNotFalse( $keywords_pos, $type );
			$this->assertLessThan( $sources_pos, $brief_pos, $type );
			$this->assertLessThan( $keywords_pos, $sources_pos, $type );
			$this->assertStringContainsString( 'Cite the supplied SOURCE MATERIAL URLs as the external links', $prompt, $type );
			$this->assertStringNotContainsString( 'Include 2-4 external links to authoritative sources', $prompt, $type );
		}
	}

	public function test_post_without_sources_keeps_the_original_external_link_rule(): void {
		$prompt = $this->build( 'Backups', 'post' );

		$this->assertStringContainsString( 'Include 2-4 external links to authoritative sources', $prompt );
		$this->assertStringNotContainsString( 'SOURCE MATERIAL', $prompt );
	}
}
