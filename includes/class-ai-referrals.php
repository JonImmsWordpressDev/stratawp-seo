<?php
/**
 * AI Referral Attribution — classifier, bot-source map, engagement comparison,
 * daily rollup table, and aggregation.
 *
 * Pure static core (classify, bot_source_map, engagement_comparison) is
 * WordPress-free so it can be unit-tested without a WP bootstrap.
 *
 * The runtime wrapper classify_visit() applies the swps_ai_referral_sources
 * filter so site owners can extend the default source map.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Referral Attribution — classifier, bot-source map, engagement comparison,
 * daily rollup table, and aggregation methods.
 */
class SWPS_AI_Referrals {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/** Schema version — bump when the rollup table DDL changes. */
	public const DB_VERSION = '1';

	/** Option key used to gate the schema upgrade. */
	public const OPT_DB_VER = 'swps_ai_referrals_db_version';

	/** Rollup table (without $wpdb->prefix). */
	private const ROLLUP_TABLE = 'swps_ai_referrals_daily';

	// -------------------------------------------------------------------------
	// Classifier core — WP-free
	// -------------------------------------------------------------------------

	/**
	 * Default referrer-host → AI source map. Subdomains match via suffix.
	 *
	 * @var array<string, string>
	 */
	private const SOURCE_MAP = array(
		'chatgpt.com'           => 'chatgpt',
		'chat.openai.com'       => 'chatgpt',
		'perplexity.ai'         => 'perplexity',
		'claude.ai'             => 'claude',
		'gemini.google.com'     => 'gemini',
		'copilot.microsoft.com' => 'copilot',
		'you.com'               => 'you',
		'chat.deepseek.com'     => 'deepseek',
		'grok.com'              => 'grok',
		'meta.ai'               => 'meta',
	);

	/**
	 * Classify a visit into an AI source.
	 *
	 * Matching rules (in priority order):
	 * 1. referrer host exact-match or `.host` suffix match (case-insensitive).
	 * 2. page_url utm_source fallback: value matches a map key (host) or a map
	 *    value (source slug), case-insensitive.
	 * 3. Returns '' when neither matches.
	 *
	 * @param string                     $referrer  Full referrer URL ('' when none).
	 * @param string                     $page_url  Landing URL (utm_source fallback).
	 * @param array<string, string>|null $map       Override map (null = default).
	 * @return string Source key, or '' when not AI-referred.
	 */
	public static function classify( string $referrer, string $page_url = '', ?array $map = null ): string {
		$map = $map ?? self::SOURCE_MAP;

		// 1. Referrer host match.
		if ( '' !== $referrer ) {
			$host = self::parse_host( $referrer );

			if ( '' !== $host ) {
				$source = self::match_host( $host, $map );

				if ( '' !== $source ) {
					return $source;
				}
			}
		}

		// 2. utm_source fallback from page_url.
		if ( '' !== $page_url ) {
			$utm = self::extract_utm_source( $page_url );

			if ( '' !== $utm ) {
				return self::match_utm( $utm, $map );
			}
		}

		return '';
	}

	/**
	 * Runtime wrapper: applies the swps_ai_referral_sources filter before classifying.
	 *
	 * @param string $referrer Full referrer URL.
	 * @param string $page_url Landing page URL.
	 * @return string Source key, or '' when not AI-referred.
	 */
	public static function classify_visit( string $referrer, string $page_url = '' ): string {
		$map = (array) apply_filters( 'swps_ai_referral_sources', self::SOURCE_MAP );

		return self::classify( $referrer, $page_url, $map );
	}

	// -------------------------------------------------------------------------
	// Bot → AI source map
	// -------------------------------------------------------------------------

	/**
	 * Return a map of bot_key → ai_source for bot keys from SWPS_AI_Bots that
	 * have a visit-side counterpart in the source map.
	 *
	 * Bots with no visit-side counterpart (applebot_extended, ccbot, bytespider,
	 * amazonbot, duckassistbot) are omitted intentionally.
	 *
	 * @return array<string, string> bot_key → ai_source.
	 */
	public static function bot_source_map(): array {
		return array(
			'gptbot'             => 'chatgpt',
			'oai_searchbot'      => 'chatgpt',
			'chatgpt_user'       => 'chatgpt',
			'claudebot'          => 'claude',
			'claude_web'         => 'claude',
			'anthropic_ai'       => 'claude',
			'perplexitybot'      => 'perplexity',
			'perplexity_user'    => 'perplexity',
			'google_extended'    => 'gemini',
			'meta_externalagent' => 'meta',
		);
	}

	// -------------------------------------------------------------------------
	// Engagement comparison — pure math, WP-free
	// -------------------------------------------------------------------------

	/**
	 * Compare AI-referred engagement against organic.
	 *
	 * Returns null when either sample is below the minimum threshold to prevent
	 * misleading ratios from sparse data.
	 *
	 * @param array{views: int, avg_time: float, avg_scroll: float, bounce_rate: float} $ai      AI aggregate metrics.
	 * @param array{views: int, avg_time: float, avg_scroll: float, bounce_rate: float} $organic Organic aggregate metrics.
	 * @param int                                                                       $min_n   Minimum sample size per group.
	 * @return array{time_ratio: float, scroll_ratio: float, bounce_ratio: float, ai_n: int, organic_n: int}|null
	 */
	public static function engagement_comparison( array $ai, array $organic, int $min_n = 30 ): ?array {
		if ( ( $ai['views'] ?? 0 ) < $min_n || ( $organic['views'] ?? 0 ) < $min_n ) {
			return null;
		}

		$organic_time   = (float) ( $organic['avg_time'] ?? 1 );
		$organic_scroll = (float) ( $organic['avg_scroll'] ?? 1 );
		$organic_bounce = (float) ( $organic['bounce_rate'] ?? 1 );

		return array(
			'time_ratio'   => $organic_time > 0 ? round( (float) $ai['avg_time'] / $organic_time, 4 ) : 0.0,
			'scroll_ratio' => $organic_scroll > 0 ? round( (float) $ai['avg_scroll'] / $organic_scroll, 4 ) : 0.0,
			'bounce_ratio' => $organic_bounce > 0 ? round( (float) $ai['bounce_rate'] / $organic_bounce, 4 ) : 0.0,
			'ai_n'         => (int) $ai['views'],
			'organic_n'    => (int) $organic['views'],
		);
	}

	// -------------------------------------------------------------------------
	// Schema — rollup table
	// -------------------------------------------------------------------------

	/**
	 * Create the AI referrals daily rollup table.
	 * Called on activation (from stratawp-seo.php) and via maybe_upgrade().
	 */
	public static function create_table(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . self::ROLLUP_TABLE;

		$sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ai_source VARCHAR(32) NOT NULL DEFAULT '',
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            date DATE NOT NULL,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            total_time INT UNSIGNED NOT NULL DEFAULT 0,
            total_scroll INT UNSIGNED NOT NULL DEFAULT 0,
            bounces INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY idx_source_post_date (ai_source, post_id, date),
            KEY idx_ai_source (ai_source),
            KEY idx_date (date)
        ) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the rollup table. Called on uninstall.
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::ROLLUP_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Ensure the rollup table exists for existing installs that did not
	 * re-run the activation hook (i.e. plugin was updated, not reactivated).
	 *
	 * Mirrors the SWPS_Backlinks::__construct() upgrade pattern.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::OPT_DB_VER ) ) {
			self::create_table();
			update_option( self::OPT_DB_VER, self::DB_VERSION );
		}
	}

	// -------------------------------------------------------------------------
	// Aggregation — called from SWPS_Analytics_Tracker::aggregate_and_prune()
	// -------------------------------------------------------------------------

	/**
	 * Roll up raw analytics rows (where ai_source is set) into the daily
	 * rollup table. Uses additive SUMS — no averaging bug.
	 *
	 * Must be called BEFORE the raw delete in aggregate_and_prune().
	 *
	 * @param string $cutoff MySQL datetime string — rows older than this are rolled up.
	 */
	public static function aggregate( string $cutoff ): void {
		global $wpdb;

		$raw    = $wpdb->prefix . 'swps_analytics';
		$rollup = $wpdb->prefix . self::ROLLUP_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$rollup} (ai_source, post_id, date, views, total_time, total_scroll, bounces)
                 SELECT ai_source, post_id, DATE(created_at),
                        COUNT(*), SUM(time_on_page), SUM(scroll_depth), SUM(is_bounce)
                 FROM {$raw}
                 WHERE ai_source <> '' AND created_at < %s
                 GROUP BY ai_source, post_id, DATE(created_at)
                 ON DUPLICATE KEY UPDATE
                    views        = views        + VALUES(views),
                    total_time   = total_time   + VALUES(total_time),
                    total_scroll = total_scroll + VALUES(total_scroll),
                    bounces      = bounces      + VALUES(bounces)",
				$cutoff
			)
		);
		// phpcs:enable

		// Prune rollup beyond retention (same option as the analytics table).
		$retention_days = (int) get_option( 'swps_analytics_retention', 90 );
		$prune_date     = gmdate( 'Y-m-d', strtotime( "-{$retention_days} days" ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$rollup} WHERE date < %s",
				$prune_date
			)
		);
		// phpcs:enable
	}

	// -------------------------------------------------------------------------
	// Internal helpers — WP-free
	// -------------------------------------------------------------------------

	/**
	 * Extract lowercased hostname from a URL string.
	 * Returns '' on parse failure.
	 *
	 * @param string $url URL string.
	 * @return string Lowercase hostname, or ''.
	 */
	private static function parse_host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		return strtolower( $host );
	}

	/**
	 * Match a hostname (already lowercased) against the source map.
	 * Supports exact match and dot-prefixed suffix match (subdomains).
	 *
	 * @param string                $host Lowercased hostname.
	 * @param array<string, string> $map  Source map.
	 * @return string Source key, or ''.
	 */
	private static function match_host( string $host, array $map ): string {
		foreach ( $map as $entry => $source ) {
			$entry_lower = strtolower( $entry );

			if ( $host === $entry_lower || str_ends_with( $host, '.' . $entry_lower ) ) {
				return $source;
			}
		}

		return '';
	}

	/**
	 * Extract the utm_source query parameter from a URL.
	 * Returns '' when absent or unparseable.
	 *
	 * @param string $url URL string.
	 * @return string utm_source value (raw, lowercased), or ''.
	 */
	private static function extract_utm_source( string $url ): string {
		$query = wp_parse_url( $url, PHP_URL_QUERY );

		if ( ! is_string( $query ) || '' === $query ) {
			return '';
		}

		parse_str( $query, $params );

		return strtolower( (string) ( $params['utm_source'] ?? '' ) );
	}

	/**
	 * Resolve a utm_source value to an AI source slug.
	 *
	 * Checks two strategies:
	 * 1. utm_source is a hostname — match via host-match rules (e.g. chatgpt.com).
	 * 2. utm_source is a bare slug — match against map source values (e.g. chatgpt).
	 *
	 * @param string                $utm_source Lowercased utm_source value.
	 * @param array<string, string> $map         Source map.
	 * @return string Source key, or ''.
	 */
	private static function match_utm( string $utm_source, array $map ): string {
		// Strategy 1: utm_source looks like a hostname (contains a dot).
		if ( str_contains( $utm_source, '.' ) ) {
			$matched = self::match_host( $utm_source, $map );

			if ( '' !== $matched ) {
				return $matched;
			}
		}

		// Strategy 2: bare slug — check if the value is in the source values.
		if ( in_array( $utm_source, $map, true ) ) {
			return $utm_source;
		}

		return '';
	}
}
