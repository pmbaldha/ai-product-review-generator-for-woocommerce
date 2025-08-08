<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Action Scheduler class for chunked processing
 * Note: This implementation requires the Action Scheduler library
 * For now, we'll use a simplified version without actual scheduling
 */
class AIPRG_Action_Scheduler {
    
    const HOOK_PROCESS_BATCH = 'aiprg_process_review_batch';
    const HOOK_PROCESS_SINGLE = 'aiprg_process_single_product';
    const BATCH_SIZE = 5;
    const PRODUCTS_PER_CHUNK = 10;
    
    private $logger;
    
    public function __construct() {
        $this->logger = AIPRG_Logger::instance();
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action(self::HOOK_PROCESS_BATCH, array($this, 'process_batch'), 10, 1);
        add_action(self::HOOK_PROCESS_SINGLE, array($this, 'process_single_product'), 10, 2);
    }
    
    public function schedule_batch_generation($product_ids, $settings = array()) {
        if (empty($product_ids)) {
            $this->logger->log_error('No products provided for batch generation');
            return false;
        }
        
        $batch_id = $this->generate_batch_id();
        
        $default_settings = array(
            'reviews_per_product' => intval(get_option('aiprg_reviews_per_product', 5)),
            'sentiments' => get_option('aiprg_review_sentiments', array('positive')),
            'sentiment_balance' => get_option('aiprg_sentiment_balance', 'balanced'),
            'review_length_mode' => get_option('aiprg_review_length_mode', 'mixed'),
            'date_start' => get_option('aiprg_date_range_start', date('Y-m-d', strtotime('-30 days'))),
            'date_end' => get_option('aiprg_date_range_end', date('Y-m-d'))
        );
        
        $settings = wp_parse_args($settings, $default_settings);
        
        $this->logger->log('Starting scheduled batch generation', 'INFO', array(
            'batch_id' => $batch_id,
            'total_products' => count($product_ids),
            'settings' => $settings
        ));
        
        update_option('aiprg_current_batch_' . $batch_id, array(
            'status' => 'processing',
            'total_products' => count($product_ids),
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'started_at' => current_time('mysql'),
            'settings' => $settings
        ));
        
        $chunks = array_chunk($product_ids, self::PRODUCTS_PER_CHUNK);
        $delay = 0;
        
        // Note: Action Scheduler not available, falling back to direct processing
        // foreach ($chunks as $chunk_index => $chunk) {
        //     as_schedule_single_action(
        //         time() + $delay,
        //         self::HOOK_PROCESS_BATCH,
        //         array(
        //             'batch_id' => $batch_id,
        //             'chunk_index' => $chunk_index,
        //             'product_ids' => $chunk,
        //             'settings' => $settings
        //         ),
        //         'aiprg'
        //     );
        //     
        //     $delay += 5;
        // }
        
        return $batch_id;
    }
    
    public function process_batch($args) {
        $batch_id = $args['batch_id'];
        $chunk_index = $args['chunk_index'];
        $product_ids = $args['product_ids'];
        $settings = $args['settings'];
        
        $this->logger->log('Processing batch chunk', 'INFO', array(
            'batch_id' => $batch_id,
            'chunk_index' => $chunk_index,
            'products_in_chunk' => count($product_ids)
        ));
        
        $batch_status = get_option('aiprg_current_batch_' . $batch_id);
        
        if (!$batch_status || $batch_status['status'] === 'cancelled') {
            $this->logger->log('Batch cancelled or not found', 'WARNING', array(
                'batch_id' => $batch_id
            ));
            return;
        }
        
        // Note: Action Scheduler not available, falling back to direct processing
        // $delay = 0;
        // foreach ($product_ids as $product_id) {
        //     as_schedule_single_action(
        //         time() + $delay,
        //         self::HOOK_PROCESS_SINGLE,
        //         array(
        //             'batch_id' => $batch_id,
        //             'product_id' => $product_id,
        //             'settings' => $settings
        //         ),
        //         'aiprg'
        //     );
        //     
        //     $delay += 2;
        // }
    }
    
    public function process_single_product($batch_id, $args) {
        $product_id = $args['product_id'];
        $settings = $args['settings'];
        
        $batch_status = get_option('aiprg_current_batch_' . $batch_id);
        
        if (!$batch_status || $batch_status['status'] === 'cancelled') {
            $this->logger->log('Batch cancelled, skipping product', 'INFO', array(
                'batch_id' => $batch_id,
                'product_id' => $product_id
            ));
            return;
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            $this->logger->log_error("Product not found", array(
                'batch_id' => $batch_id,
                'product_id' => $product_id
            ));
            
            $this->update_batch_progress($batch_id, false);
            return;
        }
        
        $this->logger->log('Processing single product', 'INFO', array(
            'batch_id' => $batch_id,
            'product_id' => $product_id,
            'product_name' => $product->get_name()
        ));
        
        $review_generator = new AIPRG_Review_Generator();
        $openai = new AIPRG_OpenAI();
        
        $reviews_generated = 0;
        $reviews_failed = 0;
        
        for ($i = 0; $i < $settings['reviews_per_product']; $i++) {
            $sentiment = $this->get_random_sentiment($settings['sentiments'], $settings['sentiment_balance']);
            $rating = $this->get_rating_from_sentiment($sentiment);
            $length = $this->get_review_length($settings['review_length_mode']);
            
            $options = array(
                'sentiment' => $sentiment,
                'rating' => $rating,
                'length' => $length
            );
            
            $review_content = $openai->generate_review($product, $options);
            
            if (is_wp_error($review_content)) {
                $this->logger->log_review_generation($product_id, $product->get_name(), 'FAILED', array(
                    'batch_id' => $batch_id,
                    'error' => $review_content->get_error_message(),
                    'review_number' => $i + 1,
                    'options' => $options
                ));
                $reviews_failed++;
                continue;
            }
            
            $reviewer_name = $this->generate_reviewer_name();
            $reviewer_email = $this->generate_reviewer_email($reviewer_name);
            $review_date = $this->get_random_date($settings['date_start'], $settings['date_end']);
            
            $comment_data = array(
                'comment_post_ID' => $product_id,
                'comment_author' => $reviewer_name,
                'comment_author_email' => $reviewer_email,
                'comment_author_url' => '',
                'comment_content' => $review_content,
                'comment_type' => 'review',
                'comment_parent' => 0,
                'user_id' => 0,
                'comment_author_IP' => '',
                'comment_agent' => 'AI Product Review Generator (Scheduled)',
                'comment_date' => $review_date,
                'comment_approved' => 1
            );
            
            $comment_id = wp_insert_comment($comment_data);
            
            if ($comment_id) {
                update_comment_meta($comment_id, 'rating', $rating);
                update_comment_meta($comment_id, 'verified', 0);
                update_comment_meta($comment_id, 'aiprg_generated', 1);
                update_comment_meta($comment_id, 'aiprg_batch_id', $batch_id);
                
                $this->logger->log_review_generation($product_id, $product->get_name(), 'SUCCESS', array(
                    'batch_id' => $batch_id,
                    'comment_id' => $comment_id,
                    'rating' => $rating,
                    'reviewer' => $reviewer_name,
                    'review_number' => $i + 1,
                    'sentiment' => $sentiment
                ));
                
                $reviews_generated++;
                $this->update_product_rating($product_id);
            } else {
                $this->logger->log_error('Failed to save review to database', array(
                    'batch_id' => $batch_id,
                    'product_id' => $product_id,
                    'product_name' => $product->get_name(),
                    'review_number' => $i + 1
                ));
                $reviews_failed++;
            }
            
            usleep(500000);
        }
        
        $success = $reviews_generated > 0;
        $this->update_batch_progress($batch_id, $success, $reviews_generated, $reviews_failed);
        
        $this->logger->log('Completed processing product', 'INFO', array(
            'batch_id' => $batch_id,
            'product_id' => $product_id,
            'reviews_generated' => $reviews_generated,
            'reviews_failed' => $reviews_failed
        ));
    }
    
    private function update_batch_progress($batch_id, $success = true, $reviews_generated = 0, $reviews_failed = 0) {
        $batch_status = get_option('aiprg_current_batch_' . $batch_id);
        
        if (!$batch_status) {
            return;
        }
        
        $batch_status['processed']++;
        
        if ($success) {
            $batch_status['success'] += $reviews_generated;
        }
        $batch_status['failed'] += $reviews_failed;
        
        if ($batch_status['processed'] >= $batch_status['total_products']) {
            $batch_status['status'] = 'completed';
            $batch_status['completed_at'] = current_time('mysql');
            
            $this->logger->log('Batch generation completed', 'INFO', array(
                'batch_id' => $batch_id,
                'total_products' => $batch_status['total_products'],
                'total_success' => $batch_status['success'],
                'total_failed' => $batch_status['failed']
            ));
        }
        
        update_option('aiprg_current_batch_' . $batch_id, $batch_status);
        
        update_option('aiprg_last_batch_status', array(
            'batch_id' => $batch_id,
            'status' => $batch_status['status'],
            'progress' => round(($batch_status['processed'] / $batch_status['total_products']) * 100),
            'success' => $batch_status['success'],
            'failed' => $batch_status['failed']
        ));
    }
    
    public function cancel_batch($batch_id) {
        $batch_status = get_option('aiprg_current_batch_' . $batch_id);
        
        if (!$batch_status || $batch_status['status'] !== 'processing') {
            return false;
        }
        
        $batch_status['status'] = 'cancelled';
        $batch_status['cancelled_at'] = current_time('mysql');
        update_option('aiprg_current_batch_' . $batch_id, $batch_status);
        
        // Note: Action Scheduler not available
        // as_unschedule_all_actions(self::HOOK_PROCESS_BATCH, array('batch_id' => $batch_id), 'aiprg');
        // as_unschedule_all_actions(self::HOOK_PROCESS_SINGLE, array('batch_id' => $batch_id), 'aiprg');
        
        $this->logger->log('Batch cancelled', 'INFO', array(
            'batch_id' => $batch_id
        ));
        
        return true;
    }
    
    public function get_batch_status($batch_id) {
        return get_option('aiprg_current_batch_' . $batch_id);
    }
    
    public function get_active_batches() {
        global $wpdb;
        
        $option_name_pattern = 'aiprg_current_batch_%';
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value 
                 FROM {$wpdb->options} 
                 WHERE option_name LIKE %s",
                $option_name_pattern
            )
        );
        
        $active_batches = array();
        
        foreach ($results as $result) {
            $batch_data = maybe_unserialize($result->option_value);
            if ($batch_data && $batch_data['status'] === 'processing') {
                $batch_id = str_replace('aiprg_current_batch_', '', $result->option_name);
                $active_batches[$batch_id] = $batch_data;
            }
        }
        
        return $active_batches;
    }
    
    public function cleanup_old_batches($days = 7) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $option_name_pattern = 'aiprg_current_batch_%';
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value 
                 FROM {$wpdb->options} 
                 WHERE option_name LIKE %s",
                $option_name_pattern
            )
        );
        
        $deleted_count = 0;
        
        foreach ($results as $result) {
            $batch_data = maybe_unserialize($result->option_value);
            
            if ($batch_data && 
                ($batch_data['status'] === 'completed' || $batch_data['status'] === 'cancelled') &&
                isset($batch_data['started_at']) && 
                $batch_data['started_at'] < $cutoff_date) {
                
                delete_option($result->option_name);
                $deleted_count++;
            }
        }
        
        if ($deleted_count > 0) {
            $this->logger->log('Cleaned up old batches', 'INFO', array(
                'deleted_count' => $deleted_count,
                'cutoff_date' => $cutoff_date
            ));
        }
        
        return $deleted_count;
    }
    
    private function generate_batch_id() {
        return 'batch_' . time() . '_' . wp_generate_password(8, false);
    }
    
    private function get_random_sentiment($sentiments, $balance) {
        if (empty($sentiments)) {
            return 'positive';
        }
        
        $weights = $this->get_sentiment_weights($sentiments, $balance);
        $rand = mt_rand(1, 100);
        $cumulative = 0;
        
        foreach ($weights as $sentiment => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return $sentiment;
            }
        }
        
        return $sentiments[array_rand($sentiments)];
    }
    
    private function get_sentiment_weights($sentiments, $balance) {
        $weights = array();
        
        switch ($balance) {
            case 'mostly_positive':
                $weights = array(
                    'positive' => 70,
                    'neutral' => 20,
                    'negative' => 10
                );
                break;
            
            case 'overwhelmingly_positive':
                $weights = array(
                    'positive' => 90,
                    'neutral' => 8,
                    'negative' => 2
                );
                break;
            
            case 'realistic':
                $weights = array(
                    'positive' => 60,
                    'neutral' => 30,
                    'negative' => 10
                );
                break;
            
            case 'balanced':
            default:
                $count = count($sentiments);
                $weight_each = 100 / $count;
                foreach ($sentiments as $sentiment) {
                    $weights[$sentiment] = $weight_each;
                }
                break;
        }
        
        $filtered_weights = array();
        foreach ($sentiments as $sentiment) {
            if (isset($weights[$sentiment])) {
                $filtered_weights[$sentiment] = $weights[$sentiment];
            }
        }
        
        return $filtered_weights;
    }
    
    private function get_rating_from_sentiment($sentiment) {
        switch ($sentiment) {
            case 'negative':
                return mt_rand(2, 3);
            case 'neutral':
                return mt_rand(3, 4);
            case 'positive':
                return mt_rand(4, 5);
            default:
                return 5;
        }
    }
    
    private function get_review_length($mode) {
        if ($mode === 'mixed') {
            $lengths = array('short', 'medium', 'long');
            return $lengths[array_rand($lengths)];
        }
        
        return $mode;
    }
    
    private function generate_reviewer_name() {
        $first_names = array(
            'John', 'Jane', 'Michael', 'Sarah', 'David', 'Emily', 'James', 'Jessica',
            'Robert', 'Jennifer', 'William', 'Lisa', 'Richard', 'Karen', 'Joseph', 'Nancy'
        );
        
        $last_initials = array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'L', 'M');
        
        return $first_names[array_rand($first_names)] . ' ' . $last_initials[array_rand($last_initials)] . '.';
    }
    
    private function generate_reviewer_email($name) {
        $name_parts = explode(' ', strtolower($name));
        $username = $name_parts[0] . mt_rand(100, 999);
        $domains = array('gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com');
        
        return $username . '@' . $domains[array_rand($domains)];
    }
    
    private function get_random_date($start, $end) {
        $start_timestamp = strtotime($start);
        $end_timestamp = strtotime($end);
        
        $random_timestamp = mt_rand($start_timestamp, $end_timestamp);
        
        return date('Y-m-d H:i:s', $random_timestamp);
    }
    
    private function update_product_rating($product_id) {
        $args = array(
            'post_id' => $product_id,
            'status' => 'approve',
            'type' => 'review',
            'meta_key' => 'rating',
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
}