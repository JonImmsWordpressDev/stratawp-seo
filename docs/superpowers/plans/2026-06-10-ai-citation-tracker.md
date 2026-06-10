# AI Citation Tracker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** BYO-key share-of-voice monitoring: user-managed tracked prompts are run on a schedule through the user's own AI providers in search-grounded mode; responses are parsed for citations of the site's domain vs competitor domains; results power a Citations section on the Keywords page (per-prompt per-engine cited status with smoothing, share-of-voice vs competitors, cited-domains breakdown), a dashboard tile, and lost-citation context injected into AEO proposals.

**Architecture:** (a) New `search_grounded( string $query, int $max_tokens = 1024 ): array|WP_Error` on `SWPS_AI_Provider` (default: `WP_Error('swps_search_unsupported')`), overridden per provider, returning `array{ text: string, citations: string[] }` (citation URLs). (b) New `SWPS_Citation_Tracker` (table, prompt management, cron with monthly call cap, smoothing, domain matching) + pure static helpers TDD'd. (c) UI on the Keywords page + dashboard tile + a `swps_aeo_proposal` filter callback that appends lost-citation context. Checks run via chunked AJAX (one prompt×engine per request) for manual runs and a capped loop for cron.

**Branch:** `feature/ai-citation-tracker` (stacked on feature/ai-visibility-funnel). Version → 4.16.0. Conventions as all prior plans (no autoloader; require in same commit as first call site; <500 lines/file — SPLIT expected: class-citation-tracker.php + class-citation-admin.php; tabs/WPCS/docblocks; null-safe get_results; nonce+manage_options on all AJAX; gates before release commit; live smoke test on jonimms.local via the Local WP-CLI pipe; no push/PR by implementers).

---

## Verified integration facts (HEAD 64a951a)

- `SWPS_AI_Provider` abstract: `chat()`, `chat_json()`, `test_key()`, `get_available_models()`, `get_slug()`, `get_api_key_option()`, providers read keys from options at call time; `get_validated_model()` exists per provider.
- Keyword tracker: table `swps_keyword_tracking` (keyword, position, date UNIQUE), `get_tracked_keywords( int $limit = 100 )` (:159), tracked list option — read `track_keyword` (:86) for where the list lives; cron precedent `schedule_cron` with frequency option.
- Competitors: option `swps_competitors` (`SWPS_Competitors::OPTION_LIST`) — read its stored shape (URLs? domains?) before matching.
- GSC question queries: `SWPS_Search_Console::get_search_data( int $days )` (:150) returns query rows — read the shape; filter question-form queries (regex: ^(who|what|how|why|when|where|which|can|does|do|is|are|should)\b or length ≥ 5 words).
- AEO proposal filter: `apply_filters( 'swps_aeo_proposal', $proposal, $post_id )` at class-aeo-optimizer.php:407 — read the $proposal array shape before injecting.
- Dashboard tile + settings-section + chunked-AJAX patterns: as in all prior features (visibility funnel / digest / auto-optimize).
- **Provider API shapes — do NOT guess; fetch current docs** (context7/WebSearch; for Anthropic consult the claude-api skill if available):
  - Anthropic: POST /v1/messages with `tools: [{"type":"web_search_20250305","name":"web_search","max_uses":3}]`; citations appear in content blocks (`web_search_tool_result` blocks and `citations` on text blocks with `url`). Header `anthropic-version: 2023-06-01` (current provider class shows the conventions).
  - OpenAI: the existing wrapper uses chat/completions; web search needs POST /v1/responses with `tools: [{"type":"web_search"}]`; citations in output annotations (`url_citation` with `url`).
  - Google: generateContent with `tools: [{"google_search": {}}]`; citations from `groundingMetadata.groundingChunks[].web` — `uri` is often a vertexaisearch redirect; ALSO capture `web.title` (usually the bare domain) and prefer title-as-domain when uri is a redirect host.
  - xAI: chat completions with `search_parameters: {"mode":"auto"}`; response carries a `citations` array of URLs.
  - Each method: use the provider's validated model; on a 4xx whose message indicates tool/search unsupported → `WP_Error('swps_search_unsupported', ..., array('status'=>$code))`; reuse each provider's existing error/status-data conventions (`swps_api_error` + `array('status'=>...)`).

## Pure helpers (TDD first — tests/unit/CitationTrackerTest.php)

```php
	/** Extract registrable hosts from citation URLs (lowercased, www-stripped); invalid skipped; deduped. */
	public static function extract_domains( array $urls ): array

	/** True when $domain (or a subdomain of it) appears in $hosts list. */
	public static function domain_cited( string $domain, array $hosts ): bool

	/** Smoothed state from newest-first cited history (bools):
	 *  'cited' (latest 2 both true, or single true), 'lost' (≥1 true in history AND latest 2 both false),
	 *  'never' (no true ever), 'mixed' otherwise. Empty history → 'never'. */
	public static function citation_state( array $history ): string

	/** Share of voice: prompts-cited counts per domain → pct of prompts where each domain cited; division-safe. */
	public static function share_of_voice( array $per_domain_counts, int $total_prompts ): array

	/** Question-form classifier for GSC query seeding. */
	public static function is_question_query( string $query ): bool
```

~16 test cases across these (subdomain matching, redirect-host exclusion is NOT here — extract_domains keeps hosts verbatim; vertexaisearch handling lives in the Google provider).

## Storage & behavior

- Table `swps_citation_checks`: id BIGINT PK, prompt_hash CHAR(32) (md5 of prompt — used in UNIQUE), prompt VARCHAR(500), engine VARCHAR(16), check_date DATE, cited TINYINT(1), our_domains TEXT (JSON, which of our aliases matched), cited_domains TEXT (JSON all extracted domains), UNIQUE KEY idx_prompt_engine_date (prompt_hash, engine, check_date), KEY idx_engine_date (engine, check_date). DB_VERSION upgrade path per the SWPS_Backlinks/AI-referrals precedent. Retention prune: 180 days, in the check cron.
- Prompts: option `swps_citation_prompts` = array of `{ prompt: string, post_id: int|0, added: timestamp }`, cap 25 (filterable). Seed action builds candidates from tracked keywords (as-is) + GSC question queries (top by impressions, is_question_query) — user picks via checkboxes; nothing auto-added.
- Engines: providers with a saved API key AND search support; option `swps_citation_engines` (multi-checkbox, default = current provider only).
- Cron `swps_citation_check`, frequency option (weekly default / daily), monthly call cap option `swps_citation_monthly_cap` (default 200) enforced against counter option `swps_citation_calls_YYYY_MM` incremented per search_grounded call (cap check BEFORE each call; stop the run when reached). Cron loops prompts×engines with the cap guard; each result row INSERT … ON DUPLICATE KEY UPDATE (re-running same day overwrites).
- Domain matching: `home_url()` host + `apply_filters('swps_citation_our_domains', array(...))`; competitor domains parsed from `swps_competitors` entries.
- Lost-citation → AEO: when a prompt with post_id>0 transitions to 'lost', store post meta `_swps_citation_loss` = { prompt, engines, top_cited_domains, time }; SWPS_Citation_Tracker hooks `swps_aeo_proposal` (filter, 2 args) appending a context line to the proposal prompt/payload when that meta exists (read the proposal shape first; append conservatively, e.g. to an existing context/notes field — report what you did). Clear the meta when state returns to 'cited'.

## UI

- Keywords page (templates/keywords-page.php + its JS — read both first): new "AI Citations" card below the keywords table: prompts table (prompt, per-engine state badges cited/lost/never/mixed via citation_state over last 3 checks, last check date), add-prompt input + seed-from-keywords/GSC modal (or simple checkbox list), remove buttons, "Run checks now" button → chunked AJAX (one prompt×engine per call, progress bar, cap-aware error), share-of-voice bars (us vs competitors over prompts checked in last 30d), cited-domains breakdown (top 10 domains AI cites for your prompts, with counts). All new AJAX: nonce `swps_citations` + manage_options.
- Settings: frequency/cap/engines fields in a small section registered by the tracker (page 'stratawp-seo'), added to the 'discoverability' (AI Crawlers) tab's sections in get_settings_tabs().
- Dashboard tile: "AI citations" — X of Y prompts cited (30d) with delta vs prior period; reuse safe_get pattern.
- Cost note in UI copy: searches bill per-search surcharges on some providers; estimates only.

## Task split (three dispatches)
1. Providers: search_grounded base + 4 overrides + provider-level parsing (citations[] of URLs; Google title fallback); pure-helper TDD (extract_domains etc. live on SWPS_Citation_Tracker but are WP-free). Commit(s).
2. Tracker core: table+upgrade, prompts CRUD, engines, cron+cap+counter, check_one(prompt, engine) runner, state queries (per-prompt histories, share-of-voice aggregates, cited-domains agg), AEO hook + loss meta, uninstall additions. Commit.
3. UI (keywords page card + JS + chunked runner), settings section + tab line, dashboard tile, wiring, gates, live smoke on jonimms.local (wp eval: run check_one with a cheap prompt IF a key is configured — otherwise instantiation smoke only), version 4.16.0 + changelogs, plan-doc commit. Changelog:
```
= 4.16.0 =
* New: AI citation tracker — monitor whether ChatGPT, Claude, Gemini, and Grok cite your site for your tracked prompts (BYO keys, search-grounded), with share-of-voice vs competitors, cited-domains breakdown, monthly call caps, and lost-citation context fed into AEO proposals.
```

## Self-review
1. Cap enforced BEFORE every provider call; counter increments even on failed calls (they may still bill)?
2. No provider call from page loads — only cron + explicit AJAX runs?
3. search_grounded never used for content generation paths?
4. Engines without keys excluded everywhere; unsupported gracefully labeled in UI?
5. Both new classes <500 lines; everything required in stratawp-seo.php in the same commit as first call site?
6. Smoothing prevents single-flap alarms (citation_state needs 2 consecutive misses for 'lost')?
