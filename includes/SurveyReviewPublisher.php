<?php

namespace Arya\Portal;

/**
 * نقد تأییدشدهٔ نظرسنجی CRM روی صفحهٔ محصول (دوره / کالا) — هستهٔ نظرسنجی CRM، فاز ۵.
 *
 * CRM فقط پس از تأیید مدیر می‌فرستد. محصول با همان اتصال همیشگی پیدا می‌شود: متای course_id
 * (روی محصول یا تنوعش) = شناسهٔ CRM و portal_category محصول مادر = دستهٔ CRM. برای هر نقد CRM فقط
 * یک دیدگاه ساخته می‌شود (متای crm_review_id)؛ فرستادن دوباره همان را به‌روز می‌کند.
 */
class SurveyReviewPublisher {

    /** @return array{status: bool, code: string, message: string, comment_id?: int, product_id?: int} */
    public static function publish(array $params) {
        $review_id = (int) ($params['crm_review_id'] ?? 0);
        $crm_id = (int) ($params['crm_id'] ?? 0);
        $category_id = (int) ($params['category_id'] ?? 0);
        $content = trim(wp_strip_all_tags((string) ($params['content'] ?? '')));
        if (!$review_id || !$crm_id || !$category_id || $content === '') {
            return ['status' => false, 'code' => 'invalid_request', 'message' => 'شناسهٔ نقد، شناسهٔ آیتم، دسته یا متن ارسال نشده است.'];
        }

        $product_id = self::product_for($crm_id, $category_id);
        if (!$product_id) {
            return ['status' => false, 'code' => 'product_not_found', 'message' => 'محصول این آیتم در سایت پیدا نشد.'];
        }

        $rating = isset($params['rating']) && $params['rating'] !== null && $params['rating'] !== '' ? max(1, min(5, (int) round((float) $params['rating']))) : 0;
        $author = sanitize_text_field((string) ($params['author'] ?? ''));
        $date = !empty($params['date']) && strtotime((string) $params['date']) ? date('Y-m-d H:i:s', strtotime((string) $params['date'])) : current_time('mysql');

        $existing = get_comments([
            'post_id' => $product_id,
            'meta_key' => 'crm_review_id',
            'meta_value' => $review_id,
            'number' => 1,
            'fields' => 'ids',
            'status' => 'all',
        ]);
        $data = [
            'comment_post_ID' => $product_id,
            'comment_author' => $author !== '' ? $author : 'کارآموز',
            'comment_content' => $content,
            'comment_type' => 'review',
            'comment_approved' => 1,
            'comment_date' => $date,
        ];
        if ($existing) {
            $comment_id = (int) $existing[0];
            wp_update_comment(array_merge($data, ['comment_ID' => $comment_id]));
        } else {
            $comment_id = (int) wp_insert_comment($data);
            if (!$comment_id) {
                return ['status' => false, 'code' => 'insert_failed', 'message' => 'ثبت دیدگاه در سایت ناموفق بود.'];
            }
            update_comment_meta($comment_id, 'crm_review_id', $review_id);
        }
        if ($rating) {
            update_comment_meta($comment_id, 'rating', $rating);
        }
        // خریدار تأییدشده: پاسخ‌دهنده کارآموز / خریدار همان دوره یا کالاست
        update_comment_meta($comment_id, 'verified', 1);
        if (class_exists('\WC_Comments') && method_exists('\WC_Comments', 'clear_transients')) {
            \WC_Comments::clear_transients($product_id);
        }

        return ['status' => true, 'code' => 'ok', 'message' => 'نقد روی صفحهٔ محصول ثبت شد.', 'comment_id' => $comment_id, 'product_id' => $product_id];
    }

    /** محصول مادرِ منتشرشده‌ای که به این آیتم CRM وصل است (اولین)، یا 0. */
    public static function product_for($crm_id, $category_id) {
        $posts = get_posts([
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish'],
            'numberposts' => 20,
            'fields' => 'ids',
            'meta_query' => [['key' => 'course_id', 'value' => (string) $crm_id, 'compare' => '=']],
        ]);
        foreach ((array) $posts as $id) {
            $parent = get_post_type($id) === 'product_variation' ? (int) wp_get_post_parent_id($id) : (int) $id;
            if ($parent && (int) get_post_meta($parent, 'portal_category', true) === (int) $category_id && get_post_status($parent) === 'publish') {
                return $parent;
            }
        }

        return 0;
    }
}
