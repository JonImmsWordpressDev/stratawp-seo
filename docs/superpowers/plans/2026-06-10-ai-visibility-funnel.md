# AI Visibility Funnel (Thin Slice) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Join the plugin's siloed AI datasets in the four places users already look: (1) the bot-analytics gap report gains AEO-score sorting, (2) the AEO queue gains crawl-recency/frequency AND AI-visit columns, (3) the AEO editor panel gains a per-post funnel line ("crawled 2d ago by ClaudeBot · 14 AI visits 30d"), (4) the dashboard gains a site-wide two-stage funnel tile (posts crawled → posts receiving AI visits, 30d, with stage drop-off). The "cited" stage arrives with the citation tracker (Feature 6) — design the tile so a third stage slots in.

**Architecture:** No new tables, no cron. One new small class `SWPS_Visibility_Funnel` holding the batched join queries (crawl stats per post-set from `swps_bot_hits`/`swps_bot_hits_daily`, AI-visit stats per post-set from `swps_analytics`/`swps_ai_referrals_daily`, site-wide funnel counts) so the four surfaces share one query layer. Strictly batched lookups (post_id IN (...)), transient-cached dashboard tile (15 min).

**Branch:** `feature/ai-visibility-funnel` (stacked on feature/ai-referral-attribution). Version → 4.15.0. Conventions as all prior plans.

---

## Integration points (verify each by reading before editing)

- `SWPS_Bot_Analytics_Tracker::get_gap_posts( int $days = 30, int $limit = 25 )` (:562) — read it; add an `$orderby` param or join `_swps_aeo_score` postmeta so the gap report can sort by score desc (high-score-but-never-crawled first). The analytics template's AI Crawlers section renders it — find and update that rendering with the score column + sort control (match how the section currently renders; if it's server-rendered, a simple link toggle `?gap_sort=score` is fine).
- `SWPS_AEO_Optimizer::get_scored_posts()` (:186, private) + `templates/aeo-page.php` queue table — add "Last crawl" (bot + human-time-diff) and "AI visits (30d)" columns fed by ONE batched call each from SWPS_Visibility_Funnel over the page of post ids. Read how the queue paginates first.
- `SWPS_Analytics_Dashboard::ajax_post_stats()` (:228) — read its response shape; the AEO editor panel (includes/class-aeo-editor-panel.php + its JS) shows per-post stats; add funnel fields (last crawl bot/date, ai_visits_30d) via SWPS_Visibility_Funnel and render one line in the panel JS following its existing field pattern.
- Dashboard tile: `safe_get_funnel()` → SWPS_Visibility_Funnel::site_funnel(30): published posts count, posts with ≥1 bot hit (30d), posts with ≥1 AI visit (30d) — two conversion percentages; transient cache 15 min (`swps_funnel_tile`); tile after the AI-referrals tile; render so a "cited" stage can be inserted later (loop over stages array, not hardcoded markup).
- Funnel data sources: crawls = swps_bot_hits (raw, 7d) + swps_bot_hits_daily (hits col) combined like get_bot_summary does (read it); AI visits = swps_analytics raw (ai_source <> '', 7d) + swps_ai_referrals_daily — combined like SWPS_AI_Referrals_Report does (reuse its private patterns; if reuse needs a method made public, prefer adding a public method on the report class over duplicating SQL).

## Tasks
0. Branch check (feature/ai-visibility-funnel), baseline 179 tests.
1. TDD pure helper: `SWPS_Visibility_Funnel::stage_rates( int $published, int $crawled, int $visited ): array` (per-stage counts + pct of previous stage, division-safe, clamped 0-100) + class skeleton. Test file tests/unit/VisibilityFunnelTest.php (~6 cases incl. zero-published, zero-crawled).
2. Query layer: `crawl_stats_for_posts( array $post_ids, int $days )` (last_crawl_at, last_bot, hits), `ai_visits_for_posts( array $post_ids, int $days )`, `site_funnel( int $days )` — batched, prepared, null-safe (`?: array()` on get_results). Commit.
3. The four surface integrations (gap sort, queue columns, editor-panel line, dashboard tile). Commit each surface or together — your call, but keep commits coherent.
4. Wire-up (require_once; instantiate only if it has hooks — it likely needs none, static/instance methods called by the surfaces), gates (phpunit/phpstan 2G/phpcs on new+modified-new-lines), version 4.15.0 + changelogs:
```
= 4.15.0 =
* New: AI visibility funnel — AEO-score sorting on the crawl-gap report, crawl recency and AI-visit columns on the AEO queue, a per-post funnel line in the AEO editor panel, and a dashboard funnel tile (crawled → AI-visited).
```
Commits: `chore: release 4.15.0 — AI visibility funnel` + `docs: implementation plan for AI visibility funnel`. No push/PR.

## Self-review
1. No N+1 anywhere (queue page = 2 batched queries max)?
2. Tile cached; cache invalidation not needed (15-min staleness fine)?
3. Funnel copy avoids causal claims?
4. Stage renderer loop-based for the future cited stage?
