=== Aimhigh HTML Form 2 Mail ===
Contributors: ericaimhigh
Donate link: https://github.com/ericaimhigh1/aimhigh-html-form-2-mail
Tags: forms, mail, contact form, static sites, headless
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Aimhigh Form Mail accepts public HTML form POSTs at a configurable URL and emails sanitized submissions using file or admin-defined HTML templates.

== Description ==

AHFM is a lightweight WordPress plugin that exposes a configurable public URL where visitors can POST a plain HTML form. Submissions are sanitized, never stored in the database, and sent to a recipient as an HTML email.

Use cases include Elementor “HTML” widgets, block editor Custom HTML, or any theme template where you control the form markup.

== Installation ==

1. Copy the `ahfm` folder into `wp-content/plugins/`.
2. In Plugins, activate Aimhigh HTML Form Mail.
3. Go to **Settings <span aria-hidden="true" class="wp-exclude-emoji"><span aria-hidden="true" class="wp-exclude-emoji">→</span></span> Permalinks** and click **Save Changes**.
4. Open **Settings <span aria-hidden="true" class="wp-exclude-emoji"><span aria-hidden="true" class="wp-exclude-emoji">→</span></span> Aimhigh Form Mail** and configure your endpoint.

== Frequently Asked Questions ==

= Does this save data to the database? =
No. For privacy and simplicity, submissions are emailed immediately and then discarded.

= Can I use custom templates? =
Yes. You can use the HTML editor in the settings or place `.html` files in the plugin's `/templates` directory.

== Screenshots ==

1. The settings page where you configure your custom endpoint and email recipient.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Rebranded to Aimhigh HTML Form 2 Mail.
* Added configurable endpoints and rate limiting.

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade necessary.