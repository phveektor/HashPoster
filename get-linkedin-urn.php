<?php
/**
 * LinkedIn URN Helper - Secure Token Testing Utility
 *
 * This file provides a secure way to test LinkedIn API tokens and retrieve URNs.
 * Security features: CSRF protection, input validation, rate limiting, secure token handling.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Ensure WordPress functions are available before proceeding
if ( ! function_exists( 'current_user_can' ) || ! function_exists( 'wp_die' ) ) {
    exit( 'WordPress not loaded properly.' );
}

// Include WordPress admin functions
if ( defined( 'ABSPATH' ) ) {
    require_once ABSPATH . 'wp-admin/includes/admin.php';
}

// Security: Check user capabilities
if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'hashposter'), __('Access Denied', 'hashposter'), array('response' => 403));
}

// Security: Rate limiting - prevent abuse
if ( function_exists( 'get_current_user_id' ) && function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
    $rate_limit_key = 'hashposter_linkedin_urn_rate_' . get_current_user_id();
    $last_request = get_transient($rate_limit_key);

    if ($last_request && (time() - $last_request) < 30) { // 30 second cooldown
        wp_die(__('Please wait 30 seconds before making another request.', 'hashposter'), __('Rate Limited', 'hashposter'), array('response' => 429));
    }
    set_transient($rate_limit_key, time(), 300); // Store for 5 minutes
}

// Include plugin files
require_once dirname(__FILE__) . '/hashposter.php';

// Initialize variables
$result = null;
$error = null;
$urn = null;

// Security: CSRF protection and input handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify nonce
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'hashposter_linkedin_urn_test')) {
        $error = __('Security check failed. Please try again.', 'hashposter');
    } else {
        // Sanitize and validate input
        $access_token = sanitize_text_field($_POST['access_token'] ?? '');

        // Validate token format (basic check)
        if (empty($access_token)) {
            $error = __('Access token is required.', 'hashposter');
        } elseif (strlen($access_token) < 20) {
            $error = __('Access token appears to be too short.', 'hashposter');
        } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $access_token)) {
            $error = __('Access token contains invalid characters.', 'hashposter');
        } else {
            // Process the request
            try {
                $api = new HashPoster_API_Handler();
                $urn = $api->get_linkedin_person_urn($access_token);

                if (is_wp_error($urn)) {
                    $error = $urn->get_error_message();
                } else {
                    $result = __('Success! Your LinkedIn Person URN: ', 'hashposter') . '<code>' . esc_html($urn) . '</code>';
                }
            } catch (Exception $e) {
                $error = __('An error occurred while processing your request.', 'hashposter');
                error_log('HashPoster LinkedIn URN Error: ' . $e->getMessage());
            }
        }
    }
}

// Security: Add security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('LinkedIn URN Helper - HashPoster', 'hashposter'); ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .security-notice {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .btn {
            background: #007cba;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            background: #005a87;
        }
        .instructions {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            margin-top: 20px;
        }
        .instructions ol {
            padding-left: 20px;
        }
        .instructions li {
            margin-bottom: 8px;
        }
        code {
            background: #f1f1f1;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?php _e('LinkedIn URN Helper', 'hashposter'); ?></h1>
        <p><?php _e('Securely test your LinkedIn access token and retrieve your Person URN.', 'hashposter'); ?></p>

        <div class="security-notice">
            <strong><?php _e('Security Notice:', 'hashposter'); ?></strong>
            <?php _e('This tool is for testing purposes only. Never share your access tokens publicly. This page includes security measures like rate limiting and input validation.', 'hashposter'); ?>
        </div>

        <?php if ($error): ?>
            <div class="error">
                <?php echo esc_html($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="success">
                <?php echo wp_kses($result, array('code' => array())); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('hashposter_linkedin_urn_test'); ?>

            <div class="form-group">
                <label for="access_token"><?php _e('LinkedIn Access Token:', 'hashposter'); ?> <span style="color: red;">*</span></label>
                <input type="text"
                       id="access_token"
                       name="access_token"
                       required
                       placeholder="<?php _e('Enter your LinkedIn access token', 'hashposter'); ?>"
                       value="<?php echo esc_attr($_POST['access_token'] ?? ''); ?>">
                <p class="description"><?php _e('Your access token should be a long string starting with "AQ".', 'hashposter'); ?></p>
            </div>

            <button type="submit" class="btn"><?php _e('Get Person URN', 'hashposter'); ?></button>
        </form>

        <div class="instructions">
            <h3><?php _e('How to get your LinkedIn Access Token:', 'hashposter'); ?></h3>
            <ol>
                <li><?php _e('Go to the <a href="https://developer.linkedin.com/" target="_blank">LinkedIn Developer Portal</a>', 'hashposter'); ?></li>
                <li><?php _e('Create or select an application', 'hashposter'); ?></li>
                <li><?php _e('Ensure your app has the <code>r_liteprofile</code> permission', 'hashposter'); ?></li>
                <li><?php _e('Generate an access token using OAuth 2.0 flow', 'hashposter'); ?></li>
                <li><?php _e('Copy the token and paste it above', 'hashposter'); ?></li>
            </ol>

            <h3><?php _e('Security Best Practices:', 'hashposter'); ?></h3>
            <ul>
                <li><?php _e('Never share your access tokens publicly', 'hashposter'); ?></li>
                <li><?php _e('Regenerate tokens regularly', 'hashposter'); ?></li>
                <li><?php _e('Use tokens with minimal required permissions', 'hashposter'); ?></li>
                <li><?php _e('Monitor token usage in your LinkedIn app dashboard', 'hashposter'); ?></li>
            </ul>
        </div>

        <p style="margin-top: 30px; text-align: center; color: #666;">
            <?php _e('Prefer the user-friendly interface?', 'hashposter'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=hashposter-get-linkedin-org-urn')); ?>" target="_blank">
                <?php _e('Use the LinkedIn URN Helper in your WordPress dashboard', 'hashposter'); ?>
            </a>
        </p>
    </div>
</body>
</html>
