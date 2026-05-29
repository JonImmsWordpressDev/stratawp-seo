# Model Discovery — Design

**Date:** 2026-05-29
**Status:** Approved (design), pending implementation plan
**Affects:** AI Model dropdown, Featured Images settings (new Gemini Image Model field), AI/image provider classes
**Branch:** its own branch off `main`, independent of the async-image PR (#41). Version finalizes at release based on merge order.

## Problem

AI text models and the Gemini image model are hardcoded — each AI provider's `get_available_models()` and a `MODEL` constant in `SWPS_Gemini_Image_Provider`. When a provider ships a new model (e.g. Claude Opus 4.8) or renames an image model, the plugin can't use it until someone edits the code and ships a release. The Gemini image-model constant has gone **stale and broken image generation before** (git history: `gemini-2.5-flash-preview-image-generation` → corrected). Users want new models to surface automatically, with a heads-up.

## Goals

- New text models from each *configured* provider appear in the AI Model dropdown automatically (daily refresh).
- The Gemini image model becomes **selectable** (new dropdown) and stays current — ending stale-constant breakage.
- A **dismissible admin alert** when a never-before-seen model appears.
- **Graceful degradation:** if a provider API is unreachable or no key is set, fall back to the curated lists (dropdown never empty or full of noise).

## Non-goals

- **No auto-switching** of the user's selected model (text or image). Discovery only adds options + alerts.
- No plugin-version auto-update changes (the existing `SWPS_GitHub_Updater` is untouched).
- No discovery for stock image providers (Unsplash/Pexels/Pixabay have no model versioning).

## Approach

Curated ∪ discovered, refreshed by a daily WP-Cron job, cached, with a dismissible admin notice on new models. The curated lists remain the labeled/ordered source of truth and fallback.

## Architecture

### 1. `SWPS_Model_Discovery` (new class)

- `get_text_models( string $provider_slug ): array` — curated (`$provider->get_available_models()`) **∪** cached-discovered for that provider, deduped by ID with curated label/order winning.
- `get_image_models(): array` — curated Gemini image models **∪** cached-discovered image-capable Gemini models.
- `refresh(): void` — daily-cron callback. For each provider with a configured API key:
  1. `fetch_remote_models()`; if it returns `[]` (error/no key), keep the last-good cached value (don't clobber).
  2. Store the result in a persistent option `swps_discovered_models` keyed by provider slug. The daily cron *is* the refresh cadence, so the option is the cache — always available to the dropdown between runs, and it survives transient expiry by design.
  3. Diff discovered IDs against `swps_known_model_ids`. **If the known set is empty (first run), seed it silently — no alert.** Otherwise, newly-seen IDs are appended to `swps_new_models_available` (for the alert) and added to the known set.
- `dismiss_alert(): void` — clears `swps_new_models_available`.

### 2. Per-provider `fetch_remote_models(): array`

Returns `[ model_id => display_name ]`. On `WP_Error` / non-200 / malformed body → return `[]`. To keep it unit-testable, split each into a thin HTTP method + a **pure static `parse_models_response( array $body ): array`** (the filter/map logic).

- **Anthropic** `GET https://api.anthropic.com/v1/models` (headers `x-api-key`, `anthropic-version: 2023-06-01`) → `data[].{id, display_name}`. All entries are chat models; map `id → display_name`.
- **OpenAI** `GET https://api.openai.com/v1/models` (Bearer) → `data[].id`; **filter to chat models** (keep `gpt-*` and `o*`; exclude `*embedding*`, `whisper*`, `tts*`, `dall-e*`, `*realtime*`, `*moderation*`, `*audio*`); prettify id → label.
- **Google** `GET https://generativelanguage.googleapis.com/v1beta/models?key={key}` → `models[]`; keep where `supportedGenerationMethods` contains `generateContent` and the model is not image/embedding-only; map `name` (strip `models/`) → `displayName`.
- **xAI** `GET https://api.x.ai/v1/models` (Bearer) → `data[].id`; filter `grok-*` (exclude image/embedding variants).
- **Gemini image** (`SWPS_Gemini_Image_Provider::fetch_remote_models()`): same Google endpoint; keep models whose capabilities/name indicate **image generation** (e.g. name contains `image`); map `name` → `displayName`.

### 3. Wiring the dropdowns

- `SWPS_Provider_Factory::get_models_for_provider( $slug )` → return `SWPS_Model_Discovery::get_text_models( $slug )`. This already feeds the AI Model dropdown via `ajax_get_models()` and the initial settings render, so **discovered text models appear with no other UI change**.
- **New setting `swps_gemini_image_model`** (default = the current constant value), registered in the Featured Images section, rendered as a `select` populated by `SWPS_Model_Discovery::get_image_models()`, shown only when image provider = Gemini (reuse the `admin.js` row-visibility pattern from the §6 fix).
- `SWPS_Gemini_Image_Provider` reads `get_option( 'swps_gemini_image_model', self::MODEL )` instead of the bare constant — the constant becomes the documented default/fallback.

### 4. Daily refresh cron

- Register a daily `swps_refresh_models` event (mirror `SWPS_Cron`'s schedule/unschedule pattern). Callback → `SWPS_Model_Discovery::refresh()`. Ensure-scheduled on init; unschedule on deactivation.

### 5. Alert (admin notice)

- `admin_notices` handler: if `swps_new_models_available` is non-empty, render a **dismissible** notice listing the new models + a link to Settings. Dismiss via a small AJAX action `swps_dismiss_model_alert` (nonce + `manage_options`) → `dismiss_alert()`. Shown on StrataWP-SEO admin pages.

## Data flow

```
daily cron → refresh()
  └─ per configured provider: fetch_remote_models() → store in swps_discovered_models[slug]
       → diff vs swps_known_model_ids → new IDs → swps_new_models_available

settings render / ajax_get_models → get_text_models()/get_image_models() → curated ∪ cached → dropdown

admin_notices → swps_new_models_available non-empty → dismissible notice → dismiss clears it
```

## Edge cases / safeguards

- **No API key** for a provider → skipped in `refresh()`; `get_*_models()` returns curated only.
- **API down / rate-limited / malformed** → `fetch_remote_models()` returns `[]`; curated fallback; **don't overwrite the cached option with empty** (keep last-good).
- **First run** seeds `swps_known_model_ids` silently (no alert on the existing baseline).
- **Duplicate** (discovered model already curated) → merged by ID, curated label/order wins.
- **Deprecated/removed** models still in curated → kept (discovery only adds; never prunes curated).
- **Cost/rate:** one list call per configured provider per day, cached → negligible.

## Testing

- **Pure unit (PHPUnit, no WP)** — add to the `unit` testsuite:
  - `parse_models_response()` per provider against captured JSON fixtures: OpenAI excludes embeddings/audio/image; Google keeps only `generateContent`; Anthropic maps `display_name`; xAI keeps `grok-*`; Gemini-image keeps image models.
  - Merge logic: curated ∪ discovered dedupes by ID, curated label wins, order stable.
  - Diff logic: empty known-set seeds silently (no new-model output); a subsequent unknown ID is reported once.
- **Integration (WP-CLI, manual, staging)** — `wp cron event run swps_refresh_models`; assert transient/option populated, `swps_new_models_available` set on a genuinely new model, dropdown includes discovered models, notice renders and dismisses.

## Rollout

- Minor version bump — next after the async-image release (likely **4.8.0**; finalize at release depending on merge order with PR #41).
- New option `swps_gemini_image_model` defaults to the current constant → **no behavior change** until the user selects a different image model.
- README.md + readme.txt changelog; build deployment zip.

## Open items (resolve during implementation)

- Exact per-provider include/exclude filter rules — refine against live API responses; keep filters permissive and rely on curated for canonical labels/ordering.
- Final version number (merge-order dependent with PR #41).
