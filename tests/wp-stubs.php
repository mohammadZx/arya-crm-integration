<?php
/**
 * حداقلِ وردپرسِ لازم برای تست Logger و PersonData بدون بوت کامل وردپرس.
 *
 * هدف این نیست که وردپرس را شبیه‌سازی کنیم؛ فقط همان چند تابعی که این دو
 * کلاس واقعاً صدا می‌زنند، با رفتار قابل کنترل. پاسخ‌های HTTP از صف
 * $GLOBALS['arya_http_queue'] برداشته می‌شوند تا تست بتواند دقیقاً بگوید
 * وب‌سرویس چه چیزی برگرداند.
 */

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('WP_CONTENT_DIR')) define('WP_CONTENT_DIR', sys_get_temp_dir() . '/arya-test-content');
if (!defined('AUTH_SALT')) define('AUTH_SALT', 'test-salt');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('ARYA_PORTAL_VERSION')) define('ARYA_PORTAL_VERSION', '1.0.0');
if (!defined('ARYA_PORTAL_PLUGIN_URL')) define('ARYA_PORTAL_PLUGIN_URL', 'https://example.test/plugin/');

$GLOBALS['arya_options'] = [];
$GLOBALS['arya_transients'] = [];
$GLOBALS['arya_actions'] = [];
$GLOBALS['arya_http_queue'] = [];
$GLOBALS['arya_http_log'] = [];
$GLOBALS['arya_scheduled'] = [];

class WP_Error {
    private $code; private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }

/** پاسخ بعدی صف؛ اگر صف خالی بود، یک ۲۰۰ خالی. */
function arya_next_http_response($url, $args, $method) {
    $GLOBALS['arya_http_log'][] = ['url' => $url, 'args' => $args, 'method' => $method];

    if (empty($GLOBALS['arya_http_queue'])) {
        return ['response' => ['code' => 200], 'body' => '{}'];
    }
    $next = array_shift($GLOBALS['arya_http_queue']);
    return is_callable($next) ? $next($url, $args) : $next;
}

function wp_remote_get($url, $args = []) { return arya_next_http_response($url, $args, 'GET'); }
function wp_remote_post($url, $args = []) { return arya_next_http_response($url, $args, 'POST'); }

function wp_remote_retrieve_body($response) {
    if (is_wp_error($response)) return '';
    return $response['body'] ?? '';
}

function wp_remote_retrieve_response_code($response) {
    if (is_wp_error($response)) return '';
    return $response['response']['code'] ?? 0;
}

function get_option($key, $default = false) { return $GLOBALS['arya_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['arya_options'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['arya_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['arya_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['arya_transients'][$key]); return true; }

function add_action($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['arya_actions'][$hook][] = $cb; }
function add_filter($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['arya_actions'][$hook][] = $cb; }
function apply_filters($hook, $value) { return $value; }
function do_action($hook) { }

function wp_next_scheduled($hook) { return $GLOBALS['arya_scheduled'][$hook] ?? false; }
function wp_schedule_event($ts, $rec, $hook) { $GLOBALS['arya_scheduled'][$hook] = $ts; return true; }
function wp_unschedule_event($ts, $hook) { unset($GLOBALS['arya_scheduled'][$hook]); return true; }

function wp_upload_dir() { return ['basedir' => $GLOBALS['arya_upload_dir'], 'error' => false]; }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
function home_url($path = '') { return 'https://site.test' . $path; }
function current_time($format) { return $format === 'mysql' ? date('Y-m-d H:i:s') : date($format); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags | JSON_UNESCAPED_UNICODE); }
function wp_generate_uuid4() { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)); }
function sanitize_text_field($str) { return trim(strip_tags((string) $str)); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function wp_get_current_user() { return $GLOBALS['arya_current_user'] ?? null; }
function get_current_user_id() { return isset($GLOBALS['arya_current_user']) ? $GLOBALS['arya_current_user']->ID : 0; }
function is_admin() { return false; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function checked($checked, $current = true, $echo = true) { $r = ((string) $checked === (string) $current) ? " checked='checked'" : ''; if ($echo) echo $r; return $r; }
function selected($selected, $current = true, $echo = true) { $r = ((string) $selected === (string) $current) ? " selected='selected'" : ''; if ($echo) echo $r; return $r; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function esc_url_raw($s) { return (string) $s; }

/** برای تست‌ها: صف پاسخ و وضعیت را از صفر بساز. */
function arya_test_reset($upload_dir) {
    $GLOBALS['arya_upload_dir'] = $upload_dir;
    $GLOBALS['arya_http_queue'] = [];
    $GLOBALS['arya_http_log'] = [];
    $GLOBALS['arya_transients'] = [];
    $GLOBALS['arya_options'] = [
        'arya_portal_url' => 'https://crm.test',
        'arya_portal_api_token' => 'test-token',
    ];
}

function arya_queue_response($code, $body) {
    $GLOBALS['arya_http_queue'][] = ['response' => ['code' => $code], 'body' => $body];
}

function arya_queue_error($message) {
    $GLOBALS['arya_http_queue'][] = new WP_Error('http_request_failed', $message);
}
