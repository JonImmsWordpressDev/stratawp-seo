<?php
/**
 * Unit tests for SWPS_Link_AI_Engine::parse_enriched_items() — mixed
 * local (post_id) and cross-site (url) candidate parsing.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

require_once __DIR__ . '/../../includes/class-link-ai-engine.php';

class LinkAiEngineParseTest extends TestCase {

	public function test_local_item_parsed_with_clamped_score(): void {
		$items = SWPS_Link_AI_Engine::parse_enriched_items(
			array(
				array(
					'post_id'         => 7,
					'relevance_score' => 1.7,
					'anchor_text'     => '<b>great anchor</b>',
					'rationale'       => 'Both cover caching.',
				),
			)
		);

		$this->assertCount( 1, $items );
		$this->assertSame( 7, $items[0]['post_id'] );
		$this->assertSame( 1.0, $items[0]['relevance_score'] );
		$this->assertSame( 'great anchor', $items[0]['anchor_text'] );
		$this->assertFalse( $items[0]['cross_site'] );
	}

	public function test_cross_site_item_parsed_by_url(): void {
		$items = SWPS_Link_AI_Engine::parse_enriched_items(
			array(
				array(
					'url'             => 'https://jonimms.com/a/',
					'relevance_score' => -0.5,
					'anchor_text'     => 'partner post',
					'rationale'       => 'Related topic.',
				),
			)
		);

		$this->assertCount( 1, $items );
		$this->assertSame( 'https://jonimms.com/a/', $items[0]['url'] );
		$this->assertSame( 0.0, $items[0]['relevance_score'] );
		$this->assertTrue( $items[0]['cross_site'] );
		$this->assertArrayNotHasKey( 'post_id', $items[0] );
	}

	public function test_items_without_identifier_or_score_skipped(): void {
		$items = SWPS_Link_AI_Engine::parse_enriched_items(
			array(
				array( 'relevance_score' => 0.9 ),
				array( 'post_id' => 3 ),
				array( 'url' => 'https://jonimms.com/b/' ),
				'garbage',
			)
		);

		$this->assertSame( array(), $items );
	}

	public function test_mixed_items_preserve_order(): void {
		$items = SWPS_Link_AI_Engine::parse_enriched_items(
			array(
				array(
					'post_id'         => 3,
					'relevance_score' => 0.4,
				),
				array(
					'url'             => 'https://jonimms.com/c/',
					'relevance_score' => 0.8,
				),
			)
		);

		$this->assertCount( 2, $items );
		$this->assertSame( 3, $items[0]['post_id'] );
		$this->assertSame( 'https://jonimms.com/c/', $items[1]['url'] );
	}
}
