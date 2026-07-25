# Owned Domains — Cross-Site Link Suggestions

**Date:** 2026-07-25
**Status:** Approved (design supplied by owner)

## Goal

Let the internal-link engines suggest links between sites the owner controls
(e.g. `stratawpdev.com` ↔ `jonimms.com`). Today every candidate must be a
local post: `SWPS_Internal_Links::detect_existing_links()` rejects any href
not starting with `home_url()` or `/`, and both engines only ever see local
post IDs.

## Constraints discovered in the codebase

- `swps_link_graph` keys targets by **local post ID** (`target_post_id`
  BIGINT, unique key on source+target). Cross-site targets have no local ID,
  so they must NOT be forced into the graph table — no schema migration.
- Unit tests are pure PHP (no WP bootstrap); WP functions are stubbed per
  test file. Core matching logic must therefore live in pure static methods.
- Settings page uses the WP Settings API under the `stratawp-seo` group;
  complex (array) options are registered directly with a custom render
  callback (precedent: `swps_aeo_weights`).

## Design

### 1. Setting: `swps_owned_domains`

- Stored as an array of normalized origins (`https://host[:port]`).
- UI: textarea (one domain per line) in a new **Cross-Site Linking** section
  on the settings page (SEO tab). Bare hosts get `https://` prepended;
  paths/queries stripped; invalid entries and the site's own host dropped.
- Sanitizer is a pure static: `SWPS_Cross_Site_Links::sanitize_owned_domains()`
  (accepts textarea string or array). `www.` is ignored when comparing hosts.
- Changing the option flushes cached inventories.

### 2. New class `SWPS_Cross_Site_Links` (includes/class-cross-site-links.php)

- `get_owned_domains(): array` — option, minus own host.
- `get_inventory(): array` — merged remote post inventory. Per-domain
  transient `swps_cross_site_inv_<md5(origin)>`, `DAY_IN_SECONDS`; fetches
  `{origin}/wp-json/wp/v2/posts?per_page=100&_fields=title,link,excerpt`.
  Fetch failures cache an empty list for `HOUR_IN_SECONDS` so a down site
  isn't hammered. Items: `['url','title','excerpt','domain']`.
- `is_owned_url( string $url ): bool` / pure static
  `url_matches_domains( string $url, array $origins ): bool` — host match,
  scheme-insensitive, `www.`-insensitive, exact host (no subdomain match).
- `find_candidates( int $post_id, float $threshold, int $limit ): array` —
  scores inventory against the source post's indexed terms (from
  `swps_link_index`) using the keyword engine's tokenizer. Pure static core:
  `score_candidates( array $term_weights, array $inventory, callable $tokenize, float $threshold, int $limit )`.
  Title tokens weighted 1.0, excerpt tokens 0.3 (mirrors engine weights),
  normalized against the best score. Results carry `cross_site => true`.
- Per-post state in post meta (graph table untouched):
  - `_swps_cross_site_existing` — `[ ['url','anchor'], … ]`, rebuilt on each
    `detect_existing_links()` run.
  - `_swps_cross_site_dismissed` — list of dismissed URLs.
  - `_swps_cross_site_ai` — AI enrichment keyed by URL
    (`score`, `anchor_text`, `rationale`).
  - Suggestions exclude existing + dismissed URLs.

### 3. Relax the same-host filter (`detect_existing_links`)

Owned-domain hrefs are no longer skipped: they're recorded into
`_swps_cross_site_existing` (they can't resolve via `url_to_postid()`).
Same-site behavior unchanged; unknown third-party hrefs still skipped.

### 4. Engine merges

- **Keyword path:** metabox render + `ajax_get_suggestions` call
  `find_candidates()` live (inventory is cached; scoring is in-memory).
  Cross-site suggestions are NOT persisted to the graph.
- **AI path:** `SWPS_Link_AI_Engine::analyze()` accepts mixed candidates:
  local (`post_id`) or cross-site (`url`,`title`,`excerpt`,`cross_site`).
  Cross-site candidates are sent with `url` as their identifier; the prompt
  instructs the model to echo whichever identifier a candidate had. Enriched
  cross-site results are stored in `_swps_cross_site_ai` by
  `ajax_deep_analysis()` and returned alongside local results.

### 5. UI

- Metabox: cross-site suggestions render in the Suggested list with a
  `Cross-site` badge showing the host; existing cross-site links appear in
  the Existing list with the same badge. `<li>` carries
  `data-cross-site="1"` + `data-target-url`.
- JS: insert reuses the existing content-insertion path (`data-target-url`);
  bookkeeping AJAX branches to `swps_link_cross_insert` /
  `swps_link_cross_dismiss` (URL-based) for cross-site items.
- Admin CSS: small badge style.

### 6. Out of scope (YAGNI)

- No graph-table schema change, no cross-site rows in link-health stats.
- No `/pages` inventory, no pagination past 100 posts per domain (v1).
- No generation-prompt enrichment with cross-site links.

## Testing

Pure-PHP unit tests (existing bootstrap conventions, WP stubs per file):

- `CrossSiteLinksTest` — origin normalization, sanitizer (string + array
  input, invalid entries, dedupe), `url_matches_domains` (scheme/www/port/
  subdomain cases), inventory response parsing, candidate scoring
  (threshold, ordering, limit, exclusions).
- `InternalLinksDetectTest` — the filter change: same-site absolute and
  relative hrefs still recorded in the graph (fake `$wpdb`), foreign hrefs
  still skipped, owned-domain hrefs recorded to cross-site meta and kept out
  of the graph.

`composer check` (phpcs + PHPStan level 5) must pass; PHPStan baseline not
extended.
