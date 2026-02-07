<?php
/**
 * Content templates for AI generation.
 *
 * Templates modify the system/user prompts to produce specific content formats.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Templates {

    /**
     * Available content templates.
     *
     * @return array Template definitions [slug => [name, system_modifier, user_modifier]].
     */
    public static function get_templates(): array {
        return [
            'auto' => [
                'name'            => __( 'Auto (AI Decides)', 'stratawp-seo' ),
                'system_modifier' => '',
                'user_modifier'   => '',
            ],
            'listicle' => [
                'name'            => __( 'Listicle', 'stratawp-seo' ),
                'system_modifier' => "\nCONTENT FORMAT: Write this as a numbered listicle (e.g., \"7 Ways to...\", \"10 Best...\"). Each list item should have its own H2 heading with a number, followed by 2-3 paragraphs of detail.",
                'user_modifier'   => "\n- Format as a numbered listicle with 7-15 items\n- Each item gets its own H2 heading\n- Include a brief intro and conclusion",
            ],
            'how-to' => [
                'name'            => __( 'How-To Guide', 'stratawp-seo' ),
                'system_modifier' => "\nCONTENT FORMAT: Write this as a step-by-step how-to guide. Use numbered steps as H2 headings (\"Step 1: ...\"). Include prerequisites, tools needed, and expected outcomes.",
                'user_modifier'   => "\n- Format as a step-by-step how-to guide\n- Include a prerequisites/what you'll need section\n- Number each step clearly\n- Add tips and warnings where relevant\n- Include an expected results section",
            ],
            'comparison' => [
                'name'            => __( 'Comparison / Vs', 'stratawp-seo' ),
                'system_modifier' => "\nCONTENT FORMAT: Write this as a detailed comparison article. Compare 2-4 options/products/approaches side by side. Include a comparison table in HTML, pros and cons for each, and a clear recommendation.",
                'user_modifier'   => "\n- Format as a comparison article\n- Include an HTML comparison table with key features\n- List pros and cons for each option\n- Provide a clear winner/recommendation with reasoning\n- Add a \"Who should choose what\" section",
            ],
            'case-study' => [
                'name'            => __( 'Case Study', 'stratawp-seo' ),
                'system_modifier' => "\nCONTENT FORMAT: Write this as a case study with clear sections: Challenge/Problem, Solution/Approach, Results/Outcomes, and Key Takeaways. Use specific (but realistic fictional) data points and metrics.",
                'user_modifier'   => "\n- Format as a case study\n- Include sections: Background, Challenge, Solution, Results, Key Takeaways\n- Use specific metrics and data points (realistic examples)\n- Include quotes or testimonials (fictional but realistic)\n- End with actionable lessons readers can apply",
            ],
            'news' => [
                'name'            => __( 'News / Trend Analysis', 'stratawp-seo' ),
                'system_modifier' => "\nCONTENT FORMAT: Write this as a news analysis or trend piece. Cover the who/what/when/where/why. Include expert perspectives, industry impact, and future predictions.",
                'user_modifier'   => "\n- Format as a news/trend analysis article\n- Lead with the most important information (inverted pyramid)\n- Include industry context and background\n- Add expert perspectives and analysis\n- Discuss implications and future predictions\n- Keep a journalistic, balanced tone",
            ],
            'tutorial' => [
                'name'            => __( 'Tutorial / Deep Dive', 'stratawp-seo' ),
                'system_modifier' => "\nCONTENT FORMAT: Write this as an in-depth tutorial or technical deep dive. Include code examples where relevant (in <pre><code> blocks), detailed explanations, and practical examples.",
                'user_modifier'   => "\n- Format as a detailed tutorial or deep dive\n- Include code examples in <pre><code> blocks where relevant\n- Explain concepts from basics to advanced\n- Add practical, real-world examples\n- Include troubleshooting tips and common pitfalls\n- Provide references for further learning",
            ],
        ];
    }

    /**
     * Get template options for a select dropdown.
     *
     * @return array [slug => name].
     */
    public static function get_options(): array {
        $options = [];
        foreach ( self::get_templates() as $slug => $template ) {
            $options[ $slug ] = $template['name'];
        }
        return $options;
    }

    /**
     * Get a specific template's modifiers.
     *
     * @param string $slug Template slug.
     * @return array|null Template data or null if not found.
     */
    public static function get_template( string $slug ): ?array {
        $templates = self::get_templates();
        return $templates[ $slug ] ?? null;
    }

    /**
     * Apply template modifiers to prompts.
     *
     * @param string $system_prompt The system prompt.
     * @param string $user_prompt   The user prompt.
     * @param string $template_slug The template to apply.
     * @return array [system_prompt, user_prompt].
     */
    public static function apply( string $system_prompt, string $user_prompt, string $template_slug ): array {
        $template = self::get_template( $template_slug );

        if ( ! $template || $template_slug === 'auto' ) {
            return [ $system_prompt, $user_prompt ];
        }

        if ( ! empty( $template['system_modifier'] ) ) {
            $system_prompt .= $template['system_modifier'];
        }

        if ( ! empty( $template['user_modifier'] ) ) {
            $user_prompt .= $template['user_modifier'];
        }

        return [ $system_prompt, $user_prompt ];
    }
}
