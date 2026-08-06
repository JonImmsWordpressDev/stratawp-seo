/* global jQuery, swpsBacklinks */
(function ($) {
    'use strict';

    var i18n = swpsBacklinks.i18n;

    function setStats(stats) {
        if (!stats) return;
        $('#swps-bl-stat-total').text((stats.total || 0).toLocaleString());
        $('#swps-bl-stat-live').text((stats.live || 0).toLocaleString());
        $('#swps-bl-stat-lost').text((stats.lost || 0).toLocaleString());
        $('#swps-bl-stat-broken').text((stats.broken || 0).toLocaleString());
        $('#swps-bl-stat-blocked').text((stats.blocked || 0).toLocaleString());
    }

    function statusPill(row) {
        var cls = 'is-' + row.status;
        var label = row.status.charAt(0).toUpperCase() + row.status.slice(1);
        var http = ((row.status === 'broken' && row.http_status > 0) ||
                    (row.status === 'blocked' && row.http_status >= 400))
            ? ' <small>(' + row.http_status + ')</small>'
            : '';
        return '<span class="swps-bl-status ' + cls + '">' + label + http + '</span>';
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderRow(row) {
        var $existing = $('#swps-bl-tbody tr[data-id="' + row.id + '"]');
        var html = '' +
            '<td>' + statusPill(row) + '</td>' +
            '<td><a href="' + escapeHtml(row.source_url) + '" target="_blank" rel="noopener">' +
                escapeHtml(row.source_host || row.source_url) + '</a>' +
                (row.notes ? '<div style="font-size:11px;color:var(--swps-text-muted);margin-top:2px">' + escapeHtml(row.notes) + '</div>' : '') +
            '</td>' +
            '<td><span style="font-size:12px">' + (row.anchor_text ? escapeHtml(row.anchor_text) : '<span style="color:var(--swps-text-faint)">—</span>') + '</span></td>' +
            '<td>' + (row.target_url
                ? '<a href="' + escapeHtml(row.target_url) + '" target="_blank" rel="noopener" style="font-size:12px">' + escapeHtml(row.target_url.replace(/^https?:\/\/[^\/]+/, '') || row.target_url) + '</a>'
                : '<span style="color:var(--swps-text-faint);font-size:12px">—</span>') + '</td>' +
            '<td style="font-size:12px;color:var(--swps-text-muted)">' + (row.first_seen ? row.first_seen.slice(0, 10) : '—') + '</td>' +
            '<td style="font-size:12px;color:var(--swps-text-muted)">' + (row.last_checked ? 'just now' : 'never') + '</td>' +
            '<td style="text-align:right;white-space:nowrap">' +
                '<button type="button" class="button-link swps-bl-verify" title="Re-verify">⟳</button>' +
                '<button type="button" class="button-link swps-bl-delete" title="Delete" style="color:#ef4444">✕</button>' +
            '</td>';

        if ($existing.length) {
            $existing.html(html).removeClass('is-pulsing');
        } else {
            var $tbody = $('#swps-bl-tbody');
            if (!$tbody.length) {
                window.location.reload();
                return;
            }
            $tbody.prepend('<tr data-id="' + row.id + '">' + html + '</tr>');
        }
    }

    // Add backlink
    $('#swps-bl-add').on('submit', function (e) {
        e.preventDefault();
        var $form   = $(this);
        var $status = $form.find('.swps-bl-add-status');
        var data    = {
            action:      'swps_backlink_save',
            nonce:       swpsBacklinks.nonce,
            source_url:  $form.find('[name=source_url]').val(),
            target_url:  $form.find('[name=target_url]').val(),
            anchor_text: $form.find('[name=anchor_text]').val()
        };
        $status.text(i18n.saving);
        $.post(swpsBacklinks.ajaxUrl, data).then(function (resp) {
            if (resp && resp.success) {
                $status.text('');
                if (resp.data.row) renderRow(resp.data.row);
                setStats(resp.data.stats);
                $form[0].reset();
            } else {
                $status.text((resp && resp.data && resp.data.message) || i18n.failed);
            }
        }).fail(function () { $status.text(i18n.failed); });
    });

    // Import
    $('#swps-bl-import').on('submit', function (e) {
        e.preventDefault();
        var $form   = $(this);
        var $status = $form.find('.swps-bl-import-status');
        var csv     = $form.find('[name=csv]').val();
        if (!csv) return;
        $status.text(i18n.importing);
        $.post(swpsBacklinks.ajaxUrl, {
            action: 'swps_backlink_import',
            nonce:  swpsBacklinks.nonce,
            csv:    csv
        }).then(function (resp) {
            if (resp && resp.success) {
                var n = resp.data.imported || 0;
                $status.text(n > 0 ? i18n.imported.replace('%d', n) : i18n.noNewLinks);
                setStats(resp.data.stats);
                if (n > 0) setTimeout(function () { window.location.reload(); }, 800);
            } else {
                $status.text((resp && resp.data && resp.data.message) || i18n.failed);
            }
        }).fail(function () { $status.text(i18n.failed); });
    });

    // Delete + verify (delegated)
    $('#swps-bl-tbody').on('click', '.swps-bl-delete', function () {
        if (!window.confirm(i18n.confirmDelete)) return;
        var $tr = $(this).closest('tr');
        var id  = $tr.data('id');
        $tr.addClass('is-pulsing');
        $.post(swpsBacklinks.ajaxUrl, {
            action: 'swps_backlink_delete',
            nonce:  swpsBacklinks.nonce,
            id:     id
        }).then(function (resp) {
            if (resp && resp.success) {
                $tr.fadeOut(150, function () { $tr.remove(); });
                setStats(resp.data.stats);
            } else {
                $tr.removeClass('is-pulsing');
            }
        }).fail(function () { $tr.removeClass('is-pulsing'); });
    });

    $('#swps-bl-tbody').on('click', '.swps-bl-verify', function () {
        var $tr = $(this).closest('tr');
        var id  = $tr.data('id');
        $tr.addClass('is-pulsing');
        $.post(swpsBacklinks.ajaxUrl, {
            action: 'swps_backlink_verify',
            nonce:  swpsBacklinks.nonce,
            id:     id
        }).then(function (resp) {
            if (resp && resp.success && resp.data.row) {
                renderRow(resp.data.row);
                setStats(resp.data.stats);
            } else {
                $tr.removeClass('is-pulsing');
            }
        }).fail(function () { $tr.removeClass('is-pulsing'); });
    });

    // Verify all
    $('#swps-bl-verify-all').on('click', function (e) {
        e.preventDefault();
        var $btn      = $(this);
        var $progress = $('#swps-bl-progress').removeAttr('hidden');
        $btn.prop('disabled', true);

        function runFrom(offset) {
            $progress.find('span').text(i18n.verifyingAll + ' (offset ' + offset + ')');
            return $.post(swpsBacklinks.ajaxUrl, {
                action: 'swps_backlink_verify',
                nonce:  swpsBacklinks.nonce,
                offset: offset
            }).then(function (resp) {
                if (!resp || !resp.success) throw new Error(i18n.failed);
                setStats(resp.data.stats);
                if (resp.data.processed > 0 && resp.data.remaining > 0) {
                    return runFrom(resp.data.next);
                }
                return resp.data;
            });
        }

        runFrom(0)
            .then(function () {
                $progress.find('span').text(i18n.verifyingAll + ' — done.');
                setTimeout(function () { window.location.reload(); }, 800);
            })
            .fail(function (err) {
                $progress.find('span').text((err && err.message) || i18n.failed);
                $btn.prop('disabled', false);
            });
    });
}(jQuery));
