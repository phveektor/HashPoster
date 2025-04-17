<?php
/*
Plugin Name: HashPoster
Description: Lightweight autoposter to publish WordPress content to selected social media platforms with short link support.
Version: 1.0
Author: Phveektor
Author URI: https://github.com/phveektor
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'HASHPOSTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'HASHPOSTER_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes
require_once HASHPOSTER_PATH . 'includes/class-api-handler.php';
require_once HASHPOSTER_PATH . 'includes/class-settings.php';
require_once HASHPOSTER_PATH . 'includes/class-post-publisher.php';

class HashPoster {
    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'plugins_loaded', array( $this, 'init' ) );
        add_action( 'admin_notices', array( $this, 'how_to_configure_notice' ) );
        add_action( 'wp_head', array( $this, 'add_social_meta_tags' ), 5 );
    }

    public function activate() {
        // ...activation logic (e.g., default options)...
    }

    public function deactivate() {
        // ...deactivation logic (e.g., cleanup)...
    }

    public function init() {
        // Initialize settings page
        new HashPoster_Settings();

        // Initialize post publisher
        new HashPoster_Post_Publisher();

        // Enqueue admin assets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function enqueue_admin_assets() {
        wp_enqueue_style( 'hashposter-admin', HASHPOSTER_URL . 'assets/css/admin.css', array(), '1.0' );
        wp_enqueue_script( 'hashposter-admin', HASHPOSTER_URL . 'assets/js/admin.js', array('jquery'), '1.0', true );
        wp_localize_script( 'hashposter-admin', 'hashposterAdmin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'hashposter_admin' ),
        ) );
    }

    public function how_to_configure_notice() {
        if ( isset($_GET['page']) && $_GET['page'] === 'hashposter-settings' ) {
            echo '<div class="notice notice-info is-dismissible"><p>
                <strong>HashPoster:</strong> Need help configuring? See the <a href="https://github.com/phveektor/hashposter#readme" target="_blank">setup guide</a> for step-by-step instructions on connecting your social accounts and using API keys.<br>
                <strong>Tip:</strong> To get your LinkedIn Organization URN, use the <a href="' . admin_url('admin.php?page=hashposter-get-linkedin-org-urn') . '" target="_blank">LinkedIn URN Helper</a> page in your dashboard.
            </p></div>';
        }
    }

    // Add Open Graph and Twitter Card meta tags for better social previews
    public function add_social_meta_tags() {
        if ( is_singular() ) {
            global $post;
            setup_postdata($post);
            $title = esc_attr(get_the_title($post));
            $desc = esc_attr(get_the_excerpt($post));
            $url = esc_url(get_permalink($post));
            $site_name = esc_attr(get_bloginfo('name'));
            $image = '';
            if (has_post_thumbnail($post)) {
                $img = wp_get_attachment_image_src(get_post_thumbnail_id($post), 'large');
                $image = esc_url($img[0]);
            }
            // Twitter Card type
            $twitter_card = $image ? 'summary_large_image' : 'summary';
            ?>
            <!-- HashPoster: Open Graph & Twitter Card -->
            <meta property="og:title" content="<?php echo $title; ?>" />
            <meta property="og:description" content="<?php echo $desc; ?>" />
            <meta property="og:url" content="<?php echo $url; ?>" />
            <meta property="og:site_name" content="<?php echo $site_name; ?>" />
            <?php if ($image): ?>
            <meta property="og:image" content="<?php echo $image; ?>" />
            <?php endif; ?>
            <meta name="twitter:card" content="<?php echo $twitter_card; ?>" />
            <meta name="twitter:title" content="<?php echo $title; ?>" />
            <meta name="twitter:description" content="<?php echo $desc; ?>" />
            <meta name="twitter:url" content="<?php echo $url; ?>" />
            <?php if ($image): ?>
            <meta name="twitter:image" content="<?php echo $image; ?>" />
            <?php endif; ?>
            <!-- /HashPoster -->
            <?php
            wp_reset_postdata();
        }
    }
}

new HashPoster();
