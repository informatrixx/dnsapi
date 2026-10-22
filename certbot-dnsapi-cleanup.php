#!/usr/bin/php
<?php
	require_once __DIR__ . '/dnsapi-certbot.inc.php';
	umask(0077);
	try {
		$config = dnsapiConfig();
		if(!defined('CONFIG')) define('CONFIG', $config);
		$GLOBALS['dnsapiDeadline'] = new DNSAPIDeadline(60);
		$journal = new DNSAPIJournal(dnsapiEnv('DNSAPI_JOURNAL_FILE', '/var/cache/dnsapi/journal.json'));
		$output = trim(dnsapiEnv('CERTBOT_AUTH_OUTPUT'));
		$lines = preg_split('/\\R/', $output);
		$id = $lines ? trim(end($lines)) : '';
		$identifier = dnsapiIdentifier(dnsapiEnv('CERTBOT_IDENTIFIER', dnsapiEnv('CERTBOT_DOMAIN')));
		$value = dnsapiEnv('CERTBOT_VALIDATION');
		$ids = array();
		foreach($journal->data['journal'] as $candidate => $entry) {
			if(!is_array($entry)) continue;
			if(($entry['CERTBOT_IDENTIFIER'] ?? $entry['CERTBOT_DOMAIN'] ?? '') !== $identifier || ($entry['CERTBOT_VALIDATION'] ?? '') !== $value) continue;
			if($id !== '' && (string)$candidate !== $id) continue;
			$ids[] = $candidate;
		}
		if(!dnsapiCleanup($journal, $config, $ids)) exit(1);
	} catch(Throwable $error) {
		fwrite(STDERR, 'CLEANUP_FAILED: journal retained; use dnsapi-recovery.php' . PHP_EOL);
		exit(1);
	}
