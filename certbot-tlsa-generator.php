#!/usr/bin/php
<?php

	//Environment Variablen von Certbot aufgreifen:
	define(constant_name: 'RENEWED_LINEAGE', value: getenv(name: 'RENEWED_LINEAGE'));
	define(constant_name: 'RENEWED_DOMAINS', value: getenv(name: 'RENEWED_DOMAINS'));

	const CONFIG_FILE = '/etc/dnsapi/config.json';
	const DNSAPI_SOURCE_DIR = '/var/lib/dnsapi';

	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(filename: CONFIG_FILE), associative: true));

	interface DNSAPIv1 {
		function __construct(string $zoneID, array $APIConfig);
		function multiSetRecords(array $records);
	}


	$aTLSARecords = array();
	$aUpdateDomains = array();
	$aAPIsArray = array();

	//TLSA Konfiguration einlesen
	if(isset(CONFIG['tlsa-records'][RENEWED_LINEAGE]))
	{
		$aTLSADomains = CONFIG['tlsa-records'][RENEWED_LINEAGE];
		if(isset(CONFIG['tlsa-records'][RENEWED_LINEAGE]['%']))
		{
			$aRenewedDomains = explode(separator: ' ', string: RENEWED_DOMAINS);
			foreach($aRenewedDomains as $aDomain)
				$aTLSADomains[$aDomain] = CONFIG['tlsa-records'][RENEWED_LINEAGE]['%'];
			unset($aTLSADomains['%']);
		}

		//Hash Wert generieren
		$aHash = shell_exec('openssl x509 -in ' . RENEWED_LINEAGE . '/cert.pem -noout -pubkey | openssl pkey -pubin -outform DER | openssl dgst -sha256 -binary | hexdump -ve \'/1 "%02x"\'');
		foreach($aTLSADomains as $aDomain => $aPortsArray)
		{
			//Domain Config holen und passende Klasse laden
			$aDNSZoneID = null;
			$aDomainPart = $aDomain;
			while(str_contains(haystack: $aDomainPart, needle: '.'))
				if(array_key_exists(key: $aDomainPart, array: CONFIG['domains']))
				{
					$aDNSZoneID = CONFIG['domains'][$aDomainPart]['zoneID'];
					$aAPIName = CONFIG['domains'][$aDomainPart]['API'];
					if(isset(CONFIG['API'][$aAPIName]))
					{
						require_once(rtrim(characters: '/', string: DNSAPI_SOURCE_DIR) . "/dnsapi-$aAPIName.inc.php");
						$aAPIClassName = $aAPIName . 'API';
						if(!isset($aAPIsArray[$aDNSZoneID]))
							$aAPIsArray[$aDNSZoneID] = new $aAPIClassName(zoneID: $aDNSZoneID, APIConfig: CONFIG['API'][$aAPIName]);
					}
					break;
				}
				else
					$aDomainPart = preg_replace(pattern: '/^[^.]+\./', replacement: '', subject: $aDomainPart);

			//Keine passende Domain Zone gefunden -> continue
			if($aDNSZoneID == null)
				continue;

			$aUpdateDomains[$aDomainPart] = $aDNSZoneID;
			//Für jeden geforderten Port/Domain einen Record vorbereiten
			foreach($aPortsArray as $aPort)
			{
				if(substr(string: $aDomain, offset: -strlen(".$aDomainPart")) == ".$aDomainPart")
					$aDomainTag = '.' . substr_replace(string: $aDomain, replace: '', offset: -strlen(".$aDomainPart"));
				elseif($aDomain == $aDomainPart)
					$aDomainTag = '';
				else
					$aDomainTag = ".$aDomain.";

				$aTLSARecords[$aDNSZoneID][] = array(
					"type" =>		"TLSA",
					"name" =>		"_$aPort._tcp$aDomainTag",
					"value" =>		"3 1 1 $aHash",
					);
			}
		}
	}

	foreach($aTLSARecords as $aDNSZoneID => $aRecords)
	{
		echo "Zone: $aDNSZoneID\t";
		if($aAPIsArray[$aDNSZoneID]->multiSetRecords($aRecords))
			echo "OK" . PHP_EOL;
		else
			echo "Fehler: " . $aAPIsArray[$aDNSZoneID]->lastError . PHP_EOL;
	}

?>




