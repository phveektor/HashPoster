/**
 * HashPoster Admin JavaScript
 */
jQuery(document).ready(function($) {
    // Tab functionality
    $('#hashposter-tabs > ul > li > a').click(function(e) {
        e.preventDefault();
        
        // Remove active class from all tabs
        $('#hashposter-tabs > ul > li > a').removeClass('active');
        
        // Add active class to current tab
        $(this).addClass('active');
        
        // Hide all tab content
        $('#hashposter-tabs > div').hide();
        
        // Show the current tab content
        $($(this).attr('href')).show();
    });
    
    // Set the first tab as active by default
    $('#hashposter-tabs > ul > li:first-child > a').addClass('active');
    
    // Test connection buttons
    $('.hashposter-test-connection').click(function() {
        var button = $(this);
        var platform = button.data('platform');
        var originalText = button.text();
        
        button.prop('disabled', true).text('Testing...');
        
        $.ajax({
            url: hashposterAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'hashposter_test_connection',
                platform: platform,
                nonce: hashposterAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    button.after('<span class="test-success" style="color:green;margin-left:10px">✓ ' + response.data.message + '</span>');
                } else {
                    button.after('<span class="test-error" style="color:red;margin-left:10px">✗ ' + response.data.message + '</span>');
                }
                
                // Remove the success/error message after 5 seconds
                setTimeout(function() {
                    $('.test-success, .test-error').fadeOut(500, function() {
                        $(this).remove();
                    });
                }, 5000);
            },
            error: function() {
                button.after('<span class="test-error" style="color:red;margin-left:10px">✗ Connection failed.</span>');
                
                // Remove the error message after 5 seconds
                setTimeout(function() {
                    $('.test-error').fadeOut(500, function() {
                        $(this).remove();
                    });
                }, 5000);
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Initialize tooltips
    $('.tooltip').hover(
        function() {
            var title = $(this).attr('title');
            $(this).data('tipText', title).removeAttr('title');
            $('<p class="hashposter-tooltip"></p>')
                .text(title)
                .appendTo('body')
                .fadeIn('fast');
        },
        function() {
            $(this).attr('title', $(this).data('tipText'));
            $('.hashposter-tooltip').remove();
        }
    ).mousemove(function(e) {
        var mousex = e.pageX + 10;
        var mousey = e.pageY + 10;
        $('.hashposter-tooltip')
            .css({ top: mousey, left: mousex });
    });
});
