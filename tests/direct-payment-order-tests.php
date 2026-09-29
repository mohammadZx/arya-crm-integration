<?php
/**
 * مدرک، وجه پرداختی و آزمون نباید به remote-force-register بروند.
 *
 * اجرا: php tests/direct-payment-order-tests.php
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/OrderHandler.php';

use Arya\Portal\OrderHandler;

function get_post_field($field, $post_id) {
    return $GLOBALS['slugs'][(int) $post_id] ?? '';
}
function wc_get_order($id) {
    return $GLOBALS['orders'][$id] ?? null;
}
function wc_get_orders($args) {
    return $GLOBALS['retry_orders'] ?? [];
}
function wc_get_order_statuses() {
    return ['wc-processing' => 'Processing'];
}

class DirectPayItem {
    public function __construct(private $productId) {}
    public function get_product_id() { return $this->productId; }
}

class DirectPayOrder {
    public $saved = 0;
    public $meta = [];
    public $items = [];
    public function get_items($type = 'line_item') { return $this->items; }
    public function get_id() { return 375565; }
    public function get_meta($key) { return $this->meta[$key] ?? null; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() { $this->saved++; }
}

function check_direct($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$handler = OrderHandler::instance();
$detect = new ReflectionMethod($handler, 'is_direct_remote_payment_order');
$detect->setAccessible(true);

$GLOBALS['slugs'] = [10 => 'pay-payment', 11 => 'exam', 12 => 'python'];

$certificate = new DirectPayOrder();
$certificate->items = [new DirectPayItem(10)];
check_direct($detect->invoke($handler, $certificate) === true, 'certificate fee uses the pay-payment product');

$due = new DirectPayOrder();
$due->items = [new DirectPayItem(10)];
check_direct($detect->invoke($handler, $due) === true, 'course due payment uses the pay-payment product');

$exam = new DirectPayOrder();
$exam->items = [new DirectPayItem(11)];
check_direct($detect->invoke($handler, $exam) === true, 'exam payment uses the exam product');

$course = new DirectPayOrder();
$course->items = [new DirectPayItem(12)];
check_direct($detect->invoke($handler, $course) === false, 'a portal course still goes through force-register');

$mixed = new DirectPayOrder();
$mixed->items = [new DirectPayItem(11), new DirectPayItem(12)];
check_direct($detect->invoke($handler, $mixed) === false, 'a mixed order is not treated as a direct payment');

$GLOBALS['orders'] = [375565 => $exam];
$GLOBALS['arya_http_log'] = [];
$handler->insert_on_portal(375565);
check_direct($GLOBALS['arya_http_log'] === [], 'exam payment is not posted to remote-force-register');

$exam->meta[OrderHandler::RETRY_META] = ['payloads' => [['category_id' => '']], 'phone' => '09137687033', 'attempts' => 1];
$GLOBALS['retry_orders'] = [$exam];
$handler->retry_failed_orders();
check_direct(!isset($exam->meta[OrderHandler::RETRY_META]), 'queued exam retries are dropped');
check_direct($GLOBALS['arya_http_log'] === [], 'dropped retries do not call the CRM');
