/**
 * Redirects admin page — AJAX interactions.
 */
(function () {
    'use strict';

    const nonce = typeof swps_admin !== 'undefined' ? swps_admin.nonce : '';
    const ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function escAttr(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;');
    }

    // -------------------------------------------------------------------------
    // Redirects list
    // -------------------------------------------------------------------------

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
                        <td><button class="button button-small swps-delete-redirect" data-id="${escAttr(r.id)}">Delete</button></td>
                    `;
                    tbody.appendChild(tr);
                });
            });
    }

    // -------------------------------------------------------------------------
    // 404s list
    // -------------------------------------------------------------------------

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
                    const suggestedDisplay = r.suggested_target
                        ? `<a href="${escAttr(r.suggested_target)}" target="_blank">${escHtml(r.suggested_target)}</a>`
                        : '&mdash;';
                    const tr = document.createElement('tr');
                    tr.dataset.entryId = r.id;
                    tr.innerHTML = `
                        <td><input type="checkbox" class="swps-404-checkbox" value="${escAttr(r.id)}"></td>
                        <td>${escHtml(r.url)}</td>
                        <td>${r.count}</td>
                        <td>${r.last_seen}</td>
                        <td>${r.referrer ? escHtml(r.referrer) : '&mdash;'}</td>
                        <td>${suggestedDisplay}</td>
                        <td>
                            <button class="button button-small swps-inline-redirect-btn"
                                data-url="${escAttr(r.url)}"
                                data-id="${escAttr(r.id)}"
                                data-suggested="${escAttr(r.suggested_target || '')}">Redirect</button>
                            <button class="button button-small swps-delete-404" data-id="${escAttr(r.id)}">Dismiss</button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
                updateBulkBar();
            });
    }

    // -------------------------------------------------------------------------
    // Inline redirect form
    // -------------------------------------------------------------------------

    function showInlineRedirectForm(btn) {
        // Remove any existing inline form rows.
        document.querySelectorAll('.swps-inline-redirect-row').forEach(el => el.remove());

        const sourceUrl = btn.dataset.url;
        const entryId = btn.dataset.id;
        const suggested = btn.dataset.suggested || '';

        const parentRow = btn.closest('tr');
        const colCount = parentRow ? parentRow.cells.length : 7;

        const formRow = document.createElement('tr');
        formRow.classList.add('swps-inline-redirect-row');
        formRow.dataset.entryId = entryId;
        formRow.innerHTML = `
            <td colspan="${colCount}">
                <div class="swps-inline-redirect-form">
                    <label><strong>Source:</strong>
                        <input type="text" class="regular-text" value="${escAttr(sourceUrl)}" readonly>
                    </label>
                    <label><strong>Target:</strong>
                        <input type="text" class="regular-text swps-inline-target" value="${escAttr(suggested)}" placeholder="Enter target URL">
                    </label>
                    <label><strong>Type:</strong>
                        <select class="swps-inline-type">
                            <option value="301">301 Permanent</option>
                            <option value="302">302 Temporary</option>
                            <option value="307">307 Temporary (preserve method)</option>
                        </select>
                    </label>
                    <button class="button button-primary swps-inline-redirect-save"
                        data-url="${escAttr(sourceUrl)}"
                        data-id="${escAttr(entryId)}">Save</button>
                    <button class="button swps-inline-redirect-cancel">Cancel</button>
                    <div class="swps-suggestion-chips"></div>
                </div>
            </td>
        `;

        if (parentRow && parentRow.parentNode) {
            parentRow.parentNode.insertBefore(formRow, parentRow.nextSibling);
        }

        const targetInput = formRow.querySelector('.swps-inline-target');
        const chipsContainer = formRow.querySelector('.swps-suggestion-chips');

        loadSuggestions(sourceUrl, chipsContainer, targetInput);
        targetInput.focus();
    }

    // -------------------------------------------------------------------------
    // Suggestions
    // -------------------------------------------------------------------------

    function loadSuggestions(url, container, targetInput) {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'swps_suggest_redirect_target', nonce: nonce, url: url }),
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success || !Array.isArray(res.data) || res.data.length === 0) return;
                container.innerHTML = '<span class="swps-chips-label">Suggestions:</span>';
                res.data.forEach(suggestion => {
                    const chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'button button-small swps-suggestion-chip';
                    chip.textContent = suggestion;
                    chip.addEventListener('click', function () {
                        targetInput.value = suggestion;
                        targetInput.focus();
                    });
                    container.appendChild(chip);
                });
            })
            .catch(() => {
                // Suggestions are best-effort; silently fail.
            });
    }

    // -------------------------------------------------------------------------
    // Save inline redirect
    // -------------------------------------------------------------------------

    function saveInlineRedirect(btn) {
        const formRow = btn.closest('.swps-inline-redirect-row');
        const sourceUrl = btn.dataset.url;
        const entryId = btn.dataset.id;
        const target = formRow.querySelector('.swps-inline-target').value.trim();
        const type = formRow.querySelector('.swps-inline-type').value;

        if (!target) {
            alert('Please enter a target URL.');
            return;
        }

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'swps_add_redirect',
                nonce: nonce,
                source: sourceUrl,
                target: target,
                type: type,
                is_regex: 0,
            }),
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    alert(res.data || 'Error adding redirect.');
                    return;
                }
                // Dismiss the 404 entry after successfully creating the redirect.
                return fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_delete_404', nonce: nonce, id: entryId }),
                });
            })
            .then(() => {
                loadRedirects();
                load404s();
            });
    }

    // -------------------------------------------------------------------------
    // Bulk bar
    // -------------------------------------------------------------------------

    function updateBulkBar() {
        const checked = document.querySelectorAll('.swps-404-checkbox:checked');
        const bar = document.querySelector('.swps-404-bulk-bar');
        const countEl = document.getElementById('swps-404-selected-count');
        if (bar) {
            bar.style.display = checked.length > 0 ? 'block' : 'none';
        }
        if (countEl) {
            countEl.textContent = checked.length;
        }
    }

    // -------------------------------------------------------------------------
    // DOMContentLoaded
    // -------------------------------------------------------------------------

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

        // Add redirect form.
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

        // Delegated click handlers.
        document.addEventListener('click', function (e) {

            // Delete redirect.
            if (e.target.classList.contains('swps-delete-redirect')) {
                const id = e.target.dataset.id;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_delete_redirect', nonce, id }),
                }).then(() => loadRedirects());
            }

            // Dismiss (delete) a single 404 entry.
            if (e.target.classList.contains('swps-delete-404')) {
                const id = e.target.dataset.id;
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'swps_delete_404', nonce, id }),
                }).then(() => load404s());
            }

            // Open inline redirect form.
            if (e.target.classList.contains('swps-inline-redirect-btn')) {
                showInlineRedirectForm(e.target);
            }

            // Save inline redirect.
            if (e.target.classList.contains('swps-inline-redirect-save')) {
                saveInlineRedirect(e.target);
            }

            // Cancel inline redirect form.
            if (e.target.classList.contains('swps-inline-redirect-cancel')) {
                const formRow = e.target.closest('.swps-inline-redirect-row');
                if (formRow) formRow.remove();
            }
        });

        // Delegated checkbox change handlers.
        document.addEventListener('change', function (e) {

            // Individual 404 checkbox.
            if (e.target.classList.contains('swps-404-checkbox')) {
                updateBulkBar();
            }

            // Select-all checkbox.
            if (e.target.id === 'swps-404-select-all') {
                const checked = e.target.checked;
                document.querySelectorAll('.swps-404-checkbox').forEach(cb => {
                    cb.checked = checked;
                });
                updateBulkBar();
            }
        });

        // Bulk dismiss.
        document.getElementById('swps-bulk-dismiss-404s')?.addEventListener('click', function () {
            const ids = Array.from(document.querySelectorAll('.swps-404-checkbox:checked')).map(cb => cb.value);
            if (ids.length === 0) return;

            const params = new URLSearchParams({ action: 'swps_bulk_delete_404s', nonce });
            ids.forEach(id => params.append('ids[]', id));

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params,
            }).then(() => load404s());
        });

        // Bulk redirect — show target input.
        document.getElementById('swps-bulk-redirect-404s')?.addEventListener('click', function () {
            const wrap = document.getElementById('swps-bulk-redirect-target-wrap');
            if (wrap) wrap.style.display = 'block';
        });

        // Bulk redirect — confirm.
        document.getElementById('swps-bulk-redirect-confirm')?.addEventListener('click', function () {
            const ids = Array.from(document.querySelectorAll('.swps-404-checkbox:checked')).map(cb => cb.value);
            const targetInput = document.getElementById('swps-bulk-redirect-target');
            const target = targetInput ? targetInput.value.trim() : '';

            if (ids.length === 0 || !target) return;

            const params = new URLSearchParams({ action: 'swps_bulk_redirect_404s', nonce, target });
            ids.forEach(id => params.append('ids[]', id));

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params,
            }).then(() => {
                const wrap = document.getElementById('swps-bulk-redirect-target-wrap');
                if (wrap) wrap.style.display = 'none';
                if (targetInput) targetInput.value = '';
                loadRedirects();
                load404s();
            });
        });

        // Bulk redirect — cancel.
        document.getElementById('swps-bulk-redirect-cancel')?.addEventListener('click', function () {
            const wrap = document.getElementById('swps-bulk-redirect-target-wrap');
            if (wrap) wrap.style.display = 'none';
        });

        // Initial load.
        loadRedirects();
    });
})();
