<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_Post_Publisher {
    public function __construct() {
        add_action( 'transition_post_status', array( $this, 'maybe_publish_to_platforms' ), 10, 3 );
        add_action( 'hashposter_scheduled_post', array( $this, 'process_scheduled_post' ) );
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

        // Get enabled platforms
        $api_credentials = get_option('hashposter_api_credentials', array());
        $active_platforms = array();
        foreach ($api_credentials as $platform => $creds) {
            if (!empty($creds['active'])) {
                $active_platforms[] = $platform;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] Active platforms: ' . implode(', ', $active_platforms));
        }

        if (empty($active_platforms)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] No active platforms, nothing to autopost.');
            }
            return;
        }

        // Check scheduling
        if (!empty($settings['scheduling']) && !empty($settings['delay_minutes'])) {
            $delay_minutes = intval($settings['delay_minutes']);
            if ($delay_minutes > 0) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[HashPoster] Scheduling post ' . $post->ID . ' for autopost in ' . $delay_minutes . ' minutes');
                }
                wp_schedule_single_event(
                    time() + ($delay_minutes * 60),
                    'hashposter_scheduled_post',
                    array($post->ID)
                );
                
                // Mark as scheduled
                update_post_meta($post->ID, '_hashposter_scheduled', current_time('mysql'));
                return;
            }
        }

        $this->do_post_to_platforms($post->ID, $post);
    }

    /**
     * Process a scheduled post
     * 
     * @param int $post_id Post ID to process
     */
    public function process_scheduled_post($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Cannot process scheduled post. Post not found or not published: ' . $post_id);
            }
            return;
        }

        // Check if already posted (could happen if cron runs multiple times)
        if (get_post_meta($post_id, '_hashposter_autoposted', true)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Scheduled post already processed: ' . $post_id);
            }
            return;
        }

        $this->do_post_to_platforms($post_id, $post);
    }

    /**
     * Actually post to all enabled platforms
     * 
     * @param int $post_id Post ID
     * @param object $post Post object
     */
    private function do_post_to_platforms($post_id, $post) {
        // Prepare content for posting
        $content = $this->prepare_content($post_id, $post);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] Prepared content for post ID ' . $post_id . ': ' . $content);
        }
        
        // Prepare media array with post data
        $media = ['post_id' => $post_id];
        
        // Check if post has a featured image
        if (has_post_thumbnail($post_id)) {
            $thumbnail_id = get_post_thumbnail_id($post_id);
            $media['image'] = wp_get_attachment_url($thumbnail_id);
            $media['image_path'] = get_attached_file($thumbnail_id);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Found featured image: ' . $media['image']);
                error_log('[HashPoster] Image path: ' . ($media['image_path'] ?? 'Not found'));
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] No featured image found for post ID ' . $post_id);
            }
        }

        // Get enabled platforms
        $api_credentials = get_option('hashposter_api_credentials', array());
        $active_platforms = array();
        foreach ($api_credentials as $platform => $creds) {
            if (!empty($creds['active'])) {
                $active_platforms[] = $platform;
            }
        }

        // Publish to each active platform
        $api = new HashPoster_API_Handler();
        $all_success = true;
        $results = [];
        
        foreach ($active_platforms as $platform) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Attempting to post to ' . $platform);
            }
            
            $result = $api->publish_to_platform($platform, $content, $media);
            $results[$platform] = $result;
            
            if ($result === true) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[HashPoster] Successfully posted to ' . $platform);
                }
            } else {
                $all_success = false;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $error_msg = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
                    error_log('[HashPoster] Failed to post to ' . $platform . ': ' . $error_msg);
                }
            }
        }

        // Mark post as autoposted
        update_post_meta($post_id, '_hashposter_autoposted', current_time('mysql'));
        
        // Store detailed results
        update_post_meta($post_id, '_hashposter_results', $results);
        
        return $all_success;
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
                $data['short_url'] = wp_get_shortlink($post_id) ?: $permalink;
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
}
