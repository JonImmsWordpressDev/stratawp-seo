/**
 * StrataWP SEO — AI Citations card (Keywords page)
 */
(function ($) {
    'use strict';

    if (typeof swpsCitations === 'undefined') return;
    if (!$('#swps-citations-card').length) return;

    var STATE_COLORS = {
        cited: '#16a34a',
        lost: '#dc2626',
        never: '#9ca3af',
        mixed: '#d97706'
    };

    var lastState = null;

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function escAttr(str) {
        return (str || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function badge(state) {
        var color = STATE_COLORS[state] || '#9ca3af';
        return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:' + color + '">' + escHtml(state) + '</span>';
    }

    function engineLabel(slug) {
        return swpsCitations.engines[slug] || slug;
    }

    // --- Load + render card state ---
    function loadState() {
        $.post(swpsCitations.ajax_url, {
            action: 'swps_citations_state',
            nonce: swpsCitations.nonce
        }, function (res) {
            if (!res.success) return;
            lastState = res.data;
            renderPrompts(res.data);
            renderSov(res.data.share_of_voice);
            renderBreakdown(res.data.breakdown);
            renderCap(res.data.cap);
        });
    }

    function renderPrompts(data) {
        var tbody = $('#swps-citations-table tbody');
        tbody.empty();

        if (!data.prompt_states.length) {
            tbody.html('<tr><td colspan="4">No tracked prompts yet. Add one above or seed from your keywords.</td></tr>');
            return;
        }

        data.prompt_states.forEach(function (row) {
            var badges = [];
            var lastDate = '';
            data.engines.forEach(function (engine) {
                var info = row.states[engine];
                if (!info) return;
                badges.push('<span title="' + escAttr(engineLabel(engine)) + '" style="margin-right:6px;white-space:nowrap">' +
                    escHtml(engineLabel(engine).split(' ')[0]) + ' ' + badge(info.state) + '</span>');
                if (info.last_date && info.last_date > lastDate) lastDate = info.last_date;
            });

            var tr = '<tr>';
            tr += '<td>' + escHtml(row.prompt) + '</td>';
            tr += '<td>' + (badges.length ? badges.join('') : '<em>No engines enabled</em>') + '</td>';
            tr += '<td>' + (lastDate ? escHtml(lastDate) : '—') + '</td>';
            tr += '<td><button class="button button-small swps-citation-remove" data-prompt="' + escAttr(row.prompt) + '">Remove</button></td>';
            tr += '</tr>';
            tbody.append(tr);
        });
    }

    function renderSov(sov) {
        var box = $('#swps-citation-sov');
        if (!sov || !sov.total_prompts) {
            box.html('<span style="color:var(--swps-text-muted);font-size:12px">No checks in the last 30 days.</span>');
            return;
        }
        var html = '';
        Object.keys(sov.pct).forEach(function (domain) {
            var pct = sov.pct[domain];
            var isUs = domain === sov.us;
            var color = isUs ? '#2271b1' : '#9ca3af';
            html += '<div style="margin-bottom:6px;font-size:12px">';
            html += '<div style="display:flex;justify-content:space-between">' +
                '<span>' + escHtml(domain) + (isUs ? ' <strong>(you)</strong>' : '') + '</span>' +
                '<span>' + pct + '%</span></div>';
            html += '<div style="background:#f0f0f1;border-radius:3px;height:8px;overflow:hidden">' +
                '<div style="width:' + Math.min(100, pct) + '%;height:8px;background:' + color + '"></div></div>';
            html += '</div>';
        });
        box.html(html);
    }

    function renderBreakdown(breakdown) {
        var list = $('#swps-citation-breakdown');
        list.empty();
        var domains = Object.keys(breakdown || {});
        if (!domains.length) {
            list.html('<li style="list-style:none;margin-left:-18px;color:var(--swps-text-muted)">No citation data yet.</li>');
            return;
        }
        domains.forEach(function (domain) {
            list.append('<li>' + escHtml(domain) + ' <span style="color:var(--swps-text-muted)">(' + parseInt(breakdown[domain], 10) + ')</span></li>');
        });
    }

    function renderCap(cap) {
        $('#swps-citation-cap-line').text(cap.used + ' of ' + cap.limit + ' checks this month');
    }

    // --- Add prompt ---
    $('#swps-citation-add-btn').on('click', function () {
        var input = $('#swps-citation-prompt-input');
        var prompt = input.val().trim();
        if (!prompt) return;

        $.post(swpsCitations.ajax_url, {
            action: 'swps_citations_add_prompt',
            nonce: swpsCitations.nonce,
            prompt: prompt
        }, function (res) {
            if (!res.success) {
                alert(res.data.message || 'Failed to add prompt.');
                return;
            }
            input.val('');
            loadState();
        });
    });

    // --- Remove prompt ---
    $(document).on('click', '.swps-citation-remove', function () {
        var prompt = $(this).data('prompt');
        if (!confirm('Stop tracking this prompt?')) return;

        $.post(swpsCitations.ajax_url, {
            action: 'swps_citations_remove_prompt',
            nonce: swpsCitations.nonce,
            prompt: prompt
        }, function () { loadState(); });
    });

    // --- Seed candidates ---
    $('#swps-citation-seed-btn').on('click', function () {
        var btn = $(this);
        var panel = $('#swps-citation-seed-panel');
        btn.prop('disabled', true);
        $('#swps-citation-spinner').addClass('is-active');

        $.post(swpsCitations.ajax_url, {
            action: 'swps_citations_seed',
            nonce: swpsCitations.nonce
        }, function (res) {
            btn.prop('disabled', false);
            $('#swps-citation-spinner').removeClass('is-active');
            if (!res.success) {
                alert((res.data && res.data.message) || 'Failed to load candidates.');
                return;
            }
            if (!res.data.length) {
                panel.html('<em>No new candidates found from tracked keywords or GSC question queries.</em>').show();
                return;
            }
            var html = '<strong style="display:block;margin-bottom:6px">Select prompts to track:</strong>';
            res.data.forEach(function (c) {
                html += '<label style="display:block;margin-bottom:3px;font-size:12px">' +
                    '<input type="checkbox" class="swps-citation-candidate" value="' + escAttr(c.prompt) + '" data-post-id="' + parseInt(c.post_id, 10) + '" /> ' +
                    escHtml(c.prompt) + ' <span style="color:var(--swps-text-muted)">(' + escHtml(c.source) + ')</span></label>';
            });
            html += '<button class="button button-primary" id="swps-citation-add-selected" style="margin-top:6px">Add selected</button> ';
            html += '<button class="button" id="swps-citation-seed-cancel" style="margin-top:6px">Cancel</button>';
            panel.html(html).show();
        });
    });

    $(document).on('click', '#swps-citation-seed-cancel', function () {
        $('#swps-citation-seed-panel').hide().empty();
    });

    $(document).on('click', '#swps-citation-add-selected', function () {
        var checked = $('.swps-citation-candidate:checked');
        if (!checked.length) return;
        var queue = checked.map(function () {
            return { prompt: $(this).val(), post_id: $(this).data('post-id') || 0 };
        }).get();

        var addNext = function () {
            if (!queue.length) {
                $('#swps-citation-seed-panel').hide().empty();
                loadState();
                return;
            }
            var item = queue.shift();
            $.post(swpsCitations.ajax_url, {
                action: 'swps_citations_add_prompt',
                nonce: swpsCitations.nonce,
                prompt: item.prompt,
                post_id: item.post_id
            }, function (res) {
                if (!res.success && res.data && res.data.message) {
                    alert(res.data.message);
                    queue = [];
                }
                addNext();
            });
        };
        addNext();
    });

    // --- Run checks now (sequential chunked AJAX) ---
    $('#swps-citation-run-btn').on('click', function () {
        if (!lastState || !lastState.prompt_states.length) {
            alert('Add at least one prompt first.');
            return;
        }
        if (!lastState.engines.length) {
            alert('No engines enabled. Select engines (with saved API keys) in Settings → AI Crawlers.');
            return;
        }

        var queue = [];
        lastState.prompt_states.forEach(function (row) {
            lastState.engines.forEach(function (engine) {
                queue.push({ prompt: row.prompt, engine: engine });
            });
        });

        var btn = $(this);
        var progress = $('#swps-citation-progress');
        var total = queue.length;
        var done = 0;
        var failed = 0;
        btn.prop('disabled', true);
        progress.show();

        var runNext = function () {
            if (!queue.length) {
                btn.prop('disabled', false);
                progress.text('Done — ' + done + ' of ' + total + ' checks completed' + (failed ? ' (' + failed + ' failed)' : '') + '.');
                loadState();
                return;
            }
            var item = queue.shift();
            progress.text('Checking ' + (done + failed + 1) + ' of ' + total + ': "' + item.prompt + '" on ' + engineLabel(item.engine) + '…');

            $.ajax({
                url: swpsCitations.ajax_url,
                method: 'POST',
                timeout: 180000,
                data: {
                    action: 'swps_citations_run_one',
                    nonce: swpsCitations.nonce,
                    prompt: item.prompt,
                    engine: item.engine
                },
                success: function (res) {
                    if (res.success) {
                        done++;
                    } else {
                        failed++;
                        if (res.data && res.data.code === 'cap') {
                            btn.prop('disabled', false);
                            progress.text('Stopped: ' + res.data.message);
                            loadState();
                            return;
                        }
                    }
                    runNext();
                },
                error: function () {
                    failed++;
                    runNext();
                }
            });
        };
        runNext();
    });

    // --- Init ---
    $(document).ready(loadState);

})(jQuery);
