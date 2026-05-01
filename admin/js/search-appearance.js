/**
 * Search Appearance — live template preview.
 */
(function () {
    'use strict';

    const sampleData = {
        '%%title%%': 'Sample Post Title',
        '%%sitename%%': document.querySelector('#swps-sitename')?.value || 'My Site',
        '%%sep%%': document.querySelector('input[name="swps_title_separator"]:checked')?.value || '-',
        '%%excerpt%%': 'This is a sample excerpt from a post...',
        '%%category%%': 'Uncategorized',
        '%%tag%%': 'sample',
        '%%author%%': 'Admin',
        '%%date%%': new Date().toLocaleDateString(),
        '%%page%%': '',
        '%%searchphrase%%': 'search query',
        '%%pt_single%%': 'Post',
        '%%pt_plural%%': 'Posts',
    };

    function resolveTemplate(template) {
        let result = template;
        for (const [variable, value] of Object.entries(sampleData)) {
            result = result.replaceAll(variable, value);
        }
        return result.replace(/%%\w+%%/g, '').replace(/\s+/g, ' ').trim();
    }

    function updatePreviews() {
        // Update separator in sample data.
        const sepEl = document.querySelector('input[name="swps_title_separator"]:checked');
        if (sepEl) {
            sampleData['%%sep%%'] = sepEl.value;
        }

        document.querySelectorAll('.swps-title-template').forEach(function (input) {
            const preview = input.parentElement.querySelector('.swps-template-preview');
            if (preview) {
                preview.textContent = 'Preview: ' + resolveTemplate(input.value);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Initial previews.
        updatePreviews();

        // Update on input change.
        document.querySelectorAll('.swps-title-template').forEach(function (input) {
            input.addEventListener('input', updatePreviews);
        });

        // Update on separator change.
        document.querySelectorAll('input[name="swps_title_separator"]').forEach(function (radio) {
            radio.addEventListener('change', updatePreviews);
        });
    });
})();
