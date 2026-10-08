<?php
/**
 * Tests for the pure helpers in SWPS_Editor_Rest.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-schema-validator.php';
require_once __DIR__ . '/../../includes/editor/class-editor-rest.php';

final class EditorRestHelpersTest extends TestCase {

	public function test_normalize_keywords_trims_dedupes_and_caps(): void {
		$out = SWPS_Editor_Rest::normalize_keywords( array( ' A ', 'a', '', 'b', 'c', 'd', 'e', 'f' ) );

		$this->assertSame( array( 'A', 'b', 'c', 'd', 'e' ), $out );
	}

	public function test_normalize_keywords_accepts_garbage(): void {
		$this->assertSame( array(), SWPS_Editor_Rest::normalize_keywords( null ) );
		$this->assertSame( array(), SWPS_Editor_Rest::normalize_keywords( 'text' ) );
	}

	public function test_schema_nodes_reports_missing_required_properties(): void {
		$html     = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","author":"A"}</script>';
		$manifest = array( 'Article' => array( 'required' => array( 'headline', 'author' ) ) );

		$this->assertSame(
			array( array( 'type' => 'Article', 'missing' => array( 'headline' ) ) ),
			SWPS_Editor_Rest::schema_nodes( $html, $manifest )
		);
	}

	public function test_schema_nodes_flattens_graphs_and_type_arrays(): void {
		$html     = '<script type="application/ld+json">{"@graph":[{"@type":["FAQPage"],"mainEntity":[]},{"@type":"Thing"}]}</script>';
		$manifest = array( 'FAQPage' => array( 'required' => array( 'mainEntity' ) ) );
		$nodes    = SWPS_Editor_Rest::schema_nodes( $html, $manifest );

		$this->assertSame( 'FAQPage', $nodes[0]['type'] );
		$this->assertSame( array( 'mainEntity' ), $nodes[0]['missing'] );
		$this->assertSame( array( 'type' => 'Thing', 'missing' => array() ), $nodes[1] );
	}

	public function test_schema_nodes_is_empty_without_jsonld(): void {
		$this->assertSame( array(), SWPS_Editor_Rest::schema_nodes( '<p>No schema</p>', array() ) );
	}

	public function test_snapshot_shape_always_has_the_same_keys(): void {
		$plain = SWPS_Editor_Rest::snapshot_shape( array(), null, null );
		$err   = SWPS_Editor_Rest::snapshot_shape( array(), 'Budget reached', 'swps_budget' );

		$expected = array( 'enabled', 'scanned', 'total', 'subscores', 'sub_queries', 'stale' );
		$this->assertSame( $expected, array_keys( $plain ) );
		$this->assertSame( array_merge( $expected, array( 'error', 'code' ) ), array_keys( $err ) );
	}

	public function test_snapshot_shape_adds_error_and_code_when_given(): void {
		$out = SWPS_Editor_Rest::snapshot_shape( array(), 'Budget reached', 'swps_budget' );

		$this->assertSame( 'Budget reached', $out['error'] );
		$this->assertSame( 'swps_budget', $out['code'] );
		$this->assertArrayNotHasKey( 'error', SWPS_Editor_Rest::snapshot_shape( array(), null, null ) );
	}

	public function test_snapshot_shape_normalises_missing_values(): void {
		$out = SWPS_Editor_Rest::snapshot_shape( array( 'total' => '', 'subscores' => array( 'markup' => '7' ) ), null, null );

		$this->assertNull( $out['total'] );
		$this->assertNull( $out['scanned'] );
		$this->assertSame( array(), $out['sub_queries'] );
		$this->assertSame(
			array( 'extractability' => null, 'markup' => 7, 'authority' => null, 'coverage' => null ),
			$out['subscores']
		);
		$this->assertFalse( $out['stale'] );
	}
}
