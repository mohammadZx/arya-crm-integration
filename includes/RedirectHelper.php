<?php

namespace Arya\Portal;

/**
 * RedirectHelper Class
 * 
 * Handles redirects related to portal integration
 */
class RedirectHelper {
    
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
        add_action('wp_loaded', [$this, 'redirect_to_product_by_crm_id']);
    }
    
    /**
     * Redirect to product by CRM ID
     * 
     * This function handles redirects when a cm_id parameter is present in the URL
     * It finds the product or product variation by ID and redirects to its permalink
     */
    public function redirect_to_product_by_crm_id() {
        if (isset($_GET['cm_id']) && esc_html($_GET['cm_id'])) {
            $args = [
                'post_type' => ['product', 'product_variation'],
                'post__in' => [esc_html($_GET['cm_id'])],
                'posts_per_page' => 1
            ];
            
            $posts = get_posts($args);
            
            if (!empty($posts)) {
                $post = $posts[0];
                // If it's a variation, get parent product permalink, otherwise use product permalink
                $permalink = get_permalink($post->post_parent ? $post->post_parent : $post->ID);
                
                if ($permalink) {
                    wp_redirect($permalink);
                    exit;
                }
            }
        }
    }
}

