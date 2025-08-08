<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPRG_Review_Generator {
    
    private $openai;
    private $logger;
    
    public function __construct() {
        $this->openai = new AIPRG_OpenAI();
        $this->logger = AIPRG_Logger::instance();
    }
    
    public function generate_reviews_for_products($product_ids = array()) {
        if (empty($product_ids)) {
            $product_ids = $this->get_selected_products();
        }
        
        if (empty($product_ids)) {
            $this->logger->log_error('No products selected for review generation');
            return new WP_Error('no_products', __('No products selected for review generation.', 'ai-product-review-generator'));
        }
        
        $this->logger->log('Starting batch review generation', 'INFO', array(
            'product_count' => count($product_ids),
            'product_ids' => $product_ids
        ));
        
        $reviews_per_product = intval(get_option('aiprg_reviews_per_product', 5));
        $sentiments = get_option('aiprg_review_sentiments', array('positive'));
        $sentiment_balance = get_option('aiprg_sentiment_balance', 'balanced');
        $review_length_mode = get_option('aiprg_review_length_mode', 'mixed');
        
        $date_start = get_option('aiprg_date_range_start', date('Y-m-d', strtotime('-30 days')));
        $date_end = get_option('aiprg_date_range_end', date('Y-m-d'));
        
        $this->logger->log('Review generation settings', 'INFO', array(
            'reviews_per_product' => $reviews_per_product,
            'sentiments' => $sentiments,
            'sentiment_balance' => $sentiment_balance,
            'review_length_mode' => $review_length_mode,
            'date_range' => array('start' => $date_start, 'end' => $date_end)
        ));
        
        $results = array(
            'success' => 0,
            'failed' => 0,
            'products' => array()
        );
        
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                $this->logger->log_error("Product not found: ID {$product_id}");
                $results['failed']++;
                continue;
            }
            
            $product_results = array(
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'reviews' => array()
            );
            
            for ($i = 0; $i < $reviews_per_product; $i++) {
                $sentiment = $this->get_random_sentiment($sentiments, $sentiment_balance);
                $rating = $this->get_rating_from_sentiment($sentiment);
                $length = $this->get_review_length($review_length_mode);
                
                $options = array(
                    'sentiment' => $sentiment,
                    'rating' => $rating,
                    'length' => $length
                );
                
                $review_content = $this->openai->generate_review($product, $options);
                
                if (is_wp_error($review_content)) {
                    $error_msg = $review_content->get_error_message();
                    $this->logger->log_review_generation($product_id, $product->get_name(), 'FAILED', array(
                        'error' => $error_msg,
                        'review_number' => $i + 1,
                        'options' => $options
                    ));
                    $results['failed']++;
                    $product_results['reviews'][] = array(
                        'status' => 'error',
                        'message' => $error_msg
                    );
                    continue;
                }
                
                $reviewer_name = $this->generate_reviewer_name();
                $reviewer_email = $this->generate_reviewer_email($reviewer_name);
                $review_date = $this->get_random_date($date_start, $date_end);
                
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
                    'comment_agent' => 'AI Product Review Generator',
                    'comment_date' => $review_date,
                    'comment_approved' => 1
                );
                
                $comment_id = wp_insert_comment($comment_data);
                
                if ($comment_id) {
                    update_comment_meta($comment_id, 'rating', $rating);
                    update_comment_meta($comment_id, 'verified', 0);
                    update_comment_meta($comment_id, 'aiprg_generated', 1);
                    
                    $this->logger->log_review_generation($product_id, $product->get_name(), 'SUCCESS', array(
                        'comment_id' => $comment_id,
                        'rating' => $rating,
                        'reviewer' => $reviewer_name,
                        'review_number' => $i + 1,
                        'sentiment' => $sentiment
                    ));
                    
                    $results['success']++;
                    $product_results['reviews'][] = array(
                        'status' => 'success',
                        'comment_id' => $comment_id,
                        'rating' => $rating,
                        'reviewer' => $reviewer_name
                    );
                    
                    $this->update_product_rating($product_id);
                } else {
                    $this->logger->log_error('Failed to save review to database', array(
                        'product_id' => $product_id,
                        'product_name' => $product->get_name(),
                        'review_number' => $i + 1,
                        'comment_data' => $comment_data
                    ));
                    
                    $results['failed']++;
                    $product_results['reviews'][] = array(
                        'status' => 'error',
                        'message' => __('Failed to save review to database.', 'ai-product-review-generator')
                    );
                }
            }
            
            $results['products'][] = $product_results;
        }
        
        // Log final summary
        $this->logger->log('Batch review generation completed', 'INFO', array(
            'total_success' => $results['success'],
            'total_failed' => $results['failed'],
            'products_processed' => count($results['products']),
            'summary' => array_map(function($p) {
                return array(
                    'product_id' => $p['product_id'],
                    'product_name' => $p['product_name'],
                    'reviews_generated' => count(array_filter($p['reviews'], function($r) {
                        return $r['status'] === 'success';
                    }))
                );
            }, $results['products'])
        ));
        
        return $results;
    }
    
    public function get_selected_products() {
        $product_ids = get_option('aiprg_select_products', array());
        $category_ids = get_option('aiprg_select_categories', array());
        
        // Handle both new multi-select and old single select for backwards compatibility
        if (empty($category_ids)) {
            $old_category = get_option('aiprg_select_category', '');
            if (!empty($old_category)) {
                $category_ids = array($old_category);
            }
        }
        
        if (!empty($category_ids) && is_array($category_ids)) {
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $category_ids,
                        'operator' => 'IN'
                    )
                ),
                'fields' => 'ids'
            );
            
            $query = new WP_Query($args);
            $category_products = $query->posts;
            
            $product_ids = array_unique(array_merge($product_ids, $category_products));
        }
        
        return $product_ids;
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
            'Robert', 'Jennifer', 'William', 'Lisa', 'Richard', 'Karen', 'Joseph', 'Nancy',
            'Thomas', 'Betty', 'Charles', 'Helen', 'Christopher', 'Sandra', 'Daniel', 'Donna',
            'Matthew', 'Carol', 'Anthony', 'Ruth', 'Mark', 'Sharon', 'Donald', 'Michelle',
            'Paul', 'Laura', 'Steven', 'Sarah', 'Andrew', 'Kimberly', 'Kenneth', 'Deborah',
            'Brian', 'Amy', 'George', 'Maria', 'Edward', 'Susan', 'Ronald', 'Dorothy',
            'Timothy', 'Rebecca', 'Jason', 'Ashley', 'Jeffrey', 'Stephanie', 'Ryan', 'Nicole',
            'Jacob', 'Elizabeth', 'Gary', 'Brenda', 'Nicholas', 'Catherine', 'Eric', 'Victoria',
            'Jonathan', 'Christina', 'Stephen', 'Janet', 'Larry', 'Alice', 'Justin', 'Rachel',
            'Scott', 'Martha', 'Benjamin', 'Debra', 'Samuel', 'Emma', 'Frank', 'Marie',
            'Gregory', 'Julie', 'Raymond', 'Joyce', 'Alexander', 'Grace', 'Patrick', 'Hannah',
            'Jack', 'Olivia', 'Dennis', 'Diana', 'Jerry', 'Amanda', 'Tyler', 'Melissa',
            'Aaron', 'Cheryl', 'Jose', 'Anna', 'Henry', 'Megan', 'Douglas', 'Andrea',
            'Nathan', 'Teresa', 'Peter', 'Gloria', 'Adam', 'Sara', 'Zachary', 'Frances',
            'Kyle', 'Christine', 'Walter', 'Samantha', 'Harold', 'Angela', 'Carl', 'Katherine',
            'Jordan', 'Judith', 'Albert', 'Rose', 'Willie', 'Evelyn', 'Austin', 'Tiffany',
            'Sean', 'Denise', 'Gerald', 'Julia', 'Ethan', 'Amber', 'Eugene', 'Theresa',
            'Ralph', 'Beverly', 'Roy', 'Danielle', 'Russell', 'Marilyn', 'Bruce', 'Charlotte',
            'Randy', 'Sophia', 'Vincent', 'Isabella', 'Mason', 'Alexis', 'Roy', 'Kayla',
            'Norman', 'Brittany', 'Philip', 'Madison', 'Joel', 'Lori', 'Louis', 'Jasmine',
            'Oliver', 'Ava', 'Lucas', 'Mia', 'Liam', 'Harper', 'Noah', 'Amelia',
            'Elijah', 'Ella', 'Logan', 'Scarlett', 'Aiden', 'Chloe', 'Sebastian', 'Lily',
            'Carter', 'Zoe', 'Jackson', 'Layla', 'Wyatt', 'Natalie', 'Owen', 'Haley',
            'Dylan', 'Luna', 'Luke', 'Savannah', 'Gabriel', 'Audrey', 'Isaac', 'Brooklyn',
            'Hunter', 'Bella', 'Cameron', 'Paisley', 'Leo', 'Clara', 'Max', 'Caroline',
            'Julian', 'Nova', 'Colton', 'Genesis', 'Blake', 'Emilia', 'Adrian', 'Everly',
            'Jaxon', 'Autumn', 'Evan', 'Quinn', 'Miles', 'Piper', 'Nolan', 'Ruby',
            'Easton', 'Serenity', 'Cole', 'Willow', 'Landon', 'Kinsley', 'Grayson', 'Naomi',
            'Caleb', 'Eliana', 'Levi', 'Aubrey', 'Ian', 'Camila', 'Connor', 'Avery',
            'Eli', 'Abigail', 'Carson', 'Kaylee', 'Theodore', 'Penelope', 'Jasper', 'Arianna',
            'Xavier', 'Nora', 'Dominic', 'Makayla', 'Jace', 'Ellie', 'Lincoln', 'Aaliyah',
            'Hudson', 'Claire', 'Asher', 'Violet', 'River', 'Leilani', 'Rowan', 'Stella',
            'Felix', 'Hazel', 'August', 'Aurora', 'Ezra', 'Emery', 'Silas', 'Hadley',
            'Beckett', 'Kinley', 'Kai', 'Maya', 'Parker', 'Elena', 'Jude', 'Morgan',
            'Finn', 'Brianna', 'Bennett', 'Kennedy', 'Emmett', 'Valentina', 'Milo', 'Adalynn',
            'Graham', 'Lydia', 'Harrison', 'Peyton', 'Reid', 'Melanie', 'Spencer', 'Mackenzie',
            'Wesley', 'Faith', 'Bryce', 'Josephine', 'Trent', 'Isabel', 'Chad', 'Daisy',
            'Shane', 'Paige', 'Travis', 'Sienna', 'Garrett', 'Alaina', 'Keith', 'Brooke',
            'Marcus', 'Trinity', 'Derrick', 'Sydney', 'Preston', 'Lauren', 'Ivan', 'Vanessa',
            'Oscar', 'Adriana', 'Ricardo', 'Juliana', 'Alan', 'Destiny', 'Juan', 'Gabriella',
            'Diego', 'Monica', 'Leonardo', 'Liliana', 'Angel', 'Yasmin', 'Miguel', 'Veronica',
            'Carlos', 'Selena', 'Fernando', 'Valeria', 'Marco', 'Mariana', 'Rafael', 'Regina',
            'Omar', 'Beatriz', 'Luis', 'Alicia', 'Sergio', 'Miranda', 'Roberto', 'Claudia',
            'Pedro', 'Raquel', 'Jamie', 'Sofia', 'Casey', 'Iris', 'Robin', 'Jade',
            'Drew', 'Rosa', 'Cameron', 'Holly', 'Morgan', 'Pearl', 'Taylor', 'Nina'
        );
        
        $last_initials = array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'L', 'M', 'N', 'P', 'R', 'S', 'T', 'W');
        
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