<?php
/**
 * Transient-based caching for site analysis.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Cache_Manager {

    private const CACHE_PREFIX = 'swps_cache_';
    private const DEFAULT_TTL  = HOUR_IN_SECONDS;

    public function __construct() {
        add_action( 'save_post', [ $this, 'invalidate_on_post_save' ], 10, 2 );
        add_action( 'delete_post', [ $this, 'invalidate_all' ] );
    }

    /**
     * Get a cached value.
     *
     * @param string $key Cache key.
     * @return mixed|false Cached value or false if not found.
     */
    public function get( string $key ): mixed {
        return get_transient( self::CACHE_PREFIX . $key );
    }

    /**
     * Set a cached value.
     *
     * @param string $key   Cache key.
     * @param mixed  $value Value to cache.
     * @param int    $ttl   Time to live in seconds.
     */
    public function set( string $key, mixed $value, int $ttl = self::DEFAULT_TTL ): void {
        set_transient( self::CACHE_PREFIX . $key, $value, $ttl );
    }

    /**
     * Delete a cached value.
     *
     * @param string $key Cache key.
     */
    public function delete( string $key ): void {
        delete_transient( self::CACHE_PREFIX . $key );
    }

    /**
     * Clear all plugin caches.
     */
    public function invalidate_all(): void {
        global $wpdb;

        // Delete all transients with our prefix.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . '%',
                '_transient_timeout_' . self::CACHE_PREFIX . '%'
            )
        );
    }

    /**
     * Invalidate caches when a post is saved (only for published posts).
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function invalidate_on_post_save( int $post_id, WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
            $this->invalidate_all();
        }
    }
}
