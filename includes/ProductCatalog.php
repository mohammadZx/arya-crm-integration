<?php

namespace Arya\Portal;

/**
 * محتوای کاتالوگ یک محصول ووکامرس برای ایمپورت CRM: توضیحات، عکس شاخص و ویژگی‌ها.
 *
 * CRM با این داده محصول مادر (متغیر) و فرزندانش (واریانت‌ها) را می‌سازد و
 * ویژگی‌های سراسری سایت (pa_*) را به ویژگی‌های سراسری خودش نگاشت می‌کند؛
 * پس برای ویژگی سراسری «نام» مقدار (نه نامک URLشده‌ی آن) فرستاده می‌شود.
 */
class ProductCatalog
{
    /**
     * @return array{description:string, short_description:string}
     */
    public static function content($product)
    {
        return [
            'description' => (string) $product->get_description(),
            'short_description' => method_exists($product, 'get_short_description') ? (string) $product->get_short_description() : '',
        ];
    }

    /**
     * آدرس عکس شاخص در اندازه‌ی کامل؛ واریانتِ بی‌عکس null است و CRM عکس مادر را نشان می‌دهد.
     *
     * context=edit: ووکامرس در حالت نمایش برای واریانت بی‌عکس، عکس مادر را
     * برمی‌گرداند و CRM همان عکس را برای هر واریانت دوباره دانلود می‌کرد.
     *
     * @return string|null
     */
    public static function image($product)
    {
        $id = (int) $product->get_image_id('edit');
        if (!$id) {
            return null;
        }

        $url = wp_get_attachment_image_url($id, 'full');

        return $url ? (string) $url : null;
    }

    /**
     * ویژگی‌های محصول ساده یا مادر.
     *
     * @return array<int, array{name:string, slug:string, taxonomy:bool, options:array<int,string>, visible:bool, variation:bool, position:int}>
     */
    public static function attributes($product)
    {
        $result = [];

        foreach ((array) $product->get_attributes() as $attribute) {
            if (!is_object($attribute)) {
                continue;
            }

            $taxonomy = (bool) $attribute->is_taxonomy();
            $key = (string) $attribute->get_name();

            if ($taxonomy) {
                $options = wc_get_product_terms($product->get_id(), $key, ['fields' => 'names']);
                $name = wc_attribute_label($key);
                $slug = self::taxonomySlug($key);
            } else {
                $options = $attribute->get_options();
                $name = $key;
                $slug = rawurldecode(sanitize_title($key));
            }

            $options = self::clean(is_array($options) ? $options : []);
            if (!$options) {
                continue;
            }

            $result[] = [
                'name' => (string) $name,
                'slug' => (string) $slug,
                'taxonomy' => $taxonomy,
                'options' => $options,
                'visible' => (bool) $attribute->get_visible(),
                'variation' => (bool) $attribute->get_variation(),
                'position' => (int) $attribute->get_position(),
            ];
        }

        usort($result, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        return $result;
    }

    /**
     * مقدارهای یک واریانت: از هر ویژگی تنوع یک مقدار. مقدار خالی یعنی «هر
     * کدام» و فرستاده نمی‌شود.
     *
     * @return array<int, array{name:string, slug:string, taxonomy:bool, option:string}>
     */
    public static function variationAttributes($variation, $parent)
    {
        $parentAttributes = $parent ? (array) $parent->get_attributes() : [];
        $result = [];

        foreach ((array) $variation->get_attributes() as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;
            if ($value === '') {
                continue;
            }

            $parentAttribute = isset($parentAttributes[$key]) && is_object($parentAttributes[$key]) ? $parentAttributes[$key] : null;
            $taxonomy = $parentAttribute ? (bool) $parentAttribute->is_taxonomy() : strpos($key, 'pa_') === 0;

            if ($taxonomy) {
                $term = get_term_by('slug', $value, $key);
                $option = ($term && !is_wp_error($term)) ? $term->name : rawurldecode($value);
                $name = wc_attribute_label($key);
                $slug = self::taxonomySlug($key);
            } else {
                $name = $parentAttribute ? (string) $parentAttribute->get_name() : $key;
                $option = $value;
                $slug = rawurldecode(sanitize_title($name));
            }

            $result[] = [
                'name' => (string) $name,
                'slug' => (string) $slug,
                'taxonomy' => $taxonomy,
                'option' => (string) $option,
            ];
        }

        return $result;
    }

    /**
     * «pa_color» → «color»؛ نامک فارسی ووکامرس URLشده است و خوانای CRM نیست.
     */
    public static function taxonomySlug($taxonomy)
    {
        $slug = function_exists('wc_attribute_taxonomy_slug')
            ? wc_attribute_taxonomy_slug($taxonomy)
            : preg_replace('/^pa_/', '', (string) $taxonomy);

        return rawurldecode((string) $slug);
    }

    /**
     * @param array<int, mixed> $options
     * @return array<int, string>
     */
    private static function clean(array $options)
    {
        $clean = [];
        foreach ($options as $option) {
            $option = trim((string) $option);
            if ($option !== '' && !in_array($option, $clean, true)) {
                $clean[] = $option;
            }
        }

        return $clean;
    }
}
