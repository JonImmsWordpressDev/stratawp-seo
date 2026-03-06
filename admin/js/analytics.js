/**
 * StrataWP SEO — Analytics Dashboard JS
 *
 * Loads dashboard data via AJAX, renders charts and tables.
 */
(function ($) {
    'use strict';

    if (typeof swpsAdmin === 'undefined') return;

    var currentDays = 30;

    // --- Dashboard ---

    function loadOverview() {
        $.post(swpsAdmin.ajax_url, {
            action: 'swps_analytics_overview',
            nonce: swpsAdmin.nonce,
            days: currentDays
        }, function (res) {
            if (!res.success) return;
            var d = res.data;

            $('#swps-total-views').text(d.total_views.toLocaleString());
            $('#swps-avg-time').text(formatSeconds(d.avg_time));

            var changeEl = $('#swps-views-change');
            if (d.views_change !== 0) {
                var arrow = d.views_change > 0 ? '↑' : '↓';
                var cls = d.views_change > 0 ? 'swps-change-up' : 'swps-change-down';
                changeEl.html('<span class="' + cls + '">' + arrow + ' ' + Math.abs(d.views_change) + '%</span>');
            } else {
                changeEl.html('');
            }

            if (d.gsc_clicks !== undefined) {
                $('#swps-gsc-clicks').text(d.gsc_clicks.toLocaleString());
                $('#swps-gsc-impressions').text(d.gsc_impressions.toLocaleString());
            }

            renderChart(d.daily, d.gsc_daily || []);
        });
    }

    function loadTopPages() {
        $.post(swpsAdmin.ajax_url, {
            action: 'swps_analytics_top_pages',
            nonce: swpsAdmin.nonce,
            days: currentDays
        }, function (res) {
            if (!res.success) return;
            var tbody = $('#swps-top-pages-table tbody');
            tbody.empty();

            if (!res.data.length) {
                tbody.html('<tr><td colspan="8">No data yet.</td></tr>');
                return;
            }

            res.data.forEach(function (page) {
                var row = '<tr>';
                row += '<td><a href="' + page.url + '" target="_blank">' + escHtml(page.title) + '</a></td>';
                row += '<td>' + parseInt(page.views).toLocaleString() + '</td>';
                row += '<td>' + formatSeconds(page.avg_time_on_page) + '</td>';
                row += '<td>' + Math.round(page.avg_scroll_depth) + '%</td>';
                row += '<td>' + page.bounce_rate + '%</td>';

                if (page.gsc_clicks !== undefined) {
                    row += '<td>' + (page.gsc_clicks || 0) + '</td>';
                    row += '<td>' + (page.gsc_impressions || 0) + '</td>';
                    row += '<td>' + (page.gsc_position || '—') + '</td>';
                }

                row += '</tr>';
                tbody.append(row);
            });
        });
    }

    function loadTopQueries() {
        $.post(swpsAdmin.ajax_url, {
            action: 'swps_analytics_top_queries',
            nonce: swpsAdmin.nonce,
            days: currentDays
        }, function (res) {
            if (!res.success) return;
            var tbody = $('#swps-top-queries-table tbody');
            tbody.empty();

            if (!res.data.length) {
                tbody.html('<tr><td colspan="5">No data yet.</td></tr>');
                return;
            }

            res.data.forEach(function (q) {
                var row = '<tr>';
                row += '<td>' + escHtml(q.query) + '</td>';
                row += '<td>' + q.clicks + '</td>';
                row += '<td>' + q.impressions.toLocaleString() + '</td>';
                row += '<td>' + q.ctr + '%</td>';
                row += '<td>' + q.position + '</td>';
                row += '</tr>';
                tbody.append(row);
            });
        });
    }

    // --- SVG Chart ---

    function renderChart(dailyViews, gscDaily) {
        var container = document.getElementById('swps-analytics-chart');
        if (!container) return;

        var width = container.offsetWidth || 800;
        var height = 250;
        var padding = { top: 20, right: 20, bottom: 30, left: 50 };
        var chartW = width - padding.left - padding.right;
        var chartH = height - padding.top - padding.bottom;

        if (!dailyViews.length) {
            container.innerHTML = '<p style="text-align:center;color:#646970;padding:40px 0;">No data for this period.</p>';
            return;
        }

        var maxViews = Math.max.apply(null, dailyViews.map(function (d) { return parseInt(d.views) || 0; }));
        if (maxViews === 0) maxViews = 1;

        var points = dailyViews.map(function (d, i) {
            var x = padding.left + (i / Math.max(dailyViews.length - 1, 1)) * chartW;
            var y = padding.top + chartH - ((parseInt(d.views) || 0) / maxViews) * chartH;
            return x + ',' + y;
        });

        var svg = '<svg width="' + width + '" height="' + height + '" xmlns="http://www.w3.org/2000/svg">';

        // Y-axis labels.
        for (var i = 0; i <= 4; i++) {
            var yVal = Math.round((maxViews / 4) * i);
            var yPos = padding.top + chartH - (i / 4) * chartH;
            svg += '<text x="' + (padding.left - 8) + '" y="' + (yPos + 4) + '" text-anchor="end" fill="#646970" font-size="11">' + yVal + '</text>';
            svg += '<line x1="' + padding.left + '" y1="' + yPos + '" x2="' + (width - padding.right) + '" y2="' + yPos + '" stroke="#e0e0e0" />';
        }

        // Views line.
        svg += '<polyline points="' + points.join(' ') + '" fill="none" stroke="#2271b1" stroke-width="2" />';

        svg += '</svg>';
        container.innerHTML = svg;
    }

    // --- Post Metabox ---

    function loadMetabox() {
        var box = document.getElementById('swps-analytics-metabox');
        if (!box) return;

        var postId = box.dataset.postId;
        var nonce = box.dataset.nonce;

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_analytics_post_stats',
            nonce: nonce,
            post_id: postId
        }, function (res) {
            if (!res.success) {
                box.innerHTML = '<p>Unable to load analytics.</p>';
                return;
            }

            var d = res.data;
            var html = '<table class="swps-metabox-stats">';
            html += '<tr><td>Views (7d)</td><td><strong>' + d.views_7d + '</strong></td></tr>';
            html += '<tr><td>Views (30d)</td><td><strong>' + d.views_30d + '</strong></td></tr>';
            html += '<tr><td>Avg Time</td><td>' + formatSeconds(d.avg_time_on_page) + '</td></tr>';
            html += '<tr><td>Scroll Depth</td><td>' + d.avg_scroll_depth + '%</td></tr>';
            html += '<tr><td>Bounce Rate</td><td>' + d.bounce_rate + '%</td></tr>';
            html += '</table>';

            if (d.gsc_queries && d.gsc_queries.length) {
                html += '<h4 style="margin:12px 0 4px;">Top Queries</h4>';
                html += '<table class="swps-metabox-stats">';
                d.gsc_queries.forEach(function (q) {
                    html += '<tr><td>' + escHtml(q.query) + '</td><td>' + q.clicks + ' clicks</td><td>#' + q.position + '</td></tr>';
                });
                html += '</table>';
            }

            box.innerHTML = html;
        });
    }

    // --- Helpers ---

    function formatSeconds(s) {
        s = parseInt(s) || 0;
        var m = Math.floor(s / 60);
        var sec = s % 60;
        return m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // --- Init ---

    $(document).ready(function () {
        // Dashboard page.
        if ($('.swps-analytics-wrap').length) {
            loadOverview();
            loadTopPages();

            if ($('#swps-top-queries-table').length) {
                loadTopQueries();
            }

            // Date range buttons.
            $('.swps-range-btn').on('click', function () {
                $('.swps-range-btn').removeClass('active');
                $(this).addClass('active');
                currentDays = parseInt($(this).data('days'));
                loadOverview();
                loadTopPages();
                if ($('#swps-top-queries-table').length) loadTopQueries();
            });

            // GSC buttons.
            $('#swps-gsc-disconnect').on('click', function () {
                if (!confirm('Disconnect from Google Search Console?')) return;
                $.post(swpsAdmin.ajax_url, {
                    action: 'swps_gsc_disconnect',
                    nonce: swpsAdmin.nonce
                }, function () { location.reload(); });
            });

            $('#swps-gsc-refresh').on('click', function () {
                var btn = $(this);
                btn.prop('disabled', true);
                $.post(swpsAdmin.ajax_url, {
                    action: 'swps_gsc_refresh',
                    nonce: swpsAdmin.nonce
                }, function () {
                    btn.prop('disabled', false);
                    loadOverview();
                    loadTopPages();
                    if ($('#swps-top-queries-table').length) loadTopQueries();
                });
            });

            $('#swps-gsc-save-property').on('click', function () {
                var prop = $('#swps-gsc-property-select').val();
                if (!prop) return;
                $.post(swpsAdmin.ajax_url, {
                    action: 'swps_gsc_save_property',
                    nonce: swpsAdmin.nonce,
                    property: prop
                }, function () { location.reload(); });
            });
        }

        // Post metabox.
        if ($('#swps-analytics-metabox').length) {
            loadMetabox();
        }
    });

})(jQuery);
