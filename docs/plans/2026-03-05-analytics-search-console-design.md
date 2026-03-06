# Analytics & Search Console — Design Document

**Date:** 2026-03-05
**Scope:** On-site analytics tracking (page views, time on page, scroll depth, bounce rate) + Google Search Console integration (OAuth, clicks, impressions, CTR, position) + unified analytics dashboard with per-post data.
**Approach:** Two-module architecture — `SWPS_Analytics_Tracker` for on-site tracking with custom DB tables, `SWPS_Search_Console` for GSC OAuth + API, `SWPS_Analytics_Dashboard` for unified admin UI.

---

## 1. Overview

Add native analytics and Google Search Console integration to StrataWP SEO so users can track how their content performs in search and on-site — without leaving WordPress.

On-site tracking is lightweight (~1KB vanilla JS), cookie-free, and GDPR-friendly. GSC integration uses OAuth with user-provided Google Cloud credentials. Both data sources feed into a unified dashboard with site overview charts and per-post metrics.

GA4 integration is deferred to a future release. The OAuth scope can be extended later without breaking changes.

---

## 2. On-Site Tracking (`SWPS_Analytics_Tracker`)

**File:** `includes/class-analytics-tracker.php`

### Frontend Snippet

~1KB vanilla JS injected via `wp_footer`. No jQuery. No cookies. No external calls.

Tracks:
- Page URL and post ID
- Referrer
- Time on page (beacon on `visibilitychange`/`beforeunload`)
- Scroll depth (max percentage, debounced)
- Bounce detection (left without interaction within 10 seconds)

Data sent to a lightweight AJAX endpoint via `navigator.sendBeacon()` with `fetch` fallback.

### Storage

**Raw hits table** `{prefix}swps_analytics`:

| Column | Type | Purpose |
|---|---|---|
| `id` | BIGINT AUTO_INCREMENT | Primary key |
| `post_id` | BIGINT | WordPress post/page ID |
| `page_url` | VARCHAR(500) | Full URL (for non-post pages) |
| `referrer` | VARCHAR(500) | HTTP referrer |
| `time_on_page` | SMALLINT | Seconds spent on page |
| `scroll_depth` | TINYINT | Max scroll percentage (0-100) |
| `is_bounce` | TINYINT(1) | 1 if bounce, 0 if engaged |
| `created_at` | DATETIME | Timestamp (indexed) |

**Daily summary table** `{prefix}swps_analytics_daily`:

| Column | Type |
|---|---|
| `post_id` | BIGINT |
| `date` | DATE |
| `views` | INT |
| `avg_time_on_page` | SMALLINT |
| `avg_scroll_depth` | TINYINT |
| `bounces` | INT |

**Composite index** on `(post_id, date)` for the daily table.

### Aggregation & Pruning

Daily cron job:
1. Roll raw hits older than 7 days into daily summary (aggregate by post_id + date)
2. Delete aggregated raw rows
3. Delete daily summary rows older than the retention setting

---

## 3. Google Search Console (`SWPS_Search_Console`)

**File:** `includes/class-search-console.php`

### OAuth Flow

1. User enters their Google Cloud OAuth Client ID and Client Secret in settings
2. User clicks "Connect to Google"
3. Plugin redirects to Google OAuth consent screen with scope `https://www.googleapis.com/auth/webmasters.readonly`
4. Google redirects back to plugin callback URL with auth code
5. Plugin exchanges code for access + refresh tokens
6. Tokens stored encrypted via `SWPS_Encryption`
7. Settings UI shows connected state with property selector and "Disconnect" button

**OAuth credentials are user-provided** — no centralized OAuth app needed. User creates their own Google Cloud project.

### Token Management

- Access token: short-lived (~1 hour), auto-refreshed via refresh token
- Refresh token: long-lived, stored encrypted
- `swps_gsc_token_expiry` timestamp checked before each API call
- If refresh fails, connection state reset, user prompted to re-authorize

### Data Fetched

GSC Search Analytics API for the selected property. Last 90 days.

| Metric | Description |
|---|---|
| Clicks | Total clicks from search |
| Impressions | Times shown in search results |
| CTR | Click-through rate |
| Position | Average ranking position |

**Dimensions:** query, page, date.

### Caching

- API responses cached as WordPress transients, 12-hour expiration
- "Refresh" button in dashboard clears cache and re-fetches
- Daily cron also refreshes data in background

### URL-to-Post Mapping

GSC returns data by URL. Plugin maps URLs to WordPress post IDs via `url_to_postid()` to join GSC data with on-site metrics in the dashboard.

---

## 4. Analytics Dashboard (`SWPS_Analytics_Dashboard`)

**File:** `includes/class-analytics-dashboard.php`

New submenu page "Analytics" under StrataWP SEO.

### Site Overview Tab

- **Metric cards (top row):** Total Page Views, Avg Time on Page, Search Clicks (GSC), Search Impressions (GSC). Each shows value + percentage change vs previous period.
- **Line chart:** Daily page views + search clicks over selected date range. Pure CSS/SVG rendering — no chart library.
- **Top Pages table:** Sortable — Page Title, Views, Avg Time, Scroll Depth, Bounce Rate, Clicks (GSC), Impressions (GSC), Avg Position (GSC). Top 20 with "View All".
- **Top Queries table (GSC):** Query, Clicks, Impressions, CTR, Position. Top 20.
- **Date range picker:** 7d, 30d, 90d, custom range. Default 30d.

### Per-Post Metabox

Appears in post editor sidebar:
- Views (7d / 30d)
- Avg Time on Page, Scroll Depth, Bounce Rate
- If GSC connected: top 5 queries driving traffic to this post with clicks and position

Single AJAX call on metabox render — no blocking.

### Post List Column

- "Views (30d)" column in Posts list table
- Sortable by view count

### Data Loading

All dashboard data via AJAX endpoints. No blocking queries on page load.

---

## 5. Settings

New section "Analytics" on the settings page.

| Option Key | Type | Default | Purpose |
|---|---|---|---|
| `swps_analytics_enabled` | Checkbox | `1` | Master toggle for on-site tracking |
| `swps_analytics_retention` | Select | `90` | Data retention: 30, 90, 180, or 365 days |
| `swps_analytics_exclude_admins` | Checkbox | `1` | Don't track logged-in administrators |
| `swps_gsc_client_id` | Text | (empty) | Google OAuth Client ID |
| `swps_gsc_client_secret` | Password | (empty) | Google OAuth Client Secret (encrypted) |
| `swps_gsc_property` | Select | (empty) | Selected GSC property (populated after OAuth) |

**Internal state (not user-editable):**
- `swps_gsc_access_token` — encrypted
- `swps_gsc_refresh_token` — encrypted
- `swps_gsc_token_expiry` — timestamp
- `swps_gsc_connected` — boolean

---

## 6. Developer Hooks

| Hook | Type | Parameters | Purpose |
|---|---|---|---|
| `swps_analytics_track` | Filter | `$data, $post_id` | Modify or block tracking data before storage |
| `swps_analytics_exclude` | Filter | `$exclude, $post_id` | Skip tracking for specific pages |
| `swps_gsc_data` | Filter | `$data, $property` | Modify GSC data after fetch |

---

## 7. Files

**Create (5):**

| File | Purpose |
|---|---|
| `includes/class-analytics-tracker.php` | On-site tracking, DB table management, aggregation cron |
| `includes/class-search-console.php` | Google OAuth, GSC API client, token management |
| `includes/class-analytics-dashboard.php` | Admin page, AJAX endpoints, metabox, post list column |
| `admin/js/analytics.js` | Dashboard charts, date picker, AJAX data loading |
| `admin/js/analytics-tracker.js` | Frontend tracking snippet (~1KB) |

**Modify (4):**

| File | Changes |
|---|---|
| `stratawp-seo.php` | Require classes, instantiate, activation hook (create tables), deactivation cleanup |
| `includes/class-settings.php` | Analytics section with 6 fields |
| `includes/class-hooks.php` | 3 new filter helpers |
| `admin/css/admin.css` | Dashboard card, chart, and metric styles |
