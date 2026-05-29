<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-model-catalog.php';
require_once __DIR__ . '/../../includes/class-cost-tracker.php';

final class CostTrackerTest extends TestCase {

	public function test_opus_4_8_is_priced_via_catalog(): void {
		$tracker = new SWPS_Cost_Tracker();
		// 1M input + 1M output at Opus pricing (15 + 75) = 90.0.
		$this->assertEqualsWithDelta( 90.0, $tracker->calculate_cost( 'claude-opus-4-8', 1_000_000, 1_000_000 ), 0.0001 );
	}

	public function test_unknown_model_uses_default_pricing(): void {
		$tracker = new SWPS_Cost_Tracker();
		// Default 3 + 15 = 18.0.
		$this->assertEqualsWithDelta( 18.0, $tracker->calculate_cost( 'who-knows-9000', 1_000_000, 1_000_000 ), 0.0001 );
	}
}
