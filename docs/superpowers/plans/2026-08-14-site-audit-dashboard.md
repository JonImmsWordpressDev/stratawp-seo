# Site Audit Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Crawl-based Semrush-parity SEO check suite (~25 checks) plus a Site Audit dashboard screen with health score, error/warning/notice triage, per-issue page lists, and crawl-over-crawl trends.

**Architecture:** Approach B from the spec — a new `swps_crawl_pages` table stores per-URL page facts written during `process_chunk()`; a check registry (`includes/crawl-checks/`, one class per check) runs per-page checks at crawl time and aggregate checks (SQL/PHP over the pages table) in `finish_run()`; a new server-rendered admin-shell screen reads only from the tables and run summary.

**Tech Stack:** PHP 8.1+, WordPress plugin APIs, DOMDocument parsing, PHPUnit 9 (pure-PHP, no WP loaded), PHPStan.

**Spec:** `docs/superpowers/specs/2026-08-14-site-audit-dashboard-design.md`

## Global Constraints

- Branch: `feature/site-audit-dashboard` (spec already committed there).
- Commits authored solely as `Jon Imms <60996163+JonImmsWordpressDev@users.noreply.github.com>` — NO Co-Authored-By/AI attribution (repo CLAUDE.md rule; overrides harness default).
- Tests are pure-PHP PHPUnit in `tests/unit/` — WordPress is never loaded; guard WP-function stubs with `function_exists`. Run: `vendor/bin/phpunit --testsuite unit`.
- PHPStan must stay clean: `composer analyze`.
- WP-glue is smoke-tested via WP-CLI against the local site (`jonimms.local`), not unit tests.
- Version bump lands ONLY in the final task: `4.27.0` in 3 places (stratawp-seo.php `Version:` header, `SWPS_VERSION`, readme.txt `Stable tag:`) + readme changelog entry.
- Issue rows always have exactly the keys: `type`, `url`, `detail` (array), `severity` (`error`|`warning`|`notice`).
- All frontend/admin JS ships a `.min.js` build (terser) enqueued via the `SCRIPT_DEBUG` suffix convention.

---

### Task 1: Page-facts flags helper + `swps_crawl_pages` table (DB v2)

**Files:**
- Create: `includes/crawl-checks/class-crawl-page-flags.php`
- Modify: `includes/class-crawl-issues.php` (DB_VERSION → '2', new table DDL, insert/query/prune)
- Create: `tests/unit/CrawlPageFlagsTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `SWPS_Crawl_Page_Flags` constants: `HAS_VIEWPORT=1, HAS_DOCTYPE=2, HAS_LANG=4, HAS_CHARSET=8, HAS_NOINDEX=16, IS_CHALLENGE=32, IS_COMPRESSED=64, IS_ARCHIVE=128, IS_PAGINATED=256`
  - `SWPS_Crawl_Page_Flags::pack( array $facts ): int` — reads boolean keys `has_viewport, has_doctype, has_lang, has_charset, has_noindex, is_challenge, is_compressed, is_archive, is_paginated`
  - `SWPS_Crawl_Page_Flags::has( int $flags, int $flag ): bool`
  - `SWPS_Crawl_Issues::TABLE_PAGES = 'swps_crawl_pages'`
  - `SWPS_Crawl_Issues::insert_page( int $run_id, array $row ): void` — row keys: `url, status_code, content_type, title, title_hash, meta_desc_hash, desc_length, flags, word_count, html_bytes, text_bytes, h1_count, canonical, internal_links (array→JSON), unminified_assets (array→JSON)`
  - `SWPS_Crawl_Issues::pages_for_run( int $run_id ): array` — rows with JSON columns decoded back to arrays
  - `SWPS_Crawl_Issues::page_counts( int $run_id ): array{total:int, healthy:int, broken:int}`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/unit/CrawlPageFlagsTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-page-flags.php';

final class CrawlPageFlagsTest extends TestCase {
	public function test_pack_sets_bits_for_true_facts(): void {
		$flags = SWPS_Crawl_Page_Flags::pack(
			array(
				'has_viewport' => true,
				'has_doctype'  => true,
				'has_lang'     => false,
				'is_challenge' => true,
			)
		);
		$this->assertTrue( SWPS_Crawl_Page_Flags::has( $flags, SWPS_Crawl_Page_Flags::HAS_VIEWPORT ) );
		$this->assertTrue( SWPS_Crawl_Page_Flags::has( $flags, SWPS_Crawl_Page_Flags::HAS_DOCTYPE ) );
		$this->assertTrue( SWPS_Crawl_Page_Flags::has( $flags, SWPS_Crawl_Page_Flags::IS_CHALLENGE ) );
		$this->assertFalse( SWPS_Crawl_Page_Flags::has( $flags, SWPS_Crawl_Page_Flags::HAS_LANG ) );
		$this->assertFalse( SWPS_Crawl_Page_Flags::has( $flags, SWPS_Crawl_Page_Flags::HAS_CHARSET ) );
	}

	public function test_missing_keys_default_to_unset(): void {
		$this->assertSame( 0, SWPS_Crawl_Page_Flags::pack( array() ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/unit/CrawlPageFlagsTest.php`
Expected: FAIL — file/class not found.

- [ ] **Step 3: Write the flags class**

```php
<?php
// includes/crawl-checks/class-crawl-page-flags.php
/**
 * Bitmask flags for swps_crawl_pages rows.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Crawl_Page_Flags {

	public const HAS_VIEWPORT  = 1;
	public const HAS_DOCTYPE   = 2;
	public const HAS_LANG      = 4;
	public const HAS_CHARSET   = 8;
	public const HAS_NOINDEX   = 16;
	public const IS_CHALLENGE  = 32;
	public const IS_COMPRESSED = 64;
	public const IS_ARCHIVE    = 128;
	public const IS_PAGINATED  = 256;

	/** Fact key => bit. */
	private const MAP = array(
		'has_viewport'  => self::HAS_VIEWPORT,
		'has_doctype'   => self::HAS_DOCTYPE,
		'has_lang'      => self::HAS_LANG,
		'has_charset'   => self::HAS_CHARSET,
		'has_noindex'   => self::HAS_NOINDEX,
		'is_challenge'  => self::IS_CHALLENGE,
		'is_compressed' => self::IS_COMPRESSED,
		'is_archive'    => self::IS_ARCHIVE,
		'is_paginated'  => self::IS_PAGINATED,
	);

	public static function pack( array $facts ): int {
		$flags = 0;
		foreach ( self::MAP as $key => $bit ) {
			if ( ! empty( $facts[ $key ] ) ) {
				$flags |= $bit;
			}
		}
		return $flags;
	}

	public static function has( int $flags, int $flag ): bool {
		return 0 !== ( $flags & $flag );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/unit/CrawlPageFlagsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Add the pages table to `SWPS_Crawl_Issues`**

In `includes/class-crawl-issues.php`:
- Change `public const DB_VERSION = '1';` to `'2'` and add `public const TABLE_PAGES = 'swps_crawl_pages';`.
- In `create_tables()`, add a second `dbDelta` DDL alongside the existing ones:

```php
$pages = $wpdb->prefix . self::TABLE_PAGES;
$sql_pages = "CREATE TABLE {$pages} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    url VARCHAR(500) NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    content_type VARCHAR(100) NOT NULL DEFAULT '',
    title TEXT,
    title_hash CHAR(32) NOT NULL DEFAULT '',
    meta_desc_hash CHAR(32) NOT NULL DEFAULT '',
    desc_length SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    flags INT UNSIGNED NOT NULL DEFAULT 0,
    word_count INT UNSIGNED NOT NULL DEFAULT 0,
    html_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    text_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    h1_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    canonical VARCHAR(500) DEFAULT NULL,
    internal_links LONGTEXT,
    unminified_assets TEXT,
    PRIMARY KEY  (id),
    KEY idx_run (run_id),
    KEY idx_run_title (run_id, title_hash),
    KEY idx_run_desc (run_id, meta_desc_hash)
) {$charset_collate};";
dbDelta( $sql_pages );
```

- Add methods (place after `insert_issue()`):

```php
/**
 * Insert one crawled-page facts row.
 */
public static function insert_page( int $run_id, array $row ): void {
	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prefix . self::TABLE_PAGES,
		array(
			'run_id'            => $run_id,
			'url'               => substr( (string) ( $row['url'] ?? '' ), 0, 500 ),
			'status_code'       => (int) ( $row['status_code'] ?? 0 ),
			'content_type'      => substr( (string) ( $row['content_type'] ?? '' ), 0, 100 ),
			'title'             => (string) ( $row['title'] ?? '' ),
			'title_hash'        => (string) ( $row['title_hash'] ?? '' ),
			'meta_desc_hash'    => (string) ( $row['meta_desc_hash'] ?? '' ),
			'desc_length'       => (int) ( $row['desc_length'] ?? 0 ),
			'flags'             => (int) ( $row['flags'] ?? 0 ),
			'word_count'        => (int) ( $row['word_count'] ?? 0 ),
			'html_bytes'        => (int) ( $row['html_bytes'] ?? 0 ),
			'text_bytes'        => (int) ( $row['text_bytes'] ?? 0 ),
			'h1_count'          => (int) ( $row['h1_count'] ?? 0 ),
			'canonical'         => isset( $row['canonical'] ) ? substr( (string) $row['canonical'], 0, 500 ) : null,
			'internal_links'    => wp_json_encode( array_values( $row['internal_links'] ?? array() ) ),
			'unminified_assets' => wp_json_encode( array_values( $row['unminified_assets'] ?? array() ) ),
		)
	);
}

/**
 * All page rows for a run, JSON columns decoded.
 */
public static function pages_for_run( int $run_id ): array {
	global $wpdb;
	$table = $wpdb->prefix . self::TABLE_PAGES;
	$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY url ASC", $run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
	foreach ( $rows as &$row ) {
		$row['internal_links']    = json_decode( (string) $row['internal_links'], true ) ?: array();
		$row['unminified_assets'] = json_decode( (string) $row['unminified_assets'], true ) ?: array();
		$row['flags']             = (int) $row['flags'];
		$row['status_code']       = (int) $row['status_code'];
	}
	return $rows;
}

/**
 * Total / healthy (2xx, zero issues) / broken (>=400 or 0) page counts.
 */
public static function page_counts( int $run_id ): array {
	global $wpdb;
	$pages  = $wpdb->prefix . self::TABLE_PAGES;
	$issues = $wpdb->prefix . self::TABLE_ISSUES;
	$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pages} WHERE run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$broken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pages} WHERE run_id = %d AND (status_code >= 400 OR status_code = 0)", $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$with_issues = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.url) FROM {$pages} p INNER JOIN {$issues} i ON i.run_id = p.run_id AND i.url = p.url WHERE p.run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return array(
		'total'   => $total,
		'healthy' => max( 0, $total - $with_issues ),
		'broken'  => $broken,
	);
}
```

- In `prune_old_runs()`, add the same run-scoped `DELETE` for `TABLE_PAGES` next to the existing issues delete (copy the existing delete statement pattern, swapping the table).

- [ ] **Step 6: Static checks + commit**

Run: `vendor/bin/phpunit --testsuite unit` (all green) and `composer analyze` (clean; add baseline-free code — fix any new PHPStan findings inline).

```bash
git add includes/crawl-checks/class-crawl-page-flags.php includes/class-crawl-issues.php tests/unit/CrawlPageFlagsTest.php
git commit -m "Site audit: page-facts flags helper + swps_crawl_pages table (DB v2)"
```

---

### Task 2: Extraction expansion — `parse_html()` facts + challenge detection

**Files:**
- Modify: `includes/class-site-crawler.php` (`parse_html()`, ~line 268)
- Create: `tests/unit/SiteCrawlerExtractionTest.php`
- Create: `tests/fixtures/sgcaptcha-challenge.html`

**Interfaces:**
- Consumes: existing `parse_html( string $html, string $base_url ): array`.
- Produces: `parse_html()` result gains keys (all in addition to existing `links, images, canonical, h1_count, has_noindex, mixed`):
  - `title` (string, trimmed, '' if absent), `meta_desc` (string, ''), `has_viewport` (bool), `has_doctype` (bool), `has_charset` (bool), `has_lang` (bool)
  - `word_count` (int), `text_bytes` (int)
  - `script_srcs` (string[] absolute), `style_hrefs` (string[] absolute)
  - `images_missing_alt` (string[] absolute src)
  - `hreflangs` (array of `['lang' => string, 'href' => string]`)
  - `has_schema` (bool, any `<script type="application/ld+json">`)
  - `nofollow_internal` (string[] hrefs of internal `rel~=nofollow` anchors — internal test deferred to caller via full URL list; store all nofollow hrefs here)
  - `is_challenge` (bool), `is_archive` (bool from `<body class>` containing `archive`), `is_paginated` (bool: base_url path matches `#/page/\d+/?$#`)

- [ ] **Step 1: Create the challenge fixture**

```html
<!-- tests/fixtures/sgcaptcha-challenge.html -->
<html><head><link rel="icon" href="data:;"><meta http-equiv="refresh" content="0;/.well-known/sgcaptcha/?r=%2Fblog%2Ftag%2Fgutenberg%2F&y=powu:136.46.19.131:1786686021.878"></meta></head></html>
```

(These are the exact bytes SiteGround served on 2026-08-13.)

- [ ] **Step 2: Write the failing tests**

```php
<?php
// tests/unit/SiteCrawlerExtractionTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-site-crawler.php';

final class SiteCrawlerExtractionTest extends TestCase {

	private const BASE = 'https://example.com/blog/tag/gutenberg/';

	private function full_page(): string {
		return '<!DOCTYPE html><html lang="en-US"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title> Gutenberg Archives - Example </title>'
			. '<meta name="description" content="Posts tagged gutenberg.">'
			. '<link rel="alternate" hreflang="en" href="https://example.com/en/">'
			. '<script type="application/ld+json">{"@context":"https://schema.org"}</script>'
			. '</head><body class="archive tag">'
			. '<h1>gutenberg</h1><p>one two three four five</p>'
			. '<a href="/about/" rel="nofollow">about</a>'
			. '<img src="/a.png"><img src="/b.png" alt="described">'
			. '<script src="/js/app.js"></script>'
			. '<link rel="stylesheet" href="/css/main.css">'
			. '</body></html>';
	}

	public function test_head_facts_extracted(): void {
		$r = SWPS_Site_Crawler::parse_html( $this->full_page(), self::BASE );
		$this->assertSame( 'Gutenberg Archives - Example', $r['title'] );
		$this->assertSame( 'Posts tagged gutenberg.', $r['meta_desc'] );
		$this->assertTrue( $r['has_viewport'] );
		$this->assertTrue( $r['has_doctype'] );
		$this->assertTrue( $r['has_charset'] );
		$this->assertTrue( $r['has_lang'] );
		$this->assertTrue( $r['has_schema'] );
		$this->assertTrue( $r['is_archive'] );
		$this->assertFalse( $r['is_paginated'] );
		$this->assertFalse( $r['is_challenge'] );
	}

	public function test_content_and_asset_facts(): void {
		$r = SWPS_Site_Crawler::parse_html( $this->full_page(), self::BASE );
		$this->assertSame( 6, $r['word_count'] ); // "gutenberg one two three four five"
		$this->assertGreaterThan( 0, $r['text_bytes'] );
		$this->assertSame( array( 'https://example.com/js/app.js' ), $r['script_srcs'] );
		$this->assertSame( array( 'https://example.com/css/main.css' ), $r['style_hrefs'] );
		$this->assertSame( array( 'https://example.com/a.png' ), $r['images_missing_alt'] );
		$this->assertSame( array( array( 'lang' => 'en', 'href' => 'https://example.com/en/' ) ), $r['hreflangs'] );
		$this->assertSame( array( 'https://example.com/about/' ), $r['nofollow_internal'] );
	}

	public function test_challenge_page_detected_from_fixture(): void {
		$html = (string) file_get_contents( __DIR__ . '/../fixtures/sgcaptcha-challenge.html' );
		$r    = SWPS_Site_Crawler::parse_html( $html, self::BASE );
		$this->assertTrue( $r['is_challenge'] );
	}

	public function test_bare_head_without_refresh_is_challenge_too(): void {
		$html = '<html><head></head><body></body></html>';
		$r    = SWPS_Site_Crawler::parse_html( $html, self::BASE );
		$this->assertTrue( $r['is_challenge'] ); // no title+viewport+doctype together
	}

	public function test_paginated_url_flag(): void {
		$r = SWPS_Site_Crawler::parse_html( $this->full_page(), 'https://example.com/blog/page/2/' );
		$this->assertTrue( $r['is_paginated'] );
	}
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/unit/SiteCrawlerExtractionTest.php`
Expected: FAIL — undefined array keys.

- [ ] **Step 4: Extend `parse_html()`**

In `includes/class-site-crawler.php`, extend the `$result` initializer and add extraction. Add to the initializer:

```php
'title'              => '',
'meta_desc'          => '',
'has_viewport'       => false,
'has_doctype'        => false,
'has_charset'        => false,
'has_lang'           => false,
'word_count'         => 0,
'text_bytes'         => 0,
'script_srcs'        => array(),
'style_hrefs'        => array(),
'images_missing_alt' => array(),
'hreflangs'          => array(),
'has_schema'         => false,
'nofollow_internal'  => array(),
'is_challenge'       => false,
'is_archive'         => false,
'is_paginated'       => false,
```

Raw-string facts (before DOM, since DOMDocument normalizes them):

```php
$result['has_doctype']  = 0 === strncasecmp( ltrim( $html ), '<!doctype', 9 );
$result['is_paginated'] = (bool) preg_match( '#/page/\d+/?$#', (string) parse_url( $base_url, PHP_URL_PATH ) );
```

Inside the existing DOM pass, add:

```php
// <title>.
$titles = $dom->getElementsByTagName( 'title' );
if ( $titles->length > 0 ) {
	$result['title'] = trim( preg_replace( '/\s+/', ' ', $titles->item( 0 )->textContent ) );
}

// <html lang>.
$html_el = $dom->getElementsByTagName( 'html' );
if ( $html_el->length > 0 && '' !== trim( $html_el->item( 0 )->getAttribute( 'lang' ) ) ) {
	$result['has_lang'] = true;
}

// <body class> archive marker.
$body = $dom->getElementsByTagName( 'body' );
if ( $body->length > 0 ) {
	$classes             = preg_split( '/\s+/', strtolower( $body->item( 0 )->getAttribute( 'class' ) ) ) ?: array();
	$result['is_archive'] = in_array( 'archive', $classes, true );
}
```

Extend the existing `<meta>` loop (it currently only reads `name="robots"`) to also read:

```php
$name = strtolower( $meta->getAttribute( 'name' ) );
if ( 'viewport' === $name && '' !== trim( $meta->getAttribute( 'content' ) ) ) {
	$result['has_viewport'] = true;
}
if ( 'description' === $name ) {
	$result['meta_desc'] = trim( $meta->getAttribute( 'content' ) );
}
if ( '' !== $meta->getAttribute( 'charset' ) ) {
	$result['has_charset'] = true;
}
if ( 'content-type' === strtolower( $meta->getAttribute( 'http-equiv' ) )
	&& false !== stripos( $meta->getAttribute( 'content' ), 'charset' ) ) {
	$result['has_charset'] = true;
}
// Challenge signal 1: meta-refresh into a challenge/captcha path.
if ( 'refresh' === strtolower( $meta->getAttribute( 'http-equiv' ) )
	&& preg_match( '#(sgcaptcha|captcha|challenge|cdn-cgi)#i', $meta->getAttribute( 'content' ) ) ) {
	$result['is_challenge'] = true;
}
```

Extend the existing `<a>` loop to record nofollow internal candidates (store the absolute URL; internality is decided by the caller with `is_internal()`):

```php
if ( '' !== $abs && preg_match( '/\bnofollow\b/i', $a->getAttribute( 'rel' ) ) ) {
	$result['nofollow_internal'][] = $abs;
}
```

Extend the existing `<script>` loop: collect `$result['script_srcs'][] = $abs;` for every non-empty src (keep the mixed-content check as-is), and detect schema:

```php
if ( 'application/ld+json' === strtolower( $el->getAttribute( 'type' ) ) ) {
	$result['has_schema'] = true;
}
```

Extend the existing `<link>` loop: when `rel` is `stylesheet` collect `$result['style_hrefs'][] = $abs;`; when `rel` is `alternate` and `hreflang` non-empty collect `$result['hreflangs'][] = array( 'lang' => $el->getAttribute( 'hreflang' ), 'href' => $abs );`.

Extend the existing `<img>` loop: when the element has no `alt` attribute at all (`! $img->hasAttribute( 'alt' )`), collect `$result['images_missing_alt'][] = $abs;`.

After the DOM loops, compute text metrics and the second challenge signal:

```php
$text                 = trim( preg_replace( '/\s+/', ' ', (string) $dom->textContent ) );
$result['text_bytes'] = strlen( $text );
$result['word_count'] = '' === $text ? 0 : count( preg_split( '/\s+/', $text ) );

// Challenge signal 2: bare head — no title, no viewport, no doctype together.
if ( '' === $result['title'] && ! $result['has_viewport'] && ! $result['has_doctype'] ) {
	$result['is_challenge'] = true;
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/SiteCrawlerExtractionTest.php` then the full suite `vendor/bin/phpunit --testsuite unit` (existing `SiteCrawlerTest` must stay green — the new keys are additive).
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/class-site-crawler.php tests/unit/SiteCrawlerExtractionTest.php tests/fixtures/sgcaptcha-challenge.html
git commit -m "Site audit: extract full head/content/asset facts + challenge-page detection in parse_html"
```

---

### Task 3: Check base class + registry + short-circuit runner

**Files:**
- Create: `includes/crawl-checks/class-crawl-check.php`
- Create: `includes/crawl-checks/class-crawl-check-registry.php`
- Create: `tests/unit/CrawlCheckRegistryTest.php`

**Interfaces:**
- Consumes: nothing new (checks added in later tasks self-register via the registry's list).
- Produces:
  - `abstract class SWPS_Crawl_Check` with abstract `id(): string`, `severity(): string`, `title(): string`, `how_to_fix(): string`; virtual `check_page( array $page ): ?array { return null; }` and `check_run( int $run_id ): array { return array(); }`; final helper `protected function issue( string $url, array $detail = array() ): array` returning `array( 'type' => $this->id(), 'url' => $url, 'detail' => $detail, 'severity' => $this->severity() )`.
  - `SWPS_Crawl_Check_Registry::all( ?array $excluded = null ): SWPS_Crawl_Check[]` — `$excluded` null → reads `get_option( 'swps_crawl_excluded_checks', array() )`; class list is a private const of class names, instantiated fresh each call.
  - `SWPS_Crawl_Check_Registry::run_page_checks( array $page, array $checks ): array` — pure; short-circuits: if `status_code >= 400 || status_code === 0` run only checks whose id is `broken_link`/`redirect_loop`; if `is_challenge` run only `challenge_page_detected` (plus fetch-level checks); otherwise run every `check_page`.
  - `SWPS_Crawl_Check_Registry::page_check_ids(): string[]` and `aggregate_check_ids(): string[]` for the dashboard.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/unit/CrawlCheckRegistryTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check-registry.php';

final class RegistryFakeAlpha extends SWPS_Crawl_Check {
	public function id(): string { return 'fake_alpha'; }
	public function severity(): string { return 'error'; }
	public function title(): string { return 'Fake alpha'; }
	public function how_to_fix(): string { return 'n/a'; }
	public function check_page( array $page ): ?array { return $this->issue( $page['url'] ); }
}

final class RegistryFakeChallenge extends SWPS_Crawl_Check {
	public function id(): string { return 'challenge_page_detected'; }
	public function severity(): string { return 'error'; }
	public function title(): string { return 'Challenge page'; }
	public function how_to_fix(): string { return 'n/a'; }
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
		$all      = SWPS_Crawl_Check_Registry::all( array() );
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
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/unit/CrawlCheckRegistryTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement base class and registry**

```php
<?php
// includes/crawl-checks/class-crawl-check.php
/**
 * Base class for crawl-time SEO checks.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SWPS_Crawl_Check {

	abstract public function id(): string;

	/** One of: error | warning | notice. */
	abstract public function severity(): string;

	/** Human title, e.g. "Pages don't have title tags". */
	abstract public function title(): string;

	/** Short remediation guidance shown in the dashboard drill-down. */
	abstract public function how_to_fix(): string;

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 * Return an issue row or null.
	 */
	public function check_page( array $page ): ?array {
		return null;
	}

	/**
	 * Aggregate check over a finished run. Returns issue rows.
	 */
	public function check_run( int $run_id ): array {
		return array();
	}

	final protected function issue( string $url, array $detail = array() ): array {
		return array(
			'type'     => $this->id(),
			'url'      => $url,
			'detail'   => $detail,
			'severity' => $this->severity(),
		);
	}
}
```

```php
<?php
// includes/crawl-checks/class-crawl-check-registry.php
/**
 * Registry + runner for crawl checks.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Crawl_Check_Registry {

	public const OPT_EXCLUDED = 'swps_crawl_excluded_checks';

	/**
	 * Ordered check class names. Populated by later tasks; order matters only
	 * for display. Empty entries are skipped defensively.
	 */
	private const CHECKS = array(
		// Task 4 adds migrated checks, Tasks 5-6 add per-page checks,
		// Task 8 adds aggregate checks.
	);

	/** @return SWPS_Crawl_Check[] */
	public static function all( ?array $excluded = null ): array {
		if ( null === $excluded ) {
			$excluded = (array) get_option( self::OPT_EXCLUDED, array() );
		}
		$out = array();
		foreach ( self::CHECKS as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$check = new $class();
			if ( ! in_array( $check->id(), $excluded, true ) ) {
				$out[] = $check;
			}
		}
		return $out;
	}

	/**
	 * Run per-page checks with short-circuit rules:
	 * - broken responses (>=400 or 0) get no page checks (broken_link is
	 *   emitted by the fetch-level check in the crawler, not here);
	 * - challenge pages get ONLY challenge_page_detected.
	 *
	 * @param array             $page   Merged fact array.
	 * @param SWPS_Crawl_Check[] $checks Checks to run.
	 * @return array[] Issue rows.
	 */
	public static function run_page_checks( array $page, array $checks ): array {
		$status = (int) ( $page['status_code'] ?? 0 );
		if ( $status >= 400 || 0 === $status ) {
			return array();
		}

		if ( ! empty( $page['is_challenge'] ) ) {
			foreach ( $checks as $check ) {
				if ( 'challenge_page_detected' === $check->id() ) {
					$issue = $check->check_page( $page );
					return null !== $issue ? array( $issue ) : array();
				}
			}
			return array();
		}

		$issues = array();
		foreach ( $checks as $check ) {
			$issue = $check->check_page( $page );
			if ( null !== $issue ) {
				$issues[] = $issue;
			}
		}
		return $issues;
	}
}
```

Note: `test_excluded_ids_are_filtered` will be trivially true while `CHECKS` is empty; it becomes meaningful as classes land. If PHPUnit flags the empty-registry case, guard the test with `if ( array() === $all ) { $this->markTestSkipped( 'registry empty until checks land' ); }` — and REMOVE that guard in Task 5 Step 6.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/CrawlCheckRegistryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/crawl-checks/ tests/unit/CrawlCheckRegistryTest.php
git commit -m "Site audit: check base class, registry, short-circuit page-check runner"
```

---

### Task 4: Migrate the existing `classify()` checks into registry classes

**Files:**
- Create: `includes/crawl-checks/class-checks-fetch.php` (broken_link, redirect_loop, redirect_chain — fetch-level, run by the crawler before page checks)
- Create: `includes/crawl-checks/class-checks-legacy-page.php` (canonical_mismatch, missing_h1, duplicate_h1, mixed_content, noindex_in_sitemap)
- Modify: `includes/class-site-crawler.php` (`classify()` delegates; keep signature + behavior identical)
- Modify: `includes/crawl-checks/class-crawl-check-registry.php` (add class names to `CHECKS`)
- Test: existing `tests/unit/SiteCrawlerTest.php` (must pass unchanged — that is the acceptance test)

**Interfaces:**
- Consumes: `SWPS_Crawl_Check`, `SWPS_Crawl_Check_Registry`, facts array from Task 2.
- Produces: check classes `SWPS_Check_Broken_Link`, `SWPS_Check_Redirect_Loop`, `SWPS_Check_Redirect_Chain`, `SWPS_Check_Canonical_Mismatch`, `SWPS_Check_Missing_H1`, `SWPS_Check_Duplicate_H1`, `SWPS_Check_Mixed_Content`, `SWPS_Check_Noindex_In_Sitemap` — issue `type` strings identical to today's (`broken_link`, `redirect_loop`, `redirect_chain`, `canonical_mismatch`, `missing_h1`, `duplicate_h1`, `mixed_content`, `noindex_in_sitemap`) so run diffing and history stay valid. The merged fact array (produced in Task 7) carries fetch keys: `url, status_code, found_on, hops, loop, final_url, home_host` alongside parse facts.

- [ ] **Step 1: Confirm the safety net exists**

Run: `vendor/bin/phpunit tests/unit/SiteCrawlerTest.php`
Expected: PASS — these tests pin `classify()` behavior; they must still pass at the end of this task WITHOUT modification.

- [ ] **Step 2: Implement the fetch-level checks**

`class-checks-fetch.php` — one file, three small classes (fetch-level checks are special: the crawler calls them on every fetch, before short-circuiting):

```php
<?php
// includes/crawl-checks/class-checks-fetch.php
/**
 * Fetch-level checks: broken link, redirect loop, redirect chain.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Check_Redirect_Loop extends SWPS_Crawl_Check {
	public function id(): string { return 'redirect_loop'; }
	public function severity(): string { return 'error'; }
	public function title(): string { return __( 'Redirect loops', 'stratawp-seo' ); }
	public function how_to_fix(): string { return __( 'The URL redirects in a cycle and never resolves. Fix the redirect rules so the chain reaches a 200 page.', 'stratawp-seo' ); }
	public function check_page( array $page ): ?array {
		if ( empty( $page['loop'] ) ) {
			return null;
		}
		return $this->issue( $page['url'], array( 'hops' => $page['hops'] ?? array(), 'found_on' => $page['found_on'] ?? '' ) );
	}
}

class SWPS_Check_Broken_Link extends SWPS_Crawl_Check {
	public function id(): string { return 'broken_link'; }
	public function severity(): string { return 'error'; }
	public function title(): string { return __( 'Broken pages and links', 'stratawp-seo' ); }
	public function how_to_fix(): string { return __( 'The URL returned a 4xx/5xx status or no response. Fix the target page or update/remove links pointing to it.', 'stratawp-seo' ); }
	public function check_page( array $page ): ?array {
		$status = (int) ( $page['status_code'] ?? 0 );
		if ( $status < 400 && 0 !== $status ) {
			return null;
		}
		return $this->issue( $page['url'], array( 'status' => $status, 'found_on' => $page['found_on'] ?? '' ) );
	}
}

class SWPS_Check_Redirect_Chain extends SWPS_Crawl_Check {
	public function id(): string { return 'redirect_chain'; }
	public function severity(): string { return 'warning'; }
	public function title(): string { return __( 'Redirect chains', 'stratawp-seo' ); }
	public function how_to_fix(): string { return __( 'The URL takes 2+ redirect hops. Point the original link (and any redirect rules) directly at the final destination.', 'stratawp-seo' ); }
	public function check_page( array $page ): ?array {
		if ( count( $page['hops'] ?? array() ) < 2 ) {
			return null;
		}
		return $this->issue( $page['url'], array( 'hops' => $page['hops'], 'found_on' => $page['found_on'] ?? '' ) );
	}
}
```

- [ ] **Step 3: Implement the legacy page checks**

`class-checks-legacy-page.php` — port the four remaining branches of `classify()` verbatim into `SWPS_Check_Canonical_Mismatch`, `SWPS_Check_Missing_H1`, `SWPS_Check_Duplicate_H1`, `SWPS_Check_Mixed_Content`, `SWPS_Check_Noindex_In_Sitemap`, reading from the merged fact array. Canonical mismatch needs `home_host`, `final_url` — both present in the merged facts. Mixed content emits ONE issue per page with `detail['assets']` being the full list (change from today's one-issue-per-asset; update the detail shape deliberately — it renders better and keeps issue counts meaning "pages"). Copy each `detail` array's other keys exactly from the current `classify()` code (`canonical`, `found_on`, `h1_count`, `post_id`).

- [ ] **Step 4: Rewrite `classify()` as a delegator**

```php
public static function classify( array $fetch, array $page, string $home_host ): array {
	$facts = array_merge(
		$page,
		array(
			'url'         => $fetch['url'] ?? '',
			'status_code' => (int) ( $fetch['status'] ?? 0 ),
			'found_on'    => $fetch['found_on'] ?? '',
			'hops'        => $fetch['hops'] ?? array(),
			'loop'        => ! empty( $fetch['loop'] ),
			'final_url'   => (string) ( $fetch['final_url'] ?? ( $fetch['url'] ?? '' ) ),
			'home_host'   => $home_host,
		)
	);

	// Fetch-level checks always run; loop and broken results end evaluation.
	$fetch_checks = array( new SWPS_Check_Redirect_Loop(), new SWPS_Check_Broken_Link(), new SWPS_Check_Redirect_Chain() );
	$issues       = array();
	foreach ( $fetch_checks as $check ) {
		$issue = $check->check_page( $facts );
		if ( null !== $issue ) {
			$issues[] = $issue;
			if ( in_array( $check->id(), array( 'redirect_loop', 'broken_link' ), true ) ) {
				return $issues;
			}
		}
	}

	$page_checks = array_filter(
		SWPS_Crawl_Check_Registry::all( array() ),
		static fn( SWPS_Crawl_Check $c ) => ! in_array( $c->id(), array( 'redirect_loop', 'broken_link', 'redirect_chain' ), true )
	);

	return array_merge( $issues, SWPS_Crawl_Check_Registry::run_page_checks( $facts, array_values( $page_checks ) ) );
}
```

Register all 8 classes in `SWPS_Crawl_Check_Registry::CHECKS` (fetch checks included — the registry is the single catalog; `classify()`/crawler decide when each kind runs). Add `require_once` lines for the `crawl-checks/` files wherever the plugin loads `class-site-crawler.php` (follow the existing require pattern in `stratawp-seo.php`).

**Watch out:** `SiteCrawlerTest` pins mixed_content's current one-issue-per-asset shape. Update those specific assertions to the new one-issue-per-page shape (`detail['assets']` array) — this is the ONE permitted test change, and the commit message must call it out.

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit --testsuite unit && composer analyze`
Expected: PASS / clean. If a `SiteCrawlerTest` case other than mixed-content fails, the port is wrong — fix the check class, not the test.

- [ ] **Step 6: Commit**

```bash
git add includes/crawl-checks/ includes/class-site-crawler.php stratawp-seo.php tests/unit/SiteCrawlerTest.php
git commit -m "Site audit: migrate classify() checks into registry classes (mixed_content now one issue per page)"
```

---

### Task 5: Head checks (title, viewport, doctype, charset, lang) + challenge check

**Files:**
- Create: `includes/crawl-checks/class-checks-head.php`
- Create: `tests/unit/CrawlChecksHeadTest.php`
- Modify: `includes/crawl-checks/class-crawl-check-registry.php` (register classes)

**Interfaces:**
- Consumes: fact keys from Task 2 (`title`, `has_viewport`, `has_doctype`, `has_charset`, `has_lang`, `is_challenge`).
- Produces: `SWPS_Check_Missing_Title` (id `missing_title`, error — fires on absent OR empty/whitespace title), `SWPS_Check_Title_Too_Long` (`title_too_long`, notice, >60 chars), `SWPS_Check_Title_Too_Short` (`title_too_short`, notice, <15 chars but non-empty), `SWPS_Check_Missing_Viewport` (`missing_viewport`, error), `SWPS_Check_Missing_Doctype` (`missing_doctype`, warning), `SWPS_Check_Missing_Charset` (`missing_charset`, warning), `SWPS_Check_Missing_Lang` (`missing_lang`, warning), `SWPS_Check_Challenge_Page` (`challenge_page_detected`, error).

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/unit/CrawlChecksHeadTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-checks-head.php';

final class CrawlChecksHeadTest extends TestCase {

	private function facts( array $over = array() ): array {
		return array_merge(
			array(
				'url'          => 'https://x.test/p/',
				'status_code'  => 200,
				'title'        => 'A perfectly reasonable page title here',
				'has_viewport' => true,
				'has_doctype'  => true,
				'has_charset'  => true,
				'has_lang'     => true,
				'is_challenge' => false,
			),
			$over
		);
	}

	public function test_healthy_page_raises_nothing(): void {
		$checks = array(
			new SWPS_Check_Missing_Title(), new SWPS_Check_Title_Too_Long(), new SWPS_Check_Title_Too_Short(),
			new SWPS_Check_Missing_Viewport(), new SWPS_Check_Missing_Doctype(),
			new SWPS_Check_Missing_Charset(), new SWPS_Check_Missing_Lang(), new SWPS_Check_Challenge_Page(),
		);
		foreach ( $checks as $check ) {
			$this->assertNull( $check->check_page( $this->facts() ), $check->id() );
		}
	}

	public function test_missing_and_empty_title(): void {
		$check = new SWPS_Check_Missing_Title();
		$this->assertSame( 'missing_title', $check->check_page( $this->facts( array( 'title' => '' ) ) )['type'] );
		$this->assertNotNull( $check->check_page( $this->facts( array( 'title' => '   ' ) ) ) );
	}

	public function test_title_length_boundaries(): void {
		$long  = new SWPS_Check_Title_Too_Long();
		$short = new SWPS_Check_Title_Too_Short();
		$this->assertNull( $long->check_page( $this->facts( array( 'title' => str_repeat( 'a', 60 ) ) ) ) );
		$this->assertNotNull( $long->check_page( $this->facts( array( 'title' => str_repeat( 'a', 61 ) ) ) ) );
		$this->assertNull( $short->check_page( $this->facts( array( 'title' => str_repeat( 'a', 15 ) ) ) ) );
		$this->assertNotNull( $short->check_page( $this->facts( array( 'title' => str_repeat( 'a', 14 ) ) ) ) );
		$this->assertNull( $short->check_page( $this->facts( array( 'title' => '' ) ) ) ); // missing_title's job
	}

	public function test_flag_checks_fire_when_flag_absent(): void {
		$this->assertSame( 'missing_viewport', ( new SWPS_Check_Missing_Viewport() )->check_page( $this->facts( array( 'has_viewport' => false ) ) )['type'] );
		$this->assertSame( 'missing_doctype', ( new SWPS_Check_Missing_Doctype() )->check_page( $this->facts( array( 'has_doctype' => false ) ) )['type'] );
		$this->assertSame( 'missing_charset', ( new SWPS_Check_Missing_Charset() )->check_page( $this->facts( array( 'has_charset' => false ) ) )['type'] );
		$this->assertSame( 'missing_lang', ( new SWPS_Check_Missing_Lang() )->check_page( $this->facts( array( 'has_lang' => false ) ) )['type'] );
	}

	public function test_challenge_check(): void {
		$check = new SWPS_Check_Challenge_Page();
		$issue = $check->check_page( $this->facts( array( 'is_challenge' => true ) ) );
		$this->assertSame( 'challenge_page_detected', $issue['type'] );
		$this->assertSame( 'error', $issue['severity'] );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/unit/CrawlChecksHeadTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the eight classes**

`class-checks-head.php`, all in one file, each following this exact shape (shown for two; repeat the pattern for the rest with the ids/severities/conditions from the Interfaces block):

```php
class SWPS_Check_Missing_Title extends SWPS_Crawl_Check {
	public function id(): string { return 'missing_title'; }
	public function severity(): string { return 'error'; }
	public function title(): string { return __( "Pages don't have title tags", 'stratawp-seo' ); }
	public function how_to_fix(): string { return __( 'Every page needs a unique, descriptive <title>. Check the Search Appearance title templates for this page type — an empty template variable renders as no title at all.', 'stratawp-seo' ); }
	public function check_page( array $page ): ?array {
		return '' === trim( (string) ( $page['title'] ?? '' ) ) ? $this->issue( $page['url'] ) : null;
	}
}

class SWPS_Check_Challenge_Page extends SWPS_Crawl_Check {
	public function id(): string { return 'challenge_page_detected'; }
	public function severity(): string { return 'error'; }
	public function title(): string { return __( 'Bot-challenge pages served to the crawler', 'stratawp-seo' ); }
	public function how_to_fix(): string { return __( 'The server answered with an anti-bot challenge (CAPTCHA) instead of the page. Your host is likely challenging crawler user agents — whitelist legitimate crawlers with your host, or these URLs will look broken to every audit tool and some search bots.', 'stratawp-seo' ); }
	public function check_page( array $page ): ?array {
		return ! empty( $page['is_challenge'] ) ? $this->issue( $page['url'] ) : null;
	}
}
```

Title-length details: include `detail['length']` and `detail['title']` in the too-long/too-short issues. Register all eight in `SWPS_Crawl_Check_Registry::CHECKS`, remove the skip-guard from Task 3's registry test if it was added, and add `require_once` for the new file next to the Task 4 requires.

- [ ] **Step 4: Run full suite to verify pass**

Run: `vendor/bin/phpunit --testsuite unit && composer analyze`
Expected: PASS / clean.

- [ ] **Step 5: Commit**

```bash
git add includes/crawl-checks/ tests/unit/CrawlChecksHeadTest.php tests/unit/CrawlCheckRegistryTest.php stratawp-seo.php
git commit -m "Site audit: head-integrity checks (title, viewport, doctype, charset, lang) + challenge check"
```

---

### Task 6: Content & asset checks

**Files:**
- Create: `includes/crawl-checks/class-checks-content.php`
- Create: `includes/crawl-checks/class-minify-heuristic.php`
- Create: `tests/unit/CrawlChecksContentTest.php`, `tests/unit/MinifyHeuristicTest.php`
- Modify: `includes/crawl-checks/class-crawl-check-registry.php` (register)

**Interfaces:**
- Consumes: fact keys `meta_desc, is_paginated, is_archive, has_noindex, word_count, text_bytes, html_bytes, images_missing_alt, hreflangs, has_schema, nofollow_internal, is_compressed, unminified_assets, home_host` (`html_bytes`, `is_compressed`, `unminified_assets` are attached by the crawler in Task 7; tests pass them directly).
- Produces:
  - `SWPS_Minify_Heuristic::looks_minified( string $sample ): bool` — pure; a sample looks minified when average line length > 200 chars over the first 20 KB (empty sample → true).
  - Checks: `SWPS_Check_Missing_Meta_Description` (`missing_meta_description`, warning; SKIPS when `is_paginated` or `has_noindex`), `SWPS_Check_Desc_Too_Long` (`desc_too_long`, notice, >160), `SWPS_Check_Low_Word_Count` (`low_word_count`, warning, <200 and NOT `is_archive`), `SWPS_Check_Low_Text_Html_Ratio` (`low_text_html_ratio`, notice, `text_bytes/html_bytes < 0.10`, only when `html_bytes > 0`), `SWPS_Check_Image_Missing_Alt` (`image_missing_alt`, warning, one issue per page, `detail['images']`), `SWPS_Check_Hreflang_Invalid` (`hreflang_invalid`, warning — fires when hreflangs exist AND any entry has empty lang, or lang fails `/^[a-z]{2}(-[A-Za-z]{2})?$|^x-default$/`, or the same lang maps to two different hrefs), `SWPS_Check_Missing_Schema` (`missing_schema`, notice, only when NOT `is_archive` and NOT `has_noindex`), `SWPS_Check_Nofollow_Internal` (`nofollow_internal_link`, notice — fires when any `nofollow_internal` URL host matches `home_host`, `detail['links']`), `SWPS_Check_Uncompressed_Page` (`uncompressed_page`, warning, fires when `is_compressed` is false), `SWPS_Check_Unminified_Assets` (`unminified_assets`, warning, fires when `unminified_assets` non-empty, `detail['assets']`).

- [ ] **Step 1: Write the failing minify-heuristic test**

```php
<?php
// tests/unit/MinifyHeuristicTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-minify-heuristic.php';

final class MinifyHeuristicTest extends TestCase {
	public function test_minified_single_line_sample(): void {
		$this->assertTrue( SWPS_Minify_Heuristic::looks_minified( str_repeat( 'var a=1;', 500 ) ) );
	}
	public function test_readable_source_sample(): void {
		$src = str_repeat( "function doThing(input) {\n    return input + 1; // add one\n}\n\n", 100 );
		$this->assertFalse( SWPS_Minify_Heuristic::looks_minified( $src ) );
	}
	public function test_empty_sample_counts_as_minified(): void {
		$this->assertTrue( SWPS_Minify_Heuristic::looks_minified( '' ) );
	}
}
```

- [ ] **Step 2: Run to verify fail, then implement**

Run: `vendor/bin/phpunit tests/unit/MinifyHeuristicTest.php` → FAIL, then:

```php
<?php
// includes/crawl-checks/class-minify-heuristic.php
/**
 * Heuristic: does a JS/CSS sample look minified?
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Minify_Heuristic {

	private const SAMPLE_BYTES  = 20480;
	private const AVG_LINE_MIN  = 200;

	public static function looks_minified( string $sample ): bool {
		$sample = substr( $sample, 0, self::SAMPLE_BYTES );
		$len    = strlen( $sample );
		if ( 0 === $len ) {
			return true;
		}
		$lines = substr_count( $sample, "\n" ) + 1;
		return ( $len / $lines ) > self::AVG_LINE_MIN;
	}
}
```

Run again → PASS.

- [ ] **Step 3: Write the failing content-check tests**

```php
<?php
// tests/unit/CrawlChecksContentTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-checks-content.php';

final class CrawlChecksContentTest extends TestCase {

	private function facts( array $over = array() ): array {
		return array_merge(
			array(
				'url'                => 'https://x.test/post/',
				'status_code'        => 200,
				'meta_desc'          => 'A description of sensible length for the test.',
				'is_paginated'       => false,
				'is_archive'         => false,
				'has_noindex'        => false,
				'word_count'         => 900,
				'text_bytes'         => 5000,
				'html_bytes'         => 20000,
				'images_missing_alt' => array(),
				'hreflangs'          => array(),
				'has_schema'         => true,
				'nofollow_internal'  => array(),
				'is_compressed'      => true,
				'unminified_assets'  => array(),
				'home_host'          => 'x.test',
			),
			$over
		);
	}

	public function test_missing_description_fires_but_not_on_paginated_or_noindex(): void {
		$check = new SWPS_Check_Missing_Meta_Description();
		$this->assertNotNull( $check->check_page( $this->facts( array( 'meta_desc' => '' ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'meta_desc' => '', 'is_paginated' => true ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'meta_desc' => '', 'has_noindex' => true ) ) ) );
	}

	public function test_word_count_exempts_archives(): void {
		$check = new SWPS_Check_Low_Word_Count();
		$this->assertNotNull( $check->check_page( $this->facts( array( 'word_count' => 50 ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'word_count' => 50, 'is_archive' => true ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'word_count' => 200 ) ) ) );
	}

	public function test_text_html_ratio(): void {
		$check = new SWPS_Check_Low_Text_Html_Ratio();
		$this->assertNotNull( $check->check_page( $this->facts( array( 'text_bytes' => 500, 'html_bytes' => 20000 ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'text_bytes' => 5000, 'html_bytes' => 20000 ) ) ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'html_bytes' => 0 ) ) ) );
	}

	public function test_hreflang_invalid_cases(): void {
		$check = new SWPS_Check_Hreflang_Invalid();
		$this->assertNull( $check->check_page( $this->facts() ) ); // absent = fine
		$ok = array( array( 'lang' => 'en-US', 'href' => 'https://x.test/en/' ) );
		$this->assertNull( $check->check_page( $this->facts( array( 'hreflangs' => $ok ) ) ) );
		$bad = array( array( 'lang' => 'english', 'href' => 'https://x.test/en/' ) );
		$this->assertNotNull( $check->check_page( $this->facts( array( 'hreflangs' => $bad ) ) ) );
		$conflict = array(
			array( 'lang' => 'en', 'href' => 'https://x.test/en/' ),
			array( 'lang' => 'en', 'href' => 'https://x.test/other/' ),
		);
		$this->assertNotNull( $check->check_page( $this->facts( array( 'hreflangs' => $conflict ) ) ) );
	}

	public function test_nofollow_internal_only_counts_same_host(): void {
		$check = new SWPS_Check_Nofollow_Internal();
		$this->assertNull( $check->check_page( $this->facts( array( 'nofollow_internal' => array( 'https://elsewhere.test/x' ) ) ) ) );
		$issue = $check->check_page( $this->facts( array( 'nofollow_internal' => array( 'https://x.test/about/' ) ) ) );
		$this->assertSame( array( 'https://x.test/about/' ), $issue['detail']['links'] );
	}

	public function test_remaining_simple_checks(): void {
		$this->assertNotNull( ( new SWPS_Check_Desc_Too_Long() )->check_page( $this->facts( array( 'meta_desc' => str_repeat( 'a', 161 ) ) ) ) );
		$this->assertNotNull( ( new SWPS_Check_Image_Missing_Alt() )->check_page( $this->facts( array( 'images_missing_alt' => array( 'https://x.test/i.png' ) ) ) ) );
		$this->assertNotNull( ( new SWPS_Check_Missing_Schema() )->check_page( $this->facts( array( 'has_schema' => false ) ) ) );
		$this->assertNull( ( new SWPS_Check_Missing_Schema() )->check_page( $this->facts( array( 'has_schema' => false, 'is_archive' => true ) ) ) );
		$this->assertNotNull( ( new SWPS_Check_Uncompressed_Page() )->check_page( $this->facts( array( 'is_compressed' => false ) ) ) );
		$this->assertNotNull( ( new SWPS_Check_Unminified_Assets() )->check_page( $this->facts( array( 'unminified_assets' => array( 'https://x.test/js/a.js' ) ) ) ) );
	}
}
```

- [ ] **Step 4: Run to verify fail, implement the ten classes, run to pass**

Each class follows the Task 5 pattern; conditions and detail keys exactly as the Interfaces block specifies. `how_to_fix()` texts (write them all; three examples to set the register):
- missing_meta_description: "Add a meta description via the Meta Editor or Search Appearance description templates. Paginated archive pages intentionally have none and are not flagged."
- unminified_assets: "Ship minified builds of first-party JS/CSS and enqueue them with the SCRIPT_DEBUG suffix convention."
- uncompressed_page: "Enable gzip or brotli compression for HTML responses at the server or host level."

Run: `vendor/bin/phpunit --testsuite unit && composer analyze` → PASS/clean. Register the ten classes in `CHECKS`; add the `require_once` lines.

- [ ] **Step 5: Commit**

```bash
git add includes/crawl-checks/ tests/unit/CrawlChecksContentTest.php tests/unit/MinifyHeuristicTest.php stratawp-seo.php
git commit -m "Site audit: content and asset checks + minify heuristic"
```

---

### Task 7: Crawler wiring — pages rows, asset sampling, compression, UA, seeding

**Files:**
- Modify: `includes/class-site-crawler.php` (`process_chunk()` ~line 589, `fetch_url()` ~line 775, `get_seed_urls()` ~line 995)
- Test: `tests/unit/SiteCrawlerExtractionTest.php` (extend), WP-CLI smoke (step 6)

**Interfaces:**
- Consumes: everything from Tasks 1-6.
- Produces:
  - `fetch_url()` adds `Accept-Encoding: gzip, deflate` to request args (`wp_remote_get` decompresses transparently), records `is_compressed` from the response `content-encoding` header, records `content_type`, and sends UA `apply_filters( 'swps_crawl_user_agent', 'StrataWP-SEO-Audit/' . SWPS_VERSION . '; +' . home_url( '/' ) )`.
  - `process_chunk()` per fetched HTML page: build merged facts (as in Task 4's `classify()`), sample first-party assets for minification (fetch up to 5 not-yet-seen `script_srcs`/`style_hrefs` whose host equals `home_host`, 20 KB range each, cache verdict per URL in run state under `asset_verdicts` so each asset is sampled once per run), attach `unminified_assets`, `html_bytes` (strlen of body), `is_compressed`; write `SWPS_Crawl_Issues::insert_page()` row (title_hash = `md5( strtolower( trim( title ) ) )`, meta_desc_hash = `'' === meta_desc ? '' : md5( strtolower( trim( meta_desc ) ) )`, flags via `SWPS_Crawl_Page_Flags::pack()`, `internal_links` = the parse `links` filtered by `is_internal()` and normalized); then store issues from `classify()` exactly as today.
  - `get_seed_urls()` seeds: ALL sitemap URLs (not just posts — reuse the existing sitemap source but drop the post-type filter), every public taxonomy term URL (`get_terms( array( 'taxonomy' => get_taxonomies( array( 'public' => true ) ), 'hide_empty' => true ) )` → `get_term_link`), author archive URLs for users with published posts (`get_users( array( 'has_published_posts' => true ) )` → `get_author_posts_url`), the posts-page/blog index URL. Pagination arrives via link-following (no guessed URLs).

- [ ] **Step 1: Extend extraction test for the internal-links filter helper**

Add to `SiteCrawlerExtractionTest`:

```php
public function test_internal_links_filtered_and_normalized(): void {
	$links = array( 'https://example.com/a/', 'https://other.test/b/', 'https://example.com/a/?utm=1#frag' );
	$out   = SWPS_Site_Crawler::internal_links_normalized( $links, 'example.com' );
	$this->assertSame( array( 'https://example.com/a/' ), array_values( array_unique( $out ) ) );
}
```

New pure helper on the crawler:

```php
/** Filter to internal links, normalized and deduplicated. */
public static function internal_links_normalized( array $links, string $home_host ): array {
	$out = array();
	foreach ( $links as $link ) {
		if ( self::is_internal( $link, $home_host ) ) {
			$norm = self::normalize_url( $link, $home_host );
			if ( '' !== $norm ) {
				$out[ $norm ] = true;
			}
		}
	}
	return array_keys( $out );
}
```

Run the test file: FAIL → implement → PASS. (Exact normalization output depends on `normalize_url()`'s existing behavior — adjust the expected value in the test to match what `normalize_url` actually produces for `https://example.com/a/`; read its code first.)

- [ ] **Step 2: Wire `fetch_url()`**

Locate the `wp_remote_get` args in `fetch_url()`; add/merge:

```php
'user-agent' => apply_filters( 'swps_crawl_user_agent', 'StrataWP-SEO-Audit/' . SWPS_VERSION . '; +' . home_url( '/' ) ),
'headers'    => array( 'Accept-Encoding' => 'gzip, deflate' ),
```

Extend the returned fetch array with:

```php
'content_type'  => (string) wp_remote_retrieve_header( $response, 'content-type' ),
'is_compressed' => '' !== (string) wp_remote_retrieve_header( $response, 'content-encoding' ),
```

- [ ] **Step 3: Wire `process_chunk()`**

At the point where the current code calls `parse_html` + `classify` (~line 669-732), insert the pages-row write and asset sampling. Asset sampler (private method on the crawler):

```php
/**
 * Sample first-party assets for minification; verdicts cached per run.
 *
 * @param string[] $urls      Absolute asset URLs (scripts + styles).
 * @param string   $home_host Home host.
 * @param array    $state     Run state (asset_verdicts cache lives here).
 * @return string[] Asset URLs that look unminified.
 */
private function sample_unminified_assets( array $urls, string $home_host, array &$state ): array {
	$bad = array();
	foreach ( array_slice( array_unique( $urls ), 0, 20 ) as $url ) {
		if ( ! self::is_internal( $url, $home_host ) ) {
			continue;
		}
		$key = md5( strtok( $url, '?' ) );
		if ( ! isset( $state['asset_verdicts'][ $key ] ) ) {
			$resp = wp_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Range' => 'bytes=0-20479' ) ) );
			$body = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
			// Empty/failed fetch counts as minified (no false positives).
			$state['asset_verdicts'][ $key ] = SWPS_Minify_Heuristic::looks_minified( $body );
		}
		if ( false === $state['asset_verdicts'][ $key ] ) {
			$bad[] = strtok( $url, '?' );
		}
	}
	return $bad;
}
```

Then in the per-URL flow (HTML 2xx responses only), after `parse_html`:

```php
$assets = array_merge( $page['script_srcs'] ?? array(), $page['style_hrefs'] ?? array() );
$page['unminified_assets'] = $this->sample_unminified_assets( $assets, $home_host, $state );
$page['html_bytes']        = strlen( $body );
$page['is_compressed']     = ! empty( $fetch['is_compressed'] );

SWPS_Crawl_Issues::insert_page(
	$run_id,
	array(
		'url'               => $url,
		'status_code'       => (int) $fetch['status'],
		'content_type'      => (string) ( $fetch['content_type'] ?? '' ),
		'title'             => $page['title'],
		'title_hash'        => md5( strtolower( trim( $page['title'] ) ) ),
		'meta_desc_hash'    => '' === trim( $page['meta_desc'] ) ? '' : md5( strtolower( trim( $page['meta_desc'] ) ) ),
		'desc_length'       => strlen( $page['meta_desc'] ),
		'flags'             => SWPS_Crawl_Page_Flags::pack( $page ),
		'word_count'        => (int) $page['word_count'],
		'html_bytes'        => (int) $page['html_bytes'],
		'text_bytes'        => (int) $page['text_bytes'],
		'h1_count'          => (int) $page['h1_count'],
		'canonical'         => $page['canonical'],
		'internal_links'    => self::internal_links_normalized( $page['links'], $home_host ),
		'unminified_assets' => $page['unminified_assets'],
	)
);
```

For broken responses (>=400/0), still write a minimal row (`url`, `status_code` only) so the dashboard can count broken pages. The existing `classify()` call site keeps working because Task 4 already merged the new facts inside `classify()` — pass the enriched `$page` in.

- [ ] **Step 4: Expand `get_seed_urls()`**

Add term/author/blog seeds per the Interfaces block, appended to the existing sitemap seeds, deduplicated, still capped by `swps_crawl_internal_cap` (raise the option default to 500 where it is read).

- [ ] **Step 5: Full suite + PHPStan**

Run: `vendor/bin/phpunit --testsuite unit && composer analyze` → PASS/clean.

- [ ] **Step 6: WP-CLI smoke test on the local site**

```bash
LOCAL_WP="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp"
SITE="/Users/jon.imms/Local Sites/jonimms/app/public"
"$LOCAL_WP" --path="$SITE" eval '
$c = new SWPS_Site_Crawler();
$run = $c->start_run( array( "internal_cap" => 30 ) );
for ( $i = 0; $i < 20; $i++ ) { $r = $c->process_chunk( 5 ); if ( ! empty( $r["done"] ) ) break; }
$pages = SWPS_Crawl_Issues::pages_for_run( $run );
echo "pages: " . count( $pages ) . "\n";
echo "first: " . wp_json_encode( array_intersect_key( $pages[0] ?? array(), array_flip( array( "url", "title", "flags", "word_count" ) ) ) ) . "\n";
'
```

Expected: pages > 0, first row shows a real title and non-zero facts. (Requires the Local site running; if `process_chunk`'s return shape differs from `done`, read its return and adapt the loop condition.)

- [ ] **Step 7: Commit**

```bash
git add includes/class-site-crawler.php tests/unit/SiteCrawlerExtractionTest.php
git commit -m "Site audit: crawler writes page facts, samples assets, adds UA + compression, seeds all public URLs"
```

---

### Task 8: Aggregate checks, health score, run summary

**Files:**
- Create: `includes/crawl-checks/class-checks-aggregate.php`
- Create: `includes/crawl-checks/class-crawl-score.php`
- Create: `tests/unit/CrawlChecksAggregateTest.php`, `tests/unit/CrawlScoreTest.php`
- Modify: `includes/class-site-crawler.php` (`finish_run()` ~line 956), `includes/crawl-checks/class-crawl-check-registry.php` (register)

**Interfaces:**
- Consumes: `SWPS_Crawl_Issues::pages_for_run()`, `severity_counts()`, `page_counts()` (Task 1), flags helper.
- Produces:
  - Pure cores (static, array-in/array-out, unit-tested):
    - `SWPS_Check_Duplicate_Title::find_duplicates( array $rows ): array` — groups rows by non-empty `title_hash`, skips rows with status >= 300 or challenge flag; returns `array<title_hash, string[] urls>` for groups of 2+.
    - `SWPS_Check_Duplicate_Description::find_duplicates( array $rows ): array` — same on `meta_desc_hash`.
    - `SWPS_Crawl_Link_Graph::incoming_counts( array $rows ): array<url,int>` — walks every row's `internal_links` list, counts references to each URL (self-links ignored).
  - Check classes (id/severity): `duplicate_title`/error, `duplicate_meta_description`/warning, `orphan_page`/notice (in pages table, 200-status, zero incoming, not the home URL), `single_incoming_link`/notice. Each `check_run()` fetches rows via `SWPS_Crawl_Issues::pages_for_run()` and delegates to its pure core; duplicate issues are emitted one per URL with `detail['duplicate_of']` = the other URLs in its group.
  - `SWPS_Crawl_Score::calculate( array $severity_counts, int $pages ): int` — `(int) round( max( 0, 100 * ( 1 - ( 5*errors + 1*warnings + 0*notices ) / max(1, 5*pages) ) ) )` (denominator = worst case of one error per page, keeping the scale comparable to Semrush's).
  - `finish_run()` additions: run every registry check's `check_run()` inside try/catch (`catch ( \Throwable $e )` → `error_log( 'SWPS crawl aggregate check failed: ' . $e->getMessage() )`, continue), insert returned issues, then compute + store run summary into the run state array: `array( 'score' => ..., 'severity' => severity_counts, 'pages' => page_counts, 'issue_counts' => issue_counts )`.

- [ ] **Step 1: Write the failing score test**

```php
<?php
// tests/unit/CrawlScoreTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-score.php';

final class CrawlScoreTest extends TestCase {
	public function test_no_issues_is_100(): void {
		$this->assertSame( 100, SWPS_Crawl_Score::calculate( array( 'error' => 0, 'warning' => 0, 'notice' => 0 ), 50 ) );
	}
	public function test_notices_are_free(): void {
		$this->assertSame( 100, SWPS_Crawl_Score::calculate( array( 'error' => 0, 'warning' => 0, 'notice' => 40 ), 50 ) );
	}
	public function test_errors_weigh_five_warnings_one(): void {
		// 10 errors on 50 pages: 1 - 50/250 = 0.8.
		$this->assertSame( 80, SWPS_Crawl_Score::calculate( array( 'error' => 10, 'warning' => 0, 'notice' => 0 ), 50 ) );
		// 50 warnings on 50 pages: 1 - 50/250 = 0.8.
		$this->assertSame( 80, SWPS_Crawl_Score::calculate( array( 'error' => 0, 'warning' => 50, 'notice' => 0 ), 50 ) );
	}
	public function test_clamps_at_zero_and_handles_zero_pages(): void {
		$this->assertSame( 0, SWPS_Crawl_Score::calculate( array( 'error' => 999, 'warning' => 0, 'notice' => 0 ), 10 ) );
		$this->assertSame( 100, SWPS_Crawl_Score::calculate( array( 'error' => 0, 'warning' => 0, 'notice' => 0 ), 0 ) );
	}
}
```

- [ ] **Step 2: Run → FAIL → implement `SWPS_Crawl_Score` → PASS**

```php
<?php
// includes/crawl-checks/class-crawl-score.php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Crawl_Score {
	public static function calculate( array $severity_counts, int $pages ): int {
		$weighted = 5 * (int) ( $severity_counts['error'] ?? 0 ) + (int) ( $severity_counts['warning'] ?? 0 );
		$max      = 5 * max( 1, $pages );
		return (int) round( max( 0.0, 100 * ( 1 - $weighted / $max ) ) );
	}
}
```

- [ ] **Step 3: Write the failing aggregate tests**

```php
<?php
// tests/unit/CrawlChecksAggregateTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-page-flags.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/../../includes/crawl-checks/class-checks-aggregate.php';

final class CrawlChecksAggregateTest extends TestCase {

	private function row( string $url, array $over = array() ): array {
		return array_merge(
			array(
				'url'            => $url,
				'status_code'    => 200,
				'title_hash'     => md5( $url ),
				'meta_desc_hash' => md5( 'd' . $url ),
				'flags'          => 0,
				'internal_links' => array(),
			),
			$over
		);
	}

	public function test_duplicate_titles_grouped(): void {
		$rows = array(
			$this->row( 'https://x.test/a/', array( 'title_hash' => 'same' ) ),
			$this->row( 'https://x.test/b/', array( 'title_hash' => 'same' ) ),
			$this->row( 'https://x.test/c/' ),
		);
		$groups = SWPS_Check_Duplicate_Title::find_duplicates( $rows );
		$this->assertSame( array( 'same' => array( 'https://x.test/a/', 'https://x.test/b/' ) ), $groups );
	}

	public function test_duplicates_skip_challenge_and_redirect_rows(): void {
		$rows = array(
			$this->row( 'https://x.test/a/', array( 'title_hash' => 'same' ) ),
			$this->row( 'https://x.test/b/', array( 'title_hash' => 'same', 'flags' => SWPS_Crawl_Page_Flags::IS_CHALLENGE ) ),
			$this->row( 'https://x.test/c/', array( 'title_hash' => 'same', 'status_code' => 301 ) ),
		);
		$this->assertSame( array(), SWPS_Check_Duplicate_Title::find_duplicates( $rows ) );
	}

	public function test_empty_hash_never_groups(): void {
		$rows = array(
			$this->row( 'https://x.test/a/', array( 'meta_desc_hash' => '' ) ),
			$this->row( 'https://x.test/b/', array( 'meta_desc_hash' => '' ) ),
		);
		$this->assertSame( array(), SWPS_Check_Duplicate_Description::find_duplicates( $rows ) );
	}

	public function test_incoming_counts_and_orphans(): void {
		$rows = array(
			$this->row( 'https://x.test/', array( 'internal_links' => array( 'https://x.test/a/', 'https://x.test/b/' ) ) ),
			$this->row( 'https://x.test/a/', array( 'internal_links' => array( 'https://x.test/b/' ) ) ),
			$this->row( 'https://x.test/b/' ),
			$this->row( 'https://x.test/orphan/' ),
		);
		$counts = SWPS_Crawl_Link_Graph::incoming_counts( $rows );
		$this->assertSame( 2, $counts['https://x.test/b/'] );
		$this->assertSame( 1, $counts['https://x.test/a/'] );
		$this->assertArrayNotHasKey( 'https://x.test/orphan/', $counts );
	}

	public function test_self_links_ignored(): void {
		$rows   = array( $this->row( 'https://x.test/a/', array( 'internal_links' => array( 'https://x.test/a/' ) ) ) );
		$this->assertSame( array(), SWPS_Crawl_Link_Graph::incoming_counts( $rows ) );
	}
}
```

- [ ] **Step 4: Run → FAIL → implement `class-checks-aggregate.php` → PASS**

Pure cores as specified; the four check classes' `check_run()` methods fetch `SWPS_Crawl_Issues::pages_for_run( $run_id )` and map groups/counts to issue rows (`duplicate_of` detail for duplicates; orphan check compares 200-status, non-challenge page URLs against the counts map and the home URL). Register the four classes in `CHECKS`.

- [ ] **Step 5: Wire `finish_run()`**

In `finish_run()`, before the existing completion bookkeeping:

```php
foreach ( SWPS_Crawl_Check_Registry::all() as $check ) {
	try {
		foreach ( $check->check_run( $state['run_id'] ) as $issue ) {
			SWPS_Crawl_Issues::insert_issue( $state['run_id'], $issue['type'], $issue['url'], $issue['detail'], $issue['severity'] );
		}
	} catch ( \Throwable $e ) {
		error_log( 'SWPS crawl aggregate check ' . $check->id() . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

$severity          = SWPS_Crawl_Issues::severity_counts( $state['run_id'] );
$pages             = SWPS_Crawl_Issues::page_counts( $state['run_id'] );
$state['summary']  = array(
	'score'        => SWPS_Crawl_Score::calculate( $severity, $pages['total'] ),
	'severity'     => $severity,
	'pages'        => $pages,
	'issue_counts' => SWPS_Crawl_Issues::issue_counts( $state['run_id'] ),
);
```

(Match `insert_issue()`'s real signature — read it at `class-crawl-issues.php:115` and adapt the call; if it takes an issue array rather than positional args, pass the array.) `severity_counts()` must count `notice` — read it and extend if it only knows error/warning.

- [ ] **Step 6: Full suite + PHPStan + commit**

Run: `vendor/bin/phpunit --testsuite unit && composer analyze` → PASS/clean.

```bash
git add includes/crawl-checks/ includes/class-site-crawler.php tests/unit/CrawlChecksAggregateTest.php tests/unit/CrawlScoreTest.php
git commit -m "Site audit: aggregate duplicate/orphan checks, health score, run summary"
```

---

### Task 9: Site Audit dashboard screen

**Files:**
- Create: `includes/class-site-audit-screen.php`
- Create: `admin/js/site-audit.js` + `admin/js/site-audit.min.js` (terser)
- Create: `admin/css/site-audit.css`
- Modify: `includes/class-admin-shell.php` (nav item + slug allowlist ~line 35)
- Modify: `includes/class-settings.php` (submenu registration, follow the `swps-search-appearance` pattern at ~line 71)
- Modify: `stratawp-seo.php` (instantiate `SWPS_Site_Audit_Screen`, require files)

**Interfaces:**
- Consumes: `SWPS_Crawl_Issues::{issues_for_run, issue_counts, severity_counts, page_counts, progress_snapshot, get_first_seen_run}`, `SWPS_Site_Crawler::{get_state, start_run}`, run summary from Task 8, `SWPS_Crawl_Check_Registry::all()` (titles + how_to_fix + severity per check id).
- Produces: admin page slug `swps-site-audit`; AJAX actions `swps_site_audit_progress` (returns progress snapshot JSON) and `swps_site_audit_start` (starts a run); admin-post action `swps_site_audit_exclude` (toggles a check id in `swps_crawl_excluded_checks`, nonce-checked, redirects back).

- [ ] **Step 1: Build the screen class**

`SWPS_Site_Audit_Screen` — constructor hooks `admin_menu` (submenu page, capability `manage_options`), `admin_enqueue_scripts` (enqueue `site-audit{$suffix}.js` + CSS only on this screen's hook), `wp_ajax_swps_site_audit_progress`, `wp_ajax_swps_site_audit_start`, `admin_post_swps_site_audit_exclude`. Render method structure (server-rendered, all data assembled before output):

```php
public function render(): void {
	$state    = SWPS_Site_Crawler::get_state();
	$run_id   = (int) ( $state['run_id'] ?? 0 );
	$running  = ! empty( $state['run_id'] ) && empty( $state['summary'] ) && empty( $state['finished'] );
	$summary  = $state['summary'] ?? array();
	$excluded = (array) get_option( SWPS_Crawl_Check_Registry::OPT_EXCLUDED, array() );

	// Check catalog keyed by id (title, how_to_fix, severity) — includes
	// excluded checks so their rows can render in the footer strip.
	$catalog = array();
	foreach ( SWPS_Crawl_Check_Registry::all( array() ) as $check ) {
		$catalog[ $check->id() ] = $check;
	}

	$issue_counts = $run_id ? SWPS_Crawl_Issues::issue_counts( $run_id ) : array();
	$issues       = $run_id ? SWPS_Crawl_Issues::issues_for_run( $run_id ) : array();
	$by_type      = array();
	foreach ( $issues as $issue ) {
		$by_type[ $issue['type'] ][] = $issue;
	}
	// Sort check ids: severity rank (error, warning, notice) then count desc.
	// Render: header (score gauge + Run now), triage cards, issue groups, trend strip.
	...
}
```

Header/gauge: score as a conic-gradient CSS circle (no JS charting). Triage deltas: for each severity, current count minus previous run's count (previous run id = second-newest distinct run in the issues table; add `SWPS_Crawl_Issues::latest_run_ids( int $limit = 5 ): array` — a one-line `SELECT DISTINCT run_id ... ORDER BY run_id DESC LIMIT %d` helper — and per-run `severity_counts` calls). Trend strip: same 5 run ids → score requires summaries per run; store a rolling `swps_crawl_run_summaries` option (id → summary) written in `finish_run()` (add that one line to Task 8's wiring: `$summaries = get_option( 'swps_crawl_run_summaries', array() ); $summaries[ $state['run_id'] ] = $state['summary']; update_option( 'swps_crawl_run_summaries', array_slice( $summaries, -5, null, true ), false );`) and render inline-SVG sparklines from it. Issue group row: expander `<details>` element (no JS needed for expand), page list table (URL linked, first-seen date from `get_first_seen_run`, detail rendered per type: assets list, duplicate_of list, title length), capped at 50 rows with a "showing 50 of N" note, "Copy URLs" button (JS: clipboard write of newline-joined URLs), "Exclude this check" form posting to admin-post with nonce. Excluded checks: footer strip listing them with a "Re-enable" form. During a run: progress bar polls `swps_site_audit_progress` every 3 s (JS `setInterval`, stops on `done`).

- [ ] **Step 2: The JS (complete file)**

```js
// admin/js/site-audit.js
( function () {
	'use strict';

	var poll = document.querySelector( '[data-swps-audit-progress]' );
	if ( poll ) {
		var tick = window.setInterval( function () {
			window.fetch( window.ajaxurl + '?action=swps_site_audit_progress&_wpnonce=' + poll.dataset.nonce )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( ! data || ! data.success ) { return; }
					var p = data.data;
					poll.querySelector( '.swps-audit-bar' ).style.width = p.percent + '%';
					poll.querySelector( '.swps-audit-count' ).textContent = p.done + ' / ' + p.total;
					if ( p.finished ) {
						window.clearInterval( tick );
						window.location.reload();
					}
				} );
		}, 3000 );
	}

	document.querySelectorAll( '[data-swps-copy-urls]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			navigator.clipboard.writeText( btn.dataset.swpsCopyUrls.split( '|' ).join( '\n' ) ).then( function () {
				btn.textContent = btn.dataset.copied || 'Copied!';
			} );
		} );
	} );
}() );
```

Minify: `npx --yes terser admin/js/site-audit.js --compress --mangle -o admin/js/site-audit.min.js`.

- [ ] **Step 3: Registration**

- `class-admin-shell.php`: add `'swps-site-audit'` to the shell slug allowlist (~line 35) and a nav item `array( 'slug' => 'swps-site-audit', 'label' => __( 'Site Audit', 'stratawp-seo' ) )` next to `swps-seo-audit` (~line 322).
- `class-settings.php`: register the submenu page with callback `array( stratawp_seo()->site_audit_screen, 'render' )` following the `swps-search-appearance` block.
- `stratawp-seo.php`: `require_once` the new class + `$this->site_audit_screen = new SWPS_Site_Audit_Screen();` next to the Task 4 requires; add the typed property `public SWPS_Site_Audit_Screen $site_audit_screen;`.

- [ ] **Step 4: PHPStan + suite + WP-CLI smoke**

Run: `vendor/bin/phpunit --testsuite unit && composer analyze` → PASS/clean. Then render smoke:

```bash
"$LOCAL_WP" --path="$SITE" eval '
$screen = stratawp_seo()->site_audit_screen;
ob_start(); $screen->render(); $html = ob_get_clean();
echo ( str_contains( $html, "swps-site-audit" ) && strlen( $html ) > 2000 ) ? "RENDER OK\n" : "RENDER BROKEN\n";
'
```

Expected: `RENDER OK`. Also load `wp-admin/admin.php?page=swps-site-audit` in a browser on jonimms.local and eyeball: gauge, cards, issue groups expand, exclude/re-enable round-trips.

- [ ] **Step 5: Commit**

```bash
git add includes/class-site-audit-screen.php admin/js/site-audit.js admin/js/site-audit.min.js admin/css/site-audit.css includes/class-admin-shell.php includes/class-settings.php stratawp-seo.php
git commit -m "Site audit: dashboard screen with score, triage, drill-downs, trends"
```

---

### Task 10: Old module handoff, changelog, version 4.27.0, final verification

**Files:**
- Modify: `includes/audit/class-site-crawl-module.php` (summary card linking to the new screen)
- Modify: `stratawp-seo.php` (version ×2), `readme.txt` (stable tag + changelog)

**Interfaces:**
- Consumes: run summary option from Task 9, screen slug `swps-site-audit`.
- Produces: released 4.27.0.

- [ ] **Step 1: Slim the audit module**

Replace `class-site-crawl-module.php`'s detailed issue rendering with: last-run score, error/warning/notice counts, and a "View full Site Audit →" link to `admin_url( 'admin.php?page=swps-site-audit' )`. Keep the module's registration and any score contribution to the overall audit intact (read the module before editing; only the render body shrinks).

- [ ] **Step 2: Version + changelog**

`stratawp-seo.php`: `Version: 4.27.0` + `SWPS_VERSION` → `4.27.0`. `readme.txt`: `Stable tag: 4.27.0` and changelog entry:

```
= 4.27.0 =
* New: Site Audit dashboard — a crawl-based audit of the rendered site with ~25 checks (missing/duplicate titles and descriptions, viewport/doctype/charset/lang, bot-challenge detection, unminified assets, word count, hreflang, orphan pages, and more), a 0-100 health score, error/warning/notice triage with per-page drill-downs and fix guidance, and crawl-over-crawl trends. The crawler now covers every public URL including term archives and pagination, and noindexed pages are audited for head integrity.
```

- [ ] **Step 3: Full verification battery**

```bash
vendor/bin/phpunit
composer analyze
```

Expected: all green, PHPStan clean. Then a full end-to-end on jonimms.local: start a run from the dashboard button, let it finish (or drive chunks via WP-CLI as in Task 7 Step 6), confirm the score renders, at least the known-real notices appear (paginated pages are NOT flagged for missing descriptions), and no challenge false positives on local pages.

- [ ] **Step 4: Commit + push + PR**

```bash
git add includes/audit/class-site-crawl-module.php stratawp-seo.php readme.txt
git commit -m "Site audit: module handoff card, changelog, version 4.27.0"
git push -u origin feature/site-audit-dashboard
gh pr create --title "Site Audit: crawl-based check suite + dashboard (4.27.0)" --body "Implements docs/superpowers/specs/2026-08-14-site-audit-dashboard-design.md — see spec for architecture and check inventory."
```

Merging is Jon's call (auto-releases 4.27.0).
