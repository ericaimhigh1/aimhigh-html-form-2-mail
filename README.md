=== CCustom Form 2 Mail (CF2M) ===
Contributors: ericaimhigh
Tags: coupon, gutenberg, block, affiliate, discount
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

# 

**CF2M** is a WordPress plugin that exposes a **configurable public URL** where visitors can `POST` a plain HTML form. Submissions are **sanitized**, **never stored in the database**, and sent to a recipient as an **HTML email** using your **template** (admin HTML and/or files under `templates/`).

Use cases include Elementor “HTML” widgets, block editor Custom HTML, or any theme template where you control the form markup.

---

## Requirements

- **WordPress** 6.0 or higher (see plugin header).
- **PHP** 7.4 or higher (see plugin header).
- Pretty permalinks (or equivalent) so the custom rewrite endpoint resolves. After install or endpoint changes, flush permalinks once (see [Installation](#installation)).

---

## Installation

1. Copy the `cf2m` folder into `wp-content/plugins/`.
2. In **Plugins**, activate **Custom Form 2 Mail**.
3. Go to **Settings → Permalinks** and click **Save Changes** (no need to edit anything). This registers the form endpoint with WordPress.
4. Open **Settings → CF2M** and configure the endpoint, recipient, and optional custom HTML template.

---

## Quick start (site owners)

1. **Settings → CF2M**  
   Note the URL shown at the top (e.g. `https://example.com/cf2m/`). That is your form **action**.
2. Build a form with `method="post"` and `action` pointing to that URL (trailing slash is fine).
3. Use `name` attributes on inputs; those names drive **`{{placeholder}}`** replacement in email templates.
4. Add the **nonce** and **honeypot** fields (recommended; see [Special form fields](#special-form-fields)).
5. Submit the form. The browser receives a **JSON** response (see [HTTP response](#http-response)).

---

## Settings (Settings → CF2M)

| Setting | Option key | Description |
|--------|-------------|-------------|
| **Form endpoint path** | `cf2m_endpoint` | URL slug only (no slashes), e.g. `cf2m` or `submit`. Allowed characters: letters, numbers, `_`, `-`. Default: `cf2m`. Changing this triggers a rewrite flush. |
| **Recipient email** | `cf2m_recipient_email` | Address that receives submissions. If empty or invalid, the **WordPress admin email** is used. |
| **Custom email HTML** | `cf2m_email_template` | Optional full HTML for the email body. Sanitized with `wp_kses_post`. See [Template resolution](#template-resolution-order) and [Placeholders](#placeholders). |

The settings page also shows the exact **form action URL** for your site.

---

## Special form fields

These `name` values are reserved; they are **not** included in the emailed field list as regular data (except where noted).

| Field name | Purpose |
|------------|--------|
| `cf2m_nonce` | CSRF token from `[cf2m_nonce_field]` shortcode (or equivalent `wp_nonce_field`). If **present**, it **must** be valid. If **omitted**, the request is still accepted (weaker security). |
| `cf2m_hp` | Honeypot: must stay **empty**. If filled, the request is rejected as spam-like. |
| `template_name` | Chooses a **file** template when custom HTML in settings is **empty**: loads `templates/{template_name}.html`, then falls back to `default` / `Default.html`. |

**Shortcode for nonce (in a post/page):**

```text
[cf2m_nonce_field]
```

Renders the hidden fields WordPress needs for `cf2m_submit_form`.

**Honeypot example (hide from users, keep empty):**

```html
<input type="text" name="cf2m_hp" value="" style="display:none !important;" tabindex="-1" autocomplete="off" />
```

---

## Placeholders

Placeholders are resolved in **email templates** only (custom HTML in settings and/or `templates/*.html`). The plain HTML table fallback does not interpret these tokens (except it does not use templates at all).

### 1. Form field placeholders — `{{placeholder}}`

Syntax: double curly braces, optional spaces inside braces.

- **Pattern:** `{{field_name}}`  
- **Meaning:** Replaced with the **sanitized** value of the submitted field whose `name` matches, after WordPress **`sanitize_key()`** rules (lowercase, only `a-z`, `0-9`, `_`, `-`).

**Examples:**

- Form: `<input name="first_name" />` → use `{{first_name}}`
- Form: `<input name="availability-date" />` → use `{{availability-date}}` (hyphen allowed in placeholder token)

If the field was **not** submitted or is empty after sanitization, the placeholder is replaced with **`N/A`**.

**Important:** HTML in field values is **not** interpreted as HTML in the email; values are output with `esc_html()` for safety.

### 2. Built-in “merge” placeholders — same `{{ }}` syntax

These are **not** taken from the form; they are injected by the plugin:

| Placeholder | Replaced with |
|-------------|----------------|
| `{{siteurl}}` | Site home URL (trailing slash), via `home_url('/')`. |
| `{{logourl}}` | Logo URL from theme/custom logo, fallbacks, or `https://yoursite.com/logo.png` if nothing else is found. |

### 3. Static literals — `[timestamp]` and `[ip]`

These use **square brackets** (not curly braces):

| Token | Replaced with |
|-------|----------------|
| `[timestamp]` | Current date/time using **Settings → General** date format, time format, and timezone (`wp_date()`). |
| `[ip]` | Client IP from `HTTP_CLIENT_IP`, `X-Forwarded-For` (first hop), or `REMOTE_ADDR`. If unknown: `N/A`. |

Replaced after `{{ }}` processing; values are escaped for HTML.

---

## Template resolution order

The email body is built in this order:

1. **Custom email HTML** (Settings → CF2M)  
   If the textarea is **not** empty (after trim), it is used for **every** submission. File templates are ignored in this case.

2. **File templates** (only when custom HTML is empty)  
   - `wp-content/plugins/cf2m/templates/{template_name}.html`  
     where `template_name` comes from the POST field `template_name` (default `default`).  
   - If that file is missing, **`templates/default.html`**, then **`templates/Default.html`** (for case-sensitive servers).

3. **Fallback**  
   If no template file exists, a simple **HTML table** of all submitted fields (keys and values) is sent.

---

## Example HTML form

```html
<form method="post" action="https://example.com/cf2m/">
  <!-- If you use the shortcode on a page, paste the rendered nonce fields here instead -->
  <input type="hidden" name="cf2m_nonce" value="..." />
  <input type="hidden" name="_wp_http_referer" value="..." />

  <input type="text" name="cf2m_hp" value="" style="display:none !important;" tabindex="-1" autocomplete="off" />

  <input type="hidden" name="template_name" value="default" />

  <label>First name <input type="text" name="first_name" required /></label>
  <label>Email <input type="email" name="email" required /></label>
  <button type="submit">Send</button>
</form>
```

Point `action` at your real endpoint from **Settings → CF2M**.

---

## HTTP response

Successful and error responses use **`wp_send_json()`**: JSON body and appropriate HTTP status code.

Typical shape:

```json
{
  "success": true,
  "message": "Form submitted successfully."
}
```

Common status codes: `200` success, `400` honeypot/invalid, `403` origin or nonce failure, `405` not POST, `429` rate limit, `500` mail failure.

**Note:** Normal HTML form navigation will show JSON in the browser. For a smooth UX, submit via **JavaScript** (`fetch` / `XMLHttpRequest`) and handle the JSON, or add your own redirect flow later.

---

## Security behavior (summary)

- **POST only** at the endpoint.
- **Same-origin** check using `Origin` / `Referer` host vs site host when those headers are present; if both are missing, the request may still be allowed (defense in depth elsewhere).
- **Optional nonce** when `cf2m_nonce` is posted.
- **Honeypot** `cf2m_hp`.
- **Per-IP rate limit:** 5 submissions per minute (transient-based).
- **Input sanitization** by field name heuristics (`email`, `url`/`website`, `message`/`comment`, etc.) and `sanitize_key` for names.
- **No** submission rows written to the WordPress database.

---

## Email delivery

- **Transport:** WordPress `wp_mail()` (respects SMTP plugins if configured).
- **Format:** HTML (`Content-Type: text/html; charset=UTF-8`).
- **Subject:** Localized string `[CF2M] New form submission` (filterable in code if you extend the plugin).

---

## Troubleshooting

| Issue | What to try |
|-------|-------------|
| 404 on form URL | **Settings → Permalinks → Save**. Re-save **Settings → CF2M** after changing the slug. |
| JSON instead of a “thank you” page | Expected for a raw form POST; use AJAX or a separate thank-you page pattern. |
| `{{my_field}}` always `N/A` | Check the input `name` matches after `sanitize_key` (lowercase; odd characters stripped). |
| Email not received | Check spam folder, `wp_mail` / SMTP plugins, and server mail logs. |
| `403 Origin check failed` | Form must be submitted from the same site (or headers must allow the check). |

---

## For contributors

### Repository layout

```
cf2m/
├── cf2m.php          # Main plugin bootstrap + CF2M_Plugin class
├── templates/        # Optional .html file templates (e.g. default.html)
└── README.md         # This file
```

### Architecture

- **Single class:** `CF2M_Plugin` (final) in `cf2m.php`.
- **Rewrite:** `add_rewrite_rule()` maps `^{endpoint}/?$` → `index.php?cf2m_action=submit`.
- **Query var:** `cf2m_action` registered via `query_vars`; value `submit` triggers the handler.
- **Handler:** `template_redirect` → `maybe_handle_submission()`.
- **Admin:** `options.php` group `cf2m_settings`; menu under **Settings → CF2M**.

### Design choices worth preserving

1. **No DB writes** for form payloads—privacy and simplicity.
2. **Strict file reads** under `templates/` using `realpath()` and basename allowlists.
3. **Template rendering** separates `{{ }}` (escaped values) from `[timestamp]` / `[ip]` (literal tokens).
4. **Options:** use `register_setting` + sanitize callbacks; endpoint changes hook `update_option_cf2m_endpoint` to flush rewrites.

### How to extend safely

- Prefer **`wp_mail` filters** (e.g. `wp_mail`, `wp_mail_from`) for from-address or headers rather than editing core flow unless necessary.
- New **placeholders:** extend `normalize_template_data()` and/or `apply_builtin_template_tokens()`; document them in this README.
- **i18n:** strings use `__()` / `esc_html__()` with text domain `cf2m`; add a `languages/` catalog if you ship translations.

### Coding standards

- Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) where practical.
- Keep changes focused; avoid unrelated refactors in the same PR as a feature fix.

### Pull requests

1. Describe behavior change and testing steps (WP version, PHP version).
2. Confirm permalinks / endpoint still work after your change.
3. If you add settings, update this README and the settings screen help text.

---

## License

This plugin is licensed under the **GPL-2.0-or-later** (see plugin header and `License` URI in `cf2m.php`).

---

## Credits

Maintained by **Eric Aimhigh** and contributors. Issues and PRs welcome on the plugin’s GitHub repository (see **Plugin URI** in `cf2m.php`).
