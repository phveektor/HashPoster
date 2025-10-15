<?php
/**
 * Simple test script for HashPoster OAuth Handler
 * Run this from WordPress admin to test OAuth functionality
 */

// Include WordPress
require_once( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php' );

if ( ! defined( 'ABSPATH' ) ) {
    die( 'WordPress not loaded' );
}

echo "<h1>HashPoster OAuth Handler Test</h1>";

// Test OAuth handler instantiation
try {
    $oauth_handler = new HashPoster_OAuth_Handler();
    echo "<p style='color: green;'>✓ OAuth Handler instantiated successfully</p>";
} catch ( Exception $e ) {
    echo "<p style='color: red;'>✗ Failed to instantiate OAuth Handler: " . $e->getMessage() . "</p>";
    exit;
}

// Test basic methods
$platforms = array( 'x', 'linkedin', 'bluesky', 'facebook' );
foreach ( $platforms as $platform ) {
    $connected = $oauth_handler->is_connected( $platform );
    $status = $connected ? 'Connected' : 'Not Connected';
    $color = $connected ? 'green' : 'orange';
    echo "<p style='color: $color;'>$platform: $status</p>";
}

// Test token storage/retrieval
echo "<h2>Token Storage Test</h2>";
$test_tokens = array(
    'access_token' => 'test_token_' . time(),
    'expires_at' => time() + 3600
);

// Store test tokens
$reflection = new ReflectionClass( $oauth_handler );
$store_method = $reflection->getMethod( 'store_tokens' );
$store_method->setAccessible( true );
$store_method->invoke( $oauth_handler, 'test_platform', $test_tokens );

echo "<p>Stored test tokens for 'test_platform'</p>";

// Retrieve tokens
$retrieved = $oauth_handler->get_tokens( 'test_platform' );
if ( !empty( $retrieved['access_token'] ) && $retrieved['access_token'] === $test_tokens['access_token'] ) {
    echo "<p style='color: green;'>✓ Token storage and retrieval working</p>";
} else {
    echo "<p style='color: red;'>✗ Token storage/retrieval failed</p>";
}

// Clean up test tokens
$oauth_handler->disconnect( 'test_platform' );
echo "<p>Cleaned up test tokens</p>";

echo "<h2>Test Complete</h2>";
echo "<p>If all items above are green, the OAuth system is working correctly.</p>";
?>