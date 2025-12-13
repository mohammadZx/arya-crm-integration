<?php
/**
 * Plugin Name: Arya Portal Integration
 * Plugin URI: https://aryatehran.com
 * Description: افزونه یکپارچگی با پورتال آریا تهران برای همگام‌سازی محصولات، سفارشات و اطلاعات کاربران
 * Version: 1.0.0
 * Author: Arya Tehran Team
 * Author URI: https://aryatehran.com
 * Text Domain: arya-portal
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ARYA_PORTAL_VERSION', '1.0.0');
define('ARYA_PORTAL_PLUGIN_FILE', __FILE__);
define('ARYA_PORTAL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ARYA_PORTAL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ARYA_PORTAL_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p><strong>Arya Portal Integration</strong> نیاز به WooCommerce دارد. لطفا WooCommerce را نصب و فعال کنید.</p></div>';
    });
    return;
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'Arya\\Portal\\';
    $base_dir = ARYA_PORTAL_PLUGIN_DIR . 'includes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Main plugin class
final class Arya_Portal_Integration {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize plugin
     */
    private function init() {
        // Load plugin files
        $this->load_dependencies();
        
        // Initialize components
        add_action('plugins_loaded', [$this, 'load_plugin'], 10);
        
        // Activation hook
        register_activation_hook(ARYA_PORTAL_PLUGIN_FILE, [$this, 'activate']);
        
        // Deactivation hook
        register_deactivation_hook(ARYA_PORTAL_PLUGIN_FILE, [$this, 'deactivate']);
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        require_once ARYA_PORTAL_PLUGIN_DIR . 'includes/PersonData.php';
        require_once ARYA_PORTAL_PLUGIN_DIR . 'includes/Settings.php';
        require_once ARYA_PORTAL_PLUGIN_DIR . 'includes/REST_API.php';
        require_once ARYA_PORTAL_PLUGIN_DIR . 'includes/OrderHandler.php';
        require_once ARYA_PORTAL_PLUGIN_DIR . 'includes/RedirectHelper.php';
        require_once ARYA_PORTAL_PLUGIN_DIR . 'includes/Public_REST_API.php';
    }
    
    /**
     * Load plugin after WooCommerce is loaded
     */
    public function load_plugin() {
        // Initialize settings
        Arya\Portal\Settings::instance();
        
        // Initialize REST API (Portal endpoints)
        Arya\Portal\REST_API::instance();
        
        // Initialize Public REST API (Site endpoints)
        Arya\Portal\Public_REST_API::instance();
        
        // Initialize Order Handler
        Arya\Portal\OrderHandler::instance();
        
        // Initialize Redirect Helper
        Arya\Portal\RedirectHelper::instance();
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        if (!get_option('arya_portal_url')) {
            update_option('arya_portal_url', 'https://portal.aryatehran.com');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

/**
 * Main plugin function
 */
function arya_portal_integration() {
    return Arya_Portal_Integration::instance();
}

// Initialize plugin
arya_portal_integration();

