# Spiral Forms

A lightweight WordPress forms plugin built to replace WPForms on Kevin's sites.

## Features (v0.3)

- **Form builder** (Spiral Forms → Add New): add, edit, reorder (drag or arrows), duplicate, and remove fields
- **Fields:** Name (one box, or first + last), Email, Phone, Single-line text, Paragraph, Dropdown, Checkboxes, Multiple choice, Number (min/max), Date, US Address, File Upload
- **File uploads:** set the allowed types, size per file and number of files for each field. Files are **private**: they're stored under `uploads/spiral-forms/` in random folders with web access denied, and only a logged-in admin can download them (links appear in the entry and in the email). Each notification can optionally attach the files (20 MB total). Script and web file types are always blocked, and file contents are checked against the extension. Deleting an entry deletes its files. On Nginx hosts, the `.htaccess` block doesn't apply, but the folder names still can't be guessed
- **Show/hide rules:** show or hide a field when other fields match (is, is not, contains, empty, not empty; all or any). Hidden fields are skipped by validation and left out of entries and emails
- **No page reload on submit** (AJAX), with inline errors. Without JavaScript, the form posts normally
- **Embed:** shortcode `[spiral_form id="123"]` or the **Spiral Form** block in the editor
- **Validation:** required fields plus type checks (email, phone, number range, real dates, allowed choices), all checked on the server
- **Email notifications:** as many as you want per form (admin and autoresponder are set up by default), HTML emails, smart tags
- **Smart tags:** `{field:ID}`, `{all_fields}`, `{form_name}`, `{site_name}`, `{site_url}`, `{admin_email}`, `{page_url}`, `{entry_id}`, `{date}`, `{user_ip}`
- **Confirmation:** show a message (smart tags work) or redirect to a URL. Uses Post/Redirect/Get, so refreshing the page doesn't send the form twice
- **Entries:** saved to the `wp_spiral_forms_entries` table. The admin list has filter, search, unread badges, bulk actions and a single-entry view. An optional per-form setting deletes entries after N days
- **Spam folder:** blocked submissions (honeypot, too-fast, low reCAPTCHA v3 score) are kept for 30 days under Entries → Spam, so a real lead flagged by mistake can be recovered with "Not spam". Nothing is emailed for spam
- **CSV export** for each form (Excel-friendly UTF-8, protected against formula injection)
- **Spam:** honeypot plus signed minimum-fill-time check, always on. Optional **Cloudflare Turnstile**, **reCAPTCHA v2** (checkbox) or **reCAPTCHA v3** (invisible, with a score threshold), set in Settings
- **WPForms importer** (Spiral Forms → Import), covered below
- **Self-hosted updates** from this private repo's GitHub Releases

There are no nonces on front-end forms, on purpose. Nonces expire on cached pages and cause "failed" submissions. Spam protection comes from the signed timestamp, the honeypot and the CAPTCHA instead.

## Installing on a site

1. Download `spiral-forms.zip` from the latest [Release](https://github.com/kpmulligan23-creator/spiral-forms/releases).
2. Go to Plugins → Add New → Upload, then activate.
3. Go to **Spiral Forms → Settings**:
   - Paste a GitHub token so the site can get updates (see below).
   - Optional: choose Turnstile and add its site and secret keys.

### GitHub token for updates

Create a **fine-grained personal access token** at GitHub → Settings → Developer settings:
- Repository access: **only** `kpmulligan23-creator/spiral-forms`
- Permissions: **Contents: Read-only** (Metadata read-only is added automatically)

You can paste it in Settings, or keep it out of the database by adding this to `wp-config.php`:

```php
define( 'SPIRAL_FORMS_GITHUB_TOKEN', 'github_pat_...' );
```

You can use one token on every site, or a separate token per site so you can revoke one site's access on its own.

## Releasing an update

1. Change the version in `spiral-forms.php` in **both** places: the `Version:` header and `SPIRAL_FORMS_VERSION`.
2. Commit, then tag and push:
   ```bash
   git tag v0.2.0 && git push origin v0.2.0
   ```
3. The **Build release** GitHub Action checks that the versions match, lints the PHP, builds `spiral-forms.zip`, and publishes a Release.
4. Sites show the update in Dashboard → Updates within about 6 hours. Spiral Forms → Settings → "Check now" checks right away.

## Moving a site off WPForms

On the site, with both plugins active:

1. **Spiral Forms → Import.** Every WPForms form is listed with its entry count, the pages it's used on, and notes on anything that can't be converted.
2. **Import form + entries.** This creates the Spiral form and copies its entries, notifications, confirmation, show/hide rules and retention setting. It also copies the WPForms CAPTCHA keys if Spiral Forms has none yet. WPForms itself isn't changed.
3. **Test** the new form on a draft page using the shortcode shown on the Import screen. Check that the notification email arrives.
4. **Swap embeds.** This replaces `[wpforms id="…"]` and the WPForms block in posts and pages, including Avada elements, with the Spiral form. A revision is saved for each page, so you can undo. Purge LiteSpeed or other page caches afterwards.
5. **Import new entries.** This picks up anything submitted to WPForms since the first import. Then deactivate WPForms.

Not converted yet (shown as notes on the Import screen): CSV email attachments, conditional notifications, multi-page forms, integrations (Constant Contact, Brevo…), invisible reCAPTCHA v2.

## Developer hooks

| Hook | Type | Use |
|---|---|---|
| `spiral_forms_submission_complete` | action `( $form, $data, $entry_id, $context )` | Webhooks, CRM, Mailchimp (Phase 3) |
| `spiral_forms_validation_errors` | filter `( $errors, $form, $data )` | Custom validation |
| `spiral_forms_email_args` | filter `( $args, $notification, $form, $context )` | Change outgoing emails |
| `spiral_forms_field_types` | filter | Register field types |
| `spiral_forms_client_ip` | filter | Change how the IP is detected |

Styling: override the CSS variables `--sf-accent`, `--sf-border`, `--sf-error` and `--sf-radius` on `.sf-wrap`, or turn off the default styles in Settings.

## Data & uninstall

Deleting the plugin **keeps** forms and entries, so a reinstall loses nothing. To wipe all data when deleting, add `define( 'SPIRAL_FORMS_REMOVE_DATA', true );` to `wp-config.php` first.

## Roadmap

- **Next:** Avada (Fusion) forms importer, test on spiral.tech, then switch stonelawfl.com over
- **Phase 2:** better drag-and-drop builder with live preview, multi-page forms, richer show/hide rules
- **Phase 3:** webhooks, Mailchimp/email lists, Stripe, others as needed

**Findings so far (Nov 2026):** garylawgroup.com and stonelawfl.com use WPForms Pro for simple contact and intake forms. They use name (first/last), email, phone, address, radio, text, date, a single show/hide rule, reCAPTCHA (Stone uses v3), AJAX submit and 365-day retention. Spam is heavy: on Stone it outnumbers real entries 5 to 1. spiral.tech uses Avada forms, including a file upload.

## Layout

```
spiral-forms.php          bootstrap
includes/                 core: forms (CPT), entries (DB), renderer, submission, smart tags, spam, block, updater
admin/                    builder, forms list, entries list/export, settings
assets/                   front-end + admin CSS/JS (no build step)
blocks/form/              Gutenberg block (server-rendered)
.github/workflows/        release build
```
