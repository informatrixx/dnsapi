<?php

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Pragma: no-cache');
	header('Expires: 0');

	const CONFIG_FILE = '/etc/dnsapi/config.json';
	const CACHE_FILE = '/var/cache/dnsapi/dyn-cache.json';
	const DNSAPI_SOURCE_DIR = '/var/lib/dnsapi';

	$aIP = isset($_GET["ip"]) ? $_GET["ip"] : $_SERVER["REMOTE_ADDR"];

	$aResponse = array(
		"IP" =>		$aIP,
	);

	if(count($_GET) > 0 && (!isset($_GET["host"]) || !isset($_GET["secret"]) || !isset($_GET['domain'])))
	{
		header("HTTP/1.1 418 NO");
		exit;
	}

	if(isset($_GET['host']) && isset($_GET['secret']) && isset($_GET['domain']))
	{
		$aHost = $_GET['host'];
		$aSecret = $_GET['secret'];
		$aDomain = $_GET['domain'];

		$aResponse['host'] = $aHost;
		$aResponse['domain'] = $aDomain;

		//Einstellungen aus JSON Datei holen
		define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(filename: CONFIG_FILE), associative: true));
		$aDynCache = json_decode(json: file_get_contents(filename: CACHE_FILE), associative: true);


		if(!isset(CONFIG['domains'][$aDomain]))
			$aResponse['error'] = 'NXDOMAIN';
		elseif(!isset(CONFIG['domains'][$aDomain]['dynDNS']))
			$aResponse['error'] = 'NOTIMP';
		elseif(CONFIG['domains'][$aDomain]['dynDNS']['secret'] != $aSecret)
			$aResponse['error'] = 'REFUSED';
		elseif(!in_array(needle: $aHost, haystack: CONFIG['domains'][$aDomain]['dynDNS']['allowed']))
			$aResponse['error'] = 'NOTZONE';

		if(!isset($aResponse['error']))
		{
			foreach($aDynCache as $aCacheRecord)
			{
				if($aCacheRecord['value'] == $aIP && $aCacheRecord['name'] == $aHost && $aCacheRecord['cache_ttl'] > time())
					$aResponse['result'] = 'CACHE';
			}
			
			if($aResponse['result'] != 'CACHE')
			{
				interface DNSAPIv1 {
					function __construct(string $zoneID, array $APIConfig);
					function setRecord(?string $name, ?string $type, ?string $value, ?int $ttl, ?string $id, ?array $record);
				}
	
				$aAPIName = CONFIG['domains'][$aDomain]['API'];
				if(isset(CONFIG['API'][$aAPIName]))
				{
					require_once(rtrim(characters: '/', string: DNSAPI_SOURCE_DIR) . "/dnsapi-$aAPIName.inc.php");
					$aAPIClassName = $aAPIName . 'API';
					$aAPI = new $aAPIClassName(zoneID: CONFIG['domains'][$aDomain]['zoneID'], APIConfig: CONFIG['API'][$aAPIName]);
				}
	
				$aRecordData = array(
					'value' =>	$aIP,
					'ttl' =>	600,
					'type' =>	'A',
					'name' =>	$aHost,
					);
				if($aDynRecord = $aAPI->setRecord(record: $aRecordData))
				{
					if(isset($aDynRecord['hostname']))	//Netcup Record in allgemeinen Record umwandeln für Cache
						$aDynRecord['name'] = $aDynRecord['hostname'];
					if(isset($aDynRecord['destination']))
						$aDynRecord['value'] = $aDynRecord['destination'];
	
					$aDynRecord['cache_ttl'] = time() + 7200;
					$aDynCache[$aDynRecord['id']] = $aDynRecord;
					$aResponse['result'] = 'OK';
				}
				else
					$aResponse['result'] = 'SRVFAIL';
			}
		}
	}

	file_put_contents(filename: CACHE_FILE, data: json_encode(value: $aDynCache, flags: JSON_PRETTY_PRINT));

	echo json_encode(value: $aResponse, flags: JSON_PRETTY_PRINT);


?>
