<?php
/**
 * تست‌های لایهٔ لاگ افزونهٔ پورتال.
 *
 * افزونه‌ها PHPUnit ندارند و بوت کردن کامل وردپرس برای این لایه لازم نیست؛
 * بنابراین یک اجراکنندهٔ کوچک با استاب‌های وردپرس. آنچه واقعاً باید ثابت شود:
 *   - هر شکستِ وب‌سرویس کد درست می‌گیرد و روی فایل روزانه می‌نشیند.
 *   - خروجی متدهای PersonData نسبت به قبل تغییر نکرده است.
 *   - وقتی CRM در دسترس نیست، لاگ گم نمی‌شود و بعداً ارسال می‌شود.
 */

require __DIR__ . '/wp-stubs.php';

$base = sys_get_temp_dir() . '/arya-logger-tests-' . getmypid();
@mkdir($base, 0777, true);
arya_test_reset($base);

require __DIR__ . '/../includes/Settings.php';
require __DIR__ . '/../includes/Logger.php';
require __DIR__ . '/../includes/PersonData.php';

use Arya\Portal\Logger;
use Arya\Portal\PersonData;

$passed = 0;
$failed = 0;
$failures = [];

function ok($condition, $name) {
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $failed++;
        $failures[] = $name;
        echo "  \033[31m✗\033[0m {$name}\n";
    }
}

function same($expected, $actual, $name) {
    $equal = $expected === $actual;
    if (!$equal) {
        echo "      expected: " . var_export($expected, true) . "\n";
        echo "      actual:   " . var_export($actual, true) . "\n";
    }
    ok($equal, $name);
}

/** پاک کردن فایل‌های لاگ بین تست‌ها. */
function reset_logs() {
    $dir = Logger::instance()->log_dir();
    foreach (glob($dir . '/*.log') ?: [] as $file) {
        @unlink($file);
    }
    // شمارندهٔ «حداکثر در هر درخواست» و صف ارسال هم باید صفر شود.
    $ref = new ReflectionClass(Logger::class);
    $logger = Logger::instance();
    foreach (['written' => 0, 'pending' => [], 'shipping' => false] as $prop => $value) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($logger, $value);
    }
}

function today_entries() {
    return Logger::instance()->read_day(null, [], 1000);
}

function codes_logged() {
    return array_column(today_entries(), 'code');
}

// در همهٔ تست‌ها ارسال به CRM را به صف می‌بریم تا خودِ تست شبکه نزند،
// مگر تست‌هایی که صریحاً ارسال را می‌سنجند.
$GLOBALS['arya_ship_mode'] = 'cron';
function apply_filters_override($hook, $value) { return $value; }

echo "\n\033[1mLogger: ذخیره‌سازی روزانه\033[0m\n";

reset_logs();
$id = Logger::instance()->log(Logger::WS_HTTP_STATUS, 'خطای ۵۰۰', ['endpoint' => 'person/x', 'http_status' => 500]);
$entries = today_entries();
ok($id !== '', 'log() یک شناسهٔ رخداد برمی‌گرداند');
same(1, count($entries), 'یک سطر روی فایل امروز نوشته می‌شود');
same(Logger::WS_HTTP_STATUS, $entries[0]['code'], 'کد خطا ذخیره می‌شود');
same(500, $entries[0]['http_status'], 'کد وضعیت از context بیرون کشیده می‌شود');
same('person/x', $entries[0]['endpoint'], 'سرویس از context بیرون کشیده می‌شود');
ok(strpos(Logger::instance()->daily_file(), date('Y-m-d')) !== false, 'نام فایل شامل تاریخ روز است');

reset_logs();
Logger::instance()->log(Logger::WS_INVALID_JSON, 'a', [], Logger::LEVEL_WARNING);
Logger::instance()->log(Logger::WS_EMPTY_BODY, 'b');
$stats = Logger::instance()->day_stats(null);
same(2, $stats['total'], 'آمار روز تعداد کل را می‌شمارد');
same(1, $stats['by_level']['warning'], 'آمار به تفکیک سطح درست است');

reset_logs();
Logger::instance()->log(Logger::WS_HTTP_STATUS, 'x', ['token' => 'super-secret', 'api_token' => 'abc']);
$entry = today_entries()[0];
same('***', $entry['context']['token'], 'توکن در context ماسک می‌شود');
same('***', $entry['context']['api_token'], 'api_token هم ماسک می‌شود');

reset_logs();
Logger::instance()->log(Logger::WS_HTTP_STATUS, 'x', ['a' => 1]);
Logger::instance()->log(Logger::WS_INVALID_JSON, 'y', ['b' => 2]);
same(
    [Logger::WS_INVALID_JSON],
    array_column(Logger::instance()->read_day(null, ['code' => Logger::WS_INVALID_JSON]), 'code'),
    'فیلتر بر اساس کد کار می‌کند'
);

echo "\n\033[1mLogger: کد پیگیری\033[0m\n";
same('WS_HTTP_STATUS-abcd1234', Logger::reference('WS_HTTP_STATUS', 'abcd12345678'), 'کد پیگیری = کد + ۸ رقم شناسه');
same('WS_HTTP_STATUS', Logger::reference('WS_HTTP_STATUS', ''), 'بدون شناسه، فقط کد');

echo "\n\033[1mPersonData: تشخیص علت شکست\033[0m\n";

reset_logs();
arya_queue_error('cURL error 28: Operation timed out');
$person = new PersonData('09120000000');
$result = $person->getPersonByPhone('09120000000');
same(null, $result, 'قرارداد قبلی حفظ شده: شکست شبکه => null');
same([Logger::WS_REQUEST_FAILED], codes_logged(), 'شکست شبکه کد WS_REQUEST_FAILED می‌گیرد');
$err = $person->getLastError();
same(Logger::WS_REQUEST_FAILED, $err['code'], 'getLastError کد را برمی‌گرداند');
ok(strpos(today_entries()[0]['context']['operation'], 'getPersonByPhone') !== false, 'نام متدِ فراخوان در لاگ ثبت می‌شود');

reset_logs();
arya_queue_response(500, '{"message":"Server Error"}');
$person = new PersonData('09120000000');
$result = $person->getPersonRegisters();
same('Server Error', $result->message, 'قرارداد قبلی حفظ شده: بدنهٔ خطای ۵۰۰ همچنان decode می‌شود');
same([Logger::WS_HTTP_STATUS], codes_logged(), 'کد وضعیت غیر ۲xx کد WS_HTTP_STATUS می‌گیرد');
same(500, today_entries()[0]['http_status'], 'کد وضعیت در لاگ ثبت می‌شود');

reset_logs();
arya_queue_response(200, '');
$person = new PersonData('09120000000');
same(null, $person->getPersonRegisters(), 'قرارداد قبلی حفظ شده: بدنهٔ خالی => null');
same([Logger::WS_EMPTY_BODY], codes_logged(), 'بدنهٔ خالی کد WS_EMPTY_BODY می‌گیرد');

reset_logs();
arya_queue_response(200, '<html>gateway error</html>');
$person = new PersonData('09120000000');
same(null, $person->getPersonRegisters(), 'قرارداد قبلی حفظ شده: پاسخ غیر JSON => null');
same([Logger::WS_INVALID_JSON], codes_logged(), 'پاسخ غیر JSON کد WS_INVALID_JSON می‌گیرد');
ok(strpos(today_entries()[0]['context']['body_preview'], 'gateway error') !== false, 'پیش‌نمایش بدنه در لاگ هست');

reset_logs();
$GLOBALS['arya_options']['arya_portal_api_token'] = '';
arya_queue_response(200, '{"data":{}}');
$person = new PersonData('09120000000');
$person->getPersonRegisters();
ok(in_array(Logger::WS_NOT_CONFIGURED, codes_logged(), true), 'نبودِ توکن کد WS_NOT_CONFIGURED می‌گیرد');
$GLOBALS['arya_options']['arya_portal_api_token'] = 'test-token';

echo "\n\033[1mgetPersonAlert: هیچ هشدار جعلی\033[0m\n";

reset_logs();
arya_queue_response(500, '{"message":"boom"}');
$person = new PersonData('09120000000');
same(null, $person->getPersonAlert(), 'شکست وب‌سرویس => null (نه دادهٔ ساختگی)');
same(Logger::WS_HTTP_STATUS, $person->getLastError()['code'], 'کد خطای هشدار در دسترس فراخوان است');

reset_logs();
arya_queue_response(200, '{"something_else":1}');
$person = new PersonData('09120000000');
$alert = $person->getPersonAlert();
ok(is_object($alert), 'پاسخ معتبر بدون data همچنان decode می‌شود');
same([Logger::ALERT_MISSING_DATA], codes_logged(), 'نبودِ کلید data کد ALERT_MISSING_DATA می‌گیرد');
same(['something_else'], today_entries()[0]['context']['received'], 'کلیدهای واقعیِ دریافتی در لاگ ثبت می‌شوند');

reset_logs();
$person = new PersonData('');
same(null, $person->getPersonAlert(), 'بدون شماره، درخواستی زده نمی‌شود');
same([Logger::ALERT_NO_PHONE], codes_logged(), 'نبودِ شماره کد ALERT_NO_PHONE می‌گیرد');

echo "\n\033[1mgetPersonDataForm: کدام پارامتر نبود\033[0m\n";

reset_logs();
arya_queue_response(200, json_encode([
    'person' => ['data' => ['id' => 1]],
    'personData' => ['data' => []],
    // dependency و schema عمداً نیستند
]));
$person = new PersonData('09120000000');
$form = $person->getPersonDataForm();
same(false, $form['success'], 'نبودِ کلید => success=false');
same(Logger::WS_MISSING_FIELD, $form['code'], 'کد خطا در پاسخ فرم برمی‌گردد');
ok(!empty($form['reference']), 'کد پیگیری برای نمایش به کاربر ساخته می‌شود');
$missing = today_entries()[0]['context']['missing'];
same(['dependency', 'schema'], $missing, 'لاگ دقیقاً می‌گوید کدام پارامترها نبودند');

reset_logs();
arya_queue_response(200, json_encode([
    'person' => ['data' => ['id' => 1]],
    'personData' => ['data' => []],
    'dependency' => ['jobs' => []],
    'schema' => ['no_fields_here' => true],
]));
$person = new PersonData('09120000000');
$form = $person->getPersonDataForm();
same(false, $form['success'], 'اسکیمای بدون fields => success=false');
same(Logger::USERINFO_INVALID_SCHEMA, $form['code'], 'اسکیمای خراب کد USERINFO_INVALID_SCHEMA می‌گیرد');

reset_logs();
arya_queue_error('connection refused');
$person = new PersonData('09120000000');
$form = $person->getPersonDataForm();
same(Logger::WS_REQUEST_FAILED, $form['code'], 'شکست شبکه در فرم، کد شبکه را نگه می‌دارد');

reset_logs();
arya_queue_response(200, json_encode([
    'person' => ['data' => ['id' => 1]],
    'personData' => ['data' => [['meta_key' => 'x', 'meta_value' => 'y']]],
    'dependency' => ['jobs' => []],
    'schema' => ['fields' => [['key' => 'first_name']]],
    'translations' => ['a' => 'b'],
    'fieldReviews' => [],
]));
$person = new PersonData('09120000000');
$form = $person->getPersonDataForm();
same(true, $form['success'], 'پاسخ کامل => success=true');
same([], codes_logged(), 'مسیر موفق هیچ خطایی لاگ نمی‌کند');
same(null, $person->getLastError(), 'مسیر موفق last_error را پاک می‌کند');

reset_logs();
arya_queue_response(200, json_encode([
    'person' => ['data' => ['id' => 1]],
    'personData' => ['data' => []],
    'dependency' => ['jobs' => []],
    'schema' => ['fields' => [['key' => 'first_name']]],
]));
$person = new PersonData('09120000000');
$form = $person->getPersonDataForm();
same(true, $form['success'], 'نبودِ بخش اختیاری مانع نمایش فرم نیست');
same(
    [Logger::USERINFO_EMPTY_SECTION, Logger::USERINFO_EMPTY_SECTION],
    codes_logged(),
    'بخش‌های اختیاریِ غایب فقط هشدار می‌گیرند'
);

echo "\n\033[1mارسال به CRM\033[0m\n";

reset_logs();
$logger = Logger::instance();
$ref = new ReflectionMethod(Logger::class, 'ship');
$ref->setAccessible(true);

arya_queue_response(200, '{"accepted_count":1}');
$entry = ['event_id' => 'e1', 'occurred_at' => date('Y-m-d H:i:s'), 'code' => 'X', 'level' => 'error', 'source' => 's', 'message' => 'm', 'context' => []];
same(true, $ref->invoke($logger, [$entry]), 'پاسخ ۲۰۰ یعنی ارسال موفق');
same(0, $logger->pending_count(), 'بعد از ارسال موفق، صف خالی است');
$sent = end($GLOBALS['arya_http_log']);
ok(strpos($sent['url'], '/api/v1/site-log') !== false, 'به اندپوینت site-log ارسال می‌شود');
same('e1', json_decode($sent['args']['body'], true)['logs'][0]['event_id'], 'بدنه شامل شناسهٔ رخداد است');

reset_logs();
arya_queue_error('crm is down');
same(false, $ref->invoke($logger, [$entry]), 'قطعی CRM یعنی ارسال ناموفق');
same(1, $logger->pending_count(), 'لاگ ناموفق در صف می‌ماند (گم نمی‌شود)');
ok(in_array(Logger::LOG_SHIP_FAILED, codes_logged(), true), 'خودِ شکستِ ارسال هم محلی لاگ می‌شود');

// حلقهٔ بی‌پایان: شکستِ ارسالِ لاگِ شکستِ ارسال نباید دوباره ارسال شود.
$ship_failures = array_filter(today_entries(), fn ($e) => $e['code'] === Logger::LOG_SHIP_FAILED);
same(1, count($ship_failures), 'شکست ارسال فقط یک بار ثبت می‌شود (بدون حلقه)');

arya_queue_response(200, '{"accepted_count":1}');
$result = $logger->flush_queue();
same(1, $result['sent'], 'کرون صف را دوباره می‌فرستد');
same(0, $logger->pending_count(), 'صف بعد از ارسال موفق خالی می‌شود');

reset_logs();
// ارسالِ تودرتو (مثلاً خطایی که حین ارسال رخ دهد) نباید لاگ را دور بیندازد.
$shippingProp = (new ReflectionClass(Logger::class))->getProperty('shipping');
$shippingProp->setAccessible(true);
$shippingProp->setValue($logger, true);
same(false, $ref->invoke($logger, [$entry]), 'ارسال تودرتو انجام نمی‌شود');
same(1, $logger->pending_count(), 'ولی بستهٔ آن به صف می‌رود (دور ریخته نمی‌شود)');
$shippingProp->setValue($logger, false);

echo "\n\033[1mسویچ‌های غیرفعال‌سازی\033[0m\n";

reset_logs();
$GLOBALS['arya_options']['arya_portal_logging_enabled'] = 0;
same('', Logger::instance()->log(Logger::WS_HTTP_STATUS, 'نباید ثبت شود'), 'سویچ خاموش => log() شناسه نمی‌دهد');
same(0, count(today_entries()), 'سویچ خاموش => هیچ چیزی روی فایل نوشته نمی‌شود');
same(0, Logger::instance()->pending_count(), 'سویچ خاموش => چیزی به صف نمی‌رود');
same(['sent' => 0, 'failed' => 0], Logger::instance()->flush_queue(), 'سویچ خاموش => کرون کاری نمی‌کند');

// وب‌سرویسی که خطا می‌دهد هم نباید چیزی بنویسد، ولی رفتارش عوض نشود.
arya_queue_error('down');
$person = new PersonData('09120000000');
same(null, $person->getPersonRegisters(), 'با سویچ خاموش، خروجی متدها همان قبلی است');
same(0, count(today_entries()), 'با سویچ خاموش، خطای وب‌سرویس هم لاگ نمی‌شود');
ok($person->getLastError() !== null, 'ولی getLastError همچنان کد خطا را می‌داند');

$GLOBALS['arya_options']['arya_portal_logging_enabled'] = 1;
reset_logs();
Logger::instance()->log(Logger::WS_HTTP_STATUS, 'حالا باید ثبت شود');
same(1, count(today_entries()), 'روشن‌کردن دوباره، ثبت را برمی‌گرداند');

// سویچ ارسال: لاگ محلی بماند، به CRM نرود
reset_logs();
$GLOBALS['arya_options']['arya_portal_log_shipping_enabled'] = 0;
$before = count($GLOBALS['arya_http_log']);
Logger::instance()->log(Logger::WS_HTTP_STATUS, 'محلی بله، ارسال خیر');
same(1, count(today_entries()), 'ارسالِ خاموش => لاگ محلی همچنان نوشته می‌شود');
same(0, Logger::instance()->pending_count(), 'ارسالِ خاموش => صف پر نمی‌شود');
same(['sent' => 0, 'failed' => 0], Logger::instance()->flush_queue(), 'ارسالِ خاموش => کرون چیزی نمی‌فرستد');
same($before, count($GLOBALS['arya_http_log']), 'ارسالِ خاموش => هیچ درخواست HTTPی زده نمی‌شود');
$GLOBALS['arya_options']['arya_portal_log_shipping_enabled'] = 1;

echo "\n\033[1mنگهداری روزانه\033[0m\n";

reset_logs();
$dir = Logger::instance()->log_dir();
$old = $dir . '/arya-' . date('Y-m-d', strtotime('-40 days')) . '.log';
$recent = $dir . '/arya-' . date('Y-m-d', strtotime('-2 days')) . '.log';
file_put_contents($old, "{}\n");
file_put_contents($recent, "{}\n");
$GLOBALS['arya_options']['arya_portal_log_retention_days'] = 14;
Logger::instance()->purge_old_files();
same(false, file_exists($old), 'فایل قدیمی‌تر از بازهٔ نگهداری حذف می‌شود');
same(true, file_exists($recent), 'فایل داخل بازه باقی می‌ماند');

reset_logs();
for ($i = 0; $i < 60; $i++) {
    Logger::instance()->log(Logger::JS_ERROR, 'flood ' . $i);
}
same(Logger::MAX_PER_REQUEST, count(today_entries()), 'سقف لاگ در هر درخواست رعایت می‌شود');

// پاک‌سازی
foreach (glob($base . '/arya-portal-logs/*/*') ?: [] as $f) @unlink($f);

echo "\n";
echo $failed === 0
    ? "\033[32m{$passed} تست موفق، بدون خطا\033[0m\n\n"
    : "\033[31m{$failed} تست ناموفق\033[0m از " . ($passed + $failed) . ":\n  - " . implode("\n  - ", $failures) . "\n\n";

exit($failed === 0 ? 0 : 1);
