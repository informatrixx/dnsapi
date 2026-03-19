#!/usr/bin/php
<?php

	const CONFIG_FILE = '/etc/dnsapi/config.json';
	const JOURNAL_FILE = '/var/cache/dnsapi/journal.json';
	const DNSAPI_SOURCE_DIR = '/var/lib/dnsapi';

	function fail(string $message, int $exitCode = 1): never
	{
		fwrite(STDERR, $message . PHP_EOL);
		exit($exitCode);
	}

	function envValue(array $names): string
	{
		foreach($names as $name)
		{
			$value = getenv($name);
			if($value !== false && $value !== '')
				return $value;
		}

		return '';
	}

	function loadConfig(): array
	{
		$config = json_decode(json: (string) file_get_contents(filename: CONFIG_FILE), associative: true);
		if(!is_array($config))
			fail('DNSAPI Konfiguration konnte nicht geladen werden: ' . CONFIG_FILE);

		return $config;
	}

	function openJournalFile()
	{
		$handle = fopen(filename: JOURNAL_FILE, mode: 'c+');
		if($handle === false)
			fail('Journal Datei kann nicht geoeffnet werden: ' . JOURNAL_FILE);

		return $handle;
	}

	function readJournal($handle): array
	{
		rewind($handle);
		$content = stream_get_contents($handle);
		$journal = json_decode(json: is_string($content) ? $content : '', associative: true);
		if(!is_array($journal))
			$journal = array();
		if(!isset($journal['journal']) || !is_array($journal['journal']))
			$journal['journal'] = array();

		foreach($journal['journal'] as $journalID => $entry)
			if(
				!is_string($journalID) ||
				$journalID === '' ||
				!is_array($entry) ||
				!is_array($entry['record'] ?? null) ||
				!is_string($entry['CERTBOT_VALIDATION'] ?? null)
			)
				unset($journal['journal'][$journalID]);

		return $journal;
	}

	function writeJournal($handle, array $journal): void
	{
		$json = json_encode(value: $journal, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if($json === false)
			fail('Journal Daten konnten nicht serialisiert werden.');

		rewind($handle);
		if(!ftruncate($handle, 0))
			fail('Journal Datei konnte nicht geleert werden: ' . JOURNAL_FILE);
		if(fwrite($handle, $json) === false)
			fail('Journal Datei konnte nicht geschrieben werden: ' . JOURNAL_FILE);
		fflush($handle);
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

	function extractJournalID(string $authOutput): string
	{
		$lines = preg_split('/\R/', trim($authOutput));
		if($lines === false)
			return '';

		for($i = count($lines) - 1; $i >= 0; $i--)
		{
			$line = trim($lines[$i]);
			if($line !== '')
				return $line;
		}

		return '';
	}

	interface DNSAPIv1 {
		function __construct(string $zoneID, array $APIConfig);
		function deleteRecord(?string $name, ?string $type, ?string $id, ?array $record, ?string $value);
	}

	$config = loadConfig();
	if(!defined('CONFIG'))
		define(constant_name: 'CONFIG', value: $config);
	$apis = array();
	$currentIdentifier = envValue(array('CERTBOT_IDENTIFIER', 'CERTBOT_DOMAIN'));
	$currentValidation = envValue(array('CERTBOT_VALIDATION'));
	$journalID = extractJournalID(envValue(array('CERTBOT_AUTH_OUTPUT')));

	$journalHandle = openJournalFile();
	if(!flock($journalHandle, LOCK_EX))
		fail('Journal Datei konnte nicht gesperrt werden: ' . JOURNAL_FILE);

	$errors = array();

	try {
		$journal = readJournal($journalHandle);
		$candidateIDs = array();

		if($journalID !== '' && isset($journal['journal'][$journalID]))
			$candidateIDs[] = $journalID;

		if(count($candidateIDs) === 0 && $currentIdentifier !== '' && $currentValidation !== '')
			foreach($journal['journal'] as $candidateID => $entry)
				if(
					($entry['CERTBOT_IDENTIFIER'] ?? $entry['CERTBOT_DOMAIN'] ?? '') === $currentIdentifier &&
					($entry['CERTBOT_VALIDATION'] ?? '') === $currentValidation
				)
					$candidateIDs[] = $candidateID;

		$candidateIDs = array_values(array_unique($candidateIDs));
		foreach($candidateIDs as $candidateID)
		{
			$entry = $journal['journal'][$candidateID] ?? null;
			if(!is_array($entry))
				continue;

			$record = $entry['record'] ?? null;
			if(!is_array($record))
			{
				unset($journal['journal'][$candidateID]);
				continue;
			}

			$identifier = $entry['CERTBOT_IDENTIFIER'] ?? $entry['CERTBOT_DOMAIN'] ?? '';
			if(!is_string($identifier) || $identifier === '')
			{
				unset($journal['journal'][$candidateID]);
				continue;
			}

			$apiData = loadAPI(config: $config, identifier: $identifier, apis: $apis);
			if($apiData === array())
			{
				$errors[] = 'Keine passende Domain in Cleanup DNS Konfiguration gefunden: ' . $identifier;
				continue;
			}

			if($apiData['api']->deleteRecord(record: $record))
			{
				unset($journal['journal'][$candidateID]);
				continue;
			}

			$errors[] = 'Cleanup fuer ' . $identifier . ' fehlgeschlagen: ' . ($apiData['api']->lastError ?? 'Unbekannter Fehler');
		}

		writeJournal($journalHandle, $journal);
	}
	finally {
		flock($journalHandle, LOCK_UN);
		fclose($journalHandle);
	}

	if(count($errors) > 0)
		fail(implode(' | ', $errors));

?>
