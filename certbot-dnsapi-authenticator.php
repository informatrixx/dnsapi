#!/usr/bin/php
<?php

	const CONFIG_FILE = '/etc/dnsapi/config.json';
	const JOURNAL_FILE = '/var/cache/dnsapi/journal.json';
	const DNSAPI_SOURCE_DIR = '/var/lib/dnsapi';
	const PROPAGATION_RESOLVERS = array('1.1.1.1', '8.8.8.8', '9.9.9.9');
	const PROPAGATION_ATTEMPTS = 120;
	const PROPAGATION_WAIT_SECONDS = 10;
	const JOURNAL_ENTRY_TTL = 3600;
	const DEBUG_LOG_FILE = '/var/cache/dnsapi/authenticator-debug.log';

	function fail(string $message, int $exitCode = 1): never
	{
		debugLog('FAIL ' . $message);
		fwrite(STDERR, $message . PHP_EOL);
		exit($exitCode);
	}

	function debugLog(string $message): void
	{
		file_put_contents(
			filename: DEBUG_LOG_FILE,
			data: date('c') . ' ' . $message . PHP_EOL,
			flags: FILE_APPEND,
		);
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
		$parts = preg_split('/[\s,]+/', trim($identifiers));
		if($parts === false)
			$parts = array();

		$parts = array_values(array_filter(array_map('trim', $parts), fn($value) => $value !== ''));
		if(count($parts) === 0 && $fallbackIdentifier !== '')
			$parts[] = $fallbackIdentifier;

		$parts = array_values(array_unique($parts));
		sort($parts, SORT_STRING);

		return implode(',', $parts);
	}

	function identifierSetParts(string $identifierSet): array
	{
		$parts = $identifierSet === '' ? array() : explode(',', $identifierSet);
		return array_values(array_filter($parts, fn($value) => $value !== ''));
	}

	function expectedChallengeCount(string $identifierSet): int
	{
		$identifiers = identifierSetParts($identifierSet);
		$count = count($identifiers);
		$baseDomains = array();
		foreach($identifiers as $identifier)
		{
			if(str_starts_with($identifier, '*.'))
				$baseDomains[substr($identifier, 2)] = true;
			elseif(isset($baseDomains[$identifier]))
				$count++;
		}

		return $count;
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
					(string)$journalID === '' ||
					!is_array($entry) ||
					!is_array($entry['record'] ?? null) ||
					!is_string($entry['CERTBOT_VALIDATION'] ?? null)
			)
				unset($journal['journal'][$journalID]);

		return $journal;
	}

	function writeJournal($handle, array $journal): void
	{
		if(isset($journal['journal']) && is_array($journal['journal']))
			$journal['journal'] = (object)$journal['journal'];
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

	function txtLookupContains(string $recordName, string $validation, string $nameserver): bool
	{
		$output = array();
		$status = 0;
		exec(
			command: 'dig +short +time=3 +tries=1 -t TXT ' . escapeshellarg($recordName) . ' @' . escapeshellarg($nameserver),
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

	function getAuthoritativeNameservers(string $domain): array
	{
		$output = array();
		$status = 0;
		exec(
			command: 'dig +short NS ' . escapeshellarg($domain),
			output: $output,
			result_code: $status,
		);
		if($status !== 0)
			return array();

		$nameservers = array();
		foreach($output as $line)
		{
			$nameserver = strtolower(rtrim(trim($line), '.'));
			if($nameserver !== '')
				$nameservers[] = $nameserver;
		}

		return array_values(array_unique($nameservers));
	}

	function txtRecordIsVisibleEverywhere(string $recordName, string $zoneName, string $validation): bool
	{
		$nameservers = getAuthoritativeNameservers($zoneName);
		if(count($nameservers) === 0)
			return false;

		foreach($nameservers as $nameserver)
			if(!txtLookupContains(recordName: $recordName, validation: $validation, nameserver: $nameserver))
				return false;

		foreach(PROPAGATION_RESOLVERS as $resolver)
			if(!txtLookupContains(recordName: $recordName, validation: $validation, nameserver: $resolver))
				return false;

		return true;
	}

	function providerRecordIsActive($api, array $record): bool
	{
		if(!isset($record['id']) || !is_string((string)$record['id']))
			return true;
		if(!method_exists($api, 'getRecords'))
			return true;

		$records = $api->getRecords();
		if(!is_array($records))
			return false;

		$recordID = (string)$record['id'];
		if(!isset($records[$recordID]))
			return false;

		$state = $records[$recordID]['state'] ?? null;
		return $state === null || $state === 'yes';
	}

	function freshJournalEntriesForSet(array $journal, string $identifierSet): array
	{
		$entries = array();
		foreach($journal['journal'] as $entry)
		{
			if(!is_array($entry))
				continue;
			if(($entry['IDENTIFIER_SET'] ?? '') !== $identifierSet)
				continue;
			if(!isset($entry['created_at']) || !is_int($entry['created_at']) || $entry['created_at'] < time() - JOURNAL_ENTRY_TTL)
				continue;

			$entries[] = $entry;
		}

		return $entries;
	}

	function shouldWaitForSet(int $remainingChallenges, array $entries, string $identifierSet): bool
	{
		return $remainingChallenges === 0;
	}

	function waitForChallengeEntries(array $config, array $entries): array
	{
		$pending = array();
		foreach($entries as $index => $entry)
			$pending[$index] = $entry;

		for($attempt = 0; $attempt < PROPAGATION_ATTEMPTS && count($pending) > 0; $attempt++)
		{
			foreach($pending as $index => $entry)
			{
				$entryIdentifier = $entry['CERTBOT_IDENTIFIER'] ?? $entry['CERTBOT_DOMAIN'] ?? '';
				$entryValidation = $entry['CERTBOT_VALIDATION'] ?? '';
				$entryRecord = is_array($entry['record'] ?? null) ? $entry['record'] : array();

				if(!is_string($entryIdentifier) || $entryIdentifier === '' || !is_string($entryValidation) || $entryValidation === '')
				{
					unset($pending[$index]);
					continue;
				}

				$recordName = '_acme-challenge.' . rtrim($entryIdentifier, '.') . '.';
				$apiData = loadAPI(config: $config, identifier: $entryIdentifier);
				if(
					providerRecordIsActive(api: $apiData['api'], record: $entryRecord) &&
					txtRecordIsVisibleEverywhere(recordName: $recordName, zoneName: $apiData['matchedDomain'], validation: $entryValidation)
				)
				{
					debugLog('VISIBLE identifier=' . $entryIdentifier . ' record=' . ($entryRecord['id'] ?? 'n/a'));
					unset($pending[$index]);
				}
			}

			if(count($pending) === 0)
				break;

			debugLog('WAIT attempt=' . $attempt . ' pending=' . count($pending));
			sleep(PROPAGATION_WAIT_SECONDS);
		}

		return array_values($pending);
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
	debugLog('CREATE identifier=' . $identifier . ' remaining=' . $remainingChallenges . ' identifier_set=' . $identifierSet . ' record=' . $journalID);

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

	$pendingEntries = freshJournalEntriesForSet(journal: $journal, identifierSet: $identifierSet);
	debugLog('STATE identifier=' . $identifier . ' remaining=' . $remainingChallenges . ' pending_entries=' . count($pendingEntries) . ' expected=' . expectedChallengeCount($identifierSet));
	if(shouldWaitForSet(remainingChallenges: $remainingChallenges, entries: $pendingEntries, identifierSet: $identifierSet))
	{
		debugLog('WAIT_START entries=' . count($pendingEntries));
		$unresolvedEntries = waitForChallengeEntries(config: $config, entries: $pendingEntries);
		if(count($unresolvedEntries) > 0)
			fail('Nicht alle DNS TXT Records wurden innerhalb des Zeitlimits sichtbar.');
		debugLog('WAIT_DONE entries=' . count($pendingEntries));
	}

?>
