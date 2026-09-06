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
	private SWPS_Duplicate_Checker $duplicate_checker;
	private SWPS_Rate_Limiter $rate_limiter;
	private SWPS_Cost_Tracker $cost_tracker;

	/**
	 * Fetch report from the last call_ai().
	 *
	 * @var array<int, array{url: string, ok: bool, error: string}>
	 */
	private array $last_sources_report = array();

	/**
	 * URLs dropped from the last request for exceeding the cap.
	 *
	 * @var string[]
	 */
	private array $last_dropped_sources = array();

	public function __construct(
		SWPS_AI_Provider $api,
		SWPS_Analyzer $analyzer,
		SWPS_Duplicate_Checker $duplicate_checker,
		SWPS_Rate_Limiter $rate_limiter,
		SWPS_Cost_Tracker $cost_tracker
	) {
		$this->api               = $api;
		$this->analyzer          = $analyzer;
		$this->duplicate_checker = $duplicate_checker;
		$this->rate_limiter      = $rate_limiter;
		$this->cost_tracker      = $cost_tracker;
	}

	/**
	 * Generate a complete blog post and save it as a WP draft.
	 *
	 * @param string $topic    Optional specific topic. If empty, AI picks one.
	 * @param string $template Content template slug.
	 * @param array  $brief    Optional normalized content brief (see SWPS_Content_Brief::from_request()).
	 * @param array  $options  Optional generation options (content_type, parent_id, image_plan, sources).
	 * @return array|WP_Error Post data on success, error on failure.
	 */
	public function generate_post( string $topic = '', string $template = 'auto', array $brief = array(), array $options = array() ): array|WP_Error {
		$options = $this->normalize_options( $options );

		// Image generation (especially Gemini) can take several minutes per post.
		// Lift PHP execution caps so cron/background jobs don't die mid-download,
		// leaving posts with no images and an empty debug.log.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		ignore_user_abort( true );

		// Budget gate — cheapest check, runs before anything spends money.
		$budget_check = SWPS_Autopilot_Guardian::check_budget();
		if ( is_wp_error( $budget_check ) ) {
			SWPS_Hooks::do_generation_failed( $budget_check, $topic, $template );
			return $budget_check;
		}

		// Fire before_generate action.
		SWPS_Hooks::do_before_generate( $topic, $template );

		// Check rate limit.
		if ( ! $this->rate_limiter->can_generate() ) {
			$remaining = $this->rate_limiter->get_remaining_seconds();
			$error     = new WP_Error(
				'swps_rate_limited',
				sprintf( __( 'Rate limited. Please wait %d seconds before generating again.', 'stratawp-seo' ), $remaining )
			);
			SWPS_Hooks::do_generation_failed( $error, $topic, $template );
			return $error;
		}

		// Build prompts and call AI.
		$ai_result = $this->call_ai( $topic, $template, $brief, $options );

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
					sprintf( __( 'Duplicate detected: "%1$s" is too similar to existing title "%2$s"', 'stratawp-seo' ), $ai_result['title'], $duplicate )
				);
				SWPS_Hooks::do_generation_failed( $error, $topic, $template );
				return $error;
			}
		}

		// Lock rate limiter.
		$this->rate_limiter->lock();

		// Validate required fields.
		$required = array( 'title', 'content_html', 'meta_description', 'slug' );
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
		$post_data = $this->create_wp_post( $ai_result, $template, $options );

		if ( is_wp_error( $post_data ) ) {
			SWPS_Hooks::do_generation_failed( $post_data, $topic, $template );
			return $post_data;
		}

		$post_data['sources']         = $this->last_sources_report;
		$post_data['dropped_sources'] = $this->last_dropped_sources;

		// Fire after_generate action.
		SWPS_Hooks::do_after_generate( $post_data, $topic, $template );

		return $post_data;
	}

	/**
	 * Preview content without creating a post.
	 *
	 * @param string $topic    Optional topic.
	 * @param string $template Content template slug.
	 * @param array  $brief    Optional normalized content brief (see SWPS_Content_Brief::from_request()).
	 * @param array  $options  Optional generation options (content_type, parent_id, image_plan, sources).
	 * @return array|WP_Error AI result data or error.
	 */
	public function preview_content( string $topic = '', string $template = 'auto', array $brief = array(), array $options = array() ): array|WP_Error {
		$options = $this->normalize_options( $options );

		// Check rate limit.
		if ( ! $this->rate_limiter->can_generate() ) {
			$remaining = $this->rate_limiter->get_remaining_seconds();
			return new WP_Error(
				'swps_rate_limited',
				sprintf( __( 'Rate limited. Please wait %d seconds.', 'stratawp-seo' ), $remaining )
			);
		}

		$ai_result = $this->call_ai( $topic, $template, $brief, $options );

		if ( is_wp_error( $ai_result ) ) {
			return $ai_result;
		}

		// Lock rate limiter.
		$this->rate_limiter->lock();

		$ai_result['content_type']    = $options['content_type'];
		$ai_result['sources']         = $this->last_sources_report;
		$ai_result['dropped_sources'] = $this->last_dropped_sources;

		return $ai_result;
	}

	/**
	 * Normalize generation options.
	 *
	 * Missing keys reproduce the 4.30 behaviour exactly: a blog post, no
	 * parent, image handling from Settings, no source material.
	 *
	 * @param array<string, mixed> $options Raw options.
	 * @return array{content_type: string, parent_id: int, image_plan: array|null, sources: string}
	 */
	private function normalize_options( array $options ): array {
		$content_type = SWPS_Templates::normalize_type( $options['content_type'] ?? SWPS_Templates::TYPE_POST );
		$parent_id    = 0;

		if ( SWPS_Templates::TYPE_PAGE === $content_type && ! empty( $options['parent_id'] ) ) {
			$candidate = (int) $options['parent_id'];
			$parent    = $candidate > 0 ? get_post( $candidate ) : null;
			if ( $parent && 'page' === $parent->post_type && 'trash' !== $parent->post_status ) {
				$parent_id = $candidate;
			}
		}

		$image_plan = null;
		if ( isset( $options['image_plan'] ) && is_array( $options['image_plan'] ) && array_key_exists( 'featured', $options['image_plan'] ) ) {
			$image_plan = array(
				'featured'      => (bool) $options['image_plan']['featured'],
				'content_count' => max( 0, min( SWPS_Image_Plan::MAX_CONTENT_IMAGES, (int) ( $options['image_plan']['content_count'] ?? 0 ) ) ),
			);
		}

		return array(
			'content_type' => $content_type,
			'parent_id'    => $parent_id,
			'image_plan'   => $image_plan,
			'sources'      => is_string( $options['sources'] ?? null ) ? $options['sources'] : '',
		);
	}

	/**
	 * Rewrite a content brief for clarity ("Improve my brief").
	 *
	 * Makes one small AI request and returns the proposed rewrite for the user
	 * to review. It never generates the article and never replaces the brief
	 * itself — the caller decides whether to accept the proposal.
	 *
	 * @param array $brief Normalized content brief (see SWPS_Content_Brief::from_request()).
	 * @return array|WP_Error { improved_brief: string, notes: string[] } or error.
	 */
	public function improve_brief( array $brief ): array|WP_Error {
		if ( SWPS_Content_Brief::is_empty( $brief ) ) {
			return new WP_Error( 'swps_empty_brief', __( 'Write a brief first, then ask for an improved version.', 'stratawp-seo' ) );
		}

		$budget_check = SWPS_Autopilot_Guardian::check_budget();
		if ( is_wp_error( $budget_check ) ) {
			return $budget_check;
		}

		$result = $this->api->chat_json(
			SWPS_Content_Brief::improve_system_prompt(),
			SWPS_Content_Brief::improve_user_prompt( $brief ),
			2048
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$improved = SWPS_Content_Brief::sanitize( $result['improved_brief'] ?? '', SWPS_Content_Brief::MAX_BRIEF_LENGTH );
		if ( '' === $improved ) {
			return new WP_Error( 'swps_improve_failed', __( 'The AI did not return a usable brief. Please try again.', 'stratawp-seo' ) );
		}

		$notes = array();
		foreach ( (array) ( $result['notes'] ?? array() ) as $note ) {
			if ( is_scalar( $note ) ) {
				$note = SWPS_Content_Brief::sanitize( $note, 300 );
				if ( '' !== $note ) {
					$notes[] = $note;
				}
			}
		}

		if ( ! empty( $result['_usage'] ) ) {
			$this->cost_tracker->track(
				(string) get_option( 'swps_model', '' ),
				(int) ( $result['_usage']['input_tokens'] ?? 0 ),
				(int) ( $result['_usage']['output_tokens'] ?? 0 )
			);
		}

		return array(
			'improved_brief' => $improved,
			'notes'          => array_slice( $notes, 0, 10 ),
		);
	}

	/**
	 * Call the AI provider with built prompts.
	 *
	 * @param string $topic    Topic.
	 * @param string $template Template slug.
	 * @param array  $brief    Normalized content brief (may be empty).
	 * @param array  $options  Normalized generation options (see normalize_options()).
	 * @return array|WP_Error Parsed AI response or error.
	 */
	private function call_ai( string $topic, string $template, array $brief = array(), array $options = array() ): array|WP_Error {
		$content_type = SWPS_Templates::normalize_type( $options['content_type'] ?? SWPS_Templates::TYPE_POST );
		$is_page      = SWPS_Templates::TYPE_PAGE === $content_type;

		// Gather site context (limit posts to keep prompt under timeout threshold).
		$site_context   = $this->analyzer->build_context_for_prompt( 20 );
		$linkable_posts = $this->analyzer->get_linkable_posts();

		// Get user preferences.
		$niche             = get_option( 'swps_site_niche', '' );
		$tone              = get_option( 'swps_tone', 'professional' );
		$style             = get_option( 'swps_writing_style', '' );
		$keywords          = get_option( 'swps_target_keywords', '' );
		$min_words         = (int) get_option( 'swps_word_count_min', 1200 );
		$max_words         = (int) get_option( 'swps_word_count_max', 2000 );
		$min_links         = (int) get_option( 'swps_internal_links_min', 3 );
		$max_links         = (int) get_option( 'swps_internal_links_max', 6 );
		$include_faq       = (bool) get_option( 'swps_include_faq_schema', true );
		$include_toc       = (bool) get_option( 'swps_include_toc', true );
		$include_takeaways = (bool) get_option( 'swps_include_takeaways', false );
		$takeaways_count   = (int) get_option( 'swps_takeaways_count', 5 );

		// Resolve the template for the type first: page templates supply the
		// word range and FAQ flag; posts keep the settings-driven values.
		if ( $is_page ) {
			$template          = SWPS_Templates::resolve_slug( $template, $content_type );
			$page_template     = SWPS_Templates::get_template( $template, $content_type ) ?? array();
			$min_words         = (int) ( $page_template['min_words'] ?? 600 );
			$max_words         = (int) ( $page_template['max_words'] ?? 1200 );
			$include_faq       = (bool) ( $page_template['include_faq'] ?? false );
			$include_toc       = false;
			$include_takeaways = false;
		}

		// Owner-supplied source material (fetched once per request).
		$sources                    = SWPS_Source_Material::prepare( (string) ( $options['sources'] ?? '' ) );
		$this->last_sources_report  = $sources['report'];
		$this->last_dropped_sources = $sources['dropped_urls'];

		// Build the system prompt.
		$system_prompt = $this->build_system_prompt( $tone, $style, $content_type );

		// Build linkable posts reference.
		$links_context  = "=== EXISTING PAGES FOR INTERNAL LINKING ===\n";
		$links_context .= "You MUST include natural internal links to these pages where relevant:\n";
		foreach ( $linkable_posts as $link ) {
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
			$include_toc,
			$include_takeaways,
			$takeaways_count,
			$brief,
			$content_type,
			$sources['block']
		);

		// Apply template modifiers. Posts keep the settings default for 'auto';
		// pages always resolve to a page template (page-auto guides structure).
		if ( ! $is_page && 'auto' === $template ) {
			$template = get_option( 'swps_default_template', 'auto' );
		}
		[ $system_prompt, $user_prompt ] = SWPS_Templates::apply( $system_prompt, $user_prompt, $template, $content_type );

		// Apply hook filters.
		$system_prompt = SWPS_Hooks::filter_system_prompt( $system_prompt, $tone, $style );
		$user_prompt   = SWPS_Hooks::filter_user_prompt( $user_prompt, $topic, $site_context );

		// Call AI with 16384 tokens for complete posts with full JSON structure.
		// 8192 was causing truncation for longer posts (1900-3000 words + metadata).
		// Input is kept lean (20 posts, 30 linkable) to stay within timeout.
		$result = $this->api->chat_json( $system_prompt, $user_prompt, 16384 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Build the system prompt for Claude.
	 */
	private function build_system_prompt( string $tone, string $style, string $content_type = SWPS_Templates::TYPE_POST ): string {
		if ( SWPS_Templates::TYPE_PAGE === $content_type ) {
			return $this->build_page_system_prompt( $tone, $style );
		}

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


RESPONSE FORMAT: Respond ONLY with a single, strictly valid RFC 8259 JSON object. No markdown fences, no prose, no explanation.

JSON SYNTAX REQUIREMENTS (NON-NEGOTIABLE — output MUST parse with strict JSON parsers):
- Every string value must be properly quoted with " and any inner " escaped as \".
- Escape sequences (\n, \r, \t, \", \\) are ONLY valid INSIDE string literals. NEVER emit a backslash-letter sequence between tokens, between array elements, between object members, or anywhere outside of a quoted string.
- Use real whitespace (spaces, real newlines) between JSON tokens — never literal \n or \t characters as separators.
- No trailing commas before } or ].
- No comments (// or /* */).
- No control characters (0x00–0x1F) inside strings — encode them as \n, \r, \t, etc.
- Balanced brackets: every { has a matching } and every [ has a matching ].
- Before finishing your response, mentally re-parse it as JSON and confirm it is valid.

Required JSON structure:
{
  "title": "The blog post title (also the SEO title tag)",
  "slug": "url-friendly-slug",
  "meta_description": "Compelling meta description, 147-160 characters",
  "content_html": "Full blog post HTML content with headings, paragraphs, lists, and internal links",
  "excerpt": "2-3 sentence post excerpt",
  "focus_keyword": "2-4 word keyword phrase (e.g. 'Claude Skills', 'WordPress SEO', 'React Hooks')",
  "secondary_keywords": ["keyword2", "keyword3"],
  "suggested_tags": ["tag1", "tag2", "tag3"],
  "suggested_category": "Best fitting category name",
  "internal_links_used": [{"anchor_text": "text", "url": "url"}],
  "external_links": [{"anchor_text": "text", "url": "url", "source": "source name"}],
  "faq_schema": [{"question": "Q?", "answer": "A."}],
  "key_takeaways": ["Concise actionable insight 1", "Insight 2"],
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
- If key takeaways are requested, include a key_takeaways array with concise, actionable bullet points (each under 20 words) summarizing the post's most valuable insights
- Write comprehensive, valuable content — not thin filler
- The focus_keyword MUST be exactly 2-4 words — short and broad (e.g. "Claude Skills", "WordPress SEO"). NEVER use a long phrase or sentence as the focus keyword.
- The focus_keyword MUST appear in: meta_description, the first <p> of content_html, and at least one <h2> heading
- The slug must contain the focus_keyword words
- Every <img> tag in content_html MUST have an alt attribute that contains the focus_keyword
PROMPT;

		return $prompt;
	}

	/**
	 * System prompt for website pages (service, landing, about, location).
	 *
	 * Same JSON contract and syntax rules as posts so the parser and the save
	 * path are shared; the role, rules and empty taxonomy fields differ.
	 *
	 * @param string $tone  Tone setting or brief override.
	 * @param string $style Writing style setting.
	 * @return string
	 */
	private function build_page_system_prompt( string $tone, string $style ): string {
		$prompt = <<<PROMPT
You are an expert website copywriter and SEO specialist. You write evergreen website pages (service pages, landing pages, about pages, location pages) that are:

1. SEO-optimized with proper heading hierarchy (H2, H3 — never H1, as WordPress uses the page title as H1)
2. Written in the voice of the business itself, speaking directly to the visitor
3. Clear about who the page is for, what is offered and what to do next
4. Grounded only in supplied facts — nothing invented

TONE: {$tone}
PROMPT;

		if ( ! empty( $style ) ) {
			$prompt .= "\nWRITING STYLE: {$style}";
		}

		$prompt .= <<<'PROMPT'


RESPONSE FORMAT: Respond ONLY with a single, strictly valid RFC 8259 JSON object. No markdown fences, no prose, no explanation.

JSON SYNTAX REQUIREMENTS (NON-NEGOTIABLE — output MUST parse with strict JSON parsers):
- Every string value must be properly quoted with " and any inner " escaped as \".
- Escape sequences (\n, \r, \t, \", \\) are ONLY valid INSIDE string literals. NEVER emit a backslash-letter sequence between tokens, between array elements, between object members, or anywhere outside of a quoted string.
- Use real whitespace (spaces, real newlines) between JSON tokens — never literal \n or \t characters as separators.
- No trailing commas before } or ].
- No comments (// or /* */).
- No control characters (0x00–0x1F) inside strings — encode them as \n, \r, \t, etc.
- Balanced brackets: every { has a matching } and every [ has a matching ].
- Before finishing your response, mentally re-parse it as JSON and confirm it is valid.

Required JSON structure:
{
  "title": "The page title (also the SEO title tag)",
  "slug": "url-friendly-slug",
  "meta_description": "Compelling meta description, 147-160 characters",
  "content_html": "Full page HTML content with headings, paragraphs, lists, and internal links",
  "excerpt": "1-2 sentence summary of the page",
  "focus_keyword": "2-4 word keyword phrase (e.g. 'WordPress Maintenance Omaha', 'Website Care Plans')",
  "secondary_keywords": ["keyword2", "keyword3"],
  "suggested_tags": [],
  "suggested_category": "",
  "internal_links_used": [{"anchor_text": "text", "url": "url"}],
  "external_links": [{"anchor_text": "text", "url": "url", "source": "source name"}],
  "faq_schema": [{"question": "Q?", "answer": "A."}],
  "key_takeaways": [],
  "estimated_word_count": 900
}

CRITICAL RULES:
- content_html must use proper HTML tags: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <a>, <strong>, <em>
- This is a PAGE, not an article: never use the words "post", "article" or "blog" to describe it; no publication dates, no "in this post", no "today we", no time-bound phrasing that will age
- Internal links must use <a href="URL">anchor text</a> format with REAL URLs from the provided list
- Every internal link must point to a URL from the provided existing pages list
- suggested_tags MUST be an empty array and suggested_category MUST be an empty string (pages have no taxonomy)
- key_takeaways MUST be an empty array
- faq_schema: populate only when a FAQ section is requested; otherwise an empty array
- Never use H1 tags in content_html
- Never invent facts, statistics, testimonials, reviews, prices, credentials, addresses, opening hours or URLs; use only what the brief or source material supplies and write around gaps or leave a short [placeholder]
- Write specific, useful copy — not generic filler
- The focus_keyword MUST be exactly 2-4 words — short and broad. NEVER use a long phrase or sentence as the focus keyword.
- The focus_keyword MUST appear in: meta_description, the first <p> of content_html, and at least one <h2> heading
- The slug must contain the focus_keyword words
- Every <img> tag in content_html MUST have an alt attribute that contains the focus_keyword
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
		bool $include_toc,
		bool $include_takeaways = false,
		int $takeaways_count = 5,
		array $brief = array(),
		string $content_type = SWPS_Templates::TYPE_POST,
		string $sources_block = ''
	): string {
		$is_page     = SWPS_Templates::TYPE_PAGE === $content_type;
		$has_sources = '' !== trim( $sources_block );

		$prompt = $site_context . "\n" . $links_context . "\n";

		// The brief block is empty when no brief was supplied, which keeps the
		// prompt identical to the pre-brief behaviour.
		$brief_block = SWPS_Content_Brief::to_prompt_block( $brief );

		if ( ! empty( $topic ) ) {
			$prompt .= $is_page
				? "TOPIC: Write a website page about: {$topic}\n\n"
				: "TOPIC: Write a blog post about: {$topic}\n\n";
		} elseif ( '' !== $brief_block ) {
			$prompt .= $is_page
				? "TOPIC: Derive the page subject and angle from the CONTENT BRIEF below. Choose a title with good search potential that matches the brief and the site's niche ({$niche}).\n\n"
				: "TOPIC: Derive the topic and angle from the CONTENT BRIEF below. Choose a title with good search potential that matches the brief and the site's niche ({$niche}).\n\n";
		} elseif ( $is_page ) {
			$prompt .= "TOPIC: Based on the site's niche ({$niche}) and existing content above, choose a page the site is missing that:\n";
			$prompt .= "- Describes a service, offer, place or the business itself that visitors would search for\n";
			$prompt .= "- Is relevant to the site's niche and audience\n";
			$prompt .= "- Has good search potential\n";
			$prompt .= "- Can naturally link to existing content\n\n";
		} else {
			$prompt .= "TOPIC: Based on the site's niche ({$niche}) and existing content above, choose a topic that:\n";
			$prompt .= "- Fills a content gap (something the site hasn't covered yet)\n";
			$prompt .= "- Is relevant to the site's niche and audience\n";
			$prompt .= "- Has good search potential\n";
			$prompt .= "- Can naturally link to existing content\n\n";
		}

		// Placed before the keyword rules and REQUIREMENTS so the brief shapes
		// the content while those SEO rules keep the final say.
		$prompt .= $brief_block;

		// Source material follows the brief and precedes the SEO rules for the
		// same reason: it shapes the facts, the rules keep the final say.
		if ( $has_sources ) {
			$prompt .= $sources_block;
		}

		if ( ! empty( $keywords ) ) {
			$prompt .= "TARGET KEYWORDS: {$keywords}\n";
			$prompt .= "KEYWORD PLACEMENT RULES (mandatory for SEO):\n";
			$prompt .= "- Include the primary keyword in the meta_title\n";
			$prompt .= "- Include the primary keyword in the meta_description\n";
			$prompt .= "- Include the primary keyword in the FIRST paragraph of the content\n";
			$prompt .= "- Include the primary keyword in at least ONE H2 heading\n";
			$prompt .= "- Include the primary keyword in the slug\n";
			$prompt .= "- Use keywords naturally throughout the rest of the content — do not stuff\n\n";
		}

		$prompt .= "REQUIREMENTS:\n";
		$prompt .= "- Word count: {$min_words}-{$max_words} words\n";
		$prompt .= "- Include {$min_links}-{$max_links} internal links to existing pages listed above\n";

		if ( $has_sources ) {
			$prompt .= "- Cite the supplied SOURCE MATERIAL URLs as the external links where you use their facts; add no other external links unless essential\n";
		} elseif ( $is_page ) {
			$prompt .= "- External links: only where genuinely useful to the visitor, at most 2\n";
		} else {
			$prompt .= "- Include 2-4 external links to authoritative sources (real websites, documentation, or industry publications)\n";
		}

		if ( $include_toc && ! $is_page ) {
			$prompt .= "- Include a table of contents (HTML list with anchor links to each H2) at the beginning\n";
		}

		if ( $include_faq ) {
			$prompt .= "- Include a FAQ section at the end with 3-5 questions (also populate the faq_schema field for structured data)\n";
		}

		if ( $include_takeaways && ! $is_page ) {
			$prompt .= "- Include {$takeaways_count} key takeaways — concise, actionable bullet points summarizing the post's most important insights (also populate the key_takeaways JSON field)\n";
		}

		$prompt .= "- Use proper heading hierarchy (H2 for main sections, H3 for subsections)\n";
		if ( ! $is_page ) {
			$prompt .= "- Suggest the best existing category for this post, or suggest a new one\n";
		}
		$prompt .= "- Write a compelling meta description (147-160 characters) that includes the primary target keyword\n\n";
		$prompt .= $is_page ? 'Generate the page now. Respond with JSON only.' : 'Generate the blog post now. Respond with JSON only.';

		return $prompt;
	}

	/**
	 * Create a WordPress post from the AI-generated data.
	 *
	 * @param array  $ai_result The parsed JSON from Claude.
	 * @param string $template  The template used.
	 * @param array  $options   Normalized generation options (see normalize_options()).
	 * @return array|WP_Error Post data or error.
	 */
	private function create_wp_post( array $ai_result, string $template = 'auto', array $options = array() ): array|WP_Error {
		$content_type = SWPS_Templates::normalize_type( $options['content_type'] ?? SWPS_Templates::TYPE_POST );
		$is_page      = SWPS_Templates::TYPE_PAGE === $content_type;
		$parent_id    = $is_page ? (int) ( $options['parent_id'] ?? 0 ) : 0;
		// Store the slug that was actually applied (pages resolve 'auto' to 'page-auto').
		if ( $is_page ) {
			$template = SWPS_Templates::resolve_slug( $template, $content_type );
		}

		$post_status = get_option( 'swps_post_status', 'draft' );
		$post_author = (int) get_option( 'swps_post_author', get_current_user_id() );
		$category_id = (int) get_option( 'swps_post_category', 0 );

		// Handle category (posts only; pages have no taxonomy).
		if ( ! $is_page && ! empty( $ai_result['suggested_category'] ) ) {
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

		// Inject key takeaways block if enabled.
		if ( ! $is_page && get_option( 'swps_include_takeaways', false ) && ! empty( $ai_result['key_takeaways'] ) ) {
			$content = $this->inject_takeaways( $content, $ai_result['key_takeaways'] );
		}

		$post_data = array(
			'post_title'    => sanitize_text_field( $ai_result['title'] ),
			'post_content'  => wp_kses_post( $content ),
			'post_excerpt'  => sanitize_text_field( $ai_result['excerpt'] ?? '' ),
			'post_status'   => $post_status,
			'post_author'   => $post_author,
			'post_name'     => sanitize_title( $ai_result['slug'] ),
			'post_category' => ( ! $is_page && $category_id ) ? array( $category_id ) : array(),
			'post_type'     => $content_type,
			'post_parent'   => $parent_id,
		);

		// Apply post data filter.
		$post_data = SWPS_Hooks::filter_post_data( $post_data, $ai_result );

		// Flag that the generator is creating this post (prevents redundant AI calls from hooks).
		if ( ! defined( 'SWPS_GENERATING' ) ) {
			define( 'SWPS_GENERATING', true );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Log the post creation immediately so we have a record even if a
		// later step (featured image, hooks) fails or times out mid-cron.
		$this->log( sprintf( 'Generated %s #%d: "%s"', $is_page ? 'page' : 'post', $post_id, $ai_result['title'] ) );

		// Set tags.
		if ( ! $is_page && ! empty( $ai_result['suggested_tags'] ) ) {
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
		update_post_meta( $post_id, '_swps_meta_description', $meta_desc );
		update_post_meta( $post_id, '_swps_focus_keyword', $focus_keyword );

		// Generate meta title (shortened post title if AI didn't provide one).
		// Shorten at a word boundary — the previous hard mb_substr cut produced
		// mid-word titles ("…for Wor") in <title> and og:title on every
		// generated post whose title ran past 60 chars.
		$meta_title = sanitize_text_field( $ai_result['meta_title'] ?? '' );
		if ( empty( $meta_title ) ) {
			$meta_title = self::shorten_at_word_boundary( (string) $ai_result['title'], 60 );
		}
		update_post_meta( $post_id, '_swps_meta_title', $meta_title );
		$secondary     = $ai_result['secondary_keywords'] ?? array();
		$secondary_str = is_array( $secondary ) ? implode( ', ', array_map( 'sanitize_text_field', $secondary ) ) : sanitize_text_field( $secondary );
		update_post_meta( $post_id, '_swps_secondary_keywords', $secondary_str );
		update_post_meta( $post_id, '_swps_internal_links', $ai_result['internal_links_used'] ?? array() );
		update_post_meta( $post_id, '_swps_external_links', $ai_result['external_links'] ?? array() );
		update_post_meta( $post_id, '_swps_template', $template );
		update_post_meta( $post_id, '_swps_content_type', $content_type );

		// Per-run image plan: the scheduler and background jobs read this
		// before falling back to the global options. Only saved when the
		// request carried one, so cron/bulk posts keep today's behaviour.
		if ( ! empty( $options['image_plan'] ) && is_array( $options['image_plan'] ) ) {
			update_post_meta( $post_id, SWPS_Image_Plan::META_KEY, $options['image_plan'] );
		}

		// Store key takeaways for schema output.
		if ( ! $is_page && ! empty( $ai_result['key_takeaways'] ) ) {
			update_post_meta( $post_id, '_swps_key_takeaways', array_map( 'sanitize_text_field', $ai_result['key_takeaways'] ) );
		}

		// Store FAQ schema as post meta.
		if ( ! empty( $ai_result['faq_schema'] ) ) {
			$schema_data = $this->build_faq_schema_data( $ai_result['title'], $ai_result['faq_schema'] );
			$schema_data = SWPS_Hooks::filter_faq_schema( $schema_data, $ai_result['title'], $ai_result['faq_schema'] );
			update_post_meta( $post_id, '_swps_faq_schema', $schema_data );
		}

		// Track cost per post.
		$cost  = null;
		$model = get_option( 'swps_model', '' );
		if ( ! empty( $ai_result['_usage'] ) ) {
			$input_tokens  = $ai_result['_usage']['input_tokens'] ?? 0;
			$output_tokens = $ai_result['_usage']['output_tokens'] ?? 0;
			$this->cost_tracker->track( $model, $input_tokens, $output_tokens, $post_id );
			$cost = $this->cost_tracker->calculate_cost( $model, $input_tokens, $output_tokens );
		}

		// Fire post_created action.
		SWPS_Hooks::do_post_created( $post_id, $ai_result, $post_data );

		// Read back content score (set by swps_post_created hook).
		$content_score = get_post_meta( $post_id, '_swps_content_score', true );

		return array(
			'post_id'          => $post_id,
			'title'            => $ai_result['title'],
			'edit_url'         => get_edit_post_link( $post_id, 'raw' ),
			'preview_url'      => get_preview_post_link( $post_id ),
			'status'           => $post_status,
			'focus_keyword'    => $focus_keyword,
			'meta_description' => $meta_desc,
			'internal_links'   => $ai_result['internal_links_used'] ?? array(),
			'external_links'   => $ai_result['external_links'] ?? array(),
			'word_count'       => $ai_result['estimated_word_count'] ?? 0,
			'template'         => $template,
			'content_type'     => $content_type,
			'parent_id'        => $parent_id,
			'cost'             => $cost,
			'content_score'    => $content_score ?: null,
		);
	}

	/**
	 * Inject a key takeaways block into content HTML.
	 *
	 * Inserts before the first <h2> tag (after intro paragraphs).
	 *
	 * @param string $content   The post content HTML.
	 * @param array  $takeaways Array of takeaway strings.
	 * @return string Modified content with takeaways injected.
	 */
	private function inject_takeaways( string $content, array $takeaways ): string {
		$takeaways_html = $this->build_takeaways_html( $takeaways );

		// Find the first <h2> tag — insert before it.
		$h2_pos = stripos( $content, '<h2' );
		if ( $h2_pos === false ) {
			// No H2 found — prepend to content.
			return $takeaways_html . $content;
		}

		return substr( $content, 0, $h2_pos ) . $takeaways_html . substr( $content, $h2_pos );
	}

	/**
	 * Build the key takeaways HTML block.
	 *
	 * @param array $takeaways Array of takeaway strings.
	 * @return string HTML markup.
	 */
	private function build_takeaways_html( array $takeaways ): string {
		$items = '';
		foreach ( $takeaways as $takeaway ) {
			$items .= '<li>' . esc_html( $takeaway ) . '</li>';
		}

		return '<div class="swps-key-takeaways">'
			. '<h3>' . esc_html__( 'Key Takeaways', 'stratawp-seo' ) . '</h3>'
			. '<ul>' . $items . '</ul>'
			. '</div>';
	}

	/**
	 * Convert classic HTML content into Gutenberg block markup.
	 *
	 * Wraps each top-level HTML element with the appropriate
	 * Gutenberg block comment delimiters so WordPress treats
	 * the content as native blocks instead of a classic block.
	 *
	 * @param string $html Raw HTML content.
	 * @return string Block-formatted content.
	 */
	private function convert_to_blocks( string $html ): string {
		// Normalize line breaks and trim.
		$html = trim( $html );
		if ( empty( $html ) ) {
			return '';
		}

		$output = '';

		// Split HTML into top-level elements using a regex that captures tags and text between them.
		// Match any top-level HTML tag with its content.
		$pattern = '/(<(h[1-6]|p|ul|ol|blockquote|pre|table|figure|div|nav|hr|img)[^>]*>.*?<\/\2>|<(hr|img)[^>]*\/?>)/is';

		$last_pos = 0;
		if ( preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$tag_html = $match[0];
				$tag_pos  = $match[1];

				// Skip any text between tags (whitespace).
				$last_pos = $tag_pos + strlen( $tag_html );

				// Detect the tag type.
				if ( preg_match( '/^<(h[1-6]|p|ul|ol|blockquote|pre|table|figure|div|nav|hr|img)/i', $tag_html, $tag_match ) ) {
					$tag     = strtolower( $tag_match[1] );
					$output .= $this->wrap_block( $tag, $tag_html );
				} else {
					$output .= $tag_html . "\n\n";
				}
			}
		}

		return $output;
	}

	/**
	 * Wrap a single HTML element with Gutenberg block comment delimiters.
	 *
	 * @param string $tag  The HTML tag name (e.g., 'p', 'h2', 'ul').
	 * @param string $html The full HTML element.
	 * @return string Block-wrapped HTML.
	 */
	private function wrap_block( string $tag, string $html ): string {
		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				$attrs = $level !== 2 ? ' {"level":' . $level . '}' : '';
				return "<!-- wp:heading{$attrs} -->\n{$html}\n<!-- /wp:heading -->\n\n";

			case 'p':
				return "<!-- wp:paragraph -->\n{$html}\n<!-- /wp:paragraph -->\n\n";

			case 'ul':
				return "<!-- wp:list -->\n{$html}\n<!-- /wp:list -->\n\n";

			case 'ol':
				return "<!-- wp:list {\"ordered\":true} -->\n{$html}\n<!-- /wp:list -->\n\n";

			case 'blockquote':
				return "<!-- wp:quote -->\n{$html}\n<!-- /wp:quote -->\n\n";

			case 'pre':
				return "<!-- wp:code -->\n{$html}\n<!-- /wp:code -->\n\n";

			case 'table':
				return "<!-- wp:table -->\n<figure class=\"wp-block-table\">{$html}</figure>\n<!-- /wp:table -->\n\n";

			case 'figure':
				return "<!-- wp:image -->\n{$html}\n<!-- /wp:image -->\n\n";

			case 'hr':
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->\n\n";

			case 'img':
				return "<!-- wp:image -->\n<figure class=\"wp-block-image\">{$html}</figure>\n<!-- /wp:image -->\n\n";

			case 'nav':
			case 'div':
				return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->\n\n";

			default:
				return $html . "\n\n";
		}
	}

	/**
	 * Build FAQ schema data array.
	 *
	 * @param string $title     Post title.
	 * @param array  $faq_items Array of [{question, answer}].
	 * @return array Schema data.
	 */
	private function build_faq_schema_data( string $title, array $faq_items ): array {
		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'name'       => sanitize_text_field( wp_strip_all_tags( $title ) ),
			'mainEntity' => array(),
		);

		foreach ( $faq_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = sanitize_text_field( wp_strip_all_tags( (string) ( $item['question'] ?? '' ) ) );
			$answer   = sanitize_textarea_field( wp_strip_all_tags( (string) ( $item['answer'] ?? '' ) ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$schema['mainEntity'][] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);
		}

		return $schema;
	}

	/**
	 * Append a message to the generation log (shown in Recent Activity).
	 *
	 * Static so background image jobs can surface their outcomes here too.
	 *
	 * @param string $message Message to record.
	 */
	public static function append_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[StrataWP SEO] ' . $message );
		}

		$log   = get_option( 'swps_generation_log', array() );
		$log[] = array(
			'time'    => current_time( 'mysql' ),
			'message' => $message,
		);

		$log = array_slice( $log, -50 );
		update_option( 'swps_generation_log', $log );
	}

	/**
	 * Log a message.
	 *
	 * @param string $message Message to record.
	 */
	private function log( string $message ): void {
		self::append_log( $message );
	}

	/**
	 * Shorten a string to a maximum length, breaking at a word boundary.
	 *
	 * @param string $text       Text to shorten.
	 * @param int    $max_length Maximum length in characters.
	 * @return string
	 */
	public static function shorten_at_word_boundary( string $text, int $max_length ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= $max_length ) {
			return $text;
		}

		$cut  = mb_substr( $text, 0, $max_length );
		$last = mb_strrpos( $cut, ' ' );

		// Only back off to the space when it doesn't gut the title; a title
		// that is one giant unbroken token still gets the hard cut.
		if ( false !== $last && $last > (int) ( $max_length / 2 ) ) {
			$cut = mb_substr( $cut, 0, $last );
		}

		return rtrim( $cut, " \t\n\r\0\x0B,;:—–-" );
	}
}
