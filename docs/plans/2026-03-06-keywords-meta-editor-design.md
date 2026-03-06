# Keywords & Meta Editor Design

**Goal:** Add keyword research/tracking and per-post SEO meta editing to StrataWP SEO (v2.3.0).

**Architecture:** Two integrated modules sharing a focus keyword concept. AI suggests keywords, GSC tracks rankings, and the meta editor targets keywords with optimized titles/descriptions. Conflict detection auto-disables meta output when Yoast/RankMath/AIOSEO is active.

---

## Data Model

### Custom table: `swps_keyword_tracking`

| Column      | Type          | Notes                                      |
|-------------|---------------|--------------------------------------------|
| id          | BIGINT PK     | Auto-increment                             |
| keyword     | VARCHAR(255)  | The tracked keyword                        |
| post_id     | BIGINT NULL    | Linked post (nullable for unlinked keywords)|
| position    | FLOAT          | Average search position                    |
| clicks      | INT UNSIGNED   | Click count                                |
| impressions | INT UNSIGNED   | Impression count                           |
| ctr         | FLOAT          | Click-through rate                         |
| date        | DATE           | Data date                                  |

- Composite index on `(keyword, date)`
- Source: GSC pull on configurable schedule + AI-suggested keywords stored with null position until GSC data appears

### Post meta (per-post SEO fields)

| Meta key                    | Type    | Purpose                          |
|-----------------------------|---------|----------------------------------|
| `_swps_meta_title`          | string  | Custom meta title                |
| `_swps_meta_description`    | string  | Custom meta description          |
| `_swps_focus_keyword`       | string  | Primary target keyword           |
| `_swps_secondary_keywords`  | string  | Comma-separated secondary keywords|
| `_swps_canonical_url`       | string  | Canonical URL override           |
| `_swps_robots`              | string  | noindex/nofollow per-post        |
| `_swps_breadcrumb_title`    | string  | Breadcrumb title override        |
| `_swps_social_title`        | string  | OG/Twitter title override        |
| `_swps_social_description`  | string  | OG/Twitter description override  |
| `_swps_social_image`        | string  | OG/Twitter image URL override    |

---

## Keyword Research & Tracking

### AI Keyword Suggestions

- New admin page: **StrataWP SEO > Keywords**
- User enters a seed topic (or auto-suggests from site niche/existing content)
- AI provider generates 10-20 keyword ideas with estimated search intent (informational, transactional, navigational)
- User can "track" keywords — adds them to the tracking table
- "Suggest for post" button on the post editor links keywords to specific posts

### GSC-Powered Rank Tracking

- WP-Cron job at configurable frequency (daily/weekly/monthly)
- Pulls GSC Search Analytics data for all tracked keywords
- Stores position, clicks, impressions, CTR per keyword per day in `swps_keyword_tracking`
- Keywords page table: keyword, current position, change vs previous period, clicks, impressions, linked post
- Click a keyword for position history chart (SVG, same pattern as analytics dashboard)

### Topic Opportunities

- "Opportunities" section on Keywords page
- Shows keywords with high impressions but low CTR (position 8-20, "striking distance")
- "Generate Post" button pre-fills content generator with that topic
- Surfaces keywords ranking without a dedicated post (content gap detection)

---

## Meta Title & Description Editor

### Post Editor Metabox

- Appears below the post editor on posts, pages, and custom post types
- Fields: focus keyword, secondary keywords, meta title, meta description, canonical URL, robots meta (index/noindex, follow/nofollow dropdowns), breadcrumb title
- **Google Preview** — live SERP snippet: title (truncated 60 chars), URL, description (truncated 160 chars). Updates as you type.
- **Social Preview tabs** — Facebook and Twitter card previews using OG/social overrides. Falls back to meta title/description if social fields empty.
- **Character counters** — green/yellow/red for title (50-60 ideal) and description (140-160 ideal)
- **AI Generate button** — sends post content + focus keyword to AI, returns suggested meta title and description. One click to accept.

### SEO Checklist

Shown in the metabox as actionable indicators (green check / red X):

- Focus keyword in meta title
- Focus keyword in meta description
- Meta title length in range (50-60)
- Meta description length in range (140-160)
- Focus keyword in first paragraph
- Focus keyword in at least one H2

No overall score — just clear pass/fail per item.

### Frontend Output

- Outputs `<title>`, `<meta name="description">`, `<link rel="canonical">`, `<meta name="robots">`, OG tags, Twitter Card tags in `wp_head`
- Auto-disable when Yoast/RankMath/AIOSEO detected (same pattern as SWPS_Schema)
- Falls back to post title / auto-generated excerpt when custom fields empty

---

## Integration Points

### Settings

- Analytics section: `keyword_tracking_frequency` (select: daily/weekly/monthly)
- New "SEO Meta" section: `meta_editor_enabled` (checkbox), `meta_editor_post_types` (multi-checkbox), `meta_auto_generate` (checkbox — auto-generate meta on publish if empty)

### Conflict Detection

- Check for `WPSEO_VERSION`, `RANK_MATH_VERSION`, `AIOSEO_VERSION` on plugin load
- If detected: suppress all meta/OG/Twitter/canonical output, show admin notice
- Reuse detection pattern from `SWPS_Schema`

### Content Generator Integration

- On post generation, also generate meta title + description, save to post meta
- Focus keyword from keyword opportunities included in generation prompt
- Existing `swps_post_data` filter still works for developer overrides

### Developer Hooks

| Hook                       | Type   | Purpose                              |
|----------------------------|--------|--------------------------------------|
| `swps_meta_title`          | filter | Filter meta title output             |
| `swps_meta_description`    | filter | Filter meta description output       |
| `swps_meta_robots`         | filter | Filter robots meta per post          |
| `swps_keyword_suggestions` | filter | Filter AI keyword suggestions        |
| `swps_seo_checklist`       | filter | Filter/add checklist items in metabox|
