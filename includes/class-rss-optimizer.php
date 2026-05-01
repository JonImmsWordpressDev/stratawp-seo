<?php
/**
 * RSS feed optimization — add content before/after feed items.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_RSS_Optimizer {

    public function __construct() {
        $before = get_option( 'swps_rss_before', '' );
        $after  = get_option( 'swps_rss_after', '' );

        if ( empty( $before ) && empty( $after ) ) {
            return;
        }

        add_filter( 'the_content_feed', [ $this, 'filter_feed_content' ] );
    }

    public function filter_feed_content( string $content ): string {
        $before = get_option( 'swps_rss_before', '' );
        $after  = get_option( 'swps_rss_after', '' );

        if ( ! empty( $before ) ) {
            $content = '<p>' . $this->replace_variables( $before ) . '</p>' . $content;
        }

        if ( ! empty( $after ) ) {
            $content .= '<p>' . $this->replace_variables( $after ) . '</p>';
        }

        return $content;
    }

    private function replace_variables( string $text ): string {
        $post_link = '<a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a>';
        $blog_link = '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>';
        $blog_name = esc_html( get_bloginfo( 'name' ) );

        return str_replace(
            [ '%%post_link%%', '%%blog_link%%', '%%blog_name%%' ],
            [ $post_link, $blog_link, $blog_name ],
            $text
        );
    }
}
