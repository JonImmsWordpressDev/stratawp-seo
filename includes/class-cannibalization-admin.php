<?php
/**
 * Keyword Cannibalization Admin — findings page, resolution AJAX, and undo chain.
 *
 * Renders a submenu page under stratawp-seo showing open findings grouped by
 * severity. Each finding has three resolution actions:
 *   - Consolidate: create a 301 via SWPS_Redirect_Manager + optional draft-loser;
 *                  stores an undo payload; Consolidate button becomes Undo.
 *   - Differentiate: links to the loser's editor with ?swps_focus_kw=<query>
 *                    so the meta box pre-fills the focus keyword field.
 *   - Canonicalize: copy-the-snippet guidance panel (no AJAX).
 *   - Dismiss: marks the finding dismissed.
 *
 * All AJAX endpoints check nonce 'swps_cannibal' and manage_options capability.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cannibalization admin page and AJAX handlers.
 */
class SWPS_Cannibalization_Admin {

	/** AJAX nonce action. */
	private const NONCE = 'swps_cannibal';

	/** Admin page slug. */
	public const PAGE_SLUG = 'swps-cannibalization';

	/**
	 * Register hooks.
	 *
	 * @param SWPS_Cannibalization $engine Detection engine (reserved for future instance calls).
	 */
	public function __construct( SWPS_Cannibalization $engine ) {
		unset( $engine ); // All SWPS_Cannibalization calls below use static methods.

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_swps_cannibal_consolidate', array( $this, 'ajax_consolidate' ) );
		add_action( 'wp_ajax_swps_cannibal_undo', array( $this, 'ajax_undo' ) );
		add_action( 'wp_ajax_swps_cannibal_dismiss', array( $this, 'ajax_dismiss' ) );

		// Focus-keyword prefill hook: when the meta editor renders on an edit
		// screen that carries ?swps_focus_kw=<term>, inject it as the initial
		// field value (overrides saved meta only when the field is empty).
		add_action( 'add_meta_boxes', array( $this, 'maybe_schedule_focus_kw_prefill' ) );
	}

	// =========================================================================
	// MENU
	// =========================================================================

	/**
	 * Register the submenu page under stratawp-seo.
	 */
	public function register_menu(): void {
		$count = SWPS_Cannibalization::count_open();
		$label = $count > 0
			? sprintf(
				/* translators: %d: number of open cannibalization findings */
				__( 'Cannibalization <span class="update-plugins count-%1$d"><span class="update-count">%1$d</span></span>', 'stratawp-seo' ),
				$count
			)
			: __( 'Cannibalization', 'stratawp-seo' );

		add_submenu_page(
			'stratawp-seo',
			__( 'Keyword Cannibalization', 'stratawp-seo' ),
			$label,
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	// =========================================================================
	// ASSETS
	// =========================================================================

	/**
	 * Enqueue page-specific assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'swps-cannibalization',
			SWPS_PLUGIN_URL . 'admin/css/pages/cannibalization.css',
			array( 'swps-tokens', 'swps-shell', 'swps-components' ),
			SWPS_VERSION
		);

		wp_enqueue_script(
			'swps-cannibalization',
			SWPS_PLUGIN_URL . 'admin/js/cannibalization.js',
			array( 'jquery', 'swps-shell' ),
			SWPS_VERSION,
			true
		);

		wp_localize_script(
			'swps-cannibalization',
			'swpsCannibalization',
			array(
				'nonce'   => wp_create_nonce( self::NONCE ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'confirm_consolidate' => __( 'Create a 301 redirect from the loser URL to the winner URL?', 'stratawp-seo' ),
					'confirm_undo'        => __( 'Remove the redirect and restore the original post status?', 'stratawp-seo' ),
					'processing'          => __( 'Processing…', 'stratawp-seo' ),
					'error'               => __( 'An error occurred. Please try again.', 'stratawp-seo' ),
				),
			)
		);
	}

	// =========================================================================
	// PAGE RENDER
	// =========================================================================

	/**
	 * Render the cannibalization findings page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stratawp-seo' ) );
		}

		$findings = SWPS_Cannibalization::get_open_findings();

		$title    = __( 'Keyword Cannibalization', 'stratawp-seo' );
		$subtitle = __( 'Queries where multiple pages split impressions — review and resolve below.', 'stratawp-seo' );
		$actions  = array();
		require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';

		echo '<div class="wrap swps-cannibalization-wrap">';

		if ( empty( $findings ) ) {
			echo '<div class="swps-empty-state" style="text-align:center;padding:40px 0;">';
			echo '<span style="font-size:48px;">✓</span>';
			echo '<h2>' . esc_html__( 'No cannibalization detected', 'stratawp-seo' ) . '</h2>';
			echo '<p style="color:var(--swps-text-muted)">' . esc_html__( 'All queries are mapping cleanly to individual pages.', 'stratawp-seo' ) . '</p>';
			echo '</div>';
			echo '</div>';
			return;
		}

		// Group by severity: cannibal first, then review.
		$by_severity = array(
			'cannibal' => array(),
			'review'   => array(),
		);
		foreach ( $findings as $finding ) {
			$sev = $finding->severity ?? 'cannibal';
			if ( isset( $by_severity[ $sev ] ) ) {
				$by_severity[ $sev ][] = $finding;
			}
		}

		foreach ( $by_severity as $severity => $group ) {
			if ( empty( $group ) ) {
				continue;
			}

			$group_label = 'cannibal' === $severity
				? esc_html__( 'Confirmed Cannibalization', 'stratawp-seo' )
				: esc_html__( 'Needs Review (archive/post pairs)', 'stratawp-seo' );

			printf( '<h2>%s</h2>', esc_html( $group_label ) );

			foreach ( $group as $row ) {
				$this->render_finding( $row );
			}
		}

		echo '</div>';
	}

	/**
	 * Render a single finding panel.
	 *
	 * @param object $row Finding row from DB.
	 */
	private function render_finding( object $row ): void {
		$id       = (int) $row->id;
		$query    = (string) ( $row->query ?? '' );
		$pages    = json_decode( (string) ( $row->pages ?? '[]' ), true );
		$pages    = is_array( $pages ) ? $pages : array();
		$has_undo = ! empty( $row->undo_json );

		$winner_idx = SWPS_Cannibalization::pick_winner( $pages );
		?>
		<div class="swps-cannibal-finding" id="swps-finding-<?php echo esc_attr( (string) $id ); ?>" data-id="<?php echo esc_attr( (string) $id ); ?>">
			<div class="swps-cannibal-finding-header">
				<strong class="swps-cannibal-query"><?php echo esc_html( $query ); ?></strong>
				<span class="swps-cannibal-page-count">
					<?php
					printf(
						/* translators: %d: number of competing pages */
						esc_html__( '%d competing pages', 'stratawp-seo' ),
						count( $pages )
					);
					?>
				</span>
			</div>

			<div class="swps-cannibal-finding-body">
				<table class="swps-cannibal-pages wp-list-table widefat striped" style="margin-bottom:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL', 'stratawp-seo' ); ?></th>
							<th><?php esc_html_e( 'Clicks', 'stratawp-seo' ); ?></th>
							<th><?php esc_html_e( 'Impressions', 'stratawp-seo' ); ?></th>
							<th><?php esc_html_e( 'Position', 'stratawp-seo' ); ?></th>
							<th><?php esc_html_e( 'CTR (actual / expected)', 'stratawp-seo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pages as $i => $page ) : ?>
							<?php
							$url        = (string) ( $page['page'] ?? '' );
							$clicks     = (int) ( $page['clicks'] ?? 0 );
							$impr       = (int) ( $page['impressions'] ?? 0 );
							$position   = (float) ( $page['position'] ?? 0 );
							$actual_ctr = $impr > 0 ? round( ( $clicks / $impr ) * 100, 1 ) : 0.0;
							$expect_ctr = round( SWPS_Cannibalization::expected_ctr( $position ) * 100, 1 );
							$is_winner  = ( $i === $winner_idx );
							?>
							<tr class="<?php echo $is_winner ? 'swps-cannibal-winner' : ''; ?>">
								<td>
									<?php if ( $is_winner ) : ?>
										<span class="swps-badge swps-badge-new" style="margin-right:4px"><?php esc_html_e( 'Winner', 'stratawp-seo' ); ?></span>
									<?php endif; ?>
									<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
										<?php echo esc_html( urldecode( $url ) ); ?>
									</a>
								</td>
								<td><?php echo esc_html( number_format_i18n( $clicks ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $impr ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $position, 1 ) ); ?></td>
								<td>
									<?php echo esc_html( $actual_ctr . '%' ); ?>
									<span style="color:var(--swps-text-faint)"> / </span>
									<?php echo esc_html( $expect_ctr . '%' ); ?>
									<abbr title="<?php esc_attr_e( 'Expected CTR is approximate (industry-average curve)', 'stratawp-seo' ); ?>"
										style="text-decoration:none;cursor:help;color:var(--swps-text-faint)">ⓘ</abbr>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="swps-cannibal-actions">
					<?php if ( $has_undo ) : ?>
						<button type="button" class="button button-secondary swps-cannibal-undo"
								data-id="<?php echo esc_attr( (string) $id ); ?>">
							<?php esc_html_e( '↩ Undo Consolidation', 'stratawp-seo' ); ?>
						</button>
					<?php else : ?>
						<?php $this->render_consolidate_button( $id, $pages, $winner_idx, $query ); ?>
						<?php $this->render_differentiate_link( $pages, $winner_idx, $query ); ?>
						<?php $this->render_canonicalize_guidance( $pages, $winner_idx ); ?>
					<?php endif; ?>

					<button type="button" class="button swps-cannibal-dismiss"
							data-id="<?php echo esc_attr( (string) $id ); ?>"
							style="margin-left:auto;color:var(--swps-text-faint)">
						<?php esc_html_e( 'Dismiss', 'stratawp-seo' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Consolidate button + modal trigger.
	 *
	 * @param int    $id         Finding row ID.
	 * @param array  $pages      Competing page data.
	 * @param int    $winner_idx Winner page index.
	 * @param string $query      The cannibalized query.
	 */
	private function render_consolidate_button( int $id, array $pages, int $winner_idx, string $query ): void {
		$winner_url = (string) ( $pages[ $winner_idx ]['page'] ?? '' );
		$losers     = array();
		foreach ( $pages as $i => $p ) {
			if ( $i !== $winner_idx ) {
				$losers[] = (string) ( $p['page'] ?? '' );
			}
		}
		$loser_url = $losers[0] ?? '';

		if ( empty( $winner_url ) || empty( $loser_url ) ) {
			return;
		}
		?>
		<button type="button" class="button button-primary swps-cannibal-consolidate"
				data-id="<?php echo esc_attr( (string) $id ); ?>"
				data-winner="<?php echo esc_attr( $winner_url ); ?>"
				data-loser="<?php echo esc_attr( $loser_url ); ?>"
				data-query="<?php echo esc_attr( $query ); ?>">
			<?php esc_html_e( 'Consolidate (301 redirect)', 'stratawp-seo' ); ?>
		</button>
		<?php
	}

	/**
	 * Render the Differentiate deep-link to the loser's editor.
	 *
	 * The link carries ?swps_focus_kw=<query> so the meta box pre-fills
	 * the focus keyword field via the maybe_schedule_focus_kw_prefill hook.
	 *
	 * @param array  $pages      Competing pages.
	 * @param int    $winner_idx Winner page index.
	 * @param string $query      The cannibalized query.
	 */
	private function render_differentiate_link( array $pages, int $winner_idx, string $query ): void {
		$loser_url = '';
		foreach ( $pages as $i => $p ) {
			if ( $i !== $winner_idx ) {
				$loser_url = (string) ( $p['page'] ?? '' );
				break;
			}
		}

		if ( empty( $loser_url ) ) {
			return;
		}

		// Resolve the loser URL to a post ID.
		$post_id = url_to_postid( $loser_url );
		if ( ! $post_id ) {
			return;
		}

		$edit_url = add_query_arg(
			array(
				'post'          => $post_id,
				'action'        => 'edit',
				'swps_focus_kw' => rawurlencode( $query ),
			),
			admin_url( 'post.php' )
		);
		?>
		<a href="<?php echo esc_url( $edit_url ); ?>"
			class="button button-secondary"
			title="<?php esc_attr_e( 'Open the loser post editor with the focus keyword pre-filled', 'stratawp-seo' ); ?>">
			<?php esc_html_e( 'Differentiate (edit loser)', 'stratawp-seo' ); ?>
		</a>
		<?php
	}

	/**
	 * Render the Canonicalize guidance panel (no AJAX; copy-the-snippet only).
	 *
	 * @param array $pages      Competing pages.
	 * @param int   $winner_idx Winner page index.
	 */
	private function render_canonicalize_guidance( array $pages, int $winner_idx ): void {
		$winner_url = (string) ( $pages[ $winner_idx ]['page'] ?? '' );
		if ( empty( $winner_url ) ) {
			return;
		}

		$snippet = sprintf( '<link rel="canonical" href="%s" />', esc_url( $winner_url ) );
		?>
		<details class="swps-cannibal-canon-details" style="display:inline-block;vertical-align:middle">
			<summary class="button button-secondary" style="cursor:pointer;list-style:none">
				<?php esc_html_e( 'Canonicalize (guidance)', 'stratawp-seo' ); ?>
			</summary>
			<div style="margin-top:8px;padding:8px;background:var(--swps-surface-1,#f9f9f9);border:1px solid #ddd;border-radius:4px;font-size:12px;">
				<p style="margin:0 0 4px">
					<?php esc_html_e( 'Add this tag to the &lt;head&gt; of each competing page to signal the canonical version:', 'stratawp-seo' ); ?>
				</p>
				<code style="display:block;word-break:break-all;padding:6px;background:#fff;border:1px solid #eee;border-radius:3px">
					<?php echo esc_html( $snippet ); ?>
				</code>
				<p style="margin:6px 0 0;color:var(--swps-text-muted)">
					<?php esc_html_e( 'Use the StrataWP SEO meta box (Advanced → Canonical URL) to set this per-post without editing theme files.', 'stratawp-seo' ); ?>
				</p>
			</div>
		</details>
		<?php
	}

	// =========================================================================
	// FOCUS KEYWORD PREFILL
	// =========================================================================

	/**
	 * When a post edit screen carries ?swps_focus_kw=<term>, enqueue a small
	 * script that sets the focus keyword field value if it is currently empty.
	 *
	 * This runs on add_meta_boxes so the meta box is already registered and the
	 * post ID is available.
	 */
	public function maybe_schedule_focus_kw_prefill(): void {
		if ( ! is_admin() ) {
			return;
		}

		// Reading query-string params on the editor screen — nonce is verified by WP core's
		// edit.php load, and add_meta_boxes only fires for authenticated admins.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$focus_kw = sanitize_text_field( wp_unslash( $_GET['swps_focus_kw'] ?? '' ) );
		if ( empty( $focus_kw ) ) {
			return;
		}

		$post_id = absint( wp_unslash( $_GET['post'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			return;
		}

		// Only prefill when the field is currently empty (don't clobber existing kw).
		$existing = get_post_meta( $post_id, '_swps_focus_keyword', true );

		wp_add_inline_script(
			'jquery',
			sprintf(
				"jQuery(function($){
					var existing = %s;
					var field = $('#_swps_focus_keyword');
					if (!existing && field.length && !field.val().trim()) {
						field.val(%s);
						field.trigger('input');
					}
				});",
				wp_json_encode( $existing ),
				wp_json_encode( $focus_kw )
			)
		);
	}

	// =========================================================================
	// AJAX HANDLERS
	// =========================================================================

	/**
	 * AJAX: Consolidate — create 301 redirect from loser to winner.
	 *
	 * Expected POST: nonce, finding_id (int), winner_url (string),
	 *                loser_url (string), draft_loser (0|1).
	 */
	public function ajax_consolidate(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$finding_id  = absint( $_POST['finding_id'] ?? 0 );
		$winner_url  = esc_url_raw( wp_unslash( $_POST['winner_url'] ?? '' ) );
		$loser_url   = esc_url_raw( wp_unslash( $_POST['loser_url'] ?? '' ) );
		$draft_loser = ! empty( $_POST['draft_loser'] );

		if ( ! $finding_id || empty( $winner_url ) || empty( $loser_url ) ) {
			wp_send_json_error( array( 'message' => 'Missing required fields.' ) );
		}

		// Parse paths for the redirect.
		$loser_path  = (string) wp_parse_url( $loser_url, PHP_URL_PATH );
		$winner_path = (string) wp_parse_url( $winner_url, PHP_URL_PATH );

		if ( empty( $loser_path ) || empty( $winner_path ) ) {
			wp_send_json_error( array( 'message' => 'Could not parse URL paths.' ) );
		}

		// Create 301 redirect via redirect manager.
		$redirect_manager = stratawp_seo()->redirect_manager;
		$redirect_id      = $redirect_manager->add_redirect( $loser_path, $winner_path, 301 );

		if ( is_wp_error( $redirect_id ) ) {
			wp_send_json_error( array( 'message' => $redirect_id->get_error_message() ) );
		}

		if ( false === $redirect_id ) {
			wp_send_json_error( array( 'message' => 'Redirect could not be created.' ) );
		}

		// Build undo payload.
		$undo_payload = array(
			'redirect_id'  => (int) $redirect_id,
			'post_id'      => 0,
			'prior_status' => '',
		);

		// Optionally draft the loser post.
		if ( $draft_loser ) {
			$loser_post_id = url_to_postid( $loser_url );
			if ( $loser_post_id ) {
				$prior_status = get_post_status( $loser_post_id );
				if ( 'publish' === $prior_status ) {
					wp_update_post(
						array(
							'ID'          => $loser_post_id,
							'post_status' => 'draft',
						)
					);
					$undo_payload['post_id']      = $loser_post_id;
					$undo_payload['prior_status'] = $prior_status;
				}
			}
		}

		// Store undo payload and mark finding resolved.
		SWPS_Cannibalization::store_undo( $finding_id, $undo_payload );

		wp_send_json_success(
			array(
				'message'     => __( 'Redirect created. Finding marked resolved.', 'stratawp-seo' ),
				'finding_id'  => $finding_id,
				'redirect_id' => (int) $redirect_id,
			)
		);
	}

	/**
	 * AJAX: Undo — delete the redirect and restore post status.
	 *
	 * Expected POST: nonce, finding_id (int).
	 */
	public function ajax_undo(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$finding_id = absint( $_POST['finding_id'] ?? 0 );
		if ( ! $finding_id ) {
			wp_send_json_error( array( 'message' => 'Missing finding_id.' ) );
		}

		$payload = SWPS_Cannibalization::get_undo( $finding_id );
		if ( ! $payload ) {
			wp_send_json_error( array( 'message' => 'No undo payload found.' ) );
		}

		global $wpdb;
		$redirect_id = (int) ( $payload['redirect_id'] ?? 0 );
		if ( $redirect_id > 0 ) {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'swps_redirects',
				array( 'id' => $redirect_id ),
				array( '%d' )
			);
			delete_transient( 'swps_redirects_cache' );
		}

		// Restore post status.
		$post_id      = (int) ( $payload['post_id'] ?? 0 );
		$prior_status = (string) ( $payload['prior_status'] ?? '' );
		if ( $post_id > 0 && ! empty( $prior_status ) ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $prior_status,
				)
			);
		}

		// Clear undo payload and re-open finding.
		SWPS_Cannibalization::clear_undo( $finding_id );

		wp_send_json_success(
			array(
				'message'    => __( 'Undo complete. Finding re-opened.', 'stratawp-seo' ),
				'finding_id' => $finding_id,
			)
		);
	}

	/**
	 * AJAX: Dismiss a finding.
	 *
	 * Expected POST: nonce, finding_id (int).
	 */
	public function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$finding_id = absint( $_POST['finding_id'] ?? 0 );
		if ( ! $finding_id ) {
			wp_send_json_error( array( 'message' => 'Missing finding_id.' ) );
		}

		if ( ! SWPS_Cannibalization::set_status( $finding_id, 'dismissed' ) ) {
			wp_send_json_error( array( 'message' => 'Could not dismiss finding.' ) );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Finding dismissed.', 'stratawp-seo' ),
				'finding_id' => $finding_id,
			)
		);
	}
}
