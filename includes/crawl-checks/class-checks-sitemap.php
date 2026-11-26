<?php
/**
 * Sitemap audit checks: sitemap URLs that redirect, error, declare another
 * canonical, or carry an impossible lastmod.
 *
 * These run only for URLs the crawler read from the sitemap. The caller puts
 * the sitemap entry into the page facts as 'sitemap' => array( 'lastmod' =>
 * string, 'post_id' => int ) before classify() runs; URLs without that key
 * are never flagged here.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- one file per check family, like the other crawl-check files.

/**
 * Pure helpers for reading sitemap XML and judging lastmod values.
 */
class SWPS_Sitemap_Audit {

	/** Check ids that run on sitemap URLs before the fetch-level short-circuit. */
	public const CHECK_IDS = array(
		'sitemap_url_redirects',
		'sitemap_url_error',
		'sitemap_url_noncanonical',
		'sitemap_bad_lastmod',
	);

	/** Earliest believable lastmod (catches the 1970 epoch bug). */
	public const MIN_LASTMOD = '2000-01-01T00:00:00+00:00';

	/** How far in the future a lastmod may be (clock skew allowance). */
	public const FUTURE_SLACK = 86400;

	/**
	 * Parse a sitemap index or urlset.
	 *
	 * @param string $xml Raw XML.
	 * @return array{sitemaps: string[], urls: array<int, array{loc: string, lastmod: string}>}
	 *               Child sitemap URLs (index) and URL entries (urlset). Both
	 *               empty when the XML does not parse.
	 */
	public static function parse_sitemap_xml( string $xml ): array {
		$out = array(
			'sitemaps' => array(),
			'urls'     => array(),
		);
		if ( '' === trim( $xml ) ) {
			return $out;
		}

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$ok   = $dom->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok || null === $dom->documentElement ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM property.
			return $out;
		}

		$root = $dom->documentElement->localName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM property.
		$item = 'sitemapindex' === $root ? 'sitemap' : ( 'urlset' === $root ? 'url' : '' );
		if ( '' === $item ) {
			return $out;
		}

		foreach ( $dom->documentElement->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM property.
			if ( ! $node instanceof DOMElement || $item !== $node->localName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM property.
				continue;
			}
			$loc     = '';
			$lastmod = '';
			foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM property.
				if ( ! $child instanceof DOMElement || $child->namespaceURI !== $node->namespaceURI ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM property.
					continue;
				}
				if ( 'loc' === $child->localName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM property.
					$loc = trim( $child->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM property.
				} elseif ( 'lastmod' === $child->localName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM property.
					$lastmod = trim( $child->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM property.
				}
			}
			if ( '' === $loc ) {
				continue;
			}
			if ( 'sitemap' === $item ) {
				$out['sitemaps'][] = $loc;
			} else {
				$out['urls'][] = array(
					'loc'     => $loc,
					'lastmod' => $lastmod,
				);
			}
		}

		return $out;
	}

	/**
	 * Judge a <lastmod> value.
	 *
	 * Accepts the W3C Datetime forms the sitemap protocol allows (YYYY,
	 * YYYY-MM, YYYY-MM-DD, or a full date-time with a timezone designator).
	 * An empty value is fine: lastmod is optional.
	 *
	 * @param string $lastmod Raw lastmod text.
	 * @param int    $now     Current Unix time.
	 * @return string '' when fine, else 'unparseable', 'too_old' or 'future'.
	 */
	public static function lastmod_problem( string $lastmod, int $now ): string {
		$lastmod = trim( $lastmod );
		if ( '' === $lastmod ) {
			return '';
		}

		$re = '/^(\d{4})(?:-(\d{2})(?:-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-]\d{2}:\d{2}))?)?)?$/';
		if ( ! preg_match( $re, $lastmod, $m ) ) {
			return 'unparseable';
		}

		$year  = (int) $m[1];
		$month = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 1;
		$day   = isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] : 1;
		if ( ! checkdate( $month, $day, $year ) ) {
			return 'unparseable';
		}
		if ( isset( $m[4] ) && '' !== $m[4] && ( (int) $m[4] > 23 || (int) $m[5] > 59 || ( isset( $m[6] ) && (int) $m[6] > 59 ) ) ) {
			return 'unparseable';
		}

		try {
			$time = ( new DateTimeImmutable( strlen( $lastmod ) <= 7 ? sprintf( '%04d-%02d-01T00:00:00Z', $year, $month ) : $lastmod ) )->getTimestamp();
		} catch ( Exception $e ) {
			return 'unparseable';
		}

		if ( $time < strtotime( self::MIN_LASTMOD ) ) {
			return 'too_old';
		}
		if ( $time > $now + self::FUTURE_SLACK ) {
			return 'future';
		}
		return '';
	}

	/**
	 * Detail keys shared by the sitemap checks.
	 *
	 * @param array $page  Merged fact array.
	 * @param array $extra Check-specific detail.
	 * @return array Detail array (post_id only when known, for the sitemap-exclude fixer).
	 */
	public static function detail( array $page, array $extra ): array {
		$post_id = (int) ( $page['sitemap']['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$extra['post_id'] = $post_id;
		}
		return $extra;
	}
}

/**
 * Flags a sitemap URL that answers with a redirect.
 */
class SWPS_Check_Sitemap_Url_Redirects extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'sitemap_url_redirects';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'warning';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Sitemap URLs that redirect', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The sitemap lists a URL that redirects somewhere else. Search engines want only final URLs in a sitemap. List the destination instead, or exclude this post from the sitemap.', 'stratawp-seo' );
	}

	/**
	 * Per-page check.
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( ! isset( $page['sitemap'] ) || empty( $page['hops'] ) ) {
			return null;
		}
		$first = (array) $page['hops'][0];
		return $this->issue(
			$page['url'],
			SWPS_Sitemap_Audit::detail(
				$page,
				array(
					'status'      => (int) ( $first['status'] ?? 0 ),
					'redirect_to' => ! empty( $page['loop'] ) ? __( 'redirect loop', 'stratawp-seo' ) : (string) ( $page['final_url'] ?? '' ),
				)
			)
		);
	}
}

/**
 * Flags a sitemap URL that ends in a 4xx/5xx response or no response.
 */
class SWPS_Check_Sitemap_Url_Error extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'sitemap_url_error';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'error';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Sitemap URLs that return errors', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The sitemap lists a URL that returns an error (such as 404 or 500) or no response. Fix the page, or exclude it from the sitemap so search engines stop requesting it.', 'stratawp-seo' );
	}

	/**
	 * Per-page check.
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( ! isset( $page['sitemap'] ) || ! empty( $page['loop'] ) ) {
			return null;
		}
		$status = (int) ( $page['status_code'] ?? 0 );
		if ( 0 !== $status && $status < 400 ) {
			return null;
		}
		return $this->issue( $page['url'], SWPS_Sitemap_Audit::detail( $page, array( 'status' => $status ) ) );
	}
}

/**
 * Flags a sitemap URL whose page declares a different canonical URL.
 */
class SWPS_Check_Sitemap_Url_Noncanonical extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'sitemap_url_noncanonical';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'warning';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Sitemap URLs that point their canonical elsewhere', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The sitemap lists a URL whose page says a different URL is the canonical one. A sitemap should only list canonical URLs. Fix the canonical tag if this URL is the right one, or exclude it from the sitemap.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. Trailing slash, scheme, host case and a leading www.
	 * are ignored when comparing.
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( ! isset( $page['sitemap'] ) || ! empty( $page['hops'] ) ) {
			return null;
		}
		$status = (int) ( $page['status_code'] ?? 0 );
		if ( $status < 200 || $status >= 300 ) {
			return null;
		}
		$canonical = $page['canonical'] ?? null;
		if ( null === $canonical || '' === trim( (string) $canonical ) ) {
			return null;
		}
		$home_host = (string) ( $page['home_host'] ?? '' );
		$norm_url  = self::comparable( (string) $page['url'], $home_host );
		$norm_can  = self::comparable( (string) $canonical, $home_host );
		if ( '' === $norm_url || '' === $norm_can || $norm_url === $norm_can ) {
			return null;
		}
		return $this->issue( $page['url'], SWPS_Sitemap_Audit::detail( $page, array( 'canonical' => (string) $canonical ) ) );
	}

	/**
	 * Normalized form for comparison: SWPS_Site_Crawler::normalize_url()
	 * (scheme-relative, lower-case host, no trailing slash) minus a leading www.
	 *
	 * @param string $url       Absolute URL.
	 * @param string $home_host Home host.
	 */
	private static function comparable( string $url, string $home_host ): string {
		$norm = SWPS_Site_Crawler::normalize_url( $url, $home_host );
		return str_starts_with( $norm, '//www.' ) ? '//' . substr( $norm, 6 ) : $norm;
	}
}

/**
 * Flags a sitemap entry whose lastmod is unparseable, before 2000, or in the future.
 */
class SWPS_Check_Sitemap_Bad_Lastmod extends SWPS_Crawl_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'sitemap_bad_lastmod';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'notice';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Sitemap entries with an impossible last-modified date', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'The sitemap gives this URL a lastmod date that cannot be read, is before the year 2000 (often a 1970 date from a missing value), or is in the future. Search engines ignore bad dates and may stop trusting the sitemap. Check the post\'s modified date, or the plugin that writes the sitemap.', 'stratawp-seo' );
	}

	/**
	 * Per-page check. Runs whatever the URL answered.
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		if ( ! isset( $page['sitemap'] ) ) {
			return null;
		}
		$lastmod = (string) ( $page['sitemap']['lastmod'] ?? '' );
		$problem = SWPS_Sitemap_Audit::lastmod_problem( $lastmod, time() );
		if ( '' === $problem ) {
			return null;
		}
		return $this->issue(
			$page['url'],
			SWPS_Sitemap_Audit::detail(
				$page,
				array(
					'lastmod' => $lastmod,
					'problem' => $problem,
				)
			)
		);
	}
}
