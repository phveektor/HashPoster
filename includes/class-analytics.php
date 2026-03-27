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
        add_action( 'admin_menu', array( $this, 'add_analytics_menu' ) );
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

        wp_enqueue_script(
            'hashposter-analytics',
            HASHPOSTER_URL . 'assets/js/analytics.js',
            array( 'jquery', 'chart-js' ),
            '1.0.0',
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
                <main class="hp-main" style="flex: 1; border-radius: var(--hp-radius-lg);">
                    
                    <!-- Top Bar -->
                    <div class="hp-topbar">
                        <div class="hp-topbar-title">
                            <h1><span class="dashicons dashicons-chart-pie" style="font-size: 28px; width: 28px; height: 28px; margin-right: 8px; color: var(--hp-brand);"></span> HashPoster Analytics</h1>
                            <p>Track your social media publication success and failure rates.</p>
                        </div>
                    </div>

                    <!-- Main Dashboard Content -->
                    <div class="hp-tab-panel active" style="padding: 40px; border:none; display:block;">
                        
                        <!-- Filters -->
                        <div class="hp-card" style="margin-bottom: 32px;">
                            <form method="get" action="" class="hp-analytics-filters">
                                <input type="hidden" name="page" value="hashposter-analytics" />
                                
                                <div style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
                                    <div>
                                        <label for="date_from" style="display:block; font-size:13px; font-weight:600; color:var(--hp-text-muted); margin-bottom:6px;">From Date</label>
                                        <input type="date" id="date_from" name="date_from" class="hp-input" value="<?php echo esc_attr( $date_from ); ?>" />
                                    </div>
                                    <div>
                                        <label for="date_to" style="display:block; font-size:13px; font-weight:600; color:var(--hp-text-muted); margin-bottom:6px;">To Date</label>
                                        <input type="date" id="date_to" name="date_to" class="hp-input" value="<?php echo esc_attr( $date_to ); ?>" />
                                    </div>
                                    <div>
                                        <button type="submit" class="hp-btn hp-btn-primary" style="padding: 10px 20px;">Update Report</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Stats Overview Grid -->
                        <div class="hp-stats-grid">
                            
                            <div class="hp-card hp-stat-card">
                                <div class="hp-stat-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);"><span class="dashicons dashicons-megaphone"></span></div>
                                <div class="hp-stat-content">
                                    <div class="hp-stat-value" id="total-posts">-</div>
                                    <div class="hp-stat-label">Total Posts Executed</div>
                                </div>
                            </div>
                            
                            <div class="hp-card hp-stat-card">
                                <div class="hp-stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><span class="dashicons dashicons-yes-alt"></span></div>
                                <div class="hp-stat-content">
                                    <div class="hp-stat-value" id="successful-posts">-</div>
                                    <div class="hp-stat-label">Successful Posts</div>
                                </div>
                            </div>
                            
                            <div class="hp-card hp-stat-card">
                                <div class="hp-stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><span class="dashicons dashicons-dismiss"></span></div>
                                <div class="hp-stat-content">
                                    <div class="hp-stat-value" id="failed-posts">-</div>
                                    <div class="hp-stat-label">Failed Transmissions</div>
                                </div>
                            </div>
                            
                            <div class="hp-card hp-stat-card">
                                <div class="hp-stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"><span class="dashicons dashicons-awards"></span></div>
                                <div class="hp-stat-content">
                                    <div class="hp-stat-value" id="success-rate">-</div>
                                    <div class="hp-stat-label">Average Success Rate</div>
                                </div>
                            </div>

                        </div>

                        <!-- Charts and Recent Activity -->
                        <div style="display:flex; gap:32px; flex-wrap:wrap; margin-top:32px;">
                            <div class="hp-card" style="flex: 2; min-width:300px;">
                                <h4 class="hp-section-title">Platform Performance</h4>
                                <div class="chart-container" style="position:relative; height:350px; margin-top:16px;">
                                    <canvas id="platform-chart"></canvas>
                                </div>
                            </div>

                            <div class="hp-card" style="flex: 1; min-width:300px;">
                                <h4 class="hp-section-title">Recent Activity Log</h4>
                                <div id="activity-list" class="hp-activity-list" style="margin-top:16px;">
                                    <div style="text-align: center; color: var(--hp-text-muted); padding:30px 0;">Loading analytics...</div>
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

        // Guard: if table doesn't exist yet, skip silently (activation didn't run)
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
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

        // Ensure table exists (lightweight check)
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            return array(
                'total_posts'         => 0, 'successful_posts' => 0,
                'failed_posts'        => 0, 'success_rate'     => 0,
                'platform_data'       => array(), 'recent_activity'  => array(),
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

        // Get recent activity
        $recent_activity = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE DATE(published_at) BETWEEN %s AND %s
             ORDER BY published_at DESC
             LIMIT 10",
            $date_from, $date_to
        ), ARRAY_A );

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

        // Check if table exists
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
