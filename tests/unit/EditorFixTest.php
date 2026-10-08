<?php
/**
 * Tests for the pure fix prompt and validation logic.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/editor/class-editor-text.php';
require_once __DIR__ . '/../../includes/editor/class-editor-fix.php';

final class EditorFixTest extends TestCase {

	private const ORIGINAL = 'Cold coffee is easy to make at home with a few simple tools and patience.';

	/**
	 * @return array<string,string>
	 */
	private function ctx(): array {
		return array(
			'title'            => 'Brewing basics',
			'meta_title'       => '',
			'meta_description' => 'Short.',
			'target_text'      => self::ORIGINAL,
			'question'         => 'How long does cold brew keep?',
			'lang'             => 'en',
		);
	}

	public function test_supported_checks_map_to_kinds(): void {
		$this->assertTrue( SWPS_Editor_Fix::supports( 'kw_in_title' ) );
		$this->assertFalse( SWPS_Editor_Fix::supports( 'kw_density' ) );
		$this->assertSame( 'meta_title', SWPS_Editor_Fix::kind( 'kw_in_title' ) );
		$this->assertSame( 'meta_description', SWPS_Editor_Fix::kind( 'kw_in_description' ) );
		$this->assertSame( 'paragraph', SWPS_Editor_Fix::kind( 'kw_in_intro' ) );
		$this->assertSame( 'insert', SWPS_Editor_Fix::kind( 'aeo_answer' ) );
		$this->assertSame( 'first', SWPS_Editor_Fix::which( 'kw_in_intro' ) );
		$this->assertSame( 'last', SWPS_Editor_Fix::which( 'kw_in_conclusion' ) );
	}

	public function test_valid_paragraph_proposal_is_accepted(): void {
		$res = SWPS_Editor_Fix::validate(
			'kw_in_intro',
			'cold brew coffee',
			$this->ctx(),
			array( 'value' => 'Cold brew coffee is easy to make at home with a few simple tools and some patience.' )
		);

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'paragraph', $res['proposal']['kind'] );
		$this->assertSame( self::ORIGINAL, $res['proposal']['original'] );
	}

	public function test_proposal_without_the_keyword_is_rejected(): void {
		$res = SWPS_Editor_Fix::validate( 'kw_in_intro', 'cold brew coffee', $this->ctx(), array( 'value' => 'Iced coffee is easy to make at home with a few simple tools and patience.' ) );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'swps_fix_keyword', $res['code'] );
	}

	public function test_noop_proposal_is_rejected(): void {
		$res = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), array( 'value' => self::ORIGINAL ) );

		$this->assertSame( 'swps_fix_noop', $res['code'] );
	}

	public function test_empty_or_malformed_responses_are_rejected(): void {
		foreach ( array( null, array(), array( 'value' => '' ), array( 'value' => array( 'x' ) ), 'text' ) as $bad ) {
			$res = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), $bad );
			$this->assertFalse( $res['ok'] );
			$this->assertSame( 'swps_fix_empty', $res['code'] );
		}
	}

	public function test_unsafe_markup_is_rejected(): void {
		$script = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), array( 'value' => 'Coffee <script>alert(1)</script> at home with tools and patience for you.' ) );
		$div    = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), array( 'value' => '<div>Coffee at home with a few simple tools and patience for everyone.</div>' ) );

		$this->assertSame( 'swps_fix_unsafe', $script['code'] );
		$this->assertSame( 'swps_fix_unsafe', $div['code'] );
	}

	public function test_paragraph_length_must_stay_close_to_the_original(): void {
		$short = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), array( 'value' => 'Coffee at home.' ) );
		$long  = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), array( 'value' => 'Coffee ' . str_repeat( 'word ', 60 ) ) );

		$this->assertSame( 'swps_fix_length', $short['code'] );
		$this->assertSame( 'swps_fix_length', $long['code'] );
	}

	public function test_meta_title_limits(): void {
		$ok   = SWPS_Editor_Fix::validate( 'kw_in_title', 'cold brew', $this->ctx(), array( 'value' => 'Cold brew basics for beginners' ) );
		$long = SWPS_Editor_Fix::validate( 'kw_in_title', 'cold brew', $this->ctx(), array( 'value' => 'Cold brew ' . str_repeat( 'x', 80 ) ) );

		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'Cold brew basics for beginners', $ok['proposal']['value'] );
		$this->assertSame( 'Brewing basics', $ok['proposal']['original'] );
		$this->assertSame( 'swps_fix_length', $long['code'] );
	}

	public function test_meta_description_limits(): void {
		$short = SWPS_Editor_Fix::validate( 'kw_in_description', 'cold brew', $this->ctx(), array( 'value' => 'Cold brew tips.' ) );
		$ok    = SWPS_Editor_Fix::validate(
			'kw_in_description',
			'cold brew',
			$this->ctx(),
			array( 'value' => 'Learn how cold brew works, which grind to use and how long to steep it for a smooth cup every morning.' )
		);

		$this->assertSame( 'swps_fix_length', $short['code'] );
		$this->assertTrue( $ok['ok'] );
	}

	public function test_insert_answers_must_be_short_and_need_no_keyword(): void {
		$ok   = SWPS_Editor_Fix::validate( 'aeo_answer', '', $this->ctx(), array( 'value' => 'Cold brew keeps for up to two weeks in a sealed jar in the fridge.' ) );
		$long = SWPS_Editor_Fix::validate( 'aeo_answer', '', $this->ctx(), array( 'value' => str_repeat( 'word ', 120 ) ) );

		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'insert', $ok['proposal']['kind'] );
		$this->assertSame( 'swps_fix_length', $long['code'] );
	}

	public function test_target_must_match_the_current_first_or_last_paragraph(): void {
		$html = '<p>First paragraph here.</p><h2>Heading</h2><p>Middle.</p><p>Last paragraph here.</p>';

		$this->assertTrue( SWPS_Editor_Fix::target_matches( $html, 'kw_in_intro', 'First paragraph here.' ) );
		$this->assertTrue( SWPS_Editor_Fix::target_matches( $html, 'kw_in_conclusion', 'Last paragraph here.' ) );
		$this->assertFalse( SWPS_Editor_Fix::target_matches( $html, 'kw_in_intro', 'An older first paragraph.' ) );
		$this->assertFalse( SWPS_Editor_Fix::target_matches( '', 'kw_in_intro', 'Anything' ) );
	}

	public function test_prompts_name_the_keyword_and_demand_json(): void {
		$p = SWPS_Editor_Fix::build_prompt( 'kw_in_intro', 'cold brew coffee', $this->ctx() );

		$this->assertStringContainsString( '"value"', $p['system'] );
		$this->assertStringContainsString( 'cold brew coffee', $p['user'] );
		$this->assertStringContainsString( self::ORIGINAL, $p['user'] );

		$a = SWPS_Editor_Fix::build_prompt( 'aeo_answer', 'cold brew coffee', $this->ctx() );
		$this->assertStringContainsString( 'How long does cold brew keep?', $a['user'] );
	}
}
