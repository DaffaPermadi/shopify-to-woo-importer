<?php
/**
 * Plugin Name: Shopify to WooCommerce Importer
 * Description: Safely import Shopify products to WooCommerce with image handling
 * Version: 1.0.0
 * Author: Daffa Permadi
 * Text Domain: shopify-to-woo-importer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('STWI_VERSION', '1.0.0');
define('STWI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STWI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('STWI_BATCH_SIZE', 10); // Configurable batch size for processing
define('STWI_MAX_EXECUTION_TIME', 55); // Maximum execution time in seconds (less than PHP timeout)

// Debug mode
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

// Load required files directly
require_once STWI_PLUGIN_DIR . 'includes/class-product-processor.php';
require_once STWI_PLUGIN_DIR . 'includes/class-importer.php';
require_once STWI_PLUGIN_DIR . 'includes/class-admin.php';


// Ensure WooCommerce is active
function stwi_check_woocommerce() {
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php _e('Shopify to WooCommerce Importer requires WooCommerce to be installed and activated.', 'shopify-to-woo-importer'); ?></p>
            </div>
            <?php
        });
        return false;
    }
    return true;
}

// Activation hook
register_activation_hook(__FILE__, 'stwi_activate');
function stwi_activate() {
    if (!stwi_check_woocommerce()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Please install and activate WooCommerce before activating this plugin.', 'shopify-to-woo-importer'));
    }
    
    // Create necessary directories
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/shopify-imports';
    if (!file_exists($import_dir)) {
        wp_mkdir_p($import_dir);
    }
    
    // Create log directory
    $log_dir = $upload_dir['basedir'] . '/shopify-imports/logs';
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }
    
    // Add capabilities
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('manage_shopify_import');
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'stwi_deactivate');
function stwi_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook('stwi_process_batch');
    
    // Clean up temporary files
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/shopify-imports/temp';
    if (file_exists($import_dir)) {
        array_map('unlink', glob("$import_dir/*.*"));
    }
}

// Error handling
function stwi_error_handler($message, $data = null) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/shopify-imports/logs/error.log';
    
    $log_message = sprintf(
        "[%s] %s %s\n",
        current_time('mysql'),
        $message,
        $data ? json_encode($data) : ''
    );
    
    error_log($log_message, 3, $log_file);
}

// Initialize plugin
function stwi_init() {
    if (stwi_check_woocommerce()) {
        new STWI\Admin();
    }
}
add_action('plugins_loaded', 'stwi_init');

// Add custom interval for batch processing
add_filter('cron_schedules', function($schedules) {
    $schedules['stwi_one_minute'] = array(
        'interval' => 60,
        'display' => __('Every Minute', 'shopify-to-woo-importer')
    );
    return $schedules;
});

// Register import hooks
add_action('stwi_process_batch', array('STWI\Importer', 'process_batch'), 10, 1);