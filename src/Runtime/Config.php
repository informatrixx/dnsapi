<?php
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
			$content = @file_get_contents(dnsapiEnv('DNSAPI_CONFIG_FILE', '/etc/dnsapi/config.json'));
			if($content === false) throw new RuntimeException('CONFIG_READ_FAILED');
			$config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		} catch(Throwable $error) { throw new RuntimeException('CONFIG_READ_FAILED'); }
		if(!is_array($config) || !is_array($config['domains'] ?? null) || !is_array($config['API'] ?? null)) throw new RuntimeException('CONFIG_INVALID');
		dnsapiSettings($config);
		dnsapiValidateConfig($config);
		return $config;
	}
	function dnsapiIdentifier(string $identifier): string {
		$identifier = strtolower(rtrim($identifier, '.'));
		if(str_starts_with($identifier, '*.')) $identifier = substr($identifier, 2);
		if(strlen($identifier) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $identifier)) throw new RuntimeException('IDENTIFIER_INVALID');
		return $identifier;
	}
	function dnsapiProviderConfig(array $config, string $identifier): array {
		$zone = dnsapiIdentifier($identifier);
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
		return array('zone' => $zone, 'key' => $key, 'provider' => $provider, 'zoneID' => $mapping['zoneID'], 'options' => array_replace($options, array('zoneName' => $zone)));
	}
	function dnsapiProvider(array $config, string $identifier, array &$apis): array {
		$data = dnsapiProviderConfig($config, $identifier);
		$key = $data['key'];
		if(!isset($apis[$key])) {
			require_once __DIR__ . '/../Provider/' . $data['provider'] . '.php';
			$class = $data['provider'] . 'API';
			$factory = $GLOBALS['dnsapiProviderFactory'] ?? fn($class, $zoneID, $options) => new $class($zoneID, $options);
			$apis[$key] = $factory($class, $data['zoneID'], $data['options']);
		}
		return array('zone' => $data['zone'], 'api' => $apis[$key], 'key' => $key);
	}
	function dnsapiValidateConfig(array $config): void {
		foreach($config['domains'] as $zone => $mapping) {
			if(!is_string($zone) || dnsapiIdentifier($zone) !== $zone || !is_array($mapping)) throw new RuntimeException('CONFIG_DOMAIN_INVALID');
			dnsapiProviderConfig($config, $zone);
			if(isset($mapping['dynDNS'])) {
				$dyn = $mapping['dynDNS'];
				if(!is_array($dyn) || !is_string($dyn['secret'] ?? null) || $dyn['secret'] === '' || !is_array($dyn['allowed'] ?? null)) throw new RuntimeException('CONFIG_DYNDNS_INVALID');
				foreach($dyn['allowed'] as $host) if(!is_string($host) || !preg_match('/^(?:@|[a-z0-9_-]+(?:\.[a-z0-9_-]+)*)$/D', $host)) throw new RuntimeException('CONFIG_DYNDNS_HOST_INVALID');
			}
		}
		if(isset($config['tlsa-records'])) {
			if(!is_array($config['tlsa-records'])) throw new RuntimeException('CONFIG_TLSA_INVALID');
			foreach($config['tlsa-records'] as $lineage => $hosts) {
				if(!is_string($lineage) || !str_starts_with($lineage, '/') || !is_array($hosts)) throw new RuntimeException('CONFIG_TLSA_INVALID');
				foreach($hosts as $host => $ports) {
					if($host !== '%') dnsapiProviderConfig($config, dnsapiIdentifier($host));
					if(!is_array($ports) || !$ports) throw new RuntimeException('CONFIG_TLSA_PORT_INVALID');
					foreach($ports as $port) if(!is_int($port) || $port < 1 || $port > 65535) throw new RuntimeException('CONFIG_TLSA_PORT_INVALID');
				}
			}
		}
	}
	function dnsapiError(Throwable $error): string {
		return preg_match('/^[A-Z][A-Z_]+(?=:|$)/', $error->getMessage(), $match) ? $match[0] : 'OPERATION_FAILED';
	}
