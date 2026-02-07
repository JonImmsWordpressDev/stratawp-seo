<?php
/**
 * WP-CLI commands for StrataWP SEO.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_CLI {

    /**
     * Generate a blog post.
     *
     * ## OPTIONS
     *
     * [<topic>]
     * : Optional topic for the post.
     *
     * [--template=<template>]
     * : Content template to use (auto, listicle, how-to, comparison, case-study, news, tutorial).
     * ---
     * default: auto
     * ---
     *
     * ## EXAMPLES
     *
     *     wp swps generate "Best practices for WordPress security"
     *     wp swps generate --template=listicle
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function generate( array $args, array $assoc_args ): void {
        $topic    = $args[0] ?? '';
        $template = $assoc_args['template'] ?? 'auto';

        WP_CLI::log( 'Starting content generation...' );

        if ( ! empty( $topic ) ) {
            WP_CLI::log( "Topic: {$topic}" );
        }

        if ( $template !== 'auto' ) {
            WP_CLI::log( "Template: {$template}" );
        }

        $plugin = stratawp_seo();
        $result = $plugin->generator->generate_post( $topic, $template );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( sprintf(
            'Post #%d created: "%s" (%s)',
            $result['post_id'],
            $result['title'],
            $result['status']
        ) );

        WP_CLI::log( "Edit: {$result['edit_url']}" );
        WP_CLI::log( "Focus keyword: {$result['focus_keyword']}" );
        WP_CLI::log( "Word count: ~{$result['word_count']}" );
    }

    /**
     * Analyze site content.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: json
     * options:
     *   - json
     *   - table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp swps analyze
     *     wp swps analyze --format=table
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function analyze( array $args, array $assoc_args ): void {
        $format = $assoc_args['format'] ?? 'json';

        WP_CLI::log( 'Analyzing site content...' );

        $plugin  = stratawp_seo();
        $summary = $plugin->analyzer->get_site_summary();

        if ( $format === 'table' ) {
            $stats = $summary['content_stats'];
            $items = [];
            foreach ( $stats as $key => $value ) {
                $items[] = [
                    'metric' => str_replace( '_', ' ', ucfirst( $key ) ),
                    'value'  => $value,
                ];
            }
            WP_CLI\Utils\format_items( 'table', $items, [ 'metric', 'value' ] );
        } else {
            WP_CLI::log( wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        }
    }

    /**
     * Show plugin status.
     *
     * ## EXAMPLES
     *
     *     wp swps status
     */
    public function status(): void {
        $ai_provider = get_option( 'swps_ai_provider', 'anthropic' );
        $model       = get_option( 'swps_model', '' );
        $schedule    = SWPS_Cron::get_schedule_info();

        WP_CLI::log( '--- StrataWP SEO Status ---' );
        WP_CLI::log( "Version: " . SWPS_VERSION );
        WP_CLI::log( "AI Provider: {$ai_provider}" );
        WP_CLI::log( "Model: {$model}" );
        WP_CLI::log( "Schedule: " . ( $schedule['enabled'] ? 'Active' : 'Inactive' ) );

        if ( $schedule['enabled'] ) {
            WP_CLI::log( "Next run: {$schedule['next_run']}" );
            WP_CLI::log( "Last run: {$schedule['last_run']}" );
        }

        // Cost stats.
        if ( get_option( 'swps_cost_tracking', false ) ) {
            $tracker = new SWPS_Cost_Tracker();
            $monthly = $tracker->get_monthly_stats();
            WP_CLI::log( sprintf(
                'This month: %d generations, $%.4f cost',
                $monthly['generation_count'],
                $monthly['total_cost']
            ) );
        }

        // Queue stats.
        $queue_count = wp_count_posts( SWPS_Topic_Queue::POST_TYPE );
        WP_CLI::log( "Queue: " . ( $queue_count->queued ?? 0 ) . " topics queued" );
    }

    /**
     * Manage the topic queue.
     *
     * ## OPTIONS
     *
     * <action>
     * : Queue action (list, add, remove, clear).
     *
     * [<value>]
     * : Topic title (for add) or topic ID (for remove).
     *
     * [--date=<date>]
     * : Scheduled date for the topic (Y-m-d H:i:s).
     *
     * [--template=<template>]
     * : Content template for the topic.
     * ---
     * default: auto
     * ---
     *
     * ## EXAMPLES
     *
     *     wp swps queue list
     *     wp swps queue add "WordPress security best practices" --date="2026-03-01 09:00:00"
     *     wp swps queue remove 123
     *     wp swps queue clear
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function queue( array $args, array $assoc_args ): void {
        $action = $args[0] ?? '';
        $value  = $args[1] ?? '';
        $queue  = new SWPS_Topic_Queue();

        switch ( $action ) {
            case 'list':
                $topics = $queue->get_queued_topics();
                if ( empty( $topics ) ) {
                    WP_CLI::log( 'Queue is empty.' );
                    return;
                }
                $items = [];
                foreach ( $topics as $topic ) {
                    $items[] = [
                        'ID'       => $topic->ID,
                        'title'    => $topic->post_title,
                        'date'     => $topic->post_date,
                        'status'   => $topic->post_status,
                        'template' => get_post_meta( $topic->ID, '_swps_template', true ) ?: 'auto',
                    ];
                }
                WP_CLI\Utils\format_items( 'table', $items, [ 'ID', 'title', 'date', 'status', 'template' ] );
                break;

            case 'add':
                if ( empty( $value ) ) {
                    WP_CLI::error( 'Topic title is required.' );
                }
                $date     = $assoc_args['date'] ?? '';
                $template = $assoc_args['template'] ?? 'auto';

                $result = $queue->create_topic( $value, $date, $template );
                if ( is_wp_error( $result ) ) {
                    WP_CLI::error( $result->get_error_message() );
                }
                WP_CLI::success( "Topic #{$result} added to queue." );
                break;

            case 'remove':
                if ( empty( $value ) ) {
                    WP_CLI::error( 'Topic ID is required.' );
                }
                $queue->delete_topic( (int) $value );
                WP_CLI::success( "Topic #{$value} removed." );
                break;

            case 'clear':
                $topics = $queue->get_queued_topics( 500 );
                $count  = count( $topics );
                foreach ( $topics as $topic ) {
                    $queue->delete_topic( $topic->ID );
                }
                WP_CLI::success( "{$count} topics cleared from queue." );
                break;

            default:
                WP_CLI::error( "Unknown action: {$action}. Use list, add, remove, or clear." );
        }
    }
}
