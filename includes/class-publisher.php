<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_Publisher {
    private $api_handler;

    public function __construct() {
        $this->api_handler = new HashPoster_API_Handler();
    }

    /**
     * Publish a single post to multiple platforms using the central pipeline
     */
    public function publish_post_to_platforms( $post, $platforms, $custom_content = '' ) {
        $results = array();
        $all_success = true;

        foreach ( $platforms as $platform ) {
            try {
                // Use internal content preparation logic (previously in HashPoster_Content_Pipeline)
                $content_data = $this->prepare_content_pipeline( $post, $platform, $custom_content );

                error_log( '[HashPoster Publisher] Publishing to ' . $platform . ' - Content length: ' . strlen( $content_data['content'] ) );

                // Media uploads are disabled plugin-wide; publish only the prepared content
                // Provide pipeline url metadata so API handlers can attach link metadata when needed
                // Pass only the content. Media uploads and URL metadata are intentionally not passed.
                $media_payload = $content_data['media'] ?? array();
                $response = $this->api_handler->publish_to_platform( $platform, $content_data['content'], $media_payload );

                // The API handler may return a structured array describing an error; normalize it to WP_Error
                if ( is_array( $response ) && isset( $response['error_info'] ) ) {
                    $msg = $response['user_message'] ?? json_encode( $response['error_info'] );
                    $response = new WP_Error( 'api_handler_error', $msg, $response );
                }

                if ( is_wp_error( $response ) ) {
                    $msg = $response->get_error_message();
                    error_log( '[HashPoster Publisher] Publish failed for ' . $platform . ': ' . $msg );
                    $results[ $platform ] = array( 'success' => false, 'message' => $msg );
                    
                    // Track failed publication for analytics
                    if ( function_exists( 'do_action' ) ) {
                        do_action( 'hashposter_post_published', $post->ID, $platform, false );
                    }
                    $all_success = false;
                } else {
                    error_log( '[HashPoster Publisher] Publish succeeded for ' . $platform );
                    $results[ $platform ] = array( 'success' => true, 'message' => 'Published successfully' );
                    
                    // Track successful publication for analytics
                    if ( function_exists( 'do_action' ) ) {
                        do_action( 'hashposter_post_published', $post->ID, $platform, true );
                    }
                }

            } catch ( Exception $e ) {
                $results[ $platform ] = array( 'success' => false, 'message' => $e->getMessage() );
                
                // Track failed publication for analytics
                if ( function_exists( 'do_action' ) ) {
                    do_action( 'hashposter_post_published', $post->ID, $platform, false );
                }
                
                $all_success = false;
            }
        }

        return array( 'success' => $all_success, 'platforms' => $results );
    }

    /**
     * Get URL card preview (migrated public method)
     */
    public function get_url_card_preview( $post_id, $platform = 'all' ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array( 'error' => 'Post not found' );
        }

        return array(
            'title' => get_the_title( $post ),
            'description' => $this->get_optimized_description( $post, 200 ),
            'url' => get_permalink( $post_id ),
            'image' => $this->get_featured_image_url( $post_id ),
            'site_name' => get_bloginfo( 'name' ),
            'platform' => $platform,
        );
    }

    /**
     * Get optimized description (migrated)
     */
    public function get_optimized_description( $post, $max_words = 200 ) {
        // Merged logic from HashPoster_Content_Pipeline::get_optimized_description
        $post_id = $post->ID;
        if ( function_exists( 'WPSEO_Meta' ) ) {
            $yoast_description = WPSEO_Meta::get_value( 'metadesc', $post_id );
            if ( ! empty( $yoast_description ) ) {
                return wp_trim_words( $yoast_description, $max_words );
            }
        }

        if ( function_exists( 'rank_math_get_meta' ) ) {
            $rankmath_description = rank_math_get_meta( 'description', $post_id );
            if ( ! empty( $rankmath_description ) ) {
                return wp_trim_words( $rankmath_description, $max_words );
            }
        }

        $description = get_the_excerpt( $post );
        if ( empty( $description ) ) {
            $description = wp_trim_words( strip_shortcodes( strip_tags( $post->post_content ) ), $max_words );
        } else {
            $description = wp_trim_words( $description, $max_words );
        }

        if ( empty( $description ) ) {
            $description = get_bloginfo( 'description' );
        }

        return $description;
    }

    /**
     * Get optimized title (migrated)
     */
    public function get_optimized_title( $post ) {
        $post_id = $post->ID;
        if ( function_exists( 'WPSEO_Meta' ) ) {
            $yoast_title = WPSEO_Meta::get_value( 'title', $post_id );
            if ( ! empty( $yoast_title ) ) {
                return $yoast_title;
            }
        }

        if ( function_exists( 'rank_math_get_meta' ) ) {
            $rankmath_title = rank_math_get_meta( 'title', $post_id );
            if ( ! empty( $rankmath_title ) ) {
                return $rankmath_title;
            }
        }

        return get_the_title( $post );
    }

    /**
     * Helper: get featured image URL (migrated)
     */
    private function get_featured_image_url( $post_id ) {
        if ( has_post_thumbnail( $post_id ) ) {
            $image_id = get_post_thumbnail_id( $post_id );
            $image_url = wp_get_attachment_image_url( $image_id, 'large' );
            if ( ! $image_url ) {
                $image_url = wp_get_attachment_image_url( $image_id, 'full' );
            }
            return $image_url;
        }
        return false;
    }

    /**
     * Minimal content preparation ported from content pipeline (keeps behavior needed by publisher)
     */
    public function prepare_content_pipeline( $post, $platform, $custom_content = '' ) {
        // Platform-specific content preparation
        $url = $this->get_url_for_platform( $post->ID, $platform );

        switch ( $platform ) {
            case 'x':
                // For X: Title + excerpt + full URL (same format as LinkedIn/Bluesky)
                $title = $this->get_optimized_title( $post );
                $excerpt = $this->get_limited_excerpt( $post, 30 ); // Brief excerpt to fit in 280 chars
                $hashtag = $this->get_post_hashtag( $post );
                
                // Build content with hashtag at the end
                $content = $title . "\n\n" . $excerpt . "\n\n" . $url;
                if ( ! empty( $hashtag ) ) {
                    $content .= " " . $hashtag;
                }
                
                return array( 'content' => $content, 'media' => array( 'url' => $url ), 'url_card_meta' => '', 'url' => $url );
                break;

            case 'bluesky':
                // For Bluesky: Title + line break + first paragraph (brief summary) + hashtag
                $content = $this->prepare_bluesky_content( $post, $url );
                $hashtag = $this->get_post_hashtag( $post );
                
                // Add hashtag at the end
                if ( ! empty( $hashtag ) ) {
                    $content .= " " . $hashtag;
                }
                
                // Pass post title and description in media for embed card
                $media = array(
                    'url' => $url,
                    'title' => $this->get_optimized_title( $post ),
                    'description' => $this->get_optimized_description( $post, 200 ),
                    'thumb' => $this->get_featured_image_url( $post->ID )
                );
                return array( 'content' => $content, 'media' => $media, 'url_card_meta' => '', 'url' => $url );
                break;

            case 'facebook':
                // For Facebook: First paragraph only + URL in media for card
                $content = $this->prepare_first_paragraph_content( $post, $url );
                $hashtag = $this->get_post_hashtag( $post );
                
                // Add hashtag at the end
                if ( ! empty( $hashtag ) ) {
                    $content .= " " . $hashtag;
                }
                
                return array( 'content' => $content, 'media' => array( 'url' => $url ), 'url_card_meta' => '', 'url' => $url );
                break;

            case 'linkedin':
                // For LinkedIn: First paragraph ONLY (no URL in text)
                // URL will be added via content.contentEntities for rich OG preview card
                $content = $this->prepare_first_paragraph_content( $post, $url );
                // DO NOT add URL to text - it will be in the preview card via contentEntities
                // $content = rtrim( $content ) . "\n\n" . $url; // REMOVED - causes lnkd.in shortlink
                $hashtag = $this->get_post_hashtag( $post );
                
                // Add hashtag at the end
                if ( ! empty( $hashtag ) ) {
                    $content .= " " . $hashtag;
                }
                
                return array( 'content' => $content, 'media' => array( 'url' => $url ), 'url_card_meta' => '', 'url' => $url );
                break;

            default:
                // Fallback to template-based approach for other platforms
                $post_cards = get_option( 'hashposter_post_cards', array() );
                $platform_template_key = $platform . '_template';
                $template = $post_cards[ $platform_template_key ] ?? $post_cards['template'] ?? '{title} {excerpt} {short_url}';

                if ( ! empty( $custom_content ) ) {
                    $content = $this->replace_placeholders( $custom_content, $post );
                } else {
                    $content = $this->replace_placeholders( $template, $post );
                }

                $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $content = preg_replace( '/&[a-zA-Z0-9#]+;/', '', $content );

                if ( strpos( $content, '{short_url}' ) !== false ) {
                    $content = str_replace( '{short_url}', $url, $content );
                }

                // Append URL inline for platforms that need it
                $needs_url_in_body = in_array( $platform, array( 'bluesky', 'x', 'linkedin' ), true );
                if ( $needs_url_in_body && strpos( $content, 'http://' ) === false && strpos( $content, 'https://' ) === false ) {
                    $content = rtrim( $content ) . ' ' . $url;
                }
                break;
        }

        return array( 'content' => $content, 'media' => array(), 'url_card_meta' => '', 'url' => $url );
    }

    /**
     * Prepare meta description content for X and Bluesky (Yoast/SEO meta description + URL)
     */
    private function prepare_meta_description_content( $post, $url ) {
        // Get Yoast/SEO meta description or fallback to excerpt
        $content = $this->get_optimized_description( $post, 150 );

        // Clean up and format
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $content = strip_tags( $content );
        $content = trim( $content );

        // For X: Add URL at the end for link preview/card
        $content = rtrim( $content ) . "\n\n" . $url;

        return $content;
    }

    /**
     * Prepare Bluesky-specific content: Title + line break + first paragraph (brief summary)
     */
    private function prepare_bluesky_content( $post, $url ) {
        // Get the title and decode HTML entities
        $title = $this->get_optimized_title( $post );
        $title = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $title = strip_tags( $title );
        $title = trim( $title );
        
        // Get first paragraph from post content
        $first_paragraph = $this->get_first_paragraph( $post );
        
        // Truncate paragraph to keep it brief (about 120 chars to leave room for title, URL card, and hashtag)
        if ( strlen( $first_paragraph ) > 120 ) {
            $first_paragraph = wp_trim_words( $first_paragraph, 20, '...' );
        }
        
        // Clean up and decode HTML entities
        $first_paragraph = html_entity_decode( $first_paragraph, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $first_paragraph = strip_tags( $first_paragraph );
        $first_paragraph = trim( $first_paragraph );
        
        // Format: Title + line break + paragraph
        // Don't include URL in text - it will be in the embed card
        $content = $title . "\n\n" . $first_paragraph;
        
        return $content;
    }

    /**
     * Prepare first paragraph content for Facebook and LinkedIn (first paragraph only)
     */
    private function prepare_first_paragraph_content( $post, $url ) {
        // Get the first paragraph from post content
        $content = $this->get_first_paragraph( $post );

        // Clean up and format
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $content = trim( $content );

        return $content;
    }

    /**
     * Extract the first paragraph from post content
     */
    private function get_first_paragraph( $post ) {
        // Get the full content
        $content = get_the_content( null, false, $post );

        // Remove shortcodes
        $content = strip_shortcodes( $content );

        // Convert to HTML with proper paragraphs
        $content = wpautop( $content );

        // Extract first paragraph
        preg_match( '/<p[^>]*>(.*?)<\/p>/s', $content, $matches );
        
        if ( ! empty( $matches[1] ) ) {
            $first_paragraph = strip_tags( $matches[1] );
            $first_paragraph = trim( $first_paragraph );
            
            // If first paragraph is too short, try to get more content
            if ( str_word_count( $first_paragraph ) < 20 ) {
                // Get up to 3 paragraphs if first is too short
                preg_match_all( '/<p[^>]*>(.*?)<\/p>/s', $content, $all_matches );
                if ( ! empty( $all_matches[1] ) ) {
                    $paragraphs = array_slice( $all_matches[1], 0, 3 );
                    $first_paragraph = strip_tags( implode( "\n\n", $paragraphs ) );
                    $first_paragraph = trim( $first_paragraph );
                }
            }
            
            return $first_paragraph;
        }

        // Fallback to excerpt if no paragraphs found
        $excerpt = get_the_excerpt( $post );
        if ( ! empty( $excerpt ) ) {
            return $excerpt;
        }

        // Final fallback to trimmed content
        $content = strip_tags( $content );
        return wp_trim_words( $content, 50, '...' );
    }



    private function replace_placeholders( $content, $post ) {
        $url = get_permalink( $post );

        $replacements = array(
            '{title}' => $this->get_optimized_title( $post ),
            '{url}' => $url,
            '{short_url}' => $url,
            '{excerpt}' => $this->get_limited_excerpt( $post, 200 ),
            '{date}' => get_the_date( '', $post ),
            '{author}' => get_the_author_meta( 'display_name', $post->post_author ),
            '{site_name}' => get_bloginfo( 'name' )
        );

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $content );
    }

    /**
     * Get one hashtag from post tags
     * @param WP_Post $post The post object
     * @return string Single hashtag with # prefix, or empty string if no tags
     * 
     * Format rules:
     * - X (Twitter): Supports alphanumeric and underscores
     * - LinkedIn: Supports alphanumeric and underscores
     * - Facebook: Supports alphanumeric and underscores
     * - Bluesky: Supports alphanumeric and underscores (parsed via facets)
     */
    private function get_post_hashtag( $post ) {
        $tags = get_the_tags( $post->ID );
        
        if ( empty( $tags ) || is_wp_error( $tags ) ) {
            return '';
        }
        
        // Get first tag
        $tag = reset( $tags );
        $hashtag = $tag->name;
        
        // Remove spaces and special characters, keep alphanumeric and underscores
        // All platforms support this format
        $hashtag = preg_replace( '/[^a-zA-Z0-9_]/', '', $hashtag );
        
        // Remove leading/trailing underscores
        $hashtag = trim( $hashtag, '_' );
        
        // Return with # prefix if not empty
        return ! empty( $hashtag ) ? '#' . $hashtag : '';
    }

    private function get_limited_excerpt( $post, $word_limit = 200 ) {
        $excerpt = get_the_excerpt( $post );
        if ( empty( $excerpt ) ) {
            $excerpt = wp_trim_words( get_the_content( $post ), $word_limit, '...' );
        } else {
            $excerpt = wp_trim_words( $excerpt, $word_limit, '...' );
        }
        return $excerpt;
    }

    private function get_url_for_platform( $post_id, $platform ) {
        // LinkedIn MUST use full permalink for proper link scraping - NEVER use shortlinks
        if ( $platform === 'linkedin' ) {
            $permalink = get_permalink( $post_id );
            
            // Debug: Log the initial permalink we get
            error_log( '[HashPoster LinkedIn URL DEBUG] Initial permalink from get_permalink(): ' . $permalink );
            
            // Ensure we have a proper permalink structure - if it's ugly (?p=123), try to force pretty
            if ( strpos( $permalink, '?p=' ) !== false ) {
                $post = get_post( $post_id );
                if ( $post && !empty( $post->post_name ) ) {
                    $home_url = home_url( '/' );
                    $permalink = rtrim( $home_url, '/' ) . '/' . $post->post_name . '/';
                    error_log( '[HashPoster LinkedIn URL DEBUG] Reconstructed pretty permalink: ' . $permalink );
                }
            }
            
            error_log( '[HashPoster LinkedIn URL DEBUG] FINAL permalink for LinkedIn (NO SHORTLINKS): ' . $permalink );
            return $permalink;
        }
        
        // For other platforms, allow shortlinks
        $shortlinks = get_option('hashposter_shortlinks', array());
        $use_bitly = !empty($shortlinks['bitly']['active']) && !empty($shortlinks['bitly']['token']);
        $use_wordpress = !empty($shortlinks['wordpress']['active']);

        // Always start with the canonical permalink (pretty URL format)
        $permalink = get_permalink( $post_id );

        // Ensure we have a proper permalink structure - if it's ugly (?p=123), try to force pretty
        if ( strpos( $permalink, '?p=' ) !== false ) {
            // If permalink structure gives ugly URL, try to get the post slug and reconstruct
            $post = get_post( $post_id );
            if ( $post && !empty( $post->post_name ) ) {
                $home_url = home_url( '/' );
                $permalink = rtrim( $home_url, '/' ) . '/' . $post->post_name . '/';
            }
        }

        if ( $use_bitly ) {
            $api_handler = new HashPoster_API_Handler();
            $short_url = $api_handler->get_short_url( $permalink, $post_id );
            if ( $short_url && $short_url !== $permalink ) {
                return $short_url;
            }
        }

        if ( $use_wordpress ) {
            $wp_shortlink = wp_get_shortlink( $post_id );
            // Only use WordPress shortlink if it's actually shorter and not ugly
            if ( $wp_shortlink && $wp_shortlink !== $permalink && strpos( $wp_shortlink, '?p=' ) === false ) {
                return $wp_shortlink;
            }
        }

        return $permalink;
    }
}