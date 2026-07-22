<?php
/**
 * Plugin Name: MAD Event Mailer
 * Description: An HTML email delivery plugin for event notifications. Supports SMTP, template variables, CSV recipients, event subscriptions, shortcode registration, batch sending and scheduled sending.
 * Version: 2.3.1
 * Requires at least: 6.2
 * Author: MAD Producer Studio
 * Author URI: https://github.com/MAD-Producer
 * License: GPL v2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mad-event-mailer
 */

if (!defined('ABSPATH')) exit;

class MADEVMA_Event_Mailer {
    const VERSION = '2.3.1';
    const OPT = 'madevma_settings';
    const CRON = 'madevma_process_campaigns';
    const CAP = 'madevma_manage_mailer';
    const ROLE = 'madevma_mail_manager';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'maybe_handle_post']);
        add_action('admin_init', [__CLASS__, 'maybe_handle_get']);
        add_action('admin_post_madevma_export_template_csv', [__CLASS__, 'export_template_csv']);
        add_action('admin_post_madevma_preview_template', [__CLASS__, 'preview_template_page']);
        add_action('wp_ajax_madevma_preview_send', [__CLASS__, 'ajax_preview_send']);
        add_action('wp_ajax_madevma_test_send', [__CLASS__, 'ajax_test_send']);
        add_action('phpmailer_init', [__CLASS__, 'smtp_config'], 1000);
        add_filter('wp_mail_from', [__CLASS__, 'mail_from']);
        add_filter('wp_mail_from_name', [__CLASS__, 'mail_from_name']);
        add_shortcode('madevma_email_register', [__CLASS__, 'shortcode_register']);
        add_action('init', [__CLASS__, 'handle_public_register']);
        add_action(self::CRON, [__CLASS__, 'process_campaigns']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action('init', [__CLASS__, 'maybe_upgrade'], 1);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_public_assets']);
    }

    public static function maybe_upgrade() {
        if (get_option('madevma_version') !== self::VERSION) {
            self::activate();
            update_option('madevma_version', self::VERSION);
        }
        self::sync_builtin_template_layouts();
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'madevma_';

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

        self::migrate_legacy_230_data();
        self::ensure_223_schema();
        self::seed_defaults();
        // The unsubscribe button is appended dynamically from the campaign settings.
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 60, 'madevma_five_minutes', self::CRON);
        update_option('madevma_version', self::VERSION);
    }

    public static function deactivate() { wp_clear_scheduled_hook(self::CRON); }

    private static function migrate_legacy_230_data() {
        if (get_option('madevma_legacy_230_migrated')) return;
        global $wpdb;

        // Version 2.3.0 replaces the former short prefix. These names are read only for one-time migration.
        $legacy_namespace = 'mad' . '_em';
        $legacy_settings = get_option($legacy_namespace . '_settings', false);
        if (false !== $legacy_settings && false === get_option(self::OPT, false)) {
            update_option(self::OPT, $legacy_settings);
        }

        foreach (['templates', 'events', 'subscribers', 'subscriber_events', 'campaigns', 'campaign_logs', 'campaign_recipient_vars'] as $name) {
            $legacy_table = $wpdb->prefix . $legacy_namespace . '_' . $name;
            $current_table = self::table($name);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacy_table))) === $legacy_table) {
                $wpdb->query($wpdb->prepare('INSERT IGNORE INTO %i SELECT * FROM %i', $current_table, $legacy_table));
            }
        }

        $legacy_role = $legacy_namespace . '_mail_manager';
        foreach (get_users(['role' => $legacy_role, 'fields' => 'ID']) as $user_id) {
            $user = new WP_User($user_id);
            $user->add_role(self::ROLE);
            $user->remove_role($legacy_role);
        }

        $legacy_shortcode = '[' . 'mad' . '_email_register';
        $current_shortcode = '[madevma_email_register';
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET post_content=REPLACE(post_content,%s,%s) WHERE post_content LIKE %s',
            $wpdb->posts,
            $legacy_shortcode,
            $current_shortcode,
            '%' . $wpdb->esc_like($legacy_shortcode) . '%'
        ));

        wp_clear_scheduled_hook($legacy_namespace . '_process_campaigns');
        update_option('madevma_legacy_230_migrated', 1);
    }

    private static function ensure_roles() {
        add_role(self::ROLE, __( 'Mail Manager', 'mad-event-mailer' ), [
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
        $schedules['madevma_five_minutes'] = ['interval' => 300, 'display' => __( 'Every 5 minutes', 'mad-event-mailer' )];
        return $schedules;
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'madevma_' . $name; }
    private static function now() { return current_time('mysql'); }
    private static function settings() { return wp_parse_args(get_option(self::OPT, []), [
        'host'=>'', 'port'=>'465', 'secure'=>'ssl', 'username'=>'', 'password'=>'', 'from_email'=>'', 'from_name'=>'', 'sender_name'=>'', 'reply_to'=>'', 'batch_size'=>30, 'logo_url'=>'', 'icon_url'=>'', 'register_page_url'=>'', 'register_page_url_zh'=>'', 'register_page_url_en'=>'', 'default_unsubscribe_button'=>1, 'default_unsubscribe_lang'=>'en'
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

    private static function render_admin_page($renderer) {
        if (!self::can_manage()) wp_die(esc_html(__( 'Permission denied.', 'mad-event-mailer' )));
        ob_start();
        call_user_func([__CLASS__, $renderer]);
        $html = ob_get_clean();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Page renderers escape dynamic values at their source; this preserves WordPress admin forms and editor markup.
        echo $html;
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
        foreach ([['Default Chinese Template', 'default-template-zh.html'], ['Default English Template', 'default-template-en.html']] as $item) {
            $file = plugin_dir_path(__FILE__) . $item[1];
            if (file_exists($file)) {
                $html = self::read_local_file($file);
                $wpdb->insert($t, [
                    'name'=>$item[0], 'subject'=>'{{title1}}', 'summary'=>__( 'Built-in sample template', 'mad-event-mailer' ), 'html'=>$html,
                    'variables'=>wp_json_encode(self::extract_vars($html . ' {{title1}}')), 'created_at'=>self::now(), 'updated_at'=>self::now()
                ]);
            }
        }
    }

    private static function sync_builtin_template_layouts() {
        global $wpdb;
        $table = self::table('templates');
        $subject = '{{title1}}';
        $templates = [
            ['Default Chinese Template', '默认中文模板', 'default-template-zh.html'],
            ['Default English Template', '默认英文模板', 'default-template-en.html'],
        ];
        foreach ($templates as $template) {
            $html = self::read_local_file(plugin_dir_path(__FILE__) . $template[2]);
            if ($html === '') continue;
            $template_id = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE name IN (%s,%s) ORDER BY id ASC LIMIT 1',
                $table,
                $template[0],
                $template[1]
            ));
            if (!$template_id) continue;
            $hash_option = 'madevma_' . sanitize_key(pathinfo($template[2], PATHINFO_FILENAME)) . '_layout_hash';
            $layout_hash = hash('sha256', $html);
            $stored_name = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM %i WHERE id=%d', $table, $template_id));
            if (get_option($hash_option) === $layout_hash && $stored_name === $template[0]) continue;
            $wpdb->update($table, [
                'name' => $template[0],
                'summary' => __( 'Built-in sample template', 'mad-event-mailer' ),
                'html' => $html,
                'variables' => wp_json_encode(self::extract_vars($html . ' ' . $subject)),
                'updated_at' => self::now(),
            ], ['id' => $template_id]);
            update_option($hash_option, $layout_hash);
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
        add_menu_page(__( 'MAD Event Mailer', 'mad-event-mailer' ), __( 'MAD Mailer', 'mad-event-mailer' ), self::CAP, 'madevma-mailer', [__CLASS__, 'page_send'], 'dashicons-email-alt2', 58);
        add_submenu_page('madevma-mailer', __( 'Send / Schedule', 'mad-event-mailer' ), __( 'Send / Schedule', 'mad-event-mailer' ), self::CAP, 'madevma-mailer', [__CLASS__, 'page_send']);
        add_submenu_page('madevma-mailer', __( 'Email Templates', 'mad-event-mailer' ), __( 'Email Templates', 'mad-event-mailer' ), self::CAP, 'madevma-mailer-templates', [__CLASS__, 'page_templates']);
        add_submenu_page('madevma-mailer', __( 'Events', 'mad-event-mailer' ), __( 'Events', 'mad-event-mailer' ), self::CAP, 'madevma-mailer-events', [__CLASS__, 'page_events']);
        add_submenu_page('madevma-mailer', __( 'Subscribers', 'mad-event-mailer' ), __( 'Subscribers', 'mad-event-mailer' ), self::CAP, 'madevma-mailer-subscribers', [__CLASS__, 'page_subscribers']);
        add_submenu_page('madevma-mailer', __( 'Campaigns', 'mad-event-mailer' ), __( 'Campaigns', 'mad-event-mailer' ), self::CAP, 'madevma-mailer-campaigns', [__CLASS__, 'page_campaigns']);
        add_submenu_page('madevma-mailer', __( 'SMTP Settings', 'mad-event-mailer' ), __( 'SMTP Settings', 'mad-event-mailer' ), self::CAP, 'madevma-mailer-settings', [__CLASS__, 'page_settings']);
    }

    public static function enqueue_admin_assets() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'madevma-mailer') !== 0 || !self::can_manage()) return;
        wp_enqueue_style('madevma-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('madevma-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', [], self::VERSION, true);
        wp_localize_script('madevma-admin', 'madevmaMailer', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'previewNonce' => wp_create_nonce('madevma_preview_send'),
            'templateVars' => self::admin_selected_template_vars(),
            'confirmDelete' => __( 'Are you sure you want to delete this?', 'mad-event-mailer' ),
            'varPlaceholder' => __( 'Enter a global default value here. If the CSV has a matching column, each recipient’s own value takes priority.', 'mad-event-mailer' ),
            'previewTitle' => __( 'Preview', 'mad-event-mailer' ),
            'previewStatus' => __( 'Static preview: variables remain as {{variable_name}} and no email will be sent.', 'mad-event-mailer' ),
            'testTitle' => __( 'Send Test Email', 'mad-event-mailer' ),
            'testHelp' => __( 'Enter a test email address and sample variable values. Email is sent only when you click “Send Test Email” below.', 'mad-event-mailer' ),
            'testPlaceholder' => __( 'Test sample value; leave blank to keep {{variable_name}}', 'mad-event-mailer' ),
            'emailRequired' => __( 'Please enter a test email address.', 'mad-event-mailer' ),
            'sending' => __( 'Sending test email...', 'mad-event-mailer' ),
            'sent' => __( 'Test email sent.', 'mad-event-mailer' ),
            'failed' => __( 'Test email failed.', 'mad-event-mailer' ),
            'failedPermission' => __( 'Test email failed. Please check SMTP settings or admin permissions.', 'mad-event-mailer' ),
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
        if (!$post || !has_shortcode((string) $post->post_content, 'madevma_email_register')) return;
        wp_enqueue_style('madevma-public', plugin_dir_url(__FILE__) . 'assets/public.css', [], self::VERSION);
        wp_enqueue_script('madevma-public', plugin_dir_url(__FILE__) . 'assets/public.js', [], self::VERSION, true);
    }

    private static function notice($msg, $type='success') { echo '<div class="notice notice-'.esc_attr($type).' is-dismissible"><p>'.esc_html($msg).'</p></div>'; }
    private static function status_label($status) {
        $map = [
            'subscribed' => __( 'Subscribed', 'mad-event-mailer' ),
            'unsubscribed' => __( 'Unsubscribed', 'mad-event-mailer' ),
            'draft' => __( 'Draft', 'mad-event-mailer' ),
            'scheduled' => __( 'Scheduled', 'mad-event-mailer' ),
            'queued' => __( 'Queued', 'mad-event-mailer' ),
            'sending' => __( 'Sending', 'mad-event-mailer' ),
            'finished' => __( 'Finished', 'mad-event-mailer' ),
            'pending' => __( 'Pending', 'mad-event-mailer' ),
            'sent' => __( 'Sent', 'mad-event-mailer' ),
            'failed' => __( 'Failed', 'mad-event-mailer' ),
        ];
        return $map[$status] ?? $status;
    }
    private static function wrap_start($title) { echo '<div class="wrap"><h1>'.esc_html($title).'</h1>'; }
    private static function wrap_end() { echo '</div>'; }
    private static function nonce($action) { wp_nonce_field($action, 'madevma_nonce'); echo '<input type="hidden" name="madevma_action" value="'.esc_attr($action).'">'; }
    private static function verify($action) {
        return self::can_manage()
            && isset($_POST['madevma_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['madevma_nonce'])), $action);
    }

    private static function auto_vars() { return ['name','name1','email','unsubscribe_url','logo_url','icon_url']; }
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
        if (!self::can_manage()) wp_die(esc_html(__( 'Permission denied.', 'mad-event-mailer' )));
        $template_id = absint(wp_unslash($_GET['template_id'] ?? $_GET['madevma_export_template_csv'] ?? 0));
        if (empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'madevma_export_template_csv_'.$template_id)) wp_die(esc_html(__( 'The export link has expired. Please return to the admin page and export again.', 'mad-event-mailer' )));
        global $wpdb;
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_die(esc_html(__( 'Template not found.', 'mad-event-mailer' )));
        $vars = self::editable_vars(self::extract_vars($template->html . ' ' . $template->subject));
        $headers = array_merge(['email','name','events'], $vars);
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=madevma-mailer-recipients-template-'.$template_id.'.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 BOM for CSV download.
        echo "\xEF\xBB\xBF";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download output, not HTML.
        echo self::csv_line($headers);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download output, not HTML.
        echo self::csv_line(array_merge(['example@example.com', __( 'John Doe', 'mad-event-mailer' ), __( 'Event name or slug', 'mad-event-mailer' )], array_fill(0, count($vars), '')));
        exit;
    }


    private static function export_url($template_id) {
        $template_id = (int)$template_id;
        if (!$template_id) return '#';
        return wp_nonce_url(admin_url('admin-post.php?action=madevma_export_template_csv&template_id='.$template_id), 'madevma_export_template_csv_'.$template_id);
    }

    private static function preview_url($template_id) {
        $template_id = (int)$template_id;
        if (!$template_id) return '#';
        return wp_nonce_url(admin_url('admin-post.php?action=madevma_preview_template&template_id='.$template_id), 'madevma_preview_template_'.$template_id);
    }

    public static function preview_template_page() {
        if (!self::can_manage()) wp_die(esc_html(__( 'Permission denied.', 'mad-event-mailer' )));
        $template_id = absint(wp_unslash($_GET['template_id'] ?? 0));
        if (!$template_id || !isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'madevma_preview_template_'.$template_id)) wp_die(esc_html(__( 'The link has expired. Please reopen the preview from the admin page.', 'mad-event-mailer' )));
        global $wpdb;
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_die(esc_html(__( 'Template not found.', 'mad-event-mailer' )));
        $vars = self::extract_vars($template->html . ' ' . $template->subject);
        $sample = [];
        foreach ($vars as $v) {
            if (in_array($v, ['name','name1'], true)) $sample[$v] = __( 'John Doe', 'mad-event-mailer' );
            elseif (in_array($v, ['title','title1'], true)) $sample[$v] = __( 'Sample Email Subject', 'mad-event-mailer' );
            elseif ($v === 'email') $sample[$v] = 'example@example.com';
            elseif ($v === 'unsubscribe_url') $sample[$v] = self::get_unsubscribe_url();
            else $sample[$v] = sprintf(
                /* translators: %s: template variable name. */
                __( 'Sample %s', 'mad-event-mailer' ),
                $v
            );
        }
        $sample['unsubscribe_url'] = self::get_unsubscribe_url();
        header('Content-Type: text/html; charset=UTF-8');
        $set = self::settings();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Email HTML is filtered by safe_email_html() immediately before output.
        echo self::safe_email_html( self::render_template( self::ensure_unsubscribe_notice( (string) $template->html, sanitize_text_field( $set['default_unsubscribe_lang'] ?? 'en' ), ! empty( $set['default_unsubscribe_button'] ) ), $sample ) );
        exit;
    }

    public static function ajax_preview_send() {
        if (!self::can_manage()) wp_send_json_error(['message'=>__( 'Permission denied.', 'mad-event-mailer' )], 403);
        check_ajax_referer('madevma_preview_send', 'nonce');
        global $wpdb;
        $template_id = absint(wp_unslash($_POST['template_id'] ?? 0));
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_send_json_error(['message'=>__( 'Template not found.', 'mad-event-mailer' )]);
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $vars = [];
        $posted_var = isset($_POST['var']) && is_array($_POST['var']) ? wp_unslash($_POST['var']) : [];
        if (!empty($posted_var)) {
            foreach ($posted_var as $k=>$v) { $key=sanitize_key($k); if ($key !== '') $vars[$key] = self::sanitize_var_value($key, $v); }
        }
        $vars['name'] = __( 'John Doe', 'mad-event-mailer' );
        $vars['name1'] = __( 'John Doe', 'mad-event-mailer' );
        $vars['email'] = 'example@example.com';
        $vars['title'] = $subject ?: __( 'Sample Email Subject', 'mad-event-mailer' );
        $vars['title1'] = $subject ?: __( 'Sample Email Subject', 'mad-event-mailer' );
        $vars['unsubscribe_url'] = self::get_unsubscribe_url();
        $include_unsub = !empty($_POST['include_unsubscribe']);
        $unsub_lang_value = sanitize_text_field(wp_unslash($_POST['unsubscribe_lang'] ?? 'en'));
        $unsub_lang = in_array($unsub_lang_value, ['zh','en'], true) ? $unsub_lang_value : 'en';
        foreach (self::editable_vars(self::extract_vars($template->html . ' ' . implode(' ', array_map('strval', $vars)))) as $v) {
            if (!isset($vars[$v]) || $vars[$v] === '') $vars[$v] = sprintf(
                /* translators: %s: template variable name. */
                __( 'Sample %s', 'mad-event-mailer' ),
                $v
            );
        }
        $html = self::render_template(self::ensure_unsubscribe_notice($template->html, $unsub_lang, $include_unsub), $vars);
        wp_send_json_success(['html'=>self::safe_email_html($html)]);
    }


    public static function ajax_test_send() {
        if (!self::can_manage()) wp_send_json_error(['message'=>__( 'Permission denied.', 'mad-event-mailer' )], 403);
        check_ajax_referer('madevma_preview_send', 'nonce');
        global $wpdb;
        $to = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        if (!is_email($to)) wp_send_json_error(['message'=>__( 'Please enter a valid test email address.', 'mad-event-mailer' )]);
        $template_id = absint(wp_unslash($_POST['template_id'] ?? 0));
        $template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), $template_id ) );
        if (!$template) wp_send_json_error(['message'=>__( 'Template not found.', 'mad-event-mailer' )]);
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? __( 'Test Email', 'mad-event-mailer' )));
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
        $vars['name'] = $vars['name'] ?? __( 'Test Recipient', 'mad-event-mailer' );
        $vars['name1'] = $vars['name1'] ?? $vars['name'];
        $vars['title'] = $subject ?: __( 'Test Email', 'mad-event-mailer' );
        $vars['title1'] = $subject ?: __( 'Test Email', 'mad-event-mailer' );
        $vars['unsubscribe_url'] = self::get_unsubscribe_url();
        $include_unsub = !empty($_POST['include_unsubscribe']);
        $unsub_lang_value = sanitize_text_field(wp_unslash($_POST['unsubscribe_lang'] ?? 'en'));
        $unsub_lang = in_array($unsub_lang_value, ['zh','en'], true) ? $unsub_lang_value : 'en';
        foreach (self::editable_vars(self::extract_vars($template->html . ' ' . $template->subject . ' ' . implode(' ', array_map('strval',$vars)))) as $v) {
            if (!isset($vars[$v]) || $vars[$v] === '') $vars[$v] = sprintf(
                /* translators: %s: template variable name. */
                __( 'Test %s', 'mad-event-mailer' ),
                $v
            );
        }
        $body = self::render_template(self::ensure_unsubscribe_notice($template->html, $unsub_lang, $include_unsub), $vars);
        $subj = self::render_template($subject ?: $template->subject, $vars);
        $ok = wp_mail($to, $subj, $body, self::mail_headers());
        if (!$ok) wp_send_json_error(['message'=>__( 'Test email failed. Please check SMTP settings or server logs.', 'mad-event-mailer' )]);
        wp_send_json_success(['message'=>sprintf(
            /* translators: %s: test recipient email address. */
            __( 'Test email sent to %s.', 'mad-event-mailer' ),
            $to
        )]);
    }

    public static function maybe_handle_post() {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
        if (empty($_POST['madevma_action'])) return;
        $action = sanitize_text_field(wp_unslash($_POST['madevma_action']));
        $nonce_action = in_array($action, ['save_campaign_draft','export_current_csv','preview_current_static'], true) ? 'create_campaign' : $action;
        if ($action === 'export_subscribers_csv') $nonce_action = 'export_subscribers_csv';
        if (!self::can_manage()) wp_die(esc_html(__( 'Permission denied.', 'mad-event-mailer' )), '', ['response' => 403]);
        check_admin_referer($nonce_action, 'madevma_nonce');
        global $wpdb;

        if ($action === 'save_settings') {
            $sender_name = sanitize_text_field(wp_unslash($_POST['sender_name'] ?? ($_POST['from_name'] ?? '')));
            update_option(self::OPT, [
                'host'=>sanitize_text_field(wp_unslash($_POST['host'] ?? '')), 'port'=>sanitize_text_field(wp_unslash($_POST['port'] ?? '465')),
                'secure'=>sanitize_text_field(wp_unslash($_POST['secure'] ?? 'ssl')), 'username'=>sanitize_text_field(wp_unslash($_POST['username'] ?? '')),
                'password'=>sanitize_text_field(wp_unslash($_POST['password'] ?? '')), 'from_email'=>sanitize_email(wp_unslash($_POST['from_email'] ?? '')),
                'from_name'=>$sender_name, 'sender_name'=>$sender_name, 'reply_to'=>sanitize_email(wp_unslash($_POST['reply_to'] ?? '')),
                'batch_size'=>max(1, (int) sanitize_text_field(wp_unslash($_POST['batch_size'] ?? 30))),
                'logo_url'=>esc_url_raw(wp_unslash($_POST['logo_url'] ?? '')),
                'icon_url'=>esc_url_raw(wp_unslash($_POST['icon_url'] ?? '')),
                'register_page_url'=>esc_url_raw(wp_unslash($_POST['register_page_url'] ?? '')),
                'register_page_url_zh'=>esc_url_raw(wp_unslash($_POST['register_page_url_zh'] ?? '')),
                'register_page_url_en'=>esc_url_raw(wp_unslash($_POST['register_page_url_en'] ?? '')),
                'default_unsubscribe_button'=>!empty($_POST['default_unsubscribe_button']) ? 1 : 0,
                'default_unsubscribe_lang'=>in_array(sanitize_text_field(wp_unslash($_POST['default_unsubscribe_lang'] ?? 'en')), ['zh','en'], true) ? sanitize_text_field(wp_unslash($_POST['default_unsubscribe_lang'])) : 'en'
            ]);
            add_action('admin_notices', fn()=>self::notice($sender_name !== ''
                ? sprintf(
                    /* translators: %s: sender name. */
                    __( 'Settings saved. Sender name updated to: %s', 'mad-event-mailer' ),
                    $sender_name
                )
                : __( 'Settings saved. When the sender name is empty, the WordPress site name will be used.', 'mad-event-mailer' )
            ));
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
            add_action('admin_notices', fn()=>self::notice(__( 'Template saved.', 'mad-event-mailer' )));
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
                    'name'=>sanitize_text_field(wp_unslash($_POST['quick_name'] ?? __( 'New Template', 'mad-event-mailer' ))),
                    'subject'=>$subject,
                    'summary'=>__( 'Created from a general template with only the body content edited.', 'mad-event-mailer' ),
                    'html'=>$html,
                    'variables'=>wp_json_encode(self::extract_vars($html . ' ' . $subject)),
                    'created_at'=>self::now(),
                    'updated_at'=>self::now()
                ]);
                add_action('admin_notices', fn()=>self::notice(__( 'New email template created from the general template.', 'mad-event-mailer' )));
            }
        }

        if ($action === 'delete_template') {
            $id = absint(wp_unslash($_POST['id']));
            if (self::is_builtin_template($id)) {
                add_action('admin_notices', fn()=>self::notice(__( 'Built-in general templates cannot be deleted.', 'mad-event-mailer' ), 'warning'));
            } else {
                $wpdb->delete(self::table('templates'), ['id'=>$id]);
                add_action('admin_notices', fn()=>self::notice(__( 'Template deleted.', 'mad-event-mailer' )));
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
            add_action('admin_notices', fn()=>self::notice(__( 'Event saved.', 'mad-event-mailer' )));
        }

        if ($action === 'delete_event') {
            $id = absint(wp_unslash($_POST['id']));
            $wpdb->delete(self::table('subscriber_events'), ['event_id'=>$id]);
            $wpdb->delete(self::table('events'), ['id'=>$id]);
            add_action('admin_notices', fn()=>self::notice(__( 'Event deleted.', 'mad-event-mailer' )));
        }

        if ($action === 'save_event_order') {
            $order = isset($_POST['event_order']) && is_array($_POST['event_order']) ? array_map('absint', wp_unslash($_POST['event_order'])) : [];
            foreach (array_values(array_filter($order)) as $index => $event_id) {
                $wpdb->update(self::table('events'), ['sort_order'=>($index + 1) * 10], ['id'=>$event_id]);
            }
            add_action('admin_notices', fn()=>self::notice(__( 'Event order saved.', 'mad-event-mailer' )));
        }

        if ($action === 'save_subscriber') {
            self::save_subscriber_from_post();
            add_action('admin_notices', fn()=>self::notice(__( 'Subscriber saved.', 'mad-event-mailer' )));
        }

        if ($action === 'export_subscribers_csv') {
            self::export_subscribers_csv_from_post();
        }

        if ($action === 'import_csv') {
            $count = self::import_csv(self::valid_uploaded_file('csv_file', ['csv']));
            add_action('admin_notices', fn()=>self::notice(sprintf(
                /* translators: %d: number of imported subscribers. */
                _n( 'CSV import complete: %d subscriber.', 'CSV import complete: %d subscribers.', $count, 'mad-event-mailer' ),
                $count
            )));
        }

        if ($action === 'delete_subscriber') {
            $id = absint(wp_unslash($_POST['id']));
            $wpdb->delete(self::table('subscriber_events'), ['subscriber_id'=>$id]);
            $wpdb->delete(self::table('subscribers'), ['id'=>$id]);
            add_action('admin_notices', fn()=>self::notice(__( 'Subscriber deleted.', 'mad-event-mailer' )));
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
            add_action('admin_notices', fn()=>self::notice($is_draft
                ? __( 'Campaign draft saved.', 'mad-event-mailer' )
                : __( 'Campaign created. The system will process it in batches through WP-Cron.', 'mad-event-mailer' )
            ));
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
        if (!self::can_manage()) wp_die(esc_html(__( 'Permission denied.', 'mad-event-mailer' )));
        $template = self::current_template_from_post();
        if (!$template) wp_die(esc_html(__( 'Please select a template first.', 'mad-event-mailer' )));
        $posted_vars = self::posted_vars_for_preview();
        $scan = $template->html . ' ' . $template->subject . ' ' . implode(' ', array_map('strval', $posted_vars));
        $vars = array_values(array_diff(self::editable_vars(self::extract_vars($scan)), self::body_vars()));
        $headers = array_merge(['email','name','events'], $vars);
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=madevma-mailer-recipients-current-template.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 BOM for CSV download.
        echo "\xEF\xBB\xBF";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download output, not HTML.
        echo self::csv_line($headers);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download output, not HTML.
        echo self::csv_line(array_merge(['example@example.com', __( 'John Doe', 'mad-event-mailer' ), __( 'Event name or slug', 'mad-event-mailer' )], array_fill(0, count($vars), '')));
        exit;
    }

    private static function preview_current_form_static() {
        if (!self::can_manage()) wp_die(esc_html(__( 'Permission denied.', 'mad-event-mailer' )));
        $template = self::current_template_from_post();
        if (!$template) wp_die(esc_html(__( 'Please select a template first.', 'mad-event-mailer' )));
        $html = (string)$template->html;
        // The static preview preserves placeholders and does not load recipient data or send email.
        $include_unsub = !empty($_POST['include_unsubscribe']);
        $unsub_lang_value = sanitize_text_field(wp_unslash($_POST['unsubscribe_lang'] ?? 'en'));
        $unsub_lang = in_array($unsub_lang_value, ['zh','en'], true) ? $unsub_lang_value : 'en';
        $html = self::render_template(self::ensure_unsubscribe_notice($html, $unsub_lang, $include_unsub), []);
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
        $settings = self::settings();
        foreach (['logo_url', 'icon_url'] as $branding_var) {
            $url = esc_url($settings[$branding_var] ?? '');
            if ($url === '') {
                $html = preg_replace('/<img\b[^>]*\bsrc=["\']{{\s*' . preg_quote($branding_var, '/') . '\s*}}["\'][^>]*\/?\s*>/i', '', $html);
            }
            $vars[$branding_var] = $url;
        }
        foreach ($vars as $k=>$v) {
            $html = preg_replace_callback('/{{\s*'.preg_quote($k, '/').'\s*}}/', static function() use ($v) { return (string) $v; }, $html);
        }
        return $html;
    }

    private static function normalize_subscription_language($lang) {
        $lang = strtolower((string)$lang);
        return in_array($lang, ['zh','en'], true) ? $lang : 'en';
    }

    private static function request_subscription_language() {
        if (isset($_POST['subscription_language'])) return self::normalize_subscription_language(sanitize_text_field(wp_unslash($_POST['subscription_language'])));
        if (isset($_GET['madevma_lang'])) return self::normalize_subscription_language(sanitize_text_field(wp_unslash($_GET['madevma_lang'])));
        return 'en';
    }

    private static function language_label($lang) {
        return self::normalize_subscription_language($lang) === 'en'
            ? __( 'English', 'mad-event-mailer' )
            : __( 'Chinese', 'mad-event-mailer' );
    }

    private static function event_language_value($event_id, $lang) {
        return absint($event_id) . '|' . self::normalize_subscription_language($lang);
    }

    private static function parse_event_language_value($value) {
        $parts = explode('|', (string)$value);
        return [absint($parts[0] ?? 0), self::normalize_subscription_language($parts[1] ?? 'en')];
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
        $lang = self::normalize_subscription_language($lang ?: 'en');
        $key = $lang === 'en' ? 'register_page_url_en' : 'register_page_url_zh';
        if (!empty($s[$key])) return $s[$key];
        return !empty($s['register_page_url']) ? $s['register_page_url'] : home_url('/');
    }

    private static function get_unsubscribe_url($lang = '') {
        $lang = self::normalize_subscription_language($lang ?: 'en');
        return add_query_arg(['madevma_action'=>'unsubscribe', 'madevma_lang'=>$lang], self::get_register_page_url($lang));
    }

    private static function current_public_url() {
        $object_id = get_queried_object_id();
        $permalink = $object_id ? get_permalink($object_id) : '';
        return $permalink ? $permalink : home_url('/');
    }

    private static function public_form_redirect_url($language) {
        $fallback = self::get_register_page_url($language);
        $posted = esc_url_raw(wp_unslash($_POST['madevma_redirect'] ?? ''));
        return $posted ? wp_validate_redirect($posted, $fallback) : $fallback;
    }

    private static function is_builtin_template($id) {
        $id = (int)$id;
        if ($id <= 0) return false;
        global $wpdb;
        $name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id=%d', self::table('templates'), $id ) );
        return in_array($id, [1,2], true) || in_array($name, ['默认中文模板','默认英文模板','Default Chinese Template','Default English Template'], true);
    }

    private static function strip_auto_unsubscribe_notice($html) {
        $html = (string)$html;
        $html = preg_replace('/<div[^>]*>[^<]*(?:如果你不想继续接收活动通知|If you no longer want to receive event notifications)[\s\S]*?<a[^>]*class="[^"]*madevma-mailer-unsubscribe-button[^"]*"[\s\S]*?<\/a>[\s\S]*?<\/div>/i', '', $html);
        $html = preg_replace('/<p[^>]*>[^<]*(?:如果你不想继续接收活动通知|If you no longer want to receive event notifications)[\s\S]*?{{\s*unsubscribe_url\s*}}[\s\S]*?<\/p>/i', '', $html);
        return $html;
    }

    private static function ensure_unsubscribe_notice($html, $lang='en', $enabled=true) {
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
        $button = '<div style="max-width:720px;margin:0 auto;padding:22px 32px 34px;font-size:12px;line-height:1.7;color:#9ca3af;text-align:center;background:#ffffff;border-top:1px solid #e5e7eb;">'.esc_html($text).'<br><a class="madevma-mailer-unsubscribe-button" href="{{unsubscribe_url}}" style="display:inline-block;margin-top:10px;padding:9px 16px;border:1px solid #d1d5db;border-radius:999px;color:#6b7280;text-decoration:none;background:#fff;">'.esc_html($label).'</a></div>';
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

    private static function upsert_subscriber($email, $name, $event_ids, $source='manual', $merge_events=false, $language='en') {
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
            $language = self::normalize_subscription_language($list['language'] ?? 'en');
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
            $html = $wpdb->get_var( $wpdb->prepare( 'SELECT html FROM %i WHERE name IN (%s,%s) ORDER BY id ASC LIMIT 1', $table, '默认中文模板', 'Default Chinese Template' ) );
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
        header('Content-Disposition: attachment; filename=madevma-mailer-subscribers.csv');
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
        $unsub_lang_value = sanitize_text_field(wp_unslash($_POST['unsubscribe_lang'] ?? 'en'));
        $vars['__unsubscribe_lang'] = in_array($unsub_lang_value, ['zh','en'], true) ? $unsub_lang_value : 'en';
        $vars['__recipient_language'] = $recipient_language;
        // Variables can appear in body content, so scan it for per-recipient CSV fields as well.
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
                $unsub_lang = in_array(($vars['__unsubscribe_lang'] ?? 'en'), ['zh','en'], true) ? $vars['__unsubscribe_lang'] : 'en';
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
        self::wrap_start(__( 'SMTP Settings', 'mad-event-mailer' )); ?>
        <form method="post"><?php self::nonce('save_settings'); ?>
        <table class="form-table"><tr><th><?php esc_html_e( 'SMTP Host', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="host" value="<?php echo esc_attr($s['host']); ?>" placeholder="smtp.example.com"></td></tr>
        <tr><th><?php esc_html_e( 'Encryption', 'mad-event-mailer' ); ?></th><td><select name="secure"><option value="ssl" <?php selected($s['secure'],'ssl'); ?>>SSL</option><option value="tls" <?php selected($s['secure'],'tls'); ?>>TLS</option></select></td></tr>
        <tr><th><?php esc_html_e( 'Port', 'mad-event-mailer' ); ?></th><td><input name="port" value="<?php echo esc_attr($s['port']); ?>"></td></tr>
        <tr><th><?php esc_html_e( 'Email Account', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="username" value="<?php echo esc_attr($s['username']); ?>"></td></tr>
        <tr><th><?php esc_html_e( 'Email Password', 'mad-event-mailer' ); ?></th><td><input class="regular-text" type="password" name="password" value="<?php echo esc_attr($s['password']); ?>"></td></tr>
        <tr><th><?php esc_html_e( 'From Email', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="from_email" value="<?php echo esc_attr($s['from_email']); ?>"></td></tr>
        <tr><th><?php esc_html_e( 'Sender Name', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="sender_name" value="<?php echo esc_attr($sender_value); ?>" placeholder="MAD Producer Studio"><p class="description"><?php esc_html_e( 'The sender name displayed in emails. When left empty, outgoing emails use the WordPress site name.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Reply-To Email', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="reply_to" value="<?php echo esc_attr($s['reply_to']); ?>"></td></tr>
        <tr><th><?php esc_html_e( 'Batch Size', 'mad-event-mailer' ); ?></th><td><input type="number" name="batch_size" value="<?php echo esc_attr($s['batch_size']); ?>"> <?php esc_html_e( 'emails per scheduled run', 'mad-event-mailer' ); ?></td></tr>
        <tr><th><?php esc_html_e( 'Email Logo URL', 'mad-event-mailer' ); ?></th><td><input class="large-text" type="url" name="logo_url" value="<?php echo esc_attr($s['logo_url']); ?>" placeholder="https://example.com/logo.png"><p class="description"><?php esc_html_e( 'Enter an image URL from the Media Library or another permitted source. Templates use the {{logo_url}} variable; leave it empty to hide the logo.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Email Footer Icon URL', 'mad-event-mailer' ); ?></th><td><input class="large-text" type="url" name="icon_url" value="<?php echo esc_attr($s['icon_url']); ?>" placeholder="https://example.com/icon.png"><p class="description"><?php esc_html_e( 'Templates use the {{icon_url}} variable; leave it empty to hide the footer icon.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Subscription Page URL', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="register_page_url" value="<?php echo esc_attr($s['register_page_url']); ?>" placeholder="https://example.com/mail-subscribe/"><p class="description"><?php esc_html_e( 'Fallback URL used when no language-specific subscription URL is configured.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Chinese Subscription Page URL', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="register_page_url_zh" value="<?php echo esc_attr($s['register_page_url_zh']); ?>" placeholder="https://example.com/zh/mail-subscribe/"><p class="description"><?php esc_html_e( 'Chinese confirmation, unsubscribe, and footer links use this URL first.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'English Subscription Page URL', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="register_page_url_en" value="<?php echo esc_attr($s['register_page_url_en']); ?>" placeholder="https://example.com/mail-subscribe/"><p class="description"><?php esc_html_e( 'English confirmation, unsubscribe, and footer links use this URL first.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Default Unsubscribe Button', 'mad-event-mailer' ); ?></th><td><label><input type="checkbox" name="default_unsubscribe_button" value="1" <?php checked(!empty($s['default_unsubscribe_button'])); ?>> <?php esc_html_e( 'Add a Manage Subscription / Unsubscribe button to new campaigns by default', 'mad-event-mailer' ); ?></label><p class="description"><?php esc_html_e( 'The plugin appends this button automatically; do not add it to the HTML template or message body.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Default Unsubscribe Language', 'mad-event-mailer' ); ?></th><td><select name="default_unsubscribe_lang"><option value="zh" <?php selected($s['default_unsubscribe_lang'] ?? 'en','zh'); ?>><?php esc_html_e( 'Chinese', 'mad-event-mailer' ); ?></option><option value="en" <?php selected($s['default_unsubscribe_lang'] ?? 'en','en'); ?>><?php esc_html_e( 'English', 'mad-event-mailer' ); ?></option></select></td></tr></table>
        <?php submit_button(__( 'Save Settings', 'mad-event-mailer' )); ?></form><?php self::wrap_end();
    }

    public static function page_templates() { self::render_admin_page('render_page_templates'); }
    private static function render_page_templates() {
        global $wpdb;
        $edit = null;
        if (!empty($_GET['edit'])) $edit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('templates'), absint(wp_unslash($_GET['edit'])) ) );
        self::wrap_start(__( 'Email Templates', 'mad-event-mailer' ));
        $show_query = !empty($query_result);
        ?>
        <div class="notice notice-info" style="padding:12px 14px"><p><strong><?php esc_html_e( 'Template Syntax Rules:', 'mad-event-mailer' ); ?></strong></p><p><?php esc_html_e( 'Variables use the {{variable_name}} format and may contain letters, numbers, underscores, or hyphens only. {{title}} / {{title1}} use the campaign subject; {{name}} / {{name1}} use the recipient name; {{email}} uses the recipient email; and {{unsubscribe_url}} generates the unsubscribe URL.', 'mad-event-mailer' ); ?></p><p><?php esc_html_e( 'General templates should keep a {{message1}} or {{message}} body slot. You can add variables such as {{score}} and {{rank}} to the body; matching CSV columns override their values for each recipient.', 'mad-event-mailer' ); ?></p></div>
        <h2><?php esc_html_e( 'Quick Create from General Template', 'mad-event-mailer' ); ?></h2>
        <form method="post"><?php self::nonce('save_quick_template'); ?>
        <table class="form-table"><tr><th><?php esc_html_e( 'General Template', 'mad-event-mailer' ); ?></th><td><select name="base_template_id" required><?php foreach(self::get_templates() as $bt): ?><option value="<?php echo (int)$bt->id; ?>"><?php echo esc_html($bt->name); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'The body below is inserted into the template’s {{message1}} or {{message}} slot and saved as a new complete template.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'New Template Name', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="quick_name" required placeholder="<?php esc_attr_e( 'Example: Event score notification', 'mad-event-mailer' ); ?>"></td></tr>
        <tr><th><?php esc_html_e( 'Default Email Subject', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="quick_subject" value="{{title1}}"><p class="description"><?php esc_html_e( 'Keep {{title1}} here in most cases, then enter the actual subject when sending.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Body Content', 'mad-event-mailer' ); ?></th><td><?php wp_editor('', 'madevma_quick_body', ['textarea_name'=>'quick_body','textarea_rows'=>10,'media_buttons'=>false,'teeny'=>false,'quicktags'=>true]); ?><p class="description"><?php esc_html_e( 'Use rich text and variables such as {{score}} or {{comment}}. These variables will appear on the send page and in generated CSV templates.', 'mad-event-mailer' ); ?></p></td></tr></table>
        <?php submit_button(__( 'Create from General Template', 'mad-event-mailer' )); ?></form><hr>
        <h2><?php echo esc_html($edit ? __( 'Edit Template', 'mad-event-mailer' ) : __( 'Add Template', 'mad-event-mailer' )); ?></h2>
        <form method="post" enctype="multipart/form-data"><?php self::nonce('save_template'); ?><input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
        <table class="form-table"><tr><th><?php esc_html_e( 'Template Name', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="name" required value="<?php echo esc_attr($edit->name ?? ''); ?>"></td></tr>
        <tr><th><?php esc_html_e( 'Default Email Subject', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="subject" value="<?php echo esc_attr($edit->subject ?? ''); ?>"><p class="description"><?php esc_html_e( 'Use {{title1}} or enter a fixed subject. The campaign subject fills {{title}} and {{title1}} when sending.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Email Summary', 'mad-event-mailer' ); ?></th><td><textarea class="large-text" name="summary" rows="3"><?php echo esc_textarea($edit->summary ?? ''); ?></textarea></td></tr>
        <tr><th><?php esc_html_e( 'Upload HTML', 'mad-event-mailer' ); ?></th><td><input type="file" name="html_file" accept=".html,.htm"><p class="description"><?php esc_html_e( 'You can also paste HTML below. Variables use the {{variable_name}} format. {{name}} and {{name1}} automatically use the recipient name.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th>HTML</th><td><textarea class="large-text code" name="html" rows="18"><?php echo esc_textarea($edit->html ?? ''); ?></textarea></td></tr></table>
        <?php submit_button(__( 'Save Template', 'mad-event-mailer' )); ?></form>
        <h2><?php esc_html_e( 'Saved Templates', 'mad-event-mailer' ); ?></h2><table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'Template Name', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Email Subject', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Variables', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Actions', 'mad-event-mailer' ); ?></th></tr></thead><tbody><?php foreach (self::get_templates() as $t): $vars=self::extract_vars($t->html.' '.$t->subject); ?>
        <tr><td><?php echo (int)$t->id; ?></td><td><?php echo esc_html($t->name); ?><?php if (self::is_builtin_template($t->id)) echo ' <span class="description">'.esc_html__( 'General Template', 'mad-event-mailer' ).'</span>'; ?></td><td><?php echo esc_html($t->subject); ?></td><td><?php echo esc_html(implode(', ', $vars)); ?></td><td class="madevma-mailer-template-actions"><a href="<?php echo esc_url(admin_url('admin.php?page=madevma-mailer-templates&edit='.$t->id)); ?>"><?php esc_html_e( 'Edit', 'mad-event-mailer' ); ?></a><button type="button" class="button-link madevma-mailer-template-preview" data-url="<?php echo esc_url(self::preview_url($t->id)); ?>"><?php esc_html_e( 'Preview', 'mad-event-mailer' ); ?></button><a href="<?php echo esc_url(self::export_url($t->id)); ?>"><?php esc_html_e( 'Export Recipient Template', 'mad-event-mailer' ); ?></a><?php if (!self::is_builtin_template($t->id)): ?><form method="post" data-confirm-delete="<?php esc_attr_e( 'Are you sure you want to delete this?', 'mad-event-mailer' ); ?>"><?php self::nonce('delete_template'); ?><input type="hidden" name="id" value="<?php echo (int)$t->id; ?>"><button class="button-link-delete"><?php esc_html_e( 'Delete', 'mad-event-mailer' ); ?></button></form><?php else: ?><span class="description"><?php esc_html_e( 'Not Deletable', 'mad-event-mailer' ); ?></span><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <div class="madevma-mailer-modal" id="madevmaTemplateModal"><div class="madevma-mailer-modal-box"><div class="madevma-mailer-modal-head"><strong><?php esc_html_e( 'Template Preview', 'mad-event-mailer' ); ?></strong><button type="button" class="button" id="madevmaTemplateClose"><?php esc_html_e( 'Close', 'mad-event-mailer' ); ?></button></div><iframe id="madevmaTemplateFrame"></iframe></div></div>
        <?php self::wrap_end();
    }

    public static function page_events() { self::render_admin_page('render_page_events'); }
    private static function render_page_events() {
        global $wpdb; $edit=null; if (!empty($_GET['edit'])) $edit=$wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table('events'), absint(wp_unslash($_GET['edit'])) ) );
        self::wrap_start(__( 'Events', 'mad-event-mailer' )); ?>
        <form method="post"><?php self::nonce('save_event'); ?><input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
        <table class="form-table"><tr><th><?php esc_html_e( 'Event Name', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="name" required value="<?php echo esc_attr($edit->name ?? ''); ?>"></td></tr>
        <tr><th><?php esc_html_e( 'Slug', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="slug" value="<?php echo esc_attr($edit->slug ?? ''); ?>"></td></tr>
        <tr><th><?php esc_html_e( 'Event Description', 'mad-event-mailer' ); ?></th><td><textarea class="large-text" name="description" rows="3"><?php echo esc_textarea($edit->description ?? ''); ?></textarea></td></tr>
        <tr><th><?php esc_html_e( 'Enabled', 'mad-event-mailer' ); ?></th><td><label><input type="checkbox" name="active" <?php checked(($edit->active ?? 1), 1); ?>> <?php esc_html_e( 'Show in the public subscription form', 'mad-event-mailer' ); ?></label></td></tr></table><?php submit_button(__( 'Save Event', 'mad-event-mailer' )); ?></form>
        <form method="post" id="madevma-mailer-event-order-form"><?php self::nonce('save_event_order'); ?>
        <table class="widefat striped"><thead><tr><th style="width:90px"><?php esc_html_e( 'Order', 'mad-event-mailer' ); ?></th><th>ID</th><th><?php esc_html_e( 'Event Name', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Slug', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Chinese Subscribers', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'English Subscribers', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Enabled', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Actions', 'mad-event-mailer' ); ?></th></tr></thead><tbody id="madevma-mailer-event-order"><?php foreach (self::get_events(false) as $e): $zh_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE event_id=%d AND language=%s', self::table('subscriber_events'), $e->id, 'zh')); $en_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE event_id=%d AND language=%s', self::table('subscriber_events'), $e->id, 'en')); ?>
        <tr draggable="true"><td class="madevma-mailer-drag-handle">↕ <?php esc_html_e( 'Drag', 'mad-event-mailer' ); ?><input type="hidden" name="event_order[]" value="<?php echo (int)$e->id; ?>"></td><td><?php echo (int)$e->id; ?></td><td><?php echo esc_html($e->name); ?></td><td><?php echo esc_html($e->slug); ?></td><td><?php echo esc_html((string)$zh_count); ?></td><td><?php echo esc_html((string)$en_count); ?></td><td><?php echo esc_html($e->active ? __( 'Yes', 'mad-event-mailer' ) : __( 'No', 'mad-event-mailer' )); ?></td><td><a href="<?php echo esc_url(admin_url('admin.php?page=madevma-mailer-events&edit='.$e->id)); ?>"><?php esc_html_e( 'Edit', 'mad-event-mailer' ); ?></a> <button class="button-link-delete" type="submit" form="madevma-mailer-delete-event-<?php echo (int)$e->id; ?>" data-confirm="<?php esc_attr_e( 'Are you sure you want to delete this event?', 'mad-event-mailer' ); ?>"><?php esc_html_e( 'Delete', 'mad-event-mailer' ); ?></button></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php submit_button(__( 'Save Event Order', 'mad-event-mailer' ), 'secondary'); ?></form>
        <?php foreach (self::get_events(false) as $e): ?><form method="post" id="madevma-mailer-delete-event-<?php echo (int)$e->id; ?>" style="display:none"><?php self::nonce('delete_event'); ?><input type="hidden" name="id" value="<?php echo (int)$e->id; ?>"></form><?php endforeach; ?>
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
        self::wrap_start(__( 'Subscribers', 'mad-event-mailer' )); ?>
        <p><?php esc_html_e( 'Supported CSV columns: email, name, and events. The legacy Chinese headers are also accepted. Enter recipient-list names in the events column and separate multiple lists with commas.', 'mad-event-mailer' ); ?></p>
        <form method="post" enctype="multipart/form-data"><?php self::nonce('import_csv'); ?><input type="file" name="csv_file" accept=".csv" required> <?php esc_html_e( 'Default Event:', 'mad-event-mailer' ); ?> <select name="default_event"><option value="0"><?php esc_html_e( 'None', 'mad-event-mailer' ); ?></option><?php foreach($events as $e) foreach(['zh','en'] as $list_lang) echo '<option value="'.esc_attr(self::event_language_value($e->id,$list_lang)).'">'.esc_html(self::event_language_label($e,$list_lang)).'</option>'; ?></select> <?php submit_button(__( 'Import CSV', 'mad-event-mailer' ), 'secondary', 'submit', false); ?></form>
        <h2><?php echo esc_html($edit ? __( 'Edit Subscriber', 'mad-event-mailer' ) : __( 'Add Subscriber', 'mad-event-mailer' )); ?></h2>
        <form method="post"><?php self::nonce('save_subscriber'); ?><input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
            <p><input name="email" placeholder="email@example.com" required value="<?php echo esc_attr($edit->email ?? ''); ?>"> <input name="name" placeholder="<?php esc_attr_e( 'Name', 'mad-event-mailer' ); ?>" value="<?php echo esc_attr($edit->name ?? ''); ?>"> <select name="status"><option value="subscribed" <?php selected($edit->status ?? 'subscribed','subscribed'); ?>><?php esc_html_e( 'Subscribed', 'mad-event-mailer' ); ?></option><option value="unsubscribed" <?php selected($edit->status ?? 'subscribed','unsubscribed'); ?>><?php esc_html_e( 'Unsubscribed', 'mad-event-mailer' ); ?></option></select></p>
            <p><?php foreach($events as $e): foreach(['zh','en'] as $list_lang): $value=self::event_language_value($e->id,$list_lang); ?><label style="margin-right:12px"><input type="checkbox" name="event_lists[]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $selected_lists, true)); ?>> <?php echo esc_html(self::event_language_label($e,$list_lang)); ?></label><?php endforeach; endforeach; ?></p>
            <?php submit_button($edit ? __( 'Update Subscriber', 'mad-event-mailer' ) : __( 'Save', 'mad-event-mailer' ), 'secondary', 'submit', false); ?> <?php if($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=madevma-mailer-subscribers')); ?>"><?php esc_html_e( 'Cancel Editing', 'mad-event-mailer' ); ?></a><?php endif; ?>
        </form>
        <h2><?php esc_html_e( 'Subscriber List', 'mad-event-mailer' ); ?></h2>
        <form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input type="hidden" name="page" value="madevma-mailer-subscribers"><label><?php esc_html_e( 'Filter by event language', 'mad-event-mailer' ); ?> <select name="subscriber_filter"><option value="0"><?php esc_html_e( 'All Subscribers', 'mad-event-mailer' ); ?></option><?php foreach($events as $e): foreach(['zh','en'] as $list_lang): $value=self::event_language_value($e->id,$list_lang); ?><option value="<?php echo esc_attr($value); ?>" <?php selected($filter_value,$value); ?>><?php echo esc_html(self::event_language_label($e,$list_lang)); ?></option><?php endforeach; endforeach; ?></select></label><?php submit_button(__( 'Filter', 'mad-event-mailer' ), 'secondary', 'submit', false); ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=madevma-mailer-subscribers')); ?>"><?php esc_html_e( 'Reset', 'mad-event-mailer' ); ?></a></form>
        <form method="post" style="margin:0 0 12px"><?php self::nonce('export_subscribers_csv'); ?><input type="hidden" name="subscriber_filter" value="<?php echo esc_attr($filter_value); ?>"><?php submit_button(__( 'Export Filtered CSV', 'mad-event-mailer' ), 'secondary', 'submit', false); ?> <span class="description"><?php echo esc_html(sprintf(
            /* translators: %d: number of subscribers in the current filter. */
            _n( '%d subscriber in the current filter.', '%d subscribers in the current filter.', $total, 'mad-event-mailer' ),
            $total
        )); ?></span></form>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Email', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Name', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Subscribed Events', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Status', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Actions', 'mad-event-mailer' ); ?></th></tr></thead><tbody><?php foreach($rows as $r): $names=self::subscriber_event_labels($r->id); ?>
        <tr><td><?php echo esc_html($r->email); ?></td><td><?php echo esc_html($r->name); ?></td><td><?php echo esc_html(implode(', ', $names)); ?></td><td><?php echo esc_html(self::status_label($r->status)); ?></td><td><a href="<?php echo esc_url(admin_url('admin.php?page=madevma-mailer-subscribers&edit='.$r->id)); ?>"><?php esc_html_e( 'Edit', 'mad-event-mailer' ); ?></a> <form method="post" style="display:inline" data-confirm-delete="<?php esc_attr_e( 'Are you sure you want to delete this subscriber?', 'mad-event-mailer' ); ?>"><?php self::nonce('delete_subscriber'); ?><input type="hidden" name="id" value="<?php echo (int)$r->id; ?>"><button class="button-link-delete"><?php esc_html_e( 'Delete', 'mad-event-mailer' ); ?></button></form></td></tr>
        <?php endforeach; if(empty($rows)): ?><tr><td colspan="5"><?php esc_html_e( 'No subscribers found.', 'mad-event-mailer' ); ?></td></tr><?php endif; ?></tbody></table><?php self::wrap_end();
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
        $initial_unsub_lang = in_array(($loaded_vars['__unsubscribe_lang'] ?? ($settings['default_unsubscribe_lang'] ?? 'en')), ['zh','en'], true) ? ($loaded_vars['__unsubscribe_lang'] ?? ($settings['default_unsubscribe_lang'] ?? 'en')) : 'en';
        if (!$initial_subject && $selected_template && !preg_match('/^\s*{{\s*title1?\s*}}\s*$/', (string)$selected_template->subject)) $initial_subject = $selected_template->subject;
        self::wrap_start(__( 'Send / Schedule Email', 'mad-event-mailer' ));
        if ($loaded_campaign) self::notice(sprintf(
            /* translators: %d: campaign ID. */
            __( 'Loaded campaign #%d settings. You can edit them and save another draft or create a new campaign.', 'mad-event-mailer' ),
            $loaded_campaign->id
        ), 'info');
        $show_query = !empty($query_result);
        ?>
        <form method="get" class="madevma-mailer-card" style="margin-bottom:14px">
            <input type="hidden" name="page" value="madevma-mailer">
            <?php if ($loaded_campaign): ?><input type="hidden" name="campaign_id" value="<?php echo (int)$loaded_campaign->id; ?>"><?php endif; ?>
            <table class="form-table"><tr><th><?php esc_html_e( 'Template', 'mad-event-mailer' ); ?></th><td>
                <select name="template_id" id="template_id_switch" required>
                    <option value=""><?php esc_html_e( 'Select...', 'mad-event-mailer' ); ?></option>
                    <?php foreach($templates as $t): ?><option value="<?php echo (int)$t->id; ?>" <?php selected($selected_id, (int)$t->id); ?>><?php echo esc_html($t->name); ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="button button-secondary"><?php esc_html_e( 'Switch Template', 'mad-event-mailer' ); ?></button>
                <p class="madevma-mailer-help"><?php esc_html_e( 'Select a template and click Switch Template to rebuild the body slots and variable fields. This button does not send email.', 'mad-event-mailer' ); ?></p>
            </td></tr></table>
        </form>
        <form method="post" id="madevma-mailer-send" enctype="multipart/form-data" class="madevma-mailer-card"><?php self::nonce('create_campaign'); ?>
        <input type="hidden" name="template_id" id="template_id" value="<?php echo (int)$selected_id; ?>">
        <table class="form-table"><tr><th><?php esc_html_e( 'Current Template', 'mad-event-mailer' ); ?></th><td><strong><?php echo $selected_template ? esc_html($selected_template->name) : esc_html__( 'No template selected', 'mad-event-mailer' ); ?></strong> <button type="submit" class="button" name="madevma_action" value="export_current_csv"><?php esc_html_e( 'Export Recipient CSV for Current Content', 'mad-event-mailer' ); ?></button><p class="madevma-mailer-help"><?php esc_html_e( 'Use the Template section above to change templates. CSV fields are generated from the current template and body content.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Email Subject', 'mad-event-mailer' ); ?></th><td><input class="regular-text" name="subject" id="subject" required value="<?php echo esc_attr($initial_subject); ?>"><p class="madevma-mailer-help"><?php esc_html_e( 'The subject automatically fills {{title}} and {{title1}}, so you do not need to set them separately.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Recipient Source', 'mad-event-mailer' ); ?></th><td><label><input type="radio" name="recipient_mode" value="event" checked> <?php esc_html_e( 'Send by event subscription list', 'mad-event-mailer' ); ?></label> &nbsp; <label><input type="radio" name="recipient_mode" value="csv"> <?php esc_html_e( 'Send using the uploaded CSV', 'mad-event-mailer' ); ?></label></td></tr>
        <tr class="recipient-event"><th><?php esc_html_e( 'Recipient Event List', 'mad-event-mailer' ); ?></th><td><select name="event_id"><option value="0"><?php esc_html_e( 'All subscribed recipients', 'mad-event-mailer' ); ?></option><?php foreach($events as $e): foreach(['zh','en'] as $list_lang): ?><option value="<?php echo esc_attr(self::event_language_value($e->id, $list_lang)); ?>"><?php echo esc_html(self::event_language_label($e, $list_lang)); ?></option><?php endforeach; endforeach; ?></select><p class="madevma-mailer-help"><?php esc_html_e( 'Admin recipient lists are separated by language; the public form still displays the event name and language choice.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr class="recipient-csv" style="display:none"><th><?php esc_html_e( 'Upload Recipient CSV', 'mad-event-mailer' ); ?></th><td><input type="file" name="recipient_csv" accept=".csv"><p class="madevma-mailer-help"><?php esc_html_e( 'Select a template, export its recipient CSV, fill in email, name, events, and extra variables, then upload it. The name column fills {{name}} and {{name1}}.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Body Content', 'mad-event-mailer' ); ?></th><td><div id="bodybox">
            <?php if (!$selected_template): ?>
                <p class="description"><?php esc_html_e( 'Please select a template first.', 'mad-event-mailer' ); ?></p>
            <?php elseif (empty($body_vars)): ?>
                <p class="description"><?php esc_html_e( 'The current template has no {{message}} or {{message1}} body slot. You can still fill in other custom variables below.', 'mad-event-mailer' ); ?></p>
            <?php else: ?>
                <?php foreach ($body_vars as $bv): ?>
                    <p><strong><?php echo esc_html(sprintf(
                        /* translators: %s: template variable name. */
                        __( 'Edit the body content for {{%s}}', 'mad-event-mailer' ),
                        $bv
                    )); ?></strong></p>
                    <?php wp_editor($loaded_vars[$bv] ?? '', 'madevma_body_'.$bv, [
                        'textarea_name' => 'var['.$bv.']',
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                        'teeny' => false,
                        'quicktags' => true,
                    ]); ?>
                    <p class="madevma-mailer-help"><?php esc_html_e( 'The body can include variables such as {{score}}, {{rank}}, and {{comment}}. Matching CSV columns provide different values for each recipient.', 'mad-event-mailer' ); ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div></td></tr>
        <tr><th><?php esc_html_e( 'Email Footer Unsubscribe Button', 'mad-event-mailer' ); ?></th><td><label><input type="checkbox" name="include_unsubscribe" value="1" <?php checked($initial_unsub); ?>> <?php esc_html_e( 'Automatically add an unsubscribe button to the email footer', 'mad-event-mailer' ); ?></label> <?php esc_html_e( 'Language:', 'mad-event-mailer' ); ?> <select name="unsubscribe_lang"><option value="zh" <?php selected($initial_unsub_lang, 'zh'); ?>><?php esc_html_e( 'Chinese', 'mad-event-mailer' ); ?></option><option value="en" <?php selected($initial_unsub_lang, 'en'); ?>><?php esc_html_e( 'English', 'mad-event-mailer' ); ?></option></select><p class="madevma-mailer-help"><?php esc_html_e( 'The plugin appends the unsubscribe button automatically. Previews, test emails, and campaigns use the language selected here.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Other Variables', 'mad-event-mailer' ); ?></th><td><div id="varbox">
            <?php if (!$selected_template): ?>
                <p class="description"><?php esc_html_e( 'Please select a template first.', 'mad-event-mailer' ); ?></p>
            <?php elseif (empty($editable_vars)): ?>
                <p class="madevma-mailer-empty-vars"><?php esc_html_e( 'The current template has no other variables that require manual input. Add a variable such as {{score}} to the body to create a field here.', 'mad-event-mailer' ); ?></p>
            <?php else: ?>
                <?php foreach ($editable_vars as $v): ?>
                    <p class="madevma-mailer-varrow" data-varrow="<?php echo esc_attr($v); ?>"><label><strong class="madevma-mailer-var-label">{{<?php echo esc_html($v); ?>}}</strong><br><textarea class="large-text" rows="3" name="var[<?php echo esc_attr($v); ?>]" placeholder="<?php esc_attr_e( 'Enter a global default value. A matching CSV column takes priority for each recipient.', 'mad-event-mailer' ); ?>"><?php echo esc_textarea($loaded_vars[$v] ?? ''); ?></textarea></label></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div><p class="madevma-mailer-help"><?php esc_html_e( '{{name}} and {{name1}} use the recipient name; {{title}} and {{title1}} use the email subject; {{unsubscribe_url}} generates the unsubscribe URL. Variables in the body are replaced during previews and sending.', 'mad-event-mailer' ); ?></p></td></tr>
        <tr><th><?php esc_html_e( 'Sending Time', 'mad-event-mailer' ); ?></th><td><label><input type="radio" name="send_mode" value="now" checked> <?php esc_html_e( 'Send now', 'mad-event-mailer' ); ?></label> <label><input type="radio" name="send_mode" value="schedule"> <?php esc_html_e( 'Schedule', 'mad-event-mailer' ); ?></label> <input type="datetime-local" name="scheduled_at"></td></tr></table>
        <p class="submit"><button type="submit" class="button" id="previewBtn" name="madevma_action" value="preview_current_static"><?php esc_html_e( 'Preview', 'mad-event-mailer' ); ?></button> <button type="button" class="button" id="testBtn"><?php esc_html_e( 'Send Test Email', 'mad-event-mailer' ); ?></button> <button type="submit" class="button button-secondary" name="madevma_action" value="save_campaign_draft"><?php esc_html_e( 'Save as Draft', 'mad-event-mailer' ); ?></button> <button type="submit" class="button button-primary" name="madevma_action" value="create_campaign"><?php esc_html_e( 'Create Campaign', 'mad-event-mailer' ); ?></button></p></form>
        <div class="madevma-mailer-preview-modal" id="previewModal"><div class="madevma-mailer-preview-box"><div class="madevma-mailer-preview-head"><strong id="previewTitle"><?php esc_html_e( 'Preview', 'mad-event-mailer' ); ?></strong><div class="madevma-mailer-preview-actions"><span id="previewStatus" class="description"></span><button type="button" class="button" id="closePreview"><?php esc_html_e( 'Close', 'mad-event-mailer' ); ?></button></div></div><div id="testPanel" style="display:none;margin-bottom:14px;padding:12px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7"><p><label><strong><?php esc_html_e( 'Test Email Address', 'mad-event-mailer' ); ?></strong><br><input type="email" id="testEmail" class="regular-text" placeholder="test@example.com"></label></p><div id="testVars"></div><p><button type="button" class="button button-primary" id="sendTestNow"><?php esc_html_e( 'Send Test Email', 'mad-event-mailer' ); ?></button></p></div><iframe id="previewFrame" name="madevmaPreviewFrame"></iframe></div></div>
        <p><?php esc_html_e( 'Subscription form shortcode:', 'mad-event-mailer' ); ?> <code>[madevma_email_register]</code></p><?php self::wrap_end();
    }

    public static function page_campaigns() { self::render_admin_page('render_page_campaigns'); }
    private static function render_page_campaigns() {
        global $wpdb; self::wrap_start(__( 'Campaigns', 'mad-event-mailer' ));
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
            <input type="hidden" name="page" value="madevma-mailer-campaigns">
            <label><?php esc_html_e( 'Filter by event', 'mad-event-mailer' ); ?> <select name="event_id"><option value="0"><?php esc_html_e( 'All Events', 'mad-event-mailer' ); ?></option><?php foreach($events as $e): ?><option value="<?php echo (int)$e->id; ?>" <?php selected($event_id,(int)$e->id); ?>><?php echo esc_html($e->name); ?></option><?php endforeach; ?></select></label>
            <label><?php esc_html_e( 'Filter by status', 'mad-event-mailer' ); ?> <select name="status"><option value=""><?php esc_html_e( 'All Statuses', 'mad-event-mailer' ); ?></option><?php foreach(['draft','scheduled','queued','sending','finished','failed'] as $st): ?><option value="<?php echo esc_attr($st); ?>" <?php selected($status,$st); ?>><?php echo esc_html(self::status_label($st)); ?></option><?php endforeach; ?></select></label>
            <button class="button"><?php esc_html_e( 'Filter', 'mad-event-mailer' ); ?></button>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=madevma-mailer-campaigns')); ?>"><?php esc_html_e( 'Reset', 'mad-event-mailer' ); ?></a>
        </form>
        <table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'Email Subject', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Event', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Status', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Scheduled Time', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Total', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Sent', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Failed', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Created', 'mad-event-mailer' ); ?></th><th><?php esc_html_e( 'Actions', 'mad-event-mailer' ); ?></th></tr></thead><tbody><?php foreach($rows as $r): $event_name = $r->event_id ? $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id=%d', self::table('events'), $r->event_id ) ) : __( 'All / CSV', 'mad-event-mailer' ); ?>
        <tr><td><?php echo (int)$r->id; ?></td><td><?php echo esc_html($r->subject); ?></td><td><?php echo esc_html($event_name); ?></td><td><?php echo esc_html(self::status_label($r->status)); ?></td><td><?php echo esc_html($r->scheduled_at); ?></td><td><?php echo (int)$r->total; ?></td><td><?php echo (int)$r->sent; ?></td><td><?php echo (int)$r->failed; ?></td><td><?php echo esc_html($r->created_at); ?></td><td><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=madevma-mailer&campaign_id='.$r->id)); ?>"><?php esc_html_e( 'Load Settings to Edit', 'mad-event-mailer' ); ?></a></td></tr>
        <?php endforeach; if(empty($rows)): ?><tr><td colspan="10"><?php esc_html_e( 'No campaigns found.', 'mad-event-mailer' ); ?></td></tr><?php endif; ?></tbody></table>
        <p class="description"><?php esc_html_e( 'Load Settings to Edit returns to the send page with the campaign template, subject, and variables. It does not send email until you create a campaign again.', 'mad-event-mailer' ); ?></p>
        <?php self::wrap_end();
    }

    public static function handle_public_register() {
        if (empty($_POST['madevma_public_register']) && empty($_POST['madevma_public_unsubscribe'])) return;
        if (!isset($_POST['madevma_public_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['madevma_public_nonce'])), 'madevma_public_register')) return;
        global $wpdb;
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $language = self::request_subscription_language();
        if (!empty($_POST['madevma_public_unsubscribe'])) {
            if (is_email($email)) {
                $sub = $wpdb->get_row( $wpdb->prepare( 'SELECT name FROM %i WHERE email=%s', self::table('subscribers'), $email ) );
                $wpdb->update(self::table('subscribers'), ['status'=>'unsubscribed','updated_at'=>self::now()], ['email'=>$email]);
                self::send_subscription_notice($email, $sub->name ?? '', $language, 'unsubscribe');
            }
            wp_safe_redirect(add_query_arg('madevma_unsubscribed', '1', self::public_form_redirect_url($language))); exit;
        }
        self::upsert_subscriber($email, $name, array_map('absint', wp_unslash($_POST['events'] ?? [])), 'shortcode', true, $language);
        self::send_subscription_notice($email, $name, $language, 'subscribe');
        wp_safe_redirect(add_query_arg('madevma_registered', '1', self::public_form_redirect_url($language))); exit;
    }

    public static function shortcode_register($atts) {
        $events=self::get_events(true); ob_start();
        $show_unsub = !empty($_GET['madevma_action']) && sanitize_text_field(wp_unslash($_GET['madevma_action'])) === 'unsubscribe';
        $current_subscription_language = self::request_subscription_language();
        $current_url = self::current_public_url();
        $query_result = null;
        if (!empty($_POST['madevma_public_query']) && isset($_POST['madevma_public_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['madevma_public_nonce'])), 'madevma_public_register')) {
            global $wpdb;
            $qemail = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            if (is_email($qemail)) {
                $sub = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email=%s', self::table('subscribers'), $qemail ) );
                if ($sub) {
                    $names = self::subscriber_event_labels($sub->id);
                    $query_result = ['found'=>true,'name'=>$sub->name,'email'=>$sub->email,'status'=>$sub->status,'events'=>implode(', ', $names) ?: __( 'No specific events selected', 'mad-event-mailer' )];
                } else { $query_result = ['found'=>false,'email'=>$qemail]; }
            }
        }
        $show_query = !empty($query_result);
        ?>
        <?php if (!empty(sanitize_text_field(wp_unslash($_GET['madevma_registered'] ?? '')))) echo '<div class="madevma-mailer-success">'.esc_html__( 'Subscription saved. Future event notifications will be sent to your email.', 'mad-event-mailer' ).'</div>'; ?>
        <?php if (!empty(sanitize_text_field(wp_unslash($_GET['madevma_unsubscribed'] ?? '')))) echo '<div class="madevma-mailer-warning">'.esc_html__( 'Unsubscription submitted. This email will no longer receive event notifications.', 'mad-event-mailer' ).'</div>'; ?>
        <div class="madevma-mailer-register-wrap"><div class="madevma-mailer-register-card">
            <div class="madevma-mailer-tabs">
                <button type="button" class="madevma-mailer-tab <?php echo (!$show_unsub && !$show_query) ? 'active' : ''; ?>" data-target="subscribe"><?php esc_html_e( 'Subscribe', 'mad-event-mailer' ); ?></button>
                <button type="button" class="madevma-mailer-tab <?php echo ($show_unsub && !$show_query) ? 'active' : ''; ?>" data-target="unsubscribe"><?php esc_html_e( 'Unsubscribe', 'mad-event-mailer' ); ?></button>
                <button type="button" class="madevma-mailer-tab <?php echo $show_query ? 'active' : ''; ?>" data-target="query"><?php esc_html_e( 'Check Subscription', 'mad-event-mailer' ); ?></button>
            </div>
            <form class="madevma-mailer-panel <?php echo (!$show_unsub && !$show_query) ? 'active' : ''; ?>" data-panel="subscribe" method="post">
                <input type="hidden" name="madevma_public_register" value="1"><input type="hidden" name="madevma_redirect" value="<?php echo esc_url($current_url); ?>"><?php wp_nonce_field('madevma_public_register', 'madevma_public_nonce'); ?>
                <h2 class="madevma-mailer-register-title"><?php esc_html_e( 'Subscribe to Event Notifications', 'mad-event-mailer' ); ?></h2>
                <p class="madevma-mailer-register-sub"><?php esc_html_e( 'Enter your email and select the events you want to receive. Submitting again only adds new categories and does not remove existing subscriptions.', 'mad-event-mailer' ); ?></p>
                <div class="madevma-mailer-grid"><div class="madevma-mailer-field"><label><?php esc_html_e( 'Name', 'mad-event-mailer' ); ?></label><input type="text" name="name" placeholder="<?php esc_attr_e( 'Enter your name', 'mad-event-mailer' ); ?>" required></div><div class="madevma-mailer-field"><label><?php esc_html_e( 'Email', 'mad-event-mailer' ); ?></label><input type="email" name="email" placeholder="name@example.com" required></div></div>
                <div class="madevma-mailer-field" style="margin-top:16px"><label><?php esc_html_e( 'Subscription Language', 'mad-event-mailer' ); ?></label><select name="subscription_language" style="width:100%;box-sizing:border-box;border:1px solid #dbe3ef;border-radius:15px;background:#fff;padding:14px 15px;font-size:15px"><option value="zh" <?php selected($current_subscription_language, 'zh'); ?>><?php esc_html_e( 'Chinese', 'mad-event-mailer' ); ?></option><option value="en" <?php selected($current_subscription_language, 'en'); ?>><?php esc_html_e( 'English', 'mad-event-mailer' ); ?></option></select></div>
                <div class="madevma-mailer-events"><div class="madevma-mailer-events-title"><?php esc_html_e( 'Select events to receive notifications', 'mad-event-mailer' ); ?></div><div class="madevma-mailer-event-list">
                <?php foreach($events as $e): ?><label class="madevma-mailer-event-item"><input type="checkbox" name="events[]" value="<?php echo (int)$e->id; ?>"><span><span class="madevma-mailer-event-name"><?php echo esc_html($e->name); ?></span></span></label><?php endforeach; ?>
                </div></div>
                <div class="madevma-mailer-actions"><button class="madevma-mailer-submit" type="submit"><?php esc_html_e( 'Save Subscription', 'mad-event-mailer' ); ?></button><span class="madevma-mailer-note"><?php esc_html_e( 'You can return to this page to unsubscribe at any time.', 'mad-event-mailer' ); ?></span></div>
            </form>
            <form class="madevma-mailer-panel <?php echo ($show_unsub && !$show_query) ? 'active' : ''; ?>" data-panel="unsubscribe" method="post">
                <input type="hidden" name="madevma_public_unsubscribe" value="1"><input type="hidden" name="madevma_redirect" value="<?php echo esc_url($current_url); ?>"><input type="hidden" name="subscription_language" value="<?php echo esc_attr($current_subscription_language); ?>"><?php wp_nonce_field('madevma_public_register', 'madevma_public_nonce'); ?>
                <h2 class="madevma-mailer-register-title"><?php esc_html_e( 'Unsubscribe from Event Notifications', 'mad-event-mailer' ); ?></h2>
                <p class="madevma-mailer-register-sub"><?php esc_html_e( 'Enter the email address to unsubscribe from all event notifications at once.', 'mad-event-mailer' ); ?></p>
                <div class="madevma-mailer-field"><label><?php esc_html_e( 'Email', 'mad-event-mailer' ); ?></label><input type="email" name="email" placeholder="name@example.com" required></div>
                <div class="madevma-mailer-actions"><button class="madevma-mailer-submit danger" type="submit"><?php esc_html_e( 'Confirm Unsubscribe', 'mad-event-mailer' ); ?></button><span class="madevma-mailer-note"><?php esc_html_e( 'After unsubscribing, use the subscription form again if you want to receive notifications later.', 'mad-event-mailer' ); ?></span></div>
            </form>
            <form class="madevma-mailer-panel <?php echo $show_query ? 'active' : ''; ?>" data-panel="query" method="post">
                <input type="hidden" name="madevma_public_query" value="1"><?php wp_nonce_field('madevma_public_register', 'madevma_public_nonce'); ?>
                <h2 class="madevma-mailer-register-title"><?php esc_html_e( 'Check Subscription', 'mad-event-mailer' ); ?></h2>
                <p class="madevma-mailer-register-sub"><?php esc_html_e( 'Enter your email to view the saved name, email address, and subscribed event categories.', 'mad-event-mailer' ); ?></p>
                <div class="madevma-mailer-field"><label><?php esc_html_e( 'Email', 'mad-event-mailer' ); ?></label><input type="email" name="email" placeholder="name@example.com" required></div>
                <div class="madevma-mailer-actions"><button class="madevma-mailer-submit" type="submit"><?php esc_html_e( 'Check Subscription', 'mad-event-mailer' ); ?></button><span class="madevma-mailer-note"><?php esc_html_e( 'This only checks subscription status and does not modify your subscription.', 'mad-event-mailer' ); ?></span></div>
                <?php if ($query_result): ?>
                    <div style="margin-top:22px;padding:18px;border:1px solid #dbe3ef;border-radius:16px;background:#fff;">
                    <?php if (!empty($query_result['found'])): ?>
                        <p><strong><?php esc_html_e( 'Name:', 'mad-event-mailer' ); ?></strong> <?php echo esc_html($query_result['name']); ?></p>
                        <p><strong><?php esc_html_e( 'Email:', 'mad-event-mailer' ); ?></strong> <?php echo esc_html($query_result['email']); ?></p>
                        <p><strong><?php esc_html_e( 'Status:', 'mad-event-mailer' ); ?></strong> <?php echo esc_html(self::status_label($query_result['status'])); ?></p>
                        <p><strong><?php esc_html_e( 'Subscribed Categories:', 'mad-event-mailer' ); ?></strong> <?php echo esc_html($query_result['events']); ?></p>
                    <?php else: ?>
                        <p><?php echo esc_html(sprintf(
                            /* translators: %s: email address. */
                            __( 'No subscription record was found for %s.', 'mad-event-mailer' ),
                            $query_result['email']
                        )); ?></p>
                    <?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>
        </div></div>
        <?php return ob_get_clean();
    }

}

register_activation_hook(__FILE__, ['MADEVMA_Event_Mailer', 'activate']);
register_deactivation_hook(__FILE__, ['MADEVMA_Event_Mailer', 'deactivate']);
MADEVMA_Event_Mailer::init();
