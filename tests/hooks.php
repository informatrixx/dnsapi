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
		$first = hookProcess('certbot-dnsapi-authenticator.php', $environment);
		$originalJournal = file_get_contents($environment['DNSAPI_JOURNAL_FILE']);
		$originalRecords = file_get_contents($environment['DNSAPI_FIXTURE_STATE']);
		$batchJournal = json_decode($originalJournal, true);
		$batchJournal['journal'] = array();
		file_put_contents($environment['DNSAPI_JOURNAL_FILE'], json_encode($batchJournal));
		$missing = hookProcess('certbot-dnsapi-authenticator.php', $secondEnv);
		check($missing['exit'] === 1 && str_contains($missing['error'], 'CHALLENGE_BATCH_INCOMPLETE') && file_get_contents($environment['DNSAPI_FIXTURE_STATE']) === $originalRecords, 'missing earlier challenge cannot produce successful final verification or a new write');
		file_put_contents($environment['DNSAPI_JOURNAL_FILE'], $originalJournal);
		$batchJournal = json_decode($originalJournal, true);
		$entry = reset($batchJournal['journal']);
		$typo = hookProcess('dnsapi-recovery.php', $environment, array('--run=' . $entry['run'], '--apply', '--dry-run'));
		check($typo['exit'] === 1 && file_get_contents($environment['DNSAPI_FIXTURE_STATE']) === $originalRecords && file_get_contents($environment['DNSAPI_JOURNAL_FILE']) === $originalJournal, 'unknown recovery option fails before journal or provider writes');
		$recover = hookProcess('dnsapi-recovery.php', $environment, array('--run=' . $entry['run'], '--apply'));
		check($recover['exit'] === 0, 'recover intact batch after incomplete-state test');
		foreach(array(600, null) as $ttl) {
			$foreign = array('foreign' => array('id' => '_acme-challenge/TXT', 'name' => '_acme-challenge', 'type' => 'TXT', 'ttl' => $ttl, 'records' => array(array('value' => '"foreign-token"'))));
			file_put_contents($environment['DNSAPI_FIXTURE_STATE'], json_encode($foreign));
			$auth = hookProcess('certbot-dnsapi-authenticator.php', array_replace($environment, array('CERTBOT_REMAINING_CHALLENGES' => '0')));
			check($auth['exit'] === 0, 'hook preserves existing or inherited RRSet TTL: ' . $auth['error']);
			$cleanup = hookProcess('certbot-dnsapi-cleanup.php', array_replace($environment, array('CERTBOT_AUTH_OUTPUT' => $auth['output'])));
			check($cleanup['exit'] === 0 && json_decode(file_get_contents($environment['DNSAPI_FIXTURE_STATE']), true) === $foreign, 'TTL regression cleanup preserves foreign RRSet');
		}
		file_put_contents($environment['DNSAPI_FIXTURE_STATE'], '[]');
		foreach(array('after-write', 'after-write-pending') as $failure) {
			$failed = hookProcess('certbot-dnsapi-authenticator.php', array_replace($environment, array('CERTBOT_REMAINING_CHALLENGES' => '0', 'DNSAPI_FIXTURE_FAILURE' => $failure)));
			$journal = json_decode(file_get_contents($environment['DNSAPI_JOURNAL_FILE']), true);
			check($failed['exit'] === 1 && str_contains($failed['error'], 'PROVIDER_RESPONSE_INVALID'), 'provider write followed by response error never passes');
			if($failure === 'after-write') check(!$journal['journal'] && !json_decode(file_get_contents($environment['DNSAPI_FIXTURE_STATE']), true), 'post-write error reconciles and cleans its exact value');
			else {
				$entry = reset($journal['journal']);
				check($entry['phase'] === 'prepared' && $entry['cleanup_failed'] === true, 'failed reconciliation retains prepared intent');
				$recover = hookProcess('dnsapi-recovery.php', $environment, array('--run=' . $entry['run'], '--apply'));
				check($recover['exit'] === 0 && !json_decode(file_get_contents($environment['DNSAPI_FIXTURE_STATE']), true), 'later recovery reconciles the retained intent');
			}
		}
		$config = json_decode(file_get_contents($environment['DNSAPI_CONFIG_FILE']), true);
		$config['certbot']['propagation_mode'] = 'recursive';
		file_put_contents($environment['DNSAPI_CONFIG_FILE'], json_encode($config));
		$recursiveEnv = array_replace($environment, array('DNSAPI_FIXTURE_FAILURE' => 'authority'));
		$first = hookProcess('certbot-dnsapi-authenticator.php', $recursiveEnv);
		$second = hookProcess('certbot-dnsapi-authenticator.php', array_replace($recursiveEnv, array('CERTBOT_VALIDATION' => 'second-token', 'CERTBOT_REMAINING_CHALLENGES' => '0')));
		check($first['exit'] === 0 && $second['exit'] === 0 && substr_count($second['error'], '"event":"PROPAGATION"') === 6, 'recursive batch succeeds without discovery or authority access');
		$doctor = hookProcess('dnsapi-doctor.php', $recursiveEnv, array('--domain=example.test', '--authorities'));
		check($doctor['exit'] === 0 && str_contains($doctor['error'], 'OPTIONAL_AUTHORITY_DIAGNOSIS_FAILED'), 'optional doctor authority error does not block recursive mode');
		$cleanup = hookProcess('certbot-dnsapi-cleanup.php', array_replace($environment, array('CERTBOT_AUTH_OUTPUT' => $first['output'])));
		check($cleanup['exit'] === 0, 'recursive first challenge cleanup');
		$cleanup = hookProcess('certbot-dnsapi-cleanup.php', array_replace($secondEnv, array('CERTBOT_AUTH_OUTPUT' => $second['output'])));
		check($cleanup['exit'] === 0, 'recursive second challenge cleanup');
		$invalidConfig = $config;
		$invalidConfig['certbot']['propagation_mode'] = 'automatic';
		file_put_contents($environment['DNSAPI_CONFIG_FILE'], json_encode($invalidConfig));
		$invalid = hookProcess('certbot-dnsapi-authenticator.php', $environment);
		check($invalid['exit'] === 1 && str_contains($invalid['error'], 'CONFIG_PROPAGATION_MODE_INVALID') && !json_decode(file_get_contents($environment['DNSAPI_FIXTURE_STATE']), true), 'invalid mode blocks hook before DNS mutation');
		file_put_contents($environment['DNSAPI_CONFIG_FILE'], json_encode($config));
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
