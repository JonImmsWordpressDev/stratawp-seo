<?php
/**
 * Tests for SWPS_Crawl_Abilities (Site Crawl abilities) with fake dependencies.
 *
 * Pure-PHP, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-site-crawler.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer-registry.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-meta-title.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-meta-description.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-image-alt.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-mixed-content.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-nofollow.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-sitemap-exclude.php';
require_once __DIR__ . '/../../includes/class-crawl-abilities.php';
require_once __DIR__ . '/../../includes/trait-ability-defs.php';
require_once __DIR__ . '/../../includes/trait-crawl-ability-defs.php';
require_once __DIR__ . '/../../includes/class-abilities.php';

/**
 * @covers SWPS_Crawl_Abilities
 */
class CrawlAbilitiesTest extends TestCase {

	/** Calls recorded by fake dependencies. */
	private array $calls = array();

	/**
	 * Two issue groups for completed run 200: a fixable noindex_in_sitemap row,
	 * an already-fixed one, and a broken_link row (no fixer).
	 */
	private function issues(): array {
		return array(
			'noindex_in_sitemap' => array(
				array( 'id' => 11, 'type' => 'noindex_in_sitemap', 'url' => 'https://example.com/a/', 'detail' => array( 'post_id' => 5 ), 'severity' => 'warning', 'post_id' => 0, 'object_type' => 'post', 'object_id' => 5 ),
				array( 'id' => 12, 'type' => 'noindex_in_sitemap', 'url' => 'https://example.com/b/', 'detail' => array( 'post_id' => 6, 'fixed_at' => 1700000000 ), 'severity' => 'warning', 'post_id' => 0, 'object_type' => 'post', 'object_id' => 6 ),
			),
			'broken_link'        => array(
				array( 'id' => 13, 'type' => 'broken_link', 'url' => 'https://example.com/gone/', 'detail' => array( 'status' => 404 ), 'severity' => 'error', 'post_id' => 0, 'object_type' => 'none', 'object_id' => 0 ),
			),
		);
	}

	private function make( array $overrides = array() ): SWPS_Crawl_Abilities {
		$deps = array(
			'can'        => fn(): bool => true,
			'state'      => fn(): array => array( 'run_id' => 200, 'status' => 'complete' ),
			'start'      => function (): int {
				$this->calls[] = 'start';
				return 300;
			},
			'run_ids'    => fn(): array => array( 200, 100 ),
			'issues'     => function ( int $run_id ): array {
				$this->calls[] = "issues:{$run_id}";
				return 200 === $run_id ? $this->issues() : array();
			},
			'apply'      => function ( int $run_id, string $check, int $issue_id ): array {
				$this->calls[] = "apply:{$run_id}:{$check}:{$issue_id}";
				return array(
					'fixed'         => array( array( 'issue_id' => $issue_id, 'message' => 'Excluded from the sitemap.' ) ),
					'skipped'       => array(),
					'remaining_ids' => array(),
				);
			},
			'home_host'  => fn(): string => 'example.com',
			'permalink'  => fn( int $id ): string => 5 === $id ? 'https://example.com/a/' : '',
			'url_to_id'  => fn( string $url ): int => 'https://example.com/a/' === $url ? 5 : 0,
			'fetch'      => fn( string $url ): array => array(
				'status'   => 200,
				'body'     => '<html><head><link rel="canonical" href="' . $url . '"><meta name="robots" content="max-image-preview:large"></head><body></body></html>',
				'x_robots' => '',
				'location' => '',
			),
			'in_sitemap' => fn( string $url, int $post_id ): bool => 5 === $post_id,
		);
		return new SWPS_Crawl_Abilities( array_merge( $deps, $overrides ) );
	}

	private function denied(): array {
		return array( 'can' => fn(): bool => false );
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public function test_crawl_abilities_are_registered_with_write_flags(): void {
		$defs = array();
		foreach ( ( new SWPS_Abilities() )->get_defs() as $def ) {
			$defs[ $def['name'] ] = $def;
		}
		$this->assertTrue( $defs['stratawp-seo/run-site-crawl']['write'] );
		$this->assertFalse( $defs['stratawp-seo/get-crawl-issues']['write'] );
		$this->assertTrue( $defs['stratawp-seo/apply-crawl-fix']['write'] );
		$this->assertFalse( $defs['stratawp-seo/get-canonical-status']['write'] );
		$this->assertSame( array( 'issue_id', 'fixer' ), $defs['stratawp-seo/apply-crawl-fix']['input_schema']['required'] );
		$this->assertCount( 17, $defs );
	}

	// -------------------------------------------------------------------------
	// run-site-crawl
	// -------------------------------------------------------------------------

	public function test_run_crawl_queues_and_returns_crawl_id(): void {
		$this->assertSame( array( 'status' => 'queued', 'crawl_id' => 300 ), $this->make()->run_crawl( array() ) );
		$this->assertSame( array( 'start' ), $this->calls );
	}

	public function test_run_crawl_refuses_while_a_crawl_is_running(): void {
		$result = $this->make( array( 'state' => fn(): array => array( 'run_id' => 250, 'status' => 'running' ) ) )->run_crawl( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swps_crawl_running', $result->get_error_code() );
		$this->assertStringContainsString( '250', $result->get_error_message() );
		$this->assertSame( array(), $this->calls );
	}

	public function test_run_crawl_permission_denied(): void {
		$result = $this->make( $this->denied() )->run_crawl( array() );
		$this->assertSame( 'swps_ability_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	// -------------------------------------------------------------------------
	// get-crawl-issues
	// -------------------------------------------------------------------------

	public function test_get_issues_groups_latest_run_with_fixers(): void {
		$result = $this->make()->get_issues( array() );

		$this->assertSame( 200, $result['crawl_id'] );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 1, $result['total_pages'] );
		$this->assertSame( array( 'broken_link', 'noindex_in_sitemap' ), array_keys( $result['groups'] ) );

		$noindex = $result['groups']['noindex_in_sitemap'];
		$this->assertSame( 2, $noindex['count'] );
		$this->assertSame( 'warning', $noindex['severity'] );
		$this->assertNotSame( 'noindex_in_sitemap', $noindex['title'], 'uses the check title' );
		$this->assertSame(
			array( 'issue_id' => 11, 'url' => 'https://example.com/a/', 'post_id' => 5, 'fixed' => false, 'fixers' => array( 'sitemap-exclude' ), 'detail' => array( 'post_id' => 5 ) ),
			$noindex['issues'][0]
		);
		$this->assertSame( array(), $noindex['issues'][1]['fixers'], 'a fixed issue offers no fixer' );
		$this->assertTrue( $noindex['issues'][1]['fixed'] );
		$this->assertSame( array(), $result['groups']['broken_link']['issues'][0]['fixers'] );
	}

	public function test_get_issues_filters_by_check_and_pages(): void {
		$abilities = $this->make();

		$page1 = $abilities->get_issues( array( 'check' => 'noindex_in_sitemap', 'per_page' => 1 ) );
		$this->assertSame( 2, $page1['total'] );
		$this->assertSame( 2, $page1['total_pages'] );
		$this->assertSame( array( 'noindex_in_sitemap' ), array_keys( $page1['groups'] ) );
		$this->assertSame( 11, $page1['groups']['noindex_in_sitemap']['issues'][0]['issue_id'] );
		$this->assertSame( 2, $page1['groups']['noindex_in_sitemap']['count'], 'count is the full group size' );

		$page2 = $abilities->get_issues( array( 'check' => 'noindex_in_sitemap', 'page' => 2, 'per_page' => 1 ) );
		$this->assertSame( 12, $page2['groups']['noindex_in_sitemap']['issues'][0]['issue_id'] );

		$past = $abilities->get_issues( array( 'page' => 9 ) );
		$this->assertSame( array(), $past['groups'] );
	}

	public function test_get_issues_skips_a_run_in_progress(): void {
		$abilities = $this->make(
			array(
				'state'   => fn(): array => array( 'run_id' => 300, 'status' => 'running' ),
				'run_ids' => fn(): array => array( 300, 200 ),
			)
		);
		$this->assertSame( 200, $abilities->get_issues( array() )['crawl_id'] );
	}

	/**
	 * @dataProvider bad_issue_inputs
	 */
	public function test_get_issues_bad_input( array $input, string $code ): void {
		$result = $this->make()->get_issues( $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public static function bad_issue_inputs(): array {
		return array(
			'unknown check' => array( array( 'check' => 'nope' ), 'swps_unknown_check' ),
			'page 0'        => array( array( 'page' => 0 ), 'swps_invalid_paging' ),
			'per_page 0'    => array( array( 'per_page' => 0 ), 'swps_invalid_paging' ),
			'per_page 101'  => array( array( 'per_page' => 101 ), 'swps_invalid_paging' ),
		);
	}

	public function test_get_issues_without_a_completed_crawl(): void {
		$result = $this->make( array( 'run_ids' => fn(): array => array() ) )->get_issues( array() );
		$this->assertSame( 'swps_no_crawl', $result->get_error_code() );
	}

	public function test_get_issues_permission_denied(): void {
		$result = $this->make( $this->denied() )->get_issues( array() );
		$this->assertSame( 'swps_ability_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	// -------------------------------------------------------------------------
	// apply-crawl-fix
	// -------------------------------------------------------------------------

	public function test_apply_fix_goes_through_the_fixit_controller(): void {
		$result = $this->make()->apply_fix( array( 'issue_id' => 11, 'fixer' => 'sitemap-exclude' ) );
		$this->assertSame(
			array( 'issue_id' => 11, 'check' => 'noindex_in_sitemap', 'fixer' => 'sitemap-exclude', 'changed' => true, 'message' => 'Excluded from the sitemap.' ),
			$result
		);
		$this->assertContains( 'apply:200:noindex_in_sitemap:11', $this->calls );
	}

	public function test_apply_fix_reports_a_skip(): void {
		$abilities = $this->make(
			array(
				'apply' => fn(): array => array(
					'fixed'         => array(),
					'skipped'       => array( array( 'issue_id' => 11, 'reason' => 'The post no longer exists.' ) ),
					'remaining_ids' => array(),
				),
			)
		);
		$result    = $abilities->apply_fix( array( 'issue_id' => 11, 'fixer' => 'sitemap-exclude' ) );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( 'The post no longer exists.', $result['message'] );
	}

	/**
	 * @dataProvider bad_fix_inputs
	 */
	public function test_apply_fix_bad_input( array $input, string $code ): void {
		$result = $this->make()->apply_fix( $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array(), array_filter( $this->calls, static fn( $c ) => str_starts_with( $c, 'apply:' ) ), 'nothing applied' );
	}

	public static function bad_fix_inputs(): array {
		return array(
			'zero id'       => array( array( 'issue_id' => 0, 'fixer' => 'sitemap-exclude' ), 'swps_invalid_issue' ),
			'unknown issue' => array( array( 'issue_id' => 99, 'fixer' => 'sitemap-exclude' ), 'swps_issue_not_found' ),
			'wrong fixer'   => array( array( 'issue_id' => 11, 'fixer' => 'meta-title' ), 'swps_fixer_mismatch' ),
			'no fixer'      => array( array( 'issue_id' => 13, 'fixer' => 'sitemap-exclude' ), 'swps_fixer_mismatch' ),
			'already fixed' => array( array( 'issue_id' => 12, 'fixer' => 'sitemap-exclude' ), 'swps_already_fixed' ),
		);
	}

	public function test_apply_fix_permission_denied(): void {
		$result = $this->make( $this->denied() )->apply_fix( array( 'issue_id' => 11, 'fixer' => 'sitemap-exclude' ) );
		$this->assertSame( 'swps_ability_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	// -------------------------------------------------------------------------
	// get-canonical-status
	// -------------------------------------------------------------------------

	public function test_canonical_status_for_post_id(): void {
		$result = $this->make()->canonical_status( array( 'post_id' => 5 ) );
		$this->assertSame(
			array(
				'url'            => 'https://example.com/a/',
				'post_id'        => 5,
				'status'         => 200,
				'redirect_to'    => '',
				'canonical'      => 'https://example.com/a/',
				'self_canonical' => true,
				'robots'         => array( 'max-image-preview:large' ),
				'indexable'      => true,
				'in_sitemap'     => true,
			),
			$result
		);
	}

	public function test_canonical_status_for_noindexed_url_pointing_elsewhere(): void {
		$abilities = $this->make(
			array(
				'fetch' => fn(): array => array(
					'status'   => 200,
					'body'     => '<html><head><link rel="canonical" href="https://example.com/other/"><meta name="robots" content="noindex, follow"></head></html>',
					'x_robots' => 'noarchive',
					'location' => '',
				),
			)
		);
		$result    = $abilities->canonical_status( array( 'url' => 'https://example.com/c/' ) );
		$this->assertSame( 0, $result['post_id'] );
		$this->assertSame( 'https://example.com/other/', $result['canonical'] );
		$this->assertFalse( $result['self_canonical'] );
		$this->assertSame( array( 'noindex', 'follow', 'noarchive' ), $result['robots'] );
		$this->assertFalse( $result['indexable'] );
		$this->assertFalse( $result['in_sitemap'] );
	}

	public function test_canonical_status_reports_a_redirect(): void {
		$abilities = $this->make(
			array(
				'fetch' => fn(): array => array(
					'status'   => 301,
					'body'     => '',
					'x_robots' => '',
					'location' => 'https://example.com/new/',
				),
			)
		);
		$result    = $abilities->canonical_status( array( 'url' => 'https://example.com/old/' ) );
		$this->assertSame( 301, $result['status'] );
		$this->assertSame( 'https://example.com/new/', $result['redirect_to'] );
		$this->assertNull( $result['canonical'] );
		$this->assertNull( $result['self_canonical'] );
		$this->assertFalse( $result['indexable'] );
	}

	/**
	 * @dataProvider bad_canonical_inputs
	 */
	public function test_canonical_status_bad_input( array $input, string $code ): void {
		$fetched = false;
		$result  = $this->make(
			array(
				'fetch' => function () use ( &$fetched ): array {
					$fetched = true;
					return array();
				},
			)
		)->canonical_status( $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertFalse( $fetched, 'nothing fetched' );
	}

	public static function bad_canonical_inputs(): array {
		return array(
			'neither'        => array( array(), 'swps_invalid_target' ),
			'both'           => array( array( 'url' => 'https://example.com/a/', 'post_id' => 5 ), 'swps_invalid_target' ),
			'external url'   => array( array( 'url' => 'https://evil.test/' ), 'swps_external_url' ),
			'internal ip'    => array( array( 'url' => 'http://169.254.169.254/latest/' ), 'swps_external_url' ),
			'not http'       => array( array( 'url' => 'file:///etc/passwd' ), 'swps_external_url' ),
			'missing post'   => array( array( 'post_id' => 77 ), 'swps_post_not_found' ),
		);
	}

	public function test_canonical_status_permission_denied(): void {
		$result = $this->make( $this->denied() )->canonical_status( array( 'post_id' => 5 ) );
		$this->assertSame( 'swps_ability_forbidden', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Pure helpers
	// -------------------------------------------------------------------------

	public function test_fixer_ids_are_kebab_case_class_names(): void {
		$this->assertSame( 'sitemap-exclude', SWPS_Crawl_Abilities::fixer_id( new SWPS_Fixer_Sitemap_Exclude() ) );
		$this->assertSame( 'meta-title', SWPS_Crawl_Abilities::fixer_id( new SWPS_Fixer_Meta_Title() ) );
	}

	public function test_page_facts_without_canonical(): void {
		$facts = SWPS_Crawl_Abilities::page_facts( '<html><head><title>x</title></head></html>', 'https://example.com/a/', '', 'example.com' );
		$this->assertSame( array( 'canonical' => null, 'self_canonical' => null, 'robots' => array() ), $facts );
	}

	public function test_page_facts_self_canonical_ignores_trailing_slash(): void {
		$facts = SWPS_Crawl_Abilities::page_facts( '<html><head><link rel="canonical" href="https://example.com/a"></head></html>', 'https://example.com/a/', '', 'example.com' );
		$this->assertTrue( $facts['self_canonical'] );
	}
}
