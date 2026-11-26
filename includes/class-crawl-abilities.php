<?php
/**
 * SWPS_Crawl_Abilities: Site Crawl operations exposed as abilities.
 *
 * Backs the run-site-crawl, get-crawl-issues, apply-crawl-fix and
 * get-canonical-status abilities. Every WordPress or crawler call goes
 * through an overridable dependency so the logic is unit-tested without
 * WordPress; the defaults are the same calls the Site Crawl admin uses.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Crawl ability callbacks.
 */
class SWPS_Crawl_Abilities {

	/** Capability the Site Crawl and Site Audit screens require. */
	public const CAPABILITY = 'manage_options';

	/** Default and maximum page size for get-crawl-issues. */
	public const DEFAULT_PER_PAGE = 50;
	public const MAX_PER_PAGE     = 100;

	/**
	 * Dependencies, keyed by name. See defaults().
	 *
	 * @var array<string, callable>
	 */
	private array $deps;

	/**
	 * Constructor.
	 *
	 * @param array<string, callable> $deps Overrides for defaults() (tests).
	 */
	public function __construct( array $deps = array() ) {
		$this->deps = array_merge( self::defaults(), $deps );
	}

	/**
	 * The real WordPress / crawler dependencies.
	 *
	 * @return array<string, callable>
	 */
	private static function defaults(): array {
		return array(
			'can'        => static fn(): bool => current_user_can( self::CAPABILITY ),
			'state'      => static fn(): array => SWPS_Site_Crawler::get_state(),
			'start'      => static function (): int {
				$run_id = stratawp_seo()->site_crawler->start_run();
				wp_schedule_single_event( time() + 1, SWPS_Site_Crawler::CRON_HOOK );
				return $run_id;
			},
			'run_ids'    => static fn(): array => SWPS_Crawl_Issues::latest_run_ids( 2 ),
			'issues'     => static fn( int $run_id ): array => SWPS_Crawl_Issues::issues_for_run( $run_id ),
			'apply'      => static fn( int $run_id, string $check_id, int $issue_id ): array => stratawp_seo()->fixit_controller->do_apply( $run_id, $check_id, array( $issue_id ) ),
			'home_host'  => static fn(): string => SWPS_Site_Crawler::get_home_host(),
			'permalink'  => static function ( int $post_id ): string {
				$post = get_post( $post_id );
				if ( ! $post || 'publish' !== $post->post_status ) {
					return '';
				}
				return (string) get_permalink( $post );
			},
			'url_to_id'  => static fn( string $url ): int => (int) url_to_postid( $url ),
			'fetch'      => array( self::class, 'fetch_page' ),
			'in_sitemap' => array( self::class, 'url_in_sitemap' ),
		);
	}

	// =========================================================================
	// Ability callbacks
	// =========================================================================

	/**
	 * Queue a Site Crawl. The queue is seeded now; chunks run on WP-Cron.
	 *
	 * @param array $input Validated input (unused).
	 * @return array|WP_Error {status: 'queued', crawl_id: int}
	 */
	public function run_crawl( array $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$denied = $this->deny();
		if ( $denied ) {
			return $denied;
		}

		$state = ( $this->deps['state'] )();
		if ( 'running' === ( $state['status'] ?? '' ) ) {
			return new WP_Error(
				'swps_crawl_running',
				sprintf(
					/* translators: %d: crawl run ID */
					__( 'A Site Crawl is already running (crawl %d). Wait for it to finish, then try again.', 'stratawp-seo' ),
					(int) ( $state['run_id'] ?? 0 )
				)
			);
		}

		return array(
			'status'   => 'queued',
			'crawl_id' => (int) ( $this->deps['start'] )(),
		);
	}

	/**
	 * Latest completed crawl's issues, grouped by check ID, one page at a time.
	 *
	 * Paging runs over the flat issue list (ordered by check ID, then issue
	 * ID) after the optional check filter; each group reports its full count.
	 *
	 * @param array $input Validated input: check (string), page (int), per_page (int).
	 * @return array|WP_Error
	 */
	public function get_issues( array $input ) {
		$denied = $this->deny();
		if ( $denied ) {
			return $denied;
		}

		$page     = (int) ( $input['page'] ?? 1 );
		$per_page = (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE );
		if ( $page < 1 || $per_page < 1 || $per_page > self::MAX_PER_PAGE ) {
			return new WP_Error(
				'swps_invalid_paging',
				sprintf(
					/* translators: %d: maximum page size */
					__( 'page must be 1 or more, and per_page between 1 and %d.', 'stratawp-seo' ),
					self::MAX_PER_PAGE
				)
			);
		}

		$catalog = self::check_catalog();
		$check   = (string) ( $input['check'] ?? '' );
		if ( '' !== $check && ! isset( $catalog[ $check ] ) ) {
			return new WP_Error( 'swps_unknown_check', __( 'Unknown check ID.', 'stratawp-seo' ) );
		}

		$run_id = $this->latest_completed_run();
		if ( 0 === $run_id ) {
			return new WP_Error( 'swps_no_crawl', __( 'No completed Site Crawl yet. Run one with run-site-crawl first.', 'stratawp-seo' ) );
		}

		$groups = ( $this->deps['issues'] )( $run_id );
		if ( '' !== $check ) {
			$groups = array( $check => (array) ( $groups[ $check ] ?? array() ) );
		}
		ksort( $groups );

		$flat = array();
		foreach ( $groups as $type => $rows ) {
			foreach ( (array) $rows as $row ) {
				$flat[] = array( (string) $type, (array) $row );
			}
		}

		$total  = count( $flat );
		$slice  = array_slice( $flat, ( $page - 1 ) * $per_page, $per_page );
		$output = array();
		foreach ( $slice as list( $type, $row ) ) {
			if ( ! isset( $output[ $type ] ) ) {
				$known           = $catalog[ $type ] ?? null;
				$output[ $type ] = array(
					'title'    => $known ? $known->title() : $type,
					'severity' => $known ? $known->severity() : (string) ( $row['severity'] ?? '' ),
					'count'    => count( (array) $groups[ $type ] ),
					'issues'   => array(),
				);
			}
			$output[ $type ]['issues'][] = self::issue_view( $type, $row );
		}

		return array(
			'crawl_id'    => $run_id,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
			'groups'      => $output,
		);
	}

	/**
	 * Apply one fixer to one issue of the latest completed crawl, through
	 * the Fix-It controller so the normal store/undo path is used.
	 *
	 * @param array $input Validated input: issue_id (int), fixer (string).
	 * @return array|WP_Error {issue_id, check, fixer, changed, message}
	 */
	public function apply_fix( array $input ) {
		$denied = $this->deny();
		if ( $denied ) {
			return $denied;
		}

		$issue_id  = (int) ( $input['issue_id'] ?? 0 );
		$fixer_req = (string) ( $input['fixer'] ?? '' );
		if ( $issue_id <= 0 ) {
			return new WP_Error( 'swps_invalid_issue', __( 'issue_id must be a positive integer.', 'stratawp-seo' ) );
		}

		$run_id = $this->latest_completed_run();
		$found  = null;
		if ( $run_id > 0 ) {
			foreach ( ( $this->deps['issues'] )( $run_id ) as $type => $rows ) {
				foreach ( (array) $rows as $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === $issue_id ) {
						$found = array( (string) $type, (array) $row );
						break 2;
					}
				}
			}
		}
		if ( null === $found ) {
			return new WP_Error( 'swps_issue_not_found', __( 'No issue with that ID in the latest completed crawl.', 'stratawp-seo' ) );
		}

		list( $type, $row ) = $found;
		$fixer              = SWPS_Crawl_Fixer_Registry::for_check( $type );
		if ( ! $fixer || self::fixer_id( $fixer ) !== $fixer_req ) {
			return new WP_Error( 'swps_fixer_mismatch', __( 'That fixer does not handle this issue. Use one of the fixers listed for it by get-crawl-issues.', 'stratawp-seo' ) );
		}
		if ( ! empty( $row['detail']['fixed_at'] ) ) {
			return new WP_Error( 'swps_already_fixed', __( 'This issue was already fixed. Run a new crawl to re-check it.', 'stratawp-seo' ) );
		}
		if ( ! $fixer->can_fix( $row ) ) {
			return new WP_Error( 'swps_not_fixable', __( 'This issue cannot be fixed automatically.', 'stratawp-seo' ) );
		}

		$result = ( $this->deps['apply'] )( $run_id, $type, $issue_id );
		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'swps_fix_failed', (string) $result['error'] );
		}

		$fixed   = (array) ( $result['fixed'][0] ?? array() );
		$skipped = (array) ( $result['skipped'][0] ?? array() );
		$changed = ! empty( $fixed );

		return array(
			'issue_id' => $issue_id,
			'check'    => $type,
			'fixer'    => $fixer_req,
			'changed'  => $changed,
			'message'  => $changed ? (string) ( $fixed['message'] ?? '' ) : (string) ( $skipped['reason'] ?? '' ),
		);
	}

	/**
	 * Canonical, robots and sitemap status of one URL or published post, read
	 * from the page the site actually serves.
	 *
	 * @param array $input Validated input: url (string) or post_id (int).
	 * @return array|WP_Error
	 */
	public function canonical_status( array $input ) {
		$denied = $this->deny();
		if ( $denied ) {
			return $denied;
		}

		$url     = trim( (string) ( $input['url'] ?? '' ) );
		$post_id = (int) ( $input['post_id'] ?? 0 );
		if ( ( '' === $url ) === ( 0 === $post_id ) ) {
			return new WP_Error( 'swps_invalid_target', __( 'Pass exactly one of url or post_id.', 'stratawp-seo' ) );
		}

		$home_host = ( $this->deps['home_host'] )();
		if ( $post_id > 0 ) {
			$url = ( $this->deps['permalink'] )( $post_id );
			if ( '' === $url ) {
				return new WP_Error( 'swps_post_not_found', __( 'No published post with that ID.', 'stratawp-seo' ) );
			}
		} elseif ( ! SWPS_Site_Crawler::is_internal( $url, $home_host ) ) {
			return new WP_Error( 'swps_external_url', __( 'url must be an http(s) URL on this site.', 'stratawp-seo' ) );
		} else {
			$post_id = ( $this->deps['url_to_id'] )( $url );
		}

		$fetch = ( $this->deps['fetch'] )( $url );
		$facts = self::page_facts(
			(string) ( $fetch['body'] ?? '' ),
			$url,
			(string) ( $fetch['x_robots'] ?? '' ),
			$home_host
		);

		return array(
			'url'            => $url,
			'post_id'        => $post_id,
			'status'         => (int) ( $fetch['status'] ?? 0 ),
			'redirect_to'    => (string) ( $fetch['location'] ?? '' ),
			'canonical'      => $facts['canonical'],
			'self_canonical' => $facts['self_canonical'],
			'robots'         => $facts['robots'],
			'indexable'      => 200 === (int) ( $fetch['status'] ?? 0 ) && ! in_array( 'noindex', $facts['robots'], true ) && false !== $facts['self_canonical'],
			'in_sitemap'     => (bool) ( $this->deps['in_sitemap'] )( $url, $post_id ),
		);
	}

	// =========================================================================
	// Pure helpers (unit-tested)
	// =========================================================================

	/**
	 * Stable fixer ID from its class: SWPS_Fixer_Sitemap_Exclude => sitemap-exclude.
	 *
	 * @param SWPS_Crawl_Fixer $fixer Fixer.
	 */
	public static function fixer_id( SWPS_Crawl_Fixer $fixer ): string {
		$name = preg_replace( '/^SWPS_Fixer_/', '', get_class( $fixer ) );
		return strtolower( str_replace( '_', '-', (string) $name ) );
	}

	/**
	 * Canonical and robots directives from a page's HTML and X-Robots-Tag.
	 *
	 * @param string $html      Response body ('' when none).
	 * @param string $url       URL that was fetched.
	 * @param string $x_robots  X-Robots-Tag header value ('' when absent).
	 * @param string $home_host Home host.
	 * @return array{canonical: ?string, self_canonical: ?bool, robots: string[]}
	 *               self_canonical is null when the page declares no canonical.
	 */
	public static function page_facts( string $html, string $url, string $x_robots, string $home_host ): array {
		$canonical  = null;
		$directives = array();

		if ( '' !== trim( $html ) ) {
			$canonical = SWPS_Site_Crawler::parse_html( $html, $url )['canonical'];

			$prev = libxml_use_internal_errors( true );
			$dom  = new DOMDocument();
			$dom->loadHTML( $html, LIBXML_NONET );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
				$name = strtolower( trim( $meta->getAttribute( 'name' ) ) );
				if ( 'robots' === $name ) {
					$directives[] = $meta->getAttribute( 'content' );
				}
			}
		}
		if ( '' !== trim( $x_robots ) ) {
			$directives[] = $x_robots;
		}

		$robots = array();
		foreach ( $directives as $list ) {
			foreach ( explode( ',', strtolower( $list ) ) as $directive ) {
				$directive = trim( $directive );
				if ( '' !== $directive && ! in_array( $directive, $robots, true ) ) {
					$robots[] = $directive;
				}
			}
		}

		$self = null;
		if ( null !== $canonical ) {
			$self = SWPS_Site_Crawler::normalize_url( $canonical, $home_host ) === SWPS_Site_Crawler::normalize_url( $url, $home_host );
		}

		return array(
			'canonical'      => $canonical,
			'self_canonical' => $self,
			'robots'         => $robots,
		);
	}

	/**
	 * One issue row as the ability returns it.
	 *
	 * @param string $type Check ID.
	 * @param array  $row  Decoded issue row (SWPS_Crawl_Issues::issues_for_run()).
	 */
	public static function issue_view( string $type, array $row ): array {
		$detail  = (array) ( $row['detail'] ?? array() );
		$post_id = (int) ( $row['post_id'] ?? 0 );
		if ( $post_id <= 0 && 'post' === ( $row['object_type'] ?? '' ) ) {
			$post_id = (int) ( $row['object_id'] ?? 0 );
		}

		$fixers = array();
		$fixer  = SWPS_Crawl_Fixer_Registry::for_check( $type );
		if ( $fixer && empty( $detail['fixed_at'] ) && $fixer->can_fix( $row ) ) {
			$fixers[] = self::fixer_id( $fixer );
		}

		return array(
			'issue_id' => (int) ( $row['id'] ?? 0 ),
			'url'      => (string) ( $row['url'] ?? '' ),
			'post_id'  => $post_id,
			'fixed'    => ! empty( $detail['fixed_at'] ),
			'fixers'   => $fixers,
			'detail'   => $detail,
		);
	}

	// =========================================================================
	// Internals
	// =========================================================================

	/**
	 * WP_Error when the current user may not use Site Crawl, else null.
	 */
	private function deny(): ?WP_Error {
		if ( ( $this->deps['can'] )() ) {
			return null;
		}
		return new WP_Error( 'swps_ability_forbidden', __( 'You do not have permission to use Site Crawl.', 'stratawp-seo' ) );
	}

	/**
	 * Newest completed run ID (0 when none), skipping a run still in progress,
	 * as the Site Audit screen does.
	 */
	private function latest_completed_run(): int {
		$run_ids = array_values( ( $this->deps['run_ids'] )() );
		$state   = ( $this->deps['state'] )();
		$offset  = 0;
		if ( 'running' === ( $state['status'] ?? '' ) && (int) ( $run_ids[0] ?? 0 ) === (int) ( $state['run_id'] ?? 0 ) ) {
			$offset = 1;
		}
		return (int) ( $run_ids[ $offset ] ?? 0 );
	}

	/**
	 * Check ID => check instance, including excluded checks.
	 *
	 * @return array<string, SWPS_Crawl_Check>
	 */
	private static function check_catalog(): array {
		$catalog = array();
		foreach ( SWPS_Crawl_Check_Registry::all( array() ) as $check ) {
			$catalog[ $check->id() ] = $check;
		}
		return $catalog;
	}

	/**
	 * GET a page on this site without following redirects.
	 *
	 * @param string $url Internal URL.
	 * @return array{status: int, body: string, x_robots: string, location: string}
	 */
	public static function fetch_page( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'user-agent'  => apply_filters( 'swps_crawl_user_agent', 'StrataWP-SEO-Audit/' . SWPS_VERSION . '; +' . home_url( '/' ) ),
				'sslverify'   => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status'   => 0,
				'body'     => '',
				'x_robots' => '',
				'location' => '',
			);
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$x_robots = wp_remote_retrieve_header( $response, 'x-robots-tag' );
		$location = wp_remote_retrieve_header( $response, 'location' );

		return array(
			'status'   => $status,
			'body'     => $status >= 200 && $status < 300 ? (string) wp_remote_retrieve_body( $response ) : '',
			'x_robots' => is_array( $x_robots ) ? implode( ', ', $x_robots ) : (string) $x_robots,
			'location' => $status >= 300 && $status < 400 && is_string( $location ) ? SWPS_Site_Crawler::resolve_url( $location, $url ) : '',
		);
	}

	/**
	 * Whether the plugin's sitemap lists this URL, using the sitemap's own
	 * eligibility rules for posts, terms, authors and the home page.
	 *
	 * @param string $url     URL.
	 * @param int    $post_id Post ID resolved from the URL (0 when none).
	 */
	public static function url_in_sitemap( string $url, int $post_id ): bool {
		$home_host = SWPS_Site_Crawler::get_home_host();
		if ( SWPS_Site_Crawler::normalize_url( $url, $home_host ) === SWPS_Site_Crawler::normalize_url( home_url( '/' ), $home_host ) ) {
			return true;
		}
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			return $post && SWPS_Sitemap_Manager::is_post_indexable( $post );
		}

		$target = SWPS_Crawl_Target::resolve( $url );
		if ( 'term' === $target['object_type'] ) {
			$term = get_term( $target['object_id'] );
			return $term instanceof WP_Term && $term->count > 0 && SWPS_Sitemap_Manager::is_term_indexable( (int) $term->term_id, $term->taxonomy );
		}
		if ( 'user' === $target['object_type'] ) {
			return ! SWPS_Sitemap_Manager::is_author_sitemap_hidden();
		}
		return false;
	}
}
