<?php
	class hetznerCloudAPI implements DNSAPIv1{
		
		private $pZoneID;
		private $pCURL;
		private $pAPIConfig;
		
		public $lastError = null;
		public $records = array();
		
		function __construct(string $zoneID, array $APIConfig)
		{
			$this->pZoneID = $zoneID;
			$this->pAPIConfig = $APIConfig;
			$this->pCURL = curl_init();
			curl_setopt($this->pCURL, CURLOPT_RETURNTRANSFER, 1);
			
			$this->records = $this->getRecords();
		}
		
		private function assembleRRset(?array $record, ?string $id = null, ?string $name = null, ?string $value = null, ?string $type = null, ?int $ttl = null,  bool $replace = false)
		{
			$aRRset = $id != null ? $this->records[$id] : [];
			
			if(is_int($ttl))
				$aRRset['ttl'] = $ttl;
			elseif(isset($record['ttl']) && is_int($record['ttl']))
				$aRRset['ttl'] = $record['ttl'];
			if(is_string($name))
				$aRRset['name'] = $name;
			elseif(isset($record['name']) && is_string($record['name']))
				$aRRset['name'] = $record['name'];
			if(is_string($type))
				$aRRset['type'] = strtoupper($type);
			elseif(isset($record['type']) && is_string($record['type']))
				$aRRset['type'] = strtoupper($record['type']);
			
			if (defined('DOMAIN_API_LOADED') && str_ends_with($aRRset['name'], '.' . DOMAIN_API_LOADED . '.')) 
				$aRRset['name'] = substr($aRRset['name'], 0, -strlen(DOMAIN_API_LOADED) - 2);
			
			if(is_string($value))
				$aValue = $value;
			elseif(isset($record['value']) && is_string($record['value']))
				$aValue = $record['value'];
			else 
				$aValue = '';
				
			if(isset($aRRset['type']) && (strtoupper($aRRset['type']) == 'TXT') && strlen($aValue) > 0 && ($aValue[0] !== '"' || substr($aValue, -1) !== '"'))
				$aValue = '"' . addcslashes($aValue, '"\\') . '"';
			
			if($replace)
				$aRRset['records'] = [[
					'value'	=>	$aValue,
					]];
			else
				$aRRset['records'][] = [
					'value'	=>	$aValue,
					];
				
			
			return $aRRset;
		}
		
		private function multiAssembleRRset(array $records)
		{
			$aRRsets = [];
			
			foreach($records as $aRecord)
			{
				$aRRset = $this->assembleRRset($aRecord);
				$aID = $aRRset['name'] . '/' . $aRRset['type'];
				if(isset($aRRsets[$aID]))
					$aRRsets[$aID]['records'][] = $aRRset['records'][0];
				else
					$aRRsets[$aID] = $aRRset;
			}
			
			return $aRRsets;
		}
		
		private function findRecord(?string $name = null, ?string $type = null, ?string $id = null, ?array $record = null)
		{
			if(isset($id) && isset($this->records[$id]))
				return $id;
			
			if(isset($record) && isset($record['id']) && isset($this->records[$record['id']]))
				return $record['id'];
																			  
			foreach($this->records as $aID => $aRecordData)
			{
				if(isset($name) && isset($type) && strtolower($aRecordData['name']) == strtolower($name) && strtoupper($aRecordData['type']) == strtoupper($type))
					return $aRecordData['id'];
				if(isset($record) && isset($record['name']) && isset($record['type']) && $aRecordData['name'] == $record['name'] && strtoupper($aRecordData['type']) == strtoupper($record['type']))
					return $aRecordData['id'];
			}
			
			return false;
		}


		function createRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?array $record = null)
		{
			//Wenn kein $name || $type 
			//oder kein array $record mit ['name'] || ['type']
			//unvollständiger record
			if((empty($name) || empty($type)) && (empty($record) || empty($record['name']) || empty($record['type'])))
				return false;
			
			//existiert bereits, also RRecord erweitern
			if(($id = $this->findRecord(name: $name, type: $type, record: $record)) !== false)
				$aRRset = $this->assembleRRset(record: $record, id: $id, replace: false, value: $value);
			else
				$aRRset = $this->assembleRRset(record: $record, id: null, replace: false, value: $value, name: $name, ttl: $ttl, type: $type);
			
			$aCURLBody = json_encode($aRRset, JSON_UNESCAPED_SLASHES || JSON_PRETTY_PRINT);
			
			//API
			$aURL = "{$this->pAPIConfig['recordsURL']}/{$this->pZoneID}/rrsets";
			
			if(!empty($id))	//Wenn ID existiert, dann nur records updaten.
			{
				$aURL .= "/$id/actions/set_records";
				$aCURLBody = json_encode(['records' => $aRRset['records']], JSON_UNESCAPED_SLASHES || JSON_PRETTY_PRINT);
			}
			
			curl_setopt($this->pCURL, CURLOPT_URL, $aURL);
			curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $this->pAPIConfig['APIToken']]);
			curl_setopt($this->pCURL, CURLOPT_POST, 1);
			curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);
			
			
			$aCURLResponse = curl_exec($this->pCURL);
			
			if(curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) != '201')
			{
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) . " $aCURLResponse";
				print_r($aRRset);
				echo PHP_EOL;
				die('Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) . " $aCURLResponse");
				return false;
			}
			
			$this->records = $this->getRecords();
			
			$aRecordID = $this->findRecord(record: $aRRset);
			
			//Erstellten Record zurückgeben
			echo "ID: $aRecordID" . PHP_EOL;
			return $this->records[$aRecordID];
		}
		
		function deleteRecord(?string $name = null, ?string $type = null, ?string $id = null, ?array $record = null, ?string $value = null)
		{
			//Record in bestehenden DNS Records finden
			if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $record)) == false)
				return false;	//Wenn Record noch nicht existiert, dann ist kein Delete möglich
			
			if(empty($value))
			{
				$aRRset = $this->assembleRRset(record: $record ?? [], id: $id, replace: false, name: $name, type: $type);
				curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'DELETE');
				curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}/{$this->pZoneID}/rrsets/$id");
			curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->pAPIConfig['APIToken']]);
			}
			else
			{
				$aRRset = $this->assembleRRset(record: $record ?? [], id: $id, replace: true, value: $value, name: $name, type: $type);
				$aCURLBody = json_encode(['records' => $aRRset['records']], JSON_UNESCAPED_SLASHES || JSON_PRETTY_PRINT);
				curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'POST');
				curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $this->pAPIConfig['APIToken']]);
				curl_setopt($this->pCURL, CURLOPT_POST, 1);
				curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);
				curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}/{$this->pZoneID}/rrsets/$id/actions/remove_records");
				
			}
			
			
			$aCURLResponse = curl_exec($this->pCURL);
			if(curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) != '201')
			{
				$this->lastError = 'Fehler beim Löschen der DNS TXT records: "' . curl_error($this->pCURL) . " $aCURLResponse";
				return false;
			}
			$this->records = $this->getRecords();
			
			return true;
		}
		
		function getRecords()
		{
			curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->pAPIConfig['APIToken']]);
			curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'GET');
			curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}/{$this->pZoneID}/rrsets");
			$aCURLResponse = curl_exec($this->pCURL);
			
			if(curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) != '200')
			{
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE);
				return false;
			}
			
			$aResponseRecords = json_decode(json: $aCURLResponse, associative: true)['rrsets'];
			$aReturnRecords = array();
			//Erhaltene DNS Records auswerten
			//und als array key die recordID verwenden
			foreach($aResponseRecords as $record)
				$aReturnRecords[$record['id']] = $record;
			return $aReturnRecords;
		}
		
		function multiCreateRecords(array $records)
		{
			$aRRsets = $this->multiAssembleRRset($records);
			
			print_r($aRRsets);
			echo PHP_EOL;
			echo "CREATE";
			echo PHP_EOL;
			exit;
			
		}
		
		function multiSetRecords(array $records)
		{
			$aRRsets = $this->multiAssembleRRset($records);
			
			foreach($aRRsets as $aRRkey => $aRRdata)
			{
				if(isset($this->records[$aRRkey]))
				{
					curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}/{$this->pZoneID}/rrsets/$aRRkey/actions/set_records");
				}
				else
				{
					curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}/{$this->pZoneID}/rrsets");
				}
			
				$aCURLBody = json_encode(value: $aRRdata);
				
				curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->pAPIConfig['APIToken'], 'Content-Type: application/json']);
				curl_setopt($this->pCURL, CURLOPT_POST, 1);
				curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'POST');
				curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);
				$aCURLResponse = curl_exec($this->pCURL);
			
				if(curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) != '201')
				{
					$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE);
					echo $aCURLResponse;
					echo PHP_EOL;
					exit;
					return false;
				}
			}
			
			exit;
		}
		
		function multiUpdateRecords(array $records)
		{
			$aRRsets = $this->multiAssembleRRset($records);
			
			print_r($aRRsets);
			echo PHP_EOL;
			echo "UPDATE";
			echo PHP_EOL;
			exit;
		}

		function setRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?string $id = null, ?array $record = null)
		{
			//Record in bestehenden DNS Records finden 
			if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $record)) == false)
				$aResult = $this->createRecord(name: $name, type: $type, value: $value, ttl: $ttl, record: $record);	//Wenn Record noch nicht existiert, dann createRecord
			else
				$aResult = $this->updateRecord(name: $name, type: $type, value: $value, ttl: $ttl, record: $record, id: $id);
			
			$this->records = $this->getRecords();
			return $aResult;
		}
		
		function updateRecord(?string $name = null, ?string $type = null, ?string $value = null, ?int $ttl = null, ?string $id = null, ?array $record = null)
		{
			//Record in bestehenden DNS Records finden 
			if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $record)) == false)
				return false;	//Wenn Record noch nicht existiert, dann ist kein Update möglich
			
			//Wenn array $record definiert ist, dann diese Werte verwenden
			if(isset($record))
				$aUpdateRecord = $this->assembleRRset($record, $id);
			else
				$aUpdateRecord = $this->assembleRRset(array(
					'name' =>	$name,
					'type' =>	$type,
					'value' =>	$value,
					'ttl' =>	$ttl,
					), $id);
			
			
			curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}/$id/actions/set_records");
			curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Auth-API-Token: ' . $this->pAPIConfig['APIToken'], 'Content-Type: application/json']);
			curl_setopt($this->pCURL, CURLOPT_POST, 1);
			$aCURLBody = json_encode(value: $aUpdateRecord);
			curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);
			$aCURLResponse = curl_exec($this->pCURL);
			
			if(curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) != '200')
			{
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE);
				return false;
			}
			
			$this->records = $this->getRecords();
			//Erstellte Records zurückgeben
			return json_decode(json: $aCURLResponse, associative: true)['record'];
		}
	}

?>