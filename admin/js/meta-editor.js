/**
 * StrataWP SEO — Meta Editor JS
 *
 * Live SERP preview, character counters, SEO checklist, AI generate.
 */
(function ($) {
    'use strict';

    if (typeof swpsAdmin === 'undefined') return;

    var $metaTitle, $metaDesc, $focusKw, $serpTitle, $serpDesc;
    var $titleCount, $descCount;

    function init() {
        $metaTitle  = $('#_swps_meta_title');
        $metaDesc   = $('#_swps_meta_description');
        $focusKw    = $('#_swps_focus_keyword');
        $serpTitle   = $('#swps-serp-title');
        $serpDesc    = $('#swps-serp-desc');
        $titleCount  = $('#swps-title-count');
        $descCount   = $('#swps-desc-count');

        if (!$metaTitle.length) return;

        // Live preview.
        $metaTitle.on('input', updatePreview);
        $metaDesc.on('input', updatePreview);
        $focusKw.on('input', updateChecklist);

        // Initial state.
        updatePreview();
        updateChecklist();

        // AI Generate.
        $('#swps-ai-generate-meta').on('click', aiGenerate);

        // Social preview updates.
        $('#_swps_social_title, #_swps_social_description, #_swps_social_image').on('input', updateSocialPreview);
        updateSocialPreview();
    }

    function updatePreview() {
        var title = $metaTitle.val() || $metaTitle.attr('placeholder') || '';
        var desc  = $metaDesc.val() || $metaDesc.attr('placeholder') || '';

        // Truncate for display.
        $serpTitle.text(title.length > 60 ? title.substring(0, 57) + '...' : title);
        $serpDesc.text(desc.length > 160 ? desc.substring(0, 157) + '...' : desc);

        // Character counters.
        updateCounter($titleCount, $metaTitle.val().length, 50, 60);
        updateCounter($descCount, $metaDesc.val().length, 140, 160);

        updateChecklist();
    }

    function updateCounter($el, len, min, max) {
        $el.text(len + '/' + max);
        $el.removeClass('swps-count-green swps-count-yellow swps-count-red');
        if (len === 0) return;
        if (len >= min && len <= max) {
            $el.addClass('swps-count-green');
        } else if (len > max) {
            $el.addClass('swps-count-red');
        } else {
            $el.addClass('swps-count-yellow');
        }
    }

    function updateChecklist() {
        var keyword = $focusKw.val().toLowerCase().trim();
        if (!keyword) {
            $('#swps-seo-checklist li .swps-check-icon').html('—').attr('class', 'swps-check-icon swps-check-neutral');
            return;
        }

        var title     = $metaTitle.val().toLowerCase();
        var desc      = $metaDesc.val().toLowerCase();
        var titleLen  = $metaTitle.val().length;
        var descLen   = $metaDesc.val().length;

        // Get post content from editor.
        var content = getEditorContent().toLowerCase();
        var firstParagraph = content.split(/\n\n|\r\n\r\n/)[0] || '';

        // Get H2 headings.
        var h2s = content.match(/<h2[^>]*>(.*?)<\/h2>/gi) || [];
        var h2Text = h2s.join(' ').toLowerCase();

        setCheck('keyword-in-title', title.indexOf(keyword) !== -1);
        setCheck('keyword-in-desc', desc.indexOf(keyword) !== -1);
        setCheck('title-length', titleLen >= 50 && titleLen <= 60);
        setCheck('desc-length', descLen >= 140 && descLen <= 160);
        setCheck('keyword-in-content', firstParagraph.indexOf(keyword) !== -1);
        setCheck('keyword-in-h2', h2Text.indexOf(keyword) !== -1);
    }

    function setCheck(name, pass) {
        var $li = $('[data-check="' + name + '"]');
        var $icon = $li.find('.swps-check-icon');
        if (pass) {
            $icon.html('&#10003;').attr('class', 'swps-check-icon swps-check-pass');
        } else {
            $icon.html('&#10007;').attr('class', 'swps-check-icon swps-check-fail');
        }
    }

    function getEditorContent() {
        // Block editor.
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            var content = wp.data.select('core/editor').getEditedPostContent();
            if (content) return content;
        }
        // Classic editor.
        if (typeof tinymce !== 'undefined') {
            var editor = tinymce.get('content');
            if (editor) return editor.getContent();
        }
        // Fallback: textarea.
        var $textarea = $('#content');
        return $textarea.length ? $textarea.val() : '';
    }

    function aiGenerate() {
        var btn = $(this);
        var postId = $('.swps-meta-editor').data('post-id');
        var keyword = $focusKw.val();

        btn.prop('disabled', true).text('Generating...');

        $.post(swpsAdmin.ajax_url, {
            action: 'swps_generate_meta',
            nonce: swpsAdmin.nonce,
            post_id: postId,
            focus_keyword: keyword
        }, function (res) {
            btn.prop('disabled', false).text('AI Generate');

            if (!res.success) {
                alert(res.data.message || 'Failed to generate meta.');
                return;
            }

            if (res.data.meta_title) {
                $metaTitle.val(res.data.meta_title);
            }
            if (res.data.meta_description) {
                $metaDesc.val(res.data.meta_description);
            }
            if (res.data.focus_keyword) {
                $focusKw.val(res.data.focus_keyword);
                updateChecklist();
            }

            updatePreview();
        });
    }

    function updateSocialPreview() {
        var title = $('#_swps_social_title').val() || $metaTitle.val() || $metaTitle.attr('placeholder') || '';
        var desc  = $('#_swps_social_description').val() || $metaDesc.val() || '';
        var image = $('#_swps_social_image').val() || '';

        $('#swps-fb-title').text(title);
        $('#swps-fb-desc').text(desc);

        var $fbImage = $('#swps-fb-image');
        if (image) {
            $fbImage.css('background-image', 'url(' + image + ')').show();
        } else {
            $fbImage.hide();
        }
    }

    $(document).ready(init);

})(jQuery);
