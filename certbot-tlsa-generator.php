#!/usr/bin/php
<?php

	const CONFIG_FILE = '/etc/dnsapi/config.json';
	const DNSAPI_SOURCE_DIR = '/var/lib/dnsapi';

	function fail(string $message, int $exitCode = 1): never
	{
		fwrite(STDERR, $message . PHP_EOL);
		exit($exitCode);
	}

	function envValue(string $name): string
	{
		$value = getenv($name);
		return $value === false ? '' : $value;
	}

	function loadConfig(): array
	{
		$config = json_decode(json: (string) file_get_contents(filename: CONFIG_FILE), associative: true);
		if(!is_array($config))
			fail('DNSAPI Konfiguration konnte nicht geladen werden: ' . CONFIG_FILE);

		return $config;
	}

	function loadAPI(array $config, string $identifier, array &$apis): array
	{
		$domainPart = strtolower(rtrim($identifier, '.'));
		while($domainPart !== '')
		{
			if(isset($config['domains'][$domainPart]))
			{
				$zoneID = $config['domains'][$domainPart]['zoneID'] ?? null;
				$apiName = $config['domains'][$domainPart]['API'] ?? null;
				if(is_string($zoneID) && is_string($apiName) && isset($config['API'][$apiName]))
				{
					require_once(rtrim(characters: '/', string: DNSAPI_SOURCE_DIR) . "/dnsapi-$apiName.inc.php");
					$apiClassName = $apiName . 'API';
					if(class_exists($apiClassName))
					{
						if(!isset($apis[$zoneID]))
							$apis[$zoneID] = new $apiClassName(zoneID: $zoneID, APIConfig: $config['API'][$apiName]);

						return array(
							'matchedDomain' => $domainPart,
							'zoneID' => $zoneID,
							'api' => $apis[$zoneID],
						);
					}
				}
			}

			$nextDomainPart = preg_replace(pattern: '/^[^.]+\./', replacement: '', subject: $domainPart);
			if(!is_string($nextDomainPart) || $nextDomainPart === $domainPart)
				break;
			$domainPart = $nextDomainPart;
		}

		return array();
	}

	function buildTLSAHash(string $lineage): string
	{
		$certificatePath = escapeshellarg($lineage . '/cert.pem');
		$command = 'openssl x509 -in ' . $certificatePath . ' -noout -pubkey'
			. ' | openssl pkey -pubin -outform DER'
			. ' | openssl dgst -sha256 -binary'
			. ' | hexdump -ve \'/1 "%02x"\'';
		$hash = trim((string) shell_exec($command));
		if($hash === '')
			fail('TLSA Hash konnte nicht aus dem erneuerten Zertifikat erzeugt werden: ' . $lineage);

		return $hash;
	}

	function splitDomains(string $domains): array
	{
		$parts = preg_split('/\s+/', trim($domains));
		if($parts === false)
			return array();

		return array_values(array_filter($parts, fn($domain) => $domain !== ''));
	}

	interface DNSAPIv1 {
		function __construct(string $zoneID, array $APIConfig);
		function multiSetRecords(array $records);
	}

	$renewedLineage = envValue('RENEWED_LINEAGE');
	$renewedDomains = envValue('RENEWED_DOMAINS');
	if($renewedLineage === '')
		fail('Certbot hat keinen RENEWED_LINEAGE fuer den TLSA Hook uebergeben.');

	$config = loadConfig();
	if(!defined('CONFIG'))
		define(constant_name: 'CONFIG', value: $config);
	$tlsaRecords = array();
	$apis = array();
	$errors = array();

	if(isset($config['tlsa-records'][$renewedLineage]) && is_array($config['tlsa-records'][$renewedLineage]))
	{
		$tlsaDomains = $config['tlsa-records'][$renewedLineage];
		if(isset($tlsaDomains['%']))
		{
			$renewedDomainList = splitDomains($renewedDomains);
			foreach($renewedDomainList as $domain)
				if(!isset($tlsaDomains[$domain]))
					$tlsaDomains[$domain] = $tlsaDomains['%'];
			unset($tlsaDomains['%']);
		}

		$hash = buildTLSAHash($renewedLineage);
		foreach($tlsaDomains as $domain => $portsArray)
		{
			if(!is_array($portsArray))
				continue;

			$apiData = loadAPI(config: $config, identifier: $domain, apis: $apis);
			if($apiData === array())
			{
				$errors[] = 'Keine passende DNS Zone fuer TLSA Domain gefunden: ' . $domain;
				continue;
			}

			$matchedDomain = $apiData['matchedDomain'];
			$zoneID = $apiData['zoneID'];

			foreach($portsArray as $port)
			{
				if(substr(string: $domain, offset: -strlen('.' . $matchedDomain)) === '.' . $matchedDomain)
					$domainTag = '.' . substr_replace(string: $domain, replace: '', offset: -strlen('.' . $matchedDomain));
				elseif($domain === $matchedDomain)
					$domainTag = '';
				else
					$domainTag = '.' . $domain . '.';

				$tlsaRecords[$zoneID][] = array(
					'type' => 'TLSA',
					'name' => '_' . $port . '._tcp' . $domainTag,
					'value' => '3 1 1 ' . $hash,
				);
			}
		}
	}

	foreach($tlsaRecords as $zoneID => $records)
	{
		echo 'Zone: ' . $zoneID . "\t";
		if($apis[$zoneID]->multiSetRecords($records))
			echo 'OK' . PHP_EOL;
		else
		{
			echo 'Fehler: ' . $apis[$zoneID]->lastError . PHP_EOL;
			$errors[] = 'TLSA Update fuer Zone ' . $zoneID . ' fehlgeschlagen: ' . $apis[$zoneID]->lastError;
		}
	}

	if(count($errors) > 0)
		fail(implode(' | ', $errors));

?>

