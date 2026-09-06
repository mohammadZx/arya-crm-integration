<?php

namespace Arya\Portal;

/**
 * PersonData Class
 * 
 * Handles all interactions with Arya Portal API
 */
class PersonData {
    
    private $portal_url;
    private $portal_path;
    private $api_token;
    public $phone;
    
    /**
     * Constructor
     */
    public function __construct($phone = false) {
        $settings = Settings::instance();
        $this->portal_url = rtrim($settings->get_portal_url(), '/');
        $this->portal_path = $this->portal_url . '/api/v1/';
        $this->api_token = $settings->get_api_token();
        
        if ($phone) {
            $this->phone = $phone;
        }
    }
    
    /**
     * Get request headers
     */
    private function get_headers() {
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_token,
            'Accept' => 'application/json',
            'from_site' => 1,
        ];

        return $headers;
    }

    /* ===============================================================
     |  لایهٔ واحدِ فراخوانی وب‌سرویس
     |
     |  هر متد این کلاس به‌جای wp_remote_get/wp_remote_post، ws_get/ws_post را
     |  صدا می‌زند. خروجیِ این دو *دقیقاً* همان چیزی است که توابع وردپرس
     |  برمی‌گردانند (آرایهٔ پاسخ یا WP_Error)، پس رفتار هیچ فراخوانی عوض
     |  نمی‌شود؛ تنها چیزی که اضافه شده، تشخیص و ثبت علتِ شکست است.
     |
     |  تفکیک علت‌ها عمدی است: «پاسخ نیامد» با «پاسخ آمد ولی ۵۰۰ بود» و با
     |  «پاسخ آمد ولی JSON نبود» سه مشکل کاملاً متفاوت‌اند و تا وقتی هر سه
     |  «خطایی رخ داد» گزارش می‌شدند، عیب‌یابی ناممکن بود.
     ===============================================================*/

    /** @var array|null آخرین خطای وب‌سرویس در این نمونه. */
    private $last_error = null;

    /**
     * آخرین خطای ثبت‌شده: ['code' => .., 'event_id' => .., 'message' => .., 'http_status' => ..]
     * فراخوان‌ها از این برای نشان دادن کد خطا به کاربر استفاده می‌کنند.
     */
    public function getLastError() {
        return $this->last_error;
    }

    public function clearLastError() {
        $this->last_error = null;
    }

    /**
     * GET با لاگ. خروجی: همان خروجی wp_remote_get.
     */
    private function ws_get($url, $args = []) {
        return $this->ws_request('GET', $url, $args);
    }

    /**
     * POST با لاگ. خروجی: همان خروجی wp_remote_post.
     */
    private function ws_post($url, $args = []) {
        return $this->ws_request('POST', $url, $args);
    }

    private function ws_request($method, $url, $args) {
        $this->last_error = null;
        $operation = $this->calling_method();
        $endpoint  = $this->relative_endpoint($url);

        // تنظیمات ناقص را همین‌جا می‌گوییم، ولی جلوی درخواست را نمی‌گیریم تا
        // رفتار قبلی (تلاش و شکست) دست‌نخورده بماند.
        if (empty($this->portal_url) || empty($this->api_token)) {
            $this->record_error(
                Logger::WS_NOT_CONFIGURED,
                'آدرس پورتال یا توکن API تنظیم نشده است.',
                [
                    'operation'    => $operation,
                    'endpoint'     => $endpoint,
                    'has_url'      => !empty($this->portal_url),
                    'has_token'    => !empty($this->api_token),
                ],
                Logger::LEVEL_WARNING
            );
        }

        $response = $method === 'POST' ? wp_remote_post($url, $args) : wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $this->record_error(
                Logger::WS_REQUEST_FAILED,
                'درخواست به وب‌سرویس نرسید: ' . $response->get_error_message(),
                [
                    'operation'  => $operation,
                    'endpoint'   => $endpoint,
                    'method'     => $method,
                    'wp_error'   => $response->get_error_code(),
                    'wp_message' => $response->get_error_message(),
                ]
            );
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            $this->record_error(
                Logger::WS_HTTP_STATUS,
                'وب‌سرویس کد وضعیت ' . $status . ' برگرداند.',
                [
                    'operation'    => $operation,
                    'endpoint'     => $endpoint,
                    'method'       => $method,
                    'http_status'  => $status,
                    'body_preview' => mb_substr($body, 0, 1000),
                ]
            );
            return $response;
        }

        if ($body === '') {
            $this->record_error(
                Logger::WS_EMPTY_BODY,
                'پاسخ وب‌سرویس خالی بود.',
                [
                    'operation'   => $operation,
                    'endpoint'    => $endpoint,
                    'method'      => $method,
                    'http_status' => $status,
                ]
            );
            return $response;
        }

        json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->record_error(
                Logger::WS_INVALID_JSON,
                'پاسخ وب‌سرویس JSON معتبر نبود: ' . json_last_error_msg(),
                [
                    'operation'    => $operation,
                    'endpoint'     => $endpoint,
                    'method'       => $method,
                    'http_status'  => $status,
                    'json_error'   => json_last_error_msg(),
                    'body_preview' => mb_substr($body, 0, 1000),
                ]
            );
        }

        return $response;
    }

    /**
     * ثبت یک کلیدِ غایب در پاسخ CRM.
     *
     * دقیقاً همان چیزی است که عیب‌یابی فرم تکمیل اطلاعات لازم داشت: به‌جای
     * «خطایی رخ داد»، بگوید کدام پارامتر نبود.
     *
     * @param array $required کلیدهایی که باید باشند.
     * @return string[] کلیدهای غایب.
     */
    public function reportMissingFields($payload, array $required, $operation, $endpoint = null) {
        $missing = [];

        foreach ($required as $key) {
            $present = is_array($payload)
                ? (isset($payload[$key]) && $payload[$key] !== '' && $payload[$key] !== [])
                : (is_object($payload) && isset($payload->{$key}) && $payload->{$key} !== '' && $payload->{$key} !== []);

            if (!$present) {
                $missing[] = $key;
            }
        }

        if ($missing) {
            $this->record_error(
                Logger::WS_MISSING_FIELD,
                'کلیدهای موردنیاز در پاسخ وب‌سرویس نبودند: ' . implode('، ', $missing),
                [
                    'operation' => $operation,
                    'endpoint'  => $endpoint ?: $this->relative_endpoint(''),
                    'missing'   => $missing,
                    'received'  => $this->payload_keys($payload),
                ]
            );
        }

        return $missing;
    }

    /** کلیدهای سطح‌اولِ پاسخ؛ برای اینکه در لاگ ببینیم CRM واقعاً چه فرستاده. */
    private function payload_keys($payload) {
        if (is_array($payload)) {
            return array_slice(array_keys($payload), 0, 50);
        }
        if (is_object($payload)) {
            return array_slice(array_keys(get_object_vars($payload)), 0, 50);
        }
        return [];
    }

    /**
     * ثبت خطا در Logger و نگه‌داشتنش به‌عنوان «آخرین خطا».
     */
    private function record_error($code, $message, $context = [], $level = Logger::LEVEL_ERROR) {
        $event_id = '';

        if (class_exists('\Arya\Portal\Logger')) {
            $context['phone'] = $context['phone'] ?? $this->phone;
            $event_id = Logger::instance()->log($code, $message, $context, $level, Logger::SOURCE_PORTAL);
        } else {
            error_log('[arya-portal][' . $code . '] ' . $message);
        }

        // هشدارِ «تنظیم نشده» نباید خطای واقعیِ بعدی را از last_error بیرون کند.
        if ($level === Logger::LEVEL_ERROR || $this->last_error === null) {
            $this->last_error = [
                'code'        => $code,
                'event_id'    => $event_id,
                'message'     => $message,
                'http_status' => $context['http_status'] ?? null,
                'reference'   => Logger::reference($code, $event_id),
            ];
        }

        return $event_id;
    }

    /** نام متدی که ws_get/ws_post را صدا زده؛ برای برچسب‌گذاری لاگ. */
    private function calling_method() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);

        foreach ($trace as $frame) {
            $fn = $frame['function'] ?? '';
            if (in_array($fn, ['calling_method', 'ws_request', 'ws_get', 'ws_post'], true)) {
                continue;
            }
            return $fn ?: 'unknown';
        }

        return 'unknown';
    }

    /** مسیر نسبی سرویس، بدون دامنهٔ پورتال — لاگ خواناتر و کوتاه‌تر می‌شود. */
    private function relative_endpoint($url) {
        $url = (string) $url;
        if ($this->portal_path && strpos($url, $this->portal_path) === 0) {
            return substr($url, strlen($this->portal_path));
        }
        return $url;
    }
    
    /**
     * Insert person
     */
    public function insertPerson($userData) {
        $response = $this->ws_post($this->portal_path . 'person/', [
            'headers' => $this->get_headers(),
            'body' => [
                'phone' => $userData->user_login,
                'name' => $userData->display_name,
                'gender' => 0,
                'acquaintance' => 1,
                'comments' => null
            ],
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person by phone
     */
    public function getPersonByPhone($phone) {
        $this->phone = $phone;
        $response = $this->ws_get($this->portal_path . 'person/' . $phone, [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person registers
     */
    public function getPersonRegisters() {
        $response = $this->ws_get($this->portal_path . 'person/' . $this->phone . '/register', [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }

    /**
     * Get CRM discounts catalog for the logged-in trainee profile.
     */
    public function getPersonDiscounts() {
        $response = $this->ws_get($this->portal_path . 'person/' . $this->phone . '/discounts', [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }

    /**
     * فهرست پورسانت‌های معرف در CRM برای صفحه مارکتینگ سایت.
     */
    public function getPersonBonuses() {
        $response = $this->ws_get($this->portal_path . 'person/' . $this->phone . '/bonuses', [
            'headers' => $this->get_headers(),
            'timeout' => 30,
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }

    /**
     * @return array{0: int, 1: mixed}
     */
    public function claimPersonBonus($bonusId) {
        $response = $this->ws_post($this->portal_path . 'person/' . $this->phone . '/bonuses/' . intval($bonusId) . '/claim-wallet', [
            'headers' => $this->get_headers(),
            'timeout' => 45,
        ]);

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response));

        return [$code, $body];
    }
    
    /**
     * Get person certificates
     */
    public function getPersonCertificates() {
        $response = $this->ws_get($this->portal_path . 'person/' . $this->phone . '/services?as_evidence=1', [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person available certificate to get
     */
    public function getPersonAvailableCertificateToGet() {
        $response = $this->ws_get($this->portal_path . 'person/' . $this->phone . '/available-services', [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get service by ID
     */
    public function getServiceById($id) {
        $response = $this->ws_get($this->portal_path . 'service/' . $id, [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Update person info
     */
    public function updatePersonInfo($data, $files) {
        $url = $this->portal_path . 'person/' . $this->phone . '/data';

        foreach ($files as $key => $file) {
            if (!$file['error']) {
                $data[$key] = new \CURLFile($file['tmp_name'], $file['type'], $file['name']);
            }
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: multipart/form-data',
            'Authorization: Bearer ' . $this->api_token,
            'from_site: 1',
        ]);

        $response = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($response === false) {
            $error = curl_error($ch);
            $error_code = curl_errno($ch);
            curl_close($ch);

            $this->record_error(
                Logger::WS_CURL_ERROR,
                'ارسال اطلاعات کاربر با cURL شکست خورد: ' . $error,
                [
                    'operation'  => 'updatePersonInfo',
                    'endpoint'   => $this->relative_endpoint($url),
                    'curl_errno' => $error_code,
                    'curl_error' => $error,
                    'sent_keys'  => $this->payload_keys($data),
                ]
            );

            return ["cURL Error #$error_code: $error"];
        }

        curl_close($ch);

        $this->inspect_raw_response($response, $http_code, 'updatePersonInfo', $this->relative_endpoint($url), [
            'sent_keys' => $this->payload_keys($data),
        ]);

        return json_decode($response);
    }

    /**
     * بررسی پاسخِ خامِ مسیرهای cURL (که از ws_request عبور نمی‌کنند).
     *
     * همان چهار تفکیکِ ws_request: وضعیت، خالی بودن، JSON نامعتبر و خطای
     * منطقیِ اعلام‌شده در بدنه.
     */
    private function inspect_raw_response($raw, $http_code, $operation, $endpoint, $context = []) {
        $base = array_merge($context, [
            'operation'   => $operation,
            'endpoint'    => $endpoint,
            'http_status' => $http_code,
        ]);

        if ($http_code < 200 || $http_code >= 300) {
            $this->record_error(
                Logger::WS_HTTP_STATUS,
                'وب‌سرویس کد وضعیت ' . $http_code . ' برگرداند.',
                array_merge($base, ['body_preview' => mb_substr((string) $raw, 0, 1000)])
            );
            return;
        }

        if ((string) $raw === '') {
            $this->record_error(Logger::WS_EMPTY_BODY, 'پاسخ وب‌سرویس خالی بود.', $base);
            return;
        }

        $decoded = json_decode((string) $raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->record_error(
                Logger::WS_INVALID_JSON,
                'پاسخ وب‌سرویس JSON معتبر نبود: ' . json_last_error_msg(),
                array_merge($base, [
                    'json_error'   => json_last_error_msg(),
                    'body_preview' => mb_substr((string) $raw, 0, 1000),
                ])
            );
            return;
        }

        // CRM با کد ۲۰۰ ولی alert.type=error جواب رد می‌دهد؛ این هم خطاست.
        if (is_array($decoded) && ($decoded['alert']['type'] ?? '') === 'error') {
            $message = $decoded['alert']['message'] ?? '';
            $this->record_error(
                Logger::WS_API_ERROR,
                'وب‌سرویس درخواست را رد کرد: ' . (is_array($message) ? implode(' | ', array_map('strval', $message)) : (string) $message),
                array_merge($base, [
                    'alert'  => $decoded['alert'],
                    'errors' => $decoded['errors'] ?? null,
                ])
            );
        }
    }
    
    /**
     * Set person service
     */
    public function setPersonService($data) {
        $response = $this->ws_post($this->portal_path . 'person/' . $this->phone . '/services', [
            'headers' => $this->get_headers(),
            'body' => ([
                'service_id' => $data['service_id'],
                'fee_index' => $data['fee_index'],
            ]),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Add user exercises
     */
    public function addUserExercises($exercise_id, $data, $files) {
        $url = $this->portal_path . 'exercise/' . $exercise_id . '/doing';
        $filename = 'file_' . $_POST['exercise_id'] ?? 'file';
        
        if (isset($files[$filename]) && !$files[$filename]['error'] && $files[$filename]['size'] < 15000000) {
            $data['file'] = new \CURLFile($files[$filename]['tmp_name'], $files[$filename]['type'], $files[$filename]['name']);
        }
        
        $data['phone'] = $this->phone;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: multipart/form-data',
            'Authorization: Bearer ' . $this->api_token
        ]);

        $response = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($response === false) {
            $error = curl_error($ch);
            $error_code = curl_errno($ch);
            curl_close($ch);

            $this->record_error(
                Logger::WS_CURL_ERROR,
                'ارسال تمرین با cURL شکست خورد: ' . $error,
                [
                    'operation'   => 'addUserExercises',
                    'endpoint'    => $this->relative_endpoint($url),
                    'exercise_id' => $exercise_id,
                    'curl_errno'  => $error_code,
                    'curl_error'  => $error,
                ]
            );

            return ["cURL Error #$error_code: $error"];
        }

        curl_close($ch);

        $this->inspect_raw_response($response, $http_code, 'addUserExercises', $this->relative_endpoint($url), [
            'exercise_id' => $exercise_id,
        ]);

        return json_decode($response);
    }
    
    /**
     * Get register payments
     */
    public function getRegisterPyametns($regid) {
        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/register/{$regid}/payment", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get register sessions
     */
    public function getRegisterSessions($regid) {
        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/register/{$regid}/session", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get register headlines
     */
    public function getRegisterHeadlines($regid) {
        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/register/{$regid}/headlines", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get register exercises
     */
    public function getRegisterExercises($regid) {
        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/register/{$regid}/exercises", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get register rollcalls
     */
    public function getRegisterRollcalls($regid) {
        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/register/{$regid}/rollcall", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person register by ID
     */
    public function getPersonRegisterById($regid) {
        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/register/{$regid}", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get register by ID
     */
    public function getRegisterById($regid) {
        $response = $this->ws_get("{$this->portal_path}register/{$regid}/with-dependencies", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get service register by ID
     */
    public function getServiceRegisterById($regid) {
        $response = $this->ws_get("{$this->portal_path}service-register/{$regid}", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Set payment remote
     */
    public function setPaymentRemote($regid, $price, $orderId, $extra_data) {
        $response = $this->ws_post("{$this->portal_path}remote-pay-payment", [
            'body' => ([
                'register_id' => $regid,
                'price' => $price,
                'order_id' => $orderId,
                'meta' => $extra_data
            ]),
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person data
     */
    public function getPersonData() {
        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/data", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get info dependency
     */
    public function getInfoDependency() {
        $response = $this->ws_get("{$this->portal_path}option/dependency", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }

    /**
     * Aggregate everything the profile form needs in a single request.
     *
     * Calls the CRM `person/{id}/data/form` endpoint only. No local/bundled
     * schema fallback — if the web service is unavailable, returns success=false.
     *
     * @return array{ success: bool, error?: string, person?: mixed, personData?: mixed, dependency?: mixed, schema?: array, translations?: array }
     */
    public function getPersonDataForm($phone = null) {
        $phone = $phone ?: $this->phone;
        $this->phone = $phone;

        $endpoint = 'person/' . $phone . '/data/form';
        $error_message = 'خطایی پیش آمده. در حال بررسی هستیم. لطفا چند ساعت دیگر مجدد بازدید نمایید.';

        if (empty($this->portal_url) || empty($this->api_token) || empty($phone)) {
            $this->record_error(
                Logger::USERINFO_NOT_CONFIGURED,
                'فرم اطلاعات بدون آدرس پورتال/توکن/شماره قابل دریافت نیست.',
                [
                    'operation' => 'getPersonDataForm',
                    'endpoint'  => $endpoint,
                    'has_url'   => !empty($this->portal_url),
                    'has_token' => !empty($this->api_token),
                    'has_phone' => !empty($phone),
                ]
            );
            return $this->formFailure($error_message);
        }

        $response = $this->ws_get($this->portal_path . $endpoint, [
            'timeout' => 20,
            'headers' => $this->get_headers(),
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            // ws_get کد دقیق (WS_REQUEST_FAILED یا WS_HTTP_STATUS) را ثبت کرده است.
            return $this->formFailure($error_message);
        }

        $bundle = json_decode(wp_remote_retrieve_body($response));

        if (!is_object($bundle)) {
            $this->record_error(
                Logger::USERINFO_INVALID_SCHEMA,
                'پاسخ فرم اطلاعات یک شیء JSON نبود.',
                [
                    'operation'     => 'getPersonDataForm',
                    'endpoint'      => $endpoint,
                    'received_type' => gettype($bundle),
                ]
            );
            return $this->formFailure($error_message);
        }

        // اینجا همان نقطه‌ای است که «کدام پارامتر نبود» را مشخص می‌کند.
        $missing = $this->reportMissingFields(
            $bundle,
            ['person', 'personData', 'dependency', 'schema'],
            'getPersonDataForm',
            $endpoint
        );

        if ($missing) {
            return $this->formFailure($error_message, Logger::USERINFO_MISSING_FIELD);
        }

        $schema = json_decode(json_encode($bundle->schema), true);
        if (!is_array($schema) || empty($schema['fields'])) {
            $this->record_error(
                Logger::USERINFO_INVALID_SCHEMA,
                'اسکیمای فرم اطلاعات فیلدی نداشت.',
                [
                    'operation'    => 'getPersonDataForm',
                    'endpoint'     => $endpoint,
                    'schema_type'  => gettype($schema),
                    'schema_keys'  => is_array($schema) ? array_keys($schema) : [],
                ]
            );
            return $this->formFailure($error_message);
        }

        // بخش‌های اختیاریِ خالی مانع نمایش فرم نیستند، ولی باید دیده شوند.
        foreach (['translations', 'fieldReviews'] as $optional) {
            if (!isset($bundle->{$optional})) {
                $this->record_error(
                    Logger::USERINFO_EMPTY_SECTION,
                    'بخش «' . $optional . '» در پاسخ فرم اطلاعات نبود.',
                    ['operation' => 'getPersonDataForm', 'endpoint' => $endpoint, 'section' => $optional],
                    Logger::LEVEL_WARNING
                );
            }
        }

        $this->clearLastError();

        return [
            'success'      => true,
            'person'       => $bundle->person,
            'personData'   => $bundle->personData,
            'dependency'   => $bundle->dependency,
            'schema'       => $schema,
            'translations' => json_decode(json_encode(isset($bundle->translations) ? $bundle->translations : new \stdClass()), true) ?: [],
            'fieldReviews' => json_decode(json_encode(isset($bundle->fieldReviews) ? $bundle->fieldReviews : new \stdClass()), true) ?: [],
        ];
    }
    
    /**
     * پاسخ شکستِ فرم اطلاعات، همراه با کد خطا و شناسهٔ رخداد.
     *
     * کلیدهای success/error دست‌نخورده‌اند تا فراخوان‌های قدیمی نشکنند؛
     * code/event_id/reference افزوده شده‌اند تا کاربر کدی برای پیگیری ببیند.
     */
    private function formFailure($message, $fallback_code = null) {
        $error = $this->getLastError();
        $code = $error['code'] ?? $fallback_code ?? Logger::USERINFO_WS_FAILED;
        $event_id = $error['event_id'] ?? '';

        return [
            'success'   => false,
            'error'     => $message,
            'code'      => $code,
            'event_id'  => $event_id,
            'reference' => Logger::reference($code, $event_id),
        ];
    }

    /**
     * Get exams
     */
    public function get_exams($userId = null, $categories = null) {
        $url = "{$this->portal_path}exam";
        $params = [];
        
        if ($userId) {
            $params['user_id'] = $userId;
        }
        if ($categories) {
            $params['categories'] = $categories;
        }
        
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        
        $response = $this->ws_get($url, [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get exam
     */
    public function get_exam($id) {
        $response = $this->ws_get("{$this->portal_path}exam/{$id}", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get user exams
     */
    public function get_user_exams() {
        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/exam", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get user exam
     */
    public function get_user_exam($id) {
        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/exam/{$id}", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get quiz
     */
    public function get_quiz($id) {
        $response = $this->ws_get("{$this->portal_path}exam/{$id}/quiz", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Check user access to exam
     */
    public function checkUserAccessToExam($examId) {
        if (!$examId) {
            return false;
        }

        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/exam/{$examId}/check-access", [
            'headers' => $this->get_headers(),
        ]);
        
        $status = json_decode(wp_remote_retrieve_body($response));
        
        if (!isset($status->status) || !$status->status) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Save user exam
     */
    public function saveUserExam($data) {
        $response = $this->ws_post("{$this->portal_path}user-exam", [
            'body' => ([
                'user_id' => $data['user_id']->id,
                'report' => $data['report'],
                'insideType' => 'insert'
            ]),
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Add user exam
     */
    public function add_user_exam($id) {
        $response = $this->ws_get("{$this->portal_path}person/{$this->phone}/exam/{$id}/add", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Set exam payment remote
     */
    public function setExamPaymentRemote($examId, $price, $orderId) {
        $response = $this->ws_post("{$this->portal_path}remote-pay-exam", [
            'body' => ([
                'user_id' => $this->phone,
                'exam_id' => $examId,
                'price' => $price,
                'order_id' => $orderId
            ]),
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Inquiry certificate
     */
    public function inquiry_certificate($ncode, $code, $lang = 'fa') {
        $response = $this->ws_get("{$this->portal_path}person/{$ncode}/register/{$code}/inquiry/?lang=" . $lang, [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Inquiry list
     */
    public function inquiry_list($ncode) {
        $response = $this->ws_get("{$this->portal_path}service-register/inquiry/{$this->phone}/{$ncode}", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get survey question
     */
    public function get_survey_question($data) {
        $response = $this->ws_post("{$this->portal_path}survey/get-questions", [
            'headers' => $this->get_headers(),
            'body' => ([
                'phone' => $data['phone'],
                'course_code' => $data['course_code'],
            ])
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Send survey
     */
    public function send_survey($data) {
        $response = $this->ws_post("{$this->portal_path}survey", [
            'headers' => $this->get_headers(),
            'body' => ($data)
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Class course videos
     */
    public function class_course_videos($data) {
        $response = $this->ws_post("{$this->portal_path}class-course/get-videos", [
            'headers' => $this->get_headers(),
            'body' => ($data)
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Send complaint
     */
    public function send_complaint($phone, $course_code, $content, $for) {
        $response = $this->ws_post("{$this->portal_path}complaint", [
            'headers' => $this->get_headers(),
            'body' => ([
                'phone' => $phone,
                'course_code' => $course_code,
                'content' => $content,
                'for' => $for
            ])
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Force register
     */
    public function forceRegister($registerData) {
        $response = $this->ws_post("{$this->portal_path}remote-force-register", [
            'headers' => $this->get_headers(),
            'body' => ($registerData)
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Force request
     */
    public function forceRequest($registerData) {
        $response = $this->ws_post("{$this->portal_path}remote-force-request", [
            'headers' => $this->get_headers(),
            'body' => ($registerData)
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person dashboard alerts
     * API: GET person/{phone|id}/get-alert
     * Response: { data: { online_class, surveys, exercises, debt, info_complete } }
     */
    public function getPersonAlert($phoneOrId = null) {
        $id = $phoneOrId ?: $this->phone;
        $endpoint = 'person/' . $id . '/get-alert';

        if (empty($id)) {
            $this->record_error(
                Logger::ALERT_NO_PHONE,
                'شناسه/شماره‌ای برای دریافت هشدارها وجود ندارد.',
                ['operation' => 'getPersonAlert', 'endpoint' => $endpoint]
            );
            return null;
        }

        $response = $this->ws_get($this->portal_path . $endpoint, [
            'headers' => $this->get_headers(),
        ]);

        // ws_get علت دقیق را ثبت کرده؛ اینجا فقط قرارداد قبلی (null) حفظ می‌شود.
        $body = wp_remote_retrieve_body($response);
        if (empty($body) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $decoded = json_decode($body);

        if (!is_object($decoded)) {
            $this->record_error(
                Logger::ALERT_INVALID_DATA,
                'پاسخ هشدارها یک شیء JSON نبود.',
                [
                    'operation'    => 'getPersonAlert',
                    'endpoint'     => $endpoint,
                    'received_type' => gettype($decoded),
                    'body_preview' => mb_substr($body, 0, 500),
                ]
            );
            return $decoded;
        }

        if (!isset($decoded->data)) {
            $this->record_error(
                Logger::ALERT_MISSING_DATA,
                'پاسخ هشدارها کلید data نداشت.',
                [
                    'operation' => 'getPersonAlert',
                    'endpoint'  => $endpoint,
                    'received'  => $this->payload_keys($decoded),
                ]
            );
        }

        return $decoded;
    }
    
    /**
     * Has register before
     */
    public function hasRegisterBefore($phone = false) {
        $phone = $phone ? $phone : $this->phone;
        $response = $this->ws_get("{$this->portal_path}has-register/" . $phone, [
            'headers' => $this->get_headers()
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }

    /* ===========================================================
     |  Cooperation Request (درخواست همکاری)
     |  getCooperationOptions(): از get-utilities با Bearer
     |  submitCooperationRequest(): POST cooperation-request با Bearer (همان توکن API پورتال)
     ===========================================================*/

    /**
     * @param array|null $body
     */
    private function cooperation_api_success($http_code, $body) {
        if ($http_code < 200 || $http_code >= 300) {
            return false;
        }
        if (!is_array($body)) {
            return false;
        }
        if (!empty($body['alert']) && ($body['alert']['type'] ?? '') === 'error') {
            return false;
        }
        return isset($body['data']);
    }

    /**
     * @param array|null $body
     */
    private function cooperation_api_error_message($body) {
        if (!is_array($body)) {
            return 'پاسخ نامعتبر از سرور';
        }
        if (!empty($body['alert']['message'])) {
            $m = $body['alert']['message'];
            return is_array($m) ? implode(' ', array_map('strval', $m)) : (string) $m;
        }
        if (!empty($body['message'])) {
            return (string) $body['message'];
        }
        return 'خطا در ثبت درخواست';
    }

    /**
     * Get cooperation form default options (request_types, work_types, fields).
     * مهارت‌ها دیگر از get-utilities بارگذاری نمی‌شوند؛ از جستجوی Option با option_key همکاری استفاده کنید.
     *
     * @param bool $force Bypass cache.
     * @return array
     */
    public function getCooperationOptions($force = false) {
        $cache_key = 'arya_portal_cooperation_options_v2';

        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $defaults = [
            'request_types' => ['کارآموز عادی', 'کارآموزی با حقوق', 'استخدام'],
            'work_types'    => ['ثابت', 'شیفتی', 'دورکار', 'پروژه‌ای'],
            'fields'        => ['فنی', 'حسابداری', 'استاد', 'بازاریابی', 'پشتیبانی'],
            'skills'        => [],
        ];

        if (empty($this->api_token) || empty($this->portal_url)) {
            return $defaults;
        }

        $response = $this->ws_get($this->portal_path . 'get-utilities', [
            'timeout' => 15,
            'headers' => $this->get_headers(),
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return $defaults;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

        $extract = function ($arr) {
            if (!is_array($arr)) return [];
            $out = [];
            foreach ($arr as $item) {
                if (is_array($item)) {
                    $val = $item['option_value'] ?? $item['label'] ?? $item['value'] ?? null;
                } else {
                    $val = $item;
                }
                if ($val !== null && $val !== '') $out[] = (string) $val;
            }
            return array_values(array_unique($out));
        };

        $options = [
            'request_types' => $extract($data['cooperation_request_types'] ?? []) ?: $defaults['request_types'],
            'work_types'    => $extract($data['cooperation_work_types']    ?? []) ?: $defaults['work_types'],
            'fields'        => $extract($data['cooperation_fields']        ?? []) ?: $defaults['fields'],
            'skills'        => [],
        ];

        set_transient($cache_key, $options, HOUR_IN_SECONDS);
        return $options;
    }

    /**
     * جستجوی مهارت همکاری در CRM از مسیر عمومی search/Option/option_value با filter_option_key.
     *
     * @return array{ success: bool, skills?: string[], message?: string }
     */
    public function searchCooperationSkills($query) {
        $query = trim((string) $query);
        if ($query === '' || mb_strlen($query) < 2) {
            return ['success' => true, 'skills' => []];
        }
        if (empty($this->api_token) || empty($this->portal_url)) {
            return ['success' => false, 'skills' => [], 'message' => 'پورتال یا توکن تنظیم نشده است.'];
        }

        /*
         * مسیر اختصاصی search/cooperation-skills در برخی نسخه‌های CRM نیست.
         * از همان جستجوی عمومی Option با filter_option_key استفاده می‌کنیم (همان منطق Vue/dynamicSearch).
         */
        $path_search = rawurlencode($query);
        $url = $this->portal_path . 'search/Option/option_value/' . $path_search . '?' . http_build_query([
            'filter_no_label'   => '1',
            'filter_option_key' => 'cooperation_skill',
        ]);

        $response = $this->ws_get($url, [
            'timeout' => 15,
            'headers' => $this->get_headers(),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'skills' => [], 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            return ['success' => false, 'skills' => [], 'message' => 'خطا در جستجوی مهارت‌ها', 'data' => $raw];
        }

        $skills = [];
        foreach ($decoded as $row) {
            if (is_array($row) && !empty($row['option_value'])) {
                $skills[] = (string) $row['option_value'];
            }
        }
        $skills = array_values(array_unique($skills));

        return ['success' => true, 'skills' => $skills];
    }

    /**
     * Clear cached cooperation options.
     */
    public function flushCooperationOptions() {
        delete_transient('arya_portal_cooperation_options');
        delete_transient('arya_portal_cooperation_options_v2');
    }

    /**
     * ثبت درخواست همکاری در CRM.
     * مسیر: POST /api/v1/cooperation-request با همان احراز هویت Bearer سایر APIها (توکن پورتال).
     * کاربر مرتبط با API باید در CRM مجوز add_cooperation داشته باشد.
     *
     * @param array      $data شامل phone، name، insideType=insert، فیلدهای فرم و …
     * @param array|null $file آرایهٔ $_FILES رزومه در صورت وجود
     * @return array { success, status, message, body }
     */
    public function submitCooperationRequest($data, $file = null) {
        if (empty($this->portal_url)) {
            return [
                'success' => false,
                'status'  => 0,
                'message' => 'آدرس پورتال تنظیم نشده است.',
                'body'    => null,
            ];
        }
        if (empty($this->api_token)) {
            return [
                'success' => false,
                'status'  => 0,
                'message' => 'توکن API پورتال در تنظیمات ووکامرس → پورتال آریا وارد نشده است.',
                'body'    => null,
            ];
        }

        $url = $this->portal_path . 'cooperation-request';

        if (empty($data['insideType'])) {
            $data['insideType'] = 'insert';
        }
        if (empty($data['source'])) {
            $data['source'] = 'wordpress_' . parse_url(home_url(), PHP_URL_HOST);
        }

        if (isset($data['skills']) && is_string($data['skills'])) {
            $skills = preg_split('/[,،\n]+/u', $data['skills']);
            $data['skills'] = array_values(array_filter(array_map('trim', $skills)));
        }

        $auth_headers_curl = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->api_token,
        ];

        if (is_array($file) && !empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
            $postfields = [];
            foreach ($data as $key => $value) {
                if ($value === null || $value === '' || $key === 'resume') {
                    continue;
                }
                /* آرایهٔ skills را برای multipart/cURL به صورت skills[0], skills[1], … می‌فرستیم؛
                   در غیر این صورت libcurl اغلب فقط یکی را می‌فرستد یا Laravel آرایه را درست نمی‌گیرد. */
                if ($key === 'skills' && is_array($value)) {
                    $n = 0;
                    foreach (array_values($value) as $skill) {
                        $skill = is_string($skill) ? trim($skill) : '';
                        if ($skill === '') {
                            continue;
                        }
                        $postfields['skills[' . $n++ . ']'] = $skill;
                    }
                    continue;
                }
                $postfields[$key] = $value;
            }
            $postfields['resume'] = new \CURLFile(
                $file['tmp_name'],
                !empty($file['type']) ? $file['type'] : 'application/octet-stream',
                !empty($file['name']) ? $file['name'] : 'resume.bin'
            );

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $auth_headers_curl);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                $this->record_error(
                    Logger::WS_CURL_ERROR,
                    'ارسال درخواست همکاری با cURL شکست خورد: ' . $err,
                    [
                        'operation'  => 'submitCooperationRequest',
                        'endpoint'   => $this->relative_endpoint($url),
                        'curl_error' => $err,
                    ]
                );
                return ['success' => false, 'status' => 0, 'message' => $err ?: 'cURL error', 'body' => null];
            }

            $this->inspect_raw_response($raw, $code, 'submitCooperationRequest', $this->relative_endpoint($url));

            $decoded = json_decode($raw, true);
            $ok = $this->cooperation_api_success($code, $decoded);
            return [
                'success' => $ok,
                'status'  => $code,
                'message' => $ok ? '' : $this->cooperation_api_error_message($decoded),
                'body'    => $decoded,
            ];
        }

        $headers = array_merge($this->get_headers(), ['Content-Type' => 'application/json']);

        $response = $this->ws_post($url, [
            'timeout' => 25,
            'headers' => $headers,
            'body'    => wp_json_encode($data),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'status' => 0, 'message' => $response->get_error_message(), 'body' => null];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $ok = $this->cooperation_api_success($code, $decoded);

        return [
            'success' => $ok,
            'status'  => $code,
            'message' => $ok ? '' : $this->cooperation_api_error_message($decoded),
            'body'    => $decoded,
        ];
    }

    /* ===============================================================
     |  تالار گفتگو (Rocket.Chat)
     |
     |  سایت هرگز مستقیم با راکت‌چت حرف نمی‌زند. همه چیز از CRM رد می‌شود تا
     |  throttle، circuit breaker، کش هویت و لاگ‌ها یک جا جمع باشند و توکن
     |  ادمین راکت‌چت هیچ‌وقت روی سایت ننشیند.
     ===============================================================*/

    /** لینک ورود مستقیم به تالار (یا به یک گروه مشخص). */
    public function getForumEntry($group = '') {
        $url = $this->portal_path . 'person/' . $this->phone . '/forum/entry';

        if ($group !== '') {
            $url = add_query_arg('group', rawurlencode($group), $url);
        }

        $response = $this->ws_get($url, ['headers' => $this->get_headers()]);

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /** فهرست تیکت‌های کاربر. */
    public function getForumTickets() {
        $response = $this->ws_get($this->portal_path . 'person/' . $this->phone . '/forum/tickets', [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /** پیام‌های یک تیکت. */
    public function getForumTicket($room_id) {
        $response = $this->ws_get($this->portal_path . 'person/' . $this->phone . '/forum/tickets/' . rawurlencode($room_id), [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /** دپارتمان‌ها برای فرم تیکت جدید. */
    public function getForumDepartments() {
        $response = $this->ws_get($this->portal_path . 'person/' . $this->phone . '/forum/departments', [
            'headers' => $this->get_headers(),
        ]);

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        return isset($decoded['data']) ? $decoded['data'] : [];
    }

    /** ثبت تیکت جدید (با پیوست اختیاری). */
    public function createForumTicket(array $fields, $file = null) {
        return $this->forum_multipart(
            $this->portal_path . 'person/' . $this->phone . '/forum/tickets',
            $fields,
            $file
        );
    }

    /** پاسخ کاربر داخل تیکت خودش (با پیوست اختیاری). */
    public function replyForumTicket($room_id, array $fields, $file = null) {
        return $this->forum_multipart(
            $this->portal_path . 'person/' . $this->phone . '/forum/tickets/' . rawurlencode($room_id) . '/reply',
            $fields,
            $file
        );
    }

    /**
     * ارسال multipart به CRM. برای مسیرهایی که ممکن است فایل داشته باشند.
     *
     * @param  array       $fields فیلدهای متنی
     * @param  array|null  $file   یک آیتم از $_FILES
     * @return array
     */
    private function forum_multipart($url, array $fields, $file = null) {
        if (!empty($file['tmp_name']) && empty($file['error']) && is_uploaded_file($file['tmp_name'])) {
            $fields['file'] = new \CURLFile($file['tmp_name'], $file['type'], $file['name']);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: multipart/form-data',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->api_token,
            'from_site: 1',
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);

            $this->record_error(
                Logger::WS_CURL_ERROR,
                'ارسال تیکت تالار با cURL شکست خورد: ' . $error,
                ['endpoint' => $this->relative_endpoint($url), 'curl_error' => $error]
            );

            return ['success' => false, 'message' => 'ارتباط با سرور برقرار نشد.'];
        }

        curl_close($ch);
        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $this->record_error(
                Logger::WS_HTTP_STATUS,
                'وب‌سرویس تالار کد وضعیت ' . $status . ' برگرداند.',
                ['endpoint' => $this->relative_endpoint($url), 'http_status' => $status]
            );
        }

        return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'پاسخ نامعتبر از سرور.'];
    }
}
