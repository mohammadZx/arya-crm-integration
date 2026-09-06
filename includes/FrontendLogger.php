<?php

namespace Arya\Portal;

/**
 * جمع‌آوری خطاهای مرورگر کاربر و ریختنشان در همان مسیر لاگ.
 *
 * تا حالا وقتی هشدار داشبورد یا فرم اطلاعات در مرورگر می‌شکست، هیچ ردی
 * باقی نمی‌ماند. حالا window.onerror، Promiseهای ردشده و شکستِ درخواست‌های
 * AJAX همان کد و همان شناسهٔ رخداد را می‌گیرند که خطاهای سمت سرور.
 *
 * محدودیت‌ها عمدی‌اند: خطاهای مرورگر ورودی بیرونی‌اند و بدون سقف می‌توانند
 * دیسک را پر کنند — nonce، سقف در هر کاربر/بازه، و سقف اندازهٔ پیام.
 */
class FrontendLogger {

    private static $instance = null;

    /** حداکثر خطای پذیرفته‌شده از هر بازدیدکننده در هر بازهٔ محدودیت. */
    const RATE_LIMIT = 20;
    const RATE_WINDOW = 300;

    const ACTION = 'arya_portal_js_error';

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handle']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue'], 1);
    }

    public function enqueue() {
        if (is_admin() || !Logger::enabled() || !is_account_page() || !is_user_logged_in()) {
            return;
        }

        wp_enqueue_script(
            'arya-portal-logger',
            ARYA_PORTAL_PLUGIN_URL . 'assets/js/arya-portal-logger.js',
            [],
            ARYA_PORTAL_VERSION,
            false // در <head>، تا خطاهای اسکریپت‌های بعدی هم گرفته شوند.
        );

        wp_localize_script('arya-portal-logger', 'aryaPortalLogger', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce(self::ACTION),
            // سویچ مستقل خطاهای مرورگر، زیر سویچ اصلی.
            'enabled' => (bool) apply_filters('arya_portal_frontend_logging', true),
        ]);
    }

    public function handle() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::ACTION)) {
            wp_send_json_error(['message' => 'invalid nonce'], 403);
        }

        // اسکریپت ممکن است از کش مرورگر بعد از خاموش‌شدن سویچ اجرا شود.
        if (!Logger::enabled()) {
            wp_send_json_success(['disabled' => true]);
        }

        if (!$this->within_rate_limit()) {
            // پذیرفته اما دور ریخته می‌شود: کلاینت نباید دوباره تلاش کند.
            wp_send_json_success(['throttled' => true]);
        }

        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : Logger::JS_ERROR;
        if (!in_array($code, [Logger::JS_ERROR, Logger::JS_PROMISE_REJECTION, Logger::JS_REQUEST_FAILED], true)) {
            $code = Logger::JS_ERROR;
        }

        $message = isset($_POST['message'])
            ? sanitize_text_field(wp_unslash($_POST['message']))
            : 'خطای ناشناخته در مرورگر';

        $context = [];
        if (isset($_POST['context'])) {
            $raw = wp_unslash($_POST['context']);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $context = $this->sanitize_context($decoded);
            }
        }

        $context['page'] = isset($_POST['page'])
            ? esc_url_raw(wp_unslash($_POST['page']))
            : '';
        $context['user_agent'] = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 300)
            : '';

        $event_id = Logger::instance()->log(
            $code,
            $message,
            $context,
            Logger::LEVEL_ERROR,
            Logger::SOURCE_FRONTEND
        );

        wp_send_json_success(['event_id' => $event_id]);
    }

    /**
     * سقف در هر بازدیدکننده. یک صفحهٔ خرابِ در حال حلقه‌زدن نباید هزاران سطر
     * لاگ بنویسد.
     */
    private function within_rate_limit() {
        $key = 'arya_js_log_' . md5(
            (string) get_current_user_id() . '|' .
            (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '')
        );

        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }

        set_transient($key, $count + 1, self::RATE_WINDOW);
        return true;
    }

    private function sanitize_context(array $context) {
        $allowed = ['source', 'lineno', 'colno', 'stack', 'url', 'status', 'response', 'detail', 'component'];
        $clean = [];

        foreach ($allowed as $key) {
            if (!isset($context[$key])) {
                continue;
            }
            $value = $context[$key];
            if (is_scalar($value)) {
                $clean[$key] = is_string($value)
                    ? mb_substr(sanitize_textarea_field($value), 0, 2000)
                    : $value;
            }
        }

        return $clean;
    }
}
