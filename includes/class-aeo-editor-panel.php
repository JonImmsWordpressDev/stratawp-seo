<?php
/**
 * AEO Editor Panel — live AEO score in the post editor.
 *
 * Renders a sidebar metabox (classic editor) showing the post's current
 * AEO score + 4 sub-scores + "Re-score" / "Improve AEO" buttons. The
 * Gutenberg sidebar plugin is registered client-side in
 * admin/js/aeo-editor-panel.js (Task 18) — this PHP class only handles
 * the classic-editor metabox and asset enqueue is delegated to the
 * Optimizer (it enqueues aeo-editor-panel.js on post.php / post-new.php).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_AEO_Editor_Panel {

	private SWPS_AEO_Scorer $scorer;

	public function __construct( SWPS_AEO_Scorer $scorer ) {
		$this->scorer = $scorer;
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
	}

	/**
	 * Register the classic-editor metabox for each configured post type.
	 */
	public function register_metabox(): void {
		$types = (array) get_option( 'swps_aeo_post_types', array( 'post', 'page' ) );
		foreach ( $types as $type ) {
			add_meta_box(
				'swps-aeo-panel',
				__( 'AEO Score', 'stratawp-seo' ),
				array( $this, 'render_metabox' ),
				$type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the metabox body.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_metabox( WP_Post $post ): void {
		$score = (int) get_post_meta( $post->ID, SWPS_AEO_Scorer::META_TOTAL, true );
		$sub   = array(
			'extractability' => get_post_meta( $post->ID, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'extractability', true ),
			'markup'         => get_post_meta( $post->ID, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'markup',         true ),
			'authority'      => get_post_meta( $post->ID, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'authority',      true ),
			'coverage'       => get_post_meta( $post->ID, SWPS_AEO_Scorer::META_SUBSCORE_PREFIX . 'coverage',       true ),
		);
		$last = (int) get_post_meta( $post->ID, SWPS_AEO_Scorer::META_LAST_SCAN, true );
		?>
		<div class="swps-aeo-panel" data-post-id="<?php echo (int) $post->ID; ?>">
			<div class="swps-aeo-panel-score" style="text-align:center;margin-bottom:8px;">
				<span class="swps-aeo-panel-num" id="swps-aeo-panel-num" style="font-size:32px;font-weight:600;color:var(--swps-accent,#10b981);">
					<?php echo $score > 0 ? esc_html( (string) $score ) : '—'; ?>
				</span>
				<br>
				<span class="swps-aeo-panel-label" style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#666;">
					<?php esc_html_e( 'AEO Score', 'stratawp-seo' ); ?>
				</span>
			</div>
			<ul class="swps-aeo-panel-sub" style="list-style:none;padding:0;margin:0 0 12px;font-size:12px;">
				<?php foreach ( $sub as $dim => $val ) : ?>
					<li style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #eee;">
						<strong><?php echo esc_html( ucfirst( $dim ) ); ?></strong>
						<span id="swps-aeo-panel-sub-<?php echo esc_attr( $dim ); ?>">
							<?php echo '' === $val ? '—' : esc_html( (string) (int) $val ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p style="display:flex;gap:6px;">
				<button type="button" class="button" id="swps-aeo-panel-rescore"><?php esc_html_e( 'Re-score', 'stratawp-seo' ); ?></button>
				<button type="button" class="button button-primary" id="swps-aeo-panel-improve"><?php esc_html_e( 'Improve AEO', 'stratawp-seo' ); ?></button>
			</p>
			<p class="description" style="margin-top:8px;font-size:11px;">
				<?php
				if ( $last > 0 ) {
					printf(
						/* translators: %s: human time-diff like "5 minutes ago". */
						esc_html__( 'Last scanned %s ago', 'stratawp-seo' ),
						esc_html( human_time_diff( $last, time() ) )
					);
				} else {
					esc_html_e( 'Not yet scanned. Click "Re-score".', 'stratawp-seo' );
				}
				?>
			</p>
			<?php
			// AI Visibility funnel line — single batched lookup.
			$crawl_map   = SWPS_Visibility_Funnel::crawl_stats_for_posts( array( $post->ID ), 30 );
			$visit_map   = SWPS_Visibility_Funnel::ai_visits_for_posts( array( $post->ID ), 30 );
			$crawl_entry = $crawl_map[ $post->ID ] ?? null;
			$ai_visits   = isset( $visit_map[ $post->ID ] ) ? (int) $visit_map[ $post->ID ]['ai_visits'] : 0;

			if ( $crawl_entry || $ai_visits > 0 ) :
				$crawl_ago = $crawl_entry && $crawl_entry['last_crawl_at']
					? human_time_diff( strtotime( (string) $crawl_entry['last_crawl_at'] ), time() ) . ' ago'
					: null;
				$crawl_bot = $crawl_entry ? (string) ( $crawl_entry['last_bot'] ?? '' ) : '';
				?>
				<p class="description swps-aeo-funnel-line" style="margin-top:6px;font-size:11px;color:var(--swps-text-muted);">
					<?php if ( $crawl_ago ) : ?>
						<?php
						printf(
							/* translators: 1: time since last crawl, 2: bot name. */
							esc_html__( 'Crawled %1$s by %2$s', 'stratawp-seo' ),
							esc_html( $crawl_ago ),
							'<code>' . esc_html( '' !== $crawl_bot ? $crawl_bot : 'AI bot' ) . '</code>'
						);
						?>
						&nbsp;&middot;&nbsp;
					<?php endif; ?>
					<?php
					printf(
						/* translators: %d: number of AI visits in 30 days. */
						esc_html( _n( '%d AI visit (30d)', '%d AI visits (30d)', $ai_visits, 'stratawp-seo' ) ),
						(int) $ai_visits
					);
					?>
				</p>
			<?php endif; ?>
			<?php
			// Lost-citation context line (set by SWPS_Citation_Tracker::detect_losses()).
			$citation_loss = get_post_meta( $post->ID, SWPS_Citation_Tracker::META_LOSS, true );
			if ( is_array( $citation_loss ) && ! empty( $citation_loss['prompt'] ) ) :
				?>
				<p class="description swps-aeo-citation-loss" style="margin-top:6px;font-size:11px;color:#b32d2e;">
					<?php
					printf(
						/* translators: 1: prompt text, 2: engine list. */
						esc_html__( 'AI engines (%2$s) stopped citing this post for "%1$s" — consider re-optimizing.', 'stratawp-seo' ),
						esc_html( (string) $citation_loss['prompt'] ),
						esc_html( implode( ', ', (array) ( $citation_loss['engines'] ?? array() ) ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Public accessor for the scorer dependency.
	 *
	 * Currently unused by the panel itself (all live data goes through
	 * AJAX endpoints), but exposed so future helpers / integrations can
	 * reuse the wired scorer instance without re-instantiating the graph.
	 */
	public function get_scorer(): SWPS_AEO_Scorer {
		return $this->scorer;
	}
}
