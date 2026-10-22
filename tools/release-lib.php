<?php
	require_once __DIR__ . '/check-artifact.php';
	final class DNSAPIRelease {
		const EXECUTABLES = array('dnsapi-interface.php', 'dnsapi-check.php', 'dnsapi-doctor.php', 'dnsapi-recovery.php', 'certbot-dnsapi-authenticator.php', 'certbot-dnsapi-cleanup.php', 'certbot-tlsa-generator.php');
		const BASELINE_COMMIT = '1a4a08f1f55e8315fa836f142266b7f88550c0f6';
		static function version(string $version, ?string $tag = null): array {
			if(!preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-rc\.[1-9][0-9]*)?$/D', $version)) throw new RuntimeException('RELEASE_VERSION_INVALID');
			if($tag !== null && $tag !== 'v' . $version) throw new RuntimeException('RELEASE_TAG_MISMATCH');
			return array('version' => $version, 'prerelease' => str_contains($version, '-rc.'));
		}
		static function temporaryDirectory(): string {
			$path = sys_get_temp_dir() . '/dnsapi-release-' . bin2hex(random_bytes(12));
			if(!mkdir($path, 0700)) throw new RuntimeException('RELEASE_TEMP_FAILED');
			return $path;
		}
		static function removeDirectory(string $path): void {
			if(!is_dir($path) || is_link($path)) return;
			foreach(new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file) {
				if($file->isDir() && !$file->isLink()) self::removeDirectory($file->getPathname());
				else unlink($file->getPathname());
			}
			rmdir($path);
		}
		private static function read($stream, int $bytes): string {
			$value = '';
			while(strlen($value) < $bytes) {
				$part = gzread($stream, min(65536, $bytes - strlen($value)));
				if($part === false || $part === '') throw new RuntimeException('ARCHIVE_TRUNCATED');
				$value .= $part;
			}
			return $value;
		}
		private static function octal(string $field): int {
			$field = trim($field, "\0 ");
			if(!preg_match('/^[0-7]{1,11}$/D', $field)) throw new RuntimeException('ARCHIVE_HEADER_INVALID');
			return (int)octdec($field);
		}
		// Extract only the small, regular-file USTAR subset emitted by git archive.
		// Never delegate extraction of unchecked paths, links or PAX path overrides.
		static function extract(string $archive, string $destination, string $version): array {
			self::version($version);
			$prefix = 'dnsapi-' . $version;
			$stream = @gzopen($archive, 'rb');
			if($stream === false) throw new RuntimeException('ARCHIVE_READ_FAILED');
			$entries = array(); $total = 0; $commit = null;
			try {
				for($i = 0; $i < 10000; $i++) {
					$header = self::read($stream, 512);
					if($header === str_repeat("\0", 512)) {
						if(self::read($stream, 512) !== $header) throw new RuntimeException('ARCHIVE_TRAILER_INVALID');
						while(!gzeof($stream)) {
							$tail = gzread($stream, 65536);
							$total += strlen($tail === false ? '' : $tail);
							if($tail === false || trim($tail, "\0") !== '' || $total > 67108864) throw new RuntimeException('ARCHIVE_TRAILER_INVALID');
						}
						if(!$entries) throw new RuntimeException('ARCHIVE_EMPTY');
						return array('root' => $destination . '/' . $prefix, 'entries' => $entries, 'commit' => $commit);
					}
					$checksum = self::octal(substr($header, 148, 8));
					if(array_sum(unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8))) !== $checksum || substr($header, 257, 5) !== 'ustar') throw new RuntimeException('ARCHIVE_HEADER_INVALID');
					$size = self::octal(substr($header, 124, 12));
					$total += 512 + (int)(ceil($size / 512) * 512);
					if($size > 16777216 || $total > 67108864) throw new RuntimeException('ARCHIVE_SIZE_LIMIT');
					$type = $header[156];
					$payload = $size ? self::read($stream, $size) : '';
					if($size % 512) self::read($stream, 512 - ($size % 512));
					if($type === 'g') {
						if($commit !== null || !preg_match('/^(\d+) comment=([a-f0-9]{40}|[a-f0-9]{64})\n$/D', $payload, $match) || (int)$match[1] !== strlen($payload)) throw new RuntimeException('ARCHIVE_PAX_INVALID');
						$commit = $match[2]; continue;
					}
					if(!in_array($type, array('0', "\0", '5'), true) || ($type === '5' && $size !== 0)) throw new RuntimeException('ARCHIVE_TYPE_FORBIDDEN');
					$name = rtrim(substr($header, 0, 100), "\0");
					$parent = rtrim(substr($header, 345, 155), "\0");
					if($parent !== '') $name = $parent . '/' . $name;
					if($type === '5') $name = rtrim($name, '/');
					if(preg_match('~[\x00-\x1f\x7f\\\\]|(^|/)(\.|\.\.|\.git)($|/)|//~', $name) || ($name !== $prefix && !str_starts_with($name, $prefix . '/')) || dnsapiForbiddenArtifact($name)) throw new RuntimeException('ARCHIVE_PATH_FORBIDDEN');
					if(isset($entries[$name])) throw new RuntimeException('ARCHIVE_DUPLICATE_PATH');
					$mode = self::octal(substr($header, 100, 8));
					if(($mode & 07000) || ($mode & 0022)) throw new RuntimeException('ARCHIVE_MODE_UNSAFE');
					$path = $destination . '/' . $name;
					if(!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) throw new RuntimeException('ARCHIVE_DIRECTORY_FAILED');
					if($type === '5') {
						if(!is_dir($path) && !mkdir($path, 0700)) throw new RuntimeException('ARCHIVE_DIRECTORY_FAILED');
					} else {
						if(file_exists($path) || file_put_contents($path, $payload) !== $size || !chmod($path, $mode & 0777)) throw new RuntimeException('ARCHIVE_WRITE_FAILED');
					}
					$entries[$name] = array('directory' => $type === '5', 'mode' => $mode & 0777);
				}
				throw new RuntimeException('ARCHIVE_ENTRY_LIMIT');
			} finally { gzclose($stream); }
		}
		static function layout(array $archive, string $version): void {
			$root = $archive['root'];
			$required = array_merge(self::EXECUTABLES, array('VERSION', 'CHANGELOG.md', 'README.md', 'LICENSE', 'index.php', 'src/bootstrap.php', 'src/Provider/ProviderInterface.php', 'src/Provider/Transport.php', 'src/Runtime/Config.php', 'src/Runtime/Runtime.php', 'src/Runtime/Journal.php', 'src/Runtime/DynDNS.php', 'src/Dns/Records.php', 'src/Dns/Dns.php', 'src/Dns/MailAudit.php', 'src/Certbot/Helpers.php', 'src/Tlsa/Tlsa.php', 'src/Cli/Arguments.php', 'tools/verify.php', 'tools/verify-release.php', 'tests/all.php', 'tests/upgrade.php', 'examples/config.example.json', 'docs/release-acceptance.md'));
			foreach(array('Hetzner', 'HetznerCloud', 'Netcup') as $provider) {
				$required[] = 'dnsapi-' . $provider . '.inc.php';
				$required[] = 'src/Provider/' . $provider . '.php';
			}
			foreach(array('runtime', 'journal', 'dns', 'certbot') as $helper) $required[] = 'dnsapi-' . $helper . '.inc.php';
			foreach($required as $file) if(!is_file($root . '/' . $file)) throw new RuntimeException('ARCHIVE_REQUIRED_FILE_MISSING: ' . $file);
			if(trim(file_get_contents($root . '/VERSION')) !== $version) throw new RuntimeException('ARCHIVE_VERSION_MISMATCH');
			foreach($archive['entries'] as $name => $entry) {
				if($entry['directory']) continue;
				$file = substr($name, strlen(basename($root)) + 1);
				$executable = in_array($file, self::EXECUTABLES, true);
				if($entry['mode'] !== ($executable ? 0755 : 0644)) throw new RuntimeException('ARCHIVE_MODE_INVALID: ' . $file);
				if($executable && !str_starts_with(file_get_contents($root . '/' . $file), "#!/usr/bin/php\n")) throw new RuntimeException('ARCHIVE_SHEBANG_INVALID');
			}
		}
		static function run(array $command, string $cwd, array $environment = array()): void {
			$env = $environment + array('PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'LC_ALL' => 'C', 'HOME' => dirname($cwd), 'DNSAPI_CONFIG_FILE' => $cwd . '/examples/config.example.json', 'DNSAPI_JOURNAL_FILE' => dirname($cwd) . '/verification-journal.json', 'DNSAPI_DYNDNS_CACHE_FILE' => dirname($cwd) . '/verification-cache.json');
			$process = proc_open($command, array(0 => array('file', '/dev/null', 'r'), 1 => STDOUT, 2 => STDERR), $pipes, $cwd, $env);
			if(!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('RELEASE_CHECK_FAILED');
		}
	}
