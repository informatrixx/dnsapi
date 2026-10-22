<?php
	class DNSAPIJournal {
		private $lock;
		public array $data;
		function __construct(private string $path) {
			umask(0077);
			if(!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true))
				throw new RuntimeException('JOURNAL_DIRECTORY_FAILED');
			$this->lock = fopen($path . '.lock', 'c');
			if($this->lock === false || !flock($this->lock, LOCK_EX | LOCK_NB))
				throw new RuntimeException('JOURNAL_BUSY: another hook or recovery is active');
			if(!chmod($path . '.lock', 0600))
				throw new RuntimeException('JOURNAL_PERMISSIONS_FAILED');
			if(!file_exists($path)) {
				$this->data = array('version' => 2, 'journal' => array());
				return;
			}
			$content = file_get_contents($path);
			try {
				$data = json_decode($content === false ? '' : $content, true, 512, JSON_THROW_ON_ERROR);
			} catch(JsonException $error) {
				throw new RuntimeException('JOURNAL_CORRUPT: file preserved');
			}
			if(!is_array($data) || !isset($data['journal']) || !is_array($data['journal']) || (isset($data['version']) && $data['version'] !== 2))
				throw new RuntimeException('JOURNAL_FORMAT_UNSUPPORTED: file preserved');
			if(isset($data['batches'])) {
				if(!is_array($data['batches'])) throw new RuntimeException('JOURNAL_BATCH_INVALID');
				foreach($data['batches'] as $batch)
					if(!is_array($batch) || !is_string($batch['run'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $batch['run']) || !is_int($batch['remaining'] ?? null) || $batch['remaining'] < 0) throw new RuntimeException('JOURNAL_BATCH_INVALID');
			}
			// Legacy entries retain all fields; ownership is unknown until explicitly adopted.
			$data['version'] = 2;
			$this->data = $data;
			if(!chmod($path, 0600)) throw new RuntimeException('JOURNAL_PERMISSIONS_FAILED');
		}
		function save(): void {
			$data = $this->data;
			$data['journal'] = (object)$data['journal'];
			$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			$tmp = tempnam(dirname($this->path), '.journal-');
			if($tmp === false)
				throw new RuntimeException('JOURNAL_TEMP_FAILED');
			$handle = false;
			try {
				if(!chmod($tmp, 0600) || ($handle = fopen($tmp, 'wb')) === false)
					throw new RuntimeException('JOURNAL_OPEN_FAILED');
				self::writeAll($handle, $json);
				if(!fflush($handle) || !fsync($handle))
					throw new RuntimeException('JOURNAL_FLUSH_FAILED');
				fclose($handle);
				$handle = false;
				if(!rename($tmp, $this->path))
					throw new RuntimeException('JOURNAL_RENAME_FAILED');
			} finally {
				if(is_resource($handle)) fclose($handle);
				if(file_exists($tmp)) unlink($tmp);
			}
		}
		static function writeAll($handle, string $data, ?callable $writer = null): void {
			$writer ??= fn($stream, $bytes) => fwrite($stream, $bytes);
			for($offset = 0; $offset < strlen($data); $offset += $written) {
				$written = $writer($handle, substr($data, $offset));
				if(!is_int($written) || $written <= 0)
					throw new RuntimeException('JOURNAL_WRITE_FAILED');
			}
		}
		function __destruct() {
			if(is_resource($this->lock)) {
				flock($this->lock, LOCK_UN);
				fclose($this->lock);
			}
		}
	}
