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

echo "\n\033[1mورود با نام کاربری: بدون ارور سیستم\033[0m\n";

reset_logs();
$beforeHttp = count($GLOBALS['arya_http_log'] ?? []);
$person = new PersonData('zahrasoltani');
same(null, $person->getPersonBonuses(), 'نام کاربری => بدون فراخوانی، خروجی null');
same(null, $person->getPersonDiscounts(), 'نام کاربری برای discounts هم null');
same([], codes_logged(), 'نام کاربری لاگ WS_HTTP_STATUS نمی‌سازد');
same(null, $person->getLastError(), 'نام کاربری last_error هم نمی‌گذارد');
same($beforeHttp, count($GLOBALS['arya_http_log'] ?? []), 'نام کاربری درخواست HTTP به CRM نمی‌زند');

reset_logs();
$person = new PersonData('aryatehran_sh');
$person->getPersonByPhone('aryatehran_sh');
same([], codes_logged(), 'اکانت ادمین با نام کاربری هم خطا ثبت نمی‌کند');

reset_logs();
arya_queue_response(404, '{"message":"No query results for model [App\\\\Models\\\\User]."}');
$person = new PersonData('09120000000');
$result = $person->getPersonDiscounts();
ok(is_object($result) && isset($result->message), '۴۰۴ کاربرِ ناموجود همچنان بدنه را برمی‌گرداند');
same([], codes_logged(), '۴۰۴ مدل User روی person/* به‌عنوان خطا ثبت نمی‌شود');
same(null, $person->getLastError(), '۴۰۴ کاربر ناموجود last_error هم نمی‌سازد');

reset_logs();
arya_queue_response(404, '{"message":"مورد درخواستی پیدا نشد یا حذف شده است."}');
$person = new PersonData('09120000000');
same(null, $person->getPersonByPhone('09120000000'), '۴۰۴ ترجمه‌شدهٔ CRM => شخص نیست (null)، نه بدنهٔ خطا');
same([], codes_logged(), '۴۰۴ ترجمه‌شده روی person/{id} به‌عنوان خطا ثبت نمی‌شود');

reset_logs();
arya_queue_response(200, '{"data":{"id":12,"phone":"09120000000"}}');
$person = new PersonData('09120000000');
same(12, $person->getPersonByPhone('09120000000')->data->id, 'شخص پیداشده همان پاسخ CRM است');

reset_logs();
arya_queue_response(404, '{"message":"مورد درخواستی پیدا نشد یا حذف شده است."}');
$person = new PersonData('09120000000');
$person->getPersonBonuses();
same([Logger::WS_HTTP_STATUS], codes_logged(), '۴۰۴ ترجمه‌شده روی زیرمسیر person همچنان خطا است');

reset_logs();
arya_queue_response(404, '{"message":"No query results for model [App\\\\Models\\\\Bonus]."}');
$person = new PersonData('09120000000');
$person->getPersonBonuses();
same([Logger::WS_HTTP_STATUS], codes_logged(), '۴۰۴ مدل دیگر همچنان خطا است');

reset_logs();
arya_queue_response(500, '{"message":"boom"}');
$person = new PersonData('09120000000');
$person->getPersonBonuses();
same([Logger::WS_HTTP_STATUS], codes_logged(), '۵۰۰ همچنان خطا است');

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

reset_logs();
arya_queue_response(200, '{"status":true,"data":{"rollcall_id":44}}');
$person = new PersonData('09120000000');
$result = $person->saveAbsenceReason(12, 44, 'illness', 'تب داشتم');
$request = end($GLOBALS['arya_http_log']);
same(true, $result->status, 'ثبت علت غیبت پاسخ API را حفظ می‌کند');
same('POST', $request['method'], 'ثبت علت غیبت با POST ارسال می‌شود');
same('https://crm.test/api/v1/person/09120000000/register/12/rollcall/44/absence-reason', $request['url'], 'مسیر ثبت علت به شخص و ثبت‌نام محدود است');
same('illness', $request['args']['body']['absence_reason'], 'علت غیبت در body ارسال می‌شود');
same('تب داشتم', $request['args']['body']['comments'], 'توضیحات غیبت در body ارسال می‌شود');
same(1, $request['args']['headers']['from_site'], 'هدر اتصال سایت روی درخواست ثبت علت وجود دارد');


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
    'personData' => ['data' => [
        ['meta_key' => 'province', 'meta_value' => 'THR', 'label' => 'تهران', 'option' => null],
        ['meta_key' => 'city_id', 'meta_value' => '1277', 'label' => 'تهران', 'option' => null],
    ]],
    // قرارداد تازه: فهرست کامل استان/شهر/places دیگر همراه فرم نیست.
    'dependency' => ['jobs' => []],
    'schema' => ['fields' => [
        ['key' => 'province', 'type' => 'vue-select', 'geo' => 'state', 'depends_clear' => ['city_id', 'region']],
        ['key' => 'city_id', 'type' => 'vue-select', 'geo' => 'city', 'options_parent' => 'province'],
        ['key' => 'birth_place', 'type' => 'text'],
    ]],
    'translations' => [],
    'fieldReviews' => [],
]));
$person = new PersonData('09120000000');
$form = $person->getPersonDataForm();
same(true, $form['success'], 'فرم بدون فهرست استان و شهر باز می‌شود');
same([], codes_logged(), 'نبودِ places/geo_states/geo_cities خطا یا هشدار نیست');
same('تهران', $form['personData']->data[0]->label, 'نام استان (label) همراه مقدار ذخیره‌شده به فرم می‌رسد');
same('THR', $form['personData']->data[0]->meta_value, 'مقدار استان همان کد می‌ماند');
same('1277', $form['personData']->data[1]->meta_value, 'مقدار شهر همان شناسه می‌ماند');
same('state', $form['schema']['fields'][0]['geo'], 'geo در اسکیما دست‌نخورده می‌ماند');
same('province', $form['schema']['fields'][1]['options_parent'], 'options_parent در اسکیما دست‌نخورده می‌ماند');

echo "\n\033[1mجستجوی استان و شهر (geo) با کش\033[0m\n";

/** آدرس و پارامترهای آخرین درخواست HTTP. */
function last_request_parts() {
    $request = end($GLOBALS['arya_http_log']);
    parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
    return [strtok($request['url'], '?'), $query, $request];
}
function http_count() { return count($GLOBALS['arya_http_log']); }

reset_logs();
$GLOBALS['arya_transients'] = [];
arya_queue_response(200, json_encode(['country' => 'IR', 'data' => [
    ['code' => 'THR', 'name' => 'تهران'],
    ['code' => 'ESF', 'name' => 'اصفهان'],
    ['code' => '', 'name' => 'بدون کد'],
    'خراب',
]]));
$person = new PersonData('09120000000');
$states = $person->getGeoStates('  تهـ ');
same([['code' => 'THR', 'name' => 'تهران'], ['code' => 'ESF', 'name' => 'اصفهان']], $states, 'getGeoStates آرایهٔ ساده برمی‌گرداند و ردیف‌های ناقص را کنار می‌گذارد');
[$path, $query, $request] = last_request_parts();
same('https://crm.test/api/v1/geo/states', $path, 'مسیر geo/states');
same(['search' => 'تهـ', 'limit' => '50'], $query, 'search (بدون فاصلهٔ اضافه) و limit=50');
same('GET', $request['method'], 'با GET');
same('Bearer test-token', $request['args']['headers']['Authorization'], 'با توکن پورتال');
same(1, $request['args']['headers']['from_site'], 'با هدر from_site');
same(1, count(array_filter(array_keys($GLOBALS['arya_transients']), fn ($k) => strpos($k, 'arya_geo_') === 0)), 'پاسخ موفق در transient با پیشوند arya_geo_ ذخیره می‌شود');

$before = http_count();
same($states, $person->getGeoStates('تهـ'), 'تکرار همان جستجو همان نتیجه را می‌دهد');
same($before, http_count(), 'تکرار همان جستجو از کش می‌آید (درخواست HTTP نمی‌زند)');
same(null, $person->getLastError(), 'پاسخ کش‌شده خطای قبلی را نشان نمی‌دهد');

arya_queue_response(200, json_encode(['data' => [['code' => 'KRN', 'name' => 'کرمان']]]));
$person->getGeoStates('كرمان');
$before = http_count();
same([['code' => 'KRN', 'name' => 'کرمان']], $person->getGeoStates('کرمان'), '«ك» عربی و «ک» فارسی یک کلید کش دارند');
same($before, http_count(), '«كرمان» و «کرمان» فقط یک درخواست می‌زنند');
same('کرمان', last_request_parts()[1]['search'], 'متن جستجو با «ک» فارسی به CRM می‌رود');

arya_queue_response(200, json_encode(['data' => [['code' => 'ESF', 'name' => 'اصفهان'], ['code' => 'THR', 'name' => 'تهران']]]));
$person->getGeoStates('نادیده', ['thr', 'ESF', 'THR', 'x"y']);
same(['codes' => 'ESF,THR,XY', 'limit' => '50'], last_request_parts()[1], 'codes یکتا، مرتب و با حروف بزرگ؛ جستجو کنارش نادیده گرفته می‌شود');

reset_logs();
arya_queue_response(500, '{"message":"boom"}');
$person = new PersonData('09120000000');
same([], $person->getGeoStates('خطا'), 'شکست => آرایهٔ خالی');
same(Logger::WS_HTTP_STATUS, $person->getLastError()['code'] ?? null, 'علت شکست از getLastError خوانده می‌شود');
same([Logger::WS_HTTP_STATUS], codes_logged(), 'شکست با همان لایهٔ ws_get لاگ می‌شود');
arya_queue_response(200, json_encode(['data' => [['code' => 'THR', 'name' => 'تهران']]]));
$before = http_count();
same([['code' => 'THR', 'name' => 'تهران']], $person->getGeoStates('خطا'), 'شکست کش نمی‌شود: درخواست بعدی دوباره به CRM می‌رود');
same($before + 1, http_count(), 'بعد از شکست، درخواست تازه زده می‌شود');

reset_logs();
arya_queue_error('cURL error 28: Operation timed out');
same([], $person->getGeoStates('قطع'), 'قطع شبکه => آرایهٔ خالی');
same([Logger::WS_REQUEST_FAILED], codes_logged(), 'قطع شبکه کد WS_REQUEST_FAILED می‌گیرد');

reset_logs();
arya_queue_response(200, '{"unexpected":1}');
same([], $person->getGeoStates('بی‌داده'), 'پاسخ بدون data => آرایهٔ خالی');
same([Logger::WS_MISSING_FIELD], codes_logged(), 'نبودِ data کد WS_MISSING_FIELD می‌گیرد');
same(['data'], today_entries()[0]['context']['missing'], 'لاگ می‌گوید data نبود');
$before = http_count();
arya_queue_response(200, json_encode(['data' => []]));
same([], $person->getGeoStates('بی‌داده'), 'پاسخ بی‌data هم کش نشده بود');
same($before + 1, http_count(), 'پاسخ ناقص کش نمی‌شود');
same(null, $person->getLastError(), 'جستجوی بی‌نتیجه شکست نیست');

reset_logs();
$GLOBALS['arya_transients'] = [];
arya_queue_response(200, json_encode(['data' => [
    ['id' => 1277, 'name' => 'تهران', 'state' => 'THR'],
    ['id' => '1279', 'name' => 'ری', 'state' => 'THR'],
    ['id' => 0, 'name' => 'بی‌شناسه', 'state' => 'THR'],
]]));
$person = new PersonData('09120000000');
$cities = $person->getGeoCities('thr', 'ر');
same([['id' => 1277, 'name' => 'تهران', 'state' => 'THR'], ['id' => 1279, 'name' => 'ری', 'state' => 'THR']], $cities, 'getGeoCities آرایهٔ ساده با شناسهٔ عددی برمی‌گرداند');
[$path, $query] = last_request_parts();
same('https://crm.test/api/v1/geo/cities', $path, 'مسیر geo/cities');
same(['state' => 'THR', 'search' => 'ر', 'limit' => '30'], $query, 'state + search + limit=30');
$before = http_count();
same($cities, $person->getGeoCities('THR', 'ر'), 'جستجوی تکراری شهر از کش می‌آید');
same($before, http_count(), 'جستجوی تکراری شهر درخواست HTTP نمی‌زند');

arya_queue_response(200, json_encode(['data' => [['id' => 1, 'name' => 'الف', 'state' => 'ESF']]]));
$person->getGeoCities('ESF', 'ر');
same('ESF', last_request_parts()[1]['state'], 'همان متن در استان دیگر کلید کش جدا دارد');

arya_queue_response(200, json_encode(['data' => [['id' => 1277, 'name' => 'تهران', 'state' => 'THR'], ['id' => 1279, 'name' => 'ری', 'state' => 'THR']]]));
$person->getGeoCities(null, 'نادیده', [1279, '1277', 'x', 1277, -3]);
same(['ids' => '1277,1279'], last_request_parts()[1], 'ids یکتا و مرتب؛ بدون state و search');

$before = http_count();
same([], $person->getGeoCities('', 'تهر'), 'شهر بدون استان => آرایهٔ خالی');
same([], $person->getGeoCities('"/<>', ''), 'کد استان نامعتبر => آرایهٔ خالی');
same($before, http_count(), 'شهر بدون استان به CRM درخواست نمی‌زند');

$person->getGeoCities('THR', str_repeat('ب', 300));
same(PersonData::GEO_SEARCH_MAX, mb_strlen(last_request_parts()[1]['search']), 'متن جستجو به ' . PersonData::GEO_SEARCH_MAX . ' نویسه محدود است');

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
