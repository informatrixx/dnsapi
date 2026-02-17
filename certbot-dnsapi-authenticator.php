#!/usr/bin/php
<?php
	
	//Environment Variablen von Certbot aufgreifen:
	define(constant_name: 'CERTBOT_DOMAIN', value: getenv(name: 'CERTBOT_DOMAIN'));
	define(constant_name: 'CERTBOT_VALIDATION', value: getenv(name: 'CERTBOT_VALIDATION'));
	define(constant_name: 'CERTBOT_TOKEN', value: getenv(name: 'CERTBOT_TOKEN'));
	define(constant_name: 'CERTBOT_REMAINING_CHALLENGES', value: getenv(name: 'CERTBOT_REMAINING_CHALLENGES'));
	define(constant_name: 'CERTBOT_ALL_DOMAINS', value: getenv(name: 'CERTBOT_ALL_DOMAINS'));

	//Name des DNS Eintrags, zB: _acme-challenge.mocu.at.
	define(constant_name: 'CHALLENGE_RECORD_NAME', value: '_acme-challenge.' . CERTBOT_DOMAIN . '.');
	
	const CONFIG_FILE = '/etc/dnsapi/config.json';
	const JOURNAL_FILE = '/var/cache/dnsapi/journal.json';
	const DNSAPI_SOURCE_DIR = '/var/lib/dnsapi';

	//Einstellungen aus JSON Datei holen
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(filename: CONFIG_FILE), associative: true));
	$aJournal = json_decode(json: file_get_contents(filename: JOURNAL_FILE), associative: true);
	

	interface DNSAPIv1 {
		function __construct(string $zoneID, array $APIConfig);
		function createRecord(string $name, string $value, string $type, int $ttl);
	}
	

	//Domain Config holen und passende Klasse laden
	$aDomainPart = CERTBOT_DOMAIN;
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
				$aAPI = new $aAPIClassName(zoneID: $aDNSZoneID, APIConfig: CONFIG['API'][$aAPIName]);
				define('DOMAIN_API_LOADED', $aDomainPart);
				break;
			}
		}
		else
			$aDomainPart = preg_replace(pattern: '/^[^.]+\./', replacement: '', subject: $aDomainPart);
	}

	if(!isset($aAPI))
		die('Keine passende Domain in Authenticator DNS Konfiguration gefunden: ' . CERTBOT_DOMAIN);


	$aRecord = $aAPI->createRecord(name: CHALLENGE_RECORD_NAME, value: CERTBOT_VALIDATION, type: 'TXT', ttl: 120);

	$aJournal['journal'][$aRecord['id']] = array(
		'CERTBOT_DOMAIN' =>     CERTBOT_DOMAIN,
		'CERTBOT_VALIDATION' => CERTBOT_VALIDATION,
		'record' =>             $aRecord,
	);

	file_put_contents(filename: JOURNAL_FILE, data: json_encode(value: $aJournal, flags: JSON_PRETTY_PRINT));
	
	//Wenn das die letzte Certbot Challenge war, dann Einträge der Reihe nach abfragen und script beenden, wenn alle aktiv sind
	if(CERTBOT_REMAINING_CHALLENGES == 0)
	{
		foreach($aJournal['journal'] as $aDNSRecordData)
		{
			$aTestDomain = '_acme-challenge.' . $aDNSRecordData['CERTBOT_DOMAIN'] . '.';
			for($i = 0; $i < 100; $i++)	//60x 30 Sekunden sleep -> halbe Stunde Zeit zum Testen ob der DNS Record aktiviert wurde 
			{
				if(trim(characters: '"', string: exec(command: 'dig -t TXT ' . escapeshellarg($aTestDomain) . ' +short @1.1.1.1')) == $aDNSRecordData['CERTBOT_VALIDATION'])
					break;
				sleep(30);
			}
		}
	}

?>
