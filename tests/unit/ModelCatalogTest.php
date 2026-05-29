<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-model-catalog.php';

final class ModelCatalogTest extends TestCase {

	public function test_metadata_detects_anthropic_opus(): void {
		$m = SWPS_Model_Catalog::metadata( 'claude-opus-4-8' );
		$this->assertSame( 'anthropic', $m['provider'] );
		$this->assertSame( 30048, $m['power_score'] );
		$this->assertSame( 75.0, $m['price_out'] );
	}

	public function test_metadata_strips_date_suffix_for_version(): void {
		$this->assertSame( 30041, SWPS_Model_Catalog::metadata( 'claude-opus-4-1-20250805' )['power_score'] );
		$this->assertSame( 30040, SWPS_Model_Catalog::metadata( 'claude-opus-4-20250514' )['power_score'] );
	}

	public function test_power_score_orders_by_tier_then_version(): void {
		$score = fn( string $id ) => SWPS_Model_Catalog::metadata( $id )['power_score'];
		$this->assertGreaterThan( $score( 'claude-opus-4-7' ), $score( 'claude-opus-4-8' ) );
		$this->assertGreaterThan( $score( 'claude-sonnet-4-6' ), $score( 'claude-opus-4-6' ) );
		$this->assertGreaterThan( $score( 'claude-haiku-4-5-20251001' ), $score( 'claude-sonnet-4-6' ) );
	}

	public function test_metadata_detects_other_providers(): void {
		$this->assertSame( 'openai', SWPS_Model_Catalog::metadata( 'gpt-4.1' )['provider'] );
		$this->assertSame( 'google', SWPS_Model_Catalog::metadata( 'gemini-2.5-pro' )['provider'] );
		$this->assertSame( 'xai', SWPS_Model_Catalog::metadata( 'grok-3' )['provider'] );
	}

	public function test_metadata_unknown_model_is_null(): void {
		$m = SWPS_Model_Catalog::metadata( 'totally-unknown-model' );
		$this->assertNull( $m['provider'] );
		$this->assertNull( $m['power_score'] );
		$this->assertNull( $m['price_out'] );
	}
}
