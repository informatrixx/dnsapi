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
		
		private function findRecord(?string $name = null, ?string $type = null, ?string $id = null, ?array $record = null)
		{
			if(isset($id) && isset($this->records[$id]))
				return $id;
			
			if(isset($record) && isset($record['id']) && isset($this->records[$record['id']]))
				return $record['id'];
																			  
			
			foreach($this->records as $aID => $aRecordData)
			{
				
				if(isset($name) && isset($type) && $aRecordData['hostname'] == $name && $aRecordData['type'] == $type)
					return $aRecordData['id'];
				
				if(isset($record) && isset($record['hostname']) && isset($record['type']) && $aRecordData['hostname'] == $record['hostname'] && $aRecordData['type'] == $record['type'])
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
            if($aResponseData['status'] === 'success')
            {
            	$this->pSessionID = $aResponseData['responsedata']['apisessionid'];
            	$this->records = $this->getRecords();
            	return true;
            }
            else
            	return false;
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
            if($aResponseData['status'] === 'success')
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
            if($aResponseData['status'] === 'success')
            {
            	$this->updateDNSRecordsList(responseData: $aResponseData);
            	$aReturnRecords = array();
            	foreach($records as $aUpdateRecord)
            	{
            		$aUpdateHostname = preg_replace(pattern: '/\.' . $this->pZoneID . '\.$/', replacement: '', subject: $aUpdateRecord['hostname']);
					foreach($aResponseData['responsedata']['dnsrecords'] as $aDNSRecord)
						if($aDNSRecord['hostname'] == $aUpdateHostname && $aDNSRecord['type'] == $aUpdateRecord['type'] && $aDNSRecord['destination'] == $aUpdateRecord['destination'])
						{
							$aReturnRecords[] = $aDNSRecord;
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
            	$this->lastError = $aResponseData['longmessage'];
            	return false;
            }
		}
		
		private function updateDNSRecordsList(array $responseData)
		{
			$this->records = array();
			foreach($responseData['responsedata']['dnsrecords'] as $aDNSRecord)
				$this->records[$aDNSRecord['id']] = $aDNSRecord;
		}

		function createRecord(?string $name = null, string $type = null, ?string $value = null, ?int $ttl = null, ?array $record = null)
		{
			return $this->setRecord(name: $name, type: $type, value: $value, record: $record);
		}
		
		function deleteRecord(?string $name = null, ?string $type = null, ?string $id = null, ?array $record = null, ?string $value = null)
		{
			//Record in bestehenden DNS Records finden
			if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $record)) == false)
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
			
            if($aResponseData['status'] !== 'success')
            {
            	$this->updateDNSRecordsList(responseData: $aResponseData);
            	$this->lastError = $aResponseData['longmessage'];
            	return false;
            }
            
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
			
            if($aResponseData['status'] !== 'success')
            {
            	$this->lastError = $aResponseData['longmessage'];
            	return false;
            }
            	
            $aResponseData = json_decode(json: $aCURLResponse, associative: true);
            $this->updateDNSRecordsList(responseData: $aResponseData);
            
			$aReturnRecords = array();
			//Erhaltene DNS Records auswerten
			//und als array key die recordID verwenden
			foreach($aResponseData['responsedata']['dnsrecords'] as $record)
				$aReturnRecords[$record['id']] = $record;
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
				if(empty($record) || empty($record['name']) || empty($record['type']))
					continue;	//Wenn record unvollständig, überspringen
				$aCreateRecord = $record;
				$aCreateRecord['hostname'] = $record['name'];
				$aCreateRecord['destination'] = isset($record['value']) ? $record['value'] : '';
				
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
			if((empty($name) || empty($type)) && (empty($record) || empty($record['name']) || empty($record['type'])))
				return false;
			
			if(!empty($record))	//Wenn array $record definiert ist
				$aSetRecord = array(
					'hostname' => $record['name'],
					'destination' => !empty($record['value']) ? $record['value'] : '',
					'type' => $record['type'],
					);
			else
			{
				$aSetRecord = array(
					'hostname' => $name,
					'destination' => !empty($value) ? $value : '',
					'type' => $type,
					);
				if(!empty($id))
					$aSetRecord['id'] = $id;
			}

			if(empty($aSetRecord['id']))
			{
				$aSetRecord['id'] = $this->findRecord(record: $aSetRecord);
				echo "Found ID: {$aSetRecord['id']}" . PHP_EOL;
			}

			return $this->netcupUpdateDNSRecords(array($aSetRecord));
		}
		
		function updateRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?string $id = null, ?array $record = null)
		{
			if(empty($id) && (empty($record) || empty($record['id'])))
			{
				if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $record)) == false)
					return false;	//Wenn Record noch nicht existiert, dann ist kein Update möglich
			}
			return $this->setRecord(name: $name, type: $type, value: $value, id: $id, record: $record);
		}
	}

?>