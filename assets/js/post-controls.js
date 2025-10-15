/**
 * HashPoster Post Controls JavaScript
 *
 * Handles post editor interactions for social media posting controls
 */

(function($) {
    'use strict';

    const HashPosterPostControls = {

        init: function() {
            this.bindEvents();
            this.initializeUI();
        },

        bindEvents: function() {
            // Toggle platforms section when enabled checkbox changes
            $(document).on('change', 'input[name="hashposter_social[enabled]"]', this.togglePlatformsSection);

            // Preview button click
            $(document).on('click', '#hashposter-preview-btn', this.handlePreview);

            // Auto-save draft when settings change
            $(document).on('change', 'input[name^="hashposter_social"], textarea[name^="hashposter_social"]', this.autoSaveDraft);
        },

        initializeUI: function() {
            // Set initial state of platforms section
            const enabledCheckbox = $('input[name="hashposter_social[enabled]"]');
            if (enabledCheckbox.length) {
                this.togglePlatformsSection.call(enabledCheckbox[0]);
            }

            // Add character counters
            this.addCharacterCounters();
        },

        togglePlatformsSection: function() {
            const platformsSection = $('#hashposter-platforms-section');
            const isEnabled = $(this).is(':checked');

            if (isEnabled) {
                platformsSection.slideDown(200);
            } else {
                platformsSection.slideUp(200);
            }
        },

        handlePreview: function() {
            const button = $(this);
            const previewContent = $('#hashposter-preview-content');
            const originalText = button.text();

            // Get selected platforms
            const platforms = [];
            $('input[name="hashposter_social[platforms][]"]:checked').each(function() {
                platforms.push($(this).val());
            });

            if (platforms.length === 0) {
                alert(hashposterPostControls.strings.no_platforms);
                return;
            }

            // Get form data
            const customContent = $('#hashposter_custom_content').val();
            const hashtags = $('#hashposter_hashtags').val();
            const preferUrlCards = $('#hashposter-prefer-url-cards').is(':checked') ? '1' : '0';
            const postId = $('#post_ID').val();

            // Show loading state
            button.prop('disabled', true).text(hashposterPostControls.strings.generating);
            previewContent.html('<p>' + hashposterPostControls.strings.generating + '</p>').show();

            // Make AJAX request
            $.ajax({
                url: hashposterPostControls.ajax_url,
                type: 'POST',
                data: {
                    action: 'hashposter_preview_post',
                    post_id: postId,
                    platforms: platforms,
                    custom_content: customContent,
                    hashtags: hashtags,
                    prefer_url_cards: preferUrlCards,
                    nonce: hashposterPostControls.nonce
                },
                success: function(response) {
                    if (response.success) {
                        previewContent.html(response.data.html);
                    } else {
                        previewContent.html('<p style="color: red;">' + (response.data.message || hashposterPostControls.strings.failed) + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('HashPoster Preview Error:', error);
                    previewContent.html('<p style="color: red;">' + hashposterPostControls.strings.failed + '</p>');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        },

        autoSaveDraft: function() {
            // Auto-save draft if WordPress auto-save is available
            if (typeof wp.autosave !== 'undefined') {
                wp.autosave.server.triggerSave();
            }
        },

        addCharacterCounters: function() {
            // Add character counters for platforms with limits
            const customContentTextarea = $('#hashposter_custom_content');

            if (customContentTextarea.length) {
                // Create counter element
                const counter = $('<div class="hashposter-char-counter">')
                    .css({
                        'font-size': '11px',
                        'color': '#666',
                        'text-align': 'right',
                        'margin-top': '2px'
                    });

                customContentTextarea.after(counter);

                // Update counter on input
                const updateCounter = function() {
                    const text = customContentTextarea.val();
                    const charCount = text.length;
                    const maxChars = 280; // Twitter-like limit

                    counter.text(charCount + '/' + maxChars + ' characters');

                    if (charCount > maxChars) {
                        counter.css('color', '#dc3232');
                    } else if (charCount > maxChars * 0.9) {
                        counter.css('color', '#ffb900');
                    } else {
                        counter.css('color', '#666');
                    }
                };

                customContentTextarea.on('input', updateCounter);
                updateCounter(); // Initial count
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        HashPosterPostControls.init();
    });

    // Also initialize when post editor is loaded (for Gutenberg compatibility)
    $(document).on('DOMContentLoaded', function() {
        if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe) {
            wp.data.subscribe(function() {
                if (wp.data.select('core/editor') && !HashPosterPostControls.initialized) {
                    HashPosterPostControls.init();
                    HashPosterPostControls.initialized = true;
                }
            });
        }
    });

})(jQuery);