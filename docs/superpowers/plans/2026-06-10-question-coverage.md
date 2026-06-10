# Question Coverage Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two question sources feeding the existing AEO fix pipeline. **Phase A (no GSC dependency):** activate the fully-built-but-never-invoked Coverage scorer with a rewritten fan-out prompt that emits a sub-query checklist (answered/partial/missing), persist the payload with the existing content-hash invalidation, surface the checklist in the AEO editor panel, and inject missing sub-queries into `do_propose()` (which already supports `kind:"qa"` inserts + validated FAQPage schema). **Phase B (GSC):** mine question-form queries per ranked URL, flag questions the post doesn't answer (matched against heading parsing), order by impressions, surface per-post unanswered lists, and push site-wide orphan question clusters into the topic queue as 'proposed'-style candidates (simple create_topic drafts marked clearly).

**Branch:** `feature/question-coverage` (stacked on feature/ai-citation-tracker). Version → 4.17.0. House conventions as all prior plans (require in same commit as call site, <500 lines, tabs/WPCS, nonce+cap AJAX, null-safe wpdb, gates + jonimms.local smoke before release commit, no push/PR).

---

## Verified facts (HEAD = citation tracker tip)

- `SWPS_AEO_Coverage_Scorer::score( string $title, string $html )` (includes/aeo/class-coverage-scorer.php:39) returns `{score:?int, coverage_gaps:string[], entity_issues:string[], error:?string}` via one `chat_json()` call. **Nothing calls score()**; `SWPS_AEO_Scorer::OPTION_COVERAGE_ENABLED = 'swps_aeo_coverage_enabled'` exists (class-aeo-scorer.php:33) and is **never read**; `META_CONTENT_HASH = '_swps_aeo_content_hash'` (:29) backs cache invalidation. Read class-aeo-scorer.php fully: how score_post composes dimensions/weights and where coverage was meant to slot (weights option includes a 'coverage' key, settings UI has the checkbox + weight already).
- `SWPS_AEO_Optimizer::do_scan_chunk(:276)`, `do_score(:337)`, `do_propose(:363)` — the propose prompt (:379) already specifies `"inserts":[{"kind":"qa|tldr|list|defn",...}]`. Read do_propose fully (prompt assembly, where coverage context could append, the `swps_aeo_proposal` filter at :407 — note the citation tracker already appends keys there; coexist cleanly).
- Markup scorer question parsing: `count_questions( $html )` and Q&A pairing detection (includes/aeo/class-markup-scorer.php:72 area) — reuse its heading/question extraction for Phase B matching (make a method public or extract a small shared helper — read first, report approach).
- GSC: `SWPS_Search_Console::get_search_data( $days )['queries']` rows `{keys:[query], clicks, impressions, ctr, position}`; `get_page_queries( $url, $days )` (:180, rowLimit 10); private `fetch_analytics( $property, $start, $end, $dimensions )` (:435). Phase B needs a page+query two-dimension fetch with startRow pagination — add a public method generalizing fetch_analytics (read auth/caching first; 12h transient pattern).
- Question classifier: reuse `SWPS_Citation_Tracker::is_question_query()` (already TDD'd) — do not duplicate.
- Topic queue: `SWPS_Topic_Queue::create_topic` (:81 area — read signature).
- Editor panel: includes/class-aeo-editor-panel.php server-rendered metabox + aeo-editor-panel.js Gutenberg sidebar; funnel line + citation loss line precedents exist in the metabox.
- AEO weights option `swps_aeo_weights` default includes coverage 0.20 (class-settings.php:1067 area).

## Phase A tasks (dispatch 1)

1. **Rewrite the coverage prompt** in class-coverage-scorer.php: ask for `{"score": 0-100, "sub_queries": [{"q": "...", "status": "answered|partial|missing"}], "entity_issues": [...]}` — 5-10 sub-queries an answer engine would fan a query about this topic into, statuses judged from the outline; keep return-shape BC by still returning coverage_gaps (= missing sub-queries' q strings) alongside a new `sub_queries` key. Seed the prompt with title + focus keyword (read how scorers get context) + heading outline (cheap: strip to h2/h3 text) instead of full HTML where the current prompt sends HTML — read what it sends now and keep token use comparable.
2. **Activate it**: in SWPS_AEO_Scorer::score_post / score_html (read which composes dimensions), when `get_option(OPTION_COVERAGE_ENABLED)` is truthy AND a cached coverage payload is invalid (content hash changed), call the coverage scorer; persist payload in NEW post meta `_swps_aeo_coverage` (sub_queries + score + hash) reusing META_CONTENT_HASH semantics; coverage sub-score feeds the existing weights composition (the dash in the UI should become a number). MUST be cost-safe: coverage runs ONLY inside do_scan_chunk/do_score explicit actions (where chat_json already happens) — never on page load or save_post. Verify by tracing call sites of score_post.
3. **Editor panel checklist**: metabox section listing sub-queries with status icons (✓/◐/✗), from the meta (no AI call on render).
4. **Propose injection**: in do_propose, when `_swps_aeo_coverage` has missing/partial sub-queries, append a compact instruction to the prompt: answer these questions via qa inserts (cap 3 per proposal). Unit-testable pure bit: `SWPS_Question_Coverage::format_gap_instruction( array $sub_queries, int $cap = 3 ): string` (new small class) — TDD it (missing first, then partial, empty → '', cap respected).

## Phase B tasks (dispatch 2)

5. **GSC page+query fetch**: public method on SWPS_Search_Console: `get_query_page_rows( int $days, int $row_limit = 1000 )` — dimensions ['query','page'], startRow pagination loop (rowLimit 25000 max per request; page until < requested), 12h transient, auth-guarded (return array() unconnected).
6. **Demand miner** in the new `SWPS_Question_Coverage` class: weekly cron piggyback — extend the EXISTING twicedaily/GSC-adjacent cron if one fits or its own weekly hook (read what crons exist; report choice): pull rows, filter is_question_query, map page URL→post_id (url_to_postid with fallback skip), group per post ordered by impressions, store per-post meta `_swps_unanswered_questions` = questions NOT matched against the post's headings/questions (reuse markup scorer extraction; fuzzy match: normalized stopword-stripped token overlap ≥ 0.6 — implement as pure static `questions_unanswered( array $questions, array $headings ): array` + `tokens_similar( string $a, string $b ): float`, TDD both). Cap stored list at 10 per post. Site-wide: questions whose page is NOT a post (or impressions on posts ranking >20) → candidate topics list option `swps_question_topic_candidates` (cap 20).
7. **Surfaces**: editor-panel metabox section "Questions searchers ask that this post doesn't answer" (from meta, with impressions); do_propose ALSO appends top-3 unanswered GSC questions to the qa instruction (same formatter, GSC source labeled); AEO page: small "Question opportunities" card listing top candidate topics with a "Queue topic" button per row → AJAX (nonce swps_question_coverage + manage_options) calling SWPS_Topic_Queue::create_topic with a clear title; remove from candidates on queue.
8. **Wiring/gates/release**: requires + instantiate; uninstall additions (option + meta note — meta deletion follows existing uninstall meta pattern if one exists, else leave with comment); gates (phpunit 217+new, phpstan 2G, phpcs new files); jonimms.local smoke (instantiate, formatter + matcher on fixtures, NO AI/GSC calls); version 4.17.0 + changelogs:
```
= 4.17.0 =
* New: Question coverage engine — the AEO Coverage dimension is now live (query fan-out sub-question checklist per post), and Search Console question mining surfaces real searcher questions your posts don't answer, feeding Q&A inserts into AEO proposals and topic suggestions into the queue.
```
Commits per dispatch; plan-doc commit at the end. No push/PR.

## Self-review
1. Coverage scorer still only fires inside explicit scan/score actions (cost safety)?
2. Cached payload invalidated by content hash; UI never triggers AI calls on render?
3. Formatter/matcher pure helpers TDD'd; is_question_query reused not duplicated?
4. GSC pagination bounded (row_limit cap) + transient-cached; unconnected sites fully no-op?
5. Both phases degrade independently (no GSC ⇒ Phase A still fully works)?
