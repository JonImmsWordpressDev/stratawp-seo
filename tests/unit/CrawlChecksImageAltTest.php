<?php
/**
 * Tests for the alt text quality crawl checks.
 *
 * Pure-PHP, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-page-flags.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-checks-image-alt.php';
require_once __DIR__ . '/../../includes/class-site-crawler.php';

/**
 * @covers SWPS_Alt_Text_Quality
 * @covers SWPS_Check_Image_Alt_Filename
 * @covers SWPS_Check_Image_Alt_Too_Long
 * @covers SWPS_Check_Image_Alt_Stuffed
 * @covers SWPS_Check_Image_Alt_Duplicate_Sitewide
 */
final class CrawlChecksImageAltTest extends TestCase {

	private const DESCRIPTIVE = 'A golden retriever catching a red frisbee in a park';

	private function page( array $image_alts ): array {
		return array(
			'url'        => 'https://x.test/post/',
			'image_alts' => $image_alts,
		);
	}

	private function img( string $src, string $alt ): array {
		return array(
			'src' => $src,
			'alt' => $alt,
		);
	}

	private function row( string $url, array $image_alts, array $over = array() ): array {
		return array_merge(
			array(
				'url'         => $url,
				'status_code' => 200,
				'flags'       => 0,
				'image_alts'  => $image_alts,
			),
			$over
		);
	}

	// -------------------------------------------------------------------
	// Extraction
	// -------------------------------------------------------------------

	public function test_parse_html_collects_non_empty_alts_only(): void {
		$html = '<!doctype html><html><body>'
			. '<img src="/a.jpg" alt="  A   dog  ">'
			. '<img src="/b.jpg" alt="">'
			. '<img src="/c.jpg">'
			. '</body></html>';
		$r    = SWPS_Site_Crawler::parse_html( $html, 'https://x.test/post/' );
		$this->assertSame( array( $this->img( 'https://x.test/a.jpg', 'A dog' ) ), $r['image_alts'] );
		$this->assertSame( array( 'https://x.test/c.jpg' ), $r['images_missing_alt'] );
	}

	// -------------------------------------------------------------------
	// image_alt_filename
	// -------------------------------------------------------------------

	/**
	 * @dataProvider filename_alts
	 */
	public function test_filename_like_alts_are_flagged( string $alt ): void {
		$this->assertTrue( SWPS_Alt_Text_Quality::looks_like_filename( $alt ), $alt );
	}

	public static function filename_alts(): array {
		return array(
			'extension'          => array( 'photo.jpg' ),
			'extension, upper'   => array( 'Hero Banner.PNG' ),
			'webp'               => array( 'team-photo-2026.webp' ),
			'IMG_ default'       => array( 'IMG_1234' ),
			'DSC default'        => array( 'DSC0001' ),
			'DSCN default'       => array( 'DSCN0042' ),
			'Pixel default'      => array( 'PXL_20260101_123456789' ),
			'screenshot slug'    => array( 'screenshot-2026-01-02' ),
			'macOS screenshot'   => array( 'Screen Shot 2020-05-01 at 10.00.00' ),
			'Windows screenshot' => array( 'Screenshot 2026-11-25 093000' ),
			'hyphen slug'        => array( 'blue-running-shoes' ),
			'underscore slug'    => array( 'hero_image' ),
		);
	}

	/**
	 * @dataProvider descriptive_alts
	 */
	public function test_descriptive_alts_are_not_filename_like( string $alt ): void {
		$this->assertFalse( SWPS_Alt_Text_Quality::looks_like_filename( $alt ), $alt );
	}

	public static function descriptive_alts(): array {
		return array(
			'sentence'            => array( self::DESCRIPTIVE ),
			'single word'         => array( 'Logo' ),
			'single hyphen word'  => array( 'T-shirt' ),
			'hyphen in a phrase'  => array( 'An e-commerce checkout page' ),
			'mentions a filetype' => array( 'Diagram of how a JPG is compressed' ),
			'year in a sentence'  => array( 'Screenshots from the 2026 conference' ),
			'empty'               => array( '' ),
		);
	}

	public function test_filename_check_lists_offending_images_with_alt(): void {
		$check = new SWPS_Check_Image_Alt_Filename();
		$issue = $check->check_page(
			$this->page(
				array(
					$this->img( 'https://x.test/a.jpg', 'IMG_1234' ),
					$this->img( 'https://x.test/b.jpg', self::DESCRIPTIVE ),
					$this->img( 'https://x.test/a.jpg', 'IMG_1234' ),
				)
			)
		);
		$this->assertNotNull( $issue );
		$this->assertSame( 'image_alt_filename', $issue['type'] );
		$this->assertSame( 'https://x.test/post/', $issue['url'] );
		$this->assertSame( array( 'https://x.test/a.jpg' ), $issue['detail']['images'] );
		$this->assertSame( array( 'https://x.test/a.jpg' => 'IMG_1234' ), $issue['detail']['alts'] );
	}

	public function test_filename_check_ignores_descriptive_and_decorative(): void {
		$check = new SWPS_Check_Image_Alt_Filename();
		$this->assertNull( $check->check_page( $this->page( array( $this->img( 'https://x.test/a.jpg', self::DESCRIPTIVE ) ) ) ) );
		$this->assertNull( $check->check_page( $this->page( array( $this->img( 'https://x.test/a.jpg', '' ) ) ) ) );
		$this->assertNull( $check->check_page( $this->page( array() ) ) );
		$this->assertNull( $check->check_page( array( 'url' => 'https://x.test/' ) ) );
	}

	// -------------------------------------------------------------------
	// image_alt_too_long
	// -------------------------------------------------------------------

	public function test_too_long_boundary_is_150_characters(): void {
		$this->assertFalse( SWPS_Alt_Text_Quality::is_too_long( str_repeat( 'a', 150 ) ) );
		$this->assertTrue( SWPS_Alt_Text_Quality::is_too_long( str_repeat( 'a', 151 ) ) );
		// Multibyte characters count once each.
		$this->assertFalse( SWPS_Alt_Text_Quality::is_too_long( str_repeat( 'é', 150 ) ) );
		// Surrounding and repeated whitespace does not count.
		$this->assertFalse( SWPS_Alt_Text_Quality::is_too_long( '   ' . str_repeat( 'a', 150 ) . '   ' ) );
	}

	public function test_too_long_check(): void {
		$check = new SWPS_Check_Image_Alt_Too_Long();
		$long  = str_repeat( 'A very detailed description. ', 10 );
		$issue = $check->check_page( $this->page( array( $this->img( 'https://x.test/a.jpg', $long ) ) ) );
		$this->assertNotNull( $issue );
		$this->assertSame( array( 'https://x.test/a.jpg' ), $issue['detail']['images'] );
		$this->assertNull( $check->check_page( $this->page( array( $this->img( 'https://x.test/a.jpg', self::DESCRIPTIVE ) ) ) ) );
		$this->assertNull( $check->check_page( $this->page( array( $this->img( 'https://x.test/a.jpg', '' ) ) ) ) );
	}

	// -------------------------------------------------------------------
	// image_alt_stuffed
	// -------------------------------------------------------------------

	/**
	 * @dataProvider stuffed_alts
	 */
	public function test_stuffed_alts_are_flagged( string $alt ): void {
		$this->assertTrue( SWPS_Alt_Text_Quality::is_stuffed( $alt ), $alt );
	}

	public static function stuffed_alts(): array {
		return array(
			'repeated word'          => array( 'Cheap shoes, best shoes for running shoes' ),
			'repeated, mixed case'   => array( 'Plumber Omaha omaha plumber OMAHA' ),
			'keyword list'           => array( 'wordpress seo, seo plugin, best seo, rank higher' ),
			'keyword list, no space' => array( 'red,blue,green,yellow' ),
		);
	}

	/**
	 * @dataProvider not_stuffed_alts
	 */
	public function test_natural_alts_are_not_stuffed( string $alt ): void {
		$this->assertFalse( SWPS_Alt_Text_Quality::is_stuffed( $alt ), $alt );
	}

	public static function not_stuffed_alts(): array {
		return array(
			'descriptive'            => array( self::DESCRIPTIVE ),
			'stop words repeat'      => array( 'The dog and the cat and the bird on the sofa' ),
			'short words repeat'     => array( 'A to Z, a to b, a to c' ),
			'word twice'             => array( 'A red car next to a blue car' ),
			'short list'             => array( 'Tomatoes, onions and garlic' ),
			'list of long parts'     => array( 'A dog running, a cat sleeping in the sun, a bird on a branch, a fish in a bowl' ),
			'numbers repeat'         => array( 'Score 100 to 100 after 100 minutes' ),
			'empty'                  => array( '' ),
		);
	}

	public function test_stuffed_check(): void {
		$check = new SWPS_Check_Image_Alt_Stuffed();
		$this->assertNotNull( $check->check_page( $this->page( array( $this->img( 'https://x.test/a.jpg', 'seo seo seo' ) ) ) ) );
		$this->assertNull( $check->check_page( $this->page( array( $this->img( 'https://x.test/a.jpg', self::DESCRIPTIVE ) ) ) ) );
	}

	// -------------------------------------------------------------------
	// image_alt_duplicate_sitewide
	// -------------------------------------------------------------------

	public function test_same_alt_on_five_different_files_is_grouped(): void {
		$rows = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$rows[] = $this->row( "https://x.test/p{$i}/", array( $this->img( "https://x.test/img{$i}.jpg", 'Product image' ) ) );
		}
		$groups = SWPS_Check_Image_Alt_Duplicate_Sitewide::find_duplicates( $rows );
		$this->assertSame( array( 'product image' ), array_keys( $groups ) );
		$this->assertSame( 5, $groups['product image']['files'] );
		$this->assertSame( 'Product image', $groups['product image']['alt'] );
		$this->assertSame( array( 'https://x.test/img1.jpg' ), $groups['product image']['pages']['https://x.test/p1/'] );
	}

	public function test_alt_match_is_case_and_whitespace_insensitive(): void {
		$alts = array( 'Product image', 'product image', 'PRODUCT IMAGE', ' Product  image ', 'Product Image' );
		$rows = array();
		foreach ( $alts as $i => $alt ) {
			$rows[] = $this->row( "https://x.test/p{$i}/", array( $this->img( "https://x.test/img{$i}.jpg", $alt ) ) );
		}
		$this->assertCount( 1, SWPS_Check_Image_Alt_Duplicate_Sitewide::find_duplicates( $rows ) );
	}

	public function test_four_files_is_below_the_threshold(): void {
		$rows = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$rows[] = $this->row( "https://x.test/p{$i}/", array( $this->img( "https://x.test/img{$i}.jpg", 'Product image' ) ) );
		}
		$this->assertSame( array(), SWPS_Check_Image_Alt_Duplicate_Sitewide::find_duplicates( $rows ) );
	}

	public function test_same_image_reused_on_many_pages_is_not_a_duplicate(): void {
		$rows = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			// The site logo on every page, at different rendition sizes and with a cache-busting query.
			$src    = 0 === $i % 2 ? 'https://x.test/uploads/logo-300x100.png?v=' . $i : 'https://x.test/uploads/logo.png';
			$rows[] = $this->row( "https://x.test/p{$i}/", array( $this->img( $src, 'Acme logo' ) ) );
		}
		$this->assertSame( array(), SWPS_Check_Image_Alt_Duplicate_Sitewide::find_duplicates( $rows ) );
	}

	public function test_decorative_empty_alt_never_groups(): void {
		$rows = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$rows[] = $this->row( "https://x.test/p{$i}/", array( $this->img( "https://x.test/img{$i}.jpg", '' ) ) );
		}
		$this->assertSame( array(), SWPS_Check_Image_Alt_Duplicate_Sitewide::find_duplicates( $rows ) );
	}

	public function test_five_files_on_one_page_count(): void {
		$imgs = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$imgs[] = $this->img( "https://x.test/img{$i}.jpg", 'Gallery photo' );
		}
		$groups = SWPS_Check_Image_Alt_Duplicate_Sitewide::find_duplicates( array( $this->row( 'https://x.test/gallery/', $imgs ) ) );
		$this->assertSame( 5, $groups['gallery photo']['files'] );
		$this->assertCount( 5, $groups['gallery photo']['pages']['https://x.test/gallery/'] );
	}

	public function test_duplicates_skip_redirect_and_challenge_rows(): void {
		$rows = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$rows[] = $this->row( "https://x.test/p{$i}/", array( $this->img( "https://x.test/img{$i}.jpg", 'Product image' ) ) );
		}
		$rows[] = $this->row( 'https://x.test/r/', array( $this->img( 'https://x.test/img5.jpg', 'Product image' ) ), array( 'status_code' => 301 ) );
		$rows[] = $this->row( 'https://x.test/c/', array( $this->img( 'https://x.test/img6.jpg', 'Product image' ) ), array( 'flags' => SWPS_Crawl_Page_Flags::IS_CHALLENGE ) );
		$this->assertSame( array(), SWPS_Check_Image_Alt_Duplicate_Sitewide::find_duplicates( $rows ) );
	}

	public function test_duplicate_check_is_an_aggregate_check(): void {
		$method = new ReflectionMethod( SWPS_Check_Image_Alt_Duplicate_Sitewide::class, 'check_run' );
		$this->assertSame( SWPS_Check_Image_Alt_Duplicate_Sitewide::class, $method->getDeclaringClass()->getName() );
		$this->assertNull( ( new SWPS_Check_Image_Alt_Duplicate_Sitewide() )->check_page( $this->page( array() ) ) );
	}
}
