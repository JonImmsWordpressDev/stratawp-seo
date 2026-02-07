<?php
/**
 * Uninstall StrataWP SEO.
 *
 * Removes all plugin data: options, post meta, CPT posts, cron events, transients.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 1. Remove all swps_* options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'swps\_%'" );

// 2. Remove all _swps_* post meta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_swps\_%'" );

// 3. Delete all swps_topic CPT posts.
$topics = get_posts( [
    'post_type'      => 'swps_topic',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
] );

foreach ( $topics as $topic_id ) {
    wp_delete_post( $topic_id, true );
}

// 4. Remove cron events.
wp_unschedule_hook( 'swps_generate_scheduled_post' );
wp_unschedule_hook( 'swps_process_topic' );

// 5. Remove transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_swps\_%' OR option_name LIKE '%\_transient\_timeout\_swps\_%'"
);

// 6. Clear any Action Scheduler entries if available.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'swps_process_topic' );
    as_unschedule_all_actions( 'swps_generate_scheduled_post' );
}
