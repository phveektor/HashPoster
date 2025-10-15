<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HashPoster Bulk Posting
 *
 * Provides bulk posting functionality for multip                        <p>
                            <label>
                                <input type="checkbox" id="hashposter-skip-featured-image" />
                                <?php _e( 'Skip featured images (text only)', 'hashposter' ); ?>
                            </label>
                        </p>
                        
                        <p>
                            <label>
                                <input type="checkbox" id="hashposter-prefer-url-cards" />
                                <?php _e( 'Prefer URL cards over uploaded images', 'hashposter' ); ?>
                            </label>
                            <small style="display: block; color: #666; margin-top: 5px;">
                                <?php _e( 'URL cards show website preview with title, description, and meta image from the target page. <strong>Default for X/Twitter, Bluesky, and LinkedIn.</strong> Supported by X/Twitter, LinkedIn, Bluesky, and Facebook.', 'hashposter' ); ?>
                            </small>
                        </p>
 */
class HashPoster_Bulk_Posting {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_bulk_posting_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_bulk_posting_assets' ) );
        add_action( 'wp_ajax_hashposter_bulk_publish', array( $this, 'handle_bulk_publish' ) );
        add_action( 'wp_ajax_hashposter_get_posts_for_bulk', array( $this, 'get_posts_for_bulk' ) );
        add_filter( 'bulk_actions-edit-post', array( $this, 'add_bulk_social_actions' ) );
        add_filter( 'handle_bulk_actions-edit-post', array( $this, 'handle_bulk_social_actions' ), 10, 3 );
    }

    /**
     * Add bulk posting menu
     */
    public function add_bulk_posting_menu() {
        add_submenu_page(
            'hashposter-settings',
            'Bulk Posting',
            'Bulk Posting',
            'publish_posts',
            'hashposter-bulk-posting',
            array( $this, 'render_bulk_posting_page' )
        );
    }

    /**
     * Render bulk posting page
     */
    public function render_bulk_posting_page() {
        if ( ! current_user_can( 'publish_posts' ) ) {
            wp_die( 'You do not have sufficient permissions to access this page.' );
        }

        // Get available platforms
        $available_platforms = $this->get_available_platforms();

        ?>
        <div class="wrap">
            <h1><?php _e( 'HashPoster - Bulk Social Media Posting', 'hashposter' ); ?></h1>

            <div class="hashposter-bulk-container">
                <div class="hashposter-bulk-sidebar">
                    <h3><?php _e( 'Select Posts', 'hashposter' ); ?></h3>

                    <div class="hashposter-filters">
                        <select id="hashposter-post-type" class="widefat">
                            <option value="post"><?php _e( 'Posts', 'hashposter' ); ?></option>
                            <option value="page"><?php _e( 'Pages', 'hashposter' ); ?></option>
                            <?php
                            $custom_post_types = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
                            foreach ( $custom_post_types as $cpt ) {
                                echo '<option value="' . esc_attr( $cpt->name ) . '">' . esc_html( $cpt->label ) . '</option>';
                            }
                            ?>
                        </select>

                        <select id="hashposter-post-status" class="widefat">
                            <option value="publish"><?php _e( 'Published', 'hashposter' ); ?></option>
                            <option value="draft"><?php _e( 'Drafts', 'hashposter' ); ?></option>
                            <option value="pending"><?php _e( 'Pending Review', 'hashposter' ); ?></option>
                        </select>

                        <input type="text" id="hashposter-search" class="widefat" placeholder="<?php _e( 'Search posts...', 'hashposter' ); ?>" />

                        <select id="hashposter-category" class="widefat">
                            <option value=""><?php _e( 'All Categories', 'hashposter' ); ?></option>
                            <?php
                            $categories = get_categories( array( 'hide_empty' => false ) );
                            foreach ( $categories as $category ) {
                                echo '<option value="' . esc_attr( $category->term_id ) . '">' . esc_html( $category->name ) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="hashposter-post-selection">
                        <label class="hashposter-select-all-label">
                            <input type="checkbox" id="hashposter-select-all" />
                            <?php _e( 'Select All', 'hashposter' ); ?>
                        </label>
                        <span id="hashposter-selection-count" class="hashposter-selection-count">0 posts selected</span>
                    </div>

                    <div id="hashposter-posts-list" class="hashposter-posts-list">
                        <p><?php _e( 'Loading posts...', 'hashposter' ); ?></p>
                    </div>
                </div>

                <div class="hashposter-bulk-main">
                    <h3><?php _e( 'Social Media Settings', 'hashposter' ); ?></h3>

                    <div class="hashposter-platforms-selection">
                        <h4><?php _e( 'Select Platforms', 'hashposter' ); ?></h4>
                        <?php foreach ( $available_platforms as $platform => $label ) : ?>
                            <label class="hashposter-platform-checkbox">
                                <input type="checkbox" name="platforms[]" value="<?php echo esc_attr( $platform ); ?>" />
                                <span class="platform-icon platform-<?php echo esc_attr( $platform ); ?>"></span>
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="hashposter-content-settings">
                        <h4><?php _e( 'Content Settings', 'hashposter' ); ?></h4>

                        <p>
                            <label>
                                <input type="checkbox" id="hashposter-use-custom-content" />
                                <?php _e( 'Use custom content for all posts', 'hashposter' ); ?>
                            </label>
                        </p>

                        <div id="hashposter-custom-content-section" style="display: none;">
                            <textarea id="hashposter-custom-content" rows="4" class="widefat"
                                placeholder="<?php _e( 'Enter custom content. Use {title}, {url}, {excerpt} as placeholders.', 'hashposter' ); ?>"></textarea>
                        </div>

                        <p>
                            <label>
                                <input type="checkbox" id="hashposter-skip-featured-image" />
                                <?php _e( 'Skip featured images in social posts', 'hashposter' ); ?>
                            </label>
                        </p>

                        <p>
                            <label>
                                <input type="checkbox" id="hashposter-prefer-url-cards" />
                                <?php _e( 'Prefer URL cards over uploaded images', 'hashposter' ); ?>
                            </label>
                            <small style="display: block; color: #666; margin-top: 5px;">
                                <?php _e( 'URL cards show website preview with title, description, and meta image from the target page. <strong>Default for X/Twitter, Bluesky, and LinkedIn.</strong> Supported by X/Twitter, LinkedIn, Bluesky, and Facebook.', 'hashposter' ); ?>
                            </small>
                        </p>
                    </div>

                    <div class="hashposter-scheduling">
                        <h4><?php _e( 'Scheduling', 'hashposter' ); ?></h4>

                        <p>
                            <label>
                                <input type="radio" name="posting_schedule" value="immediate" checked />
                                <?php _e( 'Post immediately', 'hashposter' ); ?>
                            </label>
                        </p>

                        <p>
                            <label>
                                <input type="radio" name="posting_schedule" value="staggered" />
                                <?php _e( 'Stagger posts (one every few minutes)', 'hashposter' ); ?>
                            </label>
                            <input type="number" id="hashposter-stagger-minutes" min="1" max="60" value="5" style="width: 60px; margin-left: 10px;" />
                            <?php _e( 'minutes', 'hashposter' ); ?>
                        </p>
                    </div>

                    <div class="hashposter-bulk-actions">
                        <button type="button" id="hashposter-preview-bulk" class="button button-secondary">
                            <?php _e( 'Preview Posts', 'hashposter' ); ?>
                        </button>
                        <button type="button" id="hashposter-publish-bulk" class="button button-primary">
                            <?php _e( 'Publish to Social Media', 'hashposter' ); ?>
                        </button>
                    </div>

                    <div id="hashposter-bulk-progress" class="hashposter-progress" style="display: none;">
                        <div class="hashposter-progress-bar">
                            <div class="hashposter-progress-fill"></div>
                        </div>
                        <div class="hashposter-progress-text">
                            <?php _e( 'Publishing posts...', 'hashposter' ); ?>
                        </div>
                        <div id="hashposter-progress-details"></div>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .hashposter-bulk-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }

        .hashposter-bulk-sidebar {
            flex: 0 0 300px;
            background: #fff;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 5px;
        }

        .hashposter-bulk-main {
            flex: 1;
            background: #fff;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 5px;
        }

        .hashposter-filters select,
        .hashposter-filters input {
            margin-bottom: 10px;
        }

        .hashposter-post-selection {
            padding: 10px;
            background: #f5f5f5;
            border: 1px solid #ddd;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .hashposter-posts-header {
            padding: 8px;
            background: #f0f0f0;
            border: 1px solid #ddd;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
        }

        .hashposter-posts-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            margin-top: 10px;
        }

        .hashposter-post-item {
            display: flex;
            align-items: center;
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
        }

        .hashposter-post-item:last-child {
            border-bottom: none;
        }

        .hashposter-platforms-selection {
            margin-bottom: 30px;
        }

        .hashposter-platform-checkbox {
            display: block;
            margin-bottom: 10px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }

        .hashposter-platform-checkbox:hover {
            background: #f9f9f9;
        }

        .hashposter-content-settings small {
            font-style: italic;
            line-height: 1.4;
        }

        .hashposter-progress {
            margin-top: 20px;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 5px;
        }

        .hashposter-progress-bar {
            width: 100%;
            height: 20px;
            background: #ddd;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .hashposter-progress-fill {
            height: 100%;
            background: #007cba;
            width: 0%;
            transition: width 0.3s ease;
        }
        </style>
        <?php
    }

    /**
     * Enqueue bulk posting assets
     */
    public function enqueue_bulk_posting_assets( $hook ) {
        if ( $hook !== 'hashposter_page_hashposter-bulk-posting' ) {
            return;
        }

        wp_enqueue_script(
            'hashposter-bulk-posting',
            HASHPOSTER_URL . 'assets/js/bulk-posting.js',
            array(), // Remove jQuery dependency for WordPress compatibility
            '1.2', // Increment version to force cache refresh
            true
        );

        wp_localize_script( 'hashposter-bulk-posting', 'hashposterBulkPosting', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'hashposter_bulk_posting' ),
            'strings' => array(
                'loading' => __( 'Loading posts...', 'hashposter' ),
                'no_posts' => __( 'No posts found.', 'hashposter' ),
                'select_posts' => __( 'Please select at least one post.', 'hashposter' ),
                'select_platforms' => __( 'Please select at least one platform.', 'hashposter' ),
                'confirm_publish' => __( 'Are you sure you want to publish %d posts to social media?', 'hashposter' ),
                'publishing' => __( 'Publishing posts...', 'hashposter' ),
                'completed' => __( 'Completed!', 'hashposter' ),
                'error' => __( 'Error:', 'hashposter' )
            )
        ) );
    }

    /**
     * Get posts for bulk selection
     */
    public function get_posts_for_bulk() {
        check_ajax_referer( 'hashposter_bulk_posting', 'nonce' );

        if ( ! current_user_can( 'publish_posts' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $post_type = sanitize_text_field( $_POST['post_type'] ?? 'post' );
        $post_status = sanitize_text_field( $_POST['post_status'] ?? 'publish' );
        $search = sanitize_text_field( $_POST['search'] ?? '' );
        $category = intval( $_POST['category'] ?? 0 );

        $args = array(
            'post_type' => $post_type,
            'post_status' => $post_status,
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        if ( ! empty( $search ) ) {
            $args['s'] = $search;
        }

        if ( $category > 0 ) {
            $args['cat'] = $category;
        }

        $posts = get_posts( $args );

        $html = '';
        if ( ! empty( $posts ) ) {
            $html .= '<div class="hashposter-posts-header" style="padding: 10px; background: #f0f0f0; border: 1px solid #ddd; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">';
            $html .= '<label style="font-weight: normal;"><input type="checkbox" id="hashposter-select-all-posts" style="margin-right: 5px;" /> Select All</label>';
            $html .= '<span class="hashposter-posts-count" style="font-weight: bold;">' . count( $posts ) . ' posts found</span>';
            $html .= '</div>';
            $html .= '<div class="hashposter-posts-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 5px;">';
        }
        
        foreach ( $posts as $post ) {
            $html .= '<div class="hashposter-post-item" style="padding: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 10px; background: #fff; margin-bottom: 2px;">';
            $html .= '<input type="checkbox" class="hashposter-post-checkbox" value="' . esc_attr( $post->ID ) . '" style="margin-right: 10px; transform: scale(1.2);" />';
            $html .= '<div class="hashposter-post-info" style="flex: 1;">';
            $html .= '<strong style="display: block; margin-bottom: 4px; color: #333;">' . esc_html( $post->post_title ) . '</strong>';
            $html .= '<small style="color: #666;">' . esc_html( get_the_date( 'M j, Y g:i A', $post ) ) . '</small>';
            $html .= '</div>';
            $html .= '</div>';
        }

        if ( ! empty( $posts ) ) {
            $html .= '</div>';
        }

        if ( empty( $posts ) ) {
            $html = '<p style="text-align: center; padding: 20px; color: #666;">' . __( 'No posts found.', 'hashposter' ) . '</p>';
        }

        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * Handle bulk publish AJAX request
     */
    public function handle_bulk_publish() {
        error_log( 'HashPoster: handle_bulk_publish called' );
        
        // Check nonce
        if ( ! check_ajax_referer( 'hashposter_bulk_posting', 'nonce', false ) ) {
            error_log( 'HashPoster: Nonce verification failed' );
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        if ( ! current_user_can( 'publish_posts' ) ) {
            error_log( 'HashPoster: User lacks permission' );
            wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
        }

        $post_ids = array_map( 'intval', $_POST['post_ids'] ?? array() );
        $platforms = array_map( 'sanitize_text_field', $_POST['platforms'] ?? array() );
        $custom_content = sanitize_textarea_field( $_POST['custom_content'] ?? '' );
        $skip_featured_image = ! empty( $_POST['skip_featured_image'] ) && $_POST['skip_featured_image'] !== '0';
        $prefer_url_cards = ! empty( $_POST['prefer_url_cards'] ) && $_POST['prefer_url_cards'] !== '0';
        $stagger_minutes = intval( $_POST['stagger_minutes'] ?? 0 );

        // Debug logging for parameter extraction
        error_log( 'HashPoster: Raw POST data - skip_featured_image: ' . (isset($_POST['skip_featured_image']) ? $_POST['skip_featured_image'] : 'not set') );
        error_log( 'HashPoster: Raw POST data - prefer_url_cards: ' . (isset($_POST['prefer_url_cards']) ? $_POST['prefer_url_cards'] : 'not set') );
        error_log( 'HashPoster: Processed - skip_featured_image: ' . ($skip_featured_image ? 'true' : 'false') . ', prefer_url_cards: ' . ($prefer_url_cards ? 'true' : 'false') );

        error_log( 'HashPoster: Request data - Posts: ' . count( $post_ids ) . ', Platforms: ' . count( $platforms ) );

        if ( empty( $post_ids ) || empty( $platforms ) ) {
            error_log( 'HashPoster: Missing required parameters' );
            wp_send_json_error( array( 'message' => 'Missing required parameters' ) );
        }

        $results = array();
        $delay = 0;

        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                $results[] = array(
                    'post_id' => $post_id,
                    'success' => false,
                    'message' => 'Post not found'
                );
                continue;
            }

            // Schedule or publish immediately
            if ( $stagger_minutes > 0 ) {
                wp_schedule_single_event(
                    time() + ( $delay * 60 ),
                    'hashposter_bulk_publish_single',
                    array( $post_id, $platforms, $custom_content )
                );
                $results[] = array(
                    'post_id' => $post_id,
                    'success' => true,
                    'message' => 'Scheduled for ' . date( 'H:i', time() + ( $delay * 60 ) )
                );
                $delay += $stagger_minutes;
            } else {
                $result = $this->publish_single_post( $post_id, $platforms, $custom_content );
                $results[] = $result;
            }
        }

        error_log( 'HashPoster: Sending success response with ' . count( $results ) . ' results' );
        wp_send_json_success( array( 'results' => $results ) );
    }

    /**
     * Publish single post to platforms
     */
    private function publish_single_post( $post_id, $platforms, $custom_content ) {
        // Normalize and dedupe requested platforms
        $platforms = array_map('strtolower', (array) $platforms);
        $platforms = array_unique($platforms);

        // Validate platforms against available credentials and supported list
        $api_handler = new HashPoster_API_Handler();
        $valid_platforms = array();
        foreach ( $platforms as $platform ) {
            if ( $api_handler->validate_credentials( $platform ) ) {
                $valid_platforms[] = $platform;
            } else {
                error_log( '[HashPoster] Skipping platform due to missing credentials or unsupported: ' . $platform );
            }
        }
        
        // If Bluesky credentials exist and not requested explicitly, optionally enable it
        $api_credentials = get_option( 'hashposter_api_credentials', array() );
        if ( ! in_array( 'bluesky', $valid_platforms ) && ! empty( $api_credentials['bluesky']['handle'] ) && ! empty( $api_credentials['bluesky']['app_password'] ) ) {
            $valid_platforms[] = 'bluesky';
            error_log( '[HashPoster] Auto-enabled Bluesky posting (credentials configured)' );
        }

        $platforms = $valid_platforms;
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array( 'post_id' => $post_id, 'success' => false, 'message' => 'Post not found' );
        }

        // Ensure the publisher service class is loaded (some installs may not autoload includes)
        if ( ! class_exists( 'HashPoster_Publisher' ) ) {
            require_once dirname( __FILE__ ) . '/class-publisher.php';
        }
        $publisher = new HashPoster_Publisher();
        $publish_result = $publisher->publish_post_to_platforms( $post, $platforms, $custom_content );

        return array(
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'success' => $publish_result['success'],
            'platforms' => $publish_result['platforms']
        );
    }

    /**
     * NOTE: Pipeline and platform-specific helpers were consolidated into
     * `includes/class-content-pipeline.php` and the `HashPoster_Publisher` service.
     * Keeping this file focused on orchestration and delegating content/media
     * preparation to the shared pipeline avoids duplication and inconsistencies.
     */
    private function prepare_linkedin_content( $content, $media, $skip_featured_image, $prefer_url_cards ) {
        if ( $prefer_url_cards && ! empty( $media['url'] ) ) {
            // LinkedIn uses ARTICLE content type for URL shares
            $media['content_type'] = 'ARTICLE';

            if ( strpos( $content, $media['url'] ) === false ) {
                $content .= ' ' . $media['url'];
            }

            error_log( '[HashPoster LinkedIn] ARTICLE content type set for URL sharing' );
        }

        return array( 'content' => $content, 'media' => $media );
    }

    /**
     * Prepare content specifically for Facebook
     */
    private function prepare_facebook_content( $content, $media, $skip_featured_image, $prefer_url_cards ) {
        if ( $prefer_url_cards && ! empty( $media['url'] ) ) {
            // Facebook uses link shares
            $media['link_share'] = true;

            if ( strpos( $content, $media['url'] ) === false ) {
                $content .= ' ' . $media['url'];
            }

            error_log( '[HashPoster Facebook] Link share enabled' );
        }

        return array( 'content' => $content, 'media' => $media );
    }

    /**
     * Replace placeholders in content
     */
    private function replace_placeholders( $content, $post ) {
        $replacements = array(
            '{title}' => $post->post_title,
            '{url}' => get_permalink( $post ),
            '{excerpt}' => get_the_excerpt( $post ),
            '{date}' => get_the_date( '', $post ),
            '{author}' => get_the_author_meta( 'display_name', $post->post_author )
        );

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $content );
    }

    /**
     * Add bulk social actions to posts list
     */
    public function add_bulk_social_actions( $bulk_actions ) {
        $bulk_actions['hashposter_publish_x'] = __( 'Publish to X (Twitter)', 'hashposter' );
        $bulk_actions['hashposter_publish_facebook'] = __( 'Publish to Facebook', 'hashposter' );
        $bulk_actions['hashposter_publish_linkedin'] = __( 'Publish to LinkedIn', 'hashposter' );

        return $bulk_actions;
    }

    /**
     * Handle bulk social actions
     */
    public function handle_bulk_social_actions( $redirect_to, $doaction, $post_ids ) {
        if ( strpos( $doaction, 'hashposter_publish_' ) !== 0 ) {
            return $redirect_to;
        }

        $platform = str_replace( 'hashposter_publish_', '', $doaction );
        $processed = 0;

        $publisher = new HashPoster_Publisher();
        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) continue;

            $res = $publisher->publish_post_to_platforms( $post, array( $platform ) );
            if ( ! empty( $res['success'] ) ) {
                $processed++;
            }
        }

        $redirect_to = add_query_arg( 'hashposter_bulk_published', $processed, $redirect_to );
        return $redirect_to;
    }

    /**
     * Get available platforms
     */
    private function get_available_platforms() {
        return array(
            'x' => 'X (Twitter)',
            'facebook' => 'Facebook',
            'linkedin' => 'LinkedIn',
            'bluesky' => 'Bluesky',
            // 'pinterest' => 'Pinterest' (removed),
            'wordpress' => 'WordPress'
        );
    }
}