<?php
	require_once __DIR__ . '/src/Runtime/DynDNS.php';
	header('Content-Type: application/json');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	try {
		$remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
		if(!$_GET) $response = array('IP' => $remoteIP);
		else $response = DNSAPIDynDNS::update(dnsapiConfig(), $_GET, $remoteIP, dnsapiEnv('DNSAPI_DYNDNS_CACHE_FILE', '/var/cache/dnsapi/dyn-cache-v2.json'));
		echo json_encode($response, JSON_THROW_ON_ERROR);
	} catch(Throwable $error) {
		$code = dnsapiError($error);
		http_response_code($code === 'REFUSED' ? 403 : (in_array($code, array('REQUEST_INVALID', 'IP_INVALID', 'IDENTIFIER_INVALID'), true) ? 400 : 503));
		echo json_encode(array('error' => $code));
	}
