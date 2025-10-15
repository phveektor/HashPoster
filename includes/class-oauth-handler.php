<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_OAuth_Handler {
    private $platforms = array( 'x', 'linkedin', 'bluesky', 'facebook' );

    public function __construct() {
        add_action( 'admin_init', array( $this, 'handle_oauth_callbacks' ) );
        add_action( 'wp_ajax_hashposter_oauth_initiate', array( $this, 'ajax_oauth_initiate' ) );
        add_action( 'wp_ajax_hashposter_oauth_disconnect', array( $this, 'ajax_oauth_disconnect' ) );
    }

    /**
     * Check if a platform is connected via OAuth
     */
    public function is_connected( $platform ) {
        $tokens = get_option( 'hashposter_oauth_tokens', array() );

        // For Bluesky which uses direct credential validation instead of OAuth tokens
        if ( $platform === 'bluesky' ) {
            $api_credentials = get_option( 'hashposter_api_credentials', array() );
            return !empty( $api_credentials['bluesky']['handle'] ) && !empty( $api_credentials['bluesky']['app_password'] );
        }

        // For all OAuth platforms (including X), check for access_token
        return !empty( $tokens[ $platform ] ) && !empty( $tokens[ $platform ]['access_token'] );
    }

    /**
     * Get stored OAuth tokens for a platform
     */
    public function get_tokens( $platform ) {
        $tokens = get_option( 'hashposter_oauth_tokens', array() );
        return $tokens[ $platform ] ?? array();
    }

    /**
     * Store OAuth tokens for a platform
     */
    private function store_tokens( $platform, $tokens ) {
        $all_tokens = get_option( 'hashposter_oauth_tokens', array() );
        $all_tokens[ $platform ] = array_merge( $all_tokens[ $platform ] ?? array(), $tokens );
        update_option( 'hashposter_oauth_tokens', $all_tokens );
    }

    /**
     * Remove OAuth tokens for a platform
     */
    public function disconnect( $platform ) {
        $all_tokens = get_option( 'hashposter_oauth_tokens', array() );
        unset( $all_tokens[ $platform ] );
        update_option( 'hashposter_oauth_tokens', $all_tokens );
    }

    /**
     * AJAX handler for initiating OAuth flow
     */
    public function ajax_oauth_initiate() {
        check_ajax_referer( 'hashposter_oauth', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $platform = sanitize_text_field( $_POST['platform'] ?? '' );
        if ( ! in_array( $platform, $this->platforms ) ) {
            wp_die( 'Invalid platform' );
        }

        $auth_url = $this->get_oauth_url( $platform );
        if ( $auth_url ) {
            wp_send_json_success( array( 'auth_url' => $auth_url ) );
        } else {
            wp_send_json_error( 'Failed to generate OAuth URL' );
        }
    }

    /**
     * AJAX handler for disconnecting OAuth
     */
    public function ajax_oauth_disconnect() {
        check_ajax_referer( 'hashposter_oauth', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $platform = sanitize_text_field( $_POST['platform'] ?? '' );
        if ( ! in_array( $platform, $this->platforms ) ) {
            wp_die( 'Invalid platform' );
        }

        $this->disconnect( $platform );
        wp_send_json_success( array( 'message' => ucfirst( $platform ) . ' disconnected successfully' ) );
    }

    /**
     * Generate code challenge for OAuth 2.0 PKCE
     */
    private function generate_code_challenge( $code_verifier ) {
        return str_replace( '=', '', strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ) );
    }
    public function get_oauth_url( $platform ) {
        $callback_url = admin_url( 'admin.php?page=hashposter-settings&hashposter_oauth_callback=' . $platform );

        switch ( $platform ) {
            case 'x':
                return $this->get_x_oauth_url( $callback_url );

            case 'linkedin':
                return $this->get_linkedin_oauth_url( $callback_url );

            case 'facebook':
                return $this->get_facebook_oauth_url( $callback_url );

            case 'bluesky':
                // Bluesky doesn't use traditional OAuth, just direct auth
                return false;

            default:
                return false;
        }
    }

    /**
     * Get X (Twitter) OAuth URL using OAuth 1.0a
     */
    private function get_x_oauth_url( $callback_url ) {
        $credentials = get_option( 'hashposter_api_credentials', array() );
        $consumer_key = $credentials['x']['client_id'] ?? '';
        $consumer_secret = $credentials['x']['client_secret'] ?? '';

        if ( empty( $consumer_key ) || empty( $consumer_secret ) ) {
            return false;
        }

        // Include TwitterOAuth library
        require_once HASHPOSTER_PATH . 'includes/twitteroauth/autoload.php';

        try {
            $connection = new Abraham\TwitterOAuth\TwitterOAuth($consumer_key, $consumer_secret);
            
            // Get request token
            $request_token = $connection->oauth('oauth/request_token', [
                'oauth_callback' => $callback_url
            ]);

            if (isset($request_token['oauth_token'])) {
                // Store request token for later exchange
                update_option('hashposter_oauth_request_token_x', $request_token['oauth_token']);
                update_option('hashposter_oauth_request_token_secret_x', $request_token['oauth_token_secret']);
                
                // Return authorization URL
                return 'https://api.twitter.com/oauth/authenticate?oauth_token=' . $request_token['oauth_token'];
            } else {
                error_log('[HashPoster] X OAuth 1.0a: Failed to get request token: ' . print_r($request_token, true));
                return false;
            }
        } catch (Exception $e) {
            error_log('[HashPoster] X OAuth 1.0a: Exception getting request token: ' . $e->getMessage());
            return false;
        }
    }



    /**
     * Get LinkedIn OAuth URL
     */
    private function get_linkedin_oauth_url( $callback_url ) {
        $credentials = get_option( 'hashposter_api_credentials', array() );
        $client_id = $credentials['linkedin']['client_id'] ?? '';

        if ( empty( $client_id ) ) {
            return false; // Return false instead of setup URL for AJAX handling
        }

        $state = wp_generate_password( 32, false );
        update_option( 'hashposter_oauth_state_linkedin', $state );

        $params = array(
            'response_type' => 'code',
            'client_id' => $client_id,
            'redirect_uri' => $callback_url,
            'state' => $state,
            'scope' => 'openid profile email w_member_social' // Updated scopes for new LinkedIn API
        );

        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query( $params );
    }

    /**
     * Get Facebook OAuth URL
     */
    private function get_facebook_oauth_url( $callback_url ) {
        $credentials = get_option( 'hashposter_api_credentials', array() );
        $app_id = $credentials['facebook']['app_id'] ?? '';

        if ( empty( $app_id ) ) {
            return false; // Credentials not configured
        }

        $state = wp_generate_password( 32, false );
        update_option( 'hashposter_oauth_state_facebook', $state );

        $params = array(
            'client_id' => $app_id,
            'redirect_uri' => $callback_url,
            'state' => $state,
            'scope' => 'pages_manage_posts,pages_read_engagement,pages_show_list',
            'response_type' => 'code'
        );

        return 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query( $params );
    }

    /**
     * Handle OAuth callbacks
     */
    public function handle_oauth_callbacks() {
        if ( ! isset( $_GET['hashposter_oauth_callback'] ) ) {
            return;
        }

        $platform = sanitize_text_field( $_GET['hashposter_oauth_callback'] );
        if ( ! in_array( $platform, $this->platforms ) ) {
            return;
        }

        // Handle the callback based on platform
        switch ( $platform ) {
            case 'linkedin':
                $this->handle_linkedin_callback();
                break;
            case 'facebook':
                $this->handle_facebook_callback();
                break;
            case 'x':
                $this->handle_x_callback();
                break;
        }
    }

    /**
     * Handle LinkedIn OAuth callback
     */
    private function handle_linkedin_callback() {
        if ( isset( $_GET['error'] ) ) {
            $error = sanitize_text_field( $_GET['error'] );
            $this->oauth_error_redirect( 'LinkedIn authentication failed: ' . $error );
            return;
        }

        if ( ! isset( $_GET['code'] ) || ! isset( $_GET['state'] ) ) {
            $this->oauth_error_redirect( 'LinkedIn authentication failed: Missing parameters' );
            return;
        }

        $state = get_option( 'hashposter_oauth_state_linkedin' );
        if ( $_GET['state'] !== $state ) {
            $this->oauth_error_redirect( 'LinkedIn authentication failed: Invalid state' );
            return;
        }

        // Exchange code for access token
        $credentials = get_option( 'hashposter_api_credentials', array() );
        $client_id = $credentials['linkedin']['client_id'] ?? '';
        $client_secret = $credentials['linkedin']['client_secret'] ?? '';

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            $this->oauth_error_redirect( 'LinkedIn credentials not configured' );
            return;
        }

        $token_url = 'https://www.linkedin.com/oauth/v2/accessToken';
        $callback_url = admin_url( 'admin.php?page=hashposter-settings&hashposter_oauth_callback=linkedin' );

        $response = wp_remote_post( $token_url, array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query(array(
                'grant_type' => 'authorization_code',
                'code' => $_GET['code'],
                'redirect_uri' => $callback_url,
                'client_id' => $client_id,
                'client_secret' => $client_secret
            )),
            'timeout' => 15
        ) );

        if ( is_wp_error( $response ) ) {
            $this->oauth_error_redirect( 'LinkedIn token exchange failed: ' . $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && isset( $body['access_token'] ) ) {
            $this->store_tokens( 'linkedin', array(
                'access_token' => $body['access_token'],
                'expires_at' => time() + ( $body['expires_in'] ?? 5184000 ) // Default 60 days
            ) );

            delete_option( 'hashposter_oauth_state_linkedin' );
            wp_redirect( admin_url( 'admin.php?page=hashposter-settings&tab=platforms&oauth_success=linkedin' ) );
            exit;
        } else {
            $error_msg = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : 'Unknown error' );
            $this->oauth_error_redirect( 'LinkedIn token exchange failed: ' . $error_msg );
        }
    }

    /**
     * Handle Facebook OAuth callback
     */
    private function handle_facebook_callback() {
        if ( isset( $_GET['error'] ) ) {
            $error = sanitize_text_field( $_GET['error'] );
            $this->oauth_error_redirect( 'Facebook authentication failed: ' . $error );
            return;
        }

        if ( ! isset( $_GET['code'] ) || ! isset( $_GET['state'] ) ) {
            $this->oauth_error_redirect( 'Facebook authentication failed: Missing parameters' );
            return;
        }

        $state = get_option( 'hashposter_oauth_state_facebook' );
        if ( $_GET['state'] !== $state ) {
            $this->oauth_error_redirect( 'Facebook authentication failed: Invalid state' );
            return;
        }

        // Exchange code for access token
        $credentials = get_option( 'hashposter_api_credentials', array() );
        $app_id = $credentials['facebook']['app_id'] ?? '';
        $app_secret = $credentials['facebook']['app_secret'] ?? '';

        if ( empty( $app_id ) || empty( $app_secret ) ) {
            $this->oauth_error_redirect( 'Facebook credentials not configured' );
            return;
        }

        $token_url = 'https://graph.facebook.com/v18.0/oauth/access_token';
        $callback_url = admin_url( 'admin.php?page=hashposter-settings&hashposter_oauth_callback=facebook' );

        $response = wp_remote_post( $token_url, array(
            'body' => array(
                'client_id' => $app_id,
                'client_secret' => $app_secret,
                'redirect_uri' => $callback_url,
                'code' => $_GET['code']
            )
        ) );

        if ( is_wp_error( $response ) ) {
            $this->oauth_error_redirect( 'Facebook token exchange failed: ' . $response->get_error_message() );
            return;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['access_token'] ) ) {
            // Get user's pages
            $pages_url = 'https://graph.facebook.com/v18.0/me/accounts?access_token=' . $body['access_token'];
            $pages_response = wp_remote_get( $pages_url );

            $page_id = '';
            $page_access_token = '';

            if ( ! is_wp_error( $pages_response ) ) {
                $pages_data = json_decode( wp_remote_retrieve_body( $pages_response ), true );
                if ( ! empty( $pages_data['data'] ) && is_array( $pages_data['data'] ) ) {
                    // Look specifically for Hashlytics page
                    foreach ( $pages_data['data'] as $page ) {
                        if ( stripos( $page['name'], 'hashlytics' ) !== false ) {
                            $page_id = $page['id'];
                            $page_access_token = $page['access_token'];
                            break;
                        }
                    }
                    // Fallback to first page if Hashlytics not found
                    if ( empty( $page_id ) && !empty( $pages_data['data'] ) ) {
                        $page = $pages_data['data'][0];
                        $page_id = $page['id'];
                        $page_access_token = $page['access_token'];
                    }
                }
            }

            $this->store_tokens( 'facebook', array(
                'access_token' => $page_access_token ?: $body['access_token'],
                'page_id' => $page_id,
                'user_access_token' => $body['access_token'],
                'expires_at' => time() + ( $body['expires_in'] ?? 5184000 ) // Default 60 days
            ) );

            delete_option( 'hashposter_oauth_state_facebook' );
            wp_redirect( admin_url( 'admin.php?page=hashposter-settings&tab=platforms&oauth_success=facebook' ) );
            exit;
        } else {
            $this->oauth_error_redirect( 'Facebook token exchange failed: ' . ( $body['error']['message'] ?? 'Unknown error' ) );
        }
    }

    /**
     * Handle X (Twitter) OAuth callback using OAuth 1.0a
     */
    private function handle_x_callback() {
        if ( isset( $_GET['denied'] ) ) {
            $this->oauth_error_redirect( 'X authentication was denied by user' );
            return;
        }

        if ( ! isset( $_GET['oauth_token'] ) || ! isset( $_GET['oauth_verifier'] ) ) {
            $this->oauth_error_redirect( 'X authentication failed: Missing OAuth parameters' );
            return;
        }

        $oauth_token = sanitize_text_field( $_GET['oauth_token'] );
        $oauth_verifier = sanitize_text_field( $_GET['oauth_verifier'] );

        // Get stored request token and secret
        $stored_request_token = get_option( 'hashposter_oauth_request_token_x' );
        $request_token_secret = get_option( 'hashposter_oauth_request_token_secret_x' );

        if ( empty( $stored_request_token ) || empty( $request_token_secret ) ) {
            $this->oauth_error_redirect( 'X authentication failed: Request token not found' );
            return;
        }

        if ( $oauth_token !== $stored_request_token ) {
            $this->oauth_error_redirect( 'X authentication failed: Token mismatch' );
            return;
        }

        // Get credentials
        $credentials = get_option( 'hashposter_api_credentials', array() );
        $consumer_key = $credentials['x']['client_id'] ?? '';
        $consumer_secret = $credentials['x']['client_secret'] ?? '';

        if ( empty( $consumer_key ) || empty( $consumer_secret ) ) {
            $this->oauth_error_redirect( 'X credentials not configured' );
            return;
        }

        // Include TwitterOAuth library
        require_once HASHPOSTER_PATH . 'includes/twitteroauth/autoload.php';

        try {
            $connection = new Abraham\TwitterOAuth\TwitterOAuth(
                $consumer_key, 
                $consumer_secret, 
                $stored_request_token, 
                $request_token_secret
            );

            // Exchange request token for access token
            $access_token = $connection->oauth('oauth/access_token', [
                'oauth_verifier' => $oauth_verifier
            ]);

            if ( isset( $access_token['oauth_token'] ) && isset( $access_token['oauth_token_secret'] ) ) {
                // Store OAuth 1.0a tokens
                $this->store_tokens( 'x', array(
                    'access_token' => $access_token['oauth_token'],
                    'access_token_secret' => $access_token['oauth_token_secret'],
                    'user_id' => $access_token['user_id'] ?? '',
                    'screen_name' => $access_token['screen_name'] ?? '',
                    'timestamp' => time()
                ) );

                // Clean up temporary tokens
                delete_option( 'hashposter_oauth_request_token_x' );
                delete_option( 'hashposter_oauth_request_token_secret_x' );

                wp_redirect( admin_url( 'admin.php?page=hashposter-settings&tab=platforms&oauth_success=x' ) );
                exit;
            } else {
                error_log('[HashPoster] X OAuth 1.0a: Failed to get access token: ' . print_r($access_token, true));
                $this->oauth_error_redirect( 'X authentication failed: Could not obtain access token' );
            }
        } catch (Exception $e) {
            error_log('[HashPoster] X OAuth 1.0a: Exception during token exchange: ' . $e->getMessage());
            $this->oauth_error_redirect( 'X authentication failed: ' . $e->getMessage() );
        }
    }

    /**
     * Redirect with OAuth error
     */
    private function oauth_error_redirect( $message ) {
        wp_redirect( admin_url( 'admin.php?page=hashposter-settings&tab=platforms&oauth_error=' . urlencode( $message ) ) );
        exit;
    }
}