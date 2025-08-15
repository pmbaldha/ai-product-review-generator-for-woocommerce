<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package AI_Product_Review_Generator
 * @since 1.0.0
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Delete all plugin options
 */
$options_to_delete = array(
    'aiprg_version',
    'aiprg_openai_api_key',
    'aiprg_openai_engine', 
    'aiprg_reviews_per_product',
    'aiprg_review_length_mode',
    'aiprg_review_sentiments',
    'aiprg_sentiment_balance',
    'aiprg_custom_prompt',
    'aiprg_custom_keywords',
    'aiprg_enable_logging',
    'aiprg_select_products',
    'aiprg_select_categories',
    'aiprg_date_range_start',
    'aiprg_date_range_end',
    'aiprg_generation_status',
    'aiprg_generation_progress'
);

foreach ($options_to_delete as $option) {
    delete_option($option);
}

/**
 * Delete all transients that might have been created
 */
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_aiprg_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_aiprg_%'");

/**
 * Clear scheduled events if any
 */
$timestamp = wp_next_scheduled('aiprg_scheduled_review_generation');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'aiprg_scheduled_review_generation');
}

/**
 * Clean up any Action Scheduler entries if the plugin used it
 */
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('aiprg_generate_review');
    as_unschedule_all_actions('aiprg_generate_batch_reviews');
}

/**
 * Remove any custom database tables if they exist
 */
$table_name = $wpdb->prefix . 'aiprg_logs';
if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}

/**
 * Clear any cached data
 */
wp_cache_flush();