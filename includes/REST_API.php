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

        // Catalog crawl endpoint (paginated, used by CRM bulk import)
        register_rest_route('portal', '/products', [
            'methods' => 'GET',
            'callback' => [$this, 'list_products'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        // Sync product endpoint
        register_rest_route('portal', '/sync-product', [
            'methods' => 'POST',
            'callback' => [$this, 'sync_product'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        // وجود محصولات با شناسه (CRM محصولات اهدایی خدمت را با آن بررسی می‌کند؛ فقط خواندنی)
        register_rest_route('portal', '/lookup', [
            'methods' => 'GET',
            'callback' => [$this, 'lookup_products'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);

        // فقط قیمت محصولِ خودِ یک خدمت (موجودی، مجازی بودن و ارسال دست نمی‌خورند)
        register_rest_route('portal', '/service-price', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_service_price'],
            'permission_callback' => [$this, 'check_access_from_portal']
        ]);
    }

    /**
     * پاسخ ناموفق یکدست برای CRM: status=false، کد ماشینی و پیام قابل‌نمایش.
     * CRM از روی code پیام را به زبان خودش ترجمه می‌کند و message را پشتیبان دارد.
     */
    private function fail($code, $message, $extra = []) {
        return array_merge(['status' => false, 'code' => $code, 'message' => $message], $extra);
    }

    private function ok($message, $extra = []) {
        return array_merge(['status' => true, 'code' => 'ok', 'message' => $message], $extra);
    }

    /** مادر یا تنوع دیگری از آن (به‌جز $except_id) course_id دارد؟ */
    private function has_other_mapped($parent_id, $except_id) {
        $ids = array_merge([(int) $parent_id], array_map('intval', get_children([
            'post_parent' => $parent_id,
            'post_type' => 'product_variation',
            'post_status' => 'any',
            'fields' => 'ids',
        ])));

        foreach ($ids as $id) {
            // کد انبار می‌تواند حرف هم داشته باشد؛ فقط خالی/صفر یعنی وصل نیست
            $mapped = trim((string) get_post_meta($id, 'course_id', true));
            if ($id !== (int) $except_id && $mapped !== '' && $mapped !== '0') {
                return true;
            }
        }

        return false;
    }

    /** محصولِ دوره/خدمت در سایت: متای course_id + portal_category */
    private function find_mapped_product($course_id, $category_id) {
        // مثل قبل فقط محصول منتشرشده (پیش‌فرض get_posts)
        $posts = get_posts([
            'post_type' => 'product',
            'meta_query' => [
                ['key' => 'course_id', 'value' => $course_id, 'compare' => '='],
                ['key' => 'portal_category', 'value' => $category_id, 'compare' => '='],
            ]
        ]);

        return $posts[0] ?? null;
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
     * Paginated catalog listing for the CRM bulk import.
     *
     * /portal/search is search-driven and capped at 50 rows, so it can never
     * walk a whole shop. Here we page over parent products and expand each
     * variable product into its variations, because the CRM stores every
     * variation as its own product row.
     *
     * CRM جدید دو پارامتر اختیاری می‌فرستد (CRM قدیمی همان خروجی قبلی را می‌گیرد):
     *   with_parents  ردیف خود محصول متغیر (kind=parent) پیش از واریانت‌هایش، تا CRM
     *                 محصول مادر بسازد و واریانت‌ها را فرزندش کند.
     *   with_content  توضیحات، عکس شاخص و ویژگی‌ها (ProductCatalog).
     */
    public function list_products($request) {
        $page = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        $per_page = $per_page > 0 ? min($per_page, 100) : 25;
        $with_variations = $request->get_param('variations');
        $with_variations = $with_variations === null ? true : rest_sanitize_boolean($with_variations);
        $with_parents = rest_sanitize_boolean($request->get_param('with_parents'));
        $with_content = rest_sanitize_boolean($request->get_param('with_content'));

        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
        ]);

        $items = [];

        foreach ($query->posts as $product_post) {
            $product = wc_get_product($product_post->ID);
            if (!$product) {
                continue;
            }

            $children = ($with_variations && $product->is_type('variable'))
                ? $product->get_children()
                : [];

            if (empty($children)) {
                $items[] = $this->crawl_item($product, null, $with_content);
                continue;
            }

            // مادر و واریانت‌هایش همیشه در یک صفحه‌اند (صفحه‌بندی روی مادرهاست)
            if ($with_parents) {
                $items[] = $this->crawl_item($product, null, $with_content, $children);
            }

            // یک محصول متغیر در CRM چند کالای مستقل می‌شود، پس والد ردیف ندارد.
            foreach ($children as $child_id) {
                $variation = wc_get_product($child_id);
                if (!$variation) {
                    continue;
                }
                $items[] = $this->crawl_item($variation, $product, $with_content);
            }
        }

        return rest_ensure_response([
            'status' => true,
            'page' => $page,
            'per_page' => $per_page,
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'items' => $items,
        ]);
    }

    /**
     * One crawl row. `parent` is set only for variations; `$variation_ids` only
     * for the row of a variable product itself (kind=parent).
     */
    private function crawl_item($product, $parent, $with_content = false, $variation_ids = null) {
        $id = $product->get_id();
        $name = $product->get_name();
        $variation_values = ($parent && $with_content) ? ProductCatalog::variationAttributes($product, $parent) : null;

        if ($parent) {
            // نام واریانت به‌تنهایی «Parent - Attr» است یا اصلاً خالی؛
            // برچسب ویژگی‌ها را می‌چسبانیم تا در CRM کالای قابل‌تشخیص باشد.
            // با with_content نام مقدار (نه نامک URLشده‌ی ویژگی سراسری) می‌آید.
            $attributes = $variation_values !== null
                ? array_column($variation_values, 'option')
                : array_filter(array_values($product->get_variation_attributes()));
            $suffix = $attributes ? ' - ' . implode(' / ', $attributes) : '';
            $name = $parent->get_name() . $suffix;
        }

        $stock = $product->get_stock_quantity();
        $kind = $parent ? 'variation' : ($variation_ids !== null ? 'parent' : 'simple');

        $row = [
            'id' => $id,
            'parent_id' => $parent ? $parent->get_id() : 0,
            'name' => $name,
            'label' => $name,
            'type' => $parent ? 'variation' : $product->get_type(),
            'kind' => $kind,
            // واریانتِ بی‌SKU در حالت نمایش SKU مادر را برمی‌گرداند و CRM همه‌ی
            // واریانت‌ها را یک کالا (یا کالای بی‌ربطی با همان کد) می‌دید؛ فقط SKU خودش.
            'sku' => $parent ? $product->get_sku('edit') : $product->get_sku(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'manage_stock' => (bool) $product->get_manage_stock(),
            'stock_quantity' => $stock === null ? null : (float) $stock,
            'in_stock' => $product->is_in_stock(),
            'permalink' => get_permalink($parent ? $parent->get_id() : $id),
            // اگر قبلاً به CRM وصل شده، کد انبارش اینجاست؛ برای تشخیص تکراری.
            'course_id' => get_post_meta($id, 'course_id', true),
            'portal_category' => get_post_meta($parent ? $parent->get_id() : $id, 'portal_category', true),
        ];

        if ($variation_ids !== null) {
            $row['variation_ids'] = array_values(array_map('intval', $variation_ids));
        }

        if ($with_content) {
            $row += ProductCatalog::content($product);
            $row['image'] = ProductCatalog::image($product);
            $row['attributes'] = $parent ? $variation_values : ProductCatalog::attributes($product);
        }

        return $row;
    }

    /**
     * Sync product from portal
     */
    public function sync_product($request) {
        $params = $request->get_params();

        if (empty($params['course_id']) || empty($params['category_id'])) {
            return $this->fail('invalid_request', 'شناسهٔ آیتم یا دستهٔ پورتال ارسال نشده است.');
        }

        $mapped = 0;
        if (isset($params['sync_by']) && is_array($params['sync_by'])) {
            // Validate
            foreach ($params['sync_by'] as $sync_product) {
                $product_id = $sync_product['id'] ?? null;
                if (!$product_id || !wc_get_product($product_id)) {
                    $label = !empty($sync_product['name']) ? $sync_product['name'] . ' #' . $product_id : '#' . $product_id;
                    return $this->fail('product_not_found', 'محصول ' . $label . ' در سایت پیدا نشد.', ['product_id' => $product_id]);
                }
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
                        return $this->fail('mapping_conflict', ($sync_product['name'] ?? ('#' . $product_id)) . ' دارای ورودی دیگری می باشد', [
                            'product_id' => $product_id,
                            'mapped_to' => $product_course_id,
                            'mapped_category' => $category_id,
                        ]);
                    }
                }

                // دسته روی محصول مادر است: اگر خود مادر یا تنوع دیگری از آن هنوز با دستهٔ دیگری وصل است،
                // عوض کردن دسته سفارش‌های آن‌ها را هم عوض می‌کند (مثلاً کالای انبار خدمت ثبت می‌شود)
                if ($target_id && $category_id && $category_id != $params['category_id'] && $this->has_other_mapped($target_id, (int) $product_id)) {
                    return $this->fail('mapping_conflict', 'محصول مادرِ ' . ($sync_product['name'] ?? ('#' . $product_id)) . ' به دستهٔ دیگری وصل است؛ تنوع‌های یک محصول نمی‌توانند در دو دسته (مثلاً کالا و خدمت) باشند.', [
                        'product_id' => $product_id,
                        'parent_id' => $target_id,
                        'mapped_category' => $category_id,
                    ]);
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
                $mapped++;
            }
        }

        return $this->ok($mapped ? 'اتصال ' . $mapped . ' محصول در سایت ثبت شد.' : 'محصولی برای اتصال انتخاب نشده بود.', ['mapped' => $mapped]);
    }
    
    /**
     * Add headline
     */
    public function add_headline($request) {
        $params = $request->get_params();

        if (empty($params['course_id']) || empty($params['category_id'])) {
            return $this->fail('invalid_request', 'شناسهٔ دوره یا دستهٔ پورتال ارسال نشده است.');
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
            return $this->fail('course_product_not_found', 'محصول این دوره در سایت پیدا نشد؛ محصول سایت را در فرم دوره انتخاب و دوباره سینک کنید.');
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

        return $this->ok('سرفصل ها با موفقیت برای این دوره ویرایش شد', ['products' => count($posts)]);
    }
    
    /**
     * Add order
     */
    public function add_order($request) {
        $params = $request->get_params();
        
        if (empty($params['product_ids']) || empty($params['phone']) || empty($params['name'])) {
            return $this->fail('invalid_request', 'شناسهٔ محصولات، شماره یا نام شخص ارسال نشده است.');
        }

        $products = [];
        $missing = [];
        foreach (explode(',', $params['product_ids']) as $pid) {
            $pid = trim($pid);
            if ($pid === '') {
                continue;
            }
            $product = wc_get_product($pid);
            $product ? $products[] = $product : $missing[] = $pid;
        }
        if (!$products) {
            return $this->fail('product_not_found', 'هیچ‌کدام از محصولات (' . implode(', ', $missing) . ') در سایت پیدا نشد.', ['missing' => $missing]);
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
        
        foreach ($products as $product) {
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
        
        return wp_send_json($this->ok('محصولات مرتبط به صورت اتوماتیک به پنل کاربر اضافه شد', [
            'order_id' => $order->get_id(),
            'missing' => $missing,
        ]));
    }
    
    /**
     * وجود محصولات با شناسه (فقط خواندنی). ids = "1,2,3"
     */
    public function lookup_products($request) {
        $ids = array_filter(array_map('trim', explode(',', (string) $request->get_param('ids'))), 'strlen');
        $items = [];
        $missing = [];

        foreach (array_slice(array_unique($ids), 0, 200) as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                $missing[] = $id;
                continue;
            }
            $items[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'type' => $product->get_type(),
                'status' => $product->get_status(),
            ];
        }

        return $this->ok('', ['items' => $items, 'missing' => array_values($missing)]);
    }

    /**
     * قیمت محصولِ خودِ یک خدمت (course_id = شناسهٔ خدمت، portal_category = دستهٔ خدمات).
     * برخلاف PUT /product به موجودی، مجازی بودن یا ارسال دست نمی‌زند.
     */
    public function update_service_price($request) {
        $params = $request->get_params();
        if (empty($params['course_id']) || empty($params['category_id']) || !isset($params['price'])) {
            return $this->fail('invalid_request', 'شناسهٔ خدمت، دسته یا قیمت ارسال نشده است.');
        }

        $posts = get_posts([
            'post_type' => ['product', 'product_variation'],
            'numberposts' => -1,
            'meta_query' => [['key' => 'course_id', 'value' => $params['course_id'], 'compare' => '=']],
        ]);

        $updated = [];
        foreach ($posts as $post) {
            $category_owner = $post->post_type === 'product_variation' ? $post->post_parent : $post->ID;
            if (get_post_meta($category_owner, 'portal_category', true) != $params['category_id']) {
                continue;
            }
            $product = wc_get_product($post->ID);
            if (!$product || $product->is_type('variable')) {
                continue;
            }

            $price = intval($params['price']);
            $sale = isset($params['sale_price']) && $params['sale_price'] !== '' && $params['sale_price'] !== null ? intval($params['sale_price']) : '';
            $product->set_regular_price($price);
            $product->set_sale_price($sale);
            $product->set_price($sale !== '' ? $sale : $price);
            $product->save();
            $updated[] = $post->ID;
        }

        if (!$updated) {
            return $this->fail('service_product_not_found', 'محصولی با این خدمت در سایت پیدا نشد؛ اول محصول خدمت را سینک کنید.');
        }

        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        return $this->ok('قیمت خدمت روی سایت به‌روز شد.', ['updated' => $updated]);
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
                    // فروش بدون موجودی (پیش‌فروش): CRM می‌گوید الان باز است یا نه؛
                    // CRM قدیمی که این فیلد را نمی‌فرستد همان رفتار قبلی (no) را می‌گیرد
                    $backorders = Backorder::from_payload($p);
                    $variation->set_manage_stock(true);
                    $variation->set_backorders($backorders['open'] ? 'yes' : 'no');
                    $variation->set_stock_status(Backorder::stock_status($quantity, $backorders['open']));
                    $variation->set_virtual(false);
                    Backorder::store_limit($variation, $backorders['limit']);
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
        return $this->upsert_class_course($request);
    }

    /**
     * Edit class course (همان کار ثبت: تنوع قبلی همین کد کلاس پاک و دوباره ساخته می‌شود)
     */
    public function edit_class_course($request) {
        return $this->upsert_class_course($request);
    }

    /**
     * ثبت/به‌روزرسانی تنوع کلاس روی محصول دوره. هر حالت ناموفق علت خودش را برمی‌گرداند؛
     * قبلاً پاسخ خالی بود و CRM نمی‌دانست کلاس روی سایت رفت یا نه.
     */
    private function upsert_class_course($request) {
        $params = $request->get_params();

        if (empty($params['course_id']) || empty($params['category_id'])) {
            return $this->fail('invalid_request', 'شناسهٔ دوره یا دستهٔ پورتال ارسال نشده است.');
        }

        $post = $this->find_mapped_product($params['course_id'], $params['category_id']);
        if (!$post) {
            return $this->fail('course_product_not_found', 'محصول این دوره در سایت پیدا نشد؛ اول دوره را با محصول سایت سینک کنید.');
        }

        $missing = [];
        foreach (['begin_date', 'day', 'start_time', 'end_time', 'course_code'] as $key) {
            if (empty($params[$key])) {
                $missing[] = $key;
            }
        }
        if ($missing) {
            return $this->fail('incomplete', 'اطلاعات کلاس کامل نیست: ' . implode(', ', $missing), ['missing' => $missing]);
        }

        if (strtotime($params['begin_date']) <= strtotime(date('Y-m-d'))) {
            return $this->fail('past_start', 'تاریخ شروع کلاس گذشته است؛ کلاس شروع‌شده روی سایت عرضه نمی‌شود.');
        }

        $product = wc_get_product($post->ID);
        if (!$product || !$product->is_type('variable')) {
            return $this->fail('not_variable', 'محصول دوره در سایت از نوع «متغیر» نیست.', ['product_id' => $post->ID]);
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

        if (empty($postedInfo['variation_id'])) {
            return $this->fail('variation_failed', 'ساخت تنوع کلاس روی سایت انجام نشد (نوع برگزاری حضوری/آنلاین را بررسی کنید).', ['product_id' => $post->ID]);
        }

        $variation_helper->reorder_product_variable($postedInfo['variation_id']);

        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        return $this->ok('کلاس روی سایت ثبت شد.', [
            'product_id' => $post->ID,
            'variation_id' => $postedInfo['variation_id'],
            'term' => $postedInfo['term'] ?? null,
        ]);
    }

    /**
     * Delete class course
     */
    public function delete_class_course($request) {
        $params = $request->get_params();

        if (empty($params['course_code'])) {
            return $this->fail('invalid_request', 'کد کلاس ارسال نشده است.');
        }

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

        return $this->ok(
            count($variations) ? 'کلاس از سایت حذف شد.' : 'این کلاس روی سایت نبود.',
            ['deleted' => count($variations)]
        );
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

