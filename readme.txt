=== MAD Event Mailer ===
Contributors: ruoqin
Tags: email, smtp, newsletter, event, csv
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.1
License: GPL v2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An event email plugin with SMTP, HTML templates, CSV recipients, subscriptions, batch delivery, scheduling, and campaign drafts.

== Description ==

MAD Event Mailer is designed for event operation and notification workflows. It supports SMTP delivery, reusable HTML templates, template variables, CSV recipient import/export, event subscription lists, public subscription forms, batch sending, scheduled sending, and draft campaigns. The administration and public subscription interfaces use English source strings and the standard WordPress translation system.

Author: MAD Producer Studio
Author URI: https://github.com/MAD-Producer

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/mad-event-mailer/`.
2. Activate the plugin through the WordPress Plugins screen.
3. Go to MAD Mail and configure SMTP settings.
4. Create a subscription management page with `[madevma_email_register]`.

== Frequently Asked Questions ==

= Can I configure the images used by the email templates? =

Yes. Enter the Logo URL and Icon URL under SMTP Settings. The built-in templates use `{{logo_url}}` and `{{icon_url}}`; no remote image URL is hard-coded in the plugin.

= Can I send personalized values from CSV? =

Yes. Use variables such as `{{score}}`, `{{rank}}`, and `{{comment}}`, then include matching CSV columns.

= Can I edit recipients without uploading a CSV? =

Yes. The Subscribers screen includes an online row editor. Each row must be bound to an email template. Selecting a template automatically creates fields for the editable variables found in that template, so no JSON editing is required. The bound template and recipient variables are used when a campaign is sent.

= Does the plugin reject advertising recipients? =

The importer, online editor, public subscription form, and sending pipeline reject clearly promotional rows, such as link-heavy cryptocurrency advertisements. Existing rows can be reviewed and discarded from the Subscribers screen.

== Changelog ==

= 2.4.1 =
* Replaced online recipient JSON editing with template-driven variable fields.
* Made an email template mandatory for every online recipient row.
* Automatically detects editable variables from the selected template and rebuilds the row fields when the template changes.
* Reports online rows that were skipped because their template binding or recipient data was invalid.

= 2.4.0 =
* Updated the WordPress.org metadata to Tested up to WordPress 7.1 and reviewed the plugin's admin editor, file upload, toolbar, and dependency usage for the 7.1 changes.
* Added an obvious-advertisement recipient filter across public subscriptions, manual entry, CSV imports, online editing, campaign preparation, and sending.
* Added online row-by-row recipient editing with event-language grouping, per-recipient template binding, and per-recipient template variables.
* Extended recipient CSV templates and exports with a `template` column.
* Updated campaign logs and sending to use the recipient-bound template before the campaign fallback template.

= 2.3.1 =
* Converted the administration and public subscription interfaces to English source strings.
* Replaced the custom translation helper with standard WordPress gettext functions and the `mad-event-mailer` text domain.
* Localized dynamic JavaScript messages through WordPress and changed JavaScript fallback text to English.
* Migrated built-in template names to English while retaining compatibility with existing 2.3.0 data.
* Limited directory tags to five and shortened the plugin summary to meet WordPress.org metadata requirements.

= 2.3.0 =
* Added configurable Logo URL and Icon URL settings and replaced hard-coded template images with variables.
* Changed all public hooks, options, roles, capabilities, shortcodes, menu slugs, database tables, form fields, and asset selectors to the unique `madevma` prefix.
* Added one-time migration for existing settings, database records, Mail Manager users, scheduled tasks, and shortcode pages.
* Removed bundled translation files so translations can be managed by WordPress.org.
* Kept Chinese as the default administration and public subscription interface.
* Updated the release package for WordPress.org submission requirements.

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
