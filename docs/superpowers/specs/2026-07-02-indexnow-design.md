# IndexNow Integration — Design Spec

## Overview

Add [IndexNow](https://www.indexnow.org/) support to StrataWP SEO so the site instantly
notifies participating search engines (Bing, Yandex, Seznam, Naver — **not** Google) whenever a
URL is published, updated, or removed, instead of waiting for the next crawl.

The feature is auto-submit-first (fires on the post/term lifecycle) with manual controls, mirrors
the sitemap's notion of "indexable URL" as a single source of truth, keeps a lightweight activity
log, and refuses to submit from non-production environments.

This fulfils the promise already advertised in the Sitemaps module description
(`includes/class-modules.php:92` — *"XML index, image sitemap, IndexNow batch submit."*). No prior
implementation or stub exists; this is greenfield.

### Decisions locked in brainstorming

| Decision | Choice |
|----------|--------|
| Trigger model | Auto-submit (default ON, toggleable) **plus** per-post "Submit now" and bulk "Resubmit all" |
| URL eligibility | Mirror the sitemap (posts, pages, CPTs, taxonomies, authors; honors excludes + noindex) |
| Activity log | Lightweight ring buffer (~50 entries) in an option — no DB table |
| Environment guard | Auto-skip when not production / host looks like staging |
| Debounce window | 60 seconds, coalesced into one batch POST |
| Endpoint | `https://api.indexnow.org/indexnow` aggregator (one POST fans out to all participants) |
| Per-post control location | SEO meta box (`SWPS_Meta_Editor`) |
| Version | 4.22.0 (new feature → minor bump) |

## 1. New Class: `SWPS_IndexNow`

New file `includes/class-indexnow.php` defining `SWPS_IndexNow`. It owns everything except the
persisted-setting **registration** (which goes through the settings framework) and the URL
**eligibility/enumeration** (which stays in the sitemap manager — see §2).

**Bootstrap wiring (mandatory — no runtime autoloader):**
- Add `require_once SWPS_PLUGIN_DIR . 'includes/class-indexnow.php';` to the require block in
  `stratawp-seo.php` (the block spanning ~lines 33–147, near `class-rate-limiter.php` @ line 63).
  A missing require is a **runtime-only fatal**; PHPStan will not catch it.
- Instantiate `new SWPS_IndexNow()` in `StrataWP_SEO::__construct()` (~lines 363–475), where
  `SWPS_Cron` and peers are constructed.

**Responsibilities:**
1. Key management (generate / rotate / read).
2. Serving the `/{key}.txt` verification file.
3. Post/term lifecycle hooks → enqueue.
4. The debounced queue + scheduled flush.
5. HTTP submission + response interpretation.
6. The activity-log ring buffer.
7. The environment guard.
8. AJAX handlers (generate key, submit-now, resubmit-all, fetch log).

Target < 500 lines (project convention). If it grows past that, split the AJAX/admin surface into a
sibling `SWPS_IndexNow_Admin` mirroring the `SWPS_Sitemap_Manager` / `SWPS_Sitemap_Admin` split.

## 2. Shared Eligibility (small sitemap refactor)

To make IndexNow submit exactly what the sitemap contains, add two **public** methods to
`SWPS_Sitemap_Manager` and refactor the existing private renderers to call the shared predicate so
output cannot drift:

```php
/** Is this published post part of the sitemap (single source of truth)? */
public static function is_post_indexable( WP_Post $post ): bool;

/** Full indexable URL set: posts + pages + CPTs + taxonomies + authors. */
public static function get_indexable_urls(): array; // returns list of absolute URLs (deduped)
```

- `is_post_indexable()` consolidates the current private checks: `post_status === 'publish'`,
  `! is_post_type_hidden_from_sitemap()` (`class-sitemap-manager.php:20-24`), no
  `_swps_sitemap_exclude` meta, and `_swps_robots` not containing `noindex`.
- `get_indexable_urls()` powers the bulk "Resubmit all" action, reusing the same eligibility rules
  plus `is_taxonomy_hidden_from_sitemap()` and the author `has_published_posts` filter.
- The private `render_post_type_sitemap()` / `render_taxonomy_sitemap()` per-item skip logic is
  refactored to call `is_post_indexable()` / a term equivalent — **behavior-preserving**.

**Regression guard:** `tests/unit/SitemapHomepageTest.php` and `SitemapUrlTest.php` must stay green
through this refactor; the homepage-dedup and pagination behavior in the renderers must be
untouched.

## 3. Key Management & Verification File

- **Option:** `swps_indexnow_api_key` — a **plain** (unencrypted) option. The key is public by
  design (served in cleartext at `/{key}.txt`), so it must **not** be added to the encryption
  whitelist in `SWPS_Settings::get_sanitize_callback()` (`class-settings.php:1307-1317`). Treat it
  like `swps_gsc_client_id` (plain), never like a secret.
- **Generation:** 32-char hex via `wp_generate_password( 32, false )` (or
  `bin2hex( random_bytes( 16 ) )`). Triggered by a "Generate key" AJAX action; regenerating rotates
  the key (the old `/{key}.txt` stops resolving, the new one starts — no code change needed because
  the filename is read from the option).
- **Verification file** — copy the proven `SWPS_AI_Bots::maybe_serve_llms_txt()` pattern
  (`includes/class-ai-bots.php:158-174`):
  - Hook `init` at **priority 1**.
  - `$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?? '';`
  - If `$path === '/' . $key . '.txt'` and a key exists: send `Content-Type: text/plain`,
    `X-Robots-Tag: noindex`, `nocache_headers()`, `echo $key;`, `exit;`.
  - **No rewrite rules, no `flush_rewrite_rules()`.** This deliberately sidesteps the sitemap's
    rewrite/flush lifecycle and needs no activation-time flush.

## 4. Triggers → Queue → Batch Submit

### 4.1 Lifecycle hooks (enqueue side)

| Hook | Handler behavior |
|------|------------------|
| `transition_post_status` | On `publish` (new/updated) → enqueue permalink. On `publish → non-public` → enqueue the stashed old URL (removal). |
| `before_delete_post`, `wp_trash_post` | Enqueue the stashed public URL (removal signal → 404/410). |
| `created_term`, `edited_term`, `delete_term` | Enqueue `get_term_link()` for eligible taxonomies. |

- **Removal URL stashing:** on each publish, store `_swps_indexnow_last_url` post meta with the
  current permalink. A status change fires *after* WP has dropped the pretty permalink, so the stash
  is the robust source for the now-dead URL to submit on unpublish/trash/delete.
- Every enqueue passes through **eligibility** (`SWPS_Sitemap_Manager::is_post_indexable()`) and the
  **environment guard** (§6) before touching the queue. Autosaves, revisions, and non-eligible post
  types are ignored.
- Enqueue is gated by `swps_indexnow_enabled` **and** `swps_indexnow_auto_submit`. Manual actions
  (§5) bypass `auto_submit` but still respect `enabled` + env guard.

### 4.2 Debounce queue

- **Queue store:** `swps_indexnow_queue` option — a set of absolute URLs, deduped on insert.
- On first insert of a burst, schedule `wp_schedule_single_event( time() + 60, 'swps_indexnow_flush' )`
  (only if not already scheduled) — the `SWPS_Site_Crawler` single-event pattern
  (`class-site-crawler.php:1025/1040`). A burst of saves coalesces into one POST.
- **No-op suppression:** stash the last-submitted `post_modified_gmt` in **post meta**
  (`_swps_indexnow_submitted`) — and term meta for taxonomy URLs — updated on each successful
  submit. Skip re-enqueuing a URL whose content has not changed since its last submit. Using
  per-object meta (not a global map option) keeps this naturally bounded and avoids the
  unbounded-option bloat footgun.
- **Does NOT reuse `SWPS_Rate_Limiter`** — that global 60s transient lock is shared with AI
  generation; reusing it would make IndexNow and AI generation gate each other.

### 4.3 Flush handler (`swps_indexnow_flush`)

1. Read + clear `swps_indexnow_queue`.
2. Re-check the environment guard (in case the site was cloned to staging between enqueue and flush).
3. Enforce a per-day host cap (`swps_indexnow_daily_count` + date) as a runaway backstop.
4. Chunk to ≤ 10,000 URLs/POST (spec max — almost always a single chunk).
5. Submit (§5), record each batch in the activity log (§7), stamp `_swps_indexnow_submitted` meta on
   the submitted objects (§4.2).

## 5. Submission & Manual Actions

### 5.1 HTTP submit

POST to `https://api.indexnow.org/indexnow` using the `SWPS_Search_Console` transport idiom
(`class-search-console.php:363-420`): `wp_remote_post()` → `is_wp_error()` guard →
`wp_remote_retrieve_response_code()` / `wp_remote_retrieve_body()`.

```php
wp_remote_post( 'https://api.indexnow.org/indexnow', [
    'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
    'body'    => wp_json_encode( [
        'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
        'key'         => $key,
        'keyLocation' => home_url( '/' . $key . '.txt' ),
        'urlList'     => $urls, // absolute URLs, same host
    ] ),
    'timeout' => 15,
] );
```

**Response interpretation for the log:**

| Code | Meaning |
|------|---------|
| 200 | OK — submitted |
| 202 | Accepted, key validation pending |
| 400 | Invalid request / bad key format |
| 403 | Key not found (verification file not served) |
| 422 | URLs don't match host, or key mismatch |
| 429 | Too many requests |
| `WP_Error` | Network failure — caught, logged, never fatal |

Google is **not** contacted (IndexNow excludes it). The UI states this plainly rather than implying
Google coverage.

### 5.2 Manual controls

| Control | Location | Behavior |
|---------|----------|----------|
| **Generate key** | Sitemaps admin page (§7) | AJAX → create/rotate `swps_indexnow_api_key` |
| **Submit to IndexNow now** | SEO meta box (`SWPS_Meta_Editor`) | AJAX → submit this post's permalink immediately (bypasses `auto_submit`, still respects `enabled` + env guard + eligibility) |
| **Resubmit all URLs** | Sitemaps admin page | AJAX → `SWPS_Sitemap_Manager::get_indexable_urls()` → enqueue/flush in ≤10k chunks |

AJAX handlers mirror `SWPS_Sitemap_Admin::ajax_get_sitemaps()` (`class-sitemap-admin.php:103`):
nonce check, capability check (`manage_options`), JSON response.

## 6. Environment Guard

Before any submit (auto or manual), skip and surface a notice when **either**:
- `wp_get_environment_type() !== 'production'`, or
- the host matches a staging/local pattern (`localhost`, `127.0.0.1`, `*.local`, `*.test`,
  `staging.*`, `dev.*`, `*.wpengine.com` staging, etc.).

The admin panel shows a persistent "IndexNow is paused on non-production" notice in this state so the
behavior is never silent/confusing. Manual actions return the same "paused on non-production"
message.

## 7. Admin UI & Settings

### 7.1 Persisted options (registered exactly once)

| Option | Type | Notes |
|--------|------|-------|
| `swps_indexnow_enabled` | checkbox (1/0) | Master toggle |
| `swps_indexnow_auto_submit` | checkbox (1/0) | Auto-fire on lifecycle (default 1) |
| `swps_indexnow_api_key` | text (plain) | **Not** encrypted |
| `swps_indexnow_post_types` | **multi_checkbox (array)** | Which post types auto-submit; defaults mirror sitemap-included types |

Registered through `SWPS_Settings::add_field()` (`class-settings.php:1268`) in a new
`swps_indexnow_section`. **Footgun (4.21.4 Products bug):** `swps_indexnow_post_types` is an array —
register it **once** only. Registering the same option a second time as a scalar stacks a
`sanitize_option_` filter that silently wipes the array on save. Every option here must appear in
exactly one `add_field()`/`register_setting()` call.

### 7.2 Admin surface

The IndexNow controls render as a **panel on the existing Sitemaps admin page**
(`SWPS_Sitemap_Admin` / `templates/sitemaps-page.php`) — fulfilling the module promise and where the
feature belongs conceptually. Panel contents:
- Enable + auto-submit toggles, post-type checklist.
- Key display + copy button + "Generate key".
- Verification file status (does `/{key}.txt` resolve?).
- "Resubmit all URLs" button.
- Environment-guard notice when paused.
- Activity-log table (last ~50 entries).

Options are registered via the settings framework (§7.1) but the controls are rendered on the
Sitemaps page (scoped rendering, à la `render_sections_for_tab()`), not duplicated on the main
Settings page — to keep a single registration per option.

## 8. Activity Log

- **Store:** `swps_indexnow_log` option — bounded ring buffer of the last ~50 entries. No DB table,
  no migration.
- **Entry shape:** `{ time (gmt), trigger (auto|manual|bulk), urls_count, http_code, result }`.
- Capping: on append, `array_slice()` to the last 50.
- Failures recorded consistently with `SWPS_Digest::record_failure()` (`class-digest.php:50`).

## 9. Activation / Deactivation

- **No rewrite flush needed** — the `/{key}.txt` route uses `init`+REQUEST_URI, not rewrite rules.
- On deactivate: clear the scheduled `swps_indexnow_flush` event
  (`wp_clear_scheduled_hook( 'swps_indexnow_flush' )`).
- `uninstall.php`: remove `swps_indexnow_*` options and the `_swps_indexnow_last_url` /
  `_swps_indexnow_submitted` post/term meta.

## 10. Testing

**Unit tests (`tests/unit/`):**
- `SWPS_Sitemap_Manager::is_post_indexable()` — publish/draft, excluded type, `_swps_sitemap_exclude`
  meta, `_swps_robots` noindex.
- Queue dedup + no-op suppression (unchanged `post_modified` skipped).
- Key-file path matching, including key rotation (old path 404s, new path serves).
- Environment guard skips on non-production / staging host.
- Log ring buffer caps at 50.
- **Existing sitemap tests stay green** (regression guard on the §2 refactor).

**Manual smoke test** on `jonimms.local` (symlinked dev site):
1. Generate key → confirm `/{key}.txt` serves the key with `text/plain`.
2. Publish a post → confirm it lands in `swps_indexnow_queue`, the `+60s` event is scheduled, the
   flush fires, and a log entry appears.
3. Confirm the env guard blocks submission on the local site (and the notice shows).
4. Exercise "Submit now" (meta box) and "Resubmit all" (Sitemaps page).

## 11. Release

Per the standing release ritual:
- Bump version to **4.22.0** in `stratawp-seo.php` header + `SWPS_VERSION`.
- Update `README.md`, `readme.txt` (changelog + feature list), and docs.
- Rebuild the deployment zip.

## 12. Out of Scope (explicit non-goals)

- **Google** submission — IndexNow excludes Google; no Indexing-API workaround in this feature.
- **DB-backed log** with retention/filtering — deferred; ring buffer is sufficient for v1.
- **Multisite network-admin** controls — per-site key/host works network-agnostically, but no
  network-level dashboard.
- **Sitemap-diff sweep cron** — not needed given lifecycle triggers + manual "Resubmit all".

## File Reference Index

| Concern | File / anchor |
|---------|---------------|
| New class | `includes/class-indexnow.php` (new) |
| Bootstrap require + instantiate | `stratawp-seo.php:33-147`, `:363-475` |
| Verification-file pattern | `includes/class-ai-bots.php:158-174` |
| Eligibility (to refactor) | `includes/class-sitemap-manager.php:20-24, 244-390` |
| HTTP transport idiom | `includes/class-search-console.php:363-420` |
| Settings field registration | `includes/class-settings.php:1268` (add_field), `:1658` (tabs) |
| Double-registration footgun | `includes/class-settings.php:855-859` |
| Encryption whitelist (avoid) | `includes/class-settings.php:1307-1317` |
| Debounce single-event pattern | `includes/class-site-crawler.php:1025/1040` |
| Lifecycle-hook analog | `includes/class-cache-manager.php:18-19` |
| Admin page + AJAX analog | `includes/class-sitemap-admin.php:27, 103` |
| Per-post control host | `includes/class-meta-editor.php` |
| Failure-log analog | `includes/class-digest.php:50` |
| Module promise being fulfilled | `includes/class-modules.php:92` |
