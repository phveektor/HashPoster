<?php
/**
 * HashPoster Unit Tests
 *
 * Comprehensive test suite for HashPoster plugin
 * Uses WordPress testing framework
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include plugin files for testing
require_once dirname(__FILE__) . '/../hashposter.php';

/**
 * Simple test framework for WordPress plugins
 */
class HashPoster_Test_Framework {

    private $tests_run = 0;
    private $tests_passed = 0;
    private $tests_failed = 0;
    private $failures = array();

    /**
     * Run all tests
     */
    public function run_tests() {
        echo "<h2>HashPoster Plugin Test Suite</h2>";
        echo "<pre>";

        $this->test_validation_class();
        $this->test_api_handler();
        $this->test_post_publisher();
        $this->test_integration();

        echo "\n\n";
        echo "Tests Run: {$this->tests_run}\n";
        echo "Tests Passed: {$this->tests_passed}\n";
        echo "Tests Failed: {$this->tests_failed}\n";

        if (!empty($this->failures)) {
            echo "\nFailures:\n";
            foreach ($this->failures as $failure) {
                echo "- {$failure}\n";
            }
        }

        echo "</pre>";
    }

    /**
     * Assert equals
     */
    private function assert_equals($expected, $actual, $message = '') {
        $this->tests_run++;
        if ($expected === $actual) {
            $this->tests_passed++;
            echo "✓ PASS: {$message}\n";
        } else {
            $this->tests_failed++;
            $failure = "FAIL: {$message} (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")";
            $this->failures[] = $failure;
            echo "✗ {$failure}\n";
        }
    }

    /**
     * Assert true
     */
    private function assert_true($condition, $message = '') {
        $this->tests_run++;
        if ($condition) {
            $this->tests_passed++;
            echo "✓ PASS: {$message}\n";
        } else {
            $this->tests_failed++;
            $failure = "FAIL: {$message}";
            $this->failures[] = $failure;
            echo "✗ {$failure}\n";
        }
    }

    /**
     * Assert false
     */
    private function assert_false($condition, $message = '') {
        $this->assert_true(!$condition, $message);
    }

    /**
     * Assert empty
     */
    private function assert_empty($value, $message = '') {
        $this->assert_true(empty($value), $message);
    }

    /**
     * Assert not empty
     */
    private function assert_not_empty($value, $message = '') {
        $this->assert_true(!empty($value), $message);
    }

    /**
     * Assert string contains
     */
    private function assert_string_contains($haystack, $needle, $message = '') {
        $this->assert_true(strpos($haystack, $needle) !== false, $message);
    }

    /**
     * Assert string not contains
     */
    private function assert_string_not_contains($haystack, $needle, $message = '') {
        $this->assert_true(strpos($haystack, $needle) === false, $message);
    }

    /**
     * Assert array has key
     */
    private function assert_array_has_key($key, $array, $message = '') {
        $this->assert_true(array_key_exists($key, $array), $message);
    }

    /**
     * Assert array not has key
     */
    private function assert_array_not_has_key($key, $array, $message = '') {
        $this->assert_true(!array_key_exists($key, $array), $message);
    }

    /**
     * Test validation class
     */
    private function test_validation_class() {
        echo "\n=== Testing HashPoster_Validation Class ===\n";

        // Test API key validation
        $valid_key = 'sk_test_12345678901234567890123456789012';
        $this->assert_equals($valid_key, HashPoster_Validation::sanitize_api_key($valid_key), 'Valid API key should be returned unchanged');

        $invalid_key = 'sk_test_123@#$%^&*()';
        $this->assert_empty(HashPoster_Validation::sanitize_api_key($invalid_key), 'Invalid API key should be sanitized to empty');

        $this->assert_empty(HashPoster_Validation::sanitize_api_key(''), 'Empty API key should remain empty');
        $this->assert_empty(HashPoster_Validation::sanitize_api_key(null), 'Null API key should be sanitized to empty');

        // Test URL validation
        $this->assert_equals('https://example.com', HashPoster_Validation::validate_url('https://example.com'), 'Valid HTTPS URL should be returned');
        $this->assert_equals('http://example.com', HashPoster_Validation::validate_url('http://example.com'), 'Valid HTTP URL should be returned');

        $this->assert_false(HashPoster_Validation::validate_url('not-a-url'), 'Invalid URL should return false');
        $this->assert_false(HashPoster_Validation::validate_url('ftp://example.com'), 'FTP URL should return false');
        $this->assert_false(HashPoster_Validation::validate_url(''), 'Empty URL should return false');

        // Test email validation
        $this->assert_equals('test@example.com', HashPoster_Validation::validate_email('test@example.com'), 'Valid email should be returned');
        $this->assert_false(HashPoster_Validation::validate_email('not-an-email'), 'Invalid email should return false');
        $this->assert_false(HashPoster_Validation::validate_email('test@'), 'Incomplete email should return false');

        // Test platform validation
        $valid_platforms = array('x', 'facebook', 'linkedin', 'bluesky', 'reddit', 'bitly', 'wordpress');
        foreach ($valid_platforms as $platform) {
            $this->assert_equals($platform, HashPoster_Validation::validate_platform($platform), "Valid platform '{$platform}' should be returned");
        }

        $this->assert_false(HashPoster_Validation::validate_platform('invalid_platform'), 'Invalid platform should return false');

        // Test LinkedIn URN validation
        $this->assert_equals('urn:li:person:123456789', HashPoster_Validation::sanitize_urn('urn:li:person:123456789'), 'Valid URN should be returned');
        $this->assert_empty(HashPoster_Validation::sanitize_urn('not-a-urn'), 'Invalid URN should be sanitized to empty');

        // Test post content validation
        $content = 'This is a test post content.';
        $this->assert_equals($content, HashPoster_Validation::validate_post_content($content), 'Valid content should be returned unchanged');

        $content_with_script = 'This has <script>alert("xss")</script> dangerous content.';
        $this->assert_string_not_contains(HashPoster_Validation::validate_post_content($content_with_script), '<script>', 'Script tags should be removed from content');

        // Test long content truncation
        $long_content = str_repeat('a', 10000);
        $truncated = HashPoster_Validation::validate_post_content($long_content, 100);
        $this->assert_equals(103, strlen($truncated), 'Long content should be truncated to specified length plus ellipsis');
        $this->assert_string_contains($truncated, '...', 'Truncated content should end with ellipsis');

        // Test settings validation
        $settings = array(
            'enabled' => '1',
            'logging' => '1',
            'delay_minutes' => '30',
            'invalid_field' => 'should_be_removed'
        );

        $result = HashPoster_Validation::validate_settings($settings);
        $this->assert_equals(1, $result['enabled'], 'Enabled setting should be validated');
        $this->assert_equals(1, $result['logging'], 'Logging setting should be validated');
        $this->assert_equals(30, $result['delay_minutes'], 'Delay minutes should be validated');
        $this->assert_array_not_has_key('invalid_field', $result, 'Invalid fields should be removed from settings');
    }

    /**
     * Test API handler
     */
    private function test_api_handler() {
        echo "\n=== Testing HashPoster_API_Handler Class ===\n";

        $api_handler = new HashPoster_API_Handler();

        // Test platform validation
        $this->assert_true(method_exists($api_handler, 'validate_credentials'), 'API handler should have validate_credentials method');
        $this->assert_true(method_exists($api_handler, 'publish_to_platform'), 'API handler should have publish_to_platform method');

        // Test platform methods exist
        $platforms = array('x', 'facebook', 'linkedin', 'bluesky', 'reddit');
        foreach ($platforms as $platform) {
            $method_name = 'publish_to_' . $platform;
            $this->assert_true(method_exists($api_handler, $method_name), "API handler should have {$method_name} method");
        }
    }

    /**
     * Test post publisher
     */
    private function test_post_publisher() {
        echo "\n=== Testing HashPoster_Post_Publisher Class ===\n";

        $post_publisher = new HashPoster_Post_Publisher();

        // Test required methods exist
        $this->assert_true(method_exists($post_publisher, 'format_content_for_platform'), 'Post publisher should have format_content_for_platform method');
        $this->assert_true(method_exists($post_publisher, 'publish_post'), 'Post publisher should have publish_post method');

        // Test content formatting
        $test_content = 'Test content {title} {url}';
        $formatted = $post_publisher->format_content_for_platform('x', 1, null);
        $this->assert_not_empty($formatted, 'Content should be formatted for X platform');

        $formatted_fb = $post_publisher->format_content_for_platform('facebook', 1, null);
        $this->assert_not_empty($formatted_fb, 'Content should be formatted for Facebook platform');
    }

    /**
     * Test integration
     */
    private function test_integration() {
        echo "\n=== Testing Plugin Integration ===\n";

        // Test that main plugin class exists
        $this->assert_true(class_exists('HashPoster'), 'HashPoster main class should exist');

        // Test that required classes are loaded
        $required_classes = array(
            'HashPoster_API_Handler',
            'HashPoster_Settings',
            'HashPoster_Post_Publisher',
            'HashPoster_Validation'
        );

        foreach ($required_classes as $class) {
            $this->assert_true(class_exists($class), "{$class} should be loaded");
        }

        // Test plugin activation hook
        $this->assert_true(has_action('plugins_loaded', 'HashPoster::init'), 'Plugin should have activation hook');

        // Test admin hooks
        $this->assert_true(has_action('admin_menu', array('HashPoster_Settings', 'add_admin_menu')), 'Settings should have admin menu hook');
        $this->assert_true(has_action('admin_init', array('HashPoster_Settings', 'register_settings')), 'Settings should have admin init hook');
    }
}

/**
 * Run tests when this file is accessed directly
 */
function hashposter_run_tests() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    $test_framework = new HashPoster_Test_Framework();
    $test_framework->run_tests();
}

// Add admin page for running tests
add_action('admin_menu', 'hashposter_add_test_page');
function hashposter_add_test_page() {
    add_submenu_page(
        'hashposter-settings',
        'HashPoster Tests',
        'Run Tests',
        'manage_options',
        'hashposter-tests',
        'hashposter_test_page'
    );
}

function hashposter_test_page() {
    echo '<div class="wrap">';
    echo '<h1>HashPoster Test Suite</h1>';
    echo '<p>Click the button below to run the comprehensive test suite for the HashPoster plugin.</p>';

    if (isset($_POST['run_tests'])) {
        hashposter_run_tests();
    } else {
        echo '<form method="post">';
        echo '<input type="submit" name="run_tests" class="button button-primary" value="Run Test Suite">';
        echo '</form>';
    }

    echo '</div>';
}

// Allow direct access for testing
if (isset($_GET['run_tests']) && current_user_can('manage_options')) {
    hashposter_run_tests();
}