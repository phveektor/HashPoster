<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_API_Handler {
    private $platforms = array( 'x', 'linkedin', 'bluesky', 'facebook' );
    private $shorteners = array( 'wordpress', 'bitly' );
    private $credentials = array();

    /**
     * Error types and their recovery strategies
     */
    private $error_types = array(
        'authentication' => array(
            'description' => 'Authentication or authorization errors',
            'recovery' => array('retry_with_new_token', 'reconnect_account', 'check_permissions')
        ),
        'rate_limit' => array(
            'description' => 'API rate limit exceeded',
            'recovery' => array('wait_and_retry', 'reduce_frequency', 'upgrade_plan')
        ),
        'network' => array(
            'description' => 'Network connectivity issues',
            'recovery' => array('retry_request', 'check_connectivity', 'use_proxy')
        ),
        'content' => array(
            'description' => 'Content validation or formatting errors',
            'recovery' => array('fix_content', 'truncate_content', 'skip_post')
        ),
        'api' => array(
            'description' => 'General API errors',
            'recovery' => array('retry_request', 'check_api_status', 'contact_support')
        )
    );

    public function __construct() {
        $this->initialize();
    }

    public function initialize() {
        $this->credentials = get_option( 'hashposter_api_credentials', array() );
    }

    public function validate_credentials( $platform ) {
        // For X (Twitter), check OAuth 1.0a access tokens
        if ( $platform === 'x' ) {
            $oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
            if ( ! empty( $oauth_tokens['x'] ) && ! empty( $oauth_tokens['x']['access_token'] ) && ! empty( $oauth_tokens['x']['access_token_secret'] ) ) {
                // Test the tokens by making a simple API call
                $credentials = get_option( 'hashposter_api_credentials', array() );
                $consumer_key = $credentials['x']['client_id'] ?? '';
                $consumer_secret = $credentials['x']['client_secret'] ?? '';
                
                if ( !empty($consumer_key) && !empty($consumer_secret) ) {
                    require_once HASHPOSTER_PATH . 'includes/twitteroauth/autoload.php';
                    try {
                        $connection = new Abraham\TwitterOAuth\TwitterOAuth(
                            $consumer_key, 
                            $consumer_secret,
                            $oauth_tokens['x']['access_token'],
                            $oauth_tokens['x']['access_token_secret']
                        );
                        
                        // Test with a simple API call to verify credentials (v1.1 API)
                        $test_result = $connection->get('account/verify_credentials');
                        
                        if ( isset($test_result->id) ) {
                            error_log('[HashPoster DEBUG] X validation - OAuth 1.0a tokens valid and working');
                            return true;
                        } else {
                            error_log('[HashPoster DEBUG] X validation - OAuth 1.0a tokens exist but API test failed: ' . print_r($test_result, true));
                            return false;
                        }
                    } catch (Exception $e) {
                        error_log('[HashPoster DEBUG] X validation - OAuth 1.0a tokens exist but API test exception: ' . $e->getMessage());
                        return false;
                    }
                }
                
                error_log('[HashPoster DEBUG] X validation - OAuth 1.0a tokens exist but missing consumer credentials');
                return false;
            }

            error_log('[HashPoster DEBUG] X validation - No valid OAuth 1.0a tokens found');
            return false;
        }
        
        // For other platforms, check OAuth tokens first
        $oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
        if ( ! empty( $oauth_tokens[ $platform ] ) && ! empty( $oauth_tokens[ $platform ]['access_token'] ) ) {
            // Check if token is not expired
            if ( empty( $oauth_tokens[ $platform ]['expires_at'] ) || $oauth_tokens[ $platform ]['expires_at'] > time() ) {
                return true;
            }
        }

        // Fallback to manual credentials
        $this->credentials = get_option( 'hashposter_api_credentials', array() );
        $creds = $this->credentials[ $platform ] ?? array();
        switch ($platform) {
            case 'linkedin':
                return !empty($creds['access_token']) || (!empty($creds['client_id']) && !empty($creds['client_secret']));
            case 'bluesky':
                $has_handle = isset($creds['handle']) && trim($creds['handle']) !== '';
                $has_password = !empty($creds['app_password']) || !empty($creds['password']);
                error_log('[HashPoster DEBUG] Bluesky validation - handle: ' . ($has_handle ? 'YES' : 'NO') . ', app_password: ' . ($has_password ? 'YES' : 'NO'));
                return $has_handle && $has_password;
            case 'facebook':
                $has_app_id = !empty($creds['app_id']);
                $has_app_secret = !empty($creds['app_secret']);
                $has_page_id = !empty($creds['page_id']);
                $has_access_token = !empty($creds['access_token']);
                error_log('[HashPoster DEBUG] Facebook validation - app_id: ' . ($has_app_id ? 'YES' : 'NO') . ', app_secret: ' . ($has_app_secret ? 'YES' : 'NO') . ', page_id: ' . ($has_page_id ? 'YES' : 'NO') . ', access_token: ' . ($has_access_token ? 'YES' : 'NO'));
                return $has_app_id && $has_app_secret && $has_page_id && $has_access_token;
            default:
                return false;
        }
    }

    // --- Platform-specific posting methods ---

    /**
     * Generic dispatcher used by other classes to publish to a named platform
     * @param string $platform Platform key (x, linkedin, bluesky, etc.)
     * @param string $content The content to publish
     * @param array $media Optional media array
     * @return true|WP_Error
     */
    public function publish_to_platform( $platform, $content, $media = array() ) {
        $platform = strtolower( $platform );
        if ( ! in_array( $platform, $this->platforms ) ) {
            return new WP_Error( 'unsupported_platform', 'Platform ' . $platform . ' is not supported by HashPoster.' );
        }

        // Normalize media parameter: allow integer post_id or string image path
        if ( is_array( $media ) ) {
            // already normalized by caller (may include 'url' or other keys) - keep as-is
        } elseif ( is_int( $media ) || ( is_string( $media ) && ctype_digit( $media ) ) ) {
            $media = array( 'post_id' => intval( $media ) );
        } elseif ( is_string( $media ) ) {
            $media = array( 'image' => $media );
        } else {
            $media = array();
        }

        switch ( $platform ) {
            case 'x':
                $res = $this->publish_to_x( $content, $media );
                if ( is_array( $res ) && isset( $res['error_info'] ) ) {
                    return new WP_Error( $res['error_info']['code'] ?? 'api_error', $res['user_message'] ?? json_encode( $res['error_info'] ), $res );
                }
                return $res;
            case 'facebook':
                $res = $this->publish_to_facebook( $content, $media );
                if ( is_array( $res ) && isset( $res['error_info'] ) ) {
                    return new WP_Error( $res['error_info']['code'] ?? 'api_error', $res['user_message'] ?? json_encode( $res['error_info'] ), $res );
                }
                return $res;
            case 'linkedin':
                $res = $this->publish_to_linkedin( $content, $media );
                if ( is_array( $res ) && isset( $res['error_info'] ) ) {
                    return new WP_Error( $res['error_info']['code'] ?? 'api_error', $res['user_message'] ?? json_encode( $res['error_info'] ), $res );
                }
                return $res;
            case 'bluesky':
                $res = $this->publish_to_bluesky( $content, $media );
                if ( is_array( $res ) && isset( $res['error_info'] ) ) {
                    return new WP_Error( $res['error_info']['code'] ?? 'api_error', $res['user_message'] ?? json_encode( $res['error_info'] ), $res );
                }
                return $res;
            default:
                return new WP_Error( 'unsupported_platform', 'No publish implementation for platform: ' . $platform );
        }
    }


    private function publish_to_x( $content, $media ) {
        error_log('[HashPoster] Starting X (Twitter) publish attempt via OAuth 1.0a (v1.1 API)');
        
        // Check for OAuth 1.0a access tokens
        $oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
        if ( empty( $oauth_tokens['x'] ) || empty( $oauth_tokens['x']['access_token'] ) || empty( $oauth_tokens['x']['access_token_secret'] ) ) {
            error_log('[HashPoster] X: No OAuth 1.0a access tokens found');
            return new WP_Error('missing_credentials', 
                'X (Twitter) is not connected. Please connect your X account in HashPoster settings using OAuth.'
            );
        }

        $access_token = $oauth_tokens['x']['access_token'];
        $access_token_secret = $oauth_tokens['x']['access_token_secret'];
        error_log('[HashPoster] X: Using OAuth 1.0a authentication');

        // Get credentials
        $credentials = $this->credentials['x'] ?? array();
        $consumer_key = $credentials['client_id'] ?? '';
        $consumer_secret = $credentials['client_secret'] ?? '';

        if ( empty( $consumer_key ) || empty( $consumer_secret ) ) {
            error_log('[HashPoster] X: Missing consumer credentials');
            return new WP_Error('missing_credentials', 'X consumer credentials not configured');
        }

        // Include TwitterOAuth library
        require_once HASHPOSTER_PATH . 'includes/twitteroauth/autoload.php';
        error_log('[HashPoster] X: TwitterOAuth class exists: ' . (class_exists('Abraham\TwitterOAuth\TwitterOAuth') ? 'YES' : 'NO'));

        try {
            $connection = new Abraham\TwitterOAuth\TwitterOAuth(
                $consumer_key,
                $consumer_secret,
                $access_token,
                $access_token_secret
            );
            error_log('[HashPoster] X: TwitterOAuth connection created');

            // Clean content
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            // Check content length (Twitter limit is 280 characters)
            if (strlen($content) > 280) {
                error_log('[HashPoster] X: Content too long (' . strlen($content) . ' chars), truncating to 280');
                $content = substr($content, 0, 277) . '...';
            }
            
            // For X/Twitter: Ensure URL is at the end for card generation, but don't duplicate it
            // Twitter cards require the URL to be in the tweet text
            error_log('[HashPoster] X: Content before URL processing: ' . substr($content, 0, 200) . '...');

            // Prepare tweet data for v1.1 API
            $tweet_data = array(
                'status' => $content
            );

            // Handle media if provided
            if ( !empty($media) && is_array($media) ) {
                error_log('[HashPoster] X: Media provided but X API v1.1 media upload not yet implemented - skipping media');
                // TODO: Implement X API v1.1 media upload
                // For now, skip media to test basic posting functionality
            }

            // Post tweet using OAuth 1.0a with v1.1 API
            error_log('[HashPoster] X: Sending POST request via OAuth 1.0a to v1.1 API');
            error_log('[HashPoster] X: Tweet data: ' . print_r($tweet_data, true));
            
            $start_time = microtime(true);
            $result = $connection->post('statuses/update', $tweet_data);
            $end_time = microtime(true);
            $request_time = round(($end_time - $start_time) * 1000);
            
            error_log('[HashPoster] X: HTTP status: ' . $connection->getLastHttpCode());
            error_log('[HashPoster] X: API request completed in ' . $request_time . 'ms');
            error_log('[HashPoster] X: API response: ' . print_r($result, true));
            
            if (empty($result)) {
                error_log('[HashPoster] X ERROR: Empty response from API');
                return new WP_Error('api_error', 'X API error: Empty response from API');
            }

            // Check for Twitter API errors (v1.1 format)
            if ( isset($result->errors) ) {
                $error_message = 'X API error: ';
                foreach ( $result->errors as $error ) {
                    $error_message .= ($error->message ?? 'Unknown error') . ' ';
                }
                error_log('[HashPoster] X ERROR: ' . $error_message);
                return new WP_Error('api_error', $error_message);
            }

            // Check for successful response (v1.1 API)
            if ( isset($result->id_str) ) {
                $tweet_id = $result->id_str;
                error_log('[HashPoster] X SUCCESS: Tweet posted! ID: ' . $tweet_id);
                return true;
            }

            // Handle unexpected response
            $error_message = 'X API error: Unexpected response format';
            error_log('[HashPoster] X ERROR: ' . $error_message . ' - Response: ' . print_r($result, true));
            return new WP_Error('api_error', $error_message);

        } catch (Exception $e) {
            $error_message = 'X API request failed: ' . $e->getMessage();
            error_log('[HashPoster] X ERROR: ' . $error_message);
            return new WP_Error('api_error', $error_message);
        }
    }

    /**
     * Upload media to X (Twitter) using OAuth 1.0a
     */
    private function upload_x_media( $connection, $media_url ) {
        try {
            // Download media to temporary file using WordPress function
            $tmp_file = download_url( $media_url );
            if ( is_wp_error( $tmp_file ) ) {
                throw new Exception( 'Failed to download media: ' . $tmp_file->get_error_message() );
            }

            // Upload to Twitter using file path
            $result = $connection->upload( 'media/upload', array( 'media' => $tmp_file ) );
            
            error_log('[HashPoster] X media upload result: ' . print_r($result, true));

            // Clean up temporary file
            @unlink( $tmp_file );

            // Check for upload errors
            if ( isset($result->errors) ) {
                $error_message = 'Twitter media upload failed: ';
                foreach ( $result->errors as $error ) {
                    $error_message .= ($error->message ?? 'Unknown error') . ' ';
                }
                throw new Exception( $error_message );
            }

            if ( isset( $result->media_id_string ) ) {
                return $result->media_id_string;
            } else {
                throw new Exception( 'Twitter media upload failed: Invalid response format - ' . print_r( $result, true ) );
            }
        } catch ( Exception $e ) {
            error_log( '[HashPoster] X media upload error: ' . $e->getMessage() );
            return false;
        }
    }

    private function publish_to_facebook( $content, $media ) {
        // Always try manual credentials first (more reliable)
        $manual_creds = $this->credentials['facebook'] ?? array();
        $oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
        
        // Prefer manual credentials if both access_token and page_id are present
        if ( !empty($manual_creds['access_token']) && !empty($manual_creds['page_id']) ) {
            $creds = $manual_creds;
            error_log('[HashPoster DEBUG] Facebook using manual credentials (preferred)');
        } elseif ( ! empty( $oauth_tokens['facebook'] ) && ! empty( $oauth_tokens['facebook']['access_token'] ) ) {
            $creds = $oauth_tokens['facebook'];
            error_log('[HashPoster DEBUG] Facebook using OAuth tokens (fallback)');
        } else {
            $creds = $manual_creds; // Use manual even if incomplete for better error messaging
            error_log('[HashPoster DEBUG] Facebook using manual credentials (default)');
        }

        // Debug logging to see what credentials we have
        error_log('[HashPoster DEBUG] Facebook credentials - access_token present: ' . (!empty($creds['access_token']) ? 'YES' : 'NO'));
        error_log('[HashPoster DEBUG] Facebook credentials - page_id present: ' . (!empty($creds['page_id']) ? 'YES' : 'NO'));
        error_log('[HashPoster DEBUG] Facebook credentials keys: ' . implode(', ', array_keys($creds ?? array())));

        if (empty($creds['access_token']) || empty($creds['page_id'])) {
            return $this->handle_error(new WP_Error('missing_credentials', 'Missing Facebook credentials (access token and page ID required). Please connect via OAuth or fill in manual credentials.'), 'facebook');
        }

        // First, verify the access token is still valid
        $verify_endpoint = 'https://graph.facebook.com/me?fields=id,name&access_token=' . $creds['access_token'];
        $verify_response = wp_remote_get($verify_endpoint, array('timeout' => 15));

        if (is_wp_error($verify_response)) {
            return $this->handle_error(new WP_Error('token_verification_failed', 'Facebook token verification failed: ' . $verify_response->get_error_message()), 'facebook');
        }
        
        $verify_code = wp_remote_retrieve_response_code($verify_response);
        if ($verify_code !== 200) {
            $verify_body = wp_remote_retrieve_body($verify_response);
            $error_data = json_decode($verify_body, true);

            if (isset($error_data['error']['message'])) {
                $error_message = $error_data['error']['message'];

                // Handle specific Facebook token errors
                if (strpos($error_message, 'session is invalid') !== false ||
                    strpos($error_message, 'Invalid OAuth access token') !== false ||
                    strpos($error_message, 'Error validating access token') !== false) {
                    return $this->handle_error(new WP_Error('token_expired',
                        'Facebook access token has expired or is invalid. Please generate a new Page Access Token from Facebook Developers Console and update it in the plugin settings.'), 'facebook');
                }

                if (strpos($error_message, 'permissions') !== false) {
                    return $this->handle_error(new WP_Error('insufficient_permissions',
                        'Facebook app lacks required permissions. Please ensure your Facebook app has the following permissions: pages_manage_posts, pages_show_list, publish_pages.'), 'facebook');
                }

                return $this->handle_error(new WP_Error('token_invalid', 'Facebook access token is invalid: ' . $error_message), 'facebook');
            }

            return $this->handle_error(new WP_Error('token_verification_failed', 'Facebook token verification failed: ' . $verify_body), 'facebook');
        }

        $endpoint = 'https://graph.facebook.com/' . $creds['page_id'] . '/feed';
        $body = array(
            'message' => $content,
            'access_token' => $creds['access_token'],
        );

        // Image uploads disabled: rely on URL cards and link preview
        // Prefer the pipeline-provided url metadata (passed in media['url']) so the URL is attached as metadata
        if (!empty($media['url'])) {
            $body['link'] = $media['url'];
        } elseif (preg_match('/https?:\/\/[^\s]+/', $content, $matches)) {
            $body['link'] = $matches[0];
        }

        $args = array(
            'body'    => $body,
            'timeout' => 15,
        );

        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) {
            return $this->handle_error(new WP_Error('api_error', 'Facebook API connection failed: ' . $response->get_error_message()), 'facebook');
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $error_data = json_decode($response_body, true);

            if (isset($error_data['error']['message'])) {
                $error_message = $error_data['error']['message'];

                // Handle specific posting errors
                if (strpos($error_message, 'session is invalid') !== false ||
                    strpos($error_message, 'Invalid OAuth access token') !== false) {
                    return $this->handle_error(new WP_Error('token_expired',
                        'Facebook access token has expired during posting. Please refresh your Page Access Token.'), 'facebook');
                }

                if (strpos($error_message, 'publish_to_groups') !== false ||
                    strpos($error_message, 'pages_read_engagement') !== false ||
                    strpos($error_message, 'pages_manage_posts') !== false) {
                    return $this->handle_error(new WP_Error('insufficient_page_permissions',
                        'Facebook posting failed due to insufficient permissions. For pages: ensure your app has pages_read_engagement and pages_manage_posts permissions, and you are an admin of the page. For groups: ensure publish_to_groups permission is granted.'), 'facebook');
                }

                if (strpos($error_message, 'Invalid parameter') !== false) {
                    return $this->handle_error(new WP_Error('invalid_content',
                        'Facebook rejected the post content. Please check for invalid characters or formatting.'), 'facebook');
                }

                return $this->handle_error(new WP_Error('api_error', 'Facebook API error: ' . $error_message), 'facebook');
            }

            return $this->handle_error(new WP_Error('api_error', 'Facebook API error (HTTP ' . $code . '): ' . $response_body), 'facebook');
        }

        error_log('[HashPoster] Facebook post successful');
        return true;
    }

    private function publish_to_linkedin( $content, $media ) {
        // Check for OAuth tokens first
        $oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
        if ( ! empty( $oauth_tokens['linkedin'] ) && ! empty( $oauth_tokens['linkedin']['access_token'] ) ) {
            $access_token = $oauth_tokens['linkedin']['access_token'];
            $organization_urn = $oauth_tokens['linkedin']['organization_urn'] ?? '';
        } else {
            // Fallback to manual credentials
            $creds = $this->credentials['linkedin'];
            $access_token = $creds['access_token'] ?? '';
            $organization_urn = $creds['organization_urn'] ?? '';
        }

        if ( empty( $access_token ) ) {
            $error = new WP_Error('missing_credentials', 'LinkedIn access token is required for posting. Please connect your LinkedIn account via OAuth.');
            return $this->handle_error($error, 'linkedin');
        }

        // If organization_urn provided, post as organization using newer Share API
        if (!empty($creds['organization_urn'])) {
            // Build organization post payload using Share API
            $endpoint = 'https://api.linkedin.com/v2/shares';
            // Use Share API format for organization posting (removed visibility field due to API permissions)
            $body = [
                'owner' => $creds['organization_urn'],
                'text' => [
                    'text' => $content
                ]
            ];
            
            // If pipeline provided a URL, include it for link preview
            if (!empty($media['url'])) {
                $body['content'] = [
                    'contentEntities' => [
                        [
                            'entityLocation' => $media['url'],
                            'thumbnails' => []
                        ]
                    ],
                    'title' => substr($content, 0, 200)
                ];
            }
            $args = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0'
                ],
                'body' => wp_json_encode($body),
                'timeout' => 15,
            ];
            $response = wp_remote_post($endpoint, $args);
            if (is_wp_error($response)) return $this->handle_error($response, 'linkedin');
            $code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            if ($code !== 201 && $code !== 200) {
                $error = new WP_Error('api_error', 'LinkedIn API error: ' . $response_body . ' (HTTP ' . $code . ')');
                return $this->handle_error($error, 'linkedin');
            }
            error_log('[HashPoster] LinkedIn organization post successful');
            return true;
        }

        // Otherwise post as the authenticated member
        $author_urn = $this->get_linkedin_person_urn($access_token);
        if (is_wp_error($author_urn)) {
            $error = new WP_Error('urn_error', '[HashPoster] LinkedIn: Failed to get person URN: ' . $author_urn->get_error_message());
            return $this->handle_error($error, 'linkedin');
        }

        // Use the modern ugcPosts API for better article preview support
        $endpoint = 'https://api.linkedin.com/v2/ugcPosts';
        
        // Get URL from media payload (preferred) or extract from content as fallback
        $url_for_preview = null;
        if (!empty($media['url'])) {
            $url_for_preview = $media['url'];
            error_log('[HashPoster LinkedIn DEBUG] URL from media payload: ' . $url_for_preview);
        } elseif (preg_match('/https?:\/\/[^\s]+/', $content, $url_matches)) {
            $url_for_preview = trim($url_matches[0]);
            error_log('[HashPoster LinkedIn DEBUG] URL extracted from content: ' . $url_for_preview);
        }
        
        // Build UGC post with article share (forces OG card preview)
        $body = [
            'author' => $author_urn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => [
                        'text' => $content
                    ],
                    'shareMediaCategory' => 'ARTICLE'
                ]
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
            ]
        ];
        
        // Add article media with URL for OG scraping - this triggers rich preview
        if (!empty($url_for_preview)) {
            $body['specificContent']['com.linkedin.ugc.ShareContent']['media'] = [
                [
                    'status' => 'READY',
                    'originalUrl' => $url_for_preview
                ]
            ];
            error_log('[HashPoster LinkedIn DEBUG] Using ugcPosts API with article media for OG card preview');
        }
        
        // Debug: Log the exact content being sent to LinkedIn
        error_log('[HashPoster LinkedIn DEBUG] Content text (no inline URL): ' . substr($content, 0, 500));
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'body' => wp_json_encode($body),
            'timeout' => 15,
        ];
        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) return $this->handle_error($response, 'linkedin');
        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        if ($code !== 201 && $code !== 200) {
            $error = new WP_Error('api_error', 'LinkedIn API error: ' . $response_body . ' (HTTP ' . $code . ')');
            return $this->handle_error($error, 'linkedin');
        }
        error_log('[HashPoster] LinkedIn member post successful');
        return true;
    }

    /**
     * Helper: Get LinkedIn Person URN using an access token.
     * Returns string urn:li:person:xxxxxxx or WP_Error.
     */
    public function get_linkedin_person_urn( $access_token ) {
        // Try multiple endpoints to find one that works
        $endpoints = array(
            array('url' => 'https://api.linkedin.com/v2/userinfo', 'type' => 'oauth2'),
            array('url' => 'https://api.linkedin.com/v2/me', 'type' => 'v2'),
            array('url' => 'https://api.linkedin.com/v2/people/~', 'type' => 'people')
        );

        foreach ($endpoints as $endpoint) {
            error_log('[HashPoster] LinkedIn URN - Trying ' . $endpoint['type'] . ' endpoint: ' . $endpoint['url']);

            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Connection'    => 'Keep-Alive',
                    'Accept'        => 'application/json',
                ),
                'timeout' => 15,
            );

            $response = wp_remote_get($endpoint['url'], $args);

            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);

                if ($code === 200) {
                    $body = json_decode($response_body, true);

                    // Different endpoints return different user ID fields
                    $user_id = null;
                    if ($endpoint['type'] === 'oauth2' && isset($body['sub'])) {
                        $user_id = $body['sub'];
                    } elseif (($endpoint['type'] === 'v2' || $endpoint['type'] === 'people') && isset($body['id'])) {
                        $user_id = $body['id'];
                    }

                    if ($user_id) {
                        error_log('[HashPoster] LinkedIn URN - SUCCESS with ' . $endpoint['type'] . ' endpoint, got user ID: ' . $user_id);
                        return 'urn:li:person:' . $user_id;
                    }
                } else {
                    error_log('[HashPoster] LinkedIn URN - ' . $endpoint['type'] . ' endpoint failed: HTTP ' . $code . ' - ' . $response_body);
                }
            } else {
                error_log('[HashPoster] LinkedIn URN - WP Error for ' . $endpoint['type'] . ' endpoint: ' . $response->get_error_message());
            }
        }

        return new WP_Error('api_error', 'All LinkedIn API endpoints failed to retrieve person URN. Token may be invalid or app may not be properly configured.');
    }

    /**
     * Fallback method: Publish to LinkedIn organization page
     */
    private function publish_to_linkedin_organization($content, $media, $organization_urn, $access_token) {
        // Try using the Shares API instead of UGC API for organization posting
        $endpoint = 'https://api.linkedin.com/v2/shares';

        $body = array(
            'owner' => $organization_urn,
            'text' => array(
                'text' => $content
            ),
            'distribution' => array(
                'linkedInDistributionTarget' => (object)array()
            )
        );

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0'
            ),
            'body' => wp_json_encode($body),
            'timeout' => 15,
        );

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            error_log('[HashPoster] LinkedIn Organization API WP_Error: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($code !== 201) {
            error_log('[HashPoster] LinkedIn Organization API error: ' . $response_body);
            return new WP_Error('api_error', 'LinkedIn Organization API error: ' . $response_body . ' (HTTP ' . $code . ')');
        }

        error_log('[HashPoster] LinkedIn organization post successful');
        return true;
    }

    /**
     * Fallback method: Try to refresh LinkedIn access token
     */
    private function refresh_linkedin_token($creds) {
        // Note: LinkedIn doesn't have a standard refresh token flow like OAuth2
        // This is a placeholder for future implementation if needed
        // For now, we'll just return an error indicating token refresh isn't available
        error_log('[HashPoster] LinkedIn token refresh not implemented - LinkedIn uses long-lived tokens');
        return new WP_Error('refresh_not_supported', 'LinkedIn token refresh not supported with current credentials');
    }

    /**
     * Publish to Bluesky
     */
    private function publish_to_bluesky( $content, $media ) {
        error_log('[HashPoster] Starting Bluesky publish attempt');
        
        // Check for OAuth tokens first (from connection test)
        $oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
        if ( ! empty( $oauth_tokens['bluesky'] ) && ! empty( $oauth_tokens['bluesky']['handle'] ) ) {
            $creds = array(
                'handle' => $oauth_tokens['bluesky']['handle'],
                'app_password' => $oauth_tokens['bluesky']['app_password'] ?? $this->credentials['bluesky']['app_password'] ?? $this->credentials['bluesky']['password'] ?? ''
            );
        } else {
            // Fallback to manual credentials
            $creds = $this->credentials['bluesky'];
        }

        // Check for app_password or fallback to password field
        $password = $creds['app_password'] ?? $creds['password'] ?? '';
        
        if (empty($creds['handle']) || empty($password)) {
            error_log('[HashPoster] Bluesky missing credentials - handle: ' . (empty($creds['handle']) ? 'MISSING' : 'present') . ', password: ' . (empty($password) ? 'MISSING' : 'present'));
            return $this->handle_error(new WP_Error('missing_credentials', 'Missing Bluesky credentials (handle and app password required). Configure credentials in API Credentials tab.'), 'bluesky');
        }

        try {
            // Step 1: Authenticate and get session
            $session = $this->bluesky_authenticate($creds['handle'], $password);
            if (is_wp_error($session)) {
                return $this->handle_error($session, 'bluesky');
            }

            // Debug: Log content being sent to Bluesky
            error_log('[HashPoster] Bluesky content to post: ' . substr($content, 0, 200) . '...');
            error_log('[HashPoster] Bluesky content length: ' . strlen($content));
            $has_url = strpos($content, 'http://') !== false ||
                       strpos($content, 'https://') !== false ||
                       strpos($content, 'hashlytics.io') !== false;
            error_log('[HashPoster] Bluesky content contains URL: ' . ($has_url ? 'YES' : 'NO'));

            // Bluesky has a 300 grapheme limit - truncate if necessary
            $max_graphemes = 300;
            if (grapheme_strlen($content) > $max_graphemes) {
                $content = grapheme_substr($content, 0, $max_graphemes - 3) . '...';
                error_log('[HashPoster] Bluesky content truncated to ' . grapheme_strlen($content) . ' graphemes');
            }

            // Step 2: Create the post with embed
            $post_result = $this->bluesky_create_post($session, $content, $media);
            if (is_wp_error($post_result)) {
                return $this->handle_error($post_result, 'bluesky');
            }

            error_log('[HashPoster] Bluesky post successful');
            return true;

        } catch (Exception $e) {
            $error = new WP_Error('api_exception', '[HashPoster] Bluesky Exception: ' . $e->getMessage());
            return $this->handle_error($error, 'bluesky');
        }
    }

    /**
     * Bluesky authentication helper
     */
    private function bluesky_authenticate($handle, $app_password) {
        $endpoint = 'https://bsky.social/xrpc/com.atproto.server.createSession';

        $body = wp_json_encode(array(
            'identifier' => $handle,
            'password' => $app_password
        ));

        $args = array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'HashPoster/1.0'
            ),
            'timeout' => 15,
        );

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            return new WP_Error('auth_error', '[HashPoster] Bluesky authentication failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $response_body = wp_remote_retrieve_body($response);
            return new WP_Error('auth_error', '[HashPoster] Bluesky authentication failed: HTTP ' . $code . ' - ' . $response_body);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('auth_error', '[HashPoster] Bluesky: Invalid authentication response');
        }

        // Normalize response keys: different ATProto servers may return camelCase
        $access_jwt = $data['accessJwt'] ?? $data['access_jwt'] ?? null;
        $did = $data['did'] ?? null;
        $handle_resp = $data['handle'] ?? $data['handle'] ?? $handle;

        if (empty($access_jwt) || empty($did)) {
            return new WP_Error('auth_error', '[HashPoster] Bluesky: Authentication failed - missing access token or DID. Response: ' . wp_json_encode($data));
        }

        return array(
            'access_jwt' => $access_jwt,
            'did' => $did,
            'handle' => $handle_resp
        );
    }

    /**
     * Parse content for Bluesky RichText facets (hashtags and URLs)
     */
    private function bluesky_parse_facets($text) {
        $facets = array();

        // Parse hashtags
        preg_match_all('/#([a-zA-Z0-9_]+)/', $text, $hashtag_matches, PREG_OFFSET_CAPTURE);
        foreach ($hashtag_matches[0] as $match) {
            $facets[] = array(
                'index' => array(
                    'byteStart' => $match[1],
                    'byteEnd' => $match[1] + strlen($match[0])
                ),
                'features' => array(
                    array(
                        '$type' => 'app.bsky.richtext.facet#tag',
                        'tag' => substr($match[0], 1) // Remove the # prefix
                    )
                )
            );
        }

        // Parse URLs
        preg_match_all('/https?:\/\/[^\s]+/', $text, $url_matches, PREG_OFFSET_CAPTURE);
        foreach ($url_matches[0] as $match) {
            $facets[] = array(
                'index' => array(
                    'byteStart' => $match[1],
                    'byteEnd' => $match[1] + strlen($match[0])
                ),
                'features' => array(
                    array(
                        '$type' => 'app.bsky.richtext.facet#link',
                        'uri' => $match[0]
                    )
                )
            );
        }

        return $facets;
    }

    /**
     * Bluesky create post helper
     */
    private function bluesky_create_post($session, $content, $media = array()) {
        $endpoint = 'https://bsky.social/xrpc/com.atproto.repo.createRecord';

        // Parse facets for hashtags and URLs in text
        $facets = $this->bluesky_parse_facets($content);

        // Prepare the post record
        $record = array(
            'text' => $content,
            'createdAt' => gmdate('c') // ISO 8601 format
        );

        // Add facets if any were found
        if (!empty($facets)) {
            $record['facets'] = $facets;
        }
        
        // Add embed with external URL card if media provided
        if (!empty($media['url'])) {
            // Decode HTML entities in title and description for proper display
            $title = !empty($media['title']) ? $media['title'] : 'Link';
            $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            $description = !empty($media['description']) ? $media['description'] : '';
            $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            $embed = array(
                '$type' => 'app.bsky.embed.external',
                'external' => array(
                    'uri' => $media['url'],
                    'title' => $title,
                    'description' => $description
                )
            );
            // Note: Bluesky fetches OG:image from URL on their side when users view the post
            // No thumb upload needed - it will be scraped from your site's OG tags
            $record['embed'] = $embed;
        }

        // Use JSON flags to avoid escaping unicode and slashes so hashtags remain intact
        $body = wp_json_encode(array(
            'repo' => $session['did'],
            'collection' => 'app.bsky.feed.post',
            'record' => $record
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $args = array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $session['access_jwt'],
                'User-Agent' => 'HashPoster/1.0'
            ),
            'timeout' => 15,
        );

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            return new WP_Error('post_error', '[HashPoster] Bluesky post failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $response_body = wp_remote_retrieve_body($response);
            return new WP_Error('post_error', '[HashPoster] Bluesky post failed: HTTP ' . $code . ' - ' . $response_body);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('post_error', '[HashPoster] Bluesky: Invalid post response');
        }

        return $data;
    }

    /**
     * Upload image blob to Bluesky for embed thumbnails
     */
    private function bluesky_upload_blob($session, $image_url) {
        // Download the image
        $image_data = wp_remote_get($image_url, array('timeout' => 15));
        
        if (is_wp_error($image_data)) {
            error_log('[HashPoster] Bluesky blob upload - Failed to download image: ' . $image_data->get_error_message());
            return $image_data;
        }
        
        $image_body = wp_remote_retrieve_body($image_data);
        $content_type = wp_remote_retrieve_header($image_data, 'content-type');
        
        if (empty($image_body)) {
            return new WP_Error('blob_error', '[HashPoster] Bluesky: Empty image data');
        }
        
        // Upload to Bluesky as blob
        $endpoint = 'https://bsky.social/xrpc/com.atproto.repo.uploadBlob';
        
        $args = array(
            'body' => $image_body,
            'headers' => array(
                'Content-Type' => $content_type ?: 'image/jpeg',
                'Authorization' => 'Bearer ' . $session['access_jwt'],
                'User-Agent' => 'HashPoster/1.0'
            ),
            'timeout' => 30,
        );
        
        $response = wp_remote_post($endpoint, $args);
        
        if (is_wp_error($response)) {
            error_log('[HashPoster] Bluesky blob upload failed: ' . $response->get_error_message());
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $response_body = wp_remote_retrieve_body($response);
            error_log('[HashPoster] Bluesky blob upload failed: HTTP ' . $code . ' - ' . $response_body);
            return new WP_Error('blob_error', '[HashPoster] Bluesky blob upload failed: HTTP ' . $code);
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('blob_error', '[HashPoster] Bluesky: Invalid blob upload response');
        }
        
        error_log('[HashPoster] Bluesky blob uploaded successfully');
        return $data;
    }

    /**
     * (Reddit support removed)
     */

    // Pinterest support removed plugin-wide.

    /**
     * Shorten a URL using Bitly
     * Returns shortened URL string or WP_Error
     */
    private function shorten_url_bitly($long_url) {
        $creds = $this->credentials['bitly'] ?? array();
        $token = $creds['api_key'] ?? $creds['token'] ?? '';
        if (empty($token)) {
            return new WP_Error('missing_credentials', 'Bitly token missing');
        }

        $endpoint = 'https://api-ssl.bitly.com/v4/shorten';
        $body = array('long_url' => $long_url);
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode($body),
            'timeout' => 10,
        );

        $resp = wp_remote_post($endpoint, $args);
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        if ($code !== 200 && $code !== 201) {
            return new WP_Error('bitly_error', 'Bitly API error: ' . $body);
        }

        return $data['link'] ?? ($data['id'] ?? '');
    }

    /**
     * Remote post to another WordPress site using Application Passwords (REST API)
     */
    private function remote_post_to_wordpress($site_url, $username, $app_password, $post_data) {
        if (empty($site_url) || empty($username) || empty($app_password)) {
            return new WP_Error('missing_credentials', 'Missing WordPress remote posting credentials');
        }

        $endpoint = rtrim($site_url, '/') . '/wp-json/wp/v2/posts';
        $auth = base64_encode($username . ':' . $app_password);
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode($post_data),
            'timeout' => 15,
        );

        $resp = wp_remote_post($endpoint, $args);
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code !== 201 && $code !== 200) {
            return new WP_Error('remote_post_error', 'Remote WordPress post failed: ' . $body);
        }

        return json_decode($body, true);
    }

    /**
     * Handle and process an error
     *
     * @param WP_Error|string $error The error to handle
     * @param string $platform The platform where the error occurred
     * @param array $context Additional context information
     * @return array Recovery actions and recommendations
     */
    private function handle_error($error, $platform, $context = array()) {
        $error_info = $this->parse_error($error);
        $error_type = $this->classify_error($error_info, $platform);

        // Log the error
        $this->log_error($error_info, $error_type, $platform, $context);

        // Determine recovery strategy
        $recovery_actions = $this->get_recovery_actions($error_type, $platform, $context);

        // Execute automatic recovery if possible
        $auto_recovery_result = $this->attempt_auto_recovery($error_type, $platform, $context);

        return array(
            'error_type' => $error_type,
            'error_info' => $error_info,
            'recovery_actions' => $recovery_actions,
            'auto_recovery_attempted' => !empty($auto_recovery_result),
            'auto_recovery_result' => $auto_recovery_result,
            'user_message' => $this->get_user_friendly_message($error_type, $platform)
        );
    }

    /**
     * Parse error information from various formats
     */
    private function parse_error($error) {
        if (is_wp_error($error)) {
            return array(
                'type' => 'wp_error',
                'code' => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data' => $error->get_error_data()
            );
        }

        if (is_string($error)) {
            return array(
                'type' => 'string',
                'message' => $error
            );
        }

        if (is_array($error)) {
            return array(
                'type' => 'array',
                'data' => $error
            );
        }

        return array(
            'type' => 'unknown',
            'data' => $error
        );
    }

    /**
     * Classify error type based on error information and platform
     */
    private function classify_error($error_info, $platform) {
        $message = strtolower($error_info['message'] ?? '');
        $code = strtolower($error_info['code'] ?? '');

        // Authentication errors
        if (strpos($message, 'unauthorized') !== false ||
            strpos($message, 'forbidden') !== false ||
            strpos($message, 'invalid_token') !== false ||
            strpos($code, 'auth') !== false ||
            strpos($message, '403') !== false) {
            return 'authentication';
        }

        // Rate limit errors
        if (strpos($message, 'rate limit') !== false ||
            strpos($message, 'too many requests') !== false ||
            strpos($message, '429') !== false) {
            return 'rate_limit';
        }

        // Network errors
        if (strpos($message, 'connection') !== false ||
            strpos($message, 'timeout') !== false ||
            strpos($message, 'network') !== false ||
            strpos($code, 'http') !== false) {
            return 'network';
        }

        // Content errors
        if (strpos($message, 'content') !== false ||
            strpos($message, 'length') !== false ||
            strpos($message, 'character') !== false ||
            strpos($message, 'validation') !== false) {
            return 'content';
        }

        // Default to API error
        return 'api';
    }

    /**
     * Get recovery actions for an error type
     */
    private function get_recovery_actions($error_type, $platform, $context) {
        $actions = array();

        if (isset($this->error_types[$error_type])) {
            $actions = $this->error_types[$error_type]['recovery'];
        }

        // Add platform-specific recovery actions
        $platform_actions = $this->get_platform_specific_actions($error_type, $platform);
        $actions = array_merge($actions, $platform_actions);

        return $actions;
    }

    /**
     * Get platform-specific recovery actions
     */
    private function get_platform_specific_actions($error_type, $platform) {
        $actions = array();

        switch ($platform) {
            case 'x':
                if ($error_type === 'authentication') {
                    $actions[] = 'check_twitter_app_permissions';
                    $actions[] = 'regenerate_twitter_tokens';
                }
                break;

            case 'linkedin':
                if ($error_type === 'authentication') {
                    $actions[] = 'verify_linkedin_scopes';
                    $actions[] = 'reconnect_linkedin_account';
                }
                break;

            case 'facebook':
                if ($error_type === 'authentication') {
                    $actions[] = 'extend_facebook_token';
                    $actions[] = 'verify_facebook_pages_access';
                }
                break;

            case 'bluesky':
                if ($error_type === 'authentication') {
                    $actions[] = 'regenerate_bluesky_app_password';
                }
                break;
        }

        return $actions;
    }

    /**
     * Attempt automatic recovery
     */
    private function attempt_auto_recovery($error_type, $platform, $context) {
        switch ($error_type) {
            case 'rate_limit':
                return $this->handle_rate_limit_recovery($platform, $context);

            case 'network':
                return $this->handle_network_recovery($platform, $context);

            case 'content':
                return $this->handle_content_recovery($platform, $context);

            default:
                return false;
        }
    }

    /**
     * Handle rate limit recovery
     */
    private function handle_rate_limit_recovery($platform, $context) {
        $wait_time = $this->get_rate_limit_wait_time($platform);

        if ($wait_time > 0) {
            // Schedule retry
            $retry_time = time() + $wait_time;

            if (isset($context['post_id'])) {
                wp_schedule_single_event($retry_time, 'hashposter_retry_post', array(
                    $context['post_id'],
                    $platform,
                    $context
                ));
            }

            return array(
                'action' => 'scheduled_retry',
                'retry_time' => $retry_time,
                'wait_seconds' => $wait_time
            );
        }

        return false;
    }

    /**
     * Handle network recovery
     */
    private function handle_network_recovery($platform, $context) {
        // Simple retry with exponential backoff
        $retry_count = $context['retry_count'] ?? 0;
        $max_retries = 3;

        if ($retry_count < $max_retries) {
            $backoff_time = pow(2, $retry_count) * 30; // 30s, 1min, 2min

            if (isset($context['post_id'])) {
                wp_schedule_single_event(time() + $backoff_time, 'hashposter_retry_post', array(
                    $context['post_id'],
                    $platform,
                    array_merge($context, array('retry_count' => $retry_count + 1))
                ));
            }

            return array(
                'action' => 'network_retry',
                'retry_count' => $retry_count + 1,
                'backoff_seconds' => $backoff_time
            );
        }

        return false;
    }

    /**
     * Handle content recovery
     */
    private function handle_content_recovery($platform, $context) {
        if (isset($context['content'])) {
            $fixed_content = $this->fix_content_for_platform($context['content'], $platform);

            if ($fixed_content !== $context['content']) {
                return array(
                    'action' => 'content_fixed',
                    'original_content' => $context['content'],
                    'fixed_content' => $fixed_content
                );
            }
        }

        return false;
    }

    /**
     * Fix content for platform-specific requirements
     */
    private function fix_content_for_platform($content, $platform) {
        switch ($platform) {
            case 'x':
                // Truncate to 280 characters
                if (strlen($content) > 280) {
                    $content = substr($content, 0, 277) . '...';
                }
                break;

            case 'bluesky':
                // Truncate to 300 characters
                if (strlen($content) > 300) {
                    $content = substr($content, 0, 297) . '...';
                }
                break;

            case 'linkedin':
                // Remove any HTML entities that might cause issues
                $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                break;
        }

        return $content;
    }

    /**
     * Get rate limit wait time for platform
     */
    private function get_rate_limit_wait_time($platform) {
        $wait_times = array(
            'x' => 900,        // 15 minutes
            'facebook' => 3600, // 1 hour
            'linkedin' => 1800, // 30 minutes
            'bluesky' => 300,   // 5 minutes
        );

        return $wait_times[$platform] ?? 900;
    }

    /**
     * Log error with comprehensive information
     */
    private function log_error($error_info, $error_type, $platform, $context) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'error_type' => $error_type,
            'platform' => $platform,
            'error_info' => $error_info,
            'context' => $context,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        );

        error_log('[HashPoster Error] ' . json_encode($log_entry));

        // Also store in custom error log if enabled
        $settings = get_option('hashposter_settings', array());
        if (!empty($settings['enable_error_logging'])) {
            $this->store_error_log($log_entry);
        }
    }

    /**
     * Store error in custom log
     */
    private function store_error_log($log_entry) {
        $error_logs = get_option('hashposter_error_logs', array());
        $error_logs[] = $log_entry;

        // Keep only last 100 errors
        if (count($error_logs) > 100) {
            $error_logs = array_slice($error_logs, -100);
        }

        update_option('hashposter_error_logs', $error_logs);
    }

    /**
     * Get user-friendly error message
     */
    private function get_user_friendly_message($error_type, $platform) {
        $messages = array(
            'authentication' => "Authentication failed for {$platform}. Please check your API credentials and permissions.",
            'rate_limit' => "Rate limit exceeded for {$platform}. The post will be retried automatically.",
            'network' => "Network error occurred while posting to {$platform}. The system will retry automatically.",
            'content' => "Content issue detected for {$platform}. The content has been adjusted and will be retried.",
            'api' => "An error occurred while posting to {$platform}. Please check the error logs for details."
        );

        return $messages[$error_type] ?? "An error occurred with {$platform}. Please check your settings.";
    }

    /**
     * Get error statistics
     */
    public function get_error_stats($days = 7) {
        $error_logs = get_option('hashposter_error_logs', array());
        $stats = array(
            'total_errors' => 0,
            'by_type' => array(),
            'by_platform' => array(),
            'recent_errors' => array()
        );

        $cutoff_time = strtotime("-{$days} days");

        foreach ($error_logs as $log) {
            if (strtotime($log['timestamp']) < $cutoff_time) {
                continue;
            }

            $stats['total_errors']++;

            // Count by type
            $type = $log['error_type'] ?? 'unknown';
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

            // Count by platform
            $platform = $log['platform'] ?? 'unknown';
            $stats['by_platform'][$platform] = ($stats['by_platform'][$platform] ?? 0) + 1;

            // Keep recent errors
            if (count($stats['recent_errors']) < 10) {
                $stats['recent_errors'][] = $log;
            }
        }

        return $stats;
    }
}