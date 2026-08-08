<?php
/**
 * Plugin Name: StrataWP SEO
 * Plugin URI: https://stratawpseo.com
 * Description: AI-powered SEO content generator that knows your WordPress site. Generate optimized blog posts with internal linking, on autopilot.
 * Version: 4.26.3
 * Author: Jon Imms
 * Author URI: https://jonimms.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stratawp-seo
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWPS_VERSION', '4.26.3' );
define( 'SWPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SWPS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload plugin classes.
 *
 * Base classes must load before providers; providers before factory;
 * factory before legacy aliases; legacy aliases before consumer classes.
 */

// Abstract base classes.
require_once SWPS_PLUGIN_DIR . 'includes/class-ai-provider.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-image-provider.php';

// Concrete AI providers.
require_once SWPS_PLUGIN_DIR . 'includes/providers/ai/class-anthropic-provider.php';
require_once SWPS_PLUGIN_DIR . 'includes/providers/ai/class-openai-provider.php';
require_once SWPS_PLUGIN_DIR . 'includes/providers/ai/class-google-provider.php';
require_once SWPS_PLUGIN_DIR . 'includes/providers/ai/class-xai-provider.php';

// Concrete image providers.
require_once SWPS_PLUGIN_DIR . 'includes/providers/images/class-unsplash-provider.php';
require_once SWPS_PLUGIN_DIR . 'includes/providers/images/class-pexels-provider.php';
require_once SWPS_PLUGIN_DIR . 'includes/providers/images/class-pixabay-provider.php';
require_once SWPS_PLUGIN_DIR . 'includes/providers/images/class-gemini-provider.php';

// Factory.
require_once SWPS_PLUGIN_DIR . 'includes/class-provider-factory.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-model-catalog.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-model-discovery.php';

// Legacy aliases (must load after concrete providers they extend).
require_once SWPS_PLUGIN_DIR . 'includes/class-api.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-images.php';

// v2.0 foundation classes (no dependencies on core classes).
require_once SWPS_PLUGIN_DIR . 'includes/class-encryption.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-cache-manager.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-hooks.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-templates.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-duplicate-checker.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-indexnow.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-cost-tracker.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-autopilot-guardian.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-digest.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-digest-settings.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-onboarding.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-topic-queue.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-content-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-voice-profile.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-image-inserter.php';

// SEO Audit classes.
require_once SWPS_PLUGIN_DIR . 'includes/class-audit-module.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-seo-audit.php';
require_once SWPS_PLUGIN_DIR . 'includes/audit/class-canonical-module.php';
require_once SWPS_PLUGIN_DIR . 'includes/audit/class-sitemap-module.php';
require_once SWPS_PLUGIN_DIR . 'includes/audit/class-opengraph-module.php';
require_once SWPS_PLUGIN_DIR . 'includes/audit/class-twitter-module.php';
require_once SWPS_PLUGIN_DIR . 'includes/audit/class-robots-module.php';
require_once SWPS_PLUGIN_DIR . 'includes/audit/class-meta-robots-module.php';
require_once SWPS_PLUGIN_DIR . 'includes/audit/class-image-seo-module.php';
require_once SWPS_PLUGIN_DIR . 'includes/audit/class-pagespeed-module.php';
require_once SWPS_PLUGIN_DIR . 'includes/audit/class-schema-audit-module.php';

// Schema structured data.
require_once SWPS_PLUGIN_DIR . 'includes/class-schema-validator.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-schema-graph.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-author-profile.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-schema.php';

// Analytics.
require_once SWPS_PLUGIN_DIR . 'includes/class-ai-referrals.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-ai-referrals-report.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-visibility-funnel.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-analytics-tracker.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-search-console.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-bot-analytics-tracker.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-analytics-dashboard.php';

// Keywords & Meta Editor.
require_once SWPS_PLUGIN_DIR . 'includes/class-keyword-tracker.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-citation-store.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-citation-prompts.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-citation-tracker.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-citation-admin.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-keywords-page.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-meta-editor.php';

// Auto-Optimize (v4.1).
require_once SWPS_PLUGIN_DIR . 'includes/class-auto-optimize.php';

// AEO Optimize (v4.6) — heuristic + AI scoring, dynamic schema generator.
require_once SWPS_PLUGIN_DIR . 'includes/aeo/class-extractability-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/aeo/class-markup-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/aeo/class-authority-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/aeo/class-coverage-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-aeo-scorer.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-question-coverage.php';

// Topic Autopilot (v4.19) — pure helpers + weekly scout cron.
require_once SWPS_PLUGIN_DIR . 'includes/class-topic-scout.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-topic-scout-cron.php';

require_once SWPS_PLUGIN_DIR . 'includes/class-metric-history.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-decay-watchdog.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-refresh-queue-admin.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-aeo-schema-generator.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-aeo-schema-migrator.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-aeo-optimizer.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-aeo-editor-panel.php';

// Competitors (v4.1).
require_once SWPS_PLUGIN_DIR . 'includes/class-competitors.php';

// Local SEO (v4.2).
require_once SWPS_PLUGIN_DIR . 'includes/class-local-seo.php';

// Image SEO (v4.2).
require_once SWPS_PLUGIN_DIR . 'includes/class-image-seo.php';

// Crawlers & Files (v4.2) — edit /llms.txt and /robots.txt.
require_once SWPS_PLUGIN_DIR . 'includes/class-crawl-files.php';

// Crawler verification (v4.19) — CIDR/rDNS-based search-bot hit verification.
require_once SWPS_PLUGIN_DIR . 'includes/class-crawler-verification.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-crawler-enforcement.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-crawl-budget-report.php';

// Site crawler (v4.19) — background broken-link / issues audit.
require_once SWPS_PLUGIN_DIR . 'includes/class-crawl-issues.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-site-crawler.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-site-crawl-admin.php';
require_once SWPS_PLUGIN_DIR . 'includes/audit/class-site-crawl-module.php';

// Backlinks (v4.2.2) — manual/CSV-import backlink tracker with health monitor.
require_once SWPS_PLUGIN_DIR . 'includes/class-backlinks.php';

// Keyword cannibalization detector (v4.19).
require_once SWPS_PLUGIN_DIR . 'includes/class-cannibalization.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-cannibalization-admin.php';

// v3.0 classes.
require_once SWPS_PLUGIN_DIR . 'includes/class-head-cleanup.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-rss-optimizer.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-taxonomy-meta.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-sitemap-manager.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-search-appearance.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-breadcrumbs.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-redirect-manager.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-redirect-admin.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-sitemap-admin.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-post-list-seo.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-link-keyword-engine.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-link-ai-engine.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-cross-site-links.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-internal-links.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-internal-links-admin.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-ai-bots.php';

// Migration tool (Yoast / Rank Math import).
require_once SWPS_PLUGIN_DIR . 'includes/class-migration.php';

// Core classes.
require_once SWPS_PLUGIN_DIR . 'includes/class-settings.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-analyzer.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-generator.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-cron.php';

// v2.0 classes that depend on core.
require_once SWPS_PLUGIN_DIR . 'includes/class-calendar.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-background-processor.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-model-cron.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once SWPS_PLUGIN_DIR . 'includes/trait-ability-defs.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-abilities.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-abilities-rest.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-abilities-settings.php';

// GitHub-based plugin updater. Temporary: re-enabled so users get update
// notifications until the plugin is live on the WordPress.org directory, at
// which point this self-hosted updater must be removed again (.org prohibits
// plugins updating themselves outside the official directory).
require_once SWPS_PLUGIN_DIR . 'includes/class-github-updater.php';

// v4.0 admin shell.
require_once SWPS_PLUGIN_DIR . 'includes/class-user-prefs.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-modules.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-admin-shell.php';
require_once SWPS_PLUGIN_DIR . 'includes/class-dashboard.php';

// WP-CLI commands (only when CLI is available).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SWPS_PLUGIN_DIR . 'includes/class-cli.php';
}

/**
 * Main plugin class.
 */
final class StrataWP_SEO {

	private static ?StrataWP_SEO $instance = null;

	public SWPS_Settings $settings;
	public SWPS_Generator $generator;
	public SWPS_Cron $cron;
	public SWPS_Analyzer $analyzer;
	public SWPS_AI_Provider $api;
	public SWPS_Image_Provider $images;

	// v2.0 subsystems.
	public SWPS_Cache_Manager $cache_manager;
	public SWPS_Duplicate_Checker $duplicate_checker;
	public SWPS_Rate_Limiter $rate_limiter;
	public SWPS_Cost_Tracker $cost_tracker;
	public SWPS_Topic_Queue $topic_queue;
	public SWPS_Calendar $calendar;
	public SWPS_Background_Processor $background_processor;
	public SWPS_REST_API $rest_api;
	public SWPS_Content_Scorer $content_scorer;
	public SWPS_Voice_Profile $voice_profile;
	public SWPS_Image_Inserter $image_inserter;
	public SWPS_SEO_Audit $seo_audit;
	public SWPS_Schema $schema;
	public SWPS_Analytics_Tracker $analytics_tracker;
	public SWPS_Bot_Analytics_Tracker $bot_analytics_tracker;
	public SWPS_Search_Console $search_console;
	public SWPS_Analytics_Dashboard $analytics_dashboard;
	public SWPS_Keyword_Tracker $keyword_tracker;
	/**
	 * AI citation tracker.
	 *
	 * @var SWPS_Citation_Tracker
	 */
	public SWPS_Citation_Tracker $citation_tracker;

	/**
	 * AI citation tracker admin (AJAX + settings).
	 *
	 * @var SWPS_Citation_Admin
	 */
	public SWPS_Citation_Admin $citation_admin;
	public SWPS_Keywords_Page $keywords_page;
	public SWPS_Meta_Editor $meta_editor;
	public SWPS_Head_Cleanup $head_cleanup;
	public SWPS_RSS_Optimizer $rss_optimizer;
	public SWPS_Taxonomy_Meta $taxonomy_meta;
	public SWPS_Sitemap_Manager $sitemap_manager;
	public SWPS_Search_Appearance $search_appearance;
	public SWPS_Breadcrumbs $breadcrumbs;
	public SWPS_Redirect_Manager $redirect_manager;
	public SWPS_Post_List_SEO $post_list_seo;
	public SWPS_Sitemap_Admin $sitemap_admin;
	public SWPS_IndexNow $indexnow;
	public SWPS_Internal_Links $internal_links;
	public SWPS_Internal_Links_Admin $internal_links_admin;
	public SWPS_AI_Bots $ai_bots;
	public SWPS_Auto_Optimize $auto_optimize;
	public SWPS_Competitors $competitors;
	public SWPS_Local_SEO $local_seo;
	public SWPS_Image_SEO $image_seo;
	public SWPS_Crawl_Files $crawl_files;
	public SWPS_Crawler_Verification $crawler_verification;
	public SWPS_Crawler_Enforcement  $crawler_enforcement;
	public SWPS_Crawl_Budget_Report  $crawl_budget_report;
	public SWPS_Site_Crawler $site_crawler;
	public SWPS_Site_Crawl_Admin $site_crawl_admin;
	public SWPS_Backlinks $backlinks;
	public SWPS_Autopilot_Guardian $autopilot_guardian;
	public SWPS_Digest $digest;
	public SWPS_Digest_Settings $digest_settings;
	public SWPS_Onboarding $onboarding;

	// AEO Optimize (v4.6).
	public SWPS_AEO_Scorer            $aeo_scorer;
	public SWPS_AEO_Schema_Generator  $aeo_schema_gen;
	public SWPS_AEO_Optimizer         $aeo_optimizer;
	public SWPS_AEO_Editor_Panel      $aeo_editor_panel;

	/**
	 * Question coverage engine (v4.17) — GSC question demand mining.
	 *
	 * @var SWPS_Question_Coverage
	 */
	public SWPS_Question_Coverage $question_coverage;

	/**
	 * Topic Scout cron + settings wiring (v4.19).
	 *
	 * @var SWPS_Topic_Scout_Cron
	 */
	public SWPS_Topic_Scout_Cron $topic_scout_cron;

	/**
	 * Generic per-post metric history store (v4.18).
	 *
	 * @var SWPS_Metric_History
	 */
	public SWPS_Metric_History $metric_history;

	/**
	 * Content decay watchdog — weekly cron, detection, and email alert (v4.18).
	 *
	 * @var SWPS_Decay_Watchdog
	 */
	public SWPS_Decay_Watchdog $decay_watchdog;
	public SWPS_Refresh_Queue_Admin $refresh_queue_admin;

	/**
	 * Machine-callable abilities registry (v4.19).
	 *
	 * @var SWPS_Abilities
	 */
	public SWPS_Abilities $abilities;

	/**
	 * Keyword cannibalization detector (v4.19).
	 *
	 * @var SWPS_Cannibalization
	 */
	public SWPS_Cannibalization $cannibalization;

	/**
	 * Cannibalization admin page + AJAX (v4.19).
	 *
	 * @var SWPS_Cannibalization_Admin
	 */
	public SWPS_Cannibalization_Admin $cannibalization_admin;

	// v4.0 admin shell.
	public SWPS_User_Prefs $user_prefs;
	public SWPS_Modules $modules;
	public SWPS_Admin_Shell $admin_shell;
	public SWPS_Dashboard $dashboard;
	public SWPS_Migration $migration;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->maybe_migrate_legacy_options();
		$this->maybe_migrate_qapage_to_faqpage();

		// Initialize foundation subsystems.
		$this->cache_manager     = new SWPS_Cache_Manager();
		$this->duplicate_checker = new SWPS_Duplicate_Checker();
		$this->rate_limiter      = new SWPS_Rate_Limiter();
		$this->cost_tracker      = new SWPS_Cost_Tracker();
		$this->topic_queue       = new SWPS_Topic_Queue();
		$this->content_scorer    = new SWPS_Content_Scorer();
		$this->voice_profile     = new SWPS_Voice_Profile();

		// Initialize providers and core.
		$this->api                   = SWPS_Provider_Factory::create_ai_provider();
		$this->images                = SWPS_Provider_Factory::create_image_provider();
		$this->image_inserter        = new SWPS_Image_Inserter( $this->images );
		$this->seo_audit             = new SWPS_SEO_Audit();
		$this->schema                = new SWPS_Schema();
		SWPS_AI_Referrals::maybe_upgrade();
		SWPS_Citation_Store::maybe_upgrade();
		$this->analytics_tracker     = new SWPS_Analytics_Tracker();
		$this->bot_analytics_tracker = new SWPS_Bot_Analytics_Tracker();
		$this->search_console        = new SWPS_Search_Console();
		$this->analytics_dashboard   = new SWPS_Analytics_Dashboard( $this->analytics_tracker, $this->search_console );
		$this->keyword_tracker       = new SWPS_Keyword_Tracker( $this->search_console );
		$this->citation_tracker      = new SWPS_Citation_Tracker( new SWPS_Citation_Prompts( $this->keyword_tracker, $this->search_console ) );
		$this->citation_admin        = new SWPS_Citation_Admin( $this->citation_tracker );
		$this->keywords_page         = new SWPS_Keywords_Page( $this->keyword_tracker );
		$this->meta_editor           = new SWPS_Meta_Editor();
		$this->head_cleanup          = new SWPS_Head_Cleanup();
		$this->rss_optimizer         = new SWPS_RSS_Optimizer();
		$this->taxonomy_meta         = new SWPS_Taxonomy_Meta();
		$this->sitemap_manager       = new SWPS_Sitemap_Manager();
		$this->search_appearance     = new SWPS_Search_Appearance();
		$this->breadcrumbs           = new SWPS_Breadcrumbs();
		$this->redirect_manager      = new SWPS_Redirect_Manager();
		$this->sitemap_admin         = new SWPS_Sitemap_Admin();
		$this->indexnow              = new SWPS_IndexNow();

		if ( is_admin() ) {
			$this->post_list_seo = new SWPS_Post_List_SEO( $this->content_scorer );
		}
		$link_keyword_engine = new SWPS_Link_Keyword_Engine();
		$link_ai_engine      = new SWPS_Link_AI_Engine( $this->api, $this->cost_tracker );
		$cross_site_links    = new SWPS_Cross_Site_Links();
		$cross_site_links->register_hooks();
		$this->internal_links       = new SWPS_Internal_Links( $link_keyword_engine, $link_ai_engine, $cross_site_links );
		$this->internal_links_admin = new SWPS_Internal_Links_Admin( $this->internal_links );
		$this->ai_bots              = new SWPS_AI_Bots();
		$this->auto_optimize        = new SWPS_Auto_Optimize( $this->content_scorer );

		// AEO Optimize (v4.6).
		$aeo_extractability      = new SWPS_AEO_Extractability_Scorer();
		$aeo_markup              = new SWPS_AEO_Markup_Scorer();
		$aeo_authority           = new SWPS_AEO_Authority_Scorer();
		$aeo_coverage            = new SWPS_AEO_Coverage_Scorer( $this->api );
		$this->aeo_scorer        = new SWPS_AEO_Scorer( $aeo_extractability, $aeo_markup, $aeo_authority, $aeo_coverage );
		$this->aeo_schema_gen    = new SWPS_AEO_Schema_Generator(
			$this->api,
			SWPS_PLUGIN_DIR . 'includes/data/aeo-schema-fields.json'
		);
		$this->aeo_optimizer     = new SWPS_AEO_Optimizer(
			$this->aeo_scorer,
			$this->aeo_schema_gen,
			$this->api,
			$this->cost_tracker,
			$this->rate_limiter
		);
		$this->aeo_editor_panel  = new SWPS_AEO_Editor_Panel( $this->aeo_scorer );

		// Question coverage engine (v4.17) — weekly GSC question demand mining.
		$this->question_coverage = new SWPS_Question_Coverage( $this->search_console, $this->topic_queue );

		// Topic Autopilot (v4.19) — weekly scout cron + settings.
		$this->topic_scout_cron = new SWPS_Topic_Scout_Cron(
			$this->search_console,
			$this->topic_queue,
			$this->duplicate_checker
		);

		// Content decay watchdog (v4.18) — weekly scan, metric history, email alert.
		SWPS_Metric_History::maybe_upgrade();
		$this->metric_history  = new SWPS_Metric_History();
		$this->decay_watchdog  = new SWPS_Decay_Watchdog( $this->search_console, $this->metric_history );
		$this->refresh_queue_admin   = new SWPS_Refresh_Queue_Admin( $this->metric_history );

		// Keyword cannibalization detector (v4.19).
		SWPS_Cannibalization::maybe_upgrade();
		$this->cannibalization       = new SWPS_Cannibalization( $this->search_console );
		$this->cannibalization_admin = new SWPS_Cannibalization_Admin( $this->cannibalization );

		$this->competitors          = new SWPS_Competitors();
		$this->local_seo            = new SWPS_Local_SEO();
		$this->image_seo            = new SWPS_Image_SEO();
		$this->crawl_files            = new SWPS_Crawl_Files();
		SWPS_Bot_Analytics_Tracker::maybe_upgrade();
		$this->crawler_verification = new SWPS_Crawler_Verification();
		$this->crawler_enforcement  = new SWPS_Crawler_Enforcement();
		$this->crawl_budget_report  = new SWPS_Crawl_Budget_Report();
		SWPS_Crawl_Issues::maybe_upgrade();
		$this->site_crawler         = new SWPS_Site_Crawler();
		$this->site_crawl_admin     = new SWPS_Site_Crawl_Admin( $this->site_crawler );
		add_filter( 'swps_audit_modules', array( 'SWPS_Site_Crawl_Module', 'register' ) );
		add_filter( 'swps_audit_modules', array( 'SWPS_Schema_Audit_Module', 'register' ) );
		$this->backlinks            = new SWPS_Backlinks();
		$this->settings             = new SWPS_Settings();
		$this->analyzer             = new SWPS_Analyzer( $this->cache_manager );
		$this->generator            = new SWPS_Generator(
			$this->api,
			$this->analyzer,
			$this->duplicate_checker,
			$this->rate_limiter,
			$this->cost_tracker
		);
		$this->cron                 = new SWPS_Cron( $this->generator, $this->topic_queue );
		$this->autopilot_guardian   = new SWPS_Autopilot_Guardian();
		$this->digest               = new SWPS_Digest();
		$this->digest_settings      = new SWPS_Digest_Settings( $this->digest );
		$this->onboarding           = new SWPS_Onboarding();

		// Initialize v2.0 subsystems.
		$this->calendar             = new SWPS_Calendar( $this->topic_queue );
		$this->background_processor = new SWPS_Background_Processor();
		$discovery                  = new SWPS_Model_Discovery();
		new SWPS_Model_Cron( $discovery );
		$this->rest_api             = new SWPS_REST_API();

		// Abilities API — machine-callable SEO operations (v4.19).
		SWPS_Abilities::maybe_upgrade();
		$this->abilities = new SWPS_Abilities();
		new SWPS_Abilities_Rest( $this->abilities );
		new SWPS_Abilities_Settings( $this->abilities );

		// Author E-E-A-T profile fields (v4.19).
		( new SWPS_Author_Profile() )->register_hooks();

		// v4.0 admin shell — only relevant in admin, but instantiate always so REST routes register.
		$this->user_prefs  = new SWPS_User_Prefs();
		$this->modules     = new SWPS_Modules();
		$this->admin_shell = new SWPS_Admin_Shell( $this->user_prefs );
		$this->dashboard   = new SWPS_Dashboard();
		$this->migration   = new SWPS_Migration();

		// GitHub release-based updater (admin only). Temporary until the plugin
		// ships through the WordPress.org directory — see the require above.
		if ( is_admin() ) {
			new SWPS_GitHub_Updater( __FILE__, SWPS_VERSION );
		}

		// Register CPT.
		add_action( 'init', array( SWPS_Topic_Queue::class, 'register_post_type' ) );

		// Redirect 404 log pruning cron.
		add_action( 'swps_prune_404_logs', array( SWPS_Redirect_Manager::class, 'prune_404_logs' ) );

		// Ability activity-log pruning (90 days) rides the same daily cron.
		add_action( 'swps_prune_404_logs', array( SWPS_Abilities::class, 'prune_log' ) );

		// Admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// Admin notices.
		add_action( 'admin_notices', array( $this, 'model_alert_notice' ) );

		// AJAX handlers — original.
		add_action( 'wp_ajax_swps_generate_post', array( $this, 'ajax_generate_post' ) );
		add_action( 'wp_ajax_swps_analyze_site', array( $this, 'ajax_analyze_site' ) );
		add_action( 'wp_ajax_swps_get_models', array( $this, 'ajax_get_models' ) );

		// AJAX handlers — model alert dismiss.
		add_action( 'wp_ajax_swps_dismiss_model_alert', array( $this, 'ajax_dismiss_model_alert' ) );

		// AJAX handlers — v2.0.
		add_action( 'wp_ajax_swps_bulk_generate', array( $this, 'ajax_bulk_generate' ) );
		add_action( 'wp_ajax_swps_preview_post', array( $this, 'ajax_preview_post' ) );
		add_action( 'wp_ajax_swps_clear_cache', array( $this, 'ajax_clear_cache' ) );

		// Frontend — FAQ / Takeaways schema.
		// When the StrataWP schema module is active (no other SEO plugin), these nodes
		// are absorbed into the @graph by SWPS_Schema. Register the standalone hooks
		// ONLY when the main schema is deferred (Yoast / RankMath / AIOSEO active) so
		// emission is exactly once regardless of which path handles it.
		if ( SWPS_Schema::is_main_schema_deferred() ) {
			add_action( 'wp_head', array( $this, 'output_faq_schema' ) );
			add_action( 'wp_head', array( $this, 'output_takeaways_schema' ) );
		}

		// Content scoring on post creation.
		add_action( 'swps_post_created', array( $this, 'score_generated_post' ), 10, 3 );

		// Voice profile AJAX.
		add_action( 'wp_ajax_swps_save_voice_profile', array( $this, 'ajax_save_voice_profile' ) );
		add_action( 'wp_ajax_swps_delete_voice_profile', array( $this, 'ajax_delete_voice_profile' ) );

		// In-content image insertion on post creation.
		add_action( 'swps_post_created', array( $this, 'schedule_image_jobs' ), 20, 3 );

		// SEO Audit frontend hooks.
		add_action( 'wp_head', array( SWPS_Canonical_Module::class, 'output_canonical' ), 1 );
		add_action( 'wp_head', array( SWPS_OpenGraph_Module::class, 'output_meta_tags' ), 5 );
		// Disable audit OG output when meta editor handles it.
		if ( get_option( 'swps_meta_editor_enabled', 1 ) && ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'AIOSEO_VERSION' ) ) {
			remove_action( 'wp_head', array( SWPS_OpenGraph_Module::class, 'output_meta_tags' ), 5 );
		}
		// Sitemap generation, serving, and pinging are handled by SWPS_Sitemap_Manager.
		add_filter( 'robots_txt', array( SWPS_Robots_Module::class, 'filter_robots_txt' ), 10, 2 );

		// SEO Audit AJAX handlers.
		add_action( 'wp_ajax_swps_run_audit', array( $this, 'ajax_run_audit' ) );
		add_action( 'wp_ajax_swps_get_audit_results', array( $this, 'ajax_get_audit_results' ) );
		add_action( 'wp_ajax_swps_fix_module', array( $this, 'ajax_fix_module' ) );
		add_action( 'wp_ajax_swps_fix_all', array( $this, 'ajax_fix_all' ) );

		// Dashboard widget.
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );

		// Plugins page link.
		add_filter( 'plugin_action_links_' . SWPS_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );

		// WP-CLI registration.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'swps', 'SWPS_CLI' );
		}
	}

	/**
	 * Migrate legacy option names for existing installs.
	 */
	private function maybe_migrate_legacy_options(): void {
		if ( get_option( 'swps_provider_migrated' ) ) {
			return;
		}

		$legacy_key = get_option( 'swps_api_key', '' );
		if ( ! empty( $legacy_key ) && empty( get_option( 'swps_anthropic_api_key', '' ) ) ) {
			update_option( 'swps_anthropic_api_key', $legacy_key );
		}

		if ( false === get_option( 'swps_ai_provider' ) ) {
			update_option( 'swps_ai_provider', 'anthropic' );
		}
		if ( false === get_option( 'swps_image_provider' ) ) {
			update_option( 'swps_image_provider', 'unsplash' );
		}

		update_option( 'swps_provider_migrated', 1 );
	}

	/**
	 * One-time migration: rewrite legacy QAPage AEO schema as FAQPage (issue #44).
	 *
	 * QAPage emitted for site-authored Q&A content trips Google Search Console.
	 * The generator now emits FAQPage; this fixes pages optimized before the
	 * change, whose QAPage is frozen into post meta. Idempotent and gated on a
	 * flag so it runs once after the update. Can be re-run manually via
	 * `wp swps migrate-qapage`.
	 */
	private function maybe_migrate_qapage_to_faqpage(): void {
		if ( get_option( 'swps_qapage_faqpage_migrated' ) ) {
			return;
		}
		// Frontend page loads shouldn't pay for a full-table scan; defer to
		// admin/CLI where the update is actually triggered.
		if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		SWPS_AEO_Schema_Migrator::migrate_all();
		update_option( 'swps_qapage_faqpage_migrated', 1 );
	}

	/**
	 * Enqueue admin CSS and JS on our pages only.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		// Load only CSS on the Dashboard (for the audit widget).
		if ( 'index.php' === $hook ) {
			wp_enqueue_style( 'swps-admin', SWPS_PLUGIN_URL . 'admin/css/admin.css', array(), SWPS_VERSION );
			return;
		}

		if ( ! str_contains( $hook, 'stratawp-seo' ) && ! str_contains( $hook, 'swps-generate' ) && ! str_contains( $hook, 'swps-voice-profiles' ) && ! str_contains( $hook, 'swps-seo-audit' ) && ! str_contains( $hook, 'swps-analytics' ) && ! str_contains( $hook, 'swps-keywords' ) && ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'swps-admin',
			SWPS_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			SWPS_VERSION
		);

		wp_enqueue_script(
			'swps-admin',
			SWPS_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			SWPS_VERSION,
			true
		);

		wp_localize_script(
			'swps-admin',
			'swpsAdmin',
			array(
				'ajax_url'             => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'swps_nonce' ),
				'current_ai_provider'  => get_option( 'swps_ai_provider', 'anthropic' ),
				'current_model'        => get_option( 'swps_model', '' ),
				'templates'            => SWPS_Templates::get_options(),
				'rate_limit_remaining' => $this->rate_limiter->get_remaining_seconds(),
				'min_content_score'    => get_option( 'swps_min_content_score', 0 ),
				'generate_url'         => admin_url( 'admin.php?page=swps-generate' ),
			)
		);

		// Analytics dashboard JS.
		if ( str_contains( $hook, 'swps-analytics' ) || in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			if ( str_contains( $hook, 'swps-analytics' ) ) {
				wp_enqueue_script(
					'chartjs',
					'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js',
					array(),
					'4.4.7',
					true
				);
			}
			wp_enqueue_script(
				'swps-analytics',
				SWPS_PLUGIN_URL . 'admin/js/analytics.js',
				str_contains( $hook, 'swps-analytics' ) ? array( 'jquery', 'swps-admin', 'chartjs' ) : array( 'jquery', 'swps-admin' ),
				SWPS_VERSION,
				true
			);
		}

		// Keywords page JS.
		if ( str_contains( $hook, 'swps-keywords' ) ) {
			wp_enqueue_script(
				'swps-keywords',
				SWPS_PLUGIN_URL . 'admin/js/keywords.js',
				array( 'jquery', 'swps-admin' ),
				SWPS_VERSION,
				true
			);
		}

		// Meta editor JS on post edit screens.
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_script(
				'swps-meta-editor',
				SWPS_PLUGIN_URL . 'admin/js/meta-editor.js',
				array( 'jquery', 'swps-admin' ),
				SWPS_VERSION,
				true
			);
			wp_enqueue_script( 'swps-indexnow', SWPS_PLUGIN_URL . 'admin/js/indexnow.js', array( 'swps-admin', 'jquery' ), SWPS_VERSION, true );
		}

		// Search Appearance page JS.
		if ( 'stratawp-seo_page_swps-search-appearance' === $hook ) {
			wp_enqueue_script( 'swps-search-appearance', SWPS_PLUGIN_URL . 'admin/js/search-appearance.js', array(), SWPS_VERSION, true );
		}

		// Redirects page JS.
		if ( 'stratawp-seo_page_swps-redirects' === $hook ) {
			wp_enqueue_script( 'swps-redirects', SWPS_PLUGIN_URL . 'admin/js/redirects.js', array( 'swps-admin' ), SWPS_VERSION, true );
		}

		if ( 'stratawp-seo_page_swps-sitemaps' === $hook ) {
			wp_enqueue_script( 'swps-sitemaps', SWPS_PLUGIN_URL . 'admin/js/sitemaps.js', array( 'swps-admin' ), SWPS_VERSION, true );
			wp_enqueue_script( 'swps-indexnow', SWPS_PLUGIN_URL . 'admin/js/indexnow.js', array( 'swps-admin', 'jquery' ), SWPS_VERSION, true );
		}
	}

	/**
	 * Enqueue frontend CSS on single posts.
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		wp_enqueue_style(
			'swps-frontend',
			SWPS_PLUGIN_URL . 'css/frontend.css',
			array(),
			SWPS_VERSION
		);
	}

	/**
	 * AJAX handler: Generate a single post.
	 */
	public function ajax_generate_post(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$topic    = sanitize_text_field( $_POST['topic'] ?? '' );
		$template = sanitize_text_field( $_POST['template'] ?? 'auto' );

		$result = $this->generator->generate_post( $topic, $template );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Bulk generate posts.
	 */
	public function ajax_bulk_generate(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$count    = min( (int) ( $_POST['count'] ?? 1 ), 5 );
		$template = sanitize_text_field( $_POST['template'] ?? 'auto' );
		$results  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$result = $this->generator->generate_post( '', $template );

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'success' => false,
					'message' => $result->get_error_message(),
				);
				break;
			}

			$results[] = array(
				'success' => true,
				'data'    => $result,
			);

			if ( $i < $count - 1 ) {
				sleep( 5 );
			}
		}

		wp_send_json_success(
			array(
				'results'   => $results,
				'completed' => count( array_filter( $results, fn( $r ) => $r['success'] ) ),
				'total'     => $count,
			)
		);
	}

	/**
	 * AJAX handler: Preview post content without publishing.
	 */
	public function ajax_preview_post(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$topic    = sanitize_text_field( $_POST['topic'] ?? '' );
		$template = sanitize_text_field( $_POST['template'] ?? 'auto' );

		$result = $this->generator->preview_content( $topic, $template );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Clear analysis cache.
	 */
	public function ajax_clear_cache(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$this->cache_manager->invalidate_all();

		wp_send_json_success( array( 'message' => 'Cache cleared.' ) );
	}

	/**
	 * AJAX handler: Analyze site content.
	 */
	public function ajax_analyze_site(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$analysis = $this->analyzer->get_site_summary();

		wp_send_json_success( $analysis );
	}

	/**
	 * AJAX handler: Get models for a given AI provider slug.
	 */
	public function ajax_get_models(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$provider = sanitize_text_field( $_POST['provider'] ?? '' );
		$models   = SWPS_Provider_Factory::get_models_for_provider( $provider );

		wp_send_json_success( $models );
	}

	/**
	 * Output FAQ schema JSON-LD in <head> for generated posts.
	 */
	public function output_faq_schema(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$schema = get_post_meta( get_the_ID(), '_swps_faq_schema', true );

		$schema = $this->normalize_faq_schema_meta( $schema );

		if ( empty( $schema ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD encoded from sanitized schema array.
		echo '<script type="application/ld+json">'
			. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
	}

	/**
	 * Normalize legacy FAQ schema meta into a safe schema array.
	 *
	 * Delegates to SWPS_Schema_Graph::normalize_faq_schema() so both the
	 * standalone Yoast-deferred path (here) and the @graph path (SWPS_Schema)
	 * share one implementation.
	 *
	 * @param mixed $schema Raw value from get_post_meta.
	 * @return array Normalised FAQPage schema array, or empty array.
	 */
	private function normalize_faq_schema_meta( mixed $schema ): array {
		return SWPS_Schema_Graph::normalize_faq_schema( $schema );
	}

	/**
	 * Output Key Takeaways ItemList schema JSON-LD in <head>.
	 */
	public function output_takeaways_schema(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		if ( ! get_option( 'swps_takeaways_schema', 1 ) ) {
			return;
		}

		$takeaways = get_post_meta( get_the_ID(), '_swps_key_takeaways', true );
		if ( empty( $takeaways ) || ! is_array( $takeaways ) ) {
			return;
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => __( 'Key Takeaways', 'stratawp-seo' ),
			'numberOfItems'   => count( $takeaways ),
			'itemListElement' => array(),
		);

		foreach ( $takeaways as $index => $takeaway ) {
			$schema['itemListElement'][] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $takeaway,
			);
		}

		$schema = SWPS_Hooks::filter_takeaways_schema( $schema, $takeaways );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built JSON-LD script tag.
		echo '<script type="application/ld+json">'
			. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
	}

	/**
	 * Add settings link on plugins list page.
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=stratawp-seo' ) . '">' . __( 'Settings', 'stratawp-seo' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Score a generated post and store results.
	 *
	 * @param int   $post_id   The new post ID.
	 * @param array $ai_result The AI response data.
	 * @param array $post_data The WordPress post data.
	 */
	public function score_generated_post( int $post_id, array $ai_result, array $post_data ): void {
		$results = $this->content_scorer->score( $post_id, $ai_result );

		update_post_meta( $post_id, '_swps_content_score', $results['overall_score'] );
		update_post_meta( $post_id, '_swps_score_details', $results['details'] );
		update_post_meta( $post_id, '_swps_score_recommendations', $results['recommendations'] );

		// Optional score gate: force draft if below threshold.
		$min_score = (int) get_option( 'swps_min_content_score', 0 );
		if ( $min_score > 0 && $results['overall_score'] < $min_score ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
			update_post_meta( $post_id, '_swps_score_blocked', true );
		}

		SWPS_Hooks::do_score_complete( $results, $post_id );
	}

	/**
	 * Enqueue background image-generation jobs for a freshly generated post.
	 *
	 * Featured + in-content images run as separate short requests so a slow
	 * provider (Gemini) can't blow the cron request's execution limit and
	 * leave the post imageless.
	 *
	 * @param int   $post_id   The new post ID.
	 * @param array $ai_result The AI response data.
	 * @param array $post_data The WordPress post data.
	 */
	public function schedule_image_jobs( int $post_id, array $ai_result, array $post_data ): void {
		if ( get_option( 'swps_featured_images', 1 ) ) {
			$focus = (string) get_post_meta( $post_id, '_swps_focus_keyword', true );
			$query = '' !== $focus ? $focus : (string) ( $ai_result['title'] ?? '' );
			$query = SWPS_Hooks::filter_image_query( $query, $post_id );
			$this->background_processor->schedule_featured_image( $post_id, $query );
		}

		if ( get_option( 'swps_insert_content_images', 0 ) ) {
			$this->background_processor->schedule_content_image( $post_id );
		}
	}

	/**
	 * AJAX: Save (create or update) a voice profile.
	 */
	public function ajax_save_voice_profile(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$profile_id = absint( $_POST['profile_id'] ?? 0 );
		$name       = sanitize_text_field( $_POST['name'] ?? '' );

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => 'Profile name is required.' ) );
		}

		$meta = array(
			'tone'              => sanitize_text_field( $_POST['tone'] ?? 'professional' ),
			'formality'         => absint( $_POST['formality'] ?? 5 ),
			'sentence_length'   => sanitize_text_field( $_POST['sentence_length'] ?? 'varied' ),
			'vocabulary_level'  => sanitize_text_field( $_POST['vocabulary_level'] ?? 'moderate' ),
			'person'            => sanitize_text_field( $_POST['person'] ?? 'second' ),
			'example_content'   => sanitize_textarea_field( $_POST['example_content'] ?? '' ),
			'avoid_phrases'     => sanitize_textarea_field( $_POST['avoid_phrases'] ?? '' ),
			'preferred_phrases' => sanitize_textarea_field( $_POST['preferred_phrases'] ?? '' ),
		);

		if ( $profile_id > 0 ) {
			$result = $this->voice_profile->update( $profile_id, $name, $meta );
		} else {
			$result = $this->voice_profile->create( $name, $meta );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'profile_id' => $result ) );
	}

	/**
	 * AJAX: Delete a voice profile.
	 */
	public function ajax_delete_voice_profile(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$profile_id = absint( $_POST['profile_id'] ?? 0 );
		$this->voice_profile->delete( $profile_id );

		wp_send_json_success();
	}

	/**
	 * Register the SEO Audit dashboard widget.
	 */
	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'swps_audit_widget',
			__( 'StrataWP SEO — Site Health', 'stratawp-seo' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the SEO Audit dashboard widget.
	 */
	public function render_dashboard_widget(): void {
		$results  = $this->seo_audit->get_cached_results();
		$last_run = $this->seo_audit->get_last_run();
		$modules  = $this->seo_audit->get_modules();
		$overall  = $results['overall_score'] ?? 0;

		$score_class = $overall >= 80 ? 'excellent' : ( $overall >= 50 ? 'good' : 'poor' );

		if ( empty( $results['modules'] ) ) {
			echo '<p>' . esc_html__( 'No audit results yet. Run your first audit.', 'stratawp-seo' ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=swps-seo-audit' ) ) . '" class="button button-primary">' . esc_html__( 'Run Audit', 'stratawp-seo' ) . '</a></p>';
			return;
		}

		printf(
			'<div class="swps-widget-score swps-score-badge swps-score-badge--%s" style="font-size:16px;padding:8px 16px;margin-bottom:12px;">%s: %d/100</div>',
			esc_attr( $score_class ),
			esc_html__( 'Site Health', 'stratawp-seo' ),
			(int) $overall
		);

		echo '<table class="widefat striped" style="margin-bottom:12px;">';
		echo '<tbody>';

		foreach ( $modules as $id => $module ) {
			$mod_result  = $results['modules'][ $id ] ?? null;
			$mod_status  = $mod_result['status'] ?? 'fail';
			$mod_score   = $mod_result['score'] ?? 0;
			$issue_count = count( $mod_result['issues'] ?? array() );

			$icon = match ( $mod_status ) {
				'pass'    => '<span style="color:#00a32a;">&#10003;</span>',
				'warning' => '<span style="color:#dba617;">&#9888;</span>',
				default   => '<span style="color:#d63638;">&#10007;</span>',
			};

			printf(
				'<tr><td>%s %s</td><td style="text-align:right;">%d/100</td><td style="text-align:right;color:#646970;">%d issues</td></tr>',
				$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $module->get_label() ),
				(int) $mod_score,
				$issue_count
			);
		}

		echo '</tbody></table>';

		printf(
			'<p><a href="%s" class="button">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=swps-seo-audit' ) ),
			esc_html__( 'View Details', 'stratawp-seo' )
		);

		if ( $last_run ) {
			printf(
				'<p class="description">%s %s</p>',
				esc_html__( 'Last audited:', 'stratawp-seo' ),
				esc_html( human_time_diff( strtotime( $last_run ), time() ) . ' ago' )
			);
		}
	}

	/**
	 * AJAX: Run full SEO audit.
	 */
	public function ajax_run_audit(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$results = $this->seo_audit->run_all();

		wp_send_json_success( $results );
	}

	/**
	 * AJAX: Get cached audit results without re-running.
	 */
	public function ajax_get_audit_results(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		wp_send_json_success( $this->seo_audit->get_cached_results() );
	}

	/**
	 * AJAX: Fix a single audit module.
	 */
	public function ajax_fix_module(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$module_id  = sanitize_text_field( $_POST['module_id'] ?? '' );
		$fix_result = $this->seo_audit->fix_module( $module_id );

		if ( null === $fix_result ) {
			wp_send_json_error( array( 'message' => 'Module not found or does not support auto-fix.' ) );
		}

		wp_send_json_success(
			array(
				'fix'     => $fix_result,
				'results' => $this->seo_audit->get_cached_results(),
			)
		);
	}

	/**
	 * AJAX: Fix all fixable audit modules.
	 */
	public function ajax_fix_all(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$all_fixes = array();

		foreach ( $this->seo_audit->get_modules() as $id => $module ) {
			if ( $module->can_auto_fix() ) {
				$cached = $this->seo_audit->get_cached_results();
				$issues = $cached['modules'][ $id ]['issues'] ?? array();

				if ( ! empty( $issues ) ) {
					$all_fixes[ $id ] = $this->seo_audit->fix_module( $id );
				}
			}
		}

		wp_send_json_success(
			array(
				'fixes'   => $all_fixes,
				'results' => $this->seo_audit->get_cached_results(),
			)
		);
	}

	/**
	 * Admin notice: show a dismissible alert when new AI models are discovered.
	 *
	 * Renders a `.notice.notice-info.is-dismissible.swps-model-alert` listing the
	 * newly discovered model names with a link to the settings page.
	 *
	 * @return void
	 */
	public function model_alert_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only render on StrataWP-SEO admin pages — that's where the dismiss
		// handler JS is enqueued, so dismissals always persist.
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'stratawp-seo' ) ) {
			return;
		}

		$new = ( new SWPS_Model_Discovery() )->new_models();
		if ( empty( $new ) ) {
			return;
		}

		$names = implode( ', ', array_map( 'esc_html', array_values( $new ) ) );
		$url   = esc_url( admin_url( 'admin.php?page=swps-settings' ) );

		/* translators: %s = comma-separated list of new AI model names */
		$message = sprintf( esc_html__( 'StrataWP SEO: new AI model(s) available — %s.', 'stratawp-seo' ), $names );

		printf(
			'<div class="notice notice-info is-dismissible swps-model-alert"><p>%s <a href="%s">%s</a></p></div>',
			$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sprintf(esc_html__()) with pre-escaped $names.
			$url, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value is esc_url() output.
			esc_html__( 'Choose in Settings', 'stratawp-seo' )
		);
	}

	/**
	 * AJAX handler: dismiss the new-model admin notice.
	 *
	 * Clears the `swps_new_models_available` option so the notice no longer shows.
	 *
	 * @return void
	 */
	public function ajax_dismiss_model_alert(): void {
		check_ajax_referer( 'swps_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		( new SWPS_Model_Discovery() )->dismiss_alert();

		wp_send_json_success();
	}
}

/**
 * Activation hook.
 */
function swps_activate(): void {
	$defaults = array(
		'ai_provider'                 => 'anthropic',
		'image_provider'              => 'unsplash',
		'anthropic_api_key'           => '',
		'openai_api_key'              => '',
		'google_api_key'              => '',
		'xai_api_key'                 => '',
		'unsplash_api_key'            => '',
		'pexels_api_key'              => '',
		'pixabay_api_key'             => '',
		// Gemini image provider reuses google_api_key — no separate key needed.
		'gemini_image_model'          => '',
		'model'                       => 'claude-sonnet-4-6',
		'featured_images'             => 1,
		'site_niche'                  => '',
		'site_description'            => '',
		'tone'                        => 'professional',
		'writing_style'               => '',
		'target_keywords'             => '',
		'post_category'               => 0,
		'post_author'                 => get_current_user_id(),
		'post_status'                 => 'draft',
		'word_count_min'              => 1200,
		'word_count_max'              => 2000,
		'include_faq_schema'          => 1,
		'include_toc'                 => 1,
		'internal_links_min'          => 3,
		'internal_links_max'          => 6,
		'include_takeaways'           => 0,
		'takeaways_count'             => 5,
		'takeaways_schema'            => 1,
		'monthly_budget'              => 0,
		'cron_enabled'                => 0,
		'cron_frequency'              => 'weekly',
		'cron_day'                    => 'monday',
		'cron_time'                   => '09:00',
		'cron_posts_per_run'          => 1,
		// v2.0 defaults.
		'rate_limit'                  => 60,
		'duplicate_check'             => 0,
		'default_template'            => 'auto',
		'cost_tracking'               => 0,
		'min_content_score'           => 0,
		'voice_profile'               => 0,
		'insert_content_images'       => 0,
		'content_images_count'        => 2,
		'image_max_width'             => 1200,
		'jon_ai_endpoint'             => '',
		'jon_ai_secret'               => '',
		// SEO Audit defaults.
		'audit_auto_canonical'        => 1,
		'audit_auto_og'               => 1,
		'audit_auto_sitemap'          => 1,
		'audit_cron_schedule'         => 'weekly',
		// Schema defaults.
		'schema_enabled'              => 1,
		'schema_article_type'         => 'Article',
		'schema_searchbox'            => 1,
		'schema_entity_type'          => 'Organization',
		'schema_name'                 => '',
		'schema_logo'                 => '',
		'schema_social_profiles'      => '',
		// Analytics defaults.
		'analytics_enabled'           => 1,
		'analytics_retention'         => 90,
		'analytics_exclude_admins'    => 1,
		// Bot Analytics defaults.
		'bot_analytics_enabled'       => 1,
		'bot_analytics_retention'     => 90,
		'bot_analytics_sample_rate'   => 100,
		'bot_analytics_exclude_paths' => "/wp-admin\n/wp-json\n/wp-login\n/feed\n/xmlrpc.php",
		'gsc_client_id'               => '',
		'gsc_client_secret'           => '',
		// Keywords & Meta defaults.
		'meta_editor_enabled'         => 1,
		'meta_editor_post_types'      => array( 'post', 'page' ),
		'meta_auto_generate'          => 0,
		'keyword_tracking_frequency'  => 'weekly',
		// RSS Feed defaults.
		'rss_before'                  => '',
		'rss_after'                   => 'The post %%post_link%% appeared first on %%blog_link%%.',
		// Search Appearance defaults.
		'title_separator'             => '-',
		// Breadcrumb defaults.
		'breadcrumbs_enabled'         => 1,
		'breadcrumbs_separator'       => '&raquo;',
		'breadcrumbs_home_label'      => 'Home',
		// Redirect defaults.
		'auto_redirect_slug_change'   => 1,
		// Email digest defaults.
		'digest_enabled'              => 0,
		'digest_frequency'            => 'weekly',
		'digest_recipients'           => '',
		'digest_ai_summary'           => 0,
	);

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( "swps_{$key}" ) ) {
			update_option( "swps_{$key}", $value );
		}
	}

	// On a fresh install (option absent = never activated before), trigger the onboarding wizard.
	if ( false === get_option( 'swps_onboarding_state' ) ) {
		set_transient( 'swps_onboarding_redirect', 1, 60 );
	}

	// Register CPTs for flush_rewrite_rules.
	SWPS_Topic_Queue::register_post_type();
	SWPS_Voice_Profile::register_post_type();
	SWPS_SEO_Audit::schedule_cron();
	flush_rewrite_rules();

	SWPS_Analytics_Tracker::create_tables();
	SWPS_Analytics_Tracker::schedule_cron();
	SWPS_Bot_Analytics_Tracker::create_tables();
	update_option( SWPS_Bot_Analytics_Tracker::OPT_DB_VER, SWPS_Bot_Analytics_Tracker::DB_VERSION );
	SWPS_Bot_Analytics_Tracker::schedule_cron();
	SWPS_Crawler_Verification::schedule_cron();
	SWPS_AI_Referrals::create_table();
	update_option( SWPS_AI_Referrals::OPT_DB_VER, SWPS_AI_Referrals::DB_VERSION );
	SWPS_Search_Console::schedule_cron();
	SWPS_Keyword_Tracker::create_tables();
	SWPS_Keyword_Tracker::schedule_cron();
	SWPS_Citation_Store::create_table();
	update_option( SWPS_Citation_Store::OPT_DB_VER, SWPS_Citation_Store::DB_VERSION );
	SWPS_Citation_Tracker::schedule_cron();
	SWPS_Metric_History::create_table();
	update_option( SWPS_Metric_History::OPT_DB_VER, SWPS_Metric_History::DB_VERSION );
	SWPS_Decay_Watchdog::schedule_cron();
	SWPS_Topic_Scout_Cron::schedule_cron();

	SWPS_Redirect_Manager::create_tables();
	SWPS_Link_Keyword_Engine::create_tables();
	SWPS_Internal_Links::create_tables();
	SWPS_Internal_Links::schedule_cron();

	SWPS_Backlinks::create_tables();
	SWPS_Backlinks::schedule_cron();

	SWPS_Crawl_Issues::create_tables();
	update_option( SWPS_Crawl_Issues::OPT_DB_VER, SWPS_Crawl_Issues::DB_VERSION );
	SWPS_Site_Crawler::schedule_weekly_cron();

	SWPS_Abilities::create_log_table();
	update_option( SWPS_Abilities::OPT_DB_VER, SWPS_Abilities::DB_VERSION );

	if ( ! wp_next_scheduled( 'swps_prune_404_logs' ) ) {
		wp_schedule_event( time(), 'daily', 'swps_prune_404_logs' );
	}

	if ( get_option( 'swps_cron_enabled' ) ) {
		SWPS_Cron::schedule();
	}
}
register_activation_hook( __FILE__, 'swps_activate' );

/**
 * Deactivation hook.
 */
function swps_deactivate(): void {
	SWPS_Cron::unschedule();
	SWPS_Model_Cron::unschedule();
	SWPS_SEO_Audit::unschedule_cron();
	SWPS_Analytics_Tracker::unschedule_cron();
	SWPS_Bot_Analytics_Tracker::unschedule_cron();
	SWPS_Crawler_Verification::unschedule_cron();
	SWPS_Search_Console::unschedule_cron();
	SWPS_Keyword_Tracker::unschedule_cron();
	SWPS_Citation_Tracker::unschedule_cron();
	SWPS_Competitors::unschedule_cron();
	SWPS_Backlinks::unschedule_cron();
	wp_clear_scheduled_hook( 'swps_aeo_sweep_proposals' );
	SWPS_Question_Coverage::unschedule_cron();
	SWPS_Decay_Watchdog::unschedule_cron();
	SWPS_Topic_Scout_Cron::unschedule_cron();
	wp_clear_scheduled_hook( SWPS_Cannibalization::CRON_HOOK );
	wp_unschedule_hook( 'swps_send_digest' );
	wp_unschedule_hook( SWPS_Site_Crawler::CRON_HOOK );
	SWPS_Site_Crawler::unschedule_weekly_cron();
	wp_clear_scheduled_hook( SWPS_IndexNow::CRON_HOOK );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'swps_deactivate' );

/**
 * Initialize plugin.
 */
function stratawp_seo(): StrataWP_SEO {
	return StrataWP_SEO::instance();
}
add_action( 'plugins_loaded', 'stratawp_seo' );
