# Site Audit Fix-It Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Site Audit findings one-click fixable — AI-drafted meta title/description fixes reviewed and applied from the dashboard, plus direct mechanical fixes (mixed content, nofollow, alt text, sitemap exclusion) with undo.

**Architecture:** A pure URL→WP-object resolver lets the crawler stamp every issue with its target object (new DB columns). A fixer registry (mirroring the check registry) maps check ids to fixer classes implementing draft/apply/undo. Drafts and undo snapshots live in post/term meta following the proven AEO pattern. A controller exposes chunked AJAX verbs; the Site Audit screen gains server-rendered Fix buttons and review tables with a small vanilla-JS driver.

**Tech Stack:** WordPress plugin PHP 8.0+, wpdb/dbDelta, PHPUnit 9 (WP-free unit tests via `tests/bootstrap.php` stubs), PHPStan level per repo config, vanilla JS.

**Spec:** `docs/superpowers/specs/2026-08-21-audit-fix-it-engine-design.md`

## Global Constraints

- Version bump to **4.28.0** in `stratawp-seo.php` (header + `SWPS_VERSION`) and `readme.txt` (`Stable tag:` + changelog) — Task 13 only.
- **No runtime autoloader**: every new class file MUST get a `require_once` line in `stratawp-seo.php`. PHPStan will not catch a missing require (runtime-only fatal).
- Run `composer dump-autoload` after creating new class files (the composer classmap feeds PHPStan and tests).
- Text domain `stratawp-seo`; WP coding style (tabs, Yoda conditions, `esc_html__`/`esc_attr`); prefix everything `SWPS_`/`swps_`/`_swps_`.
- Unit tests do NOT load WordPress. `tests/bootstrap.php` provides stubs; tests `require_once` their class files by relative path. Pure static helpers only — anything WP-coupled is smoke-tested (Task 14).
- JSON stored in meta MUST be `wp_slash()`-wrapped before `update_post_meta`/`update_term_meta` (see `SWPS_AEO_Optimizer` comments at `includes/class-aeo-optimizer.php:446`).
- Commits authored as `Jon Imms <60996163+JonImmsWordpressDev@users.noreply.github.com>`, committer identical, no AI attribution of any kind.
- Verification for every task: `vendor/bin/phpunit` green, `vendor/bin/phpstan analyse --memory-limit=2G` no errors, `vendor/bin/phpcs <new files>` clean for new production files.

---

### Task 1: URL → WP object resolver (`SWPS_Crawl_Target`)

**Files:**
- Create: `includes/class-crawl-target.php`
- Modify: `stratawp-seo.php` (require_once, next to the other crawl requires)
- Test: `tests/unit/CrawlTargetTest.php`

**Interfaces:**
- Produces: `SWPS_Crawl_Target::normalize( string $url ): string` (pure); `SWPS_Crawl_Target::match( string $normalized, array $maps ): array{object_type: string, object_id: int}` (pure); `SWPS_Crawl_Target::resolve( string $url ): array{object_type: string, object_id: int}` (WP-coupled, per-request cached). `object_type` is one of `'post' | 'term' | 'user' | 'none'`.
- Consumes: nothing from other tasks.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Tests for SWPS_Crawl_Target pure helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-crawl-target.php';

final class CrawlTargetTest extends TestCase {

	public function test_normalize_strips_pagination_path(): void {
		$this->assertSame(
			'https://example.com/blog/',
			SWPS_Crawl_Target::normalize( 'https://example.com/blog/page/3/' )
		);
	}

	public function test_normalize_strips_paged_query_and_fragment(): void {
		$this->assertSame(
			'https://example.com/blog/',
			SWPS_Crawl_Target::normalize( 'https://example.com/blog/?paged=2#top' )
		);
	}

	public function test_normalize_preserves_other_query_args(): void {
		$this->assertSame(
			'https://example.com/?p=42',
			SWPS_Crawl_Target::normalize( 'https://example.com/?p=42' )
		);
	}

	public function test_normalize_adds_trailing_slash_to_paths(): void {
		$this->assertSame(
			'https://example.com/about/',
			SWPS_Crawl_Target::normalize( 'https://example.com/about' )
		);
	}

	public function test_match_finds_post(): void {
		$maps = array(
			'posts' => array( 'https://example.com/hello-world/' => 7 ),
			'terms' => array(),
			'users' => array(),
		);
		$this->assertSame(
			array( 'object_type' => 'post', 'object_id' => 7 ),
			SWPS_Crawl_Target::match( 'https://example.com/hello-world/', $maps )
		);
	}

	public function test_match_finds_term(): void {
		$maps = array(
			'posts' => array(),
			'terms' => array( 'https://example.com/category/news/' => 12 ),
			'users' => array(),
		);
		$this->assertSame(
			array( 'object_type' => 'term', 'object_id' => 12 ),
			SWPS_Crawl_Target::match( 'https://example.com/category/news/', $maps )
		);
	}

	public function test_match_finds_user(): void {
		$maps = array(
			'posts' => array(),
			'terms' => array(),
			'users' => array( 'https://example.com/author/jon/' => 3 ),
		);
		$this->assertSame(
			array( 'object_type' => 'user', 'object_id' => 3 ),
			SWPS_Crawl_Target::match( 'https://example.com/author/jon/', $maps )
		);
	}

	public function test_match_unknown_url_is_none(): void {
		$maps = array( 'posts' => array(), 'terms' => array(), 'users' => array() );
		$this->assertSame(
			array( 'object_type' => 'none', 'object_id' => 0 ),
			SWPS_Crawl_Target::match( 'https://example.com/mystery/', $maps )
		);
	}

	public function test_match_normalizes_paginated_term_archive(): void {
		$maps = array(
			'posts' => array(),
			'terms' => array( 'https://example.com/category/news/' => 12 ),
			'users' => array(),
		);
		$this->assertSame(
			array( 'object_type' => 'term', 'object_id' => 12 ),
			SWPS_Crawl_Target::match(
				SWPS_Crawl_Target::normalize( 'https://example.com/category/news/page/2/' ),
				$maps
			)
		);
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter CrawlTargetTest`
Expected: FAIL — "Failed to open stream: No such file" (class file missing).

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Resolves a crawled URL to the WordPress object that renders it.
 *
 * The crawler stamps every issue row with {object_type, object_id} so the
 * Fix-It engine knows what to write to. normalize() and match() are pure
 * (unit-tested); resolve() is the WP-coupled runtime entry point with a
 * per-request cache of the term/author URL maps.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL → WP object resolution for crawl issues.
 */
class SWPS_Crawl_Target {

	/** No-object result shared by every miss path. */
	private const NONE = array(
		'object_type' => 'none',
		'object_id'   => 0,
	);

	/** Per-request cache: normalized URL → resolved target. */
	private static array $cache = array();

	/** Per-request lazy-built term/user URL maps (null until first use). */
	private static ?array $maps = null;

	/**
	 * Canonicalize a URL for map lookups: drop /page/N/ and ?paged=N
	 * pagination, fragments, and add a trailing slash to bare paths.
	 *
	 * @param string $url Absolute URL.
	 * @return string Normalized URL.
	 */
	public static function normalize( string $url ): string {
		$url = preg_replace( '#/page/\d+/?($|\?)#', '/$1', $url );
		$url = preg_replace( '#([?&])paged?=\d+&?#', '$1', (string) $url );
		$url = rtrim( (string) $url, '?&' );

		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return $url;
		}

		$path = $parts['path'] ?? '/';
		if ( '' === $path ) {
			$path = '/';
		}
		if ( ! str_ends_with( $path, '/' ) && '' === pathinfo( $path, PATHINFO_EXTENSION ) ) {
			$path .= '/';
		}

		$rebuilt = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . $path;
		if ( ! empty( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}

		return $rebuilt;
	}

	/**
	 * Pure lookup of a normalized URL against pre-built object maps.
	 *
	 * @param string $normalized normalize()d URL.
	 * @param array  $maps       {posts: [url => id], terms: [url => id], users: [url => id]}.
	 * @return array{object_type: string, object_id: int}
	 */
	public static function match( string $normalized, array $maps ): array {
		foreach ( array( 'post' => 'posts', 'term' => 'terms', 'user' => 'users' ) as $type => $key ) {
			$id = $maps[ $key ][ $normalized ] ?? 0;
			if ( $id > 0 ) {
				return array(
					'object_type' => $type,
					'object_id'   => (int) $id,
				);
			}
		}

		return self::NONE;
	}

	/**
	 * Resolve a crawled URL to its WP object. Cached per request.
	 *
	 * Posts resolve via url_to_postid() (cheap, core-cached); term and
	 * author archives via lazily-built URL maps since core has no reverse
	 * lookup for them.
	 *
	 * @param string $url Crawled URL.
	 * @return array{object_type: string, object_id: int}
	 */
	public static function resolve( string $url ): array {
		$normalized = self::normalize( $url );

		if ( isset( self::$cache[ $normalized ] ) ) {
			return self::$cache[ $normalized ];
		}

		$post_id = url_to_postid( $normalized );
		if ( $post_id > 0 ) {
			self::$cache[ $normalized ] = array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			);
			return self::$cache[ $normalized ];
		}

		$result                     = self::match( $normalized, self::maps() );
		self::$cache[ $normalized ] = $result;

		return $result;
	}

	/**
	 * Build the term/user URL maps once per request.
	 *
	 * @return array{posts: array, terms: array, users: array}
	 */
	private static function maps(): array {
		if ( null !== self::$maps ) {
			return self::$maps;
		}

		$terms = array();
		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$term_objects = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 1000,
				)
			);
			if ( is_wp_error( $term_objects ) ) {
				continue;
			}
			foreach ( $term_objects as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$terms[ self::normalize( (string) $link ) ] = (int) $term->term_id;
				}
			}
		}

		$users = array();
		foreach ( get_users( array( 'has_published_posts' => true, 'number' => 500 ) ) as $user ) {
			$users[ self::normalize( get_author_posts_url( (int) $user->ID ) ) ] = (int) $user->ID;
		}

		self::$maps = array(
			'posts' => array(),
			'terms' => $terms,
			'users' => $users,
		);

		return self::$maps;
	}

	/**
	 * Drop the per-request caches (used between crawl chunks in long-running
	 * CLI contexts and by tests).
	 */
	public static function flush_cache(): void {
		self::$cache = array();
		self::$maps  = null;
	}
}
```

Note: `str_ends_with` is PHP 8.0 native — fine (plugin floor is 8.0). If `tests/bootstrap.php` lacks stubs for `get_taxonomies` etc. that is fine — tests only call `normalize()`/`match()`, and PHP only needs the functions at call time.

- [ ] **Step 4: Add the require to `stratawp-seo.php`**

Locate the crawl requires (search for `class-site-crawler.php`) and add directly above it:

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-crawl-target.php';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer dump-autoload && vendor/bin/phpunit --filter CrawlTargetTest`
Expected: PASS (8 tests).

- [ ] **Step 6: Full verification and commit**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress && vendor/bin/phpcs includes/class-crawl-target.php`
Expected: all green.

```bash
git add includes/class-crawl-target.php tests/unit/CrawlTargetTest.php stratawp-seo.php
git commit -m "Add SWPS_Crawl_Target URL-to-object resolver"
```

---

### Task 2: Issues table v4 — object target columns

**Files:**
- Modify: `includes/class-crawl-issues.php` (DDL ~line 117, `DB_VERSION` line 28, `insert_issue` line 179, `issues_for_run` line ~437)

**Interfaces:**
- Consumes: nothing (Task 1 is independent).
- Produces: `SWPS_Crawl_Issues::insert_issue( int $run_id, string $type, string $url, array $detail, string $severity = 'warning', ?int $post_id = null, ?array $target = null ): void` where `$target = array( 'object_type' => string, 'object_id' => int )`; `issues_for_run()` rows now include `object_type` (string) and `object_id` (int) keys; new `SWPS_Crawl_Issues::mark_fixed( int $issue_id ): void`.

- [ ] **Step 1: Bump the schema version and DDL**

In `includes/class-crawl-issues.php`:
- Change `public const DB_VERSION = '3';` → `'4'`.
- In `$sql_issues`, after the `post_id` line, add:

```php
			object_type    VARCHAR(10)                 DEFAULT NULL,
			object_id      BIGINT UNSIGNED             DEFAULT NULL,
```

dbDelta adds columns in place; no data migration (v3 rows keep NULL targets and are simply not fixable — runs prune to 5 anyway). No pre-migration truncation needed for v4: plain column adds cannot collide the way the v3 unique-key change did.

- [ ] **Step 2: Extend `insert_issue`**

Replace the signature and the insert arrays:

```php
	public static function insert_issue(
		int $run_id,
		string $type,
		string $url,
		array $detail,
		string $severity = 'warning',
		?int $post_id = null,
		?array $target = null
	): void {
		global $wpdb;

		$first_seen = self::get_first_seen_run( $type, $url, $run_id );
		$table      = $wpdb->prefix . self::TABLE_ISSUES;

		$object_type = null;
		$object_id   = null;
		if ( is_array( $target ) && ! empty( $target['object_type'] ) && 'none' !== $target['object_type'] ) {
			$object_type = (string) $target['object_type'];
			$object_id   = (int) ( $target['object_id'] ?? 0 );
			// Back-fill the legacy column so older readers keep working.
			if ( 'post' === $object_type && null === $post_id ) {
				$post_id = $object_id;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'run_id'         => $run_id,
				'type'           => $type,
				'url'            => $url,
				'detail'         => wp_json_encode( $detail ),
				'severity'       => $severity,
				'post_id'        => $post_id,
				'object_type'    => $object_type,
				'object_id'      => $object_id,
				'first_seen_run' => $first_seen,
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				null === $post_id ? null : '%d',
				null === $object_type ? null : '%s',
				null === $object_id ? null : '%d',
				'%d',
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
```

- [ ] **Step 3: Extend `issues_for_run` and add `mark_fixed`**

In `issues_for_run()`'s SELECT, add `object_type, object_id` after `post_id`, and where each row is shaped/decoded, add:

```php
				'object_type' => (string) ( $row['object_type'] ?? '' ),
				'object_id'   => (int) ( $row['object_id'] ?? 0 ),
```

(match the existing row-array construction style in that method — keys sit beside `post_id`).

Add below `issues_for_run()`:

```php
	/**
	 * Stamp an issue row's detail JSON with fixed_at so the dashboard can
	 * render it as resolved-pending-recrawl. The row itself is kept — the
	 * next crawl is the source of truth.
	 *
	 * @param int $issue_id Issue row ID.
	 */
	public static function mark_fixed( int $issue_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_ISSUES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT detail FROM {$table} WHERE id = %d", $issue_id ) );
		if ( null === $raw ) {
			return;
		}

		$detail             = is_array( json_decode( (string) $raw, true ) ) ? json_decode( (string) $raw, true ) : array();
		$detail['fixed_at'] = time();

		$wpdb->update(
			$table,
			array( 'detail' => wp_json_encode( $detail ) ),
			array( 'id' => $issue_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
```

- [ ] **Step 4: Verify and commit**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress`
Expected: green (this task has no WP-free-testable surface; schema exercised in Task 14 smoke).

```bash
git add includes/class-crawl-issues.php
git commit -m "Crawl issues schema v4: polymorphic object target columns"
```

---

### Task 3: Crawler stamps issue targets

**Files:**
- Modify: `includes/class-site-crawler.php` — the three `insert_issue` call sites (lines ~776, ~1100, ~1162)

**Interfaces:**
- Consumes: `SWPS_Crawl_Target::resolve()` (Task 1), `insert_issue(..., ?array $target)` (Task 2).
- Produces: every new issue row carries `object_type`/`object_id` when the URL maps to a WP object.

- [ ] **Step 1: Per-page call site (~line 776)**

In `process_chunk()`, immediately before the `foreach ( self::classify( ... ) )` loop, add:

```php
			$target = SWPS_Crawl_Target::resolve( $url );
```

and extend the call:

```php
				SWPS_Crawl_Issues::insert_issue(
					$run_id,
					(string) $issue['type'],
					(string) $issue['url'],
					(array) ( $issue['detail'] ?? array() ),
					(string) ( $issue['severity'] ?? 'warning' ),
					null,
					$target
				);
```

- [ ] **Step 2: External-link call site (~line 1100) and aggregate call site (~line 1162)**

Both loops insert issues whose `url` is a crawled page URL. In each, resolve per issue URL (the resolver's per-request cache makes repeats free):

```php
				SWPS_Crawl_Issues::insert_issue(
					$run_id,
					$issue['type'],
					$issue['url'],
					$issue['detail'],
					$issue['severity'],
					null,
					SWPS_Crawl_Target::resolve( (string) $issue['url'] )
				);
```

(Adapt argument spelling to each site's existing local variables — keep their current expressions for the first five arguments.)

- [ ] **Step 3: Verify and commit**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress`
Expected: green. (SiteCrawlerTest exercises pure helpers only; resolution correctness is verified in the Task 14 smoke crawl by checking `wp db query "SELECT object_type, COUNT(*) FROM wp_swps_crawl_issues GROUP BY 1"`.)

```bash
git add includes/class-site-crawler.php
git commit -m "Crawler: stamp issues with resolved WP object targets"
```

---

### Task 4: Fixer base class and registry

**Files:**
- Create: `includes/crawl-fixers/class-crawl-fixer.php`
- Create: `includes/crawl-fixers/class-crawl-fixer-registry.php`
- Modify: `stratawp-seo.php` (requires)
- Test: `tests/unit/CrawlFixerRegistryTest.php`

**Interfaces:**
- Produces (abstract contract every fixer implements):
  - `check_ids(): array` — check ids this fixer handles (a fixer may cover several, e.g. all four title checks).
  - `kind(): string` — `'draft'` or `'mechanical'`.
  - `can_fix( array $issue ): bool` — `$issue` is one decoded `issues_for_run()` row.
  - `draft( array $issue ): array|WP_Error` — draft fixers; returns `array{field: string, current: string, proposed: string, usage: array}`.
  - `apply( array $issue, array $accepted ): array|WP_Error` — returns `array{changed: bool, message: string}`.
  - `undo( array $issue ): bool`.
- Registry: `SWPS_Crawl_Fixer_Registry::for_check( string $check_id ): ?SWPS_Crawl_Fixer`; `SWPS_Crawl_Fixer_Registry::fixable_ids(): array`; `SWPS_Crawl_Fixer_Registry::kind_of( string $check_id ): ?string`.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Tests for SWPS_Crawl_Fixer_Registry.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer-registry.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-meta-title.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-meta-description.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-image-alt.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-mixed-content.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-nofollow.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-sitemap-exclude.php';

final class CrawlFixerRegistryTest extends TestCase {

	public function test_fixable_ids_cover_the_v1_scope(): void {
		$expected = array(
			'missing_title',
			'title_too_long',
			'title_too_short',
			'duplicate_title',
			'missing_meta_description',
			'desc_too_long',
			'duplicate_meta_description',
			'image_missing_alt',
			'mixed_content',
			'nofollow_internal_link',
			'noindex_in_sitemap',
		);
		$actual = SWPS_Crawl_Fixer_Registry::fixable_ids();
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}

	public function test_for_check_returns_fixer_handling_that_id(): void {
		$fixer = SWPS_Crawl_Fixer_Registry::for_check( 'missing_title' );
		$this->assertInstanceOf( SWPS_Crawl_Fixer::class, $fixer );
		$this->assertContains( 'missing_title', $fixer->check_ids() );
	}

	public function test_for_check_unknown_id_returns_null(): void {
		$this->assertNull( SWPS_Crawl_Fixer_Registry::for_check( 'redirect_loop' ) );
	}

	public function test_kinds_are_valid(): void {
		foreach ( SWPS_Crawl_Fixer_Registry::fixable_ids() as $id ) {
			$this->assertContains(
				SWPS_Crawl_Fixer_Registry::kind_of( $id ),
				array( 'draft', 'mechanical' ),
				"kind_of({$id})"
			);
		}
	}

	public function test_meta_checks_are_draft_kind(): void {
		$this->assertSame( 'draft', SWPS_Crawl_Fixer_Registry::kind_of( 'missing_title' ) );
		$this->assertSame( 'draft', SWPS_Crawl_Fixer_Registry::kind_of( 'missing_meta_description' ) );
		$this->assertSame( 'mechanical', SWPS_Crawl_Fixer_Registry::kind_of( 'mixed_content' ) );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter CrawlFixerRegistryTest`
Expected: FAIL — missing files. (It will keep failing until Tasks 5–9 create the concrete fixers; that's fine — create stub concrete classes in THIS task so the registry test passes, then Tasks 5–9 fill in their logic. Stub = full class with `check_ids()`/`kind()`/`can_fix()` real, `draft()`/`apply()`/`undo()` returning `new WP_Error( 'swps_not_implemented', 'Not implemented' )` / `false`.)

- [ ] **Step 3: Write the base class**

`includes/crawl-fixers/class-crawl-fixer.php`:

```php
<?php
/**
 * Base class for Site Audit Fix-It fixers.
 *
 * A fixer turns crawl issue rows of one or more check types into applied
 * fixes. 'draft' fixers generate an AI proposal the user reviews before
 * apply; 'mechanical' fixers apply directly. Both snapshot before writing
 * and support undo.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract every Fix-It fixer implements.
 */
abstract class SWPS_Crawl_Fixer {

	/**
	 * Check ids this fixer handles.
	 *
	 * @return string[]
	 */
	abstract public function check_ids(): array;

	/**
	 * 'draft' (AI proposal, review-before-apply) or 'mechanical' (direct).
	 */
	abstract public function kind(): string;

	/**
	 * Whether this specific issue row is fixable (has a live target, etc.).
	 *
	 * @param array $issue Decoded issues_for_run() row.
	 */
	public function can_fix( array $issue ): bool {
		$type = (string) ( $issue['object_type'] ?? '' );
		$id   = (int) ( $issue['object_id'] ?? 0 );
		return '' !== $type && 'none' !== $type && $id > 0;
	}

	/**
	 * Generate a proposal for a draft-kind fixer.
	 *
	 * @param array $issue Decoded issue row.
	 * @return array|WP_Error {field, current, proposed, usage}.
	 */
	public function draft( array $issue ): array|WP_Error {
		return new WP_Error( 'swps_fixit_no_draft', __( 'This fix does not use drafts.', 'stratawp-seo' ) );
	}

	/**
	 * Apply the fix. Snapshot first.
	 *
	 * @param array $issue    Decoded issue row.
	 * @param array $accepted Fixer-specific acceptance payload (draft kinds
	 *                        pass the reviewed draft; mechanical kinds pass
	 *                        an empty array).
	 * @return array|WP_Error {changed: bool, message: string}.
	 */
	abstract public function apply( array $issue, array $accepted ): array|WP_Error;

	/**
	 * Restore the pre-apply snapshot.
	 *
	 * @param array $issue Decoded issue row.
	 */
	abstract public function undo( array $issue ): bool;
}
```

- [ ] **Step 4: Write the registry**

`includes/crawl-fixers/class-crawl-fixer-registry.php`:

```php
<?php
/**
 * Registry mapping crawl check ids to their Fix-It fixer.
 *
 * Mirrors SWPS_Crawl_Check_Registry: a hardcoded class list, instantiated
 * lazily, shared per request.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check id → fixer lookup.
 */
class SWPS_Crawl_Fixer_Registry {

	/** Fixer classes. Order is irrelevant; check_ids() declares coverage. */
	private const FIXERS = array(
		SWPS_Fixer_Meta_Title::class,
		SWPS_Fixer_Meta_Description::class,
		SWPS_Fixer_Image_Alt::class,
		SWPS_Fixer_Mixed_Content::class,
		SWPS_Fixer_Nofollow::class,
		SWPS_Fixer_Sitemap_Exclude::class,
	);

	/** Lazily-built check_id → instance map. */
	private static ?array $map = null;

	/**
	 * The fixer handling a check id, or null when the check has no fixer.
	 */
	public static function for_check( string $check_id ): ?SWPS_Crawl_Fixer {
		$map = self::map();
		return $map[ $check_id ] ?? null;
	}

	/**
	 * Every fixable check id.
	 *
	 * @return string[]
	 */
	public static function fixable_ids(): array {
		return array_keys( self::map() );
	}

	/**
	 * 'draft' | 'mechanical' | null for a check id.
	 */
	public static function kind_of( string $check_id ): ?string {
		$fixer = self::for_check( $check_id );
		return $fixer ? $fixer->kind() : null;
	}

	/**
	 * Build (once) the check_id → fixer instance map.
	 */
	private static function map(): array {
		if ( null !== self::$map ) {
			return self::$map;
		}

		self::$map = array();
		foreach ( self::FIXERS as $class ) {
			$fixer = new $class();
			foreach ( $fixer->check_ids() as $id ) {
				self::$map[ $id ] = $fixer;
			}
		}

		return self::$map;
	}
}
```

- [ ] **Step 5: Create the six concrete fixer stubs**

One file each in `includes/crawl-fixers/` with real `check_ids()`/`kind()` and stub `apply()`/`undo()` (Tasks 5–9 replace the stubs). Example — `class-fixer-mixed-content.php`:

```php
<?php
/**
 * Fix-It fixer: rewrite http:// asset URLs to https:// in post content.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mixed-content mechanical fixer.
 */
class SWPS_Fixer_Mixed_Content extends SWPS_Crawl_Fixer {

	public function check_ids(): array {
		return array( 'mixed_content' );
	}

	public function kind(): string {
		return 'mechanical';
	}

	public function apply( array $issue, array $accepted ): array|WP_Error {
		return new WP_Error( 'swps_not_implemented', 'Not implemented' );
	}

	public function undo( array $issue ): bool {
		return false;
	}
}
```

Create the other five identically shaped stubs with these values:

| File | Class | check_ids | kind |
|---|---|---|---|
| `class-fixer-meta-title.php` | `SWPS_Fixer_Meta_Title` | `missing_title`, `title_too_long`, `title_too_short`, `duplicate_title` | `draft` |
| `class-fixer-meta-description.php` | `SWPS_Fixer_Meta_Description` | `missing_meta_description`, `desc_too_long`, `duplicate_meta_description` | `draft` |
| `class-fixer-image-alt.php` | `SWPS_Fixer_Image_Alt` | `image_missing_alt` | `mechanical` |
| `class-fixer-nofollow.php` | `SWPS_Fixer_Nofollow` | `nofollow_internal_link` | `mechanical` |
| `class-fixer-sitemap-exclude.php` | `SWPS_Fixer_Sitemap_Exclude` | `noindex_in_sitemap` | `mechanical` |

Add class docblocks + method docblocks matching the mixed-content example (phpcs requires them).

- [ ] **Step 6: Add requires to `stratawp-seo.php`**

Below the `class-crawl-target.php` require:

```php
require_once SWPS_PLUGIN_DIR . 'includes/crawl-fixers/class-crawl-fixer.php';
require_once SWPS_PLUGIN_DIR . 'includes/crawl-fixers/class-fixer-meta-title.php';
require_once SWPS_PLUGIN_DIR . 'includes/crawl-fixers/class-fixer-meta-description.php';
require_once SWPS_PLUGIN_DIR . 'includes/crawl-fixers/class-fixer-image-alt.php';
require_once SWPS_PLUGIN_DIR . 'includes/crawl-fixers/class-fixer-mixed-content.php';
require_once SWPS_PLUGIN_DIR . 'includes/crawl-fixers/class-fixer-nofollow.php';
require_once SWPS_PLUGIN_DIR . 'includes/crawl-fixers/class-fixer-sitemap-exclude.php';
require_once SWPS_PLUGIN_DIR . 'includes/crawl-fixers/class-crawl-fixer-registry.php';
```

(Base class first, concrete classes before the registry that references them.)

- [ ] **Step 7: Run tests, verify, commit**

Run: `composer dump-autoload && vendor/bin/phpunit --filter CrawlFixerRegistryTest && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress && vendor/bin/phpcs includes/crawl-fixers/`
Expected: all green.

```bash
git add includes/crawl-fixers/ tests/unit/CrawlFixerRegistryTest.php stratawp-seo.php
git commit -m "Add Fix-It fixer contract, registry, and fixer stubs"
```

---

### Task 5: Fix-It draft/snapshot store

**Files:**
- Create: `includes/crawl-fixers/class-fixit-store.php`
- Modify: `stratawp-seo.php` (require, below the fixer requires)
- Test: `tests/unit/FixitStoreTest.php`

**Interfaces:**
- Produces:
  - `SWPS_Fixit_Store::merge_snapshot( array $existing, array $new_fields ): array` (pure — earliest value wins per field).
  - `SWPS_Fixit_Store::normalize_draft( mixed $draft ): ?array` (pure — validates shape, returns null on garbage).
  - WP-coupled: `get_drafts( string $otype, int $oid ): array` (field → draft map), `put_draft( string $otype, int $oid, string $field, array $draft ): void`, `remove_draft( string $otype, int $oid, string $field ): void`, `snapshot_fields( string $otype, int $oid, array $fields ): void` (merge-writes `_swps_fixit_snapshot`), `get_snapshot( string $otype, int $oid ): array`, `clear_snapshot( string $otype, int $oid ): void`.
  - Meta keys: `_swps_fixit_drafts` (JSON map keyed by field), `_swps_fixit_snapshot` (JSON map field → original value + `taken_at`).
- Consumes: nothing from other tasks.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Tests for SWPS_Fixit_Store pure helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-fixit-store.php';

final class FixitStoreTest extends TestCase {

	public function test_merge_snapshot_keeps_earliest_value_per_field(): void {
		$existing = array(
			'fields'   => array( 'meta_title' => 'Original title' ),
			'taken_at' => 100,
		);
		$merged = SWPS_Fixit_Store::merge_snapshot(
			$existing,
			array(
				'meta_title'       => 'Already-changed title',
				'meta_description' => 'Original description',
			)
		);
		$this->assertSame( 'Original title', $merged['fields']['meta_title'] );
		$this->assertSame( 'Original description', $merged['fields']['meta_description'] );
		$this->assertSame( 100, $merged['taken_at'] );
	}

	public function test_merge_snapshot_from_empty(): void {
		$merged = SWPS_Fixit_Store::merge_snapshot( array(), array( 'meta_title' => 'T' ) );
		$this->assertSame( array( 'meta_title' => 'T' ), $merged['fields'] );
		$this->assertIsInt( $merged['taken_at'] );
	}

	public function test_normalize_draft_accepts_valid_shape(): void {
		$draft = array(
			'check_id'   => 'missing_title',
			'run_id'     => 1700000000,
			'current'    => '',
			'proposed'   => 'A better title',
			'drafted_at' => 1700000100,
			'usage'      => array(),
		);
		$this->assertSame( $draft, SWPS_Fixit_Store::normalize_draft( $draft ) );
	}

	public function test_normalize_draft_rejects_missing_proposed(): void {
		$this->assertNull(
			SWPS_Fixit_Store::normalize_draft(
				array( 'check_id' => 'missing_title', 'current' => '' )
			)
		);
	}

	public function test_normalize_draft_rejects_non_array(): void {
		$this->assertNull( SWPS_Fixit_Store::normalize_draft( 'garbage' ) );
		$this->assertNull( SWPS_Fixit_Store::normalize_draft( null ) );
	}

	public function test_normalize_draft_casts_and_defaults(): void {
		$out = SWPS_Fixit_Store::normalize_draft(
			array(
				'check_id' => 'desc_too_long',
				'proposed' => 'Short desc',
			)
		);
		$this->assertSame( 'desc_too_long', $out['check_id'] );
		$this->assertSame( 'Short desc', $out['proposed'] );
		$this->assertSame( '', $out['current'] );
		$this->assertSame( 0, $out['run_id'] );
		$this->assertSame( array(), $out['usage'] );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter FixitStoreTest`
Expected: FAIL — file missing.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Draft + undo-snapshot storage for the Fix-It engine.
 *
 * Drafts: `_swps_fixit_drafts` — a JSON map keyed by field
 * ('meta_title', 'meta_description'), so one object can hold a title AND
 * a description draft without clobbering. Snapshots: `_swps_fixit_snapshot`
 * — a JSON map of pre-change values, merged (earliest wins) across
 * sequential applies so undo always restores the true original.
 *
 * JSON is wp_slash()-wrapped before meta writes; update_metadata()
 * wp_unslash()es internally and eats JSON escapes otherwise (the 4.6.6 /
 * 4.22.1 AEO bug).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta-backed draft and snapshot store (posts and terms).
 */
class SWPS_Fixit_Store {

	public const META_DRAFTS   = '_swps_fixit_drafts';
	public const META_SNAPSHOT = '_swps_fixit_snapshot';

	// =========================================================================
	// PURE HELPERS (unit-tested)
	// =========================================================================

	/**
	 * Merge new pre-change field values into an existing snapshot. The
	 * earliest captured value wins per field, so sequential applies to the
	 * same object never lose the true original.
	 *
	 * @param array $existing   Decoded snapshot ({fields, taken_at}) or empty.
	 * @param array $new_fields field => pre-change value.
	 * @return array {fields: array, taken_at: int}
	 */
	public static function merge_snapshot( array $existing, array $new_fields ): array {
		$fields = is_array( $existing['fields'] ?? null ) ? $existing['fields'] : array();

		foreach ( $new_fields as $field => $value ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				$fields[ $field ] = $value;
			}
		}

		return array(
			'fields'   => $fields,
			'taken_at' => (int) ( $existing['taken_at'] ?? time() ),
		);
	}

	/**
	 * Validate and normalize a draft array. Null for garbage.
	 *
	 * @param mixed $draft Candidate draft.
	 * @return array|null {check_id, run_id, current, proposed, drafted_at, usage}
	 */
	public static function normalize_draft( $draft ): ?array {
		if ( ! is_array( $draft ) ) {
			return null;
		}
		if ( '' === (string) ( $draft['check_id'] ?? '' ) || '' === (string) ( $draft['proposed'] ?? '' ) ) {
			return null;
		}

		return array(
			'check_id'   => (string) $draft['check_id'],
			'run_id'     => (int) ( $draft['run_id'] ?? 0 ),
			'current'    => (string) ( $draft['current'] ?? '' ),
			'proposed'   => (string) $draft['proposed'],
			'drafted_at' => (int) ( $draft['drafted_at'] ?? time() ),
			'usage'      => is_array( $draft['usage'] ?? null ) ? $draft['usage'] : array(),
		);
	}

	// =========================================================================
	// WP-COUPLED STORAGE
	// =========================================================================

	/**
	 * All drafts on an object, keyed by field. Invalid entries dropped.
	 */
	public static function get_drafts( string $otype, int $oid ): array {
		$decoded = json_decode( (string) self::meta_get( $otype, $oid, self::META_DRAFTS ), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$drafts = array();
		foreach ( $decoded as $field => $draft ) {
			$normalized = self::normalize_draft( $draft );
			if ( null !== $normalized ) {
				$drafts[ (string) $field ] = $normalized;
			}
		}

		return $drafts;
	}

	/**
	 * Store one field's draft, preserving the object's other drafts.
	 */
	public static function put_draft( string $otype, int $oid, string $field, array $draft ): void {
		$drafts           = self::get_drafts( $otype, $oid );
		$drafts[ $field ] = $draft;
		self::meta_set( $otype, $oid, self::META_DRAFTS, wp_slash( (string) wp_json_encode( $drafts ) ) );
	}

	/**
	 * Remove one field's draft; deletes the meta when the map empties.
	 */
	public static function remove_draft( string $otype, int $oid, string $field ): void {
		$drafts = self::get_drafts( $otype, $oid );
		unset( $drafts[ $field ] );
		if ( array() === $drafts ) {
			self::meta_delete( $otype, $oid, self::META_DRAFTS );
			return;
		}
		self::meta_set( $otype, $oid, self::META_DRAFTS, wp_slash( (string) wp_json_encode( $drafts ) ) );
	}

	/**
	 * Merge pre-change values into the object's snapshot (earliest wins).
	 *
	 * @param array $fields field => pre-change value.
	 */
	public static function snapshot_fields( string $otype, int $oid, array $fields ): void {
		$existing = json_decode( (string) self::meta_get( $otype, $oid, self::META_SNAPSHOT ), true );
		$merged   = self::merge_snapshot( is_array( $existing ) ? $existing : array(), $fields );
		self::meta_set( $otype, $oid, self::META_SNAPSHOT, wp_slash( (string) wp_json_encode( $merged ) ) );
	}

	/**
	 * Decoded snapshot ({fields, taken_at}) or empty array.
	 */
	public static function get_snapshot( string $otype, int $oid ): array {
		$decoded = json_decode( (string) self::meta_get( $otype, $oid, self::META_SNAPSHOT ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Delete the snapshot after a successful undo.
	 */
	public static function clear_snapshot( string $otype, int $oid ): void {
		self::meta_delete( $otype, $oid, self::META_SNAPSHOT );
	}

	// =========================================================================
	// META DISPATCH
	// =========================================================================

	/** Read meta for a post or term target. */
	private static function meta_get( string $otype, int $oid, string $key ): string {
		if ( 'term' === $otype ) {
			return (string) get_term_meta( $oid, $key, true );
		}
		return (string) get_post_meta( $oid, $key, true );
	}

	/** Write meta for a post or term target. */
	private static function meta_set( string $otype, int $oid, string $key, string $value ): void {
		if ( 'term' === $otype ) {
			update_term_meta( $oid, $key, $value );
			return;
		}
		update_post_meta( $oid, $key, $value );
	}

	/** Delete meta for a post or term target. */
	private static function meta_delete( string $otype, int $oid, string $key ): void {
		if ( 'term' === $otype ) {
			delete_term_meta( $oid, $key );
			return;
		}
		delete_post_meta( $oid, $key );
	}
}
```

- [ ] **Step 4: Require in `stratawp-seo.php`** (after `class-crawl-fixer.php`, before the concrete fixers):

```php
require_once SWPS_PLUGIN_DIR . 'includes/crawl-fixers/class-fixit-store.php';
```

- [ ] **Step 5: Run tests, verify, commit**

Run: `composer dump-autoload && vendor/bin/phpunit --filter FixitStoreTest && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress && vendor/bin/phpcs includes/crawl-fixers/class-fixit-store.php`
Note: if `wp_slash`/`wp_json_encode`/`get_post_meta` etc. are missing from `tests/bootstrap.php`, they are NOT needed — the tests touch only the pure helpers. If PHP complains at parse time, nothing needs stubbing (functions resolve at call time).

```bash
git add includes/crawl-fixers/class-fixit-store.php tests/unit/FixitStoreTest.php stratawp-seo.php
git commit -m "Add Fix-It draft and snapshot store"
```

---

### Task 6: Mixed-content fixer

**Files:**
- Modify: `includes/crawl-fixers/class-fixer-mixed-content.php` (replace stub methods)
- Test: `tests/unit/FixerMixedContentTest.php`

**Interfaces:**
- Consumes: `SWPS_Fixit_Store::snapshot_fields()/get_snapshot()/clear_snapshot()` (Task 5), base `can_fix()` (Task 4).
- Produces: pure `SWPS_Fixer_Mixed_Content::rewrite( string $html ): array{content: string, changed: int}`; working `apply()`/`undo()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Tests for the mixed-content rewriter.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-mixed-content.php';

final class FixerMixedContentTest extends TestCase {

	public function test_rewrites_img_src(): void {
		$out = SWPS_Fixer_Mixed_Content::rewrite( '<img src="http://example.com/a.jpg" alt="">' );
		$this->assertSame( '<img src="https://example.com/a.jpg" alt="">', $out['content'] );
		$this->assertSame( 1, $out['changed'] );
	}

	public function test_rewrites_srcset_with_multiple_urls(): void {
		$out = SWPS_Fixer_Mixed_Content::rewrite(
			'<img srcset="http://a.com/1.jpg 1x, http://a.com/2.jpg 2x" src="http://a.com/1.jpg">'
		);
		$this->assertStringNotContainsString( 'http://', $out['content'] );
		$this->assertSame( 3, $out['changed'] );
	}

	public function test_leaves_anchor_hrefs_alone(): void {
		$html = '<a href="http://example.com/page/">link</a>';
		$out  = SWPS_Fixer_Mixed_Content::rewrite( $html );
		$this->assertSame( $html, $out['content'] );
		$this->assertSame( 0, $out['changed'] );
	}

	public function test_leaves_plain_text_urls_alone(): void {
		$html = '<p>Visit http://example.com for more.</p>';
		$out  = SWPS_Fixer_Mixed_Content::rewrite( $html );
		$this->assertSame( $html, $out['content'] );
	}

	public function test_rewrites_script_and_iframe_and_source(): void {
		$html = '<script src="http://cdn.example.com/x.js"></script>'
			. '<iframe src="http://example.com/embed"></iframe>'
			. '<source src="http://example.com/v.mp4">';
		$out  = SWPS_Fixer_Mixed_Content::rewrite( $html );
		$this->assertStringNotContainsString( 'http://', $out['content'] );
		$this->assertSame( 3, $out['changed'] );
	}

	public function test_already_https_untouched(): void {
		$html = '<img src="https://example.com/a.jpg">';
		$out  = SWPS_Fixer_Mixed_Content::rewrite( $html );
		$this->assertSame( $html, $out['content'] );
		$this->assertSame( 0, $out['changed'] );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter FixerMixedContentTest`
Expected: FAIL — `rewrite` undefined.

- [ ] **Step 3: Implement**

Replace the stub `apply()`/`undo()` and add `rewrite()`:

```php
	/**
	 * Attributes that load subresources (= can cause mixed content).
	 * href is deliberately excluded: anchor links are not mixed content.
	 */
	private const ASSET_ATTRS = array( 'src', 'srcset', 'poster', 'data-src' );

	/**
	 * Rewrite http:// URLs to https:// inside asset-loading attributes.
	 *
	 * Pure; unit-tested. Counts every rewritten URL (srcset can hold
	 * several per attribute).
	 *
	 * @param string $html Post content HTML.
	 * @return array {content: string, changed: int}
	 */
	public static function rewrite( string $html ): array {
		$changed = 0;
		$attrs   = implode( '|', self::ASSET_ATTRS );

		$content = (string) preg_replace_callback(
			'#\b(' . $attrs . ')\s*=\s*("|\')(.*?)\2#is',
			static function ( array $m ) use ( &$changed ): string {
				$value = preg_replace( '#\bhttp://#i', 'https://', $m[3], -1, $count );
				$changed += (int) $count;
				return $m[1] . '=' . $m[2] . $value . $m[2];
			},
			$html
		);

		return array(
			'content' => $content,
			'changed' => $changed,
		);
	}

	/**
	 * Snapshot the post content, rewrite, save.
	 *
	 * @param array $issue    Decoded issue row (object_type 'post' expected).
	 * @param array $accepted Unused for mechanical fixers.
	 * @return array|WP_Error {changed: bool, message: string}
	 */
	public function apply( array $issue, array $accepted ): array|WP_Error {
		if ( 'post' !== ( $issue['object_type'] ?? '' ) ) {
			return new WP_Error( 'swps_fixit_bad_target', __( 'Mixed-content fixes apply to posts only.', 'stratawp-seo' ) );
		}

		$post = get_post( (int) $issue['object_id'] );
		if ( ! $post ) {
			return new WP_Error( 'swps_fixit_gone', __( 'The post no longer exists.', 'stratawp-seo' ) );
		}

		$result = self::rewrite( $post->post_content );
		if ( 0 === $result['changed'] ) {
			return array(
				'changed' => false,
				'message' => __( 'No http:// asset URLs found in the content (the issue may come from the theme or a widget).', 'stratawp-seo' ),
			);
		}

		SWPS_Fixit_Store::snapshot_fields( 'post', (int) $post->ID, array( 'post_content' => $post->post_content ) );

		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $result['content'] ),
			)
		);

		return array(
			'changed' => true,
			/* translators: %d: number of rewritten URLs */
			'message' => sprintf( __( 'Rewrote %d URL(s) to https.', 'stratawp-seo' ), $result['changed'] ),
		);
	}

	/**
	 * Restore the snapshotted post content.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function undo( array $issue ): bool {
		$oid  = (int) ( $issue['object_id'] ?? 0 );
		$snap = SWPS_Fixit_Store::get_snapshot( 'post', $oid );
		if ( ! isset( $snap['fields']['post_content'] ) ) {
			return false;
		}

		wp_update_post(
			array(
				'ID'           => $oid,
				'post_content' => wp_slash( (string) $snap['fields']['post_content'] ),
			)
		);
		SWPS_Fixit_Store::clear_snapshot( 'post', $oid );

		return true;
	}
```

- [ ] **Step 4: Run tests, verify, commit**

Run: `vendor/bin/phpunit --filter FixerMixedContentTest && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress && vendor/bin/phpcs includes/crawl-fixers/class-fixer-mixed-content.php`

```bash
git add includes/crawl-fixers/class-fixer-mixed-content.php tests/unit/FixerMixedContentTest.php
git commit -m "Implement mixed-content Fix-It fixer"
```

---

### Task 7: Nofollow-internal-link fixer

**Files:**
- Modify: `includes/crawl-fixers/class-fixer-nofollow.php` (replace stub)
- Test: `tests/unit/FixerNofollowTest.php`

**Interfaces:**
- Consumes: `SWPS_Fixit_Store` (Task 5).
- Produces: pure `SWPS_Fixer_Nofollow::strip( string $html, string $home_host ): array{content: string, changed: int}`; working `apply()`/`undo()` (same snapshot/restore shape as Task 6 — `post_content` field).

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Tests for the nofollow-internal-link stripper.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-nofollow.php';

final class FixerNofollowTest extends TestCase {

	public function test_strips_nofollow_from_internal_absolute_link(): void {
		$out = SWPS_Fixer_Nofollow::strip(
			'<a href="https://example.com/about/" rel="nofollow">About</a>',
			'example.com'
		);
		$this->assertSame( '<a href="https://example.com/about/">About</a>', $out['content'] );
		$this->assertSame( 1, $out['changed'] );
	}

	public function test_strips_nofollow_from_relative_link(): void {
		$out = SWPS_Fixer_Nofollow::strip(
			'<a rel="nofollow" href="/contact/">Contact</a>',
			'example.com'
		);
		$this->assertStringNotContainsString( 'nofollow', $out['content'] );
		$this->assertSame( 1, $out['changed'] );
	}

	public function test_preserves_other_rel_tokens(): void {
		$out = SWPS_Fixer_Nofollow::strip(
			'<a href="/x/" rel="nofollow noopener">x</a>',
			'example.com'
		);
		$this->assertStringContainsString( 'rel="noopener"', $out['content'] );
		$this->assertStringNotContainsString( 'nofollow', $out['content'] );
	}

	public function test_external_links_untouched(): void {
		$html = '<a href="https://other.com/" rel="nofollow">ext</a>';
		$out  = SWPS_Fixer_Nofollow::strip( $html, 'example.com' );
		$this->assertSame( $html, $out['content'] );
		$this->assertSame( 0, $out['changed'] );
	}

	public function test_links_without_rel_untouched(): void {
		$html = '<a href="/plain/">plain</a>';
		$out  = SWPS_Fixer_Nofollow::strip( $html, 'example.com' );
		$this->assertSame( $html, $out['content'] );
	}

	public function test_www_prefix_counts_as_internal(): void {
		$out = SWPS_Fixer_Nofollow::strip(
			'<a href="https://www.example.com/p/" rel="nofollow">p</a>',
			'example.com'
		);
		$this->assertSame( 1, $out['changed'] );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter FixerNofollowTest`
Expected: FAIL — `strip` undefined.

- [ ] **Step 3: Implement**

```php
	/**
	 * Remove the nofollow rel token from internal <a> tags.
	 *
	 * Internal = relative href, or absolute href whose host equals the home
	 * host (www-insensitive). Other rel tokens survive; an emptied rel
	 * attribute is removed entirely. Pure; unit-tested.
	 *
	 * @param string $html      Post content HTML.
	 * @param string $home_host Site host, e.g. "example.com".
	 * @return array {content: string, changed: int}
	 */
	public static function strip( string $html, string $home_host ): array {
		$changed   = 0;
		$home_host = strtolower( preg_replace( '/^www\./', '', $home_host ) );

		$content = (string) preg_replace_callback(
			'#<a\b[^>]*>#is',
			static function ( array $m ) use ( &$changed, $home_host ): string {
				$tag = $m[0];

				if ( ! preg_match( '#\brel\s*=\s*("|\')(.*?)\1#is', $tag, $rel ) ) {
					return $tag;
				}
				if ( ! preg_match( '#\bnofollow\b#i', $rel[2] ) ) {
					return $tag;
				}

				// Internal-only: relative href, or matching host.
				if ( preg_match( '#\bhref\s*=\s*("|\')(.*?)\1#is', $tag, $href ) ) {
					$url  = trim( $href[2] );
					$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
					$host = preg_replace( '/^www\./', '', $host );
					if ( '' !== $host && $host !== $home_host ) {
						return $tag;
					}
				}

				$rest = trim( (string) preg_replace( '#\bnofollow\b#i', '', $rel[2] ) );
				$rest = (string) preg_replace( '/\s{2,}/', ' ', $rest );
				++$changed;

				if ( '' === $rest ) {
					// Drop the whole rel attribute.
					return (string) preg_replace( '#\s*\brel\s*=\s*("|\').*?\1#is', '', $tag );
				}

				return str_replace( $rel[0], 'rel=' . $rel[1] . $rest . $rel[1], $tag );
			},
			$html
		);

		return array(
			'content' => $content,
			'changed' => $changed,
		);
	}
```

`apply()` and `undo()`: identical structure to Task 6's (post-only guard, `get_post`, call `self::strip( $post->post_content, (string) wp_parse_url( home_url(), PHP_URL_HOST ) )`, zero-changed early return with message `__( 'No internal nofollow links found in the content.', 'stratawp-seo' )`, snapshot `post_content`, `wp_update_post` with `wp_slash`, undo restores `post_content` from snapshot then `clear_snapshot`). Copy the Task 6 code and change only the rewrite call and messages.

- [ ] **Step 4: Run tests, verify, commit**

Run: `vendor/bin/phpunit --filter FixerNofollowTest && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress && vendor/bin/phpcs includes/crawl-fixers/class-fixer-nofollow.php`

```bash
git add includes/crawl-fixers/class-fixer-nofollow.php tests/unit/FixerNofollowTest.php
git commit -m "Implement nofollow-internal-link Fix-It fixer"
```

---

### Task 8: Image-alt and sitemap-exclude fixers

**Files:**
- Modify: `includes/crawl-fixers/class-fixer-image-alt.php`, `includes/crawl-fixers/class-fixer-sitemap-exclude.php` (replace stubs)
- Test: `tests/unit/FixerImageAltTest.php`

**Interfaces:**
- Consumes: `stratawp_seo()->image_seo->generate_alt_for( int $attachment_id ): string` (existing, `includes/class-image-seo.php:201`; property registered at `stratawp-seo.php:294/472`); `SWPS_Fixit_Store`.
- Produces: pure `SWPS_Fixer_Image_Alt::strip_size_suffix( string $url ): string`; both fixers' `apply()`/`undo()`.

- [ ] **Step 1: Write the failing test (pure part)**

```php
<?php
/**
 * Tests for the image-alt fixer's URL normalization.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-image-alt.php';

final class FixerImageAltTest extends TestCase {

	public function test_strips_wp_size_suffix(): void {
		$this->assertSame(
			'https://example.com/wp-content/uploads/2026/08/photo.jpg',
			SWPS_Fixer_Image_Alt::strip_size_suffix( 'https://example.com/wp-content/uploads/2026/08/photo-300x200.jpg' )
		);
	}

	public function test_leaves_unsuffixed_url_alone(): void {
		$url = 'https://example.com/wp-content/uploads/photo.jpg';
		$this->assertSame( $url, SWPS_Fixer_Image_Alt::strip_size_suffix( $url ) );
	}

	public function test_does_not_mangle_dimensions_in_filename_body(): void {
		$url = 'https://example.com/uploads/board-1920s-history.jpg';
		$this->assertSame( $url, SWPS_Fixer_Image_Alt::strip_size_suffix( $url ) );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter FixerImageAltTest`
Expected: FAIL.

- [ ] **Step 3: Implement the image-alt fixer**

```php
	/**
	 * Strip a WordPress -{W}x{H} rendition suffix so the URL matches the
	 * original attachment file for attachment_url_to_postid(). Pure.
	 *
	 * @param string $url Image URL as rendered on the page.
	 * @return string Original-file URL.
	 */
	public static function strip_size_suffix( string $url ): string {
		return (string) preg_replace( '#-\d+x\d+(\.[a-z]{3,4})$#i', '$1', $url );
	}

	/**
	 * Generate + store alt text for each library image on the page that
	 * lacks one. detail['images'] holds rendered absolute URLs; non-library
	 * images are skipped and reported.
	 *
	 * @param array $issue    Decoded issue row.
	 * @param array $accepted Unused.
	 * @return array|WP_Error {changed: bool, message: string}
	 */
	public function apply( array $issue, array $accepted ): array|WP_Error {
		$urls = array_map( 'strval', (array) ( $issue['detail']['images'] ?? array() ) );
		if ( array() === $urls ) {
			return new WP_Error( 'swps_fixit_no_images', __( 'The issue row lists no image URLs.', 'stratawp-seo' ) );
		}

		$fixed   = 0;
		$skipped = 0;

		foreach ( $urls as $url ) {
			$attachment_id = attachment_url_to_postid( self::strip_size_suffix( $url ) );
			if ( 0 === $attachment_id ) {
				++$skipped;
				continue;
			}
			if ( '' !== (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
				continue; // Already fixed via another page's issue row.
			}

			$alt = stratawp_seo()->image_seo->generate_alt_for( $attachment_id );
			if ( '' === $alt ) {
				++$skipped;
				continue;
			}

			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			++$fixed;
		}

		return array(
			'changed' => $fixed > 0,
			'message' => sprintf(
				/* translators: 1: images fixed, 2: images skipped */
				__( 'Alt text added to %1$d image(s); %2$d skipped (not in the media library or generation failed).', 'stratawp-seo' ),
				$fixed,
				$skipped
			),
		);
	}

	/**
	 * Alt-text writes are additive to previously-empty fields; undo clears
	 * the generated alt on this page's library images.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function undo( array $issue ): bool {
		$done = false;
		foreach ( array_map( 'strval', (array) ( $issue['detail']['images'] ?? array() ) ) as $url ) {
			$attachment_id = attachment_url_to_postid( self::strip_size_suffix( $url ) );
			if ( $attachment_id > 0 ) {
				delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
				$done = true;
			}
		}
		return $done;
	}
```

Also override `can_fix()` in this fixer — image issues are fixable even when the page target is a term archive, because the write target is the attachment, not the page:

```php
	/**
	 * Fixable whenever the issue lists image URLs — the write target is the
	 * attachment, not the crawled page.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function can_fix( array $issue ): bool {
		return array() !== (array) ( $issue['detail']['images'] ?? array() );
	}
```

- [ ] **Step 4: Implement the sitemap-exclude fixer**

`noindex_in_sitemap` detail already carries `post_id` (`includes/crawl-checks/class-checks-legacy-page.php:281`); the existing one-click precedent is `wp_ajax_swps_crawl_exclude_sitemap`.

```php
	/**
	 * Prefer the post id the check stored in detail; fall back to the
	 * resolved object target.
	 *
	 * @param array $issue Decoded issue row.
	 */
	private function post_id_for( array $issue ): int {
		$detail_id = (int) ( $issue['detail']['post_id'] ?? 0 );
		if ( $detail_id > 0 ) {
			return $detail_id;
		}
		return 'post' === ( $issue['object_type'] ?? '' ) ? (int) $issue['object_id'] : 0;
	}

	/**
	 * Fixable when a post id is recoverable from the row.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function can_fix( array $issue ): bool {
		return $this->post_id_for( $issue ) > 0;
	}

	/**
	 * Exclude the noindexed post from the sitemap.
	 *
	 * @param array $issue    Decoded issue row.
	 * @param array $accepted Unused.
	 * @return array|WP_Error {changed: bool, message: string}
	 */
	public function apply( array $issue, array $accepted ): array|WP_Error {
		$post_id = $this->post_id_for( $issue );
		if ( 0 === $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'swps_fixit_gone', __( 'The post no longer exists.', 'stratawp-seo' ) );
		}

		update_post_meta( $post_id, '_swps_sitemap_exclude', 1 );

		return array(
			'changed' => true,
			'message' => __( 'Excluded from the sitemap.', 'stratawp-seo' ),
		);
	}

	/**
	 * Re-include the post in the sitemap.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function undo( array $issue ): bool {
		$post_id = $this->post_id_for( $issue );
		if ( 0 === $post_id ) {
			return false;
		}
		delete_post_meta( $post_id, '_swps_sitemap_exclude' );
		return true;
	}
```

- [ ] **Step 5: Run tests, verify, commit**

Run: `vendor/bin/phpunit --filter FixerImageAltTest && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress && vendor/bin/phpcs includes/crawl-fixers/class-fixer-image-alt.php includes/crawl-fixers/class-fixer-sitemap-exclude.php`

```bash
git add includes/crawl-fixers/class-fixer-image-alt.php includes/crawl-fixers/class-fixer-sitemap-exclude.php tests/unit/FixerImageAltTest.php
git commit -m "Implement image-alt and sitemap-exclude Fix-It fixers"
```

---

### Task 9: AI meta title/description fixers

**Files:**
- Modify: `includes/crawl-fixers/class-fixer-meta-title.php`, `includes/crawl-fixers/class-fixer-meta-description.php` (replace stubs)
- Test: `tests/unit/FixerMetaPromptTest.php`

**Interfaces:**
- Consumes: `SWPS_Provider_Factory::create_ai_provider()` and `chat_json( string $system, string $user, int $max_tokens = 4096 ): array|WP_Error` (`includes/class-ai-provider.php:94`); `SWPS_Fixit_Store` (Task 5).
- Produces: pure `SWPS_Fixer_Meta_Title::build_prompt( array $ctx ): string` and `SWPS_Fixer_Meta_Title::normalize_response( array $decoded, string $key, int $max_len ): ?string` (shared via the title class; the description fixer calls them too); `draft()`/`apply()`/`undo()` for both.
- Field names: title fixer stores drafts under field `meta_title`, writes `_swps_meta_title`; description fixer uses field `meta_description`, writes `_swps_meta_description`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Tests for the AI meta fixers' pure prompt/response helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-crawl-fixer.php';
require_once __DIR__ . '/../../includes/crawl-fixers/class-fixer-meta-title.php';

final class FixerMetaPromptTest extends TestCase {

	public function test_prompt_contains_constraint_and_content(): void {
		$prompt = SWPS_Fixer_Meta_Title::build_prompt(
			array(
				'kind'       => 'title',
				'page_title' => 'Hello World',
				'excerpt'    => 'A post about greetings.',
				'keyword'    => 'greetings',
				'min'        => 30,
				'max'        => 60,
				'siblings'   => array(),
			)
		);
		$this->assertStringContainsString( 'Hello World', $prompt );
		$this->assertStringContainsString( 'greetings', $prompt );
		$this->assertStringContainsString( '30', $prompt );
		$this->assertStringContainsString( '60', $prompt );
	}

	public function test_prompt_lists_siblings_for_duplicate_checks(): void {
		$prompt = SWPS_Fixer_Meta_Title::build_prompt(
			array(
				'kind'       => 'title',
				'page_title' => 'Best Widgets',
				'excerpt'    => '',
				'keyword'    => '',
				'min'        => 30,
				'max'        => 60,
				'siblings'   => array( 'Best Widgets — Site', 'Best Widgets Guide' ),
			)
		);
		$this->assertStringContainsString( 'Best Widgets Guide', $prompt );
		$this->assertStringContainsString( 'differ', $prompt );
	}

	public function test_normalize_response_extracts_and_trims(): void {
		$out = SWPS_Fixer_Meta_Title::normalize_response(
			array( 'title' => '  A Fine Title  ' ),
			'title',
			60
		);
		$this->assertSame( 'A Fine Title', $out );
	}

	public function test_normalize_response_rejects_missing_key(): void {
		$this->assertNull( SWPS_Fixer_Meta_Title::normalize_response( array( 'nope' => 'x' ), 'title', 60 ) );
	}

	public function test_normalize_response_hard_truncates_overlong_value(): void {
		$long = str_repeat( 'word ', 40 );
		$out  = SWPS_Fixer_Meta_Title::normalize_response( array( 'title' => $long ), 'title', 60 );
		$this->assertNotNull( $out );
		$this->assertLessThanOrEqual( 60, mb_strlen( $out ) );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter FixerMetaPromptTest`
Expected: FAIL.

- [ ] **Step 3: Implement `SWPS_Fixer_Meta_Title`**

```php
	/** Draft field name in SWPS_Fixit_Store. */
	protected const FIELD = 'meta_title';

	/** Meta key written on apply. */
	protected const META_KEY = '_swps_meta_title';

	/** JSON key expected from the model. */
	protected const RESPONSE_KEY = 'title';

	/** Length window enforced in the prompt and on the response. */
	protected const MIN_LEN = 30;
	protected const MAX_LEN = 60;

	/**
	 * Build the generation prompt. Pure; unit-tested.
	 *
	 * @param array $ctx {kind, page_title, excerpt, keyword, min, max, siblings}.
	 */
	public static function build_prompt( array $ctx ): string {
		$prompt = sprintf(
			"Write an SEO %s for this page.\nPage title: %s\nContent summary: %s\n",
			(string) $ctx['kind'],
			(string) $ctx['page_title'],
			(string) $ctx['excerpt']
		);
		if ( '' !== (string) $ctx['keyword'] ) {
			$prompt .= 'Focus keyword (must appear naturally): ' . (string) $ctx['keyword'] . "\n";
		}
		$prompt .= sprintf(
			"Length: between %d and %d characters.\n",
			(int) $ctx['min'],
			(int) $ctx['max']
		);
		if ( array() !== (array) $ctx['siblings'] ) {
			$prompt .= "It must clearly differ from these existing values on other pages:\n- "
				. implode( "\n- ", array_map( 'strval', (array) $ctx['siblings'] ) ) . "\n";
		}
		$prompt .= sprintf( 'Return only JSON: {"%s": "..."}', static::RESPONSE_KEY );

		return $prompt;
	}

	/**
	 * Extract, trim, and length-cap the model response. Pure; unit-tested.
	 *
	 * @param array  $decoded chat_json() result.
	 * @param string $key     Expected JSON key.
	 * @param int    $max_len Hard cap (word-boundary truncation).
	 */
	public static function normalize_response( array $decoded, string $key, int $max_len ): ?string {
		$value = trim( (string) ( $decoded[ $key ] ?? '' ) );
		if ( '' === $value ) {
			return null;
		}
		if ( mb_strlen( $value ) > $max_len ) {
			$value = mb_substr( $value, 0, $max_len );
			$cut   = mb_strrpos( $value, ' ' );
			if ( false !== $cut && $cut > (int) ( $max_len / 2 ) ) {
				$value = mb_substr( $value, 0, $cut );
			}
			$value = rtrim( $value, " \t.,;:-" );
		}
		return $value;
	}

	/**
	 * Generate a draft for the issue's target object and store it.
	 *
	 * @param array $issue Decoded issue row.
	 * @return array|WP_Error {field, current, proposed, usage}
	 */
	public function draft( array $issue ): array|WP_Error {
		$otype = (string) $issue['object_type'];
		$oid   = (int) $issue['object_id'];

		$ctx = $this->context_for( $otype, $oid, $issue );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$api     = SWPS_Provider_Factory::create_ai_provider();
		$decoded = $api->chat_json(
			'You are an SEO copywriting expert. Return only valid JSON.',
			self::build_prompt( $ctx ),
			512
		);
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$proposed = self::normalize_response( $decoded, static::RESPONSE_KEY, static::MAX_LEN );
		if ( null === $proposed ) {
			return new WP_Error( 'swps_fixit_bad_response', __( 'The AI response was empty or malformed.', 'stratawp-seo' ) );
		}

		$draft = array(
			'check_id'   => (string) $issue['type'],
			'run_id'     => (int) $issue['run_id'],
			'current'    => $ctx['current'],
			'proposed'   => $proposed,
			'drafted_at' => time(),
			'usage'      => is_array( $decoded['_usage'] ?? null ) ? $decoded['_usage'] : array(),
		);
		SWPS_Fixit_Store::put_draft( $otype, $oid, static::FIELD, $draft );

		return array(
			'field'    => static::FIELD,
			'current'  => $ctx['current'],
			'proposed' => $proposed,
			'usage'    => $draft['usage'],
		);
	}

	/**
	 * Gather generation context from the target post or term.
	 *
	 * @param string $otype 'post' | 'term'.
	 * @param int    $oid   Object id.
	 * @param array  $issue Decoded issue row (for duplicate siblings).
	 * @return array|WP_Error {kind, page_title, excerpt, keyword, min, max, siblings, current}
	 */
	protected function context_for( string $otype, int $oid, array $issue ): array|WP_Error {
		$siblings = array_map( 'strval', (array) ( $issue['detail']['duplicate_of'] ?? array() ) );

		if ( 'term' === $otype ) {
			$term = get_term( $oid );
			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error( 'swps_fixit_gone', __( 'The term no longer exists.', 'stratawp-seo' ) );
			}
			return array(
				'kind'       => 'title' === static::RESPONSE_KEY ? 'meta title' : 'meta description',
				'page_title' => $term->name,
				'excerpt'    => mb_substr( wp_strip_all_tags( (string) $term->description ), 0, 500 ),
				'keyword'    => (string) get_term_meta( $oid, '_swps_focus_keyword', true ),
				'min'        => static::MIN_LEN,
				'max'        => static::MAX_LEN,
				'siblings'   => $siblings,
				'current'    => (string) get_term_meta( $oid, static::META_KEY, true ),
			);
		}

		$post = get_post( $oid );
		if ( ! $post ) {
			return new WP_Error( 'swps_fixit_gone', __( 'The post no longer exists.', 'stratawp-seo' ) );
		}
		return array(
			'kind'       => 'title' === static::RESPONSE_KEY ? 'meta title' : 'meta description',
			'page_title' => (string) $post->post_title,
			'excerpt'    => mb_substr( wp_strip_all_tags( (string) $post->post_content ), 0, 2000 ),
			'keyword'    => (string) get_post_meta( $oid, '_swps_focus_keyword', true ),
			'min'        => static::MIN_LEN,
			'max'        => static::MAX_LEN,
			'siblings'   => $siblings,
			'current'    => (string) get_post_meta( $oid, static::META_KEY, true ),
		);
	}

	/**
	 * Apply the reviewed draft: snapshot, write the meta key, drop the draft.
	 *
	 * @param array $issue    Decoded issue row.
	 * @param array $accepted Unused (the stored draft is the source of truth).
	 * @return array|WP_Error {changed: bool, message: string}
	 */
	public function apply( array $issue, array $accepted ): array|WP_Error {
		$otype = (string) $issue['object_type'];
		$oid   = (int) $issue['object_id'];

		$drafts = SWPS_Fixit_Store::get_drafts( $otype, $oid );
		if ( ! isset( $drafts[ static::FIELD ] ) ) {
			return new WP_Error( 'swps_fixit_no_draft', __( 'No draft cached — generate drafts again (they may have been swept).', 'stratawp-seo' ) );
		}

		$current = 'term' === $otype
			? (string) get_term_meta( $oid, static::META_KEY, true )
			: (string) get_post_meta( $oid, static::META_KEY, true );

		SWPS_Fixit_Store::snapshot_fields( $otype, $oid, array( static::FIELD => $current ) );

		$value = $this->sanitize( $drafts[ static::FIELD ]['proposed'] );
		if ( 'term' === $otype ) {
			update_term_meta( $oid, static::META_KEY, $value );
		} else {
			update_post_meta( $oid, static::META_KEY, $value );
		}

		SWPS_Fixit_Store::remove_draft( $otype, $oid, static::FIELD );

		return array(
			'changed' => true,
			'message' => __( 'Applied.', 'stratawp-seo' ),
		);
	}

	/**
	 * Restore the snapshotted value for this field.
	 *
	 * @param array $issue Decoded issue row.
	 */
	public function undo( array $issue ): bool {
		$otype = (string) $issue['object_type'];
		$oid   = (int) $issue['object_id'];

		$snap = SWPS_Fixit_Store::get_snapshot( $otype, $oid );
		if ( ! array_key_exists( static::FIELD, $snap['fields'] ?? array() ) ) {
			return false;
		}

		$original = (string) $snap['fields'][ static::FIELD ];
		if ( 'term' === $otype ) {
			if ( '' === $original ) {
				delete_term_meta( $oid, static::META_KEY );
			} else {
				update_term_meta( $oid, static::META_KEY, $this->sanitize( $original ) );
			}
		} elseif ( '' === $original ) {
			delete_post_meta( $oid, static::META_KEY );
		} else {
			update_post_meta( $oid, static::META_KEY, $this->sanitize( $original ) );
		}

		return true;
	}

	/**
	 * Field-appropriate sanitizer (titles are single-line).
	 *
	 * @param string $value Raw value.
	 */
	protected function sanitize( string $value ): string {
		return sanitize_text_field( $value );
	}
```

- [ ] **Step 4: Implement `SWPS_Fixer_Meta_Description`**

Extend the title fixer to inherit the entire flow and override only the constants and sanitizer:

```php
class SWPS_Fixer_Meta_Description extends SWPS_Fixer_Meta_Title {

	protected const FIELD        = 'meta_description';
	protected const META_KEY     = '_swps_meta_description';
	protected const RESPONSE_KEY = 'description';
	protected const MIN_LEN      = 120;
	protected const MAX_LEN      = 160;

	public function check_ids(): array {
		return array( 'missing_meta_description', 'desc_too_long', 'duplicate_meta_description' );
	}

	/**
	 * Descriptions are textarea-sanitized (multi-sentence).
	 *
	 * @param string $value Raw value.
	 */
	protected function sanitize( string $value ): string {
		return sanitize_textarea_field( $value );
	}
}
```

(Keep its file's docblock header; `kind()` stays `'draft'` inherited. The registry (Task 4) already lists both classes; PHP class constants with `static::` late static binding make the inherited methods use the description constants.)

- [ ] **Step 5: Run tests, verify, commit**

Run: `vendor/bin/phpunit --filter FixerMetaPromptTest && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress && vendor/bin/phpcs includes/crawl-fixers/class-fixer-meta-title.php includes/crawl-fixers/class-fixer-meta-description.php`

```bash
git add includes/crawl-fixers/class-fixer-meta-title.php includes/crawl-fixers/class-fixer-meta-description.php tests/unit/FixerMetaPromptTest.php
git commit -m "Implement AI meta title/description Fix-It fixers"
```

---

### Task 10: Fix-It controller (AJAX verbs + wiring)

**Files:**
- Create: `includes/crawl-fixers/class-fixit-controller.php`
- Modify: `stratawp-seo.php` (require + instantiate)
- Test: `tests/unit/FixitControllerTest.php`

**Interfaces:**
- Consumes: `SWPS_Crawl_Issues::issues_for_run( int $run_id ): array` (rows grouped by type, Task 2 target fields), `SWPS_Crawl_Issues::mark_fixed()`, `SWPS_Crawl_Fixer_Registry` (Task 4), `SWPS_Fixit_Store` (Task 5), `SWPS_Rate_Limiter` (`can_generate(): bool`, `lock(): void`, `get_remaining_seconds(): int`).
- Produces:
  - AJAX actions `swps_fixit_draft_chunk`, `swps_fixit_apply`, `swps_fixit_undo`, `swps_fixit_dismiss` (nonce action `swps_fixit`, capability `manage_options`).
  - Testable pure helper `SWPS_Fixit_Controller::partition_rows( array $rows, int $offset, int $limit ): array{batch: array, remaining: int}`.
  - `drafts_for_group( int $run_id, string $check_id, array $rows ): array` — used by the Task 12 screen render.
  - Cron `swps_fixit_sweep` (weekly) deleting drafts older than 7 days.

- [ ] **Step 1: Write the failing test (pure part)**

```php
<?php
/**
 * Tests for SWPS_Fixit_Controller::partition_rows.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-fixers/class-fixit-controller.php';

final class FixitControllerTest extends TestCase {

	private function rows( int $n ): array {
		return array_map(
			static fn( $i ) => array( 'id' => $i ),
			range( 1, $n )
		);
	}

	public function test_partition_first_chunk(): void {
		$out = SWPS_Fixit_Controller::partition_rows( $this->rows( 12 ), 0, 5 );
		$this->assertCount( 5, $out['batch'] );
		$this->assertSame( 7, $out['remaining'] );
		$this->assertSame( 1, $out['batch'][0]['id'] );
	}

	public function test_partition_last_partial_chunk(): void {
		$out = SWPS_Fixit_Controller::partition_rows( $this->rows( 12 ), 10, 5 );
		$this->assertCount( 2, $out['batch'] );
		$this->assertSame( 0, $out['remaining'] );
	}

	public function test_partition_past_end(): void {
		$out = SWPS_Fixit_Controller::partition_rows( $this->rows( 3 ), 10, 5 );
		$this->assertSame( array(), $out['batch'] );
		$this->assertSame( 0, $out['remaining'] );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter FixitControllerTest`
Expected: FAIL.

- [ ] **Step 3: Write the controller**

```php
<?php
/**
 * AJAX controller for the Site Audit Fix-It engine.
 *
 * Thin nonce/capability ajax_* wrappers over testable do_*() methods
 * (SWPS_AEO_Optimizer convention). Draft generation is chunked with an
 * offset cursor — the codebase's standard batch idiom.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fix-It draft/apply/undo/dismiss endpoints.
 */
class SWPS_Fixit_Controller {

	public const NONCE_ACTION = 'swps_fixit';
	public const SWEEP_HOOK   = 'swps_fixit_sweep';

	/** Drafts per AJAX chunk (each is one AI call). */
	private const DRAFT_CHUNK = 5;

	/** Mechanical applies per AJAX chunk. */
	private const APPLY_CHUNK = 10;

	/** Drafts older than this are swept. */
	private const DRAFT_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Wire AJAX + sweep cron.
	 */
	public function __construct() {
		add_action( 'wp_ajax_swps_fixit_draft_chunk', array( $this, 'ajax_draft_chunk' ) );
		add_action( 'wp_ajax_swps_fixit_apply', array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_swps_fixit_undo', array( $this, 'ajax_undo' ) );
		add_action( 'wp_ajax_swps_fixit_dismiss', array( $this, 'ajax_dismiss' ) );
		add_action( self::SWEEP_HOOK, array( $this, 'sweep_drafts' ) );

		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::SWEEP_HOOK );
		}
	}

	// =========================================================================
	// PURE HELPERS (unit-tested)
	// =========================================================================

	/**
	 * Slice a row list for chunked processing.
	 *
	 * @param array $rows   All rows.
	 * @param int   $offset Start index.
	 * @param int   $limit  Chunk size.
	 * @return array {batch: array, remaining: int}
	 */
	public static function partition_rows( array $rows, int $offset, int $limit ): array {
		$batch     = array_slice( $rows, $offset, $limit );
		$remaining = max( 0, count( $rows ) - $offset - count( $batch ) );

		return array(
			'batch'     => $batch,
			'remaining' => $remaining,
		);
	}

	// =========================================================================
	// AJAX WRAPPERS
	// =========================================================================

	/** Draft the next chunk of AI proposals for one check group. */
	public function ajax_draft_chunk(): void {
		$this->guard();

		$result = $this->do_draft_chunk(
			(int) ( $_POST['run_id'] ?? 0 ),
			sanitize_key( (string) ( $_POST['check_id'] ?? '' ) ),
			max( 0, (int) ( $_POST['offset'] ?? 0 ) )
		);

		$this->respond( $result );
	}

	/** Apply drafts / run mechanical fixes for accepted rows. */
	public function ajax_apply(): void {
		$this->guard();

		$accepted = array_map( 'intval', (array) ( $_POST['issue_ids'] ?? array() ) );
		$result   = $this->do_apply(
			(int) ( $_POST['run_id'] ?? 0 ),
			sanitize_key( (string) ( $_POST['check_id'] ?? '' ) ),
			$accepted
		);

		$this->respond( $result );
	}

	/** Undo one applied fix. */
	public function ajax_undo(): void {
		$this->guard();

		$result = $this->do_undo(
			(int) ( $_POST['run_id'] ?? 0 ),
			(int) ( $_POST['issue_id'] ?? 0 )
		);

		$this->respond( $result );
	}

	/** Discard one stored draft. */
	public function ajax_dismiss(): void {
		$this->guard();

		$result = $this->do_dismiss(
			(int) ( $_POST['run_id'] ?? 0 ),
			(int) ( $_POST['issue_id'] ?? 0 )
		);

		$this->respond( $result );
	}

	/** Shared nonce + capability gate. */
	private function guard(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ), 403 );
		}
	}

	/** Send a do_*() result as JSON (error key → error response). */
	private function respond( array $result ): void {
		if ( isset( $result['error'] ) ) {
			wp_send_json_error(
				array( 'message' => (string) $result['error'] ),
				(int) ( $result['http_status'] ?? 400 )
			);
		}
		wp_send_json_success( $result );
	}

	// =========================================================================
	// DO METHODS
	// =========================================================================

	/**
	 * Draft the next DRAFT_CHUNK proposals for a check group.
	 *
	 * @param int    $run_id   Crawl run id.
	 * @param string $check_id Check id.
	 * @param int    $offset   Cursor into the group's fixable rows.
	 * @return array {drafted: array, remaining: int, errors: array} or {error, http_status}.
	 */
	public function do_draft_chunk( int $run_id, string $check_id, int $offset ): array {
		$fixer = SWPS_Crawl_Fixer_Registry::for_check( $check_id );
		if ( ! $fixer || 'draft' !== $fixer->kind() ) {
			return array(
				'error'       => __( 'This check has no draft fix.', 'stratawp-seo' ),
				'http_status' => 400,
			);
		}

		$limiter = new SWPS_Rate_Limiter();
		if ( ! $limiter->can_generate() ) {
			return array(
				'error'       => sprintf(
					/* translators: %d: seconds until the rate limit clears */
					__( 'Rate limited — try again in %d seconds.', 'stratawp-seo' ),
					$limiter->get_remaining_seconds()
				),
				'http_status' => 429,
			);
		}

		$rows      = $this->fixable_rows( $run_id, $check_id, $fixer );
		$partition = self::partition_rows( $rows, $offset, self::DRAFT_CHUNK );

		$drafted = array();
		$errors  = array();
		foreach ( $partition['batch'] as $issue ) {
			$out = $fixer->draft( $issue );
			if ( is_wp_error( $out ) ) {
				$errors[] = array(
					'issue_id' => (int) $issue['id'],
					'url'      => (string) $issue['url'],
					'reason'   => $out->get_error_message(),
				);
				continue;
			}
			$drafted[] = array(
				'issue_id' => (int) $issue['id'],
				'url'      => (string) $issue['url'],
				'current'  => (string) $out['current'],
				'proposed' => (string) $out['proposed'],
			);
		}
		$limiter->lock();

		return array(
			'drafted'   => $drafted,
			'errors'    => $errors,
			'remaining' => $partition['remaining'],
		);
	}

	/**
	 * Apply accepted issue rows for a group (drafts or mechanical), capped
	 * at APPLY_CHUNK per call; the JS loops on remaining ids.
	 *
	 * @param int    $run_id    Crawl run id.
	 * @param string $check_id  Check id.
	 * @param int[]  $issue_ids Accepted issue row ids.
	 * @return array {fixed, skipped, remaining_ids} or {error, http_status}.
	 */
	public function do_apply( int $run_id, string $check_id, array $issue_ids ): array {
		$fixer = SWPS_Crawl_Fixer_Registry::for_check( $check_id );
		if ( ! $fixer ) {
			return array(
				'error'       => __( 'This check has no fix.', 'stratawp-seo' ),
				'http_status' => 400,
			);
		}

		$by_id = array();
		foreach ( $this->fixable_rows( $run_id, $check_id, $fixer ) as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		$batch_ids = array_slice( $issue_ids, 0, self::APPLY_CHUNK );
		$fixed     = array();
		$skipped   = array();

		foreach ( $batch_ids as $issue_id ) {
			$issue = $by_id[ $issue_id ] ?? null;
			if ( ! $issue ) {
				$skipped[] = array(
					'issue_id' => $issue_id,
					'reason'   => __( 'Issue not found or not fixable.', 'stratawp-seo' ),
				);
				continue;
			}

			$out = $fixer->apply( $issue, array() );
			if ( is_wp_error( $out ) ) {
				$skipped[] = array(
					'issue_id' => $issue_id,
					'reason'   => $out->get_error_message(),
				);
				continue;
			}
			if ( ! empty( $out['changed'] ) ) {
				SWPS_Crawl_Issues::mark_fixed( $issue_id );
				$fixed[] = array(
					'issue_id' => $issue_id,
					'message'  => (string) $out['message'],
				);
			} else {
				$skipped[] = array(
					'issue_id' => $issue_id,
					'reason'   => (string) $out['message'],
				);
			}
		}

		return array(
			'fixed'         => $fixed,
			'skipped'       => $skipped,
			'remaining_ids' => array_values( array_slice( $issue_ids, self::APPLY_CHUNK ) ),
		);
	}

	/**
	 * Undo one fix.
	 *
	 * @param int $run_id   Crawl run id.
	 * @param int $issue_id Issue row id.
	 * @return array {undone: bool} or {error, http_status}.
	 */
	public function do_undo( int $run_id, int $issue_id ): array {
		$issue = $this->row_by_id( $run_id, $issue_id );
		if ( ! $issue ) {
			return array(
				'error'       => __( 'Issue not found.', 'stratawp-seo' ),
				'http_status' => 404,
			);
		}

		$fixer = SWPS_Crawl_Fixer_Registry::for_check( (string) $issue['type'] );
		if ( ! $fixer ) {
			return array(
				'error'       => __( 'This check has no fix.', 'stratawp-seo' ),
				'http_status' => 400,
			);
		}

		return array( 'undone' => $fixer->undo( $issue ) );
	}

	/**
	 * Discard the stored draft behind one issue row.
	 *
	 * @param int $run_id   Crawl run id.
	 * @param int $issue_id Issue row id.
	 * @return array {dismissed: bool} or {error, http_status}.
	 */
	public function do_dismiss( int $run_id, int $issue_id ): array {
		$issue = $this->row_by_id( $run_id, $issue_id );
		if ( ! $issue ) {
			return array(
				'error'       => __( 'Issue not found.', 'stratawp-seo' ),
				'http_status' => 404,
			);
		}

		$field = $this->field_for_check( (string) $issue['type'] );
		if ( null !== $field ) {
			SWPS_Fixit_Store::remove_draft( (string) $issue['object_type'], (int) $issue['object_id'], $field );
		}

		return array( 'dismissed' => true );
	}

	// =========================================================================
	// SCREEN SUPPORT
	// =========================================================================

	/**
	 * Stored drafts for a group's rows, keyed by issue id — used by the
	 * Site Audit screen to render the review table on page load.
	 *
	 * @param int    $run_id   Crawl run id.
	 * @param string $check_id Check id.
	 * @param array  $rows     The group's decoded rows.
	 * @return array issue_id => {current, proposed}
	 */
	public function drafts_for_group( int $run_id, string $check_id, array $rows ): array {
		$field = $this->field_for_check( $check_id );
		if ( null === $field ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$otype = (string) ( $row['object_type'] ?? '' );
			$oid   = (int) ( $row['object_id'] ?? 0 );
			if ( '' === $otype || 'none' === $otype || 0 === $oid ) {
				continue;
			}
			$drafts = SWPS_Fixit_Store::get_drafts( $otype, $oid );
			if ( isset( $drafts[ $field ] ) && $drafts[ $field ]['check_id'] === $check_id ) {
				$out[ (int) $row['id'] ] = array(
					'current'  => $drafts[ $field ]['current'],
					'proposed' => $drafts[ $field ]['proposed'],
				);
			}
		}

		return $out;
	}

	/**
	 * Weekly sweep: delete drafts older than DRAFT_TTL across posts and
	 * terms (direct meta query on the two meta tables).
	 */
	public function sweep_drafts(): void {
		global $wpdb;

		$cutoff = time() - self::DRAFT_TTL;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( array(
			array( $wpdb->postmeta, 'post_id', 'delete_post_meta' ),
			array( $wpdb->termmeta, 'term_id', 'delete_term_meta' ),
		) as list( $table, $id_col, $deleter ) ) {
			$ids = (array) $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT {$id_col} FROM {$table} WHERE meta_key = %s",
					SWPS_Fixit_Store::META_DRAFTS
				)
			);
			foreach ( $ids as $id ) {
				$otype  = 'delete_term_meta' === $deleter ? 'term' : 'post';
				$drafts = SWPS_Fixit_Store::get_drafts( $otype, (int) $id );
				$kept   = array_filter( $drafts, static fn( $d ) => $d['drafted_at'] >= $cutoff );
				if ( count( $kept ) === count( $drafts ) ) {
					continue;
				}
				call_user_func( $deleter, (int) $id, SWPS_Fixit_Store::META_DRAFTS );
				foreach ( $kept as $field => $draft ) {
					SWPS_Fixit_Store::put_draft( $otype, (int) $id, (string) $field, $draft );
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// =========================================================================
	// INTERNALS
	// =========================================================================

	/** A group's rows that the fixer says it can fix. */
	private function fixable_rows( int $run_id, string $check_id, SWPS_Crawl_Fixer $fixer ): array {
		$groups = SWPS_Crawl_Issues::issues_for_run( $run_id );
		$rows   = (array) ( $groups[ $check_id ] ?? array() );

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $fixer ) {
					return empty( $row['detail']['fixed_at'] ) && $fixer->can_fix( (array) $row );
				}
			)
		);
	}

	/** One decoded row by issue id. */
	private function row_by_id( int $run_id, int $issue_id ): ?array {
		foreach ( SWPS_Crawl_Issues::issues_for_run( $run_id ) as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $issue_id ) {
					return (array) $row;
				}
			}
		}
		return null;
	}

	/** Draft-store field for a draft-kind check id, null for mechanical. */
	private function field_for_check( string $check_id ): ?string {
		$map = array(
			'missing_title'              => 'meta_title',
			'title_too_long'             => 'meta_title',
			'title_too_short'            => 'meta_title',
			'duplicate_title'            => 'meta_title',
			'missing_meta_description'   => 'meta_description',
			'desc_too_long'              => 'meta_description',
			'duplicate_meta_description' => 'meta_description',
		);
		return $map[ $check_id ] ?? null;
	}
}
```

Adjust to reality during implementation: confirm `issues_for_run()` returns rows **grouped by type** (`$groups[ $check_id ]`) — the explorer report says it groups by type; if it returns a flat list instead, group it in `fixable_rows()`. Also confirm `SWPS_Rate_Limiter`'s constructor signature before instantiating (it may take a key/window — mirror how existing callers construct it; search `new SWPS_Rate_Limiter`).

- [ ] **Step 4: Wire into `stratawp-seo.php`**

Require (after the registry require):

```php
require_once SWPS_PLUGIN_DIR . 'includes/crawl-fixers/class-fixit-controller.php';
```

Instantiate next to the site audit screen (`stratawp-seo.php` ~line 481), with a typed public property beside `$site_audit_screen` (~line 301):

```php
	public SWPS_Fixit_Controller $fixit_controller;
```
```php
		$this->fixit_controller = new SWPS_Fixit_Controller();
```

Unschedule the sweep on deactivation next to the crawler's unschedule calls (~line 1508):

```php
	wp_unschedule_hook( SWPS_Fixit_Controller::SWEEP_HOOK );
```

- [ ] **Step 5: Run tests, verify, commit**

Run: `composer dump-autoload && vendor/bin/phpunit --filter FixitControllerTest && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress && vendor/bin/phpcs includes/crawl-fixers/class-fixit-controller.php`

```bash
git add includes/crawl-fixers/class-fixit-controller.php tests/unit/FixitControllerTest.php stratawp-seo.php
git commit -m "Add Fix-It AJAX controller with chunked draft/apply/undo"
```

---

### Task 11: Projected score

**Files:**
- Modify: `includes/crawl-checks/class-crawl-score.php`
- Test: `tests/unit/CrawlScoreTest.php` (extend the existing file)

**Interfaces:**
- Consumes: existing `SWPS_Crawl_Score::calculate( array $severity_counts, int $pages ): int`.
- Produces: `SWPS_Crawl_Score::project( array $severity_counts, array $fixed_counts, int $pages ): int` — the score if fixed issues vanish.

- [ ] **Step 1: Add failing tests to `tests/unit/CrawlScoreTest.php`**

```php
	public function test_project_subtracts_fixed_counts(): void {
		$base = SWPS_Crawl_Score::calculate( array( 'error' => 2, 'warning' => 10 ), 10 );
		$proj = SWPS_Crawl_Score::project(
			array( 'error' => 2, 'warning' => 10 ),
			array( 'warning' => 6 ),
			10
		);
		$this->assertGreaterThan( $base, $proj );
		$this->assertSame( SWPS_Crawl_Score::calculate( array( 'error' => 2, 'warning' => 4 ), 10 ), $proj );
	}

	public function test_project_clamps_negative_counts(): void {
		$proj = SWPS_Crawl_Score::project(
			array( 'error' => 1 ),
			array( 'error' => 5, 'warning' => 5 ),
			10
		);
		$this->assertSame( SWPS_Crawl_Score::calculate( array(), 10 ), $proj );
	}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter CrawlScoreTest`
Expected: FAIL — `project` undefined.

- [ ] **Step 3: Implement**

```php
	/**
	 * Score the run would have if the fixed issues vanish. Display-only —
	 * the real score always comes from the next crawl.
	 *
	 * @param array $severity_counts Current counts keyed by severity.
	 * @param array $fixed_counts    Fixed-issue counts keyed by severity.
	 * @param int   $pages           Total pages in the run.
	 * @return int Projected score clamped to [0, 100].
	 */
	public static function project( array $severity_counts, array $fixed_counts, int $pages ): int {
		$remaining = array();
		foreach ( array( 'error', 'warning', 'notice' ) as $sev ) {
			$remaining[ $sev ] = max(
				0,
				(int) ( $severity_counts[ $sev ] ?? 0 ) - (int) ( $fixed_counts[ $sev ] ?? 0 )
			);
		}
		return self::calculate( $remaining, $pages );
	}
```

- [ ] **Step 4: Run tests, verify, commit**

Run: `vendor/bin/phpunit --filter CrawlScoreTest && vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress`

```bash
git add includes/crawl-checks/class-crawl-score.php tests/unit/CrawlScoreTest.php
git commit -m "Add projected crawl score for the Fix-It dashboard"
```

---

### Task 12: Site Audit screen UI + JS + CSS

**Files:**
- Modify: `includes/class-site-audit-screen.php` (constructor, `render_header`, `render_issue_group`)
- Modify: `admin/js/site-audit.js` (+ regenerate `site-audit.min.js` the same way the repo does — check how existing `.min.js` files are produced: look for a build script in `package.json` or matching `*.min.js` committed artifacts, and follow that convention)
- Modify: `admin/css/site-audit.css`
- Modify: `stratawp-seo.php` (pass the controller into the screen)

**Interfaces:**
- Consumes: `SWPS_Fixit_Controller::NONCE_ACTION`, `drafts_for_group()`, the four AJAX actions (Task 10), `SWPS_Crawl_Fixer_Registry::kind_of()/for_check()` (Task 4), `SWPS_Crawl_Score::project()` (Task 11), issue rows' `object_type`/`object_id`/`detail.fixed_at` (Tasks 2–3).
- Produces: the user-facing Fix-It flow.

- [ ] **Step 1: Inject the controller**

Change the screen constructor signature to `__construct( SWPS_Site_Crawler $crawler, SWPS_Fixit_Controller $fixit )`, store `private SWPS_Fixit_Controller $fixit;`, and update the instantiation in `stratawp-seo.php` (~line 481):

```php
		$this->fixit_controller  = new SWPS_Fixit_Controller();
		$this->site_audit_screen = new SWPS_Site_Audit_Screen( $this->site_crawler, $this->fixit_controller );
```

(Instantiate the controller BEFORE the screen; Task 10's standalone instantiation line is replaced by this ordered pair.)

- [ ] **Step 2: Add Fix buttons + review table to `render_issue_group()`**

`render_issue_group()` currently receives `( string $type, string $title, string $severity, string $how_to, array $rows, int $count, int $delta )`. Inside the `.swps-audit-issue-actions` div, after the exclude form, add:

```php
		$kind = SWPS_Crawl_Fixer_Registry::kind_of( $type );
		if ( null !== $kind ) {
			$fixer    = SWPS_Crawl_Fixer_Registry::for_check( $type );
			$fixable  = array_values( array_filter( $rows, static fn( $r ) => empty( $r['detail']['fixed_at'] ) && $fixer->can_fix( (array) $r ) ) );
			$fixed_n  = count( array_filter( $rows, static fn( $r ) => ! empty( $r['detail']['fixed_at'] ) ) );
			$run_id   = (int) ( $rows[0]['run_id'] ?? 0 ); // Present on decoded rows; if absent, pass run id into render_issue_group from render().
			$nonce    = wp_create_nonce( SWPS_Fixit_Controller::NONCE_ACTION );
			$fix_ids  = wp_json_encode( wp_list_pluck( $fixable, 'id' ) );

			if ( array() !== $fixable ) {
				$label = 'draft' === $kind ? __( '✨ Fix with AI', 'stratawp-seo' ) : __( 'Fix now', 'stratawp-seo' );
				printf(
					'<button type="button" class="swps-btn swps-btn-primary" data-swps-fixit="%s" data-kind="%s" data-check="%s" data-run="%d" data-nonce="%s" data-ids="%s">%s</button>',
					esc_attr( $type ),
					esc_attr( $kind ),
					esc_attr( $type ),
					$run_id,
					esc_attr( $nonce ),
					esc_attr( (string) $fix_ids ),
					esc_html( $label )
				);
			}
			$not_fixable = $count - count( $fixable ) - $fixed_n;
			if ( $not_fixable > 0 && array() !== $fixable ) {
				/* translators: %d: rows without a resolvable WordPress object */
				echo '<span class="swps-audit-fixit-note">' . esc_html( sprintf( __( '%d not auto-fixable (no linked post/term).', 'stratawp-seo' ), $not_fixable ) ) . '</span>';
			}
			if ( $fixed_n > 0 ) {
				/* translators: %d: fixed issue count */
				echo '<span class="swps-audit-fixit-fixed">' . esc_html( sprintf( __( '%d fixed — re-run the audit to verify.', 'stratawp-seo' ), $fixed_n ) ) . '</span>';
			}
		}
```

**Note:** if decoded rows lack `run_id`, thread the displayed run id from `render()` down through `render_issue_groups()` into `render_issue_group()` as a parameter — mechanical signature change, follow the existing call chain.

Then, after the existing page-list `</table>`, render the review table when drafts exist:

```php
		if ( 'draft' === ( $kind ?? null ) ) {
			$drafts = $this->fixit->drafts_for_group( $run_id, $type, $rows );
			if ( array() !== $drafts ) {
				$this->render_review_table( $type, $run_id, $rows, $drafts );
			}
		}
```

New method:

```php
	/**
	 * Review table for stored AI drafts: current vs proposed with per-row
	 * accept checkboxes. Server-rendered; the JS driver applies selections
	 * via swps_fixit_apply and reloads.
	 *
	 * @param string $type   Check id.
	 * @param int    $run_id Displayed run id.
	 * @param array  $rows   The group's decoded issue rows.
	 * @param array  $drafts issue_id => {current, proposed}.
	 */
	private function render_review_table( string $type, int $run_id, array $rows, array $drafts ): void {
		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		$nonce = wp_create_nonce( SWPS_Fixit_Controller::NONCE_ACTION );

		echo '<div class="swps-audit-review" data-swps-review="' . esc_attr( $type ) . '" data-run="' . (int) $run_id . '" data-nonce="' . esc_attr( $nonce ) . '">';
		echo '<h4>' . esc_html__( 'Review drafts', 'stratawp-seo' ) . '</h4>';
		echo '<table class="widefat swps-audit-review-table"><thead><tr>';
		echo '<th class="check-column"><input type="checkbox" checked data-swps-review-all /></th>';
		echo '<th>' . esc_html__( 'Page', 'stratawp-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Current', 'stratawp-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Proposed', 'stratawp-seo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $drafts as $issue_id => $draft ) {
			$url = (string) ( $by_id[ $issue_id ]['url'] ?? '' );
			echo '<tr>';
			echo '<td><input type="checkbox" checked value="' . (int) $issue_id . '" data-swps-review-row /></td>';
			echo '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a></td>';
			echo '<td>' . ( '' !== $draft['current'] ? esc_html( $draft['current'] ) : '<em>' . esc_html__( '(empty)', 'stratawp-seo' ) . '</em>' ) . '</td>';
			echo '<td>' . esc_html( $draft['proposed'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<div class="swps-audit-review-actions">';
		echo '<button type="button" class="swps-btn swps-btn-primary" data-swps-review-apply>' . esc_html__( 'Apply selected', 'stratawp-seo' ) . '</button>';
		echo '<button type="button" class="swps-btn swps-btn-secondary" data-swps-review-dismiss>' . esc_html__( 'Dismiss all', 'stratawp-seo' ) . '</button>';
		echo '<span class="swps-audit-review-status" aria-live="polite"></span>';
		echo '</div>';
		echo '</div>';
	}
```

- [ ] **Step 3: Projected score in `render_header()`**

Where the current score renders, add after it (guarded — only when any displayed row is fixed):

```php
		$fixed_by_sev = $this->fixed_severity_counts( $issues_by_type );
		if ( array_sum( $fixed_by_sev ) > 0 ) {
			$projected = SWPS_Crawl_Score::project( $severity_counts, $fixed_by_sev, $total_pages );
			/* translators: %d: projected health score */
			echo '<span class="swps-audit-projected">' . esc_html( sprintf( __( '→ projected %d after re-crawl', 'stratawp-seo' ), $projected ) ) . '</span>';
		}
```

with helper:

```php
	/**
	 * Severity counts of issues stamped fixed_at in the displayed run.
	 *
	 * @param array $issues_by_type Decoded rows grouped by check id.
	 * @return array {error: int, warning: int, notice: int}
	 */
	private function fixed_severity_counts( array $issues_by_type ): array {
		$counts = array( 'error' => 0, 'warning' => 0, 'notice' => 0 );
		foreach ( $issues_by_type as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( ! empty( $row['detail']['fixed_at'] ) ) {
					$sev = (string) ( $row['severity'] ?? 'warning' );
					if ( isset( $counts[ $sev ] ) ) {
						++$counts[ $sev ];
					}
				}
			}
		}
		return $counts;
	}
```

Wire the variables from what `render()` already has in scope (`$severity_counts`, page counts, and the grouped issues it passes to `render_issue_groups()`); adapt names to the actual locals when editing.

- [ ] **Step 4: JS driver**

Append to `admin/js/site-audit.js` inside the existing IIFE, following its style (vanilla, `window.fetch`, dataset config):

```js
	// ---- Fix-It ----

	function post( action, data ) {
		var body = new window.URLSearchParams();
		body.set( 'action', action );
		Object.keys( data ).forEach( function ( k ) {
			if ( Array.isArray( data[ k ] ) ) {
				data[ k ].forEach( function ( v ) { body.append( k + '[]', v ); } );
			} else {
				body.set( k, data[ k ] );
			}
		} );
		return window.fetch( window.ajaxurl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } );
	}

	// Draft loop (✨ Fix with AI) and mechanical apply loop (Fix now).
	document.querySelectorAll( '[data-swps-fixit]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			var check = btn.dataset.check;
			var base  = { nonce: btn.dataset.nonce, run_id: btn.dataset.run, check_id: check };

			if ( 'draft' === btn.dataset.kind ) {
				var offset = 0;
				var drafted = 0;
				( function chunk() {
					btn.textContent = 'Drafting… (' + drafted + ')';
					post( 'swps_fixit_draft_chunk', Object.assign( { offset: offset }, base ) )
						.then( function ( res ) {
							if ( ! res.success ) {
								btn.textContent = res.data && res.data.message ? res.data.message : 'Error';
								return;
							}
							drafted += res.data.drafted.length;
							offset  += res.data.drafted.length + res.data.errors.length;
							if ( res.data.remaining > 0 ) {
								chunk();
							} else {
								window.location.reload();
							}
						} );
				}() );
				return;
			}

			// Mechanical: apply all fixable ids in chunks.
			var ids = JSON.parse( btn.dataset.ids || '[]' );
			( function applyChunk( remaining ) {
				if ( ! remaining.length ) {
					window.location.reload();
					return;
				}
				btn.textContent = 'Fixing… (' + remaining.length + ' left)';
				post( 'swps_fixit_apply', Object.assign( { issue_ids: remaining }, base ) )
					.then( function ( res ) {
						if ( ! res.success ) {
							btn.textContent = res.data && res.data.message ? res.data.message : 'Error';
							return;
						}
						applyChunk( res.data.remaining_ids );
					} );
			}( ids ) );
		} );
	} );

	// Review-table actions.
	document.querySelectorAll( '[data-swps-review]' ).forEach( function ( panel ) {
		var base = {
			nonce: panel.dataset.nonce,
			run_id: panel.dataset.run,
			check_id: panel.dataset.swpsReview
		};
		var status = panel.querySelector( '.swps-audit-review-status' );

		var all = panel.querySelector( '[data-swps-review-all]' );
		if ( all ) {
			all.addEventListener( 'change', function () {
				panel.querySelectorAll( '[data-swps-review-row]' ).forEach( function ( cb ) {
					cb.checked = all.checked;
				} );
			} );
		}

		panel.querySelector( '[data-swps-review-apply]' ).addEventListener( 'click', function () {
			var ids = Array.prototype.filter.call(
				panel.querySelectorAll( '[data-swps-review-row]' ),
				function ( cb ) { return cb.checked; }
			).map( function ( cb ) { return cb.value; } );
			if ( ! ids.length ) { return; }

			( function applyChunk( remaining ) {
				if ( ! remaining.length ) {
					window.location.reload();
					return;
				}
				status.textContent = 'Applying… (' + remaining.length + ' left)';
				post( 'swps_fixit_apply', Object.assign( { issue_ids: remaining }, base ) )
					.then( function ( res ) {
						if ( ! res.success ) {
							status.textContent = res.data && res.data.message ? res.data.message : 'Error';
							return;
						}
						applyChunk( res.data.remaining_ids );
					} );
			}( ids ) );
		} );

		panel.querySelector( '[data-swps-review-dismiss]' ).addEventListener( 'click', function () {
			var ids = Array.prototype.map.call(
				panel.querySelectorAll( '[data-swps-review-row]' ),
				function ( cb ) { return cb.value; }
			);
			( function dismissNext( remaining ) {
				if ( ! remaining.length ) {
					window.location.reload();
					return;
				}
				post( 'swps_fixit_dismiss', Object.assign( { issue_id: remaining[ 0 ] }, base ) )
					.then( function () { dismissNext( remaining.slice( 1 ) ); } );
			}( ids ) );
		} );
	} );
```

Regenerate `admin/js/site-audit.min.js` following the repo's existing minification convention (see how 4.26.5 "Serve minified frontend analytics tracker" did it — check `package.json` scripts or the committed artifact pattern, and replicate).

- [ ] **Step 5: CSS**

Append to `admin/css/site-audit.css`, using the tokenized custom properties the shell uses (`--swps-text-primary` etc. — NOT the legacy `--swps-text`, per the token-clobber gotcha):

```css
/* Fix-It */
.swps-audit-fixit-note,
.swps-audit-fixit-fixed {
	font-size: 12px;
	color: var(--swps-text-secondary);
	align-self: center;
}

.swps-audit-fixit-fixed {
	color: var(--swps-success, #1a7f37);
}

.swps-audit-review {
	margin-top: 16px;
	padding: 12px;
	border: 1px solid var(--swps-border);
	border-radius: 6px;
}

.swps-audit-review-table td {
	vertical-align: top;
}

.swps-audit-review-actions {
	display: flex;
	gap: 8px;
	margin-top: 10px;
	align-items: center;
}

.swps-audit-projected {
	margin-left: 8px;
	font-weight: 600;
	color: var(--swps-success, #1a7f37);
}
```

(Verify variable names against `admin/css/tokens.css` while editing; use whatever success/border token names actually exist there.)

- [ ] **Step 6: Verify and commit**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress && vendor/bin/phpcs includes/class-site-audit-screen.php`
(phpcs on the screen file: no NEW violations vs `git stash`-baseline — the file has backlog.)

```bash
git add includes/class-site-audit-screen.php admin/js/site-audit.js admin/js/site-audit.min.js admin/css/site-audit.css stratawp-seo.php
git commit -m "Site Audit screen: Fix-It buttons, review table, projected score"
```

---

### Task 13: Version bump, changelog, README feature docs

**Files:**
- Modify: `stratawp-seo.php` (header `Version:` + `SWPS_VERSION` → `4.28.0`)
- Modify: `readme.txt` (`Stable tag: 4.28.0` + changelog entry)
- Modify: `README.md` (Site Audit section: document the Fix-It flow; add the new screenshot references Task 14 will capture)

- [ ] **Step 1: Bump versions**

`stratawp-seo.php`: `Version: 4.28.0` and `define( 'SWPS_VERSION', '4.28.0' );`
`readme.txt`: `Stable tag: 4.28.0` and prepend changelog:

```
= 4.28.0 =
* New: Site Audit Fix-It engine — audit findings are now one-click fixable. Missing, duplicate, too-long and too-short meta titles and descriptions get AI-drafted replacements you review and apply (and can undo) right on the audit dashboard; mixed-content URLs, internal nofollow links, missing image alt text, and noindexed-but-sitemapped pages are fixed mechanically with one click. Every fix snapshots the previous value for undo, fixed issues show a projected health score, and the next crawl verifies the real one.
* New: crawl issues now record which post, term, or author archive each affected URL belongs to.
```

Note: if the 4.27.1 PR (#101) merged first (expected), this diff applies on top of it; the changelog entry sits above 4.27.1's.

- [ ] **Step 2: README.md Site Audit section**

Extend the existing Site Audit section (search `README.md` for the `swps-site-crawl` / Site Audit content around lines 129 and 321–341): describe the Fix with AI flow (draft → review → apply → undo → re-crawl) in the same second-person how-to style as neighboring sections, and reference `screenshots/swps-fixit-review.png` (captured in Task 14).

- [ ] **Step 3: Verify and commit**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress`
Check the three version locations agree: `grep -n "4.28.0" stratawp-seo.php readme.txt | head`

```bash
git add stratawp-seo.php readme.txt README.md
git commit -m "Bump to 4.28.0: Fix-It engine changelog and docs"
```

---

### Task 14: LocalWP smoke test + screenshot refresh

**Files:**
- Replace: every image in `screenshots/` referenced by `README.md` (~25 files, same filenames), plus new `screenshots/swps-fixit-review.png`
- Replace: `.wordpress-org/screenshot-1.png` … `screenshot-10.png`

This task is manual/browser-driven (no unit tests). Use the LocalWP dev site that symlinks this repo (per project memory: the site runs the checked-out branch live; WP-CLI available via env-export + `wp` commands; verify rendered pages in the browser, not via same-client DB readback).

- [ ] **Step 1: Smoke the full Fix-It loop on the dev site**

1. Check out `feature/audit-fix-it-engine` (already the branch), visit wp-admin → StrataWP SEO → Site Audit, click **Re-run audit**; wait for completion.
2. Verify targets landed: `wp db query "SELECT object_type, COUNT(*) FROM wp_swps_crawl_issues WHERE object_type IS NOT NULL GROUP BY 1"` — expect post/term rows.
3. Seed fixable issues if the site is too clean (clear `_swps_meta_description` on a few posts via WP-CLI, add an `http://` image URL to one post, add `rel="nofollow"` to an internal link) and re-crawl.
4. Run **✨ Fix with AI** on the missing-meta-description group → confirm chunked progress, review table, per-row checkboxes, cost-free rendering of current-vs-proposed.
5. **Apply selected** → page reloads → group shows "N fixed — re-run the audit to verify" and the projected score appears in the header.
6. View a fixed post's rendered page source: `<meta name="description">` shows the applied text.
7. **Undo** path: `wp post meta get <ID> _swps_fixit_snapshot` exists post-apply; trigger undo via the UI if rendered, or `wp eval` calling the fixer's `undo()`; confirm the meta reverts.
8. **Fix now** on mixed-content and nofollow groups → verify post content rewritten and snapshots present.
9. Re-run the audit → fixed issues gone from the new run; health score up.
10. Confirm no PHP notices in the debug log throughout: `tail -50 wp-content/debug.log`.

- [ ] **Step 2: Fix anything the smoke test surfaces**

Debug with the systematic-debugging skill; commit fixes individually.

- [ ] **Step 3: Screenshot refresh (approved plan)**

At a consistent browser window size (match the existing screenshots' aspect — inspect one: `sips -g pixelWidth -g pixelHeight screenshots/stratawp-seo.png`), re-capture every screen `README.md` references, saving over the same filenames in `screenshots/`. Capture the new hero: the Fix-It review table mid-flow as `screenshots/swps-fixit-review.png`, and a post-fix dashboard showing the projected score. Seed the site with realistic content first (posts, keywords, an audit run) so no screen looks empty. Then copy the 10 best over `.wordpress-org/screenshot-N.png` keeping the existing N→screen mapping (compare each old `screenshot-N.png` with its `screenshots/` twin by file size/appearance to preserve the mapping).

- [ ] **Step 4: Commit**

```bash
git add screenshots/ .wordpress-org/
git commit -m "Refresh all README and wp.org screenshots for 4.28.0"
```

- [ ] **Step 5: Final verification, PR**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=2G --no-progress && vendor/bin/phpcs includes/crawl-fixers/ includes/class-crawl-target.php`
Push and open the PR (repo attribution rules; changelog-style body; note the DB v4 migration and the new cron hook).

---

## Self-Review Notes (already applied)

- Spec coverage: resolution (T1–T3), registry/fixers (T4–T9), store (T5), AJAX (T10), projection (T11), UI (T12), release + screenshots (T13–T14). Spec's error-handling and rate-limiting requirements land in T10; sanitizers in T8/T9; the `wp_slash` and snapshot-merge requirements in T5.
- The registry test (T4) requires stub fixers in the same task so the suite is green at every commit.
- `run_id` on decoded issue rows is unverified — T12 carries an explicit fallback instruction (thread it from `render()`), and T10 reads run_id from POST, not rows.
- `SWPS_Rate_Limiter` constructor args unverified — T10 instructs mirroring an existing call site.
