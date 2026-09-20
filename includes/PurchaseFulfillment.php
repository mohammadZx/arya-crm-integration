<?php

namespace Arya\Portal;

/** Public purchase timeline; CRM owns fulfillment, WooCommerce owns payment/refund records. */
class PurchaseFulfillment {
    public function __construct() {
        add_action('woocommerce_view_order', [$this, 'render'], 20);
        add_filter('woocommerce_account_orders_columns', [$this, 'columns']);
        add_action('woocommerce_my_account_my_orders_column_arya-purchase', [$this, 'column']);
    }

    public function columns($columns) {
        $columns['arya-purchase'] = 'آماده‌سازی و ارسال';
        return $columns;
    }

    public function snapshot($order) {
        if (!$order || !$order->get_meta('_arya_purchase_register_id')) return null;
        // Never expose a purchase via a guessed order ID or a different user's session.
        if (!is_user_logged_in() || (int) $order->get_customer_id() !== (int) get_current_user_id()) return null;
        $cacheKey = 'arya_purchase_'.$order->get_id();
        $cached = get_transient($cacheKey);
        if (is_array($cached)) return $cached;
        $phone = $order->get_meta('_arya_purchase_phone');
        $purchase = null;
        if ($phone) {
            $response = (new PersonData($phone))->getPurchaseStatus($order->get_id());
            if (is_object($response) && !empty($response->purchase) && (string) ($response->order_id ?? '') === (string) $order->get_id()) {
                $purchase = json_decode(wp_json_encode($response->purchase), true);
            }
        }
        // Fallback / enrich from Woo meta written when CRM syncs status + description.
        $purchase = $this->mergeOrderMeta($order, is_array($purchase) ? $purchase : null);
        if (!$purchase) return null;
        set_transient($cacheKey, $purchase, 30);
        return $purchase;
    }

    /**
     * When CRM remote-purchases is unavailable, still show status note / tracking
     * from order meta set by CRM Woo sync (_arya_tracking_code, _arya_status_note).
     */
    private function mergeOrderMeta($order, $purchase) {
        $tracking = trim((string) $order->get_meta('_arya_tracking_code'));
        $note = trim((string) $order->get_meta('_arya_status_note'));
        $status = method_exists($order, 'get_status') ? (string) $order->get_status() : '';
        $label = $status !== '' ? wc_get_order_status_name('wc-' . ltrim($status, 'wc-')) : '';

        if (!is_array($purchase)) {
            if ($tracking === '' && $note === '' && $label === '') {
                return null;
            }
            $purchase = [
                'label' => $label !== '' ? $label : 'وضعیت سفارش',
                'tracking_code' => $tracking !== '' ? $tracking : '',
                'history' => [],
            ];
        }

        if ($tracking !== '' && empty($purchase['tracking_code'])) {
            $purchase['tracking_code'] = $tracking;
        }
        // Only seed history from Woo meta when CRM did not already provide timeline entries.
        if ($note !== '' && (empty($purchase['history']) || !is_array($purchase['history']))) {
            $purchase['history'] = [[
                'label' => $label !== '' ? $label : 'به‌روزرسانی وضعیت',
                'at' => gmdate('c'),
                'comment' => $note,
                'tracking_code' => $tracking !== '' ? $tracking : null,
            ]];
        }
        return $purchase;
    }

    public function column($order) {
        $purchase = $this->snapshot($order);
        if (!$purchase) { echo '—'; return; }
        echo '<span>'.esc_html($purchase['label']).'</span>';
        if (!empty($purchase['tracking_code'])) echo '<br><small>کد رهگیری: <bdi>'.esc_html($purchase['tracking_code']).'</bdi></small>';
    }

    public function render($orderId) {
        $order = wc_get_order($orderId);
        $purchase = $this->snapshot($order);
        if (!$purchase) return;
        echo '<section class="arya-purchase-fulfillment" dir="rtl"><h2>مراحل آماده‌سازی و ارسال</h2>';
        echo '<p><strong>'.esc_html($purchase['label']).'</strong></p>';
        if (!empty($purchase['shipping']['name'])) echo '<p>روش ارسال: '.esc_html($purchase['shipping']['name']).'</p>';
        if (!empty($purchase['tracking_code'])) echo '<p>کد رهگیری: <bdi>'.esc_html($purchase['tracking_code']).'</bdi></p>';
        echo '<ol>';
        foreach ($purchase['history'] ?? [] as $entry) {
            echo '<li><strong>'.esc_html($entry['label']).'</strong> <time>'.esc_html(wp_date('Y/m/d H:i', strtotime($entry['at']))).'</time>';
            if (!empty($entry['comment'])) echo '<p style="white-space:pre-wrap">'.esc_html($entry['comment']).'</p>';
            if (!empty($entry['tracking_code'])) echo '<p>کد رهگیری: <bdi>'.esc_html($entry['tracking_code']).'</bdi></p>';
            echo '</li>';
        }
        echo '</ol></section>';
    }
}
