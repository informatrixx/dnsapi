<?php
	require_once __DIR__ . '/../Provider/Transport.php';
	class DNSAPIDiagnostics {
		private array $records = array();
		function __construct(private bool $debug = false, private $writer = null) {
			$this->writer ??= fn($record) => fwrite(STDERR, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL);
		}
		function record(array $record): void {
			if($this->debug) { ($this->writer)($record); return; }
			$this->records[] = $record;
			// Retain bounded failure context without emitting normal polling states on success.
			if(count($this->records) > 128) array_shift($this->records);
		}
		function flush(): void {
			foreach($this->records as $record) ($this->writer)($record);
			$this->records = array();
		}
	}
	function dnsapiDiagnostic(array $record): void {
		$diagnostics = $GLOBALS['dnsapiDiagnostics'] ?? null;
		if($diagnostics instanceof DNSAPIDiagnostics) $diagnostics->record($record);
		else fwrite(STDERR, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL);
	}

	class DNSAPIDeadline {
		private float $end;
		function __construct(float $seconds) {
			$this->end = hrtime(true) / 1e9 + $seconds;
		}
		function remaining(): float {
			return max(0, $this->end - hrtime(true) / 1e9);
		}
		function check(): void {
			if($this->remaining() <= 0)
				throw new RuntimeException('PROPAGATION_TIMEOUT');
		}
		function pause(float $seconds): void {
			$this->check();
			usleep((int)(min($seconds, $this->remaining()) * 1e6));
		}
	}

	// Hooks set one deadline shared by every provider request, including pagination.
	function dnsapiCurlExec(CurlHandle $handle): string|bool {
		$deadline = $GLOBALS['dnsapiDeadline'] ?? null;
		if($deadline instanceof DNSAPIDeadline) {
			$deadline->check();
			$milliseconds = max(1, (int)(min(30, $deadline->remaining()) * 1000));
			curl_setopt($handle, CURLOPT_TIMEOUT_MS, $milliseconds);
			curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, $milliseconds);
		}
		$transport = $GLOBALS['dnsapiHttpTransport'] ?? new DNSAPICurlTransport();
		$result = $transport->execute($handle);
		if($deadline instanceof DNSAPIDeadline)
			$deadline->check();
		return $result;
	}
	function dnsapiPause(float $seconds): void {
		$deadline = $GLOBALS['dnsapiDeadline'] ?? null;
		if($deadline instanceof DNSAPIDeadline) $deadline->pause($seconds);
		else usleep((int)($seconds * 1e6));
	}
