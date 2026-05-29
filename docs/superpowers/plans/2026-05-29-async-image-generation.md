# Async Image Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make scheduled (WP-Cron) posts reliably get their featured + in-content images by offloading each Gemini image to its own short background job, with failures surfaced to the Recent Activity log; and make the Gemini/Google key editable regardless of the selected text provider.

**Architecture:** `generate_post()` saves the post text and fires `swps_post_created`; a handler enqueues a featured-image job + a self-rescheduling in-content-image job via `SWPS_Background_Processor` (Action Scheduler → `wp_schedule_single_event` fallback). Each job runs in its own request doing a single ~60s Gemini call — which fits the cron budget the AI-text call already proves exists. In-content jobs run strictly sequentially (no `post_content` race). All outcomes log to `swps_generation_log`.

**Tech Stack:** PHP 8.0+, WordPress, Action Scheduler (optional), Google Gemini API. Quality gate: `composer check` (PHPCS/WPCS + PHPStan).

**Spec:** `docs/superpowers/specs/2026-05-29-async-image-generation-design.md`

---

## Testing reality (read first)

This repo has **no PHP unit-test harness** — only PHPCS + PHPStan via `composer check`. So:
- **Static gate** (`composer check`) runs after every code change and must stay green.
- **One pure-logic unit test** (Task 2) runs with plain `php` — no WordPress needed.
- **Integration verification** uses **WP-CLI** (Task 6), run in a **WordPress environment with the plugin active and a valid Google API key**. Prefer a staging site; if you must use production, create one throwaway post and delete it (commands provided). `wp` is not installed on the dev laptop — run WP-CLI on the server/staging via SSH.

## Out of scope

- "Images Per Post = 2" (user expected 3): parked — `content_images_count` is registered once (no duplicate-field bug); most likely an unsaved value. Confirm with the user before treating as a defect. Not addressed here.
- Auto model-discovery feature (text + image models) — separate spec after this ships.

## File structure

| File | Change | Responsibility |
|------|--------|----------------|
| `includes/class-generator.php` | modify | Add static `append_log()`; remove inline featured-image block |
| `includes/class-image-inserter.php` | modify | Add `target_section_index()` (pure), `eligible_section_count()`, `insert_single_image()`; refactor `insert_images()` to reuse them |
| `includes/class-background-processor.php` | modify | New image-job hooks + `schedule_*` / `run_*` handlers |
| `stratawp-seo.php` | modify | Replace synchronous `insert_content_images` handler with `schedule_image_jobs` (enqueues both jobs) |
| `admin/js/admin.js` | modify | Show the Google key row when image provider = Gemini |
| `tests/unit/test-section-index.php` | create | Pure unit test for the section-index math |
| `stratawp-seo.php`, `README.md`, `readme.txt` | modify | Version bump 4.5.1 → 4.6.0 + changelog |

---

## Task 1: Extract a reusable generation-log helper

**Files:**
- Modify: `includes/class-generator.php:720-735` (the `log()` method)

- [ ] **Step 1: Add `append_log()` static and delegate `log()` to it**

Replace the existing `log()` method:

```php
	/**
	 * Append a message to the generation log (shown in Recent Activity).
	 *
	 * Static so background image jobs can surface their outcomes here too.
	 */
	public static function append_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[StrataWP SEO] ' . $message );
		}

		$log   = get_option( 'swps_generation_log', array() );
		$log[] = array(
			'time'    => current_time( 'mysql' ),
			'message' => $message,
		);

		$log = array_slice( $log, -50 );
		update_option( 'swps_generation_log', $log );
	}

	/**
	 * Log a message.
	 */
	private function log( string $message ): void {
		self::append_log( $message );
	}
```

- [ ] **Step 2: Static gate**

Run: `composer check`
Expected: PHPCS + PHPStan pass (no new errors).

- [ ] **Step 3: Commit**

```bash
git add includes/class-generator.php
git commit -m "refactor: extract SWPS_Generator::append_log() for shared logging"
```

---

## Task 2: Pure section-index helper (TDD)

**Files:**
- Create: `tests/unit/test-section-index.php`
- Modify: `includes/class-image-inserter.php` (add a static method near `split_by_headings()`)

- [ ] **Step 1: Write the failing test**

Create `tests/unit/test-section-index.php`:

```php
<?php
/**
 * Pure-logic unit test for SWPS_Image_Inserter::target_section_index().
 * No WordPress required. Run: php tests/unit/test-section-index.php
 */
define( 'ABSPATH', __DIR__ ); // satisfy the guard at the top of the class file
require __DIR__ . '/../../includes/class-image-inserter.php';

// [ eligible_sections, position, target, expected_index ]
$cases = array(
	array( 4, 0, 2, 0 ),
	array( 4, 1, 2, 2 ),
	array( 5, 1, 2, 2 ),
	array( 3, 0, 3, 0 ),
	array( 3, 1, 3, 1 ),
	array( 3, 2, 3, 2 ),
	array( 1, 0, 2, 0 ), // interval floored to >= 1
);

$failed = 0;
foreach ( $cases as $i => $c ) {
	list( $eligible, $pos, $target, $expected ) = $c;
	$got = SWPS_Image_Inserter::target_section_index( $eligible, $pos, $target );
	if ( $got !== $expected ) {
		echo "FAIL case $i: target_section_index($eligible,$pos,$target) = $got, expected $expected\n";
		++$failed;
	}
}
echo 0 === $failed ? 'OK: all ' . count( $cases ) . " cases passed\n" : "$failed case(s) FAILED\n";
exit( 0 === $failed ? 0 : 1 );
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/unit/test-section-index.php`
Expected: PHP fatal — `Call to undefined method SWPS_Image_Inserter::target_section_index()`.

- [ ] **Step 3: Implement the method**

In `includes/class-image-inserter.php`, add immediately after `split_by_headings()`:

```php
	/**
	 * Even-spacing slot for the Nth in-content image, mirroring the original loop
	 * (interval = floor(eligible / target); index = position * interval).
	 *
	 * @param int $eligible Number of eligible (post-intro) H2 sections.
	 * @param int $position 0-based image position.
	 * @param int $target   Total images to place.
	 * @return int 0-based index into the eligible-sections list.
	 */
	public static function target_section_index( int $eligible, int $position, int $target ): int {
		$interval = max( 1, intdiv( $eligible, max( 1, $target ) ) );
		return $position * $interval;
	}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/unit/test-section-index.php`
Expected: `OK: all 7 cases passed`

- [ ] **Step 5: Static gate**

Run: `composer check`
Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add includes/class-image-inserter.php tests/unit/test-section-index.php
git commit -m "feat: add pure target_section_index() helper + unit test"
```

---

## Task 3: Inserter — `eligible_section_count()` + `insert_single_image()`

**Files:**
- Modify: `includes/class-image-inserter.php` (add two methods; refactor `insert_images()` to reuse them)

- [ ] **Step 1: Add `eligible_section_count()` and `insert_single_image()`**

Add these methods (e.g. after `target_section_index()`):

```php
	/**
	 * Number of eligible (post-intro) H2 sections available for in-content images.
	 */
	public function eligible_section_count( int $post_id ): int {
		$content  = get_post_field( 'post_content', $post_id );
		$sections = $this->split_by_headings( $content );
		return count( $sections ) >= 2 ? count( $sections ) - 1 : 0;
	}

	/**
	 * Generate and inject ONE in-content image at the given position.
	 *
	 * Re-reads the post content on each call, so sequential invocations
	 * (one per background job) stay race-free.
	 *
	 * @param int $post_id  Target post.
	 * @param int $position 0-based image position.
	 * @param int $target   Total images planned (for even spacing).
	 * @return true|WP_Error True on insert, WP_Error otherwise.
	 */
	public function insert_single_image( int $post_id, int $position, int $target ): bool|WP_Error {
		$content  = get_post_field( 'post_content', $post_id );
		$sections = $this->split_by_headings( $content );

		if ( count( $sections ) < 2 ) {
			return new WP_Error( 'swps_no_sections', __( 'Not enough sections for in-content images.', 'stratawp-seo' ) );
		}

		$eligible = array_slice( $sections, 1 ); // skip intro (section 0)
		$idx      = self::target_section_index( count( $eligible ), $position, $target );

		if ( ! isset( $eligible[ $idx ] ) ) {
			return new WP_Error( 'swps_no_section', __( 'Target section not found.', 'stratawp-seo' ) );
		}

		$section = $eligible[ $idx ];
		$query   = $this->extract_visual_concept( $section );
		$queries = apply_filters( 'swps_content_images_queries', array( $idx => $query ), $post_id );
		$query   = $queries[ $idx ] ?? $query;

		if ( empty( $query ) ) {
			return new WP_Error( 'swps_empty_query', __( 'Could not derive an image query.', 'stratawp-seo' ) );
		}

		$image_url = $this->search_image( $query );
		if ( empty( $image_url ) ) {
			return new WP_Error( 'swps_no_image', __( 'No image returned for the query.', 'stratawp-seo' ) );
		}

		$section_heading = $this->extract_heading( $section );
		$image_data      = apply_filters(
			'swps_image_selection',
			array(
				'url'     => $image_url,
				'query'   => $query,
				'alt'     => $section_heading ?: $query,
				'post_id' => $post_id,
			),
			$post_id,
			$section_heading
		);

		if ( empty( $image_data['url'] ) ) {
			return new WP_Error( 'swps_image_filtered_out', __( 'Image selection was filtered out.', 'stratawp-seo' ) );
		}

		$max_width     = (int) get_option( 'swps_image_max_width', 1200 );
		$attachment_id = $this->download_image( $image_data['url'], $post_id, $query, $max_width );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$focus_keyword = get_post_meta( $post_id, '_swps_focus_keyword', true );
		$alt_text      = sanitize_text_field( $image_data['alt'] ?? $query );
		if ( ! empty( $focus_keyword ) && false === mb_stripos( $alt_text, $focus_keyword ) ) {
			$alt_text .= ' - ' . $focus_keyword;
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		$img_src  = wp_get_attachment_url( $attachment_id );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$width    = $metadata['width'] ?? '';
		$height   = $metadata['height'] ?? '';

		$figure_html = sprintf(
			'<figure class="swps-content-image"><img src="%s" alt="%s" loading="lazy"%s%s /><figcaption>%s</figcaption></figure>',
			esc_url( $img_src ),
			esc_attr( $alt_text ),
			$width ? ' width="' . esc_attr( $width ) . '"' : '',
			$height ? ' height="' . esc_attr( $height ) . '"' : '',
			esc_html( $alt_text )
		);

		// Full-content section index is idx + 1 (section 0 is the intro).
		$updated = $this->inject_into_section( $content, $idx + 1, $figure_html );
		if ( $updated === $content ) {
			return new WP_Error( 'swps_inject_failed', __( 'Could not inject the image into the content.', 'stratawp-seo' ) );
		}

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $updated,
			)
		);
		do_action( 'swps_image_inserted', $attachment_id, $post_id, $alt_text, $idx + 1 );

		return true;
	}
```

- [ ] **Step 2: Refactor `insert_images()` to reuse the new units (DRY)**

Replace the body of `insert_images()` (currently `includes/class-image-inserter.php:29-153`) with:

```php
	public function insert_images( int $post_id, array $ai_result ): void {
		if ( ! get_option( 'swps_insert_content_images', 0 ) ) {
			return;
		}

		$eligible = $this->eligible_section_count( $post_id );
		$target   = min( (int) get_option( 'swps_content_images_count', 2 ), $eligible );

		for ( $i = 0; $i < $target; $i++ ) {
			$this->insert_single_image( $post_id, $i, $target );
		}
	}
```

(The previously-inline helpers `split_by_headings`, `extract_visual_concept`, `extract_heading`, `search_image`, `download_image`, `inject_into_section` are unchanged and now shared.)

- [ ] **Step 3: Static gate**

Run: `composer check`
Expected: pass. (If PHPStan flags an unused `$ai_result` param in `insert_images`, keep it — the method signature is fixed by the `swps_post_created` callers and the WP hook contract.)

- [ ] **Step 4: Re-run the pure unit test (still green)**

Run: `php tests/unit/test-section-index.php`
Expected: `OK: all 7 cases passed`

- [ ] **Step 5: Commit**

```bash
git add includes/class-image-inserter.php
git commit -m "feat: add eligible_section_count() + insert_single_image(); make insert_images() reuse them"
```

---

## Task 4: Background processor — image jobs

**Files:**
- Modify: `includes/class-background-processor.php`

- [ ] **Step 1: Add hook constants + register handlers**

After `private const HOOK = 'swps_process_topic';` add:

```php
	private const FEATURED_HOOK = 'swps_generate_featured_image';
	private const CONTENT_HOOK  = 'swps_generate_content_image';
```

In `__construct()`, after the existing `add_action( self::HOOK, ... )`:

```php
		add_action( self::FEATURED_HOOK, array( $this, 'run_featured_image' ), 10, 3 );
		add_action( self::CONTENT_HOOK, array( $this, 'run_content_image' ), 10, 2 );
```

- [ ] **Step 2: Add the schedule methods**

```php
	/**
	 * Queue the featured-image job for a post.
	 */
	public function schedule_featured_image( int $post_id, string $query, int $attempt = 0, int $delay = 0 ): void {
		$timestamp = time() + $delay;
		if ( $this->has_action_scheduler() ) {
			as_schedule_single_action( $timestamp, self::FEATURED_HOOK, array( $post_id, $query, $attempt ) );
		} else {
			wp_schedule_single_event( $timestamp, self::FEATURED_HOOK, array( $post_id, $query, $attempt ) );
		}
	}

	/**
	 * Queue the next in-content image job for a post.
	 */
	public function schedule_content_image( int $post_id, int $attempt = 0, int $delay = 0 ): void {
		$timestamp = time() + $delay;
		if ( $this->has_action_scheduler() ) {
			as_schedule_single_action( $timestamp, self::CONTENT_HOOK, array( $post_id, $attempt ) );
		} else {
			wp_schedule_single_event( $timestamp, self::CONTENT_HOOK, array( $post_id, $attempt ) );
		}
	}
```

- [ ] **Step 3: Add `run_featured_image()`**

```php
	/**
	 * Featured-image job: generate + attach one image. Idempotent; one retry.
	 */
	public function run_featured_image( int $post_id, string $query, int $attempt = 0 ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'trash' === $post->post_status ) {
			return;
		}
		if ( has_post_thumbnail( $post_id ) ) {
			return; // Already attached — safe re-run.
		}
		if ( '' === trim( $query ) ) {
			SWPS_Generator::append_log( sprintf( 'Featured image skipped for #%d: empty query.', $post_id ) );
			return;
		}

		$provider = SWPS_Provider_Factory::create_image_provider();
		$result   = $provider->set_featured_image( $post_id, $query );

		if ( is_wp_error( $result ) ) {
			if ( $attempt < 1 ) {
				$this->schedule_featured_image( $post_id, $query, $attempt + 1, 30 );
				return;
			}
			SWPS_Generator::append_log( sprintf( 'Featured image failed for #%d: %s', $post_id, $result->get_error_message() ) );
			return;
		}

		$attachment_id = $result;

		// SEO alt text including the focus keyword (moved from the generator).
		$focus_keyword = get_post_meta( $post_id, '_swps_focus_keyword', true );
		if ( ! empty( $focus_keyword ) ) {
			$existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( empty( $existing_alt ) || false === mb_stripos( $existing_alt, $focus_keyword ) ) {
				$seo_alt = ! empty( $existing_alt ) ? $existing_alt . ' - ' . $focus_keyword : ucfirst( $focus_keyword );
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $seo_alt ) );
			}
		}

		// OG image so the SEO-score social-image check passes once recomputed.
		$image_url = wp_get_attachment_url( $attachment_id );
		if ( $image_url ) {
			update_post_meta( $post_id, '_swps_social_image', esc_url_raw( $image_url ) );
		}

		SWPS_Generator::append_log( sprintf( 'Featured image attached to #%d.', $post_id ) );
	}
```

- [ ] **Step 4: Add `run_content_image()` (self-rescheduling, sequential)**

```php
	/**
	 * In-content image job: generate + inject ONE image, then reschedule the
	 * next until the configured count is met. Sequential, so no content race.
	 */
	public function run_content_image( int $post_id, int $attempt = 0 ): void {
		if ( ! get_option( 'swps_insert_content_images', 0 ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'trash' === $post->post_status ) {
			return;
		}

		$inserter = new SWPS_Image_Inserter( SWPS_Provider_Factory::create_image_provider() );
		$eligible = $inserter->eligible_section_count( $post_id );
		$target   = min( (int) get_option( 'swps_content_images_count', 2 ), $eligible );

		if ( $target < 1 ) {
			return; // Too few sections — nothing to do.
		}

		$done = (int) get_post_meta( $post_id, '_swps_content_images_inserted', true );
		if ( $done >= $target ) {
			return; // Finished.
		}

		$result = $inserter->insert_single_image( $post_id, $done, $target );

		if ( is_wp_error( $result ) ) {
			if ( $attempt < 1 ) {
				$this->schedule_content_image( $post_id, $attempt + 1, 30 );
				return;
			}
			SWPS_Generator::append_log(
				sprintf( 'In-content image %d/%d failed for #%d: %s', $done + 1, $target, $post_id, $result->get_error_message() )
			);
			return; // Stop the chain on permanent failure (visible in the log).
		}

		++$done;
		update_post_meta( $post_id, '_swps_content_images_inserted', $done );

		if ( $done < $target ) {
			$this->schedule_content_image( $post_id, 0, 5 ); // Next image, fresh attempt count.
		} else {
			SWPS_Generator::append_log( sprintf( '%d in-content image(s) added to #%d.', $done, $post_id ) );
		}
	}
```

- [ ] **Step 5: Static gate**

Run: `composer check`
Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add includes/class-background-processor.php
git commit -m "feat: background jobs for featured + sequential in-content images"
```

---

## Task 5: Generator — stop generating the featured image inline

**Files:**
- Modify: `includes/class-generator.php` (remove the `// Set featured image.` block, currently lines 485-513)

- [ ] **Step 1: Delete the inline featured-image block**

Remove this entire block (between the cost-tracking section and `// Fire post_created action.`):

```php
		// Set featured image.
		if ( get_option( 'swps_featured_images', 1 ) ) {
			$image_query = $focus_keyword ?: $ai_result['title'];
			$image_query = SWPS_Hooks::filter_image_query( $image_query, $post_id );

			$image_result = $this->images->set_featured_image( $post_id, $image_query );

			if ( is_wp_error( $image_result ) ) {
				$this->log( 'Featured image failed: ' . $image_result->get_error_message() );
			} else {
				$attachment_id = $image_result;

				// Update alt text to include the focus keyword for SEO.
				if ( ! empty( $focus_keyword ) ) {
					$existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
					if ( empty( $existing_alt ) || false === mb_stripos( $existing_alt, $focus_keyword ) ) {
						$seo_alt = ! empty( $existing_alt )
							? $existing_alt . ' - ' . $focus_keyword
							: ucfirst( $focus_keyword );
						update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $seo_alt ) );
					}
				}

				// Set OG image from featured image so the social_image check passes.
				$image_url = wp_get_attachment_url( $attachment_id );
				if ( $image_url ) {
					update_post_meta( $post_id, '_swps_social_image', esc_url_raw( $image_url ) );
				}
			}
		}

```

The code should now flow directly from the cost-tracking block to:

```php
		// Fire post_created action.
		SWPS_Hooks::do_post_created( $post_id, $ai_result, $post_data );
```

(The featured-image work now lives in `SWPS_Background_Processor::run_featured_image()` from Task 4; the enqueue happens in Task 6. `$focus_keyword` is still used earlier for `rank_math_focus_keyword`/meta, so it does not become unused.)

- [ ] **Step 2: Static gate**

Run: `composer check`
Expected: pass. Confirm PHPStan reports no "undefined/unused variable" for `$image_query`, `$image_result`, `$attachment_id` (all were local to the removed block).

- [ ] **Step 3: Commit**

```bash
git add includes/class-generator.php
git commit -m "refactor: move featured-image generation out of generate_post (now async)"
```

---

## Task 6: Main plugin — enqueue image jobs from `swps_post_created`

**Files:**
- Modify: `stratawp-seo.php` — the `swps_post_created` → `insert_content_images` registration (≈ line 372 on `main`) and the `insert_content_images()` handler (≈ line 855 on `main`). Use the exact-string anchors below; line numbers are hints only.

- [ ] **Step 1: Point the hook at a new handler**

Change the registration line from:

```php
		add_action( 'swps_post_created', array( $this, 'insert_content_images' ), 20, 3 );
```

to:

```php
		add_action( 'swps_post_created', array( $this, 'schedule_image_jobs' ), 20, 3 );
```

- [ ] **Step 2: Replace the handler method**

Replace the `insert_content_images()` method (≈ lines 855-857 on `main`) with:

```php
	/**
	 * Enqueue background image-generation jobs for a freshly generated post.
	 *
	 * Featured + in-content images run as separate short requests so a slow
	 * provider (Gemini) can't blow the cron request's execution limit and
	 * leave the post imageless.
	 *
	 * @param int   $post_id   The new post ID.
	 * @param array $ai_result The AI response data.
	 * @param array $post_data The WordPress post data.
	 */
	public function schedule_image_jobs( int $post_id, array $ai_result, array $post_data ): void {
		if ( get_option( 'swps_featured_images', 1 ) ) {
			$focus = (string) get_post_meta( $post_id, '_swps_focus_keyword', true );
			$query = '' !== $focus ? $focus : (string) ( $ai_result['title'] ?? '' );
			$query = SWPS_Hooks::filter_image_query( $query, $post_id );
			$this->background_processor->schedule_featured_image( $post_id, $query );
		}

		if ( get_option( 'swps_insert_content_images', 0 ) ) {
			$this->background_processor->schedule_content_image( $post_id );
		}
	}
```

- [ ] **Step 3: Static gate**

Run: `composer check`
Expected: pass.

- [ ] **Step 4: End-to-end integration verification (WP-CLI, staging preferred)**

Run on a WordPress environment with the plugin active, image provider = Gemini, and a valid Google API key. `wp` is on the server, not the dev laptop.

```bash
# 1. Generate a post (synchronous text; images get queued).
wp eval '$r = stratawp_seo()->generator->generate_post("WordPress cron image test", "auto"); echo is_wp_error($r) ? "ERR: ".$r->get_error_message() : "POST=".$r["post_id"];'
# Note the POST id printed (call it PID).

# 2. Featured image should NOT be attached yet (it's queued, not inline):
wp post meta get PID _thumbnail_id        # expect: empty

# 3. Jobs should be scheduled:
wp cron event list | grep swps_generate   # wp-cron fallback
# or, if Action Scheduler is active:
wp action-scheduler list --hook=swps_generate_featured_image --status=pending

# 4. Run the queued jobs (run content twice — it self-reschedules per image):
wp cron event run swps_generate_featured_image
wp cron event run swps_generate_content_image
wp cron event run swps_generate_content_image
# (Action Scheduler equivalent: wp action-scheduler run --hook=swps_generate_featured_image ; ... )

# 5. Verify results:
wp post meta get PID _thumbnail_id                       # expect: an attachment ID
wp post meta get PID _swps_content_images_inserted       # expect: up to content_images_count
wp post get PID --field=post_content | grep -c 'swps-content-image'   # expect: >= 1
wp option get swps_generation_log --format=json | tail   # expect: "Featured image attached to #PID." etc.

# 6. Clean up the throwaway post + its attachments:
wp post delete PID --force
```

Expected: featured thumbnail set, at least one `swps-content-image` figure in the content, and success lines in the generation log.

- [ ] **Step 5: Commit**

```bash
git add stratawp-seo.php
git commit -m "feat: enqueue async featured + in-content image jobs on post creation"
```

---

## Task 7: Make the Gemini/Google key editable regardless of text provider (§6)

**Files:**
- Modify: `admin/js/admin.js:54-62` and `:114-127`

- [ ] **Step 1: Show the Google key row when image provider = Gemini**

Replace `updateAIKeyVisibility()`:

```js
    function updateAIKeyVisibility() {
        if (!$aiProvider.length) return;

        const slug = $aiProvider.val();

        // Hide all AI key rows, then show the active text provider's row.
        $('.swps-ai-key-row').closest('tr').hide();
        $('.swps-provider-' + slug).closest('tr').show();

        // Gemini image generation reuses the Google key — keep it visible even
        // when the text provider isn't Google, so the image key stays editable.
        if ($imageProvider.length && $imageProvider.val() === 'gemini') {
            $('.swps-provider-google').closest('tr').show();
        }
    }
```

- [ ] **Step 2: Re-evaluate the Google row when the image provider changes**

Replace `updateImageKeyVisibility()`:

```js
    function updateImageKeyVisibility() {
        if (!$imageProvider.length) return;

        var slug = $imageProvider.val();
        var imagesEnabled = $featuredImages.is(':checked');

        // Hide all image key rows.
        $('.swps-image-key-row').closest('tr').hide();

        // Show the active provider's key row only if featured images are enabled.
        if (imagesEnabled) {
            $('.swps-image-provider-' + slug).closest('tr').show();
        }

        // The Google key row lives in the AI section; Gemini images depend on it.
        updateAIKeyVisibility();
    }
```

(No new input is added — there remains exactly one `name="swps_google_api_key"` field — so the v4.4.1 #24 duplicate-name bug cannot recur. The on-load init already calls both functions, so initial state is correct.)

- [ ] **Step 3: Static gate**

Run: `composer check`
Expected: pass (PHPCS/PHPStan don't lint JS; this confirms nothing else broke).

- [ ] **Step 4: Manual browser verification**

(After the version bump in Task 8 busts the `admin.js` cache, or hard-refresh.)
- Settings → text provider **Anthropic**, image provider **Gemini** → the **Google API Key** row is visible and editable.
- Save settings → the key value is preserved (not blanked).
- Switch image provider to **Unsplash** (text still Anthropic) → the Google key row hides; the Unsplash key row shows.

- [ ] **Step 5: Commit**

```bash
git add admin/js/admin.js
git commit -m "fix: keep the Google/Gemini key editable when image provider is Gemini"
```

---

## Task 8: Version bump, changelog, docs, zip

**Files:**
- Modify: `stratawp-seo.php` (header `Version:` + `SWPS_VERSION`), `README.md`, `readme.txt`

- [ ] **Step 1: Bump the version**

> **Base is `main` = 4.6.5** (this branch was 4.5.1; we branch off main). Bump 4.6.5 → **4.7.0**.

In `stratawp-seo.php` header: `* Version: 4.6.5` → `* Version: 4.7.0`
And: `define( 'SWPS_VERSION', '4.6.5' );` → `define( 'SWPS_VERSION', '4.7.0' );`

In `readme.txt`: `Stable tag: 4.6.5` → `Stable tag: 4.7.0`

In `README.md`: bump the version badge `version-4.6.5-blue` → `version-4.7.0-blue`.

- [ ] **Step 2: Add changelog entries**

`readme.txt` — add **above** the `= 4.6.5 — 2026-05-24 =` entry, matching the dated heading format:

```
= 4.7.0 — 2026-05-29 =
* Fix: Scheduled (WP-Cron) posts now reliably get their featured and in-content images. Image generation was running synchronously inside the generation request — the AI text call plus multiple Gemini image calls stacked past the host's request timeout, killing the worker mid-download (and PHP dying inside the HTTP call meant nothing was logged). Each image now runs as its own short background job (Action Scheduler, falling back to WP-Cron), and image failures are surfaced in the Recent Activity log.
* Improvement: The Google API key is now editable from the Featured Images flow whenever the Gemini image provider is selected, even if your text provider isn't Google.
```

`README.md` — add at the top of the `## Changelog` section:

```
### 4.7.0
- **Fix — Scheduled posts now get their images reliably:** image generation moved off the synchronous generation request into per-image background jobs (Action Scheduler → WP-Cron fallback), so a slow Gemini pipeline can no longer be killed by the host's request timeout. Image-job outcomes (success and failure) now appear in the Recent Activity log instead of vanishing.
- **Improvement — Editable Gemini key:** the Google API key is now visible/editable when the image provider is Gemini, regardless of the selected text provider.
```

- [ ] **Step 3: Static gate**

Run: `composer check`
Expected: pass.

- [ ] **Step 4: Build the deployment zip**

Use the project's usual packaging process (prior zips exclude `vendor/`, `node_modules/`, `.git/`, dev tooling, and `docs/`). The plugin has no runtime Composer dependencies. If there's no build script, from the parent of the plugin dir:

```bash
cd /Users/jon.imms/StrataWP-projects
zip -r stratawp-seo/stratawp-seo-4.7.0.zip stratawp-seo \
  -x 'stratawp-seo/.git/*' 'stratawp-seo/.github/*' 'stratawp-seo/node_modules/*' \
     'stratawp-seo/vendor/*' 'stratawp-seo/.idea/*' 'stratawp-seo/.claude/*' \
     'stratawp-seo/.phpstan/*' 'stratawp-seo/docs/*' 'stratawp-seo/tests/*' \
     'stratawp-seo/*.zip' 'stratawp-seo/.DS_Store'
# Verify the archive has no dev/vendor cruft:
unzip -l stratawp-seo/stratawp-seo-4.7.0.zip | grep -E 'vendor/|\.git/|node_modules/' && echo "CLEANUP NEEDED" || echo "clean"
```

Confirm the resulting file list matches the structure of the prior release zip.

- [ ] **Step 5: Commit**

```bash
git add stratawp-seo.php README.md readme.txt
git commit -m "chore: release v4.7.0 — async image generation + editable Gemini key"
```

(The `.zip` is gitignored — do not commit it; ship it via the normal deploy.)

---

## Self-review (completed)

- **Spec coverage:** §1 generator→Task 5; §2 handler→Task 6; §3 background jobs→Task 4; §4 inserter units→Tasks 2-3; §5 shared logging→Task 1 (used in Task 4); §6 key editability→Task 7; rollout→Task 8. Secondary "Images Per Post = 2" intentionally deferred (out-of-scope note above).
- **Method-name consistency:** `append_log`, `target_section_index`, `eligible_section_count`, `insert_single_image`, `schedule_featured_image`, `schedule_content_image`, `run_featured_image`, `run_content_image`, `schedule_image_jobs` — used identically across tasks.
- **No-regression check:** the gated content score (`SWPS_Content_Scorer::score()`) does not read the featured image, so async timing doesn't affect the draft gate. The displayed SEO score (`score_post()`) reads `_swps_social_image`, which the featured job sets and which self-heals on the next recompute.
