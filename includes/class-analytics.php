<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HashPoster Analytics
 *
 * Provides analytics and reporting for social media performance
 */
class HashPoster_Analytics {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_analytics_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_analytics_assets' ) );
        add_action( 'wp_ajax_hashposter_get_analytics_data', array( $this, 'get_analytics_data' ) );
        add_action( 'wp_ajax_hashposter_update_engagement', array( $this, 'ajax_update_engagement_data' ) );
        add_action( 'hashposter_post_published', array( $this, 'track_post_publication' ), 10, 3 );
        
        // Schedule cron for engagement data updates
        add_action( 'hashposter_update_engagement_cron', array( $this, 'update_engagement_data' ) );
        if ( ! wp_next_scheduled( 'hashposter_update_engagement_cron' ) ) {
            wp_schedule_event( time(), 'hourly', 'hashposter_update_engagement_cron' );
        }
    }

    /**
     * Add analytics menu
     */
    public function add_analytics_menu() {
        add_submenu_page(
            'hashposter-settings',
            'Analytics',
            'Analytics',
            'publish_posts',
            'hashposter-analytics',
            array( $this, 'render_analytics_page' )
        );
    }

    /**
     * Render analytics page
     */
    public function render_analytics_page() {
        if ( ! current_user_can( 'publish_posts' ) ) {
            wp_die( 'You do not have sufficient permissions to access this page.' );
        }

        // Get date range
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : date( 'Y-m-d' );

        ?>
        <div class="wrap">
            <h1><?php _e( 'HashPoster - Social Media Analytics', 'hashposter' ); ?></h1>

            <div class="hashposter-analytics-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="hashposter-analytics" />

                    <label for="date_from"><?php _e( 'From:', 'hashposter' ); ?></label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />

                    <label for="date_to"><?php _e( 'To:', 'hashposter' ); ?></label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />

                    <button type="submit" class="button"><?php _e( 'Update', 'hashposter' ); ?></button>
                    <button type="button" id="hashposter-update-engagement" class="button button-primary" style="margin-left: 10px;">
                        <?php _e( 'Update Engagement Data', 'hashposter' ); ?>
                    </button>
                </form>
            </div>

            <div class="hashposter-analytics-dashboard">
                <!-- Overview Cards -->
                <div class="hashposter-stats-grid">
                    <div class="hashposter-stat-card">
                        <h3><?php _e( 'Total Posts', 'hashposter' ); ?></h3>
                        <div class="hashposter-stat-number" id="total-posts">-</div>
                        <div class="hashposter-stat-change" id="total-posts-change"></div>
                    </div>

                    <div class="hashposter-stat-card">
                        <h3><?php _e( 'Successful Posts', 'hashposter' ); ?></h3>
                        <div class="hashposter-stat-number" id="successful-posts">-</div>
                        <div class="hashposter-stat-percentage" id="success-rate">-</div>
                    </div>

                    <div class="hashposter-stat-card">
                        <h3><?php _e( 'Failed Posts', 'hashposter' ); ?></h3>
                        <div class="hashposter-stat-number" id="failed-posts">-</div>
                        <div class="hashposter-stat-change" id="failed-posts-change"></div>
                    </div>

                    <div class="hashposter-stat-card">
                        <h3><?php _e( 'Most Active Platform', 'hashposter' ); ?></h3>
                        <div class="hashposter-stat-text" id="most-active-platform">-</div>
                        <div class="hashposter-stat-subtext" id="platform-count">-</div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="hashposter-charts-row">
                    <div class="hashposter-chart-container">
                        <h3><?php _e( 'Posts Over Time', 'hashposter' ); ?></h3>
                        <canvas id="posts-over-time-chart"></canvas>
                    </div>

                    <div class="hashposter-chart-container">
                        <h3><?php _e( 'Platform Performance', 'hashposter' ); ?></h3>
                        <canvas id="platform-performance-chart"></canvas>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="hashposter-recent-activity">
                    <h3><?php _e( 'Recent Activity', 'hashposter' ); ?></h3>
                    <div id="hashposter-activity-list">
                        <p><?php _e( 'Loading recent activity...', 'hashposter' ); ?></p>
                    </div>
                </div>

                <!-- Top Performing Posts -->
                <div class="hashposter-top-posts">
                    <h3><?php _e( 'Top Performing Posts', 'hashposter' ); ?></h3>
                    <div id="hashposter-top-posts-list">
                        <p><?php _e( 'Loading top posts...', 'hashposter' ); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .hashposter-analytics-filters {
            background: #fff;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .hashposter-analytics-filters form {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .hashposter-analytics-filters label {
            font-weight: bold;
        }

        .hashposter-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .hashposter-stat-card {
            background: #fff;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-align: center;
        }

        .hashposter-stat-card h3 {
            margin: 0 0 15px 0;
            color: #23282d;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .hashposter-stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #007cba;
            margin-bottom: 5px;
        }

        .hashposter-stat-change {
            font-size: 12px;
            color: #666;
        }

        .hashposter-stat-change.positive {
            color: #46b450;
        }

        .hashposter-stat-change.negative {
            color: #dc3232;
        }

        .hashposter-stat-percentage {
            font-size: 14px;
            color: #666;
        }

        .hashposter-stat-text {
            font-size: 24px;
            font-weight: bold;
            color: #007cba;
            margin-bottom: 5px;
        }

        .hashposter-stat-subtext {
            font-size: 12px;
            color: #666;
        }

        .hashposter-charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .hashposter-chart-container {
            background: #fff;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .hashposter-chart-container h3 {
            margin-top: 0;
            margin-bottom: 20px;
        }

        .hashposter-chart-container canvas {
            max-height: 300px;
        }

        .hashposter-recent-activity,
        .hashposter-top-posts {
            background: #fff;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .hashposter-recent-activity h3,
        .hashposter-top-posts h3 {
            margin-top: 0;
        }

        .hashposter-activity-item,
        .hashposter-top-post-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .hashposter-activity-item:last-child,
        .hashposter-top-post-item:last-child {
            border-bottom: none;
        }

        .hashposter-activity-info {
            flex: 1;
        }

        .hashposter-activity-info strong {
            display: block;
            color: #23282d;
        }

        .hashposter-activity-meta {
            font-size: 12px;
            color: #666;
        }

        .hashposter-activity-status {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .hashposter-activity-status.success {
            background: #46b450;
            color: white;
        }

        .hashposter-activity-status.error {
            background: #dc3232;
            color: white;
        }

        .hashposter-engagement-stats {
            margin-top: 5px;
            font-size: 11px;
            color: #666;
        }

        .hashposter-post-info {
            flex: 1;
        }

        .hashposter-post-meta {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .hashposter-engagement-metrics {
            margin-top: 8px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .hashposter-engagement-metrics .metric-item {
            font-size: 11px;
            color: #666;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .hashposter-engagement-metrics .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
        }

        .hashposter-post-stats {
            text-align: center;
            padding-left: 20px;
        }

        .engagement-score {
            font-size: 28px;
            font-weight: bold;
            color: #007cba;
            line-height: 1;
        }

        .score-label {
            font-size: 10px;
            color: #666;
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        @media (max-width: 768px) {
            .hashposter-charts-row {
                grid-template-columns: 1fr;
            }

            .hashposter-analytics-filters form {
                flex-direction: column;
                align-items: stretch;
            }
        }
        </style>
        <?php
    }

    /**
     * Enqueue analytics assets
     */
    public function enqueue_analytics_assets( $hook ) {
        if ( $hook !== 'hashposter_page_hashposter-analytics' ) {
            return;
        }

        // Ensure WordPress functions are available
        if ( ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'wp_localize_script' ) ) {
            return;
        }

        // Enqueue Chart.js
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js',
            array(),
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'hashposter-analytics',
            HASHPOSTER_URL . 'assets/js/analytics.js',
            array( 'jquery', 'chart-js' ),
            '1.0',
            true
        );

        if ( function_exists( 'admin_url' ) && function_exists( 'wp_create_nonce' ) ) {
            wp_localize_script( 'hashposter-analytics', 'hashposterAnalytics', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'hashposter_analytics' ),
                'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : date( 'Y-m-d', strtotime( '-30 days' ) ),
                'date_to' => isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : date( 'Y-m-d' ),
                'strings' => array(
                    'loading' => __( 'Loading...', 'hashposter' ),
                    'no_data' => __( 'No data available', 'hashposter' ),
                    'success' => __( 'Success', 'hashposter' ),
                    'error' => __( 'Error', 'hashposter' )
                )
            ) );
        }
    }

    /**
     * Get analytics data via AJAX
     */
    public function get_analytics_data() {
        check_ajax_referer( 'hashposter_analytics', 'nonce' );

        if ( ! current_user_can( 'publish_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
        }

        $date_from = sanitize_text_field( $_POST['date_from'] ?? date( 'Y-m-d', strtotime( '-30 days' ) ) );
        $date_to = sanitize_text_field( $_POST['date_to'] ?? date( 'Y-m-d' ) );

        $data = $this->get_analytics_data_for_range( $date_from, $date_to );

        wp_send_json_success( $data );
    }

    /**
     * Update engagement data via AJAX
     */
    public function ajax_update_engagement_data() {
        check_ajax_referer( 'hashposter_analytics', 'nonce' );

        if ( ! current_user_can( 'publish_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
        }

        $updated = $this->update_engagement_data();

        wp_send_json_success( array( 
            'message' => sprintf( __( 'Updated engagement data for %d posts', 'hashposter' ), $updated ),
            'count' => $updated
        ) );
    }

    /**
     * Get analytics data for date range
     */
    private function get_analytics_data_for_range( $date_from, $date_to ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';

        // Ensure table exists
        $this->create_analytics_table();

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

        // Get posts over time
        $posts_over_time = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(published_at) as date, COUNT(*) as count
             FROM {$table_name}
             WHERE DATE(published_at) BETWEEN %s AND %s
             GROUP BY DATE(published_at)
             ORDER BY date",
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

        // Get top performing posts based on engagement
        $top_posts = $this->get_top_performing_posts( $date_from, $date_to );

        return array(
            'total_posts' => intval( $total_posts ),
            'successful_posts' => intval( $successful_posts ),
            'failed_posts' => intval( $failed_posts ),
            'success_rate' => $total_posts > 0 ? round( ( $successful_posts / $total_posts ) * 100, 1 ) : 0,
            'platform_data' => $platform_data,
            'posts_over_time' => $posts_over_time,
            'recent_activity' => $recent_activity,
            'most_active_platform' => $most_active_platform,
            'top_posts' => $top_posts
        );
    }

    /**
     * Get top performing posts based on engagement metrics
     */
    private function get_top_performing_posts( $date_from, $date_to, $limit = 10 ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';

        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                a.post_id,
                a.platform,
                a.engagement_score,
                a.clicks,
                a.likes,
                a.shares,
                a.impressions,
                a.published_at,
                p.post_title,
                p.post_date
             FROM {$table_name} a
             LEFT JOIN {$wpdb->posts} p ON a.post_id = p.ID
             WHERE a.status = 'success'
             AND DATE(a.published_at) BETWEEN %s AND %s
             AND a.engagement_score > 0
             ORDER BY a.engagement_score DESC
             LIMIT %d",
            $date_from, $date_to, $limit
        ), ARRAY_A );

        return $posts ?: array();
    }

    /**
     * Track post publication
     */
    public function track_post_publication( $post_id, $platform, $success ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';

        // Ensure table exists
        $this->create_analytics_table();

        $data = array(
            'post_id' => $post_id,
            'platform' => $platform,
            'status' => $success ? 'success' : 'error',
            'published_at' => current_time( 'mysql' )
        );

        // If successful, try to get the post URL and shortened URL
        if ( $success ) {
            $post_url = get_permalink( $post_id );
            if ( $post_url ) {
                $data['post_url'] = $post_url;
                $data['shortened_url'] = $this->create_short_url( $post_url );
            }
        }

        $wpdb->insert(
            $table_name,
            $data,
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Update engagement data for posts
     */
    public function update_engagement_data() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';
        $updated_count = 0;

        // Get posts that need engagement updates (older than 1 hour or never updated)
        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE status = 'success'
             AND (last_updated IS NULL OR last_updated < DATE_SUB(NOW(), INTERVAL 1 HOUR))
             ORDER BY published_at DESC
             LIMIT 50"
        ), ARRAY_A );

        foreach ( $posts as $post ) {
            $engagement_data = $this->fetch_engagement_data( $post['platform'], $post['post_url'] );

            if ( $engagement_data ) {
                $engagement_score = $this->calculate_engagement_score( $engagement_data );

                $result = $wpdb->update(
                    $table_name,
                    array(
                        'clicks' => $engagement_data['clicks'] ?? $post['clicks'],
                        'opens' => $engagement_data['opens'] ?? $post['opens'],
                        'impressions' => $engagement_data['impressions'] ?? $post['impressions'],
                        'likes' => $engagement_data['likes'] ?? $post['likes'],
                        'shares' => $engagement_data['shares'] ?? $post['shares'],
                        'comments' => $engagement_data['comments'] ?? $post['comments'],
                        'engagement_score' => $engagement_score,
                        'last_updated' => current_time( 'mysql' )
                    ),
                    array( 'id' => $post['id'] ),
                    array( '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%s' ),
                    array( '%d' )
                );

                if ( $result !== false ) {
                    $updated_count++;
                }
            }
        }

        return $updated_count;
    }

    /**
     * Fetch engagement data from social media APIs
     */
    private function fetch_engagement_data( $platform, $url ) {
        // This is a placeholder - actual implementation would depend on each platform's API
        // For now, return mock data for demonstration
        return array(
            'clicks' => rand(10, 100),
            'opens' => rand(5, 50),
            'impressions' => rand(100, 1000),
            'likes' => rand(0, 20),
            'shares' => rand(0, 10),
            'comments' => rand(0, 5)
        );
    }

    /**
     * Calculate engagement score based on various metrics
     */
    private function calculate_engagement_score( $data ) {
        // Simple engagement score calculation
        // Weight: clicks (3x), likes (2x), shares (4x), comments (5x)
        $score = (
            ($data['clicks'] ?? 0) * 3 +
            ($data['likes'] ?? 0) * 2 +
            ($data['shares'] ?? 0) * 4 +
            ($data['comments'] ?? 0) * 5
        ) / max($data['impressions'] ?? 1, 1); // Normalize by impressions

        return round( $score, 2 );
    }

    /**
     * Create shortened URL for tracking
     */
    private function create_short_url( $url ) {
        // Placeholder for URL shortening service integration
        // Could integrate with Bitly, TinyURL, or similar service
        return $url; // For now, return original URL
    }

    /**
     * Create analytics table
     */
    private function create_analytics_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            platform varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            published_at datetime DEFAULT CURRENT_TIMESTAMP,
            post_url text DEFAULT NULL,
            shortened_url text DEFAULT NULL,
            clicks int(11) DEFAULT 0,
            opens int(11) DEFAULT 0,
            impressions int(11) DEFAULT 0,
            likes int(11) DEFAULT 0,
            shares int(11) DEFAULT 0,
            comments int(11) DEFAULT 0,
            engagement_score decimal(10,2) DEFAULT 0.00,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY platform (platform),
            KEY status (status),
            KEY published_at (published_at),
            KEY engagement_score (engagement_score)
        ) {$charset_collate};";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        // Check if we need to add new columns to existing table
        $this->upgrade_analytics_table();
    }

    /**
     * Upgrade analytics table to add new columns
     */
    private function upgrade_analytics_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';

        // Check if new columns exist, add them if they don't
        $columns_to_add = array(
            'post_url' => 'text DEFAULT NULL',
            'shortened_url' => 'text DEFAULT NULL',
            'clicks' => 'int(11) DEFAULT 0',
            'opens' => 'int(11) DEFAULT 0',
            'impressions' => 'int(11) DEFAULT 0',
            'likes' => 'int(11) DEFAULT 0',
            'shares' => 'int(11) DEFAULT 0',
            'comments' => 'int(11) DEFAULT 0',
            'engagement_score' => 'decimal(10,2) DEFAULT 0.00',
            'last_updated' => 'datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );

        foreach ($columns_to_add as $column => $definition) {
            $column_exists = $wpdb->get_results( $wpdb->prepare(
                "SHOW COLUMNS FROM {$table_name} LIKE %s",
                $column
            ) );

            if ( empty( $column_exists ) ) {
                $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN {$column} {$definition}" );
            }
        }

        // Add new index if it doesn't exist
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'engagement_score'" );
        if ( empty( $indexes ) ) {
            $wpdb->query( "ALTER TABLE {$table_name} ADD INDEX engagement_score (engagement_score)" );
        }
    }

    /**
     * Get analytics summary for dashboard
     */
    public static function get_analytics_summary( $days = 30 ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hashposter_analytics';
        $date_from = date( 'Y-m-d', strtotime( "-{$days} days" ) );

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