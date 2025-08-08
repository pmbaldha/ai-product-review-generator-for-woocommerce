<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPRG_OpenAI {
    
    private $api_key;
    private $engine;
    private $logger;
    private $api_base_url;
    
    public function __construct() {
        $this->api_key = get_option('aiprg_openai_api_key', '');
        $this->engine = get_option('aiprg_openai_engine', 'gpt-3.5-turbo');
        $this->api_base_url = 'https://api.openai.com/v1'; // Static API base URL
        $this->logger = AIPRG_Logger::instance();
    }
    
    public function generate_review($product, $options = array()) {
        if (empty($this->api_key)) {
            $this->logger->log_error('API key missing for review generation');
            return new WP_Error('api_key_missing', __('OpenAI API key is not configured.', 'ai-product-review-generator'));
        }
        
        $product_name = $product->get_name();
        $product_id = $product->get_id();
        
        $this->logger->log("Starting review generation for product: {$product_name} (ID: {$product_id})", 'INFO', $options);
        
        // Build the full prompt for custom /responses endpoint
        // Using completions-style format with system instruction in prompt
        $system_instruction = 'Write an authentic product review that sounds natural and human-like. Avoid overly promotional language.';
        $user_prompt = $this->build_prompt($product, $options);
        $full_prompt = $system_instruction . "\n\n" . $user_prompt . "\n\nReview:";
        
        $request_body = array(
            'model' => $this->get_model_name(),
            'input' => $full_prompt,
            // 'max_tokens' => $this->get_max_tokens($options['length'] ?? 'medium'),
            // 'top_p' => 1,
            // 'frequency_penalty' => 0.5,
            // 'presence_penalty' => 0.3,
            // 'n' => 1,
            // 'echo' => false,
            // 'stop' => array("\n\n\n", "END")
        );
        
        $endpoint = rtrim($this->api_base_url, '/') . '/responses';
        $headers = array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json'
        );
        
        // Log API request
        $this->logger->log_api_request($endpoint, $request_body, $headers);
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($request_body),
            'timeout' => 30,
            'sslverify' => true, // Enable SSL verification for security
            'user-agent' => 'AI-Product-Review-Generator/1.0.0'
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log_error("WP Remote Post Error: {$error_message}", array(
                'product_id' => $product_id,
                'product_name' => $product_name
            ));
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Log raw response for debugging
        $this->logger->log("Raw API Response - Status: {$status_code}, Body length: " . strlen($body), 'DEBUG');
        
        $data = json_decode($body, true);
        
        // Check for JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log_error('JSON decode error: ' . json_last_error_msg(), array(
                'raw_body' => substr($body, 0, 500),
                'status_code' => $status_code
            ));
            return new WP_Error('json_error', __('Failed to decode API response.', 'ai-product-review-generator'));
        }
        
        // Log API response
        $this->logger->log_api_response($data, $status_code);
        
        if (isset($data['error'])) {
            $error_msg = $data['error']['message'] ?? __('OpenAI API error occurred.', 'ai-product-review-generator');
            $error_type = $data['error']['type'] ?? 'unknown';
            $error_code = $data['error']['code'] ?? 'unknown';
            
            $this->logger->log_error("OpenAI API Error: {$error_msg}", array(
                'product_id' => $product_id,
                'error_type' => $error_type,
                'error_code' => $error_code,
                'error_data' => $data['error']
            ));
            return new WP_Error('openai_error', $error_msg);
        }
        
        if (!isset($data['output']) || !isset($data['output'][0]['content'][0]['text'])) {
			error_log(print_r($data, true));;
            $this->logger->log_error('Invalid OpenAI API response structure', array(
                'product_id' => $product_id,
                'response_data' => $data
            ));
            return new WP_Error('invalid_response', __('Invalid response from OpenAI API.', 'ai-product-review-generator'));
        }
        
        $review_content = trim($data['output'][0]['content'][0]['text']);
        $this->logger->log("Successfully generated review for product: {$product_name}", 'SUCCESS', array(
            'product_id' => $product_id,
            'review_length' => strlen($review_content),
            'tokens_used' => $data['usage'] ?? null
        ));
        
        return $review_content;
    }
    
    private function build_prompt($product, $options) {
        $custom_prompt = get_option('aiprg_custom_prompt', 'Write a realistic product review for {product_title}. Make it sound natural and authentic.');
        
        $replacements = array(
            '{product_title}' => $product->get_name(),
            '{product_description}' => wp_strip_all_tags($product->get_short_description()),
            '{product_price}' => $product->get_price()
        );
        
        $prompt = str_replace(array_keys($replacements), array_values($replacements), $custom_prompt);
        
        if (!empty($options['sentiment'])) {
            $sentiment_text = $this->get_sentiment_instruction($options['sentiment']);
            $prompt .= "\n\n" . $sentiment_text;
        }
        
        if (!empty($options['length'])) {
            $length_text = $this->get_length_instruction($options['length']);
            $prompt .= "\n\n" . $length_text;
        }
        
        $keywords = get_option('aiprg_custom_keywords', '');
        if (!empty($keywords)) {
            $prompt .= "\n\nFocus on these aspects: " . $keywords;
        }
        
        if (!empty($options['rating'])) {
            $prompt .= "\n\nThis should be a " . $options['rating'] . " star review.";
        }
        
        return $prompt;
    }
    
    private function get_sentiment_instruction($sentiment) {
        $instructions = array(
            'positive' => 'Write a positive review highlighting the product\'s strengths and benefits.',
            'negative' => 'Write a critical review mentioning some drawbacks or areas for improvement.',
            'neutral' => 'Write a balanced review discussing both pros and cons objectively.'
        );
        
        return $instructions[$sentiment] ?? '';
    }
    
    private function get_length_instruction($length) {
        $instructions = array(
            'short' => 'Keep the review concise, between 50-100 words.',
            'medium' => 'Write a moderate length review, between 100-200 words.',
            'long' => 'Write a detailed review, between 200-300 words.'
        );
        
        return $instructions[$length] ?? '';
    }
    
    private function get_max_tokens($length) {
        $tokens = array(
            'short' => 150,
            'medium' => 300,
            'long' => 450
        );
        
        return $tokens[$length] ?? 300;
    }
    
    private function get_model_name() {
        // Custom /responses endpoint - using gpt-3.5-turbo-instruct format
        // All models map to gpt-3.5-turbo-instruct for compatibility
        $model_map = array(
            'gpt-4o-mini' => 'gpt-3.5-turbo-instruct',
            'gpt-3.5-turbo' => 'gpt-3.5-turbo-instruct', 
            'gpt-4' => 'gpt-3.5-turbo-instruct',
            'gpt-4-turbo' => 'gpt-3.5-turbo-instruct',
            'chatgpt-4o-latest' => 'gpt-3.5-turbo-instruct'
        );
        
        $model = $model_map[$this->engine] ?? 'gpt-4o-mini';
        $model = 'gpt-4o-mini';

        $this->logger->log("Using custom /responses endpoint with model: {$model}", 'INFO');
        if ($this->engine !== 'gpt-3.5-turbo') {
            $this->logger->log("Note: {$this->engine} mapped to gpt-3.5-turbo-instruct for compatibility", 'INFO');
        }


        return $model;
    }
    
    public function validate_api_key() {
        if (empty($this->api_key)) {
            $this->logger->log('API key validation failed: No API key provided', 'WARNING');
            return false;
        }
        
        $this->logger->log('Validating OpenAI API key', 'INFO');
        
        $endpoint = rtrim($this->api_base_url, '/') . '/models';
        $headers = array(
            'Authorization' => 'Bearer ' . $this->api_key
        );
        
        $response = wp_remote_get($endpoint, array(
            'headers' => $headers,
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log_error("API key validation failed: {$error_message}");
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200) {
            $this->logger->log('API key validation successful', 'SUCCESS');
            return true;
        } else {
            $body = wp_remote_retrieve_body($response);
            $this->logger->log_error("API key validation failed with status code: {$status_code}", array(
                'response_body' => $body
            ));
            return false;
        }
    }
}