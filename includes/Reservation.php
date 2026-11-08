<?php

namespace Arya\Portal;

/**
 * رزرو دوره (پیش‌پرداخت) در سایت.
 *
 * مشتری فقط «حداقل پیش‌پرداخت» (min_price_to_installment) را می‌پردازد؛ مبلغ کل ثبت‌نام «قیمت فروش اقساطی»
 * دوره (installment_price) است و باقی در CRM بدهی/اقساط می‌شود (صفحهٔ «قوانین رزرو»: رزرو بی‌تخفیف، باقی اقساطی).
 *
 * رزرو دو جا علامت می‌خورد: خرید سریع قالب روی خود سفارش (pay_as_installment = on) و سبد خرید روی ردیف سفارش.
 * تا ۱۴۰۵/۰۸/۱۷ افزونه فقط علامت ردیف را می‌دید، پس رزرو خرید سریع با مبلغ کل = پیش‌پرداخت به CRM می‌رفت
 * و ثبت‌نام «تسویه» دیده می‌شد.
 */
class Reservation {

    const FLAG = 'pay_as_installment';

    /** کد تخفیف ووکامرس روی پیش‌پرداخت رزرو مجاز است؟ (تنظیمات ← پورتال آریا) */
    const OPTION_ALLOW_COUPON = 'arya_portal_reserve_allow_coupon';

    /** کد معرف (بازاریابی) روی پیش‌پرداخت رزرو مجاز است؟ */
    const OPTION_ALLOW_REFERRAL = 'arya_portal_reserve_allow_referral';

    /**
     * باقیِ صریح روی ردیف سفارش، برای رزروی که قاعدهٔ خودش را دارد: صفحهٔ «خرید سریع» قالب (/quick،
     * «رزرو صندلی (پرداخت ۵۰٪ مبلغ)») نیمهٔ دیگر را وعده می‌دهد، پس مبلغ کل = قیمت همان صفحه، نه قیمت فروش اقساطی
     * (تصمیم ۱۴۰۵/۰۸/۱۷). مبلغ کل ردیف (نه تک‌واحد)، به واحد سایت.
     */
    const ITEM_REST_META = '_arya_reserve_rest';

    /**
     * مقدار علامت رزرو: چک‌باکس سبد «on» می‌فرستد و فرم دوره (CoursePurchaseSystem) «1».
     * قبلاً فقط «on» پذیرفته می‌شد و رزرو از فرم دوره بی‌صدا ثبت‌نام کامل می‌شد.
     */
    public static function flagged($value) {
        if ($value === true || $value === 1) {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['on', '1', 'true', 'yes'], true);
    }

    /** ردیف سفارش رزرو است؟ (علامت روی سفارش از خرید سریع، یا روی ردیف از سبد) */
    public static function isReservedItem($order, $item) {
        if ($order && method_exists($order, 'get_meta') && self::flagged($order->get_meta(self::FLAG))) {
            return true;
        }

        return $item && method_exists($item, 'get_meta') && self::flagged($item->get_meta(self::FLAG, true));
    }

    /** حداقل پیش‌پرداخت: اول متغیر (تاریخ دوره)، بعد خود محصول؛ همان که خرید سریع و سبد می‌گیرند */
    public static function deposit($productId, $variationId = 0) {
        $deposit = $variationId ? (float) get_post_meta($variationId, 'min_price_to_installment', true) : 0.0;
        if ($deposit <= 0 && $productId) {
            $deposit = (float) get_post_meta($productId, 'min_price_to_installment', true);
        }

        return max(0.0, $deposit);
    }

    /**
     * مبلغ کل دوره در رزرو: «قیمت فروش اقساطی» (اول متغیر، بعد محصول)؛ اگر تعریف نشده،
     * قیمت عادی (پیش از تخفیف ویژه) همان تاریخ — رزرو تخفیف ندارد.
     */
    public static function fullPrice($productId, $variationId = 0, $product = null) {
        foreach ([$variationId, $productId] as $id) {
            if (!$id) {
                continue;
            }
            $price = (float) get_post_meta($id, 'installment_price', true);
            if ($price > 0) {
                return $price;
            }
        }

        if (!$product && function_exists('wc_get_product')) {
            $product = wc_get_product($variationId ?: $productId);
        }
        if ($product && method_exists($product, 'get_regular_price')) {
            $regular = (float) $product->get_regular_price();
            if ($regular > 0) {
                return $regular;
            }
        }

        return 0.0;
    }

    /**
     * باقی دورهٔ رزروشده (به واحد سایت): (مبلغ کل − حداقل پیش‌پرداخت) × تعداد. مبلغ کل ثبت‌نام در CRM
     * = آنچه پرداخت شد + همین؛ پس قیمت درگاه یا تخفیفِ روی پیش‌پرداخت فقط مبلغ کل را جابه‌جا می‌کند، نه باقی را
     * (همان فرمول قبلی: قیمت اقساطی − (حداقل پیش‌پرداخت − پرداختی)).
     *
     * باقیِ صریح ردیف (ITEM_REST_META) مقدم است. صفر وقتی: رزرو نیست؛ پیش‌پرداخت یا مبلغ کل تعریف نشده؛ یا ردیف عملاً به قیمت کامل فروخته شده
     * (سفارش‌های رزروِ پیش از ۱۴۰۵/۰۷/۲۲ که خرید سریع کل مبلغ را می‌گرفت و حالا شاید دیرتر پرداخت شوند).
     *
     * @param \WC_Order|null             $order
     * @param \WC_Order_Item_Product     $item
     */
    public static function rest($order, $item) {
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 0;
        $explicit = method_exists($item, 'get_meta') ? (float) $item->get_meta(self::ITEM_REST_META, true) : 0.0;
        if ($explicit > 0) {
            return round($explicit, $decimals);
        }

        if (!self::isReservedItem($order, $item)) {
            return 0.0;
        }

        $productId = (int) $item->get_product_id();
        $variationId = (int) $item->get_variation_id();
        $quantity = max(1, (int) $item->get_quantity());
        $product = method_exists($item, 'get_product') ? $item->get_product() : null;
        $deposit = self::deposit($productId, $variationId);
        $full = self::fullPrice($productId, $variationId, $product);

        $context = [
            'order_id' => $order && method_exists($order, 'get_id') ? $order->get_id() : null,
            'product_id' => $productId,
            'variation_id' => $variationId,
            'deposit' => $deposit,
            'full_price' => $full,
        ];

        if ($deposit <= 0 || $full <= $deposit) {
            self::warn('رزرو دوره بدون «قیمت فروش اقساطی» و «حداقل پیش‌پرداخت» معتبر؛ مبلغ کل ثبت‌نام همان پرداختی می‌رود.', $context);

            return 0.0;
        }

        // ردیفی که به قیمت کامل (یا بیشتر) فروخته شده رزرو پولی نیست؛ باقی گذاشتن دو بار حساب کردن است
        $unit = (float) $item->get_subtotal() / $quantity;
        $current = $product && method_exists($product, 'get_price') ? (float) $product->get_price() : 0.0;
        if ($unit >= $full || ($current > 0 && $unit >= $current)) {
            self::warn('سفارش علامت رزرو دارد ولی قیمت کامل پرداخت شده؛ باقی برای CRM گذاشته نشد.', $context + ['unit_price' => $unit, 'current_price' => $current]);

            return 0.0;
        }

        return round(($full - $deposit) * $quantity, $decimals);
    }

    public static function allowsCoupon() {
        return (bool) get_option(self::OPTION_ALLOW_COUPON, 1);
    }

    public static function allowsReferral() {
        return (bool) get_option(self::OPTION_ALLOW_REFERRAL, 1);
    }

    /**
     * تخفیف یک کد ووکامرس روی مبلغ پیش‌پرداخت (برای نمایش خرید سریع؛ سفارش همان را با ردیفِ پیش‌پرداخت حساب می‌کند).
     * درصدی: درصد پیش‌پرداخت؛ ثابت: حداکثر خود پیش‌پرداخت؛ نوع ناشناخته: به نسبت تخفیف سبد.
     *
     * @param \WC_Coupon $coupon
     */
    public static function couponDiscount($coupon, $deposit, $cartDiscount = 0.0, $cartSubtotal = 0.0) {
        $deposit = max(0.0, (float) $deposit);
        if (!$coupon || $deposit <= 0) {
            return 0.0;
        }

        $minimum = (float) $coupon->get_minimum_amount();
        if ($minimum > 0 && $deposit < $minimum) {
            return 0.0;
        }

        $amount = (float) $coupon->get_amount();
        switch ($coupon->get_discount_type()) {
            case 'percent':
                $discount = $deposit * min(100.0, $amount) / 100;
                break;
            case 'fixed_cart':
            case 'fixed_product':
                $discount = $amount;
                break;
            default:
                $discount = $cartSubtotal > 0 ? (float) $cartDiscount * $deposit / (float) $cartSubtotal : 0.0;
        }

        return round(min($deposit, max(0.0, $discount)), function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 0);
    }

    private static function warn($message, array $context) {
        if (class_exists(Logger::class)) {
            Logger::instance()->warning(Logger::ORDER_RESERVATION, $message, $context, Logger::SOURCE_PORTAL);
        }
    }
}
