<?php
/**
 * هویتِ امضاشدهٔ چتِ سایت (ChatIdentity): فقط کاربرِ واردشده توکن می‌گیرد، شکل و امضا همان
 * است که CRM می‌سنجد (App\Ai\Channels\RocketChat\SiteIdentityToken)، انقضا کوتاه است و شناسهٔ
 * CRM یک بار پرسیده و کش می‌شود.
 *
 * اجرا:  php tests/chat-identity-tests.php
 */

require __DIR__ . '/wp-stubs.php';

$base = sys_get_temp_dir() . '/arya-chat-identity-tests-' . getmypid();
@mkdir($base, 0777, true);
arya_test_reset($base);

$GLOBALS['arya_user_meta'] = [];
$GLOBALS['arya_rest_routes'] = [];
function get_user_meta($id, $key, $single = false) { return $GLOBALS['arya_user_meta'][$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { $GLOBALS['arya_user_meta'][$id][$key] = $value; return true; }
function is_user_logged_in() { return !empty($GLOBALS['arya_current_user']); }
function nocache_headers() { $GLOBALS['arya_nocache'] = true; }
function register_rest_route($namespace, $route, $args) { $GLOBALS['arya_rest_routes'][$namespace . $route] = $args; }
class WP_REST_Response {
    public $data; public $status;
    public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
}

require __DIR__ . '/../includes/Settings.php';
require __DIR__ . '/../includes/Logger.php';
require __DIR__ . '/../includes/PersonData.php';
require __DIR__ . '/../includes/ChatIdentity.php';

use Arya\Portal\ChatIdentity;

$failed = 0;
function check_identity($condition, $name) {
    global $failed;
    echo ($condition ? '  PASS: ' : '  FAIL: ') . $name . "\n";
    if (!$condition) {
        $failed++;
    }
}

function wp_user($id, $login) {
    $user = new stdClass();
    $user->ID = $id;
    $user->user_login = $login;
    return $user;
}

$identity = ChatIdentity::instance();
$now = 1800000000;

echo "\nChatIdentity\n";

// مهمان یا رمزِ خالی: هیچ توکنی
check_identity($identity->mint_for(null, $now) === null, 'a logged-out visitor gets nothing');
check_identity($identity->mint_for(wp_user(0, ''), $now) === null, 'user id 0 gets nothing');
$GLOBALS['arya_user_meta'][7][ChatIdentity::META_CRM_ID] = ['id' => 4512, 'login' => '09121112233'];
check_identity($identity->mint_for(wp_user(7, '09121112233'), $now) === null, 'no shared secret configured → nothing');

$GLOBALS['arya_options'][ChatIdentity::OPTION_SECRET] = 'shared-secret-abc';

// شکل و امضا
$minted = $identity->mint_for(wp_user(7, '09121112233'), $now);
check_identity(is_array($minted), 'a logged-in user with a CRM person gets a token');
check_identity((bool) preg_match('/^v1\.4512\.(\d{10})\.([a-f0-9]{64})$/', $minted['token'], $m), 'format v1.{crm id}.{expiry}.{64 hex}');
check_identity((int) $m[1] === $now + ChatIdentity::TTL && $minted['expires_at'] === $now + ChatIdentity::TTL, 'expires one TTL after minting');
check_identity(ChatIdentity::TTL > 0 && ChatIdentity::TTL <= 7200, 'TTL fits under the CRM maximum (2h)');
check_identity(hash_equals(hash_hmac('sha256', 'v1.4512.' . ($now + ChatIdentity::TTL), 'shared-secret-abc'), $m[2]), 'signature = HMAC-SHA256 of "v1.{id}.{exp}" with the shared secret');
check_identity(ChatIdentity::sign(4512, $now + 3600, 'another-secret') !== $minted['token'], 'another secret gives another signature');
check_identity(ChatIdentity::sign(4513, $now + 3600, 'shared-secret-abc') !== $minted['token'], 'another person gives another signature');
check_identity(empty($GLOBALS['arya_http_log']), 'a cached CRM id needs no CRM call');

// شناسهٔ CRM: یک بار با موبایل پرسیده و کش می‌شود
arya_queue_response(200, '{"data":{"id":901,"name":"سارا"}}');
$fresh = $identity->mint_for(wp_user(8, '09125556677'), $now);
check_identity($fresh !== null && strpos($fresh['token'], 'v1.901.') === 0, 'the CRM id is looked up by the phone login');
check_identity(strpos($GLOBALS['arya_http_log'][0]['url'], 'person/09125556677') !== false, 'lookup goes to person/{phone}');
check_identity(($GLOBALS['arya_user_meta'][8][ChatIdentity::META_CRM_ID]['id'] ?? null) === 901, 'the id is cached in user meta');
$identity->mint_for(wp_user(8, '09125556677'), $now);
check_identity(count($GLOBALS['arya_http_log']) === 1, 'the second mint uses the cache');

// نامِ کاربری عوض شد: کشِ قبلی به کار نمی‌رود
arya_queue_response(200, '{"data":{"id":902}}');
$changed = $identity->mint_for(wp_user(8, '09120001122'), $now);
check_identity($changed !== null && strpos($changed['token'], 'v1.902.') === 0, 'a changed login is looked up again');

// شخص در CRM نیست
arya_queue_response(404, '{"message":"No query results for model [App\\\\Models\\\\User]."}');
check_identity($identity->mint_for(wp_user(9, '09129990000'), $now) === null, 'no CRM person → nothing');

// مسیرِ REST: فقط کاربرِ واردشده، بدونِ کش
$identity->register_routes();
$route = $GLOBALS['arya_rest_routes']['arya-portal/v1/chat-identity'] ?? null;
check_identity($route !== null && $route['methods'] === 'POST', 'REST route POST arya-portal/v1/chat-identity is registered');
$GLOBALS['arya_current_user'] = null;
check_identity($route['permission_callback']() === false, 'logged-out requests are refused by the permission callback');
$GLOBALS['arya_current_user'] = wp_user(7, '09121112233');
check_identity($route['permission_callback']() === true, 'logged-in requests are allowed');
$response = $identity->rest_mint(null);
check_identity($response->status === 200 && strpos((string) $response->data['token'], 'v1.4512.') === 0 && $response->data['field'] === ChatIdentity::FIELD, 'the endpoint returns the token and the livechat field name');
check_identity(!empty($GLOBALS['arya_nocache']), 'the endpoint sends no-cache headers');

// ثابتِ wp-config بر گزینه مقدم است (آخر، چون ثابت پاک نمی‌شود)
define('ARYA_PORTAL_CHAT_IDENTITY_SECRET', 'from-wp-config');
check_identity($identity->secret() === 'from-wp-config', 'ARYA_PORTAL_CHAT_IDENTITY_SECRET overrides the option');

if ($failed > 0) {
    echo "\n{$failed} chat identity test(s) failed.\n";
    exit(1);
}

echo "All chat identity tests passed.\n";
