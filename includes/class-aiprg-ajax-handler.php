<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPRG_Ajax_Handler {
    
    /**
     * The single instance of the class
     * 
     * @var AIPRG_Ajax_Handler
     */
    private static $instance = null;
    
    private $review_generator;
    private $action_scheduler;
    private $logger;
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        add_action('wp_ajax_aiprg_generate_reviews', array($this, 'handle_generate_reviews'));
        add_action('wp_ajax_aiprg_generate_reviews_scheduled', array($this, 'handle_generate_reviews_scheduled'));
        add_action('wp_ajax_aiprg_validate_api_key', array($this, 'handle_validate_api_key'));
        add_action('wp_ajax_aiprg_get_generation_progress', array($this, 'handle_get_progress'));
        add_action('wp_ajax_aiprg_get_batch_status', array($this, 'handle_get_batch_status'));
        add_action('wp_ajax_aiprg_cancel_batch', array($this, 'handle_cancel_batch'));
        add_action('wp_ajax_aiprg_auto_save_setting', array($this, 'handle_auto_save_setting'));
        add_action('wp_ajax_aiprg_search_products', array($this, 'handle_search_products'));
        add_action('wp_ajax_aiprg_search_categories', array($this, 'handle_search_categories'));
        add_action('wp_ajax_aiprg_delete_review', array($this, 'handle_delete_review'));
        add_action('wp_ajax_aiprg_delete_all_reviews', array($this, 'handle_delete_all_reviews'));
        
        // Also hook into WooCommerce's product search if it's not already available
        if (!has_action('wp_ajax_woocommerce_json_search_products_and_variations')) {
            add_action('wp_ajax_woocommerce_json_search_products_and_variations', array($this, 'handle_search_products'));
        }
        
        $this->review_generator = AIPRG_Review_Generator::instance();
        $this->action_scheduler = AIPRG_Action_Scheduler::instance();
        $this->logger = AIPRG_Logger::instance();
    }
    
    /**
     * Get the singleton instance of the class
     * 
     * @return AIPRG_Ajax_Handler
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Prevent cloning of the instance
     */
    private function __clone() {
        _doing_it_wrong(__FUNCTION__, esc_html__('Cloning is forbidden.', 'ai-product-review-generator-for-woocommerce'), '1.0.0');
    }
    
    /**
     * Prevent unserializing of the instance
     */
    public function __wakeup() {
        _doing_it_wrong(__FUNCTION__, esc_html__('Unserializing is forbidden.', 'ai-product-review-generator-for-woocommerce'), '1.0.0');
    }
    
    public function handle_generate_reviews() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
        }
        
        check_ajax_referer('aiprg_generate_reviews', 'nonce');
        
        $product_ids = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : array();
        
        if (empty($product_ids)) {
            $product_ids = $this->review_generator->get_selected_products();
        }
        
        if (empty($product_ids)) {
            wp_send_json_error(array(
                'message' => esc_html__('No products selected for review generation.', 'ai-product-review-generator-for-woocommerce')
            ));
        }
        
        set_transient('aiprg_generation_in_progress', true, HOUR_IN_SECONDS);
        set_transient('aiprg_generation_total', count($product_ids), HOUR_IN_SECONDS);
        set_transient('aiprg_generation_current', 0, HOUR_IN_SECONDS);
        
        $results = $this->review_generator->generate_reviews_for_products($product_ids);
        
        delete_transient('aiprg_generation_in_progress');
        delete_transient('aiprg_generation_total');
        delete_transient('aiprg_generation_current');
        
        if (is_wp_error($results)) {
            wp_send_json_error(array(
                'message' => $results->get_error_message()
            ));
        }
        
        $message = sprintf(
            /* translators: %1$d: number of successful reviews, %2$d: number of failed reviews */
			esc_html__('Review generation completed! Successfully generated %1$d reviews, %2$d failed.', 'ai-product-review-generator-for-woocommerce'),
            $results['success'],
            $results['failed']
        );
        
        wp_send_json_success(array(
            'message' => $message,
            'results' => $results
        ));
    }
    
    public function handle_validate_api_key() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
        }
        
        check_ajax_referer('aiprg_validate_api_key', 'nonce');
        
        $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        
        // Initialize logger
        $logger = AIPRG_Logger::instance();
        
        // Log validation attempt
        $logger->log('API Key validation requested via AJAX', 'INFO');
        
        if (empty($api_key)) {
            $logger->log('API Key validation failed: Empty API key provided', 'ERROR');
            wp_send_json_error(array(
                'message' => __('API key is required.', 'ai-product-review-generator-for-woocommerce')
            ));
        }
        
        // Log API key details (masked for security)
        $masked_key = substr($api_key, 0, 7) . '...' . substr($api_key, -4);
        $logger->log("Validating API key: {$masked_key}", 'INFO');
        
        update_option('aiprg_openai_api_key', $api_key, false);
        $logger->log('API key saved to database', 'INFO');
        
        $openai = AIPRG_OpenAI::instance();
        $is_valid = $openai->validate_api_key();
        
        if ($is_valid) {
            $logger->log("API key validation successful for key: {$masked_key}", 'SUCCESS');
            wp_send_json_success(array(
                'message' => esc_html__('API key is valid and has been saved.', 'ai-product-review-generator-for-woocommerce')
            ));
        } else {
            $logger->log("API key validation failed for key: {$masked_key}", 'ERROR');
            wp_send_json_error(array(
                'message' => esc_html__('Invalid API key. Please check your key and try again.', 'ai-product-review-generator-for-woocommerce')
            ));
        }
    }
    
    public function handle_get_progress() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
        }
        
        $in_progress = get_transient('aiprg_generation_in_progress');
        
        if (!$in_progress) {
            wp_send_json_success(array(
                'in_progress' => false
            ));
        }
        
        $total = get_transient('aiprg_generation_total');
        $current = get_transient('aiprg_generation_current');
        
        $percentage = $total > 0 ? round(($current / $total) * 100) : 0;
        
        wp_send_json_success(array(
            'in_progress' => true,
            'total' => $total,
            'current' => $current,
            'percentage' => $percentage
        ));
    }
    
    public function handle_generate_reviews_scheduled() {
        // Increase memory limit and execution time for large batches
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for processing large batches
        @ini_set('memory_limit', '256M');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for long-running review generation
        @set_time_limit(300); // 5 minutes
        
        try {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
            }
            
            check_ajax_referer('aiprg_generate_reviews', 'nonce');

            $use_scheduler = isset($_POST['use_scheduler']) ? filter_var(wp_unslash($_POST['use_scheduler']), FILTER_VALIDATE_BOOLEAN) : true;
            $product_ids = $this->review_generator->get_selected_products();

            if (empty($product_ids)) {
                wp_send_json_error(array(
                    'message' => esc_html__('No products selected for review generation.', 'ai-product-review-generator-for-woocommerce')
                ));
                return;
            }
            
            // Log the request details
            $this->logger->log('Review generation request received', 'INFO', array(
                'product_count' => count($product_ids),
                'use_scheduler' => $use_scheduler,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ));
            

			// Check if action scheduler is properly initialized
			if (!$this->action_scheduler) {
				$this->logger->log_error('Action Scheduler not initialized');
				wp_send_json_error(array(
					'message' => esc_html__('Failed to initialize scheduler. Please try again.', 'ai-product-review-generator-for-woocommerce')
				));
				return;
			}

			// Clear any stuck or corrupted actions before starting new batch
			$this->action_scheduler->clear_all_scheduled_actions();

			$batch_id = $this->action_scheduler->schedule_batch_generation($product_ids);

			if (!$batch_id) {
				wp_send_json_error(array(
					'message' => esc_html__('Failed to schedule batch generation.', 'ai-product-review-generator-for-woocommerce')
				));
				return;
			}

			$count_product_ids = count($product_ids);
			$approx_generation_time_in_sec = intval(get_option('aiprg_reviews_per_product', 1)) * $count_product_ids * 25 * 2;
			$message = sprintf(
				/* translators: %1$d: number of products being processed, %2$s: time to wait */
				esc_html__('Review generation scheduled for %1$d products. Processing will continue in the background. Please check after %2$s.', 'ai-product-review-generator-for-woocommerce'),
				$count_product_ids,
				human_time_diff(time(), time() + $approx_generation_time_in_sec)
			);

			wp_send_json_success(array(
				'message' => $message,
				'batch_id' => $batch_id,
				'total_products' => count($product_ids),
				'method' => 'scheduled'
			));
        } catch (Exception $e) {
            // Log the error
            $this->logger->log_error('Fatal error in review generation', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    esc_html__('An error occurred: %s', 'ai-product-review-generator-for-woocommerce'),
                    $e->getMessage()
                )
            ));
        }
    }
    
    public function handle_get_batch_status() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in parent function  
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '';
        
        if (empty($batch_id)) {
            $last_batch_status = get_option('aiprg_last_batch_status');
            
            if ($last_batch_status) {
                wp_send_json_success($last_batch_status);
            } else {
                wp_send_json_error(array(
                    'message' => esc_html__('No batch ID provided and no recent batch found.', 'ai-product-review-generator-for-woocommerce')
                ));
            }
        }
        
        $batch_status = $this->action_scheduler->get_batch_status($batch_id);
        
        if (!$batch_status) {
            wp_send_json_error(array(
                'message' => esc_html__('Batch not found.', 'ai-product-review-generator-for-woocommerce')
            ));
        }
        
        $progress = $batch_status['total_products'] > 0 
            ? round(($batch_status['processed'] / $batch_status['total_products']) * 100) 
            : 0;
        
        wp_send_json_success(array(
            'batch_id' => $batch_id,
            'status' => $batch_status['status'],
            'progress' => $progress,
            'processed' => $batch_status['processed'],
            'total' => $batch_status['total_products'],
            'success' => $batch_status['success'],
            'failed' => $batch_status['failed'],
            'started_at' => $batch_status['started_at'],
            'completed_at' => isset($batch_status['completed_at']) ? $batch_status['completed_at'] : null
        ));
    }
    
    public function handle_cancel_batch() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
        }
        
        check_ajax_referer('aiprg_cancel_batch', 'nonce');
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in parent function  
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '';
        
        if (empty($batch_id)) {
            wp_send_json_error(array(
                'message' => esc_html__('Batch ID is required.', 'ai-product-review-generator-for-woocommerce')
            ));
        }
        
        $result = $this->action_scheduler->cancel_batch($batch_id);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => esc_html__('Batch generation cancelled successfully.', 'ai-product-review-generator-for-woocommerce')
            ));
        } else {
            wp_send_json_error(array(
                'message' => esc_html__('Failed to cancel batch or batch is not currently processing.', 'ai-product-review-generator-for-woocommerce')
            ));
        }
    }
    
    public function handle_auto_save_setting() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
        }
        
        check_ajax_referer('aiprg_auto_save_setting', 'nonce');
        
        $field_id = isset($_POST['field_id']) ? sanitize_text_field(wp_unslash($_POST['field_id'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization happens in switch statement below based on field type
        $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';
        
        if (empty($field_id)) {
            wp_send_json_error(array(
                'message' => __('Field ID is required.', 'ai-product-review-generator-for-woocommerce')
            ));
        }
        
        // Handle different field types
        switch ($field_id) {
            case 'aiprg_openai_api_key':
            case 'aiprg_openai_engine':
            case 'aiprg_review_length_mode':
            case 'aiprg_sentiment_balance':
                $value = sanitize_text_field($value);
                break;
            case 'aiprg_custom_prompt':
            case 'aiprg_custom_keywords':
                $value = sanitize_textarea_field($value);
                break;
            case 'aiprg_reviews_per_product':
                $value = absint($value);
                break;
            case 'aiprg_enable_logging':
                $value = ($value === 'true' || $value === '1') ? 'yes' : 'no';
                break;
            case 'aiprg_select_products':
            case 'aiprg_select_categories':
                $value = is_array($value) ? array_map('intval', $value) : array();
                break;
            case 'aiprg_date_range_start':
            case 'aiprg_date_range_end':
                $value = sanitize_text_field($value);
                break;
            case 'aiprg_review_sentiments':
                $value = is_array($value) ? array_map('sanitize_text_field', $value) : array();
                break;
            default:
                $value = sanitize_text_field($value);
        }
        
        // Save the option
        update_option($field_id, $value);
        
        wp_send_json_success(array(
            'message' => __('Setting saved.', 'ai-product-review-generator-for-woocommerce'),
            'field_id' => $field_id,
            'value' => $value
        ));
    }
    
    public function handle_search_products() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
        }
        
        // Initialize logger for debugging
        $logger = AIPRG_Logger::instance();
        
        // Check for nonce - support both our custom nonce and WooCommerce's nonce
        if (!check_ajax_referer('search-products', 'security', false) && 
            !check_ajax_referer('aiprg_search_products', 'nonce', false)) {
            $logger->log('Product search failed: Invalid nonce', 'ERROR');
            wp_die(esc_html__('Security check failed', 'ai-product-review-generator-for-woocommerce'));
        }
        
        // Check if request is POST or GET
        $term = isset($_REQUEST['term']) ? sanitize_text_field(wp_unslash($_REQUEST['term'])) : '';
        $exclude = isset($_REQUEST['exclude']) ? array_map('intval', (array) $_REQUEST['exclude']) : array();
        $include = isset($_REQUEST['include']) ? array_map('intval', (array) $_REQUEST['include']) : array();
        $limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 30;
        
        // Format log message based on whether term is empty
        if (empty($term)) {
            $logger->log("Product search initiated - fetching recent products (Limit: {$limit})", 'INFO');
        } else {
            $logger->log("Product search initiated - searching for: \"{$term}\" (Limit: {$limit})", 'INFO');
        }
        
        // If empty term, get recent products
        if (empty($term) && empty($include)) {
            
            // Get recent products
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => 10,
                'post_status' => 'publish',
                'orderby' => 'date',
                'order' => 'DESC',
            );
            
            if (!empty($exclude)) {
                // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Required for excluding already selected products
                $args['post__not_in'] = $exclude;
            }
            
            $query = new WP_Query($args);
            $products = $query->posts;
            
            $results = array();
            foreach ($products as $product_post) {
                $product = wc_get_product($product_post->ID);
                if ($product) {
                    $formatted_name = $product->get_name();
                    if ($product->get_sku()) {
                        $formatted_name .= ' (SKU: ' . $product->get_sku() . ')';
                    }
                    $results[$product->get_id()] = $formatted_name;
                }
            }
            
            $logger->log('Returning ' . count($results) . ' recent products', 'INFO');
            wp_send_json($results);
            return;
        }
        
        $results = array();
        
        // First, try searching with multiple methods
        if (!empty($term)) {
            // Method 1: WP_Query with 's' parameter
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => $limit,
                'post_status' => 'publish',
                'orderby' => 'relevance',
                'order' => 'DESC',
                's' => $term,
            );
            
            if (!empty($exclude)) {
                // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Required for excluding already selected products
                $args['post__not_in'] = $exclude;
            }
            
            $logger->log('Method 1: WP_Query search with args: ' . json_encode($args), 'DEBUG');
            
            $query = new WP_Query($args);
            $products = $query->posts;
            
            $logger->log('Method 1: Found ' . count($products) . ' products', 'INFO');
            
            foreach ($products as $product_post) {
                $product = wc_get_product($product_post->ID);
                if (!$product) continue;
                
                $formatted_name = $product->get_name();
                if ($product->get_sku()) {
                    $formatted_name .= ' (SKU: ' . $product->get_sku() . ')';
                }
                if ($product->get_price()) {
                    $formatted_name .= ' - ' . wc_price($product->get_price());
                }
                
                $results[$product->get_id()] = $formatted_name;
            }
            
            // Method 2: Direct database search if not enough results
            if (count($results) < 5) {
                $logger->log('Method 2: Direct database search for more results', 'INFO');
                
                global $wpdb;
                $like_term = '%' . $wpdb->esc_like($term) . '%';
                
                // Create cache key for this specific query
                $cache_key = 'aiprg_product_search_' . md5($term . '_' . $limit);
                $products = wp_cache_get($cache_key, 'aiprg_searches');
                
                if (false === $products) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented with wp_cache
                    $products = $wpdb->get_results($wpdb->prepare(
                        "SELECT DISTINCT p.ID, p.post_title
                        FROM {$wpdb->posts} p
                        LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
                        WHERE p.post_type = 'product' 
                        AND p.post_status = 'publish'
                        AND (
                            p.post_title LIKE %s 
                            OR p.post_content LIKE %s
                            OR p.post_excerpt LIKE %s
                            OR pm_sku.meta_value LIKE %s
                        )
                        LIMIT %d",
                        $like_term,
                        $like_term,
                        $like_term,
                        $like_term,
                        $limit
                    ));
                    
                    // Cache for 5 minutes
                    wp_cache_set($cache_key, $products, 'aiprg_searches', 300);
                }
                
                $logger->log('Method 2: Found ' . count($products) . ' products', 'INFO');
                
                foreach ($products as $product_row) {
                    if (!isset($results[$product_row->ID])) {
                        $product = wc_get_product($product_row->ID);
                        if ($product) {
                            $formatted_name = $product->get_name();
                            if ($product->get_sku()) {
                                $formatted_name .= ' (SKU: ' . $product->get_sku() . ')';
                            }
                            if ($product->get_price()) {
                                $formatted_name .= ' - ' . wc_price($product->get_price());
                            }
                            
                            $results[$product->get_id()] = $formatted_name;
                        }
                    }
                }
            }
            
            // Method 3: Search by product variations
            if (count($results) < 5) {
                $logger->log('Method 3: Searching product variations', 'INFO');
                
                $args = array(
                    'post_type' => array('product', 'product_variation'),
                    'posts_per_page' => $limit,
                    'post_status' => 'publish',
                    's' => $term,
                );
                
                $query = new WP_Query($args);
                
                foreach ($query->posts as $post) {
                    if (!isset($results[$post->ID])) {
                        $product = wc_get_product($post->ID);
                        if ($product) {
                            $formatted_name = $product->get_name();
                            if ($product->get_sku()) {
                                $formatted_name .= ' (SKU: ' . $product->get_sku() . ')';
                            }
                            if ($product->get_price()) {
                                $formatted_name .= ' - ' . wc_price($product->get_price());
                            }
                            
                            $results[$product->get_id()] = $formatted_name;
                        }
                    }
                }
            }
        }
        
        // Handle include parameter
        if (!empty($include)) {
            foreach ($include as $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $formatted_name = $product->get_name();
                    if ($product->get_sku()) {
                        $formatted_name .= ' (SKU: ' . $product->get_sku() . ')';
                    }
                    if ($product->get_price()) {
                        $formatted_name .= ' - ' . wc_price($product->get_price());
                    }
                    
                    $results[$product->get_id()] = $formatted_name;
                }
            }
        }
        
        // If still no results, get all products
        if (empty($results) && strlen($term) >= 2) {
            $logger->log('No results found - fetching all products as fallback', 'WARNING');
            
            $all_products = wc_get_products(array(
                'limit' => 20,
                'status' => 'publish',
                'orderby' => 'name',
                'order' => 'ASC',
            ));
            
            foreach ($all_products as $product) {
                $product_name = strtolower($product->get_name());
                $search_term = strtolower($term);
                
                // Simple substring match
                if (strpos($product_name, $search_term) !== false) {
                    $formatted_name = $product->get_name();
                    if ($product->get_sku()) {
                        $formatted_name .= ' (SKU: ' . $product->get_sku() . ')';
                    }
                    if ($product->get_price()) {
                        $formatted_name .= ' - ' . wc_price($product->get_price());
                    }
                    
                    $results[$product->get_id()] = $formatted_name;
                }
            }
        }
        
        $logger->log('Product search completed. Returning ' . count($results) . ' results', 'SUCCESS');
        
        // Log the first few results for debugging
        if (!empty($results)) {
            $sample = array_slice($results, 0, 3, true);
            $logger->log('Sample results: ' . json_encode($sample), 'DEBUG');
        }
        
        wp_send_json($results);
    }
    
    public function handle_search_categories() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
        }
        
        // Initialize logger for debugging
        $logger = AIPRG_Logger::instance();
        
        // Check for nonce
        if (!check_ajax_referer('aiprg_search_categories', 'nonce', false)) {
            $logger->log('Category search failed: Invalid nonce', 'ERROR');
            wp_die(esc_html__('Security check failed', 'ai-product-review-generator-for-woocommerce'));
        }
        
        $term = isset($_REQUEST['term']) ? sanitize_text_field(wp_unslash($_REQUEST['term'])) : '';
        $exclude = isset($_REQUEST['exclude']) ? array_map('intval', (array) $_REQUEST['exclude']) : array();
        $include = isset($_REQUEST['include']) ? array_map('intval', (array) $_REQUEST['include']) : array();
        
        // Format log message based on whether term is empty
        if (empty($term)) {
            $logger->log('Category search initiated - fetching all categories', 'INFO');
        } else {
            $logger->log("Category search initiated - searching for: \"{$term}\"", 'INFO');
        }
        
        $results = array();
        
        // If include is specified, get those categories
        if (!empty($include)) {
            foreach ($include as $cat_id) {
                $category = get_term($cat_id, 'product_cat');
                if ($category && !is_wp_error($category)) {
                    $results[$category->term_id] = $category->name;
                }
            }
        }
        
        // Search for categories
        $args = array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'search' => $term,
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Required for excluding already selected categories
            'exclude' => $exclude,
            'number' => 30,
        );
        
        // If no search term, get all categories
        if (empty($term)) {
            unset($args['search']);
            $args['orderby'] = 'name';
            $args['order'] = 'ASC';
        }
        
        $categories = get_terms($args);
        
        $logger->log('Found ' . count($categories) . ' categories', 'INFO');
        
        if (!is_wp_error($categories)) {
            foreach ($categories as $category) {
                // Format category name with count
                $formatted_name = $category->name;
                if ($category->count > 0) {
                    $formatted_name .= ' (' . $category->count . ' products)';
                }
                
                // Add parent category name if it has a parent
                if ($category->parent > 0) {
                    $parent = get_term($category->parent, 'product_cat');
                    if ($parent && !is_wp_error($parent)) {
                        $formatted_name = $parent->name . ' → ' . $formatted_name;
                    }
                }
                
                $results[$category->term_id] = $formatted_name;
            }
        }
        
        // If no results with search, try name__like
        if (empty($results) && !empty($term)) {
            $logger->log('No categories found with search, trying name__like', 'INFO');
            
            $args = array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'name__like' => $term,
                // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Required for excluding already selected categories
                'exclude' => $exclude,
                'number' => 30,
            );
            
            $categories = get_terms($args);
            
            if (!is_wp_error($categories)) {
                foreach ($categories as $category) {
                    $formatted_name = $category->name;
                    if ($category->count > 0) {
                        $formatted_name .= ' (' . $category->count . ' products)';
                    }
                    
                    if ($category->parent > 0) {
                        $parent = get_term($category->parent, 'product_cat');
                        if ($parent && !is_wp_error($parent)) {
                            $formatted_name = $parent->name . ' → ' . $formatted_name;
                        }
                    }
                    
                    $results[$category->term_id] = $formatted_name;
                }
            }
        }
        
        // If still no results, do a broader search
        if (empty($results) && !empty($term)) {
            $logger->log('Trying broader category search', 'INFO');
            
            global $wpdb;
            $like_term = '%' . $wpdb->esc_like($term) . '%';
            
            // Create cache key for this specific query
            $cache_key = 'aiprg_category_search_' . md5($term);
            $category_ids = wp_cache_get($cache_key, 'aiprg_searches');
            
            if (false === $category_ids) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching implemented with wp_cache
                $category_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT t.term_id
                    FROM {$wpdb->terms} t
                    INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                    WHERE tt.taxonomy = 'product_cat'
                    AND (t.name LIKE %s OR t.slug LIKE %s)
                    LIMIT 30",
                    $like_term,
                    $like_term
                ));
                
                // Cache for 5 minutes
                wp_cache_set($cache_key, $category_ids, 'aiprg_searches', 300);
            }
            
            foreach ($category_ids as $cat_id) {
                $category = get_term($cat_id, 'product_cat');
                if ($category && !is_wp_error($category)) {
                    $formatted_name = $category->name;
                    if ($category->count > 0) {
                        $formatted_name .= ' (' . $category->count . ' products)';
                    }
                    
                    if ($category->parent > 0) {
                        $parent = get_term($category->parent, 'product_cat');
                        if ($parent && !is_wp_error($parent)) {
                            $formatted_name = $parent->name . ' → ' . $formatted_name;
                        }
                    }
                    
                    $results[$category->term_id] = $formatted_name;
                }
            }
        }
        
        $logger->log('Category search completed. Returning ' . count($results) . ' results', 'SUCCESS');
        
        wp_send_json($results);
    }
    
    public function handle_delete_review() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
        }
        
        $review_id = isset($_POST['review_id']) ? intval($_POST['review_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        
        if (!wp_verify_nonce($nonce, 'aiprg_delete_review_' . $review_id)) {
            wp_send_json_error(array(
                'message' => esc_html__('Security check failed.', 'ai-product-review-generator-for-woocommerce')
            ));
            return;
        }
        
        if (empty($review_id)) {
            wp_send_json_error(array(
                'message' => esc_html__('Review ID is required.', 'ai-product-review-generator-for-woocommerce')
            ));
            return;
        }
        
        // Check if this is an AI-generated review
        $is_ai_generated = get_comment_meta($review_id, 'aiprg_generated', true);
        
        if (!$is_ai_generated) {
            wp_send_json_error(array(
                'message' => esc_html__('This is not an AI-generated review.', 'ai-product-review-generator-for-woocommerce')
            ));
            return;
        }
        
        // Get the product ID before deletion for rating update
        $comment = get_comment($review_id);
        if (!$comment) {
            wp_send_json_error(array(
                'message' => esc_html__('Review not found.', 'ai-product-review-generator-for-woocommerce')
            ));
            return;
        }
        
        $product_id = $comment->comment_post_ID;
        
        // Delete the review
        $result = wp_delete_comment($review_id, true); // true = force delete (bypass trash)
        
        if ($result) {
            // Update product rating after deletion
            $this->update_product_rating_after_deletion($product_id);
            
            // Clear cache after review deletion
            $this->clear_review_caches();
            
            // Log the deletion
            $this->logger->log('AI-generated review deleted', 'INFO', array(
                'review_id' => $review_id,
                'product_id' => $product_id,
                'deleted_by' => wp_get_current_user()->user_login
            ));
            
            wp_send_json_success(array(
                'message' => esc_html__('Review deleted successfully.', 'ai-product-review-generator-for-woocommerce'),
                'review_id' => $review_id
            ));
        } else {
            wp_send_json_error(array(
                'message' => esc_html__('Failed to delete review.', 'ai-product-review-generator-for-woocommerce')
            ));
        }
    }
    
    private function update_product_rating_after_deletion($product_id) {
        // Recalculate product ratings after review deletion
        $args = array(
            'post_id' => $product_id,
            'status' => 'approve',
            'type' => 'review',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for filtering reviews by rating
            'meta_key' => 'rating',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for filtering reviews by rating
            'meta_value' => array(1, 2, 3, 4, 5)
        );
        
        $reviews = get_comments($args);
        
        if (empty($reviews)) {
            delete_post_meta($product_id, '_wc_average_rating');
            delete_post_meta($product_id, '_wc_review_count');
            return;
        }
        
        $total_rating = 0;
        $rating_counts = array_fill(1, 5, 0);
        
        foreach ($reviews as $review) {
            $rating = get_comment_meta($review->comment_ID, 'rating', true);
            if ($rating) {
                $total_rating += intval($rating);
                $rating_counts[intval($rating)]++;
            }
        }
        
        $review_count = count($reviews);
        $average_rating = $review_count > 0 ? round($total_rating / $review_count, 2) : 0;
        
        update_post_meta($product_id, '_wc_average_rating', $average_rating);
        update_post_meta($product_id, '_wc_review_count', $review_count);
        update_post_meta($product_id, '_wc_rating_count', $rating_counts);
    }
    
    /**
     * Clear review-related caches
     */
    private function clear_review_caches() {
        // Clear all review-related caches
        wp_cache_delete('aiprg_total_reviews_count', 'aiprg');
        wp_cache_delete('aiprg_stats_total_reviews', 'aiprg_stats');
        wp_cache_delete('aiprg_stats_today_reviews_' . current_time('Y-m-d'), 'aiprg_stats');
        wp_cache_delete('aiprg_stats_avg_rating', 'aiprg_stats');
        wp_cache_delete('aiprg_active_batches', 'aiprg_batches');
    }
    
    public function handle_delete_all_reviews() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'ai-product-review-generator-for-woocommerce'));
        }
        
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        
        if (!wp_verify_nonce($nonce, 'aiprg_delete_all_reviews')) {
            wp_send_json_error(array(
                'message' => esc_html__('Security check failed.', 'ai-product-review-generator-for-woocommerce')
            ));
            return;
        }
        
        global $wpdb;
        
        // Get all AI-generated review IDs
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Necessary for bulk deletion of all AI reviews
        $review_ids = $wpdb->get_col(
            "SELECT comment_id FROM {$wpdb->commentmeta} 
            WHERE meta_key = 'aiprg_generated' AND meta_value = '1'"
        );
        
        if (empty($review_ids)) {
            wp_send_json_success(array(
                'message' => esc_html__('No AI-generated reviews found.', 'ai-product-review-generator-for-woocommerce'),
                'deleted' => 0
            ));
            return;
        }
        
        $deleted_count = 0;
        $affected_products = array();
        
        // Delete each review
        foreach ($review_ids as $review_id) {
            $comment = get_comment($review_id);
            if ($comment) {
                $product_id = $comment->comment_post_ID;
                if (!in_array($product_id, $affected_products)) {
                    $affected_products[] = $product_id;
                }
                
                // Force delete the review
                if (wp_delete_comment($review_id, true)) {
                    $deleted_count++;
                }
            }
        }
        
        // Update ratings for all affected products
        foreach ($affected_products as $product_id) {
            $this->update_product_rating_after_deletion($product_id);
        }
        
        // Clear all caches
        $this->clear_review_caches();
        
        // Log the bulk deletion
        $this->logger->log('All AI-generated reviews deleted', 'WARNING', array(
            'deleted_count' => $deleted_count,
            'affected_products' => count($affected_products),
            'deleted_by' => wp_get_current_user()->user_login
        ));
        
        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %d: number of deleted reviews */
                esc_html__('Successfully deleted %d AI-generated reviews.', 'ai-product-review-generator-for-woocommerce'),
                $deleted_count
            ),
            'deleted' => $deleted_count,
            'affected_products' => count($affected_products)
        ));
    }
}