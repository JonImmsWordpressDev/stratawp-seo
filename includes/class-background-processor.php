<?php
/**
 * Background processing for content generation.
 *
 * Uses Action Scheduler if available, falls back to wp_schedule_single_event.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Background_Processor {

	private const HOOK          = 'swps_process_topic';
	private const FEATURED_HOOK = 'swps_generate_featured_image';
	private const CONTENT_HOOK  = 'swps_generate_content_image';

	public function __construct() {
		add_action( self::HOOK, array( $this, 'process_topic' ) );
		add_action( self::FEATURED_HOOK, array( $this, 'run_featured_image' ), 10, 3 );
		add_action( self::CONTENT_HOOK, array( $this, 'run_content_image' ), 10, 2 );
	}

	/**
	 * Schedule a topic for background generation.
	 *
	 * @param int $topic_id Topic post ID.
	 * @param int $delay    Delay in seconds from now.
	 */
	public function schedule_generation( int $topic_id, int $delay = 0 ): void {
		$timestamp = time() + $delay;

		if ( $this->has_action_scheduler() ) {
			as_schedule_single_action( $timestamp, self::HOOK, array( 'topic_id' => $topic_id ) );
		} else {
			wp_schedule_single_event( $timestamp, self::HOOK, array( $topic_id ) );
		}
	}

	/**
	 * Queue the featured-image job for a post.
	 *
	 * @param int    $post_id Post to attach the featured image to.
	 * @param string $query   Image search/generation query.
	 * @param int    $attempt Retry attempt counter.
	 * @param int    $delay   Delay in seconds before the job runs.
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
	 *
	 * @param int $post_id Post to insert the image into.
	 * @param int $attempt Retry attempt counter.
	 * @param int $delay   Delay in seconds before the job runs.
	 */
	public function schedule_content_image( int $post_id, int $attempt = 0, int $delay = 0 ): void {
		$timestamp = time() + $delay;

		if ( $this->has_action_scheduler() ) {
			as_schedule_single_action( $timestamp, self::CONTENT_HOOK, array( $post_id, $attempt ) );
		} else {
			wp_schedule_single_event( $timestamp, self::CONTENT_HOOK, array( $post_id, $attempt ) );
		}
	}

	/**
	 * Featured-image job: generate + attach one image. Idempotent; retries once.
	 *
	 * @param int    $post_id Post to attach the featured image to.
	 * @param string $query   Image search/generation query.
	 * @param int    $attempt Retry attempt counter.
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

	/**
	 * In-content image job: generate + inject ONE image, then reschedule the next
	 * until the configured count is met. Sequential, so no content race.
	 *
	 * @param int $post_id Post to insert images into.
	 * @param int $attempt Retry attempt counter for the current position.
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

		// Single-chain assumption: swps_post_created fires exactly once per
		// freshly-created post, so only one chain advances this position. A
		// future "regenerate images" path would need a dedup guard here.
		$position = (int) get_post_meta( $post_id, '_swps_content_images_position', true );
		if ( $position >= $target ) {
			return; // All positions processed.
		}

		$result = $inserter->insert_single_image( $post_id, $position, $target );

		// Retry a transient failure once before giving up on this position.
		if ( is_wp_error( $result ) && $attempt < 1 ) {
			$this->schedule_content_image( $post_id, $attempt + 1, 30 );
			return;
		}

		if ( is_wp_error( $result ) ) {
			SWPS_Generator::append_log(
				sprintf( 'In-content image %d/%d skipped for #%d: %s', $position + 1, $target, $post_id, $result->get_error_message() )
			);
		}

		// Advance whether this position succeeded or was skipped, so one weak
		// section can't block the remaining images.
		++$position;
		update_post_meta( $post_id, '_swps_content_images_position', $position );

		if ( $position < $target ) {
			$this->schedule_content_image( $post_id, 0, 5 ); // Next position, fresh attempt count.
		} else {
			SWPS_Generator::append_log( sprintf( 'In-content images complete for #%d.', $post_id ) );
		}
	}

	/**
	 * Process a single topic (callback for scheduled action).
	 *
	 * @param int $topic_id Topic post ID.
	 */
	public function process_topic( int $topic_id ): void {
		$topic = get_post( $topic_id );

		if ( ! $topic || $topic->post_type !== SWPS_Topic_Queue::POST_TYPE ) {
			return;
		}

		$queue = new SWPS_Topic_Queue();
		$queue->update_status( $topic_id, 'generating' );

		// Get the plugin instance and generator.
		$plugin   = stratawp_seo();
		$template = get_post_meta( $topic_id, '_swps_template', true ) ?: 'auto';

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
	}

	/**
	 * Schedule multiple topics with staggered delays.
	 *
	 * @param array $topic_ids Array of topic post IDs.
	 * @param int   $interval  Seconds between each generation.
	 */
	public function schedule_batch( array $topic_ids, int $interval = 5 ): void {
		foreach ( $topic_ids as $index => $topic_id ) {
			$this->schedule_generation( $topic_id, $index * $interval );
		}
	}

	/**
	 * Check if any topics are currently being processed.
	 *
	 * @return bool True if processing.
	 */
	public function is_processing(): bool {
		$generating = get_posts(
			array(
				'post_type'      => SWPS_Topic_Queue::POST_TYPE,
				'post_status'    => 'generating',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return ! empty( $generating );
	}

	/**
	 * Check if Action Scheduler is available.
	 *
	 * @return bool
	 */
	private function has_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' );
	}
}
