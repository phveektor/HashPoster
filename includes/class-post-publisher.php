<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_Post_Publisher {
    private $settings_cache = null;
    private $api_credentials_cache = null;
    private $oauth_tokens_cache = null;
    private $api_handler = null;

    public function __construct() {
        add_action( 'transition_post_status', array( $this, 'maybe_publish_to_platforms' ), 10, 3 );
        add_action( 'hashposter_scheduled_post', array( $this, 'process_scheduled_post' ) );
        add_action( 'hashposter_delayed_post_process', array( $this, 'process_delayed_post' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_social_posting_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_social_posting_meta' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_post_editor_assets' ) );
        add_action( 'wp_ajax_hashposter_preview_post', array( $this, 'ajax_preview_post' ) );
        add_filter( 'hashposter_should_publish_post', array( $this, 'check_post_level_settings' ), 10, 2 );
        add_action( 'before_delete_post', array( $this, 'cleanup_scheduled_events' ) );
        add_action( 'wp_trash_post', array( $this, 'cleanup_scheduled_events' ) );
    }

    /**
     * Get cached settings to reduce DB calls
     */
    private function get_cached_settings() {
        if ( $this->settings_cache === null ) {
            $this->settings_cache = get_option( 'hashposter_settings', array() );
        }
        return $this->settings_cache;
    }

    /**
     * Get cached API credentials to reduce DB calls
     */
    private function get_cached_credentials() {
        if ( $this->api_credentials_cache === null ) {
            $this->api_credentials_cache = get_option( 'hashposter_api_credentials', array() );
        }
        return $this->api_credentials_cache;
    }

    /**
     * Get cached OAuth tokens to reduce DB calls
     */
    private function get_cached_oauth_tokens() {
        if ( $this->oauth_tokens_cache === null ) {
            $this->oauth_tokens_cache = get_option( 'hashposter_oauth_tokens', array() );
        }
        return $this->oauth_tokens_cache;
    }

    /**
     * Get API handler instance (singleton pattern)
     */
    private function get_api_handler() {
        if ( $this->api_handler === null ) {
            $this->api_handler = new HashPoster_API_Handler();
        }
        return $this->api_handler;
    }

    /**
     * Add UTM parameters to URL for tracking
     * 
     * @param string $url       The URL to add UTM parameters to
     * @param string $platform  The social media platform (facebook, linkedin, bluesky, telegram)
     * @return string           URL with UTM parameters
     */
    private function add_utm_parameters( $url, $platform ) {
        // Only add UTMs to outbound social links, not canonical URLs
        $utm_params = array(
            'utm_source' => sanitize_key( $platform ),
            'utm_medium' => 'social',
            'utm_campaign' => 'hashposter'
        );
        
        return add_query_arg( $utm_params, $url );
    }

    /**
     * Cleanup scheduled events when post is deleted or trashed
     *
     * @param int $post_id The post ID
     */
    public function cleanup_scheduled_events( $post_id ) {
        // Remove scheduled post event
        $timestamp = wp_next_scheduled( 'hashposter_scheduled_post', array( $post_id ) );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'hashposter_scheduled_post', array( $post_id ) );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[HashPoster] Cleaned up scheduled event for deleted post ' . $post_id );
            }
        }
        
        // Remove delayed post processing event
        $timestamp_delayed = wp_next_scheduled( 'hashposter_delayed_post_process', array( $post_id ) );
        if ( $timestamp_delayed ) {
            wp_unschedule_event( $timestamp_delayed, 'hashposter_delayed_post_process', array( $post_id ) );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[HashPoster] Cleaned up delayed event for deleted post ' . $post_id );
            }
        }
    }

    /**
     * Get active platforms for a post - consolidated logic to reduce duplication
     *
     * @param int $post_id The post ID
     * @return array Active platforms
     */
    private function get_active_platforms_for_post( $post_id ) {
        $api_credentials = $this->get_cached_credentials();
        $oauth_tokens = $this->get_cached_oauth_tokens();
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
        // Default to enabled ('1') if meta hasn't been set yet (e.g., on first post creation)
        $per_post_enabled = !isset($post_social_settings['enabled']) || $post_social_settings['enabled'] === '1';
        $api_handler = $this->get_api_handler();
        
        if (!$per_post_enabled) {
            // If per-post is disabled, don't post for this post
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Per-post posting disabled for post ' . $post_id);
            }
            return array();
        }
        
        // Get platforms from post meta, or use defaults if not set
        $platforms_from_meta = !empty($post_social_settings['platforms']) && is_array($post_social_settings['platforms']) 
            ? $post_social_settings['platforms'] 
            : array();
        
        // If no platforms in meta, use defaults from settings option
        if (empty($platforms_from_meta)) {
            $default_platforms = get_option('hashposter_default_platforms', array('facebook', 'linkedin', 'bluesky'));
            if (is_array($default_platforms) && !empty($default_platforms)) {
                $platforms_from_meta = $default_platforms;
            } else {
                // Fallback to checking enabled API credentials if settings option is not set
                foreach ( array( 'facebook', 'linkedin', 'bluesky', 'telegram' ) as $platform ) {
                    if ( ! empty( $api_credentials[ $platform ]['active'] ) && $api_credentials[ $platform ]['active'] === '1' ) {
                        $platforms_from_meta[] = $platform;
                    }
                }
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] No platforms in post meta, using defaults: ' . implode(', ', $platforms_from_meta));
            }
        }
        
        // Validate each platform and add to active list
        if (!empty($platforms_from_meta) && is_array($platforms_from_meta)) {
            foreach ($platforms_from_meta as $platform) {
                if ($api_handler->validate_credentials($platform)) {
                    $active_platforms[] = $platform;
                }
            }
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] Active platforms for post ' . $post_id . ': ' . implode(', ', $active_platforms));
        }
        
        return $active_platforms;
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
        
        // Check if plugin is enabled
        $settings = $this->get_cached_settings();
        if ( empty( $settings['enabled'] ) ) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Plugin is disabled, skipping autopost.');
            }
            return;
        }

        // Only autopost when status is 'publish'
        if ( $new_status !== 'publish' ) {
            return;
        }

        // Skip non-public post types
        $post_type = get_post_type_object( $post->post_type );
        if ( !$post_type || !$post_type->public || $post->post_type !== 'post' ) {
            return;
        }

        // Detect if this is an update to an already-published post
        $disable_on_update = ! isset( $settings['disable_on_update'] ) || ! empty( $settings['disable_on_update'] );
        $autoposted = get_post_meta( $post->ID, '_hashposter_autoposted', true );
        $first_published = get_post_meta( $post->ID, '_hashposter_first_published', true );
        $post_social_settings = get_post_meta( $post->ID, '_hashposter_social_settings', true );
        $explicit_repost = ! empty( $post_social_settings['repost_on_update'] ) && $post_social_settings['repost_on_update'] === '1';

        // Check if this post was already published before
        $is_update = false;
        if ( $old_status === 'publish' ) {
            $is_update = true;
        } elseif ( ! empty( $autoposted ) || ! empty( $first_published ) ) {
            $is_update = true;
        } elseif ( ! empty( $post->post_date_gmt ) && $post->post_date_gmt !== '0000-00-00 00:00:00' && strtotime( $post->post_date_gmt ) < ( time() - 300 ) ) {
            // Post date was established more than 5 minutes ago — subsequent save is an update
            $is_update = true;
        }

        if ( $is_update ) {
            if ( $disable_on_update && ! $explicit_repost ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[HashPoster] Post ' . $post->ID . ' is an update. Skipping autopost (disable_on_update is active).' );
                }
                return;
            }
        } elseif ( ! empty( $autoposted ) ) {
            // New post publish, but already autoposted
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[HashPoster] Post ' . $post->ID . ' already autoposted on: ' . $autoposted );
            }
            return;
        }

        // Get enabled platforms using consolidated method
        $active_platforms = $this->get_active_platforms_for_post( $post->ID );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $post_social_settings = get_post_meta($post->ID, '_hashposter_social_settings', true);
            $per_post_enabled = !empty($post_social_settings['enabled']) && $post_social_settings['enabled'] === '1';
            error_log('[HashPoster] Per-post enabled: ' . ($per_post_enabled ? 'YES' : 'NO'));
            if ($per_post_enabled) {
                error_log('[HashPoster] Per-post platforms: ' . implode(', ', $post_social_settings['platforms'] ?? []));
            }
            error_log('[HashPoster] Final active platforms: ' . implode(', ', $active_platforms));
            
            // Debug credential status for troubleshooting
            $api_credentials = $this->get_cached_credentials();
            $oauth_tokens = $this->get_cached_oauth_tokens();
            foreach (['bluesky', 'facebook', 'linkedin', 'telegram'] as $platform) {
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
        
        // Always use scheduled posting to ensure tags are ready
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

        // Prevent concurrent execution for the same post across workers / requests
        $lock_key = 'hashposter_lock_' . $post_id;
        if ( get_transient( $lock_key ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[HashPoster] Post ' . $post_id . ' is already being published by another worker. Skipping duplicate run.' );
            }
            return;
        }
        set_transient( $lock_key, time(), 300 ); // 5-minute safety lock

        try {
            // Get enabled platforms using consolidated method
            $active_platforms = $this->get_active_platforms_for_post( $post_id );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Scheduled post ' . $post_id . ' - Final active platforms: ' . implode(', ', $active_platforms));
            }

            if (empty($active_platforms)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[HashPoster] Scheduled post ' . $post_id . ' - No active platforms, nothing to autopost.');
                }
                delete_transient( $lock_key );
                return;
            }

            // Use central publisher to handle pipeline and API interaction
            $publisher = new HashPoster_Publisher();
            $publish_result = $publisher->publish_post_to_platforms( $post, $active_platforms );

            // Store results and mark autoposted if applicable
            if ( isset( $publish_result['platforms'] ) ) {
                update_post_meta( $post_id, '_hashposter_results', $publish_result['platforms'] );
            }

            // Always record first_published and autoposted timestamp
            update_post_meta( $post_id, '_hashposter_autoposted', current_time( 'mysql' ) );
            if ( ! get_post_meta( $post_id, '_hashposter_first_published', true ) ) {
                update_post_meta( $post_id, '_hashposter_first_published', current_time( 'mysql' ) );
            }
            // Reset repost_on_update so subsequent edits don't keep reposting
            $post_meta_data = get_post_meta( $post_id, '_hashposter_social_settings', true );
            if ( is_array( $post_meta_data ) ) {
                $post_meta_data['repost_on_update'] = '0';
                $post_meta_data['enabled'] = '0';
                update_post_meta( $post_id, '_hashposter_social_settings', $post_meta_data );
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Scheduled post ' . $post_id . ' autoposted');
            }

            delete_transient( $lock_key );
            return ! empty( $publish_result['success'] );

        } catch ( Exception $e ) {
            delete_transient( $lock_key );
            error_log( '[HashPoster] Exception during scheduled post ' . $post_id . ': ' . $e->getMessage() );
            return false;
        }
    }

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
        // Output nonce field with explicit handling
        wp_nonce_field( 'hashposter_social_posting_meta', 'hashposter_social_posting_nonce' );
        
        // Safety: Also output as hidden input in case wp_nonce_field has issues
        echo '<input type="hidden" name="hashposter_social_posting_nonce" value="' . wp_create_nonce( 'hashposter_social_posting_meta' ) . '" />';

        $meta = get_post_meta( $post->ID, '_hashposter_social_settings', true );
        
        // Get configured default platforms from settings (or fallback to hardcoded defaults)
        $default_platforms = get_option( 'hashposter_default_platforms', array( 'facebook', 'linkedin', 'bluesky' ) );
        
        // If meta exists, use it (user's manual selections override defaults)
        // If meta doesn't exist, use the default platforms from settings
        if ( is_array( $meta ) && isset( $meta['platforms'] ) ) {
            $platforms_to_use = $meta['platforms'];
            $enabled = isset( $meta['enabled'] ) ? $meta['enabled'] : '1';
        } else {
            $platforms_to_use = $default_platforms;
            $enabled = '1';
        }
        
        $settings = wp_parse_args( $meta, array(
            'enabled' => $enabled,
            'platforms' => $platforms_to_use,
            'custom_content' => '',
            'schedule_time' => '',
            'skip_featured_image' => '0',
            'prefer_url_cards' => '0'
        ) );

        // Get available platforms
        $available_platforms = $this->get_available_platforms();

        $already_autoposted = get_post_meta( $post->ID, '_hashposter_autoposted', true );
        $is_published_post = ( $post->post_status === 'publish' );
        ?>
        <div id="hashposter-post-controls">
            <?php if ( $is_published_post || ! empty( $already_autoposted ) ) : ?>
                <div style="background:#e7f3fe; border-left:4px solid #2271b1; padding:8px 12px; margin-bottom:12px; border-radius:2px;">
                    <strong><?php _e( 'Social Status:', 'hashposter' ); ?></strong>
                    <?php if ( ! empty( $already_autoposted ) ) : ?>
                        <span><?php printf( __( 'Shared on %s', 'hashposter' ), esc_html( $already_autoposted ) ); ?></span>
                    <?php else : ?>
                        <span><?php _e( 'Post already published.', 'hashposter' ); ?></span>
                    <?php endif; ?>
                </div>
                <p>
                    <label>
                        <input type="checkbox" name="hashposter_social[repost_on_update]" value="1" <?php checked( ! empty( $settings['repost_on_update'] ) ); ?> />
                        <strong><?php _e( 'Repost to social media on this update', 'hashposter' ); ?></strong>
                    </label>
                    <br><small style="color:#666;"><?php _e( 'Leave unchecked to keep existing social posts unchanged.', 'hashposter' ); ?></small>
                </p>
                <input type="hidden" name="hashposter_social[enabled]" value="<?php echo ! empty( $settings['repost_on_update'] ) ? '1' : '0'; ?>" />
            <?php else : ?>
                <p>
                    <label>
                        <input type="checkbox" name="hashposter_social[enabled]" value="1" <?php checked( $settings['enabled'], '1' ); ?> />
                        <strong><?php _e( 'Enable social media posting for this post', 'hashposter' ); ?></strong>
                    </label>
                </p>
            <?php endif; ?>

            <div id="hashposter-platforms-section" style="<?php echo $settings['enabled'] === '1' ? '' : 'display:none;'; ?>">
                <p>
                    <strong><?php _e( 'Select platforms:', 'hashposter' ); ?></strong><br>
                    <small><?php _e( 'Defaults configured in settings. Check/uncheck to customize.', 'hashposter' ); ?></small>
                </p>

                <?php foreach ( $available_platforms as $platform => $label ) : ?>
                    <label style="display: block; margin-bottom: 5px;">
                        <input type="checkbox"
                               name="hashposter_social[platforms][]"
                               value="<?php echo esc_attr( $platform ); ?>"
                               <?php checked( in_array( $platform, $settings['platforms'] ) ); ?> />
                        <?php echo esc_html( $label ); ?>
                        <?php if ( in_array( $platform, $default_platforms ) ) : ?>
                            <span style="color: #666; font-size: 0.9em;">(default)</span>
                        <?php endif; ?>
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
        </div>
        <?php
    }

    /**
     * Save social posting meta data
     */
    public function save_social_posting_meta( $post_id, $post ) {
        // Skip autosaves, revisions, and bulk edits to avoid conflicts
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( wp_is_post_autosave( $post_id ) ) {
            return;
        }

        // Check permissions first
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Verify nonce if present - but allow saving if meta data is being submitted
        // (some cases like REST API or programmatic saves may not include nonce)
        if ( isset( $_POST['hashposter_social_posting_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['hashposter_social_posting_nonce'], 'hashposter_social_posting_meta' ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[HashPoster] Nonce verification failed for post ' . $post_id );
                }
                return;
            }
        } elseif ( ! isset( $_POST['hashposter_social'] ) ) {
            // If no nonce and no hashposter_social data, skip processing
            return;
        }

        // Always save meta data (even when checkbox is unchecked)
        // If hashposter_social is not set, it means the checkbox was unchecked
        $data = isset( $_POST['hashposter_social'] ) ? $_POST['hashposter_social'] : array();

        // If enabled checkbox is checked but no platforms selected, use defaults from settings
        $platforms = isset( $data['platforms'] ) ? array_map( 'sanitize_text_field', $data['platforms'] ) : array();
        if ( isset( $data['enabled'] ) && empty( $platforms ) ) {
            // Use defaults from settings
            $platforms = get_option( 'hashposter_default_platforms', array( 'facebook', 'linkedin', 'bluesky' ) );
            if ( ! is_array( $platforms ) ) {
                $platforms = array( 'facebook', 'linkedin', 'bluesky' );
            }
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[HashPoster] No platforms selected but enabled. Using defaults: ' . implode( ', ', $platforms ) );
            }
        }

        // Sanitize data
        $sanitized = array(
            'enabled' => isset( $data['enabled'] ) ? '1' : '0',
            'repost_on_update' => isset( $data['repost_on_update'] ) ? '1' : '0',
            'platforms' => $platforms,
            'custom_content' => isset( $data['custom_content'] ) ? sanitize_textarea_field( $data['custom_content'] ) : '',
            'schedule_time' => isset( $data['schedule_time'] ) ? sanitize_text_field( $data['schedule_time'] ) : '',
            'skip_featured_image' => isset( $data['skip_featured_image'] ) ? '1' : '0',
            'prefer_url_cards' => isset( $data['prefer_url_cards'] ) ? '1' : '0'
        );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[HashPoster] Saving meta for post ' . $post_id . ': enabled=' . $sanitized['enabled'] . ', platforms=' . implode( ', ', $sanitized['platforms'] ) );
        }

        update_post_meta( $post_id, '_hashposter_social_settings', $sanitized );
    }

    /**
     * Enqueue assets for post editor
     */
    public function enqueue_post_editor_assets( $hook ) {
        global $post;
        
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
            return;
        }

        // Enqueue post controls script
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

        // Enqueue meta box script
        wp_enqueue_script(
            'hashposter-meta-box',
            HASHPOSTER_URL . 'assets/js/meta-box.js',
            array( 'jquery' ),
            '1.0',
            true
        );

        wp_localize_script( 'hashposter-meta-box', 'hashposterMetaBox', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'post_id' => isset( $post->ID ) ? $post->ID : 0,
            'nonce' => wp_create_nonce( 'hashposter_preview' ),
            'strings' => array(
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
            'telegram' => 'Telegram',
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
     * NOTE: Uses fresh DB reads (not cached) since this runs in a different request context
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

        // Check if plugin is enabled (fresh DB read, not cached)
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

        // Get enabled platforms - fresh DB reads since this is a separate request
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

        // Check if per-post social posting is enabled (default to true if not set)
        $per_post_enabled = !isset( $post_social_settings['enabled'] ) || $post_social_settings['enabled'] === '1';

        if ( $per_post_enabled && ! empty( $post_social_settings['platforms'] ) && is_array( $post_social_settings['platforms'] ) ) {
            // Use per-post platform selection - validate credentials with API handler
            $api_handler = new HashPoster_API_Handler();
            foreach ( $post_social_settings['platforms'] as $platform ) {
                if ( $api_handler->validate_credentials( $platform ) ) {
                    $active_platforms[] = $platform;
                }
            }
        } else if ( $per_post_enabled ) {
            // Fallback to configured default platforms, then check enabled API credentials
            $api_handler = new HashPoster_API_Handler();
            
            // First, try to use the configured default platforms
            $default_platforms = get_option('hashposter_default_platforms', array('facebook', 'linkedin', 'bluesky'));
            if (is_array($default_platforms) && !empty($default_platforms)) {
                // Use configured default platforms
                foreach ($default_platforms as $platform) {
                    if ($api_handler->validate_credentials($platform)) {
                        $active_platforms[] = $platform;
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('[HashPoster] Using default platform ' . $platform);
                        }
                    }
                }
            } else {
                // Fallback to checking enabled API credentials if settings option is not set
                foreach ( [ 'facebook', 'linkedin', 'bluesky', 'telegram', 'x' ] as $platform ) {
                    // Skip platforms that aren't enabled - reduces validation overhead
                    if ( empty( $api_credentials[$platform]['active'] ) || $api_credentials[$platform]['active'] !== '1' ) {
                        continue;
                    }

                    // Only validate enabled platforms
                    if ( $api_handler->validate_credentials( $platform ) ) {
                        $active_platforms[] = $platform;
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[HashPoster] Platform ' . $platform . ' is active' );
                        }
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