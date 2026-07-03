# MAD Event Mailer

[中文文档 / Chinese README](./README.zh-CN.md)

MAD Event Mailer is a WordPress plugin built for event notification emails. It provides SMTP delivery, reusable HTML email templates, template variables, CSV recipient import/export, event-based subscription lists, shortcode-based public subscription forms, batch sending, scheduled sending, and basic bilingual interface support.

The plugin was created for event operation scenarios such as submission notices, review results, score notifications, schedule updates, and general announcement emails.

## Project Information

- **Plugin name:** MAD Event Mailer
- **Author:** [MAD Producer Studio](https://github.com/MAD-Producer)
- **License:** GPL v2
- **Text domain:** `mad-event-mailer`
- **Current version:** 2.2.2
- **Shortcode:** `[mad_email_register]`

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
- any custom variables used in the selected template or body content

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
[mad_email_register]
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

The plugin supports batch sending to reduce server pressure. You can configure the number of emails sent per batch.

Campaigns can be:

- sent immediately
- scheduled for a future time
- saved as drafts
- reused from previous campaign settings

The scheduled sending system uses WordPress Cron, so real execution time may depend on site traffic and WordPress Cron behavior.

### Language Pack

The plugin includes an English language pack:

```text
languages/en_US.php
```

You can configure:

- admin interface language
- public subscription page language

Available options:

- Chinese
- English
- follow WordPress site language

Email content language is controlled by the selected email template and the text you write in the editor.

## Installation

### Install from ZIP

1. Download the plugin ZIP file.
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin.
3. Upload the ZIP file.
4. Activate the plugin.
5. Go to **MAD Mail / MAD 邮件** in the WordPress admin menu.
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
   [mad_email_register]
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
