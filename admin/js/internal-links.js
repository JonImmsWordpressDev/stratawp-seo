/* global jQuery, swpsInternalLinks, wp */
(function ($) {
    'use strict';

    var $metabox = $('#swps-internal-links-metabox');
    if (!$metabox.length) {
        return;
    }

    // Insert link into editor content.
    $metabox.on('click', '.swps-insert-link', function (e) {
        e.preventDefault();

        var $li       = $(this).closest('li');
        var targetUrl = $li.data('target-url');
        var anchor    = $li.data('anchor');
        var targetId  = $li.data('target-id');
        var postId    = $('#post_ID').val();
        var $btn      = $(this);

        $btn.prop('disabled', true).text(swpsInternalLinks.i18n.inserting);

        // Insert link HTML into the editor.
        var linkHtml = '<a href="' + targetUrl + '">' + anchor + '</a>';

        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/block-editor')) {
            // Block editor: insert as inline content at cursor or append to selected block.
            var selectedBlock = wp.data.select('core/block-editor').getSelectedBlock();
            if (selectedBlock && selectedBlock.attributes && typeof selectedBlock.attributes.content === 'string') {
                var newContent = selectedBlock.attributes.content + ' ' + linkHtml;
                wp.data.dispatch('core/block-editor').updateBlockAttributes(selectedBlock.clientId, {
                    content: newContent
                });
            } else {
                // Insert as a new paragraph block.
                var newBlock = wp.blocks.createBlock('core/paragraph', {
                    content: linkHtml
                });
                wp.data.dispatch('core/block-editor').insertBlock(newBlock);
            }
        } else if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            // Classic editor.
            tinymce.activeEditor.execCommand('mceInsertContent', false, ' ' + linkHtml);
        }

        // Mark as inserted via AJAX.
        $.post(swpsInternalLinks.ajaxUrl, {
            action:      'swps_link_insert',
            nonce:       swpsInternalLinks.nonce,
            post_id:     postId,
            target_id:   targetId,
            anchor_text: anchor
        }).always(function () {
            $li.fadeOut(300, function () { $(this).remove(); });
        });
    });

    // Dismiss suggestion.
    $metabox.on('click', '.swps-dismiss-link', function (e) {
        e.preventDefault();

        var $li      = $(this).closest('li');
        var targetId = $li.data('target-id');
        var postId   = $('#post_ID').val();

        $.post(swpsInternalLinks.ajaxUrl, {
            action:    'swps_link_dismiss',
            nonce:     swpsInternalLinks.nonce,
            post_id:   postId,
            target_id: targetId
        });

        $li.fadeOut(300, function () { $(this).remove(); });
    });

    // Deep Analysis button.
    $('#swps-deep-analysis').on('click', function (e) {
        e.preventDefault();

        var $btn    = $(this);
        var $status = $('#swps-deep-analysis-status');
        var postId  = $btn.data('post-id');

        $btn.prop('disabled', true);
        $status.text(swpsInternalLinks.i18n.analyzing);

        $.post(swpsInternalLinks.ajaxUrl, {
            action:  'swps_link_deep_analysis',
            nonce:   swpsInternalLinks.nonce,
            post_id: postId
        }).done(function (response) {
            if (response.success) {
                $status.text(swpsInternalLinks.i18n.done);
                // Reload the page to show updated suggestions.
                window.location.reload();
            } else {
                $status.text(response.data.message || swpsInternalLinks.i18n.error);
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            $status.text(swpsInternalLinks.i18n.error);
            $btn.prop('disabled', false);
        });
    });

})(jQuery);
