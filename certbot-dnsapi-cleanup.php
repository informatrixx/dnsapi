#!/usr/bin/php
<?php
	const CONFIG_FILE = '/etc/dnsapi/config.json';
	const JOURNAL_FILE = '/var/cache/dnsapi/journal.json';
	const DNSAPI_SOURCE_DIR = '/var/lib/dnsapi';

	//Einstellungen aus JSON Datei holen
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(filename: CONFIG_FILE), associative: true));
	$aJournal = json_decode(json: file_get_contents(filename: JOURNAL_FILE), associative: true);
	
	interface DNSAPIv1 {
		function __construct(string $zoneID, array $APIConfig);
		function deleteRecord(?string $name, ?string $type, ?string $id, ?array $record);
	}
	
	$aAPIsArray = array();
	
	foreach($aJournal['journal'] as $aRecordID => $aDNSRecordData)
	{
		//Domain Config holen und passende Klasse laden
		$aDomainPart = $aDNSRecordData['CERTBOT_DOMAIN'];
		while(str_contains(haystack: $aDomainPart, needle: '.'))
		{
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
					if($aAPIsArray[$aDNSZoneID]->deleteRecord(record: $aDNSRecordData['record']))
						unset($aJournal['journal'][$aRecordID]);
					break;
				}
			}
			else
				$aDomainPart = preg_replace(pattern: '/^[^.]+\./', replacement: '', subject: $aDomainPart);
		}
	}
	
	file_put_contents(filename: JOURNAL_FILE, data: json_encode(value: $aJournal, flags: JSON_PRETTY_PRINT));
	
?>
