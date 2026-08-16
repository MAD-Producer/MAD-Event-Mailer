# MAD Event Mailer

Administration access is limited to WordPress administrators and users assigned the **Mail Manager** (`邮箱管理员`) role.

[中文文档 / Chinese README](./README.zh-CN.md)
<img width="1038" height="718" alt="Snipaste_2026-07-05_17-11-48" src="https://github.com/user-attachments/assets/c29ac4e3-7e4d-4483-b23e-dc0350f759e9" />


MAD Event Mailer is a WordPress plugin built for event notification emails. It provides SMTP delivery, reusable HTML email templates, template variables, CSV recipient import/export, event-based subscription lists, shortcode-based public subscription forms, batch sending, scheduled sending, and basic bilingual interface support.

The plugin was created for event operation scenarios such as submission notices, review results, score notifications, schedule updates, and general announcement emails.

## Project Information

- **Plugin name:** MAD Event Mailer
- **Author:** [MAD Producer Studio](https://github.com/MAD-Producer)
- **License:** GPL v2
- **Text domain:** `mad-event-mailer`
- **Current version:** 2.4.4
- **Shortcode:** `[madevma_email_register]`

## Main Features

### SMTP Email Delivery

The plugin allows WordPress to send HTML emails through a custom SMTP server. You can configure:

- SMTP host
- SSL/TLS protocol
- SMTP port
- SMTP account
- SMTP password
- sender email address
- sender name
- reply-to address
- batch sending quantity
- seconds between background emails

This is useful when the default WordPress email system is unreliable or when you need to send from a dedicated event mailbox.

### HTML Email Templates

MAD Event Mailer supports reusable HTML templates. You can:

- use the built-in Chinese template
- use the built-in English template
- upload your own HTML template
- paste HTML directly in the template editor
- preview templates in the admin panel
- create a new template based on a common layout

The built-in common templates are protected and cannot be deleted.

### Template Variables

Variables use double curly braces:

```text
{{variable_name}}
```

Variable names may contain letters, numbers, and underscores.

Common built-in variables:

| Variable | Meaning |
| --- | --- |
| `{{title}}` / `{{title1}}` | Email subject or title field |
| `{{name}}` / `{{name1}}` | Recipient name |
| `{{email}}` | Recipient email address |
| `{{message}}` / `{{message1}}` | Main body content slot |
| `{{unsubscribe_url}}` | Subscription management page URL |

Custom variables can be used in the email body. For example:

```text
Your final score is {{score}}.
Your rank is {{rank}}.
Comment: {{comment}}
```

If you upload a CSV file containing `score`, `rank`, and `comment` columns, each recipient can receive personalized values.

### Body Slot Editing

The common HTML template can stay unchanged while the email body is edited separately through the WordPress rich text editor.

For example, the template may contain:

```html
<div class="personal-message">{{message1}}</div>
```

When sending an email, you only edit the `message1` body content instead of changing the whole HTML template every time.

### CSV Recipient Template Export

The plugin can export a CSV recipient template based on the selected email template and the variables used in the body content.

Example CSV format:

```csv
email,name,events,score,rank,comment
john@example.com,John,IFT IC #6,95,2,Good work
```

Required fields:

- `email`
- `name`

Optional fields:

- `events`
- `template`
- any custom variables used in the selected template or body content

### Online Recipient Editor

The Subscribers screen provides a separate Recipient Data Tools section alongside recipient import/export. Its row-by-row editor is not part of the single-recipient form. Each row must be bound to one specific email template and can be assigned to one or more event-language recipient lists. Selecting a template automatically detects its editable variables and creates ordinary fields for that row; no JSON editing is required. The bound template and values are merged into the campaign before rendering.

Clearly promotional advertising rows are rejected by the recipient filter during public subscription, manual entry, CSV import, online editing, campaign preparation, and sending. Existing rows can be cleaned from the Subscribers screen.

### Event-Based Subscription Lists

The plugin includes event category management. Admins can create and delete event categories, and recipients can subscribe to selected events.

This makes it suitable for activity-based email notifications, for example:

- contest notifications
- submission review results
- schedule updates
- award announcements
- community event notices

### Public Subscription Form

Create a WordPress page and insert this shortcode:

```text
[madevma_email_register]
```

The generated public form supports:

- subscribing to event notifications
- checking existing subscription status
- unsubscribing from all notifications

The subscription logic is additive: when a user subscribes again, newly selected categories are added, while previous categories are not removed.

Unsubscribe means unsubscribing from all event notifications.

### Subscription Management / Unsubscribe Button

The plugin can automatically add a subscription management button to the bottom of outgoing emails.

The button can be configured in:

- global SMTP settings
- individual sending task settings

Supported button languages:

- Chinese
- English

### Batch Sending and Scheduled Sending

The plugin supports background batch sending to reduce server pressure. You can configure the maximum batch size and the number of seconds between emails.

Event campaigns require an explicit event-language list. The send form no longer falls back to all subscribers when the list is missing or invalid. Sending to every subscribed recipient remains available only as an explicit, confirmed option. If WordPress or the SMTP transport rejects a message, the campaign log stores the returned error and a campaign with zero accepted messages is marked Failed rather than Finished.

Campaigns can be:

- sent immediately
- scheduled for a future time
- saved as drafts
- reused from previous campaign settings
- cancelled while queued, scheduled, or sending

Campaigns are queued instead of sending inside the admin request. A WordPress Cron worker sends one email per interval when a delay is configured, checks the campaign status before every recipient, and stops pending recipients when the campaign is cancelled. The worker still depends on WordPress Cron, so execution time may depend on site traffic and the site's Cron configuration.

### Email Branding Images

The SMTP Settings page provides separate URL fields for the email logo and footer icon. Built-in templates reference these values through `{{logo_url}}` and `{{icon_url}}`, so the plugin does not ship with hard-coded remote image dependencies. Leaving either field blank hides the corresponding image.

The administration and public subscription interfaces use English source strings and standard WordPress gettext functions with the `mad-event-mailer` text domain. Community translations are managed through translate.wordpress.org; translation files are not bundled in the release package.

## Installation

### Install from ZIP

1. Download the plugin ZIP file.
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin.
3. Upload the ZIP file.
4. Activate the plugin.
5. Go to **MAD Mail** in the WordPress admin menu.
6. Configure SMTP settings before sending emails.

### Install from Source

Copy the plugin folder to:

```text
wp-content/plugins/mad-event-mailer/
```

Then activate it from the WordPress admin plugin page.

## Recommended Setup

1. Go to **SMTP Settings** and configure SMTP.
2. Create a subscription management page with:

   ```text
   [madevma_email_register]
   ```

3. Paste the page URL into the plugin settings.
4. Create event categories.
5. Add recipients manually, import recipients from CSV, or let users subscribe from the public form.
6. Create or select an email template.
7. Write the email body.
8. Export a CSV template if personalized variables are needed.
9. Send a test email first.
10. Create a sending task.

## Template Writing Rules

### Basic variable format

```text
{{variable_name}}
```

Correct examples:

```text
{{name1}}
{{message1}}
{{score}}
{{rank}}
{{comment}}
```

Avoid spaces inside variable names:

```text
{{ score }}     # not recommended
{{user name}}  # not recommended
```

### Recommended common template structure

```html
<h1>{{title1}}</h1>
<p>Dear {{name1}},</p>
<div>{{message1}}</div>
```

### Personalized body example

```text
Dear {{name1}},

Your score for IFT IC #6 is {{score}}.
Your ranking is {{rank}}.

Comment:
{{comment}}
```

CSV example:

```csv
email,name,events,score,rank,comment
john@example.com,John,IFT IC #6,95,2,Excellent work
jane@example.com,Jane,IFT IC #6,88,5,Good structure
```

## Database Tables

The plugin creates several custom WordPress database tables using the WordPress table prefix.

Typical tables include:

- templates
- events
- subscribers
- subscriber-event relations
- campaigns
- campaign recipients

Table names may vary depending on the WordPress database prefix.

## Notes and Limitations

- Scheduled sending depends on WordPress Cron.
- Bulk email delivery may be limited by your SMTP provider.
- Always test with a small recipient list before sending a large campaign.
- The plugin is intended for event and community notification use cases, not for spam or unsolicited marketing.
- For better deliverability, configure SPF, DKIM, and DMARC for the sender domain.

## Release Notes

### 2.4.4

- Prevented event campaigns from silently falling back to all subscribers when an event-language list is missing or invalid.
- Made all-recipient delivery an explicit confirmation-only option and display the selected recipient source in campaign history.
- Stored the WordPress mail transport error for failed deliveries and marked campaigns with zero accepted messages as Failed.
- Moved the strictly template-bound online recipient editor into the Recipient Data Tools section alongside import/export, and made new rows require an explicit template selection.

### 2.4.3

- Moved campaign delivery into a background WP-Cron worker so creating a campaign does not block the admin request.
- Added configurable per-email sending intervals and a safe batch limit to reduce server pressure.
- Added campaign cancellation for queued, scheduled, and active campaigns; pending recipients are marked cancelled and are not sent.
- Added a worker lock and cancellation checks to prevent overlapping campaign workers from continuing after cancellation.

### 2.4.2

- Inlines template CSS before delivery so Gmail and other clients that remove style blocks retain the email layout.
- Explicitly configures outgoing messages as UTF-8 HTML through PHPMailer.
- Restricts Quick Create to the two built-in Chinese and English general templates so previously created content cannot be inherited accidentally.

### 2.4.1

- Replaced online recipient JSON editing with template-driven variable fields.
- Made an email template mandatory for every online recipient row.
- Automatically detects editable variables from the selected template and rebuilds the row fields when the template changes.
- Reports online rows skipped because their template binding or recipient data is invalid.

### 2.4.0

- Updated WordPress.org metadata to `Tested up to: 7.1`.
- Added obvious-advertisement recipient filtering across recipient intake and delivery.
- Added online recipient row editing with event-language grouping and per-recipient template binding.
- Added per-recipient template variables and synchronized campaign delivery with the bound template.
- Added the `template` column to recipient CSV templates and exports.

### 2.3.1

- Converted the administration and public subscription interfaces to English source strings.
- Replaced the custom translation helper with WordPress gettext functions using the `mad-event-mailer` text domain.
- Localized dynamic JavaScript messages through WordPress and changed all JavaScript fallback text to English.
- Migrated built-in template names to English while preserving compatibility with existing 2.3.0 data.
- Corrected WordPress.org directory tags and short-description length.

### 2.3.0

- Added Logo URL and Icon URL settings and replaced hard-coded template image addresses with `{{logo_url}}` and `{{icon_url}}`.
- Migrated public hooks, options, roles, capabilities, shortcodes, menu slugs, database tables, form fields, and asset selectors to the unique `madevma` prefix.
- Added an automatic one-time migration for existing plugin data and shortcode pages.
- Removed bundled translation files for WordPress.org compatibility and kept Chinese as the default interface.
- Updated the WordPress.org and GitHub release package structure.

### 2.2.4

- Fixed outgoing email sender name so SMTP sender settings are applied consistently.
- Subscription and unsubscribe confirmation emails now use the common HTML email template.

### 2.2.2

This release continues the 2.2.1 GitHub release metadata updates and adds the following changes:

- Improved English translations across admin, campaign, template, subscriber, preview, and JavaScript status text.
- Changed the default admin interface and public subscription page language settings to follow the WordPress site language.

### 2.2.1

- Updated plugin metadata for GitHub release.
- Added GPL v2 license header.
- Updated author to MAD Producer Studio with GitHub author URL.
- Added English README and Chinese README.

## License

This project is licensed under **GPL v2**.

See [LICENSE](./LICENSE) for details.

## Author

Created and maintained by [MAD Producer Studio](https://github.com/MAD-Producer).
