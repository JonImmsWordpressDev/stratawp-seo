/* global jQuery, swpsAeo */
(function ($) {
    'use strict';

    var $progress     = $('#swps-aeo-progress');
    var $progressFill = $('#swps-aeo-progress-fill');
    var $progressText = $('#swps-aeo-progress-text');
    var $queue        = $('#swps-aeo-queue tbody');
    var $modal        = $('#swps-aeo-modal');
    var $modalInner   = $modal.find('.swps-aeo-modal-inner');

    var allResults = [];

    function escapeHtml(s) {
        return $('<i>').text(String(s == null ? '' : s)).html();
    }

    function scoreClass(s) {
        if (s < 50) return 'low';
        if (s < 75) return 'mid';
        return 'high';
    }

    function chip(dim, val) {
        var display = (val === null || val === undefined) ? '—' : val;
        return '<span class="swps-aeo-subscore-chip dim-' + dim + '">' +
            dim.substring(0, 3).toUpperCase() + ': ' + display +
        '</span>';
    }

    function currentThreshold() {
        return parseInt($('#swps-aeo-threshold').val(), 10) || swpsAeo.threshold || 70;
    }

    function renderQueue() {
        var threshold = currentThreshold();
        var ptFilter  = $('#swps-aeo-post-type').val() || '';
        var filtered  = allResults
            .filter(function (r) { return r.score < threshold; })
            .filter(function (r) { return !ptFilter || r.post_type === ptFilter; })
            .sort(function (a, b) { return a.score - b.score; });

        if (filtered.length === 0) {
            $queue.html('<tr class="swps-aeo-empty"><td colspan="4">' + escapeHtml(swpsAeo.i18n.noPosts) + '</td></tr>');
            return;
        }

        var rows = filtered.map(function (r) {
            var sub = r.subscores || {};
            var actionBtn = r.has_proposal
                ? '<button class="button swps-aeo-review"  data-id="' + r.post_id + '">' + escapeHtml(swpsAeo.i18n.review) + '</button>'
                : '<button class="button swps-aeo-propose" data-id="' + r.post_id + '">' + escapeHtml(swpsAeo.i18n.generate) + '</button>';
            return '<tr data-post-id="' + r.post_id + '">' +
                '<td><span class="swps-aeo-score-cell ' + scoreClass(r.score) + '">' + r.score + '</span></td>' +
                '<td>' +
                    '<a href="' + escapeHtml(r.edit_url || '#') + '" target="_blank">' + escapeHtml(r.title) + '</a>' +
                    '<br><small>' + escapeHtml(r.permalink || '') + '</small>' +
                '</td>' +
                '<td>' +
                    chip('extractability', sub.extractability) +
                    chip('markup',         sub.markup) +
                    chip('authority',      sub.authority) +
                    chip('coverage',       sub.coverage) +
                '</td>' +
                '<td>' +
                    actionBtn + ' ' +
                    '<button class="button-link swps-aeo-dismiss" data-id="' + r.post_id + '">' + escapeHtml(swpsAeo.i18n.dismiss) + '</button>' +
                '</td>' +
            '</tr>';
        }).join('');
        $queue.html(rows);
    }

    function updateTiles() {
        var total  = allResults.length;
        var below  = allResults.filter(function (r) { return r.score < currentThreshold(); }).length;
        var avg    = total > 0 ? Math.round(allResults.reduce(function (a, r) { return a + r.score; }, 0) / total) : 0;
        var dims   = ['extractability', 'markup', 'authority', 'coverage'];
        var dimAvg = {};
        dims.forEach(function (d) {
            var vals = allResults
                .map(function (r) { return r.subscores ? r.subscores[d] : null; })
                .filter(function (v) { return v !== null && v !== undefined; });
            dimAvg[d] = vals.length > 0
                ? Math.round(vals.reduce(function (a, b) { return a + b; }, 0) / vals.length)
                : 0;
        });
        var weakest = total > 0
            ? Object.keys(dimAvg).sort(function (a, b) { return dimAvg[a] - dimAvg[b]; })[0]
            : '—';
        var weakestLabel = total > 0 ? (weakest + ' (' + dimAvg[weakest] + ')') : '—';

        $('#swps-aeo-tile-scored .swps-tile-num').text(total);
        $('#swps-aeo-tile-below .swps-tile-num').text(below);
        $('#swps-aeo-tile-avg .swps-tile-num').text(avg);
        $('#swps-aeo-tile-low-dim .swps-tile-num').text(weakestLabel);
    }

    function rescan() {
        allResults = [];
        $progress.show();
        $progressFill.css('width', '0%');
        $progressText.text(swpsAeo.i18n.scanning);
        scanChunk(0);
    }

    function scanChunk(offset) {
        $.post(swpsAeo.ajaxUrl, {
            action: 'swps_aeo_scan_chunk',
            nonce:  swpsAeo.nonce,
            offset: offset
        }).done(function (resp) {
            if (!resp || !resp.success) {
                $progressText.text(swpsAeo.i18n.genericFail);
                return;
            }
            allResults = allResults.concat(resp.data.results || []);
            var total = resp.data.total || 1;
            var done  = resp.data.next_offset || 0;
            $progressFill.css('width', Math.min(100, (done / Math.max(1, total)) * 100) + '%');
            $progressText.text(done + ' / ' + total);

            if (resp.data.done) {
                $progress.hide();
                updateTiles();
                renderQueue();
            } else {
                scanChunk(resp.data.next_offset);
            }
        }).fail(function () {
            $progressText.text(swpsAeo.i18n.genericFail);
            $progress.hide();
        });
    }

    function propose(postId) {
        var $btn = $('button.swps-aeo-propose[data-id="' + postId + '"], button.swps-aeo-review[data-id="' + postId + '"]');
        $btn.prop('disabled', true).text(swpsAeo.i18n.proposing);
        $.post(swpsAeo.ajaxUrl, {
            action: 'swps_aeo_propose',
            nonce:  swpsAeo.nonce,
            post_id: postId
        }).done(function (resp) {
            $btn.prop('disabled', false);
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || swpsAeo.i18n.genericFail);
                $btn.text(swpsAeo.i18n.generate);
                return;
            }
            openModal(postId, resp.data.proposal || {});
        }).fail(function () {
            $btn.prop('disabled', false).text(swpsAeo.i18n.generate);
            alert(swpsAeo.i18n.genericFail);
        });
    }

    function openModal(postId, proposal) {
        var proj = proposal.projected_score != null ? proposal.projected_score : '—';
        var html = '<h2>' + escapeHtml(swpsAeo.i18n.projected) + ': ' + escapeHtml(proj) + '</h2>';

        if (proposal.schema && proposal.schema.type && proposal.schema.type !== 'null') {
            var validErr = proposal.schema.validation_error;
            html += '<h3>' + escapeHtml(swpsAeo.i18n.schemaSection) + '</h3>';
            if (validErr) {
                html += '<p style="color:#dc2626;">Schema generation failed validation: ' + escapeHtml(validErr) + '</p>';
            } else if (proposal.schema.json) {
                html += '<label><input type="checkbox" class="swps-aeo-sel-schema" checked> Add ' + escapeHtml(proposal.schema.type) + ' schema</label>';
                html += '<pre class="schema-preview">' + escapeHtml(JSON.stringify(proposal.schema.json, null, 2)) + '</pre>';
            }
        }

        if (proposal.inserts && proposal.inserts.length) {
            html += '<h3>' + escapeHtml(swpsAeo.i18n.insertsSection) + ' (' + proposal.inserts.length + ')</h3>';
            html += '<ul>';
            proposal.inserts.forEach(function (ins, i) {
                html += '<li><label>' +
                    '<input type="checkbox" class="swps-aeo-sel-insert" data-idx="' + i + '" checked> ' +
                    '<strong>' + escapeHtml(ins.kind || '') + '</strong> @ ' + escapeHtml(ins.anchor || '') +
                    '<br><em>' + escapeHtml(ins.reason || '') + '</em>' +
                '</label></li>';
            });
            html += '</ul>';
        }

        if (proposal.edits && proposal.edits.length) {
            html += '<h3>' + escapeHtml(swpsAeo.i18n.editsSection) + ' (' + proposal.edits.length + ')</h3>';
            html += '<ul>';
            proposal.edits.forEach(function (e, i) {
                html += '<li><label>' +
                    '<input type="checkbox" class="swps-aeo-sel-edit" data-idx="' + i + '" checked> ' +
                    '<span class="diff-del">' + escapeHtml(e.find || '') + '</span> &rarr; ' +
                    '<span class="diff-add">' + escapeHtml(e.replace || '') + '</span>' +
                    '<br><em>' + escapeHtml(e.reason || '') + '</em>' +
                '</label></li>';
            });
            html += '</ul>';
        }

        html += '<div style="margin-top:20px;text-align:right;">';
        html += '<button class="button button-primary" id="swps-aeo-apply" data-id="' + postId + '">' + escapeHtml(swpsAeo.i18n.apply) + '</button> ';
        html += '<button class="button" id="swps-aeo-cancel">' + escapeHtml(swpsAeo.i18n.cancel) + '</button>';
        html += '</div>';

        $modalInner.html(html);
        $modal.show();
    }

    function apply(postId) {
        var edits = $modal.find('.swps-aeo-sel-edit:checked').map(function () { return parseInt($(this).data('idx'), 10); }).get();
        var inserts = $modal.find('.swps-aeo-sel-insert:checked').map(function () { return parseInt($(this).data('idx'), 10); }).get();
        var schema = $modal.find('.swps-aeo-sel-schema:checked').length > 0;

        $.post(swpsAeo.ajaxUrl, {
            action: 'swps_aeo_apply',
            nonce:  swpsAeo.nonce,
            post_id: postId,
            edits:   edits,
            inserts: inserts,
            schema:  schema ? 1 : 0
        }).done(function (resp) {
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || swpsAeo.i18n.genericFail);
                return;
            }
            // Update local cache.
            var idx = allResults.findIndex(function (r) { return r.post_id === postId; });
            if (idx >= 0) {
                allResults[idx].score        = resp.data.new_score;
                allResults[idx].subscores    = resp.data.new_subscores;
                allResults[idx].has_proposal = false;
            }
            $modal.hide();
            updateTiles();
            renderQueue();
        }).fail(function () { alert(swpsAeo.i18n.genericFail); });
    }

    function dismiss(postId) {
        $.post(swpsAeo.ajaxUrl, {
            action: 'swps_aeo_dismiss',
            nonce:  swpsAeo.nonce,
            post_id: postId,
            dismissed: 1
        }).done(function () {
            allResults = allResults.filter(function (r) { return r.post_id !== postId; });
            updateTiles();
            renderQueue();
        });
    }

    // Wire events.
    $('#swps-aeo-rescan').on('click', rescan);
    $('#swps-aeo-threshold, #swps-aeo-post-type').on('change input', function () {
        updateTiles();
        renderQueue();
    });
    $(document).on('click', '.swps-aeo-propose', function () { propose(parseInt($(this).data('id'), 10)); });
    $(document).on('click', '.swps-aeo-review',  function () { propose(parseInt($(this).data('id'), 10)); });
    $(document).on('click', '.swps-aeo-dismiss', function () { dismiss(parseInt($(this).data('id'), 10)); });
    $(document).on('click', '#swps-aeo-cancel',  function () { $modal.hide(); });
    $(document).on('click', '#swps-aeo-apply',   function () { apply(parseInt($(this).data('id'), 10)); });

    // Support ?focus_post=N URL param: deep-link from Bot Analytics (Task 19).
    var match = window.location.search.match(/[?&]focus_post=(\d+)/);
    if (match) {
        var focusId = parseInt(match[1], 10);
        // Wait until DOM is ready then trigger propose.
        $(function () { setTimeout(function () { propose(focusId); }, 100); });
    }

})(jQuery);
