<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_Settings {
    private $oauth_handler;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'admin_menu', array( $this, 'add_linkedin_org_urn_page' ) );
        add_action( 'wp_ajax_hashposter_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_hashposter_oauth_initiate', array( $this, 'ajax_oauth_initiate' ) );
        add_action( 'wp_ajax_hashposter_oauth_disconnect', array( $this, 'ajax_oauth_disconnect' ) );

        // Initialize OAuth handler
        $this->oauth_handler = new HashPoster_OAuth_Handler();
    }

    public function add_settings_page() {
        add_menu_page(
            'HashPoster Settings',
            'HashPoster',
            'manage_options',
            'hashposter-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-share',
            30
        );

        // Add settings as first submenu
        add_submenu_page(
            'hashposter-settings',
            'Settings',
            'Settings',
            'manage_options',
            'hashposter-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'hashposter_settings_group', 'hashposter_settings', array($this, 'validate_settings_callback') );
        register_setting( 'hashposter_settings_group', 'hashposter_api_credentials', array($this, 'validate_api_credentials_callback') );
        register_setting( 'hashposter_settings_group', 'hashposter_post_cards', array($this, 'validate_post_cards_callback') );
        register_setting( 'hashposter_settings_group', 'hashposter_shortlinks', array($this, 'validate_shortlinks_callback') );
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
                            Connect your social media accounts using OAuth authentication. This provides secure, automatic token management and eliminates permission issues.
                        </p>

                        <?php
                        $platforms = array(
                            'x' => array('name' => 'X (Twitter)', 'icon' => 'dashicons-twitter', 'description' => 'Connect your X account for automated posting'),
                            'facebook' => array('name' => 'Facebook', 'icon' => 'dashicons-facebook', 'description' => 'Connect your Facebook Page for automated posting'),
                            'linkedin' => array('name' => 'LinkedIn', 'icon' => 'dashicons-linkedin', 'description' => 'Connect your LinkedIn account for automated posting'),
                            'bluesky' => array('name' => 'Bluesky', 'icon' => '🦋', 'description' => 'Connect your Bluesky account for automated posting')
                        );

                        foreach ( $platforms as $platform_key => $platform_info ):
                            $is_connected = $this->oauth_handler->is_connected( $platform_key );
                        ?>
                            <div class="platform-section">
                                <h4>
                                    <?php if ($platform_info['icon'] === '🦋'): ?>
                                        <span style="margin-right:5px;"><?php echo $platform_info['icon']; ?></span> <?php echo $platform_info['name']; ?>
                                    <?php else: ?>
                                        <span class="dashicons <?php echo $platform_info['icon']; ?>"></span> <?php echo $platform_info['name']; ?>
                                    <?php endif; ?>
                                </h4>

                                <p class="platform-description"><?php echo $platform_info['description']; ?></p>

                                <div class="platform-status">
                                    <?php if ( $is_connected ): ?>
                                        <div class="connection-status connected">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <strong>Connected</strong>
                                            <button type="button" class="button button-secondary disconnect-btn" data-platform="<?php echo $platform_key; ?>">
                                                Disconnect
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="connection-status not-connected">
                                            <span class="dashicons dashicons-warning"></span>
                                            <strong>Not Connected</strong>
                                            <button type="button" class="button button-primary connect-btn" data-platform="<?php echo $platform_key; ?>">
                                                Connect Account
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ( $platform_key === 'x' && !$is_connected ): ?>
                                    <div class="platform-setup-notice" style="background: #e3f2fd; padding: 15px; border-radius: 6px; margin-top: 10px; border-left: 4px solid #1DA1F2;">
                                        <h5 style="margin-top: 0; color: #1976d2;">X (Twitter) OAuth 2.0 Setup</h5>
                                        <ol style="margin-bottom: 10px;">
                                            <li>Go to <a href="https://developer.twitter.com/en/portal/dashboard" target="_blank">X Developer Portal</a></li>
                                            <li>Create a new app and set permissions to "Read and Write"</li>
                                            <li>Add OAuth 2.0 redirect URL: <code style="background: #e9ecef; padding: 2px 4px; border-radius: 3px;"><?php echo esc_url(admin_url('admin.php?page=hashposter-settings&hashposter_oauth_callback=x')); ?></code></li>
                                            <li>Enter Client ID and Client Secret below</li>
                                        </ol>
                                    </div>

                                    <!-- OAuth 2.0 Credentials -->
                                    <div class="platform-credentials" style="margin-top: 15px;">
                                        <table class="form-table">
                                            <tr>
                                                <th><label for="hashposter_api_credentials_x_client_id">Client ID</label></th>
                                                <td>
                                                    <input type="text" name="hashposter_api_credentials[x][client_id]" id="hashposter_api_credentials_x_client_id" value="<?php echo esc_attr($api_credentials['x']['client_id'] ?? ''); ?>" class="regular-text" />
                                                </td>
                                            </tr>
                                            <tr>
                                                <th><label for="hashposter_api_credentials_x_client_secret">Client Secret</label></th>
                                                <td>
                                                    <input type="password" name="hashposter_api_credentials[x][client_secret]" id="hashposter_api_credentials_x_client_secret" value="<?php echo esc_attr($api_credentials['x']['client_secret'] ?? ''); ?>" class="regular-text" />
                                                </td>
                                            </tr>
                                            <tr>
                                                <th><label for="hashposter_api_credentials_x_active">Enable X Posting</label></th>
                                                <td>
                                                    <input type="checkbox" name="hashposter_api_credentials[x][active]" id="hashposter_api_credentials_x_active" value="1" <?php checked( !empty($api_credentials['x']['active']) ); ?> />
                                                    <span class="description">Check to enable autoposting to X</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $platform_key === 'linkedin' && !$is_connected ): ?>
                                    <div class="platform-oauth-notice" style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-top: 10px; border-left: 4px solid #28a745;">
                                        <h5 style="margin-top: 0; color: #28a745;">OAuth Setup Required</h5>
                                        <ol style="margin-bottom: 10px;">
                                            <li>Go to <a href="https://developer.linkedin.com/" target="_blank">LinkedIn Developer Portal</a></li>
                                            <li>Create a new app</li>
                                            <li>Add this OAuth 2.0 redirect URL: <code style="background: #e9ecef; padding: 2px 4px; border-radius: 3px;"><?php echo esc_url(admin_url('admin.php?page=hashposter-settings&hashposter_oauth_callback=linkedin')); ?></code></li>
                                            <li>Enter Client ID and Client Secret below</li>
                                        </ol>
                                    </div>
                                    <div class="platform-credentials" style="margin-top: 15px;">
                                        <table class="form-table">
                                            <tr>
                                                <th><label for="hashposter_api_credentials_linkedin_client_id">Client ID</label></th>
                                                <td><input type="text" name="hashposter_api_credentials[linkedin][client_id]" id="hashposter_api_credentials_linkedin_client_id" value="<?php echo esc_attr($api_credentials['linkedin']['client_id'] ?? ''); ?>" class="regular-text" /></td>
                                            </tr>
                                            <tr>
                                                <th><label for="hashposter_api_credentials_linkedin_client_secret">Client Secret</label></th>
                                                <td><input type="password" name="hashposter_api_credentials[linkedin][client_secret]" id="hashposter_api_credentials_linkedin_client_secret" value="<?php echo esc_attr($api_credentials['linkedin']['client_secret'] ?? ''); ?>" class="regular-text" /></td>
                                            </tr>
                                            <tr>
                                                <th><label for="hashposter_api_credentials_linkedin_active">Enable LinkedIn Posting</label></th>
                                                <td>
                                                    <input type="checkbox" name="hashposter_api_credentials[linkedin][active]" id="hashposter_api_credentials_linkedin_active" value="1" <?php checked( !empty($api_credentials['linkedin']['active']) ); ?> />
                                                    <span class="description">Check to enable autoposting to LinkedIn</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $platform_key === 'facebook' && !$is_connected ): ?>
                                    <div class="platform-setup-notice" style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-top: 10px; border-left: 4px solid #007cba;">
                                        <h5 style="margin-top: 0; color: #007cba;">Setup Required</h5>
                                        <ol style="margin-bottom: 10px;">
                                            <li>Go to <a href="https://developers.facebook.com/" target="_blank">Facebook Developers</a></li>
                                            <li>Create a new app with "Consumer" use case</li>
                                            <li>Add "Facebook Login" product to your app</li>
                                            <li>Go to <a href="https://developers.facebook.com/tools/explorer/" target="_blank">Graph API Explorer</a></li>
                                            <li>Select your app, get User Access Token, then convert to Page Access Token</li>
                                            <li>Ensure token has <code>pages_manage_posts</code> and <code>pages_show_list</code> permissions</li>
                                            <li>Enter the credentials below</li>
                                        </ol>
                                    </div>
                                    <div class="platform-credentials" style="margin-top: 15px;">
                                        <table class="form-table">
                                            <tr>
                                                <th><label for="hashposter_api_credentials_facebook_access_token">Page Access Token</label></th>
                                                <td>
                                                    <input type="password" name="hashposter_api_credentials[facebook][access_token]" id="hashposter_api_credentials_facebook_access_token" value="<?php echo esc_attr($api_credentials['facebook']['access_token'] ?? ''); ?>" class="regular-text" />
                                                    <p class="description">Long-lived Page Access Token with pages_manage_posts permission</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th><label for="hashposter_api_credentials_facebook_page_id">Page ID</label></th>
                                                <td>
                                                    <input type="text" name="hashposter_api_credentials[facebook][page_id]" id="hashposter_api_credentials_facebook_page_id" value="<?php echo esc_attr($api_credentials['facebook']['page_id'] ?? ''); ?>" class="regular-text" />
                                                    <p class="description">Numeric ID of your Facebook Page</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th><label for="hashposter_api_credentials_facebook_active">Enable Facebook Posting</label></th>
                                                <td>
                                                    <input type="checkbox" name="hashposter_api_credentials[facebook][active]" id="hashposter_api_credentials_facebook_active" value="1" <?php checked( !empty($api_credentials['facebook']['active']) ); ?> />
                                                    <span class="description">Check to enable autoposting to Facebook</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $platform_key === 'bluesky' && !$is_connected ): ?>
                                    <div class="platform-setup-notice" style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-top: 10px; border-left: 4px solid #007cba;">
                                        <h5 style="margin-top: 0; color: #007cba;">Setup Required</h5>
                                        <ol style="margin-bottom: 0;">
                                            <li>Log into your Bluesky account at <a href="https://bsky.app/" target="_blank">bsky.app</a></li>
                                            <li>Go to Settings → Privacy and Security → App Passwords</li>
                                            <li>Click "Add App Password" and create a password for HashPoster</li>
                                            <li>Copy the generated app password (you can't see it again!)</li>
                                            <li>Enter your handle and app password below</li>
                                        </ol>
                                    </div>
                                    <div class="platform-credentials" style="margin-top: 15px;">
                                        <table class="form-table">
                                            <tr>
                                                <th><label for="hashposter_api_credentials_bluesky_handle">Handle</label></th>
                                                <td>
                                                    <input type="text" name="hashposter_api_credentials[bluesky][handle]" id="hashposter_api_credentials_bluesky_handle" value="<?php echo esc_attr($api_credentials['bluesky']['handle'] ?? ''); ?>" class="regular-text" placeholder="yourname.bsky.social" />
                                                    <p class="description">Your full Bluesky handle (e.g., yourname.bsky.social)</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th><label for="hashposter_api_credentials_bluesky_app_password">App Password</label></th>
                                                <td>
                                                    <input type="password" name="hashposter_api_credentials[bluesky][app_password]" id="hashposter_api_credentials_bluesky_app_password" value="<?php echo esc_attr($api_credentials['bluesky']['app_password'] ?? ''); ?>" class="regular-text" />
                                                    <p class="description">App password generated in Bluesky settings (not your account password)</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th><label for="hashposter_api_credentials_bluesky_active">Enable Bluesky Posting</label></th>
                                                <td>
                                                    <input type="checkbox" name="hashposter_api_credentials[bluesky][active]" id="hashposter_api_credentials_bluesky_active" value="1" <?php checked( !empty($api_credentials['bluesky']['active']) ); ?> />
                                                    <span class="description">Check to enable autoposting to Bluesky</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div><!-- .platform-section -->
                        <?php endforeach; ?>

                        <!-- OAuth JavaScript -->
                        <script type="text/javascript">
                        jQuery(document).ready(function($) {
                            $('.connect-btn').on('click', function() {
                                var platform = $(this).data('platform');
                                var $button = $(this);
                                var $status = $button.closest('.connection-status');

                                $button.prop('disabled', true).text('Connecting...');

                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'hashposter_oauth_initiate',
                                        platform: platform,
                                        nonce: '<?php echo wp_create_nonce( "hashposter_oauth" ); ?>'
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            if (response.data.auth_url) {
                                                window.location.href = response.data.auth_url;
                                            } else {
                                                // Direct connection successful (Bluesky, X), reload to show updated status
                                                location.reload();
                                            }
                                        } else {
                                            $status.append('<div class="error">Failed to connect: ' + (response.data || 'Unknown error') + '</div>');
                                            $button.prop('disabled', false).text('Connect Account');
                                        }
                                    },
                                    error: function() {
                                        $status.append('<div class="error">Connection failed. Please try again.</div>');
                                        $button.prop('disabled', false).text('Connect Account');
                                    }
                                });
                            });

                            $('.disconnect-btn').on('click', function() {
                                var platform = $(this).data('platform');
                                var $button = $(this);
                                var $status = $button.closest('.connection-status');

                                if (!confirm('Are you sure you want to disconnect ' + platform + '?')) {
                                    return;
                                }

                                $button.prop('disabled', true);

                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'hashposter_oauth_disconnect',
                                        platform: platform,
                                        nonce: '<?php echo wp_create_nonce( "hashposter_oauth" ); ?>'
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            location.reload();
                                        } else {
                                            alert('Failed to disconnect: ' + (response.data || 'Unknown error'));
                                            $button.prop('disabled', false);
                                        }
                                    },
                                    error: function() {
                                        alert('Connection failed. Please try again.');
                                        $button.prop('disabled', false);
                                    }
                                });
                            });
                        });
                        </script>

                        <style>
                        .platform-section {
                            background: #fff;
                            border: 1px solid #ddd;
                            border-radius: 8px;
                            padding: 20px;
                            margin-bottom: 20px;
                        }
                        .platform-section h4 {
                            margin-top: 0;
                            color: #23282d;
                        }
                        .platform-description {
                            color: #666;
                            margin-bottom: 15px;
                        }
                        .connection-status {
                            display: flex;
                            align-items: center;
                            gap: 10px;
                        }
                        .connection-status.connected {
                            color: #28a745;
                        }
                        .connection-status.not-connected {
                            color: #dc3545;
                        }
                        .connection-status .dashicons {
                            font-size: 18px;
                        }
                        .disconnect-btn {
                            margin-left: auto;
                        }
                        </style>
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
                        
                        <h4>Per-Platform Content Structure = Non-Negotiable</h4>
                        <div class="hashposter-content-structure" style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #007cba;">
                            <p style="margin-top: 0; font-weight: bold; color: #007cba;">✅ LinkedIn & Facebook thrive on longer, value-packed captions.</p>
                            <ul style="margin-bottom: 15px;">
                                <li>300–450 words is the sweet spot.</li>
                                <li>Add a link after you've given value (algos punish link-first posts).</li>
                            </ul>
                            
                            <p style="font-weight: bold; color: #007cba;">✅ X & Bluesky are short-form, punchy & link-driven.</p>
                            <ul style="margin-bottom: 0;">
                                <li>180–220 words max, often better as a thread or a 1-2 punch post.</li>
                                <li>Hook first line, then CTA + link.</li>
                            </ul>
                        </div>
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

            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.test-connection-btn').on('click', function() {
                    var $button = $(this);
                    var platform = $button.data('platform');
                    var $status = $('#connection-status-' + platform);
                    
                    // Disable button and show loading
                    $button.prop('disabled', true).text('Testing...');
                    $status.html('<span style="color: blue;">Testing connection...</span>');
                    
                    // Make AJAX call
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'hashposter_test_connection',
                            platform: platform,
                            nonce: hashposterAdmin.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color: green;">✓ Connected successfully</span>');
                            } else {
                                var errorMsg = response.data || 'Unknown error';
                                $status.html('<span style="color: red;">✗ Connection failed: ' + errorMsg + '</span>');
                            }
                        },
                        error: function(xhr, status, error) {
                            $status.html('<span style="color: red;">✗ AJAX error: ' + error + '</span>');
                        },
                        complete: function() {
                            // Re-enable button
                            $button.prop('disabled', false).text('Test Connection');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function admin_notices() {
        if ( isset($_GET['hashposter_notice']) ) {
            $msg = sanitize_text_field($_GET['hashposter_notice']);
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }

        // Handle OAuth success notices
        if ( isset($_GET['oauth_success']) ) {
            $platform = sanitize_text_field($_GET['oauth_success']);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(ucfirst($platform)) . ' connected successfully!</p></div>';
        }
    }

    /**
     * Show OAuth setup notice for platforms that need configuration
     */
    private function show_oauth_setup_notice( $platform ) {
        $platform_names = array(
            'x' => 'X (Twitter)',
            'linkedin' => 'LinkedIn',
            'facebook' => 'Facebook',
            'bluesky' => 'Bluesky'
        );

        $platform_name = $platform_names[$platform] ?? ucfirst($platform);

        $messages = array(
            'x' => 'To connect X (Twitter), you need to create a Twitter app and configure OAuth 1.0a. Please enter your API keys in the Platforms tab first.',
            'linkedin' => 'To connect LinkedIn, you need to create a LinkedIn app and configure OAuth 2.0. Please enter your Client ID and Client Secret in the Platforms tab first.',
            'facebook' => 'To connect Facebook, you need to create a Facebook app and configure OAuth 2.0. Please enter your App ID and App Secret in the Platforms tab first.',
            'bluesky' => 'To connect Bluesky, you need to enter your handle and app password in the Platforms tab.'
        );

        $message = $messages[$platform] ?? "Please configure your {$platform_name} credentials in the Platforms tab first.";

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>' . esc_html($platform_name) . ' Setup Required:</strong> ' . esc_html($message) . '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=hashposter-settings&tab=platforms')) . '" class="button button-primary">Go to Platforms Settings</a></p>';
        echo '</div>';
    }

    public function add_linkedin_org_urn_page() {
        add_submenu_page(
            'hashposter-settings',
            'Get LinkedIn Organization URN',
            'LinkedIn URN Helper',
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

    // Merged from class-validation.php

    /**
     * Validate and sanitize API credentials
     */
    public static function validate_api_credentials($credentials, $platform) {
        if (!is_array($credentials)) {
            return array();
        }

        $sanitized = array();

        switch ($platform) {
            case 'x':
                $sanitized = self::validate_x_credentials($credentials);
                break;
            case 'facebook':
                $sanitized = self::validate_facebook_credentials($credentials);
                break;
            case 'linkedin':
                $sanitized = self::validate_linkedin_credentials($credentials);
                break;
            case 'bluesky':
                $sanitized = self::validate_bluesky_credentials($credentials);
                break;
            
            case 'bitly':
                $sanitized = self::validate_bitly_credentials($credentials);
                break;
            case 'wordpress':
                $sanitized = self::validate_wordpress_credentials($credentials);
                break;
        }

        return $sanitized;
    }

    /**
     * Validate X (Twitter) credentials
     */
    private static function validate_x_credentials($credentials) {
        return array(
            'active' => !empty($credentials['active']) ? 1 : 0,
            'client_id' => self::sanitize_alphanumeric($credentials['client_id'] ?? ''),
            'client_secret' => self::sanitize_alphanumeric($credentials['client_secret'] ?? ''),
        );
    }

    /**
     * Validate Facebook credentials
     */
    private static function validate_facebook_credentials($credentials) {
        return array(
            'active' => !empty($credentials['active']) ? 1 : 0,
            'access_token' => self::sanitize_alphanumeric($credentials['access_token'] ?? ''),
            'page_id' => self::sanitize_alphanumeric($credentials['page_id'] ?? ''),
        );
    }

    /**
     * Validate LinkedIn credentials
     */
    private static function validate_linkedin_credentials($credentials) {
        return array(
            'active' => !empty($credentials['active']) ? 1 : 0,
            'client_id' => self::sanitize_alphanumeric($credentials['client_id'] ?? ''),
            'client_secret' => self::sanitize_alphanumeric($credentials['client_secret'] ?? ''),
            'organization_urn' => self::sanitize_alphanumeric($credentials['organization_urn'] ?? ''),
            'access_token' => self::sanitize_alphanumeric($credentials['access_token'] ?? ''),
        );
    }

    /**
     * Validate Bluesky credentials
     */
    private static function validate_bluesky_credentials($credentials) {
        return array(
            'active' => !empty($credentials['active']) ? 1 : 0,
            'handle' => self::sanitize_bluesky_handle($credentials['handle'] ?? ''),
            'app_password' => self::sanitize_alphanumeric($credentials['app_password'] ?? ''),
        );
    }



    /**
     * Validate Bitly credentials
     */
    private static function validate_bitly_credentials($credentials) {
        return array(
            'active' => !empty($credentials['active']) ? 1 : 0,
            'api_key' => self::sanitize_alphanumeric($credentials['api_key'] ?? ''),
        );
    }

    /**
     * Validate WordPress credentials
     */
    private static function validate_wordpress_credentials($credentials) {
        return array(
            'active' => !empty($credentials['active']) ? 1 : 0,
            'username' => self::sanitize_handle($credentials['username'] ?? ''),
            'password' => self::sanitize_alphanumeric($credentials['password'] ?? ''),
            'site_url' => filter_var($credentials['site_url'] ?? '', FILTER_SANITIZE_URL),
        );
    }

    /**
     * Validate post content
     */
    public static function validate_post_content($content, $max_length = 500) {
        $content = wp_kses_post($content);
        if (strlen($content) > $max_length) {
            $content = substr($content, 0, $max_length);
        }
        return $content;
    }

    /**
     * Validate settings
     */
    public static function validate_settings($settings) {
        if (!is_array($settings)) {
            return array();
        }

        return array(
            'enabled' => !empty($settings['enabled']) ? 1 : 0,
            'logging' => !empty($settings['logging']) ? 1 : 0,
            'scheduling' => !empty($settings['scheduling']) ? 1 : 0,
            'delay_minutes' => self::validate_delay_minutes($settings['delay_minutes'] ?? 0),
            'facebook_app_id' => self::sanitize_alphanumeric($settings['facebook_app_id'] ?? ''),
            'twitter_handle' => self::sanitize_handle($settings['twitter_handle'] ?? ''),
        );
    }

    /**
     * Validate delay minutes
     */
    private static function validate_delay_minutes($minutes) {
        $minutes = intval($minutes);

        // Must be between 0 and 1440 (24 hours)
        if ($minutes < 0) {
            return 0;
        }

        if ($minutes > 1440) {
            return 1440;
        }

        return $minutes;
    }

    /**
     * Sanitize alphanumeric string
     */
    private static function sanitize_alphanumeric($string) {
        if (!is_string($string)) {
            return '';
        }

        if (function_exists('sanitize_text_field')) {
            $string = sanitize_text_field($string);
        }

        return trim($string);
    }

    /**
     * Sanitize handle (username)
     */
    private static function sanitize_handle($handle) {
        $handle = ltrim($handle, '@');
        return preg_replace('/[^a-zA-Z0-9_]/', '', $handle);
    }

    /**
     * Sanitize Bluesky handle allowing dots and hyphens
     */
    private static function sanitize_bluesky_handle($handle) {
        $handle = ltrim($handle, '@');

        if (function_exists('sanitize_text_field')) {
            $handle = sanitize_text_field($handle);
        }

        return preg_replace('/[^a-zA-Z0-9_.-]/', '', $handle);
    }

    /**
     * Apply post template from settings
     */
    public static function apply_post_template($post_id, $content = '', $platform = '') {
        $post_cards = get_option('hashposter_post_cards', array());
        $platform_key = $platform ? $platform . '_template' : '';
        $template = ($platform_key && isset($post_cards[$platform_key])) ? $post_cards[$platform_key] : ($post_cards['template'] ?? '{title} {short_url}');

        if (empty($template) || $template === '{title} {short_url}') {
            return $content; // Use default if no custom template
        }

        // Replace template placeholders
        $post = get_post($post_id);
        if (!$post) return $content;

        $replacements = array(
            '{title}' => get_the_title($post_id),
            '{url}' => get_permalink($post_id),
            '{short_url}' => get_permalink($post_id), // Always use full permalink for social media previews
            '{excerpt}' => get_the_excerpt($post_id),
            '{author}' => get_the_author_meta('display_name', $post->post_author),
            '{date}' => get_the_date('', $post_id),
            '{category}' => get_the_category_list(', ', '', $post_id),
            '{tags}' => get_the_tag_list('', ', ', '', $post_id),
            '{site_name}' => get_bloginfo('name'),
        );

        $processed_content = str_replace(array_keys($replacements), array_values($replacements), $template);

        return $processed_content;
    }

    /**
     * Test platform connection with provided credentials
     */
    public function ajax_test_connection() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'hashposter_admin')) {
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $platform = sanitize_text_field($_POST['platform'] ?? '');
    $allowed_platforms = array('x', 'facebook', 'linkedin', 'bluesky');

        if (!in_array($platform, $allowed_platforms)) {
            wp_send_json_error('Invalid platform');
        }

        // Get credentials (same as publishing code - no active check needed)
        $credentials = get_option('hashposter_api_credentials', array());
        $platform_creds = $credentials[$platform] ?? array();

        // Debug logging
        error_log('[HashPoster] ' . ucfirst($platform) . ' Test - Platform: ' . $platform);
        error_log('[HashPoster] ' . ucfirst($platform) . ' Test - Credentials exist: ' . (!empty($platform_creds) ? 'YES' : 'NO'));

        // Test connection based on platform
        $result = $this->test_platform_connection($platform, $platform_creds);

        if ($result['success']) {
            wp_send_json_success('Connection successful');
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /**
     * AJAX handler for OAuth initiation
     */
    public function ajax_oauth_initiate() {
        check_ajax_referer( 'hashposter_oauth', 'nonce' );

        $platform = sanitize_text_field( $_POST['platform'] ?? '' );
        if ( ! in_array( $platform, array( 'x', 'linkedin', 'bluesky', 'facebook' ) ) ) {
            wp_send_json_error( 'Invalid platform' );
        }

        // Check if credentials are configured
        $credentials = get_option( 'hashposter_api_credentials', array() );
        $platform_creds = $credentials[$platform] ?? array();

        $missing_creds = false;
        switch ( $platform ) {
            case 'linkedin':
                $missing_creds = empty( $platform_creds['client_id'] );
                break;
            case 'facebook':
                $missing_creds = empty( $platform_creds['app_id'] );
                break;
            case 'x':
                $missing_creds = empty( $platform_creds['client_id'] ) || empty( $platform_creds['client_secret'] );
                break;
            case 'bluesky':
                $missing_creds = empty( $platform_creds['handle'] ) || (empty( $platform_creds['app_password'] ) && empty( $platform_creds['password'] ));
                break;
        }

        if ( $missing_creds ) {
            $platform_names = array(
                'x' => 'X (Twitter)',
                'linkedin' => 'LinkedIn',
                'facebook' => 'Facebook',
                'bluesky' => 'Bluesky'
            );
            $platform_name = $platform_names[$platform] ?? ucfirst($platform);
            wp_send_json_error( $platform_name . ' credentials not configured. Please set up your API credentials first.' );
        }

        $auth_url = $this->oauth_handler->get_oauth_url( $platform );
        if ( $auth_url ) {
            wp_send_json_success( array( 'auth_url' => $auth_url ) );
        } elseif ( in_array( $platform, array( 'bluesky', 'x' ) ) ) {
            // These platforms don't use traditional OAuth, try direct connection
            $result = $this->test_platform_connection( $platform, $platform_creds );
            if ( $result['success'] ) {
                // Store the credentials as "connected" for these platforms
                $oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
                if ( $platform === 'bluesky' ) {
                    $oauth_tokens['bluesky'] = array(
                        'connected' => true,
                        'handle' => $platform_creds['handle'] ?? '',
                        'app_password' => $platform_creds['app_password'] ?? $platform_creds['password'] ?? '',
                        'timestamp' => time()
                    );
                } elseif ( $platform === 'x' ) {
                    $oauth_tokens['x'] = array(
                        'connected' => true,
                        'timestamp' => time()
                    );
                }
                update_option( 'hashposter_oauth_tokens', $oauth_tokens );
                wp_send_json_success( ucfirst( $platform ) . ' connected successfully!' );
            } else {
                wp_send_json_error( ucfirst( $platform ) . ' connection failed: ' . $result['error'] );
            }
        } else {
            wp_send_json_error( 'Failed to generate OAuth URL' );
        }
    }

    /**
     * AJAX handler for OAuth disconnect
     */
    public function ajax_oauth_disconnect() {
        check_ajax_referer( 'hashposter_oauth', 'nonce' );

        $platform = sanitize_text_field( $_POST['platform'] ?? '' );
        if ( ! in_array( $platform, array( 'x', 'linkedin', 'bluesky', 'facebook' ) ) ) {
            wp_send_json_error( 'Invalid platform' );
        }

        $this->oauth_handler->disconnect( $platform );
        wp_send_json_success( array( 'message' => ucfirst( $platform ) . ' disconnected successfully' ) );
    }

    /**
     * Test connection to a specific platform
     */
    private function test_platform_connection($platform, $credentials) {
        switch ($platform) {
            case 'x':
                return $this->test_x_connection($credentials);
            case 'facebook':
                return $this->test_facebook_connection($credentials);
            case 'linkedin':
                return $this->test_linkedin_connection($credentials);
            case 'bluesky':
                return $this->test_bluesky_connection($credentials);
            // Pinterest support removed - no test available
            default:
                return array('success' => false, 'error' => 'Unsupported platform');
        }
    }

    /**
     * Test X (Twitter) connection
     */
    private function test_x_connection($credentials) {
        // Check if OAuth 1.0a tokens exist
        $oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
        if ( empty( $oauth_tokens['x'] ) || empty( $oauth_tokens['x']['access_token'] ) || empty( $oauth_tokens['x']['access_token_secret'] ) ) {
            return array('success' => false, 'error' => 'X account not connected. Please click "Connect X Account" to authorize HashPoster.');
        }

        // Test OAuth 1.0a connection
        if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            return array('success' => false, 'error' => 'Missing X API credentials. Please configure Client ID and Client Secret.');
        }

        // Use TwitterOAuth library for OAuth 1.0a authentication
        $autoload_path = dirname(__FILE__) . '/twitteroauth/autoload.php';
        if (!file_exists($autoload_path)) {
            return array('success' => false, 'error' => 'TwitterOAuth library not found. Please ensure the twitteroauth folder is in the includes directory.');
        }

        if (!class_exists('Abraham\TwitterOAuth\TwitterOAuth')) {
            require_once $autoload_path;
        }

        try {
            // Initialize TwitterOAuth with OAuth 1.0a
            $connection = new \Abraham\TwitterOAuth\TwitterOAuth(
                $credentials['client_id'], 
                $credentials['client_secret'],
                $oauth_tokens['x']['access_token'],
                $oauth_tokens['x']['access_token_secret']
            );

            // Test with a simple API call (v1.1 API)
            $verify = $connection->get('account/verify_credentials');
            $verify_http_code = $connection->getLastHttpCode();

            // Debug logging
            error_log('[HashPoster] X Test Connection - HTTP Code: ' . $verify_http_code);
            error_log('[HashPoster] X Test Connection - Raw Response: ' . print_r($verify, true));

            if ($verify_http_code === 200 && isset($verify->id)) {
                return array('success' => true, 'message' => 'X connection successful! Connected as @' . ($verify->screen_name ?? 'unknown'));
            } else {
                $error_msg = 'X API test failed';
                if (isset($verify->errors) && is_array($verify->errors)) {
                    $error_msg .= ': ' . $verify->errors[0]->message;
                } elseif ($verify_http_code !== 200) {
                    $error_msg .= ' (HTTP ' . $verify_http_code . ')';
                }
                return array('success' => false, 'error' => $error_msg);
            }
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'X connection test failed: ' . $e->getMessage());
        }
    }
    /**
     * Test Facebook connection
     */
    private function test_facebook_connection($credentials) {
        if (empty($credentials['access_token'])) {
            return array('success' => false, 'error' => 'Missing access token');
        }

        if (empty($credentials['page_id'])) {
            return array('success' => false, 'error' => 'Missing Page ID. Facebook requires a Page ID to post to pages. Get your Page ID from Facebook Page settings or use a tool like findmyfbid.com');
        }

        // Test token by getting user info with proper API version
        $response = wp_remote_get('https://graph.facebook.com/v18.0/me?fields=id,name&access_token=' . $credentials['access_token'], array(
            'timeout' => 10
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => 'Connection failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            $error = $data['error']['message'] ?? 'Unknown API error';
            return array('success' => false, 'error' => 'API error: ' . $error);
        }

        return array('success' => true);
    }

    /**
     * Test LinkedIn connection
     */
    private function test_linkedin_connection($credentials) {
        if (empty($credentials['access_token'])) {
            return array('success' => false, 'error' => 'Missing access token');
        }

        // Try multiple LinkedIn endpoints to diagnose the issue
        $endpoints = array(
            'https://api.linkedin.com/v2/userinfo',  // OAuth 2.0 OpenID Connect
            'https://api.linkedin.com/v2/me',        // Legacy v2 API
            'https://api.linkedin.com/v2/people/~'   // Alternative people endpoint
        );

        foreach ($endpoints as $endpoint) {
            error_log('[HashPoster] LinkedIn Test - Trying endpoint: ' . $endpoint);

            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $credentials['access_token'],
                    'Connection'    => 'Keep-Alive',
                    'Accept'        => 'application/json',
                ),
                'timeout' => 15,
            );

            $response = wp_remote_get($endpoint, $args);

                if (!is_wp_error($response)) {
                    $code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);

                    error_log('[HashPoster] LinkedIn Test - Endpoint: ' . $endpoint . ' - HTTP Code: ' . $code);
                    error_log('[HashPoster] LinkedIn Test - Body: ' . $body);

                    if ($code === 200) {
                        error_log('[HashPoster] LinkedIn Test - SUCCESS with endpoint: ' . $endpoint);
                        return array('success' => true, 'message' => 'Connected successfully using ' . $endpoint);
                    } else {
                        // Continue to next endpoint but preserve details in logs
                        error_log('[HashPoster] LinkedIn Test - FAILED (will try next endpoint)');
                    }
                } else {
                    error_log('[HashPoster] LinkedIn Test - WP Error for ' . $endpoint . ': ' . $response->get_error_message());
                }
        }

        // If all endpoints failed, provide detailed error
        $error_msg = 'All LinkedIn API endpoints failed. ';
        $error_msg .= 'Your token is valid according to LinkedIn\'s introspection but fails API calls. ';
        $error_msg .= 'This suggests an app configuration issue: ';
        $error_msg .= '1) Your LinkedIn app may not be properly configured for OAuth 2.0. ';
        $error_msg .= '2) The token may be from a different OAuth flow than expected. ';
        $error_msg .= '3) Check your app\'s "Authorized redirect URLs" in LinkedIn Developer Portal. ';
        $error_msg .= '4) Ensure your app has the required products enabled (Sign In with LinkedIn). ';
        $error_msg .= '5) Try regenerating the token through the proper OAuth flow.';
        return array('success' => false, 'error' => $error_msg);
    }

    /**
     * Test Bluesky connection
     */
    private function test_bluesky_connection($credentials) {
        if (empty($credentials['handle']) || empty($credentials['app_password'])) {
            return array('success' => false, 'error' => 'Missing handle or app password');
        }

        // Bluesky uses session-based auth, so we'll do a basic connectivity test
        $response = wp_remote_post('https://bsky.social/xrpc/com.atproto.server.createSession', array(
            'body' => json_encode(array(
                'identifier' => $credentials['handle'],
                'password' => $credentials['app_password']
            )),
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'timeout' => 10
        ));

        if (is_wp_error($response)) {
            error_log('[HashPoster] Bluesky Test - WP Error: ' . $response->get_error_message());
            return array('success' => false, 'error' => 'Connection failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log('[HashPoster] Bluesky Test - HTTP Code: ' . $code);
        error_log('[HashPoster] Bluesky Test - Body: ' . $body);

        if ($code !== 200) {
            $data = json_decode($body, true);
            $error = $data['error'] ?? ($data['message'] ?? 'Authentication failed');
            return array('success' => false, 'error' => 'API error: ' . $error);
        }

        return array('success' => true);
    }

    // Pinterest connection test removed.

    /**
     * Validation callbacks for settings
     */
    public function validate_settings_callback($input) {
        $sanitized = array();
        $sanitized['enabled'] = isset($input['enabled']) ? '1' : '0';
        $sanitized['logging'] = isset($input['logging']) ? '1' : '0';
        $sanitized['scheduling'] = isset($input['scheduling']) ? '1' : '0';
        $sanitized['enable_url_cards'] = isset($input['enable_url_cards']) ? '1' : '0';
        $sanitized['delay_minutes'] = isset($input['delay_minutes']) ? absint($input['delay_minutes']) : 0;
        return $sanitized;
    }

    public function validate_api_credentials_callback($input) {
        $sanitized = array();
    $platforms = array('x', 'linkedin', 'bluesky', 'facebook');

        foreach ($platforms as $platform) {
            if (isset($input[$platform])) {
                $sanitized[$platform] = array_map('sanitize_text_field', $input[$platform]);
                $sanitized[$platform]['active'] = isset($input[$platform]['active']) ? '1' : '0';
            }
        }
        return $sanitized;
    }

    public function validate_post_cards_callback($input) {
        $sanitized = array();
    $platforms = array('x', 'linkedin', 'bluesky', 'facebook');

        // Validate per-platform templates
        foreach ($platforms as $platform) {
            $template_key = $platform . '_template';
            if (isset($input[$template_key])) {
                $sanitized[$template_key] = sanitize_textarea_field($input[$template_key]);
            }
        }

        // Keep legacy template for backward compatibility
        if (isset($input['template'])) {
            $sanitized['template'] = sanitize_textarea_field($input['template']);
        }

        return $sanitized;
    }

    public function validate_shortlinks_callback($input) {
        $sanitized = array();

        if (isset($input['wordpress'])) {
            $sanitized['wordpress'] = array(
                'active' => isset($input['wordpress']['active']) ? '1' : '0'
            );
        }

        if (isset($input['bitly'])) {
            $sanitized['bitly'] = array(
                'active' => isset($input['bitly']['active']) ? '1' : '0',
                'token' => isset($input['bitly']['token']) ? sanitize_text_field($input['bitly']['token']) : ''
            );
        }

        return $sanitized;
    }
}
?>