<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Informatics computed, not guessed: truth tables of logic expressions,
 * encodings (binary, hexadecimal, two's complement, UTF-8 bytes, Unicode code
 * points, Base64, URL encoding, hashes), and SQL run on example tables in a
 * throwaway in-memory SQLite database (no files, no extensions).
 */
class Informatics {
	/** @var list<string> */
	private array $tokens = [];
	private int $at = 0;
	/** @var array<string, bool> */
	private array $values = [];

	/** The truth table of an expression: and/∧/&, or/∨/|, not/¬/!, xor/⊕, ->/→, <->/↔, nand, nor; variables are names. */
	public static function truthTable(string $expression): array {
		$self = new self();
		$normal = strtr($expression, ['∧' => ' and ', '∨' => ' or ', '¬' => ' not ', '⊕' => ' xor ', '→' => ' -> ', '⇒' => ' -> ', '↔' => ' <-> ', '⇔' => ' <-> ',
			'&&' => ' and ', '||' => ' or ', '&' => ' and ', '|' => ' or ', '!' => ' not ', '~' => ' not ', '<=>' => ' <-> ', '=>' => ' -> ', '·' => ' and ']);
		preg_match_all('/<->|->|[()]|[A-Za-z_]\w*|[01]|\S/', $normal, $m);
		$self->tokens = $m[0];
		$keywords = ['and', 'or', 'not', 'xor', 'nand', 'nor', 'implies', 'iff', 'true', 'false'];
		$variables = array_values(array_unique(array_filter($self->tokens, fn ($t) => preg_match('/^[A-Za-z_]\w*$/', $t) === 1 && !in_array(strtolower($t), $keywords, true))));
		if (count($variables) > 6) {
			throw new \InvalidArgumentException('at most six variables (64 rows)');
		}
		$rows = [];
		$results = [];
		for ($i = 0; $i < 2 ** count($variables); $i++) {
			$self->values = [];
			foreach ($variables as $k => $v) {
				$self->values[$v] = (bool)(($i >> (count($variables) - 1 - $k)) & 1);
			}
			$self->at = 0;
			$value = $self->equivalence();
			if ($self->at < count($self->tokens)) {
				throw new \InvalidArgumentException('unexpected "' . $self->tokens[$self->at] . '"');
			}
			$results[] = $value;
			$rows[] = '| ' . implode(' | ', array_map(fn ($v) => (int)$v, $self->values)) . ' | **' . (int)$value . '** |';
		}
		$table = '| ' . implode(' | ', $variables) . ' | ' . trim($expression) . " |\n|" . str_repeat('---|', count($variables) + 1) . "\n" . implode("\n", $rows);
		return [
			'table' => $table,
			'kind' => !in_array(false, $results, true) ? 'tautology (always true)' : (!in_array(true, $results, true) ? 'contradiction (never true)' : 'satisfiable'),
			'true_rows' => count(array_filter($results)),
		];
	}

	private function equivalence(): bool {
		$left = $this->implication();
		while (in_array(strtolower((string)($this->tokens[$this->at] ?? '')), ['<->', 'iff'], true)) {
			$this->at++;
			$left = $left === $this->implication();
		}
		return $left;
	}

	private function implication(): bool {
		$left = $this->disjunction();
		if (in_array(strtolower((string)($this->tokens[$this->at] ?? '')), ['->', 'implies'], true)) {
			$this->at++;
			// Parsed first: || would skip the right side's tokens.
			$right = $this->implication();
			return !$left || $right;
		}
		return $left;
	}

	private function disjunction(): bool {
		$left = $this->conjunction();
		while (in_array($op = strtolower((string)($this->tokens[$this->at] ?? '')), ['or', 'xor', 'nor', '+'], true)) {
			$this->at++;
			$right = $this->conjunction();
			$left = match ($op) {
				'xor' => $left !== $right,
				'nor' => !($left || $right),
				default => $left || $right,
			};
		}
		return $left;
	}

	private function conjunction(): bool {
		$left = $this->negation();
		while (in_array($op = strtolower((string)($this->tokens[$this->at] ?? '')), ['and', 'nand', '*'], true)) {
			$this->at++;
			$right = $this->negation();
			$left = $op === 'nand' ? !($left && $right) : $left && $right;
		}
		return $left;
	}

	private function negation(): bool {
		$t = $this->tokens[$this->at++] ?? throw new \InvalidArgumentException('incomplete');
		return match (true) {
			strtolower($t) === 'not' => !$this->negation(),
			$t === '(' => (function () {
				$v = $this->equivalence();
				if (($this->tokens[$this->at] ?? '') !== ')') {
					throw new \InvalidArgumentException('")" missing');
				}
				$this->at++;
				return $v;
			})(),
			$t === '1' || strtolower($t) === 'true' => true,
			$t === '0' || strtolower($t) === 'false' => false,
			isset($this->values[$t]) => $this->values[$t],
			default => throw new \InvalidArgumentException('unexpected "' . $t . '"'),
		};
	}

	/** A text or number in the encodings asked for. */
	public static function encode(string $input, string $kind, int $bits = 8): array {
		return match ($kind) {
			'number' => self::number($input, $bits),
			'base64' => ['base64' => base64_encode($input)],
			'base64_decode' => ['text' => ($d = base64_decode($input, true)) === false ? throw new \InvalidArgumentException('not Base64') : $d],
			'url' => ['url_encoded' => rawurlencode($input)],
			'url_decode' => ['text' => rawurldecode($input)],
			'hash' => ['md5' => md5($input), 'sha1' => sha1($input), 'sha256' => hash('sha256', $input), 'crc32' => sprintf('%08x', crc32($input))],
			default => [
				'utf8_bytes_hex' => implode(' ', str_split(strtoupper(bin2hex($input)), 2)),
				'utf8_bytes_binary' => implode(' ', array_map(fn ($b) => str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT), str_split($input))),
				'code_points' => implode(' ', array_map(fn ($c) => 'U+' . strtoupper(str_pad(dechex(mb_ord($c)), 4, '0', STR_PAD_LEFT)) . ' (' . mb_ord($c) . ')', mb_str_split($input))),
			],
		};
	}

	/** An integer in bases 2, 8, 10 and 16, and in two's complement with this many bits. */
	private static function number(string $input, int $bits): array {
		$input = str_replace([' ', '_'], '', trim($input));
		$n = match (true) {
			preg_match('/^-?0[bB][01]+$/', $input) === 1 => (str_starts_with($input, '-') ? -1 : 1) * bindec(substr(ltrim($input, '-'), 2)),
			preg_match('/^-?0[xX][0-9a-fA-F]+$/', $input) === 1 => (str_starts_with($input, '-') ? -1 : 1) * hexdec(substr(ltrim($input, '-'), 2)),
			preg_match('/^-?0[oO][0-7]+$/', $input) === 1 => (str_starts_with($input, '-') ? -1 : 1) * octdec(substr(ltrim($input, '-'), 2)),
			preg_match('/^-?\d+$/', $input) === 1 => (int)$input,
			default => throw new \InvalidArgumentException('a whole number, e.g. 42, -5, 0b1010, 0x2A'),
		};
		$bits = max(2, min(64, $bits));
		$fits = $n >= -(2 ** ($bits - 1)) && $n < 2 ** ($bits - 1);
		$twos = $fits ? str_pad(decbin($n < 0 ? (2 ** $bits + $n) : $n), $bits, '0', STR_PAD_LEFT) : 'does not fit in ' . $bits . ' bits';
		return ['decimal' => (string)$n, 'binary' => ($n < 0 ? '-' : '') . decbin(abs((int)$n)), 'octal' => ($n < 0 ? '-' : '') . decoct(abs((int)$n)),
			'hexadecimal' => ($n < 0 ? '-' : '') . strtoupper(dechex(abs((int)$n))), 'twos_complement_' . $bits . '_bit' => $twos];
	}

	/**
	 * SQL on example data: the setup statements (CREATE TABLE, INSERT) and the
	 * query run in a new in-memory SQLite database; the query's rows as a
	 * Markdown table. Nothing reaches files: ATTACH, VACUUM, PRAGMA and
	 * extensions are refused, and so are recursive queries, which could run forever.
	 */
	public static function sql(string $setup, string $query): array {
		if (!class_exists(\PDO::class) || !in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
			throw new \RuntimeException('SQLite is not available on this server');
		}
		foreach ([$setup, $query] as $sql) {
			// Recursive queries could run without end; the rest could touch files.
			if (preg_match('/\b(attach|detach|vacuum|pragma|load_extension|recursive)\b/i', $sql) === 1) {
				throw new \InvalidArgumentException('ATTACH, VACUUM, PRAGMA, extensions and recursive queries are not allowed here');
			}
		}
		$db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
		$started = microtime(true);
		if (trim($setup) !== '') {
			$db->exec($setup);
		}
		$out = [];
		foreach (array_filter(array_map('trim', self::statements($query))) as $statement) {
			$result = $db->query($statement);
			$rows = $result->fetchAll(\PDO::FETCH_ASSOC);
			if ($result->columnCount() === 0) {
				$out[] = ['statement' => $statement, 'changed_rows' => $result->rowCount()];
				continue;
			}
			$columns = [];
			for ($i = 0; $i < $result->columnCount(); $i++) {
				$columns[] = (string)($result->getColumnMeta($i)['name'] ?? ('col' . $i));
			}
			$table = '| ' . implode(' | ', $columns) . " |\n|" . str_repeat('---|', count($columns));
			foreach (array_slice($rows, 0, 100) as $row) {
				$table .= "\n| " . implode(' | ', array_map(fn ($v) => $v === null ? 'NULL' : str_replace('|', '\|', (string)$v), array_values($row))) . ' |';
			}
			$out[] = ['statement' => $statement, 'rows' => count($rows), 'table' => $table . (count($rows) > 100 ? "\n(first 100 rows)" : '')];
			if (microtime(true) - $started > 10) {
				break;
			}
		}
		return $out;
	}

	/** SQL statements split at semicolons outside quotes. */
	private static function statements(string $sql): array {
		return preg_split('/;(?=(?:[^\'"]*[\'"][^\'"]*[\'"])*[^\'"]*$)/', $sql) ?: [$sql];
	}
}
