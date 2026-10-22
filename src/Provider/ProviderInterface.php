<?php
	interface DNSAPIv1 {
		function __construct(string $zoneID, array $APIConfig);
		function createRecord(?string $name, ?string $type, ?string $value, ?int $ttl, ?array $record);
		function deleteRecord(?string $name, ?string $type, ?string $id, ?array $record, ?string $value);
		function getRecords();
		function multiCreateRecords(array $records);
		function multiSetRecords(array $records);
		function multiUpdateRecords(array $records);
		function setRecord(?string $name, ?string $type, ?string $value, ?int $ttl, ?string $id, ?array $record);
		function updateRecord(?string $name, ?string $type, ?string $value, ?int $ttl, ?string $id, ?array $record);
	}
