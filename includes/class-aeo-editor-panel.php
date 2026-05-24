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
