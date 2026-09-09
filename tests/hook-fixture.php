<?php
	// Loaded only by the offline subprocess tests, never by a production hook.
	class HookFixtureAPI {
		private string $path;
		function __construct() { $this->path = getenv('DNSAPI_FIXTURE_STATE'); }
		function getRecords() {
			if(getenv('DNSAPI_FIXTURE_FAILURE') === 'read') return false;
			return file_exists($this->path) ? json_decode(file_get_contents($this->path), true) : array();
		}
		function createRecord($name = null, $type = null, $value = null, $ttl = null, $record = null) {
			$journal = json_decode(file_get_contents(getenv('DNSAPI_JOURNAL_FILE')), true);
			$prepared = array_filter($journal['journal'], fn($entry) => $entry['phase'] === 'prepared' && $entry['CERTBOT_VALIDATION'] === $value);
			if(!$prepared) throw new RuntimeException('NO_PREPARED_INTENT');
			$records = $this->getRecords();
			$id = hash('sha256', $value);
			$record = array('id' => $id, 'name' => $name, 'type' => $type, 'value' => $value);
			$records[$id] = $record;
			file_put_contents($this->path, json_encode($records));
			if(getenv('DNSAPI_FIXTURE_FAILURE') === 'crash') exit(70);
			return $record;
		}
		function deleteRecord($record = null, $value = null) {
			if(!is_string($value) || $value === '') throw new RuntimeException('UNSAFE_DELETE');
			$records = $this->getRecords();
			unset($records[$record['id']]);
			file_put_contents($this->path, json_encode($records));
			return true;
		}
	}
	$GLOBALS['dnsapiProviderFactory'] = fn() => new HookFixtureAPI();
	$GLOBALS['dnsapiDNSRunner'] = function($args, $timeout) {
		$type = $args[6];
		$name = $args[4];
		$answers = '';
		if($type === 'NS') $answers = "$name 120 IN NS ns.example.test.\n";
		if($type === 'A') $answers = "$name 120 IN A 192.0.2.53\n";
		if($type === 'TXT') {
			$api = new HookFixtureAPI();
			foreach($api->getRecords() ?: array() as $record)
				$answers .= "$name 120 IN TXT \"" . $record['value'] . "\"\n";
		}
		if($type === 'TXT' && getenv('DNSAPI_FIXTURE_FAILURE') === 'dns') $answers = '';
		return array('output' => ";; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: 1\n;; flags: qr aa; QUERY: 1\n;; ANSWER SECTION:\n" . $answers, 'exit' => 0, 'duration_ms' => 1);
	};
