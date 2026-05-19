<?php
/**
 * Redirects admin page (v4.0).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap swps-redirects-page swps-settings-wrap">

	<?php
	$title    = __( 'Redirects', 'stratawp-seo' );
	$subtitle = __( 'Manage 301/302/307/410 redirects and monitor 404 errors. Click "Create redirect" on any 404 row to fix it instantly.', 'stratawp-seo' );
	$actions  = array();
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<div class="nav-tab-wrapper swps-settings-tabs" role="tablist">
		<a href="#redirects" class="nav-tab nav-tab-active" data-tab="redirects" role="tab" aria-selected="true"><?php esc_html_e( 'Redirects', 'stratawp-seo' ); ?></a>
		<a href="#404s" class="nav-tab" data-tab="404s" role="tab" aria-selected="false"><?php esc_html_e( '404 Errors', 'stratawp-seo' ); ?></a>
	</div>

	<div id="tab-redirects" class="swps-tab-content swps-settings-panel" data-tab="redirects" role="tabpanel">
		<h2 style="margin-top:0"><?php esc_html_e( 'Add Redirect', 'stratawp-seo' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="swps-redirect-source"><?php esc_html_e( 'Source URL', 'stratawp-seo' ); ?></label></th>
				<td><input type="text" id="swps-redirect-source" class="large-text" placeholder="/old-page"></td>
			</tr>
			<tr>
				<th><label for="swps-redirect-target"><?php esc_html_e( 'Target URL', 'stratawp-seo' ); ?></label></th>
				<td><input type="text" id="swps-redirect-target" class="large-text" placeholder="/new-page"></td>
			</tr>
			<tr>
				<th><label for="swps-redirect-type"><?php esc_html_e( 'Type', 'stratawp-seo' ); ?></label></th>
				<td>
					<select id="swps-redirect-type">
						<option value="301"><?php esc_html_e( '301 Permanent', 'stratawp-seo' ); ?></option>
						<option value="302"><?php esc_html_e( '302 Temporary', 'stratawp-seo' ); ?></option>
						<option value="307"><?php esc_html_e( '307 Temporary (preserve method)', 'stratawp-seo' ); ?></option>
						<option value="410"><?php esc_html_e( '410 Gone', 'stratawp-seo' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th></th>
				<td>
					<label><input type="checkbox" id="swps-redirect-regex"> <?php esc_html_e( 'Regex match', 'stratawp-seo' ); ?></label>
				</td>
			</tr>
		</table>
		<p style="margin-top:16px"><button class="swps-btn swps-btn-grad" id="swps-add-redirect"><?php esc_html_e( 'Add Redirect', 'stratawp-seo' ); ?></button></p>

		<h2 style="margin-top:32px"><?php esc_html_e( 'Existing Redirects', 'stratawp-seo' ); ?></h2>
		<table class="widefat striped" id="swps-redirects-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Target', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Type', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Hits', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>

	<div id="tab-404s" class="swps-tab-content swps-settings-panel" data-tab="404s" role="tabpanel" style="display:none;">
		<h2 style="margin-top:0"><?php esc_html_e( '404 Errors', 'stratawp-seo' ); ?></h2>

		<div class="swps-404-bulk-bar" style="display:none; margin-bottom:12px; padding:10px 14px; background:var(--swps-info-bg); border:1px solid var(--swps-accent-1); border-radius:var(--swps-radius-sm); color:var(--swps-text-primary)">
			<span id="swps-404-selected-count">0</span> <?php esc_html_e( 'selected', 'stratawp-seo' ); ?> —
			<button class="swps-btn swps-btn-secondary" id="swps-bulk-dismiss-404s" style="padding:4px 10px;font-size:11px"><?php esc_html_e( 'Dismiss Selected', 'stratawp-seo' ); ?></button>
			<button class="swps-btn swps-btn-secondary" id="swps-bulk-redirect-404s" style="padding:4px 10px;font-size:11px"><?php esc_html_e( 'Redirect Selected to...', 'stratawp-seo' ); ?></button>
			<span id="swps-bulk-redirect-target-wrap" style="display:none;">
				<input type="text" id="swps-bulk-redirect-target" class="regular-text" placeholder="<?php esc_attr_e( '/target-page', 'stratawp-seo' ); ?>">
				<button class="swps-btn swps-btn-grad" id="swps-bulk-redirect-confirm" style="padding:4px 10px;font-size:11px"><?php esc_html_e( 'Go', 'stratawp-seo' ); ?></button>
				<button class="swps-btn swps-btn-secondary" id="swps-bulk-redirect-cancel" style="padding:4px 10px;font-size:11px"><?php esc_html_e( 'Cancel', 'stratawp-seo' ); ?></button>
			</span>
		</div>

		<table class="widefat striped" id="swps-404s-table">
			<thead>
				<tr>
					<th style="width:30px;"><input type="checkbox" id="swps-404-select-all"></th>
					<th><?php esc_html_e( 'URL', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Referrer', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Hits', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Suggested Target', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Last Seen', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'stratawp-seo' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</div>

<script>
(function () {
	'use strict';
	document.addEventListener('DOMContentLoaded', function () {
		var wrap = document.querySelector('.swps-redirects-page');
		if (!wrap) return;
		var tabs = wrap.querySelectorAll('.swps-settings-tabs .nav-tab');
		var panels = wrap.querySelectorAll('.swps-tab-content');
		function activate(key) {
			tabs.forEach(function (t) {
				var match = t.dataset.tab === key;
				t.classList.toggle('nav-tab-active', match);
				t.setAttribute('aria-selected', match ? 'true' : 'false');
			});
			panels.forEach(function (p) {
				p.style.display = p.dataset.tab === key ? '' : 'none';
			});
		}
		tabs.forEach(function (t) {
			t.addEventListener('click', function (e) {
				e.preventDefault();
				activate(t.dataset.tab);
			});
		});
		var hash = window.location.hash.replace(/^#/, '');
		if (hash && wrap.querySelector('.nav-tab[data-tab="' + hash + '"]')) activate(hash);
	});
})();
</script>
