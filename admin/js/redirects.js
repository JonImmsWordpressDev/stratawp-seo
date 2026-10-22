/**
 * Redirects admin page — AJAX interactions.
 */
(function () {
    'use strict';

    const nonce = typeof swpsAdmin !== 'undefined' ? swpsAdmin.nonce : '';
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

    function errorText(res, fallback) {
        if (res && res.data && typeof res.data === 'object') return res.data.message || fallback;
        return (res && res.data) || fallback;
    }

    /**
     * POST a redirect action. If the server refuses a homepage/blog-index
     * target (a soft 404 to Google), ask before retrying with the override.
     */
    function postRedirect(params) {
        const send = extra => fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(Object.assign({ nonce: nonce }, params, extra)),
        }).then(r => r.json());

        return send({}).then(res => {
            const isHub = !res.success && res.data && res.data.code === 'swps_redirect_hub_target';
            if (isHub && confirm(res.data.message + '\n\nRedirect there anyway?')) {
                return send({ allow_hub: 1 });
            }
            return res;
        });
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
                            <option value="410">410 Gone (no replacement)</option>
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

        if (!target && type !== '410') {
            alert('Please enter a target URL.');
            return;
        }

        postRedirect({
            action: 'swps_add_redirect',
            source: sourceUrl,
            target: target,
            type: type,
            is_regex: 0,
        })
            .then(res => {
                if (!res.success) {
                    alert(errorText(res, 'Error adding redirect.'));
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

            postRedirect({ action: 'swps_add_redirect', source, target, type, is_regex: isRegex })
                .then(res => {
                    if (res.success) {
                        document.getElementById('swps-redirect-source').value = '';
                        document.getElementById('swps-redirect-target').value = '';
                        loadRedirects();
                    } else {
                        alert(errorText(res, 'Error adding redirect'));
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
            const typeSelect = document.getElementById('swps-bulk-redirect-type');
            const target = targetInput ? targetInput.value.trim() : '';
            const type = typeSelect ? typeSelect.value : '301';

            if (ids.length === 0 || (!target && type !== '410')) return;

            const params = { action: 'swps_bulk_redirect_404s', target, type };
            ids.forEach((id, i) => { params['ids[' + i + ']'] = id; });

            postRedirect(params).then(res => {
                if (!res.success) {
                    alert(errorText(res, 'Error creating redirects.'));
                    return;
                }
                if (res.data && res.data.errors && res.data.errors.length) {
                    alert(res.data.errors.join('\n'));
                }
                const wrap = document.getElementById('swps-bulk-redirect-target-wrap');
                if (wrap) wrap.style.display = 'none';
                if (targetInput) targetInput.value = '';
                loadRedirects();
                load404s();
            });
        });

        // Bulk redirect — a 410 needs no target.
        document.getElementById('swps-bulk-redirect-type')?.addEventListener('change', function () {
            const targetInput = document.getElementById('swps-bulk-redirect-target');
            if (targetInput) targetInput.style.display = this.value === '410' ? 'none' : '';
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
