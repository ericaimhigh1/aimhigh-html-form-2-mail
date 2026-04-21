<?php
/**
 * Plugin Name:       Custom Form 2 Mail
 * Plugin URI:        https://github.com/ericaimhigh1/custom-form-2-mail
 * Description:       Accepts public HTML form POSTs at a configurable URL and emails sanitized submissions using  admin-defined HTML template.
 * Version:           1.0.0
 * Author:            Eric Aimhigh
 * Author URI:        https://x.com/ericaimhigh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cf2m
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if (!defined('ABSPATH')) {
	exit;
}

final class CF2M_Plugin {
	private const QUERY_VAR        = 'cf2m_action';
	private const QUERY_VALUE      = 'submit';
	private const NONCE_ACTION     = 'cf2m_submit_form';
	private const NONCE_FIELD      = 'cf2m_nonce';
	private const HONEYPOT_FIELD   = 'cf2m_hp';
	private const TEMPLATE_FIELD   = 'template_name';
	private const DEFAULT_TEMPLATE = 'default';

	private const OPTION_ENDPOINT         = 'cf2m_endpoint';
	private const OPTION_RECIPIENT_EMAIL  = 'cf2m_recipient_email';
	private const OPTION_EMAIL_TEMPLATE   = 'cf2m_email_template';

	public static function init(): void {
		add_action('init', [__CLASS__, 'add_rewrite_rule'], 10, 0);
		add_filter('query_vars', [__CLASS__, 'register_query_var']);
		add_action('template_redirect', [__CLASS__, 'maybe_handle_submission']);

		add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		add_action('update_option_' . self::OPTION_ENDPOINT, [__CLASS__, 'on_endpoint_changed'], 10, 3);

		add_shortcode('cf2m_nonce_field', [__CLASS__, 'shortcode_nonce_field']);
	}

	public static function activate(): void {
		add_option(self::OPTION_ENDPOINT, 'cf2m');
		add_option(self::OPTION_RECIPIENT_EMAIL, '');
		add_option(self::OPTION_EMAIL_TEMPLATE, '');
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function on_endpoint_changed($old_value, $value, $option): void {
		if ((string) $old_value !== (string) $value) {
			flush_rewrite_rules(false);
		}
	}

	public static function get_endpoint_slug(): string {
		$slug = get_option(self::OPTION_ENDPOINT, 'cf2m');
		return is_string($slug) && $slug !== '' ? $slug : 'cf2m';
	}

	public static function add_rewrite_rule(): void {
		$slug = preg_replace('/[^a-z0-9_-]+/i', '', self::get_endpoint_slug());
		if ($slug === '') {
			$slug = 'cf2m';
		}

		add_rewrite_rule(
			'^' . $slug . '/?$',
			'index.php?' . self::QUERY_VAR . '=' . self::QUERY_VALUE,
			'top'
		);
	}

	public static function register_query_var(array $vars): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function register_admin_menu(): void {
		add_options_page(
			__('CF2M', 'cf2m'),
			__('CF2M', 'cf2m'),
			'manage_options',
			'cf2m',
			[__CLASS__, 'render_settings_page']
		);
	}

	public static function register_settings(): void {
		register_setting(
			'cf2m_settings',
			self::OPTION_ENDPOINT,
			[
				'type'              => 'string',
				'sanitize_callback' => [__CLASS__, 'sanitize_endpoint_option'],
				'default'           => 'cf2m',
			]
		);

		register_setting(
			'cf2m_settings',
			self::OPTION_RECIPIENT_EMAIL,
			[
				'type'              => 'string',
				'sanitize_callback' => [__CLASS__, 'sanitize_recipient_email_option'],
				'default'           => '',
			]
		);

		register_setting(
			'cf2m_settings',
			self::OPTION_EMAIL_TEMPLATE,
			[
				'type'              => 'string',
				'sanitize_callback' => [__CLASS__, 'sanitize_email_template_option'],
				'default'           => '',
			]
		);
	}

	public static function sanitize_endpoint_option($value): string {
		$raw = is_string($value) ? wp_unslash($value) : '';
		$raw = trim($raw, " \t\n\r\0\x0B/");
		$slug = preg_replace('/[^a-z0-9_-]+/i', '', $raw);
		return $slug !== '' ? $slug : 'cf2m';
	}

	public static function sanitize_recipient_email_option($value): string {
		$raw = is_string($value) ? wp_unslash($value) : '';
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}
		$email = sanitize_email($raw);
		return is_email($email) ? $email : '';
	}

	public static function sanitize_email_template_option($value): string {
		if (!is_string($value)) {
			return '';
		}
		return wp_kses_post(wp_unslash($value));
	}

	public static function render_settings_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}

		$slug   = self::get_endpoint_slug();
		$url    = home_url('/' . rawurlencode($slug) . '/');
		$url    = esc_url($url);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('CF2M — Form to email', 'cf2m'); ?></h1>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: example form action URL */
						__('Point your form action to: %s', 'cf2m'),
						$url
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
				<?php settings_fields('cf2m_settings'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cf2m_endpoint"><?php esc_html_e('Form endpoint path', 'cf2m'); ?></label></th>
						<td>
							<input name="<?php echo esc_attr(self::OPTION_ENDPOINT); ?>" type="text" id="cf2m_endpoint" value="<?php echo esc_attr($slug); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e('Slug only, no slashes (e.g. submit or cf2m). Default: cf2m. When done, go to the Settings > permalinks and save for the endpoint to be activated.', 'cf2m'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cf2m_recipient_email"><?php esc_html_e('Recipient email', 'cf2m'); ?></label></th>
						<td>
							<input name="<?php echo esc_attr(self::OPTION_RECIPIENT_EMAIL); ?>" type="email" id="cf2m_recipient_email" value="<?php echo esc_attr((string) get_option(self::OPTION_RECIPIENT_EMAIL, '')); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" />
							<p class="description"><?php esc_html_e('Enter an email to receive email submissions of your custom form. Leave empty to use the WordPress admin email.', 'cf2m'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cf2m_email_template"><?php esc_html_e('Custom email HTML', 'cf2m'); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr(self::OPTION_EMAIL_TEMPLATE); ?>" id="cf2m_email_template" rows="14" class="large-text code"><?php echo esc_textarea((string) get_option(self::OPTION_EMAIL_TEMPLATE, '')); ?></textarea>
							<p class="description">
								<?php esc_html_e('If this is not empty, it is used first for every submission (supports {{field-name}} placeholders). If empty, the plugin uses templates/{name}.html from the form’s template_name, then templates/default.html (or Default.html), then a simple HTML table of fields.', 'cf2m'); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function shortcode_nonce_field(): string {
		return wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, true, false);
	}

	public static function maybe_handle_submission(): void {
		$action = get_query_var(self::QUERY_VAR);

		if ($action !== self::QUERY_VALUE) {
			return;
		}

		$request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD'])) : '';
		if ($request_method !== 'POST') {
			self::respond(405, __('Method not allowed. Use POST.', 'cf2m'));
		}

		if (!self::is_same_origin_request()) {
			self::respond(403, __('Origin check failed.', 'cf2m'));
		}

		$nonce = isset($_POST[self::NONCE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])) : '';
		if ($nonce !== '' && !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			self::respond(403, __('Security check failed.', 'cf2m'));
		}

		if (!self::allow_request_rate()) {
			self::respond(429, __('Too many submissions. Please try again later.', 'cf2m'));
		}

		$sanitized = self::sanitize_form_data($_POST);

		$honeypot = isset($_POST[self::HONEYPOT_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::HONEYPOT_FIELD])) : '';
		if ($honeypot !== '') {
			self::respond(400, __('Invalid submission.', 'cf2m'));
		}

		$template_name = isset($_POST[self::TEMPLATE_FIELD]) ? sanitize_key(wp_unslash((string) $_POST[self::TEMPLATE_FIELD])) : self::DEFAULT_TEMPLATE;
		if ($template_name === '') {
			$template_name = self::DEFAULT_TEMPLATE;
		}

		$custom_html = trim((string) get_option(self::OPTION_EMAIL_TEMPLATE, ''));
		if ($custom_html !== '') {
			$body = self::render_template($custom_html, $sanitized);
		} else {
			$template_contents = self::load_template($template_name);
			if ($template_contents === null && $template_name !== self::DEFAULT_TEMPLATE) {
				$template_contents = self::load_template(self::DEFAULT_TEMPLATE);
			}

			if ($template_contents !== null) {
				$body = self::render_template($template_contents, $sanitized);
			} else {
				$body = self::build_raw_html_submission_email($sanitized);
			}
		}

		$subject = __('[CF2M] New form submission', 'cf2m');
		$headers = ['Content-Type: text/html; charset=UTF-8'];

		$to = (string) get_option(self::OPTION_RECIPIENT_EMAIL, '');
		if ($to === '' || !is_email($to)) {
			$to = (string) get_option('admin_email');
		}

		$sent = wp_mail($to, $subject, $body, $headers);

		if (!$sent) {
			self::respond(500, __('Failed to send email.', 'cf2m'));
		}

		self::respond(200, __('Form submitted successfully.', 'cf2m'));
	}

	private static function build_raw_html_submission_email(array $sanitized): string {
		$rows = '';
		foreach ($sanitized as $key => $value) {
			$label = esc_html((string) $key);
			if (is_array($value)) {
				$cell = esc_html(implode(', ', array_map('strval', $value)));
			} else {
				$cell = nl2br(esc_html((string) $value));
			}
			$rows .= '<tr><th scope="row" style="text-align:left;vertical-align:top;padding:8px;border:1px solid #ccc;">' . $label . '</th>';
			$rows .= '<td style="padding:8px;border:1px solid #ccc;">' . $cell . '</td></tr>';
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="2" style="padding:8px;border:1px solid #ccc;">' . esc_html__('(No fields submitted.)', 'cf2m') . '</td></tr>';
		}

		$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
		$html .= '<p>' . esc_html__('New form submission (no template configured).', 'cf2m') . '</p>';
		$html .= '<table style="border-collapse:collapse;width:100%;max-width:640px;">' . $rows . '</table>';
		$html .= '</body></html>';

		return $html;
	}

	private static function sanitize_form_data(array $raw): array {
		$exclude_keys = [
			self::NONCE_FIELD,
			self::HONEYPOT_FIELD,
			self::TEMPLATE_FIELD,
			'_wp_http_referer',
			'_wpnonce',
			'action',
			self::QUERY_VAR,
		];

		$clean = [];

		foreach ($raw as $key => $value) {
			$key = sanitize_key((string) $key);

			if ($key === '' || in_array($key, $exclude_keys, true)) {
				continue;
			}

			$value       = wp_unslash($value);
			$clean[$key] = self::sanitize_value_by_key($key, $value);
		}

		return $clean;
	}

	private static function sanitize_value_by_key(string $key, $value) {
		if (is_array($value)) {
			return array_map(
				static fn($item) => is_scalar($item) ? sanitize_text_field((string) $item) : '',
				$value
			);
		}

		$value = (string) $value;

		if (strpos($key, 'email') !== false) {
			return sanitize_email($value);
		}

		if (strpos($key, 'url') !== false || strpos($key, 'website') !== false) {
			return esc_url_raw($value);
		}

		if (strpos($key, 'message') !== false || strpos($key, 'comment') !== false) {
			return sanitize_textarea_field($value);
		}

		return sanitize_text_field($value);
	}

	private static function is_same_origin_request(): bool {
		$site_host = wp_parse_url(home_url(), PHP_URL_HOST);

		$origin_raw = '';
		if (isset($_SERVER['HTTP_ORIGIN'])) {
			$origin_raw = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_ORIGIN']));
		}
		$origin_url = $origin_raw !== '' ? esc_url_raw($origin_raw) : '';
		$origin     = $origin_url !== '' ? wp_parse_url($origin_url, PHP_URL_HOST) : null;

		$referer_raw = '';
		if (isset($_SERVER['HTTP_REFERER'])) {
			$referer_raw = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_REFERER']));
		}
		$referer_url = $referer_raw !== '' ? esc_url_raw($referer_raw) : '';
		$referer     = $referer_url !== '' ? wp_parse_url($referer_url, PHP_URL_HOST) : null;

		if (!$site_host) {
			return false;
		}

		if (!$origin && !$referer) {
			return true;
		}

		if ($origin && hash_equals((string) $site_host, (string) $origin)) {
			return true;
		}

		if ($referer && hash_equals((string) $site_host, (string) $referer)) {
			return true;
		}

		return false;
	}

	private static function allow_request_rate(): bool {
		$ip = self::get_client_ip();
		if ($ip === '') {
			return true;
		}

		$key          = 'cf2m_rate_' . md5($ip);
		$max_requests = 5;
		$window       = MINUTE_IN_SECONDS;

		$count = (int) get_transient($key);
		if ($count >= $max_requests) {
			return false;
		}

		set_transient($key, $count + 1, $window);
		return true;
	}

	private static function load_template(string $template_name): ?string {
		$base_dir = plugin_dir_path(__FILE__) . 'templates/';
		if (!is_dir($base_dir)) {
			return null;
		}

		$base_real = realpath($base_dir);
		if ($base_real === false) {
			return null;
		}

		$filenames = [ $template_name . '.html' ];
		if (strtolower($template_name) === strtolower(self::DEFAULT_TEMPLATE)) {
			$filenames[] = 'Default.html';
			$filenames   = array_values(array_unique($filenames));
		}

		foreach ($filenames as $file) {
			if (!preg_match('/^[a-zA-Z0-9_-]+\.html$/', $file)) {
				continue;
			}

			$path      = $base_real . DIRECTORY_SEPARATOR . $file;
			$path_real = realpath($path);
			if ($path_real === false || !is_file($path_real)) {
				continue;
			}

			if (strpos($path_real, $base_real . DIRECTORY_SEPARATOR) !== 0 && $path_real !== $base_real . DIRECTORY_SEPARATOR . $file) {
				continue;
			}

			$contents = file_get_contents($path_real);
			if ($contents !== false) {
				return $contents;
			}
		}

		return null;
	}

	private static function render_template(string $template, array $data): string {
		$normalized_data = self::normalize_template_data($data);

		$rendered = preg_replace_callback(
			'/\{\{\s*([a-zA-Z][a-zA-Z0-9_-]*)\s*\}\}/',
			static function (array $matches) use ($normalized_data): string {
				$key    = sanitize_key($matches[1]);
				$value = $normalized_data[$key] ?? 'N/A';
				return esc_html($value);
			},
			$template
		);

		if (!is_string($rendered)) {
			return esc_html__('Unable to render email template.', 'cf2m');
		}

		return self::apply_builtin_template_tokens($rendered);
	}

	/**
	 * Replaces [timestamp] and [ip] in email HTML (site-local time and client IP).
	 */
	private static function apply_builtin_template_tokens(string $html): string {
		$timestamp = wp_date(
			(string) get_option('date_format', 'Y-m-d') . ' ' . (string) get_option('time_format', 'H:i'),
			null
		);
		$ip = self::get_client_ip();
		$ip = $ip !== '' ? sanitize_text_field($ip) : 'N/A';

		$html = str_replace('[timestamp]', esc_html($timestamp), $html);
		$html = str_replace('[ip]', esc_html($ip), $html);

		return $html;
	}

	private static function normalize_template_data(array $data): array {
		$normalized = [];

		foreach ($data as $key => $value) {
			$clean_key = sanitize_key((string) $key);
			if ($clean_key === '') {
				continue;
			}

			if (is_array($value)) {
				$normalized[$clean_key] = implode(', ', array_map('strval', $value));
				continue;
			}

			$normalized[$clean_key] = (string) $value;
		}

		$normalized['siteurl'] = home_url('/');
		$normalized['logourl'] = self::get_site_logo_url();

		return $normalized;
	}

	private static function get_site_logo_url(): string {
		$custom_logo_id = (int) get_theme_mod('custom_logo');
		if ($custom_logo_id > 0) {
			$logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
			if (is_string($logo_url) && $logo_url !== '') {
				return $logo_url;
			}

			$attachment_url = wp_get_attachment_url($custom_logo_id);
			if (is_string($attachment_url) && $attachment_url !== '') {
				return $attachment_url;
			}
		}

		$custom_logo_html = get_custom_logo();
		if (is_string($custom_logo_html) && $custom_logo_html !== '') {
			if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $custom_logo_html, $matches) === 1 && !empty($matches[1])) {
				return esc_url_raw($matches[1]);
			}
		}

		$option_logo_id = (int) get_option('site_logo');
		if ($option_logo_id > 0) {
			$option_logo_url = wp_get_attachment_image_url($option_logo_id, 'full');
			if (is_string($option_logo_url) && $option_logo_url !== '') {
				return $option_logo_url;
			}
		}

		$icon_url = get_site_icon_url(512);
		if (is_string($icon_url) && $icon_url !== '') {
			return $icon_url;
		}

		return home_url('/logo.png');
	}

	private static function get_client_ip(): string {
		$keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

		foreach ($keys as $key) {
			if (!isset($_SERVER[ $key ]) || ! is_string($_SERVER[ $key ]) || $_SERVER[ $key ] === '') {
				continue;
			}

			$raw = sanitize_text_field(wp_unslash($_SERVER[ $key ]));
			$ip  = trim(explode(',', $raw, 2)[0]);
			if ($ip !== '') {
				return $ip;
			}
		}

		return '';
	}

	private static function respond(int $status_code, string $message): void {
		status_header($status_code);

		wp_send_json(
			[
				'success' => $status_code >= 200 && $status_code < 300,
				'message' => $message,
			],
			$status_code
		);
	}
}

register_activation_hook(__FILE__, ['CF2M_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['CF2M_Plugin', 'deactivate']);
CF2M_Plugin::init();
