# 5-Minute Onboarding Wizard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A guided first-run flow — detect & preview Yoast/RankMath migration, validate an AI API key (wiring the existing-but-unused `test_key()`), AI-suggest the site niche/description, run the audit, generate a preview post — ending on a concrete win, with a persistent dashboard setup checklist until complete.

**Architecture:** One new class `SWPS_Onboarding` (hidden admin page, activation redirect, AJAX endpoints, state option `swps_onboarding_state`, pure static step helpers) + `templates/onboarding-page.php` + `admin/js/onboarding.js` + `admin/css/onboarding.css`. The wizard *sequences existing backends*; the only new logic is state tracking and the key-test/niche-suggest endpoints. The migration RUN is deep-linked to the existing Migration page (avoids wizard-embedded timeouts); the wizard shows detection + preview counts only. Dashboard checklist renders from the state option.

**Branch:** `feature/onboarding-wizard`, stacked on `feature/weekly-digest` (HEAD adfb31e). Version 4.12.0 → 4.13.0 at the end. PR base: `main` if PR #52 has merged by then, else `feature/weekly-digest`.

**Conventions:** no autoloader (require_once), files <500 lines, tabs/WPCS, no Co-Authored-By, all new AJAX = `manage_options` + nonce, version bump + changelogs at the end.

---

## Verified backends (HEAD adfb31e)

- `SWPS_Migration::detect_sources(): array` (:102), `preview( string $source, string $conflict_policy, array $phases ): array` (:138) — read the class header + these methods to learn the source ids, policies, and phases arrays and the preview return shape before use. Migration page slug: find via `grep -n "swps-migration\|add_submenu_page" includes/class-migration.php includes/class-admin-shell.php`.
- `SWPS_AI_Provider::test_key( string $api_key ): bool|WP_Error` — abstract, implemented by all four providers (:171 in anthropic). Provider construction: read `SWPS_Provider_Factory` for how a provider is created and how API keys are stored/read (check for an encryption layer — `grep -n "encrypt\|swps_api_key" includes/class-provider-factory.php includes/class-encryption.php includes/class-settings.php | head -20`). The wizard must SAVE the key through the same path settings uses (sanitize callback may encrypt!).
- `SWPS_Analyzer::get_site_summary( int $max_posts = 50 ): array` (:29), `get_site_info()`, `get_categories()`. Instance available at `stratawp_seo()->analyzer` (verify property name).
- `SWPS_SEO_Audit::run_all(): array` (:86), `fix_module( string $id )` (:139). Audit page slug + existing fix-all AJAX: `grep -n "swps_fix_all\|swps-audit" admin/js/admin.js includes/class-seo-audit.php | head`.
- `SWPS_Generator::preview_content( string $topic = '', string $template = 'auto' )` — rate-limited, returns array|WP_Error. Note: generation is gated on the budget (guardian) — surface a clear error message if blocked.
- Dashboard: `SWPS_Dashboard::get_summary_data()` + `templates/dashboard-page.php` tile/`$data` pattern; menu parent slug `stratawp-seo` (add_menu_page in class-dashboard.php:52).
- Activation: `swps_activate()` at stratawp-seo.php:1196 (defaults array with swps_ prefix loop).
- Admin shell chrome: see how other pages render inside the shell — read `templates/audit-page.php` top lines + how its assets enqueue (`grep -n "audit" includes/class-admin-shell.php includes/class-seo-audit.php | grep -i enqueue`); match that pattern for the wizard page + its JS/CSS.

---

### Task 0: Branch check
`git branch --show-current` must print feature/onboarding-wizard (already created). `vendor/bin/phpunit` baseline: 117 passing.

### Task 1: State helpers (TDD)
Create `includes/class-onboarding.php` with ABSPATH guard, constants, and pure statics; test-first in `tests/unit/OnboardingTest.php` (tabs, full docblocks):

```php
	public const OPTION_STATE = 'swps_onboarding_state';
	public const STEPS        = array( 'migrate', 'api_key', 'site_info', 'audit', 'preview' );

	/**
	 * Normalize a stored state array: unknown keys dropped, known steps cast to bool,
	 * 'dismissed' preserved.
	 *
	 * @param mixed $raw Raw option value.
	 * @return array{steps: array<string, bool>, dismissed: bool}
	 */
	public static function normalize_state( $raw ): array {
		$steps = array();
		$raw   = is_array( $raw ) ? $raw : array();
		$saved = isset( $raw['steps'] ) && is_array( $raw['steps'] ) ? $raw['steps'] : array();
		foreach ( self::STEPS as $step ) {
			$steps[ $step ] = ! empty( $saved[ $step ] );
		}
		return array(
			'steps'     => $steps,
			'dismissed' => ! empty( $raw['dismissed'] ),
		);
	}

	/**
	 * @param array{steps: array<string, bool>} $state Normalized state.
	 * @return array{done: int, total: int, complete: bool, next: string|null}
	 */
	public static function progress( array $state ): array {
		$done = count( array_filter( $state['steps'] ) );
		$next = null;
		foreach ( self::STEPS as $step ) {
			if ( empty( $state['steps'][ $step ] ) ) {
				$next = $step;
				break;
			}
		}
		return array(
			'done'     => $done,
			'total'    => count( self::STEPS ),
			'complete' => count( self::STEPS ) === $done,
			'next'     => $next,
		);
	}
```

Tests: normalize drops junk keys/casts/missing→false/dismissed; progress counts, next = first incomplete in STEPS order, complete when all done, next null when complete. Commit `feat: onboarding state helpers`.

### Task 2: Class behavior — page, redirect, AJAX
Add to SWPS_Onboarding (constructor hooks):
- `admin_menu` (priority 70): hidden page — `add_submenu_page( '', ... )` is deprecated-ish; use parent slug `'stratawp-seo'` then REMOVE it from the menu via `remove_submenu_page` on a later hook, OR follow however the plugin registers any hidden page already (grep `remove_submenu_page|add_submenu_page( ''` first; match precedent; if none, register under 'stratawp-seo' visible as "Setup Wizard" — visible is acceptable and simpler; decide and report). Page slug `swps-onboarding`, cap `manage_options`, renders `templates/onboarding-page.php` inside the same chrome other pages use.
- Activation redirect: in `swps_activate()` add `set_transient( 'swps_onboarding_redirect', 1, 60 );` ONLY when `get_option( self::OPTION_STATE ) === false` (fresh install, not upgrade/re-activation). In the class, on `admin_init`: if transient present → delete it; bail on: `wp_doing_ajax()`, `is_network_admin()`, `isset( $_GET['activate-multi'] )`, `defined('WP_CLI') && WP_CLI`, `! current_user_can('manage_options')`; else `wp_safe_redirect( admin_url( 'admin.php?page=swps-onboarding' ) ); exit;`
- AJAX endpoints (ALL: `check_ajax_referer( 'swps_onboarding', 'nonce' )` + manage_options; wp_send_json_success/error):
  - `swps_onboarding_status`: returns normalize_state + progress + detection info (`SWPS_Migration::detect_sources()`).
  - `swps_onboarding_test_key`: POST provider + api_key; create that provider via the factory (or directly construct with the key — read how factory injects keys; you may need a small factory method accepting an explicit key — check for one first), call `test_key`; on success SAVE provider choice + key through the SAME option names + sanitization settings uses (encryption-aware), mark step api_key done.
  - `swps_onboarding_suggest_site_info`: uses analyzer summary + provider chat() for a 1-2 sentence site description suggestion (fail-soft); does NOT save.
  - `swps_onboarding_save_site_info`: POST description (sanitize_textarea_field) → save to the same option the settings Site Details section uses (find the option name), mark site_info done.
  - `swps_onboarding_run_audit`: calls run_all(), stores nothing new (audit stores its own results), marks audit done, returns issue counts + audit page URL.
  - `swps_onboarding_preview`: POST optional topic → `preview_content()`; returns title + meta_description + first ~600 chars of content_html (wp_kses_post'd); marks preview done. Budget/rate-limit WP_Errors → clean error message.
  - `swps_onboarding_step_done`: POST step (whitelisted vs STEPS) — used for migrate (deep-linked) and skip buttons; sets the step true.
  - `swps_onboarding_dismiss`: sets dismissed (used by dashboard checklist).
- `mark_step( string $step ): void` private helper (normalize → set → update_option autoload false).
Commit `feat: onboarding wizard page, redirect, and AJAX backends`.

### Task 3: Template + JS + CSS
- `templates/onboarding-page.php`: five-step vertical stepper. Each step card: title, body, primary action, "Skip" link. Step 1 (migrate): server-rendered detection (sources found or "nothing to migrate" auto-skip state), preview counts via JS on demand, link to the real Migration page + "I'm done / skip" → step_done. Step 2 (api key): provider select (anthropic/openai/google/xai), password input, Test & Save button, success/error inline. Step 3: textarea pre-filled by "Suggest with AI" button, Save. Step 4: Run audit button + spinner + results summary (X passed / Y issues) + "Open audit" link. Step 5: topic input (optional) + Generate preview + rendered preview card + cost note ("uses your API key; one generation"). Final state (all done): win panel — audit issues count with "Fix now" link if >0, else the preview-post panel, else schema/llms.txt pointers. Escape everything.
- `admin/js/onboarding.js`: vanilla JS (match the plugin's existing JS style — check admin/js/admin.js), `fetch`/jQuery.post to ajaxurl with the nonce (localized via wp_localize_script as other pages do — find the enqueue pattern), step transitions, button spinners, error rendering.
- `admin/css/onboarding.css`: stepper styles consistent with the swps admin look (reuse CSS vars like var(--swps-*) seen in dashboard CSS).
- Enqueue both only on the wizard page (hook check like other pages).
Commit `feat: onboarding wizard UI`.

### Task 4: Dashboard checklist + wiring + release
- class-dashboard.php: `'onboarding' => $this->safe_get_onboarding()` → normalize_state + progress; templates/dashboard-page.php: when NOT complete and NOT dismissed, render a checklist card at the TOP of the dashboard: "Setup X/5 complete" + per-step links into the wizard + dismiss control (calls swps_onboarding_dismiss; simple inline JS or reuse dashboard JS file if one exists).
- stratawp-seo.php: require_once class-onboarding.php; public property + instantiate; activation: the set_transient line + (no state default — absence of the option IS the fresh-install signal; do NOT seed swps_onboarding_state in defaults).
- uninstall: confirm swps_% wildcard covers swps_onboarding_state (it does — note only).
- Verification gate: phpunit (117 + new Onboarding tests), phpstan --memory-limit=2G no new errors, phpcs clean on all NEW files.
- Version 4.12.0 → 4.13.0 everywhere + changelog:
```
= 4.13.0 =
* New: 5-minute onboarding wizard — migrate from Yoast/Rank Math, validate your AI key, AI-suggest your site description, run the audit, and generate a preview post; setup checklist on the dashboard until complete.
* New: AI provider keys can now be validated from the UI (Test & Save).
```
- Commit `chore: release 4.13.0 — onboarding wizard`, commit the plan doc, do NOT push/PR (controller does after final review).

## Self-review checklist
1. Every AJAX endpoint: nonce + manage_options + sanitized inputs?
2. Key saved via the settings-equivalent path (encrypted if settings encrypt)?
3. Redirect guards: ajax/network/bulk/WP-CLI/capability + fresh-install-only transient?
4. class-onboarding.php < 500 lines (template/JS carry the UI)?
5. No step can wedge: every step skippable; wizard reachable anytime via URL?
6. Dashboard checklist disappears when complete or dismissed?
