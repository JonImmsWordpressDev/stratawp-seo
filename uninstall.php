<?php
/**
 * Uninstall StrataWP SEO.
 *
 * Always cleans up scheduled tasks and transient caches. Full data removal
 * (options, post meta, CPT posts, custom tables, term meta, user meta) only
 * runs when "Remove Data on Uninstall" is enabled in Settings → Advanced,
 * so a delete + reinstall keeps your data by default.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Remove cron events (recurring and single) for every plugin hook.
// Always runs: orphaned cron entries serve no purpose without the plugin,
// and reactivation reschedules them.
$swps_cron_hooks = array(
	'swps_generate_scheduled_post',
	'swps_process_topic',
	'swps_generate_featured_image',
	'swps_generate_content_image',
	'swps_prune_404_logs',
	'swps_audit_cron',
	'swps_analytics_aggregate',
	'swps_bot_analytics_aggregate',
	'swps_gsc_refresh_data',
	'swps_keyword_sync',
	'swps_link_maintenance',
	'swps_backlinks_daily_verify',
	'swps_competitors_daily_scan',
	'swps_refresh_models',
	'swps_aeo_sweep_proposals',
);

foreach ( $swps_cron_hooks as $swps_cron_hook ) {
	wp_unschedule_hook( $swps_cron_hook );
}

// 2. Clear any Action Scheduler entries if available. Always runs.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'swps_process_topic' );
	as_unschedule_all_actions( 'swps_generate_scheduled_post' );
	as_unschedule_all_actions( 'swps_generate_featured_image' );
	as_unschedule_all_actions( 'swps_generate_content_image' );
}

// 3. Remove transients. Always runs: pure cache, rebuilt on demand.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_swps\_%' OR option_name LIKE '%\_transient\_timeout\_swps\_%'"
);

// Everything below permanently destroys data — only with explicit opt-in.
if ( ! get_option( 'swps_remove_data_on_uninstall' ) ) {
	return;
}

// 4. Remove all swps_* options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'swps\_%'" );

// 5. Remove all _swps_* post meta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_swps\_%'" );

// 6. Delete all plugin CPT posts.
foreach ( array( 'swps_topic', 'swps_voice_profile' ) as $swps_post_type ) {
	$swps_posts = get_posts(
		array(
			'post_type'      => $swps_post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $swps_posts as $swps_post_id ) {
		wp_delete_post( $swps_post_id, true );
	}
}

// 7. Drop all custom tables.
$swps_tables = array(
	'swps_redirects',
	'swps_404_log',
	'swps_analytics',
	'swps_analytics_daily',
	'swps_bot_hits',
	'swps_bot_hits_daily',
	'swps_keyword_tracking',
	'swps_link_index',
	'swps_link_graph',
	'swps_backlinks',
);

foreach ( $swps_tables as $swps_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$swps_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// 8. Remove term meta.
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\_swps\_%'" );

// 9. Remove user meta.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\_swps\_%'" );
