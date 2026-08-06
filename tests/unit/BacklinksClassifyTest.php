<?php
/**
 * TDD tests for SWPS_Backlinks::classify_check() — pure static helper that
 * maps a verification fetch result to a backlink status.
 *
 * Pure-PHP, no WordPress. The class is required for its static method only;
 * the constructor (which needs WP) is never called.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-backlinks.php';

/**
 * @covers SWPS_Backlinks
 */
class BacklinksClassifyTest extends TestCase {

	// -------------------------------------------------------------------------
	// Bot-blocking HTTP codes → blocked (not broken)
	// -------------------------------------------------------------------------

	public function test_403_is_blocked_not_broken(): void {
		// Medium behind Cloudflare: 403 to every non-browser client.
		$this->assertSame( 'blocked', SWPS_Backlinks::classify_check( 403, false, 'medium.com' ) );
	}

	public function test_401_is_blocked(): void {
		$this->assertSame( 'blocked', SWPS_Backlinks::classify_check( 401, false, 'example.com' ) );
	}

	public function test_429_is_blocked(): void {
		$this->assertSame( 'blocked', SWPS_Backlinks::classify_check( 429, false, 'example.com' ) );
	}

	public function test_999_is_blocked(): void {
		// LinkedIn's non-standard bot-rejection code.
		$this->assertSame( 'blocked', SWPS_Backlinks::classify_check( 999, false, 'www.linkedin.com' ) );
	}

	// -------------------------------------------------------------------------
	// Genuine HTTP errors → broken
	// -------------------------------------------------------------------------

	public function test_404_is_broken(): void {
		$this->assertSame( 'broken', SWPS_Backlinks::classify_check( 404, false, 'example.com' ) );
	}

	public function test_410_is_broken(): void {
		$this->assertSame( 'broken', SWPS_Backlinks::classify_check( 410, false, 'example.com' ) );
	}

	public function test_500_is_broken(): void {
		$this->assertSame( 'broken', SWPS_Backlinks::classify_check( 500, false, 'example.com' ) );
	}

	public function test_zero_code_is_broken(): void {
		$this->assertSame( 'broken', SWPS_Backlinks::classify_check( 0, false, 'example.com' ) );
	}

	// -------------------------------------------------------------------------
	// Successful fetch, link present → live
	// -------------------------------------------------------------------------

	public function test_200_with_link_is_live(): void {
		$this->assertSame( 'live', SWPS_Backlinks::classify_check( 200, true, 'example.com' ) );
	}

	public function test_200_with_link_on_authwalled_host_is_live(): void {
		// Link found wins even on a login-walled platform.
		$this->assertSame( 'live', SWPS_Backlinks::classify_check( 200, true, 'www.linkedin.com' ) );
	}

	// -------------------------------------------------------------------------
	// Successful fetch, link absent → lost, unless the host is login-walled
	// -------------------------------------------------------------------------

	public function test_200_without_link_is_lost(): void {
		$this->assertSame( 'lost', SWPS_Backlinks::classify_check( 200, false, 'example.com' ) );
	}

	public function test_200_without_link_on_linkedin_is_blocked(): void {
		// LinkedIn serves its sign-in wall to anonymous fetches: 200 but the
		// post content (and any link in it) is never in the HTML.
		$this->assertSame( 'blocked', SWPS_Backlinks::classify_check( 200, false, 'linkedin.com' ) );
	}

	public function test_200_without_link_on_linkedin_subdomain_is_blocked(): void {
		$this->assertSame( 'blocked', SWPS_Backlinks::classify_check( 200, false, 'www.linkedin.com' ) );
	}

	public function test_200_without_link_on_facebook_is_blocked(): void {
		$this->assertSame( 'blocked', SWPS_Backlinks::classify_check( 200, false, 'facebook.com' ) );
	}

	public function test_lookalike_host_is_not_treated_as_authwalled(): void {
		// Suffix matching must not catch unrelated domains.
		$this->assertSame( 'lost', SWPS_Backlinks::classify_check( 200, false, 'notlinkedin.com' ) );
	}
}
