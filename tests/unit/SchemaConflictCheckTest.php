<?php
/**
 * Tests for the schema_conflict crawl check and its detector.
 *
 * Pure-PHP, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/class-site-crawler.php';

/**
 * @covers SWPS_Check_Schema_Conflict
 * @covers SWPS_Schema_Conflict_Detector
 */
final class SchemaConflictCheckTest extends TestCase {

	private const URL = 'https://example.test/hello-world/';

	private function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/../fixtures/schema-conflict/' . $name );
	}

	/**
	 * Render a page carrying the given JSON-LD blocks, parse it the way the
	 * crawler does, and run the check on the result.
	 *
	 * @param string[] $blocks Raw JSON-LD block bodies.
	 */
	private function run_check( array $blocks ): ?array {
		$scripts = '';
		foreach ( $blocks as $block ) {
			$scripts .= '<script type="application/ld+json">' . $block . "</script>\n";
		}
		$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Hello world</title>'
			. $scripts . '</head><body><h1>Hello world</h1><p>Body text.</p></body></html>';

		$page = array_merge(
			SWPS_Site_Crawler::parse_html( $html, self::URL ),
			array(
				'url'         => self::URL,
				'status_code' => 200,
			)
		);
		return ( new SWPS_Check_Schema_Conflict() )->check_page( $page );
	}

	private function kinds( array $issue ): array {
		return array_column( $issue['detail']['conflicts'], 'kind' );
	}

	public function test_check_metadata(): void {
		$check = new SWPS_Check_Schema_Conflict();
		$this->assertSame( 'schema_conflict', $check->id() );
		$this->assertSame( 'warning', $check->severity() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->how_to_fix() );
	}

	public function test_check_is_registered(): void {
		$ids = array_map( static fn( SWPS_Crawl_Check $c ) => $c->id(), SWPS_Crawl_Check_Registry::all( array() ) );
		$this->assertContains( 'schema_conflict', $ids );
		$this->assertContains( 'schema_conflict', SWPS_Crawl_Check_Registry::page_check_ids() );
	}

	public function test_parse_html_collects_raw_jsonld_blocks_in_page_order(): void {
		$html = '<html><head><script type="application/ld+json">{"a":1}</script>'
			. '<script src="/x.js"></script>'
			. '<script type="application/ld+json">{"b":2}</script></head><body></body></html>';
		$page = SWPS_Site_Crawler::parse_html( $html, self::URL );
		$this->assertSame( array( '{"a":1}', '{"b":2}' ), $page['jsonld_blocks'] );
		$this->assertTrue( $page['has_schema'] );
	}

	public function test_no_jsonld_means_no_issue(): void {
		$this->assertNull( $this->run_check( array() ) );
	}

	public function test_stratawp_graph_alone_has_no_findings(): void {
		// Includes a LocalBusiness branch linked by parentOrganization and an
		// external Wikidata @id reference; neither is a conflict.
		$this->assertNull( $this->run_check( array( $this->fixture( 'strata-graph.json' ) ) ) );
	}

	public function test_second_plugin_organization_is_flagged_with_its_block(): void {
		$issue = $this->run_check(
			array( $this->fixture( 'strata-graph.json' ), $this->fixture( 'other-plugin-organization.json' ) )
		);

		$this->assertNotNull( $issue );
		$this->assertSame( 'schema_conflict', $issue['type'] );
		$this->assertSame( self::URL, $issue['url'] );
		$this->assertSame( array( 'duplicate_organization' ), $this->kinds( $issue ) );

		$conflict = $issue['detail']['conflicts'][0];
		$this->assertSame( array( 'https://example.test/#organization', 'https://example.test/#org' ), $conflict['ids'] );
		$this->assertSame( array( 1, 2 ), array_column( $conflict['blocks'], 'block' ) );
		$this->assertStringContainsString( '"@type": "Organization"', $conflict['blocks'][1]['snippet'] );
		$this->assertStringContainsString( 'block 1, 2', $issue['detail']['summary'] );
		$this->assertSame( 2, $issue['detail']['block_count'] );
	}

	public function test_dangling_id_is_flagged_but_resolvable_refs_are_not(): void {
		$issue = $this->run_check(
			array( $this->fixture( 'strata-graph.json' ), $this->fixture( 'other-plugin-dangling.json' ) )
		);

		$this->assertNotNull( $issue );
		$this->assertSame( array( 'dangling_id' ), $this->kinds( $issue ) );
		$conflict = $issue['detail']['conflicts'][0];
		// The publisher ref in block 2 resolves to block 1's Organization, so only the image ref dangles.
		$this->assertSame( 'https://example.test/hello-world/#primaryimage', $conflict['id'] );
		$this->assertSame( array( 2 ), array_column( $conflict['blocks'], 'block' ) );
	}

	public function test_invalid_json_is_counted_not_fatal(): void {
		$issue = $this->run_check( array( $this->fixture( 'strata-graph.json' ), $this->fixture( 'invalid.json' ) ) );

		$this->assertNotNull( $issue );
		$this->assertSame( array(), $issue['detail']['conflicts'] );
		$this->assertCount( 1, $issue['detail']['invalid_blocks'] );
		$this->assertSame( 2, $issue['detail']['invalid_blocks'][0]['block'] );
		$this->assertStringContainsString( '1 JSON-LD block(s) failed to parse (block 2)', $issue['detail']['summary'] );
	}

	public function test_same_id_with_conflicting_types(): void {
		$result = SWPS_Schema_Conflict_Detector::analyze(
			array(
				'{"@type":"Organization","@id":"https://example.test/#brand","name":"A"}',
				'{"@type":"Person","@id":"https://example.test/#brand","name":"B"}',
			),
			self::URL
		);
		$this->assertSame( array( 'conflicting_type' ), array_column( $result['conflicts'], 'kind' ) );
		$this->assertSame( array( 'Organization', 'Person' ), $result['conflicts'][0]['types'] );
	}

	public function test_overlapping_types_on_one_id_are_not_a_conflict(): void {
		$result = SWPS_Schema_Conflict_Detector::analyze(
			array(
				'{"@type":["Organization","Brand"],"@id":"https://example.test/#organization","name":"A"}',
				'{"@type":"Organization","@id":"https://example.test/#organization","url":"https://example.test/"}',
			),
			self::URL
		);
		$this->assertSame( array(), $result['conflicts'] );
	}

	public function test_person_and_organization_publishers(): void {
		$result = SWPS_Schema_Conflict_Detector::analyze(
			array(
				'{"@type":"Article","@id":"https://example.test/a/#article","publisher":{"@type":"Person","name":"Sam"}}',
				'{"@type":"NewsArticle","@id":"https://example.test/a/#news","publisher":{"@type":"NewsMediaOrganization","name":"Example"}}',
			),
			self::URL
		);
		$this->assertSame( array( 'mixed_publisher' ), array_column( $result['conflicts'], 'kind' ) );
	}

	public function test_two_websites_with_different_ids(): void {
		$result = SWPS_Schema_Conflict_Detector::analyze(
			array(
				'{"@context":"https://schema.org","@graph":[{"@type":"WebSite","@id":"https://example.test/#website","url":"https://example.test/"}]}',
				'{"@type":"WebSite","url":"https://example.test/","name":"Example"}',
			),
			self::URL
		);
		$this->assertSame( array( 'duplicate_website' ), array_column( $result['conflicts'], 'kind' ) );
		$this->assertSame( array( 'https://example.test/#website', '(no @id)' ), $result['conflicts'][0]['ids'] );
	}

	public function test_same_organization_id_in_two_blocks_is_one_entity(): void {
		$result = SWPS_Schema_Conflict_Detector::analyze(
			array(
				'{"@type":"Organization","@id":"https://example.test/#organization","name":"A"}',
				'{"@type":"Organization","@id":"https://example.test/#organization","logo":"https://example.test/l.png"}',
			),
			self::URL
		);
		$this->assertSame( array(), $result['conflicts'] );
	}

	public function test_snippet_never_splits_a_multibyte_character(): void {
		$name   = str_repeat( 'é', 200 );
		$result = SWPS_Schema_Conflict_Detector::analyze( array( '{"@type":"Thing","name":"' . $name . '"' ), self::URL );
		$snippet = $result['invalid_blocks'][0]['snippet'];
		$this->assertSame( 1, preg_match( '//u', $snippet ), 'snippet must stay valid UTF-8' );
		$this->assertNotFalse( json_encode( $result ) );
	}

	public function test_block_snippets_lists_every_named_block(): void {
		$result = SWPS_Schema_Conflict_Detector::analyze(
			array(
				$this->fixture( 'strata-graph.json' ),
				'not json',
				$this->fixture( 'other-plugin-organization.json' ),
			),
			self::URL
		);
		$snippets = SWPS_Schema_Conflict_Detector::block_snippets( $result );
		$this->assertSame( array( 1, 2, 3 ), array_keys( $snippets ) );
		$this->assertSame( 'not json', $snippets[2] );
		$this->assertLessThanOrEqual( SWPS_Schema_Conflict_Detector::SNIPPET_LENGTH + 3, strlen( $snippets[1] ) );
	}
}
