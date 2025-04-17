<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_Settings {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_hashposter_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'admin_menu', array( $this, 'add_linkedin_org_urn_page' ) );
    }

    public function add_settings_page() {
        add_options_page(
            'HashPoster Settings',
            'HashPoster',
            'manage_options',
            'hashposter-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'hashposter_settings_group', 'hashposter_settings' );
        register_setting( 'hashposter_settings_group', 'hashposter_api_credentials' );
        register_setting( 'hashposter_settings_group', 'hashposter_post_cards' );
        register_setting( 'hashposter_settings_group', 'hashposter_shortlinks' );
        // ...other settings...
    }

    public function render_settings_page() {
        $settings = get_option( 'hashposter_settings', array() );
        $api_credentials = get_option( 'hashposter_api_credentials', array() );
        $post_cards = get_option( 'hashposter_post_cards', array() );
        $shortlinks = get_option( 'hashposter_shortlinks', array() );
        ?>
        <div class="wrap hashposter-settings">
            <h1><span class="dashicons dashicons-share" style="font-size: 28px; margin-right: 10px;"></span> HashPoster Settings</h1>
            
            <div class="hashposter-guide">
                <h2><span class="dashicons dashicons-lightbulb"></span> Quick Start Guide</h2>
                <ol>
                    <li><strong>General:</strong> Enable the plugin and logging if needed.</li>
                    <li><strong>Platforms:</strong> Activate platforms and enter API credentials.</li>
                    <li><strong>Post Cards:</strong> Customize how your posts appear on each platform.</li>
                    <li><strong>Short Links:</strong> Configure URL shortening options.</li>
                    <li><strong>Scheduling:</strong> Optionally enable delayed posting.</li>
                </ol>
                <div class="hashposter-tip">Need help getting API keys? <a href="https://github.com/phveektor/hashposter#platform-setup" target="_blank">See the documentation</a></div>
            </div>
            
            <?php if ( isset($_GET['settings-updated']) && $_GET['settings-updated'] ) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields( 'hashposter_settings_group' );
                wp_nonce_field( 'hashposter_settings_save', 'hashposter_settings_nonce' );
                ?>
                
                <div id="hashposter-tabs">
                    <ul>
                        <li><a href="#tab-general"><span class="dashicons dashicons-admin-settings"></span> General</a></li>
                        <li><a href="#tab-platforms"><span class="dashicons dashicons-share-alt"></span> Platforms</a></li>
                        <li><a href="#tab-postcards"><span class="dashicons dashicons-format-aside"></span> Post Cards</a></li>
                        <li><a href="#tab-shortlinks"><span class="dashicons dashicons-admin-links"></span> Short Links</a></li>
                        <li><a href="#tab-scheduling"><span class="dashicons dashicons-calendar-alt"></span> Scheduling</a></li>
                    </ul>
                    
                    <!-- General Tab -->
                    <div id="tab-general">
                        <h3>General Settings</h3>
                        <p>Enable or disable autoposting and logging features.</p>
                        
                        <div class="hashposter-option">
                            <label>
                                <input type="checkbox" name="hashposter_settings[enabled]" value="1" <?php checked( !empty($settings['enabled']) ); ?> />
                                Enable HashPoster
                            </label>
                            <span class="dashicons dashicons-info tooltip" title="Toggle to enable or disable autoposting."></span>
                            <p class="description">When enabled, your new posts will be automatically shared to your connected platforms.</p>
                        </div>
                        
                        <div class="hashposter-option">
                            <label>
                                <input type="checkbox" name="hashposter_settings[logging]" value="1" <?php checked( !empty($settings['logging']) ); ?> />
                                Enable Logging
                            </label>
                            <span class="dashicons dashicons-info tooltip" title="Enable to log all autoposting actions for debugging."></span>
                            <p class="description">Logs will be stored in your WordPress debug log if WP_DEBUG_LOG is enabled.</p>
                        </div>
                    </div>
                    
                    <!-- Platforms Tab -->
                    <div id="tab-platforms">
                        <h3>Social Media Platforms</h3>
                        <p>
                            Activate the platforms you want to share content to and enter your API credentials.
                        </p>
                        
                        <?php foreach ( array('x','facebook','linkedin','bluesky','reddit') as $platform ): ?>
                            <div class="platform-section">
                                <h4>
                                    <?php if ($platform === 'x'): ?>
                                        <span class="dashicons dashicons-twitter"></span> X (Twitter)
                                    <?php elseif ($platform === 'facebook'): ?>
                                        <span class="dashicons dashicons-facebook"></span> Facebook
                                    <?php elseif ($platform === 'linkedin'): ?>
                                        <span class="dashicons dashicons-linkedin"></span> LinkedIn
                                    <?php elseif ($platform === 'bluesky'): ?>
                                        <span style="margin-right:5px;">🦋</span> Bluesky
                                    <?php elseif ($platform === 'reddit'): ?>
                                        <span style="margin-right:5px;">🔴</span> Reddit
                                    <?php endif; ?>
                                </h4>
                                
                                <label>
                                    <input type="checkbox" name="hashposter_api_credentials[<?php echo $platform; ?>][active]" value="1" <?php checked( !empty($api_credentials[$platform]['active']) ); ?> />
                                    Activate <?php echo ucfirst($platform); ?>
                                </label>
                                
                                <div class="platform-fields">
                                    <?php if ($platform === 'x'): ?>
                                        <!-- X (Twitter) fields -->
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[x][key]" value="<?php echo esc_attr($api_credentials['x']['key'] ?? ''); ?>" placeholder="API Key" />
                                        </div>
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[x][secret]" value="<?php echo esc_attr($api_credentials['x']['secret'] ?? ''); ?>" placeholder="API Secret" />
                                        </div>
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[x][access_token]" value="<?php echo esc_attr($api_credentials['x']['access_token'] ?? ''); ?>" placeholder="Access Token" />
                                        </div>
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[x][access_token_secret]" value="<?php echo esc_attr($api_credentials['x']['access_token_secret'] ?? ''); ?>" placeholder="Access Token Secret" />
                                        </div>
                                        <div class="hashposter-tip">
                                            Get these from the <a href="https://developer.twitter.com/en/portal/dashboard" target="_blank">Twitter Developer Portal</a>
                                        </div>
                                    <?php elseif ($platform === 'facebook'): ?>
                                        <!-- Facebook fields -->
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[facebook][access_token]" value="<?php echo esc_attr($api_credentials['facebook']['access_token'] ?? ''); ?>" placeholder="Page Access Token" />
                                        </div>
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[facebook][page_id]" value="<?php echo esc_attr($api_credentials['facebook']['page_id'] ?? ''); ?>" placeholder="Page ID" />
                                        </div>
                                    <?php elseif ($platform === 'linkedin'): ?>
                                        <!-- LinkedIn fields -->
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[linkedin][client_id]" value="<?php echo esc_attr($api_credentials['linkedin']['client_id'] ?? ''); ?>" placeholder="Client ID" />
                                        </div>
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[linkedin][client_secret]" value="<?php echo esc_attr($api_credentials['linkedin']['client_secret'] ?? ''); ?>" placeholder="Client Secret" />
                                        </div>
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[linkedin][organization_urn]" value="<?php echo esc_attr($api_credentials['linkedin']['organization_urn'] ?? ''); ?>" placeholder="Organization URN" />
                                        </div>
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[linkedin][access_token]" value="<?php echo esc_attr($api_credentials['linkedin']['access_token'] ?? ''); ?>" placeholder="Access Token" />
                                        </div>
                                    <?php elseif ($platform === 'bluesky'): ?>
                                        <!-- Bluesky fields -->
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[bluesky][handle]" value="<?php echo esc_attr($api_credentials['bluesky']['handle'] ?? ''); ?>" placeholder="Handle" />
                                        </div>
                                        <div>
                                            <input type="password" name="hashposter_api_credentials[bluesky][app_password]" value="<?php echo esc_attr($api_credentials['bluesky']['app_password'] ?? ''); ?>" placeholder="App Password" />
                                        </div>
                                    <?php elseif ($platform === 'reddit'): ?>
                                        <!-- Reddit fields -->
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[reddit][client_id]" value="<?php echo esc_attr($api_credentials['reddit']['client_id'] ?? ''); ?>" placeholder="Client ID" />
                                        </div>
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[reddit][client_secret]" value="<?php echo esc_attr($api_credentials['reddit']['client_secret'] ?? ''); ?>" placeholder="Client Secret" />
                                        </div>
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[reddit][username]" value="<?php echo esc_attr($api_credentials['reddit']['username'] ?? ''); ?>" placeholder="Reddit Username" />
                                        </div>
                                        <div>
                                            <input type="password" name="hashposter_api_credentials[reddit][password]" value="<?php echo esc_attr($api_credentials['reddit']['password'] ?? ''); ?>" placeholder="Reddit Password" />
                                        </div>
                                        <div>
                                            <input type="text" name="hashposter_api_credentials[reddit][subreddit]" value="<?php echo esc_attr($api_credentials['reddit']['subreddit'] ?? ''); ?>" placeholder="Subreddit" />
                                        </div>
                                    <?php endif; ?>
                                    
                                    <button type="button" class="button hashposter-test-connection" data-platform="<?php echo $platform; ?>">Test Connection</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Post Cards Tab -->
                    <div id="tab-postcards">
                        <h3>Post Cards</h3>
                        <p>
                            Configure how your content appears when shared to social media platforms.
                        </p>
                        
                        <div class="template-preview" style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                            <h4 style="margin-top: 0;">Available Template Tags</h4>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                <code style="padding: 5px;">{title}</code>
                                <code style="padding: 5px;">{url}</code>
                                <code style="padding: 5px;">{short_url}</code>
                                <code style="padding: 5px;">{excerpt}</code>
                                <code style="padding: 5px;">{author}</code>
                                <code style="padding: 5px;">{date}</code>
                                <code style="padding: 5px;">{category}</code>
                                <code style="padding: 5px;">{tags}</code>
                                <code style="padding: 5px;">{site_name}</code>
                            </div>
                            <p class="hashposter-tip">Example: <code>{title} - {short_url} #blog by {author}</code></p>
                        </div>
                        
                        <h4>Post Template</h4>
                        <p>Customize how your posts will appear on social media:</p>
                        <textarea name="hashposter_post_cards[template]" rows="4" cols="60" placeholder="Post template. For example: {title} {short_url}"><?php echo esc_textarea($post_cards['template'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Short Links Tab -->
                    <div id="tab-shortlinks">
                        <h3>Short Links</h3>
                        <p>Configure URL shortening for your shared posts.</p>
                        
                        <div class="shortlink-options" style="display: flex; gap: 20px; margin-bottom: 20px;">
                            <!-- WordPress Shortlinks -->
                            <div class="shortlink-section" style="flex: 1;">
                                <h4><span class="dashicons dashicons-wordpress"></span> WordPress</h4>
                                <label>
                                    <input type="checkbox" name="hashposter_shortlinks[wordpress][active]" value="1" <?php checked( !empty($shortlinks['wordpress']['active']) ); ?> />
                                    Use WordPress Shortlinks
                                </label>
                                <p class="description">Uses WordPress built-in shortlink feature (example: ?p=123)</p>
                            </div>
                            
                            <!-- Bitly -->
                            <div class="shortlink-section" style="flex: 1;">
                                <h4><span style="font-weight: bold;">bit.ly</span></h4>
                                <label>
                                    <input type="checkbox" name="hashposter_shortlinks[bitly][active]" value="1" <?php checked( !empty($shortlinks['bitly']['active']) ); ?> />
                                    Use Bitly Shortlinks
                                </label>
                                <div style="margin-top: 10px;">
                                    <input type="text" name="hashposter_shortlinks[bitly][token]" value="<?php echo esc_attr($shortlinks['bitly']['token'] ?? ''); ?>" placeholder="Bitly Access Token" />
                                    <p class="hashposter-tip">
                                        <a href="https://dev.bitly.com/docs/getting-started/authentication/" target="_blank">How to get Bitly token</a>
                                    </p>
                                </div>
                                <div style="margin-top: 10px;">
                                    <button type="button" class="button hashposter-test-connection" data-platform="bitly">Test Bitly Connection</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Scheduling Tab -->
                    <div id="tab-scheduling">
                        <h3>Scheduling</h3>
                        <p>Configure delayed posting to your social media platforms.</p>
                        
                        <div class="hashposter-option">
                            <label>
                                <input type="checkbox" name="hashposter_settings[scheduling]" value="1" <?php checked( !empty($settings['scheduling']) ); ?> />
                                Enable Scheduling
                            </label>
                            <span class="dashicons dashicons-info tooltip" title="Enable to schedule posts for later publishing."></span>
                        </div>
                        
                        <div class="delay-option" style="margin-top: 15px;">
                            <label for="delay_minutes">Delay Duration:</label>
                            <div style="display: flex; align-items: center; max-width: 300px;">
                                <input type="number" id="delay_minutes" name="hashposter_settings[delay_minutes]" value="<?php echo esc_attr($settings['delay_minutes'] ?? '0'); ?>" min="0" style="width: 80px;"/>
                                <span style="margin-left: 10px;">minutes</span>
                            </div>
                            <p class="description">Posts will be shared after this delay following publication. Use 0 for immediate posting.</p>
                        </div>
                    </div>
                </div>
                
                <?php submit_button('Save Settings', 'primary', 'submit', false, ['style' => 'margin-top: 20px;']); ?>
            </form>
        </div>
        <?php
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'hashposter_admin', 'nonce' );
        $platform = sanitize_text_field( $_POST['platform'] ?? '' );
        if ( ! $platform ) {
            wp_send_json_error( array( 'message' => 'Invalid platform/service.' ) );
        }
        require_once HASHPOSTER_PATH . 'includes/class-api-handler.php';
        $api = new HashPoster_API_Handler();
        $api->initialize();
        $result = $api->test_connection( $platform );
        if ( $result === true ) {
            wp_send_json_success( array( 'message' => 'Connection successful!' ) );
        } else {
            wp_send_json_error( array( 'message' => is_string($result) ? $result : 'Connection failed.' ) );
        }
    }

    public function admin_notices() {
        if ( isset($_GET['hashposter_notice']) ) {
            $msg = sanitize_text_field($_GET['hashposter_notice']);
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }

    public function add_linkedin_org_urn_page() {
        add_submenu_page(
            null,
            'Get LinkedIn Organization URN',
            'Get LinkedIn Organization URN',
            'manage_options',
            'hashposter-get-linkedin-org-urn',
            array( $this, 'render_linkedin_org_urn_page' )
        );
    }

    public function render_linkedin_org_urn_page() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('Unauthorized');
        }
        $urn = '';
        $error = '';
        $orgs = array();
        if ( isset($_POST['hashposter_linkedin_token']) && check_admin_referer('hashposter_get_org_urn') ) {
            $access_token = sanitize_text_field($_POST['hashposter_linkedin_token']);

            if (!empty($_POST['hashposter_linkedin_client_id']) && !empty($_POST['hashposter_linkedin_client_secret'])) {
                $client_id = sanitize_text_field($_POST['hashposter_linkedin_client_id']);
                $client_secret = sanitize_text_field($_POST['hashposter_linkedin_client_secret']);
                $introspect = wp_remote_post('https://www.linkedin.com/oauth/v2/introspectToken', array(
                    'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
                    'body' => http_build_query(array(
                        'token' => $access_token,
                        'client_id' => $client_id,
                        'client_secret' => $client_secret,
                    )),
                    'timeout' => 15,
                ));
                if (!is_wp_error($introspect)) {
                    $introspect_body = wp_remote_retrieve_body($introspect);
                    $introspect_data = json_decode($introspect_body, true);
                    if (isset($introspect_data['active']) && !$introspect_data['active']) {
                        $error = 'Access token is not active or is invalid. Please generate a new token.';
                    }
                }
            }

            if (!$error) {
                $endpoint = 'https://api.linkedin.com/v2/organizationalEntityAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED';
                $args = array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Connection'    => 'Keep-Alive',
                    ),
                    'timeout' => 15,
                );
                $response = wp_remote_get($endpoint, $args);
                if (is_wp_error($response)) {
                    $error = $response->get_error_message();
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    if ($code === 403) {
                        $error = 'Access denied. Your LinkedIn app and token must have the <code>r_organization_social</code> and <code>w_member_social</code> permissions, and you must be an admin of the organization. See <a href="https://docs.microsoft.com/en-us/linkedin/marketing/integrations/community-management/organization-lookup-api" target="_blank">LinkedIn Organization Lookup API docs</a>.';
                    } elseif ($code !== 200) {
                        $error = 'LinkedIn API error: ' . $body;
                    } else {
                        $body = json_decode($body, true);
                        if (!empty($body['elements'])) {
                            foreach ($body['elements'] as $element) {
                                if (!empty($element['organizationalTarget'])) {
                                    $orgs[] = $element['organizationalTarget'];
                                }
                            }
                        }
                        if (empty($orgs)) {
                            $error = 'No organizations found for this access token. Make sure you are an admin of a LinkedIn organization and your token has the required scopes.';
                        }
                    }
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>Get LinkedIn Organization URN</h1>
            <form method="post">
                <?php wp_nonce_field('hashposter_get_org_urn'); ?>
                <p>
                    <label for="hashposter_linkedin_token"><strong>LinkedIn Access Token:</strong></label><br>
                    <input type="text" id="hashposter_linkedin_token" name="hashposter_linkedin_token" style="width: 500px;" required>
                </p>
                <p>
                    <label for="hashposter_linkedin_client_id"><strong>Client ID (optional for token check):</strong></label><br>
                    <input type="text" id="hashposter_linkedin_client_id" name="hashposter_linkedin_client_id" style="width: 500px;">
                </p>
                <p>
                    <label for="hashposter_linkedin_client_secret"><strong>Client Secret (optional for token check):</strong></label><br>
                    <input type="text" id="hashposter_linkedin_client_secret" name="hashposter_linkedin_client_secret" style="width: 500px;">
                </p>
                <p>
                    <input type="submit" class="button button-primary" value="Get Organization URNs">
                </p>
            </form>
            <?php if ($orgs): ?>
                <div style="margin-top:20px;padding:10px;background:#e7f7e7;border:1px solid #b2d8b2;">
                    <strong>Your LinkedIn Organization URNs:</strong><br>
                    <?php foreach ($orgs as $org): ?>
                        <code><?php echo esc_html($org); ?></code><br>
                    <?php endforeach; ?>
                    <p style="margin-top:10px;">Copy the URN for your business page and use it in your HashPoster LinkedIn settings.</p>
                </div>
            <?php elseif ($error): ?>
                <div style="margin-top:20px;padding:10px;background:#fbeaea;border:1px solid #e0b4b4;color:#a94442;">
                    <strong>Error:</strong> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            <p style="margin-top:30px;">
                <strong>Instructions:</strong><br>
                - Your LinkedIn app must have both <code>r_organization_social</code> and <code>w_member_social</code> permissions.<br>
                - Your access token must be generated with both scopes.<br>
                - You must be an admin of the organization.<br>
                - Optionally, enter your Client ID and Client Secret to check if your token is valid.<br>
                - Paste your token above and click "Get Organization URNs".<br>
                - If you see a 403 error, check your app permissions in the LinkedIn Developer Portal.<br>
            </p>
        </div>
        <?php
    }
}
?>
