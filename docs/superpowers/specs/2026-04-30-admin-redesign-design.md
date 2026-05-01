# StrataWP SEO — Admin Redesign Design Spec

**Status:** Draft for review
**Date:** 2026-04-30
**Author:** Jon Imms (with Claude)
**Plugin version target:** 4.0.0
**Visual reference:** `.superpowers/brainstorm/40290-1777599404/content/dashboard-v2.html`, `inner-pages.html`

---

## 1. Goal

Replace the page-per-feature WordPress admin pattern with a **branded admin shell** ("StrataWP SEO Hub") that visually positions the plugin as an AI-forward SEO platform that beats RankMath. Every existing page gains the same chrome; two new modules (AI Auto-Optimize, Competitors) ship in the same release.

This spec defines the **design language and shell architecture only**. Per-feature implementation specs (Auto-Optimize logic, Competitors data model, etc.) are separate documents linked at the bottom.

### Non-goals
- Backlinks integration (deferred — needs decision on paid data partner)
- Per-feature behavior specs (separate docs)
- Frontend (public site) styling — unchanged
- Block editor (Gutenberg) integration — unchanged

---

## 2. Design Language

### 2.1 Personality
**AI-Forward Bold.** Dark chrome by default, glowing gradient accents, glassmorphism on tiles. Distinct from RankMath's white/blue corporate look; signals "this plugin is an AI-native tool, not a checklist." Light mode required for accessibility and agency users.

### 2.2 Color tokens (CSS variables)

All tokens live on `:root` in dark mode and `[data-swps-theme="light"]` for light mode. Existing `--swps-*` variables are kept for back-compat and aliased to the new system; new variables use the `--swps-` prefix and a semantic name (`--swps-bg-app`, `--swps-text-primary`, etc.) — not the old positional names (`--swps-primary`).

**Dark (default):**
| Token | Value | Usage |
|---|---|---|
| `--swps-bg-app` | `linear-gradient(180deg, #0F172A 0%, #1E1B4B 100%)` | App background |
| `--swps-bg-chrome` | `rgba(15,23,42,0.85)` + `backdrop-filter: blur(12px)` | Top bar |
| `--swps-bg-side` | `rgba(15,23,42,0.5)` | Sidebar |
| `--swps-bg-surface` | `rgba(30,41,59,0.4)` + `backdrop-filter: blur(8px)` | Tiles, cards |
| `--swps-bg-input` | `rgba(15,23,42,0.6)` | Form inputs |
| `--swps-border` | `rgba(255,255,255,0.06)` | Tile borders |
| `--swps-border-strong` | `rgba(255,255,255,0.1)` | Input borders |
| `--swps-text-primary` | `#F1F5F9` | Headings, primary text |
| `--swps-text-body` | `#E2E8F0` | Body text |
| `--swps-text-muted` | `#94A3B8` | Secondary text |
| `--swps-text-faint` | `#64748B` | Tertiary, helper text |
| `--swps-accent-1` | `#38BDF8` | Sky — gradient start, primary actions, focus rings |
| `--swps-accent-2` | `#A78BFA` | Violet — gradient end, AI badges |
| `--swps-accent-grad` | `linear-gradient(135deg, #38BDF8, #A78BFA)` | Logo, primary CTAs, hero numbers |
| `--swps-success` | `#34D399` | Pass states |
| `--swps-warn` | `#FBBF24` | Warning states |
| `--swps-crit` | `#F87171` | Critical states |
| `--swps-ai-grad` | `linear-gradient(135deg, #A78BFA, #F472B6)` | "AI" feature badges only |

**Light:**
Same token names, swapped values. Background becomes `#F8FAFC`, chrome becomes `#FFFFFF` with subtle border, accents stay sky→violet, text inverts. Glassmorphism backdrop-blur is dropped in light mode (it looks cheap on white). Light mode is a one-time `[data-swps-theme="light"]` selector swap; no JS rebuild needed.

### 2.3 Type
Inter (Google Font, self-hosted in `admin/fonts/`) with system fallback. Sizes:
- `12px` — labels, helper text
- `13px` — body, nav items
- `14-15px` — section headings
- `20px` — page H1
- `26-32px` — KPI numbers (gradient text fill)

Letter-spacing `-0.01em` on H1; `0.06em` uppercase on labels.

### 2.4 Radii, shadows, motion
- Radius: `6px` small (chips/pills), `8px` inputs, `10px` cards/tiles, `12px` shell
- Shadow: replaced by `border` + `backdrop-filter: blur(8px)`; gradient elements get `box-shadow: 0 0 16px rgba(167,139,250,.25)` glow
- Motion: 200ms ease for hover, 150ms for color/border transitions; gradient buttons pulse glow on hover

---

## 3. Shell Architecture

### 3.1 Layout
```
┌────────────────────────────────────────────────────────────┐
│  Top bar (60px)                                            │
├──────────┬─────────────────────────────────────────────────┤
│ Sidebar  │  Page content                                   │
│ (220px)  │                                                 │
│          │                                                 │
└──────────┴─────────────────────────────────────────────────┘
```

Whole shell sits inside WP's main content area for StrataWP admin pages only. WP's left admin sidebar (`#adminmenuwrap`) and top admin bar (`#wpadminbar`) **stay visible**; our shell occupies the `#wpbody-content` region. CSS in `shell.css` zeroes out `#wpcontent`'s default `padding-left/right` and `margin` for our pages so the shell goes edge-to-edge of the WP content area. The shell is a `<div class="swps-shell">` rendered by `SWPS_Admin_Shell::render_open()` on `in_admin_header` and closed on `admin_footer`, scoped to pages whose hook prefix matches our menu slugs.

### 3.2 Top bar
- **Logo mark** — 28×28 gradient square with "S" mark (placeholder — replaceable with full logo asset)
- **Logo text** — "StrataWP SEO" (clickable → Dashboard)
- **Breadcrumb** — `/ Group / Page` (e.g. `/ SEO / Audit`); current page bold
- **Spacer**
- **Global search** — `⌕ Search settings, posts, keywords...` (220px, opens command-palette overlay; v1 = simple page jump, v2 = unified search across posts/settings/keywords)
- **Theme toggle** — sun/moon icon, persists per-user via user meta
- **Help icon** — opens help drawer (slide-in panel from right; per-page contextual help)

### 3.3 Sidebar
- **Width:** 220px, no collapsed state in v1 (add in v2 for tablet)
- **Standalone item:** Dashboard
- **Groups (with uppercase 10px labels):**
  - **Content** — Generate · Auto-Optimize (NEW) · Calendar · Topic Queue · Voice Profiles
  - **SEO** — Audit · Search Appearance · Schema · Sitemaps · Redirects · Internal Links
  - **Insights** — Analytics · Keywords · Search Console · Competitors (NEW)
  - **System** — Settings · Debug
- **Item state:**
  - Default: muted text, dot bullet
  - Active: sky text, sky dot with glow, sky 2px left border, slight bg tint
  - Hover: brighter text, no other change
  - Badges: red for issue counts (e.g. Audit `3`), gradient for `New`
- **No collapsing groups** — all 4 always expanded; if total items grow past ~18, revisit

### 3.4 Page header (within main)
- H1 + one-line subtitle on left
- Right-aligned button row (Secondary buttons + one Primary)
- Primary button uses `--swps-accent-grad` background with glow shadow
- Secondary buttons use `rgba(255,255,255,0.06)` bg + `--swps-border-strong` border

### 3.5 Out of scope for shell v1
- Floating "Generate" FAB — defer (could conflict with WP page actions)
- Notification toasts — use existing WP admin notices for now; redesign in v2
- Keyboard shortcuts (`Cmd+K` for search, etc.) — defer
- Mobile/tablet collapsed sidebar — defer

---

## 4. Dashboard

The dashboard is the new landing page (`admin.php?page=swps-dashboard`). The current Settings page becomes `swps-settings`. WP's auto-redirect `swps` → `swps-dashboard`.

### 4.1 Above-the-fold (Activity hub)
**Welcome line** — "Welcome back, {first_name}" + one-sentence summary computed at request time:
> Site Health 87. 2 posts published this week. **Auto-Optimize ready to refresh 14 old posts.**

Bolded clause is action-oriented and links to its source page.

**Row 1 — KPI tiles (4 columns):**
1. **Site Health** — conic gradient ring (87/100), "Healthy" label, "3 fixable / 2h ago"
2. **Posts (30d)** — total view count, % delta vs previous 30d
3. **Backlinks** — count + new this week (placeholder values until backlinks module ships; tile reads "Connect a backlink source →" if no API key)
4. **AI Cost (30d)** — gradient $$$ + generation count

**Row 2 (2:1 ratio):**
- **Recent AI generations** — last 3 with icon, title, meta (template · words · model · status), score chip
- **Quick actions** — 4 tile-buttons: Generate post / Run audit / Auto-Optimize / Striking keywords

**Row 3 (2:1 ratio):**
- **AI Auto-Optimize queue** — top 3 posts queued for AI editing with current → projected score, edit count, est. cost; footer line shows total queued + next scheduled run + total est. cost; "Review all →" link
- **Issues to fix** — 3-row list: Critical (red pill) + Warning (amber) + AI Heal (gradient pill); each has CTA link

**Row 4 (2:2 in a 4-col grid):**
- **Competitor watch** — 3 tracked sites with favicon, name, change summary, rank delta pill
- **Backlinks (this week)** — 3-stat header (new / lost / DR) + 2-row recent list (graceful empty state if no API connected)

### 4.2 Below-the-fold (Modules grid)
Section header `Modules` + subtitle `Toggle features on or off · {enabled} enabled, {available} available`.

**4-column grid of module cards.** Each card has:
- Gradient-tinted icon square
- Title + 1-2 sentence description (min-height matched so toggle row aligns)
- Footer row: `Settings →` link (left), toggle switch (right)
- Optional badge top-right: `New` (sky→violet), `AI New` (violet→pink), `Pro` (outlined)

**Modules in scope for v4.0:**
| Module | Status | Notes |
|---|---|---|
| AI Content Generation | existing | |
| AI Auto-Optimize | NEW | Spec: separate doc |
| Competitors | NEW | Spec: separate doc |
| SEO Audit | existing | |
| Schema | existing → enhanced | Add HowTo, Product, Recipe, Course, Event, Job (separate spec) |
| Redirects | existing | |
| Sitemaps | existing | |
| Internal Links | existing | |
| Local SEO | NEW (placeholder card) | "Coming soon" disabled state |
| WooCommerce SEO | NEW (placeholder card) | "Coming soon" disabled state |
| Image SEO | NEW (placeholder card) | "Coming soon" disabled state |

**Module on/off toggle behavior:** persists to `swps_modules_enabled` option (array of module slugs). When a module is off, its sidebar entry is hidden, its hooks are not registered, and its admin page returns a 403 if accessed directly. Default: all "existing" modules on, all "NEW" modules off.

---

## 5. Page Templates

Two reusable templates cover ~95% of inner pages.

### 5.1 Settings template (form-heavy)
**Used for:** Settings, Search Appearance, Voice Profile edit, Schema settings, per-module settings.

```
┌ Page header (H1 + subtitle + Save button) ─────────────────┐
│                                                            │
├ Top tabs (broad categories) ───────────────────────────────┤
│  [ AI & Providers ] [ Content Defaults ] [ Schedule ] ...  │
├ Content row (200px subnav | flex form column) ─────────────┤
│  ┌─Subnav──┐  ┌─Form sections (cards) ──────────────────┐  │
│  │ Section │  │ ┌─ Section card ────────────────────┐  │  │
│  │ Section │  │ │ H3 + desc                         │  │  │
│  │ Section │  │ │ Row: label-left | control-right   │  │  │
│  │ Section │  │ │ Row: label-left | control-right   │  │  │
│  └─────────┘  │ └───────────────────────────────────┘  │  │
│               │ ┌─ Section card ────────────────────┐  │  │
│               │ │ ...                               │  │  │
│               │ └───────────────────────────────────┘  │  │
│               └────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────┘
```

- **Top tabs** = broad categories (max 6); pill-shaped, gradient-tinted active state
- **Sub-nav** = sticky, 200px, scrolls with sections (anchor links). Active state is sky text + left border
- **Form sections** = cards with H3 title + description + rows; rows separated by `1px rgba(255,255,255,0.04)` divider
- **Field control patterns:** input, select, button-group (inline pills like Provider picker), toggle switch, password with `••••` mask, encrypted indicator with verified-checkmark

**Save model:** `Save changes` button in header is sticky. Auto-save on toggle changes (debounced 500ms); explicit save for text fields. Inline `Saved ✓` flash next to changed fields.

### 5.2 Data-dense template (issues / lists / queues)
**Used for:** Audit, Auto-Optimize queue, Internal Links suggestions, Redirects list, 404 log, Keywords list, Competitors keyword gaps.

```
┌ Page header ───────────────────────────────────────────────┐
├ Summary tile row (4 KPIs) ─────────────────────────────────┤
│  [ KPI ] [ KPI ] [ KPI ] [ KPI ]                           │
├ Section header + filter chips ─────────────────────────────┤
│  H3       [ All ] [ Issues only ] [ Auto-fixable ]         │
├ Module/row cards (expandable) ─────────────────────────────┤
│  ┌ Status icon | Name+msg | Score | Action btn | ▾ ┐      │
│  └─ (when expanded) detail rows with per-item actions ┘    │
│  ┌ Status icon | Name+msg | Score | Action btn | ▸ ┐      │
│  ...                                                        │
└────────────────────────────────────────────────────────────┘
```

- **Status icons:** ✓ (success), ! (warn — amber), ! (crit — red); 28px circle with tinted bg
- **Expandable rows** preferred over master-detail panel for v1 (less navigation overhead)
- **Bulk actions** sit in a sticky action bar that appears when ≥1 row is selected (defer to v2 if not immediately needed for Audit)

### 5.3 Other patterns (briefly)
- **Generate page** — single-column wizard (topic → template → keyword → voice → preview → generate). Uses Settings template for the form half + a live-preview pane on the right.
- **Calendar** — keep current calendar widget but reskin with new tokens and surfaces.
- **Analytics** — keep Chart.js but restyle gridlines/colors to dark mode; tile layout from the data-dense template above the charts.

---

## 6. New Modules in Scope

These modules ship in v4.0 *with the redesign*. Their full specs live in separate docs (referenced below); this section defines only their place in the shell.

### 6.1 AI Auto-Optimize
- **Slug:** `swps-auto-optimize`
- **Sidebar group:** Content
- **Page template:** Data-dense
- **Page surfaces:** queue list (top), schedule controls (right tile), settings link
- **Trust model (locked Q6):** review queue is the default. Per-post-type or per-category opt-in to "Trusted — auto-apply approved edit types." A "trust pattern" matches edit categories (e.g., "alt text only", "schema only") so users can grant narrow trust without giving up review for content rewrites.
- **Edit types (initial):** missing focus keyword in H2 / first paragraph / URL / meta · missing canonical · missing alt text · missing FAQ schema · missing key takeaways · weak intro paragraph · missing internal links
- **Spec doc:** `docs/superpowers/specs/2026-XX-XX-auto-optimize-design.md` (TBD)

### 6.2 Competitors
- **Slug:** `swps-competitors`
- **Sidebar group:** Insights
- **Page template:** Data-dense
- **MVP scope:** track up to N URLs (configurable, default 3 for free / 10 for paid). Weekly scan: post count delta, schema diff (which schema types newly added/removed), publish velocity (posts/week trend), title/H1 changes. Keyword gap analysis is **deferred** — needs Ahrefs/SEMrush API.
- **Dashboard widget shows:** 3 most-watched competitors with one-line change summary
- **Spec doc:** `docs/superpowers/specs/2026-XX-XX-competitors-design.md` (TBD)

### 6.3 Backlinks (placeholder only)
- Sidebar entry rendered as disabled "Coming soon" until a paid data partner is selected
- Module card on dashboard shows the same; toggle is disabled
- Dashboard tile shows empty state: "Connect Ahrefs / Moz / SE Ranking →"

---

## 7. Technical Architecture

### 7.1 Asset organization
```
admin/
├── css/
│   ├── tokens.css         NEW — :root and [data-swps-theme="light"] vars
│   ├── shell.css          NEW — top bar, sidebar, page header
│   ├── components.css     NEW — buttons, tiles, toggles, badges, chips
│   ├── templates.css      NEW — settings template, data-dense template
│   ├── pages/
│   │   ├── dashboard.css  NEW — dashboard-specific layout
│   │   └── ...            existing per-page styles get split out
│   ├── admin.css          DEPRECATED — kept for back-compat, gradually emptied
│   └── calendar.css       updated to use tokens
└── js/
    ├── shell.js           NEW — theme toggle, sidebar state, search overlay
    └── ...                existing per-page JS unchanged
```

### 7.2 PHP integration
- New class: `SWPS_Admin_Shell` (in `includes/class-admin-shell.php`)
  - Hooks `admin_head` to inject `<div class="swps-shell">` wrapper open
  - Hooks `admin_footer` to close
  - Conditional: only fires on pages whose hook starts with `toplevel_page_swps-` or `stratawp-seo_page_swps-`
  - Renders top bar + sidebar via PHP partials
- New module registry: `SWPS_Modules` (in `includes/class-modules.php`)
  - Registers each module with: slug, group, name, description, icon, default-on, settings page callback
  - `is_enabled($slug)` reads `swps_modules_enabled` option
  - Hooks that conditionally register a module's admin page + REST routes + cron events guard via `is_enabled()`
- Existing classes (Settings, Generator, etc.) **untouched** beyond reading from the modules registry
- Backwards compat: old `?page=swps-settings` redirects continue to work; old menu URLs redirect to new dashboard

### 7.3 Theme persistence
- Per-user meta key: `swps_theme` = `dark` | `light` | `auto` (default `dark`)
- Server-side: PHP renders `<div data-swps-theme="dark">` based on meta
- Client-side: theme toggle button in top bar updates via REST endpoint, swaps `data-swps-theme` attr immediately, no reload

### 7.4 Build / minification
Phase 1: ship CSS as separate files (no build pipeline needed). Plugin enqueues with version-busted query string from `SWPS_VERSION`.
Phase 2 (post-launch): introduce a simple `npm run build` that concats and minifies the new CSS into `admin/css/dist/` for production use. Not required to ship.

---

## 8. Migration Strategy

### 8.1 Phased rollout within v4.0
The release ships in one go (4.0.0), but internally rolls out per-page so we can land work incrementally on the feature branch:

1. **Tokens + shell scaffold** — top bar + sidebar render on Dashboard only; other pages stay legacy
2. **Dashboard page** — full activity hub + modules grid
3. **Settings page** — converted to new template
4. **Data-dense pages batch** — Audit, Redirects, Internal Links suggestions, 404 log, Keywords
5. **Specialty pages** — Generate (wizard), Calendar (reskin), Analytics (chart restyle)
6. **New modules** — Auto-Optimize, Competitors (their pages use the data-dense template)
7. **Modules registry conversion** — wrap every existing feature in module on/off
8. **Light theme polish + accessibility audit**
9. **Help drawer copy + per-page contextual help**

Each numbered step ships as a sub-PR or feature commit; v4.0.0 ships when 1-9 are merged + smoke-tested.

### 8.2 Backwards compatibility
- Old `--swps-*` CSS variables are aliased so any user customizations via custom CSS still work
- All existing options/meta keys unchanged
- Existing hooks/filters unchanged
- WP-CLI commands unchanged
- REST API unchanged

### 8.3 Conflict matrix
- Yoast/RankMath/AIOSEO active: shell still renders normally; existing per-feature conflict-detect logic untouched (schema/meta auto-disable continues)
- WP admin color scheme set to anything: our shell ignores user's WP color scheme inside our pages
- Mobile WP admin: sidebar collapses to a hamburger drawer (deferred — desktop-first for v1)

---

## 9. Out of Scope (for v4.0)

- Backlinks data integration
- Mobile-optimized sidebar collapse
- Notification toast system (continue using WP admin notices)
- Command-palette search (`Cmd+K`) — search box is page-jump only in v1
- Keyboard shortcuts globally
- White-label / agency branding overrides
- Role manager (per-role plugin permissions)
- News / Video sitemap support
- Recipe / Course / Job / Event schema (separate "Schema enhancement" spec)

---

## 10. Open Questions

1. **Logo asset** — current "S" gradient mark is placeholder. Need a real logo file (SVG) before final visual polish. Treat as a Phase-1.5 task before screenshots/marketing.
2. **Global search v2** — when (if?) to invest in a unified search index (posts + settings + keywords + audit issues). Not blocking v4.0.
3. **Inline help / tooltips** — RankMath has heavy "Learn more" links. We've left these out of the mocks. Decide before final polish whether to add a help drawer + per-field `(?)` tooltips, or keep the cleaner look.
4. **Cost telemetry** — for the dashboard's "AI Cost (30d)" tile, do we surface a forecast ("at current rate, $X by month-end") in v1 or defer to v2?

---

## 11. Linked Specs (to be written after this is approved)

- `auto-optimize-design.md` — full spec for the AI Auto-Optimize module
- `competitors-design.md` — full spec for the Competitors module
- `schema-enhancement-design.md` — Recipe / Course / Job / Event / HowTo / Product
- `image-seo-design.md` — auto-alt, auto-rename, compression
- `local-seo-design.md` — Google Business profile, NAP, LocalBusiness schema
- `woocommerce-seo-design.md` — Product schema, category SEO

These six specs collectively become the "RankMath Killer" feature roadmap that follows the redesign. The redesign's modules grid already reserves real estate for all of them.
