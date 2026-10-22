<?php
	require_once __DIR__ . '/../bootstrap.php';
	class netcupAPI implements DNSAPIv1{

		private $pZoneID;
		private $pCURL;
		private $pAPIConfig;

		private $pSessionID = null;

		public $lastError = null;
		public $records = array();

		function __construct(string $zoneID, array $APIConfig)
		{
			$this->pZoneID = $zoneID;
			$this->pAPIConfig = $APIConfig;

			$this->pCURL = curl_init();
			$aCURLOptions = array(
				CURLOPT_POST			=> 1,
				CURLOPT_TIMEOUT			=> 30,
				CURLOPT_RETURNTRANSFER	=> 1,
				CURLOPT_FAILONERROR		=> 1,
				CURLOPT_HTTPHEADER		=> array('Content-Type: application/json'),
				CURLOPT_URL				=> $this->pAPIConfig['endpointURL'],
			);
			curl_setopt_array($this->pCURL, $aCURLOptions);

				if(!$this->login()) throw new RuntimeException('PROVIDER_LOGIN_FAILED');
			}

		function __destruct()
		{
				try { $this->logout(); } catch(Throwable $error) { /* Expired hook budget: session expires server-side. */ }
			}

			private function normalizeName(?string $name)
			{
				if(!is_string($name))
					return null;

				$name = strtolower(trim($name));
				if($name === '' || $name === '@')
					return '@';

				$name = rtrim($name, '.');
				$aZoneSuffix = '.' . strtolower(trim($this->pZoneID, '.'));
				if($name === strtolower(trim($this->pZoneID, '.')))
					return '@';
				if(str_ends_with($name, $aZoneSuffix))
					$name = substr($name, 0, -strlen($aZoneSuffix));

				return $name === '' ? '@' : $name;
			}

			private function normalizeType(?string $type)
			{
				return is_string($type) ? strtoupper(trim($type)) : null;
			}

			private function normalizeValue(?string $value)
			{
				return is_string($value) ? trim($value, '"') : null;
			}

			private function normalizeRecord(array $record, ?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null)
			{
				$aName = $this->normalizeName($name ?? ($record['name'] ?? ($record['hostname'] ?? null)));
				$aType = $this->normalizeType($type ?? ($record['type'] ?? null));
				$aValue = $this->normalizeValue($value ?? ($record['value'] ?? ($record['destination'] ?? null)));

				if($aName === null || $aType === null || $aValue === null)
					return false;

				$aRecord = array(
					'hostname'	=> $aName,
					'type'		=> $aType,
					'destination'	=> $aValue,
				);

				if(isset($record['id']) && is_string((string)$record['id']) && (string)$record['id'] !== '')
					$aRecord['id'] = (string)$record['id'];
				if($aType === 'MX' && preg_match('/^(?<priority>\d+)\s+(?<destination>.+)$/', $aValue, $aMatches))
				{
					if(isset($record['priority']) && (int)$record['priority'] !== (int)$aMatches['priority']) return false;
					$aRecord['priority'] = (int)$aMatches['priority'];
					$aRecord['destination'] = $aMatches['destination'];
				}
				elseif(isset($record['priority']))
					$aRecord['priority'] = (int)$record['priority'];
				else
					$aRecord['priority'] = 0;
				// Legacy Netcup exposes TTL at zone level, not in updateDnsRecords.

				return $aRecord;
			}

			private function buildCompatRecord(array $record)
			{
				$aValue = (string)($record['destination'] ?? '');
				if(($record['type'] ?? '') === 'MX' && isset($record['priority']))
					$aValue = (int)$record['priority'] . ' ' . $aValue;

				return array(
					'id'		=> (string)$record['id'],
					'journal_id'	=> (string)$record['id'],
					'name'		=> $record['hostname'],
					'type'		=> $record['type'],
					'value'		=> $aValue,
					'hostname'	=> $record['hostname'],
					'destination'	=> $record['destination'],
					'priority'	=> $record['priority'] ?? 0,
				);
			}

			private function findRecord(?string $name = null, ?string $type = null, ?string $id = null, ?array $record = null, ?string $value = null)
			{
				if(isset($id) && isset($this->records[$id]))
					return $id;

				if(isset($record) && isset($record['id']) && isset($this->records[$record['id']]))
					return $record['id'];

				$aName = $this->normalizeName($name ?? ($record['name'] ?? ($record['hostname'] ?? null)));
				$aType = $this->normalizeType($type ?? ($record['type'] ?? null));
				$aValue = $this->normalizeValue($value ?? ($record['value'] ?? ($record['destination'] ?? null)));
				$aPriority = isset($record['priority']) ? (int)$record['priority'] : null;

				foreach($this->records as $aID => $aRecordData)
				{
					if(isset($aName) && isset($aType) && $aRecordData['hostname'] == $aName && $aRecordData['type'] == $aType && ($aPriority === null || (int)($aRecordData['priority'] ?? 0) === $aPriority) && (!isset($aValue) || trim($aRecordData['destination'], '"') == $aValue))
						return $aRecordData['id'];

				}

				return false;
			}

		private function login()
		{
			$aJSONData = array(
				'action' => 'login',
				'param' => array(
					'customernumber' => $this->pAPIConfig['KundenNR'],
					'apikey' => $this->pAPIConfig['APIKey'],
					'apipassword' => $this->pAPIConfig['APIPass'],
				)
            );

            $aCURLBody = json_encode($aJSONData);
            curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);

            $aCURLResponse = dnsapiCurlExec($this->pCURL);

            if(curl_errno($this->pCURL))
            {
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE);
				return false;
			}

	            $aResponseData = json_decode(json: $aCURLResponse, associative: true);
	            if(is_array($aResponseData) && ($aResponseData['status'] ?? null) === 'success' && is_string($aResponseData['responsedata']['apisessionid'] ?? null))
	            {
		$this->pSessionID = $aResponseData['responsedata']['apisessionid'];
		return $this->getRecords() !== false;
	            }
	            else
	            {
		$this->lastError = 'PROVIDER_LOGIN_FAILED';
		return false;
	            }
		}

		private function logout()
		{
			if($this->pSessionID === null) return true;
			$aJSONData = array(
				'action' => 'logout',
				'param' => array(
					'customernumber' => $this->pAPIConfig['KundenNR'],
					'apikey' => $this->pAPIConfig['APIKey'],
					'apisessionid' => $this->pSessionID,
				)
            );

            $aCURLBody = json_encode($aJSONData);
            curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);

            $aCURLResponse = dnsapiCurlExec($this->pCURL);

            if(curl_errno($this->pCURL))
            {
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE);
				return false;
			}

            $aResponseData = json_decode(json: $aCURLResponse, associative: true);
	            if(is_array($aResponseData) && ($aResponseData['status'] ?? null) === 'success')
		return true;
	            else
		return false;
		}

		private function netcupUpdateDNSRecords(array $records)
		{
			$aJSONData = array(
				'action' => 'updateDnsRecords',
				'param' => array(
					'domainname' => $this->pZoneID,
					'customernumber' => $this->pAPIConfig['KundenNR'],
					'apikey' => $this->pAPIConfig['APIKey'],
					'apisessionid' => $this->pSessionID,
					'dnsrecordset' => array(
						'dnsrecords' => $records
					)
				)
			);

			$aCURLBody = json_encode($aJSONData);

			curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);

			$aCURLResponse = dnsapiCurlExec($this->pCURL);

				$aResponseData = json_decode(json: $aCURLResponse, associative: true);
				if(!is_array($aResponseData))
				{
					$this->lastError = 'Ungueltige Netcup API Antwort.';
					return false;
				}
	            if(($aResponseData['status'] ?? null) === 'success')
	            {
		$this->updateDNSRecordsList(responseData: $aResponseData);
		$aReturnRecords = array();
		foreach($records as $aUpdateRecord)
		{
			if($aUpdateRecord['deleterecord'] ?? false) continue;
						foreach($aResponseData['responsedata']['dnsrecords'] as $aDNSRecord)
							if(
								$aDNSRecord['hostname'] == $aUpdateRecord['hostname'] &&
								$aDNSRecord['type'] == $aUpdateRecord['type'] &&
								trim($aDNSRecord['destination'], '"') == trim($aUpdateRecord['destination'], '"') &&
								(int)($aDNSRecord['priority'] ?? 0) === (int)($aUpdateRecord['priority'] ?? 0)
							)
							{
								$aReturnRecords[] = $this->buildCompatRecord($aDNSRecord);
								break;
							}
					}
				if(count($aReturnRecords) !== count(array_filter($records, fn($r) => !($r['deleterecord'] ?? false)))) { $this->lastError = 'PROVIDER_CHANGE_UNCONFIRMED'; return false; }
				if(count($aReturnRecords) > 1)
					return $aReturnRecords;
				elseif(count($aReturnRecords) == 1)
					return $aReturnRecords[0];
				else
					return false;
            }
            else
            {
		$this->lastError = 'PROVIDER_UPDATE_FAILED';
		return false;
	            }
		}

		private function updateDNSRecordsList(array $responseData)
		{
			if(!is_array($responseData['responsedata']['dnsrecords'] ?? null) || !array_is_list($responseData['responsedata']['dnsrecords'])) throw new RuntimeException('PROVIDER_RESPONSE_INVALID');
			$records = array();
				foreach($responseData['responsedata']['dnsrecords'] ?? array() as $aDNSRecord)
				{
					if(!is_array($aDNSRecord) || (!is_string($aDNSRecord['id'] ?? null) && !is_int($aDNSRecord['id'] ?? null))) throw new RuntimeException('PROVIDER_RECORD_INVALID');
					foreach(array('hostname', 'type', 'destination') as $field) if(!is_string($aDNSRecord[$field] ?? null)) throw new RuntimeException('PROVIDER_RECORD_INVALID');
					if((string)$aDNSRecord['id'] === '' || isset($records[(string)$aDNSRecord['id']])) throw new RuntimeException('PROVIDER_RECORD_INVALID');
					$records[(string)$aDNSRecord['id']] = $aDNSRecord;
				}
				$this->records = $records;
			}

			function createRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?array $record = null)
			{
				$aRecord = $this->normalizeRecord($record ?? array(), name: $name, type: $type, value: $value, ttl: $ttl);
				if($aRecord === false)
				{
					$this->lastError = 'Fehler: Unvollstaendiger Record.';
					return false;
				}

				if(($aID = $this->findRecord(record: $aRecord, value: $aRecord['destination'])) !== false)
					return $this->buildCompatRecord($this->records[$aID]);

				$aCreatedRecord = $this->netcupUpdateDNSRecords(array($aRecord));
				if($aCreatedRecord === false)
					return false;

				return $aCreatedRecord;
			}

		function deleteRecord(?string $name = null, ?string $type = null, ?string $id = null, ?array $record = null, ?string $value = null)
		{
			if($this->getRecords() === false) return false;
			$selectedID = $id ?? ($record['id'] ?? null);
			$selectedName = $this->normalizeName($name ?? ($record['name'] ?? $record['hostname'] ?? null));
			$selectedType = $this->normalizeType($type ?? ($record['type'] ?? null));
			$selectedValue = $this->normalizeValue($value ?? ($record['value'] ?? $record['destination'] ?? null));
			if($selectedValue !== null) {
				$actual = $selectedID !== null ? ($this->records[$selectedID] ?? array()) : array();
				$lookup = $this->normalizeRecord($record ?? array(), $selectedName ?? ($actual['hostname'] ?? null), $selectedType ?? ($actual['type'] ?? null), $selectedValue);
				if($lookup === false) { $this->lastError = 'RECORD_INCOMPLETE'; return false; }
				$selectedValue = $lookup['type'] === 'MX' ? $lookup['priority'] . ' ' . $lookup['destination'] : $lookup['destination'];
			}
			if($selectedID !== null && isset($this->records[$selectedID])) {
				$old = $this->buildCompatRecord($this->records[$selectedID]);
				if(($selectedName !== null && $old['name'] !== $selectedName) || ($selectedType !== null && $old['type'] !== $selectedType) || ($selectedValue !== null && $this->normalizeValue($old['value']) !== $selectedValue)) { $this->lastError = 'RECORD_SELECTOR_MISMATCH'; return false; }
			} elseif($selectedID !== null) { $this->lastError = 'RECORD_NOT_FOUND'; return false; }
			if($selectedID === null && ($selectedName === null || $selectedType === null)) { $this->lastError = 'RECORD_INCOMPLETE'; return false; }
			if($selectedID === null && $selectedValue === null && count(array_filter($this->records, fn($r) => $r['hostname'] === $selectedName && $r['type'] === $selectedType)) > 1) { $this->lastError = 'RECORD_AMBIGUOUS'; return false; }
			//Record in bestehenden DNS Records finden
				if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $lookup ?? $record, value: isset($lookup) ? $lookup['destination'] : $value)) === false)
					return false;	//Wenn Record noch nicht existiert, dann ist kein Delete möglich

			$record = $this->records[$id];

			$record['deleterecord'] = true;
			$aJSONData = array(
				'action' => 'updateDnsRecords',
				'param' => array(
					'domainname' => $this->pZoneID,
					'customernumber' => $this->pAPIConfig['KundenNR'],
					'apikey' => $this->pAPIConfig['APIKey'],
					'apisessionid' => $this->pSessionID,
					'dnsrecordset' => array(
						'dnsrecords' => array($record)
						)
					)
				);

			$aCURLBody = json_encode($aJSONData);

			curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);

			$aCURLResponse = dnsapiCurlExec($this->pCURL);

				$aResponseData = json_decode(json: $aCURLResponse, associative: true);
				if(!is_array($aResponseData))
				{
					$this->lastError = 'Ungueltige Netcup API Antwort.';
					return false;
				}

	            if(($aResponseData['status'] ?? null) !== 'success')
	            {
		$this->lastError = 'PROVIDER_DELETE_FAILED';
		return false;
	            }

	            $this->updateDNSRecordsList(responseData: $aResponseData);
	            if(isset($this->records[$id])) { $this->lastError = 'PROVIDER_CHANGE_UNCONFIRMED'; return false; }
	            return true;
			}

		function getRecords()
		{
			$aJSONData = array(
				'action' => 'infoDnsRecords',
				'param' => array(
					'domainname' => $this->pZoneID,
					'customernumber' => $this->pAPIConfig['KundenNR'],
					'apikey' => $this->pAPIConfig['APIKey'],
					'apisessionid' => $this->pSessionID,
					)
				);

			$aCURLBody = json_encode($aJSONData);
			curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);

			$aCURLResponse = dnsapiCurlExec($this->pCURL);

				$aResponseData = json_decode(json: $aCURLResponse, associative: true);

				if(!is_array($aResponseData))
				{
					$this->lastError = 'Ungueltige Netcup API Antwort.';
					return false;
				}

	            if(($aResponseData['status'] ?? null) !== 'success')
	            {
		$this->lastError = 'PROVIDER_READ_FAILED';
		return false;
	            }

	            $this->updateDNSRecordsList(responseData: $aResponseData);

			$aReturnRecords = array();
			//Erhaltene DNS Records auswerten
			//und als array key die recordID verwenden
				foreach($aResponseData['responsedata']['dnsrecords'] as $record)
					$aReturnRecords[(string)$record['id']] = $record;
				return $aReturnRecords;
			}

		function multiCreateRecords(array $records)
		{
			$result = array();
			foreach($records as $record) {
				$created = $this->createRecord(record: $record);
				if($created === false) return false;
				$result[] = $created;
			}
			return $result ?: false;
		}

		function multiUpdateRecords(array $records)
		{
			if($this->getRecords() === false) return false;
				$aCreateRecords = array();
				foreach($records as $record)
				{
					if(empty($record)) return false;
					$aCreateRecord = $this->normalizeRecord($record);
					if($aCreateRecord === false) return false;
					$id = $this->updateTarget($aCreateRecord);
					if($id === false) return false;
					$aCreateRecord['id'] = $id;

					$aCreateRecords[] = $aCreateRecord;
				}
			if(count($aCreateRecords) == 0)
				return false;

			return $this->netcupUpdateDNSRecords($aCreateRecords);
		}

		function multiSetRecords(array $records)
		{
			if($this->getRecords() === false) return false;
			$groups = array();
			foreach($records as $record) {
				$normalized = $this->normalizeRecord($record);
				if($normalized === false) return false;
				$key = $normalized['hostname'] . '/' . $normalized['type'];
				$groups[$key][$normalized['priority'] . ' ' . $normalized['destination']] = $normalized;
			}
			$changes = array();
			foreach($groups as $key => $desired) {
				$existing = array_filter($this->records, fn($r) => $r['hostname'] . '/' . $r['type'] === $key);
				foreach($desired as $record) {
					$match = null;
					foreach($existing as $id => $old) if($old['destination'] === $record['destination'] && (int)($old['priority'] ?? 0) === $record['priority']) { $match = $id; break; }
					if($match === null && $existing) $match = array_key_first($existing);
					if($match !== null) { $record['id'] = (string)$existing[$match]['id']; unset($existing[$match]); }
					$changes[] = $record;
				}
				foreach($existing as $record) { $record['deleterecord'] = true; $changes[] = $record; }
			}
			return $changes ? $this->netcupUpdateDNSRecords($changes) : false;
		}

		private function updateTarget(array $record): string|false {
			if(!empty($record['id'])) {
				$id = (string)$record['id'];
				if(isset($this->records[$id])) return $id;
				$this->lastError = 'RECORD_NOT_FOUND';
				return false;
			}
			$matches = array_filter($this->records, fn($r) => $r['hostname'] === $record['hostname'] && $r['type'] === $record['type']);
			if(count($matches) === 1) return (string)reset($matches)['id'];
			$id = $this->findRecord(record: $record);
			if($id !== false) return (string)$id;
			$this->lastError = $matches ? 'RECORD_AMBIGUOUS: select an ID or explicitly replace the RRSet' : 'RECORD_NOT_FOUND';
			return false;
		}

			function setRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?string $id = null, ?array $record = null)
			{
				$aSetRecord = $this->normalizeRecord($record ?? array(), name: $name, type: $type, value: $value, ttl: $ttl);
				if($aSetRecord === false)
				{
					$this->lastError = 'Fehler: Unvollstaendiger Record.';
					return false;
				}
				if(!empty($id))
					$aSetRecord['id'] = $id;
				if($this->getRecords() === false) return false;
				$aID = $this->updateTarget($aSetRecord);
				if($aID !== false) $aSetRecord['id'] = $aID;
				elseif($this->lastError !== 'RECORD_NOT_FOUND' || !empty($aSetRecord['id'])) return false;

				return $this->netcupUpdateDNSRecords(array($aSetRecord));
			}

		function updateRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?string $id = null, ?array $record = null)
		{
			if($this->getRecords() === false) return false;
			$data = $this->normalizeRecord($record ?? array(), $name, $type, $value, $ttl);
			if($data === false) return false;
			if($id !== null) $data['id'] = $id;
			$id = $this->updateTarget($data);
			if($id === false) return false;
			$data['id'] = $id;
			return $this->netcupUpdateDNSRecords(array($data));
		}
	}

?>
