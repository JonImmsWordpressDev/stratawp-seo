/**
 * StrataWP SEO — Dashboard JS (v4.0)
 *
 * Quick action handlers: Run audit (AJAX), module toggles (REST stub
 * until Phase 6 ships).
 */
(function () {
    'use strict';

    var cfg = window.swpsShell || {};

    function showFlash(msg, kind) {
        // Lightweight inline flash — replaced by the toast system in Phase 5.
        var existing = document.querySelector('.swps-dash-flash');
        if (existing) existing.remove();
        var el = document.createElement('div');
        el.className = 'swps-dash-flash';
        el.textContent = msg;
        el.style.cssText =
            'position:fixed;bottom:24px;right:24px;z-index:9999;' +
            'padding:12px 18px;border-radius:8px;font-size:13px;' +
            'background:var(--swps-bg-surface-strong);' +
            'border:1px solid var(--swps-' + (kind === 'err' ? 'crit' : 'accent-1') + ');' +
            'color:var(--swps-text-primary);' +
            'box-shadow:var(--swps-shadow-lg);';
        document.body.appendChild(el);
        setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; }, 2400);
        setTimeout(function () { el.remove(); }, 2800);
    }

    /* ------------------------------------------------------------------
     * Run audit from dashboard
     * ------------------------------------------------------------------ */
    function bindRunAudit() {
        var triggers = document.querySelectorAll('[data-swps-action="run-audit"]');
        triggers.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                if (!cfg.restUrl) return; // let normal navigation happen
                e.preventDefault();
                btn.classList.add('is-loading');
                btn.setAttribute('aria-busy', 'true');
                fetch(cfg.restUrl + 'swps/v1/audit', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': cfg.restNonce }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            showFlash('Audit complete — refreshing...', 'ok');
                            setTimeout(function () { window.location.reload(); }, 800);
                        } else {
                            showFlash('Audit failed.', 'err');
                            btn.classList.remove('is-loading');
                            btn.removeAttribute('aria-busy');
                        }
                    })
                    .catch(function () {
                        showFlash('Audit failed.', 'err');
                        btn.classList.remove('is-loading');
                        btn.removeAttribute('aria-busy');
                    });
            });
        });
    }

    /* ------------------------------------------------------------------
     * Module toggles — persists via REST POST /swps/v1/modules/{slug}/toggle
     * ------------------------------------------------------------------ */
    function bindModuleToggles() {
        var toggles = document.querySelectorAll('[data-swps-module-toggle]');
        toggles.forEach(function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                if (t.disabled || t.dataset.swpsLocked === '1') return;

                var slug = t.getAttribute('data-swps-module-toggle');
                var on   = !t.classList.contains('is-on');

                // Optimistic toggle.
                t.classList.toggle('is-on', on);
                t.setAttribute('aria-checked', on ? 'true' : 'false');
                t.disabled = true;

                if (!cfg.restUrl) {
                    t.disabled = false;
                    return;
                }

                fetch(cfg.restUrl + 'swps/v1/modules/' + encodeURIComponent(slug) + '/toggle', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': cfg.restNonce
                    },
                    body: JSON.stringify({ enabled: on })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        t.disabled = false;
                        if (!data || !data.success) {
                            // Rollback.
                            t.classList.toggle('is-on', !on);
                            t.setAttribute('aria-checked', !on ? 'true' : 'false');
                            showFlash('Could not save — locked or unknown module.', 'err');
                            return;
                        }
                        showFlash(
                            (t.getAttribute('aria-label') || 'Module') +
                            (data.enabled ? ' enabled' : ' disabled'),
                            'ok'
                        );
                    })
                    .catch(function () {
                        t.disabled = false;
                        t.classList.toggle('is-on', !on);
                        t.setAttribute('aria-checked', !on ? 'true' : 'false');
                        showFlash('Network error saving module state.', 'err');
                    });
            });
        });
    }

    function init() {
        bindRunAudit();
        bindModuleToggles();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
