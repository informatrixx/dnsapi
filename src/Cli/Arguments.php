<?php
	final class DNSAPIArguments {
		static function options(array $arguments, string $short, array $long): array {
			$spec = array();
			preg_match_all('/([a-zA-Z])(:{0,2})/', $short, $matches, PREG_SET_ORDER);
			foreach($matches as $match) $spec['-' . $match[1]] = strlen($match[2]);
			foreach($long as $name) $spec['--' . rtrim($name, ':')] = strlen($name) - strlen(rtrim($name, ':'));
			$options = array();
			for($i = 1; $i < count($arguments); $i++) {
				$argument = $arguments[$i];
				if(str_starts_with($argument, '--')) {
					$parts = explode('=', $argument, 2); $flag = $parts[0]; $value = $parts[1] ?? null;
				} else { $flag = substr($argument, 0, 2); $value = strlen($argument) > 2 ? substr($argument, 2) : null; }
				if(!isset($spec[$flag])) throw new RuntimeException('ARGUMENT_UNKNOWN');
				if($spec[$flag] === 0 && $value !== null) throw new RuntimeException('ARGUMENT_INVALID');
				if($spec[$flag] === 1 && $value === null) {
					if(!isset($arguments[$i + 1]) || str_starts_with($arguments[$i + 1], '--')) throw new RuntimeException('ARGUMENT_VALUE_REQUIRED');
					$value = $arguments[++$i];
				}
				$name = ltrim($flag, '-');
				if(array_key_exists($name, $options)) {
					if(!in_array($name, array('c', 's', 'u', 'd', 'create', 'set', 'update', 'delete'), true)) throw new RuntimeException('ARGUMENT_DUPLICATE');
					$options[$name] = array_merge((array)$options[$name], array($value ?? false));
				} else $options[$name] = $value ?? false;
			}
			return $options;
		}
		static function record(string $input, string $action, ?string $value = null, ?int $ttl = null): array {
			$parts = explode('=', $input, 2);
			$selector = $parts[0];
			$record = array();
			if(str_starts_with($selector, '?')) $record['id'] = substr($selector, 1);
			elseif(preg_match('/^([^:]+):([a-zA-Z0-9]+)$/D', $selector, $match)) $record = array('name' => $match[1], 'type' => strtoupper($match[2]));
			else throw new RuntimeException('ARGUMENT_INVALID');
			if($value !== null && isset($parts[1])) throw new RuntimeException('ARGUMENT_VALUE_CONFLICT');
			if($value === null && isset($parts[1])) {
				$value = $parts[1];
				// IPv6 colons are data, never a TTL separator. Use --ttl for AAAA.
				if($action !== 'delete' && ($record['type'] ?? '') !== 'AAAA' && $ttl === null && preg_match('/^([^:]+):(\d+)$/D', $value, $match)) { $value = $match[1]; $ttl = (int)$match[2]; }
			}
			if($value !== null) $record['value'] = $value;
			elseif($action !== 'delete') throw new RuntimeException('ARGUMENT_VALUE_REQUIRED');
			if($ttl !== null) $record['ttl'] = $ttl;
			return $record;
		}
	}
