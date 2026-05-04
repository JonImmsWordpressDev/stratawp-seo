/* global jQuery, swpsImageSeo */
(function ($) {
    'use strict';

    var BATCH_SIZE = 20;
    var $btn      = $('#swps-img-bulk-fix');
    var $status   = $('#swps-img-status');
    var $missing  = $('#swps-img-missing');

    if (!$btn.length) return;

    function setStatus(msg) {
        $status.text(msg);
    }

    function fixBatch(totalFixed) {
        return $.post(swpsImageSeo.ajaxUrl, {
            action: 'swps_image_seo_fix',
            nonce:  swpsImageSeo.nonce,
            batch:  BATCH_SIZE
        }).then(function (resp) {
            if (!resp || !resp.success) {
                throw new Error((resp && resp.data && resp.data.message) || swpsImageSeo.i18n.failed);
            }
            var data        = resp.data;
            var newTotal    = totalFixed + (data.fixed || 0);
            var remaining   = data.remaining || 0;
            $missing.text(remaining.toLocaleString());

            if ((data.attempted || 0) === 0 || remaining === 0) {
                return newTotal;
            }
            setStatus(
                swpsImageSeo.i18n.fixing + ' (' + newTotal + ' fixed, ' + remaining + ' remaining)'
            );
            return fixBatch(newTotal);
        });
    }

    $btn.on('click', function () {
        $btn.prop('disabled', true);
        setStatus(swpsImageSeo.i18n.scanning);

        fixBatch(0)
            .then(function (totalFixed) {
                var template = totalFixed === 1 ? swpsImageSeo.i18n.fixedOne : swpsImageSeo.i18n.fixedMany;
                setStatus(template.replace('%d', totalFixed) + ' ' + swpsImageSeo.i18n.done);
            })
            .fail(function (err) {
                setStatus((err && err.message) ? err.message : swpsImageSeo.i18n.failed);
            })
            .always(function () {
                $btn.prop('disabled', $missing.text() === '0');
            });
    });
}(jQuery));
