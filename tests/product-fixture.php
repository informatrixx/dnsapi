<?php
	require_once __DIR__ . '/support/MemoryProvider.php';
	$GLOBALS['dnsapiProviderFactory'] = fn() => new ProductMemoryProvider(getenv('DNSAPI_TEST_RECORDS'));
	$_GET = json_decode(getenv('DNSAPI_TEST_QUERY') ?: '[]', true);
	$_SERVER['REMOTE_ADDR'] = '192.0.2.5';
