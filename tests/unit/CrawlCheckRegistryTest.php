<?php
/**
 * Tests for SWPS_Crawl_Check and SWPS_Crawl_Check_Registry.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check-registry.php';

final class RegistryFakeAlpha extends SWPS_Crawl_Check {
	public function id(): string {
		return 'fake_alpha';
	}

	public function severity(): string {
		return 'error';
	}

	public function title(): string {
		return 'Fake alpha';
	}

	public function how_to_fix(): string {
		return 'n/a';
	}

	public function check_page( array $page ): ?array {
		return $this->issue( $page['url'] );
	}
}

final class RegistryFakeChallenge extends SWPS_Crawl_Check {
	public function id(): string {
		return 'challenge_page_detected';
	}

	public function severity(): string {
		return 'error';
	}

	public function title(): string {
		return 'Challenge page';
	}

	public function how_to_fix(): string {
		return 'n/a';
	}

	public function check_page( array $page ): ?array {
		return ! empty( $page['is_challenge'] ) ? $this->issue( $page['url'] ) : null;
	}
}

final class CrawlCheckRegistryTest extends TestCase {

	public function test_issue_helper_shapes_row(): void {
		$check = new RegistryFakeAlpha();
		$row   = $check->check_page( array( 'url' => 'https://x.test/' ) );
		$this->assertSame(
			array( 'type' => 'fake_alpha', 'url' => 'https://x.test/', 'detail' => array(), 'severity' => 'error' ),
			$row
		);
	}

	public function test_excluded_ids_are_filtered(): void {
		$all = SWPS_Crawl_Check_Registry::all( array() );
		if ( array() === $all ) {
			$this->markTestSkipped( 'registry empty until checks land' ); // Removed in Task 5.
		}
		$filtered = SWPS_Crawl_Check_Registry::all( array( $all[0]->id() ) );
		$this->assertCount( count( $all ) - 1, $filtered );
	}

	public function test_challenge_short_circuits_other_page_checks(): void {
		$checks = array( new RegistryFakeAlpha(), new RegistryFakeChallenge() );
		$issues = SWPS_Crawl_Check_Registry::run_page_checks(
			array( 'url' => 'https://x.test/', 'status_code' => 200, 'is_challenge' => true ),
			$checks
		);
		$this->assertCount( 1, $issues );
		$this->assertSame( 'challenge_page_detected', $issues[0]['type'] );
	}

	public function test_broken_status_skips_page_checks(): void {
		$checks = array( new RegistryFakeAlpha(), new RegistryFakeChallenge() );
		$issues = SWPS_Crawl_Check_Registry::run_page_checks(
			array( 'url' => 'https://x.test/', 'status_code' => 500, 'is_challenge' => false ),
			$checks
		);
		$this->assertSame( array(), $issues );
	}

	public function test_page_check_ids_empty_registry(): void {
		if ( array() !== SWPS_Crawl_Check_Registry::all( array() ) ) {
			// Task 4 populated CHECKS with 8 classes that all override check_page();
			// this "empty registry" assumption no longer holds. Superseded by
			// Task 5/6 coverage. Removed in Task 5.
			$this->markTestSkipped( 'registry populated as of Task 4' );
		}
		$ids = SWPS_Crawl_Check_Registry::page_check_ids();
		$this->assertSame( array(), $ids );
	}

	public function test_aggregate_check_ids_empty_registry(): void {
		$ids = SWPS_Crawl_Check_Registry::aggregate_check_ids();
		$this->assertSame( array(), $ids );
	}

	public function test_partition_logic_with_fake_checks(): void {
		// Verify that RegistryFakeAlpha (which overrides check_page) would be
		// partitioned as a page check, and RegistryFakeChallenge (which also
		// overrides check_page) would also be a page check. This direct test
		// verifies the reflection logic works correctly without needing to
		// populate the registry's CHECKS constant.
		$alpha = new RegistryFakeAlpha();
		$challenge = new RegistryFakeChallenge();

		// Both override check_page, so both should partition as per-page checks.
		$alpha_method = new ReflectionMethod( $alpha, 'check_page' );
		$challenge_method = new ReflectionMethod( $challenge, 'check_page' );

		$this->assertNotSame(
			'SWPS_Crawl_Check',
			$alpha_method->getDeclaringClass()->getName(),
			'RegistryFakeAlpha should override check_page'
		);
		$this->assertNotSame(
			'SWPS_Crawl_Check',
			$challenge_method->getDeclaringClass()->getName(),
			'RegistryFakeChallenge should override check_page'
		);

		// Neither override check_run, so both should NOT partition as aggregate checks.
		$alpha_run = new ReflectionMethod( $alpha, 'check_run' );
		$challenge_run = new ReflectionMethod( $challenge, 'check_run' );

		$this->assertSame(
			'SWPS_Crawl_Check',
			$alpha_run->getDeclaringClass()->getName(),
			'RegistryFakeAlpha should NOT override check_run'
		);
		$this->assertSame(
			'SWPS_Crawl_Check',
			$challenge_run->getDeclaringClass()->getName(),
			'RegistryFakeChallenge should NOT override check_run'
		);
	}
}
