<?php
/**
 * Tests the brand mention mode (full, limited, none): the prompt line each
 * mode adds, that full leaves the prompt byte-identical to the pre-option
 * prompt, that pages are unaffected, and the site-default fallback.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
	}
}

require_once __DIR__ . '/../../includes/class-content-brief.php';
require_once __DIR__ . '/../../includes/class-source-material.php';
require_once __DIR__ . '/../../includes/class-templates.php';
require_once __DIR__ . '/../../includes/class-generator.php';

final class GeneratorBrandMentionsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['swps_test_options'] );
	}

	/**
	 * Call the private build_user_prompt(). With no $mode, the call has
	 * exactly the arguments callers passed before the option existed.
	 *
	 * @param string|null $mode         Brand mention mode, or null to omit the argument.
	 * @param string      $content_type post|page.
	 * @return string
	 */
	private function build( ?string $mode = null, string $content_type = SWPS_Templates::TYPE_POST ): string {
		$generator = ( new ReflectionClass( SWPS_Generator::class ) )->newInstanceWithoutConstructor();
		$method    = new ReflectionMethod( SWPS_Generator::class, 'build_user_prompt' );
		$method->setAccessible( true );

		$args = array(
			'Choosing a host',
			"=== SITE CONTEXT ===\n",
			"=== EXISTING PAGES FOR INTERNAL LINKING ===\n",
			'WordPress care',
			'wordpress hosting',
			1200,
			2000,
			3,
			6,
			true,
			true,
			true,
			5,
			array(),
			$content_type,
			'',
		);
		if ( null !== $mode ) {
			$args[] = $mode;
		}

		return (string) $method->invokeArgs( $generator, $args );
	}

	public function test_full_is_byte_identical_to_the_prompt_without_the_option(): void {
		$before = $this->build();
		$full   = $this->build( SWPS_Generator::BRAND_MENTIONS_FULL );

		$this->assertSame( $before, $full );
		$this->assertStringNotContainsString( 'Brand mentions:', $full );
		$this->assertSame( '', SWPS_Generator::brand_mentions_instruction( 'full' ) );
	}

	public function test_limited_adds_the_light_mentions_requirement(): void {
		$prompt = $this->build( SWPS_Generator::BRAND_MENTIONS_LIMITED );

		$this->assertStringContainsString( '- Brand mentions: keep the post about 95% educational', $prompt );
		$this->assertStringContainsString( 'at most 2-3 times', $prompt );
		$this->assertLessThan( strpos( $prompt, '- Use proper heading hierarchy' ), strpos( $prompt, '- Brand mentions:' ) );
		$this->assertGreaterThan( strpos( $prompt, "REQUIREMENTS:\n" ), strpos( $prompt, '- Brand mentions:' ) );
	}

	public function test_none_adds_the_no_pitch_requirement(): void {
		$prompt = $this->build( SWPS_Generator::BRAND_MENTIONS_NONE );

		$this->assertStringContainsString( '- Brand mentions: write a purely educational post', $prompt );
		$this->assertStringContainsString( 'add no sales call to action', $prompt );
		$this->assertStringNotContainsString( 'about 95% educational', $prompt );
	}

	public function test_each_mode_changes_only_the_brand_line(): void {
		$full = $this->build( 'full' );

		foreach ( array( 'limited', 'none' ) as $mode ) {
			$prompt = $this->build( $mode );
			$this->assertSame( $full, str_replace( SWPS_Generator::brand_mentions_instruction( $mode ), '', $prompt ), $mode );
		}
	}

	public function test_pages_ignore_the_mode(): void {
		$page_full = $this->build( 'full', SWPS_Templates::TYPE_PAGE );

		$this->assertSame( $page_full, $this->build( 'none', SWPS_Templates::TYPE_PAGE ) );
		$this->assertSame( $page_full, $this->build( 'limited', SWPS_Templates::TYPE_PAGE ) );
	}

	public function test_normalize_prefers_a_valid_request_value(): void {
		$GLOBALS['swps_test_options']['swps_brand_mentions'] = 'none';

		$this->assertSame( 'limited', SWPS_Generator::normalize_brand_mentions( 'limited' ) );
		$this->assertSame( 'full', SWPS_Generator::normalize_brand_mentions( 'full' ) );
	}

	public function test_normalize_falls_back_to_the_site_default_then_full(): void {
		$this->assertSame( 'full', SWPS_Generator::normalize_brand_mentions( null ) );
		$this->assertSame( 'full', SWPS_Generator::normalize_brand_mentions( 'shouty' ) );

		$GLOBALS['swps_test_options']['swps_brand_mentions'] = 'none';
		$this->assertSame( 'none', SWPS_Generator::normalize_brand_mentions( null ) );
		$this->assertSame( 'none', SWPS_Generator::normalize_brand_mentions( '' ) );
		$this->assertSame( 'none', SWPS_Generator::normalize_brand_mentions( array( 'limited' ) ) );

		$GLOBALS['swps_test_options']['swps_brand_mentions'] = 'bogus';
		$this->assertSame( 'full', SWPS_Generator::normalize_brand_mentions( null ) );
	}

	public function test_modes_list_has_exactly_the_three_slugs(): void {
		$this->assertSame( array( 'full', 'limited', 'none' ), array_keys( SWPS_Generator::brand_mention_modes() ) );
	}
}
