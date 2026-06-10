/**
 * StrataWP SEO — Onboarding wizard interactions.
 *
 * Vanilla JS (matches dashboard.js / sitemaps.js style). All endpoints share
 * the swps_onboarding nonce localized via the swpsOnboarding object.
 */
(function () {
    'use strict';

    var cfg = window.swpsOnboarding || {};
    var ajaxUrl = cfg.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
    var nonce = cfg.nonce || '';
    var i18n = cfg.i18n || {};

    function post(action, data) {
        var body = new URLSearchParams(data || {});
        body.set('action', action);
        body.set('nonce', nonce);
        return fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
        }).then(function (r) { return r.json(); });
    }

    function setMsg(id, text, ok) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('is-ok', !!ok);
        el.classList.toggle('is-err', !ok && !!text);
    }

    function spinner(id, on) {
        var el = document.getElementById(id);
        if (el) el.classList.toggle('is-active', !!on);
    }

    function stepCard(step) {
        return document.querySelector('.swps-ob-step[data-step="' + step + '"]');
    }

    function markDone(step) {
        var card = stepCard(step);
        if (card) card.classList.add('is-done');
        // All five done? Reload so the server renders the win panel.
        var total = document.querySelectorAll('.swps-ob-step').length;
        var done = document.querySelectorAll('.swps-ob-step.is-done').length;
        if (total > 0 && done >= total) {
            window.setTimeout(function () { window.location.reload(); }, 900);
        }
    }

    function completeStep(step) {
        return post('swps_onboarding_step_done', { step: step }).then(function (res) {
            if (res && res.success) markDone(step);
            return res;
        });
    }

    /* Skip links + explicit "I'm done" buttons. */
    document.querySelectorAll('.swps-ob-skip, .swps-ob-step-complete').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            completeStep(el.getAttribute('data-step'));
        });
    });

    /* Step 1: auto-skip when nothing was detected. */
    var autoSkip = document.querySelector('.swps-ob-step[data-auto-skip="1"]');
    if (autoSkip) {
        completeStep(autoSkip.getAttribute('data-step'));
    }

    /* Step 1: refresh detection counts on demand. */
    var refreshBtn = document.querySelector('.swps-ob-refresh-detect');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            refreshBtn.disabled = true;
            post('swps_onboarding_status', {}).then(function (res) {
                refreshBtn.disabled = false;
                if (res && res.success) {
                    // Counts are server-rendered — a reload is the simplest sync.
                    window.location.reload();
                }
            }).catch(function () { refreshBtn.disabled = false; });
        });
    }

    /* Step 2: Test & Save the API key. */
    var testBtn = document.getElementById('swps-ob-test-key');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var provider = document.getElementById('swps-ob-provider').value;
            var key = document.getElementById('swps-ob-api-key').value.trim();
            if (!key) {
                setMsg('swps-ob-key-msg', i18n.keyRequired || 'Please paste an API key first.', false);
                return;
            }
            testBtn.disabled = true;
            setMsg('swps-ob-key-msg', i18n.testing || 'Testing key…', true);
            post('swps_onboarding_test_key', { provider: provider, api_key: key })
                .then(function (res) {
                    testBtn.disabled = false;
                    if (res && res.success) {
                        setMsg('swps-ob-key-msg', res.data.message, true);
                        markDone('api_key');
                    } else {
                        setMsg('swps-ob-key-msg', (res && res.data && res.data.message) || i18n.failed || 'Request failed.', false);
                    }
                })
                .catch(function () {
                    testBtn.disabled = false;
                    setMsg('swps-ob-key-msg', i18n.failed || 'Request failed.', false);
                });
        });
    }

    /* Step 3: Suggest with AI. */
    var suggestBtn = document.getElementById('swps-ob-suggest');
    if (suggestBtn) {
        suggestBtn.addEventListener('click', function () {
            suggestBtn.disabled = true;
            setMsg('swps-ob-info-msg', i18n.suggesting || 'Asking the AI…', true);
            post('swps_onboarding_suggest_site_info', {})
                .then(function (res) {
                    suggestBtn.disabled = false;
                    if (res && res.success && res.data.suggestion) {
                        document.getElementById('swps-ob-description').value = res.data.suggestion;
                        setMsg('swps-ob-info-msg', i18n.suggested || 'Suggestion ready — edit it, then save.', true);
                    } else if (res && res.success) {
                        setMsg('swps-ob-info-msg', i18n.noSuggestion || 'No suggestion available — add a key in step 2 or write your own.', false);
                    } else {
                        setMsg('swps-ob-info-msg', (res && res.data && res.data.message) || i18n.failed || 'Request failed.', false);
                    }
                })
                .catch(function () {
                    suggestBtn.disabled = false;
                    setMsg('swps-ob-info-msg', i18n.failed || 'Request failed.', false);
                });
        });
    }

    /* Step 3: Save description. */
    var saveInfoBtn = document.getElementById('swps-ob-save-info');
    if (saveInfoBtn) {
        saveInfoBtn.addEventListener('click', function () {
            var desc = document.getElementById('swps-ob-description').value.trim();
            if (!desc) {
                setMsg('swps-ob-info-msg', i18n.descRequired || 'Please enter a description first.', false);
                return;
            }
            saveInfoBtn.disabled = true;
            post('swps_onboarding_save_site_info', { description: desc })
                .then(function (res) {
                    saveInfoBtn.disabled = false;
                    if (res && res.success) {
                        setMsg('swps-ob-info-msg', res.data.message, true);
                        markDone('site_info');
                    } else {
                        setMsg('swps-ob-info-msg', (res && res.data && res.data.message) || i18n.failed || 'Request failed.', false);
                    }
                })
                .catch(function () {
                    saveInfoBtn.disabled = false;
                    setMsg('swps-ob-info-msg', i18n.failed || 'Request failed.', false);
                });
        });
    }

    /* Step 4: Run audit. */
    var auditBtn = document.getElementById('swps-ob-run-audit');
    if (auditBtn) {
        auditBtn.addEventListener('click', function () {
            auditBtn.disabled = true;
            spinner('swps-ob-audit-spinner', true);
            setMsg('swps-ob-audit-msg', i18n.auditing || 'Running the audit — this can take a minute…', true);
            post('swps_onboarding_run_audit', {})
                .then(function (res) {
                    auditBtn.disabled = false;
                    spinner('swps-ob-audit-spinner', false);
                    if (res && res.success) {
                        var d = res.data;
                        var summary = (i18n.auditDone || '%1$s passed / %2$s issues.')
                            .replace('%1$s', d.pass)
                            .replace('%2$s', d.issues);
                        var msgEl = document.getElementById('swps-ob-audit-msg');
                        msgEl.textContent = summary + ' ';
                        var link = document.createElement('a');
                        link.href = d.audit_url;
                        link.textContent = i18n.openAudit || 'Open the audit';
                        msgEl.appendChild(link);
                        msgEl.classList.add('is-ok');
                        msgEl.classList.remove('is-err');
                        markDone('audit');
                    } else {
                        setMsg('swps-ob-audit-msg', (res && res.data && res.data.message) || i18n.failed || 'Request failed.', false);
                    }
                })
                .catch(function () {
                    auditBtn.disabled = false;
                    spinner('swps-ob-audit-spinner', false);
                    setMsg('swps-ob-audit-msg', i18n.failed || 'Request failed.', false);
                });
        });
    }

    /* Step 5: Generate preview. */
    var previewBtn = document.getElementById('swps-ob-preview');
    if (previewBtn) {
        previewBtn.addEventListener('click', function () {
            previewBtn.disabled = true;
            spinner('swps-ob-preview-spinner', true);
            setMsg('swps-ob-preview-msg', i18n.generating || 'Generating — usually 30–60 seconds…', true);
            post('swps_onboarding_preview', { topic: document.getElementById('swps-ob-topic').value })
                .then(function (res) {
                    previewBtn.disabled = false;
                    spinner('swps-ob-preview-spinner', false);
                    if (res && res.success) {
                        setMsg('swps-ob-preview-msg', '', true);
                        var card = document.getElementById('swps-ob-preview-card');
                        document.getElementById('swps-ob-preview-title').textContent = res.data.title;
                        document.getElementById('swps-ob-preview-meta').textContent = res.data.meta_description;
                        // Plain-text excerpt — textContent keeps AI output out of the DOM as markup.
                        document.getElementById('swps-ob-preview-content').textContent = res.data.content_excerpt;
                        card.hidden = false;
                        markDone('preview');
                    } else {
                        setMsg('swps-ob-preview-msg', (res && res.data && res.data.message) || i18n.failed || 'Request failed.', false);
                    }
                })
                .catch(function () {
                    previewBtn.disabled = false;
                    spinner('swps-ob-preview-spinner', false);
                    setMsg('swps-ob-preview-msg', i18n.failed || 'Request failed.', false);
                });
        });
    }
})();
