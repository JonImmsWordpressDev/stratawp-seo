/**
 * StrataWP SEO — Keywords Page JS
 */
(function ($) {
    'use strict';

    if (typeof swpsAdmin === 'undefined') return;

    // --- Load tracked keywords ---
    function loadTracked() {
        $.post(swpsAdmin.ajax_url, {
            action: 'swps_get_keywords',
            nonce: swpsAdmin.nonce
        }, function (res) {
            if (!res.success) return;
            var tbody = $('#swps-tracked-table tbody');
            tbody.empty();

            if (!res.data.length) {
                tbody.html('<tr><td colspan="7">No tracked keywords yet. Use AI suggestions above to discover and track keywords.</td></tr>');
                return;
            }

            res.data.forEach(function (kw) {
                var postLink = kw.post_title
                    ? '<a href="' + escAttr(kw.post_url) + '">' + escHtml(kw.post_title) + '</a>'
                    : '<em>None</em>';
                var pos = kw.position !== null ? parseFloat(kw.position).toFixed(1) : '—';
                var row = '<tr>';
                row += '<td><a href="#" class="swps-kw-history" data-keyword="' + escAttr(kw.keyword) + '">' + escHtml(kw.keyword) + '</a></td>';
                row += '<td>' + pos + '</td>';
                row += '<td>' + parseInt(kw.clicks).toLocaleString() + '</td>';
                row += '<td>' + parseInt(kw.impressions).toLocaleString() + '</td>';
                row += '<td>' + parseFloat(kw.ctr).toFixed(1) + '%</td>';
                row += '<td>' + postLink + '</td>';
                row += '<td><button class="button button-small swps-untrack-btn" data-keyword="' + escAttr(kw.keyword) + '">Untrack</button></td>';
                row += '</tr>';
                tbody.append(row);
            });
        });
    }

    // --- Load opportunities ---
    function loadOpportunities() {
        if (!$('#swps-opportunities-table').length) return;
        $.post(swpsAdmin.ajax_url, {
            action: 'swps_get_opportunities',
            nonce: swpsAdmin.nonce
        }, function (res) {
            if (!res.success) return;
            var tbody = $('#swps-opportunities-table tbody');
            tbody.empty();

            if (!res.data.length) {
                tbody.html('<tr><td colspan="5">No striking distance keywords found yet. Track keywords and sync with GSC.</td></tr>');
                return;
            }

            res.data.forEach(function (opp) {
                var row = '<tr>';
                row += '<td>' + escHtml(opp.keyword) + '</td>';
                row += '<td>' + parseFloat(opp.position).toFixed(1) + '</td>';
                row += '<td>' + parseInt(opp.impressions).toLocaleString() + '</td>';
                row += '<td>' + parseFloat(opp.ctr).toFixed(1) + '%</td>';
                row += '<td>';
                row += '<a href="' + swpsAdmin.generate_url + '&topic=' + encodeURIComponent(opp.keyword) + '" class="button button-small">Generate Post</a>';
                row += '</td>';
                row += '</tr>';
                tbody.append(row);
            });
        });
    }

    // --- AI Suggestions ---
    $('#swps-suggest-btn').on('click', function () {
        var seed = $('#swps-seed-topic').val().trim();
        if (!seed) return;

        var btn = $(this);
        var spinner = $('#swps-suggest-spinner');
        btn.prop('disabled', true);
        spinner.addClass('is-active');

        $.ajax({
            url: swpsAdmin.ajax_url,
            method: 'POST',
            timeout: 120000,
            data: {
                action: 'swps_suggest_keywords',
                nonce: swpsAdmin.nonce,
                seed_topic: seed
            },
            success: function (res) {
                btn.prop('disabled', false);
                spinner.removeClass('is-active');

                if (!res.success) {
                    alert(res.data.message || 'Failed to get suggestions.');
                    return;
                }

                var table = $('#swps-suggestions-table');
                var tbody = table.find('tbody');
                tbody.empty();
                table.show();

                res.data.forEach(function (s) {
                    var row = '<tr>';
                    row += '<td><strong>' + escHtml(s.keyword) + '</strong></td>';
                    row += '<td><span class="swps-intent-badge swps-intent-' + escAttr(s.intent) + '">' + escHtml(s.intent) + '</span></td>';
                    row += '<td>' + escHtml(s.difficulty) + '</td>';
                    row += '<td>' + escHtml(s.suggested_title) + '</td>';
                    row += '<td><button class="button button-small swps-track-btn" data-keyword="' + escAttr(s.keyword) + '">Track</button></td>';
                    row += '</tr>';
                    tbody.append(row);
                });
            },
            error: function () {
                btn.prop('disabled', false);
                spinner.removeClass('is-active');
                alert('Request failed. Please check your AI provider settings and try again.');
            }
        });
    });

    // --- Track keyword ---
    $(document).on('click', '.swps-track-btn', function () {
        var btn = $(this);
        var keyword = btn.data('keyword');
        btn.prop('disabled', true).text('Tracking...');

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_track_keyword',
            nonce: swpsAdmin.nonce,
            keyword: keyword
        }, function () {
            btn.text('Tracked').addClass('disabled');
            loadTracked();
        });
    });

    // --- Untrack keyword ---
    $(document).on('click', '.swps-untrack-btn', function () {
        var keyword = $(this).data('keyword');
        if (!confirm('Stop tracking "' + keyword + '"?')) return;

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_untrack_keyword',
            nonce: swpsAdmin.nonce,
            keyword: keyword
        }, function () { loadTracked(); });
    });

    // --- Keyword history modal ---
    $(document).on('click', '.swps-kw-history', function (e) {
        e.preventDefault();
        var keyword = $(this).data('keyword');
        $('#swps-history-title').text(keyword);
        $('#swps-keyword-history-modal').show();

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_keyword_history',
            nonce: swpsAdmin.nonce,
            keyword: keyword,
            days: 90
        }, function (res) {
            if (!res.success || !res.data.length) {
                $('#swps-history-chart').html('<p>No history data yet.</p>');
                return;
            }
            renderPositionChart(res.data);
        });
    });

    $('.swps-modal-close').on('click', function () {
        $(this).closest('.swps-modal').hide();
    });

    // --- Position chart (SVG) ---
    function renderPositionChart(data) {
        var container = document.getElementById('swps-history-chart');
        if (!container) return;

        var width = container.offsetWidth || 600;
        var height = 200;
        var pad = { top: 20, right: 20, bottom: 30, left: 50 };
        var cW = width - pad.left - pad.right;
        var cH = height - pad.top - pad.bottom;

        // Position is inverted — lower is better.
        var positions = data.map(function (d) { return parseFloat(d.position) || 100; });
        var maxPos = Math.max.apply(null, positions);
        var minPos = Math.min.apply(null, positions);
        if (maxPos === minPos) maxPos = minPos + 10;

        var points = data.map(function (d, i) {
            var x = pad.left + (i / Math.max(data.length - 1, 1)) * cW;
            var pos = parseFloat(d.position) || 100;
            var y = pad.top + ((pos - minPos) / (maxPos - minPos)) * cH;
            return x + ',' + y;
        });

        var svg = '<svg width="' + width + '" height="' + height + '" xmlns="http://www.w3.org/2000/svg">';
        svg += '<polyline points="' + points.join(' ') + '" fill="none" stroke="#2271b1" stroke-width="2" />';

        // Y-axis: position labels (inverted).
        for (var i = 0; i <= 4; i++) {
            var yVal = Math.round(minPos + ((maxPos - minPos) / 4) * i);
            var yPos = pad.top + (i / 4) * cH;
            svg += '<text x="' + (pad.left - 8) + '" y="' + (yPos + 4) + '" text-anchor="end" fill="#646970" font-size="11">#' + yVal + '</text>';
            svg += '<line x1="' + pad.left + '" y1="' + yPos + '" x2="' + (width - pad.right) + '" y2="' + yPos + '" stroke="#e0e0e0" />';
        }

        svg += '</svg>';
        container.innerHTML = svg;
    }

    // --- Helpers ---
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function escAttr(str) {
        return (str || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // --- Init ---
    $(document).ready(function () {
        if ($('.swps-keywords-wrap').length) {
            loadTracked();
            loadOpportunities();
        }
    });

})(jQuery);
