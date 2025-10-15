<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_Post_Publisher {
    public function __construct() {
        add_action( 'transition_post_status', array( $this, 'maybe_publish_to_platforms' ), 10, 3 );
        add_action( 'hashposter_scheduled_post', array( $this, 'process_scheduled_post' ) );
        add_action( 'hashposter_delayed_post_process', array( $this, 'process_delayed_post' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_social_posting_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_social_posting_meta' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_post_editor_assets' ) );
        add_action( 'wp_ajax_hashposter_preview_post', array( $this, 'ajax_preview_post' ) );
        add_filter( 'hashposter_should_publish_post', array( $this, 'check_post_level_settings' ), 10, 2 );
    }

    /**
     * Check if we should publish a post to social platforms
     * 
     * @param string $new_status New post status
     * @param string $old_status Old post status
     * @param object $post Post object
     */
    public function maybe_publish_to_platforms( $new_status, $old_status, $post ) {
        // Extensive logging to diagnose workflow
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] Post status change for post ID ' . $post->ID . ': ' . $old_status . ' → ' . $new_status);
        }
        
        // Only autopost on new publish, not on draft/update/trash
        if ( $new_status !== 'publish' || $old_status === 'publish' ) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Skipping - not a new publish.');
            }
            return;
        }

        // Check if plugin is enabled
        $settings = get_option( 'hashposter_settings', array() );
        if ( empty( $settings['enabled'] ) ) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Plugin is disabled, skipping autopost.');
            }
            return;
        }

        // Skip non-public post types
        $post_type = get_post_type_object( $post->post_type );
        if ( !$post_type || !$post_type->public ) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Skipping non-public post type: ' . $post->post_type);
            }
            return;
        }

        // Prevent duplicate autoposts
        $autoposted = get_post_meta( $post->ID, '_hashposter_autoposted', true );
        if ($autoposted) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Post ' . $post->ID . ' already autoposted on: ' . $autoposted);
            }
            return;
        }

        // Get enabled platforms - check both global settings and per-post settings
        $api_credentials = get_option('hashposter_api_credentials', array());
        $oauth_tokens = get_option('hashposter_oauth_tokens', array());
        $post_social_settings = get_post_meta($post->ID, '_hashposter_social_settings', true);
        $active_platforms = array();
        
        // Ensure we have valid data to work with (fix PHP deprecation warnings)
        if (!is_array($post_social_settings)) {
            $post_social_settings = array();
        }
        if (!is_array($api_credentials)) {
            $api_credentials = array();
        }
        if (!is_array($oauth_tokens)) {
            $oauth_tokens = array();
        }
        
        // Check if per-post social posting is enabled
        $per_post_enabled = !empty($post_social_settings['enabled']) && $post_social_settings['enabled'] === '1';
        
        if ($per_post_enabled && !empty($post_social_settings['platforms']) && is_array($post_social_settings['platforms'])) {
            // Use per-post platform selection - validate credentials with API handler
            $api_handler = new HashPoster_API_Handler();
            foreach ($post_social_settings['platforms'] as $platform) {
                if ($api_handler->validate_credentials($platform)) {
                    $active_platforms[] = $platform;
                }
            }
        } else {
            // Fallback to global settings - use same credential detection as bulk posting
            $api_handler = new HashPoster_API_Handler();
            
            // Check all possible platforms
            foreach (['x', 'facebook', 'linkedin', 'bluesky'] as $platform) {
                // Check if platform is explicitly enabled in manual credentials
                $is_manually_active = !empty($api_credentials[$platform]['active']) && $api_credentials[$platform]['active'] === '1';
                
                // Check if platform has OAuth tokens
                $has_oauth = !empty($oauth_tokens[$platform]['access_token']);
                
                // First validate credentials to see if platform is configured
                $has_valid_credentials = $api_handler->validate_credentials($platform);
                
                // Platform is active if:
                // 1. Has OAuth tokens and valid credentials, OR
                // 2. Is manually enabled with valid credentials, OR
                // 3. Has valid credentials (for platforms like Bluesky that don't need explicit activation)
                if (($has_oauth && $has_valid_credentials) || ($is_manually_active && $has_valid_credentials) || (!$has_oauth && !$is_manually_active && $has_valid_credentials)) {
                    $active_platforms[] = $platform;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        $auth_method = $has_oauth ? 'OAuth' : 'manual';
                        error_log('[HashPoster] Platform ' . $platform . ' enabled via ' . $auth_method . ' credentials');
                    }
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] Per-post enabled: ' . ($per_post_enabled ? 'YES' : 'NO'));
            if ($per_post_enabled) {
                error_log('[HashPoster] Per-post platforms: ' . implode(', ', $post_social_settings['platforms'] ?? []));
            }
            error_log('[HashPoster] Final active platforms: ' . implode(', ', $active_platforms));
            
            // Debug credential status for troubleshooting
            foreach (['x', 'bluesky', 'facebook', 'linkedin'] as $platform) {
                $creds = $api_credentials[$platform] ?? array();
                $oauth = $oauth_tokens[$platform] ?? array();
                $is_active = !empty($creds['active']) && $creds['active'] === '1';
                $has_oauth = !empty($oauth['access_token']);
                $has_manual = !empty($creds);
                
                error_log('[HashPoster] Platform ' . $platform . ' - Active checkbox: ' . ($is_active ? 'YES' : 'NO') . 
                         ', Has OAuth: ' . ($has_oauth ? 'YES' : 'NO') .
                         ', Has manual credentials: ' . ($has_manual ? 'YES' : 'NO'));
            }
        }

        if (empty($active_platforms)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] No active platforms, nothing to autopost.');
            }
            return;
        }

        // ALWAYS add 30-second delay for WordPress to finish saving tags/taxonomies
        // Tags are assigned asynchronously and may not be available immediately after publish
        $delay_seconds = 30;
        
        // Check if user has configured additional scheduling delay
        if (!empty($settings['scheduling']) && !empty($settings['delay_minutes'])) {
            $delay_minutes = intval($settings['delay_minutes']);
            if ($delay_minutes > 0) {
                // Add user's delay on top of the 30-second tag delay
                $delay_seconds = 30 + ($delay_minutes * 60);
            }
        }
        
        // Check if WordPress cron is disabled (server-side cron setup)
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        
        if ($wp_cron_disabled) {
            // Server-side cron: Schedule for immediate processing after delay
            // Use a transient to store the post ID for delayed processing
            $transient_key = 'hashposter_delayed_post_' . $post->ID;
            set_transient($transient_key, $post->ID, $delay_seconds + 60); // Extra minute buffer
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Server-side cron detected, scheduling delayed processing in ' . $delay_seconds . ' seconds');
            }
            
            // Schedule the delayed processing using wp_schedule_single_event
            // This will work with server-side cron since it calls wp-cron.php
            wp_schedule_single_event(
                time() + $delay_seconds,
                'hashposter_delayed_post_process',
                array( $post->ID )
            );
        } else {
            // Standard WordPress cron: Use existing scheduling
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] WordPress cron enabled, scheduling post ' . $post->ID . ' for autopost in ' . $delay_seconds . ' seconds (allows tags to save)');
            }
            
            // Schedule the autopost with delay
            wp_schedule_single_event(
                time() + $delay_seconds,
                'hashposter_scheduled_post',
                array( $post->ID )
            );
        }
        
        return; // Always use scheduled posting to ensure tags are ready

        // Store results and mark autoposted if applicable
        if ( isset( $publish_result['platforms'] ) ) {
            update_post_meta( $post->ID, '_hashposter_results', $publish_result['platforms'] );
        }

        if ( ! empty( $publish_result['success'] ) ) {
            update_post_meta( $post->ID, '_hashposter_autoposted', current_time( 'mysql' ) );
        }

        return ! empty( $publish_result['success'] );
    }

    /**
     * Process a scheduled post (called by wp_cron)
     * This method handles posts that were scheduled for delayed posting
     * 
     * @param int $post_id Post ID to process
     */
    public function process_scheduled_post( $post_id ) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] Processing scheduled post ID: ' . $post_id);
        }

        // Get the post object
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Scheduled post ' . $post_id . ' is not published, skipping');
            }
            return;
        }

        // Check if already autoposted
        $autoposted = get_post_meta( $post_id, '_hashposter_autoposted', true );
        if ($autoposted) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Scheduled post ' . $post_id . ' already autoposted on: ' . $autoposted);
            }
            return;
        }

        // Get enabled platforms - check both global settings and per-post settings
        $api_credentials = get_option('hashposter_api_credentials', array());
        $oauth_tokens = get_option('hashposter_oauth_tokens', array());
        $post_social_settings = get_post_meta($post_id, '_hashposter_social_settings', true);
        $active_platforms = array();
        
        // Ensure we have valid data to work with
        if (!is_array($post_social_settings)) {
            $post_social_settings = array();
        }
        if (!is_array($api_credentials)) {
            $api_credentials = array();
        }
        if (!is_array($oauth_tokens)) {
            $oauth_tokens = array();
        }
        
        // Check if per-post social posting is enabled
        $per_post_enabled = !empty($post_social_settings['enabled']) && $post_social_settings['enabled'] === '1';
        
        if ($per_post_enabled && !empty($post_social_settings['platforms']) && is_array($post_social_settings['platforms'])) {
            // Use per-post platform selection - validate credentials with API handler
            $api_handler = new HashPoster_API_Handler();
            foreach ($post_social_settings['platforms'] as $platform) {
                if ($api_handler->validate_credentials($platform)) {
                    $active_platforms[] = $platform;
                }
            }
        } else {
            // Fallback to global settings - use same credential detection as bulk posting
            $api_handler = new HashPoster_API_Handler();
            
            // Check all possible platforms
            foreach (['x', 'facebook', 'linkedin', 'bluesky'] as $platform) {
                // Check if platform is explicitly enabled in manual credentials
                $is_manually_active = !empty($api_credentials[$platform]['active']) && $api_credentials[$platform]['active'] === '1';
                
                // Check if platform has OAuth tokens
                $has_oauth = !empty($oauth_tokens[$platform]['access_token']);
                
                // First validate credentials to see if platform is configured
                $has_valid_credentials = $api_handler->validate_credentials($platform);
                
                // Platform is active if:
                // 1. Has OAuth tokens and valid credentials, OR
                // 2. Is manually enabled with valid credentials, OR
                // 3. Has valid credentials (for platforms like Bluesky that don't need explicit activation)
                if (($has_oauth && $has_valid_credentials) || ($is_manually_active && $has_valid_credentials) || (!$has_oauth && !$is_manually_active && $has_valid_credentials)) {
                    $active_platforms[] = $platform;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        $auth_method = $has_oauth ? 'OAuth' : 'manual';
                        error_log('[HashPoster] Platform ' . $platform . ' enabled via ' . $auth_method . ' credentials');
                    }
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] Scheduled post ' . $post_id . ' - Final active platforms: ' . implode(', ', $active_platforms));
        }

        if (empty($active_platforms)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Scheduled post ' . $post_id . ' - No active platforms, nothing to autopost.');
            }
            return;
        }

        // Use central publisher to handle pipeline and API interaction
        $publisher = new HashPoster_Publisher();
        $publish_result = $publisher->publish_post_to_platforms( $post, $active_platforms );

        // Store results and mark autoposted if applicable
        if ( isset( $publish_result['platforms'] ) ) {
            update_post_meta( $post_id, '_hashposter_results', $publish_result['platforms'] );
        }

        if ( ! empty( $publish_result['success'] ) ) {
            update_post_meta( $post_id, '_hashposter_autoposted', current_time( 'mysql' ) );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Scheduled post ' . $post_id . ' successfully autoposted');
            }
        }

        return ! empty( $publish_result['success'] );
    }

    private function prepare_content($post_id, $post) {
        // Get template from options
        $post_cards = get_option('hashposter_post_cards', array());
        $template = !empty($post_cards['template']) ? $post_cards['template'] : '{title} {url}';

        // For optimal social media cards, keep text concise
        $title = get_the_title($post_id);
        $permalink = get_permalink($post_id);

        // Get a concise excerpt
        $excerpt = '';
        if (has_excerpt($post_id)) {
            $excerpt = get_the_excerpt($post_id);
        } else {
            $excerpt = wp_trim_words(strip_shortcodes(strip_tags($post->post_content)), 30);
        }

        // Core replacement data
        $data = [
            'title' => $title,
            'url' => $permalink,
            'excerpt' => $excerpt,
            'author' => get_the_author_meta('display_name', $post->post_author),
            'date' => get_the_date('', $post_id),
            'site_name' => get_bloginfo('name'),
        ];

        // Get categories
        $categories = get_the_category($post_id);
        $data['category'] = !empty($categories) ? $categories[0]->name : '';

        // Get tags
        $tags_arr = get_the_tags($post_id);
        $data['tags'] = '';
        if ($tags_arr) {
            $tag_names = array_map(function($tag) { return $tag->name; }, $tags_arr);
            $data['tags'] = implode(', ', $tag_names);
        }

        // Get shortlinks if requested
        $shortlinks = get_option('hashposter_shortlinks', array());
        $use_bitly = !empty($shortlinks['bitly']['active']) && !empty($shortlinks['bitly']['token']);

        // Only generate short URL if the template contains {short_url}
        if (strpos($template, '{short_url}') !== false) {
            if ($use_bitly) {
                $api = new HashPoster_API_Handler();
                $data['short_url'] = $api->get_short_url($permalink, $post_id);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[HashPoster] Generated Bitly short URL: ' . $data['short_url']);
                }
            } else {
                // Always use full permalink instead of WordPress shortlink for better social media previews
                $data['short_url'] = $permalink;
            }
        } else {
            $data['short_url'] = $permalink; // Default if not used in template
        }

        // Replace all tags in the template
        $content = $template;
        foreach ($data as $key => $value) {
            $content = str_replace('{'.$key.'}', $value, $content);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] Final content prepared: ' . $content);
        }

        return $content;
    }

    /**
     * Format content for specific social media platforms with OG-compliant formatting
     *
     * @param string $platform The social media platform
     * @param int $post_id Post ID
     * @param object $post Post object
     * @return string Formatted content for the platform
     */
    public function format_content_for_platform($platform, $post_id, $post, $base_content = null) {
        // Use provided base content (from pipeline) if available, otherwise regenerate
        $base_content = $base_content ?? $this->prepare_content($post_id, $post);

        switch ($platform) {
            case 'x':
                return $this->format_for_x($base_content, $post_id);
            case 'facebook':
                return $this->format_for_facebook($base_content, $post_id);
            case 'linkedin':
                return $this->format_for_linkedin($base_content, $post_id);
            case 'bluesky':
                return $this->format_for_bluesky($base_content, $post_id);
            // Pinterest support removed plugin-wide.
            default:
                return $base_content;
        }
    }

    /**
     * Format content for X (Twitter) with optimal length
     */
    private function format_for_x($content, $post_id) {
        // X has 280 character limit, prefers concise content with link cards

        $max_length = 280;
        $url = get_permalink($post_id);

        // Always use full permalink for better social media preview cards
        // X will auto-shorten URLs in character count anyway

        // For X, we prefer link cards over embedded URLs when possible
        // But we still need to account for URL length in character counting
        $url_length = strlen($url);

        // Check if URL is already in content
        $url_in_content = strpos($content, $url) !== false;

        // If URL not in content, reserve space for it (X shortens URLs to ~23 characters in counts)
        if (!$url_in_content) {
            $reserved_space = 24; // Approximate shortened URL length + space
        } else {
            $reserved_space = 0;
        }

        $available_content_length = $max_length - $reserved_space;

        // Truncate content if needed, but preserve important words
        if (strlen($content) > $available_content_length) {
            // Try to truncate at word boundary
            $truncated = substr($content, 0, $available_content_length);
            $last_space = strrpos($truncated, ' ');
            if ($last_space !== false && $last_space > $available_content_length * 0.7) {
                $truncated = substr($truncated, 0, $last_space);
            }
            $content = $truncated . '...';
        }

        // Build final content
        $final_content = $content;

        // Add URL if not present (X will convert this to a link card)
        if (!$url_in_content) {
            $final_content .= ' ' . $url;
        }

        // Final safety check - ensure we never exceed limit
        if (strlen($final_content) > $max_length) {
            $final_content = substr($final_content, 0, $max_length - 3) . '...';
        }

        return $final_content;
    }

    /**
     * Format content for Facebook with rich formatting
     */
    private function format_for_facebook($content, $post_id) {
        // Facebook supports longer posts (up to 63,206 characters), but optimal engagement is 40-80 characters
        // We'll aim for a balanced approach with excerpt and call-to-action

        $excerpt = get_the_excerpt($post_id);
        $title = get_the_title($post_id);

        // Build engaging Facebook post
        $facebook_content = $content;

        // Add excerpt if it's substantial and not already included
        if (!empty($excerpt) && strlen($excerpt) > 30 && strpos($content, $excerpt) === false) {
            // Limit excerpt to 150 characters for Facebook
            $excerpt = strlen($excerpt) > 150 ? substr($excerpt, 0, 147) . '...' : $excerpt;
            $facebook_content .= "\n\n" . $excerpt;
        }

        // Add call-to-action
        $facebook_content .= "\n\n🔗 Read the full article on our blog!";

        return $facebook_content;
    }

    /**
     * Format content for LinkedIn with professional tone
     */
    private function format_for_linkedin($content, $post_id) {
        // LinkedIn allows up to 3,000 characters but optimal engagement is 100-200 characters
        // Professional tone with article-style formatting

        $author = get_the_author_meta('display_name', get_post_field('post_author', $post_id));
        $categories = get_the_category($post_id);
        $excerpt = get_the_excerpt($post_id);

        // Professional introduction
        $linkedin_content = "📈 New article";
        if (!empty($author)) {
            $linkedin_content .= " by " . $author;
        }
        if (!empty($categories)) {
            $linkedin_content .= " in " . $categories[0]->name;
        }
        $linkedin_content .= ":\n\n";

        // Add the main content
        $linkedin_content .= $content;

        // Add excerpt if substantial and not redundant
        if (!empty($excerpt) && strlen($excerpt) > 50 && strpos($content, $excerpt) === false) {
            $excerpt = strlen($excerpt) > 100 ? substr($excerpt, 0, 97) . '...' : $excerpt;
            $linkedin_content .= "\n\n" . $excerpt;
        }

        // Add call-to-action
        $linkedin_content .= "\n\n💡 What are your thoughts on this topic? Share in the comments!";

        return $linkedin_content;
    }

    /**
     * Format content for Bluesky with modern, conversational tone
     */
    private function format_for_bluesky($content, $post_id) {
        // Bluesky has 300 character limit, prefers conversational tone with emojis

        $max_length = 300;
        $url = get_permalink($post_id);

        // Always use full permalink for better social media preview cards

        // Calculate space needed
        $url_length = strlen($url);
        $reserved_space = $url_length + 4; // spaces and emoji

        $available_content_length = $max_length - $reserved_space;

        // Add emoji based on content
        $content = $this->add_content_emojis($content, $post_id);

        // Check if URL is already in content
        $url_in_content = strpos($content, $url) !== false;

        // If URL not in content, reserve space for it
        if (!$url_in_content) {
            $available_content_length -= ($url_length + 1);
        }

        // Truncate content if needed
        if (strlen($content) > $available_content_length) {
            $content = substr($content, 0, $available_content_length - 3) . '...';
        }

        // Build final content
        $final_content = $content;

        // Add URL if not present
        if (!$url_in_content) {
            $final_content .= ' ' . $url;
        }

        // Final safety check
        if (strlen($final_content) > $max_length) {
            $final_content = substr($final_content, 0, $max_length - 3) . '...';
        }

        return $final_content;
    }

    // (Reddit support removed)

    // Pinterest formatting removed.

    // Merged from class-post-controls.php

    /**
     * Add meta box to post editor
     */
    public function add_social_posting_meta_box() {
        $post_types = get_post_types( array( 'public' => true ) );

        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'hashposter_social_posting',
                'HashPoster - Social Media Posting',
                array( $this, 'render_social_posting_meta_box' ),
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the social posting meta box
     */
    public function render_social_posting_meta_box( $post ) {
        wp_nonce_field( 'hashposter_social_posting_meta', 'hashposter_social_posting_nonce' );

        $meta = get_post_meta( $post->ID, '_hashposter_social_settings', true );
        $settings = wp_parse_args( $meta, array(
            'enabled' => '1',
            'platforms' => array(),
            'custom_content' => '',
            'schedule_time' => '',
            'skip_featured_image' => '0',
            'prefer_url_cards' => '0'
        ) );

        // Get available platforms
        $available_platforms = $this->get_available_platforms();

        ?>
        <div id="hashposter-post-controls">
            <p>
                <label>
                    <input type="checkbox" name="hashposter_social[enabled]" value="1" <?php checked( $settings['enabled'], '1' ); ?> />
                    <strong><?php _e( 'Enable social media posting for this post', 'hashposter' ); ?></strong>
                </label>
            </p>

            <div id="hashposter-platforms-section" style="<?php echo $settings['enabled'] === '1' ? '' : 'display:none;'; ?>">
                <p><strong><?php _e( 'Select platforms:', 'hashposter' ); ?></strong></p>

                <?php foreach ( $available_platforms as $platform => $label ) : ?>
                    <label style="display: block; margin-bottom: 5px;">
                        <input type="checkbox"
                               name="hashposter_social[platforms][]"
                               value="<?php echo esc_attr( $platform ); ?>"
                               <?php checked( in_array( $platform, $settings['platforms'] ) ); ?> />
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>

                <p>
                    <label for="hashposter_custom_content">
                        <strong><?php _e( 'Custom content (optional):', 'hashposter' ); ?></strong>
                    </label>
                    <textarea name="hashposter_social[custom_content]"
                              id="hashposter_custom_content"
                              rows="3"
                              style="width: 100%;"
                              placeholder="<?php _e( 'Leave empty to use post title and excerpt', 'hashposter' ); ?>"><?php echo esc_textarea( $settings['custom_content'] ); ?></textarea>
                </p>

                <p>
                    <label for="hashposter_schedule_time">
                        <strong><?php _e( 'Schedule posting:', 'hashposter' ); ?></strong>
                    </label>
                    <input type="datetime-local"
                           name="hashposter_social[schedule_time]"
                           id="hashposter_schedule_time"
                           value="<?php echo esc_attr( $settings['schedule_time'] ); ?>"
                           style="width: 100%;" />
                    <small><?php _e( 'Leave empty for immediate posting', 'hashposter' ); ?></small>
                </p>
            </div>

            <div id="hashposter-preview-section" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                <button type="button" id="hashposter-preview-btn" class="button button-secondary">
                    <?php _e( 'Preview Social Posts', 'hashposter' ); ?>
                </button>
                <div id="hashposter-preview-content" style="display: none; margin-top: 10px;"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
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
                    alert('<?php _e( 'Please select at least one platform to preview.', 'hashposter' ); ?>');
                    return;
                }

                var customContent = $('#hashposter_custom_content').val();

                // Show loading
                $('#hashposter-preview-content').html('<p><?php _e( 'Generating preview...', 'hashposter' ); ?></p>').show();

                // AJAX request for preview
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hashposter_preview_post',
                        post_id: <?php echo $post->ID; ?>,
                        platforms: platforms,
                        custom_content: customContent,
                        nonce: '<?php echo wp_create_nonce( 'hashposter_preview' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#hashposter-preview-content').html(response.data.html);
                        } else {
                            $('#hashposter-preview-content').html('<p style="color: red;">' + response.data.message + '</p>');
                        }
                    },
                    error: function() {
                        $('#hashposter-preview-content').html('<p style="color: red;"><?php _e( 'Preview failed. Please try again.', 'hashposter' ); ?></p>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Save social posting meta data
     */
    public function save_social_posting_meta( $post_id ) {
        // Verify nonce
        if ( ! isset( $_POST['hashposter_social_posting_nonce'] ) ||
             ! wp_verify_nonce( $_POST['hashposter_social_posting_nonce'], 'hashposter_social_posting_meta' ) ) {
            return;
        }

        // Check permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Don't save on autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Save meta data
        if ( isset( $_POST['hashposter_social'] ) ) {
            $data = $_POST['hashposter_social'];

            // Sanitize data
            $sanitized = array(
                'enabled' => isset( $data['enabled'] ) ? '1' : '0',
                'platforms' => isset( $data['platforms'] ) ? array_map( 'sanitize_text_field', $data['platforms'] ) : array(),
                'custom_content' => isset( $data['custom_content'] ) ? sanitize_textarea_field( $data['custom_content'] ) : '',
                'schedule_time' => isset( $data['schedule_time'] ) ? sanitize_text_field( $data['schedule_time'] ) : '',
                'skip_featured_image' => isset( $data['skip_featured_image'] ) ? '1' : '0',
                'prefer_url_cards' => isset( $data['prefer_url_cards'] ) ? '1' : '0'
            );

            update_post_meta( $post_id, '_hashposter_social_settings', $sanitized );
        }
    }

    /**
     * Enqueue assets for post editor
     */
    public function enqueue_post_editor_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
            return;
        }

        wp_enqueue_script(
            'hashposter-post-controls',
            HASHPOSTER_URL . 'assets/js/post-controls.js',
            array( 'jquery' ),
            '1.0',
            true
        );

        wp_localize_script( 'hashposter-post-controls', 'hashposterPostControls', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'hashposter_preview' ),
            'strings' => array(
                'preview' => __( 'Preview Social Posts', 'hashposter' ),
                'generating' => __( 'Generating preview...', 'hashposter' ),
                'no_platforms' => __( 'Please select at least one platform to preview.', 'hashposter' ),
                'failed' => __( 'Preview failed. Please try again.', 'hashposter' )
            )
        ) );
    }

    /**
     * Check post-level settings before publishing
     */
    public function check_post_level_settings( $should_publish, $post_id ) {
        $meta = get_post_meta( $post_id, '_hashposter_social_settings', true );

        if ( ! $meta || $meta['enabled'] !== '1' ) {
            return false; // Post-level social posting is disabled
        }

        return $should_publish;
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
            // 'pinterest' => 'Pinterest' (removed)
        );
    }

    /**
     * Get post social settings
     */
    public static function get_post_social_settings( $post_id ) {
        $meta = get_post_meta( $post_id, '_hashposter_social_settings', true );

        if ( ! $meta ) {
            return array(
                'enabled' => '1',
                'platforms' => array(),
                'custom_content' => '',
                'schedule_time' => '',
                'skip_featured_image' => '0',
                'prefer_url_cards' => '0'
            );
        }

        return $meta;
    }

    /**
     * AJAX handler for post preview
     */
    public function ajax_preview_post() {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'hashposter_preview' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
        }

    $post_id = intval( $_POST['post_id'] ?? 0 );
        $platforms = array_map( 'sanitize_text_field', $_POST['platforms'] ?? array() );
        $custom_content = sanitize_textarea_field( $_POST['custom_content'] ?? '' );
        $prefer_url_cards = ! empty( $_POST['prefer_url_cards'] ) && $_POST['prefer_url_cards'] !== '0';
    $skip_featured_image = ! empty( $_POST['skip_featured_image'] ) && $_POST['skip_featured_image'] !== '0';

        if ( ! $post_id || empty( $platforms ) ) {
            wp_send_json_error( array( 'message' => 'Missing required parameters' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( array( 'message' => 'Post not found' ) );
        }

        $api_handler = new HashPoster_API_Handler();
        $post_publisher = new HashPoster_Post_Publisher();
        $publisher = new HashPoster_Publisher();
        $preview_html = '<div class="hashposter-preview">';

        foreach ( $platforms as $platform ) {
            // Use shared content preparation via publisher
            $content_data = $publisher->prepare_content_pipeline( $post, $platform, $custom_content );

            $content = $content_data['content'];
            $media = $content_data['media'];

            $preview_html .= '<div class="hashposter-platform-preview">';
            $preview_html .= '<h4>' . esc_html( $this->get_platform_label( $platform ) ) . '</h4>';
            $preview_html .= '<div class="hashposter-content-preview">';
            $preview_html .= '<p><strong>Content:</strong></p>';
            $preview_html .= '<div class="hashposter-content-text">' . esc_html( $content ) . '</div>';

            if ( ! empty( $media['prefer_url_card'] ) ) {
                $preview_html .= '<p><strong>URL Card:</strong> ' . esc_html( $media['url'] ) . '</p>';
            }

            $preview_html .= '</div></div>';
        }

        $preview_html .= '</div>';

        wp_send_json_success( array( 'html' => $preview_html ) );
    }

    /**
     * Get platform display label
     */
    private function get_platform_label( $platform ) {
        $labels = array(
            'x' => 'X (Twitter)',
            'facebook' => 'Facebook',
            'linkedin' => 'LinkedIn',
            'bluesky' => 'Bluesky',
            // 'reddit' => 'Reddit',
            // 'pinterest' => 'Pinterest' (removed)
        );

        return $labels[ $platform ] ?? ucfirst( $platform );
    }

    /**
     * Replace placeholders in content
     */
    private function replace_placeholders( $content, $post ) {
        $placeholders = array(
            '{title}' => get_the_title( $post ),
            '{excerpt}' => get_the_excerpt( $post ),
            '{url}' => get_permalink( $post ),
            '{date}' => get_the_date( '', $post ),
            '{author}' => get_the_author_meta( 'display_name', $post->post_author )
        );

        return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $content );
    }

    /**
     * Process delayed post for server-side cron environments
     * This method handles posts that were scheduled with a delay to ensure tags are saved
     */
    public function process_delayed_post( $post_id ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[HashPoster] Processing delayed post: ' . $post_id );
        }

        // Get the post
        $post = get_post( $post_id );
        if ( ! $post ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[HashPoster] Delayed post not found: ' . $post_id );
            }
            return;
        }

        // Replicate the same checks as maybe_publish_to_platforms
        // Check if plugin is enabled
        $settings = get_option( 'hashposter_settings', array() );
        if ( empty( $settings['enabled'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[HashPoster] Plugin is disabled, skipping delayed autopost.' );
            }
            return;
        }

        // Skip non-public post types
        $post_type = get_post_type_object( $post->post_type );
        if ( ! $post_type || ! $post_type->public ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[HashPoster] Skipping non-public post type: ' . $post->post_type );
            }
            return;
        }

        // Prevent duplicate autoposts
        $autoposted = get_post_meta( $post->ID, '_hashposter_autoposted', true );
        if ( $autoposted ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[HashPoster] Post ' . $post->ID . ' already autoposted on: ' . $autoposted );
            }
            return;
        }

        // Get enabled platforms - simplified check
        $api_credentials = get_option( 'hashposter_api_credentials', array() );
        $oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
        $post_social_settings = get_post_meta( $post->ID, '_hashposter_social_settings', true );
        $active_platforms = array();

        // Ensure we have valid data
        if ( ! is_array( $post_social_settings ) ) {
            $post_social_settings = array();
        }
        if ( ! is_array( $api_credentials ) ) {
            $api_credentials = array();
        }
        if ( ! is_array( $oauth_tokens ) ) {
            $oauth_tokens = array();
        }

        // Check if per-post social posting is enabled
        $per_post_enabled = ! empty( $post_social_settings['enabled'] ) && $post_social_settings['enabled'] === '1';

        if ( $per_post_enabled && ! empty( $post_social_settings['platforms'] ) && is_array( $post_social_settings['platforms'] ) ) {
            // Use per-post platform selection - validate credentials with API handler
            $api_handler = new HashPoster_API_Handler();
            foreach ( $post_social_settings['platforms'] as $platform ) {
                if ( $api_handler->validate_credentials( $platform ) ) {
                    $active_platforms[] = $platform;
                }
            }
        } else {
            // Fallback to global settings - use same credential detection as bulk posting
            $api_handler = new HashPoster_API_Handler();

            // Check all possible platforms
            foreach ( [ 'x', 'facebook', 'linkedin', 'bluesky' ] as $platform ) {
                // Check if platform is explicitly enabled in manual credentials
                $is_manually_active = ! empty( $api_credentials[$platform]['active'] ) && $api_credentials[$platform]['active'] === '1';

                // Check if platform has OAuth tokens
                $has_oauth = ! empty( $oauth_tokens[$platform]['access_token'] );

                // First validate credentials to see if platform is configured
                $has_valid_credentials = $api_handler->validate_credentials( $platform );

                // Platform is active if:
                // 1. Has OAuth tokens and valid credentials, OR
                // 2. Is manually enabled with valid credentials, OR
                // 3. Has valid credentials (for platforms like Bluesky that don't need explicit activation)
                if ( ( $has_oauth && $has_valid_credentials ) || ( $is_manually_active && $has_valid_credentials ) || ( ! $has_oauth && ! $is_manually_active && $has_valid_credentials ) ) {
                    $active_platforms[] = $platform;
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        $auth_method = $has_oauth ? 'OAuth' : 'manual';
                        error_log( '[HashPoster] Platform ' . $platform . ' enabled via ' . $auth_method . ' credentials' );
                    }
                }
            }
        }

        if ( empty( $active_platforms ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[HashPoster] No active platforms, nothing to autopost.' );
            }
            return;
        }

        // Process the post immediately now that tags should be available
        $this->process_scheduled_post( $post_id );

        // Clean up the transient
        $transient_key = 'hashposter_delayed_post_' . $post_id;
        delete_transient( $transient_key );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[HashPoster] Delayed post processing completed for: ' . $post_id );
        }
    }
}