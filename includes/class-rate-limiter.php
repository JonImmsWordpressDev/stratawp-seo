<?php
/**
 * Transient-based rate limiter for AI generation.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Rate_Limiter {

    private const TRANSIENT_KEY = 'swps_rate_limit_lock';

    /**
     * Check if generation is currently allowed.
     *
     * @return bool True if generation can proceed.
     */
    public function can_generate(): bool {
        return false === get_transient( self::TRANSIENT_KEY );
    }

    /**
     * Lock generation for the configured cooldown period.
     */
    public function lock(): void {
        $cooldown = (int) get_option( 'swps_rate_limit', 60 );
        set_transient( self::TRANSIENT_KEY, time(), $cooldown );
    }

    /**
     * Get remaining seconds until generation is allowed.
     *
     * @return int Seconds remaining, 0 if ready.
     */
    public function get_remaining_seconds(): int {
        $lock_time = get_transient( self::TRANSIENT_KEY );

        if ( false === $lock_time ) {
            return 0;
        }

        $cooldown  = (int) get_option( 'swps_rate_limit', 60 );
        $elapsed   = time() - (int) $lock_time;
        $remaining = $cooldown - $elapsed;

        return max( 0, $remaining );
    }

    /**
     * Clear the rate limit lock.
     */
    public function clear(): void {
        delete_transient( self::TRANSIENT_KEY );
    }
}
