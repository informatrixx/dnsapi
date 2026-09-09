#!/usr/bin/php
<?php
	require_once __DIR__ . '/dnsapi-certbot.inc.php';
	try {
		$options = getopt('', array('domain:', 'zone:'));
		$domain = dnsapiIdentifier($options['domain'] ?? '');
		$zone = dnsapiIdentifier($options['zone'] ?? $domain);
		$config = dnsapiConfig();
		$dns = new DNSAPIDNS(dnsapiSettings($config), new DNSAPIDeadline(60));
		// Supply an expected value privately via environment; never emit it or TXT answers.
		$result = $dns->check('_acme-challenge.' . $domain, dnsapiEnv('DNSAPI_EXPECTED_TXT'), $dns->authorities($zone));
		foreach($result['results'] as $diagnostic) echo json_encode($diagnostic, JSON_UNESCAPED_SLASHES) . PHP_EOL;
		exit($result['success'] ? 0 : 1);
	} catch(Throwable $error) {
		fwrite(STDERR, 'DNS_DIAGNOSIS_FAILED: check configuration, delegation and DNS connectivity' . PHP_EOL);
		exit(1);
	}
