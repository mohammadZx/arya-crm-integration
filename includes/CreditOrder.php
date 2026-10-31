<?php

namespace Arya\Portal;

/**
 * بخش «پرداخت از اعتبار» یک سفارش ووکامرس (اعتبار مرکزی CRM).
 *
 * arya-account هنگام ثبت سفارش اعتبار را در CRM رزرو می‌کند، یک fee منفی با متای FEE_META روی سفارش
 * می‌گذارد (تا درگاه فقط باقی را بگیرد) و شناسهٔ رزرو و مبلغ را در متای سفارش نگه می‌دارد. این‌جا
 * همان را به payload ثبت سفارش در CRM می‌دهیم: price کل خرید (درگاه + اعتبار) و credit = {hold_id, amount}.
 * fee اعتبار «تخفیف» نیست و در discount_total نمی‌آید.
 */
class CreditOrder {
    const FEE_META = '_arya_credit_fee';
    const ORDER_HOLD = '_arya_credit_hold';
    const ORDER_AMOUNT = '_arya_credit_amount';

    /** آیا این fee بخش اعتبار سفارش است؟ */
    public static function isCreditFee($fee) {
        return is_object($fee) && method_exists($fee, 'get_meta') && (string) $fee->get_meta(self::FEE_META, true) === '1';
    }

    /** @return array{hold_id: int, amount: float}|null */
    public static function fromOrder($order) {
        if (!$order || !method_exists($order, 'get_meta')) {
            return null;
        }
        $hold = (int) $order->get_meta(self::ORDER_HOLD, true);
        $amount = (float) $order->get_meta(self::ORDER_AMOUNT, true);
        if ($hold <= 0 || $amount <= 0) {
            return null;
        }

        return ['hold_id' => $hold, 'amount' => $amount];
    }
}
