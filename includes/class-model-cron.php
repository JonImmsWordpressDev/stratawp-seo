<?php
/**
 * Daily WP-Cron job that refreshes discovered models.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and runs the daily swps_refresh_models cron event.
 */
class SWPS_Model_Cron {

	/**
	 * The WP-Cron hook name.
	 */
	private const HOOK = 'swps_refresh_models';

	/**
	 * The model discovery service instance.
	 *
	 * @var SWPS_Model_Discovery
	 */
	private SWPS_Model_Discovery $discovery;

	/**
	 * Constructor — registers cron callback and schedule-ensure hook.
	 *
	 * @param SWPS_Model_Discovery $discovery Model discovery service.
	 */
	public function __construct( SWPS_Model_Discovery $discovery ) {
		$this->discovery = $discovery;
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Schedule the daily event if it is not already queued.
	 *
	 * Runs on the `init` hook so WP functions are available. Delays the first
	 * run by one hour to avoid slowing page loads on first activation.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Cron callback — delegates to the discovery service refresh.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->discovery->refresh();
	}

	/**
	 * Unschedule all pending instances of the cron event.
	 *
	 * Called on plugin deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
		wp_unschedule_hook( self::HOOK );
	}
}
