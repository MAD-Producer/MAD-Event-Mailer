=== MAD Event Mailer ===
Contributors: ruoqin
Tags: email, smtp, newsletter, event, html email, csv
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.2.5
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

= 2.2.5 =
* Added a dedicated Mail Manager role and capability; only Mail Managers and site administrators can see or use the plugin administration screens.
* Added nonce and capability checks across privileged requests.
* Moved frontend and administration CSS/JavaScript to properly enqueued assets.
* Completed English translation coverage for admin menus, dynamic preview/test messages, subscriber guidance, and notices.
* Declared WordPress 6.2 as the minimum supported version because safe SQL identifier placeholders require WordPress 6.2 or newer.
* Sanitized redirect URL handling, paired output buffering in one render flow, and strengthened the plugin's global declaration prefix.
* Fixed campaign filtering, CSV output annotations, package hygiene, and the WordPress.org contributor username.

= 2.2.4 =
* Fixed outgoing email sender name so SMTP sender settings are applied consistently.
* Subscription and unsubscribe confirmation emails now use the common HTML email template.

= 2.2.3 =
* Added bilingual subscription language selection on the public shortcode form.
* Splits backend recipient lists by event language, such as Site Announcement Chinese and Site Announcement English.
* Stores event subscriptions by recipient language for Chinese and English lists.
* Sends subscription and unsubscribe confirmation emails in the selected subscription language.
* Added separate Chinese and English subscription/unsubscribe page URLs.
* Updated the default sender name away from No-reply.
* Added drag-and-drop event ordering for frontend display.
* Added subscriber editing, event-language filtering, counts, and filtered CSV export.

= 2.2.1 =
* Updated plugin metadata for GitHub release.
* Added GPL v2 license header.
* Updated author to MAD Producer Studio with GitHub author URL.
* Added English README and Chinese README.

= 2.2.0 =
* Added language pack support.
