(function($) {
    'use strict';

    function swpsScoreBadge(score) {
        if (score === null || score === undefined) return '';
        var cls = score >= 80 ? 'excellent' : (score >= 60 ? 'good' : 'poor');
        return '<span class="swps-score-badge swps-score-badge--' + cls + '">Score: ' + score + '/100</span>';
    }

    // --- Generate page elements ---
    const $generateBtn    = $('#swps-generate-btn');
    const $analyzeBtn     = $('#swps-analyze-btn');
    const $previewBtn     = $('#swps-preview-btn');
    const $bulkBtn        = $('#swps-bulk-btn');
    const $generateAnother = $('#swps-generate-another');
    const $topicInput     = $('#swps-topic');
    const $templateSelect = $('#swps-template');
    const $bulkCount      = $('#swps-bulk-count');
    const $progress       = $('#swps-progress');
    const $progressTitle  = $('#swps-progress-title');
    const $progressDesc   = $('#swps-progress-desc');
    const $error          = $('#swps-error');
    const $errorMessage   = $('#swps-error-message');
    const $result         = $('#swps-result');
    const $analysis       = $('#swps-analysis');
    const $rateLimitBar   = $('#swps-rate-limit');
    const $previewModal   = $('#swps-preview-modal');

    // --- Settings page elements ---
    const $aiProvider     = $('select[name="swps_ai_provider"]');
    const $imageProvider  = $('select[name="swps_image_provider"]');
    const $featuredImages = $('input[name="swps_featured_images"]');
    const $modelSelect    = $('select[name="swps_model"]');
    const $clearCacheBtn  = $('#swps-clear-cache-btn');

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
    let rateLimitTimer = null;

    // =====================================================================
    // Settings page: AI provider switching
    // =====================================================================

    function updateAIKeyVisibility() {
        if (!$aiProvider.length) return;

        const slug = $aiProvider.val();

        // Hide all AI key rows, then show the active one.
        $('.swps-ai-key-row').closest('tr').hide();
        $('.swps-provider-' + slug).closest('tr').show();
    }

    function loadModelsForProvider(slug) {
        if (!$modelSelect.length) return;

        var currentModel = swpsAdmin.current_model || $modelSelect.val();

        $modelSelect.prop('disabled', true);

        $.ajax({
            url: swpsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_get_models',
                nonce: swpsAdmin.nonce,
                provider: slug,
            },
            success: function(response) {
                if (response.success) {
                    $modelSelect.empty();
                    $.each(response.data, function(id, name) {
                        $modelSelect.append(
                            $('<option>').val(id).text(name)
                        );
                    });

                    // Try to restore previous selection if it exists in the new list.
                    if ($modelSelect.find('option[value="' + currentModel + '"]').length) {
                        $modelSelect.val(currentModel);
                    }
                }
            },
            complete: function() {
                $modelSelect.prop('disabled', false);
            }
        });
    }

    if ($aiProvider.length) {
        $aiProvider.on('change', function() {
            updateAIKeyVisibility();
            loadModelsForProvider($(this).val());
        });

        // Initial state on page load.
        updateAIKeyVisibility();
    }

    // =====================================================================
    // Settings page: Image provider switching
    // =====================================================================

    function updateImageKeyVisibility() {
        if (!$imageProvider.length) return;

        var slug = $imageProvider.val();
        var imagesEnabled = $featuredImages.is(':checked');

        // Hide all image key rows.
        $('.swps-image-key-row').closest('tr').hide();

        // Show the active provider's key row only if featured images are enabled.
        if (imagesEnabled) {
            $('.swps-image-provider-' + slug).closest('tr').show();
        }
    }

    if ($imageProvider.length) {
        $imageProvider.on('change', updateImageKeyVisibility);
        $featuredImages.on('change', updateImageKeyVisibility);

        // Initial state on page load.
        updateImageKeyVisibility();
    }

    // =====================================================================
    // Settings page: Clear cache button
    // =====================================================================

    if ($clearCacheBtn.length) {
        $clearCacheBtn.on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Clearing...');

            $.ajax({
                url: swpsAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'swps_clear_cache',
                    nonce: swpsAdmin.nonce,
                },
                success: function(response) {
                    if (response.success) {
                        $btn.text('Cache Cleared!');
                        setTimeout(function() {
                            $btn.text('Clear Cache').prop('disabled', false);
                        }, 2000);
                    } else {
                        $btn.text('Failed').prop('disabled', false);
                    }
                },
                error: function() {
                    $btn.text('Error').prop('disabled', false);
                }
            });
        });
    }

    // =====================================================================
    // Generate page: Rate limit indicator
    // =====================================================================

    function initRateLimit() {
        if (!$rateLimitBar.length) return;

        var remaining = parseInt(swpsAdmin.rate_limit_remaining, 10) || 0;
        if (remaining > 0) {
            startRateLimitCountdown(remaining);
        } else {
            showRateLimitReady();
        }
    }

    function startRateLimitCountdown(seconds) {
        setGenerateButtonsEnabled(false);
        updateRateLimitDisplay(seconds);

        rateLimitTimer = setInterval(function() {
            seconds--;
            if (seconds <= 0) {
                clearInterval(rateLimitTimer);
                rateLimitTimer = null;
                showRateLimitReady();
                setGenerateButtonsEnabled(true);
            } else {
                updateRateLimitDisplay(seconds);
            }
        }, 1000);
    }

    function updateRateLimitDisplay(seconds) {
        $rateLimitBar
            .html('<span class="swps-status-dot swps-status-inactive"></span> <strong>Cooldown:</strong> ' + seconds + 's remaining')
            .show();
    }

    function showRateLimitReady() {
        $rateLimitBar
            .html('<span class="swps-status-dot swps-status-active"></span> <strong>Ready</strong> to generate')
            .show();
    }

    function setGenerateButtonsEnabled(enabled) {
        $generateBtn.prop('disabled', !enabled);
        $previewBtn.prop('disabled', !enabled);
        $bulkBtn.prop('disabled', !enabled);
    }

    // =====================================================================
    // Generate page: progress animation
    // =====================================================================

    function startProgress(customTitle) {
        messageIndex = 0;
        $progress.slideDown(200);
        $error.hide();
        $result.hide();

        if (customTitle) {
            $progressTitle.text(customTitle);
            $progressDesc.text('This may take a moment...');
        } else {
            updateProgressMessage();
        }

        progressInterval = setInterval(function() {
            if (!customTitle) {
                messageIndex++;
                if (messageIndex < progressMessages.length) {
                    updateProgressMessage();
                }
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

        // Cost info.
        if (data.cost) {
            $('#swps-result-cost').text('$' + parseFloat(data.cost).toFixed(4));
            $('#swps-result-cost-row').show();
        } else {
            $('#swps-result-cost-row').hide();
        }

        // Content score badge.
        if (data.content_score !== null && data.content_score !== undefined) {
            var scoreHtml = swpsScoreBadge(data.content_score);
            var minScore = parseInt(swpsAdmin.min_content_score, 10) || 0;
            if (minScore > 0 && data.content_score < minScore) {
                scoreHtml += '<p class="swps-score-blocked-notice">Below minimum (' + minScore + ') — saved as draft.</p>';
            }
            $('#swps-result-score').html(scoreHtml);
            $('#swps-result-score-row').show();
        } else {
            $('#swps-result-score-row').hide();
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
     * Get the currently selected template.
     */
    function getSelectedTemplate() {
        return $templateSelect.length ? $templateSelect.val() : 'auto';
    }

    /**
     * Generate Post button click handler.
     */
    $generateBtn.on('click', function() {
        const topic    = $topicInput.val().trim();
        const template = getSelectedTemplate();

        $(this).prop('disabled', true);
        $analyzeBtn.prop('disabled', true);
        $previewBtn.prop('disabled', true);
        $bulkBtn.prop('disabled', true);

        startProgress();

        $.ajax({
            url: swpsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_generate_post',
                nonce: swpsAdmin.nonce,
                topic: topic,
                template: template,
            },
            timeout: 180000, // 3 minute timeout.
            success: function(response) {
                if (response.success) {
                    showResult(response.data);
                    // Start rate limit cooldown after successful generation.
                    var cooldown = parseInt(swpsAdmin.rate_limit_remaining, 10) || 60;
                    if (cooldown > 0) {
                        startRateLimitCountdown(cooldown);
                    }
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
                $previewBtn.prop('disabled', false);
                $bulkBtn.prop('disabled', false);
            }
        });
    });

    // =====================================================================
    // Generate page: Preview button
    // =====================================================================

    $previewBtn.on('click', function() {
        const topic    = $topicInput.val().trim();
        const template = getSelectedTemplate();

        $(this).prop('disabled', true);
        $generateBtn.prop('disabled', true);
        $bulkBtn.prop('disabled', true);

        startProgress('Generating preview...');

        $.ajax({
            url: swpsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_preview_post',
                nonce: swpsAdmin.nonce,
                topic: topic,
                template: template,
            },
            timeout: 180000,
            success: function(response) {
                stopProgress();
                if (response.success) {
                    showPreviewModal(response.data);
                } else {
                    showError(response.data.message || 'Preview failed.');
                }
            },
            error: function(xhr, status, error) {
                if (status === 'timeout') {
                    showError('The preview request timed out.');
                } else {
                    showError('Preview failed: ' + (error || 'Unknown error.'));
                }
            },
            complete: function() {
                $previewBtn.prop('disabled', false);
                $generateBtn.prop('disabled', false);
                $bulkBtn.prop('disabled', false);
            }
        });
    });

    function showPreviewModal(data) {
        $('#swps-preview-title').text(data.title || 'Untitled');
        $('#swps-preview-meta').text(data.meta_description || '');
        $('#swps-preview-keyword').text(data.focus_keyword || '—');
        $('#swps-preview-content').html(data.content || '');

        $previewModal.fadeIn(200);

        // Publish button in preview modal.
        $('#swps-preview-publish').off('click').on('click', function() {
            $previewModal.fadeOut(200);
            // Trigger a generate with the same topic.
            $topicInput.val(data.title || '');
            $generateBtn.trigger('click');
        });
    }

    // Close preview modal.
    $(document).on('click', '.swps-modal-close, .swps-modal-overlay', function() {
        $previewModal.fadeOut(200);
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $previewModal.is(':visible')) {
            $previewModal.fadeOut(200);
        }
    });

    // =====================================================================
    // Generate page: Bulk generate
    // =====================================================================

    $bulkBtn.on('click', function() {
        var count    = parseInt($bulkCount.val(), 10) || 1;
        var template = getSelectedTemplate();

        if (count < 1) count = 1;
        if (count > 5) count = 5;

        if (!confirm('Generate ' + count + ' post' + (count > 1 ? 's' : '') + '? This will run ' + count + ' AI generation' + (count > 1 ? 's' : '') + ' and may take several minutes.')) {
            return;
        }

        $(this).prop('disabled', true);
        $generateBtn.prop('disabled', true);
        $previewBtn.prop('disabled', true);

        startProgress('Generating ' + count + ' posts... (this may take a few minutes)');

        $.ajax({
            url: swpsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_bulk_generate',
                nonce: swpsAdmin.nonce,
                count: count,
                template: template,
            },
            timeout: 600000, // 10 minute timeout for bulk.
            success: function(response) {
                stopProgress();
                if (response.success) {
                    showBulkResults(response.data);
                } else {
                    showError(response.data.message || 'Bulk generation failed.');
                }
            },
            error: function(xhr, status, error) {
                if (status === 'timeout') {
                    showError('Bulk generation timed out. Check your posts — some may have been created.');
                } else {
                    showError('Bulk generation failed: ' + (error || 'Unknown error.'));
                }
            },
            complete: function() {
                $bulkBtn.prop('disabled', false);
                $generateBtn.prop('disabled', false);
                $previewBtn.prop('disabled', false);
            }
        });
    });

    function escHtml(str) {
        return $('<span>').text(str || '').html();
    }

    function showBulkResults(data) {
        var html = '<h2><span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> Bulk Generation Complete</h2>';
        html += '<p><strong>' + data.completed + '</strong> of <strong>' + data.total + '</strong> posts generated successfully.</p>';

        if (data.results && data.results.length > 0) {
            html += '<table class="swps-result-table">';
            html += '<tr><th>Title</th><th>Status</th><th>Actions</th></tr>';

            data.results.forEach(function(r) {
                html += '<tr>';
                if (r.success) {
                    html += '<td>' + escHtml(r.data.title) + '</td>';
                    html += '<td><span style="color:#00a32a;">Created</span></td>';
                    html += '<td><a href="' + escHtml(r.data.edit_url) + '" target="_blank" class="button button-small">Edit</a></td>';
                } else {
                    html += '<td colspan="2"><span style="color:#d63638;">Failed: ' + escHtml(r.message) + '</span></td>';
                    html += '<td></td>';
                }
                html += '</tr>';
            });

            html += '</table>';
        }

        $result.html(html).slideDown(300);

        $('html, body').animate({
            scrollTop: $result.offset().top - 50
        }, 400);
    }

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

    // Initialize rate limit on page load.
    initRateLimit();

    // Voice Profile form submission.
    $('#swps-voice-profile-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#swps-save-profile');
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Saving...');
        $.post(swpsAdmin.ajax_url, $(this).serialize() + '&action=swps_save_voice_profile', function(res) {
            if (res.success) {
                window.location.href = swpsAdmin.ajax_url.replace('admin-ajax.php', 'admin.php?page=swps-voice-profiles&saved=1');
            } else {
                alert(res.data.message || 'Error saving profile.');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // Voice Profile delete.
    $(document).on('click', '.swps-delete-profile', function(e) {
        e.preventDefault();
        if (!confirm('Delete this voice profile?')) return;
        var id = $(this).data('id');
        $.post(swpsAdmin.ajax_url, { action: 'swps_delete_voice_profile', nonce: swpsAdmin.nonce, profile_id: id }, function(res) {
            if (res.success) location.reload();
        });
    });

    // Formality range slider label.
    $('#vp-formality').on('input', function() {
        $('#vp-formality-label').text(this.value + '/10');
    });

    // =====================================================================
    // SEO Audit page
    // =====================================================================

    var $auditProgress = $('#swps-audit-progress');

    // Toggle issues.
    $(document).on('click', '.swps-toggle-issues', function() {
        var moduleId = $(this).data('module');
        var $issues = $('#swps-issues-' + moduleId);
        $issues.slideToggle(200);
        var $icon = $(this).find('.dashicons');
        $icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
    });

    // Run Audit.
    $('#swps-run-audit').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $auditProgress.slideDown(200);

        $.ajax({
            url: swpsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_run_audit',
                nonce: swpsAdmin.nonce,
            },
            timeout: 120000,
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Audit failed.');
                }
            },
            error: function() {
                alert('Audit request failed.');
            },
            complete: function() {
                $btn.prop('disabled', false);
                $auditProgress.slideUp(200);
            }
        });
    });

    // Fix single module.
    $(document).on('click', '.swps-fix-module', function() {
        var $btn = $(this);
        var moduleId = $btn.data('module');
        $btn.prop('disabled', true).text('Fixing...');

        $.ajax({
            url: swpsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_fix_module',
                nonce: swpsAdmin.nonce,
                module_id: moduleId,
            },
            timeout: 60000,
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Fix failed.');
                    $btn.prop('disabled', false).text('Fix');
                }
            },
            error: function() {
                alert('Fix request failed.');
                $btn.prop('disabled', false).text('Fix');
            }
        });
    });

    // Fix All.
    $('#swps-fix-all').on('click', function() {
        if (!confirm('Run auto-fix for all fixable modules?')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Fixing all...');
        $auditProgress.slideDown(200);

        $.ajax({
            url: swpsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_fix_all',
                nonce: swpsAdmin.nonce,
            },
            timeout: 120000,
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Fix all failed.');
                }
            },
            error: function() {
                alert('Fix all request failed.');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Fix All');
                $auditProgress.slideUp(200);
            }
        });
    });

    // Export CSV.
    $('#swps-export-csv').on('click', function() {
        // Fetch current cached results via AJAX to build CSV.
        $.ajax({
            url: swpsAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_get_audit_results',
                nonce: swpsAdmin.nonce,
            },
            success: function(response) {
                if (!response.success) return;

                var csv = 'Module,Score,Status,Issues\n';
                var modules = response.data.modules || {};

                for (var id in modules) {
                    var mod = modules[id];
                    var issueMessages = (mod.issues || []).map(function(i) { return i.message; }).join('; ');
                    csv += '"' + id + '",' + mod.score + ',"' + mod.status + '","' + issueMessages.replace(/"/g, '""') + '"\n';
                }

                csv += '\nOverall Score,' + response.data.overall_score + '\n';

                var blob = new Blob([csv], { type: 'text/csv' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'swps-seo-audit-' + new Date().toISOString().slice(0, 10) + '.csv';
                a.click();
                URL.revokeObjectURL(url);
            }
        });
    });

})(jQuery);
