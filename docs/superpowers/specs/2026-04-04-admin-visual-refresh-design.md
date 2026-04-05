# Admin Visual Refresh — Design Spec

## Overview

Modernize all 11 StrataWP SEO admin pages from a standard WordPress look to a modern SaaS dashboard aesthetic. CSS-first approach: rewrite `admin.css` with a new design system, add Chart.js for data visualizations, and make targeted PHP template changes where markup needs it.

## Color Palette — Slate & Coral

| Token | Value | Usage |
|-------|-------|-------|
| `--swps-primary` | `#1E293B` | Headers, primary text, dark cards |
| `--swps-primary-light` | `#334155` | Hover states, secondary surfaces |
| `--swps-accent` | `#F97316` | CTAs, highlights, trend indicators |
| `--swps-accent-light` | `#FFF7ED` | Accent tinted backgrounds |
| `--swps-secondary` | `#6366F1` | Chart secondary series, links, info states |
| `--swps-secondary-light` | `#EEF2FF` | Info backgrounds |
| `--swps-success` | `#10B981` | Good scores, positive trends, pass states |
| `--swps-success-light` | `#ECFDF5` | Success backgrounds |
| `--swps-warning` | `#FBBF24` | Warnings, moderate scores |
| `--swps-warning-light` | `#FFFBEB` | Warning backgrounds |
| `--swps-error` | `#EF4444` | Errors, poor scores, failures |
| `--swps-error-light` | `#FEF2F2` | Error backgrounds |
| `--swps-text` | `#1E293B` | Primary text |
| `--swps-text-muted` | `#64748B` | Secondary/label text |
| `--swps-border` | `#E2E8F0` | Card borders, dividers |
| `--swps-border-light` | `#F1F5F9` | Table row borders, subtle dividers |
| `--swps-surface` | `#FFFFFF` | Card backgrounds |
| `--swps-bg` | `#F8FAFC` | Page background |

## Design Tokens

**Spacing:** 4px base unit — 4, 8, 12, 16, 20, 24, 32, 40, 48px

**Border radius:**
- `--swps-radius-sm`: 6px (badges, pills, small elements)
- `--swps-radius`: 10px (cards, containers)
- `--swps-radius-lg`: 16px (modals, large containers)

**Shadows:**
- `--swps-shadow-sm`: `0 1px 2px rgba(0,0,0,0.05)` (subtle lift)
- `--swps-shadow`: `0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04)` (standard card)
- `--swps-shadow-lg`: `0 10px 15px rgba(0,0,0,0.08), 0 4px 6px rgba(0,0,0,0.04)` (modals, dropdowns)

**Typography:** System font stack (WordPress default `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif`)
- Page titles: 20px / font-weight 600
- Section headings: 15px / font-weight 600
- Body: 14px / font-weight 400
- Labels: 11px / font-weight 500 / uppercase / letter-spacing 0.5px
- Small: 12px / font-weight 400

## Reusable Components

### Page Header
Every admin page gets a gradient header replacing the plain `<h1>`:
- Background: `linear-gradient(135deg, #1E293B, #334155)`
- White title text (20px/600), muted subtitle (#94A3B8, 13px)
- Accent underline: 3px gradient bar at bottom (`#F97316` to transparent)
- Decorative subtle circles (rgba tinted, positioned absolute) for depth
- Padding: 24px 32px

### Cards
White surface, 10px radius, `--swps-shadow`, 24px padding. Three variants:

**Standard card** — Content sections, settings groups, form areas. White bg, border optional.

**Metric card** — Compact summary card. 4px colored left border (accent for each metric type). Contains: uppercase label, large number (28px/700), trend pill badge, optional sparkline SVG below.

**Status card** — For audit modules, sitemap types. Color-coded 3px top border (success/warning/error). Icon + title + description + score badge.

### Tables
- Container: white card with rounded corners, no outer border
- Header row: uppercase 11px labels, `--swps-text-muted` color, `--swps-border-light` top border
- Body rows: `--swps-border-light` top border (no alternating stripes), subtle hover state (`#F8FAFC`)
- Row padding: 12px vertical, 16-24px horizontal
- Text: 13px, page titles in font-weight 500

### Buttons
- Primary: `--swps-primary` bg, white text, on hover add orange glow (`box-shadow: 0 0 0 3px rgba(249,115,22,0.2)`)
- Secondary: white bg, `--swps-primary` border, slate text
- Danger: `--swps-error` bg, white text
- All: 6px radius, 10px 20px padding, 13px font, 200ms transitions

### Badges / Pills
Rounded (12px radius) inline indicators:
- Trend up: `#10B981` text on `#ECFDF5` bg, with ↑ prefix
- Trend down: `#EF4444` text on `#FEF2F2` bg, with ↓ prefix
- Status: color-coded by state (success/warning/error)
- Intent (keywords): informational (`#6366F1` on `#EEF2FF`), transactional (`#10B981` on `#ECFDF5`), navigational (`#F97316` on `#FFF7ED`)

### Score Rings
CSS-only circular progress for SEO scores:
- Size: 100px diameter (audit page main score), 32px (inline/list scores)
- `conic-gradient` with score-colored fill and `#F1F5F9` remainder
- Score number centered inside
- Color thresholds: 80-100 success, 50-79 warning, 0-49 error
- Animate on load: use a wrapper `<div>` that rotates via `@keyframes` from 0 to target, since `conic-gradient` itself is not animatable via CSS transitions. Alternatively, set the gradient dynamically via inline style from JS after page load with a short delay.

### Sparklines
Inline SVG trend lines (120x24px) for metric cards:
- Thin line (1.5px stroke) in the metric's accent color at 60% opacity
- 7-day data points, smooth cubic bezier curves
- No axes, labels, or interactivity — purely visual trend indicator

### Progress Bars
- Container: 6px height, `#F1F5F9` bg, full radius
- Fill: gradient from `--swps-accent` to `--swps-secondary`, animated width transition
- Used in: content generation progress, audit module scores

### Notices / Alerts
Replace WordPress default admin notices:
- 10px radius, no left-border (use subtle left accent instead)
- Background tinted with state color (light variant)
- Icon + text, 14px, proper padding (16px)

## Chart.js Integration

Add Chart.js v4 (loaded from CDN: `cdn.jsdelivr.net/npm/chart.js`). Enqueue only on pages that use charts.

### Chart Configuration Defaults
All charts share a base config:
- Font: system stack, 12px
- Grid: `#F1F5F9` lines, no border
- Tick color: `#94A3B8`
- Point radius: 0 (clean lines), hover radius: 4
- Tension: 0.4 (smooth curves)
- Responsive: true, maintain aspect ratio

### Analytics Page Charts
**Traffic Overview (Line Chart):**
- Two datasets: Page Views (solid `#F97316` with gradient fill) and Search Clicks (dashed `#6366F1`)
- X-axis: date labels, Y-axis: count
- Tooltip: custom styled with slate bg, white text
- Fill: linear gradient from accent at 40% opacity to transparent

### Keywords Page Charts
**Keyword Performance (Bar Chart):**
- Bars: `#6366F1` with `#F97316` accent for highlighted keywords
- Grouped bars for position vs. volume comparisons

### SEO Audit Charts
**Module Scores (Horizontal Bar Chart):**
- Each audit module as a horizontal bar, color-coded by score threshold
- Labels on left, score values on right

## Page-by-Page Changes

### 1. Analytics Page
**Template (`analytics-page.php`):**
- Add page header markup (gradient bar)
- Metric cards row: wrap existing metric elements in new card markup with sparkline containers
- Chart container: replace inline SVG with `<canvas>` element for Chart.js
- Table: add card wrapper around existing table

**JS (`analytics.js`):**
- Replace custom SVG chart rendering with Chart.js initialization
- Add sparkline generation for metric cards
- Keep existing AJAX data fetching logic

**CSS:** New styles for metric cards, chart container, table refinements

### 2. SEO Audit Page
**Template (`audit-page.php`):**
- Page header
- Replace basic score circle with CSS conic-gradient score ring
- Wrap audit modules in status cards (2-column grid)
- Issue list in a card with severity badges

**CSS:** Score ring styles, status card grid, severity badges

### 3. Keywords Page
**Template (`keywords-page.php`):**
- Page header
- Summary metric row (tracked, ranking, opportunities)
- Chart.js canvas for keyword trends
- Redesigned intent badges using new palette

**JS (`keywords.js`):**
- Add Chart.js keyword trend chart
- Keep existing AJAX and suggestion logic

### 4. Content Calendar
**CSS (`calendar.css`):**
- FullCalendar theme overrides: match new palette for event colors, toolbar styling, day cell hover
- Update status legend colors to new palette
- Style modal for topic creation with new card/button styles

### 5. Generate Content Page
**Template (`generate-page.php`):**
- Page header
- Wrap form in a standard card
- Restyle progress bar with accent gradient
- Result preview: clean modal card with proper shadows

**CSS:** Form card, progress bar gradient, modal styling

### 6. Redirects Page
**Template (`redirects-page.php`):**
- Page header
- Restyle tab navigation (pill-style tabs matching palette)
- Table refinements
- Inline redirect form cards
- Suggestion chips as new badge style

### 7. Sitemaps Page
**Template (`sitemaps-page.php`):**
- Page header
- Status cards per sitemap type (card with toggle and status indicator)
- Restyle tab navigation

### 8. Internal Links Page
**Template (`internal-links-page.php`):**
- Page header
- Link suggestion cards with accent-colored left border
- Stats summary row at top

### 9. Settings Page
**Template (`settings-page.php`):**
- Page header
- Each settings section in a standard card with slate section header
- Form field spacing improvements
- Toggle switches for boolean options (CSS-only, replace checkboxes visually)

### 10. Voice Profiles Page
**Template (`voice-profiles-page.php`):**
- Page header
- Profile cards in a responsive grid
- Edit form in a card

### 11. Search Appearance Page
**Template (`search-appearance-page.php`):**
- Page header
- SERP preview in a shadowed card
- Social preview cards (Facebook, Twitter) side by side

### Post Editor Integration
**Meta Editor Metabox:**
- Restyle SERP preview with new card shadow
- Character count indicators using new palette colors
- SEO checklist items with new badge styles

**Post List SEO Column:**
- Score circle using new colors (success/warning/error thresholds)
- Tooltip styling update

## File Changes Summary

| File | Change Type | Scope |
|------|-------------|-------|
| `admin/css/admin.css` | Rewrite | Full design system + all page styles |
| `admin/css/calendar.css` | Edit | FullCalendar theme overrides |
| `admin/js/analytics.js` | Edit | Replace SVG chart with Chart.js |
| `admin/js/keywords.js` | Edit | Add Chart.js keyword chart |
| `templates/analytics-page.php` | Edit | Add header, card wrappers, canvas |
| `templates/audit-page.php` | Edit | Add header, score ring, status cards |
| `templates/keywords-page.php` | Edit | Add header, metric row, canvas |
| `templates/generate-page.php` | Edit | Add header, card wrappers |
| `templates/settings-page.php` | Edit | Add header, section cards |
| `templates/voice-profiles-page.php` | Edit | Add header, profile cards |
| `templates/search-appearance-page.php` | Edit | Add header, preview cards |
| `templates/calendar-page.php` | Edit | Add header |
| `templates/redirects-page.php` | Edit | Add header, tab restyle |
| `templates/sitemaps-page.php` | Edit | Add header, status cards |
| `templates/internal-links-page.php` | Edit | Add header, suggestion cards |
| `templates/meta-editor-metabox.php` | Edit | Restyle preview + checklist |
| `includes/class-settings.php` | Edit | Enqueue Chart.js on relevant pages |
| `includes/class-redirect-admin.php` | Edit | Add header markup to render method |
| `includes/class-sitemap-admin.php` | Edit | Add header markup to render method |
| `includes/class-internal-links-admin.php` | Edit | Add header markup to render method |

## Dependencies

- **Chart.js v4** — CDN (`cdn.jsdelivr.net/npm/chart.js`), enqueued only on analytics and keywords pages
- No other new dependencies

## Constraints

- All visual changes must work within WordPress admin (wp-admin context)
- Must not break existing AJAX functionality or data flows
- Must remain responsive (existing breakpoints preserved, enhanced)
- CSS custom properties used with fallback values for older browsers
- No build step required — all CSS/JS served directly
- Chart.js loaded from CDN to avoid bundling
