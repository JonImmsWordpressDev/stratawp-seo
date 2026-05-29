# Dynamic Model Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace hardcoded AI-model lists and hand-written labels with a fully dynamic list (live discovery, filtered to text-gen models) and heuristic-driven superlative tags (Most powerful / Cheapest / Costs most / Best value) across all four providers.

**Architecture:** A new pure-PHP `SWPS_Model_Catalog` derives family → power rank + default price from a model ID and decorates a model list with superlative tags. `SWPS_Model_Discovery::get_text_models()` serves discovered-or-fallback models decorated by the Catalog; the cost tracker and the model validator both read from the same source, fixing the bug where a discovered model silently reverts at generation time.

**Tech Stack:** PHP 8.x, WordPress plugin (no runtime autoloader — every class is `require_once`'d in `stratawp-seo.php`), PHPUnit (pure-PHP unit tests, no WP bootstrap — see `tests/bootstrap.php`), PHPCS (WordPress standard), PHPStan.

**Conventions:** Match the codebase — `array()` long syntax, Yoda conditions, tabs for indentation, `1_000_000` numeric separators. Run a single test with `vendor/bin/phpunit --filter <name>`; the whole suite with `composer test`.

---

### Task 1: `SWPS_Model_Catalog` — metadata + power score

**Files:**
- Create: `includes/class-model-catalog.php`
- Test: `tests/unit/ModelCatalogTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/ModelCatalogTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-model-catalog.php';

final class ModelCatalogTest extends TestCase {

	public function test_metadata_detects_anthropic_opus(): void {
		$m = SWPS_Model_Catalog::metadata( 'claude-opus-4-8' );
		$this->assertSame( 'anthropic', $m['provider'] );
		$this->assertSame( 30048, $m['power_score'] );
		$this->assertSame( 75.0, $m['price_out'] );
	}

	public function test_metadata_strips_date_suffix_for_version(): void {
		$this->assertSame( 30041, SWPS_Model_Catalog::metadata( 'claude-opus-4-1-20250805' )['power_score'] );
		$this->assertSame( 30040, SWPS_Model_Catalog::metadata( 'claude-opus-4-20250514' )['power_score'] );
	}

	public function test_power_score_orders_by_tier_then_version(): void {
		$score = fn( string $id ) => SWPS_Model_Catalog::metadata( $id )['power_score'];
		$this->assertGreaterThan( $score( 'claude-opus-4-7' ), $score( 'claude-opus-4-8' ) );
		$this->assertGreaterThan( $score( 'claude-sonnet-4-6' ), $score( 'claude-opus-4-6' ) );
		$this->assertGreaterThan( $score( 'claude-haiku-4-5-20251001' ), $score( 'claude-sonnet-4-6' ) );
	}

	public function test_metadata_detects_other_providers(): void {
		$this->assertSame( 'openai', SWPS_Model_Catalog::metadata( 'gpt-4.1' )['provider'] );
		$this->assertSame( 'google', SWPS_Model_Catalog::metadata( 'gemini-2.5-pro' )['provider'] );
		$this->assertSame( 'xai', SWPS_Model_Catalog::metadata( 'grok-3' )['provider'] );
	}

	public function test_metadata_unknown_model_is_null(): void {
		$m = SWPS_Model_Catalog::metadata( 'totally-unknown-model' );
		$this->assertNull( $m['provider'] );
		$this->assertNull( $m['power_score'] );
		$this->assertNull( $m['price_out'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ModelCatalogTest`
Expected: FAIL — `Error: Failed opening required '.../class-model-catalog.php'` (file does not exist yet).

- [ ] **Step 3: Write minimal implementation**

Create `includes/class-model-catalog.php`:

```php
<?php
/**
 * Heuristic model catalog: derives provider, power rank, and default pricing
 * from a model ID, and computes dynamic superlative labels for a model list.
 *
 * Pure PHP — no WordPress calls — so it is unit-testable without a WP bootstrap.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Model_Catalog {

	/**
	 * Per-provider heuristic rules. Provider is detected by the `id` pattern;
	 * the family (power rank + default USD price per 1M input/output) is the
	 * first matching `match`, evaluated in order (most specific first, with a
	 * catch-all last so unknown sub-families still get a sane mid-tier default).
	 */
	private const RULES = array(
		'anthropic' => array(
			'id'       => '/^claude-/',
			'families' => array(
				array( 'match' => '/opus/',   'rank' => 30, 'in' => 15.00, 'out' => 75.00 ),
				array( 'match' => '/sonnet/', 'rank' => 20, 'in' => 3.00,  'out' => 15.00 ),
				array( 'match' => '/haiku/',  'rank' => 10, 'in' => 0.80,  'out' => 4.00 ),
				array( 'match' => '/claude/', 'rank' => 20, 'in' => 3.00,  'out' => 15.00 ),
			),
		),
		'openai'    => array(
			'id'       => '/^(gpt-|o\d|chatgpt-)/',
			'families' => array(
				array( 'match' => '/nano/',   'rank' => 10, 'in' => 0.10, 'out' => 0.40 ),
				array( 'match' => '/mini/',   'rank' => 15, 'in' => 0.40, 'out' => 1.60 ),
				array( 'match' => '/^o\d/',   'rank' => 30, 'in' => 1.10, 'out' => 4.40 ),
				array( 'match' => '/gpt-4o/', 'rank' => 22, 'in' => 2.50, 'out' => 10.00 ),
				array( 'match' => '/gpt-/',   'rank' => 25, 'in' => 2.00, 'out' => 8.00 ),
			),
		),
		'google'    => array(
			'id'       => '/^(gemini|gemma)/',
			'families' => array(
				array( 'match' => '/flash-lite/', 'rank' => 10, 'in' => 0.075, 'out' => 0.30 ),
				array( 'match' => '/flash/',      'rank' => 20, 'in' => 0.15,  'out' => 0.60 ),
				array( 'match' => '/pro/',        'rank' => 30, 'in' => 1.25,  'out' => 10.00 ),
				array( 'match' => '/gemma/',      'rank' => 5,  'in' => 0.00,  'out' => 0.00 ),
				array( 'match' => '/gemini/',     'rank' => 20, 'in' => 0.15,  'out' => 0.60 ),
			),
		),
		'xai'       => array(
			'id'       => '/^grok/',
			'families' => array(
				array( 'match' => '/mini/', 'rank' => 10, 'in' => 0.30, 'out' => 0.50 ),
				array( 'match' => '/fast/', 'rank' => 20, 'in' => 5.00, 'out' => 25.00 ),
				array( 'match' => '/grok/', 'rank' => 20, 'in' => 3.00, 'out' => 15.00 ),
			),
		),
	);

	/**
	 * Default price (USD per 1M input/output) for models with no family match.
	 */
	private const DEFAULT_PRICE = array( 'input' => 3.00, 'output' => 15.00 );

	/**
	 * Derive heuristic metadata for a model ID.
	 *
	 * @param string $id Model identifier.
	 * @return array{provider: string|null, power_score: int|null, price_in: float|null, price_out: float|null}
	 */
	public static function metadata( string $id ): array {
		$none = array(
			'provider'    => null,
			'power_score' => null,
			'price_in'    => null,
			'price_out'   => null,
		);

		foreach ( self::RULES as $provider => $rules ) {
			if ( ! preg_match( $rules['id'], $id ) ) {
				continue;
			}
			foreach ( $rules['families'] as $family ) {
				if ( preg_match( $family['match'], $id ) ) {
					$version = self::parse_version( $id );
					return array(
						'provider'    => $provider,
						'power_score' => (int) ( $family['rank'] * 1000 + (int) round( $version * 10 ) ),
						'price_in'    => (float) $family['in'],
						'price_out'   => (float) $family['out'],
					);
				}
			}
			return array_merge( $none, array( 'provider' => $provider ) );
		}

		return $none;
	}

	/**
	 * Parse a numeric version from a model ID for ordering within a tier.
	 *
	 * Strips a trailing date suffix, then reads the first number, treating the
	 * first internal separator as a decimal point (e.g. `opus-4-8` => 4.8).
	 *
	 * @param string $id Model identifier.
	 * @return float
	 */
	private static function parse_version( string $id ): float {
		$id = preg_replace( '/-\d{8}$/', '', $id );
		$id = preg_replace( '/-\d{4}-\d{2}-\d{2}$/', '', $id );
		if ( preg_match( '/(\d+)(?:[.-](\d+))?/', $id, $m ) ) {
			return (float) ( $m[1] . ( isset( $m[2] ) ? '.' . $m[2] : '' ) );
		}
		return 0.0;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ModelCatalogTest`
Expected: PASS — `OK (5 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-model-catalog.php tests/unit/ModelCatalogTest.php
git commit -m "feat: add SWPS_Model_Catalog metadata + power score heuristics"
```

---

### Task 2: Catalog — `price_for()`

**Files:**
- Modify: `includes/class-model-catalog.php`
- Test: `tests/unit/ModelCatalogTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/unit/ModelCatalogTest.php`:

```php
	public function test_price_for_known_model(): void {
		$this->assertSame( array( 'input' => 15.0, 'output' => 75.0 ), SWPS_Model_Catalog::price_for( 'claude-opus-4-8' ) );
		$this->assertSame( array( 'input' => 0.8, 'output' => 4.0 ), SWPS_Model_Catalog::price_for( 'claude-haiku-4-5-20251001' ) );
	}

	public function test_price_for_unknown_model_uses_default(): void {
		$this->assertSame( array( 'input' => 3.0, 'output' => 15.0 ), SWPS_Model_Catalog::price_for( 'who-knows-9000' ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_price_for`
Expected: FAIL — `Error: Call to undefined method SWPS_Model_Catalog::price_for()`.

- [ ] **Step 3: Write minimal implementation**

Add this method to `SWPS_Model_Catalog` (after `metadata()`):

```php
	/**
	 * Resolve input/output price (USD per 1M tokens) for a model, falling back
	 * to a default for unknown models. Used by the cost tracker.
	 *
	 * @param string $id Model identifier.
	 * @return array{input: float, output: float}
	 */
	public static function price_for( string $id ): array {
		$m = self::metadata( $id );
		if ( null !== $m['price_in'] ) {
			return array(
				'input'  => $m['price_in'],
				'output' => $m['price_out'],
			);
		}
		return self::DEFAULT_PRICE;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ModelCatalogTest`
Expected: PASS — `OK (7 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-model-catalog.php tests/unit/ModelCatalogTest.php
git commit -m "feat: add SWPS_Model_Catalog::price_for"
```

---

### Task 3: Catalog — `decorate_labels()` (superlatives)

**Files:**
- Modify: `includes/class-model-catalog.php`
- Test: `tests/unit/ModelCatalogTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/unit/ModelCatalogTest.php`:

```php
	public function test_decorate_labels_tags_superlatives(): void {
		$models = array(
			'claude-opus-4-8'           => 'Claude Opus 4.8',
			'claude-opus-4-7'           => 'Claude Opus 4.7',
			'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
			'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
		);
		$out = SWPS_Model_Catalog::decorate_labels( $models );
		$this->assertSame( 'Claude Opus 4.8 — Most powerful · Costs most', $out['claude-opus-4-8'] );
		$this->assertSame( 'Claude Sonnet 4.6 — Best value', $out['claude-sonnet-4-6'] );
		$this->assertSame( 'Claude Haiku 4.5 — Cheapest', $out['claude-haiku-4-5-20251001'] );
		$this->assertSame( 'Claude Opus 4.7', $out['claude-opus-4-7'] );
	}

	public function test_decorate_labels_noop_for_single_model(): void {
		$models = array( 'claude-opus-4-8' => 'Claude Opus 4.8' );
		$this->assertSame( $models, SWPS_Model_Catalog::decorate_labels( $models ) );
	}

	public function test_decorate_labels_no_price_tags_when_all_same_price(): void {
		$models = array(
			'claude-opus-4-8' => 'Claude Opus 4.8',
			'claude-opus-4-7' => 'Claude Opus 4.7',
		);
		$out = SWPS_Model_Catalog::decorate_labels( $models );
		$this->assertSame( 'Claude Opus 4.8 — Most powerful', $out['claude-opus-4-8'] );
		$this->assertSame( 'Claude Opus 4.7', $out['claude-opus-4-7'] );
	}

	public function test_decorate_labels_leaves_unknown_models_untagged(): void {
		$models = array( 'mystery-a' => 'Mystery A', 'mystery-b' => 'Mystery B' );
		$this->assertSame( $models, SWPS_Model_Catalog::decorate_labels( $models ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_decorate_labels`
Expected: FAIL — `Error: Call to undefined method SWPS_Model_Catalog::decorate_labels()`.

- [ ] **Step 3: Write minimal implementation**

Add this method to `SWPS_Model_Catalog` (after `price_for()`):

```php
	/**
	 * Decorate a provider's `id => display_name` map with dynamic superlative
	 * tags computed across the set. Keys (model IDs) are never modified.
	 *
	 * @param array<string, string> $models id => display name.
	 * @return array<string, string> id => decorated label.
	 */
	public static function decorate_labels( array $models ): array {
		if ( count( $models ) < 2 ) {
			return $models;
		}

		$meta = array();
		foreach ( $models as $id => $label ) {
			$meta[ $id ] = self::metadata( (string) $id );
		}

		$tags = array();

		// Most powerful: highest power score.
		$top_power = null;
		$top_id    = null;
		foreach ( $meta as $id => $m ) {
			if ( null !== $m['power_score'] && ( null === $top_power || $m['power_score'] > $top_power ) ) {
				$top_power = $m['power_score'];
				$top_id    = $id;
			}
		}
		if ( null !== $top_id ) {
			$tags[ $top_id ][] = 'Most powerful';
		}

		// Price-based tags only when there is an actual spread.
		$priced = array();
		foreach ( $meta as $id => $m ) {
			if ( null !== $m['price_out'] ) {
				$priced[ $id ] = $m['price_out'];
			}
		}
		if ( count( $priced ) >= 2 ) {
			$max_out = max( $priced );
			$min_out = min( $priced );
			if ( $max_out > $min_out ) {
				$costliest = array_search( $max_out, $priced, true );
				$cheapest  = array_search( $min_out, $priced, true );
				if ( false !== $costliest ) {
					$tags[ $costliest ][] = 'Costs most';
				}
				if ( false !== $cheapest ) {
					$tags[ $cheapest ][] = 'Cheapest';
				}

				// Best value: highest power among models priced below the max.
				$bv_power = null;
				$bv_id    = null;
				foreach ( $priced as $id => $out ) {
					if ( $out >= $max_out ) {
						continue;
					}
					$p = $meta[ $id ]['power_score'];
					if ( null !== $p && ( null === $bv_power || $p > $bv_power ) ) {
						$bv_power = $p;
						$bv_id    = $id;
					}
				}
				if ( null !== $bv_id ) {
					$tags[ $bv_id ][] = 'Best value';
				}
			}
		}

		$out = array();
		foreach ( $models as $id => $label ) {
			$out[ $id ] = isset( $tags[ $id ] )
				? $label . ' — ' . implode( ' · ', $tags[ $id ] )
				: $label;
		}
		return $out;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ModelCatalogTest`
Expected: PASS — `OK (11 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-model-catalog.php tests/unit/ModelCatalogTest.php
git commit -m "feat: add SWPS_Model_Catalog::decorate_labels superlative tagging"
```

---

### Task 4: Register the Catalog class (no runtime autoloader)

**Files:**
- Modify: `stratawp-seo.php:50` (just before the model-discovery require)

- [ ] **Step 1: Add the require**

In `stratawp-seo.php`, immediately **before** the existing line `require_once SWPS_PLUGIN_DIR . 'includes/class-model-discovery.php';` (line 50), add:

```php
require_once SWPS_PLUGIN_DIR . 'includes/class-model-catalog.php';
```

- [ ] **Step 2: Verify it parses and is wired**

Run: `php -l includes/class-model-catalog.php`
Expected: `No syntax errors detected`.

Run: `grep -n "class-model-catalog.php" stratawp-seo.php`
Expected: one line showing the new require above the model-discovery require.

- [ ] **Step 3: Run the full suite (no regressions)**

Run: `composer test`
Expected: PASS — all suites green.

- [ ] **Step 4: Commit**

```bash
git add stratawp-seo.php
git commit -m "feat: require SWPS_Model_Catalog in plugin bootstrap"
```

---

### Task 5: Tighten the Google model filter

**Files:**
- Modify: `includes/providers/ai/class-google-provider.php:142-154` (`parse_models_response`)
- Test: `tests/unit/RemoteModelsParserTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/unit/RemoteModelsParserTest.php`:

```php
	public function test_google_excludes_nontext_models_reporting_generatecontent(): void {
		$body = array( 'models' => array(
			array( 'name' => 'models/gemini-3-pro', 'displayName' => 'Gemini 3 Pro', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/gemma-4-31b-it', 'displayName' => 'Gemma 4 31B', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/gemini-2.5-flash-image', 'displayName' => 'Nano Banana', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/gemini-2.5-flash-preview-tts', 'displayName' => 'TTS', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/lyria-3-pro-preview', 'displayName' => 'Lyria', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/gemini-robotics-er-1.5-preview', 'displayName' => 'Robotics', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/nano-banana-pro-preview', 'displayName' => 'Nano Banana Pro', 'supportedGenerationMethods' => array( 'generateContent' ) ),
		) );
		$out = SWPS_Google_Provider::parse_models_response( $body );
		$this->assertArrayHasKey( 'gemini-3-pro', $out );
		$this->assertArrayHasKey( 'gemma-4-31b-it', $out );
		$this->assertArrayNotHasKey( 'gemini-2.5-flash-image', $out );
		$this->assertArrayNotHasKey( 'gemini-2.5-flash-preview-tts', $out );
		$this->assertArrayNotHasKey( 'lyria-3-pro-preview', $out );
		$this->assertArrayNotHasKey( 'gemini-robotics-er-1.5-preview', $out );
		$this->assertArrayNotHasKey( 'nano-banana-pro-preview', $out );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_google_excludes_nontext_models_reporting_generatecontent`
Expected: FAIL — the image/TTS/Lyria/robotics models are still present (current filter only checks `generateContent`).

- [ ] **Step 3: Write minimal implementation**

In `class-google-provider.php`, replace the body of `parse_models_response()` with:

```php
	public static function parse_models_response( array $body ): array {
		$models = array();
		foreach ( $body['models'] ?? array() as $m ) {
			$name    = (string) ( $m['name'] ?? '' );
			$methods = (array) ( $m['supportedGenerationMethods'] ?? array() );
			if ( '' === $name || ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}
			$id = preg_replace( '#^models/#', '', $name );
			// Drop non-text-generation models that still report generateContent
			// (image, TTS, music, robotics, agents, etc.).
			if ( preg_match( '/(image|tts|lyria|veo|imagen|robotics|computer-use|deep-research|antigravity|nano-banana|aqa)/i', $id ) ) {
				continue;
			}
			$models[ $id ] = (string) ( $m['displayName'] ?? $id );
		}
		return $models;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter RemoteModelsParserTest`
Expected: PASS — all parser tests including the new one and the existing `test_google_keeps_generatecontent_only`.

- [ ] **Step 5: Commit**

```bash
git add includes/providers/ai/class-google-provider.php tests/unit/RemoteModelsParserTest.php
git commit -m "fix: exclude non-text Google models (image/tts/music/robotics) from discovery"
```

---

### Task 6: Cost tracker reads pricing from the Catalog

**Files:**
- Modify: `includes/class-cost-tracker.php` (remove `PRICING` const lines 20-97; rewrite `calculate_cost` lines 156-166)
- Test: `tests/unit/CostTrackerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/CostTrackerTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-model-catalog.php';
require_once __DIR__ . '/../../includes/class-cost-tracker.php';

final class CostTrackerTest extends TestCase {

	public function test_opus_4_8_is_priced_via_catalog(): void {
		$tracker = new SWPS_Cost_Tracker();
		// 1M input + 1M output at Opus pricing (15 + 75) = 90.0.
		$this->assertEqualsWithDelta( 90.0, $tracker->calculate_cost( 'claude-opus-4-8', 1_000_000, 1_000_000 ), 0.0001 );
	}

	public function test_unknown_model_uses_default_pricing(): void {
		$tracker = new SWPS_Cost_Tracker();
		// Default 3 + 15 = 18.0.
		$this->assertEqualsWithDelta( 18.0, $tracker->calculate_cost( 'who-knows-9000', 1_000_000, 1_000_000 ), 0.0001 );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter CostTrackerTest`
Expected: FAIL — `test_opus_4_8_is_priced_via_catalog` fails: current `PRICING` has no `claude-opus-4-8`, so it falls to the hardcoded default 3/15 → 18.0, not 90.0.

- [ ] **Step 3: Write minimal implementation**

In `class-cost-tracker.php`, delete the entire `private const PRICING = array( ... );` block (lines 16-97, including its docblock). Then replace `calculate_cost()` (lines 156-166) with:

```php
	public function calculate_cost( string $model, int $input_tokens, int $output_tokens ): float {
		$pricing = SWPS_Model_Catalog::price_for( $model );

		$input_cost  = ( $input_tokens / 1_000_000 ) * $pricing['input'];
		$output_cost = ( $output_tokens / 1_000_000 ) * $pricing['output'];

		return $input_cost + $output_cost;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter CostTrackerTest`
Expected: PASS — `OK (2 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-cost-tracker.php tests/unit/CostTrackerTest.php
git commit -m "refactor: cost tracker derives pricing from SWPS_Model_Catalog"
```

---

### Task 7: Dynamic list + validation helper in `SWPS_Model_Discovery`

**Files:**
- Modify: `includes/class-model-discovery.php` (rewrite `get_text_models()`; add `available_model_ids()`, private `resolve_models()`, static `validate_selection()`)
- Test: `tests/unit/ModelDiscoveryTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/unit/ModelDiscoveryTest.php`:

```php
	public function test_validate_selection_keeps_valid_stored_model(): void {
		$ids = array( 'claude-opus-4-8', 'claude-opus-4-7' );
		$this->assertSame( 'claude-opus-4-8', SWPS_Model_Discovery::validate_selection( 'claude-opus-4-8', $ids ) );
	}

	public function test_validate_selection_falls_back_to_first_when_invalid(): void {
		$ids = array( 'claude-opus-4-8', 'claude-opus-4-7' );
		$this->assertSame( 'claude-opus-4-8', SWPS_Model_Discovery::validate_selection( 'gone-model', $ids ) );
		$this->assertSame( 'claude-opus-4-8', SWPS_Model_Discovery::validate_selection( '', $ids ) );
	}

	public function test_validate_selection_empty_ids_returns_empty_string(): void {
		$this->assertSame( '', SWPS_Model_Discovery::validate_selection( 'anything', array() ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_validate_selection`
Expected: FAIL — `Error: Call to undefined method SWPS_Model_Discovery::validate_selection()`.

- [ ] **Step 3: Write minimal implementation**

In `class-model-discovery.php`, replace `get_text_models()` with the following, and add the three new methods alongside it:

```php
	/**
	 * Get the decorated text models for a provider (discovered, else fallback).
	 *
	 * @param string $provider_slug AI provider slug.
	 * @return array<string, string> model ID => decorated display label.
	 */
	public function get_text_models( string $provider_slug ): array {
		return SWPS_Model_Catalog::decorate_labels( $this->resolve_models( $provider_slug ) );
	}

	/**
	 * Valid model IDs for a provider (discovered, else fallback) — used to
	 * validate the saved model selection.
	 *
	 * @param string $provider_slug AI provider slug.
	 * @return array<int, string>
	 */
	public function available_model_ids( string $provider_slug ): array {
		return array_keys( $this->resolve_models( $provider_slug ) );
	}

	/**
	 * Resolve the raw model map: discovered (filtered) if present, else the
	 * provider's curated fallback list.
	 *
	 * @param string $provider_slug AI provider slug.
	 * @return array<string, string> model ID => display name.
	 */
	private function resolve_models( string $provider_slug ): array {
		$discovered = (array) ( get_option( self::OPTION_DISCOVERED, array() )[ $provider_slug ] ?? array() );
		if ( ! empty( $discovered ) ) {
			return $discovered;
		}
		return SWPS_Provider_Factory::curated_models_for_provider( $provider_slug );
	}

	/**
	 * Validate a stored model selection against the list of valid IDs, falling
	 * back to the first available ID. Pure — no WordPress calls.
	 *
	 * @param string             $stored Stored model ID.
	 * @param array<int, string> $ids    Valid model IDs.
	 * @return string
	 */
	public static function validate_selection( string $stored, array $ids ): string {
		if ( '' !== $stored && in_array( $stored, $ids, true ) ) {
			return $stored;
		}
		return $ids[0] ?? '';
	}
```

Note: `get_image_models()` and `merge_models()` are unchanged — the image picker keeps its curated ∪ discovered behavior.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ModelDiscoveryTest`
Expected: PASS — the three new tests plus the existing `merge`/`diff` tests (still green; `merge_models` is untouched).

- [ ] **Step 5: Commit**

```bash
git add includes/class-model-discovery.php tests/unit/ModelDiscoveryTest.php
git commit -m "feat: dynamic text-model list + model-selection validator in discovery"
```

---

### Task 8: Fix `get_validated_model()` so a discovered model sticks

**Files:**
- Modify: `includes/class-ai-provider.php:415-425` (`get_validated_model`)

- [ ] **Step 1: Replace the method**

In `class-ai-provider.php`, replace `get_validated_model()` (lines 415-425) with:

```php
	/**
	 * Get the validated model — falls back to the first available model
	 * (discovered, else curated fallback) if the stored model is not valid
	 * for this provider. Validating against the dynamic list ensures a
	 * discovered model the user selected is honoured at generation time.
	 */
	public function get_validated_model(): string {
		$ids = ( new SWPS_Model_Discovery() )->available_model_ids( $this->get_slug() );
		return SWPS_Model_Discovery::validate_selection( (string) get_option( 'swps_model', '' ), $ids );
	}
```

- [ ] **Step 2: Verify it parses**

Run: `php -l includes/class-ai-provider.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run the full suite (no regressions)**

Run: `composer test`
Expected: PASS — all suites green. (`RemoteModelsParserTest` requires the provider classes but never calls `get_validated_model`, so the new `SWPS_Model_Discovery` reference — used only inside the method body — does not need loading there.)

- [ ] **Step 4: Commit**

```bash
git add includes/class-ai-provider.php
git commit -m "fix: validate selected model against dynamic list, not hardcoded list"
```

---

### Task 9: Strip hand-written suffixes from fallback model lists

**Files:**
- Modify: `class-anthropic-provider.php:28-36`, `class-openai-provider.php:28-37`, `class-google-provider.php:28-35`, `class-xai-provider.php:28-34` (`get_available_models`)

Rationale: these lists are now only a fallback (when there is no API key / discovery is empty). The Catalog adds superlative tags dynamically, so the hand-written `(Most powerful, …)` suffixes must be removed to avoid doubled-up labels.

- [ ] **Step 1: Anthropic — replace `get_available_models()` body**

```php
	public function get_available_models(): array {
		return array(
			'claude-opus-4-7'            => 'Claude Opus 4.7',
			'claude-opus-4-6'            => 'Claude Opus 4.6',
			'claude-sonnet-4-6'          => 'Claude Sonnet 4.6',
			'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5',
			'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5',
		);
	}
```

- [ ] **Step 2: OpenAI — replace `get_available_models()` body**

```php
	public function get_available_models(): array {
		return array(
			'gpt-4.1'      => 'GPT-4.1',
			'gpt-4.1-mini' => 'GPT-4.1 Mini',
			'gpt-4.1-nano' => 'GPT-4.1 Nano',
			'gpt-4o'       => 'GPT-4o',
			'gpt-4o-mini'  => 'GPT-4o Mini',
			'o3-mini'      => 'o3-mini',
		);
	}
```

- [ ] **Step 3: Google — replace `get_available_models()` body**

```php
	public function get_available_models(): array {
		return array(
			'gemini-2.5-pro'        => 'Gemini 2.5 Pro',
			'gemini-2.5-flash'      => 'Gemini 2.5 Flash',
			'gemini-2.0-flash'      => 'Gemini 2.0 Flash',
			'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash Lite',
		);
	}
```

- [ ] **Step 4: xAI — replace `get_available_models()` body**

```php
	public function get_available_models(): array {
		return array(
			'grok-3'      => 'Grok 3',
			'grok-3-mini' => 'Grok 3 Mini',
			'grok-3-fast' => 'Grok 3 Fast',
		);
	}
```

- [ ] **Step 5: Verify parse + run suite**

Run: `php -l includes/providers/ai/class-anthropic-provider.php && php -l includes/providers/ai/class-openai-provider.php && php -l includes/providers/ai/class-google-provider.php && php -l includes/providers/ai/class-xai-provider.php`
Expected: `No syntax errors detected` for each.

Run: `composer test`
Expected: PASS — all suites green.

- [ ] **Step 6: Commit**

```bash
git add includes/providers/ai/class-anthropic-provider.php includes/providers/ai/class-openai-provider.php includes/providers/ai/class-google-provider.php includes/providers/ai/class-xai-provider.php
git commit -m "refactor: plain labels in fallback model lists (Catalog tags dynamically)"
```

---

### Task 10: Quality gate, manual smoke, version bump + docs + zip

**Files:**
- Modify: `stratawp-seo.php` (version header + `SWPS_VERSION`), `readme.txt`, `README.md`, any `docs/` changelog

- [ ] **Step 1: Full quality gate**

Run: `composer test && composer check`
Expected: PHPUnit all green; PHPCS no errors; PHPStan no new errors. Fix any reported issues inline (do not add to the PHPStan baseline).

- [ ] **Step 2: Manual WP-CLI smoke (on the live server, `~/www/jonimms.com/public_html`)**

After deploying the branch build, run:

```bash
wp cron event run swps_refresh_models
wp option get swps_discovered_models --format=json
```
Expected: Google list no longer contains image/TTS/Lyria/robotics IDs.

In the admin model dropdown (Settings → StrataWP SEO), confirm: `Claude Opus 4.8 — Most powerful · Costs most` near the top of the discovered set, `Best value` on a Sonnet, `Cheapest` on Haiku. Select Opus 4.8, save, then:

```bash
wp option get swps_model
```
Expected: `claude-opus-4-8` (and a test generation actually uses it — confirm in cost/usage logs).

- [ ] **Step 3: Bump version + docs**

Bump the version in `stratawp-seo.php` (plugin header `Version:` and `define( 'SWPS_VERSION', ... )`) to the next minor (e.g. `4.9.0`), update `readme.txt` `Stable tag` + changelog and `README.md`, with a changelog entry summarizing: dynamic model listing, heuristic superlative labels, Google filter tightening, unified pricing, and the selected-model fix.

- [ ] **Step 4: Build the deployment zip**

Build the distributable zip per the project's existing packaging step.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: release vX.Y.0 — dynamic model catalog"
```

---

## Self-Review

- **Spec coverage:** Catalog heuristics (Tasks 1-3) ✓; require/no-autoloader (Task 4) ✓; Google filter tightening (Task 5, the only filtering gap per the addendum) ✓; unified pricing + cost-tracker bug (Task 6) ✓; dynamic list + fallback (Task 7) ✓; selected-model fix from the addendum (Tasks 7-8) ✓; strip hand-written labels (Task 9) ✓; tests throughout; version/docs/zip ritual (Task 10) ✓.
- **Placeholder scan:** none — every code step contains complete code; every test step contains real assertions; every run step has an exact command + expected output.
- **Type consistency:** `metadata()` returns `{provider, power_score, price_in, price_out}` and is consumed consistently by `price_for()` and `decorate_labels()`; `price_for()` returns `{input, output}` matching the cost tracker's usage; `validate_selection(string, array): string` and `available_model_ids(): array` line up between Tasks 7 and 8.
