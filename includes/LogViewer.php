<?php

namespace Arya\Portal;

/**
 * صفحهٔ مشاهدهٔ لاگ در پیشخوان وردپرس.
 *
 * فقط خواندنی روی فایل‌های روزانه — هیچ جدولی به دیتابیس اضافه نمی‌شود.
 * وجودش برای وقتی است که CRM در دسترس نیست یا می‌خواهیم ببینیم چه چیزی
 * هنوز در صف ارسال مانده است.
 */
class LogViewer {

    private static $instance = null;

    const PAGE_SLUG = 'arya-portal-logs';

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 20);
        add_action('admin_post_arya_portal_flush_logs', [$this, 'handle_flush']);
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            'لاگ خطاهای پورتال آریا',
            'لاگ خطاهای آریا',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /** ارسال دستی صف به CRM. */
    public function handle_flush() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی ندارید.');
        }
        check_admin_referer('arya_portal_flush_logs');

        $result = Logger::instance()->flush_queue();

        wp_safe_redirect(add_query_arg([
            'page'   => self::PAGE_SLUG,
            'sent'   => (int) $result['sent'],
            'failed' => (int) $result['failed'],
        ], admin_url('admin.php')));
        exit;
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $logger = Logger::instance();
        $days = $logger->available_days();
        $today = current_time('Y-m-d');

        $selected_day = isset($_GET['day']) ? sanitize_text_field(wp_unslash($_GET['day'])) : ($days[0] ?? $today);
        $filters = [
            'code'   => isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '',
            'level'  => isset($_GET['level']) ? sanitize_text_field(wp_unslash($_GET['level'])) : '',
            'source' => isset($_GET['source']) ? sanitize_text_field(wp_unslash($_GET['source'])) : '',
            'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
        ];

        $rows = $logger->read_day($selected_day, $filters, 500);
        $stats = $logger->day_stats($selected_day);
        $pending = $logger->pending_count();

        ?>
        <div class="wrap">
            <h1>لاگ خطاهای پورتال آریا</h1>

            <?php if (isset($_GET['sent'])) : ?>
                <div class="notice notice-info">
                    <p>
                        ارسال دستی انجام شد — موفق: <strong><?php echo (int) $_GET['sent']; ?></strong>،
                        ناموفق: <strong><?php echo (int) ($_GET['failed'] ?? 0); ?></strong>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (!Logger::enabled()) : ?>
                <div class="notice notice-error">
                    <p>
                        <strong>ثبت خطاها خاموش است.</strong>
                        هیچ رخداد جدیدی ثبت یا ارسال نمی‌شود. برای روشن‌کردن به
                        <a href="<?php echo esc_url(admin_url('admin.php?page=arya-portal-settings')); ?>">تنظیمات پورتال آریا</a>
                        بروید.
                    </p>
                </div>
            <?php elseif (!Logger::shipping_enabled()) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>ارسال به CRM خاموش است.</strong>
                        خطاها فقط روی فایل همین سایت ثبت می‌شوند.
                    </p>
                </div>
            <?php endif; ?>

            <div class="notice notice-<?php echo $pending > 0 ? 'warning' : 'success'; ?>">
                <p>
                    در انتظار ارسال به CRM: <strong><?php echo (int) $pending; ?></strong> لاگ.
                    <?php if ($pending > 0) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <input type="hidden" name="action" value="arya_portal_flush_logs">
                            <?php wp_nonce_field('arya_portal_flush_logs'); ?>
                            <button type="submit" class="button button-small">ارسال همین حالا</button>
                        </form>
                    <?php endif; ?>
                </p>
            </div>

            <form method="get" style="margin: 15px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">

                <label for="arya-day">روز:</label>
                <select id="arya-day" name="day">
                    <?php if (empty($days)) : ?>
                        <option value="<?php echo esc_attr($today); ?>"><?php echo esc_html($today); ?></option>
                    <?php endif; ?>
                    <?php foreach ($days as $day) : ?>
                        <option value="<?php echo esc_attr($day); ?>" <?php selected($day, $selected_day); ?>>
                            <?php echo esc_html($day); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="arya-level">سطح:</label>
                <select id="arya-level" name="level">
                    <option value="">همه</option>
                    <?php foreach ([Logger::LEVEL_ERROR => 'خطا', Logger::LEVEL_WARNING => 'هشدار', Logger::LEVEL_INFO => 'اطلاع'] as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $filters['level']); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="arya-code">کد:</label>
                <select id="arya-code" name="code">
                    <option value="">همه</option>
                    <?php foreach (array_keys($stats['by_code']) as $code) : ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($code, $filters['code']); ?>>
                            <?php echo esc_html($code . ' (' . $stats['by_code'][$code] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="arya-source">منبع:</label>
                <select id="arya-source" name="source">
                    <option value="">همه</option>
                    <?php foreach ([Logger::SOURCE_PORTAL, Logger::SOURCE_TRAINING, Logger::SOURCE_FRONTEND] as $source) : ?>
                        <option value="<?php echo esc_attr($source); ?>" <?php selected($source, $filters['source']); ?>>
                            <?php echo esc_html($source); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="جستجو در پیام/سرویس/شماره">

                <button type="submit" class="button">فیلتر</button>
            </form>

            <p>
                <strong><?php echo (int) $stats['total']; ?></strong> رخداد در <?php echo esc_html($selected_day); ?>
                <?php foreach ($stats['by_level'] as $level => $count) : ?>
                    &nbsp;|&nbsp; <?php echo esc_html($level); ?>: <strong><?php echo (int) $count; ?></strong>
                <?php endforeach; ?>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:140px">زمان</th>
                        <th style="width:70px">سطح</th>
                        <th style="width:180px">کد</th>
                        <th style="width:130px">منبع</th>
                        <th>پیام</th>
                        <th style="width:200px">سرویس</th>
                        <th style="width:110px">موبایل</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="7">رخدادی برای این فیلترها ثبت نشده است.</td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td dir="ltr"><?php echo esc_html($row['occurred_at'] ?? ''); ?></td>
                        <td><?php echo esc_html($row['level'] ?? ''); ?></td>
                        <td>
                            <code><?php echo esc_html($row['code'] ?? ''); ?></code><br>
                            <small><?php echo esc_html(Logger::label($row['code'] ?? '')); ?></small>
                        </td>
                        <td><?php echo esc_html($row['source'] ?? ''); ?></td>
                        <td>
                            <?php echo esc_html($row['message'] ?? ''); ?>
                            <?php if (!empty($row['context'])) : ?>
                                <details>
                                    <summary>جزئیات</summary>
                                    <pre dir="ltr" style="white-space:pre-wrap;max-height:300px;overflow:auto"><?php
                                        echo esc_html(wp_json_encode($row['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                    ?></pre>
                                    <small>شناسه رخداد: <code dir="ltr"><?php echo esc_html($row['event_id'] ?? ''); ?></code></small>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td dir="ltr"><small><?php echo esc_html($row['endpoint'] ?? ''); ?></small></td>
                        <td dir="ltr"><?php echo esc_html($row['phone'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description">
                لاگ‌ها روزانه در <code dir="ltr"><?php echo esc_html($logger->log_dir()); ?></code> نگه‌داری می‌شوند
                و پس از <?php echo (int) apply_filters('arya_portal_log_retention_days', get_option('arya_portal_log_retention_days', 14)); ?>
                روز خودکار پاک می‌شوند. نسخهٔ ارسال‌شده در CRM، در فایل روزانهٔ
                <code dir="ltr">storage/logs/site-YYYY-MM-DD.log</code> نوشته می‌شود.
            </p>
        </div>
        <?php
    }
}
