<?php

namespace Arya\Portal;

/**
 * Public purchase timeline; CRM owns fulfillment, WooCommerce owns payment/refund records.
 *
 * CRM writes the shipments of an order into the order meta `_arya_shipments`
 * (JSON: {version, updated_at, shipments: [{number, status, label, color, method,
 * tracking_code, tracking_url, note, items, shipped_at, delivered_at, history}]}).
 * Rendering reads only that meta — no request to the CRM on page load, so the
 * account pages stay fast. Orders from before the shipping module fall back to
 * `_arya_tracking_code` / `_arya_status_note` and the WooCommerce status.
 */
class PurchaseFulfillment {
    public function __construct() {
        add_action('woocommerce_view_order', [$this, 'render'], 20);
        add_filter('woocommerce_account_orders_columns', [$this, 'columns']);
        // arya-account renders its own order list through this filter
        add_filter('woocommerce_my_account_my_orders_columns', [$this, 'columns']);
        add_action('woocommerce_my_account_my_orders_column_arya-purchase', [$this, 'column']);
    }

    public function columns($columns) {
        $columns['arya-purchase'] = 'آماده‌سازی و ارسال';
        return $columns;
    }

    /** @return array|null */
    public function snapshot($order) {
        if (!$order) return null;
        // Never expose a purchase via a guessed order ID or a different user's session.
        if (!is_user_logged_in() || (int) $order->get_customer_id() !== (int) get_current_user_id()) return null;

        $shipments = $this->shipments($order);
        if ($shipments) {
            $latest = end($shipments);
            $tracking = '';
            foreach (array_reverse($shipments) as $row) {
                if (!empty($row['tracking_code'])) { $tracking = (string) $row['tracking_code']; break; }
            }
            return [
                'label' => (string) ($latest['label'] ?? ''),
                'tracking_code' => $tracking,
                'shipments' => $shipments,
                'history' => [],
            ];
        }

        if (!$order->get_meta('_arya_purchase_register_id')) return null;
        return $this->mergeOrderMeta($order, null);
    }

    /** @return array<int, array> */
    private function shipments($order) {
        $raw = $order->get_meta('_arya_shipments');
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || empty($raw['shipments']) || !is_array($raw['shipments'])) return [];
        return array_values(array_filter($raw['shipments'], 'is_array'));
    }

    /**
     * Orders without shipments: status note / tracking from order meta set by the
     * CRM Woo sync (_arya_tracking_code, _arya_status_note) and the Woo status.
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
        if (!empty($purchase['shipments']) && count($purchase['shipments']) > 1) {
            foreach ($purchase['shipments'] as $shipment) {
                echo '<span class="d-block">'.esc_html($shipment['label'] ?? '');
                if (!empty($shipment['tracking_code'])) echo ' — <bdi>'.esc_html($shipment['tracking_code']).'</bdi>';
                echo '</span>';
            }
            return;
        }
        echo '<span>'.esc_html($purchase['label']).'</span>';
        if (!empty($purchase['tracking_code'])) echo '<br><small>کد رهگیری: <bdi>'.esc_html($purchase['tracking_code']).'</bdi></small>';
    }

    public function render($orderId) {
        $order = wc_get_order($orderId);
        $purchase = $this->snapshot($order);
        if (!$purchase) return;
        echo '<section class="arya-purchase-fulfillment" dir="rtl"><h2>مراحل آماده‌سازی و ارسال</h2>';

        if (!empty($purchase['shipments'])) {
            $count = count($purchase['shipments']);
            foreach ($purchase['shipments'] as $index => $shipment) {
                $this->renderShipment($shipment, $count > 1 ? $index + 1 : null);
            }
            echo '</section>';
            return;
        }

        echo '<p><strong>'.esc_html($purchase['label']).'</strong></p>';
        if (!empty($purchase['shipping']['name'])) echo '<p>روش ارسال: '.esc_html($purchase['shipping']['name']).'</p>';
        if (!empty($purchase['tracking_code'])) echo '<p>کد رهگیری: <bdi>'.esc_html($purchase['tracking_code']).'</bdi></p>';
        $this->renderHistory($purchase['history'] ?? []);
        echo '</section>';
    }

    private function renderShipment(array $shipment, $index) {
        $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($shipment['color'] ?? '')) ? $shipment['color'] : '#777';
        echo '<div class="arya-shipment" style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:12px">';
        echo '<p style="margin:0 0 6px">';
        if ($index !== null) echo '<span>بستهٔ '.esc_html((string) $index).' — </span>';
        echo '<strong style="color:'.esc_attr($color).'">'.esc_html($shipment['label'] ?? '').'</strong>';
        echo '</p>';
        if (!empty($shipment['method'])) echo '<p style="margin:0 0 4px">روش ارسال: '.esc_html($shipment['method']).'</p>';
        if (!empty($shipment['tracking_code'])) {
            echo '<p style="margin:0 0 4px">کد رهگیری: ';
            if (!empty($shipment['tracking_url'])) {
                echo '<a href="'.esc_url($shipment['tracking_url']).'" target="_blank" rel="noopener nofollow"><bdi>'.esc_html($shipment['tracking_code']).'</bdi></a>';
            } else {
                echo '<bdi>'.esc_html($shipment['tracking_code']).'</bdi>';
            }
            echo '</p>';
        }
        if (!empty($shipment['note'])) echo '<p style="white-space:pre-wrap;margin:0 0 4px">'.esc_html($shipment['note']).'</p>';
        if (!empty($shipment['items']) && is_array($shipment['items'])) {
            $parts = [];
            foreach ($shipment['items'] as $item) {
                if (!is_array($item)) continue;
                $parts[] = esc_html(($item['name'] ?? '').' × '.rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ''), '0'), '.'));
            }
            if ($parts) echo '<p style="margin:0 0 4px"><small>'.implode('، ', $parts).'</small></p>';
        }
        $history = [];
        foreach ((array) ($shipment['history'] ?? []) as $event) {
            if (!is_array($event) || empty($event['at'])) continue;
            $history[] = ['label' => $event['label'] ?? '', 'at' => $event['at'], 'tracking_code' => $event['tracking_code'] ?? null];
        }
        $this->renderHistory($history);
        echo '</div>';
    }

    private function renderHistory(array $history) {
        if (!$history) return;
        echo '<ol>';
        foreach ($history as $entry) {
            echo '<li><strong>'.esc_html($entry['label'] ?? '').'</strong> <time>'.esc_html(wp_date('Y/m/d H:i', strtotime($entry['at']))).'</time>';
            if (!empty($entry['comment'])) echo '<p style="white-space:pre-wrap">'.esc_html($entry['comment']).'</p>';
            if (!empty($entry['tracking_code'])) echo '<p>کد رهگیری: <bdi>'.esc_html($entry['tracking_code']).'</bdi></p>';
            echo '</li>';
        }
        echo '</ol>';
    }
}
