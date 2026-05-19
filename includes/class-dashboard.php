<?php
/**
 * Dashboard page — landing surface for StrataWP SEO.
 *
 * Activity hub (KPI tiles, recent generations, queues, issues) above
 * the modules grid. Aggregates data from Audit, Cost Tracker, Analytics,
 * Generator, Internal Links, etc.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Dashboard {

	public function __construct() {
		// Hook earlier than SWPS_Settings (priority 10) so we register the
		// top-level menu first. Settings::register_menu() now skips the
		// top-level add_menu_page and only registers submenus.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Page-specific CSS/JS for the dashboard.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_stratawp-seo' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'swps-dashboard',
			SWPS_PLUGIN_URL . 'admin/css/pages/dashboard.css',
			array( 'swps-tokens', 'swps-shell', 'swps-components' ),
			SWPS_VERSION
		);
		wp_enqueue_script(
			'swps-dashboard',
			SWPS_PLUGIN_URL . 'admin/js/dashboard.js',
			array( 'swps-shell' ),
			SWPS_VERSION,
			true
		);
	}

	/**
	 * Register the top-level menu and Dashboard submenu.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'StrataWP SEO', 'stratawp-seo' ),
			__( 'StrataWP SEO', 'stratawp-seo' ),
			'manage_options',
			'stratawp-seo',
			array( $this, 'render' ),
			'dashicons-superhero-alt',
			30
		);

		add_submenu_page(
			'stratawp-seo',
			__( 'Dashboard', 'stratawp-seo' ),
			__( 'Dashboard', 'stratawp-seo' ),
			'manage_options',
			'stratawp-seo',
			array( $this, 'render' )
		);

		// Auto-Optimize and Competitors register their own submenus
		// (SWPS_Auto_Optimize, SWPS_Competitors).
	}

	/**
	 * Render the dashboard page.
	 */
	public function render(): void {
		$data = $this->get_summary_data();
		require SWPS_PLUGIN_DIR . 'templates/dashboard-page.php';
	}

	/**
	 * Build everything the dashboard template needs.
	 *
	 * Each piece is wrapped in try/catch so a single subsystem error
	 * doesn't blank the whole page.
	 *
	 * @return array
	 */
	public function get_summary_data(): array {
		$current_user = wp_get_current_user();

		return array(
			'first_name'          => $current_user->first_name ?: $current_user->display_name,
			'site_health'         => $this->safe_get_health(),
			'recent_generations'  => $this->safe_get_recent_generations(),
			'ai_cost_30d'         => $this->safe_get_ai_cost(),
			'posts_30d'           => $this->safe_get_post_views(),
			'top_issues'          => $this->safe_get_top_issues(),
			'top_queries'         => $this->safe_get_top_gsc_queries(),
			'auto_optimize_queue' => array(), // Phase 7 fills in.
			'competitors'         => $this->safe_get_competitor_summary(),
			'backlinks'           => $this->safe_get_backlinks_summary(),
			'modules'             => $this->get_modules_for_grid(),
		);
	}

	private function safe_get_health(): array {
		try {
			$audit    = stratawp_seo()->seo_audit;
			$results  = $audit->get_cached_results();
			$last_run = $audit->get_last_run();
			$score    = (int) ( $results['overall_score'] ?? 0 );

			$crit_count = 0;
			$warn_count = 0;
			$pass_count = 0;
			foreach ( ( $results['modules'] ?? array() ) as $mod ) {
				$status = $mod['status'] ?? '';
				if ( 'critical' === $status ) {
					++$crit_count;
				} elseif ( 'warning' === $status ) {
					++$warn_count;
				} else {
					++$pass_count;
				}
			}

			$label = 'Needs work';
			if ( $score >= 80 ) {
				$label = 'Healthy';
			} elseif ( $score >= 50 ) {
				$label = 'Fair';
			}

			return array(
				'score'      => $score,
				'label'      => $label,
				'crit_count' => $crit_count,
				'warn_count' => $warn_count,
				'pass_count' => $pass_count,
				'last_run'   => $last_run,
			);
		} catch ( \Throwable $e ) {
			return array(
				'score'      => 0,
				'label'      => '—',
				'crit_count' => 0,
				'warn_count' => 0,
				'pass_count' => 0,
				'last_run'   => 0,
			);
		}
	}

	private function safe_get_recent_generations(): array {
		try {
			$posts = get_posts(
				array(
					'numberposts' => 3,
					'post_status' => array( 'publish', 'draft', 'future', 'pending' ),
					'meta_key'    => '_swps_generated_at',
					'orderby'     => 'meta_value',
					'order'       => 'DESC',
				)
			);
			$out   = array();
			foreach ( $posts as $p ) {
				$out[] = array(
					'id'         => $p->ID,
					'title'      => $p->post_title,
					'template'   => get_post_meta( $p->ID, '_swps_template', true ) ?: 'auto',
					'word_count' => str_word_count( wp_strip_all_tags( $p->post_content ) ),
					'model'      => get_post_meta( $p->ID, '_swps_model', true ) ?: '',
					'score'      => (int) get_post_meta( $p->ID, '_swps_content_score', true ),
					'status'     => $p->post_status,
					'edit_link'  => get_edit_post_link( $p->ID ),
					'time_ago'   => human_time_diff( strtotime( $p->post_date_gmt ), time() ) . ' ago',
				);
			}
			return $out;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	private function safe_get_ai_cost(): array {
		try {
			$tracker = stratawp_seo()->cost_tracker;
			$stats   = $tracker->get_monthly_stats();
			return array(
				'usd'  => (float) ( $stats['total_cost'] ?? 0 ),
				'gens' => (int) ( $stats['total_generations'] ?? 0 ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'usd'  => 0.0,
				'gens' => 0,
			);
		}
	}

	private function safe_get_post_views(): array {
		try {
			global $wpdb;
			$table = $wpdb->prefix . 'swps_analytics';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				return array(
					'views'     => 0,
					'delta_pct' => null,
				);
			}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$views_30 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$views_60 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND viewed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
			$delta    = ( $views_60 > 0 ) ? round( ( ( $views_30 - $views_60 ) / $views_60 ) * 100, 1 ) : null;
			return array(
				'views'     => $views_30,
				'delta_pct' => $delta,
			);
		} catch ( \Throwable $e ) {
			return array(
				'views'     => 0,
				'delta_pct' => null,
			);
		}
	}

	private function safe_get_top_issues(): array {
		try {
			$audit   = stratawp_seo()->seo_audit;
			$results = $audit->get_cached_results();
			$issues  = array();
			foreach ( ( $results['modules'] ?? array() ) as $mod ) {
				$status = $mod['status'] ?? '';
				if ( 'critical' === $status || 'warning' === $status ) {
					$issues[] = array(
						'level'    => $status === 'critical' ? 'crit' : 'warn',
						'name'     => $mod['name'] ?? '',
						'message'  => $mod['message'] ?? '',
						'fixable'  => ! empty( $mod['fixable'] ),
						'page_url' => admin_url( 'admin.php?page=swps-seo-audit' ),
					);
				}
				if ( count( $issues ) >= 3 ) {
					break;
				}
			}
			return $issues;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	private function safe_get_top_gsc_queries(): array {
		try {
			$gsc = stratawp_seo()->search_console;
			if ( ! method_exists( $gsc, 'get_top_queries' ) ) {
				return array();
			}
			$rows = $gsc->get_top_queries( 7, 3 );
			return is_array( $rows ) ? $rows : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	private function safe_get_competitor_summary(): array {
		try {
			$competitors = stratawp_seo()->competitors;
			if ( ! method_exists( $competitors, 'get_dashboard_summary' ) ) {
				return array();
			}
			return $competitors->get_dashboard_summary( 3 );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	private function safe_get_backlinks_summary(): array {
		try {
			$bl = stratawp_seo()->backlinks;
			return array(
				'stats'  => $bl->get_stats(),
				'recent' => $bl->get_dashboard_recent( 3 ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'stats'  => array(
					'total'          => 0,
					'live'           => 0,
					'lost'           => 0,
					'broken'         => 0,
					'new_30d'        => 0,
					'lost_30d'       => 0,
					'unique_domains' => 0,
				),
				'recent' => array(),
			);
		}
	}

	/**
	 * Build the modules grid array for the dashboard template.
	 *
	 * Reads from SWPS_Modules registry — each entry includes the
	 * persisted on/off state.
	 */
	private function get_modules_for_grid(): array {
		$modules = stratawp_seo()->modules->get_all();
		$out     = array();
		foreach ( $modules as $slug => $mod ) {
			$out[] = array(
				'slug'         => $slug,
				'icon'         => $mod['icon'],
				'title'        => $mod['name'],
				'desc'         => $mod['desc'],
				'badge'        => $mod['badge'],
				'enabled'      => (bool) $mod['enabled'],
				'locked'       => ! empty( $mod['locked'] ),
				'settings_url' => $mod['settings_url'],
			);
		}
		return $out;
	}
}
