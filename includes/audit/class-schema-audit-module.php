<?php
/**
 * Schema Audit Module — validates JSON-LD on sampled live pages.
 *
 * Samples up to 10 URLs (front page + latest posts + one author archive),
 * fetches each via wp_remote_get (same UA/timeout as the site crawler),
 * extracts JSON-LD with SWPS_Schema_Validator::extract_jsonld(), and runs
 * field-presence + orphan-ref + date-consistency checks.
 *
 * Graceful degradation:
 * - If ANY loopback request fails (WP_Error or non-200 for home page), the
 *   module returns status 'warning' with a clear explanation.
 * - Results are cached in a 12-hour transient so run_all() stays fast on
 *   re-runs (schema rarely changes within hours).
 *
 * Read-only — can_auto_fix() returns false.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema-validation audit module.
 */
class SWPS_Schema_Audit_Module extends SWPS_Audit_Module {

	/**
	 * Transient key for cached results.
	 */
	private const CACHE_KEY = 'swps_schema_audit_result';

	/**
	 * Cache lifetime in seconds (12 hours).
	 */
	private const CACHE_TTL = 43200;

	/**
	 * User-agent string for loopback fetches.
	 */
	private const USER_AGENT = 'StrataWP-SEO/schema-audit (+https://stratawpseo.com)';

	// -------------------------------------------------------------------------
	// SWPS_Audit_Module contract
	// -------------------------------------------------------------------------

	/**
	 * Module identifier.
	 */
	public function get_id(): string {
		return 'schema_audit';
	}

	/**
	 * Human-readable label.
	 */
	public function get_label(): string {
		return __( 'Schema Markup', 'stratawp-seo' );
	}

	/**
	 * This module is informational — no auto-fix available.
	 */
	public function can_auto_fix(): bool {
		return false;
	}

	/**
	 * Run the schema audit.
	 *
	 * Returns the cached result if fresh; otherwise samples pages, validates,
	 * stores, and returns the result contract array.
	 *
	 * @return array { score, status, issues, summary }
	 */
	public function run(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = $this->do_run();
		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}

	// -------------------------------------------------------------------------
	// Registration helper
	// -------------------------------------------------------------------------

	/**
	 * Filter callback — append this module to the registered audit modules.
	 *
	 * Hooked to swps_audit_modules in stratawp-seo.php.
	 *
	 * @param SWPS_Audit_Module[] $modules Modules keyed by ID.
	 * @return SWPS_Audit_Module[]
	 */
	public static function register( array $modules ): array {
		$module                       = new self();
		$modules[ $module->get_id() ] = $module;
		return $modules;
	}

	// -------------------------------------------------------------------------
	// Internal implementation
	// -------------------------------------------------------------------------

	/**
	 * Perform the live audit: fetch pages, extract JSON-LD, validate.
	 *
	 * @return array { score, status, issues, summary }
	 */
	private function do_run(): array {
		$rules   = SWPS_Schema_Validator::load_rules_manifest();
		$urls    = $this->collect_sample_urls();
		$issues  = array();
		$checked = 0;

		// Loopback sanity check — fetch the home page first.
		$loopback = $this->fetch_page( home_url( '/' ) );
		if ( null === $loopback ) {
			return $this->loopback_warning();
		}

		$all_urls = array_merge( array( home_url( '/' ) => $loopback ), $urls );

		foreach ( $all_urls as $url => $body ) {
			// $body may already be a fetched string (home page) or null (needs fetch).
			if ( null === $body ) {
				$body = $this->fetch_page( $url );
			}
			if ( null === $body ) {
				// Non-fatal — count as a warning per page.
				$issues[] = array(
					'post_id' => null,
					'message' => sprintf(
						/* translators: %s: page URL */
						__( 'Could not fetch %s for schema validation (loopback request failed).', 'stratawp-seo' ),
						esc_url( $url )
					),
					'fixable' => false,
				);
				continue;
			}

			$blocks = SWPS_Schema_Validator::extract_jsonld( $body );
			++$checked;

			if ( empty( $blocks ) ) {
				$issues[] = array(
					'post_id' => null,
					'message' => sprintf(
						/* translators: %s: page URL */
						__( 'No JSON-LD schema found on %s.', 'stratawp-seo' ),
						esc_url( $url )
					),
					'fixable' => false,
				);
				continue;
			}

			foreach ( $blocks as $block ) {
				$page_issues = $this->validate_block( $block, $rules, $url );
				$issues      = array_merge( $issues, $page_issues );
			}
		}

		// Score: start at 100, deduct 5 per issue, minimum 0.
		$score = max( 0, 100 - ( count( $issues ) * 5 ) );

		return array(
			'score'   => $score,
			'status'  => $this->status_from_score( $score ),
			'issues'  => $issues,
			'summary' => sprintf(
				/* translators: 1: issue count, 2: page count */
				__( '%1$d schema issue(s) found across %2$d sampled page(s).', 'stratawp-seo' ),
				count( $issues ),
				$checked
			),
		);
	}

	/**
	 * Validate a single JSON-LD block (standalone node or @graph wrapper).
	 *
	 * Recursively validates all nodes that have a @type key against the rules
	 * manifest, and runs orphan-ref detection on the whole block.
	 *
	 * @param array  $block  Decoded JSON-LD block.
	 * @param array  $rules  Rules manifest from schema-rules.json.
	 * @param string $url    Source URL for issue messages.
	 * @return array[] Issues in the standard { post_id, message, fixable } shape.
	 */
	private function validate_block( array $block, array $rules, string $url ): array {
		$issues = array();

		// Flatten @graph to a list of nodes, or treat the whole block as one node.
		if ( isset( $block['@graph'] ) && is_array( $block['@graph'] ) ) {
			$nodes = $block['@graph'];

			// Orphan-ref check across the whole graph.
			$orphans = SWPS_Schema_Validator::find_orphan_refs( $block );
			foreach ( $orphans as $orphan ) {
				$issues[] = array(
					'post_id' => null,
					'message' => sprintf(
						/* translators: 1: @id reference, 2: page URL */
						__( 'Schema @graph on %2$s references @id "%1$s" which is never defined.', 'stratawp-seo' ),
						esc_html( $orphan ),
						esc_url( $url )
					),
					'fixable' => false,
				);
			}
		} else {
			$nodes = array( $block );
		}

		// Validate each node.
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
				continue;
			}

			$type = (string) $node['@type'];

			// Required-field validation.
			if ( isset( $rules[ $type ]['required'] ) ) {
				$missing = SWPS_Schema_Validator::validate_node( $node, (array) $rules[ $type ]['required'] );
				foreach ( $missing as $prop ) {
					$issues[] = array(
						'post_id' => null,
						'message' => sprintf(
							/* translators: 1: schema type, 2: required property, 3: page URL */
							__( '%1$s schema on %3$s is missing required property "%2$s".', 'stratawp-seo' ),
							esc_html( $type ),
							esc_html( $prop ),
							esc_url( $url )
						),
						'fixable' => false,
					);
				}
			}

			// Date-consistency check (Articles only).
			if ( in_array( $type, array( 'Article', 'NewsArticle', 'BlogPosting' ), true ) ) {
				$post_id = url_to_postid( $url );
				if ( $post_id > 0 ) {
					$post = get_post( $post_id );
					if ( $post ) {
						$ts      = (int) strtotime( $post->post_modified_gmt . ' UTC' );
						$warning = SWPS_Schema_Validator::check_date_consistency( $node, $ts );
						if ( $warning ) {
							$issues[] = array(
								'post_id' => $post_id,
								'message' => sprintf(
									/* translators: 1: date warning, 2: page URL */
									__( 'Date consistency issue on %2$s: %1$s', 'stratawp-seo' ),
									esc_html( $warning ),
									esc_url( $url )
								),
								'fixable' => false,
							);
						}
					}
				}
			}
		}

		return $issues;
	}

	/**
	 * Collect up to MAX_SAMPLE URLs to audit.
	 *
	 * Returns an associative array of URL => null so the caller knows it still
	 * needs fetching (the home page is pre-fetched separately).
	 *
	 * @return array<string, null>
	 */
	private function collect_sample_urls(): array {
		$urls = array();

		// Latest posts (up to 8 — home page + author archive = 10 total).
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 8,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			if ( $permalink ) {
				$urls[ $permalink ] = null;
			}
			if ( count( $urls ) >= 8 ) {
				break;
			}
		}

		// One author archive — pick the author of the most-recent post.
		if ( ! empty( $posts ) ) {
			$author_url = get_author_posts_url( (int) $posts[0]->post_author );
			if ( $author_url ) {
				$urls[ $author_url ] = null;
			}
		}

		return $urls;
	}

	/**
	 * Fetch a URL via loopback request.
	 *
	 * Returns the body string on success, or null on WP_Error / non-2xx.
	 * Uses the same UA/timeout conventions as SWPS_Site_Crawler::fetch_url().
	 *
	 * @param string $url URL to fetch.
	 * @return string|null Response body or null.
	 */
	private function fetch_page( string $url ): ?string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => self::USER_AGENT,
				'sslverify'  => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Build the 'warning' result returned when loopback is unavailable.
	 *
	 * @return array { score, status, issues, summary }
	 */
	private function loopback_warning(): array {
		$message = __(
			'Schema audit skipped: loopback HTTP request to the site failed. This is common on hosts with basic-auth or WAF restrictions. The audit requires a working loopback connection to fetch and inspect live page output.',
			'stratawp-seo'
		);

		return array(
			'score'   => 100,
			'status'  => 'warning',
			'issues'  => array(
				array(
					'post_id' => null,
					'message' => $message,
					'fixable' => false,
				),
			),
			'summary' => __( 'Loopback request failed — schema audit could not run.', 'stratawp-seo' ),
		);
	}
}
