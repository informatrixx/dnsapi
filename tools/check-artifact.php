<?php
	function dnsapiForbiddenArtifact(string $path): bool {
		$path = str_replace('\\', '/', trim($path));
		return preg_match('~(^|/)(config\.json|\.env(?:\..*)?|[^/]*\.(?:log|pem|key|p12)|\.codex-sessions\.tsv|journal\.json|dyn-cache[^/]*\.json)(?:$|/)~i', $path) === 1
			|| preg_match('~(^|/)\.\.(?:/|$)~', $path) === 1;
	}
	if(realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
		$bad = false;
		while(($line = fgets(STDIN)) !== false) if(dnsapiForbiddenArtifact($line)) { fwrite(STDERR, 'Forbidden artifact path: ' . trim($line) . PHP_EOL); $bad = true; }
		exit($bad ? 1 : 0);
	}
