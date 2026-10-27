<?php

namespace Arya\Portal;

/**
 * هویتِ امضاشدهٔ کاربرِ واردشده برای چتِ سایت (livechat ی راکت‌چت).
 *
 * چتِ سایت همان livechat ی راکت‌چت است و هوش مصنوعیِ CRM در آن جواب می‌دهد. مهمان فقط پاسخِ
 * عمومی می‌گیرد؛ برای کارهای شخصی کاربر باید وارد سایت شده باشد. پوستهٔ سایت بعد از بارگذاریِ
 * ویجت این توکن را می‌گیرد و در فیلدِ پنهانِ livechat (crm_identity) می‌گذارد؛ CRM آن را در
 * webhook با همان رمزِ مشترک می‌سنجد (App\Ai\Channels\RocketChat\SiteIdentityToken).
 *
 * شکلِ توکن:  v1.{crm_user_id}.{expires_unix}.{hex(hmac_sha256("v1.{id}.{exp}", secret))}
 *
 * - فقط برای کاربرِ واردشده، از مسیرِ REST با nonce (کش‌شدنِ صفحه آن را خراب نمی‌کند: پوسته
 *   nonce را هم بعد از بارگذاری از admin-ajax.php?action=rest-nonce می‌گیرد)؛
 * - شناسهٔ CRM از PersonData::getPersonByPhone (نامِ کاربری = موبایل) و در user meta کش می‌شود؛
 * - رمز: ثابتِ ARYA_PORTAL_CHAT_IDENTITY_SECRET در wp-config، وگرنه گزینهٔ تنظیماتِ افزونه.
 */
class ChatIdentity {

    const VERSION = 'v1';

    /** عمرِ توکن (ثانیه)؛ CRM بیش از دو ساعت را نمی‌پذیرد. پوسته هر ۲۰ دقیقه تازه‌اش می‌کند. */
    const TTL = 3600;

    /** نامِ فیلدِ پنهانِ livechat. */
    const FIELD = 'crm_identity';

    const OPTION_SECRET = 'arya_portal_chat_identity_secret';

    const META_CRM_ID = 'arya_portal_crm_user_id';

    const REST_NAMESPACE = 'arya-portal/v1';

    const REST_ROUTE = '/chat-identity';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'rest_mint'],
            // کوکیِ ورود + nonce ی wp_rest؛ مهمان چیزی نمی‌گیرد
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ]);
    }

    public function rest_mint($request) {
        nocache_headers();

        $minted = $this->mint_for(wp_get_current_user());
        if ($minted === null) {
            return new \WP_REST_Response(['token' => null, 'field' => self::FIELD], 200);
        }

        return new \WP_REST_Response($minted + ['field' => self::FIELD], 200);
    }

    /**
     * @param \WP_User|null $user
     * @return array{token: string, expires_at: int}|null  null = مهمان، رمز تنظیم نشده یا شخص در CRM نیست
     */
    public function mint_for($user, $now = null) {
        if (!$user || empty($user->ID)) {
            return null;
        }

        $secret = $this->secret();
        if ($secret === '') {
            return null;
        }

        $crmId = $this->crm_user_id($user);
        if ($crmId === null) {
            return null;
        }

        $expires = (int) ($now !== null ? $now : time()) + self::TTL;

        return ['token' => self::sign($crmId, $expires, $secret), 'expires_at' => $expires];
    }

    /** امضا؛ همان که CRM می‌سنجد. */
    public static function sign($crmUserId, $expires, $secret) {
        $payload = self::VERSION . '.' . (int) $crmUserId . '.' . (int) $expires;

        return $payload . '.' . hash_hmac('sha256', $payload, $secret);
    }

    public function secret() {
        if (defined('ARYA_PORTAL_CHAT_IDENTITY_SECRET') && ARYA_PORTAL_CHAT_IDENTITY_SECRET) {
            return (string) ARYA_PORTAL_CHAT_IDENTITY_SECRET;
        }

        return trim((string) get_option(self::OPTION_SECRET, ''));
    }

    /**
     * شناسهٔ شخص در CRM. با نامِ کاربری (موبایل) پیدا و در user meta کش می‌شود؛ اگر نامِ کاربری
     * عوض شود، دوباره پرسیده می‌شود.
     */
    public function crm_user_id($user) {
        $login = (string) $user->user_login;
        if ($login === '') {
            return null;
        }

        $cached = get_user_meta($user->ID, self::META_CRM_ID, true);
        if (is_array($cached) && ($cached['login'] ?? '') === $login && !empty($cached['id'])) {
            return (int) $cached['id'];
        }

        if (!class_exists(PersonData::class)) {
            return null;
        }

        $person = (new PersonData($login))->getPersonByPhone($login);
        $id = (is_object($person) && isset($person->data->id)) ? (int) $person->data->id : 0;
        if ($id <= 0) {
            return null;
        }

        update_user_meta($user->ID, self::META_CRM_ID, ['id' => $id, 'login' => $login]);

        return $id;
    }
}
