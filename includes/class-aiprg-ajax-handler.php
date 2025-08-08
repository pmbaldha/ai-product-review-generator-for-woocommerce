<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPRG_Ajax_Handler {
    
    private $review_generator;
    private $action_scheduler;
    
    public function __construct() {
        add_action('wp_ajax_aiprg_generate_reviews', array($this, 'handle_generate_reviews'));
        add_action('wp_ajax_aiprg_generate_reviews_scheduled', array($this, 'handle_generate_reviews_scheduled'));
        add_action('wp_ajax_aiprg_validate_api_key', array($this, 'handle_validate_api_key'));
        add_action('wp_ajax_aiprg_get_generation_progress', array($this, 'handle_get_progress'));
        add_action('wp_ajax_aiprg_get_batch_status', array($this, 'handle_get_batch_status'));
        add_action('wp_ajax_aiprg_cancel_batch', array($this, 'handle_cancel_batch'));
        
        $this->review_generator = new AIPRG_Review_Generator();
        $this->action_scheduler = new AIPRG_Action_Scheduler();
    }
    
    public function handle_generate_reviews() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Unauthorized', 'ai-product-review-generator'));
        }
        
        check_ajax_referer('aiprg_generate_reviews', 'nonce');
        
        $product_ids = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : array();
        
        if (empty($product_ids)) {
            $product_ids = $this->review_generator->get_selected_products();
        }
        
        if (empty($product_ids)) {
            wp_send_json_error(array(
                'message' => __('No products selected for review generation.', 'ai-product-review-generator')
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
            __('Review generation completed! Successfully generated %d reviews, %d failed.', 'ai-product-review-generator'),
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
            wp_die(__('Unauthorized', 'ai-product-review-generator'));
        }
        
        check_ajax_referer('aiprg_validate_api_key', 'nonce');
        
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        
        if (empty($api_key)) {
            wp_send_json_error(array(
                'message' => __('API key is required.', 'ai-product-review-generator')
            ));
        }
        
        update_option('aiprg_openai_api_key', $api_key);
        
        $openai = new AIPRG_OpenAI();
        $is_valid = $openai->validate_api_key();
        
        if ($is_valid) {
            wp_send_json_success(array(
                'message' => __('API key is valid and has been saved.', 'ai-product-review-generator')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Invalid API key. Please check your key and try again.', 'ai-product-review-generator')
            ));
        }
    }
    
    public function handle_get_progress() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Unauthorized', 'ai-product-review-generator'));
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
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Unauthorized', 'ai-product-review-generator'));
        }
        
        check_ajax_referer('aiprg_generate_reviews', 'nonce');
        
        $product_ids = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : array();
        $use_scheduler = isset($_POST['use_scheduler']) ? filter_var($_POST['use_scheduler'], FILTER_VALIDATE_BOOLEAN) : true;
        
        if (empty($product_ids)) {
            $product_ids = $this->review_generator->get_selected_products();
        }
        
        if (empty($product_ids)) {
            wp_send_json_error(array(
                'message' => __('No products selected for review generation.', 'ai-product-review-generator')
            ));
        }
        
        if (!$use_scheduler || count($product_ids) <= 5) {
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
                __('Review generation completed! Successfully generated %d reviews, %d failed.', 'ai-product-review-generator'),
                $results['success'],
                $results['failed']
            );
            
            wp_send_json_success(array(
                'message' => $message,
                'results' => $results,
                'method' => 'direct'
            ));
        } else {
            $batch_id = $this->action_scheduler->schedule_batch_generation($product_ids);
            
            if (!$batch_id) {
                wp_send_json_error(array(
                    'message' => __('Failed to schedule batch generation.', 'ai-product-review-generator')
                ));
            }
            
            $message = sprintf(
                __('Review generation scheduled for %d products. Processing will continue in the background.', 'ai-product-review-generator'),
                count($product_ids)
            );
            
            wp_send_json_success(array(
                'message' => $message,
                'batch_id' => $batch_id,
                'total_products' => count($product_ids),
                'method' => 'scheduled'
            ));
        }
    }
    
    public function handle_get_batch_status() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Unauthorized', 'ai-product-review-generator'));
        }
        
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
        
        if (empty($batch_id)) {
            $last_batch_status = get_option('aiprg_last_batch_status');
            
            if ($last_batch_status) {
                wp_send_json_success($last_batch_status);
            } else {
                wp_send_json_error(array(
                    'message' => __('No batch ID provided and no recent batch found.', 'ai-product-review-generator')
                ));
            }
        }
        
        $batch_status = $this->action_scheduler->get_batch_status($batch_id);
        
        if (!$batch_status) {
            wp_send_json_error(array(
                'message' => __('Batch not found.', 'ai-product-review-generator')
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
            wp_die(__('Unauthorized', 'ai-product-review-generator'));
        }
        
        check_ajax_referer('aiprg_cancel_batch', 'nonce');
        
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
        
        if (empty($batch_id)) {
            wp_send_json_error(array(
                'message' => __('Batch ID is required.', 'ai-product-review-generator')
            ));
        }
        
        $result = $this->action_scheduler->cancel_batch($batch_id);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Batch generation cancelled successfully.', 'ai-product-review-generator')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to cancel batch or batch is not currently processing.', 'ai-product-review-generator')
            ));
        }
    }
}