<?php

require __DIR__.'/wp-stubs.php';
require __DIR__.'/../includes/PurchasePayload.php';
require __DIR__.'/../includes/PurchaseFulfillment.php';

function wc_get_price_decimals() { return 2; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['categories'][$id] ?? ''; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function wc_get_order($id) { return $GLOBALS['test_order']; }
function wp_date($format, $timestamp) { return date($format, $timestamp); }

class PurchaseTestLine {
    public function __construct(private $product, private $total, private $tax = 0, private $name = 'کالا', private $virtual = false) {}
    public function get_product_id() { return $this->product; }
    public function get_total() { return $this->total; }
    public function get_total_tax() { return $this->tax; }
    public function get_product() { return $this; }
    public function is_virtual() { return $this->virtual; }
    public function get_name() { return $this->name; }
    public function get_method_id() { return 'flat_rate'; }
    public function get_instance_id() { return 3; }
}
class PurchaseTestOrder {
    public $items = [];
    public $fees = [];
    public $shipping = [];
    public function get_items($type = 'line_item') { return $type === 'fee' ? $this->fees : ($type === 'shipping' ? $this->shipping : $this->items); }
    public function get_id() { return 100; }
    public function get_customer_id() { return 7; }
    public function get_meta($key) { return $key === '_arya_purchase_phone' ? '09120000000' : 17; }
    public function __call($name, $args) { return ''; }
}
function check_purchase($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS: {$message}\n"; }

$GLOBALS['categories'] = [1 => 2, 2 => 1];
$order = new PurchaseTestOrder();
$order->items = [10 => new PurchaseTestLine(1, 900, 90)];
$order->fees = [20 => new PurchaseTestLine(0, 30, 3, 'بسته‌بندی'), 21 => new PurchaseTestLine(0, -50, 0, 'تخفیف معرف')];
$order->shipping = [30 => new PurchaseTestLine(0, 100, 10, 'پست')];
$base = ['name' => 'مشتری', 'price' => 973, 'pay_price' => 973, 'items' => [['purchase_key' => 'woo-item-10', 'quantity' => 1, 'course_id' => 1, 'course_code' => 'P1', 'price' => 900]]];
$payloads = \Arya\Portal\PurchasePayload::groups($order, $base);
check_purchase(count($payloads) === 1, 'one category produces one purchase');
check_purchase($payloads[0]['purchase_total'] === 1083.0, 'goods, tax, positive fees, discount and shipping agree');
check_purchase($payloads[0]['purchase_discount'] === 50.0, 'positive fees are never invoice discounts');
check_purchase($payloads[0]['shipping_price'] === 100.0, 'shipping is preserved exactly once');
check_purchase($payloads[0]['shipping']['external_method'] === 'flat_rate:3', 'shipping method instance is carried to CRM');

$order->items[11] = new PurchaseTestLine(2, 100, 0, 'دوره', true);
$base['items'][] = ['purchase_key' => 'woo-item-11', 'quantity' => 1, 'course_id' => 2, 'course_code' => 'C1', 'price' => 100];
$payloads = \Arya\Portal\PurchasePayload::groups($order, $base);
check_purchase(count($payloads) === 2, 'mixed categories are imported independently');
check_purchase(abs(array_sum(array_column($payloads, 'purchase_total')) - 1183) < 0.0001, 'category allocation preserves the total charged');
check_purchase($payloads[1]['shipping_price'] === 0.0, 'virtual course is not charged product shipping');

$GLOBALS['arya_current_user'] = (object) ['ID' => 8];
$view = new \Arya\Portal\PurchaseFulfillment();
check_purchase($view->snapshot($order) === null, 'another customer cannot read purchase status');
$GLOBALS['arya_current_user'] = (object) ['ID' => 7];
$GLOBALS['test_order'] = $order;
set_transient('arya_purchase_100', ['label' => 'ارسال‌شده', 'tracking_code' => '00123', 'history' => [['label' => 'ارسال‌شده', 'at' => '2026-09-15T12:00:00Z', 'comment' => '<script>alert(1)</script>', 'internal_comment' => 'PRIVATE']]]);
ob_start(); $view->render(100); $html = ob_get_clean();
check_purchase(strpos($html, '&lt;script&gt;') !== false && strpos($html, '<script>') === false, 'customer comments are escaped');
check_purchase(strpos($html, 'PRIVATE') === false, 'internal notes never render even if accidentally present');
check_purchase(strpos($html, '00123') !== false, 'tracking retains leading zeroes');
