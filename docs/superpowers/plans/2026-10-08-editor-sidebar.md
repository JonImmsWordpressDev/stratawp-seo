# Editor Sidebar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the classic SEO and AEO metaboxes in the block editor with a live `PluginSidebar` that has multi-keyword analysis, an AI visibility panel and a Fix flow that applies through the editor store.

**Architecture:** A WordPress-free PHP check engine (the source of truth) and a JS mirror (the instant tier) are kept in lockstep by a golden-file parity test. A new REST layer (`swps/v1/editor/*`) serves the deep tier (cached AEO, citations, schema, uniqueness, AI fixes). The sidebar is built with `@wordpress/scripts`; compiled output is committed to `admin/editor/`.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, PHPUnit (no WordPress loaded), `@wordpress/scripts` (React, Jest), Playwright for a manual smoke test.

**Spec:** `docs/superpowers/specs/2026-10-08-editor-sidebar-design.md`

## Global Constraints

- PHP 8.0+ and WordPress 6.0+ (plugin header). Text domain `stratawp-seo`, REST namespace `swps/v1`, meta prefix `_swps_`, class prefix `SWPS_`.
- Compiled JS is committed to `admin/editor/` (never `build/`: `bin/build-zip.sh` uses `build/` as its zip output directory and excludes it).
- Existing admin screens stay jQuery. The classic metaboxes stay as the fallback for non-block-editor screens.
- Existing post meta keys are unchanged; no migration. Yoast and Rank Math imports are unaffected.
- PHPUnit tests run WITHOUT WordPress loaded (`tests/bootstrap.php` stubs only `__`, `wp_strip_all_tags`, `wp_parse_url`, `WP_Error`). Anything that needs WordPress stays out of the pure classes.
- The version lives in three places (`stratawp-seo.php` header, `SWPS_VERSION`, `readme.txt` Stable tag plus changelog) and is bumped only in the final task.
- Commits are authored as Jon Imms with NO AI attribution, no `Co-Authored-By`, no session trailers (repo `CLAUDE.md`). Stage explicit paths only. The working tree has unrelated untracked files; never use `git add -A`.
- Do not write em dashes or en dashes in any user-facing string, comment or doc. Restructure the sentence.
- AI fixes must pass `SWPS_Autopilot_Guardian::check_budget()` and be tracked with `SWPS_Cost_Tracker::track()`. Nothing is written to the post without an explicit Apply.
- Branch: `feat/editor-sidebar` (already created from `origin/main`, holds the spec commit).

## Review Focus

Failure modes the spec implies that no feature task is built around. Each has a pinning test in the task that owns the code.

1. **No focus keyword yet.** A new post must not show a wall of red. Keyword checks return `na`, the score uses global checks only, status is `no_keyword`. (Task 2 `test_empty_keyword_makes_keyword_checks_na`, Task 3 golden case `empty-keyword`, Task 5 group test `keeps every keyword check under notAnalyzed when no keyword is set`.)
2. **Non-English and CJK content.** Word-based and English-only checks must report `na` ("not analyzed"), never a silent pass. Accented keywords must still match. (Task 2 `test_cjk_skips_word_based_checks`, `test_accented_keyword_matches`, Task 3 golden cases `japanese` and `accents`.)
3. **Gutenberg block comments and shortcodes in content.** They must not count as words or hide keywords. (Task 2 `test_block_comments_and_shortcodes_are_ignored`, Task 3 golden case `blocks-and-shortcodes`.)
4. **Unsaved or auto-draft posts.** Analysis and fixes must use the editor state sent in the request, never the saved DB copy. (Task 5 `useAnalysis` reads the editor store; Task 7 `analyze` and Task 8 `fix` read `content_html` from the request; Task 8 `test_target_must_match_the_current_first_or_last_paragraph`; Task 7 manual permission and unsaved checks.)
5. **Stale or ambiguous AI fixes.** A proposal whose target paragraph changed, whose replacement lacks the keyword, or that is a no-op must be rejected, not applied. (Task 8 `test_proposal_without_the_keyword_is_rejected`, `test_noop_proposal_is_rejected`, `test_unsafe_markup_is_rejected`, `test_paragraph_length_must_stay_close_to_the_original`, `test_target_must_match_the_current_first_or_last_paragraph`, and the apply-after-edit manual step.)

## File Structure

Create:

| Path | Responsibility |
|---|---|
| `package.json`, `package-lock.json` | JS toolchain and scripts |
| `src/editor/index.js` | Registers the plugin |
| `src/editor/compat.js` | `PluginSidebar` and friends across WP 6.0 to current |
| `src/editor/analysis/text.js` | Pure text helpers (mirror of `SWPS_Editor_Text`) |
| `src/editor/analysis/checks.js` | `runChecks(input)` (mirror of the PHP engine) |
| `src/editor/analysis/score.js` | `scoreOutput(output, registry)` |
| `src/editor/analysis/fixes.js` | Rule fixes (slug, trim title, trim description) |
| `src/editor/hooks/*.js` | `usePostMeta`, `useKeywords`, `useAnalysis`, `useDeep` |
| `src/editor/components/*.js` | Sidebar and panels |
| `src/editor/style.scss` | Styles |
| `src/editor/__tests__/*.test.js` | Jest tests |
| `includes/editor/class-editor-text.php` | Pure text helpers |
| `includes/editor/class-editor-check-registry.php` | Check definitions, weights, JS export |
| `includes/editor/class-editor-check-engine.php` | Pure engine `run()` and `score()` |
| `includes/editor/class-editor-input.php` | WordPress-bound input builder, presets, uniqueness |
| `includes/editor/class-editor-fix.php` | Pure fix prompt building and proposal validation |
| `includes/editor/class-editor-snapshots.php` | Pending fixes and proof snapshots |
| `includes/editor/class-editor-rest.php` | REST routes |
| `includes/editor/class-editor-sidebar.php` | Rollout setting, assets, meta registration, metabox fallback |
| `bin/gen-editor-golden.php` | Regenerates the golden parity file |
| `tests/fixtures/editor/inputs.json`, `golden.json` | Parity fixtures |
| `tests/unit/Editor*Test.php` | PHPUnit tests |
| `e2e/editor-sidebar.spec.js` | Manual Playwright smoke test |

Modify: `stratawp-seo.php`, `includes/class-meta-editor.php`, `includes/class-aeo-editor-panel.php`, `phpstan.neon.dist`, `phpcs.xml.dist`, `bin/build-zip.sh`, `.github/workflows/release.yml`, `.github/workflows/quality.yml`, `readme.txt`, `README.md`.

---

### Task 1: Toolchain, rollout setting and an empty sidebar

**Files:**
- Create: `package.json`, `src/editor/index.js`, `src/editor/compat.js`, `src/editor/components/Sidebar.js`, `src/editor/style.scss`, `includes/editor/class-editor-sidebar.php`
- Modify: `stratawp-seo.php`, `phpstan.neon.dist`, `phpcs.xml.dist`, `includes/class-meta-editor.php`, `includes/class-aeo-editor-panel.php`

**Interfaces:**
- Produces: `SWPS_Editor_Sidebar::is_enabled(): bool`, `SWPS_Editor_Sidebar::hide_classic_metaboxes(): bool`, `SWPS_Editor_Sidebar::on_activate(): void`, window global `swpsEditor` (`{ enabled, toggleUrl }`, extended by later tasks), compiled bundle `admin/editor/index.js` plus `index.asset.php` and `index.css`.

- [ ] **Step 1: Create the npm project and scripts**

```bash
cd ~/StrataWP-projects/stratawp-seo
npm init -y >/dev/null
npm pkg set name=stratawp-seo private=true version=0.0.0
npm pkg delete main keywords author license description
npm pkg set scripts.build="wp-scripts build --webpack-src-dir=src/editor --output-path=admin/editor"
npm pkg set scripts.start="wp-scripts start --webpack-src-dir=src/editor --output-path=admin/editor"
npm pkg set scripts.test:js="wp-scripts test-unit-js src/editor"
npm pkg set scripts.verify:build="npm run build && git diff --exit-code -- admin/editor"
npm install --save-dev @wordpress/scripts @playwright/test
```

Expected: `package.json` and `package-lock.json` exist; `node_modules/` is already in `.gitignore`.

- [ ] **Step 2: Write the compat shim**

`src/editor/compat.js`:

```js
import * as editor from '@wordpress/editor';
import * as editPost from '@wordpress/edit-post';

// PluginSidebar and friends moved from @wordpress/edit-post to
// @wordpress/editor in WordPress 6.6. The plugin supports 6.0+, so prefer the
// new home and fall back to the old one.
export const PluginSidebar = editor.PluginSidebar || editPost.PluginSidebar;
export const PluginSidebarMoreMenuItem =
	editor.PluginSidebarMoreMenuItem || editPost.PluginSidebarMoreMenuItem;
export const PluginDocumentSettingPanel =
	editor.PluginDocumentSettingPanel || editPost.PluginDocumentSettingPanel;
```

- [ ] **Step 3: Write the placeholder sidebar and entry**

`src/editor/components/Sidebar.js`:

```js
import { __ } from '@wordpress/i18n';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '../compat';

export default function Sidebar() {
	const title = __( 'StrataWP SEO', 'stratawp-seo' );
	return (
		<>
			<PluginSidebarMoreMenuItem target="stratawp-seo">
				{ title }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name="stratawp-seo" title={ title } icon="search">
				<p className="swps-editor__empty">
					{ __( 'Analysis loads here.', 'stratawp-seo' ) }
				</p>
			</PluginSidebar>
		</>
	);
}
```

`src/editor/index.js`:

```js
import { registerPlugin } from '@wordpress/plugins';
import Sidebar from './components/Sidebar';
import './style.scss';

registerPlugin( 'stratawp-seo', { render: Sidebar, icon: 'search' } );
```

`src/editor/style.scss`:

```scss
.swps-editor__empty {
	margin: 0;
	padding: 16px;
}
```

- [ ] **Step 4: Build once and confirm output location**

Run: `npm run build`
Expected: `admin/editor/index.js`, `admin/editor/index.asset.php`, `admin/editor/index.css` exist.

- [ ] **Step 5: Write the PHP loader**

`includes/editor/class-editor-sidebar.php`:

```php
<?php
/**
 * Block editor sidebar: rollout setting, assets and classic metabox fallback.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Sidebar {

	public const OPTION_ENABLED   = 'swps_editor_sidebar';
	public const OPTION_INSTALLED = 'swps_installed_version';
	public const TOGGLE_ACTION    = 'swps_editor_sidebar_toggle';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'seed_rollout_default' ), 1 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
	}

	/**
	 * Fresh installs get the sidebar on; sites that already ran an earlier
	 * version get it off until they opt in. A fresh install runs activation
	 * first, which writes OPTION_INSTALLED, so seed_rollout_default() is then
	 * a no-op. An upgraded site never ran activation, so it lands there first.
	 */
	public static function on_activate(): void {
		if ( false === get_option( self::OPTION_INSTALLED ) ) {
			add_option( self::OPTION_INSTALLED, SWPS_VERSION );
			add_option( self::OPTION_ENABLED, '1' );
		}
	}

	public static function seed_rollout_default(): void {
		if ( false === get_option( self::OPTION_INSTALLED ) ) {
			add_option( self::OPTION_INSTALLED, SWPS_VERSION );
			add_option( self::OPTION_ENABLED, '0' );
		}
	}

	public static function is_enabled(): bool {
		$enabled = '1' === (string) get_option( self::OPTION_ENABLED, '0' );
		return (bool) apply_filters( 'swps_editor_sidebar_enabled', $enabled );
	}

	/**
	 * True when the classic SEO and AEO metaboxes should not register because
	 * the sidebar replaces them on this screen.
	 */
	public static function hide_classic_metaboxes(): bool {
		if ( ! self::is_enabled() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen instanceof WP_Screen && $screen->is_block_editor();
	}

	public function enqueue(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		// Post editor only. The Site Editor and widgets editor also fire this
		// hook, but they have no post to analyse.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! $screen->is_block_editor() ) {
			return;
		}
		$asset_file = SWPS_PLUGIN_DIR . 'admin/editor/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;

		wp_enqueue_script(
			'swps-editor',
			SWPS_PLUGIN_URL . 'admin/editor/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( 'swps-editor', 'stratawp-seo' );
		if ( file_exists( SWPS_PLUGIN_DIR . 'admin/editor/index.css' ) ) {
			wp_enqueue_style( 'swps-editor', SWPS_PLUGIN_URL . 'admin/editor/index.css', array(), $asset['version'] );
		}

		wp_add_inline_script(
			'swps-editor',
			'window.swpsEditor = ' . wp_json_encode( $this->script_data() ) . ';',
			'before'
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function script_data(): array {
		return array(
			'enabled'   => true,
			'toggleUrl' => $this->toggle_url( false ),
		);
	}

	private function toggle_url( bool $enable ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::TOGGLE_ACTION,
					'enable' => $enable ? '1' : '0',
				),
				admin_url( 'admin-post.php' )
			),
			self::TOGGLE_ACTION
		);
	}

	public function handle_toggle(): void {
		check_admin_referer( self::TOGGLE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change this setting.', 'stratawp-seo' ), 403 );
		}
		$enable = isset( $_GET['enable'] ) && '1' === $_GET['enable'];
		update_option( self::OPTION_ENABLED, $enable ? '1' : '0' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public function maybe_show_notice(): void {
		if ( self::is_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! $screen->is_block_editor() ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'StrataWP SEO has a new editor sidebar with live analysis, multiple keywords and AI fixes.', 'stratawp-seo' ),
			esc_url( $this->toggle_url( true ) ),
			esc_html__( 'Turn it on', 'stratawp-seo' )
		);
	}
}
```

- [ ] **Step 6: Wire the loader into the plugin**

In `stratawp-seo.php`, directly after the line `require_once SWPS_PLUGIN_DIR . 'includes/class-modules.php';` add:

```php
// Block editor sidebar.
require_once SWPS_PLUGIN_DIR . 'includes/editor/class-editor-sidebar.php';
```

Directly after the line `$this->aeo_editor_panel  = new SWPS_AEO_Editor_Panel( $this->aeo_scorer );` add:

```php
		new SWPS_Editor_Sidebar();
```

As the first statement inside `function swps_activate()` add:

```php
	SWPS_Editor_Sidebar::on_activate();
```

- [ ] **Step 7: Make the classic metaboxes step aside inside the block editor**

In `includes/class-meta-editor.php`, as the first statement of `register_metabox()`:

```php
		if ( SWPS_Editor_Sidebar::hide_classic_metaboxes() ) {
			return;
		}
```

In `includes/class-aeo-editor-panel.php`, as the first statement of `register_metabox()`:

```php
		if ( SWPS_Editor_Sidebar::hide_classic_metaboxes() ) {
			return;
		}
```

- [ ] **Step 8: Keep static analysis away from compiled output**

In `phpstan.neon.dist` add `- admin/editor/*` under `excludePaths`. In `phpcs.xml.dist` add `<exclude-pattern>*/admin/editor/*</exclude-pattern>` directly after the `*/build/*` exclude line.

- [ ] **Step 9: Verify**

Run:

```bash
for f in includes/editor/class-editor-sidebar.php includes/class-meta-editor.php includes/class-aeo-editor-panel.php stratawp-seo.php; do php -l "$f"; done
composer analyze
composer test
```

Expected: no syntax errors, PHPStan clean, all existing PHPUnit tests pass.

Manual: on a local WordPress with the plugin active, run `wp option update swps_editor_sidebar 1`, open a post in the block editor. Expected: a "StrataWP SEO" sidebar entry in the editor top bar, the classic "StrataWP SEO" and "AEO Score" metaboxes are gone. Run `wp option update swps_editor_sidebar 0`: the metaboxes return and the notice with "Turn it on" appears.

- [ ] **Step 10: Commit**

```bash
git add package.json package-lock.json src/editor admin/editor includes/editor/class-editor-sidebar.php stratawp-seo.php includes/class-meta-editor.php includes/class-aeo-editor-panel.php phpstan.neon.dist phpcs.xml.dist
git commit -m "Add editor sidebar toolchain, rollout setting and empty sidebar"
```

---

### Task 2: PHP check registry and engine (source of truth)

**Files:**
- Create: `includes/editor/class-editor-text.php`, `includes/editor/class-editor-check-registry.php`, `includes/editor/class-editor-check-engine.php`, `tests/unit/EditorCheckEngineTest.php`

**Interfaces:**
- Produces:
  - `SWPS_Editor_Text::plain(string $html): string`, `::words(string $text): array`, `::sentences(string $text): array`, `::paragraphs(string $html): array`, `::headings(string $html): array` (items `{level:int,text:string}`), `::image_alts(string $html): array`, `::link_counts(string $html, string $host): array` (`{internal:int,external:int}`), `::lower()`, `::fold()`, `::slugify()`, `::host_key()`.
  - `SWPS_Editor_Check_Registry::all(): array` (items `{id,scope,dimension,group,fix,tier,label,help}`), `::for_js(): array` (`{checks, weights}`), const `DIMENSION_WEIGHTS`.
  - `SWPS_Editor_Check_Engine::run(array $input): array` returns `{global: {id: {status,value}}, keywords: [{keyword, results: {id: {status,value}}}]}`, and `::score(array $output): array` returns `{overall:int, seo:?int, readability:?int, status:string}`.
- Input shape for `run()`: `{title, slug, content_html, meta_title, meta_description, lang, host, preset:{min_words,title_min,title_max,desc_min,desc_max}, keywords:[{keyword, used_elsewhere:?bool}]}`. Index 0 of `keywords` is the focus keyword. `status` is one of `pass|warn|fail|na`.

- [ ] **Step 1: Write the failing tests**

`tests/unit/EditorCheckEngineTest.php`:

```php
<?php
/**
 * Tests for the pure editor check engine.
 *
 * No WordPress dependency: runs in the stub bootstrap environment.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/editor/class-editor-text.php';
require_once __DIR__ . '/../../includes/editor/class-editor-check-registry.php';
require_once __DIR__ . '/../../includes/editor/class-editor-check-engine.php';
require_once __DIR__ . '/../../includes/class-content-scorer.php';

final class EditorCheckEngineTest extends TestCase {

	private function html(): string {
		return '<!-- wp:paragraph --><p>Cold brew coffee is easier to make at home than most people expect. It takes a coarse grind, cold water and patience.</p><!-- /wp:paragraph -->'
			. '<!-- wp:heading --><h2>How to make cold brew coffee</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Add the grounds to a jar, pour in water and wait. However, the wait is the hard part. Therefore plan ahead and start the night before.</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Strain it, dilute it and enjoy. Cold brew coffee keeps for a week in the fridge.</p><!-- /wp:paragraph -->';
	}

	/**
	 * @param array<string,mixed> $over Overrides.
	 * @return array<string,mixed>
	 */
	private function input( array $over = array() ): array {
		return array_merge(
			array(
				'title'            => 'How to make cold brew coffee at home',
				'slug'             => 'cold-brew-coffee',
				'content_html'     => $this->html(),
				'meta_title'       => '',
				'meta_description' => 'Learn how to make cold brew coffee at home with a coarse grind and cold water.',
				'lang'             => 'en_US',
				'host'             => 'example.com',
				'preset'           => array( 'min_words' => 20 ),
				'keywords'         => array( array( 'keyword' => 'cold brew coffee', 'used_elsewhere' => false ) ),
			),
			$over
		);
	}

	public function test_keyword_placement_checks_pass_for_a_well_optimised_post(): void {
		$out = SWPS_Editor_Check_Engine::run( $this->input() );
		$r   = $out['keywords'][0]['results'];

		foreach ( array( 'kw_in_title', 'kw_in_description', 'kw_in_slug', 'kw_in_intro', 'kw_in_subheading', 'kw_in_conclusion', 'kw_unique' ) as $id ) {
			$this->assertSame( 'pass', $r[ $id ]['status'], $id );
		}
	}

	public function test_empty_keyword_makes_keyword_checks_na(): void {
		$out = SWPS_Editor_Check_Engine::run( $this->input( array( 'keywords' => array( array( 'keyword' => '' ) ) ) ) );

		foreach ( $out['keywords'][0]['results'] as $id => $res ) {
			$this->assertSame( 'na', $res['status'], $id );
		}
		$this->assertNotSame( 'na', $out['global']['title_length']['status'] );

		$score = SWPS_Editor_Check_Engine::score( $out );
		$this->assertSame( 'no_keyword', $score['status'] );
		$this->assertIsInt( $score['overall'] );
	}

	public function test_cjk_skips_word_based_checks(): void {
		$out = SWPS_Editor_Check_Engine::run(
			$this->input(
				array(
					'lang'         => 'ja',
					'content_html' => '<p>これはテストです。日本語の文章です。三つ目の文です。</p>',
					'keywords'     => array( array( 'keyword' => 'テスト' ) ),
				)
			)
		);

		foreach ( array( 'content_length', 'sentence_length', 'paragraph_length', 'transition_words', 'passive_voice', 'consecutive_starters', 'subheading_distribution' ) as $id ) {
			$this->assertSame( 'na', $out['global'][ $id ]['status'], $id );
		}
		$this->assertSame( 'na', $out['keywords'][0]['results']['kw_density']['status'] );
	}

	public function test_accented_keyword_matches(): void {
		$out = SWPS_Editor_Check_Engine::run(
			$this->input(
				array(
					'title'    => 'Café au lait at home',
					'slug'     => 'cafe-au-lait',
					'keywords' => array( array( 'keyword' => 'café au lait' ) ),
				)
			)
		);
		$r   = $out['keywords'][0]['results'];

		$this->assertSame( 'pass', $r['kw_in_title']['status'] );
		$this->assertSame( 'pass', $r['kw_in_slug']['status'] );
	}

	public function test_block_comments_and_shortcodes_are_ignored(): void {
		$html = '<!-- wp:paragraph {"className":"cold brew"} --><p>Hello [gallery ids="1,2"] world</p><!-- /wp:paragraph -->';

		$this->assertSame( 'Hello world', SWPS_Editor_Text::plain( $html ) );
		$this->assertCount( 2, SWPS_Editor_Text::words( SWPS_Editor_Text::plain( $html ) ) );
	}

	public function test_density_is_occurrences_over_words(): void {
		$html = '<p>' . str_repeat( 'word ', 98 ) . 'espresso espresso</p>';
		$out  = SWPS_Editor_Check_Engine::run( $this->input( array( 'content_html' => $html, 'keywords' => array( array( 'keyword' => 'espresso' ) ) ) ) );
		$res  = $out['keywords'][0]['results']['kw_density'];

		$this->assertSame( 'pass', $res['status'] );
		$this->assertEqualsWithDelta( 2.0, $res['value'], 0.001 );
	}

	public function test_stuffed_keyword_fails_density(): void {
		$html = '<p>' . str_repeat( 'espresso ', 10 ) . str_repeat( 'word ', 10 ) . '</p>';
		$out  = SWPS_Editor_Check_Engine::run( $this->input( array( 'content_html' => $html, 'keywords' => array( array( 'keyword' => 'espresso' ) ) ) ) );

		$this->assertSame( 'fail', $out['keywords'][0]['results']['kw_density']['status'] );
	}

	public function test_link_classification(): void {
		$html = '<a href="/about">a</a><a href="https://www.example.com/x">b</a><a href="https://other.org/y">c</a><a href="#top">d</a><a href="mailto:a@b.co">e</a>';

		$this->assertSame( array( 'internal' => 2, 'external' => 1 ), SWPS_Editor_Text::link_counts( $html, 'example.com' ) );
	}

	public function test_missing_image_alt_is_flagged(): void {
		$html = '<p>Text</p><img src="a.jpg" alt=""><img src="b.jpg" alt="cold brew coffee jar"><img src="c.jpg">';
		$out  = SWPS_Editor_Check_Engine::run( $this->input( array( 'content_html' => $html ) ) );

		$this->assertSame( 'warn', $out['global']['image_alt_missing']['status'] );
		$this->assertSame( 2, $out['global']['image_alt_missing']['value'] );
		$this->assertSame( 'pass', $out['keywords'][0]['results']['kw_in_image_alt']['status'] );
	}

	public function test_used_elsewhere_flag_drives_unique_check(): void {
		$statuses = array();
		foreach ( array( true, false, null ) as $flag ) {
			$out        = SWPS_Editor_Check_Engine::run( $this->input( array( 'keywords' => array( array( 'keyword' => 'cold brew coffee', 'used_elsewhere' => $flag ) ) ) ) );
			$statuses[] = $out['keywords'][0]['results']['kw_unique']['status'];
		}

		$this->assertSame( array( 'fail', 'pass', 'na' ), $statuses );
	}

	public function test_related_keywords_weigh_less_than_the_focus_keyword(): void {
		$focus_only = SWPS_Editor_Check_Engine::score( SWPS_Editor_Check_Engine::run( $this->input() ) );

		$with_bad_related = SWPS_Editor_Check_Engine::score(
			SWPS_Editor_Check_Engine::run(
				$this->input(
					array(
						'keywords' => array(
							array( 'keyword' => 'cold brew coffee' ),
							array( 'keyword' => 'zzz unrelated phrase' ),
						),
					)
				)
			)
		);

		$bad_focus = SWPS_Editor_Check_Engine::score(
			SWPS_Editor_Check_Engine::run(
				$this->input(
					array(
						'keywords' => array(
							array( 'keyword' => 'zzz unrelated phrase' ),
							array( 'keyword' => 'cold brew coffee' ),
						),
					)
				)
			)
		);

		$this->assertLessThan( $focus_only['overall'], $with_bad_related['overall'] );
		$this->assertLessThan( $with_bad_related['overall'], $bad_focus['overall'] );
	}

	public function test_dimension_weights_match_the_legacy_content_scorer(): void {
		$this->assertSame( SWPS_Content_Scorer::DEFAULT_WEIGHTS, SWPS_Editor_Check_Registry::DIMENSION_WEIGHTS );
	}

	public function test_every_check_is_registered_and_produced(): void {
		$out = SWPS_Editor_Check_Engine::run( $this->input() );

		foreach ( SWPS_Editor_Check_Registry::all() as $def ) {
			if ( 'global' === $def['scope'] ) {
				$this->assertArrayHasKey( $def['id'], $out['global'], $def['id'] );
			} else {
				$this->assertArrayHasKey( $def['id'], $out['keywords'][0]['results'], $def['id'] );
			}
		}
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/unit/EditorCheckEngineTest.php`
Expected: FAIL (fatal "Failed opening required .../class-editor-text.php").

- [ ] **Step 3: Write the text helpers**

`includes/editor/class-editor-text.php`:

```php
<?php
/**
 * Pure text helpers for the editor check engine.
 *
 * No WordPress calls: these run in PHPUnit without WordPress loaded and must
 * behave exactly like src/editor/analysis/text.js (a golden-file test pins it).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Text {

	private const FOLD_FROM = 'àáâãäåçèéêëìíîïñòóôõöùúûüýÿ';
	private const FOLD_TO   = 'aaaaaaceeeeiiiinooooouuuuyy';

	public static function lower( string $s ): string {
		return mb_strtolower( $s, 'UTF-8' );
	}

	/**
	 * Fold common Latin accents so a keyword like "café" matches the slug "cafe".
	 */
	public static function fold( string $s ): string {
		static $map = null;
		if ( null === $map ) {
			$map = array_combine( mb_str_split( self::FOLD_FROM ), str_split( self::FOLD_TO ) );
		}
		return strtr( $s, $map );
	}

	public static function slugify( string $s ): string {
		return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', '-', self::fold( self::lower( $s ) ) ), '-' );
	}

	public static function host_key( string $host ): string {
		return (string) preg_replace( '/^www\./', '', self::lower( trim( $host ) ) );
	}

	/**
	 * Strip block comments, scripts, shortcodes and tags; decode a few entities.
	 */
	public static function plain( string $html ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '#</(?:p|div|h[1-6]|li|blockquote|tr|ul|ol)>|<br\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '/\[\/?[a-z_][\w-]*(?:\s[^\]]*)?\]/i', ' ', $html );
		$text = (string) preg_replace( '/<[^>]*>/', '', $html );
		$text = (string) preg_replace_callback(
			'/&(amp|nbsp|quot|#039|#8217|lt|gt);/',
			static function ( array $m ): string {
				$map = array(
					'amp'   => '&',
					'nbsp'  => ' ',
					'quot'  => '"',
					'#039'  => "'",
					'#8217' => "'",
					'lt'    => '<',
					'gt'    => '>',
				);
				return $map[ $m[1] ];
			},
			$text
		);
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n[ \n]*/', "\n", $text );
		return trim( $text );
	}

	/**
	 * @return string[]
	 */
	public static function words( string $text ): array {
		$w = preg_split( '/[^\p{L}\p{N}\'’-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $w ) ? $w : array();
	}

	/**
	 * @return string[]
	 */
	public static function sentences( string $text ): array {
		$out = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$parts = preg_split( '/(?<=[.!?])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( (array) $parts as $p ) {
				$p = trim( $p );
				if ( '' !== $p && count( self::words( $p ) ) > 0 ) {
					$out[] = $p;
				}
			}
		}
		return $out;
	}

	/**
	 * Plain text of each paragraph. Uses <p> elements; falls back to lines.
	 *
	 * @return string[]
	 */
	public static function paragraphs( string $html ): array {
		$out = array();
		if ( preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', $html, $m ) ) {
			foreach ( $m[1] as $inner ) {
				$t = self::plain( $inner );
				if ( '' !== $t ) {
					$out[] = $t;
				}
			}
		}
		if ( ! $out ) {
			foreach ( explode( "\n", self::plain( $html ) ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$out[] = $line;
				}
			}
		}
		return $out;
	}

	/**
	 * @return array<int,array{level:int,text:string}>
	 */
	public static function headings( string $html ): array {
		$out = array();
		if ( preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$out[] = array(
					'level' => (int) $row[1],
					'text'  => self::plain( $row[2] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Alt text of every <img>, trimmed. Missing alt is an empty string.
	 *
	 * @return string[]
	 */
	public static function image_alts( string $html ): array {
		$out = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$alt = '';
				if ( preg_match( '/(?<![\w-])alt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $a ) ) {
					$alt = '' !== ( $a[1] ?? '' ) ? $a[1] : ( $a[2] ?? '' );
				}
				$out[] = trim( $alt );
			}
		}
		return $out;
	}

	/**
	 * @return array{internal:int,external:int}
	 */
	public static function link_counts( string $html, string $host ): array {
		$host     = self::host_key( $host );
		$internal = 0;
		$external = 0;
		if ( preg_match_all( '/<a\b[^>]*?(?<![\w-])href\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$href = trim( '' !== ( $row[1] ?? '' ) ? $row[1] : ( $row[2] ?? '' ) );
				if ( '' === $href || preg_match( '/^(#|mailto:|tel:|javascript:)/i', $href ) ) {
					continue;
				}
				if ( preg_match( '#^(?:https?:)?//([^/:?\#]+)#i', $href, $h ) ) {
					if ( self::host_key( $h[1] ) === $host ) {
						++$internal;
					} else {
						++$external;
					}
				} else {
					++$internal;
				}
			}
		}
		return array(
			'internal' => $internal,
			'external' => $external,
		);
	}
}
```

- [ ] **Step 4: Write the registry**

`includes/editor/class-editor-check-registry.php`:

```php
<?php
/**
 * Definitions for every editor check. One list feeds PHP, the REST layer and
 * (via for_js()) the sidebar, so a check is added in exactly one place.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Check_Registry {

	/**
	 * Same weights as SWPS_Content_Scorer::DEFAULT_WEIGHTS (a test pins this)
	 * so scores on existing posts do not swing.
	 */
	public const DIMENSION_WEIGHTS = array(
		'keyword_density'   => 15,
		'readability'       => 20,
		'heading_structure' => 15,
		'meta_quality'      => 15,
		'internal_links'    => 10,
		'content_depth'     => 15,
		'engagement'        => 10,
	);

	/**
	 * @return array<string,mixed>
	 */
	private static function def( string $id, string $scope, string $dimension, string $fix, string $label, string $help ): array {
		return array(
			'id'        => $id,
			'scope'     => $scope,
			'dimension' => $dimension,
			'group'     => 'readability' === $dimension ? 'readability' : 'seo',
			'fix'       => $fix,
			'tier'      => 'instant',
			'label'     => $label,
			'help'      => $help,
		);
	}

	/**
	 * Global checks first, then keyword checks. Order is part of the contract:
	 * the scorers iterate in this order so PHP and JS sum floats identically.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		return array(
			self::def( 'title_length', 'global', 'meta_quality', 'rule', __( 'Title length', 'stratawp-seo' ), __( 'Keep the title long enough to be useful and short enough to avoid being cut off in results.', 'stratawp-seo' ) ),
			self::def( 'description_length', 'global', 'meta_quality', 'rule', __( 'Meta description length', 'stratawp-seo' ), __( 'Write a description that fills the snippet without being truncated.', 'stratawp-seo' ) ),
			self::def( 'content_length', 'global', 'content_depth', 'none', __( 'Content length', 'stratawp-seo' ), __( 'Cover the topic in enough depth for this type of page.', 'stratawp-seo' ) ),
			self::def( 'internal_links', 'global', 'internal_links', 'none', __( 'Internal links', 'stratawp-seo' ), __( 'Link to at least one related page on your site.', 'stratawp-seo' ) ),
			self::def( 'external_links', 'global', 'internal_links', 'none', __( 'External links', 'stratawp-seo' ), __( 'Link out to at least one trustworthy source.', 'stratawp-seo' ) ),
			self::def( 'subheading_distribution', 'global', 'heading_structure', 'none', __( 'Subheading distribution', 'stratawp-seo' ), __( 'Break long stretches of text with subheadings.', 'stratawp-seo' ) ),
			self::def( 'image_alt_missing', 'global', 'engagement', 'none', __( 'Image alt text', 'stratawp-seo' ), __( 'Give every image descriptive alt text.', 'stratawp-seo' ) ),
			self::def( 'sentence_length', 'global', 'readability', 'none', __( 'Sentence length', 'stratawp-seo' ), __( 'Keep most sentences under 20 words.', 'stratawp-seo' ) ),
			self::def( 'paragraph_length', 'global', 'readability', 'none', __( 'Paragraph length', 'stratawp-seo' ), __( 'Split paragraphs longer than 150 words.', 'stratawp-seo' ) ),
			self::def( 'transition_words', 'global', 'readability', 'none', __( 'Transition words', 'stratawp-seo' ), __( 'Use connecting words so the text flows.', 'stratawp-seo' ) ),
			self::def( 'passive_voice', 'global', 'readability', 'none', __( 'Passive voice', 'stratawp-seo' ), __( 'Prefer active sentences.', 'stratawp-seo' ) ),
			self::def( 'consecutive_starters', 'global', 'readability', 'none', __( 'Sentence beginnings', 'stratawp-seo' ), __( 'Avoid starting three sentences in a row with the same word.', 'stratawp-seo' ) ),
			self::def( 'kw_in_title', 'keyword', 'meta_quality', 'ai', __( 'Keyword in the title', 'stratawp-seo' ), __( 'Use the keyword in the page title.', 'stratawp-seo' ) ),
			self::def( 'kw_in_description', 'keyword', 'meta_quality', 'ai', __( 'Keyword in the meta description', 'stratawp-seo' ), __( 'Use the keyword in the meta description.', 'stratawp-seo' ) ),
			self::def( 'kw_in_slug', 'keyword', 'meta_quality', 'rule', __( 'Keyword in the URL slug', 'stratawp-seo' ), __( 'Use the keyword in the URL.', 'stratawp-seo' ) ),
			self::def( 'kw_in_intro', 'keyword', 'keyword_density', 'ai', __( 'Keyword in the introduction', 'stratawp-seo' ), __( 'Mention the keyword in the first paragraph.', 'stratawp-seo' ) ),
			self::def( 'kw_in_subheading', 'keyword', 'heading_structure', 'none', __( 'Keyword in a subheading', 'stratawp-seo' ), __( 'Use the keyword in at least one H2 or H3.', 'stratawp-seo' ) ),
			self::def( 'kw_in_image_alt', 'keyword', 'engagement', 'none', __( 'Keyword in image alt text', 'stratawp-seo' ), __( 'Describe at least one image using the keyword.', 'stratawp-seo' ) ),
			self::def( 'kw_density', 'keyword', 'keyword_density', 'none', __( 'Keyword density', 'stratawp-seo' ), __( 'Use the keyword naturally, about 0.5 to 3 percent of the words.', 'stratawp-seo' ) ),
			self::def( 'kw_title_position', 'keyword', 'keyword_density', 'none', __( 'Keyword near the start of the title', 'stratawp-seo' ), __( 'Place the keyword in the first half of the title.', 'stratawp-seo' ) ),
			self::def( 'kw_in_conclusion', 'keyword', 'keyword_density', 'ai', __( 'Keyword in the conclusion', 'stratawp-seo' ), __( 'Mention the keyword in the last paragraph.', 'stratawp-seo' ) ),
			self::def( 'kw_unique', 'keyword', 'meta_quality', 'none', __( 'Keyword not used on another post', 'stratawp-seo' ), __( 'Using one keyword on several pages makes them compete.', 'stratawp-seo' ) ),
		);
	}

	/**
	 * Data the sidebar needs to label and score results.
	 *
	 * @return array{checks:array<int,array<string,mixed>>,weights:array<string,int>}
	 */
	public static function for_js(): array {
		return array(
			'checks'  => self::all(),
			'weights' => self::DIMENSION_WEIGHTS,
		);
	}
}
```

- [ ] **Step 5: Write the engine**

`includes/editor/class-editor-check-engine.php`:

```php
<?php
/**
 * Pure check engine. Source of truth for the instant checks; the JS mirror in
 * src/editor/analysis/checks.js must produce identical output (golden test).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Check_Engine {

	private const TRANSITIONS = array(
		'however',
		'therefore',
		'moreover',
		'furthermore',
		'consequently',
		'meanwhile',
		'additionally',
		'similarly',
		'for example',
		'for instance',
		'in addition',
		'as a result',
		'in conclusion',
		'on the other hand',
		'in contrast',
		'finally',
		'first',
		'second',
		'third',
		'next',
		'then',
		'also',
		'because',
		'although',
		'while',
		'since',
		'instead',
		'otherwise',
		'nevertheless',
		'thus',
		'hence',
		'besides',
		'likewise',
		'specifically',
		'in fact',
		'in summary',
	);

	private const PASSIVE = '/\b(?:is|are|was|were|be|been|being)\s+(?:\w+ed|\w+en)\b/i';

	/**
	 * @param array<string,mixed> $input Raw input; see the task interface.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $input ): array {
		$preset = array_merge(
			array(
				'min_words' => 300,
				'title_min' => 30,
				'title_max' => 60,
				'desc_min'  => 70,
				'desc_max'  => 160,
			),
			is_array( $input['preset'] ?? null ) ? $input['preset'] : array()
		);

		$keywords = array();
		foreach ( (array) ( $input['keywords'] ?? array() ) as $row ) {
			$row        = (array) $row;
			$keywords[] = array(
				'keyword'        => trim( (string) ( $row['keyword'] ?? '' ) ),
				'used_elsewhere' => isset( $row['used_elsewhere'] ) ? (bool) $row['used_elsewhere'] : null,
			);
		}
		if ( ! $keywords ) {
			$keywords[] = array(
				'keyword'        => '',
				'used_elsewhere' => null,
			);
		}

		$lang = (string) ( $input['lang'] ?? '' );

		return array(
			'title'            => (string) ( $input['title'] ?? '' ),
			'slug'             => (string) ( $input['slug'] ?? '' ),
			'content_html'     => (string) ( $input['content_html'] ?? '' ),
			'meta_title'       => (string) ( $input['meta_title'] ?? '' ),
			'meta_description' => (string) ( $input['meta_description'] ?? '' ),
			'lang'             => '' !== $lang ? $lang : 'en',
			'host'             => (string) ( $input['host'] ?? '' ),
			'preset'           => array_map( 'intval', $preset ),
			'keywords'         => $keywords,
		);
	}

	/**
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function run( array $input ): array {
		$in  = self::normalize( $input );
		$ctx = self::context( $in );
		$out = array(
			'global'   => self::global_checks( $in, $ctx ),
			'keywords' => array(),
		);
		foreach ( $in['keywords'] as $row ) {
			$out['keywords'][] = array(
				'keyword' => $row['keyword'],
				'results' => self::keyword_checks( $row, $in, $ctx ),
			);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $output Result of run().
	 * @return array{overall:int,seo:?int,readability:?int,status:string}
	 */
	public static function score( array $output ): array {
		$weights = SWPS_Editor_Check_Registry::DIMENSION_WEIGHTS;
		$sum     = array();
		$den     = array();
		$add     = static function ( string $dim, float $w, string $status ) use ( &$sum, &$den ): void {
			if ( 'na' === $status ) {
				return;
			}
			$v           = 'pass' === $status ? 1.0 : ( 'warn' === $status ? 0.5 : 0.0 );
			$sum[ $dim ] = ( $sum[ $dim ] ?? 0.0 ) + $w * $v;
			$den[ $dim ] = ( $den[ $dim ] ?? 0.0 ) + $w;
		};

		foreach ( SWPS_Editor_Check_Registry::all() as $def ) {
			if ( 'global' === $def['scope'] ) {
				$add( $def['dimension'], 1.0, $output['global'][ $def['id'] ]['status'] ?? 'na' );
				continue;
			}
			foreach ( $output['keywords'] as $i => $kw ) {
				$add( $def['dimension'], 0 === $i ? 1.0 : 0.25, $kw['results'][ $def['id'] ]['status'] ?? 'na' );
			}
		}

		$dim_score = array();
		foreach ( $weights as $dim => $w ) {
			if ( isset( $den[ $dim ] ) && $den[ $dim ] > 0 ) {
				$dim_score[ $dim ] = $sum[ $dim ] / $den[ $dim ];
			}
		}

		$overall = self::weighted( $dim_score, $weights, 'all' );
		$has_kw  = '' !== ( $output['keywords'][0]['keyword'] ?? '' );
		$score   = (int) $overall;

		if ( ! $has_kw ) {
			$status = 'no_keyword';
		} elseif ( $score >= 75 ) {
			$status = 'good';
		} elseif ( $score >= 50 ) {
			$status = 'needs_work';
		} else {
			$status = 'poor';
		}

		return array(
			'overall'     => $score,
			'seo'         => self::weighted( $dim_score, $weights, 'seo' ),
			'readability' => self::weighted( $dim_score, $weights, 'readability' ),
			'status'      => $status,
		);
	}

	/**
	 * @param array<string,float> $dim_score Score 0 to 1 per dimension.
	 * @param array<string,int>   $weights   Dimension weights.
	 */
	private static function weighted( array $dim_score, array $weights, string $subset ): ?int {
		$num = 0.0;
		$den = 0.0;
		foreach ( $weights as $dim => $w ) {
			if ( ! isset( $dim_score[ $dim ] ) ) {
				continue;
			}
			if ( 'seo' === $subset && 'readability' === $dim ) {
				continue;
			}
			if ( 'readability' === $subset && 'readability' !== $dim ) {
				continue;
			}
			$num += $w * $dim_score[ $dim ];
			$den += $w;
		}
		return $den > 0 ? self::rnd( $num / $den * 100 ) : null;
	}

	/**
	 * Round half up with plain IEEE arithmetic. PHP's round() pre-rounds and
	 * would disagree with JS Math.round on values like 28.499999999999996, so
	 * both sides use floor(x + 0.5).
	 */
	private static function rnd( float $x ): int {
		return (int) floor( $x + 0.5 );
	}

	/**
	 * @param array<string,mixed> $in Normalized input.
	 * @return array<string,mixed>
	 */
	private static function context( array $in ): array {
		$html      = $in['content_html'];
		$text      = SWPS_Editor_Text::plain( $html );
		$sentences = SWPS_Editor_Text::sentences( $text );

		return array(
			'title'      => '' !== trim( $in['meta_title'] ) ? $in['meta_title'] : $in['title'],
			'text'       => $text,
			'word_count' => count( SWPS_Editor_Text::words( $text ) ),
			'wordbased'  => ! preg_match( '/^(ja|zh|ko|th)/i', $in['lang'] ),
			'english'    => (bool) preg_match( '/^en/i', $in['lang'] ),
			'paragraphs' => SWPS_Editor_Text::paragraphs( $html ),
			'headings'   => SWPS_Editor_Text::headings( $html ),
			'alts'       => SWPS_Editor_Text::image_alts( $html ),
			'links'      => SWPS_Editor_Text::link_counts( $html, $in['host'] ),
			'sentences'  => $sentences,
			'swords'     => array_map( array( 'SWPS_Editor_Text', 'words' ), $sentences ),
		);
	}

	/**
	 * @param mixed $value Optional numeric value.
	 * @return array{status:string,value:mixed}
	 */
	private static function r( string $status, $value = null ): array {
		return array(
			'status' => $status,
			'value'  => $value,
		);
	}

	private static function has( string $haystack, string $needle_lower ): bool {
		return false !== mb_strpos( SWPS_Editor_Text::lower( $haystack ), $needle_lower );
	}

	/**
	 * @param array<string,mixed> $in  Normalized input.
	 * @param array<string,mixed> $c   Context.
	 * @return array<string,array{status:string,value:mixed}>
	 */
	private static function global_checks( array $in, array $c ): array {
		$p = $in['preset'];
		$g = array();

		$tl                = mb_strlen( trim( $c['title'] ) );
		$g['title_length'] = 0 === $tl
			? self::r( 'fail', 0 )
			: self::r( $tl >= $p['title_min'] && $tl <= $p['title_max'] ? 'pass' : 'warn', $tl );

		$dl                      = mb_strlen( trim( $in['meta_description'] ) );
		$g['description_length'] = 0 === $dl
			? self::r( 'fail', 0 )
			: self::r( $dl >= $p['desc_min'] && $dl <= $p['desc_max'] ? 'pass' : 'warn', $dl );

		if ( $c['wordbased'] ) {
			$wc                  = $c['word_count'];
			$g['content_length'] = self::r( $wc >= $p['min_words'] ? 'pass' : ( $wc * 2 >= $p['min_words'] ? 'warn' : 'fail' ), $wc );
		} else {
			$g['content_length'] = self::r( 'na' );
		}

		$g['internal_links'] = self::r( $c['links']['internal'] > 0 ? 'pass' : 'warn', $c['links']['internal'] );
		$g['external_links'] = self::r( $c['links']['external'] > 0 ? 'pass' : 'warn', $c['links']['external'] );

		if ( $c['wordbased'] && $c['word_count'] >= 300 ) {
			$max = 0;
			foreach ( (array) preg_split( '#<h[1-6]\b[^>]*>.*?</h[1-6]>#is', $in['content_html'] ) as $seg ) {
				$max = max( $max, count( SWPS_Editor_Text::words( SWPS_Editor_Text::plain( (string) $seg ) ) ) );
			}
			$g['subheading_distribution'] = self::r( $max > 300 ? 'warn' : 'pass', $max );
		} else {
			$g['subheading_distribution'] = self::r( 'na' );
		}

		if ( $c['alts'] ) {
			$missing                  = count(
				array_filter(
					$c['alts'],
					static function ( string $a ): bool {
						return '' === $a;
					}
				)
			);
			$g['image_alt_missing'] = self::r( 0 === $missing ? 'pass' : 'warn', $missing );
		} else {
			$g['image_alt_missing'] = self::r( 'na' );
		}

		$n     = count( $c['sentences'] );
		$ready = $c['wordbased'] && $n >= 3;

		if ( $ready ) {
			$long                  = count(
				array_filter(
					$c['swords'],
					static function ( array $w ): bool {
						return count( $w ) > 20;
					}
				)
			);
			$pct                   = self::rnd( $long / $n * 100 );
			$g['sentence_length'] = self::r( $pct <= 25 ? 'pass' : ( $pct <= 35 ? 'warn' : 'fail' ), $pct );
		} else {
			$g['sentence_length'] = self::r( 'na' );
		}

		if ( $c['wordbased'] && $c['paragraphs'] ) {
			$long                   = 0;
			foreach ( $c['paragraphs'] as $para ) {
				if ( count( SWPS_Editor_Text::words( $para ) ) > 150 ) {
					++$long;
				}
			}
			$g['paragraph_length'] = self::r( 0 === $long ? 'pass' : 'warn', $long );
		} else {
			$g['paragraph_length'] = self::r( 'na' );
		}

		if ( $ready && $c['english'] ) {
			$re    = '/\b(?:' . implode( '|', array_map( 'preg_quote', self::TRANSITIONS ) ) . ')\b/i';
			$hits  = 0;
			$pass  = 0;
			foreach ( $c['sentences'] as $s ) {
				if ( preg_match( $re, $s ) ) {
					++$hits;
				}
				if ( preg_match( self::PASSIVE, $s ) ) {
					++$pass;
				}
			}
			$tp                      = self::rnd( $hits / $n * 100 );
			$pp                      = self::rnd( $pass / $n * 100 );
			$g['transition_words'] = self::r( $tp >= 20 ? 'pass' : ( $tp >= 10 ? 'warn' : 'fail' ), $tp );
			$g['passive_voice']    = self::r( $pp <= 10 ? 'pass' : ( $pp <= 15 ? 'warn' : 'fail' ), $pp );
		} else {
			$g['transition_words'] = self::r( 'na' );
			$g['passive_voice']    = self::r( 'na' );
		}

		if ( $ready ) {
			$run  = 1;
			$best = 1;
			$prev = null;
			foreach ( $c['swords'] as $w ) {
				$first = SWPS_Editor_Text::lower( $w[0] );
				$run   = $first === $prev ? $run + 1 : 1;
				$best  = max( $best, $run );
				$prev  = $first;
			}
			$g['consecutive_starters'] = self::r( $best >= 3 ? 'warn' : 'pass', $best );
		} else {
			$g['consecutive_starters'] = self::r( 'na' );
		}

		return $g;
	}

	/**
	 * @param array<string,mixed> $row Keyword row.
	 * @param array<string,mixed> $in  Normalized input.
	 * @param array<string,mixed> $c   Context.
	 * @return array<string,array{status:string,value:mixed}>
	 */
	private static function keyword_checks( array $row, array $in, array $c ): array {
		$ids = array();
		foreach ( SWPS_Editor_Check_Registry::all() as $def ) {
			if ( 'keyword' === $def['scope'] ) {
				$ids[] = $def['id'];
			}
		}

		$kw = $row['keyword'];
		if ( '' === $kw ) {
			return array_fill_keys( $ids, self::r( 'na' ) );
		}

		$k   = SWPS_Editor_Text::lower( $kw );
		$res = array();

		$res['kw_in_title'] = self::r( self::has( $c['title'], $k ) ? 'pass' : 'fail' );

		$desc                    = trim( $in['meta_description'] );
		$res['kw_in_description'] = self::r( '' !== $desc && self::has( $desc, $k ) ? 'pass' : 'fail' );

		$slug_kw = SWPS_Editor_Text::slugify( $kw );
		if ( '' === $slug_kw ) {
			$res['kw_in_slug'] = self::r( 'na' );
		} else {
			$slug              = SWPS_Editor_Text::fold( SWPS_Editor_Text::lower( $in['slug'] ) );
			$res['kw_in_slug'] = self::r( false !== mb_strpos( $slug, $slug_kw ) ? 'pass' : 'fail' );
		}

		$res['kw_in_intro'] = $c['paragraphs']
			? self::r( self::has( $c['paragraphs'][0], $k ) ? 'pass' : 'fail' )
			: self::r( 'na' );

		$subs = array_values(
			array_filter(
				$c['headings'],
				static function ( array $h ): bool {
					return 2 === $h['level'] || 3 === $h['level'];
				}
			)
		);
		$hit  = false;
		foreach ( $subs as $h ) {
			if ( self::has( $h['text'], $k ) ) {
				$hit = true;
				break;
			}
		}
		$res['kw_in_subheading'] = $subs ? self::r( $hit ? 'pass' : 'fail' ) : self::r( 'na' );

		$alt_hit = false;
		foreach ( $c['alts'] as $alt ) {
			if ( self::has( $alt, $k ) ) {
				$alt_hit = true;
				break;
			}
		}
		$res['kw_in_image_alt'] = $c['alts'] ? self::r( $alt_hit ? 'pass' : 'fail' ) : self::r( 'na' );

		if ( $c['wordbased'] && $c['word_count'] > 0 ) {
			$occ     = mb_substr_count( SWPS_Editor_Text::lower( $c['text'] ), $k );
			$kwords  = max( 1, count( SWPS_Editor_Text::words( $kw ) ) );
			$percent = $occ * $kwords / $c['word_count'] * 100;
			$density = floor( $percent * 100 + 0.5 ) / 100;
			if ( 0 === $occ ) {
				$st = 'fail';
			} elseif ( $density < 0.5 ) {
				$st = 'warn';
			} elseif ( $density <= 3 ) {
				$st = 'pass';
			} else {
				$st = 'fail';
			}
			$res['kw_density'] = self::r( $st, $density );
		} else {
			$res['kw_density'] = self::r( 'na' );
		}

		$title_lower = SWPS_Editor_Text::lower( $c['title'] );
		$idx         = mb_strpos( $title_lower, $k );
		if ( false === $idx ) {
			$res['kw_title_position'] = self::r( 'na' );
		} else {
			$res['kw_title_position'] = self::r( $idx * 2 <= mb_strlen( $title_lower ) ? 'pass' : 'warn', $idx );
		}

		$res['kw_in_conclusion'] = count( $c['paragraphs'] ) >= 3
			? self::r( self::has( $c['paragraphs'][ count( $c['paragraphs'] ) - 1 ], $k ) ? 'pass' : 'warn' )
			: self::r( 'na' );

		if ( null === $row['used_elsewhere'] ) {
			$res['kw_unique'] = self::r( 'na' );
		} else {
			$res['kw_unique'] = self::r( $row['used_elsewhere'] ? 'fail' : 'pass' );
		}

		return $res;
	}
}
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit tests/unit/EditorCheckEngineTest.php`
Expected: PASS (13 tests). If `test_related_keywords_weigh_less_than_the_focus_keyword` fails on equal scores, the unrelated keyword's checks are not failing enough to move the integer score; change the unrelated phrase to something that also fails `kw_in_title` and `kw_in_slug` (it already does) and confirm `kw_density` and `kw_in_subheading` fail for it, then re-run.

- [ ] **Step 7: Static checks**

Run: `composer analyze && composer test`
Expected: PHPStan clean, full PHPUnit suite green.

- [ ] **Step 8: Commit**

```bash
git add includes/editor/class-editor-text.php includes/editor/class-editor-check-registry.php includes/editor/class-editor-check-engine.php tests/unit/EditorCheckEngineTest.php
git commit -m "Add pure PHP editor check engine, registry and text helpers"
```

---

### Task 3: JS instant tier and golden parity with PHP

**Files:**
- Create: `tests/fixtures/editor/inputs.json`, `tests/fixtures/editor/golden.json` (generated), `bin/gen-editor-golden.php`, `tests/unit/EditorGoldenTest.php`, `src/editor/analysis/text.js`, `src/editor/analysis/checks.js`, `src/editor/analysis/score.js`, `src/editor/__tests__/parity.test.js`

**Interfaces:**
- Consumes: the PHP engine from Task 2 as the source of truth.
- Produces: `runChecks(input)` and `normalize(input)` in `checks.js`, `scoreOutput(output, registry)` in `score.js`, both returning exactly the shapes documented in Task 2. `registry` is `SWPS_Editor_Check_Registry::for_js()` (`{checks, weights}`).

- [ ] **Step 1: Write the fixture inputs**

`tests/fixtures/editor/inputs.json`:

```json
[
	{
		"name": "good-post",
		"input": {
			"title": "How to make cold brew coffee at home",
			"slug": "cold-brew-coffee",
			"content_html": "<!-- wp:paragraph --><p>Cold brew coffee is easier to make at home than most people expect. It takes a coarse grind, cold water and patience.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>How to make cold brew coffee</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Add the grounds to a jar, pour in water and wait. However, the wait is the hard part. Therefore plan ahead and start the night before. Read our <a href=\"/grinding-guide\">grinding guide</a> or the <a href=\"https://en.wikipedia.org/wiki/Cold_brew\">cold brew article</a>.</p><!-- /wp:paragraph --><img src=\"jar.jpg\" alt=\"A jar of cold brew coffee\"><!-- wp:paragraph --><p>Strain it, dilute it and enjoy. Cold brew coffee keeps for a week in the fridge.</p><!-- /wp:paragraph -->",
			"meta_title": "",
			"meta_description": "Learn how to make cold brew coffee at home with a coarse grind and cold water.",
			"lang": "en_US",
			"host": "example.com",
			"preset": { "min_words": 20 },
			"keywords": [ { "keyword": "cold brew coffee", "used_elsewhere": false } ]
		}
	},
	{
		"name": "empty-keyword",
		"input": {
			"title": "Untitled draft",
			"slug": "",
			"content_html": "<p>A short first paragraph with no keyword chosen yet. It has two sentences. And a third one.</p>",
			"meta_title": "",
			"meta_description": "",
			"lang": "en",
			"host": "example.com",
			"keywords": [ { "keyword": "" } ]
		}
	},
	{
		"name": "japanese",
		"input": {
			"title": "テストの書き方",
			"slug": "test",
			"content_html": "<p>これはテストです。日本語の文章です。三つ目の文です。</p>",
			"meta_title": "",
			"meta_description": "テストの書き方を説明します。",
			"lang": "ja",
			"host": "example.com",
			"keywords": [ { "keyword": "テスト" } ]
		}
	},
	{
		"name": "blocks-and-shortcodes",
		"input": {
			"title": "Gallery tips & tricks",
			"slug": "gallery-tips",
			"content_html": "<!-- wp:paragraph {\"className\":\"gallery tips\"} --><p>Hello [gallery ids=\"1,2\"] world &amp; friends. These gallery tips help.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[contact-form]<!-- /wp:shortcode -->",
			"meta_title": "Gallery tips",
			"meta_description": "Short description about gallery tips for your site.",
			"lang": "en",
			"host": "example.com",
			"preset": { "min_words": 5 },
			"keywords": [ { "keyword": "gallery tips", "used_elsewhere": null } ]
		}
	},
	{
		"name": "accents",
		"input": {
			"title": "Café au lait at home",
			"slug": "cafe-au-lait",
			"content_html": "<p>Un café au lait est simple. Le café au lait se prépare avec du lait chaud et du café fort.</p><h2>Le café au lait parfait</h2><p>Servez le café au lait chaud.</p><p>Bonne dégustation avec ce café au lait.</p>",
			"meta_title": "",
			"meta_description": "Comment préparer un café au lait parfait à la maison, étape par étape et sans effort.",
			"lang": "fr_FR",
			"host": "example.com",
			"preset": { "min_words": 10 },
			"keywords": [ { "keyword": "café au lait", "used_elsewhere": false } ]
		}
	},
	{
		"name": "stuffed",
		"input": {
			"title": "Espresso espresso espresso",
			"slug": "espresso",
			"content_html": "<p>espresso espresso espresso espresso espresso espresso espresso espresso espresso espresso word word word word word word word word word word</p>",
			"meta_title": "",
			"meta_description": "espresso",
			"lang": "en",
			"host": "example.com",
			"preset": { "min_words": 10 },
			"keywords": [ { "keyword": "espresso" } ]
		}
	},
	{
		"name": "related-keywords",
		"input": {
			"title": "Cold brew vs iced coffee: what is the difference",
			"slug": "cold-brew-vs-iced-coffee",
			"content_html": "<p>Cold brew is steeped for hours. Iced coffee is brewed hot and then cooled. The difference is taste.</p><h2>Cold brew</h2><p>Smooth and low in acid.</p><h3>Iced coffee</h3><p>Bright and sharp, so it suits milk.</p>",
			"meta_title": "",
			"meta_description": "Cold brew and iced coffee taste different because of how they are made. Here is how to choose.",
			"lang": "en",
			"host": "example.com",
			"preset": { "min_words": 20 },
			"keywords": [
				{ "keyword": "cold brew", "used_elsewhere": false },
				{ "keyword": "iced coffee", "used_elsewhere": true },
				{ "keyword": "nitro coffee", "used_elsewhere": null }
			]
		}
	},
	{
		"name": "long-passive-no-headings",
		"content_repeat": {
			"text": "The report was written by the committee and it was reviewed by the board before the results were shared with everyone who had been waiting for an answer about the new policy. ",
			"times": 12
		},
		"input": {
			"title": "Committee report",
			"slug": "committee-report",
			"content_html": "",
			"meta_title": "",
			"meta_description": "A report about the committee.",
			"lang": "en",
			"host": "example.com",
			"keywords": [ { "keyword": "committee report" } ]
		}
	}
]
```

- [ ] **Step 2: Write the golden generator**

`bin/gen-editor-golden.php`:

```php
<?php
/**
 * Regenerates tests/fixtures/editor/golden.json from inputs.json using the PHP
 * check engine (the source of truth). The JS mirror is tested against this file.
 *
 * Usage: php bin/gen-editor-golden.php          (writes golden.json)
 *        php bin/gen-editor-golden.php --stdout (prints it, used by a test)
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

require_once __DIR__ . '/../includes/editor/class-editor-text.php';
require_once __DIR__ . '/../includes/editor/class-editor-check-registry.php';
require_once __DIR__ . '/../includes/editor/class-editor-check-engine.php';

$root   = dirname( __DIR__ ) . '/tests/fixtures/editor';
$inputs = json_decode( (string) file_get_contents( $root . '/inputs.json' ), true );
$cases  = array();

foreach ( $inputs as $case ) {
	$input = $case['input'];
	if ( isset( $case['content_repeat'] ) ) {
		$input['content_html'] = '<p>' . str_repeat( $case['content_repeat']['text'], (int) $case['content_repeat']['times'] ) . '</p>';
	}
	$output  = SWPS_Editor_Check_Engine::run( $input );
	$cases[] = array(
		'name'   => $case['name'],
		'input'  => $input,
		'output' => $output,
		'score'  => SWPS_Editor_Check_Engine::score( $output ),
	);
}

$json = json_encode(
	array(
		'registry' => SWPS_Editor_Check_Registry::for_js(),
		'cases'    => $cases,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . "\n";

if ( in_array( '--stdout', $argv, true ) ) {
	echo $json;
} else {
	file_put_contents( $root . '/golden.json', $json );
	echo "Wrote {$root}/golden.json\n";
}
```

- [ ] **Step 3: Write the failing golden freshness test**

`tests/unit/EditorGoldenTest.php`:

```php
<?php
/**
 * Pins tests/fixtures/editor/golden.json to the PHP engine so the JS mirror is
 * always compared against current PHP behaviour.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

final class EditorGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../fixtures/editor/golden.json';

	public function test_golden_file_matches_the_php_engine(): void {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/../../bin/gen-editor-golden.php' ) . ' --stdout';
		$out = shell_exec( $cmd );

		$this->assertIsString( $out );
		$this->assertFileExists( self::GOLDEN );
		$this->assertSame( file_get_contents( self::GOLDEN ), $out, 'golden.json is stale. Run: php bin/gen-editor-golden.php' );
	}

	public function test_golden_covers_the_review_focus_cases(): void {
		$golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
		$names  = array_column( $golden['cases'], 'name' );

		foreach ( array( 'good-post', 'empty-keyword', 'japanese', 'blocks-and-shortcodes', 'accents', 'stuffed', 'related-keywords', 'long-passive-no-headings' ) as $expected ) {
			$this->assertContains( $expected, $names );
		}
	}
}
```

Run: `vendor/bin/phpunit tests/unit/EditorGoldenTest.php`
Expected: FAIL (`golden.json` does not exist).

- [ ] **Step 4: Generate the golden file and sanity-check it by eye**

Run: `php bin/gen-editor-golden.php && vendor/bin/phpunit tests/unit/EditorGoldenTest.php`
Expected: PASS.

Then open `tests/fixtures/editor/golden.json` and confirm by reading, not by assuming:
- `good-post`: `kw_in_title`, `kw_in_slug`, `kw_in_intro`, `kw_in_subheading`, `kw_in_image_alt`, `kw_in_conclusion` are `pass`; `internal_links` and `external_links` are `pass`; `score.status` is `good`.
- `empty-keyword`: every keyword result is `na`; `score.status` is `no_keyword`.
- `japanese`: `content_length`, `sentence_length`, `transition_words` are `na`.
- `blocks-and-shortcodes`: the `plain` word count is small (no `wp:paragraph` text); `kw_in_intro` is `pass`.
- `long-passive-no-headings`: `sentence_length` is `fail`, `paragraph_length` is `warn`, `subheading_distribution` is `warn`.
If any of these is wrong, the engine has a bug: fix `includes/editor/class-editor-check-engine.php` (not the JSON) and regenerate.

- [ ] **Step 5: Write the failing Jest parity test**

`src/editor/__tests__/parity.test.js`:

```js
import golden from '../../../tests/fixtures/editor/golden.json';
import { runChecks } from '../analysis/checks';
import { scoreOutput } from '../analysis/score';

describe( 'JS instant tier matches the PHP engine', () => {
	golden.cases.forEach( ( c ) => {
		it( `output for ${ c.name }`, () => {
			expect( runChecks( c.input ) ).toEqual( c.output );
		} );
		it( `score for ${ c.name }`, () => {
			expect( scoreOutput( c.output, golden.registry ) ).toEqual( c.score );
		} );
	} );
} );

describe( 'instant tier behaviour', () => {
	it( 'passes keyword placement for the good post', () => {
		const good = golden.cases.find( ( c ) => c.name === 'good-post' );
		const r = runChecks( good.input ).keywords[ 0 ].results;
		expect( r.kw_in_title.status ).toBe( 'pass' );
		expect( r.kw_in_slug.status ).toBe( 'pass' );
	} );

	it( 'analyses a 5,000 word post quickly', () => {
		const input = {
			title: 'Big post',
			slug: 'big-post',
			content_html: '<p>' + 'Cold brew coffee is a good drink. '.repeat( 1000 ) + '</p>',
			keywords: [ { keyword: 'cold brew coffee' } ],
		};
		const start = Date.now();
		runChecks( input );
		expect( Date.now() - start ).toBeLessThan( 250 );
	} );
} );
```

Run: `npm run test:js`
Expected: FAIL ("Cannot find module '../analysis/checks'").

- [ ] **Step 6: Write the JS text helpers**

`src/editor/analysis/text.js`:

```js
// Mirror of includes/editor/class-editor-text.php. Keep the two identical:
// tests/fixtures/editor/golden.json pins them together.

const FOLD_FROM = 'àáâãäåçèéêëìíîïñòóôõöùúûüýÿ';
const FOLD_TO = 'aaaaaaceeeeiiiinooooouuuuyy';
const FOLD_MAP = {};
Array.from( FOLD_FROM ).forEach( ( ch, i ) => {
	FOLD_MAP[ ch ] = FOLD_TO[ i ];
} );

const ENTITIES = {
	amp: '&',
	nbsp: ' ',
	quot: '"',
	'#039': "'",
	'#8217': "'",
	lt: '<',
	gt: '>',
};

export const lower = ( s ) => s.toLowerCase();

export const fold = ( s ) =>
	Array.from( s )
		.map( ( ch ) => FOLD_MAP[ ch ] ?? ch )
		.join( '' );

export const slugify = ( s ) =>
	fold( lower( s ) )
		.replace( /[^\p{L}\p{N}]+/gu, '-' )
		.replace( /^-+|-+$/g, '' );

export const hostKey = ( h ) => lower( h.trim() ).replace( /^www\./, '' );

export const charLength = ( s ) => Array.from( s ).length;

export function plain( html ) {
	return html
		.replace( /<!--[\s\S]*?-->/g, ' ' )
		.replace( /<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ' )
		.replace(
			/<\/(?:p|div|h[1-6]|li|blockquote|tr|ul|ol)>|<br\s*\/?>/gi,
			'\n'
		)
		.replace( /\[\/?[a-z_][\w-]*(?:\s[^\]]*)?\]/gi, ' ' )
		.replace( /<[^>]*>/g, '' )
		.replace( /&(amp|nbsp|quot|#039|#8217|lt|gt);/g, ( m, k ) => ENTITIES[ k ] )
		.replace( /[ \t ]+/g, ' ' )
		.replace( / *\n[ \n]*/g, '\n' )
		.trim();
}

export const words = ( text ) =>
	text.split( /[^\p{L}\p{N}'’-]+/u ).filter( Boolean );

export function sentences( text ) {
	const out = [];
	text.split( '\n' ).forEach( ( line ) => {
		line.split( /(?<=[.!?])\s+/u ).forEach( ( part ) => {
			const t = part.trim();
			if ( t !== '' && words( t ).length > 0 ) {
				out.push( t );
			}
		} );
	} );
	return out;
}

export function paragraphs( html ) {
	const out = [];
	for ( const m of html.matchAll( /<p\b[^>]*>([\s\S]*?)<\/p>/gi ) ) {
		const t = plain( m[ 1 ] );
		if ( t !== '' ) {
			out.push( t );
		}
	}
	if ( ! out.length ) {
		plain( html )
			.split( '\n' )
			.forEach( ( line ) => {
				const t = line.trim();
				if ( t !== '' ) {
					out.push( t );
				}
			} );
	}
	return out;
}

export function headings( html ) {
	const out = [];
	for ( const m of html.matchAll( /<h([1-6])\b[^>]*>([\s\S]*?)<\/h\1>/gi ) ) {
		out.push( { level: Number( m[ 1 ] ), text: plain( m[ 2 ] ) } );
	}
	return out;
}

export function imageAlts( html ) {
	const out = [];
	for ( const m of html.matchAll( /<img\b[^>]*>/gi ) ) {
		const a = m[ 0 ].match(
			/(?<![\w-])alt\s*=\s*(?:"([^"]*)"|'([^']*)')/i
		);
		let alt = '';
		if ( a ) {
			alt = ( a[ 1 ] ?? '' ) !== '' ? a[ 1 ] : a[ 2 ] ?? '';
		}
		out.push( alt.trim() );
	}
	return out;
}

export function linkCounts( html, host ) {
	const own = hostKey( host );
	let internal = 0;
	let external = 0;
	for ( const m of html.matchAll(
		/<a\b[^>]*?(?<![\w-])href\s*=\s*(?:"([^"]*)"|'([^']*)')/gi
	) ) {
		const href = ( ( m[ 1 ] ?? '' ) !== '' ? m[ 1 ] : m[ 2 ] ?? '' ).trim();
		if ( href === '' || /^(#|mailto:|tel:|javascript:)/i.test( href ) ) {
			continue;
		}
		const h = href.match( /^(?:https?:)?\/\/([^/:?#]+)/i );
		if ( h ) {
			if ( hostKey( h[ 1 ] ) === own ) {
				internal++;
			} else {
				external++;
			}
		} else {
			internal++;
		}
	}
	return { internal, external };
}
```

- [ ] **Step 7: Write the JS engine**

`src/editor/analysis/checks.js`:

```js
// Mirror of includes/editor/class-editor-check-engine.php.
import * as T from './text';

const TRANSITIONS = [
	'however',
	'therefore',
	'moreover',
	'furthermore',
	'consequently',
	'meanwhile',
	'additionally',
	'similarly',
	'for example',
	'for instance',
	'in addition',
	'as a result',
	'in conclusion',
	'on the other hand',
	'in contrast',
	'finally',
	'first',
	'second',
	'third',
	'next',
	'then',
	'also',
	'because',
	'although',
	'while',
	'since',
	'instead',
	'otherwise',
	'nevertheless',
	'thus',
	'hence',
	'besides',
	'likewise',
	'specifically',
	'in fact',
	'in summary',
];
const TRANSITION_RE = new RegExp( '\\b(?:' + TRANSITIONS.join( '|' ) + ')\\b', 'i' );
const PASSIVE_RE = /\b(?:is|are|was|were|be|been|being)\s+(?:\w+ed|\w+en)\b/i;

const KEYWORD_IDS = [
	'kw_in_title',
	'kw_in_description',
	'kw_in_slug',
	'kw_in_intro',
	'kw_in_subheading',
	'kw_in_image_alt',
	'kw_density',
	'kw_title_position',
	'kw_in_conclusion',
	'kw_unique',
];

const rnd = ( x ) => Math.floor( x + 0.5 );
const r = ( status, value = null ) => ( { status, value } );
const has = ( haystack, needleLower ) => T.lower( haystack ).includes( needleLower );

export function normalize( input ) {
	const preset = {
		min_words: 300,
		title_min: 30,
		title_max: 60,
		desc_min: 70,
		desc_max: 160,
		...( input.preset || {} ),
	};
	Object.keys( preset ).forEach( ( k ) => {
		preset[ k ] = Math.trunc( Number( preset[ k ] ) ) || 0;
	} );

	let keywords = ( input.keywords || [] ).map( ( row ) => ( {
		keyword: String( row.keyword ?? '' ).trim(),
		used_elsewhere:
			row.used_elsewhere === undefined || row.used_elsewhere === null
				? null
				: Boolean( row.used_elsewhere ),
	} ) );
	if ( ! keywords.length ) {
		keywords = [ { keyword: '', used_elsewhere: null } ];
	}

	return {
		title: String( input.title ?? '' ),
		slug: String( input.slug ?? '' ),
		content_html: String( input.content_html ?? '' ),
		meta_title: String( input.meta_title ?? '' ),
		meta_description: String( input.meta_description ?? '' ),
		lang: String( input.lang ?? '' ) || 'en',
		host: String( input.host ?? '' ),
		preset,
		keywords,
	};
}

function context( inp ) {
	const html = inp.content_html;
	const text = T.plain( html );
	const sentences = T.sentences( text );
	return {
		title: inp.meta_title.trim() !== '' ? inp.meta_title : inp.title,
		text,
		wordCount: T.words( text ).length,
		wordbased: ! /^(ja|zh|ko|th)/i.test( inp.lang ),
		english: /^en/i.test( inp.lang ),
		paragraphs: T.paragraphs( html ),
		headings: T.headings( html ),
		alts: T.imageAlts( html ),
		links: T.linkCounts( html, inp.host ),
		sentences,
		swords: sentences.map( T.words ),
	};
}

function globalChecks( inp, c ) {
	const p = inp.preset;
	const g = {};

	const tl = T.charLength( c.title.trim() );
	g.title_length =
		tl === 0
			? r( 'fail', 0 )
			: r( tl >= p.title_min && tl <= p.title_max ? 'pass' : 'warn', tl );

	const dl = T.charLength( inp.meta_description.trim() );
	g.description_length =
		dl === 0
			? r( 'fail', 0 )
			: r( dl >= p.desc_min && dl <= p.desc_max ? 'pass' : 'warn', dl );

	if ( c.wordbased ) {
		const wc = c.wordCount;
		g.content_length = r(
			wc >= p.min_words ? 'pass' : wc * 2 >= p.min_words ? 'warn' : 'fail',
			wc
		);
	} else {
		g.content_length = r( 'na' );
	}

	g.internal_links = r( c.links.internal > 0 ? 'pass' : 'warn', c.links.internal );
	g.external_links = r( c.links.external > 0 ? 'pass' : 'warn', c.links.external );

	if ( c.wordbased && c.wordCount >= 300 ) {
		let max = 0;
		inp.content_html
			.split( /<h[1-6]\b[^>]*>[\s\S]*?<\/h[1-6]>/gi )
			.forEach( ( seg ) => {
				max = Math.max( max, T.words( T.plain( seg ) ).length );
			} );
		g.subheading_distribution = r( max > 300 ? 'warn' : 'pass', max );
	} else {
		g.subheading_distribution = r( 'na' );
	}

	if ( c.alts.length ) {
		const missing = c.alts.filter( ( a ) => a === '' ).length;
		g.image_alt_missing = r( missing === 0 ? 'pass' : 'warn', missing );
	} else {
		g.image_alt_missing = r( 'na' );
	}

	const n = c.sentences.length;
	const ready = c.wordbased && n >= 3;

	if ( ready ) {
		const long = c.swords.filter( ( w ) => w.length > 20 ).length;
		const pct = rnd( ( long / n ) * 100 );
		g.sentence_length = r( pct <= 25 ? 'pass' : pct <= 35 ? 'warn' : 'fail', pct );
	} else {
		g.sentence_length = r( 'na' );
	}

	if ( c.wordbased && c.paragraphs.length ) {
		const long = c.paragraphs.filter( ( para ) => T.words( para ).length > 150 ).length;
		g.paragraph_length = r( long === 0 ? 'pass' : 'warn', long );
	} else {
		g.paragraph_length = r( 'na' );
	}

	if ( ready && c.english ) {
		let hits = 0;
		let passive = 0;
		c.sentences.forEach( ( s ) => {
			if ( TRANSITION_RE.test( s ) ) {
				hits++;
			}
			if ( PASSIVE_RE.test( s ) ) {
				passive++;
			}
		} );
		const tp = rnd( ( hits / n ) * 100 );
		const pp = rnd( ( passive / n ) * 100 );
		g.transition_words = r( tp >= 20 ? 'pass' : tp >= 10 ? 'warn' : 'fail', tp );
		g.passive_voice = r( pp <= 10 ? 'pass' : pp <= 15 ? 'warn' : 'fail', pp );
	} else {
		g.transition_words = r( 'na' );
		g.passive_voice = r( 'na' );
	}

	if ( ready ) {
		let run = 1;
		let best = 1;
		let prev = null;
		c.swords.forEach( ( w ) => {
			const first = T.lower( w[ 0 ] );
			run = first === prev ? run + 1 : 1;
			best = Math.max( best, run );
			prev = first;
		} );
		g.consecutive_starters = r( best >= 3 ? 'warn' : 'pass', best );
	} else {
		g.consecutive_starters = r( 'na' );
	}

	return g;
}

function keywordChecks( row, inp, c ) {
	const kw = row.keyword;
	if ( kw === '' ) {
		const na = {};
		KEYWORD_IDS.forEach( ( id ) => {
			na[ id ] = r( 'na' );
		} );
		return na;
	}

	const k = T.lower( kw );
	const res = {};

	res.kw_in_title = r( has( c.title, k ) ? 'pass' : 'fail' );

	const desc = inp.meta_description.trim();
	res.kw_in_description = r( desc !== '' && has( desc, k ) ? 'pass' : 'fail' );

	const slugKw = T.slugify( kw );
	if ( slugKw === '' ) {
		res.kw_in_slug = r( 'na' );
	} else {
		const slug = T.fold( T.lower( inp.slug ) );
		res.kw_in_slug = r( slug.includes( slugKw ) ? 'pass' : 'fail' );
	}

	res.kw_in_intro = c.paragraphs.length
		? r( has( c.paragraphs[ 0 ], k ) ? 'pass' : 'fail' )
		: r( 'na' );

	const subs = c.headings.filter( ( h ) => h.level === 2 || h.level === 3 );
	res.kw_in_subheading = subs.length
		? r( subs.some( ( h ) => has( h.text, k ) ) ? 'pass' : 'fail' )
		: r( 'na' );

	res.kw_in_image_alt = c.alts.length
		? r( c.alts.some( ( a ) => has( a, k ) ) ? 'pass' : 'fail' )
		: r( 'na' );

	if ( c.wordbased && c.wordCount > 0 ) {
		const occ = T.lower( c.text ).split( k ).length - 1;
		const kwords = Math.max( 1, T.words( kw ).length );
		const percent = ( ( occ * kwords ) / c.wordCount ) * 100;
		const density = Math.floor( percent * 100 + 0.5 ) / 100;
		let st;
		if ( occ === 0 ) {
			st = 'fail';
		} else if ( density < 0.5 ) {
			st = 'warn';
		} else if ( density <= 3 ) {
			st = 'pass';
		} else {
			st = 'fail';
		}
		res.kw_density = r( st, density );
	} else {
		res.kw_density = r( 'na' );
	}

	const titleLower = T.lower( c.title );
	const at = titleLower.indexOf( k );
	if ( at < 0 ) {
		res.kw_title_position = r( 'na' );
	} else {
		const idx = T.charLength( titleLower.slice( 0, at ) );
		res.kw_title_position = r(
			idx * 2 <= T.charLength( titleLower ) ? 'pass' : 'warn',
			idx
		);
	}

	res.kw_in_conclusion =
		c.paragraphs.length >= 3
			? r( has( c.paragraphs[ c.paragraphs.length - 1 ], k ) ? 'pass' : 'warn' )
			: r( 'na' );

	res.kw_unique =
		row.used_elsewhere === null
			? r( 'na' )
			: r( row.used_elsewhere ? 'fail' : 'pass' );

	return res;
}

export function runChecks( input ) {
	const inp = normalize( input );
	const c = context( inp );
	return {
		global: globalChecks( inp, c ),
		keywords: inp.keywords.map( ( row ) => ( {
			keyword: row.keyword,
			results: keywordChecks( row, inp, c ),
		} ) ),
	};
}
```

`src/editor/analysis/score.js`:

```js
// Mirror of SWPS_Editor_Check_Engine::score().
const rnd = ( x ) => Math.floor( x + 0.5 );

export function scoreOutput( output, registry ) {
	const sum = {};
	const den = {};
	const add = ( dim, w, status ) => {
		if ( status === 'na' ) {
			return;
		}
		const v = status === 'pass' ? 1 : status === 'warn' ? 0.5 : 0;
		sum[ dim ] = ( sum[ dim ] ?? 0 ) + w * v;
		den[ dim ] = ( den[ dim ] ?? 0 ) + w;
	};

	registry.checks.forEach( ( def ) => {
		if ( def.scope === 'global' ) {
			add( def.dimension, 1, output.global[ def.id ]?.status ?? 'na' );
			return;
		}
		output.keywords.forEach( ( kw, i ) => {
			add( def.dimension, i === 0 ? 1 : 0.25, kw.results[ def.id ]?.status ?? 'na' );
		} );
	} );

	const dims = Object.keys( registry.weights );
	const dimScore = {};
	dims.forEach( ( dim ) => {
		if ( den[ dim ] > 0 ) {
			dimScore[ dim ] = sum[ dim ] / den[ dim ];
		}
	} );

	const weighted = ( subset ) => {
		let num = 0;
		let d = 0;
		dims.forEach( ( dim ) => {
			if ( dimScore[ dim ] === undefined ) {
				return;
			}
			if ( subset === 'seo' && dim === 'readability' ) {
				return;
			}
			if ( subset === 'readability' && dim !== 'readability' ) {
				return;
			}
			const w = registry.weights[ dim ];
			num += w * dimScore[ dim ];
			d += w;
		} );
		return d > 0 ? rnd( ( num / d ) * 100 ) : null;
	};

	const overall = weighted( 'all' ) ?? 0;
	const hasKeyword = ( output.keywords[ 0 ]?.keyword ?? '' ) !== '';
	let status = 'poor';
	if ( ! hasKeyword ) {
		status = 'no_keyword';
	} else if ( overall >= 75 ) {
		status = 'good';
	} else if ( overall >= 50 ) {
		status = 'needs_work';
	}

	return {
		overall,
		seo: weighted( 'seo' ),
		readability: weighted( 'readability' ),
		status,
	};
}
```

- [ ] **Step 8: Run Jest and fix any divergence in the JS (never in the golden)**

Run: `npm run test:js`
Expected: PASS for every case and the performance test. If a case differs, the toEqual diff names the exact check id; fix `text.js` or `checks.js` so JS matches PHP. If the PHP result is the wrong behaviour, fix the PHP engine, regenerate the golden (`php bin/gen-editor-golden.php`), and re-run both suites.

- [ ] **Step 9: Build and commit**

```bash
npm run build
vendor/bin/phpunit
git add bin/gen-editor-golden.php tests/fixtures/editor tests/unit/EditorGoldenTest.php src/editor/analysis src/editor/__tests__ admin/editor
git commit -m "Add JS instant checks with golden parity against the PHP engine"
```

---

### Task 4: Post meta in REST, related keywords and script data

**Files:**
- Create: `includes/editor/class-editor-input.php`, `tests/unit/EditorInputTest.php`
- Modify: `includes/editor/class-editor-sidebar.php`, `includes/class-meta-editor.php` (one signature), `stratawp-seo.php` (requires)

**Interfaces:**
- Consumes: `SWPS_Editor_Check_Registry::for_js()` (Task 2).
- Produces:
  - `SWPS_Editor_Input::sanitize_related(mixed $value): string[]` (trim, strip tags, dedupe case-insensitively, max 4), `::secondary_string(array $related): string`, const `META_RELATED = '_swps_related_keywords'`, const `MAX_RELATED = 4`.
  - `SWPS_Editor_Input::preset(string $post_type): array`, `::keyword_used_elsewhere(string $keyword, int $post_id): bool`, `::for_post(WP_Post $post): array` (engine input built from the saved post).
  - `SWPS_Meta_Editor::get_enabled_post_types(): array` becomes `public static`.
  - Registered REST meta: `_swps_meta_title`, `_swps_meta_description`, `_swps_focus_keyword`, `_swps_secondary_keywords`, `_swps_related_keywords` (string[]), `_swps_canonical_url`, `_swps_robots`, `_swps_breadcrumb_title`, `_swps_social_title`, `_swps_social_description`, `_swps_social_image`, `_swps_sitemap_exclude` (int), `_swps_sitemap_priority`, `_swps_sitemap_changefreq`, and read-only `_swps_seo_score_value` (int).
  - `window.swpsEditor` gains `registry`, `preset`, `host`, `lang`, `siteTitle`, `aeoEnabled`.

- [ ] **Step 1: Write the failing test for the pure helpers**

`tests/unit/EditorInputTest.php`:

```php
<?php
/**
 * Tests for the pure helpers in SWPS_Editor_Input.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/editor/class-editor-input.php';

final class EditorInputTest extends TestCase {

	public function test_sanitize_related_trims_dedupes_and_caps(): void {
		$out = SWPS_Editor_Input::sanitize_related( array( '  Cold Brew ', 'cold brew', '', '<b>iced</b> coffee', 'a', 'b', 'c', 'd' ) );

		$this->assertSame( array( 'Cold Brew', 'iced coffee', 'a', 'b' ), $out );
	}

	public function test_sanitize_related_accepts_garbage(): void {
		$this->assertSame( array(), SWPS_Editor_Input::sanitize_related( '' ) );
		$this->assertSame( array(), SWPS_Editor_Input::sanitize_related( null ) );
		$this->assertSame( array( 'one' ), SWPS_Editor_Input::sanitize_related( 'one' ) );
	}

	public function test_secondary_string_is_comma_separated(): void {
		$this->assertSame( 'a, b', SWPS_Editor_Input::secondary_string( array( 'a', 'b' ) ) );
		$this->assertSame( '', SWPS_Editor_Input::secondary_string( array() ) );
	}
}
```

Run: `vendor/bin/phpunit tests/unit/EditorInputTest.php`
Expected: FAIL (missing class file).

- [ ] **Step 2: Write the input class**

`includes/editor/class-editor-input.php`:

```php
<?php
/**
 * Builds engine input from WordPress state and holds the small pure helpers
 * around keywords and presets.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Input {

	public const META_RELATED = '_swps_related_keywords';
	public const MAX_RELATED  = 4;

	/**
	 * @param mixed $value Raw meta value.
	 * @return string[]
	 */
	public static function sanitize_related( $value ): array {
		$out = array();
		foreach ( (array) $value as $kw ) {
			$kw = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $kw ) ) );
			if ( '' === $kw ) {
				continue;
			}
			$key = mb_strtolower( $kw );
			if ( isset( $out[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = mb_substr( $kw, 0, 80 );
			if ( count( $out ) >= self::MAX_RELATED ) {
				break;
			}
		}
		return array_values( $out );
	}

	/**
	 * The older _swps_secondary_keywords meta is a comma separated string used
	 * by the AI generation path. Related keywords are mirrored into it so that
	 * path keeps working.
	 *
	 * @param string[] $related Related keywords.
	 */
	public static function secondary_string( array $related ): string {
		return implode( ', ', $related );
	}

	/**
	 * Thresholds for a post type. Posts keep honouring the existing
	 * swps_seo_score_content_min option.
	 *
	 * @return array<string,int>
	 */
	public static function preset( string $post_type ): array {
		$base = array(
			'min_words' => 300,
			'title_min' => 30,
			'title_max' => 60,
			'desc_min'  => 70,
			'desc_max'  => 160,
		);
		$per  = array(
			'post'    => array( 'min_words' => (int) get_option( 'swps_seo_score_content_min', 300 ) ),
			'page'    => array( 'min_words' => 150 ),
			'product' => array( 'min_words' => 100 ),
		);

		$preset = array_merge( $base, $per[ $post_type ] ?? array() );

		/**
		 * Filters the editor check thresholds for a post type.
		 *
		 * @param array<string,int> $preset    Thresholds.
		 * @param string            $post_type Post type slug.
		 */
		return array_map( 'intval', (array) apply_filters( 'swps_editor_preset', $preset, $post_type ) );
	}

	/**
	 * True when another post already uses this as its focus keyword.
	 */
	public static function keyword_used_elsewhere( string $keyword, int $post_id ): bool {
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return false;
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'post__not_in'   => array( $post_id ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_swps_focus_keyword',
						'value' => $keyword,
					),
				),
			)
		);
		return ! empty( $query->posts );
	}

	/**
	 * Engine input for a saved post (used for server-side scoring).
	 *
	 * @return array<string,mixed>
	 */
	public static function for_post( WP_Post $post ): array {
		$focus    = (string) get_post_meta( $post->ID, '_swps_focus_keyword', true );
		$related  = self::sanitize_related( get_post_meta( $post->ID, self::META_RELATED, true ) );
		$keywords = array(
			array(
				'keyword'        => $focus,
				'used_elsewhere' => '' === $focus ? null : self::keyword_used_elsewhere( $focus, $post->ID ),
			),
		);
		foreach ( $related as $kw ) {
			$keywords[] = array(
				'keyword'        => $kw,
				'used_elsewhere' => self::keyword_used_elsewhere( $kw, $post->ID ),
			);
		}

		return array(
			'title'            => $post->post_title,
			'slug'             => $post->post_name,
			'content_html'     => $post->post_content,
			'meta_title'       => (string) get_post_meta( $post->ID, '_swps_meta_title', true ),
			'meta_description' => (string) get_post_meta( $post->ID, '_swps_meta_description', true ),
			'lang'             => get_locale(),
			'host'             => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'preset'           => self::preset( $post->post_type ),
			'keywords'         => $keywords,
		);
	}
}
```

Run: `vendor/bin/phpunit tests/unit/EditorInputTest.php`
Expected: PASS (3 tests).

- [ ] **Step 3: Share the enabled post types**

In `includes/class-meta-editor.php` change the signature `private function get_enabled_post_types(): array {` to `public static function get_enabled_post_types(): array {`. Existing `$this->get_enabled_post_types()` calls keep working (PHP allows calling a static method through `$this`).

- [ ] **Step 4: Require the new classes**

In `stratawp-seo.php`, directly after the `class-editor-sidebar.php` require added in Task 1, add:

```php
require_once SWPS_PLUGIN_DIR . 'includes/editor/class-editor-text.php';
require_once SWPS_PLUGIN_DIR . 'includes/editor/class-editor-check-registry.php';
require_once SWPS_PLUGIN_DIR . 'includes/editor/class-editor-check-engine.php';
require_once SWPS_PLUGIN_DIR . 'includes/editor/class-editor-input.php';
```

- [ ] **Step 5: Register the meta, mirror related keywords, extend the script data**

In `includes/editor/class-editor-sidebar.php`, add these lines at the end of `__construct()`:

```php
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'added_post_meta', array( $this, 'mirror_related' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'mirror_related' ), 10, 4 );
```

Add these methods to the class:

```php
	public static function sanitize_robots( $value ): string {
		$allowed = array( '', 'noindex, follow', 'index, nofollow', 'noindex, nofollow' );
		$value   = (string) $value;
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Expose the SEO fields to the block editor through REST. Only registered
	 * when the sidebar is on, so nothing changes for sites that have not
	 * opted in.
	 */
	public function register_meta(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$auth = static function ( $allowed, $meta_key, $post_id ): bool {
			return current_user_can( 'edit_post', (int) $post_id );
		};

		$strings = array(
			'_swps_meta_title'         => 'sanitize_text_field',
			'_swps_meta_description'   => 'sanitize_textarea_field',
			'_swps_focus_keyword'      => 'sanitize_text_field',
			'_swps_secondary_keywords' => 'sanitize_text_field',
			'_swps_canonical_url'      => 'esc_url_raw',
			'_swps_robots'             => array( __CLASS__, 'sanitize_robots' ),
			'_swps_breadcrumb_title'   => 'sanitize_text_field',
			'_swps_social_title'       => 'sanitize_text_field',
			'_swps_social_description' => 'sanitize_textarea_field',
			'_swps_social_image'       => 'esc_url_raw',
			'_swps_sitemap_priority'   => 'sanitize_text_field',
			'_swps_sitemap_changefreq' => 'sanitize_text_field',
		);

		foreach ( SWPS_Meta_Editor::get_enabled_post_types() as $type ) {
			foreach ( $strings as $key => $sanitize ) {
				register_post_meta(
					$type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => $sanitize,
						'auth_callback'     => $auth,
					)
				);
			}

			register_post_meta(
				$type,
				'_swps_sitemap_exclude',
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => $auth,
				)
			);

			register_post_meta(
				$type,
				SWPS_Editor_Input::META_RELATED,
				array(
					'type'              => 'array',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'maxItems' => SWPS_Editor_Input::MAX_RELATED,
						),
					),
					'sanitize_callback' => array( 'SWPS_Editor_Input', 'sanitize_related' ),
					'auth_callback'     => $auth,
				)
			);

			// Readable in the editor (for the old versus new score note), never writable over REST.
			register_post_meta(
				$type,
				'_swps_seo_score_value',
				array(
					'type'          => 'integer',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => '__return_false',
				)
			);
		}
	}

	/**
	 * Keep the legacy comma separated secondary keywords in step with the new
	 * related keywords list so the AI generation path sees the same values.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public function mirror_related( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( SWPS_Editor_Input::META_RELATED !== $meta_key ) {
			return;
		}
		update_post_meta(
			(int) $post_id,
			'_swps_secondary_keywords',
			SWPS_Editor_Input::secondary_string( SWPS_Editor_Input::sanitize_related( $meta_value ) )
		);
	}
```

Also restrict the sidebar to the post types the classic metabox serves. In `enqueue()`, directly after the `$screen` guard added in Task 1, add:

```php
		if ( ! in_array( $screen->post_type, SWPS_Meta_Editor::get_enabled_post_types(), true ) ) {
			return;
		}
```

Replace the body of `script_data()` with:

```php
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = $screen && $screen->post_type ? $screen->post_type : 'post';

		return array(
			'enabled'    => true,
			'toggleUrl'  => $this->toggle_url( false ),
			'registry'   => SWPS_Editor_Check_Registry::for_js(),
			'preset'     => SWPS_Editor_Input::preset( $post_type ),
			'host'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'lang'       => get_locale(),
			'siteTitle'  => get_bloginfo( 'name' ),
			'aeoEnabled' => (bool) get_option( SWPS_AEO_Scorer::OPTION_COVERAGE_ENABLED ),
		);
```

- [ ] **Step 6: Verify**

Run:

```bash
php -l includes/editor/class-editor-sidebar.php && php -l includes/editor/class-editor-input.php
composer analyze && composer test
```

Expected: clean. Manual: with `wp option update swps_editor_sidebar 1`, run `wp eval 'echo json_encode(get_registered_meta_keys("post"));' | grep -c swps_` and confirm the `_swps_*` keys are listed; open a post in the block editor and in the browser console run `wp.data.select('core').getEditedEntityRecord('postType','post',wp.data.select('core/editor').getCurrentPostId()).meta` and confirm it contains `_swps_focus_keyword` and `_swps_related_keywords`.

- [ ] **Step 7: Commit**

```bash
git add includes/editor/class-editor-input.php includes/editor/class-editor-sidebar.php includes/class-meta-editor.php stratawp-seo.php tests/unit/EditorInputTest.php
git commit -m "Expose SEO post meta to the block editor and add related keywords"
```

---

### Task 5: Sidebar core UI (score, search preview, keywords, checks)

**Files:**
- Create: `src/editor/hooks/usePostMeta.js`, `src/editor/hooks/useKeywords.js`, `src/editor/hooks/useAnalysis.js`, `src/editor/analysis/group.js`, `src/editor/__tests__/group.test.js`, `src/editor/components/ScoreBadge.js`, `src/editor/components/SearchPreview.js`, `src/editor/components/KeywordsPanel.js`, `src/editor/components/ChecksPanel.js`
- Modify: `src/editor/components/Sidebar.js`, `src/editor/style.scss`

**Interfaces:**
- Consumes: `runChecks`, `scoreOutput` (Task 3); `window.swpsEditor.registry|preset|host|lang` (Task 4).
- Produces:
  - `usePostMeta(): { meta, setKey(key, value), postType }`
  - `useKeywords(): { focus, related, setFocus(v), setRelated(list) }`
  - `useAnalysis(usedElsewhere = {}): { input, output, score, error }` (`usedElsewhere` maps keyword string to boolean; recomputed 400 ms after the last edit)
  - `groupResults(output, registry, group, keywordIndex = 0): { problems, improvements, good, notAnalyzed }` where each row is `{ def, res }`
  - `<ChecksPanel output registry group keywordIndex renderAction? />` where `renderAction(def, res, keyword)` may return a node shown beside a row (used by Task 8)
  - `<ScoreBadge score legacy? />`

- [ ] **Step 1: Write the failing test for result grouping**

`src/editor/__tests__/group.test.js`:

```js
import golden from '../../../tests/fixtures/editor/golden.json';
import { groupResults } from '../analysis/group';

const byName = ( name ) => golden.cases.find( ( c ) => c.name === name );

describe( 'groupResults', () => {
	it( 'puts passing checks under good and failing under problems', () => {
		const c = byName( 'long-passive-no-headings' );
		const g = groupResults( c.output, golden.registry, 'readability' );
		expect( g.problems.map( ( r ) => r.def.id ) ).toContain( 'sentence_length' );
		expect( g.improvements.map( ( r ) => r.def.id ) ).toContain( 'paragraph_length' );
	} );

	it( 'keeps every keyword check under notAnalyzed when no keyword is set', () => {
		const c = byName( 'empty-keyword' );
		const g = groupResults( c.output, golden.registry, 'seo' );
		const keywordIds = golden.registry.checks
			.filter( ( d ) => d.scope === 'keyword' && d.group === 'seo' )
			.map( ( d ) => d.id );
		expect( g.notAnalyzed.map( ( r ) => r.def.id ) ).toEqual(
			expect.arrayContaining( keywordIds )
		);
		expect( g.problems.some( ( r ) => r.def.scope === 'keyword' ) ).toBe( false );
	} );

	it( 'reads results for the requested keyword index', () => {
		const c = byName( 'related-keywords' );
		const first = groupResults( c.output, golden.registry, 'seo', 0 );
		const third = groupResults( c.output, golden.registry, 'seo', 2 );
		expect( first.good.length ).not.toBe( third.good.length );
	} );
} );
```

Run: `npm run test:js`
Expected: FAIL (cannot find `../analysis/group`).

- [ ] **Step 2: Write the grouping helper**

`src/editor/analysis/group.js`:

```js
/**
 * Split one group of results (seo or readability) into the four buckets the
 * panels show. Global checks are always included; keyword checks use the
 * keyword at keywordIndex.
 */
export function groupResults( output, registry, group, keywordIndex = 0 ) {
	const rows = [];
	registry.checks.forEach( ( def ) => {
		if ( def.group !== group ) {
			return;
		}
		const res =
			def.scope === 'global'
				? output.global[ def.id ]
				: output.keywords[ keywordIndex ]?.results[ def.id ];
		if ( res ) {
			rows.push( { def, res } );
		}
	} );
	const pick = ( ...statuses ) =>
		rows.filter( ( row ) => statuses.includes( row.res.status ) );
	return {
		problems: pick( 'fail' ),
		improvements: pick( 'warn' ),
		good: pick( 'pass' ),
		notAnalyzed: pick( 'na', 'error' ),
	};
}
```

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 3: Write the data hooks**

`src/editor/hooks/usePostMeta.js`:

```js
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';

export function usePostMeta() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const setKey = ( key, value ) => setMeta( { ...( meta || {} ), [ key ]: value } );
	return { meta: meta || {}, setKey, postType };
}
```

`src/editor/hooks/useKeywords.js`:

```js
import { usePostMeta } from './usePostMeta';

export function useKeywords() {
	const { meta, setKey } = usePostMeta();
	const related = Array.isArray( meta._swps_related_keywords )
		? meta._swps_related_keywords
		: [];
	return {
		focus: meta._swps_focus_keyword || '',
		related,
		setFocus: ( value ) => setKey( '_swps_focus_keyword', value ),
		setRelated: ( list ) => setKey( '_swps_related_keywords', list.slice( 0, 4 ) ),
	};
}
```

`src/editor/hooks/useAnalysis.js`:

```js
import { useEffect, useState } from '@wordpress/element';
import { useRegistry, useSelect } from '@wordpress/data';
import { runChecks } from '../analysis/checks';
import { scoreOutput } from '../analysis/score';
import { usePostMeta } from './usePostMeta';
import { useKeywords } from './useKeywords';

const DEBOUNCE_MS = 400;

/**
 * Instant tier. Re-runs the pure checks 400 ms after the last edit. Reads the
 * editor state, never the saved copy, so unsaved and auto-draft posts work.
 */
export function useAnalysis( usedElsewhere = {} ) {
	const registry = useRegistry();
	const { meta } = usePostMeta();
	const { focus, related } = useKeywords();
	const blocks = useSelect(
		( select ) => select( 'core/block-editor' ).getBlocks(),
		[]
	);
	const { title, slug } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			title: editor.getEditedPostAttribute( 'title' ) || '',
			slug: editor.getEditedPostAttribute( 'slug' ) || '',
		};
	}, [] );

	const [ state, setState ] = useState( {
		input: null,
		output: null,
		score: null,
		error: null,
	} );

	const signature = JSON.stringify( [
		title,
		slug,
		meta._swps_meta_title,
		meta._swps_meta_description,
		focus,
		related,
		usedElsewhere,
	] );

	useEffect( () => {
		const timer = setTimeout( () => {
			const data = window.swpsEditor || {};
			const input = {
				title,
				slug,
				content_html: registry.select( 'core/editor' ).getEditedPostContent(),
				meta_title: meta._swps_meta_title || '',
				meta_description: meta._swps_meta_description || '',
				lang: data.lang || 'en',
				host: data.host || '',
				preset: data.preset || {},
				keywords: [ focus, ...related ].map( ( keyword ) => ( {
					keyword,
					used_elsewhere: Object.prototype.hasOwnProperty.call( usedElsewhere, keyword )
						? usedElsewhere[ keyword ]
						: null,
				} ) ),
			};
			try {
				const output = runChecks( input );
				setState( {
					input,
					output,
					score: scoreOutput( output, data.registry ),
					error: null,
				} );
			} catch ( error ) {
				setState( { input, output: null, score: null, error } );
			}
		}, DEBOUNCE_MS );
		return () => clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ blocks, signature ] );

	return state;
}
```

- [ ] **Step 4: Write the score badge and checks panel**

`src/editor/components/ScoreBadge.js`:

```js
import { __ } from '@wordpress/i18n';

const LABELS = {
	good: __( 'Good', 'stratawp-seo' ),
	needs_work: __( 'Needs work', 'stratawp-seo' ),
	poor: __( 'Poor', 'stratawp-seo' ),
	no_keyword: __( 'Add a focus keyword', 'stratawp-seo' ),
};

export default function ScoreBadge( { score, legacy, aeo = null } ) {
	if ( ! score ) {
		return (
			<p className="swps-editor__muted">{ __( 'Analyzing', 'stratawp-seo' ) }</p>
		);
	}
	const showLegacy =
		Number.isFinite( legacy ) && legacy > 0 && Math.abs( legacy - score.overall ) > 10;
	return (
		<div className={ `swps-score swps-score--${ score.status }` } data-testid="swps-score">
			<span className="swps-score__num">{ score.overall }</span>
			<span className="swps-score__label">{ LABELS[ score.status ] }</span>
			<span className="swps-score__sub">
				{ score.seo !== null && (
					<span>{ __( 'SEO', 'stratawp-seo' ) } { score.seo }</span>
				) }
				{ score.readability !== null && (
					<span>{ __( 'Readability', 'stratawp-seo' ) } { score.readability }</span>
				) }
				{ aeo !== null && (
					<span>{ __( 'AI visibility', 'stratawp-seo' ) } { aeo }</span>
				) }
			</span>
			{ showLegacy && (
				<span className="swps-score__legacy">
					{ __( 'Previous score', 'stratawp-seo' ) } { legacy }
				</span>
			) }
		</div>
	);
}
```

`src/editor/components/ChecksPanel.js`:

```js
import { __ } from '@wordpress/i18n';
import { groupResults } from '../analysis/group';

const GLYPH = { fail: '✕', warn: '!', pass: '✓', na: '-', error: '-' };

function Row( { row, keyword, renderAction } ) {
	const { def, res } = row;
	return (
		<li className={ `swps-check swps-check--${ res.status }` } data-check={ def.id }>
			<span className="swps-check__glyph" aria-hidden="true">
				{ GLYPH[ res.status ] }
			</span>
			<span className="swps-check__body">
				<span className="swps-check__label">
					{ def.label }
					{ res.value !== null && res.value !== undefined && (
						<span className="swps-check__value"> ({ res.value })</span>
					) }
				</span>
				{ ( res.status === 'fail' || res.status === 'warn' ) && (
					<span className="swps-check__help">{ def.help }</span>
				) }
				{ res.status === 'na' && (
					<span className="swps-check__help">
						{ __( 'Not analyzed', 'stratawp-seo' ) }
					</span>
				) }
			</span>
			{ renderAction && renderAction( def, res, keyword ) }
		</li>
	);
}

function Bucket( { title, rows, keyword, renderAction } ) {
	if ( ! rows.length ) {
		return null;
	}
	return (
		<section className="swps-bucket">
			<h3 className="swps-bucket__title">
				{ title } ({ rows.length })
			</h3>
			<ul className="swps-bucket__list">
				{ rows.map( ( row ) => (
					<Row key={ row.def.id } row={ row } keyword={ keyword } renderAction={ renderAction } />
				) ) }
			</ul>
		</section>
	);
}

export default function ChecksPanel( {
	output,
	registry,
	group,
	keywordIndex = 0,
	renderAction,
} ) {
	if ( ! output ) {
		return (
			<p className="swps-editor__muted">
				{ __( 'Analysis could not run. It will retry after your next edit.', 'stratawp-seo' ) }
			</p>
		);
	}
	const g = groupResults( output, registry, group, keywordIndex );
	const keyword = output.keywords[ keywordIndex ]?.keyword || '';
	return (
		<div className="swps-checks">
			<Bucket title={ __( 'Problems', 'stratawp-seo' ) } rows={ g.problems } keyword={ keyword } renderAction={ renderAction } />
			<Bucket title={ __( 'Improvements', 'stratawp-seo' ) } rows={ g.improvements } keyword={ keyword } renderAction={ renderAction } />
			<Bucket title={ __( 'Good', 'stratawp-seo' ) } rows={ g.good } keyword={ keyword } renderAction={ renderAction } />
			<Bucket title={ __( 'Not analyzed', 'stratawp-seo' ) } rows={ g.notAnalyzed } keyword={ keyword } renderAction={ renderAction } />
		</div>
	);
}
```

- [ ] **Step 5: Write the search preview and keywords panel**

`src/editor/components/SearchPreview.js`:

```js
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { Button, ButtonGroup, TextControl, TextareaControl } from '@wordpress/components';
import { usePostMeta } from '../hooks/usePostMeta';
import { paragraphs } from '../analysis/text';

const trunc = ( s, n ) => ( s.length > n ? s.slice( 0, n - 1 ).trimEnd() + '…' : s );

export default function SearchPreview( { input } ) {
	const { meta, setKey } = usePostMeta();
	const [ mode, setMode ] = useState( 'desktop' );
	const { permalink, title } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			permalink: editor.getPermalink() || '',
			title: editor.getEditedPostAttribute( 'title' ) || '',
		};
	}, [] );
	const data = window.swpsEditor || {};
	const preset = data.preset || {};

	const seoTitle = meta._swps_meta_title || title || __( 'Untitled', 'stratawp-seo' );
	const firstPara = input ? paragraphs( input.content_html )[ 0 ] || '' : '';
	const description = meta._swps_meta_description || firstPara;
	const host = permalink.replace( /^https?:\/\//, '' ).replace( /\/$/, '' ).split( '/' ).join( ' › ' );

	return (
		<div className="swps-preview">
			<ButtonGroup className="swps-preview__modes">
				<Button variant={ mode === 'desktop' ? 'primary' : 'secondary' } size="small" onClick={ () => setMode( 'desktop' ) }>
					{ __( 'Desktop', 'stratawp-seo' ) }
				</Button>
				<Button variant={ mode === 'mobile' ? 'primary' : 'secondary' } size="small" onClick={ () => setMode( 'mobile' ) }>
					{ __( 'Mobile', 'stratawp-seo' ) }
				</Button>
				<Button variant={ mode === 'social' ? 'primary' : 'secondary' } size="small" onClick={ () => setMode( 'social' ) }>
					{ __( 'Social', 'stratawp-seo' ) }
				</Button>
			</ButtonGroup>

			{ mode === 'social' ? (
				<div className="swps-social" data-testid="swps-social">
					{ meta._swps_social_image ? (
						<img className="swps-social__image" src={ meta._swps_social_image } alt="" />
					) : (
						<div className="swps-social__image swps-social__image--empty">
							{ __( 'No social image set. The featured image is used if there is one.', 'stratawp-seo' ) }
						</div>
					) }
					<div className="swps-social__host">{ permalink.replace( /^https?:\/\//, '' ).split( '/' )[ 0 ] }</div>
					<div className="swps-social__title">{ meta._swps_social_title || seoTitle }</div>
					<div className="swps-social__desc">
						{ trunc( meta._swps_social_description || description, 110 ) }
					</div>
				</div>
			) : (
				<div className={ `swps-serp swps-serp--${ mode }` } data-testid="swps-serp">
					<div className="swps-serp__url">{ host }</div>
					<div className="swps-serp__title">{ trunc( seoTitle, preset.title_max || 60 ) }</div>
					<div className="swps-serp__desc">
						{ trunc( description, mode === 'mobile' ? 120 : preset.desc_max || 160 ) }
					</div>
				</div>
			) }

			<TextControl
				label={ __( 'SEO title', 'stratawp-seo' ) }
				value={ meta._swps_meta_title || '' }
				placeholder={ title }
				onChange={ ( v ) => setKey( '_swps_meta_title', v ) }
				help={ `${ ( meta._swps_meta_title || title ).length } / ${ preset.title_max || 60 }` }
			/>
			<TextareaControl
				label={ __( 'Meta description', 'stratawp-seo' ) }
				value={ meta._swps_meta_description || '' }
				onChange={ ( v ) => setKey( '_swps_meta_description', v ) }
				help={ `${ ( meta._swps_meta_description || '' ).length } / ${ preset.desc_max || 160 }` }
			/>
		</div>
	);
}
```

`src/editor/components/KeywordsPanel.js`:

```js
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';
import { useKeywords } from '../hooks/useKeywords';

function summary( output, registry, index ) {
	if ( ! output || ! output.keywords[ index ] || ! output.keywords[ index ].keyword ) {
		return '';
	}
	const ids = registry.checks.filter( ( d ) => d.scope === 'keyword' ).map( ( d ) => d.id );
	const results = ids
		.map( ( id ) => output.keywords[ index ].results[ id ] )
		.filter( ( r ) => r && r.status !== 'na' );
	const passing = results.filter( ( r ) => r.status === 'pass' ).length;
	return `${ passing } / ${ results.length }`;
}

export default function KeywordsPanel( { output, activeIndex, onSelect, children } ) {
	const { focus, related, setFocus, setRelated } = useKeywords();
	const [ draft, setDraft ] = useState( '' );
	const registry = ( window.swpsEditor || {} ).registry || { checks: [] };

	const add = () => {
		const value = draft.trim();
		if ( ! value || related.length >= 4 ) {
			return;
		}
		setRelated( [ ...related, value ] );
		setDraft( '' );
	};

	return (
		<div className="swps-keywords">
			<TextControl
				label={ __( 'Focus keyword', 'stratawp-seo' ) }
				value={ focus }
				onChange={ setFocus }
				help={ summary( output, registry, 0 ) }
			/>
			<ul className="swps-keywords__list">
				{ related.map( ( kw, i ) => (
					<li key={ kw } className={ activeIndex === i + 1 ? 'is-active' : '' }>
						<Button variant="link" onClick={ () => onSelect( i + 1 ) }>{ kw }</Button>
						<span className="swps-keywords__sum">{ summary( output, registry, i + 1 ) }</span>
						<Button
							icon="no-alt"
							label={ __( 'Remove related keyword', 'stratawp-seo' ) + ': ' + kw }
							onClick={ () => setRelated( related.filter( ( k ) => k !== kw ) ) }
						/>
					</li>
				) ) }
			</ul>
			{ related.length < 4 && (
				<div className="swps-keywords__add">
					<TextControl
						label={ __( 'Add a related keyword', 'stratawp-seo' ) }
						value={ draft }
						onChange={ setDraft }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								e.preventDefault();
								add();
							}
						} }
					/>
					<Button variant="secondary" onClick={ add }>{ __( 'Add', 'stratawp-seo' ) }</Button>
				</div>
			) }
			{ activeIndex > 0 && (
				<Button variant="link" onClick={ () => onSelect( 0 ) }>
					{ __( 'Show checks for the focus keyword', 'stratawp-seo' ) }
				</Button>
			) }
			{ children }
		</div>
	);
}
```

- [ ] **Step 6: Assemble the sidebar**

Replace `src/editor/components/Sidebar.js`:

```js
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { PanelBody } from '@wordpress/components';
import {
	PluginDocumentSettingPanel,
	PluginSidebar,
	PluginSidebarMoreMenuItem,
} from '../compat';
import { useAnalysis } from '../hooks/useAnalysis';
import { usePostMeta } from '../hooks/usePostMeta';
import ScoreBadge from './ScoreBadge';
import SearchPreview from './SearchPreview';
import KeywordsPanel from './KeywordsPanel';
import ChecksPanel from './ChecksPanel';

export default function Sidebar() {
	const title = __( 'StrataWP SEO', 'stratawp-seo' );
	const { meta } = usePostMeta();
	const analysis = useAnalysis();
	const [ activeIndex, setActiveIndex ] = useState( 0 );
	const registry = ( window.swpsEditor || {} ).registry;

	return (
		<>
			<PluginSidebarMoreMenuItem target="stratawp-seo">{ title }</PluginSidebarMoreMenuItem>

			<PluginDocumentSettingPanel name="swps-score" title={ title }>
				<ScoreBadge score={ analysis.score } legacy={ meta._swps_seo_score_value } />
			</PluginDocumentSettingPanel>

			<PluginSidebar name="stratawp-seo" title={ title } icon="search">
				<div className="swps-editor">
					<ScoreBadge score={ analysis.score } legacy={ meta._swps_seo_score_value } />

					<PanelBody title={ __( 'Search preview', 'stratawp-seo' ) } initialOpen>
						<SearchPreview input={ analysis.input } />
					</PanelBody>

					<PanelBody title={ __( 'Keywords', 'stratawp-seo' ) } initialOpen>
						<KeywordsPanel
							output={ analysis.output }
							activeIndex={ activeIndex }
							onSelect={ setActiveIndex }
						/>
					</PanelBody>

					<PanelBody title={ __( 'SEO checks', 'stratawp-seo' ) } initialOpen>
						<ChecksPanel
							output={ analysis.output }
							registry={ registry }
							group="seo"
							keywordIndex={ activeIndex }
						/>
					</PanelBody>

					<PanelBody title={ __( 'Readability', 'stratawp-seo' ) } initialOpen={ false }>
						<ChecksPanel output={ analysis.output } registry={ registry } group="readability" />
					</PanelBody>
				</div>
			</PluginSidebar>
		</>
	);
}
```

- [ ] **Step 7: Styles**

Replace `src/editor/style.scss`:

```scss
.swps-editor__empty,
.swps-editor__muted {
	margin: 0;
	padding: 0 16px 16px;
	color: #50575e;
}

.swps-score {
	display: grid;
	grid-template-columns: auto 1fr;
	align-items: center;
	gap: 2px 12px;
	margin: 16px;
	padding: 12px 16px;
	border-left: 4px solid #757575;
	background: #f6f7f7;

	&--good { border-left-color: #007017; }
	&--needs_work { border-left-color: #996800; }
	&--poor { border-left-color: #b32d2e; }

	&__num { grid-row: span 2; font-size: 32px; font-weight: 600; line-height: 1; }
	&__label { font-weight: 600; }
	&__sub { display: flex; gap: 12px; color: #50575e; font-size: 12px; }
	&__legacy { grid-column: 1 / -1; color: #50575e; font-size: 12px; }
}

.swps-serp {
	margin: 0 0 16px;
	padding: 12px;
	border: 1px solid #dcdcde;
	border-radius: 4px;
	background: #fff;

	&__url { color: #1e1e1e; font-size: 12px; }
	&__title { color: #1a0dab; font-size: 18px; line-height: 1.3; }
	&__desc { color: #4d5156; font-size: 13px; }
	&--mobile { max-width: 320px; }
}

.swps-social {
	margin: 0 0 16px;
	border: 1px solid #dcdcde;
	border-radius: 4px;
	background: #f6f7f7;
	overflow: hidden;

	&__image { display: block; width: 100%; height: 140px; object-fit: cover; background: #dcdcde; }
	&__image--empty { display: flex; align-items: center; justify-content: center; padding: 8px; color: #50575e; font-size: 12px; text-align: center; }
	&__host { padding: 8px 12px 0; color: #50575e; font-size: 11px; text-transform: uppercase; }
	&__title { padding: 2px 12px 0; font-weight: 600; }
	&__desc { padding: 0 12px 12px; color: #50575e; font-size: 13px; }
}

.swps-checks .swps-bucket__title { margin: 12px 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
.swps-bucket__list { margin: 0; padding: 0; list-style: none; }

.swps-check {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid #f0f0f1;

	&__glyph { width: 16px; font-weight: 700; text-align: center; }
	&__body { flex: 1; display: flex; flex-direction: column; }
	&__help { color: #50575e; font-size: 12px; }
	&--pass .swps-check__glyph { color: #007017; }
	&--warn .swps-check__glyph { color: #996800; }
	&--fail .swps-check__glyph { color: #b32d2e; }
	&--na .swps-check__glyph { color: #757575; }
}

.swps-keywords__list { margin: 0 0 8px; padding: 0; list-style: none; }
.swps-keywords__list li { display: flex; align-items: center; gap: 8px; }
.swps-keywords__list li.is-active { font-weight: 600; }
.swps-keywords__sum { margin-left: auto; color: #50575e; font-size: 12px; }
.swps-keywords__add { display: flex; align-items: flex-end; gap: 8px; }
```

- [ ] **Step 8: Verify**

Run: `npm run test:js && npm run build`
Expected: Jest passes; build emits `admin/editor/index.js` and `index.css`.

Manual in the block editor (sidebar enabled): open the "StrataWP SEO" sidebar, type a title and a few paragraphs, set a focus keyword. Expected: the score badge appears within about half a second of the last keystroke, "Problems" lists failing checks with a `✕` glyph and help text, adding a related keyword shows its own `n / m` summary, clicking the related keyword switches the SEO checks list to it, and the SERP preview updates as you type the SEO title.

- [ ] **Step 9: Commit**

```bash
git add src/editor admin/editor
git commit -m "Add sidebar score, search preview, keywords and checks panels"
```

---

### Task 6: Advanced panel (fields that live in the classic metabox)

**Files:**
- Create: `src/editor/components/AdvancedPanel.js`
- Modify: `src/editor/components/Sidebar.js`

**Interfaces:**
- Consumes: `usePostMeta()` (Task 5); REST meta registered in Task 4; `window.swpsEditor.toggleUrl` (Task 1).
- Produces: `<AdvancedPanel />`.

The classic metabox is hidden in the block editor, so canonical URL, robots, breadcrumb title, social fields and sitemap controls would become unreachable. This panel carries every one of them with the same allowed values as `templates/meta-editor-metabox.php`.

- [ ] **Step 1: Write the panel**

`src/editor/components/AdvancedPanel.js`:

```js
import { __ } from '@wordpress/i18n';
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { usePostMeta } from '../hooks/usePostMeta';

const ROBOTS = [
	{ label: __( 'Default (index, follow)', 'stratawp-seo' ), value: '' },
	{ label: 'noindex, follow', value: 'noindex, follow' },
	{ label: 'index, nofollow', value: 'index, nofollow' },
	{ label: 'noindex, nofollow', value: 'noindex, nofollow' },
];

const PRIORITY = [
	{ label: __( 'Auto', 'stratawp-seo' ), value: '' },
	...[ '1.0', '0.9', '0.8', '0.7', '0.6', '0.5', '0.4', '0.3', '0.2', '0.1' ].map( ( p ) => ( {
		label: p,
		value: p,
	} ) ),
];

const CHANGEFREQ = [
	{ label: __( 'Auto', 'stratawp-seo' ), value: '' },
	{ label: __( 'Always', 'stratawp-seo' ), value: 'always' },
	{ label: __( 'Hourly', 'stratawp-seo' ), value: 'hourly' },
	{ label: __( 'Daily', 'stratawp-seo' ), value: 'daily' },
	{ label: __( 'Weekly', 'stratawp-seo' ), value: 'weekly' },
	{ label: __( 'Monthly', 'stratawp-seo' ), value: 'monthly' },
	{ label: __( 'Yearly', 'stratawp-seo' ), value: 'yearly' },
	{ label: __( 'Never', 'stratawp-seo' ), value: 'never' },
];

export default function AdvancedPanel() {
	const { meta, setKey } = usePostMeta();
	const toggleUrl = ( window.swpsEditor || {} ).toggleUrl;

	return (
		<div className="swps-advanced">
			<TextControl
				label={ __( 'Canonical URL', 'stratawp-seo' ) }
				type="url"
				value={ meta._swps_canonical_url || '' }
				onChange={ ( v ) => setKey( '_swps_canonical_url', v ) }
			/>
			<SelectControl
				label={ __( 'Robots meta', 'stratawp-seo' ) }
				value={ meta._swps_robots || '' }
				options={ ROBOTS }
				onChange={ ( v ) => setKey( '_swps_robots', v ) }
			/>
			<TextControl
				label={ __( 'Breadcrumb title', 'stratawp-seo' ) }
				value={ meta._swps_breadcrumb_title || '' }
				onChange={ ( v ) => setKey( '_swps_breadcrumb_title', v ) }
			/>
			<TextControl
				label={ __( 'Social title', 'stratawp-seo' ) }
				value={ meta._swps_social_title || '' }
				onChange={ ( v ) => setKey( '_swps_social_title', v ) }
			/>
			<TextareaControl
				label={ __( 'Social description', 'stratawp-seo' ) }
				value={ meta._swps_social_description || '' }
				onChange={ ( v ) => setKey( '_swps_social_description', v ) }
			/>
			<TextControl
				label={ __( 'Social image URL', 'stratawp-seo' ) }
				type="url"
				value={ meta._swps_social_image || '' }
				onChange={ ( v ) => setKey( '_swps_social_image', v ) }
			/>
			<MediaUploadCheck>
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					onSelect={ ( media ) => setKey( '_swps_social_image', media.url ) }
					render={ ( { open } ) => (
						<Button variant="secondary" onClick={ open }>
							{ __( 'Choose social image', 'stratawp-seo' ) }
						</Button>
					) }
				/>
			</MediaUploadCheck>

			<h3 className="swps-advanced__heading">{ __( 'Sitemap', 'stratawp-seo' ) }</h3>
			<ToggleControl
				label={ __( 'Exclude from the sitemap', 'stratawp-seo' ) }
				checked={ !! meta._swps_sitemap_exclude }
				onChange={ ( v ) => setKey( '_swps_sitemap_exclude', v ? 1 : 0 ) }
			/>
			<SelectControl
				label={ __( 'Priority', 'stratawp-seo' ) }
				value={ meta._swps_sitemap_priority || '' }
				options={ PRIORITY }
				onChange={ ( v ) => setKey( '_swps_sitemap_priority', v ) }
			/>
			<SelectControl
				label={ __( 'Change frequency', 'stratawp-seo' ) }
				value={ meta._swps_sitemap_changefreq || '' }
				options={ CHANGEFREQ }
				onChange={ ( v ) => setKey( '_swps_sitemap_changefreq', v ) }
			/>

			{ toggleUrl && (
				<p className="swps-advanced__switch">
					<a href={ toggleUrl }>
						{ __( 'Use the classic editor panel instead', 'stratawp-seo' ) }
					</a>
				</p>
			) }
		</div>
	);
}
```

- [ ] **Step 2: Mount it**

In `src/editor/components/Sidebar.js` add `import AdvancedPanel from './AdvancedPanel';` and, after the Readability `PanelBody`, add:

```js
					<PanelBody title={ __( 'Advanced', 'stratawp-seo' ) } initialOpen={ false }>
						<AdvancedPanel />
					</PanelBody>
```

Add to `src/editor/style.scss`:

```scss
.swps-advanced__heading { margin: 16px 0 8px; font-size: 13px; }
.swps-advanced__switch { margin-top: 16px; font-size: 12px; }
```

- [ ] **Step 3: Verify**

Run: `npm run build`
Manual: open a post, in Advanced set Robots to `noindex, follow`, tick "Exclude from the sitemap", set a canonical URL, click Update. Then run `wp post meta get <ID> _swps_robots` (expect `noindex, follow`), `wp post meta get <ID> _swps_sitemap_exclude` (expect `1`), and view the front end source: expect `<meta name="robots" content="noindex, follow">` and the canonical link. Choose "Use the classic editor panel instead": the page reloads with the classic metabox back and no sidebar.

- [ ] **Step 4: Commit**

```bash
git add src/editor admin/editor
git commit -m "Add Advanced panel so canonical, robots, social and sitemap fields stay reachable"
```

---

### Task 7: Deep tier (REST analyze, AI visibility, schema, citations, suggestions)

**Files:**
- Create: `includes/editor/class-editor-rest.php`, `tests/unit/EditorRestHelpersTest.php`, `src/editor/hooks/useDeep.js`, `src/editor/components/AiVisibilityPanel.js`, `src/editor/components/SchemaPanel.js`, `src/editor/components/KeywordSuggestions.js`
- Modify: `stratawp-seo.php` (require and instantiate), `src/editor/components/Sidebar.js`, `src/editor/components/KeywordsPanel.js`, `src/editor/style.scss`

**Interfaces:**
- Consumes: `SWPS_Editor_Input::keyword_used_elsewhere()` (Task 4); existing `SWPS_AEO_Optimizer::do_score(int): array`, `SWPS_AEO_Scorer` meta constants, `SWPS_Citation_Tracker::prompt_states()` and `->prompts()->add_prompt(string, int): bool|WP_Error`, `SWPS_Schema_Validator::extract_jsonld()/validate_node()/load_rules_manifest()`, `SWPS_Search_Console::is_connected()/get_page_queries()`, `SWPS_Keyword_Tracker::suggest_keywords()`, `SWPS_Autopilot_Guardian::check_budget()`.
- Produces:
  - `SWPS_Editor_Rest::normalize_keywords(mixed $raw): string[]` (trim, dedupe case-insensitively, drop empties, max 5) and `::schema_nodes(string $html, array $manifest): array` (items `{type:string, missing:string[]}`), both pure and static.
  - Routes (all require `edit_post` on `post_id`):
    - `POST /swps/v1/editor/analyze` body `{post_id, mode: 'cached'|'rescore', keywords: string[], focus: string, content_html: string}` returns `{unique: {kw: bool}, aeo: {...}, schema: {nodes}, citations: {...}}`.
    - `POST /swps/v1/editor/keyword-suggestions` body `{post_id, use_ai: bool, seed: string}` returns `{gsc: [{query, clicks, position}], ai: [{keyword, intent, difficulty}]}`.
    - `POST /swps/v1/editor/citation-track` body `{post_id, prompt}` (also requires `manage_options`) returns `{ok: true}`.
  - `useDeep(): { data, loading, error, refresh(mode) }` and `<AiVisibilityPanel data loading error dirty onRescore onTrack focus renderQueryAction? />`.

- [ ] **Step 1: Write the failing tests for the pure helpers**

`tests/unit/EditorRestHelpersTest.php`:

```php
<?php
/**
 * Tests for the pure helpers in SWPS_Editor_Rest.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-schema-validator.php';
require_once __DIR__ . '/../../includes/editor/class-editor-rest.php';

final class EditorRestHelpersTest extends TestCase {

	public function test_normalize_keywords_trims_dedupes_and_caps(): void {
		$out = SWPS_Editor_Rest::normalize_keywords( array( ' A ', 'a', '', 'b', 'c', 'd', 'e', 'f' ) );

		$this->assertSame( array( 'A', 'b', 'c', 'd', 'e' ), $out );
	}

	public function test_normalize_keywords_accepts_garbage(): void {
		$this->assertSame( array(), SWPS_Editor_Rest::normalize_keywords( null ) );
		$this->assertSame( array(), SWPS_Editor_Rest::normalize_keywords( 'text' ) );
	}

	public function test_schema_nodes_reports_missing_required_properties(): void {
		$html     = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","author":"A"}</script>';
		$manifest = array( 'Article' => array( 'required' => array( 'headline', 'author' ) ) );

		$this->assertSame(
			array( array( 'type' => 'Article', 'missing' => array( 'headline' ) ) ),
			SWPS_Editor_Rest::schema_nodes( $html, $manifest )
		);
	}

	public function test_schema_nodes_flattens_graphs_and_type_arrays(): void {
		$html     = '<script type="application/ld+json">{"@graph":[{"@type":["FAQPage"],"mainEntity":[]},{"@type":"Thing"}]}</script>';
		$manifest = array( 'FAQPage' => array( 'required' => array( 'mainEntity' ) ) );
		$nodes    = SWPS_Editor_Rest::schema_nodes( $html, $manifest );

		$this->assertSame( 'FAQPage', $nodes[0]['type'] );
		$this->assertSame( array( 'mainEntity' ), $nodes[0]['missing'] );
		$this->assertSame( array( 'type' => 'Thing', 'missing' => array() ), $nodes[1] );
	}

	public function test_schema_nodes_is_empty_without_jsonld(): void {
		$this->assertSame( array(), SWPS_Editor_Rest::schema_nodes( '<p>No schema</p>', array() ) );
	}
}
```

Run: `vendor/bin/phpunit tests/unit/EditorRestHelpersTest.php`
Expected: FAIL (missing class file).

- [ ] **Step 2: Write the REST class (analyze, suggestions, citation-track)**

`includes/editor/class-editor-rest.php`:

```php
<?php
/**
 * REST routes for the editor sidebar's deep tier.
 *
 * Every route requires edit_post on the post and reads the editor state from
 * the request body, never the saved copy, so unsaved and auto-draft posts work.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Rest {

	private const NS = 'swps/v1';

	private SWPS_AI_Provider $ai;
	private SWPS_Cost_Tracker $cost;
	private SWPS_AEO_Optimizer $aeo;
	private SWPS_Citation_Tracker $citations;
	private SWPS_Search_Console $gsc;
	private SWPS_Keyword_Tracker $keywords;

	public function __construct(
		SWPS_AI_Provider $ai,
		SWPS_Cost_Tracker $cost,
		SWPS_AEO_Optimizer $aeo,
		SWPS_Citation_Tracker $citations,
		SWPS_Search_Console $gsc,
		SWPS_Keyword_Tracker $keywords
	) {
		$this->ai        = $ai;
		$this->cost      = $cost;
		$this->aeo       = $aeo;
		$this->citations = $citations;
		$this->gsc       = $gsc;
		$this->keywords  = $keywords;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	// ---------------------------------------------------------------------
	// Pure helpers (unit tested without WordPress).
	// ---------------------------------------------------------------------

	/**
	 * @param mixed $raw Raw request value.
	 * @return string[]
	 */
	public static function normalize_keywords( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $kw ) {
			$kw = trim( (string) $kw );
			if ( '' === $kw ) {
				continue;
			}
			$key = mb_strtolower( $kw );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $kw;
			}
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return array_values( $out );
	}

	/**
	 * JSON-LD nodes found in post content with their missing required props.
	 *
	 * @param string               $html     Post content.
	 * @param array<string,mixed>  $manifest Rules manifest (type => {required: []}).
	 * @return array<int,array{type:string,missing:string[]}>
	 */
	public static function schema_nodes( string $html, array $manifest ): array {
		$out = array();
		foreach ( SWPS_Schema_Validator::extract_jsonld( $html ) as $decoded ) {
			$list = isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ? $decoded['@graph'] : array( $decoded );
			foreach ( $list as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				foreach ( (array) ( $node['@type'] ?? array() ) as $type ) {
					$type     = (string) $type;
					$required = isset( $manifest[ $type ]['required'] ) ? (array) $manifest[ $type ]['required'] : array();
					$out[]    = array(
						'type'    => $type,
						'missing' => SWPS_Schema_Validator::validate_node( $node, $required ),
					);
				}
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Routes.
	// ---------------------------------------------------------------------

	public function register_routes(): void {
		$post_id = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => static function ( $value ): bool {
				return (int) $value > 0;
			},
		);

		register_rest_route(
			self::NS,
			'/editor/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'analyze' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'post_id'      => $post_id,
					'mode'         => array(
						'type'    => 'string',
						'enum'    => array( 'cached', 'rescore' ),
						'default' => 'cached',
					),
					'keywords'     => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array(),
					),
					'focus'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'content_html' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/editor/keyword-suggestions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'keyword_suggestions' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'post_id' => $post_id,
					'use_ai'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'seed'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/editor/citation-track',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'citation_track' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'post_id' => $post_id,
					'prompt'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public function can_edit( WP_REST_Request $request ): bool {
		$post_id = (int) $request['post_id'];
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public function can_manage( WP_REST_Request $request ): bool {
		return $this->can_edit( $request ) && current_user_can( 'manage_options' );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function analyze( WP_REST_Request $request ) {
		$post_id  = (int) $request['post_id'];
		$mode     = 'rescore' === $request['mode'] ? 'rescore' : 'cached';
		$keywords = self::normalize_keywords( $request['keywords'] );
		$focus    = trim( (string) $request['focus'] );

		$unique = array();
		foreach ( $keywords as $kw ) {
			$unique[ $kw ] = SWPS_Editor_Input::keyword_used_elsewhere( $kw, $post_id );
		}

		return rest_ensure_response(
			array(
				'unique'    => (object) $unique,
				'aeo'       => $this->aeo_snapshot( $post_id, $mode ),
				'schema'    => array( 'nodes' => self::schema_nodes( (string) $request['content_html'], SWPS_Schema_Validator::load_rules_manifest() ) ),
				'citations' => $this->citation_snapshot( $post_id, $focus ),
			)
		);
	}

	/**
	 * Cached AEO values. A rescore is the only path that can spend AI budget
	 * (coverage), so it is explicit and guarded by the monthly cap.
	 *
	 * @return array<string,mixed>
	 */
	private function aeo_snapshot( int $post_id, string $mode ): array {
		if ( 'rescore' === $mode ) {
			$budget = SWPS_Autopilot_Guardian::check_budget();
			if ( is_wp_error( $budget ) ) {
				return array(
					'error' => $budget->get_error_message(),
					'code'  => $budget->get_error_code(),
				);
			}
			$this->aeo->do_score( $post_id );
		}

		$post      = get_post( $post_id );
		$payload   = get_post_meta( $post_id, SWPS_AEO_Scorer::META_COVERAGE_PAYLOAD, true );
		$subscores = array();
		foreach ( array( 'extractability', 'markup', 'authority', 'coverage' ) as $dim ) {
			$v                  = get_post_meta( $post_id, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . $dim, true );
			$subscores[ $dim ] = '' === $v ? null : (int) $v;
		}
		$total   = get_post_meta( $post_id, SWPS_AEO_Scorer::META_TOTAL, true );
		$scanned = (int) get_post_meta( $post_id, SWPS_AEO_Scorer::META_LAST_SCAN, true );

		return array(
			'enabled'     => (bool) get_option( SWPS_AEO_Scorer::OPTION_COVERAGE_ENABLED ),
			'scanned'     => $scanned > 0 ? $scanned : null,
			'total'       => '' === $total ? null : (int) $total,
			'subscores'   => $subscores,
			'sub_queries' => is_array( $payload ) ? array_values( (array) ( $payload['sub_queries'] ?? array() ) ) : array(),
			'stale'       => $post instanceof WP_Post && is_array( $payload ) && ( $payload['hash'] ?? '' ) !== md5( $post->post_content ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function citation_snapshot( int $post_id, string $focus ): array {
		foreach ( $this->citations->prompt_states() as $entry ) {
			if ( (int) ( $entry['post_id'] ?? 0 ) === $post_id ) {
				return array(
					'tracked'          => true,
					'prompt'           => (string) ( $entry['prompt'] ?? '' ),
					'states'           => (object) (array) ( $entry['states'] ?? array() ),
					'suggested_prompt' => $focus,
				);
			}
		}
		return array(
			'tracked'          => false,
			'prompt'           => null,
			'states'           => (object) array(),
			'suggested_prompt' => $focus,
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function keyword_suggestions( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$gsc     = array();

		if ( $this->gsc->is_connected() ) {
			$url = get_permalink( $post_id );
			if ( $url ) {
				foreach ( $this->gsc->get_page_queries( $url, 90 ) as $row ) {
					$query = (string) ( $row['keys'][0] ?? '' );
					if ( '' !== $query ) {
						$gsc[] = array(
							'query'    => $query,
							'clicks'   => (int) ( $row['clicks'] ?? 0 ),
							'position' => round( (float) ( $row['position'] ?? 0 ), 1 ),
						);
					}
				}
			}
		}

		$ai = array();
		if ( $request['use_ai'] ) {
			$budget = SWPS_Autopilot_Guardian::check_budget();
			if ( is_wp_error( $budget ) ) {
				return new WP_Error( $budget->get_error_code(), $budget->get_error_message(), array( 'status' => 402 ) );
			}
			$seed = trim( (string) $request['seed'] );
			if ( '' === $seed ) {
				$seed = (string) get_the_title( $post_id );
			}
			$result = $this->keywords->suggest_keywords( $seed );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
			}
			foreach ( $result as $row ) {
				if ( ! empty( $row['keyword'] ) ) {
					$ai[] = array(
						'keyword'    => (string) $row['keyword'],
						'intent'     => (string) ( $row['intent'] ?? '' ),
						'difficulty' => (string) ( $row['difficulty'] ?? '' ),
					);
				}
			}
		}

		return rest_ensure_response(
			array(
				'gsc' => $gsc,
				'ai'  => $ai,
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function citation_track( WP_REST_Request $request ) {
		$result = $this->citations->prompts()->add_prompt( trim( (string) $request['prompt'] ), (int) $request['post_id'] );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => (bool) $result ) );
	}
}
```

Run: `vendor/bin/phpunit tests/unit/EditorRestHelpersTest.php`
Expected: PASS (5 tests).

- [ ] **Step 3: Wire the REST class**

In `stratawp-seo.php` add after the Task 4 requires:

```php
require_once SWPS_PLUGIN_DIR . 'includes/editor/class-editor-rest.php';
```

and directly after `new SWPS_Editor_Sidebar();` add:

```php
		new SWPS_Editor_Rest(
			$this->api,
			$this->cost_tracker,
			$this->aeo_optimizer,
			$this->citation_tracker,
			$this->search_console,
			$this->keyword_tracker
		);
```

Run: `php -l includes/editor/class-editor-rest.php && composer analyze`
Expected: clean. If PHPStan flags `$this->api` as not a `SWPS_AI_Provider`, open the property declaration near `public ... $api;` in `stratawp-seo.php` and confirm it is `SWPS_API`, which extends the provider (it is passed to `SWPS_Link_AI_Engine`, which has the same parameter type).

- [ ] **Step 4: Write the deep-tier hook**

`src/editor/hooks/useDeep.js`:

```js
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useRegistry, useSelect } from '@wordpress/data';
import { useKeywords } from './useKeywords';

const KEYWORD_DEBOUNCE_MS = 1200;

/**
 * Deep tier: uniqueness, cached AEO, schema and citations from the server.
 * Runs when the keyword list changes (debounced), after each save, and on
 * demand. mode "rescore" is the only call that can spend AI budget.
 */
export function useDeep() {
	const registry = useRegistry();
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const saving = useSelect(
		( select ) => {
			const editor = select( 'core/editor' );
			return editor.isSavingPost() && ! editor.isAutosavingPost();
		},
		[]
	);
	const { focus, related } = useKeywords();
	const keywords = [ focus, ...related ].filter( Boolean );
	const signature = keywords.join( '\u0001' );

	const [ state, setState ] = useState( { data: null, loading: false, error: null } );

	const refresh = useCallback(
		async ( mode = 'cached' ) => {
			if ( ! postId ) {
				return;
			}
			setState( ( s ) => ( { ...s, loading: true, error: null } ) );
			try {
				const data = await apiFetch( {
					path: '/swps/v1/editor/analyze',
					method: 'POST',
					data: {
						post_id: postId,
						mode,
						keywords,
						focus,
						content_html: registry.select( 'core/editor' ).getEditedPostContent(),
					},
				} );
				setState( { data, loading: false, error: null } );
			} catch ( e ) {
				setState( ( s ) => ( {
					...s,
					loading: false,
					error: e && e.message ? e.message : String( e ),
				} ) );
			}
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ postId, signature ]
	);

	useEffect( () => {
		const timer = setTimeout( () => refresh( 'cached' ), KEYWORD_DEBOUNCE_MS );
		return () => clearTimeout( timer );
	}, [ refresh ] );

	const wasSaving = useRef( false );
	useEffect( () => {
		if ( wasSaving.current && ! saving ) {
			refresh( 'cached' );
		}
		wasSaving.current = saving;
	}, [ saving, refresh ] );

	return { ...state, refresh };
}
```

- [ ] **Step 5: Write the AI visibility and schema panels**

`src/editor/components/AiVisibilityPanel.js`:

```js
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

const DIMENSIONS = [
	[ 'extractability', __( 'Extractability', 'stratawp-seo' ) ],
	[ 'markup', __( 'Markup', 'stratawp-seo' ) ],
	[ 'authority', __( 'Authority', 'stratawp-seo' ) ],
	[ 'coverage', __( 'Coverage', 'stratawp-seo' ) ],
];
const QUERY_GLYPH = { answered: '✓', partial: '!', missing: '✕' };

export default function AiVisibilityPanel( {
	data,
	loading,
	error,
	dirty,
	focus,
	onRescore,
	onTrack,
	renderQueryAction,
} ) {
	if ( ! data ) {
		return error ? (
			<Notice status="warning" isDismissible={ false }>{ error }</Notice>
		) : (
			<p className="swps-editor__muted">{ __( 'Loading', 'stratawp-seo' ) }</p>
		);
	}
	const { aeo, citations } = data;

	return (
		<div className="swps-ai">
			{ error && <Notice status="warning" isDismissible={ false }>{ error }</Notice> }
			{ aeo.error && <Notice status="warning" isDismissible={ false }>{ aeo.error }</Notice> }

			<div className="swps-ai__score" data-testid="swps-aeo">
				<strong>{ aeo.total === null ? __( 'Not scored yet', 'stratawp-seo' ) : aeo.total }</strong>
				<span>{ __( 'AI visibility score', 'stratawp-seo' ) }</span>
				{ loading && <Spinner /> }
			</div>
			{ aeo.scanned && (
				<p className="swps-editor__muted">
					{ __( 'Last scored', 'stratawp-seo' ) } { new Date( aeo.scanned * 1000 ).toLocaleString() }
				</p>
			) }
			{ aeo.stale && (
				<p className="swps-editor__muted">
					{ __( 'This score is from an earlier version of the post.', 'stratawp-seo' ) }
				</p>
			) }

			<ul className="swps-ai__dims">
				{ DIMENSIONS.map( ( [ key, label ] ) => (
					<li key={ key }>
						<span>{ label }</span>
						<span>{ aeo.subscores[ key ] === null ? '-' : aeo.subscores[ key ] }</span>
					</li>
				) ) }
			</ul>

			<Button
				variant="secondary"
				onClick={ onRescore }
				disabled={ dirty || loading }
				title={ dirty ? __( 'Save the post first. The score reads the saved copy.', 'stratawp-seo' ) : undefined }
			>
				{ __( 'Re-score (may use AI)', 'stratawp-seo' ) }
			</Button>

			{ aeo.sub_queries.length > 0 && (
				<>
					<h3 className="swps-bucket__title">{ __( 'Questions an answer engine would ask', 'stratawp-seo' ) }</h3>
					<ul className="swps-ai__queries">
						{ aeo.sub_queries.map( ( sq ) => (
							<li key={ sq.q } className={ `swps-query swps-query--${ sq.status }` }>
								<span aria-hidden="true">{ QUERY_GLYPH[ sq.status ] }</span>
								<span className="swps-query__q">{ sq.q }</span>
								{ renderQueryAction && sq.status !== 'answered' && renderQueryAction( sq ) }
							</li>
						) ) }
					</ul>
				</>
			) }

			<h3 className="swps-bucket__title">{ __( 'AI citations', 'stratawp-seo' ) }</h3>
			{ citations.tracked ? (
				<ul className="swps-ai__dims">
					{ Object.entries( citations.states ).map( ( [ engine, s ] ) => (
						<li key={ engine }>
							<span>{ engine }</span>
							<span>{ s.state }{ s.last_date ? ` (${ s.last_date })` : '' }</span>
						</li>
					) ) }
					{ Object.keys( citations.states ).length === 0 && (
						<li>{ __( 'Tracked. No checks have run yet.', 'stratawp-seo' ) }</li>
					) }
				</ul>
			) : (
				<>
					<p className="swps-editor__muted">
						{ __( 'No citation data for this post yet.', 'stratawp-seo' ) }
					</p>
					{ focus && window.swpsEditor?.canManage && (
						<Button variant="secondary" onClick={ () => onTrack( focus ) }>
							{ __( 'Track this keyword for AI citations', 'stratawp-seo' ) }
						</Button>
					) }
				</>
			) }
		</div>
	);
}
```

`src/editor/components/SchemaPanel.js`:

```js
import { __ } from '@wordpress/i18n';

export default function SchemaPanel( { data } ) {
	if ( ! data ) {
		return <p className="swps-editor__muted">{ __( 'Loading', 'stratawp-seo' ) }</p>;
	}
	const nodes = data.schema.nodes;
	if ( ! nodes.length ) {
		return (
			<p className="swps-editor__muted">
				{ __( 'No structured data in this post\'s content. Site-wide schema is added automatically.', 'stratawp-seo' ) }
			</p>
		);
	}
	return (
		<ul className="swps-ai__dims">
			{ nodes.map( ( n, i ) => (
				<li key={ `${ n.type }-${ i }` }>
					<span>{ n.type }</span>
					<span>
						{ n.missing.length
							? `${ __( 'Missing', 'stratawp-seo' ) }: ${ n.missing.join( ', ' ) }`
							: __( 'Complete', 'stratawp-seo' ) }
					</span>
				</li>
			) ) }
		</ul>
	);
}
```

Add `canManage` to the script data in `includes/editor/class-editor-sidebar.php` `script_data()`:

```php
			'canManage'  => current_user_can( 'manage_options' ),
```

- [ ] **Step 6: Write keyword suggestions and mount everything**

`src/editor/components/KeywordSuggestions.js`:

```js
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { Button, Notice } from '@wordpress/components';
import { useKeywords } from '../hooks/useKeywords';

export default function KeywordSuggestions() {
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const { focus, related, setFocus, setRelated } = useKeywords();
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const load = async ( useAi ) => {
		setBusy( true );
		setError( null );
		try {
			setResult(
				await apiFetch( {
					path: '/swps/v1/editor/keyword-suggestions',
					method: 'POST',
					data: { post_id: postId, use_ai: useAi, seed: focus },
				} )
			);
		} catch ( e ) {
			setError( e && e.message ? e.message : String( e ) );
		}
		setBusy( false );
	};

	const addRelated = ( kw ) => {
		if ( kw && ! related.includes( kw ) && related.length < 4 ) {
			setRelated( [ ...related, kw ] );
		}
	};

	const rows = result ? [ ...result.gsc.map( ( r ) => r.query ), ...result.ai.map( ( r ) => r.keyword ) ] : [];

	return (
		<div className="swps-suggest">
			<div className="swps-suggest__actions">
				<Button variant="secondary" size="small" onClick={ () => load( false ) } disabled={ busy }>
					{ __( 'Suggest from Search Console', 'stratawp-seo' ) }
				</Button>
				<Button variant="secondary" size="small" onClick={ () => load( true ) } disabled={ busy }>
					{ __( 'Suggest with AI', 'stratawp-seo' ) }
				</Button>
			</div>
			{ error && <Notice status="warning" isDismissible={ false }>{ error }</Notice> }
			{ result && ! rows.length && ! error && (
				<p className="swps-editor__muted">{ __( 'No suggestions found.', 'stratawp-seo' ) }</p>
			) }
			<ul className="swps-suggest__list">
				{ rows.map( ( kw ) => (
					<li key={ kw }>
						<span>{ kw }</span>
						<Button variant="link" onClick={ () => setFocus( kw ) }>{ __( 'Focus', 'stratawp-seo' ) }</Button>
						<Button variant="link" onClick={ () => addRelated( kw ) }>{ __( 'Related', 'stratawp-seo' ) }</Button>
					</li>
				) ) }
			</ul>
		</div>
	);
}
```

In `src/editor/components/KeywordsPanel.js`, import `KeywordSuggestions` and render `<KeywordSuggestions />` just before `{ children }`.

In `src/editor/components/Sidebar.js`:
- add imports: `useSelect` from `@wordpress/data`, `useDeep`, `AiVisibilityPanel`, `SchemaPanel`, `apiFetch`.
- replace `const analysis = useAnalysis();` with:

```js
	const deep = useDeep();
	const analysis = useAnalysis( deep.data ? deep.data.unique : {} );
	const dirty = useSelect( ( select ) => select( 'core/editor' ).isEditedPostDirty(), [] );
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const focus = useKeywords().focus;
	const track = async ( prompt ) => {
		await apiFetch( {
			path: '/swps/v1/editor/citation-track',
			method: 'POST',
			data: { post_id: postId, prompt },
		} );
		deep.refresh( 'cached' );
	};
```

(import `useKeywords` too.) After the Readability `PanelBody` add:

```js
					<PanelBody title={ __( 'AI visibility', 'stratawp-seo' ) } initialOpen>
						<AiVisibilityPanel
							data={ deep.data }
							loading={ deep.loading }
							error={ deep.error }
							dirty={ dirty }
							focus={ focus }
							onRescore={ () => deep.refresh( 'rescore' ) }
							onTrack={ track }
						/>
					</PanelBody>

					<PanelBody title={ __( 'Schema', 'stratawp-seo' ) } initialOpen={ false }>
						<SchemaPanel data={ deep.data } />
					</PanelBody>
```

Pass the AI visibility score to both `ScoreBadge` elements in `Sidebar.js` (the Document panel one and the sidebar one) by adding this prop to each:

```js
aeo={ deep.data && deep.data.aeo ? deep.data.aeo.total : null }
```

Append to `src/editor/style.scss`:

```scss
.swps-ai__score { display: flex; align-items: center; gap: 8px; strong { font-size: 24px; } }
.swps-ai__dims, .swps-ai__queries, .swps-suggest__list { margin: 8px 0; padding: 0; list-style: none; }
.swps-ai__dims li { display: flex; justify-content: space-between; padding: 2px 0; }
.swps-query { display: flex; gap: 8px; align-items: flex-start; padding: 4px 0; }
.swps-query--answered span:first-child { color: #007017; }
.swps-query--partial span:first-child { color: #996800; }
.swps-query--missing span:first-child { color: #b32d2e; }
.swps-query__q { flex: 1; }
.swps-suggest__actions { display: flex; gap: 8px; flex-wrap: wrap; margin: 8px 0; }
.swps-suggest__list li { display: flex; align-items: center; gap: 8px; }
.swps-suggest__list li span { flex: 1; }
```

- [ ] **Step 7: Verify**

Run: `npm run test:js && npm run build && composer analyze && composer test`
Expected: all green.

Manual in the editor: set a focus keyword and wait about 1.5 seconds. Expected: the AI visibility panel shows a score (or "Not scored yet") and the four dimensions; `swps_aeo_score` meta is untouched by merely opening the post (check `wp post meta get <ID> _swps_aeo_last_scan` does not change). Make an edit so the post is dirty: "Re-score" is disabled with the tooltip. Save, click Re-score: `_swps_aeo_last_scan` updates. Open the Network tab and confirm `analyze` is sent with the unsaved `content_html`. Set the same focus keyword on two posts: the second post's "Keyword not used on another post" check fails within about 2 seconds. With a monthly budget set to 0.01 and spend above it, "Re-score" shows the budget message inline instead of failing silently.

Permissions (REST routes need a WordPress runtime, so they are checked by hand): log in as a Contributor, then in the browser console run `wp.apiFetch({path:'/swps/v1/editor/analyze',method:'POST',data:{post_id:<ID of a post by another author>}})`. Expected: a 403 `rest_forbidden` error. Run the same call with a missing `post_id`: a 400 `rest_missing_callback_param`. Run it logged out: a 401 or 403. Run `citation-track` as an Editor without `manage_options`: a 403.

- [ ] **Step 8: Commit**

```bash
git add includes/editor/class-editor-rest.php includes/editor/class-editor-sidebar.php stratawp-seo.php tests/unit/EditorRestHelpersTest.php src/editor admin/editor
git commit -m "Add deep tier: REST analyze, AI visibility, schema and citation panels"
```

---

### Task 8: Fix flow (rule fixes, AI fixes, "Add an answer")

**Files:**
- Create: `includes/editor/class-editor-fix.php`, `tests/unit/EditorFixTest.php`, `src/editor/analysis/fixes.js`, `src/editor/__tests__/fixes.test.js`, `src/editor/hooks/useApplyFix.js`, `src/editor/components/FixDiff.js`, `src/editor/components/FixButton.js`, `src/editor/components/AnswerButton.js`
- Modify: `includes/editor/class-editor-rest.php` (fix route), `includes/editor/class-editor-sidebar.php` (cost estimate), `stratawp-seo.php` (require), `src/editor/components/Sidebar.js`, `src/editor/style.scss`

**Interfaces:**
- Consumes: `paragraphs()`, `plain()`, `slugify()`, `charLength()` from `analysis/text.js`; `usePostMeta()`; `SWPS_Autopilot_Guardian::check_budget()`; `SWPS_Cost_Tracker::track()/calculate_cost()`; `SWPS_AI_Provider::chat_json()` (returns the decoded JSON array plus `_usage {input_tokens, output_tokens}`, or `WP_Error`).
- Produces:
  - `SWPS_Editor_Fix::supports(string): bool`, `::kind(string): string` (`meta_title|meta_description|paragraph|insert`), `::which(string): string` (`first|last`), `::target_matches(string $html, string $check_id, string $target): bool`, `::build_prompt(string $check_id, string $keyword, array $ctx): array{system:string,user:string}`, `::validate(string $check_id, string $keyword, array $ctx, mixed $parsed): array` returning `{ok:true, proposal:{check_id,kind,value,original}}` or `{ok:false, code:string, message:string}`. `$ctx` keys: `title`, `meta_title`, `meta_description`, `target_text`, `question`, `lang`.
  - Route `POST /swps/v1/editor/fix` body `{post_id, check_id, keyword, title, meta_title, meta_description, content_html, lang, target_text, question}` returns `{proposal, cost}`; errors use `WP_Error` statuses 400 (unsupported or no keyword), 402 (budget), 409 (`swps_fix_stale`), 422 (invalid proposal), 502 (provider).
  - `ruleFix(checkId, ctx): null | {kind:'slug'|'meta_title'|'meta_description', value, original}`; `useApplyFix(): (proposal) => {ok:boolean, message?:string}`.

- [ ] **Step 1: Write the failing PHPUnit tests for proposal validation**

`tests/unit/EditorFixTest.php`:

```php
<?php
/**
 * Tests for the pure fix prompt and validation logic.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/editor/class-editor-text.php';
require_once __DIR__ . '/../../includes/editor/class-editor-fix.php';

final class EditorFixTest extends TestCase {

	private const ORIGINAL = 'Cold coffee is easy to make at home with a few simple tools and patience.';

	/**
	 * @return array<string,string>
	 */
	private function ctx(): array {
		return array(
			'title'            => 'Brewing basics',
			'meta_title'       => '',
			'meta_description' => 'Short.',
			'target_text'      => self::ORIGINAL,
			'question'         => 'How long does cold brew keep?',
			'lang'             => 'en',
		);
	}

	public function test_supported_checks_map_to_kinds(): void {
		$this->assertTrue( SWPS_Editor_Fix::supports( 'kw_in_title' ) );
		$this->assertFalse( SWPS_Editor_Fix::supports( 'kw_density' ) );
		$this->assertSame( 'meta_title', SWPS_Editor_Fix::kind( 'kw_in_title' ) );
		$this->assertSame( 'meta_description', SWPS_Editor_Fix::kind( 'kw_in_description' ) );
		$this->assertSame( 'paragraph', SWPS_Editor_Fix::kind( 'kw_in_intro' ) );
		$this->assertSame( 'insert', SWPS_Editor_Fix::kind( 'aeo_answer' ) );
		$this->assertSame( 'first', SWPS_Editor_Fix::which( 'kw_in_intro' ) );
		$this->assertSame( 'last', SWPS_Editor_Fix::which( 'kw_in_conclusion' ) );
	}

	public function test_valid_paragraph_proposal_is_accepted(): void {
		$res = SWPS_Editor_Fix::validate(
			'kw_in_intro',
			'cold brew coffee',
			$this->ctx(),
			array( 'value' => 'Cold brew coffee is easy to make at home with a few simple tools and some patience.' )
		);

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'paragraph', $res['proposal']['kind'] );
		$this->assertSame( self::ORIGINAL, $res['proposal']['original'] );
	}

	public function test_proposal_without_the_keyword_is_rejected(): void {
		$res = SWPS_Editor_Fix::validate( 'kw_in_intro', 'cold brew coffee', $this->ctx(), array( 'value' => 'Iced coffee is easy to make at home with a few simple tools and patience.' ) );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'swps_fix_keyword', $res['code'] );
	}

	public function test_noop_proposal_is_rejected(): void {
		$res = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), array( 'value' => self::ORIGINAL ) );

		$this->assertSame( 'swps_fix_noop', $res['code'] );
	}

	public function test_empty_or_malformed_responses_are_rejected(): void {
		foreach ( array( null, array(), array( 'value' => '' ), array( 'value' => array( 'x' ) ), 'text' ) as $bad ) {
			$res = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), $bad );
			$this->assertFalse( $res['ok'] );
			$this->assertSame( 'swps_fix_empty', $res['code'] );
		}
	}

	public function test_unsafe_markup_is_rejected(): void {
		$script = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), array( 'value' => 'Coffee <script>alert(1)</script> at home with tools and patience for you.' ) );
		$div    = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), array( 'value' => '<div>Coffee at home with a few simple tools and patience for everyone.</div>' ) );

		$this->assertSame( 'swps_fix_unsafe', $script['code'] );
		$this->assertSame( 'swps_fix_unsafe', $div['code'] );
	}

	public function test_paragraph_length_must_stay_close_to_the_original(): void {
		$short = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), array( 'value' => 'Coffee at home.' ) );
		$long  = SWPS_Editor_Fix::validate( 'kw_in_intro', 'coffee', $this->ctx(), array( 'value' => 'Coffee ' . str_repeat( 'word ', 60 ) ) );

		$this->assertSame( 'swps_fix_length', $short['code'] );
		$this->assertSame( 'swps_fix_length', $long['code'] );
	}

	public function test_meta_title_limits(): void {
		$ok   = SWPS_Editor_Fix::validate( 'kw_in_title', 'cold brew', $this->ctx(), array( 'value' => 'Cold brew basics for beginners' ) );
		$long = SWPS_Editor_Fix::validate( 'kw_in_title', 'cold brew', $this->ctx(), array( 'value' => 'Cold brew ' . str_repeat( 'x', 80 ) ) );

		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'Cold brew basics for beginners', $ok['proposal']['value'] );
		$this->assertSame( 'Brewing basics', $ok['proposal']['original'] );
		$this->assertSame( 'swps_fix_length', $long['code'] );
	}

	public function test_meta_description_limits(): void {
		$short = SWPS_Editor_Fix::validate( 'kw_in_description', 'cold brew', $this->ctx(), array( 'value' => 'Cold brew tips.' ) );
		$ok    = SWPS_Editor_Fix::validate(
			'kw_in_description',
			'cold brew',
			$this->ctx(),
			array( 'value' => 'Learn how cold brew works, which grind to use and how long to steep it for a smooth cup every morning.' )
		);

		$this->assertSame( 'swps_fix_length', $short['code'] );
		$this->assertTrue( $ok['ok'] );
	}

	public function test_insert_answers_must_be_short_and_need_no_keyword(): void {
		$ok   = SWPS_Editor_Fix::validate( 'aeo_answer', '', $this->ctx(), array( 'value' => 'Cold brew keeps for up to two weeks in a sealed jar in the fridge.' ) );
		$long = SWPS_Editor_Fix::validate( 'aeo_answer', '', $this->ctx(), array( 'value' => str_repeat( 'word ', 120 ) ) );

		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'insert', $ok['proposal']['kind'] );
		$this->assertSame( 'swps_fix_length', $long['code'] );
	}

	public function test_target_must_match_the_current_first_or_last_paragraph(): void {
		$html = '<p>First paragraph here.</p><h2>Heading</h2><p>Middle.</p><p>Last paragraph here.</p>';

		$this->assertTrue( SWPS_Editor_Fix::target_matches( $html, 'kw_in_intro', 'First paragraph here.' ) );
		$this->assertTrue( SWPS_Editor_Fix::target_matches( $html, 'kw_in_conclusion', 'Last paragraph here.' ) );
		$this->assertFalse( SWPS_Editor_Fix::target_matches( $html, 'kw_in_intro', 'An older first paragraph.' ) );
		$this->assertFalse( SWPS_Editor_Fix::target_matches( '', 'kw_in_intro', 'Anything' ) );
	}

	public function test_prompts_name_the_keyword_and_demand_json(): void {
		$p = SWPS_Editor_Fix::build_prompt( 'kw_in_intro', 'cold brew coffee', $this->ctx() );

		$this->assertStringContainsString( '"value"', $p['system'] );
		$this->assertStringContainsString( 'cold brew coffee', $p['user'] );
		$this->assertStringContainsString( self::ORIGINAL, $p['user'] );

		$a = SWPS_Editor_Fix::build_prompt( 'aeo_answer', 'cold brew coffee', $this->ctx() );
		$this->assertStringContainsString( 'How long does cold brew keep?', $a['user'] );
	}
}
```

Run: `vendor/bin/phpunit tests/unit/EditorFixTest.php`
Expected: FAIL (missing class file).

- [ ] **Step 2: Write the pure fix class**

`includes/editor/class-editor-fix.php`:

```php
<?php
/**
 * Pure prompt building and proposal validation for editor fixes.
 *
 * No WordPress calls. The REST layer calls the provider and the budget guard;
 * this class decides whether what came back is safe to show the writer.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Fix {

	private const KINDS = array(
		'kw_in_title'      => 'meta_title',
		'kw_in_description' => 'meta_description',
		'kw_in_intro'      => 'paragraph',
		'kw_in_conclusion' => 'paragraph',
		'aeo_answer'       => 'insert',
	);

	private const SYSTEM = 'You are a careful SEO editor. Reply with JSON only, in exactly this form: {"value": "<text>"}. No commentary. If you cannot do the task confidently, reply {"value": ""}.';

	/**
	 * @return string[]
	 */
	public static function check_ids(): array {
		return array_keys( self::KINDS );
	}

	public static function supports( string $check_id ): bool {
		return isset( self::KINDS[ $check_id ] );
	}

	public static function kind( string $check_id ): string {
		return self::KINDS[ $check_id ] ?? '';
	}

	public static function which( string $check_id ): string {
		return 'kw_in_conclusion' === $check_id ? 'last' : 'first';
	}

	/**
	 * True when the paragraph the writer is looking at is still the first or
	 * last paragraph of the current content.
	 */
	public static function target_matches( string $content_html, string $check_id, string $target ): bool {
		$paras = SWPS_Editor_Text::paragraphs( $content_html );
		if ( ! $paras ) {
			return false;
		}
		$expected = 'last' === self::which( $check_id ) ? $paras[ count( $paras ) - 1 ] : $paras[0];
		return $expected === $target;
	}

	/**
	 * @param array<string,string> $ctx Context: title, meta_title, meta_description, target_text, question, lang.
	 * @return array{system:string,user:string}
	 */
	public static function build_prompt( string $check_id, string $keyword, array $ctx ): array {
		$lang  = '' !== ( $ctx['lang'] ?? '' ) ? $ctx['lang'] : 'en';
		$title = '' !== trim( $ctx['meta_title'] ?? '' ) ? trim( $ctx['meta_title'] ) : trim( $ctx['title'] ?? '' );

		switch ( self::kind( $check_id ) ) {
			case 'meta_title':
				$user = "Rewrite this page title so it naturally contains the exact keyword \"{$keyword}\", ideally near the start. Keep the meaning and keep it under 60 characters.\nCurrent title: {$title}\nLanguage: {$lang}";
				break;
			case 'meta_description':
				$user = "Write a meta description of 120 to 155 characters that contains the exact keyword \"{$keyword}\" and gives a reason to click.\nPage title: {$title}\nCurrent description: " . trim( $ctx['meta_description'] ?? '' ) . "\nLanguage: {$lang}";
				break;
			case 'paragraph':
				$user = "Rewrite this paragraph so it naturally contains the exact keyword \"{$keyword}\". Keep the meaning, keep every fact and keep the length within 30 percent. You may use only <a>, <strong> and <em> tags.\nLanguage: {$lang}\nParagraph:\n" . ( $ctx['target_text'] ?? '' );
				break;
			default:
				$user = "Answer this question in 2 to 4 plain sentences, under 80 words, for an article titled \"{$title}\" about \"{$keyword}\". Use only facts you are confident about. You may use only <a>, <strong> and <em> tags.\nLanguage: {$lang}\nQuestion: " . ( $ctx['question'] ?? '' );
				break;
		}

		return array(
			'system' => self::SYSTEM,
			'user'   => $user,
		);
	}

	/**
	 * @param array<string,string> $ctx    Same context as build_prompt().
	 * @param mixed                $parsed Decoded provider JSON.
	 * @return array<string,mixed>
	 */
	public static function validate( string $check_id, string $keyword, array $ctx, $parsed ): array {
		$kind  = self::kind( $check_id );
		$value = ( is_array( $parsed ) && isset( $parsed['value'] ) && is_string( $parsed['value'] ) ) ? trim( $parsed['value'] ) : '';

		if ( '' === $value ) {
			return self::bad( 'swps_fix_empty', __( 'The AI did not return usable text. Try again.', 'stratawp-seo' ) );
		}
		if ( preg_match( '/<\s*(?:script|iframe|style|object|embed)\b/i', $value ) ) {
			return self::bad( 'swps_fix_unsafe', __( 'The suggestion contained markup that is not allowed.', 'stratawp-seo' ) );
		}

		$plain  = SWPS_Editor_Text::plain( $value );
		$has_kw = '' !== $keyword && false !== mb_strpos( SWPS_Editor_Text::lower( $plain ), SWPS_Editor_Text::lower( $keyword ) );

		switch ( $kind ) {
			case 'meta_title':
				$original = '' !== trim( $ctx['meta_title'] ?? '' ) ? trim( $ctx['meta_title'] ) : trim( $ctx['title'] ?? '' );
				if ( mb_strlen( $plain ) > 70 ) {
					return self::bad( 'swps_fix_length', __( 'The suggested title is too long.', 'stratawp-seo' ) );
				}
				if ( ! $has_kw ) {
					return self::bad( 'swps_fix_keyword', __( 'The suggestion does not contain the keyword.', 'stratawp-seo' ) );
				}
				$value   = $plain;
				$compare = $plain;
				break;

			case 'meta_description':
				$original = trim( $ctx['meta_description'] ?? '' );
				$len      = mb_strlen( $plain );
				if ( $len < 50 || $len > 170 ) {
					return self::bad( 'swps_fix_length', __( 'The suggested description is the wrong length.', 'stratawp-seo' ) );
				}
				if ( ! $has_kw ) {
					return self::bad( 'swps_fix_keyword', __( 'The suggestion does not contain the keyword.', 'stratawp-seo' ) );
				}
				$value   = $plain;
				$compare = $plain;
				break;

			case 'paragraph':
				$original = trim( $ctx['target_text'] ?? '' );
				if ( strip_tags( $value, '<a><strong><em>' ) !== $value ) {
					return self::bad( 'swps_fix_unsafe', __( 'The suggestion contained markup that is not allowed.', 'stratawp-seo' ) );
				}
				$before = count( SWPS_Editor_Text::words( $original ) );
				$after  = count( SWPS_Editor_Text::words( $plain ) );
				if ( $before > 0 && ( $after * 2 < $before || $after > $before * 3 ) ) {
					return self::bad( 'swps_fix_length', __( 'The suggested paragraph is much longer or shorter than the original.', 'stratawp-seo' ) );
				}
				if ( ! $has_kw ) {
					return self::bad( 'swps_fix_keyword', __( 'The suggestion does not contain the keyword.', 'stratawp-seo' ) );
				}
				$compare = $plain;
				break;

			default:
				$original = '';
				if ( strip_tags( $value, '<a><strong><em>' ) !== $value ) {
					return self::bad( 'swps_fix_unsafe', __( 'The suggestion contained markup that is not allowed.', 'stratawp-seo' ) );
				}
				if ( count( SWPS_Editor_Text::words( $plain ) ) > 90 ) {
					return self::bad( 'swps_fix_length', __( 'The suggested answer is too long.', 'stratawp-seo' ) );
				}
				$compare = '';
				break;
		}

		if ( '' !== $original && $compare === $original ) {
			return self::bad( 'swps_fix_noop', __( 'The suggestion is the same as the current text.', 'stratawp-seo' ) );
		}

		return array(
			'ok'       => true,
			'proposal' => array(
				'check_id' => $check_id,
				'kind'     => $kind,
				'value'    => $value,
				'original' => $original,
			),
		);
	}

	/**
	 * @return array{ok:false,code:string,message:string}
	 */
	private static function bad( string $code, string $message ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
		);
	}
}
```

Run: `vendor/bin/phpunit tests/unit/EditorFixTest.php`
Expected: PASS (11 tests).

- [ ] **Step 3: Add the fix route**

In `stratawp-seo.php`, after the REST class require, add `require_once SWPS_PLUGIN_DIR . 'includes/editor/class-editor-fix.php';`.

In `includes/editor/class-editor-rest.php`, add this to `register_routes()` after the citation route:

```php
		register_rest_route(
			self::NS,
			'/editor/fix',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'fix' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'post_id'          => $post_id,
					'check_id'         => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => SWPS_Editor_Fix::check_ids(),
					),
					'keyword'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'title'            => array(
						'type'    => 'string',
						'default' => '',
					),
					'meta_title'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'meta_description' => array(
						'type'    => 'string',
						'default' => '',
					),
					'content_html'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'lang'             => array(
						'type'    => 'string',
						'default' => 'en',
					),
					'target_text'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'question'         => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
```

and this method:

```php
	/**
	 * Ask the provider for one fix and return it only if it validates.
	 * Nothing is written to the post here; the editor applies it on request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function fix( WP_REST_Request $request ) {
		$check_id = (string) $request['check_id'];
		$keyword  = trim( (string) $request['keyword'] );
		$post_id  = (int) $request['post_id'];

		if ( ! SWPS_Editor_Fix::supports( $check_id ) ) {
			return new WP_Error( 'swps_fix_unsupported', __( 'This check has no AI fix.', 'stratawp-seo' ), array( 'status' => 400 ) );
		}
		if ( '' === $keyword && 'aeo_answer' !== $check_id ) {
			return new WP_Error( 'swps_fix_no_keyword', __( 'Set a focus keyword first.', 'stratawp-seo' ), array( 'status' => 400 ) );
		}

		$ctx = array(
			'title'            => (string) $request['title'],
			'meta_title'       => (string) $request['meta_title'],
			'meta_description' => (string) $request['meta_description'],
			'target_text'      => (string) $request['target_text'],
			'question'         => (string) $request['question'],
			'lang'             => (string) $request['lang'],
		);

		if ( 'paragraph' === SWPS_Editor_Fix::kind( $check_id )
			&& ! SWPS_Editor_Fix::target_matches( (string) $request['content_html'], $check_id, $ctx['target_text'] ) ) {
			return new WP_Error( 'swps_fix_stale', __( 'The paragraph changed. Try again.', 'stratawp-seo' ), array( 'status' => 409 ) );
		}

		$budget = SWPS_Autopilot_Guardian::check_budget();
		if ( is_wp_error( $budget ) ) {
			return new WP_Error( $budget->get_error_code(), $budget->get_error_message(), array( 'status' => 402 ) );
		}

		$prompt = SWPS_Editor_Fix::build_prompt( $check_id, $keyword, $ctx );
		$result = $this->ai->chat_json( $prompt['system'], $prompt['user'], 1024 );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}

		$cost  = null;
		$model = (string) get_option( 'swps_model', '' );
		if ( ! empty( $result['_usage'] ) ) {
			$in   = (int) ( $result['_usage']['input_tokens'] ?? 0 );
			$out  = (int) ( $result['_usage']['output_tokens'] ?? 0 );
			$this->cost->track( $model, $in, $out, $post_id );
			$cost = round( $this->cost->calculate_cost( $model, $in, $out ), 4 );
		}

		$checked = SWPS_Editor_Fix::validate( $check_id, $keyword, $ctx, $result );
		if ( empty( $checked['ok'] ) ) {
			return new WP_Error( (string) $checked['code'], (string) $checked['message'], array( 'status' => 422 ) );
		}

		$proposal = $checked['proposal'];
		if ( in_array( $proposal['kind'], array( 'paragraph', 'insert' ), true ) ) {
			$proposal['value'] = wp_kses(
				$proposal['value'],
				array(
					'a'      => array( 'href' => true ),
					'strong' => array(),
					'em'     => array(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'proposal' => $proposal,
				'cost'     => $cost,
			)
		);
	}
```

In `includes/editor/class-editor-sidebar.php`, add `'fixCost' => $this->estimate_fix_cost(),` to `script_data()` and this method:

```php
	/**
	 * Rough price of one AI fix (about 1,500 tokens in, 300 out), or null when
	 * the model price is unknown.
	 */
	private function estimate_fix_cost(): ?float {
		$model = (string) get_option( 'swps_model', '' );
		if ( '' === $model ) {
			return null;
		}
		$cost = ( new SWPS_Cost_Tracker() )->calculate_cost( $model, 1500, 300 );
		return $cost > 0 ? round( $cost, 4 ) : null;
	}
```

Run: `php -l includes/editor/class-editor-rest.php && composer analyze && composer test`
Expected: clean.

- [ ] **Step 4: Write the failing Jest test for rule fixes**

`src/editor/__tests__/fixes.test.js`:

```js
import { ruleFix } from '../analysis/fixes';
import { charLength } from '../analysis/text';

describe( 'ruleFix', () => {
	it( 'suggests the keyword slug for a draft', () => {
		expect(
			ruleFix( 'kw_in_slug', { keyword: 'Cold Brew Coffee', slug: 'untitled', status: 'draft' } )
		).toEqual( { kind: 'slug', value: 'cold-brew-coffee', original: 'untitled' } );
	} );

	it( 'never rewrites the slug of a published or scheduled post', () => {
		expect( ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'x', status: 'publish' } ) ).toBeNull();
		expect( ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'x', status: 'future' } ) ).toBeNull();
	} );

	it( 'has no slug fix when the slug already matches', () => {
		expect( ruleFix( 'kw_in_slug', { keyword: 'cold brew', slug: 'cold-brew', status: 'draft' } ) ).toBeNull();
	} );

	it( 'trims a long title at a word boundary', () => {
		const title = 'How to make the best cold brew coffee at home in under ten minutes with simple tools';
		const fix = ruleFix( 'title_length', { title, metaTitle: '', preset: { title_max: 60 } } );
		expect( charLength( fix.value ) ).toBeLessThanOrEqual( 60 );
		expect( title.startsWith( fix.value ) ).toBe( true );
		expect( fix.value.endsWith( ' ' ) ).toBe( false );
		expect( fix.kind ).toBe( 'meta_title' );
	} );

	it( 'has no title fix when the title already fits', () => {
		expect( ruleFix( 'title_length', { title: 'Short title', preset: { title_max: 60 } } ) ).toBeNull();
	} );

	it( 'cuts a long description at a sentence end when one is close enough', () => {
		const metaDescription =
			'Cold brew is smooth. It is low in acid and easy to make at home. Follow this guide to get it right every single time you brew.';
		const fix = ruleFix( 'description_length', { metaDescription, preset: { desc_max: 70 } } );
		expect( fix.value ).toBe( 'Cold brew is smooth. It is low in acid and easy to make at home.' );
	} );

	it( 'returns null for checks without a rule fix', () => {
		expect( ruleFix( 'kw_density', {} ) ).toBeNull();
	} );
} );
```

Run: `npm run test:js`
Expected: FAIL (cannot find `../analysis/fixes`).

- [ ] **Step 5: Write the rule fixes**

`src/editor/analysis/fixes.js`:

```js
import { charLength, slugify } from './text';

const cutChars = ( s, n ) => Array.from( s ).slice( 0, n ).join( '' );

export function trimToWords( text, max ) {
	if ( charLength( text ) <= max ) {
		return text;
	}
	const cut = cutChars( text, max );
	const space = cut.lastIndexOf( ' ' );
	const base = space > max * 0.6 ? cut.slice( 0, space ) : cut;
	return base.replace( /[\s,;:-]+$/, '' );
}

export function trimToSentence( text, max ) {
	if ( charLength( text ) <= max ) {
		return text;
	}
	const probe = cutChars( text, max ) + ' ';
	const end = Math.max(
		probe.lastIndexOf( '. ' ),
		probe.lastIndexOf( '! ' ),
		probe.lastIndexOf( '? ' )
	);
	if ( end > max * 0.5 ) {
		return probe.slice( 0, end + 1 );
	}
	return trimToWords( text, max );
}

/**
 * Deterministic fixes that need no AI. Returns null when the fix does not
 * apply, so the sidebar shows no button.
 */
export function ruleFix( checkId, ctx ) {
	const {
		keyword = '',
		slug = '',
		title = '',
		metaTitle = '',
		metaDescription = '',
		status = 'draft',
		preset = {},
	} = ctx;

	if ( checkId === 'kw_in_slug' ) {
		const next = slugify( keyword );
		// Changing the URL of a live post breaks inbound links.
		if ( status === 'publish' || status === 'future' || ! next || next === slug ) {
			return null;
		}
		return { kind: 'slug', value: next, original: slug };
	}

	if ( checkId === 'title_length' ) {
		const original = metaTitle.trim() !== '' ? metaTitle : title;
		const max = preset.title_max || 60;
		if ( charLength( original.trim() ) <= max ) {
			return null;
		}
		return { kind: 'meta_title', value: trimToWords( original.trim(), max ), original };
	}

	if ( checkId === 'description_length' ) {
		const max = preset.desc_max || 160;
		if ( charLength( metaDescription.trim() ) <= max ) {
			return null;
		}
		return {
			kind: 'meta_description',
			value: trimToSentence( metaDescription.trim(), max ),
			original: metaDescription,
		};
	}

	return null;
}
```

Run: `npm run test:js`
Expected: PASS.

- [ ] **Step 6: Write the apply hook**

`src/editor/hooks/useApplyFix.js`:

```js
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useCallback } from '@wordpress/element';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';
import { usePostMeta } from './usePostMeta';
import { plain } from '../analysis/text';

/**
 * Find the core/paragraph block whose visible text is exactly `text`.
 */
export function findParagraphBlock( registry, text ) {
	const store = registry.select( 'core/block-editor' );
	return (
		store.getClientIdsWithDescendants().find( ( id ) => {
			const block = store.getBlock( id );
			return (
				block &&
				block.name === 'core/paragraph' &&
				plain( String( block.attributes.content ?? '' ) ) === text
			);
		} ) || null
	);
}

/**
 * Apply a validated proposal through the editor stores so one Undo reverts it.
 * Returns { ok } or { ok: false, message } and never throws.
 */
export function useApplyFix() {
	const registry = useRegistry();
	const { setKey } = usePostMeta();
	const { editPost } = useDispatch( 'core/editor' );
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );

	return useCallback(
		( proposal ) => {
			try {
				switch ( proposal.kind ) {
					case 'slug':
						editPost( { slug: proposal.value } );
						break;
					case 'meta_title':
						setKey( '_swps_meta_title', proposal.value );
						break;
					case 'meta_description':
						setKey( '_swps_meta_description', proposal.value );
						break;
					case 'paragraph': {
						const id = findParagraphBlock( registry, proposal.original );
						if ( ! id ) {
							return {
								ok: false,
								message: __( 'The paragraph changed. Try again.', 'stratawp-seo' ),
							};
						}
						registry
							.dispatch( 'core/block-editor' )
							.updateBlockAttributes( id, { content: proposal.value } );
						break;
					}
					case 'insert': {
						const store = registry.select( 'core/block-editor' );
						const selected = store.getSelectedBlockClientId();
						const root = selected ? store.getBlockRootClientId( selected ) : '';
						const index = selected
							? store.getBlockIndex( selected ) + 1
							: store.getBlockCount();
						registry.dispatch( 'core/block-editor' ).insertBlocks(
							[
								createBlock( 'core/heading', { level: 3, content: proposal.heading } ),
								createBlock( 'core/paragraph', { content: proposal.value } ),
							],
							index,
							root || ''
						);
						break;
					}
					default:
						return { ok: false, message: __( 'Unknown fix.', 'stratawp-seo' ) };
				}
			} catch ( e ) {
				return { ok: false, message: e && e.message ? e.message : String( e ) };
			}

			// Record for the proof snapshot. Failure here must never undo the fix.
			apiFetch( {
				path: '/swps/v1/editor/applied',
				method: 'POST',
				data: { post_id: postId, check_id: proposal.check_id },
			} ).catch( () => {} );

			return { ok: true };
		},
		[ registry, setKey, editPost, postId ]
	);
}
```

- [ ] **Step 7: Write the diff, fix button and answer button**

`src/editor/components/FixDiff.js`:

```js
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

export default function FixDiff( { proposal, onApply, onDismiss } ) {
	return (
		<div className="swps-diff" data-testid="swps-diff">
			{ proposal.original !== '' && (
				<div className="swps-diff__before">
					<span className="swps-diff__tag">{ __( 'Before', 'stratawp-seo' ) }</span>
					<del>{ proposal.original }</del>
				</div>
			) }
			<div className="swps-diff__after">
				<span className="swps-diff__tag">{ __( 'After', 'stratawp-seo' ) }</span>
				{ proposal.heading && <strong>{ proposal.heading }</strong> }
				<ins>{ proposal.value }</ins>
			</div>
			<div className="swps-diff__actions">
				<Button variant="primary" size="small" onClick={ onApply }>
					{ __( 'Apply', 'stratawp-seo' ) }
				</Button>
				<Button variant="tertiary" size="small" onClick={ onDismiss }>
					{ __( 'Dismiss', 'stratawp-seo' ) }
				</Button>
			</div>
		</div>
	);
}
```

`src/editor/components/FixButton.js`:

```js
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useRegistry, useSelect } from '@wordpress/data';
import { Button, Notice } from '@wordpress/components';
import FixDiff from './FixDiff';
import { ruleFix } from '../analysis/fixes';
import { paragraphs } from '../analysis/text';
import { findParagraphBlock, useApplyFix } from '../hooks/useApplyFix';

export default function FixButton( { def, res, keyword, input } ) {
	const registry = useRegistry();
	const apply = useApplyFix();
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const status = useSelect(
		( select ) => select( 'core/editor' ).getEditedPostAttribute( 'status' ),
		[]
	);
	const [ proposal, setProposal ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	if ( ! input || def.fix === 'none' || ( res.status !== 'fail' && res.status !== 'warn' ) ) {
		return null;
	}

	let rule = null;
	if ( def.fix === 'rule' ) {
		rule = ruleFix( def.id, {
			keyword,
			slug: input.slug,
			title: input.title,
			metaTitle: input.meta_title,
			metaDescription: input.meta_description,
			status,
			preset: input.preset,
		} );
		if ( ! rule ) {
			return null;
		}
	} else if ( ! keyword ) {
		return null;
	}

	const requestAi = async () => {
		setBusy( true );
		setError( null );
		// Read the editor now, not the last debounced snapshot.
		const content = registry.select( 'core/editor' ).getEditedPostContent();
		const paras = paragraphs( content );
		let target = '';
		if ( def.id === 'kw_in_intro' ) {
			target = paras[ 0 ] || '';
		} else if ( def.id === 'kw_in_conclusion' ) {
			target = paras[ paras.length - 1 ] || '';
		}
		if ( target && ! findParagraphBlock( registry, target ) ) {
			setError( __( 'That paragraph is not a paragraph block, so it cannot be fixed automatically.', 'stratawp-seo' ) );
			setBusy( false );
			return;
		}
		try {
			const r = await apiFetch( {
				path: '/swps/v1/editor/fix',
				method: 'POST',
				data: {
					post_id: postId,
					check_id: def.id,
					keyword,
					title: input.title,
					meta_title: input.meta_title,
					meta_description: input.meta_description,
					content_html: content,
					lang: input.lang,
					target_text: target,
				},
			} );
			setProposal( r.proposal );
		} catch ( e ) {
			setError( e && e.message ? e.message : String( e ) );
		}
		setBusy( false );
	};

	const onApply = () => {
		const r = apply( proposal );
		if ( r.ok ) {
			setProposal( null );
		} else {
			setError( r.message );
		}
	};

	const cost = ( window.swpsEditor || {} ).fixCost;
	const label = rule
		? __( 'Fix', 'stratawp-seo' )
		: `${ __( 'Fix with AI', 'stratawp-seo' ) }${ cost ? ` (about $${ cost })` : '' }`;

	return (
		<div className="swps-fix">
			{ ! proposal && (
				<Button
					variant="secondary"
					size="small"
					disabled={ busy }
					isBusy={ busy }
					aria-label={ `${ __( 'Fix', 'stratawp-seo' ) }: ${ def.label }` }
					onClick={ rule ? () => setProposal( { ...rule, check_id: def.id } ) : requestAi }
				>
					{ label }
				</Button>
			) }
			{ proposal && (
				<FixDiff proposal={ proposal } onApply={ onApply } onDismiss={ () => setProposal( null ) } />
			) }
			{ error && (
				<Notice status="warning" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
		</div>
	);
}
```

`src/editor/components/AnswerButton.js`:

```js
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useRegistry, useSelect } from '@wordpress/data';
import { Button, Notice } from '@wordpress/components';
import FixDiff from './FixDiff';
import { useApplyFix } from '../hooks/useApplyFix';

export default function AnswerButton( { question, keyword, input } ) {
	const registry = useRegistry();
	const apply = useApplyFix();
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const [ proposal, setProposal ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const request = async () => {
		setBusy( true );
		setError( null );
		try {
			const r = await apiFetch( {
				path: '/swps/v1/editor/fix',
				method: 'POST',
				data: {
					post_id: postId,
					check_id: 'aeo_answer',
					keyword,
					question,
					title: input ? input.title : '',
					content_html: registry.select( 'core/editor' ).getEditedPostContent(),
					lang: input ? input.lang : 'en',
				},
			} );
			setProposal( { ...r.proposal, heading: question } );
		} catch ( e ) {
			setError( e && e.message ? e.message : String( e ) );
		}
		setBusy( false );
	};

	const cost = ( window.swpsEditor || {} ).fixCost;

	return (
		<div className="swps-fix">
			{ ! proposal && (
				<Button variant="secondary" size="small" isBusy={ busy } disabled={ busy } onClick={ request }>
					{ __( 'Add an answer', 'stratawp-seo' ) }{ cost ? ` (about $${ cost })` : '' }
				</Button>
			) }
			{ proposal && (
				<FixDiff
					proposal={ proposal }
					onApply={ () => {
						const r = apply( proposal );
						if ( r.ok ) {
							setProposal( null );
						} else {
							setError( r.message );
						}
					} }
					onDismiss={ () => setProposal( null ) }
				/>
			) }
			{ error && (
				<Notice status="warning" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
		</div>
	);
}
```

- [ ] **Step 8: Mount the fix buttons**

In `src/editor/components/Sidebar.js` add imports `FixButton` and `AnswerButton`, then:
- on the SEO checks `ChecksPanel` add:

```js
							renderAction={ ( def, res, keyword ) => (
								<FixButton def={ def } res={ res } keyword={ keyword } input={ analysis.input } />
							) }
```
- on the Readability `ChecksPanel` add the same `renderAction` (readability checks have `fix: 'none'`, so it renders nothing, but keeps the contract uniform).
- on `AiVisibilityPanel` add:

```js
							renderQueryAction={ ( sq ) => (
								<AnswerButton question={ sq.q } keyword={ focus } input={ analysis.input } />
							) }
```

Append to `src/editor/style.scss`:

```scss
.swps-check { flex-wrap: wrap; }
.swps-fix { flex-basis: 100%; margin-top: 4px; }
.swps-diff {
	padding: 8px;
	border: 1px solid #dcdcde;
	border-radius: 2px;
	background: #fff;

	&__before, &__after { display: flex; flex-direction: column; gap: 2px; margin-bottom: 8px; }
	&__tag { color: #50575e; font-size: 11px; text-transform: uppercase; }
	del { color: #8a2424; background: #fcf0f1; text-decoration: line-through; }
	ins { color: #007017; background: #edfaef; text-decoration: none; }
	&__actions { display: flex; gap: 8px; }
}
```

- [ ] **Step 9: Verify**

Run: `npm run test:js && npm run build && composer analyze && composer test`
Expected: all green.

Manual: new draft, focus keyword `cold brew coffee`, title `Brewing basics`.
1. "Keyword in the URL slug" shows `Fix`. Click, the diff shows `untitled` to `cold-brew-coffee`. Apply: the slug field in the document panel updates. Press Cmd+Z: the slug reverts.
2. "Keyword in the introduction" shows `Fix with AI (about $...)`. Click: a diff appears. Before clicking Apply, edit the first paragraph in the canvas: click Apply, expect the "The paragraph changed" message and no change to the post. Request again and Apply: the paragraph text updates; Cmd+Z restores it.
3. Publish the post: "Keyword in the URL slug" shows no `Fix` button (published URLs are never rewritten).
4. In AI visibility, a "missing" question shows `Add an answer`. Apply inserts an H3 and a paragraph after the selected block (or at the end when nothing is selected); Cmd+Z removes both.
5. Set the monthly budget below current spend: `Fix with AI` shows the budget message in a notice, and no provider call is made (confirm with the cost report).

- [ ] **Step 10: Commit**

```bash
git add includes/editor/class-editor-fix.php includes/editor/class-editor-rest.php includes/editor/class-editor-sidebar.php stratawp-seo.php tests/unit/EditorFixTest.php src/editor admin/editor
git commit -m "Add Fix flow: rule fixes, validated AI fixes and Add an answer"
```

---

### Task 9: Proof snapshot hook

**Files:**
- Create: `includes/editor/class-editor-snapshots.php`, `tests/unit/EditorSnapshotsTest.php`
- Modify: `includes/editor/class-editor-rest.php` (applied route), `stratawp-seo.php` (require and instantiate)

**Interfaces:**
- Consumes: `SWPS_Editor_Input::for_post()` (Task 4), `SWPS_Editor_Check_Engine::run()/score()` (Task 2), `SWPS_Search_Console::is_connected()/get_page_queries()`, the `useApplyFix` call to `POST /swps/v1/editor/applied` (Task 8).
- Produces:
  - `SWPS_Editor_Snapshots::add_pending(mixed $existing, string $check_id): array` (unique, newest 20), `::build(int $score, array $fixes, ?array $gsc, ?int $aeo, int $time): array`, `::append(mixed $existing, array $snapshot, int $cap = 20): array`, `::pick_keyword_row(array $rows, string $keyword): ?array` (`{clicks, impressions, position}`).
  - Post meta `_swps_pending_fixes` (string[]) and `_swps_editor_snapshots` (list of `{time, score, fixes, aeo, gsc}`, newest last, max 20).
  - Route `POST /swps/v1/editor/applied` body `{post_id, check_id}` returns `{ok: true}`.
  - Storage only. The before/after strip and reports are a separate spec.

A snapshot is written when a post that has pending fixes is next saved while published, after its meta is saved (`rest_after_insert_{type}` fires after REST meta updates; `wp_after_insert_post` fires before them).

- [ ] **Step 1: Write the failing tests**

`tests/unit/EditorSnapshotsTest.php`:

```php
<?php
/**
 * Tests for the pure helpers in SWPS_Editor_Snapshots.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/editor/class-editor-snapshots.php';

final class EditorSnapshotsTest extends TestCase {

	public function test_add_pending_is_unique_and_ordered(): void {
		$list = SWPS_Editor_Snapshots::add_pending( array( 'kw_in_slug' ), 'kw_in_title' );
		$list = SWPS_Editor_Snapshots::add_pending( $list, 'kw_in_slug' );

		$this->assertSame( array( 'kw_in_slug', 'kw_in_title' ), $list );
	}

	public function test_add_pending_caps_at_twenty_newest(): void {
		$list = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$list = SWPS_Editor_Snapshots::add_pending( $list, 'fix_' . $i );
		}

		$this->assertCount( 20, $list );
		$this->assertSame( 'fix_6', $list[0] );
		$this->assertSame( 'fix_25', $list[19] );
	}

	public function test_add_pending_accepts_garbage(): void {
		$this->assertSame( array( 'a' ), SWPS_Editor_Snapshots::add_pending( null, 'a' ) );
		$this->assertSame( array( 'a' ), SWPS_Editor_Snapshots::add_pending( 'junk', 'a' ) );
	}

	public function test_build_dedupes_fixes_and_keeps_nulls(): void {
		$snap = SWPS_Editor_Snapshots::build( 82, array( 'a', 'b', 'a' ), null, null, 1700000000 );

		$this->assertSame(
			array(
				'time'  => 1700000000,
				'score' => 82,
				'fixes' => array( 'a', 'b' ),
				'aeo'   => null,
				'gsc'   => null,
			),
			$snap
		);
	}

	public function test_append_keeps_only_the_newest_snapshots(): void {
		$list = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$list = SWPS_Editor_Snapshots::append( $list, array( 'time' => $i ), 3 );
		}

		$this->assertSame( array( 3, 4, 5 ), array_column( $list, 'time' ) );
		$this->assertSame( array( array( 'time' => 1 ) ), SWPS_Editor_Snapshots::append( 'junk', array( 'time' => 1 ) ) );
	}

	public function test_pick_keyword_row_matches_case_insensitively(): void {
		$rows = array(
			array( 'keys' => array( 'iced coffee' ), 'clicks' => 3, 'impressions' => 90, 'position' => 12.34 ),
			array( 'keys' => array( 'Cold Brew Coffee' ), 'clicks' => 11, 'impressions' => 400, 'position' => 8.76 ),
		);

		$this->assertSame(
			array( 'clicks' => 11, 'impressions' => 400, 'position' => 8.8 ),
			SWPS_Editor_Snapshots::pick_keyword_row( $rows, 'cold brew coffee' )
		);
		$this->assertNull( SWPS_Editor_Snapshots::pick_keyword_row( $rows, 'nitro' ) );
		$this->assertNull( SWPS_Editor_Snapshots::pick_keyword_row( array(), 'nitro' ) );
	}
}
```

Run: `vendor/bin/phpunit tests/unit/EditorSnapshotsTest.php`
Expected: FAIL (missing class file).

- [ ] **Step 2: Write the snapshot class**

`includes/editor/class-editor-snapshots.php`:

```php
<?php
/**
 * Proof snapshots: remember which fixes were applied to a post and what its
 * score and search numbers were when it was next saved while published.
 * Storage only; reporting is a separate feature.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Snapshots {

	public const META_PENDING   = '_swps_pending_fixes';
	public const META_SNAPSHOTS = '_swps_editor_snapshots';
	public const CAP            = 20;

	private SWPS_Search_Console $gsc;

	public function __construct( SWPS_Search_Console $gsc ) {
		$this->gsc = $gsc;
		add_action( 'init', array( $this, 'register' ), 30 );
	}

	// ---------------------------------------------------------------------
	// Pure helpers (unit tested without WordPress).
	// ---------------------------------------------------------------------

	/**
	 * @param mixed $existing Stored list.
	 * @return string[]
	 */
	public static function add_pending( $existing, string $check_id ): array {
		$list = array();
		if ( is_array( $existing ) ) {
			foreach ( $existing as $id ) {
				if ( is_string( $id ) && '' !== $id ) {
					$list[] = $id;
				}
			}
		}
		if ( ! in_array( $check_id, $list, true ) ) {
			$list[] = $check_id;
		}
		return array_slice( $list, -self::CAP );
	}

	/**
	 * @param string[]                                           $fixes Applied check ids.
	 * @param array{clicks:int,impressions:int,position:float}|null $gsc  Search Console numbers.
	 * @return array<string,mixed>
	 */
	public static function build( int $score, array $fixes, ?array $gsc, ?int $aeo, int $time ): array {
		return array(
			'time'  => $time,
			'score' => $score,
			'fixes' => array_values( array_unique( $fixes ) ),
			'aeo'   => $aeo,
			'gsc'   => $gsc,
		);
	}

	/**
	 * @param mixed                $existing Stored snapshots.
	 * @param array<string,mixed>  $snapshot New snapshot.
	 * @return array<int,array<string,mixed>>
	 */
	public static function append( $existing, array $snapshot, int $cap = self::CAP ): array {
		$list   = is_array( $existing ) ? array_values( $existing ) : array();
		$list[] = $snapshot;
		return array_slice( $list, -$cap );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows    Search Console page-query rows.
	 * @return array{clicks:int,impressions:int,position:float}|null
	 */
	public static function pick_keyword_row( array $rows, string $keyword ): ?array {
		$needle = mb_strtolower( trim( $keyword ) );
		foreach ( $rows as $row ) {
			if ( mb_strtolower( (string) ( $row['keys'][0] ?? '' ) ) === $needle && '' !== $needle ) {
				return array(
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				);
			}
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// WordPress wiring.
	// ---------------------------------------------------------------------

	public function register(): void {
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
			add_action( "rest_after_insert_{$type}", array( $this, 'maybe_snapshot' ), 20, 3 );
		}
	}

	/**
	 * @param WP_Post         $post     Saved post.
	 * @param WP_REST_Request $request  Request.
	 * @param bool            $creating Whether the post was just created.
	 */
	public function maybe_snapshot( $post, $request, $creating ): void {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		$pending = get_post_meta( $post->ID, self::META_PENDING, true );
		if ( ! is_array( $pending ) || ! $pending ) {
			return;
		}

		$input = SWPS_Editor_Input::for_post( $post );
		$score = SWPS_Editor_Check_Engine::score( SWPS_Editor_Check_Engine::run( $input ) );
		$focus = (string) $input['keywords'][0]['keyword'];

		$gsc = null;
		if ( '' !== $focus && $this->gsc->is_connected() ) {
			$url = get_permalink( $post );
			if ( $url ) {
				$gsc = self::pick_keyword_row( $this->gsc->get_page_queries( $url, 28 ), $focus );
			}
		}

		$aeo_total = get_post_meta( $post->ID, SWPS_AEO_Scorer::META_TOTAL, true );
		$snapshot  = self::build( $score['overall'], $pending, $gsc, '' === $aeo_total ? null : (int) $aeo_total, time() );

		update_post_meta(
			$post->ID,
			self::META_SNAPSHOTS,
			self::append( get_post_meta( $post->ID, self::META_SNAPSHOTS, true ), $snapshot )
		);
		delete_post_meta( $post->ID, self::META_PENDING );
	}
}
```

Run: `vendor/bin/phpunit tests/unit/EditorSnapshotsTest.php`
Expected: PASS (6 tests).

- [ ] **Step 3: Add the applied route**

In `includes/editor/class-editor-rest.php`, add to `register_routes()`:

```php
		$check_ids = array_merge(
			array_column( SWPS_Editor_Check_Registry::all(), 'id' ),
			array( 'aeo_answer' )
		);

		register_rest_route(
			self::NS,
			'/editor/applied',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'applied' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'post_id'  => $post_id,
					'check_id' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => $check_ids,
					),
				),
			)
		);
```

and the method:

```php
	/**
	 * Remember that a fix was applied so the next published save can snapshot it.
	 *
	 * @return WP_REST_Response
	 */
	public function applied( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		update_post_meta(
			$post_id,
			SWPS_Editor_Snapshots::META_PENDING,
			SWPS_Editor_Snapshots::add_pending( get_post_meta( $post_id, SWPS_Editor_Snapshots::META_PENDING, true ), (string) $request['check_id'] )
		);
		return rest_ensure_response( array( 'ok' => true ) );
	}
```

- [ ] **Step 4: Wire it up**

In `stratawp-seo.php` add after the fix class require: `require_once SWPS_PLUGIN_DIR . 'includes/editor/class-editor-snapshots.php';`, and after the `new SWPS_Editor_Rest( ... );` call add:

```php
		new SWPS_Editor_Snapshots( $this->search_console );
```

- [ ] **Step 5: Verify**

Run: `php -l includes/editor/class-editor-snapshots.php && composer analyze && composer test && npm run build`
Expected: clean.

Manual: on a published post with a focus keyword, apply a rule fix (for example shorten a long SEO title) and click Update. Then run `wp post meta get <ID> _swps_editor_snapshots --format=json`. Expected: one snapshot with `fixes` containing `title_length`, a numeric `score`, and `gsc` either `null` or `{clicks, impressions, position}`; `wp post meta get <ID> _swps_pending_fixes` is empty. Click Update again without applying a fix: no new snapshot. Apply a fix to a draft and save it: `_swps_pending_fixes` keeps the id and no snapshot exists until the post is saved while published.

- [ ] **Step 6: Commit**

```bash
git add includes/editor/class-editor-snapshots.php includes/editor/class-editor-rest.php stratawp-seo.php tests/unit/EditorSnapshotsTest.php
git commit -m "Record proof snapshots when a post with applied fixes is saved while published"
```

---

### Task 10: CI, release packaging, smoke test, docs and version

**Files:**
- Create: `e2e/editor-sidebar.spec.js`, `playwright.config.js`
- Modify: `.github/workflows/quality.yml`, `.github/workflows/release.yml`, `bin/build-zip.sh`, `package.json` (script), `stratawp-seo.php`, `readme.txt`, `README.md`

**Interfaces:**
- Consumes: everything above. Produces release 4.32.0.

- [ ] **Step 1: Add the JS job to code-quality CI**

In `.github/workflows/quality.yml`, add this job after `unit-tests` (same indentation as the other jobs):

```yaml
  editor-js:
    name: Editor sidebar (Jest and committed build)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
      - run: npm ci
      - name: Jest
        run: npm run test:js
      - name: Committed build matches sources
        run: npm run verify:build
```

- [ ] **Step 2: Make the release build the sidebar**

In `.github/workflows/release.yml`:
- add `- 'src/**'`, `- 'package.json'` and `- 'package-lock.json'` to the `paths:` list;
- add these two steps directly before the `Build release zips` step:

```yaml
      - name: Set up Node
        if: steps.exists.outputs.exists == 'false'
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm

      - name: Build the editor sidebar and confirm it matches what is committed
        if: steps.exists.outputs.exists == 'false'
        run: |
          npm ci
          npm run build
          git diff --exit-code -- admin/editor
```

- [ ] **Step 3: Make the zip script refuse a zip without the bundle**

In `bin/build-zip.sh`, directly after the line `has "admin/fonts/"           || fail "bundled fonts missing"` add:

```bash
has "admin/editor/index.js"  || fail "editor sidebar bundle missing"
has "admin/editor/index.asset.php" || fail "editor sidebar asset manifest missing"
```

and add `--exclude='/playwright.config.js'` to the `wporg` exclude block (next to `--exclude='/e2e'`).

Run: `./bin/build-zip.sh --target github && ./bin/build-zip.sh --target wporg`
Expected: both print their invariant summary and no `FAIL`. Do not commit anything under `build/`.

- [ ] **Step 4: Write the smoke test**

`playwright.config.js`:

```js
module.exports = {
	testDir: 'e2e',
	timeout: 90000,
	use: { baseURL: process.env.WP_BASE_URL, headless: true },
};
```

`e2e/editor-sidebar.spec.js`:

```js
// Manual smoke test against a local site with the plugin active and the
// sidebar enabled (wp option update swps_editor_sidebar 1).
// WP_BASE_URL=http://site.local WP_USER=admin WP_PASS=... npm run test:e2e
const { test, expect } = require( '@playwright/test' );

test( 'sidebar scores live, applies a rule fix and undoes it', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', process.env.WP_USER );
	await page.fill( '#user_pass', process.env.WP_PASS );
	await page.click( '#wp-submit' );

	await page.goto( '/wp-admin/post-new.php' );
	const close = page.getByRole( 'button', { name: 'Close' } );
	if ( await close.count() ) {
		await close.first().click();
	}

	await page.getByRole( 'textbox', { name: 'Add title' } ).fill( 'Cold brew coffee guide' );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.type( 'Cold brew coffee is easy to make at home.' );

	// First match is the pinned top-bar button.
	await page.getByRole( 'button', { name: 'StrataWP SEO' } ).first().click();
	await page.getByLabel( 'Focus keyword' ).fill( 'cold brew coffee' );

	// The score appears within the debounce window.
	await expect( page.getByTestId( 'swps-score' ).first() ).toBeVisible();

	const slugRow = page.locator( '[data-check="kw_in_slug"]' );
	await expect( slugRow ).toHaveClass( /swps-check--fail/ );

	await page.getByRole( 'button', { name: 'Fix: Keyword in the URL slug' } ).click();
	await expect( page.getByTestId( 'swps-diff' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Apply' } ).click();
	await expect( slugRow ).toHaveClass( /swps-check--pass/ );

	await page.getByRole( 'button', { name: 'Undo' } ).click();
	await expect( slugRow ).toHaveClass( /swps-check--fail/ );
} );
```

Run: `npm pkg set scripts.test:e2e="playwright test"` then `npx playwright install chromium` once. Run the test with the environment variables above against the local site.
Expected: PASS. If the slug row never reaches `pass`, open the sidebar in a headed run (`npx playwright test --headed`) and check the browser console for errors from `swps-editor`.

- [ ] **Step 5: Bump the version in all three places**

- `stratawp-seo.php`: header ` * Version: 4.32.0` and `define( 'SWPS_VERSION', '4.32.0' );`
- `readme.txt`: `Stable tag: 4.32.0`, and add directly under `== Changelog ==`:

```
= 4.32.0 =
* New: a block editor sidebar replaces the SEO and AEO metaboxes in the block editor. It analyses as you type (SEO checks and readability, grouped into problems, improvements and good), supports a focus keyword plus up to four related keywords, previews the search result on desktop and mobile, and shows the AI visibility score, the questions an answer engine would ask, schema found in the content and AI citation status for the post.
* New: Fix buttons. Deterministic fixes (keyword slug for drafts, trimming a long title or description) apply instantly; AI fixes (keyword in the title, description, introduction or conclusion, and "Add an answer" for missing AI visibility questions) show a before and after diff and write nothing until you press Apply. Every fix goes through the editor, so Undo reverses it. AI fixes respect the monthly AI budget and are cost tracked.
* New: when a post with applied fixes is next saved while published, the plugin stores a snapshot of its score and search numbers so later releases can show before and after results.
* Changed: new installs use the sidebar by default. Existing sites keep the classic metaboxes until they choose "Turn it on" in the notice shown in the block editor. "Use the classic editor panel instead" in the sidebar's Advanced panel switches back.
* Changed: the compiled sidebar is built with @wordpress/scripts; sources are in src/editor and the compiled files are in admin/editor.
```

- [ ] **Step 6: Update the README**

In `README.md`: change the version badge to `4.32.0`; in the Feature Tour add a subsection "Editor sidebar" under the AI Content Engine section with the same bullets as the changelog above (live analysis, related keywords, search preview, AI visibility panel, Fix buttons with diff and Undo, rollout behaviour); add a `### v4.32.0 (October 2026)` entry at the top of the Changelog section with the same text. Use no em dashes or en dashes in the new text.

- [ ] **Step 7: Full verification**

Run:

```bash
composer analyze && composer test
npm run test:js && npm run verify:build
./bin/build-zip.sh --target github && ./bin/build-zip.sh --target wporg
unzip -l build/stratawp-seo-wporg.zip | grep -E "admin/editor/index\.(js|css|asset\.php)|src/editor/index\.js"
```

Expected: all green; the wp.org zip lists the three compiled files and the source entry; it lists no `tests/`, `e2e/` or `node_modules`.

Screenshot: with the sidebar open on a post that has a focus keyword and a few failing checks, save a 1280 by 900 capture as `screenshots/swps-editor-sidebar.png` and reference it in the README subsection from Step 6 with `![Editor sidebar](screenshots/swps-editor-sidebar.png)`. Add `screenshots/swps-editor-sidebar.png` to the Step 8 `git add` list.

Final manual pass on a clean local WordPress:
1. Fresh activation: the sidebar is on, classic metaboxes are gone in the block editor.
2. Upgrade path: set `wp option delete swps_installed_version swps_editor_sidebar`, reload any admin page, then `wp option get swps_editor_sidebar` returns `0`; the notice appears; "Turn it on" enables it; Advanced, "Use the classic editor panel instead" returns to the metaboxes.
3. Classic Editor plugin active: the classic metaboxes show and no sidebar errors appear.
4. Yoast active alongside: meta output stays disabled as before; the sidebar still opens.
5. The review-focus checks: a brand new post shows no wall of red (keyword checks listed under Not analyzed); a Japanese post shows "Not analyzed" for sentence length; a post containing `[gallery]` shortcodes counts words correctly.

- [ ] **Step 8: Commit**

```bash
git add .github/workflows/quality.yml .github/workflows/release.yml bin/build-zip.sh package.json package-lock.json playwright.config.js e2e stratawp-seo.php readme.txt README.md
git commit -m "Release 4.32.0: block editor sidebar"
```

Do not push or open a pull request until asked. When the branch is ready, use `superpowers:finishing-a-development-branch`.

