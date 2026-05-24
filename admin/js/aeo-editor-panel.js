/* global jQuery, wp, swpsAeo */
(function ($) {
    'use strict';

    // ── Classic editor metabox wiring ─────────────────────────────────
    function panelPostId() {
        var $p = $('.swps-aeo-panel');
        return $p.length ? parseInt($p.data('post-id'), 10) : null;
    }

    function updatePanelScores(data) {
        if (!data) return;
        $('#swps-aeo-panel-num').text(data.total != null ? data.total : '—');
        ['extractability', 'markup', 'authority', 'coverage'].forEach(function (d) {
            var v = data.subscores ? data.subscores[d] : null;
            $('#swps-aeo-panel-sub-' + d).text(v === null || v === undefined ? '—' : v);
        });
    }

    function rescore() {
        var id = panelPostId();
        if (!id) return;
        var $btn = $('#swps-aeo-panel-rescore').prop('disabled', true).text('…');
        $.post(swpsAeo.ajaxUrl, {
            action:  'swps_aeo_score',
            nonce:   swpsAeo.nonce,
            post_id: id
        }).done(function (resp) {
            if (resp && resp.success) {
                updatePanelScores(resp.data);
            }
        }).always(function () {
            $btn.prop('disabled', false).text('Re-score');
        });
    }

    function improve() {
        var id = panelPostId();
        if (!id) return;
        var $btn = $('#swps-aeo-panel-improve').prop('disabled', true).text(swpsAeo.i18n.proposing);
        $.post(swpsAeo.ajaxUrl, {
            action:  'swps_aeo_propose',
            nonce:   swpsAeo.nonce,
            post_id: id
        }).done(function (resp) {
            $btn.prop('disabled', false).text(swpsAeo.i18n.apply.replace('Apply', 'Improve') || 'Improve AEO');
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || swpsAeo.i18n.genericFail);
                return;
            }
            var proposal = resp.data.proposal || {};
            var projected = proposal.projected_score != null ? proposal.projected_score : '?';
            alert(
                swpsAeo.i18n.projected + ': ' + projected + '\n\n' +
                'Open the AEO Optimize page to review the diff and apply changes.'
            );
        }).fail(function () {
            $btn.prop('disabled', false).text('Improve AEO');
            alert(swpsAeo.i18n.genericFail);
        });
    }

    $(document).on('click', '#swps-aeo-panel-rescore', rescore);
    $(document).on('click', '#swps-aeo-panel-improve', improve);

    // ── Gutenberg sidebar plugin ──────────────────────────────────────
    if (typeof wp === 'undefined' || !wp.plugins || !wp.editPost || !wp.element || !wp.components || !wp.data) {
        return;
    }

    var registerPlugin = wp.plugins.registerPlugin;
    var PluginSidebar = wp.editPost.PluginSidebar;
    var PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var Button = wp.components.Button;
    var PanelBody = wp.components.PanelBody;
    var Spinner = wp.components.Spinner;
    var useSelect = wp.data.useSelect;

    function ajaxPost(action, data, done) {
        $.post(swpsAeo.ajaxUrl, $.extend({
            action: action,
            nonce: swpsAeo.nonce
        }, data)).done(done).fail(function () {
            done({ success: false });
        });
    }

    function AeoSidebar() {
        var postId = useSelect(function (select) {
            return select('core/editor').getCurrentPostId();
        }, []);

        var stateInit = useState({ score: null, subscores: {} });
        var state = stateInit[0];
        var setState = stateInit[1];

        var loadingInit = useState(false);
        var loading = loadingInit[0];
        var setLoading = loadingInit[1];

        useEffect(function () {
            if (!postId) return;
            ajaxPost('swps_aeo_score', { post_id: postId }, function (resp) {
                if (resp && resp.success) {
                    setState({ score: resp.data.total, subscores: resp.data.subscores || {} });
                }
            });
        }, [postId]);

        var onRescore = function () {
            if (!postId) return;
            setLoading(true);
            ajaxPost('swps_aeo_score', { post_id: postId }, function (resp) {
                setLoading(false);
                if (resp && resp.success) {
                    setState({ score: resp.data.total, subscores: resp.data.subscores || {} });
                    if (wp.data.dispatch('core/notices')) {
                        wp.data.dispatch('core/notices').createNotice('success', 'AEO re-scored: ' + resp.data.total, { isDismissible: true, type: 'snackbar' });
                    }
                }
            });
        };

        var onImprove = function () {
            if (!postId) return;
            setLoading(true);
            ajaxPost('swps_aeo_propose', { post_id: postId }, function (resp) {
                setLoading(false);
                if (resp && resp.success) {
                    var p = resp.data.proposal || {};
                    var msg = 'AEO proposal ready (projected ' + (p.projected_score != null ? p.projected_score : '?') + '). Open AEO Optimize to review and apply.';
                    if (wp.data.dispatch('core/notices')) {
                        wp.data.dispatch('core/notices').createNotice('info', msg, { isDismissible: true, type: 'snackbar' });
                    } else {
                        alert(msg);
                    }
                } else {
                    var err = (resp && resp.data && resp.data.message) || swpsAeo.i18n.genericFail;
                    if (wp.data.dispatch('core/notices')) {
                        wp.data.dispatch('core/notices').createNotice('error', err, { isDismissible: true });
                    } else {
                        alert(err);
                    }
                }
            });
        };

        var dims = ['extractability', 'markup', 'authority', 'coverage'];
        var subRows = dims.map(function (d) {
            var v = state.subscores[d];
            return el(
                'li',
                { key: d, style: { display: 'flex', justifyContent: 'space-between', padding: '4px 0', borderBottom: '1px solid #eee', fontSize: '12px' } },
                el('strong', null, d.charAt(0).toUpperCase() + d.slice(1)),
                el('span', null, (v === null || v === undefined) ? '—' : v)
            );
        });

        return el(
            Fragment,
            null,
            el(PluginSidebarMoreMenuItem, { target: 'swps-aeo-sidebar', icon: 'chart-area' }, 'AEO Score'),
            el(
                PluginSidebar,
                { name: 'swps-aeo-sidebar', title: 'AEO Score', icon: 'chart-area' },
                el(
                    PanelBody,
                    null,
                    el('div', { style: { textAlign: 'center', marginBottom: '12px' } },
                        el('div', { style: { fontSize: '32px', fontWeight: '600' } }, state.score != null ? state.score : '—'),
                        el('div', { style: { fontSize: '11px', textTransform: 'uppercase', color: '#666' } }, 'AEO Score')
                    ),
                    el('ul', { style: { listStyle: 'none', padding: 0, margin: '0 0 12px' } }, subRows),
                    el('div', { style: { display: 'flex', gap: '6px' } },
                        el(Button, { isSecondary: true, onClick: onRescore, disabled: loading }, loading ? 'Working…' : 'Re-score'),
                        el(Button, { isPrimary: true, onClick: onImprove, disabled: loading }, loading ? 'Working…' : 'Improve AEO')
                    ),
                    loading ? el(Spinner, null) : null
                )
            )
        );
    }

    registerPlugin('swps-aeo-sidebar', { render: AeoSidebar, icon: 'chart-area' });

})(jQuery);
