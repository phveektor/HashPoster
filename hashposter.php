<?php
/*
Plugin Name: HashPoster
Description: Lightweight autoposter to publish WordPress content to selected social media platforms with short link support.
Version: 1.1
Author: Phveektor
Author URI: https://github.com/phveektor
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Ensure WordPress functions are available
if ( ! function_exists( 'plugin_dir_path' ) || ! function_exists( 'plugin_dir_url' ) ) {
    return;
}

define( 'HASHPOSTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'HASHPOSTER_URL', plugin_dir_url( __FILE__ ) );
define( 'HASHPOSTER_VERSION', '1.1' );

// GitHub Updater via Plugin Update Checker
if ( file_exists( HASHPOSTER_PATH . 'includes/plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once HASHPOSTER_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';
    $hashposter_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/phveektor/HashPoster/',
        __FILE__,
        'HashPoster'
    );
    $hashposter_update_checker->setBranch( 'main' );
}

// Autoload classes
require_once HASHPOSTER_PATH . 'includes/class-api-handler.php';
require_once HASHPOSTER_PATH . 'includes/class-oauth-handler.php';
require_once HASHPOSTER_PATH . 'includes/class-settings.php';
require_once HASHPOSTER_PATH . 'includes/class-post-publisher.php';
require_once HASHPOSTER_PATH . 'includes/class-bulk-posting.php';
require_once HASHPOSTER_PATH . 'includes/class-publisher.php';
require_once HASHPOSTER_PATH . 'includes/class-analytics.php';

class HashPoster {
    public function __construct() {
        // Ensure WordPress hook functions are available
        if ( function_exists( 'register_activation_hook' ) ) {
            register_activation_hook( __FILE__, array( $this, 'activate' ) );
        }
        if ( function_exists( 'register_deactivation_hook' ) ) {
            register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
        }

        if ( function_exists( 'add_action' ) ) {
            add_action( 'plugins_loaded', array( $this, 'init' ) );
            add_action( 'admin_notices', array( $this, 'how_to_configure_notice' ) );
        }
    }

    public function activate() {
        // Set default settings with all platforms enabled
        $default_settings = array(
            'enabled' => '1',
            'logging' => '0',
            'scheduling' => '0',
            'delay_minutes' => '0'
        );

        $default_api_credentials = array(
            'facebook' => array('active' => '1'),
            'linkedin' => array('active' => '1'),
            'bluesky' => array('active' => '1'),
            'telegram' => array('active' => '1')
        );

        $default_post_cards = array(
            'template' => '{title} {excerpt} {url}'
        );

        $default_shortlinks = array(
            'wordpress' => array('active' => '0'), // Disabled by default for better social media sharing
            'bitly' => array('active' => '0')
        );

        // Merge defaults with existing settings to ensure new settings are added
        if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
            $existing_settings = get_option('hashposter_settings', array());
            $merged_settings = array_merge($default_settings, $existing_settings);
            update_option('hashposter_settings', $merged_settings);

            $existing_api_credentials = get_option('hashposter_api_credentials', array());
            $merged_api_credentials = array_merge($default_api_credentials, $existing_api_credentials);
            update_option('hashposter_api_credentials', $merged_api_credentials);
            
            $existing_post_cards = get_option('hashposter_post_cards', array());
            $merged_post_cards = array_merge($default_post_cards, $existing_post_cards);
            update_option('hashposter_post_cards', $merged_post_cards);

            $existing_shortlinks = get_option('hashposter_shortlinks', array());
            $merged_shortlinks = array_merge($default_shortlinks, $existing_shortlinks);
            update_option('hashposter_shortlinks', $merged_shortlinks);
        }

        // Create analytics DB table once on activation (not on every request)
        if ( class_exists( 'HashPoster_Analytics' ) ) {
            HashPoster_Analytics::create_analytics_table();
        }
    }

    public function deactivate() {
        // ...deactivation logic (e.g., cleanup)...
    }

    public function init() {
        // Initialize settings page
        new HashPoster_Settings();

        // Initialize post publisher
        new HashPoster_Post_Publisher();

        // Initialize analytics
        new HashPoster_Analytics();

    // Initialize new features
    new HashPoster_Bulk_Posting();

    // Initialize publisher (replaces separate pipeline)
    $publisher = new HashPoster_Publisher();
    if ( function_exists( 'add_action' ) ) {
        // Enqueue admin assets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }
    }

    public function enqueue_admin_assets( $hook ) {
        // Only enqueue on HashPoster admin pages
        $hashposter_pages = array(
            'toplevel_page_hashposter-settings',
            'hashposter_page_hashposter-settings'
        );
        
        if ( ! in_array( $hook, $hashposter_pages ) && strpos( $hook, 'hashposter' ) === false ) {
            return;
        }
        
        if ( function_exists( 'wp_enqueue_style' ) ) {
            $css_ver = file_exists( plugin_dir_path( __FILE__ ) . 'assets/css/admin.css' ) ? filemtime( plugin_dir_path( __FILE__ ) . 'assets/css/admin.css' ) : '2.0.0';
            wp_enqueue_style( 'hashposter-admin', HASHPOSTER_URL . 'assets/css/admin.css', array(), $css_ver );
        }
        if ( function_exists( 'wp_enqueue_script' ) ) {
            $js_ver = file_exists( plugin_dir_path( __FILE__ ) . 'assets/js/admin.js' ) ? filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/admin.js' ) : '2.0.0';
            wp_enqueue_script( 'hashposter-admin', HASHPOSTER_URL . 'assets/js/admin.js', array(), $js_ver, true );
        }

        if ( function_exists( 'wp_localize_script' ) && function_exists( 'admin_url' ) && function_exists( 'wp_create_nonce' ) ) {
            wp_localize_script( 'hashposter-admin', 'hashposterAdmin', array(
                'ajax_url'    => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'hashposter_admin' ),
                'oauth_nonce' => wp_create_nonce( 'hashposter_oauth' ),
            ) );
        }
    }

    public function how_to_configure_notice() {
        if ( isset($_GET['page']) && $_GET['page'] === 'hashposter-settings' ) {
            echo '<div class="notice notice-info is-dismissible"><p>
                <strong>HashPoster:</strong> Need help configuring? See the <a href="https://github.com/phveektor/hashposter#readme" target="_blank">setup guide</a> for step-by-step instructions on connecting your social accounts and using API keys.
            </p></div>';
        }
    }
}

new HashPoster();