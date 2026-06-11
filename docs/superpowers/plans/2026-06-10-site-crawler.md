# Site Crawler Implementation Plan (v4.19 batch)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Screaming-Frog-lite crawling the site's own server-rendered front end in background batches: broken internal/external links (4xx/5xx, HEAD→GET fallback + ignore list), redirect chains/loops (swps_redirects rows + live hops), canonical-vs-URL mismatch, noindexed-URLs-in-sitemap, missing/duplicate H1, mixed content. Findings in a per-run issues table surfaced as a read-only audit module + a Site Crawl admin page with one-click fixes (add_redirect / edit post / _swps_sitemap_exclude). On-demand chunked-AJAX crawl + optional weekly re-crawl that diffs runs and flags only new issues.

**Hard limits (Broken Link Checker's hosting reputation is the cautionary tale):** concurrency 1; politeness delay ≥ 500ms between fetches (filterable); default caps 500 internal URLs / 200 external checks per run (settings); same-host scoping + URL normalization (strip fragments, sort query args, cap query-string URLs at 2 per path) against crawl traps; depth cap 5; timeouts 10s; external checks: HEAD then GET-on-403/405/429, one retry, per-host ignore list option. "Rendered" = wp_remote_get HTML only (no JS) — say so in UI copy.

**NO version bump. Commits only on feature/v4.19-batch. COMMIT PER SUB-TASK.**

**Architecture:** `SWPS_Site_Crawler` (queue/state machine: option-backed crawl state + two tables) + `SWPS_Crawl_Issues` (issues table + run diffing + prune) + `SWPS_Site_Crawl_Admin` (page, chunked AJAX per auto-optimize precedent, fix actions) + a thin read-only audit module (registers via swps_audit_modules filter at class-seo-audit.php:78, run() returns the LATEST stored results — never crawls synchronously). Pure TDD: URL normalization, same-host check, link extraction from HTML, issue classification.

## Verified facts (batch @ 4b0fc80)
- `SWPS_Redirect_Manager::add_redirect( string $source, string $target, int $type = 301, bool $is_regex = false ): int|false|WP_Error` (:193); 404-log prune precedent in same class (90d).
- Audit: `SWPS_Audit_Module` abstract `run(): array` (read the full base class for the result contract: status/items shape); modules filtered at class-seo-audit.php:78; `run_all()` is synchronous — the crawl module MUST be read-only.
- Chunked AJAX precedent: `SWPS_Auto_Optimize::ajax_scan_chunk` (:510) + its JS — read both.
- Sitemap seeds: SWPS_Sitemap_Manager — find a method listing included post URLs (or query posts + `_swps_sitemap_exclude` meta directly); `_swps_sitemap_exclude` post meta exists.
- UA convention: 'StrataWP-SEO/<version>' (class-backlinks.php).
- Tables/upgrade/uninstall precedents as all prior features. wp_remote_head/get with `'redirection' => 0` to observe hops manually.

## Pure logic (TDD first — tests/unit/SiteCrawlerTest.php, ~20 cases)
```php
	/** Normalize for the visited-set: absolute URL → scheme-relative host+path+sorted-query, no fragment, no trailing-slash dupes. '' when invalid/unsupported scheme. */
	public static function normalize_url( string $url, string $home_host ): string
	/** Internal? (same registrable host as home, http/https only). */
	public static function is_internal( string $url, string $home_host ): bool
	/** Extract candidate links + assets from HTML: returns array{links: string[], images: string[], canonical: ?string, h1_count: int, has_noindex: bool, mixed: string[]} — anchors href, img/script/link src/href for mixed-content (http:// on https page), link rel=canonical, meta robots noindex, h1 count. Resolves relative URLs against a base. */
	public static function parse_html( string $html, string $base_url ): array
	/** Issue classifier for a fetched URL result → issue rows. */
	public static function classify( array $fetch, array $page, string $home_host ): array
```
DOMDocument with libxml_use_internal_errors for parse_html (malformed HTML tests included).

## Tasks (two dispatches)
### Dispatch 1 (commits a-c)
a. `feat: site crawler pure parsing and normalization` — the four pure statics, TDD.
b. `feat: crawl state machine with politeness caps` — tables `swps_crawl_queue` (run_id, url_hash UNIQUE per run, url, depth, status pending/done, found_on) + `swps_crawl_issues` (run_id, type, url, detail JSON, post_id nullable, first_seen_run); DB-version path; SWPS_Site_Crawler: start_run() seeds from sitemap post URLs (capped), process_chunk( $n = 10 ) pops pending → fetch (politeness usleep, redirection=0, follow hops manually ≤5 recording chains) → parse → enqueue new internal URLs (depth+1, caps) → classify + store issues → external links collected into a per-run set checked at the END of the run (HEAD→GET fallback, cap 200, ignore-list option `swps_crawl_ignore_hosts`); run finishes when queue empty → run summary option + diff vs previous run (new_issue flag via first_seen_run).
c. `feat: weekly re-crawl via background processor` — optional weekly cron (default OFF, setting) scheduling chunk processing through SWPS_Background_Processor single events chained until done (NOT one long request); deactivation/uninstall hygiene.
### Dispatch 2 (commits d-f)
d. `feat: site crawl admin page with one-click fixes` — page under stratawp-seo (auto-optimize template/JS precedent): Start crawl → chunked AJAX progress (crawled/queued/issues counts), issue groups by type (expandable), per-row actions: create redirect (prefilled add_redirect modal/inline), edit post link, exclude from sitemap (meta toggle), ignore host (for external 403-noise); "new since last run" badge from the diff. All AJAX nonce `swps_site_crawl` + manage_options; caps/politeness settings section on the page or settings tab (report choice).
e. `feat: read-only crawl results audit module` — registers via the filter; run() reads the latest run's issue counts (status fail/warn/pass + items linking to the crawl page); never crawls.
f. gates + smoke: phpunit (306+~20), phpstan 2G, phpcs new files; jonimms.local smoke — REAL MINI-CRAWL: start_run with cap 10, process chunks to completion against the live local site (it's local — politeness 100ms OK via filter), report issues found, then clean up run rows. This is the first feature where live smoke exercises the full pipeline.

## Self-review
1. No synchronous crawling in run_all()/page load — chunks only via AJAX/background events?
2. Caps + politeness + same-host scoping + normalization all enforced in process_chunk (not just documented)?
3. External-check failures (403/429 walls) → 'warning' severity not 'broken', after GET fallback + retry?
4. Loopback failure (basic-auth/blocked) → clear error surfaced, crawl aborts gracefully?
5. Tables pruned (keep last 5 runs); uninstall covers tables + cron.
