<?php
	require __DIR__ . '/support/Test.php';
	require_once __DIR__ . '/../tools/release-lib.php';
	foreach(array('0.3.0', '0.3.0-rc.1', '1.2.3-rc.12') as $version)
		testAssert(DNSAPIRelease::version($version, 'v' . $version)['prerelease'] === str_contains($version, '-rc.'), 'release version ' . $version);
	foreach(array('v0.3.0', '0.03.0', '0.3.0-rc.0', '0.3.0-rc.01', '0.3.0-beta.1', '0.3.0+build', "0.3.0\n") as $version)
		testReject(fn() => DNSAPIRelease::version($version), 'RELEASE_VERSION_INVALID');
	testReject(fn() => DNSAPIRelease::version('0.3.0-rc.1', 'v0.3.0'), 'RELEASE_TAG_MISMATCH');
	function releaseTarEntry(string $name, string $content = '', string $type = '0', int $mode = 0644): string {
		$header = str_repeat("\0", 512);
		foreach(array(0 => str_pad($name, 100, "\0"), 100 => sprintf('%07o', $mode) . "\0", 108 => "0000000\0", 116 => "0000000\0", 124 => sprintf('%011o', strlen($content)) . "\0", 136 => "00000000000\0", 148 => '        ', 156 => $type, 257 => "ustar\00000") as $offset => $field)
			$header = substr_replace($header, $field, $offset, strlen($field));
		$header = substr_replace($header, sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ", 148, 8);
		return $header . $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
	}
	$directory = DNSAPIRelease::temporaryDirectory();
	$version = '0.3.0-rc.1';
	$prefix = 'dnsapi-' . $version . '/';
	try {
		$counter = 0;
		$extract = function(string $tar) use ($directory, &$counter, $version) {
			$path = $directory . '/' . ++$counter;
			mkdir($path, 0700);
			file_put_contents($path . '.tar.gz', gzencode($tar));
			return DNSAPIRelease::extract($path . '.tar.gz', $path, $version);
		};
		$end = str_repeat("\0", 1024);
		$archive = $extract(releaseTarEntry($prefix . 'VERSION', $version . "\n") . $end);
		testAssert(file_get_contents($archive['root'] . '/VERSION') === $version . "\n", 'regular archive extracts');
		testReject(fn() => DNSAPIRelease::layout($archive, $version), 'ARCHIVE_REQUIRED_FILE_MISSING');
		foreach(array('/outside', $prefix . '../outside', $prefix . './relative', $prefix . 'a//b', $prefix . "bad\nname", $prefix . 'config.json', $prefix . '.git/config', $prefix . 'cache/journal.json', $prefix . 'secret.pem') as $path)
			testReject(fn() => $extract(releaseTarEntry($path, 'secret-fixture') . $end), 'ARCHIVE_PATH_FORBIDDEN');
		foreach(array('1', '2', '3', '6', 'x') as $type)
			testReject(fn() => $extract(releaseTarEntry($prefix . 'link', '', $type) . $end), 'ARCHIVE_TYPE_FORBIDDEN');
		testReject(fn() => $extract(releaseTarEntry($prefix . 'unsafe', '', '0', 04755) . $end), 'ARCHIVE_MODE_UNSAFE');
		testReject(fn() => $extract(releaseTarEntry($prefix . 'writable', '', '0', 0666) . $end), 'ARCHIVE_MODE_UNSAFE');
		$entry = releaseTarEntry($prefix . 'VERSION', $version);
		testReject(fn() => $extract($entry . $entry . $end), 'ARCHIVE_DUPLICATE_PATH');
		testReject(fn() => $extract($entry . str_repeat("\0", 512)), 'ARCHIVE_TRUNCATED');
		testReject(fn() => $extract($entry . $end . 'extra archive'), 'ARCHIVE_TRAILER_INVALID');
		testReject(fn() => $extract('X' . substr($entry, 1) . $end), 'ARCHIVE_HEADER_INVALID');
		testReject(fn() => $extract(releaseTarEntry('pax_global_header', "20 path=../outside\n", 'g') . $end), 'ARCHIVE_PAX_INVALID');
		$pax = '52 comment=' . str_repeat('a', 40) . "\n";
		$archive = $extract(releaseTarEntry('pax_global_header', $pax, 'g') . $entry . $end);
		testAssert($archive['commit'] === str_repeat('a', 40), 'git commit metadata is retained');
		testAssert(!file_exists($directory . '/outside'), 'forbidden paths never escaped extraction');
	} finally { DNSAPIRelease::removeDirectory($directory); }
	echo "OK: $testCount release checks on PHP " . PHP_VERSION . PHP_EOL;
