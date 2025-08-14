<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPRG_Logger {
    
    private static $instance = null;
    private $log_file;
    private $max_log_size = 5242880; // 5MB
    private $log_enabled;
    private $session_id;
    private $request_id;
    
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
        $this->log_file = $log_dir . '/debug-' . $hash . '-' . gmdate('Y-m-d') . '.log';
        
        // Generate session and request IDs for tracking
        // Use a combination of time and random values for session ID to avoid session_id() issues
        $this->session_id = substr(md5(time() . wp_rand() . get_current_user_id()), 0, 8);
        $this->request_id = substr(md5(uniqid('', true)), 0, 8);
        
        // Ensure the option exists with default value
        if (get_option('aiprg_enable_logging') === false) {
            add_option('aiprg_enable_logging', 'yes');
        }
        
        $this->log_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
        
        // Log session start
        // $this->log_session_start();
    }
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function log_session_start() {
        if (!$this->log_enabled) {
            return;
        }
        
        $separator = str_repeat('═', 100);
        $timestamp = current_time('Y-m-d H:i:s');
        
        $session_header = PHP_EOL . $separator . PHP_EOL;
        $session_header .= "║ NEW SESSION STARTED" . PHP_EOL;
        $session_header .= "║ Time: " . $timestamp . PHP_EOL;
        $session_header .= "║ Session ID: " . $this->session_id . PHP_EOL;
        $session_header .= "║ Request ID: " . $this->request_id . PHP_EOL;
        $session_header .= "║ User: " . wp_get_current_user()->user_login . " (ID: " . get_current_user_id() . ")" . PHP_EOL;
        $session_header .= "║ PHP Version: " . phpversion() . PHP_EOL;
        $session_header .= "║ WordPress Version: " . get_bloginfo('version') . PHP_EOL;
        $session_header .= "║ Plugin Version: " . (defined('AIPRG_VERSION') ? AIPRG_VERSION : 'Unknown') . PHP_EOL;
        $session_header .= $separator . PHP_EOL . PHP_EOL;
        
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Required for logging to custom file
        error_log($session_header, 3, $this->log_file);
    }
    
    public function log($message, $level = 'INFO', $context = array()) {
        // Check current setting dynamically
        $this->log_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
        
        if (!$this->log_enabled && $level !== 'ERROR' && $level !== 'CRITICAL') {
            return;
        }
        
        // Rotate log if it's too large
        $this->rotate_log();
        
        $timestamp = current_time('Y-m-d H:i:s');
        $microseconds = substr(microtime(), 2, 6);
        
        // Get caller information for debugging
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Required for error tracking in logs
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]) ? $backtrace[1] : array();
        $file = isset($caller['file']) ? basename($caller['file']) : 'Unknown';
        $line = isset($caller['line']) ? $caller['line'] : '0';
        $function = isset($caller['function']) ? $caller['function'] : 'Unknown';
        
        // Format level with visual indicators
        $level_formatted = $this->format_level($level);
        $level_separator = $this->get_level_separator($level);
        
        // Build the log entry with improved formatting
        $log_entry = $level_separator . PHP_EOL;
        
        // Header line with timestamp and level
        $log_entry .= sprintf(
            "┌─[%s.%s] %s [Session: %s] [Request: %s]" . PHP_EOL,
            $timestamp,
            $microseconds,
            $level_formatted,
            $this->session_id,
            $this->request_id
        );
        
        // Location information
        $log_entry .= sprintf(
            "├─ 📍 Location: %s:%s in %s()" . PHP_EOL,
            $file,
            $line,
            $function
        );
        
        // Main message
        $log_entry .= "├─ 💬 Message: " . $this->format_message($message, $level) . PHP_EOL;
        
        // Context/Additional Data
        if (!empty($context)) {
            $log_entry .= "├─ 📊 Context:" . PHP_EOL;
            $log_entry .= $this->format_context($context, '│  ');
        }
        
        // Memory and performance info for debugging
        if ($level === 'ERROR' || $level === 'CRITICAL' || $level === 'DEBUG') {
            $log_entry .= "├─ 🔧 Debug Info:" . PHP_EOL;
            $log_entry .= "│  ├─ Memory Usage: " . $this->format_bytes(memory_get_usage(true)) . " / " . $this->format_bytes(memory_get_peak_usage(true)) . " (peak)" . PHP_EOL;
            $request_time = isset($_SERVER['REQUEST_TIME_FLOAT']) ? floatval(wp_unslash($_SERVER['REQUEST_TIME_FLOAT'])) : 0;
            if ($request_time > 0) {
                $log_entry .= "│  └─ Execution Time: " . (microtime(true) - $request_time) . " seconds" . PHP_EOL;
            }
        }
        
        // Footer
        $log_entry .= "└" . str_repeat('─', 90) . PHP_EOL . PHP_EOL;
        
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Required for logging to custom file
        error_log($log_entry, 3, $this->log_file);
    }
    
    private function format_level($level) {
        $icons = array(
            'DEBUG'        => '🔍 DEBUG',
            'INFO'         => 'ℹ️  INFO',
            'SUCCESS'      => '✅ SUCCESS',
            'WARNING'      => '⚠️  WARNING',
            'ERROR'        => '❌ ERROR',
            'CRITICAL'     => '🔥 CRITICAL',
            'API_REQUEST'  => '📤 API REQUEST',
            'API_RESPONSE' => '📥 API RESPONSE',
            'REVIEW_GEN'   => '📝 REVIEW GEN',
        );
        
        return isset($icons[$level]) ? $icons[$level] : '📌 ' . $level;
    }
    
    private function get_level_separator($level) {
        switch($level) {
            case 'ERROR':
            case 'CRITICAL':
                return str_repeat('━', 100);
            case 'WARNING':
                return str_repeat('─', 100);
            case 'SUCCESS':
                return str_repeat('═', 100);
            default:
                return str_repeat('·', 100);
        }
    }
    
    private function format_message($message, $level) {
        // Add color codes for terminal output (won't affect file logs)
        $colors = array(
            'ERROR'    => "\033[31m", // Red
            'CRITICAL' => "\033[91m", // Bright Red
            'WARNING'  => "\033[33m", // Yellow
            'SUCCESS'  => "\033[32m", // Green
            'INFO'     => "\033[36m", // Cyan
            'DEBUG'    => "\033[90m", // Gray
        );
        
        $reset = "\033[0m";
        
        if (isset($colors[$level])) {
            return $colors[$level] . $message . $reset;
        }
        
        return $message;
    }
    
    private function format_context($context, $prefix = '') {
        $output = '';
        
        if (is_array($context) || is_object($context)) {
            $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $lines = explode("\n", $json);
            
            foreach ($lines as $index => $line) {
                if ($index === 0) {
                    $output .= $prefix . "├─ " . $line . PHP_EOL;
                } elseif ($index === count($lines) - 1) {
                    $output .= $prefix . "└─ " . $line . PHP_EOL;
                } else {
                    $output .= $prefix . "│  " . $line . PHP_EOL;
                }
            }
        } else {
            $output .= $prefix . "└─ " . (string)$context . PHP_EOL;
        }
        
        return $output;
    }
    
    private function format_bytes($bytes) {
        $units = array('B', 'KB', 'MB', 'GB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    public function log_api_request($endpoint, $request_data, $headers = array()) {
        // Check current setting dynamically
        $this->log_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
        
        if (!$this->log_enabled) {
            return;
        }
        
        $context = array(
            'endpoint' => $endpoint,
            'method' => isset($headers['method']) ? $headers['method'] : 'POST',
            'headers' => $this->sanitize_headers($headers),
            'request_body' => $this->format_api_data($request_data),
            'timestamp' => microtime(true)
        );
        
        $this->log('OpenAI API Request to ' . $endpoint, 'API_REQUEST', $context);
    }
    
    public function log_api_response($response, $status_code = null) {
        // Check current setting dynamically
        $this->log_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
        
        if (!$this->log_enabled) {
            return;
        }
        
        $context = array(
            'status_code' => $status_code,
            'status_text' => $this->get_http_status_text($status_code),
            'response' => $this->format_api_data($response),
            'response_time' => isset($this->last_request_time) ? (microtime(true) - $this->last_request_time) . ' seconds' : 'N/A'
        );
        
        $level = ($status_code >= 200 && $status_code < 300) ? 'API_RESPONSE' : 'ERROR';
        $this->log('OpenAI API Response', $level, $context);
    }
    
    private function format_api_data($data) {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->truncate_response($decoded);
            }
            return $this->truncate_response($data);
        }
        return $this->truncate_response($data);
    }
    
    private function get_http_status_text($code) {
        $statuses = array(
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        );
        
        return isset($statuses[$code]) ? $statuses[$code] : 'Unknown';
    }
    
    public function log_error($error_message, $error_data = array()) {
        // Always log errors, even if logging is disabled
        $context = array(
            'error_data' => $error_data,
            'stack_trace' => $this->get_formatted_backtrace(),
            'system_info' => array(
                'php_version' => phpversion(),
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => defined('AIPRG_VERSION') ? AIPRG_VERSION : 'Unknown',
                'memory_usage' => $this->format_bytes(memory_get_usage(true)),
                'memory_limit' => ini_get('memory_limit')
            )
        );
        
        $this->log($error_message, 'ERROR', $context);
    }
    
    private function get_formatted_backtrace() {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Required for error tracking in logs
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $formatted = array();
        
        foreach ($backtrace as $index => $trace) {
            if ($index === 0) continue; // Skip the current function
            
            $formatted[] = sprintf(
                "#%d %s:%s - %s%s%s()",
                $index,
                isset($trace['file']) ? basename($trace['file']) : 'Unknown',
                isset($trace['line']) ? $trace['line'] : '0',
                isset($trace['class']) ? $trace['class'] : '',
                isset($trace['type']) ? $trace['type'] : '',
                isset($trace['function']) ? $trace['function'] : 'Unknown'
            );
        }
        
        return $formatted;
    }
    
    public function log_review_generation($product_id, $product_name, $status, $details = array()) {
        // Check current setting dynamically
        $this->log_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
        
        if (!$this->log_enabled) {
            return;
        }
        
        $context = array(
            'product' => array(
                'id' => $product_id,
                'name' => $product_name,
                'permalink' => get_permalink($product_id)
            ),
            'generation' => array(
                'status' => $status,
                'timestamp' => current_time('Y-m-d H:i:s'),
                'details' => $details
            ),
            'statistics' => array(
                'total_reviews' => isset($details['total_reviews']) ? $details['total_reviews'] : 0,
                'successful' => isset($details['successful']) ? $details['successful'] : 0,
                'failed' => isset($details['failed']) ? $details['failed'] : 0
            )
        );
        
        $level = ($status === 'success') ? 'SUCCESS' : (($status === 'failed') ? 'ERROR' : 'INFO');
        $this->log('Review Generation for Product: ' . $product_name, 'REVIEW_GEN', $context);
    }
    
    public function log_summary($title, $data) {
        if (!$this->log_enabled) {
            return;
        }
        
        $separator = str_repeat('▬', 100);
        $summary = PHP_EOL . $separator . PHP_EOL;
        $summary .= "╔══════════════════════════════════════════════════════════════════════════════════════════════╗" . PHP_EOL;
        $summary .= "║ " . str_pad($title, 94) . "║" . PHP_EOL;
        $summary .= "╠══════════════════════════════════════════════════════════════════════════════════════════════╣" . PHP_EOL;
        
        foreach ($data as $key => $value) {
            $summary .= "║ " . str_pad($key . ': ' . $value, 94) . "║" . PHP_EOL;
        }
        
        $summary .= "╚══════════════════════════════════════════════════════════════════════════════════════════════╝" . PHP_EOL;
        $summary .= $separator . PHP_EOL . PHP_EOL;
        
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Required for logging to custom file
        error_log($summary, 3, $this->log_file);
    }
    
    private function sanitize_headers($headers) {
        $sanitized = array();
        foreach ($headers as $key => $value) {
            if (stripos($key, 'authorization') !== false || stripos($key, 'api-key') !== false) {
                // Mask sensitive headers
                $sanitized[$key] = '***MASKED***';
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
    
    private function truncate_response($response) {
        $max_length = 2000; // Increased for better debugging
        
        if (is_string($response) && strlen($response) > $max_length) {
            return substr($response, 0, $max_length) . "\n... [TRUNCATED - Original length: " . strlen($response) . " chars]";
        }
        
        if (is_array($response) || is_object($response)) {
            $json = json_encode($response, JSON_PRETTY_PRINT);
            if (strlen($json) > $max_length) {
                return substr($json, 0, $max_length) . "\n... [TRUNCATED - Original length: " . strlen($json) . " chars]";
            }
            return $response;
        }
        
        return $response;
    }
    
    private function rotate_log() {
        if (file_exists($this->log_file) && filesize($this->log_file) > $this->max_log_size) {
            $archive_file = str_replace('.log', '-' . time() . '.log', $this->log_file);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Direct file operation needed for log rotation
            rename($this->log_file, $archive_file);
            
            // Create rotation summary
            $this->log_summary('LOG ROTATION', array(
                'Previous Log' => basename($archive_file),
                'Size' => $this->format_bytes($this->max_log_size),
                'Rotated At' => current_time('Y-m-d H:i:s')
            ));
            
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
            $today = gmdate('Y-m-d');
            
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
        // Log before clearing
        $this->log_summary('LOGS CLEARED', array(
            'Cleared By' => wp_get_current_user()->user_login,
            'Time' => current_time('Y-m-d H:i:s'),
            'Total Size Before Clear' => $this->format_bytes($this->get_total_log_size())
        ));
        
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
        $today = gmdate('Y-m-d');
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
    
    /**
     * Get log statistics for debugging
     * 
     * @return array Statistics about log files
     */
    public function get_log_statistics() {
        $log_dir = dirname($this->log_file);
        $stats = array(
            'total_files' => 0,
            'total_size' => 0,
            'oldest_file' => null,
            'newest_file' => null,
            'errors_today' => 0,
            'warnings_today' => 0,
            'api_calls_today' => 0
        );
        
        // Get all log files
        $log_files = array_merge(
            glob($log_dir . '/debug-*.log'),
            glob($log_dir . '/aiprg-debug-*.log')
        );
        
        $stats['total_files'] = count($log_files);
        
        if (!empty($log_files)) {
            // Sort by modification time
            usort($log_files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $stats['oldest_file'] = basename($log_files[0]);
            $stats['newest_file'] = basename($log_files[count($log_files) - 1]);
            
            // Calculate total size and analyze today's logs
            foreach ($log_files as $log_file) {
                $stats['total_size'] += filesize($log_file);
                
                // Analyze today's logs
                if (strpos($log_file, gmdate('Y-m-d')) !== false) {
                    $content = file_get_contents($log_file);
                    $stats['errors_today'] += substr_count($content, '❌ ERROR');
                    $stats['warnings_today'] += substr_count($content, '⚠️  WARNING');
                    $stats['api_calls_today'] += substr_count($content, '📤 API REQUEST');
                }
            }
        }
        
        $stats['total_size_formatted'] = $this->format_bytes($stats['total_size']);
        
        return $stats;
    }
}