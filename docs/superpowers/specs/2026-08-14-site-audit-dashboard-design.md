# Site Audit Dashboard — Design Spec

**Date:** 2026-08-14
**Status:** Approved (design review with Jon, 2026-08-14)

## Motivation

A Semrush Site Audit of jonimms.com (Aug 2026) caught four classes of real defect the
plugin's own audit missed entirely: generic archive titles (Yoast-variable migration bug),
duplicate meta descriptions on paginated archives, unminified first-party assets, and a
host anti-bot system serving challenge pages to crawlers. Every one lived in the gap
between what the plugin audits (WordPress data, from inside PHP) and what search engines
see (rendered HTML, fetched over HTTP like a bot).

The plugin already has the hard infrastructure: `SWPS_Site_Crawler` (sitemap-seeded queue,
link-following, chunked cron processing, redirect tracking) and `SWPS_Crawl_Issues`
(issues table with run IDs, run diffing, 5-run retention). This project closes the gap
with a full crawl-based check suite and a Semrush-style dashboard.

## Goals

- Full Semrush-parity check coverage feasible without JS rendering (~25 checks).
- Crawl everything public — including noindexed archives and pagination.
- A dedicated Site Audit admin screen: health score, error/warning/notice triage,
  per-issue page lists with fix guidance, crawl-over-crawl trends.

## Non-Goals (v1)

- JS rendering, Core Web Vitals field data (PageSpeed module covers it), backlinks,
  AI-search scoring, multisite, emailed reports (Digest can consume the run summary later).

## Architecture (Approach B — check registry + pages table)

Data flow:

```
start_run()        seeds queue: sitemap + term/author archives + pagination
process_chunk()    fetch URL → parse_html() extracts page facts
                   → write row to swps_crawl_pages
                   → run per-page checks from registry → swps_crawl_issues
finish_run()       run aggregate checks (SQL over swps_crawl_pages)
                   → compute health score → stamp run summary
```

The dashboard reads only from the two tables and the run summary — no crawling or heavy
queries at render time.

### New table: `swps_crawl_pages`

One row per crawled URL per run. Pruned with the same 5-run retention as issues, in the
same pruning call. Schema rides the existing `DB_VERSION` / `maybe_upgrade()` path in
`SWPS_Crawl_Issues` (version bump).

| column | purpose |
|---|---|
| `run_id`, `url`, `status_code`, `content_type` | identity + response basics |
| `title`, `title_hash`, `meta_desc_hash`, `desc_length` | head content; hashes make duplicate GROUP BYs cheap |
| `flags` | unsigned INT bitmask (class constants `FLAG_HAS_VIEWPORT = 1`, etc.): has_viewport, has_doctype, has_lang, has_charset, has_noindex, is_challenge_page, is_compressed |
| `word_count`, `html_bytes`, `text_bytes` | content metrics (text-HTML ratio derived) |
| `h1_count`, `internal_links_out`, `canonical` | existing extractions, now persisted |
| `unminified_assets` | JSON array of offending first-party asset URLs |

### Check registry

`includes/crawl-checks/` — one small class per check, shared interface:

- `id(): string`, `severity(): string` (error|warning|notice), `title(): string`,
  `how_to_fix(): string`
- Per-page checks: `check_page( array $page ): ?array` (issue row or null)
- Aggregate checks: `check_run( int $run_id ): array` (issue rows)

`SWPS_Crawl_Check_Registry::all()` returns instances; the crawler iterates per-page
checks in `process_chunk()`, `finish_run()` iterates aggregate checks. Existing
`classify()` checks migrate into registry classes with unchanged issue types so
historical run diffing keeps working. Severity taxonomy gains `notice`.

Per-check exclusion: option-backed toggle set (`swps_crawl_excluded_checks`); excluded
checks are skipped at crawl time and shown collapsed in the dashboard footer.

### Health score

`100 × (1 − weighted_issues / pages_crawled)`, clamped to [0,100]. Weights: error 5,
warning 1, notice 0. Pure function; stored per run in the run summary for trending.

### Run summary

Stored with run state: pages crawled, healthy/with-issues/broken counts, score,
per-check issue counts — everything the overview cards need.

## Check Suite

### Migrated from `classify()` (types unchanged)

broken_link (E), redirect_loop (E), redirect_chain (W), canonical_mismatch (W),
missing_h1 (W), duplicate_h1 (W), mixed_content (W), noindex_in_sitemap (W).

### New per-page checks

| check | severity | notes |
|---|---|---|
| missing_title / empty_title | Error | |
| title_too_long / title_too_short | Notice | >60 / <15 chars |
| missing_viewport | Error | |
| missing_doctype / missing_charset / missing_lang | Warning | |
| missing_meta_description | Warning | **skipped on paginated archives (page 2+)** — omitting it there is this plugin's own by-design behavior (4.26.4) |
| desc_too_long | Notice | >160 chars |
| challenge_page_detected | Error | fingerprint: near-empty HTML with meta-refresh to a challenge path, or bare head missing title+viewport+doctype together; fix text explains host anti-bot systems |
| unminified_assets | Warning | first-party JS/CSS only; heuristic: newline density + average line length over a 20 KB sample; one issue per page listing files |
| uncompressed_page | Warning | no gzip/br when requested |
| low_word_count | Warning | <200 words; content pages only, archives exempt |
| low_text_html_ratio | Notice | <10% |
| image_missing_alt | Warning | per page, listing offending images |
| hreflang_invalid | Warning | only when hreflang is present and malformed/conflicting; absence is not an issue |
| missing_schema | Notice | no JSON-LD on a content page |
| nofollow_internal_link | Notice | |

Noindexed pages are crawled and fully checked. Noindex exempts a page only from
index-oriented checks (missing_meta_description, missing_schema), never head-integrity
checks.

### Aggregate checks (SQL over `swps_crawl_pages` in `finish_run()`)

| check | severity | mechanism |
|---|---|---|
| duplicate_title | Error | `GROUP BY title_hash HAVING COUNT(*)>1`, excluding redirects |
| duplicate_meta_description | Warning | same on desc_hash |
| orphan_page | Notice | in sitemap, zero incoming internal links in crawl |
| single_incoming_link | Notice | exactly one referrer |
| broken_internal_ratio | — | feeds score/summary only, no issue rows |

Every check ships a short "how to fix" string rendered in the drill-down.

## Crawl Scope & Scheduling

Seeding (`get_seed_urls()`):
- All sitemap URLs (posts, pages, CPTs)
- All public taxonomy term archive URLs (`get_terms()`)
- Author archives for users with published posts; blog index; pagination discovered by
  following pagination links (not guessed)
- Date archives only via link-following

Link-following on top of seeds is unchanged. Crawl UA becomes
`StrataWP-SEO-Audit/x.y; +site-url` with a `swps_crawl_user_agent` filter (doubles as a
bot-view reproduction tool). Existing `swps_crawl_internal_cap` and delay options stay
authoritative; default cap 500. Weekly cron default unchanged; dashboard adds
"Run audit now" with live progress from the existing progress snapshot. Interrupted runs
resume via the queue as today.

## Dashboard

New admin-shell screen, slug `swps-site-audit`. The existing site-crawl audit module
becomes a summary card linking here; the settings-level SEO Audit page is untouched.

Layout:
1. **Header** — health score gauge, pages crawled, last run date, "Run audit now".
   During a run: progress bar (AJAX poll of the progress snapshot).
2. **Triage cards** — Errors / Warnings / Notices counts with deltas vs previous run
   (existing run diffing).
3. **Issues list** — grouped by check, severity-then-count sort. Row: title
   ("28 pages don't have title tags"), severity badge, count, delta chip, "How to fix"
   expander. Expanding a row shows the page list inline: URL, discovered date, per-issue
   detail (e.g. offending assets). Paginated at 50; "Copy URLs" button.
   Per-group "Exclude this check" action; excluded checks collapse to a footer strip.
4. **Trend strip** — score + error/warning counts across retained runs (sparklines).

Server-rendered PHP per admin-shell conventions; JS limited to progress polling and
expanders, shipped minified.

## Error Handling

- Fetch failure → broken_link issue + status-only pages row; per-page checks skip it
  (no false "missing title" on a 500).
- challenge_page_detected short-circuits other head checks: one clear error, not eight
  misleading ones.
- Each aggregate check wrapped in try/catch in `finish_run()` — a buggy check logs and
  skips; it can never zero the audit (structural fix for the 4.25.1 regression class).
- Schema migration via `DB_VERSION` bump; pages pruning shares the issues pruning call.

## Testing

Per repo conventions (pure-PHP PHPUnit, no WP loaded):
- One unit test per check class: fact arrays in → issue/null out.
- Aggregate checks: seeded fixture rows; extract pure grouping helpers where possible.
- `parse_html()`: HTML fixtures (real pages + a captured sgcaptcha challenge page) →
  expected fact arrays.
- Score formula: table-driven tests of the pure function.
- WP-glue (seeding, dashboard render, cron): WP-CLI `wp eval-file` smoke tests against
  the local site.
