# Site Audit Fix-It Engine — Design

**Date:** 2026-08-21
**Target release:** 4.28.0 (flagship feature)
**Status:** Approved scope; spec for implementation planning

## Problem

The 4.27.0 Site Audit finds up to 28 kinds of issues across a crawled
site and tells the user *how* to fix each one — but every fix is manual.
For the highest-volume issues (missing/duplicate/too-long meta titles
and descriptions, images without alt text), the plugin already contains
the machinery to generate the fix; the audit just doesn't connect to it.

The Fix-It engine makes audit findings actionable: AI-drafted fixes
reviewed and applied from the Site Audit dashboard, and safe mechanical
fixes applied in one click — all with undo.

## Goals

- One-click path from "18 pages missing meta description" to fixed pages.
- AI drafts are always reviewed before apply; nothing content-facing is
  silently written by AI.
- Mechanical fixes (https rewrite, nofollow strip, alt text) apply
  directly but snapshot first and offer undo.
- No new frameworks: server-rendered PHP + small vanilla JS, chunked
  AJAX batching, matching the existing Site Audit screen.

## Non-goals (v1)

- Content edits: `missing_h1`, `duplicate_h1`, `low_word_count`,
  `low_text_html_ratio` — deferred to v2 via the Auto-Optimize proposal
  machinery.
- `canonical_mismatch` auto-fix — too risky to automate.
- `broken_link` redirect creation — needs a human-chosen target; the
  existing `swps_crawl_create_redirect` flow on the legacy screen stays.
- Site-level/theme checks (viewport, doctype, charset, lang,
  compression, minification, hreflang, redirect chains/loops,
  challenge pages) — remain advisory.
- Orphan-page link building — possible v2 stretch via
  `SWPS_Link_AI_Engine`.
- Changes to the older `includes/audit/*` module system. It keeps its
  own `auto_fix()` flow untouched.

## V1 fixable checks

| Check id | Kind | Write target |
|---|---|---|
| `missing_title` | AI draft | `_swps_meta_title` (post or term meta) |
| `title_too_long` | AI draft | `_swps_meta_title` |
| `title_too_short` | AI draft | `_swps_meta_title` |
| `duplicate_title` | AI draft | `_swps_meta_title` on the duplicates |
| `missing_meta_description` | AI draft | `_swps_meta_description` |
| `desc_too_long` | AI draft | `_swps_meta_description` |
| `duplicate_meta_description` | AI draft | `_swps_meta_description` |
| `image_missing_alt` | Mechanical* | `_wp_attachment_image_alt` via `SWPS_Image_SEO::generate_alt_for()` |
| `mixed_content` | Mechanical | `post_content` http→https rewrite of asset URLs |
| `nofollow_internal_link` | Mechanical | `post_content` strip `rel="nofollow"` on internal links |
| `noindex_in_sitemap` | Mechanical | `_swps_sitemap_exclude` (existing fix, resurfaced in new UI) |

\* Alt text routes through the existing generator, which respects the
user's configured mode (AI or heuristic). It applies directly like the
other mechanical fixes because alt text is invisible page furniture with
an undo path; this is the same posture as the existing
`SWPS_Image_SEO::ajax_fix()` batch flow.

## Architecture

### 1. Issue → WordPress object resolution (prerequisite)

Crawl issues currently store only a URL; the `post_id` column exists but
is never populated. Fixes need a WP object, and ~25% of crawled URLs
are term/author archives, so a bare post id is not enough.

- Add `object_type VARCHAR(10)` (`post` | `term` | `user` | `none`) and
  `object_id BIGINT` to `swps_crawl_issues`. Bump
  `SWPS_Crawl_Issues::DB_VERSION` to `'4'`; dbDelta adds the columns.
- New pure helper `SWPS_Crawl_Target::resolve( string $url ): array`
  returning `{ object_type, object_id }`:
  - `url_to_postid()` for posts/pages (strip pagination first);
  - term archives resolved by matching the URL against
    `get_term_link()` for public taxonomies (cached per-run map built
    once, not per URL);
  - author archives via `author` rewrite matching;
  - home/archive/unknown → `none` / `0`.
- `SWPS_Site_Crawler::process_chunk()` resolves each crawled URL once
  and passes the target into every `insert_issue()` for that page
  (all three call sites).
- Old rows keep NULL targets and simply aren't fixable until the next
  crawl. No data migration; runs are pruned to the last 5 anyway.
- `detail['duplicate_of']` sibling URLs (duplicate title/description
  aggregate checks) are resolved lazily at fix time with the same
  helper.

### 2. Fixer registry

Checks stay pure — no fix logic enters `SWPS_Crawl_Check`. New files:

- `includes/crawl-fixers/class-crawl-fixer.php` — abstract base:
  - `check_id(): string` — which check this fixer handles.
  - `kind(): string` — `'draft'` (AI, review-before-apply) or
    `'mechanical'` (direct apply).
  - `can_fix( array $issue ): bool` — has a usable target, object still
    exists, etc.
  - `draft( array $issue ): array|WP_Error` — draft fixers only:
    returns `{ current, proposed, meta }` for review.
  - `apply( array $issue, array $accepted ): array|WP_Error` — writes
    the fix. Snapshots first. Returns `{ changed, message }`.
  - `undo( array $issue ): bool` — restores the snapshot.
- `includes/crawl-fixers/class-crawl-fixer-registry.php` — hardcoded
  map of check id → fixer class (mirrors
  `SWPS_Crawl_Check_Registry`), `for_check( string $id )`,
  `fixable_ids()`.
- Concrete fixers (one file each):
  - `class-fixer-meta-title.php` — handles the four title checks.
  - `class-fixer-meta-description.php` — handles the three description
    checks.
  - `class-fixer-image-alt.php` — resolves `detail['images']` URLs to
    attachment ids via `attachment_url_to_postid()` (skipping
    non-library images), calls `SWPS_Image_SEO::generate_alt_for()`.
  - `class-fixer-mixed-content.php` — pure static rewriter over
    `post_content`.
  - `class-fixer-nofollow.php` — pure static rel-attribute stripper
    (internal links only).
  - `class-fixer-sitemap-exclude.php` — wraps the existing
    sitemap-exclusion write.

All new files get `require_once` lines in `stratawp-seo.php` (no
runtime autoloader in this plugin).

### 3. AI drafting

- Provider via `SWPS_Provider_Factory::create_ai_provider()`, using
  `chat_json()` (the newer pattern — not the legacy regex-over-`chat()`
  in `ajax_generate_meta`).
- Title/description prompts include: post/term title, content excerpt
  (≤2000 chars stripped), focus keyword if set, the length constraint
  the check enforces, and — for duplicate checks — the sibling titles
  to differentiate from.
- Batch drafting is chunked AJAX with an offset cursor (the codebase's
  standard batch idiom), default 5 posts per request, capped.
- Cost: `_usage` from `chat_json()` accumulates through the existing
  cost-tracker plumbing and is shown on the review panel.

### 4. Draft storage, apply, undo

Copies the proven `SWPS_AEO_Optimizer` pattern:

- Drafts stored per object in meta:
  `_swps_fixit_drafts` — a JSON **map keyed by field**
  (`meta_title`, `meta_description`), so a post with both a title and
  a description issue holds both drafts without one clobbering the
  other. JSON string `wp_slash()`-wrapped before `update_post_meta` —
  required, or quotes are eaten. Term targets use identical term-meta
  keys.
- Per-field draft shape:
  `{ check_id, run_id, current, proposed, drafted_at, usage }`.
- On apply: **merge** into `_swps_fixit_snapshot` the prior values of
  every field being changed (including `post_content` for mechanical
  content fixes) — merge, not overwrite, so sequential applies to the
  same object keep the earliest original for each field — then write
  the fix, then remove that field's draft from the map.
- Undo restores the snapshot verbatim and deletes it. Snapshots expire
  via a weekly sweep cron (`swps_fixit_sweep`), same as AEO's
  `sweep_proposals()`.
- A fixed issue row gets `detail['fixed_at']` stamped so the dashboard
  can render it as resolved-pending-recrawl; the health score is never
  recomputed from fixes — only a *projected* score is displayed, and
  the next crawl is the source of truth.

### 5. AJAX surface

Thin nonce/capability wrappers over testable `do_*()` methods
(`manage_options`, per-verb nonces):

- `swps_fixit_draft_chunk` — POST `run_id`, `check_id`, `offset` →
  drafts the next N targets, returns `{ drafted, remaining, cost }`.
- `swps_fixit_apply` — POST `run_id`, `check_id`,
  `accepted[]` (object refs) → applies accepted drafts / runs the
  mechanical fix, returns `{ fixed, skipped[], messages }`.
- `swps_fixit_undo` — POST object ref → snapshot restore.
- `swps_fixit_dismiss` — POST object ref → deletes the draft.

Mechanical fixes use `swps_fixit_apply` directly (no draft phase),
batched with the same offset-cursor loop for large groups.

### 6. UI (Site Audit screen)

Server-rendered additions to `SWPS_Site_Audit_Screen`, small additions
to `admin/js/site-audit.js` (stays vanilla, target ≤ ~200 lines total):

- `render_issue_group()` gains, for fixable groups: a
  **✨ Fix with AI** button (draft kinds) or **Fix now** button
  (mechanical kinds) beside Copy URLs / Exclude. Unfixable rows within
  a group (NULL target) are counted and excluded ("3 of 18 not
  auto-fixable — archive pages").
- Clicking Fix with AI starts the chunked draft loop with a progress
  bar (reuses the crawl-progress polling pattern), then reveals a
  **review table**: page, current value, proposed value, checkbox per
  row, Apply selected / Dismiss all. Cost shown.
- Applied rows swap to a ✓ with an **Undo** link.
- Group header shows "N fixed — re-crawl to verify"; the page header
  shows `Health 61 → projected 78` once fixes exist for the displayed
  run. Projection = current score recomputed with fixed issues
  excluded, clearly labeled *projected*.
- The header **Re-run audit** button is the verification loop's
  closing affordance.

### 7. Error handling

- `WP_Error` from the provider surfaces per-row in the review table;
  the batch continues past failures and reports
  `{ skipped: [reason] }` (AEO/Auto-Optimize convention).
- Objects deleted between crawl and fix: `can_fix()` returns false,
  row rendered as stale.
- Rate limiting: existing `SWPS_Rate_Limiter` wraps draft chunks.
- All writes sanitize with the same functions `SWPS_Meta_Editor` and
  `SWPS_Taxonomy_Meta` use for those keys.

## Testing

- Pure static helpers with WP-free unit tests (matching crawler/decay
  watchdog test style):
  - `SWPS_Crawl_Target::resolve()` URL classification (with injected
    lookup maps);
  - mixed-content rewriter (asset URLs only, no external hrefs, no
    false positives inside text);
  - nofollow stripper (internal-only, preserves other rel values);
  - draft normalization/validation;
  - projected-score computation.
- `do_*()` methods covered with the existing WP-stub test approach
  used by AEO tests.
- Manual smoke test on the LocalWP dev site (repo-symlinked): full
  crawl → draft → review → apply → undo → re-crawl, screenshots
  captured for the README refresh.

## Release

- Version 4.28.0: bump `stratawp-seo.php` (header + `SWPS_VERSION`),
  `readme.txt` stable tag + changelog.
- README refresh in the same release: re-capture all referenced
  screenshots on the dev site post-implementation, hero shot = Fix-It
  review flow; sync `.wordpress-org/screenshot-N.png`.
