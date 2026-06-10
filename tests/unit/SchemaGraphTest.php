<?php
/**
 * Tests for SWPS_Schema_Graph — pure-PHP unit tests for the graph builder,
 * normalize_node(), @id factory methods, and FAQ normalizer.
 *
 * No WordPress dependency — runs in the stub bootstrap environment.
 * normalize_faq_schema() calls sanitize_text_field / sanitize_textarea_field /
 * wp_strip_all_tags which are stubbed in bootstrap.php or added here.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-schema-graph.php';

// Minimal WP function stubs needed by normalize_faq_schema.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $str ): string {
		return rtrim( $str, '/' ) . '/';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title(): string {
		return 'Test Post';
	}
}

/**
 * @covers SWPS_Schema_Graph
 */
class SchemaGraphTest extends TestCase {

	// -------------------------------------------------------------------------
	// add_node / get_nodes
	// -------------------------------------------------------------------------

	public function test_add_node_accumulates(): void {
		$graph = new SWPS_Schema_Graph();
		$graph->add_node( array( '@type' => 'WebSite', '@id' => '#website', 'name' => 'Acme' ) );
		$graph->add_node( array( '@type' => 'Organization', '@id' => '#org', 'name' => 'Acme Inc' ) );
		$this->assertCount( 2, $graph->get_nodes() );
	}

	public function test_add_node_replaces_same_id(): void {
		$graph = new SWPS_Schema_Graph();
		$graph->add_node( array( '@type' => 'WebSite', '@id' => '#website', 'name' => 'Old' ) );
		$graph->add_node( array( '@type' => 'WebSite', '@id' => '#website', 'name' => 'New' ) );
		$nodes = $graph->get_nodes();
		$this->assertCount( 1, $nodes );
		$this->assertSame( 'New', $nodes[0]['name'] );
	}

	public function test_add_node_ignores_empty(): void {
		$graph = new SWPS_Schema_Graph();
		$graph->add_node( array() );
		$this->assertCount( 0, $graph->get_nodes() );
	}

	public function test_add_node_without_id_appends(): void {
		$graph = new SWPS_Schema_Graph();
		$graph->add_node( array( '@type' => 'FAQPage', 'name' => 'FAQ 1' ) );
		$graph->add_node( array( '@type' => 'FAQPage', 'name' => 'FAQ 2' ) );
		$this->assertCount( 2, $graph->get_nodes() );
	}

	public function test_add_node_drops_typeless_node(): void {
		// A legacy filter returning junk (no @type) must not pollute the graph.
		$graph = new SWPS_Schema_Graph();
		$graph->add_node( array( '@id' => '#junk', 'name' => 'No type here' ) );
		$this->assertCount( 0, $graph->get_nodes() );
	}

	public function test_add_node_filter_returns_empty_graph_still_valid(): void {
		// Simulates a filter wiping a node: empty array dropped, other nodes intact.
		$graph = new SWPS_Schema_Graph();
		$graph->add_node( array( '@type' => 'WebSite', '@id' => '#website', 'name' => 'Acme' ) );
		$graph->add_node( array() );
		$nodes = $graph->get_nodes();
		$this->assertCount( 1, $nodes );
		$this->assertSame( 'WebSite', $nodes[0]['@type'] );
	}

	// -------------------------------------------------------------------------
	// normalize_node
	// -------------------------------------------------------------------------

	public function test_normalize_node_strips_context(): void {
		$node   = array( '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'Acme' );
		$result = SWPS_Schema_Graph::normalize_node( $node, '#website' );
		$this->assertArrayNotHasKey( '@context', $result );
	}

	public function test_normalize_node_adds_id_when_missing(): void {
		$node   = array( '@type' => 'WebSite', 'name' => 'Acme' );
		$result = SWPS_Schema_Graph::normalize_node( $node, '#website' );
		$this->assertSame( '#website', $result['@id'] );
	}

	public function test_normalize_node_preserves_existing_id(): void {
		$node   = array( '@type' => 'WebSite', '@id' => '#custom', 'name' => 'Acme' );
		$result = SWPS_Schema_Graph::normalize_node( $node, '#fallback' );
		$this->assertSame( '#custom', $result['@id'] );
	}

	// -------------------------------------------------------------------------
	// @id factory methods
	// -------------------------------------------------------------------------

	public function test_org_id_contains_hash_organization(): void {
		$this->assertStringContainsString( '#organization', SWPS_Schema_Graph::org_id() );
	}

	public function test_website_id_contains_hash_website(): void {
		$this->assertStringContainsString( '#website', SWPS_Schema_Graph::website_id() );
	}

	public function test_webpage_id_appends_fragment(): void {
		$id = SWPS_Schema_Graph::webpage_id( 'https://example.com/post/' );
		$this->assertStringEndsWith( '#webpage', $id );
	}

	public function test_article_id_appends_fragment(): void {
		$id = SWPS_Schema_Graph::article_id( 'https://example.com/post/' );
		$this->assertStringEndsWith( '#article', $id );
	}

	public function test_breadcrumb_id_appends_fragment(): void {
		$id = SWPS_Schema_Graph::breadcrumb_id( 'https://example.com/post/' );
		$this->assertStringEndsWith( '#breadcrumb', $id );
	}

	public function test_person_id_contains_user_id(): void {
		$id = SWPS_Schema_Graph::person_id( 42 );
		$this->assertStringContainsString( '42', $id );
	}

	public function test_profile_page_id_appends_fragment(): void {
		$id = SWPS_Schema_Graph::profile_page_id( 'https://example.com/author/alice/' );
		$this->assertStringEndsWith( '#profilepage', $id );
	}

	// -------------------------------------------------------------------------
	// normalize_faq_schema
	// -------------------------------------------------------------------------

	public function test_normalize_faq_returns_empty_on_empty_input(): void {
		$this->assertSame( array(), SWPS_Schema_Graph::normalize_faq_schema( null ) );
		$this->assertSame( array(), SWPS_Schema_Graph::normalize_faq_schema( '' ) );
		$this->assertSame( array(), SWPS_Schema_Graph::normalize_faq_schema( array() ) );
	}

	public function test_normalize_faq_returns_empty_on_wrong_type(): void {
		$schema = array( '@type' => 'Article', 'headline' => 'Foo' );
		$this->assertSame( array(), SWPS_Schema_Graph::normalize_faq_schema( $schema ) );
	}

	public function test_normalize_faq_parses_valid_array(): void {
		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'name'       => 'FAQ',
			'mainEntity' => array(
				array(
					'@type'          => 'Question',
					'name'           => 'What is X?',
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'X is Y.' ),
				),
			),
		);
		$result = SWPS_Schema_Graph::normalize_faq_schema( $schema );
		$this->assertSame( 'FAQPage', $result['@type'] );
		$this->assertCount( 1, $result['mainEntity'] );
		$this->assertSame( 'What is X?', $result['mainEntity'][0]['name'] );
	}

	public function test_normalize_faq_skips_questions_with_empty_answer(): void {
		$schema = array(
			'@type'      => 'FAQPage',
			'mainEntity' => array(
				array(
					'@type'          => 'Question',
					'name'           => 'Q1',
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => '' ),
				),
				array(
					'@type'          => 'Question',
					'name'           => 'Q2',
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'A2.' ),
				),
			),
		);
		$result = SWPS_Schema_Graph::normalize_faq_schema( $schema );
		$this->assertCount( 1, $result['mainEntity'] );
		$this->assertSame( 'Q2', $result['mainEntity'][0]['name'] );
	}

	public function test_normalize_faq_parses_json_string(): void {
		$schema_arr = array(
			'@type'      => 'FAQPage',
			'mainEntity' => array(
				array(
					'@type'          => 'Question',
					'name'           => 'Q?',
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'A.' ),
				),
			),
		);
		$json   = json_encode( $schema_arr );
		$result = SWPS_Schema_Graph::normalize_faq_schema( $json );
		$this->assertSame( 'FAQPage', $result['@type'] );
	}
}
