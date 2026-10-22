<?php
	require_once __DIR__ . '/../bootstrap.php';
	require_once __DIR__ . '/Dns.php';

	final class DNSAPIMailAudit {
		function __construct(private DNSAPIDNS $dns, private string $resolver) {}
		function check(string $domain, ?string $selector = null, ?string $mailHost = null): array {
			$domain = dnsapiIdentifier($domain);
			if($selector !== null && !preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $selector)) throw new RuntimeException('DKIM_SELECTOR_INVALID');
			$queries = array('MX' => array($domain, 'MX', null), 'SPF' => array($domain, 'TXT', 'v=spf1'), 'DMARC' => array('_dmarc.' . $domain, 'TXT', 'v=DMARC1;'), 'MTA_STS' => array('_mta-sts.' . $domain, 'TXT', 'v=STSv1;'));
			if($selector !== null) $queries['DKIM'] = array($selector . '._domainkey.' . $domain, 'TXT', null);
			if($mailHost !== null) $queries['TLSA'] = array('_25._tcp.' . dnsapiIdentifier($mailHost), 'TLSA', null);
			$checks = array();
			foreach($queries as $label => [$name, $type, $prefix]) {
				$result = $this->dns->query($name, $type, $this->resolver);
				$records = array_values(array_filter($result['records'], fn($r) => $r['type'] === $type && ($prefix === null || str_starts_with($r['value'], $prefix))));
				$ok = $result['error'] === 'OK' && count($records) > 0;
				if(in_array($label, array('SPF', 'DMARC', 'MTA_STS', 'DKIM'), true)) $ok = $ok && count($records) === 1;
				if($label === 'DKIM') $ok = $ok && preg_match('/(?:^|;)\s*p\s*=\s*[^;\s]+/', $records[0]['value'] ?? '') === 1;
				$checks[$label] = array('status' => $ok ? 'present' : 'attention', 'count' => count($records), 'rcode' => $result['rcode'], 'error' => $result['error']);
			}
			$dnssec = $this->dns->query($domain, 'SOA', $this->resolver, dnssec: true);
			$checks['DNSSEC'] = array('status' => $dnssec['error'] === 'OK' && $dnssec['ad'] ? 'resolver_validated' : 'unconfirmed', 'error' => $dnssec['error']);
			$checks['DKIM'] ??= array('status' => 'skipped', 'reason' => 'selector required');
			$checks['TLSA'] ??= array('status' => 'skipped', 'reason' => 'mail-host required');
			return array('domain' => $domain, 'resolver' => $this->resolver, 'read_only' => true, 'checks' => $checks, 'scope' => 'DNS inventory only; no SPF evaluation, DKIM signature test, HTTPS policy fetch or TLS handshake; AD relies on resolver trust');
		}
	}
