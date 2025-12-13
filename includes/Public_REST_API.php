<?php

namespace Arya\Portal;

/**
 * Public_REST_API Class
 * 
 * Handles public REST API endpoints for site features
 * (search, menu, comments, etc.)
 */
class Public_REST_API {
    
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
        add_action('rest_api_init', [$this, 'register_rest_fields']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('rest_post_search_query', [$this, 'modify_search_query'], 10, 2);
        add_filter('post_search_columns', [$this, 'limit_search_columns']);
        add_filter('rest_prepare_comment', [$this, 'add_post_title_to_comment'], 10, 3);
        add_filter('rest_comment_query', [$this, 'include_all_comment_types'], 10, 2);
        add_filter('posts_search', [$this, 'custom_search_for_rest_api'], 10, 2);
        add_filter('wp_speculation_rules_href_exclude_paths', [$this, 'exclude_paths_from_speculation']);
    }
    
    /**
     * Register REST fields for search results
     */
    public function register_rest_fields() {
        // Image URL field
        register_rest_field('search-result', 'image_url', [
            'get_callback' => function ($post_arr) {
                return get_the_post_thumbnail_url($post_arr['id'], 'thumbnail');
            },
        ]);

        // Farsi subtype field
        register_rest_field('search-result', 'fa_subtype', [
            'get_callback' => function ($post_arr) {
                switch ($post_arr['subtype']) {
                    case 'post':
                        return 'مقالات';
                    case 'product':
                        return 'محصولات';
                    case 'podcast':
                        return 'پادکست ها';
                    case 'event':
                        return 'رویداد ها';
                    case 'page':
                        return 'صفحه';
                    default:
                        return '';
                }
            },
        ]);

        // Comments count field
        register_rest_field('search-result', 'comments', [
            'get_callback' => function ($post_arr) {
                return get_comment_count($post_arr['id']);
            },
        ]);

        // Rate field
        register_rest_field('search-result', 'rate', [
            'get_callback' => function ($post_arr) {
                if ($post_arr['subtype'] == 'product') {
                    $product = wc_get_product($post_arr['id']);
                    return $product ? $product->get_average_rating() : 0;
                }
                
                // Use theme function if available
                if (function_exists('get_post_rate')) {
                    return get_post_rate($post_arr['id']);
                }
                
                return 0;
            },
        ]);

        // Price field
        register_rest_field('search-result', 'price', [
            'get_callback' => function ($post_arr) {
                if ($post_arr['subtype'] != 'product') {
                    return null;
                }
                
                $product = wc_get_product($post_arr['id']);
                if (!$product) {
                    return null;
                }
                
                if ($product->is_type('simple')) {
                    return $product->get_sale_price();
                }
                
                return $product->get_variation_sale_price('max');
            },
        ]);
    }
    
    /**
     * Register REST routes
     */
    public function register_routes() {
        register_rest_route('get-menu', 'main-menu', [
            'methods' => 'GET',
            'callback' => [$this, 'get_main_menu'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    /**
     * Get main menu API
     */
    public function get_main_menu($data) {
        $primaryNav = wp_get_nav_menu_items('main-menu');
        return $this->filter_array_by_keys((array) $primaryNav, ['menu_item_parent', 'title', 'url', 'ID', 'icon', 'icon_type']);
    }
    
    /**
     * Filter array by keys
     */
    private function filter_array_by_keys(array $input, array $column_keys) {
        $result = [];
        $column_keys = array_flip($column_keys);
        
        foreach (json_decode(json_encode($input), true) as $key => $val) {
            $icon = get_post_meta($val['ID'], '_menu_icon', true);
            $val['icon'] = $icon;
            $val['icon_type'] = 'code';
            
            if (filter_var($icon, FILTER_VALIDATE_URL)) {
                $val['icon_type'] = 'image';
            }

            $result[$key] = array_intersect_key($val, $column_keys);
        }
        
        return $result;
    }
    
    /**
     * Modify search query order
     */
    public function modify_search_query($args, $query) {
        $args['orderby'] = 'post_type';
        $args['order'] = 'DESC';
        return $args;
    }
    
    /**
     * Limit search columns to post title only
     */
    public function limit_search_columns($search_columns) {
        return ['post_title'];
    }
    
    /**
     * Add post title to comment REST response
     */
    public function add_post_title_to_comment($response, $comment, $request) {
        $post_id = $comment->comment_post_ID;
        $post_title = get_the_title($post_id);
        $response->data['post_title'] = $post_title;
        return $response;
    }
    
    /**
     * Include all comment types in REST API
     */
    public function include_all_comment_types($args, $request) {
        $args['type__in'] = ['comment', 'review', 'pingback', 'trackback'];

        if (!empty($request['search'])) {
            $args['search'] = sanitize_text_field($request['search']);
        }
        
        return $args;
    }
    
    /**
     * Custom search for REST API with normalization
     */
    public function custom_search_for_rest_api($search, $query) {
        global $wpdb;

        // Only for REST API and search endpoint
        if (
            !defined('REST_REQUEST') || !REST_REQUEST ||
            !isset($query->query_vars['s']) ||
            !isset($_SERVER['REQUEST_URI']) ||
            strpos($_SERVER['REQUEST_URI'], '/wp-json/wp/v2/search') === false
        ) {
            return $search;
        }

        $original = esc_sql($query->query_vars['s']);
        $variants = explode(' ', $original);

        // Custom search query
        $search = " AND ( 
            EXISTS (
                SELECT 1 FROM {$wpdb->postmeta}
                WHERE {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID AND 
                {$wpdb->postmeta}.meta_key IN ('_yoast_wpseo_title') AND 
        ";
        
        $like_parts = [];
        $like_title = [];

        foreach ($variants as $term) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $like_parts[] = $wpdb->prepare("{$wpdb->postmeta}.meta_value LIKE %s", $like);
            $like_title[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $like);
        }
        
        $search .= implode(' AND ', $like_parts);
        $search .= ') OR (' . implode(' AND ', $like_title) . ') )';
        
        return $search;
    }
    
    /**
     * Exclude paths from speculation rules
     */
    public function exclude_paths_from_speculation($paths) {
        $paths[] = '/my-account';
        $paths[] = '/my-account/';
        return $paths;
    }
}

