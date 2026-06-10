<?php
/**
 * Site Crawler — pure static parsing helpers and crawl state machine.
 *
 * Pure statics (normalize_url, is_internal, parse_html, classify) have no
 * WordPress dependency and are covered by unit tests in tests/unit/SiteCrawlerTest.php.
 *
 * The state machine (start_run, process_chunk) uses WP APIs and is exercised
 * via smoke tests only (Dispatch 2, commit f).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Crawler — HTML parsing helpers and chunked crawl state machine.
 */
class SWPS_Site_Crawler {

	// -------------------------------------------------------------------------
	// Option / cron constants
	// -------------------------------------------------------------------------

	/** Option key storing the serialised crawl-run state array. */
	private const OPT_STATE = 'swps_crawl_state';

	/** Option key storing the last completed run's summary. */
	public const OPT_LAST_SUMMARY = 'swps_crawl_last_summary';

	/** Cron hook for optional weekly re-crawl. */
	public const CRON_HOOK = 'swps_site_crawl_chunk';

	/** Cron hook that kicks off a fresh weekly run (fires once per week). */
	public const WEEKLY_HOOK = 'swps_site_crawl_weekly';

	// -------------------------------------------------------------------------
	// Hard limits (non-negotiable per plan)
	// -------------------------------------------------------------------------

	/** Default cap: internal URLs per run. */
	public const DEFAULT_INTERNAL_CAP = 500;

	/** Default cap: external URLs checked per run. */
	public const DEFAULT_EXTERNAL_CAP = 200;

	/** Default politeness delay in microseconds (500 ms). */
	public const DEFAULT_DELAY_US = 500000;

	/** Maximum hop depth for manual redirect-chain following. */
	public const MAX_HOPS = 5;

	/** Maximum crawl depth from seed. */
	public const MAX_DEPTH = 5;

	/** Query-string URL cap per unique path (crawl-trap guard). */
	private const QS_CAP_PER_PATH = 2;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Wire up cron hooks.
	 *
	 * The weekly trigger fires once a week (when enabled) and starts a fresh
	 * crawl run; CRON_HOOK processes individual chunks until the queue drains.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'cron_process_chunk' ) );
		add_action( self::WEEKLY_HOOK, array( $this, 'cron_weekly_trigger' ) );
	}

	/**
	 * Schedule the optional weekly re-crawl cron event.
	 *
	 * No-op when the setting is disabled (default).
	 */
	public static function schedule_weekly_cron(): void {
		if ( ! get_option( 'swps_crawl_weekly_enabled', 0 ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::WEEKLY_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', self::WEEKLY_HOOK );
		}
	}

	/**
	 * Unschedule the weekly re-crawl cron event.
	 */
	public static function unschedule_weekly_cron(): void {
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
	}

	/**
	 * Return the current crawl state array (empty array when no run exists).
	 *
	 * @return array Crawl state as persisted by start_run()/process_chunk().
	 */
	public static function get_state(): array {
		$state = get_option( self::OPT_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	// =========================================================================
	// PURE STATIC HELPERS (unit-tested, no WP dependency)
	// =========================================================================

	/**
	 * Normalize a URL for the visited-set.
	 *
	 * Returns a scheme-relative string (//host/path[?sorted-query]) with the
	 * fragment stripped, trailing slash removed from non-root paths, and query
	 * args sorted alphabetically.
	 *
	 * Returns '' when the URL is invalid or uses an unsupported scheme.
	 *
	 * @param string $url       Absolute URL to normalise.
	 * @param string $home_host Registrable hostname of the home URL (unused here;
	 *                          kept for API symmetry with is_internal).
	 * @return string Normalised scheme-relative string, or '' on failure.
	 */
	public static function normalize_url( string $url, string $home_host ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] ?? '' );
		if ( '' === $host ) {
			return '';
		}

		$path = $parts['path'] ?? '/';
		if ( '' === $path ) {
			$path = '/';
		}

		// Strip trailing slash from non-root paths.
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		// Sort query args.
		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $qargs );
			ksort( $qargs );
			$query = '?' . http_build_query( $qargs );
		}

		return '//' . $host . $path . $query;
	}

	/**
	 * Determine whether a URL is internal (same registrable host as home).
	 *
	 * "Internal" means the host part of $url, after stripping a leading "www.",
	 * equals $home_host. Only http/https schemes qualify.
	 *
	 * @param string $url       Absolute URL to test.
	 * @param string $home_host Registrable home hostname, lower-case, no www.
	 * @return bool True when the URL should be treated as internal.
	 */
	public static function is_internal( string $url, string $home_host ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] ?? '' );
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return strtolower( $home_host ) === $host;
	}

	/**
	 * Extract candidate links, assets, and meta signals from an HTML string.
	 *
	 * Uses DOMDocument with libxml_use_internal_errors so malformed HTML is
	 * tolerated.
	 *
	 * @param string $html     Full HTML source of the page.
	 * @param string $base_url Absolute URL used to resolve relative hrefs.
	 * @return array{links:string[],images:string[],canonical:?string,h1_count:int,has_noindex:bool,mixed:string[]}
	 */
	public static function parse_html( string $html, string $base_url ): array {
		$result = array(
			'links'       => array(),
			'images'      => array(),
			'canonical'   => null,
			'h1_count'    => 0,
			'has_noindex' => false,
			'mixed'       => array(),
		);

		$is_https = str_starts_with( strtolower( $base_url ), 'https://' );

		$prev = libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		@$dom->loadHTML( $html, LIBXML_NONET ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$base_parts = wp_parse_url( $base_url );

		/**
		 * Resolve a potentially relative href/src to an absolute URL.
		 *
		 * @param string $href Raw value from the attribute.
		 * @return string Absolute URL, or '' when non-resolvable.
		 */
		$resolve = static function ( string $href ) use ( $base_parts ): string {
			$href = trim( $href );
			if ( '' === $href || str_starts_with( $href, '#' ) ) {
				return '';
			}

			// Already absolute.
			if ( preg_match( '/^https?:\/\//i', $href ) ) {
				return $href;
			}

			// Protocol-relative.
			if ( str_starts_with( $href, '//' ) ) {
				return ( $base_parts['scheme'] ?? 'https' ) . ':' . $href;
			}

			$scheme = $base_parts['scheme'] ?? 'https';
			$host   = $base_parts['host'] ?? '';

			// Root-relative.
			if ( str_starts_with( $href, '/' ) ) {
				return $scheme . '://' . $host . $href;
			}

			// Relative: resolve against the base path directory.
			$base_dir = isset( $base_parts['path'] )
				? rtrim( dirname( $base_parts['path'] ), '/' )
				: '';

			// Walk ../ segments.
			$path     = $base_dir . '/' . $href;
			$segments = explode( '/', $path );
			$resolved = array();
			foreach ( $segments as $seg ) {
				if ( '..' === $seg ) {
					array_pop( $resolved );
				} elseif ( '.' !== $seg ) {
					$resolved[] = $seg;
				}
			}

			return $scheme . '://' . $host . implode( '/', $resolved );
		};

		// <a href>.
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			// Loop variable is a DOMElement anchor node.
			$abs = $resolve( $a->getAttribute( 'href' ) );
			if ( '' !== $abs ) {
				$result['links'][] = $abs;
			}
		}

		// <img src> — also checked for mixed content.
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			// Loop variable is a DOMElement image node.
			$abs = $resolve( $img->getAttribute( 'src' ) );
			if ( '' !== $abs ) {
				$result['images'][] = $abs;
				if ( $is_https && str_starts_with( strtolower( $abs ), 'http://' ) ) {
					$result['mixed'][] = $abs;
				}
			}
		}

		// <script src> — mixed-content only.
		foreach ( $dom->getElementsByTagName( 'script' ) as $el ) {
			// Loop variable is a DOMElement script node.
			$src = $el->getAttribute( 'src' );
			if ( '' === $src ) {
				continue;
			}
			$abs = $resolve( $src );
			if ( '' !== $abs && $is_https && str_starts_with( strtolower( $abs ), 'http://' ) ) {
				$result['mixed'][] = $abs;
			}
		}

		// <link href> — canonical + mixed-content.
		foreach ( $dom->getElementsByTagName( 'link' ) as $el ) {
			// Loop variable is a DOMElement link node.
			$rel = strtolower( $el->getAttribute( 'rel' ) );
			$abs = $resolve( $el->getAttribute( 'href' ) );

			if ( 'canonical' === $rel && '' !== $abs ) {
				$result['canonical'] = $abs;
				continue;
			}

			if ( '' !== $abs && $is_https && str_starts_with( strtolower( $abs ), 'http://' ) ) {
				$result['mixed'][] = $abs;
			}
		}

		// <meta name="robots">.
		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			// Loop variable is a DOMElement meta node.
			if ( 'robots' !== strtolower( $meta->getAttribute( 'name' ) ) ) {
				continue;
			}
			if ( str_contains( strtolower( $meta->getAttribute( 'content' ) ), 'noindex' ) ) {
				$result['has_noindex'] = true;
			}
		}

		// <h1> count.
		$result['h1_count'] = $dom->getElementsByTagName( 'h1' )->length;

		return $result;
	}

	/**
	 * Classify a fetched URL result into issue rows.
	 *
	 * @param array  $fetch     Fetch result: keys url (string), status (int), found_on (string), hops (array).
	 * @param array  $page      Result of parse_html() on the response body.
	 * @param string $home_host Registrable home hostname, lower-case, no www.
	 * @return array[] Issue rows; each has keys: type, url, detail (array), severity.
	 */
	public static function classify( array $fetch, array $page, string $home_host ): array {
		$issues   = array();
		$url      = $fetch['url'] ?? '';
		$status   = (int) ( $fetch['status'] ?? 0 );
		$found_on = $fetch['found_on'] ?? '';
		$hops     = $fetch['hops'] ?? array();

		// Broken link: 4xx / 5xx / connection error.
		if ( $status >= 400 || 0 === $status ) {
			$issues[] = array(
				'type'     => 'broken_link',
				'url'      => $url,
				'detail'   => array(
					'status'   => $status,
					'found_on' => $found_on,
				),
				'severity' => 'error',
			);
			// No further checks make sense for broken resources.
			return $issues;
		}

		// Redirect chain: 2+ hops to reach the final destination.
		if ( count( $hops ) >= 2 ) {
			$issues[] = array(
				'type'     => 'redirect_chain',
				'url'      => $url,
				'detail'   => array(
					'hops'     => $hops,
					'found_on' => $found_on,
				),
				'severity' => 'warning',
			);
		}

		// Canonical mismatch: page declares a canonical that differs from the
		// fetched URL (after normalisation).
		$canonical = $page['canonical'] ?? null;
		if ( null !== $canonical ) {
			$norm_fetched = self::normalize_url( $url, $home_host );
			$norm_canon   = self::normalize_url( $canonical, $home_host );
			if ( '' !== $norm_fetched && '' !== $norm_canon && $norm_fetched !== $norm_canon ) {
				$issues[] = array(
					'type'     => 'canonical_mismatch',
					'url'      => $url,
					'detail'   => array(
						'canonical' => $canonical,
						'found_on'  => $found_on,
					),
					'severity' => 'warning',
				);
			}
		}

		// Missing / duplicate H1.
		$h1_count = (int) ( $page['h1_count'] ?? 0 );
		if ( 0 === $h1_count ) {
			$issues[] = array(
				'type'     => 'missing_h1',
				'url'      => $url,
				'detail'   => array( 'found_on' => $found_on ),
				'severity' => 'warning',
			);
		} elseif ( $h1_count > 1 ) {
			$issues[] = array(
				'type'     => 'duplicate_h1',
				'url'      => $url,
				'detail'   => array(
					'h1_count' => $h1_count,
					'found_on' => $found_on,
				),
				'severity' => 'warning',
			);
		}

		// Mixed content.
		foreach ( ( $page['mixed'] ?? array() ) as $asset_url ) {
			$issues[] = array(
				'type'     => 'mixed_content',
				'url'      => $url,
				'detail'   => array(
					'asset'    => $asset_url,
					'found_on' => $found_on,
				),
				'severity' => 'warning',
			);
		}

		return $issues;
	}

	// =========================================================================
	// STATE MACHINE
	// =========================================================================

	/**
	 * Start a new crawl run.
	 *
	 * Seeds the queue from sitemap post URLs (capped to swps_crawl_internal_cap)
	 * and persists the run state to a WP option.
	 *
	 * @param array $opts Run options: internal_cap (int), external_cap (int), delay_us (int), ignore_hosts (string).
	 * @return int Run ID (Unix timestamp used as a simple integer ID).
	 */
	public function start_run( array $opts = array() ): int {
		global $wpdb;

		$run_id       = time();
		$internal_cap = (int) ( $opts['internal_cap'] ?? get_option( 'swps_crawl_internal_cap', self::DEFAULT_INTERNAL_CAP ) );
		$external_cap = (int) ( $opts['external_cap'] ?? get_option( 'swps_crawl_external_cap', self::DEFAULT_EXTERNAL_CAP ) );
		$delay_us     = (int) ( $opts['delay_us'] ?? get_option( 'swps_crawl_delay_us', self::DEFAULT_DELAY_US ) );
		$ignore_hosts = (string) ( $opts['ignore_hosts'] ?? get_option( 'swps_crawl_ignore_hosts', '' ) );

		$state = array(
			'run_id'           => $run_id,
			'status'           => 'running',
			'internal_cap'     => $internal_cap,
			'external_cap'     => $external_cap,
			'delay_us'         => $delay_us,
			'ignore_hosts'     => $ignore_hosts,
			'internal_queued'  => 0,
			'external_links'   => array(),
			'external_checked' => 0,
			'started_at'       => gmdate( 'Y-m-d H:i:s' ),
			'qs_path_counts'   => array(),
		);
		update_option( self::OPT_STATE, $state, false );

		// Seed queue from sitemap post URLs.
		$seeds     = $this->get_seed_urls();
		$table     = $wpdb->prefix . SWPS_Crawl_Issues::TABLE_QUEUE;
		$home_host = self::get_home_host();

		foreach ( $seeds as $url ) {
			if ( $state['internal_queued'] >= $internal_cap ) {
				break;
			}

			$norm = self::normalize_url( $url, $home_host );
			if ( '' === $norm ) {
				continue;
			}

			if ( ! $this->is_qs_allowed( $url, $state ) ) {
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'run_id'   => $run_id,
					'url_hash' => hash( 'sha256', $norm ),
					'url'      => $url,
					'depth'    => 0,
					'status'   => 'pending',
					'found_on' => '',
				),
				array( '%d', '%s', '%s', '%d', '%s', '%s' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! empty( $wpdb->insert_id ) ) {
				++$state['internal_queued'];
				$this->update_qs_counts( $url, $state );
			}
		}

		update_option( self::OPT_STATE, $state, false );

		return $run_id;
	}

	/**
	 * Process a chunk of pending queue items.
	 *
	 * Pops up to $n pending internal URLs, fetches each (with politeness delay),
	 * parses the response, enqueues new internal URLs, and stores classified issues.
	 * When the internal queue empties, triggers external link checking then marks
	 * the run complete.
	 *
	 * @param int $n Batch size. Default 10.
	 * @return array{done:bool,crawled:int,queued:int,issues:int} Progress snapshot.
	 */
	public function process_chunk( int $n = 10 ): array {
		global $wpdb;

		$state = get_option( self::OPT_STATE, array() );
		if ( empty( $state ) || 'running' !== ( $state['status'] ?? '' ) ) {
			return array(
				'done'    => true,
				'crawled' => 0,
				'queued'  => 0,
				'issues'  => 0,
			);
		}

		$run_id       = (int) $state['run_id'];
		$home_host    = self::get_home_host();
		$delay_us     = max( 0, (int) ( $state['delay_us'] ?? self::DEFAULT_DELAY_US ) );
		$internal_cap = (int) ( $state['internal_cap'] ?? self::DEFAULT_INTERNAL_CAP );
		$queue_table  = $wpdb->prefix . SWPS_Crawl_Issues::TABLE_QUEUE;

		// Fetch next $n pending items.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, depth FROM {$queue_table} WHERE run_id = %d AND status = 'pending' LIMIT %d",
				$run_id,
				$n
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			// Internal queue empty: check external links, then finish.
			$this->check_external_links( $state );
			$this->finish_run( $state );
			return SWPS_Crawl_Issues::progress_snapshot( $run_id );
		}

		$crawled_count = 0;

		foreach ( $rows as $row ) {
			$url   = (string) $row->url;
			$depth = (int) $row->depth;

			// Mark as done.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$queue_table,
				array( 'status' => 'done' ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			// Politeness delay (skip on the first fetch in the chunk).
			if ( $crawled_count > 0 && $delay_us > 0 ) {
				usleep( (int) $delay_us );
			}

			$fetch = $this->fetch_url( $url );

			$page = array(
				'links'       => array(),
				'images'      => array(),
				'canonical'   => null,
				'h1_count'    => 0,
				'has_noindex' => false,
				'mixed'       => array(),
			);

			if ( ! empty( $fetch['body'] ) && $fetch['status'] < 400 ) {
				$page = self::parse_html( $fetch['body'], $url );
			}

			// Classify and store issues.
			foreach ( self::classify( $fetch, $page, $home_host ) as $issue ) {
				SWPS_Crawl_Issues::insert_issue(
					$run_id,
					(string) $issue['type'],
					(string) $issue['url'],
					(array) ( $issue['detail'] ?? array() ),
					(string) ( $issue['severity'] ?? 'warning' )
				);
			}

			// Collect external links for later.
			foreach ( $page['links'] as $link ) {
				if ( ! self::is_internal( $link, $home_host ) ) {
					$state['external_links'][ $link ] = true;
				}
			}

			// Enqueue new internal links (depth + 1, capped).
			if ( $depth < self::MAX_DEPTH ) {
				foreach ( $page['links'] as $link ) {
					if ( ! self::is_internal( $link, $home_host ) ) {
						continue;
					}
					if ( (int) $state['internal_queued'] >= $internal_cap ) {
						break;
					}

					$norm = self::normalize_url( $link, $home_host );
					if ( '' === $norm || ! $this->is_qs_allowed( $link, $state ) ) {
						continue;
					}

					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$inserted = $wpdb->insert(
						$queue_table,
						array(
							'run_id'   => $run_id,
							'url_hash' => hash( 'sha256', $norm ),
							'url'      => $link,
							'depth'    => $depth + 1,
							'status'   => 'pending',
							'found_on' => $url,
						),
						array( '%d', '%s', '%s', '%d', '%s', '%s' )
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

					if ( ! empty( $inserted ) ) {
						++$state['internal_queued'];
						$this->update_qs_counts( $link, $state );
					}
				}
			}

			++$crawled_count;
		}

		update_option( self::OPT_STATE, $state, false );

		return SWPS_Crawl_Issues::progress_snapshot( $run_id );
	}

	// =========================================================================
	// PRIVATE HELPERS
	// =========================================================================

	/**
	 * Fetch a URL following 3xx redirects manually up to MAX_HOPS.
	 *
	 * @param string $url Starting URL.
	 * @return array{url:string,status:int,body:string,found_on:string,hops:array}
	 */
	private function fetch_url( string $url ): array {
		$hops    = array();
		$current = $url;

		for ( $i = 0; $i <= self::MAX_HOPS; $i++ ) {
			$response = wp_remote_get(
				$current,
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'user-agent'  => 'StrataWP-SEO/' . SWPS_VERSION . ' (+https://stratawpseo.com; site-crawler)',
					'sslverify'   => false,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'url'      => $url,
					'status'   => 0,
					'body'     => '',
					'found_on' => '',
					'hops'     => $hops,
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( $status >= 300 && $status < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( empty( $location ) ) {
					break;
				}
				$hops[]  = array(
					'url'    => $current,
					'status' => $status,
				);
				$current = $location;
				continue;
			}

			$body = '';
			if ( $status < 400 ) {
				$body = wp_remote_retrieve_body( $response );
			}

			$hops[] = array(
				'url'    => $current,
				'status' => $status,
			);

			return array(
				'url'      => $url,
				'status'   => $status,
				'body'     => $body,
				'found_on' => '',
				'hops'     => array_slice( $hops, 0, -1 ), // Exclude the final (success) hop.
			);
		}

		// Exceeded MAX_HOPS — redirect loop.
		return array(
			'url'      => $url,
			'status'   => 0,
			'body'     => '',
			'found_on' => '',
			'hops'     => $hops,
		);
	}

	/**
	 * Check all collected external links (HEAD first, GET fallback on 403/405/429).
	 *
	 * Mutates $state['external_checked'] with the count of URLs actually checked.
	 *
	 * @param array $state Crawl state (passed by reference).
	 */
	private function check_external_links( array &$state ): void {
		$run_id       = (int) $state['run_id'];
		$external_cap = (int) ( $state['external_cap'] ?? self::DEFAULT_EXTERNAL_CAP );
		$delay_us     = max( 0, (int) ( $state['delay_us'] ?? self::DEFAULT_DELAY_US ) );

		$ignore_raw  = (string) ( $state['ignore_hosts'] ?? '' );
		$ignore_list = array_filter( array_map( 'trim', explode( ',', $ignore_raw ) ) );

		$external_links = array_keys( $state['external_links'] ?? array() );
		$checked        = 0;

		foreach ( $external_links as $link ) {
			if ( $checked >= $external_cap ) {
				break;
			}

			$parts = wp_parse_url( $link );
			$ehost = strtolower( $parts['host'] ?? '' );
			if ( in_array( $ehost, $ignore_list, true ) ) {
				continue;
			}

			if ( $delay_us > 0 ) {
				usleep( (int) $delay_us );
			}

			$status   = $this->check_external_url( $link );
			$severity = $status >= 500 ? 'error' : 'warning';

			if ( $status >= 400 ) {
				SWPS_Crawl_Issues::insert_issue(
					$run_id,
					'broken_link',
					$link,
					array(
						'status'   => $status,
						'external' => true,
					),
					$severity
				);
			}

			++$checked;
		}

		$state['external_checked'] = $checked;
	}

	/**
	 * HEAD-first external URL check with GET fallback on 403/405/429.
	 *
	 * @param string $url External URL.
	 * @return int HTTP status code, or 0 on connection failure.
	 */
	private function check_external_url( string $url ): int {
		$args = array(
			'timeout'     => 10,
			'redirection' => 5,
			'user-agent'  => 'StrataWP-SEO/' . SWPS_VERSION . ' (+https://stratawpseo.com; link-checker)',
			'sslverify'   => false,
		);

		$response = wp_remote_head( $url, $args );

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		// GET fallback when HEAD is blocked.
		if ( in_array( $status, array( 403, 405, 429 ), true ) ) {
			$response2 = wp_remote_get( $url, $args );
			if ( ! is_wp_error( $response2 ) ) {
				$status = (int) wp_remote_retrieve_response_code( $response2 );
			}
		}

		return $status;
	}

	/**
	 * Finalize the run: build + store the summary option, then prune old runs.
	 *
	 * @param array $state Current run state (passed by reference).
	 */
	private function finish_run( array &$state ): void {
		$run_id = (int) $state['run_id'];

		$summary = array(
			'run_id'           => $run_id,
			'completed_at'     => gmdate( 'Y-m-d H:i:s' ),
			'crawled'          => SWPS_Crawl_Issues::crawled_count( $run_id ),
			'issue_counts'     => SWPS_Crawl_Issues::issue_counts( $run_id ),
			'external_checked' => (int) ( $state['external_checked'] ?? 0 ),
		);

		update_option( self::OPT_LAST_SUMMARY, $summary, false );

		$state['status'] = 'done';
		update_option( self::OPT_STATE, $state, false );

		SWPS_Crawl_Issues::prune_old_runs( $run_id );
	}

	/**
	 * Return the home URL's registrable hostname (lowercase, no www.).
	 *
	 * @return string
	 */
	public static function get_home_host(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strtolower( $host );
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}

	/**
	 * Get seed URLs from published sitemap-eligible posts.
	 *
	 * @return string[] Array of absolute URLs.
	 */
	private function get_seed_urls(): array {
		$posts = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ) ),
				'post_status'    => 'publish',
				'posts_per_page' => self::DEFAULT_INTERNAL_CAP * 2,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_swps_sitemap_exclude',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$urls = array( home_url( '/' ) );

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			if ( ! empty( $permalink ) ) {
				$urls[] = $permalink;
			}
		}

		return array_unique( $urls );
	}

	/**
	 * Return true when the URL is below the query-string-per-path cap.
	 *
	 * @param string $url   URL to check.
	 * @param array  $state Crawl state (reads qs_path_counts).
	 * @return bool True when the URL should be allowed into the queue.
	 */
	private function is_qs_allowed( string $url, array $state ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return true;
		}
		$path  = $parts['path'] ?? '/';
		$count = (int) ( $state['qs_path_counts'][ $path ] ?? 0 );

		return $count < self::QS_CAP_PER_PATH;
	}

	/**
	 * Increment the query-string count for the URL's path in the state array.
	 *
	 * @param string $url   URL just added to the queue.
	 * @param array  $state Crawl state (passed by reference).
	 */
	private function update_qs_counts( string $url, array &$state ): void {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return;
		}
		$path                             = $parts['path'] ?? '/';
		$state['qs_path_counts'][ $path ] = (int) ( $state['qs_path_counts'][ $path ] ?? 0 ) + 1;
	}

	/**
	 * Cron handler: process one chunk and re-schedule until done.
	 */
	public function cron_process_chunk(): void {
		$result = $this->process_chunk( 10 );
		if ( ! $result['done'] ) {
			wp_schedule_single_event( time() + 1, self::CRON_HOOK );
		}
	}

	/**
	 * Weekly cron handler: start a fresh crawl run and kick off the first chunk.
	 *
	 * The weekly setting is re-checked at fire time so toggling it off takes effect
	 * on the next scheduled trigger without requiring a deactivate/reactivate cycle.
	 */
	public function cron_weekly_trigger(): void {
		if ( ! get_option( 'swps_crawl_weekly_enabled', 0 ) ) {
			return;
		}
		$this->start_run();
		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}
}
