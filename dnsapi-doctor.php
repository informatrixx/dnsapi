#!/usr/bin/php
<?php
	require_once __DIR__ . '/dnsapi-certbot.inc.php';
	require_once __DIR__ . '/src/Cli/Arguments.php';
	try {
		$options = DNSAPIArguments::options($argv, '', array('domain:', 'zone:', 'authorities', 'help'));
		if(isset($options['help'])) { echo "dnsapi-doctor.php --domain NAME [--zone NAME] [--authorities]\nDNS reads only; DNSAPI_EXPECTED_TXT optionally supplies the exact expected value.\n"; exit(0); }
		$domain = dnsapiIdentifier($options['domain'] ?? '');
		$zone = dnsapiIdentifier($options['zone'] ?? $domain);
		$config = dnsapiConfig();
		$settings = dnsapiSettings($config);
		$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(60));
		// Supply an expected value privately via environment; never emit it or TXT answers.
		$result = $dns->check('_acme-challenge.' . $domain, dnsapiEnv('DNSAPI_EXPECTED_TXT'), $settings['propagation_mode'] === 'strict' ? $dns->authorities($zone) : array());
		foreach($result['results'] as $diagnostic) echo json_encode(array('mode' => $settings['propagation_mode']) + $diagnostic, JSON_UNESCAPED_SLASHES) . PHP_EOL;
		if(isset($options['authorities']) && $settings['propagation_mode'] === 'recursive') {
			try {
				$extra = new DNSAPIDNS(array_replace($settings, array('propagation_mode' => 'strict')), new DNSAPIDeadline(60));
				foreach($extra->authorities($zone) as $server => $ips)
					foreach($ips as $ip) echo json_encode(array('mode' => 'recursive', 'diagnostic_only' => true) + $extra->summary($extra->query('_acme-challenge.' . $domain, 'TXT', $ip, true, dnsapiEnv('DNSAPI_EXPECTED_TXT')), 'authoritative', $server), JSON_UNESCAPED_SLASHES) . PHP_EOL;
			} catch(Throwable $error) { fwrite(STDERR, 'OPTIONAL_AUTHORITY_DIAGNOSIS_FAILED' . PHP_EOL); }
		}
		exit($result['success'] ? 0 : 1);
	} catch(Throwable $error) {
		fwrite(STDERR, 'DNS_DIAGNOSIS_FAILED: check configuration, delegation and DNS connectivity' . PHP_EOL);
		exit(1);
	}
