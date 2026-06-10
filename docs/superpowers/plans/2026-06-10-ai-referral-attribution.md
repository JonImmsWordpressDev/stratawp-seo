# AI Referral Attribution (Crawl-to-Click Funnel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Classify visit referrers into AI sources at insert time (the tracker already captures referrers and never reads them), persist per-source daily rollups, and report: AI-referred views by engine over time, landing posts, engagement vs organic (low-sample suppressed), a per-post crawl→visit funnel joining `swps_bot_hits_daily`, a dashboard KPI tile, and a crawled-but-no-AI-visits list.

**Architecture:** New class `SWPS_AI_Referrals` owns the classifier (pure static core for TDD + filterable domain map), the bot_key→ai_source map, the daily-rollup SQL (called from the tracker's existing aggregation cron), and all reporting queries. `SWPS_Analytics_Tracker` gets two surgical touches: an `ai_source VARCHAR(32)` column (dbDelta handles column adds on activation; also add a one-time `maybe_upgrade_schema` for existing installs) and one classify call in `ajax_track()`. The new rollup table stores SUMS (total_time, total_scroll) not averages — averages computed at read time — deliberately avoiding the daily table's `(avg+new)/2` compounding bug. UI: new "AI Referrals" tab in the analytics dashboard + dashboard KPI tile.

**Branch:** `feature/ai-referral-attribution`, stacked on `feature/onboarding-wizard`. Version → 4.14.0. PR base: previous branch (or main if its PR merged).

**Conventions:** as previous plans (no autoloader, <500 lines, tabs/WPCS, nonce+cap on new AJAX, no Co-Authored-By, version bump + changelogs last, never push/PR — controller does).

---

## Verified facts (HEAD 21a339c)

- Raw table `swps_analytics` (class-analytics-tracker.php:43): id, post_id, page_url, referrer VARCHAR(500), time_on_page, scroll_depth, is_bounce, created_at; `ajax_track()` (:138) inserts with `'referrer' => esc_url_raw( $_POST['referrer'] ?? '' )`.
- Daily table `swps_analytics_daily` UNIQUE (post_id, date) — DO NOT TOUCH its key. Its ON DUPLICATE averaging `(avg + VALUES(avg)) / 2` is a known bug — do not replicate.
- `aggregate_and_prune()` (:195): rolls raw → daily for rows `created_at < -7 days`, then DELETES those raw rows, then prunes daily past `swps_analytics_retention` (90d). The AI rollup MUST run inside this method BEFORE the raw delete.
- Tracker JS nonce action `swps_track`; tracker disabled for admins by default.
- Bot rollup `swps_bot_hits_daily` UNIQUE (bot_key, post_id, date) with a `hits` count (verify the count column name by reading the CREATE TABLE at class-bot-analytics-tracker.php:63-73). Bot keys from `SWPS_AI_Bots::get_bots()` (class-ai-bots.php:19+) — read the full list; relevant: gptbot, oai_searchbot?, chatgpt_user?, claudebot, claude_web?, anthropic_ai?, perplexitybot, perplexity_user?, google_extended, copilot? (use EXACT keys found).
- Analytics dashboard AJAX pattern: `wp_ajax_swps_analytics_overview/top_pages/top_queries/post_stats` in class-analytics-dashboard.php (:25-28); read one handler fully (nonce action, response shape) + templates/analytics-page.php tab markup + admin/js JS file driving it before adding the tab.
- Dashboard tiles: `safe_get_*` in class-dashboard.php + `$data[...]` in templates/dashboard-page.php.
- `SWPS_Hooks` has a `filter_analytics_track`/`swps_analytics_track` filter applied in the insert path (verify name/signature at class-hooks.php:~263) — classification happens directly in ajax_track, not via the filter (simpler, always-on), but keep the filter untouched.
- Activation table creation: `SWPS_Analytics_Tracker::create_tables()` is static, called from activation (verify where).

---

### Task 0: Branch check + baseline
`git branch --show-current` → feature/ai-referral-attribution; `vendor/bin/phpunit` → 131.

### Task 1: Classifier (TDD)
Create `includes/class-ai-referrals.php` + `tests/unit/AiReferralsTest.php`. Pure static core:

```php
	/**
	 * Default referrer-host → AI source map. Subdomains match via suffix.
	 *
	 * @var array<string, string>
	 */
	private const SOURCE_MAP = array(
		'chatgpt.com'           => 'chatgpt',
		'chat.openai.com'       => 'chatgpt',
		'perplexity.ai'         => 'perplexity',
		'claude.ai'             => 'claude',
		'gemini.google.com'     => 'gemini',
		'copilot.microsoft.com' => 'copilot',
		'you.com'               => 'you',
		'chat.deepseek.com'     => 'deepseek',
		'grok.com'              => 'grok',
		'meta.ai'               => 'meta',
	);

	/**
	 * Classify a visit into an AI source.
	 *
	 * @param string $referrer Full referrer URL ('' when none).
	 * @param string $page_url Landing URL (utm_source fallback).
	 * @param array<string, string>|null $map Override map (null = default).
	 * @return string Source key or '' when not AI-referred.
	 */
	public static function classify( string $referrer, string $page_url = '', ?array $map = null ): string
```

Rules (write tests FIRST): host match exact or `.host` suffix (www.chatgpt.com → chatgpt; notchatgpt.com → ''); case-insensitive; invalid/empty referrer → check page_url `utm_source` (chatgpt.com→chatgpt, perplexity.ai→perplexity, plus bare values matching a map host or source value); neither → ''. Runtime wrapper `classify_visit()` applies `apply_filters( 'swps_ai_referral_sources', self::SOURCE_MAP )` then calls classify (filter only in the wrapper so the core stays WP-free).
Also pure + tested: `bot_source_map()` returning bot_key→ai_source for the EXACT keys in SWPS_AI_Bots (gptbot→chatgpt etc.; google_extended→gemini; bots with no visit-side counterpart omitted), and `engagement_comparison( array $ai, array $organic, int $min_n = 30 ): ?array` returning null when either sample < min_n else ratio metrics (time, scroll, bounce) — pure math, tested.
Commit: `feat: AI referral classifier and maps`

### Task 2: Capture + rollup
- Tracker schema: add `ai_source VARCHAR(32) NOT NULL DEFAULT ''` + `KEY idx_ai_source (ai_source)` to the raw CREATE TABLE (dbDelta adds columns/keys); ALSO call `create_tables()` (or a slim `maybe_upgrade()`) from an `upgrader_process_complete`/version-check path — find how the plugin handles schema upgrades on plugin update (`grep -n "dbDelta\|db_version\|maybe_upgrade" stratawp-seo.php includes/*.php | head`); follow precedent; if none exists, add a `swps_db_version` option check on admin_init that re-runs create_tables once.
- New rollup table (in SWPS_AI_Referrals::create_table, called alongside the tracker's): `swps_ai_referrals_daily`: id, ai_source VARCHAR(32), post_id, date, views INT, total_time INT UNSIGNED, total_scroll INT UNSIGNED, bounces INT, UNIQUE KEY idx_source_post_date (ai_source, post_id, date). SUMS not averages.
- ajax_track: `$ai_source = SWPS_AI_Referrals::classify_visit( $referrer, $page_url );` added to the insert columns.
- Rollup inside aggregate_and_prune BEFORE the raw delete (delegate: `SWPS_AI_Referrals::aggregate( $cutoff )`): INSERT…SELECT from raw WHERE ai_source <> '' AND created_at < cutoff GROUP BY ai_source, post_id, DATE; ON DUPLICATE: views=views+VALUES, total_time=total_time+VALUES, total_scroll=total_scroll+VALUES, bounces=bounces+VALUES (additive — correct, unlike the daily table). Prune with the same retention option.
- Uninstall: add the new table to uninstall.php's table list (read its pattern).
Commit: `feat: capture and roll up AI referral sources`

### Task 3: Reporting queries + AJAX + UI
In SWPS_AI_Referrals (reporting methods combine raw last-7-days + rollup, mirroring how the tracker's existing readers combine raw+daily — read get_post_views/:254 area first):
- `get_summary( int $days )`: views by ai_source by date (chartable), totals + previous-period delta.
- `get_landing_posts( int $days, int $limit = 10 )`: per post: views, avg time (total_time/views), avg scroll, bounce rate, ai_sources.
- `get_engagement_vs_organic( int $days )`: AI aggregate vs non-AI aggregate from the same tables → engagement_comparison() (null ⇒ UI shows "not enough data yet").
- `get_funnel( int $days, int $limit = 20 )`: join bot crawls to visits per post: from swps_bot_hits_daily sum hits per (post_id, mapped ai_source) via bot_source_map(), LEFT JOIN rollup sums; output rows: post, source, crawls, visits — plus `crawled_no_visits` list (crawls>0, visits=0, ordered by crawls desc). Batched single queries, no N+1.
- AJAX `wp_ajax_swps_analytics_ai_referrals` in class-analytics-dashboard.php following its siblings exactly (same nonce/cap pattern), delegating to these methods. Wording everywhere: views/visits, never sessions; the funnel labeled "correlation, not causation" in the UI copy.
- templates/analytics-page.php + its JS: new "AI Referrals" tab matching the existing tabs (read the markup/JS pattern first): summary chart or table, landing posts table, engagement panel (or the low-sample message), funnel table + crawled-but-no-visits list with edit links.
- Dashboard tile: `safe_get_ai_referrals()` (30d views + prev-period delta from get_summary) + tile after the autopilot tile in dashboard-page.php (trend arrow pattern like posts_30d).
Commit: `feat: AI referrals analytics tab, funnel, and dashboard tile`

### Task 4: Wiring + release
require_once class-ai-referrals.php; instantiate if it has hooks (create_table call wired wherever tracker's create_tables runs — activation + upgrade path); phpunit/phpstan(2G)/phpcs gates; version 4.14.0 + changelogs:
```
= 4.14.0 =
* New: AI referral attribution — visits from ChatGPT, Perplexity, Claude, Gemini, Copilot and others are classified at capture, with an AI Referrals analytics tab (engine trends, landing posts, engagement vs organic), a per-post crawl-to-visit funnel joined to AI-bot crawl data, and a dashboard KPI tile.
```
Commits: `chore: release 4.14.0 — AI referral attribution` + `docs: implementation plan for AI referral attribution`. No push/PR.

## Self-review
1. Raw insert always sets ai_source (default '' for non-AI)?
2. Rollup additive sums only; runs BEFORE raw delete; pruned by retention?
3. Existing installs get the new column + table without re-activation?
4. Low-sample suppression actually reachable (n<30 → message, not bogus ratios)?
5. No N+1 in funnel/landing queries?
6. Copy: views/visits; funnel labeled correlation.
