<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class HashPoster_Settings {
    private $oauth_handler;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'wp_ajax_hashposter_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_hashposter_oauth_initiate', array( $this, 'ajax_oauth_initiate' ) );

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
        register_setting( 'hashposter_settings_group', 'hashposter_default_platforms', array($this, 'validate_default_platforms_callback') );
        register_setting( 'hashposter_settings_group', 'hashposter_api_credentials', array($this, 'validate_api_credentials_callback') );
        register_setting( 'hashposter_settings_group', 'hashposter_post_cards', array($this, 'validate_post_cards_callback') );
        register_setting( 'hashposter_settings_group', 'hashposter_shortlinks', array($this, 'validate_shortlinks_callback') );
        // ...other settings...
    }

    public function render_settings_page() {
        $settings          = get_option( 'hashposter_settings', array() );
        $api_credentials   = get_option( 'hashposter_api_credentials', array() );
        $post_cards        = get_option( 'hashposter_post_cards', array() );
        $shortlinks        = get_option( 'hashposter_shortlinks', array() );
        $default_platforms = get_option( 'hashposter_default_platforms', array( 'facebook', 'linkedin', 'bluesky' ) );

        $active_tab = 'tab-general';
        if ( isset( $_GET['hp_active_tab'] ) ) {
            $allowed_tabs = array( 'tab-general', 'tab-platforms', 'tab-postcards', 'tab-shortlinks', 'tab-scheduling' );
            $req_tab = sanitize_key( $_GET['hp_active_tab'] );
            if ( in_array( $req_tab, $allowed_tabs, true ) ) {
                $active_tab = $req_tab;
            }
        }

        $platforms_meta = array(
            'twitter'  => array( 'name' => 'X (Twitter)', 'dashicon' => 'dashicons-twitter', 'class' => 'hp-logo-tw', 'desc' => 'Post to X using the v2 Free Tier (OAuth 1.0a User Context).' ),
            'facebook' => array( 'name' => 'Facebook', 'dashicon' => 'dashicons-facebook', 'class' => 'hp-logo-fb', 'desc' => 'Publish automatically to your Facebook Page.' ),
            'linkedin' => array( 'name' => 'LinkedIn', 'dashicon' => 'dashicons-linkedin', 'class' => 'hp-logo-li', 'desc' => 'Share professional updates to LinkedIn.' ),
            'bluesky'  => array( 'name' => 'Bluesky',  'dashicon' => 'dashicons-twitter',  'class' => 'hp-logo-bs', 'desc' => 'Post to the open, decentralised network.' ),
            'telegram' => array( 'name' => 'Telegram', 'dashicon' => 'dashicons-megaphone','class' => 'hp-logo-tg', 'desc' => 'Broadcast posts to your Telegram channel.' ),
        );

        $tabs = array(
            'tab-general'    => array('icon' => 'dashicons-admin-settings', 'title' => 'General Settings', 'desc' => 'Control whether the plugin is active and how it logs activity.'),
            'tab-platforms'  => array('icon' => 'dashicons-share-alt', 'title' => 'Social Platforms', 'desc' => 'Connect your accounts using OAuth or API keys.'),
            'tab-postcards'  => array('icon' => 'dashicons-format-aside', 'title' => 'Post template', 'desc' => 'Define a single template used to compose the message.'),
            'tab-shortlinks' => array('icon' => 'dashicons-admin-links', 'title' => 'URL Shortening', 'desc' => 'Use {short_url} to insert a shortened link.'),
            'tab-scheduling' => array('icon' => 'dashicons-calendar-alt', 'title' => 'Posting Delay', 'desc' => 'Delay social posting after a WP post goes live.')
        );

        $active_title = $tabs[$active_tab]['title'];
        $active_desc  = $tabs[$active_tab]['desc'];
        ?>

        <div class="wrap hashposter-settings">
            
            <?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) : ?>
                <div class="hp-notice"><span class="dashicons dashicons-yes-alt"></span> Settings saved beautifully!</div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" id="hashposter-settings-form">
                <?php settings_fields( 'hashposter_settings_group' ); ?>
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( admin_url( 'admin.php?page=hashposter-settings&hp_active_tab=' . $active_tab ) ); ?>" />
                <input type="hidden" name="hp_active_tab" id="hp_active_tab_input" value="<?php echo esc_attr($active_tab); ?>" />

                <div class="hp-dashboard">
                    <!-- Sidebar -->
                    <aside class="hp-sidebar">
                        <div class="hp-brand-header">
                            <div class="hp-brand-icon"><span class="dashicons dashicons-share"></span></div>
                            <div class="hp-brand-text">
                                <h2>HashPoster</h2>
                                <span>PRO</span>
                            </div>
                        </div>

                        <nav class="hp-nav">
                            <?php foreach ($tabs as $tid => $tinfo): ?>
                            <a href="#" class="hp-nav-item <?php echo $tid === $active_tab ? 'active' : ''; ?>" 
                               data-tab="<?php echo esc_attr($tid); ?>"
                               data-title="<?php echo esc_attr($tinfo['title']); ?>"
                               data-desc="<?php echo esc_attr($tinfo['desc']); ?>">
                                <span class="dashicons <?php echo esc_attr($tinfo['icon']); ?>"></span>
                                <?php echo esc_html($tinfo['title']); ?>
                            </a>
                            <?php endforeach; ?>
                        </nav>
                    </aside>

                    <!-- Main Content -->
                    <main class="hp-main">
                        <div class="hp-topbar">
                            <div class="hp-topbar-title">
                                <h1 id="hp_active_tab_title"><?php echo esc_html($active_title); ?></h1>
                                <p id="hp_active_tab_desc"><?php echo esc_html($active_desc); ?></p>
                            </div>
                        </div>

                        <!-- General Tab -->
                        <div id="tab-general" class="hp-tab-panel <?php echo $active_tab === 'tab-general' ? 'active' : ''; ?>">
                            <div class="hp-card">
                                <h4 class="hp-section-title">Core Engine</h4>
                                <div class="hp-toggle-row">
                                    <div class="hp-toggle-info">
                                        <h4>Enable HashPoster</h4>
                                        <p>Automatically share new posts to your connected platforms.</p>
                                    </div>
                                    <label class="hp-switch">
                                        <input type="checkbox" name="hashposter_settings[enabled]" value="1" <?php checked( ! empty($settings['enabled']) ); ?>>
                                        <span class="hp-slider"></span>
                                    </label>
                                </div>
                                <div class="hp-toggle-row">
                                    <div class="hp-toggle-info">
                                        <h4>Debug Logging</h4>
                                        <p>Write activity to debug.log when WP_DEBUG_LOG is on.</p>
                                    </div>
                                    <label class="hp-switch">
                                        <input type="checkbox" name="hashposter_settings[logging]" value="1" <?php checked( ! empty($settings['logging']) ); ?>>
                                        <span class="hp-slider"></span>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="hp-card">
                                <h4 class="hp-section-title">Default Platforms</h4>
                                <p style="color:var(--hp-text-muted);font-size:14px;margin-bottom:20px;">Which platforms are pre-selected for new posts? Authors can override this per-post.</p>
                                
                                <div class="hp-platform-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
                                    <?php foreach ( $platforms_meta as $pk => $pm ) : ?>
                                    <div class="hp-toggle-row" style="padding:10px 0; border:none;">
                                        <div class="hp-toggle-info"><h4 style="font-size:15px;"><?php echo esc_html($pm['name']); ?></h4></div>
                                        <label class="hp-switch">
                                            <input type="checkbox" name="hashposter_default_platforms[]" value="<?php echo esc_attr($pk); ?>" <?php checked( in_array( $pk, $default_platforms, true ) ); ?>>
                                            <span class="hp-slider"></span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Platforms Tab -->
                        <div id="tab-platforms" class="hp-tab-panel <?php echo $active_tab === 'tab-platforms' ? 'active' : ''; ?>">
                            <div class="hp-platform-grid">
                                <?php foreach ( $platforms_meta as $pk => $pm ) :
                                    $is_connected = $this->oauth_handler->is_connected( $pk );
                                    $creds = $api_credentials[ $pk ] ?? array();
                                ?>
                                <div class="hp-platform-card">
                                    <div class="hp-platform-header">
                                        <div class="hp-platform-logo <?php echo esc_attr($pm['class']); ?>">
                                            <span class="dashicons <?php echo esc_attr($pm['dashicon']); ?>" style="font-size:24px;width:24px;height:24px;"></span>
                                        </div>
                                        <div>
                                            <h3><?php echo esc_html($pm['name']); ?></h3>
                                        </div>
                                        <span class="hp-status <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
                                            <?php echo $is_connected ? 'Connected' : 'Disconnected'; ?>
                                        </span>
                                    </div>
                                    <div class="hp-platform-body">
                                        <p style="color:var(--hp-text-muted);font-size:13px;margin:0 0 20px 0;"><?php echo esc_html($pm['desc']); ?></p>
                                        
                                        <?php if ($pk === 'facebook' && !$is_connected): ?>
                                            <div class="hp-input-group">
                                                <label>App ID</label>
                                                <input type="text" class="hp-input" name="hashposter_api_credentials[facebook][app_id]" value="<?php echo esc_attr($creds['app_id']??''); ?>" placeholder="Enter Meta App ID">
                                            </div>
                                            <div class="hp-input-group">
                                                <label>App Secret</label>
                                                <input type="password" class="hp-input" name="hashposter_api_credentials[facebook][app_secret]" value="<?php echo esc_attr($creds['app_secret']??''); ?>" placeholder="Enter App Secret">
                                            </div>
                                        <?php elseif ($pk === 'linkedin' && !$is_connected): ?>
                                            <div class="hp-input-group">
                                                <label>Client ID</label>
                                                <input type="text" class="hp-input" name="hashposter_api_credentials[linkedin][client_id]" value="<?php echo esc_attr($creds['client_id']??''); ?>" placeholder="Enter LinkedIn Client ID">
                                            </div>
                                            <div class="hp-input-group">
                                                <label>Client Secret</label>
                                                <input type="password" class="hp-input" name="hashposter_api_credentials[linkedin][client_secret]" value="<?php echo esc_attr($creds['client_secret']??''); ?>" placeholder="Enter LinkedIn Client Secret">
                                            </div>
                                        <?php elseif ($pk === 'bluesky' && !$is_connected): ?>
                                            <div class="hp-input-group">
                                                <label>Handle</label>
                                                <input type="text" class="hp-input" name="hashposter_api_credentials[bluesky][handle]" value="<?php echo esc_attr($creds['handle']??''); ?>" placeholder="yourname.bsky.social">
                                            </div>
                                            <div class="hp-input-group">
                                                <label>App Password</label>
                                                <input type="password" class="hp-input" name="hashposter_api_credentials[bluesky][app_password]" value="<?php echo esc_attr($creds['app_password']??''); ?>" placeholder="xxxx-xxxx-xxxx-xxxx">
                                            </div>
                                        <?php elseif ($pk === 'telegram' && !$is_connected): ?>
                                            <div class="hp-input-group">
                                                <label>Bot Token</label>
                                                <input type="password" class="hp-input" name="hashposter_api_credentials[telegram][bot_token]" value="<?php echo esc_attr($creds['bot_token']??''); ?>" placeholder="123456:ABC-DEF1234ghIkl-zyx5c">
                                            </div>
                                            <div class="hp-input-group">
                                                <label>Channel ID</label>
                                                <input type="text" class="hp-input" name="hashposter_api_credentials[telegram][channel_id]" value="<?php echo esc_attr($creds['channel_id']??''); ?>" placeholder="@yourchannel">
                                            </div>
                                        <?php elseif ($pk === 'twitter' && !$is_connected): ?>
                                            <div class="hp-input-group">
                                                <label>API Key (Consumer Key)</label>
                                                <input type="text" class="hp-input" name="hashposter_api_credentials[twitter][api_key]" value="<?php echo esc_attr($creds['api_key']??''); ?>" placeholder="API Key">
                                            </div>
                                            <div class="hp-input-group">
                                                <label>API Key Secret</label>
                                                <input type="password" class="hp-input" name="hashposter_api_credentials[twitter][api_secret]" value="<?php echo esc_attr($creds['api_secret']??''); ?>" placeholder="API Key Secret">
                                            </div>
                                            <div class="hp-input-group">
                                                <label>Access Token</label>
                                                <input type="text" class="hp-input" name="hashposter_api_credentials[twitter][access_token]" value="<?php echo esc_attr($creds['access_token']??''); ?>" placeholder="Access Token">
                                            </div>
                                            <div class="hp-input-group">
                                                <label>Access Token Secret</label>
                                                <input type="password" class="hp-input" name="hashposter_api_credentials[twitter][access_token_secret]" value="<?php echo esc_attr($creds['access_token_secret']??''); ?>" placeholder="Access Token Secret">
                                            </div>
                                        <?php endif; ?>

                                        <div style="margin-top:auto;padding-top:16px;">
                                            <?php if ($is_connected): ?>
                                                <button type="button" class="hp-btn hp-btn-danger hp-oauth-btn" style="width:100%;justify-content:center;" data-platform="<?php echo esc_attr($pk); ?>">
                                                    <span class="dashicons dashicons-no-alt"></span> Disconnect Account
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="hp-btn hp-btn-primary hp-oauth-btn" style="width:100%;justify-content:center;" data-platform="<?php echo esc_attr($pk); ?>">
                                                    <span class="dashicons dashicons-admin-links"></span> Connect Account
                                                </button>
                                                <div style="text-align:center; margin-top:12px; font-size:13px;">
                                                <?php
                                                    if ($pk === 'facebook') echo '<a href="https://developers.facebook.com/apps" target="_blank" style="color:var(--hp-brand);text-decoration:none;">Get App Keys &rarr;</a>';
                                                    elseif ($pk === 'linkedin') echo '<a href="https://www.linkedin.com/developers/apps" target="_blank" style="color:var(--hp-brand);text-decoration:none;">Get Client Keys &rarr;</a>';
                                                    elseif ($pk === 'twitter') echo '<a href="https://developer.twitter.com/en/portal/dashboard" target="_blank" style="color:var(--hp-brand);text-decoration:none;">Get X API Keys &rarr;</a>';
                                                    elseif ($pk === 'telegram') echo '<a href="https://t.me/BotFather" target="_blank" style="color:var(--hp-brand);text-decoration:none;">Open @BotFather &rarr;</a>';
                                                ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Post Cards Tab -->
                        <div id="tab-postcards" class="hp-tab-panel <?php echo $active_tab === 'tab-postcards' ? 'active' : ''; ?>">
                            <div class="hp-card" style="padding:0; border:none; background:transparent; box-shadow:none;">
                                <div class="hp-template-editor">
                                    <div class="hp-template-toolbar">
                                        <?php
                                        $tokens = array( '{title}', '{url}', '{short_url}', '{excerpt}', '{author}', '{date}', '{tags}', '{site_name}' );
                                        foreach ( $tokens as $token ) :
                                        ?>
                                        <button type="button" class="hp-token-btn" data-token="<?php echo esc_attr($token); ?>"><?php echo esc_html($token); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                    <textarea id="hp_template_input" name="hashposter_post_cards[template]" class="hp-template-textarea" placeholder="Craft your post template..."><?php echo esc_textarea( $post_cards['template'] ?? '{title} {excerpt} {url}' ); ?></textarea>
                                </div>

                                <div class="hp-preview-box" id="hp_template_preview">
                                    <!-- Populated by JS -->
                                </div>
                            </div>
                        </div>

                        <!-- Short Links Tab -->
                        <div id="tab-shortlinks" class="hp-tab-panel <?php echo $active_tab === 'tab-shortlinks' ? 'active' : ''; ?>">
                            <div class="hp-card">
                                <h4 class="hp-section-title">WordPress Native</h4>
                                <div class="hp-toggle-row">
                                    <div class="hp-toggle-info">
                                        <h4>Built-in Shortlinks</h4>
                                        <p>Use ?p=123 format automatically.</p>
                                    </div>
                                    <label class="hp-switch">
                                        <input type="checkbox" name="hashposter_shortlinks[wordpress][active]" value="1" <?php checked( ! empty( $shortlinks['wordpress']['active'] ) ); ?>>
                                        <span class="hp-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="hp-card">
                                <h4 class="hp-section-title">Bitly Integration</h4>
                                <div class="hp-toggle-row" style="border-bottom:none; padding-bottom:16px;">
                                    <div class="hp-toggle-info">
                                        <h4>Enable Bitly</h4>
                                        <p>Use professional shortened bit.ly links.</p>
                                    </div>
                                    <label class="hp-switch">
                                        <input type="checkbox" name="hashposter_shortlinks[bitly][active]" value="1" <?php checked( ! empty( $shortlinks['bitly']['active'] ) ); ?>>
                                        <span class="hp-slider"></span>
                                    </label>
                                </div>
                                <div class="hp-input-group" style="padding-top:16px; border-top:1px solid var(--hp-border);">
                                    <label>Generic Access Token</label>
                                    <input type="text" class="hp-input" name="hashposter_shortlinks[bitly][token]" value="<?php echo esc_attr( $shortlinks['bitly']['token'] ?? '' ); ?>" placeholder="Enter Bitly Token">
                                </div>
                            </div>
                        </div>

                        <!-- Scheduling Tab -->
                        <div id="tab-scheduling" class="hp-tab-panel <?php echo $active_tab === 'tab-scheduling' ? 'active' : ''; ?>">
                            <div class="hp-card">
                                <h4 class="hp-section-title">Publish Delay</h4>
                                <div class="hp-toggle-row" style="border-bottom:none; padding-bottom:16px;">
                                    <div class="hp-toggle-info">
                                        <h4>Enable Posting Delay</h4>
                                        <p>Delay sharing to social media after WP publish.</p>
                                    </div>
                                    <label class="hp-switch">
                                        <input type="checkbox" name="hashposter_settings[scheduling]" value="1" <?php checked( ! empty( $settings['scheduling'] ) ); ?>>
                                        <span class="hp-slider"></span>
                                    </label>
                                </div>
                                <div class="hp-input-group" style="padding-top:16px; border-top:1px solid var(--hp-border);">
                                    <label>Minutes Delay</label>
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <input type="number" class="hp-input" style="width:100px;" name="hashposter_settings[delay_minutes]" min="0" max="1440" value="<?php echo esc_attr( $settings['delay_minutes'] ?? '0' ); ?>">
                                        <span style="color:var(--hp-text-muted); font-size:14px;">minutes after publish</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Global Save Bar -->
                        <div class="hp-actions-bar">
                            <button type="submit" name="submit" id="submit" class="hp-btn hp-btn-primary">
                                <span class="dashicons dashicons-saved"></span> Save Configuration
                            </button>
                        </div>

                    </main>
                </div>
            </form>
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
            case 'twitter':
                $sanitized = self::validate_twitter_credentials($credentials);
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
            case 'telegram':
                $sanitized = self::validate_telegram_credentials($credentials);
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
     * Validate Twitter credentials
     */
    private static function validate_twitter_credentials($credentials) {
        return array(
            'api_key' => self::sanitize_alphanumeric($credentials['api_key'] ?? ''),
            'api_secret' => self::sanitize_alphanumeric($credentials['api_secret'] ?? ''),
            'access_token' => self::sanitize_alphanumeric($credentials['access_token'] ?? ''),
            'access_token_secret' => self::sanitize_alphanumeric($credentials['access_token_secret'] ?? ''),
        );
    }

    /**
     * Validate Facebook credentials
     */
    private static function validate_facebook_credentials($credentials) {
        return array(
            'app_id' => self::sanitize_alphanumeric($credentials['app_id'] ?? ''),
            'app_secret' => self::sanitize_alphanumeric($credentials['app_secret'] ?? ''),
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
     * Validate Telegram credentials
     */
    private static function validate_telegram_credentials($credentials) {
        return array(
            'active' => !empty($credentials['active']) ? 1 : 0,
            'bot_token' => self::sanitize_alphanumeric($credentials['bot_token'] ?? ''),
            'channel_id' => sanitize_text_field($credentials['channel_id'] ?? ''),
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
    $allowed_platforms = array('twitter', 'facebook', 'linkedin', 'bluesky', 'telegram');

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
        if ( ! in_array( $platform, array( 'linkedin', 'bluesky', 'facebook', 'twitter' ) ) ) {
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
                // Facebook requires app_id and app_secret for OAuth flow
                $missing_creds = empty( $platform_creds['app_id'] ) || empty( $platform_creds['app_secret'] );
                break;
            case 'twitter':
                $missing_creds = empty( $platform_creds['api_key'] ) || empty( $platform_creds['api_secret'] ) || empty( $platform_creds['access_token'] ) || empty( $platform_creds['access_token_secret'] );
                break;
            case 'bluesky':
                $missing_creds = empty( $platform_creds['handle'] ) || (empty( $platform_creds['app_password'] ) && empty( $platform_creds['password'] ));
                break;
            case 'telegram':
                // Telegram doesn't use OAuth, shouldn't reach here
                wp_send_json_error( 'Telegram does not use OAuth. Please test connection directly.' );
                return;
        }

        if ( $missing_creds ) {
            $platform_names = array(
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
        } elseif ( $platform === 'bluesky' || $platform === 'twitter' ) {
            // Bluesky/Twitter don't use traditional OAuth here, try direct connection
            $result = $this->test_platform_connection( $platform, $platform_creds );
            if ( $result['success'] ) {
                // Store the credentials as "connected" for these platforms
                $oauth_tokens = get_option( 'hashposter_oauth_tokens', array() );
                if ($platform === 'bluesky') {
                    $oauth_tokens['bluesky'] = array(
                        'connected' => true,
                        'handle' => $platform_creds['handle'] ?? '',
                        'app_password' => $platform_creds['app_password'] ?? $platform_creds['password'] ?? '',
                        'timestamp' => time()
                    );
                } elseif ($platform === 'twitter') {
                    $oauth_tokens['twitter'] = array(
                        'connected' => true,
                        'api_key' => $platform_creds['api_key'] ?? '',
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
     * Test connection to a specific platform
     */
    private function test_platform_connection($platform, $credentials) {
        switch ($platform) {
            case 'twitter':
                return $this->test_twitter_connection($credentials);
            case 'facebook':
                return $this->test_facebook_connection($credentials);
            case 'linkedin':
                return $this->test_linkedin_connection($credentials);
            case 'bluesky':
                return $this->test_bluesky_connection($credentials);
            case 'telegram':
                return $this->test_telegram_connection($credentials);
            default:
                return array('success' => false, 'error' => 'Unsupported platform');
        }
    }

    /**
     * Test Twitter (X) connection using OAuth 1.0a
     */
    private function test_twitter_connection($credentials) {
        $api_handler = new HashPoster_API_Handler();
        $result = $api_handler->verify_twitter_credentials($credentials);
        
        if ( is_wp_error( $result ) ) {
            return array('success' => false, 'error' => $result->get_error_message());
        }
        
        return array('success' => true);
    }

    /**
     * Test Facebook connection
     */
    private function test_facebook_connection($credentials) {
        // Check if user has set up app credentials (ready for OAuth)
        if (!empty($credentials['app_id']) && !empty($credentials['app_secret']) && empty($credentials['access_token'])) {
            return array('success' => true, 'message' => 'App credentials configured. Click "Connect Account" to authenticate.');
        }

        // If access token exists, verify it
        if (empty($credentials['access_token'])) {
            return array('success' => false, 'error' => 'Missing access token. Please click "Connect Account" to authenticate with Facebook.');
        }

        if (empty($credentials['page_id'])) {
            return array('success' => false, 'error' => 'Missing Page ID. Facebook requires a Page ID to post to pages. Get your Page ID from Facebook Page settings or use a tool like findmyfbid.com');
        }

        // Test token by getting user info with proper API version
        $response = wp_remote_get('https://graph.facebook.com/v24.0/me?fields=id,name&access_token=' . $credentials['access_token'], array(
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

    /**
     * Test Telegram connection
     */
    private function test_telegram_connection($credentials) {
        if (empty($credentials['bot_token'])) {
            return array('success' => false, 'error' => 'Missing bot token');
        }

        if (empty($credentials['channel_id'])) {
            return array('success' => false, 'error' => 'Missing channel ID');
        }

        // Test bot token by getting bot info
        $endpoint = 'https://api.telegram.org/bot' . $credentials['bot_token'] . '/getMe';

        $response = wp_remote_get($endpoint, array(
            'timeout' => 10,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            error_log('[HashPoster] Telegram Test - WP Error: ' . $response->get_error_message());
            return array('success' => false, 'error' => 'Bot token validation failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log('[HashPoster] Telegram Test - HTTP Code: ' . $code);
        error_log('[HashPoster] Telegram Test - Body: ' . $body);

        if ($code !== 200) {
            $data = json_decode($body, true);
            $error = $data['description'] ?? 'Unknown error';
            return array('success' => false, 'error' => 'Bot token error: ' . $error);
        }

        $data = json_decode($body, true);
        if (!isset($data['ok']) || !$data['ok']) {
            $error = $data['description'] ?? 'Bot token validation failed';
            return array('success' => false, 'error' => 'Bot error: ' . $error);
        }

        // Bot token is valid - now test channel access
        // Note: Full channel access test requires actually sending a message or using getChat
        $bot_info = $data['result'] ?? array();
        error_log('[HashPoster] Telegram Bot verified: ' . ($bot_info['username'] ?? 'unknown'));

        // Normalize channel ID for display
        $channel_id = $credentials['channel_id'];
        if (!preg_match('/^-\d+$/', $channel_id) && !preg_match('/^@/', $channel_id)) {
            $channel_id = '@' . ltrim($channel_id, '@');
        }

        // Try to get channel info to verify bot has access
        $get_chat_endpoint = 'https://api.telegram.org/bot' . $credentials['bot_token'] . '/getChat';
        $chat_response = wp_remote_post($get_chat_endpoint, array(
            'body' => json_encode(array('chat_id' => $channel_id)),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 10,
            'sslverify' => true
        ));

        if (!is_wp_error($chat_response)) {
            $chat_code = wp_remote_retrieve_response_code($chat_response);
            $chat_body = wp_remote_retrieve_body($chat_response);
            $chat_data = json_decode($chat_body, true);

            if ($chat_code === 200 && isset($chat_data['ok']) && $chat_data['ok']) {
                error_log('[HashPoster] Telegram: Channel verified');
                return array('success' => true, 'message' => 'Bot and channel verified successfully');
            } else {
                $chat_error = $chat_data['description'] ?? 'Channel verification failed';
                error_log('[HashPoster] Telegram: Channel error - ' . $chat_error);
                return array('success' => false, 'error' => 'Channel error: ' . $chat_error . '. Make sure the bot is an admin in the channel and the channel ID format is correct (use @channelname or numeric ID like -1001234567890)');
            }
        }

        return array('success' => true, 'message' => 'Bot token verified (channel access not fully testable without posting)');
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
        // Get existing credentials to preserve values not in current form submission
        $existing = get_option('hashposter_api_credentials', array());
        $sanitized = $existing; // Start with existing data
        $platforms = array('twitter', 'linkedin', 'bluesky', 'facebook', 'telegram');

        foreach ($platforms as $platform) {
            if (isset($input[$platform])) {
                // Use platform-specific validation
                $validated = self::validate_api_credentials($input[$platform], $platform);
                $sanitized[$platform] = $validated;
            }
        }
        return $sanitized;
    }

    public function validate_post_cards_callback($input) {
        $sanitized = array();
    $platforms = array('twitter', 'linkedin', 'bluesky', 'facebook', 'telegram');

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

    public function validate_default_platforms_callback($input) {
        if (!is_array($input)) {
            return array('facebook', 'linkedin', 'bluesky');
        }
        
        $valid_platforms = array('twitter', 'facebook', 'linkedin', 'bluesky', 'telegram');
        $sanitized = array();
        
        foreach ($input as $platform) {
            $platform = sanitize_text_field($platform);
            if (in_array($platform, $valid_platforms)) {
                $sanitized[] = $platform;
            }
        }
        
        // Ensure at least one platform is selected
        if (empty($sanitized)) {
            $sanitized = array('facebook', 'linkedin', 'bluesky');
        }
        
        return $sanitized;
    }

}
?>