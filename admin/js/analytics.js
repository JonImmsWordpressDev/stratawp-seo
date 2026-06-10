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

    function loadAiReferrals() {
        if (!$('#swps-ai-ref-engines-table').length) return;

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_analytics_ai_referrals',
            nonce: swpsAdmin.nonce,
            days: currentDays
        }, function (res) {
            if (!res.success) return;
            var d = res.data;

            // Summary tiles.
            $('#swps-ai-ref-views').text(d.summary.total_views.toLocaleString());

            var changeEl = $('#swps-ai-ref-change');
            if (d.summary.delta_pct !== null) {
                var up = d.summary.delta_pct >= 0;
                changeEl.html('<span class="' + (up ? 'swps-change-up' : 'swps-change-down') + '">' +
                    (up ? '↑' : '↓') + ' ' + Math.abs(d.summary.delta_pct) + '% vs prev period</span>');
            } else {
                changeEl.html('');
            }

            var engines = Object.keys(d.summary.by_source);
            $('#swps-ai-ref-top-engine').text(engines.length ? engines[0] : '—');

            // Views by engine.
            var engineBody = $('#swps-ai-ref-engines-table tbody');
            engineBody.empty();
            if (!engines.length) {
                engineBody.html('<tr><td colspan="2">No AI-referred views yet.</td></tr>');
            } else {
                engines.forEach(function (engine) {
                    engineBody.append('<tr><td><code>' + escHtml(engine) + '</code></td>' +
                        '<td style="text-align:right">' + d.summary.by_source[engine].toLocaleString() + '</td></tr>');
                });
            }

            // Landing posts.
            var landingBody = $('#swps-ai-ref-landing-table tbody');
            landingBody.empty();
            if (!d.landing_posts.length) {
                landingBody.html('<tr><td colspan="6">No AI-referred visits yet.</td></tr>');
            } else {
                d.landing_posts.forEach(function (p) {
                    var title = p.url
                        ? '<a href="' + p.url + '" target="_blank">' + escHtml(p.title) + '</a>'
                        : escHtml(p.title);
                    landingBody.append('<tr><td>' + title + '</td>' +
                        '<td style="text-align:right">' + p.views.toLocaleString() + '</td>' +
                        '<td style="text-align:right">' + formatSeconds(p.avg_time) + '</td>' +
                        '<td style="text-align:right">' + p.avg_scroll + '%</td>' +
                        '<td style="text-align:right">' + p.bounce_rate + '%</td>' +
                        '<td><code>' + escHtml(p.ai_sources || '') + '</code></td></tr>');
                });
            }

            // Engagement panel.
            var engEl = $('#swps-ai-ref-engagement');
            if (!d.engagement) {
                engEl.html('<p style="margin:0;color:var(--swps-text-muted)">Not enough data yet — engagement comparison needs at least 30 AI-referred views and 30 organic views in this period.</p>');
            } else {
                var e = d.engagement;
                var html = '<table class="widefat striped" style="margin-top:4px"><thead><tr>' +
                    '<th></th><th style="text-align:right">AI-referred</th><th style="text-align:right">Organic</th><th style="text-align:right">Ratio</th></tr></thead><tbody>';
                html += '<tr><td>Avg time on page</td><td style="text-align:right">' + formatSeconds(e.ai.avg_time) + '</td>' +
                    '<td style="text-align:right">' + formatSeconds(e.organic.avg_time) + '</td>' +
                    '<td style="text-align:right">' + e.time_ratio.toFixed(2) + '×</td></tr>';
                html += '<tr><td>Avg scroll depth</td><td style="text-align:right">' + e.ai.avg_scroll + '%</td>' +
                    '<td style="text-align:right">' + e.organic.avg_scroll + '%</td>' +
                    '<td style="text-align:right">' + e.scroll_ratio.toFixed(2) + '×</td></tr>';
                html += '<tr><td>Bounce rate</td><td style="text-align:right">' + e.ai.bounce_rate + '%</td>' +
                    '<td style="text-align:right">' + e.organic.bounce_rate + '%</td>' +
                    '<td style="text-align:right">' + e.bounce_ratio.toFixed(2) + '×</td></tr>';
                html += '</tbody></table>';
                html += '<p style="color:var(--swps-text-muted);font-size:11px;margin:8px 0 0">Based on ' +
                    e.ai_n.toLocaleString() + ' AI-referred views and ' + e.organic_n.toLocaleString() + ' organic views.</p>';
                engEl.html(html);
            }

            // Funnel.
            var funnelBody = $('#swps-ai-ref-funnel-table tbody');
            funnelBody.empty();
            if (!d.funnel.rows.length) {
                funnelBody.html('<tr><td colspan="4">No AI crawl data for this period.</td></tr>');
            } else {
                d.funnel.rows.forEach(function (r) {
                    var title = r.edit_link
                        ? '<a href="' + r.edit_link + '">' + escHtml(r.title) + '</a>'
                        : escHtml(r.title);
                    funnelBody.append('<tr><td>' + title + '</td>' +
                        '<td><code>' + escHtml(r.ai_source) + '</code></td>' +
                        '<td style="text-align:right">' + r.crawls.toLocaleString() + '</td>' +
                        '<td style="text-align:right">' + r.visits.toLocaleString() + '</td></tr>');
                });
            }

            // Crawled but no AI visits.
            var noVisitsBody = $('#swps-ai-ref-novisits-table tbody');
            noVisitsBody.empty();
            if (!d.funnel.crawled_no_visits.length) {
                noVisitsBody.html('<tr><td colspan="3">None — every crawled post has AI-referred visits.</td></tr>');
            } else {
                d.funnel.crawled_no_visits.forEach(function (r) {
                    var title = r.edit_link
                        ? '<a href="' + r.edit_link + '">' + escHtml(r.title) + '</a>'
                        : escHtml(r.title);
                    noVisitsBody.append('<tr><td>' + title + '</td>' +
                        '<td><code>' + escHtml(r.ai_source) + '</code></td>' +
                        '<td style="text-align:right">' + r.crawls.toLocaleString() + '</td></tr>');
                });
            }
        });
    }

    // --- Chart.js Chart ---

    var chartInstance = null;

    function renderChart(dailyViews, gscDaily) {
        var canvas = document.getElementById('swps-analytics-chart');
        if (!canvas || typeof Chart === 'undefined') return;

        if (chartInstance) {
            chartInstance.destroy();
            chartInstance = null;
        }

        var existing = canvas.parentNode.querySelector('.swps-no-data-msg');
        if (existing) existing.remove();

        if (!dailyViews.length) {
            canvas.parentNode.insertAdjacentHTML('beforeend', '<p class="swps-no-data-msg" style="text-align:center;color:#64748B;padding:40px 0;">No data for this period.</p>');
            return;
        }

        var labels = dailyViews.map(function (d) { return d.date; });
        var views = dailyViews.map(function (d) { return parseInt(d.views) || 0; });
        var clicks = gscDaily.map(function (d) { return parseInt(d.clicks) || 0; });

        // v4.0.4 — gold/black palette, read theme from <html data-swps-theme>.
        var theme = document.documentElement.getAttribute('data-swps-theme') || 'dark';
        var pal = theme === 'light'
            ? { line1: '#B45309', line2: '#D97706', grid: '#E5E7EB', tick: '#4B5563', tip: '#0F172A' }
            : { line1: '#FBBF24', line2: '#D97706', grid: 'rgba(255,255,255,0.05)', tick: '#94A3B8', tip: '#1E293B' };

        var ctx = canvas.getContext('2d');
        var gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
        gradient.addColorStop(0, theme === 'light' ? 'rgba(180, 83, 9, 0.10)' : 'rgba(251, 191, 36, 0.22)');
        gradient.addColorStop(1, theme === 'light' ? 'rgba(180, 83, 9, 0)'   : 'rgba(251, 191, 36, 0)');

        var datasets = [{
            label: 'Page Views',
            data: views,
            borderColor: pal.line1,
            backgroundColor: gradient,
            fill: true,
            tension: 0.4,
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 4,
            pointHoverBackgroundColor: pal.line1
        }];

        if (clicks.length) {
            datasets.push({
                label: 'Search Clicks',
                data: clicks,
                borderColor: pal.line2,
                backgroundColor: 'transparent',
                borderDash: [6, 3],
                tension: 0.4,
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                pointHoverBackgroundColor: pal.line2
            });
        }

        chartInstance = new Chart(canvas, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: datasets.length > 1, position: 'top', align: 'end', labels: { usePointStyle: true, pointStyle: 'line', font: { size: 12 }, color: pal.tick } },
                    tooltip: {
                        backgroundColor: pal.tip,
                        titleColor: '#F1F5F9',
                        bodyColor: '#E2E8F0',
                        titleFont: { size: 13 },
                        bodyFont: { size: 12 },
                        cornerRadius: 8,
                        padding: 12
                    }
                },
                scales: {
                    x: { border: { display: false }, grid: { color: pal.grid }, ticks: { color: pal.tick, font: { size: 11 } } },
                    y: { border: { display: false }, grid: { color: pal.grid }, ticks: { color: pal.tick, font: { size: 11 } }, beginAtZero: true }
                }
            }
        });
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
            loadAiReferrals();

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
                loadAiReferrals();
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
