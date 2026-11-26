<?php
/**
 * Trait SWPS_Crawl_Ability_Defs: Site Crawl ability definitions for SWPS_Abilities.
 *
 * Kept apart from SWPS_Ability_Defs so neither file grows past 500 lines.
 * The callbacks delegate to SWPS_Crawl_Abilities.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Crawl ability definitions and callbacks.
 */
trait SWPS_Crawl_Ability_Defs {

	/**
	 * Lazily built Site Crawl ability backend.
	 *
	 * @var SWPS_Crawl_Abilities|null
	 */
	private ?SWPS_Crawl_Abilities $crawl_abilities = null;

	/**
	 * Site Crawl ability definitions.
	 *
	 * @return array[]
	 */
	private function build_crawl_defs(): array {
		return array(

			// ------------------------------------------------------------------
			// run-site-crawl (WRITE, async)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/run-site-crawl',
				'label'         => __( 'Run Site Crawl', 'stratawp-seo' ),
				'description'   => __( 'Queue a Site Crawl. Returns "queued" and the crawl ID immediately; the crawl runs in the background. Refuses while another crawl is running. Read the results with get-crawl-issues once it finishes.', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string' ),
						'crawl_id' => array( 'type' => 'integer' ),
					),
				),
				'callback'      => array( $this, 'cb_run_site_crawl' ),
			),

			// ------------------------------------------------------------------
			// get-crawl-issues (READ)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/get-crawl-issues',
				'label'         => __( 'Get Crawl Issues', 'stratawp-seo' ),
				'description'   => __( 'Return the latest completed Site Crawl issues grouped by check ID, a page at a time. Each issue lists its URL, post ID when known, and the fixers that can fix it (see apply-crawl-fix).', 'stratawp-seo' ),
				'write'         => false,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'check'    => array(
							'type'        => 'string',
							'description' => __( 'Only this check ID, e.g. missing_meta_description.', 'stratawp-seo' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => __( 'Page number, from 1 (default 1).', 'stratawp-seo' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Issues per page, 1 to 100 (default 50).', 'stratawp-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'crawl_id'    => array( 'type' => 'integer' ),
						'total'       => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
						'per_page'    => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'groups'      => array( 'type' => 'object' ),
					),
				),
				'callback'      => array( $this, 'cb_get_crawl_issues' ),
			),

			// ------------------------------------------------------------------
			// apply-crawl-fix (WRITE)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/apply-crawl-fix',
				'label'         => __( 'Apply Crawl Fix', 'stratawp-seo' ),
				'description'   => __( 'Apply one fixer to one issue from the latest completed Site Crawl, the same way the Fix-It button does, so it can be undone from the Site Audit screen. Returns what changed.', 'stratawp-seo' ),
				'write'         => true,
				'input_schema'  => array(
					'type'       => 'object',
					'required'   => array( 'issue_id', 'fixer' ),
					'properties' => array(
						'issue_id' => array(
							'type'        => 'integer',
							'description' => __( 'Issue ID from get-crawl-issues.', 'stratawp-seo' ),
						),
						'fixer'    => array(
							'type'        => 'string',
							'description' => __( 'Fixer ID listed for that issue, e.g. sitemap-exclude.', 'stratawp-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'issue_id' => array( 'type' => 'integer' ),
						'check'    => array( 'type' => 'string' ),
						'fixer'    => array( 'type' => 'string' ),
						'changed'  => array( 'type' => 'boolean' ),
						'message'  => array( 'type' => 'string' ),
					),
				),
				'callback'      => array( $this, 'cb_apply_crawl_fix' ),
			),

			// ------------------------------------------------------------------
			// get-canonical-status (READ)
			// ------------------------------------------------------------------
			array(
				'name'          => 'stratawp-seo/get-canonical-status',
				'label'         => __( 'Get Canonical Status', 'stratawp-seo' ),
				'description'   => __( 'For a URL on this site or a published post ID, fetch the page and return its canonical URL, whether it is self-canonical, its robots directives, and whether the sitemap lists it.', 'stratawp-seo' ),
				'write'         => false,
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'url'     => array(
							'type'        => 'string',
							'description' => __( 'Absolute URL on this site. Pass this or post_id.', 'stratawp-seo' ),
						),
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Published post ID. Pass this or url.', 'stratawp-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'            => array( 'type' => 'string' ),
						'post_id'        => array( 'type' => 'integer' ),
						'status'         => array( 'type' => 'integer' ),
						'redirect_to'    => array( 'type' => 'string' ),
						'canonical'      => array( 'type' => array( 'string', 'null' ) ),
						'self_canonical' => array( 'type' => array( 'boolean', 'null' ) ),
						'robots'         => array( 'type' => 'array' ),
						'indexable'      => array( 'type' => 'boolean' ),
						'in_sitemap'     => array( 'type' => 'boolean' ),
					),
				),
				'callback'      => array( $this, 'cb_get_canonical_status' ),
			),
		);
	}

	/**
	 * The Site Crawl ability backend.
	 */
	private function crawl_abilities(): SWPS_Crawl_Abilities {
		if ( null === $this->crawl_abilities ) {
			$this->crawl_abilities = new SWPS_Crawl_Abilities();
		}
		return $this->crawl_abilities;
	}

	/**
	 * Callback: run-site-crawl.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function cb_run_site_crawl( array $input ) {
		return $this->crawl_abilities()->run_crawl( $input );
	}

	/**
	 * Callback: get-crawl-issues.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function cb_get_crawl_issues( array $input ) {
		return $this->crawl_abilities()->get_issues( $input );
	}

	/**
	 * Callback: apply-crawl-fix.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function cb_apply_crawl_fix( array $input ) {
		return $this->crawl_abilities()->apply_fix( $input );
	}

	/**
	 * Callback: get-canonical-status.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public function cb_get_canonical_status( array $input ) {
		return $this->crawl_abilities()->canonical_status( $input );
	}
}
