<?php

namespace Arya\Portal;

/**
 * OrderHandler Class
 * 
 * Handles order-related operations with portal
 */
class OrderHandler {

    /** بسته‌هایی از سفارش که به CRM نرسیدند، برای تلاش دوباره. */
    const RETRY_META = '_arya_crm_retry';

    /** بسته‌هایی که بعد از همه‌ی تلاش‌ها هم نرسیدند (برای بررسی دستی). */
    const FAILED_META = '_arya_crm_failed';

    const RETRY_HOOK = 'arya_portal_retry_crm_orders';

    /** هر ۵ دقیقه، تا حدود ۶ ساعت. */
    const MAX_ATTEMPTS = 72;

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

        // سفارش پرداخت‌شده‌ای که ارسالش به CRM شکست خورد، دوباره فرستاده می‌شود؛
        // وگرنه کالایش در CRM نه رزرو می‌شد نه از انبار کم
        add_action(self::RETRY_HOOK, [$this, 'retry_failed_orders']);
        if (!wp_next_scheduled(self::RETRY_HOOK)) {
            wp_schedule_event(time() + 300, 'arya_portal_five_minutes', self::RETRY_HOOK);
        }

        // لغو/بازپرداخت در سایت به CRM خبر داده می‌شود تا رزرو کالا آزاد شود
        add_action('woocommerce_order_status_changed', [$this, 'push_status_to_portal'], 20, 4);
    }
    
    /**
     * سفارش فقط مدرک، وجه پرداختی یا آزمون است.
     *
     * این‌ها در CRM از payPayment / payExam می‌روند و category_id نمی‌خواهند.
     * محصول وجه و مدرک اسلاگ pay-payment است؛ آزمون اسلاگ exam.
     *
     * @param \WC_Order $order
     */
    private function is_direct_remote_payment_order($order) {
        $items = $order->get_items();
        if (!$items) {
            return false;
        }

        foreach ($items as $item) {
            $product_id = (int) $item->get_product_id();
            $slug = function_exists('get_post_field')
                ? (string) get_post_field('post_name', $product_id)
                : '';
            if (!in_array($slug, ['pay-payment', 'exam'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve buyer phone/name from WP user or WooCommerce billing fields (guest checkout).
     *
     * @param \WC_Order $order
     * @return array{phone: string, name: string}|null
     */
    private function resolve_buyer_from_order($order) {
        $user = $order->get_user();

        if ($user) {
            $phone = trim((string) $user->user_login);
            $name = trim((string) $user->display_name);

            if ($phone !== '') {
                return [
                    'phone' => $phone,
                    'name' => $name !== '' ? $name : $phone,
                ];
            }
        }

        $phone = trim((string) $order->get_billing_phone());
        $name = trim(
            trim((string) $order->get_billing_first_name()) . ' ' .
            trim((string) $order->get_billing_last_name())
        );

        if ($name === '') {
            $name = trim((string) $order->get_formatted_billing_full_name());
        }

        if ($phone === '') {
            return null;
        }

        return [
            'phone' => $phone,
            'name' => $name !== '' ? $name : $phone,
        ];
    }

    /**
     * Log that order was not sent to CRM (visible in plugin log viewer).
     *
     * @param int    $order_id
     * @param string $reason   Machine-readable reason key
     * @param string $message  Human-readable Persian message
     * @param array  $context  Extra context for debugging
     * @param string $stage    payment_complete|order_request|build_payload
     */
    private function log_order_send_skipped($order_id, $reason, $message, $context = [], $stage = 'payment_complete') {
        Logger::instance()->warning(
            Logger::ORDER_SEND_SKIPPED,
            $message,
            array_merge([
                'order_id' => $order_id,
                'reason' => $reason,
                'stage' => $stage,
            ], $context),
            Logger::SOURCE_PORTAL
        );
    }

    /**
     * Insert order on portal after payment complete
     * 
     * @param int $order_id Order ID
     * @param bool $get_info Whether to return data instead of sending
     * @return array|void
     */
    public function insert_on_portal($order_id, $get_info = false) {
        $stage = $get_info ? 'build_payload' : 'payment_complete';
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $this->log_order_send_skipped(
                $order_id,
                'order_not_found',
                'ارسال سفارش به CRM انجام نشد: سفارش پیدا نشد.',
                [],
                $stage
            );
            return;
        }

        // مدرک، وجه پرداختی و آزمون با remote-pay-payment / remote-pay-exam ثبت می‌شوند.
        // ForceRegisterController برای این‌ها category_id و portal_category نمی‌خواهد.
        if ($this->is_direct_remote_payment_order($order)) {
            return;
        }

        $buyer = $this->resolve_buyer_from_order($order);
        if (!$buyer) {
            $this->log_order_send_skipped(
                $order_id,
                'no_phone',
                'ارسال سفارش به CRM انجام نشد: شماره تلفن خریدار (کاربر یا billing_phone) موجود نیست.',
                [
                    'customer_id' => $order->get_customer_id(),
                    'billing_phone' => (string) $order->get_billing_phone(),
                    'billing_name' => trim(
                        trim((string) $order->get_billing_first_name()) . ' ' .
                        trim((string) $order->get_billing_last_name())
                    ),
                ],
                $stage
            );
            return;
        }

        $sendData = [];
        $productId = null;
        $varId = null;
        $courseCode = null;
        $courseId = null;
        $order_fee_total = 0;
        $quantity = 0;
        $is_online = false;
        
        foreach ($order->get_items() as $itemId => $item) {
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
                'purchase_key' => 'woo-item-'.$itemId,
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
        $sendData['phone'] = $buyer['phone'];
        $sendData['name'] = $buyer['name'];
        $sendData['price'] = $order->get_total() - $order->get_shipping_total();
        $sendData['pay_price'] = $order->get_total() - $order->get_shipping_total();
        $sendData['shipping_price'] = $order->get_shipping_total();
        $sendData['discount_total'] = $order_fee_total;
        $sendData['order_id'] = $order_id;
        // Woo status slug without wc- prefix (e.g. processing, completed)
        $sendData['order_status'] = method_exists($order, 'get_status') ? (string) $order->get_status() : '';
        $sendData['transaction'] = $order->get_transaction_id();
        $sendData['course_code'] = $courseCode;
        $sendData['course_id'] = $courseId;
        $sendData['category_id'] = get_post_meta($productId, 'portal_category', true);
        $sendData['quantity'] = $quantity;
        $sendData['is_online'] = $is_online;
        $sendData['gate_way'] = $order->get_payment_method();


        // Prefer Woo shipping address; fall back to billing when shipping is empty.
        $ship_address_1 = trim((string) $order->get_shipping_address_1());
        $ship_address_2 = trim((string) $order->get_shipping_address_2());
        $ship_city = trim((string) $order->get_shipping_city());
        $ship_state = trim((string) $order->get_shipping_state());
        $ship_postcode = trim((string) $order->get_shipping_postcode());
        $ship_country = trim((string) $order->get_shipping_country());
        $ship_first = trim((string) $order->get_shipping_first_name());
        $ship_last = trim((string) $order->get_shipping_last_name());

        if ($ship_address_1 === '' && $ship_city === '' && $ship_postcode === '') {
            $ship_address_1 = trim((string) $order->get_billing_address_1());
            $ship_address_2 = trim((string) $order->get_billing_address_2());
            $ship_city = trim((string) $order->get_billing_city());
            $ship_state = trim((string) $order->get_billing_state());
            $ship_postcode = trim((string) $order->get_billing_postcode());
            $ship_country = trim((string) $order->get_billing_country());
            if ($ship_first === '') {
                $ship_first = trim((string) $order->get_billing_first_name());
            }
            if ($ship_last === '') {
                $ship_last = trim((string) $order->get_billing_last_name());
            }
        }

        $address_parts = array_filter([$ship_address_1, $ship_address_2], static function ($p) {
            return $p !== '';
        });
        $sendData['shipping'] = [
            'address' => implode('، ', $address_parts),
            'city' => $ship_city,
            'state' => $ship_state,
            'postcode' => $ship_postcode,
            'country' => $ship_country,
            'first_name' => $ship_first,
            'last_name' => $ship_last,
        ];
        // Flat aliases for consumers that do not read nested shipping.
        $sendData['shipping_address'] = $sendData['shipping']['address'];
        $sendData['shipping_city'] = $ship_city;
        $sendData['shipping_state'] = $ship_state;
        $sendData['shipping_postcode'] = $ship_postcode;

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
            $site_payloads = PurchasePayload::groups($order, $sendData);
            // Legacy fallback: if no portal_category group matched, keep single sendData payload.
            if (!$site_payloads) {
                $site_payloads = [$sendData];
            }
            foreach ($site_payloads as &$site_payload) {
                if (!isset($site_payload['shipping']) && isset($sendData['shipping'])) {
                    $site_payload['shipping'] = $sendData['shipping'];
                }
                if (!isset($site_payload['shipping_address']) && isset($sendData['shipping_address'])) {
                    $site_payload['shipping_address'] = $sendData['shipping_address'];
                    $site_payload['shipping_city'] = $sendData['shipping_city'] ?? '';
                    $site_payload['shipping_state'] = $sendData['shipping_state'] ?? '';
                    $site_payload['shipping_postcode'] = $sendData['shipping_postcode'] ?? '';
                }
                if (!isset($site_payload['order_status']) && isset($sendData['order_status'])) {
                    $site_payload['order_status'] = $sendData['order_status'];
                }
                if (!isset($site_payload['order_id']) && isset($sendData['order_id'])) {
                    $site_payload['order_id'] = $sendData['order_id'];
                }
            }
            unset($site_payload);
            $sendData['site_payloads'] = $site_payloads;
            return $sendData;
        }

        // Force register to portal
        $personObject = new PersonData($buyer['phone']);
        $failed = [];
        $payloads = PurchasePayload::groups($order, $sendData);
        // Legacy fallback: empty groups must not skip CRM registration.
        if (!$payloads) {
            $payloads = [$sendData];
        }
        foreach ($payloads as $payload) {
            if (!isset($payload['shipping']) && isset($sendData['shipping'])) {
                $payload['shipping'] = $sendData['shipping'];
            }
            if (!isset($payload['shipping_address']) && isset($sendData['shipping_address'])) {
                $payload['shipping_address'] = $sendData['shipping_address'];
                $payload['shipping_city'] = $sendData['shipping_city'] ?? '';
                $payload['shipping_state'] = $sendData['shipping_state'] ?? '';
                $payload['shipping_postcode'] = $sendData['shipping_postcode'] ?? '';
            }
            if (!isset($payload['order_status']) && isset($sendData['order_status'])) {
                $payload['order_status'] = $sendData['order_status'];
            }
            if (!isset($payload['order_id']) && isset($sendData['order_id'])) {
                $payload['order_id'] = $sendData['order_id'];
            }
            if (!$this->register_purchase($order, $personObject, $payload, $buyer['phone'])) {
                $failed[] = $payload;
            }
        }

        $this->remember_failed($order, $failed, $buyer['phone']);

        return;
    }

    /**
     * یک بسته‌ی خرید (یک دسته) را در CRM ثبت می‌کند.
     *
     * CRM هر سفارش را برای هر دسته فقط یک‌بار ثبت می‌کند (site_order_imports)،
     * پس فرستادن دوباره‌ی بسته‌ای که قبلاً رسیده ولی پاسخش گم شده بی‌خطر است.
     *
     * @return bool رسید؟
     */
    private function register_purchase($order, PersonData $person, array $payload, $phone) {
        $response = $person->forceRegister($payload);
        $ok = is_object($response) && !empty($response->id) && !isset($response->errors);
        if (!$ok) {
            return false;
        }

        if (!empty($response->purchase_register_id)) {
            $order->update_meta_data('_arya_purchase_register_id', (int) $response->purchase_register_id);
            $order->update_meta_data('_arya_purchase_phone', $phone);
            $order->save();
        }

        return true;
    }

    /** بسته‌های نرسیده روی سفارش می‌مانند تا retry_failed_orders دوباره بفرستد. */
    private function remember_failed($order, array $failed, $phone) {
        if (!$failed) {
            return;
        }

        $order->update_meta_data(self::RETRY_META, [
            'payloads' => $failed,
            'phone' => $phone,
            'attempts' => 0,
        ]);
        $order->save();

        Logger::instance()->warning(
            Logger::ORDER_SEND_RETRY,
            'ثبت سفارش پرداخت‌شده در CRM شکست خورد؛ هر ۵ دقیقه دوباره تلاش می‌شود.',
            ['order_id' => $order->get_id(), 'payloads' => count($failed)],
            Logger::SOURCE_PORTAL
        );
    }

    /** اجرای زمان‌بندی‌شده: فرستادن دوباره‌ی بسته‌های نرسیده. */
    public function retry_failed_orders() {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        $orders = wc_get_orders([
            'limit' => 20,
            'type' => 'shop_order',
            'status' => array_keys(wc_get_order_statuses()),
            'meta_query' => [['key' => self::RETRY_META, 'compare' => 'EXISTS']],
        ]);

        foreach ($orders as $order) {
            if ($this->is_direct_remote_payment_order($order)) {
                $order->delete_meta_data(self::RETRY_META);
                $order->save();
                continue;
            }

            $retry = $order->get_meta(self::RETRY_META);
            if (!is_array($retry) || empty($retry['payloads'])) {
                $order->delete_meta_data(self::RETRY_META);
                $order->save();
                continue;
            }

            $person = new PersonData($retry['phone']);
            $still = [];
            foreach ($retry['payloads'] as $payload) {
                if (!$this->register_purchase($order, $person, $payload, $retry['phone'])) {
                    $still[] = $payload;
                }
            }

            $attempts = (int) ($retry['attempts'] ?? 0) + 1;
            $order = wc_get_order($order->get_id());

            if (!$still) {
                $order->delete_meta_data(self::RETRY_META);
            } elseif ($attempts >= self::MAX_ATTEMPTS) {
                $order->delete_meta_data(self::RETRY_META);
                $order->update_meta_data(self::FAILED_META, $still);
                Logger::instance()->error(
                    Logger::ORDER_SEND_FAILED,
                    'سفارش پرداخت‌شده بعد از همه‌ی تلاش‌ها در CRM ثبت نشد؛ آن را دستی ثبت کنید.',
                    ['order_id' => $order->get_id(), 'attempts' => $attempts],
                    Logger::SOURCE_PORTAL
                );
            } else {
                $order->update_meta_data(self::RETRY_META, [
                    'payloads' => $still,
                    'phone' => $retry['phone'],
                    'attempts' => $attempts,
                ]);
            }
            $order->save();
        }
    }

    /**
     * وضعیت سفارشِ ثبت‌شده در CRM عوض شد؛ مثلاً لغو یا بازپرداخت در سایت.
     * تغییری که خود CRM به سایت فرستاده هم برمی‌گردد و CRM آن را بی‌اثر رد می‌کند.
     */
    public function push_status_to_portal($order_id, $from, $to, $order = null) {
        $order = $order ?: wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $phone = $order->get_meta('_arya_purchase_phone');
        if (!$phone || !$order->get_meta('_arya_purchase_register_id')) {
            return;
        }

        (new PersonData($phone))->updatePurchaseStatus($order_id, $to);
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
            if (strpos($fee_name, 'تخفیف کد معرف: ') !== 0) continue;
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
        
        if (!$sendData || empty($sendData['phone'])) {
            // insert_on_portal already logs when it aborts; this covers empty payload edge cases.
            if ($sendData && empty($sendData['phone'])) {
                $this->log_order_send_skipped(
                    $order_id,
                    'no_phone',
                    'ارسال درخواست سفارش به CRM انجام نشد: شماره تلفن در دادهٔ ارسالی خالی است.',
                    [],
                    'order_request'
                );
            }
            return;
        }

        // Force register to portal (works for logged-in and guest buyers)
        $personObject = new PersonData($sendData['phone']);
        foreach ($sendData['site_payloads'] ?? [$sendData] as $payload) {
            unset($payload['site_payloads']);
            $personObject->forceRequest($payload);
        }
    }
}
