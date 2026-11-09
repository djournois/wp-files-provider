<?php
/**
 * Plugin Name: WP Files Provider
 * Description: Provides capability-protected, short-lived downloads from a separate file server.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

final class WP_Files_Provider {
	const OPTION = 'wpfp_settings';
	const ACTION = 'wpfp_download';
	const CAPABILITY = 'download_private_files';
	const DEFAULT_TTL = 120;
	const MAX_TTL = 600;

	public static function init() {
		add_shortcode('wpfp_download', array(__CLASS__, 'shortcode'));
		add_action('admin_post_' . self::ACTION, array(__CLASS__, 'download'));
		add_action('admin_post_nopriv_' . self::ACTION, array(__CLASS__, 'deny_unauthenticated'));
		add_action('admin_menu', array(__CLASS__, 'admin_menu'));
		add_action('admin_init', array(__CLASS__, 'register_settings'));
	}

	private static function settings() {
		$settings = get_option(self::OPTION, array());
		return is_array($settings) ? $settings : array();
	}

	public static function shortcode($attributes) {
		$attributes = shortcode_atts(array('path' => '', 'label' => __('Download file', 'wp-files-provider')), $attributes, 'wpfp_download');
		$path = is_string($attributes['path']) ? $attributes['path'] : '';
		if ($path === '') {
			return '';
		}
		$url = add_query_arg(array('action' => self::ACTION, 'path' => $path), admin_url('admin-post.php'));
		$url = wp_nonce_url($url, self::ACTION . '|' . $path, '_wpfp_nonce');
		return '<a href="' . esc_url($url) . '">' . esc_html($attributes['label']) . '</a>';
	}

	public static function deny_unauthenticated() {
		self::error(401, __('Please sign in to download this file.', 'wp-files-provider'));
	}

	public static function download() {
		self::no_cache();
		if (!is_user_logged_in()) {
			self::error(401, __('Please sign in to download this file.', 'wp-files-provider'));
		}
		if (!current_user_can(self::CAPABILITY)) {
			self::error(403, __('You are not allowed to download this file.', 'wp-files-provider'));
		}
		$path = isset($_GET['path']) && is_string($_GET['path']) ? wp_unslash($_GET['path']) : '';
		$nonce = isset($_GET['_wpfp_nonce']) && is_string($_GET['_wpfp_nonce']) ? sanitize_text_field(wp_unslash($_GET['_wpfp_nonce'])) : '';
		if ($path === '' || strlen($path) > 2048 || strpos($path, "\0") !== false || preg_match('/[\x00-\x1F\x7F]/', $path) || !wp_verify_nonce($nonce, self::ACTION . '|' . $path)) {
			self::error(400, __('This download link is invalid or expired. Please request a new link.', 'wp-files-provider'));
		}
		$settings = self::settings();
		$base = isset($settings['server_url']) ? trim($settings['server_url']) : '';
		$secret = defined('WPFP_SHARED_SECRET') ? WPFP_SHARED_SECRET : '';
		if (!$base || !wp_http_validate_url($base) || strtolower((string) wp_parse_url($base, PHP_URL_SCHEME)) !== 'https' || !is_string($secret) || strlen($secret) < 32) {
			self::error(503, __('Downloads are not configured. Please contact the site administrator.', 'wp-files-provider'));
		}
		$ttl = defined('WPFP_LINK_TTL') ? (int) WPFP_LINK_TTL : (isset($settings['ttl']) ? (int) $settings['ttl'] : self::DEFAULT_TTL);
		$ttl = max(30, min(self::MAX_TTL, $ttl));
		$expires = time() + $ttl;
		$encoded_path = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
		$canonical = "wpfp\n1\n" . strlen($path) . ':' . $path . "\n" . $expires;
		$signature = hash_hmac('sha256', $canonical, $secret);
		$url = add_query_arg(array('v' => '1', 'p' => $encoded_path, 'e' => (string) $expires, 's' => $signature), $base);
		wp_redirect($url, 302, 'WP Files Provider');
		exit;
	}

	private static function no_cache() {
		if (!defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}
		nocache_headers();
		header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
		header('Pragma: no-cache', true);
		header('Vary: Cookie', false);
	}

	private static function error($status, $message) {
		self::no_cache();
		wp_die(esc_html($message), esc_html__('Download unavailable', 'wp-files-provider'), array('response' => (int) $status));
	}

	public static function admin_menu() {
		add_options_page(__('WP Files Provider', 'wp-files-provider'), __('WP Files Provider', 'wp-files-provider'), 'manage_options', 'wp-files-provider', array(__CLASS__, 'settings_page'));
	}

	public static function register_settings() {
		register_setting('wpfp_settings_group', self::OPTION, array('sanitize_callback' => array(__CLASS__, 'sanitize_settings')));
	}

	public static function sanitize_settings($input) {
		$input = is_array($input) ? $input : array();
		$previous = self::settings();
		$all_roles = wp_roles()->roles;
		$requested = isset($input['roles']) && is_array($input['roles']) ? array_map('sanitize_key', array_filter($input['roles'], 'is_string')) : array();
		$roles = array_values(array_intersect(array_keys($all_roles), array_unique($requested)));
		$old_roles = isset($previous['roles']) && is_array($previous['roles']) ? $previous['roles'] : array();
		foreach (array_diff($old_roles, $roles) as $role_slug) {
			$role = get_role($role_slug);
			if ($role) {
				$role->remove_cap(self::CAPABILITY);
			}
		}
		foreach ($roles as $role_slug) {
			$role = get_role($role_slug);
			if ($role) {
				$role->add_cap(self::CAPABILITY);
			}
		}
		$server_url = isset($input['server_url']) ? untrailingslashit(esc_url_raw(trim($input['server_url']))) : '';
		$ttl = isset($input['ttl']) ? absint($input['ttl']) : self::DEFAULT_TTL;
		return array(
			'server_url' => $server_url,
			'ttl' => max(30, min(self::MAX_TTL, $ttl)),
			'roles' => $roles,
		);
	}

	public static function settings_page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$settings = self::settings();
		$selected_roles = isset($settings['roles']) && is_array($settings['roles']) ? $settings['roles'] : array();
		$all_roles = wp_roles()->roles;
		?>
		<div class="wrap">
			<h1><?php esc_html_e('WP Files Provider', 'wp-files-provider'); ?></h1>
			<p><?php esc_html_e('The shared secret is read from private server configuration and is never stored or shown here.', 'wp-files-provider'); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields('wpfp_settings_group'); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="wpfp-server-url"><?php esc_html_e('Remote handler URL', 'wp-files-provider'); ?></label></th><td><input id="wpfp-server-url" class="regular-text" type="url" name="<?php echo esc_attr(self::OPTION); ?>[server_url]" value="<?php echo esc_attr(isset($settings['server_url']) ? $settings['server_url'] : ''); ?>" required><p class="description"><?php esc_html_e('HTTPS URL of the signed PHP handler on the file server.', 'wp-files-provider'); ?></p></td></tr>
					<tr><th><label for="wpfp-ttl"><?php esc_html_e('Link validity (seconds)', 'wp-files-provider'); ?></label></th><td><input id="wpfp-ttl" type="number" min="30" max="600" name="<?php echo esc_attr(self::OPTION); ?>[ttl]" value="<?php echo esc_attr(isset($settings['ttl']) ? (int) $settings['ttl'] : self::DEFAULT_TTL); ?>"></td></tr>
					<tr><th><?php esc_html_e('Authorized roles', 'wp-files-provider'); ?></th><td>
						<?php foreach ($all_roles as $role_slug => $role_data) : ?>
							<label style="display:block;margin:0.3em 0"><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[roles][]" value="<?php echo esc_attr($role_slug); ?>" <?php checked(in_array($role_slug, $selected_roles, true)); ?>> <?php echo esc_html(translate_user_role($role_data['name'])); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e('Selected roles receive the dedicated download_private_files capability. No role is selected by default.', 'wp-files-provider'); ?></p>
					</td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<p><?php esc_html_e('Define WPFP_SHARED_SECRET privately in wp-config.php or the PHP environment. It is not managed in this screen.', 'wp-files-provider'); ?></p>
		</div>
		<?php
	}
}

WP_Files_Provider::init();
