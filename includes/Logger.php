<?php

namespace Arya\Portal;

/**
 * Logger — محل تجمیع خطاهای هر دو افزونه (portal-integration و training).
 *
 * سه قاعده‌ای که این کلاس بر آن‌ها بنا شده است:
 *
 *  ۱) هیچ خطایی بی‌کد نیست. هر رخداد یک `code` ثابت دارد (ثابت‌های همین کلاس)
 *     تا هم به کاربر نشان داده شود و هم مستقیم در لاگ جست‌وجو شود.
 *
 *  ۲) لاگ اول محلی نوشته می‌شود، بعد فرستاده می‌شود. اگر ترتیب برعکس بود،
 *     دقیقاً در لحظه‌ای که CRM در دسترس نیست — یعنی همان لحظه‌ای که بیشترین
 *     خطا تولید می‌شود — لاگ‌ها را از دست می‌دادیم.
 *
 *  ۳) لاگ کردن هرگز نباید جریان کاربر را بشکند یا کند کند: نوشتن روی فایل
 *     روزانه است (بدون دیتابیس)، و ارسال به CRM در `shutdown` انجام می‌شود،
 *     یعنی بعد از اینکه پاسخ کاربر ساخته شد.
 *
 * فایل‌ها روزانه‌اند (`arya-YYYY-MM-DD.log`، هر خط یک JSON) تا حجمشان کنترل
 * شود و پاک‌سازی قدیمی‌ها فقط حذف چند فایل باشد.
 */
class Logger {

    /* ---------------------------------------------------------------
     |  سطح‌ها
     ---------------------------------------------------------------- */
    const LEVEL_ERROR   = 'error';
    const LEVEL_WARNING = 'warning';
    const LEVEL_INFO    = 'info';

    /* ---------------------------------------------------------------
     |  منابع
     ---------------------------------------------------------------- */
    const SOURCE_PORTAL   = 'arya-portal-integration';
    const SOURCE_TRAINING = 'arya-training';
    const SOURCE_FRONTEND = 'frontend';

    /* ---------------------------------------------------------------
     |  کدهای وب‌سرویس (لایهٔ PersonData)
     ---------------------------------------------------------------- */
    /** آدرس پورتال یا توکن API در تنظیمات خالی است. */
    const WS_NOT_CONFIGURED = 'WS_NOT_CONFIGURED';
    /** درخواست اصلاً به مقصد نرسید: DNS، تایم‌اوت، TLS، … */
    const WS_REQUEST_FAILED = 'WS_REQUEST_FAILED';
    /** پاسخ آمد ولی کد وضعیت خارج از 2xx بود. */
    const WS_HTTP_STATUS    = 'WS_HTTP_STATUS';
    /** بدنهٔ پاسخ خالی بود. */
    const WS_EMPTY_BODY     = 'WS_EMPTY_BODY';
    /** بدنه JSON معتبر نبود. */
    const WS_INVALID_JSON   = 'WS_INVALID_JSON';
    /** JSON معتبر بود ولی ساختارش آن چیزی نبود که انتظار می‌رفت. */
    const WS_UNEXPECTED_SHAPE = 'WS_UNEXPECTED_SHAPE';
    /** یک یا چند کلیدِ لازم در پاسخ CRM نبود (کلیدها در context.missing). */
    const WS_MISSING_FIELD  = 'WS_MISSING_FIELD';
    /** مسیرهای cURL مستقیم (آپلود فایل) شکست خوردند. */
    const WS_CURL_ERROR     = 'WS_CURL_ERROR';
    /** CRM در بدنهٔ پاسخ صریحاً alert=error برگرداند. */
    const WS_API_ERROR      = 'WS_API_ERROR';

    /* ---------------------------------------------------------------
     |  کدهای هشدارهای داشبورد (personAlert)
     ---------------------------------------------------------------- */
    /** افزونهٔ پورتال فعال نیست یا کلاس PersonData بارگذاری نشده. */
    const ALERT_PORTAL_MISSING  = 'ALERT_PORTAL_MISSING';
    /** فراخوانی وب‌سرویس get-alert شکست خورد (جزئیات در لاگ WS_* هم‌زمان). */
    const ALERT_WS_FAILED       = 'ALERT_WS_FAILED';
    /** پاسخ آمد ولی کلید data نداشت. */
    const ALERT_MISSING_DATA    = 'ALERT_MISSING_DATA';
    /** data وجود داشت ولی از نوع مورد انتظار نبود. */
    const ALERT_INVALID_DATA    = 'ALERT_INVALID_DATA';
    /** خطای غیرمنتظره هنگام ساختن هشدارها از داده‌ی خام. */
    const ALERT_PROCESS_FAILED  = 'ALERT_PROCESS_FAILED';
    /** کاربر لاگین است ولی شماره‌ای برای پرس‌وجو ندارد. */
    const ALERT_NO_PHONE        = 'ALERT_NO_PHONE';

    /* ---------------------------------------------------------------
     |  کدهای تکمیل اطلاعات (userinfo)
     ---------------------------------------------------------------- */
    const USERINFO_NOT_CONFIGURED   = 'USERINFO_NOT_CONFIGURED';
    const USERINFO_WS_FAILED        = 'USERINFO_WS_FAILED';
    const USERINFO_MISSING_FIELD    = 'USERINFO_MISSING_FIELD';
    const USERINFO_INVALID_SCHEMA   = 'USERINFO_INVALID_SCHEMA';
    const USERINFO_EMPTY_SECTION    = 'USERINFO_EMPTY_SECTION';
    const USERINFO_UPDATE_FAILED    = 'USERINFO_UPDATE_FAILED';
    const USERINFO_UPDATE_REJECTED  = 'USERINFO_UPDATE_REJECTED';

    /* ---------------------------------------------------------------
     |  کدهای تالار گفتگو (Rocket.Chat)
     ---------------------------------------------------------------- */
    const RC_REQUEST_FAILED = 'RC_REQUEST_FAILED';
    const RC_HTTP_STATUS    = 'RC_HTTP_STATUS';
    const RC_CIRCUIT_OPEN   = 'RC_CIRCUIT_OPEN';
    const RC_THROTTLED      = 'RC_THROTTLED';
    const RC_INVITE_FAILED  = 'RC_INVITE_FAILED';
    const RC_TOKEN_FAILED   = 'RC_TOKEN_FAILED';

    /* ---------------------------------------------------------------
     |  کدهای فرانت‌اند
     ---------------------------------------------------------------- */
    const JS_ERROR              = 'JS_ERROR';
    const JS_PROMISE_REJECTION  = 'JS_PROMISE_REJECTION';
    const JS_REQUEST_FAILED     = 'JS_REQUEST_FAILED';

    /** خودِ ارسال لاگ شکست خورد. هرگز فرستاده نمی‌شود؛ فقط محلی می‌ماند. */
    const LOG_SHIP_FAILED = 'LOG_SHIP_FAILED';

    /** پیام فارسی هر کد، برای نمایش در صفحهٔ مدیریت. */
    const CODE_LABELS = [
        self::WS_NOT_CONFIGURED   => 'آدرس پورتال یا توکن API تنظیم نشده است',
        self::WS_REQUEST_FAILED   => 'درخواست به وب‌سرویس نرسید (شبکه/تایم‌اوت)',
        self::WS_HTTP_STATUS      => 'وب‌سرویس کد وضعیت خطا برگرداند',
        self::WS_EMPTY_BODY       => 'پاسخ وب‌سرویس خالی بود',
        self::WS_INVALID_JSON     => 'پاسخ وب‌سرویس JSON معتبر نبود',
        self::WS_UNEXPECTED_SHAPE => 'ساختار پاسخ وب‌سرویس غیرمنتظره بود',
        self::WS_MISSING_FIELD    => 'کلید موردنیاز در پاسخ وب‌سرویس نبود',
        self::WS_CURL_ERROR       => 'خطای cURL در ارسال فایل به وب‌سرویس',
        self::WS_API_ERROR        => 'وب‌سرویس خطای منطقی برگرداند',
        self::ALERT_PORTAL_MISSING => 'افزونهٔ پورتال در دسترس نیست',
        self::ALERT_WS_FAILED     => 'دریافت هشدارهای داشبورد از وب‌سرویس شکست خورد',
        self::ALERT_MISSING_DATA  => 'پاسخ هشدارها کلید data نداشت',
        self::ALERT_INVALID_DATA  => 'ساختار داده‌های هشدار نامعتبر بود',
        self::ALERT_PROCESS_FAILED => 'خطا در پردازش داده‌های هشدار',
        self::ALERT_NO_PHONE      => 'شماره موبایل کاربر برای دریافت هشدار موجود نیست',
        self::USERINFO_NOT_CONFIGURED => 'تنظیمات پورتال برای فرم اطلاعات ناقص است',
        self::USERINFO_WS_FAILED  => 'دریافت فرم اطلاعات از وب‌سرویس شکست خورد',
        self::USERINFO_MISSING_FIELD => 'پارامتر موردنیاز فرم در پاسخ CRM نبود',
        self::USERINFO_INVALID_SCHEMA => 'ساختار فیلدهای فرم اطلاعات نامعتبر بود',
        self::USERINFO_EMPTY_SECTION => 'بخشی از داده‌های فرم خالی برگشت',
        self::USERINFO_UPDATE_FAILED => 'ثبت اطلاعات کاربر در CRM شکست خورد',
        self::USERINFO_UPDATE_REJECTED => 'CRM اطلاعات ارسالی را رد کرد',
        self::RC_REQUEST_FAILED   => 'درخواست به سرور تالار گفتگو نرسید (شبکه/تایم‌اوت)',
        self::RC_HTTP_STATUS      => 'سرور تالار گفتگو کد وضعیت خطا برگرداند',
        self::RC_CIRCUIT_OPEN     => 'ارتباط با تالار گفتگو به دلیل خطاهای پیاپی موقتاً قطع شد',
        self::RC_THROTTLED        => 'درخواست تالار گفتگو به دلیل محدودیت نرخ ارسال نشد',
        self::RC_INVITE_FAILED    => 'عضویت کاربر در گروه تالار گفتگو انجام نشد',
        self::RC_TOKEN_FAILED     => 'ساخت توکن ورود تالار گفتگو شکست خورد',
        self::JS_ERROR            => 'خطای جاوااسکریپت در مرورگر کاربر',
        self::JS_PROMISE_REJECTION => 'Promise رد شده در مرورگر کاربر',
        self::JS_REQUEST_FAILED   => 'درخواست AJAX در مرورگر کاربر شکست خورد',
        self::LOG_SHIP_FAILED     => 'ارسال لاگ‌ها به CRM شکست خورد',
    ];

    /** سقف لاگ در هر درخواست؛ حلقهٔ خطا نباید دیسک را پر کند. */
    const MAX_PER_REQUEST = 50;

    /** سقف تعداد لاگ در هر بستهٔ ارسالی به CRM (هم‌راستا با سقف کنترلر CRM). */
    const SHIP_BATCH = 50;

    private static $instance = null;

    /** @var array لاگ‌های همین درخواست که هنوز به CRM نرفته‌اند. */
    private $pending = [];

    /** @var int شمارندهٔ لاگ‌های همین درخواست. */
    private $written = 0;

    /** @var bool محافظ بازگشت: وقتی در حال ارسال لاگیم، خطای ارسال نباید ارسال شود. */
    private $shipping = false;

    /** @var bool آیا قلاب shutdown ثبت شده است. */
    private $shutdown_hooked = false;

    /** @var string|null مسیر پوشهٔ لاگ (بعد از اولین محاسبه کش می‌شود). */
    private $dir = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * سویچ اصلی لاگ‌گیری.
     *
     * خاموش که باشد هیچ فایلی نوشته نمی‌شود و هیچ چیزی به CRM نمی‌رود؛ افزونه
     * دقیقاً مثل قبل از این تغییرات رفتار می‌کند. فیلتر برای وقتی است که
     * بخواهید موقتاً و بدون دست‌زدن به تنظیمات خاموشش کنید (مثلاً در تست).
     */
    public static function enabled() {
        $enabled = (bool) get_option('arya_portal_logging_enabled', true);

        return (bool) apply_filters('arya_portal_logging_enabled', $enabled);
    }

    /**
     * سویچ ارسال به CRM، مستقل از لاگ محلی.
     *
     * جدا از enabled() است چون یک حالت واقعی دارد: وقتی CRM در حال جابه‌جایی
     * یا به‌روزرسانی است و نمی‌خواهید صف محلی بی‌جهت پر و خالی شود، ولی
     * همچنان می‌خواهید خطاها روی فایل وردپرس بمانند.
     */
    public static function shipping_enabled() {
        $enabled = (bool) get_option('arya_portal_log_shipping_enabled', true);

        return (bool) apply_filters('arya_portal_log_shipping_enabled', $enabled);
    }

    private function __construct() {
        add_action('arya_portal_flush_log_queue', [$this, 'flush_queue']);
        add_action('arya_portal_purge_logs', [$this, 'purge_old_files']);
        add_filter('cron_schedules', [$this, 'add_cron_schedule']);

        if (!wp_next_scheduled('arya_portal_flush_log_queue')) {
            wp_schedule_event(time() + 300, 'arya_portal_five_minutes', 'arya_portal_flush_log_queue');
        }
        if (!wp_next_scheduled('arya_portal_purge_logs')) {
            wp_schedule_event(time() + 3600, 'daily', 'arya_portal_purge_logs');
        }
    }

    public function add_cron_schedule($schedules) {
        if (!isset($schedules['arya_portal_five_minutes'])) {
            $schedules['arya_portal_five_minutes'] = [
                'interval' => 300,
                'display'  => 'هر ۵ دقیقه (لاگ آریا)',
            ];
        }
        return $schedules;
    }

    /* ===============================================================
     |  نوشتن لاگ
     ===============================================================*/

    /**
     * ثبت یک رخداد.
     *
     * @param string $code    یکی از ثابت‌های کد این کلاس.
     * @param string $message پیام خوانا (فارسی یا انگلیسی).
     * @param array  $context هر چیزی که برای عیب‌یابی لازم است.
     * @param string $level   error|warning|info
     * @param string $source  کدام افزونه/لایه.
     * @return string شناسهٔ رخداد؛ همان چیزی که به کاربر نشان داده می‌شود.
     */
    public function log($code, $message, $context = [], $level = self::LEVEL_ERROR, $source = self::SOURCE_PORTAL) {
        try {
            if (!self::enabled()) {
                return '';
            }

            if ($this->written >= self::MAX_PER_REQUEST) {
                return '';
            }
            $this->written++;

            $entry = $this->build_entry($code, $message, $context, $level, $source);

            $this->append_to_file($this->daily_file($entry['occurred_at']), $entry);
            $this->mirror_to_php_log($entry);

            // خطای خودِ لایهٔ ارسال نباید دوباره ارسال شود؛ فقط محلی می‌ماند.
            if ($code === self::LOG_SHIP_FAILED || $this->shipping) {
                return $entry['event_id'];
            }

            $this->queue_for_ship($entry);

            return $entry['event_id'];
        } catch (\Throwable $e) {
            // لاگ کردن هرگز نباید خودش صفحه را بشکند.
            error_log('[arya-portal] logger failure: ' . $e->getMessage());
            return '';
        }
    }

    public function error($code, $message, $context = [], $source = self::SOURCE_PORTAL) {
        return $this->log($code, $message, $context, self::LEVEL_ERROR, $source);
    }

    public function warning($code, $message, $context = [], $source = self::SOURCE_PORTAL) {
        return $this->log($code, $message, $context, self::LEVEL_WARNING, $source);
    }

    public function info($code, $message, $context = [], $source = self::SOURCE_PORTAL) {
        return $this->log($code, $message, $context, self::LEVEL_INFO, $source);
    }

    /**
     * برچسبی که کنار پیام خطا به کاربر نشان داده می‌شود.
     * کد + ۸ رقم اول شناسهٔ رخداد، تا پشتیبانی دقیقاً همان سطر را پیدا کند.
     */
    public static function reference($code, $event_id = '') {
        $event_id = (string) $event_id;
        return $event_id !== '' ? $code . '-' . substr($event_id, 0, 8) : (string) $code;
    }

    public static function label($code) {
        return self::CODE_LABELS[$code] ?? $code;
    }

    private function build_entry($code, $message, $context, $level, $source) {
        if (!in_array($level, [self::LEVEL_ERROR, self::LEVEL_WARNING, self::LEVEL_INFO], true)) {
            $level = self::LEVEL_ERROR;
        }

        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $phone = '';
        if ($user && $user->ID) {
            // در این سایت user_login همان شماره موبایل است.
            $phone = (string) $user->user_login;
        }
        if (!empty($context['phone'])) {
            $phone = (string) $context['phone'];
        }

        $endpoint = isset($context['endpoint']) ? (string) $context['endpoint'] : null;
        $http_status = isset($context['http_status']) && is_numeric($context['http_status'])
            ? (int) $context['http_status']
            : null;

        return [
            'event_id'    => $this->make_event_id(),
            'occurred_at' => current_time('mysql'),
            'level'       => $level,
            'source'      => $source,
            'code'        => (string) $code,
            'message'     => $this->truncate((string) $message, 1000),
            'endpoint'    => $endpoint ? $this->truncate($endpoint, 255) : null,
            'http_status' => $http_status,
            'phone'       => $phone !== '' ? $this->truncate($phone, 32) : null,
            'site'        => home_url(),
            'request_url' => $this->current_url(),
            'context'     => $this->sanitize_context($context),
        ];
    }

    private function make_event_id() {
        if (function_exists('wp_generate_uuid4')) {
            return str_replace('-', '', wp_generate_uuid4());
        }
        return md5(uniqid('arya', true));
    }

    /**
     * context نباید توکن/کوکی/رمز را به CRM ببرد و نباید بی‌اندازه بزرگ شود.
     */
    private function sanitize_context($context) {
        if (!is_array($context)) {
            $context = ['value' => $context];
        }

        $secret_keys = ['token', 'api_token', 'authorization', 'password', 'pass', 'cookie', 'nonce', 'secret'];
        $clean = [];

        foreach ($context as $key => $value) {
            $lower = is_string($key) ? strtolower($key) : $key;
            if (is_string($lower)) {
                foreach ($secret_keys as $secret) {
                    if (strpos($lower, $secret) !== false) {
                        $value = '***';
                        break;
                    }
                }
            }

            if (is_object($value)) {
                $value = json_decode(wp_json_encode($value), true);
            }
            if (is_string($value)) {
                $value = $this->truncate($value, 2000);
            }

            $clean[$key] = $value;
        }

        $encoded = wp_json_encode($clean);
        if (is_string($encoded) && strlen($encoded) > 15000) {
            $clean = ['_truncated' => true, 'preview' => substr($encoded, 0, 15000)];
        }

        return $clean;
    }

    private function truncate($value, $max) {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }

    private function current_url() {
        if (defined('DOING_CRON') && DOING_CRON) {
            return 'wp-cron';
        }
        if (empty($_SERVER['REQUEST_URI'])) {
            return php_sapi_name() === 'cli' ? 'cli' : '';
        }
        return $this->truncate(home_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))), 2000);
    }

    private function mirror_to_php_log($entry) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        error_log(sprintf(
            '[arya-portal][%s][%s] %s | %s',
            $entry['level'],
            $entry['code'],
            $entry['message'],
            wp_json_encode($entry['context'])
        ));
    }

    /* ===============================================================
     |  ذخیره‌سازی روزانه روی فایل
     ===============================================================*/

    /**
     * مسیر پوشهٔ لاگ. یک بخشِ هش‌شده در مسیر هست تا آدرس فایل‌ها از بیرون
     * حدس‌زدنی نباشد (روی Nginx فایل .htaccess خوانده نمی‌شود).
     */
    public function log_dir() {
        if ($this->dir !== null) {
            return $this->dir;
        }

        $uploads = wp_upload_dir();
        $base = !empty($uploads['basedir']) && empty($uploads['error'])
            ? $uploads['basedir']
            : WP_CONTENT_DIR;

        $salt = defined('AUTH_SALT') ? AUTH_SALT : ABSPATH;
        $dir = $base . '/arya-portal-logs/' . substr(md5($salt . 'arya-portal-logs'), 0, 20);

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            $this->protect_dir(dirname($dir));
            $this->protect_dir($dir);
        }

        $this->dir = $dir;
        return $dir;
    }

    private function protect_dir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
        }
    }

    /** فایل لاگ یک روز مشخص. */
    public function daily_file($date = null) {
        return $this->log_dir() . '/arya-' . $this->date_of($date) . '.log';
    }

    /** صفِ ارسال‌نشده‌های یک روز مشخص. */
    public function queue_file($date = null) {
        return $this->log_dir() . '/queue-' . $this->date_of($date) . '.log';
    }

    private function date_of($date) {
        if (!$date) {
            return current_time('Y-m-d');
        }
        $ts = strtotime((string) $date);
        return $ts ? date('Y-m-d', $ts) : current_time('Y-m-d');
    }

    private function append_to_file($file, $entry) {
        $line = wp_json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return false;
        }
        return (bool) @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** روزهایی که فایل لاگ دارند، از جدید به قدیم. */
    public function available_days() {
        $files = glob($this->log_dir() . '/arya-*.log') ?: [];
        $days = [];
        foreach ($files as $file) {
            if (preg_match('/arya-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                $days[] = $m[1];
            }
        }
        rsort($days);
        return $days;
    }

    /**
     * خواندن لاگ‌های یک روز، جدیدترین اول.
     *
     * @param array $filters code|level|source|search
     */
    public function read_day($date, $filters = [], $limit = 500) {
        $file = $this->daily_file($date);
        if (!is_readable($file)) {
            return [];
        }

        $handle = @fopen($file, 'r');
        if (!$handle) {
            return [];
        }

        $rows = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if (!$this->matches($entry, $filters)) {
                continue;
            }
            $rows[] = $entry;
        }
        fclose($handle);

        $rows = array_reverse($rows);

        return array_slice($rows, 0, $limit);
    }

    private function matches($entry, $filters) {
        foreach (['code', 'level', 'source'] as $key) {
            if (!empty($filters[$key]) && ($entry[$key] ?? '') !== $filters[$key]) {
                return false;
            }
        }

        if (!empty($filters['search'])) {
            $needle = mb_strtolower($filters['search']);
            $haystack = mb_strtolower(
                ($entry['message'] ?? '') . ' ' .
                ($entry['endpoint'] ?? '') . ' ' .
                ($entry['phone'] ?? '') . ' ' .
                wp_json_encode($entry['context'] ?? [])
            );
            if (mb_strpos($haystack, $needle) === false) {
                return false;
            }
        }

        return true;
    }

    /** آمار سریع برای صفحهٔ مدیریت. */
    public function day_stats($date) {
        $rows = $this->read_day($date, [], PHP_INT_MAX);
        $stats = ['total' => count($rows), 'by_code' => [], 'by_level' => []];

        foreach ($rows as $row) {
            $code = $row['code'] ?? '?';
            $level = $row['level'] ?? '?';
            $stats['by_code'][$code] = ($stats['by_code'][$code] ?? 0) + 1;
            $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
        }

        arsort($stats['by_code']);
        return $stats;
    }

    /** تعداد لاگ‌های در انتظار ارسال به CRM. */
    public function pending_count() {
        $files = glob($this->log_dir() . '/queue-*.log') ?: [];
        $count = 0;
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $count += is_array($lines) ? count($lines) : 0;
        }
        return $count;
    }

    /**
     * حذف فایل‌های قدیمی‌تر از بازهٔ نگهداری.
     * فایل روزانه است، پس پاک‌سازی فقط حذف چند فایل است.
     */
    public function purge_old_files() {
        $days = (int) apply_filters('arya_portal_log_retention_days', get_option('arya_portal_log_retention_days', 14));
        $days = max(1, min($days, 365));
        $cutoff = strtotime('-' . $days . ' days', strtotime(current_time('Y-m-d')));

        $deleted = 0;
        foreach (glob($this->log_dir() . '/*.log') ?: [] as $file) {
            if (!preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                continue;
            }
            if (strtotime($m[1]) < $cutoff) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /* ===============================================================
     |  ارسال به CRM
     ===============================================================*/

    /**
     * لاگ را برای ارسال در انتهای همین درخواست نگه می‌دارد.
     *
     * ارسال در `shutdown` انجام می‌شود نه همین‌جا: لاگ در همان درخواستی که
     * تولید شده به CRM می‌رسد (یعنی «فوری»)، ولی رندر صفحهٔ کاربر منتظر یک
     * درخواست HTTP دیگر نمی‌ماند.
     */
    private function queue_for_ship($entry) {
        // ارسال خاموش است: لاگ روی فایل محلی نوشته شده و همان‌جا می‌ماند.
        // به صف هم نمی‌رود، وگرنه با روشن‌شدن دوباره، انبوهی از خطاهای کهنه
        // یک‌جا به CRM سرازیر می‌شد.
        if (!self::shipping_enabled()) {
            return;
        }

        $mode = apply_filters('arya_portal_log_ship_mode', 'shutdown');

        if ($mode === 'cron') {
            $this->append_to_file($this->queue_file($entry['occurred_at']), $entry);
            return;
        }

        if ($mode === 'immediate') {
            $this->ship([$entry]);
            return;
        }

        $this->pending[] = $entry;

        if (!$this->shutdown_hooked) {
            $this->shutdown_hooked = true;
            add_action('shutdown', [$this, 'ship_pending'], 99);
        }
    }

    /** قلاب shutdown: ارسال لاگ‌های همین درخواست. */
    public function ship_pending() {
        if (empty($this->pending)) {
            return;
        }

        $batch = $this->pending;
        $this->pending = [];

        $this->ship($batch);
    }

    /**
     * ارسال یک بسته به CRM. هر چه نرسید به صف روزانه می‌رود تا کرون دوباره
     * تلاش کند؛ CRM بر اساس event_id تکراری‌ها را کنار می‌گذارد.
     *
     * @return bool آیا کل بسته پذیرفته شد.
     */
    private function ship(array $entries) {
        if (empty($entries)) {
            return false;
        }

        // اگر همین حالا وسط یک ارسال هستیم، این بسته را دور نمی‌ریزیم: به صف
        // می‌رود تا کرون بفرستدش. قاعدهٔ «هیچ لاگی گم نمی‌شود» استثنا ندارد.
        if ($this->shipping) {
            $this->requeue($entries);
            return false;
        }

        $settings = Settings::instance();
        $url = rtrim($settings->get_portal_url(), '/');
        $token = $settings->get_api_token();

        if (empty($url) || empty($token)) {
            $this->requeue($entries);
            return false;
        }

        $this->shipping = true;

        $ok = false;
        try {
            $response = wp_remote_post($url . '/api/v1/site-log', [
                'timeout' => 8,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'from_site'     => 1,
                ],
                'body' => wp_json_encode(['logs' => array_slice($entries, 0, self::SHIP_BATCH)]),
            ]);

            if (is_wp_error($response)) {
                $this->note_ship_failure('wp_error', $response->get_error_message(), count($entries));
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code >= 200 && $code < 300) {
                    $ok = true;
                } else {
                    $this->note_ship_failure('http_' . $code, wp_remote_retrieve_body($response), count($entries));
                }
            }
        } catch (\Throwable $e) {
            $this->note_ship_failure('exception', $e->getMessage(), count($entries));
        }

        $this->shipping = false;

        if (!$ok) {
            $this->requeue($entries);
        }

        return $ok;
    }

    /**
     * ناکامی در ارسال، خودش یک لاگ محلی است — ولی هرگز ارسال نمی‌شود، وگرنه
     * قطعیِ CRM یک حلقهٔ بی‌پایان از تلاش برای گزارش قطعی می‌ساخت.
     */
    private function note_ship_failure($reason, $detail, $count) {
        $entry = $this->build_entry(
            self::LOG_SHIP_FAILED,
            'ارسال لاگ‌ها به CRM انجام نشد: ' . $reason,
            ['reason' => $reason, 'detail' => $this->truncate((string) $detail, 500), 'count' => $count],
            self::LEVEL_WARNING,
            self::SOURCE_PORTAL
        );

        $this->append_to_file($this->daily_file($entry['occurred_at']), $entry);
        $this->mirror_to_php_log($entry);
    }

    private function requeue(array $entries) {
        foreach ($entries as $entry) {
            $this->append_to_file($this->queue_file($entry['occurred_at'] ?? null), $entry);
        }
    }

    /**
     * کرون: تلاش دوبارهٔ ارسال صف. فایل صف با آنچه هنوز نرفته بازنویسی می‌شود.
     *
     * @return array{sent:int, failed:int}
     */
    public function flush_queue() {
        $sent = 0;
        $failed = 0;

        if (!self::enabled() || !self::shipping_enabled()) {
            return ['sent' => $sent, 'failed' => $failed];
        }

        foreach (glob($this->log_dir() . '/queue-*.log') ?: [] as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines) || empty($lines)) {
                @unlink($file);
                continue;
            }

            $remaining = [];
            foreach (array_chunk($lines, self::SHIP_BATCH) as $chunk) {
                $entries = [];
                foreach ($chunk as $line) {
                    $entry = json_decode($line, true);
                    if (is_array($entry)) {
                        $entries[] = $entry;
                    }
                }
                if (empty($entries)) {
                    continue;
                }

                // ship() خودش شکست‌ها را به صف برمی‌گرداند؛ اینجا صف را از صفر
                // می‌سازیم پس باید مستقیم پست کنیم و نتیجه را خودمان نگه داریم.
                if ($this->post_batch($entries)) {
                    $sent += count($entries);
                } else {
                    $failed += count($entries);
                    $remaining = array_merge($remaining, $chunk);
                }
            }

            if (empty($remaining)) {
                @unlink($file);
            } else {
                @file_put_contents($file, implode(PHP_EOL, $remaining) . PHP_EOL, LOCK_EX);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** ارسال خام بدون بازگرداندن به صف (صف‌گردانی با فراخوان است). */
    private function post_batch(array $entries) {
        $settings = Settings::instance();
        $url = rtrim($settings->get_portal_url(), '/');
        $token = $settings->get_api_token();

        if (empty($url) || empty($token)) {
            return false;
        }

        $this->shipping = true;

        $response = wp_remote_post($url . '/api/v1/site-log', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'from_site'     => 1,
            ],
            'body' => wp_json_encode(['logs' => $entries]),
        ]);

        $this->shipping = false;

        if (is_wp_error($response)) {
            $this->note_ship_failure('wp_error', $response->get_error_message(), count($entries));
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $this->note_ship_failure('http_' . $code, wp_remote_retrieve_body($response), count($entries));
            return false;
        }

        return true;
    }
}

/**
 * میان‌بر سراسری، تا افزونهٔ arya-training هم بدون وابستگی سخت لاگ بزند.
 */
if (!function_exists('arya_portal_log')) {
    function arya_portal_log($code, $message, $context = [], $level = 'error', $source = 'arya-portal-integration') {
        if (!class_exists('\Arya\Portal\Logger')) {
            error_log('[arya-portal][' . $code . '] ' . $message);
            return '';
        }
        return \Arya\Portal\Logger::instance()->log($code, $message, $context, $level, $source);
    }
}
