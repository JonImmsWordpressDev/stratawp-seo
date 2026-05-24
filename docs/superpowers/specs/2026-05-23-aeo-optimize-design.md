# AEO Optimize — Design

**Date:** 2026-05-23
**Target version:** v4.6.0
**Status:** Brainstorm approved; pending implementation plan
**Estimated effort:** ~2 weeks (1 engineer)

---

## 1. Problem

StrataWP SEO ships a deep AI feature set, but has three concrete gaps when it comes to *Answer Engine Optimization* (AEO) — the discipline of making content easy for AI search engines (ChatGPT, Claude, Perplexity, Google AI Overviews, etc.) to extract, attribute, and cite:

1. **No action layer on the AEO gap report.** The AI Bot Analytics dashboard (v4.5) tracks 15 AI crawlers and surfaces an "AEO gap report", but the plugin has no mechanism to *apply* fixes to those gap posts. Users see the problem but cannot act on it from inside the plugin.
2. **No dynamic schema beyond `Article`.** `SWPS_Schema` only emits `Article` / `BlogPosting` / `NewsArticle` / `BreadcrumbList` / `FAQPage` / `WebSite` / `Organization` / `Person` / `ItemList`. Posts that are recipes, how-tos, product reviews, or question pages cannot be served with their correct typed schema — invisible to Google rich results and to AI-citation pipelines that prefer typed JSON-LD.
3. **No AI-citeability score.** The existing `SWPS_Content_Scorer` measures readability, keyword density, and structure for *human* readers. It does not measure whether content is easy for an AI to extract verbatim, attribute, or trust as a source.

## 2. Goals & non-goals

### In scope (v4.6.0)

- **AEO Scorer** — 4 dimensions: Extractability, Markup, Authority, Coverage. Heuristic for 3, optional LLM for Coverage.
- **AEO Optimize admin page** — mirrors the Auto-Optimize UX (bulk re-scan, threshold filter, queue, per-row proposal, diff modal, apply with snapshot).
- **Live AEO panel** in the post editor — Gutenberg sidebar plugin + classic-editor metabox fallback.
- **Dynamic schema generation** for 5 new types: `HowTo`, `Recipe`, `Product`, `Review`, `QAPage`.
- **REST API** — 5 new endpoints under `/swps/v1/aeo/*`.
- **Settings** — new AEO section with threshold, per-dimension weights, Coverage toggle, enabled schema types.
- **Hooks** — 5 new filters for extensibility.
- **Snapshot/undo** — every apply creates a typed snapshot that reverts in one click.

### Out of scope (defer)

- Author E-E-A-T enhancement (Person schema, bio rewriting) — earmarked for v4.6.1.
- Multilingual content / hreflang management.
- Public-facing AI chat widget or RAG-over-posts.
- Citation tracking (which AI engine actually cited which page).
- New schema types beyond the five (`Event`, `Course`, `SoftwareApplication`, `VideoObject`, `JobPosting`, etc.) — addable later via the same generator pattern.
- Image AEO — already covered by the Image SEO module.
- AEO scoring for custom post types beyond `post`/`page` — configurable via filter, not surfaced in admin UI.

## 3. Architecture

### 3.1 New classes

| Class | File | Responsibility |
|---|---|---|
| `SWPS_AEO_Scorer` | `includes/class-aeo-scorer.php` | Orchestrator. Runs 4 sub-scorers, returns weighted total + per-dimension breakdown. Caches in post meta. |
| `SWPS_AEO_Extractability_Scorer` | `includes/aeo/class-extractability-scorer.php` | Heuristic. Self-contained paragraph rate, declarative-vs-hedged sentence ratio, list/table/definition-list density, definitional first-sentence pattern detection. |
| `SWPS_AEO_Markup_Scorer` | `includes/aeo/class-markup-scorer.php` | Heuristic. Counts explicit questions in headings + body with directly-following answers. Checks current schema and infers expected schema type from content patterns. |
| `SWPS_AEO_Authority_Scorer` | `includes/aeo/class-authority-scorer.php` | Heuristic. Author byline presence, publish + updated dates, outbound links to authoritative domains, current-year references, "Updated:" notices. |
| `SWPS_AEO_Coverage_Scorer` | `includes/aeo/class-coverage-scorer.php` | LLM. Sends post outline + topic; returns coverage gaps and entity-ambiguity issues. Cached as post meta with content-hash invalidation. Off by default in bulk scan; runs lazily during proposal generation. |
| `SWPS_AEO_Schema_Generator` | `includes/class-aeo-schema-generator.php` | AI-driven. Detects type (`HowTo`/`Recipe`/`Product`/`Review`/`QAPage`/`null`). Generates type-appropriate JSON-LD. Validates against schema.org required fields. Persists to post meta. |
| `SWPS_AEO_Optimizer` | `includes/class-aeo-optimizer.php` | Admin page controller. Mirrors `class-auto-optimize.php`. Handles re-scan batches, queue, proposal generation, apply pipeline, snapshot, dismiss. |
| `SWPS_AEO_Editor_Panel` | `includes/class-aeo-editor-panel.php` | Renders the live AEO panel — Gutenberg sidebar plugin registration + classic-editor metabox fallback. Reuses the same proposal/diff pipeline as the optimizer page. |

### 3.2 Extended classes

| Class | Change |
|---|---|
| `SWPS_Schema` (`class-schema.php`) | Add `schema_howto()`, `schema_recipe()`, `schema_product()`, `schema_review()`, `schema_qa_page()` rendering methods. Each follows the existing `schema_*()` pattern (`wp_head` hook output, filterable via `swps_schema_{type}`, auto-defer if Yoast / RankMath / AIOSEO active). Each reads `_swps_aeo_schema_type` + `_swps_aeo_schema_json` and outputs them as JSON-LD only when the detected type matches and the user has that type enabled in settings. |
| `SWPS_REST_API` (`class-rest-api.php`) | 6 endpoints (see §6). |
| `SWPS_Settings` (`class-settings.php`) | New "AEO" tab on the existing settings page. Registers `swps_aeo_threshold`, `swps_aeo_weights`, `swps_aeo_coverage_enabled`, `swps_aeo_enabled_schema_types`, `swps_aeo_post_types`. |
| `SWPS_Hooks` (`class-hooks.php`) | 5 new filters: `swps_aeo_score`, `swps_aeo_subscores`, `swps_aeo_proposal`, `swps_aeo_schema_json`, `swps_aeo_dimensions`. |
| `SWPS_Dashboard` (`class-dashboard.php`) | New tile: "AEO health" — % of posts above threshold, lowest-scoring sub-dimension, link to Optimize page. |
| `SWPS_Bot_Analytics_Tracker` (`class-bot-analytics-tracker.php`) | Existing AEO gap report rows get a "Optimize for AEO →" link that deep-links to the AEO Optimize page filtered to that post. |

### 3.3 Frontend assets

| File | Purpose |
|---|---|
| `admin/js/aeo-optimizer.js` | Admin page: re-scan progress bar, queue rendering, proposal fetch, diff modal, apply, dismiss, undo. |
| `admin/js/aeo-editor-panel.js` | Gutenberg sidebar plugin (React + `@wordpress/plugins` registration) + classic-editor metabox script. Live score, sub-score chips, "Improve AEO" button, inline diff modal. |
| `admin/css/aeo.css` | Sub-score chips, score gauge, diff styling for schema preview, editor-panel layout. |
| `templates/aeo-page.php` | Admin page template (mirrors `templates/auto-optimize-page.php`). |

### 3.4 Storage

No new database tables. State lives in:

**Post meta (per post):**
- `_swps_aeo_score` — int 0-100
- `_swps_aeo_subscore_extractability` — int 0-100
- `_swps_aeo_subscore_markup` — int 0-100
- `_swps_aeo_subscore_authority` — int 0-100
- `_swps_aeo_subscore_coverage` — int 0-100 (nullable; not set if Coverage scorer never ran)
- `_swps_aeo_last_scan` — UNIX timestamp
- `_swps_aeo_content_hash` — md5 of post content at last Coverage scan, for invalidation
- `_swps_aeo_schema_type` — one of `howto`, `recipe`, `product`, `review`, `qapage`, or empty
- `_swps_aeo_schema_json` — JSON string (the JSON-LD body)
- `_swps_aeo_proposal` — JSON blob (cached proposal; TTL 24h via cron sweep)
- `_swps_aeo_snapshot` — JSON blob (last-apply snapshot for undo)
- `_swps_aeo_dismissed` — bool

**Options (site-wide):**
- `swps_aeo_threshold` — int (default 70)
- `swps_aeo_weights` — array `{ extractability: 0.30, markup: 0.30, authority: 0.20, coverage: 0.20 }`
- `swps_aeo_coverage_enabled` — bool (default false)
- `swps_aeo_enabled_schema_types` — array of slugs (default all 5)
- `swps_aeo_post_types` — array (default `['post', 'page']`)

`uninstall.php` already deletes all `_swps_*` postmeta and `swps_*` options via wildcard SQL — no change needed.

## 4. Scoring model

### 4.1 Dimensions and weights

| Dimension | Default weight | Cost | What it measures |
|---|---|---|---|
| Extractability | 30% | Free | Are paragraphs quotable in isolation? Lists/tables/definitions present? |
| Markup | 30% | Free | Q&A density + presence of the *correct* schema for inferred content type. |
| Authority | 20% | Free | Author byline, dates, external links to authoritative domains, freshness signals. |
| Coverage | 20% | 1 LLM call | Topic-completeness gaps + entity-ambiguity flags. Optional, lazy. |

Weights are user-configurable. If Coverage is disabled, its weight is redistributed proportionally across the other three.

### 4.2 Extractability scorer (heuristic)

Inputs: post HTML.

Sub-checks:
- **Self-contained paragraph rate** — % of `<p>` blocks whose first sentence begins with a noun phrase (not a pronoun, conjunction, or hedge). Targets: ≥60% = full marks, ≤30% = zero.
- **Declarative ratio** — % of sentences ending in `.` (not `?` or hedge patterns like "may be", "could be", "we think"). Targets: ≥75% / ≤40%.
- **Structural density** — count of `<ul>`, `<ol>`, `<table>`, `<dl>`, `<blockquote>` elements, normalized per 1000 words. Targets: ≥2 / 0.
- **Definitional lead pattern** — does the first paragraph match `^[A-Z][^.]+ is/are [a-z]` or `^[A-Z][^.]+ refers to`? Binary signal worth 15 of the 100.

Final score = weighted sum, clamped 0-100.

### 4.3 Markup scorer (heuristic)

Sub-checks:
- **Question count** — count of headings (`h2`-`h6`) ending in `?` + body lines ending in `?`. Targets: ≥3 questions = full marks (and we also expect FAQ schema in that case).
- **Q-followed-by-A** — for each question, is the next block under 150 words and declarative? Binary per pair; aggregated as % of questions with good answers.
- **Schema present vs. expected** — compares `_swps_aeo_schema_type` (if set) to inferred type from content patterns (see §5). Mismatches cost points.
- **FAQ schema for Q&A-heavy posts** — if ≥3 questions present and `FAQPage` schema absent, deduct.
- **TOC presence** — bonus if the post has a TOC (existing plugin feature).

### 4.4 Authority scorer (heuristic)

Sub-checks:
- **Byline present** — `post_author` resolves to a non-empty display name. Yes/no.
- **Publish date in last 24 months** OR **updated date in last 12 months** — freshness component.
- **Outbound authoritative links** — count of `<a href>` to domains in the bundled high-authority allowlist (Wikipedia, .gov, .edu, major news, well-known reference sites). Normalized per 1000 words. Targets: ≥1 / 0.
- **Current-year mention** — `\b202[5-9]\b` in the post body. Binary bonus.
- **"Updated:" / "Last reviewed:" notices** — pattern match for these phrases near the top. Binary bonus.

The authoritative-domain allowlist ships as a JSON file (`includes/data/authoritative-domains.json`) and is filterable via `swps_authoritative_domains`.

### 4.5 Coverage scorer (LLM, optional)

When enabled and content hash differs from `_swps_aeo_content_hash`:

1. Build a compact outline (H1, H2s, first sentence of each section, named entities pulled from the post via a simple capitalized-noun-phrase pass).
2. Prompt the active AI provider:
   > "Given this post outline on topic `{title}`, list (a) sub-topics a reader interested in this topic would expect that are missing, max 5, and (b) entities mentioned vaguely (pronouns, generic references) that should be named explicitly, max 5. Return JSON: `{coverage_gaps: [], entity_issues: [], score: 0-100}`."
3. Cache score + content hash in post meta.
4. Per-call cost is recorded via the existing `SWPS_Cost_Tracker`.

If the LLM call fails or returns invalid JSON, fall back to the cached value (or null if never run) and mark the dimension as "stale" in the UI.

### 4.6 Total score

```
total = (extractability * w_e + markup * w_m + authority * w_a + coverage * w_c)
        / (w_e + w_m + w_a + (coverage_set ? w_c : 0))
```

Weights re-normalize if Coverage was skipped. Always rounded to nearest int.

## 5. Dynamic schema generation

### 5.1 Detection heuristics

The Markup scorer runs detection on content *patterns*; the Schema Generator confirms via AI before emitting.

| Type | Heuristic signal |
|---|---|
| `HowTo` | Ordered list of ≥3 steps where each `<li>` starts with imperative verb; "How to" in title; step-heading patterns (`Step 1`, `Step 2`). |
| `Recipe` | "Ingredients" heading followed by unordered list; cook/prep/total-time mentions; "servings" / "yield" keyword; food/cooking taxonomy. |
| `Product` | Product-name pattern in title; price near top (`$`, `£`, `€`); brand mention; specs list. |
| `Review` | "Review of X" / "X Review" in title; rating language ("5 out of 5", "★★★★"); pros/cons headings. |
| `QAPage` | Title is a question OR ≥80% of H2s are questions; single dominant question with one detailed answer. |

If multiple types match, pick the highest-confidence one and pass the others as `secondary_candidates` to the AI for tie-breaking.

### 5.2 Generation prompt

The Schema Generator ships with a per-type field manifest (`includes/data/aeo-schema-fields.json`) defining required and recommended fields for each of the 5 supported types, sourced from schema.org + Google's structured-data documentation. The manifest is filterable via `swps_aeo_schema_fields`.

The Schema Generator builds a single JSON-coercion prompt:

> "Given this post (title, content, detected type `{type}`), generate the schema.org JSON-LD for that type. Required fields for `{type}`: `{schema_required_fields}`. Optional but recommended: `{schema_optional_fields}`. Return a single JSON object with `@context`, `@type`, and all populated fields. Use empty arrays / null for fields you cannot derive from the post — do not invent."

Output runs through `SWPS_AI_Provider::chat_json()` (existing 5-stage repair pipeline).

### 5.3 Validation

Before saving, generated JSON is validated against a bundled minimal SHACL-like check:

- All `required_fields` for the type must be non-empty.
- `@context` must be `https://schema.org`.
- `@type` must match the detected type.
- URLs must be absolute and `wp_http_validate_url()`-passing.
- Dates must be ISO 8601.

Validation failures: schema portion is dropped from the proposal; the edits + inserts still run; the diff modal shows a "Schema generation failed validation — skipped" notice.

### 5.4 Rendering

`SWPS_Schema` gains 5 methods (one per type). Each:

1. Hooks `wp_head` at priority 10 (existing schema priority).
2. Reads `_swps_aeo_schema_type` and `_swps_aeo_schema_json` for the current post.
3. Outputs the JSON-LD inside a `<script type="application/ld+json">` block.
4. Only fires when:
   - The type matches the method (e.g. `schema_recipe()` only fires when `_swps_aeo_schema_type === 'recipe'`).
   - The type is in `swps_aeo_enabled_schema_types`.
   - Yoast / RankMath / AIOSEO is NOT active (existing `is_schema_deferred()` check).
5. Runs through filter `swps_schema_{type}` for theme/plugin overrides.

## 6. REST API

All endpoints live under namespace `swps/v1`, prefix `/aeo`. All require `edit_posts` capability + nonce.

| Method | Route | Body | Returns |
|---|---|---|---|
| POST | `/aeo/scan-batch` | `{ post_type?, offset, limit }` | `{ scored: int, next_offset: int, total: int, done: bool, results: [{post_id, score, subscores}] }` |
| GET | `/aeo/score/{id}` | — | `{ score, subscores, schema_type, last_scan }` or 404 |
| POST | `/aeo/proposal/{id}` | `{ force_coverage?: bool }` | `{ proposal: {...}, projected_score, projected_subscores, cost_usd, tokens_in, tokens_out }` |
| POST | `/aeo/apply/{id}` | `{ selected_edits: [int], selected_inserts: [int], selected_schema: bool }` | `{ new_score, new_subscores, applied_count }` (snapshot is stored under `_swps_aeo_snapshot`; v4.6 retains the latest only) |
| POST | `/aeo/undo/{id}` | — | `{ restored: bool, score, subscores }` |
| POST | `/aeo/dismiss/{id}` | `{ dismissed: bool }` | `{ ok }` |

All endpoints record AI cost (when LLM call made) via `SWPS_Cost_Tracker::record()` and respect `SWPS_Rate_Limiter` cooldown.

## 7. UI / UX

### 7.1 Admin page (`Create → AEO Optimize`)

Layout mirrors `templates/auto-optimize-page.php`:

```
┌─ Header bar ──────────────────────────────────────────────┐
│ AEO Optimize                                              │
│ Score posts on AI-citeability. Optimize what's below 70.  │
│                              [Re-scan all]   [Settings ↗] │
└───────────────────────────────────────────────────────────┘
┌─ Stats tiles ─────────────────────────────────────────────┐
│ 142 scored │ 38 below threshold │ Avg 74 │ Lowest dim:    │
│                                          │ Markup 61      │
└───────────────────────────────────────────────────────────┘
┌─ Filters ─────────────────────────────────────────────────┐
│ Threshold: [70 ▾]   Post type: [All ▾]   Sort: [Score ▾]  │
└───────────────────────────────────────────────────────────┘
┌─ Queue (table) ───────────────────────────────────────────┐
│ ● 42 │ How to make sourdough bread       │ E:30 M:40 A:60 C:- │
│       │ /how-to-make-sourdough/           │ [Generate proposal]│
│ ● 51 │ Best running shoes 2026           │ E:60 M:30 A:80 C:- │
│       │ /best-running-shoes-2026/         │ [Generate proposal]│
│ ● 65 │ Why your plants are dying         │ E:80 M:50 A:70 C:- │
│       │ /why-plants-die/                  │ [Generate proposal]│
│ ...                                                       │
└───────────────────────────────────────────────────────────┘
```

Row states: not-yet-proposed → "Generate proposal"; proposed → "Review (8 changes)"; applied recently → "Applied · undo".

### 7.2 Diff review modal

```
┌─ Review AEO proposal: "How to make sourdough bread" ──────┐
│ Projected: 42 → 87 (+45)                                  │
│ E: 30→90  M: 40→95  A: 60→75  C: --→80                    │
│                                                           │
│ ── Schema (new) ───────────────────────────────────────── │
│ [✓] Add HowTo schema (4 required steps detected)          │
│      ▾ Preview JSON-LD                                    │
│                                                           │
│ ── Structural inserts (3) ─────────────────────────────── │
│ [✓] Q&A pair after H2 "Sourdough basics"                  │
│      Q: How long does sourdough need to ferment?          │
│      A: A typical bulk ferment runs 4-6 hours at...       │
│ [✓] TL;DR box at top (110 words)                          │
│ [ ] Convert paragraph 3 into a 6-step ordered list        │
│                                                           │
│ ── Edits (5) ──────────────────────────────────────────── │
│ [✓] ¶1: "you might want to try" → "Try"  (diff view)      │
│ [✓] H2#2: "Some thoughts on flour" → "Choosing flour"     │
│ ...                                                       │
│                                                           │
│ Estimated AI cost: $0.04 (already incurred)               │
│                                                           │
│        [Apply selected (8)]   [Cancel]   [Discard prop.]  │
└───────────────────────────────────────────────────────────┘
```

Each insert/edit is independently checkable. Schema preview expands to a syntax-highlighted JSON block.

### 7.3 Live editor panel

Gutenberg sidebar plugin registered via `@wordpress/plugins`, icon: small AEO badge. Classic editor: standard `add_meta_box` slot on the side column.

```
┌─ AEO Score ───────────────────────┐
│         ╭─────╮                   │
│         │ 74  │   Above threshold │
│         ╰─────╯                   │
│                                   │
│ Extractability   ████████░░  80   │
│ Markup           █████░░░░░  50   │
│ Authority        ███████░░░  70   │
│ Coverage         ░░░░░░░░░░  --   │
│                                   │
│ [Improve AEO]   [Re-score]        │
│                                   │
│ Last scan: 2 min ago              │
└───────────────────────────────────┘
```

"Improve AEO" opens the same diff modal as the queue page.

### 7.4 Settings tab

Under `System → Settings → AEO`:

- **AEO threshold** (slider 50-95, default 70) — posts below this score appear in the queue.
- **Dimension weights** (4 sliders, must sum to 1.0; UI auto-normalizes).
- **Enable Coverage scoring** (toggle + cost note: "Adds one AI call per post when re-scoring or generating a proposal — about $0.001-0.003 per post depending on provider").
- **Enabled schema types** (5 checkboxes: HowTo, Recipe, Product, Review, QAPage).
- **Post types to score** (multi-select; default `post`, `page`).
- **Schema coexistence override** (existing checkbox now also applies to AEO schema types).

## 8. Hooks

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `swps_aeo_score` | filter | `($total, $post_id, $subscores)` | Override final score (e.g. to ignore certain post types). |
| `swps_aeo_subscores` | filter | `($subscores, $post_id)` | Modify individual sub-scores before they roll up. |
| `swps_aeo_proposal` | filter | `($proposal, $post_id)` | Modify the AI-returned proposal before user review. |
| `swps_aeo_schema_json` | filter | `($json, $type, $post_id)` | Modify generated schema JSON before validation/save. |
| `swps_aeo_dimensions` | filter | `($dimensions)` | Add or remove scoring dimensions (advanced — for third-party extensions). |

## 9. Coexistence

- **Yoast / RankMath / AIOSEO active** — all AEO schema rendering defers (matches existing pattern). Scoring still runs and the optimizer is still useful (edits + inserts still apply); only the schema portion of proposals is hidden in the diff modal with a one-line "Disabled because Yoast is active" notice. Override toggle in settings forces output.
- **Existing FAQ schema** — if a post already has FAQ schema via the plugin's existing FAQ feature, the Q&A density score reflects it; the Schema Generator will not propose duplicate `FAQPage`.
- **Existing Content Scorer** — runs independently. The two scores are displayed side-by-side in the meta editor; neither blocks the other.
- **Migration tool** — no interaction needed; AEO meta is generated post-migration on next scan.

## 10. Cost & performance budget

| Operation | Cost (USD) | Latency |
|---|---|---|
| Heuristic scan, 1 post | $0 | ~15-50 ms |
| Heuristic scan, 100 posts (one batch run) | $0 | ~5 s wall-clock (10×10 batches) |
| Coverage scorer, 1 post | ~$0.001-0.003 (depends on provider/model) | 2-6 s |
| Generate proposal, 1 post | ~$0.02-0.08 (one large prompt, JSON output) | 5-15 s |
| Schema generation (folded into proposal call) | included | included |
| Re-score after apply | $0 (heuristic) + Coverage if stale | <100 ms + Coverage |

Bulk scanning 1000 posts with Coverage off: ~$0, ~50 s total wall-clock.
Bulk scanning 1000 posts with Coverage on: ~$1-3, ~30-100 min wall-clock (chunked via background processor).

## 11. Error handling

| Failure | Behavior |
|---|---|
| AI JSON parse failure | 5-stage repair (existing); on failure save raw to debug transient + toast "Generation failed — check Debug page". |
| Coverage scorer LLM timeout | Fall back to cached value (or null); mark dimension "stale" in UI. |
| Schema validation failure | Schema dropped from proposal; edits + inserts still applied; notice in modal. |
| Edit conflict (find string 0 or >1 matches) | Skip the edit; log to browser console; continue with rest. |
| Apply failure mid-pipeline | Rollback via snapshot meta; surface error toast; queue row unchanged. |
| Rate-limit hit | Existing cooldown error surfaced in toast; row state unchanged. |
| Post deleted between scan and proposal | API returns 404; queue row removed on next render. |
| Background processor crash mid-scan | Resumable: `swps_aeo_last_scan` per post means re-running the batch starts where it left off. |

## 12. Testing

| Layer | Coverage |
|---|---|
| **Unit — sub-scorers** | 8 fixture posts per scorer (high/low/edge). 32 fixture posts total in `tests/fixtures/aeo/`. |
| **Unit — schema generator** | 5 fixture posts (one per type) + 3 ambiguous fixtures. Validated against real schema.org JSON-LD samples. |
| **Integration — full pipeline** | scoring → proposal → apply → re-score round trip; verifies post content + meta + snapshot. |
| **Integration — Schema rendering** | Mocks active state of Yoast/RankMath/AIOSEO; asserts schema deferral behavior. |
| **REST** | All 6 endpoints via `WP_Test_REST_TestCase`; permission + nonce + payload validation. |
| **JS** | Diff modal, queue interactions, editor panel via existing Jest setup (if present) or Playwright smoke. |
| **Manual smoke** | Run against a local site with 50 posts, verify Google Rich Results Test passes on generated schema for one post per type. |

## 13. Rollout

- **Migration on activation:** none required — all state is opt-in. First load of AEO Optimize page shows "No posts scored yet — click Re-scan" empty state.
- **Backwards compat:** zero breakage — every change is additive. Existing schema unchanged unless the user has AEO schema enabled AND the post has been processed.
- **Settings defaults:** Coverage off, all 5 schema types enabled, threshold 70, weights as in §4.1, post types `post` + `page`.
- **Dashboard integration:** new "AEO health" tile on the existing dashboard (additive, links to AEO Optimize page).
- **Bot Analytics integration:** AEO gap report rows get a deep-link to the optimizer filtered to that post.

## 14. Versioning & deliverables

Per the user's standing rule:

- Bump `* Version:` in `stratawp-seo.php` header and `SWPS_VERSION` constant to `4.6.0`.
- Update `readme.txt` Stable tag and add a Changelog entry.
- Update `README.md` — add to the Changelog section and add a new feature section "AEO Optimize (v4.6) ★".
- Update `docs/` if any reference docs touch schema or scoring.
- Build `stratawp-seo-4.6.0.zip` and overwrite `stratawp-seo.zip`.

## 15. Open questions (none blocking)

- Should the AEO Optimize page also expose a "Force re-score with Coverage" bulk button, or is per-row sufficient? (Default: per-row; bulk Coverage is opt-in via the same flow plus a small batch button at the top.)
- Should the Schema Generator allow a "manual paste" override, where the user supplies their own JSON? (Default: no in v4.6; revisit if users ask.)
- Should we cap the queue at N posts to avoid pathological lists on very large sites? (Default: yes, 200 rows max with "Show next 200" pagination.)

These don't block the spec; they're decisions to make during implementation.
