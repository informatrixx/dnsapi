<?php
	class ProductMemoryProvider {
		public array $records = array();
		public int $writes = 0;
		function __construct(private ?string $path = null) {
			if($path && is_file($path)) $this->records = json_decode(file_get_contents($path), true);
		}
		function getRecords() { return $this->records; }
		private function save(): void {
			$this->writes++;
			if($this->path) file_put_contents($this->path, json_encode($this->records));
		}
		function createRecord($record = null, $name = null, $type = null, $value = null, $ttl = null) {
			$record ??= array('name' => $name, 'type' => $type, 'value' => $value, 'ttl' => $ttl);
			foreach($this->records as $old) if($old['name'] === $record['name'] && $old['type'] === $record['type'] && $old['value'] === $record['value']) return $old;
			$record['id'] = bin2hex(random_bytes(4));
			$this->records[$record['id']] = $record;
			$this->save(); return $record;
		}
		function deleteRecord($record = null, $value = null) {
			unset($this->records[$record['id']]); $this->save(); return true;
		}
		function setRecord($record = null) {
			foreach($this->records as $id => $old) if(isset($record['id']) ? $id === $record['id'] : ($old['name'] === $record['name'] && $old['type'] === $record['type'])) unset($this->records[$id]);
			return $this->createRecord(record: $record);
		}
		function updateRecord($record = null) { return $this->setRecord($record); }
		function multiSetRecords($records) { foreach($records as $record) $this->setRecord($record); return true; }
	}
