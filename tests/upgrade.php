<?php
	require __DIR__ . '/support/Test.php';
	require_once __DIR__ . '/../tools/release-lib.php';
	require_once __DIR__ . '/../src/Runtime/Journal.php';
	$baseline = getenv('DNSAPI_BASELINE_DIR');
	if(!is_string($baseline) || !is_file($baseline . '/VERSION') || trim(file_get_contents($baseline . '/VERSION')) !== '0.2.1') throw new RuntimeException('UPGRADE_BASELINE_REQUIRED');
	$candidate = dirname(__DIR__);
	$directory = DNSAPIRelease::temporaryDirectory();
	try {
		$config = array('API' => array('HetznerCloud' => array('recordsURL' => 'https://api.hetzner.cloud/v1/zones', 'APIToken' => 'fixture-secret')), 'domains' => array('example.test' => array('zoneID' => '1', 'API' => 'HetznerCloud')), 'certbot' => array('propagation_mode' => 'recursive', 'propagation_timeout_seconds' => 1));
		file_put_contents($directory . '/config.json', json_encode($config));
		$env = array('DNSAPI_CONFIG_FILE' => $directory . '/config.json', 'DNSAPI_JOURNAL_FILE' => $directory . '/journal.json', 'DNSAPI_FIXTURE_STATE' => $directory . '/records.json', 'DNSAPI_RUN_ID' => 'upgrade-fixture', 'CERTBOT_DOMAIN' => 'example.test', 'CERTBOT_VALIDATION' => 'upgrade-token', 'CERTBOT_REMAINING_CHALLENGES' => '1');
		$invoke = fn($root, $file, $args = array(), $extra = array()) => testProcess(array_merge(array(PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/hook-fixture.php', $root . '/' . $file), $args), $extra + $env);
		$foreign = array('foreign' => array('id' => 'foreign', 'name' => '_acme-challenge.example.test.', 'type' => 'TXT', 'value' => 'unrelated-token'));
		foreach(array(array($baseline, $candidate, 'upgrade'), array($candidate, $baseline, 'rollback')) as [$from, $to, $label]) {
			file_put_contents($directory . '/records.json', json_encode($foreign));
			if(file_exists($directory . '/journal.json')) unlink($directory . '/journal.json');
			$auth = $invoke($from, 'certbot-dnsapi-authenticator.php');
			testAssert($auth['exit'] === 0, $label . ' original tree creates pending operation: ' . $auth['error']);
			$original = file_get_contents($directory . '/journal.json');
			$journal = json_decode($original, true);
			$entry = reset($journal['journal']);
			$plan = $invoke($to, 'dnsapi-recovery.php', array('--run=' . $entry['run']));
			testAssert($plan['exit'] === 0 && file_get_contents($directory . '/journal.json') === $original, $label . ' reads pending version-2 journal without rewriting');
			$lock = new DNSAPIJournal($directory . '/journal.json');
			$blocked = $invoke($to, 'dnsapi-recovery.php', array('--run=' . $entry['run'], '--apply'));
			testAssert($blocked['exit'] !== 0 && file_get_contents($directory . '/journal.json') === $original, $label . ' stable lock blocks recovery while hook owns journal');
			unset($lock);
			$recovery = $invoke($to, 'dnsapi-recovery.php', array('--run=' . $entry['run'], '--apply'));
			testAssert($recovery['exit'] === 0 && json_decode(file_get_contents($directory . '/records.json'), true) === $foreign, $label . ' removes only owned value');
			testAssert(!json_decode(file_get_contents($directory . '/journal.json'), true)['journal'], $label . ' clears confirmed recovery entry');
			$again = $invoke($to, 'dnsapi-recovery.php', array('--run=' . $entry['run'], '--apply'));
			testAssert($again['exit'] === 0 && json_decode(file_get_contents($directory . '/records.json'), true) === $foreign, $label . ' repeated recovery is harmless');
			file_put_contents($directory . '/journal.json', '{broken');
			$broken = $invoke($to, 'dnsapi-recovery.php', array('--run=' . $entry['run'], '--apply'));
			testAssert($broken['exit'] !== 0 && file_get_contents($directory . '/journal.json') === '{broken', $label . ' preserves corrupt journal');
		}
	} finally { unset($lock); DNSAPIRelease::removeDirectory($directory); }
	echo "OK: $testCount upgrade/rollback checks on PHP " . PHP_VERSION . PHP_EOL;
