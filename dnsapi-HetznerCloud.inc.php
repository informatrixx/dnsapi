<?php
	class hetznerCloudAPI implements DNSAPIv1{

		private $pZoneID;
		private $pZoneName = null;
		private $pCURL;
		private $pAPIConfig;

		public $lastError = null;
		public $records = array();

		function __construct(string $zoneID, array $APIConfig)
		{
			$this->pZoneID = $zoneID;
			$this->pAPIConfig = $APIConfig;
			$this->pZoneName = $this->detectZoneName();

			$this->pCURL = curl_init();
			curl_setopt_array($this->pCURL, array(
				CURLOPT_RETURNTRANSFER	=> 1,
				CURLOPT_TIMEOUT			=> 30,
				CURLOPT_FAILONERROR		=> 0,
			));

			$this->records = $this->getRecords();
		}

		function __destruct()
		{
			if($this->pCURL)
				curl_close($this->pCURL);
		}

		private function detectZoneName()
		{
			if(defined('DOMAIN_API_LOADED') && is_string(DOMAIN_API_LOADED))
				return strtolower(trim(DOMAIN_API_LOADED, '.'));

			if(defined('DOMAIN') && is_string(DOMAIN))
				return strtolower(trim(DOMAIN, '.'));

			if(defined('CONFIG') && isset(CONFIG['domains']) && is_array(CONFIG['domains']))
				foreach(CONFIG['domains'] as $aDomain => $aDomainConfig)
					if(isset($aDomainConfig['API']) && strtolower($aDomainConfig['API']) == 'hetznercloud' && isset($aDomainConfig['zoneID']) && (string)$aDomainConfig['zoneID'] === (string)$this->pZoneID)
						return strtolower(trim($aDomain, '.'));

			return null;
		}

		private function normalizeRRSetID(string $id)
		{
			if(!str_contains($id, '/'))
				return $id;

			$aParts = explode('/', $id, 2);
			return $this->normalizeName($aParts[0]) . '/' . $this->normalizeType($aParts[1]);
		}

		private function normalizeName(?string $name)
		{
			if(!is_string($name))
				return null;

			$name = trim($name);
			if($name === '' || $name === '@')
				return '@';

			$name = strtolower(rtrim($name, '.'));
			if($this->pZoneName !== null)
			{
				if($name === $this->pZoneName)
					return '@';

				$aZoneSuffix = '.' . $this->pZoneName;
				if(str_ends_with($name, $aZoneSuffix))
					$name = substr($name, 0, -strlen($aZoneSuffix));
			}

			return $name === '' ? '@' : $name;
		}

		private function normalizeType(?string $type)
		{
			return is_string($type) ? strtoupper(trim($type)) : null;
		}

		private function normalizeRecordValue(string $type, string $value)
		{
			if($type != 'TXT')
				return $value;

			$aTrimmedValue = trim($value);
			if($aTrimmedValue !== '' && preg_match('/^"(?:[^"\\\\]|\\\\.)*"(\s+"(?:[^"\\\\]|\\\\.)*")*$/', $aTrimmedValue))
				return $aTrimmedValue;

			return '"' . addcslashes($value, "\\\"") . '"';
		}

		private function buildRecordPayload(string $type, ?string $value = null, ?array $record = null)
		{
			if($value === null && isset($record) && array_key_exists('value', $record) && is_string($record['value']))
				$value = $record['value'];

			if(!is_string($value))
				return false;

			$aRecordPayload = array(
				'value' => $this->normalizeRecordValue($type, $value),
			);

			if(isset($record) && array_key_exists('comment', $record) && is_string($record['comment']))
				$aRecordPayload['comment'] = $record['comment'];

			return $aRecordPayload;
		}

		private function extractTTL(?array $record = null, ?int $ttl = null)
		{
			if(is_int($ttl))
				return $ttl;

			if(isset($record) && array_key_exists('ttl', $record) && is_int($record['ttl']))
				return $record['ttl'];

			return null;
		}

		private function buildZonePath(string $suffix = '')
		{
			return rawurlencode((string)$this->pZoneID) . $suffix;
		}

		private function buildRRSetPath(string $name, string $type, string $suffix = '')
		{
			return $this->buildZonePath('/rrsets/' . rawurlencode($name) . '/' . rawurlencode($type) . $suffix);
		}

		private function buildQueryString(array $query)
		{
			if(count($query) == 0)
				return '';

			$aParts = array();
			foreach($query as $aKey => $aValue)
				if(is_array($aValue))
					foreach($aValue as $aArrayValue)
						$aParts[] = rawurlencode((string)$aKey) . '=' . rawurlencode((string)$aArrayValue);
				elseif($aValue !== null)
					$aParts[] = rawurlencode((string)$aKey) . '=' . rawurlencode((string)$aValue);

			return count($aParts) > 0 ? '?' . implode('&', $aParts) : '';
		}

		private function requestJSON(string $method, string $path, ?array $body = null, array $query = array())
		{
			$aURL = rtrim($this->pAPIConfig['recordsURL'], '/') . '/' . ltrim($path, '/') . $this->buildQueryString($query);
			$aHeaders = array(
				'Accept: application/json',
				'Authorization: Bearer ' . $this->pAPIConfig['APIToken'],
			);

			$aPayload = '';
			if($body !== null)
			{
				$aPayload = json_encode($body, JSON_UNESCAPED_SLASHES);
				if($aPayload === false)
				{
					$this->lastError = 'Fehler beim JSON Encoding.';
					return false;
				}
				$aHeaders[] = 'Content-Type: application/json';
			}

			curl_setopt($this->pCURL, CURLOPT_URL, $aURL);
			curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, $aHeaders);
			curl_setopt($this->pCURL, CURLOPT_POST, $method == 'POST' ? 1 : 0);
			curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $body !== null ? $aPayload : '');

			$aResponse = curl_exec($this->pCURL);
			if($aResponse === false)
			{
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_error($this->pCURL);
				return false;
			}

			$aResponseBody = array();
			if(strlen($aResponse) > 0)
			{
				$aResponseBody = json_decode($aResponse, true);
				if(!is_array($aResponseBody))
					$aResponseBody = array();
			}

			return array(
				'status'	=> (int)curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE),
				'body'		=> $aResponseBody,
				'raw'		=> $aResponse,
			);
		}

		private function setLastErrorFromResponse(array $response, string $defaultMessage)
		{
			if(isset($response['body']['error']['message']) && is_string($response['body']['error']['message']))
			{
				$aErrorCode = isset($response['body']['error']['code']) && is_string($response['body']['error']['code']) ? $response['body']['error']['code'] . ' ' : '';
				$this->lastError = $defaultMessage . ': ' . $response['status'] . ' ' . $aErrorCode . $response['body']['error']['message'];
			}
			else
				$this->lastError = $defaultMessage . ': ' . $response['status'] . ' ' . trim($response['raw']);
		}

		private function waitForAction(int $actionID, int $timeoutSeconds = 60)
		{
			$aDeadline = time() + $timeoutSeconds;
			do{
				$aActionResponse = $this->requestJSON('GET', '/actions/' . rawurlencode((string)$actionID));
				if($aActionResponse === false)
					return false;

				if($aActionResponse['status'] != 200 || !isset($aActionResponse['body']['action']))
				{
					$this->setLastErrorFromResponse($aActionResponse, 'Fehler beim Abfragen der Action');
					return false;
				}

				$aAction = $aActionResponse['body']['action'];
				if(($aAction['status'] ?? null) == 'success')
					return true;

				if(($aAction['status'] ?? null) == 'error')
				{
					if(isset($aAction['error']['message']) && is_string($aAction['error']['message']))
						$this->lastError = 'Hetzner Action fehlgeschlagen: ' . $aAction['error']['message'];
					else
						$this->lastError = 'Hetzner Action fehlgeschlagen.';
					return false;
				}

				usleep(500000);
			}
			while(time() <= $aDeadline);

			$this->lastError = 'Timeout beim Warten auf Hetzner Action ' . $actionID . '.';
			return false;
		}

		private function handleActionResponse(array $response, int $expectedStatus, string $defaultMessage)
		{
			if($response['status'] != $expectedStatus || !isset($response['body']['action']['id']))
			{
				$this->setLastErrorFromResponse($response, $defaultMessage);
				return false;
			}

			return $this->waitForAction((int)$response['body']['action']['id']);
		}

		private function refreshRecords()
		{
			$aRecords = $this->getRecords();
			if($aRecords === false)
				return false;

			$this->records = $aRecords;
			return true;
		}

		private function getRRSetRecordMap(array $rrset)
		{
			$aRecordMap = array();
			foreach($rrset['records'] as $aRecord)
				$aRecordMap[$aRecord['value']] = $aRecord;
			return $aRecordMap;
		}

		private function findRRSetRecord(array $rrset, string $type, string $value)
		{
			$aValue = $this->normalizeRecordValue($type, $value);
			foreach($rrset['records'] as $aRecord)
				if($aRecord['value'] == $aValue)
					return $aRecord;

			return false;
		}

		private function buildCompatRecord(array $rrset, ?array $record = null)
		{
			if(!isset($record))
				$record = $rrset['records'][0] ?? array();

			$aCompatRecord = array(
				'id'		=> $rrset['id'],
				'journal_id'	=> $rrset['id'] . '#' . sha1(($record['value'] ?? '') . "\n" . ($record['comment'] ?? '')),
				'name'		=> $rrset['name'],
				'type'		=> $rrset['type'],
				'value'		=> $record['value'] ?? null,
				'ttl'		=> $rrset['ttl'],
			);

			if(isset($record['comment']) && is_string($record['comment']))
				$aCompatRecord['comment'] = $record['comment'];

			if(isset($rrset['zone']))
				$aCompatRecord['zone'] = $rrset['zone'];

			return $aCompatRecord;
		}

		private function findRecord(?string $name = null, ?string $type = null, ?string $id = null, ?array $record = null)
		{
			if(isset($id) && is_string($id))
			{
				$aID = $this->normalizeRRSetID($id);
				if(isset($this->records[$aID]))
					return $aID;
			}

			if(isset($record) && isset($record['id']) && is_string($record['id']))
			{
				$aID = $this->normalizeRRSetID($record['id']);
				if(isset($this->records[$aID]))
					return $aID;
			}

			$aName = $this->normalizeName($name ?? ($record['name'] ?? null));
			$aType = $this->normalizeType($type ?? ($record['type'] ?? null));
			if($aName === null || $aType === null)
				return false;

			$aID = $aName . '/' . $aType;
			return isset($this->records[$aID]) ? $aID : false;
		}

		private function groupRecordsByRRSet(array $records)
		{
			$aGroups = array();

			foreach($records as $aRecord)
			{
				$aName = $this->normalizeName($aRecord['name'] ?? null);
				$aType = $this->normalizeType($aRecord['type'] ?? null);
				if($aName === null || $aType === null)
					continue;

				$aRecordPayload = $this->buildRecordPayload($aType, null, $aRecord);
				if($aRecordPayload === false)
					continue;

				$aKey = $aName . '/' . $aType;
				if(!isset($aGroups[$aKey]))
					$aGroups[$aKey] = array(
						'name'		=> $aName,
						'type'		=> $aType,
						'ttl'		=> null,
						'records'	=> array(),
					);

				$aTTL = $this->extractTTL($aRecord);
				if($aTTL !== null)
				{
					if($aGroups[$aKey]['ttl'] !== null && $aGroups[$aKey]['ttl'] !== $aTTL)
					{
						$this->lastError = 'Fehler: Unterschiedliche TTL Angaben fuer RRSet ' . $aKey . '.';
						return false;
					}
					$aGroups[$aKey]['ttl'] = $aTTL;
				}

				$aGroups[$aKey]['records'][$aRecordPayload['value']] = $aRecordPayload;
			}

			foreach($aGroups as $aKey => $aGroup)
				$aGroups[$aKey]['records'] = array_values($aGroup['records']);

			return $aGroups;
		}

		private function mergeExistingComments(array $rrset, array $records)
		{
			$aExistingRecords = $this->getRRSetRecordMap($rrset);
			$aMergedRecords = array();

			foreach($records as $aRecord)
			{
				if(!array_key_exists('comment', $aRecord) && isset($aExistingRecords[$aRecord['value']]['comment']))
					$aRecord['comment'] = $aExistingRecords[$aRecord['value']]['comment'];

				$aMergedRecords[] = $aRecord;
			}

			return $aMergedRecords;
		}

		private function recordsAreEqual(array $rrset, array $records)
		{
			$aCurrentRecords = $this->getRRSetRecordMap($rrset);
			$aExpectedRecords = array();
			foreach($records as $aRecord)
				$aExpectedRecords[$aRecord['value']] = $aRecord;

			if(count($aCurrentRecords) != count($aExpectedRecords))
				return false;

			foreach($aExpectedRecords as $aValue => $aExpectedRecord)
			{
				if(!isset($aCurrentRecords[$aValue]))
					return false;

				$aCurrentComment = $aCurrentRecords[$aValue]['comment'] ?? null;
				$aExpectedComment = $aExpectedRecord['comment'] ?? null;
				if($aCurrentComment !== $aExpectedComment)
					return false;
			}

			return true;
		}

		private function createRRSet(string $name, string $type, array $records, ?int $ttl = null)
		{
			$aBody = array(
				'name'		=> $name,
				'type'		=> $type,
				'records'	=> array_values($records),
			);
			if($ttl !== null)
				$aBody['ttl'] = $ttl;

			$aResponse = $this->requestJSON('POST', $this->buildZonePath('/rrsets'), $aBody);
			if($aResponse === false)
				return false;

			if($aResponse['status'] != 201 || !isset($aResponse['body']['rrset']))
			{
				$this->setLastErrorFromResponse($aResponse, 'Fehler beim Erstellen des RRSets');
				return false;
			}

			return $aResponse['body']['rrset'];
		}

		private function deleteRRSet(string $name, string $type)
		{
			$aResponse = $this->requestJSON('DELETE', $this->buildRRSetPath($name, $type));
			if($aResponse === false)
				return false;

			return $this->handleActionResponse($aResponse, 201, 'Fehler beim Loeschen des RRSets');
		}

		private function addRRSetRecords(string $name, string $type, array $records, ?int $ttl = null)
		{
			$aBody = array(
				'records' => array_values($records),
			);
			if($ttl !== null)
				$aBody['ttl'] = $ttl;

			$aResponse = $this->requestJSON('POST', $this->buildRRSetPath($name, $type, '/actions/add_records'), $aBody);
			if($aResponse === false)
				return false;

			return $this->handleActionResponse($aResponse, 201, 'Fehler beim Hinzufuegen von RRSet Records');
		}

			private function setRRSetRecords(string $name, string $type, array $records)
			{
				$aResponse = $this->requestJSON('POST', $this->buildRRSetPath($name, $type, '/actions/set_records'), array(
					'records' => array_values($records),
				));
				if($aResponse === false)
					return false;

				return $this->handleActionResponse($aResponse, 201, 'Fehler beim Setzen von RRSet Records');
			}

			private function setOrCreateRRSetRecords(string $name, string $type, array $records, ?int $ttl = null)
			{
				if($this->setRRSetRecords($name, $type, $records))
					return true;

				if(!str_contains((string)$this->lastError, '404') && !str_contains((string)$this->lastError, 'not_found'))
					return false;

				return $this->createRRSet($name, $type, $records, $ttl) !== false;
			}

		private function removeRRSetRecords(string $name, string $type, array $records)
		{
			$aResponse = $this->requestJSON('POST', $this->buildRRSetPath($name, $type, '/actions/remove_records'), array(
				'records' => array_values($records),
			));
			if($aResponse === false)
				return false;

			return $this->handleActionResponse($aResponse, 201, 'Fehler beim Entfernen von RRSet Records');
		}

		private function updateRRSetRecords(string $name, string $type, array $records)
		{
			$aResponse = $this->requestJSON('POST', $this->buildRRSetPath($name, $type, '/actions/update_records'), array(
				'records' => array_values($records),
			));
			if($aResponse === false)
				return false;

			return $this->handleActionResponse($aResponse, 200, 'Fehler beim Aktualisieren von RRSet Records');
		}

		private function changeRRSetTTL(string $name, string $type, ?int $ttl)
		{
			$aResponse = $this->requestJSON('POST', $this->buildRRSetPath($name, $type, '/actions/change_ttl'), array(
				'ttl' => $ttl,
			));
			if($aResponse === false)
				return false;

			return $this->handleActionResponse($aResponse, 201, 'Fehler beim Aendern der TTL');
		}


		function createRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?array $record = null)
		{
			$aName = $this->normalizeName($name ?? ($record['name'] ?? null));
			$aType = $this->normalizeType($type ?? ($record['type'] ?? null));
			$aTTL = $this->extractTTL($record, $ttl);
			$aRecordPayload = ($aName !== null && $aType !== null) ? $this->buildRecordPayload($aType, $value, $record) : false;

			if($aName === null || $aType === null || $aRecordPayload === false)
			{
				$this->lastError = 'Fehler: Unvollstaendiger Record.';
				return false;
			}

			if(($aID = $this->findRecord(name: $aName, type: $aType, record: $record)) !== false)
			{
				$aRRset = $this->records[$aID];
				if($aTTL !== null && $aRRset['ttl'] !== $aTTL)
				{
					$this->lastError = 'Fehler: TTL Konflikt fuer bestehendes RRSet ' . $aRRset['id'] . '.';
					return false;
				}

				if(($aExistingRecord = $this->findRRSetRecord($aRRset, $aType, $aRecordPayload['value'])) !== false)
					return $this->buildCompatRecord($aRRset, $aExistingRecord);
			}

			if(!$this->addRRSetRecords($aName, $aType, array($aRecordPayload), $aTTL))
				return false;

			if(!$this->refreshRecords())
				return false;

			$aID = $this->findRecord(name: $aName, type: $aType);
			if($aID === false)
			{
				$this->lastError = 'Fehler: RRSet nach dem Erstellen nicht gefunden.';
				return false;
			}

			$aRRset = $this->records[$aID];
			$aCreatedRecord = $this->findRRSetRecord($aRRset, $aType, $aRecordPayload['value']);
			return $this->buildCompatRecord($aRRset, $aCreatedRecord !== false ? $aCreatedRecord : $aRecordPayload);
		}

		function deleteRecord(?string $name = null, ?string $type = null, ?string $id = null, ?array $record = null, ?string $value = null)
		{
			if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $record)) === false)
				return false;

			$aRRset = $this->records[$id];
			$aName = $aRRset['name'];
			$aType = $aRRset['type'];

			if($value === null && isset($record) && array_key_exists('value', $record) && is_string($record['value']))
				$value = $record['value'];

			if(!is_string($value))
			{
				if(!$this->deleteRRSet($aName, $aType))
					return false;
			}
			else
			{
				$aDeleteRecord = $this->findRRSetRecord($aRRset, $aType, $value);
				if($aDeleteRecord === false)
				{
					$this->lastError = 'Fehler: Record zum Loeschen nicht gefunden.';
					return false;
				}

				if(!$this->removeRRSetRecords($aName, $aType, array($aDeleteRecord)))
					return false;
			}

			return $this->refreshRecords();
		}

		function getRecords()
		{
			$aRecords = array();
			$aPage = 1;

			do{
				$aResponse = $this->requestJSON('GET', $this->buildZonePath('/rrsets'), null, array(
					'page'		=> $aPage,
					'per_page'	=> 100,
				));
				if($aResponse === false)
					return false;

				if($aResponse['status'] != 200 || !isset($aResponse['body']['rrsets']) || !is_array($aResponse['body']['rrsets']))
				{
					$this->setLastErrorFromResponse($aResponse, 'Fehler beim Laden der RRSets');
					return false;
				}

				foreach($aResponse['body']['rrsets'] as $aRRset)
					$aRecords[$aRRset['id']] = $aRRset;

				$aPage = $aResponse['body']['meta']['pagination']['next_page'] ?? null;
			}
			while($aPage !== null);

			return $aRecords;
		}

		function multiCreateRecords(array $records)
		{
			$aGroups = $this->groupRecordsByRRSet($records);
			if($aGroups === false || count($aGroups) == 0)
				return false;

			$aCreatedRecords = array();
			foreach($aGroups as $aGroup)
			{
				$aRecordsToCreate = $aGroup['records'];
				if(($aID = $this->findRecord(name: $aGroup['name'], type: $aGroup['type'])) !== false)
				{
					$aRRset = $this->records[$aID];
					if($aGroup['ttl'] !== null && $aRRset['ttl'] !== $aGroup['ttl'])
					{
						$this->lastError = 'Fehler: TTL Konflikt fuer bestehendes RRSet ' . $aRRset['id'] . '.';
						return false;
					}

					$aRecordsToCreate = array_values(array_filter($aRecordsToCreate, function($record) use ($aRRset, $aGroup){
						return $this->findRRSetRecord($aRRset, $aGroup['type'], $record['value']) === false;
					}));

					if(count($aRecordsToCreate) == 0)
						continue;
				}

				if(!$this->addRRSetRecords($aGroup['name'], $aGroup['type'], $aRecordsToCreate, $aGroup['ttl']))
					return false;

				if(!$this->refreshRecords())
					return false;

				$aID = $this->findRecord(name: $aGroup['name'], type: $aGroup['type']);
				if($aID === false)
				{
					$this->lastError = 'Fehler: RRSet nach dem Erstellen nicht gefunden.';
					return false;
				}

				$aRRset = $this->records[$aID];
				foreach($aRecordsToCreate as $aRecord)
					if(($aCreatedRecord = $this->findRRSetRecord($aRRset, $aGroup['type'], $aRecord['value'])) !== false)
						$aCreatedRecords[] = $this->buildCompatRecord($aRRset, $aCreatedRecord);
			}

			return count($aCreatedRecords) > 0 ? $aCreatedRecords : false;
		}

		function multiSetRecords(array $records)
		{
			$aGroups = $this->groupRecordsByRRSet($records);
			if($aGroups === false || count($aGroups) == 0)
				return false;

				foreach($aGroups as $aGroup)
				{
					if(!$this->refreshRecords())
						return false;

					$aID = $this->findRecord(name: $aGroup['name'], type: $aGroup['type']);
					if($aID === false)
					{
						if($this->createRRSet($aGroup['name'], $aGroup['type'], $aGroup['records'], $aGroup['ttl']) === false)
							return false;
					}
				else
				{
					$aRRset = $this->records[$aID];
					$aSetRecords = $this->mergeExistingComments($aRRset, $aGroup['records']);

					if($aGroup['ttl'] !== null && $aRRset['ttl'] !== $aGroup['ttl'])
						if(!$this->changeRRSetTTL($aGroup['name'], $aGroup['type'], $aGroup['ttl']))
							return false;

						if(!$this->recordsAreEqual($aRRset, $aSetRecords))
							if(!$this->setOrCreateRRSetRecords($aGroup['name'], $aGroup['type'], $aSetRecords, $aGroup['ttl']))
								return false;
					}

				if(!$this->refreshRecords())
					return false;
			}

			return true;
		}

		function multiUpdateRecords(array $records)
		{
			$aGroups = $this->groupRecordsByRRSet($records);
			if($aGroups === false || count($aGroups) == 0)
				return false;

			$aDidUpdate = false;
			foreach($aGroups as $aGroup)
			{
				$aID = $this->findRecord(name: $aGroup['name'], type: $aGroup['type']);
				if($aID === false)
					continue;

				$aRRset = $this->records[$aID];
				$aUpdateRecords = $this->mergeExistingComments($aRRset, $aGroup['records']);

				if($aGroup['ttl'] !== null && $aRRset['ttl'] !== $aGroup['ttl'])
					if(!$this->changeRRSetTTL($aGroup['name'], $aGroup['type'], $aGroup['ttl']))
						return false;

				if(!$this->recordsAreEqual($aRRset, $aUpdateRecords))
					if(!$this->setRRSetRecords($aGroup['name'], $aGroup['type'], $aUpdateRecords))
						return false;

				if(!$this->refreshRecords())
					return false;

				$aDidUpdate = true;
			}

			return $aDidUpdate;
		}

		function setRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?string $id = null, ?array $record = null)
		{
			$aName = $this->normalizeName($name ?? ($record['name'] ?? null));
			$aType = $this->normalizeType($type ?? ($record['type'] ?? null));
			$aTTL = $this->extractTTL($record, $ttl);
			$aRecordPayload = ($aName !== null && $aType !== null) ? $this->buildRecordPayload($aType, $value, $record) : false;

			if($aName === null || $aType === null || $aRecordPayload === false)
			{
				$this->lastError = 'Fehler: Unvollstaendiger Record.';
				return false;
			}

			if(($id = $this->findRecord(name: $aName, type: $aType, id: $id, record: $record)) === false)
			{
				if($this->createRRSet($aName, $aType, array($aRecordPayload), $aTTL) === false)
					return false;
			}
			else
			{
				$aRRset = $this->records[$id];
				if($aTTL !== null && $aRRset['ttl'] !== $aTTL)
					if(!$this->changeRRSetTTL($aName, $aType, $aTTL))
						return false;

				if(!$this->recordsAreEqual($aRRset, array($aRecordPayload)))
					if(!$this->setRRSetRecords($aName, $aType, array($aRecordPayload)))
						return false;
			}

			if(!$this->refreshRecords())
				return false;

			$id = $this->findRecord(name: $aName, type: $aType);
			if($id === false)
			{
				$this->lastError = 'Fehler: RRSet nach dem Setzen nicht gefunden.';
				return false;
			}

			$aRRset = $this->records[$id];
			$aCurrentRecord = $this->findRRSetRecord($aRRset, $aType, $aRecordPayload['value']);
			return $this->buildCompatRecord($aRRset, $aCurrentRecord !== false ? $aCurrentRecord : $aRecordPayload);
		}

		function updateRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?string $id = null, ?array $record = null)
		{
			if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $record)) === false)
				return false;

			$aRRset = $this->records[$id];
			$aName = $aRRset['name'];
			$aType = $aRRset['type'];
			$aTTL = $this->extractTTL($record, $ttl);

			if(count($aRRset['records']) != 1)
			{
				$this->lastError = 'Fehler: Update ist nur fuer RRSets mit genau einem Record eindeutig moeglich.';
				return false;
			}

			$aCurrentRecord = array_values($aRRset['records'])[0];
			$aTargetValue = $value;
			if($aTargetValue === null && isset($record) && array_key_exists('value', $record) && is_string($record['value']))
				$aTargetValue = $record['value'];
			if($aTargetValue === null)
				$aTargetValue = $aCurrentRecord['value'];

			$aTargetRecord = array(
				'value' => $this->normalizeRecordValue($aType, $aTargetValue),
			);

			if(isset($record) && array_key_exists('comment', $record) && is_string($record['comment']))
				$aTargetRecord['comment'] = $record['comment'];
			elseif(isset($aCurrentRecord['comment']) && is_string($aCurrentRecord['comment']))
				$aTargetRecord['comment'] = $aCurrentRecord['comment'];

			if($aTTL !== null && $aRRset['ttl'] !== $aTTL)
				if(!$this->changeRRSetTTL($aName, $aType, $aTTL))
					return false;

			if($aTargetRecord['value'] == $aCurrentRecord['value'] && isset($record) && array_key_exists('comment', $record) && is_string($record['comment']))
			{
				if(!$this->updateRRSetRecords($aName, $aType, array(
					array(
						'value'		=> $aCurrentRecord['value'],
						'comment'	=> $record['comment'],
					),
				)))
					return false;
			}
			elseif(!$this->recordsAreEqual($aRRset, array($aTargetRecord)))
				if(!$this->setRRSetRecords($aName, $aType, array($aTargetRecord)))
					return false;

			if(!$this->refreshRecords())
				return false;

			$aRRset = $this->records[$id] ?? null;
			if(!is_array($aRRset))
			{
				$this->lastError = 'Fehler: RRSet nach dem Update nicht gefunden.';
				return false;
			}

			$aUpdatedRecord = $this->findRRSetRecord($aRRset, $aType, $aTargetRecord['value']);
			return $this->buildCompatRecord($aRRset, $aUpdatedRecord !== false ? $aUpdatedRecord : $aTargetRecord);
		}
	}

?>
