<?php
/**
 * TDD tests for SWPS_Crawler_Verification pure static helpers.
 *
 * Pure-PHP, no WordPress. All tests run against the static methods only:
 * ip_in_ranges(), classify_hit(), rdns_host_allowed(), client_ip().
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-crawler-verification.php';

/**
 * @covers SWPS_Crawler_Verification
 */
class CrawlerVerificationTest extends TestCase {

	// -------------------------------------------------------------------------
	// ip_in_ranges — IPv4
	// -------------------------------------------------------------------------

	public function test_ipv4_in_range(): void {
		$this->assertTrue( SWPS_Crawler_Verification::ip_in_ranges( '66.249.64.1', array( '66.249.64.0/19' ) ) );
	}

	public function test_ipv4_below_range(): void {
		$this->assertFalse( SWPS_Crawler_Verification::ip_in_ranges( '66.249.63.255', array( '66.249.64.0/19' ) ) );
	}

	public function test_ipv4_above_range(): void {
		$this->assertFalse( SWPS_Crawler_Verification::ip_in_ranges( '66.249.96.0', array( '66.249.64.0/19' ) ) );
	}

	public function test_ipv4_slash32_exact_match(): void {
		$this->assertTrue( SWPS_Crawler_Verification::ip_in_ranges( '1.2.3.4', array( '1.2.3.4/32' ) ) );
	}

	public function test_ipv4_slash32_no_match(): void {
		$this->assertFalse( SWPS_Crawler_Verification::ip_in_ranges( '1.2.3.5', array( '1.2.3.4/32' ) ) );
	}

	// -------------------------------------------------------------------------
	// ip_in_ranges — IPv6
	// -------------------------------------------------------------------------

	public function test_ipv6_in_range(): void {
		// 2001:4860::/32 → 2001:4860:0000:... to 2001:4860:ffff:...
		$this->assertTrue( SWPS_Crawler_Verification::ip_in_ranges( '2001:4860:4801::1', array( '2001:4860::/32' ) ) );
	}

	public function test_ipv6_not_in_range(): void {
		$this->assertFalse( SWPS_Crawler_Verification::ip_in_ranges( '2001:4861::1', array( '2001:4860::/32' ) ) );
	}

	// -------------------------------------------------------------------------
	// ip_in_ranges — invalid inputs (fail-open = false)
	// -------------------------------------------------------------------------

	public function test_invalid_ip_returns_false(): void {
		$this->assertFalse( SWPS_Crawler_Verification::ip_in_ranges( 'not-an-ip', array( '66.249.64.0/19' ) ) );
	}

	public function test_invalid_cidr_returns_false(): void {
		$this->assertFalse( SWPS_Crawler_Verification::ip_in_ranges( '66.249.64.1', array( 'not-a-cidr' ) ) );
	}

	public function test_empty_ip_returns_false(): void {
		$this->assertFalse( SWPS_Crawler_Verification::ip_in_ranges( '', array( '66.249.64.0/19' ) ) );
	}

	public function test_empty_cidrs_returns_false(): void {
		$this->assertFalse( SWPS_Crawler_Verification::ip_in_ranges( '66.249.64.1', array() ) );
	}

	// -------------------------------------------------------------------------
	// classify_hit — verified
	// -------------------------------------------------------------------------

	public function test_classify_verified_when_ip_in_provider_range(): void {
		$ranges = array(
			'googlebot' => array( '66.249.64.0/19' ),
		);
		$this->assertSame( 'verified', SWPS_Crawler_Verification::classify_hit( 'googlebot', '66.249.64.5', $ranges ) );
	}

	// -------------------------------------------------------------------------
	// classify_hit — spoofed
	// -------------------------------------------------------------------------

	public function test_classify_spoofed_when_ip_not_in_provider_range(): void {
		$ranges = array(
			'googlebot' => array( '66.249.64.0/19' ),
		);
		$this->assertSame( 'spoofed', SWPS_Crawler_Verification::classify_hit( 'googlebot', '1.2.3.4', $ranges ) );
	}

	// -------------------------------------------------------------------------
	// classify_hit — unverifiable
	// -------------------------------------------------------------------------

	public function test_classify_unverifiable_when_no_ranges_for_provider(): void {
		$this->assertSame( 'unverifiable', SWPS_Crawler_Verification::classify_hit( 'applebot', '17.0.0.1', array() ) );
	}

	public function test_classify_unverifiable_when_empty_ip(): void {
		$ranges = array( 'googlebot' => array( '66.249.64.0/19' ) );
		$this->assertSame( 'unverifiable', SWPS_Crawler_Verification::classify_hit( 'googlebot', '', $ranges ) );
	}

	public function test_classify_unverifiable_when_provider_ranges_empty_array(): void {
		$ranges = array( 'googlebot' => array() );
		$this->assertSame( 'unverifiable', SWPS_Crawler_Verification::classify_hit( 'googlebot', '66.249.64.5', $ranges ) );
	}

	// -------------------------------------------------------------------------
	// rdns_host_allowed — valid suffixes
	// -------------------------------------------------------------------------

	public function test_googlebot_host_allowed(): void {
		$this->assertTrue( SWPS_Crawler_Verification::rdns_host_allowed( 'crawl-66-249-64-1.googlebot.com', 'googlebot' ) );
	}

	public function test_googlebot_google_com_host_allowed(): void {
		$this->assertTrue( SWPS_Crawler_Verification::rdns_host_allowed( 'rate-limited-by-google.google.com', 'googlebot' ) );
	}

	public function test_bingbot_host_allowed(): void {
		$this->assertTrue( SWPS_Crawler_Verification::rdns_host_allowed( 'msnbot-157-55-39-1.search.msn.com', 'bingbot' ) );
	}

	public function test_applebot_host_allowed(): void {
		$this->assertTrue( SWPS_Crawler_Verification::rdns_host_allowed( 'server1.applebot.apple.com', 'applebot' ) );
	}

	public function test_openai_host_allowed(): void {
		$this->assertTrue( SWPS_Crawler_Verification::rdns_host_allowed( 'host.openai.com', 'gptbot' ) );
	}

	public function test_perplexity_host_allowed(): void {
		$this->assertTrue( SWPS_Crawler_Verification::rdns_host_allowed( 'bot.perplexity.ai', 'perplexitybot' ) );
	}

	// -------------------------------------------------------------------------
	// rdns_host_allowed — anti-spoof cases
	// -------------------------------------------------------------------------

	public function test_evil_prefix_googlebot_subdomain_not_allowed(): void {
		// evilgooglebot.com is NOT a suffix of googlebot.com
		$this->assertFalse( SWPS_Crawler_Verification::rdns_host_allowed( 'crawl.evilgooglebot.com', 'googlebot' ) );
	}

	public function test_wrong_suffix_for_bot_not_allowed(): void {
		// A Bing IP resolving to a Google hostname should not verify as bingbot.
		$this->assertFalse( SWPS_Crawler_Verification::rdns_host_allowed( 'crawl.googlebot.com', 'bingbot' ) );
	}

	public function test_unknown_bot_key_returns_false(): void {
		$this->assertFalse( SWPS_Crawler_Verification::rdns_host_allowed( 'any.host.com', 'unknown_bot' ) );
	}

	// -------------------------------------------------------------------------
	// client_ip — proxy modes
	// -------------------------------------------------------------------------

	public function test_client_ip_none_mode_returns_remote_addr(): void {
		$server = array(
			'REMOTE_ADDR'          => '1.2.3.4',
			'HTTP_CF_CONNECTING_IP' => '9.9.9.9',
			'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
		);
		$this->assertSame( '1.2.3.4', SWPS_Crawler_Verification::client_ip( $server, 'none' ) );
	}

	public function test_client_ip_cf_mode_returns_cf_header(): void {
		$server = array(
			'REMOTE_ADDR'          => '1.2.3.4',
			'HTTP_CF_CONNECTING_IP' => '9.9.9.9',
		);
		$this->assertSame( '9.9.9.9', SWPS_Crawler_Verification::client_ip( $server, 'cf' ) );
	}

	public function test_client_ip_cf_mode_falls_back_to_remote_addr_when_header_absent(): void {
		$server = array( 'REMOTE_ADDR' => '1.2.3.4' );
		$this->assertSame( '1.2.3.4', SWPS_Crawler_Verification::client_ip( $server, 'cf' ) );
	}

	public function test_client_ip_xff_mode_returns_first_ip(): void {
		$server = array(
			'REMOTE_ADDR'          => '1.2.3.4',
			'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 8.8.8.8',
		);
		$this->assertSame( '9.9.9.9', SWPS_Crawler_Verification::client_ip( $server, 'xff' ) );
	}

	public function test_client_ip_xff_mode_falls_back_to_remote_addr_when_header_absent(): void {
		$server = array( 'REMOTE_ADDR' => '1.2.3.4' );
		$this->assertSame( '1.2.3.4', SWPS_Crawler_Verification::client_ip( $server, 'xff' ) );
	}

	// -------------------------------------------------------------------------
	// should_block — pure enforcement decision
	// -------------------------------------------------------------------------

	public function test_should_block_returns_false_when_master_off(): void {
		$this->assertFalse( SWPS_Crawler_Verification::should_block( 'spoofed', false, true, false ) );
	}

	public function test_should_block_returns_false_when_bot_not_enabled(): void {
		$this->assertFalse( SWPS_Crawler_Verification::should_block( 'spoofed', true, false, false ) );
	}

	public function test_should_block_returns_false_for_unverifiable(): void {
		$this->assertFalse( SWPS_Crawler_Verification::should_block( 'unverifiable', true, true, false ) );
	}

	public function test_should_block_returns_false_for_empty_verdict(): void {
		$this->assertFalse( SWPS_Crawler_Verification::should_block( '', true, true, false ) );
	}

	public function test_should_block_returns_true_for_spoofed_when_enabled(): void {
		$this->assertTrue( SWPS_Crawler_Verification::should_block( 'spoofed', true, true, false ) );
	}

	public function test_should_block_returns_true_for_verified_user_blocked(): void {
		$this->assertTrue( SWPS_Crawler_Verification::should_block( 'verified', true, true, true ) );
	}

	public function test_should_block_returns_false_for_verified_not_user_blocked(): void {
		$this->assertFalse( SWPS_Crawler_Verification::should_block( 'verified', true, true, false ) );
	}

	public function test_should_block_master_off_overrides_spoofed_user_blocked(): void {
		// Even with spoofed + user_blocked, master off = no block.
		$this->assertFalse( SWPS_Crawler_Verification::should_block( 'spoofed', false, true, true ) );
	}
}
