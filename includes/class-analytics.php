<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HashPoster Analytics
 *
 * Tracks successful and failed post publications (no click/engagement tracking)
 */
class HashPoster_Analytics {

    public function __construct() {
        add_action( 'hashposter_post_published', array( $this, 'track_post_publication' ), 10, 3 );
        add_action( 'admin_menu', array( $this, 'add_analytics_menu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_analytics_assets' ) );
        add_action( 'wp_ajax_hashposter_get_analytics_data', array( $this, 'ajax_get_analytics_data' ) );
    }

    /**
     * Add analytics submenu page
     */
    public function add_analytics_menu() {
        add_submenu_page(
            'hashposter-settings',
            'Analytics',
            'Analytics',
            'manage_options',
            'hashposter-analytics',
            array( $this, 'render_analytics_page' )
        );
    }

    /**
     * Enqueue analytics assets
     */
    public function enqueue_analytics_assets( $hook ) {
        if ( $hook !== 'hashposter_page_hashposter-analytics' ) {
            return;
        }

        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            array(),
            '4.4.0',
            true
        );

        $js_ver = file_exists( HASHPOSTER_PATH . 'assets/js/analytics.js' ) ? filemtime( HASHPOSTER_PATH . 'assets/js/analytics.js' ) : '2.3.0';
        wp_enqueue_script(
            'hashposter-analytics',
            HASHPOSTER_URL . 'assets/js/analytics.js',
            array( 'jquery', 'chart-js' ),
            $js_ver,
            true
        );

        wp_localize_script( 'hashposter-analytics', 'hashposterAnalytics', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'hashposter_analytics' ),
            'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : date( 'Y-m-d', strtotime( '-30 days' ) ),
            'date_to' => isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : date( 'Y-m-d' )
        ) );
    }

    /**
     * Render analytics page
     */
    public function render_analytics_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have sufficient permissions to access this page.' );
        }

        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : date( 'Y-m-d' );
        ?>
        <div class="wrap hashposter-settings">
            <div class="hp-dashboard">
                
                <!-- Unified Responsive Sidebar -->
                <aside class="hp-sidebar">
                    <div class="hp-brand-header">
                        <div class="hp-brand-icon"><span class="dashicons dashicons-share"></span></div>
                        <div class="hp-brand-text">
                            <h2>HashPoster</h2>
                            <span>PRO</span>
                        </div>
                    </div>

                    <nav class="hp-nav">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=hashposter-settings' ) ); ?>" class="hp-nav-item">
                            <span class="dashicons dashicons-admin-generic"></span>
                            Settings
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=hashposter-analytics' ) ); ?>" class="hp-nav-item active">
                            <span class="dashicons dashicons-chart-pie"></span>
                            Analytics
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=hashposter-bulk-posting' ) ); ?>" class="hp-nav-item">
                            <span class="dashicons dashicons-grid-view"></span>
                            Bulk Posting
                        </a>
                    </nav>
                </aside>

                <!-- Main Content Area -->
                <main class="hp-main">
                    
                    <!-- Top Bar -->
                    <div class="hp-topbar">
                        <div class="hp-topbar-title">
                            <h1><span class="dashicons dashicons-chart-pie" style="font-size: 26px; width: 26px; height: 26px; margin-right: 8px; color: var(--hp-brand);"></span> Performance Analytics</h1>
                            <p>Track your automated social media publication metrics, success rates, and live activity.</p>
                        </div>
                    </div>

                    <!-- Dashboard Content -->
                    <div class="hp-tab-panel active hp-analytics-panel">
                        
                        <!-- Filters Card -->
                        <div class="hp-card hp-filter-card">
                            <form method="get" action="" class="hp-analytics-filters">
                                <input type="hidden" name="page" value="hashposter-analytics" />
                                
                                <div class="hp-filter-row">
                                    <div class="hp-filter-field">
                                        <label for="date_from">From Date</label>
                                        <input type="date" id="date_from" name="date_from" class="hp-input" value="<?php echo esc_attr( $date_from ); ?>" />
                                    </div>
                                    <div class="hp-filter-field">
                                        <label for="date_to">To Date</label>
                                        <input type="date" id="date_to" name="date_to" class="hp-input" value="<?php echo esc_attr( $date_to ); ?>" />
                                    </div>
                                    <div class="hp-filter-btn-wrap">
                                        <button type="submit" class="hp-btn hp-btn-primary">
                                            <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                            Update Report
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Stats Overview Grid -->
                        <div class="hp-stats-grid">
                            
                            <div class="hp-card hp-stat-card">
                                <div class="hp-stat-icon hp-stat-blue">
                                    <span class="dashicons dashicons-megaphone"></span>
                                </div>
                                <div class="hp-stat-content">
                                    <div class="hp-stat-value" id="total-posts">-</div>
                                    <div class="hp-stat-label">Total Posts Executed</div>
                                </div>
                            </div>
                            
                            <div class="hp-card hp-stat-card">
                                <div class="hp-stat-icon hp-stat-green">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                </div>
                                <div class="hp-stat-content">
                                    <div class="hp-stat-value" id="successful-posts">-</div>
                                    <div class="hp-stat-label">Successful Posts</div>
                                </div>
                            </div>
                            
                            <div class="hp-card hp-stat-card">
                                <div class="hp-stat-icon hp-stat-red">
                                    <span class="dashicons dashicons-dismiss"></span>
                                </div>
                                <div class="hp-stat-content">
                                    <div class="hp-stat-value" id="failed-posts">-</div>
                                    <div class="hp-stat-label">Failed Transmissions</div>
                                </div>
                            </div>
                            
                            <div class="hp-card hp-stat-card">
                                <div class="hp-stat-icon hp-stat-amber">
                                    <span class="dashicons dashicons-awards"></span>
                                </div>
                                <div class="hp-stat-content">
                                    <div class="hp-stat-value" id="success-rate">-</div>
                                    <div class="hp-stat-label">Average Success Rate</div>
                                </div>
                            </div>

                        </div>

                        <!-- Charts and Recent Activity Split (Graph Shrunk, Posts Expanded) -->
                        <div class="hp-analytics-split">
                            
                            <!-- Shrunk Graph Card (~38-40% width) -->
                            <div class="hp-card hp-analytics-chart-card">
                                <div class="hp-card-header">
                                    <h4 class="hp-section-title">Platform Performance</h4>
                                </div>
                                <div class="chart-container">
                                    <canvas id="platform-chart"></canvas>
                                </div>
                            </div>

                            <!-- Expanded Activity Log Card (~60-62% width with Post Titles) -->
                            <div class="hp-card hp-analytics-activity-card">
                                <div class="hp-card-header hp-activity-card-header">
                                    <h4 class="hp-section-title">Recent Activity Log</h4>
                                    <span class="hp-activity-badge">Latest Broadcasts</span>
                                </div>
                                <div id="activity-list" class="hp-activity-list">
                                    <div class="hp-activity-loading">
                                        <span class="dashicons dashicons-update hp-spin"></span>
                                        <p>Loading activity log...</p>
                                    </div>
                                </div>
                            </div>

                        </div>

                    </div>
                </main>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler to get analytics data
     */
    public function ajax_get_analytics_data() {
        check_ajax_referer( 'hashposter_analytics', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
        }

        $date_from = sanitize_text_field( $_POST['date_from'] ?? date( 'Y-m-d', strtotime( '-30 days' ) ) );
        $date_to = sanitize_text_field( $_POST['date_to'] ?? date( 'Y-m-d' ) );

        $data = $this->get_analytics_data_for_range( $date_from, $date_to );

        wp_send_json_success( $data );
    }

    /**
     * Track post publication
     */
    public function track_post_publication( $post_id, $platform, $success ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';

        // Guard: if table doesn't exist yet, skip silently (in-memory cached check)
        static $table_verified = null;
        if ( $table_verified === null ) {
            $table_verified = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name );
        }
        if ( ! $table_verified ) {
            return;
        }

        $wpdb->insert(
            $table_name,
            array(
                'post_id'      => absint( $post_id ),
                'platform'     => sanitize_key( $platform ),
                'status'       => $success ? 'success' : 'error',
                'published_at' => current_time( 'mysql' )
            ),
            array( '%d', '%s', '%s', '%s' )
        );
    }

    /**
     * Get analytics data for date range
     */
    public function get_analytics_data_for_range( $date_from, $date_to ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';

        // Ensure table exists (in-memory cached check)
        static $table_verified_range = null;
        if ( $table_verified_range === null ) {
            $table_verified_range = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name );
        }
        if ( ! $table_verified_range ) {
            return array(
                'total_posts'         => 0,
                'successful_posts'    => 0,
                'failed_posts'        => 0,
                'success_rate'        => 0,
                'platform_data'       => array(),
                'recent_activity'     => array(),
                'most_active_platform'=> null
            );
        }

        // Get total posts
        $total_posts = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE DATE(published_at) BETWEEN %s AND %s",
            $date_from, $date_to
        ) );

        // Get successful posts
        $successful_posts = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE status = 'success' AND DATE(published_at) BETWEEN %s AND %s",
            $date_from, $date_to
        ) );

        // Get failed posts
        $failed_posts = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE status = 'error' AND DATE(published_at) BETWEEN %s AND %s",
            $date_from, $date_to
        ) );

        // Get platform performance
        $platform_data = $wpdb->get_results( $wpdb->prepare(
            "SELECT platform, COUNT(*) as count, status FROM {$table_name}
             WHERE DATE(published_at) BETWEEN %s AND %s
             GROUP BY platform, status",
            $date_from, $date_to
        ), ARRAY_A );

        // Get recent activity with joined post title
        $recent_activity = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, p.post_title
             FROM {$table_name} a
             LEFT JOIN {$wpdb->posts} p ON a.post_id = p.ID
             WHERE DATE(a.published_at) BETWEEN %s AND %s
             ORDER BY a.published_at DESC
             LIMIT 15",
            $date_from, $date_to
        ), ARRAY_A );

        if ( ! empty( $recent_activity ) ) {
            foreach ( $recent_activity as &$row ) {
                $pid = absint( $row['post_id'] );
                $raw_title = ! empty( $row['post_title'] ) ? trim( wp_strip_all_tags( $row['post_title'] ) ) : '';
                if ( empty( $raw_title ) ) {
                    $raw_title = sprintf( __( 'Post #%d', 'hashposter' ), $pid );
                }
                $row['post_title'] = $raw_title;
                $row['edit_url']   = admin_url( 'post.php?post=' . $pid . '&action=edit' );
            }
            unset( $row );
        }

        // Get most active platform
        $most_active_platform = $wpdb->get_row( $wpdb->prepare(
            "SELECT platform, COUNT(*) as count FROM {$table_name}
             WHERE DATE(published_at) BETWEEN %s AND %s
             GROUP BY platform
             ORDER BY count DESC
             LIMIT 1",
            $date_from, $date_to
        ), ARRAY_A );

        return array(
            'total_posts' => intval( $total_posts ),
            'successful_posts' => intval( $successful_posts ),
            'failed_posts' => intval( $failed_posts ),
            'success_rate' => $total_posts > 0 ? round( ( $successful_posts / $total_posts ) * 100, 1 ) : 0,
            'platform_data' => $platform_data,
            'recent_activity' => $recent_activity,
            'most_active_platform' => $most_active_platform
        );
    }

    /**
     * Create analytics table
     */
    public static function create_analytics_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            platform varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            published_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY platform (platform),
            KEY status (status),
            KEY published_at (published_at)
        ) {$charset_collate};";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Get analytics summary for dashboard
     */
    public static function get_analytics_summary( $days = 30 ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';
        $date_from = date( 'Y-m-d', strtotime( "-{$days} days" ) );

        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" );
        if ( ! $table_exists ) {
            return array(
                'total_posts' => 0,
                'successful_posts' => 0,
                'failed_posts' => 0,
                'success_rate' => 0
            );
        }

        $summary = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as total_posts,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_posts,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failed_posts
             FROM {$table_name}
             WHERE DATE(published_at) >= %s",
            $date_from
        ), ARRAY_A );

        if ( $summary ) {
            $summary['success_rate'] = $summary['total_posts'] > 0 ?
                round( ( $summary['successful_posts'] / $summary['total_posts'] ) * 100, 1 ) : 0;
        }

        return $summary ?: array(
            'total_posts' => 0,
            'successful_posts' => 0,
            'failed_posts' => 0,
            'success_rate' => 0
        );
    }
}
