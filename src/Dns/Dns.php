<?php
	require_once __DIR__ . '/Txt.php';
	require_once __DIR__ . '/../Runtime/Runtime.php';

	class DNSAPIProcess {
		static function errorCode(int $exit, string $output, string $stderr = ''): string {
			if($exit === 0) return 'OK';
			if($exit === 124) return 'TIMEOUT';
			$message = strtolower($output . "\n" . $stderr);
			foreach(array('network is unreachable' => 'NETWORK_UNREACHABLE', 'network unreachable' => 'NETWORK_UNREACHABLE', 'no route to host' => 'NO_ROUTE', 'connection refused' => 'CONNECTION_REFUSED', 'permission denied' => 'PERMISSION_DENIED', 'operation not permitted' => 'PERMISSION_DENIED', 'timed out' => 'TIMEOUT') as $text => $code)
				if(str_contains($message, $text)) return $code;
			return $exit === 9 ? 'NO_RESPONSE' : 'PROCESS_ERROR';
		}
		static function run(array $arguments, float $timeout): array {
			$start = hrtime(true);
			$process = proc_open($arguments, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array('PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'LC_ALL' => 'C'));
			if(!is_resource($process))
				throw new RuntimeException('DNS_PROCESS_FAILED');
			foreach($pipes as $pipe) stream_set_blocking($pipe, false);
			$output = '';
			$stderr = '';
			$timedOut = false;
			$outputLimit = false;
			do {
				$output .= stream_get_contents($pipes[1]);
				$stderr .= stream_get_contents($pipes[2]);
				$status = proc_get_status($process);
				if(!$status['running']) break;
				$outputLimit = strlen($output) + strlen($stderr) > 1048576;
				if((hrtime(true) - $start) / 1e9 >= $timeout || $outputLimit) {
					$timedOut = !$outputLimit;
					proc_terminate($process, 9);
					break;
				}
				usleep(1000);
			} while(true);
			$output .= stream_get_contents($pipes[1]);
			$stderr .= stream_get_contents($pipes[2]);
			foreach($pipes as $pipe) fclose($pipe);
			$exit = proc_close($process);
			$exit = $timedOut ? 124 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $exit);
			$error = $outputLimit ? 'OUTPUT_LIMIT' : self::errorCode($exit, $output, $stderr);
			// Only the classified error crosses the boundary; stderr may contain inputs.
			return array('output' => $output, 'exit' => $exit, 'error' => $error, 'duration_ms' => (int)((hrtime(true) - $start) / 1e6));
		}
	}

	class DNSAPIDNS {
		function __construct(private array $settings, private DNSAPIDeadline $deadline, private $runner = null) {
			$this->runner ??= $GLOBALS['dnsapiDNSRunner'] ?? array(DNSAPIProcess::class, 'run');
		}
		static function parse(string $output, string $name, string $expected = ''): array {
			$result = array('rcode' => 'UNKNOWN', 'aa' => false, 'ra' => false, 'rd' => false, 'ad' => false, 'tc' => false, 'matched' => false, 'ttl' => null, 'negative_ttl' => null, 'records' => array(), 'referral' => false, 'cname' => false);
			if(preg_match('/status: ([A-Z0-9]+),/', $output, $match)) $result['rcode'] = $match[1];
			if(preg_match('/;; flags: ([^;]*);/', $output, $match))
				foreach(array('aa', 'ra', 'rd', 'ad', 'tc') as $flag) $result[$flag] = in_array($flag, explode(' ', trim($match[1])), true);
			$section = '';
			foreach(explode("\n", $output) as $line) {
				if(preg_match('/^;; ([A-Z]+) SECTION:/', $line, $match)) { $section = $match[1]; continue; }
				if(!preg_match('/^(\S+)\s+(\d+)\s+IN\s+(\S+)\s+(.+)$/', $line, $match)) continue;
				[$all, $owner, $ttl, $type, $value] = $match;
				$owner = strtolower(rtrim($owner, '.'));
				if($section === 'AUTHORITY' && $type === 'NS') $result['referral'] = true;
				if($section === 'AUTHORITY' && $type === 'SOA') {
					$fields = preg_split('/\s+/', trim($value));
					if(count($fields) === 7 && ctype_digit($fields[6])) $result['negative_ttl'] = min((int)$ttl, (int)$fields[6]);
				}
				if($section !== 'ANSWER') continue;
				if($type === 'CNAME') $result['cname'] = true;
				if($owner !== strtolower(rtrim($name, '.'))) continue;
				if($type === 'TXT') {
					if(!str_starts_with($value, '"')) continue;
					try { $value = DNSAPITXT::value($value); } catch(RuntimeException $error) { continue; }
					if($expected !== '' && $value === $expected) { $result['matched'] = true; $result['ttl'] = (int)$ttl; }
				}
				$result['records'][] = array('type' => $type, 'value' => $value);
			}
			return $result;
		}
		function query(string $name, string $type, string $server, bool $authoritative = false, string $expected = '', bool $dnssec = false): array {
			if(!filter_var($server, FILTER_VALIDATE_IP)) throw new RuntimeException('DNS_SERVER_MUST_BE_IP');
			$attempts = array();
			foreach(array('udp', 'tcp') as $transport) {
				if($this->deadline->remaining() <= 0) {
					if(isset($result)) break;
					$result = self::parse('', $name) + array('server' => $server, 'transport' => $transport, 'exit' => 124, 'duration_ms' => 0, 'error' => 'BUDGET_EXHAUSTED');
					break;
				}
				$timeout = min($this->settings['dns_timeout_seconds'], $this->deadline->remaining());
				$args = array('dig', '-r', '@' . $server, '-q', rtrim($name, '.') . '.', '-t', $type, '+time=' . max(1, (int)ceil($timeout)), '+tries=1', '+nosearch', '+noall', '+comments', '+answer', '+authority', '+nomultiline', '+nocookie', $authoritative ? '+norecurse' : '+recurse', $transport === 'tcp' ? '+tcp' : '+notcp', '+ignore');
				if($dnssec) $args[] = '+dnssec';
				$process = ($this->runner)($args, $timeout);
				$result = self::parse($process['output'], $name, $expected);
				$result += array('server' => $server, 'transport' => $transport, 'exit' => $process['exit'], 'duration_ms' => $process['duration_ms']);
				$hasTXT = count(array_filter($result['records'], fn($record) => $record['type'] === 'TXT')) > 0;
				$processError = $process['error'] ?? DNSAPIProcess::errorCode($result['exit'], $process['output']);
				$result['error'] = $processError !== 'OK' ? $processError : ($result['tc'] ? 'TRUNCATED' : ($result['rcode'] !== 'NOERROR' ? $result['rcode'] : ($authoritative && !$result['aa'] ? 'NOT_AUTHORITATIVE' : ($expected !== '' && !$result['matched'] ? ($hasTXT ? 'TXT_MISMATCH' : 'NODATA') : 'OK'))));
				$attempts[] = array_intersect_key($result, array_flip(array('transport', 'exit', 'rcode', 'error', 'duration_ms')));
				if(in_array($result['error'], array('NETWORK_UNREACHABLE', 'NO_ROUTE', 'PERMISSION_DENIED', 'OUTPUT_LIMIT'), true)) break;
				if($result['exit'] === 0 && !$result['tc']) break;
			}
			$result['attempts'] = $attempts;
			return $result;
		}
		function authorities(string $zone): array {
			$resolver = $this->settings['discovery_resolver'];
			$ns = $this->query($zone, 'NS', $resolver);
			if($ns['error'] !== 'OK') throw new RuntimeException('DNS_DISCOVERY_FAILED ' . $ns['error']);
			$servers = array();
			foreach($ns['records'] as $record) {
				if($record['type'] !== 'NS') continue;
				$name = rtrim($record['value'], '.');
				$servers[$name] = array();
				foreach(array('A', 'AAAA') as $type) {
					$addresses = $this->query($name, $type, $resolver);
					if($addresses['error'] !== 'OK') continue;
					foreach($addresses['records'] as $address)
						if($address['type'] === $type && filter_var($address['value'], FILTER_VALIDATE_IP)) $servers[$name][] = $address['value'];
				}
				if(!$servers[$name]) throw new RuntimeException('DNS_NS_ADDRESS_MISSING');
			}
			if(!$servers) throw new RuntimeException('DNS_NS_MISSING');
			return $servers;
		}
		function preflight(string $name, array $servers): void {
			foreach($servers as $addresses) {
				$valid = false;
				foreach($addresses as $ip) {
					$result = $this->query($name, 'TXT', $ip, true);
					fwrite(STDERR, json_encode(array('event' => 'PREFLIGHT') + $this->summary($result, 'authoritative', $ip), JSON_UNESCAPED_SLASHES) . PHP_EOL);
					if($result['cname'] || $result['referral']) throw new RuntimeException('CHALLENGE_DELEGATION_UNSUPPORTED');
					if($result['exit'] === 0 && !$result['tc'] && $result['aa'] && in_array($result['rcode'], array('NOERROR', 'NXDOMAIN'), true)) $valid = true;
				}
				if(!$valid) throw new RuntimeException('CHALLENGE_AUTHORITY_UNCONFIRMED: use dnsapi-doctor.php');
			}
		}
		function check(string $name, string $expected, array $servers): array {
			$mode = $this->settings['propagation_mode'] ?? 'strict';
			if($mode === 'recursive') $servers = array();
			$results = array();
			$success = $mode === 'recursive' || count($servers) > 0;
			foreach($servers as $server => $addresses) {
				$confirmed = false;
				$contradiction = false;
				foreach($addresses as $ip) {
					$result = $this->query($name, 'TXT', $ip, true, $expected);
					$ok = $result['error'] === 'OK' && !$result['cname'] && !$result['referral'];
					$confirmed = $confirmed || $ok;
					if($result['exit'] === 0 && !$ok) $contradiction = true;
					$results[] = $this->summary($result, 'authoritative', $server);
				}
				$success = $success && $confirmed && !$contradiction;
			}
			foreach($this->settings['resolvers'] as $ip) {
				$result = $this->query($name, 'TXT', $ip, false, $expected);
				$success = $success && $result['error'] === 'OK' && !$result['cname'];
				$results[] = $this->summary($result, 'recursive', $ip);
			}
			return array('success' => $success, 'results' => $results);
		}
		static function providerPreflight(array $records, string $zone, string $name): void {
			$zone = strtolower(rtrim($zone, '.'));
			$name = strtolower(rtrim($name, '.'));
			if(!str_ends_with($name, '.' . $zone)) throw new RuntimeException('CHALLENGE_ZONE_MISMATCH');
			foreach($records as $record) {
				if(!is_array($record)) throw new RuntimeException('PROVIDER_RECORD_INVALID');
				$type = strtoupper($record['type'] ?? '');
				$owner = strtolower(rtrim($record['name'] ?? $record['hostname'] ?? '', '.'));
				if($owner === '@' || $owner === '') $owner = $zone;
				elseif($owner !== $zone && !str_ends_with($owner, '.' . $zone)) $owner .= '.' . $zone;
				$onPath = $name === $owner || str_ends_with($name, '.' . $owner);
				if(($type === 'CNAME' && $name === $owner) || ($type === 'NS' && $owner !== $zone && $onPath) || ($type === 'DNAME' && $onPath)) throw new RuntimeException('CHALLENGE_DELEGATION_UNSUPPORTED');
			}
		}
		function summary(array $result, string $group, string $name): array {
			return array('group' => $group, 'nameserver' => $name) + array_intersect_key($result, array_flip(array('server', 'transport', 'exit', 'duration_ms', 'rcode', 'aa', 'ra', 'rd', 'matched', 'ttl', 'negative_ttl', 'error', 'attempts')));
		}
	}
