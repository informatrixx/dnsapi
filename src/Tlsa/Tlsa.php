<?php
	require_once __DIR__ . '/../Dns/Records.php';

	final class DNSAPITLSA {
		static function hash(string $path): string {
			if(!extension_loaded('openssl')) throw new RuntimeException('OPENSSL_EXTENSION_REQUIRED');
			$pem = @file_get_contents($path);
			$certificate = is_string($pem) ? @openssl_x509_read($pem) : false;
			if($certificate === false) throw new RuntimeException('TLSA_CERTIFICATE_INVALID');
			$key = @openssl_pkey_get_public($certificate);
			$details = $key !== false ? openssl_pkey_get_details($key) : false;
			if(!is_array($details) || !preg_match('/-----BEGIN PUBLIC KEY-----\s+([A-Za-z0-9+\/=\s]+)-----END PUBLIC KEY-----/', $details['key'], $matches)) throw new RuntimeException('TLSA_PUBLIC_KEY_INVALID');
			$der = base64_decode(preg_replace('/\s+/', '', $matches[1]), true);
			if($der === false || $der === '') throw new RuntimeException('TLSA_PUBLIC_KEY_INVALID');
			return hash('sha256', $der);
		}
		static function targets(array $config, string $lineage, string $renewedDomains): array {
			$hosts = $config['tlsa-records'][$lineage] ?? array();
			if(isset($hosts['%'])) {
				foreach(preg_split('/\s+/', trim($renewedDomains), -1, PREG_SPLIT_NO_EMPTY) as $host) {
					// A wildcard certificate is not a concrete service hostname.
					if(str_starts_with($host, '*.')) continue;
					$hosts[$host] ??= $hosts['%'];
				}
				unset($hosts['%']);
			}
			$result = array();
			foreach($hosts as $host => $ports) {
				if(str_starts_with($host, '*.')) throw new RuntimeException('TLSA_CONCRETE_HOST_REQUIRED');
				$host = dnsapiIdentifier($host);
				$data = dnsapiProviderConfig($config, $host);
				foreach(array_unique($ports) as $port) $result[] = array('host' => $host, 'name' => '_' . $port . '._tcp.' . $host . '.', 'type' => 'TLSA', 'zone' => $data['zone']);
			}
			return $result;
		}
	}
