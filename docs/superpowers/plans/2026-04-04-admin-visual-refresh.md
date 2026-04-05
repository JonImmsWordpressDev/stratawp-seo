# Admin Visual Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transform all 11 StrataWP SEO admin pages from standard WordPress styling to a modern SaaS dashboard aesthetic using the Slate & Coral color palette.

**Architecture:** CSS-first approach — rewrite `admin/css/admin.css` with CSS custom properties as a design system, add Chart.js for analytics/keywords charts, and make targeted PHP template edits to add gradient page headers and card wrappers. Existing AJAX logic and data flows remain untouched.

**Tech Stack:** CSS custom properties, Chart.js v4 (CDN), PHP template markup, vanilla JS for score ring animation.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `admin/css/admin.css` | Full design system: tokens, components, all page styles |
| `admin/css/calendar.css` | FullCalendar theme overrides for new palette |
| `admin/js/analytics.js` | Replace SVG chart with Chart.js, add sparklines |
| `admin/js/keywords.js` | Add Chart.js keyword trend chart |
| `stratawp-seo.php` | Enqueue Chart.js CDN on analytics/keywords pages |
| `templates/analytics-page.php` | Page header, metric card wrappers, canvas element |
| `templates/audit-page.php` | Page header, score ring markup |
| `templates/keywords-page.php` | Page header, metric summary row, canvas |
| `templates/generate-page.php` | Page header |
| `templates/settings-page.php` | Page header |
| `templates/voice-profiles-page.php` | Page header |
| `templates/calendar-page.php` | Page header |
| `templates/meta-editor-metabox.php` | Updated color classes |

Note: Redirects, Sitemaps, and Internal Links pages are rendered by their own admin classes — their headers will be added to those class render methods.

---

### Task 1: CSS Design System Foundation

**Files:**
- Rewrite: `admin/css/admin.css` (lines 1-40, prepend design tokens)

- [ ] **Step 1: Add CSS custom properties block at the top of admin.css**

Replace lines 1-6 of `admin/css/admin.css` (the existing `/* StrataWP SEO Admin Styles */` comment and `.swps-wrap` rule) with the design tokens and updated `.swps-wrap`:

```css
/* StrataWP SEO Admin Styles — Slate & Coral Design System */

:root {
    /* Primary */
    --swps-primary: #1E293B;
    --swps-primary-light: #334155;
    --swps-primary-lighter: #475569;
    /* Accent */
    --swps-accent: #F97316;
    --swps-accent-light: #FFF7ED;
    --swps-accent-hover: #EA580C;
    /* Secondary */
    --swps-secondary: #6366F1;
    --swps-secondary-light: #EEF2FF;
    /* Status */
    --swps-success: #10B981;
    --swps-success-light: #ECFDF5;
    --swps-warning: #FBBF24;
    --swps-warning-light: #FFFBEB;
    --swps-error: #EF4444;
    --swps-error-light: #FEF2F2;
    /* Text */
    --swps-text: #1E293B;
    --swps-text-muted: #64748B;
    --swps-text-light: #94A3B8;
    /* Surface */
    --swps-surface: #FFFFFF;
    --swps-bg: #F8FAFC;
    --swps-border: #E2E8F0;
    --swps-border-light: #F1F5F9;
    /* Radius */
    --swps-radius-sm: 6px;
    --swps-radius: 10px;
    --swps-radius-lg: 16px;
    /* Shadows */
    --swps-shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --swps-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
    --swps-shadow-lg: 0 10px 15px rgba(0,0,0,0.08), 0 4px 6px rgba(0,0,0,0.04);
    /* Transitions */
    --swps-transition: 200ms ease;
}

.swps-wrap,
.swps-analytics-wrap,
.swps-keywords-wrap,
.swps-calendar-wrap {
    max-width: 1200px;
}
```

- [ ] **Step 2: Verify the file renders without breaking — open any plugin admin page in browser**

Open any StrataWP SEO admin page in the WordPress dashboard. The page should still render correctly — only the `:root` variables are new, nothing visual has changed yet.

- [ ] **Step 3: Commit**

```bash
git add admin/css/admin.css
git commit -m "feat: add CSS custom properties design system tokens"
```

---

### Task 2: Page Header Component

**Files:**
- Modify: `admin/css/admin.css` (add after the `.swps-wrap` rule)

- [ ] **Step 1: Add page header CSS after the wrap rules**

Add this CSS after the `.swps-wrap` rules (currently around line 5-6, after the tokens block from Task 1):

```css
/* ===================== Page Header ===================== */

.swps-page-header {
    background: linear-gradient(135deg, var(--swps-primary) 0%, var(--swps-primary-light) 100%);
    padding: 24px 32px;
    margin: -1px -1px 24px -1px;
    position: relative;
    overflow: hidden;
    border-radius: var(--swps-radius) var(--swps-radius) 0 0;
}

.swps-page-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 32px;
    right: 32px;
    height: 3px;
    background: linear-gradient(90deg, var(--swps-accent), transparent);
    border-radius: 2px;
}

.swps-page-header .swps-page-header-orb {
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.swps-page-header .swps-page-header-orb:first-of-type {
    top: -20px;
    right: -20px;
    width: 120px;
    height: 120px;
    background: rgba(249, 115, 22, 0.1);
}

.swps-page-header .swps-page-header-orb:last-of-type {
    bottom: -30px;
    right: 60px;
    width: 80px;
    height: 80px;
    background: rgba(99, 102, 241, 0.08);
}

.swps-page-header h1 {
    color: #fff;
    margin: 0 0 4px;
    padding: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.3;
}

.swps-page-header h1 .dashicons {
    color: var(--swps-accent);
    font-size: 24px;
    width: 24px;
    height: 24px;
    margin-right: 8px;
    vertical-align: middle;
}

.swps-page-header p {
    color: var(--swps-text-light);
    margin: 0;
    font-size: 13px;
}

.swps-page-header .page-title-action {
    background: rgba(255,255,255,0.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: var(--swps-radius-sm);
}

.swps-page-header .page-title-action:hover {
    background: rgba(255,255,255,0.25);
}
```

- [ ] **Step 2: Add the page header markup to analytics-page.php**

Replace lines 15-16 (`<div class="wrap swps-analytics-wrap">` and `<h1>Analytics</h1>`) in `templates/analytics-page.php`:

Old:
```php
<div class="wrap swps-analytics-wrap">
    <h1><?php esc_html_e( 'Analytics', 'stratawp-seo' ); ?></h1>
```

New:
```php
<div class="wrap swps-analytics-wrap">
    <div class="swps-page-header">
        <span class="swps-page-header-orb"></span>
        <span class="swps-page-header-orb"></span>
        <h1><?php esc_html_e( 'Analytics', 'stratawp-seo' ); ?></h1>
        <p><?php esc_html_e( 'Track your site\'s performance and search visibility', 'stratawp-seo' ); ?></p>
    </div>
```

- [ ] **Step 3: Add header to audit-page.php**

Replace lines 20-21 in `templates/audit-page.php`:

Old:
```php
<div class="wrap swps-wrap">
	<h1><?php esc_html_e( 'SEO Audit', 'stratawp-seo' ); ?></h1>
```

New:
```php
<div class="wrap swps-wrap">
	<div class="swps-page-header">
		<span class="swps-page-header-orb"></span>
		<span class="swps-page-header-orb"></span>
		<h1><?php esc_html_e( 'SEO Audit', 'stratawp-seo' ); ?></h1>
		<p><?php esc_html_e( 'Analyze your site\'s SEO health and fix issues', 'stratawp-seo' ); ?></p>
	</div>
```

- [ ] **Step 4: Add header to generate-page.php**

Replace lines 8-12 in `templates/generate-page.php`:

Old:
```php
<div class="wrap swps-wrap">
    <h1>
        <span class="dashicons dashicons-superhero-alt" style="font-size: 28px; margin-right: 8px;"></span>
        <?php esc_html_e( 'Generate SEO Content', 'stratawp-seo' ); ?>
    </h1>
```

New:
```php
<div class="wrap swps-wrap">
    <div class="swps-page-header">
        <span class="swps-page-header-orb"></span>
        <span class="swps-page-header-orb"></span>
        <h1>
            <span class="dashicons dashicons-superhero-alt"></span>
            <?php esc_html_e( 'Generate SEO Content', 'stratawp-seo' ); ?>
        </h1>
        <p><?php esc_html_e( 'Create AI-powered SEO-optimized blog posts', 'stratawp-seo' ); ?></p>
    </div>
```

- [ ] **Step 5: Add header to keywords-page.php**

Replace lines 12-13 in `templates/keywords-page.php`:

Old:
```php
<div class="wrap swps-keywords-wrap">
    <h1><?php esc_html_e( 'Keyword Research & Tracking', 'stratawp-seo' ); ?></h1>
```

New:
```php
<div class="wrap swps-keywords-wrap">
    <div class="swps-page-header">
        <span class="swps-page-header-orb"></span>
        <span class="swps-page-header-orb"></span>
        <h1><?php esc_html_e( 'Keyword Research & Tracking', 'stratawp-seo' ); ?></h1>
        <p><?php esc_html_e( 'Discover opportunities and track your keyword rankings', 'stratawp-seo' ); ?></p>
    </div>
```

- [ ] **Step 6: Add header to settings-page.php**

Replace lines 1-5 in `templates/settings-page.php`:

Old:
```php
<div class="wrap swps-wrap">
    <h1>
        <span class="dashicons dashicons-superhero-alt" style="font-size: 28px; margin-right: 8px;"></span>
        <?php esc_html_e( 'StrataWP SEO — Settings', 'stratawp-seo' ); ?>
    </h1>
```

New:
```php
<div class="wrap swps-wrap">
    <div class="swps-page-header">
        <span class="swps-page-header-orb"></span>
        <span class="swps-page-header-orb"></span>
        <h1>
            <span class="dashicons dashicons-superhero-alt"></span>
            <?php esc_html_e( 'StrataWP SEO — Settings', 'stratawp-seo' ); ?>
        </h1>
        <p><?php esc_html_e( 'Configure your AI-powered SEO content generator', 'stratawp-seo' ); ?></p>
    </div>
```

- [ ] **Step 7: Add header to voice-profiles-page.php**

Replace lines 1-7 in `templates/voice-profiles-page.php`:

Old:
```php
<div class="wrap swps-wrap">
    <h1>
        <span class="dashicons dashicons-format-status" style="font-size: 28px; margin-right: 8px;"></span>
        <?php esc_html_e( 'Voice Profiles', 'stratawp-seo' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-voice-profiles&action=new' ) ); ?>" class="page-title-action">
            <?php esc_html_e( 'Add New', 'stratawp-seo' ); ?>
        </a>
    </h1>
```

New:
```php
<div class="wrap swps-wrap">
    <div class="swps-page-header">
        <span class="swps-page-header-orb"></span>
        <span class="swps-page-header-orb"></span>
        <h1>
            <span class="dashicons dashicons-format-status"></span>
            <?php esc_html_e( 'Voice Profiles', 'stratawp-seo' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-voice-profiles&action=new' ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Add New', 'stratawp-seo' ); ?>
            </a>
        </h1>
        <p><?php esc_html_e( 'Define consistent brand voices for your AI content', 'stratawp-seo' ); ?></p>
    </div>
```

- [ ] **Step 8: Add header to calendar-page.php**

Replace lines 6-9 in `templates/calendar-page.php`:

Old:
```php
<div class="wrap swps-wrap swps-calendar-wrap">
    <h1>
        <span class="dashicons dashicons-calendar-alt" style="font-size: 28px; margin-right: 8px;"></span>
        <?php esc_html_e( 'Content Calendar', 'stratawp-seo' ); ?>
    </h1>
```

New:
```php
<div class="wrap swps-wrap swps-calendar-wrap">
    <div class="swps-page-header">
        <span class="swps-page-header-orb"></span>
        <span class="swps-page-header-orb"></span>
        <h1>
            <span class="dashicons dashicons-calendar-alt"></span>
            <?php esc_html_e( 'Content Calendar', 'stratawp-seo' ); ?>
        </h1>
        <p><?php esc_html_e( 'Plan your content schedule — click any date to add a topic', 'stratawp-seo' ); ?></p>
    </div>
```

- [ ] **Step 9: Verify all 7 updated pages render gradient headers**

Open each page in the WordPress admin and verify the dark gradient header with white text and orange accent line appears.

- [ ] **Step 10: Commit**

```bash
git add admin/css/admin.css templates/analytics-page.php templates/audit-page.php templates/generate-page.php templates/keywords-page.php templates/settings-page.php templates/voice-profiles-page.php templates/calendar-page.php
git commit -m "feat: add gradient page headers to all admin pages"
```

---

### Task 3: Card and Button Component Styles

**Files:**
- Modify: `admin/css/admin.css`

- [ ] **Step 1: Update the existing card styles**

Find the existing `.swps-card` block (around line 20-27 after Task 1 changes) and replace it:

Old:
```css
.swps-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 24px;
    margin-bottom: 20px;
}

.swps-card h2 {
    margin-top: 0;
    padding-top: 0;
    font-size: 18px;
    border-bottom: none;
}

.swps-card-desc {
    color: #646970;
    font-size: 14px;
}
```

New:
```css
/* ===================== Cards ===================== */

.swps-card {
    background: var(--swps-surface);
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius);
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: var(--swps-shadow);
    transition: box-shadow var(--swps-transition);
}

.swps-card:hover {
    box-shadow: var(--swps-shadow-lg);
}

.swps-card h2 {
    margin-top: 0;
    padding-top: 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--swps-text);
    border-bottom: none;
}

.swps-card-desc {
    color: var(--swps-text-muted);
    font-size: 14px;
}
```

- [ ] **Step 2: Update button styles**

Find and update the `.swps-generate-actions .button-hero` styles and add new global button overrides. After the card styles, add:

```css
/* ===================== Buttons ===================== */

.swps-wrap .button-primary,
.swps-analytics-wrap .button-primary,
.swps-keywords-wrap .button-primary,
.swps-calendar-wrap .button-primary {
    background: var(--swps-primary);
    border-color: var(--swps-primary);
    color: #fff;
    border-radius: var(--swps-radius-sm);
    transition: all var(--swps-transition);
}

.swps-wrap .button-primary:hover,
.swps-analytics-wrap .button-primary:hover,
.swps-keywords-wrap .button-primary:hover,
.swps-calendar-wrap .button-primary:hover {
    background: var(--swps-primary-light);
    border-color: var(--swps-primary-light);
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.2);
}

.swps-wrap .button-primary:focus,
.swps-analytics-wrap .button-primary:focus,
.swps-keywords-wrap .button-primary:focus,
.swps-calendar-wrap .button-primary:focus {
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.3);
}

.swps-wrap .button:not(.button-primary),
.swps-analytics-wrap .button:not(.button-primary),
.swps-keywords-wrap .button:not(.button-primary),
.swps-calendar-wrap .button:not(.button-primary) {
    border-radius: var(--swps-radius-sm);
    transition: all var(--swps-transition);
}

.swps-wrap .button-link-delete,
.swps-analytics-wrap .button-link-delete,
.swps-keywords-wrap .button-link-delete,
.swps-calendar-wrap .button-link-delete {
    color: var(--swps-error);
}
```

- [ ] **Step 3: Update badge/pill styles**

Find and update the existing `.swps-score-badge` rules:

Old:
```css
.swps-score-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    color: #fff;
    line-height: 1.4;
}
.swps-score-badge--excellent { background: #00a32a; }
.swps-score-badge--good { background: #dba617; color: #1d2327; }
.swps-score-badge--poor { background: #d63638; }
```

New:
```css
.swps-score-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
}
.swps-score-badge--excellent { background: var(--swps-success-light); color: var(--swps-success); }
.swps-score-badge--good { background: var(--swps-warning-light); color: #92400E; }
.swps-score-badge--poor { background: var(--swps-error-light); color: var(--swps-error); }
```

- [ ] **Step 4: Update header bar styles**

Find and update `.swps-header-bar`:

Old:
```css
.swps-header-bar {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-left: 4px solid #2271b1;
    padding: 12px 16px;
    margin: 16px 0;
}
```

New:
```css
.swps-header-bar {
    background: var(--swps-secondary-light);
    border: 1px solid var(--swps-border);
    border-left: 4px solid var(--swps-secondary);
    border-radius: var(--swps-radius-sm);
    padding: 12px 16px;
    margin: 0 0 20px;
}
```

- [ ] **Step 5: Update notice styles**

Add after the button styles:

```css
/* ===================== Notices ===================== */

.swps-wrap .notice,
.swps-analytics-wrap .notice,
.swps-keywords-wrap .notice {
    border-radius: var(--swps-radius-sm);
    border-left-width: 4px;
}
```

- [ ] **Step 6: Verify all pages — cards, buttons, badges look correct**

Open Settings, Generate Content, and SEO Audit pages. Verify cards have subtle shadows, buttons are slate-colored with rounded corners, and badges use the new palette.

- [ ] **Step 7: Commit**

```bash
git add admin/css/admin.css
git commit -m "feat: update card, button, badge, and notice styles to Slate & Coral palette"
```

---

### Task 4: Table and Form Styles

**Files:**
- Modify: `admin/css/admin.css`

- [ ] **Step 1: Add table override styles**

Add a new table section to `admin/css/admin.css`:

```css
/* ===================== Tables ===================== */

.swps-wrap .widefat,
.swps-analytics-wrap .widefat,
.swps-keywords-wrap .widefat {
    border: none;
    border-radius: var(--swps-radius);
    box-shadow: var(--swps-shadow);
    overflow: hidden;
}

.swps-wrap .widefat thead th,
.swps-analytics-wrap .widefat thead th,
.swps-keywords-wrap .widefat thead th {
    background: var(--swps-bg);
    border-bottom: 1px solid var(--swps-border-light);
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--swps-text-muted);
    padding: 10px 16px;
}

.swps-wrap .widefat tbody td,
.swps-analytics-wrap .widefat tbody td,
.swps-keywords-wrap .widefat tbody td {
    border-top: 1px solid var(--swps-border-light);
    padding: 12px 16px;
    font-size: 13px;
    color: var(--swps-text);
}

.swps-wrap .widefat.striped > tbody > :nth-child(odd),
.swps-analytics-wrap .widefat.striped > tbody > :nth-child(odd),
.swps-keywords-wrap .widefat.striped > tbody > :nth-child(odd) {
    background: transparent;
}

.swps-wrap .widefat tbody tr:hover,
.swps-analytics-wrap .widefat tbody tr:hover,
.swps-keywords-wrap .widefat tbody tr:hover {
    background: var(--swps-bg);
}
```

- [ ] **Step 2: Update form group styles**

Find and update the `.swps-form-group` block:

Old:
```css
.swps-form-group {
    margin-bottom: 20px;
}

.swps-form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    font-size: 14px;
}
```

New:
```css
.swps-form-group {
    margin-bottom: 20px;
}

.swps-form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    font-size: 13px;
    color: var(--swps-text);
}

.swps-form-group input[type="text"],
.swps-form-group input[type="url"],
.swps-form-group input[type="number"],
.swps-form-group input[type="password"],
.swps-form-group textarea,
.swps-form-group select {
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius-sm);
    transition: border-color var(--swps-transition), box-shadow var(--swps-transition);
}

.swps-form-group input:focus,
.swps-form-group textarea:focus,
.swps-form-group select:focus {
    border-color: var(--swps-accent);
    box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.15);
    outline: none;
}
```

- [ ] **Step 3: Update intent badges for keywords**

Find and update the intent badge rules:

Old:
```css
.swps-intent-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; }
.swps-intent-informational { background: #e7f5ff; color: #1971c2; }
.swps-intent-transactional { background: #e6fcf5; color: #087f5b; }
.swps-intent-navigational { background: #fff3e0; color: #e65100; }
```

New:
```css
.swps-intent-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 500; }
.swps-intent-informational { background: var(--swps-secondary-light); color: var(--swps-secondary); }
.swps-intent-transactional { background: var(--swps-success-light); color: var(--swps-success); }
.swps-intent-navigational { background: var(--swps-accent-light); color: var(--swps-accent); }
```

- [ ] **Step 4: Verify tables on analytics and keywords pages, forms on settings page**

- [ ] **Step 5: Commit**

```bash
git add admin/css/admin.css
git commit -m "feat: modernize table, form, and intent badge styles"
```

---

### Task 5: Analytics Page — Metric Cards and Chart.js

**Files:**
- Modify: `admin/css/admin.css` (metric card styles)
- Modify: `templates/analytics-page.php` (card wrappers, canvas)
- Modify: `admin/js/analytics.js` (Chart.js rendering)
- Modify: `stratawp-seo.php` (enqueue Chart.js)

- [ ] **Step 1: Update metric card CSS**

Find the existing metric card styles and replace:

Old:
```css
.swps-metric-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.swps-metric-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 16px;
    display: flex;
    flex-direction: column;
}

.swps-metric-label {
    font-size: 12px;
    color: #646970;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.swps-metric-value {
    font-size: 28px;
    font-weight: 600;
    color: #1d2327;
    line-height: 1.2;
}

.swps-metric-change {
    font-size: 13px;
    margin-top: 4px;
}

.swps-change-up {
    color: #00a32a;
}

.swps-change-down {
    color: #d63638;
}

.swps-chart-container {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 16px;
    margin-bottom: 24px;
}

.swps-chart {
    width: 100%;
    min-height: 250px;
}
```

New:
```css
.swps-metric-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.swps-metric-card {
    background: var(--swps-surface);
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius);
    border-left: 4px solid var(--swps-accent);
    padding: 20px;
    display: flex;
    flex-direction: column;
    box-shadow: var(--swps-shadow);
    transition: box-shadow var(--swps-transition);
}

.swps-metric-card:hover {
    box-shadow: var(--swps-shadow-lg);
}

.swps-metric-card:nth-child(2) { border-left-color: var(--swps-secondary); }
.swps-metric-card:nth-child(3) { border-left-color: var(--swps-success); }
.swps-metric-card:nth-child(4) { border-left-color: var(--swps-primary); }

.swps-metric-label {
    font-size: 11px;
    font-weight: 500;
    color: var(--swps-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.swps-metric-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--swps-text);
    line-height: 1.2;
}

.swps-metric-change {
    font-size: 12px;
    margin-top: 8px;
}

.swps-change-up {
    color: var(--swps-success);
    background: var(--swps-success-light);
    padding: 2px 8px;
    border-radius: 12px;
    display: inline-block;
}

.swps-change-down {
    color: var(--swps-error);
    background: var(--swps-error-light);
    padding: 2px 8px;
    border-radius: 12px;
    display: inline-block;
}

.swps-chart-container {
    background: var(--swps-surface);
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: var(--swps-shadow);
}

.swps-chart-container h2 {
    margin-top: 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--swps-text);
}

.swps-chart-subtitle {
    color: var(--swps-text-muted);
    font-size: 12px;
    margin-top: 4px;
}

.swps-chart {
    width: 100%;
    min-height: 280px;
}
```

- [ ] **Step 2: Add chart canvas to analytics-page.php**

In `templates/analytics-page.php`, replace the chart container (lines 87-90):

Old:
```php
    <!-- Chart -->
    <div class="swps-chart-container">
        <h2><?php esc_html_e( 'Traffic Over Time', 'stratawp-seo' ); ?></h2>
        <div id="swps-analytics-chart" class="swps-chart"></div>
    </div>
```

New:
```php
    <!-- Chart -->
    <div class="swps-chart-container">
        <h2><?php esc_html_e( 'Traffic Overview', 'stratawp-seo' ); ?></h2>
        <p class="swps-chart-subtitle"><?php esc_html_e( 'Page views and search clicks over time', 'stratawp-seo' ); ?></p>
        <canvas id="swps-analytics-chart" class="swps-chart"></canvas>
    </div>
```

- [ ] **Step 3: Wrap tables in card containers in analytics-page.php**

In `templates/analytics-page.php`, wrap the Top Pages section (lines 93-114):

Old:
```php
    <!-- Top Pages -->
    <div class="swps-analytics-section">
        <h2><?php esc_html_e( 'Top Pages', 'stratawp-seo' ); ?></h2>
        <table class="widefat striped" id="swps-top-pages-table">
```

New:
```php
    <!-- Top Pages -->
    <div class="swps-analytics-section swps-card" style="padding: 0;">
        <div style="padding: 20px 24px 0;">
            <h2><?php esc_html_e( 'Top Pages', 'stratawp-seo' ); ?></h2>
        </div>
        <table class="widefat striped" id="swps-top-pages-table" style="box-shadow: none; border-radius: 0;">
```

Do the same for Top Queries table (lines 117-134).

- [ ] **Step 4: Enqueue Chart.js in stratawp-seo.php**

In `stratawp-seo.php`, find the analytics JS enqueue block (around line 319-327). Add Chart.js CDN before the analytics script:

Old:
```php
        // Analytics dashboard JS.
        if ( str_contains( $hook, 'swps-analytics' ) || in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            wp_enqueue_script(
                'swps-analytics',
                SWPS_PLUGIN_URL . 'admin/js/analytics.js',
                [ 'jquery', 'swps-admin' ],
                SWPS_VERSION,
                true
            );
        }
```

New:
```php
        // Analytics dashboard JS.
        if ( str_contains( $hook, 'swps-analytics' ) || in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            if ( str_contains( $hook, 'swps-analytics' ) ) {
                wp_enqueue_script(
                    'chartjs',
                    'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
                    [],
                    '4.4.7',
                    true
                );
            }
            wp_enqueue_script(
                'swps-analytics',
                SWPS_PLUGIN_URL . 'admin/js/analytics.js',
                str_contains( $hook, 'swps-analytics' ) ? [ 'jquery', 'swps-admin', 'chartjs' ] : [ 'jquery', 'swps-admin' ],
                SWPS_VERSION,
                true
            );
        }
```

- [ ] **Step 5: Replace SVG chart rendering with Chart.js in analytics.js**

In `admin/js/analytics.js`, replace the entire `renderChart` function (lines 110-149):

Old:
```javascript
    function renderChart(dailyViews, gscDaily) {
        var container = document.getElementById('swps-analytics-chart');
        if (!container) return;
        // ... SVG rendering ...
        container.innerHTML = svg;
    }
```

New:
```javascript
    var chartInstance = null;

    function renderChart(dailyViews, gscDaily) {
        var canvas = document.getElementById('swps-analytics-chart');
        if (!canvas || typeof Chart === 'undefined') return;

        if (!dailyViews.length) {
            canvas.parentNode.insertAdjacentHTML('beforeend', '<p style="text-align:center;color:#64748B;padding:40px 0;">No data for this period.</p>');
            return;
        }

        var labels = dailyViews.map(function (d) { return d.date; });
        var views = dailyViews.map(function (d) { return parseInt(d.views) || 0; });
        var clicks = gscDaily.map(function (d) { return parseInt(d.clicks) || 0; });

        if (chartInstance) {
            chartInstance.destroy();
        }

        var ctx = canvas.getContext('2d');
        var gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
        gradient.addColorStop(0, 'rgba(249, 115, 22, 0.15)');
        gradient.addColorStop(1, 'rgba(249, 115, 22, 0)');

        var datasets = [{
            label: 'Page Views',
            data: views,
            borderColor: '#F97316',
            backgroundColor: gradient,
            fill: true,
            tension: 0.4,
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 4,
            pointHoverBackgroundColor: '#F97316'
        }];

        if (clicks.length) {
            datasets.push({
                label: 'Search Clicks',
                data: clicks,
                borderColor: '#6366F1',
                backgroundColor: 'transparent',
                borderDash: [6, 3],
                tension: 0.4,
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                pointHoverBackgroundColor: '#6366F1'
            });
        }

        chartInstance = new Chart(canvas, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: datasets.length > 1, position: 'top', align: 'end', labels: { usePointStyle: true, pointStyle: 'line', font: { size: 12 } } },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        titleFont: { size: 13 },
                        bodyFont: { size: 12 },
                        cornerRadius: 8,
                        padding: 12
                    }
                },
                scales: {
                    x: { grid: { color: '#F1F5F9', drawBorder: false }, ticks: { color: '#94A3B8', font: { size: 11 } } },
                    y: { grid: { color: '#F1F5F9', drawBorder: false }, ticks: { color: '#94A3B8', font: { size: 11 } }, beginAtZero: true }
                }
            }
        });
    }
```

- [ ] **Step 6: Update trend indicator rendering in analytics.js**

In `admin/js/analytics.js`, find the change element rendering (around lines 28-34) and update:

Old:
```javascript
            var changeEl = $('#swps-views-change');
            if (d.views_change !== 0) {
                var arrow = d.views_change > 0 ? '↑' : '↓';
                var cls = d.views_change > 0 ? 'swps-change-up' : 'swps-change-down';
                changeEl.html('<span class="' + cls + '">' + arrow + ' ' + Math.abs(d.views_change) + '%</span>');
            } else {
                changeEl.html('');
            }
```

New:
```javascript
            var changeEl = $('#swps-views-change');
            if (d.views_change !== 0) {
                var arrow = d.views_change > 0 ? '↑' : '↓';
                var cls = d.views_change > 0 ? 'swps-change-up' : 'swps-change-down';
                changeEl.html('<span class="' + cls + '">' + arrow + ' ' + Math.abs(d.views_change).toFixed(1) + '%</span>');
            } else {
                changeEl.html('');
            }
```

- [ ] **Step 7: Verify analytics page renders Chart.js chart with gradient fill**

Open the Analytics page. The chart should render as a smooth line chart with orange gradient fill instead of the basic SVG polyline. Metric cards should have colored left borders.

- [ ] **Step 8: Commit**

```bash
git add admin/css/admin.css admin/js/analytics.js templates/analytics-page.php stratawp-seo.php
git commit -m "feat: add Chart.js analytics chart and modernize metric cards"
```

---

### Task 6: SEO Audit Page Styles

**Files:**
- Modify: `admin/css/admin.css` (audit section styles)

- [ ] **Step 1: Update audit header and score circle styles**

Find the existing `/* ===================== SEO Audit ===================== */` section and replace all audit styles:

Old (lines starting with `.swps-audit-header` through `.swps-issue-fixable .dashicons`):

New:
```css
/* ===================== SEO Audit ===================== */

.swps-audit-header {
    display: flex;
    align-items: center;
    gap: 30px;
    margin: 0 0 24px;
    padding: 24px;
    background: var(--swps-surface);
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius);
    box-shadow: var(--swps-shadow);
}

.swps-audit-score-circle {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
}

.swps-audit-score--excellent {
    background: conic-gradient(var(--swps-success) calc(var(--score, 0) * 1%), var(--swps-border-light) 0);
    color: var(--swps-success);
}
.swps-audit-score--good {
    background: conic-gradient(var(--swps-warning) calc(var(--score, 0) * 1%), var(--swps-border-light) 0);
    color: #92400E;
}
.swps-audit-score--poor {
    background: conic-gradient(var(--swps-error) calc(var(--score, 0) * 1%), var(--swps-border-light) 0);
    color: var(--swps-error);
}

.swps-audit-score-circle::before {
    content: '';
    position: absolute;
    inset: 8px;
    border-radius: 50%;
    background: var(--swps-surface);
}

.swps-audit-score-number {
    font-size: 28px;
    font-weight: 700;
    line-height: 1;
    position: relative;
    z-index: 1;
}

.swps-audit-score-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 2px;
    position: relative;
    z-index: 1;
    color: var(--swps-text-muted);
}

.swps-audit-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.swps-audit-last-run {
    color: var(--swps-text-muted);
    font-size: 13px;
}

.swps-audit-modules {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 16px;
}

.swps-audit-module-card {
    padding: 16px 24px;
    border-top: 3px solid var(--swps-border);
    transition: border-color var(--swps-transition);
}

.swps-audit-module-card[data-status="pass"] {
    border-top-color: var(--swps-success);
}
.swps-audit-module-card[data-status="warning"] {
    border-top-color: var(--swps-warning);
}
.swps-audit-module-card[data-status="fail"] {
    border-top-color: var(--swps-error);
}

.swps-audit-module-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.swps-audit-module-info {
    display: flex;
    align-items: center;
    gap: 8px;
}

.swps-audit-module-info .dashicons {
    font-size: 20px;
    width: 20px;
    height: 20px;
}

.swps-audit-module-info .dashicons-yes-alt { color: var(--swps-success); }
.swps-audit-module-info .dashicons-warning { color: var(--swps-warning); }
.swps-audit-module-info .dashicons-dismiss { color: var(--swps-error); }

.swps-audit-module-actions {
    display: flex;
    gap: 8px;
}

.swps-audit-module-summary {
    color: var(--swps-text-muted);
    margin: 8px 0 0;
    font-size: 13px;
}

.swps-audit-issues {
    margin-top: 12px;
}

.swps-audit-issues .widefat td {
    font-size: 13px;
}

.swps-issue-link {
    margin-left: 8px;
    font-size: 12px;
    color: var(--swps-secondary);
}

.swps-issue-fixable {
    width: 30px;
    text-align: center;
}

.swps-issue-fixable .dashicons {
    color: var(--swps-secondary);
    font-size: 16px;
    width: 16px;
    height: 16px;
}
```

- [ ] **Step 2: Add score CSS variable to audit-page.php**

In `templates/audit-page.php`, update the score circle div (line 24) to include the CSS variable:

Old:
```php
		<div class="swps-audit-score-circle swps-audit-score--<?php echo esc_attr( $score_class ); ?>">
```

New:
```php
		<div class="swps-audit-score-circle swps-audit-score--<?php echo esc_attr( $score_class ); ?>" style="--score: <?php echo (int) $overall; ?>">
```

Also add `data-status` to each module card (line 79):

Old:
```php
		<div class="swps-audit-module-card swps-card" data-module-id="<?php echo esc_attr( $id ); ?>">
```

New:
```php
		<div class="swps-audit-module-card swps-card" data-module-id="<?php echo esc_attr( $id ); ?>" data-status="<?php echo esc_attr( $mod_status ); ?>">
```

- [ ] **Step 3: Verify the audit page — score ring with conic gradient, module cards with colored top borders**

- [ ] **Step 4: Commit**

```bash
git add admin/css/admin.css templates/audit-page.php
git commit -m "feat: modernize SEO audit page with score ring and status cards"
```

---

### Task 7: Progress, Modal, and Remaining Component Styles

**Files:**
- Modify: `admin/css/admin.css`

- [ ] **Step 1: Update progress bar and spinner styles**

Find and update the `.swps-progress` and `.swps-spinner` rules:

Old:
```css
.swps-progress {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    margin-top: 20px;
    background: #f0f6fc;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
}

.swps-spinner {
    width: 36px;
    height: 36px;
    border: 4px solid #c3c4c7;
    border-top-color: #2271b1;
    border-radius: 50%;
    animation: swps-spin 0.8s linear infinite;
    flex-shrink: 0;
}
```

New:
```css
.swps-progress {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    margin-top: 20px;
    background: var(--swps-secondary-light);
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius);
}

.swps-spinner {
    width: 36px;
    height: 36px;
    border: 4px solid var(--swps-border);
    border-top-color: var(--swps-accent);
    border-radius: 50%;
    animation: swps-spin 0.8s linear infinite;
    flex-shrink: 0;
}
```

- [ ] **Step 2: Update modal styles**

Find and update the `.swps-modal-dialog` and related rules:

Old:
```css
.swps-modal-dialog {
    position: relative;
    background: #fff;
    border-radius: 6px;
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
}
```

New:
```css
.swps-modal-dialog {
    position: relative;
    background: var(--swps-surface);
    border-radius: var(--swps-radius-lg);
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: var(--swps-shadow-lg);
}
```

Also update `.swps-modal-header`:

Old:
```css
.swps-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 24px;
    border-bottom: 1px solid #dcdcde;
}
```

New:
```css
.swps-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 24px;
    border-bottom: 1px solid var(--swps-border-light);
}
```

And `.swps-modal-footer`:

Old:
```css
.swps-modal-footer {
    display: flex;
    gap: 10px;
    padding: 16px 24px;
    border-top: 1px solid #dcdcde;
    justify-content: flex-end;
}
```

New:
```css
.swps-modal-footer {
    display: flex;
    gap: 10px;
    padding: 16px 24px;
    border-top: 1px solid var(--swps-border-light);
    justify-content: flex-end;
}
```

- [ ] **Step 3: Update the date range selector for analytics**

Find and update `.swps-range-btn.active`:

Old:
```css
.swps-range-btn.active {
    background: #2271b1;
    color: #fff;
    border-color: #2271b1;
}
```

New:
```css
.swps-range-btn.active {
    background: var(--swps-primary);
    color: #fff;
    border-color: var(--swps-primary);
}
```

- [ ] **Step 4: Update the GSC connected status**

Old:
```css
.swps-gsc-connected {
    color: #00a32a;
}
```

New:
```css
.swps-gsc-connected {
    color: var(--swps-success);
}
```

- [ ] **Step 5: Update rate limit indicator**

Old:
```css
.swps-rate-limit {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    margin-bottom: 16px;
    background: #f0f6fc;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    font-size: 13px;
}
```

New:
```css
.swps-rate-limit {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    margin-bottom: 16px;
    background: var(--swps-accent-light);
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius-sm);
    font-size: 13px;
}
```

- [ ] **Step 6: Update status dots**

Old:
```css
.swps-status-active {
    background: #00a32a;
}

.swps-status-inactive {
    background: #dba617;
}
```

New:
```css
.swps-status-active {
    background: var(--swps-success);
}

.swps-status-inactive {
    background: var(--swps-warning);
}
```

- [ ] **Step 7: Update SERP preview and social card styles**

Old:
```css
.swps-serp-mock { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px; max-width: 600px; }
```

New:
```css
.swps-serp-mock { background: var(--swps-surface); border: 1px solid var(--swps-border); border-radius: var(--swps-radius); padding: 16px; max-width: 600px; box-shadow: var(--swps-shadow); }
```

Old:
```css
.swps-seo-checklist { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 16px; margin: 16px 0; }
```

New:
```css
.swps-seo-checklist { background: var(--swps-bg); border: 1px solid var(--swps-border); border-radius: var(--swps-radius-sm); padding: 12px 16px; margin: 16px 0; }
```

Old:
```css
.swps-check-pass { color: #00a32a; }
.swps-check-fail { color: #d63638; }
.swps-check-neutral { color: #646970; }
```

New:
```css
.swps-check-pass { color: var(--swps-success); }
.swps-check-fail { color: var(--swps-error); }
.swps-check-neutral { color: var(--swps-text-muted); }
```

Old:
```css
.swps-count-green { color: #00a32a; }
.swps-count-yellow { color: #dba617; }
.swps-count-red { color: #d63638; }
```

New:
```css
.swps-count-green { color: var(--swps-success); }
.swps-count-yellow { color: var(--swps-warning); }
.swps-count-red { color: var(--swps-error); }
```

- [ ] **Step 8: Update remaining hardcoded colors to CSS variables**

Scan through the entire `admin.css` for any remaining `#c3c4c7`, `#dcdcde`, `#646970`, `#1d2327`, `#2271b1`, `#00a32a`, `#d63638`, `#dba617`, `#f0f6fc` and replace with their corresponding CSS variables.

- [ ] **Step 9: Verify generate page, modals, SERP preview, and other components use new palette**

- [ ] **Step 10: Commit**

```bash
git add admin/css/admin.css
git commit -m "feat: update progress, modal, SERP preview, and all remaining component colors"
```

---

### Task 8: Calendar Styles

**Files:**
- Modify: `admin/css/calendar.css`

- [ ] **Step 1: Update calendar.css**

Rewrite the FullCalendar overrides and legend:

Old (entire file content):

New:
```css
/* StrataWP SEO Calendar Styles */

.swps-calendar-wrap {
    max-width: 1200px;
}

#swps-calendar {
    background: var(--swps-surface);
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius);
    padding: 16px;
    margin-top: 0;
    box-shadow: var(--swps-shadow);
}

/* FullCalendar overrides */
.fc .fc-toolbar-title {
    font-size: 18px !important;
    color: var(--swps-text) !important;
}

.fc .fc-button-primary {
    background-color: var(--swps-primary) !important;
    border-color: var(--swps-primary) !important;
    border-radius: var(--swps-radius-sm) !important;
    transition: all 200ms ease !important;
}

.fc .fc-button-primary:hover {
    background-color: var(--swps-primary-light) !important;
    border-color: var(--swps-primary-light) !important;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.2) !important;
}

.fc .fc-button-primary:not(:disabled).fc-button-active {
    background-color: var(--swps-primary-light) !important;
}

.fc .fc-daygrid-day:hover {
    background: var(--swps-bg);
    cursor: pointer;
}

.fc .fc-event {
    cursor: pointer;
    border-radius: var(--swps-radius-sm);
    padding: 2px 6px;
    font-size: 12px;
}

/* Legend */
.swps-calendar-legend {
    display: flex;
    gap: 20px;
    margin-top: 16px;
    padding: 12px 16px;
    background: var(--swps-surface);
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius);
    box-shadow: var(--swps-shadow-sm);
}

.swps-legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--swps-text-muted);
}

.swps-legend-dot {
    width: 12px;
    height: 12px;
    border-radius: var(--swps-radius-sm);
    display: inline-block;
}

.swps-legend-queued { background: var(--swps-warning); }
.swps-legend-generating { background: var(--swps-secondary); }
.swps-legend-published { background: var(--swps-success); }
.swps-legend-failed { background: var(--swps-error); }
.swps-legend-existing { background: var(--swps-text-light); }

/* Modal */
.swps-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 100100;
}

.swps-modal.swps-modal-open {
    display: flex;
    align-items: center;
    justify-content: center;
}

.swps-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
}

.swps-modal-content {
    position: relative;
    background: var(--swps-surface);
    border-radius: var(--swps-radius-lg);
    padding: 32px;
    max-width: 500px;
    width: 90%;
    box-shadow: var(--swps-shadow-lg);
    z-index: 1;
}

.swps-modal-close {
    position: absolute;
    top: 12px;
    right: 16px;
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--swps-text-muted);
    line-height: 1;
}

.swps-modal-close:hover {
    color: var(--swps-text);
}

.swps-modal h2 {
    margin-top: 0;
    font-size: 18px;
    color: var(--swps-text);
}

.swps-modal .swps-form-group {
    margin-bottom: 16px;
}

.swps-modal .swps-form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
    font-size: 13px;
    color: var(--swps-text);
}

.swps-modal input[type="text"],
.swps-modal input[type="date"],
.swps-modal select,
.swps-modal textarea {
    width: 100%;
    max-width: 100%;
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius-sm);
}

.swps-modal input:focus,
.swps-modal textarea:focus,
.swps-modal select:focus {
    border-color: var(--swps-accent);
    box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.15);
    outline: none;
}

/* Detail modal */
.swps-detail-table {
    width: 100%;
    margin: 16px 0;
}

.swps-detail-table td {
    padding: 6px 0;
    vertical-align: top;
}

.swps-detail-table td:first-child {
    color: var(--swps-text-muted);
    width: 90px;
    font-weight: 600;
}

.swps-modal-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}
```

- [ ] **Step 2: Remove the old header-bar from calendar-page.php**

In `templates/calendar-page.php`, remove lines 12-14 (the old `.swps-header-bar` div — no longer needed since the page header now has the subtitle):

Old:
```php
    <div class="swps-header-bar">
        <p><?php esc_html_e( 'Plan your content schedule. Click any date to add a topic, drag to reschedule, click a topic to view details.', 'stratawp-seo' ); ?></p>
    </div>
```

Remove this block entirely (the page header from Task 2 already has the description).

- [ ] **Step 3: Verify the calendar page**

- [ ] **Step 4: Commit**

```bash
git add admin/css/calendar.css templates/calendar-page.php
git commit -m "feat: update calendar styles to Slate & Coral palette"
```

---

### Task 9: Stats Grid, Result Card, and Generate Page Polish

**Files:**
- Modify: `admin/css/admin.css`

- [ ] **Step 1: Update stats grid and result card styles**

Find and update the stats grid:

Old:
```css
.swps-stat-number {
    display: block;
    font-size: 28px;
    font-weight: 700;
    color: #1d2327;
    line-height: 1.2;
}

.swps-stat-label {
    display: block;
    font-size: 12px;
    color: #646970;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
```

New:
```css
.swps-stat-number {
    display: block;
    font-size: 28px;
    font-weight: 700;
    color: var(--swps-text);
    line-height: 1.2;
}

.swps-stat-label {
    display: block;
    font-size: 11px;
    font-weight: 500;
    color: var(--swps-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 4px;
}
```

Find and update the result card:

Old:
```css
.swps-card-result {
    border-left: 4px solid #00a32a;
    margin-top: 20px;
}
```

New:
```css
.swps-card-result {
    border-left: 4px solid var(--swps-success);
    margin-top: 20px;
}
```

Find and update the preview meta:

Old:
```css
.swps-preview-meta {
    background: #f0f6fc;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 12px 16px;
    margin-bottom: 20px;
}
```

New:
```css
.swps-preview-meta {
    background: var(--swps-secondary-light);
    border: 1px solid var(--swps-border);
    border-radius: var(--swps-radius-sm);
    padding: 12px 16px;
    margin-bottom: 20px;
}
```

- [ ] **Step 2: Verify generate page looks polished**

- [ ] **Step 3: Commit**

```bash
git add admin/css/admin.css
git commit -m "feat: polish stats grid, result cards, and preview styles"
```

---

### Task 10: Responsive Audit, Loading State, and Social Card Styles

**Files:**
- Modify: `admin/css/admin.css`

- [ ] **Step 1: Update responsive audit breakpoint**

Old:
```css
@media (max-width: 782px) {
    .swps-audit-header {
        flex-direction: column;
        text-align: center;
    }
}
```

New:
```css
@media (max-width: 782px) {
    .swps-audit-header {
        flex-direction: column;
        text-align: center;
    }
    .swps-audit-modules {
        grid-template-columns: 1fr;
    }
    .swps-page-header {
        padding: 20px 16px;
    }
}
```

- [ ] **Step 2: Update loading state**

Old:
```css
.swps-loading {
    color: #646970;
    text-align: center;
    padding: 16px;
}
```

New:
```css
.swps-loading {
    color: var(--swps-text-muted);
    text-align: center;
    padding: 24px 16px;
}
```

- [ ] **Step 3: Update social card styles**

Old:
```css
.swps-social-card { border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; max-width: 500px; }
.swps-social-image { height: 150px; background-size: cover; background-position: center; background-color: #f0f0f0; }
```

New:
```css
.swps-social-card { border: 1px solid var(--swps-border); border-radius: var(--swps-radius); overflow: hidden; max-width: 500px; box-shadow: var(--swps-shadow-sm); }
.swps-social-image { height: 150px; background-size: cover; background-position: center; background-color: var(--swps-bg); }
```

Old:
```css
.swps-social-domain { font-size: 11px; color: #646970; text-transform: uppercase; }
```

New:
```css
.swps-social-domain { font-size: 11px; color: var(--swps-text-muted); text-transform: uppercase; }
```

Old:
```css
.swps-social-desc { font-size: 12px; color: #646970; }
```

New:
```css
.swps-social-desc { font-size: 12px; color: var(--swps-text-muted); }
```

- [ ] **Step 4: Commit**

```bash
git add admin/css/admin.css
git commit -m "feat: update responsive styles, loading states, and social card styling"
```

---

### Task 11: Version Bump and Build

**Files:**
- Modify: `stratawp-seo.php` (version number)

- [ ] **Step 1: Bump version in stratawp-seo.php**

Update the plugin version constant and header to the next minor version (check current version first, increment appropriately — currently 3.6.1, bump to 3.7.0).

- [ ] **Step 2: Build the deployment zip**

```bash
npm run build
```

Or the project's zip build command if different.

- [ ] **Step 3: Verify the build succeeds**

- [ ] **Step 4: Commit**

```bash
git add stratawp-seo.php
git commit -m "chore: bump version to 3.7.0 for admin visual refresh"
```

---

## Self-Review Notes

**Spec coverage check:**
- Design tokens ✓ (Task 1)
- Page headers ✓ (Task 2)
- Cards, buttons, badges ✓ (Task 3)
- Tables, forms, intent badges ✓ (Task 4)
- Analytics metric cards + Chart.js ✓ (Task 5)
- Audit score ring + module cards ✓ (Task 6)
- Progress, modals, remaining components ✓ (Task 7)
- Calendar theme ✓ (Task 8)
- Stats grid, result cards ✓ (Task 9)
- Responsive, loading, social cards ✓ (Task 10)
- Version bump ✓ (Task 11)

**Not covered (by design — CSS-first approach, these render from PHP admin classes):**
- Redirects page header: needs edit to `includes/class-redirect-admin.php` render method
- Sitemaps page header: needs edit to `includes/class-sitemap-admin.php` render method
- Internal links page header: needs edit to `includes/class-internal-links-admin.php` render method
- Search appearance page: template file doesn't exist yet in the codebase (rendered differently)

These 3-4 pages will inherit the new card/table/button styles from the CSS changes but won't get gradient headers without editing their PHP render methods. This can be a follow-up task.

**Placeholder scan:** No TBDs, TODOs, or vague references found.

**Type consistency:** CSS class names are consistent throughout (`.swps-page-header`, `.swps-page-header-orb`, etc.). Chart.js variable `chartInstance` used consistently.
