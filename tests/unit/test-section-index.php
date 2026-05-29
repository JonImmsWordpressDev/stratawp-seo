<?php
/**
 * Pure-logic unit test for SWPS_Image_Inserter::target_section_index().
 * No WordPress required. Run: php tests/unit/test-section-index.php
 *
 * @package StrataWP_SEO
 */

define( 'ABSPATH', __DIR__ ); // satisfy the guard at the top of the class file.
require __DIR__ . '/../../includes/class-image-inserter.php';

// [ eligible_sections, position, target, expected_index ].
$cases = array(
	array( 4, 0, 2, 0 ),
	array( 4, 1, 2, 2 ),
	array( 5, 1, 2, 2 ),
	array( 3, 0, 3, 0 ),
	array( 3, 1, 3, 1 ),
	array( 3, 2, 3, 2 ),
	array( 1, 0, 2, 0 ), // interval floored to >= 1.
);

$failed = 0;
foreach ( $cases as $i => $c ) {
	list( $eligible, $pos, $target, $expected ) = $c;
	$got = SWPS_Image_Inserter::target_section_index( $eligible, $pos, $target );
	if ( $got !== $expected ) {
		echo "FAIL case $i: target_section_index($eligible,$pos,$target) = $got, expected $expected\n";
		++$failed;
	}
}
echo 0 === $failed ? 'OK: all ' . count( $cases ) . " cases passed\n" : "$failed case(s) FAILED\n";
exit( 0 === $failed ? 0 : 1 );
