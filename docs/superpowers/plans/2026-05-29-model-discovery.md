# Model Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auto-discover available AI text models and the Gemini image model from each provider's list-models API (daily, cached), surface them in the dropdowns, and alert on never-seen models — without auto-switching anything.

**Architecture:** A new `SWPS_Model_Discovery` service merges each provider's curated list with a daily-refreshed cache of API-discovered models. Per-provider `fetch_remote_models()` calls the provider's `/models` endpoint (each with a pure, unit-testable `parse_models_response()`); a `swps_refresh_models` daily cron refreshes the cache and flags new models for a dismissible admin notice. The Gemini image model becomes a selectable setting (`swps_gemini_image_model`) instead of a hardcoded constant.

**Tech Stack:** PHP 8.0+, WordPress, WP-Cron, provider REST APIs. Gate: PHPStan green + no new PHPCS errors + PHPUnit (`composer test`).

**Spec:** `docs/superpowers/specs/2026-05-29-model-discovery-design.md`

---

## Base & sequencing (read first)

- **Branch off `main`**, independent of the async-image PR (#41). If #41 merges first, rebase — overlap is limited to `admin.js` (image-row visibility), `settings.php` (Featured Images section), and the Gemini provider; all minor.
- **Gate model (same as the image fix):** `composer check` is pre-existingly red from a WPCS backlog. The per-task gate is **PHPStan stays green (`vendor/bin/phpstan analyse`) + no NEW PHPCS errors on touched lines + `composer test` (PHPUnit) green.** Pure parser/merge logic gets real PHPUnit tests in the `unit` suite (added in #41; if not yet merged, this plan's Task 3 re-adds the suite wiring — check `phpunit.xml.dist` first and skip if present).
- All new methods need full WPCS docblocks (`@param`/`@return`) — the provider/factory/settings files are largely WPCS-clean, so missing docblocks = new errors.
- API keys are stored encrypted; `get_api_key()` returns the decrypted value — use it, don't read the option directly.

## File structure

| File | Change | Responsibility |
|------|--------|----------------|
| `includes/class-ai-provider.php` | modify | Default `fetch_remote_models()` returning `[]` (providers opt in) |
| `includes/providers/ai/class-anthropic-provider.php` | modify | `fetch_remote_models()` + pure `parse_models_response()` |
| `includes/providers/ai/class-openai-provider.php` | modify | same (with chat-model filter) |
| `includes/providers/ai/class-google-provider.php` | modify | same (`generateContent` filter) |
| `includes/providers/ai/class-xai-provider.php` | modify | same (`grok-*` filter) |
| `includes/providers/images/class-gemini-provider.php` | modify | `fetch_remote_models()` (image models) + read `swps_gemini_image_model` setting |
| `includes/class-model-discovery.php` | create | Merge/diff/refresh/cache + dismiss; pure merge & diff helpers |
| `includes/class-provider-factory.php` | modify | `get_models_for_provider()` returns merged list |
| `includes/class-settings.php` | modify | Register + render `swps_gemini_image_model` dropdown |
| `admin/js/admin.js` | modify | Show the image-model row when provider = Gemini |
| `stratawp-seo.php` | modify | Instantiate service; register cron, admin_notices, dismiss AJAX; default option |
| `includes/class-model-cron.php` | create | Daily `swps_refresh_models` schedule/unschedule/callback |
| `tests/unit/*Test.php` | create | Pure parser, merge, and diff tests |

---

## Task 1: Base provider opt-in hook

**Files:** Modify `includes/class-ai-provider.php`

- [ ] **Step 1: Add a default `fetch_remote_models()`** (after `get_available_models()` abstract, add a concrete default so providers opt in):

```php
	/**
	 * Fetch the live model list from the provider's API.
	 *
	 * Providers override this to query their /models endpoint. The default
	 * returns an empty list, so discovery is a no-op for providers that
	 * don't implement it.
	 *
	 * @return array<string, string> Model ID => display name (empty on error/unsupported).
	 */
	public function fetch_remote_models(): array {
		return array();
	}
```

- [ ] **Step 2: Gate** — `vendor/bin/phpstan analyse --memory-limit=2G --no-progress` → `[OK]`; `vendor/bin/phpcs includes/class-ai-provider.php` shows no new errors at the added lines.
- [ ] **Step 3: Commit** — `git commit -m "feat: add fetch_remote_models() opt-in hook to AI provider base"` (append `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`).

---

## Task 2: Per-provider text-model fetchers + pure parsers (TDD)

**Files:** Modify the 4 AI providers; create `tests/unit/RemoteModelsParserTest.php`

Each provider gets a thin `fetch_remote_models()` (HTTP) delegating to a **pure static `parse_models_response(array $body): array`** (filter/map). Use each provider's existing `chat()` auth pattern (Anthropic: `x-api-key` + `anthropic-version`; OpenAI/xAI: `Authorization: Bearer`; Google: `?key=` query arg).

- [ ] **Step 1: Write the failing parser tests** — `tests/unit/RemoteModelsParserTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-ai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-anthropic-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-openai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-google-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-xai-provider.php';

final class RemoteModelsParserTest extends TestCase {

	public function test_anthropic_maps_id_to_display_name(): void {
		$body = array( 'data' => array(
			array( 'id' => 'claude-opus-4-8', 'display_name' => 'Claude Opus 4.8', 'type' => 'model' ),
		) );
		$this->assertSame(
			array( 'claude-opus-4-8' => 'Claude Opus 4.8' ),
			SWPS_Anthropic_Provider::parse_models_response( $body )
		);
	}

	public function test_openai_keeps_chat_models_only(): void {
		$body = array( 'data' => array(
			array( 'id' => 'gpt-5' ),
			array( 'id' => 'o4-mini' ),
			array( 'id' => 'text-embedding-3-large' ),
			array( 'id' => 'whisper-1' ),
			array( 'id' => 'dall-e-3' ),
		) );
		$out = SWPS_OpenAI_Provider::parse_models_response( $body );
		$this->assertArrayHasKey( 'gpt-5', $out );
		$this->assertArrayHasKey( 'o4-mini', $out );
		$this->assertArrayNotHasKey( 'text-embedding-3-large', $out );
		$this->assertArrayNotHasKey( 'whisper-1', $out );
		$this->assertArrayNotHasKey( 'dall-e-3', $out );
	}

	public function test_google_keeps_generatecontent_only(): void {
		$body = array( 'models' => array(
			array( 'name' => 'models/gemini-3.0-pro', 'displayName' => 'Gemini 3.0 Pro', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/text-embedding-004', 'displayName' => 'Embedding 004', 'supportedGenerationMethods' => array( 'embedContent' ) ),
		) );
		$out = SWPS_Google_Provider::parse_models_response( $body );
		$this->assertSame( array( 'gemini-3.0-pro' => 'Gemini 3.0 Pro' ), $out );
	}

	public function test_xai_keeps_grok_only(): void {
		$body = array( 'data' => array(
			array( 'id' => 'grok-5' ),
			array( 'id' => 'grok-2-image' ),
			array( 'id' => 'text-embedding' ),
		) );
		$out = SWPS_XAI_Provider::parse_models_response( $body );
		$this->assertArrayHasKey( 'grok-5', $out );
		$this->assertArrayNotHasKey( 'text-embedding', $out );
	}

	public function test_malformed_body_returns_empty(): void {
		$this->assertSame( array(), SWPS_Anthropic_Provider::parse_models_response( array() ) );
		$this->assertSame( array(), SWPS_OpenAI_Provider::parse_models_response( array( 'nope' => 1 ) ) );
	}
}
```

- [ ] **Step 2: Run → fail** — `vendor/bin/phpunit --testsuite unit` → errors (methods undefined).

- [ ] **Step 3: Implement.** Add to **Anthropic**:

```php
	public function fetch_remote_models(): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			'https://api.anthropic.com/v1/models?limit=100',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		return self::parse_models_response( (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Map an Anthropic /v1/models body to [ id => display_name ].
	 *
	 * @param array $body Decoded response body.
	 * @return array<string, string>
	 */
	public static function parse_models_response( array $body ): array {
		$models = array();
		foreach ( $body['data'] ?? array() as $m ) {
			if ( ! empty( $m['id'] ) ) {
				$models[ (string) $m['id'] ] = (string) ( $m['display_name'] ?? $m['id'] );
			}
		}
		return $models;
	}
```

Add to **OpenAI** (auth: `Authorization: Bearer`; endpoint `https://api.openai.com/v1/models`):

```php
	public function fetch_remote_models(): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		return self::parse_models_response( (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Keep chat-capable models only (gpt-* / o-series), excluding embeddings,
	 * audio, image, realtime, and moderation models.
	 *
	 * @param array $body Decoded response body.
	 * @return array<string, string>
	 */
	public static function parse_models_response( array $body ): array {
		$models = array();
		foreach ( $body['data'] ?? array() as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$is_chat = (bool) preg_match( '/^(gpt-|o\d)/', $id );
			$exclude = (bool) preg_match( '/(embedding|whisper|tts|dall-e|realtime|moderation|audio|image|transcribe|search|sora)/', $id );
			if ( $is_chat && ! $exclude ) {
				$models[ $id ] = $id;
			}
		}
		return $models;
	}
```

Add to **Google** (auth: `?key=`; endpoint `https://generativelanguage.googleapis.com/v1beta/models`):

```php
	public function fetch_remote_models(): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200&key=' . rawurlencode( $api_key ),
			array( 'timeout' => 15 )
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		return self::parse_models_response( (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Keep text-generation models (supportedGenerationMethods contains
	 * generateContent), excluding embedding/image-only models.
	 *
	 * @param array $body Decoded response body.
	 * @return array<string, string>
	 */
	public static function parse_models_response( array $body ): array {
		$models = array();
		foreach ( $body['models'] ?? array() as $m ) {
			$name    = (string) ( $m['name'] ?? '' );
			$methods = (array) ( $m['supportedGenerationMethods'] ?? array() );
			if ( '' === $name || ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}
			$id            = preg_replace( '#^models/#', '', $name );
			$models[ $id ] = (string) ( $m['displayName'] ?? $id );
		}
		return $models;
	}
```

Add to **xAI** (auth: `Authorization: Bearer`; endpoint `https://api.x.ai/v1/models`):

```php
	public function fetch_remote_models(): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			'https://api.x.ai/v1/models',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		return self::parse_models_response( (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Keep grok-* chat models only.
	 *
	 * @param array $body Decoded response body.
	 * @return array<string, string>
	 */
	public static function parse_models_response( array $body ): array {
		$models = array();
		foreach ( $body['data'] ?? array() as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			if ( '' !== $id && 0 === strpos( $id, 'grok-' ) && false === strpos( $id, 'image' ) ) {
				$models[ $id ] = $id;
			}
		}
		return $models;
	}
```

- [ ] **Step 4: Run → pass** — `vendor/bin/phpunit --testsuite unit`.
- [ ] **Step 5: Gate** — PHPStan `[OK]`; PHPCS no new errors on the 4 providers.
- [ ] **Step 6: Commit** — `feat: per-provider fetch_remote_models() + pure parsers (+unit tests)`.

> **Verify-against-live (implementation note, not a placeholder):** the include/exclude regexes are conservative starting points. After implementing, run one live `fetch_remote_models()` per provider you have a key for (via `wp eval`) and confirm the returned set looks right; tighten the filters if a provider surfaces an unexpected family. Curated lists remain the canonical labels regardless.

---

## Task 3: `SWPS_Model_Discovery` service (TDD for merge + diff)

**Files:** Create `includes/class-model-discovery.php`; create `tests/unit/ModelDiscoveryTest.php`. (If `phpunit.xml.dist` lacks a `unit` testsuite — i.e. #41 not merged — add it: a `<testsuite name="unit"><directory>tests/unit</directory></testsuite>` entry.)

- [ ] **Step 1: Failing tests** — `tests/unit/ModelDiscoveryTest.php` covering the two pure helpers:

```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-model-discovery.php';

final class ModelDiscoveryTest extends TestCase {

	public function test_merge_prefers_curated_label_and_order(): void {
		$curated    = array( 'a' => 'Curated A', 'b' => 'Curated B' );
		$discovered = array( 'b' => 'Discovered B', 'c' => 'Discovered C' );
		$merged     = SWPS_Model_Discovery::merge_models( $curated, $discovered );
		// Curated entries keep their label and come first; new discovered appended.
		$this->assertSame(
			array( 'a' => 'Curated A', 'b' => 'Curated B', 'c' => 'Discovered C' ),
			$merged
		);
	}

	public function test_diff_returns_only_unknown_ids(): void {
		$known     = array( 'a', 'b' );
		$current   = array( 'a', 'b', 'c', 'd' );
		$this->assertSame( array( 'c', 'd' ), SWPS_Model_Discovery::diff_new_ids( $known, $current ) );
	}

	public function test_diff_empty_known_returns_all(): void {
		$this->assertSame( array( 'a' ), SWPS_Model_Discovery::diff_new_ids( array(), array( 'a' ) ) );
	}
}
```

- [ ] **Step 2: Run → fail.**

- [ ] **Step 3: Implement** `includes/class-model-discovery.php`:

```php
<?php
/**
 * Discovers available models from provider APIs and merges them with the
 * curated lists. Refreshed daily; never auto-switches the selected model.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Model_Discovery {

	private const OPTION_DISCOVERED = 'swps_discovered_models'; // [ slug => [id=>label] ].
	private const OPTION_KNOWN      = 'swps_known_model_ids';   // [ id, ... ].
	private const OPTION_NEW        = 'swps_new_models_available'; // [ id => label ].

	/** Curated ∪ discovered text models for a provider. */
	public function get_text_models( string $provider_slug ): array {
		$curated    = SWPS_Provider_Factory::curated_models_for_provider( $provider_slug );
		$discovered = ( get_option( self::OPTION_DISCOVERED, array() )[ $provider_slug ] ?? array() );
		return self::merge_models( $curated, $discovered );
	}

	/** Curated ∪ discovered Gemini image models. */
	public function get_image_models(): array {
		$curated    = SWPS_Gemini_Image_Provider::curated_models();
		$discovered = ( get_option( self::OPTION_DISCOVERED, array() )['gemini-image'] ?? array() );
		return self::merge_models( $curated, $discovered );
	}

	/** Daily cron: refresh discovered models for every configured provider. */
	public function refresh(): void {
		$store = (array) get_option( self::OPTION_DISCOVERED, array() );
		$seen  = array();

		foreach ( SWPS_Provider_Factory::all_ai_providers() as $slug => $provider ) {
			$found = $provider->fetch_remote_models();
			if ( ! empty( $found ) ) {
				$store[ $slug ] = $found;            // keep last-good if empty.
			}
			$seen = array_merge( $seen, array_keys( $store[ $slug ] ?? array() ) );
		}

		$image_provider = new SWPS_Gemini_Image_Provider();
		$found_image    = $image_provider->fetch_remote_models();
		if ( ! empty( $found_image ) ) {
			$store['gemini-image'] = $found_image;
		}
		$seen = array_merge( $seen, array_keys( $store['gemini-image'] ?? array() ) );

		update_option( self::OPTION_DISCOVERED, $store );

		$known = (array) get_option( self::OPTION_KNOWN, array() );
		if ( empty( $known ) ) {
			update_option( self::OPTION_KNOWN, array_values( array_unique( $seen ) ) ); // seed silently.
			return;
		}

		$new = self::diff_new_ids( $known, array_values( array_unique( $seen ) ) );
		if ( ! empty( $new ) ) {
			$labels = self::labels_for( $store, $new );
			update_option( self::OPTION_NEW, array_replace( (array) get_option( self::OPTION_NEW, array() ), $labels ) );
			update_option( self::OPTION_KNOWN, array_values( array_unique( array_merge( $known, $new ) ) ) );
		}
	}

	public function dismiss_alert(): void {
		delete_option( self::OPTION_NEW );
	}

	public function new_models(): array {
		return (array) get_option( self::OPTION_NEW, array() );
	}

	/** Curated ∪ discovered; curated label + order wins, new discovered appended. */
	public static function merge_models( array $curated, array $discovered ): array {
		$merged = $curated;
		foreach ( $discovered as $id => $label ) {
			if ( ! array_key_exists( $id, $merged ) ) {
				$merged[ $id ] = $label;
			}
		}
		return $merged;
	}

	/** IDs in $current not present in $known. */
	public static function diff_new_ids( array $known, array $current ): array {
		return array_values( array_diff( $current, $known ) );
	}

	/** Look up labels for IDs across the per-slug discovered store. */
	private static function labels_for( array $store, array $ids ): array {
		$flat = array();
		foreach ( $store as $models ) {
			$flat += (array) $models;
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = $flat[ $id ] ?? $id;
		}
		return $out;
	}
}
```

- [ ] **Step 4: Run → pass** (`--testsuite unit`).
- [ ] **Step 5: Gate** — PHPStan `[OK]` (note: this references `SWPS_Provider_Factory::curated_models_for_provider/all_ai_providers` and `SWPS_Gemini_Image_Provider::curated_models` — added in Tasks 4 & 5; if implementing strictly in order, those methods land in Task 4/5 and PHPStan goes green then. Sequence Task 4 immediately after.)
- [ ] **Step 6: Commit** — `feat: SWPS_Model_Discovery service (merge/diff/refresh/dismiss + unit tests)`.

---

## Task 4: Factory wiring — merged dropdown + helpers

**Files:** Modify `includes/class-provider-factory.php`

- [ ] **Step 1:** Add a `curated_models_for_provider()` (the current `get_models_for_provider` body) and an `all_ai_providers()` helper, then make `get_models_for_provider()` return the **merged** list:

```php
	/** Curated (hardcoded) models for a provider — the canonical labels/order. */
	public static function curated_models_for_provider( string $slug ): array {
		$class = self::AI_PROVIDERS[ $slug ] ?? null;
		if ( ! $class ) {
			return array();
		}
		return ( new $class() )->get_available_models();
	}

	/** Instantiate every AI provider, keyed by slug. */
	public static function all_ai_providers(): array {
		$out = array();
		foreach ( self::AI_PROVIDERS as $slug => $class ) {
			$out[ $slug ] = new $class();
		}
		return $out;
	}

	/** Curated ∪ discovered models for the dropdown. */
	public static function get_models_for_provider( string $slug ): array {
		return ( new SWPS_Model_Discovery() )->get_text_models( $slug );
	}
```

(`get_text_models()` itself calls `curated_models_for_provider()`, so the curated list is always the floor even if discovery is empty.)

- [ ] **Step 2: Gate** — PHPStan `[OK]`; `composer test` green (Task 3's service now resolves). PHPCS no new errors.
- [ ] **Step 3: Commit** — `feat: factory returns curated ∪ discovered models`.

---

## Task 5: Gemini image model — discovery + selectable setting

**Files:** Modify `includes/providers/images/class-gemini-provider.php`, `includes/class-settings.php`, `admin/js/admin.js`, `stratawp-seo.php` (default)

- [ ] **Step 1:** In `class-gemini-provider.php`, add curated image models + remote fetch, and read the setting. Replace the bare `self::MODEL` usage in `generate_image()`'s URL with the configured model:

```php
	/** Curated Gemini image models — MODEL is the default/fallback. */
	public static function curated_models(): array {
		return array(
			self::MODEL => 'Gemini Image (default)',
		);
	}

	/** The configured image model, falling back to the constant. */
	private function image_model(): string {
		$model = (string) get_option( 'swps_gemini_image_model', '' );
		return '' !== $model ? $model : self::MODEL;
	}

	public function fetch_remote_models(): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200&key=' . rawurlencode( $api_key ),
			array( 'timeout' => 15 )
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		return self::parse_models_response( (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Keep image-generation-capable Gemini models.
	 *
	 * @param array $body Decoded /v1beta/models body.
	 * @return array<string, string>
	 */
	public static function parse_models_response( array $body ): array {
		$models = array();
		foreach ( $body['models'] ?? array() as $m ) {
			$name = (string) ( $m['name'] ?? '' );
			if ( '' === $name || false === strpos( $name, 'image' ) ) {
				continue;
			}
			$id            = preg_replace( '#^models/#', '', $name );
			$models[ $id ] = (string) ( $m['displayName'] ?? $id );
		}
		return $models;
	}
```

Then in `generate_image()`, change the URL build from `self::API_BASE . self::MODEL . ':generateContent...'` to `self::API_BASE . $this->image_model() . ':generateContent...'`.

> **Verify-against-live:** confirm the `image`-in-name filter matches Google's actual image-model naming for your key; widen to a `supportedGenerationMethods` check if needed.

- [ ] **Step 2:** Register the setting + default. In `stratawp-seo.php` defaults array add `'gemini_image_model' => ''`. In `class-settings.php`, in the Featured Images section, register `swps_gemini_image_model` and add a `select` field (after the Gemini key info row) whose options come from `( new SWPS_Model_Discovery() )->get_image_models()`, with `row_class => 'swps-image-key-row swps-image-provider-gemini'` so it shows only when Gemini is the image provider (existing visibility pattern). Mirror the existing `add_field`/`register_setting` calls in that section.

- [ ] **Step 3:** `admin.js` — the image-model row uses the existing `swps-image-provider-gemini` class, so `updateImageKeyVisibility()` already shows/hides it with the other Gemini rows. Confirm no JS change needed (verify the row class matches); if the field needs to show regardless of the "featured images enabled" gate, adjust the selector. `node --check admin/js/admin.js`.

- [ ] **Step 4: Gate** — PHPStan `[OK]`; PHPCS no new errors; `composer test` green.
- [ ] **Step 5: Commit** — `feat: selectable Gemini image model with discovery + fallback`.

---

## Task 6: Daily refresh cron

**Files:** Create `includes/class-model-cron.php`; modify `stratawp-seo.php`

- [ ] **Step 1:** Create `includes/class-model-cron.php` mirroring `SWPS_Cron`'s schedule pattern:

```php
<?php
/**
 * Daily WP-Cron job that refreshes discovered models.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Model_Cron {

	private const HOOK = 'swps_refresh_models';

	private SWPS_Model_Discovery $discovery;

	public function __construct( SWPS_Model_Discovery $discovery ) {
		$this->discovery = $discovery;
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public function run(): void {
		$this->discovery->refresh();
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
		wp_unschedule_hook( self::HOOK );
	}
}
```

- [ ] **Step 2:** In `stratawp-seo.php`, instantiate the service + cron once (near the other subsystem init): `$discovery = new SWPS_Model_Discovery(); new SWPS_Model_Cron( $discovery );`. Call `SWPS_Model_Cron::unschedule()` in the plugin's deactivation handler (find the existing `register_deactivation_hook` / deactivate method and add it alongside the other unschedules).
- [ ] **Step 3: Gate** — PHPStan `[OK]`; PHPCS no new errors.
- [ ] **Step 4: Integration check (WP-CLI, staging):** `wp cron event run swps_refresh_models`; then `wp option get swps_discovered_models --format=json` (populated for configured providers) and `wp option get swps_known_model_ids --format=json` (seeded). `wp option get swps_new_models_available` empty on first run (silent seed).
- [ ] **Step 5: Commit** — `feat: daily swps_refresh_models cron`.

---

## Task 7: New-model admin notice + dismiss

**Files:** Modify `stratawp-seo.php` (or a small admin class)

- [ ] **Step 1:** Register an `admin_notices` handler + a dismiss AJAX action:

```php
	public function model_alert_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$new = ( new SWPS_Model_Discovery() )->new_models();
		if ( empty( $new ) ) {
			return;
		}
		$names = implode( ', ', array_map( 'esc_html', array_values( $new ) ) );
		$url   = esc_url( admin_url( 'admin.php?page=swps-settings' ) );
		printf(
			'<div class="notice notice-info is-dismissible swps-model-alert"><p>%s <a href="%s">%s</a></p></div>',
			sprintf(
				/* translators: %s = comma-separated model names */
				esc_html__( 'StrataWP SEO: new AI model(s) available — %s.', 'stratawp-seo' ),
				$names // already escaped above.
			),
			$url,
			esc_html__( 'Choose in Settings', 'stratawp-seo' )
		);
	}

	public function ajax_dismiss_model_alert(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		( new SWPS_Model_Discovery() )->dismiss_alert();
		wp_send_json_success();
	}
```

Hook them: `add_action( 'admin_notices', array( $this, 'model_alert_notice' ) );` and `add_action( 'wp_ajax_swps_dismiss_model_alert', array( $this, 'ajax_dismiss_model_alert' ) );`. Wire the WP `is-dismissible` close button to the AJAX action in `admin.js` (jQuery `.on('click', '.swps-model-alert .notice-dismiss', ...)` POSTing `action=swps_dismiss_model_alert` + `swpsAdmin.nonce`).

> The `$names` string is pre-escaped via `array_map('esc_html', ...)`; pass it through `%s` without re-escaping. Verify PHPCS is satisfied (it may want a `wp_kses_post` wrapper — adjust to satisfy the sniff without double-escaping).

- [ ] **Step 2: Gate** — PHPStan `[OK]`; PHPCS no new errors; `node --check admin/js/admin.js`.
- [ ] **Step 3: Integration check (staging):** with `swps_new_models_available` set, load any admin page → notice shows; dismiss → option cleared (`wp option get swps_new_models_available` → empty/false).
- [ ] **Step 4: Commit** — `feat: dismissible new-model admin notice`.

---

## Task 8: Version bump, changelog, zip

**Files:** `stratawp-seo.php`, `README.md`, `readme.txt`

- [ ] **Step 1:** Bump version (read the current values first — depends on whether #41 merged: likely **4.8.0** if #41 (4.7.0) is in, else reconcile). Update the header `Version:`, `SWPS_VERSION`, `readme.txt` `Stable tag:`, and the `README.md` badge to the chosen version.
- [ ] **Step 2:** Changelog entries (dated `= X.Y.Z — 2026-MM-DD =` in readme.txt; `### vX.Y.Z — Month Year` in README.md):
  - "Feature: AI models are now auto-discovered from each configured provider's API (daily) and added to the model dropdown automatically, with a dismissible alert when a new model appears."
  - "Feature: the Gemini image model is now selectable in Settings (auto-discovered), replacing the hardcoded model — no more breakage when Google renames the image model."
- [ ] **Step 3: Gate** — PHPStan `[OK]`; `composer test` green.
- [ ] **Step 4:** Rebuild the deployment zip (same exclude list as the prior release; exclude `docs/`, `tests/`, `vendor/`, `.git/`, dev config, `.phpunit.cache/`).
- [ ] **Step 5: Commit** — `chore: release vX.Y.0 — model discovery`.

---

## Self-review (completed)

- **Spec coverage:** §1 service → Task 3; §2 per-provider fetch+parse → Tasks 1–2 (text) + Task 5 (image); §3 dropdown wiring → Task 4 (text) + Task 5 (image setting); §4 cron → Task 6; §5 alert → Task 7; rollout → Task 8. Graceful-degradation (empty/error → curated) baked into `fetch_remote_models()` (returns `[]`) + `merge_models()` floor. First-run silent seed → Task 3 `refresh()`. No-auto-switch → discovery only adds options; selection unchanged.
- **Method-name consistency:** `fetch_remote_models`, `parse_models_response`, `merge_models`, `diff_new_ids`, `get_text_models`, `get_image_models`, `refresh`, `dismiss_alert`, `new_models`, `curated_models_for_provider`, `all_ai_providers`, `curated_models`, `image_model` — used identically across tasks.
- **Cross-task dependency:** Task 3's service references factory/provider helpers defined in Tasks 4 & 5 — implement in order 1→2→3→4→5→6→7→8; PHPStan goes green at Task 4/5 (note in Task 3). 
- **Testing reality:** pure parsers/merge/diff are unit-tested (no WP); HTTP, cron, settings, notice verified via WP-CLI/browser on staging.
