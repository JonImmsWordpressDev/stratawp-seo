# Async Image Generation — Design

**Date:** 2026-05-29
**Status:** Approved (design), pending implementation plan
**Affects:** scheduled/cron content generation, image attachment pipeline

## Problem

Scheduled (WP-Cron) posts are saved with their AI text but **no featured image and no in-content images**, and nothing reaches `debug.log` or the plugin's Recent Activity log. Manual generation (`Generate Content`, admin-ajax) works fine.

Confirmed live on jonimms.com:
- Settings are correct: Auto Featured Images **ON**, In-Content Images **ON**, provider **Gemini**, Google API key **set**.
- Recent published posts have a mix of freshly-generated featured images (adjacent attachment IDs) and reused older images — i.e. **intermittent** success.
- The generation log shows `Generated post #N` entries but **zero** `Featured image failed:` entries across ~25 runs.

## Root cause

`SWPS_Generator::generate_post()` runs the **entire** image pipeline synchronously inside the generating request:

```
AI text (Opus, ~30–90s) → 1 Gemini featured image (~up to 60s) → up to N in-content Gemini images (~up to 60s each)
```

That stacks to ~200s+. The WP-Cron request is killed by the host's execution limit (PHP-FPM `request_terminate_timeout` / web-server proxy timeout) **before** the images finish. Because PHP dies *inside* `wp_remote_post`, the `error_log()` / generation-log calls in the failure paths never fire — hence the empty log. v4.4.2's `@set_time_limit(0)` only lifts PHP `max_execution_time`, not the FPM/server cap, so it didn't fix the real limit.

**Key enabling fact:** the AI-text call (itself a 30–90s `wp_remote_post`) *already succeeds* in the cron request. So the cron request budget is already ≥ ~90s. The problem is purely the **stacking** of multiple long calls in one request. Splitting each image into its own request (one ~60s call) fits the budget that already works for text.

## Goals

- Scheduled posts reliably get their featured image and configured in-content images.
- Image failures are **visible** in the Recent Activity log instead of vanishing.
- No regression for manual generation.
- Reuse existing infrastructure (`SWPS_Background_Processor`, provider factory, image inserter).
- The Gemini/Google image key is **editable from the UI** regardless of which AI *text* provider is selected (see §6).

## Non-goals

- No new user settings.
- No change to the image providers themselves (Gemini/Unsplash/Pexels/Pixabay logic stays).
- No stock-provider fallback or multi-retry policy beyond a single retry (can be a later iteration).
- No change to how AI **text** is generated.

## Approach (selected)

**Featured job + self-rescheduling in-content job.** Applies to **all** generation paths (cron, queue, REST, CLI, and manual).

Rejected alternatives:
- *Fan-out (one job per image, all at once):* concurrent in-content jobs each read→inject→`wp_update_post` on `post_content` and clobber each other. Needs a lock; fragile.
- *Single "all images" job:* one job still does N Gemini calls (~180s) → reintroduces the timeout.

## Architecture

### 1. `SWPS_Generator::generate_post()` — stop generating images inline

- Remove the synchronous featured-image block (current [class-generator.php:485–513](../../../includes/class-generator.php)).
- Keep saving text + meta + the `Generated post #N` log + `SWPS_Hooks::do_post_created()`.
- Result: `generate_post()` becomes short and always fits the request budget. The generator no longer depends on the image provider at runtime for attachment (it still receives it via constructor; that may later be trimmed — out of scope here).

### 2. `swps_post_created` handler — enqueue image jobs

A single handler (in `stratawp-seo.php`, replacing/extending the current `insert_content_images` handler at [stratawp-seo.php:819](../../../stratawp-seo.php)) enqueues jobs based on existing options:

- If `swps_featured_images`: compute query = `_swps_focus_keyword` meta ?: `$ai_result['title']`, run through `SWPS_Hooks::filter_image_query()`, then `schedule_featured_image($post_id, $query)`.
- If `swps_insert_content_images`: `schedule_content_image($post_id)` (first of the self-rescheduling chain).

### 3. `SWPS_Background_Processor` — new hooks + handlers

Reuses the existing Action Scheduler → `wp_schedule_single_event` fallback (`has_action_scheduler()`).

New constants/hooks:
- `swps_generate_featured_image` → `run_featured_image(int $post_id, string $query, int $attempt = 0)`
- `swps_generate_content_image`  → `run_content_image(int $post_id, int $attempt = 0)`

`run_featured_image()`:
1. Idempotent: return if `has_post_thumbnail($post_id)`.
2. `$provider = SWPS_Provider_Factory::create_image_provider();`
3. `$result = $provider->set_featured_image($post_id, $query);`
4. On success: set SEO alt text (focus keyword) and `_swps_social_image` OG meta (logic moved here from the generator). Log `Featured image attached to #N` to the generation log.
5. On `WP_Error`: log `Featured image failed: …` to the generation log. If `$attempt < 1`, reschedule once with `$attempt + 1`.

`run_content_image()`:
1. Return if `! swps_insert_content_images`.
2. Compute `target = min( (int) swps_content_images_count, eligible_section_count($post_id) )`.
3. Compute `done` from `_swps_content_images_inserted` meta (default 0). If `done >= target` → finished, return.
4. Generate + inject **one** image via `insert_single_image($post_id, $done, $target)` (below). Increment `_swps_content_images_inserted`.
5. On success and `done + 1 < target`: `schedule_content_image($post_id)` for the next. → strictly sequential, **no `post_content` race**.
6. On `WP_Error`: log; if `$attempt < 1`, reschedule the same position once.

### 4. `SWPS_Image_Inserter` — single-image entry point

Refactor the current monolithic `insert_images()` loop into reusable units:
- `eligible_section_count(int $post_id): int` — sections after splitting by H2, minus the intro section (existing logic in `split_by_headings()` + the `array_slice(…, 1)`). Returns 0 if fewer than 2 sections.
- `insert_single_image(int $post_id, int $position, int $target): true|WP_Error` — places the image at the **evenly-spaced** slot the current loop would use: split content, `eligible = array_slice(sections, 1)`, `interval = max(1, floor(count(eligible) / $target))`, target index `= $position * $interval`, content section index `= index + 1`. Extract the visual concept, search/generate via the configured provider (existing `search_image()` / `generate_gemini_image()` / `download_image()`), inject the `<figure>`, and `wp_update_post()`. One Gemini call per invocation.

  *Re-split stability:* each job run re-reads `post_content` and re-splits by H2. Injecting a `<figure>` adds no H2, so section indices stay valid across the sequential runs — earlier insertions don't shift later positions.

`insert_images()` is no longer called synchronously; the orchestration moves to the background job.

### 5. Failure logging

Image-job outcomes write to the same `swps_generation_log` option the generator uses (so they appear in **Recent Activity**). Extract the generator's append-and-trim logic into a small shared helper (e.g. a static `SWPS_Generator::append_log()` or a tiny logger) and call it from the background processor. Avoids duplicating the trim/cap logic.

### 6. Make the Gemini/Google image key editable regardless of text provider

The single `swps_google_api_key` input lives in the AI Provider section with row class `swps-ai-key-row swps-provider-google`. [admin.js:60–61](../../../admin/js/admin.js) shows it **only** when `swps_ai_provider === 'google'`. With Gemini as the *image* provider but a non-Google *text* provider (e.g. Anthropic), the field is hidden — so the key can't be seen or rotated from the UI, even though the Gemini image provider depends on it.

Fix — **visibility only, no second input** (so the v4.4.1 #24 duplicate-name regression is structurally impossible):
- Show the Google key row when `swps_ai_provider === 'google'` **OR** `swps_image_provider === 'gemini'`. Update both the AI-provider and image-provider change handlers in `admin.js`, **and** the on-load init so the state is correct before any change event.
- Clarify the field description to note it's also used for Gemini image generation; keep the Featured Images info row ([class-settings.php:295](../../../includes/class-settings.php)) pointing to it.
- **Guard:** exactly one input named `swps_google_api_key` remains in the entire form. No duplicate is added; we only change when the existing one is visible.

Testing: with text=Anthropic + image=Gemini, the Google key field is visible and editable; saving preserves the value (never blanks it); switching the image provider away from Gemini while text≠Google hides it again.

## Data flow

```
trigger (cron / manual / queue / REST / CLI)
  └─ generate_post()
       ├─ AI text → wp_insert_post → meta → log "Generated post #N"
       └─ do_post_created()
            └─ enqueue: featured-image job  +  content-image job(0)
  └─ returns  (post has text; request ends well within budget)

later (Action Scheduler / wp-cron tick), each in its own short request:
  swps_generate_featured_image(N, query)        → attach thumbnail + alt + OG, log
  swps_generate_content_image(N)  position 0    → inject 1 image → reschedule →
  swps_generate_content_image(N)  position 1    → inject 1 image → … until target
```

## Idempotency & edge cases

- Featured job no-ops if a thumbnail already exists (safe re-runs, manual re-trigger).
- Content job is bounded by `target` and the `_swps_content_images_inserted` counter; re-runs won't double-insert.
- Post deleted/trashed before a job runs: handler returns early if `get_post()` is falsy or status is `trash`.
- Provider/key missing: job logs a clear failure to Recent Activity (previously silent).
- Action Scheduler absent: `wp_schedule_single_event` fires on the next cron tick — images land a little later, still reliably.

## Testing

- Verify `generate_post()` schedules the featured + content jobs and does **not** call `set_featured_image()` synchronously (assert a scheduled action exists; assert the provider isn't hit during generation).
- Verify `run_featured_image()` is idempotent (no-op when thumbnail present) and logs on both success and failure.
- Verify content-image sequencing: `insert_single_image($id, k)` injects into the k-th eligible section; counter increments; the chain stops at `target`.
- Confirm whether the repo has a PHPUnit/WP test harness during planning; if not, add a lightweight test script + a live verification (trigger a scheduled generation, confirm images attach and Recent Activity shows the outcomes).

## Rollout

- Behavior change → minor version bump off `main` (**4.6.5 → 4.7.0**). NB: the working branch `chore/repo-health-bundle` is 4.5.1, but this work branches off `main` (4.6.5); the image code is byte-identical on both, so the fix applies cleanly.
- Per project workflow: bump version in `stratawp-seo.php` + `SWPS_VERSION`, update `README.md` + `readme.txt` changelog + relevant docs, rebuild the deployment zip.
- Existing already-generated posts are unaffected; only new generations use the async path.

## Secondary (separate, minor)

"Images Per Post" is saved as **2**, though the user set 3. `content_images_count` is registered **once** ([class-settings.php:316](../../../includes/class-settings.php)) — not the v4.4.1 duplicate-field class of bug — so this is most likely an unsaved/reverted value, not a code defect. To be confirmed with the user; only pursued as a fix if reproduced. Not the cause of the missing images (2 would still produce images).
