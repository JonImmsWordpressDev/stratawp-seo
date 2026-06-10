# Content Decay & Freshness Watchdog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A weekly cron compares each post's GSC clicks/impressions/position across rolling 28-day windows, flags posts past a configurable decline threshold (default 20% clicks, minimum-impressions floor, per-post cooldown), classifies the likely cause heuristically (position drop / demand drop / CTR-at-stable-position / staleness), and queues them in a Refresh Queue with one-click AI refresh through the existing AEO propose/apply/undo pipeline (forced diff review — never blind apply). Per-post metric + AEO-score history powers sparklines; a wp_mail alert fires when a top-traffic post decays.

**Architecture:** New `SWPS_Decay_Watchdog` (cron, detection, classification, alert) + `SWPS_Metric_History` (one generic history table: post_id, metric VARCHAR(32), date, value FLOAT; UNIQUE (post_id, metric, date); used for gsc_clicks/gsc_impressions/gsc_position/aeo_score) + `SWPS_Refresh_Queue_Admin` (page cloning the Auto-Optimize queue UI, AJAX propose/apply/undo passthrough with a refresh-specific prompt addition). Pure, TDD'd: decay math + cause classification.

**Branch:** `feature/decay-watchdog` off origin/main (bc6132c, v4.17.0). Version → 4.18.0. House conventions (require-with-call-site, <500 lines, tabs/WPCS, nonce+cap AJAX, null-safe wpdb, gates + jonimms.local smoke, no push/PR by implementers, no Co-Authored-By).

---

## Verified facts (origin/main bc6132c)

- `SWPS_Search_Console::fetch_analytics( $property, $start, $end, $dimensions )` is PRIVATE, rowLimit 100 (:506,516); `get_query_page_rows( $days, $row_limit )` (added in 4.17.0) already does paginated query+page fetches — read it; for decay we need PAGE-dimension rows for two explicit windows: add public `get_page_metrics( string $start, string $end, int $row_limit = 1000 ): array` (dimensions ['page'], startRow pagination, transient 12h keyed by window) returning page → {clicks, impressions, ctr, position}.
- `SWPS_AEO_Optimizer::do_propose(365)/do_apply(472)/do_undo(597)` public; apply goes through wp_update_post (revisions + dateModified update for free). The propose prompt already accepts appended context (coverage + citation precedents append paragraphs before the filter).
- Authority freshness: `SWPS_AEO_Authority_Scorer::is_fresh()` private (modified <12mo or published <24mo) — expose minimally or recompute (it's two timestamps — just recompute in the watchdog; do NOT change the scorer).
- Bot crawl deltas: `swps_bot_hits_daily` (bot_key, post_id, date, hits) — optional classification signal when bot analytics enabled.
- Auto-Optimize queue UI precedent: `SWPS_Auto_Optimize` page (add_submenu_page :48, get_queue :174, build_queue_row :693, templates/auto-optimize-page.php + its JS) — read all before cloning.
- wp_mail precedent: digest's guarded try/catch wp_mail (trait-digest-send.php).
- AEO score meta `_swps_aeo_score`; URL→post mapping caveats as in prior features (url_to_postid, skip non-posts).
- History table precedent: keyword tracker / citation store DDL + DB_VERSION upgrade pattern.

## Pure logic (TDD first — tests/unit/DecayWatchdogTest.php)

```php
	/**
	 * Decay verdict for one post given two 28d windows of GSC metrics.
	 *
	 * @param array $recent  {clicks, impressions, position(avg, lower=better), ctr}
	 * @param array $prior   same shape
	 * @param array $opts    {threshold: 0.2, min_impressions: 200}
	 * @return array{decayed: bool, click_change: float, reason: string}
	 *   reason ∈ position_drop | demand_drop | ctr_drop | unknown — only when decayed.
	 */
	public static function assess( array $recent, array $prior, array $opts = array() ): array
```
Rules: not decayed when prior impressions < min_impressions (sample floor) or prior clicks == 0; decayed when clicks decline ≥ threshold. Cause: position_drop when position worsened ≥ 3 spots; demand_drop when impressions fell ≥ threshold while position stable (<3 worse); ctr_drop when CTR fell ≥ threshold at stable position and stable impressions; else unknown. Plus `staleness_flags( int $published, int $modified, string $html ): array` (not_fresh per the 12/24-month rule, no_current_year mention — reuse a cheap regex, don't call the scorer). ~12 test cases incl. boundary threshold, floor, divide-safety.

## Tasks (two dispatches)

### Dispatch 1: history + detection core
1. `SWPS_Metric_History` (table + DB_VERSION upgrade + activation + uninstall entry + prune >400 days in the cron): `record( int $post_id, string $metric, string $date, float $value )` (upsert), `series( int $post_id, string $metric, int $days )`, `bulk_record` for the cron.
2. Pure helpers TDD on `SWPS_Decay_Watchdog`.
3. Weekly cron `swps_decay_scan` (core 'weekly' schedule — it exists since WP 5.4; schedule/unschedule/deactivation/uninstall wiring per house pattern): GSC-connected guard (clean no-op otherwise); fetch recent (last 28d) + prior (29-56d) page metrics; map to posts; `bulk_record` clicks/impressions/position (recent window, dated today) + current `_swps_aeo_score` snapshot; run `assess` per post; for decayed posts NOT in cooldown (post meta `_swps_decay_flagged` < 28 days ago) write meta `_swps_decay` = {reason, click_change, recent, prior, staleness, flagged_at} + set cooldown meta; clear `_swps_decay` for posts no longer decayed. Alert: if any flagged post is in the site's top-10 by recent clicks → ONE summary wp_mail (digest-style guarded, recipients = digest recipients option fallback admin_email, suppressible via option `swps_decay_email` default on).
4. Settings (small section, registered by the watchdog, into the 'seo' tab… read get_settings_tabs and pick 'analytics' — report): threshold %, min impressions, email toggle.

### Dispatch 2: Refresh Queue UI + release
5. `SWPS_Refresh_Queue_Admin`: submenu "Refresh Queue" under stratawp-seo (read Auto-Optimize's registration/assets and clone the structure): table of flagged posts sorted by traffic-at-risk (prior clicks × click_change), columns: post, reason badge (labeled "heuristic"), clicks then/now, AEO score sparkline (inline SVG from `series(post_id,'aeo_score',180)` + clicks sparkline), staleness chips, actions: Propose refresh → AJAX calling `do_propose` with an appended refresh instruction ("update outdated facts/years cautiously — never invent statistics; add an 'Updated' note; refresh the TL;DR"), then REUSE the AEO review UI flow if feasible (read how aeo-page JS renders proposals; if reuse is >50 lines of glue, deep-link to the AEO page with focus_post instead and note it — apply/undo must ALWAYS go through the existing reviewed-diff flow, never auto-apply), Dismiss (clears `_swps_decay`, sets cooldown).
6. Editor metabox line when `_swps_decay` present (decay reason + flagged date) following the existing metabox-line precedents.
7. Wiring, gates (phpunit/phpstan 2G/phpcs), jonimms.local smoke (instantiate, assess() fixtures, history record/series roundtrip, cron handler no-op without GSC), version 4.18.0 + changelogs:
```
= 4.18.0 =
* New: Content decay watchdog — weekly Search Console scan flags posts losing clicks (28-day windows, configurable threshold), classifies the likely cause, tracks metric + AEO-score history with sparklines, emails you when a top post decays, and queues one-click AI refresh proposals through the reviewed AEO apply/undo pipeline.
```
Commits per task group + plan doc. No push/PR.

## Self-review
1. GSC calls only from cron/explicit actions; unconnected = silent no-op everywhere?
2. Refresh NEVER auto-applies — propose → human-reviewed diff → apply/undo only?
3. Cooldown + floor prevent alert/queue spam; email is one summary, not per-post?
4. History table pruned; all new options/meta swps_-prefixed (uninstall wildcard)?
5. Classification UI always labeled heuristic?
