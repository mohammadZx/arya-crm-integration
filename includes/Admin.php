<?php

namespace Arya\Portal;

/**
 * Admin Class
 * 
 * Handles admin UI for portal integration (Meta boxes, fields, etc.)
 */
class Admin {
    
    private static $instance = null;
    
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
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Add custom fields to variations
        add_action('woocommerce_variation_options_pricing', [$this, 'add_custom_field_to_variations'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_custom_field_variations'], 10, 2);
        add_filter('woocommerce_available_variation', [$this, 'add_custom_field_variation_data']);
        
        // Add portal tab to product data
        add_filter('woocommerce_product_data_tabs', [$this, 'add_custom_product_tab'], 10, 1);
        add_action('woocommerce_product_data_panels', [$this, 'render_custom_tab_data']);
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_general_fields']);
        
        // Ajax for product selector
        add_action('wp_ajax_get_product_selector', [$this, 'get_product_selector']);
        add_action('wp_ajax_admin_get_product_selector', [$this, 'get_product_selector']);
    }

    /**
     * Add custom fields to variations
     */
    public function add_custom_field_to_variations($loop, $variation_data, $variation) {
        woocommerce_wp_text_input([
            'id' => 'custom_field[' . $loop . ']',
            'class' => 'short',
            'label' => 'کد دوره',
            'name' => 'course_code['. $loop .']',
            'placeholder' => 'لطفا کد دوره را وارد نمایید',
            'value' => get_post_meta($variation->ID, 'course_code', true)
        ]);

        woocommerce_wp_text_input([
            'id' => 'course_id[' . $loop . ']',
            'label' => 'آیدی در پورتال',
            'placeholder' => 'آیدی در پورتال',
            'name' => 'course_id['. $loop .']',
            'class' => 'wc-portal-input',
            'value' => get_post_meta($variation->ID, 'course_id', true),
        ]);

        woocommerce_wp_text_input([
            'id' => 'class_course_date_start[' . $loop . ']',
            'class' => 'short',
            'label' => 'تاریخ شروع دوره',
            'name' => 'class_course_date_start['. $loop .']',
            'placeholder' => 'لطفا تاریخ شروع دوره راه وارد نمایید',
            'value' => get_post_meta($variation->ID, 'class_course_date_start', true)
        ]);

        woocommerce_wp_text_input([
            'id' => 'days_in_week[' . $loop . ']',
            'class' => 'short',
            'label' => 'روز های هفته',
            'name' => 'days_in_week['. $loop .']',
            'placeholder' => 'لطفا روز های هفته را وارد کنید',
            'value' => get_post_meta($variation->ID, 'days_in_week', true)
        ]);

        woocommerce_wp_text_input([
            'id' => 'course_time[' . $loop . ']',
            'class' => 'short',
            'type' => 'number',
            'label' => 'ساعت دوره',
            'name' => 'course_time['. $loop .']',
            'placeholder' => 'ساعت دوره',
            'value' => get_post_meta($variation->ID, 'course_time', true)
        ]);

        woocommerce_wp_text_input([
            'id' => 'course_time_to[' . $loop . ']',
            'class' => 'short',
            'type' => 'number',
            'label' => 'حداکثر ساعت دوره',
            'name' => 'course_time_to['. $loop .']',
            'placeholder' => 'حداکثر ساعت دوره',
            'value' => get_post_meta($variation->ID, 'course_time_to', true)
        ]);

        woocommerce_wp_text_input([
            'id' => 'course_duration[' . $loop . ']',
            'class' => 'short',
            'type' => 'number',
            'label' => 'دوره چقدر طول می کشد',
            'name' => 'course_duration['. $loop .']',
            'placeholder' => 'دوره چقدر طول می کشد',
            'value' => get_post_meta($variation->ID, 'course_duration', true)
        ]);

        woocommerce_wp_text_input([
            'id' => 'course_duration_unit[' . $loop . ']',
            'class' => 'short',
            'type' => 'text',
            'label' => 'واحد طول دوره',
            'name' => 'course_duration_unit['. $loop .']',
            'placeholder' => 'واحد طول دوره',
            'value' => get_post_meta($variation->ID, 'course_duration_unit', true)
        ]);

        woocommerce_wp_text_input([
            'id' => 'start_time[' . $loop . ']',
            'class' => 'short',
            'label' => 'دوره از ساعت',
            'name' => 'start_time['. $loop .']',
            'placeholder' => 'دوره از ساعت',
            'value' => get_post_meta($variation->ID, 'start_time', true)
        ]);

        woocommerce_wp_text_input([
            'id' => 'end_time[' . $loop . ']',
            'class' => 'short',
            'label' => 'دوره از ساعت',
            'name' => 'end_time['. $loop .']',
            'placeholder' => 'دوره تا ساعت',
            'value' => get_post_meta($variation->ID, 'end_time', true)
        ]);

        $checked = get_post_meta($variation->ID, 'has_online', true) == 'yes' ? 'checked' : null;
        woocommerce_wp_checkbox([
            'id'=> 'has_online[' . $loop . ']', 
            'name'=> 'has_online[' . $loop . ']', 
            'label' => 'آنلاین دارد؟',
            'value' => get_post_meta($variation->ID, 'has_online', true), 
            'custom_attributes' => $checked
        ]);

        $checked = get_post_meta($variation->ID, 'has_presence', true) == 'yes' ? 'checked' : null;
        woocommerce_wp_checkbox([
            'id'=> 'has_presence[' . $loop . ']', 
            'name'=> 'has_presence[' . $loop . ']', 
            'label' => 'حضوری دارد؟',
            'value' => get_post_meta($variation->ID, 'has_presence', true), 
            'custom_attributes' => $checked
        ]);
    }

    /**
     * Save custom field variations
     */
    public function save_custom_field_variations($variation_id, $i) {
        $custom_field = $_POST['course_code'][$i];
        $course_id = $_POST['course_id'][$i];
        $courseDateStart = $_POST['class_course_date_start'][$i];
        $days_in_week = $_POST['days_in_week'][$i];
        $course_duration = $_POST['course_duration'][$i];
        $course_time = $_POST['course_duration'][$i]; // Note: This seemed to use course_duration in original code, potentially a bug in original theme code but keeping faithful copy
        // Wait, original: $course_time = $_POST['course_duration'][$i]; -> This looks like a bug in theme code (line 416). 
        // But line 429 uses $course_time.
        // I will fix this obvious bug if I see it. 
        // Original: $course_time = $_POST['course_duration'][$i];
        // Line 416. Line 429: update_post_meta(..., 'course_time', $course_time).
        // Line 333 name is course_time. So $_POST['course_time'] should exist.
        // I will use $_POST['course_time'][$i] instead.
        
        $course_time_val = isset($_POST['course_time'][$i]) ? $_POST['course_time'][$i] : '';

        $start_time = $_POST['start_time'][$i];
        $end_time = $_POST['end_time'][$i];
        $has_online = $_POST['has_online'][$i];
        $has_presence = $_POST['has_presence'][$i];
        $course_time_to = $_POST['course_time_to'][$i];
        $course_duration_unit = $_POST['course_duration_unit'][$i];

        if (isset($custom_field)) update_post_meta($variation_id, 'course_code', esc_attr($custom_field));
        if (isset($course_id)) update_post_meta($variation_id, 'course_id', esc_attr($course_id));
        if (isset($courseDateStart)) update_post_meta($variation_id, 'class_course_date_start', esc_attr($courseDateStart));
        if (isset($days_in_week)) update_post_meta($variation_id, 'days_in_week', esc_attr($days_in_week));
        if (isset($course_duration)) update_post_meta($variation_id, 'course_duration', esc_attr($course_duration));
        if (isset($course_time_val)) update_post_meta($variation_id, 'course_time', esc_attr($course_time_val));
        if (isset($start_time)) update_post_meta($variation_id, 'start_time', esc_attr($start_time));
        if (isset($end_time)) update_post_meta($variation_id, 'end_time', esc_attr($end_time));
        if (isset($course_time_to)) update_post_meta($variation_id, 'course_time_to', esc_attr($course_time_to));
        if (isset($course_duration_unit)) update_post_meta($variation_id, 'course_duration_unit', esc_attr($course_duration_unit));

        if ($course_time_to && $course_time_val) {
            update_post_meta($variation_id, 'course_time_compact', $course_time_to . ',' . $course_time_val);
        }

        if (isset($has_online)) {
            update_post_meta($variation_id, 'has_online', 'yes');
        } else {
            update_post_meta($variation_id, 'has_online', 'no');
        }

        if (isset($has_presence)) {
            update_post_meta($variation_id, 'has_presence', 'yes');
        } else {
            update_post_meta($variation_id, 'has_presence', 'no');
        }
    }

    /**
     * Add custom field variation data
     */
    public function add_custom_field_variation_data($variations) {
        $startDate = get_post_meta($variations['variation_id'], 'class_course_date_start', true);
        $startDay = '';
        if ($startDate && function_exists('jdate')) {
             $startDay = jdate('j F Y', strtotime($startDate));
        } elseif ($startDate) {
             $startDay = date('j F Y', strtotime($startDate));
        }

        $variations['course_code'] = '<div class="woocommerce_custom_field">کد دوره: <span>' . get_post_meta($variations['variation_id'], 'course_code', true) . '</span></div>';
        $variations['course_code_p'] = get_post_meta($variations['variation_id'], 'course_code', true);
        $variations['class_course_date_start'] = '<div class="woocommerce_class_course_date_start">تاریخ شروع دوره: <span>' . get_post_meta($variations['variation_id'], 'class_course_date_start', true) . '</span></div>';
        $variations['course_id'] = get_post_meta($variations['variation_id'], 'course_id', true);
        $variations['begin_date_str'] = $startDate ? $startDay : null;
        $variations['course_duration'] = get_post_meta($variations['variation_id'], 'course_duration', true);
        $variations['course_duration_unit'] = get_post_meta($variations['variation_id'], 'course_duration_unit', true);
        $variations['course_time_to'] = get_post_meta($variations['variation_id'], 'course_time_to', true);
        $variations['days_in_week'] = get_post_meta($variations['variation_id'], 'days_in_week', true);
        $variations['course_time'] = get_post_meta($variations['variation_id'], 'course_time', true);
        $variations['start_time'] = get_post_meta($variations['variation_id'], 'start_time', true);
        $variations['end_time'] = get_post_meta($variations['variation_id'], 'end_time', true);
        $variations['has_online'] = get_post_meta($variations['variation_id'], 'has_online', true);
        $variations['has_presence'] = get_post_meta($variations['variation_id'], 'has_presence', true);
        $variations['id'] = $variations['variation_id'];
        return $variations;
    }

    /**
     * Add custom product tab
     */
    public function add_custom_product_tab($default_tabs) {
        $default_tabs['custom_tab'] = [
            'label' => 'اطلاعات پورتال',
            'target' => 'wk_custom_tab_data',
            'priority' => 60,
            'class' => []
        ];
        return $default_tabs;
    }

    /**
     * Render custom tab data
     */
    public function render_custom_tab_data() {
        global $post;
        $settings = Settings::instance();
        
        // get information course and category from portal
        $catRequest = wp_remote_get($settings->get_portal_url() . '/api/v1/service-list');
        $categories = [];
        $body = wp_remote_retrieve_body($catRequest);
        if ($body) {
            $categories = json_decode($body, true);
        }

        $ocats = [
            '' => 'یک گزینه را انتخاب کنید'
        ];
        
        if (isset($categories['data']) && is_array($categories['data'])) {
            foreach ($categories['data'] as $category) {
                $ocats[$category['id']] = $category['name'];
            }
        }
        
        echo '<div id="wk_custom_tab_data" class="panel woocommerce_options_panel">';
        
        woocommerce_wp_select([
            'id' => 'portal_category',
            'label' => 'دسته بندی در پورتال',
            'class' => 'wc-portal-search',
            'value' => intval(get_post_meta($post->ID, 'portal_category', true)),
            'options' => $ocats
        ]);
    
        woocommerce_wp_text_input([
            'id' => 'course_id',
            'label' => 'آیدی در پورتال',
            'class' => 'wc-portal-input',
            'value' => intval(get_post_meta($post->ID, 'course_id', true)),
        ]);
    
        woocommerce_wp_text_input([
            'id' => 'course_code',
            'label' => 'کد دوره',
            'class' => 'wc-portal-input',
            'value' => intval(get_post_meta($post->ID, 'course_code', true)),
        ]);
        
        woocommerce_wp_text_input([
            'id' => 'installment_price',
            'label' => 'قیمت فروش اقساطی',
            'class' => 'wc-portal-input',
            'value' => intval(get_post_meta($post->ID, 'installment_price', true)),
        ]);

        woocommerce_wp_text_input([
            'id' => 'min_price_to_installment',
            'label' => 'حداقل پیش پرداخت',
            'class' => 'wc-portal-input',
            'value' => intval(get_post_meta($post->ID, 'min_price_to_installment', true)),
        ]);

        echo '</div>';
    }

    /**
     * Save custom general fields
     */
    public function save_custom_general_fields($post_id) {
        if (isset($_POST['course_id']) && !empty($_POST['course_id'])) {
            update_post_meta($post_id, 'course_id', $_POST['course_id']);
        }
        
        if (isset($_POST['portal_category']) && !empty($_POST['portal_category'])) {
            update_post_meta($post_id, 'portal_category', $_POST['portal_category']);
        }
        
        if (isset($_POST['course_code']) && !empty($_POST['course_code'])) {
            update_post_meta($post_id, 'course_code', $_POST['course_code']);
        }
        
        if (isset($_POST['installment_price']) && !empty($_POST['installment_price'])) {
            update_post_meta($post_id, 'installment_price', $_POST['installment_price']);
        }

        if (isset($_POST['min_price_to_installment']) && !empty($_POST['min_price_to_installment'])) {
            update_post_meta($post_id, 'min_price_to_installment', $_POST['min_price_to_installment']);
        }
    }
    
    /**
     * Get product selector (AJAX)
     */
    public function get_product_selector() {
        if ($_SERVER['REQUEST_METHOD'] != 'POST' || !isset($_REQUEST['item_id']) || !isset($_REQUEST['attr_name'])) {
            wp_send_json(['status' => false, 'message' => 'method not valid']);
            return;
        }
        
        $product = wc_get_product($_REQUEST['item_id']);
        if (!$product) {
            wp_send_json(['status' => false, 'message' => 'product not valid']);
            return;
        }

        wp_send_json($product->get_available_variations());
    }
}

