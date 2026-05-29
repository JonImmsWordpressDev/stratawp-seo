# Dynamic Model Catalog — Design Spec

- **Date:** 2026-05-29
- **Status:** Approved (design); pending implementation plan
- **Topic:** Replace hardcoded AI model lists + hand-written labels with dynamic discovery, capability filtering, and heuristic-driven descriptive labels across all four AI providers.

## Summary

Today the AI-model dropdown shows a hardcoded "curated" list per provider with hand-written suffixes (e.g. `Claude Opus 4.7 (Most powerful, higher cost)`), and discovery merely *appends* API-found models beneath it with plain labels. The result: new models (e.g. `claude-opus-4-8`) land at the bottom, unlabeled, and the "Most powerful" tag is frozen on whatever was hardcoded.

This feature makes the list **fully dynamic** (pulled live, filtered to text-generation chat models) and computes the descriptive tags — **Most powerful / Cheapest / Costs most / Best value** — from **family/pattern heuristics**, for **all four providers** (Anthropic, OpenAI, Google, xAI).

## The governing constraint

Provider model-list endpoints return essentially `id` + `display_name` only — **no price and no capability/power ranking**. Google additionally returns `supportedGenerationMethods`. Therefore:

- The **list** can be fully dynamic from the API.
- The **superlative labels** require price + power data that must live in the plugin. Per the chosen approach, that data is **derived heuristically from the model ID** (family → tier + default price), with **no per-model override table** (pure heuristics — accepts approximation as the trade-off for zero per-launch upkeep).

## Goals

1. List models pulled live per provider, filtered to usable text-generation chat models.
2. Compute and display dynamic superlative tags over the active provider's filtered set.
3. New models within a known family are priced and ranked automatically — no code edit per launch.
4. Unify pricing into one source of truth shared by the dropdown labels and the cost tracker.

## Non-goals / out of scope

- Per-model price/rank override table (explicitly rejected — pure heuristics).
- The image-model picker (Gemini image bucket) — unchanged.
- Auto-switching the user's selected model (discovery never changes the selection; preserved).
- New comparison UI — labels remain inline in the existing `<select>`.

## Architecture

### New component — `SWPS_Model_Catalog`

A single class holding all heuristics as **per-provider rule tables**. Responsibilities:

- `metadata( string $id, ?string $provider = null ): array` → `{ provider, family, tier_rank, version, power_score, price_in, price_out }`. Infers provider from family patterns when not supplied.
- `price_for( string $id ): array` → `[ input, output ]` per 1M tokens (used by the cost tracker).
- `power_score( string $id ): ?int`.
- `decorate_labels( array $models, string $provider ): array` → takes `id => display_name`, returns `id => labelled_name`, computing superlatives across the set.

Power score: `power_score = tier_rank * 1000 + round( version * 10 )`. Version is parsed from the ID after stripping a trailing date (`-YYYYMMDD`), taking the first numeric run after the family token and treating the first internal separator as a decimal point: `opus-4-8` → 4.8, `opus-4-1-20250805` → 4.1, `opus-4-20250514` → 4.0, `gemini-2.5-pro` → 2.5, `grok-3` → 3.0. Yields the intended order `opus-4-8 > opus-4-7 > opus-4-6 > opus-4-5 > opus-4-1 > opus-4 > sonnet-4-6 > … > haiku-4-5`.

Unknown family → `metadata` returns nulls for tier/price; the model is still listed but receives no tags and is excluded from price-based superlatives.

#### Seed heuristic tables (the maintained config)

Tier ranks and default prices (USD per 1M input/output). Higher tier_rank = more powerful.

| Provider | Family (ID match) | tier_rank | in | out |
|---|---|---|---|---|
| anthropic | `opus` | 30 | 15.00 | 75.00 |
| anthropic | `sonnet` | 20 | 3.00 | 15.00 |
| anthropic | `haiku` | 10 | 0.80 | 4.00 |
| openai | `^o\d` (o-series) | 30 | 1.10 | 4.40 |
| openai | `gpt-4.1` (non-mini/nano) | 25 | 2.00 | 8.00 |
| openai | `gpt-4o` (non-mini) | 22 | 2.50 | 10.00 |
| openai | `*-mini` | 15 | 0.40 | 1.60 |
| openai | `*-nano` | 10 | 0.10 | 0.40 |
| google | `pro` | 30 | 1.25 | 10.00 |
| google | `flash` (non-lite) | 20 | 0.15 | 0.60 |
| google | `flash-lite` | 10 | 0.075 | 0.30 |
| google | `gemma` | 5 | 0.00 | 0.00 |
| xai | `grok` + `fast` | 20 | 5.00 | 25.00 |
| xai | `grok` (non-mini) | 20 | 3.00 | 15.00 |
| xai | `grok` + `mini` | 10 | 0.30 | 0.50 |

Matching is most-specific-first (e.g. `flash-lite` before `flash`, `mini`/`nano`/`fast` modifiers before the base family). Seed values are the agreed approximations; they are tuned by editing this table, not per model.

### Filtering — each provider's `fetch_remote_models()`

Filtering is the provider's responsibility, applied **before storage**, returning only text-generation models as `id => display_name`:

- **Anthropic:** keep all `claude-*` (none excluded today; reserve image/tts patterns for the future).
- **OpenAI:** include `^(gpt-|o\d|chatgpt)`; exclude IDs matching `whisper|dall-e|tts|text-embedding|omni-moderation|babbage|davinci|gpt-image` or the segments `-(audio|realtime|transcribe|tts|image|embedding|search)(-|$)`.
- **Google:** keep only models whose `supportedGenerationMethods` includes `generateContent`, **and** whose name does **not** match `-(image|tts|embedding)|imagen|veo|lyria|aqa|robotics|computer-use|deep-research|antigravity`. (Note: `*-image` Gemini models support `generateContent` but are image-output → excluded by name.) Requires Google's `fetch_remote_models()` to read `supportedGenerationMethods`, which the current id-only parser discards.
- **xAI:** include `grok-*`; exclude image-output (`-image`).

Exclude/include lists are the per-provider maintained surface (the accepted trade-off of filtering choice A).

### Labels — computed at render time

`SWPS_Model_Catalog::decorate_labels()` scans the active provider's filtered set and assigns tags:

- **Most powerful** = highest `power_score`.
- **Costs most** / **Cheapest** = highest / lowest output price among models with known price.
- **Best value** = the highest-`power_score` model whose output price is **strictly below** the set's maximum output price. Suppressed if no model is below the max (e.g. all same price).
- Tags **combine** when they coincide (the flagship is often `Most powerful · Costs most`).
- **Suppressed entirely** when the set has fewer than 2 models.
- Models with unknown family/price are listed with their plain name and are ineligible for tags.

Label string: `"{display_name} — {tags joined by ' · '}"`, or just `display_name` when untagged. **No price shown** (per decision). Plain text, no emoji. Example set (Anthropic):

```
Claude Opus 4.8 — Most powerful · Costs most
Claude Opus 4.7
Claude Sonnet 4.6 — Best value
Claude Haiku 4.5 — Cheapest
```

### Dynamic list with fallback

`SWPS_Model_Discovery::get_text_models( $slug )` becomes:

1. `models = discovered[$slug]` if non-empty, else `fallback_list($slug)`.
2. `return SWPS_Model_Catalog::decorate_labels( models, $slug )`.

The curated-merge-as-primary is dropped. The existing hardcoded `get_available_models()` per provider is **retained but demoted to a fallback** (used only when there's no API key / discovery is empty so the dropdown is never blank) and its hand-written `(Most powerful, …)` suffixes are removed.

### Unified pricing (fixes the cost-tracker gap)

`SWPS_Cost_Tracker::calculate_cost()` currently reads a private `PRICING` const missing newer models (`claude-opus-4-8` is absent — why 4.8 generations wouldn't be costed). It will instead call `SWPS_Model_Catalog::price_for( $model )`, so cost tracking auto-covers new models through the same family heuristics. The `PRICING` const is retired; the Catalog tables are the single source of truth.

## Data flow

1. Daily cron `swps_refresh_models` → `SWPS_Model_Discovery::refresh()` → per provider `fetch_remote_models()` (now filtered) → stored in `swps_discovered_models[$slug]` as `id => display_name` (last-good retained on empty). Stored junk from prior unfiltered runs self-heals on the next refresh.
2. Settings render → `get_current_model_options()` → `get_models_for_provider($slug)` → `get_text_models($slug)` → fallback-or-discovered → `Catalog::decorate_labels()`.
3. Generation cost → `Cost_Tracker::track()` → `calculate_cost()` → `Catalog::price_for()`.

## Files

**New**
- `includes/class-model-catalog.php` — the heuristics brain. **Must be `require_once`'d in `stratawp-seo.php`** (no runtime autoloader — a missing require is a runtime-only fatal).
- `tests/unit/ModelCatalogTest.php`.

**Modified**
- `includes/providers/ai/class-anthropic-provider.php`, `class-openai-provider.php`, `class-google-provider.php`, `class-xai-provider.php` — add filtering to `fetch_remote_models()`; trim `get_available_models()` to a plain fallback (Google also parses `supportedGenerationMethods`).
- `includes/class-model-discovery.php` — `get_text_models()` fallback + decoration; drop curated-merge.
- `includes/class-cost-tracker.php` — delegate pricing to the Catalog; retire `PRICING`.
- `stratawp-seo.php` — `require_once` the new class.
- `tests/unit/ModelDiscoveryTest.php` — update for new behavior; per-provider filter tests with junk-filled fixture responses.

## Testing

- **Catalog:** family + version detection; `power_score` ordering across families and versions; default pricing lookup; superlative selection on a representative set (most powerful, cheapest, costs most, best value); label decoration and tag combination; unknown-family pass-through (listed, untagged); `<2` models → no tags; price ties → deterministic pick; best-value suppression when all prices equal.
- **Per-provider filtering:** feed a fixture API response containing junk (TTS, image, embeddings, music, robotics) → assert only text-gen models survive. Google fixture exercises `supportedGenerationMethods` + name exclusion.
- **Cost tracker:** `claude-opus-4-8` is now costed via the Catalog (regression for the original bug).

## Risks & trade-offs

- **Heuristic approximation:** an off-pattern price or an unusually-ranked model is wrong until the family table is edited. Accepted (chose pure heuristics over a per-model table).
- **OpenAI/xAI capability filtering is pattern-based** (their APIs return no capability data), so a novel non-text model with a `gpt-`/`grok-` prefix could slip through until its pattern is added.
- **Per-provider maintenance** shifts from "add each model" to "maintain family + exclusion rules" — far less frequent, but non-zero.

## Open items for user review

None outstanding — best-value rule and no-price label format confirmed during design.
