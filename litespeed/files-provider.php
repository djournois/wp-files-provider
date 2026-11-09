<?php
/**
 * Signed endpoint for WP Files Provider. Install outside the public document root
 * where possible and point PHP-FPM at a private config file through WPFP_CONFIG.
 */
declare(strict_types=1);

$configFile = getenv('WPFP_CONFIG') ?: '/etc/wp-files-provider/config.php';
if (!is_file($configFile) || !is_readable($configFile)) {
	http_response_code(503);
	header('Cache-Control: private, no-store');
	exit('Download service unavailable');
}
$config = require $configFile;
if (!is_array($config)) {
	http_response_code(503);
	exit('Download service unavailable');
}

function wpfp_fail(int $status, string $message): void {
	http_response_code($status);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	exit($message);
}

function wpfp_decode_path(string $value) {
	if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
		return false;
	}
	$padding = str_repeat('=', (4 - strlen($value) % 4) % 4);
	$decoded = base64_decode(strtr($value, '-_', '+/') . $padding, true);
	if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $value) {
		return false;
	}
	return $decoded;
}

function wpfp_is_valid_relative_path(string $path): bool {
	if ($path === '' || strlen($path) > 2048 || $path[0] === '/' || $path[0] === '\\') {
		return false;
	}
	if (strpos($path, "\0") !== false || preg_match('/[\x00-\x1F\x7F]/', $path)) {
		return false;
	}
	// Percent signs, backslashes, colons, and empty/dot segments are rejected to avoid
	// alternate encodings, platform separators, drive paths, and traversal ambiguity.
	if (strpos($path, '%') !== false || strpos($path, '\\') !== false || strpos($path, ':') !== false) {
		return false;
	}
	foreach (explode('/', $path) as $segment) {
		if ($segment === '' || $segment === '.' || $segment === '..') {
			return false;
		}
	}
	return true;
}

function wpfp_is_below_root(string $target, string $root): bool {
	$normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR);
	if ($normalizedRoot === '') {
		// A filesystem root is too broad to be a safe documents boundary.
		return false;
	}
	$prefix = $normalizedRoot . DIRECTORY_SEPARATOR;
	return strpos($target, $prefix) === 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	header('Allow: GET');
	wpfp_fail(405, 'Method not allowed');
}
$version = $_GET['v'] ?? null;
$encodedPath = $_GET['p'] ?? null;
$expiresText = $_GET['e'] ?? null;
$signature = $_GET['s'] ?? null;
if (!is_string($version) || $version !== '1' || !is_string($encodedPath) || !is_string($expiresText) || !preg_match('/^[0-9]{1,12}$/D', $expiresText) || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
	wpfp_fail(403, 'Invalid download link');
}
$relative = wpfp_decode_path($encodedPath);
if ($relative === false || !wpfp_is_valid_relative_path($relative)) {
	wpfp_fail(403, 'Invalid download link');
}
$secret = $config['secret'] ?? '';
$clockTolerance = isset($config['clock_tolerance']) ? (int) $config['clock_tolerance'] : 30;
$clockTolerance = max(0, min(300, $clockTolerance));
$maxTtl = isset($config['max_ttl']) ? (int) $config['max_ttl'] : 600;
$maxTtl = max(30, min(600, $maxTtl));
$expiry = (int) $expiresText;
if (!is_string($secret) || strlen($secret) < 32 || (string) $expiry !== $expiresText) {
	wpfp_fail(503, 'Download service unavailable');
}
$canonical = "wpfp\n1\n" . strlen($relative) . ':' . $relative . "\n" . $expiresText;
$expectedSignature = hash_hmac('sha256', $canonical, $secret);
if (!hash_equals($expectedSignature, $signature)) {
	wpfp_fail(403, 'Invalid download link');
}
$now = time();
if ($expiry < ($now - $clockTolerance) || $expiry > ($now + $maxTtl + $clockTolerance)) {
	wpfp_fail(410, 'Download link expired');
}

$rootSetting = $config['document_root'] ?? '';
$root = is_string($rootSetting) ? realpath($rootSetting) : false;
if ($root === false || !is_dir($root)) {
	wpfp_fail(503, 'Download service unavailable');
}
$candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
$target = realpath($candidate);
if ($target === false || !wpfp_is_below_root($target, $root) || !is_file($target) || !is_readable($target)) {
	wpfp_fail(404, 'File not found');
}
$targetStat = @lstat($target);
if ($targetStat === false || (($targetStat['mode'] & 0170000) !== 0100000)) {
	wpfp_fail(404, 'File not found');
}
$maxBytes = isset($config['max_bytes']) ? (int) $config['max_bytes'] : 800000000;
$maxBytes = max(1, $maxBytes);
if ($targetStat['size'] > $maxBytes) {
	wpfp_fail(413, 'File exceeds the download size limit');
}

$filename = basename($target);
if ($filename === '' || preg_match('/[\x00-\x1F\x7F]/', $filename)) {
	$filename = 'download';
}
$asciiFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
if ($asciiFilename === '' || $asciiFilename === '.' || $asciiFilename === '..') {
	$asciiFilename = 'download';
}
$contentDisposition = 'attachment; filename="' . addcslashes($asciiFilename, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($filename);

$transferMode = $config['transfer_mode'] ?? 'php';
if ($transferMode !== 'php' && $transferMode !== 'litespeed') {
	wpfp_fail(503, 'Download service unavailable');
}
$uriPrefix = '';
if ($transferMode === 'litespeed') {
	$uriPrefix = $config['private_uri_prefix'] ?? '';
	if (!is_string($uriPrefix) || $uriPrefix === '' || $uriPrefix[0] !== '/' || substr($uriPrefix, -1) !== '/') {
		wpfp_fail(503, 'Download service unavailable');
	}
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: ' . $contentDisposition);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($transferMode === 'litespeed') {
	$relativeResolved = substr($target, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
	$uriParts = array_map('rawurlencode', explode(DIRECTORY_SEPARATOR, $relativeResolved));
	header('X-LiteSpeed-Location: ' . $uriPrefix . implode('/', $uriParts));
	exit;
}

// Stream through PHP without buffering the entire file. The inode check narrows the
// race window between canonicalization/stat and opening the resolved target.
$handle = @fopen($target, 'rb');
if ($handle === false) {
	wpfp_fail(404, 'File not found');
}
$openedStat = fstat($handle);
$currentStat = @stat($target);
if ($openedStat === false || $currentStat === false || $openedStat['ino'] !== $currentStat['ino'] || $openedStat['dev'] !== $currentStat['dev'] || (($openedStat['mode'] & 0170000) !== 0100000)) {
	fclose($handle);
	wpfp_fail(404, 'File not found');
}
if ($openedStat['size'] > $maxBytes) {
	fclose($handle);
	wpfp_fail(413, 'File exceeds the download size limit');
}
header('Content-Length: ' . (string) $openedStat['size']);
@ini_set('zlib.output_compression', '0');
@set_time_limit(0);
while (ob_get_level() > 0) {
	@ob_end_clean();
}
while (!feof($handle)) {
	$chunk = fread($handle, 1024 * 1024);
	if ($chunk === false) {
		break;
	}
	echo $chunk;
	if (connection_aborted()) {
		break;
	}
}
fclose($handle);
