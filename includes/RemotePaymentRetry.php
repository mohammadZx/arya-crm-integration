<?php

namespace Arya\Portal;

/**
 * پرداخت‌های مستقیم سایت که به CRM نرسیدند: الباقی/قسط دوره و هزینهٔ مدرک (محصول pay-payment) و آزمون (محصول exam).
 *
 * خرید دوره و کالا (OrderHandler) از قبل تلاش دوباره داشت؛ این‌ها نداشتند. اگر CRM لحظهٔ پرداخت جواب نمی‌داد،
 * پول گرفته شده بود، سفارش «تکمیل‌شده» می‌ماند و در CRM فیشی نبود (فقط یک پیام به مشتری).
 * حالا پرداخت در صف سفارش می‌ماند و هر ۵ دقیقه (تا حدود ۶ ساعت) دوباره فرستاده می‌شود. CRM هر پرداخت را با
 * شمارهٔ سفارش و مبلغ فقط یک بار ثبت می‌کند (SiteOrderReceipt)، پس فرستادن دوبارهٔ پاسخِ گم‌شده بی‌خطر است.
 */
class RemotePaymentRetry {

    const META = '_arya_crm_payment_retry';

    /** پرداختی که CRM ردش کرد یا بعد از همهٔ تلاش‌ها نرسید (برای بررسی دستی). */
    const FAILED_META = '_arya_crm_payment_failed';

    const HOOK = 'arya_portal_retry_crm_payments';

    /** هر ۵ دقیقه، تا حدود ۶ ساعت (مثل OrderHandler). */
    const MAX_ATTEMPTS = 72;

    const KIND_PAYMENT = 'pay-payment';
    const KIND_EXAM = 'exam';

    /** CRM پرداخت را ثبت کرد */
    const OK = 'ok';
    /** CRM پاسخ داد که ثبت نمی‌کند (مثلاً خرید بدهی ندارد)؛ دوباره فرستادن فایده ندارد */
    const REJECTED = 'rejected';
    /** پاسخی نرسید یا خطای سرور بود؛ دوباره فرستاده می‌شود */
    const FAILED = 'failed';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action(self::HOOK, [$this, 'run']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'arya_portal_five_minutes', self::HOOK);
        }
    }

    /**
     * پاسخ remote-pay-payment: موفق = PaymentResource ({data: {id}}). خطای سرور هم JSON است ({message})،
     * پس «شیء بودن» پاسخ نشانهٔ ثبت نیست (قبلاً بود و پرداخت بی‌صدا گم می‌شد).
     */
    public static function paymentOutcome($response) {
        if (is_object($response) && isset($response->status) && $response->status === false) {
            return self::REJECTED;
        }
        if (is_object($response) && isset($response->data) && is_object($response->data) && !empty($response->data->id)) {
            return self::OK;
        }

        return self::FAILED;
    }

    /** پاسخ remote-pay-exam و افزودن نوبت آزمون: {status: true|false}؛ بی status یعنی خطای سرور/شبکه */
    public static function examOutcome($response) {
        if (is_object($response) && isset($response->status)) {
            return $response->status ? self::OK : self::REJECTED;
        }

        return self::FAILED;
    }

    /** شمارهٔ خریدار برای PersonData: کاربر سفارش؛ بازگشت از درگاه گاهی بی‌نشست کاربر است */
    public static function phoneOf($order, $fallback = '') {
        $user = $order && method_exists($order, 'get_user') ? $order->get_user() : null;
        $phone = $user ? trim((string) $user->user_login) : '';
        if ($phone === '' && $order && method_exists($order, 'get_billing_phone')) {
            $phone = trim((string) $order->get_billing_phone());
        }

        return $phone !== '' ? $phone : (string) $fallback;
    }

    /**
     * پرداختی که نرسید به صف سفارش می‌رود.
     *
     * @param array $args pay-payment: register_id, price, post_data — exam: exam_id, price, steps (grant|pay)
     */
    public static function queue($order, $kind, array $args, $phone, $reason = '') {
        $order->update_meta_data(self::META, [
            'kind' => $kind,
            'args' => $args,
            'phone' => (string) $phone,
            'attempts' => 0,
        ]);
        $order->add_order_note('ثبت این پرداخت در CRM انجام نشد؛ هر ۵ دقیقه دوباره فرستاده می‌شود.' . ($reason !== '' ? ' (' . $reason . ')' : ''));
        $order->save();

        Logger::instance()->warning(
            Logger::PAYMENT_SEND_RETRY,
            'پرداخت سایت در CRM ثبت نشد؛ هر ۵ دقیقه دوباره فرستاده می‌شود.',
            ['order_id' => $order->get_id(), 'kind' => $kind, 'reason' => $reason],
            Logger::SOURCE_PORTAL
        );
    }

    /** CRM پرداخت را نپذیرفت یا تلاش‌ها تمام شد: «در انتظار بررسی» برای ثبت دستی */
    public static function giveUp($order, $kind, array $args, $message) {
        $order->delete_meta_data(self::META);
        $order->update_meta_data(self::FAILED_META, ['kind' => $kind, 'args' => $args, 'message' => (string) $message]);
        $order->save();
        $order->update_status('on-hold', 'پرداخت در CRM ثبت نشد؛ دستی بررسی و ثبت شود: ' . $message);

        Logger::instance()->error(
            Logger::PAYMENT_SEND_FAILED,
            'پرداخت سایت در CRM ثبت نشد؛ آن را دستی ثبت کنید.',
            ['order_id' => $order->get_id(), 'kind' => $kind, 'message' => (string) $message],
            Logger::SOURCE_PORTAL
        );
    }

    /**
     * یک بار فرستادن. خروجی: [نتیجه، args باقی‌مانده]؛ در آزمون هر قدمِ رسیده از steps کم می‌شود تا
     * نوبت آزمون دو بار اضافه نشود.
     */
    public static function attempt($order, $kind, array $args, $phone) {
        $person = new PersonData($phone);

        if ($kind === self::KIND_PAYMENT) {
            $response = $person->setPaymentRemote($args['register_id'], $args['price'], $order->get_id(), $args['post_data'] ?? []);

            return [self::paymentOutcome($response), $args, self::messageOf($response)];
        }

        if ($kind === self::KIND_EXAM) {
            $steps = array_values((array) ($args['steps'] ?? ['grant', 'pay']));
            foreach ($steps as $step) {
                $response = $step === 'grant'
                    ? $person->add_user_exam($args['exam_id'])
                    : $person->setExamPaymentRemote($args['exam_id'], $args['price'], $order->get_id());
                $outcome = self::examOutcome($response);
                if ($outcome !== self::OK) {
                    return [$outcome, $args, self::messageOf($response)];
                }
                $args['steps'] = array_values(array_diff((array) $args['steps'], [$step]));
            }

            return [self::OK, $args, ''];
        }

        return [self::REJECTED, $args, 'نوع پرداخت ناشناخته: ' . $kind];
    }

    /** اجرای زمان‌بندی‌شده */
    public function run() {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        $orders = wc_get_orders([
            'limit' => 20,
            'type' => 'shop_order',
            'status' => array_keys(wc_get_order_statuses()),
            'meta_query' => [['key' => self::META, 'compare' => 'EXISTS']],
        ]);

        foreach ($orders as $order) {
            $this->retry($order);
        }
    }

    public function retry($order) {
        $queued = $order->get_meta(self::META);
        if (!is_array($queued) || empty($queued['kind'])) {
            $order->delete_meta_data(self::META);
            $order->save();

            return;
        }

        $kind = $queued['kind'];
        $attempts = (int) ($queued['attempts'] ?? 0) + 1;
        [$outcome, $args, $message] = self::attempt($order, $kind, (array) $queued['args'], (string) $queued['phone']);

        if ($outcome === self::OK) {
            $order->delete_meta_data(self::META);
            $order->add_order_note('پرداخت در CRM ثبت شد (تلاش ' . $attempts . ').');
            $order->save();

            return;
        }

        if ($outcome === self::REJECTED) {
            self::giveUp($order, $kind, $args, $message ?: 'CRM این پرداخت را ثبت نکرد');

            return;
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            self::giveUp($order, $kind, $args, 'بعد از ' . $attempts . ' تلاش پاسخی از CRM نرسید');

            return;
        }

        $order->update_meta_data(self::META, ['kind' => $kind, 'args' => $args, 'phone' => $queued['phone'], 'attempts' => $attempts]);
        $order->save();
    }

    private static function messageOf($response) {
        return is_object($response) && isset($response->message) && is_scalar($response->message) ? (string) $response->message : '';
    }
}
