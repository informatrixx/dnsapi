<?php
	require_once __DIR__ . '/../bootstrap.php';
	require_once __DIR__ . '/Dns.php';

	final class DNSAPIRecords {
		function __construct(private $api, private string $zone) {}
		static function name(string $name, string $zone): string {
			$absolute = str_ends_with(trim($name), '.');
			$name = strtolower(rtrim(trim($name), '.'));
			if($name === '' || $name === '@' || $name === $zone) return '@';
			if($absolute && !str_ends_with($name, '.' . $zone)) throw new RuntimeException('RECORD_OUTSIDE_ZONE');
			if(str_ends_with($name, '.' . $zone)) $name = substr($name, 0, -strlen('.' . $zone));
			if(strlen($name . '.' . $zone) > 253 || !preg_match('/^(?:\*|[a-z0-9_-]{1,63})(?:\.[a-z0-9_-]{1,63})*$/D', $name)) throw new RuntimeException('RECORD_NAME_INVALID');
			return $name;
		}
		static function value(string $type, string $value): string {
			if($type === 'TXT' && str_starts_with($value, '"')) {
				$parsed = DNSAPIDNS::parse(";; ANSWER SECTION:\nx. 1 IN TXT " . $value, 'x');
				if(count($parsed['records']) !== 1) throw new RuntimeException('TXT_VALUE_INVALID');
				return $parsed['records'][0]['value'];
			}
			return $value;
		}
		static function validate(array $record): array {
			if(!is_string($record['name'] ?? null) || !is_string($record['type'] ?? null) || !is_string($record['value'] ?? null)) throw new RuntimeException('RECORD_INCOMPLETE');
			$record['type'] = strtoupper($record['type']);
			if(!preg_match('/^[A-Z][A-Z0-9]*$/D', $record['type']) || str_contains($record['value'], "\0") || str_contains($record['value'], "\n") || str_contains($record['value'], "\r")) throw new RuntimeException('RECORD_VALUE_INVALID');
			if(isset($record['ttl']) && (!is_int($record['ttl']) || $record['ttl'] < 60 || $record['ttl'] > 2147483647)) throw new RuntimeException('RECORD_TTL_INVALID');
			if(in_array($record['type'], array('A', 'AAAA'), true) && !filter_var($record['value'], FILTER_VALIDATE_IP, $record['type'] === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) throw new RuntimeException('RECORD_IP_INVALID');
			if($record['type'] === 'TLSA') {
				if(!preg_match('/^[0-3] [01] ([012]) ([a-fA-F0-9]+)$/D', $record['value'], $match)) throw new RuntimeException('RECORD_TLSA_INVALID');
				if(strlen($match[2]) % 2 !== 0 || ($match[1] === '1' && strlen($match[2]) !== 64) || ($match[1] === '2' && strlen($match[2]) !== 128)) throw new RuntimeException('RECORD_TLSA_INVALID');
			}
			return $record;
		}
		function snapshot(): array {
			$records = $this->api->getRecords();
			if(!is_array($records)) throw new RuntimeException('PROVIDER_READ_FAILED');
			$result = array();
			foreach($records as $record) {
				if(!is_array($record) || (!is_string($record['id'] ?? null) && !is_int($record['id'] ?? null)) || !is_string($record['name'] ?? $record['hostname'] ?? null) || !is_string($record['type'] ?? null)) throw new RuntimeException('PROVIDER_RECORD_INVALID');
				if(array_key_exists('records', $record) && (!is_array($record['records']) || !array_is_list($record['records']))) throw new RuntimeException('PROVIDER_RECORD_INVALID');
				$type = strtoupper($record['type']);
				foreach($record['records'] ?? array(array('value' => $record['value'] ?? $record['destination'] ?? null)) as $part) {
					if(!is_string($part['value'] ?? null)) throw new RuntimeException('PROVIDER_RECORD_INVALID');
					$value = self::value($type, $part['value']);
					if($type === 'MX' && isset($record['destination'])) $value = (int)($record['priority'] ?? 0) . ' ' . $value;
					$result[] = array('id' => (string)$record['id'], 'name' => self::name($record['name'] ?? $record['hostname'], $this->zone), 'type' => $type, 'value' => $value, 'ttl' => $record['ttl'] ?? null);
				}
			}
			return $result;
		}
		function plan(string $action, array $record, bool $wholeSet = false): array {
			if(!in_array($action, array('create', 'set', 'update', 'delete'), true)) throw new RuntimeException('ACTION_INVALID');
			if($wholeSet && ($action === 'create' || isset($record['id']) || ($action === 'delete' && isset($record['value'])))) throw new RuntimeException('ARGUMENT_CONFLICT');
			if($action === 'create' && isset($record['id'])) throw new RuntimeException('ARGUMENT_CONFLICT');
			$all = $this->snapshot();
			if(isset($record['id'])) {
				$selected = array_values(array_filter($all, fn($r) => $r['id'] === $record['id']));
				if(!$selected) throw new RuntimeException('RECORD_NOT_FOUND');
				$record += array('name' => $selected[0]['name'], 'type' => $selected[0]['type']);
			}
			if(!isset($record['name'], $record['type'])) throw new RuntimeException('RECORD_INCOMPLETE');
			$record['name'] = self::name($record['name'], $this->zone);
			$record['type'] = strtoupper($record['type']);
			if(isset($selected) && ($record['name'] !== $selected[0]['name'] || $record['type'] !== $selected[0]['type'])) throw new RuntimeException('RECORD_SELECTOR_MISMATCH');
			$before = array_values(array_filter($all, fn($r) => $r['name'] === $record['name'] && $r['type'] === $record['type']));
			if($action !== 'delete') $record = self::validate($record);
			if(isset($record['value'])) $record['value'] = self::value($record['type'], $record['value']);
			$exact = array_values(array_filter($before, fn($r) => isset($record['value']) && $r['value'] === $record['value']));
			if($action === 'update' && !$before) throw new RuntimeException('RECORD_NOT_FOUND');
			if(!$wholeSet && count($before) > 1 && !$exact && !isset($record['id']) && $action !== 'create') throw new RuntimeException('RECORD_AMBIGUOUS: use --replace-rrset or an exact value/ID');
			if(!$wholeSet && isset($record['id']) && count(array_filter($before, fn($r) => $r['id'] === $record['id'])) > 1 && !$exact && $action !== 'create') throw new RuntimeException('RECORD_AMBIGUOUS');
			$targets = isset($record['id']) ? array_values(array_filter($before, fn($r) => $r['id'] === $record['id'])) : $before;
			$targetExact = array_values(array_filter($targets, fn($r) => isset($record['value']) && $r['value'] === $record['value']));
			if(isset($record['id']) && $exact && !$targetExact) throw new RuntimeException('RECORD_SELECTOR_MISMATCH');
			if(!$wholeSet && count($targets) > 1 && in_array($action, array('set', 'update'), true) && isset($record['ttl'])) throw new RuntimeException('RECORD_AMBIGUOUS');
			if($action === 'delete') {
				$targets = isset($record['value']) ? $targetExact : $targets;
				$after = array_values(array_filter($before, fn($r) => !in_array($r, $targets, true)));
			} elseif($action === 'create') $after = $exact ? $before : array_merge($before, array($record));
			elseif($wholeSet) $after = array($record);
			else $after = array_merge(array_values(array_filter($before, fn($r) => !in_array($r, $targetExact ?: $targets, true))), array($record));
			return array('action' => $action, 'record' => $record, 'whole_set' => $wholeSet, 'before' => $before, 'after' => $after, 'targets' => $targets, 'warnings' => $this->api instanceof netcupAPI && isset($record['ttl']) ? array('NETCUP_ZONE_TTL_UNCHANGED') : array());
		}
		function apply(array $plan): void {
			// Re-read before mutation: a reviewed plan must not silently replace concurrent changes.
			$fresh = $this->plan($plan['action'], $plan['record'], $plan['whole_set']);
			if($fresh['before'] !== $plan['before']) throw new RuntimeException('RECORD_PLAN_STALE');
			$record = $plan['record'];
			$exact = array_values(array_filter($plan['before'], fn($r) => isset($record['value']) && $r['value'] === $record['value']));
			if($plan['action'] === 'create' && $exact) return;
			if(!$plan['whole_set'] && in_array($plan['action'], array('set', 'update'), true) && $exact && !isset($record['ttl'])) return;
			if($plan['action'] === 'delete') {
				foreach($plan['targets'] as $target) if(!$this->api->deleteRecord(record: $target, value: $target['value'])) throw new RuntimeException('PROVIDER_DELETE_FAILED');
			} elseif($plan['whole_set']) {
				if($this->api instanceof hetznerAPI) {
					if(!$this->api->createRecord(record: $record)) throw new RuntimeException('PROVIDER_CREATE_FAILED');
					foreach($plan['before'] as $old) if($old['value'] !== $record['value'] && !$this->api->deleteRecord(record: $old, value: $old['value'])) throw new RuntimeException('PROVIDER_DELETE_FAILED');
				} elseif(!$this->api->multiSetRecords($plan['after'])) throw new RuntimeException('PROVIDER_REPLACE_FAILED');
			} elseif($plan['action'] === 'create') {
				if(!$this->api->createRecord(record: $record)) throw new RuntimeException('PROVIDER_CREATE_FAILED');
			} else {
				if(count($plan['before']) > 1 && !isset($record['id'])) {
					$exact = array_values(array_filter($plan['before'], fn($r) => $r['value'] === $record['value']));
					if($exact && !isset($record['ttl'])) return;
					throw new RuntimeException('RECORD_AMBIGUOUS');
				}
				$method = $plan['action'] === 'update' ? 'updateRecord' : 'setRecord';
				if(!$this->api->$method(record: $record)) throw new RuntimeException('PROVIDER_UPDATE_FAILED');
			}
			$after = array_values(array_filter($this->snapshot(), fn($r) => $r['name'] === $record['name'] && $r['type'] === $record['type']));
			$values = fn($records) => array_values(array_unique(array_column($records, 'value')));
			$expected = $values($plan['after']); $actual = $values($after);
			sort($expected); sort($actual);
			if($expected !== $actual) throw new RuntimeException('PROVIDER_CHANGE_UNCONFIRMED');
			if($plan['action'] !== 'delete' && isset($record['ttl']) && !($this->api instanceof netcupAPI))
				foreach($after as $current) if($current['value'] === $record['value'] && $current['ttl'] !== $record['ttl']) throw new RuntimeException('PROVIDER_TTL_UNCONFIRMED');
		}
	}
