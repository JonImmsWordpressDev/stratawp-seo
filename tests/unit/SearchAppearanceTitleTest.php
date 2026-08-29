<?php
// tests/unit/SearchAppearanceTitleTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-search-appearance.php';

final class SearchAppearanceTitleTest extends TestCase {

	public function test_empty_meta_title_leaves_parts_untouched(): void {
		$parts = array( 'title' => 'Blog', 'site' => 'JonImms' );
		$this->assertSame( $parts, SWPS_Search_Appearance::apply_posts_page_title( $parts, '' ) );
	}

	public function test_whitespace_only_meta_title_leaves_parts_untouched(): void {
		$parts = array( 'title' => 'Blog', 'site' => 'JonImms' );
		$this->assertSame( $parts, SWPS_Search_Appearance::apply_posts_page_title( $parts, "  \n\t " ) );
	}

	public function test_meta_title_replaces_title_and_drops_site_suffix(): void {
		$parts  = array( 'title' => 'Blog', 'site' => 'JonImms', 'tagline' => 'Dev blog' );
		$result = SWPS_Search_Appearance::apply_posts_page_title( $parts, 'WordPress Development Blog' );

		$this->assertSame( 'WordPress Development Blog', $result['title'] );
		$this->assertArrayNotHasKey( 'site', $result, 'site suffix must be dropped: the stored title is complete' );
		$this->assertArrayNotHasKey( 'tagline', $result );
	}

	public function test_meta_title_is_trimmed(): void {
		$result = SWPS_Search_Appearance::apply_posts_page_title( array( 'title' => 'Blog' ), '  Padded Title  ' );
		$this->assertSame( 'Padded Title', $result['title'] );
	}

	public function test_unrelated_parts_are_preserved(): void {
		$parts  = array( 'title' => 'Blog', 'page' => 'Page 2', 'site' => 'JonImms' );
		$result = SWPS_Search_Appearance::apply_posts_page_title( $parts, 'Custom' );

		$this->assertSame( 'Page 2', $result['page'], 'pagination part must survive so page 2+ titles stay distinct' );
	}
}
