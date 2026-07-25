<?php
/**
 * Unit tests for SWPS_Internal_Links::detect_existing_links() — the
 * same-host filter relaxation for owned/partner domains.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'url_to_postid' ) ) {
	function url_to_postid( $url ) {
		return $GLOBALS['swps_test_url_map'][ $url ] ?? 0;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return '2026-07-25 00:00:00';
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		return $GLOBALS['swps_test_postmeta'][ $id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['swps_test_postmeta'][ $id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $key ) {
		unset( $GLOBALS['swps_test_postmeta'][ $id ][ $key ] );
		return true;
	}
}

require_once __DIR__ . '/../../includes/class-link-keyword-engine.php';
require_once __DIR__ . '/../../includes/class-link-ai-engine.php';
require_once __DIR__ . '/../../includes/class-cross-site-links.php';
require_once __DIR__ . '/../../includes/class-internal-links.php';

if ( ! class_exists( 'SWPS_Test_WPDB' ) ) {
	/**
	 * Minimal wpdb double: records inserts, returns no existing rows.
	 */
	class SWPS_Test_WPDB {
		public $prefix  = 'wp_';
		public $inserts = array();

		public function prepare( $query, ...$args ) {
			return $query;
		}
		public function get_row( ...$args ) {
			return null;
		}
		public function get_results( ...$args ) {
			return array();
		}
		public function insert( $table, $data, $format = null ) {
			$this->inserts[] = array(
				'table' => $table,
				'data'  => $data,
			);
			return 1;
		}
		public function update( ...$args ) {
			return 1;
		}
		public function delete( ...$args ) {
			return 1;
		}
		public function query( ...$args ) {
			return 1;
		}
	}
}

class InternalLinksDetectTest extends TestCase {

	private SWPS_Test_WPDB $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['swps_test_options']  = array();
		$GLOBALS['swps_test_postmeta'] = array();
		$GLOBALS['swps_test_url_map']  = array();
		$this->wpdb                    = new SWPS_Test_WPDB();
		$GLOBALS['wpdb']               = $this->wpdb;
	}

	private function make_engine(): SWPS_Internal_Links {
		$api  = $this->getMockBuilder( SWPS_AI_Provider::class )
			->disableOriginalConstructor()
			->getMockForAbstractClass();
		$cost = $this->getMockBuilder( SWPS_Cost_Tracker::class )
			->disableOriginalConstructor()
			->getMock();

		return new SWPS_Internal_Links(
			new SWPS_Link_Keyword_Engine(),
			new SWPS_Link_AI_Engine( $api, $cost )
		);
	}

	public function test_same_site_absolute_href_recorded_in_graph(): void {
		$GLOBALS['swps_test_url_map']['https://example.com/about/'] = 42;

		$this->make_engine()->detect_existing_links( 5, '<p>See <a href="https://example.com/about/">About us</a> now.</p>' );

		$this->assertCount( 1, $this->wpdb->inserts );
		$data = $this->wpdb->inserts[0]['data'];
		$this->assertSame( 5, $data['source_post_id'] );
		$this->assertSame( 42, $data['target_post_id'] );
		$this->assertSame( 'existing', $data['status'] );
		$this->assertSame( 'About us', $data['anchor_text'] );
	}

	public function test_relative_href_recorded_in_graph(): void {
		$GLOBALS['swps_test_url_map']['/services/'] = 7;

		$this->make_engine()->detect_existing_links( 5, "<a href='/services/'>Services</a>" );

		$this->assertCount( 1, $this->wpdb->inserts );
		$this->assertSame( 7, $this->wpdb->inserts[0]['data']['target_post_id'] );
	}

	public function test_foreign_href_still_skipped(): void {
		$this->make_engine()->detect_existing_links( 5, '<a href="https://google.com/search">Google</a>' );

		$this->assertSame( array(), $this->wpdb->inserts );
		$this->assertArrayNotHasKey( 5, $GLOBALS['swps_test_postmeta'] );
	}

	public function test_owned_domain_href_recorded_as_cross_site_not_graph(): void {
		$GLOBALS['swps_test_options']['swps_owned_domains'] = array( 'https://jonimms.com' );

		$this->make_engine()->detect_existing_links( 5, '<a href="https://jonimms.com/post/hello-world/">my post</a>' );

		$this->assertSame( array(), $this->wpdb->inserts );
		$this->assertSame(
			array(
				array(
					'url'    => 'https://jonimms.com/post/hello-world/',
					'anchor' => 'my post',
				),
			),
			$GLOBALS['swps_test_postmeta'][5]['_swps_cross_site_existing']
		);
	}

	public function test_mixed_content_splits_between_graph_and_cross_site_meta(): void {
		$GLOBALS['swps_test_options']['swps_owned_domains']         = array( 'https://jonimms.com' );
		$GLOBALS['swps_test_url_map']['https://example.com/about/'] = 42;

		$content  = '<a href="https://example.com/about/">About</a>';
		$content .= '<a href="https://jonimms.com/other/">partner</a>';
		$content .= '<a href="https://google.com/x">skip me</a>';

		$this->make_engine()->detect_existing_links( 5, $content );

		$this->assertCount( 1, $this->wpdb->inserts );
		$this->assertSame( 42, $this->wpdb->inserts[0]['data']['target_post_id'] );
		$this->assertCount( 1, $GLOBALS['swps_test_postmeta'][5]['_swps_cross_site_existing'] );
		$this->assertSame( 'https://jonimms.com/other/', $GLOBALS['swps_test_postmeta'][5]['_swps_cross_site_existing'][0]['url'] );
	}

	public function test_stale_cross_site_meta_cleared_when_links_removed(): void {
		$GLOBALS['swps_test_options']['swps_owned_domains'] = array( 'https://jonimms.com' );
		$GLOBALS['swps_test_postmeta'][5]['_swps_cross_site_existing'] = array(
			array(
				'url'    => 'https://jonimms.com/old/',
				'anchor' => 'old link',
			),
		);

		$this->make_engine()->detect_existing_links( 5, '<p>No links here anymore.</p>' );

		$this->assertArrayNotHasKey( '_swps_cross_site_existing', $GLOBALS['swps_test_postmeta'][5] ?? array() );
	}
}
