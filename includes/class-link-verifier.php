<?php
/**
 * Link verification for AI-generated content.
 *
 * The generator asks the model for internal links from a supplied list and
 * for real external sources, but a model can still invent a plausible URL.
 * verify() walks every <a href> in the generated HTML and unwraps the ones
 * it cannot vouch for (the anchor text stays, the link goes):
 *
 * - Internal (same host or a relative path): kept when the URL was in the
 *   internal-link list given to the prompt, or the resolver says it is a
 *   published post. The site home is always kept.
 * - External, with source URLs supplied: kept only when it matches one of
 *   them (scheme, www., trailing slash and fragment ignored).
 * - External, no source URLs: kept when the checker says it answers with
 *   2xx/3xx. At most $max_checks distinct URLs are checked; the rest are
 *   unwrapped as not checked.
 *
 * Anchors (#id), mailto:, tel: and other non-http links are left alone.
 *
 * Pure PHP: the WordPress lookups are passed in as callables so it can be
 * unit tested without a WordPress bootstrap.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies and unwraps unverifiable links in generated HTML.
 */
class SWPS_Link_Verifier {

	/** Maximum distinct external URLs checked over HTTP per post. */
	public const MAX_EXTERNAL_CHECKS = 8;

	/** Timeout in seconds for one external check request. */
	public const CHECK_TIMEOUT = 5;

	/** Whole-post time budget in seconds for external checks. */
	public const CHECK_BUDGET = 20;

	/** Post meta key holding the links removed from a generated post. */
	public const META_KEY = '_swps_removed_links';

	/** Removal reason: internal URL not in the list and not a published post. */
	public const REASON_INTERNAL = 'internal_unknown';

	/** Removal reason: external URL not among the supplied source URLs. */
	public const REASON_NOT_SOURCE = 'not_in_sources';

	/** Removal reason: external URL did not answer with 2xx/3xx. */
	public const REASON_UNREACHABLE = 'unreachable';

	/** Removal reason: external URL over the check cap or time budget. */
	public const REASON_NOT_CHECKED = 'not_checked';

	/**
	 * Verify the links in generated HTML.
	 *
	 * @param string $html    Generated content HTML.
	 * @param array  $context Verification inputs: site_host (string, host of home_url()),
	 *                        internal_urls (string[], candidates given to the prompt),
	 *                        source_urls (string[], owner-supplied sources),
	 *                        resolve_internal (callable, fn( string $href ): bool, true for a published post),
	 *                        check_external (callable, fn( string $url ): ?bool, null when not checked),
	 *                        max_checks (int, cap on distinct external URLs checked).
	 * @return array{html: string, removed: array<int, array{url: string, anchor: string, reason: string}>}
	 */
	public static function verify( string $html, array $context ): array {
		$site_host  = self::host_key( (string) ( $context['site_host'] ?? '' ) );
		$resolve    = $context['resolve_internal'] ?? null;
		$check      = $context['check_external'] ?? null;
		$max_checks = (int) ( $context['max_checks'] ?? self::MAX_EXTERNAL_CHECKS );

		$internal = self::key_set( (array) ( $context['internal_urls'] ?? array() ), $site_host );
		$sources  = self::key_set( (array) ( $context['source_urls'] ?? array() ), $site_host );

		$removed  = array();
		$verdicts = array();
		$checked  = 0;

		$result = preg_replace_callback(
			'/<a\b([^>]*)>(.*?)<\/a\s*>/is',
			static function ( array $m ) use ( $site_host, $resolve, $check, $max_checks, $internal, $sources, &$removed, &$verdicts, &$checked ): string {
				$href = self::extract_href( $m[1] );
				if ( null === $href ) {
					return $m[0];
				}

				$link = self::classify( $href, $site_host );
				if ( null === $link ) {
					return $m[0];
				}

				$key = $link['key'];
				if ( ! array_key_exists( $key, $verdicts ) ) {
					$reason = '';
					if ( $link['internal'] ) {
						$is_home = $site_host . '/' === $key;
						if ( ! $is_home && ! isset( $internal[ $key ] ) && ! ( is_callable( $resolve ) && $resolve( $href ) ) ) {
							$reason = self::REASON_INTERNAL;
						}
					} elseif ( ! empty( $sources ) ) {
						if ( ! isset( $sources[ $key ] ) ) {
							$reason = self::REASON_NOT_SOURCE;
						}
					} else {
						$ok = null;
						if ( $checked < $max_checks && is_callable( $check ) ) {
							++$checked;
							$ok = $check( $link['url'] );
						}
						if ( null === $ok ) {
							$reason = self::REASON_NOT_CHECKED;
						} elseif ( ! $ok ) {
							$reason = self::REASON_UNREACHABLE;
						}
					}
					$verdicts[ $key ] = $reason;
				}

				if ( '' === $verdicts[ $key ] ) {
					return $m[0];
				}

				$removed[] = array(
					'url'    => $href,
					'anchor' => trim( wp_strip_all_tags( $m[2] ) ),
					'reason' => $verdicts[ $key ],
				);
				return $m[2];
			},
			$html
		);

		return array(
			'html'    => is_string( $result ) ? $result : $html,
			'removed' => $removed,
		);
	}

	/**
	 * Drop entries whose url was removed from a model-reported link list
	 * (internal_links_used / external_links: arrays of {anchor_text, url}).
	 *
	 * @param mixed  $links     Model-reported list.
	 * @param array  $removed   Removed links from verify().
	 * @param string $site_host Host of the site.
	 * @return mixed The filtered list, or the input unchanged when it is not a list.
	 */
	public static function filter_link_list( $links, array $removed, string $site_host ) {
		if ( ! is_array( $links ) || empty( $removed ) ) {
			return $links;
		}
		$host = self::host_key( $site_host );
		$gone = self::key_set( array_column( $removed, 'url' ), $host );

		return array_values(
			array_filter(
				$links,
				static function ( $item ) use ( $gone, $host ): bool {
					$url = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : '';
					if ( '' === $url ) {
						return true;
					}
					$link = self::classify( $url, $host );
					return null === $link || ! isset( $gone[ $link['key'] ] );
				}
			)
		);
	}

	/**
	 * Human-readable label for a removal reason.
	 *
	 * @param string $reason One of the REASON_* constants.
	 * @return string
	 */
	public static function reason_label( string $reason ): string {
		switch ( $reason ) {
			case self::REASON_INTERNAL:
				return 'not a page on this site';
			case self::REASON_NOT_SOURCE:
				return 'not one of the supplied sources';
			case self::REASON_UNREACHABLE:
				return 'did not load';
			case self::REASON_NOT_CHECKED:
				return 'over the check limit, not verified';
			default:
				return $reason;
		}
	}

	/**
	 * Classify an href.
	 *
	 * @param string $href      Raw href value (entities decoded).
	 * @param string $site_host Normalized site host key.
	 * @return array{internal: bool, key: string, url: string}|null Null for links that are not checked.
	 */
	public static function classify( string $href, string $site_host ): ?array {
		$href = trim( $href );
		if ( '' === $href || '#' === $href[0] || '?' === $href[0] ) {
			return null;
		}

		if ( 0 === strpos( $href, '//' ) ) {
			$href_abs = 'https:' . $href;
		} elseif ( preg_match( '/^[a-z][a-z0-9+.\-]*:/i', $href ) ) {
			$scheme = strtolower( (string) strstr( $href, ':', true ) );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				return null;
			}
			$href_abs = $href;
		} else {
			// Relative URL: resolve against the site.
			$path     = '/' === $href[0] ? $href : '/' . $href;
			$href_abs = 'https://' . $site_host . $path;
		}

		$parts = wp_parse_url( $href_abs );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$host = self::host_key( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		$path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$key  = $host . ( '' === $path ? '/' : $path ) . ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );

		return array(
			'internal' => '' !== $site_host && $host === $site_host,
			'key'      => $key,
			'url'      => $href_abs,
		);
	}

	/**
	 * Lowercased host with any leading "www." removed. Accepts a bare host
	 * or a full URL.
	 *
	 * @param string $host_or_url Host or URL.
	 * @return string
	 */
	public static function host_key( string $host_or_url ): string {
		$value = strtolower( trim( $host_or_url ) );
		if ( false !== strpos( $value, '://' ) ) {
			$parts = wp_parse_url( $value );
			$value = (string) ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		}
		return 0 === strpos( $value, 'www.' ) ? substr( $value, 4 ) : $value;
	}

	/**
	 * Read the href attribute out of an <a> tag's attribute string.
	 *
	 * @param string $attrs Attribute string.
	 * @return string|null Decoded href, or null when there is none.
	 */
	private static function extract_href( string $attrs ): ?string {
		if ( ! preg_match( '/(?:^|\s)href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $m ) ) {
			return null;
		}
		$raw = $m[3] ?? '';
		if ( '' === $raw ) {
			$raw = '' !== ( $m[2] ?? '' ) ? $m[2] : $m[1];
		}
		return html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Build a lookup set of normalized keys from a list of URLs.
	 *
	 * @param array  $urls      URLs.
	 * @param string $site_host Normalized site host key.
	 * @return array<string, true>
	 */
	private static function key_set( array $urls, string $site_host ): array {
		$set = array();
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}
			$link = self::classify( $url, $site_host );
			if ( null !== $link ) {
				$set[ $link['key'] ] = true;
			}
		}
		return $set;
	}
}
