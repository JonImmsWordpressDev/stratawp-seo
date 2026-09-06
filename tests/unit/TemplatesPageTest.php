<?php
/**
 * Tests for the page templates in SWPS_Templates and the type-aware helpers.
 *
 * No WordPress dependency — __() is stubbed by the bootstrap.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-templates.php';

final class TemplatesPageTest extends TestCase {

	public function test_post_templates_are_unchanged_by_default(): void {
		$options = SWPS_Templates::get_options();

		$this->assertSame(
			array( 'auto', 'informational', 'listicle', 'how-to', 'comparison', 'case-study', 'news', 'tutorial' ),
			array_keys( $options )
		);
		$this->assertSame( $options, SWPS_Templates::get_options( SWPS_Templates::TYPE_POST ) );
	}

	public function test_page_templates_list_and_order(): void {
		$options = SWPS_Templates::get_options( SWPS_Templates::TYPE_PAGE );

		$this->assertSame( array( 'page-auto', 'service', 'landing', 'about', 'location' ), array_keys( $options ) );
		$this->assertSame( 'Service page', $options['service'] );
	}

	public function test_page_templates_carry_word_ranges_and_faq_flags(): void {
		$expected = array(
			'page-auto' => array( 600, 1200, false ),
			'service'   => array( 800, 1400, true ),
			'landing'   => array( 600, 1100, false ),
			'about'     => array( 500, 900, false ),
			'location'  => array( 800, 1400, true ),
		);

		foreach ( $expected as $slug => [ $min, $max, $faq ] ) {
			$tpl = SWPS_Templates::get_template( $slug, SWPS_Templates::TYPE_PAGE );
			$this->assertNotNull( $tpl, $slug );
			$this->assertSame( $min, $tpl['min_words'], $slug );
			$this->assertSame( $max, $tpl['max_words'], $slug );
			$this->assertSame( $faq, $tpl['include_faq'], $slug );
			$this->assertNotSame( '', $tpl['system_modifier'], $slug );
			$this->assertNotSame( '', $tpl['user_modifier'], $slug );
		}
	}

	public function test_resolve_slug_falls_back_to_the_type_auto(): void {
		$this->assertSame( 'auto', SWPS_Templates::resolve_slug( 'nonsense', SWPS_Templates::TYPE_POST ) );
		$this->assertSame( 'auto', SWPS_Templates::resolve_slug( 'service', SWPS_Templates::TYPE_POST ) );
		$this->assertSame( 'listicle', SWPS_Templates::resolve_slug( 'listicle', SWPS_Templates::TYPE_POST ) );
		$this->assertSame( 'page-auto', SWPS_Templates::resolve_slug( 'nonsense', SWPS_Templates::TYPE_PAGE ) );
		$this->assertSame( 'page-auto', SWPS_Templates::resolve_slug( 'listicle', SWPS_Templates::TYPE_PAGE ) );
		$this->assertSame( 'page-auto', SWPS_Templates::resolve_slug( 'auto', SWPS_Templates::TYPE_PAGE ) );
		$this->assertSame( 'location', SWPS_Templates::resolve_slug( 'location', SWPS_Templates::TYPE_PAGE ) );
	}

	public function test_normalize_type_only_allows_post_and_page(): void {
		$this->assertSame( 'post', SWPS_Templates::normalize_type( 'post' ) );
		$this->assertSame( 'page', SWPS_Templates::normalize_type( 'page' ) );
		$this->assertSame( 'page', SWPS_Templates::normalize_type( ' PAGE ' ) );
		$this->assertSame( 'post', SWPS_Templates::normalize_type( 'attachment' ) );
		$this->assertSame( 'post', SWPS_Templates::normalize_type( null ) );
	}

	public function test_apply_for_pages_appends_modifiers_and_page_auto_still_guides(): void {
		[ $sys, $usr ] = SWPS_Templates::apply( 'SYS', 'USR', 'service', SWPS_Templates::TYPE_PAGE );
		$this->assertStringStartsWith( 'SYS', $sys );
		$this->assertStringContainsString( 'service page', strtolower( $sys ) );
		$this->assertStringContainsString( 'FAQ', $usr );

		[ $sys_auto, $usr_auto ] = SWPS_Templates::apply( 'SYS', 'USR', 'page-auto', SWPS_Templates::TYPE_PAGE );
		$this->assertNotSame( 'SYS', $sys_auto, 'page-auto must tell the AI to choose a page structure' );
		$this->assertStringContainsString( 'service page, landing page, about page or location page', strtolower( $sys_auto ) );
		$this->assertNotSame( 'USR', $usr_auto );
	}

	public function test_apply_for_posts_is_unchanged(): void {
		$this->assertSame( array( 'SYS', 'USR' ), SWPS_Templates::apply( 'SYS', 'USR', 'auto' ) );
		[ $sys ] = SWPS_Templates::apply( 'SYS', 'USR', 'listicle' );
		$this->assertStringContainsString( 'listicle', strtolower( $sys ) );
	}
}
