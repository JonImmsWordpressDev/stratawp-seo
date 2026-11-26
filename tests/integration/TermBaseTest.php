<?php
/**
 * Optional category base removal: links, resolution, collisions, redirects.
 *
 * @package StrataWP_SEO
 */

class TermBaseTest extends WP_UnitTestCase {

	private int $parent;
	private int $child;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		update_option( SWPS_Term_Base::OPTION, array( 'category' ) );

		$this->parent = self::factory()->category->create( array( 'slug' => 'news', 'name' => 'News' ) );
		$this->child  = self::factory()->category->create( array( 'slug' => 'local', 'name' => 'Local', 'parent' => $this->parent ) );
		$posts        = self::factory()->post->create_many( 3 );
		foreach ( $posts as $post_id ) {
			wp_set_post_categories( $post_id, array( $this->child ) );
		}
		update_option( 'posts_per_page', 1 );
	}

	public function tear_down(): void {
		delete_option( SWPS_Term_Base::OPTION );
		parent::tear_down();
	}

	public function test_off_by_default(): void {
		delete_option( SWPS_Term_Base::OPTION );
		$this->assertSame( home_url( '/category/news/' ), get_term_link( $this->parent ) );
	}

	public function test_links_drop_the_base_for_parent_and_child(): void {
		$this->assertSame( home_url( '/news/' ), get_term_link( $this->parent ) );
		$this->assertSame( home_url( '/news/local/' ), get_category_link( $this->child ) );
	}

	public function test_tags_keep_their_base(): void {
		$tag = self::factory()->tag->create( array( 'slug' => 'php' ) );
		$this->assertSame( home_url( '/tag/php/' ), get_term_link( $tag ) );
	}

	public function test_plain_permalinks_are_untouched(): void {
		$this->set_permalink_structure( '' );
		$this->assertStringContainsString( 'cat=' . $this->parent, get_term_link( $this->parent ) );
	}

	public function test_resolves_parent_child_paged_and_feed_urls(): void {
		$this->go_to( home_url( '/news/' ) );
		$this->assertTrue( is_category( $this->parent ), 'parent' );
		$this->assertFalse( is_404() );

		$this->go_to( home_url( '/news/local/' ) );
		$this->assertTrue( is_category( $this->child ), 'child' );

		$this->go_to( home_url( '/news/local/page/2/' ) );
		$this->assertTrue( is_category( $this->child ), 'paged' );
		$this->assertSame( 2, (int) get_query_var( 'paged' ) );
		$this->assertFalse( is_404() );

		$this->go_to( home_url( '/news/feed/' ) );
		$this->assertTrue( is_category( $this->parent ), 'feed' );
		$this->assertTrue( is_feed() );
	}

	public function test_wrong_parent_path_is_not_a_term(): void {
		$this->go_to( home_url( '/local/' ) );
		$this->assertFalse( is_category() );
		$this->assertTrue( is_404() );
	}

	public function test_pages_and_posts_win_on_collisions(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'news' ) );
		$this->go_to( home_url( '/news/' ) );
		$this->assertTrue( is_page( $page ) );
		$this->assertFalse( is_category() );

		$other = self::factory()->category->create( array( 'slug' => 'hello' ) );
		$post  = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$this->go_to( home_url( '/hello/' ) );
		$this->assertTrue( is_single( $post ) );

		$paths = wp_list_pluck( SWPS_Term_Base::collisions(), 'path' );
		sort( $paths );
		$this->assertSame( array( 'hello', 'news' ), $paths );
		$this->assertNotEmpty( $other );
	}

	public function test_old_base_urls_redirect_to_the_new_ones(): void {
		$this->go_to( home_url( '/category/news/local/' ) );
		$this->assertTrue( is_category( $this->child ) );
		$this->assertSame( home_url( '/news/local/' ), stratawp_seo()->term_base->based_url_target() );

		$this->go_to( home_url( '/category/news/local/page/2/' ) );
		$this->assertSame( home_url( '/news/local/page/2/' ), stratawp_seo()->term_base->based_url_target() );

		$this->go_to( home_url( '/news/local/' ) );
		$this->assertNull( stratawp_seo()->term_base->based_url_target(), 'new URL must not redirect' );

		delete_option( SWPS_Term_Base::OPTION );
		$this->go_to( home_url( '/category/news/' ) );
		$this->assertNull( stratawp_seo()->term_base->based_url_target(), 'no redirect while off' );
	}

	public function test_core_canonical_redirect_leaves_the_new_url_alone(): void {
		$this->go_to( home_url( '/news/local/' ) );
		$this->assertNull( redirect_canonical( home_url( '/news/local/' ), false ) );

		$query_url = home_url( '/?cat=' . $this->child );
		$this->go_to( $query_url );
		$this->assertSame( home_url( '/news/local/' ), redirect_canonical( $query_url, false ) );
	}

	public function test_sanitize_keeps_only_eligible_taxonomies(): void {
		$this->assertSame( array( 'category' ), SWPS_Term_Base::sanitize( array( 'category', 'post_tag', 'nope' ) ) );
		$this->assertSame( array(), SWPS_Term_Base::sanitize( null ) );
	}
}
