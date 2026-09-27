<?php

namespace Arya\Portal;

/**
 * فروش بدون موجودی (پیش‌فروش) که CRM برای هر انبار روشن می‌کند.
 *
 * CRM در PUT /portal/product دو فیلد می‌فرستد:
 *   backorders       آیا الان فروش بدون موجودی باز است (سقف پر نشده)
 *   backorder_limit  بیشترین موجودی منفی؛ null یعنی بی‌سقف
 *
 * خود ووکامرس با backorders=yes خرید با موجودی صفر و منفی را قبول می‌کند و
 * چیزی به مشتری نشان نمی‌دهد (برخلاف notify). موجودی منفی یعنی «فروخته‌شده،
 * هنوز نرسیده» — همان معنایی که CRM دارد. این کلاس فقط سقف را اجرا می‌کند:
 *   - تعداد در سبد و صفحه‌ی محصول از «موجودی + سقف» بیشتر نشود؛
 *   - بعد از هر کاهش موجودی، اگر به سقف رسید، خرید بسته شود تا CRM (با
 *     رسیدن کالا) دوباره بازش کند.
 * دو خرید هم‌زمان درست لب سقف ممکن است هر دو ثبت شوند؛ موجودی CRM در هر
 * حال دقیق می‌ماند چون کسر را خودش هنگام تایید فیش انجام می‌دهد.
 */
class Backorder {

    const LIMIT_META = '_arya_backorder_limit';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_product_set_stock', [$this, 'close_at_limit']);
        add_action('woocommerce_variation_set_stock', [$this, 'close_at_limit']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 20, 4);
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart']);
        add_filter('woocommerce_quantity_input_args', [$this, 'quantity_input_args'], 20, 2);
        add_filter('woocommerce_available_variation', [$this, 'available_variation'], 20, 3);
        add_filter('woocommerce_get_availability_text', [$this, 'availability_text'], 20, 2);
    }

    /**
     * فیلدهای ارسالی CRM برای یک ردیف.
     *
     * @return array{open: bool, limit: float|null}
     */
    public static function from_payload(array $item) {
        $open = isset($item['backorders']) && filter_var($item['backorders'], FILTER_VALIDATE_BOOLEAN);

        $limit = $item['backorder_limit'] ?? null;
        $limit = ($limit === null || $limit === '' || !is_numeric($limit)) ? null : max(0, (float) $limit);

        return ['open' => $open, 'limit' => $limit];
    }

    /**
     * وضعیت موجودی برای مقدار ارسالی CRM؛ همان چیزی که ووکامرس هنگام save
     * از روی manage_stock خود محصول حساب می‌کند (validate_props).
     */
    public static function stock_status($quantity, $open) {
        if ((float) $quantity > 0) {
            return 'instock';
        }

        return $open ? 'onbackorder' : 'outofstock';
    }

    /**
     * backorders=yes در ووکامرس یعنی «اجازه بده، اعلام نکن». با مدیریت موجودی
     * سراسریِ روشن هم همین‌طور رفتار می‌کند، ولی وقتی تنظیم سراسری خاموش است
     * (تنظیم فعلی سایت) برای onbackorder متن «موجود برای پیش‌سفارش» می‌گذارد.
     * مشتری نباید تفاوتی ببیند، پس متن را برمی‌داریم؛ notify دست نمی‌خورد.
     */
    public function availability_text($text, $product) {
        if ($product && $product->get_backorders() === 'yes' && $product->get_stock_status() === 'onbackorder') {
            return '';
        }

        return $text;
    }

    /** سقف روی خود محصول/واریانت (قبل از save صدا زده می‌شود). */
    public static function store_limit($product, $limit) {
        if ($limit === null) {
            $product->delete_meta_data(self::LIMIT_META);
            return;
        }
        $product->update_meta_data(self::LIMIT_META, $limit);
    }

    /** سقف ثبت‌شده؛ null یعنی بی‌سقف. */
    public static function limit($product) {
        if (!$product) {
            return null;
        }
        $limit = $product->get_meta(self::LIMIT_META, true);

        return ($limit === '' || $limit === null || $limit === false || !is_numeric($limit)) ? null : (float) $limit;
    }

    /**
     * چند واحد دیگر می‌شود سفارش داد؛ null یعنی محدودیتی از این کلاس ندارد
     * (موجودی این محصول از CRM نیست، پیش‌فروش بسته — که خود ووکامرس جلویش را
     * می‌گیرد — یا بی‌سقف).
     *
     * مدیریت موجودیِ خود محصول (نه تنظیم سراسری) ملاک است: با تنظیم سراسریِ
     * خاموش، موجودی ذخیره‌شده همان عددی است که CRM آخرین بار فرستاده.
     */
    public static function sellable($product) {
        if (!$product || $product->get_manage_stock() !== true || $product->get_backorders() === 'no') {
            return null;
        }

        $limit = self::limit($product);
        if ($limit === null) {
            return null;
        }

        return max(0, (float) $product->get_stock_quantity() + $limit);
    }

    /** بعد از کاهش موجودی ووکامرس (سفارش): اگر سقف پر شد، خرید را ببند. */
    public function close_at_limit($product) {
        if (!$product || !$product->managing_stock() || $product->get_backorders() === 'no') {
            return;
        }

        $limit = self::limit($product);
        if ($limit === null || (float) $product->get_stock_quantity() > -$limit) {
            return;
        }

        $product->set_backorders('no');
        $product->save();
    }

    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0) {
        if (!$passed || !function_exists('wc_get_product')) {
            return $passed;
        }

        $product = wc_get_product($variation_id ?: $product_id);
        $sellable = self::sellable($product);
        if ($sellable === null) {
            return $passed;
        }

        $in_cart = $this->cart_quantity($product);
        if ($in_cart + (float) $quantity > $sellable) {
            wc_add_notice($this->limit_message($product, $sellable), 'error');
            return false;
        }

        return $passed;
    }

    /** سبد و checkout: تعدادی که بعداً در سبد زیاد شده هم نباید از سقف رد شود. */
    public function validate_cart() {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        foreach (WC()->cart->get_cart_item_quantities() as $product_id => $quantity) {
            $product = wc_get_product($product_id);
            $sellable = self::sellable($product);
            if ($sellable !== null && (float) $quantity > $sellable) {
                wc_add_notice($this->limit_message($product, $sellable), 'error');
            }
        }
    }

    public function quantity_input_args($args, $product) {
        $sellable = self::sellable($product);
        if ($sellable === null) {
            return $args;
        }

        $max = isset($args['max_value']) ? (float) $args['max_value'] : -1;
        $args['max_value'] = $max > 0 ? min($max, $sellable) : $sellable;

        return $args;
    }

    public function available_variation($data, $product, $variation) {
        $sellable = self::sellable($variation);
        if ($sellable !== null) {
            $max = isset($data['max_qty']) && $data['max_qty'] !== '' ? (float) $data['max_qty'] : -1;
            $data['max_qty'] = $max > 0 ? min($max, $sellable) : $sellable;
        }

        return $data;
    }

    private function cart_quantity($product) {
        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }
        $quantities = WC()->cart->get_cart_item_quantities();

        return (float) ($quantities[$product->get_stock_managed_by_id()] ?? 0);
    }

    private function limit_message($product, $sellable) {
        return sprintf('از «%s» حداکثر %s عدد قابل سفارش است.', $product->get_name(), wc_stock_amount($sellable));
    }
}
