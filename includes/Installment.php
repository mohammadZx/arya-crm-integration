<?php

namespace Arya\Portal;

/**
 * Installment Class
 * 
 * Handles installment payment logic
 */
class Installment {
    
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
        add_action('woocommerce_after_add_to_cart_quantity', [$this, 'prefix_after_cart_item_name'], 10, 2);
        add_filter('woocommerce_cart_item_price', [$this, 'filter_cart_item_price'], 10, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'filter_cart_item_subtotal'], 10, 3);
        add_action('woocommerce_before_calculate_totals', [$this, 'add_custom_price']);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
        // add_action('woocommerce_add_to_cart', [$this, 'custom_add_to_cart'], 10, 6); // Empty in theme
    }
    
    /**
     * Display installment checkbox after quantity field
     */
    public function prefix_after_cart_item_name() {
        global $product;
        if (!$this->can_installment_shop($product)) return;
        
        $installmentPrice = wc_price(get_post_meta($product->get_id(), 'installment_price', true));
        $minPrice = wc_price(get_post_meta($product->get_id(), 'min_price_to_installment', true));
        
        echo <<<HTML
            <div class="form-group pay-installment">
                <input type="checkbox" name="pay_as_installment" />
                <label for="installment">
                    رزرو تاریخ انتخابی با پیش پرداخت 
                    <strong>$minPrice</strong>
                </label>
            </div>
HTML;
    }
    
    /**
     * Filter cart item price
     */
    public function filter_cart_item_price($price_html, $cart_item, $cart_item_key) {
        if (!$this->can_installment_shop()) return $price_html;
        
        $pay_as_installment = isset($cart_item['pay_as_installment']) ? $cart_item['pay_as_installment'] : false;
        
        $installmentPrice = $this->get_installment_price(
            $cart_item['product_id'], 
            $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'], 
            $pay_as_installment
        );
        
        if ($installmentPrice) {
            return wc_price($installmentPrice);
        }
        
        return $price_html; 
    }
    
    /**
     * Filter cart item subtotal
     */
    public function filter_cart_item_subtotal($price_html, $cart_item, $cart_item_key) {
        if (!$this->can_installment_shop()) return $price_html;
        
        $pay_as_installment = isset($cart_item['pay_as_installment']) ? $cart_item['pay_as_installment'] : false;

        $installmentPrice = $this->get_installment_price(
            $cart_item['product_id'], 
            $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'], 
            $pay_as_installment
        );

        if ($installmentPrice) {
            return wc_price($installmentPrice * $cart_item['quantity']);
        }
        
        return $price_html; 
    }
    
    /**
     * Calculate total for installment
     */
    public function add_custom_price($cart) {
        if (!$this->can_installment_shop()) return;
        
        foreach ($cart->get_cart() as $cart_item) {
            $pay_as_installment = isset($cart_item['pay_as_installment']) ? $cart_item['pay_as_installment'] : false;

            $installmentPrice = $this->get_installment_price(
                $cart_item['product_id'], 
                $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'], 
                $pay_as_installment
            );
            
            if ($installmentPrice) {
                $cart_item['data']->set_price($installmentPrice);
                foreach ($cart->applied_coupons as $coupon) {
                    $cart->remove_coupon($coupon);
                }
                if (isset($_SESSION['REF_CODE'])) {
                    unset($_SESSION['REF_CODE']);
                }
                continue;
            }
        }
    }
    
    /**
     * Add cart item data
     */
    public function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        $cart_item_data['pay_as_installment'] = isset($_REQUEST['pay_as_installment']) ? $_REQUEST['pay_as_installment'] : false;
        return $cart_item_data;
    }
    
    /**
     * Check if product can be shopped as installment
     */
    public function can_installment_shop($product = null) {
        if ($product) {
            $installmentPrice = get_post_meta($product->get_id(), 'installment_price', true);
            $minPrice = get_post_meta($product->get_id(), 'min_price_to_installment', true);
            if (!intval($installmentPrice) || !intval($minPrice)) return false;
        }
        // if(!isset($_SESSION['REF_CODE']) || empty($_SESSION['REF_CODE']) || !getUserByMarketingCode($_SESSION['REF_CODE'])) return false;
        return true;
    }
    
    /**
     * Get installment price
     */
    public function get_installment_price($productId, $varId, $installmentPay = false) {
        if ($installmentPay != 'on') return false;
        $minPrice = get_post_meta($productId, 'min_price_to_installment', true);
        if (!intval($minPrice)) return false;
        return $minPrice;
    }
}

