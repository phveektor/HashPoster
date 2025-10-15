<?php
/**
 * HashPoster Security Handler
 *
 * Provides enterprise-grade security functionality including
 * input validation, CSRF protection, capability checks, and encryption.
 *
 * @package HashPoster
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Security Handler Class
 */
class HashPoster_Security {

    /**
     * Security instance
     *
     * @var HashPoster_Security
     */
    private static $instance = null;

    /**
     * Logger instance
     *
     * @var HashPoster_Logger
     */
    private $logger;

    /**
     * Encryption key
     *
     * @var string
     */
    private $encryption_key;

    /**
     * Rate limiting cache
     *
     * @var array
     */
    private $rate_limit_cache = array();

    /**
     * Get security instance
     *
     * @return HashPoster_Security
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
        $this->encryption_key = $this->get_or_create_encryption_key();
        $this->init_hooks();
    }

    /**
     * Initialize security hooks
     */
    private function init_hooks() {
        add_action( 'wp_ajax_nopriv_hashposter_public_endpoint', array( $this, 'block_unauthorized_ajax' ) );
        add_filter( 'wp_die_handler', array( $this, 'handle_security_errors' ) );
        add_action( 'init', array( $this, 'security_headers' ) );
    }

    /**
     * Validate and sanitize input data
     *
     * @param array  $data     Input data
     * @param array  $schema   Validation schema
     * @param string $context  Context for logging
     * @return array|WP_Error
     */
    public function validate_input( $data, $schema, $context = '' ) {
        $sanitized = array();
        $errors = array();

        foreach ( $schema as $field => $rules ) {
            $value = $data[ $field ] ?? null;

            // Check required fields
            if ( ! empty( $rules['required'] ) && ( is_null( $value ) || '' === $value ) ) {
                $errors[] = sprintf( 'Field %s is required', $field );
                continue;
            }

            // Skip validation if field is not required and empty
            if ( is_null( $value ) || '' === $value ) {
                continue;
            }

            // Type validation
            if ( isset( $rules['type'] ) ) {
                $validated_value = $this->validate_type( $value, $rules['type'], $field );
                if ( is_wp_error( $validated_value ) ) {
                    $errors[] = $validated_value->get_error_message();
                    continue;
                }
                $value = $validated_value;
            }

            // Length validation
            if ( isset( $rules['min_length'] ) && strlen( $value ) < $rules['min_length'] ) {
                $errors[] = sprintf( 'Field %s must be at least %d characters', $field, $rules['min_length'] );
                continue;
            }

            if ( isset( $rules['max_length'] ) && strlen( $value ) > $rules['max_length'] ) {
                $errors[] = sprintf( 'Field %s must not exceed %d characters', $field, $rules['max_length'] );
                continue;
            }

            // Pattern validation
            if ( isset( $rules['pattern'] ) && ! preg_match( $rules['pattern'], $value ) ) {
                $errors[] = sprintf( 'Field %s has invalid format', $field );
                continue;
            }

            // Custom validation
            if ( isset( $rules['callback'] ) && is_callable( $rules['callback'] ) ) {
                $custom_result = call_user_func( $rules['callback'], $value );
                if ( is_wp_error( $custom_result ) ) {
                    $errors[] = $custom_result->get_error_message();
                    continue;
                }
                $value = $custom_result;
            }

            $sanitized[ $field ] = $value;
        }

        if ( ! empty( $errors ) ) {
            $this->logger->warning( 'Input validation failed', array(
                'context' => $context,
                'errors'  => $errors,
                'data'    => $this->sanitize_log_data( $data ),
            ) );
            return new WP_Error( 'validation_failed', implode( ', ', $errors ) );
        }

        $this->logger->debug( 'Input validation successful', array(
            'context' => $context,
            'fields'  => array_keys( $sanitized ),
        ) );

        return $sanitized;
    }

    /**
     * Validate data type
     *
     * @param mixed  $value Input value
     * @param string $type  Expected type
     * @param string $field Field name
     * @return mixed|WP_Error
     */
    private function validate_type( $value, $type, $field ) {
        switch ( $type ) {
            case 'string':
                return sanitize_text_field( $value );

            case 'textarea':
                return sanitize_textarea_field( $value );

            case 'email':
                $email = sanitize_email( $value );
                if ( ! is_email( $email ) ) {
                    return new WP_Error( 'invalid_email', sprintf( 'Field %s must be a valid email', $field ) );
                }
                return $email;

            case 'url':
                $url = esc_url_raw( $value );
                if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                    return new WP_Error( 'invalid_url', sprintf( 'Field %s must be a valid URL', $field ) );
                }
                return $url;

            case 'int':
                if ( ! is_numeric( $value ) ) {
                    return new WP_Error( 'invalid_int', sprintf( 'Field %s must be a number', $field ) );
                }
                return intval( $value );

            case 'float':
                if ( ! is_numeric( $value ) ) {
                    return new WP_Error( 'invalid_float', sprintf( 'Field %s must be a number', $field ) );
                }
                return floatval( $value );

            case 'bool':
                return (bool) $value;

            case 'array':
                if ( ! is_array( $value ) ) {
                    return new WP_Error( 'invalid_array', sprintf( 'Field %s must be an array', $field ) );
                }
                return array_map( 'sanitize_text_field', $value );

            case 'json':
                $decoded = json_decode( $value, true );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    return new WP_Error( 'invalid_json', sprintf( 'Field %s must be valid JSON', $field ) );
                }
                return $decoded;

            default:
                return sanitize_text_field( $value );
        }
    }

    /**
     * Check user capabilities with logging
     *
     * @param string $capability Required capability
     * @param string $context    Context for logging
     * @param int    $user_id    User ID (optional)
     * @return bool
     */
    public function check_capability( $capability, $context = '', $user_id = null ) {
        $user_id = $user_id ?? get_current_user_id();
        $can_access = user_can( $user_id, $capability );

        if ( ! $can_access ) {
            $this->logger->warning( 'Capability check failed', array(
                'context'    => $context,
                'capability' => $capability,
                'user_id'    => $user_id,
                'ip'         => $this->get_user_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            ) );
        }

        return $can_access;
    }

    /**
     * Verify nonce with enhanced security
     *
     * @param string $nonce   Nonce value
     * @param string $action  Nonce action
     * @param string $context Context for logging
     * @return bool
     */
    public function verify_nonce( $nonce, $action, $context = '' ) {
        $verified = wp_verify_nonce( $nonce, $action );

        if ( ! $verified ) {
            $this->logger->warning( 'Nonce verification failed', array(
                'context' => $context,
                'action'  => $action,
                'ip'      => $this->get_user_ip(),
                'referer' => wp_get_referer(),
            ) );

            // Rate limit failed nonce attempts
            $this->rate_limit_failed_nonces();
        }

        return $verified;
    }

    /**
     * Rate limit failed nonce attempts
     */
    private function rate_limit_failed_nonces() {
        $ip = $this->get_user_ip();
        $key = 'failed_nonces_' . md5( $ip );
        
        $attempts = get_transient( $key ) ?: 0;
        $attempts++;
        
        set_transient( $key, $attempts, HOUR_IN_SECONDS );
        
        if ( $attempts > 5 ) {
            $this->logger->alert( 'Multiple failed nonce attempts detected', array(
                'ip'       => $ip,
                'attempts' => $attempts,
            ) );
            
            // Block IP for 1 hour
            set_transient( 'blocked_ip_' . md5( $ip ), true, HOUR_IN_SECONDS );
        }
    }

    /**
     * Check if IP is blocked
     *
     * @param string $ip IP address
     * @return bool
     */
    public function is_ip_blocked( $ip = null ) {
        $ip = $ip ?? $this->get_user_ip();
        return (bool) get_transient( 'blocked_ip_' . md5( $ip ) );
    }

    /**
     * Encrypt sensitive data
     *
     * @param string $data Data to encrypt
     * @return string
     */
    public function encrypt( $data ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return base64_encode( $data ); // Fallback
        }

        $iv = openssl_random_pseudo_bytes( 16 );
        $encrypted = openssl_encrypt( $data, 'AES-256-CBC', $this->encryption_key, 0, $iv );
        
        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt sensitive data
     *
     * @param string $encrypted_data Encrypted data
     * @return string|false
     */
    public function decrypt( $encrypted_data ) {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return base64_decode( $encrypted_data ); // Fallback
        }

        $data = base64_decode( $encrypted_data );
        $iv = substr( $data, 0, 16 );
        $encrypted = substr( $data, 16 );
        
        return openssl_decrypt( $encrypted, 'AES-256-CBC', $this->encryption_key, 0, $iv );
    }

    /**
     * Get or create encryption key
     *
     * @return string
     */
    private function get_or_create_encryption_key() {
        $key = get_option( 'hashposter_encryption_key' );
        
        if ( ! $key ) {
            if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
                $key = base64_encode( openssl_random_pseudo_bytes( 32 ) );
            } else {
                $key = wp_generate_password( 32, false );
            }
            
            update_option( 'hashposter_encryption_key', $key, false );
            
            $this->logger->info( 'New encryption key generated' );
        }
        
        return $key;
    }

    /**
     * Rate limiting check
     *
     * @param string $action   Action to rate limit
     * @param int    $limit    Request limit
     * @param int    $window   Time window in seconds
     * @param string $context  Context for logging
     * @return bool
     */
    public function rate_limit( $action, $limit = 60, $window = 3600, $context = '' ) {
        $ip = $this->get_user_ip();
        $user_id = get_current_user_id();
        $key = sprintf( 'rate_limit_%s_%s_%d', $action, md5( $ip ), $user_id );
        
        $requests = get_transient( $key ) ?: 0;
        
        if ( $requests >= $limit ) {
            $this->logger->warning( 'Rate limit exceeded', array(
                'context'  => $context,
                'action'   => $action,
                'ip'       => $ip,
                'user_id'  => $user_id,
                'requests' => $requests,
                'limit'    => $limit,
            ) );
            
            return false;
        }
        
        $requests++;
        set_transient( $key, $requests, $window );
        
        return true;
    }

    /**
     * Get user IP address
     *
     * @return string
     */
    private function get_user_ip() {
        $ip_keys = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
        
        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( $_SERVER[ $key ] );
                // Handle comma-separated IPs
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    return $ip;
                }
            }
        }
        
        return 'unknown';
    }

    /**
     * Add security headers
     */
    public function security_headers() {
        if ( is_admin() && strpos( $_SERVER['REQUEST_URI'] ?? '', 'hashposter' ) !== false ) {
            header( 'X-Content-Type-Options: nosniff' );
            header( 'X-Frame-Options: SAMEORIGIN' );
            header( 'X-XSS-Protection: 1; mode=block' );
            header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        }
    }

    /**
     * Sanitize data for logging
     *
     * @param array $data Data to sanitize
     * @return array
     */
    private function sanitize_log_data( $data ) {
        $sensitive_keys = array( 'password', 'api_key', 'token', 'secret', 'auth' );
        $sanitized = array();
        
        foreach ( $data as $key => $value ) {
            $key_lower = strtolower( $key );
            $is_sensitive = false;
            
            foreach ( $sensitive_keys as $sensitive_key ) {
                if ( strpos( $key_lower, $sensitive_key ) !== false ) {
                    $is_sensitive = true;
                    break;
                }
            }
            
            if ( $is_sensitive ) {
                $sanitized[ $key ] = '[REDACTED]';
            } else {
                $sanitized[ $key ] = is_string( $value ) ? substr( $value, 0, 100 ) : $value;
            }
        }
        
        return $sanitized;
    }

    /**
     * Block unauthorized AJAX requests
     */
    public function block_unauthorized_ajax() {
        $this->logger->warning( 'Unauthorized AJAX request blocked', array(
            'action' => sanitize_text_field( $_POST['action'] ?? 'unknown' ),
            'ip'     => $this->get_user_ip(),
        ) );
        
        wp_die( 'Unauthorized', 'Unauthorized', array( 'response' => 401 ) );
    }

    /**
     * Handle security errors
     *
     * @param callable $handler Original handler
     * @return callable
     */
    public function handle_security_errors( $handler ) {
        return function( $message, $title, $args ) use ( $handler ) {
            if ( is_string( $message ) && strpos( $message, 'hashposter' ) !== false ) {
                $this->logger->error( 'Security error occurred', array(
                    'message' => $message,
                    'title'   => $title,
                    'args'    => $args,
                ) );
            }
            return call_user_func( $handler, $message, $title, $args );
        };
    }
}