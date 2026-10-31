<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HashPoster Bulk Posting
 *
 * Provides bulk social media posting across multiple platforms.
 */
class HashPoster_Bulk_Posting {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_bulk_posting_menu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_bulk_posting_assets' ) );
        add_action( 'wp_ajax_hashposter_bulk_publish', array( $this, 'handle_bulk_publish' ) );
        add_action( 'wp_ajax_hashposter_get_posts_for_bulk', array( $this, 'get_posts_for_bulk' ) );
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
        <div class="wrap hashposter-settings">
            <div class="hp-dashboard">
                
                <!-- Unified Responsive Sidebar -->
                <aside class="hp-sidebar">
                    <div class="hp-brand-header">
                        <div class="hp-brand-icon"><span class="dashicons dashicons-share"></span></div>
                        <div class="hp-brand-text">
                            <h2>HashPoster</h2>
                            <span>PRO</span>
                        </div>
                    </div>

                    <nav class="hp-nav">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=hashposter-settings' ) ); ?>" class="hp-nav-item">
                            <span class="dashicons dashicons-admin-generic"></span>
                            Settings
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=hashposter-analytics' ) ); ?>" class="hp-nav-item">
                            <span class="dashicons dashicons-chart-pie"></span>
                            Analytics
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=hashposter-bulk-posting' ) ); ?>" class="hp-nav-item active">
                            <span class="dashicons dashicons-grid-view"></span>
                            Bulk Posting
                        </a>
                    </nav>
                </aside>

                <!-- Main Content -->
                <main class="hp-main">
                    <div class="hp-topbar">
                        <div class="hp-topbar-title">
                            <h1><span class="dashicons dashicons-grid-view" style="font-size: 26px; width: 26px; height: 26px; margin-right: 8px; color: var(--hp-brand);"></span> <?php _e( 'Bulk Social Media Posting', 'hashposter' ); ?></h1>
                            <p><?php _e( 'Filter, select, and batch publish your WordPress posts to connected social channels.', 'hashposter' ); ?></p>
                        </div>
                    </div>

                    <div class="hp-tab-panel active" style="padding: 32px 36px; border:none; display:block;">
                        <div class="hp-bulk-grid">
                            
                            <!-- Left: Select Posts Column -->
                            <div class="hp-card hp-bulk-sidebar">
                                <h4 class="hp-section-title"><?php _e( 'Select Posts', 'hashposter' ); ?></h4>

                                <div class="hashposter-filters" style="display:flex; flex-direction:column; gap:10px; margin-bottom:14px;">
                                    <select id="hashposter-post-type" class="hp-select">
                                        <option value="post"><?php _e( 'Posts', 'hashposter' ); ?></option>
                                        <option value="page"><?php _e( 'Pages', 'hashposter' ); ?></option>
                                        <?php
                                        $custom_post_types = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
                                        foreach ( $custom_post_types as $cpt ) {
                                            echo '<option value="' . esc_attr( $cpt->name ) . '">' . esc_html( $cpt->label ) . '</option>';
                                        }
                                        ?>
                                    </select>

                                    <select id="hashposter-post-status" class="hp-select">
                                        <option value="publish"><?php _e( 'Published', 'hashposter' ); ?></option>
                                        <option value="draft"><?php _e( 'Drafts', 'hashposter' ); ?></option>
                                        <option value="pending"><?php _e( 'Pending Review', 'hashposter' ); ?></option>
                                    </select>

                                    <input type="text" id="hashposter-search" class="hp-input" placeholder="<?php _e( 'Search posts...', 'hashposter' ); ?>" />

                                    <select id="hashposter-category" class="hp-select">
                                        <option value=""><?php _e( 'All Categories', 'hashposter' ); ?></option>
                                        <?php
                                        $categories = get_categories( array( 'hide_empty' => false ) );
                                        foreach ( $categories as $category ) {
                                            echo '<option value="' . esc_attr( $category->term_id ) . '">' . esc_html( $category->name ) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="hp-bulk-select-all">
                                    <label class="hashposter-select-all-label" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                        <input type="checkbox" id="hashposter-select-all" />
                                        <?php _e( 'Select All', 'hashposter' ); ?>
                                    </label>
                                    <span id="hashposter-selection-count" class="hp-badge hp-badge-success" style="font-size:11px;">0 selected</span>
                                </div>

                                <div id="hashposter-posts-list" class="hashposter-posts-list">
                                    <p style="color:var(--hp-text-muted); font-size:13px; padding:12px 0; text-align:center;"><?php _e( 'Loading posts...', 'hashposter' ); ?></p>
                                </div>
                            </div>

                            <!-- Right: Social Media Settings Column -->
                            <div class="hp-card hp-bulk-main">
                                <h4 class="hp-section-title"><?php _e( 'Target Social Networks', 'hashposter' ); ?></h4>

                                <div class="hashposter-platforms-selection" style="margin-bottom:24px;">
                                    <?php foreach ( $available_platforms as $platform => $label ) : ?>
                                        <label class="hashposter-platform-checkbox">
                                            <input type="checkbox" name="platforms[]" value="<?php echo esc_attr( $platform ); ?>" />
                                            <span class="hp-platform-pill hp-pill-<?php echo esc_attr( $platform ); ?>"><?php echo esc_html( $label ); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <h4 class="hp-section-title"><?php _e( 'Content & Display Options', 'hashposter' ); ?></h4>
                                <div class="hashposter-content-settings" style="margin-bottom:24px;">
                                    <div class="hp-toggle-row">
                                        <div class="hp-toggle-info">
                                            <h4><?php _e( 'Custom Content Override', 'hashposter' ); ?></h4>
                                            <p><?php _e( 'Apply a uniform custom message or hashtags to all selected posts.', 'hashposter' ); ?></p>
                                        </div>
                                        <label class="hp-switch">
                                            <input type="checkbox" id="hashposter-use-custom-content" />
                                            <span class="hp-slider"></span>
                                        </label>
                                    </div>

                                    <div id="hashposter-custom-content-section" style="display: none; margin-top:14px;">
                                        <textarea id="hashposter-custom-content" rows="4" class="hp-input" style="min-height:90px;"
                                            placeholder="<?php _e( 'Enter custom message. Use {title}, {url}, {excerpt} as placeholders.', 'hashposter' ); ?>"></textarea>
                                    </div>

                                    <div class="hp-toggle-row">
                                        <div class="hp-toggle-info">
                                            <h4><?php _e( 'Skip Featured Images', 'hashposter' ); ?></h4>
                                            <p><?php _e( 'Publish as text/card link only without uploading image attachments.', 'hashposter' ); ?></p>
                                        </div>
                                        <label class="hp-switch">
                                            <input type="checkbox" id="hashposter-skip-featured-image" />
                                            <span class="hp-slider"></span>
                                        </label>
                                    </div>

                                    <div class="hp-toggle-row">
                                        <div class="hp-toggle-info">
                                            <h4><?php _e( 'Prefer Rich URL Cards', 'hashposter' ); ?></h4>
                                            <p><?php _e( 'Use rich Open Graph web cards instead of raw uploaded images.', 'hashposter' ); ?></p>
                                        </div>
                                        <label class="hp-switch">
                                            <input type="checkbox" id="hashposter-prefer-url-cards" checked />
                                            <span class="hp-slider"></span>
                                        </label>
                                    </div>
                                </div>

                                <h4 class="hp-section-title"><?php _e( 'Dispatch Schedule', 'hashposter' ); ?></h4>
                                <div class="hashposter-scheduling" style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px;">
                                        <input type="radio" name="posting_schedule" value="immediate" checked />
                                        <span><?php _e( 'Post immediately in sequence', 'hashposter' ); ?></span>
                                    </label>

                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px;">
                                        <input type="radio" name="posting_schedule" value="staggered" />
                                        <span><?php _e( 'Stagger posts with interval', 'hashposter' ); ?></span>
                                        <input type="number" id="hashposter-stagger-minutes" class="hp-input" min="1" max="60" value="5" style="width: 70px; margin-left: 8px; display:inline-block;" />
                                        <span style="color:var(--hp-text-muted); font-size:13px;"><?php _e( 'minutes apart', 'hashposter' ); ?></span>
                                    </label>
                                </div>

                                <div class="hashposter-bulk-actions">
                                    <button type="button" id="hashposter-preview-bulk" class="hp-btn hp-btn-secondary">
                                        <span class="dashicons dashicons-visibility" style="font-size:16px; width:16px; height:16px;"></span>
                                        <?php _e( 'Preview Posts', 'hashposter' ); ?>
                                    </button>
                                    <button type="button" id="hashposter-publish-bulk" class="hp-btn hp-btn-primary">
                                        <span class="dashicons dashicons-share" style="font-size:16px; width:16px; height:16px;"></span>
                                        <?php _e( 'Publish to Social Media', 'hashposter' ); ?>
                                    </button>
                                </div>

                                <div id="hashposter-bulk-progress" class="hashposter-progress" style="display: none;">
                                    <div class="hashposter-progress-bar">
                                        <div class="hashposter-progress-fill"></div>
                                    </div>
                                    <div class="hashposter-progress-text" style="font-size:13px; font-weight:600; color:var(--hp-text);">
                                        <?php _e( 'Publishing posts...', 'hashposter' ); ?>
                                    </div>
                                    <div id="hashposter-progress-details" style="font-size:12px; color:var(--hp-text-muted); margin-top:4px;"></div>
                                </div>
                            </div>

                        </div>
                    </div>
                </main>
            </div>
        </div>
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
    private function get_available_platforms() {
        return array(
            'x' => 'X (Twitter)',
            'facebook' => 'Facebook',
            'linkedin' => 'LinkedIn',
            'bluesky' => 'Bluesky',
            'telegram' => 'Telegram',
            // 'pinterest' => 'Pinterest' (removed),
            'wordpress' => 'WordPress'
        );
    }
}