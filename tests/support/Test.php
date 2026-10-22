<?php
	error_reporting(E_ALL);
	set_error_handler(function($severity, $message, $file, $line) {
		if(!(error_reporting() & $severity)) return false;
		throw new ErrorException($message, 0, $severity, $file, $line);
	});
	$testCount = 0;
	function testAssert(bool $condition, string $message): void {
		global $testCount;
		if(!$condition) throw new RuntimeException('FAIL: ' . $message);
		$testCount++;
	}
	function testReject(callable $function, string $code): void {
		try { $function(); } catch(Throwable $error) { testAssert(str_starts_with($error->getMessage(), $code), 'expected ' . $code . ', got ' . $error->getMessage()); return; }
		throw new RuntimeException('FAIL: expected ' . $code);
	}
	function testProcess(array $command, array $env = array()): array {
		$process = proc_open($command, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env + array('PATH' => getenv('PATH')));
		$output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]);
		return array('exit' => proc_close($process), 'output' => $output, 'error' => $error);
	}
