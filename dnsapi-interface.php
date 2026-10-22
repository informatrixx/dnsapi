#!/usr/bin/php
<?php
	require_once __DIR__ . '/src/bootstrap.php';
	require_once __DIR__ . '/src/Dns/Records.php';
	require_once __DIR__ . '/src/Cli/Arguments.php';
	try {
		$options = DNSAPIArguments::options($argv, 'x:c:d:p::s:u:h', array('domain:', 'create:', 'delete:', 'print::', 'set:', 'update:', 'help', 'action:', 'name:', 'type:', 'value:', 'ttl:', 'dry-run', 'json', 'replace-rrset', 'config-check', 'version'));
		if(isset($options['h']) || isset($options['help'])) {
			echo "dnsapi-interface.php -x ZONE -p[TYPE]\n  -c/--create, -s/--set, -u/--update name:TYPE=value[:ttl]\n  -d/--delete name:TYPE[=value] or ?ID\n  --action create|set|update|delete --name NAME --type TYPE --value VALUE --ttl 600\n  --dry-run: provider reads only; show planned changes\n  --replace-rrset: explicitly replace/delete all values at this name/type\n  --json, --config-check, --version\nAAAA values use --ttl instead of :ttl. Multiple values are never implicitly replaced.\n";
			exit(0);
		}
		if(isset($options['version'])) { echo trim(file_get_contents(__DIR__ . '/VERSION')) . PHP_EOL; exit(0); }
		$config = dnsapiConfig();
		if(isset($options['config-check'])) { echo json_encode(array('ok' => true, 'domains' => count($config['domains']), 'network_access' => false)) . PHP_EOL; exit(0); }
		if(isset($options['x'], $options['domain']) || isset($options['p'], $options['print'])) throw new RuntimeException('ARGUMENT_CONFLICT');
		$domain = dnsapiIdentifier($options['x'] ?? $options['domain'] ?? '');
		$actions = array();
		foreach(array('c' => 'create', 's' => 'set', 'u' => 'update', 'd' => 'delete') as $short => $long) {
			if(isset($options[$short], $options[$long])) throw new RuntimeException('ARGUMENT_CONFLICT');
			foreach((array)($options[$short] ?? $options[$long] ?? array()) as $input) $actions[] = array($long, $input);
		}
		if(isset($options['action'])) {
			if($actions) throw new RuntimeException('ARGUMENT_CONFLICT');
			$actions[] = array($options['action'], ($options['name'] ?? '') . ':' . ($options['type'] ?? ''));
		}
		$print = isset($options['p']) || isset($options['print']);
		if(!$print && !$actions) throw new RuntimeException('ACTION_REQUIRED');
		if($print && $actions) throw new RuntimeException('ARGUMENT_CONFLICT');
		if(isset($options['ttl']) && (!is_string($options['ttl']) || !ctype_digit($options['ttl']))) throw new RuntimeException('RECORD_TTL_INVALID');
		$parsed = array();
		foreach($actions as [$action, $input]) $parsed[] = array($action, DNSAPIArguments::record($input, $action, $options['value'] ?? null, isset($options['ttl']) ? (int)$options['ttl'] : null));
		$apis = array();
		$data = dnsapiProvider($config, $domain, $apis);
		$service = new DNSAPIRecords($data['api'], $data['zone']);
		if($print) {
			$type = $options['p'] ?? $options['print'];
			$records = array_values(array_filter($service->snapshot(), fn($r) => !$type || $r['type'] === strtoupper($type)));
			if(isset($options['json'])) echo json_encode($records, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
			else print_r($records);
		} else {
			$plans = array();
			$seen = array();
			foreach($parsed as [$action, $record]) {
				$plan = $service->plan($action, $record, isset($options['replace-rrset']));
				$key = $plan['record']['name'] . '/' . $plan['record']['type'];
				if(isset($seen[$key])) throw new RuntimeException('DUPLICATE_RRSET_IN_BATCH: use separate calls');
				$seen[$key] = true;
				$plans[] = $plan;
				foreach($plan['warnings'] as $warning) fwrite(STDERR, $warning . PHP_EOL);
			}
			if(!isset($options['dry-run'])) foreach($plans as $plan) $service->apply($plan);
			if(isset($options['json']) || isset($options['dry-run'])) echo json_encode(array('ok' => true, 'dry_run' => isset($options['dry-run']), 'changes' => $plans), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
			else echo 'OK' . PHP_EOL;
		}
	} catch(Throwable $error) {
		$code = dnsapiError($error);
		fwrite(STDERR, isset($options['json']) ? json_encode(array('ok' => false, 'error' => $code)) . PHP_EOL : $code . PHP_EOL);
		exit(1);
	}
