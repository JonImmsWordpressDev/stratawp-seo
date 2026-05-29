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

	public function test_price_for_known_model(): void {
		$this->assertSame( array( 'input' => 15.0, 'output' => 75.0 ), SWPS_Model_Catalog::price_for( 'claude-opus-4-8' ) );
		$this->assertSame( array( 'input' => 0.8, 'output' => 4.0 ), SWPS_Model_Catalog::price_for( 'claude-haiku-4-5-20251001' ) );
	}

	public function test_price_for_unknown_model_uses_default(): void {
		$this->assertSame( array( 'input' => 3.0, 'output' => 15.0 ), SWPS_Model_Catalog::price_for( 'who-knows-9000' ) );
	}
}
