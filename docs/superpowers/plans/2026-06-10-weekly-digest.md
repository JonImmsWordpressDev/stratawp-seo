# White-Label Weekly Digest Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A scheduled HTML digest email aggregating everything the plugin already measures — posts generated, generation failures, keyword winners/losers, backlink changes, competitor changes, AI-bot crawl trends, monthly AI spend, AEO score movers — led by a "needs your attention" triage section, with agency branding, multiple recipients, a test-send button, and an optional AI executive summary.

**Architecture:** One new class `SWPS_Digest` (data assembly, cron, settings, send + failure-listener) plus an email template `templates/email/digest.php` (inline-CSS table layout). Pure computation helpers (movers, keyword deltas, recipient parsing) are static and unit-tested without WordPress. Every data section uses fail-soft getters (try/catch → null) and the template suppresses empty sections. Structured generation failures get their first persistent store (a rolling option written by a `swps_generation_failed` listener). AEO movers come from a per-send postmeta snapshot diff.

**Tech Stack:** PHP 8.0, WP options/cron/Settings API/wp_mail, PHPUnit pure-PHP tests, PHPCS/PHPStan.

**Branch:** `feature/weekly-digest` STACKED on `feature/autopilot-guardian` (PR base = that branch; version bumps 4.11.0 → 4.12.0). The guardian's `SWPS_Autopilot_Guardian::get_status()` is available and used for budget info.

**Project conventions:** no autoloader (require_once in stratawp-seo.php), files < 500 lines, tabs/WPCS in includes/, no Co-Authored-By, version bump + README/readme.txt changelog at the end, release workflow builds the zip on merge to main.

---

## Verified code facts (branch feature/autopilot-guardian @ 7242c65)

- `SWPS_Backlinks::get_stats(): array` — includes/class-backlinks.php:584 (read it to learn exact keys: new/lost/broken counts).
- `SWPS_Competitors::get_dashboard_summary( int $limit = 3 ): array` — includes/class-competitors.php:310.
- `SWPS_Bot_Analytics_Tracker::get_bot_summary( int $days = 30 ): array` — includes/class-bot-analytics-tracker.php:324; also has `get_totals` patterns with period deltas (read the class).
- `SWPS_Cost_Tracker::get_monthly_stats(): array` (total_cost, generation_count, tokens).
- `SWPS_Autopilot_Guardian::get_status(): array` (last_run, spent, budget, budget_state).
- Keyword history table `wp_swps_keyword_tracking`: columns include keyword, position (FLOAT NULL), date; UNIQUE (keyword, date). One row per keyword per sync. Winners/losers = compare each keyword's latest position vs its position ~7 days prior.
- Generation log: option `swps_generation_log` (rolling array, see `SWPS_Generator::append_log` line 698) — lossy strings; NOT sufficient for failures, hence the new listener.
- `SWPS_Hooks::do_generation_failed( WP_Error $error, string $topic, string $template )` fires the action `swps_generation_failed` with 3 args (verify arg order with `grep -n "swps_generation_failed" includes/class-hooks.php` — read the do_action call).
- Settings tabs: `SWPS_Settings::get_settings_tabs()` (class-settings.php:1597) maps tab keys → section id arrays; template renders via `render_sections_for_tab`. To show a NEW section, its id must be appended to a tab's `sections` array AND the section/fields registered on page `'stratawp-seo'`, group `'stratawp-seo'`.
- Recent generations: `SWPS_Dashboard::safe_get_recent_generations()` (class-dashboard.php:208) — read it and reuse its query shape (it queries posts with `_swps_cost`/generation meta; copy the approach, not the method).
- Cron schedule pattern: `SWPS_Keyword_Tracker::schedule_cron()` (reads a frequency option, wp_schedule_event); custom intervals `swps_monthly` etc. registered by SWPS_Cron::add_custom_schedules. 'daily'/'weekly' are WP core schedules.
- AEO score meta key: `_swps_aeo_score` (int per post).
- Email: there is currently ZERO `wp_mail` usage in the plugin.
- Activation defaults: `swps_activate()` in stratawp-seo.php uses a defaults array whose keys get prefixed `swps_`.

---

### Task 0: Branch

- [ ] `git checkout -b feature/weekly-digest feature/autopilot-guardian` (HEAD must be 7242c65 or later)
- [ ] `vendor/bin/phpunit` → 105 passing baseline.

---

### Task 1: Pure computation helpers (TDD)

**Files:** Create `includes/class-digest.php`; Test `tests/unit/DigestTest.php`

Static methods to build first, test-first (tab indentation in BOTH files):

```php
	/**
	 * Split a comma/space-separated recipients string into valid emails.
	 *
	 * @param string $raw Raw setting value.
	 * @return string[] Deduplicated valid emails (may be empty).
	 */
	public static function parse_recipients( string $raw ): array {
		$parts  = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$emails = array();
		foreach ( $parts as $part ) {
			$email = filter_var( $part, FILTER_VALIDATE_EMAIL );
			if ( false !== $email ) {
				$emails[ strtolower( $email ) ] = $email;
			}
		}
		return array_values( $emails );
	}

	/**
	 * Diff two post_id => score maps into top movers.
	 *
	 * @param array $previous post_id => int score at last send.
	 * @param array $current  post_id => int score now.
	 * @param int   $limit    Max movers per direction.
	 * @return array{risers: array<int, int>, fallers: array<int, int>} post_id => delta.
	 */
	public static function compute_movers( array $previous, array $current, int $limit = 5 ): array {
		$deltas = array();
		foreach ( $current as $post_id => $score ) {
			if ( isset( $previous[ $post_id ] ) && (int) $previous[ $post_id ] !== (int) $score ) {
				$deltas[ $post_id ] = (int) $score - (int) $previous[ $post_id ];
			}
		}
		arsort( $deltas );
		$risers = array_slice( array_filter( $deltas, static fn( $d ) => $d > 0 ), 0, $limit, true );
		asort( $deltas );
		$fallers = array_slice( array_filter( $deltas, static fn( $d ) => $d < 0 ), 0, $limit, true );
		return array(
			'risers'  => $risers,
			'fallers' => $fallers,
		);
	}

	/**
	 * Compute keyword winners/losers from (keyword => [old_position, new_position]) pairs.
	 * Lower position = better. Null positions are skipped.
	 *
	 * @param array $pairs keyword => array{0: float|null, 1: float|null}.
	 * @param int   $limit Max per direction.
	 * @return array{winners: array<string, float>, losers: array<string, float>} keyword => signed delta (negative = improved).
	 */
	public static function keyword_deltas( array $pairs, int $limit = 5 ): array {
		$deltas = array();
		foreach ( $pairs as $keyword => $pair ) {
			if ( ! isset( $pair[0], $pair[1] ) ) {
				continue;
			}
			$delta = (float) $pair[1] - (float) $pair[0];
			if ( 0.0 !== $delta ) {
				$deltas[ $keyword ] = $delta;
			}
		}
		asort( $deltas ); // Most negative (biggest improvement) first.
		$winners = array_slice( array_filter( $deltas, static fn( $d ) => $d < 0 ), 0, $limit, true );
		arsort( $deltas );
		$losers = array_slice( array_filter( $deltas, static fn( $d ) => $d > 0 ), 0, $limit, true );
		return array(
			'winners' => $winners,
			'losers'  => $losers,
		);
	}
```

Tests (write FIRST, watch fail, then implement — the test file `require_once`s includes/class-digest.php; class skeleton has ABSPATH guard + constants + these statics only at this stage):
- parse_recipients: mixed separators (`"a@b.com, c@d.com;e@f.com\ng@h.io"` → 4), invalid entries dropped, case-dedup (`A@B.com a@b.com` → 1), empty string → empty array.
- compute_movers: rising/falling split, limit respected, posts absent from previous skipped, unchanged scores skipped.
- keyword_deltas: improvement = negative delta in winners, null old or new skipped, zero delta skipped, limit respected.

Run suite (expect ~118+), commit `feat: digest pure helpers — recipients, movers, keyword deltas`.

---

### Task 2: Failure listener + data assembly

**Files:** Modify `includes/class-digest.php`

Constants on the class:

```php
	public const OPTION_ENABLED    = 'swps_digest_enabled';
	public const OPTION_FREQUENCY  = 'swps_digest_frequency'; // daily|weekly|monthly
	public const OPTION_RECIPIENTS = 'swps_digest_recipients';
	public const OPTION_LOGO       = 'swps_digest_logo';
	public const OPTION_ACCENT     = 'swps_digest_accent';
	public const OPTION_FOOTER     = 'swps_digest_footer';
	public const OPTION_AI_SUMMARY = 'swps_digest_ai_summary';
	public const OPTION_FAILURES   = 'swps_generation_failures';
	public const OPTION_AEO_SNAP   = 'swps_digest_aeo_snapshot';
	public const OPTION_LAST_SENT  = 'swps_digest_last_sent';
	public const CRON_HOOK         = 'swps_send_digest';
	private const MAX_FAILURES     = 20;
```

Constructor hooks: `add_action( 'swps_generation_failed', array( $this, 'record_failure' ), 10, 3 )`; `add_action( self::CRON_HOOK, array( $this, 'send' ) )`; settings + test-send hooks come in Task 3. VERIFY the action arg order in class-hooks.php before writing record_failure; signature `record_failure( WP_Error $error, string $topic, string $template ): void` — prepend `array( 'time' => time(), 'code' => ..., 'message' => ..., 'topic' => ..., 'template' => ... )` to the OPTION_FAILURES rolling array, trim to MAX_FAILURES, update_option autoload false.

`collect_data( int $since ): array` — assembles sections, each via a private fail-soft getter wrapped in try/catch (Throwable) returning null; null sections are dropped:
- `generated`: posts since $since with `_swps_tokens` meta (mirror safe_get_recent_generations query), each: title, edit link, `_swps_seo_score`/`_swps_aeo_score` meta if present, `_swps_cost`.
- `failures`: entries from OPTION_FAILURES newer than $since (then prune older ones).
- `keywords`: query wp_swps_keyword_tracking — for each tracked keyword, latest position and the closest row <= ($since): build pairs, run `keyword_deltas()`.
- `backlinks`: `SWPS_Backlinks::get_stats()` — read the class first; how to construct it (constructor args?) — if it needs dependencies, get the instance off `stratawp_seo()` (check the property name in stratawp-seo.php). Same for competitors/bot tracker below.
- `competitors`: `get_dashboard_summary( 3 )`.
- `bots`: `get_bot_summary( $days )` where $days matches the frequency window.
- `spend`: `SWPS_Cost_Tracker::get_monthly_stats()` + `SWPS_Autopilot_Guardian::get_status()` (budget + state).
- `aeo_movers`: read current `_swps_aeo_score` map (one `$wpdb` query over postmeta, LIMIT 500), `compute_movers()` vs OPTION_AEO_SNAP, then write the new snapshot.
- `attention`: built last — failures count, broken backlinks (key from get_stats — read it), budget_state warning/exceeded, autopilot last_run failed count. Empty array = "all good" line in the email.

No commit yet if you prefer one commit with Task 3, but preferred: commit `feat: digest failure listener and data assembly`.

---

### Task 3: Settings section, cron scheduling, test-send

**Files:** Modify `includes/class-digest.php`, `includes/class-settings.php` (ONE line: add section id to the analytics tab), `stratawp-seo.php` (activation defaults)

- Register section `swps_digest_section` titled "Email Digest" on page 'stratawp-seo' from the digest class (admin_init priority 20), with fields: enabled checkbox; frequency select (daily/weekly/monthly); recipients text (sanitize: `sanitize_textarea_field`, validated on use via parse_recipients — show a description that invalid addresses are ignored); logo URL (esc_url_raw sanitize); accent color (sanitize_hex_color); footer text (sanitize_text_field); AI summary checkbox. Group 'stratawp-seo' so it saves with the main form.
- Add `'swps_digest_section'` to the `'analytics'` tab's sections array in `get_settings_tabs()` (class-settings.php:1631-1636).
- Scheduling: on `update_option_swps_digest_enabled` and `update_option_swps_digest_frequency` (use `add_action( 'update_option_{option}', ..., 10, 2 )`), call a `reschedule()` method: clear CRON_HOOK (wp_unschedule_hook) and, if enabled, `wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::CRON_HOOK )` where $schedule maps daily→'daily', weekly→'weekly', monthly→'swps_monthly' (registered by SWPS_Cron). Also handle first-save (add_option_… actions fire instead of update when option didn't exist — register both or seed activation defaults so update always fires; defaults below).
- Activation defaults in stratawp-seo.php: `digest_enabled => 0`, `digest_frequency => 'weekly'`, `digest_recipients => ''`, `digest_ai_summary => 0` (keys get swps_ prefix via the loop).
- Test-send: `add_action( 'admin_post_swps_digest_test', array( $this, 'handle_test_send' ) )` — manage_options + check_admin_referer( 'swps_digest_test' ); sends the digest (window = last 7 days) to the CURRENT USER's email only; redirect back with `&swps_digest_test=sent|failed` and show an admin notice. Render the button inside the section description callback as a small form posting to admin-post.php (a nested form inside the settings form is invalid HTML — use a LINK styled as button: `wp_nonce_url( admin_url( 'admin-post.php?action=swps_digest_test' ), 'swps_digest_test' )` and make handle_test_send accept GET).
- Uninstall/cron hygiene: add `wp_unschedule_hook( 'swps_send_digest' )` wherever the plugin clears its other cron hooks on deactivation (grep `register_deactivation_hook` / the deactivate function in stratawp-seo.php and match the pattern), and add the new options to uninstall.php following its existing option-deletion pattern (it was recently overhauled — read it).

Commit: `feat: digest settings, scheduling, and test-send`

---

### Task 4: Email template + send()

**Files:** Create `templates/email/digest.php`; modify `includes/class-digest.php`

`send( bool $test = false, ?string $override_recipient = null ): bool`:
- Window: since OPTION_LAST_SENT (fallback: frequency span); collect_data; bail (return false, no email) if EVERY section is null/empty AND attention is empty — except never bail on test sends.
- Optional AI executive summary: if OPTION_AI_SUMMARY, build a compact stats JSON and ask the provider for 2 sentences via `SWPS_Provider_Factory::create_ai_provider()->chat(...)` (check the factory method name with grep first); wrap fully in try/catch + is_wp_error → skip section. If the provider exposes a public usage accessor (grep for `last_usage` public method), track cost via SWPS_Cost_Tracker; if not, skip tracking with a brief comment.
- Render: `ob_start(); include ...templates/email/digest.php; $html = ob_get_clean();` passing data via local vars. Template: 600px-wide table layout, ALL CSS inline (no <style> reliance for layout; a minimal <style> block for dark-mode meta is fine), accent color applied to header bar + links, logo at top when set, footer text + "Prepared by" line, every item linking to wp-admin pages with absolute URLs (admin_url). Sections render ONLY when their data key is non-empty. The attention section renders first with a colored left border; when empty render the one-liner "Nothing needs your attention this week. 🎉".
- Headers: `array( 'Content-Type: text/html; charset=UTF-8' )`. Recipients: parse_recipients(OPTION_RECIPIENTS); fallback to `get_option( 'admin_email' )` when empty. `wp_mail( $recipients, $subject, $html, $headers )`. Subject: `sprintf( '[%s] SEO digest — %s', get_bloginfo( 'name' ), wp_date( get_option( 'date_format' ) ) )`.
- On real (non-test) success: update OPTION_LAST_SENT = time().
- Catch-up guard: the cron callback `send()` should no-op (and NOT update last_sent) if last_sent is more recent than half the frequency window (double-fire protection).

Templates dir note: `templates/email/` is new — creating a subdirectory is fine (templates/ already exists).

Commit: `feat: digest email template and send pipeline`

---

### Task 5: Wire-up, verification, release

- require_once `includes/class-digest.php` in stratawp-seo.php (with the other requires) + public typed property + `$this->digest = new SWPS_Digest();` near the guardian instantiation.
- `php -l` everything touched; `vendor/bin/phpunit`; `vendor/bin/phpstan analyse --memory-limit=2G` (no new errors); `vendor/bin/phpcs --standard=phpcs.xml.dist includes/class-digest.php templates/email/digest.php` (clean — new files).
- Version 4.11.0 → 4.12.0 (stratawp-seo.php header + SWPS_VERSION, readme.txt stable tag + changelog, README.md badge + changelog):

```
= 4.12.0 =
* New: White-label email digest — weekly/daily/monthly report of generations, failures, keyword movers, backlinks, competitors, AI-bot trends, and AI spend, led by a needs-attention triage section; agency branding, multiple recipients, test-send, optional AI executive summary.
* New: generation failures are now persistently recorded (swps_generation_failed listener) and surfaced in the digest.
```

- Commit `chore: release 4.12.0 — email digest`, commit the plan doc too, push, PR with base `feature/autopilot-guardian`:
  `gh pr create --base feature/autopilot-guardian --title "feat: white-label email digest with needs-attention alerts" --body ...` (summary + test plan + note that wp_mail deliverability depends on the site's mailer and a deliverability note was added to the field description). End body with the Claude Code attribution line.

## Self-review checklist
1. Digest class < 500 lines? (If over, move the AI-summary + rendering glue into the template/a trait — report rather than improvise a new file.)
2. Every section fail-soft? A fatal in one getter must not kill the email.
3. No section renders empty headings?
4. Cron cleared on deactivation; options removed in uninstall.php?
5. parse_recipients used everywhere recipients are read (never raw)?
6. Test-send works via GET admin-post link with nonce + capability?
