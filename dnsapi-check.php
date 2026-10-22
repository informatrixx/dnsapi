#!/usr/bin/php
<?php
	require_once __DIR__ . '/src/Dns/MailAudit.php';
	require_once __DIR__ . '/src/Cli/Arguments.php';
	try {
		$options = DNSAPIArguments::options($argv, '', array('domain:', 'resolver:', 'dkim-selector:', 'mail-host:', 'help'));
		if(isset($options['help'])) { echo "dnsapi-check.php --domain example.com [--resolver 1.1.1.1] [--dkim-selector default] [--mail-host mail.example.com]\nRead-only DNS inventory, JSON output; exit 2 means attention/skipped checks.\n"; exit(0); }
		$resolver = $options['resolver'] ?? '1.1.1.1';
		if(!is_string($resolver) || !filter_var($resolver, FILTER_VALIDATE_IP)) throw new RuntimeException('DNS_SERVER_MUST_BE_IP');
		$settings = dnsapiSettings(array());
		$audit = new DNSAPIMailAudit(new DNSAPIDNS($settings, new DNSAPIDeadline(60)), $resolver);
		$result = $audit->check($options['domain'] ?? '', $options['dkim-selector'] ?? null, $options['mail-host'] ?? null);
		echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
		exit(count(array_filter($result['checks'], fn($check) => !in_array($check['status'], array('present', 'resolver_validated'), true))) ? 2 : 0);
	} catch(Throwable $error) {
		fwrite(STDERR, json_encode(array('ok' => false, 'error' => dnsapiError($error))) . PHP_EOL);
		exit(1);
	}
