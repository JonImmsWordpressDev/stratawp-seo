# Autopilot Guardian Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make unattended autopilot generation trustworthy: a monthly AI spend cap enforced before generation, retry-with-backoff for transient provider errors, automatic requeue of failed topics (capped attempts), a cron loop that survives individual failures, and a dashboard status strip.

**Architecture:** One new class `SWPS_Autopilot_Guardian` owns all guardian behavior: pure static helpers (budget state, error classification, retry delay — unit-testable without WordPress), the budget gate called from `SWPS_Generator::generate_post()`, topic-failure handling shared by `SWPS_Cron` and `SWPS_Background_Processor`, settings registration (kept out of the already-1765-line `class-settings.php`), the 80% warning notice, and run-summary recording. Providers gain machine-readable HTTP status in `WP_Error` data so transient (429/5xx/network) and permanent (auth) failures are distinguishable. Retries are rescheduled via the existing background processor — never `sleep()` — so shared-hosting `max_execution_time` is never at risk.

**Tech Stack:** PHP 8.0, WordPress plugin APIs (options, cron, Settings API), PHPUnit 9 pure-PHP unit tests (`tests/bootstrap.php` stubs, no WP load), PHPCS (WPCS), PHPStan level 5.

**Branch:** `feature/autopilot-guardian` off `origin/main` (currently 4.10.0, commit 88595e2).

**Project conventions that apply (from CLAUDE.md / memory):**
- No runtime autoloader — every new class MUST be `require_once`'d in `stratawp-seo.php`.
- Keep files under 500 lines.
- No `Co-Authored-By` trailers on commits.
- At the end: bump version (4.11.0), update README.md + readme.txt + docs, build the deployment zip.
- Run `composer test`, `composer phpstan` (or `vendor/bin/phpstan`), PHPCS before declaring done. Check `composer.json` scripts for exact names (`composer test` exists; check `lint`/`phpstan` script names with `composer run-script --list`).

---

## Existing code facts (verified 2026-06-10, main @ 88595e2)

- `SWPS_Cron::run_scheduled_generation()` ([includes/class-cron.php:57-106]) — the loop `break`s on first `WP_Error` (line 92), abandoning the rest of the batch; failed topics get status `failed` and are never retried.
- `SWPS_Cost_Tracker::track()` ([includes/class-cost-tracker.php:24-27]) returns early unless `swps_cost_tracking` option is truthy (defaults OFF). Monthly aggregates live in option `swps_cost_YYYY_m` (e.g. `swps_cost_2026_06`) with key `total_cost`. `get_monthly_stats()` is public.
- All four providers (`includes/providers/ai/class-{anthropic,openai,google,xai}-provider.php`) return `new WP_Error( 'swps_api_error', sprintf( ... status ... ) )` for non-200 — the status code is only inside the human message string. Network failures return code `swps_api_request_failed`. Missing key → `swps_no_api_key`. Key-validation failure → `swps_invalid_key`.
- `SWPS_Generator::generate_post()` ([includes/class-generator.php:43]) starts with a time-limit block (lines 44-53), then fires `SWPS_Hooks::do_before_generate` and the rate-limit check. Every failure path calls `SWPS_Hooks::do_generation_failed( $error, $topic, $template )`.
- `SWPS_Background_Processor::process_topic()` ([includes/class-background-processor.php:193-215]) sets topic status `failed` immediately on error; `schedule_generation( int $topic_id, int $delay )` schedules via Action Scheduler when available, else `wp_schedule_single_event`. The plugin singleton exposes `stratawp_seo()->background_processor` (public property, stratawp-seo.php:192).
- `SWPS_Topic_Queue::update_status( int $topic_id, string $status, string $error = '', int $post_id = 0 )` — statuses are free-form strings; `get_next_topic()` selects status `queued` with past-dated `post_date`.
- Settings: sections/fields registered on page slug `'stratawp-seo'`, option group `'stratawp-seo'` (`register_setting( 'stratawp-seo', ... )`, see class-settings.php:1067). The Auto-Publishing Schedule section id is `swps_schedule_section` (class-settings.php:596).
- Dashboard: `SWPS_Dashboard::get_summary_data()` aggregates `safe_get_*()` methods (class-dashboard.php:96-105), each wrapped in try/catch returning a safe default. Tiles in `templates/dashboard-page.php` use `<div class="swps-tile"><div class="swps-tile-h">…</div><div class="swps-tile-stat">…</div>…</div>`.
- `SWPS_Cron::get_schedule_info()` (class-cron.php:185) already returns enabled/frequency/next_run/last_run for display.
- Tests: `tests/unit/*Test.php`, pure PHP, no WordPress; `tests/bootstrap.php` defines minimal stubs including a `WP_Error` class that currently has NO `$data` support. PHPUnit config: `phpunit.xml.dist`. Run: `vendor/bin/phpunit` or `composer test`.
- Activation defaults are seeded in `swps_activate()` in `stratawp-seo.php` (search for `add_option`).

---

### Task 0: Branch setup

- [ ] **Step 1: Create the branch**

```bash
cd /Users/jon.imms/StrataWP-projects/stratawp-seo
git checkout -b feature/autopilot-guardian origin/main
```

Expected: `branch 'feature/autopilot-guardian' set up to track 'origin/main'.`

- [ ] **Step 2: Confirm clean baseline**

```bash
composer test
```

Expected: all tests PASS (if the suite fails on main, STOP and report — do not build on a broken baseline).

---

### Task 1: WP_Error test stub gains `$data` support

The classifier (Task 2) reads `WP_Error::get_error_data()`. The bootstrap stub doesn't have it.

**Files:**
- Modify: `tests/bootstrap.php` (the `WP_Error` stub class)

- [ ] **Step 1: Replace the WP_Error stub**

In `tests/bootstrap.php`, replace the existing `WP_Error` stub class body with:

```php
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        private $data;
        public function __construct( string $code = '', string $message = '', $data = null ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
```

- [ ] **Step 2: Run the suite to confirm nothing broke**

Run: `vendor/bin/phpunit`
Expected: PASS (same count as baseline).

- [ ] **Step 3: Commit**

```bash
git add tests/bootstrap.php
git commit -m "test: add data support to WP_Error stub"
```

---

### Task 2: SWPS_Autopilot_Guardian pure logic (TDD)

**Files:**
- Create: `includes/class-autopilot-guardian.php`
- Test: `tests/unit/AutopilotGuardianTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/AutopilotGuardianTest.php`:

```php
<?php
/**
 * Unit tests for SWPS_Autopilot_Guardian pure logic.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-autopilot-guardian.php';

class AutopilotGuardianTest extends TestCase {

    // ---- budget_state() ----

    public function test_budget_state_ok_when_no_budget(): void {
        $this->assertSame( 'ok', SWPS_Autopilot_Guardian::budget_state( 50.0, 0.0 ) );
    }

    public function test_budget_state_ok_under_warning_threshold(): void {
        $this->assertSame( 'ok', SWPS_Autopilot_Guardian::budget_state( 7.99, 10.0 ) );
    }

    public function test_budget_state_warning_at_80_percent(): void {
        $this->assertSame( 'warning', SWPS_Autopilot_Guardian::budget_state( 8.0, 10.0 ) );
    }

    public function test_budget_state_exceeded_at_budget(): void {
        $this->assertSame( 'exceeded', SWPS_Autopilot_Guardian::budget_state( 10.0, 10.0 ) );
    }

    public function test_budget_state_exceeded_over_budget(): void {
        $this->assertSame( 'exceeded', SWPS_Autopilot_Guardian::budget_state( 25.5, 10.0 ) );
    }

    // ---- is_transient_error() ----

    public function test_network_failure_is_transient(): void {
        $e = new WP_Error( 'swps_api_request_failed', 'cURL error 28' );
        $this->assertTrue( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_api_error_429_is_transient(): void {
        $e = new WP_Error( 'swps_api_error', 'Rate limited', array( 'status' => 429 ) );
        $this->assertTrue( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_api_error_500_is_transient(): void {
        $e = new WP_Error( 'swps_api_error', 'Server error', array( 'status' => 500 ) );
        $this->assertTrue( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_api_error_401_is_permanent(): void {
        $e = new WP_Error( 'swps_api_error', 'Unauthorized', array( 'status' => 401 ) );
        $this->assertFalse( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_api_error_without_status_is_transient(): void {
        // Back-compat: an error produced before providers attached status data.
        $e = new WP_Error( 'swps_api_error', 'Claude API error (503): overloaded' );
        $this->assertTrue( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_missing_key_is_permanent(): void {
        $e = new WP_Error( 'swps_no_api_key', 'Please enter your API key.' );
        $this->assertFalse( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_invalid_key_is_permanent(): void {
        $e = new WP_Error( 'swps_invalid_key', 'Invalid key.' );
        $this->assertFalse( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_duplicate_is_permanent(): void {
        $e = new WP_Error( 'swps_duplicate', 'Duplicate detected.' );
        $this->assertFalse( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_budget_exceeded_is_permanent(): void {
        $e = new WP_Error( 'swps_budget_exceeded', 'Budget exhausted.' );
        $this->assertFalse( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    public function test_empty_response_is_transient(): void {
        $e = new WP_Error( 'swps_empty_response', 'Empty response.' );
        $this->assertTrue( SWPS_Autopilot_Guardian::is_transient_error( $e ) );
    }

    // ---- retry_delay() ----

    public function test_retry_delay_first_attempt_15_minutes(): void {
        $this->assertSame( 900, SWPS_Autopilot_Guardian::retry_delay( 1 ) );
    }

    public function test_retry_delay_second_attempt_30_minutes(): void {
        $this->assertSame( 1800, SWPS_Autopilot_Guardian::retry_delay( 2 ) );
    }

    public function test_retry_delay_clamps_minimum_attempt(): void {
        $this->assertSame( 900, SWPS_Autopilot_Guardian::retry_delay( 0 ) );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/unit/AutopilotGuardianTest.php`
Expected: FAIL — `Failed opening required '.../includes/class-autopilot-guardian.php'`.

- [ ] **Step 3: Write the class with the pure static methods**

Create `includes/class-autopilot-guardian.php`. IMPORTANT: this file is loaded by the WP-less test bootstrap, so the top guard must not `exit` when ABSPATH is the fake test constant — keep the standard guard (the bootstrap defines `ABSPATH`, so the guard passes). Instance methods may call WP functions; tests only touch the statics.

```php
<?php
/**
 * Autopilot safety layer: budget caps, transient-error retry, topic requeue,
 * and run-summary recording for unattended generation.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Autopilot_Guardian {

	public const OPTION_BUDGET   = 'swps_monthly_budget';
	public const OPTION_LAST_RUN = 'swps_autopilot_last_run';

	public const MAX_ATTEMPTS = 3;

	/**
	 * Fraction of budget at which the warning notice fires.
	 */
	private const WARNING_RATIO = 0.8;

	/**
	 * Error codes that retrying cannot fix. Everything else is treated as
	 * transient — the attempt cap bounds the damage of a misclassification.
	 */
	private const PERMANENT_CODES = array(
		'swps_no_api_key',
		'swps_invalid_key',
		'swps_duplicate',
		'swps_budget_exceeded',
	);

	/**
	 * Classify spend against the budget.
	 *
	 * @param float $spent  USD spent this month.
	 * @param float $budget Monthly budget in USD; 0 disables budgeting.
	 * @return string 'ok' | 'warning' | 'exceeded'.
	 */
	public static function budget_state( float $spent, float $budget ): string {
		if ( $budget <= 0 ) {
			return 'ok';
		}
		if ( $spent >= $budget ) {
			return 'exceeded';
		}
		if ( $spent >= $budget * self::WARNING_RATIO ) {
			return 'warning';
		}
		return 'ok';
	}

	/**
	 * Whether a generation error is worth retrying.
	 *
	 * Providers attach the HTTP status as error data ('status'); a 4xx other
	 * than 429 means the request itself is bad, so retrying is pointless.
	 */
	public static function is_transient_error( WP_Error $error ): bool {
		$code = $error->get_error_code();

		if ( in_array( $code, self::PERMANENT_CODES, true ) ) {
			return false;
		}

		if ( 'swps_api_error' === $code ) {
			$data   = $error->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
			if ( $status >= 400 && $status < 500 && 429 !== $status ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Exponential backoff: 15 min, 30 min, 60 min…
	 *
	 * @param int $attempt 1-based attempt number about to be scheduled.
	 */
	public static function retry_delay( int $attempt ): int {
		$attempt = max( 1, $attempt );
		return 900 * ( 2 ** ( $attempt - 1 ) );
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/AutopilotGuardianTest.php`
Expected: PASS, 20 tests.

- [ ] **Step 5: Commit**

```bash
git add includes/class-autopilot-guardian.php tests/unit/AutopilotGuardianTest.php
git commit -m "feat: autopilot guardian pure logic — budget state, error classification, backoff"
```

---

### Task 3: Providers attach HTTP status to WP_Error data

**Files:**
- Modify: `includes/providers/ai/class-anthropic-provider.php`
- Modify: `includes/providers/ai/class-openai-provider.php`
- Modify: `includes/providers/ai/class-google-provider.php`
- Modify: `includes/providers/ai/class-xai-provider.php`

- [ ] **Step 1: Add status data to every non-200 `swps_api_error` return**

In EACH of the four provider files, find every `return new WP_Error( 'swps_api_error', sprintf( ... ) );` produced from a non-200 chat/completions response and add a third argument. Anthropic example (class-anthropic-provider.php:94-100) becomes:

```php
		if ( 200 !== $status_code ) {
			$error_message = $body['error']['message'] ?? __( 'Unknown API error.', 'stratawp-seo' );
			return new WP_Error(
				'swps_api_error',
				sprintf( __( 'Claude API error (%1$d): %2$s', 'stratawp-seo' ), $status_code, $error_message ),
				array( 'status' => (int) $status_code )
			);
		}
```

Apply the same `array( 'status' => (int) $status_code )` third argument in the other three providers (their wording differs — OpenAI/Gemini/Grok — but each has the same `200 !== $status_code` guard in its `chat()`/completion method). Do NOT touch the `validate_key`/`test_key` error paths — only the generation request path. Use `grep -n "swps_api_error" includes/providers/ai/*.php` to enumerate every site; there may be more than one per file (e.g. JSON-mode and plain paths) — all generation-path sites get the data argument.

- [ ] **Step 2: Lint the touched files**

```bash
php -l includes/providers/ai/class-anthropic-provider.php && php -l includes/providers/ai/class-openai-provider.php && php -l includes/providers/ai/class-google-provider.php && php -l includes/providers/ai/class-xai-provider.php
```

Expected: `No syntax errors detected` ×4.

- [ ] **Step 3: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add includes/providers/ai/
git commit -m "feat: attach HTTP status to provider API errors for retry classification"
```

---

### Task 4: Budget gate in the generator + cost-tracker force-enable

**Files:**
- Modify: `includes/class-autopilot-guardian.php` (add `check_budget()`)
- Modify: `includes/class-generator.php:53-56` (gate before `do_before_generate`)
- Modify: `includes/class-cost-tracker.php:24-27` (track when a budget is set even if tracking is off)

- [ ] **Step 1: Add `check_budget()` to the guardian**

Append inside the class (after `retry_delay()`):

```php
	/**
	 * Gate a generation on the monthly budget.
	 *
	 * @return true|WP_Error True to proceed; WP_Error 'swps_budget_exceeded' to block.
	 */
	public static function check_budget() {
		$budget = (float) get_option( self::OPTION_BUDGET, 0 );
		if ( $budget <= 0 ) {
			return true;
		}

		$tracker = new SWPS_Cost_Tracker();
		$spent   = (float) ( $tracker->get_monthly_stats()['total_cost'] ?? 0 );

		if ( 'exceeded' === self::budget_state( $spent, $budget ) ) {
			return new WP_Error(
				'swps_budget_exceeded',
				sprintf(
					/* translators: 1: amount spent, 2: budget */
					__( 'Monthly AI budget reached ($%1$s of $%2$s). Generation is paused until next month or until you raise the budget in Settings.', 'stratawp-seo' ),
					number_format_i18n( $spent, 2 ),
					number_format_i18n( $budget, 2 )
				)
			);
		}

		return true;
	}
```

- [ ] **Step 2: Gate `generate_post()`**

In `includes/class-generator.php`, immediately after the `ignore_user_abort( true );` line (line 53) and BEFORE `SWPS_Hooks::do_before_generate(...)`, insert:

```php
		// Budget gate — cheapest check, runs before anything spends money.
		$budget_check = SWPS_Autopilot_Guardian::check_budget();
		if ( is_wp_error( $budget_check ) ) {
			SWPS_Hooks::do_generation_failed( $budget_check, $topic, $template );
			return $budget_check;
		}
```

- [ ] **Step 3: Cost tracker tracks whenever a budget is set**

In `includes/class-cost-tracker.php`, replace the early return in `track()` (lines 25-27):

```php
		// A budget cap is meaningless without spend data, so a set budget
		// force-enables tracking regardless of the opt-in.
		$budget_set = (float) get_option( 'swps_monthly_budget', 0 ) > 0;
		if ( ! get_option( 'swps_cost_tracking', false ) && ! $budget_set ) {
			return;
		}
```

- [ ] **Step 4: Lint + suite**

```bash
php -l includes/class-generator.php && php -l includes/class-cost-tracker.php && php -l includes/class-autopilot-guardian.php && vendor/bin/phpunit
```

Expected: no syntax errors; tests PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-generator.php includes/class-cost-tracker.php includes/class-autopilot-guardian.php
git commit -m "feat: enforce monthly AI budget before generation"
```

---

### Task 5: Settings field, activation default, and 80% warning notice

**Files:**
- Modify: `includes/class-autopilot-guardian.php` (constructor + settings + notice)
- Modify: `stratawp-seo.php` (activation default; instantiation comes in Task 7)

- [ ] **Step 1: Add constructor, settings registration, and warning notice to the guardian**

Add to the class:

```php
	public function __construct() {
		// Priority 20: SWPS_Settings registers the schedule section at default 10.
		add_action( 'admin_init', array( $this, 'register_settings' ), 20 );
		add_action( 'admin_notices', array( $this, 'maybe_warn_budget' ) );
	}

	/**
	 * Register the budget field inside the existing Auto-Publishing Schedule section.
	 */
	public function register_settings(): void {
		register_setting(
			'stratawp-seo',
			self::OPTION_BUDGET,
			array(
				'type'              => 'number',
				'sanitize_callback' => array( $this, 'sanitize_budget' ),
				'default'           => 0,
			)
		);

		add_settings_field(
			self::OPTION_BUDGET,
			__( 'Monthly AI budget (USD)', 'stratawp-seo' ),
			array( $this, 'render_budget_field' ),
			'stratawp-seo',
			'swps_schedule_section'
		);
	}

	/**
	 * @param mixed $value Raw submitted value.
	 */
	public function sanitize_budget( $value ): float {
		return max( 0, round( (float) $value, 2 ) );
	}

	public function render_budget_field(): void {
		$budget = (float) get_option( self::OPTION_BUDGET, 0 );
		printf(
			'<input type="number" name="%1$s" value="%2$s" min="0" step="0.01" class="small-text" /> ' .
			'<p class="description">%3$s</p>',
			esc_attr( self::OPTION_BUDGET ),
			esc_attr( $budget > 0 ? (string) $budget : '' ),
			esc_html__( 'Hard stop for AI generation this calendar month. 0 or empty disables the cap. Setting a budget automatically enables cost tracking. Costs are estimates from the model price catalog — the provider bill may differ slightly.', 'stratawp-seo' )
		);
	}

	/**
	 * One dismissible warning per month once spend crosses 80% of budget.
	 */
	public function maybe_warn_budget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$budget = (float) get_option( self::OPTION_BUDGET, 0 );
		if ( $budget <= 0 ) {
			return;
		}

		$tracker = new SWPS_Cost_Tracker();
		$spent   = (float) ( $tracker->get_monthly_stats()['total_cost'] ?? 0 );
		$state   = self::budget_state( $spent, $budget );

		if ( 'ok' === $state ) {
			return;
		}

		$dismiss_key = 'swps_budget_notice_dismissed_' . gmdate( 'Y_m' );
		if ( 'warning' === $state && get_option( $dismiss_key ) ) {
			return;
		}
		if ( 'warning' === $state && isset( $_GET['swps_dismiss_budget_notice'] ) && check_admin_referer( 'swps_dismiss_budget_notice' ) ) {
			update_option( $dismiss_key, 1, false );
			return;
		}

		if ( 'exceeded' === $state ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'StrataWP SEO: monthly AI budget reached.', 'stratawp-seo' ),
				esc_html(
					sprintf(
						/* translators: 1: spent, 2: budget */
						__( '$%1$s of $%2$s spent — AI generation is paused until next month or a higher budget.', 'stratawp-seo' ),
						number_format_i18n( $spent, 2 ),
						number_format_i18n( $budget, 2 )
					)
				)
			);
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'swps_dismiss_budget_notice', 1 ),
			'swps_dismiss_budget_notice'
		);
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'StrataWP SEO: approaching monthly AI budget.', 'stratawp-seo' ),
			esc_html(
				sprintf(
					/* translators: 1: spent, 2: budget */
					__( '$%1$s of $%2$s spent this month.', 'stratawp-seo' ),
					number_format_i18n( $spent, 2 ),
					number_format_i18n( $budget, 2 )
				)
			),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss for this month', 'stratawp-seo' )
		);
	}
```

- [ ] **Step 2: Activation default**

In `stratawp-seo.php`, find the `swps_activate()` defaults block (`grep -n "add_option" stratawp-seo.php | head`) and add alongside the other defaults:

```php
	add_option( 'swps_monthly_budget', 0 );
```

- [ ] **Step 3: Lint + suite**

```bash
php -l includes/class-autopilot-guardian.php && php -l stratawp-seo.php && vendor/bin/phpunit
```

Expected: clean.

- [ ] **Step 4: Commit**

```bash
git add includes/class-autopilot-guardian.php stratawp-seo.php
git commit -m "feat: monthly budget setting with 80% warning notice"
```

---

### Task 6: Resilient cron loop + topic retry/requeue

**Files:**
- Modify: `includes/class-autopilot-guardian.php` (add `handle_topic_failure()` + `record_run()` + `get_status()`)
- Modify: `includes/class-cron.php:57-106` (rework loop)
- Modify: `includes/class-background-processor.php:193-215` (retry path in `process_topic`)

- [ ] **Step 1: Add failure handling, run recording, and status to the guardian**

```php
	/**
	 * Handle a failed topic: requeue transient failures with backoff (capped
	 * at MAX_ATTEMPTS total attempts), mark permanent ones failed.
	 *
	 * @param int             $topic_id Topic post ID.
	 * @param WP_Error        $error    The generation error.
	 * @param SWPS_Topic_Queue $queue   Queue for status updates.
	 * @return string 'retrying' or 'failed'.
	 */
	public static function handle_topic_failure( int $topic_id, WP_Error $error, SWPS_Topic_Queue $queue ): string {
		$attempts = (int) get_post_meta( $topic_id, '_swps_attempt_count', true ) + 1;
		update_post_meta( $topic_id, '_swps_attempt_count', $attempts );

		if ( self::is_transient_error( $error ) && $attempts < self::MAX_ATTEMPTS ) {
			$queue->update_status(
				$topic_id,
				'retrying',
				sprintf(
					/* translators: 1: attempt, 2: max attempts, 3: error message */
					__( 'Attempt %1$d of %2$d failed: %3$s — retry scheduled.', 'stratawp-seo' ),
					$attempts,
					self::MAX_ATTEMPTS,
					$error->get_error_message()
				)
			);
			stratawp_seo()->background_processor->schedule_generation( $topic_id, self::retry_delay( $attempts ) );
			return 'retrying';
		}

		$queue->update_status( $topic_id, 'failed', $error->get_error_message() );
		return 'failed';
	}

	/**
	 * Record a per-run summary for the dashboard status strip.
	 */
	public static function record_run( int $succeeded, int $failed ): void {
		update_option(
			self::OPTION_LAST_RUN,
			array(
				'timestamp' => time(),
				'succeeded' => $succeeded,
				'failed'    => $failed,
			),
			false
		);
	}

	/**
	 * Status payload for the dashboard tile.
	 */
	public static function get_status(): array {
		$tracker = new SWPS_Cost_Tracker();
		$spent   = (float) ( $tracker->get_monthly_stats()['total_cost'] ?? 0 );
		$budget  = (float) get_option( self::OPTION_BUDGET, 0 );

		return array(
			'last_run'     => get_option( self::OPTION_LAST_RUN, array() ),
			'spent'        => $spent,
			'budget'       => $budget,
			'budget_state' => self::budget_state( $spent, $budget ),
		);
	}
```

- [ ] **Step 2: Rework the cron loop**

Replace the body of `SWPS_Cron::run_scheduled_generation()` (class-cron.php:57-106) with:

```php
	public function run_scheduled_generation(): void {
		$enabled = get_option( 'swps_cron_enabled', false );

		if ( ! $enabled ) {
			return;
		}

		$posts_per_run = (int) get_option( 'swps_cron_posts_per_run', 1 );
		$posts_per_run = min( $posts_per_run, 5 );

		$succeeded = 0;
		$failed    = 0;

		for ( $i = 0; $i < $posts_per_run; $i++ ) {
			$topic    = '';
			$template = get_option( 'swps_default_template', 'auto' );
			$topic_id = 0;

			// Check queue for next topic.
			if ( $this->queue ) {
				$next_topic = $this->queue->get_next_topic();
				if ( $next_topic ) {
					$topic    = $next_topic->post_title;
					$template = get_post_meta( $next_topic->ID, '_swps_template', true ) ?: $template;
					$topic_id = $next_topic->ID;
					$this->queue->update_status( $topic_id, 'generating' );
				}
			}

			$result = $this->generator->generate_post( $topic, $template );

			if ( is_wp_error( $result ) ) {
				++$failed;
				error_log( '[StrataWP SEO Cron] Generation failed: ' . $result->get_error_message() );

				// Budget exhausted: every remaining post would fail too. Put
				// the topic back in the queue untouched and stop the batch.
				if ( 'swps_budget_exceeded' === $result->get_error_code() ) {
					if ( $topic_id && $this->queue ) {
						$this->queue->update_status( $topic_id, 'queued' );
					}
					break;
				}

				if ( $topic_id && $this->queue ) {
					SWPS_Autopilot_Guardian::handle_topic_failure( $topic_id, $result, $this->queue );
				}

				continue;
			}

			++$succeeded;

			// Update topic status if from queue.
			if ( $topic_id && $this->queue ) {
				$this->queue->update_status( $topic_id, 'published', '', $result['post_id'] );
			}

			if ( $i < $posts_per_run - 1 ) {
				sleep( 5 );
			}
		}

		SWPS_Autopilot_Guardian::record_run( $succeeded, $failed );
		update_option( 'swps_cron_last_run', current_time( 'mysql' ) );
	}
```

- [ ] **Step 3: Retry path in the background processor**

In `SWPS_Background_Processor::process_topic()` (class-background-processor.php:193-215): the topic arriving here may have status `retrying` (scheduled by the guardian) or `queued`. Replace the failure branch:

```php
		$result = $plugin->generator->generate_post( $topic->post_title, $template );

		if ( is_wp_error( $result ) ) {
			if ( 'swps_budget_exceeded' === $result->get_error_code() ) {
				// Not the topic's fault — return it to the queue untouched.
				$queue->update_status( $topic_id, 'queued' );
				return;
			}
			SWPS_Autopilot_Guardian::handle_topic_failure( $topic_id, $result, $queue );
			return;
		}

		delete_post_meta( $topic_id, '_swps_attempt_count' );
		$queue->update_status( $topic_id, 'published', '', $result['post_id'] );
```

Also add the same cleanup to the cron success path in Step 2? No — keep it in one place: ALSO add `delete_post_meta( $topic_id, '_swps_attempt_count' );` immediately before the `'published'` update in the cron loop success branch (Step 2 code), so a topic that succeeded on retry doesn't carry a stale counter. Update the Step 2 code accordingly when applying.

- [ ] **Step 4: 'retrying' status must not be re-picked by `get_next_topic()` but must surface in the calendar**

Verify `get_next_topic()` only selects `post_status`/meta-status `queued` (read `includes/class-topic-queue.php`); since `update_status` sets the status to `retrying`, it is excluded automatically. Then check the calendar:

```bash
grep -n "queued\|generating\|failed\|published" includes/class-calendar.php | head -20
```

If `get_calendar_events()` (or its color map) enumerates statuses, add `'retrying'` with color `#f59e0b` (amber) following the exact pattern of the existing entries. If statuses are not hardcoded, no change needed.

- [ ] **Step 5: Lint + suite**

```bash
php -l includes/class-cron.php && php -l includes/class-background-processor.php && php -l includes/class-autopilot-guardian.php && vendor/bin/phpunit
```

Expected: clean, tests PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/class-cron.php includes/class-background-processor.php includes/class-autopilot-guardian.php includes/class-calendar.php
git commit -m "feat: retry transient failures with backoff and survive batch errors in autopilot cron"
```

(Drop `class-calendar.php` from the add list if Step 4 needed no change.)

---

### Task 7: Wire-up + dashboard status tile

**Files:**
- Modify: `stratawp-seo.php` (require_once + instantiate)
- Modify: `includes/class-dashboard.php` (safe_get_autopilot)
- Modify: `templates/dashboard-page.php` (tile)

- [ ] **Step 1: Register the class (NO autoloader in this plugin)**

In `stratawp-seo.php`: add with the other requires (alphabetical/grouped as the file does):

```php
require_once STRATAWP_SEO_PLUGIN_DIR . 'includes/class-autopilot-guardian.php';
```

(Verify the exact require prefix used by neighboring lines — `grep -n "require_once" stratawp-seo.php | head -5` — and match it.) Then instantiate near the cron/background-processor construction (stratawp-seo.php:323-327):

```php
		$this->autopilot_guardian = new SWPS_Autopilot_Guardian();
```

with a public typed property alongside the others (stratawp-seo.php:~192):

```php
	public SWPS_Autopilot_Guardian $autopilot_guardian;
```

- [ ] **Step 2: Dashboard data**

In `includes/class-dashboard.php`, add to the `get_summary_data()` array (after `'recent_generations'`):

```php
			'autopilot'           => $this->safe_get_autopilot(),
```

and add the method following the safe_get pattern (try/catch → default):

```php
	private function safe_get_autopilot(): array {
		try {
			return array_merge(
				SWPS_Cron::get_schedule_info(),
				SWPS_Autopilot_Guardian::get_status()
			);
		} catch ( Throwable $e ) {
			return array();
		}
	}
```

(Match the exact catch style of the neighboring `safe_get_*` methods — read one first and copy its shape.)

- [ ] **Step 3: Dashboard tile**

In `templates/dashboard-page.php`, after the existing AI-cost tile (locate via `grep -n "ai_cost_30d\|AI cost" templates/dashboard-page.php`), add a tile following the established markup:

```php
	<?php $autopilot = $data['autopilot'] ?? array(); ?>
	<?php if ( ! empty( $autopilot ) ) : ?>
		<div class="swps-tile">
			<div class="swps-tile-h"><?php esc_html_e( 'Autopilot', 'stratawp-seo' ); ?></div>
			<?php if ( empty( $autopilot['enabled'] ) ) : ?>
				<div class="swps-tile-stat"><?php esc_html_e( 'Off', 'stratawp-seo' ); ?></div>
			<?php else : ?>
				<?php
				$last      = $autopilot['last_run'] ?? array();
				$healthy   = empty( $last ) || empty( $last['failed'] );
				$exceeded  = ( $autopilot['budget_state'] ?? 'ok' ) === 'exceeded';
				?>
				<div class="swps-tile-stat">
					<?php
					if ( $exceeded ) {
						esc_html_e( 'Paused — budget', 'stratawp-seo' );
					} elseif ( $healthy ) {
						esc_html_e( 'Healthy', 'stratawp-seo' );
					} else {
						printf(
							/* translators: number of failed generations in the last run */
							esc_html__( '%d failed last run', 'stratawp-seo' ),
							(int) $last['failed']
						);
					}
					?>
				</div>
				<div class="swps-tile-trend" style="color:var(--swps-text-faint)">
					<?php
					if ( ! empty( $last['timestamp'] ) ) {
						printf(
							/* translators: 1: human time diff, 2: succeeded count, 3: failed count */
							esc_html__( 'Last run %1$s ago — %2$d ok, %3$d failed', 'stratawp-seo' ),
							esc_html( human_time_diff( (int) $last['timestamp'], time() ) ),
							(int) ( $last['succeeded'] ?? 0 ),
							(int) ( $last['failed'] ?? 0 )
						);
					}
					if ( ( $autopilot['budget'] ?? 0 ) > 0 ) {
						printf(
							' · %s',
							esc_html(
								sprintf(
									/* translators: 1: spent, 2: budget */
									__( '$%1$s of $%2$s budget', 'stratawp-seo' ),
									number_format_i18n( (float) $autopilot['spent'], 2 ),
									number_format_i18n( (float) $autopilot['budget'], 2 )
								)
							)
						);
					}
					?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
```

Adapt variable access to how the template actually receives data — read the top of `templates/dashboard-page.php` first to see whether tiles read `$data['x']`, extracted variables (`$posts_30d` suggests `extract()` or individual assignment), and match exactly.

- [ ] **Step 4: Lint + suite + static analysis**

```bash
php -l stratawp-seo.php && php -l includes/class-dashboard.php && php -l templates/dashboard-page.php
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: all clean. PHPStan must not add NEW errors beyond the committed baseline.

- [ ] **Step 5: Commit**

```bash
git add stratawp-seo.php includes/class-dashboard.php templates/dashboard-page.php
git commit -m "feat: autopilot status tile with budget usage on dashboard"
```

---

### Task 8: Release chores + PR

- [ ] **Step 1: Version bump to 4.11.0**

Update the version in: `stratawp-seo.php` (plugin header `Version:` AND any `STRATAWP_SEO_VERSION` define), `readme.txt` (`Stable tag:`), `README.md` (version badge + changelog section). Add a changelog entry to both readme files:

```
= 4.11.0 =
* New: Autopilot Guardian — monthly AI budget cap with 80% warning, automatic retry with backoff for transient API errors, failed-topic requeue (max 3 attempts), and a dashboard autopilot status tile.
* Fix: a single failed generation no longer abandons the rest of a scheduled batch.
```

- [ ] **Step 2: Full verification**

```bash
vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/phpcs --standard=phpcs.xml.dist includes/class-autopilot-guardian.php includes/class-cron.php includes/class-background-processor.php
```

Expected: tests PASS, PHPStan no new errors, PHPCS clean on the new class (legacy files may carry baseline warnings — only fix violations introduced by this change).

- [ ] **Step 3: Build the deployment zip**

Check how previous zips were built (`grep -rn "zip" .github/workflows/ composer.json | head`). If the release workflow auto-builds from a version bump on main, SKIP local zip creation and note it in the PR body. Otherwise mirror the existing zip structure (top-level `stratawp-seo/` folder, exclude tests/docs/.git per the existing zips).

- [ ] **Step 4: Commit + push + PR**

```bash
git add -A
git commit -m "chore: release 4.11.0 — autopilot guardian"
git push -u origin feature/autopilot-guardian
gh pr create --title "feat: Autopilot Guardian — budget caps, retry with backoff, auto-requeue" --body "$(cat <<'EOF'
## Summary
- Monthly AI budget cap (hard stop) enforced before any generation; setting a budget force-enables cost tracking; dismissible 80% warning notice
- Providers attach HTTP status to API errors so transient (429/5xx/network) and permanent (auth/duplicate) failures are distinguishable
- Transient failures retry via the background processor with 15/30-minute backoff, capped at 3 attempts (`_swps_attempt_count`); permanent failures fail fast
- Scheduled batch continues past individual failures instead of aborting (`break` → `continue`); budget exhaustion stops the batch and returns the topic to the queue untouched
- Dashboard "Autopilot" tile: health, last-run summary, budget usage

## Test plan
- [ ] `vendor/bin/phpunit` — new AutopilotGuardianTest (20 tests) + existing suite
- [ ] PHPStan/PHPCS clean
- [ ] Manual: set $0.01 budget with cost tracking data present → generation blocked with budget error; cron run with a failing provider key → topic marked failed (permanent) vs retrying (transient)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist (run after implementation)

1. Every new class `require_once`'d in stratawp-seo.php? (guardian — yes, Task 7.)
2. `SWPS_Autopilot_Guardian` file under 500 lines?
3. No `sleep()`-based retries anywhere?
4. `swps_budget_exceeded` never increments `_swps_attempt_count` / never marks a topic failed?
5. Topic that succeeds after retry has `_swps_attempt_count` deleted (both cron and background paths)?
6. `retrying` status excluded from `get_next_topic()` (verified, not assumed)?
7. Budget field renders in Settings → schedule section; saving persists; sanitizer clamps negatives to 0?
