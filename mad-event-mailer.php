<?php
/**
 * Plugin Name: MAD Event Mailer
 * Description: An HTML email delivery plugin for event notifications. Supports SMTP, template variables, CSV recipients, event subscriptions, shortcode registration, batch sending, scheduled sending and language packs.
 * Version: 2.2.5
 * Requires at least: 6.2
 * Author: MAD Producer Studio
 * Author URI: https://github.com/MAD-Producer
 * License: GPL v2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mad-event-mailer
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

class MADEVMA_Event_Mailer {
    const VERSION = '2.2.5';
    const OPT = 'mad_em_settings';
    const CRON = 'mad_em_process_campaigns';
    const CAP = 'mad_em_manage_mailer';
    const ROLE = 'mad_em_mail_manager';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'maybe_handle_post']);
        add_action('admin_init', [__CLASS__, 'maybe_handle_get']);
        add_action('admin_post_mad_em_export_template_csv', [__CLASS__, 'export_template_csv']);
        add_action('admin_post_mad_em_preview_template', [__CLASS__, 'preview_template_page']);
        add_action('wp_ajax_mad_em_preview_send', [__CLASS__, 'ajax_preview_send']);
        add_action('wp_ajax_mad_em_test_send', [__CLASS__, 'ajax_test_send']);
        add_action('phpmailer_init', [__CLASS__, 'smtp_config'], 1000);
        add_filter('wp_mail_from', [__CLASS__, 'mail_from']);
        add_filter('wp_mail_from_name', [__CLASS__, 'mail_from_name']);
        add_shortcode('mad_email_register', [__CLASS__, 'shortcode_register']);
        add_action('init', [__CLASS__, 'handle_public_register']);
        add_action(self::CRON, [__CLASS__, 'process_campaigns']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action('init', [__CLASS__, 'maybe_upgrade'], 1);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_public_assets']);
    }

    public static function maybe_upgrade() {
        if (get_option('mad_em_version') !== self::VERSION) {
            self::activate();
            update_option('mad_em_version', self::VERSION);
        }
        self::sync_default_zh_template_layout();
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'mad_em_';

        self::ensure_roles();

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
            sort_order INT NOT NULL DEFAULT 0,
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
            language VARCHAR(10) NOT NULL DEFAULT 'zh',
            PRIMARY KEY (subscriber_id,event_id,language)
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

        self::ensure_223_schema();
        self::seed_defaults();
        // 退订按钮现在由发送任务选项动态追加，不再直接写入模板。
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 60, 'mad_em_five_minutes', self::CRON);
        update_option('mad_em_version', self::VERSION);
    }

    public static function deactivate() { wp_clear_scheduled_hook(self::CRON); }

    private static function ensure_roles() {
        add_role(self::ROLE, '邮箱管理员', [
            'read' => true,
            self::CAP => true,
        ]);
        $mail_manager = get_role(self::ROLE);
        if ($mail_manager && !$mail_manager->has_cap(self::CAP)) $mail_manager->add_cap(self::CAP);
        $administrator = get_role('administrator');
        if ($administrator && !$administrator->has_cap(self::CAP)) $administrator->add_cap(self::CAP);
    }

    private static function can_manage() {
        return current_user_can(self::CAP) || current_user_can('manage_options');
    }

    public static function cron_schedules($schedules) {
        $schedules['mad_em_five_minutes'] = ['interval' => 300, 'display' => '每 5 分钟'];
        return $schedules;
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'mad_em_' . $name; }
    private static function now() { return current_time('mysql'); }
    private static function settings() { return wp_parse_args(get_option(self::OPT, []), [
        'host'=>'', 'port'=>'465', 'secure'=>'ssl', 'username'=>'', 'password'=>'', 'from_email'=>'', 'from_name'=>'', 'sender_name'=>'', 'reply_to'=>'', 'batch_size'=>30, 'register_page_url'=>'', 'register_page_url_zh'=>'', 'register_page_url_en'=>'', 'default_unsubscribe_button'=>1, 'default_unsubscribe_lang'=>'zh', 'ui_language'=>'auto', 'public_language'=>'auto'
    ]); }

    private static function looks_like_no_reply($name) {
        $normalized = strtolower(preg_replace('/[\s._-]+/', '', (string)$name));
        return in_array($normalized, ['noreply','donotreply'], true);
    }

    private static function sender_name() {
        $raw = get_option(self::OPT, []);
        $s = self::settings();
        $candidates = [];
        if (is_array($raw)) {
            $candidates[] = $raw['sender_name'] ?? '';
            $candidates[] = $raw['from_name'] ?? '';
        }
        $candidates[] = $s['sender_name'] ?? '';
        $candidates[] = $s['from_name'] ?? '';
        foreach ($candidates as $candidate) {
            $name = trim((string)$candidate);
            if ($name !== '' && !self::looks_like_no_reply($name)) return $name;
        }
        $site_name = trim(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        return $site_name ?: 'WordPress';
    }

    private static function sender_email() {
        $s = self::settings();
        foreach ([$s['from_email'] ?? '', $s['username'] ?? ''] as $candidate) {
            $email = sanitize_email($candidate);
            if (is_email($email)) return $email;
        }
        return '';
    }

    public static function mail_from($email) {
        $sender_email = self::sender_email();
        return $sender_email ?: $email;
    }

    public static function mail_from_name($name) {
        return self::sender_name();
    }

    private static function mail_headers($extra = []) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $from_email = self::sender_email();
        if ($from_email) {
            $from_name = str_replace(["\r", "\n"], '', self::sender_name());
            $headers[] = 'From: "' . addcslashes($from_name, '"\\') . '" <' . $from_email . '>';
        }
        $s = self::settings();
        $reply_to = sanitize_email($s['reply_to'] ?? '');
        if (is_email($reply_to)) $headers[] = 'Reply-To: ' . $reply_to;
        return array_merge($headers, (array)$extra);
    }

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

    private static function render_admin_page($renderer) {
        if (!self::can_manage()) wp_die(esc_html(self::tr('权限不足。')));
        ob_start();
        call_user_func([__CLASS__, $renderer]);
        $html = ob_get_clean();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Page renderers escape dynamic values at their source; this preserves WordPress admin forms and editor markup.
        echo self::translate_text($html, self::current_ui_language());
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


    private static function ensure_223_schema() {
        global $wpdb;
        $events = self::table('events');
        $subscriber_events = self::table('subscriber_events');
        $event_columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $events ), 0 );
        if (is_array($event_columns) && !in_array('sort_order', $event_columns, true)) {
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD sort_order INT NOT NULL DEFAULT 0 AFTER active', $events ) );
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET sort_order=id*10 WHERE sort_order=0', $events ) );
        }
        $subscriber_event_columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $subscriber_events ), 0 );
        if (is_array($subscriber_event_columns) && !in_array('language', $subscriber_event_columns, true)) {
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD language VARCHAR(10) NOT NULL DEFAULT %s AFTER event_id', $subscriber_events, 'zh' ) );
        }
        $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP PRIMARY KEY, ADD PRIMARY KEY (subscriber_id,event_id,language)', $subscriber_events ) );
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

    private static function sync_default_zh_template_layout() {
        $file = plugin_dir_path(__FILE__) . 'default-template-zh.html';
        $html = self::read_local_file($file);
        if ($html === '') return;
        $layout_hash = hash('sha256', $html);
        if (get_option('mad_em_default_zh_layout_hash') === $layout_hash) return;

        global $wpdb;
        $table = self::table('templates');
        $subject = '{{title1}}';
        $wpdb->update($table, [
            'html' => $html,
            'variables' => wp_json_encode(self::extract_vars($html . ' ' . $subject)),
            'updated_at' => self::now(),
        ], ['name' => '默认中文模板']);
        update_option('mad_em_default_zh_layout_hash', $layout_hash);
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
        $from_email = self::sender_email();
        $display_name = self::sender_name();
        if ($from_email) {
            $phpmailer->setFrom($from_email, $display_name, false);
            $phpmailer->FromName = $display_name;
            $phpmailer->Sender = $from_email;
        } else {
            $phpmailer->FromName = $display_name;
        }
        if (empty($s['host']) || empty($s['username'])) return;
        $phpmailer->isSMTP();
        $phpmailer->Host = $s['host'];
        $phpmailer->SMTPAuth = true;
        $phpmailer->Port = (int)$s['port'];
        $phpmailer->Username = $s['username'];
        $phpmailer->Password = $s['password'];
        $phpmailer->SMTPSecure = $s['secure'];
        $reply_to = sanitize_email($s['reply_to'] ?? '');
        if (is_email($reply_to)) {
            if (method_exists($phpmailer, 'clearReplyTos')) $phpmailer->clearReplyTos();
            $phpmailer->addReplyTo($reply_to);
        }
    }

    public static function menu() {
        if (!self::can_manage()) return;
        add_menu_page(self::tr('MAD 活动邮件系统'), self::tr('MAD 邮件'), self::CAP, 'mad-em', [__CLASS__, 'page_send'], 'dashicons-email-alt2', 58);
        add_submenu_page('mad-em', self::tr('发送 / 定时发送'), self::tr('发送 / 定时发送'), self::CAP, 'mad-em', [__CLASS__, 'page_send']);
        add_submenu_page('mad-em', self::tr('邮件模板'), self::tr('邮件模板'), self::CAP, 'mad-em-templates', [__CLASS__, 'page_templates']);
        add_submenu_page('mad-em', self::tr('活动管理'), self::tr('活动管理'), self::CAP, 'mad-em-events', [__CLASS__, 'page_events']);
        add_submenu_page('mad-em', self::tr('收件人'), self::tr('收件人'), self::CAP, 'mad-em-subscribers', [__CLASS__, 'page_subscribers']);
        add_submenu_page('mad-em', self::tr('发送任务'), self::tr('发送任务'), self::CAP, 'mad-em-campaigns', [__CLASS__, 'page_campaigns']);
        add_submenu_page('mad-em', self::tr('SMTP 设置'), self::tr('SMTP 设置'), self::CAP, 'mad-em-settings', [__CLASS__, 'page_settings']);
    }

    public static function enqueue_admin_assets() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'mad-em') !== 0 || !self::can_manage()) return;
        wp_enqueue_style('madevma-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('madevma-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', [], self::VERSION, true);
        wp_localize_script('madevma-admin', 'madevmaMailer', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'previewNonce' => wp_create_nonce('mad_em_preview_send'),
            'templateVars' => self::admin_selected_template_vars(),
            'confirmDelete' => self::tr('确定删除吗？'),
            'varPlaceholder' => self::tr('这里填写全局默认值；如果 CSV 中有同名列，会优先使用每个收件人自己的值。'),
            'previewTitle' => self::tr('发送前预览'),
            'previewStatus' => self::tr('静态预览：变量会保留为 {{变量名}}，不会发送邮件。'),
            'testTitle' => self::tr('发送测试邮件'),
            'testHelp' => self::tr('填写测试邮箱和变量示例值。只有点击下面的“发送测试邮件”才会真正发送。'),
            'testPlaceholder' => self::tr('测试示例值；不填则保留 {{变量名}}'),
            'emailRequired' => self::tr('请填写测试邮箱。'),
            'sending' => self::tr('正在发送测试邮件...'),
            'sent' => self::tr('测试邮件已发送。'),
            'failed' => self::tr('测试邮件发送失败。'),
            'failedPermission' => self::tr('测试邮件发送失败，请检查 SMTP 设置或后台权限。'),
        ]);
    }

    private static function admin_selected_template_vars() {
        if (empty($_GET['template_id'])) return [];
        global $wpdb;
        $template_id = absint(wp_unslash($_GET['template_id']));
        $template = $wpdb->get_row($wpdb->prepare('SELECT subject, html FROM %i WHERE id=%d', self::table('templates'), $template_id));
        return $template ? self::extract_vars($template->html . ' ' . $template->subject) : [];
    }

    public static function enqueue_public_assets() {
        if (!is_singular()) return;
        $post = get_post();
        if (!$post || !has_shortcode((string) $post->post_content, 'mad_email_register')) return;
        wp_enqueue_style('madevma-public', plugin_dir_url(__FILE__) . 'assets/public.css', [], self::VERSION);
        wp_enqueue_script('madevma-public', plugin_dir_url(__FILE__) . 'assets/public.js', [], self::VERSION, true);
    }

    private static function notice($msg, $type='success') { echo '<div class="notice notice-'.esc_attr($type).' is-dismissible"><p>'.esc_html(self::tr($msg)).'</p></div>'; }
    private static function status_label($status) { $map = ['subscribed'=>'已订阅','unsubscribed'=>'已退订','draft'=>'草稿','scheduled'=>'已定时','queued'=>'排队中','sending'=>'发送中','finished'=>'已完成','pending'=>'待发送','sent'=>'已发送','failed'=>'发送失败']; return $map[$status] ?? $status; }
    private static function wrap_start($title) { echo '<div class="wrap"><h1>'.esc_html($title).'</h1>'; }
    private static function wrap_end() { echo '</div>'; }
    private static function nonce($action) { wp_nonce_field($action, 'mad_em_nonce'); echo '<input type="hidden" name="mad_em_action" value="'.esc_attr($action).'">'; }
    private static function verify($action) {
        return self::can_manage()
            && isset($_POST['mad_em_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad_em_nonce'])), $action);
    }

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
        if (!self::can_manage()) wp_die(esc_html(self::tr('权限不足。')));
        $template_id = absint(wp_unslash($_GET['template_id'] ?? $_GET['mad_em_export_template_csv'] ?? 0));
        if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mad_em_export_template_csv_'.$template_id)) wp_die(esc_html(self::tr('导出链接已过期，请返回后台重新导出。')));
        global $wpdb;
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_die(esc_html(self::tr('模板不存在。')));
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
        echo self::csv_line(array_merge(['example@example.com', self::tr('张三'), self::tr('活动名称或 slug')], array_fill(0, count($vars), '')));
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
        if (!self::can_manage()) wp_die(esc_html(self::tr('权限不足。')));
        $template_id = absint(wp_unslash($_GET['template_id'] ?? 0));
        if (!$template_id || !isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mad_em_preview_template_'.$template_id)) wp_die(esc_html(self::tr('链接已过期，请返回后台重新打开预览。')));
        global $wpdb;
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_die(esc_html(self::tr('模板不存在。')));
        $vars = self::extract_vars($template->html . ' ' . $template->subject);
        $sample = [];
        foreach ($vars as $v) {
            if (in_array($v, ['name','name1'], true)) $sample[$v] = self::tr('张三');
            elseif (in_array($v, ['title','title1'], true)) $sample[$v] = self::tr('示例邮件标题');
            elseif ($v === 'email') $sample[$v] = 'example@example.com';
            elseif ($v === 'unsubscribe_url') $sample[$v] = self::get_unsubscribe_url();
            else $sample[$v] = self::tr('示例 ').$v;
        }
        $sample['unsubscribe_url'] = self::get_unsubscribe_url();
        header('Content-Type: text/html; charset=UTF-8');
        $set = self::settings();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Email HTML is filtered by safe_email_html() immediately before output.
        echo self::safe_email_html( self::render_template( self::ensure_unsubscribe_notice( (string) $template->html, sanitize_text_field( $set['default_unsubscribe_lang'] ?? 'zh' ), ! empty( $set['default_unsubscribe_button'] ) ), $sample ) );
        exit;
    }

    public static function ajax_preview_send() {
        if (!self::can_manage()) wp_send_json_error(['message'=>self::tr('权限不足。')], 403);
        check_ajax_referer('mad_em_preview_send', 'nonce');
        global $wpdb;
        $template_id = absint(wp_unslash($_POST['template_id'] ?? 0));
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_send_json_error(['message'=>self::tr('模板不存在。')]);
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
        if (!self::can_manage()) wp_send_json_error(['message'=>self::tr('权限不足。')], 403);
        check_ajax_referer('mad_em_preview_send', 'nonce');
        global $wpdb;
        $to = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        if (!is_email($to)) wp_send_json_error(['message'=>self::tr('请填写有效的测试邮箱。')]);
        $template_id = absint(wp_unslash($_POST['template_id'] ?? 0));
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_send_json_error(['message'=>self::tr('模板不存在。')]);
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
        $ok = wp_mail($to, $subj, $body, self::mail_headers());
        if (!$ok) wp_send_json_error(['message'=>self::tr('测试邮件发送失败，请检查 SMTP 设置或服务器日志。')]);
        wp_send_json_success(['message'=>self::tr('测试邮件已发送到').$to]);
    }

    public static function maybe_handle_post() {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
        if (empty($_POST['mad_em_action'])) return;
        $action = sanitize_text_field(wp_unslash($_POST['mad_em_action']));
        $nonce_action = in_array($action, ['save_campaign_draft','export_current_csv','preview_current_static'], true) ? 'create_campaign' : $action;
        if ($action === 'export_subscribers_csv') $nonce_action = 'export_subscribers_csv';
        if (!self::can_manage()) wp_die(esc_html(self::tr('权限不足。')), '', ['response' => 403]);
        check_admin_referer($nonce_action, 'mad_em_nonce');
        global $wpdb;

        if ($action === 'save_settings') {
            $sender_name = sanitize_text_field(wp_unslash($_POST['sender_name'] ?? ($_POST['from_name'] ?? '')));
            update_option(self::OPT, [
                'host'=>sanitize_text_field(wp_unslash($_POST['host'] ?? '')), 'port'=>sanitize_text_field(wp_unslash($_POST['port'] ?? '465')),
                'secure'=>sanitize_text_field(wp_unslash($_POST['secure'] ?? 'ssl')), 'username'=>sanitize_text_field(wp_unslash($_POST['username'] ?? '')),
                'password'=>sanitize_text_field(wp_unslash($_POST['password'] ?? '')), 'from_email'=>sanitize_email(wp_unslash($_POST['from_email'] ?? '')),
                'from_name'=>$sender_name, 'sender_name'=>$sender_name, 'reply_to'=>sanitize_email(wp_unslash($_POST['reply_to'] ?? '')),
                'batch_size'=>max(1, (int) sanitize_text_field(wp_unslash($_POST['batch_size'] ?? 30))),
                'register_page_url'=>esc_url_raw(wp_unslash($_POST['register_page_url'] ?? '')),
                'register_page_url_zh'=>esc_url_raw(wp_unslash($_POST['register_page_url_zh'] ?? '')),
                'register_page_url_en'=>esc_url_raw(wp_unslash($_POST['register_page_url_en'] ?? '')),
                'default_unsubscribe_button'=>!empty($_POST['default_unsubscribe_button']) ? 1 : 0,
                'default_unsubscribe_lang'=>in_array(sanitize_text_field(wp_unslash($_POST['default_unsubscribe_lang'] ?? 'zh')), ['zh','en'], true) ? sanitize_text_field(wp_unslash($_POST['default_unsubscribe_lang'])) : 'zh',
                'ui_language'=>in_array(sanitize_text_field(wp_unslash($_POST['ui_language'] ?? 'auto')), ['zh_CN','en_US','auto'], true) ? sanitize_text_field(wp_unslash($_POST['ui_language'])) : 'auto',
                'public_language'=>in_array(sanitize_text_field(wp_unslash($_POST['public_language'] ?? 'auto')), ['zh_CN','en_US','auto'], true) ? sanitize_text_field(wp_unslash($_POST['public_language'])) : 'auto'
            ]);
            add_action('admin_notices', fn()=>self::notice($sender_name !== '' ? '设置已保存。发件人姓名已更新为：'.$sender_name : '设置已保存。发件人姓名留空时会使用 WordPress 站点名称。'));
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
            else {
                $max_order = (int)$wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort_order),0) FROM %i', self::table('events') ) );
                $data['sort_order']=$max_order + 10;
                $data['created_at']=self::now();
                $wpdb->insert(self::table('events'), $data);
            }
            add_action('admin_notices', fn()=>self::notice('活动已保存。'));
        }

        if ($action === 'delete_event') {
            $id = absint(wp_unslash($_POST['id']));
            $wpdb->delete(self::table('subscriber_events'), ['event_id'=>$id]);
            $wpdb->delete(self::table('events'), ['id'=>$id]);
            add_action('admin_notices', fn()=>self::notice('活动已删除。'));
        }

        if ($action === 'save_event_order') {
            $order = isset($_POST['event_order']) && is_array($_POST['event_order']) ? array_map('absint', wp_unslash($_POST['event_order'])) : [];
            foreach (array_values(array_filter($order)) as $index => $event_id) {
                $wpdb->update(self::table('events'), ['sort_order'=>($index + 1) * 10], ['id'=>$event_id]);
            }
            add_action('admin_notices', fn()=>self::notice('活动排序已保存。'));
        }

        if ($action === 'save_subscriber') {
            self::save_subscriber_from_post();
            add_action('admin_notices', fn()=>self::notice('收件人已保存。'));
        }

        if ($action === 'export_subscribers_csv') {
            self::export_subscribers_csv_from_post();
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
        if (!self::can_manage()) wp_die(esc_html(self::tr('权限不足。')));
        $template = self::current_template_from_post();
        if (!$template) wp_die(esc_html(self::tr('请先选择模板。')));
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
        echo self::csv_line(array_merge(['example@example.com', self::tr('张三'), self::tr('活动名称或 slug')], array_fill(0, count($vars), '')));
        exit;
    }

    private static function preview_current_form_static() {
        if (!self::can_manage()) wp_die(esc_html(self::tr('权限不足。')));
        $template = self::current_template_from_post();
        if (!$template) wp_die(esc_html(self::tr('请先选择模板。')));
        $html = (string)$template->html;
        // 发送前预览只做结构预览：所有变量都保留为 {{变量名}}，不读取收件人或变量实际值，也不会发送邮件。
        $include_unsub = !empty($_POST['include_unsubscribe']);
        $unsub_lang_value = sanitize_text_field(wp_unslash($_POST['unsubscribe_lang'] ?? 'zh'));
        $unsub_lang = in_array($unsub_lang_value, ['zh','en'], true) ? $unsub_lang_value : 'zh';
        $html = self::ensure_unsubscribe_notice($html, $unsub_lang, $include_unsub);
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: text/html; charset=UTF-8');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Email HTML is filtered by safe_email_html() immediately before output.
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

    private static function normalize_subscription_language($lang) {
        $lang = strtolower((string)$lang);
        return in_array($lang, ['zh','en'], true) ? $lang : 'zh';
    }

    private static function request_subscription_language() {
        if (isset($_POST['subscription_language'])) return self::normalize_subscription_language(sanitize_text_field(wp_unslash($_POST['subscription_language'])));
        if (isset($_GET['mad_em_lang'])) return self::normalize_subscription_language(sanitize_text_field(wp_unslash($_GET['mad_em_lang'])));
        return self::is_english_language(self::current_public_language()) ? 'en' : 'zh';
    }

    private static function language_label($lang) {
        return self::normalize_subscription_language($lang) === 'en' ? '英文' : '中文';
    }

    private static function event_language_value($event_id, $lang) {
        return absint($event_id) . '|' . self::normalize_subscription_language($lang);
    }

    private static function parse_event_language_value($value) {
        $parts = explode('|', (string)$value);
        return [absint($parts[0] ?? 0), self::normalize_subscription_language($parts[1] ?? 'zh')];
    }

    private static function event_language_label($event, $lang) {
        return trim((string)$event->name) . ' ' . self::language_label($lang);
    }

    private static function recipient_list_key($value) {
        return strtolower(trim((string)$value));
    }

    private static function recipient_list_map($events) {
        $map = [];
        foreach ($events as $e) {
            $name = trim((string)$e->name);
            $slug = trim((string)$e->slug);
            foreach (['zh','en'] as $lang) {
                $list = ['event_id'=>(int)$e->id, 'language'=>$lang];
                $suffixes = $lang === 'en' ? ['英文','English','english','en'] : ['中文','Chinese','chinese','zh'];
                foreach (array_filter([$name, $slug]) as $base) {
                    foreach ($suffixes as $suffix) {
                        $map[self::recipient_list_key($base . ' ' . $suffix)] = $list;
                        $map[self::recipient_list_key($base . '-' . $suffix)] = $list;
                    }
                }
                $map[self::recipient_list_key(self::event_language_value($e->id, $lang))] = $list;
            }
        }
        return $map;
    }

    private static function parse_recipient_list_tokens($event_text, $event_map) {
        $lists = [];
        foreach (preg_split('/[,;，、]+/', (string)$event_text) as $token) {
            $token = trim((string)$token);
            if ($token === '') continue;
            $key = self::recipient_list_key($token);
            if (isset($event_map[$key])) {
                $lists[] = $event_map[$key];
                continue;
            }
            [$event_id, $language] = self::parse_event_language_value($token);
            if ($event_id) $lists[] = ['event_id'=>$event_id, 'language'=>$language];
        }
        $unique = [];
        foreach ($lists as $list) {
            $key = (int)$list['event_id'] . '|' . self::normalize_subscription_language($list['language']);
            $unique[$key] = ['event_id'=>(int)$list['event_id'], 'language'=>self::normalize_subscription_language($list['language'])];
        }
        return array_values($unique);
    }

    private static function default_recipient_list_from_post($field = 'default_event') {
        if (empty($_POST[$field])) return [];
        [$event_id, $language] = self::parse_event_language_value(sanitize_text_field(wp_unslash($_POST[$field])));
        return $event_id ? [['event_id'=>$event_id, 'language'=>$language]] : [];
    }

    private static function get_register_page_url($lang = '') {
        $s = self::settings();
        $lang = self::normalize_subscription_language($lang ?: 'zh');
        $key = $lang === 'en' ? 'register_page_url_en' : 'register_page_url_zh';
        if (!empty($s[$key])) return $s[$key];
        return !empty($s['register_page_url']) ? $s['register_page_url'] : home_url('/');
    }

    private static function get_unsubscribe_url($lang = '') {
        $lang = self::normalize_subscription_language($lang ?: 'zh');
        return add_query_arg(['mad_em_action'=>'unsubscribe', 'mad_em_lang'=>$lang], self::get_register_page_url($lang));
    }

    private static function current_public_url() {
        $object_id = get_queried_object_id();
        $permalink = $object_id ? get_permalink($object_id) : '';
        return $permalink ? $permalink : home_url('/');
    }

    private static function public_form_redirect_url($language) {
        $fallback = self::get_register_page_url($language);
        $posted = esc_url_raw(wp_unslash($_POST['mad_em_redirect'] ?? ''));
        return $posted ? wp_validate_redirect($posted, $fallback) : $fallback;
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
            return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE active=%d ORDER BY sort_order ASC, id DESC', self::table('events'), 1 ) );
        }
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id DESC', self::table('events') ) );
    }
    private static function get_templates() {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', self::table('templates') ) );
    }

    private static function upsert_subscriber($email, $name, $event_ids, $source='manual', $merge_events=false, $language='zh') {
        $language = self::normalize_subscription_language($language);
        $event_lists = [];
        foreach ($event_ids as $eid) if ($eid) $event_lists[] = ['event_id'=>(int)$eid, 'language'=>$language];
        return self::upsert_subscriber_event_lists($email, $name, $event_lists, $source, $merge_events);
    }

    private static function upsert_subscriber_event_lists($email, $name, $event_lists, $source='manual', $merge_events=false) {
        global $wpdb;
        if (!is_email($email)) return 0;
        $table = self::table('subscribers');
        $id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email=%s', $table, $email ) );
        $data = ['email'=>$email, 'name'=>$name, 'status'=>'subscribed', 'source'=>$source, 'updated_at'=>self::now()];
        if ($id) $wpdb->update($table, $data, ['id'=>$id]); else { $data['created_at']=self::now(); $wpdb->insert($table, $data); $id = (int)$wpdb->insert_id; }
        if (!$merge_events) $wpdb->delete(self::table('subscriber_events'), ['subscriber_id'=>$id]);
        foreach ($event_lists as $list) {
            $event_id = absint($list['event_id'] ?? 0);
            $language = self::normalize_subscription_language($list['language'] ?? 'zh');
            if ($event_id) $wpdb->replace(self::table('subscriber_events'), ['subscriber_id'=>$id, 'event_id'=>$event_id, 'language'=>$language], ['%d','%d','%s']);
        }
        return $id;
    }

    private static function default_subscription_template_html($language) {
        global $wpdb;
        $language = self::normalize_subscription_language($language);
        $table = self::table('templates');
        $preferred_id = $language === 'en' ? 2 : 1;
        $html = $wpdb->get_var( $wpdb->prepare( 'SELECT html FROM %i WHERE id=%d LIMIT 1', $table, $preferred_id ) );
        if ($html) return (string)$html;
        if ($language === 'en') {
            $html = $wpdb->get_var( $wpdb->prepare( 'SELECT html FROM %i WHERE name IN (%s,%s) ORDER BY id ASC LIMIT 1', $table, '默认英文模板', 'Default English Template' ) );
        } else {
            $html = $wpdb->get_var( $wpdb->prepare( 'SELECT html FROM %i WHERE name=%s ORDER BY id ASC LIMIT 1', $table, '默认中文模板' ) );
        }
        if ($html) return (string)$html;
        $file = plugin_dir_path(__FILE__) . ($language === 'en' ? 'default-template-en.html' : 'default-template-zh.html');
        $html = self::read_local_file($file);
        return $html ?: '{{message1}}';
    }

    private static function send_subscription_notice($email, $name, $language, $type='subscribe') {
        if (!is_email($email)) return false;
        $language = self::normalize_subscription_language($language);
        $display_name = $name ?: $email;
        $safe_display_name = esc_html($display_name);
        if ($type === 'unsubscribe') {
            if ($language === 'en') {
                $subject = 'Subscription cancelled';
                $message = '<p>Your event notification subscription has been cancelled.</p>';
            } else {
                $subject = '订阅已退订';
                $message = '<p>你的活动通知订阅已经退订。</p>';
            }
        } elseif ($language === 'en') {
            $subject = 'Subscription confirmed';
            $message = '<p>Welcome to MAD Producer event notifications. Your subscription has been saved successfully.</p>';
        } else {
            $subject = '订阅已确认';
            $message = '<p>欢迎使用 MAD Producer 活动通知。你的订阅已经保存成功。</p>';
        }
        $template_html = self::default_subscription_template_html($language);
        $vars = [
            'email' => $email,
            'name' => $safe_display_name,
            'name1' => $safe_display_name,
            'title' => $subject,
            'title1' => $subject,
            'message' => $message,
            'message1' => $message,
            'unsubscribe_url' => self::get_unsubscribe_url($language),
        ];
        foreach (self::extract_vars($template_html) as $v) {
            if (!array_key_exists($v, $vars)) $vars[$v] = '';
        }
        $body = self::render_template($template_html, $vars);
        return wp_mail($email, $subject, $body, self::mail_headers());
    }

    private static function subscriber_event_language_values($subscriber_id) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id, language FROM %i WHERE subscriber_id=%d', self::table('subscriber_events'), $subscriber_id ) );
        $values = [];
        foreach ($rows as $row) $values[] = self::event_language_value($row->event_id, $row->language);
        return $values;
    }

    private static function save_subscriber_from_post() {
        global $wpdb;
        $id = absint(wp_unslash($_POST['id'] ?? 0));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!is_email($email)) return 0;
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $status = in_array(sanitize_text_field(wp_unslash($_POST['status'] ?? 'subscribed')), ['subscribed','unsubscribed'], true) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'subscribed';
        $table = self::table('subscribers');
        $existing_id = (int)$wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email=%s', $table, $email ) );
        if ($id && $existing_id && $existing_id !== $id) return 0;
        $data = ['email'=>$email, 'name'=>$name, 'status'=>$status, 'source'=>'manual', 'updated_at'=>self::now()];
        if ($id) $wpdb->update($table, $data, ['id'=>$id]);
        elseif ($existing_id) { $id = $existing_id; $wpdb->update($table, $data, ['id'=>$id]); }
        else { $data['created_at']=self::now(); $wpdb->insert($table, $data); $id=(int)$wpdb->insert_id; }
        $wpdb->delete(self::table('subscriber_events'), ['subscriber_id'=>$id]);
        $lists = isset($_POST['event_lists']) && is_array($_POST['event_lists']) ? wp_unslash($_POST['event_lists']) : [];
        foreach ($lists as $list_value) {
            [$event_id, $language] = self::parse_event_language_value($list_value);
            if ($event_id) $wpdb->replace(self::table('subscriber_events'), ['subscriber_id'=>$id, 'event_id'=>$event_id, 'language'=>$language], ['%d','%d','%s']);
        }
        return $id;
    }

    private static function subscriber_filter_parts($value) {
        if ($value === '' || $value === '0') return [0, ''];
        return self::parse_event_language_value($value);
    }

    private static function get_filtered_subscribers($filter_value = '0', $limit = 200) {
        global $wpdb;
        [$event_id, $language] = self::subscriber_filter_parts($filter_value);
        $subs = self::table('subscribers'); $se = self::table('subscriber_events');
        if ($event_id && $language) {
            return $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT s.* FROM %i s INNER JOIN %i se ON s.id=se.subscriber_id WHERE se.event_id=%d AND se.language=%s ORDER BY s.id DESC LIMIT %d', $subs, $se, $event_id, $language, $limit ) );
        }
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $subs, $limit ) );
    }

    private static function subscriber_event_labels($subscriber_id) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT e.name, se.language FROM %i se JOIN %i e ON e.id=se.event_id WHERE se.subscriber_id=%d ORDER BY e.sort_order ASC, e.id DESC, se.language ASC', self::table('subscriber_events'), self::table('events'), $subscriber_id ) );
        $labels = [];
        foreach ($rows as $row) $labels[] = $row->name . ' ' . self::language_label($row->language);
        return $labels;
    }

    private static function export_subscribers_csv_from_post() {
        $filter_value = sanitize_text_field(wp_unslash($_POST['subscriber_filter'] ?? '0'));
        $rows = self::get_filtered_subscribers($filter_value, 100000);
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=mad-mailer-subscribers.csv');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 BOM for CSV download.
        echo "\xEF\xBB\xBF";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV fields are encoded by csv_line(); this is not HTML output.
        echo self::csv_line(['email','name','status','events']);
        foreach ($rows as $row) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV fields are encoded by csv_line(); this is not HTML output.
            echo self::csv_line([$row->email, $row->name, $row->status, implode('; ', self::subscriber_event_labels($row->id))]);
        }
        exit;
    }

    private static function import_csv($path) {
        $rows = self::parse_csv_file($path);
        if (empty($rows)) return 0;
        $header = array_shift($rows);
        if (!$header) return 0;
        $header = array_map(fn($h)=>strtolower(trim((string) $h)), $header);
        $count = 0; $event_map = self::recipient_list_map(self::get_events(false));
        foreach ($rows as $row) {
            $data = array_combine($header, array_pad($row, count($header), ''));
            if (!is_array($data)) continue;
            $email = sanitize_email($data['email'] ?? $data['邮箱'] ?? '');
            $name = sanitize_text_field($data['name'] ?? $data['姓名'] ?? '');
            $event_text = $data['events'] ?? $data['event'] ?? $data['活动'] ?? '';
            $event_lists = self::parse_recipient_list_tokens($event_text, $event_map);
            if (empty($event_lists)) $event_lists = self::default_recipient_list_from_post();
            if (self::upsert_subscriber_event_lists($email, $name, $event_lists, 'csv')) $count++;
        }
        return $count;
    }

    private static function create_campaign_from_post($as_draft=false) {
        global $wpdb;
        $template_id = absint(wp_unslash($_POST['template_id'] ?? 0));
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) return 0;
        [$event_id, $recipient_language] = self::parse_event_language_value(sanitize_text_field(wp_unslash($_POST['event_id'] ?? '0|zh')));
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
        $vars['__recipient_language'] = $recipient_language;
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
                self::prepare_logs($cid, $event_id, $recipient_language);
            }
        }
        return $cid;
    }

    private static function prepare_logs($campaign_id, $event_id=0, $language='') {
        global $wpdb;
        $subs = self::table('subscribers'); $se = self::table('subscriber_events'); $logs = self::table('campaign_logs');
        $language = $language ? self::normalize_subscription_language($language) : '';
        if ($event_id && $language) $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT s.* FROM %i s INNER JOIN %i se ON s.id=se.subscriber_id WHERE s.status=%s AND se.event_id=%d AND se.language=%s', $subs, $se, 'subscribed', $event_id, $language ) );
        elseif ($event_id) $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT s.* FROM %i s INNER JOIN %i se ON s.id=se.subscriber_id WHERE s.status=%s AND se.event_id=%d', $subs, $se, 'subscribed', $event_id ) );
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
        $event_map = self::recipient_list_map(self::get_events(false));
        $count = 0; $editable = self::editable_vars($template_vars);
        foreach ($rows as $row) {
            $data = array_combine($header, array_pad($row, count($header), ''));
            if (!is_array($data)) continue;
            $email = sanitize_email($data['email'] ?? $data['邮箱'] ?? '');
            $name = sanitize_text_field($data['name'] ?? $data['姓名'] ?? '');
            if (!is_email($email)) continue;
            $event_text = $data['events'] ?? $data['event'] ?? $data['活动'] ?? '';
            $event_lists = self::parse_recipient_list_tokens($event_text, $event_map);
            $sid = self::upsert_subscriber_event_lists($email, $name, $event_lists, 'csv-campaign', true);
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
                $vars['title'] = $vars['title'] ?? $c->subject;
                $vars['title1'] = $vars['title1'] ?? $c->subject;
                $subject = self::render_template($c->subject, $vars);
                $include_unsub = !empty($vars['__include_unsubscribe']);
                $unsub_lang = in_array(($vars['__unsubscribe_lang'] ?? 'zh'), ['zh','en'], true) ? $vars['__unsubscribe_lang'] : 'zh';
                $vars['unsubscribe_url'] = self::get_unsubscribe_url($unsub_lang);
                $body = self::render_template(self::ensure_unsubscribe_notice($template->html, $unsub_lang, $include_unsub), $vars);
                $ok = wp_mail($log->email, $subject, $body, self::mail_headers());
                $wpdb->update($log_table, ['status'=>$ok?'sent':'failed', 'error'=>$ok?'':'wp_mail failed', 'sent_at'=>self::now()], ['id'=>$log->id]);
                $ok ? $wpdb->query( $wpdb->prepare( 'UPDATE %i SET sent=sent+1 WHERE id=%d', $campaign_table, $c->id ) ) : null;
                if (!$ok) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET failed=failed+1 WHERE id=%d', $campaign_table, $c->id ) );
            }
            $pending = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE campaign_id=%d AND status=%s', $log_table, $c->id, 'pending' ) );
            if (!$pending) $wpdb->update($campaign_table, ['status'=>'finished', 'sent_at'=>self::now()], ['id'=>$c->id]);
        }
    }

    public static function page_settings() { self::render_admin_page('render_page_settings'); }
    private static function render_page_settings() {
        $s = self::settings();
        $sender_value = trim((string)($s['sender_name'] ?? ($s['from_name'] ?? '')));
        self::wrap_start('SMTP 设置'); ?>
        <form method="post"><?php self::nonce('save_settings'); ?>
        <table class="form-table"><tr><th>SMTP 地址</th><td><input class="regular-text" name="host" value="<?php echo esc_attr($s['host']); ?>" placeholder="smtp.feishu.cn"></td></tr>
        <tr><th>发送协议</th><td><select name="secure"><option value="ssl" <?php selected($s['secure'],'ssl'); ?>>ssl</option><option value="tls" <?php selected($s['secure'],'tls'); ?>>tls</option></select></td></tr>
        <tr><th>端口</th><td><input name="port" value="<?php echo esc_attr($s['port']); ?>"></td></tr>
        <tr><th>邮箱账号</th><td><input class="regular-text" name="username" value="<?php echo esc_attr($s['username']); ?>"></td></tr>
        <tr><th>邮箱密码</th><td><input class="regular-text" type="password" name="password" value="<?php echo esc_attr($s['password']); ?>"></td></tr>
        <tr><th>发件邮箱</th><td><input class="regular-text" name="from_email" value="<?php echo esc_attr($s['from_email']); ?>"></td></tr>
        <tr><th>发件人姓名</th><td><input class="regular-text" name="sender_name" value="<?php echo esc_attr($sender_value); ?>" placeholder="MAD Producer 麦德工坊"><p class="description">邮件里显示的发件人名称。留空时发送邮件会使用 WordPress 站点名称。</p></td></tr>
        <tr><th>回复地址</th><td><input class="regular-text" name="reply_to" value="<?php echo esc_attr($s['reply_to']); ?>"></td></tr>
        <tr><th>每批发送数量</th><td><input type="number" name="batch_size" value="<?php echo esc_attr($s['batch_size']); ?>"> 封 / 每次定时任务</td></tr>
        <tr><th>订阅 / 退订页面固定链接</th><td><input class="regular-text" name="register_page_url" value="<?php echo esc_attr($s['register_page_url']); ?>" placeholder="https://example.com/mail-subscribe/"><p class="description">通用固定链接，未设置语言专属链接时作为备用。</p></td></tr>
        <tr><th>中文订阅 / 退订固定链接</th><td><input class="regular-text" name="register_page_url_zh" value="<?php echo esc_attr($s['register_page_url_zh']); ?>" placeholder="https://example.com/mail-subscribe/"><p class="description">中文订阅确认、退订入口和邮件底部中文退订链接优先使用这个地址。</p></td></tr>
        <tr><th>英文订阅 / 退订固定链接</th><td><input class="regular-text" name="register_page_url_en" value="<?php echo esc_attr($s['register_page_url_en']); ?>" placeholder="https://example.com/en/mail-subscribe/"><p class="description">English subscription confirmation, unsubscribe entry, and English footer links use this URL first.</p></td></tr>
        <tr><th>默认退订按钮</th><td><label><input type="checkbox" name="default_unsubscribe_button" value="1" <?php checked(!empty($s['default_unsubscribe_button'])); ?>> 新建发送任务时默认在邮件底部添加“订阅管理 / 退订”按钮</label><p class="description">这个按钮由插件自动追加，不需要写在 HTML 模板或正文里。</p></td></tr>
        <tr><th>默认退订按钮语言</th><td><select name="default_unsubscribe_lang"><option value="zh" <?php selected($s['default_unsubscribe_lang'] ?? 'zh','zh'); ?>>中文</option><option value="en" <?php selected($s['default_unsubscribe_lang'] ?? 'zh','en'); ?>>English</option></select></td></tr>
        <tr><th>后台界面语言</th><td><select name="ui_language"><option value="zh_CN" <?php selected($s['ui_language'] ?? 'auto','zh_CN'); ?>>中文</option><option value="en_US" <?php selected($s['ui_language'] ?? 'auto','en_US'); ?>>English</option><option value="auto" <?php selected($s['ui_language'] ?? 'auto','auto'); ?>>跟随 WordPress 站点语言</option></select><p class="description">切换后保存并刷新 MAD 邮件后台页面即可生效。</p></td></tr>
        <tr><th>前台订阅页语言</th><td><select name="public_language"><option value="zh_CN" <?php selected($s['public_language'] ?? 'auto','zh_CN'); ?>>中文</option><option value="en_US" <?php selected($s['public_language'] ?? 'auto','en_US'); ?>>English</option><option value="auto" <?php selected($s['public_language'] ?? 'auto','auto'); ?>>跟随 WordPress 站点语言</option></select><p class="description">影响短代码 <code>[mad_email_register]</code> 生成的订阅、查询和退订表单。</p></td></tr></table>
        <?php submit_button('保存设置'); ?></form><?php self::wrap_end();
    }

    public static function page_templates() { self::render_admin_page('render_page_templates'); }
    private static function render_page_templates() {
        global $wpdb;
        $edit = null;
        if (!empty($_GET['edit'])) $edit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), absint(wp_unslash($_GET['edit'])) ) );
        self::wrap_start('邮件模板');
        $show_query = !empty($query_result);
        ?>
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
        <tr><td><?php echo (int)$t->id; ?></td><td><?php echo esc_html($t->name); ?><?php if (self::is_builtin_template($t->id)) echo ' <span class="description">通用模板</span>'; ?></td><td><?php echo esc_html($t->subject); ?></td><td><?php echo esc_html(implode(', ', $vars)); ?></td><td class="mad-em-template-actions"><a href="<?php echo esc_url(admin_url('admin.php?page=mad-em-templates&edit='.$t->id)); ?>">编辑</a><button type="button" class="button-link mad-em-template-preview" data-url="<?php echo esc_url(self::preview_url($t->id)); ?>">预览</button><a href="<?php echo esc_url(self::export_url($t->id)); ?>">导出收件人模板</a><?php if (!self::is_builtin_template($t->id)): ?><form method="post" data-confirm-delete="确定删除吗？"><?php self::nonce('delete_template'); ?><input type="hidden" name="id" value="<?php echo (int)$t->id; ?>"><button class="button-link-delete">删除</button></form><?php else: ?><span class="description">不可删除</span><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <div class="mad-em-modal" id="madEmTemplateModal"><div class="mad-em-modal-box"><div class="mad-em-modal-head"><strong>模板预览</strong><button type="button" class="button" id="madEmTemplateClose">关闭</button></div><iframe id="madEmTemplateFrame"></iframe></div></div>
        <?php self::wrap_end();
    }

    public static function page_events() { self::render_admin_page('render_page_events'); }
    private static function render_page_events() {
        global $wpdb; $edit=null; if (!empty($_GET['edit'])) $edit=$wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('events'), absint(wp_unslash($_GET['edit'])) ) );
        self::wrap_start('活动管理'); ?>
        <form method="post"><?php self::nonce('save_event'); ?><input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
        <table class="form-table"><tr><th>活动名称</th><td><input class="regular-text" name="name" required value="<?php echo esc_attr($edit->name ?? ''); ?>"></td></tr>
        <tr><th>别名 / Slug</th><td><input class="regular-text" name="slug" value="<?php echo esc_attr($edit->slug ?? ''); ?>"></td></tr>
        <tr><th>活动描述</th><td><textarea class="large-text" name="description" rows="3"><?php echo esc_textarea($edit->description ?? ''); ?></textarea></td></tr>
        <tr><th>启用</th><td><label><input type="checkbox" name="active" <?php checked(($edit->active ?? 1), 1); ?>> 在前台注册表单中显示</label></td></tr></table><?php submit_button('保存活动'); ?></form>
        <form method="post" id="mad-em-event-order-form"><?php self::nonce('save_event_order'); ?>
        <table class="widefat striped"><thead><tr><th style="width:70px">排序</th><th>ID</th><th>活动名称</th><th>别名 / Slug</th><th>中文收件人</th><th>英文收件人</th><th>启用</th><th>操作</th></tr></thead><tbody id="mad-em-event-order"><?php foreach (self::get_events(false) as $e): $zh_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE event_id=%d AND language=%s', self::table('subscriber_events'), $e->id, 'zh')); $en_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE event_id=%d AND language=%s', self::table('subscriber_events'), $e->id, 'en')); ?>
        <tr draggable="true"><td class="mad-em-drag-handle">↕ 拖拽<input type="hidden" name="event_order[]" value="<?php echo (int)$e->id; ?>"></td><td><?php echo (int)$e->id; ?></td><td><?php echo esc_html($e->name); ?></td><td><?php echo esc_html($e->slug); ?></td><td><?php echo esc_html((string)$zh_count); ?></td><td><?php echo esc_html((string)$en_count); ?></td><td><?php echo $e->active ? '是' : '否'; ?></td><td><a href="<?php echo esc_url(admin_url('admin.php?page=mad-em-events&edit='.$e->id)); ?>">编辑</a> <button class="button-link-delete" type="submit" form="mad-em-delete-event-<?php echo (int)$e->id; ?>" data-confirm="确定删除这个活动吗？">删除</button></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php submit_button('保存活动排序', 'secondary'); ?></form>
        <?php foreach (self::get_events(false) as $e): ?><form method="post" id="mad-em-delete-event-<?php echo (int)$e->id; ?>" style="display:none"><?php self::nonce('delete_event'); ?><input type="hidden" name="id" value="<?php echo (int)$e->id; ?>"></form><?php endforeach; ?>
        <?php self::wrap_end();
    }

    public static function page_subscribers() { self::render_admin_page('render_page_subscribers'); }
    private static function render_page_subscribers() {
        global $wpdb;
        $events = self::get_events(false);
        $edit_id = absint(wp_unslash($_GET['edit'] ?? 0));
        $edit = $edit_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('subscribers'), $edit_id ) ) : null;
        $selected_lists = $edit ? self::subscriber_event_language_values($edit->id) : [];
        $filter_value = sanitize_text_field(wp_unslash($_GET['subscriber_filter'] ?? '0'));
        $rows = self::get_filtered_subscribers($filter_value, 200);
        $total = count($rows);
        self::wrap_start('收件人'); ?>
        <p>支持的 CSV 列名：<code>email,name,events</code>，也兼容 <code>邮箱,姓名,活动</code>。活动需填写后台收件人列表名称，例如 <code>站点公告 中文</code> 或 <code>站点公告 英文</code>，多个列表用逗号分隔。</p>
        <form method="post" enctype="multipart/form-data"><?php self::nonce('import_csv'); ?><input type="file" name="csv_file" accept=".csv" required> 默认活动： <select name="default_event"><option value="0">不指定</option><?php foreach($events as $e) foreach(['zh','en'] as $list_lang) echo '<option value="'.esc_attr(self::event_language_value($e->id,$list_lang)).'">'.esc_html(self::event_language_label($e,$list_lang)).'</option>'; ?></select> <?php submit_button('导入 CSV', 'secondary', 'submit', false); ?></form>
        <h2><?php echo $edit ? '编辑收件人' : '添加收件人'; ?></h2>
        <form method="post"><?php self::nonce('save_subscriber'); ?><input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
            <p><input name="email" placeholder="email@example.com" required value="<?php echo esc_attr($edit->email ?? ''); ?>"> <input name="name" placeholder="姓名" value="<?php echo esc_attr($edit->name ?? ''); ?>"> <select name="status"><option value="subscribed" <?php selected($edit->status ?? 'subscribed','subscribed'); ?>>已订阅</option><option value="unsubscribed" <?php selected($edit->status ?? 'subscribed','unsubscribed'); ?>>已退订</option></select></p>
            <p><?php foreach($events as $e): foreach(['zh','en'] as $list_lang): $value=self::event_language_value($e->id,$list_lang); ?><label style="margin-right:12px"><input type="checkbox" name="event_lists[]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $selected_lists, true)); ?>> <?php echo esc_html(self::event_language_label($e,$list_lang)); ?></label><?php endforeach; endforeach; ?></p>
            <?php submit_button($edit ? '更新收件人' : '保存', 'secondary', 'submit', false); ?> <?php if($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mad-em-subscribers')); ?>">取消编辑</a><?php endif; ?>
        </form>
        <h2>收件人列表</h2>
        <form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input type="hidden" name="page" value="mad-em-subscribers"><label>按活动语言筛选 <select name="subscriber_filter"><option value="0">全部收件人</option><?php foreach($events as $e): foreach(['zh','en'] as $list_lang): $value=self::event_language_value($e->id,$list_lang); ?><option value="<?php echo esc_attr($value); ?>" <?php selected($filter_value,$value); ?>><?php echo esc_html(self::event_language_label($e,$list_lang)); ?></option><?php endforeach; endforeach; ?></select></label><?php submit_button('筛选', 'secondary', 'submit', false); ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mad-em-subscribers')); ?>">重置</a></form>
        <form method="post" style="margin:0 0 12px"><?php self::nonce('export_subscribers_csv'); ?><input type="hidden" name="subscriber_filter" value="<?php echo esc_attr($filter_value); ?>"><?php submit_button('导出当前筛选 CSV', 'secondary', 'submit', false); ?> <span class="description">当前筛选共 <?php echo (int)$total; ?> 个收件人。</span></form>
        <table class="widefat striped"><thead><tr><th>邮箱</th><th>姓名</th><th>订阅活动</th><th>状态</th><th>操作</th></tr></thead><tbody><?php foreach($rows as $r): $names=self::subscriber_event_labels($r->id); ?>
        <tr><td><?php echo esc_html($r->email); ?></td><td><?php echo esc_html($r->name); ?></td><td><?php echo esc_html(implode(', ', $names)); ?></td><td><?php echo esc_html(self::status_label($r->status)); ?></td><td><a href="<?php echo esc_url(admin_url('admin.php?page=mad-em-subscribers&edit='.$r->id)); ?>">编辑</a> <form method="post" style="display:inline" data-confirm-delete="确定删除这个收件人吗？"><?php self::nonce('delete_subscriber'); ?><input type="hidden" name="id" value="<?php echo (int)$r->id; ?>"><button class="button-link-delete">删除</button></form></td></tr>
        <?php endforeach; if(empty($rows)): ?><tr><td colspan="5">没有找到收件人。</td></tr><?php endif; ?></tbody></table><?php self::wrap_end();
    }

    public static function page_send() { self::render_admin_page('render_page_send'); }
    private static function render_page_send() {
        global $wpdb;
        $templates = self::get_templates();
        $events = self::get_events(false);
        $settings = self::settings();
        $load_id = absint(wp_unslash($_GET['campaign_id'] ?? 0));
        $loaded_campaign = $load_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('campaigns'), $load_id ) ) : null;
        $loaded_vars = $loaded_campaign ? (json_decode($loaded_campaign->variables, true) ?: []) : [];
        $selected_id = absint(wp_unslash($_GET['template_id'] ?? 0));
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
        <tr class="recipient-event"><th>收件人活动列表</th><td><select name="event_id"><option value="0">全部已订阅收件人</option><?php foreach($events as $e): foreach(['zh','en'] as $list_lang): ?><option value="<?php echo esc_attr(self::event_language_value($e->id, $list_lang)); ?>"><?php echo esc_html(self::event_language_label($e, $list_lang)); ?></option><?php endforeach; endforeach; ?></select><p class="mad-em-help">后台按语言拆分收件人列表；前台仍只显示活动名称和语言选择。</p></td></tr>
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
        <p class="submit"><button type="submit" class="button" id="previewBtn" name="mad_em_action" value="preview_current_static">发送前预览</button> <button type="button" class="button" id="testBtn">发送测试邮件</button> <button type="submit" class="button button-secondary" name="mad_em_action" value="save_campaign_draft">保存为草稿</button> <button type="submit" class="button button-primary" name="mad_em_action" value="create_campaign">创建发送任务</button></p></form>
        <div class="mad-em-preview-modal" id="previewModal"><div class="mad-em-preview-box"><div class="mad-em-preview-head"><strong id="previewTitle">发送前预览</strong><div class="mad-em-preview-actions"><span id="previewStatus" class="description"></span><button type="button" class="button" id="closePreview">关闭</button></div></div><div id="testPanel" style="display:none;margin-bottom:14px;padding:12px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7"><p><label><strong>测试邮箱</strong><br><input type="email" id="testEmail" class="regular-text" placeholder="test@example.com"></label></p><div id="testVars"></div><p><button type="button" class="button button-primary" id="sendTestNow">发送测试邮件</button></p></div><iframe id="previewFrame" name="madEmPreviewFrame"></iframe></div></div>
        <p>注册表单短代码： <code>[mad_email_register]</code></p><?php self::wrap_end();
    }

    public static function page_campaigns() { self::render_admin_page('render_page_campaigns'); }
    private static function render_page_campaigns() {
        global $wpdb; self::wrap_start('发送任务');
        $events = self::get_events(false);
        $status = sanitize_text_field(wp_unslash($_GET['status'] ?? ''));
        $event_id = absint(wp_unslash($_GET['event_id'] ?? 0));
        $campaigns_table = self::table('campaigns');
        if ($status !== '' && $event_id) {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status=%s AND event_id=%d ORDER BY id DESC LIMIT 200', $campaigns_table, $status, $event_id ) );
        } elseif ($status !== '') {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status=%s ORDER BY id DESC LIMIT 200', $campaigns_table, $status ) );
        } elseif ($event_id) {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%d ORDER BY id DESC LIMIT 200', $campaigns_table, $event_id ) );
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
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $language = self::request_subscription_language();
        if (!empty($_POST['mad_em_public_unsubscribe'])) {
            if (is_email($email)) {
                $sub = $wpdb->get_row( $wpdb->prepare( 'SELECT name FROM %i WHERE email=%s', self::table('subscribers'), $email ) );
                $wpdb->update(self::table('subscribers'), ['status'=>'unsubscribed','updated_at'=>self::now()], ['email'=>$email]);
                self::send_subscription_notice($email, $sub->name ?? '', $language, 'unsubscribe');
            }
            wp_safe_redirect(add_query_arg('mad_em_unsubscribed', '1', self::public_form_redirect_url($language))); exit;
        }
        self::upsert_subscriber($email, $name, array_map('absint', wp_unslash($_POST['events'] ?? [])), 'shortcode', true, $language);
        self::send_subscription_notice($email, $name, $language, 'subscribe');
        wp_safe_redirect(add_query_arg('mad_em_registered', '1', self::public_form_redirect_url($language))); exit;
    }

    public static function shortcode_register($atts) {
        $events=self::get_events(true); ob_start();
        $show_unsub = !empty($_GET['mad_em_action']) && sanitize_text_field(wp_unslash($_GET['mad_em_action'])) === 'unsubscribe';
        $current_subscription_language = self::request_subscription_language();
        $current_url = self::current_public_url();
        $query_result = null;
        if (!empty($_POST['mad_em_public_query']) && isset($_POST['mad_em_public_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad_em_public_nonce'])), 'mad_em_public_register')) {
            global $wpdb;
            $qemail = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            if (is_email($qemail)) {
                $sub = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email=%s', self::table('subscribers'), $qemail ) );
                if ($sub) {
                    $names = self::subscriber_event_labels($sub->id);
                    $query_result = ['found'=>true,'name'=>$sub->name,'email'=>$sub->email,'status'=>$sub->status,'events'=>implode('、', $names) ?: '未选择具体活动'];
                } else { $query_result = ['found'=>false,'email'=>$qemail]; }
            }
        }
        $show_query = !empty($query_result);
        ?>
        <?php if (!empty(sanitize_text_field(wp_unslash($_GET['mad_em_registered'] ?? '')))) echo '<div class="mad-em-success">订阅已保存。后续相关活动通知会发送到你的邮箱。</div>'; ?>
        <?php if (!empty(sanitize_text_field(wp_unslash($_GET['mad_em_unsubscribed'] ?? '')))) echo '<div class="mad-em-warning">退订已提交。这个邮箱将不再接收活动通知。</div>'; ?>
        <div class="mad-em-register-wrap"><div class="mad-em-register-card">
            <div class="mad-em-tabs">
                <button type="button" class="mad-em-tab <?php echo (!$show_unsub && !$show_query) ? 'active' : ''; ?>" data-target="subscribe">订阅通知</button>
                <button type="button" class="mad-em-tab <?php echo ($show_unsub && !$show_query) ? 'active' : ''; ?>" data-target="unsubscribe">退订通知</button>
                <button type="button" class="mad-em-tab <?php echo $show_query ? 'active' : ''; ?>" data-target="query">查询订阅</button>
            </div>
            <form class="mad-em-panel <?php echo (!$show_unsub && !$show_query) ? 'active' : ''; ?>" data-panel="subscribe" method="post">
                <input type="hidden" name="mad_em_public_register" value="1"><input type="hidden" name="mad_em_redirect" value="<?php echo esc_url($current_url); ?>"><?php wp_nonce_field('mad_em_public_register', 'mad_em_public_nonce'); ?>
                <h2 class="mad-em-register-title">订阅活动通知</h2>
                <p class="mad-em-register-sub">填写邮箱并选择你想接收通知的活动。重复提交只会增加新的订阅类目，不会删除你以前已经订阅的类目。</p>
                <div class="mad-em-grid"><div class="mad-em-field"><label>姓名</label><input type="text" name="name" placeholder="请输入你的姓名" required></div><div class="mad-em-field"><label>邮箱</label><input type="email" name="email" placeholder="name@example.com" required></div></div>
                <div class="mad-em-field" style="margin-top:16px"><label>订阅语言 / Subscription Language</label><select name="subscription_language" style="width:100%;box-sizing:border-box;border:1px solid #dbe3ef;border-radius:15px;background:#fff;padding:14px 15px;font-size:15px"><option value="zh" <?php selected($current_subscription_language, 'zh'); ?>>中文</option><option value="en" <?php selected($current_subscription_language, 'en'); ?>>English</option></select></div>
                <div class="mad-em-events"><div class="mad-em-events-title">选择想接收通知的活动</div><div class="mad-em-event-list">
                <?php foreach($events as $e): ?><label class="mad-em-event-item"><input type="checkbox" name="events[]" value="<?php echo (int)$e->id; ?>"><span><span class="mad-em-event-name"><?php echo esc_html($e->name); ?></span></span></label><?php endforeach; ?>
                </div></div>
                <div class="mad-em-actions"><button class="mad-em-submit" type="submit">保存订阅</button><span class="mad-em-note">你可以随时回到这个页面退订。</span></div>
            </form>
            <form class="mad-em-panel <?php echo ($show_unsub && !$show_query) ? 'active' : ''; ?>" data-panel="unsubscribe" method="post">
                <input type="hidden" name="mad_em_public_unsubscribe" value="1"><input type="hidden" name="mad_em_redirect" value="<?php echo esc_url($current_url); ?>"><input type="hidden" name="subscription_language" value="<?php echo esc_attr($current_subscription_language); ?>"><?php wp_nonce_field('mad_em_public_register', 'mad_em_public_nonce'); ?>
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
        <?php $html = ob_get_clean();
        if (self::is_english_language(self::current_public_language())) $html = self::translate_text($html, self::current_public_language());
        return $html;
    }

}

register_activation_hook(__FILE__, ['MADEVMA_Event_Mailer', 'activate']);
register_deactivation_hook(__FILE__, ['MADEVMA_Event_Mailer', 'deactivate']);
MADEVMA_Event_Mailer::init();
