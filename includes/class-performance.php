<?php
/**
 * HashPoster Performance Handler
 *
 * Provides enterprise-grade performance optimizations including
 * caching, database optimization, and resource management.
 *
 * @package HashPoster
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Performance Handler Class
 */
class HashPoster_Performance {

    /**
     * Performance instance
     *
     * @var HashPoster_Performance
     */
    private static $instance = null;

    /**
     * Logger instance
     *
     * @var HashPoster_Logger
     */
    private $logger;

    /**
     * Cache group
     *
     * @var string
     */
    private $cache_group = 'hashposter';

    /**
     * Cache expiration times
     *
     * @var array
     */
    private $cache_expiration = array(
        'api_response'    => 300,   // 5 minutes
        'post_data'       => 1800,  // 30 minutes
        'user_settings'   => 3600,  // 1 hour
        'platform_status' => 900,   // 15 minutes
        'analytics'       => 1800,  // 30 minutes
    );

    /**
     * Memory usage tracking
     *
     * @var array
     */
    private $memory_checkpoints = array();

    /**
     * Query tracking
     *
     * @var array
     */
    private $query_log = array();

    /**
     * Get performance instance
     *
     * @return HashPoster_Performance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->logger = HashPoster_Logger::get_instance();
        $this->init_hooks();
        $this->setup_query_monitoring();
    }

    /**
     * Initialize performance hooks
     */
    private function init_hooks() {
        add_action( 'shutdown', array( $this, 'performance_summary' ) );
        add_action( 'wp_ajax_hashposter_performance_stats', array( $this, 'ajax_performance_stats' ) );
        add_filter( 'hashposter_cache_key', array( $this, 'generate_cache_key' ), 10, 2 );
        
        // Object cache optimization
        if ( function_exists( 'wp_cache_add_global_groups' ) ) {
            wp_cache_add_global_groups( array( $this->cache_group ) );
        }
    }

    /**
     * Setup query monitoring
     */
    private function setup_query_monitoring() {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            add_filter( 'query', array( $this, 'log_query' ) );
        }
    }

    /**
     * Cache data with automatic expiration and compression
     *
     * @param string $key        Cache key
     * @param mixed  $data       Data to cache
     * @param string $type       Cache type for expiration
     * @param int    $expiration Custom expiration (optional)
     * @return bool
     */
    public function cache_set( $key, $data, $type = 'default', $expiration = null ) {
        $cache_key = $this->generate_cache_key( $key, $type );
        $expire_time = $expiration ?? $this->cache_expiration[ $type ] ?? 3600;

        // Compress large data
        if ( is_string( $data ) && strlen( $data ) > 1024 && function_exists( 'gzcompress' ) ) {
            $data = array(
                'compressed' => true,
                'data'       => gzcompress( $data ),
            );
        }

        $result = wp_cache_set( $cache_key, $data, $this->cache_group, $expire_time );

        if ( $result ) {
            $this->logger->debug( 'Cache set successful', array(
                'key'        => $cache_key,
                'type'       => $type,
                'size'       => $this->get_data_size( $data ),
                'expiration' => $expire_time,
            ) );
        }

        return $result;
    }

    /**
     * Get cached data with automatic decompression
     *
     * @param string $key  Cache key
     * @param string $type Cache type
     * @return mixed|false
     */
    public function cache_get( $key, $type = 'default' ) {
        $cache_key = $this->generate_cache_key( $key, $type );
        $data = wp_cache_get( $cache_key, $this->cache_group );

        if ( false === $data ) {
            $this->logger->debug( 'Cache miss', array( 'key' => $cache_key ) );
            return false;
        }

        // Decompress if needed
        if ( is_array( $data ) && isset( $data['compressed'] ) && $data['compressed'] ) {
            $data = gzuncompress( $data['data'] );
        }

        $this->logger->debug( 'Cache hit', array( 'key' => $cache_key ) );
        return $data;
    }

    /**
     * Delete cached data
     *
     * @param string $key  Cache key
     * @param string $type Cache type
     * @return bool
     */
    public function cache_delete( $key, $type = 'default' ) {
        $cache_key = $this->generate_cache_key( $key, $type );
        return wp_cache_delete( $cache_key, $this->cache_group );
    }

    /**
     * Flush cache for specific type or all
     *
     * @param string $type Cache type (optional)
     * @return bool
     */
    public function cache_flush( $type = null ) {
        if ( $type ) {
            // Flush specific type (WordPress doesn't support this natively)
            // We'll implement a workaround using cache keys
            $keys = get_transient( "hashposter_cache_keys_{$type}" );
            if ( $keys && is_array( $keys ) ) {
                foreach ( $keys as $key ) {
                    wp_cache_delete( $key, $this->cache_group );
                }
                delete_transient( "hashposter_cache_keys_{$type}" );
            }
            return true;
        }

        return wp_cache_flush();
    }

    /**
     * Generate cache key
     *
     * @param string $key  Base key
     * @param string $type Cache type
     * @return string
     */
    public function generate_cache_key( $key, $type = 'default' ) {
        $cache_key = sprintf( '%s_%s_%s', $this->cache_group, $type, md5( $key ) );
        
        // Track cache keys for type-specific flushing
        $keys = get_transient( "hashposter_cache_keys_{$type}" ) ?: array();
        if ( ! in_array( $cache_key, $keys, true ) ) {
            $keys[] = $cache_key;
            set_transient( "hashposter_cache_keys_{$type}", $keys, DAY_IN_SECONDS );
        }
        
        return $cache_key;
    }

    /**
     * Optimize database queries with caching
     *
     * @param string $query     SQL query
     * @param array  $args      Query arguments
     * @param string $cache_key Cache key
     * @param int    $expiration Cache expiration
     * @return mixed
     */
    public function cached_query( $query, $args = array(), $cache_key = '', $expiration = 3600 ) {
        global $wpdb;

        if ( empty( $cache_key ) ) {
            $cache_key = md5( $query . serialize( $args ) );
        }

        $result = $this->cache_get( $cache_key, 'query' );
        if ( false !== $result ) {
            return $result;
        }

        $start_time = microtime( true );

        if ( ! empty( $args ) ) {
            $prepared_query = $wpdb->prepare( $query, $args );
        } else {
            $prepared_query = $query;
        }

        if ( stripos( $query, 'SELECT' ) === 0 ) {
            $result = $wpdb->get_results( $prepared_query );
        } else {
            $result = $wpdb->query( $prepared_query );
        }

        $execution_time = microtime( true ) - $start_time;

        // Cache successful SELECT queries
        if ( stripos( $query, 'SELECT' ) === 0 && ! is_wp_error( $result ) ) {
            $this->cache_set( $cache_key, $result, 'query', $expiration );
        }

        $this->logger->debug( 'Database query executed', array(
            'query'          => substr( $prepared_query, 0, 200 ),
            'execution_time' => $execution_time,
            'rows_affected'  => $wpdb->num_rows,
            'cached'         => false,
        ) );

        return $result;
    }

    /**
     * Batch process large datasets
     *
     * @param array    $items     Items to process
     * @param callable $callback  Processing callback
     * @param int      $batch_size Batch size
     * @param int      $delay     Delay between batches (microseconds)
     * @return array
     */
    public function batch_process( $items, $callback, $batch_size = 10, $delay = 100000 ) {
        $results = array();
        $total_items = count( $items );
        $batches = array_chunk( $items, $batch_size );
        
        $this->logger->info( 'Starting batch process', array(
            'total_items' => $total_items,
            'batch_size'  => $batch_size,
            'total_batches' => count( $batches ),
        ) );

        foreach ( $batches as $batch_index => $batch ) {
            $batch_start = microtime( true );
            
            foreach ( $batch as $item ) {
                try {
                    $result = call_user_func( $callback, $item );
                    $results[] = $result;
                } catch ( Exception $e ) {
                    $this->logger->error( 'Batch process item failed', array(
                        'item'  => $item,
                        'error' => $e->getMessage(),
                    ) );
                    $results[] = new WP_Error( 'batch_item_failed', $e->getMessage() );
                }
            }

            $batch_time = microtime( true ) - $batch_start;
            
            $this->logger->debug( 'Batch processed', array(
                'batch_index' => $batch_index + 1,
                'items_processed' => count( $batch ),
                'execution_time' => $batch_time,
            ) );

            // Add delay between batches to prevent server overload
            if ( $delay > 0 && $batch_index < count( $batches ) - 1 ) {
                usleep( $delay );
            }

            // Check memory usage and garbage collect if needed
            $this->memory_checkpoint( "batch_{$batch_index}" );
            if ( memory_get_usage() > $this->get_memory_limit() * 0.8 ) {
                gc_collect_cycles();
                $this->logger->warning( 'High memory usage, garbage collection triggered' );
            }
        }

        $this->logger->info( 'Batch process completed', array(
            'total_processed' => count( $results ),
            'success_count'   => count( array_filter( $results, function( $r ) { 
                return ! is_wp_error( $r ); 
            } ) ),
        ) );

        return $results;
    }

    /**
     * Set memory checkpoint
     *
     * @param string $checkpoint Checkpoint name
     */
    public function memory_checkpoint( $checkpoint ) {
        $this->memory_checkpoints[ $checkpoint ] = array(
            'time'   => microtime( true ),
            'memory' => memory_get_usage(),
            'peak'   => memory_get_peak_usage(),
        );
    }

    /**
     * Get memory limit in bytes
     *
     * @return int
     */
    private function get_memory_limit() {
        $limit = ini_get( 'memory_limit' );
        if ( $limit === '-1' ) {
            return PHP_INT_MAX;
        }
        
        return wp_convert_hr_to_bytes( $limit );
    }

    /**
     * Log database query
     *
     * @param string $query SQL query
     * @return string
     */
    public function log_query( $query ) {
        if ( stripos( $query, 'hashposter' ) !== false ) {
            $this->query_log[] = array(
                'query' => $query,
                'time'  => microtime( true ),
                'trace' => wp_debug_backtrace_summary(),
            );
        }
        
        return $query;
    }

    /**
     * Get data size for logging
     *
     * @param mixed $data Data to measure
     * @return string
     */
    private function get_data_size( $data ) {
        $size = strlen( serialize( $data ) );
        
        if ( $size < 1024 ) {
            return $size . ' B';
        } elseif ( $size < 1048576 ) {
            return round( $size / 1024, 2 ) . ' KB';
        } else {
            return round( $size / 1048576, 2 ) . ' MB';
        }
    }

    /**
     * Performance summary at shutdown
     */
    public function performance_summary() {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $summary = array(
            'execution_time' => microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'],
            'memory_usage'   => memory_get_usage(),
            'peak_memory'    => memory_get_peak_usage(),
            'query_count'    => count( $this->query_log ),
            'checkpoints'    => count( $this->memory_checkpoints ),
        );

        $this->logger->debug( 'Performance summary', $summary );
    }

    /**
     * AJAX handler for performance statistics
     */
    public function ajax_performance_stats() {
        check_ajax_referer( 'hashposter_admin', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
        }

        $stats = array(
            'memory_checkpoints' => $this->memory_checkpoints,
            'query_log'         => array_slice( $this->query_log, -20 ), // Last 20 queries
            'cache_stats'       => $this->get_cache_stats(),
            'system_info'       => $this->get_system_info(),
        );

        wp_send_json_success( $stats );
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    private function get_cache_stats() {
        // This would need to be implemented based on the caching system in use
        return array(
            'hits'   => 0,
            'misses' => 0,
            'ratio'  => 0,
        );
    }

    /**
     * Get system information
     *
     * @return array
     */
    private function get_system_info() {
        return array(
            'php_version'    => PHP_VERSION,
            'memory_limit'   => ini_get( 'memory_limit' ),
            'max_execution'  => ini_get( 'max_execution_time' ),
            'upload_limit'   => ini_get( 'upload_max_filesize' ),
            'wordpress'      => get_bloginfo( 'version' ),
            'mysql_version'  => $this->get_mysql_version(),
        );
    }

    /**
     * Get MySQL version
     *
     * @return string
     */
    private function get_mysql_version() {
        global $wpdb;
        return $wpdb->get_var( "SELECT VERSION()" );
    }

    /**
     * Optimize images for social media
     *
     * @param string $image_path Path to image
     * @param array  $sizes      Required sizes
     * @return array
     */
    public function optimize_images( $image_path, $sizes = array() ) {
        if ( ! file_exists( $image_path ) ) {
            return new WP_Error( 'file_not_found', 'Image file not found' );
        }

        $optimized = array();
        $default_sizes = array(
            'twitter'  => array( 'width' => 1200, 'height' => 675 ),
            'facebook' => array( 'width' => 1200, 'height' => 630 ),
            'linkedin' => array( 'width' => 1200, 'height' => 627 ),
        );

        $sizes = array_merge( $default_sizes, $sizes );

        foreach ( $sizes as $platform => $size ) {
            $cache_key = md5( $image_path . serialize( $size ) );
            $cached_path = $this->cache_get( $cache_key, 'image' );
            
            if ( $cached_path && file_exists( $cached_path ) ) {
                $optimized[ $platform ] = $cached_path;
                continue;
            }

            $optimized_path = $this->resize_image( $image_path, $size );
            if ( ! is_wp_error( $optimized_path ) ) {
                $this->cache_set( $cache_key, $optimized_path, 'image', DAY_IN_SECONDS );
                $optimized[ $platform ] = $optimized_path;
            }
        }

        return $optimized;
    }

    /**
     * Resize image
     *
     * @param string $image_path Original image path
     * @param array  $size       Target size
     * @return string|WP_Error
     */
    private function resize_image( $image_path, $size ) {
        $image_editor = wp_get_image_editor( $image_path );
        
        if ( is_wp_error( $image_editor ) ) {
            return $image_editor;
        }

        $image_editor->resize( $size['width'], $size['height'], true );
        
        $upload_dir = wp_upload_dir();
        $filename = basename( $image_path, '.' . pathinfo( $image_path, PATHINFO_EXTENSION ) );
        $new_filename = sprintf( 
            '%s_%dx%d.%s',
            $filename,
            $size['width'],
            $size['height'],
            pathinfo( $image_path, PATHINFO_EXTENSION )
        );
        
        $new_path = $upload_dir['path'] . '/' . $new_filename;
        $saved = $image_editor->save( $new_path );
        
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        
        return $saved['path'];
    }
}