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

/**
 * Content template manager for AI generation.
 *
 * Manages templates for both posts and pages, providing type-aware helpers
 * for retrieving and applying template modifiers to prompts.
 *
 * @package StrataWP_SEO
 */
class SWPS_Templates {

	/** Content type: blog post. */
	public const TYPE_POST = 'post';

	/** Content type: static page. */
	public const TYPE_PAGE = 'page';

	/** Default page template slug. */
	public const PAGE_AUTO = 'page-auto';

	/**
	 * Normalize a requested content type to post|page.
	 *
	 * @param mixed $type Raw value.
	 * @return string
	 */
	public static function normalize_type( $type ): string {
		$type = is_scalar( $type ) ? strtolower( trim( (string) $type ) ) : '';
		return self::TYPE_PAGE === $type ? self::TYPE_PAGE : self::TYPE_POST;
	}

	/**
	 * Available content templates for a content type.
	 *
	 * @param string $type post|page.
	 * @return array Template definitions [slug => [name, system_modifier, user_modifier, (pages) min_words, max_words, include_faq]].
	 */
	public static function get_templates( string $type = self::TYPE_POST ): array {
		return self::TYPE_PAGE === self::normalize_type( $type ) ? self::page_templates() : self::post_templates();
	}

	/**
	 * Blog post templates (unchanged from earlier releases).
	 *
	 * @return array
	 */
	private static function post_templates(): array {
		return array(
			'auto'          => array(
				'name'            => __( 'Auto (AI Decides)', 'stratawp-seo' ),
				'system_modifier' => '',
				'user_modifier'   => '',
			),
			'informational' => array(
				'name'            => __( 'Informational Article', 'stratawp-seo' ),
				'system_modifier' => "\nCONTENT FORMAT: Write this as a straightforward informational article that explains a subject clearly and thoroughly. Use descriptive H2/H3 headings, short readable paragraphs, and a logical top-to-bottom flow. No gimmicky format — just clear, well-organized explanation.",
				'user_modifier'   => "\n- Format as a clear, informative article that explains the subject\n- Open with a concise summary of what the reader will learn\n- Use descriptive H2/H3 headings to organize topics\n- Keep paragraphs short and easy to scan\n- Cover the topic comprehensively and end with a brief conclusion",
			),
			'listicle'      => array(
				'name'            => __( 'Listicle', 'stratawp-seo' ),
				'system_modifier' => "\nCONTENT FORMAT: Write this as a numbered listicle (e.g., \"7 Ways to...\", \"10 Best...\"). Each list item should have its own H2 heading with a number, followed by 2-3 paragraphs of detail.",
				'user_modifier'   => "\n- Format as a numbered listicle with 7-15 items\n- Each item gets its own H2 heading\n- Include a brief intro and conclusion",
			),
			'how-to'        => array(
				'name'            => __( 'How-To Guide', 'stratawp-seo' ),
				'system_modifier' => "\nCONTENT FORMAT: Write this as a step-by-step how-to guide. Use numbered steps as H2 headings (\"Step 1: ...\"). Include prerequisites, tools needed, and expected outcomes.",
				'user_modifier'   => "\n- Format as a step-by-step how-to guide\n- Include a prerequisites/what you'll need section\n- Number each step clearly\n- Add tips and warnings where relevant\n- Include an expected results section",
			),
			'comparison'    => array(
				'name'            => __( 'Comparison / Vs', 'stratawp-seo' ),
				'system_modifier' => "\nCONTENT FORMAT: Write this as a detailed comparison article. Compare 2-4 options/products/approaches side by side. Include a comparison table in HTML, pros and cons for each, and a clear recommendation.",
				'user_modifier'   => "\n- Format as a comparison article\n- Include an HTML comparison table with key features\n- List pros and cons for each option\n- Provide a clear winner/recommendation with reasoning\n- Add a \"Who should choose what\" section",
			),
			'case-study'    => array(
				'name'            => __( 'Case Study', 'stratawp-seo' ),
				'system_modifier' => "\nCONTENT FORMAT: Write this as a case study with clear sections: Challenge/Problem, Solution/Approach, Results/Outcomes, and Key Takeaways. Use specific (but realistic fictional) data points and metrics.",
				'user_modifier'   => "\n- Format as a case study\n- Include sections: Background, Challenge, Solution, Results, Key Takeaways\n- Use specific metrics and data points (realistic examples)\n- Include quotes or testimonials (fictional but realistic)\n- End with actionable lessons readers can apply",
			),
			'news'          => array(
				'name'            => __( 'News / Trend Analysis', 'stratawp-seo' ),
				'system_modifier' => "\nCONTENT FORMAT: Write this as a news analysis or trend piece. Cover the who/what/when/where/why. Include expert perspectives, industry impact, and future predictions.",
				'user_modifier'   => "\n- Format as a news/trend analysis article\n- Lead with the most important information (inverted pyramid)\n- Include industry context and background\n- Add expert perspectives and analysis\n- Discuss implications and future predictions\n- Keep a journalistic, balanced tone",
			),
			'tutorial'      => array(
				'name'            => __( 'Tutorial / Deep Dive', 'stratawp-seo' ),
				'system_modifier' => "\nCONTENT FORMAT: Write this as an in-depth tutorial or technical deep dive. Include code examples where relevant (in <pre><code> blocks), detailed explanations, and practical examples.",
				'user_modifier'   => "\n- Format as a detailed tutorial or deep dive\n- Include code examples in <pre><code> blocks where relevant\n- Explain concepts from basics to advanced\n- Add practical, real-world examples\n- Include troubleshooting tips and common pitfalls\n- Provide references for further learning",
			),
		);
	}

	/**
	 * Page templates: evergreen, conversion-oriented structures.
	 *
	 * Word ranges replace the post word-count settings; include_faq decides
	 * whether the FAQ requirement (and faq_schema) is requested.
	 *
	 * @return array
	 */
	private static function page_templates(): array {
		$no_invent = ' Use only facts, names, credentials, prices and places supplied in the brief or source material; where a detail is missing, write around it or leave a short [placeholder] rather than inventing it.';

		return array(
			self::PAGE_AUTO => array(
				'name'            => __( 'Auto (AI decides)', 'stratawp-seo' ),
				'system_modifier' => "\nPAGE STRUCTURE: Decide from the brief and topic whether this is best written as a service page, landing page, about page or location page, then follow that structure." . $no_invent,
				'user_modifier'   => "\n- Choose the page structure (service, landing, about or location) that best fits the brief and topic, and say which you chose in the excerpt's first sentence\n- Open with a clear statement of who the page is for and what it offers\n- End with one clear call to action",
				'min_words'       => 600,
				'max_words'       => 1200,
				'include_faq'     => false,
			),
			'service'       => array(
				'name'            => __( 'Service page', 'stratawp-seo' ),
				'system_modifier' => "\nPAGE STRUCTURE: Write this as a service page that explains one service and persuades the right visitor to enquire." . $no_invent,
				'user_modifier'   => "\n- Sections in order: who this service is for; what is included; how it works (the process, as numbered steps or short H3s); why choose us (only supplied proof); pricing only if supplied; FAQ; call to action\n- Lead with the outcome for the customer, not the feature list\n- Keep paragraphs short and scannable",
				'min_words'       => 800,
				'max_words'       => 1400,
				'include_faq'     => true,
			),
			'landing'       => array(
				'name'            => __( 'Landing page', 'stratawp-seo' ),
				'system_modifier' => "\nPAGE STRUCTURE: Write this as a conversion-focused landing page with one goal and one primary call to action." . $no_invent,
				'user_modifier'   => "\n- Sections in order: hero statement (one H2 and a two-sentence promise); 3-5 core benefits as H3s; how it works; proof (only supplied facts); objections answered as short H3 question/answer pairs; final call to action that repeats the primary CTA\n- One primary call to action, repeated at the end; no competing offers\n- Every section should move the reader toward the CTA",
				'min_words'       => 600,
				'max_words'       => 1100,
				'include_faq'     => false,
			),
			'about'         => array(
				'name'            => __( 'About / Team', 'stratawp-seo' ),
				'system_modifier' => "\nPAGE STRUCTURE: Write this as an About page in the first person plural (or singular for a personal brand) that builds trust." . $no_invent,
				'user_modifier'   => "\n- Sections in order: our story or origin; what we do and for whom; our values or approach; credentials and experience (only supplied facts); the team (use [Name, role] placeholders where names are not supplied); how to get in touch\n- Warm and specific; avoid generic mission-statement language\n- End with a contact call to action",
				'min_words'       => 500,
				'max_words'       => 900,
				'include_faq'     => false,
			),
			'location'      => array(
				'name'            => __( 'Location / area page', 'stratawp-seo' ),
				'system_modifier' => "\nPAGE STRUCTURE: Write this as a location page for one service in one city or area. Name the service and the place in the title and in the first paragraph." . $no_invent,
				'user_modifier'   => "\n- Sections in order: the service in this place (service + place in the first paragraph); local context (only supplied facts; never invent landmarks, statistics or local claims); areas served as a list if supplied; how to get started; FAQ; call to action\n- Use the place name naturally, not in every sentence\n- Do not fabricate reviews, addresses or opening hours",
				'min_words'       => 800,
				'max_words'       => 1400,
				'include_faq'     => true,
			),
		);
	}

	/**
	 * Get template options for a select dropdown.
	 *
	 * @param string $type post|page.
	 * @return array [slug => name].
	 */
	public static function get_options( string $type = self::TYPE_POST ): array {
		$options = array();
		foreach ( self::get_templates( $type ) as $slug => $template ) {
			$options[ $slug ] = $template['name'];
		}
		return $options;
	}

	/**
	 * Get a specific template's modifiers.
	 *
	 * @param string $slug Template slug.
	 * @param string $type post|page.
	 * @return array|null Template data or null if not found for that type.
	 */
	public static function get_template( string $slug, string $type = self::TYPE_POST ): ?array {
		$templates = self::get_templates( $type );
		return $templates[ $slug ] ?? null;
	}

	/**
	 * Resolve a requested slug to one that exists for the type, else the type's auto.
	 *
	 * @param string $slug Requested slug.
	 * @param string $type post|page.
	 * @return string
	 */
	public static function resolve_slug( string $slug, string $type ): string {
		$type = self::normalize_type( $type );
		if ( null !== self::get_template( $slug, $type ) ) {
			return $slug;
		}
		return self::TYPE_PAGE === $type ? self::PAGE_AUTO : 'auto';
	}

	/**
	 * Apply template modifiers to prompts.
	 *
	 * Post 'auto' applies nothing (unchanged behaviour). Page 'page-auto' DOES
	 * apply its modifiers, because a page always needs a structure hint.
	 *
	 * @param string $system_prompt The system prompt.
	 * @param string $user_prompt   The user prompt.
	 * @param string $template_slug The template to apply.
	 * @param string $type          post|page.
	 * @return array [system_prompt, user_prompt].
	 */
	public static function apply( string $system_prompt, string $user_prompt, string $template_slug, string $type = self::TYPE_POST ): array {
		$type     = self::normalize_type( $type );
		$template = self::get_template( $template_slug, $type );

		if ( ! $template || ( self::TYPE_POST === $type && 'auto' === $template_slug ) ) {
			return array( $system_prompt, $user_prompt );
		}

		if ( ! empty( $template['system_modifier'] ) ) {
			$system_prompt .= $template['system_modifier'];
		}

		if ( ! empty( $template['user_modifier'] ) ) {
			$user_prompt .= $template['user_modifier'];
		}

		return array( $system_prompt, $user_prompt );
	}
}
