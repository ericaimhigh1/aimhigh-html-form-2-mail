<?php
/**
 * Plugin Name: Custom Form 2 Mail
 * Description: Handles frontend custom HTML form submissions at /cf2m and emails sanitized payloads.
 * Version: 1.0.0
 * Author: Eric Aimhigh
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

final class CF2M_Plugin {
	private const QUERY_VAR      = 'cf2m_action';
	private const QUERY_VALUE    = 'submit';
	private const NONCE_ACTION   = 'cf2m_submit_form';
	private const NONCE_FIELD    = 'cf2m_nonce';
	private const HONEYPOT_FIELD = 'cf2m_hp';
	private const TARGET_EMAIL   = 'test@localhost.test';

	public static function init(): void {
		add_action('init', [__CLASS__, 'add_rewrite_rule']);
		add_filter('query_vars', [__CLASS__, 'register_query_var']);
		add_action('template_redirect', [__CLASS__, 'maybe_handle_submission']);

		// Optional helper for nonce field in frontend forms.
		add_shortcode('cf2m_nonce_field', [__CLASS__, 'shortcode_nonce_field']);
	}

	public static function activate(): void {
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function add_rewrite_rule(): void {
		add_rewrite_rule(
			'^cf2m/?$',
			'index.php?' . self::QUERY_VAR . '=' . self::QUERY_VALUE,
			'top'
		);
	}

	public static function register_query_var(array $vars): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function shortcode_nonce_field(): string {
		return wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, true, false);
	}

	public static function maybe_handle_submission(): void {
		$action = get_query_var(self::QUERY_VAR);

		if ($action !== self::QUERY_VALUE) {
			return;
		}

		// Route exists, but only accept POST.
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			self::respond(405, 'Method not allowed. Use POST.');
		}

		// Basic same-origin check (defense in depth).
		if (!self::is_same_origin_request()) {
			self::respond(403, 'Origin check failed.');
		}

		// CSRF protection: verify nonce when present. This is required if form includes [cf2m_nonce_field].
		$nonce = isset($_POST[self::NONCE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])) : '';
		if ($nonce !== '' && !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			self::respond(403, 'Security check failed.');
		}

		// Basic per-IP rate limit to reduce abuse of unauthenticated endpoint.
		if (!self::allow_request_rate()) {
			self::respond(429, 'Too many submissions. Please try again later.');
		}

		$sanitized = self::sanitize_form_data($_POST);

		// Honeypot check: use a dedicated hidden field so legitimate "website" inputs are allowed.
		$honeypot = isset($_POST[self::HONEYPOT_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::HONEYPOT_FIELD])) : '';
		if ($honeypot !== '') {
			self::respond(400, 'Invalid submission.');
		}

		$subject = '[CF2M] New Form Submission';

		$body_lines = [];
		$body_lines[] = 'A form was submitted via /cf2m.';
		$body_lines[] = '';
		$body_lines[] = 'IP: ' . sanitize_text_field(self::get_client_ip());
		$body_lines[] = 'User Agent: ' . sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
		$body_lines[] = '';
		$body_lines[] = 'Submitted fields:';

		foreach ($sanitized as $key => $value) {
			$body_lines[] = sprintf('- %s: %s', $key, is_scalar($value) ? (string) $value : wp_json_encode($value));
		}

		$body = implode("\n", $body_lines);

		$headers = ['Content-Type: text/plain; charset=UTF-8'];

		$sent = wp_mail(self::TARGET_EMAIL, $subject, $body, $headers);

		if (!$sent) {
			self::respond(500, 'Failed to send email.');
		}

		self::respond(200, 'Form submitted successfully.');
	}

	private static function sanitize_form_data(array $raw): array {
		$exclude_keys = [
			self::NONCE_FIELD,
			self::HONEYPOT_FIELD,
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

			$value = wp_unslash($value);
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

		// Preserve line breaks for message-like fields, strip dangerous tags.
		if (strpos($key, 'message') !== false || strpos($key, 'comment') !== false) {
			return sanitize_textarea_field($value);
		}

		return sanitize_text_field($value);
	}

	private static function is_same_origin_request(): bool {
		$site_host = wp_parse_url(home_url(), PHP_URL_HOST);
		$origin    = isset($_SERVER['HTTP_ORIGIN']) ? wp_parse_url((string) $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) : null;
		$referer   = isset($_SERVER['HTTP_REFERER']) ? wp_parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST) : null;

		if (!$site_host) {
			return false;
		}

		// Some browsers/privacy tools omit both headers; allow in that case and rely on sanitization + rate limits.
		if (!$origin && !$referer) {
			return true;
		}

		// Accept if either Origin or Referer matches site host.
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

		$key = 'cf2m_rate_' . md5($ip);
		$max_requests = 5;
		$window = MINUTE_IN_SECONDS;

		$count = (int) get_transient($key);
		if ($count >= $max_requests) {
			return false;
		}

		set_transient($key, $count + 1, $window);
		return true;
	}

	private static function get_client_ip(): string {
		$keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

		foreach ($keys as $key) {
			if (!empty($_SERVER[$key])) {
				$ip = explode(',', (string) $_SERVER[$key])[0];
				return trim($ip);
			}
		}

		return '';
	}

	private static function respond(int $status_code, string $message): void {
		status_header($status_code);

		// Return JSON for easier frontend handling.
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