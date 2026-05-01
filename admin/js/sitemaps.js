/**
 * Sitemaps admin page — AJAX interactions.
 */
(function () {
    'use strict';

    const adminData = typeof swpsAdmin !== 'undefined' ? swpsAdmin : (typeof swps_admin !== 'undefined' ? swps_admin : {});
    const nonce = adminData.nonce || '';
    const ajaxUrl = adminData.ajax_url || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;');
    }

    function loadSitemaps() {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'swps_get_sitemaps', nonce: nonce }),
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;

                const tbody = document.querySelector('#swps-sitemaps-table tbody');
                tbody.innerHTML = '';

                // Handle sitemaps data
                if (res.data.sitemaps && Array.isArray(res.data.sitemaps)) {
                    res.data.sitemaps.forEach(sitemap => {
                        const tr = document.createElement('tr');
                        const isExcluded = sitemap.is_excluded === '1' || sitemap.is_excluded === true;
                        const rowStyle = isExcluded ? 'style="opacity: 0.5"' : '';

                        let typeBadge = '';
                        if (sitemap.type) {
                            const typeClass = sitemap.type === 'news' ? 'badge-warning' : 'badge-info';
                            typeBadge = ` <span class="badge ${typeClass}">${escHtml(sitemap.type)}</span>`;
                        }

                        const toggleLabel = isExcluded ? 'Enable' : 'Disable';
                        const toggleClass = isExcluded ? 'button-secondary' : 'button-primary';

                        tr.innerHTML = `
                            <td ${rowStyle}>${escHtml(sitemap.name)}${typeBadge}</td>
                            <td ${rowStyle}><a href="${escAttr(sitemap.url)}" target="_blank">${escHtml(sitemap.url)}</a></td>
                            <td ${rowStyle}>${sitemap.count || 0}</td>
                            <td ${rowStyle}>${sitemap.lastmod || '—'}</td>
                            <td ${rowStyle}>
                                <button class="button button-small ${toggleClass} swps-toggle-sitemap" data-key="${escAttr(sitemap.key)}">
                                    ${toggleLabel}
                                </button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }

                // Handle IndexNow key display
                if (res.data.indexnow_key) {
                    const indexNowStatus = document.querySelector('#swps-indexnow-status');
                    if (indexNowStatus) {
                        indexNowStatus.innerHTML = `<code>${escHtml(res.data.indexnow_key)}</code>`;
                    }
                }
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Only proceed if we're on the sitemaps page
        const sitemapsPage = document.querySelector('.swps-sitemaps-page');
        if (!sitemapsPage) return;

        // Tab switching
        document.querySelectorAll('.swps-sitemaps-page .nav-tab').forEach(tab => {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelectorAll('.swps-sitemaps-page .nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
                this.classList.add('nav-tab-active');
                document.querySelectorAll('.swps-sitemaps-page .swps-tab-content').forEach(c => c.style.display = 'none');
                const tabName = this.dataset.tab;
                const tabContent = document.querySelector(`.swps-sitemaps-page .swps-tab-content[data-tab="${escAttr(tabName)}"]`);
                if (tabContent) {
                    tabContent.style.display = 'block';
                }
            });
        });

        // Ping search engines button
        document.getElementById('swps-ping-engines')?.addEventListener('click', function () {
            const pingResult = document.getElementById('swps-ping-result');
            if (pingResult) {
                pingResult.innerHTML = 'Pinging search engines...';
                pingResult.style.display = 'block';
            }

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'swps_ping_search_engines', nonce: nonce }),
            })
                .then(r => r.json())
                .then(res => {
                    if (!pingResult) return;
                    const message =
                        (res && res.data && typeof res.data === 'object' && res.data.message) ||
                        (res && typeof res.data === 'string' && res.data) ||
                        (res && res.success ? 'Ping complete' : 'Ping failed');
                    const ok = res && res.success;
                    pingResult.innerHTML = ' ' + escHtml(message);
                    pingResult.style.color = ok ? '#0a7d2c' : '#b32d2e';
                    pingResult.style.display = 'inline';
                    setTimeout(() => {
                        pingResult.style.display = 'none';
                    }, 8000);
                })
                .catch(err => {
                    if (!pingResult) return;
                    pingResult.innerHTML = ' ' + escHtml('Network error: ' + err.message);
                    pingResult.style.color = '#b32d2e';
                    pingResult.style.display = 'inline';
                });
        });

        // Verify IndexNow key URL accessibility
        document.getElementById('swps-verify-key')?.addEventListener('click', function () {
            const verifyBox = document.getElementById('swps-verify-result');
            if (verifyBox) {
                verifyBox.style.display = 'block';
                verifyBox.innerHTML = '<em>' + escHtml('Checking key URLs...') + '</em>';
            }

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'swps_verify_indexnow_key', nonce: nonce }),
            })
                .then(r => r.json())
                .then(res => {
                    if (!verifyBox) return;
                    if (!res || !res.success || !res.data) {
                        const msg = (res && res.data && res.data.message) || 'Verification failed.';
                        verifyBox.innerHTML = '<span style="color:#b32d2e">' + escHtml(msg) + '</span>';
                        return;
                    }
                    const checks = res.data.checks || [];
                    const rows = checks.map(c => {
                        const color = c.ok ? '#0a7d2c' : '#b32d2e';
                        const status = c.ok ? 'OK' : (c.error || ('HTTP ' + c.status));
                        const link = c.url ? '<a href="' + escAttr(c.url) + '" target="_blank" rel="noopener">' + escHtml(c.url) + '</a>' : '—';
                        return '<li style="margin:4px 0;color:' + color + '"><strong>' + escHtml(c.label) + ':</strong> ' + escHtml(status) + ' &mdash; ' + link + '</li>';
                    }).join('');
                    const overall = res.data.ok
                        ? '<p style="color:#0a7d2c;margin:0 0 6px"><strong>' + escHtml('At least one key URL is reachable. Pings should succeed.') + '</strong></p>'
                        : '<p style="color:#b32d2e;margin:0 0 6px"><strong>' + escHtml('No key URL is reachable. IndexNow will reject pings until this is fixed.') + '</strong></p>';
                    verifyBox.innerHTML = overall + '<ul style="margin:0;padding-left:18px">' + rows + '</ul>';
                })
                .catch(err => {
                    if (!verifyBox) return;
                    verifyBox.innerHTML = '<span style="color:#b32d2e">' + escHtml('Network error: ' + err.message) + '</span>';
                });
        });

        // Delegate clicks for toggle sitemap button
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('swps-toggle-sitemap')) {
                const key = e.target.dataset.key;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_toggle_sitemap', key: key, nonce: nonce }),
                }).then(() => loadSitemaps());
            }
        });

        // Initial load
        loadSitemaps();
    });
})();
