<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_API_Handler {
    private $platforms = array( 'x', 'facebook', 'linkedin', 'bluesky', 'reddit' );
    private $shorteners = array( 'wordpress', 'bitly' );
    private $credentials = array();

    public function __construct() {
        $this->initialize();
    }

    public function initialize() {
        $this->credentials = get_option( 'hashposter_api_credentials', array() );
    }

    public function connect_platform( $platform ) {
        // For OAuth-based platforms, you may need to implement OAuth flows here.
        // For simplicity, we assume credentials are already saved.
        return $this->validate_credentials( $platform );
    }

    public function publish_to_platform( $platform, $content, $media = array() ) {
        if ( ! $this->validate_credentials( $platform ) ) return new WP_Error('invalid_credentials', 'Invalid credentials for ' . $platform);
        
        // If media is empty but we have a post ID, try to get the featured image
        if (empty($media['image']) && !empty($media['post_id'])) {
            $post_id = $media['post_id'];
            if (has_post_thumbnail($post_id)) {
                $image_id = get_post_thumbnail_id($post_id);
                $image_url = wp_get_attachment_image_url($image_id, 'large');
                if ($image_url) {
                    $media['image'] = $image_url;
                    $media['image_path'] = get_attached_file($image_id);
                }
            }
        }
        
        switch ( $platform ) {
            case 'x':
                return $this->publish_to_x( $content, $media );
            case 'facebook':
                return $this->publish_to_facebook( $content, $media );
            case 'linkedin':
                return $this->publish_to_linkedin( $content, $media );
            case 'bluesky':
                return $this->publish_to_bluesky( $content, $media );
            case 'reddit':
                return $this->publish_to_reddit( $content, $media );
            default:
                return new WP_Error('unsupported_platform', 'Unsupported platform');
        }
    }

    public function validate_credentials( $platform ) {
        $this->credentials = get_option( 'hashposter_api_credentials', array() );
        $creds = $this->credentials[ $platform ] ?? array();
        switch ($platform) {
            case 'x':
                return !empty($creds['key']) && !empty($creds['secret']) && !empty($creds['access_token']) && !empty($creds['access_token_secret']);
            case 'facebook':
                return !empty($creds['access_token']) && !empty($creds['page_id']);
            case 'linkedin':
                // For business pages: require client_id, client_secret, and organization_urn
                return !empty($creds['client_id']) && !empty($creds['client_secret']) && !empty($creds['organization_urn']);
            case 'bluesky':
                return isset($creds['handle'], $creds['app_password']) && trim($creds['handle']) !== '' && trim($creds['app_password']) !== '';
            case 'reddit':
                return !empty($creds['client_id']) && !empty($creds['client_secret']) && !empty($creds['username']) && !empty($creds['password']) && !empty($creds['subreddit']);
            default:
                return false;
        }
    }

    // --- Platform-specific posting methods ---

    private function publish_to_x( $content, $media ) {
        // X (Twitter) API: Use abraham/twitteroauth for OAuth 1.0a user context
        // On shared hosting, use the included twitteroauth library
        $creds = $this->credentials['x'];
        if (
            empty($creds['key']) ||
            empty($creds['secret']) ||
            empty($creds['access_token']) ||
            empty($creds['access_token_secret'])
        ) {
            return new WP_Error('missing_credentials', 'Missing X (Twitter) credentials.');
        }

        $autoload_path = dirname(__FILE__) . '/twitteroauth/autoload.php';
        if ( !file_exists($autoload_path) ) {
            return new WP_Error('dependency_missing', 'TwitterOAuth library not found. Please upload the twitteroauth folder (with autoload.php and src/) to the plugin\'s includes directory.');
        }
        if ( !class_exists('Abraham\TwitterOAuth\TwitterOAuth') ) {
            require_once $autoload_path;
        }

        try {
            // Decode HTML entities for better Twitter display
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            $connection = new \Abraham\TwitterOAuth\TwitterOAuth(
                $creds['key'],
                $creds['secret'],
                $creds['access_token'],
                $creds['access_token_secret']
            );
            
            // Fix: For TwitterOAuth V2 API, we need a different approach to media
            if (!empty($media['image'])) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[HashPoster] Attempting to upload image to X using file: ' . $media['image']);
                }
                
                // Try using file_get_contents for the image
                try {
                    $image_data = file_get_contents($media['image']);
                    if ($image_data) {
                        // For TwitterOAuth, the best way to upload media
                        $connection->setApiVersion('1.1');  // Media uploads need to use v1.1 API
                        $media_upload = $connection->upload('media/upload', ['media' => $image_data]);
                        $connection->setApiVersion('2');  // Switch back to v2 for posting
                        
                        if (isset($media_upload->media_id_string)) {
                            // For Twitter API v2, we need to use this format
                            $post_params = [
                                'text' => $content,
                                'media' => ['media_ids' => [$media_upload->media_id_string]]
                            ];
                            
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('[HashPoster] X media upload successful. Media ID: ' . $media_upload->media_id_string);
                            }
                        } else {
                            $post_params = ['text' => $content];
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('[HashPoster] X media upload failed with data: ' . print_r($media_upload, true));
                            }
                        }
                    } else {
                        $post_params = ['text' => $content];
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('[HashPoster] X media file could not be read: ' . $media['image']);
                        }
                    }
                } catch (Exception $e) {
                    $post_params = ['text' => $content];
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[HashPoster] X media exception: ' . $e->getMessage());
                    }
                }
            } else {
                $post_params = ['text' => $content];
            }
            
            // Now post the tweet with the prepared params
            $result = $connection->post('tweets', $post_params);

            // Log the response for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] X (Twitter) API response: ' . print_r($result, true));
                error_log('[HashPoster] X (Twitter) HTTP code: ' . $connection->getLastHttpCode());
            }

            // Improved diagnostics: always return error if not 201 (v2 API returns 201 for success)
            if ($connection->getLastHttpCode() == 201) {
                return true;
            } else {
                $error_msg = isset($result->errors) ? json_encode($result->errors) : json_encode($result);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[HashPoster] X (Twitter) POST ERROR: ' . $error_msg);
                }
                return new WP_Error('api_error', 'X (Twitter) API error: ' . $error_msg);
            }
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] X (Twitter) Exception: ' . $e->getMessage());
            }
            return new WP_Error('api_error', 'X (Twitter) Exception: ' . $e->getMessage());
        }
    }

    private function publish_to_facebook( $content, $media ) {
        // Facebook Graph API: Use /{page-id}/feed with a Page Access Token
        $creds = $this->credentials['facebook'];
        if (empty($creds['access_token']) || empty($creds['page_id'])) {
            return new WP_Error('missing_credentials', 'Missing Facebook credentials.');
        }
        $endpoint = 'https://graph.facebook.com/' . $creds['page_id'] . '/feed';
        $body = array(
            'message' => $content,
            'access_token' => $creds['access_token'],
        );
        $args = array(
            'body'    => $body,
            'timeout' => 15,
        );
        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'Facebook API error: ' . wp_remote_retrieve_body($response));
        }
        return true;
    }

    private function publish_to_linkedin( $content, $media ) {
        // LinkedIn: Post to organization (company) page using w_member_social
        $creds = $this->credentials['linkedin'];
        if (
            empty($creds['client_id']) ||
            empty($creds['client_secret']) ||
            empty($creds['organization_urn'])
        ) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] LinkedIn missing credentials');
            }
            return new WP_Error('missing_credentials', 'Missing LinkedIn business page credentials.');
        }

        // You must obtain an access token using OAuth 2.0 with w_member_social scope.
        $access_token = $creds['access_token'] ?? '';
        if (empty($access_token)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] LinkedIn missing access token');
            }
            return new WP_Error('missing_credentials', 'LinkedIn access token is required for posting.');
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] LinkedIn attempting to post with org URN: ' . $creds['organization_urn']);
        }

        $endpoint = 'https://api.linkedin.com/v2/ugcPosts';
        
        // Base share content
        $share_content = [
            'shareCommentary' => ['text' => $content],
            'shareMediaCategory' => 'NONE'
        ];
        
        // If media image exists, prepare it for LinkedIn
        if (!empty($media['image'])) {
            try {
                // LinkedIn requires a more complex flow for images
                // First register the image upload
                $register_endpoint = 'https://api.linkedin.com/v2/assets?action=registerUpload';
                $register_body = [
                    'registerUploadRequest' => [
                        'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
                        'owner' => $creds['organization_urn'],
                        'serviceRelationships' => [
                            [
                                'relationshipType' => 'OWNER',
                                'identifier' => 'urn:li:userGeneratedContent'
                            ]
                        ]
                    ]
                ];
                
                $register_args = [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type'  => 'application/json',
                        'X-Restli-Protocol-Version' => '2.0.0'
                    ],
                    'body' => wp_json_encode($register_body),
                    'timeout' => 15,
                ];
                
                $register_response = wp_remote_post($register_endpoint, $register_args);
                
                if (!is_wp_error($register_response) && wp_remote_retrieve_response_code($register_response) === 200) {
                    $register_data = json_decode(wp_remote_retrieve_body($register_response), true);
                    
                    if (!empty($register_data['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl']) &&
                        !empty($register_data['value']['asset'])) {
                        
                        $upload_url = $register_data['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'];
                        $asset_id = $register_data['value']['asset'];
                        
                        // Upload the image
                        $image_data = file_get_contents($media['image']);
                        if ($image_data) {
                            $upload_args = [
                                'headers' => [
                                    'Authorization' => 'Bearer ' . $access_token,
                                ],
                                'body' => $image_data,
                                'method' => 'PUT',
                                'timeout' => 30,
                            ];
                            
                            $upload_response = wp_remote_request($upload_url, $upload_args);
                            
                            if (!is_wp_error($upload_response) && 
                                wp_remote_retrieve_response_code($upload_response) === 201) {
                                
                                // Successfully uploaded, now attach to post
                                $share_content['shareMediaCategory'] = 'IMAGE';
                                $share_content['media'] = [[
                                    'status' => 'READY',
                                    'description' => [
                                        'text' => substr($content, 0, 200)
                                    ],
                                    'media' => $asset_id,
                                    'title' => [
                                        'text' => get_the_title($media['post_id']) ?? 'Post'
                                    ]
                                ]];
                                
                                if (defined('WP_DEBUG') && WP_DEBUG) {
                                    error_log('[HashPoster] LinkedIn image upload successful: ' . $asset_id);
                                }
                            } else {
                                if (defined('WP_DEBUG') && WP_DEBUG) {
                                    error_log('[HashPoster] LinkedIn image upload failed: ' . 
                                        print_r($upload_response, true));
                                }
                            }
                        }
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[HashPoster] LinkedIn image registration failed: ' . 
                            print_r($register_response, true));
                    }
                }
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[HashPoster] LinkedIn image exception: ' . $e->getMessage());
                }
            }
        }
        
        $body = [
            'author' => $creds['organization_urn'],
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => $share_content
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC']
        ];
        
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'body' => wp_json_encode($body),
            'timeout' => 15,
        ];
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] LinkedIn request body: ' . print_r($body, true));
        }
        
        $response = wp_remote_post($endpoint, $args);
        
        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] LinkedIn API WP_Error: ' . $response->get_error_message());
            }
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[HashPoster] LinkedIn API response code: ' . $code);
            error_log('[HashPoster] LinkedIn API response body: ' . $response_body);
        }
        
        if ($code !== 201) {
            return new WP_Error('api_error', 'LinkedIn API error: ' . $response_body);
        }
        
        return true;
    }

    private function publish_to_bluesky( $content, $media ) {
        // Bluesky AT Protocol: Use ATProto endpoints for login and posting
        $creds = $this->credentials['bluesky'];
        if (empty($creds['handle']) || empty($creds['app_password'])) {
            return new WP_Error('missing_credentials', 'Missing Bluesky credentials.');
        }
        // Step 1: Login to get JWT and DID
        $login = wp_remote_post('https://bsky.social/xrpc/com.atproto.server.createSession', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode(array(
                'identifier' => $creds['handle'],
                'password'   => $creds['app_password'],
            )),
            'timeout' => 15,
        ));
        if (is_wp_error($login)) return $login;
        $login_body = json_decode(wp_remote_retrieve_body($login), true);
        if (empty($login_body['accessJwt']) || empty($login_body['did'])) {
            return new WP_Error(
                'api_error',
                'Bluesky login failed: ' . wp_remote_retrieve_body($login)
            );
        }
        $jwt = $login_body['accessJwt'];
        $did = $login_body['did'];
        
        // Modify the record to properly create clickable links in Bluesky
        $text = $content;
        // Parse any URLs in the content for embedding - don't automatically convert to Bitly
        preg_match_all('/https?:\/\/[^\s]+/', $text, $matches);
        
        // Prepare facets for URL linking as they appear in the original content
        $facets = [];
        foreach ($matches[0] as $url) {
            $start = strpos($text, $url);
            if ($start !== false) {
                $end = $start + strlen($url);
                $facets[] = [
                    'index' => [
                        'byteStart' => $start,
                        'byteEnd' => $end
                    ],
                    'features' => [
                        [
                            '$type' => 'app.bsky.richtext.facet#link',
                            'uri' => $url  // Use the URL exactly as it appears in content
                        ]
                    ]
                ];
            }
        }
        
        // Enhanced record with media if available
        $record = [
            'text' => $text,
            '$type' => 'app.bsky.feed.post',
            'createdAt' => gmdate('c'),
        ];
        
        if (!empty($facets)) {
            $record['facets'] = $facets;
        }
        
        // If we have a featured image, attach it to the Bluesky post
        if (!empty($media['image'])) {
            // For Bluesky, we need to upload the image to their server first
            $image_data = file_get_contents($media['image']);
            
            if ($image_data) {
                $boundary = wp_generate_password(24, false);
                $image_args = [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $jwt,
                        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                    ],
                    'timeout' => 30,
                ];
                
                // Create multipart body
                $body = '--' . $boundary . "\r\n";
                $body .= 'Content-Disposition: form-data; name="file"; filename="image.jpg"' . "\r\n";
                $body .= 'Content-Type: image/jpeg' . "\r\n\r\n";
                $body .= $image_data . "\r\n";
                $body .= '--' . $boundary . '--';
                
                $image_args['body'] = $body;
                
                // Upload image
                $upload_response = wp_remote_post(
                    'https://bsky.social/xrpc/com.atproto.repo.uploadBlob',
                    $image_args
                );
                
                if (!is_wp_error($upload_response) && 
                    wp_remote_retrieve_response_code($upload_response) === 200) {
                    
                    $blob_data = json_decode(wp_remote_retrieve_body($upload_response), true);
                    
                    if (!empty($blob_data['blob'])) {
                        // Add image to post
                        $record['embed'] = [
                            '$type' => 'app.bsky.embed.images',
                            'images' => [
                                [
                                    'alt' => get_the_title($media['post_id']) ?? 'Image',
                                    'image' => $blob_data['blob']
                                ]
                            ]
                        ];
                        
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('[HashPoster] Bluesky image upload successful: ' . print_r($blob_data, true));
                        }
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[HashPoster] Bluesky image upload failed: ' . 
                            (is_wp_error($upload_response) ? 
                            $upload_response->get_error_message() : 
                            wp_remote_retrieve_body($upload_response)));
                    }
                }
            }
        }
        
        // Step 2: Post with enhanced record structure
        $endpoint = 'https://bsky.social/xrpc/com.atproto.repo.createRecord';
        $body = [
            'repo' => $did,
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ];
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($body),
            'timeout' => 15,
        );
        try {
            $response = wp_remote_post($endpoint, $args);
            // Log the response for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HashPoster] Bluesky API response: ' . print_r($response, true));
            }
            // Improved diagnostics: check for error in response
            if (is_wp_error($response)) {
                error_log('[HashPoster] Bluesky WP_Error: ' . $response->get_error_message());
                return $response;
            }
            $code = is_array($response) && isset($response['response']['code']) ? $response['response']['code'] : null;
            if ($code == 200) {
                return true;
            } else {
                $body = is_array($response) && isset($response['body']) ? $response['body'] : '';
                error_log('[HashPoster] Bluesky POST ERROR: ' . $body);
                return new WP_Error('api_error', 'Bluesky API error: ' . $body);
            }
        } catch (\Exception $e) {
            error_log('[HashPoster] Bluesky Exception: ' . $e->getMessage());
            return new WP_Error('api_error', 'Bluesky Exception: ' . $e->getMessage());
        }
    }

    private function publish_to_reddit( $content, $media ) {
        $creds = $this->credentials['reddit'];
        if (
            empty($creds['client_id']) ||
            empty($creds['client_secret']) ||
            empty($creds['username']) ||
            empty($creds['password']) ||
            empty($creds['subreddit'])
        ) {
            return new WP_Error('missing_credentials', 'Missing Reddit credentials.');
        }

        // Step 1: Get access token
        $token_response = wp_remote_post('https://www.reddit.com/api/v1/access_token', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($creds['client_id'] . ':' . $creds['client_secret']),
                'User-Agent' => 'HashPoster/1.0 by ' . $creds['username'],
            ),
            'body' => array(
                'grant_type' => 'password',
                'username' => $creds['username'],
                'password' => $creds['password'],
            ),
            'timeout' => 15,
        ));
        if (is_wp_error($token_response)) return $token_response;
        $token_data = json_decode(wp_remote_retrieve_body($token_response), true);
        if (empty($token_data['access_token'])) {
            return new WP_Error('api_error', 'Reddit token error: ' . wp_remote_retrieve_body($token_response));
        }
        $access_token = $token_data['access_token'];

        // Step 2: Post to subreddit
        $post_response = wp_remote_post('https://oauth.reddit.com/api/submit', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'User-Agent' => 'HashPoster/1.0 by ' . $creds['username'],
            ),
            'body' => array(
                'sr' => $creds['subreddit'],
                'kind' => 'self',
                'title' => wp_trim_words($content, 15, '...'),
                'text' => $content,
                'resubmit' => true,
                'api_type' => 'json',
            ),
            'timeout' => 15,
        ));
        if (is_wp_error($post_response)) return $post_response;
        $post_data = json_decode(wp_remote_retrieve_body($post_response), true);
        if (!empty($post_data['json']['errors'])) {
            return new WP_Error('api_error', 'Reddit API error: ' . json_encode($post_data['json']['errors']));
        }
        return true;
    }

    /**
     * Helper: Get LinkedIn Person URN using an access token.
     * Returns string urn:li:person:xxxxxxx or WP_Error.
     */
    public function get_linkedin_person_urn( $access_token ) {
        $endpoint = 'https://api.linkedin.com/v2/me';
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Connection'    => 'Keep-Alive',
            ),
            'timeout' => 15,
        );
        $response = wp_remote_get($endpoint, $args);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'LinkedIn API error: ' . wp_remote_retrieve_body($response));
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['id'])) {
            return 'urn:li:person:' . $body['id'];
        }
        return new WP_Error('api_error', 'Could not retrieve LinkedIn Person URN.');
    }

    // --- Shortlink logic ---
    public function generate_shortlink( $url, $service = 'wordpress' ) {
        switch ( $service ) {
            case 'bitly':
                return $this->shorten_with_bitly( $url );
            case 'wordpress':
            default:
                return wp_get_shortlink( url_to_postid( $url ) );
        }
    }

    private function shorten_with_bitly( $url ) {
        $token = isset( $this->credentials['bitly']['token'] ) ? $this->credentials['bitly']['token'] : '';
        if ( !$token ) return $url;
        $endpoint = 'https://api-ssl.bitly.com/v4/shorten';
        $body = array('long_url' => $url);
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($body),
            'timeout' => 10,
        );
        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) return $url;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return !empty($data['link']) ? $data['link'] : $url;
    }

    public function get_short_url($url, $post_id = 0) {
        // Clear any previous results
        static $cache = [];
        $cache_key = md5($url . $post_id);
        
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }
        
        $shortlinks = get_option('hashposter_shortlinks', array());
        $use_bitly = !empty($shortlinks['bitly']['active']) && !empty($shortlinks['bitly']['token']);
        $bitly_token = $shortlinks['bitly']['token'] ?? '';

        if ($use_bitly && $bitly_token) {
            $endpoint = 'https://api-ssl.bitly.com/v4/shorten';
            $args = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bitly_token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode(['long_url' => $url]),
                'timeout' => 10,
            ];
            
            $response = wp_remote_post($endpoint, $args);
            
            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                
                if ($code === 200 && !empty($body['link'])) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[HashPoster] Bitly short URL successfully generated: ' . $body['link'] . ' for ' . $url);
                    }
                    $cache[$cache_key] = $body['link'];
                    return $body['link'];
                }
                
                // Bitly API error, log for debug
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[HashPoster] Bitly API error but response OK, using Bitly URL anyway: ' . print_r($body, true));
                    if (!empty($body['link'])) {
                        $cache[$cache_key] = $body['link'];
                        return $body['link'];
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[HashPoster] Bitly WP_Error: ' . $response->get_error_message());
                }
            }
        }
        
        // fallback to WordPress shortlink or original URL
        $shortlink = $post_id ? wp_get_shortlink($post_id) : false;
        $result = $shortlink ?: $url;
        $cache[$cache_key] = $result;
        return $result;
    }

    public function test_connection( $platform_or_service ) {
        // For demo: check credentials exist, in real use, make an API call
        if ( in_array( $platform_or_service, $this->platforms ) ) {
            return $this->validate_credentials( $platform_or_service ) ? true : 'Missing or invalid credentials.';
        }
        if ( $platform_or_service === 'bitly' ) {
            // Always reload credentials to get latest saved value
            $this->credentials = get_option( 'hashposter_shortlinks', array() );
            $token = $this->credentials['bitly']['token'] ?? '';
            if (!$token) return 'Missing Bitly token.';
            // Try a simple Bitly API call
            $endpoint = 'https://api-ssl.bitly.com/v4/user';
            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                ),
                'timeout' => 10,
            );
            $response = wp_remote_get($endpoint, $args);
            if (is_wp_error($response)) return $response->get_error_message();
            $code = wp_remote_retrieve_response_code($response);
            return ($code === 200) ? true : 'Bitly API error: ' . wp_remote_retrieve_body($response);
        }
        if ( $platform_or_service === 'wordpress' ) {
            return true;
        }
        return 'Unknown platform/service.';
    }
}
