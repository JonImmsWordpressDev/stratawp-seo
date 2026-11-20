# WPCS Security Triage — 2026-05-18

Triage of every security-relevant PHPCS finding remaining after the
`phpcbf` auto-fix pass (#30). Scope: the sniff categories that can
indicate a real vulnerability.

## Verdict

**No exploitable vulnerabilities found.** All 140 security-relevant
findings are false positives — artefacts of WPCS being unable to
statically reason about four safe patterns the codebase uses
consistently. No SQL injection, XSS, CSRF, open redirect, or
unsanitised-input sink was identified.

## Findings by category

| Sniff | Count | Disposition | Evidence |
|-------|-------|-------------|----------|
| `WordPress.DB.PreparedSQL.*` | 84 | False positive | Only `{$table}` / `{$placeholders}` interpolated — table names derived from class constants + `$wpdb->prefix` (cannot be bound by `prepare()`); all *values* bound via `%s`/`%d`. Verified the highest-risk vector: `class-redirect-manager.php` URL lookups (`url = %s`, bound) and AJAX 404 handlers (gated by `check_ajax_referer` + `current_user_can`). |
| `WordPress.Security.EscapeOutput.OutputNotEscaped` | 20 | False positive | Every flagged output is already `esc_html` / `esc_attr` / `esc_url` / `esc_textarea`'d, an `(int)` cast, a plugin-internal icon/separator constant, a developer-controlled translatable string, or non-HTML served with an explicit content-type (`text/markdown`). |
| `WordPress.Security.NonceVerification.Recommended` | 14 | False positive | `$_GET` read only for post-redirect display flags, admin-page routing, and pagination — all sanitised (`absint` / `sanitize_key`). Real form submits carry `wp_nonce_field`; AJAX uses `check_ajax_referer`; OAuth uses `state`-param validation (a WP nonce is inapplicable to an external redirect). Sniff flagged **zero** unprotected `$_POST` state-change handlers. |
| `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | 20 | False positive | Input sanitised via variable callbacks WPCS can't follow (e.g. `meta-editor.php::save_meta()` — nonce + `current_user_can('edit_post')` + per-field sanitiser map), or used in safe contexts (`wp_parse_url(..., PHP_URL_PATH)` for path matching). |
| `WordPress.Security.SafeRedirect` | 2 | False positive | One targets `home_url()` (internal); one targets admin-configured DB redirects (off-site redirects are intended functionality, set behind nonce + capability). |

## Why WPCS misfires here

1. **Constant-derived table names** interpolated into SQL — unavoidable; `prepare()` cannot parameterise identifiers.
2. **Sanitisation/escaping one expression removed** from the flagged line (multi-arg `printf`, variable `$sanitizer` callbacks).
3. **`$_GET` for display/routing**, not state changes — sanitised, no nonce applicable.
4. **OAuth `state`** as the correct CSRF defence for an external redirect.

## Recommended remediation (mechanical, non-urgent)

These are **noise-reduction**, not security fixes:

- Replace the existing misplaced single-line `// phpcs:ignore` comments
  (currently one line above the multi-line SQL they intend to cover, so
  they don't suppress) with correctly-scoped, **reason-documented**
  ignores, e.g.
  `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from class constant, values bound via prepare()`.
- Decide one annotation convention and apply it in a single focused PR
  so the *next* genuine finding isn't buried in 140 known-safe entries.
- Only then promote PHPCS from advisory to a hard CI gate.

## Optional defence-in-depth (not vulnerabilities)

- `class-redirect-manager.php:115` — `wp_redirect()` → `wp_safe_redirect()`.
  Target is admin-defined, but `wp_safe_redirect` costs nothing.
- `class-redirect-manager.php:53` — add `wp_unslash()` before
  `wp_parse_url()` for consistency with the line-110 sibling (negligible
  impact: path-only comparison).

## Out of scope (separate hygiene backlog)

The remaining ~1,500 non-security findings (missing docblocks ~700,
unprefixed locals 201, short-ternary 91, Yoda 39, translators-comments
49, etc.) are style/maintainability — no runtime or security impact.
Track separately; do not gate on them.
