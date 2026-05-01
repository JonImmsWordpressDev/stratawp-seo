/**
 * StrataWP SEO — Admin Shell JS (v4.0)
 *
 * - Theme toggle (dark / light) persisted via REST
 * - Sidebar item active state from URL
 * - Search box page-jump (v1: keyword match against menu items)
 */
(function () {
    'use strict';

    var cfg = window.swpsShell || {};

    /* ------------------------------------------------------------------
     * Theme toggle
     * ------------------------------------------------------------------ */
    function currentTheme() {
        return document.documentElement.getAttribute('data-swps-theme') || cfg.theme || 'dark';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-swps-theme', theme);
        // Update button icon
        var btn = document.querySelector('[data-swps-theme-toggle]');
        if (btn) {
            var icon = btn.querySelector('.dashicons');
            if (icon) {
                icon.className = 'dashicons ' + (theme === 'dark' ? 'dashicons-sun' : 'dashicons-moon');
            }
            btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            btn.title = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
        }
    }

    function persistTheme(theme) {
        if (!cfg.restUrl || !cfg.restNonce) {
            return;
        }
        fetch(cfg.restUrl + 'swps/v1/user-prefs/theme', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.restNonce
            },
            body: JSON.stringify({ theme: theme })
        }).catch(function () { /* fail silent — local toggle still works */ });
    }

    function bindThemeToggle() {
        var btn = document.querySelector('[data-swps-theme-toggle]');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var next = currentTheme() === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            persistTheme(next);
        });
    }

    /* ------------------------------------------------------------------
     * Top-nav active state
     * Mark the current nav item active by matching ?page= against href.
     * (v4.0.2: works on either .swps-shell-nav-item or legacy
     *  .swps-shell-side-item — kept dual selectors for back-compat.)
     * ------------------------------------------------------------------ */
    function activateCurrentNavItem() {
        var params = new URLSearchParams(window.location.search);
        var currentPage = params.get('page');
        if (!currentPage) {
            return;
        }
        var items = document.querySelectorAll('.swps-shell-nav-item, .swps-shell-side-item');
        var active = null;
        items.forEach(function (item) {
            var href = item.getAttribute('href') || '';
            if (href.indexOf('page=' + currentPage) !== -1) {
                item.classList.add('is-active');
                if (!active) active = item;
            } else {
                item.classList.remove('is-active');
            }
        });
        // Scroll active item into view in horizontal nav.
        if (active && active.scrollIntoView) {
            try { active.scrollIntoView({ behavior: 'instant', block: 'nearest', inline: 'center' }); }
            catch (e) { /* ignore */ }
        }
    }

    /* ------------------------------------------------------------------
     * Search box: simple page-jump
     * Filter nav items live; Enter on first match navigates.
     * ------------------------------------------------------------------ */
    function bindSearch() {
        var input = document.querySelector('[data-swps-search]');
        if (!input) {
            return;
        }
        var items = Array.prototype.slice.call(
            document.querySelectorAll('.swps-shell-nav-item, .swps-shell-side-item')
        );

        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            items.forEach(function (item) {
                var label = item.textContent.trim().toLowerCase();
                item.style.display = (q === '' || label.indexOf(q) !== -1) ? '' : 'none';
            });
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var firstVisible = items.find(function (item) {
                    return item.style.display !== 'none' && item.getAttribute('href');
                });
                if (firstVisible) {
                    window.location.href = firstVisible.getAttribute('href');
                }
            }
            if (e.key === 'Escape') {
                input.value = '';
                items.forEach(function (item) { item.style.display = ''; });
                input.blur();
            }
        });
    }

    /* ------------------------------------------------------------------
     * Init
     * ------------------------------------------------------------------ */
    function init() {
        bindThemeToggle();
        activateCurrentNavItem();
        bindSearch();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
