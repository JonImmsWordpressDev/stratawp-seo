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

    private SWPS_API $api;
    private SWPS_Analyzer $analyzer;

    public function __construct( SWPS_API $api, SWPS_Analyzer $analyzer ) {
        $this->api      = $api;
        $this->analyzer = $analyzer;
    }

    /**
     * Generate a complete blog post and save it as a WP draft.
     *
     * @param string $topic Optional specific topic. If empty, AI picks one.
     * @return array|WP_Error Post data on success, error on failure.
     */
    public function generate_post( string $topic = '' ): array|WP_Error {
        // Gather site context.
        $site_context   = $this->analyzer->build_context_for_prompt();
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
        foreach ( array_slice( $linkable_posts, 0, 50 ) as $link ) {
            $links_context .= "- \"{$link['title']}\" → {$link['url']}\n";
        }

        // Build the user prompt.
        $user_prompt = $this->build_user_prompt(
            $topic,
            $site_context,
            $links_context,
            $niche,
            $keywords,
            $min_words,
            $max_words,
            $min_links,
            $max_links,
            $include_faq,
            $include_toc
        );

        // Call Claude.
        $result = $this->api->chat_json( $system_prompt, $user_prompt, 8192 );

        if ( is_wp_error( $result ) ) {
            $this->log( 'Generation failed: ' . $result->get_error_message() );
            return $result;
        }

        // Validate required fields.
        $required = [ 'title', 'content_html', 'meta_description', 'slug' ];
        foreach ( $required as $field ) {
            if ( empty( $result[ $field ] ) ) {
                return new WP_Error(
                    'swps_missing_field',
                    sprintf( __( 'AI response missing required field: %s', 'stratawp-seo' ), $field )
                );
            }
        }

        // Create the WordPress post.
        $post_data = $this->create_wp_post( $result );

        if ( is_wp_error( $post_data ) ) {
            return $post_data;
        }

        $this->log( sprintf( 'Generated post #%d: "%s"', $post_data['post_id'], $result['title'] ) );

        return $post_data;
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
  "faq_schema": [{"question": "Q?", "answer": "A."}],
  "estimated_word_count": 1500
}

CRITICAL RULES:
- content_html must use proper HTML tags: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <a>, <strong>, <em>
- Internal links must use <a href="URL">anchor text</a> format with REAL URLs from the provided list
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
     * @param array $ai_result The parsed JSON from Claude.
     * @return array|WP_Error Post data or error.
     */
    private function create_wp_post( array $ai_result ): array|WP_Error {
        $post_status = get_option( 'swps_post_status', 'draft' );
        $post_author = (int) get_option( 'swps_post_author', get_current_user_id() );
        $category_id = (int) get_option( 'swps_post_category', 0 );

        // Handle category.
        if ( ! empty( $ai_result['suggested_category'] ) ) {
            $cat = get_cat_ID( $ai_result['suggested_category'] );
            if ( $cat > 0 ) {
                $category_id = $cat;
            } elseif ( $category_id === 0 ) {
                // Use the suggested category if no default is set, create it.
                $new_cat = wp_create_category( $ai_result['suggested_category'] );
                if ( ! is_wp_error( $new_cat ) ) {
                    $category_id = $new_cat;
                }
            }
        }

        // Build post content with optional FAQ schema.
        $content = $ai_result['content_html'];

        // Add FAQ schema as a script block if present.
        if ( ! empty( $ai_result['faq_schema'] ) ) {
            $schema = $this->build_faq_schema( $ai_result['title'], $ai_result['faq_schema'] );
            $content .= "\n\n<!-- StrataWP SEO FAQ Schema -->\n" . $schema;
        }

        // Insert the post.
        $post_id = wp_insert_post( [
            'post_title'    => sanitize_text_field( $ai_result['title'] ),
            'post_content'  => wp_kses_post( $content ),
            'post_excerpt'  => sanitize_text_field( $ai_result['excerpt'] ?? '' ),
            'post_status'   => $post_status,
            'post_author'   => $post_author,
            'post_name'     => sanitize_title( $ai_result['slug'] ),
            'post_category' => $category_id ? [ $category_id ] : [],
            'post_type'     => 'post',
        ], true );

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

        // Yoast SEO meta.
        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
        update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_keyword );

        // RankMath meta.
        update_post_meta( $post_id, 'rank_math_description', $meta_desc );
        update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_keyword );

        // Store StrataWP SEO metadata.
        update_post_meta( $post_id, '_swps_generated', true );
        update_post_meta( $post_id, '_swps_generated_at', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_swps_focus_keyword', $focus_keyword );
        update_post_meta( $post_id, '_swps_secondary_keywords', $ai_result['secondary_keywords'] ?? [] );
        update_post_meta( $post_id, '_swps_internal_links', $ai_result['internal_links_used'] ?? [] );

        return [
            'post_id'          => $post_id,
            'title'            => $ai_result['title'],
            'edit_url'         => get_edit_post_link( $post_id, 'raw' ),
            'preview_url'      => get_preview_post_link( $post_id ),
            'status'           => $post_status,
            'focus_keyword'    => $focus_keyword,
            'meta_description' => $meta_desc,
            'internal_links'   => $ai_result['internal_links_used'] ?? [],
            'word_count'       => $ai_result['estimated_word_count'] ?? 0,
        ];
    }

    /**
     * Build FAQ schema JSON-LD.
     *
     * @param string $title     Post title.
     * @param array  $faq_items Array of [{question, answer}].
     * @return string Script tag with JSON-LD.
     */
    private function build_faq_schema( string $title, array $faq_items ): string {
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

        return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>';
    }

    /**
     * Log a message.
     *
     * @param string $message Message to log.
     */
    private function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[StrataWP SEO] ' . $message );
        }

        // Also store in options for the admin UI log.
        $log   = get_option( 'swps_generation_log', [] );
        $log[] = [
            'time'    => current_time( 'mysql' ),
            'message' => $message,
        ];

        // Keep last 50 entries.
        $log = array_slice( $log, -50 );
        update_option( 'swps_generation_log', $log );
    }
}
