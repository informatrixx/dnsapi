<?php
	final class DNSAPITXT {
		static function value(string $value): string {
			if(!str_starts_with($value, '"')) return $value;
			if(strpbrk($value, "\r\n") !== false || !preg_match('/^(?:"(?:[^"\\\\]|\\\\.)*"[ \t]*)+$/D', $value)) throw new RuntimeException('TXT_VALUE_INVALID');
			preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $value, $strings);
			$result = '';
			foreach($strings[1] as $string) {
				$result .= preg_replace_callback('/\\\\(\d{3}|.)/s', function($match) {
					if(strlen($match[1]) !== 3 || !ctype_digit($match[1])) return $match[1];
					if((int)$match[1] > 255) throw new RuntimeException('TXT_VALUE_INVALID');
					return chr((int)$match[1]);
				}, $string);
			}
			return $result;
		}

		// Split decoded octets before escaping, so each DNS character-string fits its length byte.
		static function serialize(string $value): string {
			$strings = array();
			foreach($value === '' ? array('') : str_split($value, 255) as $part) {
				$escaped = preg_replace_callback('/[\x00-\x1f\x7f-\xff"\\\\]/', function($match) {
					return $match[0] === '"' || $match[0] === '\\' ? '\\' . $match[0] : sprintf('\\%03d', ord($match[0]));
				}, $part);
				$strings[] = '"' . $escaped . '"';
			}
			return implode(' ', $strings);
		}
	}
