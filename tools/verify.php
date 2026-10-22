<?php
	$root = dirname(__DIR__);
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach($files as $file) {
		if(str_contains($file->getPathname(), '/.git/') || $file->getExtension() !== 'php') continue;
		$process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=stderr', '-l', $file->getPathname()), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes);
		$output = stream_get_contents($pipes[1]); fclose($pipes[1]);
		if(proc_close($process) !== 0 || preg_match('/(?:Warning|Deprecated|Fatal error):/', $output)) { fwrite(STDERR, $output); exit(1); }
	}
	echo 'PHP syntax and deprecation checks passed on ' . PHP_VERSION . PHP_EOL;
	require $root . '/tests/all.php';
