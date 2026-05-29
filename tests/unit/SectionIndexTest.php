<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-image-inserter.php';

/**
 * Pure-logic tests for the in-content image section-index math.
 * No WordPress required (see tests/bootstrap.php).
 */
final class SectionIndexTest extends TestCase {

	/**
	 * @dataProvider provide_section_index_cases
	 */
	public function test_target_section_index( int $eligible, int $position, int $target, int $expected ): void {
		$this->assertSame(
			$expected,
			SWPS_Image_Inserter::target_section_index( $eligible, $position, $target )
		);
	}

	/**
	 * @return array<string, array{int, int, int, int}>
	 */
	public function provide_section_index_cases(): array {
		return array(
			// [ eligible_sections, position, target, expected_index ].
			'two slots, first'   => array( 4, 0, 2, 0 ),
			'two slots, second'  => array( 4, 1, 2, 2 ),
			'odd eligible count' => array( 5, 1, 2, 2 ),
			'three slots, pos 0' => array( 3, 0, 3, 0 ),
			'three slots, pos 1' => array( 3, 1, 3, 1 ),
			'three slots, pos 2' => array( 3, 2, 3, 2 ),
			'interval floored'   => array( 1, 0, 2, 0 ),
		);
	}
}
