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

	public function test_usable_usage_rejects_non_arrays_and_missing_keys(): void {
		$this->assertNull( SWPS_Cost_Tracker::usable_usage( null ) );
		$this->assertNull( SWPS_Cost_Tracker::usable_usage( 'nope' ) );
		$this->assertNull( SWPS_Cost_Tracker::usable_usage( array() ) );
		$this->assertNull( SWPS_Cost_Tracker::usable_usage( array( 'input_tokens' => 10 ) ) );
		$this->assertNull( SWPS_Cost_Tracker::usable_usage( array( 'output_tokens' => 10 ) ) );
	}

	public function test_usable_usage_rejects_zero_negative_and_non_numeric(): void {
		$this->assertNull( SWPS_Cost_Tracker::usable_usage( array( 'input_tokens' => 0, 'output_tokens' => 0 ) ) );
		$this->assertNull( SWPS_Cost_Tracker::usable_usage( array( 'input_tokens' => -5, 'output_tokens' => -1 ) ) );
		$this->assertNull( SWPS_Cost_Tracker::usable_usage( array( 'input_tokens' => 'abc', 'output_tokens' => 5 ) ) );
	}

	public function test_usable_usage_casts_valid_values_to_ints(): void {
		$this->assertSame(
			array( 'input' => 120, 'output' => 30 ),
			SWPS_Cost_Tracker::usable_usage( array( 'input_tokens' => '120', 'output_tokens' => 30 ) )
		);
	}

	public function test_usable_usage_accepts_one_side_zero_and_clamps_negative(): void {
		$this->assertSame(
			array( 'input' => 0, 'output' => 40 ),
			SWPS_Cost_Tracker::usable_usage( array( 'input_tokens' => 0, 'output_tokens' => 40 ) )
		);
		$this->assertSame(
			array( 'input' => 25, 'output' => 0 ),
			SWPS_Cost_Tracker::usable_usage( array( 'input_tokens' => 25, 'output_tokens' => -3 ) )
		);
	}
}
