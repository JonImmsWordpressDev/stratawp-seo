<?php
/**
 * Tests for SWPS_Fixit_Controller::partition_rows.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-fixit-controller.php';

final class FixitControllerTest extends TestCase {

	private function rows( int $n ): array {
		return array_map(
			static fn( $i ) => array( 'id' => $i ),
			range( 1, $n )
		);
	}

	public function test_partition_first_chunk(): void {
		$out = SWPS_Fixit_Controller::partition_rows( $this->rows( 12 ), 0, 5 );
		$this->assertCount( 5, $out['batch'] );
		$this->assertSame( 7, $out['remaining'] );
		$this->assertSame( 1, $out['batch'][0]['id'] );
	}

	public function test_partition_last_partial_chunk(): void {
		$out = SWPS_Fixit_Controller::partition_rows( $this->rows( 12 ), 10, 5 );
		$this->assertCount( 2, $out['batch'] );
		$this->assertSame( 0, $out['remaining'] );
	}

	public function test_partition_past_end(): void {
		$out = SWPS_Fixit_Controller::partition_rows( $this->rows( 3 ), 10, 5 );
		$this->assertSame( array(), $out['batch'] );
		$this->assertSame( 0, $out['remaining'] );
	}
}
