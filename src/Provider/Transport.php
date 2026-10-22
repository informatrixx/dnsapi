<?php
	interface DNSAPIHttpTransport {
		public function execute(CurlHandle $handle): string|bool;
	}

	final class DNSAPICurlTransport implements DNSAPIHttpTransport {
		public function execute(CurlHandle $handle): string|bool {
			return curl_exec($handle);
		}
	}
