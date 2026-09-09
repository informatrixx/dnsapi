#!/usr/bin/php
<?php
	require_once __DIR__ . '/dnsapi-certbot.inc.php';
	umask(0077);
	$journal = null;
	$run = null;
	try {
		$config = dnsapiConfig();
		if(!defined('CONFIG')) define('CONFIG', $config);
		$settings = dnsapiSettings($config);
		$GLOBALS['dnsapiDeadline'] = new DNSAPIDeadline($settings['propagation_timeout_seconds']);
		$identifier = dnsapiIdentifier(dnsapiEnv('CERTBOT_IDENTIFIER', dnsapiEnv('CERTBOT_DOMAIN')));
		$validation = dnsapiEnv('CERTBOT_VALIDATION');
		$remaining = dnsapiEnv('CERTBOT_REMAINING_CHALLENGES');
		if(!preg_match('/^[A-Za-z0-9_-]+$/D', $validation) || !ctype_digit($remaining))
			throw new RuntimeException('CERTBOT_ENV_INVALID');
		$remaining = (int)$remaining;
		$owner = dnsapiOwner();
		$journal = new DNSAPIJournal(dnsapiEnv('DNSAPI_JOURNAL_FILE', '/var/cache/dnsapi/journal.json'));
		$batch = $journal->data['batches'][$owner] ?? null;
		if(is_array($batch) && $batch['remaining'] > 0) {
			if($remaining !== $batch['remaining'] - 1) throw new RuntimeException('CHALLENGE_SEQUENCE_CONFLICT');
			$run = $batch['run'];
		} else {
			$run = bin2hex(random_bytes(16));
		}
		foreach($journal->data['journal'] as $entry) {
			if(!is_array($entry)) throw new RuntimeException('JOURNAL_ENTRY_INVALID');
			if(($entry['CERTBOT_IDENTIFIER'] ?? $entry['CERTBOT_DOMAIN'] ?? '') === $identifier && ($entry['run'] ?? '') !== $run)
				throw new RuntimeException('CHALLENGE_OWNED_BY_OTHER_RUN: use recovery');
		}
		$apis = array();
		$data = dnsapiProvider($config, $identifier, $apis);
		$dns = new DNSAPIDNS($settings, $GLOBALS['dnsapiDeadline']);
		if($settings['propagation_mode'] === 'strict')
			$dns->preflight('_acme-challenge.' . $identifier, $dns->authorities($data['zone']));
		$records = $data['api']->getRecords();
		if(!is_array($records)) throw new RuntimeException('PROVIDER_READ_FAILED');
		if($settings['propagation_mode'] === 'recursive') DNSAPIDNS::providerPreflight($records, $data['zone'], '_acme-challenge.' . $identifier);
		$existing = dnsapiFindChallenge($records, $data['zone'], $identifier, $validation);
		$id = bin2hex(random_bytes(16));
		$entry = array('run' => $run, 'owned' => $existing === null, 'phase' => 'prepared', 'provider_key' => $data['key'], 'zone' => $data['zone'],
			'CERTBOT_IDENTIFIER' => $identifier, 'CERTBOT_VALIDATION' => $validation,
			'created_at' => time(), 'record' => array('name' => '_acme-challenge.' . $identifier . '.', 'type' => 'TXT', 'value' => $validation));
		$journal->data['journal'][$id] = $entry;
		$journal->data['batches'][$owner] = array('run' => $run, 'remaining' => $remaining);
		$journal->save();
		$record = $existing ?? $data['api']->createRecord(name: $entry['record']['name'], type: 'TXT', value: $validation, ttl: 120);
		if(!is_array($record)) throw new RuntimeException('PROVIDER_CREATE_FAILED');
		$journal->data['journal'][$id]['phase'] = 'created';
		// Retain the exact intended value independently of provider response formatting.
		$journal->data['journal'][$id]['record'] = array_replace($record, $entry['record']);
		$journal->save();
		echo $id . PHP_EOL;
		if($remaining === 0) {
			$entries = array_filter($journal->data['journal'], fn($entry) => is_array($entry) && ($entry['run'] ?? '') === $run);
			dnsapiWait($config, $entries, $GLOBALS['dnsapiDeadline']);
		}
	} catch(Throwable $error) {
		$code = preg_match('/^[A-Z_]+/', $error->getMessage(), $match) ? $match[0] : 'HOOK_FAILED';
		fwrite(STDERR, $code . ': authentication failed; journal recovery may be required' . PHP_EOL);
		if($journal instanceof DNSAPIJournal && $run !== null && isset($config)) {
			try {
				// Cleanup has a separate bounded budget after propagation has ended.
				$GLOBALS['dnsapiDeadline'] = new DNSAPIDeadline(60);
				$ids = array_keys(array_filter($journal->data['journal'], fn($entry) => is_array($entry) && ($entry['run'] ?? '') === $run));
				dnsapiCleanup($journal, $config, $ids);
			} catch(Throwable $cleanupError) {
				fwrite(STDERR, 'RECOVERY_REQUIRED: journal retained' . PHP_EOL);
			}
		}
		exit(1);
	}
