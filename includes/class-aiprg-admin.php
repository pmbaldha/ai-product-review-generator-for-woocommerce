<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIPRG_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_filter('plugin_action_links_' . AIPRG_PLUGIN_BASENAME, array($this, 'add_action_links'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function add_admin_menu() {
        // Add main submenu under Products
        add_submenu_page(
            'edit.php?post_type=product',
            __('AI Product Review Generator for Woo', 'ai-product-review-generator'),
            __('AI Review Generator', 'ai-product-review-generator'),
            'manage_woocommerce',
            'aiprg-dashboard',
            array($this, 'render_main_page')
        );
    }
    
    public function add_action_links($links) {
        $action_links = array(
            '<a href="' . admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=settings') . '">' . __('Settings', 'ai-product-review-generator') . '</a>',
            '<a href="' . admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=help') . '" >' . __('Documentation', 'ai-product-review-generator') . '</a>'
        );
        
        return array_merge($action_links, $links);
    }
    
    public function render_main_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
        ?>
        <div class="wrap aiprg-main">
            <h1><?php echo esc_html__('AI Product Review Generator for Woo', 'ai-product-review-generator'); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=dashboard')); ?>" class="nav-tab <?php echo $active_tab == 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Dashboard', 'ai-product-review-generator'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=settings')); ?>" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'ai-product-review-generator'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=reviews')); ?>" class="nav-tab <?php echo $active_tab == 'reviews' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Reviews', 'ai-product-review-generator'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=logs')); ?>" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Logs', 'ai-product-review-generator'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=help')); ?>" class="nav-tab <?php echo $active_tab == 'help' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Help', 'ai-product-review-generator'); ?>
                </a>
            </nav>
            
            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'reviews':
                        $this->render_reviews_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    case 'help':
                        $this->render_help_tab();
                        break;
                    case 'dashboard':
                    default:
                        $this->render_dashboard_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    private function render_dashboard_tab() {
        ?>
        <div class="aiprg-dashboard-content">
            <div class="aiprg-stats-container">
                <h2><?php esc_html_e('Review Statistics', 'ai-product-review-generator'); ?></h2>
                <?php $this->render_stats(); ?>
            </div>
            
            <div class="aiprg-recent-reviews">
                <h2><?php esc_html_e('Recent AI Generated Reviews', 'ai-product-review-generator'); ?></h2>
                <?php $this->render_recent_reviews(); ?>
            </div>
            
            <div class="aiprg-quick-actions">
                <h2><?php esc_html_e('Quick Actions', 'ai-product-review-generator'); ?></h2>
                <p>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=settings')); ?>" class="button button-primary">
                        <?php esc_html_e('Generate Reviews', 'ai-product-review-generator'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('edit-comments.php?comment_type=review')); ?>" class="button">
                        <?php esc_html_e('View All Reviews', 'ai-product-review-generator'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=logs')); ?>" class="button">
                        <?php esc_html_e('View Debug Logs', 'ai-product-review-generator'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }
    
    private function render_settings_tab() {
        // Include the settings instance
        $settings = new AIPRG_Settings();
        
        // Handle form submission
        if (isset($_POST['save_settings']) && wp_verify_nonce($_POST['aiprg_settings_nonce'], 'aiprg_save_settings')) {
            woocommerce_update_options($settings->get_settings());
            $settings->save_custom_fields();
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully.', 'ai-product-review-generator') . '</p></div>';
        }
        
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('aiprg_save_settings', 'aiprg_settings_nonce'); ?>
            <?php 
            woocommerce_admin_fields($settings->get_settings());
            $settings->render_custom_fields();
            $settings->render_generate_button();
            ?>
            <p class="submit">
                <input type="submit" name="save_settings" class="button-primary" value="<?php esc_attr_e('Save Settings', 'ai-product-review-generator'); ?>" />
            </p>
        </form>
        <?php
    }
    
    private function render_reviews_tab() {
        ?>
        <div class="aiprg-reviews-content">
            <h2><?php esc_html_e('AI Generated Reviews', 'ai-product-review-generator'); ?></h2>
            <?php $this->render_all_ai_reviews(); ?>
        </div>
        <?php
    }
    
    private function render_all_ai_reviews() {
        $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $per_page = 20;
        
        $args = array(
            'type' => 'review',
            'status' => 'approve',
            'number' => $per_page,
            'offset' => ($paged - 1) * $per_page,
            'meta_query' => array(
                array(
                    'key' => 'aiprg_generated',
                    'value' => '1'
                )
            )
        );
        
        $reviews = get_comments($args);
        
        // Get total count for pagination
        global $wpdb;
        $total_reviews = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->commentmeta} 
            WHERE meta_key = 'aiprg_generated' AND meta_value = '1'"
        );
        
        if (empty($reviews)) {
            echo '<p>' . esc_html__('No AI generated reviews yet.', 'ai-product-review-generator') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Product', 'ai-product-review-generator') . '</th>';
        echo '<th>' . esc_html__('Reviewer', 'ai-product-review-generator') . '</th>';
        echo '<th>' . esc_html__('Rating', 'ai-product-review-generator') . '</th>';
        echo '<th>' . esc_html__('Review', 'ai-product-review-generator') . '</th>';
        echo '<th>' . esc_html__('Date', 'ai-product-review-generator') . '</th>';
        echo '<th>' . esc_html__('Actions', 'ai-product-review-generator') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($reviews as $review) {
            $product = wc_get_product($review->comment_post_ID);
            if (!$product) continue;
            
            $rating = get_comment_meta($review->comment_ID, 'rating', true);
            
            echo '<tr>';
            echo '<td><a href="' . get_edit_post_link($review->comment_post_ID) . '">' . esc_html($product->get_name()) . '</a></td>';
            echo '<td>' . esc_html($review->comment_author) . '</td>';
            echo '<td>' . $this->render_star_rating($rating) . '</td>';
            echo '<td>' . wp_trim_words($review->comment_content, 30) . '</td>';
            echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($review->comment_date))) . '</td>';
            echo '<td>';
            echo '<a href="' . get_edit_comment_link($review->comment_ID) . '" class="button button-small">' . __('Edit', 'ai-product-review-generator') . '</a> ';
            echo '<a href="' . get_permalink($review->comment_post_ID) . '#comment-' . $review->comment_ID . '" class="button button-small" target="_blank">' . __('View', 'ai-product-review-generator') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        // Pagination
        if ($total_reviews > $per_page) {
            $total_pages = ceil($total_reviews / $per_page);
            echo '<div class="tablenav bottom">';
            echo '<div class="tablenav-pages">';
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $paged,
                'total' => $total_pages
            ));
            echo '</div>';
            echo '</div>';
        }
    }
    
    private function render_help_tab() {
        ?>
        <div class="aiprg-help-content">
            <h2><?php esc_html_e('Getting Started with OpenAI API', 'ai-product-review-generator'); ?></h2>
            
            <div class="aiprg-help-section">
                <h3><?php esc_html_e('Step 1: Create an OpenAI Account', 'ai-product-review-generator'); ?></h3>
                <ol>
                    <li><?php esc_html_e('Visit', 'ai-product-review-generator'); ?> <a href="https://platform.openai.com/signup" target="_blank">https://platform.openai.com/signup</a></li>
                    <li><?php esc_html_e('Sign up with your email address or continue with Google/Microsoft account', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Verify your email address', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Complete your profile information', 'ai-product-review-generator'); ?></li>
                </ol>
            </div>
            
            <div class="aiprg-help-section">
                <h3><?php esc_html_e('Step 2: Set Up Your Organization (Optional but highly Recommended for free API Users)', 'ai-product-review-generator'); ?></h3>
                <ol>
                    <li><?php esc_html_e('Go to', 'ai-product-review-generator'); ?> <a href="https://platform.openai.com/account/organization" target="_blank">Organization Settings</a></li>
                    <li><?php esc_html_e('Click "Create new organization" if you want a separate organization for your store', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Enter your organization name (e.g., your store name)', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Select organization type (typically "Personal" for individual stores)', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Note your Organization ID for reference', 'ai-product-review-generator'); ?></li>
                </ol>
                <div class="notice notice-info inline">
                    <p><?php esc_html_e('Organizations help you manage API usage, billing, and team members separately. Each organization has its own API keys and usage limits.', 'ai-product-review-generator'); ?></p>
                </div>
            </div>
            
            <div class="aiprg-help-section">
                <h3><?php esc_html_e('Step 3: Generate Your API Key', 'ai-product-review-generator'); ?></h3>
                <ol>
                    <li><?php esc_html_e('Go to', 'ai-product-review-generator'); ?> <a href="https://platform.openai.com/api-keys" target="_blank">API Keys Page</a></li>
                    <li><?php esc_html_e('Click "Create new secret key"', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Give your key a descriptive name (e.g., "WooCommerce Store Reviews")', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Select permissions (typically "All" for full functionality)', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Click "Create secret key"', 'ai-product-review-generator'); ?></li>
                    <li><strong><?php esc_html_e('IMPORTANT: Copy the key immediately! You won\'t be able to see it again.', 'ai-product-review-generator'); ?></strong></li>
                    <li><?php esc_html_e('Store the key securely - treat it like a password', 'ai-product-review-generator'); ?></li>
                </ol>
                <div class="notice notice-error inline">
                    <p><?php esc_html_e('Security Warning: Never share your API key publicly or commit it to version control. Anyone with your API key can use your OpenAI account and incur charges.', 'ai-product-review-generator'); ?></p>
                </div>
            </div>
            
            <div class="aiprg-help-section">
                <h3><?php esc_html_e('Step 4: Configure the Plugin', 'ai-product-review-generator'); ?></h3>
                <ol>
                    <li><?php esc_html_e('Go to the', 'ai-product-review-generator'); ?> <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=settings')); ?>"><?php esc_html_e('Settings tab', 'ai-product-review-generator'); ?></a></li>
                    <li><?php esc_html_e('Paste your API key in the "OpenAI API Key" field', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Click "Validate API Key" to verify it\'s working', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Configure other settings as needed', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Save your settings', 'ai-product-review-generator'); ?></li>
                </ol>
            </div>
            
            <div class="aiprg-help-section">
                <h3><?php esc_html_e('API Pricing Information', 'ai-product-review-generator'); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Model', 'ai-product-review-generator'); ?></th>
                            <th><?php esc_html_e('Input Price', 'ai-product-review-generator'); ?></th>
                            <th><?php esc_html_e('Output Price', 'ai-product-review-generator'); ?></th>
                            <th><?php esc_html_e('RPM Limit', 'ai-product-review-generator'); ?></th>
                            <th><?php esc_html_e('Estimated Cost per Review', 'ai-product-review-generator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>GPT-4o Mini</td>
                            <td>$0.00015 / 1K tokens</td>
                            <td>$0.0006 / 1K tokens</td>
                            <td>500 RPM</td>
                            <td>Free</td>
                        </tr>
                        <tr>
                            <td>GPT-3.5 Turbo</td>
                            <td>$0.0005 / 1K tokens</td>
                            <td>$0.0015 / 1K tokens</td>
                            <td>500 RPM</td>
                            <td>~$0.001 - $0.003</td>
                        </tr>
                        <tr>
                            <td>GPT-4</td>
                            <td>$0.03 / 1K tokens</td>
                            <td>$0.06 / 1K tokens</td>
                            <td>500 RPM</td>
                            <td>~$0.06 - $0.18</td>
                        </tr>
                        <tr>
                            <td>GPT-4 Turbo</td>
                            <td>$0.01 / 1K tokens</td>
                            <td>$0.03 / 1K tokens</td>
                            <td>500 RPM</td>
                            <td>~$0.02 - $0.06</td>
                        </tr>
                    </tbody>
                </table>
                <p class="description">
                    <?php esc_html_e('* Prices are approximate and may change. Visit', 'ai-product-review-generator'); ?> 
                    <a href="https://openai.com/pricing" target="_blank"><?php esc_html_e('OpenAI Pricing', 'ai-product-review-generator'); ?></a> 
                    <?php esc_html_e('for current rates.', 'ai-product-review-generator'); ?><br>
                    <?php esc_html_e('* RPM (Requests Per Minute) limits may vary based on your account tier. Default limits shown are for Tier 1 accounts.', 'ai-product-review-generator'); ?>
                </p>
            </div>
            
            <div class="aiprg-help-section">
                <h3><?php esc_html_e('Best Practices', 'ai-product-review-generator'); ?></h3>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Start with a small batch of products to test the system', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Use GPT-4o Mini model for free review generation', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Set up usage alerts in your OpenAI dashboard', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Regularly rotate your API keys for security', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Monitor your usage through OpenAI\'s dashboard', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Use mixed sentiment settings for more realistic reviews', 'ai-product-review-generator'); ?></li>
                    <li><?php esc_html_e('Space out review generation dates for authenticity', 'ai-product-review-generator'); ?></li>
                </ul>
            </div>
            
            <div class="aiprg-help-section">
                <h3><?php esc_html_e('Troubleshooting Common Issues', 'ai-product-review-generator'); ?></h3>
                <div class="aiprg-troubleshooting">
                    <h4><?php esc_html_e('Invalid API Key Error', 'ai-product-review-generator'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Ensure you copied the entire key including "sk-" prefix', 'ai-product-review-generator'); ?></li>
                        <li><?php esc_html_e('Check that there are no extra spaces before or after the key', 'ai-product-review-generator'); ?></li>
                        <li><?php esc_html_e('Verify the key hasn\'t been revoked in your OpenAI dashboard', 'ai-product-review-generator'); ?></li>
                        <li><?php esc_html_e('Confirm your account has available credits', 'ai-product-review-generator'); ?></li>
                    </ul>
                    
                    <h4><?php esc_html_e('Rate Limit Errors', 'ai-product-review-generator'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Reduce the number of products processed at once', 'ai-product-review-generator'); ?></li>
                        <li><?php esc_html_e('Wait a few minutes between large batches', 'ai-product-review-generator'); ?></li>
                        <li><?php esc_html_e('Consider upgrading your OpenAI plan for higher limits', 'ai-product-review-generator'); ?></li>
                    </ul>
                    
                    <h4><?php esc_html_e('No Reviews Generated', 'ai-product-review-generator'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Check the debug logs for specific error messages', 'ai-product-review-generator'); ?></li>
                        <li><?php esc_html_e('Ensure products have descriptions for the AI to work with', 'ai-product-review-generator'); ?></li>
                        <li><?php esc_html_e('Verify your API key has the necessary permissions', 'ai-product-review-generator'); ?></li>
                    </ul>
                </div>
            </div>
            
            <div class="aiprg-help-section">
                <h3><?php esc_html_e('Need More Help?', 'ai-product-review-generator'); ?></h3>
                <p>
                    <a href="https://platform.openai.com/docs" target="_blank" class="button">
                        <?php esc_html_e('OpenAI Documentation', 'ai-product-review-generator'); ?>
                    </a>
                    <a href="https://help.openai.com" target="_blank" class="button">
                        <?php esc_html_e('OpenAI Support', 'ai-product-review-generator'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=logs')); ?>" class="button">
                        <?php esc_html_e('View Debug Logs', 'ai-product-review-generator'); ?>
                    </a>
                </p>
            </div>
            
            <style>
                .aiprg-help-content {
                    max-width: 900px;
                    margin: 20px 0;
                }
                .aiprg-help-section {
                    background: #fff;
                    padding: 20px;
                    margin-bottom: 20px;
                    border: 1px solid #ccd0d4;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                }
                .aiprg-help-section h3 {
                    margin-top: 0;
                    padding-bottom: 10px;
                    border-bottom: 1px solid #e1e1e1;
                }
                .aiprg-help-section h4 {
                    margin-top: 15px;
                    margin-bottom: 10px;
                    color: #23282d;
                }
                .aiprg-help-section ol,
                .aiprg-help-section ul {
                    margin: 15px 0;
                    padding-left: 30px;
                }
                .aiprg-help-section li {
                    margin-bottom: 8px;
                    line-height: 1.6;
                }
                .aiprg-help-section .notice {
                    margin: 15px 0;
                }
                .aiprg-help-section table {
                    margin: 15px 0;
                }
                .aiprg-troubleshooting {
                    background: #f9f9f9;
                    padding: 15px;
                    border-radius: 4px;
                    margin-top: 15px;
                }
                .aiprg-troubleshooting h4 {
                    color: #0073aa;
                }
                .aiprg-troubleshooting ul {
                    margin-top: 5px;
                    margin-bottom: 15px;
                }
            </style>
        </div>
        <?php
    }
    
    private function render_logs_tab() {
        $logger = AIPRG_Logger::instance();
        
        // Handle log clearing
        if (isset($_POST['clear_logs']) && wp_verify_nonce($_POST['aiprg_logs_nonce'], 'aiprg_clear_logs')) {
            $logger->clear_logs();
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Logs cleared successfully.', 'ai-product-review-generator') . '</p></div>';
        }
        
        // Handle log download
        if (isset($_GET['download_log']) && wp_verify_nonce($_GET['nonce'], 'aiprg_download_log')) {
            $log_file = $logger->get_log_file_path();
            if (file_exists($log_file)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="aiprg-debug-' . date('Y-m-d-His') . '.log"');
                readfile($log_file);
                exit;
            }
        }
        
        $log_file = $logger->get_log_file_path();
        $log_size = $logger->get_log_size();
        $logs = $logger->get_recent_logs(500);
        $logging_enabled = get_option('aiprg_enable_logging', 'yes') === 'yes';
        
        ?>
        <div class="aiprg-logs-content">
            <h2><?php esc_html_e('Debug Logs', 'ai-product-review-generator'); ?></h2>
            
            <div class="aiprg-logs-info">
                <p>
                    <strong><?php esc_html_e('Log File:', 'ai-product-review-generator'); ?></strong> 
                    <?php echo esc_html($log_file); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('File Size:', 'ai-product-review-generator'); ?></strong> 
                    <?php echo esc_html($this->format_bytes($log_size)); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Logging Status:', 'ai-product-review-generator'); ?></strong> 
                    <?php if ($logging_enabled): ?>
                        <span style="color: green;">✓ <?php esc_html_e('Enabled', 'ai-product-review-generator'); ?></span>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=settings#aiprg_enable_logging')); ?>" style="margin-left: 10px;">
                            <?php esc_html_e('View Settings', 'ai-product-review-generator'); ?>
                        </a>
                    <?php else: ?>
                        <span style="color: red;">✗ <?php esc_html_e('Disabled', 'ai-product-review-generator'); ?></span>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=settings#aiprg_enable_logging')); ?>" style="margin-left: 10px;">
                            <?php esc_html_e('Enable in Settings', 'ai-product-review-generator'); ?>
                        </a>
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="aiprg-logs-actions" style="margin: 20px 0;">
                <a href="<?php echo wp_nonce_url(add_query_arg('download_log', '1'), 'aiprg_download_log', 'nonce'); ?>" 
                   class="button button-primary">
                    <?php esc_html_e('Download Log File', 'ai-product-review-generator'); ?>
                </a>
                
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('aiprg_clear_logs', 'aiprg_logs_nonce'); ?>
                    <input type="submit" name="clear_logs" class="button" 
                           value="<?php esc_attr_e('Clear All Logs', 'ai-product-review-generator'); ?>"
                           onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all logs?', 'ai-product-review-generator'); ?>');">
                </form>
                
                <a href="<?php echo esc_url(remove_query_arg('download_log')); ?>" class="button">
                    <?php esc_html_e('Refresh', 'ai-product-review-generator'); ?>
                </a>
            </div>
            
            <div class="aiprg-logs-viewer">
                <h2><?php esc_html_e('Recent Log Entries', 'ai-product-review-generator'); ?></h2>
                
                <?php if (empty($logs)): ?>
                    <p><?php esc_html_e('No log entries found.', 'ai-product-review-generator'); ?></p>
                <?php else: ?>
                    <div style="background: #f1f1f1; padding: 10px; border: 1px solid #ccc; 
                                max-height: 600px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                        <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?php
                            foreach ($logs as $log_line) {
                                $formatted_line = esc_html($log_line);
                                
                                // Color code based on log level
                                if (strpos($log_line, '[ERROR]') !== false) {
                                    echo '<span style="color: #d54e21; font-weight: bold;">' . $formatted_line . '</span>';
                                } elseif (strpos($log_line, '[WARNING]') !== false) {
                                    echo '<span style="color: #f0b849;">' . $formatted_line . '</span>';
                                } elseif (strpos($log_line, '[SUCCESS]') !== false) {
                                    echo '<span style="color: #46b450;">' . $formatted_line . '</span>';
                                } elseif (strpos($log_line, '[API_REQUEST]') !== false) {
                                    echo '<span style="color: #0073aa;">' . $formatted_line . '</span>';
                                } elseif (strpos($log_line, '[API_RESPONSE]') !== false) {
                                    echo '<span style="color: #826eb4;">' . $formatted_line . '</span>';
                                } else {
                                    echo $formatted_line;
                                }
                            }
                        ?></pre>
                    </div>
                <?php endif; ?>
            </div>
            
            <style>
                .aiprg-logs-info {
                    background: #fff;
                    padding: 15px;
                    margin: 20px 0;
                    border: 1px solid #ccd0d4;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                }
                .aiprg-logs-info p {
                    margin: 5px 0;
                }
                .aiprg-logs-viewer {
                    background: #fff;
                    padding: 15px;
                    border: 1px solid #ccd0d4;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                }
            </style>
        </div>
        <?php
    }
    
    private function render_stats() {
        global $wpdb;
        
        $total_ai_reviews = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->commentmeta} 
            WHERE meta_key = 'aiprg_generated' AND meta_value = '1'"
        );
        
        $today_reviews = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} c
            INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_id
            WHERE cm.meta_key = 'aiprg_generated' 
            AND cm.meta_value = '1'
            AND DATE(c.comment_date) = %s",
            current_time('Y-m-d')
        ));
        
        $avg_rating = $wpdb->get_var(
            "SELECT AVG(CAST(cm2.meta_value AS DECIMAL(2,1)))
            FROM {$wpdb->commentmeta} cm1
            INNER JOIN {$wpdb->commentmeta} cm2 ON cm1.comment_id = cm2.comment_id
            WHERE cm1.meta_key = 'aiprg_generated' 
            AND cm1.meta_value = '1'
            AND cm2.meta_key = 'rating'"
        );
        
        ?>
        <div class="aiprg-stats-grid">
            <div class="aiprg-stat-box">
                <span class="aiprg-stat-number"><?php echo esc_html($total_ai_reviews); ?></span>
                <span class="aiprg-stat-label"><?php esc_html_e('Total AI Reviews', 'ai-product-review-generator'); ?></span>
            </div>
            <div class="aiprg-stat-box">
                <span class="aiprg-stat-number"><?php echo esc_html($today_reviews); ?></span>
                <span class="aiprg-stat-label"><?php esc_html_e('Reviews Today', 'ai-product-review-generator'); ?></span>
            </div>
            <div class="aiprg-stat-box">
                <span class="aiprg-stat-number"><?php echo number_format($avg_rating ?? 0, 1); ?></span>
                <span class="aiprg-stat-label"><?php esc_html_e('Average Rating', 'ai-product-review-generator'); ?></span>
            </div>
        </div>
        <?php
    }
    
    private function render_recent_reviews() {
        $args = array(
            'type' => 'review',
            'status' => 'approve',
            'number' => 5,
            'meta_query' => array(
                array(
                    'key' => 'aiprg_generated',
                    'value' => '1'
                )
            )
        );
        
        $reviews = get_comments($args);
        
        if (empty($reviews)) {
            echo '<p>' . esc_html__('No AI generated reviews yet.', 'ai-product-review-generator') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Product', 'ai-product-review-generator') . '</th>';
        echo '<th>' . esc_html__('Reviewer', 'ai-product-review-generator') . '</th>';
        echo '<th>' . esc_html__('Rating', 'ai-product-review-generator') . '</th>';
        echo '<th>' . esc_html__('Review', 'ai-product-review-generator') . '</th>';
        echo '<th>' . esc_html__('Date', 'ai-product-review-generator') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($reviews as $review) {
            $product = wc_get_product($review->comment_post_ID);
            $rating = get_comment_meta($review->comment_ID, 'rating', true);
            
            echo '<tr>';
            echo '<td><a href="' . get_edit_post_link($review->comment_post_ID) . '">' . esc_html($product->get_name()) . '</a></td>';
            echo '<td>' . esc_html($review->comment_author) . '</td>';
            echo '<td>' . $this->render_star_rating($rating) . '</td>';
            echo '<td>' . wp_trim_words($review->comment_content, 20) . '</td>';
            echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($review->comment_date))) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    private function render_star_rating($rating) {
        $output = '<div class="star-rating">';
        for ($i = 1; $i <= 5; $i++) {
            if ($i <= $rating) {
                $output .= '<span class="star filled">★</span>';
            } else {
                $output .= '<span class="star">☆</span>';
            }
        }
        $output .= '</div>';
        return $output;
    }
    
    public function admin_notices() {
        $api_key = get_option('aiprg_openai_api_key', '');
        
        if (empty($api_key) && isset($_GET['page']) && strpos($_GET['page'], 'aiprg') !== false) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php
                    printf(
                        __('AI Product Review Generator: Please configure your OpenAI API key in the <a href="%s">settings</a>.', 'ai-product-review-generator'),
                        admin_url('edit.php?post_type=product&page=aiprg-dashboard&tab=settings')
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }
    
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    public function enqueue_admin_scripts($hook) {
        // Check if we're on our plugin's admin page (under Products menu)
        if ('product_page_aiprg-dashboard' !== $hook) {
            return;
        }
        
        $tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
        
        // Load scripts for settings tab
        if ($tab === 'settings') {
            // Enqueue WooCommerce admin scripts and styles for product search
            wp_enqueue_script('selectWoo');
            wp_enqueue_script('wc-enhanced-select');
            wp_enqueue_style('woocommerce_admin_styles');
            wp_enqueue_style('select2');
            
            wp_enqueue_style('aiprg-admin', AIPRG_PLUGIN_URL . 'assets/css/admin.css', array('woocommerce_admin_styles'), AIPRG_VERSION);
            wp_enqueue_script('aiprg-admin', AIPRG_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'selectWoo', 'wc-enhanced-select'), AIPRG_VERSION, true);
            
            // Get WooCommerce's search nonce
            $search_products_nonce = '';
            if (function_exists('wp_create_nonce')) {
                $search_products_nonce = wp_create_nonce('search-products');
            }
            
            wp_localize_script('aiprg-admin', 'aiprg_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('aiprg_generate_reviews'),
                'validate_nonce' => wp_create_nonce('aiprg_validate_api_key'),
                'search_products_nonce' => $search_products_nonce,
                'generating_text' => __('Generating reviews...', 'ai-product-review-generator'),
                'success_text' => __('Reviews generated successfully!', 'ai-product-review-generator'),
                'error_text' => __('An error occurred while generating reviews.', 'ai-product-review-generator'),
                'validating_text' => __('Validating...', 'ai-product-review-generator'),
                'valid_api_key_text' => __('✓ Valid API Key', 'ai-product-review-generator'),
                'invalid_api_key_text' => __('✗ Invalid API Key', 'ai-product-review-generator')
            ));
            
            // Also add WooCommerce's localized params for enhanced select
            global $wp_scripts;
            if (isset($wp_scripts->registered['wc-enhanced-select'])) {
                $params = array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'search_products_nonce' => wp_create_nonce('search-products'),
                );
                wp_localize_script('wc-enhanced-select', 'wc_enhanced_select_params', $params);
            }
        }
    }
}