<?php
/**
 * Migration tool: import settings from Yoast SEO / Yoast Premium / Rank Math / Rank Math Pro.
 *
 * Phase 1: per-post meta migration with preview, batch runner, and undo.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Migration {

	/** Backup option key (stores overwritten StrataWP values for undo). */
	private const BACKUP_OPTION = 'swps_migration_backup';

	/** Report option key (last migration summary). */
	private const REPORT_OPTION = 'swps_migration_last_report';

	/** Batch size for post meta migration. */
	private const BATCH_SIZE = 100;

	/**
	 * Per-post meta key map.
	 *
	 * StrataWP key => [ yoast_keys[], rank_math_keys[] ]
	 * The first non-empty source wins.
	 */
	private const META_MAP = array(
		'_swps_meta_title'         => array( array( '_yoast_wpseo_title' ), array( 'rank_math_title' ) ),
		'_swps_meta_description'   => array( array( '_yoast_wpseo_metadesc' ), array( 'rank_math_description' ) ),
		'_swps_focus_keyword'      => array( array( '_yoast_wpseo_focuskw' ), array( 'rank_math_focus_keyword' ) ),
		'_swps_canonical_url'      => array( array( '_yoast_wpseo_canonical' ), array( 'rank_math_canonical_url' ) ),
		'_swps_breadcrumb_title'   => array( array( '_yoast_wpseo_bctitle' ), array( 'rank_math_breadcrumb_title' ) ),
		'_swps_social_title'       => array( array( '_yoast_wpseo_opengraph-title' ), array( 'rank_math_facebook_title' ) ),
		'_swps_social_description' => array( array( '_yoast_wpseo_opengraph-description' ), array( 'rank_math_facebook_description' ) ),
		'_swps_social_image'       => array( array( '_yoast_wpseo_opengraph-image' ), array( 'rank_math_facebook_image' ) ),
	);

	/**
	 * Yoast separator codes → literal characters.
	 */
	private const YOAST_SEPARATOR_MAP = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	);

	/**
	 * Rank Math single-percent variable → StrataWP double-percent variable.
	 */
	private const RM_VAR_MAP = array(
		'%title%'            => '%%title%%',
		'%sitename%'         => '%%sitename%%',
		'%sitedesc%'         => '%%sitedesc%%',
		'%sep%'              => '%%sep%%',
		'%excerpt%'          => '%%excerpt%%',
		'%excerpt_only%'     => '%%excerpt%%',
		'%page%'             => '%%page%%',
		'%pagenumber%'       => '%%pagenumber%%',
		'%pagetotal%'        => '%%pagetotal%%',
		'%category%'         => '%%category%%',
		'%tag%'              => '%%tag%%',
		'%term%'             => '%%term_title%%',
		'%term_title%'       => '%%term_title%%',
		'%date%'             => '%%date%%',
		'%modified%'         => '%%modified%%',
		'%search_query%'     => '%%searchphrase%%',
		'%name%'             => '%%name%%',
		'%user_description%' => '%%user_description%%',
		'%pt_plural%'        => '%%pt_plural%%',
		'%pt_single%'        => '%%pt_single%%',
		'%currenttime%'      => '%%currenttime%%',
		'%currentdate%'      => '%%currentdate%%',
		'%currentyear%'      => '%%currentyear%%',
		'%currentmonth%'     => '%%currentmonth%%',
		'%currentday%'       => '%%currentday%%',
	);

	public function __construct() {
		add_action( 'admin_post_swps_run_migration', array( $this, 'handle_run' ) );
		add_action( 'admin_post_swps_undo_migration', array( $this, 'handle_undo' ) );
	}

	/**
	 * Detect installed source plugins.
	 *
	 * Returns array keyed by source slug => [ 'label' => string, 'post_count' => int ].
	 */
	public function detect_sources(): array {
		global $wpdb;
		$sources = array();

		$yoast_count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_focuskw','_yoast_wpseo_canonical')
             AND meta_value <> ''"
		);
		if ( $yoast_count > 0 || get_option( 'wpseo' ) || get_option( 'wpseo_titles' ) ) {
			$sources['yoast'] = array(
				'label'      => __( 'Yoast SEO / Yoast SEO Premium', 'stratawp-seo' ),
				'post_count' => $yoast_count,
			);
		}

		$rm_count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
             WHERE meta_key IN ('rank_math_title','rank_math_description','rank_math_focus_keyword','rank_math_canonical_url')
             AND meta_value <> ''"
		);
		if ( $rm_count > 0 || get_option( 'rank-math-options-titles' ) || get_option( 'rank-math-options-general' ) ) {
			$sources['rank_math'] = array(
				'label'      => __( 'Rank Math SEO / Rank Math Pro', 'stratawp-seo' ),
				'post_count' => $rm_count,
			);
		}

		return $sources;
	}

	/**
	 * Preview without writing — counts what would change.
	 *
	 * @param string[] $phases subset of ['post_meta','settings','redirects'].
	 */
	public function preview( string $source, string $conflict_policy, array $phases ): array {
		$stats        = array(
			'posts_scanned'           => 0,
			'fields_to_write'         => 0,
			'fields_skipped_existing' => 0,
			'fields_overwritten'      => 0,
			'options_to_write'        => 0,
			'options_skipped'         => 0,
			'redirects_to_write'      => 0,
		);
		$valid_phases = array_intersect( $phases, array( 'post_meta', 'settings', 'redirects' ) );

		if ( in_array( 'post_meta', $valid_phases, true ) ) {
			foreach ( $this->iterate_source_posts( $source ) as $post_id => $source_meta ) {
				++$stats['posts_scanned'];
				foreach ( self::META_MAP as $swps_key => $sources ) {
					$value = $this->pick_value( $source_meta, $sources, $source );
					if ( '' === $value ) {
						continue;
					}
					$existing = get_post_meta( $post_id, $swps_key, true );
					if ( '' !== (string) $existing && (string) $existing !== $value ) {
						if ( 'skip' === $conflict_policy ) {
							++$stats['fields_skipped_existing'];
							continue;
						}
						++$stats['fields_overwritten'];
					}
					++$stats['fields_to_write'];
				}
			}
		}

		if ( in_array( 'settings', $valid_phases, true ) ) {
			$mappings = $this->build_settings_mappings( $source );
			foreach ( $mappings as $opt => $val ) {
				if ( null === $val || '' === $val ) {
					continue;
				}
				$existing     = get_option( $opt, '' );
				$existing_str = is_scalar( $existing ) ? (string) $existing : '';
				if ( '' !== $existing_str && $existing_str !== (string) $val && 'skip' === $conflict_policy ) {
					++$stats['options_skipped'];
					continue;
				}
				++$stats['options_to_write'];
			}
		}

		if ( in_array( 'redirects', $valid_phases, true ) ) {
			$stats['redirects_to_write'] = $this->count_source_redirects( $source );
		}

		return $stats;
	}

	/**
	 * Count redirects available in the source plugin (no insert).
	 */
	private function count_source_redirects( string $source ): int {
		if ( 'yoast' === $source ) {
			$plain = get_option( 'wpseo-premium-redirects-base', array() );
			$regex = get_option( 'wpseo-premium-redirects-base-regex', array() );
			return ( is_array( $plain ) ? count( $plain ) : 0 ) + ( is_array( $regex ) ? count( $regex ) : 0 );
		}
		global $wpdb;
		$rm_table = $wpdb->prefix . 'rank_math_redirections';
		$exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rm_table ) );
		if ( ! $exists ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rm_table} WHERE status = 'active'" );
	}

	/**
	 * Run the migration. Returns a report array.
	 *
	 * @param string   $source          'yoast' or 'rank_math'.
	 * @param string   $conflict_policy 'skip' or 'overwrite'.
	 * @param string[] $phases          subset of ['post_meta','settings','redirects'].
	 */
	public function run( string $source, string $conflict_policy, array $phases ): array {
		if ( ! in_array( $source, array( 'yoast', 'rank_math' ), true ) ) {
			return array( 'error' => __( 'Unknown source.', 'stratawp-seo' ) );
		}
		if ( ! in_array( $conflict_policy, array( 'skip', 'overwrite' ), true ) ) {
			$conflict_policy = 'skip';
		}
		$valid_phases = array_intersect( $phases, array( 'post_meta', 'settings', 'redirects' ) );

		$report = array(
			'source'             => $source,
			'conflict_policy'    => $conflict_policy,
			'phases'             => array_values( $valid_phases ),
			'started_at'         => current_time( 'mysql' ),
			'posts_processed'    => 0,
			'fields_written'     => 0,
			'fields_skipped'     => 0,
			'fields_overwritten' => 0,
			'options_written'    => 0,
			'options_skipped'    => 0,
			'redirects_written'  => 0,
			'redirects_skipped'  => 0,
			'redirects_errors'   => array(),
			'notes'              => array(),
		);

		$backup = $this->fresh_backup();

		if ( in_array( 'post_meta', $valid_phases, true ) ) {
			$this->run_post_meta( $source, $conflict_policy, $report, $backup );
		}
		if ( in_array( 'settings', $valid_phases, true ) ) {
			$this->run_settings( $source, $conflict_policy, $report, $backup );
		}
		if ( in_array( 'redirects', $valid_phases, true ) ) {
			$this->run_redirects( $source, $report, $backup );
		}

		$report['finished_at'] = current_time( 'mysql' );

		update_option( self::BACKUP_OPTION, $backup, false );
		update_option( self::REPORT_OPTION, $report, false );

		return $report;
	}

	/**
	 * Undo last migration using the backup snapshot.
	 */
	public function undo(): array {
		$backup = get_option( self::BACKUP_OPTION, array() );
		if ( ! is_array( $backup ) || empty( $backup ) ) {
			return array( 'error' => __( 'Nothing to undo.', 'stratawp-seo' ) );
		}

		$reverted = 0;

		// New typed format.
		$post_meta = $backup['post_meta'] ?? null;
		$options   = $backup['options'] ?? null;
		$redirects = $backup['redirects'] ?? null;

		// Backwards-compat with v4.3.0 flat format ( post_id => [ key => prev ] ).
		if ( null === $post_meta && null === $options && null === $redirects ) {
			$post_meta = $backup;
		}

		if ( is_array( $post_meta ) ) {
			foreach ( $post_meta as $post_id => $keys ) {
				if ( ! is_array( $keys ) ) {
					continue;
				}
				foreach ( $keys as $swps_key => $previous ) {
					if ( null === $previous ) {
						delete_post_meta( (int) $post_id, $swps_key );
					} else {
						update_post_meta( (int) $post_id, $swps_key, $previous );
					}
					++$reverted;
				}
			}
		}

		if ( is_array( $options ) ) {
			foreach ( $options as $name => $previous ) {
				if ( null === $previous ) {
					delete_option( $name );
				} else {
					update_option( $name, $previous, false );
				}
				++$reverted;
			}
		}

		if ( is_array( $redirects ) && ! empty( $redirects ) ) {
			global $wpdb;
			$table        = $wpdb->prefix . 'swps_redirects';
			$ids          = array_map( 'intval', $redirects );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ($placeholders)", $ids ) );
			$reverted += count( $ids );
			delete_transient( 'swps_redirects_cache' );
		}

		delete_option( self::BACKUP_OPTION );

		return array(
			'reverted' => $reverted,
		);
	}

	/**
	 * Build a fresh, typed backup container.
	 */
	private function fresh_backup(): array {
		return array(
			'post_meta' => array(),
			'options'   => array(),
			'redirects' => array(),
		);
	}

	/**
	 * Phase 1: per-post meta.
	 */
	private function run_post_meta( string $source, string $conflict_policy, array &$report, array &$backup ): void {
		foreach ( $this->iterate_source_posts( $source ) as $post_id => $source_meta ) {
			++$report['posts_processed'];

			foreach ( self::META_MAP as $swps_key => $sources ) {
				$value = $this->pick_value( $source_meta, $sources, $source );
				if ( '' === $value ) {
					continue;
				}

				$existing     = get_post_meta( $post_id, $swps_key, true );
				$existing_str = (string) $existing;

				if ( '' !== $existing_str && $existing_str !== $value ) {
					if ( 'skip' === $conflict_policy ) {
						++$report['fields_skipped'];
						continue;
					}
					++$report['fields_overwritten'];
				}

				if ( ! isset( $backup['post_meta'][ $post_id ][ $swps_key ] ) ) {
					$backup['post_meta'][ $post_id ][ $swps_key ] = '' === $existing_str ? null : $existing_str;
				}

				update_post_meta( $post_id, $swps_key, $value );
				++$report['fields_written'];
			}
		}
	}

	/**
	 * Phase 2: global settings (separator, title templates, noindex flags).
	 */
	private function run_settings( string $source, string $conflict_policy, array &$report, array &$backup ): void {
		$mappings = $this->build_settings_mappings( $source );

		foreach ( $mappings as $swps_option => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$existing     = get_option( $swps_option, '' );
			$existing_str = is_scalar( $existing ) ? (string) $existing : '';

			if ( '' !== $existing_str && $existing_str !== (string) $value && 'skip' === $conflict_policy ) {
				++$report['options_skipped'];
				continue;
			}

			if ( ! array_key_exists( $swps_option, $backup['options'] ) ) {
				$backup['options'][ $swps_option ] = ( false === get_option( $swps_option, false ) ) ? null : $existing;
			}

			update_option( $swps_option, $value, false );
			++$report['options_written'];
		}
	}

	/**
	 * Build StrataWP option => value map from source plugin options.
	 */
	private function build_settings_mappings( string $source ): array {
		$out = array();

		if ( 'yoast' === $source ) {
			$titles = get_option( 'wpseo_titles', array() );
			if ( ! is_array( $titles ) ) {
				$titles = array();
			}

			// Separator.
			if ( ! empty( $titles['separator'] ) ) {
				$code                        = (string) $titles['separator'];
				$out['swps_title_separator'] = self::YOAST_SEPARATOR_MAP[ $code ] ?? $code;
			}

			// Title templates: title-{post_type}, title-tax-{taxonomy}, title-author-wpseo, etc.
			foreach ( $titles as $key => $val ) {
				if ( ! is_string( $val ) || '' === $val ) {
					continue;
				}
				$rewritten = $this->rewrite_template_vars( $val, 'yoast' );

				if ( 'title-author-wpseo' === $key ) {
					$out['swps_title_template_author'] = $rewritten;
				} elseif ( 'title-archive-wpseo' === $key ) {
					$out['swps_title_template_date'] = $rewritten;
				} elseif ( 'title-search-wpseo' === $key ) {
					$out['swps_title_template_search'] = $rewritten;
				} elseif ( 'title-404-wpseo' === $key ) {
					$out['swps_title_template_404'] = $rewritten;
				} elseif ( 0 === strpos( $key, 'title-tax-' ) ) {
					$tax = substr( $key, strlen( 'title-tax-' ) );
					if ( $tax ) {
						$out[ "swps_title_template_{$tax}" ] = $rewritten;
					}
				} elseif ( 0 === strpos( $key, 'title-ptarchive-' ) ) {
					$pt = substr( $key, strlen( 'title-ptarchive-' ) );
					if ( $pt ) {
						$out[ "swps_title_template_{$pt}_archive" ] = $rewritten;
					}
				} elseif ( 0 === strpos( $key, 'title-' ) ) {
					$pt = substr( $key, strlen( 'title-' ) );
					if ( $pt && false === strpos( $pt, '-wpseo' ) && false === strpos( $pt, 'tax-' ) && false === strpos( $pt, 'ptarchive-' ) ) {
						$out[ "swps_title_template_{$pt}" ] = $rewritten;
					}
				}
			}

			// noindex flags: noindex-{post_type} → swps_noindex_{post_type}.
			foreach ( $titles as $key => $val ) {
				if ( 0 === strpos( $key, 'noindex-' ) ) {
					$rest = substr( $key, strlen( 'noindex-' ) );
					if ( 'author-wpseo' === $rest || 'archive-wpseo' === $rest ) {
						continue;
					}
					if ( 0 === strpos( $rest, 'tax-' ) ) {
						$rest = substr( $rest, strlen( 'tax-' ) );
					}
					if ( $rest ) {
						$out[ "swps_noindex_{$rest}" ] = ! empty( $val ) ? 1 : 0;
					}
				}
			}
		} else {
			$titles = get_option( 'rank-math-options-titles', array() );
			if ( ! is_array( $titles ) ) {
				$titles = array();
			}

			if ( ! empty( $titles['title_separator'] ) ) {
				$out['swps_title_separator'] = (string) $titles['title_separator'];
			}

			foreach ( $titles as $key => $val ) {
				if ( ! is_string( $val ) || '' === $val ) {
					continue;
				}
				$rewritten = $this->rewrite_template_vars( $val, 'rank_math' );

				if ( preg_match( '/^pt_(.+)_title$/', $key, $m ) ) {
					$out[ "swps_title_template_{$m[1]}" ] = $rewritten;
				} elseif ( preg_match( '/^tax_(.+)_title$/', $key, $m ) ) {
					$out[ "swps_title_template_{$m[1]}" ] = $rewritten;
				} elseif ( 'author_archive_title' === $key ) {
					$out['swps_title_template_author'] = $rewritten;
				} elseif ( 'date_archive_title' === $key ) {
					$out['swps_title_template_date'] = $rewritten;
				} elseif ( 'search_title' === $key ) {
					$out['swps_title_template_search'] = $rewritten;
				} elseif ( '404_title' === $key ) {
					$out['swps_title_template_404'] = $rewritten;
				}
			}

			// noindex flags.
			foreach ( $titles as $key => $val ) {
				if ( preg_match( '/^pt_(.+)_robots$/', $key, $m ) && is_array( $val ) && in_array( 'noindex', $val, true ) ) {
					$out[ "swps_noindex_{$m[1]}" ] = 1;
				} elseif ( preg_match( '/^tax_(.+)_robots$/', $key, $m ) && is_array( $val ) && in_array( 'noindex', $val, true ) ) {
					$out[ "swps_noindex_{$m[1]}" ] = 1;
				}
			}
		}

		return $out;
	}

	/**
	 * Rewrite source-plugin template variables to StrataWP %%var%% syntax.
	 */
	private function rewrite_template_vars( string $template, string $source ): string {
		if ( 'rank_math' === $source ) {
			// Sort keys by length descending so longer matches replace first.
			$keys = array_keys( self::RM_VAR_MAP );
			usort( $keys, static fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
			foreach ( $keys as $k ) {
				$template = str_replace( $k, self::RM_VAR_MAP[ $k ], $template );
			}
		}
		// Yoast already uses %%var%% so no rewriting needed.
		return $template;
	}

	/**
	 * Phase 3: redirects (Yoast Premium + Rank Math Pro).
	 */
	private function run_redirects( string $source, array &$report, array &$backup ): void {
		if ( ! isset( stratawp_seo()->redirect_manager ) ) {
			$report['notes'][] = __( 'Redirect manager unavailable; skipping redirects.', 'stratawp-seo' );
			return;
		}
		$manager = stratawp_seo()->redirect_manager;

		if ( 'yoast' === $source ) {
			$plain = get_option( 'wpseo-premium-redirects-base', array() );
			$regex = get_option( 'wpseo-premium-redirects-base-regex', array() );

			foreach ( (array) $plain as $src => $row ) {
				$this->insert_redirect_safe( $manager, (string) $src, (string) ( $row['url'] ?? '' ), (int) ( $row['type'] ?? 301 ), false, $report, $backup );
			}
			foreach ( (array) $regex as $src => $row ) {
				$this->insert_redirect_safe( $manager, (string) $src, (string) ( $row['url'] ?? '' ), (int) ( $row['type'] ?? 301 ), true, $report, $backup );
			}
		} else {
			global $wpdb;
			$rm_table = $wpdb->prefix . 'rank_math_redirections';
			$exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rm_table ) );
			if ( ! $exists ) {
				$report['notes'][] = __( 'Rank Math redirections table not found.', 'stratawp-seo' );
				return;
			}

			$rows = $wpdb->get_results( "SELECT sources, url_to, header_code FROM {$rm_table} WHERE status = 'active'" );
			foreach ( (array) $rows as $row ) {
				$sources = maybe_unserialize( $row->sources );
				if ( ! is_array( $sources ) ) {
					continue;
				}
				$type = (int) ( $row->header_code ?: 301 );
				$tgt  = (string) ( $row->url_to ?? '' );

				foreach ( $sources as $entry ) {
					if ( ! is_array( $entry ) || empty( $entry['pattern'] ) ) {
						continue;
					}
					$is_regex = isset( $entry['comparison'] ) && 'regex' === $entry['comparison'];
					$this->insert_redirect_safe( $manager, (string) $entry['pattern'], $tgt, $type, $is_regex, $report, $backup );
				}
			}
		}
	}

	/**
	 * Insert one redirect, recording the new ID in backup for undo.
	 */
	private function insert_redirect_safe( $manager, string $source, string $target, int $type, bool $is_regex, array &$report, array &$backup ): void {
		if ( '' === $source ) {
			++$report['redirects_skipped'];
			return;
		}
		if ( ! in_array( $type, array( 301, 302, 307, 410 ), true ) ) {
			$type = 301;
		}

		$result = $manager->add_redirect( $source, $target, $type, $is_regex );

		if ( is_wp_error( $result ) ) {
			$report['redirects_errors'][] = sprintf( '%s: %s', $source, $result->get_error_message() );
			++$report['redirects_skipped'];
			return;
		}
		if ( false === $result ) {
			++$report['redirects_skipped'];
			return;
		}

		$backup['redirects'][] = (int) $result;
		++$report['redirects_written'];
	}

	/**
	 * Form handler: run migration.
	 */
	public function handle_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stratawp-seo' ) );
		}
		check_admin_referer( 'swps_run_migration' );

		$source          = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$conflict_policy = isset( $_POST['conflict_policy'] ) ? sanitize_key( wp_unslash( $_POST['conflict_policy'] ) ) : 'skip';
		$mode            = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'preview';
		$phases_raw      = isset( $_POST['phases'] ) && is_array( $_POST['phases'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['phases'] ) ) : array();
		if ( empty( $phases_raw ) ) {
			$phases_raw = array( 'post_meta' );
		}

		if ( 'run' === $mode ) {
			$this->run( $source, $conflict_policy, $phases_raw );
			$redirect = add_query_arg(
				array(
					'page'      => 'swps-migration',
					'migration' => 'done',
				),
				admin_url( 'admin.php' )
			);
		} else {
			$preview = $this->preview( $source, $conflict_policy, $phases_raw );
			set_transient(
				'swps_migration_preview_' . get_current_user_id(),
				array(
					'source'          => $source,
					'conflict_policy' => $conflict_policy,
					'phases'          => $phases_raw,
					'stats'           => $preview,
				),
				10 * MINUTE_IN_SECONDS
			);
			$redirect = add_query_arg(
				array(
					'page'      => 'swps-migration',
					'migration' => 'preview',
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Form handler: undo last migration.
	 */
	public function handle_undo(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stratawp-seo' ) );
		}
		check_admin_referer( 'swps_undo_migration' );

		$this->undo();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'swps-migration',
					'migration' => 'undone',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Pick the source value for a StrataWP key from a row of source meta.
	 *
	 * @param array  $row        post_id meta as key=>value (string).
	 * @param array  $source_def [ yoast_keys[], rm_keys[] ]
	 * @param string $source     'yoast' or 'rank_math'.
	 */
	private function pick_value( array $row, array $source_def, string $source ): string {
		$keys = 'yoast' === $source ? $source_def[0] : $source_def[1];
		foreach ( $keys as $k ) {
			if ( isset( $row[ $k ] ) && '' !== (string) $row[ $k ] ) {
				return (string) $row[ $k ];
			}
		}
		return '';
	}

	/**
	 * Generator: yields post_id => [ meta_key => meta_value ] for posts with source meta.
	 *
	 * Streams in batches of self::BATCH_SIZE to avoid loading the whole site into memory.
	 */
	private function iterate_source_posts( string $source ): Generator {
		global $wpdb;

		$keys = 'yoast' === $source
			? array_merge( ...array_column( self::META_MAP, 0 ) )
			: array_merge( ...array_column( self::META_MAP, 1 ) );

		if ( empty( $keys ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		$offset = 0;
		while ( true ) {
			$sql      = $wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key IN ($placeholders) AND meta_value <> ''
                 ORDER BY post_id ASC
                 LIMIT %d OFFSET %d",
				array_merge( $keys, array( self::BATCH_SIZE, $offset ) )
			);
			$post_ids = $wpdb->get_col( $sql );

			if ( empty( $post_ids ) ) {
				break;
			}

			// Fetch all relevant meta for this batch in one query.
			$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			$rows            = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id IN ($id_placeholders) AND meta_key IN ($placeholders)",
					array_merge( array_map( 'intval', $post_ids ), $keys )
				)
			);

			$grouped = array();
			foreach ( $rows as $row ) {
				$grouped[ (int) $row->post_id ][ $row->meta_key ] = $row->meta_value;
			}

			foreach ( $post_ids as $pid ) {
				$pid = (int) $pid;
				yield $pid => $grouped[ $pid ] ?? array();
			}

			$offset += self::BATCH_SIZE;
		}
	}

	/**
	 * Public accessor for last report (for the template).
	 */
	public function get_last_report(): array {
		$r = get_option( self::REPORT_OPTION, array() );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * Public accessor for whether undo is available.
	 */
	public function has_backup(): bool {
		$b = get_option( self::BACKUP_OPTION, array() );
		return is_array( $b ) && ! empty( $b );
	}
}
