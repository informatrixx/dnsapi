#!/usr/bin/php
<?php
	require_once __DIR__ . '/src/Tlsa/Tlsa.php';
	require_once __DIR__ . '/src/Cli/Arguments.php';
	try {
		$options = DNSAPIArguments::options($argv, '', array('lineage:', 'certificate:', 'domains:', 'mode:', 'old-value:', 'confirm-rollover-complete', 'dry-run', 'json', 'help'));
		if(isset($options['help'])) {
			echo "TLSA: --lineage PATH --certificate CERT.pem --domains 'mail.example.com'\n  --mode publish (default): add current/new key, preserve previous values\n  --mode retire --old-value '3 1 1 HASH': remove only an explicitly selected old key\n  --confirm-rollover-complete: required to apply retirement after the TTL/activation checks\n  --dry-run --json: show changes without DNS writes\nPublish the future key before activation; see docs/tlsa.md for the required TTL wait.\n";
			exit(0);
		}
		$lineage = $options['lineage'] ?? dnsapiEnv('RENEWED_LINEAGE');
		if(!is_string($lineage) || $lineage === '') throw new RuntimeException('TLSA_LINEAGE_REQUIRED');
		$mode = $options['mode'] ?? 'publish';
		if(!in_array($mode, array('publish', 'retire'), true)) throw new RuntimeException('TLSA_MODE_INVALID');
		if($mode === 'retire' && !isset($options['dry-run']) && !isset($options['confirm-rollover-complete'])) throw new RuntimeException('TLSA_ROLLOVER_CONFIRMATION_REQUIRED');
		$config = dnsapiConfig();
		$targets = DNSAPITLSA::targets($config, $lineage, $options['domains'] ?? dnsapiEnv('RENEWED_DOMAINS'));
		if(!$targets) { echo json_encode(array('ok' => true, 'skipped' => true, 'reason' => 'NO_TLSA_TARGETS')) . PHP_EOL; exit(0); }
		$value = '3 1 1 ' . DNSAPITLSA::hash($options['certificate'] ?? $lineage . '/cert.pem');
		$old = $options['old-value'] ?? '';
		if($mode === 'retire' && (!is_string($old) || !preg_match('/^3 1 1 [a-f0-9]{64}$/D', $old) || $old === $value)) throw new RuntimeException('TLSA_RETIRE_VALUE_INVALID');
		$apis = $plans = array();
		foreach($targets as $target) {
			$data = dnsapiProvider($config, $target['host'], $apis);
			$service = new DNSAPIRecords($data['api'], $data['zone']);
			$record = array('name' => $target['name'], 'type' => 'TLSA', 'value' => $mode === 'retire' ? $old : $value);
			$plan = $service->plan($mode === 'retire' ? 'delete' : 'create', $record);
			if($mode === 'retire' && !in_array($value, array_column($plan['after'], 'value'), true)) throw new RuntimeException('TLSA_CURRENT_KEY_NOT_PUBLISHED');
			$plans[] = array('service' => $service, 'plan' => $plan, 'host' => $target['host']);
		}
		if(!isset($options['dry-run'])) foreach($plans as $item) $item['service']->apply($item['plan']);
		echo json_encode(array('ok' => true, 'mode' => $mode, 'dry_run' => isset($options['dry-run']), 'changes' => array_map(fn($item) => array('host' => $item['host'], 'plan' => $item['plan']), $plans)), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
	} catch(Throwable $error) {
		fwrite(STDERR, json_encode(array('ok' => false, 'error' => dnsapiError($error))) . PHP_EOL);
		exit(1);
	}
