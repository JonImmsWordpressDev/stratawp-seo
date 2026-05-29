<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-model-discovery.php';

final class ModelDiscoveryTest extends TestCase {

	public function test_merge_prefers_curated_label_and_order(): void {
		$curated    = array( 'a' => 'Curated A', 'b' => 'Curated B' );
		$discovered = array( 'b' => 'Discovered B', 'c' => 'Discovered C' );
		$merged     = SWPS_Model_Discovery::merge_models( $curated, $discovered );
		// Curated entries keep their label and come first; new discovered appended.
		$this->assertSame(
			array( 'a' => 'Curated A', 'b' => 'Curated B', 'c' => 'Discovered C' ),
			$merged
		);
	}

	public function test_diff_returns_only_unknown_ids(): void {
		$known   = array( 'a', 'b' );
		$current = array( 'a', 'b', 'c', 'd' );
		$this->assertSame( array( 'c', 'd' ), SWPS_Model_Discovery::diff_new_ids( $known, $current ) );
	}

	public function test_diff_empty_known_returns_all(): void {
		$this->assertSame( array( 'a' ), SWPS_Model_Discovery::diff_new_ids( array(), array( 'a' ) ) );
	}
}
