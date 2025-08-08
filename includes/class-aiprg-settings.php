<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPRG_Settings {
    
    public function __construct() {
        // Removed WooCommerce tab registration - now handled in AIPRG_Admin
        add_action('wp_ajax_aiprg_validate_api_key', array($this, 'ajax_validate_api_key'));
        // WooCommerce already handles product search, no need to override
    }
    
    // Removed add_settings_tab and settings_tab methods - now handled in AIPRG_Admin
    
    public function update_settings() {
        woocommerce_update_options($this->get_settings());
        $this->save_custom_fields();
    }
    
    public function get_settings() {
        $settings = array(
            'section_title' => array(
                'name' => __('AI Product Reviews Settings', 'ai-product-review-generator'),
                'type' => 'title',
                'desc' => __('Configure the settings for generating AI product reviews below.', 'ai-product-review-generator'),
                'id'   => 'aiprg_section_title'
            ),
            'openai_api_key' => array(
                'name' => __('OpenAI API Key', 'ai-product-review-generator'),
                'type' => 'text',
                'desc' => __('Enter your OpenAI API key. You can obtain your API key from OpenAI\'s platform.', 'ai-product-review-generator') . '<br><button type="button" id="aiprg-validate-api-key" class="button button-secondary" style="margin-top: 10px;">' . __('Validate API Key', 'ai-product-review-generator') . '</button><span id="aiprg-validation-result" style="margin-left: 10px;"></span>',
                'id'   => 'aiprg_openai_api_key',
                'placeholder' => __('sk-...', 'ai-product-review-generator'),
                'css' => 'width: 500px; max-width: 100%; font-family: monospace;',
                'custom_attributes' => array(
                    'autocomplete' => 'off',
                    'spellcheck' => 'false'
                )
            ),
            'openai_engine' => array(
                'name' => __('OpenAI Model', 'ai-product-review-generator'),
                'type' => 'select',
                'desc' => __('Using custom /responses endpoint - All models use gpt-3.5-turbo-instruct format.', 'ai-product-review-generator'),
                'id'   => 'aiprg_openai_engine',
                'options' => array(
                    'gpt-4o-mini' => __('GPT-4o Mini (Maps to GPT-3.5 Turbo Instruct)', 'ai-product-review-generator'),
                    'gpt-3.5-turbo' => __('GPT-3.5 Turbo Instruct (Recommended)', 'ai-product-review-generator'),
                    'gpt-4-turbo' => __('GPT-4 Turbo (Maps to GPT-3.5 Turbo Instruct)', 'ai-product-review-generator'),
                    'gpt-4' => __('GPT-4 (Maps to GPT-3.5 Turbo Instruct)', 'ai-product-review-generator')
                ),
                'default' => 'gpt-3.5-turbo'
            ),
            'reviews_per_product' => array(
                'name' => __('Number of Reviews per Product', 'ai-product-review-generator'),
                'type' => 'number',
                'desc' => __('Specify how many reviews to generate for each product.', 'ai-product-review-generator'),
                'id'   => 'aiprg_reviews_per_product',
                'placeholder' => __('5', 'ai-product-review-generator'),
                'default' => '5',
                'custom_attributes' => array(
                    'min'  => '1',
                    'max'  => '50'
                )
            ),
            'custom_prompt' => array(
                'name' => __('Custom Review Prompt', 'ai-product-review-generator'),
                'type' => 'textarea',
                'desc' => __('<div class="aiprg-prompt-help">
                    <strong>Available placeholders:</strong>
                    <ul style="margin: 5px 0; padding-left: 20px;">
                        <li><code>{product_title}</code> - Product name</li>
                        <li><code>{product_description}</code> - Product short description</li>
                        <li><code>{product_price}</code> - Product price</li>
                    </ul>
                    <div class="aiprg-char-counter" style="margin-top: 5px; color: #666;">
                        <span id="aiprg-prompt-char-count">0</span> / 500 characters
                    </div>
                </div>', 'ai-product-review-generator'),
                'id'   => 'aiprg_custom_prompt',
                'placeholder' => __('Write a realistic and authentic product review for {product_title}. The product is priced at {product_price}. Consider the following description: {product_description}', 'ai-product-review-generator'),
                'css' => 'width:100%; height: 120px; font-family: monospace; padding: 10px; border: 1px solid #ddd; border-radius: 4px;',
                'custom_attributes' => array(
                    'maxlength' => '500',
                    'data-show-counter' => 'true'
                )
            ),
            'review_length_mode' => array(
                'name' => __('Review Length Mode', 'ai-product-review-generator'),
                'type' => 'select',
                'desc' => __('Choose the length of the reviews.', 'ai-product-review-generator'),
                'id'   => 'aiprg_review_length_mode',
                'options' => array(
                    'short' => __('Short (50-100 words)', 'ai-product-review-generator'),
                    'medium' => __('Medium (100-200 words)', 'ai-product-review-generator'),
                    'long' => __('Long (200-300 words)', 'ai-product-review-generator'),
                    'mixed' => __('Mixed (Random length)', 'ai-product-review-generator')
                ),
                'default' => 'mixed'
            ),
            'sentiment_balance' => array(
                'name' => __('Review Sentiment Balance', 'ai-product-review-generator'),
                'type' => 'select',
                'desc' => __('Balance of sentiment distribution in generated reviews.', 'ai-product-review-generator'),
                'id'   => 'aiprg_sentiment_balance',
                'options' => array(
                    'balanced' => __('Balanced (Equal distribution)', 'ai-product-review-generator'),
                    'mostly_positive' => __('Mostly Positive (70% positive)', 'ai-product-review-generator'),
                    'overwhelmingly_positive' => __('Overwhelmingly Positive (90% positive)', 'ai-product-review-generator'),
                    'realistic' => __('Realistic (60% positive, 30% neutral, 10% negative)', 'ai-product-review-generator')
                ),
                'default' => 'balanced'
            ),
            'custom_keywords' => array(
                'name' => __('Custom Keywords / Focus Areas', 'ai-product-review-generator'),
                'type' => 'textarea',
                'desc' => __('<div class="aiprg-keywords-help">
                    <span style="color: #666;">Enter keywords or aspects for the AI to focus on (comma-separated)</span>
                    <div style="margin-top: 5px;">
                        <span class="aiprg-tag-example" style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; margin-right: 5px; font-size: 12px;">quality</span>
                        <span class="aiprg-tag-example" style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; margin-right: 5px; font-size: 12px;">durability</span>
                        <span class="aiprg-tag-example" style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; margin-right: 5px; font-size: 12px;">value</span>
                        <span class="aiprg-tag-example" style="display: inline-block; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; margin-right: 5px; font-size: 12px;">shipping</span>
                    </div>
                </div>', 'ai-product-review-generator'),
                'id'   => 'aiprg_custom_keywords',
                'placeholder' => __('quality, customer service, packaging, value for money, durability, ease of use', 'ai-product-review-generator'),
                'css' => 'width:100%; height: 80px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 10px; border: 1px solid #ddd; border-radius: 4px; resize: vertical;'
            ),
            'enable_logging' => array(
                'name' => __('Enable Debug Logging', 'ai-product-review-generator'),
                'type' => 'checkbox',
                'desc' => __('Check to enable detailed logging for debugging purposes. Logs are stored in wp-content/uploads/aiprg-logs/', 'ai-product-review-generator'),
                'id'   => 'aiprg_enable_logging',
                'default' => 'yes',
                'desc_tip' => true
            ),
            'section_end' => array(
                'type' => 'sectionend',
                'id' => 'aiprg_section_end'
            )
        );
        
        return apply_filters('aiprg_settings', $settings);
    }
    
    public function render_custom_fields() {
        ?>
        <table class="form-table">
            <tbody>
                <tr valign="top">
                    <th scope="row" class="titledesc">
                        <label for="aiprg_select_products"><?php esc_html_e('Select Products', 'ai-product-review-generator'); ?></label>
                    </th>
                    <td class="forminp">
                        <select class="wc-product-search" multiple="multiple" style="width: 50%;" id="aiprg_select_products" name="aiprg_select_products[]" data-placeholder="<?php esc_attr_e('Search for products...', 'ai-product-review-generator'); ?>">
                            <?php
                            $product_ids = get_option('aiprg_select_products', array());
                            if (!empty($product_ids)) {
                                foreach ($product_ids as $product_id) {
                                    $product = wc_get_product($product_id);
                                    if ($product) {
                                        echo '<option value="' . esc_attr($product_id) . '" selected="selected">' . wp_kses_post($product->get_formatted_name()) . '</option>';
                                    }
                                }
                            }
                            ?>
                        </select>
                        <p class="description"><?php esc_html_e('Manually select specific products for review generation.', 'ai-product-review-generator'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row" class="titledesc">
                        <label for="aiprg_select_categories"><?php esc_html_e('Select Product Categories', 'ai-product-review-generator'); ?></label>
                    </th>
                    <td class="forminp">
                        <select id="aiprg_select_categories" name="aiprg_select_categories[]" class="wc-category-search" multiple="multiple" style="width: 50%;" data-placeholder="<?php esc_attr_e('Search for categories...', 'ai-product-review-generator'); ?>">
                            <?php
                            $categories = get_terms('product_cat', array('hide_empty' => false));
                            $selected_categories = get_option('aiprg_select_categories', array());
                            if (!is_array($selected_categories)) {
                                $selected_categories = array($selected_categories);
                            }
                            foreach ($categories as $category) {
                                $selected = in_array($category->term_id, $selected_categories) ? 'selected="selected"' : '';
                                echo '<option value="' . esc_attr($category->term_id) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
                            }
                            ?>
                        </select>
                        <p class="description"><?php esc_html_e('Choose product categories to generate reviews for all products within those categories. You can select multiple categories.', 'ai-product-review-generator'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row" class="titledesc">
                        <label for="aiprg_date_range"><?php esc_html_e('Review Date Range', 'ai-product-review-generator'); ?></label>
                    </th>
                    <td class="forminp">
                        <input type="date" id="aiprg_date_range_start" name="aiprg_date_range_start" value="<?php echo esc_attr(get_option('aiprg_date_range_start', date('Y-m-d', strtotime('-30 days')))); ?>" />
                        <span> <?php esc_html_e('to', 'ai-product-review-generator'); ?> </span>
                        <input type="date" id="aiprg_date_range_end" name="aiprg_date_range_end" value="<?php echo esc_attr(get_option('aiprg_date_range_end', date('Y-m-d'))); ?>" />
                        <p class="description"><?php esc_html_e('Define a date range for the review timestamps.', 'ai-product-review-generator'); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row" class="titledesc">
                        <label for="aiprg_review_sentiments"><?php esc_html_e('Review Sentiments', 'ai-product-review-generator'); ?></label>
                    </th>
                    <td class="forminp">
                        <?php
                        $sentiments = get_option('aiprg_review_sentiments', array('positive'));
                        ?>
                        <label><input type="checkbox" name="aiprg_review_sentiments[]" value="negative" <?php checked(in_array('negative', $sentiments)); ?> /> <?php esc_html_e('Negative (2-3 stars)', 'ai-product-review-generator'); ?></label><br>
                        <label><input type="checkbox" name="aiprg_review_sentiments[]" value="neutral" <?php checked(in_array('neutral', $sentiments)); ?> /> <?php esc_html_e('Neutral (3-4 stars)', 'ai-product-review-generator'); ?></label><br>
                        <label><input type="checkbox" name="aiprg_review_sentiments[]" value="positive" <?php checked(in_array('positive', $sentiments)); ?> /> <?php esc_html_e('Positive (4-5 stars)', 'ai-product-review-generator'); ?></label>
                        <p class="description"><?php esc_html_e('Select the sentiments the AI should reflect in the reviews.', 'ai-product-review-generator'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }
    
    public function render_generate_button() {
        ?>
        <div class="aiprg-generate-section">
            <hr />
            <p class="submit">
                <button type="button" id="aiprg_generate_reviews" class="button-primary">
                    <?php esc_html_e('Generate AI Product Reviews', 'ai-product-review-generator'); ?>
                </button>
                <span class="spinner" style="float: none; margin-top: 0;"></span>
            </p>
            <div id="aiprg_generation_status"></div>
            <div id="aiprg_generation_progress" style="display: none;">
                <div class="progress-bar">
                    <div class="progress-bar-fill" style="width: 0%;"></div>
                </div>
                <p class="progress-text"></p>
            </div>
        </div>
        <?php
    }
    
    public function save_custom_fields() {
        if (isset($_POST['aiprg_select_products'])) {
            update_option('aiprg_select_products', array_map('intval', $_POST['aiprg_select_products']));
        } else {
            update_option('aiprg_select_products', array());
        }
        
        // Save selected categories (now multi-select)
        if (isset($_POST['aiprg_select_categories'])) {
            update_option('aiprg_select_categories', array_map('intval', $_POST['aiprg_select_categories']));
        } else {
            update_option('aiprg_select_categories', array());
        }
        
        if (isset($_POST['aiprg_date_range_start'])) {
            update_option('aiprg_date_range_start', sanitize_text_field($_POST['aiprg_date_range_start']));
        }
        
        if (isset($_POST['aiprg_date_range_end'])) {
            update_option('aiprg_date_range_end', sanitize_text_field($_POST['aiprg_date_range_end']));
        }
        
        if (isset($_POST['aiprg_review_sentiments'])) {
            update_option('aiprg_review_sentiments', array_map('sanitize_text_field', $_POST['aiprg_review_sentiments']));
        } else {
            update_option('aiprg_review_sentiments', array());
        }
    }
    
    public function ajax_validate_api_key() {
        check_ajax_referer('aiprg_validate_api_key', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'ai-product-review-generator'));
        }
        
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('Please enter an API key.', 'ai-product-review-generator')));
        }
        
        // Create a temporary OpenAI instance with the provided key
        $openai = new AIPRG_OpenAI();
        
        // Temporarily set the API key for validation
        $reflection = new ReflectionClass($openai);
        $api_key_property = $reflection->getProperty('api_key');
        $api_key_property->setAccessible(true);
        $api_key_property->setValue($openai, $api_key);
        
        // Validate the API key
        $is_valid = $openai->validate_api_key();
        
        if ($is_valid) {
            wp_send_json_success(array('message' => __('API key is valid!', 'ai-product-review-generator')));
        } else {
            wp_send_json_error(array('message' => __('Invalid API key. Please check your key and try again.', 'ai-product-review-generator')));
        }
    }
}