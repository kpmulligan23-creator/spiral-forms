# Spiral Forms

A lightweight WordPress forms plugin built to replace WPForms on Kevin's sites.

## What's in v0.1 (Phase 1)

- **Form builder** (Spiral Forms → Add New): add, edit, reorder (drag or arrows), duplicate, and remove fields
- **Fields:** Name, Email, Phone, Single-line text, Paragraph, Dropdown, Checkboxes, Multiple choice, Number (min/max), Date
- **Embed:** shortcode `[spiral_form id="123"]` or the **Spiral Form** block in the editor
- **Validation:** required fields plus type checks (email, phone, number range, real dates, allowed choices), all checked on the server
- **Email notifications:** as many as you want per form (admin and autoresponder are set up by default), HTML emails, smart tags
- **Smart tags:** `{field:ID}`, `{all_fields}`, `{form_name}`, `{site_name}`, `{site_url}`, `{admin_email}`, `{page_url}`, `{entry_id}`, `{date}`, `{user_ip}`
- **Confirmation:** show a message (smart tags work) or redirect to a URL. Uses Post/Redirect/Get, so refreshing the page doesn't send the form twice
- **Entries:** saved to the `wp_spiral_forms_entries` table. The admin list has filter, search, unread badges, bulk read/unread/delete, and a single-entry view
- **CSV export** for each form (Excel-friendly UTF-8, protected against formula injection)
- **Spam:** honeypot plus signed minimum-fill-time check, always on. Optional **Cloudflare Turnstile** or **reCAPTCHA v2** (Settings)
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

- **Phase 2:** better drag-and-drop builder with live preview, conditional logic, file uploads, multi-page forms, **WPForms importer** (convert existing `wpforms` posts automatically)
- **Phase 3:** webhooks, Mailchimp/email lists, Stripe, others as needed

**Open question:** which WPForms features are used across the ~40 sites (payments, uploads, conditional logic, integrations)? The answer decides what moves into Phase 1.

## Layout

```
spiral-forms.php          bootstrap
includes/                 core: forms (CPT), entries (DB), renderer, submission, smart tags, spam, block, updater
admin/                    builder, forms list, entries list/export, settings
assets/                   front-end + admin CSS/JS (no build step)
blocks/form/              Gutenberg block (server-rendered)
.github/workflows/        release build
```
