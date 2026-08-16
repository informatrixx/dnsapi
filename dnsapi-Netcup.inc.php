<?php
	class netcupAPI implements DNSAPIv1{

		private $pZoneID;
		private $pCURL;
		private $pAPIConfig;

		private $pSessionID = null;

		public $lastError = null;

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

				$this->login();
			}

		function __destruct()
		{
				$this->logout();
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
				if(isset($record['priority']))
					$aRecord['priority'] = (int)$record['priority'];
				elseif($aType === 'MX' && preg_match('/^(?<priority>\d+)\s+(?<destination>.+)$/', $aValue, $aMatches))
				{
					$aRecord['priority'] = (int)$aMatches['priority'];
					$aRecord['destination'] = $aMatches['destination'];
				}
				else
					$aRecord['priority'] = 0;
				if($ttl !== null)
					$aRecord['ttl'] = $ttl;

				return $aRecord;
			}

			private function buildCompatRecord(array $record)
			{
				$aValue = (string)($record['destination'] ?? '');
				if(($record['type'] ?? '') === 'MX' && isset($record['priority']) && (int)$record['priority'] > 0)
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

				foreach($this->records as $aID => $aRecordData)
				{
					if(isset($aName) && isset($aType) && $aRecordData['hostname'] == $aName && $aRecordData['type'] == $aType && (!isset($aValue) || trim($aRecordData['destination'], '"') == $aValue))
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

            $aCURLResponse = curl_exec($this->pCURL);

            if(curl_errno($this->pCURL))
            {
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE);
				return false;
			}

	            $aResponseData = json_decode(json: $aCURLResponse, associative: true);
	            if(is_array($aResponseData) && ($aResponseData['status'] ?? null) === 'success')
	            {
		$this->pSessionID = $aResponseData['responsedata']['apisessionid'];
		$this->records = $this->getRecords();
		return true;
	            }
	            else
	            {
		$this->lastError = is_array($aResponseData) ? ($aResponseData['longmessage'] ?? 'Login fehlgeschlagen') : 'Ungueltige Netcup API Antwort beim Login';
		return false;
	            }
		}

		private function logout()
		{
			$aJSONData = array(
				'action' => 'logout',
				'param' => array(
					'customernumber' => $this->pAPIConfig['KundenNR'],
					'apikey' => $this->pAPIConfig['APIKey'],
					'apipassword' => $this->pAPIConfig['APIPass'],
				)
            );

            $aCURLBody = json_encode($aJSONData);
            curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);

            $aCURLResponse = curl_exec($this->pCURL);

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

			$aCURLResponse = curl_exec($this->pCURL);

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
						foreach($aResponseData['responsedata']['dnsrecords'] as $aDNSRecord)
							if(
								$aDNSRecord['hostname'] == $aUpdateRecord['hostname'] &&
								$aDNSRecord['type'] == $aUpdateRecord['type'] &&
								trim($aDNSRecord['destination'], '"') == trim($aUpdateRecord['destination'], '"')
							)
							{
								$aReturnRecords[] = $this->buildCompatRecord($aDNSRecord);
								break;
							}
					}
				if(count($aReturnRecords) > 1)
					return $aReturnRecords;
				elseif(count($aReturnRecords) == 1)
					return $aReturnRecords[0];
				else
					return false;
            }
            else
            {
		$this->lastError = $aResponseData['longmessage'] ?? 'Netcup DNS Update fehlgeschlagen.';
		return false;
	            }
		}

		private function updateDNSRecordsList(array $responseData)
		{
			$this->records = array();
				foreach($responseData['responsedata']['dnsrecords'] ?? array() as $aDNSRecord)
					$this->records[(string)$aDNSRecord['id']] = $aDNSRecord;
			}

			function createRecord(?string $name = null, string $type = null, ?string $value = null, ?int $ttl = null, ?array $record = null)
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
			//Record in bestehenden DNS Records finden
				if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $record, value: $value)) == false)
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

			$aCURLResponse = curl_exec($this->pCURL);

				$aResponseData = json_decode(json: $aCURLResponse, associative: true);
				if(!is_array($aResponseData))
				{
					$this->lastError = 'Ungueltige Netcup API Antwort.';
					return false;
				}

	            if(($aResponseData['status'] ?? null) !== 'success')
	            {
		$this->lastError = $aResponseData['longmessage'] ?? 'Netcup DNS Delete fehlgeschlagen.';
		return false;
	            }

	            $this->updateDNSRecordsList(responseData: $aResponseData);
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

			$aCURLResponse = curl_exec($this->pCURL);

				$aResponseData = json_decode(json: $aCURLResponse, associative: true);

				if(!is_array($aResponseData))
				{
					$this->lastError = 'Ungueltige Netcup API Antwort.';
					return false;
				}

	            if(($aResponseData['status'] ?? null) !== 'success')
	            {
		$this->lastError = $aResponseData['longmessage'] ?? 'Netcup DNS Records konnten nicht geladen werden.';
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
			return $this->multiUpdateRecords(records: $records);
		}

		function multiUpdateRecords(array $records)
		{
				$aCreateRecords = array();
				foreach($records as $record)
				{
					if(empty($record))
						continue;	//Wenn record unvollständig, überspringen
					$aCreateRecord = $this->normalizeRecord($record);
					if($aCreateRecord === false)
						continue;

					$aCreateRecords[] = $aCreateRecord;
				}
			if(count($aCreateRecords) == 0)
				return false;

			return $this->netcupUpdateDNSRecords($aCreateRecords);
		}

		function multiSetRecords(array $records)
		{
			return $this->multiCreateRecords(records: $records);
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
				elseif(empty($aSetRecord['id']) && ($aID = $this->findRecord(record: $aSetRecord)) !== false)
					$aSetRecord['id'] = $aID;

				return $this->netcupUpdateDNSRecords(array($aSetRecord));
			}

		function updateRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?string $id = null, ?array $record = null)
		{
			if(empty($id) && (empty($record) || empty($record['id'])))
			{
					if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $record, value: $value)) == false)
						return false;	//Wenn Record noch nicht existiert, dann ist kein Update möglich
				}
			return $this->setRecord(name: $name, type: $type, value: $value, id: $id, record: $record);
		}
	}

?>
