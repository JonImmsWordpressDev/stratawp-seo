<?php
/**
 * Cannibalization finding panel template.
 *
 * Variables provided by SWPS_Cannibalization_Admin::render_finding():
 *   int    $id         Finding row ID.
 *   string $query      The cannibalized query.
 *   array  $pages      Competing pages (page, clicks, impressions, position).
 *   bool   $has_undo   Whether an undo payload exists.
 *   int    $winner_idx Index of the winner page.
 *   object $finding    Full row object (for action buttons).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
// Variables $id, $query, $pages, $has_undo, $winner_idx are injected by the calling context
// (SWPS_Cannibalization_Admin::render()). They are not truly global — `require` in PHP
// inherits the calling scope, so PHPCS's global-variable checks are false positives here.
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
				<?php
				/* --- Consolidate button --- */
				$winner_url = (string) ( $pages[ $winner_idx ]['page'] ?? '' );
				$losers     = array();
				foreach ( $pages as $idx => $p ) {
					if ( $idx !== $winner_idx ) {
						$losers[] = (string) ( $p['page'] ?? '' );
					}
				}
				$loser_url = $losers[0] ?? '';
				if ( ! empty( $winner_url ) && ! empty( $loser_url ) ) :
					?>
					<button type="button" class="button button-primary swps-cannibal-consolidate"
							data-id="<?php echo esc_attr( (string) $id ); ?>"
							data-winner="<?php echo esc_attr( $winner_url ); ?>"
							data-loser="<?php echo esc_attr( $loser_url ); ?>"
							data-query="<?php echo esc_attr( $query ); ?>">
						<?php esc_html_e( 'Consolidate (301 redirect)', 'stratawp-seo' ); ?>
					</button>
				<?php endif; ?>

				<?php
				/* --- Differentiate link --- */
				$diff_loser_url = '';
				foreach ( $pages as $idx => $p ) {
					if ( $idx !== $winner_idx ) {
						$diff_loser_url = (string) ( $p['page'] ?? '' );
						break;
					}
				}
				if ( ! empty( $diff_loser_url ) ) :
					$diff_post_id = url_to_postid( $diff_loser_url );
					if ( $diff_post_id ) :
						$edit_url = add_query_arg(
							array(
								'post'          => $diff_post_id,
								'action'        => 'edit',
								'swps_focus_kw' => rawurlencode( $query ),
							),
							admin_url( 'post.php' )
						);
						?>
						<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-secondary"
							title="<?php esc_attr_e( 'Open the loser post editor with the focus keyword pre-filled (Classic editor only — in the block editor, set the focus keyword manually)', 'stratawp-seo' ); ?>">
							<?php esc_html_e( 'Differentiate (edit loser)', 'stratawp-seo' ); ?>
						</a>
					<?php endif; ?>
				<?php endif; ?>

				<?php
				/* --- Canonicalize guidance --- */
				if ( ! empty( $winner_url ) ) :
					$canon_snippet = sprintf( '<link rel="canonical" href="%s" />', esc_url( $winner_url ) );
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
								<?php echo esc_html( $canon_snippet ); ?>
							</code>
							<p style="margin:6px 0 0;color:var(--swps-text-muted)">
								<?php esc_html_e( 'Use the StrataWP SEO meta box (Advanced → Canonical URL) to set this per-post without editing theme files.', 'stratawp-seo' ); ?>
							</p>
						</div>
					</details>
				<?php endif; ?>
			<?php endif; ?>

			<button type="button" class="button swps-cannibal-dismiss"
					data-id="<?php echo esc_attr( (string) $id ); ?>"
					style="margin-left:auto;color:var(--swps-text-faint)">
				<?php esc_html_e( 'Dismiss', 'stratawp-seo' ); ?>
			</button>
		</div>
	</div>
</div>
