/**
 * Redirects admin page — AJAX interactions.
 */
(function () {
    'use strict';

    const nonce = typeof swps_admin !== 'undefined' ? swps_admin.nonce : '';
    const ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';

    function loadRedirects() {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'swps_get_redirects', nonce: nonce }),
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                const tbody = document.querySelector('#swps-redirects-table tbody');
                tbody.innerHTML = '';
                res.data.forEach(r => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${escHtml(r.source_url)}${r.is_regex === '1' ? ' <em>(regex)</em>' : ''}</td>
                        <td>${escHtml(r.target_url)}</td>
                        <td>${r.type}</td>
                        <td>${r.hits}</td>
                        <td><button class="button button-small swps-delete-redirect" data-id="${r.id}">Delete</button></td>
                    `;
                    tbody.appendChild(tr);
                });
            });
    }

    function load404s() {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'swps_get_404s', nonce: nonce }),
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                const tbody = document.querySelector('#swps-404s-table tbody');
                tbody.innerHTML = '';
                res.data.forEach(r => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${escHtml(r.url)}</td>
                        <td>${r.count}</td>
                        <td>${r.last_seen}</td>
                        <td>
                            <button class="button button-small swps-create-redirect-from-404" data-url="${escAttr(r.url)}">Create Redirect</button>
                            <button class="button button-small swps-delete-404" data-id="${r.id}">Dismiss</button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            });
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;');
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Tab switching.
        document.querySelectorAll('.nav-tab').forEach(tab => {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
                this.classList.add('nav-tab-active');
                document.querySelectorAll('.swps-tab-content').forEach(c => c.style.display = 'none');
                document.getElementById('tab-' + this.dataset.tab).style.display = 'block';

                if (this.dataset.tab === '404s') load404s();
            });
        });

        // Add redirect.
        document.getElementById('swps-add-redirect')?.addEventListener('click', function () {
            const source = document.getElementById('swps-redirect-source').value;
            const target = document.getElementById('swps-redirect-target').value;
            const type = document.getElementById('swps-redirect-type').value;
            const isRegex = document.getElementById('swps-redirect-regex').checked ? 1 : 0;

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'swps_add_redirect', nonce, source, target, type, is_regex: isRegex }),
            })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        document.getElementById('swps-redirect-source').value = '';
                        document.getElementById('swps-redirect-target').value = '';
                        loadRedirects();
                    } else {
                        alert(res.data || 'Error adding redirect');
                    }
                });
        });

        // Delegate clicks for delete and create-from-404.
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('swps-delete-redirect')) {
                const id = e.target.dataset.id;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_delete_redirect', nonce, id }),
                }).then(() => loadRedirects());
            }

            if (e.target.classList.contains('swps-delete-404')) {
                const id = e.target.dataset.id;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_delete_404', nonce, id }),
                }).then(() => load404s());
            }

            if (e.target.classList.contains('swps-create-redirect-from-404')) {
                document.getElementById('swps-redirect-source').value = e.target.dataset.url;
                document.querySelector('[data-tab="redirects"]').click();
                document.getElementById('swps-redirect-target').focus();
            }
        });

        // Initial load.
        loadRedirects();
    });
})();
