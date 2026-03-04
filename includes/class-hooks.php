<?php
/**
 * WordPress hooks (filters and actions) for extensibility.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Hooks {

    /**
     * Apply the system prompt filter.
     *
     * @param string $prompt  The system prompt text.
     * @param string $tone    The selected tone.
     * @param string $style   Custom style notes.
     * @return string Filtered prompt.
     */
    public static function filter_system_prompt( string $prompt, string $tone, string $style ): string {
        return apply_filters( 'swps_system_prompt', $prompt, $tone, $style );
    }

    /**
     * Apply the user prompt filter.
     *
     * @param string $prompt       The user prompt text.
     * @param string $topic        The requested topic.
     * @param string $site_context Site context string.
     * @return string Filtered prompt.
     */
    public static function filter_user_prompt( string $prompt, string $topic, string $site_context ): string {
        return apply_filters( 'swps_user_prompt', $prompt, $topic, $site_context );
    }

    /**
     * Apply the post data filter before inserting.
     *
     * @param array $post_data WordPress post data array.
     * @param array $ai_result The AI response data.
     * @return array Filtered post data.
     */
    public static function filter_post_data( array $post_data, array $ai_result ): array {
        return apply_filters( 'swps_post_data', $post_data, $ai_result );
    }

    /**
     * Apply the image query filter.
     *
     * @param string $query   The image search query.
     * @param int    $post_id The post ID.
     * @return string Filtered query.
     */
    public static function filter_image_query( string $query, int $post_id ): string {
        return apply_filters( 'swps_image_query', $query, $post_id );
    }

    /**
     * Apply the FAQ schema filter.
     *
     * @param array  $schema    The FAQ schema array.
     * @param string $title     Post title.
     * @param array  $faq_items Raw FAQ items from AI.
     * @return array Filtered schema.
     */
    public static function filter_faq_schema( array $schema, string $title, array $faq_items ): array {
        return apply_filters( 'swps_faq_schema', $schema, $title, $faq_items );
    }

    /**
     * Apply the takeaways schema filter.
     *
     * @param array $schema    The ItemList schema array.
     * @param array $takeaways The raw takeaways array.
     * @return array Filtered schema.
     */
    public static function filter_takeaways_schema( array $schema, array $takeaways ): array {
        return apply_filters( 'swps_takeaways_schema', $schema, $takeaways );
    }

    /**
     * Apply the AI response filter.
     *
     * @param array  $response The parsed AI JSON response.
     * @param string $topic    The requested topic.
     * @return array Filtered response.
     */
    public static function filter_ai_response( array $response, string $topic ): array {
        return apply_filters( 'swps_ai_response', $response, $topic );
    }

    /**
     * Fire the before_generate action.
     *
     * @param string $topic    The topic being generated.
     * @param string $template The template being used.
     */
    public static function do_before_generate( string $topic, string $template ): void {
        do_action( 'swps_before_generate', $topic, $template );
    }

    /**
     * Fire the after_generate action.
     *
     * @param array  $result   The generation result data.
     * @param string $topic    The topic that was generated.
     * @param string $template The template that was used.
     */
    public static function do_after_generate( array $result, string $topic, string $template ): void {
        do_action( 'swps_after_generate', $result, $topic, $template );
    }

    /**
     * Fire the post_created action.
     *
     * @param int   $post_id   The new post ID.
     * @param array $ai_result The AI response data.
     * @param array $post_data The post data that was inserted.
     */
    public static function do_post_created( int $post_id, array $ai_result, array $post_data ): void {
        do_action( 'swps_post_created', $post_id, $ai_result, $post_data );
    }

    /**
     * Fire the generation_failed action.
     *
     * @param WP_Error $error  The error object.
     * @param string   $topic  The topic that was attempted.
     * @param string   $template The template that was used.
     */
    public static function do_generation_failed( WP_Error $error, string $topic, string $template ): void {
        do_action( 'swps_generation_failed', $error, $topic, $template );
    }

    /**
     * Apply the score weights filter.
     *
     * @param array  $weights   Analyzer weight map.
     * @param string $post_type The post type being scored.
     * @return array Filtered weights.
     */
    public static function filter_score_weights( array $weights, string $post_type ): array {
        return apply_filters( 'swps_score_weights', $weights, $post_type );
    }

    /**
     * Fire the score_complete action.
     *
     * @param array $results Score results array.
     * @param int   $post_id The scored post ID.
     */
    public static function do_score_complete( array $results, int $post_id ): void {
        do_action( 'swps_score_complete', $results, $post_id );
    }
}
