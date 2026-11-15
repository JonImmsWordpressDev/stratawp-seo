<?php
/**
 * Generation lock and cooldown for AI generation.
 *
 * Two jobs, one database row:
 *
 * - While a generation runs, nobody else may start one. Generations take
 *   minutes (images especially) and cost money, so a second click, a cron
 *   tick and a REST call must not overlap.
 * - After a successful generation, a short cooldown (the "Rate Limit
 *   Cooldown" setting) stops accidental double-clicks from producing two
 *   posts. Batch callers (bulk generate, the autopilot cron, the background
 *   queue) are one deliberate request for N posts, so they skip the
 *   cooldown — but never an in-progress generation.
 *
 * A failed generation releases the lock straight away: nothing was created,
 * so there is nothing to protect against a retry.
 *
 * The row is written with raw SQL rather than options/transients because
 * acquiring must be atomic: INSERT IGNORE on the options table's unique
 * option_name either inserts (we hold the lock) or affects zero rows
 * (someone else does). add_option() and set_transient() check-then-write,
 * so two requests can both "win".
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Rate_Limiter {

	/** Option row holding "<expiry unix time>|<phase>". */
	public const LOCK_OPTION = 'swps_generation_lock';

	/** The pre-4.33 transient; cleared on release so old locks don't linger. */
	private const LEGACY_TRANSIENT = 'swps_rate_limit_lock';

	public const PHASE_RUNNING  = 'running';
	public const PHASE_COOLDOWN = 'cooldown';

	/**
	 * Upper bound on a "running" lock. If a request dies mid-generation
	 * (fatal error, killed worker) the lock frees itself after this long.
	 */
	public const RUNNING_TTL = 900;

	/**
	 * Pure: does an existing lock value block a new generation?
	 *
	 * @param string|null $raw             Stored value, or null when no lock row exists.
	 * @param int         $now             Current unix time.
	 * @param bool        $ignore_cooldown True for batch callers.
	 */
	public static function blocks( ?string $raw, int $now, bool $ignore_cooldown ): bool {
		$lock = self::parse( $raw );
		if ( null === $lock || $lock['expiry'] <= $now ) {
			return false;
		}
		if ( self::PHASE_COOLDOWN === $lock['phase'] && $ignore_cooldown ) {
			return false;
		}
		return true;
	}

	/**
	 * Pure: parse a stored value. Malformed values count as no lock.
	 *
	 * @param string|null $raw Stored value.
	 * @return array{expiry:int, phase:string}|null
	 */
	public static function parse( ?string $raw ): ?array {
		if ( null === $raw || ! preg_match( '/^(\d+)\|(running|cooldown)$/', $raw, $m ) ) {
			return null;
		}
		return array(
			'expiry' => (int) $m[1],
			'phase'  => $m[2],
		);
	}

	/**
	 * Configured cooldown in seconds; 0 disables the cooldown.
	 */
	public function cooldown(): int {
		return max( 0, (int) get_option( 'swps_rate_limit', 60 ) );
	}

	/**
	 * Try to take the generation lock. Atomic.
	 *
	 * @param bool $ignore_cooldown Batch callers skip a cooldown, never a running generation.
	 * @return bool True when the caller now holds the lock.
	 */
	public function acquire( bool $ignore_cooldown = false ): bool {
		global $wpdb;

		$now = time();
		$raw = $this->read_raw();

		if ( null !== $raw ) {
			if ( self::blocks( $raw, $now, $ignore_cooldown ) ) {
				return false;
			}
			// Expired, malformed, or a cooldown a batch may skip. Delete only
			// the exact value we read: if another request replaced it in the
			// meantime, this deletes nothing and the INSERT below loses.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$value    = ( $now + self::RUNNING_TTL ) . '|' . self::PHASE_RUNNING;
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->flush_cache();

		return 1 === (int) $inserted;
	}

	/**
	 * Finish a generation the caller holds the lock for.
	 *
	 * @param bool $success Success starts the cooldown; failure frees the lock.
	 */
	public function release( bool $success ): void {
		global $wpdb;

		$cooldown = $this->cooldown();
		if ( $success && $cooldown > 0 ) {
			$value = ( time() + $cooldown ) . '|' . self::PHASE_COOLDOWN;
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $value, self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_transient( self::LEGACY_TRANSIENT );
		$this->flush_cache();
	}

	/**
	 * True while a generation is running (not merely cooling down).
	 */
	public function is_running(): bool {
		$lock = self::parse( $this->read_raw() );
		return null !== $lock && self::PHASE_RUNNING === $lock['phase'] && $lock['expiry'] > time();
	}

	/**
	 * Check if a single (non-batch) generation is currently allowed.
	 */
	public function can_generate(): bool {
		return ! self::blocks( $this->read_raw(), time(), false );
	}

	/**
	 * Seconds until a single generation may start; 0 when it may start now.
	 */
	public function get_remaining_seconds(): int {
		$lock = self::parse( $this->read_raw() );
		return null === $lock ? 0 : max( 0, $lock['expiry'] - time() );
	}

	/**
	 * Start a cooldown without having acquired (kept for back-compat).
	 */
	public function lock(): void {
		if ( $this->acquire( true ) ) {
			$this->release( true );
		}
	}

	/**
	 * Drop any lock or cooldown.
	 */
	public function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_transient( self::LEGACY_TRANSIENT );
		$this->flush_cache();
	}

	/**
	 * Read the lock row straight from the database, bypassing the options cache.
	 */
	private function read_raw(): ?string {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $raw ? null : (string) $raw;
	}

	/**
	 * Keep WordPress's option caches from serving a stale copy of the row.
	 */
	private function flush_cache(): void {
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
