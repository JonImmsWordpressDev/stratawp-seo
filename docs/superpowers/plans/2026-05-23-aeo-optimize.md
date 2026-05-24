# AEO Optimize Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement v4.6.0 — an AEO (Answer Engine Optimization) scoring + per-post optimization layer, a dedicated admin page mirroring Auto-Optimize, a live editor panel, and dynamic schema generation for 5 new types (HowTo, Recipe, Product, Review, QAPage).

**Architecture:** New `SWPS_AEO_*` classes layered on existing patterns: heuristic scorers (3) + optional LLM scorer (1) feed an orchestrator that caches results in post meta. Admin page mirrors `SWPS_Auto_Optimize` (AJAX-based queue + diff modal). Schema generator extends `SWPS_Schema` with 5 new render methods. REST endpoints are thin wrappers over the AJAX-layer logic for external/programmatic access.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, jQuery (admin JS, matching project), `@wordpress/plugins` + React (Gutenberg sidebar only), PHPCS (WordPress standards), PHPStan level 5, optional PHPUnit for pure scorers.

**Spec:** `docs/superpowers/specs/2026-05-23-aeo-optimize-design.md`

**Important deviations from spec:**
- Admin actions use `wp_ajax_*` (matches `SWPS_Auto_Optimize` pattern), not REST. REST endpoints in `class-rest-api.php` are added as thin wrappers for external/programmatic access (same 6 operations).
- Tests for heuristic scorers use lightweight PHPUnit (no WP bootstrap needed — they're pure HTML/string analysis). Full WP integration tests are manual WP-CLI smoke tests, matching project precedent (no `tests/` directory exists).

---

## Phase 0 — Foundation

### Task 1: Scaffold directories and bootstrap entries

**Files:**
- Create: `includes/aeo/.gitkeep`
- Create: `includes/data/.gitkeep`
- Create: `tests/aeo/.gitkeep`
- Create: `tests/fixtures/aeo/.gitkeep`
- Modify: `stratawp-seo.php` (add require_once block)

- [ ] **Step 1: Create directories with .gitkeep placeholders**

```bash
cd /Users/jon.imms/StrataWP-projects/stratawp-seo
mkdir -p includes/aeo includes/data tests/aeo tests/fixtures/aeo
touch includes/aeo/.gitkeep includes/data/.gitkeep tests/aeo/.gitkeep tests/fixtures/aeo/.gitkeep
```

- [ ] **Step 2: Add bootstrap require_once block to `stratawp-seo.php`**

Open `stratawp-seo.php`. Find the block:
```php
// Auto-Optimize (v4.1).
require_once SWPS_PLUGIN_DIR . 'includes/class-auto-optimize.php';
```

Add immediately after it:
```php
// AEO Optimize (v4.6) — heuristic + AI scoring, dynamic schema generator.
require_once SWPS_PLUGIN_DIR . 'includes/aeo/class-extractability-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/aeo/class-markup-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/aeo/class-authority-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/aeo/class-coverage-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-aeo-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-aeo-schema-generator.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-aeo-optimizer.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-aeo-editor-panel.php';
```

- [ ] **Step 3: Create empty class stubs so the bootstrap doesn't fatal**

For each file referenced in Step 2, create with this template (substitute class name and short description):

```php
<?php
/**
 * <one-line description>
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_AEO_Extractability_Scorer {
    // Implementation: Task 5
}
```

Class-name → file mapping:
- `SWPS_AEO_Extractability_Scorer` → `includes/aeo/class-extractability-scorer.php`
- `SWPS_AEO_Markup_Scorer` → `includes/aeo/class-markup-scorer.php`
- `SWPS_AEO_Authority_Scorer` → `includes/aeo/class-authority-scorer.php`
- `SWPS_AEO_Coverage_Scorer` → `includes/aeo/class-coverage-scorer.php`
- `SWPS_AEO_Scorer` → `includes/class-aeo-scorer.php`
- `SWPS_AEO_Schema_Generator` → `includes/class-aeo-schema-generator.php`
- `SWPS_AEO_Optimizer` → `includes/class-aeo-optimizer.php`
- `SWPS_AEO_Editor_Panel` → `includes/class-aeo-editor-panel.php`

- [ ] **Step 4: Run lint and static analysis — verify clean boot**

```bash
cd /Users/jon.imms/StrataWP-projects/stratawp-seo
composer lint -- includes/aeo includes/class-aeo-*.php
composer analyze
```

Expected: PHPCS clean on new files; PHPStan baseline unchanged (no new errors).

- [ ] **Step 5: Commit**

```bash
git add includes/aeo includes/data tests/ stratawp-seo.php includes/class-aeo-*.php
git commit -m "feat(aeo): scaffold v4.6 AEO Optimize class skeletons

- Add includes/aeo/ subdirectory for sub-scorers
- Add includes/data/ for schema-field manifest and authoritative-domains lists
- Add bootstrap entries for 8 new AEO classes (empty stubs)
- Add tests/aeo/ + fixtures placeholder for pure-PHP scorer tests"
```

### Task 2: Set up minimal PHPUnit for unit-testable scorers

**Files:**
- Modify: `composer.json` (add phpunit dev dependency + test script)
- Create: `phpunit.xml.dist`
- Create: `tests/bootstrap.php`
- Create: `tests/aeo/test-smoke.php`

- [ ] **Step 1: Add PHPUnit dependency and test script to composer.json**

Open `composer.json`. In the `require-dev` block add:
```json
"phpunit/phpunit": "^9.6"
```

In the `scripts` block add:
```json
"test": "phpunit"
```

In the `scripts-descriptions` block add:
```json
"test": "Run PHPUnit unit tests (pure-PHP scorers; no WP bootstrap)."
```

Run:
```bash
composer update phpunit/phpunit --no-interaction
```

Expected: phpunit installed under `vendor/phpunit/phpunit/`.

- [ ] **Step 2: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheResultFile=".phpunit.cache/test-results"
    executionOrder="depends,defects"
    forceCoversAnnotation="false"
    beStrictAboutCoversAnnotation="false"
    beStrictAboutOutputDuringTests="true"
    beStrictAboutTodoAnnotatedTests="true"
    failOnRisky="true"
    failOnWarning="true"
    verbose="true">
    <testsuites>
        <testsuite name="aeo-unit">
            <directory>tests/aeo</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Create `tests/bootstrap.php`** (no WP required for unit tests)

```php
<?php
/**
 * PHPUnit bootstrap for pure-PHP scorer unit tests.
 *
 * These tests do NOT load WordPress. Anything requiring WP functions
 * should be tested via manual WP-CLI smoke (see Task 40).
 *
 * @package StrataWP_SEO
 */

// Minimal WP function stubs the scorers need at parse-time.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp-fake/' );
}

require_once __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 4: Create smoke test and run**

`tests/aeo/test-smoke.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
    public function test_phpunit_runs(): void {
        $this->assertSame( 2, 1 + 1 );
    }
}
```

Run:
```bash
composer test
```

Expected: `OK (1 test, 1 assertion)`.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock phpunit.xml.dist tests/bootstrap.php tests/aeo/test-smoke.php
git commit -m "test: add minimal PHPUnit for AEO scorer unit tests

PHPUnit 9.6 added as a dev dependency for pure-PHP unit tests on
the heuristic AEO scorers (no WP bootstrap required). Composer
'test' script wraps phpunit. Full WP integration verification
remains via manual WP-CLI smoke tests."
```

### Task 3: Add data files (schema field manifest + authoritative domains)

**Files:**
- Create: `includes/data/aeo-schema-fields.json`
- Create: `includes/data/authoritative-domains.json`

- [ ] **Step 1: Create the schema field manifest**

`includes/data/aeo-schema-fields.json`:
```json
{
    "howto": {
        "type": "HowTo",
        "required": ["name", "step"],
        "recommended": ["description", "image", "totalTime", "supply", "tool", "estimatedCost"],
        "step_shape": {
            "@type": "HowToStep",
            "fields": ["name", "text", "image", "url"]
        }
    },
    "recipe": {
        "type": "Recipe",
        "required": ["name", "recipeIngredient", "recipeInstructions"],
        "recommended": ["image", "author", "datePublished", "description", "prepTime", "cookTime", "totalTime", "recipeYield", "recipeCategory", "recipeCuisine", "nutrition", "keywords"],
        "instruction_shape": {
            "@type": "HowToStep",
            "fields": ["name", "text"]
        }
    },
    "product": {
        "type": "Product",
        "required": ["name"],
        "recommended": ["image", "description", "brand", "sku", "gtin", "offers", "aggregateRating", "review"],
        "offer_shape": {
            "@type": "Offer",
            "fields": ["price", "priceCurrency", "availability", "url"]
        }
    },
    "review": {
        "type": "Review",
        "required": ["itemReviewed", "reviewRating", "author"],
        "recommended": ["datePublished", "reviewBody", "publisher"],
        "rating_shape": {
            "@type": "Rating",
            "fields": ["ratingValue", "bestRating", "worstRating"]
        }
    },
    "qapage": {
        "type": "QAPage",
        "required": ["mainEntity"],
        "recommended": [],
        "main_entity_shape": {
            "@type": "Question",
            "fields": ["name", "text", "answerCount", "acceptedAnswer", "upvoteCount", "dateCreated", "author"]
        },
        "answer_shape": {
            "@type": "Answer",
            "fields": ["text", "dateCreated", "upvoteCount", "author"]
        }
    }
}
```

- [ ] **Step 2: Create the authoritative-domains seed list**

`includes/data/authoritative-domains.json`:
```json
{
    "version": 1,
    "description": "Seed list of authoritative outbound-link domains for AEO Authority scoring. Filterable via 'swps_authoritative_domains'.",
    "tlds": [".gov", ".edu", ".int", ".mil", ".gov.uk", ".ac.uk", ".edu.au", ".gov.au"],
    "domains": [
        "wikipedia.org",
        "britannica.com",
        "nih.gov",
        "cdc.gov",
        "who.int",
        "nasa.gov",
        "nature.com",
        "science.org",
        "scientificamerican.com",
        "reuters.com",
        "apnews.com",
        "bbc.com",
        "bbc.co.uk",
        "nytimes.com",
        "washingtonpost.com",
        "theguardian.com",
        "ft.com",
        "wsj.com",
        "economist.com",
        "bloomberg.com",
        "hbr.org",
        "mit.edu",
        "stanford.edu",
        "harvard.edu",
        "ox.ac.uk",
        "cam.ac.uk",
        "ietf.org",
        "w3.org",
        "iso.org",
        "ieee.org",
        "developer.mozilla.org",
        "schema.org",
        "google.com",
        "microsoft.com",
        "apple.com"
    ]
}
```

- [ ] **Step 3: Verify JSON parses**

```bash
php -r 'var_dump(json_decode(file_get_contents("includes/data/aeo-schema-fields.json"), true) !== null);'
php -r 'var_dump(json_decode(file_get_contents("includes/data/authoritative-domains.json"), true) !== null);'
```

Expected: both print `bool(true)`.

- [ ] **Step 4: Commit**

```bash
git add includes/data/
git commit -m "feat(aeo): add data files — schema field manifest + authoritative domains

aeo-schema-fields.json defines required/recommended fields per the
5 AEO schema types (HowTo, Recipe, Product, Review, QAPage), used
by the Schema Generator to build prompts and validate output.

authoritative-domains.json seeds the Authority scorer with a list
of high-trust domains and TLDs. Filterable via
'swps_authoritative_domains'."
```

---

## Phase 1 — Settings and Hooks

### Task 4: Register 5 new filter hooks in SWPS_Hooks

**Files:**
- Modify: `includes/class-hooks.php`

- [ ] **Step 1: Read the existing hooks file to understand the pattern**

```bash
cat includes/class-hooks.php | head -80
```

Identify where existing filters are documented (typically a constants list or a `register_filters()` method).

- [ ] **Step 2: Add 5 new filter doc-blocks to the class header**

Append to the class-level docblock (after the last `@since` line):

```php
 * @since 4.6.0 AEO Optimize filter hooks:
 *   - swps_aeo_score          ($total, $post_id, $subscores)         Modify the final AEO score.
 *   - swps_aeo_subscores      ($subscores, $post_id)                 Modify per-dimension sub-scores.
 *   - swps_aeo_proposal       ($proposal, $post_id)                  Modify the AI-returned proposal.
 *   - swps_aeo_schema_json    ($json, $type, $post_id)               Modify generated schema JSON.
 *   - swps_aeo_dimensions     ($dimensions)                          Add/remove scoring dimensions.
```

Hooks do not need explicit registration in `add_filter()` calls — they're documentation only. The actual `apply_filters()` calls happen inside the scorer/optimizer classes.

- [ ] **Step 3: Run PHPCS to verify docblock format**

```bash
composer lint -- includes/class-hooks.php
```

Expected: zero new issues.

- [ ] **Step 4: Commit**

```bash
git add includes/class-hooks.php
git commit -m "feat(aeo): document 5 new filter hooks for AEO Optimize

Adds doc-only entries for swps_aeo_score, swps_aeo_subscores,
swps_aeo_proposal, swps_aeo_schema_json, swps_aeo_dimensions.
Filters are applied in the scorer/optimizer/schema-generator
classes themselves; this file is the central reference."
```

### Task 5: Add AEO settings tab to SWPS_Settings

**Files:**
- Modify: `includes/class-settings.php`
- Modify: `templates/settings-page.php` (add AEO tab)

- [ ] **Step 1: Find existing tab registration in `class-settings.php`**

```bash
grep -n "add_settings_section\|add_settings_field\|register_setting" includes/class-settings.php | head -30
```

Identify the existing pattern (likely `add_settings_section()` calls grouped by tab in an `init_settings()` method).

- [ ] **Step 2: Register AEO options in the appropriate init method**

Add to the class's settings-init method (find by `function ` + `register_setting` calls):

```php
// AEO Optimize (v4.6) ────────────────────────────────────────────────
register_setting( 'swps_aeo_settings', 'swps_aeo_threshold', array(
    'type'              => 'integer',
    'sanitize_callback' => function ( $v ) { return max( 50, min( 95, (int) $v ) ); },
    'default'           => 70,
) );

register_setting( 'swps_aeo_settings', 'swps_aeo_weights', array(
    'type'              => 'array',
    'sanitize_callback' => array( $this, 'sanitize_aeo_weights' ),
    'default'           => array(
        'extractability' => 0.30,
        'markup'         => 0.30,
        'authority'      => 0.20,
        'coverage'       => 0.20,
    ),
) );

register_setting( 'swps_aeo_settings', 'swps_aeo_coverage_enabled', array(
    'type'              => 'boolean',
    'sanitize_callback' => 'rest_sanitize_boolean',
    'default'           => false,
) );

register_setting( 'swps_aeo_settings', 'swps_aeo_enabled_schema_types', array(
    'type'              => 'array',
    'sanitize_callback' => array( $this, 'sanitize_aeo_schema_types' ),
    'default'           => array( 'howto', 'recipe', 'product', 'review', 'qapage' ),
) );

register_setting( 'swps_aeo_settings', 'swps_aeo_post_types', array(
    'type'              => 'array',
    'sanitize_callback' => array( $this, 'sanitize_aeo_post_types' ),
    'default'           => array( 'post', 'page' ),
) );
```

Add three private sanitizer methods to the class:

```php
public function sanitize_aeo_weights( $value ): array {
    $defaults = array(
        'extractability' => 0.30,
        'markup'         => 0.30,
        'authority'      => 0.20,
        'coverage'       => 0.20,
    );
    if ( ! is_array( $value ) ) {
        return $defaults;
    }
    $clean = array();
    foreach ( $defaults as $k => $d ) {
        $clean[ $k ] = isset( $value[ $k ] ) ? max( 0, min( 1, (float) $value[ $k ] ) ) : $d;
    }
    $sum = array_sum( $clean );
    if ( $sum <= 0 ) {
        return $defaults;
    }
    foreach ( $clean as $k => $v ) {
        $clean[ $k ] = round( $v / $sum, 2 );
    }
    return $clean;
}

public function sanitize_aeo_schema_types( $value ): array {
    $allowed = array( 'howto', 'recipe', 'product', 'review', 'qapage' );
    if ( ! is_array( $value ) ) {
        return $allowed;
    }
    return array_values( array_intersect( $allowed, array_map( 'sanitize_key', $value ) ) );
}

public function sanitize_aeo_post_types( $value ): array {
    if ( ! is_array( $value ) ) {
        return array( 'post', 'page' );
    }
    $public = get_post_types( array( 'public' => true ), 'names' );
    $clean  = array_values( array_intersect( $public, array_map( 'sanitize_key', $value ) ) );
    return ! empty( $clean ) ? $clean : array( 'post', 'page' );
}
```

- [ ] **Step 3: Add AEO tab markup to `templates/settings-page.php`**

Locate the tab navigation block (look for `nav-tab-wrapper` or similar tab-rendering markup).

Add a new tab entry:
```php
<a href="?page=stratawp-seo-settings&tab=aeo"
   class="nav-tab <?php echo $active_tab === 'aeo' ? 'nav-tab-active' : ''; ?>">
    <?php esc_html_e( 'AEO', 'stratawp-seo' ); ?>
</a>
```

And in the tab-body switch, add:
```php
<?php if ( $active_tab === 'aeo' ) : ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'swps_aeo_settings' ); ?>

        <h2><?php esc_html_e( 'AEO Score', 'stratawp-seo' ); ?></h2>

        <table class="form-table">
            <tr>
                <th><label for="swps_aeo_threshold"><?php esc_html_e( 'Score threshold', 'stratawp-seo' ); ?></label></th>
                <td>
                    <input type="range" id="swps_aeo_threshold" name="swps_aeo_threshold"
                           min="50" max="95" value="<?php echo esc_attr( get_option( 'swps_aeo_threshold', 70 ) ); ?>"
                           oninput="this.nextElementSibling.value = this.value">
                    <output><?php echo esc_html( get_option( 'swps_aeo_threshold', 70 ) ); ?></output>
                    <p class="description"><?php esc_html_e( 'Posts scoring below this appear in the AEO Optimize queue.', 'stratawp-seo' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Dimension weights', 'stratawp-seo' ); ?></th>
                <td>
                    <?php
                    $w = get_option( 'swps_aeo_weights', array(
                        'extractability' => 0.30, 'markup' => 0.30,
                        'authority' => 0.20, 'coverage' => 0.20,
                    ) );
                    foreach ( array(
                        'extractability' => __( 'Extractability', 'stratawp-seo' ),
                        'markup'         => __( 'Markup',         'stratawp-seo' ),
                        'authority'      => __( 'Authority',      'stratawp-seo' ),
                        'coverage'       => __( 'Coverage',       'stratawp-seo' ),
                    ) as $k => $label ) : ?>
                        <label style="display:block;margin:6px 0;">
                            <?php echo esc_html( $label ); ?>
                            <input type="number" step="0.05" min="0" max="1"
                                   name="swps_aeo_weights[<?php echo esc_attr( $k ); ?>]"
                                   value="<?php echo esc_attr( $w[ $k ] ?? 0.25 ); ?>"
                                   style="width:80px;margin-left:8px;">
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php esc_html_e( 'Weights are auto-normalized on save.', 'stratawp-seo' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Coverage scoring', 'stratawp-seo' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="swps_aeo_coverage_enabled" value="1"
                            <?php checked( get_option( 'swps_aeo_coverage_enabled', false ) ); ?>>
                        <?php esc_html_e( 'Enable Coverage dimension (uses 1 AI call per post — ~$0.001–$0.003 each)', 'stratawp-seo' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Dynamic schema', 'stratawp-seo' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Which dynamic schema types should be rendered when the AEO Optimizer adds them to a post. (Defers automatically when Yoast / RankMath / AIOSEO is active.)', 'stratawp-seo' ); ?></p>

        <?php
        $enabled = (array) get_option( 'swps_aeo_enabled_schema_types', array( 'howto', 'recipe', 'product', 'review', 'qapage' ) );
        foreach ( array(
            'howto'   => 'HowTo',
            'recipe'  => 'Recipe',
            'product' => 'Product',
            'review'  => 'Review',
            'qapage'  => 'QAPage',
        ) as $slug => $label ) : ?>
            <label style="display:block;margin:6px 0;">
                <input type="checkbox" name="swps_aeo_enabled_schema_types[]" value="<?php echo esc_attr( $slug ); ?>"
                    <?php checked( in_array( $slug, $enabled, true ) ); ?>>
                <?php echo esc_html( $label ); ?>
            </label>
        <?php endforeach; ?>

        <h2><?php esc_html_e( 'Post types to score', 'stratawp-seo' ); ?></h2>
        <?php
        $types_enabled = (array) get_option( 'swps_aeo_post_types', array( 'post', 'page' ) );
        foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : ?>
            <label style="display:block;margin:6px 0;">
                <input type="checkbox" name="swps_aeo_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
                    <?php checked( in_array( $pt->name, $types_enabled, true ) ); ?>>
                <?php echo esc_html( $pt->label ); ?> <code><?php echo esc_html( $pt->name ); ?></code>
            </label>
        <?php endforeach; ?>

        <?php submit_button(); ?>
    </form>
<?php endif; ?>
```

- [ ] **Step 4: Lint + manual smoke**

```bash
composer lint -- includes/class-settings.php templates/settings-page.php
```

Manual: in a local WP install, visit `Settings → StrataWP SEO → AEO` (or wherever the existing settings page lives). Verify the tab loads, save changes, and re-read the options:

```bash
wp option get swps_aeo_threshold
wp option get swps_aeo_weights --format=json
```

Expected: stored values reflect your edits, weights sum to 1.0.

- [ ] **Step 5: Commit**

```bash
git add includes/class-settings.php templates/settings-page.php
git commit -m "feat(aeo): add AEO settings tab with threshold, weights, schema toggles

Registers 5 new options under 'swps_aeo_settings' group:
- swps_aeo_threshold (50–95, default 70)
- swps_aeo_weights (4 dimensions, auto-normalized)
- swps_aeo_coverage_enabled (default off)
- swps_aeo_enabled_schema_types (default all 5)
- swps_aeo_post_types (default post + page)

Settings page tab renders the controls with help text noting the
AI cost for Coverage and the Yoast/RankMath/AIOSEO deferral."
```

---

## Phase 2 — Heuristic scorers (TDD)

### Task 6: Implement Extractability scorer (test-first)

**Files:**
- Modify: `includes/aeo/class-extractability-scorer.php`
- Create: `tests/aeo/test-extractability-scorer.php`
- Create: `tests/fixtures/aeo/extractable-high.html`
- Create: `tests/fixtures/aeo/extractable-low.html`

- [ ] **Step 1: Write fixtures**

`tests/fixtures/aeo/extractable-high.html`:
```html
<p>Sourdough is a naturally leavened bread made from flour and water fermented by wild yeast and bacteria.</p>
<h2>Choosing flour</h2>
<p>Bread flour with 12-14% protein produces the best gluten structure for sourdough.</p>
<ul>
  <li>Use bread flour or strong white flour.</li>
  <li>Hydration: 70-75% for a starter loaf.</li>
  <li>Salt: 2% of total flour weight.</li>
</ul>
<h2>Fermentation</h2>
<p>Bulk fermentation runs 4-6 hours at room temperature.</p>
<table>
  <tr><th>Temperature</th><th>Bulk time</th></tr>
  <tr><td>22°C</td><td>5-6 hours</td></tr>
  <tr><td>26°C</td><td>3-4 hours</td></tr>
</table>
```

`tests/fixtures/aeo/extractable-low.html`:
```html
<p>So, I think you might want to try this thing if you have time, maybe?</p>
<p>It could be useful, or it might not, depending on whether things go well.</p>
<p>We've been thinking about this for a while, and we're still not sure.</p>
<p>Anyway, here's what I did when I tried it last weekend.</p>
```

- [ ] **Step 2: Write the failing test**

Place at `tests/aeo/test-extractability-scorer.php` — full file in the [Extractability tests appendix](#appendix-a-extractability-tests).

- [ ] **Step 3: Run tests — verify they fail**

```bash
composer test -- --filter ExtractabilityScorer
```

Expected: 6 failures (methods missing).

- [ ] **Step 4: Implement `SWPS_AEO_Extractability_Scorer`**

Full class body in the [Extractability implementation appendix](#appendix-b-extractability-implementation). Replace the stub class body in `includes/aeo/class-extractability-scorer.php`.

Tests need `wp_strip_all_tags()`. Add this stub to `tests/bootstrap.php` (after the ABSPATH define):

```php
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        $string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
        $string = strip_tags( $string );
        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }
        return trim( $string );
    }
}
```

- [ ] **Step 5: Run tests — verify they pass**

```bash
composer test -- --filter ExtractabilityScorer
composer lint -- includes/aeo/class-extractability-scorer.php
```

Expected: `OK (6 tests, ...)`; PHPCS clean.

- [ ] **Step 6: Commit**

```bash
git add includes/aeo/class-extractability-scorer.php \
        tests/aeo/test-extractability-scorer.php \
        tests/fixtures/aeo/extractable-*.html \
        tests/bootstrap.php
git commit -m "feat(aeo): implement Extractability sub-scorer with tests

4 sub-checks (weighted): self-contained-paragraph rate (30%),
declarative-vs-hedged sentence ratio (30%), structural density
of lists/tables/etc per 1000 words (25%), definitional-lead
pattern detection (15%)."
```

### Task 7: Implement Markup scorer (test-first)

Same structure as Task 6:

**Files:**
- Modify: `includes/aeo/class-markup-scorer.php`
- Create: `tests/aeo/test-markup-scorer.php`
- Create: `tests/fixtures/aeo/markup-qa-heavy.html`, `markup-no-questions.html`, `markup-recipe-like.html`

Fixtures, tests, and implementation are in [Markup appendix](#appendix-c-markup). Steps:

- [ ] **Step 1: Write 3 fixtures (per appendix)**
- [ ] **Step 2: Write failing tests (per appendix) — `composer test -- --filter MarkupScorer` shows 7 fails**
- [ ] **Step 3: Implement `SWPS_AEO_Markup_Scorer` (per appendix)**
- [ ] **Step 4: Verify tests pass + PHPCS clean**
- [ ] **Step 5: Commit:**

```bash
git add includes/aeo/class-markup-scorer.php \
        tests/aeo/test-markup-scorer.php \
        tests/fixtures/aeo/markup-*.html
git commit -m "feat(aeo): implement Markup sub-scorer with schema-type inference

Scores Q&A density (count + directly-followed-by-answer rate) and
schema-type alignment (existing vs expected). Includes
infer_schema_type() detecting HowTo/Recipe/Product/Review/QAPage
from content patterns — reused by Schema Generator (Task 14)."
```

### Task 8: Implement Authority scorer (test-first)

**Files:**
- Modify: `includes/aeo/class-authority-scorer.php`
- Create: `tests/aeo/test-authority-scorer.php`

Tests + implementation in [Authority appendix](#appendix-d-authority). Steps mirror Tasks 6-7:

- [ ] **Step 1: Write failing tests** (6 tests covering byline/freshness/auth-link counts/TLD allowlist/current-year/updated-notice)
- [ ] **Step 2: Verify 6 failures**
- [ ] **Step 3: Implement scorer**
- [ ] **Step 4: Verify tests pass + PHPCS clean**
- [ ] **Step 5: Commit:**

```bash
git add includes/aeo/class-authority-scorer.php tests/aeo/test-authority-scorer.php
git commit -m "feat(aeo): implement Authority sub-scorer with domain allowlist

5 weighted signals: byline (25), freshness (25), authoritative
outbound links per 1k words (30), current-year mention (10),
'updated/reviewed' notice (10). Loads domain allowlist from
includes/data/authoritative-domains.json with
swps_authoritative_domains filter."
```

### Task 9: Implement AEO Scorer orchestrator

**Files:**
- Modify: `includes/class-aeo-scorer.php`
- Create: `tests/aeo/test-aeo-scorer.php`

Tests + implementation in [Orchestrator appendix](#appendix-e-orchestrator). The orchestrator wires the 4 sub-scorers, applies the `swps_aeo_subscores` and `swps_aeo_score` filters, persists results to post meta, and re-normalizes weights when Coverage is null.

- [ ] **Step 1: Write 3 failing tests** (orchestrator returns total + subscores, weight re-norm when Coverage missing, custom weights)
- [ ] **Step 2: Verify 3 failures**
- [ ] **Step 3: Implement `SWPS_AEO_Scorer` (per appendix)** — includes both `score_html()` (pure, testable) and `score_post()` (WP-integrated, manually smoke-tested in Task 40)
- [ ] **Step 4: Verify tests pass + PHPCS clean**
- [ ] **Step 5: Commit:**

```bash
git add includes/class-aeo-scorer.php tests/aeo/test-aeo-scorer.php
git commit -m "feat(aeo): implement AEO Scorer orchestrator with weighted rollup

Combines 4 sub-scorers, applies swps_aeo_subscores + swps_aeo_score
filters, persists to post meta (_swps_aeo_score,
_swps_aeo_subscore_*, _swps_aeo_last_scan). Weights re-normalize
when Coverage is null. Content-hash invalidation ensures cached
Coverage scores invalidate on content change."
```

---


## Phase 3 — Coverage scorer (LLM)

### Task 10: Implement Coverage scorer with mocked AI provider

**Files:**
- Modify: `includes/aeo/class-coverage-scorer.php`
- Create: `tests/aeo/test-coverage-scorer.php`

The Coverage scorer is the only sub-scorer that calls the AI provider. To keep tests pure-PHP, the AI provider is injected; tests pass a stub.

- [ ] **Step 1: Write failing tests**

`tests/aeo/test-coverage-scorer.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-coverage-scorer.php';

/** Minimal stand-in for SWPS_AI_Provider — implements only what Coverage scorer calls. */
final class FakeAIProvider {
    public array $next_response = array();
    public bool $should_fail = false;
    public array $last_messages = array();

    public function chat_json( array $messages, int $max_tokens = 1024 ): array {
        $this->last_messages = $messages;
        if ( $this->should_fail ) {
            throw new RuntimeException( 'AI provider error' );
        }
        return $this->next_response;
    }
}

final class CoverageScorerTest extends TestCase {

    public function test_score_returns_0_to_100_from_ai_response(): void {
        $provider = new FakeAIProvider();
        $provider->next_response = array(
            'coverage_gaps'  => array( 'How to store sourdough' ),
            'entity_issues' => array(),
            'score'          => 72,
        );
        $scorer = new SWPS_AEO_Coverage_Scorer( $provider );
        $result = $scorer->score( 'Sourdough basics', '<p>Sourdough is bread.</p>' );
        $this->assertSame( 72, $result['score'] );
        $this->assertCount( 1, $result['coverage_gaps'] );
    }

    public function test_score_falls_back_on_provider_failure(): void {
        $provider = new FakeAIProvider();
        $provider->should_fail = true;
        $scorer = new SWPS_AEO_Coverage_Scorer( $provider );
        $result = $scorer->score( 'Sourdough basics', '<p>Body.</p>' );
        $this->assertNull( $result['score'] );
        $this->assertSame( 'AI provider error', $result['error'] );
    }

    public function test_score_clamps_out_of_range_values(): void {
        $provider = new FakeAIProvider();
        $provider->next_response = array(
            'coverage_gaps' => array(),
            'entity_issues' => array(),
            'score' => 150,
        );
        $scorer = new SWPS_AEO_Coverage_Scorer( $provider );
        $result = $scorer->score( 'X', '<p>Y.</p>' );
        $this->assertSame( 100, $result['score'] );
    }

    public function test_score_handles_missing_score_field(): void {
        $provider = new FakeAIProvider();
        $provider->next_response = array( 'coverage_gaps' => array(), 'entity_issues' => array() );
        $scorer = new SWPS_AEO_Coverage_Scorer( $provider );
        $result = $scorer->score( 'X', '<p>Y.</p>' );
        $this->assertNull( $result['score'] );
    }

    public function test_outline_includes_h2s_and_first_sentences(): void {
        $html = '<h2>Choosing flour</h2><p>Bread flour with 12-14% protein produces the best gluten.</p>' .
                '<h2>Fermentation</h2><p>Bulk runs 4-6 hours.</p>';
        $scorer = new SWPS_AEO_Coverage_Scorer( new FakeAIProvider() );
        $outline = $scorer->build_outline( $html );
        $this->assertStringContainsString( 'Choosing flour', $outline );
        $this->assertStringContainsString( 'Bread flour with 12-14% protein', $outline );
        $this->assertStringContainsString( 'Fermentation', $outline );
    }
}
```

- [ ] **Step 2: Verify failures**

```bash
composer test -- --filter CoverageScorer
```

Expected: 5 failures.

- [ ] **Step 3: Implement scorer**

Replace stub in `includes/aeo/class-coverage-scorer.php`:

```php
class SWPS_AEO_Coverage_Scorer {

    /** @var object Anything with chat_json(array, int): array */
    private $provider;

    public function __construct( $provider ) {
        $this->provider = $provider;
    }

    /**
     * @return array{score:?int, coverage_gaps:string[], entity_issues:string[], error:?string}
     */
    public function score( string $title, string $html ): array {
        $outline = $this->build_outline( $html );
        $system  = 'You evaluate how completely a blog post covers its topic for AI search citation.';
        $user    = sprintf(
            "Topic: %s\n\nOutline (H2s + opening sentence of each section):\n%s\n\n" .
            "Return JSON with these exact keys: " .
            "{\"coverage_gaps\": [up to 5 sub-topics a reader would expect that are missing], " .
            "\"entity_issues\": [up to 5 entities mentioned vaguely or generically that should be named explicitly], " .
            "\"score\": integer 0–100 reflecting overall coverage and entity clarity}",
            $title,
            $outline
        );

        try {
            $response = $this->provider->chat_json(
                array(
                    array( 'role' => 'system', 'content' => $system ),
                    array( 'role' => 'user',   'content' => $user ),
                ),
                512
            );
        } catch ( \Throwable $e ) {
            return array(
                'score'          => null,
                'coverage_gaps'  => array(),
                'entity_issues'  => array(),
                'error'          => $e->getMessage(),
            );
        }

        $raw_score = $response['score'] ?? null;
        $score     = null;
        if ( is_int( $raw_score ) || ( is_string( $raw_score ) && ctype_digit( $raw_score ) ) ) {
            $score = max( 0, min( 100, (int) $raw_score ) );
        }

        return array(
            'score'          => $score,
            'coverage_gaps'  => array_values( array_filter( (array) ( $response['coverage_gaps']  ?? array() ), 'is_string' ) ),
            'entity_issues'  => array_values( array_filter( (array) ( $response['entity_issues']  ?? array() ), 'is_string' ) ),
            'error'          => null,
        );
    }

    /** Build a compact outline string (H2s + first sentence of each section). */
    public function build_outline( string $html ): string {
        $lines = array();
        if ( preg_match_all( '#<h2[^>]*>(.*?)</h2>(.*?)(?=<h2|$)#is', $html, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $match ) {
                $h2     = trim( wp_strip_all_tags( $match[1] ) );
                $body   = trim( wp_strip_all_tags( $match[2] ) );
                $first  = '';
                if ( preg_match( '/^([^.?!]*[.?!])/u', $body, $sm ) ) {
                    $first = trim( $sm[1] );
                }
                $lines[] = '## ' . $h2;
                if ( '' !== $first ) {
                    $lines[] = $first;
                }
            }
        }
        if ( empty( $lines ) ) {
            // No H2s — use first 300 chars.
            $lines[] = trim( wp_strip_all_tags( substr( $html, 0, 1200 ) ) );
        }
        return implode( "\n", $lines );
    }
}
```

- [ ] **Step 4: Verify tests pass + lint**

```bash
composer test -- --filter CoverageScorer
composer lint -- includes/aeo/class-coverage-scorer.php
```

Expected: `OK (5 tests, ...)`; PHPCS clean.

- [ ] **Step 5: Commit**

```bash
git add includes/aeo/class-coverage-scorer.php tests/aeo/test-coverage-scorer.php
git commit -m "feat(aeo): implement Coverage sub-scorer with provider DI

Sends post outline (H2s + opening sentences) to injected AI
provider, parses {coverage_gaps, entity_issues, score} JSON,
clamps and returns. Falls back gracefully (score=null) on
provider error. Provider is dependency-injected so tests use
a pure-PHP fake — no AI calls in CI."
```

---

## Phase 4 — Schema Generator and rendering

### Task 11: Implement Schema Generator with per-type prompts

**Files:**
- Modify: `includes/class-aeo-schema-generator.php`
- Create: `tests/aeo/test-aeo-schema-generator.php`

- [ ] **Step 1: Write failing tests**

`tests/aeo/test-aeo-schema-generator.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-aeo-schema-generator.php';

// Reuse FakeAIProvider from CoverageScorerTest.
require_once __DIR__ . '/test-coverage-scorer.php';

final class AeoSchemaGeneratorTest extends TestCase {

    private function fields_path(): string {
        return __DIR__ . '/../../includes/data/aeo-schema-fields.json';
    }

    public function test_generate_recipe_returns_valid_jsonld(): void {
        $provider = new FakeAIProvider();
        $provider->next_response = array(
            '@context'           => 'https://schema.org',
            '@type'              => 'Recipe',
            'name'               => 'Chocolate Chip Cookies',
            'recipeIngredient'   => array( '2 cups flour', '1 cup sugar' ),
            'recipeInstructions' => array(
                array( '@type' => 'HowToStep', 'text' => 'Cream butter and sugar.' ),
                array( '@type' => 'HowToStep', 'text' => 'Bake at 180°C.' ),
            ),
        );

        $gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
        $r   = $gen->generate( 'recipe', 'My Cookies', '<p>...</p>' );

        $this->assertSame( 'Recipe', $r['json']['@type'] );
        $this->assertSame( 'https://schema.org', $r['json']['@context'] );
        $this->assertNull( $r['error'] );
    }

    public function test_validation_rejects_missing_required_field(): void {
        $provider = new FakeAIProvider();
        $provider->next_response = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Recipe',
            // Missing 'name' (required).
            'recipeIngredient'   => array( '...' ),
            'recipeInstructions' => array( array( '@type' => 'HowToStep', 'text' => '...' ) ),
        );

        $gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
        $r   = $gen->generate( 'recipe', 'X', '<p>Y</p>' );

        $this->assertNull( $r['json'] );
        $this->assertStringContainsString( 'name', (string) $r['error'] );
    }

    public function test_validation_rejects_wrong_type(): void {
        $provider = new FakeAIProvider();
        $provider->next_response = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Article',  // wrong: caller asked for recipe
            'name'     => 'X',
            'recipeIngredient'   => array(),
            'recipeInstructions' => array(),
        );
        $gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
        $r   = $gen->generate( 'recipe', 'X', '<p>Y</p>' );

        $this->assertNull( $r['json'] );
        $this->assertStringContainsString( '@type', (string) $r['error'] );
    }

    public function test_validation_rejects_wrong_context(): void {
        $provider = new FakeAIProvider();
        $provider->next_response = array(
            '@context' => 'https://example.com',
            '@type'    => 'Recipe',
            'name'     => 'X',
            'recipeIngredient'   => array( 'a' ),
            'recipeInstructions' => array( array( '@type' => 'HowToStep', 'text' => 't' ) ),
        );
        $gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
        $r   = $gen->generate( 'recipe', 'X', '<p>Y</p>' );

        $this->assertNull( $r['json'] );
        $this->assertStringContainsString( '@context', (string) $r['error'] );
    }

    public function test_unknown_type_returns_error(): void {
        $gen = new SWPS_AEO_Schema_Generator( new FakeAIProvider(), $this->fields_path() );
        $r   = $gen->generate( 'event', 'X', '<p>Y</p>' );  // not in our supported types
        $this->assertNull( $r['json'] );
        $this->assertSame( 'unsupported_type', $r['error'] );
    }

    public function test_provider_failure_returns_error(): void {
        $provider = new FakeAIProvider();
        $provider->should_fail = true;
        $gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
        $r   = $gen->generate( 'recipe', 'X', '<p>Y</p>' );
        $this->assertNull( $r['json'] );
        $this->assertStringContainsString( 'AI provider error', (string) $r['error'] );
    }
}
```

- [ ] **Step 2: Verify failures**

```bash
composer test -- --filter AeoSchemaGenerator
```

Expected: 6 failures.

- [ ] **Step 3: Implement generator**

Replace stub in `includes/class-aeo-schema-generator.php`:

```php
class SWPS_AEO_Schema_Generator {

    private const SUPPORTED_TYPES = array( 'howto', 'recipe', 'product', 'review', 'qapage' );

    /** @var object */
    private $provider;

    /** @var array<string, array<string, mixed>> */
    private array $fields;

    public function __construct( $provider, string $fields_path ) {
        $this->provider = $provider;
        $raw = is_readable( $fields_path ) ? (string) file_get_contents( $fields_path ) : '{}';
        $this->fields = (array) ( json_decode( $raw, true ) ?? array() );
    }

    /**
     * @return array{json: ?array<string, mixed>, error: ?string}
     */
    public function generate( string $type, string $title, string $html ): array {
        $type = strtolower( $type );
        if ( ! in_array( $type, self::SUPPORTED_TYPES, true ) ) {
            return array( 'json' => null, 'error' => 'unsupported_type' );
        }
        if ( ! isset( $this->fields[ $type ] ) ) {
            return array( 'json' => null, 'error' => 'missing_field_manifest' );
        }

        $spec = $this->fields[ $type ];
        $required    = (array) ( $spec['required']    ?? array() );
        $recommended = (array) ( $spec['recommended'] ?? array() );
        $expected_type = (string) ( $spec['type']     ?? '' );

        $system = 'You generate schema.org JSON-LD from blog post content. ' .
                  'Only return fields you can derive from the post — do NOT invent.';
        $user = sprintf(
            "Type: %s\nTitle: %s\n\nRequired fields: %s\nRecommended fields: %s\n\n" .
            "Post HTML (truncated):\n%s\n\n" .
            "Return a single JSON object: {\"@context\": \"https://schema.org\", \"@type\": \"%s\", ...fields...}. " .
            "Use empty arrays / null for fields you cannot derive.",
            $expected_type,
            $title,
            implode( ', ', $required ),
            implode( ', ', $recommended ),
            mb_substr( wp_strip_all_tags( $html ), 0, 8000 ),
            $expected_type
        );

        try {
            $json = $this->provider->chat_json(
                array(
                    array( 'role' => 'system', 'content' => $system ),
                    array( 'role' => 'user',   'content' => $user ),
                ),
                2048
            );
        } catch ( \Throwable $e ) {
            return array( 'json' => null, 'error' => $e->getMessage() );
        }

        if ( function_exists( 'apply_filters' ) ) {
            $json = (array) apply_filters( 'swps_aeo_schema_json', $json, $type, null );
        }

        $err = $this->validate( (array) $json, $expected_type, $required );
        if ( null !== $err ) {
            return array( 'json' => null, 'error' => $err );
        }

        return array( 'json' => (array) $json, 'error' => null );
    }

    /** @param array<string, mixed> $json @param string[] $required */
    private function validate( array $json, string $expected_type, array $required ): ?string {
        if ( ( $json['@context'] ?? '' ) !== 'https://schema.org' ) {
            return 'invalid @context';
        }
        if ( ( $json['@type'] ?? '' ) !== $expected_type ) {
            return 'invalid @type (expected ' . $expected_type . ')';
        }
        foreach ( $required as $field ) {
            if ( ! array_key_exists( $field, $json ) ) {
                return "missing required field: {$field}";
            }
            $v = $json[ $field ];
            if ( null === $v || '' === $v || ( is_array( $v ) && empty( $v ) ) ) {
                return "empty required field: {$field}";
            }
        }
        return null;
    }
}
```

- [ ] **Step 4: Verify tests + lint**

```bash
composer test -- --filter AeoSchemaGenerator
composer lint -- includes/class-aeo-schema-generator.php
```

Expected: `OK (6 tests, ...)`; PHPCS clean.

- [ ] **Step 5: Commit**

```bash
git add includes/class-aeo-schema-generator.php tests/aeo/test-aeo-schema-generator.php
git commit -m "feat(aeo): implement dynamic Schema Generator for 5 types

HowTo, Recipe, Product, Review, QAPage. Builds per-type prompts
from includes/data/aeo-schema-fields.json, calls injected AI
provider for JSON-LD, validates (@context, @type, required-field
presence). Filterable output via swps_aeo_schema_json."
```

### Task 12: Extend SWPS_Schema with 5 new render methods

**Files:**
- Modify: `includes/class-schema.php`

This task does not have pure-PHP unit tests — it's WP-integrated and verified by manual smoke (Task 40) and by Google Rich Results Test on a real post.

- [ ] **Step 1: Read the existing pattern**

```bash
grep -n "function schema_\|wp_head\|json_encode\|ld\\\\+json" includes/class-schema.php | head -30
```

Identify the existing `schema_article()` (or similar) method — your new ones follow its structure.

- [ ] **Step 2: Add 5 new methods to `SWPS_Schema`**

In `includes/class-schema.php`, add (use the existing `is_schema_deferred()` deferral helper if present; if not, check `defined( 'WPSEO_VERSION' )`, `class_exists( 'RankMath' )`, `defined( 'AIOSEO_VERSION' )`):

```php
public function maybe_render_aeo_schema(): void {
    if ( ! is_singular() ) {
        return;
    }
    $post_id = get_queried_object_id();
    $type    = (string) get_post_meta( $post_id, '_swps_aeo_schema_type', true );
    if ( '' === $type ) {
        return;
    }
    $enabled = (array) get_option( 'swps_aeo_enabled_schema_types', array( 'howto', 'recipe', 'product', 'review', 'qapage' ) );
    if ( ! in_array( $type, $enabled, true ) ) {
        return;
    }
    if ( $this->is_schema_deferred() ) {
        return;
    }
    $json = (string) get_post_meta( $post_id, '_swps_aeo_schema_json', true );
    if ( '' === $json ) {
        return;
    }
    $decoded = json_decode( $json, true );
    if ( ! is_array( $decoded ) ) {
        return;
    }
    $decoded = apply_filters( "swps_schema_{$type}", $decoded, $post_id );
    echo "<script type=\"application/ld+json\">\n" .
         wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
         "\n</script>\n";
}
```

In the class constructor (or wherever `wp_head` schema hooks are registered), add:

```php
add_action( 'wp_head', array( $this, 'maybe_render_aeo_schema' ), 10 );
```

If `is_schema_deferred()` does not yet exist, add this helper:

```php
private function is_schema_deferred(): bool {
    if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) ) {
        $override = (bool) get_option( 'swps_schema_override', false );
        return ! $override;
    }
    return false;
}
```

- [ ] **Step 3: Lint + manual smoke**

```bash
composer lint -- includes/class-schema.php
composer analyze
```

Manual: in a local WP, manually set:
```bash
wp post meta set 123 _swps_aeo_schema_type recipe
wp post meta set 123 _swps_aeo_schema_json '{"@context":"https://schema.org","@type":"Recipe","name":"Test","recipeIngredient":["x"],"recipeInstructions":[{"@type":"HowToStep","text":"y"}]}'
```

Visit `/?p=123` and view source — confirm the `<script type="application/ld+json">` block is present. Paste the JSON-LD into [Google Rich Results Test](https://search.google.com/test/rich-results) and confirm "Recipe" detected.

- [ ] **Step 4: Commit**

```bash
git add includes/class-schema.php
git commit -m "feat(aeo): render dynamic JSON-LD on singulars for 5 AEO types

SWPS_Schema::maybe_render_aeo_schema() reads
_swps_aeo_schema_type + _swps_aeo_schema_json post meta and
outputs ld+json on singular post views. Respects the
swps_aeo_enabled_schema_types option and the
Yoast/RankMath/AIOSEO deferral pattern. Hooked at wp_head
priority 10. Filterable via swps_schema_{type}."
```

---

## Phase 5 — AJAX handlers and REST endpoints

### Task 13: Implement AEO Optimizer AJAX backend

**Files:**
- Modify: `includes/class-aeo-optimizer.php`

This class instantiates the dependency graph (4 sub-scorers, orchestrator, schema generator) and registers AJAX handlers. Mirrors the structure of `SWPS_Auto_Optimize`.

This task is verified by manual smoke (Task 40) since AJAX handlers run in a WP context.

- [ ] **Step 1: Implement the class**

Replace stub in `includes/class-aeo-optimizer.php`:

```php
class SWPS_AEO_Optimizer {

    public const META_PROPOSAL    = '_swps_aeo_proposal';
    public const META_SNAPSHOT    = '_swps_aeo_snapshot';
    public const META_DISMISSED   = '_swps_aeo_dismissed';
    public const META_SCHEMA_TYPE = '_swps_aeo_schema_type';
    public const META_SCHEMA_JSON = '_swps_aeo_schema_json';

    private SWPS_AEO_Scorer            $scorer;
    private SWPS_AEO_Schema_Generator  $schema_gen;
    private $ai_provider;
    private SWPS_Cost_Tracker          $cost;
    private SWPS_Rate_Limiter          $rate;

    public function __construct(
        SWPS_AEO_Scorer $scorer,
        SWPS_AEO_Schema_Generator $schema_gen,
        $ai_provider,
        SWPS_Cost_Tracker $cost,
        SWPS_Rate_Limiter $rate
    ) {
        $this->scorer      = $scorer;
        $this->schema_gen  = $schema_gen;
        $this->ai_provider = $ai_provider;
        $this->cost        = $cost;
        $this->rate        = $rate;

        add_action( 'admin_menu',           array( $this, 'register_menu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        add_action( 'wp_ajax_swps_aeo_scan_chunk', array( $this, 'ajax_scan_chunk' ) );
        add_action( 'wp_ajax_swps_aeo_propose',    array( $this, 'ajax_propose' ) );
        add_action( 'wp_ajax_swps_aeo_apply',      array( $this, 'ajax_apply' ) );
        add_action( 'wp_ajax_swps_aeo_undo',       array( $this, 'ajax_undo' ) );
        add_action( 'wp_ajax_swps_aeo_dismiss',    array( $this, 'ajax_dismiss' ) );
        add_action( 'wp_ajax_swps_aeo_score',      array( $this, 'ajax_score' ) );
    }

    public function register_menu(): void {
        add_submenu_page(
            'stratawp-seo',
            __( 'AEO Optimize', 'stratawp-seo' ),
            __( 'AEO Optimize', 'stratawp-seo' ),
            'manage_options',
            'swps-aeo-optimize',
            array( $this, 'render_page' )
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
        }
        $threshold = (int) get_option( 'swps_aeo_threshold', 70 );
        $post_types = (array) get_option( 'swps_aeo_post_types', array( 'post', 'page' ) );
        require SWPS_PLUGIN_DIR . 'templates/aeo-page.php';
    }

    public function enqueue_assets( string $hook ): void {
        // Admin page assets.
        if ( 'stratawp-seo_page_swps-aeo-optimize' === $hook ) {
            wp_enqueue_style(
                'swps-aeo',
                SWPS_PLUGIN_URL . 'admin/css/aeo.css',
                array( 'swps-tokens', 'swps-components', 'swps-templates' ),
                SWPS_VERSION
            );
            wp_enqueue_script(
                'swps-aeo-optimizer',
                SWPS_PLUGIN_URL . 'admin/js/aeo-optimizer.js',
                array( 'jquery' ),
                SWPS_VERSION,
                true
            );
            wp_localize_script( 'swps-aeo-optimizer', 'swpsAeo', $this->localize_data() );
        }

        // Editor panel assets — see Task 17 for the panel class; this handles asset enqueue.
        if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            wp_enqueue_style( 'swps-aeo', SWPS_PLUGIN_URL . 'admin/css/aeo.css', array(), SWPS_VERSION );
            wp_enqueue_script(
                'swps-aeo-editor-panel',
                SWPS_PLUGIN_URL . 'admin/js/aeo-editor-panel.js',
                array( 'jquery', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ),
                SWPS_VERSION,
                true
            );
            wp_localize_script( 'swps-aeo-editor-panel', 'swpsAeo', $this->localize_data() );
        }
    }

    /** @return array<string, mixed> */
    private function localize_data(): array {
        return array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'swps_nonce' ),
            'threshold' => (int) get_option( 'swps_aeo_threshold', 70 ),
            'i18n'      => array(
                'scanning'       => __( 'Scoring posts...', 'stratawp-seo' ),
                'proposing'      => __( 'Generating proposal — calling AI...', 'stratawp-seo' ),
                'applying'       => __( 'Applying changes...', 'stratawp-seo' ),
                'undoing'        => __( 'Undoing last apply...', 'stratawp-seo' ),
                'generate'       => __( 'Generate proposal', 'stratawp-seo' ),
                'review'         => __( 'Review', 'stratawp-seo' ),
                'apply'          => __( 'Apply selected', 'stratawp-seo' ),
                'cancel'         => __( 'Cancel', 'stratawp-seo' ),
                'dismiss'        => __( 'Dismiss', 'stratawp-seo' ),
                'noPosts'        => __( 'Nothing below the threshold. Lower the threshold or re-scan.', 'stratawp-seo' ),
                'genericFail'    => __( 'Request failed.', 'stratawp-seo' ),
                'projected'      => __( 'Projected score', 'stratawp-seo' ),
                'schemaSection'  => __( 'Schema (new)', 'stratawp-seo' ),
                'insertsSection' => __( 'Structural inserts', 'stratawp-seo' ),
                'editsSection'   => __( 'Edits', 'stratawp-seo' ),
            ),
        );
    }

    private function verify_request(): void {
        check_ajax_referer( 'swps_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'unauthorized' ), 403 );
        }
    }

    public function ajax_scan_chunk(): void {
        $this->verify_request();
        $offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
        $limit  = 10;
        $types  = (array) get_option( 'swps_aeo_post_types', array( 'post', 'page' ) );

        $query = new WP_Query( array(
            'post_type'      => $types,
            'post_status'    => 'publish',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'offset'         => $offset,
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ) );

        $results = array();
        foreach ( $query->posts as $post_id ) {
            $r = $this->scorer->score_post( (int) $post_id );
            $results[] = array(
                'post_id'   => (int) $post_id,
                'score'     => $r['total'],
                'subscores' => $r['subscores'],
            );
        }

        wp_send_json_success( array(
            'scored'      => count( $results ),
            'next_offset' => $offset + count( $results ),
            'total'       => (int) $query->found_posts,
            'done'        => $offset + count( $results ) >= (int) $query->found_posts,
            'results'     => $results,
        ) );
    }

    public function ajax_score(): void {
        $this->verify_request();
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => 'invalid post_id' ), 400 );
        }
        $r = $this->scorer->score_post( $post_id );
        wp_send_json_success( $r );
    }

    public function ajax_propose(): void {
        $this->verify_request();
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( array( 'message' => 'invalid post_id' ), 400 );
        }
        if ( ! $this->rate->is_allowed( 'aeo_propose' ) ) {
            wp_send_json_error( array( 'message' => 'rate_limited' ), 429 );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => 'post_not_found' ), 404 );
        }

        // Detect expected type via Markup scorer's inference.
        $markup       = new SWPS_AEO_Markup_Scorer();
        $expected_type = $markup->infer_schema_type( $post->post_content, $post->post_title );

        // Build proposal prompt — single AI call returns edits + inserts + schema.
        $system = 'You optimize blog posts for AI search citation (AEO). ' .
                  'Return concrete find/replace edits, structural inserts, and schema if applicable.';
        $user   = sprintf(
            "Title: %s\nDetected schema type: %s\n\nPost HTML (truncated):\n%s\n\n" .
            "Return JSON with exactly these keys: " .
            "{\"edits\":[{\"find\":\"...\",\"replace\":\"...\",\"reason\":\"...\"}], " .
            "\"inserts\":[{\"kind\":\"qa|tldr|list|defn\",\"anchor\":\"<h2 text> or 'top'\",\"html\":\"<...>\",\"reason\":\"...\"}], " .
            "\"schema\":{\"type\":\"howto|recipe|product|review|qapage|null\",\"reason\":\"...\"}, " .
            "\"projected_score\":<int 0-100>}.",
            $post->post_title,
            $expected_type ?? 'none',
            mb_substr( $post->post_content, 0, 8000 )
        );

        try {
            $proposal = $this->ai_provider->chat_json(
                array(
                    array( 'role' => 'system', 'content' => $system ),
                    array( 'role' => 'user',   'content' => $user ),
                ),
                4096
            );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
        }

        // Generate schema JSON if proposed.
        $schema_json = null;
        if ( ! empty( $proposal['schema']['type'] ) && 'null' !== $proposal['schema']['type'] ) {
            $sg = $this->schema_gen->generate(
                (string) $proposal['schema']['type'],
                $post->post_title,
                $post->post_content
            );
            $schema_json = $sg['json'];
            $proposal['schema']['validation_error'] = $sg['error'];
            $proposal['schema']['json']             = $schema_json;
        }

        $proposal = apply_filters( 'swps_aeo_proposal', $proposal, $post_id );

        // Cache (24h via cron sweep — see Task 19).
        update_post_meta( $post_id, self::META_PROPOSAL, wp_json_encode( $proposal ) );

        // Cost tracking (provider's last_usage is populated by chat_json).
        if ( method_exists( $this->ai_provider, 'get_last_usage' ) ) {
            $usage = $this->ai_provider->get_last_usage();
            $this->cost->record( 'aeo_propose', $usage );
        }

        wp_send_json_success( array( 'proposal' => $proposal ) );
    }

    public function ajax_apply(): void {
        $this->verify_request();
        $post_id          = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $selected_edits   = isset( $_POST['edits'] )   ? array_map( 'intval', (array) $_POST['edits'] )   : array();
        $selected_inserts = isset( $_POST['inserts'] ) ? array_map( 'intval', (array) $_POST['inserts'] ) : array();
        $apply_schema     = ! empty( $_POST['schema'] );

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => 'post_not_found' ), 404 );
        }

        $raw = (string) get_post_meta( $post_id, self::META_PROPOSAL, true );
        if ( '' === $raw ) {
            wp_send_json_error( array( 'message' => 'no_proposal' ), 400 );
        }
        $proposal = json_decode( $raw, true );
        if ( ! is_array( $proposal ) ) {
            wp_send_json_error( array( 'message' => 'invalid_proposal' ), 500 );
        }

        // Snapshot.
        update_post_meta( $post_id, self::META_SNAPSHOT, wp_json_encode( array(
            'content'     => $post->post_content,
            'schema_type' => get_post_meta( $post_id, self::META_SCHEMA_TYPE, true ),
            'schema_json' => get_post_meta( $post_id, self::META_SCHEMA_JSON, true ),
            'taken_at'    => time(),
        ) ) );

        $new_content = $post->post_content;
        $applied     = 0;

        // Apply edits.
        foreach ( $selected_edits as $idx ) {
            if ( ! isset( $proposal['edits'][ $idx ] ) ) {
                continue;
            }
            $find    = (string) ( $proposal['edits'][ $idx ]['find']    ?? '' );
            $replace = (string) ( $proposal['edits'][ $idx ]['replace'] ?? '' );
            if ( '' === $find ) {
                continue;
            }
            $occurrences = substr_count( $new_content, $find );
            if ( 1 === $occurrences ) {
                $new_content = str_replace( $find, $replace, $new_content );
                $applied++;
            }
            // Skip if 0 or >1 — ambiguous.
        }

        // Apply inserts.
        foreach ( $selected_inserts as $idx ) {
            if ( ! isset( $proposal['inserts'][ $idx ] ) ) {
                continue;
            }
            $ins    = $proposal['inserts'][ $idx ];
            $anchor = (string) ( $ins['anchor'] ?? '' );
            $html   = (string) ( $ins['html']   ?? '' );
            if ( '' === $html ) {
                continue;
            }
            if ( 'top' === $anchor ) {
                $new_content = $html . "\n\n" . $new_content;
                $applied++;
                continue;
            }
            // Anchor by H2 text.
            $pattern = '#(<h2[^>]*>[^<]*' . preg_quote( $anchor, '#' ) . '[^<]*</h2>)#i';
            if ( preg_match( $pattern, $new_content ) ) {
                $new_content = preg_replace( $pattern, "$1\n\n" . $html, $new_content, 1 );
                $applied++;
            }
        }

        wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => $new_content,
        ) );

        // Apply schema.
        if ( $apply_schema && ! empty( $proposal['schema']['type'] ) && ! empty( $proposal['schema']['json'] ) ) {
            update_post_meta( $post_id, self::META_SCHEMA_TYPE, (string) $proposal['schema']['type'] );
            update_post_meta( $post_id, self::META_SCHEMA_JSON, wp_json_encode( $proposal['schema']['json'] ) );
            $applied++;
        }

        // Clear cached proposal; re-score.
        delete_post_meta( $post_id, self::META_PROPOSAL );
        $rescore = $this->scorer->score_post( $post_id );

        wp_send_json_success( array(
            'applied_count' => $applied,
            'new_score'     => $rescore['total'],
            'new_subscores' => $rescore['subscores'],
        ) );
    }

    public function ajax_undo(): void {
        $this->verify_request();
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $raw     = (string) get_post_meta( $post_id, self::META_SNAPSHOT, true );
        if ( '' === $raw ) {
            wp_send_json_error( array( 'message' => 'no_snapshot' ), 404 );
        }
        $snap = json_decode( $raw, true );
        if ( ! is_array( $snap ) ) {
            wp_send_json_error( array( 'message' => 'invalid_snapshot' ), 500 );
        }

        wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => (string) ( $snap['content'] ?? '' ),
        ) );
        if ( ! empty( $snap['schema_type'] ) ) {
            update_post_meta( $post_id, self::META_SCHEMA_TYPE, (string) $snap['schema_type'] );
        } else {
            delete_post_meta( $post_id, self::META_SCHEMA_TYPE );
        }
        if ( ! empty( $snap['schema_json'] ) ) {
            update_post_meta( $post_id, self::META_SCHEMA_JSON, (string) $snap['schema_json'] );
        } else {
            delete_post_meta( $post_id, self::META_SCHEMA_JSON );
        }

        delete_post_meta( $post_id, self::META_SNAPSHOT );
        $r = $this->scorer->score_post( $post_id );

        wp_send_json_success( array( 'restored' => true, 'score' => $r['total'], 'subscores' => $r['subscores'] ) );
    }

    public function ajax_dismiss(): void {
        $this->verify_request();
        $post_id   = isset( $_POST['post_id'] )    ? (int) $_POST['post_id']    : 0;
        $dismissed = isset( $_POST['dismissed'] )  ? (bool) $_POST['dismissed'] : true;
        if ( $dismissed ) {
            update_post_meta( $post_id, self::META_DISMISSED, 1 );
        } else {
            delete_post_meta( $post_id, self::META_DISMISSED );
        }
        wp_send_json_success( array( 'ok' => true ) );
    }
}
```

- [ ] **Step 2: Wire instantiation in `stratawp-seo.php` / singleton**

Open `stratawp-seo.php`. Find the section where `SWPS_Auto_Optimize` is instantiated (search for `new SWPS_Auto_Optimize`). Add immediately after:

```php
$swps_aeo_extractability = new SWPS_AEO_Extractability_Scorer();
$swps_aeo_markup         = new SWPS_AEO_Markup_Scorer();
$swps_aeo_authority      = new SWPS_AEO_Authority_Scorer();
$swps_aeo_coverage       = new SWPS_AEO_Coverage_Scorer( $this->ai_provider );
$this->aeo_scorer        = new SWPS_AEO_Scorer(
    $swps_aeo_extractability,
    $swps_aeo_markup,
    $swps_aeo_authority,
    $swps_aeo_coverage
);
$this->aeo_schema_gen    = new SWPS_AEO_Schema_Generator(
    $this->ai_provider,
    SWPS_PLUGIN_DIR . 'includes/data/aeo-schema-fields.json'
);
$this->aeo_optimizer     = new SWPS_AEO_Optimizer(
    $this->aeo_scorer,
    $this->aeo_schema_gen,
    $this->ai_provider,
    $this->cost_tracker,
    $this->rate_limiter
);
$this->aeo_editor_panel  = new SWPS_AEO_Editor_Panel( $this->aeo_scorer );
```

Also add the matching public property declarations in the singleton class (above `__construct`):

```php
public SWPS_AEO_Scorer            $aeo_scorer;
public SWPS_AEO_Schema_Generator  $aeo_schema_gen;
public SWPS_AEO_Optimizer         $aeo_optimizer;
public SWPS_AEO_Editor_Panel      $aeo_editor_panel;
```

- [ ] **Step 3: Lint + analyze**

```bash
composer lint -- includes/class-aeo-optimizer.php stratawp-seo.php
composer analyze
```

- [ ] **Step 4: Commit**

```bash
git add includes/class-aeo-optimizer.php stratawp-seo.php
git commit -m "feat(aeo): implement AEO Optimizer with AJAX handlers + DI

6 AJAX handlers: scan_chunk, score, propose, apply, undo,
dismiss. Mirrors SWPS_Auto_Optimize pattern. Snapshot-on-apply
enables undo. Proposal cached as post meta. Cost tracked via
SWPS_Cost_Tracker. Rate limited via SWPS_Rate_Limiter."
```

### Task 14: Add 6 REST endpoints as thin wrappers in SWPS_REST_API

**Files:**
- Modify: `includes/class-rest-api.php`

These are external-access mirrors of the AJAX handlers. Same operations, REST shape.

- [ ] **Step 1: Read the existing pattern**

```bash
grep -n "register_rest_route\|function rest_\|permission_callback" includes/class-rest-api.php | head -20
```

- [ ] **Step 2: Add routes in the existing `register_routes()` method (or equivalent)**

```php
register_rest_route( 'swps/v1', '/aeo/scan-batch', array(
    'methods'             => 'POST',
    'callback'            => array( $this, 'rest_aeo_scan_batch' ),
    'permission_callback' => array( $this, 'check_edit_posts' ),
    'args'                => array(
        'offset' => array( 'type' => 'integer', 'default' => 0 ),
        'limit'  => array( 'type' => 'integer', 'default' => 10 ),
    ),
) );
register_rest_route( 'swps/v1', '/aeo/score/(?P<id>\d+)', array(
    'methods'             => 'GET',
    'callback'            => array( $this, 'rest_aeo_score' ),
    'permission_callback' => array( $this, 'check_edit_posts' ),
) );
register_rest_route( 'swps/v1', '/aeo/proposal/(?P<id>\d+)', array(
    'methods'             => 'POST',
    'callback'            => array( $this, 'rest_aeo_proposal' ),
    'permission_callback' => array( $this, 'check_edit_posts' ),
) );
register_rest_route( 'swps/v1', '/aeo/apply/(?P<id>\d+)', array(
    'methods'             => 'POST',
    'callback'            => array( $this, 'rest_aeo_apply' ),
    'permission_callback' => array( $this, 'check_edit_posts' ),
) );
register_rest_route( 'swps/v1', '/aeo/undo/(?P<id>\d+)', array(
    'methods'             => 'POST',
    'callback'            => array( $this, 'rest_aeo_undo' ),
    'permission_callback' => array( $this, 'check_edit_posts' ),
) );
register_rest_route( 'swps/v1', '/aeo/dismiss/(?P<id>\d+)', array(
    'methods'             => 'POST',
    'callback'            => array( $this, 'rest_aeo_dismiss' ),
    'permission_callback' => array( $this, 'check_edit_posts' ),
) );
```

- [ ] **Step 3: Implement the 6 callbacks as thin wrappers**

Add to the class:

```php
private function check_edit_posts(): bool {
    return current_user_can( 'edit_posts' );
}

public function rest_aeo_score( WP_REST_Request $req ): WP_REST_Response {
    $optimizer = SWPS_Plugin::instance()->aeo_optimizer ?? null;
    $scorer    = SWPS_Plugin::instance()->aeo_scorer    ?? null;
    if ( ! $scorer ) {
        return new WP_REST_Response( array( 'message' => 'unavailable' ), 503 );
    }
    $post_id = (int) $req->get_param( 'id' );
    $r       = $scorer->score_post( $post_id );
    return new WP_REST_Response( $r, 200 );
}

// Pattern for scan_batch / proposal / apply / undo / dismiss:
// 1. Resolve $this->dependencies from singleton.
// 2. Re-use the same business logic as the AJAX handler — refactor the
//    AJAX handler body into a private method that both call. To avoid
//    breaking the AJAX path, leave the existing handler in place and
//    extract a `proposal_for( int $post_id ): array` method to the
//    optimizer; both layers call it.
```

(Use the actual singleton class name from the codebase — search for `class SWPS_` and `instance()` to find it; commonly `StrataWP_SEO::instance()`.)

- [ ] **Step 4: Lint + analyze**

```bash
composer lint -- includes/class-rest-api.php
composer analyze
```

- [ ] **Step 5: Smoke test the REST endpoint with WP-CLI**

```bash
wp eval 'echo rest_do_request( new WP_REST_Request( "GET", "/swps/v1/aeo/score/1" ) )->get_body();'
```

Expected: JSON body with `total` and `subscores` (after a re-scan has populated meta).

- [ ] **Step 6: Commit**

```bash
git add includes/class-rest-api.php
git commit -m "feat(aeo): expose 6 REST endpoints under /swps/v1/aeo/*

Thin wrappers over the AEO Optimizer business logic (extracted
into shared methods) for external/programmatic access. Same
operations as the AJAX handlers; edit_posts capability required."
```

---


## Phase 6 — Admin page UI

### Task 15: Build the AEO Optimize admin page template + CSS

**Files:**
- Create: `templates/aeo-page.php`
- Create: `admin/css/aeo.css`

This is server-rendered shell; data is loaded via AJAX from Task 13.

- [ ] **Step 1: Write the page template**

`templates/aeo-page.php`:
```php
<?php
/**
 * AEO Optimize admin page.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $threshold, $post_types;
?>
<div class="wrap swps-page swps-aeo-page">
    <div class="swps-page-header">
        <h1><?php esc_html_e( 'AEO Optimize', 'stratawp-seo' ); ?></h1>
        <p class="description">
            <?php esc_html_e( 'Score posts on AI-citeability across 4 dimensions. Surface posts below the threshold; generate AI proposals; review diffs; apply with one click.', 'stratawp-seo' ); ?>
        </p>
        <div class="swps-actions">
            <button type="button" class="button button-primary" id="swps-aeo-rescan">
                <?php esc_html_e( 'Re-scan all posts', 'stratawp-seo' ); ?>
            </button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=stratawp-seo-settings&tab=aeo' ) ); ?>" class="button">
                <?php esc_html_e( 'Settings', 'stratawp-seo' ); ?>
            </a>
        </div>
    </div>

    <div class="swps-aeo-progress" id="swps-aeo-progress" hidden>
        <div class="swps-aeo-progress-bar"><span id="swps-aeo-progress-fill"></span></div>
        <p id="swps-aeo-progress-text"></p>
    </div>

    <div class="swps-aeo-tiles">
        <div class="swps-tile" id="swps-aeo-tile-scored"><span class="swps-tile-num">—</span><span class="swps-tile-label"><?php esc_html_e( 'Scored', 'stratawp-seo' ); ?></span></div>
        <div class="swps-tile" id="swps-aeo-tile-below"><span class="swps-tile-num">—</span><span class="swps-tile-label"><?php esc_html_e( 'Below threshold', 'stratawp-seo' ); ?></span></div>
        <div class="swps-tile" id="swps-aeo-tile-avg"><span class="swps-tile-num">—</span><span class="swps-tile-label"><?php esc_html_e( 'Avg score', 'stratawp-seo' ); ?></span></div>
        <div class="swps-tile" id="swps-aeo-tile-low-dim"><span class="swps-tile-num">—</span><span class="swps-tile-label"><?php esc_html_e( 'Weakest dimension', 'stratawp-seo' ); ?></span></div>
    </div>

    <div class="swps-aeo-filters">
        <label>
            <?php esc_html_e( 'Threshold', 'stratawp-seo' ); ?>
            <input type="number" id="swps-aeo-threshold" value="<?php echo esc_attr( $threshold ); ?>" min="50" max="95">
        </label>
        <label>
            <?php esc_html_e( 'Post type', 'stratawp-seo' ); ?>
            <select id="swps-aeo-post-type">
                <option value=""><?php esc_html_e( 'All', 'stratawp-seo' ); ?></option>
                <?php foreach ( $post_types as $pt ) : ?>
                    <option value="<?php echo esc_attr( $pt ); ?>"><?php echo esc_html( $pt ); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <table class="wp-list-table widefat striped swps-aeo-queue" id="swps-aeo-queue">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Score', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Title', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Sub-scores', 'stratawp-seo' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr class="swps-aeo-empty"><td colspan="4"><?php esc_html_e( 'No posts scored yet. Click "Re-scan all posts" to start.', 'stratawp-seo' ); ?></td></tr>
        </tbody>
    </table>
</div>

<div class="swps-aeo-modal" id="swps-aeo-modal" hidden>
    <div class="swps-aeo-modal-inner"></div>
</div>
```

- [ ] **Step 2: Write the CSS**

`admin/css/aeo.css`:
```css
.swps-aeo-page .swps-aeo-tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 16px 0; }
.swps-aeo-page .swps-tile { background: var(--swps-bg-panel, #fff); border: 1px solid var(--swps-border, #ddd); border-radius: 8px; padding: 16px; text-align: center; }
.swps-aeo-page .swps-tile-num { display: block; font-size: 28px; font-weight: 600; color: var(--swps-accent, #10b981); }
.swps-aeo-page .swps-tile-label { display: block; font-size: 12px; color: var(--swps-text-muted, #666); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px; }

.swps-aeo-progress { margin: 12px 0; }
.swps-aeo-progress-bar { background: var(--swps-bg-muted, #eee); height: 8px; border-radius: 4px; overflow: hidden; }
.swps-aeo-progress-bar > span { display: block; height: 100%; background: var(--swps-accent, #10b981); width: 0; transition: width 200ms; }

.swps-aeo-filters { display: flex; gap: 16px; margin: 12px 0; }
.swps-aeo-filters label { display: inline-flex; gap: 8px; align-items: center; }

.swps-aeo-queue td { vertical-align: middle; }
.swps-aeo-score-cell { font-size: 18px; font-weight: 600; }
.swps-aeo-score-cell.low { color: #dc2626; }
.swps-aeo-score-cell.mid { color: #f59e0b; }
.swps-aeo-score-cell.high { color: #10b981; }

.swps-aeo-subscore-chip { display: inline-block; padding: 2px 8px; margin-right: 4px; border-radius: 12px; font-size: 11px; background: var(--swps-bg-muted, #eee); }
.swps-aeo-subscore-chip.dim-extractability { background: #ddd6fe; }
.swps-aeo-subscore-chip.dim-markup         { background: #fde68a; }
.swps-aeo-subscore-chip.dim-authority      { background: #bfdbfe; }
.swps-aeo-subscore-chip.dim-coverage       { background: #fecaca; }

.swps-aeo-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 100000; }
.swps-aeo-modal-inner { background: #fff; max-width: 900px; max-height: 85vh; width: 90vw; overflow-y: auto; border-radius: 8px; padding: 24px; }
.swps-aeo-modal .diff-add { background: #d1fae5; padding: 2px 4px; }
.swps-aeo-modal .diff-del { background: #fecaca; padding: 2px 4px; text-decoration: line-through; }
.swps-aeo-modal .schema-preview { background: #f9fafb; border: 1px solid #e5e7eb; padding: 12px; font-family: ui-monospace, monospace; font-size: 12px; white-space: pre-wrap; max-height: 240px; overflow-y: auto; }
```

- [ ] **Step 3: Lint**

```bash
composer lint -- templates/aeo-page.php
```

(No CSS linter is configured in the project; visual check only.)

- [ ] **Step 4: Manual smoke**

In a local WP install, navigate to `StrataWP SEO → AEO Optimize` (or the submenu the optimizer registers). Confirm the page loads with empty state.

- [ ] **Step 5: Commit**

```bash
git add templates/aeo-page.php admin/css/aeo.css
git commit -m "feat(aeo): add admin page template + CSS

Server-rendered shell with stats tiles, threshold filter,
queue table, and modal container. Data populates via AJAX
from aeo-optimizer.js (Task 16). Styling matches the existing
admin shell tokens (--swps-bg-panel, --swps-accent, etc.)."
```

### Task 16: Implement aeo-optimizer.js (scan loop, queue, diff modal, apply)

**Files:**
- Create: `admin/js/aeo-optimizer.js`

- [ ] **Step 1: Write the JS**

`admin/js/aeo-optimizer.js`:
```javascript
/* global jQuery, swpsAeo */
(function ($) {
    'use strict';

    const $progress     = $('#swps-aeo-progress');
    const $progressFill = $('#swps-aeo-progress-fill');
    const $progressText = $('#swps-aeo-progress-text');
    const $queue        = $('#swps-aeo-queue tbody');
    const $modal        = $('#swps-aeo-modal');
    const $modalInner   = $modal.find('.swps-aeo-modal-inner');

    let allResults = [];

    function scoreClass(s) {
        if (s < 50) return 'low';
        if (s < 75) return 'mid';
        return 'high';
    }

    function renderQueue() {
        const threshold = parseInt($('#swps-aeo-threshold').val(), 10) || 70;
        const ptFilter  = $('#swps-aeo-post-type').val() || '';
        const filtered  = allResults
            .filter(r => r.score < threshold)
            .filter(r => !ptFilter || r.post_type === ptFilter)
            .sort((a, b) => a.score - b.score);

        if (filtered.length === 0) {
            $queue.html('<tr class="swps-aeo-empty"><td colspan="4">' + swpsAeo.i18n.noPosts + '</td></tr>');
            return;
        }

        const html = filtered.map(r => {
            const sub = r.subscores;
            return '<tr data-post-id="' + r.post_id + '">' +
                '<td><span class="swps-aeo-score-cell ' + scoreClass(r.score) + '">' + r.score + '</span></td>' +
                '<td><a href="' + r.edit_url + '" target="_blank">' + $('<i>').text(r.title).html() + '</a><br><small>' + r.permalink + '</small></td>' +
                '<td>' +
                    chip('extractability', sub.extractability) +
                    chip('markup',         sub.markup) +
                    chip('authority',      sub.authority) +
                    chip('coverage',       sub.coverage) +
                '</td>' +
                '<td>' +
                    (r.has_proposal
                      ? '<button class="button swps-aeo-review" data-id="' + r.post_id + '">' + swpsAeo.i18n.review + '</button> '
                      : '<button class="button swps-aeo-propose" data-id="' + r.post_id + '">' + swpsAeo.i18n.generate + '</button> ') +
                    '<button class="button-link swps-aeo-dismiss" data-id="' + r.post_id + '">' + swpsAeo.i18n.dismiss + '</button>' +
                '</td>' +
            '</tr>';
        }).join('');
        $queue.html(html);
    }

    function chip(dim, val) {
        const display = (val === null || val === undefined) ? '—' : val;
        return '<span class="swps-aeo-subscore-chip dim-' + dim + '">' +
            dim.substring(0, 3).toUpperCase() + ': ' + display +
        '</span>';
    }

    function updateTiles() {
        const total  = allResults.length;
        const below  = allResults.filter(r => r.score < (parseInt($('#swps-aeo-threshold').val(), 10) || 70)).length;
        const avg    = total > 0 ? Math.round(allResults.reduce((a, r) => a + r.score, 0) / total) : 0;
        const dims   = ['extractability', 'markup', 'authority', 'coverage'];
        const dimAvg = {};
        dims.forEach(d => {
            const vals = allResults.map(r => r.subscores[d]).filter(v => v !== null && v !== undefined);
            dimAvg[d] = vals.length > 0 ? Math.round(vals.reduce((a, b) => a + b, 0) / vals.length) : 0;
        });
        const weakest = Object.keys(dimAvg).sort((a, b) => dimAvg[a] - dimAvg[b])[0];

        $('#swps-aeo-tile-scored .swps-tile-num').text(total);
        $('#swps-aeo-tile-below .swps-tile-num').text(below);
        $('#swps-aeo-tile-avg .swps-tile-num').text(avg);
        $('#swps-aeo-tile-low-dim .swps-tile-num').text(weakest + ' (' + dimAvg[weakest] + ')');
    }

    function rescan() {
        allResults = [];
        $progress.show();
        $progressFill.css('width', '0%');
        $progressText.text(swpsAeo.i18n.scanning);
        scanChunk(0);
    }

    function scanChunk(offset) {
        $.post(swpsAeo.ajaxUrl, {
            action: 'swps_aeo_scan_chunk',
            nonce:  swpsAeo.nonce,
            offset: offset,
        }).done(function (resp) {
            if (!resp.success) {
                $progressText.text(swpsAeo.i18n.genericFail);
                return;
            }
            allResults = allResults.concat(resp.data.results);
            const total = resp.data.total;
            const done  = resp.data.next_offset;
            $progressFill.css('width', Math.min(100, (done / Math.max(1, total)) * 100) + '%');
            $progressText.text(done + ' / ' + total);

            if (resp.data.done) {
                $progress.hide();
                updateTiles();
                renderQueue();
            } else {
                scanChunk(resp.data.next_offset);
            }
        });
    }

    function propose(postId) {
        const $btn = $('button.swps-aeo-propose[data-id="' + postId + '"]');
        $btn.prop('disabled', true).text(swpsAeo.i18n.proposing);
        $.post(swpsAeo.ajaxUrl, {
            action: 'swps_aeo_propose',
            nonce:  swpsAeo.nonce,
            post_id: postId,
        }).done(function (resp) {
            if (!resp.success) {
                alert(resp.data && resp.data.message ? resp.data.message : swpsAeo.i18n.genericFail);
                $btn.prop('disabled', false).text(swpsAeo.i18n.generate);
                return;
            }
            openModal(postId, resp.data.proposal);
        });
    }

    function openModal(postId, proposal) {
        const proj = proposal.projected_score || '—';
        let html = '<h2>' + swpsAeo.i18n.projected + ': ' + proj + '</h2>';

        // Schema section.
        if (proposal.schema && proposal.schema.type && proposal.schema.type !== 'null') {
            const validErr = proposal.schema.validation_error;
            html += '<h3>' + swpsAeo.i18n.schemaSection + '</h3>';
            if (validErr) {
                html += '<p style="color:#dc2626;">Schema generation failed validation: ' + $('<i>').text(validErr).html() + '</p>';
            } else {
                html += '<label><input type="checkbox" class="swps-aeo-sel-schema" checked> Add ' + proposal.schema.type + ' schema</label>';
                html += '<pre class="schema-preview">' + $('<i>').text(JSON.stringify(proposal.schema.json, null, 2)).html() + '</pre>';
            }
        }

        // Inserts.
        if (proposal.inserts && proposal.inserts.length) {
            html += '<h3>' + swpsAeo.i18n.insertsSection + ' (' + proposal.inserts.length + ')</h3>';
            html += '<ul>';
            proposal.inserts.forEach((ins, i) => {
                html += '<li><label><input type="checkbox" class="swps-aeo-sel-insert" data-idx="' + i + '" checked> ' +
                        '<strong>' + ins.kind + '</strong> @ ' + $('<i>').text(ins.anchor).html() +
                        '<br><em>' + $('<i>').text(ins.reason || '').html() + '</em></label></li>';
            });
            html += '</ul>';
        }

        // Edits.
        if (proposal.edits && proposal.edits.length) {
            html += '<h3>' + swpsAeo.i18n.editsSection + ' (' + proposal.edits.length + ')</h3>';
            html += '<ul>';
            proposal.edits.forEach((e, i) => {
                html += '<li><label><input type="checkbox" class="swps-aeo-sel-edit" data-idx="' + i + '" checked> ' +
                        '<span class="diff-del">' + $('<i>').text(e.find).html() + '</span> &rarr; ' +
                        '<span class="diff-add">' + $('<i>').text(e.replace).html() + '</span>' +
                        '<br><em>' + $('<i>').text(e.reason || '').html() + '</em></label></li>';
            });
            html += '</ul>';
        }

        html += '<div style="margin-top:20px;text-align:right;">';
        html += '<button class="button button-primary" id="swps-aeo-apply" data-id="' + postId + '">' + swpsAeo.i18n.apply + '</button> ';
        html += '<button class="button" id="swps-aeo-cancel">' + swpsAeo.i18n.cancel + '</button>';
        html += '</div>';

        $modalInner.html(html);
        $modal.show();
    }

    function apply(postId) {
        const edits   = $modal.find('.swps-aeo-sel-edit:checked').map(function () { return parseInt($(this).data('idx'), 10); }).get();
        const inserts = $modal.find('.swps-aeo-sel-insert:checked').map(function () { return parseInt($(this).data('idx'), 10); }).get();
        const schema  = $modal.find('.swps-aeo-sel-schema:checked').length > 0;

        $.post(swpsAeo.ajaxUrl, {
            action: 'swps_aeo_apply',
            nonce:  swpsAeo.nonce,
            post_id: postId,
            edits:   edits,
            inserts: inserts,
            schema:  schema ? 1 : 0,
        }).done(function (resp) {
            if (!resp.success) {
                alert(resp.data && resp.data.message ? resp.data.message : swpsAeo.i18n.genericFail);
                return;
            }
            // Update local cache.
            const idx = allResults.findIndex(r => r.post_id === postId);
            if (idx >= 0) {
                allResults[idx].score     = resp.data.new_score;
                allResults[idx].subscores = resp.data.new_subscores;
                allResults[idx].has_proposal = false;
            }
            $modal.hide();
            updateTiles();
            renderQueue();
        });
    }

    function dismiss(postId) {
        $.post(swpsAeo.ajaxUrl, {
            action: 'swps_aeo_dismiss',
            nonce:  swpsAeo.nonce,
            post_id: postId,
            dismissed: 1,
        }).done(function () {
            allResults = allResults.filter(r => r.post_id !== postId);
            updateTiles();
            renderQueue();
        });
    }

    // Wire events.
    $('#swps-aeo-rescan').on('click', rescan);
    $('#swps-aeo-threshold, #swps-aeo-post-type').on('change input', function () {
        updateTiles();
        renderQueue();
    });
    $(document).on('click', '.swps-aeo-propose',  function () { propose(parseInt($(this).data('id'), 10)); });
    $(document).on('click', '.swps-aeo-review',   function () { propose(parseInt($(this).data('id'), 10)); }); // re-fetch cached
    $(document).on('click', '.swps-aeo-dismiss',  function () { dismiss(parseInt($(this).data('id'), 10)); });
    $(document).on('click', '#swps-aeo-cancel',   function () { $modal.hide(); });
    $(document).on('click', '#swps-aeo-apply',    function () { apply(parseInt($(this).data('id'), 10)); });

})(jQuery);
```

- [ ] **Step 2: Manual smoke**

Reload the AEO Optimize page. Click "Re-scan all posts". Verify:
- Progress bar advances.
- Queue populates with posts below threshold, sorted by score ascending.
- "Generate proposal" on a row → modal opens with sections.
- Check/uncheck items, click Apply → row updates with new score; modal closes.
- Threshold input change → queue re-filters live.
- Dismiss removes a row.

- [ ] **Step 3: Commit**

```bash
git add admin/js/aeo-optimizer.js
git commit -m "feat(aeo): admin JS — scan loop, queue, diff modal, apply, dismiss

vanilla jQuery (matches Auto-Optimize). Chunked AJAX scan (10
posts/batch) with live progress. Client-side filter by threshold
+ post type. Modal renders schema preview, structural inserts,
and find/replace edits, all independently checkable."
```

---

## Phase 7 — Editor panel

### Task 17: Implement classic-editor metabox + Gutenberg sidebar registration

**Files:**
- Modify: `includes/class-aeo-editor-panel.php`

- [ ] **Step 1: Implement the class**

Replace stub in `includes/class-aeo-editor-panel.php`:

```php
class SWPS_AEO_Editor_Panel {

    private SWPS_AEO_Scorer $scorer;

    public function __construct( SWPS_AEO_Scorer $scorer ) {
        $this->scorer = $scorer;

        add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
        add_action( 'init',           array( $this, 'register_gutenberg_panel' ) );
    }

    public function register_metabox(): void {
        $types = (array) get_option( 'swps_aeo_post_types', array( 'post', 'page' ) );
        foreach ( $types as $type ) {
            add_meta_box(
                'swps-aeo-panel',
                __( 'AEO Score', 'stratawp-seo' ),
                array( $this, 'render_metabox' ),
                $type,
                'side',
                'high'
            );
        }
    }

    public function render_metabox( WP_Post $post ): void {
        $score = (int) get_post_meta( $post->ID, SWPS_AEO_Scorer::META_TOTAL, true );
        $sub   = array(
            'extractability' => get_post_meta( $post->ID, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'extractability', true ),
            'markup'         => get_post_meta( $post->ID, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'markup',         true ),
            'authority'      => get_post_meta( $post->ID, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'authority',      true ),
            'coverage'       => get_post_meta( $post->ID, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'coverage',       true ),
        );
        $last  = (int) get_post_meta( $post->ID, SWPS_AEO_Scorer::META_LAST_SCAN, true );
        ?>
        <div class="swps-aeo-panel" data-post-id="<?php echo (int) $post->ID; ?>">
            <div class="swps-aeo-panel-score">
                <span class="swps-aeo-panel-num" id="swps-aeo-panel-num"><?php echo $score > 0 ? esc_html( (string) $score ) : '—'; ?></span>
                <span class="swps-aeo-panel-label"><?php esc_html_e( 'AEO Score', 'stratawp-seo' ); ?></span>
            </div>
            <ul class="swps-aeo-panel-sub">
                <?php foreach ( $sub as $dim => $val ) : ?>
                    <li>
                        <strong><?php echo esc_html( ucfirst( $dim ) ); ?></strong>
                        <span id="swps-aeo-panel-sub-<?php echo esc_attr( $dim ); ?>">
                            <?php echo '' === $val ? '—' : esc_html( (string) (int) $val ); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p>
                <button type="button" class="button" id="swps-aeo-panel-rescore"><?php esc_html_e( 'Re-score', 'stratawp-seo' ); ?></button>
                <button type="button" class="button button-primary" id="swps-aeo-panel-improve"><?php esc_html_e( 'Improve AEO', 'stratawp-seo' ); ?></button>
            </p>
            <p class="description"><?php
                if ( $last > 0 ) {
                    printf(
                        /* translators: %s: human time-diff (e.g. "5 minutes ago"). */
                        esc_html__( 'Last scanned %s', 'stratawp-seo' ),
                        esc_html( human_time_diff( $last, time() ) . ' ' . __( 'ago', 'stratawp-seo' ) )
                    );
                } else {
                    esc_html_e( 'Not yet scanned. Click "Re-score".', 'stratawp-seo' );
                }
            ?></p>
        </div>
        <div id="swps-aeo-modal" hidden><div class="swps-aeo-modal-inner"></div></div>
        <?php
    }

    public function register_gutenberg_panel(): void {
        // The Gutenberg sidebar plugin is registered client-side via
        // admin/js/aeo-editor-panel.js (loaded conditionally in Task 13).
        // The classic metabox above is the server-rendered fallback;
        // when block editor is active, the metabox is suppressed via
        // 'edit_form_after_title'-style hooks if needed. For simplicity,
        // the metabox is also visible alongside the sidebar.
    }
}
```

- [ ] **Step 2: Lint + smoke**

```bash
composer lint -- includes/class-aeo-editor-panel.php
```

Manual: edit any post → confirm the "AEO Score" metabox appears in the sidebar.

- [ ] **Step 3: Commit**

```bash
git add includes/class-aeo-editor-panel.php
git commit -m "feat(aeo): live editor metabox + sidebar registration hook

Classic-editor metabox renders current AEO score + 4 sub-scores
+ Re-score / Improve AEO buttons. Gutenberg sidebar plugin
registration is client-side (Task 18). Both call the same AJAX
endpoints as the optimizer page."
```

### Task 18: Implement aeo-editor-panel.js (classic + Gutenberg sidebar)

**Files:**
- Create: `admin/js/aeo-editor-panel.js`

- [ ] **Step 1: Write the JS**

`admin/js/aeo-editor-panel.js`:
```javascript
/* global jQuery, wp, swpsAeo */
(function ($) {
    'use strict';

    // ── Classic editor metabox wiring ─────────────────────────────────
    function panelPostId() {
        const $p = $('.swps-aeo-panel');
        return $p.length ? parseInt($p.data('post-id'), 10) : null;
    }

    function rescore() {
        const id = panelPostId();
        if (!id) return;
        $.post(swpsAeo.ajaxUrl, {
            action: 'swps_aeo_score',
            nonce:  swpsAeo.nonce,
            post_id: id,
        }).done(function (resp) {
            if (!resp.success) return;
            $('#swps-aeo-panel-num').text(resp.data.total);
            ['extractability', 'markup', 'authority', 'coverage'].forEach(d => {
                const v = resp.data.subscores[d];
                $('#swps-aeo-panel-sub-' + d).text(v === null || v === undefined ? '—' : v);
            });
        });
    }

    function improve() {
        const id = panelPostId();
        if (!id) return;
        $.post(swpsAeo.ajaxUrl, {
            action: 'swps_aeo_propose',
            nonce:  swpsAeo.nonce,
            post_id: id,
        }).done(function (resp) {
            if (!resp.success) {
                alert(resp.data && resp.data.message ? resp.data.message : swpsAeo.i18n.genericFail);
                return;
            }
            // Render a compact modal — re-use the same markup as the optimizer page.
            // For brevity in this panel, just alert the projected score.
            alert(swpsAeo.i18n.projected + ': ' + resp.data.proposal.projected_score +
                  '\n\nOpen the AEO Optimize page to review and apply changes.');
        });
    }

    $(document).on('click', '#swps-aeo-panel-rescore', rescore);
    $(document).on('click', '#swps-aeo-panel-improve', improve);

    // ── Gutenberg sidebar plugin (only if @wordpress/plugins is loaded) ──
    if (typeof wp !== 'undefined' && wp.plugins && wp.editPost && wp.element && wp.components && wp.data) {
        const { registerPlugin } = wp.plugins;
        const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
        const { Fragment, createElement: el } = wp.element;
        const { Button, PanelBody } = wp.components;
        const { useSelect } = wp.data;

        const AeoSidebar = () => {
            const postId = useSelect(select => select('core/editor').getCurrentPostId(), []);

            const onRescore = () => {
                wp.apiFetch({
                    url: swpsAeo.ajaxUrl + '?action=swps_aeo_score&post_id=' + postId + '&nonce=' + swpsAeo.nonce,
                    method: 'POST',
                }).then(() => {
                    wp.data.dispatch('core/notices').createNotice('success', 'AEO re-scored.');
                });
            };

            const onImprove = () => {
                wp.apiFetch({
                    url: swpsAeo.ajaxUrl + '?action=swps_aeo_propose&post_id=' + postId + '&nonce=' + swpsAeo.nonce,
                    method: 'POST',
                }).then((resp) => {
                    if (resp && resp.success) {
                        wp.data.dispatch('core/notices').createNotice('success',
                            'AEO proposal ready (projected ' + resp.data.proposal.projected_score + '). Open AEO Optimize to apply.');
                    }
                });
            };

            return el(Fragment, {},
                el(PluginSidebarMoreMenuItem, { target: 'swps-aeo-sidebar' }, 'AEO Score'),
                el(PluginSidebar, { name: 'swps-aeo-sidebar', title: 'AEO Score' },
                    el(PanelBody, {},
                        el('p', {}, 'Re-score this post on AI-citeability, or fetch an AI improvement proposal.'),
                        el(Button, { isSecondary: true, onClick: onRescore }, 'Re-score'),
                        ' ',
                        el(Button, { isPrimary: true, onClick: onImprove }, 'Improve AEO')
                    )
                )
            );
        };

        registerPlugin('swps-aeo-sidebar', { render: AeoSidebar, icon: 'chart-area' });
    }

})(jQuery);
```

- [ ] **Step 2: Manual smoke**

Open a post in the block editor → confirm "AEO Score" appears in the more-menu (⋮) → click it → sidebar opens → Re-score updates the score (verify by re-checking post meta).

Open a post in classic editor (if available) → metabox renders → Re-score works.

- [ ] **Step 3: Commit**

```bash
git add admin/js/aeo-editor-panel.js
git commit -m "feat(aeo): editor panel JS — classic metabox + Gutenberg sidebar

Classic side metabox uses jQuery + admin-ajax. Gutenberg sidebar
plugin uses @wordpress/plugins + @wordpress/edit-post. Both
trigger the same wp_ajax_swps_aeo_score and _propose handlers.
Improve flow surfaces a notice prompting the user to apply on
the AEO Optimize page (deep apply UI is the bulk page)."
```

---

## Phase 8 — Integrations and housekeeping

### Task 19: Dashboard tile, Bot Analytics deep-link, proposal-sweep cron

**Files:**
- Modify: `includes/class-dashboard.php`
- Modify: `includes/class-bot-analytics-tracker.php`
- Modify: `includes/class-aeo-optimizer.php` (add cron sweep)

- [ ] **Step 1: Add Dashboard tile**

Find the dashboard tile-rendering method in `includes/class-dashboard.php` (grep for `swps-tile` or `render_dashboard`). Append a new tile:

```php
// AEO Health tile.
$aeo_avg = $this->get_aeo_avg_score();
$aeo_above_pct = $this->get_aeo_above_threshold_pct();
?>
<a class="swps-tile" href="<?php echo esc_url( admin_url( 'admin.php?page=swps-aeo-optimize' ) ); ?>">
    <span class="swps-tile-num"><?php echo esc_html( (string) $aeo_avg ); ?></span>
    <span class="swps-tile-label"><?php esc_html_e( 'AEO health (avg)', 'stratawp-seo' ); ?></span>
    <small><?php echo esc_html( $aeo_above_pct . '% above threshold' ); ?></small>
</a>
<?php
```

Add helper methods:
```php
private function get_aeo_avg_score(): int {
    global $wpdb;
    $avg = $wpdb->get_var( "SELECT AVG(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = '_swps_aeo_score'" );
    return (int) round( (float) $avg );
}

private function get_aeo_above_threshold_pct(): int {
    global $wpdb;
    $threshold = (int) get_option( 'swps_aeo_threshold', 70 );
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_swps_aeo_score'" );
    if ( $total <= 0 ) {
        return 0;
    }
    $above = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_swps_aeo_score' AND CAST(meta_value AS UNSIGNED) >= %d",
        $threshold
    ) );
    return (int) round( ( $above / $total ) * 100 );
}
```

- [ ] **Step 2: Add "Optimize for AEO →" link in Bot Analytics AEO gap report**

Find the gap-report-rendering code in `includes/class-bot-analytics-tracker.php` (grep for `gap` or `render_aeo`). For each row, append a deep link:

```php
$gap_link = add_query_arg(
    array(
        'page'       => 'swps-aeo-optimize',
        'focus_post' => (int) $row->post_id,
    ),
    admin_url( 'admin.php' )
);
echo '<a class="button button-small" href="' . esc_url( $gap_link ) . '">' .
     esc_html__( 'Optimize for AEO →', 'stratawp-seo' ) . '</a>';
```

In `aeo-optimizer.js`, add a small URL-param read at boot that auto-opens the proposal for that post if `?focus_post=N` is present.

- [ ] **Step 3: Add cron sweep for stale proposals**

Add to `SWPS_AEO_Optimizer::__construct()`:

```php
add_action( 'swps_aeo_sweep_proposals', array( $this, 'sweep_proposals' ) );
if ( ! wp_next_scheduled( 'swps_aeo_sweep_proposals' ) ) {
    wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'swps_aeo_sweep_proposals' );
}
```

Add method:
```php
public function sweep_proposals(): void {
    global $wpdb;
    $stale = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta}
         WHERE meta_key = '" . self::META_PROPOSAL . "'
         LIMIT 500"
    );
    foreach ( $stale as $row ) {
        $proposal = json_decode( (string) $row->meta_value, true );
        $age = isset( $proposal['_generated_at'] )
            ? time() - (int) $proposal['_generated_at']
            : DAY_IN_SECONDS + 1;
        if ( $age > DAY_IN_SECONDS ) {
            delete_post_meta( (int) $row->post_id, self::META_PROPOSAL );
        }
    }
}
```

In `ajax_propose()`, set `$proposal['_generated_at'] = time();` before caching.

Deactivation hook should also clear the schedule. Add to `stratawp-seo.php`:
```php
register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'swps_aeo_sweep_proposals' );
} );
```

- [ ] **Step 4: Lint**

```bash
composer lint -- includes/class-dashboard.php includes/class-bot-analytics-tracker.php includes/class-aeo-optimizer.php stratawp-seo.php
```

- [ ] **Step 5: Commit**

```bash
git add includes/class-dashboard.php \
        includes/class-bot-analytics-tracker.php \
        includes/class-aeo-optimizer.php \
        admin/js/aeo-optimizer.js \
        stratawp-seo.php
git commit -m "feat(aeo): dashboard tile + bot-analytics deep-link + cron sweep

- Dashboard 'AEO health (avg)' tile shows avg score + % above
  threshold, links to AEO Optimize page.
- Bot Analytics gap report rows get an 'Optimize for AEO →'
  button that deep-links to the optimizer with ?focus_post=N.
- Daily cron sweeps cached proposals older than 24h."
```

---

## Phase 9 — Polish and ship

### Task 20: Version bump, README + readme.txt + zip build

**Files:**
- Modify: `stratawp-seo.php` (header version + SWPS_VERSION constant)
- Modify: `readme.txt`
- Modify: `README.md`

- [ ] **Step 1: Bump version**

In `stratawp-seo.php`:
- Change `* Version: 4.5.1` → `* Version: 4.6.0`
- Change `define( 'SWPS_VERSION', '4.5.1' );` → `define( 'SWPS_VERSION', '4.6.0' );`

In `readme.txt`:
- Change `Stable tag: 4.5.1` → `Stable tag: 4.6.0`
- Add changelog entry under `== Changelog ==`:

```
= 4.6.0 — 2026-05-XX =
* New: AEO Optimize — score posts across 4 AI-citeability dimensions (Extractability, Markup, Authority, Coverage), generate AI proposals, review diffs, apply with one click. Mirrors Auto-Optimize UX.
* New: Live AEO Score panel in the post editor (Gutenberg sidebar + classic metabox).
* New: Dynamic JSON-LD schema generation for HowTo, Recipe, Product, Review, and QAPage. Coexists with Yoast / RankMath / AIOSEO (defers automatically).
* New: 6 REST endpoints under /swps/v1/aeo/* for external/programmatic access.
* New: 5 filter hooks for extensibility (swps_aeo_score, swps_aeo_subscores, swps_aeo_proposal, swps_aeo_schema_json, swps_aeo_dimensions).
* Improvement: Dashboard "AEO health" tile shows average score and % above threshold.
* Improvement: AI Bot Analytics gap-report rows now deep-link into the AEO Optimizer for one-click optimization.
```

In `README.md`:
- Update the version badge: `![Version](https://img.shields.io/badge/version-4.6.0-blue.svg)`
- Add a new feature section under "Features":

```markdown
### AEO Optimize (v4.6) ★

- **AEO Score with 4 dimensions** — Extractability (paragraphs that quote well + lists/tables/definitions), Markup (Q&A density + correct schema type), Authority (byline + dates + authoritative outbound links + freshness), Coverage (optional LLM-based topic completeness + entity clarity)
- **Bulk AEO Optimize page** — re-scan all posts (chunked, free since 3 of 4 dimensions are heuristic), threshold filter, queue of low-scoring posts, per-row AI proposal with diff review, apply with snapshot/undo
- **Live editor panel** — Gutenberg sidebar plugin + classic-editor metabox show the current score and 4 sub-scores in real time, with one-click "Re-score" and "Improve AEO"
- **Dynamic schema generation** — HowTo, Recipe, Product, Review, QAPage JSON-LD generated by AI based on post content, validated against schema.org required fields, rendered on the front-end (defers to Yoast / RankMath / AIOSEO when those are active)
- **5 filter hooks** for extensibility
```

Add to the Changelog section:
```markdown
### v4.6.0 — May 2026
- New: AEO Optimize (score + bulk page + editor panel + dynamic schema for 5 new types)
- New: 6 REST endpoints under `/swps/v1/aeo/*`
- Improvement: Dashboard AEO health tile + Bot Analytics deep-link
```

- [ ] **Step 2: Run full quality gate**

```bash
composer check
composer test
```

Expected: zero PHPCS issues, no PHPStan regressions, all PHPUnit tests pass.

- [ ] **Step 3: Build the deployment zip**

Per the user's standing rule (see `~/.claude/projects/.../memory/feedback_version_bump_zip.md`):

```bash
cd /Users/jon.imms/StrataWP-projects
rm -f stratawp-seo/stratawp-seo.zip stratawp-seo/stratawp-seo-4.6.0.zip

zip -r stratawp-seo/stratawp-seo-4.6.0.zip stratawp-seo \
    -x "stratawp-seo/.git/*" \
       "stratawp-seo/.claude*" \
       "stratawp-seo/.idea*" \
       "stratawp-seo/.codex*" \
       "stratawp-seo/.agents*" \
       "stratawp-seo/.superpowers*" \
       "stratawp-seo/.playwright-mcp*" \
       "stratawp-seo/.mcp.json" \
       "stratawp-seo/CLAUDE.md" \
       "stratawp-seo/AGENTS.md" \
       "stratawp-seo/.github/*" \
       "stratawp-seo/docs/rank-math-reference/*" \
       "stratawp-seo/node_modules/*" \
       "stratawp-seo/*.zip" \
       "stratawp-seo/tests/*" \
       "stratawp-seo/phpunit.xml.dist" \
       "stratawp-seo/.phpunit.cache/*"

cp stratawp-seo/stratawp-seo-4.6.0.zip stratawp-seo/stratawp-seo.zip
ls -lh stratawp-seo/stratawp-seo*.zip
```

Expected: two zip files, identical size.

- [ ] **Step 4: Commit version bump**

```bash
git add stratawp-seo.php readme.txt README.md
git commit -m "release: v4.6.0 — AEO Optimize

- Bump SWPS_VERSION + plugin header to 4.6.0
- readme.txt Stable tag + changelog entry
- README.md feature section + version badge + changelog"
```

- [ ] **Step 5: Tag (optional — wait for user before pushing)**

```bash
git tag -a v4.6.0 -m "v4.6.0 — AEO Optimize"
# Do NOT push without user confirmation.
```

### Task 21: Manual smoke test checklist

**No files modified.** This is the human-in-the-loop verification before declaring done.

- [ ] **Step 1: Run the checklist on a local WP install**

In a local WP 6.0+ install with the plugin freshly installed from the v4.6.0 zip:

1. **Settings tab loads.** Visit `Settings → StrataWP SEO → AEO`. Verify all controls render. Change threshold to 80, save, reload — value persisted.
2. **Empty state.** Visit `AEO Optimize`. Confirm empty-state message.
3. **Bulk re-scan.** Click "Re-scan all posts" with 10+ published posts. Verify progress bar advances; queue populates with posts below threshold.
4. **Generate proposal.** Click "Generate proposal" on a row. Verify modal opens with at least one of: schema preview / inserts / edits.
5. **Apply.** Check 1-2 items, click Apply. Verify row's score updates; modal closes; post content has the edits applied (open the post in the editor — `wp post get <id> --field=post_content`).
6. **Apply schema.** If proposal included a schema, verify `_swps_aeo_schema_type` + `_swps_aeo_schema_json` post meta were set: `wp post meta list <id>`. Visit the front-end URL, view source, confirm `<script type="application/ld+json">` block is present.
7. **Validate JSON-LD.** Copy the JSON-LD into [Google Rich Results Test](https://search.google.com/test/rich-results). Confirm the type is detected (Recipe / HowTo / etc.) without errors.
8. **Undo.** From the AEO Optimizer (or via WP-CLI: `wp eval 'StrataWP_SEO::instance()->aeo_optimizer->ajax_undo();'` is too invasive — instead, navigate to the editor, then back to the Optimizer and look for the snapshot-aware Undo button — implement if missing). Verify post content reverts.
9. **Dismiss.** Click dismiss on a row → row disappears. Confirm `_swps_aeo_dismissed = 1` via `wp post meta get`.
10. **Editor metabox.** Open any post. Confirm "AEO Score" metabox in the sidebar. Click "Re-score" → score updates.
11. **Gutenberg sidebar.** Block editor: open `⋮ → AEO Score`. Verify the sidebar opens and Re-score works.
12. **Yoast deferral.** Install Yoast SEO. Confirm the AEO schema does NOT render on singulars (`view-source:` should not have the AEO ld+json block). The score still calculates; only the schema is suppressed.
13. **WP-CLI smoke.** `wp eval 'echo rest_do_request( new WP_REST_Request( "GET", "/swps/v1/aeo/score/1" ) )->get_body();'` — JSON returned with `total` and `subscores`.
14. **Dashboard tile.** Visit the StrataWP SEO Dashboard. Confirm the "AEO health (avg)" tile renders with a number.
15. **Bot analytics deep-link.** Visit AI Bot Analytics. Confirm AEO gap-report rows have an "Optimize for AEO →" button that deep-links to the optimizer page.
16. **Activation/deactivation cycle.** Deactivate the plugin. Confirm the `swps_aeo_sweep_proposals` cron is cleared (`wp cron event list | grep aeo` → empty). Reactivate. Confirm cron is re-scheduled.

- [ ] **Step 2: Record any issues**

For each checklist failure, capture exact reproduction + error message. File as follow-up tasks; do NOT proceed to release until checklist is green or the user explicitly accepts known issues.

- [ ] **Step 3: Final lint + analyze + test sweep**

```bash
composer check
composer test
```

Both must be green before release.

---

## Appendix A — Extractability tests (full source for Task 6 Step 2)

`tests/aeo/test-extractability-scorer.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-extractability-scorer.php';

final class ExtractabilityScorerTest extends TestCase {

    private SWPS_AEO_Extractability_Scorer $scorer;

    protected function setUp(): void {
        $this->scorer = new SWPS_AEO_Extractability_Scorer();
    }

    public function test_high_quality_content_scores_above_75(): void {
        $html  = file_get_contents( __DIR__ . '/../fixtures/aeo/extractable-high.html' );
        $score = $this->scorer->score( $html );
        $this->assertGreaterThanOrEqual( 75, $score );
    }

    public function test_low_quality_content_scores_below_40(): void {
        $html  = file_get_contents( __DIR__ . '/../fixtures/aeo/extractable-low.html' );
        $this->assertLessThan( 40, $this->scorer->score( $html ) );
    }

    public function test_empty_content_returns_zero(): void {
        $this->assertSame( 0, $this->scorer->score( '' ) );
    }

    public function test_definitional_lead_pattern_detected(): void {
        $this->assertTrue( $this->scorer->has_definitional_lead( '<p>Sourdough is a naturally leavened bread.</p>' ) );
        $this->assertFalse( $this->scorer->has_definitional_lead( '<p>So I was thinking about bread.</p>' ) );
    }

    public function test_declarative_ratio_calculation(): void {
        $html  = '<p>Sourdough is bread. Flour matters. Hydration is key. Why does it taste sour?</p>';
        $this->assertEqualsWithDelta( 0.75, $this->scorer->declarative_ratio( $html ), 0.01 );
    }

    public function test_structural_density_counts_lists_and_tables(): void {
        $html = '<ul><li>a</li></ul><ol><li>b</li></ol><table><tr><td>c</td></tr></table>';
        $this->assertGreaterThan( 0, $this->scorer->structural_density( $html, 100 ) );
    }
}
```

## Appendix B — Extractability implementation (full class body for Task 6 Step 4)

Full class body for `includes/aeo/class-extractability-scorer.php`:

```php
class SWPS_AEO_Extractability_Scorer {

    private const HEDGES = array(
        'may be', 'might be', 'could be', 'perhaps', 'maybe',
        'we think', 'i think', 'in our opinion', 'arguably',
        'somewhat', 'sort of', 'kind of', 'probably', 'possibly',
    );

    public function score( string $html ): int {
        $html = trim( $html );
        if ( '' === $html ) {
            return 0;
        }
        $text       = trim( wp_strip_all_tags( $html ) );
        $word_count = str_word_count( $text );
        if ( $word_count < 20 ) {
            return 0;
        }
        $self_contained = $this->self_contained_paragraph_rate( $html );
        $declarative    = $this->declarative_ratio( $html );
        $structural     = min( 1.0, $this->structural_density( $html, $word_count ) / 4 );
        $definitional   = $this->has_definitional_lead( $html ) ? 1.0 : 0.0;

        $score = ( $self_contained * 0.30
                 + $declarative    * 0.30
                 + $structural     * 0.25
                 + $definitional   * 0.15 ) * 100;

        return (int) round( max( 0, min( 100, $score ) ) );
    }

    public function self_contained_paragraph_rate( string $html ): float {
        if ( ! preg_match_all( '#<p[^>]*>(.*?)</p>#is', $html, $matches ) ) {
            return 0.0;
        }
        $opens_bad = array(
            'i ', 'we ', 'they ', 'it ', 'this ', 'that ', 'these ', 'those ',
            'so ', 'but ', 'and ', 'or ', 'however ', 'anyway ', 'meanwhile ',
            'well ', 'now ', 'okay ', 'right ', 'um ', 'uh ',
        );
        $good = 0;
        $total = count( $matches[1] );
        foreach ( $matches[1] as $p ) {
            $first = strtolower( ltrim( wp_strip_all_tags( $p ) ) );
            if ( '' === $first ) { continue; }
            $bad = false;
            foreach ( $opens_bad as $w ) {
                if ( str_starts_with( $first, $w ) ) { $bad = true; break; }
            }
            if ( ! $bad ) { $good++; }
        }
        return $total > 0 ? $good / $total : 0.0;
    }

    public function declarative_ratio( string $html ): float {
        $text = trim( wp_strip_all_tags( $html ) );
        $sentences = preg_split( '/(?<=[.?!])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( empty( $sentences ) ) { return 0.0; }
        $declarative = 0;
        foreach ( $sentences as $s ) {
            $s = strtolower( trim( $s ) );
            if ( '' === $s || ! str_ends_with( $s, '.' ) ) { continue; }
            $has_hedge = false;
            foreach ( self::HEDGES as $h ) {
                if ( strpos( $s, $h ) !== false ) { $has_hedge = true; break; }
            }
            if ( ! $has_hedge ) { $declarative++; }
        }
        return $declarative / count( $sentences );
    }

    public function structural_density( string $html, int $word_count ): float {
        if ( $word_count <= 0 ) { return 0.0; }
        $count = preg_match_all( '#<(ul|ol|table|dl|blockquote)[\s>]#i', $html );
        return ( $count / max( 1, $word_count ) ) * 1000;
    }

    public function has_definitional_lead( string $html ): bool {
        if ( ! preg_match( '#<p[^>]*>(.*?)</p>#is', $html, $m ) ) { return false; }
        $first = trim( wp_strip_all_tags( $m[1] ) );
        return (bool) preg_match( '/^[A-Z][^.?!]{1,80}\s+(is|are|refers to|means)\s+[a-z]/u', $first );
    }
}
```

## Appendix C — Markup tests, fixtures, and implementation (Task 7)

Fixtures: see Task 7 Step 1 (markup-qa-heavy.html, markup-no-questions.html, markup-recipe-like.html).

Test file `tests/aeo/test-markup-scorer.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-markup-scorer.php';

final class MarkupScorerTest extends TestCase {

    private SWPS_AEO_Markup_Scorer $scorer;

    protected function setUp(): void {
        $this->scorer = new SWPS_AEO_Markup_Scorer();
    }

    public function test_qa_heavy_content_scores_high(): void {
        $html = file_get_contents( __DIR__ . '/../fixtures/aeo/markup-qa-heavy.html' );
        $this->assertGreaterThan( 70, $this->scorer->score( $html, array() ) );
    }

    public function test_no_questions_scores_low(): void {
        $html = file_get_contents( __DIR__ . '/../fixtures/aeo/markup-no-questions.html' );
        $this->assertLessThan( 35, $this->scorer->score( $html, array() ) );
    }

    public function test_count_questions_in_headings_and_body(): void {
        $html = '<h2>Why?</h2><p>Because.</p><p>What about now?</p><h3>How?</h3>';
        $this->assertSame( 3, $this->scorer->count_questions( $html ) );
    }

    public function test_questions_with_answers_pair_rate(): void {
        $html = '<h2>What?</h2><p>This is the answer.</p><h2>Why?</h2><p>Because reasons.</p><h2>Where?</h2>';
        $this->assertEqualsWithDelta( 0.67, $this->scorer->qa_pair_rate( $html ), 0.02 );
    }

    public function test_detect_recipe_pattern(): void {
        $html = file_get_contents( __DIR__ . '/../fixtures/aeo/markup-recipe-like.html' );
        $this->assertSame( 'recipe', $this->scorer->infer_schema_type( $html, 'My favorite cookies' ) );
    }

    public function test_detect_no_special_type_for_generic_post(): void {
        $this->assertNull( $this->scorer->infer_schema_type( '<p>Generic post.</p>', 'Generic post' ) );
    }

    public function test_existing_schema_mismatch_penalizes_score(): void {
        $html_recipe = file_get_contents( __DIR__ . '/../fixtures/aeo/markup-recipe-like.html' );
        $wrong = $this->scorer->score( $html_recipe, array( 'existing_schema' => 'Article', 'title' => 'My cookies' ) );
        $right = $this->scorer->score( $html_recipe, array( 'existing_schema' => 'Recipe',  'title' => 'My cookies' ) );
        $this->assertLessThan( $right, $wrong );
    }
}
```

Implementation of `SWPS_AEO_Markup_Scorer`:
```php
class SWPS_AEO_Markup_Scorer {

    private const TYPE_SIGNALS = array(
        'recipe' => array(
            '/\bingredients?\b/i',
            '/\b(prep|cook|total)\s*time\b/i',
            '/\b(servings|yield)\b/i',
            '/\b(bake|simmer|whisk|fold in|stir)\b/i',
        ),
        'howto' => array(
            '/\bhow to\b/i',
            '/\bstep\s*\d+\b/i',
            '/<ol[^>]*>(?:[^<]|<(?!\/ol>))*<li/is',
        ),
        'product' => array(
            '/\$\s?\d{1,5}(?:\.\d{2})?\b/',
            '/\b(brand|sku|model|specifications?)\b/i',
            '/\bbuy now\b/i',
        ),
        'review' => array(
            '/\b(review of|review:)\b/i',
            '/\bpros\b.*\bcons\b/is',
            '/\b\d(?:\.\d)?\s*\/\s*5\b/',
            '/★{3,5}/u',
        ),
        'qapage' => array(),
    );

    public function score( string $html, array $context ): int {
        $q_count       = $this->count_questions( $html );
        $pair_rate     = $this->qa_pair_rate( $html );
        $expected_type = $this->infer_schema_type( $html, $context['title'] ?? '' );
        $existing      = $context['existing_schema'] ?? null;

        $q_score = min( 1.0, $q_count / 5 ) * 40;
        $a_score = $pair_rate * 30;
        $s_score = 30;
        if ( null !== $expected_type ) {
            if ( null === $existing || strtolower( $existing ) !== $expected_type ) {
                $s_score = 5;
            }
        }
        if ( $q_count >= 3 && $existing !== 'FAQPage' && $expected_type !== 'recipe' ) {
            $s_score = max( 0, $s_score - 10 );
        }
        return (int) round( max( 0, min( 100, $q_score + $a_score + $s_score ) ) );
    }

    public function count_questions( string $html ): int {
        $headings = preg_match_all( '#<h[2-6][^>]*>([^<]*\?)\s*</h[2-6]>#i', $html );
        $body     = preg_match_all( '#(?:<p[^>]*>|<li[^>]*>)([^<]*\?)\s*(?:</p>|</li>)#i', $html );
        return (int) ( $headings + $body );
    }

    public function qa_pair_rate( string $html ): float {
        if ( ! preg_match_all( '#<h[2-6][^>]*>([^<]*\?)\s*</h[2-6]>(.*?)(?=<h[2-6]|$)#is', $html, $matches ) ) {
            return 0.0;
        }
        $total = count( $matches[0] );
        if ( 0 === $total ) { return 0.0; }
        $with_answer = 0;
        foreach ( $matches[2] as $following ) {
            if ( ! preg_match( '#<p[^>]*>(.*?)</p>#is', $following, $p ) ) { continue; }
            $text  = trim( wp_strip_all_tags( $p[1] ) );
            if ( '' === $text ) { continue; }
            if ( str_word_count( $text ) < 150 && substr( $text, -1 ) !== '?' ) {
                $with_answer++;
            }
        }
        return $with_answer / $total;
    }

    public function infer_schema_type( string $html, string $title ): ?string {
        $title = strtolower( $title );
        $haystack = $title . ' ' . $html;
        if ( str_ends_with( trim( $title ), '?' ) ) { return 'qapage'; }
        if ( preg_match_all( '#<h2[^>]*>([^<]+)</h2>#i', $html, $h2s ) ) {
            $total_h2 = count( $h2s[1] );
            if ( $total_h2 > 0 ) {
                $q_h2 = 0;
                foreach ( $h2s[1] as $h ) {
                    if ( str_ends_with( trim( $h ), '?' ) ) { $q_h2++; }
                }
                if ( $q_h2 / $total_h2 >= 0.8 ) { return 'qapage'; }
            }
        }
        $scores = array();
        foreach ( self::TYPE_SIGNALS as $slug => $patterns ) {
            if ( 'qapage' === $slug ) { continue; }
            $hits = 0;
            foreach ( $patterns as $pat ) {
                if ( preg_match( $pat, $haystack ) ) { $hits++; }
            }
            if ( $hits >= 2 ) { $scores[ $slug ] = $hits; }
        }
        if ( empty( $scores ) ) { return null; }
        arsort( $scores );
        return (string) array_key_first( $scores );
    }
}
```

## Appendix D — Authority tests and implementation (Task 8)

Test file `tests/aeo/test-authority-scorer.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-authority-scorer.php';

final class AuthorityScorerTest extends TestCase {

    private SWPS_AEO_Authority_Scorer $scorer;

    protected function setUp(): void {
        $this->scorer = new SWPS_AEO_Authority_Scorer();
    }

    public function test_post_with_byline_dates_and_authoritative_links_scores_high(): void {
        $html = '<p>According to <a href="https://www.nih.gov/study">NIH</a>, true. ' .
                '<em>Updated: 2026-03-15.</em> See also <a href="https://en.wikipedia.org/wiki/X">Wikipedia</a>.</p>';
        $ctx = array(
            'author' => 'Dr. Jane Smith',
            'published_unix' => strtotime( '-6 months' ),
            'modified_unix'  => strtotime( '-2 weeks' ),
            'word_count' => 800,
        );
        $this->assertGreaterThan( 75, $this->scorer->score( $html, $ctx ) );
    }

    public function test_post_with_no_signals_scores_low(): void {
        $html = '<p>Just plain text.</p>';
        $ctx = array( 'author' => '', 'published_unix' => 0, 'modified_unix' => 0, 'word_count' => 50 );
        $this->assertLessThan( 25, $this->scorer->score( $html, $ctx ) );
    }

    public function test_authoritative_link_count_uses_domain_allowlist(): void {
        $html = '<a href="https://nih.gov/x">NIH</a> ' .
                '<a href="https://random.example/y">random</a> ' .
                '<a href="https://www.bbc.co.uk/z">BBC</a>';
        $this->assertSame( 2, $this->scorer->count_authoritative_links( $html ) );
    }

    public function test_authoritative_link_count_uses_tld_allowlist(): void {
        $html = '<a href="https://example.gov/x">Gov</a> <a href="https://example.edu/y">Edu</a> <a href="https://example.com/z">.com</a>';
        $this->assertSame( 2, $this->scorer->count_authoritative_links( $html ) );
    }

    public function test_current_year_mention_detected(): void {
        $year = (int) gmdate( 'Y' );
        $this->assertTrue( $this->scorer->has_current_year_mention( "Updated for {$year}." ) );
        $this->assertFalse( $this->scorer->has_current_year_mention( 'Updated for 2019.' ) );
    }

    public function test_updated_notice_pattern(): void {
        $this->assertTrue( $this->scorer->has_updated_notice( '<p><em>Updated: March 2026</em></p>' ) );
        $this->assertTrue( $this->scorer->has_updated_notice( 'Last reviewed: 2025-12-01' ) );
        $this->assertFalse( $this->scorer->has_updated_notice( 'Just regular text.' ) );
    }
}
```

Implementation of `SWPS_AEO_Authority_Scorer`:
```php
class SWPS_AEO_Authority_Scorer {

    /** @var array{tlds: string[], domains: string[]}|null */
    private ?array $domain_data = null;

    public function score( string $html, array $context ): int {
        $byline_present     = ! empty( $context['author'] );
        $fresh              = $this->is_fresh(
            (int) ( $context['published_unix'] ?? 0 ),
            (int) ( $context['modified_unix']  ?? 0 )
        );
        $auth_links         = $this->count_authoritative_links( $html );
        $word_count         = max( 1, (int) ( $context['word_count'] ?? 0 ) );
        $links_per_1k_words = ( $auth_links / $word_count ) * 1000;
        $current_year       = $this->has_current_year_mention( $html );
        $updated_notice     = $this->has_updated_notice( $html );

        $score  = 0;
        $score += $byline_present ? 25 : 0;
        $score += $fresh ? 25 : 0;
        $score += min( 1.0, $links_per_1k_words / 1.5 ) * 30;
        $score += $current_year ? 10 : 0;
        $score += $updated_notice ? 10 : 0;
        return (int) round( max( 0, min( 100, $score ) ) );
    }

    public function count_authoritative_links( string $html ): int {
        if ( ! preg_match_all( '#<a[^>]+href=["\']([^"\']+)["\']#i', $html, $matches ) ) { return 0; }
        $data = $this->load_domains();
        $count = 0;
        foreach ( $matches[1] as $url ) {
            $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
            if ( '' === $host ) { continue; }
            foreach ( $data['tlds'] as $tld ) {
                if ( str_ends_with( $host, $tld ) ) { $count++; continue 2; }
            }
            foreach ( $data['domains'] as $domain ) {
                if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) { $count++; break; }
            }
        }
        return $count;
    }

    public function has_current_year_mention( string $text ): bool {
        return (bool) preg_match( '/\b' . (int) gmdate( 'Y' ) . '\b/', $text );
    }

    public function has_updated_notice( string $html ): bool {
        return (bool) preg_match( '/\b(updated|last reviewed|last updated|revised)\s*[:\-]/i', wp_strip_all_tags( $html ) );
    }

    private function is_fresh( int $published, int $modified ): bool {
        $now = time();
        if ( $modified > 0 && ( $now - $modified ) < ( 365 * 86400 ) ) { return true; }
        if ( $published > 0 && ( $now - $published ) < ( 730 * 86400 ) ) { return true; }
        return false;
    }

    private function load_domains(): array {
        if ( null !== $this->domain_data ) { return $this->domain_data; }
        $path = defined( 'SWPS_PLUGIN_DIR' )
            ? SWPS_PLUGIN_DIR . 'includes/data/authoritative-domains.json'
            : __DIR__ . '/../data/authoritative-domains.json';
        $raw = is_readable( $path ) ? file_get_contents( $path ) : '{}';
        $decoded = json_decode( $raw, true );
        $tlds    = ( is_array( $decoded ) && isset( $decoded['tlds'] )    && is_array( $decoded['tlds'] ) )    ? $decoded['tlds']    : array();
        $domains = ( is_array( $decoded ) && isset( $decoded['domains'] ) && is_array( $decoded['domains'] ) ) ? $decoded['domains'] : array();
        if ( function_exists( 'apply_filters' ) ) {
            $domains = (array) apply_filters( 'swps_authoritative_domains', $domains );
        }
        $this->domain_data = array(
            'tlds'    => array_map( 'strtolower', $tlds ),
            'domains' => array_map( 'strtolower', $domains ),
        );
        return $this->domain_data;
    }
}
```

## Appendix E — Orchestrator tests and implementation (Task 9)

Test file `tests/aeo/test-aeo-scorer.php`:
```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/aeo/class-extractability-scorer.php';
require_once __DIR__ . '/../../includes/aeo/class-markup-scorer.php';
require_once __DIR__ . '/../../includes/aeo/class-authority-scorer.php';
require_once __DIR__ . '/../../includes/aeo/class-coverage-scorer.php';
require_once __DIR__ . '/../../includes/class-aeo-scorer.php';

final class AeoScorerTest extends TestCase {

    private function makeScorer(): SWPS_AEO_Scorer {
        return new SWPS_AEO_Scorer(
            new SWPS_AEO_Extractability_Scorer(),
            new SWPS_AEO_Markup_Scorer(),
            new SWPS_AEO_Authority_Scorer(),
            new SWPS_AEO_Coverage_Scorer( new class {
                public function chat_json( array $m, int $t = 0 ): array { return array(); }
            } )
        );
    }

    public function test_orchestrator_returns_total_and_subscores(): void {
        $scorer = $this->makeScorer();
        $r = $scorer->score_html( '<p>Sourdough is a naturally leavened bread.</p>', array(
            'title' => 'Sourdough basics', 'author' => 'Jon',
            'published_unix' => time(), 'modified_unix' => time(),
            'word_count' => 8, 'existing_schema' => null, 'coverage_cached' => 60,
        ) );
        $this->assertArrayHasKey( 'total', $r );
        $this->assertArrayHasKey( 'subscores', $r );
        $this->assertArrayHasKey( 'extractability', $r['subscores'] );
        $this->assertArrayHasKey( 'markup', $r['subscores'] );
        $this->assertArrayHasKey( 'authority', $r['subscores'] );
        $this->assertArrayHasKey( 'coverage', $r['subscores'] );
        $this->assertIsInt( $r['total'] );
        $this->assertGreaterThanOrEqual( 0, $r['total'] );
        $this->assertLessThanOrEqual( 100, $r['total'] );
    }

    public function test_weights_redistribute_when_coverage_missing(): void {
        $scorer = $this->makeScorer();
        $r = $scorer->score_html( '<p>Sourdough is a naturally leavened bread.</p>', array(
            'title' => 't', 'author' => 'Jon', 'published_unix' => time(),
            'modified_unix' => time(), 'word_count' => 8,
            'existing_schema' => null, 'coverage_cached' => null,
        ) );
        $this->assertNull( $r['subscores']['coverage'] );
        $this->assertIsInt( $r['total'] );
    }

    public function test_custom_weights_passed_in(): void {
        $scorer = $this->makeScorer();
        $ctx = array(
            'title' => 't', 'author' => '', 'published_unix' => 0, 'modified_unix' => 0,
            'word_count' => 2, 'existing_schema' => null, 'coverage_cached' => 80,
        );
        $r1 = $scorer->score_html( '<p>Test content with enough words to score.</p>', $ctx,
            array( 'extractability' => 1.0, 'markup' => 0, 'authority' => 0, 'coverage' => 0 ) );
        $r2 = $scorer->score_html( '<p>Test content.</p>', $ctx,
            array( 'extractability' => 0, 'markup' => 0, 'authority' => 0, 'coverage' => 1.0 ) );
        $this->assertSame( $r1['subscores']['extractability'], $r1['total'] );
        $this->assertSame( 80, $r2['total'] );
    }
}
```

Implementation of `SWPS_AEO_Scorer`:
```php
class SWPS_AEO_Scorer {

    public const META_TOTAL              = '_swps_aeo_score';
    public const META_SUBSCORE_PREFIX    = '_swps_aeo_subscore_';
    public const META_LAST_SCAN          = '_swps_aeo_last_scan';
    public const META_CONTENT_HASH       = '_swps_aeo_content_hash';

    public const OPTION_THRESHOLD        = 'swps_aeo_threshold';
    public const OPTION_WEIGHTS          = 'swps_aeo_weights';
    public const OPTION_COVERAGE_ENABLED = 'swps_aeo_coverage_enabled';

    public const DEFAULT_WEIGHTS = array(
        'extractability' => 0.30, 'markup' => 0.30, 'authority' => 0.20, 'coverage' => 0.20,
    );

    private SWPS_AEO_Extractability_Scorer $extractability;
    private SWPS_AEO_Markup_Scorer         $markup;
    private SWPS_AEO_Authority_Scorer      $authority;
    private SWPS_AEO_Coverage_Scorer       $coverage;

    public function __construct(
        SWPS_AEO_Extractability_Scorer $extractability,
        SWPS_AEO_Markup_Scorer $markup,
        SWPS_AEO_Authority_Scorer $authority,
        SWPS_AEO_Coverage_Scorer $coverage
    ) {
        $this->extractability = $extractability;
        $this->markup         = $markup;
        $this->authority      = $authority;
        $this->coverage       = $coverage;
    }

    public function score_html( string $html, array $ctx, ?array $weights = null ): array {
        $subscores = array(
            'extractability' => $this->extractability->score( $html ),
            'markup'         => $this->markup->score( $html, array(
                'existing_schema' => $ctx['existing_schema'] ?? null,
                'title'           => $ctx['title']           ?? '',
            ) ),
            'authority'      => $this->authority->score( $html, array(
                'author'         => $ctx['author']         ?? '',
                'published_unix' => $ctx['published_unix'] ?? 0,
                'modified_unix'  => $ctx['modified_unix']  ?? 0,
                'word_count'     => $ctx['word_count']     ?? 0,
            ) ),
            'coverage'       => array_key_exists( 'coverage_cached', $ctx ) && null !== $ctx['coverage_cached']
                                ? (int) $ctx['coverage_cached']
                                : null,
        );

        if ( function_exists( 'apply_filters' ) ) {
            $subscores = (array) apply_filters( 'swps_aeo_subscores', $subscores, $ctx );
        }

        $weights = $weights ?? $this->load_weights();
        $total   = $this->compute_total( $subscores, $weights );

        if ( function_exists( 'apply_filters' ) ) {
            $total = (int) apply_filters( 'swps_aeo_score', $total, $ctx, $subscores );
        }

        return array( 'total' => $total, 'subscores' => $subscores );
    }

    public function score_post( int $post_id, ?array $weights = null ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return array( 'total' => 0, 'subscores' => array(
                'extractability' => 0, 'markup' => 0, 'authority' => 0, 'coverage' => null,
            ) );
        }
        $ctx = array(
            'title'           => $post->post_title,
            'author'          => get_the_author_meta( 'display_name', (int) $post->post_author ),
            'published_unix'  => (int) strtotime( $post->post_date_gmt ),
            'modified_unix'   => (int) strtotime( $post->post_modified_gmt ),
            'word_count'      => str_word_count( wp_strip_all_tags( $post->post_content ) ),
            'existing_schema' => $this->detect_existing_schema( $post ),
            'coverage_cached' => $this->load_cached_coverage( $post_id, $post->post_content ),
        );
        $result = $this->score_html( $post->post_content, $ctx, $weights );

        update_post_meta( $post_id, self::META_TOTAL, $result['total'] );
        foreach ( $result['subscores'] as $k => $v ) {
            if ( null === $v ) {
                delete_post_meta( $post_id, self::META_SUBSCORE_PREFIX . $k );
            } else {
                update_post_meta( $post_id, self::META_SUBSCORE_PREFIX . $k, $v );
            }
        }
        update_post_meta( $post_id, self::META_LAST_SCAN, time() );
        return $result;
    }

    private function compute_total( array $subscores, array $weights ): int {
        $w_sum = 0.0; $weighted = 0.0;
        foreach ( $subscores as $dim => $val ) {
            if ( null === $val ) { continue; }
            $w = (float) ( $weights[ $dim ] ?? 0 );
            $weighted += $val * $w;
            $w_sum    += $w;
        }
        if ( $w_sum <= 0 ) { return 0; }
        return (int) round( $weighted / $w_sum );
    }

    private function load_weights(): array {
        if ( ! function_exists( 'get_option' ) ) { return self::DEFAULT_WEIGHTS; }
        return array_merge( self::DEFAULT_WEIGHTS, (array) get_option( self::OPTION_WEIGHTS, self::DEFAULT_WEIGHTS ) );
    }

    private function detect_existing_schema( WP_Post $post ): ?string {
        $aeo_type = get_post_meta( $post->ID, '_swps_aeo_schema_type', true );
        if ( ! empty( $aeo_type ) ) { return ucfirst( (string) $aeo_type ); }
        if ( false !== stripos( $post->post_content, 'FAQPage' ) ) { return 'FAQPage'; }
        return null;
    }

    private function load_cached_coverage( int $post_id, string $content ): ?int {
        $cached = get_post_meta( $post_id, '_swps_aeo_subscore_coverage', true );
        if ( '' === $cached ) { return null; }
        $hash    = get_post_meta( $post_id, self::META_CONTENT_HASH, true );
        if ( $hash !== md5( $content ) ) { return null; }
        return (int) $cached;
    }
}
```

---

## Self-Review

This plan was reviewed against the spec at `docs/superpowers/specs/2026-05-23-aeo-optimize-design.md`. Coverage check:

| Spec section | Plan task(s) |
|---|---|
| §3.1 — 8 new classes | Tasks 1, 6, 7, 8, 9, 10, 11, 13, 17 |
| §3.2 — Extended classes (Schema, REST, Settings, Hooks, Dashboard, Bot Analytics) | Tasks 4, 5, 12, 14, 19 |
| §3.3 — Frontend assets | Tasks 15, 16, 18 |
| §3.4 — Post meta + options storage | Tasks 5, 9, 13 (constants in class-aeo-scorer + class-aeo-optimizer) |
| §4 — Scoring model (4 dimensions + weights) | Tasks 6, 7, 8, 9, 10 |
| §5 — Schema generator (5 types, validation, rendering) | Tasks 11, 12 |
| §6 — REST API (6 endpoints) | Task 14 |
| §7 — Admin page + diff modal + editor panel | Tasks 15, 16, 17, 18 |
| §8 — Hooks (5 filters) | Task 4 (doc) + Tasks 9, 11, 13 (apply_filters calls) |
| §9 — Coexistence (defer when Yoast/RankMath/AIOSEO active) | Task 12 (Step 2 deferral helper) |
| §10 — Cost/performance budget | Tasks 10, 13 (cost tracking via SWPS_Cost_Tracker) |
| §11 — Error handling (validation, rollback, rate-limit) | Tasks 11 (validation), 13 (snapshot/undo, rate limiter) |
| §12 — Testing (unit + integration + manual) | Tasks 2, 6-10, 11, 21 |
| §13 — Rollout (no migration, additive) | Tasks 1, 13 (no DDL; uninstall.php wildcard already covers) |
| §14 — Versioning + zip | Task 20 |
| §15 — Open questions | Acknowledged in spec; not blocking |

**Gaps / follow-up:**
- The Coverage scorer's invocation must update `_swps_aeo_content_hash` after a successful score. The plan has the `score_post()` orchestrator reading the hash but does NOT yet have the optimizer's propose-flow writing it back when Coverage runs. **Add to Task 13**: after invoking `$this->coverage->score(...)` (if implemented in propose path), call `update_post_meta( $post_id, SWPS_AEO_Scorer::META_CONTENT_HASH, md5( $post->post_content ) )` and `update_post_meta( $post_id, '_swps_aeo_subscore_coverage', $cov_score )`.
- The Coverage scorer is wired into `$this->ai_provider` but the propose flow does NOT call `$this->coverage->score()` — the orchestrator's `coverage_cached` path uses prior cache only. **Decision:** Coverage is only triggered manually (via a UI control planned for v4.6.1) or by an explicit Re-score with Coverage button on the panel. Plan Task 17 covers the panel button but not the "with Coverage" variant. **Add a one-line note in Task 21 (smoke checklist) acknowledging Coverage scoring is opt-in by manual trigger**, and earmark "Re-score with Coverage" as a v4.6.1 follow-up.

These are non-blocking; the plan ships a working v4.6 with Coverage opt-in.

