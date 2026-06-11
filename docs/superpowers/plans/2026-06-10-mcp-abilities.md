# MCP Server + WP Abilities API Implementation Plan (v4.19 batch)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the plugin's already-reviewed headless operations as machine-callable abilities so Claude/ChatGPT (via MCP) can drive the site's SEO. ~10 abilities wrapping verified methods: queue-topic, generate-post (ASYNC via background processor), run-audit (async-queued)/get-audit-results, aeo-scan-chunk/propose/apply/undo, add-redirect (with a new undo affordance), get-bot-stats, get-keyword-positions, get-decay-queue. Per-ability enable toggles (WRITE abilities default OFF), an activity-log table, and a settings panel.

**ARCHITECTURE DECISION (deviation from the original idea, controller-approved):** NO vendored Composer dependency. Three integration layers, all feature-detected:
1. **WP Abilities API** (core in 6.9): `wp_register_ability()` calls guarded by `function_exists` — on WP ≥6.9 the abilities appear in core's registry (and anything built on it, including the official MCP Adapter plugin if the user installs it).
2. **REST fallback** (works on the plugin's 6.0 floor): namespace `swps/v1/abilities` — GET /abilities (discovery: names, descriptions, input schemas, enabled state), POST /abilities/{name}/run — auth via standard REST (application passwords), permission `manage_options` + per-ability enabled check.
3. **Docs**: connecting Claude/ChatGPT via the official `wordpress/mcp-adapter` plugin (abilities auto-exposed) or any REST-capable bridge (covered in Feature 15 docs).
This keeps the no-runtime-Composer-dep convention, ships value on every WP version, and rides the official rails when present.

**NO version bump. COMMIT PER SUB-TASK on feature/v4.19-batch.**

## Verified facts (batch @ c93c44e)
- Headless methods (all previously reviewed): SWPS_Topic_Queue::create_topic(:91); SWPS_Background_Processor::schedule_generation; SWPS_SEO_Audit::run_all/run_module/fix_module (run_all SYNC + slow → the run-audit ability must schedule a single background event and return "queued", with get-audit-results reading the stored results — read how audit results are stored); SWPS_AEO_Optimizer::do_scan_chunk/do_propose/do_apply/do_undo; SWPS_Redirect_Manager::add_redirect(:193) (+ the cannibalization admin already deletes redirect rows directly — reuse that pattern for the new delete-redirect/undo affordance, or check if redirect manager has a public delete — read it); SWPS_Bot_Analytics_Tracker::get_bot_summary/get_totals; SWPS_Keyword_Tracker::get_tracked_keywords/get_keyword_history (verify names); SWPS_Refresh_Queue_Admin::get_queue.
- REST precedent: includes/class-rest-api.php (namespace swps/v1, permission callbacks — read 2-3 routes).
- Activity-log table precedent: any DB-version class (citation store newest-but-one).
- WP Abilities API shapes: `wp_register_ability( 'stratawp-seo/queue-topic', array( 'label'=>…, 'description'=>…, 'input_schema'=>…, 'output_schema'=>…, 'execute_callback'=>…, 'permission_callback'=>… ) )` — VERIFY current signature via WebSearch/context7 (it's new in 6.9; do not guess; if the signature differs, adapt and report).

## Components
- `SWPS_Abilities` (includes/class-abilities.php): the registry — one source of truth array of ability defs {name, label, description, input_schema (JSON Schema), write (bool), callback}; registers with WP Abilities when available AND exposes via the REST controller; per-ability enabled option swps_abilities_enabled (array; READ abilities default on, WRITE default off); execute() wrapper: enabled check → permission → sanitized input vs schema (pure validator, TDD) → callback → activity log row → result.
- `SWPS_Abilities_Rest` (includes/class-abilities-rest.php): the two routes; permission manage_options via standard REST auth; never expose API keys/secrets in any output.
- Activity log: table swps_ability_log (id, ability, user_id, input JSON (REDACT fields named like key/secret/token), result_summary VARCHAR(200), created_at; prune 90d via existing daily cron or own weekly).
- Settings panel: per-ability checkboxes grouped read/write + a "copy connection snippet" block (REST URL + note about application passwords + MCP Adapter mention) — discoverability tab.
- Pure TDD (tests/unit/AbilitiesTest.php ~12 cases): `validate_input( array $input, array $schema ): array|WP_Error` (required props, type checks string/int/bool/enum, unknown-prop strip), `redact( array $input ): array` (key/secret/token/password patterns), registry integrity check (every def has the required fields; write flags present).
- Safety: generate-post ability → schedule_generation ONLY (never sync); add-redirect logs loudly + the activity log row is the undo pointer (store redirect id in result; delete-redirect ability exists and is write-gated); apply/undo abilities call the existing snapshot-protected methods; NOTHING bypasses the budget/cap guards (they live inside the wrapped methods already — verify generate path hits the guardian gate).

## Tasks (single dispatch, commits a-c)
a. `feat: abilities registry with validated execution and activity log` — registry + validator/redactor TDD + log table (DB-version) + execute pipeline + uninstall entries.
b. `feat: abilities REST endpoints and WP Abilities registration` — REST controller + feature-detected wp_register_ability calls (verify signature first; permission_callback maps to our manage_options + enabled check).
c. `feat: abilities settings panel` — checkboxes + connection snippet; wiring; gates; live smoke on jonimms.local: list abilities via internal call, execute a READ ability (get-bot-stats) through the full execute() pipeline, attempt a disabled WRITE ability → clean denial + log row check, validator fixtures; REST route smoke via wp eval calling the controller directly or rest_do_request (NO real generation/AI).

## Self-review
1. Write abilities default OFF; enabled check inside execute() (not only UI)?
2. Secrets never logged (redactor) nor returned (no api_key in any output schema)?
3. generate-post strictly async; audit ability queued not sync?
4. REST routes manage_options; no nopriv anywhere?
5. Zero Composer/runtime deps added; everything feature-detected?
