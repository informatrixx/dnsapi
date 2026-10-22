<?php
	require_once __DIR__ . '/../Dns/Records.php';
	require_once __DIR__ . '/Journal.php';

	final class DNSAPIDynDNS {
		static function update(array $config, array $query, string $remoteIP, string $cachePath): array {
			if(!$query) return array('IP' => $remoteIP);
			foreach(array('domain', 'host', 'secret') as $field) if(!is_string($query[$field] ?? null)) throw new RuntimeException('REQUEST_INVALID');
			$domain = dnsapiIdentifier($query['domain']);
			$host = $query['host'];
			$settings = $config['domains'][$domain]['dynDNS'] ?? null;
			if(!is_array($settings) || !is_string($settings['secret'] ?? null) || !hash_equals($settings['secret'], $query['secret']) || !in_array($host, $settings['allowed'] ?? array(), true)) throw new RuntimeException('REFUSED');
			$ip = $query['ip'] ?? $remoteIP;
			if(!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('IP_INVALID');
			$type = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'A' : 'AAAA';
			$descriptor = dnsapiProviderConfig($config, $domain);
			$key = hash('sha256', implode("\n", array($descriptor['key'], $domain, $host, $type)));
			$cache = new DNSAPIJournal($cachePath);
			$response = array('IP' => $ip, 'host' => $host, 'domain' => $domain, 'type' => $type);
			$entry = $cache->data['journal'][$key] ?? null;
			if(is_array($entry) && ($entry['value'] ?? null) === $ip && ($entry['expires'] ?? 0) > time()) return $response + array('result' => 'CACHE');
			$apis = array();
			$data = dnsapiProvider($config, $domain, $apis);
			$records = new DNSAPIRecords($data['api'], $domain);
			$plan = $records->plan('set', array('name' => $host, 'type' => $type, 'value' => $ip, 'ttl' => 600));
			$records->apply($plan);
			foreach($cache->data['journal'] as $id => $old) if(!is_array($old) || ($old['expires'] ?? 0) <= time()) unset($cache->data['journal'][$id]);
			$cache->data['journal'][$key] = array('value' => $ip, 'expires' => time() + 600);
			$cache->save();
			return $response + array('result' => 'OK');
		}
	}
