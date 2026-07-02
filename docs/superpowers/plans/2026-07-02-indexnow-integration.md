# IndexNow Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Instantly notify IndexNow search engines (Bing, Yandex, Seznam, Naver) whenever a StrataWP SEO site publishes, updates, or removes a URL.

**Architecture:** One new class `SWPS_IndexNow` (`includes/class-indexnow.php`) owns key management, the `/{key}.txt` verification file, post/term lifecycle triggers, a 60-second debounced queue flushed by a single wp-cron event, the batched HTTP submit, an environment guard, a ring-buffer activity log, and AJAX handlers. "What counts as an indexable URL" is centralized in three new public-static methods on `SWPS_Sitemap_Manager` so IndexNow submits exactly the sitemap's URL set. Settings persist through a dedicated `swps_indexnow_settings` option group (mirroring the existing `swps_sitemap_settings` precedent); the key is generated/rotated via AJAX and never rendered in the form.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, PHPUnit 9.6 (pure-PHP unit tests with inline WP function stubs — **tests do NOT load WordPress**), WordPress Settings API, wp-cron, `wp_remote_post`.

## Global Constraints

- **No runtime autoloader** — every new class MUST get an explicit `require_once SWPS_PLUGIN_DIR . 'includes/class-<name>.php';` in `stratawp-seo.php`. A missing require is a runtime-only fatal; PHPStan will not catch it.
- **Option prefix** `swps_` on every option; **meta prefix** `_swps_` on every post/term meta key.
- **Settings double-registration footgun** — register each option name exactly ONCE. Never register an array option (`multi_checkbox`) a second time as a scalar; the stacked `sanitize_option_` filter silently wipes the array on save.
- **IndexNow key is PUBLIC** — stored plain in `swps_indexnow_api_key`; never encrypt it (it is served in cleartext at `/{key}.txt`).
- **Text domain** `stratawp-seo` on every user-facing string.
- **Version target** `4.22.0` (new feature → minor bump): update both the plugin header `Version:` and `define( 'SWPS_VERSION', ... )`.
- **Files under 500 lines**; **validate input at system boundaries** (AJAX handlers: `check_ajax_referer` + `current_user_can`).
- **Test harness:** pure PHP, no mocking framework. Each test file conditionally defines the WP functions it needs (guarded by `function_exists`) and injects data through `$GLOBALS`. Use `@runInSeparateProcess` + `@preserveGlobalState disabled` on any test method that relies on redefinable stubs or `define()`, exactly like `tests/unit/SitemapHomepageTest.php`. Run one file with `./vendor/bin/phpunit tests/unit/<File>.php`; run all with `composer test`.

---

## Naming Reference (used across all tasks)

| Symbol | Value |
|--------|-------|
| Class | `SWPS_IndexNow` in `includes/class-indexnow.php` |
| Cron hook | `SWPS_IndexNow::CRON_HOOK` = `'swps_indexnow_flush'` |
| Endpoint | `SWPS_IndexNow::ENDPOINT` = `'https://api.indexnow.org/indexnow'` |
| Options | `swps_indexnow_enabled`, `swps_indexnow_auto_submit`, `swps_indexnow_api_key`, `swps_indexnow_post_types` (array), `swps_indexnow_queue` (array), `swps_indexnow_log` (array), `swps_indexnow_daily_count` (int), `swps_indexnow_daily_date` (string) |
| Post meta | `_swps_indexnow_last_url`, `_swps_indexnow_submitted` |
| Settings group | `swps_indexnow_settings` |
| AJAX actions | `swps_indexnow_generate_key`, `swps_indexnow_resubmit_all`, `swps_indexnow_get_log`, `swps_indexnow_submit_post` |
| Constants | `MAX_LOG` = 50, `MAX_URLS_PER_REQUEST` = 10000, `DEBOUNCE_SECONDS` = 60, `DAILY_CAP` = 10000 |

---

## Task 1: Sitemap shared eligibility

**Files:**
- Modify: `includes/class-sitemap-manager.php` (make the two hidden-checks `private static`; add three new public-static methods)
- Test: `tests/unit/SitemapIndexableTest.php` (create)

**Interfaces:**
- Produces:
  - `SWPS_Sitemap_Manager::is_post_indexable( $post ): bool`
  - `SWPS_Sitemap_Manager::is_term_indexable( int $term_id ): bool`
  - `SWPS_Sitemap_Manager::get_indexable_urls(): array` (absolute URLs, deduped)

**Why the renderers are NOT refactored:** the existing `render_post_type_sitemap()` loop and its regression tests (`SitemapHomepageTest`) pass bare `stdClass` objects without `post_status`/`post_type`. Routing that loop through a `WP_Post`-typed predicate would break those tests. Instead we add `is_post_indexable()` as the canonical predicate that IndexNow consumes, encoding the *identical* rules the renderer applies inline. Keep a `// Keep in sync with SWPS_Sitemap_Manager::is_post_indexable().` comment above the renderer's inline skip checks.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/SitemapIndexableTest.php`:

```php
<?php
/**
 * Unit tests for the shared IndexNow/sitemap eligibility predicate.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		return $GLOBALS['swps_test_postmeta'][ $id ][ $key ] ?? '';
	}
}

require_once __DIR__ . '/../../includes/class-sitemap-manager.php';

class SitemapIndexableTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options']  = array();
		$GLOBALS['swps_test_postmeta'] = array();
	}

	private function post( int $id, string $status = 'publish', string $type = 'post' ): object {
		return (object) array( 'ID' => $id, 'post_status' => $status, 'post_type' => $type );
	}

	public function test_published_post_is_indexable(): void {
		$this->assertTrue( SWPS_Sitemap_Manager::is_post_indexable( $this->post( 1 ) ) );
	}

	public function test_draft_is_not_indexable(): void {
		$this->assertFalse( SWPS_Sitemap_Manager::is_post_indexable( $this->post( 1, 'draft' ) ) );
	}

	public function test_excluded_post_meta_blocks_indexing(): void {
		$GLOBALS['swps_test_postmeta'][2]['_swps_sitemap_exclude'] = 1;
		$this->assertFalse( SWPS_Sitemap_Manager::is_post_indexable( $this->post( 2 ) ) );
	}

	public function test_noindex_robots_meta_blocks_indexing(): void {
		$GLOBALS['swps_test_postmeta'][3]['_swps_robots'] = 'noindex,follow';
		$this->assertFalse( SWPS_Sitemap_Manager::is_post_indexable( $this->post( 3 ) ) );
	}

	public function test_hidden_post_type_blocks_indexing(): void {
		$GLOBALS['swps_test_options']['swps_sitemap_exclude_product'] = 1;
		$this->assertFalse( SWPS_Sitemap_Manager::is_post_indexable( $this->post( 4, 'publish', 'product' ) ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SitemapIndexableTest.php`
Expected: FAIL — `Error: Call to undefined method SWPS_Sitemap_Manager::is_post_indexable()`.

- [ ] **Step 3: Make the hidden-checks static**

In `includes/class-sitemap-manager.php`, change the two helpers from `private function` to `private static function`:

```php
	private static function is_post_type_hidden_from_sitemap( string $post_type ): bool {
		return 'attachment' === $post_type
			|| (bool) get_option( "swps_sitemap_exclude_{$post_type}", 0 )
			|| (bool) get_option( "swps_noindex_{$post_type}", 0 );
	}

	private static function is_taxonomy_hidden_from_sitemap( string $taxonomy ): bool {
		return 'post_format' === $taxonomy
			|| (bool) get_option( "swps_sitemap_exclude_{$taxonomy}", 0 )
			|| (bool) get_option( "swps_noindex_{$taxonomy}", 0 );
	}
```

Then update their call sites: replace every `$this->is_post_type_hidden_from_sitemap(` with `self::is_post_type_hidden_from_sitemap(` and every `$this->is_taxonomy_hidden_from_sitemap(` with `self::is_taxonomy_hidden_from_sitemap(`. Find them with:

Run: `grep -n 'is_post_type_hidden_from_sitemap\|is_taxonomy_hidden_from_sitemap' includes/class-sitemap-manager.php`

- [ ] **Step 4: Add the three public-static methods**

Add to `SWPS_Sitemap_Manager` (near the other helpers):

```php
	/**
	 * Canonical "is this URL part of the sitemap" predicate, shared with IndexNow.
	 * Mirrors the inline skip logic in render_post_type_sitemap().
	 *
	 * @param WP_Post|object $post Post object with ID, post_status, post_type.
	 */
	public static function is_post_indexable( $post ): bool {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return false;
		}
		if ( 'publish' !== ( $post->post_status ?? '' ) ) {
			return false;
		}
		if ( self::is_post_type_hidden_from_sitemap( (string) ( $post->post_type ?? '' ) ) ) {
			return false;
		}
		if ( get_post_meta( $post->ID, '_swps_sitemap_exclude', true ) ) {
			return false;
		}
		$robots = get_post_meta( $post->ID, '_swps_robots', true );
		if ( ! empty( $robots ) && str_contains( (string) $robots, 'noindex' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Term eligibility, mirroring render_taxonomy_sitemap()'s inline checks.
	 */
	public static function is_term_indexable( int $term_id ): bool {
		if ( get_term_meta( $term_id, '_swps_sitemap_exclude', true ) ) {
			return false;
		}
		$robots = get_term_meta( $term_id, '_swps_robots', true );
		if ( ! empty( $robots ) && str_contains( (string) $robots, 'noindex' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Full indexable URL set (posts + pages + CPTs + taxonomies + authors),
	 * applying the same eligibility rules as the sitemap. Used by "Resubmit all".
	 *
	 * @return string[] Absolute URLs, deduped.
	 */
	public static function get_indexable_urls(): array {
		$urls = array( home_url( '/' ) );

		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( self::is_post_type_hidden_from_sitemap( $post_type ) ) {
				continue;
			}
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $posts as $post_id ) {
				$post = get_post( $post_id );
				if ( $post && self::is_post_indexable( $post ) ) {
					$urls[] = get_permalink( $post );
				}
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			if ( self::is_taxonomy_hidden_from_sitemap( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( self::is_term_indexable( (int) $term->term_id ) ) {
					$urls[] = get_term_link( $term );
				}
			}
		}

		if ( ! get_option( 'swps_sitemap_exclude_author', 0 ) ) {
			$authors = get_users( array( 'has_published_posts' => true, 'fields' => 'ID' ) );
			foreach ( $authors as $author_id ) {
				$urls[] = get_author_posts_url( (int) $author_id );
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}
```

- [ ] **Step 5: Run the new test and the existing sitemap tests**

Run: `./vendor/bin/phpunit tests/unit/SitemapIndexableTest.php tests/unit/SitemapHomepageTest.php tests/unit/SitemapUrlTest.php`
Expected: PASS (all green — the existing sitemap tests must remain green, proving the static change is behavior-preserving).

- [ ] **Step 6: Commit**

```bash
git add includes/class-sitemap-manager.php tests/unit/SitemapIndexableTest.php
git commit -m "feat(indexnow): add shared sitemap eligibility predicate for IndexNow"
```

---

## Task 2: SWPS_IndexNow scaffold + bootstrap wiring

**Files:**
- Create: `includes/class-indexnow.php`
- Modify: `stratawp-seo.php` (add `require_once`; instantiate in `__construct()`)
- Test: `tests/unit/IndexNowScaffoldTest.php` (create)

**Interfaces:**
- Produces: class `SWPS_IndexNow` with the constants from the Naming Reference; `public function flush(): void` (real body arrives in Task 7 — no-op stub for now); constructor that registers `add_action( self::CRON_HOOK, array( $this, 'flush' ) )`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/IndexNowScaffoldTest.php`:

```php
<?php
/**
 * Scaffold test: the IndexNow class and its public contract exist.
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

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowScaffoldTest extends TestCase {

	public function test_class_and_constants_exist(): void {
		$this->assertTrue( class_exists( 'SWPS_IndexNow' ) );
		$this->assertSame( 'swps_indexnow_flush', SWPS_IndexNow::CRON_HOOK );
		$this->assertSame( 'https://api.indexnow.org/indexnow', SWPS_IndexNow::ENDPOINT );
		$this->assertSame( 50, SWPS_IndexNow::MAX_LOG );
		$this->assertSame( 10000, SWPS_IndexNow::MAX_URLS_PER_REQUEST );
		$this->assertSame( 60, SWPS_IndexNow::DEBOUNCE_SECONDS );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/IndexNowScaffoldTest.php`
Expected: FAIL — `require_once ... class-indexnow.php` → "No such file or directory".

- [ ] **Step 3: Create the class file**

Create `includes/class-indexnow.php`:

```php
<?php
/**
 * IndexNow integration — instantly notify Bing/Yandex/Seznam/Naver of URL changes.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns key management, the /{key}.txt verification file, lifecycle triggers,
 * the debounced submit queue, HTTP submission, the activity log, and AJAX.
 */
class SWPS_IndexNow {

	const CRON_HOOK           = 'swps_indexnow_flush';
	const ENDPOINT            = 'https://api.indexnow.org/indexnow';
	const MAX_LOG             = 50;
	const MAX_URLS_PER_REQUEST = 10000;
	const DEBOUNCE_SECONDS    = 60;
	const DAILY_CAP           = 10000;

	const OPT_ENABLED     = 'swps_indexnow_enabled';
	const OPT_AUTO        = 'swps_indexnow_auto_submit';
	const OPT_KEY         = 'swps_indexnow_api_key';
	const OPT_POST_TYPES  = 'swps_indexnow_post_types';
	const OPT_QUEUE       = 'swps_indexnow_queue';
	const OPT_LOG         = 'swps_indexnow_log';
	const OPT_DAILY_COUNT = 'swps_indexnow_daily_count';
	const OPT_DAILY_DATE  = 'swps_indexnow_daily_date';

	const META_LAST_URL  = '_swps_indexnow_last_url';
	const META_SUBMITTED = '_swps_indexnow_submitted';

	const SETTINGS_GROUP = 'swps_indexnow_settings';

	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'flush' ) );
	}

	/**
	 * wp-cron handler — drains the debounce queue and submits. Filled in Task 7.
	 */
	public function flush(): void {
	}
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/IndexNowScaffoldTest.php`
Expected: PASS.

- [ ] **Step 5: Wire into the bootstrap**

In `stratawp-seo.php`, add the require after the rate-limiter require (line 63):

```php
	require_once SWPS_PLUGIN_DIR . 'includes/class-rate-limiter.php';
	require_once SWPS_PLUGIN_DIR . 'includes/class-indexnow.php';
```

In `StrataWP_SEO::__construct()`, instantiate it next to the sitemap manager (after line 400, `$this->sitemap_admin = new SWPS_Sitemap_Admin();`):

```php
		$this->sitemap_admin = new SWPS_Sitemap_Admin();
		$this->indexnow      = new SWPS_IndexNow();
```

Add the property declaration alongside the other `private $...;` class properties (match the existing property block style near the top of the class):

```php
	private $indexnow;
```

- [ ] **Step 6: Lint and run the full suite**

Run: `php -l includes/class-indexnow.php && php -l stratawp-seo.php && composer test`
Expected: "No syntax errors detected" for both files; PHPUnit all green.

- [ ] **Step 7: Commit**

```bash
git add includes/class-indexnow.php stratawp-seo.php tests/unit/IndexNowScaffoldTest.php
git commit -m "feat(indexnow): scaffold SWPS_IndexNow class and bootstrap wiring"
```

---

## Task 3: Pure helper methods

**Files:**
- Modify: `includes/class-indexnow.php`
- Test: `tests/unit/IndexNowHelpersTest.php` (create)

**Interfaces:**
- Produces:
  - `SWPS_IndexNow::generate_key(): string`
  - `SWPS_IndexNow::is_valid_key( string $key ): bool`
  - `SWPS_IndexNow::is_staging_host( string $host ): bool`
  - `SWPS_IndexNow::should_skip_environment(): bool`
  - `SWPS_IndexNow::build_payload( string $host, string $key, array $urls ): array`
  - `SWPS_IndexNow::interpret_response_code( int $code ): string`
  - `SWPS_IndexNow::key_file_path_matches( string $path, string $key ): bool`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/IndexNowHelpersTest.php`:

```php
<?php
/**
 * Unit tests for SWPS_IndexNow pure helpers.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return ( $GLOBALS['swps_test_home'] ?? 'https://example.com' ) . $path;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type() {
		return $GLOBALS['swps_test_env'] ?? 'production';
	}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowHelpersTest extends TestCase {

	public function test_generate_key_is_32_hex_chars(): void {
		$key = SWPS_IndexNow::generate_key();
		$this->assertSame( 32, strlen( $key ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $key );
		$this->assertNotSame( SWPS_IndexNow::generate_key(), $key );
	}

	public function test_is_valid_key(): void {
		$this->assertTrue( SWPS_IndexNow::is_valid_key( str_repeat( 'a', 32 ) ) );
		$this->assertTrue( SWPS_IndexNow::is_valid_key( 'abc1234-DEF' ) );
		$this->assertFalse( SWPS_IndexNow::is_valid_key( 'short' ) );        // < 8
		$this->assertFalse( SWPS_IndexNow::is_valid_key( 'has space here' ) );
		$this->assertFalse( SWPS_IndexNow::is_valid_key( '' ) );
	}

	public function test_is_staging_host(): void {
		$this->assertTrue( SWPS_IndexNow::is_staging_host( 'localhost' ) );
		$this->assertTrue( SWPS_IndexNow::is_staging_host( 'jonimms.local' ) );
		$this->assertTrue( SWPS_IndexNow::is_staging_host( 'my-site.test' ) );
		$this->assertTrue( SWPS_IndexNow::is_staging_host( 'staging.example.com' ) );
		$this->assertTrue( SWPS_IndexNow::is_staging_host( 'dev.example.com' ) );
		$this->assertFalse( SWPS_IndexNow::is_staging_host( 'example.com' ) );
		$this->assertFalse( SWPS_IndexNow::is_staging_host( 'www.jonimms.com' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_should_skip_environment_on_non_production(): void {
		$GLOBALS['swps_test_env']  = 'staging';
		$GLOBALS['swps_test_home'] = 'https://example.com';
		$this->assertTrue( SWPS_IndexNow::should_skip_environment() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_should_skip_environment_on_staging_host(): void {
		$GLOBALS['swps_test_env']  = 'production';
		$GLOBALS['swps_test_home'] = 'https://staging.example.com';
		$this->assertTrue( SWPS_IndexNow::should_skip_environment() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_should_not_skip_on_production_live_host(): void {
		$GLOBALS['swps_test_env']  = 'production';
		$GLOBALS['swps_test_home'] = 'https://www.jonimms.com';
		$this->assertFalse( SWPS_IndexNow::should_skip_environment() );
	}

	public function test_build_payload(): void {
		$payload = SWPS_IndexNow::build_payload( 'example.com', 'abcdef1234', array( 'https://example.com/a', 'https://example.com/a' ) );
		$this->assertSame( 'example.com', $payload['host'] );
		$this->assertSame( 'abcdef1234', $payload['key'] );
		$this->assertSame( 'https://example.com/abcdef1234.txt', $payload['keyLocation'] );
		$this->assertSame( array( 'https://example.com/a', 'https://example.com/a' ), $payload['urlList'] );
	}

	public function test_interpret_response_code(): void {
		$this->assertSame( 'ok', SWPS_IndexNow::interpret_response_code( 200 ) );
		$this->assertSame( 'pending', SWPS_IndexNow::interpret_response_code( 202 ) );
		$this->assertSame( 'invalid', SWPS_IndexNow::interpret_response_code( 400 ) );
		$this->assertSame( 'key_not_found', SWPS_IndexNow::interpret_response_code( 403 ) );
		$this->assertSame( 'host_mismatch', SWPS_IndexNow::interpret_response_code( 422 ) );
		$this->assertSame( 'rate_limited', SWPS_IndexNow::interpret_response_code( 429 ) );
		$this->assertSame( 'error', SWPS_IndexNow::interpret_response_code( 500 ) );
	}

	public function test_key_file_path_matches(): void {
		$this->assertTrue( SWPS_IndexNow::key_file_path_matches( '/abc123.txt', 'abc123' ) );
		$this->assertFalse( SWPS_IndexNow::key_file_path_matches( '/other.txt', 'abc123' ) );
		$this->assertFalse( SWPS_IndexNow::key_file_path_matches( '/abc123.txt/', 'abc123' ) );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/IndexNowHelpersTest.php`
Expected: FAIL — "Call to undefined method SWPS_IndexNow::generate_key()".

- [ ] **Step 3: Implement the helpers**

Add to `SWPS_IndexNow` (after `flush()`):

```php
	/** Generate a fresh 32-char hex IndexNow key (does not persist). */
	public static function generate_key(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/** IndexNow keys are 8–128 chars of [a-zA-Z0-9-]. */
	public static function is_valid_key( string $key ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $key );
	}

	/** Heuristic: does this host look like a local/staging environment? */
	public static function is_staging_host( string $host ): bool {
		$host = strtolower( $host );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		foreach ( array( '.local', '.test', '.localhost', '.example', '.invalid' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		foreach ( array( 'staging.', 'stage.', 'dev.', 'test.', 'sandbox.' ) as $prefix ) {
			if ( str_starts_with( $host, $prefix ) ) {
				return true;
			}
		}
		return str_contains( $host, '.staging.' ) || str_contains( $host, '.dev.' );
	}

	/** True when submissions must be suppressed (non-production or staging host). */
	public static function should_skip_environment(): bool {
		if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
			return true;
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return self::is_staging_host( $host );
	}

	/** Build the IndexNow POST body. Pure. */
	public static function build_payload( string $host, string $key, array $urls ): array {
		return array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => 'https://' . $host . '/' . $key . '.txt',
			'urlList'     => array_values( $urls ),
		);
	}

	/** Map an HTTP status code to a log-friendly result label. Pure. */
	public static function interpret_response_code( int $code ): string {
		switch ( $code ) {
			case 200:
				return 'ok';
			case 202:
				return 'pending';
			case 400:
				return 'invalid';
			case 403:
				return 'key_not_found';
			case 422:
				return 'host_mismatch';
			case 429:
				return 'rate_limited';
			default:
				return 'error';
		}
	}

	/** Does a request path exactly match /{key}.txt? Pure. */
	public static function key_file_path_matches( string $path, string $key ): bool {
		return '/' . $key . '.txt' === $path;
	}
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/IndexNowHelpersTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-indexnow.php tests/unit/IndexNowHelpersTest.php
git commit -m "feat(indexnow): add pure helpers (key, env guard, payload, response codes)"
```

---

## Task 4: Activity log ring buffer

**Files:**
- Modify: `includes/class-indexnow.php`
- Test: `tests/unit/IndexNowLogTest.php` (create)

**Interfaces:**
- Produces: `SWPS_IndexNow::append_log( array $entry ): void`, `SWPS_IndexNow::get_log(): array`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/IndexNowLogTest.php`:

```php
<?php
/**
 * Unit tests for the IndexNow activity-log ring buffer.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['swps_test_options'][ $option ] = $value;
		return true;
	}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowLogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options'] = array();
	}

	public function test_append_prepends_newest_first(): void {
		SWPS_IndexNow::append_log( array( 'result' => 'first' ) );
		SWPS_IndexNow::append_log( array( 'result' => 'second' ) );
		$log = SWPS_IndexNow::get_log();
		$this->assertSame( 'second', $log[0]['result'] );
		$this->assertSame( 'first', $log[1]['result'] );
	}

	public function test_log_caps_at_max(): void {
		for ( $i = 0; $i < SWPS_IndexNow::MAX_LOG + 20; $i++ ) {
			SWPS_IndexNow::append_log( array( 'result' => "e{$i}" ) );
		}
		$this->assertCount( SWPS_IndexNow::MAX_LOG, SWPS_IndexNow::get_log() );
	}

	public function test_get_log_returns_empty_array_when_unset(): void {
		$this->assertSame( array(), SWPS_IndexNow::get_log() );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/IndexNowLogTest.php`
Expected: FAIL — "Call to undefined method SWPS_IndexNow::append_log()".

- [ ] **Step 3: Implement**

Add to `SWPS_IndexNow`:

```php
	/** Prepend an entry to the bounded activity log (newest first, capped at MAX_LOG). */
	public static function append_log( array $entry ): void {
		$log = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX_LOG );
		update_option( self::OPT_LOG, $log, false );
	}

	/** @return array[] The activity log, newest first. */
	public static function get_log(): array {
		$log = get_option( self::OPT_LOG, array() );
		return is_array( $log ) ? $log : array();
	}
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/IndexNowLogTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-indexnow.php tests/unit/IndexNowLogTest.php
git commit -m "feat(indexnow): activity-log ring buffer"
```

---

## Task 5: Debounce queue (enqueue + dedup + schedule)

**Files:**
- Modify: `includes/class-indexnow.php`
- Test: `tests/unit/IndexNowQueueTest.php` (create)

**Interfaces:**
- Produces: `SWPS_IndexNow::enqueue_url( string $url ): void`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/IndexNowQueueTest.php`:

```php
<?php
/**
 * Unit tests for the IndexNow debounce queue.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['swps_test_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return $GLOBALS['swps_test_scheduled'][ $hook ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) {
		$GLOBALS['swps_test_scheduled'][ $hook ]     = $ts;
		$GLOBALS['swps_test_schedule_calls']         = ( $GLOBALS['swps_test_schedule_calls'] ?? 0 ) + 1;
		return true;
	}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowQueueTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options']         = array();
		$GLOBALS['swps_test_scheduled']       = array();
		$GLOBALS['swps_test_schedule_calls']  = 0;
	}

	public function test_enqueue_adds_url(): void {
		SWPS_IndexNow::enqueue_url( 'https://example.com/a' );
		$this->assertSame( array( 'https://example.com/a' ), get_option( SWPS_IndexNow::OPT_QUEUE ) );
	}

	public function test_enqueue_dedupes(): void {
		SWPS_IndexNow::enqueue_url( 'https://example.com/a' );
		SWPS_IndexNow::enqueue_url( 'https://example.com/a' );
		$this->assertCount( 1, get_option( SWPS_IndexNow::OPT_QUEUE ) );
	}

	public function test_enqueue_schedules_flush_once_per_burst(): void {
		SWPS_IndexNow::enqueue_url( 'https://example.com/a' );
		SWPS_IndexNow::enqueue_url( 'https://example.com/b' );
		$this->assertSame( 1, $GLOBALS['swps_test_schedule_calls'] );
		$this->assertArrayHasKey( SWPS_IndexNow::CRON_HOOK, $GLOBALS['swps_test_scheduled'] );
	}

	public function test_enqueue_ignores_empty(): void {
		SWPS_IndexNow::enqueue_url( '   ' );
		$this->assertSame( array(), get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/IndexNowQueueTest.php`
Expected: FAIL — "Call to undefined method SWPS_IndexNow::enqueue_url()".

- [ ] **Step 3: Implement**

Add to `SWPS_IndexNow`:

```php
	/** Add a URL to the debounce queue (deduped) and schedule a single flush. */
	public static function enqueue_url( string $url ): void {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return;
		}
		$queue = get_option( self::OPT_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		if ( ! in_array( $url, $queue, true ) ) {
			$queue[] = $url;
			update_option( self::OPT_QUEUE, $queue, false );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::CRON_HOOK );
		}
	}
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/IndexNowQueueTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-indexnow.php tests/unit/IndexNowQueueTest.php
git commit -m "feat(indexnow): debounced submit queue with dedup + single-event scheduling"
```

---

## Task 6: Eligibility gate + no-op suppression (auto path)

**Files:**
- Modify: `includes/class-indexnow.php`
- Test: `tests/unit/IndexNowEligibilityTest.php` (create)

**Interfaces:**
- Consumes: `SWPS_Sitemap_Manager::is_post_indexable()` (Task 1); `SWPS_IndexNow::enqueue_url()` (Task 5); `SWPS_IndexNow::should_skip_environment()` (Task 3)
- Produces: `SWPS_IndexNow::maybe_enqueue_post( $post ): void`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/IndexNowEligibilityTest.php`:

```php
<?php
/**
 * Unit tests for the IndexNow auto-enqueue eligibility gate.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['swps_test_options'][ $option ] = $value;
		return true;
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
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return 'https://example.com/?p=' . $id;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $c = -1 ) {
		return parse_url( $url, $c );
	}
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type() {
		return 'production';
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) {
		return true;
	}
}

require_once __DIR__ . '/../../includes/class-sitemap-manager.php';
require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowEligibilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options']  = array(
			SWPS_IndexNow::OPT_ENABLED    => 1,
			SWPS_IndexNow::OPT_AUTO       => 1,
			SWPS_IndexNow::OPT_POST_TYPES => array( 'post', 'page' ),
		);
		$GLOBALS['swps_test_postmeta'] = array();
	}

	private function post( int $id, string $type = 'post', string $modified = '2026-07-02 00:00:00' ): object {
		return (object) array( 'ID' => $id, 'post_status' => 'publish', 'post_type' => $type, 'post_modified_gmt' => $modified );
	}

	public function test_eligible_post_is_enqueued(): void {
		( new SWPS_IndexNow() )->maybe_enqueue_post( $this->post( 10 ) );
		$this->assertContains( 'https://example.com/?p=10', get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}

	public function test_disabled_feature_skips(): void {
		$GLOBALS['swps_test_options'][ SWPS_IndexNow::OPT_ENABLED ] = 0;
		( new SWPS_IndexNow() )->maybe_enqueue_post( $this->post( 10 ) );
		$this->assertSame( array(), get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}

	public function test_unselected_post_type_skips(): void {
		( new SWPS_IndexNow() )->maybe_enqueue_post( $this->post( 10, 'product' ) );
		$this->assertSame( array(), get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}

	public function test_noop_resave_is_suppressed(): void {
		$in = new SWPS_IndexNow();
		$in->maybe_enqueue_post( $this->post( 10, 'post', '2026-07-02 00:00:00' ) );
		$GLOBALS['swps_test_options'][ SWPS_IndexNow::OPT_QUEUE ] = array(); // simulate a flush
		$in->maybe_enqueue_post( $this->post( 10, 'post', '2026-07-02 00:00:00' ) ); // identical modified
		$this->assertSame( array(), get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}

	public function test_edited_post_resubmits(): void {
		$in = new SWPS_IndexNow();
		$in->maybe_enqueue_post( $this->post( 10, 'post', '2026-07-02 00:00:00' ) );
		$GLOBALS['swps_test_options'][ SWPS_IndexNow::OPT_QUEUE ] = array();
		$in->maybe_enqueue_post( $this->post( 10, 'post', '2026-07-03 12:00:00' ) ); // changed
		$this->assertContains( 'https://example.com/?p=10', get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/IndexNowEligibilityTest.php`
Expected: FAIL — "Call to undefined method SWPS_IndexNow::maybe_enqueue_post()".

- [ ] **Step 3: Implement**

Add to `SWPS_IndexNow`:

```php
	/**
	 * Auto path: enqueue a post's permalink if IndexNow is enabled, auto-submit is
	 * on, the post type is selected, the URL is sitemap-indexable, we're on
	 * production, and the content actually changed since the last submit.
	 *
	 * @param WP_Post|object $post
	 */
	public function maybe_enqueue_post( $post ): void {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return;
		}
		if ( ! get_option( self::OPT_ENABLED, 0 ) || ! get_option( self::OPT_AUTO, 1 ) ) {
			return;
		}
		$selected = (array) get_option( self::OPT_POST_TYPES, array() );
		if ( ! in_array( ( $post->post_type ?? '' ), $selected, true ) ) {
			return;
		}
		if ( ! SWPS_Sitemap_Manager::is_post_indexable( $post ) ) {
			return;
		}
		if ( self::should_skip_environment() ) {
			return;
		}
		$modified = (string) ( $post->post_modified_gmt ?? '' );
		if ( '' !== $modified && (string) get_post_meta( $post->ID, self::META_SUBMITTED, true ) === $modified ) {
			return; // No-op resave — content unchanged since last push.
		}
		$url = get_permalink( $post );
		update_post_meta( $post->ID, self::META_LAST_URL, $url );
		update_post_meta( $post->ID, self::META_SUBMITTED, $modified );
		self::enqueue_url( $url );
	}
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/IndexNowEligibilityTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-indexnow.php tests/unit/IndexNowEligibilityTest.php
git commit -m "feat(indexnow): eligibility gate + no-op resave suppression"
```

---

## Task 7: HTTP submit + flush

**Files:**
- Modify: `includes/class-indexnow.php` (fill in `flush()`, add `submit_urls()`)
- Test: `tests/unit/IndexNowSubmitTest.php` (create)

**Interfaces:**
- Consumes: `build_payload()`, `interpret_response_code()`, `is_valid_key()`, `should_skip_environment()`, `append_log()`
- Produces: `SWPS_IndexNow::submit_urls( array $urls, string $trigger = 'manual' ): array`; real `flush()` body

- [ ] **Step 1: Write the failing test**

Create `tests/unit/IndexNowSubmitTest.php`:

```php
<?php
/**
 * Unit tests for IndexNow HTTP submission + flush.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['swps_test_options'][ $option ] ?? $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['swps_test_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $c = -1 ) {
		return parse_url( $url, $c );
	}
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type() {
		return 'production';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['swps_test_last_post'] = array( 'url' => $url, 'args' => $args );
		return $GLOBALS['swps_test_http_response'] ?? array( 'response' => array( 'code' => 200 ), 'body' => '' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return $r['response']['code'] ?? 0;
	}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowSubmitTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options']       = array( SWPS_IndexNow::OPT_KEY => str_repeat( 'a', 32 ) );
		$GLOBALS['swps_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '' );
		unset( $GLOBALS['swps_test_last_post'] );
	}

	public function test_submit_posts_json_and_logs_ok(): void {
		$results = ( new SWPS_IndexNow() )->submit_urls( array( 'https://example.com/a' ), 'manual' );
		$this->assertSame( 'ok', $results[0]['result'] );
		$this->assertSame( SWPS_IndexNow::ENDPOINT, $GLOBALS['swps_test_last_post']['url'] );
		$body = json_decode( $GLOBALS['swps_test_last_post']['args']['body'], true );
		$this->assertSame( 'example.com', $body['host'] );
		$this->assertSame( array( 'https://example.com/a' ), $body['urlList'] );
		$this->assertSame( 'ok', SWPS_IndexNow::get_log()[0]['result'] );
	}

	public function test_submit_without_valid_key_logs_no_key(): void {
		$GLOBALS['swps_test_options'][ SWPS_IndexNow::OPT_KEY ] = '';
		$results = ( new SWPS_IndexNow() )->submit_urls( array( 'https://example.com/a' ) );
		$this->assertSame( array(), $results );
		$this->assertSame( 'no_key', SWPS_IndexNow::get_log()[0]['result'] );
	}

	public function test_wp_error_is_logged_as_error(): void {
		$GLOBALS['swps_test_http_response'] = new WP_Error( 'http', 'down' );
		$results = ( new SWPS_IndexNow() )->submit_urls( array( 'https://example.com/a' ) );
		$this->assertSame( 'error', $results[0]['result'] );
	}

	public function test_flush_drains_queue_and_submits(): void {
		$GLOBALS['swps_test_options'][ SWPS_IndexNow::OPT_QUEUE ] = array( 'https://example.com/a', 'https://example.com/b' );
		( new SWPS_IndexNow() )->flush();
		$this->assertSame( array(), get_option( SWPS_IndexNow::OPT_QUEUE, array() ) );
		$body = json_decode( $GLOBALS['swps_test_last_post']['args']['body'], true );
		$this->assertCount( 2, $body['urlList'] );
	}

	public function test_flush_on_empty_queue_does_nothing(): void {
		( new SWPS_IndexNow() )->flush();
		$this->assertArrayNotHasKey( 'swps_test_last_post', $GLOBALS );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/IndexNowSubmitTest.php`
Expected: FAIL — "Call to undefined method SWPS_IndexNow::submit_urls()".

- [ ] **Step 3: Implement `submit_urls()` and fill `flush()`**

Add `submit_urls()` and replace the empty `flush()` body:

```php
	/**
	 * POST one or more chunks of URLs to IndexNow. Logs each chunk's result.
	 *
	 * @return array[] One {code,result} per chunk.
	 */
	public function submit_urls( array $urls, string $trigger = 'manual' ): array {
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		if ( empty( $urls ) ) {
			return array();
		}
		$key = (string) get_option( self::OPT_KEY, '' );
		if ( ! self::is_valid_key( $key ) ) {
			self::append_log( array( 'time' => time(), 'trigger' => $trigger, 'count' => count( $urls ), 'code' => 0, 'result' => 'no_key' ) );
			return array();
		}
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$results = array();
		foreach ( array_chunk( $urls, self::MAX_URLS_PER_REQUEST ) as $chunk ) {
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode( self::build_payload( $host, $key, $chunk ) ),
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) ) {
				$code   = 0;
				$result = 'error';
			} else {
				$code   = (int) wp_remote_retrieve_response_code( $response );
				$result = self::interpret_response_code( $code );
			}
			self::append_log( array( 'time' => time(), 'trigger' => $trigger, 'count' => count( $chunk ), 'code' => $code, 'result' => $result ) );
			$results[] = array( 'code' => $code, 'result' => $result );
		}
		return $results;
	}
```

Replace the `flush()` stub from Task 2 with:

```php
	/** wp-cron handler — drain the debounce queue and submit as one batch. */
	public function flush(): void {
		$queue = get_option( self::OPT_QUEUE, array() );
		update_option( self::OPT_QUEUE, array(), false );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		if ( self::should_skip_environment() ) {
			self::append_log( array( 'time' => time(), 'trigger' => 'auto', 'count' => count( $queue ), 'code' => 0, 'result' => 'skipped_env' ) );
			return;
		}
		$this->submit_urls( array_values( $queue ), 'auto' );
	}
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/IndexNowSubmitTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `composer test`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add includes/class-indexnow.php tests/unit/IndexNowSubmitTest.php
git commit -m "feat(indexnow): batched HTTP submit + cron flush"
```

---

## Task 8: Lifecycle hooks + key-file serving

**Files:**
- Modify: `includes/class-indexnow.php` (register hooks in constructor; add handlers)

**Interfaces:**
- Consumes: `maybe_enqueue_post()`, `enqueue_url()`, `should_skip_environment()`, `is_valid_key()`, `key_file_path_matches()`
- Produces: `on_transition_post_status()`, `on_delete_post()`, `on_term_change()`, `maybe_serve_key_file()`

This task is WordPress-integration wiring; it is verified by the manual smoke test in Task 13 (no new unit test — the underlying logic is already covered by Tasks 3, 6, 7).

- [ ] **Step 1: Register the hooks in the constructor**

Replace the `SWPS_IndexNow::__construct()` body with:

```php
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'flush' ) );

		// Serve the verification file as early as possible.
		add_action( 'init', array( $this, 'maybe_serve_key_file' ), 1 );

		// Settings (Task 10) + admin surface (Task 11) + AJAX (Tasks 11-12).
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_' . 'swps_indexnow_generate_key', array( $this, 'ajax_generate_key' ) );
		add_action( 'wp_ajax_' . 'swps_indexnow_resubmit_all', array( $this, 'ajax_resubmit_all' ) );
		add_action( 'wp_ajax_' . 'swps_indexnow_get_log', array( $this, 'ajax_get_log' ) );
		add_action( 'wp_ajax_' . 'swps_indexnow_submit_post', array( $this, 'ajax_submit_post' ) );

		// Lifecycle triggers.
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'wp_trash_post', array( $this, 'on_delete_post' ) );
		add_action( 'created_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_change' ), 10, 3 );
	}
```

> The `register_settings`, `ajax_*` methods are added in Tasks 10–12; PHP only resolves them at call time, so registering the hooks now is safe as long as those tasks land before release. If running `php -l` between tasks, note it does not check method existence.

- [ ] **Step 2: Add the lifecycle + key-file handlers**

Add to `SWPS_IndexNow`:

```php
	/** Fired on every status change: enqueue on publish, submit the dead URL on unpublish. */
	public function on_transition_post_status( string $new_status, string $old_status, $post ): void {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post->ID ) ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			$this->maybe_enqueue_post( $post );
			return;
		}
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->enqueue_removal( (int) $post->ID );
		}
	}

	/** Fired on trash/delete: submit the last known public URL so engines recrawl (404/410). */
	public function on_delete_post( int $post_id ): void {
		$this->enqueue_removal( $post_id );
	}

	/** Submit a taxonomy term's URL when it changes, if its taxonomy is public + eligible. */
	public function on_term_change( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! get_option( self::OPT_ENABLED, 0 ) || ! get_option( self::OPT_AUTO, 1 ) ) {
			return;
		}
		if ( self::should_skip_environment() ) {
			return;
		}
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || empty( $tax->public ) ) {
			return;
		}
		if ( ! SWPS_Sitemap_Manager::is_term_indexable( $term_id, $taxonomy ) ) {
			return;
		}
		$url = get_term_link( $term_id, $taxonomy );
		if ( ! is_wp_error( $url ) ) {
			self::enqueue_url( (string) $url );
		}
	}

	/** Enqueue the stashed public URL for a removed/unpublished post. */
	private function enqueue_removal( int $post_id ): void {
		if ( ! get_option( self::OPT_ENABLED, 0 ) || ! get_option( self::OPT_AUTO, 1 ) ) {
			return;
		}
		if ( self::should_skip_environment() ) {
			return;
		}
		$url = (string) get_post_meta( $post_id, self::META_LAST_URL, true );
		if ( '' !== $url ) {
			self::enqueue_url( $url );
		}
	}

	/** Serve GET /{key}.txt with the raw key body (init, priority 1). */
	public function maybe_serve_key_file(): void {
		$key = (string) get_option( self::OPT_KEY, '' );
		if ( ! self::is_valid_key( $key ) ) {
			return;
		}
		$path = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
			: '';
		if ( ! self::key_file_path_matches( $path, $key ) ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $key );
		exit;
	}
```

- [ ] **Step 3: Lint and run the suite**

Run: `php -l includes/class-indexnow.php && composer test`
Expected: no syntax errors; all tests green (existing tests unaffected — the new hooks aren't fired in unit tests).

- [ ] **Step 4: Commit**

```bash
git add includes/class-indexnow.php
git commit -m "feat(indexnow): lifecycle triggers + /{key}.txt verification file"
```

---

## Task 9: Settings registration (dedicated option group)

**Files:**
- Modify: `includes/class-indexnow.php` (add `register_settings()` + sanitizers)
- Test: `tests/unit/IndexNowSettingsSanitizeTest.php` (create)

**Interfaces:**
- Produces: `register_settings(): void`; `sanitize_checkbox( $v ): int`; `sanitize_post_types( $v ): array`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/IndexNowSettingsSanitizeTest.php`:

```php
<?php
/**
 * Unit tests for IndexNow settings sanitizers (array footgun guard).
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
	}
}

require_once __DIR__ . '/../../includes/class-indexnow.php';

class IndexNowSettingsSanitizeTest extends TestCase {

	public function test_checkbox_sanitizes_to_int(): void {
		$this->assertSame( 1, SWPS_IndexNow::sanitize_checkbox( '1' ) );
		$this->assertSame( 1, SWPS_IndexNow::sanitize_checkbox( 'on' ) );
		$this->assertSame( 0, SWPS_IndexNow::sanitize_checkbox( '' ) );
		$this->assertSame( 0, SWPS_IndexNow::sanitize_checkbox( null ) );
	}

	public function test_post_types_stay_an_array(): void {
		$this->assertSame( array( 'post', 'page' ), SWPS_IndexNow::sanitize_post_types( array( 'post', 'page' ) ) );
	}

	public function test_post_types_sanitizes_keys_and_drops_bad_input(): void {
		$this->assertSame( array( 'mycpt' ), SWPS_IndexNow::sanitize_post_types( array( 'My CPT!' ) ) );
		$this->assertSame( array(), SWPS_IndexNow::sanitize_post_types( 'not-an-array' ) );
		$this->assertSame( array(), SWPS_IndexNow::sanitize_post_types( null ) );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/IndexNowSettingsSanitizeTest.php`
Expected: FAIL — "Call to undefined method SWPS_IndexNow::sanitize_checkbox()".

- [ ] **Step 3: Implement**

Add to `SWPS_IndexNow`:

```php
	/**
	 * Register the three form-persisted options in a DEDICATED option group so a
	 * partial form on the Sitemaps page cannot wipe unrelated swps_* options.
	 * The API key is NOT registered here — it is managed via the generate-key AJAX
	 * action and never rendered in the form. Each option is registered exactly once.
	 */
	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_ENABLED,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ), 'default' => 0 )
		);
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_AUTO,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ), 'default' => 1 )
		);
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_POST_TYPES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_post_types' ),
				'default'           => array( 'post', 'page' ),
			)
		);
	}

	public static function sanitize_checkbox( $value ): int {
		return $value ? 1 : 0;
	}

	public static function sanitize_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_map( 'sanitize_key', $value ) );
	}
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/IndexNowSettingsSanitizeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-indexnow.php tests/unit/IndexNowSettingsSanitizeTest.php
git commit -m "feat(indexnow): dedicated settings group + footgun-safe sanitizers"
```

---

## Task 10: AJAX handlers (generate key, resubmit all, get log)

**Files:**
- Modify: `includes/class-indexnow.php`

**Interfaces:**
- Consumes: `generate_key()`, `submit_urls()`, `get_log()`, `SWPS_Sitemap_Manager::get_indexable_urls()`
- Produces: `ajax_generate_key()`, `ajax_resubmit_all()`, `ajax_get_log()`

WordPress-integration; verified by Task 13 browser smoke test. All handlers use the shared `swps_nonce` (localized as `swpsAdmin.nonce`) and `manage_options`, matching `SWPS_Sitemap_Admin`.

- [ ] **Step 1: Implement the three admin AJAX handlers**

Add to `SWPS_IndexNow`:

```php
	/** AJAX: generate + persist a new IndexNow key (rotation). */
	public function ajax_generate_key(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}
		$key = self::generate_key();
		update_option( self::OPT_KEY, $key, false );
		wp_send_json_success(
			array(
				'key'          => $key,
				'key_file_url' => home_url( '/' . $key . '.txt' ),
			)
		);
	}

	/** AJAX: submit the full indexable URL set ("Resubmit all"). */
	public function ajax_resubmit_all(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}
		if ( self::should_skip_environment() ) {
			wp_send_json_error( array( 'message' => __( 'IndexNow is paused on non-production environments.', 'stratawp-seo' ) ) );
		}
		$urls    = SWPS_Sitemap_Manager::get_indexable_urls();
		$results = $this->submit_urls( $urls, 'bulk' );
		wp_send_json_success(
			array(
				'submitted' => count( $urls ),
				'batches'   => $results,
			)
		);
	}

	/** AJAX: return the activity log for the admin panel. */
	public function ajax_get_log(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}
		wp_send_json_success( array( 'log' => self::get_log() ) );
	}
```

- [ ] **Step 2: Lint**

Run: `php -l includes/class-indexnow.php`
Expected: "No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add includes/class-indexnow.php
git commit -m "feat(indexnow): admin AJAX handlers (generate key, resubmit all, get log)"
```

---

## Task 11: Admin panel on the Sitemaps page

**Files:**
- Create: `templates/indexnow-panel.php`
- Create: `admin/js/indexnow.js`
- Modify: `templates/sitemaps-page.php` (include the panel)
- Modify: `stratawp-seo.php` (enqueue `indexnow.js` on the sitemaps admin page)

WordPress-integration; verified by Task 13 browser smoke test.

- [ ] **Step 1: Create the panel template**

Create `templates/indexnow-panel.php`:

```php
<?php
/**
 * IndexNow panel, rendered inside the Sitemaps admin page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$swps_in_key      = (string) get_option( SWPS_IndexNow::OPT_KEY, '' );
$swps_in_enabled  = (bool) get_option( SWPS_IndexNow::OPT_ENABLED, 0 );
$swps_in_auto     = (bool) get_option( SWPS_IndexNow::OPT_AUTO, 1 );
$swps_in_selected = (array) get_option( SWPS_IndexNow::OPT_POST_TYPES, array( 'post', 'page' ) );
$swps_in_skip_env = SWPS_IndexNow::should_skip_environment();
$swps_in_key_url  = $swps_in_key ? home_url( '/' . $swps_in_key . '.txt' ) : '';
?>
<div class="postbox">
	<div class="inside">
		<h3><?php esc_html_e( 'IndexNow', 'stratawp-seo' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Instantly notify Bing, Yandex, Seznam, and Naver when your content changes. (Google does not participate in IndexNow.)', 'stratawp-seo' ); ?>
		</p>

		<?php if ( $swps_in_skip_env ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'IndexNow is paused: this does not look like a production site, so URLs will not be submitted.', 'stratawp-seo' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( SWPS_IndexNow::SETTINGS_GROUP ); ?>
			<table class="form-table">
				<tr>
					<th><label for="swps_indexnow_enabled"><?php esc_html_e( 'Enable IndexNow', 'stratawp-seo' ); ?></label></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( SWPS_IndexNow::OPT_ENABLED ); ?>" value="0">
						<input type="checkbox" id="swps_indexnow_enabled" name="<?php echo esc_attr( SWPS_IndexNow::OPT_ENABLED ); ?>" value="1" <?php checked( $swps_in_enabled ); ?>>
					</td>
				</tr>
				<tr>
					<th><label for="swps_indexnow_auto"><?php esc_html_e( 'Auto-submit on publish/update', 'stratawp-seo' ); ?></label></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( SWPS_IndexNow::OPT_AUTO ); ?>" value="0">
						<input type="checkbox" id="swps_indexnow_auto" name="<?php echo esc_attr( SWPS_IndexNow::OPT_AUTO ); ?>" value="1" <?php checked( $swps_in_auto ); ?>>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Post types', 'stratawp-seo' ); ?></th>
					<td>
						<fieldset>
							<?php
							foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $swps_pt ) {
								if ( 'attachment' === $swps_pt->name ) {
									continue;
								}
								printf(
									'<label style="display:inline-block;min-width:160px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
									esc_attr( SWPS_IndexNow::OPT_POST_TYPES ),
									esc_attr( $swps_pt->name ),
									checked( in_array( $swps_pt->name, $swps_in_selected, true ), true, false ),
									esc_html( $swps_pt->label )
								);
							}
							?>
						</fieldset>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save IndexNow Settings', 'stratawp-seo' ) ); ?>
		</form>

		<hr>
		<h4><?php esc_html_e( 'API Key', 'stratawp-seo' ); ?></h4>
		<p>
			<code id="swps-indexnow-key"><?php echo esc_html( $swps_in_key ?: __( '(not generated yet)', 'stratawp-seo' ) ); ?></code>
			<button type="button" class="button" id="swps-indexnow-generate"><?php esc_html_e( 'Generate / Rotate Key', 'stratawp-seo' ); ?></button>
		</p>
		<p class="description">
			<?php esc_html_e( 'Verification file:', 'stratawp-seo' ); ?>
			<a id="swps-indexnow-key-url" href="<?php echo esc_url( $swps_in_key_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $swps_in_key_url ); ?></a>
		</p>

		<hr>
		<p>
			<button type="button" class="button button-secondary" id="swps-indexnow-resubmit"><?php esc_html_e( 'Resubmit All URLs', 'stratawp-seo' ); ?></button>
			<span id="swps-indexnow-resubmit-status"></span>
		</p>

		<h4><?php esc_html_e( 'Recent Activity', 'stratawp-seo' ); ?></h4>
		<table class="widefat striped" id="swps-indexnow-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Trigger', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'URLs', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Result', 'stratawp-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td colspan="4"><?php esc_html_e( 'Loading…', 'stratawp-seo' ); ?></td></tr>
			</tbody>
		</table>
	</div>
</div>
```

- [ ] **Step 2: Include the panel from the Sitemaps page**

In `templates/sitemaps-page.php`, inside the dashboard tab `<div id="tab-dashboard" ...>`, after the sitemaps `</table>` (around line 48) and before the closing `</div>` of the tab, add:

```php
		<?php require SWPS_PLUGIN_DIR . 'templates/indexnow-panel.php'; ?>
```

- [ ] **Step 3: Create the JS**

Create `admin/js/indexnow.js`:

```javascript
/* global jQuery, swpsAdmin */
( function ( $ ) {
	'use strict';

	function post( action, extra ) {
		return $.post( swpsAdmin.ajax_url, Object.assign( { action: action, nonce: swpsAdmin.nonce }, extra || {} ) );
	}

	function renderLog( log ) {
		var $body = $( '#swps-indexnow-log tbody' ).empty();
		if ( ! log || ! log.length ) {
			$body.append( '<tr><td colspan="4">No activity yet.</td></tr>' );
			return;
		}
		log.forEach( function ( e ) {
			var when = e.time ? new Date( e.time * 1000 ).toLocaleString() : '';
			$body.append(
				'<tr><td>' + when + '</td><td>' + ( e.trigger || '' ) + '</td><td>' +
				( e.count || 0 ) + '</td><td>' + ( e.result || '' ) + '</td></tr>'
			);
		} );
	}

	function loadLog() {
		post( 'swps_indexnow_get_log' ).done( function ( r ) {
			if ( r && r.success ) {
				renderLog( r.data.log );
			}
		} );
	}

	$( function () {
		if ( ! $( '#swps-indexnow-log' ).length ) {
			return;
		}
		loadLog();

		$( '#swps-indexnow-generate' ).on( 'click', function () {
			var $b = $( this ).prop( 'disabled', true );
			post( 'swps_indexnow_generate_key' ).done( function ( r ) {
				if ( r && r.success ) {
					$( '#swps-indexnow-key' ).text( r.data.key );
					$( '#swps-indexnow-key-url' ).text( r.data.key_file_url ).attr( 'href', r.data.key_file_url );
				} else {
					window.alert( r && r.data ? r.data.message : 'Error' );
				}
			} ).always( function () {
				$b.prop( 'disabled', false );
			} );
		} );

		$( '#swps-indexnow-resubmit' ).on( 'click', function () {
			var $b = $( this ).prop( 'disabled', true );
			$( '#swps-indexnow-resubmit-status' ).text( 'Submitting…' );
			post( 'swps_indexnow_resubmit_all' ).done( function ( r ) {
				$( '#swps-indexnow-resubmit-status' ).text(
					r && r.success ? ( 'Submitted ' + r.data.submitted + ' URLs.' ) : ( r && r.data ? r.data.message : 'Error' )
				);
				loadLog();
			} ).always( function () {
				$b.prop( 'disabled', false );
			} );
		} );
	} );
}( jQuery ) );
```

- [ ] **Step 4: Enqueue the JS on the Sitemaps page**

In `stratawp-seo.php`, next to the existing sitemaps script enqueue (around line 731), add:

```php
		if ( 'stratawp-seo_page_swps-sitemaps' === $hook ) {
			wp_enqueue_script( 'swps-sitemaps', SWPS_PLUGIN_URL . 'admin/js/sitemaps.js', array( 'swps-admin' ), SWPS_VERSION, true );
			wp_enqueue_script( 'swps-indexnow', SWPS_PLUGIN_URL . 'admin/js/indexnow.js', array( 'swps-admin', 'jquery' ), SWPS_VERSION, true );
		}
```

- [ ] **Step 5: Lint the PHP**

Run: `php -l templates/indexnow-panel.php && php -l templates/sitemaps-page.php && php -l stratawp-seo.php`
Expected: no syntax errors.

- [ ] **Step 6: Commit**

```bash
git add templates/indexnow-panel.php templates/sitemaps-page.php admin/js/indexnow.js stratawp-seo.php
git commit -m "feat(indexnow): admin panel on Sitemaps page (settings, key, resubmit, log)"
```

---

## Task 12: Per-post "Submit to IndexNow" button

**Files:**
- Modify: `includes/class-indexnow.php` (add `ajax_submit_post()`)
- Modify: `templates/meta-editor-metabox.php` (add the button)
- Modify: `admin/js/indexnow.js` (wire the button)

WordPress-integration; verified by Task 13 browser smoke test.

- [ ] **Step 1: Add the AJAX handler**

Add to `SWPS_IndexNow`:

```php
	/** AJAX: manually submit a single post's URL now (bypasses auto-submit toggle). */
	public function ajax_submit_post(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'stratawp-seo' ) ) );
		}
		if ( ! get_option( self::OPT_ENABLED, 0 ) ) {
			wp_send_json_error( array( 'message' => __( 'IndexNow is disabled. Enable it under Sitemaps → IndexNow.', 'stratawp-seo' ) ) );
		}
		if ( self::should_skip_environment() ) {
			wp_send_json_error( array( 'message' => __( 'IndexNow is paused on non-production environments.', 'stratawp-seo' ) ) );
		}
		$post = get_post( $post_id );
		if ( ! $post || ! SWPS_Sitemap_Manager::is_post_indexable( $post ) ) {
			wp_send_json_error( array( 'message' => __( 'This URL is not eligible (unpublished, excluded, or noindex).', 'stratawp-seo' ) ) );
		}
		$url     = get_permalink( $post );
		update_post_meta( $post_id, self::META_LAST_URL, $url );
		$results = $this->submit_urls( array( $url ), 'manual' );
		$result  = $results[0]['result'] ?? 'error';
		wp_send_json_success( array( 'result' => $result, 'url' => $url ) );
	}
```

- [ ] **Step 2: Add the button to the meta box**

In `templates/meta-editor-metabox.php`, add near the end of the template (inside the metabox markup). Use the existing `$post` variable that `render_metabox()` passes through:

```php
	<?php if ( get_option( SWPS_IndexNow::OPT_ENABLED, 0 ) && isset( $post ) ) : ?>
		<p class="swps-indexnow-metabox">
			<button type="button" class="button" id="swps-indexnow-submit-post" data-post="<?php echo esc_attr( $post->ID ); ?>">
				<?php esc_html_e( 'Submit to IndexNow now', 'stratawp-seo' ); ?>
			</button>
			<span id="swps-indexnow-submit-post-status"></span>
		</p>
	<?php endif; ?>
```

> The `meta-editor-metabox.php` template runs inside `render_metabox( WP_Post $post )`, so `$post` is in scope (verified in `class-meta-editor.php:80-105`).

- [ ] **Step 3: Ensure the metabox loads the IndexNow JS**

The meta box appears on post-edit screens, where `admin/js/indexnow.js` is not yet enqueued. In `stratawp-seo.php`, in the admin enqueue method, add a post-edit-screen branch (near the sitemaps enqueue):

```php
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_script( 'swps-indexnow', SWPS_PLUGIN_URL . 'admin/js/indexnow.js', array( 'swps-admin', 'jquery' ), SWPS_VERSION, true );
		}
```

- [ ] **Step 4: Wire the button in JS**

In `admin/js/indexnow.js`, inside the `$( function () { ... } )` ready block (after the resubmit handler), add:

```javascript
		$( '#swps-indexnow-submit-post' ).on( 'click', function () {
			var $b = $( this ).prop( 'disabled', true );
			var id = $b.data( 'post' );
			$( '#swps-indexnow-submit-post-status' ).text( 'Submitting…' );
			post( 'swps_indexnow_submit_post', { post_id: id } ).done( function ( r ) {
				$( '#swps-indexnow-submit-post-status' ).text(
					r && r.success ? ( 'Submitted (' + r.data.result + ').' ) : ( r && r.data ? r.data.message : 'Error' )
				);
			} ).always( function () {
				$b.prop( 'disabled', false );
			} );
		} );
```

Also relax the early-return guard at the top of the ready block so the button works on the post editor (where `#swps-indexnow-log` is absent). Replace:

```javascript
		if ( ! $( '#swps-indexnow-log' ).length ) {
			return;
		}
		loadLog();
```

with:

```javascript
		if ( $( '#swps-indexnow-log' ).length ) {
			loadLog();
		}
```

- [ ] **Step 5: Lint**

Run: `php -l includes/class-indexnow.php && php -l templates/meta-editor-metabox.php`
Expected: no syntax errors.

- [ ] **Step 6: Commit**

```bash
git add includes/class-indexnow.php templates/meta-editor-metabox.php admin/js/indexnow.js stratawp-seo.php
git commit -m "feat(indexnow): per-post 'Submit to IndexNow now' button in the SEO meta box"
```

---

## Task 13: Deactivation/uninstall cleanup + manual smoke test

**Files:**
- Modify: `stratawp-seo.php` (`swps_deactivate()` — clear the flush event)
- Modify: `uninstall.php` (add the cron hook to the unschedule list)

**Interfaces:**
- Consumes: `SWPS_IndexNow::CRON_HOOK`

- [ ] **Step 1: Clear the scheduled flush on deactivation**

In `stratawp-seo.php`, inside `swps_deactivate()` (before `flush_rewrite_rules();` at line 1489), add:

```php
	wp_clear_scheduled_hook( SWPS_IndexNow::CRON_HOOK );
```

- [ ] **Step 2: Unschedule the flush hook on uninstall**

In `uninstall.php`, add the flush hook to the `$swps_cron_hooks` array (after `'swps_audit_ability_run',` at line 48):

```php
	'swps_audit_ability_run',
	'swps_indexnow_flush',
```

> Options (`swps_indexnow_*`) and post/term meta (`_swps_indexnow_*`) need no explicit uninstall entries — the existing wildcard deletes (`DELETE FROM options WHERE option_name LIKE 'swps\_%'`, `postmeta LIKE '\_swps\_%'`, `termmeta LIKE '\_swps\_%'`) already remove them when "Remove Data on Uninstall" is enabled.

- [ ] **Step 3: Lint + full suite**

Run: `php -l stratawp-seo.php && php -l uninstall.php && composer test`
Expected: no syntax errors; all unit tests green.

- [ ] **Step 4: Manual smoke test on jonimms.local**

Run these against the `jonimms.local` dev site (LocalWP; the site runs the checked-out branch live). This is the environment guard's expected "paused" state, so verify both the guard AND force a live submission.

1. Confirm the class loads and options exist:
   ```bash
   wp eval 'var_dump( class_exists("SWPS_IndexNow") );'
   ```
   Expected: `bool(true)`.

2. Generate a key and confirm the verification file serves:
   ```bash
   wp option update swps_indexnow_api_key "$(php -r 'echo bin2hex(random_bytes(16));')"
   wp option update swps_indexnow_enabled 1
   KEY=$(wp option get swps_indexnow_api_key)
   curl -sI "http://jonimms.local/${KEY}.txt" | grep -i 'content-type'
   curl -s "http://jonimms.local/${KEY}.txt"
   ```
   Expected: `Content-Type: text/plain`; body is exactly the key.

3. Confirm the environment guard pauses submission on `.local`:
   ```bash
   wp eval 'var_dump( SWPS_IndexNow::should_skip_environment() );'
   ```
   Expected: `bool(true)` (host ends in `.local`).

4. Verify `get_indexable_urls()` returns the sitemap's URL set:
   ```bash
   wp eval '$u = SWPS_Sitemap_Manager::get_indexable_urls(); echo count($u) . " urls\n"; echo implode("\n", array_slice($u,0,5));'
   ```
   Expected: a non-zero count; homepage + real permalinks; nothing excluded/noindex.

5. Force a real submission (bypass the guard) and confirm the log records the IndexNow HTTP response:
   ```bash
   wp eval '
     add_filter("wp_get_environment_type", fn() => "production");
     $in = new SWPS_IndexNow();
     $in->submit_urls( array( home_url("/") ), "manual" );
     print_r( SWPS_IndexNow::get_log()[0] );
   '
   ```
   Expected: a log entry with `result` = `ok` or `pending` (200/202) if the key file is reachable, or `key_not_found` (403) if IndexNow can't fetch `/{key}.txt` from the local host (expected on a non-public `.local` domain — that still proves the round-trip works).

6. Browser check (Claude in Chrome or manual): open **StrataWP SEO → Sitemaps**, confirm the IndexNow panel renders with the pause notice, the key + verification link, the post-type checkboxes, "Resubmit All URLs", and the activity-log table. Open any post editor and confirm the "Submit to IndexNow now" button appears in the SEO meta box.

- [ ] **Step 5: Commit**

```bash
git add stratawp-seo.php uninstall.php
git commit -m "feat(indexnow): deactivation + uninstall cleanup for the flush cron"
```

---

## Task 14: Version bump, docs, and deployment zip

**Files:**
- Modify: `stratawp-seo.php` (header `Version:` + `SWPS_VERSION`)
- Modify: `README.md`, `readme.txt` (changelog + feature entry)
- Build: deployment zip

- [ ] **Step 1: Bump the version to 4.22.0**

In `stratawp-seo.php`, change the header line 6 `* Version: 4.21.4` → `* Version: 4.22.0`, and line 20 `define( 'SWPS_VERSION', '4.21.4' );` → `define( 'SWPS_VERSION', '4.22.0' );`.

- [ ] **Step 2: Update readme.txt**

Bump `Stable tag:` to `4.22.0` and add a changelog entry under `== Changelog ==`:

```
= 4.22.0 =
* New: IndexNow integration — instantly notify Bing, Yandex, Seznam, and Naver when you publish, update, or remove content. Includes an auto-generated verification key served at /{key}.txt, auto-submit on the post/term lifecycle with a 60-second debounce, per-post "Submit now" and bulk "Resubmit all" controls, a dedicated panel on the Sitemaps screen, and a recent-activity log. Submissions are automatically paused on non-production environments. (Google does not participate in IndexNow.)
```

Add a one-line feature bullet to the feature list section of `readme.txt` (match the existing bullet style).

- [ ] **Step 3: Update README.md**

Add an IndexNow entry to the feature list and a `## 4.22.0` (or equivalent) changelog section, matching the document's existing headings and tone.

- [ ] **Step 4: Verify the whole suite + static analysis**

Run: `composer test && composer analyze`
Expected: PHPUnit all green; PHPStan reports no new errors (the plan introduces no untyped globals; if PHPStan flags the new class, address inline or add to the baseline only if consistent with existing project practice).

- [ ] **Step 5: Build the deployment zip**

Build the distribution zip the same way the repo's existing `stratawp-seo.zip` is produced (the repo ignores `*.zip`). If a build script exists, run it; otherwise:

```bash
git archive --format=zip --prefix=stratawp-seo/ -o stratawp-seo.zip HEAD
```

Confirm the zip excludes dev files (`tests/`, `.phpstan/`, `docs/`, `node_modules/`, `vendor/` dev-only) per the existing packaging convention — if the repo has a dedicated build script or `.distignore`, prefer it over `git archive`.

- [ ] **Step 6: Commit**

```bash
git add stratawp-seo.php README.md readme.txt
git commit -m "release: IndexNow integration (4.22.0)"
```

---

## Self-Review

**Spec coverage** (each spec section → task):

| Spec section | Task(s) |
|--------------|---------|
| §1 New class + bootstrap wiring | Task 2 |
| §2 Shared eligibility (is_post_indexable / get_indexable_urls) | Task 1 |
| §3 Key management + /{key}.txt | Tasks 3 (key/path helpers), 8 (serving), 10 (generate AJAX) |
| §4.1 Lifecycle triggers + removal-URL stash | Tasks 6, 8 |
| §4.2 Debounce queue + no-op suppression | Tasks 5, 6 |
| §4.3 Flush handler | Task 7 |
| §5 Submission + response interpretation + manual actions | Tasks 3, 7, 10, 12 |
| §6 Environment guard | Task 3 (logic), enforced in Tasks 7, 8, 10, 12 |
| §7 Settings + admin UI | Tasks 9 (settings), 11 (panel) |
| §8 Activity log | Task 4 |
| §9 Activation/deactivation/uninstall | Task 13 |
| §10 Testing | Tasks 1–9 unit tests; Task 13 smoke test |
| §11 Release | Task 14 |
| §12 Out of scope (Google, DB log, multisite dashboard, diff-sweep) | Not implemented — correct |

**Deviations from spec (deliberate, and why):**
- **Renderer not refactored to call the predicate** (Task 1) — the existing sitemap regression tests pass bare `stdClass` without `post_status`/`post_type`; routing the tested renderer through a `WP_Post` predicate would break them. `is_post_indexable()` encodes identical rules and is annotated to stay in sync. Functional "mirror the sitemap" behavior is preserved.
- **Settings use a dedicated `swps_indexnow_settings` group** rather than `add_field()` into the main `stratawp-seo` group (spec §7.1). Reason: the panel lives on the Sitemaps page; a partial form posting to the shared group would wipe unrelated `swps_*` options. This follows the existing `swps_sitemap_settings` precedent and still registers each option exactly once (footgun-safe).
- **API key is not a settings-form field** — managed via the generate-key AJAX action, so it is never rendered or round-tripped through the form (avoids accidental blanking).
- **Uninstall** adds only the cron hook; options/meta are already wildcard-cleaned (verified in `uninstall.php:74-77,121`).

**Placeholder scan:** none — every code step contains complete code; every test step has real assertions; every command has expected output.

**Type consistency:** option/constant/meta/AJAX-action names match the Naming Reference table throughout; `is_post_indexable()`, `enqueue_url()`, `submit_urls()`, `should_skip_environment()`, `append_log()`/`get_log()`, `maybe_enqueue_post()`, `sanitize_checkbox()`/`sanitize_post_types()` are referenced with identical signatures wherever they appear.
