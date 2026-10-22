<?php
	require_once __DIR__ . '/release-lib.php';
	require_once __DIR__ . '/../src/Cli/Arguments.php';
	$directory = null;
	$exit = 0;
	try {
		$options = DNSAPIArguments::options($argv, '', array('archive:', 'version:', 'baseline-archive:', 'help'));
		if(isset($options['help'])) { echo "php tools/verify-release.php --archive FILE --version VERSION [--baseline-archive FILE]\nRuns code from a trusted project archive, not a sandbox for untrusted downloads.\n"; exit(0); }
		if(!isset($options['archive'], $options['version'])) throw new RuntimeException('RELEASE_ARGUMENTS_REQUIRED');
		$metadata = DNSAPIRelease::version($options['version']);
		$directory = DNSAPIRelease::temporaryDirectory();
		$archive = DNSAPIRelease::extract($options['archive'], $directory, $options['version']);
		DNSAPIRelease::layout($archive, $options['version']);
		$root = $archive['root'];
		DNSAPIRelease::run(array(PHP_BINARY, 'tools/verify.php'), $root);
		foreach(array('dnsapi-interface.php', 'dnsapi-check.php', 'dnsapi-doctor.php', 'dnsapi-recovery.php', 'certbot-tlsa-generator.php') as $entry)
			DNSAPIRelease::run(array('./' . $entry, '--help'), $root);
		DNSAPIRelease::run(array('./dnsapi-interface.php', '--version'), $root);
		DNSAPIRelease::run(array('./dnsapi-interface.php', '--config-check'), $root);
		$upgrade = 'not_run';
		if(isset($options['baseline-archive'])) {
			$baseline = DNSAPIRelease::extract($options['baseline-archive'], $directory, '0.2.1');
			if($baseline['commit'] !== DNSAPIRelease::BASELINE_COMMIT || trim(file_get_contents($baseline['root'] . '/VERSION')) !== '0.2.1') throw new RuntimeException('BASELINE_MISMATCH');
			DNSAPIRelease::run(array(PHP_BINARY, 'tests/upgrade.php'), $root, array('DNSAPI_BASELINE_DIR' => $baseline['root']));
			$upgrade = 'passed';
		}
		echo json_encode($metadata + array('archive_sha256' => hash_file('sha256', $options['archive']), 'source_commit' => $archive['commit'], 'php' => PHP_VERSION, 'offline' => 'passed', 'upgrade_rollback' => $upgrade, 'live_acceptance' => 'not_run'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
	} catch(Throwable $error) {
		fwrite(STDERR, 'RELEASE_VERIFICATION_FAILED: ' . $error->getMessage() . PHP_EOL);
		$exit = 1;
	} finally { if($directory !== null) DNSAPIRelease::removeDirectory($directory); }
	exit($exit);
