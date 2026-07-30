<?php

namespace Arya\Portal;

/**
 * OrderHandler Class
 * 
 * Handles order-related operations with portal
 */
class OrderHandler {
    
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
        add_action('woocommerce_payment_complete', [$this, 'insert_on_portal']);
        add_action('woocommerce_checkout_order_processed', [$this, 'insert_order_request_on_portal'], 10, 1);
        add_action('woocommerce_thankyou', [$this, 'complete_info'], 4);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'installment_order'], 10, 4);
    }
    
    /**
     * Insert order on portal after payment complete
     * 
     * @param int $order_id Order ID
     * @param bool $get_info Whether to return data instead of sending
     * @return array|void
     */
    public function insert_on_portal($order_id, $get_info = false) {
        $order = wc_get_order($order_id);
        
        if (!$order || !is_user_logged_in()) {
            return;
        }
        
        $user = wp_get_current_user();
        $sendData = [];
        $productId = null;
        $varId = null;
        $courseCode = null;
        $courseId = null;
        $order_fee_total = 0;
        $quantity = 0;
        $is_online = false;
        
        foreach ($order->get_items() as $item) {
            $productId = $item->get_product_id();
            $varId = $item->get_variation_id();

            if (!metadata_exists('post', $productId, 'portal_category')) {
                continue;
            }

            $courseCode = get_post_meta($productId, 'course_code', true);
            if (metadata_exists('post', $varId, 'course_code')) {
                $courseCode = get_post_meta($varId, 'course_code', true);
            }

            $courseId = get_post_meta($productId, 'course_id', true);
            if (metadata_exists('post', $varId, 'course_id') && get_post_meta($varId, 'course_id', true)) {
                $courseId = get_post_meta($varId, 'course_id', true);
            }

            $is_online = get_post_meta($varId, 'has_online', true) == 'yes' && 
                        (!get_post_meta($varId, 'has_presence', true) || get_post_meta($varId, 'has_presence', true) == 'no');

            $sendData['items'][] = [
                'course_id' => $courseId,
                'course_code' => $courseCode,
                'quantity' => $item->get_quantity(),
                'price' => $item->get_total()
            ];
            
            $quantity = $item->get_quantity();
        }

        foreach ($order->get_fees() as $fee_id => $fee) {
            $order_fee_total += $fee->get_total();
        }

        $sendData['coupons'] = $this->get_coupon_data($order);
        $sendData['phone'] = $user->user_login;
        $sendData['name'] = $user->display_name;
        $sendData['price'] = $order->get_total() - $order->get_shipping_total();
        $sendData['pay_price'] = $order->get_total() - $order->get_shipping_total();
        $sendData['shipping_price'] = $order->get_shipping_total();
        $sendData['discount_total'] = $order_fee_total;
        $sendData['order_id'] = $order_id;
        $sendData['transaction'] = $order->get_transaction_id();
        $sendData['course_code'] = $courseCode;
        $sendData['course_id'] = $courseId;
        $sendData['category_id'] = get_post_meta($productId, 'portal_category', true);
        $sendData['quantity'] = $quantity;
        $sendData['is_online'] = $is_online;
        $sendData['gate_way'] = $order->get_payment_method();

        $order_notes = wc_get_order_notes(['order_id' => $order_id, 'limit' => 1]);
        if (!empty($order_notes)) {
            $sendData['comments'] = $order_notes[0]->content;
        }

        // Check for installment payment
        foreach ($order->get_items() as $item_id => $item) {
            $pay_as_installment = $item->get_meta('pay_as_installment', true);
            if ($pay_as_installment == 'on') {
                $getProductId = $item->get_product_id();
                $installmentPrice = get_post_meta($getProductId, 'installment_price', true);
                $minInstallmentPrice = get_post_meta($getProductId, 'min_price_to_installment', true);
                $payPrice = $sendData['pay_price'];
                $sendData['price'] = $installmentPrice - ($minInstallmentPrice - $payPrice);
            }
        }

        if ($get_info) {
            return $sendData;
        }

        // Force register to portal
        $personObject = new PersonData($user->user_login);
        $personData = $personObject->forceRegister($sendData);
        
        return;
    }
    
    /**
     * Get coupon data from order
     * 
     * @param WC_Order $order
     * @return array
     */
    private function get_coupon_data($order) {
        $coupons = [];
        
        // Add user coupons
        foreach ($order->get_coupon_codes() as $coupon_code) {
            $coupon = new \WC_Coupon($coupon_code);
            $coupons[$coupon_code]['name'] = $coupon_code;
            $coupons[$coupon_code]['type'] = $coupon->get_discount_type();
            $coupons[$coupon_code]['amount'] = $coupon->get_amount();
            $coupons[$coupon_code]['source'] = 'site';
        }

        // Add marketing coupons
        foreach ($order->get_items('fee') as $item_id => $item_fee) {
            $fee_name = $item_fee->get_name();
            $code = str_replace('تخفیف کد معرف: ', '', $fee_name);
            
            if (!$code) {
                continue;
            }
            
            $code = trim($code);

            // Resolve marketing user + per-code commission from arya-account Marketing
            if (function_exists('getUserByMarketingCode')) {
                $marketing_user = getUserByMarketingCode($code);
                
                if ($marketing_user && class_exists('\Arya\Account\User\Marketing')) {
                    $marketing = new \Arya\Account\User\Marketing();
                    $rules = $marketing->getCodeRules($marketing_user->ID, $code);
                    $amount = isset($rules['commission']) ? floatval($rules['commission']) : 0;

                    $coupons[$code]['name'] = $code;
                    $coupons[$code]['user_phone'] = $marketing_user->user_login;
                    $coupons[$code]['type'] = 'percent';
                    $coupons[$code]['amount'] = $amount;
                    $coupons[$code]['source'] = 'marketing';
                }
            }
        }

        return $coupons;
    }
    
    /**
     * Complete info redirect after payment
     * 
     * @param int $order_id
     */
    public function complete_info($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order->has_status('completed')) {
            return;
        }
        
        $productId = null;
        foreach ($order->get_items() as $item) {
            $productId = $item->get_product_id();
        }
        
        $settings = Settings::instance();
        $categoryId = get_post_meta($productId, 'portal_category', true);

        if ($categoryId != $settings->get_course_category_id()) {
            return;
        }
        
        // This function should be available in theme
        if (function_exists('myAccount') && is_user_logged_in()) {
            $accountinfo = myAccount('user-info', true);
            echo '<script>window.location = "' . esc_js($accountinfo) . '"</script>';
        }
    }
    
    /**
     * Handle installment order meta
     * 
     * @param WC_Order_Item_Product $item
     * @param string $cart_item_key
     * @param array $values
     * @param WC_Order $order
     */
    public function installment_order($item, $cart_item_key, $values, $order) {
        if (isset($values['pay_as_installment']) && $values['pay_as_installment'] == 'on') {
            $item->add_meta_data(
                'pay_as_installment',
                $values['pay_as_installment'],
                true
            );
        }
    }
    
    /**
     * Insert order request on portal (before payment)
     * 
     * @param int $order_id
     */
    public function insert_order_request_on_portal($order_id) {
        $sendData = $this->insert_on_portal($order_id, true);
        
        if (!$sendData) {
            return;
        }
        
        $order = wc_get_order($order_id);
        $user = $order->get_user();
        
        if (!$user) {
            return;
        }
        
        // Force register to portal
        $personObject = new PersonData($user->user_login);
        $personData = $personObject->forceRequest($sendData);
    }
}

