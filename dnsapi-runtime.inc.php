<?php
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
		$result = curl_exec($handle);
		if($deadline instanceof DNSAPIDeadline)
			$deadline->check();
		return $result;
	}
	function dnsapiPause(float $seconds): void {
		$deadline = $GLOBALS['dnsapiDeadline'] ?? null;
		if($deadline instanceof DNSAPIDeadline) $deadline->pause($seconds);
		else usleep((int)($seconds * 1e6));
	}
