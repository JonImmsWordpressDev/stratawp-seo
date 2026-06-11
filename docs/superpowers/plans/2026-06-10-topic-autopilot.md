# Data-Driven Topic Autopilot Implementation Plan (v4.19 batch)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A weekly scout mines three signals — (1) GSC question queries with impressions but no ranking post (REUSE `swps_question_topic_candidates` built by Feature 7's miner — do NOT re-mine), (2) striking-distance queries (position 8–20) from raw GSC rows lacking a dedicated post, (3) the orphan-pages list — and asks the AI to turn the top signals into concrete topic proposals (title, target keyword, template suggestion, one-line rationale citing the data). Proposals land in the topic queue as a new 'proposed' status; the user approves (→ 'queued') or dismisses them from the calendar/queue UI. Optional full-autopilot (default OFF): auto-promote the top proposal when the queue runs dry, replacing today's blind-topic fallback with an audited, evidence-backed pick.

**NO version bump. COMMIT PER SUB-TASK on feature/v4.19-batch.**

## Verified facts (batch @ f3e79b0)
- `SWPS_Topic_Queue::create_topic( string $title, string $date = '', string $template = 'auto', string $notes = '', int $priority = 10 ): int|WP_Error` (:91); custom post statuses registered (:40-70: queued/generating/failed/retrying — read exact registration to add 'proposed' identically); `get_next_topic()` selects 'queued' with past post_date; `get_calendar_events` status list + color map (match expression — add proposed, e.g. '#8b5cf6' violet).
- Feature 7 candidates: option `swps_question_topic_candidates` = rows {q, impressions, ...} (read SWPS_Question_Coverage for the exact shape) — signal 1, ready-made.
- Striking distance: `SWPS_Search_Console::get_query_page_rows( $days, $row_limit )` rows {keys:[query,page]?, clicks, impressions, position} — read the exact row shape (two dimensions); filter position 8-20, group by query, "no dedicated post" = the ranking page maps to a non-post OR the query's tokens don't overlap the post title (use SWPS_Question_Coverage::tokens_similar — reuse, threshold <0.6 = not dedicated).
- Orphans: SQL precedent in class-internal-links-admin.php (:84-140) — reuse/extract the query (read it; call the admin class if the method is public, else duplicate the SQL with a comment pointing at the source).
- AI: SWPS_Provider_Factory::create_ai_provider()->chat_json() (JSON-proposal parse precedent: suggest_keywords at class-keyword-tracker.php:301 — read it); budget gate NOT applied to chat_json paths (known) — but DO check SWPS_Autopilot_Guardian::check_budget() before the scout's AI call and skip with a log when exceeded (cheap consistency win).
- Dedup: SWPS_Duplicate_Checker (read its is_duplicate signature) against existing posts AND existing queue/proposed topics.
- Blind fallback: SWPS_Cron::run_scheduled_generation → generate_post('') lets the AI invent a topic (class-generator.php empty-topic path). Auto-promote hooks BEFORE that: when queue empty AND option swps_topic_autopromote on AND a proposal exists → promote top proposal (status 'queued', post_date set to NOW minus 1 minute so get_next_topic picks it immediately) and proceed.
- Calendar AJAX precedent: SWPS_Calendar (read for approve/dismiss endpoints placement).

## Pure logic (TDD — tests/unit/TopicScoutTest.php, ~10 cases)
```php
	/** Score and rank raw signals into a unified candidate list. */
	public static function rank_signals( array $question_candidates, array $striking, array $orphans, int $limit = 10 ): array
	// question: weight impressions; striking: weight impressions * (21 - position)/13; orphan: fixed low weight; dedupe by normalized text; returns [{type, text, score, meta}]
	/** Build the AI prompt payload from ranked signals + site context (pure formatting). */
	public static function build_scout_prompt( array $ranked, string $site_context ): string
	/** Validate/normalize one AI proposal row: requires title (≤120 chars) + rationale; whitelists template against a passed list; returns null on junk. */
	public static function normalize_proposal( array $row, array $templates ): ?array
```

## Tasks (single dispatch, commits a-c)
a. `feat: topic scout signal ranking and proposal normalization` — TDD pure statics on new SWPS_Topic_Scout (includes/class-topic-scout.php).
b. `feat: weekly topic scout with proposed-status queue entries` — weekly cron swps_topic_scout (house wiring: activation schedule, deactivation/uninstall): gather signals (GSC guard for 1-2; orphans always), rank, budget-guard, ONE chat_json call (cap 5 proposals/run), normalize, dedupe (duplicate checker + existing topics incl. proposed), create via create_topic + set status 'proposed' + meta _swps_target_keyword/_swps_rationale/_swps_signal (register 'proposed' post status alongside the others; add to calendar query+colors). Settings: enabled toggle (default ON but inert without any signal), autopromote toggle (default OFF) — small section, schedule tab. Cap proposed backlog at 10 (skip run when full).
c. `feat: proposal review UI and auto-promote` — calendar/topic-queue UI: proposed entries visibly distinct + Approve/Dismiss (AJAX nonce swps_topic_scout + manage_options; approve → wp_update_post status queued; dismiss → trash); auto-promote in SWPS_Cron before the blind fallback as described (log via SWPS_Generator::append_log: "Auto-promoted proposal: ..."); editor-facing rationale shown in the topic row (read how topics render in the queue page/calendar tooltips — match).
Gates + live smoke (jonimms.local): rank_signals/normalize_proposal fixtures; cron handler with GSC unconnected + empty candidates → no AI call, clean no-op (assert via a counting filter or log); create_topic + proposed status + approve flow roundtrip with cleanup. NO real AI calls. phpunit (406+), phpstan 2G, phpcs new files.

## Self-review
1. ONE AI call per weekly run max, budget-guarded, capped output?
2. Auto-promote default OFF; promoted topics auditable (rationale meta + log)?
3. 'proposed' never picked by get_next_topic directly?
4. Backlog cap prevents proposal spam; dedupe covers posts AND topics?
