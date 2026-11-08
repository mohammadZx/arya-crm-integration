<?php
/**
 * رزرو دوره (پیش‌پرداخت) و تلاش دوبارهٔ پرداخت‌های مستقیم.
 *
 * اجرا: php tests/reservation-tests.php
 *
 * باگ ۱۴۰۵/۰۸/۱۷: خرید سریع علامت رزرو را روی خود سفارش می‌گذارد و افزونه فقط علامت ردیف را می‌دید؛
 * مبلغ کل ثبت‌نام CRM همان پیش‌پرداخت می‌شد و شخص «تسویه» دیده می‌شد.
 */

require __DIR__ . '/wp-stubs.php';

$base = sys_get_temp_dir() . '/arya-reservation-tests-' . getmypid();
@mkdir($base, 0777, true);
arya_test_reset($base);

require __DIR__ . '/../includes/Settings.php';
require __DIR__ . '/../includes/Logger.php';
require __DIR__ . '/../includes/PersonData.php';
require __DIR__ . '/../includes/CreditOrder.php';
require __DIR__ . '/../includes/PurchasePayload.php';
require __DIR__ . '/../includes/Reservation.php';
require __DIR__ . '/../includes/OrderHandler.php';
require __DIR__ . '/../includes/Installment.php';
require __DIR__ . '/../includes/RemotePaymentRetry.php';

use Arya\Portal\Installment;
use Arya\Portal\OrderHandler;
use Arya\Portal\RemotePaymentRetry;
use Arya\Portal\Reservation;

function wc_get_price_decimals() { return 0; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function metadata_exists($type, $id, $key) { return isset($GLOBALS['meta'][$id][$key]); }
function get_post_field($field, $id) { return $GLOBALS['slugs'][(int) $id] ?? ''; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? null; }
function wc_get_orders($args) { return $GLOBALS['retry_orders'] ?? []; }
function wc_get_order_statuses() { return ['wc-processing' => 'Processing', 'wc-completed' => 'Completed', 'wc-on-hold' => 'On hold']; }
function wc_get_order_notes($args) { return []; }
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? null; }

class ResProduct {
    public function __construct(public $price, public $regular, public $virtual = true) {}
    public function get_price() { return $this->price; }
    public function get_regular_price() { return $this->regular; }
    public function is_virtual() { return $this->virtual; }
}

class ResItem {
    public $meta = [];
    public function __construct(public $productId, public $variationId, public $total, public $subtotal = null, public $product = null, public $quantity = 1) {
        $this->subtotal = $subtotal ?? $total;
    }
    public function get_product_id() { return $this->productId; }
    public function get_variation_id() { return $this->variationId; }
    public function get_quantity() { return $this->quantity; }
    public function get_total() { return $this->total; }
    public function get_subtotal() { return $this->subtotal; }
    public function get_total_tax() { return 0; }
    public function get_product() { return $this->product; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function get_name() { return 'دوره'; }
}

class ResFee {
    public function __construct(public $name, public $total) {}
    public function get_total() { return $this->total; }
    public function get_total_tax() { return 0; }
    public function get_name() { return $this->name; }
    public function get_meta($key, $single = true) { return ''; }
}

class ResOrder {
    public $items = [];
    public $fees = [];
    public $meta = [];
    public $notes = [];
    public $status = 'completed';
    public $saved = 0;
    public function __construct(public $id = 500) {}
    public function get_id() { return $this->id; }
    public function get_items($type = 'line_item') { return $type === 'fee' ? $this->fees : ($type === 'shipping' ? [] : $this->items); }
    public function get_fees() { return $this->fees; }
    public function get_total() {
        return array_sum(array_map(fn ($i) => $i->get_total(), $this->items)) + array_sum(array_map(fn ($f) => $f->get_total(), $this->fees));
    }
    public function get_shipping_total() { return 0; }
    public function get_coupon_codes() { return []; }
    public function get_user() { return (object) ['user_login' => '09121234567', 'display_name' => 'Zahra']; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() { $this->saved++; }
    public function add_order_note($note) { $this->notes[] = $note; }
    public function update_status($status, $note = '') { $this->status = $status; $this->notes[] = $note; }
    public function get_status() { return $this->status; }
    public function get_payment_method() { return 'WC_ZPal'; }
    public function __call($name, $args) { return ''; }
}

$passed = 0;
function check($condition, $message) {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$message}\n";
}

// ICDL روی سایت: قیمت فروش اقساطی ۷٬۵۰۰٬۰۰۰، حداقل پیش‌پرداخت ۳٬۰۰۰٬۰۰۰، تاریخ حضوری با تخفیف ویژه ۶٬۹۹۰٬۰۰۰
$GLOBALS['meta'] = [
    7729 => ['portal_category' => '1', 'course_id' => '5', 'installment_price' => '7500000', 'min_price_to_installment' => '3000000'],
    337413 => ['course_code' => '1407', 'has_online' => 'no'],
    // کالا (دستهٔ دیگر) برای سفارش چنددسته‌ای
    9000 => ['portal_category' => '2', 'course_id' => 'P-1'],
];
$icdl = new ResProduct(6990000, 10500000);

function reserved_order($flagOnOrder = true, $deposit = 3000000) {
    global $icdl;
    $order = new ResOrder();
    $item = new ResItem(7729, 337413, $deposit, $deposit, $icdl);
    if ($flagOnOrder) {
        $order->meta['pay_as_installment'] = 'on';
    } else {
        $item->meta['pay_as_installment'] = 'on';
    }
    $order->items = [41 => $item];
    $GLOBALS['orders'][$order->id] = $order;

    return $order;
}

$handler = OrderHandler::instance();

// --- علامت رزرو ---
check(Reservation::flagged('on') && Reservation::flagged('1') && Reservation::flagged(true), 'the course form "1" and the cart checkbox "on" both mean a reservation');
check(!Reservation::flagged('0') && !Reservation::flagged('') && !Reservation::flagged(false) && !Reservation::flagged(null), 'the form default "0" is a full registration');

// --- خرید سریع: علامت روی خود سفارش ---
$order = reserved_order(true);
$data = $handler->insert_on_portal($order->id, true);
check((float) $data['pay_price'] === 3000000.0, 'quick checkout reservation: the deposit is what was paid');
check((float) $data['price'] === 7500000.0, 'quick checkout reservation: the CRM total is the installment price, not the deposit');
check((float) $data['items'][0]['reserve_rest'] === 4500000.0, 'the rest of the course travels with the line');
check((float) $data['site_payloads'][0]['price'] === 7500000.0 && (float) $data['site_payloads'][0]['pay_price'] === 3000000.0, 'the payload actually posted to the CRM carries both amounts');

// --- سبد خرید: علامت روی ردیف (مسیر قدیمی) ---
$data = $handler->insert_on_portal(reserved_order(false)->id, true);
check((float) $data['price'] === 7500000.0, 'cart reservation (flag on the line) keeps the same total as before');

// --- تخفیف معرف روی پیش‌پرداخت: مبلغ کل همان‌قدر کم می‌شود ---
$order = reserved_order(true);
$order->fees = [60 => new ResFee('تخفیف کد معرف: ABC', -300000)];
$data = $handler->insert_on_portal($order->id, true);
check((float) $data['site_payloads'][0]['pay_price'] === 2700000.0 && (float) $data['site_payloads'][0]['price'] === 7200000.0, 'a referral discount on the deposit lowers the total by the same amount (rest unchanged)');
check($data['site_payloads'][0]['discount_lines'] === [['name' => 'تخفیف کد معرف: ABC', 'amount' => 300000.0]], 'the discount is still sent as a named discount line');

// --- ثبت‌نام کامل: دست نمی‌خورد ---
$order = new ResOrder();
$order->items = [41 => new ResItem(7729, 337413, 6990000, 6990000, $icdl)];
$GLOBALS['orders'][$order->id] = $order;
$data = $handler->insert_on_portal($order->id, true);
check((float) $data['price'] === 6990000.0 && !isset($data['items'][0]['reserve_rest']), 'a full registration is sent exactly as before');

// --- علامت رزرو ولی قیمت کامل پرداخت‌شده (سفارش‌های پیش از ۱۴۰۵/۰۷/۲۲) ---
$data = $handler->insert_on_portal(reserved_order(true, 6990000)->id, true);
check((float) $data['price'] === 6990000.0, 'an order flagged as reservation but paid in full gets no extra rest');

// --- قیمت اقساطی خالی: قیمت عادی همان تاریخ ---
$GLOBALS['meta'][7729]['installment_price'] = '';
$data = $handler->insert_on_portal(reserved_order(true)->id, true);
check((float) $data['price'] === 10500000.0, 'without an installment price the regular (undiscounted) price of that date is the total');
$GLOBALS['meta'][7729]['installment_price'] = '7500000';

// --- پیش‌پرداخت تعریف‌نشده: رزرو پولی نیست ---
$GLOBALS['meta'][7729]['min_price_to_installment'] = '';
$data = $handler->insert_on_portal(reserved_order(true, 7500000)->id, true);
check((float) $data['price'] === 7500000.0, 'without a deposit amount nothing is added (and never a zero or negative total)');
$GLOBALS['meta'][7729]['min_price_to_installment'] = '3000000';

// --- پیش‌پرداخت مخصوص یک تاریخ (متغیر) ---
$GLOBALS['meta'][337413]['min_price_to_installment'] = '2000000';
$data = $handler->insert_on_portal(reserved_order(true, 2000000)->id, true);
check((float) $data['price'] === 7500000.0, 'a date-specific deposit (variation meta) is used like quick checkout does');
unset($GLOBALS['meta'][337413]['min_price_to_installment']);

// --- سفارش چنددسته‌ای (دورهٔ رزروی + کالا) ---
$order = reserved_order(true);
$order->items[42] = new ResItem(9000, 0, 500000, 500000, new ResProduct(500000, 500000, false));
$data = $handler->insert_on_portal($order->id, true);
$byCategory = [];
foreach ($data['site_payloads'] as $payload) {
    $byCategory[$payload['category_id']] = $payload;
}
check((float) $byCategory['1']['price'] === 7500000.0 && (float) $byCategory['1']['pay_price'] === 3000000.0, 'mixed order: the reserved course keeps its full total');
check((float) $byCategory['2']['price'] === 500000.0 && (float) $byCategory['2']['pay_price'] === 500000.0, 'mixed order: the product is untouched');

// --- صفحهٔ «خرید سریع» قالب (/quick): رزرو ۵۰٪، نیمهٔ دیگر صریح روی ردیف ---
$order = new ResOrder();
$half = new ResItem(7729, 337413, 3495000, 3495000, $icdl);
$half->meta['_arya_reserve_rest'] = 3495000;
$order->meta['_quick_checkout_reserve'] = 'yes';
$order->items = [41 => $half];
$GLOBALS['orders'][$order->id] = $order;
$data = $handler->insert_on_portal($order->id, true);
check((float) $data['site_payloads'][0]['price'] === 6990000.0 && (float) $data['site_payloads'][0]['pay_price'] === 3495000.0, 'the 50% landing reservation: total = the page price, not the half (and not the installment price)');
$order->fees = [61 => new ResFee('تخفیف با کد pedar', -349500)];
$data = $handler->insert_on_portal($order->id, true);
check((float) $data['site_payloads'][0]['price'] === 6640500.0 && (float) $data['site_payloads'][0]['discount_lines'][0]['amount'] === 349500.0, 'the landing coupon on the paid half lowers the total by the same amount and stays a named discount');

// --- سبد خرید (بدون خرید سریع) ---
$installment = Installment::instance();
check((float) $installment->get_installment_price(7729, 337413, '1') === 3000000.0, 'the course form "1" now reserves at the deposit (was ignored: full price was charged)');
check($installment->get_installment_price(7729, 337413, '0') === false, 'a normal registration keeps the full price');
$_REQUEST['pay_as_installment'] = '1';
check($installment->add_cart_item_data([], 7729, 337413)['pay_as_installment'] === 'on', 'the cart stores one value ("on") for every reservation form');
$_REQUEST['pay_as_installment'] = '0';
check($installment->add_cart_item_data([], 7729, 337413)['pay_as_installment'] === false, 'the hidden default "0" is not a reservation');
unset($_REQUEST['pay_as_installment']);

// --- تنظیم تخفیف رزرو ---
check(Reservation::allowsCoupon() && Reservation::allowsReferral(), 'by default discounts stay allowed on reservations (quick checkout behaviour so far)');
update_option(Reservation::OPTION_ALLOW_COUPON, 0);
check(!Reservation::allowsCoupon() && Reservation::allowsReferral(), 'each discount type has its own switch');
update_option(Reservation::OPTION_ALLOW_COUPON, 1);

class ResCoupon {
    public function __construct(public $type, public $amount, public $minimum = 0) {}
    public function get_discount_type() { return $this->type; }
    public function get_amount() { return $this->amount; }
    public function get_minimum_amount() { return $this->minimum; }
}
check(Reservation::couponDiscount(new ResCoupon('percent', 10), 3000000) === 300000.0, 'a percent coupon is a percent of the deposit');
check(Reservation::couponDiscount(new ResCoupon('fixed_cart', 5000000), 3000000) === 3000000.0, 'a fixed coupon never exceeds the deposit');
check(Reservation::couponDiscount(new ResCoupon('percent', 10, 4000000), 3000000) === 0.0, 'a coupon whose minimum is above the deposit gives nothing (the order would refuse it too)');

// --- پرداخت الباقی/آزمون: نتیجهٔ پاسخ CRM ---
check(RemotePaymentRetry::paymentOutcome((object) ['data' => (object) ['id' => 9]]) === RemotePaymentRetry::OK, 'PaymentResource is a recorded payment');
check(RemotePaymentRetry::paymentOutcome((object) ['message' => 'Server Error']) === RemotePaymentRetry::FAILED, 'a server-error JSON is NOT a recorded payment (it used to count as one)');
check(RemotePaymentRetry::paymentOutcome(null) === RemotePaymentRetry::FAILED, 'no answer is retried');
check(RemotePaymentRetry::paymentOutcome((object) ['status' => false, 'message' => 'no debt']) === RemotePaymentRetry::REJECTED, 'a CRM refusal is not retried');
check(RemotePaymentRetry::examOutcome((object) ['status' => true]) === RemotePaymentRetry::OK && RemotePaymentRetry::examOutcome((object) ['message' => 'x']) === RemotePaymentRetry::FAILED, 'exam answers are read the same way');

// --- صف تلاش دوباره ---
$retry = RemotePaymentRetry::instance();
check(!empty($GLOBALS['arya_scheduled'][RemotePaymentRetry::HOOK]), 'the retry runs on the five-minute schedule');

$pay = new ResOrder(701);
RemotePaymentRetry::queue($pay, RemotePaymentRetry::KIND_PAYMENT, ['register_id' => 37819, 'price' => 4500000, 'post_data' => ['register_type' => 'course']], '09121234567', 'timeout');
check($pay->meta[RemotePaymentRetry::META]['attempts'] === 0 && count($pay->notes) === 1, 'a payment the CRM did not take is queued on the order with a note');

$GLOBALS['arya_http_log'] = [];
arya_queue_response(500, '{"message":"Server Error"}');
$GLOBALS['retry_orders'] = [$pay];
$retry->run();
check($pay->meta[RemotePaymentRetry::META]['attempts'] === 1 && $pay->status === 'completed', 'a still-failing CRM keeps the payment queued');
check(strpos($GLOBALS['arya_http_log'][0]['url'], 'remote-pay-payment') !== false && (string) $GLOBALS['arya_http_log'][0]['args']['body']['register_id'] === '37819', 'the retry posts the same purchase and amount');

arya_queue_response(200, '{"data":{"id":555}}');
$retry->run();
check(!isset($pay->meta[RemotePaymentRetry::META]) && strpos(end($pay->notes), 'ثبت شد') !== false, 'once the CRM records it the queue entry is cleared with a note');

$rejected = new ResOrder(702);
RemotePaymentRetry::queue($rejected, RemotePaymentRetry::KIND_PAYMENT, ['register_id' => 1, 'price' => 10, 'post_data' => []], '09121234567');
arya_queue_response(200, '{"status":false,"message":"purchase has no debt"}');
$GLOBALS['retry_orders'] = [$rejected];
$retry->run();
check($rejected->status === 'on-hold' && !isset($rejected->meta[RemotePaymentRetry::META]) && $rejected->meta[RemotePaymentRetry::FAILED_META]['message'] === 'purchase has no debt', 'a refused payment goes on hold for manual review');

$late = new ResOrder(703);
$late->meta[RemotePaymentRetry::META] = ['kind' => RemotePaymentRetry::KIND_PAYMENT, 'args' => ['register_id' => 1, 'price' => 10, 'post_data' => []], 'phone' => '09121234567', 'attempts' => RemotePaymentRetry::MAX_ATTEMPTS - 1];
arya_queue_error('timed out');
$GLOBALS['retry_orders'] = [$late];
$retry->run();
check($late->status === 'on-hold' && isset($late->meta[RemotePaymentRetry::FAILED_META]), 'after the last attempt the order goes on hold');

// آزمون: نوبت آزمون رسید، پرداخت نه ⇒ فقط پرداخت دوباره فرستاده می‌شود
$exam = new ResOrder(704);
RemotePaymentRetry::queue($exam, RemotePaymentRetry::KIND_EXAM, ['exam_id' => 12, 'price' => 200000, 'steps' => ['grant', 'pay']], '09121234567');
arya_queue_response(200, '{"status":true}');
arya_queue_response(500, '{"message":"Server Error"}');
$GLOBALS['retry_orders'] = [$exam];
$GLOBALS['arya_http_log'] = [];
$retry->run();
check($exam->meta[RemotePaymentRetry::META]['args']['steps'] === ['pay'], 'the exam attempt that reached the CRM is not sent again');
arya_queue_response(200, '{"status":true,"data":{}}');
$GLOBALS['arya_http_log'] = [];
$retry->run();
check(count($GLOBALS['arya_http_log']) === 1 && strpos($GLOBALS['arya_http_log'][0]['url'], 'remote-pay-exam') !== false, 'only the exam payment is retried');
check(!isset($exam->meta[RemotePaymentRetry::META]), 'the exam payment is cleared once recorded');

echo "\n{$passed} reservation/retry checks passed.\n";
