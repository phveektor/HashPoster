<?php
// Usage: Place this file in your plugin folder, then visit it in your browser (while logged in as admin).
require_once dirname(__FILE__) . '/hashposter.php';

if ( ! current_user_can('manage_options') ) {
    wp_die('Unauthorized');
}

$access_token = 'YOUR_LINKEDIN_ACCESS_TOKEN_HERE'; // <-- Replace with your token

$api = new HashPoster_API_Handler();
$urn = $api->get_linkedin_person_urn($access_token);

echo '<pre>';
if (is_wp_error($urn)) {
    echo $urn->get_error_message();
} else {
    echo "Your LinkedIn Person URN: " . esc_html($urn);
}
echo '</pre>';

if (function_exists('admin_url')) {
    echo '<p><strong>Tip:</strong> For a user-friendly way to generate your LinkedIn Person or Organization URN, use the <a href="' . admin_url('admin.php?page=hashposter-get-linkedin-org-urn') . '" target="_blank">LinkedIn URN Helper</a> page in your WordPress dashboard.</p>';
} else {
    echo '<p><strong>Tip:</strong> For a user-friendly way to generate your LinkedIn Person or Organization URN, visit the LinkedIn URN Helper page in your WordPress dashboard: <code>/wp-admin/admin.php?page=hashposter-get-linkedin-org-urn</code></p>';
}
