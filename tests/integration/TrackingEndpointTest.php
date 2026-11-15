<?php
/**
 * The public pageview endpoint (wp_ajax_nopriv_swps_track).
 *
 * @package StrataWP_SEO
 */

class TrackingEndpointTest extends WP_Ajax_UnitTestCase {

	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}swps_analytics" );
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		unset( $_SERVER['HTTP_ORIGIN'] );
	}

	private function ping( array $overrides = array() ): void {
		$_POST = array_merge(
			array(
				'action'       => 'swps_track',
				'post_id'      => $this->post_id,
				'page_url'     => home_url( '/?p=' . $this->post_id ),
				'referrer'     => 'https://chatgpt.com/',
				'time_on_page' => 42,
				'scroll_depth' => 80,
				'is_bounce'    => 0,
			),
			$overrides
		);
		try {
			$this->_handleAjax( 'swps_track' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_send_json ends the handler.
		}
		$this->_last_response = '';
	}

	private function rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}swps_analytics" );
	}

	public function test_records_a_valid_hit_without_any_nonce(): void {
		$_SERVER['HTTP_ORIGIN'] = home_url();
		$this->ping(); // No 'nonce' field at all: cached pages must still track.
		$this->assertSame( 1, $this->rows() );
	}

	public function test_refuses_a_cross_site_origin(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
		$this->ping();
		$this->assertSame( 0, $this->rows() );
	}

	public function test_refuses_a_page_url_on_another_site(): void {
		$this->ping( array( 'page_url' => 'https://evil.example/spam' ) );
		$this->assertSame( 0, $this->rows() );
	}

	public function test_refuses_unpublished_or_missing_posts(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->ping( array( 'post_id' => $draft ) );
		$this->ping( array( 'post_id' => 999999 ) );
		$this->assertSame( 0, $this->rows() );
	}

	public function test_caps_hits_per_visitor(): void {
		for ( $i = 0; $i < SWPS_Analytics_Tracker::VISITOR_CAP + 5; $i++ ) {
			$this->ping();
		}
		$this->assertSame( SWPS_Analytics_Tracker::VISITOR_CAP, $this->rows() );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7'; // A different visitor is unaffected.
		$this->ping();
		$this->assertSame( SWPS_Analytics_Tracker::VISITOR_CAP + 1, $this->rows() );
	}

	public function test_origin_helper(): void {
		$home = 'https://example.org';
		$this->assertTrue( SWPS_Analytics_Tracker::origin_allowed( '', $home ) );
		$this->assertTrue( SWPS_Analytics_Tracker::origin_allowed( 'https://EXAMPLE.org', $home ) );
		$this->assertFalse( SWPS_Analytics_Tracker::origin_allowed( 'https://example.org.evil.example', $home ) );
		$this->assertFalse( SWPS_Analytics_Tracker::origin_allowed( 'https://example.org:8443', $home ) );
		$this->assertFalse( SWPS_Analytics_Tracker::origin_allowed( 'null', $home ) );
	}
}
