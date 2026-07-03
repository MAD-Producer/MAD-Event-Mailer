=== MAD Event Mailer ===
Contributors: MAD Producer Studio
Tags: email, smtp, newsletter, event, html email, csv
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.2.2
License: GPL v2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An HTML email delivery plugin for event notifications. Supports SMTP, template variables, CSV recipients, event subscriptions, shortcode registration, batch sending, scheduled sending and language packs.

== Description ==

MAD Event Mailer is designed for event operation and notification workflows. It supports SMTP delivery, reusable HTML templates, template variables, CSV recipient import/export, event subscription lists, public subscription forms, batch sending, scheduled sending, draft campaigns, and bilingual interface options.

Author: MAD Producer Studio
Author URI: https://github.com/MAD-Producer

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/mad-event-mailer/`.
2. Activate the plugin through the WordPress Plugins screen.
3. Go to MAD Mail / MAD 邮件 and configure SMTP settings.
4. Create a subscription management page with `[mad_email_register]`.

== Frequently Asked Questions ==

= Does it support English emails? =

Yes. The plugin includes a default English template and an English interface language pack.

= Can I send personalized values from CSV? =

Yes. Use variables such as `{{score}}`, `{{rank}}`, and `{{comment}}`, then include matching CSV columns.

== Changelog ==

= 2.2.2 =
* Updated plugin metadata for GitHub release.
* Added GPL v2 license header.
* Updated author to MAD Producer Studio with GitHub author URL.
* Added English README and Chinese README.
* Improved English translations for admin, campaign, template, subscriber, preview, and JavaScript status text.
* Changed admin and public language defaults to follow the WordPress site language.

= 2.2.1 =
* Updated plugin metadata for GitHub release.
* Added GPL v2 license header.
* Updated author to MAD Producer Studio with GitHub author URL.
* Added English README and Chinese README.

= 2.2.0 =
* Added language pack support.
