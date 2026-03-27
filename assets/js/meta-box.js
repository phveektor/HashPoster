/**
 * HashPoster Meta Box JavaScript
 * Handles social posting meta box interactions in post editor
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Toggle platforms section
        $('input[name="hashposter_social[enabled]"]').on('change', function() {
            $('#hashposter-platforms-section').toggle($(this).is(':checked'));
        });

        // Preview functionality
        $('#hashposter-preview-btn').on('click', function() {
            var platforms = [];
            $('input[name="hashposter_social[platforms][]"]:checked').each(function() {
                platforms.push($(this).val());
            });

            if (platforms.length === 0) {
                alert(hashposterMetaBox.strings.no_platforms);
                return;
            }

            var customContent = $('#hashposter_custom_content').val();

            // Show loading
            $('#hashposter-preview-content').html('<p>' + hashposterMetaBox.strings.generating + '</p>').show();

            // AJAX request for preview
            $.ajax({
                url: hashposterMetaBox.ajax_url,
                type: 'POST',
                data: {
                    action: 'hashposter_preview_post',
                    post_id: hashposterMetaBox.post_id,
                    platforms: platforms,
                    custom_content: customContent,
                    nonce: hashposterMetaBox.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#hashposter-preview-content').html(response.data.html);
                    } else {
                        $('#hashposter-preview-content').html('<p style="color: red;">' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $('#hashposter-preview-content').html('<p style="color: red;">' + hashposterMetaBox.strings.failed + '</p>');
                }
            });
        });
    });

})(jQuery);
