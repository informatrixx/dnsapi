<?php
	class hetznerAPI implements DNSAPIv1{
		
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
		
		private function assignValues(array $record, ?string $id = null)
		{
			//Mit Werten befüllen und als Array zurückgeben
			$aDNSRecord = array(); 
			if(isset($id))	//Wenn Parameter $id übergeben wurde ...
			{
				$aDNSRecord = $this->records[$id];
				$aDNSRecord['id'] = $id;
			}	//...ansonsten falls im Array $record['id'] gesetzt ist
			elseif(!empty($record['id']) && is_string($record['id']))
				$aDNSRecord['id'] = $record['id'];
			
			if(!empty($record['name']) && is_string($record['name']))
				$aDNSRecord['name'] = $record['name'];
			if(!empty($record['type']) && is_string($record['type']))
				$aDNSRecord['type'] = $record['type'];
			if(!empty($record['value']) && is_string($record['value']))
				$aDNSRecord['value'] = $record['value'];
			if(!empty($record['ttl']) && is_int($record['ttl']))
				$aDNSRecord['ttl'] = $record['ttl'];
			
			$aReturnRecord['zone_id'] = $this->pZoneID;
			return $aDNSRecord;
		}
		
		private function findRecord(?string $name = null, ?string $type = null, ?string $id = null, ?array $record = null)
		{
			if(isset($id) && isset($this->records[$id]))
				return $id;
			
			if(isset($record) && isset($record['id']) && isset($this->records[$record['id']]))
				return $record['id'];
																			  
			
			foreach($this->records as $aID => $aRecordData)
			{
				if(isset($name) && isset($type) && $aRecordData['name'] == $name && $aRecordData['type'] == $type)
					return $aRecordData['id'];
				if(isset($record) && isset($record['name']) && isset($record['type']) && $aRecordData['name'] == $record['name'] && $aRecordData['type'] == $record['type'])
					return $aRecordData['id'];
			}
			
			return false;
		}


		function createRecord(?string $name = null, string $type = null, ?string $value = null, ?int $ttl = null, ?array $record = null)
		{
			//Wenn kein $name || $type 
			//oder kein array $record mit ['name'] || ['type']
			//unvollständiger record
			if((empty($name) || empty($type)) && (empty($record) || empty($record['name']) || empty($record['type'])))
				return false;
			
			if(!empty($record))	//Wenn array $record definiert ist
			{
				$aCreateRecord = $record;
				$aCreateRecord['zone_id'] = $this->pZoneID;
			}
			else
			{
				$aCreateRecord = array(
					'name' => $name,
					'value' => !empty($value) ? $value : '',
					'type' => $type,
					'zone_id' => $this->pZoneID,
					);
				if(!empty($ttl))
					$aCreateRecord['ttl'] = $ttl;
			}
			
			$aCURLBody = json_encode($aCreateRecord);
			
			//API
			curl_setopt($this->pCURL, CURLOPT_URL, $this->pAPIConfig['recordsURL']);
			curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Auth-API-Token: ' . $this->pAPIConfig['APIToken']]);
			curl_setopt($this->pCURL, CURLOPT_POST, 1);
			curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);
			
			
			$aCURLResponse = curl_exec($this->pCURL);
			
			if(curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) != '200')
			{
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) . " $aCURLResponse";
				return false;
			}
			
			$this->records = $this->getRecords();
			//Erstellten Record zurückgeben
			return json_decode(json: $aCURLResponse, associative: true)['record'];
		}
		
		function deleteRecord(?string $name = null, ?string $type = null, ?string $id = null, ?array $record = null, ?string $value = null)
		{
			//Record in bestehenden DNS Records finden
			if(($id = $this->findRecord(name: $name, type: $type, id: $id, record: $record)) == false)
				return false;	//Wenn Record noch nicht existiert, dann ist kein Delete möglich
			
			curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Auth-API-Token: ' . $this->pAPIConfig['APIToken']]);
			curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}/$id");
			curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'DELETE');
			
			$aCURLResponse = curl_exec($this->pCURL);
			if(curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) != '200')
			{
				$this->lastError = 'Fehler beim Löschen der DNS TXT records: "' . curl_error($this->pCURL) . " $aCURLResponse";
				return false;
			}
			$this->records = $this->getRecords();
			
			return true;
		}
		
		function getRecords()
		{
			curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Auth-API-Token: ' . $this->pAPIConfig['APIToken']]);
			curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'GET');
			curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}?zone_id={$this->pZoneID}");
			$aCURLResponse = curl_exec($this->pCURL);
			
			if(curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) != '200')
			{
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE);
				return false;
			}
			
			$aResponseRecords = json_decode(json: $aCURLResponse, associative: true)['records'];
			$aReturnRecords = array();
			//Erhaltene DNS Records auswerten
			//und als array key die recordID verwenden
			foreach($aResponseRecords as $record)
				$aReturnRecords[$record['id']] = $record;
			return $aReturnRecords;
		}
		
		function multiCreateRecords(array $records)
		{
			$aCreateRecords = array();
			foreach($records as $record)
			{
				if(empty($record) || empty($record['name']) || empty($record['type']))
					continue;	//Wenn record unvollständig, überspringen
				$aCreateRecord = $record;
				if(!isset($record['value']))	//Wenn value nicht definiert, dann leeren String einfügen
					$aCreateRecord['value'] = '';
				$aCreateRecord['zone_id'] = $this->pZoneID;
				
				$aCreateRecords[] = $aCreateRecord;
			}
			if(count($aCreateRecords) == 0)
				return false;
			
			$aCURLBody = json_encode(array('records' => array_values(array: $aCreateRecords)));
			
			//API
			curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}/bulk");
			curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Auth-API-Token: ' . $this->pAPIConfig['APIToken'], 'Content-Type: application/json']);
			curl_setopt($this->pCURL, CURLOPT_POST, 1);
			curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);

			$aCURLResponse = curl_exec($this->pCURL);
			
			if(curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) != '200')
			{
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE);
				return false;
			}
			
			//Erstellte Records zurückgeben
			$this->records = $this->getRecords();
			return json_decode(json: $aCURLResponse, associative: true)['records'];
		}
		
		function multiSetRecords(array $records)
		{
			$aUpdateRecords = array();
			$aCreateRecords = array();
			foreach($records as $record)
			{
				//Record in bestehenden DNS Records finden 
				if(($id = $this->findRecord(record: $record)) == false)
				{
					if(empty($record) || empty($record['name']) || empty($record['type']))
						continue;	//Wenn record unvollständig, überspringen
					$aCreateRecord = $record;
					if(!isset($record['value']))	//Wenn value nicht definiert, dann leeren String einfügen
						$aCreateRecord['value'] = '';
					$aCreateRecord['zone_id'] = $this->pZoneID;
					
					$aCreateRecords[] = $aCreateRecord;
				}
				else
					$aUpdateRecords[] = $this->assignValues(record: $record, id: isset($record['id']) ? $record['id'] : null);
			}
			
			if(count($aCreateRecords) == 0 && count($aUpdateRecords) == 0)
				return false;
			
			if(count($aCreateRecords) > 0)
				$this->multiCreateRecords($aCreateRecords);
			if(count($aUpdateRecords) > 0)
				$this->multiUpdateRecords($aUpdateRecords);
			
			return true;
		}
		
		function multiUpdateRecords(array $records)
		{
			$aUpdateRecords = array();
			foreach($records as $record)
			{
				//Record in bestehenden DNS Records finden 
				if(($id = $this->findRecord(record: $record)) == false)
					continue;	//Wenn Record noch nicht existiert, dann ist kein Update möglich
				
				//Update Arra befüllen
				$aUpdateRecords[] = $this->assignValues(record: $record, id: isset($record['id']) ? $record['id'] : $id);
			}
			if(count($aUpdateRecords) == 0)	//Wenn Update Array leer ist, dann kein Update möglich
				return false;
			
			$aCURLBody = json_encode(array('records' => array_values(array: $aUpdateRecords)));

			//API
			curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'PUT');
			curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}/bulk");
			curl_setopt($this->pCURL, CURLOPT_HTTPHEADER, ['Auth-API-Token: ' . $this->pAPIConfig['APIToken'], 'Content-Type: application/json']);
			curl_setopt($this->pCURL, CURLOPT_POST, 1);
			curl_setopt($this->pCURL, CURLOPT_POSTFIELDS, $aCURLBody);
			
			$aCURLResponse = curl_exec($this->pCURL);
			
			if(curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE) != '200')
			{
				$this->lastError = 'Fehler beim API Aufruf: ' . curl_getinfo($this->pCURL, CURLINFO_HTTP_CODE);
				return false;
			}
			
			$this->records = $this->getRecords();
			//Erstellte Records zurückgeben
			return json_decode(json: $aCURLResponse, associative: true)['records'];
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
				$aUpdateRecord = $this->assignValues($record, $id);
			else
				$aUpdateRecord = $this->assignValues(array(
					'name' =>	$name,
					'type' =>	$type,
					'value' =>	$value,
					'ttl' =>	$ttl,
					), $id);
			
			
			curl_setopt($this->pCURL, CURLOPT_CUSTOMREQUEST, 'PUT');
			curl_setopt($this->pCURL, CURLOPT_URL, "{$this->pAPIConfig['recordsURL']}/$id");
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