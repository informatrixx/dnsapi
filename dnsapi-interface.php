#!/usr/bin/php
<?php
	const CONFIG_FILE = '/etc/dnsapi/config.json';
	const DNSAPI_SOURCE_DIR = '/var/lib/dnsapi';

	//Einstellungen aus JSON Datei holen
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(filename: CONFIG_FILE), associative: true));
	
	interface DNSAPIv1 {
		function __construct(string $zoneID, array $APIConfig);
		function createRecord(?string $name, ?string $type, ?string $value, ?int $ttl, ?array $record);
		function deleteRecord(?string $name, ?string $type, ?string $id, ?array $record, ?string $value);
		function getRecords();
		function multiCreateRecords(array $records);
		function multiSetRecords(array $records);
		function multiUpdateRecords(array $records);
		function setRecord(?string $name, ?string $type, ?string $value, ?int $ttl, ?string $id, ?array $record);
		function updateRecord(?string $name, ?string $type, ?string $value, ?int $ttl, ?string $id, ?array $record);
	}
	
	$aOptions = getopt(short_options: "x:c:d:p::s:u:h", long_options: array("domain:", "create:", "delete:", "print::", "set:", "update:", "help"));
	
	if(isset($aOptions['h']) || isset($aOptions['help']))
		echo <<<"HELP"
	dns-interface.php [Optionen]
		-x | --domain	(Erforderlich)
			DNS root Domain (zB. mocu.at)
		-c | --create
			Create Record in folgender Schreibweise:
			name:type=value[:ttl]
		-d | --delete
			(?id|name:type)
			?id oder name:type	(Erforderlich)
		-p | --print
			Print Records. Entweder alle oder:
			Optional: Record Type (zB. -pTXT) 
		-s | --set
			Set Record (Update falls vorhanden oder Create falls nicht) in folgender Schreibweise:
			(?id|name:type)=value[:ttl]
			?id oder name:type	(Erforderlich) 
		-u | --update
			Update Record in folgender Schreibweise:
			(?id|name:type)=value[:ttl]
		
				
		Beispiele:
			-c test.mocu.at:A=127.0.0.1:300
			-s dyn.mocu.at:A=192.168.1.1
			-u ?mnsQmZmXXmWh5MpFeT67ZZ=Test-Text
			-d road.mocu.at:CNAME
			-pCNAME
				
		-h | --help

HELP;


	if(!isset($aOptions['x']) && !isset($aOptions['domain']))
		die('	-x | --domain	... DNS Root Domain ist erfoderlich!' . PHP_EOL);

	define(constant_name: 'DOMAIN', value: isset($aOptions['x']) ? $aOptions['x'] : $aOptions['domain']);
	
	if(isset(CONFIG['domains'][DOMAIN]))
	{
		$aDNSZoneID = CONFIG['domains'][DOMAIN]['zoneID'];
		$aAPIName = CONFIG['domains'][DOMAIN]['API'];
		if(isset(CONFIG['API'][$aAPIName]))
		{
			require_once(rtrim(characters: '/', string: DNSAPI_SOURCE_DIR) . "/dnsapi-$aAPIName.inc.php");
			$aAPIClassName = $aAPIName . 'API';
			$aAPI = new $aAPIClassName(zoneID: $aDNSZoneID, APIConfig: CONFIG['API'][$aAPIName]);
		}
		else
			die('Fehler: API Konfiguration von Domain' . PHP_EOL);
	}
	else
		die('Fehler: Domain nicht konfiguriert' . PHP_EOL);

	if(isset($aOptions['c']) && is_string($aOptions['c']))
	{
		if(preg_match(pattern: '/^(?<name>[^:]+):(?<type>[^=]+)=(?<value>[^:]+)(:(?<ttl>\d+))?$/', subject: $aOptions['c'], matches: $aMatches))
		{	
			$aDNSRecord = array(
				'name' => $aMatches['name'],
				'type' => $aMatches['type'],
				'value' => $aMatches['value'],
				);
			if(!empty($aMatches['ttl']))
				$aDNSRecord['ttl'] = (int) $aMatches['ttl'];
			
			if($aAPI->createRecord(record: $aDNSRecord))
				echo 'OK' . PHP_EOL;
			else
				echo 'Fehler: ' . $aAPI->lastError . PHP_EOL;
		}
		else
			echo 'Fehler: Falsche Parameter' . PHP_EOL;
	}
	if(isset($aOptions['d']) && is_string($aOptions['d']))
	{
		if(preg_match(pattern: '/^(\?(?<id>[^:]+)|^(?<name>[^:]+):(?<type>[^=]+)(=(?<value>[^:]+))?)$/', subject: $aOptions['d'], matches: $aMatches))
		{	
			if(!empty($aMatches['id']))
				$aResult = $aAPI->deleteRecord(id: $aMatches['id']);
			else
				$aResult = $aAPI->deleteRecord(name: $aMatches['name'], type: $aMatches['type'], value: $aMatches['value'] ?? null);
			if($aResult)
				echo 'OK' . PHP_EOL;
			else
				echo 'Fehler: ' . $aAPI->lastError . PHP_EOL; 
		}
	}
	if(isset($aOptions['s']))
	{
		if(is_string($aOptions['s']) && preg_match(pattern: '/^(\?(?<id>[^:]+)|(?<name>[^:]+):(?<type>[^=]+))=(?<value>[^:]+)(:(?<ttl>\d+))?$/', subject: $aOptions['s'], matches: $aMatches))
		{	
			$aDNSRecord = array(
				'value' => $aMatches['value'],
				);
			if(!empty($aMatches['name']))
				$aDNSRecord['name'] = $aMatches['name'];
			if(!empty($aMatches['type']))
				$aDNSRecord['type'] = $aMatches['type'];
			if(!empty($aMatches['ttl']))
				$aDNSRecord['ttl'] = (int) $aMatches['ttl'];
			if(!empty($aMatches['id']))
				$aDNSRecord['id'] = $aMatches['id'];
			
			if($aAPI->setRecord(record: $aDNSRecord))
				echo 'OK' . PHP_EOL;
			else
				echo 'Fehler: ' . $aAPI->lastError . PHP_EOL;
		}
		elseif(is_array($aOptions['s']))
		{
			$aSetRecords = array();
			foreach($aOptions['s'] as $aSetOption)
				if(preg_match(pattern: '/^(\?(?<id>[^:]+)|(?<name>[^:]+):(?<type>[^=]+))=(?<value>[^:]+)(:(?<ttl>\d+))?$/', subject: $aSetOption, matches: $aMatches))
				{
					$aDNSRecord = array(
						'value' => $aMatches['value'],
						);
					if(!empty($aMatches['name']))
						$aDNSRecord['name'] = $aMatches['name'];
					if(!empty($aMatches['type']))
						$aDNSRecord['type'] = $aMatches['type'];
					if(!empty($aMatches['ttl']))
						$aDNSRecord['ttl'] = (int) $aMatches['ttl'];
					if(!empty($aMatches['id']))
						$aDNSRecord['id'] = $aMatches['id'];
					$aSetRecords[] = $aDNSRecord;
				}
				else
					echo 'Fehler: Falsche Parameter (-s)' . PHP_EOL;
					
			if($aAPI->multiSetRecords(records: $aSetRecords))
				echo 'OK' . PHP_EOL;
			else
				echo 'Fehler: ' . $aAPI->lastError . PHP_EOL;
		}
		else
			echo 'Fehler: Falsche Parameter (-s)' . PHP_EOL;
	}
	if(isset($aOptions['u']) && is_string($aOptions['u']))
	{
		$aDNSRecord = array();
		if(preg_match(pattern: '/^(\?(?<id>[^:]+)|(?<name>[^:]+):(?<type>[^=]+))=(?<value>[^:]+)(:(?<ttl>\d+))?$/', subject: $aOptions['u'], matches: $aMatches))
		{	
			$aDNSRecord = array(
				'value' => $aMatches['value'],
				);
			if(!empty($aMatches['name']))
				$aDNSRecord['name'] = $aMatches['name'];
			if(!empty($aMatches['type']))
				$aDNSRecord['type'] = $aMatches['type'];
			if(!empty($aMatches['ttl']))
				$aDNSRecord['ttl'] = (int) $aMatches['ttl'];
			if(!empty($aMatches['id']))
				$aDNSRecord['id'] = $aMatches['id'];
			
			if($aAPI->updateRecord(record: $aDNSRecord))
				echo 'OK' . PHP_EOL;
			else
				echo 'Fehler: ' . $aAPI->lastError . PHP_EOL;
		}
		else
			echo 'Fehler: Falsche Parameter' . PHP_EOL;
	}
	if(isset($aOptions['p']) || isset($aOptions['print']))
	{
		$aSelector = !empty($aOptions['p']) ? $aOptions['p'] : null;
		$aRecords = $aAPI->getRecords();
		$aPrintRecords = array();
		foreach($aRecords as $aRecord)
			if(empty($aSelector) || $aRecord['type'] == $aSelector)
				$aPrintRecords[] = $aRecord;
		print_r($aPrintRecords);	
	}
?>
