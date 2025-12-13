<?php

namespace Arya\Portal;

/**
 * REST_API Class
 * 
 * Handles all REST API endpoints for portal integration
 */
class REST_API {
    
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
        add_action('rest_api_init', [$this, 'register_routes']);
        require_once ARYA_PORTAL_PLUGIN_DIR . 'includes/helpers/VariationHelper.php';
    }
    
    /**
     * Register REST routes
     */
    public function register_routes() {
        // Class course endpoints
        register_rest_route('portal', '/class-course', [
            'methods' => 'POST',
            'callback' => [$this, 'add_class_course'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        register_rest_route('portal', '/class-course', [
            'methods' => 'PUT',
            'callback' => [$this, 'edit_class_course'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        register_rest_route('portal', '/class-course', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_class_course'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        // Purge expire
        register_rest_route('portal', '/purge-exprire', [
            'methods' => 'GET',
            'callback' => [$this, 'purge_exprire']
        ]);

        // Product endpoints
        register_rest_route('portal', '/product', [
            'methods' => 'POST',
            'callback' => [$this, 'add_product'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        register_rest_route('portal', '/product', [
            'methods' => 'PUT',
            'callback' => [$this, 'edit_product'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        register_rest_route('portal', '/product', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_product'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        // Order endpoint
        register_rest_route('portal', '/order', [
            'methods' => 'POST',
            'callback' => [$this, 'add_order'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        // Course headline endpoint
        register_rest_route('portal', '/course-headline', [
            'methods' => 'POST',
            'callback' => [$this, 'add_headline'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        // Search endpoint
        register_rest_route('portal', '/search', [
            'methods' => 'GET',
            'callback' => [$this, 'search_product'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        // Sync product endpoint
        register_rest_route('portal', '/sync-product', [
            'methods' => 'POST',
            'callback' => [$this, 'sync_product'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);
    }
    
    /**
     * Check access from portal
     */
    public function check_access_from_portal($request) {
        $user = wp_get_current_user();
        return in_array('administrator', $user->roles) || in_array('shop_manager', $user->roles);
    }
    
    /**
     * Search product
     */
    public function search_product($request) {
        $search = sanitize_text_field($request->get_param('search'));
        $openType = ['product'];
        
        if ($request->get_param('open_vars')) {
            $openType[] = 'product_variation';
        }

        $args = [
            'post_type' => $openType,
            'posts_per_page' => 50,
            's' => $search,
            'post_status' => 'publish',
        ];

        $query = new \WP_Query($args);
        $results = [];

        foreach ($query->posts as $product_post) {
            $product = wc_get_product($product_post->ID);
            if (!$product) {
                continue;
            }

            $data = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'label' => $product->get_name(),
                'type' => $product->get_type(),
                'price' => $product->get_price(),
                'sku' => $product->get_sku(),
            ];

            $results[] = $data;
        }

        return rest_ensure_response($results);
    }
    
    /**
     * Sync product from portal
     */
    public function sync_product($request) {
        $params = $request->get_params();

        if (!$params['course_id'] || !$params['category_id']) {
            return ['status' => false, 'message' => 'ورودی نامعتبر', 'request' => $params];
        }

        if (isset($params['sync_by']) && is_array($params['sync_by'])) {
            // Validate
            foreach ($params['sync_by'] as $sync_product) {
                $product_id = $sync_product['id'];
                $product_course_id = get_post_meta($product_id, 'course_id', true);

                $post_type = get_post_type($product_id);
                if ($post_type === 'product') {
                    $target_id = $product_id;
                } elseif ($post_type === 'product_variation') {
                    $target_id = wp_get_post_parent_id($product_id);
                } else {
                    $target_id = null;
                }
                
                $category_id = get_post_meta($target_id, 'portal_category', true);

                if ($product_course_id && $product_course_id > 0 && $category_id) {
                    if (($product_course_id != $params['course_id'] && $category_id == $params['category_id']) ||
                        ($category_id != $params['category_id'])
                    ) {
                        return ['status' => false, 'message' => $sync_product['name'] . ' دارای ورودی دیگری می باشد'];
                    }
                }
            }

            foreach ($params['sync_by'] as $sync_product) {
                $product_id = $sync_product['id'];
                $product = wc_get_product($product_id);

                $settings = Settings::instance();
                if ($product && $product->get_type() === 'simple' && $params['category_id'] == $settings->get_course_category_id()) {
                    wp_set_object_terms($product_id, 'variable', 'product_type');
                }
                
                update_post_meta($product_id, 'course_id', $params['course_id']);

                $post_type = get_post_type($product_id);
                if ($post_type === 'product') {
                    $target_id = $product_id;
                } elseif ($post_type === 'product_variation') {
                    $target_id = wp_get_post_parent_id($product_id);
                } else {
                    $target_id = null;
                }
                
                update_post_meta($target_id, 'portal_category', $params['category_id']);
            }
        }

        return ['status' => true];
    }
    
    /**
     * Add headline
     */
    public function add_headline($request) {
        $params = $request->get_params();

        if (!$params['course_id'] || !$params['category_id']) {
            return ['status' => false, 'message' => 'ورودی نامعتبر', 'request' => $params];
        }

        $sync = $this->sync_product($request);
        if (isset($sync['status']) && !$sync['status']) {
            return $sync;
        }

        $args = [
            'post_type' => ['product'],
            'meta_query' => [
                [
                    'key' => 'course_id',
                    'value' => $params['course_id'],
                    'compare' => '=',
                ],
                [
                    'key' => 'portal_category',
                    'value' => $params['category_id'],
                    'compare' => '=',
                ],
            ]
        ];

        $posts = get_posts($args);
        if (!count($posts)) {
            return;
        }

        $variation_helper = new VariationHelper();
        $settings = Settings::instance();

        foreach ($posts as $post) {
            if (isset($params['headlines']) && $params['headlines']) {
                update_post_meta($post->ID, '_edumango_course_chapters', $params['headlines']);
            }

            update_post_meta($post->ID, 'installment_price', $params['course']['installment_price']);
            update_post_meta($post->ID, 'min_price_to_installment', $params['course']['min_price_to_installment']);

            // Delete variation inserted
            $variations = get_posts([
                'post_type' => 'product_variation',
                'numberposts' => -1,
                'post_parent' => $post->ID,
                'meta_query' => [
                    [
                        'key' => 'course_code',
                        'value' => [$settings->get_private_class_code()],
                        'compare' => 'IN',
                    ],
                ]
            ]);

            foreach ($variations as $variation_post) {
                wp_delete_post($variation_post->ID, true);
            }

            // Pre insert private
            $variation_helper->make_variation([
                'termname' => 'خصوصی (ساعتی)',
                'taxonomy' => defined('COURSE_ATTR_NAME') ? COURSE_ATTR_NAME : 'انتخاب زمان برگذاری دوره',
                'typeTaxonomy' => 'نوع دوره',
                'post' => $post,
                'post_ID' => $post->ID,
                'begin_date' => null,
                'day' => null,
                'time' => null,
                'time_to' => null,
                'course_duration' => null,
                'course_duration_unit' => null,
                'start_time' => null,
                'end_time' => null,
                'course_code' => $settings->get_private_class_code(),
                'course_type' => null,
                'class_course_comment' => null,
                'has_presence' => 'yes',
                'has_online' => 'yes',
                'price' => $params['course']['private_price'],
                'sale_price' => false,
                'sale_price_date_from' => false,
                'sale_price_date_to' => false
            ]);
        }

        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        return ['status' => true, 'message' => 'سرفصل ها با موفقیت برای این دوره ویرایش شد'];
    }
    
    /**
     * Add order
     */
    public function add_order($request) {
        $params = $request->get_params();
        
        if (!$params['product_ids'] || !$params['phone'] || !$params['name']) {
            return;
        }
        
        $user = get_user_by('login', $params['phone']);

        if (!$user) {
            $userId = wp_insert_user([
                'user_pass' => rand(0, 1000),
                'user_login' => $params['phone'],
                'user_email' => $params['phone'] . '@gmail.com',
                'display_name' => $params['name'],
                'role' => 'customer'
            ]);
            $user = get_user_by('ID', $userId);
        }

        $address = [
            'first_name' => $params['name'],
            'phone' => $params['phone'],
        ];

        $order = wc_create_order();
        
        foreach (explode(',', $params['product_ids']) as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            
            $item_id = $order->add_product($product, 1);

            $item = $order->get_item($item_id);
            if ($item) {
                $item->set_subtotal(0);
                $item->set_total(0);
                $item->save();
            }
        }
        
        $order->set_address($address, 'billing');
        $order->calculate_totals();
        $order->update_status("completed", 'ثبت اتوماتیک توسط crm', true);
        $order->set_customer_id($user->ID);
        $order->save();
        
        wc_downloadable_product_permissions($order->ID, true);
        
        return wp_send_json(['status' => true, 'message' => 'محصولات مرتبط به صورت اتوماتیک به پنل کاربر اضافه شد']);
    }
    
    /**
     * Add product (empty for now)
     */
    public function add_product($request) {
        return ['status' => false, 'message' => 'Not implemented'];
    }
    
    /**
     * Edit product
     */
    public function edit_product($request) {
        $params = $request->get_params();

        $items = [];
        if (isset($params['bulk']) && $params['bulk'] && isset($params['items']) && is_array($params['items'])) {
            $items = $params['items'];
        } else {
            $items[] = $params;
        }

        $total_change = [];
        $codes = [];

        foreach ($items as $p) {
            if (isset($p['code']) && $p['code']) {
                $codes[] = $p['code'];
            }
        }

        if (empty($codes)) {
            return $total_change;
        }

        $args = [
            'post_type' => ['product', 'product_variation'],
            'posts_per_page' => -1,
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => 'course_id',
                    'value' => $codes,
                    'compare' => 'IN',
                ]
            ]
        ];

        $all_posts = get_posts($args);

        $posts_by_code = [];
        $parent_ids = [];

        foreach ($all_posts as $post) {
            $c_id = get_post_meta($post->ID, 'course_id', true);
            if ($c_id) {
                $posts_by_code[$c_id][] = $post;
            }
            if ($post->post_type == 'product_variation' && $post->post_parent) {
                $parent_ids[] = $post->post_parent;
            }
        }

        // Optimize parent meta query
        if (!empty($parent_ids)) {
            update_meta_cache('post', array_unique($parent_ids));
        }

        foreach ($items as $p) {
            $code = $p['code'];
            if (!isset($posts_by_code[$code])) {
                $total_change[] = ['status' => false, 'message' => 'No product found for => ' . $code];
                continue;
            }

            $posts = $posts_by_code[$code];
            $quantity = $p['quantity'];

            foreach ($posts as $post) {
                if ($post->post_type == 'product_variation' && get_post_meta($post->post_parent, 'portal_category', true) != $p['category_id']) {
                    continue;
                } elseif ($post->post_type == 'product' && get_post_meta($post->ID, 'portal_category', true) != $p['category_id']) {
                    continue;
                }

                $product = $post->ID;

                if ($post->post_type == 'product_variation') {
                    $variation = new \WC_Product_Variation($product);
                } elseif ($post->post_type == 'product') {
                    $variation = new \WC_Product($product);
                }

                // Prices
                if (empty($p['sale_price'])) {
                    $variation->set_price(intval($p['price']));
                } else {
                    $variation->set_price(intval($p['price']));
                    $variation->set_sale_price(intval($p['sale_price']));
                }
                
                $variation->set_regular_price(intval($p['price']));

                if (isset($p['sale_price_date_from']) && $p['sale_price_date_from'] && isset($p['sale_price_date_to']) && $p['sale_price_date_to']) {
                    $variation->set_date_on_sale_from($p['sale_price_date_from']);
                    $variation->set_date_on_sale_to($p['sale_price_date_to']);
                }

                $variation->set_stock_quantity($quantity);

                if ($p['is_infinite']) {
                    $variation->set_manage_stock(false);
                    $variation->set_stock_status('instock');
                    $variation->set_virtual(true);
                } else {
                    $variation->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
                    $variation->set_manage_stock(true);
                    $variation->set_virtual(false);
                }
                
                $variation->set_stock_quantity($quantity);
                $variation->set_weight('');
                $variation->save();

                $data = $variation->get_data();
                unset($data['description']);
                unset($data['short_description']);
                unset($data['_yoast_wpseo_title']);
                $total_change[] = ['id' => $post->ID, 'type' => $post->post_type, 'data' => $data];
            }
        }

        return $total_change;
    }
    
    /**
     * Delete product (empty for now)
     */
    public function delete_product($request) {
        return ['status' => false, 'message' => 'Not implemented'];
    }
    
    /**
     * Add class course
     */
    public function add_class_course($request) {
        $params = $request->get_params();
        
        if (!$params['course_id'] || !$params['category_id']) {
            return;
        }

        $args = [
            'post_type' => 'product',
            'meta_query' => [
                [
                    'key' => 'course_id',
                    'value' => $params['course_id'],
                    'compare' => '=',
                ],
                [
                    'key' => 'portal_category',
                    'value' => $params['category_id'],
                    'compare' => '=',
                ],
            ]
        ];
        
        $posts = get_posts($args);
        $post = $posts[0] ?? null;
        
        if (!$post || !$params['begin_date'] || !$params['day'] || !$params['start_time'] || !$params['end_time'] || !$params['course_code']) {
            return;
        }
        
        if (strtotime($params['begin_date']) <= strtotime(date('Y-m-d'))) {
            return;
        }

        $variation_helper = new VariationHelper();
        $settings = Settings::instance();

        // Delete variation inserted
        $variations = get_posts([
            'post_type' => 'product_variation',
            'numberposts' => -1,
            'post_parent' => $post->ID,
            'meta_query' => [
                [
                    'key' => 'course_code',
                    'value' => [$params['course_code'], $settings->get_private_class_code()],
                    'compare' => 'IN',
                ],
            ]
        ]);

        foreach ($variations as $variation_post) {
            wp_delete_post($variation_post->ID, true);
        }

        $postedInfo = $variation_helper->insert_product_variable($post, $params);

        if (isset($postedInfo['variation_id'])) {
            $variation_helper->reorder_product_variable($postedInfo['variation_id']);
        }
        
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        
        return $postedInfo;
    }
    
    /**
     * Edit class course
     */
    public function edit_class_course($request) {
        $params = $request->get_params();
        
        if (!$params['course_id'] || !$params['category_id']) {
            return;
        }

        $args = [
            'post_type' => 'product',
            'meta_query' => [
                [
                    'key' => 'course_id',
                    'value' => $params['course_id'],
                    'compare' => '=',
                ],
                [
                    'key' => 'portal_category',
                    'value' => $params['category_id'],
                    'compare' => '=',
                ],
            ]
        ];
        
        $posts = get_posts($args);
        $post = $posts[0] ?? null;
        
        if (!$post || !$params['begin_date'] || !$params['day'] || !$params['start_time'] || !$params['end_time'] || !$params['course_code']) {
            return ['status' => false, 'message' => 'invalid data'];
        }
        
        if (strtotime($params['begin_date']) <= strtotime(date('Y-m-d'))) {
            return;
        }

        $variation_helper = new VariationHelper();
        $settings = Settings::instance();

        // Delete variation inserted
        $variations = get_posts([
            'post_type' => 'product_variation',
            'numberposts' => -1,
            'post_parent' => $post->ID,
            'meta_query' => [
                [
                    'key' => 'course_code',
                    'value' => [$params['course_code'], $settings->get_private_class_code()],
                    'compare' => 'IN',
                ],
            ]
        ]);

        foreach ($variations as $variation_post) {
            wp_delete_post($variation_post->ID, true);
        }

        $postedInfo = $variation_helper->insert_product_variable($post, $params);
        
        if (isset($postedInfo['variation_id'])) {
            $variation_helper->reorder_product_variable($postedInfo['variation_id']);
        }

        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        
        return $postedInfo;
    }
    
    /**
     * Delete class course
     */
    public function delete_class_course($request) {
        $params = $request->get_params();

        // Delete variation inserted
        $variations = get_posts([
            'post_type' => 'product_variation',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => 'course_code',
                    'value' => $params['course_code'],
                    'compare' => '=',
                ],
            ]
        ]);

        foreach ($variations as $variation) {
            wp_delete_post($variation->ID, true);
        }

        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        return ['status' => true];
    }
    
    /**
     * Purge expire
     */
    public function purge_exprire($request) {
        $posts = get_posts([
            'post_type' => 'product_variation',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => 'class_course_date_start',
                    'value' => date('Y-m-d', strtotime("-1 days")),
                    'compare' => '=',
                ]
            ]
        ]);
        
        foreach ($posts as $post) {
            wp_delete_post($post->ID, true);
        }

        return ['status' => true, 'count' => count($posts)];
    }
}

