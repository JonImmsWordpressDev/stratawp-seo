<?php
/**
 * Tests for SWPS_Schema_Validator pure-static helpers.
 *
 * No WordPress dependency — runs in the stub bootstrap environment.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-schema-validator.php';

/**
 * @covers SWPS_Schema_Validator
 */
class SchemaValidatorTest extends TestCase {

	// -------------------------------------------------------------------------
	// extract_jsonld
	// -------------------------------------------------------------------------

	public function test_extract_jsonld_returns_empty_on_no_blocks(): void {
		$this->assertSame( array(), SWPS_Schema_Validator::extract_jsonld( '<html><head></head></html>' ) );
	}

	public function test_extract_jsonld_parses_single_block(): void {
		$html = '<script type="application/ld+json">{"@type":"Article","headline":"Hello"}</script>';
		$result = SWPS_Schema_Validator::extract_jsonld( $html );
		$this->assertCount( 1, $result );
		$this->assertSame( 'Article', $result[0]['@type'] );
	}

	public function test_extract_jsonld_parses_multiple_blocks(): void {
		$html  = '<script type="application/ld+json">{"@type":"WebSite","name":"Acme"}</script>';
		$html .= '<script type="application/ld+json">{"@type":"Organization","name":"Acme Inc"}</script>';
		$result = SWPS_Schema_Validator::extract_jsonld( $html );
		$this->assertCount( 2, $result );
	}

	public function test_extract_jsonld_skips_invalid_json(): void {
		$html = '<script type="application/ld+json">NOT_JSON</script>';
		$this->assertSame( array(), SWPS_Schema_Validator::extract_jsonld( $html ) );
	}

	public function test_extract_jsonld_handles_graph_block(): void {
		$schema = array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'WebSite', '@id' => '#website', 'name' => 'Acme' ),
				array( '@type' => 'Article', '@id' => '#article', 'headline' => 'Hello' ),
			),
		);
		$html   = '<script type="application/ld+json">' . json_encode( $schema ) . '</script>';
		$result = SWPS_Schema_Validator::extract_jsonld( $html );
		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( '@graph', $result[0] );
		$this->assertCount( 2, $result[0]['@graph'] );
	}

	public function test_extract_jsonld_handles_whitespace_in_script_tag(): void {
		$html = '<script type="application/ld+json" >  {"@type":"WebPage","name":"Home"}  </script>';
		$result = SWPS_Schema_Validator::extract_jsonld( $html );
		$this->assertCount( 1, $result );
		$this->assertSame( 'WebPage', $result[0]['@type'] );
	}

	public function test_extract_jsonld_ignores_non_ldjson_scripts(): void {
		$html = '<script type="text/javascript">var x = 1;</script>';
		$this->assertSame( array(), SWPS_Schema_Validator::extract_jsonld( $html ) );
	}

	// -------------------------------------------------------------------------
	// validate_node
	// -------------------------------------------------------------------------

	public function test_validate_node_passes_when_all_required_present(): void {
		$node  = array( 'headline' => 'Test', 'datePublished' => '2024-01-01', 'dateModified' => '2024-01-02', 'author' => array( '@type' => 'Person', 'name' => 'Alice' ) );
		$rules = array( 'headline', 'datePublished', 'dateModified', 'author' );
		$this->assertSame( array(), SWPS_Schema_Validator::validate_node( $node, $rules ) );
	}

	public function test_validate_node_returns_missing_keys(): void {
		$node  = array( 'headline' => 'Test' );
		$rules = array( 'headline', 'datePublished', 'dateModified', 'author' );
		$missing = SWPS_Schema_Validator::validate_node( $node, $rules );
		$this->assertContains( 'datePublished', $missing );
		$this->assertContains( 'dateModified', $missing );
		$this->assertContains( 'author', $missing );
		$this->assertNotContains( 'headline', $missing );
	}

	public function test_validate_node_treats_empty_string_as_missing(): void {
		$node  = array( 'name' => '' );
		$rules = array( 'name' );
		$this->assertSame( array( 'name' ), SWPS_Schema_Validator::validate_node( $node, $rules ) );
	}

	public function test_validate_node_treats_null_as_missing(): void {
		$node  = array( 'name' => null );
		$rules = array( 'name' );
		$this->assertSame( array( 'name' ), SWPS_Schema_Validator::validate_node( $node, $rules ) );
	}

	public function test_validate_node_treats_empty_array_as_missing(): void {
		$node  = array( 'mainEntity' => array() );
		$rules = array( 'mainEntity' );
		$this->assertSame( array( 'mainEntity' ), SWPS_Schema_Validator::validate_node( $node, $rules ) );
	}

	public function test_validate_node_empty_rules_always_passes(): void {
		$node  = array( 'anything' => 'value' );
		$this->assertSame( array(), SWPS_Schema_Validator::validate_node( $node, array() ) );
	}

	// -------------------------------------------------------------------------
	// find_orphan_refs
	// -------------------------------------------------------------------------

	public function test_find_orphan_refs_empty_graph_returns_empty(): void {
		$this->assertSame( array(), SWPS_Schema_Validator::find_orphan_refs( array() ) );
	}

	public function test_find_orphan_refs_no_orphans_when_all_defined(): void {
		$graph = array(
			'@graph' => array(
				array( '@type' => 'WebSite', '@id' => '#website', 'name' => 'Acme' ),
				array(
					'@type'     => 'Article',
					'@id'       => '#article',
					'headline'  => 'Hello',
					'publisher' => array( '@id' => '#website' ),
				),
			),
		);
		$this->assertSame( array(), SWPS_Schema_Validator::find_orphan_refs( $graph ) );
	}

	public function test_find_orphan_refs_detects_missing_id(): void {
		$graph = array(
			'@graph' => array(
				array(
					'@type'     => 'Article',
					'@id'       => '#article',
					'headline'  => 'Hello',
					'publisher' => array( '@id' => '#organization' ), // never defined
				),
			),
		);
		$orphans = SWPS_Schema_Validator::find_orphan_refs( $graph );
		$this->assertContains( '#organization', $orphans );
	}

	public function test_find_orphan_refs_handles_single_node(): void {
		$node = array(
			'@type'     => 'Article',
			'@id'       => '#article',
			'publisher' => array( '@id' => '#org' ),
		);
		$orphans = SWPS_Schema_Validator::find_orphan_refs( $node );
		$this->assertContains( '#org', $orphans );
	}

	// -------------------------------------------------------------------------
	// check_date_consistency
	// -------------------------------------------------------------------------

	public function test_check_date_consistency_null_when_no_datemodified(): void {
		$this->assertNull( SWPS_Schema_Validator::check_date_consistency( array( '@type' => 'Article' ), time() ) );
	}

	public function test_check_date_consistency_null_when_within_threshold(): void {
		$ts   = mktime( 12, 0, 0, 6, 10, 2024 );
		$node = array( 'dateModified' => date( 'c', $ts ) );
		// 12 hours drift — under 1-day threshold.
		$this->assertNull( SWPS_Schema_Validator::check_date_consistency( $node, $ts + 43200 ) );
	}

	public function test_check_date_consistency_returns_message_when_drift_exceeds_threshold(): void {
		$ts   = mktime( 0, 0, 0, 1, 1, 2024 );
		$node = array( 'dateModified' => date( 'c', $ts ) );
		// 3-day drift.
		$result = SWPS_Schema_Validator::check_date_consistency( $node, $ts + ( 3 * DAY_IN_SECONDS ) );
		$this->assertIsString( $result );
		$this->assertStringContainsString( '3 day', $result );
	}

	public function test_check_date_consistency_returns_message_for_unparseable_date(): void {
		$node   = array( 'dateModified' => 'not-a-date' );
		$result = SWPS_Schema_Validator::check_date_consistency( $node, time() );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'could not be parsed', $result );
	}

	public function test_check_date_consistency_null_when_exactly_at_threshold(): void {
		$ts   = time();
		$node = array( 'dateModified' => date( 'c', $ts ) );
		// Exactly 1 day — NOT over threshold (condition is strictly greater than).
		$this->assertNull( SWPS_Schema_Validator::check_date_consistency( $node, $ts + DAY_IN_SECONDS ) );
	}
}
