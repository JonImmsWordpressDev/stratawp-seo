/**
 * StrataWP SEO — Site Crawl page (v4.19).
 *
 * Start crawl → chunked AJAX loop driving SWPS_Site_Crawler::process_chunk()
 * until done → render grouped issues with one-click fixes.
 * AJAX uses the localized swpsSiteCrawl object (nonce swps_site_crawl).
 */
(function ($) {
    'use strict';

    if (typeof swpsSiteCrawl === 'undefined') {
        return;
    }

    var i18n = swpsSiteCrawl.i18n || {};

    var $startBtn     = $('#swps-crawl-start');
    var $progress     = $('#swps-crawl-progress');
    var $progressText = $('#swps-crawl-progress-text');
    var $issues       = $('#swps-crawl-issues');
    var $tileCrawled  = $('#swps-crawl-tile-crawled');
    var $tileQueued   = $('#swps-crawl-tile-queued');
    var $tileIssues   = $('#swps-crawl-tile-issues');

    var TYPE_LABELS = {
        broken_link:        'Broken links',
        redirect_chain:     'Redirect chains',
        redirect_loop:      'Redirect loops',
        canonical_mismatch: 'Canonical mismatches',
        missing_h1:         'Missing H1',
        duplicate_h1:       'Duplicate H1',
        mixed_content:      'Mixed content',
        noindex_in_sitemap: 'Noindexed URLs in sitemap'
    };

    function ajax(action, data) {
        return $.post(swpsSiteCrawl.ajaxUrl, $.extend({
            action: action,
            nonce: swpsSiteCrawl.nonce
        }, data || {}));
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setBusy(msg) {
        if (msg) {
            $progressText.text(msg);
            $progress.css('display', 'flex');
        } else {
            $progress.hide();
        }
    }

    function updateTiles(snapshot) {
        $tileCrawled.text(snapshot.crawled | 0);
        $tileQueued.text(snapshot.queued | 0);
        $tileIssues.text(snapshot.issues | 0);
    }

    // ------------------------------------------------------------------
    // Crawl loop
    // ------------------------------------------------------------------

    function crawlChunkLoop() {
        ajax('swps_crawl_chunk').done(function (res) {
            if (!res.success) {
                setBusy(null);
                window.alert((res.data && res.data.message) || i18n.failed);
                $startBtn.prop('disabled', false);
                return;
            }
            var s = res.data;
            updateTiles(s);

            if (s.done) {
                // Loopback sanity: a finished run with zero crawled pages
                // means we never reached our own site.
                if ((s.crawled | 0) === 0) {
                    setBusy(null);
                    window.alert(i18n.loopbackFail);
                    $startBtn.prop('disabled', false);
                    return;
                }
                setBusy(i18n.done);
                $startBtn.prop('disabled', false);
                loadIssues();
                window.setTimeout(function () { setBusy(null); }, 2500);
                return;
            }

            var msg = String(i18n.crawling || '%1$d / %2$d / %3$d')
                .replace('%1$d', s.crawled | 0)
                .replace('%2$d', s.queued | 0)
                .replace('%3$d', s.issues | 0);
            // The final chunk also performs external checks server-side and
            // can take noticeably longer than the others.
            setBusy((s.queued | 0) <= 10 ? (msg + ' ' + (i18n.finishing || '')) : msg);

            crawlChunkLoop();
        }).fail(function () {
            setBusy(null);
            window.alert(i18n.failed);
            $startBtn.prop('disabled', false);
        });
    }

    $startBtn.on('click', function () {
        $startBtn.prop('disabled', true);
        setBusy(i18n.starting);
        ajax('swps_crawl_start').done(function (res) {
            if (!res.success) {
                setBusy(null);
                window.alert((res.data && res.data.message) || i18n.failed);
                $startBtn.prop('disabled', false);
                return;
            }
            updateTiles(res.data);
            crawlChunkLoop();
        }).fail(function () {
            setBusy(null);
            window.alert(i18n.failed);
            $startBtn.prop('disabled', false);
        });
    });

    // ------------------------------------------------------------------
    // Issues rendering
    // ------------------------------------------------------------------

    function detailText(row) {
        var d = row.detail || {};
        var bits = [];
        if (d.status) { bits.push('HTTP ' + d.status); }
        if (d.external) { bits.push('external'); }
        if (d.hops && d.hops.length) { bits.push(d.hops.length + ' hops'); }
        if (d.canonical) { bits.push('canonical: ' + d.canonical); }
        if (d.h1_count) { bits.push(d.h1_count + ' H1s'); }
        if (d.asset) { bits.push('asset: ' + d.asset); }
        if (d.found_on) { bits.push('found on ' + d.found_on); }
        return bits.join(' · ');
    }

    function renderRow(type, row) {
        var sevClass = row.severity === 'error' ? 'swps-status-dot-crit' : 'swps-status-dot-warn';
        var html = '<div class="swps-row-card swps-crawl-issue-row" data-issue-id="' + (row.id | 0) + '">';
        html += '<div class="swps-row-head">';
        html += '<div class="swps-status-dot ' + sevClass + '">!</div>';
        html += '<div>';
        html += '<div class="swps-row-head-name">';
        html += '<a href="' + escapeHtml(row.url) + '" target="_blank" rel="noopener">' + escapeHtml(row.url) + '</a>';
        if (row.is_new) {
            html += ' <span class="swps-badge" style="background:#d63638;color:#fff;border-radius:3px;padding:1px 6px;font-size:10px;vertical-align:middle;">' + escapeHtml(i18n.newBadge || 'NEW') + '</span>';
        }
        html += '</div>';
        html += '<div class="swps-row-head-msg">' + escapeHtml(detailText(row)) + '</div>';
        html += '</div>';

        html += '<div class="swps-row-head-meta">';
        if (type === 'broken_link' && !(row.detail && row.detail.external)) {
            html += '<button class="swps-btn swps-btn-secondary swps-crawl-fix-redirect" data-path="' + escapeHtml(row.path) + '" style="padding:6px 10px;font-size:11px">' + escapeHtml(i18n.createRedirect || 'Create redirect') + '</button>';
        }
        if (type === 'broken_link' && row.detail && row.detail.external && row.host) {
            html += '<button class="swps-btn swps-btn-secondary swps-crawl-fix-ignore-host" data-host="' + escapeHtml(row.host) + '" style="padding:6px 10px;font-size:11px">' + escapeHtml(i18n.ignoreHost || 'Ignore host') + '</button>';
        }
        if (row.edit_url) {
            html += '<a class="swps-btn swps-btn-secondary" href="' + escapeHtml(row.edit_url) + '" target="_blank" rel="noopener" style="padding:6px 10px;font-size:11px">' + escapeHtml(i18n.editPost || 'Edit post') + '</a>';
        }
        if ((type === 'canonical_mismatch' || type === 'missing_h1' || type === 'duplicate_h1' || type === 'noindex_in_sitemap') && row.post_id > 0) {
            html += '<button class="swps-btn swps-btn-secondary swps-crawl-fix-exclude" data-post-id="' + (row.post_id | 0) + '" style="padding:6px 10px;font-size:11px">' + escapeHtml(i18n.excludeSitemap || 'Exclude from sitemap') + '</button>';
        }
        html += '</div>';
        html += '</div></div>';
        return html;
    }

    function renderGroups(groups) {
        var keys = Object.keys(groups);
        if (!keys.length) {
            $issues.html('<div class="swps-row-card"><p>' + escapeHtml(i18n.noIssues || 'No issues found.') + '</p></div>');
            return;
        }
        var html = '';
        keys.forEach(function (type) {
            var rows = groups[type] || [];
            var label = TYPE_LABELS[type] || type;
            html += '<details class="swps-crawl-group" style="margin-bottom:12px;" open>';
            html += '<summary style="cursor:pointer;font-weight:600;padding:8px 0;">' + escapeHtml(label) + ' (' + rows.length + ')</summary>';
            rows.forEach(function (row) { html += renderRow(type, row); });
            html += '</details>';
        });
        $issues.html(html);
    }

    function loadIssues() {
        ajax('swps_crawl_issues').done(function (res) {
            if (!res.success) {
                return;
            }
            renderGroups(res.data.groups || {});
        });
    }

    // ------------------------------------------------------------------
    // One-click fixes
    // ------------------------------------------------------------------

    $issues.on('click', '.swps-crawl-fix-redirect', function () {
        var $btn = $(this);
        var target = window.prompt(i18n.redirectTo || 'Redirect target:');
        if (!target) {
            return;
        }
        $btn.prop('disabled', true);
        ajax('swps_crawl_create_redirect', { source: $btn.data('path'), target: target }).done(function (res) {
            if (res.success) {
                $btn.text(i18n.redirectOk || 'Redirect created.');
            } else {
                window.alert((res.data && res.data.message) || i18n.failed);
                $btn.prop('disabled', false);
            }
        });
    });

    $issues.on('click', '.swps-crawl-fix-exclude', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        ajax('swps_crawl_exclude_sitemap', { post_id: $btn.data('post-id') }).done(function (res) {
            if (res.success) {
                $btn.text(i18n.excluded || 'Excluded.');
            } else {
                window.alert((res.data && res.data.message) || i18n.failed);
                $btn.prop('disabled', false);
            }
        });
    });

    $issues.on('click', '.swps-crawl-fix-ignore-host', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        ajax('swps_crawl_ignore_host', { host: $btn.data('host') }).done(function (res) {
            if (res.success) {
                $btn.text(i18n.hostIgnored || 'Ignored.');
                $('#swps-crawl-ignore-hosts').val((res.data.hosts || []).join(', '));
            } else {
                window.alert((res.data && res.data.message) || i18n.failed);
                $btn.prop('disabled', false);
            }
        });
    });

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    $('#swps-crawl-save-settings').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        ajax('swps_crawl_save_settings', {
            internal_cap:   $('#swps-crawl-internal-cap').val(),
            external_cap:   $('#swps-crawl-external-cap').val(),
            delay_ms:       $('#swps-crawl-delay').val(),
            ignore_hosts:   $('#swps-crawl-ignore-hosts').val(),
            weekly_enabled: $('#swps-crawl-weekly').is(':checked') ? 1 : 0
        }).done(function (res) {
            $btn.prop('disabled', false);
            $('#swps-crawl-settings-msg').text(res.success ? (i18n.settingsSaved || 'Saved.') : ((res.data && res.data.message) || i18n.failed));
            window.setTimeout(function () { $('#swps-crawl-settings-msg').text(''); }, 3000);
        }).fail(function () {
            $btn.prop('disabled', false);
            window.alert(i18n.failed);
        });
    });

    // Initial load of the latest run's issues.
    loadIssues();
})(jQuery);
