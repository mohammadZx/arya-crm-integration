<?php
/**
 * محتوای کاتالوگ برای ایمپورت CRM: توضیحات، عکس و ویژگی‌های مادر و واریانت.
 *
 * اجرا:  php tests/catalog-tests.php
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/ProductCatalog.php';

use Arya\Portal\ProductCatalog;

// ویژگی سراسری «رنگ» با نامک فارسی URLشده، مثل خود ووکامرس
const COLOR_TAXONOMY = 'pa_%d8%b1%d9%86%da%af';

function sanitize_title($title) { return strtolower(preg_replace('/\s+/u', '-', trim((string) $title))); }
function wc_attribute_label($name) { return $name === COLOR_TAXONOMY ? 'رنگ' : $name; }
function wc_attribute_taxonomy_slug($name) { return preg_replace('/^pa_/', '', $name); }
function wp_get_attachment_image_url($id, $size) { return $id ? "https://site.test/uploads/{$id}-{$size}.jpg" : false; }
function wc_get_product_terms($productId, $taxonomy, $args) { return $GLOBALS['terms'][$taxonomy] ?? []; }
function get_term_by($field, $value, $taxonomy) {
    foreach ($GLOBALS['term_objects'][$taxonomy] ?? [] as $term) {
        if ($term->slug === $value) {
            return $term;
        }
    }
    return false;
}

class CatalogTestAttribute {
    public function __construct(private $name, private $taxonomy, private $options, private $visible = true, private $variation = false, private $position = 0) {}
    public function get_name() { return $this->name; }
    public function is_taxonomy() { return $this->taxonomy; }
    public function get_options() { return $this->options; }
    public function get_visible() { return $this->visible; }
    public function get_variation() { return $this->variation; }
    public function get_position() { return $this->position; }
}

class CatalogTestProduct {
    public function __construct(private $id, private $attributes, private $image = 0, private $description = '', private $short = '') {}
    public function get_id() { return $this->id; }
    public function get_attributes() { return $this->attributes; }
    public function get_image_id() { return $this->image; }
    public function get_description() { return $this->description; }
    public function get_short_description() { return $this->short; }
}

$passed = 0;
$failed = 0;
function same($expected, $actual, $name) {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
        return;
    }
    $failed++;
    echo "  \033[31m✗\033[0m {$name}\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

$GLOBALS['terms'][COLOR_TAXONOMY] = ['قرمز', 'آبی'];
$GLOBALS['term_objects'][COLOR_TAXONOMY] = [
    (object) ['slug' => '%d9%82%d8%b1%d9%85%d8%b2', 'name' => 'قرمز'],
    (object) ['slug' => '%d8%a2%d8%a8%db%8c', 'name' => 'آبی'],
];

$parent = new CatalogTestProduct(10, [
    COLOR_TAXONOMY => new CatalogTestAttribute(COLOR_TAXONOMY, true, [51, 52], true, true, 0),
    'جنس' => new CatalogTestAttribute('جنس', false, ['پشم', ' ', 'پشم'], true, false, 2),
    'سایز' => new CatalogTestAttribute('سایز', false, ['S', 'M'], false, true, 1),
], 77, '<p>کت</p>', 'کوتاه');

echo "parent\n";
$attributes = ProductCatalog::attributes($parent);
same(['رنگ', 'سایز', 'جنس'], array_column($attributes, 'name'), 'attributes ordered by position');
same('رنگ', $attributes[0]['slug'], 'taxonomy slug is decoded and without pa_');
same(['قرمز', 'آبی'], $attributes[0]['options'], 'taxonomy options are term names');
same(true, $attributes[0]['taxonomy'], 'global attribute flagged');
same(['پشم'], $attributes[2]['options'], 'custom options trimmed and unique');
same([true, true, false], array_column($attributes, 'variation'), 'variation flags');
same([true, false, true], array_column($attributes, 'visible'), 'visibility flags');
same('https://site.test/uploads/77-full.jpg', ProductCatalog::image($parent), 'featured image url');
same(['description' => '<p>کت</p>', 'short_description' => 'کوتاه'], ProductCatalog::content($parent), 'descriptions');

echo "variation\n";
$variation = new CatalogTestProduct(11, [
    COLOR_TAXONOMY => '%d8%a2%d8%a8%db%8c',
    'سایز' => 'M',
    'جنس' => '',
]);
$values = ProductCatalog::variationAttributes($variation, $parent);
same([['name' => 'رنگ', 'slug' => 'رنگ', 'taxonomy' => true, 'option' => 'آبی'], ['name' => 'سایز', 'slug' => 'سایز', 'taxonomy' => false, 'option' => 'M']], $values, 'term name and custom value; "any" skipped');
same(null, ProductCatalog::image($variation), 'variation without own image');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
