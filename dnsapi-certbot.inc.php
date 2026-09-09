<?php
	require_once __DIR__ . '/dnsapi-journal.inc.php';
	require_once __DIR__ . '/dnsapi-dns.inc.php';

	interface DNSAPIv1 {
		function __construct(string $zoneID, array $APIConfig);
		function createRecord(?string $name, ?string $type, ?string $value, ?int $ttl, ?array $record);
		function deleteRecord(?string $name, ?string $type, ?string $id, ?array $record, ?string $value);
		function getRecords();
	}

	function dnsapiEnv(string $name, string $fallback = ''): string {
		$value = getenv($name);
		return $value === false || $value === '' ? $fallback : $value;
	}
	function dnsapiSettings(array $config): array {
		$settings = $config['certbot'] ?? array();
		if(!is_array($settings)) throw new RuntimeException('CONFIG_CERTBOT_INVALID');
		$settings += array('propagation_mode' => 'strict');
		if(!in_array($settings['propagation_mode'], array('strict', 'recursive'), true)) throw new RuntimeException('CONFIG_PROPAGATION_MODE_INVALID');
		$settings += array('propagation_timeout_seconds' => 1200, 'dns_timeout_seconds' => 3, 'poll_interval_seconds' => 10, 'discovery_resolver' => '1.1.1.1', 'resolvers' => array('1.1.1.1', '8.8.8.8', '9.9.9.9'));
		foreach(array('propagation_timeout_seconds' => 7200, 'dns_timeout_seconds' => 30, 'poll_interval_seconds' => 300) as $name => $max)
			if(!is_int($settings[$name]) || $settings[$name] < 1 || $settings[$name] > $max) throw new RuntimeException('CONFIG_TIME_LIMIT_INVALID');
		if(!is_array($settings['resolvers']) || !$settings['resolvers'] || count($settings['resolvers']) > 10) throw new RuntimeException('CONFIG_RESOLVERS_INVALID');
		foreach(array_merge(array($settings['discovery_resolver']), $settings['resolvers']) as $ip)
			if(!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('CONFIG_RESOLVER_MUST_BE_IP');
		return $settings;
	}
	function dnsapiConfig(): array {
		try {
			$config = json_decode((string)file_get_contents(dnsapiEnv('DNSAPI_CONFIG_FILE', '/etc/dnsapi/config.json')), true, 512, JSON_THROW_ON_ERROR);
		} catch(Throwable $error) { throw new RuntimeException('CONFIG_READ_FAILED'); }
		if(!is_array($config) || !is_array($config['domains'] ?? null) || !is_array($config['API'] ?? null)) throw new RuntimeException('CONFIG_INVALID');
		dnsapiSettings($config);
		return $config;
	}
	function dnsapiIdentifier(string $identifier): string {
		$identifier = strtolower(rtrim($identifier, '.'));
		if(str_starts_with($identifier, '*.')) $identifier = substr($identifier, 2);
		if(strlen($identifier) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $identifier)) throw new RuntimeException('IDENTIFIER_INVALID');
		return $identifier;
	}
	function dnsapiOwner(): string {
		if(dnsapiEnv('DNSAPI_RUN_ID') !== '') return hash('sha256', dnsapiEnv('DNSAPI_RUN_ID'));
		$pid = getmypid();
		for($depth = 0; $depth < 16 && $pid > 1; $depth++) {
			$stat = @file_get_contents('/proc/' . $pid . '/stat');
			$cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
			if(!is_string($stat) || !is_string($cmd)) break;
			$fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
			foreach(explode("\0", $cmd) as $arg)
				if(basename($arg) === 'certbot') return hash('sha256', gethostname() . ':' . $pid . ':' . ($fields[19] ?? ''));
			$pid = (int)($fields[1] ?? 0);
		}
		throw new RuntimeException('RUN_ID_REQUIRED: set DNSAPI_RUN_ID uniquely for this Certbot invocation');
	}
	function dnsapiProvider(array $config, string $identifier, array &$apis): array {
		$zone = $identifier;
		while(!isset($config['domains'][$zone])) {
			if(!str_contains($zone, '.')) throw new RuntimeException('DOMAIN_NOT_CONFIGURED');
			$zone = substr($zone, strpos($zone, '.') + 1);
		}
		$mapping = $config['domains'][$zone];
		$alias = $mapping['API'] ?? '';
		$providers = array('hetzner' => 'Hetzner', 'hetznercloud' => 'HetznerCloud', 'netcup' => 'Netcup');
		if(!is_string($alias) || !isset($providers[strtolower($alias)]) || !is_array($config['API'][$alias] ?? null) || !is_string($mapping['zoneID'] ?? null) || $mapping['zoneID'] === '') throw new RuntimeException('PROVIDER_CONFIG_INVALID');
		$provider = $providers[strtolower($alias)];
		$options = $config['API'][$alias];
		$url = $options[$provider === 'Netcup' ? 'endpointURL' : 'recordsURL'] ?? '';
		$urls = array('HetznerCloud' => 'https://api.hetzner.cloud/v1/zones', 'Hetzner' => 'https://dns.hetzner.com/api/v1/records', 'Netcup' => 'https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON');
		if(!is_string($url) || rtrim($url, '/') !== $urls[$provider]) throw new RuntimeException('PROVIDER_URL_INVALID');
		foreach($provider === 'Netcup' ? array('KundenNR', 'APIKey', 'APIPass') : array('APIToken') as $field)
			if(!isset($options[$field]) || !is_scalar($options[$field]) || (string)$options[$field] === '') throw new RuntimeException('PROVIDER_CREDENTIALS_MISSING');
		$key = $alias . ':' . $mapping['zoneID'];
		if(!isset($apis[$key])) {
			require_once __DIR__ . '/dnsapi-' . $provider . '.inc.php';
			$class = $provider . 'API';
			$factory = $GLOBALS['dnsapiProviderFactory'] ?? fn($class, $zoneID, $options) => new $class($zoneID, $options);
			$apis[$key] = $factory($class, $mapping['zoneID'], $options);
		}
		return array('zone' => $zone, 'api' => $apis[$key], 'key' => $key);
	}
	function dnsapiFindChallenge(array $records, string $zone, string $identifier, string $value): ?array {
		$name = '_acme-challenge.' . $identifier;
		foreach($records as $record) {
			if(!is_array($record) || strtoupper($record['type'] ?? '') !== 'TXT') continue;
			$owner = strtolower(rtrim($record['name'] ?? $record['hostname'] ?? '', '.'));
			if($owner === '@' || $owner === '') $owner = $zone;
			elseif($owner !== $zone && !str_ends_with($owner, '.' . $zone)) $owner .= '.' . $zone;
			if($owner !== $name) continue;
			foreach($record['records'] ?? array(array('value' => $record['value'] ?? $record['destination'] ?? null)) as $part) {
				if(!is_string($part['value'] ?? null)) continue;
				$actual = $part['value'];
				if(str_starts_with($actual, '"')) {
					$parsed = DNSAPIDNS::parse(";; ANSWER SECTION:\n" . $name . '. 120 IN TXT ' . $actual, $name, $value);
					if(!$parsed['matched']) continue;
				} elseif($actual !== $value) continue;
				return array('id' => (string)$record['id'], 'name' => $record['name'] ?? $record['hostname'], 'type' => 'TXT', 'value' => $actual, 'state' => $record['state'] ?? null);
			}
		}
		return null;
	}
	function dnsapiCleanupEntry(array $entry, $api, string $zone, bool $adoptLegacy = false): void {
		$identifier = dnsapiIdentifier($entry['CERTBOT_IDENTIFIER'] ?? $entry['CERTBOT_DOMAIN'] ?? '');
		$value = $entry['CERTBOT_VALIDATION'] ?? null;
		if(!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+$/D', $value) || !is_array($entry['record'] ?? null) || !is_string($entry['record']['value'] ?? null) || $entry['record']['value'] === '') throw new RuntimeException('CLEANUP_ENTRY_INCOMPLETE');
		if(dnsapiFindChallenge(array($entry['record'] + array('id' => 'intent')), $zone, $identifier, $value) === null) throw new RuntimeException('CLEANUP_ENTRY_MISMATCH');
		if(!array_key_exists('owned', $entry) && !$adoptLegacy) throw new RuntimeException('CLEANUP_LEGACY_OWNERSHIP_UNKNOWN');
		if(array_key_exists('owned', $entry) && !is_bool($entry['owned'])) throw new RuntimeException('CLEANUP_OWNERSHIP_INVALID');
		if(($entry['owned'] ?? true) === false) return;
		$records = $api->getRecords();
		if(!is_array($records)) throw new RuntimeException('CLEANUP_API_READ_FAILED');
		$record = dnsapiFindChallenge($records, $zone, $identifier, $value);
		if($record === null) return;
		if(!$api->deleteRecord(record: $record, value: $record['value'])) throw new RuntimeException('CLEANUP_API_DELETE_FAILED');
		$records = $api->getRecords();
		if(!is_array($records) || dnsapiFindChallenge($records, $zone, $identifier, $value) !== null) throw new RuntimeException('CLEANUP_NOT_CONFIRMED');
	}
	function dnsapiCleanup(DNSAPIJournal $journal, array $config, array $ids, bool $adoptLegacy = false): bool {
		$apis = array();
		$ok = true;
		foreach($ids as $id) {
			if(!array_key_exists($id, $journal->data['journal'])) continue;
			try {
				$entry = $journal->data['journal'][$id];
				if(!is_array($entry)) throw new RuntimeException('CLEANUP_ENTRY_INVALID');
				$data = dnsapiProvider($config, dnsapiIdentifier($entry['CERTBOT_IDENTIFIER'] ?? $entry['CERTBOT_DOMAIN'] ?? ''), $apis);
				if(isset($entry['provider_key']) && ($entry['provider_key'] !== $data['key'] || ($entry['zone'] ?? '') !== $data['zone'])) throw new RuntimeException('CLEANUP_CONFIG_CHANGED');
				dnsapiCleanupEntry($entry, $data['api'], $data['zone'], $adoptLegacy);
				unset($journal->data['journal'][$id]);
			} catch(Throwable $error) {
				$ok = false;
				if(is_array($journal->data['journal'][$id])) $journal->data['journal'][$id]['cleanup_failed'] = true;
				$code = preg_match('/^[A-Z_]+/', $error->getMessage(), $match) ? $match[0] : 'CLEANUP_FAILED';
				fwrite(STDERR, 'CLEANUP_PENDING code=' . $code . ': entry retained for recovery' . PHP_EOL);
			}
			$journal->save();
		}
		foreach($journal->data['batches'] ?? array() as $owner => $batch) {
			$remaining = array_filter($journal->data['journal'], fn($entry) => is_array($entry) && ($entry['run'] ?? null) === ($batch['run'] ?? null));
			if(!$remaining) unset($journal->data['batches'][$owner]);
		}
		$journal->save();
		return $ok;
	}
	function dnsapiWait(array $config, array $entries, DNSAPIDeadline $deadline): void {
		if(!$entries) throw new RuntimeException('NO_CHALLENGES_TO_VERIFY');
		$settings = dnsapiSettings($config);
		$dns = new DNSAPIDNS($settings, $deadline);
		$apis = $servers = array();
		do {
			$deadline->check();
			$all = true;
			$records = array();
			foreach($entries as $entry) {
				$identifier = dnsapiIdentifier($entry['CERTBOT_IDENTIFIER']);
				$data = dnsapiProvider($config, $identifier, $apis);
				if(!array_key_exists($data['key'], $records)) $records[$data['key']] = $data['api']->getRecords();
				$record = is_array($records[$data['key']]) ? dnsapiFindChallenge($records[$data['key']], $data['zone'], $identifier, $entry['CERTBOT_VALIDATION']) : null;
				$active = $record !== null && ($record['state'] === null || $record['state'] === 'yes');
				$servers[$data['zone']] ??= $settings['propagation_mode'] === 'strict' ? $dns->authorities($data['zone']) : array();
				$result = $dns->check('_acme-challenge.' . $identifier, $entry['CERTBOT_VALIDATION'], $servers[$data['zone']]);
				foreach($result['results'] as $diagnostic)
					fwrite(STDERR, json_encode(array('event' => 'PROPAGATION', 'mode' => $settings['propagation_mode'], 'identifier' => $identifier, 'provider_active' => $active, 'remaining_seconds' => (int)$deadline->remaining()) + $diagnostic, JSON_UNESCAPED_SLASHES) . PHP_EOL);
				$all = $all && $active && $result['success'];
			}
			if($all) return;
			$deadline->pause($settings['poll_interval_seconds']);
		} while(true);
	}
