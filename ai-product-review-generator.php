<?php
/**
 * Plugin Name: AI Product Review Generator for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/ai-product-review-generator
 * Description: Transform your WooCommerce store with AI-powered product reviews. Generate authentic, detailed reviews using advanced AI technology.
 * Version: 1.0.0
 * Author: Orca WP
 * Author URI: https://prashantwp.com
 * Text Domain: ai-product-review-generator
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 7.2
 * WC requires at least: 9.3
 * WC tested up to: 9.3
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIPRG_VERSION', '1.0.1');
define('AIPRG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AIPRG_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AIPRG_PLUGIN_BASENAME', plugin_basename(__FILE__));

class AI_Product_Review_Generator {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        add_action('before_woocommerce_init', array($this, 'declare_compatibility'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        if (!$this->check_dependencies()) {
            return;
        }
        
        $this->load_textdomain();
        $this->includes();
        $this->init_hooks();
    }
    
    private function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        
        if (version_compare(WC_VERSION, '9.3', '<')) {
            add_action('admin_notices', array($this, 'woocommerce_version_notice'));
            return false;
        }
        
        return true;
    }
    
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php esc_html_e('AI Product Review Generator requires WooCommerce to be installed and activated.', 'ai-product-review-generator'); ?></p>
        </div>
        <?php
    }
    
    public function woocommerce_version_notice() {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php esc_html_e('AI Product Review Generator requires WooCommerce version 9.3 or higher.', 'ai-product-review-generator'); ?></p>
        </div>
        <?php
    }
    
    private function load_textdomain() {
        load_plugin_textdomain('ai-product-review-generator', false, dirname(AIPRG_PLUGIN_BASENAME) . '/languages');
    }
    
    private function includes() {
        require_once AIPRG_PLUGIN_PATH . 'includes/class-aiprg-logger.php';
        require_once AIPRG_PLUGIN_PATH . 'includes/class-aiprg-settings.php';
        require_once AIPRG_PLUGIN_PATH . 'includes/class-aiprg-openai.php';
        require_once AIPRG_PLUGIN_PATH . 'includes/class-aiprg-review-generator.php';
        require_once AIPRG_PLUGIN_PATH . 'includes/class-aiprg-action-scheduler.php';
        require_once AIPRG_PLUGIN_PATH . 'includes/class-aiprg-ajax-handler.php';
        require_once AIPRG_PLUGIN_PATH . 'includes/class-aiprg-admin.php';
    }
    
    private function init_hooks() {
        new AIPRG_Settings();
        new AIPRG_OpenAI();
        new AIPRG_Review_Generator();
        new AIPRG_Action_Scheduler();
        new AIPRG_Ajax_Handler();
        new AIPRG_Admin();
    }
    
    public function declare_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }
    
    public function activate() {
        if (!get_option('aiprg_version')) {
            add_option('aiprg_version', AIPRG_VERSION);
        }
        
        $default_settings = array(
            'openai_api_key' => '',
            'openai_engine' => 'gpt-3.5-turbo',
            'temperature' => 0.7,
            'reviews_per_product' => 5,
            'review_length_mode' => 'mixed',
            'review_sentiments' => array('positive'),
            'sentiment_balance' => 'balanced',
            'custom_prompt' => 'Write a realistic product review for {product_title}. Make it sound natural and authentic.',
            'custom_keywords' => '',
            'enable_logging' => 'yes'
        );
        
        foreach ($default_settings as $key => $value) {
            if (!get_option('aiprg_' . $key)) {
                add_option('aiprg_' . $key, $value);
            }
        }
    }
    
    public function deactivate() {
        
    }
}

AI_Product_Review_Generator::get_instance();