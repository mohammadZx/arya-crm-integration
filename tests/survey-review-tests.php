<?php
/**
 * تست نقد نظرسنجی CRM روی صفحهٔ محصول (SurveyReviewPublisher): پیدا کردن محصول با اتصال
 * course_id + portal_category، یک دیدگاه برای هر نقد CRM، امتیاز ۱ تا ۵.
 * اجرا: php tests/survey-review-tests.php
 */

namespace {
    $GLOBALS['t_posts'] = [];      // id => [type, parent, status, meta]
    $GLOBALS['t_comments'] = [];   // id => [data, meta]
    $GLOBALS['t_cleared'] = [];

    function wp_strip_all_tags($s) { return strip_tags($s); }
    function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
    function current_time($f) { return '2026-11-12 10:00:00'; }
    function get_post_type($id) { return $GLOBALS['t_posts'][$id]['type'] ?? false; }
    function wp_get_post_parent_id($id) { return $GLOBALS['t_posts'][$id]['parent'] ?? 0; }
    function get_post_status($id) { return $GLOBALS['t_posts'][$id]['status'] ?? false; }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['t_posts'][$id]['meta'][$key] ?? ''; }
    function get_posts($args) {
        $value = $args['meta_query'][0]['value'];
        $out = [];
        foreach ($GLOBALS['t_posts'] as $id => $p) {
            if (in_array($p['type'], $args['post_type'], true) && in_array($p['status'], $args['post_status'], true) && (string) ($p['meta']['course_id'] ?? '') === $value) {
                $out[] = $id;
            }
        }
        return $out;
    }
    function get_comments($args) {
        foreach ($GLOBALS['t_comments'] as $id => $c) {
            if ($c['data']['comment_post_ID'] === $args['post_id'] && (string) ($c['meta'][$args['meta_key']] ?? '') === (string) $args['meta_value']) {
                return [$id];
            }
        }
        return [];
    }
    function wp_insert_comment($data) { $id = count($GLOBALS['t_comments']) + 100; $GLOBALS['t_comments'][$id] = ['data' => $data, 'meta' => []]; return $id; }
    function wp_update_comment($data) { $id = $data['comment_ID']; unset($data['comment_ID']); $GLOBALS['t_comments'][$id]['data'] = $data; return 1; }
    function update_comment_meta($id, $key, $value) { $GLOBALS['t_comments'][$id]['meta'][$key] = $value; }
    class WC_Comments { public static function clear_transients($id) { $GLOBALS['t_cleared'][] = $id; } }

    require __DIR__ . '/../includes/SurveyReviewPublisher.php';

    $passed = 0; $failed = 0;
    function check($cond, $name) { global $passed, $failed; if ($cond) { $passed++; echo "  ✓ {$name}\n"; } else { $failed++; echo "  ✗ {$name}\n"; } }

    // دورهٔ 20 (دستهٔ ۱) روی تنوع 11 از محصول 10؛ کالای 20 (دستهٔ ۲) روی محصول 30؛ محصول 40 پیش‌نویس
    $GLOBALS['t_posts'] = [
        10 => ['type' => 'product', 'parent' => 0, 'status' => 'publish', 'meta' => ['portal_category' => '1']],
        11 => ['type' => 'product_variation', 'parent' => 10, 'status' => 'publish', 'meta' => ['course_id' => '20']],
        30 => ['type' => 'product', 'parent' => 0, 'status' => 'publish', 'meta' => ['course_id' => '20', 'portal_category' => '2']],
        40 => ['type' => 'product', 'parent' => 0, 'status' => 'draft', 'meta' => ['course_id' => '21', 'portal_category' => '1']],
    ];
    $P = \Arya\Portal\SurveyReviewPublisher::class;

    check($P::product_for(20, 1) === 10, 'course → parent product of the variation');
    check($P::product_for(20, 2) === 30, 'same CRM id, product category → the product');
    check($P::product_for(21, 1) === 0, 'draft product is not used');

    $r = $P::publish(['crm_review_id' => 5, 'crm_id' => 20, 'category_id' => 1, 'content' => '<b>عالی</b> بود', 'rating' => 4.6, 'author' => 'علی م.']);
    check($r['status'] === true && $r['product_id'] === 10, 'published on product 10');
    $c = $GLOBALS['t_comments'][$r['comment_id']];
    check($c['data']['comment_content'] === 'عالی بود' && $c['data']['comment_type'] === 'review' && $c['data']['comment_approved'] === 1, 'approved review, tags stripped');
    check($c['meta']['rating'] === 5 && $c['meta']['verified'] === 1 && $c['meta']['crm_review_id'] === 5, 'rating rounded to 5, verified, crm id kept');
    check($GLOBALS['t_cleared'] === [10], 'WooCommerce rating cache cleared');

    $again = $P::publish(['crm_review_id' => 5, 'crm_id' => 20, 'category_id' => 1, 'content' => 'متن ویرایش‌شده', 'rating' => 3]);
    check($again['comment_id'] === $r['comment_id'] && count($GLOBALS['t_comments']) === 1, 'sending again updates the same comment');
    check($GLOBALS['t_comments'][$r['comment_id']]['data']['comment_content'] === 'متن ویرایش‌شده' && $GLOBALS['t_comments'][$r['comment_id']]['meta']['rating'] === 3, 'text and rating updated');

    check($P::publish(['crm_review_id' => 6, 'crm_id' => 99, 'category_id' => 1, 'content' => 'x'])['code'] === 'product_not_found', 'unknown item → product_not_found');
    check($P::publish(['crm_review_id' => 7, 'crm_id' => 20, 'category_id' => 1, 'content' => '  '])['code'] === 'invalid_request', 'empty text → invalid_request');

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed ? 1 : 0);
}
