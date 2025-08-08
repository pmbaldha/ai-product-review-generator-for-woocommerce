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
        }
        
        // Generate unique hash for the log file
        $hash = substr(md5(time() . wp_rand()), 0, 20);
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
            rename($this->log_file, $archive_file);
            
            // Clean up old logs (keep only last 10 files)
            $this->cleanup_old_logs();
        }
    }
    
    private function cleanup_old_logs() {
        $log_dir = dirname($this->log_file);
        $files = glob($log_dir . '/debug-*.log');
        
        if (count($files) > 10) {
            // Sort by modification time
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest files
            $files_to_remove = array_slice($files, 0, count($files) - 10);
            foreach ($files_to_remove as $file) {
                unlink($file);
            }
        }
    }
    
    public function get_recent_logs($lines = 100) {
        if (!file_exists($this->log_file)) {
            return array();
        }
        
        $file = new SplFileObject($this->log_file, 'r');
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
        $files = glob($log_dir . '/debug-*.log');
        
        foreach ($files as $file) {
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
}