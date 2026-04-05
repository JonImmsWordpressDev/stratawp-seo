/**
 * Post List SEO Column — tooltip positioning, refresh, bulk refresh.
 *
 * @package StrataWP_SEO
 */
(function ($) {
    'use strict';

    var config = window.swpsPostListSeo || {};

    /**
     * Reposition tooltip above or below depending on viewport space.
     */
    function positionTooltip($indicator) {
        var $tooltip = $indicator.find('.swps-seo-tooltip');
        if (!$tooltip.length) return;

        $tooltip.removeClass('swps-seo-tooltip--below');
        var rect = $indicator[0].getBoundingClientRect();
        var tooltipHeight = $tooltip.outerHeight();

        if (rect.top < tooltipHeight + 20) {
            $tooltip.addClass('swps-seo-tooltip--below');
        }
    }

    // Position tooltips on hover.
    $(document).on('mouseenter', '.swps-seo-indicator', function () {
        positionTooltip($(this));
    });

    /**
     * Single post refresh.
     */
    $(document).on('click', '.swps-seo-refresh', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $link = $(this);
        var postId = $link.data('post-id');
        var $indicator = $link.closest('.swps-seo-indicator');
        var $circle = $indicator.find('.swps-seo-circle').first();

        // Show loading state.
        var originalText = $circle.text();
        $circle.text('…').addClass('swps-loading');

        $.post(config.ajaxUrl, {
            action: 'swps_refresh_seo_score',
            nonce: config.nonce,
            post_id: postId
        })
        .done(function (response) {
            if (response.success && response.data.html) {
                $indicator.replaceWith(response.data.html);
            } else {
                $circle.text(originalText).removeClass('swps-loading');
            }
        })
        .fail(function () {
            $circle.text(originalText).removeClass('swps-loading');
        });
    });

    /**
     * Bulk refresh all SEO scores.
     */
    $(document).on('click', '#swps-bulk-refresh-btn', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var $bar = $btn.closest('.swps-bulk-refresh-bar');
        var $progress = $bar.find('.swps-progress');
        var $fill = $bar.find('.swps-progress-fill');
        var $status = $bar.find('.swps-bulk-status');

        $btn.prop('disabled', true);
        $progress.show();
        $fill.css('width', '0%');

        function processBatch(offset) {
            $.post(config.ajaxUrl, {
                action: 'swps_bulk_refresh_seo_scores',
                nonce: config.nonce,
                offset: offset
            })
            .done(function (response) {
                if (!response.success) {
                    $status.text('Error refreshing scores.');
                    $btn.prop('disabled', false);
                    return;
                }

                var data = response.data;
                var pct = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 100;
                $fill.css('width', pct + '%');
                $status.text(data.processed + ' / ' + data.total + ' scored');

                if (data.done) {
                    $status.text('Complete! Reloading…');
                    setTimeout(function () {
                        window.location.reload();
                    }, 500);
                } else {
                    processBatch(data.processed);
                }
            })
            .fail(function () {
                $status.text('Error refreshing scores.');
                $btn.prop('disabled', false);
            });
        }

        processBatch(0);
    });

    /**
     * Bulk generate meta for all posts.
     */
    $(document).on('click', '#swps-bulk-generate-meta-btn', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var $bar = $btn.closest('.swps-bulk-refresh-bar');
        var $progress = $bar.find('.swps-progress');
        var $fill = $bar.find('.swps-progress-fill');
        var $status = $bar.find('.swps-bulk-meta-status');
        var generated = 0;
        var skipped = 0;
        var errors = 0;

        $btn.prop('disabled', true);
        $bar.find('#swps-bulk-refresh-btn').prop('disabled', true);
        $progress.show();
        $fill.css('width', '0%');

        function processOne(offset) {
            $.post(config.ajaxUrl, {
                action: 'swps_bulk_generate_meta',
                nonce: config.nonce,
                offset: offset
            })
            .done(function (response) {
                if (!response.success) {
                    $status.text('Error: ' + (response.data.message || 'Unknown error'));
                    $btn.prop('disabled', false);
                    $bar.find('#swps-bulk-refresh-btn').prop('disabled', false);
                    return;
                }

                var data = response.data;
                var pct = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 100;
                $fill.css('width', pct + '%');

                if (data.skipped) {
                    skipped++;
                } else if (data.error) {
                    errors++;
                } else if (data.post_title) {
                    generated++;
                }

                var statusText = data.processed + ' / ' + data.total;
                if (data.post_title) {
                    statusText += ' — ' + (data.skipped ? 'Skipped' : data.error ? 'Error' : 'Generated') + ': ' + data.post_title;
                }
                $status.text(statusText);

                if (data.done) {
                    $status.text('Done! Generated: ' + generated + ', Skipped: ' + skipped + ', Errors: ' + errors + '. Reloading…');
                    setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                } else {
                    processOne(data.processed);
                }
            })
            .fail(function () {
                $status.text('Request failed. Try again.');
                $btn.prop('disabled', false);
                $bar.find('#swps-bulk-refresh-btn').prop('disabled', false);
            });
        }

        processOne(0);
    });

    /**
     * Inject bulk refresh bar above the posts table.
     */
    $(function () {
        var $table = $('.wp-list-table');
        if ($table.length && $table.find('th#swps_seo, th.column-swps_seo').length) {
            var bar = '<div class="swps-bulk-refresh-bar">' +
                '<button type="button" id="swps-bulk-refresh-btn" class="button">' +
                'Refresh All SEO Scores</button>' +
                ' <button type="button" id="swps-bulk-generate-meta-btn" class="button button-primary">' +
                'AI Generate All Meta</button>' +
                '<span class="swps-bulk-status"></span>' +
                '<span class="swps-bulk-meta-status"></span>' +
                '<div class="swps-progress"><div class="swps-progress-fill"></div></div>' +
                '</div>';
            $table.before(bar);
        }
    });

})(jQuery);
