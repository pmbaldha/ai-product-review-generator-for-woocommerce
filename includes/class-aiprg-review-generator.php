<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPRG_Review_Generator {
    
    /**
     * The single instance of the class
     * 
     * @var AIPRG_Review_Generator
     */
    private static $instance = null;
    
    private $openai;
    private $logger;
    private $action_scheduler;
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->openai = AIPRG_OpenAI::instance();
        $this->logger = AIPRG_Logger::instance();
        $this->action_scheduler = null; // Lazy load when needed
    }
    
    /**
     * Get the singleton instance of the class
     * 
     * @return AIPRG_Review_Generator
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
    
    /**
     * Get or initialize the action scheduler
     */
    private function get_action_scheduler() {
        if ($this->action_scheduler === null) {
            $this->action_scheduler = AIPRG_Action_Scheduler::instance();
        }
        return $this->action_scheduler;
    }
    
    /**
     * Generate reviews for products - supports both immediate and scheduled processing
     * 
     * @param array $product_ids Array of product IDs
     * @param bool $use_scheduler Whether to use background processing via Action Scheduler
     * @return mixed Batch ID if scheduled, results array if immediate
     */
    public function generate_reviews_for_products($product_ids = array(), $use_scheduler = false) {
        if (empty($product_ids)) {
            $product_ids = $this->get_selected_products();
        }
        
        if (empty($product_ids)) {
            $this->logger->log_error('No products selected for review generation');
            return new WP_Error('no_products', __('No products selected for review generation.', 'ai-product-review-generator-for-woocommerce'));
        }
        
        $this->logger->log('Starting batch review generation', 'INFO', array(
            'product_count' => count($product_ids),
            'product_ids' => $product_ids
        ));
        
        $reviews_per_product = intval(get_option('aiprg_reviews_per_product', 1));
        $sentiments = get_option('aiprg_review_sentiments', array('positive'));
        $sentiment_balance = get_option('aiprg_sentiment_balance', 'balanced');
        $review_length_mode = get_option('aiprg_review_length_mode', 'mixed');
        
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Using date() intentionally for local timezone
        $date_start = get_option('aiprg_date_range_start', date('Y-m-d', strtotime('-30 days')));
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Using date() intentionally for local timezone
        $date_end = get_option('aiprg_date_range_end', date('Y-m-d'));
        
        // If using scheduler, delegate to Action Scheduler
        if ($use_scheduler) {
            $settings = array(
                'reviews_per_product' => $reviews_per_product,
                'sentiments' => $sentiments,
                'sentiment_balance' => $sentiment_balance,
                'review_length_mode' => $review_length_mode,
                'date_start' => $date_start,
                'date_end' => $date_end
            );
            
            $batch_id = $this->get_action_scheduler()->schedule_batch_generation($product_ids, $settings);
            
            if ($batch_id) {
                $this->logger->log('Reviews scheduled for background processing', 'INFO', array(
                    'batch_id' => $batch_id,
                    'product_count' => count($product_ids)
                ));
                
                return array(
                    'scheduled' => true,
                    'batch_id' => $batch_id,
                    'message' => sprintf(
                        /* translators: %d: number of products */
                        __('Review generation scheduled for %d products. Processing in background.', 'ai-product-review-generator-for-woocommerce'),
                        count($product_ids)
                    )
                );
            } else {
                return new WP_Error('scheduling_failed', __('Failed to schedule review generation.', 'ai-product-review-generator-for-woocommerce'));
            }
        }
        
        // Immediate processing (existing code)
        // Extend execution time for immediate processing to handle API delays
        $estimated_time = count($product_ids) * $reviews_per_product * 25; // 20s delay + 5s processing per review
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for long-running review generation process
        @set_time_limit($estimated_time);
        
        $this->logger->log('Review generation settings', 'INFO', array(
            'reviews_per_product' => $reviews_per_product,
            'sentiments' => $sentiments,
            'sentiment_balance' => $sentiment_balance,
            'review_length_mode' => $review_length_mode,
            'date_range' => array('start' => $date_start, 'end' => $date_end),
            'estimated_time' => $estimated_time
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
                    $this->clear_review_caches();
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
                        'message' => __('Failed to save review to database.', 'ai-product-review-generator-for-woocommerce')
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
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for filtering products by category
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
        $rand = wp_rand(1, 100);
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
                return wp_rand(2, 3);
            case 'neutral':
                return wp_rand(3, 4);
            case 'positive':
                return wp_rand(4, 5);
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
            'Drew', 'Rosa', 'Cameron', 'Holly', 'Morgan', 'Pearl', 'Taylor', 'Nina',
            'Abby', 'Adaline', 'Adelaide', 'Adeline', 'Agnes', 'Aisha', 'Alana', 'Alba',
            'Alejandra', 'Alessandra', 'Alexandra', 'Alexandria', 'Alexia', 'Alina', 'Alison',
            'Allegra', 'Allison', 'Alma', 'Amara', 'Amaya', 'Amelie', 'Amira', 'Ana',
            'Anastasia', 'Anaya', 'Andi', 'Angie', 'Anika', 'Anita', 'Ann', 'Annabelle',
            'Annalise', 'Anne', 'Annie', 'Annika', 'Antonia', 'April', 'Arabella', 'Aria',
            'Ariana', 'Ariel', 'Armani', 'Artemis', 'Arya', 'Ashlyn', 'Aspen', 'Astrid',
            'Athena', 'Aubree', 'Audra', 'August', 'Aurelia', 'Averie', 'Ayla', 'Azalea',
            'Bailey', 'Barbara', 'Beatrice', 'Becca', 'Bella', 'Belle', 'Bernice', 'Beth',
            'Bethany', 'Bianca', 'Blair', 'Blake', 'Blanche', 'Bonnie', 'Braelyn', 'Bree',
            'Brenna', 'Briana', 'Bridget', 'Brielle', 'Brigitte', 'Brinley', 'Bristol', 'Brittney',
            'Brynn', 'Cadence', 'Caitlin', 'Caitlyn', 'Callie', 'Cameron', 'Camille', 'Candace',
            'Cara', 'Carina', 'Carla', 'Carly', 'Carmen', 'Carolina', 'Carolyn', 'Carrie',
            'Carter', 'Cassandra', 'Cassidy', 'Catalina', 'Cecelia', 'Cecilia', 'Celeste', 'Celia',
            'Chanel', 'Charity', 'Charlee', 'Charley', 'Charlie', 'Charlize', 'Chelsea', 'Cheyenne',
            'Christina', 'Claire', 'Clara', 'Clarissa', 'Clementine', 'Cleo', 'Colette', 'Collins',
            'Constance', 'Cora', 'Coral', 'Cordelia', 'Corinne', 'Courtney', 'Crystal', 'Cynthia',
            'Dahlia', 'Dakota', 'Dallas', 'Dana', 'Daniela', 'Daniella', 'Daphne', 'Dara',
            'Darcy', 'Daria', 'Dawn', 'Deanna', 'Delia', 'Delilah', 'Demi', 'Denise',
            'Desiree', 'Diamond', 'Diana', 'Diane', 'Dina', 'Dixie', 'Dolores', 'Dominique',
            'Dora', 'Doris', 'Dream', 'Dulce', 'Dylan', 'Eden', 'Edith', 'Eileen',
            'Elaina', 'Elaine', 'Eleanor', 'Elisa', 'Elise', 'Eliza', 'Ellen', 'Ellie',
            'Eloise', 'Elora', 'Elsa', 'Elsie', 'Ember', 'Emely', 'Emerson', 'Emilia',
            'Emmeline', 'Emmy', 'Ensley', 'Erica', 'Erika', 'Erin', 'Esme', 'Esmeralda',
            'Esperanza', 'Estella', 'Estelle', 'Esther', 'Estrella', 'Ethel', 'Eva', 'Evangeline',
            'Eve', 'Evelynn', 'Everleigh', 'Evie', 'Faye', 'Felicity', 'Fernanda', 'Fiona',
            'Flora', 'Florence', 'Frances', 'Francesca', 'Frankie', 'Freya', 'Frida', 'Gabi',
            'Gabriela', 'Gabrielle', 'Gail', 'Galilea', 'Gemma', 'Genevieve', 'Georgia', 'Geraldine',
            'Gia', 'Gianna', 'Gigi', 'Gillian', 'Gina', 'Giovanna', 'Giselle', 'Gladys',
            'Gloria', 'Goldie', 'Grace', 'Gracie', 'Gracelyn', 'Greta', 'Gretchen', 'Guadalupe',
            'Gwen', 'Gwendolyn', 'Hadassah', 'Hailey', 'Haisley', 'Haley', 'Halle', 'Hallie',
            'Hana', 'Hanna', 'Hannah', 'Harley', 'Harlow', 'Harmony', 'Harper', 'Harriet',
            'Hattie', 'Haven', 'Hayden', 'Haylee', 'Hayley', 'Heather', 'Heaven', 'Heidi',
            'Helena', 'Henley', 'Henrietta', 'Hillary', 'Holland', 'Hope', 'Hunter', 'Ida',
            'Iliana', 'Imani', 'India', 'Indigo', 'Ingrid', 'Irene', 'Iris', 'Irma',
            'Isabela', 'Isabella', 'Isabelle', 'Isla', 'Ivy', 'Izabella', 'Jacqueline', 'Jada',
            'Jade', 'Jaelynn', 'Jaida', 'Jaimie', 'Jamie', 'Jana', 'Jane', 'Janelle',
            'Janessa', 'Janice', 'Janine', 'Jasmin', 'Jaycee', 'Jayda', 'Jayden', 'Jayla',
            'Jaylah', 'Jazlyn', 'Jazmin', 'Jean', 'Jeanette', 'Jemma', 'Jenna', 'Jennie',
            'Jenny', 'Jensen', 'Jessa', 'Jessie', 'Jewel', 'Jill', 'Jillian', 'Jo',
            'Joan', 'Joanna', 'Joanne', 'Jocelyn', 'Joelle', 'Johanna', 'Jolene', 'Jolie',
            'Jordan', 'Jordyn', 'Joselyn', 'Josie', 'Journey', 'Joy', 'Joyce', 'Juanita',
            'Judith', 'Judy', 'Julia', 'Juliet', 'Juliette', 'June', 'Juniper', 'Justice',
            'Justine', 'Kaia', 'Kailani', 'Kaitlin', 'Kaitlyn', 'Kali', 'Kaliyah', 'Kallie',
            'Kamila', 'Kamilah', 'Kamryn', 'Kara', 'Karen', 'Karina', 'Karla', 'Karlee',
            'Karly', 'Karma', 'Kasey', 'Kate', 'Katelyn', 'Katharine', 'Katherine', 'Kathleen',
            'Kathryn', 'Kathy', 'Katie', 'Katrina', 'Kay', 'Kaydence', 'Kayla', 'Kaylani',
            'Kaylee', 'Kayleigh', 'Keira', 'Kelly', 'Kelsey', 'Kendall', 'Kendra', 'Kenna',
            'Kennedi', 'Kennedy', 'Kenya', 'Kenzie', 'Keyla', 'Khloe', 'Kiana', 'Kiara',
            'Kiera', 'Kim', 'Kimber', 'Kimberly', 'Kimora', 'Kinley', 'Kira', 'Kirsten',
            'Kora', 'Kori', 'Kristen', 'Kristin', 'Kristina', 'Krystal', 'Kyla', 'Kylee',
            'Kyleigh', 'Kylie', 'Kyra', 'Lacey', 'Laila', 'Lailah', 'Lainey', 'Lana',
            'Landry', 'Laney', 'Lara', 'Larissa', 'Laura', 'Laurel', 'Lauren', 'Lauryn',
            'Layla', 'Laylah', 'Lea', 'Leah', 'Leanna', 'Lee', 'Legacy', 'Leia',
            'Leighton', 'Leila', 'Leilani', 'Lena', 'Lennon', 'Lennox', 'Leona', 'Leslie',
            'Lexi', 'Lexie', 'Leyla', 'Lia', 'Liana', 'Liberty', 'Lila', 'Lilah',
            'Lilian', 'Liliana', 'Lillian', 'Lilliana', 'Lillie', 'Lilly', 'Lily', 'Lilyana',
            'Lina', 'Linda', 'Lindsay', 'Lindsey', 'Lisa', 'Liv', 'Livia', 'Liz',
            'Liza', 'Lizbeth', 'Lizzie', 'Logan', 'Lola', 'London', 'Londyn', 'Lorelai',
            'Lorelei', 'Loretta', 'Lori', 'Lorraine', 'Louisa', 'Louise', 'Lucia', 'Luciana',
            'Lucille', 'Lucy', 'Luella', 'Luisa', 'Luna', 'Lyanna', 'Lydia', 'Lyla',
            'Lylah', 'Lynn', 'Lyra', 'Lyric', 'Mabel', 'Maci', 'Macie', 'Macy',
            'Madalyn', 'Maddison', 'Madeleine', 'Madeline', 'Madelyn', 'Madelynn', 'Madilyn', 'Madilynn',
            'Madison', 'Madisyn', 'Mae', 'Maeve', 'Maggie', 'Magnolia', 'Maia', 'Maisie',
            'Makayla', 'Makenna', 'Makenzie', 'Malani', 'Malaya', 'Malayah', 'Malaysia', 'Maleah',
            'Malia', 'Maliyah', 'Mallory', 'Mara', 'Maren', 'Margaret', 'Margo', 'Margot',
            'Maria', 'Mariah', 'Mariam', 'Mariana', 'Marianna', 'Marie', 'Mariela', 'Marilyn',
            'Marina', 'Marine', 'Marion', 'Marisa', 'Marisol', 'Marissa', 'Marjorie', 'Marla',
            'Marlee', 'Marley', 'Marlowe', 'Martha', 'Mary', 'Maryam', 'Matilda', 'Mattie',
            'Mavis', 'Maxine', 'May', 'Maya', 'Mckenna', 'Mckenzie', 'Mckinley', 'Meadow',
            'Megan', 'Meghan', 'Melanie', 'Melany', 'Melina', 'Melissa', 'Melody', 'Mercedes',
            'Meredith', 'Mia', 'Micah', 'Michaela', 'Michelle', 'Mikaela', 'Mikayla', 'Mila',
            'Milan', 'Milana', 'Milani', 'Milena', 'Miley', 'Millie', 'Mina', 'Mira',
            'Miracle', 'Miranda', 'Miriam', 'Mollie', 'Molly', 'Monica', 'Monroe', 'Morgan',
            'Mya', 'Myah', 'Myla', 'Mylah', 'Myra', 'Nadia', 'Nadine', 'Nala',
            'Nalani', 'Nancy', 'Naomi', 'Naomy', 'Natalia', 'Natalie', 'Nataly', 'Natalya',
            'Natasha', 'Nathalia', 'Nathalie', 'Navy', 'Naya', 'Nayeli', 'Nevaeh', 'Nia',
            'Nichole', 'Nicole', 'Nicolette', 'Nikki', 'Nina', 'Noa', 'Noel', 'Noelle',
            'Noemi', 'Nola', 'Noor', 'Nora', 'Norah', 'Nova', 'Novah', 'Nyla',
            'Nylah', 'Oaklee', 'Oakley', 'Oaklyn', 'Oaklynn', 'Octavia', 'Odette', 'Olive',
            'Olivia', 'Opal', 'Ophelia', 'Paige', 'Paislee', 'Paisley', 'Palmer', 'Paloma',
            'Pamela', 'Paola', 'Paradise', 'Paris', 'Parker', 'Patricia', 'Paula', 'Paulina',
            'Payton', 'Pearl', 'Penelope', 'Penny', 'Perla', 'Peyton', 'Phoebe', 'Phoenix',
            'Piper', 'Poppy', 'Presley', 'Princess', 'Priscilla', 'Promise', 'Quinn', 'Rachel',
            'Raegan', 'Raelyn', 'Raelynn', 'Raina', 'Ramona', 'Raquel', 'Raven', 'Rayna',
            'Rayne', 'Reagan', 'Rebecca', 'Rebekah', 'Reese', 'Reina', 'Remi', 'Remington',
            'Remy', 'Renata', 'Renee', 'Reyna', 'Rhea', 'Rhiannon', 'Riley', 'River',
            'Rivka', 'Robin', 'Robyn', 'Rocio', 'Romina', 'Rory', 'Rosa', 'Rosalee',
            'Rosalie', 'Rosalind', 'Rosalyn', 'Rose', 'Rosemary', 'Rosie', 'Rowan', 'Roxanne',
            'Royal', 'Royalty', 'Ruby', 'Ruth', 'Ryan', 'Ryann', 'Rylee', 'Ryleigh',
            'Rylie', 'Sabrina', 'Sadie', 'Sage', 'Saige', 'Salem', 'Salma', 'Samantha',
            'Samara', 'Samira', 'Sandra', 'Sandy', 'Saoirse', 'Sara', 'Sarah', 'Sarai',
            'Sariah', 'Sasha', 'Savanna', 'Savannah', 'Sawyer', 'Saylor', 'Scarlet', 'Scarlett',
            'Scout', 'Selah', 'Selena', 'Selene', 'Serena', 'Serenity', 'Shania', 'Shannon',
            'Sharon', 'Shayla', 'Shea', 'Sheila', 'Shelby', 'Sherry', 'Shiloh', 'Shirley',
            'Siena', 'Sienna', 'Sierra', 'Simone', 'Sky', 'Skye', 'Skyla', 'Skylar',
            'Skyler', 'Sloan', 'Sloane', 'Sofia', 'Soleil', 'Sonia', 'Sonya', 'Sophia',
            'Sophie', 'Stacy', 'Stella', 'Stephanie', 'Stevie', 'Stormi', 'Summer', 'Sunny',
            'Susan', 'Sutton', 'Suzanne', 'Sydney', 'Sylvia', 'Sylvie', 'Tabitha', 'Talia',
            'Taliyah', 'Tamara', 'Tamera', 'Tamia', 'Tammy', 'Tania', 'Tanya', 'Tara',
            'Taryn', 'Tatiana', 'Tatum', 'Taylor', 'Teagan', 'Teresa', 'Tessa', 'Thalia',
            'Thea', 'Theodora', 'Theresa', 'Tiana', 'Tiffany', 'Tina', 'Tinley', 'Tinsley',
            'Tori', 'Tracy', 'Trinity', 'Trisha', 'Trudy', 'Tuesday', 'Tyler', 'Uma',
            'Unity', 'Ursula', 'Valentina', 'Valeria', 'Valerie', 'Valery', 'Vanessa', 'Veda',
            'Vera', 'Veronica', 'Victoria', 'Vienna', 'Violet', 'Violeta', 'Virginia', 'Vivian',
            'Viviana', 'Vivienne', 'Wanda', 'Waverly', 'Wendy', 'Whitley', 'Whitney', 'Willa',
            'Willow', 'Winter', 'Wren', 'Wynter', 'Ximena', 'Xiomara', 'Yamileth', 'Yara',
            'Yareli', 'Yaretzi', 'Yasmin', 'Yazmin', 'Yolanda', 'Yvette', 'Yvonne', 'Zainab',
            'Zara', 'Zaria', 'Zariah', 'Zariyah', 'Zaylee', 'Zelda', 'Zendaya', 'Zion',
            'Zoe', 'Zoey', 'Zoie', 'Zola', 'Zora', 'Zuri', 'Abdul', 'Abdullah',
            'Abel', 'Abraham', 'Abram', 'Ace', 'Adam', 'Adan', 'Aden', 'Aditya',
            'Adonis', 'Adrian', 'Adriel', 'Adrien', 'Ahmad', 'Ahmed', 'Aidan', 'Aiden',
            'Alan', 'Albert', 'Alberto', 'Alden', 'Aldo', 'Alec', 'Alejandro', 'Alessandro',
            'Alex', 'Alexander', 'Alexis', 'Alfonso', 'Alfred', 'Alfredo', 'Ali', 'Alijah',
            'Alistair', 'Allan', 'Allen', 'Alonso', 'Alonzo', 'Alvaro', 'Alvin', 'Amari',
            'Ambrose', 'Amir', 'Amos', 'Anakin', 'Anders', 'Anderson', 'Andre', 'Andreas',
            'Andres', 'Andrew', 'Andy', 'Angel', 'Angelo', 'Anson', 'Anthony', 'Antoine',
            'Anton', 'Antonio', 'Apollo', 'Archer', 'Archie', 'Ares', 'Ari', 'Ariel',
            'Arjun', 'Arlo', 'Armando', 'Armani', 'Arnav', 'Arnold', 'Arrow', 'Arthur',
            'Arturo', 'Aryan', 'Asa', 'Asher', 'Ashton', 'Atlas', 'Atticus', 'August',
            'Augustine', 'Augustus', 'Austin', 'Avery', 'Axel', 'Axl', 'Axton', 'Ayaan',
            'Ayden', 'Azariah', 'Aziel', 'Baker', 'Banks', 'Barrett', 'Barry', 'Bartholomew',
            'Basil', 'Baxter', 'Bear', 'Beau', 'Beckett', 'Beckham', 'Ben', 'Benedict',
            'Benjamin', 'Bennett', 'Bennie', 'Benny', 'Benson', 'Bentley', 'Bernard', 'Bert',
            'Billy', 'Bjorn', 'Blaine', 'Blair', 'Blake', 'Blaze', 'Bo', 'Bobby',
            'Bode', 'Bodhi', 'Bodie', 'Boone', 'Boris', 'Boston', 'Bowen', 'Boyd',
            'Brad', 'Braden', 'Bradley', 'Brady', 'Braeden', 'Braiden', 'Brandon', 'Branson',
            'Brantley', 'Braxton', 'Brayan', 'Brayden', 'Braydon', 'Braylon', 'Brecken', 'Brendan',
            'Brenden', 'Brennan', 'Brent', 'Brett', 'Brian', 'Briggs', 'Brock', 'Brodie',
            'Brody', 'Bronson', 'Brooks', 'Bruce', 'Bruno', 'Bryan', 'Bryant', 'Bryce',
            'Brycen', 'Bryson', 'Buck', 'Byron', 'Cade', 'Caden', 'Caiden', 'Cain',
            'Caleb', 'Callan', 'Callum', 'Calvin', 'Camden', 'Cameron', 'Camilo', 'Cannon',
            'Carl', 'Carlos', 'Carlton', 'Carmelo', 'Carson', 'Carter', 'Case', 'Casen',
            'Casey', 'Cash', 'Cason', 'Caspian', 'Cassius', 'Castiel', 'Cayden', 'Cayson',
            'Cecil', 'Cedric', 'Cesar', 'Chad', 'Chaim', 'Chance', 'Chandler', 'Charles',
            'Charlie', 'Chase', 'Chester', 'Chris', 'Christian', 'Christopher', 'Cillian', 'Clark',
            'Claude', 'Clay', 'Clayton', 'Clement', 'Clifford', 'Clifton', 'Clint', 'Clinton',
            'Clyde', 'Coby', 'Cody', 'Cohen', 'Colby', 'Cole', 'Coleman', 'Colin',
            'Collin', 'Colson', 'Colt', 'Colten', 'Colton', 'Conner', 'Connor', 'Conrad',
            'Cooper', 'Corbin', 'Corey', 'Cormac', 'Cornelius', 'Cory', 'Craig', 'Crew',
            'Cristian', 'Cristiano', 'Cristopher', 'Crosby', 'Cruz', 'Cullen', 'Curtis', 'Cyrus',
            'Dakota', 'Dale', 'Dallas', 'Dalton', 'Damari', 'Damian', 'Damien', 'Damon',
            'Dan', 'Dane', 'Dangelo', 'Daniel', 'Danny', 'Dante', 'Darian', 'Dariel',
            'Dario', 'Darius', 'Darnell', 'Darrell', 'Darren', 'Darwin', 'Dash', 'Dave',
            'David', 'Davin', 'Davis', 'Davion', 'Dawson', 'Dax', 'Daxton', 'Dayton',
            'Dean', 'Deandre', 'Declan', 'Demetrius', 'Dennis', 'Denver', 'Derek', 'Derrick',
            'Desmond', 'Devin', 'Devon', 'Dexter', 'Diego', 'Dilan', 'Dillon', 'Dimitri',
            'Dion', 'Dominic', 'Dominick', 'Dominik', 'Dominique', 'Don', 'Donald', 'Donovan',
            'Dorian', 'Douglas', 'Drake', 'Drew', 'Duke', 'Duncan', 'Dustin', 'Dwayne',
            'Dylan', 'Earl', 'Easton', 'Eddie', 'Eden', 'Edgar', 'Edison', 'Edmund',
            'Eduardo', 'Edward', 'Edwin', 'Efrain', 'Eli', 'Elian', 'Elias', 'Elijah',
            'Eliseo', 'Elisha', 'Elliot', 'Elliott', 'Ellis', 'Elmer', 'Elon', 'Elvis',
            'Emanuel', 'Emerson', 'Emery', 'Emiliano', 'Emilio', 'Emmanuel', 'Emmett', 'Emmitt',
            'Emory', 'Enoch', 'Enrique', 'Enzo', 'Ephraim', 'Eric', 'Erick', 'Erik',
            'Ernest', 'Ernesto', 'Ernie', 'Ervin', 'Esteban', 'Ethan', 'Eugene', 'Evan',
            'Everett', 'Ezekiel', 'Ezequiel', 'Ezra', 'Fabian', 'Felipe', 'Felix', 'Fernando',
            'Finley', 'Finn', 'Finnegan', 'Fisher', 'Fletcher', 'Floyd', 'Flynn', 'Ford',
            'Forest', 'Forrest', 'Foster', 'Fox', 'Francis', 'Francisco', 'Frank', 'Franklin',
            'Franky', 'Fred', 'Freddie', 'Frederick', 'Gabriel', 'Gael', 'Gage', 'Garrett',
            'Gary', 'Gatlin', 'Gavin', 'Gene', 'Geoffrey', 'George', 'Gerald', 'Gerard',
            'Gerardo', 'German', 'Gianni', 'Gibson', 'Gideon', 'Gilbert', 'Gilberto', 'Gino',
            'Giovanni', 'Glen', 'Glenn', 'Gordon', 'Grady', 'Graham', 'Grant', 'Grayson',
            'Greg', 'Gregory', 'Grey', 'Greyson', 'Griffin', 'Guillermo', 'Gunnar', 'Gunner',
            'Gus', 'Gustavo', 'Guy', 'Haden', 'Hadley', 'Hakeem', 'Hamza', 'Hank',
            'Hans', 'Harlan', 'Harley', 'Harold', 'Harris', 'Harrison', 'Harry', 'Harvey',
            'Hassan', 'Hayden', 'Hayes', 'Heath', 'Hector', 'Hendrix', 'Henrik', 'Henry',
            'Herbert', 'Herman', 'Hezekiah', 'Holden', 'Homer', 'Horace', 'Houston', 'Howard',
            'Hudson', 'Hugh', 'Hugo', 'Humberto', 'Hunter', 'Huxley', 'Ian', 'Ibrahim',
            'Idris', 'Ignacio', 'Iker', 'Immanuel', 'Indiana', 'Ira', 'Irvin', 'Irving',
            'Isaac', 'Isaiah', 'Isaias', 'Ishaan', 'Ismael', 'Israel', 'Issac', 'Ivan',
            'Izaiah', 'Jabari', 'Jace', 'Jack', 'Jackson', 'Jacob', 'Jacoby', 'Jad',
            'Jaden', 'Jadiel', 'Jagger', 'Jaiden', 'Jaime', 'Jair', 'Jairo', 'Jake',
            'Jakob', 'Jalen', 'Jamal', 'Jamari', 'James', 'Jameson', 'Jamie', 'Jamir',
            'Jamison', 'Jared', 'Jarvis', 'Jase', 'Jasiah', 'Jason', 'Jasper', 'Javier',
            'Javion', 'Jax', 'Jaxen', 'Jaxon', 'Jaxson', 'Jay', 'Jayce', 'Jayceon',
            'Jayden', 'Jaylen', 'Jayson', 'Jaziel', 'Jean', 'Jedidiah', 'Jeff', 'Jefferson',
            'Jeffery', 'Jeffrey', 'Jensen', 'Jeremiah', 'Jeremy', 'Jericho', 'Jermaine', 'Jerome',
            'Jerry', 'Jesse', 'Jessie', 'Jesus', 'Jett', 'Jimmy', 'Jin', 'Joaquin',
            'Job', 'Jody', 'Joe', 'Joel', 'Joey', 'Johan', 'Johann', 'John',
            'Johnathan', 'Johnathon', 'Johnny', 'Jon', 'Jonah', 'Jonas', 'Jonathan', 'Jonathon',
            'Jones', 'Jordan', 'Jordy', 'Jorge', 'Jose', 'Josef', 'Joseph', 'Josh',
            'Joshua', 'Josiah', 'Josue', 'Jovan', 'Jovani', 'Juan', 'Judah', 'Jude',
            'Judson', 'Julian', 'Julien', 'Julio', 'Julius', 'Junior', 'Justice', 'Justin',
            'Justus', 'Kace', 'Kade', 'Kaden', 'Kai', 'Kaiden', 'Kale', 'Kaleb',
            'Kamari', 'Kamden', 'Kameron', 'Kamryn', 'Kane', 'Kannon', 'Kareem', 'Karl',
            'Karson', 'Karter', 'Kase', 'Kasen', 'Kash', 'Kashton', 'Kason', 'Kayden',
            'Kayson', 'Keanu', 'Keaton', 'Keegan', 'Keenan', 'Keith', 'Kellan', 'Kellen',
            'Kelvin', 'Kendall', 'Kendrick', 'Kenneth', 'Kenny', 'Kent', 'Kenton', 'Kenya',
            'Kenzo', 'Keon', 'Keoni', 'Kermit', 'Kevin', 'Khalid', 'Khalil', 'Kian',
            'Kieran', 'Kieren', 'Killian', 'Kim', 'King', 'Kingston', 'Kirk', 'Klaus',
            'Klay', 'Knox', 'Koa', 'Kobe', 'Koda', 'Kody', 'Kohen', 'Kole',
            'Kolten', 'Kolton', 'Korbin', 'Krew', 'Kristian', 'Kristopher', 'Kurt', 'Kurtis',
            'Kylan', 'Kyle', 'Kyler', 'Kylian', 'Kyrie', 'Kyson', 'Lachlan', 'Lake',
            'Lance', 'Landen', 'Landon', 'Landry', 'Lane', 'Larry', 'Lars', 'Laurence',
            'Lawrence', 'Lawson', 'Layne', 'Layton', 'Leandro', 'Ledger', 'Lee', 'Legacy',
            'Legend', 'Leif', 'Leigh', 'Leland', 'Lennon', 'Lennox', 'Leo', 'Leon',
            'Leonard', 'Leonardo', 'Leonel', 'Leonidas', 'Leopold', 'Leroy', 'Leslie', 'Lester',
            'Levi', 'Lewis', 'Liam', 'Lincoln', 'Linden', 'Lionel', 'Lloyd', 'Lochlan',
            'Logan', 'London', 'Lorenzo', 'Louie', 'Louis', 'Luca', 'Lucas', 'Lucian',
            'Luciano', 'Lucky', 'Luis', 'Luka', 'Lukas', 'Luke', 'Luther', 'Lyric',
            'Mac', 'Mack', 'Mackenzie', 'Maddox', 'Magnus', 'Maison', 'Major', 'Makai',
            'Malachi', 'Malakai', 'Malcolm', 'Malik', 'Manuel', 'Marc', 'Marcel', 'Marcelo',
            'Marco', 'Marcos', 'Marcus', 'Mario', 'Marion', 'Mark', 'Marley', 'Marlon',
            'Marshall', 'Martin', 'Marvin', 'Mason', 'Mateo', 'Mathew', 'Mathias', 'Matias',
            'Matt', 'Matteo', 'Matthew', 'Matthias', 'Maurice', 'Mauricio', 'Maverick', 'Max',
            'Maxim', 'Maximilian', 'Maximiliano', 'Maximo', 'Maximus', 'Maxwell', 'Mekhi', 'Melvin',
            'Memphis', 'Merrick', 'Messiah', 'Micah', 'Michael', 'Mickey', 'Miguel', 'Mike',
            'Milan', 'Miles', 'Miller', 'Milo', 'Milton', 'Misael', 'Mitchell', 'Mohamed',
            'Mohammad', 'Mohammed', 'Moises', 'Montana', 'Montgomery', 'Monty', 'Morgan', 'Morris',
            'Moses', 'Muhammad', 'Murphy', 'Murray', 'Myles', 'Mylo', 'Nash', 'Nasir',
            'Nathan', 'Nathanael', 'Nathaniel', 'Naveen', 'Neal', 'Neil', 'Nelson', 'Neville',
            'Nicholas', 'Nick', 'Nico', 'Nicolas', 'Nigel', 'Nikhil', 'Nikolas', 'Niko',
            'Nikolai', 'Nixon', 'Noah', 'Noe', 'Noel', 'Nolan', 'Norman', 'Nova',
            'Oakley', 'Ocean', 'Octavio', 'Oden', 'Odin', 'Oliver', 'Ollie', 'Omar',
            'Omari', 'Onyx', 'Orion', 'Orlando', 'Oscar', 'Oswald', 'Otis', 'Otto',
            'Owen', 'Pablo', 'Palmer', 'Paolo', 'Paris', 'Parker', 'Pascal', 'Patrick',
            'Paul', 'Paxton', 'Payton', 'Pedro', 'Percy', 'Perry', 'Pete', 'Peter',
            'Peyton', 'Philip', 'Phillip', 'Phoenix', 'Pierce', 'Pierre', 'Porter', 'Prasad',
            'Preston', 'Prince', 'Princeton', 'Quentin', 'Quest', 'Quincy', 'Quinn', 'Quinton',
            'Radley', 'Rafael', 'Rage', 'Raiden', 'Ralph', 'Ramon', 'Ramsey', 'Randy',
            'Ranger', 'Raphael', 'Rashad', 'Raul', 'Raven', 'Ray', 'Rayan', 'Raymond',
            'Rayyan', 'Reagan', 'Reed', 'Reese', 'Reginald', 'Reid', 'Reign', 'Remington',
            'Remy', 'Rene', 'Reuben', 'Rex', 'Rey', 'Reyan', 'Rhett', 'Rhys',
            'Ricardo', 'Richard', 'Ricky', 'Rico', 'Ridge', 'Ridley', 'Riley', 'River',
            'Robert', 'Roberto', 'Robin', 'Rocco', 'Rocky', 'Roderick', 'Rodrigo', 'Rodney',
            'Roger', 'Rohan', 'Roland', 'Roman', 'Rome', 'Romeo', 'Ronald', 'Ronan',
            'Ronin', 'Ronnie', 'Rory', 'Ross', 'Rowan', 'Roy', 'Royal', 'Royce',
            'Ruben', 'Rudy', 'Russell', 'Ryan', 'Ryder', 'Ryker', 'Rylan', 'Ryland',
            'Rylee', 'Sage', 'Saint', 'Salem', 'Salvador', 'Salvatore', 'Sam', 'Samir',
            'Samson', 'Samuel', 'Sander', 'Santana', 'Santiago', 'Santos', 'Saul', 'Sawyer',
            'Scott', 'Sean', 'Sebastian', 'Sergio', 'Seth', 'Shamus', 'Shane', 'Shannon',
            'Shaun', 'Shawn', 'Shea', 'Sheldon', 'Shepherd', 'Sherman', 'Shiloh', 'Sidney',
            'Silas', 'Simeon', 'Simon', 'Sincere', 'Skylar', 'Skyler', 'Solomon', 'Sonny',
            'Soren', 'Spencer', 'Stanley', 'Stefan', 'Stephen', 'Sterling', 'Steve', 'Steven',
            'Stone', 'Stuart', 'Sullivan', 'Sutton', 'Sylas', 'Sylvester', 'Tadeo', 'Talon',
            'Tanner', 'Tate', 'Tatum', 'Taylor', 'Ted', 'Teddy', 'Terrance', 'Terrell',
            'Terrence', 'Terry', 'Thaddeus', 'Thatcher', 'Theo', 'Theodore', 'Theron', 'Thomas',
            'Thor', 'Tiberius', 'Tim', 'Timothy', 'Titan', 'Titus', 'Tobias', 'Toby',
            'Todd', 'Tom', 'Tomas', 'Tommy', 'Tony', 'Trace', 'Travis', 'Trent',
            'Trenton', 'Trevor', 'Trey', 'Tripp', 'Tristan', 'Tristen', 'Tristian', 'Troy',
            'Tucker', 'Ty', 'Tyler', 'Tyree', 'Tyrell', 'Tyrone', 'Tyson', 'Ulises',
            'Ulysses', 'Uriah', 'Uriel', 'Valentin', 'Valentine', 'Valentino', 'Van', 'Vance',
            'Vaughn', 'Vernon', 'Vicente', 'Victor', 'Vihaan', 'Vincent', 'Vincenzo', 'Wade',
            'Walker', 'Wallace', 'Walter', 'Warren', 'Watson', 'Waylon', 'Wayne', 'Wells',
            'Wes', 'Wesley', 'Weston', 'Wilder', 'Wiley', 'Will', 'William', 'Willie',
            'Willis', 'Wilson', 'Winston', 'Wyatt', 'Wylie', 'Xander', 'Xavier', 'Xzavier',
            'Yahir', 'Yehuda', 'Yosef', 'Yusuf', 'Zachariah', 'Zachary', 'Zack', 'Zaid',
            'Zaiden', 'Zain', 'Zaire', 'Zakai', 'Zander', 'Zane', 'Zavier', 'Zayn',
            'Zayne', 'Zayden', 'Zechariah', 'Zeke', 'Zion', 'Zyaire'
        );
        
        $last_initials = array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'L', 'M', 'N', 'P', 'R', 'S', 'T', 'W');
        
        return $first_names[array_rand($first_names)] . ' ' . $last_initials[array_rand($last_initials)] . '.';
    }
    
    private function generate_reviewer_email($name) {
        $name_parts = explode(' ', strtolower($name));
        $username = $name_parts[0] . wp_rand(100, 999);
        $domains = array('gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com');
        
        return $username . '@' . $domains[array_rand($domains)];
    }
    
    private function get_random_date($start, $end) {
        $start_timestamp = strtotime($start);
        $end_timestamp = strtotime($end);
        
        $random_timestamp = wp_rand($start_timestamp, $end_timestamp);
        
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Using date() intentionally for local timezone
        return date('Y-m-d H:i:s', $random_timestamp);
    }
    
    private function update_product_rating($product_id) {
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
}