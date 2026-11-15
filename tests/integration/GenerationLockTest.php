<?php
/**
 * Generation lock + cooldown, against a real database.
 *
 * Regression cover for the bug where every post after the first in a bulk
 * or cron batch failed with "Rate limited": the 60s cooldown was set after
 * post one and the batch only waited 5s.
 *
 * @package StrataWP_SEO
 */

class GenerationLockTest extends WP_UnitTestCase {

	private SWPS_Rate_Limiter $limiter;
	private SWPS_Stub_AI_Provider $ai;
	private SWPS_Generator $generator;

	public function set_up(): void {
		parent::set_up();
		update_option( 'swps_rate_limit', 60 );
		update_option( 'swps_duplicate_check', 0 );
		update_option( 'swps_monthly_budget', 0 );
		update_option( 'swps_post_author', self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->limiter = new SWPS_Rate_Limiter();
		$this->limiter->clear();
		$this->ai        = new SWPS_Stub_AI_Provider();
		$plugin          = stratawp_seo();
		$this->generator = new SWPS_Generator( $this->ai, $plugin->analyzer, $plugin->duplicate_checker, $this->limiter, $plugin->cost_tracker );

		// No network in tests: image search, IndexNow, pings all short-circuit.
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'swps_tests_offline', 'Network disabled in tests' );
			}
		);
	}

	public function tear_down(): void {
		$this->limiter->clear();
		parent::tear_down();
	}

	public function test_acquire_is_exclusive_until_released(): void {
		$this->assertTrue( $this->limiter->acquire() );
		$this->assertFalse( $this->limiter->acquire(), 'second acquire must fail while running' );
		$this->assertFalse( $this->limiter->acquire( true ), 'a batch must not skip a running generation' );
		$this->assertTrue( $this->limiter->is_running() );

		$this->limiter->release( false );
		$this->assertTrue( $this->limiter->acquire(), 'a failed generation frees the lock at once' );
	}

	public function test_cooldown_blocks_single_calls_but_not_batches(): void {
		$this->assertTrue( $this->limiter->acquire() );
		$this->limiter->release( true );

		$this->assertFalse( $this->limiter->is_running() );
		$this->assertFalse( $this->limiter->can_generate() );
		$this->assertGreaterThan( 50, $this->limiter->get_remaining_seconds() );
		$this->assertFalse( $this->limiter->acquire(), 'double-click inside the cooldown is refused' );
		$this->assertTrue( $this->limiter->acquire( true ), 'the next post of a batch skips the cooldown' );
	}

	public function test_zero_cooldown_disables_cooldown_only(): void {
		update_option( 'swps_rate_limit', 0 );
		$this->assertTrue( $this->limiter->acquire() );
		$this->assertFalse( $this->limiter->acquire(), 'concurrency is still guarded with cooldown off' );
		$this->limiter->release( true );
		$this->assertTrue( $this->limiter->acquire() );
	}

	public function test_stale_running_lock_is_taken_over(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", SWPS_Rate_Limiter::LOCK_OPTION, ( time() - 1 ) . '|running' ) );
		$this->assertTrue( $this->limiter->acquire(), 'a lock left by a dead request expires' );
	}

	public function test_single_generations_respect_the_cooldown(): void {
		$first = $this->generator->generate_post( 'lighthouses' );
		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );

		$second = $this->generator->generate_post( 'lighthouses again' );
		$this->assertWPError( $second );
		$this->assertSame( 'swps_rate_limited', $second->get_error_code() );
		$this->assertSame( 1, $this->ai->calls, 'a refused generation must not call (and pay) the AI' );
	}

	public function test_batch_generations_are_not_rate_limited(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$result = $this->generator->generate_post( '', 'auto', array(), array( 'continue_batch' => $i > 0 ) );
			$this->assertIsArray( $result, "post {$i}: " . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		}
		$this->assertSame( 3, $this->ai->calls );
	}

	public function test_failure_and_exception_both_release_the_lock(): void {
		$this->ai->next = array( 'error', 'throw' );

		$this->assertWPError( $this->generator->generate_post( 'one' ) );
		$this->assertFalse( $this->limiter->is_running() );
		$this->assertTrue( $this->limiter->can_generate(), 'a failed generation leaves no cooldown' );

		try {
			$this->generator->generate_post( 'two' );
			$this->fail( 'expected the stub exception to propagate' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Stub provider exception', $e->getMessage() );
		}
		$this->assertFalse( $this->limiter->is_running(), 'an exception must not strand the running lock' );

		$this->assertIsArray( $this->generator->generate_post( 'three' ) );
	}

	public function test_cron_batch_publishes_every_queued_topic(): void {
		update_option( 'swps_cron_enabled', 1 );
		update_option( 'swps_cron_posts_per_run', 2 );
		$queue = new SWPS_Topic_Queue();
		$ids   = array(
			$queue->create_topic( 'Fresnel lenses', gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			$queue->create_topic( 'Fog signals', gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
		);

		( new SWPS_Cron( $this->generator, $queue ) )->run_scheduled_generation();

		foreach ( $ids as $id ) {
			$this->assertSame( 'published', get_post_status( $id ), 'every topic in a multi-post run must publish' );
			$this->assertEmpty( get_post_meta( $id, '_swps_attempt_count', true ), 'no topic may be charged a failed attempt' );
		}
	}

	public function test_cron_requeues_untouched_when_someone_else_holds_the_lock(): void {
		update_option( 'swps_cron_enabled', 1 );
		update_option( 'swps_cron_posts_per_run', 1 );
		$queue = new SWPS_Topic_Queue();
		$id    = $queue->create_topic( 'Keepers logbooks', gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		$this->assertTrue( $this->limiter->acquire() ); // A manual generation is mid-flight.
		( new SWPS_Cron( $this->generator, $queue ) )->run_scheduled_generation();

		$this->assertSame( 'queued', get_post_status( $id ) );
		$this->assertEmpty( get_post_meta( $id, '_swps_attempt_count', true ) );
		$this->assertSame( 0, $this->ai->calls );
	}
}
