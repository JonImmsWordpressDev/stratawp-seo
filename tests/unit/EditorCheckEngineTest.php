<?php
/**
 * Tests for the pure editor check engine.
 *
 * No WordPress dependency: runs in the stub bootstrap environment.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/editor/class-editor-text.php';
require_once __DIR__ . '/../../includes/editor/class-editor-check-registry.php';
require_once __DIR__ . '/../../includes/editor/class-editor-check-engine.php';
require_once __DIR__ . '/../../includes/class-content-scorer.php';

final class EditorCheckEngineTest extends TestCase {

	private function html(): string {
		return '<!-- wp:paragraph --><p>Cold brew coffee is easier to make at home than most people expect. It takes a coarse grind, cold water and patience.</p><!-- /wp:paragraph -->'
			. '<!-- wp:heading --><h2>How to make cold brew coffee</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Add the grounds to a jar, pour in water and wait. However, the wait is the hard part. Therefore plan ahead and start the night before.</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Strain it, dilute it and enjoy. Cold brew coffee keeps for a week in the fridge.</p><!-- /wp:paragraph -->';
	}

	/**
	 * @param array<string,mixed> $over Overrides.
	 * @return array<string,mixed>
	 */
	private function input( array $over = array() ): array {
		return array_merge(
			array(
				'title'            => 'How to make cold brew coffee at home',
				'slug'             => 'cold-brew-coffee',
				'content_html'     => $this->html(),
				'meta_title'       => '',
				'meta_description' => 'Learn how to make cold brew coffee at home with a coarse grind and cold water.',
				'lang'             => 'en_US',
				'host'             => 'example.com',
				'preset'           => array( 'min_words' => 20 ),
				'keywords'         => array( array( 'keyword' => 'cold brew coffee', 'used_elsewhere' => false ) ),
			),
			$over
		);
	}

	public function test_keyword_placement_checks_pass_for_a_well_optimised_post(): void {
		$out = SWPS_Editor_Check_Engine::run( $this->input() );
		$r   = $out['keywords'][0]['results'];

		foreach ( array( 'kw_in_title', 'kw_in_description', 'kw_in_slug', 'kw_in_intro', 'kw_in_subheading', 'kw_in_conclusion', 'kw_unique' ) as $id ) {
			$this->assertSame( 'pass', $r[ $id ]['status'], $id );
		}
	}

	public function test_empty_keyword_makes_keyword_checks_na(): void {
		$out = SWPS_Editor_Check_Engine::run( $this->input( array( 'keywords' => array( array( 'keyword' => '' ) ) ) ) );

		foreach ( $out['keywords'][0]['results'] as $id => $res ) {
			$this->assertSame( 'na', $res['status'], $id );
		}
		$this->assertNotSame( 'na', $out['global']['title_length']['status'] );

		$score = SWPS_Editor_Check_Engine::score( $out );
		$this->assertSame( 'no_keyword', $score['status'] );
		$this->assertIsInt( $score['overall'] );
	}

	public function test_cjk_skips_word_based_checks(): void {
		$out = SWPS_Editor_Check_Engine::run(
			$this->input(
				array(
					'lang'         => 'ja',
					'content_html' => '<p>これはテストです。日本語の文章です。三つ目の文です。</p>',
					'keywords'     => array( array( 'keyword' => 'テスト' ) ),
				)
			)
		);

		foreach ( array( 'content_length', 'sentence_length', 'paragraph_length', 'transition_words', 'passive_voice', 'consecutive_starters', 'subheading_distribution' ) as $id ) {
			$this->assertSame( 'na', $out['global'][ $id ]['status'], $id );
		}
		$this->assertSame( 'na', $out['keywords'][0]['results']['kw_density']['status'] );
	}

	public function test_accented_keyword_matches(): void {
		$out = SWPS_Editor_Check_Engine::run(
			$this->input(
				array(
					'title'    => 'Café au lait at home',
					'slug'     => 'cafe-au-lait',
					'keywords' => array( array( 'keyword' => 'café au lait' ) ),
				)
			)
		);
		$r   = $out['keywords'][0]['results'];

		$this->assertSame( 'pass', $r['kw_in_title']['status'] );
		$this->assertSame( 'pass', $r['kw_in_slug']['status'] );
	}

	public function test_block_comments_and_shortcodes_are_ignored(): void {
		$html = '<!-- wp:paragraph {"className":"cold brew"} --><p>Hello [gallery ids="1,2"] world</p><!-- /wp:paragraph -->';

		$this->assertSame( 'Hello world', SWPS_Editor_Text::plain( $html ) );
		$this->assertCount( 2, SWPS_Editor_Text::words( SWPS_Editor_Text::plain( $html ) ) );
	}

	public function test_density_is_occurrences_over_words(): void {
		$html = '<p>' . str_repeat( 'word ', 98 ) . 'espresso espresso</p>';
		$out  = SWPS_Editor_Check_Engine::run( $this->input( array( 'content_html' => $html, 'keywords' => array( array( 'keyword' => 'espresso' ) ) ) ) );
		$res  = $out['keywords'][0]['results']['kw_density'];

		$this->assertSame( 'pass', $res['status'] );
		$this->assertEqualsWithDelta( 2.0, $res['value'], 0.001 );
	}

	public function test_stuffed_keyword_fails_density(): void {
		$html = '<p>' . str_repeat( 'espresso ', 10 ) . str_repeat( 'word ', 10 ) . '</p>';
		$out  = SWPS_Editor_Check_Engine::run( $this->input( array( 'content_html' => $html, 'keywords' => array( array( 'keyword' => 'espresso' ) ) ) ) );

		$this->assertSame( 'fail', $out['keywords'][0]['results']['kw_density']['status'] );
	}

	public function test_link_classification(): void {
		$html = '<a href="/about">a</a><a href="https://www.example.com/x">b</a><a href="https://other.org/y">c</a><a href="#top">d</a><a href="mailto:a@b.co">e</a>';

		$this->assertSame( array( 'internal' => 2, 'external' => 1 ), SWPS_Editor_Text::link_counts( $html, 'example.com' ) );
	}

	public function test_missing_image_alt_is_flagged(): void {
		$html = '<p>Text</p><img src="a.jpg" alt=""><img src="b.jpg" alt="cold brew coffee jar"><img src="c.jpg">';
		$out  = SWPS_Editor_Check_Engine::run( $this->input( array( 'content_html' => $html ) ) );

		$this->assertSame( 'warn', $out['global']['image_alt_missing']['status'] );
		$this->assertSame( 2, $out['global']['image_alt_missing']['value'] );
		$this->assertSame( 'pass', $out['keywords'][0]['results']['kw_in_image_alt']['status'] );
	}

	public function test_used_elsewhere_flag_drives_unique_check(): void {
		$statuses = array();
		foreach ( array( true, false, null ) as $flag ) {
			$out        = SWPS_Editor_Check_Engine::run( $this->input( array( 'keywords' => array( array( 'keyword' => 'cold brew coffee', 'used_elsewhere' => $flag ) ) ) ) );
			$statuses[] = $out['keywords'][0]['results']['kw_unique']['status'];
		}

		$this->assertSame( array( 'fail', 'pass', 'na' ), $statuses );
	}

	public function test_related_keywords_weigh_less_than_the_focus_keyword(): void {
		$focus_only = SWPS_Editor_Check_Engine::score( SWPS_Editor_Check_Engine::run( $this->input() ) );

		$with_bad_related = SWPS_Editor_Check_Engine::score(
			SWPS_Editor_Check_Engine::run(
				$this->input(
					array(
						'keywords' => array(
							array( 'keyword' => 'cold brew coffee' ),
							array( 'keyword' => 'zzz unrelated phrase' ),
						),
					)
				)
			)
		);

		$bad_focus = SWPS_Editor_Check_Engine::score(
			SWPS_Editor_Check_Engine::run(
				$this->input(
					array(
						'keywords' => array(
							array( 'keyword' => 'zzz unrelated phrase' ),
							array( 'keyword' => 'cold brew coffee' ),
						),
					)
				)
			)
		);

		$this->assertLessThan( $focus_only['overall'], $with_bad_related['overall'] );
		$this->assertLessThan( $with_bad_related['overall'], $bad_focus['overall'] );
	}

	public function test_dimension_weights_match_the_legacy_content_scorer(): void {
		$this->assertSame( SWPS_Content_Scorer::DEFAULT_WEIGHTS, SWPS_Editor_Check_Registry::DIMENSION_WEIGHTS );
	}

	public function test_every_check_is_registered_and_produced(): void {
		$out = SWPS_Editor_Check_Engine::run( $this->input() );

		foreach ( SWPS_Editor_Check_Registry::all() as $def ) {
			if ( 'global' === $def['scope'] ) {
				$this->assertArrayHasKey( $def['id'], $out['global'], $def['id'] );
			} else {
				$this->assertArrayHasKey( $def['id'], $out['keywords'][0]['results'], $def['id'] );
			}
		}
	}
}
