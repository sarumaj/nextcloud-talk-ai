<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Theoretical informatics run step by step: the accumulator register machine of German
 * school glossaries (e.g. Hessen's for the Abitur: LOAD/STORE/ADD/SUB/MUL/DIV, GOTO/JZERO/JNZERO,
 * END; constants #i, registers i, indirect *i; natural numbers), finite automata
 * (deterministic or not) on words, and regular expressions on words.
 */
class Machines {
	private const MAX_STEPS = 100000;

	/**
	 * Runs a register machine program: one instruction per line, optionally with a
	 * label ("loop: SUB #1" or a line number "3 JZERO 7"); jumps go to a label or a
	 * line number (counted from 1, or as written in front). The trace of the first steps.
	 *
	 * @param array<int|string, int> $registers start values, e.g. {"1": 5, "2": 3}
	 */
	public static function registerMachine(string $program, array $registers = [], int $traceSteps = 60): array {
		$lines = [];
		$labels = [];
		foreach (preg_split('/\R/', trim($program)) ?: [] as $raw) {
			$line = trim(preg_replace('/(\/\/|;|#\s).*$/', '', $raw) ?? $raw);
			if ($line === '') {
				continue;
			}
			if (preg_match('/^(\w+)\s*[:.)]\s*(.*)$/u', $line, $m) === 1 || preg_match('/^(\d+)\s+(.*)$/', $line, $m) === 1) {
				$labels[mb_strtolower($m[1])] = count($lines);
				$line = trim($m[2]);
				if ($line === '') {
					continue;
				}
			}
			$parts = preg_split('/\s+/', $line) ?: [];
			$lines[] = [strtoupper($parts[0]), $parts[1] ?? '', $line];
		}
		if ($lines === []) {
			throw new \InvalidArgumentException('no instructions');
		}
		$reg = [];
		foreach ($registers as $i => $v) {
			$reg[(int)$i] = max(0, (int)$v);
		}
		$acc = 0;
		$pc = 0;
		$trace = [];
		$target = function (string $m) use ($labels, $lines): int {
			$key = mb_strtolower($m);
			if (isset($labels[$key])) {
				return $labels[$key];
			}
			if (ctype_digit($m) && (int)$m >= 1 && (int)$m <= count($lines)) {
				return (int)$m - 1;
			}
			throw new \InvalidArgumentException('no label or line "' . $m . '"');
		};
		$address = function (string $x) use (&$reg): int {
			if (preg_match('/^\*(\d+)$/', $x, $m) === 1) {
				return $reg[(int)$m[1]] ?? 0;
			}
			if (ctype_digit($x)) {
				return (int)$x;
			}
			throw new \InvalidArgumentException('operand "' . $x . '": #i, i or *i');
		};
		// A closure by reference, not an arrow function: it must see what STORE wrote.
		$value = function (string $x) use (&$reg, $address): int {
			return preg_match('/^#(\d+)$/', $x, $m) === 1 ? (int)$m[1] : ($reg[$address($x)] ?? 0);
		};
		$ended = 'END';
		for ($step = 1; ; $step++) {
			if ($step > self::MAX_STEPS) {
				$ended = 'stopped after ' . self::MAX_STEPS . ' steps (an endless loop?)';
				break;
			}
			if ($pc < 0 || $pc >= count($lines)) {
				$ended = 'ran past the last line without END';
				break;
			}
			[$op, $x, $text] = $lines[$pc];
			$next = $pc + 1;
			$changed = '';
			switch ($op) {
				case 'LOAD': $acc = $value($x); break;
				case 'STORE':
					if (str_starts_with($x, '#')) {
						throw new \InvalidArgumentException('line ' . ($pc + 1) . ': STORE cannot take a constant');
					}
					$reg[$address($x)] = $acc;
					$changed = 'R' . $address($x) . ' = ' . $acc;
					break;
				case 'ADD': $acc += $value($x); break;
				case 'SUB': $acc = max(0, $acc - $value($x)); break;
				case 'MUL': $acc *= $value($x); break;
				case 'DIV':
					$d = $value($x);
					if ($d === 0) {
						$ended = 'DIV by 0 ended the program (line ' . ($pc + 1) . ')';
						break 2;
					}
					$acc = intdiv($acc, $d);
					break;
				case 'GOTO': $next = $target($x); break;
				case 'JZERO': $next = $acc === 0 ? $target($x) : $next; break;
				case 'JNZERO': $next = $acc > 0 ? $target($x) : $next; break;
				case 'END':
					if ($step <= $traceSteps) {
						$trace[] = ['step' => $step, 'line' => $pc + 1, 'instruction' => $text, 'accumulator' => $acc];
					}
					break 2;
				default: throw new \InvalidArgumentException('line ' . ($pc + 1) . ': unknown instruction "' . $op . '"');
			}
			if ($step <= $traceSteps) {
				$trace[] = array_filter(['step' => $step, 'line' => $pc + 1, 'instruction' => $text, 'accumulator' => $acc, 'changed' => $changed], fn ($v) => $v !== '');
			}
			$pc = $next;
		}
		ksort($reg);
		return ['ended' => $ended, 'steps' => $step, 'accumulator' => $acc, 'registers' => $reg, 'trace' => $trace,
			'machine' => 'accumulator machine on natural numbers: SUB stops at 0, DIV divides whole numbers, DIV by 0 ends; #i a constant, i register i, *i the register named in register i'];
	}

	/**
	 * A finite automaton on words. transitions: lines "q0, a -> q1" (or "q0 a q1"),
	 * several targets or ε make it nondeterministic. Whether it is deterministic
	 * and complete, and per word the run and whether it is accepted.
	 */
	public static function automaton(string $transitions, string $start, array $accepting, array $words): array {
		$delta = [];
		$states = [$start => true];
		$alphabet = [];
		foreach (preg_split('/\R|;/', trim($transitions)) ?: [] as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			if (preg_match('/^\(?\s*([^\s,()]+)\s*[, ]\s*([^\s,()]+)\s*\)?\s*(?:->|→|=|\s)\s*\{?\s*([^}]+?)\s*\}?$/u', $line, $m) !== 1) {
				throw new \InvalidArgumentException('a transition as "q0, a -> q1", not "' . $line . '"');
			}
			$symbol = in_array($m[2], ['ε', 'eps', 'epsilon', 'λ'], true) ? 'ε' : $m[2];
			foreach (preg_split('/\s*[,|]\s*/', $m[3]) ?: [] as $to) {
				$delta[$m[1]][$symbol][$to] = true;
				$states[$m[1]] = $states[$to] = true;
			}
			if ($symbol !== 'ε') {
				$alphabet[$symbol] = true;
			}
		}
		$accepting = array_flip(array_map('strval', $accepting));
		$deterministic = true;
		$missing = [];
		foreach (array_keys($states) as $q) {
			if (isset($delta[$q]['ε'])) {
				$deterministic = false;
			}
			foreach (array_keys($alphabet) as $a) {
				$n = count($delta[$q][$a] ?? []);
				$deterministic = $deterministic && $n <= 1;
				if ($n === 0) {
					$missing[] = '(' . $q . ', ' . $a . ')';
				}
			}
		}
		$closure = function (array $set) use ($delta): array {
			$stack = array_keys($set);
			while ($stack !== []) {
				$q = array_pop($stack);
				foreach (array_keys($delta[$q]['ε'] ?? []) as $to) {
					if (!isset($set[$to])) {
						$set[$to] = true;
						$stack[] = $to;
					}
				}
			}
			return $set;
		};
		$runs = [];
		foreach (array_slice($words, 0, 50) as $word) {
			$word = (string)$word;
			$symbols = preg_match('/[\s,]/', $word) === 1 ? preg_split('/[\s,]+/', trim($word)) : mb_str_split($word);
			$current = $closure([$start => true]);
			$path = ['{' . implode(', ', array_keys($current)) . '}'];
			foreach ($symbols as $s) {
				if ($s === '' || $s === 'ε') {
					continue;
				}
				$nextSet = [];
				foreach (array_keys($current) as $q) {
					$nextSet += $delta[$q][$s] ?? [];
				}
				$current = $closure($nextSet);
				$path[] = $s . ' → {' . implode(', ', array_keys($current)) . '}';
			}
			$runs[] = ['word' => $word === '' ? 'ε' : $word, 'accepted' => array_intersect_key($current, $accepting) !== [],
				'run' => $deterministic ? str_replace(['{', '}'], '', implode(' ', $path)) : implode(' ', $path)];
		}
		return ['states' => array_keys($states), 'alphabet' => array_keys($alphabet), 'start' => $start, 'accepting' => array_keys($accepting),
			'deterministic' => $deterministic, 'complete' => $missing === [], 'missing_transitions' => array_slice($missing, 0, 30), 'words' => $runs];
	}

	/**
	 * Which words a regular expression matches as a whole, in the glossary's syntax
	 * (. \ | [...] [^...] ( ) ? * + {n} {n,m}).
	 */
	public static function regex(string $pattern, array $words): array {
		$regex = '/^(?:' . str_replace('/', '\/', $pattern) . ')$/u';
		if (@preg_match($regex, '') === false) {
			throw new \InvalidArgumentException('not a valid regular expression: ' . $pattern);
		}
		return ['pattern' => $pattern, 'words' => array_map(fn ($w) => ['word' => (string)$w === '' ? 'ε' : (string)$w, 'matches' => preg_match($regex, (string)$w) === 1],
			array_slice($words, 0, 100)), 'note' => 'the whole word must match'];
	}
}
