# Editor sidebar: live analysis, multi-keyword and AI visibility

Date: 2026-10-08
Status: Draft for review

## Goal

Make the in-editor experience clearly better than Yoast and Rank Math, and put
the plugin's real differentiator (AI visibility, AEO, AI-assisted fixes) in front
of the writer at the moment of writing. Today the editor UI is a classic
metabox driven by jQuery (`admin/js/meta-editor.js`, `admin/js/aeo-editor-panel.js`).
There is no block editor sidebar, scoring is on demand through admin-ajax, and
only one focus keyword is first class.

Success looks like:

- A `PluginSidebar` in the block editor that updates as the writer types.
- Multiple keywords per post, each with its own checks.
- AEO score, query fan-out checklist and citation status visible in the editor.
- Failing checks offer a "Fix" that previews a diff and applies through the
  editor store, so undo works.
- Existing posts, post meta and Yoast/Rank Math imports keep working with no
  migration.

## Out of scope

- Proof dashboards and reports (before/after, AI Overview and citation trends,
  auto-fix queue). Separate spec. This spec only adds the snapshot hook so data
  starts accumulating.
- Breadth items: extra schema types, WooCommerce, video and news sitemaps,
  bulk edit.
- Rewriting existing admin screens. They stay jQuery.

## Architecture

### Build tooling

- Add `package.json` with `@wordpress/scripts`. Sources in `src/editor/`,
  output in `admin/editor/` (not `build/`: `bin/build-zip.sh` uses `build/` as
  its zip output directory and excludes it from the packaged zips).
- `admin/editor/` is committed so the wp.org zip and release zip need no Node
  step on the user's side.
- `.github/workflows/release.yml` gains `npm ci && npm run build` before
  packaging. A CI check fails if `build/` does not match the sources.

### Sidebar

A `PluginSidebar` ("StrataWP SEO") plus a `PluginDocumentSettingPanel` in the
Document tab showing the score badge. Panels:

1. Search preview (Google desktop and mobile, social card)
2. Keywords (focus plus related, per-keyword results)
3. SEO checks (grouped: problems, improvements, good)
4. Readability
5. AI visibility
6. Schema (detected type and validation status)
7. Advanced (canonical URL, robots, breadcrumb title, social title,
   description and image, sitemap exclude, priority and change frequency).
   These fields live in the classic metabox today. Because the metabox is
   hidden in the block editor, the sidebar must carry them or they become
   unreachable.

### Two-tier analysis

- **Instant tier (JS).** Debounced (about 400 ms). Reads title, content, slug and
  meta from the `core/editor` store. Runs cheap checks only. Pure functions, no
  network.
- **Deep tier (REST).** AEO scoring, coverage fan-out, schema validation and
  anything needing the server or AI. Reuses the existing PHP scorers
  (`SWPS_AEO_Scorer`, `SWPS_Content_Scorer`, schema validator) through new REST
  routes. Runs on demand and on save.

### Single source of truth

One PHP check registry defines every check: `id`, `label`, `group`
(seo, readability, ai), `tier` (instant, deep), `severity`, `fix`
(none, rule, ai). It is exposed to JS through `wp_localize_script` and the REST
route. The JS instant tier implements only the instant subset. A parity test
runs a shared fixture set through PHP and JS and asserts identical results.

### Data

- Existing meta keys unchanged (`_swps_focus_keyword` and related).
- Meta exposed via `register_post_meta` with `show_in_rest`.
- One new meta key for related keywords (array, max 4).
- No migration. Yoast/Rank Math imports unaffected.

### Fallback

The classic metabox registers only when the block editor is not active for the
post (Classic Editor plugin, post types without REST support). No duplicated
UI in Gutenberg.

## Checks and scoring

### Instant checks, per keyword

Keyword in title, meta description, slug, first paragraph, an H2, image alt.
Title and description length. Density with a stuffing warning. Content length
against the post type minimum. Internal and external link presence. Unique
keyword (not another post's focus keyword), reusing cannibalization data.

### New checks

Keyword in intro and conclusion. Keyword position in title. Transition word
and passive voice ratios. Sentence and paragraph length distribution.
Consecutive sentence starters. Subheading distribution. Image count and
missing alt text. Outbound link rel sanity. Readability checks are per
language and show a "not analyzed" state for unsupported languages, never a
silent pass.

### Multi-keyword

One focus keyword plus up to 4 related keywords. Each gets its own check list.
The post score weights the focus keyword fully and related keywords at reduced
weight. Suggestions come from Search Console queries for the URL (when
connected) and the AI engine.

### Scoring

- 0 to 100 post score with traffic-light status, plus SEO, Readability and AI
  Visibility sub-scores.
- Weights keep the existing 7 dimensions so old posts do not swing.
- During rollout the Document panel shows old and new scores when they differ
  by more than 10 points. Remove once stable.
- Thresholds (minimum words, title range) come from per-post-type presets with
  defaults for posts, pages and products.

## AI visibility panel

- AEO score and four dimensions (Extractability, Markup, Authority,
  Coverage) via REST from existing scorers. Coverage stays cached by content
  hash.
- Query fan-out checklist, each sub-question answered, partial or missing. A
  missing item has "Add an answer", which drafts a short block and inserts it
  at the cursor or the end of the closest section.
- Citation status for the focus keyword from citation tracking (ChatGPT,
  Claude, Gemini, Grok) with last-checked date. With no data, show that and
  offer to add the keyword to the tracker. Never show an invented status.

## Fix it flow

1. Failing check shows "Fix". Rule fixes are instant, AI fixes show a spinner.
2. Sidebar shows a diff of the proposal with Apply and Dismiss.
3. Apply writes through the editor store (`editPost`, `replaceBlocks`), so
   Cmd+Z undoes it.
4. Instant checks re-run and the score updates.

Guardrails:

- AI fixes go through the Autopilot Guardian budget cap and cost tracker. The
  button shows an estimated cost when the model price is known.
- Nothing is written without an explicit Apply.
- A proposal is validated before display: its target text must still exist in
  the current content. If content changed, discard with "content changed, try
  again".
- Provider requests follow the plugin's existing privacy rules.

## REST surface

- `POST /swps/v1/editor/analyze` (deep tier)
- `POST /swps/v1/editor/fix` (proposal)

Both require `edit_post` on the specific post and a nonce, consistent with the
existing abilities permission model.

## Proof snapshot hook

When a Fix is applied and the post is next published or updated, store a
snapshot: score, focus keyword position and clicks from Search Console (when
available), timestamp, and the fix ids applied. Storage only. Reporting UI is a
separate spec.

## Error handling

- Instant tier never blocks typing. A throwing check shows "couldn't run" and
  the others still render.
- Deep tier failures (no API key, budget cap, provider timeout, offline) show a
  specific inline message with retry. Budget-cap hits link to the budget
  setting. No blank panels.
- SEO fields save through normal post meta, so a crash never loses them.

## Testing

- PHPUnit: check registry, each new check, REST permission and nonce paths,
  fix proposal validation.
- Jest via `@wordpress/scripts`: instant checks.
- Parity test: shared fixtures through PHP and JS, identical results.
- Playwright smoke test on the local site: open editor, type, score moves,
  apply a rule fix, undo it.
- phpcs and phpstan stay green. CI verifies `admin/editor/` matches sources.

## Rollout

- Setting "New editor sidebar": on by default for new installs, off by default
  for upgrades for one release, with a one-click switch in an onboarding
  notice. The classic metabox is removed the release after that.
- Version bump in the three required places (`stratawp-seo.php` header,
  `SWPS_VERSION`, `readme.txt` Stable tag plus changelog). README and
  screenshots updated.

## Decomposition

1. This spec: sidebar, check registry, multi-keyword, AI visibility panel, Fix
   it flow, REST routes, snapshot hook.
2. Next spec: proof dashboards and reports.
3. Later, optional: breadth items.

## Repo conventions

Commits and PRs are authored as the repo owner with no AI attribution or
co-author trailers (see `CLAUDE.md`). Stage explicit paths only.
