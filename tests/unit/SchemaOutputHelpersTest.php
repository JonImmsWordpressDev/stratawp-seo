<?php
/**
 * Tests for the SWPS_Schema_Graph output helpers: visible-FAQ filtering,
 * coordinate formatting and telephone formatting.
 *
 * Pure PHP; no WordPress loaded.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-schema-graph.php';

class SchemaOutputHelpersTest extends TestCase {

	private function faq( array $questions ): array {
		$entities = array();
		foreach ( $questions as $q ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Answer.' ),
			);
		}
		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
	}

	public function test_faq_question_not_on_page_drops_the_node(): void {
		$schema = $this->faq( array( 'What is the privacy policy for example.com?' ) );
		$page   = '<h2>Privacy Policy</h2><p>We collect only what you submit.</p>';

		$this->assertSame( array(), SWPS_Schema_Graph::filter_faq_to_visible( $schema, $page ) );
	}

	public function test_faq_keeps_only_visible_questions(): void {
		$schema = $this->faq( array( 'Is the plugin free?', 'What is the meaning of life?' ) );
		$page   = '<h3>Is the plugin free?</h3><p>Yes.</p>';

		$out = SWPS_Schema_Graph::filter_faq_to_visible( $schema, $page );

		$this->assertCount( 1, $out['mainEntity'] );
		$this->assertSame( 'Is the plugin free?', $out['mainEntity'][0]['name'] );
	}

	public function test_faq_match_ignores_case_punctuation_entities_and_tags(): void {
		$schema = $this->faq( array( 'Can I use dynamic blocks with page caching?' ) );
		$page   = '<h3 class="x">Can I use <em>dynamic blocks</em> with page&nbsp;caching</h3>';

		$out = SWPS_Schema_Graph::filter_faq_to_visible( $schema, $page );

		$this->assertCount( 1, $out['mainEntity'] );
	}

	public function test_faq_single_entity_object_is_supported(): void {
		$schema               = $this->faq( array( 'Does it need ACF?' ) );
		$schema['mainEntity'] = $schema['mainEntity'][0];

		$out = SWPS_Schema_Graph::filter_faq_to_visible( $schema, '<h3>Does it need ACF?</h3>' );

		$this->assertCount( 1, $out['mainEntity'] );
	}

	public function test_non_faq_schema_is_unchanged(): void {
		$schema = array( '@type' => 'Recipe', 'name' => 'Not on the page' );

		$this->assertSame( $schema, SWPS_Schema_Graph::filter_faq_to_visible( $schema, '' ) );
	}

	public function test_format_coordinate_trims_float_noise(): void {
		$this->assertSame( '41.2565', SWPS_Schema_Graph::format_coordinate( '41.2565' ) );
		$this->assertSame( '41.2565', SWPS_Schema_Graph::format_coordinate( 41.256500000000002614 ) );
		$this->assertSame( '-95.9345', SWPS_Schema_Graph::format_coordinate( '-95.9345' ) );
		$this->assertSame( '41.123457', SWPS_Schema_Graph::format_coordinate( '41.1234567' ) );
		$this->assertSame( '0', SWPS_Schema_Graph::format_coordinate( '-0.0000001' ) );
	}

	public function test_format_coordinate_rejects_non_numbers(): void {
		$this->assertSame( '', SWPS_Schema_Graph::format_coordinate( '' ) );
		$this->assertSame( '', SWPS_Schema_Graph::format_coordinate( 'north' ) );
		$this->assertSame( '', SWPS_Schema_Graph::format_coordinate( null ) );
	}

	public function test_format_telephone_us_numbers_get_country_code(): void {
		$this->assertSame( '+1-402-522-6634', SWPS_Schema_Graph::format_telephone( '4025226634', 'US' ) );
		$this->assertSame( '+1-402-522-6634', SWPS_Schema_Graph::format_telephone( '(402) 522-6634', '' ) );
		$this->assertSame( '+1-402-522-6634', SWPS_Schema_Graph::format_telephone( '1-402-522-6634', 'United States' ) );
		$this->assertSame( '+1-416-555-0100', SWPS_Schema_Graph::format_telephone( '416.555.0100', 'CA' ) );
	}

	public function test_business_description_plain_text(): void {
		$this->assertSame(
			'Jon Imms is a freelance WordPress developer in Omaha.',
			SWPS_Schema_Graph::business_description( "<p>Jon Imms is a   freelance\nWordPress developer in Omaha.</p>" )
		);
		$this->assertSame( '', SWPS_Schema_Graph::business_description( "  \n " ) );
	}

	public function test_business_description_cuts_at_a_sentence(): void {
		$text = 'Jon Imms builds custom WordPress sites for small businesses in Omaha. He also maintains open-source tools. ' . str_repeat( 'More words here. ', 30 );
		$out  = SWPS_Schema_Graph::business_description( $text, 120 );
		$this->assertSame( 'Jon Imms builds custom WordPress sites for small businesses in Omaha. He also maintains open-source tools.', $out );
	}

	public function test_business_description_falls_back_to_a_word_boundary(): void {
		$out = SWPS_Schema_Graph::business_description( str_repeat( 'word ', 100 ), 50 );
		$this->assertStringEndsWith( '…', $out );
		$this->assertLessThanOrEqual( 51, mb_strlen( $out ) );
		$this->assertStringNotContainsString( 'wor…', $out );
	}

	public function test_format_telephone_leaves_other_numbers_alone(): void {
		$this->assertSame( '+44 20 7946 0958', SWPS_Schema_Graph::format_telephone( ' +44 20 7946 0958 ', 'GB' ) );
		$this->assertSame( '020 7946 0958', SWPS_Schema_Graph::format_telephone( '020 7946 0958', 'GB' ) );
		$this->assertSame( '555-0100', SWPS_Schema_Graph::format_telephone( '555-0100', 'US' ) );
		$this->assertSame( '', SWPS_Schema_Graph::format_telephone( '', 'US' ) );
	}
}
