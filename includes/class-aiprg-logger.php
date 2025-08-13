<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPRG_Logger {
    
    private static $instance = null;
    private $log_file;
    private $max_log_size = 5242880; // 5MB
    private $log_enabled;
    
    private function __construct() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/aiprg-logs';
        
        // Create log directory if it doesn't exist
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            
            // Add .htaccess to protect log files
            $htaccess_file = $log_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                file_put_contents($htaccess_file, 'Deny from all');
            }
            
            // Add index.php for extra security
            $index_file = $log_dir . '/index.php';
            if (!file_exists($index_file)) {
                file_put_contents($index_file, '<?php // Silence is golden');
            }
        }
        
        // Generate unique hash for the log file
        $hash = substr(md5(time() . wp_rand()), 0, 20);
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Using date() intentionally for local timezone
        $this->log_file = $log_dir . '/debug-' . $hash . '-' . date('Y-m-d') . '.log';
        
        // Ensure the option exists with default value
        if (get_option('aiprg_enable_logging') === false) {
            add_option('aiprg_enable_logging', 'yes');
        }
        
        $this->log_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
    }
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function log($message, $level = 'INFO', $context = array()) {
        // Check current setting dynamically
        $this->log_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
        
        if (!$this->log_enabled) {
            return;
        }
        
        // Rotate log if it's too large
        $this->rotate_log();
        
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf(
            "[%s] [%s] %s",
            $timestamp,
            $level,
            $message
        );
        
        if (!empty($context)) {
            $log_entry .= ' | Context: ' . json_encode($context, JSON_PRETTY_PRINT);
        }
        
        $log_entry .= PHP_EOL . str_repeat('-', 80) . PHP_EOL;
        
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Required for logging to custom file
        error_log($log_entry, 3, $this->log_file);
    }
    
    public function log_api_request($endpoint, $request_data, $headers = array()) {
        // Check current setting dynamically
        $this->log_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
        
        if (!$this->log_enabled) {
            return;
        }
        
        $context = array(
            'endpoint' => $endpoint,
            'request_body' => $request_data,
            'headers' => $this->sanitize_headers($headers)
        );
        
        $this->log('OpenAI API Request', 'API_REQUEST', $context);
    }
    
    public function log_api_response($response, $status_code = null) {
        // Check current setting dynamically
        $this->log_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
        
        if (!$this->log_enabled) {
            return;
        }
        
        $context = array(
            'status_code' => $status_code,
            'response' => $this->truncate_response($response)
        );
        
        $this->log('OpenAI API Response', 'API_RESPONSE', $context);
    }
    
    public function log_error($error_message, $error_data = array()) {
        // Always log errors, even if logging is disabled
        $context = array(
            'error_data' => $error_data,
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Required for error context logging
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        );
        
        $this->log($error_message, 'ERROR', $context);
    }
    
    public function log_review_generation($product_id, $product_name, $status, $details = array()) {
        // Check current setting dynamically
        $this->log_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
        
        if (!$this->log_enabled) {
            return;
        }
        
        $context = array(
            'product_id' => $product_id,
            'product_name' => $product_name,
            'status' => $status,
            'details' => $details
        );
        
        $this->log('Review Generation', 'REVIEW_GEN', $context);
    }
    
    private function sanitize_headers($headers) {
        $sanitized = array();
        foreach ($headers as $key => $value) {
            if (stripos($key, 'authorization') !== false) {
                // Mask API key
                $sanitized[$key] = 'Bearer ***MASKED***';
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
    
    private function truncate_response($response) {
        $max_length = 1000;
        
        if (is_string($response) && strlen($response) > $max_length) {
            return substr($response, 0, $max_length) . '... [TRUNCATED]';
        }
        
        if (is_array($response) || is_object($response)) {
            $json = json_encode($response);
            if (strlen($json) > $max_length) {
                return json_decode(substr($json, 0, $max_length) . '"}', true);
            }
        }
        
        return $response;
    }
    
    private function rotate_log() {
        if (file_exists($this->log_file) && filesize($this->log_file) > $this->max_log_size) {
            $archive_file = str_replace('.log', '-' . time() . '.log', $this->log_file);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Direct file operation needed for log rotation
            rename($this->log_file, $archive_file);
            
            // Clean up old logs (keep only last 10 files)
            $this->cleanup_old_logs();
        }
    }
    
    private function cleanup_old_logs() {
        $log_dir = dirname($this->log_file);
        // Match both old format (debug-*) and new format (aiprg-debug-*)
        $files = array_merge(
            glob($log_dir . '/debug-*.log'),
            glob($log_dir . '/aiprg-debug-*.log')
        );
        
        if (count($files) > 10) {
            // Sort by modification time
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest files
            $files_to_remove = array_slice($files, 0, count($files) - 10);
            foreach ($files_to_remove as $file) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct file operation needed for log cleanup
                unlink($file);
            }
        }
    }
    
    public function get_recent_logs($lines = 100) {
        // First try the current log file
        if (!file_exists($this->log_file)) {
            // If current log file doesn't exist, try to find today's logs from any file
            $log_dir = dirname($this->log_file);
            // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Using date() intentionally for local timezone
            $today = date('Y-m-d');
            
            // Look for any log file from today (old or new format)
            $possible_files = array_merge(
                glob($log_dir . '/debug-*' . $today . '.log'),
                glob($log_dir . '/aiprg-debug-' . $today . '.log')
            );
            
            if (empty($possible_files)) {
                return array();
            }
            
            // Use the most recent file
            usort($possible_files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            $log_file_to_read = $possible_files[0];
        } else {
            $log_file_to_read = $this->log_file;
        }
        
        if (!file_exists($log_file_to_read)) {
            return array();
        }
        
        $file = new SplFileObject($log_file_to_read, 'r');
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();
        
        $start_line = max(0, $total_lines - $lines);
        $logs = array();
        
        $file->seek($start_line);
        while (!$file->eof()) {
            $logs[] = $file->current();
            $file->next();
        }
        
        return $logs;
    }
    
    public function clear_logs() {
        $log_dir = dirname($this->log_file);
        // Clear both old format (debug-*) and new format (aiprg-debug-*)
        $files = array_merge(
            glob($log_dir . '/debug-*.log'),
            glob($log_dir . '/aiprg-debug-*.log')
        );
        
        foreach ($files as $file) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct file operation needed for log cleanup
            unlink($file);
        }
        
        return true;
    }
    
    public function get_log_file_path() {
        return $this->log_file;
    }
    
    public function get_log_size() {
        if (file_exists($this->log_file)) {
            return filesize($this->log_file);
        }
        return 0;
    }
    
    /**
     * Get all logs from today, combining multiple log files if they exist
     * 
     * @param int $lines Maximum number of lines to return
     * @return array Combined log entries
     */
    public function get_all_recent_logs($lines = 500) {
        $log_dir = dirname($this->log_file);
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Using date() intentionally for local timezone
        $today = date('Y-m-d');
        $all_logs = array();
        
        // Find all log files from today (both old and new format)
        $log_files = array_merge(
            glob($log_dir . '/debug-*' . $today . '.log'),
            glob($log_dir . '/aiprg-debug-' . $today . '.log')
        );
        
        // Sort files by modification time (newest first)
        usort($log_files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // Read logs from each file
        foreach ($log_files as $log_file) {
            if (file_exists($log_file)) {
                $file_content = file_get_contents($log_file);
                if (!empty($file_content)) {
                    $file_lines = explode(PHP_EOL, $file_content);
                    $all_logs = array_merge($all_logs, $file_lines);
                }
            }
        }
        
        // Return the most recent lines
        if (count($all_logs) > $lines) {
            return array_slice($all_logs, -$lines);
        }
        
        return $all_logs;
    }
    
    /**
     * Get total size of all log files
     * 
     * @return int Total size in bytes
     */
    public function get_total_log_size() {
        $log_dir = dirname($this->log_file);
        $total_size = 0;
        
        // Get all log files
        $log_files = array_merge(
            glob($log_dir . '/debug-*.log'),
            glob($log_dir . '/aiprg-debug-*.log')
        );
        
        foreach ($log_files as $log_file) {
            if (file_exists($log_file)) {
                $total_size += filesize($log_file);
            }
        }
        
        return $total_size;
    }
}