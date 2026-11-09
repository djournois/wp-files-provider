<?php
// Run with: php tests/handler-security.php
// The handler is executed in isolated PHP CLI processes, so the checks do not need network access.
$base = sys_get_temp_dir() . '/wpfp-test-' . bin2hex(random_bytes(5));
$documents = $base . '/documents';
mkdir($documents, 0700, true);
file_put_contents($documents . '/hello.txt', 'private file');
file_put_contents($documents . '/large.txt', str_repeat('x', 32));
file_put_contents($base . '/outside.txt', 'outside');
$has_symlink = @symlink($base . '/outside.txt', $documents . '/outside-link.txt');
$secret = bin2hex(random_bytes(32));
$config = array(
	'secret' => $secret,
	'document_root' => $documents,
	'clock_tolerance' => 0,
	'max_ttl' => 600,
	'max_bytes' => 20,
	'transfer_mode' => 'php',
);
file_put_contents($base . '/config.php', "<?php return " . var_export($config, true) . ";\n");
$handler = realpath(dirname(__DIR__) . '/litespeed/files-provider.php');

function handler_request(string $path, int $expiry_offset = 120, bool $tamper = false): array {
	global $secret, $handler, $base;
	$expiry = time() + $expiry_offset;
	$encoded = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
	$canonical = "wpfp\n1\n" . strlen($path) . ':' . $path . "\n" . $expiry;
	$signature = hash_hmac('sha256', $canonical, $secret);
	if ($tamper) {
		$signature[0] = $signature[0] === 'a' ? 'b' : 'a';
	}
	$query = http_build_query(array('v' => '1', 'p' => $encoded, 'e' => (string) $expiry, 's' => $signature), '', '&', PHP_QUERY_RFC3986);
	$script = 'parse_str(' . var_export($query, true) . ', $_GET); $_SERVER["REQUEST_METHOD"]="GET"; http_response_code(200); register_shutdown_function(function(){fwrite(STDERR,"\\n__STATUS=".http_response_code());}); include ' . var_export($handler, true) . ';';
	$command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script);
	$descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$environment = array_merge($_ENV, array('WPFP_CONFIG' => $base . '/config.php'));
	$process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $environment);
	if (!is_resource($process)) {
		throw new RuntimeException('Could not start handler check');
	}
	fclose($pipes[0]);
	$body = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	proc_close($process);
	if (!preg_match('/__STATUS=(\d+)/', $stderr, $match)) {
		throw new RuntimeException('Handler did not report a status: ' . $stderr);
	}
	return array((int) $match[1], $body);
}

try {
	$cases = array(
		'valid file' => handler_request('hello.txt'),
		'tampered signature' => handler_request('hello.txt', 120, true),
		'expired link' => handler_request('hello.txt', -60),
		'signed traversal' => handler_request('../outside.txt'),
		'missing file' => handler_request('missing.txt'),
		'file over configured size limit' => handler_request('large.txt'),
	);
	$expected = array(
		'valid file' => 200,
		'tampered signature' => 403,
		'expired link' => 410,
		'signed traversal' => 403,
		'missing file' => 404,
		'file over configured size limit' => 413,
	);
	if ($has_symlink) {
		$cases['symlink outside root'] = handler_request('outside-link.txt');
		$expected['symlink outside root'] = 404;
	}
	foreach ($cases as $name => $result) {
		if ($result[0] !== $expected[$name]) {
			throw new RuntimeException($name . ' returned HTTP ' . $result[0] . ', expected ' . $expected[$name]);
		}
		if ($name === 'valid file' && $result[1] !== 'private file') {
			throw new RuntimeException('Valid file bytes were not streamed');
		}
	}
	echo 'handler-security: ' . count($cases) . " cases passed\n";
} finally {
	foreach (glob($documents . '/*') ?: array() as $file) { @unlink($file); }
	@unlink($base . '/outside.txt');
	@unlink($base . '/config.php');
	@rmdir($documents);
	@rmdir($base);
}
