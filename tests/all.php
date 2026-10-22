<?php
	foreach(array('hooks.php', 'product.php', 'providers.php', 'release.php') as $suite) {
		$process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=E_ALL', __DIR__ . '/' . $suite), array(0 => STDIN, 1 => STDOUT, 2 => STDERR), $pipes);
		if(proc_close($process) !== 0) exit(1);
	}
