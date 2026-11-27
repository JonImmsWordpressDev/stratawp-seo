<?php
// tests/unit/SearchAppearanceTermRobotsTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-search-appearance.php';

final class SearchAppearanceTermRobotsTest extends TestCase {

	public function test_no_term_value_uses_taxonomy_noindex(): void {
		$this->assertSame( array( 'noindex', 'follow' ), SWPS_Search_Appearance::term_directives( '', true ) );
	}

	public function test_term_nofollow_only_keeps_taxonomy_noindex(): void {
		$this->assertSame(
			array( 'noindex' ),
			SWPS_Search_Appearance::term_directives( 'nofollow', true ),
			'term nofollow must not drop the taxonomy noindex, and must not be paired with follow'
		);
	}

	public function test_term_noindex_is_left_to_taxonomy_meta(): void {
		$this->assertSame( array(), SWPS_Search_Appearance::term_directives( 'noindex', true ) );
		$this->assertSame( array(), SWPS_Search_Appearance::term_directives( 'noindex,nofollow', true ) );
	}

	public function test_taxonomy_not_noindexed_adds_nothing(): void {
		$this->assertSame( array(), SWPS_Search_Appearance::term_directives( '', false ) );
		$this->assertSame( array(), SWPS_Search_Appearance::term_directives( 'nofollow', false ) );
	}
}
