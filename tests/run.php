<?php
	error_reporting(E_ALL);
	set_error_handler(function($severity, $message, $file, $line) {
		if(!(error_reporting() & $severity)) return false;
		throw new ErrorException($message, 0, $severity, $file, $line);
	});
	require_once __DIR__ . '/../dnsapi-certbot.inc.php';
	$count = 0;
	function check(bool $condition, string $label): void {
		global $count;
		if(!$condition) throw new RuntimeException('FAIL: ' . $label);
		$count++;
	}
	function rejects(callable $call, string $label): void {
		try { $call(); } catch(Throwable $error) { check(true, $label); return; }
		check(false, $label);
	}
	function answer(string $rcode = 'NOERROR', string $flags = 'qr aa', string $records = '', string $authority = ''): string {
		return ";; ->>HEADER<<- opcode: QUERY, status: $rcode, id: 1\n;; flags: $flags; QUERY: 1, ANSWER: 1\n;; ANSWER SECTION:\n$records\n;; AUTHORITY SECTION:\n$authority\n";
	}
	$name = '_acme-challenge.example.test';
	$txt = "$name. 120 IN TXT ";
	$result = DNSAPIDNS::parse(answer(records: $txt . '"foreign"' . "\n" . $txt . '"expec" "ted"'), $name, 'expected');
	check($result['matched'] && $result['ttl'] === 120, 'TXT character strings within one record');
	check(!DNSAPIDNS::parse(answer(records: $txt . '"expec"' . "\n" . $txt . '"ted"'), $name, 'expected')['matched'], 'never join separate TXT records');
	check(DNSAPIDNS::parse(answer(records: $txt . '"expec\\116ed"'), $name, 'expected')['matched'], 'DNS decimal escape');
	check(!DNSAPIDNS::parse(answer(records: 'other.test. 120 IN TXT "expected"'), $name, 'expected')['matched'], 'reject other owner');
	$negative = answer('NXDOMAIN', 'qr rd ra', '', 'example.test. 3400 IN SOA ns.example.test. hostmaster.example.test. 1 2 3 4 3600');
	$result = DNSAPIDNS::parse($negative, $name, 'expected');
	check($result['negative_ttl'] === 3400 && !$result['aa'] && $result['ra'], 'negative TTL and flags');
	$settings = dnsapiSettings(array());
	rejects(fn() => dnsapiWait(array(), array(), new DNSAPIDeadline(1)), 'empty verification never passes');
	rejects(fn() => dnsapiWait(array(), array(array('CERTBOT_IDENTIFIER' => 'example.test', 'CERTBOT_VALIDATION' => '', 'phase' => 'created')), new DNSAPIDeadline(1)), 'empty expected TXT fails before DNS or provider access');
	rejects(fn() => dnsapiWait(array(), array(array('CERTBOT_IDENTIFIER' => 'example.test', 'CERTBOT_VALIDATION' => 'token', 'phase' => 'prepared')), new DNSAPIDeadline(1)), 'unconfirmed operation never passes verification');
	check($settings['propagation_mode'] === 'strict', 'strict mode remains default');
	rejects(fn() => dnsapiSettings(array('certbot' => array('propagation_mode' => 'fallback'))), 'unknown mode rejected');
	$recursive = array_replace($settings, array('propagation_mode' => 'recursive'));
	$dns = new DNSAPIDNS($recursive, new DNSAPIDeadline(5), function($args) use ($txt) {
		check(!in_array('+norecurse', $args, true), 'recursive mode never queries authorities');
		return array('output' => answer(flags: 'qr rd ra', records: $txt . '"expected"'), 'exit' => 0, 'duration_ms' => 1);
	});
	check($dns->check($name, 'expected', array('ns' => array('192.0.2.1')))['success'], 'recursive mode accepts exact value without AA');
	$dns = new DNSAPIDNS($recursive, new DNSAPIDeadline(5), fn($args) => array('output' => answer(flags: 'qr rd ra', records: $txt . ($args[2] === '@8.8.8.8' ? '"old"' : '"expected"')), 'exit' => 0, 'duration_ms' => 1));
	check(!$dns->check($name, 'expected', array())['success'], 'every configured resolver must match');
	$dns = new DNSAPIDNS($recursive, new DNSAPIDeadline(5), fn($args) => $args[2] === '@8.8.8.8' ? array('output' => 'timed out', 'exit' => 9, 'duration_ms' => 1) : array('output' => answer(flags: 'qr rd ra', records: $txt . '"expected"'), 'exit' => 0, 'duration_ms' => 1));
	check(!$dns->check($name, 'expected', array())['success'], 'missing mandatory resolver prevents recursive success');
	foreach(array(array('name' => '_acme-challenge', 'type' => 'CNAME'), array('hostname' => '_acme-challenge', 'type' => 'NS'), array('name' => '@', 'type' => 'DNAME')) as $record)
		rejects(fn() => DNSAPIDNS::providerPreflight(array($record), 'example.test', $name), 'provider preflight rejects delegation ' . $record['type']);
	DNSAPIDNS::providerPreflight(array(array('name' => '@', 'type' => 'NS'), array('name' => 'other', 'type' => 'CNAME')), 'example.test', $name);
	check(true, 'zone NS and unrelated CNAME allowed');
	rejects(fn() => DNSAPIDNS::providerPreflight(array(array('name' => 'mail', 'type' => 'NS')), 'example.test', '_acme-challenge.mail.example.test'), 'ancestor NS delegation rejected');
	rejects(fn() => DNSAPIDNS::providerPreflight(array(array('hostname' => 'mail.example.test.', 'type' => 'DNAME')), 'example.test', '_acme-challenge.mail.example.test'), 'absolute ancestor DNAME rejected');
	rejects(fn() => DNSAPIDNS::providerPreflight(array(), 'example.test', '_acme-challenge.other.test'), 'zone mismatch rejected');
	$settings['discovery_resolver'] = '192.0.2.53';
	$seen = array();
	$runner = function($args, $timeout) use (&$seen, $negative, $txt) {
		$seen[] = $args;
		$output = $args[2] === '@192.0.2.1' ? $negative : answer(records: $txt . '"expected"');
		return array('output' => $output, 'exit' => 0, 'duration_ms' => 1);
	};
	$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(5), $runner);
	$result = $dns->check($name, 'expected', array('ns.example.test' => array('192.0.2.1')));
	check(!$result['success'] && count($result['results']) === 4, 'all groups checked after authoritative NXDOMAIN');
	check($result['results'][0]['error'] === 'NXDOMAIN' && $result['results'][3]['matched'], 'diagnostic distinguishes groups');
	check(strpos(json_encode($result), 'expected') === false, 'diagnostic excludes TXT value');
	check(in_array('+norecurse', $seen[0], true) && $seen[0][1] === '-r', 'authority nonrecursive and no digrc');
	foreach(array('SERVFAIL', 'REFUSED', 'NXDOMAIN') as $rcode) {
		$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(5), fn() => array('output' => answer($rcode), 'exit' => 0, 'duration_ms' => 1));
		check($dns->query($name, 'TXT', '192.0.2.1', true, 'expected')['error'] === $rcode, 'distinct RCODE ' . $rcode);
	}
	$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(5), fn() => array('output' => answer(records: $txt . '"old"'), 'exit' => 0, 'duration_ms' => 1));
	check($dns->query($name, 'TXT', '192.0.2.1', true, 'expected')['error'] === 'TXT_MISMATCH', 'old TXT does not pass');
	$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(5), fn() => array('output' => answer(), 'exit' => 0, 'duration_ms' => 1));
	check($dns->query($name, 'TXT', '192.0.2.1', true, 'expected')['error'] === 'NODATA', 'NOERROR without TXT differs from mismatch');
	$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(5), fn() => array('output' => answer(flags: 'qr rd ra', records: $txt . '"expected"'), 'exit' => 0, 'duration_ms' => 1));
	check($dns->query($name, 'TXT', '192.0.2.1', true, 'expected')['error'] === 'NOT_AUTHORITATIVE', 'matching recursive response is not authority proof');
	$attempt = 0;
	$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(5), function() use (&$attempt, $txt) {
		return array('output' => ++$attempt === 1 ? answer(flags: 'qr tc') : answer(records: $txt . '"expected"'), 'exit' => 0, 'duration_ms' => 1);
	});
	$result = $dns->query($name, 'TXT', '192.0.2.1', true, 'expected');
	check($result['matched'] && $result['transport'] === 'tcp' && count($result['attempts']) === 2, 'truncation uses bounded TCP fallback');
	$seen = array();
	$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(5), function($args) use (&$seen) {
		$seen[] = $args;
		$type = $args[6];
		$name = $args[4];
		$value = match($type) { 'NS' => 'ns.example.test.', 'A' => '192.0.2.1', 'AAAA' => '2001:db8::1' };
		return array('output' => answer(records: "$name 120 IN $type $value"), 'exit' => 0, 'duration_ms' => 1);
	});
	check(count($dns->authorities('example.test')['ns.example.test']) === 2, 'discover A and AAAA');
	check(count(array_filter($seen, fn($args) => $args[2] === '@192.0.2.53')) === 3, 'all discovery uses explicit resolver');
	$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(5), fn() => array('output' => answer(records: "$name. 120 IN CNAME delegated.test."), 'exit' => 0, 'duration_ms' => 1));
	rejects(fn() => $dns->preflight($name, array('ns' => array('192.0.2.1'))), 'reject CNAME before mutation');
	$start = hrtime(true);
	$result = DNSAPIProcess::run(array(PHP_BINARY, '-r', 'usleep(1000000);'), 0.05);
	check($result['exit'] === 124 && (hrtime(true) - $start) / 1e9 < 0.5, 'process hard timeout');
	foreach(array('network unreachable' => 'NETWORK_UNREACHABLE', 'Network is unreachable' => 'NETWORK_UNREACHABLE', 'No route to host' => 'NO_ROUTE', 'connection refused' => 'CONNECTION_REFUSED', 'permission denied' => 'PERMISSION_DENIED', 'timed out' => 'TIMEOUT', 'no servers could be reached' => 'NO_RESPONSE') as $message => $code) {
		check(DNSAPIProcess::errorCode(9, $message) === $code, 'dig exit 9 distinguished: ' . $code);
		$process = DNSAPIProcess::run(array(PHP_BINARY, '-r', 'fwrite(STDERR, $argv[1]); exit(9);', $message . ' private-fixture-value'), 1);
		check($process['error'] === $code && !str_contains(json_encode($process), 'private-fixture-value'), 'stderr classified without leaking input: ' . $code);
	}
	$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(5), fn() => array('output' => ';; network unreachable', 'exit' => 9, 'duration_ms' => 1));
	$result = $dns->query($name, 'TXT', '2001:db8::1', true, 'expected');
	check($result['error'] === 'NETWORK_UNREACHABLE' && count($result['attempts']) === 1, 'no TCP retry when IP has no route');
	$dns = new DNSAPIDNS($settings, new DNSAPIDeadline(5), fn($args) => $args[2] === '@2001:db8::1' ? array('output' => 'network unreachable', 'exit' => 9, 'duration_ms' => 1) : array('output' => answer(records: $txt . '"expected"'), 'exit' => 0, 'duration_ms' => 1));
	check($dns->check($name, 'expected', array('ns' => array('2001:db8::1', '192.0.2.1')))['success'], 'unroutable IPv6 does not block verified IPv4');
	$deadline = new DNSAPIDeadline(0.01);
	$deadline->pause(1);
	rejects(fn() => $deadline->check(), 'sleep respects deadline');

	class FixtureAPI {
		public array|false $records;
		public array $deleted = array();
		function getRecords() { return $this->records; }
		function deleteRecord($record = null, $value = null) {
			$this->deleted[] = $record;
			unset($this->records[$record['id']]);
			return true;
		}
	}
	$api = new FixtureAPI();
	$own = array('id' => 'own', 'name' => '_acme-challenge', 'type' => 'TXT', 'value' => 'expected');
	$foreign = array_replace($own, array('id' => 'foreign', 'value' => 'other'));
	$api->records = array('own' => $own, 'foreign' => $foreign);
	$entry = array('CERTBOT_IDENTIFIER' => 'example.test', 'CERTBOT_VALIDATION' => 'expected', 'record' => $own, 'owned' => true);
	dnsapiCleanupEntry($entry, $api, 'example.test');
	dnsapiCleanupEntry($entry, $api, 'example.test');
	check(count($api->deleted) === 1 && isset($api->records['foreign']), 'idempotent exact cleanup preserves foreign TXT');
	$api->records = false;
	rejects(fn() => dnsapiCleanupEntry($entry, $api, 'example.test'), 'read failure is not absence');
	$broken = $entry;
	unset($broken['record']['value']);
	rejects(fn() => dnsapiCleanupEntry($broken, $api, 'example.test'), 'missing value cannot reach deletion');
	$broken['record']['value'] = 'wrong';
	rejects(fn() => dnsapiCleanupEntry($broken, $api, 'example.test'), 'mismatched journal value rejected');
	$legacy = $entry;
	unset($legacy['owned']);
	rejects(fn() => dnsapiCleanupEntry($legacy, $api, 'example.test'), 'legacy ownership needs adoption');
	$reused = array_replace($entry, array('owned' => false));
	dnsapiCleanupEntry($reused, $api, 'example.test');
	check(count($api->deleted) === 1, 'reused value not deleted');
	$rrset = array('id' => '_acme-challenge/TXT', 'name' => '_acme-challenge', 'type' => 'TXT', 'records' => array(array('value' => '"other"'), array('value' => '"expec" "ted"')));
	check(dnsapiFindChallenge(array($rrset), 'example.test', 'example.test', 'expected')['value'] === '"expec" "ted"', 'Cloud RRSet exact lookup');

	$directory = sys_get_temp_dir() . '/dnsapi-tests-' . bin2hex(random_bytes(8));
	mkdir($directory, 0700);
	$path = $directory . '/journal.json';
	try {
		$journal = new DNSAPIJournal($path);
		$journal->data['journal']['operation'] = $entry;
		$journal->save();
		rejects(fn() => new DNSAPIJournal($path), 'stable lock rejects concurrent process');
		check((fileperms($path) & 0777) === 0600, 'journal private permissions');
		$before = file_get_contents($path);
		$journal->data['bad'] = NAN;
		rejects(fn() => $journal->save(), 'serialization failure');
		check(file_get_contents($path) === $before, 'failed save preserves original');
		unset($journal);
		$journal = new DNSAPIJournal($path);
		check(isset($journal->data['journal']['operation']), 'atomic save survives reopen');
		unset($journal);
		file_put_contents($path, '{broken');
		rejects(fn() => new DNSAPIJournal($path), 'corrupt journal rejected');
		check(file_get_contents($path) === '{broken', 'corrupt journal unchanged');
		file_put_contents($path, json_encode(array('journal' => array('legacy' => $legacy))));
		$journal = new DNSAPIJournal($path);
		check(!isset($journal->data['journal']['legacy']['owned']), 'legacy retained without assuming ownership');
		unset($journal);
		$stream = fopen('php://temp', 'w+');
		DNSAPIJournal::writeAll($stream, 'abcdef', fn($handle, $bytes) => fwrite($handle, substr($bytes, 0, 2)));
		rewind($stream);
		check(stream_get_contents($stream) === 'abcdef', 'partial writes completed');
		rejects(fn() => DNSAPIJournal::writeAll($stream, 'abcdef', fn() => 0), 'zero-byte writes fail');
		fclose($stream);
	} finally {
		unset($journal);
		foreach(glob($directory . '/*') as $file) unlink($file);
		rmdir($directory);
	}
	foreach(array('Hetzner', 'HetznerCloud', 'Netcup') as $provider) require_once __DIR__ . '/../dnsapi-' . $provider . '.inc.php';
	check(true, 'provider interfaces load on current PHP');
	class CloudDeleteFixture extends hetznerCloudAPI {
		public array|false $fixture = array();
		function __construct(string $zoneID = '1', array $APIConfig = array()) {}
		function getRecords() { return $this->fixture; }
	}
	$cloud = new CloudDeleteFixture();
	check($cloud->deleteRecord(name: '_acme-challenge', type: 'TXT', value: 'expected'), 'Cloud missing RRSet is idempotent after read');
	$cloud->fixture = array('_acme-challenge/TXT' => array_replace($rrset, array('records' => array(array('value' => '"other"')))));
	check($cloud->deleteRecord(id: '_acme-challenge/TXT', value: 'expected'), 'Cloud missing value preserves other RRSet values');
	$cloud->fixture = false;
	check(!$cloud->deleteRecord(id: '_acme-challenge/TXT', value: 'expected'), 'Cloud API failure is not cleanup success');
	$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
	$handle = curl_init('http://' . stream_socket_get_name($socket, false));
	curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
	$GLOBALS['dnsapiDeadline'] = new DNSAPIDeadline(0.05);
	$start = hrtime(true);
	try { dnsapiCurlExec($handle); } catch(RuntimeException $error) {}
	check((hrtime(true) - $start) / 1e9 < 0.5 && curl_errno($handle) === CURLE_OPERATION_TIMEDOUT, 'slow HTTP request respects propagation deadline');
	unset($GLOBALS['dnsapiDeadline'], $handle);
	fclose($socket);
	echo "OK: $count checks on PHP " . PHP_VERSION . PHP_EOL;
