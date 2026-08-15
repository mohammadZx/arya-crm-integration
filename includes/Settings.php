<?php

namespace Arya\Portal;

/**
 * Settings Class
 * 
 * Handles plugin settings and configuration
 */
class Settings {
    
    private static $instance = null;
    private $options_key = 'arya_portal_settings';
    
    /**
     * Get instance
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
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'تنظیمات پورتال آریا',
            'پورتال آریا',
            'manage_options',
            'arya-portal-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('arya_portal_settings', 'arya_portal_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://portal.aryatehran.com'
        ]);
        
        register_setting('arya_portal_settings', 'arya_portal_api_token', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);
        
        register_setting('arya_portal_settings', 'arya_portal_course_category_id', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1
        ]);
        
        register_setting('arya_portal_settings', 'arya_portal_private_class_code', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 999
        ]);
        
        register_setting('arya_portal_settings', 'arya_portal_exam_categories', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);
        
        // Settings sections
        add_settings_section(
            'arya_portal_main_section',
            'تنظیمات اصلی',
            [$this, 'render_section_description'],
            'arya-portal-settings'
        );
        
        // Settings fields
        add_settings_field(
            'arya_portal_url',
            'آدرس پورتال',
            [$this, 'render_url_field'],
            'arya-portal-settings',
            'arya_portal_main_section'
        );
        
        add_settings_field(
            'arya_portal_api_token',
            'توکن API',
            [$this, 'render_token_field'],
            'arya-portal-settings',
            'arya_portal_main_section'
        );
        
        add_settings_field(
            'arya_portal_course_category_id',
            'شناسه دسته‌بندی دوره‌ها',
            [$this, 'render_course_category_field'],
            'arya-portal-settings',
            'arya_portal_main_section'
        );
        
        add_settings_field(
            'arya_portal_private_class_code',
            'کد کلاس خصوصی',
            [$this, 'render_private_class_code_field'],
            'arya-portal-settings',
            'arya_portal_main_section'
        );
        
        add_settings_field(
            'arya_portal_exam_categories',
            'دسته‌بندی آزمون‌ها',
            [$this, 'render_exam_categories_field'],
            'arya-portal-settings',
            'arya_portal_main_section'
        );
    }
    
    /**
     * Render section description
     */
    public function render_section_description() {
        echo '<p>لطفا اطلاعات اتصال به پورتال آریا تهران را وارد کنید.</p>';
    }
    
    /**
     * Render URL field
     */
    public function render_url_field() {
        $value = get_option('arya_portal_url', 'https://portal.aryatehran.com');
        echo '<input type="url" name="arya_portal_url" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://portal.aryatehran.com">';
        echo '<p class="description">آدرس کامل پورتال آریا تهران (بدون /api/v1)</p>';
    }
    
    /**
     * Render token field
     */
    public function render_token_field() {
        $value = get_option('arya_portal_api_token', '');
        echo '<input type="password" name="arya_portal_api_token" value="' . esc_attr($value) . '" class="regular-text" placeholder="توکن API">';
        echo '<p class="description">توکن دسترسی API پورتال</p>';
    }
    
    /**
     * Render course category field
     */
    public function render_course_category_field() {
        $value = $this->get_course_category_id();
        echo '<input type="number" name="arya_portal_course_category_id" value="' . esc_attr($value) . '" class="small-text">';
        echo '<p class="description">شناسه دسته‌بندی دوره‌ها در پورتال</p>';
    }
    
    /**
     * Render private class code field
     */
    public function render_private_class_code_field() {
        $value = $this->get_private_class_code();
        echo '<input type="number" name="arya_portal_private_class_code" value="' . esc_attr($value) . '" class="small-text">';
        echo '<p class="description">کد دوره کلاس خصوصی در پورتال</p>';
    }
    
    /**
     * Render exam categories field
     */
    public function render_exam_categories_field() {
        $value = $this->get_exam_categories();
        echo '<input type="text" name="arya_portal_exam_categories" value="' . esc_attr($value) . '" class="regular-text" placeholder="1,2,3" dir="ltr">';
        echo '<p class="description">شناسه دسته‌بندی آزمون‌ها در پورتال (جدا شده با کاما)</p>';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (isset($_GET['settings-updated'])) {
            add_settings_error('arya_portal_messages', 'arya_portal_message', 'تنظیمات ذخیره شد.', 'updated');
        }
        
        settings_errors('arya_portal_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('arya_portal_settings');
                do_settings_sections('arya-portal-settings');
                submit_button('ذخیره تنظیمات');
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Get portal URL
     */
    public function get_portal_url() {
        return get_option('arya_portal_url', 'https://portal.aryatehran.com');
    }
    
    /**
     * Get API token
     */
    public function get_api_token() {
        return get_option('arya_portal_api_token', '');
    }
    
    /**
     * Get course category ID
     */
    public function get_course_category_id() {
        return (int) get_option('arya_portal_course_category_id', 1);
    }
    
    /**
     * Get private class code
     */
    public function get_private_class_code() {
        return (int) get_option('arya_portal_private_class_code', 999);
    }
    
    /**
     * Get exam categories
     */
    public function get_exam_categories() {
        return get_option('arya_portal_exam_categories', '');
    }
}

