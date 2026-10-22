#!/usr/bin/php
<?php
	require_once __DIR__ . '/dnsapi-certbot.inc.php';
	require_once __DIR__ . '/src/Cli/Arguments.php';
	try {
		$options = DNSAPIArguments::options($argv, '', array('run:', 'apply', 'adopt-legacy', 'help'));
		if(isset($options['help'])) { echo "dnsapi-recovery.php [--run RUN_ID] [--apply] [--adopt-legacy]\nDefault: local recovery plan only. Stop the associated Certbot run before --apply.\n"; exit(0); }
		$run = $options['run'] ?? null;
		if(isset($options['apply']) && (!is_string($run) || $run === '')) throw new RuntimeException('RECOVERY_RUN_REQUIRED');
		if(isset($options['adopt-legacy']) && (!isset($options['apply']) || $run !== 'legacy')) throw new RuntimeException('RECOVERY_LEGACY_SELECTION_REQUIRED');
		$journal = new DNSAPIJournal(dnsapiEnv('DNSAPI_JOURNAL_FILE', '/var/cache/dnsapi/journal.json'));
		$ids = array();
		foreach($journal->data['journal'] as $id => $entry) {
			$entryRun = is_array($entry) ? ($entry['run'] ?? 'legacy') : 'invalid';
			if($run !== null && $run !== $entryRun) continue;
			$ids[] = $id;
			try { $identifier = dnsapiIdentifier(is_array($entry) ? ($entry['CERTBOT_IDENTIFIER'] ?? $entry['CERTBOT_DOMAIN'] ?? '') : ''); }
			catch(Throwable $error) { $identifier = 'invalid'; }
			// IDs and states only: legacy provider IDs can contain user-supplied text.
			echo json_encode(array('run' => $entryRun, 'identifier' => $identifier, 'entry' => hash('sha256', (string)$id), 'owned' => is_array($entry) ? ($entry['owned'] ?? null) : null, 'phase' => is_array($entry) ? ($entry['phase'] ?? 'legacy') : 'invalid')) . PHP_EOL;
		}
		if(isset($options['apply'])) {
			if(!is_string($run) || $run === '') throw new RuntimeException('Explicit --run required');
			$config = dnsapiConfig();
			if(!defined('CONFIG')) define('CONFIG', $config);
			$GLOBALS['dnsapiDeadline'] = new DNSAPIDeadline(60);
			if(!dnsapiCleanup($journal, $config, $ids, isset($options['adopt-legacy']))) exit(1);
		}
	} catch(Throwable $error) {
		fwrite(STDERR, 'RECOVERY_FAILED: check journal, configuration and explicit --run selection' . PHP_EOL);
		exit(1);
	}
