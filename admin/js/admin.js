(function($) {
    'use strict';

    const $generateBtn    = $('#swps-generate-btn');
    const $analyzeBtn     = $('#swps-analyze-btn');
    const $generateAnother = $('#swps-generate-another');
    const $topicInput     = $('#swps-topic');
    const $progress       = $('#swps-progress');
    const $progressTitle  = $('#swps-progress-title');
    const $progressDesc   = $('#swps-progress-desc');
    const $error          = $('#swps-error');
    const $errorMessage   = $('#swps-error-message');
    const $result         = $('#swps-result');
    const $analysis       = $('#swps-analysis');

    // Progress messages to cycle through during generation.
    const progressMessages = [
        { title: 'Analyzing your site content...', desc: 'Reading your existing posts to understand your site.' },
        { title: 'Identifying content opportunities...', desc: 'Finding the best topic and keywords for your site.' },
        { title: 'Mapping internal links...', desc: 'Figuring out which existing posts to link to.' },
        { title: 'Writing your blog post...', desc: 'Crafting SEO-optimized content in your voice.' },
        { title: 'Adding structured data...', desc: 'Generating FAQ schema and meta tags.' },
        { title: 'Almost there...', desc: 'Finalizing the post and saving to WordPress.' },
    ];

    let progressInterval = null;
    let messageIndex = 0;

    /**
     * Start the progress animation.
     */
    function startProgress() {
        messageIndex = 0;
        $progress.slideDown(200);
        $error.hide();
        $result.hide();

        updateProgressMessage();
        progressInterval = setInterval(function() {
            messageIndex++;
            if (messageIndex < progressMessages.length) {
                updateProgressMessage();
            }
        }, 8000);
    }

    function updateProgressMessage() {
        const msg = progressMessages[messageIndex];
        $progressTitle.text(msg.title);
        $progressDesc.text(msg.desc);
    }

    function stopProgress() {
        clearInterval(progressInterval);
        $progress.slideUp(200);
    }

    function showError(message) {
        stopProgress();
        $errorMessage.text(message);
        $error.slideDown(200);
    }

    function showResult(data) {
        stopProgress();

        $('#swps-result-title').text(data.title);
        $('#swps-result-status').text(data.status === 'draft' ? 'Draft — ready for review' : data.status);
        $('#swps-result-keyword').text(data.focus_keyword || '—');
        $('#swps-result-meta').text(data.meta_description || '—');
        $('#swps-result-words').text(data.word_count ? '~' + data.word_count + ' words' : '—');

        // Internal links.
        if (data.internal_links && data.internal_links.length > 0) {
            const linkTexts = data.internal_links.map(function(link) {
                return '"' + link.anchor_text + '"';
            });
            $('#swps-result-links').text(data.internal_links.length + ' links: ' + linkTexts.join(', '));
        } else {
            $('#swps-result-links').text('None');
        }

        // Action URLs.
        $('#swps-result-edit').attr('href', data.edit_url);
        $('#swps-result-preview').attr('href', data.preview_url);

        $result.slideDown(300);

        // Scroll to result.
        $('html, body').animate({
            scrollTop: $result.offset().top - 50
        }, 400);
    }

    /**
     * Generate Post button click handler.
     */
    $generateBtn.on('click', function() {
        const topic = $topicInput.val().trim();

        $(this).prop('disabled', true);
        $analyzeBtn.prop('disabled', true);

        startProgress();

        $.ajax({
            url: swpsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_generate_post',
                nonce: swpsAdmin.nonce,
                topic: topic,
            },
            timeout: 180000, // 3 minute timeout.
            success: function(response) {
                if (response.success) {
                    showResult(response.data);
                } else {
                    showError(response.data.message || 'Generation failed. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                if (status === 'timeout') {
                    showError('The request timed out. The AI may be under heavy load — please try again in a minute.');
                } else {
                    showError('Request failed: ' + (error || 'Unknown error. Check your server logs.'));
                }
            },
            complete: function() {
                $generateBtn.prop('disabled', false);
                $analyzeBtn.prop('disabled', false);
            }
        });
    });

    /**
     * Analyze Site button click handler.
     */
    $analyzeBtn.on('click', function() {
        $(this).prop('disabled', true).text('Analyzing...');
        $analysis.hide();

        $.ajax({
            url: swpsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_analyze_site',
                nonce: swpsAdmin.nonce,
            },
            success: function(response) {
                if (response.success) {
                    $('#swps-analysis-data').text(JSON.stringify(response.data, null, 2));
                    $analysis.slideDown(300);
                } else {
                    showError(response.data.message || 'Analysis failed.');
                }
            },
            error: function() {
                showError('Failed to analyze site.');
            },
            complete: function() {
                $analyzeBtn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-chart-bar" style="margin-top: 4px;"></span> Analyze My Site'
                );
            }
        });
    });

    /**
     * Generate Another button.
     */
    $generateAnother.on('click', function() {
        $result.slideUp(200);
        $topicInput.val('').focus();
    });

    // Allow Enter key in topic field to trigger generation.
    $topicInput.on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $generateBtn.trigger('click');
        }
    });

})(jQuery);
