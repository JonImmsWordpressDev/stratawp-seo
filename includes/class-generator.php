<?php
/**
 * AI Content Generator.
 *
 * Generates SEO-optimized blog posts using Claude, aware of existing site content.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Generator {

    private SWPS_AI_Provider $api;
    private SWPS_Analyzer $analyzer;
    private SWPS_Image_Provider $images;
    private SWPS_Duplicate_Checker $duplicate_checker;
    private SWPS_Rate_Limiter $rate_limiter;
    private SWPS_Cost_Tracker $cost_tracker;

    public function __construct(
        SWPS_AI_Provider $api,
        SWPS_Analyzer $analyzer,
        SWPS_Image_Provider $images,
        SWPS_Duplicate_Checker $duplicate_checker,
        SWPS_Rate_Limiter $rate_limiter,
        SWPS_Cost_Tracker $cost_tracker
    ) {
        $this->api               = $api;
        $this->analyzer          = $analyzer;
        $this->images            = $images;
        $this->duplicate_checker = $duplicate_checker;
        $this->rate_limiter      = $rate_limiter;
        $this->cost_tracker      = $cost_tracker;
    }

    /**
     * Generate a complete blog post and save it as a WP draft.
     *
     * @param string $topic    Optional specific topic. If empty, AI picks one.
     * @param string $template Content template slug.
     * @return array|WP_Error Post data on success, error on failure.
     */
    public function generate_post( string $topic = '', string $template = 'auto' ): array|WP_Error {
        // Fire before_generate action.
        SWPS_Hooks::do_before_generate( $topic, $template );

        // Check rate limit.
        if ( ! $this->rate_limiter->can_generate() ) {
            $remaining = $this->rate_limiter->get_remaining_seconds();
            $error = new WP_Error(
                'swps_rate_limited',
                sprintf( __( 'Rate limited. Please wait %d seconds before generating again.', 'stratawp-seo' ), $remaining )
            );
            SWPS_Hooks::do_generation_failed( $error, $topic, $template );
            return $error;
        }

        // Build prompts and call AI.
        $ai_result = $this->call_ai( $topic, $template );

        if ( is_wp_error( $ai_result ) ) {
            $this->log( 'Generation failed: ' . $ai_result->get_error_message() );
            SWPS_Hooks::do_generation_failed( $ai_result, $topic, $template );
            return $ai_result;
        }

        // Apply AI response filter.
        $ai_result = SWPS_Hooks::filter_ai_response( $ai_result, $topic );

        // Check for duplicates.
        if ( ! empty( $ai_result['title'] ) ) {
            $duplicate = $this->duplicate_checker->is_duplicate( $ai_result['title'] );
            if ( false !== $duplicate ) {
                $error = new WP_Error(
                    'swps_duplicate',
                    sprintf( __( 'Duplicate detected: "%s" is too similar to existing title "%s"', 'stratawp-seo' ), $ai_result['title'], $duplicate )
                );
                SWPS_Hooks::do_generation_failed( $error, $topic, $template );
                return $error;
            }
        }

        // Lock rate limiter.
        $this->rate_limiter->lock();

        // Validate required fields.
        $required = [ 'title', 'content_html', 'meta_description', 'slug' ];
        foreach ( $required as $field ) {
            if ( empty( $ai_result[ $field ] ) ) {
                $error = new WP_Error(
                    'swps_missing_field',
                    sprintf( __( 'AI response missing required field: %s', 'stratawp-seo' ), $field )
                );
                SWPS_Hooks::do_generation_failed( $error, $topic, $template );
                return $error;
            }
        }

        // Create the WordPress post.
        $post_data = $this->create_wp_post( $ai_result, $template );

        if ( is_wp_error( $post_data ) ) {
            SWPS_Hooks::do_generation_failed( $post_data, $topic, $template );
            return $post_data;
        }

        $this->log( sprintf( 'Generated post #%d: "%s"', $post_data['post_id'], $ai_result['title'] ) );

        // Fire after_generate action.
        SWPS_Hooks::do_after_generate( $post_data, $topic, $template );

        return $post_data;
    }

    /**
     * Preview content without creating a post.
     *
     * @param string $topic    Optional topic.
     * @param string $template Content template slug.
     * @return array|WP_Error AI result data or error.
     */
    public function preview_content( string $topic = '', string $template = 'auto' ): array|WP_Error {
        // Check rate limit.
        if ( ! $this->rate_limiter->can_generate() ) {
            $remaining = $this->rate_limiter->get_remaining_seconds();
            return new WP_Error(
                'swps_rate_limited',
                sprintf( __( 'Rate limited. Please wait %d seconds.', 'stratawp-seo' ), $remaining )
            );
        }

        $ai_result = $this->call_ai( $topic, $template );

        if ( is_wp_error( $ai_result ) ) {
            return $ai_result;
        }

        // Lock rate limiter.
        $this->rate_limiter->lock();

        return $ai_result;
    }

    /**
     * Call the AI provider with built prompts.
     *
     * @param string $topic    Topic.
     * @param string $template Template slug.
     * @return array|WP_Error Parsed AI response or error.
     */
    private function call_ai( string $topic, string $template ): array|WP_Error {
        // Gather site context (limit posts to keep prompt under timeout threshold).
        $site_context   = $this->analyzer->build_context_for_prompt( 20 );
        $linkable_posts = $this->analyzer->get_linkable_posts();

        // Get user preferences.
        $niche       = get_option( 'swps_site_niche', '' );
        $tone        = get_option( 'swps_tone', 'professional' );
        $style       = get_option( 'swps_writing_style', '' );
        $keywords    = get_option( 'swps_target_keywords', '' );
        $min_words   = (int) get_option( 'swps_word_count_min', 1200 );
        $max_words   = (int) get_option( 'swps_word_count_max', 2000 );
        $min_links   = (int) get_option( 'swps_internal_links_min', 3 );
        $max_links   = (int) get_option( 'swps_internal_links_max', 6 );
        $include_faq = (bool) get_option( 'swps_include_faq_schema', true );
        $include_toc = (bool) get_option( 'swps_include_toc', true );

        // Build the system prompt.
        $system_prompt = $this->build_system_prompt( $tone, $style );

        // Build linkable posts reference.
        $links_context = "=== EXISTING PAGES FOR INTERNAL LINKING ===\n";
        $links_context .= "You MUST include natural internal links to these pages where relevant:\n";
        foreach ( $linkable_posts as $link ) {
            $links_context .= "- \"{$link['title']}\" → {$link['url']}\n";
        }

        // Build the user prompt.
        $user_prompt = $this->build_user_prompt(
            $topic, $site_context, $links_context, $niche, $keywords,
            $min_words, $max_words, $min_links, $max_links, $include_faq, $include_toc
        );

        // Apply template modifiers.
        if ( $template === 'auto' ) {
            $template = get_option( 'swps_default_template', 'auto' );
        }
        [ $system_prompt, $user_prompt ] = SWPS_Templates::apply( $system_prompt, $user_prompt, $template );

        // Apply hook filters.
        $system_prompt = SWPS_Hooks::filter_system_prompt( $system_prompt, $tone, $style );
        $user_prompt   = SWPS_Hooks::filter_user_prompt( $user_prompt, $topic, $site_context );

        // Call AI with 8192 tokens for complete posts with full JSON structure.
        // Input is kept lean (20 posts, 30 linkable) to stay under 120s timeout.
        $result = $this->api->chat_json( $system_prompt, $user_prompt, 8192 );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result;
    }

    /**
     * Build the system prompt for Claude.
     */
    private function build_system_prompt( string $tone, string $style ): string {
        $prompt = <<<PROMPT
You are an expert SEO content writer and WordPress specialist. You create blog posts that are:

1. SEO-optimized with proper heading hierarchy (H2, H3 — never H1, as WordPress uses the post title as H1)
2. Written with natural internal links to the site's existing content
3. Engaging and valuable to readers (not thin AI content)
4. Structured for featured snippets and rich results

TONE: {$tone}
PROMPT;

        if ( ! empty( $style ) ) {
            $prompt .= "\nWRITING STYLE: {$style}";
        }

        $prompt .= <<<'PROMPT'


RESPONSE FORMAT: Respond ONLY with a valid JSON object. No markdown fences, no explanation, just JSON.

Required JSON structure:
{
  "title": "The blog post title (also the SEO title tag)",
  "slug": "url-friendly-slug",
  "meta_description": "Compelling meta description, 150-160 characters",
  "content_html": "Full blog post HTML content with headings, paragraphs, lists, and internal links",
  "excerpt": "2-3 sentence post excerpt",
  "focus_keyword": "primary target keyword",
  "secondary_keywords": ["keyword2", "keyword3"],
  "suggested_tags": ["tag1", "tag2", "tag3"],
  "suggested_category": "Best fitting category name",
  "internal_links_used": [{"anchor_text": "text", "url": "url"}],
  "external_links": [{"anchor_text": "text", "url": "url", "source": "source name"}],
  "faq_schema": [{"question": "Q?", "answer": "A."}],
  "estimated_word_count": 1500
}

CRITICAL RULES:
- content_html must use proper HTML tags: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <a>, <strong>, <em>
- Internal links must use <a href="URL">anchor text</a> format with REAL URLs from the provided list
- Include 2-4 external links to authoritative, relevant sources (real websites, documentation, or industry resources)
- Never use H1 tags in content_html
- Every internal link must point to a URL from the provided existing pages list
- Include a table of contents at the start if requested
- Include FAQ section at the end if requested
- Write comprehensive, valuable content — not thin filler
PROMPT;

        return $prompt;
    }

    /**
     * Build the user prompt with site context and preferences.
     */
    private function build_user_prompt(
        string $topic,
        string $site_context,
        string $links_context,
        string $niche,
        string $keywords,
        int $min_words,
        int $max_words,
        int $min_links,
        int $max_links,
        bool $include_faq,
        bool $include_toc
    ): string {
        $prompt = $site_context . "\n" . $links_context . "\n";

        if ( ! empty( $topic ) ) {
            $prompt .= "TOPIC: Write a blog post about: {$topic}\n\n";
        } else {
            $prompt .= "TOPIC: Based on the site's niche ({$niche}) and existing content above, choose a topic that:\n";
            $prompt .= "- Fills a content gap (something the site hasn't covered yet)\n";
            $prompt .= "- Is relevant to the site's niche and audience\n";
            $prompt .= "- Has good search potential\n";
            $prompt .= "- Can naturally link to existing content\n\n";
        }

        if ( ! empty( $keywords ) ) {
            $prompt .= "TARGET KEYWORDS to incorporate naturally: {$keywords}\n\n";
        }

        $prompt .= "REQUIREMENTS:\n";
        $prompt .= "- Word count: {$min_words}-{$max_words} words\n";
        $prompt .= "- Include {$min_links}-{$max_links} internal links to existing pages listed above\n";
        $prompt .= "- Include 2-4 external links to authoritative sources (real websites, documentation, or industry publications)\n";

        if ( $include_toc ) {
            $prompt .= "- Include a table of contents (HTML list with anchor links to each H2) at the beginning\n";
        }

        if ( $include_faq ) {
            $prompt .= "- Include a FAQ section at the end with 3-5 questions (also populate the faq_schema field for structured data)\n";
        }

        $prompt .= "- Use proper heading hierarchy (H2 for main sections, H3 for subsections)\n";
        $prompt .= "- Suggest the best existing category for this post, or suggest a new one\n";
        $prompt .= "- Write a compelling meta description (150-160 characters)\n\n";
        $prompt .= "Generate the blog post now. Respond with JSON only.";

        return $prompt;
    }

    /**
     * Create a WordPress post from the AI-generated data.
     *
     * @param array  $ai_result The parsed JSON from Claude.
     * @param string $template  The template used.
     * @return array|WP_Error Post data or error.
     */
    private function create_wp_post( array $ai_result, string $template = 'auto' ): array|WP_Error {
        $post_status = get_option( 'swps_post_status', 'draft' );
        $post_author = (int) get_option( 'swps_post_author', get_current_user_id() );
        $category_id = (int) get_option( 'swps_post_category', 0 );

        // Handle category.
        if ( ! empty( $ai_result['suggested_category'] ) ) {
            $cat = get_cat_ID( $ai_result['suggested_category'] );
            if ( $cat > 0 ) {
                $category_id = $cat;
            } elseif ( $category_id === 0 ) {
                $new_cat = wp_create_category( $ai_result['suggested_category'] );
                if ( ! is_wp_error( $new_cat ) ) {
                    $category_id = $new_cat;
                }
            }
        }

        $content = $ai_result['content_html'];

        $post_data = [
            'post_title'    => sanitize_text_field( $ai_result['title'] ),
            'post_content'  => wp_kses_post( $content ),
            'post_excerpt'  => sanitize_text_field( $ai_result['excerpt'] ?? '' ),
            'post_status'   => $post_status,
            'post_author'   => $post_author,
            'post_name'     => sanitize_title( $ai_result['slug'] ),
            'post_category' => $category_id ? [ $category_id ] : [],
            'post_type'     => 'post',
        ];

        // Apply post data filter.
        $post_data = SWPS_Hooks::filter_post_data( $post_data, $ai_result );

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Set tags.
        if ( ! empty( $ai_result['suggested_tags'] ) ) {
            wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $ai_result['suggested_tags'] ) );
        }

        // Store SEO meta (compatible with Yoast and RankMath).
        $meta_desc     = sanitize_text_field( $ai_result['meta_description'] ?? '' );
        $focus_keyword = sanitize_text_field( $ai_result['focus_keyword'] ?? '' );

        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
        update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_keyword );
        update_post_meta( $post_id, 'rank_math_description', $meta_desc );
        update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_keyword );

        // Store StrataWP SEO metadata.
        update_post_meta( $post_id, '_swps_generated', true );
        update_post_meta( $post_id, '_swps_generated_at', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_swps_focus_keyword', $focus_keyword );
        update_post_meta( $post_id, '_swps_secondary_keywords', $ai_result['secondary_keywords'] ?? [] );
        update_post_meta( $post_id, '_swps_internal_links', $ai_result['internal_links_used'] ?? [] );
        update_post_meta( $post_id, '_swps_external_links', $ai_result['external_links'] ?? [] );
        update_post_meta( $post_id, '_swps_template', $template );

        // Store FAQ schema as post meta.
        if ( ! empty( $ai_result['faq_schema'] ) ) {
            $schema_data = $this->build_faq_schema_data( $ai_result['title'], $ai_result['faq_schema'] );
            $schema_data = SWPS_Hooks::filter_faq_schema( $schema_data, $ai_result['title'], $ai_result['faq_schema'] );
            $schema_tag  = '<script type="application/ld+json">' . wp_json_encode( $schema_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>';
            update_post_meta( $post_id, '_swps_faq_schema', $schema_tag );
        }

        // Track cost per post.
        $cost = null;
        $model = get_option( 'swps_model', '' );
        if ( ! empty( $ai_result['_usage'] ) ) {
            $input_tokens  = $ai_result['_usage']['input_tokens'] ?? 0;
            $output_tokens = $ai_result['_usage']['output_tokens'] ?? 0;
            $this->cost_tracker->track( $model, $input_tokens, $output_tokens, $post_id );
            $cost = $this->cost_tracker->calculate_cost( $model, $input_tokens, $output_tokens );
        }

        // Set featured image.
        if ( get_option( 'swps_featured_images', 1 ) ) {
            $image_query = $focus_keyword ?: $ai_result['title'];
            $image_query = SWPS_Hooks::filter_image_query( $image_query, $post_id );

            $image_result = $this->images->set_featured_image( $post_id, $image_query );

            if ( is_wp_error( $image_result ) ) {
                $this->log( 'Featured image failed: ' . $image_result->get_error_message() );
            }
        }

        // Fire post_created action.
        SWPS_Hooks::do_post_created( $post_id, $ai_result, $post_data );

        return [
            'post_id'          => $post_id,
            'title'            => $ai_result['title'],
            'edit_url'         => get_edit_post_link( $post_id, 'raw' ),
            'preview_url'      => get_preview_post_link( $post_id ),
            'status'           => $post_status,
            'focus_keyword'    => $focus_keyword,
            'meta_description' => $meta_desc,
            'internal_links'   => $ai_result['internal_links_used'] ?? [],
            'external_links'   => $ai_result['external_links'] ?? [],
            'word_count'       => $ai_result['estimated_word_count'] ?? 0,
            'template'         => $template,
            'cost'             => $cost,
        ];
    }

    /**
     * Build FAQ schema data array.
     *
     * @param string $title     Post title.
     * @param array  $faq_items Array of [{question, answer}].
     * @return array Schema data.
     */
    private function build_faq_schema_data( string $title, array $faq_items ): array {
        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'name'       => $title,
            'mainEntity' => [],
        ];

        foreach ( $faq_items as $item ) {
            $schema['mainEntity'][] = [
                '@type'          => 'Question',
                'name'           => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $item['answer'],
                ],
            ];
        }

        return $schema;
    }

    /**
     * Log a message.
     */
    private function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[StrataWP SEO] ' . $message );
        }

        $log   = get_option( 'swps_generation_log', [] );
        $log[] = [
            'time'    => current_time( 'mysql' ),
            'message' => $message,
        ];

        $log = array_slice( $log, -50 );
        update_option( 'swps_generation_log', $log );
    }
}
