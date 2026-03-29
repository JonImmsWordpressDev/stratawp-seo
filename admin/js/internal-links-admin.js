/* global jQuery, swpsLinksAdmin */
(function ($) {
    'use strict';

    // Rebuild Index.
    $('#swps-rebuild-index').on('click', function () {
        var $btn      = $(this).prop('disabled', true);
        var $progress = $('#swps-rebuild-progress').show();
        var $bar      = $('#swps-rebuild-bar');
        var $text     = $('#swps-rebuild-text');
        var $status   = $('#swps-rebuild-status');

        $status.text(swpsLinksAdmin.i18n.rebuilding);

        function processBatch(offset) {
            $.post(swpsLinksAdmin.ajaxUrl, {
                action: 'swps_link_rebuild',
                nonce:  swpsLinksAdmin.nonce,
                offset: offset
            }).done(function (response) {
                if (!response.success) {
                    $status.text(swpsLinksAdmin.i18n.error);
                    $btn.prop('disabled', false);
                    return;
                }

                var d = response.data;
                var pct = d.total > 0 ? Math.round((d.processed / d.total) * 100) : 100;
                $bar.val(pct);
                $text.text(
                    swpsLinksAdmin.i18n.progress
                        .replace('%1$d', d.processed)
                        .replace('%2$d', d.total)
                );

                if (d.done) {
                    $status.text(swpsLinksAdmin.i18n.complete);
                    $btn.prop('disabled', false);
                    setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                    processBatch(d.processed);
                }
            }).fail(function () {
                $status.text(swpsLinksAdmin.i18n.error);
                $btn.prop('disabled', false);
            });
        }

        processBatch(0);
    });

    // Check All.
    $('#swps-check-all').on('change', function () {
        $('.swps-opp-check').prop('checked', $(this).is(':checked'));
    });

    // Bulk Dismiss.
    $('#swps-bulk-dismiss').on('click', function () {
        var ids = [];
        $('.swps-opp-check:checked').each(function () {
            ids.push($(this).val());
        });

        if (!ids.length) return;

        $.post(swpsLinksAdmin.ajaxUrl, {
            action: 'swps_link_bulk_dismiss',
            nonce:  swpsLinksAdmin.nonce,
            ids:    ids
        }).done(function () {
            window.location.reload();
        });
    });

})(jQuery);
