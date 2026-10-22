<?php
	$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	if($path === '/health') { echo 'ready'; return; }
	$statePath = getenv('DNSAPI_HTTP_STATE');
	$state = json_decode(file_get_contents($statePath), true);
	$body = json_decode(file_get_contents('php://input'), true);
	$state['requests'][] = array('method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'query' => $_GET, 'body' => $body);
	$respond = function($body, int $code = 200) use (&$state, $statePath) {
		file_put_contents($statePath, json_encode($state));
		http_response_code($code); header('Content-Type: application/json'); echo json_encode($body);
	};
	if(isset($state['malformed'])) { file_put_contents($statePath, json_encode($state)); echo '{invalid'; return; }
	if(isset($state['failure'])) { $respond(array('error' => 'private-fixture-value'), 503); return; }
	if($path === '/netcup') {
		$action = $body['action'];
		if($action === 'login') { $respond(array('status' => 'success', 'responsedata' => array('apisessionid' => 'fixture-session'))); return; }
		if(($body['param']['apisessionid'] ?? '') !== 'fixture-session') { $respond(array('status' => 'error'), 403); return; }
		if($action === 'logout') { $respond(array('status' => 'success')); return; }
		if($action === 'updateDnsRecords') foreach($body['param']['dnsrecordset']['dnsrecords'] as $record) {
			if(isset($record['id']) && !isset($state['netcup'][$record['id']])) { $respond(array('status' => 'error')); return; }
			$id = $record['id'] ?? (string)++$state['next'];
			if($record['deleterecord'] ?? false) { if(!isset($state['ignore_delete'])) unset($state['netcup'][$id]); }
			else $state['netcup'][$id] = $record + array('id' => $id, 'state' => 'yes');
		}
		if($action === 'updateDnsRecords' && isset($state['after_write_error'])) { $respond(array('status' => 'success', 'responsedata' => array())); return; }
		if($action === 'infoDnsRecords' && isset($state['invalid_netcup_record'])) { $respond(array('status' => 'success', 'responsedata' => array('dnsrecords' => array(array('id' => 'bad', 'hostname' => '_acme-challenge', 'type' => 'TXT', 'destination' => false))))); return; }
		$respond(array('status' => 'success', 'responsedata' => array('dnsrecords' => array_values($state['netcup'])))); return;
	}
	if($path === '/zones/actions/19') {
		$state['polls'] = ($state['polls'] ?? 0) + 1;
		$respond(array('action' => array('id' => 19, 'status' => $state['polls'] === 1 || isset($state['action_stuck']) ? 'running' : 'success'))); return;
	}
	if($path === '/zones/1/rrsets') {
		if($_SERVER['REQUEST_METHOD'] === 'POST') {
			$id = $body['name'] . '/' . $body['type'];
			$state['cloud'][$id] = $body + array('id' => $id, 'ttl' => 300);
			$respond(array('rrset' => $state['cloud'][$id]), 201); return;
		}
		$rrsets = array_values($state['cloud']);
		$page = (int)($_GET['page'] ?? 1);
		$response = array('rrsets' => array_slice($rrsets, $page - 1, 1), 'meta' => array('pagination' => array('next_page' => isset($state['pagination_cycle']) ? 1 : ($page < count($rrsets) ? $page + 1 : null))));
		if(isset($state['missing_pagination'])) unset($response['meta']);
		if(isset($state['incomplete_rrset']) && $response['rrsets']) unset($response['rrsets'][0]['records']);
		$respond($response); return;
	}
	if($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('~^/zones/1/rrsets/([^/]+)/([^/]+)$~', $path, $matches)) {
		unset($state['cloud'][rawurldecode($matches[1]) . '/' . $matches[2]]);
		$state['polls'] = 0;
		$respond(array('action' => array('id' => 19, 'status' => 'running')), 201); return;
	}
	if(preg_match('~^/zones/1/rrsets/([^/]+)/([^/]+)/actions/(add_records|remove_records|set_records|change_ttl|update_records)$~', $path, $matches)) {
		[, $name, $type, $action] = $matches;
		$id = rawurldecode($name) . '/' . $type;
		if(!isset($state['cloud'][$id]) && $action !== 'add_records') { $respond(array('error' => array('code' => 'not_found')), 404); return; }
		$state['cloud'][$id] ??= array('id' => $id, 'name' => rawurldecode($name), 'type' => $type, 'ttl' => 300, 'records' => array());
		if($action === 'set_records') $state['cloud'][$id]['records'] = $body['records'];
		if($action === 'add_records' && !isset($state['ignore_add'])) foreach($body['records'] as $record) if(!in_array($record['value'], array_column($state['cloud'][$id]['records'], 'value'), true)) $state['cloud'][$id]['records'][] = $record;
		if($action === 'remove_records') $state['cloud'][$id]['records'] = array_values(array_filter($state['cloud'][$id]['records'], fn($r) => !in_array($r['value'], array_column($body['records'], 'value'), true)));
		if($action === 'remove_records' && !$state['cloud'][$id]['records']) unset($state['cloud'][$id]);
		if($action === 'change_ttl') $state['cloud'][$id]['ttl'] = $body['ttl'];
		if($action === 'update_records') foreach($state['cloud'][$id]['records'] as &$record) foreach($body['records'] as $update) if($record['value'] === $update['value']) $record = $update;
		$state['polls'] = 0;
		$respond(array('action' => array('id' => 19, 'status' => 'running')), $action === 'update_records' ? 200 : 201); return;
	}
	$respond(array('error' => 'UNEXPECTED_FIXTURE_REQUEST'), 404);
