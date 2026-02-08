<?php
/*
Plugin Name: WC Telegram Notifier (Roles & Users) — Final Version
Description: سوشال نوتیفایر فارسی — نسخه نهایی با داشبورد پیشرفته و حل مشکل کرون.
Version: 2.0.0
Author: AMP (Finalized)
*/

if (!defined('ABSPATH')) {
    exit;
}

class WCTelegramNotifier
{
    const OPTION_KEY = 'wctn_settings';
    const QUEUE_OPTION_KEY = 'wctn_notification_queue';
    const GROUP_KEY = 'wctn_settings_group';
    const NONCE_KEY = 'wctn_save_settings';
    const TEST_ACTION = 'wctn_send_test';
    const CRON_HOOK = 'wctn_process_queue_hook';
    const MANUAL_SEND_ACTION = 'wctn_manual_send';

    private static $instance = null;

    public function __construct()
    {
        self::$instance = $this;

        // Ensure the cron event is always scheduled.
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'minutely', self::CRON_HOOK);
        }

        // Admin UI
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Test send handler (admin-post)
        add_action('admin_post_' . self::TEST_ACTION, [$this, 'handle_test_send']);
        add_action('admin_post_wctn_send_test_sms', [$this, 'handle_test_sms_send']);
        add_action('admin_post_wctn_save_rules', [$this, 'handle_save_rules']);
        add_action('admin_post_wctn_process_queue', [$this, 'handle_manual_queue_process']);
        add_action('admin_post_' . self::MANUAL_SEND_ACTION, [$this, 'handle_manual_send']);

        // User profile fields
        add_action('show_user_profile', [$this, 'render_user_telegram_field']);
        add_action('edit_user_profile', [$this, 'render_user_telegram_field']);
        add_action('personal_options_update', [$this, 'save_user_telegram_field']);
        add_action('edit_user_profile_update', [$this, 'save_user_telegram_field']);

        add_action('show_user_profile', [$this, 'render_user_mobile_field']);
        add_action('edit_user_profile', [$this, 'render_user_mobile_field']);
        add_action('personal_options_update', [$this, 'save_user_mobile_field']);
        add_action('edit_user_profile_update', [$this, 'save_user_mobile_field']);

        // WP events
        add_action('user_register', [$this, 'on_user_register'], 10, 1);
        add_action('profile_update', [$this, 'on_profile_update'], 10, 2);
        add_action('transition_post_status', [$this, 'on_transition_post_status'], 10, 3);
        add_action('comment_post', [$this, 'on_comment_post'], 10, 3);

        // WooCommerce hooks when available
        if (class_exists('WooCommerce')) {
            $this->hook_woocommerce();
        } else {
            add_action('plugins_loaded', [$this, 'maybe_hook_woocommerce']);
        }

        // Admin notices
        add_action('admin_notices', [$this, 'maybe_show_admin_notices']);

        // Cron processing
        add_action(self::CRON_HOOK, [$this, 'process_notification_queue']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Dashboard Widget
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        
        
        // Inside the __construct function, add this line:
add_action('admin_post_wctn_reset_stats', [$this, 'handle_reset_stats']);


    }

    public static function get_instance()
    {
        return self::$instance;
    }

    /* ------------------------------
     * Activation / Deactivation
     * ------------------------------ */

    public static function on_activation()
{
    if (!wp_next_scheduled(self::CRON_HOOK)) {
        wp_schedule_event(time(), 'minutely', self::CRON_HOOK);
    }
    if (false === get_option(self::OPTION_KEY, false)) {
        add_option(self::OPTION_KEY, self::defaults());
    }
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Stats table (already exists)
    $table_name_stats = $wpdb->prefix . 'wctn_stats';
    $sql_stats = "CREATE TABLE $table_name_stats (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        date date NOT NULL,
        type varchar(10) NOT NULL,
        status varchar(10) NOT NULL,
        count int NOT NULL DEFAULT 1,
        PRIMARY KEY  (id),
        UNIQUE KEY date_type_status (date, type, status)
    ) $charset_collate;";
    
    // NEW: Logs table
    $table_name_logs = $wpdb->prefix . 'wctn_logs';
    $sql_logs = "CREATE TABLE $table_name_logs (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        timestamp datetime NOT NULL,
        type varchar(10) NOT NULL,
        recipient varchar(255) NOT NULL,
        message text NOT NULL,
        status varchar(10) NOT NULL,
        error_message text NULL,
        PRIMARY KEY  (id),
        KEY type_status (type, status),
        KEY timestamp (timestamp)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_stats);
    dbDelta($sql_logs); // This line creates the new table
}

    public static function on_deactivation()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_option(self::QUEUE_OPTION_KEY);
    }

    /* ------------------------------
     * Defaults & settings
     * ------------------------------ */

    public static function defaults()
{
    return [
        'bot_token' => '',
        'parse_mode' => 'HTML',
        'kavenegar_api_key' => '',
        'kavenegar_sender' => '',
        'timezone' => 'Asia/Tehran', // <-- ADD THIS LINE
        // Proxy settings for Cloudflare worker
        'proxy_url' => 'https://telnotifcloudflare.rfkala-ir.workers.dev',
        'proxy_api_key' => '',
        'rules' => [],
    ];
}

    public static function get_settings()
    {
        $saved = get_option(self::OPTION_KEY, []);
        $defaults = self::defaults();
        $opts = wp_parse_args($saved, $defaults);
        $opts['rules'] = !empty($opts['rules']) && is_array($opts['rules']) ? $opts['rules'] : [];
        return $opts;
    }

    public function register_settings()
    {
        register_setting(self::GROUP_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input)
    {
        $saved_settings = get_option(self::OPTION_KEY, self::defaults());
        $output = wp_parse_args($saved_settings, self::defaults());

        if (array_key_exists('bot_token', $input)) {
            $output['bot_token'] = sanitize_text_field($input['bot_token']);
        }
        if (array_key_exists('parse_mode', $input)) {
            $parse = sanitize_text_field($input['parse_mode']);
            $output['parse_mode'] = in_array($parse, ['HTML', 'MarkdownV2'], true) ? $parse : 'HTML';
        } else {
            $output['parse_mode'] = 'HTML';
        }
        if (array_key_exists('kavenegar_api_key', $input)) {
            $output['kavenegar_api_key'] = sanitize_text_field($input['kavenegar_api_key']);
        } else {
            $output['kavenegar_api_key'] = '';
        }
        if (array_key_exists('kavenegar_sender', $input)) {
            $output['kavenegar_sender'] = sanitize_text_field($input['kavenegar_sender']);
        } else {
            $output['kavenegar_sender'] = '';
        }
        $output['kavenegar_sender'] = sanitize_text_field($input['kavenegar_sender'] ?? '');
        $output['timezone'] = in_array($input['timezone'] ?? '', timezone_identifiers_list()) ? $input['timezone'] : 'Asia/Tehran'; // <-- ADD THIS LINE

        // proxy settings
        if (array_key_exists('proxy_url', $input)) {
            $output['proxy_url'] = esc_url_raw(trim($input['proxy_url']));
        } else {
            $output['proxy_url'] = $output['proxy_url'] ?? '';
        }
        if (array_key_exists('proxy_api_key', $input)) {
            $output['proxy_api_key'] = sanitize_text_field($input['proxy_api_key']);
        } else {
            $output['proxy_api_key'] = $output['proxy_api_key'] ?? '';
        }
    return $output;
    }

    /* ------------------------------
     * Stats & Dashboard Helpers
     * ------------------------------ */

    /**
     * Updates the daily statistics in the database.
     */
    private function update_stats($type, $status)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wctn_stats';
        $today = date('Y-m-d');

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $table_name (date, type, status, count) VALUES (%s, %s, %s, 1)
                 ON DUPLICATE KEY UPDATE count = count + 1",
                $today,
                $type,
                $status
            )
        );
    }

    /**
     * Fetches statistics for the dashboard.
     */
    private function get_dashboard_stats($days = 7)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wctn_stats';
        $date_limit = date('Y-m-d', strtotime("-$days days"));

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT date, type, status, SUM(count) as total_count
                 FROM $table_name
                 WHERE date >= %s
                 GROUP BY date, type, status
                 ORDER BY date DESC",
                $date_limit
            )
        );

        $stats = [];
        foreach ($results as $row) {
            $stats[$row->date][$row->type][$row->status] = $row->total_count;
        }
        return $stats;
    }

    /* ------------------------------
     * Dashboard Widget
     * ------------------------------ */

    public function add_dashboard_widget()
    {
        if (current_user_can('manage_options')) {
            wp_add_dashboard_widget('wctn_dashboard_widget', __('وضعیت نوتیفیکیشن‌ها', 'wc-telegram-notifier'), [$this, 'render_dashboard_widget']);
        }
    }

    public function render_dashboard_widget()
{
    $queue = get_option(self::QUEUE_OPTION_KEY, []);
    $queue_count = is_array($queue) ? count($queue) : 0;
    $next_cron = wp_next_scheduled(self::CRON_HOOK);
    // Use the new helper function for correct timezone display
    $cron_status = $next_cron ? $this->get_formatted_datetime(date('Y-m-d H:i:s', $next_cron)) : __('نامشخص', 'wc-telegram-notifier');

    $stats_data = $this->get_dashboard_stats(7);
    $total_sent = $total_failed = 0;
    foreach ($stats_data as $date => $types) {
        foreach (['telegram', 'sms'] as $type) {
            $total_sent += isset($types[$type]['sent']) ? $types[$type]['sent'] : 0;
            $total_failed += isset($types[$type]['failed']) ? $types[$type]['failed'] : 0;
        }
    }
    $total_all = $total_sent + $total_failed;
    $success_rate = $total_all > 0 ? round(($total_sent / $total_all) * 100, 1) : 0;

    echo '<div style="direction: rtl; text-align: right;">';
    echo '<h4>' . __('آمار ۷ روز گذشته', 'wc-telegram-notifier') . '</h4>';
    echo '<p style="font-size: 1.1em;">';
    echo '<strong style="color: #46b450;">' . __('موفق:', 'wc-telegram-notifier') . '</strong> ' . number_format($total_sent) . ' | ';
    echo '<strong style="color: #dc3232;">' . __('ناموفق:', 'wc-telegram-notifier') . '</strong> ' . number_format($total_failed) . ' | ';
    echo '<strong>' . __('نرخ موفقیت:', 'wc-telegram-notifier') . '</strong> ' . $success_rate . '%';
    echo '</p>';
    
    // IMPROVED CHART
    echo '<h5>' . __('نمودار ارسال روزانه', 'wc-telegram-notifier') . '</h5>';
    echo '<div style="border-left: 2px solid #ccc; padding-left: 10px; margin-bottom: 15px; font-size: 12px;">';
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $tg_sent = $stats_data[$date]['telegram']['sent'] ?? 0;
        $tg_failed = $stats_data[$date]['telegram']['failed'] ?? 0;
        $sms_sent = $stats_data[$date]['sms']['sent'] ?? 0;
        $sms_failed = $stats_data[$date]['sms']['failed'] ?? 0;
        $day_total = $tg_sent + $tg_failed + $sms_sent + $sms_failed;
        $formatted_date = date_i18n(get_option('date_format'), strtotime($date));
        echo '<div style="margin-bottom: 5px;">';
        echo '<span style="display: inline-block; width: 80px;">' . $formatted_date . '</span>';
        echo '<div style="display: inline-block; width: 200px; background-color: #f0f0f0; vertical-align: middle; height: 15px;">';
        if ($tg_sent > 0) echo '<div style="display:inline-block; width:'.round(($tg_sent/max(1,$day_total))*100).'%; height:100%; background-color:#008000;" title="تلگرام موفق: '.$tg_sent.'"></div>';
        if ($tg_failed > 0) echo '<div style="display:inline-block; width:'.round(($tg_failed/max(1,$day_total))*100).'%; height:100%; background-color:#b30000;" title="تلگرام ناموفق: '.$tg_failed.'"></div>';
        if ($sms_sent > 0) echo '<div style="display:inline-block; width:'.round(($sms_sent/max(1,$day_total))*100).'%; height:100%; background-color:#1e73be;" title="پیامک موفق: '.$sms_sent.'"></div>';
        if ($sms_failed > 0) echo '<div style="display:inline-block; width:'.round(($sms_failed/max(1,$day_total))*100).'%; height:100%; background-color:#ff9800;" title="پیامک ناموفق: '.$sms_failed.'"></div>';
        echo '</div>';
        echo '<span>(' . number_format($day_total) . ')</span>';
        echo '</div>';
    }
    echo '</div>';

    // Queue Status & Reset Button
    echo '<hr>';
    echo '<h4>' . __('وضعیت فعلی صف', 'wc-telegram-notifier') . '</h4>';
    echo '<p><strong>' . __('پیام‌های در صف انتظار:', 'wc-telegram-notifier') . '</strong> <span style="font-size: 1.5em; font-weight: bold; color: #0073aa;">' . (int) $queue_count . '</span></p>';
    echo '<p><small>' . sprintf(__('اجرای بعدی کرون: %s', 'wc-telegram-notifier'), $cron_status) . '</small></p>';
    
    if ($queue_count > 0) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block; margin-top: 10px;">';
        wp_nonce_field('wctn_process_queue', 'wctn_process_queue_nonce');
        echo '<input type="hidden" name="action" value="wctn_process_queue">';
        submit_button(__('پردازش فوری صف', 'wc-telegram-notifier'), 'primary', 'wctn_process_queue', false);
        echo '</form>';
    } else {
        echo '<p style="color: green;">' . __('هیچ پیامی در صف نیست.', 'wc-telegram-notifier') . '</p>';
    }
    
    // ADD RESET BUTTON
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block; margin-top: 10px;" onsubmit="return confirm(\'آیا از پاک کردن تمام آمار و لاگ‌ها اطمینان دارید؟ این عمل غیرقابل بازگشت است.\');">';
    wp_nonce_field('wctn_reset_stats', 'wctn_reset_stats_nonce');
    echo '<input type="hidden" name="action" value="wctn_reset_stats">';
    submit_button(__('ریست آمار و لاگ‌ها', 'wc-telegram-notifier'), 'secondary', 'wctn_reset_stats', false);
    echo '</form>';
    
    echo '</div>';
}

    /* ------------------------------
     * Admin UI: menu + assets + page
     * ------------------------------ */

    public function add_admin_menu()
    {
        add_menu_page('Telegram Notifier', 'Telegram Notifier', 'manage_options', 'wctn_settings', [$this, 'render_settings_page'], 'dashicons-megaphone', 56);
    // ADD THIS LINE:
    add_submenu_page('wctn_settings', 'لاگ ارسال‌ها', 'لاگ ارسال‌ها', 'manage_options', 'wctn_logs', [$this, 'render_logs_page']);
    }

    public function enqueue_admin_assets($hook_suffix)
    {
        if ('toplevel_page_wctn_settings' !== $hook_suffix && 'index.php' !== $hook_suffix) {
            return;
        }
        if (!wp_script_is('select2', 'registered')) {
            wp_register_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', ['jquery'], null, true);
            wp_register_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', [], null);
        }
        wp_enqueue_script('select2');
        wp_enqueue_style('select2');
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $opts = self::get_settings();
        $all_events = $this->get_available_events();
        $all_roles = $this->get_all_roles();
        $all_users = get_users(['fields' => ['ID', 'display_name', 'user_login'], 'orderby' => 'display_name', 'number' => -1]);
        $saved_rules = self::get_all_rules();
        ?>
<div class="wrap">
    <h1><?php _e('Telegram Notifier', 'wc-telegram-notifier'); ?></h1>
    <?php settings_errors(); ?>
    <h2 class="nav-tab-wrapper">
        <a href="#rules" class="nav-tab nav-tab-active"><?php _e('قوانین ارسال', 'wc-telegram-notifier'); ?></a>
        <a href="#manual-send" class="nav-tab"><?php _e('ارسال دستی', 'wc-telegram-notifier'); ?></a>
        <a href="#settings" class="nav-tab"><?php _e('تنظیمات افزونه', 'wc-telegram-notifier'); ?></a>
    </h2>
    <div id="rules" class="wctn-tab-content">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wctn_save_rules">
            <?php wp_nonce_field('wctn_save_rules', 'wctn_save_rules_nonce'); ?>
            <p><?php _e('قوانین ارسال نوتیفیکیشن برای رویدادهای مختلف را در اینجا مدیریت کنید.', 'wc-telegram-notifier'); ?>
            </p>
            <div id="wctn-rules-container">
                <?php if (empty($saved_rules)): ?>
                <p><?php _e('هنوز قانونی ثبت نشده است. برای شروع یک قانون جدید اضافه کنید.', 'wc-telegram-notifier'); ?>
                </p>
                <?php else: ?>
                <?php foreach ($saved_rules as $index => $rule): ?>
                <?php $this->render_rule_template($index, $rule, $all_events, $all_roles, $all_users); ?>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <p><button type="button" class="button"
                    id="wctn-add-rule"><?php _e('افزودن قانون جدید', 'wc-telegram-notifier'); ?></button></p>
            <?php submit_button(__('ذخیره قوانین', 'wc-telegram-notifier')); ?>
        </form>
    </div>
    <div id="manual-send" class="wctn-tab-content" style="display:none;">
        <h3><?php _e('ارسال پیام دستی', 'wc-telegram-notifier'); ?></h3>
        <p><?php _e('از این بخش برای ارسال یک پیام سفارشی به کاربران خاص استفاده کنید.', 'wc-telegram-notifier'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::MANUAL_SEND_ACTION, self::MANUAL_SEND_ACTION . '_nonce'); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::MANUAL_SEND_ACTION); ?>">
            <table class="form-table">
                <tr>
                    <th><label for="wctn_recipient_type"><?php _e('نوع گیرنده', 'wc-telegram-notifier'); ?></label></th>
                    <td>
                        <select name="recipient_type" id="wctn_recipient_type">
                            <option value="users"><?php _e('انتخاب از لیست کاربران', 'wc-telegram-notifier'); ?>
                            </option>
                            <option value="manual">
                                <?php _e('وارد کردن دستی (Chat ID / شماره موبایل)', 'wc-telegram-notifier'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr id="wctn_recipient_users_row">
                    <th><label for="wctn_recipient_users"><?php _e('انتخاب کاربران', 'wc-telegram-notifier'); ?></label>
                    </th>
                    <td>
                        <select name="recipient_users[]" id="wctn_recipient_users" class="wctn-select2" multiple
                            style="width:100%;">
                            <?php foreach ($all_users as $user): ?>
                            <option value="<?php echo esc_attr($user->ID); ?>">
                                <?php echo esc_html(sprintf('%s (%s) #%d', $user->display_name, $user->user_login, $user->ID)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php _e('می‌توانید یک یا چند کاربر را برای ارسال پیام انتخاب کنید.', 'wc-telegram-notifier'); ?>
                        </p>
                    </td>
                </tr>
                <tr id="wctn_recipient_manual_row" style="display:none;">
                    <th><label
                            for="wctn_recipient_manual"><?php _e('گیرنده (Chat ID / شماره موبایل)', 'wc-telegram-notifier'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="recipient_manual" id="wctn_recipient_manual"
                            class="regular-text ltr" />
                        <p class="description">
                            <?php _e('برای تلگرام Chat ID عددی و برای پیامک شماره موبایل با کد کشور وارد کنید. برای چند گیرنده، آن‌ها را با کاما (,) جدا کنید.', 'wc-telegram-notifier'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wctn_manual_channels"><?php _e('کانال ارسال', 'wc-telegram-notifier'); ?></label>
                    </th>
                    <td>
                        <label><input type="checkbox" name="channels[]" value="telegram" checked>
                            <?php _e('تلگرام', 'wc-telegram-notifier'); ?></label><br>
                        <label><input type="checkbox" name="channels[]" value="sms">
                            <?php _e('پیامک', 'wc-telegram-notifier'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th><label for="wctn_manual_message"><?php _e('متن پیام', 'wc-telegram-notifier'); ?></label></th>
                    <td>
                        <textarea name="message" id="wctn_manual_message" rows="6" class="large-text"
                            placeholder="<?php esc_attr_e('متن پیام خود را اینجا بنویسید...', 'wc-telegram-notifier'); ?>"></textarea>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('ارسال پیام', 'wc-telegram-notifier'), 'primary'); ?>
        </form>
    </div>
    <div id="settings" class="wctn-tab-content" style="display:none;">
        <div style="display:flex; gap:20px;">
            <div style="min-width:140px;">
                <a href="#telegram-settings"
                    class="nav-tab nav-tab-active"><?php _e('تلگرام', 'wc-telegram-notifier'); ?></a>
                <a href="#sms-settings" class="nav-tab"><?php _e('پیامک', 'wc-telegram-notifier'); ?></a>
            </div>
            <div style="flex:1;">
                <!-- Telegram -->
                <div id="telegram-settings" class="wctn-vertical-tab-pane">
                    <form method="post" action="options.php">
                        <?php settings_fields(self::GROUP_KEY); wp_nonce_field(self::NONCE_KEY, self::NONCE_KEY); ?>
                        <table class="form-table">
                            <tr>
                                <th><label
                                        for="wctn_bot_token"><?php _e('Bot Token', 'wc-telegram-notifier'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="wctn_bot_token"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[bot_token]"
                                        value="<?php echo esc_attr($opts['bot_token']); ?>" class="regular-text ltr" />
                                    <p class="description">
                                        <?php _e('توکن ربات را از BotFather تلگرام دریافت کنید.', 'wc-telegram-notifier'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label
                                        for="wctn_parse_mode"><?php _e('Parse Mode', 'wc-telegram-notifier'); ?></label>
                                </th>
                                <td>
                                    <select id="wctn_parse_mode"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[parse_mode]">
                                        <option value="HTML" <?php selected($opts['parse_mode'], 'HTML'); ?>>HTML
                                        </option>
                                        <option value="MarkdownV2"
                                            <?php selected($opts['parse_mode'], 'MarkdownV2'); ?>>MarkdownV2</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label
                                        for="wctn_timezone"><?php _e('منطقه زمانی', 'wc-telegram-notifier'); ?></label>
                                </th>
                                <td>
                                    <select id="wctn_timezone"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[timezone]">
                                        <?php
            $current_timezone = $opts['timezone'] ?? 'Asia/Tehran';
            $timezones = timezone_identifiers_list();
            foreach ($timezones as $tz) {
                echo '<option value="' . esc_attr($tz) . '" ' . selected($current_timezone, $tz, false) . '>' . esc_html($tz) . '</option>';
            }
            ?>
                                    </select>
                                    <p class="description">
                                        <?php _e('منطقه زمانی برای نمایش صحیح تاریخ و ساعت در گزارش‌ها.', 'wc-telegram-notifier'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(__('ذخیره تنظیمات تلگرام', 'wc-telegram-notifier')); ?>
                    </form>
                    <h3><?php _e('تست ارسال پیام تلگرام', 'wc-telegram-notifier'); ?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(self::NONCE_KEY, self::NONCE_KEY); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_ACTION); ?>" />
                        <table class="form-table">
                            <tr>
                                <th><label
                                        for="wctn_test_chat_id"><?php _e('Chat ID تست', 'wc-telegram-notifier'); ?></label>
                                </th>
                                <td><input type="text" id="wctn_test_chat_id" name="wctn_test_chat_id"
                                        class="regular-text ltr" /></td>
                            </tr>
                        </table>
                        <?php submit_button(__('ارسال پیام تست', 'wc-telegram-notifier')); ?>
                    </form>
                </div>
                <!-- SMS -->
                <div id="sms-settings" class="wctn-vertical-tab-pane" style="display:none;">
                    <form method="post" action="options.php">
                        <?php settings_fields(self::GROUP_KEY); wp_nonce_field(self::NONCE_KEY, self::NONCE_KEY); ?>
                        <table class="form-table">
                            <tr>
                                <th><label
                                        for="wctn_kavenegar_api_key"><?php _e('API Key', 'wc-telegram-notifier'); ?></label>
                                </th>
                                <td><input type="text" id="wctn_kavenegar_api_key"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[kavenegar_api_key]"
                                        value="<?php echo esc_attr($opts['kavenegar_api_key']); ?>"
                                        class="regular-text ltr" /></td>
                            </tr>
                            <tr>
                                <th><label
                                        for="wctn_kavenegar_sender"><?php _e('شماره فرستنده', 'wc-telegram-notifier'); ?></label>
                                </th>
                                <td><input type="text" id="wctn_kavenegar_sender"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[kavenegar_sender]"
                                        value="<?php echo esc_attr($opts['kavenegar_sender']); ?>"
                                        class="regular-text ltr" /></td>
                            </tr>
                        </table>
                        <?php submit_button(__('ذخیره تنظیمات پیامک', 'wc-telegram-notifier')); ?>
                    </form>
                    <h3><?php _e('تست ارسال پیامک', 'wc-telegram-notifier'); ?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wctn_send_test_sms" />
                        <?php wp_nonce_field('wctn_send_test_sms', 'wctn_send_test_sms_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th><label
                                        for="wctn_test_mobile"><?php _e('شماره موبایل تست', 'wc-telegram-notifier'); ?></label>
                                </th>
                                <td><input type="text" id="wctn_test_mobile" name="wctn_test_mobile"
                                        class="regular-text ltr" /></td>
                            </tr>
                        </table>
                        <?php submit_button(__('ارسال پیامک تست', 'wc-telegram-notifier')); ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Hidden template for rules -->
    <?php $this->render_rule_template('__INDEX__', [], $all_events, $all_roles, $all_users, true); ?>
</div>
<script type="text/javascript">
(function($) {
    $(function() {
        $('.nav-tab-wrapper a').on('click', function(e) {
            e.preventDefault();
            $('.nav-tab-wrapper a').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.wctn-tab-content').hide();
            $($(this).attr('href')).show();
        });
        $('.nav-tab, .wctn-vertical-tabs-nav a').on('click', function(e) {
            e.preventDefault();
            var container = $(this).closest('div').next('div');
            $(this).closest('div').find('a').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            container.find('.wctn-vertical-tab-pane').hide();
            var target = $(this).attr('href');
            if (target) {
                $(target).show();
            }
        });
        $('#wctn_recipient_type').on('change', function() {
            var type = $(this).val();
            if (type === 'users') {
                $('#wctn_recipient_users_row').show();
                $('#wctn_recipient_manual_row').hide();
            } else {
                $('#wctn_recipient_users_row').hide();
                $('#wctn_recipient_manual_row').show();
            }
        });

        function initSelect2(el) {
            if ($(el).select2) {
                $(el).select2({
                    placeholder: 'یک یا چند مورد انتخاب کنید',
                    width: '100%'
                });
            }
        }
        $('.wctn-select2').each(function() {
            initSelect2(this);
        });
        $('#wctn-add-rule').on('click', function() {
            var tpl = $('#wctn-rule-template').html();
            var idx = new Date().getTime();
            tpl = tpl.replace(/__INDEX__/g, idx);
            $('#wctn-rules-container').append(tpl);
            $('#wctn-rules-container .wctn-select2').each(function() {
                initSelect2(this);
            });
        });
        $('#wctn-rules-container').on('click', '.wctn-remove-rule', function(e) {
            e.preventDefault();
            $(this).closest('.wctn-rule').remove();
        });
    });
})(jQuery);
</script>
<?php
    }

    /* ------------------------------
     * Handlers
     * ------------------------------ */

    public function handle_test_send()
    {
        if (!current_user_can('manage_options')) { wp_die('Access denied'); }
        check_admin_referer(self::NONCE_KEY, self::NONCE_KEY);
        $opts = self::get_settings();
        if (empty($opts['bot_token']) || empty($_POST['wctn_test_chat_id'])) {
            wp_redirect(add_query_arg(['wctn_error' => '1'], wp_get_referer()));
            exit;
        }
        $instance = self::get_instance();
         $result = $instance->send_telegram($opts['bot_token'], sanitize_text_field($_POST['wctn_test_chat_id']), 'This is a test message from Telegram Notifier.', $opts['parse_mode'], true);
        if ($result && $result['ok']) {
            wp_redirect(add_query_arg(['wctn_success' => '1'], wp_get_referer()));
        } else {
            wp_redirect(add_query_arg(['wctn_error' => '1'], wp_get_referer()));
        }
        exit;
    }

    public function handle_test_sms_send()
    {
        if (!current_user_can('manage_options')) { wp_die('Access denied'); }
        check_admin_referer('wctn_send_test_sms', 'wctn_send_test_sms_nonce');
        $opts = self::get_settings();
        if (empty($opts['kavenegar_api_key']) || empty($opts['kavenegar_sender']) || empty($_POST['wctn_test_mobile'])) {
            wp_redirect(add_query_arg(['wctn_error' => '1'], wp_get_referer()));
            exit;
        }
        $instance = self::get_instance();
        $result = $instance->send_sms_kavenegar($opts['kavenegar_api_key'], $opts['kavenegar_sender'], sanitize_text_field($_POST['wctn_test_mobile']), 'This is a test message from Telegram Notifier.');
        if ($result && $result['ok']) {
            wp_redirect(add_query_arg(['wctn_success' => '1'], wp_get_referer()));
        } else {
            wp_redirect(add_query_arg(['wctn_error' => '1'], wp_get_referer()));
        }
        exit;
    }

    public function handle_manual_send()
    {
        if (!current_user_can('manage_options')) { wp_die('Access denied'); }
        check_admin_referer(self::MANUAL_SEND_ACTION, self::MANUAL_SEND_ACTION . '_nonce');
        $recipient_type = isset($_POST['recipient_type']) ? sanitize_text_field($_POST['recipient_type']) : 'users';
        $channels = isset($_POST['channels']) ? array_map('sanitize_text_field', $_POST['channels']) : [];
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        if (empty($channels) || empty($message)) {
            wp_redirect(add_query_arg(['wctn_manual_send_error' => '1'], wp_get_referer()));
            exit;
        }
        $telegram_targets = []; $sms_targets = [];
        if ($recipient_type === 'users') {
            $user_ids = isset($_POST['recipient_users']) ? array_map('intval', $_POST['recipient_users']) : [];
            foreach ($user_ids as $uid) {
                if (in_array('telegram', $channels)) { $chat_id = get_user_meta($uid, '_wctn_telegram_chat_id', true); if ($chat_id) { $telegram_targets[] = $chat_id; } }
                if (in_array('sms', $channels)) { $mobile = get_user_meta($uid, '_wctn_mobile_number', true); if ($mobile) { $sms_targets[] = $mobile; } }
            }
        } else {
            $manual_recipients = isset($_POST['recipient_manual']) ? sanitize_text_field($_POST['recipient_manual']) : '';
            $recipients = array_map('trim', explode(',', $manual_recipients));
            foreach ($recipients as $recipient) {
                if (empty($recipient)) continue;
                if (is_numeric($recipient)) { if (in_array('sms', $channels)) { $sms_targets[] = $recipient; } }
                else { if (in_array('telegram', $channels)) { $telegram_targets[] = $recipient; } }
            }
        }
        $queue = get_option(self::QUEUE_OPTION_KEY, []); if (!is_array($queue)) { $queue = []; }
        $opts = self::get_settings();
        foreach ($telegram_targets as $chat_id) { $queue[] = ['type' => 'telegram', 'to' => $chat_id, 'message' => $message, 'parse_mode' => $opts['parse_mode'] ?? 'HTML']; }
        foreach ($sms_targets as $mobile) { $queue[] = ['type' => 'sms', 'to' => $mobile, 'message' => wp_strip_all_tags($message)]; }
        update_option(self::QUEUE_OPTION_KEY, $queue, false);
        wp_redirect(add_query_arg(['wctn_manual_send_success' => '1'], wp_get_referer()));
        exit;
    }

    public function handle_save_rules()
    {
        if (!current_user_can('manage_options')) { wp_die('Access denied'); }
        check_admin_referer('wctn_save_rules', 'wctn_save_rules_nonce');
        $this->save_rules_manually();
        wp_redirect(add_query_arg(['wctn_rules_saved' => '1'], wp_get_referer()));
        exit;
    }

    public function save_rules_manually()
    {
        global $wpdb;
        $like = 'wctn_rule_%';
        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name LIKE %s", $like));
        $rules = isset($_POST['wctn_rules']) && is_array($_POST['wctn_rules']) ? $_POST['wctn_rules'] : [];
        foreach ($rules as $rule_in) {
            if (empty($rule_in['event']) || empty($rule_in['channels'])) { continue; }
            $new_rule = [];
            $new_rule['event'] = sanitize_text_field($rule_in['event']);
            $new_rule['channels'] = array_map('sanitize_text_field', $rule_in['channels']);
            $new_rule['roles'] = isset($rule_in['roles']) ? array_map('sanitize_text_field', $rule_in['roles']) : [];
            $new_rule['user_ids'] = isset($rule_in['user_ids']) ? array_map('intval', $rule_in['user_ids']) : [];
            add_option('wctn_rule_' . uniqid(), $new_rule, '', 'no');
        }
    }

    public static function get_all_rules()
    {
        global $wpdb;
        $rules = [];
        $like = 'wctn_rule_%';
        $results = $wpdb->get_results($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name LIKE %s", $like));
        foreach ($results as $r) {
            $rule = maybe_unserialize($r->option_value);
            if (is_array($rule) && !empty($rule['event'])) { $rules[] = $rule; }
        }
        return $rules;
    }

    public function handle_manual_queue_process()
    {
        if (!current_user_can('manage_options')) { wp_die('Access denied'); }
        check_admin_referer('wctn_process_queue', 'wctn_process_queue_nonce');
        $this->process_notification_queue();
        wp_redirect(add_query_arg(['wctn_queue_processed' => '1'], wp_get_referer()));
        exit;
    }

    public function maybe_show_admin_notices()
    {
        if (isset($_GET['wctn_success'])) { echo '<div class="notice notice-success is-dismissible"><p>' . __('عملیات با موفقیت انجام شد.', 'wc-telegram-notifier') . '</p></div>'; }
        if (isset($_GET['wctn_error'])) { echo '<div class="notice notice-error is-dismissible"><p>' . __('خطایی رخ داد. لطفا تنظیمات را بررسی کنید.', 'wc-telegram-notifier') . '</p></div>'; }
        if (isset($_GET['wctn_rules_saved'])) { echo '<div class="notice notice-success is-dismissible"><p>' . __('قوانین با موفقیت ذخیره شدند.', 'wc-telegram-notifier') . '</p></div>'; }
        if (isset($_GET['wctn_manual_send_success'])) { echo '<div class="notice notice-success is-dismissible"><p>' . __('پیام با موفقیت به صف اضافه شد.', 'wc-telegram-notifier') . '</p></div>'; }
        if (isset($_GET['wctn_manual_send_error'])) { echo '<div class="notice notice-error is-dismissible"><p>' . __('خطا: گیرنده یا متن پیام نمی‌تواند خالی باشد.', 'wc-telegram-notifier') . '</p></div>'; }
        if (isset($_GET['wctn_queue_processed'])) { echo '<div class="notice notice-success is-dismissible"><p>' . __('صف با موفقیت پردازش شد.', 'wc-telegram-notifier') . '</p></div>'; }
        // Inside the maybe_show_admin_notices function, add this line:
if (isset($_GET['wctn_reset_success'])) { echo '<div class="notice notice-success is-dismissible"><p>' . __('آمار و لاگ‌ها با موفقیت بازنشانی شدند.', 'wc-telegram-notifier') . '</p></div>'; }
    }

    private function render_rule_template($index, $rule, $all_events, $all_roles, $all_users, $is_template = false)
    {
        $rule_event = $rule['event'] ?? '';
        $rule_roles = $rule['roles'] ?? [];
        $rule_users = $rule['user_ids'] ?? [];
        $rule_channels = $rule['channels'] ?? ['telegram'];
        $base_name = esc_attr('wctn_rules[' . $index . ']');
        if ($is_template) { echo '<script type="text/template" id="wctn-rule-template">'; }
        ?>
<div class="wctn-rule" data-index="<?php echo esc_attr($index); ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h4><?php _e('قانون نوتیفیکیشن', 'wc-telegram-notifier'); ?></h4>
        <button type="button" class="button wctn-remove-rule"><?php _e('حذف', 'wc-telegram-notifier'); ?></button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:8px;">
        <div>
            <label><?php _e('رویداد', 'wc-telegram-notifier'); ?></label>
            <select name="<?php echo $base_name; ?>[event]" class="wctn-select2" style="width:100%;">
                <option value=""><?php _e('یک رویداد انتخاب کنید...', 'wc-telegram-notifier'); ?></option>
                <?php foreach ($all_events as $k => $label): ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected($k, $rule_event); ?>>
                    <?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?php _e('ارسال به نقش‌ها', 'wc-telegram-notifier'); ?></label>
            <select name="<?php echo $base_name; ?>[roles][]" class="wctn-select2" multiple style="width:100%;">
                <?php foreach ($all_roles as $k => $label): ?>
                <option value="<?php echo esc_attr($k); ?>" <?php echo in_array($k, $rule_roles) ? 'selected' : ''; ?>>
                    <?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?php _e('ارسال به کاربران', 'wc-telegram-notifier'); ?></label>
            <select name="<?php echo $base_name; ?>[user_ids][]" class="wctn-select2" multiple style="width:100%;">
                <?php foreach ($all_users as $user): ?>
                <option value="<?php echo esc_attr($user->ID); ?>"
                    <?php echo in_array($user->ID, $rule_users) ? 'selected' : ''; ?>>
                    <?php echo esc_html(sprintf('%s (%s) #%d', $user->display_name, $user->user_login, $user->ID)); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?php _e('ارسال از طریق', 'wc-telegram-notifier'); ?></label>
            <div>
                <label><input type="checkbox" name="<?php echo $base_name; ?>[channels][]" value="telegram"
                        <?php echo in_array('telegram', $rule_channels) ? 'checked' : ''; ?>>
                    <?php _e('تلگرام', 'wc-telegram-notifier'); ?></label><br>
                <label><input type="checkbox" name="<?php echo $base_name; ?>[channels][]" value="sms"
                        <?php echo in_array('sms', $rule_channels) ? 'checked' : ''; ?>>
                    <?php _e('پیامک', 'wc-telegram-notifier'); ?></label><br>
            </div>
        </div>
    </div>
</div>
<hr />
<?php
        if ($is_template) { echo '</script>'; }
    }

    private function get_available_events()
    {
        $events = [
            'wp_user_register' => __('ثبت‌نام کاربر جدید', 'wc-telegram-notifier'),
            'wp_profile_update' => __('ویرایش پروفایل کاربر', 'wc-telegram-notifier'),
            'wp_post_publish' => __('انتشار محتوای جدید', 'wc-telegram-notifier'),
            'wp_comment_new' => __('ثبت دیدگاه جدید', 'wc-telegram-notifier'),
        ];
        if (class_exists('WooCommerce')) {
            $events['woocommerce_new_order'] = __('ثبت سفارش جدید (WooCommerce)', 'wc-telegram-notifier');
            $wc_statuses = wc_get_order_statuses();
            foreach ($wc_statuses as $slug => $label) {
                $clean = str_replace('wc-', '', $slug);
                $events['woocommerce_order_status_' . $clean] = sprintf(__('وضعیت سفارش به %s تغییر کرد', 'wc-telegram-notifier'), $label);
            }
        }
        return $events;
    }

    private function get_all_roles()
    {
        global $wp_roles;
        if (!isset($wp_roles)) { $wp_roles = new WP_Roles(); }
        $roles = $wp_roles->roles;
        $out = [];
        foreach ($roles as $key => $data) {
            $out[$key] = translate_user_role($data['name']);
        }
        return $out;
    }

    /* ------------------------------
     * Profile fields
     * ------------------------------ */

    public function render_user_telegram_field($user)
    {
        $chat_id = get_user_meta($user->ID, '_wctn_telegram_chat_id', true);
        ?>
<h2>Telegram</h2>
<table class="form-table" role="presentation">
    <tr>
        <th><label for="wctn_telegram_chat_id">Telegram Chat ID</label></th>
        <td>
            <input type="text" name="wctn_telegram_chat_id" id="wctn_telegram_chat_id"
                value="<?php echo esc_attr($chat_id); ?>" class="regular-text ltr" />
            <p class="description">Chat ID عددی کاربر در تلگرام. کاربر باید به ربات پیام بدهد تا Chat ID قابل دریافت
                باشد.</p>
        </td>
    </tr>
</table>
<?php
    }

    public function save_user_telegram_field($user_id)
    {
        if (!current_user_can('edit_user', $user_id)) { return false; }
        if (isset($_POST['wctn_telegram_chat_id'])) {
            update_user_meta($user_id, '_wctn_telegram_chat_id', sanitize_text_field($_POST['wctn_telegram_chat_id']));
        }
    }

    public function render_user_mobile_field($user)
    {
        $mobile = get_user_meta($user->ID, '_wctn_mobile_number', true);
        ?>
<h2>شماره موبایل</h2>
<table class="form-table" role="presentation">
    <tr>
        <th><label for="wctn_mobile_number">شماره موبایل</label></th>
        <td>
            <input type="text" name="wctn_mobile_number" id="wctn_mobile_number"
                value="<?php echo esc_attr($mobile); ?>" class="regular-text ltr" />
            <p class="description">شماره موبایل را با کد کشور بدون فاصله وارد کنید (مثال: 98912xxxxxxx).</p>
        </td>
    </tr>
</table>
<?php
    }

    public function save_user_mobile_field($user_id)
    {
        if (!current_user_can('edit_user', $user_id)) { return false; }
        if (isset($_POST['wctn_mobile_number'])) {
            update_user_meta($user_id, '_wctn_mobile_number', sanitize_text_field($_POST['wctn_mobile_number']));
        }
    }

    /* ------------------------------
     * Event handlers
     * ------------------------------ */

    public function on_user_register($user_id)
    {
        $user = get_userdata($user_id);
        $msg = self::format_message(__('ثبت‌نام کاربر جدید', 'wc-telegram-notifier'), [
            __('نام', 'wc-telegram-notifier') => $user ? $user->display_name : ('#' . $user_id),
            __('شناسه', 'wc-telegram-notifier') => $user_id,
            __('نام‌کاربری', 'wc-telegram-notifier') => $user ? $user->user_login : '',
            __('ایمیل', 'wc-telegram-notifier') => $user ? $user->user_email : '',
        ]);
        self::dispatch('wp_user_register', $msg);
    }

    public function on_profile_update($user_id, $old_user_data)
{
    // --- شروع بخش اصلاح شده ---
    // متغیر سراسری $pagenow را دریافت می‌کنیم تا بفهمیم در کدام صفحه از پیشخوان هستیم.
    global $pagenow;
    
    // فقط اگر آپدیت پروفایل از صفحه خود پروفایل (profile.php) یا صفحه ویرایش کاربر (user-edit.php) انجام شده باشد، ادامه بده.
    // این کار جلوی اجرای اشتباه هوک هنگام ذخیره شدن نوشته را می‌گیرد.
    if (!in_array($pagenow, ['profile.php', 'user-edit.php'])) {
        return; // اگر در این صفحات نبودیم، کاری نکن و از تابع خارج شو.
    }
    // --- پایان بخش اصلاح شده ---

    // بقیه کد تابع بدون تغییر باقی می‌ماند
    $user = get_userdata($user_id);
    $msg = self::format_message(__('ویرایش پروفایل کاربر', 'wc-telegram-notifier'), [
        __('نام', 'wc-telegram-notifier') => $user ? $user->display_name : ('#' . $user_id),
        __('شناسه', 'wc-telegram-notifier') => $user_id,
        __('نام‌کاربری', 'wc-telegram-notifier') => $user ? $user->user_login : '',
        __('ایمیل', 'wc-telegram-notifier') => $user ? $user->user_email : '',
    ]);
    self::dispatch('wp_profile_update', $msg);
}

    public function on_transition_post_status($new, $old, $post)
    {
        if ('publish' !== $new || 'publish' === $old || wp_is_post_revision($post->ID)) { return; }
        $type_label = get_post_type_object($post->post_type);
        $type_name = $type_label ? $type_label->labels->singular_name : $post->post_type;
        $msg = self::format_message(__('انتشار محتوای جدید', 'wc-telegram-notifier'), [
            __('نوع', 'wc-telegram-notifier') => $type_name,
            __('عنوان', 'wc-telegram-notifier') => get_the_title($post),
            __('شناسه', 'wc-telegram-notifier') => $post->ID,
            __('نویسنده', 'wc-telegram-notifier') => get_the_author_meta('display_name', $post->post_author),
            __('لینک', 'wc-telegram-notifier') => get_permalink($post),
        ]);
        self::dispatch('wp_post_publish', $msg);
    }

    public function on_comment_post($comment_ID, $comment_approved, $commentdata)
    {
        if (1 != $comment_approved) { return; }
        $c = get_comment($comment_ID);
        if (!$c) { return; }
        $msg = self::format_message(__('دیدگاه جدید', 'wc-telegram-notifier'), [
            __('نویسنده', 'wc-telegram-notifier') => $c->comment_author,
            __('ایمیل', 'wc-telegram-notifier') => $c->comment_author_email,
            __('روی', 'wc-telegram-notifier') => get_the_title($c->comment_post_ID),
            __('متن', 'wc-telegram-notifier') => wp_trim_words(wp_strip_all_tags($c->comment_content), 30),
            __('لینک', 'wc-telegram-notifier') => get_comment_link($c),
        ]);
        self::dispatch('wp_comment_new', $msg);
    }

    /* ------------------------------
     * WooCommerce helpers
     * ------------------------------ */

    public function hook_woocommerce()
    {
        add_action('woocommerce_new_order', function ($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) { return; }
            $msg = self::format_order_message(__('سفارش جدید', 'wc-telegram-notifier'), $order);
            self::dispatch('woocommerce_new_order', $msg);
        }, 10, 1);
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 10, 4);
    }

    public function maybe_hook_woocommerce()
    {
        if (class_exists('WooCommerce')) {
            $this->hook_woocommerce();
        }
    }

    public function on_order_status_changed($order_id, $old_status, $new_status, $order)
    {
        if (!$order) { $order = wc_get_order($order_id); if (!$order) { return; } }
        if ($old_status === $new_status || 'checkout-draft' === $new_status) { return; }
        $event_key = 'woocommerce_order_status_' . $new_status;
        $all_statuses = wc_get_order_statuses();
        $title = $all_statuses['wc-' . $new_status] ?? $new_status;
        $msg = self::format_order_message(sprintf(__('وضعیت سفارش: %s', 'wc-telegram-notifier'), $title), $order);
        self::dispatch($event_key, $msg);
    }

    /* ------------------------------
     * Formatting & Dispatching
     * ------------------------------ */

    private static function format_message($title, $pairs = [])
    {
        $lines = [];
        $lines[] = '🔔 ' . $title; // Emoji is back!
        foreach ($pairs as $k => $v) {
            if ($v === '' || $v === null) { continue; }
            $lines[] = $k . ': ' . $v;
        }
        return implode("\n", $lines);
    }

    private static function format_order_message($title, $order, $refund_amount = '')
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name() . ' × ' . $item->get_quantity();
        }
        $pairs = [
            'شماره سفارش' => $order->get_order_number(),
            'وضعیت' => wc_get_order_status_name($order->get_status()),
            'مشتری' => trim($order->get_formatted_billing_full_name()),
            'مجموع' => strip_tags($order->get_formatted_order_total()),
            'اقلام' => implode(', ', $items),
            'لینک مدیریت' => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
        ];
        if ($refund_amount !== '') {
            if (function_exists('wc_price')) {
                $pairs['مبلغ بازپرداخت'] = strip_tags(wc_price($refund_amount));
            } else {
                $pairs['مبلغ بازپرداخت'] = $refund_amount;
            }
        }
        return self::format_message($title, $pairs);
    }

    private static function dispatch($event_key, $message)
    {
        $opts = self::get_settings();
        $rules = self::get_all_rules();
        $telegram_targets = []; $sms_targets = [];
        foreach ($rules as $rule) {
            if (isset($rule['event']) && $rule['event'] === $event_key) {
                $channels = $rule['channels'] ?? [];
                if (in_array('telegram', $channels, true)) {
                    $collected = self::collect_targets($rule, 'telegram');
                    $telegram_targets = array_merge($telegram_targets, $collected);
                }
                if (in_array('sms', $channels, true)) {
                    $collected = self::collect_targets($rule, 'sms');
                    $sms_targets = array_merge($sms_targets, $collected);
                }
            }
        }
        $telegram_targets = array_unique($telegram_targets);
        $sms_targets = array_unique($sms_targets);
        if (empty($telegram_targets) && empty($sms_targets)) { return; }
        $queue = get_option(self::QUEUE_OPTION_KEY, []); if (!is_array($queue)) { $queue = []; }
        foreach ($telegram_targets as $chat_id) { $queue[] = ['type' => 'telegram', 'to' => $chat_id, 'message' => $message, 'parse_mode' => $opts['parse_mode'] ?? 'HTML']; }
        foreach ($sms_targets as $mobile) { $queue[] = ['type' => 'sms', 'to' => $mobile, 'message' => wp_strip_all_tags($message)]; }
        update_option(self::QUEUE_OPTION_KEY, $queue, false);
    }

    private static function collect_targets($rule, $type)
    {
        $targets = [];
        if (!empty($rule['roles'])) {
            $users = get_users(['role__in' => $rule['roles'], 'fields' => ['ID']]);
            foreach ($users as $user) {
                $meta_key = '_wctn_' . ($type === 'telegram' ? 'telegram_chat_id' : 'mobile_number');
                $value = get_user_meta($user->ID, $meta_key, true);
                if ($value) { $targets[] = $value; }
            }
        }
        if (!empty($rule['user_ids'])) {
            foreach ($rule['user_ids'] as $uid) {
                $meta_key = '_wctn_' . ($type === 'telegram' ? 'telegram_chat_id' : 'mobile_number');
                $value = get_user_meta($uid, $meta_key, true);
                if ($value) { $targets[] = $value; }
            }
        }
        return array_unique($targets);
    }

    /* ------------------------------
     * Core Logic: Queue & Sending
     * ------------------------------ */

    public function add_cron_schedules($schedules)
    {
        if (!isset($schedules['minutely'])) {
            $schedules['minutely'] = ['interval' => 60, 'display' => esc_html__('Every Minute')];
        }
        return $schedules;
    }

    /**
     * Processes the notification queue with a robust, self-healing mechanism.
     * This version is clean and logs only critical errors.
     */
    public function process_notification_queue()
    {
        $queue = get_option(self::QUEUE_OPTION_KEY);
        if (empty($queue) || !is_array($queue)) {
            return;
        }

        $instance = self::get_instance();
        $failed_queue = [];

        foreach ($queue as $index => $item) {
            // --- ROBUST SETTINGS LOADING ---
            global $wpdb;
            $db_value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", self::OPTION_KEY));
            $current_opts = maybe_unserialize($db_value);

            if (!is_array($current_opts)) {
                $failed_queue[] = $item;
                continue;
            }
            $current_opts = wp_parse_args($current_opts, self::defaults());
            // --- END SETTINGS LOADING ---

            $sent_successfully = false;

            try {
                switch ($item['type']) {
                    case 'telegram':
                        if (!empty($current_opts['bot_token'])) {
                            $result = $instance->send_telegram($current_opts['bot_token'], $item['to'], $item['message'], $item['parse_mode'] ?? 'HTML', true);
                            if ($result && isset($result['ok']) && $result['ok']) {
                                $sent_successfully = true;
                                $this->update_stats('telegram', 'sent');
                            } else {
                                $this->update_stats('telegram', 'failed');
                            }
                        }
                        break;

                    case 'sms':
                        if (empty($current_opts['kavenegar_api_key']) || empty($current_opts['kavenegar_sender'])) {
                            break;
                        }

                        // The message is now used as-is, including the emoji.
                        $sms_message = wp_strip_all_tags($item['message']);
                        
                        $result = $instance->send_sms_kavenegar(
                            $current_opts['kavenegar_api_key'],
                            $current_opts['kavenegar_sender'],
                            $item['to'],
                            $sms_message,
                            true
                        );

                        if ($result && isset($result['ok']) && $result['ok']) {
                            $sent_successfully = true;
                            $this->update_stats('sms', 'sent');
                        } else {
                            $this->update_stats('sms', 'failed');
                        }
                        break;
                }
            } catch (Exception $e) {
                error_log('WCTN Queue: Exception for item ' . $index . ': ' . $e->getMessage());
            }
            
            // Log the attempt
 $this->log_send_attempt(
    $item['type'],
    $item['to'],
    $item['message'],
    $sent_successfully ? 'sent' : 'failed',
    $error_message ?? ''
);


            if (!$sent_successfully) {
                $failed_queue[] = $item;
            }
        }

        update_option(self::QUEUE_OPTION_KEY, $failed_queue, false);
    }

   public function send_telegram($bot_token, $chat_id, $message, $parse_mode = 'HTML', $need_response = true)
{
    // Load settings early so we can allow proxy-based sending without bot token
    $opts = self::get_settings();

    if (empty($chat_id) || empty($message)) {
        return $need_response ? ['ok' => false, 'error' => 'missing-params'] : [];
    }

    // If no proxy is configured, require bot token (legacy fallback)
    if (empty($opts['proxy_url']) && empty($bot_token)) {
        return $need_response ? ['ok' => false, 'error' => 'missing-bot-token'] : [];
    }
    $url = !empty($opts['proxy_url']) ? rtrim($opts['proxy_url'], '/') : '';
    if (empty($url)) {
        return $need_response ? ['ok' => false, 'error' => 'missing-proxy-url'] : [];
    }

    $body = [
        'api_key'   => $opts['proxy_api_key'] ?? '',
        'chat_id'   => $chat_id,
        'text'      => $message,
        'parse_mode'=> $parse_mode ?: 'HTML',
    ];

    


    $args = [
    'method'  => 'POST',
    'headers' => [
        'Content-Type' => 'application/json'
    ],
    'body'        => wp_json_encode($body), // استفاده از تابع استاندارد وردپرس
        'timeout'     => 30,
        'redirection' => 5,
        'httpversion' => '1.0',
        'blocking'    => true,
];

$response = wp_remote_post($url, $args);

if (is_wp_error($response)) {
    error_log('WCTN Worker: WP_Error: ' . $response->get_error_message());
} else {
    $code = wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);
    error_log('WCTN Worker Response Code: ' . $code . ' Body: ' . substr($raw, 0, 2000));
}

    if (!$need_response) {
        return [];
    }

    if (is_wp_error($response)) {
        return ['ok' => false, 'error' => $response->get_error_message()];
    }

    $code = wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);
    
    if ($code !== 200) {
        return ['ok' => false, 'error' => "Worker Error (Status $code): " . $raw];
    }

    $json = json_decode($raw, true);


    if (isset($json['ok']) && $json['ok'] == true) {
        return ['ok' => true, 'error' => ''];
    }
    
return ['ok' => false, 'error' => isset($json['description']) ? $json['description'] : $raw];
}


    public function send_sms_kavenegar($api_key, $sender, $receptor, $message, $is_test = false)
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            global $wpdb;
            $db_value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", self::OPTION_KEY));
            $current_opts = maybe_unserialize($db_value);
            if (is_array($current_opts) && !empty($current_opts['kavenegar_api_key'])) {
                $api_key = $current_opts['kavenegar_api_key'];
                $sender   = $current_opts['kavenegar_sender'];
            } else {
                return ['ok' => false, 'error' => 'Could not load settings in cron context.'];
            }
        }

        $url = 'https://api.kavenegar.com/v1/' . $api_key . '/sms/send.json';
        $body = ['receptor' => $receptor, 'sender' => $sender, 'message' => $message];
        $response = wp_remote_post($url, ['body' => $body, 'timeout' => 15]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (isset($result['return']['status']) && $result['return']['status'] == 200) {
            return ['ok' => true];
        } else {
            return ['ok' => false, 'error' => $result['return']['message'] ?? 'Unknown'];
        }
    }
    
    
    /**
 * Converts a UTC datetime string to the timezone set in plugin settings.
 */
private function get_formatted_datetime($datetime_string)
{
    $opts = self::get_settings();
    $timezone = new DateTimeZone($opts['timezone']);
    $datetime = new DateTime($datetime_string, new DateTimeZone('UTC')); // Assume DB is in UTC
    $datetime->setTimezone($timezone);
    return $datetime->format('Y-m-d H:i:s');
}




/**
 * Logs a send attempt to the database.
 */
private function log_send_attempt($type, $recipient, $message, $status, $error_message = '')
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'wctn_logs';
    $wpdb->insert(
        $table_name,
        [
            'timestamp' => current_time('mysql'), // Uses WordPress timezone
            'type' => $type,
            'recipient' => $recipient,
            'message' => substr($message, 0, 500), // Limit message length
            'status' => $status,
            'error_message' => $error_message,
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s']
    );
}



/**
 * Renders the logs page in the admin dashboard.
 */
public function render_logs_page()
{
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table_name = $wpdb->prefix . 'wctn_logs';
    $per_page = 50;
    $current_page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    $total_items = $wpdb->get_var("SELECT COUNT(id) FROM $table_name");
    $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d OFFSET %d", $per_page, $offset));

    ?>
<div class="wrap">
    <h1><?php _e('لاگ ارسال‌ها', 'wc-telegram-notifier'); ?></h1>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php _e('تاریخ و ساعت', 'wc-telegram-notifier'); ?></th>
                <th scope="col"><?php _e('نوع', 'wc-telegram-notifier'); ?></th>
                <th scope="col"><?php _e('گیرنده', 'wc-telegram-notifier'); ?></th>
                <th scope="col"><?php _e('پیام', 'wc-telegram-notifier'); ?></th>
                <th scope="col"><?php _e('وضعیت', 'wc-telegram-notifier'); ?></th>
                <th scope="col"><?php _e('خطا', 'wc-telegram-notifier'); ?></th>
            </tr>
            <tr>
                <th><label for="wctn_proxy_url"><?php _e('Proxy URL (Worker)', 'wc-telegram-notifier'); ?></label></th>
                <td>
                    <input type="text" id="wctn_proxy_url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[proxy_url]" value="<?php echo esc_attr($opts['proxy_url'] ?? ''); ?>" class="regular-text ltr" />
                    <p class="description"><?php _e('آدرس کامل ورکر کلودفلر (مثال: https://<your>-workers.dev).', 'wc-telegram-notifier'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wctn_proxy_api_key"><?php _e('Proxy API Key', 'wc-telegram-notifier'); ?></label></th>
                <td>
                    <input type="text" id="wctn_proxy_api_key" name="<?php echo esc_attr(self::OPTION_KEY); ?>[proxy_api_key]" value="<?php echo esc_attr($opts['proxy_api_key'] ?? ''); ?>" class="regular-text ltr" />
                    <p class="description"><?php _e('کلید API که ورکر بررسی می‌کند (بدون کاراکترهای اضافی).', 'wc-telegram-notifier'); ?></p>
                </td>
            </tr>
        </thead>
        <tbody>
            <?php if ($logs): ?>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td><?php echo esc_html($this->get_formatted_datetime($log->timestamp)); ?></td>
                <td><?php echo esc_html(ucfirst($log->type)); ?></td>
                <td><?php echo esc_html($log->recipient); ?></td>
                <td><?php echo esc_html(substr($log->message, 0, 100)); ?>...</td>
                <td>
                    <?php if ($log->status === 'sent'): ?>
                    <span style="color: #46b450;"><?php _e('موفق', 'wc-telegram-notifier'); ?></span>
                    <?php else: ?>
                    <span style="color: #dc3232;"><?php _e('ناموفق', 'wc-telegram-notifier'); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($log->error_message); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr>
                <td colspan="6"><?php _e('هیچ لاگی یافت نشد.', 'wc-telegram-notifier'); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
        if ($total_items > $per_page) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links([
                'base' => add_query_arg('p', '%#%', admin_url('admin.php?page=wctn_logs')),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => ceil($total_items / $per_page),
                'current' => $current_page,
            ]);
            echo '</div></div>';
        }
        ?>
</div>
<?php
}


/**
 * Handles the reset stats action from the dashboard.
 */
public function handle_reset_stats()
{
    if (!current_user_can('manage_options')) wp_die('Access denied');
    check_admin_referer('wctn_reset_stats', 'wctn_reset_stats_nonce');
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wctn_stats");
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wctn_logs");
    wp_redirect(add_query_arg(['wctn_reset_success' => '1'], wp_get_referer()));
    exit;
}


}

// Register activation/deactivation hooks
register_activation_hook(__FILE__, ['WCTelegramNotifier', 'on_activation']);
register_deactivation_hook(__FILE__, ['WCTelegramNotifier', 'on_deactivation']);

// Instantiate the plugin
new WCTelegramNotifier();