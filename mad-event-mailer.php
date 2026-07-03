<?php
/**
 * Plugin Name: MAD Event Mailer
 * Description: An HTML email delivery plugin for event notifications. Supports SMTP, template variables, CSV recipients, event subscriptions, shortcode registration, batch sending, scheduled sending and language packs.
 * Version: 2.2.2
 * Author: MAD Producer Studio
 * Author URI: https://github.com/MAD-Producer
 * License: GPL v2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mad-event-mailer
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

class MAD_Event_Mailer {
    const VERSION = '2.2.2';
    const OPT = 'mad_em_settings';
    const CRON = 'mad_em_process_campaigns';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'maybe_start_i18n_buffer'], 0);
        add_action('admin_init', [__CLASS__, 'maybe_handle_post']);
        add_action('admin_init', [__CLASS__, 'maybe_handle_get']);
        add_action('admin_post_mad_em_export_template_csv', [__CLASS__, 'export_template_csv']);
        add_action('admin_post_mad_em_preview_template', [__CLASS__, 'preview_template_page']);
        add_action('wp_ajax_mad_em_preview_send', [__CLASS__, 'ajax_preview_send']);
        add_action('wp_ajax_mad_em_test_send', [__CLASS__, 'ajax_test_send']);
        add_action('phpmailer_init', [__CLASS__, 'smtp_config']);
        add_shortcode('mad_email_register', [__CLASS__, 'shortcode_register']);
        add_action('init', [__CLASS__, 'handle_public_register']);
        add_action(self::CRON, [__CLASS__, 'process_campaigns']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action('init', [__CLASS__, 'maybe_upgrade'], 1);
    }

    public static function maybe_upgrade() {
        if (get_option('mad_em_version') !== self::VERSION) {
            self::activate();
            update_option('mad_em_version', self::VERSION);
        }
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'mad_em_';

        dbDelta("CREATE TABLE {$prefix}templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            summary TEXT NULL,
            html LONGTEXT NOT NULL,
            variables LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset;");

        dbDelta("CREATE TABLE {$prefix}events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            description TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset;");

        dbDelta("CREATE TABLE {$prefix}subscribers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            name VARCHAR(190) DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'subscribed',
            source VARCHAR(50) DEFAULT 'manual',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) $charset;");

        dbDelta("CREATE TABLE {$prefix}subscriber_events (
            subscriber_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (subscriber_id,event_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$prefix}campaigns (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            template_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NULL,
            variables LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            scheduled_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            total INT NOT NULL DEFAULT 0,
            sent INT NOT NULL DEFAULT 0,
            failed INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY status (status),
            KEY scheduled_at (scheduled_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$prefix}campaign_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            subscriber_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error TEXT NULL,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_subscriber (campaign_id,subscriber_id),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE {$prefix}campaign_recipient_vars (
            campaign_id BIGINT UNSIGNED NOT NULL,
            subscriber_id BIGINT UNSIGNED NOT NULL,
            variables LONGTEXT NULL,
            PRIMARY KEY (campaign_id,subscriber_id)
        ) $charset;");

        self::seed_defaults();
        // 退订按钮现在由发送任务选项动态追加，不再直接写入模板。
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 60, 'mad_em_five_minutes', self::CRON);
        update_option('mad_em_version', self::VERSION);
    }

    public static function deactivate() { wp_clear_scheduled_hook(self::CRON); }

    public static function cron_schedules($schedules) {
        $schedules['mad_em_five_minutes'] = ['interval' => 300, 'display' => '每 5 分钟'];
        return $schedules;
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'mad_em_' . $name; }
    private static function now() { return current_time('mysql'); }
    private static function settings() { return wp_parse_args(get_option(self::OPT, []), [
        'host'=>'', 'port'=>'465', 'secure'=>'ssl', 'username'=>'', 'password'=>'', 'from_email'=>'', 'from_name'=>'No-reply', 'sender_name'=>'', 'reply_to'=>'', 'batch_size'=>30, 'register_page_url'=>'', 'default_unsubscribe_button'=>1, 'default_unsubscribe_lang'=>'zh', 'ui_language'=>'auto', 'public_language'=>'auto'
    ]); }

    private static function current_ui_language() {
        $s = self::settings();
        $lang = $s['ui_language'] ?? 'auto';
        if ($lang === 'auto') $lang = get_locale();
        return $lang;
    }

    private static function current_public_language() {
        $s = self::settings();
        $lang = $s['public_language'] ?? 'auto';
        if ($lang === 'auto') $lang = get_locale();
        return $lang;
    }

    private static function is_english_language($lang) {
        return stripos((string)$lang, 'en') === 0;
    }

    private static function language_pack($lang = null) {
        $lang = $lang ?: self::current_ui_language();
        if (!self::is_english_language($lang)) return [];
        $file = plugin_dir_path(__FILE__) . 'languages/en_US.php';
        return file_exists($file) ? (array) include $file : [];
    }

    public static function maybe_start_i18n_buffer() {
        if (!is_admin()) return;
        if (empty($_GET['page']) || strpos(sanitize_text_field(wp_unslash($_GET['page'])), 'mad-em') !== 0) return;
        if (!self::is_english_language(self::current_ui_language())) return;
        ob_start([__CLASS__, 'translate_admin_output']);
    }

    public static function translate_admin_output($html) {
        return self::translate_text($html, self::current_ui_language());
    }

    private static function translate_text($text, $lang = null) {
        $pack = self::language_pack($lang);
        if (!$pack) return $text;
        uksort($pack, function($a, $b) { return strlen($b) <=> strlen($a); });
        return strtr($text, $pack);
    }

    private static function tr($text, $lang = null) {
        return self::translate_text($text, $lang);
    }


    private static function seed_defaults() {
        global $wpdb;
        $t = self::table('templates');
        $exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) );
        if ($exists) return;
        foreach ([['默认中文模板', 'default-template-zh.html'], ['默认英文模板', 'default-template-en.html']] as $item) {
            $file = plugin_dir_path(__FILE__) . $item[1];
            if (file_exists($file)) {
                $html = self::read_local_file($file);
                $wpdb->insert($t, [
                    'name'=>$item[0], 'subject'=>'{{title1}}', 'summary'=>'内置示例模板', 'html'=>$html,
                    'variables'=>wp_json_encode(self::extract_vars($html . ' {{title1}}')), 'created_at'=>self::now(), 'updated_at'=>self::now()
                ]);
            }
        }
    }

    private static function upgrade_templates_unsubscribe() {
        global $wpdb;
        $table = self::table('templates');
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, subject, html FROM %i', $table ) );
        foreach ($rows as $row) {
            if (stripos((string)$row->html, '{{unsubscribe_url}}') !== false) continue;
            $html = self::ensure_unsubscribe_notice((string)$row->html);
            $wpdb->update($table, [
                'html' => $html,
                'variables' => wp_json_encode(self::extract_vars($html . ' ' . $row->subject)),
                'updated_at' => self::now()
            ], ['id' => (int)$row->id]);
        }
    }

    public static function smtp_config($phpmailer) {
        $s = self::settings();
        if (empty($s['host']) || empty($s['username'])) return;
        $phpmailer->isSMTP();
        $phpmailer->Host = $s['host'];
        $phpmailer->SMTPAuth = true;
        $phpmailer->Port = (int)$s['port'];
        $phpmailer->Username = $s['username'];
        $phpmailer->Password = $s['password'];
        $phpmailer->SMTPSecure = $s['secure'];
        $display_name = !empty($s['sender_name']) ? $s['sender_name'] : ($s['from_name'] ?: 'No-reply');
        if (!empty($s['from_email'])) $phpmailer->setFrom($s['from_email'], $display_name, false);
        if (!empty($s['from_email'])) $phpmailer->FromName = $display_name;
        if (!empty($s['reply_to'])) $phpmailer->addReplyTo($s['reply_to']);
    }

    public static function menu() {
        add_menu_page('MAD 活动邮件系统', 'MAD 邮件', 'manage_options', 'mad-em', [__CLASS__, 'page_send'], 'dashicons-email-alt2', 58);
        add_submenu_page('mad-em', '发送 / 定时发送', '发送 / 定时发送', 'manage_options', 'mad-em', [__CLASS__, 'page_send']);
        add_submenu_page('mad-em', '邮件模板', '邮件模板', 'manage_options', 'mad-em-templates', [__CLASS__, 'page_templates']);
        add_submenu_page('mad-em', '活动管理', '活动管理', 'manage_options', 'mad-em-events', [__CLASS__, 'page_events']);
        add_submenu_page('mad-em', '收件人', '收件人', 'manage_options', 'mad-em-subscribers', [__CLASS__, 'page_subscribers']);
        add_submenu_page('mad-em', '发送任务', '发送任务', 'manage_options', 'mad-em-campaigns', [__CLASS__, 'page_campaigns']);
        add_submenu_page('mad-em', 'SMTP 设置', 'SMTP 设置', 'manage_options', 'mad-em-settings', [__CLASS__, 'page_settings']);
    }

    private static function notice($msg, $type='success') { echo '<div class="notice notice-'.esc_attr($type).' is-dismissible"><p>'.esc_html($msg).'</p></div>'; }
    private static function status_label($status) { $map = ['subscribed'=>'已订阅','unsubscribed'=>'已退订','draft'=>'草稿','scheduled'=>'已定时','queued'=>'排队中','sending'=>'发送中','finished'=>'已完成','pending'=>'待发送','sent'=>'已发送','failed'=>'发送失败']; return $map[$status] ?? $status; }
    private static function wrap_start($title) { echo '<div class="wrap"><h1>'.esc_html($title).'</h1>'; }
    private static function wrap_end() { echo '</div>'; }
    private static function nonce($action) { wp_nonce_field($action, 'mad_em_nonce'); echo '<input type="hidden" name="mad_em_action" value="'.esc_attr($action).'">'; }
    private static function verify($action) { return current_user_can('manage_options'); }

    private static function auto_vars() { return ['name','name1','email','unsubscribe_url']; }
    private static function title_vars() { return ['title','title1']; }
    private static function body_vars() { return ['message','message1']; }
    private static function system_vars() { return array_merge(self::auto_vars(), self::title_vars()); }
    private static function editable_vars($vars) { return array_values(array_diff($vars, self::system_vars())); }
    private static function csv_escape($v) { return str_replace(["\r","\n"], ' ', (string)$v); }

    private static function csv_line($fields) {
        $out = [];
        foreach ((array) $fields as $field) {
            $field = (string) $field;
            $field = str_replace('"', '""', $field);
            $out[] = '"' . $field . '"';
        }
        return implode(',', $out) . "\r\n";
    }

    private static function allowed_email_html() {
        $allowed = wp_kses_allowed_html('post');
        $extra_attrs = [
            'style' => true,
            'class' => true,
            'id' => true,
            'width' => true,
            'height' => true,
            'align' => true,
            'valign' => true,
            'cellpadding' => true,
            'cellspacing' => true,
            'border' => true,
            'role' => true,
            'aria-label' => true,
        ];
        foreach (['div','span','p','table','thead','tbody','tfoot','tr','td','th','h1','h2','h3','h4','h5','h6','ul','ol','li','strong','em','b','i','br','hr','center'] as $tag) {
            $allowed[$tag] = isset($allowed[$tag]) ? array_merge($allowed[$tag], $extra_attrs) : $extra_attrs;
        }
        $allowed['a'] = isset($allowed['a']) ? array_merge($allowed['a'], $extra_attrs, ['href'=>true,'target'=>true,'rel'=>true,'title'=>true]) : array_merge($extra_attrs, ['href'=>true,'target'=>true,'rel'=>true,'title'=>true]);
        $allowed['img'] = isset($allowed['img']) ? array_merge($allowed['img'], $extra_attrs, ['src'=>true,'alt'=>true,'title'=>true]) : array_merge($extra_attrs, ['src'=>true,'alt'=>true,'title'=>true]);
        $allowed['style'] = ['type' => true, 'media' => true];
        return $allowed;
    }

    private static function safe_email_html($html) {
        return wp_kses((string) $html, self::allowed_email_html());
    }

    private static function read_local_file($path) {
        $path = (string) $path;
        if ($path === '' || !file_exists($path) || !is_readable($path)) return '';
        global $wp_filesystem;
        if (!function_exists('WP_Filesystem')) require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        if ($wp_filesystem) {
            $content = $wp_filesystem->get_contents($path);
            return false === $content ? '' : (string) $content;
        }
        return '';
    }

    private static function parse_csv_file($path) {
        $content = self::read_local_file($path);
        if ($content === '') return [];
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $rows[] = str_getcsv($line);
        }
        return $rows;
    }

    private static function valid_uploaded_file($key, $extensions = ['csv']) {
        if (empty($_FILES[$key]) || !is_array($_FILES[$key])) return '';
        $file = wp_unslash($_FILES[$key]);
        $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        $name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) return '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $extensions, true)) return '';
        if ($size > 1024 * 1024) return '';
        return $tmp;
    }

    public static function maybe_handle_get() {
        // Legacy GET export handler intentionally disabled. CSV export now uses admin-post.php with a required nonce.
        return;
    }

    public static function export_template_csv() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'mad-event-mailer'));
        $template_id = absint($_GET['template_id'] ?? $_GET['mad_em_export_template_csv'] ?? 0);
        if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mad_em_export_template_csv_'.$template_id)) wp_die(esc_html__('The link has expired. Please return to the admin page and export again.', 'mad-event-mailer'));
        global $wpdb;
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_die(esc_html__('Template not found.', 'mad-event-mailer'));
        $vars = self::editable_vars(self::extract_vars($template->html . ' ' . $template->subject));
        $headers = array_merge(['email','name','events'], $vars);
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=mad-mailer-recipients-template-'.$template_id.'.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 BOM for CSV download.
        echo "\xEF\xBB\xBF";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download output, not HTML.
        echo self::csv_line($headers);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download output, not HTML.
        echo self::csv_line(array_merge(['example@example.com','张三','活动名称或 slug'], array_fill(0, count($vars), '')));
        exit;
    }


    private static function export_url($template_id) {
        $template_id = (int)$template_id;
        if (!$template_id) return '#';
        return wp_nonce_url(admin_url('admin-post.php?action=mad_em_export_template_csv&template_id='.$template_id), 'mad_em_export_template_csv_'.$template_id);
    }

    private static function preview_url($template_id) {
        $template_id = (int)$template_id;
        if (!$template_id) return '#';
        return wp_nonce_url(admin_url('admin-post.php?action=mad_em_preview_template&template_id='.$template_id), 'mad_em_preview_template_'.$template_id);
    }

    public static function preview_template_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'mad-event-mailer'));
        $template_id = absint($_GET['template_id'] ?? 0);
        if (!$template_id || !isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mad_em_preview_template_'.$template_id)) wp_die(esc_html__('The preview link has expired. Please open the preview again.', 'mad-event-mailer'));
        global $wpdb;
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_die(esc_html__('Template not found.', 'mad-event-mailer'));
        $vars = self::extract_vars($template->html . ' ' . $template->subject);
        $sample = [];
        foreach ($vars as $v) {
            if (in_array($v, ['name','name1'], true)) $sample[$v] = '张三';
            elseif (in_array($v, ['title','title1'], true)) $sample[$v] = '示例邮件标题';
            elseif ($v === 'email') $sample[$v] = 'example@example.com';
            elseif ($v === 'unsubscribe_url') $sample[$v] = self::get_unsubscribe_url();
            else $sample[$v] = '示例 '.$v;
        }
        $sample['unsubscribe_url'] = self::get_unsubscribe_url();
        header('Content-Type: text/html; charset=UTF-8');
        $set = self::settings();
        echo self::safe_email_html( self::render_template( self::ensure_unsubscribe_notice( (string) $template->html, sanitize_text_field( $set['default_unsubscribe_lang'] ?? 'zh' ), ! empty( $set['default_unsubscribe_button'] ) ), $sample ) );
        exit;
    }

    public static function ajax_preview_send() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'权限不足。'], 403);
        check_ajax_referer('mad_em_preview_send', 'nonce');
        global $wpdb;
        $template_id = absint(wp_unslash($_POST['template_id'] ?? 0));
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_send_json_error(['message'=>'模板不存在。']);
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $vars = [];
        $posted_var = isset($_POST['var']) && is_array($_POST['var']) ? wp_unslash($_POST['var']) : [];
        if (!empty($posted_var)) {
            foreach ($posted_var as $k=>$v) { $key=sanitize_key($k); if ($key !== '') $vars[$key] = self::sanitize_var_value($key, $v); }
        }
        $vars['name'] = '张三';
        $vars['name1'] = '张三';
        $vars['email'] = 'example@example.com';
        $vars['title'] = $subject ?: '示例邮件标题';
        $vars['title1'] = $subject ?: '示例邮件标题';
        $vars['unsubscribe_url'] = self::get_unsubscribe_url();
        $include_unsub = !empty($_POST['include_unsubscribe']);
        $unsub_lang_value = sanitize_text_field(wp_unslash($_POST['unsubscribe_lang'] ?? 'zh'));
        $unsub_lang = in_array($unsub_lang_value, ['zh','en'], true) ? $unsub_lang_value : 'zh';
        foreach (self::editable_vars(self::extract_vars($template->html . ' ' . implode(' ', array_map('strval', $vars)))) as $v) {
            if (!isset($vars[$v]) || $vars[$v] === '') $vars[$v] = '示例 '.$v;
        }
        $html = self::render_template(self::ensure_unsubscribe_notice($template->html, $unsub_lang, $include_unsub), $vars);
        wp_send_json_success(['html'=>self::safe_email_html($html)]);
    }


    public static function ajax_test_send() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'权限不足。'], 403);
        check_ajax_referer('mad_em_preview_send', 'nonce');
        global $wpdb;
        $to = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        if (!is_email($to)) wp_send_json_error(['message'=>'请填写有效的测试邮箱。']);
        $template_id = absint(wp_unslash($_POST['template_id'] ?? 0));
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_send_json_error(['message'=>'模板不存在。']);
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? '测试邮件'));
        $vars = [];
        $posted_var = isset($_POST['var']) && is_array($_POST['var']) ? wp_unslash($_POST['var']) : [];
        if (!empty($posted_var)) {
            foreach ($posted_var as $k=>$v) { $key=sanitize_key($k); if ($key !== '') $vars[$key] = self::sanitize_var_value($key, $v); }
        }
        $posted_test_var = isset($_POST['test_var']) && is_array($_POST['test_var']) ? wp_unslash($_POST['test_var']) : [];
        if (!empty($posted_test_var)) {
            foreach ($posted_test_var as $k=>$v) { $key=sanitize_key($k); if ($key !== '') $vars[$key] = self::sanitize_var_value($key, $v); }
        }
        $vars['email'] = $to;
        $vars['name'] = $vars['name'] ?? '测试收件人';
        $vars['name1'] = $vars['name1'] ?? $vars['name'];
        $vars['title'] = $subject ?: '测试邮件';
        $vars['title1'] = $subject ?: '测试邮件';
        $vars['unsubscribe_url'] = self::get_unsubscribe_url();
        $include_unsub = !empty($_POST['include_unsubscribe']);
        $unsub_lang_value = sanitize_text_field(wp_unslash($_POST['unsubscribe_lang'] ?? 'zh'));
        $unsub_lang = in_array($unsub_lang_value, ['zh','en'], true) ? $unsub_lang_value : 'zh';
        foreach (self::editable_vars(self::extract_vars($template->html . ' ' . $template->subject . ' ' . implode(' ', array_map('strval',$vars)))) as $v) {
            if (!isset($vars[$v]) || $vars[$v] === '') $vars[$v] = '测试 '.$v;
        }
        $body = self::render_template(self::ensure_unsubscribe_notice($template->html, $unsub_lang, $include_unsub), $vars);
        $subj = self::render_template($subject ?: $template->subject, $vars);
        $ok = wp_mail($to, $subj, $body, ['Content-Type: text/html; charset=UTF-8']);
        if (!$ok) wp_send_json_error(['message'=>'测试邮件发送失败，请检查 SMTP 设置或服务器日志。']);
        wp_send_json_success(['message'=>'测试邮件已发送到 '.$to]);
    }

    public static function maybe_handle_post() {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
        if (empty($_POST['mad_em_action'])) return;
        $action = sanitize_text_field(wp_unslash($_POST['mad_em_action']));
        $nonce_action = in_array($action, ['save_campaign_draft','export_current_csv','preview_current_static'], true) ? 'create_campaign' : $action;
        if (!current_user_can('manage_options')) return;
        check_admin_referer($nonce_action, 'mad_em_nonce');
        global $wpdb;

        if ($action === 'save_settings') {
            $sender_name = sanitize_text_field(wp_unslash($_POST['sender_name'] ?? ($_POST['from_name'] ?? 'No-reply')));
            update_option(self::OPT, [
                'host'=>sanitize_text_field(wp_unslash($_POST['host'] ?? '')), 'port'=>sanitize_text_field(wp_unslash($_POST['port'] ?? '465')),
                'secure'=>sanitize_text_field(wp_unslash($_POST['secure'] ?? 'ssl')), 'username'=>sanitize_text_field(wp_unslash($_POST['username'] ?? '')),
                'password'=>sanitize_text_field(wp_unslash($_POST['password'] ?? '')), 'from_email'=>sanitize_email(wp_unslash($_POST['from_email'] ?? '')),
                'from_name'=>$sender_name, 'sender_name'=>$sender_name, 'reply_to'=>sanitize_email(wp_unslash($_POST['reply_to'] ?? '')),
                'batch_size'=>max(1, (int) sanitize_text_field(wp_unslash($_POST['batch_size'] ?? 30))),
                'register_page_url'=>esc_url_raw(wp_unslash($_POST['register_page_url'] ?? '')),
                'default_unsubscribe_button'=>!empty($_POST['default_unsubscribe_button']) ? 1 : 0,
                'default_unsubscribe_lang'=>in_array(sanitize_text_field(wp_unslash($_POST['default_unsubscribe_lang'] ?? 'zh')), ['zh','en'], true) ? sanitize_text_field(wp_unslash($_POST['default_unsubscribe_lang'])) : 'zh',
                'ui_language'=>in_array(sanitize_text_field(wp_unslash($_POST['ui_language'] ?? 'auto')), ['zh_CN','en_US','auto'], true) ? sanitize_text_field(wp_unslash($_POST['ui_language'])) : 'auto',
                'public_language'=>in_array(sanitize_text_field(wp_unslash($_POST['public_language'] ?? 'auto')), ['zh_CN','en_US','auto'], true) ? sanitize_text_field(wp_unslash($_POST['public_language'])) : 'auto'
            ]);
            add_action('admin_notices', fn()=>self::notice('设置已保存。发件人姓名已更新为：'.$sender_name));
        }


        if ($action === 'save_template') {
            $id = absint(wp_unslash($_POST['id'] ?? 0));
            $html = self::safe_email_html(wp_unslash($_POST['html'] ?? ''));
            $html_file = self::valid_uploaded_file('html_file', ['html','htm']);
            if ($html_file) $html = self::safe_email_html(self::read_local_file($html_file));
            $data = [
                'name'=>sanitize_text_field(wp_unslash($_POST['name'] ?? '')), 'subject'=>sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
                'summary'=>sanitize_textarea_field(wp_unslash($_POST['summary'] ?? '')), 'html'=>$html,
                'variables'=>wp_json_encode(self::extract_vars($html . ' ' . sanitize_text_field(wp_unslash($_POST['subject'] ?? '')))), 'updated_at'=>self::now()
            ];
            if ($id) $wpdb->update(self::table('templates'), $data, ['id'=>$id]);
            else { $data['created_at'] = self::now(); $wpdb->insert(self::table('templates'), $data); }
            add_action('admin_notices', fn()=>self::notice('模板已保存。'));
        }

        if ($action === 'save_quick_template') {
            $base_id = absint(wp_unslash($_POST['base_template_id'] ?? 0));
            $base = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $base_id ) );
            if ($base) {
                $body = wp_kses_post(wp_unslash($_POST['quick_body'] ?? ''));
                $html = (string)$base->html;
                if (stripos($html, '{{message1}}') !== false) $html = preg_replace('/{{\s*message1\s*}}/i', $body, $html);
                elseif (stripos($html, '{{message}}') !== false) $html = preg_replace('/{{\s*message\s*}}/i', $body, $html);
                else $html = $html . $body;
                $subject = sanitize_text_field(wp_unslash($_POST['quick_subject'] ?? $base->subject));
                $wpdb->insert(self::table('templates'), [
                    'name'=>sanitize_text_field(wp_unslash($_POST['quick_name'] ?? '新模板')),
                    'subject'=>$subject,
                    'summary'=>'基于通用模板创建，只编辑了正文内容。',
                    'html'=>$html,
                    'variables'=>wp_json_encode(self::extract_vars($html . ' ' . $subject)),
                    'created_at'=>self::now(),
                    'updated_at'=>self::now()
                ]);
                add_action('admin_notices', fn()=>self::notice('已基于通用模板创建新邮件模板。'));
            }
        }

        if ($action === 'delete_template') {
            $id = absint(wp_unslash($_POST['id']));
            if (self::is_builtin_template($id)) {
                add_action('admin_notices', fn()=>self::notice('通用内置模板不能删除。', 'warning'));
            } else {
                $wpdb->delete(self::table('templates'), ['id'=>$id]);
                add_action('admin_notices', fn()=>self::notice('模板已删除。'));
            }
        }

        if ($action === 'save_event') {
            $id = absint(wp_unslash($_POST['id'] ?? 0));
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $slug = sanitize_title(wp_unslash($_POST['slug'] ?? $name));
            $data = ['name'=>$name, 'slug'=>$slug, 'description'=>sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')), 'active'=>!empty($_POST['active']) ? 1 : 0];
            if ($id) $wpdb->update(self::table('events'), $data, ['id'=>$id]);
            else { $data['created_at']=self::now(); $wpdb->insert(self::table('events'), $data); }
            add_action('admin_notices', fn()=>self::notice('活动已保存。'));
        }

        if ($action === 'delete_event') {
            $id = absint(wp_unslash($_POST['id']));
            $wpdb->delete(self::table('subscriber_events'), ['event_id'=>$id]);
            $wpdb->delete(self::table('events'), ['id'=>$id]);
            add_action('admin_notices', fn()=>self::notice('活动已删除。'));
        }

        if ($action === 'save_subscriber') {
            self::upsert_subscriber(sanitize_email(wp_unslash($_POST['email'] ?? '')), sanitize_text_field(wp_unslash($_POST['name'] ?? '')), array_map('absint', wp_unslash($_POST['events'] ?? [])), 'manual');
            add_action('admin_notices', fn()=>self::notice('收件人已保存。'));
        }

        if ($action === 'import_csv') {
            $count = self::import_csv(self::valid_uploaded_file('csv_file', ['csv']));
            add_action('admin_notices', fn()=>self::notice("CSV 导入完成：$count 个收件人。"));
        }

        if ($action === 'delete_subscriber') {
            $id = absint(wp_unslash($_POST['id']));
            $wpdb->delete(self::table('subscriber_events'), ['subscriber_id'=>$id]);
            $wpdb->delete(self::table('subscribers'), ['id'=>$id]);
            add_action('admin_notices', fn()=>self::notice('收件人已删除。'));
        }

        if ($action === 'export_current_csv') {
            self::export_current_form_csv();
        }

        if ($action === 'preview_current_static') {
            self::preview_current_form_static();
        }

        if ($action === 'create_campaign' || $action === 'save_campaign_draft') {
            $is_draft = $action === 'save_campaign_draft';
            $campaign_id = self::create_campaign_from_post($is_draft);
            if ($campaign_id && !$is_draft && sanitize_text_field(wp_unslash($_POST['send_mode'] ?? '')) === 'now') self::process_campaigns($campaign_id);
            add_action('admin_notices', fn()=>self::notice($is_draft ? '发送任务草稿已保存。' : '发送任务已创建。系统会通过 WP-Cron 分批处理。'));
        }
    }

    private static function posted_vars_for_preview() {
        $vars = [];
        $posted_var = isset($_POST['var']) && is_array($_POST['var']) ? wp_unslash($_POST['var']) : [];
        if (!empty($posted_var)) {
            foreach ($posted_var as $k=>$v) {
                $key = sanitize_key($k);
                if ($key === '') continue;
                $vars[$key] = self::sanitize_var_value($key, $v);
            }
        }
        return $vars;
    }

    private static function current_template_from_post() {
        global $wpdb;
        $template_id = absint(wp_unslash($_POST['template_id'] ?? 0));
        if (!$template_id) return null;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
    }

    private static function export_current_form_csv() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'mad-event-mailer'));
        $template = self::current_template_from_post();
        if (!$template) wp_die(esc_html__('Please select a template first.', 'mad-event-mailer'));
        $posted_vars = self::posted_vars_for_preview();
        $scan = $template->html . ' ' . $template->subject . ' ' . implode(' ', array_map('strval', $posted_vars));
        $vars = array_values(array_diff(self::editable_vars(self::extract_vars($scan)), self::body_vars()));
        $headers = array_merge(['email','name','events'], $vars);
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=mad-mailer-recipients-current-template.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 BOM for CSV download.
        echo "\xEF\xBB\xBF";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download output, not HTML.
        echo self::csv_line($headers);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download output, not HTML.
        echo self::csv_line(array_merge(['example@example.com','张三','活动名称或 slug'], array_fill(0, count($vars), '')));
        exit;
    }

    private static function preview_current_form_static() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'mad-event-mailer'));
        $template = self::current_template_from_post();
        if (!$template) wp_die(esc_html__('Please select a template first.', 'mad-event-mailer'));
        $html = (string)$template->html;
        // 发送前预览只做结构预览：所有变量都保留为 {{变量名}}，不读取收件人或变量实际值，也不会发送邮件。
        $include_unsub = !empty($_POST['include_unsubscribe']);
        $unsub_lang_value = sanitize_text_field(wp_unslash($_POST['unsubscribe_lang'] ?? 'zh'));
        $unsub_lang = in_array($unsub_lang_value, ['zh','en'], true) ? $unsub_lang_value : 'zh';
        $html = self::ensure_unsubscribe_notice($html, $unsub_lang, $include_unsub);
        header('Content-Type: text/html; charset=UTF-8');
        echo self::safe_email_html($html);
        exit;
    }

    private static function sanitize_var_value($key, $value) {
        $key = (string)$key;
        if (in_array($key, self::body_vars(), true)) return wp_kses_post(wp_unslash($value));
        return sanitize_textarea_field(wp_unslash($value));
    }

    private static function extract_vars($html) {
        preg_match_all('/{{\s*([A-Za-z0-9_\-]+)\s*}}/', $html, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    private static function render_template($html, $vars) {
        foreach ($vars as $k=>$v) $html = preg_replace('/{{\s*'.preg_quote($k, '/').'\s*}}/', (string)$v, $html);
        return $html;
    }

    private static function get_register_page_url() {
        $s = self::settings();
        return !empty($s['register_page_url']) ? $s['register_page_url'] : home_url('/');
    }

    private static function get_unsubscribe_url() {
        return add_query_arg('mad_em_action', 'unsubscribe', self::get_register_page_url());
    }

    private static function is_builtin_template($id) {
        $id = (int)$id;
        if ($id <= 0) return false;
        global $wpdb;
        $name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id=%d', self::table('templates'), $id ) );
        return in_array($id, [1,2], true) || in_array($name, ['默认中文模板','默认英文模板','Default English Template'], true);
    }

    private static function strip_auto_unsubscribe_notice($html) {
        $html = (string)$html;
        $html = preg_replace('/<div[^>]*>[^<]*(?:如果你不想继续接收活动通知|If you no longer want to receive event notifications)[\s\S]*?<a[^>]*class="[^"]*mad-em-unsubscribe-button[^"]*"[\s\S]*?<\/a>[\s\S]*?<\/div>/i', '', $html);
        $html = preg_replace('/<p[^>]*>[^<]*(?:如果你不想继续接收活动通知|If you no longer want to receive event notifications)[\s\S]*?{{\s*unsubscribe_url\s*}}[\s\S]*?<\/p>/i', '', $html);
        return $html;
    }

    private static function ensure_unsubscribe_notice($html, $lang='zh', $enabled=true) {
        $html = self::strip_auto_unsubscribe_notice($html);
        if (!$enabled) return $html;
        $lang = $lang === 'en' ? 'en' : 'zh';
        if ($lang === 'en') {
            $text = 'If you no longer want to receive event notifications, you can manage your subscription or unsubscribe from the page below.';
            $label = 'Manage subscription / Unsubscribe';
        } else {
            $text = '如果你不想继续接收活动通知，可以点击下面按钮进入订阅管理页面。';
            $label = '订阅管理 / 退订';
        }
        $button = '<div style="max-width:720px;margin:0 auto;padding:22px 32px 34px;font-size:12px;line-height:1.7;color:#9ca3af;text-align:center;background:#ffffff;border-top:1px solid #e5e7eb;">'.esc_html($text).'<br><a class="mad-em-unsubscribe-button" href="{{unsubscribe_url}}" style="display:inline-block;margin-top:10px;padding:9px 16px;border:1px solid #d1d5db;border-radius:999px;color:#6b7280;text-decoration:none;background:#fff;">'.esc_html($label).'</a></div>';
        if (stripos($html, '</body>') !== false) return preg_replace('/<\/body>/i', $button.'</body>', $html, 1);
        return $html . $button;
    }

    private static function get_events($active_only=false) {
        global $wpdb;
        if ($active_only) {
            return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE active=%d ORDER BY id DESC', self::table('events'), 1 ) );
        }
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', self::table('events') ) );
    }
    private static function get_templates() {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', self::table('templates') ) );
    }

    private static function upsert_subscriber($email, $name, $event_ids, $source='manual', $merge_events=false) {
        global $wpdb;
        if (!is_email($email)) return 0;
        $table = self::table('subscribers');
        $id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email=%s', $table, $email ) );
        $data = ['email'=>$email, 'name'=>$name, 'status'=>'subscribed', 'source'=>$source, 'updated_at'=>self::now()];
        if ($id) $wpdb->update($table, $data, ['id'=>$id]); else { $data['created_at']=self::now(); $wpdb->insert($table, $data); $id = (int)$wpdb->insert_id; }
        if (!$merge_events) $wpdb->delete(self::table('subscriber_events'), ['subscriber_id'=>$id]);
        foreach ($event_ids as $eid) if ($eid) $wpdb->replace(self::table('subscriber_events'), ['subscriber_id'=>$id, 'event_id'=>(int)$eid], ['%d','%d']);
        return $id;
    }

    private static function import_csv($path) {
        $rows = self::parse_csv_file($path);
        if (empty($rows)) return 0;
        $header = array_shift($rows);
        if (!$header) return 0;
        $header = array_map(fn($h)=>strtolower(trim((string) $h)), $header);
        $count = 0; $events = self::get_events(false); $event_map = [];
        foreach ($events as $e) { $event_map[strtolower($e->slug)] = $e->id; $event_map[strtolower($e->name)] = $e->id; }
        foreach ($rows as $row) {
            $data = array_combine($header, array_pad($row, count($header), ''));
            if (!is_array($data)) continue;
            $email = sanitize_email($data['email'] ?? $data['邮箱'] ?? '');
            $name = sanitize_text_field($data['name'] ?? $data['姓名'] ?? '');
            $event_ids = [];
            $event_text = $data['events'] ?? $data['event'] ?? $data['活动'] ?? '';
            foreach (preg_split('/[,;|，、]+/', $event_text) as $token) {
                $key = strtolower(trim($token)); if (isset($event_map[$key])) $event_ids[] = $event_map[$key];
            }
            if (empty($event_ids) && !empty($_POST['default_event'])) $event_ids[] = absint(wp_unslash($_POST['default_event']));
            if (self::upsert_subscriber($email, $name, $event_ids, 'csv')) $count++;
        }
        return $count;
    }

    private static function create_campaign_from_post($as_draft=false) {
        global $wpdb;
        $template_id = absint(wp_unslash($_POST['template_id'] ?? 0));
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) return 0;
        $event_id = absint(wp_unslash($_POST['event_id'] ?? 0));
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? $template->subject));
        $vars = [];
        $posted_var = isset($_POST['var']) && is_array($_POST['var']) ? wp_unslash($_POST['var']) : [];
        if (!empty($posted_var)) {
            foreach ($posted_var as $k=>$v) {
                $key = sanitize_key($k);
                if ($key === '') continue;
                $vars[$key] = self::sanitize_var_value($key, $v);
            }
        }
        foreach (self::title_vars() as $tv) $vars[$tv] = $subject;
        $vars['__include_unsubscribe'] = !empty($_POST['include_unsubscribe']) ? 1 : 0;
        $unsub_lang_value = sanitize_text_field(wp_unslash($_POST['unsubscribe_lang'] ?? 'zh'));
        $vars['__unsubscribe_lang'] = in_array($unsub_lang_value, ['zh','en'], true) ? $unsub_lang_value : 'zh';
        // 变量可能写在“正文内容”里，例如正文中包含 {{score}}，这里要一起扫描，方便 CSV 逐人填充。
        $scan_text = $template->html . ' ' . $template->subject . ' ' . implode(' ', array_map('strval', $vars));
        $all_vars = self::extract_vars($scan_text);
        foreach (self::editable_vars($all_vars) as $v) {
            if (!array_key_exists($v, $vars)) $vars[$v] = '';
        }
        $scheduled = sanitize_text_field(wp_unslash($_POST['scheduled_at'] ?? ''));
        $status = $as_draft ? 'draft' : ((sanitize_text_field(wp_unslash($_POST['send_mode'] ?? '')) === 'schedule' && $scheduled) ? 'scheduled' : 'queued');
        $recipient_mode = sanitize_text_field(wp_unslash($_POST['recipient_mode'] ?? 'event'));
        $wpdb->insert(self::table('campaigns'), [
            'name'=>$subject, 'subject'=>$subject, 'template_id'=>$template_id, 'event_id'=>$recipient_mode === 'csv' ? null : ($event_id ?: null),
            'variables'=>wp_json_encode($vars), 'status'=>$status, 'scheduled_at'=>$scheduled ?: null, 'created_at'=>self::now()
        ]);
        $cid = (int)$wpdb->insert_id;
        if (!$as_draft) {
            if ($recipient_mode === 'csv') {
                $recipient_csv = self::valid_uploaded_file('recipient_csv', ['csv']);
                if ($recipient_csv) self::prepare_logs_from_template_csv($cid, $recipient_csv, $all_vars);
            } else {
                self::prepare_logs($cid, $event_id);
            }
        }
        return $cid;
    }

    private static function prepare_logs($campaign_id, $event_id=0) {
        global $wpdb;
        $subs = self::table('subscribers'); $se = self::table('subscriber_events'); $logs = self::table('campaign_logs');
        if ($event_id) $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT s.* FROM %i s INNER JOIN %i se ON s.id=se.subscriber_id WHERE s.status=%s AND se.event_id=%d', $subs, $se, 'subscribed', $event_id ) );
        else $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status=%s', $subs, 'subscribed' ) );
        foreach ($rows as $s) $wpdb->insert($logs, ['campaign_id'=>$campaign_id, 'subscriber_id'=>$s->id, 'email'=>$s->email, 'status'=>'pending']);
        $wpdb->update(self::table('campaigns'), ['total'=>count($rows)], ['id'=>$campaign_id]);
    }

    private static function prepare_logs_from_template_csv($campaign_id, $path, $template_vars) {
        global $wpdb;
        $rows = self::parse_csv_file($path);
        if (empty($rows)) return 0;
        $header = array_shift($rows);
        if (!$header) return 0;
        if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map(fn($h)=>strtolower(trim((string) $h)), $header);
        $events = self::get_events(false); $event_map = [];
        foreach ($events as $e) { $event_map[strtolower($e->slug)] = $e->id; $event_map[strtolower($e->name)] = $e->id; }
        $count = 0; $editable = self::editable_vars($template_vars);
        foreach ($rows as $row) {
            $data = array_combine($header, array_pad($row, count($header), ''));
            if (!is_array($data)) continue;
            $email = sanitize_email($data['email'] ?? $data['邮箱'] ?? '');
            $name = sanitize_text_field($data['name'] ?? $data['姓名'] ?? '');
            if (!is_email($email)) continue;
            $event_ids = [];
            $event_text = $data['events'] ?? $data['event'] ?? $data['活动'] ?? '';
            foreach (preg_split('/[,;|，、]+/', $event_text) as $token) {
                $key = strtolower(trim($token)); if (isset($event_map[$key])) $event_ids[] = $event_map[$key];
            }
            $sid = self::upsert_subscriber($email, $name, $event_ids, 'csv-campaign', true);
            if (!$sid) continue;
            $wpdb->insert(self::table('campaign_logs'), ['campaign_id'=>$campaign_id, 'subscriber_id'=>$sid, 'email'=>$email, 'status'=>'pending']);
            $per_vars = [];
            foreach ($editable as $v) if (isset($data[strtolower($v)])) $per_vars[$v] = sanitize_textarea_field($data[strtolower($v)]);
            if ($per_vars) $wpdb->replace(self::table('campaign_recipient_vars'), ['campaign_id'=>$campaign_id, 'subscriber_id'=>$sid, 'variables'=>wp_json_encode($per_vars)]);
            $count++;
        }
        $wpdb->update(self::table('campaigns'), ['total'=>$count], ['id'=>$campaign_id]);
        return $count;
    }

    public static function process_campaigns($specific_id=0) {
        global $wpdb;
        $s = self::settings(); $limit = max(1, (int)$s['batch_size']);
        $campaign_table = self::table('campaigns'); $log_table = self::table('campaign_logs');
        $now = self::now();
        if ($specific_id) $campaigns = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $campaign_table, $specific_id ) );
        else $campaigns = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status IN (%s,%s) OR (status=%s AND scheduled_at <= %s) ORDER BY id ASC LIMIT 3', $campaign_table, 'queued', 'sending', 'scheduled', $now ) );
        foreach ($campaigns as $c) {
            $wpdb->update($campaign_table, ['status'=>'sending'], ['id'=>$c->id]);
            $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $c->template_id ) );
            if (!$template) continue;
            $logs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d AND status=%s LIMIT %d', $log_table, $c->id, 'pending', $limit ) );
            foreach ($logs as $log) {
                $sub = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('subscribers'), $log->subscriber_id ) );
                $vars = json_decode($c->variables, true) ?: [];
                $per = $wpdb->get_var( $wpdb->prepare( 'SELECT variables FROM %i WHERE campaign_id=%d AND subscriber_id=%d', self::table('campaign_recipient_vars'), $c->id, $log->subscriber_id ) );
                if ($per) $vars = array_merge($vars, json_decode($per, true) ?: []);
                $vars['email'] = $sub->email ?? $log->email;
                $vars['name'] = $sub->name ?? '';
                $vars['name1'] = $sub->name ?? '';
                $vars['unsubscribe_url'] = self::get_unsubscribe_url();
                $vars['title'] = $vars['title'] ?? $c->subject;
                $vars['title1'] = $vars['title1'] ?? $c->subject;
                $subject = self::render_template($c->subject, $vars);
                $include_unsub = !empty($vars['__include_unsubscribe']);
                $unsub_lang = in_array(($vars['__unsubscribe_lang'] ?? 'zh'), ['zh','en'], true) ? $vars['__unsubscribe_lang'] : 'zh';
                $body = self::render_template(self::ensure_unsubscribe_notice($template->html, $unsub_lang, $include_unsub), $vars);
                $ok = wp_mail($log->email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
                $wpdb->update($log_table, ['status'=>$ok?'sent':'failed', 'error'=>$ok?'':'wp_mail failed', 'sent_at'=>self::now()], ['id'=>$log->id]);
                $ok ? $wpdb->query( $wpdb->prepare( 'UPDATE %i SET sent=sent+1 WHERE id=%d', $campaign_table, $c->id ) ) : null;
                if (!$ok) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET failed=failed+1 WHERE id=%d', $campaign_table, $c->id ) );
            }
            $pending = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE campaign_id=%d AND status=%s', $log_table, $c->id, 'pending' ) );
            if (!$pending) $wpdb->update($campaign_table, ['status'=>'finished', 'sent_at'=>self::now()], ['id'=>$c->id]);
        }
    }

    public static function page_settings() {
        $s = self::settings(); self::wrap_start('SMTP 设置'); ?>
        <form method="post"><?php self::nonce('save_settings'); ?>
        <table class="form-table"><tr><th>SMTP 地址</th><td><input class="regular-text" name="host" value="<?php echo esc_attr($s['host']); ?>" placeholder="smtp.feishu.cn"></td></tr>
        <tr><th>发送协议</th><td><select name="secure"><option value="ssl" <?php selected($s['secure'],'ssl'); ?>>ssl</option><option value="tls" <?php selected($s['secure'],'tls'); ?>>tls</option></select></td></tr>
        <tr><th>端口</th><td><input name="port" value="<?php echo esc_attr($s['port']); ?>"></td></tr>
        <tr><th>邮箱账号</th><td><input class="regular-text" name="username" value="<?php echo esc_attr($s['username']); ?>"></td></tr>
        <tr><th>邮箱密码</th><td><input class="regular-text" type="password" name="password" value="<?php echo esc_attr($s['password']); ?>"></td></tr>
        <tr><th>发件邮箱</th><td><input class="regular-text" name="from_email" value="<?php echo esc_attr($s['from_email']); ?>"></td></tr>
        <tr><th>发件人姓名</th><td><input class="regular-text" name="sender_name" value="<?php echo esc_attr(!empty($s['sender_name']) ? $s['sender_name'] : $s['from_name']); ?>"><p class="description">邮件里显示的发件人名称，例如：MAD Producer 麦德工坊。保存后会同步用于 SMTP 发信 From Name。</p></td></tr>
        <tr><th>回复地址</th><td><input class="regular-text" name="reply_to" value="<?php echo esc_attr($s['reply_to']); ?>"></td></tr>
        <tr><th>每批发送数量</th><td><input type="number" name="batch_size" value="<?php echo esc_attr($s['batch_size']); ?>"> 封 / 每次定时任务</td></tr>
        <tr><th>订阅 / 退订页面固定链接</th><td><input class="regular-text" name="register_page_url" value="<?php echo esc_attr($s['register_page_url']); ?>" placeholder="https://example.com/mail-subscribe/"><p class="description">请新建一个页面，放入短代码 <code>[mad_email_register]</code>，然后把该页面链接填在这里。邮件底部的退订链接会跳转到这个页面。</p></td></tr>
        <tr><th>默认退订按钮</th><td><label><input type="checkbox" name="default_unsubscribe_button" value="1" <?php checked(!empty($s['default_unsubscribe_button'])); ?>> 新建发送任务时默认在邮件底部添加“订阅管理 / 退订”按钮</label><p class="description">这个按钮由插件自动追加，不需要写在 HTML 模板或正文里。</p></td></tr>
        <tr><th>默认退订按钮语言</th><td><select name="default_unsubscribe_lang"><option value="zh" <?php selected($s['default_unsubscribe_lang'] ?? 'zh','zh'); ?>>中文</option><option value="en" <?php selected($s['default_unsubscribe_lang'] ?? 'zh','en'); ?>>English</option></select></td></tr>
        <tr><th>后台界面语言</th><td><select name="ui_language"><option value="zh_CN" <?php selected($s['ui_language'] ?? 'auto','zh_CN'); ?>>中文</option><option value="en_US" <?php selected($s['ui_language'] ?? 'auto','en_US'); ?>>English</option><option value="auto" <?php selected($s['ui_language'] ?? 'auto','auto'); ?>>跟随 WordPress 站点语言</option></select><p class="description">切换后保存并刷新 MAD 邮件后台页面即可生效。</p></td></tr>
        <tr><th>前台订阅页语言</th><td><select name="public_language"><option value="zh_CN" <?php selected($s['public_language'] ?? 'auto','zh_CN'); ?>>中文</option><option value="en_US" <?php selected($s['public_language'] ?? 'auto','en_US'); ?>>English</option><option value="auto" <?php selected($s['public_language'] ?? 'auto','auto'); ?>>跟随 WordPress 站点语言</option></select><p class="description">影响短代码 <code>[mad_email_register]</code> 生成的订阅、查询和退订表单。</p></td></tr></table>
        <?php submit_button('保存设置'); ?></form><?php self::wrap_end();
    }

    public static function page_templates() {
        global $wpdb;
        $edit = null;
        if (!empty($_GET['edit'])) $edit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), absint(wp_unslash($_GET['edit'])) ) );
        self::wrap_start('邮件模板');
        $show_query = !empty($query_result);
        ?>
        <style>
            .mad-em-modal{display:none;position:fixed;z-index:100000;inset:0;background:rgba(0,0,0,.48);padding:36px;box-sizing:border-box}.mad-em-modal-box{max-width:980px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 24px 80px rgba(0,0,0,.28);padding:16px}.mad-em-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.mad-em-modal iframe{width:100%;height:720px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.mad-em-template-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.mad-em-template-actions form{margin:0}.mad-em-template-actions a,.mad-em-template-actions button{margin:0!important;white-space:nowrap}
        </style>
        <div class="notice notice-info" style="padding:12px 14px"><p><strong>模板写法规则：</strong></p><p>变量统一使用 <code>{{变量名}}</code>，只能包含英文、数字、下划线或短横线。<code>{{title}}</code> / <code>{{title1}}</code> 会自动使用发送页面的邮件主题；<code>{{name}}</code> / <code>{{name1}}</code> 会自动使用收件人姓名；<code>{{email}}</code> 自动使用收件人邮箱；<code>{{unsubscribe_url}}</code> 自动生成退订链接。</p><p>通用模板建议保留一个正文插槽：<code>{{message1}}</code> 或 <code>{{message}}</code>。发送邮件时可以直接用富文本编辑器编辑正文内容，正文里还可以继续写 <code>{{score}}</code>、<code>{{rank}}</code> 这种变量；上传 CSV 时同名列会覆盖每个收件人的值。</p></div>
        <h2>基于通用模板快速创建</h2>
        <form method="post"><?php self::nonce('save_quick_template'); ?>
        <table class="form-table"><tr><th>选择通用模板</th><td><select name="base_template_id" required><?php foreach(self::get_templates() as $bt): ?><option value="<?php echo (int)$bt->id; ?>"><?php echo esc_html($bt->name); ?></option><?php endforeach; ?></select><p class="description">会把下面的正文内容放进该模板的 <code>{{message1}}</code> 或 <code>{{message}}</code> 位置，并保存为一个新的完整模板。</p></td></tr>
        <tr><th>新模板名称</th><td><input class="regular-text" name="quick_name" required placeholder="例如：IFT IC #6 分数通知"></td></tr>
        <tr><th>默认邮件主题</th><td><input class="regular-text" name="quick_subject" value="{{title1}}"><p class="description">一般保持 <code>{{title1}}</code> 即可，发送时再填写真实标题。</p></td></tr>
        <tr><th>正文内容</th><td><?php wp_editor('', 'mad_em_quick_body', ['textarea_name'=>'quick_body','textarea_rows'=>10,'media_buttons'=>false,'teeny'=>false,'quicktags'=>true]); ?><p class="description">这里可以写富文本，也可以插入变量，例如 <code>{{score}}</code>、<code>{{comment}}</code>。创建后这些变量会出现在发送页和 CSV 模板里。</p></td></tr></table>
        <?php submit_button('基于通用模板创建'); ?></form><hr>
        <h2><?php echo $edit ? '编辑模板' : '新增模板'; ?></h2>
        <form method="post" enctype="multipart/form-data"><?php self::nonce('save_template'); ?><input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
        <table class="form-table"><tr><th>模板名称</th><td><input class="regular-text" name="name" required value="<?php echo esc_attr($edit->name ?? ''); ?>"></td></tr>
        <tr><th>默认邮件主题</th><td><input class="regular-text" name="subject" value="<?php echo esc_attr($edit->subject ?? ''); ?>"><p class="description">建议使用 {{title1}} 或直接填写固定标题。发送时的“邮件主题”会自动填充 {{title}} / {{title1}}。</p></td></tr>
        <tr><th>邮件摘要</th><td><textarea class="large-text" name="summary" rows="3"><?php echo esc_textarea($edit->summary ?? ''); ?></textarea></td></tr>
        <tr><th>上传 HTML</th><td><input type="file" name="html_file" accept=".html,.htm"><p class="description">也可以在下面直接粘贴 HTML。变量格式为 {{变量名}}。{{name}} / {{name1}} 会自动使用收件人姓名，不需要手动填写。</p></td></tr>
        <tr><th>HTML</th><td><textarea class="large-text code" name="html" rows="18"><?php echo esc_textarea($edit->html ?? ''); ?></textarea></td></tr></table>
        <?php submit_button('保存模板'); ?></form>
        <h2>已保存模板</h2><table class="widefat striped"><thead><tr><th>ID</th><th>模板名称</th><th>邮件主题</th><th>变量</th><th>操作</th></tr></thead><tbody><?php foreach (self::get_templates() as $t): $vars=self::extract_vars($t->html.' '.$t->subject); ?>
        <tr><td><?php echo (int)$t->id; ?></td><td><?php echo esc_html($t->name); ?><?php if (self::is_builtin_template($t->id)) echo ' <span class="description">通用模板</span>'; ?></td><td><?php echo esc_html($t->subject); ?></td><td><?php echo esc_html(implode(', ', $vars)); ?></td><td class="mad-em-template-actions"><a href="<?php echo esc_url(admin_url('admin.php?page=mad-em-templates&edit='.$t->id)); ?>">编辑</a><button type="button" class="button-link mad-em-template-preview" data-url="<?php echo esc_url(self::preview_url($t->id)); ?>">预览</button><a href="<?php echo esc_url(self::export_url($t->id)); ?>">导出收件人模板</a><?php if (!self::is_builtin_template($t->id)): ?><form method="post"><?php self::nonce('delete_template'); ?><input type="hidden" name="id" value="<?php echo (int)$t->id; ?>"><button class="button-link-delete" onclick="return confirm('确定删除吗？')">删除</button></form><?php else: ?><span class="description">不可删除</span><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <div class="mad-em-modal" id="madEmTemplateModal"><div class="mad-em-modal-box"><div class="mad-em-modal-head"><strong>模板预览</strong><button type="button" class="button" id="madEmTemplateClose">关闭</button></div><iframe id="madEmTemplateFrame"></iframe></div></div>
        <script>
        (function(){
            var modal=document.getElementById('madEmTemplateModal'), frame=document.getElementById('madEmTemplateFrame'), close=document.getElementById('madEmTemplateClose');
            document.querySelectorAll('.mad-em-template-preview').forEach(function(btn){btn.addEventListener('click',function(){frame.src=this.getAttribute('data-url'); modal.style.display='block';});});
            close.addEventListener('click',function(){modal.style.display='none'; frame.src='about:blank';});
            modal.addEventListener('click',function(e){if(e.target===modal){modal.style.display='none'; frame.src='about:blank';}});
        })();
        </script>
        <?php self::wrap_end();
    }

    public static function page_events() {
        global $wpdb; $edit=null; if (!empty($_GET['edit'])) $edit=$wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('events'), absint(wp_unslash($_GET['edit'])) ) );
        self::wrap_start('活动管理'); ?>
        <form method="post"><?php self::nonce('save_event'); ?><input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
        <table class="form-table"><tr><th>活动名称</th><td><input class="regular-text" name="name" required value="<?php echo esc_attr($edit->name ?? ''); ?>"></td></tr>
        <tr><th>别名 / Slug</th><td><input class="regular-text" name="slug" value="<?php echo esc_attr($edit->slug ?? ''); ?>"></td></tr>
        <tr><th>活动描述</th><td><textarea class="large-text" name="description" rows="3"><?php echo esc_textarea($edit->description ?? ''); ?></textarea></td></tr>
        <tr><th>启用</th><td><label><input type="checkbox" name="active" <?php checked(($edit->active ?? 1), 1); ?>> 在前台注册表单中显示</label></td></tr></table><?php submit_button('保存活动'); ?></form>
        <table class="widefat striped"><thead><tr><th>ID</th><th>活动名称</th><th>别名 / Slug</th><th>启用</th><th>操作</th></tr></thead><tbody><?php foreach (self::get_events(false) as $e): ?>
        <tr><td><?php echo (int)$e->id; ?></td><td><?php echo esc_html($e->name); ?></td><td><?php echo esc_html($e->slug); ?></td><td><?php echo $e->active ? '是' : '否'; ?></td><td><a href="<?php echo esc_url(admin_url('admin.php?page=mad-em-events&edit='.$e->id)); ?>">编辑</a> <form method="post" style="display:inline"><?php self::nonce('delete_event'); ?><input type="hidden" name="id" value="<?php echo (int)$e->id; ?>"><button class="button-link-delete" onclick="return confirm('确定删除这个活动吗？')">删除</button></form></td></tr>
        <?php endforeach; ?></tbody></table><?php self::wrap_end();
    }

    public static function page_subscribers() {
        global $wpdb; self::wrap_start('收件人'); $events=self::get_events(false); ?>
        <p>支持的 CSV 列名：<code>email,name,events</code>，也兼容 <code>邮箱,姓名,活动</code>。活动可以填写活动名称或 slug，多个活动用逗号分隔。</p>
        <form method="post" enctype="multipart/form-data"><?php self::nonce('import_csv'); ?><input type="file" name="csv_file" accept=".csv" required> 默认活动： <select name="default_event"><option value="0">不指定</option><?php foreach($events as $e) echo '<option value="'.(int)$e->id.'">'.esc_html($e->name).'</option>'; ?></select> <?php submit_button('导入 CSV', 'secondary', 'submit', false); ?></form>
        <h2>添加收件人</h2><form method="post"><?php self::nonce('save_subscriber'); ?><input name="email" placeholder="email@example.com" required> <input name="name" placeholder="姓名"> <?php foreach($events as $e): ?><label style="margin-right:12px"><input type="checkbox" name="events[]" value="<?php echo (int)$e->id; ?>"> <?php echo esc_html($e->name); ?></label><?php endforeach; ?> <?php submit_button('保存', 'secondary', 'submit', false); ?></form>
        <h2>最近收件人</h2><table class="widefat striped"><thead><tr><th>邮箱</th><th>姓名</th><th>订阅活动</th><th>状态</th><th>操作</th></tr></thead><tbody><?php
        $rows=$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 200', self::table('subscribers') ) ); foreach($rows as $r):
        $names=$wpdb->get_col( $wpdb->prepare( 'SELECT e.name FROM %i se JOIN %i e ON e.id=se.event_id WHERE se.subscriber_id=%d', self::table('subscriber_events'), self::table('events'), $r->id ) ); ?>
        <tr><td><?php echo esc_html($r->email); ?></td><td><?php echo esc_html($r->name); ?></td><td><?php echo esc_html(implode(', ', $names)); ?></td><td><?php echo esc_html(self::status_label($r->status)); ?></td><td><form method="post"><?php self::nonce('delete_subscriber'); ?><input type="hidden" name="id" value="<?php echo (int)$r->id; ?>"><button class="button-link-delete" onclick="return confirm('确定删除这个收件人吗？')">删除</button></form></td></tr>
        <?php endforeach; ?></tbody></table><?php self::wrap_end();
    }

    public static function page_send() {
        global $wpdb;
        $templates = self::get_templates();
        $events = self::get_events(false);
        $settings = self::settings();
        $load_id = absint($_GET['campaign_id'] ?? 0);
        $loaded_campaign = $load_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('campaigns'), $load_id ) ) : null;
        $loaded_vars = $loaded_campaign ? (json_decode($loaded_campaign->variables, true) ?: []) : [];
        $selected_id = absint($_GET['template_id'] ?? 0);
        if ($loaded_campaign && !$selected_id) $selected_id = (int)$loaded_campaign->template_id;
        if (!$selected_id && !empty($templates)) $selected_id = (int)$templates[0]->id;
        $selected_template = null;
        foreach ($templates as $t) if ((int)$t->id === $selected_id) $selected_template = $t;
        $template_vars = $selected_template ? self::extract_vars($selected_template->html . ' ' . $selected_template->subject . ' ' . implode(' ', array_map('strval', $loaded_vars))) : [];
        $body_vars = array_values(array_intersect($template_vars, self::body_vars()));
        $editable_vars = $selected_template ? array_values(array_diff(self::editable_vars($template_vars), self::body_vars())) : [];
        $initial_subject = $loaded_campaign ? $loaded_campaign->subject : '';
        $initial_unsub = array_key_exists('__include_unsubscribe', $loaded_vars) ? !empty($loaded_vars['__include_unsubscribe']) : !empty($settings['default_unsubscribe_button']);
        $initial_unsub_lang = in_array(($loaded_vars['__unsubscribe_lang'] ?? ($settings['default_unsubscribe_lang'] ?? 'zh')), ['zh','en'], true) ? ($loaded_vars['__unsubscribe_lang'] ?? ($settings['default_unsubscribe_lang'] ?? 'zh')) : 'zh';
        if (!$initial_subject && $selected_template && !preg_match('/^\s*{{\s*title1?\s*}}\s*$/', (string)$selected_template->subject)) $initial_subject = $selected_template->subject;
        self::wrap_start('发送 / 定时发送邮件');
        if ($loaded_campaign) self::notice('已载入发送任务 #'.$loaded_campaign->id.' 的设置，你可以修改后重新保存草稿或创建新的发送任务。', 'info');
        $show_query = !empty($query_result);
        ?>
        <style>
            .mad-em-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px 22px;max-width:1180px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .mad-em-help{color:#646970;margin-top:6px}.mad-em-varrow{margin:0 0 14px}.mad-em-varrow textarea{max-width:880px}.mad-em-preview-modal{display:none;position:fixed;z-index:100000;inset:0;background:rgba(0,0,0,.45);padding:40px;box-sizing:border-box}.mad-em-preview-box{background:#fff;border-radius:12px;max-width:920px;margin:0 auto;padding:18px;box-shadow:0 20px 60px rgba(0,0,0,.25)}.mad-em-preview-box iframe{width:100%;height:650px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.mad-em-preview-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.mad-em-preview-actions{display:flex;gap:8px;align-items:center}.mad-em-var-label{display:inline-block;margin-bottom:6px}
        </style>
        <form method="get" class="mad-em-card" style="margin-bottom:14px">
            <input type="hidden" name="page" value="mad-em">
            <?php if ($loaded_campaign): ?><input type="hidden" name="campaign_id" value="<?php echo (int)$loaded_campaign->id; ?>"><?php endif; ?>
            <table class="form-table"><tr><th>模板选择</th><td>
                <select name="template_id" id="template_id_switch" required>
                    <option value="">请选择...</option>
                    <?php foreach($templates as $t): ?><option value="<?php echo (int)$t->id; ?>" <?php selected($selected_id, (int)$t->id); ?>><?php echo esc_html($t->name); ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="button button-secondary">切换模板</button>
                <p class="mad-em-help">选择模板后点击“切换模板”，页面会刷新并重新生成正文插槽与变量填写框。这个按钮不会发送邮件。</p>
            </td></tr></table>
        </form>
        <form method="post" id="mad-em-send" enctype="multipart/form-data" class="mad-em-card"><?php self::nonce('create_campaign'); ?>
        <input type="hidden" name="template_id" id="template_id" value="<?php echo (int)$selected_id; ?>">
        <table class="form-table"><tr><th>当前模板</th><td><strong><?php echo $selected_template ? esc_html($selected_template->name) : '未选择模板'; ?></strong> <button type="submit" class="button" name="mad_em_action" value="export_current_csv">导出当前内容的收件人 CSV</button><p class="mad-em-help">如需更换模板，请使用上方“模板选择”区域切换。导出 CSV 会按照当前模板和当前正文内容生成字段。</p></td></tr>
        <tr><th>邮件主题</th><td><input class="regular-text" name="subject" id="subject" required value="<?php echo esc_attr($initial_subject); ?>"><p class="mad-em-help">这里会自动填充模板中的 {{title}} / {{title1}}，不需要再单独设置 title 变量。</p></td></tr>
        <tr><th>收件人来源</th><td><label><input type="radio" name="recipient_mode" value="event" checked> 按活动订阅列表发送</label> &nbsp; <label><input type="radio" name="recipient_mode" value="csv"> 使用本次上传的 CSV 发送</label></td></tr>
        <tr class="recipient-event"><th>收件人活动列表</th><td><select name="event_id"><option value="0">全部已订阅收件人</option><?php foreach($events as $e): ?><option value="<?php echo (int)$e->id; ?>"><?php echo esc_html($e->name); ?></option><?php endforeach; ?></select></td></tr>
        <tr class="recipient-csv" style="display:none"><th>上传收件人 CSV</th><td><input type="file" name="recipient_csv" accept=".csv"><p class="mad-em-help">先选择邮件模板，再点击“导出该模板的收件人 CSV”。填好 email、name、events 和额外变量后上传。name 会自动用于 {{name}} / {{name1}}。</p></td></tr>
        <tr><th>正文内容</th><td><div id="bodybox">
            <?php if (!$selected_template): ?>
                <p class="description">请先选择模板。</p>
            <?php elseif (empty($body_vars)): ?>
                <p class="description">当前模板没有 <code>{{message}}</code> 或 <code>{{message1}}</code> 正文插槽。你仍然可以在下面填写其它自定义变量。</p>
            <?php else: ?>
                <?php foreach ($body_vars as $bv): ?>
                    <p><strong>编辑 {{<?php echo esc_html($bv); ?>}} 对应的正文内容</strong></p>
                    <?php wp_editor($loaded_vars[$bv] ?? '', 'mad_em_body_'.$bv, [
                        'textarea_name' => 'var['.$bv.']',
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                        'teeny' => false,
                        'quicktags' => true,
                    ]); ?>
                    <p class="mad-em-help">正文里也可以继续写变量，例如 <code>{{score}}</code>、<code>{{rank}}</code>、<code>{{comment}}</code>。如果你上传 CSV，CSV 里有同名列时会给每个收件人填不同内容。</p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div></td></tr>
        <tr><th>邮件底部退订按钮</th><td><label><input type="checkbox" name="include_unsubscribe" value="1" <?php checked($initial_unsub); ?>> 在邮件底部自动添加退订按钮</label>　语言：<select name="unsubscribe_lang"><option value="zh" <?php selected($initial_unsub_lang, 'zh'); ?>>中文</option><option value="en" <?php selected($initial_unsub_lang, 'en'); ?>>English</option></select><p class="mad-em-help">退订按钮由插件自动追加到邮件底部，不需要放在正文或 HTML 模板里。预览、测试邮件和正式发送都会按这里的选择生成。</p></td></tr>
        <tr><th>其它变量设置</th><td><div id="varbox">
            <?php if (!$selected_template): ?>
                <p class="description">请先选择模板。</p>
            <?php elseif (empty($editable_vars)): ?>
                <p class="mad-em-empty-vars">当前模板没有其它需要手动填写的变量。你在正文里写入 {{score}} 这类变量后，系统会自动在这里增加填写框。</p>
            <?php else: ?>
                <?php foreach ($editable_vars as $v): ?>
                    <p class="mad-em-varrow" data-varrow="<?php echo esc_attr($v); ?>"><label><strong class="mad-em-var-label">{{<?php echo esc_html($v); ?>}}</strong><br><textarea class="large-text" rows="3" name="var[<?php echo esc_attr($v); ?>]" placeholder="这里填写全局默认值；如果 CSV 中有同名列，会优先使用每个收件人自己的值。"><?php echo esc_textarea($loaded_vars[$v] ?? ''); ?></textarea></label></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div><p class="mad-em-help">{{name}} / {{name1}} 会自动从收件人姓名获取，{{title}} / {{title1}} 会自动从邮件主题获取，{{unsubscribe_url}} 会自动生成退订链接。正文内容中的变量会在预览和发送时继续替换。</p></td></tr>
        <tr><th>发送方式</th><td><label><input type="radio" name="send_mode" value="now" checked> 立即发送</label> <label><input type="radio" name="send_mode" value="schedule"> 定时发送</label> <input type="datetime-local" name="scheduled_at"></td></tr></table>
        <p class="submit"><button type="submit" class="button" id="previewBtn" name="mad_em_action" value="preview_current_static" formtarget="_blank" onclick="return window.madEmPreviewSubmit ? window.madEmPreviewSubmit(this) : true;">发送前预览</button> <button type="button" class="button" id="testBtn" onclick="return window.madEmOpenTest ? window.madEmOpenTest() : false;">发送测试邮件</button> <button type="submit" class="button button-secondary" name="mad_em_action" value="save_campaign_draft">保存为草稿</button> <button type="submit" class="button button-primary" name="mad_em_action" value="create_campaign">创建发送任务</button></p></form>
        <div class="mad-em-preview-modal" id="previewModal"><div class="mad-em-preview-box"><div class="mad-em-preview-head"><strong id="previewTitle">发送前预览</strong><div class="mad-em-preview-actions"><span id="previewStatus" class="description"></span><button type="button" class="button" id="closePreview" onclick="window.madEmCloseModal && window.madEmCloseModal();">关闭</button></div></div><div id="testPanel" style="display:none;margin-bottom:14px;padding:12px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7"><p><label><strong>测试邮箱</strong><br><input type="email" id="testEmail" class="regular-text" placeholder="test@example.com"></label></p><div id="testVars"></div><p><button type="button" class="button button-primary" id="sendTestNow">发送测试邮件</button></p></div><iframe id="previewFrame" name="madEmPreviewFrame"></iframe></div></div>
        <script>
        (function(){
            var templateVars = <?php echo wp_json_encode($selected_template ? self::extract_vars($selected_template->html . ' ' . $selected_template->subject) : []); ?> || [];
            var systemVars = ['name','name1','email','title','title1','unsubscribe_url','message','message1'];
            function $(id){ return document.getElementById(id); }
            function syncEditors(){ try { if (window.tinyMCE && tinyMCE.triggerSave) tinyMCE.triggerSave(); } catch(e) {} }
            function extractVars(text){
                var out=[], re=/{{\s*([A-Za-z0-9_\-]+)\s*}}/g, m;
                while((m=re.exec(text||''))!==null){ if(systemVars.indexOf(m[1])===-1 && out.indexOf(m[1])===-1) out.push(m[1]); }
                return out;
            }
            function bodyText(){ syncEditors(); var text=''; var areas=document.querySelectorAll('#bodybox textarea'); for(var i=0;i<areas.length;i++) text+=' '+(areas[i].value||''); return text; }
            function existingVars(){ var out=[], rows=document.querySelectorAll('#varbox [data-varrow]'); for(var i=0;i<rows.length;i++){ var v=rows[i].getAttribute('data-varrow'); if(v&&out.indexOf(v)===-1) out.push(v); } return out; }
            function addVarField(v){
                if(!v || systemVars.indexOf(v)!==-1 || existingVars().indexOf(v)!==-1) return;
                var box=$('varbox'); if(!box) return;
                var empty=box.querySelector('.mad-em-empty-vars'); if(empty) empty.parentNode.removeChild(empty);
                var p=document.createElement('p'); p.className='mad-em-varrow'; p.setAttribute('data-varrow',v);
                p.innerHTML='<label><strong class="mad-em-var-label">{{'+v+'}}</strong><br><textarea class="large-text" rows="3" name="var['+v+']" placeholder="这里填写全局默认值；如果 CSV 中有同名列，会优先使用每个收件人自己的值。"></textarea></label>';
                box.appendChild(p);
            }
            function refreshVars(){ var vs=extractVars(bodyText()); for(var i=0;i<vs.length;i++) addVarField(vs[i]); }
            function allVars(){ var out=[], sources=[templateVars, extractVars(bodyText()), existingVars()]; for(var s=0;s<sources.length;s++){ for(var i=0;i<sources[s].length;i++){ var v=sources[s][i]; if(systemVars.indexOf(v)===-1 && out.indexOf(v)===-1) out.push(v); } } return out; }
            window.madEmCloseModal = function(){ var m=$('previewModal'), f=$('previewFrame'); if(m)m.style.display='none'; if(f){ f.removeAttribute('src'); try{f.src='about:blank';}catch(e){} } };
            window.madEmPreviewSubmit = function(btn){
                var form=$('mad-em-send'), modal=$('previewModal'), panel=$('testPanel'), frame=$('previewFrame'), title=$('previewTitle'), status=$('previewStatus');
                if(!form) return true;
                if(modal) modal.style.display='block';
                if(panel) panel.style.display='none';
                if(frame) frame.style.display='block';
                if(title) title.textContent='发送前预览';
                if(status) status.textContent='静态预览：变量会保留为 {{变量名}}，不会发送邮件。';
                form.target='madEmPreviewFrame';
                setTimeout(function(){ form.removeAttribute('target'); }, 1200);
                return true;
            };
            window.madEmOpenTest = function(){
                refreshVars();
                var modal=$('previewModal'), panel=$('testPanel'), frame=$('previewFrame'), title=$('previewTitle'), status=$('previewStatus'), testVars=$('testVars');
                if(modal) modal.style.display='block';
                if(panel) panel.style.display='block';
                if(frame) frame.style.display='none';
                if(title) title.textContent='发送测试邮件';
                if(status) status.textContent='';
                var vars=allVars(), html='<p class="description">填写测试邮箱和变量示例值。只有点击下面的“发送测试邮件”才会真正发送。</p>';
                for(var i=0;i<vars.length;i++){ html+='<p><label><strong>{{'+vars[i]+'}}</strong><br><textarea class="large-text" rows="2" data-test-var="'+vars[i]+'" placeholder="测试示例值；不填则保留 {{'+vars[i]+'}}"></textarea></label></p>'; }
                if(testVars) testVars.innerHTML=html;
                return false;
            };
            function bind(){
                document.addEventListener('input', function(e){ if(e.target && e.target.closest && e.target.closest('#bodybox')) refreshVars(); });
                var modes=document.querySelectorAll('input[name="recipient_mode"]');
                for(var i=0;i<modes.length;i++) modes[i].onchange=function(){ var csv=document.querySelector('.recipient-csv'), ev=document.querySelector('.recipient-event'), checked=document.querySelector('input[name="recipient_mode"]:checked'); if(checked && checked.value==='csv'){ if(csv)csv.style.display='table-row'; if(ev)ev.style.display='none'; } else { if(csv)csv.style.display='none'; if(ev)ev.style.display='table-row'; } };
                var modal=$('previewModal'); if(modal) modal.addEventListener('click', function(e){ if(e.target===modal) window.madEmCloseModal(); });
                var send=$('sendTestNow'); if(send) send.onclick=function(e){
                    e.preventDefault(); syncEditors(); refreshVars();
                    var email=($('testEmail')&&$('testEmail').value)||''; if(!email){ alert('请填写测试邮箱。'); return false; }
                    var fd=new FormData($('mad-em-send')); fd.delete('mad_em_action'); fd.append('action','mad_em_test_send'); fd.append('nonce','<?php echo esc_js(wp_create_nonce('mad_em_preview_send')); ?>'); fd.append('test_email', email);
                    var fields=document.querySelectorAll('[data-test-var]'); for(var i=0;i<fields.length;i++){ var key=fields[i].getAttribute('data-test-var'); fd.append('test_var['+key+']', fields[i].value || '{{'+key+'}}'); }
                    var status=$('previewStatus'); if(status) status.textContent='正在发送测试邮件...';
                    fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){ if(status) status.textContent=(res&&res.data&&res.data.message)?res.data.message:(res&&res.success?'测试邮件已发送。':'测试邮件发送失败。'); }).catch(function(){ if(status) status.textContent='测试邮件发送失败，请检查 SMTP 设置或后台权限。'; });
                    return false;
                };
                setTimeout(refreshVars, 600);
            }
            if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', bind); else bind();
        })();
        </script>
        <p>注册表单短代码： <code>[mad_email_register]</code></p><?php self::wrap_end();
    }

    public static function page_campaigns() {
        global $wpdb; self::wrap_start('发送任务');
        $events = self::get_events(false);
        $status = sanitize_text_field(wp_unslash($_GET['status'] ?? ''));
        $event_id = absint($_GET['event_id'] ?? 0);
        $where = 'WHERE 1=1'; $args=[];
        if ($status !== '') { $where .= ' AND status=%s'; $args[]=$status; }
        if ($event_id) { $where .= ' AND event_id=%d'; $args[]=$event_id; }
        $campaigns_table = self::table('campaigns');
        if ($status_filter && $event_filter) {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status=%s AND event_id=%d ORDER BY id DESC LIMIT 200', $campaigns_table, $status_filter, $event_filter ) );
        } elseif ($status_filter) {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status=%s ORDER BY id DESC LIMIT 200', $campaigns_table, $status_filter ) );
        } elseif ($event_filter) {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d ORDER BY id DESC LIMIT 200', $campaigns_table, $event_filter ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 200', $campaigns_table ) );
        }
        ?>
        <form method="get" style="margin:12px 0 16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="page" value="mad-em-campaigns">
            <label>按活动筛选 <select name="event_id"><option value="0">全部活动</option><?php foreach($events as $e): ?><option value="<?php echo (int)$e->id; ?>" <?php selected($event_id,(int)$e->id); ?>><?php echo esc_html($e->name); ?></option><?php endforeach; ?></select></label>
            <label>按状态筛选 <select name="status"><option value="">全部状态</option><?php foreach(['draft','scheduled','queued','sending','finished','failed'] as $st): ?><option value="<?php echo esc_attr($st); ?>" <?php selected($status,$st); ?>><?php echo esc_html(self::status_label($st)); ?></option><?php endforeach; ?></select></label>
            <button class="button">筛选</button>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mad-em-campaigns')); ?>">重置</a>
        </form>
        <table class="widefat striped"><thead><tr><th>ID</th><th>邮件主题</th><th>活动</th><th>状态</th><th>定时时间</th><th>总数</th><th>已发送</th><th>失败</th><th>创建时间</th><th>操作</th></tr></thead><tbody><?php foreach($rows as $r): $event_name = $r->event_id ? $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id=%d', self::table('events'), $r->event_id ) ) : '全部 / CSV'; ?>
        <tr><td><?php echo (int)$r->id; ?></td><td><?php echo esc_html($r->subject); ?></td><td><?php echo esc_html($event_name); ?></td><td><?php echo esc_html(self::status_label($r->status)); ?></td><td><?php echo esc_html($r->scheduled_at); ?></td><td><?php echo (int)$r->total; ?></td><td><?php echo (int)$r->sent; ?></td><td><?php echo (int)$r->failed; ?></td><td><?php echo esc_html($r->created_at); ?></td><td><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=mad-em&campaign_id='.$r->id)); ?>">调用设置继续编辑</a></td></tr>
        <?php endforeach; if(empty($rows)): ?><tr><td colspan="10">没有找到发送任务。</td></tr><?php endif; ?></tbody></table>
        <p class="description">“调用设置继续编辑”会回到发送页面，并带入该任务的模板、主题和变量内容。不会直接发送，需要你重新点击创建发送任务。</p>
        <?php self::wrap_end();
    }

    public static function handle_public_register() {
        if (empty($_POST['mad_em_public_register']) && empty($_POST['mad_em_public_unsubscribe'])) return;
        if (!isset($_POST['mad_em_public_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad_em_public_nonce'])), 'mad_em_public_register')) return;
        global $wpdb;
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!empty($_POST['mad_em_public_unsubscribe'])) {
            if (is_email($email)) {
                $wpdb->update(self::table('subscribers'), ['status'=>'unsubscribed','updated_at'=>self::now()], ['email'=>$email]);
            }
            wp_safe_redirect(add_query_arg('mad_em_unsubscribed', '1', wp_get_referer() ?: self::get_register_page_url())); exit;
        }
        self::upsert_subscriber($email, sanitize_text_field(wp_unslash($_POST['name'] ?? '')), array_map('absint', wp_unslash($_POST['events'] ?? [])), 'shortcode', true);
        wp_safe_redirect(add_query_arg('mad_em_registered', '1', wp_get_referer() ?: self::get_register_page_url())); exit;
    }

    public static function shortcode_register($atts) {
        $events=self::get_events(true); ob_start();
        $show_unsub = !empty($_GET['mad_em_action']) && sanitize_text_field(wp_unslash($_GET['mad_em_action'])) === 'unsubscribe';
        $query_result = null;
        if (!empty($_POST['mad_em_public_query']) && isset($_POST['mad_em_public_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad_em_public_nonce'])), 'mad_em_public_register')) {
            global $wpdb;
            $qemail = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            if (is_email($qemail)) {
                $sub = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email=%s', self::table('subscribers'), $qemail ) );
                if ($sub) {
                    $names = $wpdb->get_col( $wpdb->prepare( 'SELECT e.name FROM %i se JOIN %i e ON e.id=se.event_id WHERE se.subscriber_id=%d', self::table('subscriber_events'), self::table('events'), $sub->id ) );
                    $query_result = ['found'=>true,'name'=>$sub->name,'email'=>$sub->email,'status'=>$sub->status,'events'=>implode('、', $names) ?: '未选择具体活动'];
                } else { $query_result = ['found'=>false,'email'=>$qemail]; }
            }
        }
        $show_query = !empty($query_result);
        ?>
        <style>
        .mad-em-register-wrap{max-width:860px;margin:36px auto;padding:0 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}.mad-em-register-card{position:relative;overflow:hidden;background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);border:1px solid rgba(15,23,42,.08);border-radius:28px;box-shadow:0 22px 70px rgba(15,23,42,.12);padding:36px}.mad-em-register-card:before{content:"";position:absolute;inset:0 0 auto 0;height:6px;background:linear-gradient(90deg,#2563eb,#60a5fa,#22c55e)}.mad-em-register-title{font-size:30px;font-weight:800;letter-spacing:-.03em;color:#0f172a;margin:0 0 8px}.mad-em-register-sub{color:#64748b;font-size:15px;line-height:1.8;margin:0 0 26px}.mad-em-tabs{display:flex;gap:10px;background:#eef4ff;border-radius:16px;padding:6px;margin-bottom:24px}.mad-em-tab{flex:1;text-align:center;border:0;border-radius:12px;padding:12px 14px;font-weight:750;cursor:pointer;color:#475569;background:transparent}.mad-em-tab.active{background:#fff;color:#1d4ed8;box-shadow:0 8px 24px rgba(37,99,235,.12)}.mad-em-panel{display:none}.mad-em-panel.active{display:block}.mad-em-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.mad-em-field label{display:block;font-size:14px;font-weight:700;color:#334155;margin-bottom:8px}.mad-em-field input[type=text],.mad-em-field input[type=email]{width:100%;box-sizing:border-box;border:1px solid #dbe3ef;border-radius:15px;background:#fff;padding:14px 15px;font-size:15px;outline:none;transition:.2s}.mad-em-field input:focus{border-color:#3b82f6;box-shadow:0 0 0 4px rgba(59,130,246,.13)}.mad-em-events{margin-top:22px}.mad-em-events-title{font-size:15px;font-weight:800;color:#0f172a;margin-bottom:12px}.mad-em-event-list{display:grid;grid-template-columns:1fr 1fr;gap:12px}.mad-em-event-item{display:flex;gap:10px;align-items:flex-start;border:1px solid #e2e8f0;background:#fff;border-radius:16px;padding:14px;cursor:pointer;transition:.2s}.mad-em-event-item:hover{border-color:#93c5fd;box-shadow:0 8px 24px rgba(37,99,235,.08);transform:translateY(-1px)}.mad-em-event-item input{margin-top:3px;accent-color:#2563eb}.mad-em-event-name{font-weight:700;color:#1e293b}.mad-em-actions{margin-top:26px;display:flex;gap:12px;align-items:center}.mad-em-submit{border:0;border-radius:16px;padding:14px 22px;font-size:16px;font-weight:800;color:#fff;background:linear-gradient(135deg,#2563eb,#1d4ed8);cursor:pointer;box-shadow:0 12px 24px rgba(37,99,235,.24);transition:.2s}.mad-em-submit:hover{transform:translateY(-1px);box-shadow:0 16px 30px rgba(37,99,235,.30)}.mad-em-submit.danger{background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 12px 24px rgba(239,68,68,.20)}.mad-em-note{font-size:13px;color:#64748b;line-height:1.7}.mad-em-success{max-width:860px;margin:20px auto;padding:14px 16px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:14px;font-weight:700}.mad-em-warning{max-width:860px;margin:20px auto;padding:14px 16px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:14px;font-weight:700}@media(max-width:680px){.mad-em-grid,.mad-em-event-list{grid-template-columns:1fr}.mad-em-register-card{padding:26px}.mad-em-register-title{font-size:25px}.mad-em-tabs{flex-direction:column}.mad-em-actions{flex-direction:column;align-items:stretch}.mad-em-submit{width:100%}}
        </style>
        <?php if (!empty($_GET['mad_em_registered'])) echo '<div class="mad-em-success">订阅已保存。后续相关活动通知会发送到你的邮箱。</div>'; ?>
        <?php if (!empty($_GET['mad_em_unsubscribed'])) echo '<div class="mad-em-warning">退订已提交。这个邮箱将不再接收活动通知。</div>'; ?>
        <div class="mad-em-register-wrap"><div class="mad-em-register-card">
            <div class="mad-em-tabs">
                <button type="button" class="mad-em-tab <?php echo (!$show_unsub && !$show_query) ? 'active' : ''; ?>" data-target="subscribe">订阅通知</button>
                <button type="button" class="mad-em-tab <?php echo ($show_unsub && !$show_query) ? 'active' : ''; ?>" data-target="unsubscribe">退订通知</button>
                <button type="button" class="mad-em-tab <?php echo $show_query ? 'active' : ''; ?>" data-target="query">查询订阅</button>
            </div>
            <form class="mad-em-panel <?php echo (!$show_unsub && !$show_query) ? 'active' : ''; ?>" data-panel="subscribe" method="post">
                <input type="hidden" name="mad_em_public_register" value="1"><?php wp_nonce_field('mad_em_public_register', 'mad_em_public_nonce'); ?>
                <h2 class="mad-em-register-title">订阅活动通知</h2>
                <p class="mad-em-register-sub">填写邮箱并选择你想接收通知的活动。重复提交只会增加新的订阅类目，不会删除你以前已经订阅的类目。</p>
                <div class="mad-em-grid"><div class="mad-em-field"><label>姓名</label><input type="text" name="name" placeholder="请输入你的姓名" required></div><div class="mad-em-field"><label>邮箱</label><input type="email" name="email" placeholder="name@example.com" required></div></div>
                <div class="mad-em-events"><div class="mad-em-events-title">选择想接收通知的活动</div><div class="mad-em-event-list">
                <?php foreach($events as $e): ?><label class="mad-em-event-item"><input type="checkbox" name="events[]" value="<?php echo (int)$e->id; ?>"><span><span class="mad-em-event-name"><?php echo esc_html($e->name); ?></span></span></label><?php endforeach; ?>
                </div></div>
                <div class="mad-em-actions"><button class="mad-em-submit" type="submit">保存订阅</button><span class="mad-em-note">你可以随时回到这个页面退订。</span></div>
            </form>
            <form class="mad-em-panel <?php echo ($show_unsub && !$show_query) ? 'active' : ''; ?>" data-panel="unsubscribe" method="post">
                <input type="hidden" name="mad_em_public_unsubscribe" value="1"><?php wp_nonce_field('mad_em_public_register', 'mad_em_public_nonce'); ?>
                <h2 class="mad-em-register-title">退订活动通知</h2>
                <p class="mad-em-register-sub">请输入需要退订的邮箱。退订会一次性退订全部活动通知，不需要逐个类目取消。</p>
                <div class="mad-em-field"><label>邮箱</label><input type="email" name="email" placeholder="name@example.com" required></div>
                <div class="mad-em-actions"><button class="mad-em-submit danger" type="submit">确认退订</button><span class="mad-em-note">退订后，如需重新接收通知，可以再次使用左侧订阅表单。</span></div>
            </form>
            <form class="mad-em-panel <?php echo $show_query ? 'active' : ''; ?>" data-panel="query" method="post">
                <input type="hidden" name="mad_em_public_query" value="1"><?php wp_nonce_field('mad_em_public_register', 'mad_em_public_nonce'); ?>
                <h2 class="mad-em-register-title">查询订阅</h2>
                <p class="mad-em-register-sub">输入邮箱后，可以查看当前保存的姓名、邮箱和已订阅的活动类目。</p>
                <div class="mad-em-field"><label>邮箱</label><input type="email" name="email" placeholder="name@example.com" required></div>
                <div class="mad-em-actions"><button class="mad-em-submit" type="submit">查询订阅</button><span class="mad-em-note">这里只查询订阅状态，不会修改你的订阅。</span></div>
                <?php if ($query_result): ?>
                    <div style="margin-top:22px;padding:18px;border:1px solid #dbe3ef;border-radius:16px;background:#fff;">
                    <?php if (!empty($query_result['found'])): ?>
                        <p><strong>姓名：</strong><?php echo esc_html($query_result['name']); ?></p>
                        <p><strong>邮箱：</strong><?php echo esc_html($query_result['email']); ?></p>
                        <p><strong>状态：</strong><?php echo esc_html(self::status_label($query_result['status'])); ?></p>
                        <p><strong>订阅类目：</strong><?php echo esc_html($query_result['events']); ?></p>
                    <?php else: ?>
                        <p>没有找到 <?php echo esc_html($query_result['email']); ?> 的订阅记录。</p>
                    <?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>
        </div></div>
        <script>(function(){document.querySelectorAll('.mad-em-tab').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('.mad-em-tab').forEach(b=>b.classList.remove('active'));document.querySelectorAll('.mad-em-panel').forEach(p=>p.classList.remove('active'));btn.classList.add('active');const panel=document.querySelector('.mad-em-panel[data-panel="'+btn.dataset.target+'"]');if(panel)panel.classList.add('active');}));})();</script>
        <?php $html = ob_get_clean();
        if (self::is_english_language(self::current_public_language())) $html = self::translate_text($html, self::current_public_language());
        return $html;
    }

}

register_activation_hook(__FILE__, ['MAD_Event_Mailer', 'activate']);
register_deactivation_hook(__FILE__, ['MAD_Event_Mailer', 'deactivate']);
MAD_Event_Mailer::init();
