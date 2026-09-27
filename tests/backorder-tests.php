<?php
/**
 * فروش بدون موجودی (پیش‌فروش): خواندن فیلدهای CRM و اجرای سقف.
 *
 * اجرا:  php tests/backorder-tests.php
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/Backorder.php';

use Arya\Portal\Backorder;

function wc_stock_amount($amount) { return (int) $amount; }
function wc_add_notice($message, $type) { $GLOBALS['notices'][] = [$type, $message]; }
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? null; }
function WC() { return $GLOBALS['woo']; }

class BackorderTestProduct {
    public $saved = 0;
    private $meta = [];
    public function __construct(private $id, private $stock, private $backorders = 'yes', private $manage = true) {}
    public function get_id() { return $this->id; }
    public function get_stock_managed_by_id() { return $this->id; }
    public function get_name() { return 'کتاب'; }
    public function managing_stock() { return $this->manage; }
    public function get_manage_stock() { return $this->manage; }
    public function get_backorders() { return $this->backorders; }
    public function set_backorders($value) { $this->backorders = $value; }
    public function get_stock_quantity() { return $this->stock; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() { $this->saved++; }
}

class BackorderTestCart {
    public $quantities = [];
    public function get_cart_item_quantities() { return $this->quantities; }
}

function check_backorder($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$GLOBALS['woo'] = (object) ['cart' => new BackorderTestCart()];
$backorder = Backorder::instance();

// فیلدهای CRM
check_backorder(Backorder::from_payload([]) === ['open' => false, 'limit' => null], 'old CRM without the fields keeps back-orders off');
check_backorder(Backorder::from_payload(['backorders' => true, 'backorder_limit' => null]) === ['open' => true, 'limit' => null], 'open without a limit');
check_backorder(Backorder::from_payload(['backorders' => 'false', 'backorder_limit' => '5']) === ['open' => false, 'limit' => 5.0], 'string booleans from form-encoded requests');

// وضعیت موجودی و متن نمایش
check_backorder(Backorder::stock_status(0, true) === 'onbackorder', 'open pre-sale with no stock is onbackorder');
check_backorder(Backorder::stock_status(-2, false) === 'outofstock', 'closed and empty is out of stock');
check_backorder(Backorder::stock_status(4, false) === 'instock', 'positive stock is in stock');
$status = new class {
    public $backorders = 'yes';
    public function get_backorders() { return $this->backorders; }
    public function get_stock_status() { return 'onbackorder'; }
};
check_backorder($backorder->availability_text('موجود برای پیش‌سفارش', $status) === '', 'pre-sale shows no back-order text');
$status->backorders = 'notify';
check_backorder($backorder->availability_text('موجود برای پیش‌سفارش', $status) === 'موجود برای پیش‌سفارش', 'notify keeps its text');

// سقف روی محصول
$product = new BackorderTestProduct(10, 0);
Backorder::store_limit($product, 3.0);
check_backorder(Backorder::sellable($product) === 3.0, 'stock 0 + limit 3 ⇒ 3 sellable');
Backorder::store_limit($product, null);
check_backorder(Backorder::sellable($product) === null, 'no limit ⇒ nothing to enforce');
check_backorder(Backorder::sellable(new BackorderTestProduct(11, 0, 'no')) === null, 'closed back-orders are Woo\'s own job');

// صفحه‌ی محصول و سبد
$limited = new BackorderTestProduct(20, -1);
Backorder::store_limit($limited, 3.0);
$GLOBALS['products'][20] = $limited;
$args = $backorder->quantity_input_args(['max_value' => -1], $limited);
check_backorder($args['max_value'] === 2.0, 'quantity input max is stock + limit');

$GLOBALS['notices'] = [];
check_backorder($backorder->validate_add_to_cart(true, 20, 2) === true, 'two more fit under the limit');
$GLOBALS['woo']->cart->quantities = [20 => 2];
check_backorder($backorder->validate_add_to_cart(true, 20, 1) === false, 'cart already holds the rest of the limit');
check_backorder(count($GLOBALS['notices']) === 1, 'customer sees why');

$GLOBALS['notices'] = [];
$GLOBALS['woo']->cart->quantities = [20 => 5];
$backorder->validate_cart();
check_backorder(count($GLOBALS['notices']) === 1, 'checkout is blocked when the cart grew past the limit');

// بستن بعد از فروش
$atLimit = new BackorderTestProduct(30, -3);
Backorder::store_limit($atLimit, 3.0);
$backorder->close_at_limit($atLimit);
check_backorder($atLimit->get_backorders() === 'no' && $atLimit->saved === 1, 'reaching the limit closes back-orders');

$below = new BackorderTestProduct(31, -2);
Backorder::store_limit($below, 3.0);
$backorder->close_at_limit($below);
check_backorder($below->get_backorders() === 'yes' && $below->saved === 0, 'below the limit stays open');

$unlimited = new BackorderTestProduct(32, -50);
$backorder->close_at_limit($unlimited);
check_backorder($unlimited->get_backorders() === 'yes', 'unlimited back-orders never close');

echo "All back-order tests passed.\n";
