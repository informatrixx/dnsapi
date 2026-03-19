#!/usr/bin/php
<?php

	const CONFIG_FILE = '/etc/dnsapi/config.json';
	const JOURNAL_FILE = '/var/cache/dnsapi/journal.json';
	const DNSAPI_SOURCE_DIR = '/var/lib/dnsapi';
	const PROPAGATION_NAMESERVER = '1.1.1.1';
	const PROPAGATION_ATTEMPTS = 60;
	const PROPAGATION_WAIT_SECONDS = 30;
	const JOURNAL_ENTRY_TTL = 3600;

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

	function normalizeIdentifierSet(string $identifiers, string $fallbackIdentifier): string
	{
		$parts = preg_split('/\s*,\s*/', $identifiers);
		if($parts === false)
			$parts = array();

		$parts = array_values(array_filter(array_map('trim', $parts), fn($value) => $value !== ''));
		if(count($parts) === 0 && $fallbackIdentifier !== '')
			$parts[] = $fallbackIdentifier;

		$parts = array_values(array_unique($parts));
		sort($parts, SORT_STRING);

		return implode(',', $parts);
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

	function loadAPI(array $config, string $identifier): array
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
						return array(
							'matchedDomain' => $domainPart,
							'zoneID' => $zoneID,
							'api' => new $apiClassName(zoneID: $zoneID, APIConfig: $config['API'][$apiName]),
						);
				}
			}

			$nextDomainPart = preg_replace(pattern: '/^[^.]+\./', replacement: '', subject: $domainPart);
			if(!is_string($nextDomainPart) || $nextDomainPart === $domainPart)
				break;
			$domainPart = $nextDomainPart;
		}

		fail('Keine passende Domain in Authenticator DNS Konfiguration gefunden: ' . $identifier);
	}

	function txtRecordIsVisible(string $recordName, string $validation): bool
	{
		$output = array();
		$status = 0;
		exec(
			command: 'dig +short +time=2 +tries=1 -t TXT ' . escapeshellarg($recordName) . ' @' . PROPAGATION_NAMESERVER,
			output: $output,
			result_code: $status,
		);

		if($status !== 0)
			return false;

		foreach($output as $line)
		{
			$normalizedLine = trim($line);
			if($normalizedLine === '')
				continue;
			if(trim(characters: '"', string: $normalizedLine) === $validation)
				return true;
		}

		return false;
	}

	interface DNSAPIv1 {
		function __construct(string $zoneID, array $APIConfig);
		function createRecord(?string $name, ?string $type, ?string $value, ?int $ttl, ?array $record);
	}

	$identifier = envValue(array('CERTBOT_IDENTIFIER', 'CERTBOT_DOMAIN'));
	$validation = envValue(array('CERTBOT_VALIDATION'));
	$remainingChallenges = (int) envValue(array('CERTBOT_REMAINING_CHALLENGES'));
	$identifierSet = normalizeIdentifierSet(
		identifiers: envValue(array('CERTBOT_ALL_IDENTIFIERS', 'CERTBOT_ALL_DOMAINS')),
		fallbackIdentifier: $identifier,
	);

	if($identifier === '')
		fail('Certbot hat keinen Identifier fuer den DNS Hook uebergeben.');
	if($validation === '')
		fail('Certbot hat keinen Validation Wert fuer den DNS Hook uebergeben.');
	if(trim((string) shell_exec('command -v dig')) === '')
		fail('Das Kommando "dig" wird fuer die DNS-Propagation-Pruefung benoetigt.');

	$config = loadConfig();
	if(!defined('CONFIG'))
		define(constant_name: 'CONFIG', value: $config);
	$apiData = loadAPI(config: $config, identifier: $identifier);
	$challengeRecordName = '_acme-challenge.' . rtrim($identifier, '.') . '.';
	$record = $apiData['api']->createRecord(name: $challengeRecordName, value: $validation, type: 'TXT', ttl: 120);
	if(!is_array($record))
		fail('DNS TXT Challenge Record konnte nicht erstellt werden: ' . ($apiData['api']->lastError ?? 'Unbekannter Fehler'));

	$journalID = isset($record['journal_id']) && is_string($record['journal_id']) && $record['journal_id'] !== ''
		? $record['journal_id']
		: ((isset($record['id']) && is_string($record['id']) && $record['id'] !== '') ? $record['id'] : '');
	if($journalID === '')
		fail('DNS API hat keine gueltige Record-ID fuer das Journal geliefert.');

	$journalHandle = openJournalFile();
	if(!flock($journalHandle, LOCK_EX))
		fail('Journal Datei konnte nicht gesperrt werden: ' . JOURNAL_FILE);

	try {
		$journal = readJournal($journalHandle);
		$journal['journal'][$journalID] = array(
			'CERTBOT_IDENTIFIER' => $identifier,
			'CERTBOT_DOMAIN' => $identifier,
			'CERTBOT_VALIDATION' => $validation,
			'IDENTIFIER_SET' => $identifierSet,
			'created_at' => time(),
			'record' => $record,
		);
		writeJournal($journalHandle, $journal);
	}
	finally {
		flock($journalHandle, LOCK_UN);
		fclose($journalHandle);
	}

	// Der Cleanup-Hook bekommt diesen Wert ueber CERTBOT_AUTH_OUTPUT.
	echo $journalID . PHP_EOL;

	if($remainingChallenges === 0)
	{
		$journalHandle = openJournalFile();
		if(!flock($journalHandle, LOCK_SH))
			fail('Journal Datei konnte nicht fuer das Lesen gesperrt werden: ' . JOURNAL_FILE);

		try {
			$journal = readJournal($journalHandle);
		}
		finally {
			flock($journalHandle, LOCK_UN);
			fclose($journalHandle);
		}

		$pendingEntries = array();
		foreach($journal['journal'] as $entry)
		{
			if(!is_array($entry))
				continue;
			if(($entry['IDENTIFIER_SET'] ?? '') !== $identifierSet)
				continue;
			if(!isset($entry['created_at']) || !is_int($entry['created_at']) || $entry['created_at'] < time() - JOURNAL_ENTRY_TTL)
				continue;

			$pendingEntries[] = $entry;
		}

		foreach($pendingEntries as $entry)
		{
			$entryIdentifier = $entry['CERTBOT_IDENTIFIER'] ?? $entry['CERTBOT_DOMAIN'] ?? '';
			$entryValidation = $entry['CERTBOT_VALIDATION'] ?? '';
			if(!is_string($entryIdentifier) || $entryIdentifier === '' || !is_string($entryValidation) || $entryValidation === '')
				continue;

			$recordName = '_acme-challenge.' . rtrim($entryIdentifier, '.') . '.';
			$isVisible = false;
			for($attempt = 0; $attempt < PROPAGATION_ATTEMPTS; $attempt++)
			{
				if(txtRecordIsVisible(recordName: $recordName, validation: $entryValidation))
				{
					$isVisible = true;
					break;
				}

				sleep(PROPAGATION_WAIT_SECONDS);
			}

			if(!$isVisible)
				fail('DNS TXT Record wurde innerhalb des Zeitlimits nicht sichtbar: ' . $recordName);
		}
	}

?>
