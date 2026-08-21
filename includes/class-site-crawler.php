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

require_once __DIR__ . '/crawl-checks/class-crawl-check.php';
require_once __DIR__ . '/crawl-checks/class-crawl-check-registry.php';
require_once __DIR__ . '/crawl-checks/class-checks-fetch.php';
require_once __DIR__ . '/crawl-checks/class-checks-legacy-page.php';
require_once __DIR__ . '/crawl-checks/class-checks-head.php';
require_once __DIR__ . '/crawl-checks/class-minify-heuristic.php';
require_once __DIR__ . '/crawl-checks/class-checks-content.php';
require_once __DIR__ . '/crawl-checks/class-checks-aggregate.php';
require_once __DIR__ . '/crawl-checks/class-crawl-score.php';

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
	 * Filter a list of links down to internal ones, normalized and deduplicated.
	 *
	 * @param string[] $links     Absolute URLs (as extracted by parse_html()).
	 * @param string   $home_host Registrable home hostname, lower-case, no www.
	 * @return string[] Deduplicated, normalized (scheme-relative) internal links.
	 */
	public static function internal_links_normalized( array $links, string $home_host ): array {
		$out = array();
		foreach ( $links as $link ) {
			if ( self::is_internal( $link, $home_host ) ) {
				$norm = self::normalize_url( $link, $home_host );
				if ( '' !== $norm ) {
					$out[ $norm ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * Resolve a potentially relative href/src/Location value to an absolute URL.
	 *
	 * Only http(s) targets are resolvable: any other scheme (mailto:, tel:,
	 * javascript:, data:, ftp:, …) returns '' — resolving those as relative
	 * paths would fabricate URLs like /blog/mailto:user@host that 404.
	 *
	 * @param string $href     Raw attribute or header value.
	 * @param string $base_url Absolute URL the value was found on.
	 * @return string Absolute URL, or '' when non-resolvable.
	 */
	public static function resolve_url( string $href, string $base_url ): string {
		$href = trim( $href );
		if ( '' === $href || str_starts_with( $href, '#' ) ) {
			return '';
		}

		// Already absolute.
		if ( preg_match( '/^https?:\/\//i', $href ) ) {
			return $href;
		}

		$base_parts = wp_parse_url( $base_url );
		if ( ! is_array( $base_parts ) ) {
			return '';
		}

		// Protocol-relative.
		if ( str_starts_with( $href, '//' ) ) {
			return ( $base_parts['scheme'] ?? 'https' ) . ':' . $href;
		}

		// Any other scheme is not crawlable.
		if ( preg_match( '/^[a-z][a-z0-9+.\-]*:/i', $href ) ) {
			return '';
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
	}

	/**
	 * Extract candidate links, assets, and meta signals from an HTML string.
	 *
	 * Uses DOMDocument with libxml_use_internal_errors so malformed HTML is
	 * tolerated.
	 *
	 * @param string $html     Full HTML source of the page.
	 * @param string $base_url Absolute URL used to resolve relative hrefs.
	 * @return array{links:string[],images:string[],canonical:?string,h1_count:int,has_noindex:bool,mixed:string[],title:string,meta_desc:string,has_viewport:bool,has_doctype:bool,has_charset:bool,has_lang:bool,word_count:int,text_bytes:int,script_srcs:string[],style_hrefs:string[],images_missing_alt:string[],hreflangs:array,has_schema:bool,nofollow_internal:string[],is_challenge:bool,is_archive:bool,is_paginated:bool}
	 */
	public static function parse_html( string $html, string $base_url ): array {
		$result = array(
			'links'              => array(),
			'images'             => array(),
			'canonical'          => null,
			'h1_count'           => 0,
			'has_noindex'        => false,
			'mixed'              => array(),
			'title'              => '',
			'meta_desc'          => '',
			'has_viewport'       => false,
			'has_doctype'        => false,
			'has_charset'        => false,
			'has_lang'           => false,
			'word_count'         => 0,
			'text_bytes'         => 0,
			'script_srcs'        => array(),
			'style_hrefs'        => array(),
			'images_missing_alt' => array(),
			'hreflangs'          => array(),
			'has_schema'         => false,
			'nofollow_internal'  => array(),
			'is_challenge'       => false,
			'is_archive'         => false,
			'is_paginated'       => false,
		);

		$is_https = str_starts_with( strtolower( $base_url ), 'https://' );

		// Raw-string facts, computed before DOMDocument normalizes the markup.
		$result['has_doctype']  = 0 === strncasecmp( ltrim( $html ), '<!doctype', 9 );
		$result['is_paginated'] = (bool) preg_match( '#/page/\d+/?$#', (string) wp_parse_url( $base_url, PHP_URL_PATH ) );

		$prev = libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		@$dom->loadHTML( $html, LIBXML_NONET ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$resolve = static function ( string $href ) use ( $base_url ): string {
			return self::resolve_url( $href, $base_url );
		};

		// <title>.
		$titles = $dom->getElementsByTagName( 'title' );
		if ( $titles->length > 0 ) {
			$result['title'] = trim( (string) preg_replace( '/\s+/', ' ', $titles->item( 0 )->textContent ) );
		}

		// <html lang>.
		$html_el = $dom->getElementsByTagName( 'html' );
		if ( $html_el->length > 0 && $html_el->item( 0 ) instanceof DOMElement && '' !== trim( $html_el->item( 0 )->getAttribute( 'lang' ) ) ) {
			$result['has_lang'] = true;
		}

		// <body class> archive marker.
		$body = $dom->getElementsByTagName( 'body' );
		if ( $body->length > 0 && $body->item( 0 ) instanceof DOMElement ) {
			$classes = preg_split( '/\s+/', strtolower( $body->item( 0 )->getAttribute( 'class' ) ) );
			if ( false === $classes ) {
				$classes = array();
			}
			$result['is_archive'] = in_array( 'archive', $classes, true );
		}

		// <a href>.
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			// Loop variable is a DOMElement anchor node.
			$abs = $resolve( $a->getAttribute( 'href' ) );
			if ( '' !== $abs ) {
				$result['links'][] = $abs;
				if ( preg_match( '/\bnofollow\b/i', $a->getAttribute( 'rel' ) ) ) {
					$result['nofollow_internal'][] = $abs;
				}
			}
		}

		// <img src> — also checked for mixed content and missing alt.
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			// Loop variable is a DOMElement image node.
			$abs = $resolve( $img->getAttribute( 'src' ) );
			if ( '' !== $abs ) {
				$result['images'][] = $abs;
				if ( $is_https && str_starts_with( strtolower( $abs ), 'http://' ) ) {
					$result['mixed'][] = $abs;
				}
				if ( ! $img->hasAttribute( 'alt' ) ) {
					$result['images_missing_alt'][] = $abs;
				}
			}
		}

		// <script src> — mixed-content, script_srcs, and JSON-LD schema detection.
		foreach ( $dom->getElementsByTagName( 'script' ) as $el ) {
			// Loop variable is a DOMElement script node.
			if ( 'application/ld+json' === strtolower( $el->getAttribute( 'type' ) ) ) {
				$result['has_schema'] = true;
			}

			$src = $el->getAttribute( 'src' );
			if ( '' === $src ) {
				continue;
			}
			$abs = $resolve( $src );
			if ( '' === $abs ) {
				continue;
			}
			$result['script_srcs'][] = $abs;
			if ( $is_https && str_starts_with( strtolower( $abs ), 'http://' ) ) {
				$result['mixed'][] = $abs;
			}
		}

		// <link href> — canonical, stylesheets, hreflang, and mixed-content.
		foreach ( $dom->getElementsByTagName( 'link' ) as $el ) {
			// Loop variable is a DOMElement link node.
			$rel = strtolower( $el->getAttribute( 'rel' ) );
			$abs = $resolve( $el->getAttribute( 'href' ) );

			if ( 'canonical' === $rel && '' !== $abs ) {
				$result['canonical'] = $abs;
				continue;
			}

			if ( 'stylesheet' === $rel && '' !== $abs ) {
				$result['style_hrefs'][] = $abs;
			}

			if ( 'alternate' === $rel && '' !== $abs && '' !== trim( $el->getAttribute( 'hreflang' ) ) ) {
				$result['hreflangs'][] = array(
					'lang' => $el->getAttribute( 'hreflang' ),
					'href' => $abs,
				);
			}

			if ( '' !== $abs && $is_https && str_starts_with( strtolower( $abs ), 'http://' ) ) {
				$result['mixed'][] = $abs;
			}
		}

		// <meta name="robots"> + viewport, description, charset, and refresh-challenge signal.
		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			// Loop variable is a DOMElement meta node.
			$name = strtolower( $meta->getAttribute( 'name' ) );
			if ( 'robots' === $name && str_contains( strtolower( $meta->getAttribute( 'content' ) ), 'noindex' ) ) {
				$result['has_noindex'] = true;
			}
			if ( 'viewport' === $name && '' !== trim( $meta->getAttribute( 'content' ) ) ) {
				$result['has_viewport'] = true;
			}
			if ( 'description' === $name ) {
				$result['meta_desc'] = trim( $meta->getAttribute( 'content' ) );
			}
			if ( '' !== $meta->getAttribute( 'charset' ) ) {
				$result['has_charset'] = true;
			}
			if ( 'content-type' === strtolower( $meta->getAttribute( 'http-equiv' ) )
				&& false !== stripos( $meta->getAttribute( 'content' ), 'charset' ) ) {
				$result['has_charset'] = true;
			}
			// Challenge signal 1: meta-refresh into a challenge/captcha path.
			if ( 'refresh' === strtolower( $meta->getAttribute( 'http-equiv' ) )
				&& preg_match( '#(sgcaptcha|captcha|challenge|cdn-cgi)#i', $meta->getAttribute( 'content' ) ) ) {
				$result['is_challenge'] = true;
			}
		}

		// <h1> count.
		$result['h1_count'] = $dom->getElementsByTagName( 'h1' )->length;

		// Text metrics: body text nodes, excluding <script>/<style>/<a> content
		// (link labels and boilerplate would otherwise inflate the count).
		$xpath      = new DOMXPath( $dom );
		$text_nodes = $xpath->query( '//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::a)]' );
		$parts      = array();
		if ( false !== $text_nodes ) {
			foreach ( $text_nodes as $node ) {
				$piece = trim( $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMText native property, not a WP object.
				if ( '' !== $piece ) {
					$parts[] = $piece;
				}
			}
		}
		$text                 = trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', $parts ) ) );
		$result['text_bytes'] = strlen( $text );
		$result['word_count'] = '' === $text ? 0 : count( preg_split( '/\s+/', $text ) );

		// Challenge signal 2: bare head — no title, no viewport, no doctype together.
		if ( '' === $result['title'] && ! $result['has_viewport'] && ! $result['has_doctype'] ) {
			$result['is_challenge'] = true;
		}

		return $result;
	}

	/**
	 * Classify a fetched URL result into issue rows.
	 *
	 * @param array  $fetch     Fetch result: keys url (string), status (int), found_on (string),
	 *                          hops (array of 3xx responses), loop (bool, MAX_HOPS exceeded),
	 *                          final_url (string, post-redirect URL; falls back to url).
	 * @param array  $page      Result of parse_html() on the response body. The caller may
	 *                          enrich it with post_id (int) and sitemap_excluded (bool) for
	 *                          the noindex-in-sitemap check (pure callers pass fixtures), and
	 *                          should carry the response content_type (string) through so
	 *                          page-level checks are skipped for non-HTML resources; an absent
	 *                          content_type key is treated as HTML for backward compatibility.
	 * @param string $home_host Registrable home hostname, lower-case, no www.
	 * @param array  $excluded  Check IDs to skip (from SWPS_Crawl_Check_Registry::OPT_EXCLUDED).
	 *                          Defaults to none excluded, preserving prior behaviour for callers
	 *                          that don't pass this.
	 * @return array[] Issue rows; each has keys: type, url, detail (array), severity.
	 */
	public static function classify( array $fetch, array $page, string $home_host, array $excluded = array() ): array {
		$facts = array_merge(
			$page,
			array(
				'url'         => $fetch['url'] ?? '',
				'status_code' => (int) ( $fetch['status'] ?? 0 ),
				'found_on'    => $fetch['found_on'] ?? '',
				'hops'        => $fetch['hops'] ?? array(),
				'loop'        => ! empty( $fetch['loop'] ),
				'final_url'   => (string) ( $fetch['final_url'] ?? ( $fetch['url'] ?? '' ) ),
				'home_host'   => $home_host,
			)
		);

		// Fetch-level checks always run; loop and broken results end evaluation.
		$fetch_checks = array( new SWPS_Check_Redirect_Loop(), new SWPS_Check_Broken_Link(), new SWPS_Check_Redirect_Chain() );
		$issues       = array();
		foreach ( $fetch_checks as $check ) {
			$issue = $check->check_page( $facts );
			if ( null !== $issue ) {
				$issues[] = $issue;
				if ( in_array( $check->id(), array( 'redirect_loop', 'broken_link' ), true ) ) {
					return $issues;
				}
			}
		}

		// Non-HTML resources (images, PDFs, etc.) that resolve to a non-broken
		// response carry no real HTML facts — running content checks against
		// their necessarily-absent/falsy defaults (missing_meta_description,
		// low_word_count, missing_schema, uncompressed_page, ...) would be a
		// false positive, so page-level checks are skipped entirely once the
		// content type is known and is not HTML. An absent content_type key
		// (every pre-existing caller/fixture) preserves prior behaviour.
		$content_type = strtolower( (string) ( $facts['content_type'] ?? '' ) );
		if ( '' !== $content_type && ! str_contains( $content_type, 'text/html' ) ) {
			return $issues;
		}

		$page_checks = array_filter(
			SWPS_Crawl_Check_Registry::all( $excluded ),
			static fn( SWPS_Crawl_Check $c ) => ! in_array( $c->id(), array( 'redirect_loop', 'broken_link', 'redirect_chain' ), true )
		);

		return array_merge( $issues, SWPS_Crawl_Check_Registry::run_page_checks( $facts, array_values( $page_checks ) ) );
	}

	// =========================================================================
	// STATE MACHINE
	// =========================================================================

	/**
	 * Start a new crawl run.
	 *
	 * Seeds the queue from get_seed_urls() (capped to swps_crawl_internal_cap)
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
		$internal_cap = (int) ( $state['internal_cap'] ?? self::DEFAULT_INTERNAL_CAP );

		// Resolved once per chunk (not per URL) so a mid-run settings change
		// takes effect on the next chunk without extra option reads per fetch.
		$excluded_checks = (array) get_option( SWPS_Crawl_Check_Registry::OPT_EXCLUDED, array() );

		/**
		 * Filter the politeness delay (µs) between crawler fetches.
		 *
		 * The stored option/state value is the base; e.g. a local smoke test
		 * can lower it without touching settings.
		 *
		 * @param int $delay_us Microseconds to sleep between fetches.
		 */
		$delay_us    = max( 0, (int) apply_filters( 'swps_crawl_delay_us', (int) ( $state['delay_us'] ?? self::DEFAULT_DELAY_US ) ) );
		$queue_table = $wpdb->prefix . SWPS_Crawl_Issues::TABLE_QUEUE;

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
			// Internal queue empty: check external links, then finish. This
			// runs in its own chunk request so the external phase does not
			// extend the final page-fetch chunk.
			$this->check_external_links( $state );
			$this->finish_run( $state );

			$snapshot         = SWPS_Crawl_Issues::progress_snapshot( $run_id );
			$snapshot['done'] = true;
			return $snapshot;
		}

		$crawled_count = 0;

		foreach ( $rows as $row ) {
			$url   = (string) $row->url;
			$depth = (int) $row->depth;

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

			// HTML-only gate: page facts (and the checks that depend on them)
			// only ever come from a fully-formed 2xx HTML response. Broken
			// responses (>=400 or 0) and non-HTML resources (images, PDFs,
			// ...) never reach parse_html(), so $page stays the minimal
			// fallback above — classify()'s own content-type gate then keeps
			// page-level checks from running against those absent facts.
			$content_type = strtolower( (string) $fetch['content_type'] );
			$is_broken    = $fetch['status'] >= 400 || 0 === $fetch['status'];
			$is_html      = ! $is_broken && ! empty( $fetch['body'] ) && str_contains( $content_type, 'text/html' );

			if ( $is_html ) {
				// Parse against the post-redirect URL so relative hrefs on a
				// redirect destination resolve against the right base.
				$page = self::parse_html( $fetch['body'], (string) $fetch['final_url'] );

				// Enrich with WP lookups for the noindex-in-sitemap check
				// (classify() itself stays pure).
				if ( ! empty( $page['has_noindex'] ) ) {
					$page_post_id             = url_to_postid( $url );
					$page['post_id']          = $page_post_id;
					$page['sitemap_excluded'] = $page_post_id > 0
						&& (bool) get_post_meta( $page_post_id, '_swps_sitemap_exclude', true );
				}

				$assets                    = array_merge( $page['script_srcs'], $page['style_hrefs'] );
				$page['unminified_assets'] = $this->sample_unminified_assets( $assets, $home_host, $state );
				$page['html_bytes']        = strlen( (string) $fetch['body'] );
				$page['is_compressed']     = ! empty( $fetch['is_compressed'] );
			} elseif ( ! $is_broken && '' === $content_type ) {
				// A live (non-broken) response with a missing/empty
				// Content-Type header is not identifiably HTML, but an
				// empty string is indistinguishable from "key never set"
				// to classify()'s backward-compat gate (which treats an
				// absent content_type as HTML to preserve old fixtures).
				// Substitute an explicit non-HTML sentinel so that gate
				// always sees a definite type here and still skips page
				// checks against these bare fallback facts.
				$content_type = 'application/octet-stream';
			}

			// Carries through to classify()'s $facts merge regardless of
			// branch, so its content-type gate always has a real value.
			$page['content_type'] = $content_type;

			// Classify and store issues.
			$target = SWPS_Crawl_Target::resolve( $url );
			foreach ( self::classify( $fetch, $page, $home_host, $excluded_checks ) as $issue ) {
				SWPS_Crawl_Issues::insert_issue(
					$run_id,
					(string) $issue['type'],
					(string) $issue['url'],
					(array) ( $issue['detail'] ?? array() ),
					(string) ( $issue['severity'] ?? 'warning' ),
					null,
					$target
				);
			}

			// Page-facts row: full row for parsed HTML, minimal (url +
			// status_code only) row for broken responses so the dashboard
			// can still count them, and no row at all for non-HTML 2xx
			// resources — there are no page facts to record for those.
			if ( $is_html ) {
				SWPS_Crawl_Issues::insert_page(
					$run_id,
					array(
						'url'               => $url,
						'status_code'       => (int) $fetch['status'],
						'content_type'      => $content_type,
						'title'             => $page['title'],
						'title_hash'        => md5( strtolower( trim( $page['title'] ) ) ),
						'meta_desc_hash'    => '' === trim( $page['meta_desc'] ) ? '' : md5( strtolower( trim( $page['meta_desc'] ) ) ),
						'desc_length'       => strlen( $page['meta_desc'] ),
						'flags'             => SWPS_Crawl_Page_Flags::pack( $page ),
						'word_count'        => (int) $page['word_count'],
						'html_bytes'        => (int) $page['html_bytes'],
						'text_bytes'        => (int) $page['text_bytes'],
						'h1_count'          => (int) $page['h1_count'],
						'canonical'         => $page['canonical'],
						'internal_links'    => self::internal_links_normalized( $page['links'], $home_host ),
						'unminified_assets' => $page['unminified_assets'],
					)
				);
			} elseif ( $is_broken ) {
				SWPS_Crawl_Issues::insert_page(
					$run_id,
					array(
						'url'         => $url,
						'status_code' => (int) $fetch['status'],
					)
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

			// Mark the row done only AFTER fetch + classify + enqueue, so a
			// crash mid-chunk re-processes at most this one URL on the next
			// chunk instead of silently skipping the whole batch.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$queue_table,
				array( 'status' => 'done' ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			++$crawled_count;
		}

		update_option( self::OPT_STATE, $state, false );

		// Not done yet even when the queue just emptied: the next chunk call
		// performs external checks + finish_run() and reports done=true.
		$snapshot         = SWPS_Crawl_Issues::progress_snapshot( $run_id );
		$snapshot['done'] = false;

		return $snapshot;
	}

	// =========================================================================
	// PRIVATE HELPERS
	// =========================================================================

	/**
	 * Fetch a URL following 3xx redirects manually up to MAX_HOPS.
	 *
	 * The returned 'hops' array contains every 3xx response observed (one
	 * entry per redirect, {url, status}); the final non-3xx response is NOT
	 * included. 'final_url' is the URL that produced the final response (equal
	 * to 'url' when no redirect occurred). 'loop' is true only when MAX_HOPS
	 * was exceeded without reaching a non-3xx response; a 3xx with a missing
	 * or unresolvable Location header returns status 0 (dead redirect).
	 *
	 * @param string $url Starting URL.
	 * @return array{url:string,status:int,body:string,found_on:string,hops:array,loop:bool,final_url:string,content_type:string,is_compressed:bool}
	 */
	private function fetch_url( string $url ): array {
		$hops    = array();
		$current = $url;

		/**
		 * Filter the crawler's User-Agent string.
		 *
		 * @param string $user_agent Default: "StrataWP-SEO-Audit/{version}; +{home_url}".
		 */
		$user_agent = apply_filters( 'swps_crawl_user_agent', 'StrataWP-SEO-Audit/' . SWPS_VERSION . '; +' . home_url( '/' ) );

		for ( $i = 0; $i <= self::MAX_HOPS; $i++ ) {
			$response = wp_remote_get(
				$current,
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'user-agent'  => $user_agent,
					'headers'     => array( 'Accept-Encoding' => 'gzip, deflate' ),
					'sslverify'   => false,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'url'           => $url,
					'status'        => 0,
					'body'          => '',
					'found_on'      => '',
					'hops'          => $hops,
					'loop'          => false,
					'final_url'     => $current,
					'content_type'  => '',
					'is_compressed' => false,
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( $status >= 300 && $status < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				// RFC 7231 allows relative Location values; resolve against
				// the URL that answered. Missing/unresolvable = dead redirect.
				$next = is_string( $location ) ? self::resolve_url( $location, $current ) : '';
				if ( '' === $next ) {
					return array(
						'url'           => $url,
						'status'        => 0,
						'body'          => '',
						'found_on'      => '',
						'hops'          => $hops,
						'loop'          => false,
						'final_url'     => $current,
						'content_type'  => '',
						'is_compressed' => false,
					);
				}
				$hops[]  = array(
					'url'    => $current,
					'status' => $status,
				);
				$current = $next;
				continue;
			}

			$body = '';
			if ( $status < 400 ) {
				$body = wp_remote_retrieve_body( $response );
			}

			return array(
				'url'           => $url,
				'status'        => $status,
				'body'          => $body,
				'found_on'      => '',
				'hops'          => $hops,
				'loop'          => false,
				'final_url'     => $current,
				'content_type'  => (string) wp_remote_retrieve_header( $response, 'content-type' ),
				'is_compressed' => '' !== (string) wp_remote_retrieve_header( $response, 'content-encoding' ),
			);
		}

		// Exceeded MAX_HOPS — redirect loop.
		return array(
			'url'           => $url,
			'status'        => 0,
			'body'          => '',
			'found_on'      => '',
			'hops'          => $hops,
			'loop'          => true,
			'final_url'     => $current,
			'content_type'  => '',
			'is_compressed' => false,
		);
	}

	/**
	 * Sample first-party assets for minification; verdicts cached per run.
	 *
	 * Fetches at most the first 20 unique candidate URLs, skipping any whose
	 * host is not internal, and caches the minified/not-minified verdict per
	 * asset URL (query string stripped) in $state['asset_verdicts'] so a
	 * given asset is only ever fetched once per run regardless of how many
	 * pages reference it.
	 *
	 * Bounded to a 15-second wall-clock budget per call: once elapsed, the
	 * loop stops fetching further assets for this page and returns whatever
	 * verdicts it has. This is a per-page cap, not a per-run one — an asset
	 * left unsampled here simply isn't reported as unminified for this page;
	 * because verdicts are cached by URL in $state['asset_verdicts'], a later
	 * page that references the same asset can still pick up its verdict for
	 * free, or sample it fresh if it was never reached.
	 *
	 * @param string[] $urls      Absolute asset URLs (scripts + styles).
	 * @param string   $home_host Home host.
	 * @param array    $state     Run state (asset_verdicts cache lives here), passed by reference.
	 * @return string[] Asset URLs that look unminified.
	 */
	private function sample_unminified_assets( array $urls, string $home_host, array &$state ): array {
		$bad      = array();
		$deadline = microtime( true ) + 15;
		foreach ( array_slice( array_unique( $urls ), 0, 20 ) as $url ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			if ( ! self::is_internal( $url, $home_host ) ) {
				continue;
			}
			$key = md5( strtok( $url, '?' ) );
			if ( ! isset( $state['asset_verdicts'][ $key ] ) ) {
				$resp = wp_remote_get(
					$url,
					array(
						'timeout' => 5,
						'headers' => array( 'Range' => 'bytes=0-20479' ),
					)
				);
				$body = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
				// Empty/failed fetch counts as minified (no false positives).
				$state['asset_verdicts'][ $key ] = SWPS_Minify_Heuristic::looks_minified( $body );
			}
			if ( false === $state['asset_verdicts'][ $key ] ) {
				$bad[] = strtok( $url, '?' );
			}
		}
		return $bad;
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

		/** This filter is documented in process_chunk(). */
		$delay_us = max( 0, (int) apply_filters( 'swps_crawl_delay_us', (int) ( $state['delay_us'] ?? self::DEFAULT_DELAY_US ) ) );

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

			$status = $this->check_external_url( $link );

			// 5xx = genuinely broken (error). Everything else that reaches
			// this branch (4xx walls after the GET fallback, or non-standard
			// codes like LinkedIn's 999 bot-wall) is a warning — these are
			// usually anti-bot noise, not dead links.
			$severity = ( $status >= 500 && $status < 600 ) ? 'error' : 'warning';

			if ( $status >= 400 ) {
				SWPS_Crawl_Issues::insert_issue(
					$run_id,
					'broken_link',
					$link,
					array(
						'status'   => $status,
						'external' => true,
					),
					$severity,
					null,
					SWPS_Crawl_Target::resolve( (string) $link )
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

		foreach ( SWPS_Crawl_Check_Registry::all() as $check ) {
			try {
				foreach ( $check->check_run( $run_id ) as $issue ) {
					SWPS_Crawl_Issues::insert_issue(
						$run_id,
						$issue['type'],
						$issue['url'],
						$issue['detail'],
						$issue['severity'],
						null,
						SWPS_Crawl_Target::resolve( (string) $issue['url'] )
					);
				}
			} catch ( \Throwable $e ) {
				error_log( 'SWPS crawl aggregate check ' . $check->id() . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		$severity     = SWPS_Crawl_Issues::severity_counts( $run_id );
		$pages        = SWPS_Crawl_Issues::page_counts( $run_id );
		$issue_counts = SWPS_Crawl_Issues::issue_counts( $run_id );

		$state['summary'] = array(
			'score'        => SWPS_Crawl_Score::calculate( $severity, $pages['total'] ),
			'severity'     => $severity,
			'pages'        => $pages,
			'issue_counts' => $issue_counts,
		);

		// Rolling window of the last 5 run summaries, for Task 9's trend view.
		$summaries            = get_option( 'swps_crawl_run_summaries', array() );
		$summaries[ $run_id ] = $state['summary'];
		update_option( 'swps_crawl_run_summaries', array_slice( $summaries, -5, null, true ), false );

		$summary = array(
			'run_id'           => $run_id,
			'completed_at'     => gmdate( 'Y-m-d H:i:s' ),
			'crawled'          => SWPS_Crawl_Issues::crawled_count( $run_id ),
			'issue_counts'     => $issue_counts,
			'severity_counts'  => $severity,
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
	 * Get seed URLs covering the whole public site: every published post of
	 * every public post type, every public taxonomy term archive, every
	 * author archive with published posts, and the blog index.
	 *
	 * Deliberately broader than the XML sitemap (no sitemap-exclusion or
	 * per-post noindex filtering): the crawler's job is to independently
	 * discover and audit URLs, including ones the sitemap itself omits.
	 * Deeper pagination is not guessed here — it is discovered by following
	 * links during the crawl.
	 *
	 * @return string[] Array of absolute URLs.
	 */
	private function get_seed_urls(): array {
		$urls = array( home_url( '/' ) );

		$posts = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ) ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post_id ) {
			$permalink = get_permalink( $post_id );
			if ( ! empty( $permalink ) ) {
				$urls[] = $permalink;
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => get_taxonomies( array( 'public' => true ) ),
				'hide_empty' => true,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		$authors = get_users( array( 'has_published_posts' => true ) );
		foreach ( $authors as $author ) {
			$urls[] = get_author_posts_url( (int) $author->ID );
		}

		$page_for_posts = (int) get_option( 'page_for_posts' );
		if ( $page_for_posts > 0 ) {
			$permalink = get_permalink( $page_for_posts );
			if ( ! empty( $permalink ) ) {
				$urls[] = $permalink;
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
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
