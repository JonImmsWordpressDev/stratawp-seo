<?php
/**
 * WP-Cron scheduling for automated content generation.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Cron {

    private const HOOK = 'swps_generate_scheduled_post';

    private SWPS_Generator $generator;
    private ?SWPS_Topic_Queue $queue;

    public function __construct( SWPS_Generator $generator, ?SWPS_Topic_Queue $queue = null ) {
        $this->generator = $generator;
        $this->queue     = $queue;

        add_action( self::HOOK, [ $this, 'run_scheduled_generation' ] );
        add_filter( 'cron_schedules', [ $this, 'add_custom_schedules' ] );
    }

    /**
     * Add custom cron schedules.
     */
    public function add_custom_schedules( array $schedules ): array {
        $schedules['swps_twice_weekly'] = [
            'interval' => 3.5 * DAY_IN_SECONDS,
            'display'  => __( 'Twice Weekly', 'stratawp-seo' ),
        ];

        $schedules['swps_three_weekly'] = [
            'interval' => 2.33 * DAY_IN_SECONDS,
            'display'  => __( 'Three Times Weekly', 'stratawp-seo' ),
        ];

        $schedules['swps_biweekly'] = [
            'interval' => 14 * DAY_IN_SECONDS,
            'display'  => __( 'Every Two Weeks', 'stratawp-seo' ),
        ];

        $schedules['swps_monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => __( 'Monthly', 'stratawp-seo' ),
        ];

        return $schedules;
    }

    /**
     * The cron callback — generates posts from queue or randomly.
     */
    public function run_scheduled_generation(): void {
        $enabled = get_option( 'swps_cron_enabled', false );

        if ( ! $enabled ) {
            return;
        }

        $posts_per_run = (int) get_option( 'swps_cron_posts_per_run', 1 );
        $posts_per_run = min( $posts_per_run, 5 );

        for ( $i = 0; $i < $posts_per_run; $i++ ) {
            $topic    = '';
            $template = get_option( 'swps_default_template', 'auto' );
            $topic_id = 0;

            // Check queue for next topic.
            if ( $this->queue ) {
                $next_topic = $this->queue->get_next_topic();
                if ( $next_topic ) {
                    $topic    = $next_topic->post_title;
                    $template = get_post_meta( $next_topic->ID, '_swps_template', true ) ?: $template;
                    $topic_id = $next_topic->ID;
                    $this->queue->update_status( $topic_id, 'generating' );
                }
            }

            $result = $this->generator->generate_post( $topic, $template );

            if ( is_wp_error( $result ) ) {
                error_log( '[StrataWP SEO Cron] Generation failed: ' . $result->get_error_message() );

                if ( $topic_id && $this->queue ) {
                    $this->queue->update_status( $topic_id, 'failed', $result->get_error_message() );
                }

                break;
            }

            // Update topic status if from queue.
            if ( $topic_id && $this->queue ) {
                $this->queue->update_status( $topic_id, 'published', '', $result['post_id'] );
            }

            if ( $i < $posts_per_run - 1 ) {
                sleep( 5 );
            }
        }

        update_option( 'swps_cron_last_run', current_time( 'mysql' ) );
    }

    /**
     * Schedule the cron event.
     */
    public static function schedule(): void {
        self::unschedule();

        $frequency = get_option( 'swps_cron_frequency', 'weekly' );
        $day       = get_option( 'swps_cron_day', 'monday' );
        $time      = get_option( 'swps_cron_time', '09:00' );

        $recurrence_map = [
            'daily'        => 'daily',
            'twice_weekly' => 'swps_twice_weekly',
            'three_weekly' => 'swps_three_weekly',
            'weekly'       => 'weekly',
            'biweekly'     => 'swps_biweekly',
            'monthly'      => 'swps_monthly',
        ];

        $recurrence = $recurrence_map[ $frequency ] ?? 'weekly';
        $next_run = self::calculate_next_run( $day, $time );

        wp_schedule_event( $next_run, $recurrence, self::HOOK );
    }

    /**
     * Unschedule the cron event.
     */
    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
        wp_unschedule_hook( self::HOOK );
    }

    /**
     * Calculate the next run timestamp based on desired day and time.
     */
    private static function calculate_next_run( string $day, string $time ): int {
        $timezone = wp_timezone();
        $now      = new DateTime( 'now', $timezone );

        list( $hour, $minute ) = explode( ':', $time );

        $next = new DateTime( "next {$day}", $timezone );
        $next->setTime( (int) $hour, (int) $minute, 0 );

        $diff = $now->diff( $next );
        if ( $diff->days > 7 ) {
            $next = clone $now;
            $next->setTime( (int) $hour, (int) $minute, 0 );
            if ( $next <= $now ) {
                $next->modify( '+1 day' );
            }
        }

        return $next->getTimestamp();
    }

    /**
     * Get info about the current schedule for display.
     */
    public static function get_schedule_info(): array {
        $next = wp_next_scheduled( self::HOOK );

        return [
            'enabled'    => (bool) get_option( 'swps_cron_enabled', false ),
            'frequency'  => get_option( 'swps_cron_frequency', 'weekly' ),
            'day'        => get_option( 'swps_cron_day', 'monday' ),
            'time'       => get_option( 'swps_cron_time', '09:00' ),
            'posts_per'  => (int) get_option( 'swps_cron_posts_per_run', 1 ),
            'next_run'   => $next ? wp_date( 'F j, Y \a\t g:i A', $next ) : __( 'Not scheduled', 'stratawp-seo' ),
            'last_run'   => get_option( 'swps_cron_last_run', __( 'Never', 'stratawp-seo' ) ),
        ];
    }
}
