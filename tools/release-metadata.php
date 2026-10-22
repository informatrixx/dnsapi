<?php
	require_once __DIR__ . '/release-lib.php';
	require_once __DIR__ . '/../src/Cli/Arguments.php';
	try {
		$options = DNSAPIArguments::options($argv, '', array('tag:'));
		echo json_encode(DNSAPIRelease::version(trim(file_get_contents(__DIR__ . '/../VERSION')), $options['tag'] ?? null), JSON_THROW_ON_ERROR) . PHP_EOL;
	} catch(Throwable $error) { fwrite(STDERR, 'RELEASE_METADATA_INVALID' . PHP_EOL); exit(1); }
