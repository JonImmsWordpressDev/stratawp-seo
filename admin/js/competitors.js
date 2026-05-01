/**
 * StrataWP SEO — Competitors page (v4.1).
 *
 * Wires the add/edit form, per-row scan, scan-all, and new-post expanders.
 * AJAX uses the localized swpsCompetitors object set by SWPS_Competitors::enqueue_assets().
 */
(function ($) {
    'use strict';

    if (typeof swpsCompetitors === 'undefined') {
        return;
    }

    var i18n  = swpsCompetitors.i18n || {};
    var maxN  = parseInt(swpsCompetitors.maxCompetitors, 10) || 10;

    var $list         = $('#swps-comp-list');
    var $form         = $('#swps-comp-form');
    var $formId       = $('#swps-comp-form-id');
    var $formUrl      = $('#swps-comp-form-url');
    var $formLabel    = $('#swps-comp-form-label');
    var $progress     = $('#swps-comp-progress');
    var $progressText = $('#swps-comp-progress-text');
    var $tileCount    = $('#swps-comp-tile-count');
    var $tileNew      = $('#swps-comp-tile-newposts');
    var $tileChanges  = $('#swps-comp-tile-changes');
    var $tileLast     = $('#swps-comp-tile-lastscan');

    function setBusy(msg) {
        if (msg) {
            $progressText.text(msg);
            $progress.css('display', 'flex');
            $form.show();
        } else {
            $progress.hide();
        }
    }

    function ajax(action, data) {
        return $.post(swpsCompetitors.ajaxUrl, $.extend({
            action: action,
            nonce:  swpsCompetitors.nonce
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

    function timeAgo(mysqlTime) {
        if (!mysqlTime) return i18n.never || 'never';
        var ts = Date.parse(mysqlTime.replace(' ', 'T') + 'Z');
        if (isNaN(ts)) return mysqlTime;
        var diff = Math.max(0, Math.floor((Date.now() - ts) / 1000));
        if (diff < 60)        return Math.floor(diff) + 's ago';
        if (diff < 3600)      return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400)     return Math.floor(diff / 3600) + 'h ago';
        if (diff < 86400 * 7) return Math.floor(diff / 86400) + 'd ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function hostOf(url) {
        try { return new URL(url).hostname; } catch (e) { return url; }
    }

    function diffOf(competitor) {
        var snaps = competitor.snapshots || [];
        if (!snaps.length) return { has_data: false };
        var cur  = snaps[snaps.length - 1];
        var prev = snaps.length > 1 ? snaps[snaps.length - 2] : null;
        var newPosts = [];
        var newSchema = [];
        var titleChanged = false;
        var h1Changed = false;
        if (prev) {
            var prevUrls = (prev.recent_posts || []).map(function (p) { return p.url || ''; });
            (cur.recent_posts || []).forEach(function (p) {
                if (prevUrls.indexOf(p.url || '') === -1) newPosts.push(p);
            });
            var curSchema  = cur.schema_types  || [];
            var prevSchema = prev.schema_types || [];
            newSchema = curSchema.filter(function (t) { return prevSchema.indexOf(t) === -1; });
            titleChanged = (cur.homepage_title || '') !== (prev.homepage_title || '');
            h1Changed    = (cur.homepage_h1 || '')    !== (prev.homepage_h1 || '');
        }
        var velocity = 0;
        if (snaps.length > 1) {
            var first = snaps[0];
            var firstTs = Date.parse(first.ts.replace(' ', 'T') + 'Z');
            var lastTs  = Date.parse(cur.ts.replace(' ', 'T') + 'Z');
            if (firstTs && lastTs && lastTs > firstTs) {
                var days = Math.max(1, Math.round((lastTs - firstTs) / 86400000));
                var delta = Math.max(0, (cur.post_count || 0) - (first.post_count || 0));
                velocity = Math.round((delta / days) * 7 * 10) / 10;
            }
        }
        return {
            has_data: true,
            cur: cur,
            new_posts: newPosts,
            new_post_count: newPosts.length,
            new_schema_types: newSchema,
            schemas_now: cur.schema_types || [],
            homepage_title_changed: titleChanged,
            homepage_h1_changed: h1Changed,
            velocity_per_week: velocity
        };
    }

    function renderRow(c) {
        var diff = diffOf(c);
        var err = c.last_error || '';
        var hasError = err !== '';
        var dotClass, dotGlyph;
        if (hasError && !diff.has_data) {
            dotClass = 'swps-status-dot-crit'; dotGlyph = '!';
        } else if (hasError && diff.has_data) {
            dotClass = 'swps-status-dot-warn'; dotGlyph = '!';
        } else {
            dotClass = 'swps-status-dot-ok'; dotGlyph = '✓';
        }

        var html = '<div class="swps-row-card swps-comp-row" '
            + 'data-id="'    + escapeHtml(c.id)    + '" '
            + 'data-url="'   + escapeHtml(c.url)   + '" '
            + 'data-label="' + escapeHtml(c.label) + '">';

        html += '<div class="swps-row-head">';
        html += '<div class="swps-status-dot ' + dotClass + '">' + dotGlyph + '</div>';
        html += '<div>';
        html += '<div class="swps-row-head-name">';
        html += '<a href="' + escapeHtml(c.url) + '" target="_blank" rel="noopener">' + escapeHtml(c.label) + '</a>';
        if (c.data_source && c.data_source !== 'none') {
            html += ' <span class="swps-comp-source-pill">' + escapeHtml(c.data_source.toUpperCase()) + '</span>';
        }
        html += '</div>';
        html += '<div class="swps-row-head-msg">';
        html += escapeHtml(hostOf(c.url));
        html += ' &middot; ' + (c.last_scanned ? 'scanned ' + timeAgo(c.last_scanned) : (i18n.noScanYet || 'never scanned'));
        if (hasError) {
            html += ' &middot; <span class="swps-comp-err">' + escapeHtml(err) + '</span>';
        }
        html += '</div>';
        html += '</div>';

        html += '<div class="swps-row-head-meta">';
        html += '<div class="swps-comp-stats">';
        if (diff.has_data) {
            if (diff.new_post_count > 0) {
                html += '<button type="button" class="swps-comp-newposts swps-comp-newposts--has" data-toggle-newposts>+' + diff.new_post_count + ' ' + escapeHtml(i18n.newPosts || 'new') + '</button>';
            } else {
                html += '<span class="swps-comp-newposts">0 ' + escapeHtml(i18n.newPosts || 'new') + '</span>';
            }
            html += '<span class="swps-comp-velocity">' + diff.velocity_per_week.toFixed(1) + '<span class="swps-comp-velocity-unit">' + escapeHtml(i18n.velocity || '/wk') + '</span></span>';
        }
        html += '</div>';
        html += '<button class="swps-btn swps-btn-grad swps-comp-scan" style="padding:6px 12px;font-size:11px">' + escapeHtml(i18n.scanNow || 'Scan now') + '</button>';
        html += '<button class="swps-btn swps-btn-secondary swps-comp-edit" title="' + escapeHtml(i18n.editCompetitor || 'Edit') + '" style="padding:6px 10px;font-size:11px"><span class="dashicons dashicons-edit"></span></button>';
        html += '<button class="swps-btn swps-btn-secondary swps-comp-delete" title="' + escapeHtml(i18n.delete || 'Delete') + '" style="padding:6px 10px;font-size:11px"><span class="dashicons dashicons-trash"></span></button>';
        html += '</div>';
        html += '</div>'; // /row-head

        // Body: schema chips + change pills.
        if (diff.has_data && (diff.schemas_now.length || diff.homepage_title_changed || diff.homepage_h1_changed)) {
            html += '<div class="swps-comp-row-body">';
            if (diff.schemas_now.length) {
                html += '<div class="swps-comp-schema-row">';
                html += '<span class="swps-comp-schema-label">Schema:</span>';
                diff.schemas_now.forEach(function (t) {
                    var isNew = diff.new_schema_types.indexOf(t) !== -1;
                    html += '<span class="swps-comp-schema-chip' + (isNew ? ' is-new' : '') + '">' + escapeHtml(t) + '</span>';
                });
                html += '</div>';
            }
            if (diff.homepage_title_changed || diff.homepage_h1_changed) {
                html += '<div class="swps-comp-changes">';
                if (diff.homepage_title_changed) {
                    html += '<span class="swps-comp-change-pill"><span class="dashicons dashicons-edit-page"></span>' + escapeHtml(i18n.titleChanged || 'Title changed') + '</span>';
                }
                if (diff.homepage_h1_changed) {
                    html += '<span class="swps-comp-change-pill"><span class="dashicons dashicons-heading"></span>' + escapeHtml(i18n.h1Changed || 'H1 changed') + '</span>';
                }
                html += '</div>';
            }
            html += '</div>';
        }

        // New-posts expander.
        if (diff.has_data && diff.new_post_count > 0) {
            html += '<div class="swps-comp-newposts-list" style="display:none;">';
            html += '<div class="swps-comp-newposts-h">' + escapeHtml(i18n.recentPosts || 'New posts') + '</div><ul>';
            diff.new_posts.forEach(function (p) {
                html += '<li><a href="' + escapeHtml(p.url) + '" target="_blank" rel="noopener">' + escapeHtml(p.title || p.url) + '</a>';
                if (p.date) html += ' <span class="swps-comp-newposts-date">' + escapeHtml(p.date) + '</span>';
                html += '</li>';
            });
            html += '</ul></div>';
        }

        html += '</div>'; // /row-card
        return html;
    }

    function renderEmpty() {
        return '<div class="swps-row-card swps-comp-empty"><p>' + escapeHtml(i18n.empty || 'No competitors tracked yet.') + '</p></div>';
    }

    function applyList(list, summary) {
        if (!list || !list.length) {
            $list.html(renderEmpty());
        } else {
            var html = '';
            list.forEach(function (c) { html += renderRow(c); });
            $list.html(html);
        }
        if (summary) {
            $tileCount.text(summary.count | 0);
            $tileNew.text(summary.new_posts_week | 0);
            $tileChanges.text(summary.changes | 0);
            $tileLast.text(summary.latest_scan ? timeAgo(summary.latest_scan) : (i18n.never || 'never'));
        }
    }

    // ---------- Form ----------

    function showForm(prefill) {
        $formId.val(prefill ? prefill.id : '');
        $formUrl.val(prefill ? prefill.url : '');
        $formLabel.val(prefill ? prefill.label : '');
        $form.show();
        $formUrl.trigger('focus');
    }

    function hideForm() {
        $form.hide();
        $formId.val('');
        $formUrl.val('');
        $formLabel.val('');
        setBusy(false);
    }

    $('#swps-comp-add-toggle').on('click', function (e) {
        e.preventDefault();
        var rows = $list.find('.swps-comp-row').length;
        if (rows >= maxN) {
            window.alert(i18n.limitReached || 'Limit reached.');
            return;
        }
        if ($form.is(':visible') && !$formId.val()) {
            hideForm();
        } else {
            showForm(null);
        }
    });

    $('#swps-comp-form-cancel').on('click', function (e) {
        e.preventDefault();
        hideForm();
    });

    $('#swps-comp-form-save').on('click', function (e) {
        e.preventDefault();
        var url = ($formUrl.val() || '').trim();
        if (!/^https?:\/\//i.test(url)) {
            window.alert(i18n.invalidUrl || 'Invalid URL.');
            return;
        }
        setBusy(i18n.saving || 'Saving...');
        ajax('swps_competitor_save', {
            id:    $formId.val(),
            url:   url,
            label: ($formLabel.val() || '').trim()
        }).done(function (resp) {
            if (resp && resp.success) {
                applyList(resp.data.list, resp.data.summary);
                hideForm();
            } else {
                window.alert((resp && resp.data && resp.data.message) || (i18n.genericFail || 'Failed.'));
                setBusy(false);
            }
        }).fail(function () {
            window.alert(i18n.genericFail || 'Failed.');
            setBusy(false);
        });
    });

    // ---------- Row actions ----------

    $list.on('click', '.swps-comp-edit', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.swps-comp-row');
        showForm({
            id:    $row.data('id'),
            url:   $row.data('url'),
            label: $row.data('label')
        });
    });

    $list.on('click', '.swps-comp-delete', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.swps-comp-row');
        if (!window.confirm(i18n.confirmDelete || 'Delete?')) return;
        setBusy(i18n.deleting || 'Deleting...');
        ajax('swps_competitor_delete', { id: $row.data('id') })
            .done(function (resp) {
                if (resp && resp.success) {
                    applyList(resp.data.list, resp.data.summary);
                    hideForm();
                } else {
                    window.alert((resp && resp.data && resp.data.message) || (i18n.genericFail || 'Failed.'));
                    setBusy(false);
                }
            })
            .fail(function () { window.alert(i18n.genericFail || 'Failed.'); setBusy(false); });
    });

    $list.on('click', '.swps-comp-scan', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.swps-comp-row');
        setBusy(i18n.scanning || 'Scanning...');
        ajax('swps_competitor_scan', { id: $row.data('id') })
            .done(function (resp) {
                if (resp && resp.success) {
                    applyList(resp.data.list, resp.data.summary);
                    hideForm();
                } else {
                    window.alert((resp && resp.data && resp.data.message) || (i18n.genericFail || 'Failed.'));
                    setBusy(false);
                }
            })
            .fail(function () { window.alert(i18n.genericFail || 'Failed.'); setBusy(false); });
    });

    $list.on('click', '[data-toggle-newposts]', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.swps-comp-row');
        $row.find('.swps-comp-newposts-list').slideToggle(120);
    });

    // ---------- Scan-all ----------

    $('#swps-comp-scan-all').on('click', function (e) {
        e.preventDefault();
        if (!$list.find('.swps-comp-row').length) {
            window.alert(i18n.empty || 'No competitors to scan.');
            return;
        }
        setBusy(i18n.scanningAll || 'Scanning all competitors...');
        ajax('swps_competitor_scan_all')
            .done(function (resp) {
                if (resp && resp.success) {
                    applyList(resp.data.list, resp.data.summary);
                } else {
                    window.alert((resp && resp.data && resp.data.message) || (i18n.genericFail || 'Failed.'));
                }
            })
            .fail(function () { window.alert(i18n.genericFail || 'Failed.'); })
            .always(function () { setBusy(false); });
    });

})(jQuery);
