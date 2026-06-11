# Keyword Cannibalization Detector Implementation Plan (v4.19 batch)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detect queries where 2+ of the site's URLs split impressions in GSC, with exclusions for legitimate multi-ranking (brand/navigational queries, category-vs-post pairs, pagination); show competing URLs side by side with per-URL metrics and a recommended resolution: consolidate (301 the loser into the winner — preview + confirm + undoable), differentiate (meta-editor deep link with focus-keyword prefill), or canonicalize (guidance only). Weekly cron re-detect; dashboard tile suppressed at zero findings.

**Cut (per vetting):** automated internal-link rewriting; the position-volatility signal (keyword_tracking lacks per-URL data); the CTR-below-benchmark signal needs a position-CTR curve — implement a SIMPLE built-in curve (filterable constant array, documented as approximate) and use it as a secondary score only, never a primary trigger.

**NO version bump. COMMIT PER SUB-TASK on feature/v4.19-batch.**

**Architecture:** `SWPS_Cannibalization` (detection from `SWPS_Search_Console::get_query_page_rows()` (:227 — already paginated/cached), pure TDD'd grouping/scoring/exclusion logic, findings table, weekly cron) + `SWPS_Cannibalization_Admin` (page under stratawp-seo, resolution AJAX). Consolidate chain: create 301 via `SWPS_Redirect_Manager::add_redirect` (:193) + optionally draft the loser; store an UNDO payload (redirect id + prior post_status) in the finding row; undo reverses both.

## Pure logic (TDD — tests/unit/CannibalizationTest.php, ~14 cases)
```php
	/** Group rows (query,page,clicks,impressions,position) → candidate findings. */
	public static function find_candidates( array $rows, array $opts = array() ): array
	// opts: min_impressions (default 100 per query), min_pages 2, max_position 30.
	// A candidate: 2+ pages each with ≥20% of the query's impressions (real splits, not 99/1 noise).
	/** Exclusions: brand terms (site name tokens, filterable list), pagination URLs (/page/N), and
	 *  archive-vs-post pairs are DOWNGRADED to 'review' severity not dropped (is_archive_url heuristic: /category|/tag|/author). */
	public static function classify_finding( array $finding, array $brand_tokens ): string // 'cannibal'|'review'|'excluded'
	/** Winner pick: highest clicks, tie → better position. */
	public static function pick_winner( array $pages ): int // index
	/** Expected CTR at position (built-in curve, filterable at the runtime wrapper). */
	public static function expected_ctr( float $position ): float
```

## Tasks (single dispatch, commits a-d)
a. `feat: cannibalization detection core` — pure helpers TDD; findings table `swps_cannibalization` (id, query VARCHAR(255), pages JSON, severity, status open/resolved/dismissed, undo JSON NULL, detected_at, UNIQUE query-hash) + DB-version path + uninstall; weekly cron `swps_cannibal_scan` (GSC guard, get_query_page_rows(90, 5000), find_candidates → classify → upsert by query hash, resolve-vanished: mark resolved when a query no longer triggers; retention: drop dismissed/resolved >90d).
b. `feat: cannibalization admin page` — page under stratawp-seo (house template/JS patterns): findings table grouped by severity, expandable per-finding panel (per-URL clicks/impressions/position + expected-vs-actual CTR note labeled approximate), actions: Consolidate (modal: confirm source→target 301 + optional draft-loser checkbox → AJAX → add_redirect + undo payload stored; button flips to Undo), Differentiate (link to the loser's editor with the meta box focused — `_swps_focus_keyword` prefill via query arg the meta editor reads — check how the meta box renders and add a small prefill hook if absent, report), Canonicalize (copy-the-snippet guidance), Dismiss. All AJAX nonce swps_cannibal + manage_options. Undo: delete redirect row + restore post_status.
c. `feat: cannibalization dashboard tile` — safe_get pattern; suppressed entirely at zero open findings.
d. Gates + live smoke (jonimms.local): pure helpers on fixture rows; cron handler no-op without GSC; findings CRUD roundtrip + undo payload roundtrip with cleanup. NO consolidate against real posts. phpunit (343+), phpstan 2G, phpcs new files.

## Self-review
1. Consolidate is confirm-gated, undoable, and never touches content (no link rewriting)?
2. Brand/pagination/archive exclusions actually downgrade/skip (tested)?
3. GSC-unconnected → everything no-ops; tile suppressed when empty?
4. Findings keyed by query hash — re-scans update, not duplicate?
