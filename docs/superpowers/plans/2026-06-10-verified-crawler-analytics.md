# Verified Crawler Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make bot analytics trustworthy and enforceable: (a) extend tracking to search-engine crawlers in a SEPARATE list (never `KNOWN_BOTS` — `get_blocked_bots()` would emit `Disallow: /` for Googlebot); (b) verify hits against published CIDR ranges (+ cron-only rDNS) and stamp rows verified/spoofed/unverifiable; (c) daily reconciliation: "a bot you disallowed is still crawling" + "spoofed traffic impersonating X" admin notices/email; (d) opt-in per-bot 403 enforcement on a NEW early `template_redirect` handler (the capture filter fires at shutdown — it CANNOT enforce), fail-open and only for verified-spoofed or verified-disallowed; (e) a Crawl Budget report (verified hits per section, crawl waste on 404s/redirects/noindexed, not-crawled-in-90d × sitemap membership) plus a per-bot compliance column on the AI Crawlers tab.

**NO version bump — this is part of the v4.19.0 batch. No PR. Commits only on feature/v4.19-batch.**

**Architecture:** New `SWPS_Crawler_Verification` (CIDR option fetched weekly per provider, pure CIDR/rDNS matchers TDD'd, verdict stamping, reconciliation cron, enforcement handler, settings) + `SWPS_Crawl_Budget_Report` (report queries) + a `SEARCH_BOTS` const on `SWPS_AI_Bots` (kept OUT of KNOWN_BOTS/get_bots/get_blocked_bots — verify nothing else iterates KNOWN_BOTS in robots.txt paths). Tracker gains ip/verified columns (dbDelta + DB-version bump path) with verdict stamped at capture from the cached CIDR option ONLY (no network/rDNS inline); rDNS forward-confirmation runs in cron for 'unverifiable' rows.

---

## Verified facts (batch branch @ 9ce1732)

- Capture: `SWPS_Bot_Analytics_Tracker` records on `shutdown` 999 (:33); sampling option; `swps_bot_analytics_capture` filter (:168) fires at shutdown — CONFIRMED unusable for 403; `swps_bot_analytics_hit` action (:183); `match_bot()` (:189); `insert_hit(bot_key, post_id, uri, status, ua)` (:261); tables `swps_bot_hits` (raw, 7d) / `swps_bot_hits_daily` (hits col, UNIQUE bot_key+post_id+date); aggregation cron exists.
- `SWPS_AI_Bots::KNOWN_BOTS` (:18, 15 AI bots), `get_bots()` filterable (:48), `swps_ai_bots_allowed` option (:87), `get_blocked_bots()` (:108) — read fully; grep every consumer of KNOWN_BOTS/get_bots before adding SEARCH_BOTS to be certain robots.txt output can't pick them up.
- Enforcement precedent: `SWPS_Redirect_Manager` hooks `template_redirect` at priority 1 (:24). Enforcement handler hooks priority 0.
- IP-range sources (fetch weekly into option `swps_crawler_ip_ranges`, per-provider URLs filterable `swps_crawler_ip_sources`): Googlebot https://www.gstatic.com/ipranges/goog.json + https://developers.google.com/static/search/apis/ipranges/googlebot.json; Bingbot https://www.bing.com/toolbox/bingbot.json; OpenAI https://openai.com/gptbot.json (and searchbot/chatgpt-user lists — implementer verifies current URLs via docs/WebSearch); Perplexity https://www.perplexity.com/perplexitybot.json; Applebot via DNS only → rDNS path. Anthropic publishes no stable list → rDNS only. Store fetched_at; stale >14d ⇒ treat matches as before but reconciliation notes staleness.
- Trusted proxy: when behind Cloudflare etc., REMOTE_ADDR is the CDN. Setting `swps_trusted_proxy_header` (none|cf|xff) default none; helper `client_ip( array $server, string $mode ): string` pure + TDD (spoof-resistant: only honor the header when mode set).
- DB upgrade precedent: DB_VERSION consts (citation store / metric history / ai-referrals).
- GDPR: store IP only on rows kept ≤7 days (raw table already prunes at 7d — verify) and note in readme privacy section later (docs task).

## Pure logic (TDD first — tests/unit/CrawlerVerificationTest.php)

```php
	/** True when $ip falls in any CIDR (v4 + v6 support; invalid input false). */
	public static function ip_in_ranges( string $ip, array $cidrs ): bool

	/** Verdict given bot_key, ip, cached ranges map, and rdns-capable flag:
	 *  'verified' | 'spoofed' | 'unverifiable'.
	 *  - provider has ranges + ip matches → verified
	 *  - provider has ranges + no match → spoofed
	 *  - no ranges for provider (or empty ip) → unverifiable (rDNS cron may upgrade later) */
	public static function classify_hit( string $bot_key, string $ip, array $ranges ): string

	/** Forward-confirm rDNS: hostname must end with an allowed suffix AND re-resolve to the ip.
	 *  Pure part: suffix check `rdns_host_allowed( string $host, string $bot_key ): bool`
	 *  (suffix map: googlebot.com/google.com, search.msn.com, applebot.apple.com, openai.com, perplexity.ai, anthropic.com…). */
	public static function rdns_host_allowed( string $host, string $bot_key ): bool

	public static function client_ip( array $server, string $mode ): string
```
~18 cases: v4/v6 CIDR boundaries, /32, invalid ip/cidr, classify branches, suffix anti-spoof (`evilgooglebot.com` fails, `crawl-1-2-3-4.googlebot.com` passes), proxy modes.

## Tasks (two dispatches)

### Dispatch 1: verification core
1. `SEARCH_BOTS` const on SWPS_AI_Bots (googlebot, googlebot_image, bingbot, applebot, yandexbot, duckduckbot + UA tokens) + static `get_search_bots()`. PROVE isolation: list every KNOWN_BOTS consumer and confirm robots.txt/llms.txt outputs unaffected; extend `match_bot()` to also match search bots (tracker-side only).
2. Tracker schema: `ip VARCHAR(45) NOT NULL DEFAULT ''`, `verified VARCHAR(12) NOT NULL DEFAULT ''` on swps_bot_hits; `verified_hits` column on swps_bot_hits_daily + rollup SQL update; DB-version upgrade path. Capture: stamp `client_ip()` + `classify_hit()` from the CACHED option only (zero network at capture).
3. `SWPS_Crawler_Verification`: weekly cron `swps_crawler_ranges_fetch` (guarded wp_remote_get per source, JSON shapes differ — normalize to flat CIDR list per provider; keep previous on fetch failure); rDNS cron `swps_crawler_rdns` (hourly batch ≤50 'unverifiable' rows: `gethostbyaddr` + forward re-resolve + `rdns_host_allowed` → upgrade to verified/spoofed; CRON ONLY); pure helpers above.
4. Settings section (discoverability tab): trusted-proxy mode select, per-bot "Block at server" multi-checkbox (default none), enforcement master toggle (default off).
Commits: `feat: search-bot tracking with CIDR/rDNS crawler verification`.

### Dispatch 2: reconciliation, enforcement, report
5. Daily reconciliation cron `swps_crawler_reconcile`: yesterday's hits vs `swps_ai_bots_allowed` robots.txt intent → transient-backed admin notice (+ guarded wp_mail reusing decay/digest recipient pattern, option-suppressible) for (a) disallowed-but-crawling verified bots, (b) spoofed share >20% of a bot's hits. Smoothing: only after 2 consecutive days.
6. Enforcement: `template_redirect` priority 0 handler — master toggle on AND bot UA matches AND per-bot block enabled AND verdict from CACHED ranges is 'spoofed' (or bot is verified+user-blocked) → `status_header(403); exit`. FAIL-OPEN: unverifiable/no-ranges/proxy-misconfig NEVER blocks. Unit-test the pure decision: `should_block( string $verdict, bool $master, bool $bot_enabled ): bool`.
7. Crawl Budget report: new section on the analytics page (server-rendered like AI Crawlers): verified hits per bot per section (post type), waste % (hits with status 404/30x or on noindexed URLs — join what capture already stores), posts in sitemap not crawled by googlebot in 90d (needs daily-table lookback; show "insufficient data" below 30d of data), compliance column (allowed-vs-observed-vs-verified-share) added to the AI Crawlers table.
8. Gates + jonimms.local smoke (pure helpers on fixtures; classify_hit with fixture ranges; enforcement decision table; cron handlers no-op cleanly with empty options; NO live rDNS/IP fetches in smoke) + commit `feat: crawler reconciliation, opt-in 403 enforcement, crawl budget report`. NO version bump (batch).

## Self-review
1. SEARCH_BOTS provably absent from robots.txt paths?
2. Zero network calls at capture/enforcement time (cached option only); rDNS strictly cron?
3. Fail-open everywhere (missing ranges/proxy/unknown → allow)?
4. IP stored only in the 7d raw table?
5. Notices smoothed (2 consecutive days) and email suppressible?
