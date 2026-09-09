<?php
	require_once __DIR__ . '/run.php';
	function hookProcess(string $file, array $environment, array $arguments = array()): array {
		$process = proc_open(array_merge(array(PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'auto_prepend_file=' . __DIR__ . '/hook-fixture.php', __DIR__ . '/../' . $file), $arguments), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $environment);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]);
		return array('exit' => proc_close($process), 'output' => trim($output), 'error' => $error);
	}
	$directory = sys_get_temp_dir() . '/dnsapi-hooks-' . bin2hex(random_bytes(8));
	mkdir($directory, 0700);
	$environment = array('PATH' => getenv('PATH'), 'DNSAPI_CONFIG_FILE' => $directory . '/config.json', 'DNSAPI_JOURNAL_FILE' => $directory . '/journal.json', 'DNSAPI_FIXTURE_STATE' => $directory . '/records.json', 'DNSAPI_RUN_ID' => 'fixture-run', 'CERTBOT_DOMAIN' => 'example.test', 'CERTBOT_VALIDATION' => 'first-token', 'CERTBOT_REMAINING_CHALLENGES' => '1');
	file_put_contents($environment['DNSAPI_CONFIG_FILE'], json_encode(array('API' => array('HetznerCloud' => array('recordsURL' => 'https://api.hetzner.cloud/v1/zones', 'APIToken' => 'fixture-secret')), 'domains' => array('example.test' => array('zoneID' => '1', 'API' => 'HetznerCloud')), 'certbot' => array('propagation_timeout_seconds' => 1))));
	try {
		$first = hookProcess('certbot-dnsapi-authenticator.php', $environment);
		check($first['exit'] === 0 && preg_match('/^[a-f0-9]{32}$/D', $first['output']) === 1, 'first hook writes only operation ID');
		check(!str_contains($first['error'], 'PROPAGATION'), 'batch first hook does not wait');
		$other = hookProcess('certbot-dnsapi-authenticator.php', array_replace($environment, array('DNSAPI_RUN_ID' => 'other-run')));
		check($other['exit'] === 1 && str_contains($other['error'], 'CHALLENGE_OWNED_BY_OTHER_RUN'), 'concurrent order rejected');
		$secondEnv = array_replace($environment, array('CERTBOT_VALIDATION' => 'second-token', 'CERTBOT_REMAINING_CHALLENGES' => '0'));
		$second = hookProcess('certbot-dnsapi-authenticator.php', $secondEnv);
		check($second['exit'] === 0 && substr_count($second['error'], '"event":"PROPAGATION"') === 8, 'apex/wildcard same name both checked in final hook');
		check(!str_contains($second['error'], 'first-token') && !str_contains($second['error'], 'second-token') && !str_contains($second['error'], 'fixture-secret'), 'hook logs redact values and credentials');
		$cleanup = hookProcess('certbot-dnsapi-cleanup.php', array_replace($environment, array('CERTBOT_AUTH_OUTPUT' => $first['output'])));
		check($cleanup['exit'] === 0 && count(json_decode(file_get_contents($environment['DNSAPI_FIXTURE_STATE']), true)) === 1, 'cleanup preserves simultaneous second challenge');
		$cleanup = hookProcess('certbot-dnsapi-cleanup.php', array_replace($environment, array('CERTBOT_AUTH_OUTPUT' => $first['output'])));
		check($cleanup['exit'] === 0, 'hook cleanup repeat succeeds');
		$failed = hookProcess('certbot-dnsapi-cleanup.php', array_replace($secondEnv, array('CERTBOT_AUTH_OUTPUT' => $second['output'], 'DNSAPI_FIXTURE_FAILURE' => 'read')));
		$journal = json_decode(file_get_contents($environment['DNSAPI_JOURNAL_FILE']), true);
		check($failed['exit'] === 1 && isset($journal['journal'][$second['output']]), 'cleanup read failure retains entry');
		$cleanup = hookProcess('certbot-dnsapi-cleanup.php', array_replace($secondEnv, array('CERTBOT_AUTH_OUTPUT' => $second['output'])));
		check($cleanup['exit'] === 0, 'cleanup retry succeeds');
		$crash = hookProcess('certbot-dnsapi-authenticator.php', array_replace($environment, array('CERTBOT_REMAINING_CHALLENGES' => '0', 'DNSAPI_FIXTURE_FAILURE' => 'crash')));
		$journal = json_decode(file_get_contents($environment['DNSAPI_JOURNAL_FILE']), true);
		$entry = reset($journal['journal']);
		check($crash['exit'] === 70 && $entry['phase'] === 'prepared', 'crash after API write retains intent');
		$plan = hookProcess('dnsapi-recovery.php', $environment, array('--run=' . $entry['run']));
		check($plan['exit'] === 0 && count(json_decode(file_get_contents($environment['DNSAPI_FIXTURE_STATE']), true)) === 1, 'recovery plan does not delete');
		$recover = hookProcess('dnsapi-recovery.php', $environment, array('--run=' . $entry['run'], '--apply'));
		check($recover['exit'] === 0 && !json_decode(file_get_contents($environment['DNSAPI_FIXTURE_STATE']), true), 'recovery removes exact intent after crash');
		$start = hrtime(true);
		$timeout = hookProcess('certbot-dnsapi-authenticator.php', array_replace($environment, array('CERTBOT_REMAINING_CHALLENGES' => '0', 'DNSAPI_FIXTURE_FAILURE' => 'dns')));
		check($timeout['exit'] === 1 && str_contains($timeout['error'], 'PROPAGATION_TIMEOUT') && (hrtime(true) - $start) / 1e9 >= 0.9 && (hrtime(true) - $start) / 1e9 < 2, 'hook deadline includes polling sleep: ' . $timeout['error']);
		check(!json_decode(file_get_contents($environment['DNSAPI_FIXTURE_STATE']), true) && !json_decode(file_get_contents($environment['DNSAPI_JOURNAL_FILE']), true)['journal'], 'propagation failure automatically cleans own values');
		file_put_contents($environment['DNSAPI_JOURNAL_FILE'], '{broken');
		$corrupt = hookProcess('certbot-dnsapi-authenticator.php', $environment);
		check($corrupt['exit'] === 1 && file_get_contents($environment['DNSAPI_JOURNAL_FILE']) === '{broken' && !json_decode(file_get_contents($environment['DNSAPI_FIXTURE_STATE']), true), 'broken journal blocks hook before DNS mutation');
	} finally {
		foreach(glob($directory . '/*') as $file) unlink($file);
		rmdir($directory);
	}
	echo "OK: $count total checks including hook subprocesses on PHP " . PHP_VERSION . PHP_EOL;
