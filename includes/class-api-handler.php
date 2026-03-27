<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_API_Handler {

    /* ── Centralised API version constants ─────────────────────── */
    const FB_API_VERSION = 'v24.0';
    const FB_GRAPH_BASE  = 'https://graph.facebook.com/v24.0';
    const LI_API_BASE    = 'https://api.linkedin.com/v2';
    const TG_API_BASE    = 'https://api.telegram.org/bot';
    const BSKY_BASE      = 'https://bsky.social/xrpc';
    const TWITTER_API_BASE = 'https://api.twitter.com/2';
    /* ─────────────────────────────────────────────────────────── */

    private $platforms = array( 'twitter', 'linkedin', 'bluesky', 'facebook', 'telegram' );

    private $shorteners = array( 'wordpress', 'bitly' );
    private $credentials = array();
    private $oauth_tokens = null;

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
        $this->oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
    }

    /**
     * Execute wp_remote_post with retry logic for transient failures
     *
     * @param string $endpoint API endpoint URL
     * @param array $args wp_remote_post arguments
     * @param int $max_retries Maximum number of retry attempts
     * @return array|WP_Error Response or error
     */
    private function wp_remote_post_with_retry( $endpoint, $args, $max_retries = 2 ) {
        $attempt = 0;
        $last_error = null;
        
        while ( $attempt <= $max_retries ) {
            $response = wp_remote_post( $endpoint, $args );
            
            // Check if successful
            if ( ! is_wp_error( $response ) ) {
                $code = wp_remote_retrieve_response_code( $response );
                // Retry on 5xx server errors and 429 rate limit
                if ( $code < 500 && $code !== 429 ) {
                    return $response;
                }
                $last_error = new WP_Error( 'http_error', 'HTTP ' . $code . ' error', array( 'status' => $code ) );
            } else {
                $last_error = $response;
                // Don't retry on authentication/authorization errors
                if ( in_array( $response->get_error_code(), array( 'rest_forbidden', 'rest_cookie_invalid_nonce', 'unauthorized' ) ) ) {
                    return $response;
                }
            }
            
            // Exponential backoff: 1s, 2s, 4s
            if ( $attempt < $max_retries ) {
                $wait_time = pow( 2, $attempt );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[HashPoster] API retry attempt ' . ( $attempt + 1 ) . '/' . $max_retries . ', waiting ' . $wait_time . 's' );
                }
                sleep( $wait_time );
            }
            
            $attempt++;
        }
        
        return $last_error;
    }

    public function validate_credentials( $platform ) {
        // Check transient cache first to avoid excessive API calls (5 minute cache)
        $cache_key = 'hashposter_validated_' . $platform;
        $cached_result = get_transient( $cache_key );
        if ( $cached_result !== false ) {
            return (bool) $cached_result;
        }
        
        // For X (Twitter), check OAuth 1.0a access tokens
        if ( $platform === 'x' ) {
            $oauth_tokens = $this->oauth_tokens;
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
                            set_transient( $cache_key, 1, 300 ); // Cache for 5 minutes
                            return true;
                        } else {
                            error_log('[HashPoster DEBUG] X validation - OAuth 1.0a tokens exist but API test failed: ' . print_r($test_result, true));
                            set_transient( $cache_key, 0, 60 ); // Cache failures for 1 minute only
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
        $oauth_tokens = $this->oauth_tokens;
        if ( ! empty( $oauth_tokens[ $platform ] ) && ! empty( $oauth_tokens[ $platform ]['access_token'] ) ) {
            // Check if token is not expired
            if ( empty( $oauth_tokens[ $platform ]['expires_at'] ) || $oauth_tokens[ $platform ]['expires_at'] > time() ) {
                set_transient( $cache_key, 1, 300 ); // Cache for 5 minutes
                return true;
            }
        }

        // Fallback to manual credentials
        $this->credentials = get_option( 'hashposter_api_credentials', array() );
        $creds = $this->credentials[ $platform ] ?? array();
        $result = false;
        
        switch ($platform) {
            case 'linkedin':
                $result = !empty($creds['access_token']) || (!empty($creds['client_id']) && !empty($creds['client_secret']));
                break;
            case 'bluesky':
                $has_handle = isset($creds['handle']) && trim($creds['handle']) !== '';
                $has_password = !empty($creds['app_password']) || !empty($creds['password']);
                error_log('[HashPoster DEBUG] Bluesky validation - handle: ' . ($has_handle ? 'YES' : 'NO') . ', app_password: ' . ($has_password ? 'YES' : 'NO'));
                $result = $has_handle && $has_password;
                break;
            case 'telegram':
                $has_token = !empty($creds['bot_token']);
                $has_channel = !empty($creds['channel_id']);
                error_log('[HashPoster DEBUG] Telegram validation - bot_token: ' . ($has_token ? 'YES' : 'NO') . ', channel_id: ' . ($has_channel ? 'YES' : 'NO'));
                $result = $has_token && $has_channel;
                break;
            case 'facebook':
                $oauth_tokens = $this->oauth_tokens;
                $result = !empty($oauth_tokens['facebook']['access_token']) && !empty($oauth_tokens['facebook']['page_id']);
                break;
            default:
                $result = false;
        }
        
        // Cache result
        set_transient( $cache_key, $result ? 1 : 0, $result ? 300 : 60 );
        return $result;
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
            case 'telegram':
                $res = $this->publish_to_telegram( $content, $media );
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
        $oauth_tokens = $this->oauth_tokens;
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

    private function facebook_can_refresh_page_token( $creds ) {
        if ( ! is_array( $creds ) ) {
            return false;
        }

        $has_app_id = ! empty( $creds['app_id'] );
        $has_app_secret = ! empty( $creds['app_secret'] );
        $has_page_id = ! empty( $creds['page_id'] );
        $has_user_token = ! empty( $creds['user_access_token_long_lived'] ) || ! empty( $creds['user_access_token'] );

        return $has_app_id && $has_app_secret && $has_page_id && $has_user_token;
    }

    private function facebook_refresh_page_access_token( $facebook_creds ) {
        if ( ! is_array( $facebook_creds ) ) {
            return new WP_Error( 'fb_refresh_failed', 'Facebook credentials are missing or invalid.' );
        }

        $app_id = trim( (string) ( $facebook_creds['app_id'] ?? '' ) );
        $app_secret = trim( (string) ( $facebook_creds['app_secret'] ?? '' ) );
        $page_id = trim( (string) ( $facebook_creds['page_id'] ?? '' ) );

        if ( $app_id === '' || $app_secret === '' || $page_id === '' ) {
            return new WP_Error( 'fb_refresh_missing_prereqs', 'Facebook refresh requires App ID, App Secret, and Page ID.' );
        }

        $long_lived_user_token = trim( (string) ( $facebook_creds['user_access_token_long_lived'] ?? '' ) );
        $long_lived_expires_at = ! empty( $facebook_creds['user_access_token_long_lived_expires_at'] ) ? intval( $facebook_creds['user_access_token_long_lived_expires_at'] ) : 0;
        $short_lived_user_token = trim( (string) ( $facebook_creds['user_access_token'] ?? '' ) );

        // If we have a known-valid long-lived user token, reuse it. Otherwise attempt an exchange using a provided user token.
        if ( $long_lived_user_token === '' || ( $long_lived_expires_at > 0 && $long_lived_expires_at <= time() ) ) {
            if ( $short_lived_user_token === '' ) {
                return new WP_Error( 'fb_refresh_missing_user_token', 'Facebook refresh requires a User Access Token (or a stored long-lived user token).' );
            }

            $exchange_url = add_query_arg(
                array(
                    'grant_type' => 'fb_exchange_token',
                    'client_id' => $app_id,
                    'client_secret' => $app_secret,
                    'fb_exchange_token' => $short_lived_user_token,
                ),
                'https://graph.facebook.com/v24.0/oauth/access_token'
            );

            $exchange_response = wp_remote_get( $exchange_url, array( 'timeout' => 15 ) );
            if ( is_wp_error( $exchange_response ) ) {
                return new WP_Error( 'fb_token_exchange_failed', 'Facebook token exchange failed: ' . $exchange_response->get_error_message() );
            }

            $exchange_code = wp_remote_retrieve_response_code( $exchange_response );
            $exchange_body = wp_remote_retrieve_body( $exchange_response );
            $exchange_data = json_decode( $exchange_body, true );

            if ( $exchange_code !== 200 || empty( $exchange_data['access_token'] ) ) {
                $msg = 'Facebook token exchange failed.';
                if ( is_array( $exchange_data ) && isset( $exchange_data['error']['message'] ) ) {
                    $msg .= ' ' . $exchange_data['error']['message'];
                }
                return new WP_Error( 'fb_token_exchange_failed', $msg );
            }

            $long_lived_user_token = $exchange_data['access_token'];
            $expires_in = ! empty( $exchange_data['expires_in'] ) ? intval( $exchange_data['expires_in'] ) : 0;
            $long_lived_expires_at = $expires_in > 0 ? ( time() + $expires_in ) : 0;

            $facebook_creds['user_access_token_long_lived'] = $long_lived_user_token;
            $facebook_creds['user_access_token_long_lived_expires_at'] = $long_lived_expires_at;
        }

        // Fetch Page tokens for the current user and pick the matching Page.
        $accounts_url = add_query_arg(
            array(
                'fields' => 'id,name,access_token',
                'limit' => 200,
                'access_token' => $long_lived_user_token,
            ),
            'https://graph.facebook.com/v24.0/me/accounts'
        );

        $accounts_response = wp_remote_get( $accounts_url, array( 'timeout' => 15 ) );
        if ( is_wp_error( $accounts_response ) ) {
            return new WP_Error( 'fb_accounts_failed', 'Facebook /me/accounts request failed: ' . $accounts_response->get_error_message() );
        }

        $accounts_code = wp_remote_retrieve_response_code( $accounts_response );
        $accounts_body = wp_remote_retrieve_body( $accounts_response );
        $accounts_data = json_decode( $accounts_body, true );

        if ( $accounts_code !== 200 || empty( $accounts_data['data'] ) || ! is_array( $accounts_data['data'] ) ) {
            $msg = 'Facebook /me/accounts request failed.';
            if ( is_array( $accounts_data ) && isset( $accounts_data['error']['message'] ) ) {
                $msg .= ' ' . $accounts_data['error']['message'];
            }
            return new WP_Error( 'fb_accounts_failed', $msg );
        }

        $page_token = '';
        foreach ( $accounts_data['data'] as $page ) {
            $id = isset( $page['id'] ) ? (string) $page['id'] : '';
            if ( $id !== '' && $id === (string) $page_id && ! empty( $page['access_token'] ) ) {
                $page_token = (string) $page['access_token'];
                break;
            }
        }

        if ( $page_token === '' ) {
            return new WP_Error( 'fb_page_not_found', 'Facebook Page not found in /me/accounts for the provided user token. Ensure you are an admin of the Page and granted pages_show_list/pages_read_engagement.' );
        }

        $facebook_creds['access_token'] = $page_token;
        $facebook_creds['token_updated_at'] = time();
        $facebook_creds['token_source'] = 'me_accounts';

        return $facebook_creds;
    }

    private function facebook_persist_refreshed_credentials( $refreshed_facebook_creds ) {
        if ( ! is_array( $refreshed_facebook_creds ) ) {
            return;
        }

        $all = get_option( 'hashposter_api_credentials', array() );
        if ( ! is_array( $all ) ) {
            $all = array();
        }

        $existing_fb = isset( $all['facebook'] ) && is_array( $all['facebook'] ) ? $all['facebook'] : array();
        $all['facebook'] = array_merge( $existing_fb, $refreshed_facebook_creds );
        update_option( 'hashposter_api_credentials', $all );
        delete_transient( 'hashposter_validated_facebook' );

        $this->credentials = $all;
    }

    private function publish_to_facebook( $content, $media ) {
        // Use OAuth tokens for Facebook
        $oauth_tokens = $this->oauth_tokens;
        $creds = $oauth_tokens['facebook'] ?? array();

        if (empty($creds['access_token']) || empty($creds['page_id'])) {
            return $this->handle_error(new WP_Error('missing_oauth', 'Facebook OAuth not configured. Please connect via OAuth in settings.'), 'facebook');
        }

        // Debug logging
        error_log('[HashPoster DEBUG] Facebook using OAuth tokens');
        error_log('[HashPoster DEBUG] Facebook credentials - access_token present: ' . (!empty($creds['access_token']) ? 'YES' : 'NO'));
        error_log('[HashPoster DEBUG] Facebook credentials - page_id present: ' . (!empty($creds['page_id']) ? 'YES' : 'NO'));

        if (empty($creds['access_token']) || empty($creds['page_id'])) {
            return $this->handle_error(new WP_Error('missing_credentials', 'Missing Facebook credentials (access token and page ID required). Please connect via OAuth or fill in manual credentials.'), 'facebook');
        }

        // Verify the OAuth token
        $verify_endpoint = 'https://graph.facebook.com/v24.0/me?fields=id,name&access_token=' . $creds['access_token'];
        $verify_response = wp_remote_get($verify_endpoint, array('timeout' => 15));

        if (is_wp_error($verify_response)) {
            return $this->handle_error(new WP_Error('token_verification_failed', 'Facebook token verification failed: ' . $verify_response->get_error_message()), 'facebook');
        }

        $verify_code = wp_remote_retrieve_response_code($verify_response);
        if ($verify_code !== 200) {
            $verify_body = wp_remote_retrieve_body($verify_response);
            $error_data = json_decode($verify_body, true);
            $error_message = $error_data['error']['message'] ?? '';

            if ($error_message !== '' && (
                strpos($error_message, 'session is invalid') !== false ||
                strpos($error_message, 'Invalid OAuth access token') !== false ||
                strpos($error_message, 'Error validating access token') !== false
            )) {
                return $this->handle_error(new WP_Error('token_expired',
                    'Facebook OAuth access token has expired. Please reconnect via OAuth in the settings.'), 'facebook');
            }

            if (strpos($error_message, 'permissions') !== false) {
                return $this->handle_error(new WP_Error('insufficient_permissions',
                    'Facebook app lacks required permissions. Please ensure your Facebook app has the following permissions: pages_manage_posts, pages_show_list, publish_pages.'), 'facebook');
            }

            return $this->handle_error(new WP_Error('token_invalid', 'Facebook access token is invalid: ' . $error_message), 'facebook');
        }

        $endpoint = 'https://graph.facebook.com/v24.0/' . $creds['page_id'] . '/feed';
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

        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            $body['access_token'] = $creds['access_token'];
            $args['body'] = $body;

            $response = $this->wp_remote_post_with_retry($endpoint, $args);
            if (is_wp_error($response)) {
                return $this->handle_error(new WP_Error('api_error', 'Facebook API connection failed: ' . $response->get_error_message()), 'facebook');
            }

            $code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($code === 200) {
                error_log('[HashPoster] Facebook post successful');
                return true;
            }

            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? '';
            $is_expired = ($error_message !== '' && (
                strpos($error_message, 'session is invalid') !== false ||
                strpos($error_message, 'Invalid OAuth access token') !== false
            ));

            if ( $is_expired && $attempt === 0 && $this->facebook_can_refresh_page_token( $manual_creds ) ) {
                $refreshed = $this->facebook_refresh_page_access_token( $manual_creds );
                if ( ! is_wp_error( $refreshed ) ) {
                    $this->facebook_persist_refreshed_credentials( $refreshed );
                    $manual_creds = $refreshed;
                    $creds = $refreshed;
                    continue;
                }
            }

            if ($error_message !== '') {
                if ( $is_expired ) {
                    return $this->handle_error(new WP_Error('token_expired',
                        'Facebook access token has expired during posting. If Meta App refresh is configured, re-save settings to refresh tokens; otherwise refresh your Page Access Token.'), 'facebook');
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

        return $this->handle_error(new WP_Error('api_error', 'Facebook API error: Unknown error'), 'facebook');
    }

    private function publish_to_linkedin( $content, $media ) {
        // Check for OAuth tokens first
        $oauth_tokens = $this->oauth_tokens;
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
            $response = $this->wp_remote_post_with_retry($endpoint, $args);
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
        $response = $this->wp_remote_post_with_retry($endpoint, $args);
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

        $response = $this->wp_remote_post_with_retry($endpoint, $args);

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
        $oauth_tokens = $this->oauth_tokens;
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

        $response = $this->wp_remote_post_with_retry($endpoint, $args);

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
            
            // ATProto clients must actively upload thumbnails to display rich OG cards
            if ( !empty($media['thumb']) ) {
                error_log('[HashPoster] Bluesky: Found thumbnail, attempting to upload blob...');
                $blob_response = $this->bluesky_upload_blob( $session, $media['thumb'] );
                if ( ! is_wp_error( $blob_response ) && !empty($blob_response['blob']) ) {
                    $embed['external']['thumb'] = $blob_response['blob'];
                    error_log('[HashPoster] Bluesky: Thumbnail successfully attached to URL card.');
                } else {
                    error_log('[HashPoster] Bluesky: Thumbnail blob upload failed, continuing without image.');
                }
            }

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

        $response = $this->wp_remote_post_with_retry($endpoint, $args);

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
        
        $response = $this->wp_remote_post_with_retry($endpoint, $args, 1); // Only 1 retry for large uploads
        
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
     * Publish a post to Telegram
     * 
     * @param string $content The message content
     * @param array $media Optional media data
     * @return array|WP_Error
     */
    private function publish_to_telegram( $content, $media = array() ) {
        error_log('[HashPoster] Starting Telegram publish attempt');

        // Get Telegram credentials
        $creds = $this->credentials['telegram'] ?? array();
        $bot_token = $creds['bot_token'] ?? '';
        $channel_id = $creds['channel_id'] ?? '';

        if ( empty( $bot_token ) || empty( $channel_id ) ) {
            error_log('[HashPoster] Telegram: Missing bot token or channel ID');
            return new WP_Error( 'missing_credentials', 'Telegram bot token or channel ID not configured' );
        }

        // Normalize channel ID - ensure @ prefix for channel names
        if (!preg_match('/^-\d+$/', $channel_id) && !preg_match('/^@/', $channel_id)) {
            $channel_id = '@' . ltrim($channel_id, '@');
        }

        error_log('[HashPoster] Telegram: Using channel ID: ' . $channel_id);

        // Telegram API endpoint
        $endpoint = 'https://api.telegram.org/bot' . $bot_token . '/sendMessage';

        // Parse markdown in content (Telegram supports markdown formatting)
        $parse_mode = 'Markdown';

        // Prepare request args
        $args = array(
            'timeout' => 30,
            'sslverify' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'chat_id' => $channel_id,
                'text' => $content,
                'parse_mode' => $parse_mode,
                'disable_web_page_preview' => false,
            ) ),
        );

        error_log('[HashPoster] Telegram: Sending to channel ' . $channel_id);

        $response = $this->wp_remote_post_with_retry( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            error_log('[HashPoster] Telegram API error: ' . $error_msg );
            return new WP_Error( 'api_error', 'Telegram API error: ' . $error_msg );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            $error_desc = $data['description'] ?? 'Unknown error';
            error_log('[HashPoster] Telegram API error (' . $code . '): ' . $error_desc );
            return new WP_Error( 'api_error', 'Telegram API error: ' . $error_desc );
        }

        if ( ! isset( $data['ok'] ) || ! $data['ok'] ) {
            $error_desc = $data['description'] ?? 'Unknown error';
            error_log('[HashPoster] Telegram: Message send failed: ' . $error_desc );
            return new WP_Error( 'api_error', 'Telegram error: ' . $error_desc );
        }

        $message_id = $data['result']['message_id'] ?? null;
        if ( empty( $message_id ) ) {
            error_log('[HashPoster] Telegram: No message ID returned');
            return new WP_Error( 'api_error', 'Telegram: Failed to get message ID' );
        }

        error_log('[HashPoster] Telegram: Message posted successfully (ID: ' . $message_id . ')' );
        return array( 'success' => true, 'message_id' => $message_id );
    }

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

    /**
     * Post to Twitter (X) using API v2 and OAuth 1.0a User Context
     */
    public function post_to_twitter( $post, $content ) {
        $creds = $this->credentials['twitter'] ?? array();
        
        if ( empty($creds['api_key']) || empty($creds['api_secret']) || empty($creds['access_token']) || empty($creds['access_token_secret']) ) {
            return new WP_Error( 'missing_credentials', 'Twitter API credentials not configured' );
        }

        $endpoint = self::TWITTER_API_BASE . '/tweets';
        
        $body = array(
            'text' => $content
        );

        $oauth_header = $this->generate_twitter_oauth_header( $endpoint, 'POST', $creds );

        $args = array(
            'headers' => array(
                'Authorization' => $oauth_header,
                'Content-Type'  => 'application/json'
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 15
        );

        $response = $this->wp_remote_post_with_retry( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 201 && !empty($body['data']['id']) ) {
            return array(
                'success' => true,
                'post_id' => $body['data']['id'],
                'url'     => 'https://twitter.com/user/status/' . $body['data']['id']
            );
        }

        // Handle Twitter specific errors
        $error_msg = $body['detail'] ?? ($body['title'] ?? 'Unknown Twitter API error');
        if ( isset($body['errors'][0]['message']) ) {
            $error_msg = $body['errors'][0]['message'];
        }

        return new WP_Error( 'twitter_api_error', 'Twitter Error: ' . $error_msg, array( 'status' => $code, 'response' => $body ) );
    }

    /**
     * Generate OAuth 1.0a Authorization header for Twitter
     *
     * Follows the OAuth 1.0a spec precisely:
     * - Collects query string params from the URL and merges them with oauth params
     * - Sorts all params before building signature base string
     * - Uses a strictly alphanumeric nonce
     */
    private function generate_twitter_oauth_header( $url, $method, $creds ) {
        // Strict alphanumeric nonce (no special chars)
        $nonce = substr( str_replace( array('+', '/', '='), '', base64_encode( random_bytes(32) ) ), 0, 32 );

        $oauth_params = array(
            'oauth_consumer_key'     => $creds['api_key'],
            'oauth_nonce'            => $nonce,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) time(),
            'oauth_token'            => $creds['access_token'],
            'oauth_version'          => '1.0',
        );

        // Per OAuth 1.0a spec: extract query params from the URL and include them in the base string
        $parsed = wp_parse_url( $url );
        $base_url = $parsed['scheme'] . '://' . $parsed['host'] . ( $parsed['path'] ?? '' );

        $query_params = array();
        if ( ! empty( $parsed['query'] ) ) {
            wp_parse_str( $parsed['query'], $query_params );
        }

        // Merge oauth + query params, then sort
        $all_params = array_merge( $oauth_params, $query_params );
        ksort( $all_params );

        // Percent-encode each key and value, build the parameter string
        $param_parts = array();
        foreach ( $all_params as $k => $v ) {
            $param_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
        }
        $param_string = implode( '&', $param_parts );

        // Build the signature base string
        $base_string = strtoupper( $method )
            . '&' . rawurlencode( $base_url )
            . '&' . rawurlencode( $param_string );

        // Generate signature
        $signing_key = rawurlencode( $creds['api_secret'] ) . '&' . rawurlencode( $creds['access_token_secret'] );
        $signature   = base64_encode( hash_hmac( 'sha1', $base_string, $signing_key, true ) );

        $oauth_params['oauth_signature'] = $signature;

        // Build Authorization header (only oauth_ params go in the header, NOT query params)
        ksort( $oauth_params );
        $header_parts = array();
        foreach ( $oauth_params as $k => $v ) {
            $header_parts[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
        }

        return 'OAuth ' . implode( ', ', $header_parts );
    }

    /**
     * Verify Twitter credentials by hitting the /2/users/me endpoint
     */
    public function verify_twitter_credentials( $creds ) {
        if ( empty($creds['api_key']) || empty($creds['api_secret']) || empty($creds['access_token']) || empty($creds['access_token_secret']) ) {
            return new WP_Error( 'missing_credentials', 'All 4 Twitter API keys are required' );
        }

        $endpoint = self::TWITTER_API_BASE . '/users/me';
        
        $oauth_header = $this->generate_twitter_oauth_header( $endpoint, 'GET', $creds );

        $args = array(
            'headers' => array(
                'Authorization' => $oauth_header
            ),
            'timeout' => 15
        );

        $response = wp_remote_get( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $body     = json_decode( $raw_body, true );

        // Debug: log full response so we can diagnose
        error_log( '[HashPoster Twitter] HTTP ' . $code . ' — ' . $raw_body );

        if ( $code === 200 && !empty($body['data']['id']) ) {
            return true;
        }

        $error_msg = $body['detail'] ?? ( $body['title'] ?? 'Authentication failed. Please verify your keys and ensure your App has Read and Write access.' );
        // Surface the full raw body in the error so it shows in the UI
        return new WP_Error( 'twitter_auth_failed', 'Twitter connection failed: ' . $error_msg . ' (HTTP ' . $code . ')', array( 'status' => $code, 'raw' => $raw_body ) );
    }

}
