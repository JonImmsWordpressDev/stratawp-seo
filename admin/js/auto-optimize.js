/**
 * StrataWP SEO — Auto-Optimize page (v4.1).
 *
 * Wires the queue UI, AI proposal flow, review modal, and apply step.
 * AJAX uses the localized swpsOptimize object set by SWPS_Auto_Optimize::enqueue_assets().
 */
(function ($) {
    'use strict';

    if (typeof swpsOptimize === 'undefined') {
        return;
    }

    var i18n = swpsOptimize.i18n || {};

    var $queue        = $('#swps-optimize-queue');
    var $modal        = $('#swps-optimize-modal');
    var $modalBody    = $('#swps-optimize-modal-body');
    var $progress     = $('#swps-optimize-progress');
    var $progressText = $('#swps-optimize-progress-text');
    var $threshold    = $('#swps-optimize-threshold');
    var $tileCount    = $('#swps-optimize-tile-count');
    var $tileProposed = $('#swps-optimize-tile-proposed');
    var $tileLift     = $('#swps-optimize-tile-lift');
    var $tileCost     = $('#swps-optimize-tile-cost');

    var activeProposal = null; // { postId, proposal, currentScore }

    function setBusy(msg) {
        if (msg) {
            $progressText.text(msg);
            $progress.css('display', 'flex');
        } else {
            $progress.hide();
        }
    }

    function ajax(action, data) {
        return $.post(swpsOptimize.ajaxUrl, $.extend({
            action: action,
            nonce: swpsOptimize.nonce
        }, data || {}));
    }

    function statusDotClass(score) {
        if (score >= 80) return 'swps-status-dot-ok';
        if (score >= 50) return 'swps-status-dot-warn';
        return 'swps-status-dot-crit';
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function format(template, value) {
        return String(template).replace('%d', value).replace('%s', value);
    }

    function renderRow(row) {
        var has = !!row.has_proposal;
        var current = parseInt(row.current_score, 10) || 0;
        var proj = has ? (parseInt(row.proposal.projected_score, 10) || 0) : null;
        var topRecs = row.top_recs || [];
        var reason = topRecs.length ? topRecs[0] : '';
        var glyph = current >= 80 ? '✓' : '!';
        var status = has ? 'proposed' : 'needs';

        var html = '<div class="swps-row-card swps-optimize-row" '
            + 'data-post-id="' + (row.post_id|0) + '" '
            + 'data-current-score="' + current + '" '
            + 'data-status="' + status + '">';

        html += '<div class="swps-row-head">';
        html += '<div class="swps-status-dot ' + statusDotClass(current) + '">' + glyph + '</div>';
        html += '<div>';
        html += '<div class="swps-row-head-name">';
        html += '<a href="' + escapeHtml(row.edit_url) + '" target="_blank" rel="noopener">' + escapeHtml(row.title) + '</a>';
        html += '</div>';
        html += '<div class="swps-row-head-msg">';
        html += escapeHtml(reason || 'Click "Generate proposal" for AI suggestions.');
        html += ' &middot; ' + (row.word_count|0) + ' ' + escapeHtml(i18n.words || 'words');
        html += ' &middot; <a href="' + escapeHtml(row.permalink) + '" target="_blank" rel="noopener">' + escapeHtml(i18n.view || 'View') + '</a>';
        html += '</div>';
        html += '</div>';

        html += '<div class="swps-row-head-meta">';
        html += '<div class="swps-row-head-score">' + current;
        if (proj !== null) {
            html += '<span class="swps-optimize-arrow">&rarr;</span>';
            html += '<span class="swps-optimize-projected">' + proj + '</span>';
        }
        html += '</div>';
        if (has) {
            html += '<button class="swps-btn swps-btn-grad swps-optimize-review" style="padding:6px 12px;font-size:11px">' + escapeHtml(i18n.review || 'Review') + '</button>';
            html += '<button class="swps-btn swps-btn-secondary swps-optimize-propose" title="' + escapeHtml(i18n.reGenerate || 'Re-generate') + '" style="padding:6px 10px;font-size:11px"><span class="dashicons dashicons-update"></span></button>';
        } else {
            html += '<button class="swps-btn swps-btn-grad swps-optimize-propose" style="padding:6px 12px;font-size:11px">' + escapeHtml(i18n.generate || 'Generate proposal') + '</button>';
        }
        html += '<button class="swps-btn swps-btn-secondary swps-optimize-dismiss" title="' + escapeHtml(i18n.dismiss || 'Dismiss') + '" style="padding:6px 10px;font-size:11px"><span class="dashicons dashicons-no-alt"></span></button>';
        html += '</div>';
        html += '</div></div>';
        return html;
    }

    function renderEmpty() {
        return '<div class="swps-row-card swps-optimize-empty"><p>' + escapeHtml(i18n.empty || 'Nothing to optimize.') + '</p></div>';
    }

    function applyQueue(rows, stats) {
        if (!rows || !rows.length) {
            $queue.html(renderEmpty());
        } else {
            var html = '';
            rows.forEach(function (r) { html += renderRow(r); });
            $queue.html(html);
            applyFilter(getActiveFilter());
        }

        if (stats) {
            $tileCount.text(stats.count|0);
            $tileProposed.text(stats.with_proposal|0);
            $tileLift.text('+' + (stats.avg_lift|0));
            $tileCost.text('$' + Number(stats.est_cost || 0).toFixed(4));
        }
    }

    function refresh(rescore) {
        setBusy(rescore ? (i18n.scoring || 'Re-scoring...') : (i18n.loading || 'Loading...'));
        $.ajax({
            url:      swpsOptimize.ajaxUrl,
            method:   'POST',
            data:     {
                action:    'swps_optimize_scan',
                nonce:     swpsOptimize.nonce,
                threshold: parseInt($threshold.val(), 10) || 75,
                limit:     50,
                rescore:   rescore ? 1 : 0
            },
            timeout:  rescore ? 240000 : 60000  // 4-min for re-score, 60s for cached load
        }).done(function (resp) {
            if (resp && resp.success) {
                applyQueue(resp.data.rows, resp.data.stats);
            } else {
                window.alert((resp && resp.data && resp.data.message) || (i18n.genericFail || 'Request failed.'));
            }
        }).fail(function (xhr, status, err) {
            var detail = '';
            if (status === 'timeout') {
                detail = ' (request timed out — try a smaller batch or fewer posts)';
            } else if (xhr && xhr.status) {
                detail = ' (HTTP ' + xhr.status + (err ? ': ' + err : '') + ')';
            } else if (status) {
                detail = ' (' + status + ')';
            }
            window.alert((i18n.genericFail || 'Request failed.') + detail);
        }).always(function () {
            setBusy(false);
        });
    }

    function findRow(postId) {
        return $queue.find('.swps-optimize-row[data-post-id="' + postId + '"]');
    }

    function propose(postId) {
        setBusy(i18n.proposing || 'Generating proposal...');
        ajax('swps_optimize_propose', { post_id: postId })
            .done(function (resp) {
                if (resp && resp.success) {
                    var $row = findRow(postId);
                    activeProposal = {
                        postId: postId,
                        proposal: resp.data,
                        currentScore: parseInt($row.data('current-score'), 10) || 0
                    };
                    openReview();
                    refresh(false);
                } else {
                    window.alert((resp && resp.data && resp.data.message) || (i18n.genericFail || 'Request failed.'));
                }
            })
            .fail(function () {
                window.alert(i18n.genericFail || 'Request failed.');
            })
            .always(function () {
                setBusy(false);
            });
    }

    function review(postId) {
        var $row = findRow(postId);
        var current = parseInt($row.data('current-score'), 10) || 0;
        setBusy(i18n.loading || 'Loading...');
        ajax('swps_optimize_scan', {
            threshold: 100,
            limit: 200,
            rescore: 0
        }).done(function (resp) {
            if (!resp || !resp.success) {
                window.alert(i18n.genericFail || 'Request failed.');
                return;
            }
            var rows = resp.data.rows || [];
            var match = null;
            for (var i = 0; i < rows.length; i++) {
                if (parseInt(rows[i].post_id, 10) === parseInt(postId, 10)) {
                    match = rows[i];
                    break;
                }
            }
            if (!match || !match.proposal) {
                window.alert(i18n.genericFail || 'Proposal not found.');
                return;
            }
            activeProposal = { postId: postId, proposal: match.proposal, currentScore: current };
            openReview();
        }).always(function () {
            setBusy(false);
        });
    }

    function openReview() {
        if (!activeProposal) return;
        var p = activeProposal.proposal;
        var current = activeProposal.currentScore;
        var projected = parseInt(p.projected_score || 0, 10);

        var html = '';
        html += '<div class="swps-optimize-review-summary">';
        html += '<div class="swps-optimize-review-scores">';
        html += '<span>' + current + '</span>';
        html += '<span class="swps-optimize-arrow">&rarr;</span>';
        html += '<span class="swps-optimize-projected">' + projected + '</span>';
        html += '<span class="swps-optimize-review-cost">' + escapeHtml(i18n.estCost || 'est. cost') + ' $' + Number(p.estimated_cost || 0).toFixed(4) + '</span>';
        html += '</div>';
        if (p.summary) {
            html += '<p class="swps-optimize-review-rationale">' + escapeHtml(p.summary) + '</p>';
        }
        html += '</div>';

        var hasMeta = p.meta_title || p.meta_description || p.focus_keyword;
        if (hasMeta) {
            html += '<h3>' + escapeHtml(i18n.metaSection || 'Metadata changes') + '</h3>';
            html += '<div class="swps-optimize-edits">';
            if (p.meta_title)       html += metaRow('meta_title',       i18n.metaTitle || 'Meta title',       p.meta_title);
            if (p.meta_description) html += metaRow('meta_description', i18n.metaDesc  || 'Meta description', p.meta_description);
            if (p.focus_keyword)    html += metaRow('focus_keyword',    i18n.focusKw   || 'Focus keyword',    p.focus_keyword);
            html += '</div>';
        }

        if (p.edits && p.edits.length) {
            html += '<h3>' + escapeHtml(i18n.editsSection || 'Content edits') + '</h3>';
            html += '<div class="swps-optimize-edits">';
            p.edits.forEach(function (edit, idx) {
                html += '<label class="swps-optimize-edit">';
                html += '<input type="checkbox" data-edit-idx="' + idx + '" checked />';
                html += '<div class="swps-optimize-edit-body">';
                html += '<div class="swps-optimize-edit-head"><strong>' + escapeHtml(edit.type) + '</strong>';
                if (edit.reason) html += '<span class="swps-optimize-edit-reason">' + escapeHtml(edit.reason) + '</span>';
                html += '</div>';
                if (edit.find_text)    html += '<div class="swps-optimize-edit-diff swps-optimize-edit-diff--from"><pre>' + escapeHtml(edit.find_text) + '</pre></div>';
                if (edit.replace_text) html += '<div class="swps-optimize-edit-diff swps-optimize-edit-diff--to"><pre>' + escapeHtml(edit.replace_text) + '</pre></div>';
                html += '</div></label>';
            });
            html += '</div>';
        }

        if (!hasMeta && (!p.edits || !p.edits.length)) {
            html += '<p>' + escapeHtml(i18n.noEdits || 'No edits proposed.') + '</p>';
        }

        $modalBody.html(html);
        $modal.show().attr('aria-hidden', 'false');
    }

    function metaRow(field, label, value) {
        var html = '<label class="swps-optimize-edit">';
        html += '<input type="checkbox" data-meta-field="' + field + '" checked />';
        html += '<div class="swps-optimize-edit-body">';
        html += '<div class="swps-optimize-edit-head"><strong>' + escapeHtml(label) + '</strong></div>';
        html += '<div class="swps-optimize-edit-diff swps-optimize-edit-diff--to"><pre>' + escapeHtml(value) + '</pre></div>';
        html += '</div></label>';
        return html;
    }

    function closeReview() {
        $modal.hide().attr('aria-hidden', 'true');
        activeProposal = null;
    }

    function applyAccepted() {
        if (!activeProposal) return;
        var accepted = {
            meta_title:       $modalBody.find('input[data-meta-field="meta_title"]:checked').length > 0 ? 1 : 0,
            meta_description: $modalBody.find('input[data-meta-field="meta_description"]:checked').length > 0 ? 1 : 0,
            focus_keyword:    $modalBody.find('input[data-meta-field="focus_keyword"]:checked').length > 0 ? 1 : 0,
            edits: []
        };
        $modalBody.find('input[data-edit-idx]:checked').each(function () {
            accepted.edits.push(parseInt($(this).data('edit-idx'), 10));
        });

        setBusy(i18n.applying || 'Applying...');
        ajax('swps_optimize_apply', {
            post_id: activeProposal.postId,
            accepted: accepted
        }).done(function (resp) {
            if (resp && resp.success) {
                closeReview();
                refresh(false);
            } else {
                window.alert((resp && resp.data && resp.data.message) || (i18n.genericFail || 'Request failed.'));
            }
        }).fail(function () {
            window.alert(i18n.genericFail || 'Request failed.');
        }).always(function () {
            setBusy(false);
        });
    }

    function dismiss(postId) {
        if (!window.confirm(i18n.confirmDismiss || 'Dismiss?')) return;
        setBusy(i18n.dismissing || 'Dismissing...');
        ajax('swps_optimize_dismiss', { post_id: postId })
            .done(function (resp) {
                if (resp && resp.success) {
                    refresh(false);
                } else {
                    window.alert(i18n.genericFail || 'Request failed.');
                }
            })
            .always(function () { setBusy(false); });
    }

    // Filter chips
    function getActiveFilter() {
        return $('.swps-filter-chip.is-on').data('swps-filter') || 'all';
    }
    function applyFilter(filter) {
        $queue.find('.swps-optimize-row').each(function () {
            var status = $(this).data('status');
            var show = filter === 'all'
                || (filter === 'proposed' && status === 'proposed')
                || (filter === 'needs'    && status === 'needs');
            $(this).css('display', show ? '' : 'none');
        });
    }

    // Chunked rescan — scans 10 posts per request so we don't hit shared-host PHP timeouts.
    function rescanAll() {
        var offset    = 0;
        var chunkSize = 10;
        var totalScanned = 0;

        setBusy((i18n.scoring || 'Re-scoring...') + ' 0%');

        function next() {
            $.ajax({
                url:    swpsOptimize.ajaxUrl,
                method: 'POST',
                data:   {
                    action:     'swps_optimize_scan_chunk',
                    nonce:      swpsOptimize.nonce,
                    offset:     offset,
                    chunk_size: chunkSize
                },
                timeout: 90000  // 90s per chunk
            }).done(function (resp) {
                if (!resp || !resp.success) {
                    window.alert((resp && resp.data && resp.data.message) || (i18n.genericFail || 'Request failed.'));
                    setBusy(false);
                    return;
                }
                var d = resp.data || {};
                totalScanned += (d.scanned | 0);
                offset = d.next_offset | 0;
                var total = d.total | 0;
                var pct   = total > 0 ? Math.min(100, Math.round((offset / total) * 100)) : 0;
                setBusy(
                    (i18n.scoring || 'Re-scoring...') + ' ' + offset + '/' + total + ' (' + pct + '%)'
                );

                if (d.has_more) {
                    next();
                } else {
                    // All chunks done — load the cached queue (no rescore) to render.
                    refresh(false);
                }
            }).fail(function (xhr, status, err) {
                var detail = '';
                if (status === 'timeout') {
                    detail = ' (chunk timed out at offset ' + offset + ' — try fewer posts per batch)';
                } else if (xhr && xhr.status) {
                    detail = ' (HTTP ' + xhr.status + (err ? ': ' + err : '') + ')';
                } else if (status) {
                    detail = ' (' + status + ')';
                }
                window.alert((i18n.genericFail || 'Request failed.') + detail);
                setBusy(false);
            });
        }

        next();
    }

    // Wire events
    $('#swps-optimize-rescan').on('click', function (e) { e.preventDefault(); rescanAll(); });
    $('#swps-optimize-refresh').on('click', function (e) { e.preventDefault(); refresh(false); });
    $threshold.on('change', function () { refresh(false); });

    $queue.on('click', '.swps-optimize-propose', function (e) {
        e.preventDefault();
        propose($(this).closest('.swps-optimize-row').data('post-id'));
    });
    $queue.on('click', '.swps-optimize-review', function (e) {
        e.preventDefault();
        review($(this).closest('.swps-optimize-row').data('post-id'));
    });
    $queue.on('click', '.swps-optimize-dismiss', function (e) {
        e.preventDefault();
        dismiss($(this).closest('.swps-optimize-row').data('post-id'));
    });

    $('.swps-filter-chip').on('click', function () {
        $('.swps-filter-chip').removeClass('is-on');
        $(this).addClass('is-on');
        applyFilter($(this).data('swps-filter'));
    });

    $modal.on('click', '[data-close]', closeReview);
    $('#swps-optimize-apply').on('click', applyAccepted);
    $(document).on('keyup', function (e) {
        if (e.key === 'Escape') closeReview();
    });

})(jQuery);
