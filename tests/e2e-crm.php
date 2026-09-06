<?php
/**
 * تست یکپارچهٔ واقعی: افزونه → CRM.
 *
 * برخلاف run-tests.php که HTTP را استاب می‌کند، اینجا wp_remote_post واقعاً
 * به CRM می‌زند. چیزی که ثابت می‌کند: قالبِ بسته‌ای که Logger می‌سازد را
 * SiteLogController بدون تغییر می‌پذیرد — یعنی دو سر قرارداد جفت‌اند.
 *
 * اجرا:  php tests/e2e-crm.php <crm-url> <api-token>
 */

$crmUrl = $argv[1] ?? 'http://localhost:8004';
$token  = $argv[2] ?? '';

if (!$token) {
    fwrite(STDERR, "usage: php tests/e2e-crm.php <crm-url> <api-token>\n");
    exit(2);
}

require __DIR__ . '/wp-stubs.php';

// wp_remote_post واقعی: به CRM می‌زند به جای صف استاب.
function arya_real_post($url, $args) {
    $ch = curl_init($url);
    $headers = [];
    foreach (($args['headers'] ?? []) as $k => $v) {
        $headers[] = $k . ': ' . $v;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $args['body'] ?? '',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return new WP_Error('http_request_failed', $err);
    }
    return ['response' => ['code' => $code], 'body' => $body];
}

$base = sys_get_temp_dir() . '/arya-e2e-' . getmypid();
@mkdir($base, 0777, true);
arya_test_reset($base);
$GLOBALS['arya_options']['arya_portal_url'] = $crmUrl;
$GLOBALS['arya_options']['arya_portal_api_token'] = $token;

// صف استاب را دور می‌زنیم: هر POST مستقیم به CRM می‌رود.
$GLOBALS['arya_http_queue'][] = fn($url, $args) => arya_real_post($url, $args);
$GLOBALS['arya_http_queue'][] = fn($url, $args) => arya_real_post($url, $args);

require __DIR__ . '/../includes/Settings.php';
require __DIR__ . '/../includes/Logger.php';

use Arya\Portal\Logger;

$logger = Logger::instance();
$ship = new ReflectionMethod(Logger::class, 'ship');
$ship->setAccessible(true);
$build = new ReflectionMethod(Logger::class, 'build_entry');
$build->setAccessible(true);

$failed = 0;
function check($cond, $label) {
    global $failed;
    echo ($cond ? "  ✓ " : "  ✗ ") . $label . "\n";
    if (!$cond) $failed++;
}

echo "\nارسال واقعی به {$crmUrl}/api/v1/site-log\n";

$entry = $build->invoke(
    $logger,
    Logger::WS_HTTP_STATUS,
    'e2e: وب‌سرویس کد وضعیت 503 برگرداند.',
    ['operation' => 'getPersonAlert', 'endpoint' => 'person/09364240674/get-alert', 'http_status' => 503, 'phone' => '09364240674'],
    Logger::LEVEL_ERROR,
    Logger::SOURCE_PORTAL
);

$ok = $ship->invoke($logger, [$entry]);
check($ok, 'CRM بستهٔ ساخته‌شده توسط Logger را پذیرفت');
check($logger->pending_count() === 0, 'چیزی در صف باقی نماند');

/*
 * تلاش دوباره بعد از قطعی نباید رد شود. توجه: خروجی CRM فایل است نه جدول،
 * پس ارسال مجدد یک سطر تکراری در فایل می‌سازد — `event_id` در هر سطر هست تا
 * بشود تکراری‌ها را تشخیص داد. برای یک فایل لاگ این پذیرفتنی است؛ گم‌شدن لاگ
 * پرهزینه‌تر از تکراری‌شدنش است.
 */
$again = $ship->invoke($logger, [$entry]);
check($again, 'تلاش دوبارهٔ ارسال هم پذیرفته می‌شود (لاگ گم نمی‌شود)');

echo "\nشناسهٔ رخداد ارسالی: {$entry['event_id']}\n";
echo $failed === 0 ? "\n\033[32mیکپارچگی افزونه↔CRM تایید شد\033[0m\n\n" : "\n\033[31m{$failed} مورد ناموفق\033[0m\n\n";

foreach (glob($base . '/arya-portal-logs/*/*') ?: [] as $f) @unlink($f);
exit($failed === 0 ? 0 : 1);
