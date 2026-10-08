<?php
/**
 * Definitions for every editor check. One list feeds PHP, the REST layer and
 * (via for_js()) the sidebar, so a check is added in exactly one place.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Check_Registry {

	/**
	 * Same weights as SWPS_Content_Scorer::DEFAULT_WEIGHTS (a test pins this)
	 * so scores on existing posts do not swing.
	 */
	public const DIMENSION_WEIGHTS = array(
		'keyword_density'   => 15,
		'readability'       => 20,
		'heading_structure' => 15,
		'meta_quality'      => 15,
		'internal_links'    => 10,
		'content_depth'     => 15,
		'engagement'        => 10,
	);

	/**
	 * @return array<string,mixed>
	 */
	private static function def( string $id, string $scope, string $dimension, string $fix, string $label, string $help ): array {
		return array(
			'id'        => $id,
			'scope'     => $scope,
			'dimension' => $dimension,
			'group'     => 'readability' === $dimension ? 'readability' : 'seo',
			'fix'       => $fix,
			'tier'      => 'instant',
			'label'     => $label,
			'help'      => $help,
		);
	}

	/**
	 * Global checks first, then keyword checks. Order is part of the contract:
	 * the scorers iterate in this order so PHP and JS sum floats identically.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		return array(
			self::def( 'title_length', 'global', 'meta_quality', 'rule', __( 'Title length', 'stratawp-seo' ), __( 'Keep the title long enough to be useful and short enough to avoid being cut off in results.', 'stratawp-seo' ) ),
			self::def( 'description_length', 'global', 'meta_quality', 'rule', __( 'Meta description length', 'stratawp-seo' ), __( 'Write a description that fills the snippet without being truncated.', 'stratawp-seo' ) ),
			self::def( 'content_length', 'global', 'content_depth', 'none', __( 'Content length', 'stratawp-seo' ), __( 'Cover the topic in enough depth for this type of page.', 'stratawp-seo' ) ),
			self::def( 'internal_links', 'global', 'internal_links', 'none', __( 'Internal links', 'stratawp-seo' ), __( 'Link to at least one related page on your site.', 'stratawp-seo' ) ),
			self::def( 'external_links', 'global', 'internal_links', 'none', __( 'External links', 'stratawp-seo' ), __( 'Link out to at least one trustworthy source.', 'stratawp-seo' ) ),
			self::def( 'subheading_distribution', 'global', 'heading_structure', 'none', __( 'Subheading distribution', 'stratawp-seo' ), __( 'Break long stretches of text with subheadings.', 'stratawp-seo' ) ),
			self::def( 'image_alt_missing', 'global', 'engagement', 'none', __( 'Image alt text', 'stratawp-seo' ), __( 'Give every image descriptive alt text.', 'stratawp-seo' ) ),
			self::def( 'sentence_length', 'global', 'readability', 'none', __( 'Sentence length', 'stratawp-seo' ), __( 'Keep most sentences under 20 words.', 'stratawp-seo' ) ),
			self::def( 'paragraph_length', 'global', 'readability', 'none', __( 'Paragraph length', 'stratawp-seo' ), __( 'Split paragraphs longer than 150 words.', 'stratawp-seo' ) ),
			self::def( 'transition_words', 'global', 'readability', 'none', __( 'Transition words', 'stratawp-seo' ), __( 'Use connecting words so the text flows.', 'stratawp-seo' ) ),
			self::def( 'passive_voice', 'global', 'readability', 'none', __( 'Passive voice', 'stratawp-seo' ), __( 'Prefer active sentences.', 'stratawp-seo' ) ),
			self::def( 'consecutive_starters', 'global', 'readability', 'none', __( 'Sentence beginnings', 'stratawp-seo' ), __( 'Avoid starting three sentences in a row with the same word.', 'stratawp-seo' ) ),
			self::def( 'kw_in_title', 'keyword', 'meta_quality', 'ai', __( 'Keyword in the title', 'stratawp-seo' ), __( 'Use the keyword in the page title.', 'stratawp-seo' ) ),
			self::def( 'kw_in_description', 'keyword', 'meta_quality', 'ai', __( 'Keyword in the meta description', 'stratawp-seo' ), __( 'Use the keyword in the meta description.', 'stratawp-seo' ) ),
			self::def( 'kw_in_slug', 'keyword', 'meta_quality', 'rule', __( 'Keyword in the URL slug', 'stratawp-seo' ), __( 'Use the keyword in the URL.', 'stratawp-seo' ) ),
			self::def( 'kw_in_intro', 'keyword', 'keyword_density', 'ai', __( 'Keyword in the introduction', 'stratawp-seo' ), __( 'Mention the keyword in the first paragraph.', 'stratawp-seo' ) ),
			self::def( 'kw_in_subheading', 'keyword', 'heading_structure', 'none', __( 'Keyword in a subheading', 'stratawp-seo' ), __( 'Use the keyword in at least one H2 or H3.', 'stratawp-seo' ) ),
			self::def( 'kw_in_image_alt', 'keyword', 'engagement', 'none', __( 'Keyword in image alt text', 'stratawp-seo' ), __( 'Describe at least one image using the keyword.', 'stratawp-seo' ) ),
			self::def( 'kw_density', 'keyword', 'keyword_density', 'none', __( 'Keyword density', 'stratawp-seo' ), __( 'Use the keyword naturally, about 0.5 to 3 percent of the words.', 'stratawp-seo' ) ),
			self::def( 'kw_title_position', 'keyword', 'keyword_density', 'none', __( 'Keyword near the start of the title', 'stratawp-seo' ), __( 'Place the keyword in the first half of the title.', 'stratawp-seo' ) ),
			self::def( 'kw_in_conclusion', 'keyword', 'keyword_density', 'ai', __( 'Keyword in the conclusion', 'stratawp-seo' ), __( 'Mention the keyword in the last paragraph.', 'stratawp-seo' ) ),
			self::def( 'kw_unique', 'keyword', 'meta_quality', 'none', __( 'Keyword not used on another post', 'stratawp-seo' ), __( 'Using one keyword on several pages makes them compete.', 'stratawp-seo' ) ),
		);
	}

	/**
	 * Data the sidebar needs to label and score results.
	 *
	 * @return array{checks:array<int,array<string,mixed>>,weights:array<string,int>}
	 */
	public static function for_js(): array {
		return array(
			'checks'  => self::all(),
			'weights' => self::DIMENSION_WEIGHTS,
		);
	}
}
