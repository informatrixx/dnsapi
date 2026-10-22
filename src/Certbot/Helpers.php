<?php
	require_once __DIR__ . '/../bootstrap.php';
	require_once __DIR__ . '/../Runtime/Journal.php';
	require_once __DIR__ . '/../Dns/Dns.php';
	function dnsapiChallengeTTL(array $records, string $zone, string $identifier): ?int {
		foreach($records as $record) {
			if(!is_array($record) || ($record['type'] ?? '') !== 'TXT' || !isset($record['records'])) continue;
			$name = strtolower(rtrim($record['name'] ?? '', '.'));
			if($name !== $zone && !str_ends_with($name, '.' . $zone)) $name .= '.' . $zone;
			if($name !== '_acme-challenge.' . $identifier) continue;
			if(!array_key_exists('ttl', $record) || ($record['ttl'] !== null && (!is_int($record['ttl']) || $record['ttl'] < 60))) throw new RuntimeException('PROVIDER_TTL_INVALID');
			return $record['ttl'];
		}
		return 120;
	}
	function dnsapiBatchEntries(array $journal, string $run, int $expected): array {
		$entries = array_filter($journal, fn($entry) => is_array($entry) && ($entry['run'] ?? '') === $run);
		if($expected < 1 || count($entries) !== $expected) throw new RuntimeException('CHALLENGE_BATCH_INCOMPLETE');
		foreach($entries as $entry)
			if(($entry['phase'] ?? '') !== 'created') throw new RuntimeException('CHALLENGE_BATCH_INCOMPLETE');
		return $entries;
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
				if(DNSAPITXT::value($actual) !== $value) continue;
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
		foreach($entries as $entry) {
			if(!is_array($entry) || !is_string($entry['CERTBOT_IDENTIFIER'] ?? null) || !is_string($entry['CERTBOT_VALIDATION'] ?? null) || !preg_match('/^[A-Za-z0-9_-]+$/D', $entry['CERTBOT_VALIDATION']) || ($entry['phase'] ?? '') !== 'created') throw new RuntimeException('CHALLENGE_ENTRY_INVALID');
			dnsapiIdentifier($entry['CERTBOT_IDENTIFIER']);
		}
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
