<?php
// Lightweight WordPress API doubles: exercise the endpoint's login and capability gates.
define('ABSPATH', __DIR__);
$hooks = array();
$logged_in = false;
$has_download_capability = false;
$caught = null;
function add_shortcode($tag, $callback) {}
function add_action($hook, $callback) { global $hooks; $hooks[$hook] = $callback; }
function add_options_page() {}
function register_setting() {}
function __($text, $domain = null) { return $text; }
function esc_html($text) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
function get_option($name, $default = false) { return array('server_url' => 'https://files.example.test/download.php', 'roles' => array()); }
function is_user_logged_in() { global $logged_in; return $logged_in; }
function current_user_can($capability) { global $has_download_capability; return $capability === 'download_private_files' && $has_download_capability; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return $value; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid'; }
function wp_http_validate_url($url) { return true; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url) { return $url; }
function untrailingslashit($path) { return rtrim($path, '/'); }
function absint($value) { return abs((int) $value); }
function wp_roles() { return (object) array('roles' => array()); }
function get_role($slug) { return null; }
function nocache_headers() {}
function wp_die($message, $title = '', $args = array()) { global $caught; $caught = array($message, $args['response'] ?? 0); throw new RuntimeException('wp_die'); }
require dirname(__DIR__) . '/wp-files-provider/wp-files-provider.php';

function assert_response(int $expected, string $label): void {
	global $caught;
	if (!$caught || $caught[1] !== $expected) {
		throw new RuntimeException($label . ' did not return HTTP ' . $expected);
	}
	$caught = null;
}

try { WP_Files_Provider::download(); } catch (RuntimeException $error) {}
assert_response(401, 'Unauthenticated request');
$logged_in = true;
try { WP_Files_Provider::download(); } catch (RuntimeException $error) {}
assert_response(403, 'Insufficient capability');
echo "wordpress-access: 2 cases passed\n";
