<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Exam arithmetic for school and university, by the rules the person names rather
 * than rules built in for one state or year: raw marks to points or grades by a
 * table (the common 15-point table of German upper secondary schools by default),
 * an error index and its deduction or scale, grades from weighted parts, written and
 * oral exams combined, the Abitur's total and average by the nationwide KMK formulas,
 * a check of a mock exam (marks, requirement levels), and a study plan to exam dates.
 * Syllabus, operators, dates and aids differ by state, school and year: they come from
 * the official documents, read like any other file or page.
 */
class Exams {
	/** The usual German 15-point table: points => from percent. */
	public const POINTS_TABLE = [15 => 95, 14 => 90, 13 => 85, 12 => 80, 11 => 75, 10 => 70, 9 => 65, 8 => 60, 7 => 55,
		6 => 50, 5 => 45, 4 => 40, 3 => 33, 2 => 27, 1 => 20, 0 => 0];

	/**
	 * Marks to points or a grade: achieved of max by a table {result: from percent}
	 * (the 15-point table by default). whole: the whole, unrounded percent counts (as
	 * many regulations say); else the exact one.
	 */
	public static function points(float $achieved, float $max = 100, array $table = [], bool $whole = true): array {
		if ($max <= 0 || $achieved < 0 || $achieved > $max) {
			throw new \InvalidArgumentException('achieved must lie between 0 and the maximum');
		}
		$custom = $table !== [];
		$table = $custom ? array_map('floatval', $table) : self::POINTS_TABLE;
		arsort($table);
		$percent = $achieved / $max * 100;
		$counted = $whole ? floor($percent + 1e-9) : $percent;
		$result = null;
		$next = null;
		foreach ($table as $label => $from) {
			if ($counted >= $from - 1e-9) {
				$result = $label;
				break;
			}
			$next = [$label, $from];
		}
		$out = ['percent' => round($percent, 2), 'result' => $result ?? 'below the table'];
		if (!$custom && is_int($result)) {
			$out['grade'] = self::grade($result);
		}
		if ($next !== null) {
			$out['next'] = $next[0] . ' from ' . Calculator::format($next[1]) . ' % (' . Calculator::format(ceil($next[1] * $max / 100 * 2) / 2) . ' of ' . Calculator::format($max) . ')';
		}
		$out['table'] = implode(', ', array_map(fn ($l, $f) => $l . ' from ' . Calculator::format($f) . ' %', array_keys($table), $table))
			. ($custom ? '' : ' (the usual 15-point table; give the school\'s if it differs)') . ($whole ? '; the whole percent counts, not rounded' : '');
		return $out;
	}

	/**
	 * An error index (errors × 100 / words) and what it costs: steps [[from index,
	 * points off], …] (default from 3 one point, from 6 two, as many German regulations
	 * have), or a table [[up to index, result], …] that turns it into a result itself (as
	 * for translations from Latin).
	 */
	public static function errorIndex(float $errors, int $words, array $steps = [], array $table = [], ?int $points = null): array {
		if ($words <= 0 || $errors < 0) {
			throw new \InvalidArgumentException('words must be positive and errors not negative');
		}
		$index = $errors * 100 / $words;
		$out = ['error_index' => round($index, 2)];
		if ($table !== []) {
			usort($table, fn ($a, $b) => (float)$a[0] <=> (float)$b[0]);
			$result = null;
			foreach ($table as [$upTo, $value]) {
				if ($index <= (float)$upTo + 1e-9) {
					$result = $value;
					break;
				}
			}
			return $out + ['result' => $result ?? 'beyond the table', 'rule' => 'the first row whose index the work does not exceed'];
		}
		$steps = $steps !== [] ? $steps : [[3, 1], [6, 2]];
		usort($steps, fn ($a, $b) => (float)$a[0] <=> (float)$b[0]);
		$whole = floor($index + 1e-9);
		$off = 0.0;
		foreach ($steps as [$from, $deduction]) {
			if ($whole >= (float)$from) {
				$off = (float)$deduction;
			}
		}
		$out['deduction'] = $off;
		if ($points !== null) {
			$out['points_after'] = max(0, $points - $off);
		}
		$out['rule'] = implode(', ', array_map(fn ($s) => 'from ' . $s[0] . ': ' . $s[1] . ' off', $steps)) . ' (the whole index, not rounded)';
		return $out;
	}

	/**
	 * A grade from weighted parts [{name, points, weight}] (e.g. content 70 %, language
	 * 30 %), rounded to whole points half up, or with the decimals given.
	 */
	public static function weighted(array $parts, int $decimals = 0): array {
		$sum = $weights = 0.0;
		$terms = [];
		foreach ($parts as $p) {
			$w = (float)($p['weight'] ?? 1);
			$sum += (float)($p['points'] ?? 0) * $w;
			$weights += $w;
			$terms[] = ($p['name'] ?? '?') . ': ' . Calculator::format((float)($p['points'] ?? 0)) . ' × ' . Calculator::format($w);
		}
		if ($weights <= 0) {
			throw new \InvalidArgumentException('the weights must add up to more than 0');
		}
		$exact = $sum / $weights;
		$f = 10 ** $decimals;
		return ['exact' => Calculator::format(round($exact, 4)), 'result' => Calculator::format(floor($exact * $f + 0.5 + 1e-9) / $f),
			'terms' => $terms, 'note' => 'rounded half up; regulations may add caps (e.g. a part with 0 points limiting the whole)'];
	}

	/**
	 * Written and oral exam in one subject: (written × w + oral × o) / (w + o) × factor, the
	 * whole part taken. Default as most German states: written twice, oral once, counted
	 * four times in the Abitur.
	 */
	public static function combine(float $written, float $oral, float $writtenWeight = 2, float $oralWeight = 1, float $factor = 4): array {
		$value = ($written * $writtenWeight + $oral * $oralWeight) / ($writtenWeight + $oralWeight) * $factor;
		return ['result' => (int)floor($value + 1e-9), 'exact' => Calculator::format(round($value, 4)), 'single' => Calculator::format(round($value / $factor, 2)),
			'formula' => '(' . Calculator::format($writtenWeight) . ' × written + ' . Calculator::format($oralWeight) . ' × oral) / ' . Calculator::format($writtenWeight + $oralWeight) . ' × ' . Calculator::format($factor) . ', whole part'];
	}

	/**
	 * The Abitur's total and average by the KMK's agreement (valid in every state):
	 * Block I = (course points / courses counted) × 40, with courses [{points, weight}]
	 * (an LK course weight 2 where it counts twice) or its points given; Block II = the
	 * exams scaled to 300 (5 exams × 4, 4 × 5). Average N = 17/3 − E/180, one decimal
	 * cut off; 823 points or more give 1,0. With target: the Block II it needs.
	 */
	public static function abitur(array $courses = [], ?int $block1 = null, array $exams = [], ?float $target = null): array {
		$out = [];
		if ($courses !== []) {
			$sum = $count = 0.0;
			foreach ($courses as $c) {
				$w = (float)(is_array($c) ? ($c['weight'] ?? 1) : 1);
				$p = (float)(is_array($c) ? ($c['points'] ?? 0) : $c);
				if ($p < 0 || $p > 15) {
					throw new \InvalidArgumentException('course points are 0 to 15');
				}
				$sum += $p * $w;
				$count += $w;
			}
			$block1 = (int)floor($sum / $count * 40 + 0.5);
			$out['block_1'] = ['points' => $block1, 'of' => 600, 'formula' => '(' . Calculator::format($sum) . ' / ' . Calculator::format($count) . ') × 40, rounded'];
		} elseif ($block1 !== null) {
			$out['block_1'] = ['points' => $block1, 'of' => 600];
		}
		if ($exams !== []) {
			$factor = 20 / count($exams);
			$block2 = (int)floor(array_sum(array_map('floatval', $exams)) * $factor + 0.5);
			$out['block_2'] = ['points' => $block2, 'of' => 300, 'formula' => 'the ' . count($exams) . ' exams × ' . Calculator::format($factor)];
		}
		if (isset($out['block_1'], $out['block_2'])) {
			$total = $out['block_1']['points'] + $out['block_2']['points'];
			$out['total'] = $total . ' / 900';
			$out['average'] = $total >= 300 ? self::average($total) : 'under 300 points: not passed';
			$out['minimums'] = ['block_1 ≥ 200' => $out['block_1']['points'] >= 200, 'block_2 ≥ 100' => $out['block_2']['points'] >= 100];
		}
		if ($target !== null && isset($out['block_1'])) {
			$needed = self::needed($target);
			$rest = max(100, $needed - $out['block_1']['points']);
			$out['for_target'] = ['grade' => number_format($target, 1, ',', ''), 'total_needed' => $needed, 'block_2_needed' => $rest, 'reachable' => $rest <= 300];
		}
		$out['rule'] = 'KMK agreement on the Abitur: Block I max 600, Block II max 300; average 17/3 − total/180, one decimal cut off (823+ = 1,0). Which courses count, double weights and further conditions (courses under 5 points, exams with 5 points) are set by each state: check them in its regulation.';
		return $out;
	}

	/** The average of a total of 300 to 900 by the KMK formula. */
	public static function average(int $total): string {
		if ($total >= 823) {
			return '1,0';
		}
		return number_format(min(4.0, floor((17 / 3 - $total / 180) * 10 + 1e-9) / 10), 1, ',', '');
	}

	/** The least total for an average (KMK formula). */
	public static function needed(float $grade): int {
		return $grade <= 1.0 ? 823 : max(300, (int)ceil((17 / 3 - $grade - 0.1 + 1e-9) * 180));
	}

	/**
	 * A check of a practice exam: tasks [{id, operator, afb (I, II, III), marks}] against
	 * the total and the shares of the requirement levels (default about 30/40/30 %, the
	 * main weight on II); marks per minute when minutes are given.
	 */
	public static function check(array $tasks, ?float $total = null, ?float $minutes = null, array $shares = []): array {
		$by = ['I' => 0.0, 'II' => 0.0, 'III' => 0.0];
		$sum = 0.0;
		$problems = [];
		foreach ($tasks as $t) {
			$m = (float)($t['marks'] ?? 0);
			$sum += $m;
			$afb = strtoupper(trim((string)($t['afb'] ?? '')));
			if (isset($by[$afb])) {
				$by[$afb] += $m;
			} else {
				$problems[] = ($t['id'] ?? '?') . ': no requirement level (I, II or III)';
			}
			if (trim((string)($t['operator'] ?? '')) === '') {
				$problems[] = ($t['id'] ?? '?') . ': no operator';
			}
		}
		if ($total !== null && abs($sum - $total) > 1e-9) {
			$problems[] = 'the marks add up to ' . Calculator::format($sum) . ', not ' . Calculator::format($total);
		}
		$want = $shares + ['I' => 30, 'II' => 40, 'III' => 30];
		$actual = array_map(fn ($v) => $sum > 0 ? round($v / $sum * 100) : 0, $by);
		foreach ($actual as $level => $share) {
			if (abs($share - $want[$level]) > 10) {
				$problems[] = 'level ' . $level . ' has ' . $share . ' %, about ' . $want[$level] . ' % is usual';
			}
		}
		if ($by['III'] == 0.0) {
			$problems[] = 'no task reaches level III';
		}
		return array_filter(['marks' => Calculator::format($sum), 'levels' => array_map(fn ($v, $s) => Calculator::format($v) . ' (' . $s . ' %)', $by, $actual),
			'marks_per_minute' => $minutes ? round($sum / $minutes, 2) : null, 'problems' => $problems, 'fine' => $problems === []], fn ($v) => $v !== null);
	}

	/**
	 * A study plan: each exam's topics spread over the weeks from a date to two weeks
	 * before it, then revision and a mock exam. One task per week, for the calendar tool.
	 * exams [{name, date, topics: [..]}].
	 */
	public static function plan(array $exams, string $from = '', int $revisionDays = 14): array {
		$start = (new \DateTimeImmutable(($from !== '' ? $from : 'today') . ' 00:00'))->modify('monday this week');
		$weeks = [];
		$finals = [];
		foreach ($exams as $e) {
			$name = (string)($e['name'] ?? 'Exam');
			$date = new \DateTimeImmutable((string)($e['date'] ?? ''));
			if ($date <= $start) {
				throw new \InvalidArgumentException($name . ': the exam date must lie ahead');
			}
			$end = $date->modify('-' . max(0, $revisionDays) . ' days');
			$count = max(1, (int)floor(($end->getTimestamp() - $start->getTimestamp()) / (7 * 86400)));
			$topics = array_values(array_map('strval', (array)($e['topics'] ?? [])));
			foreach ($topics as $i => $topic) {
				$weeks[$start->modify('+' . (int)floor($i * $count / max(1, count($topics))) . ' weeks')->format('Y-m-d')][] = $name . ': ' . $topic;
			}
			$finals[] = ['exam' => $name, 'date' => $date->format('Y-m-d'), 'revision_from' => max($start, $end)->format('Y-m-d')];
		}
		ksort($weeks);
		$tasks = [];
		foreach ($weeks as $week => $items) {
			$tasks[] = ['title' => 'Study: ' . implode(', ', array_unique(array_map(fn ($i) => (string)strstr($i, ':', true), $items))),
				'due' => (new \DateTimeImmutable($week))->modify('sunday this week')->format('Y-m-d'), 'description' => implode("\n", $items)];
		}
		foreach ($finals as $f) {
			$tasks[] = ['title' => $f['exam'] . ': revision and a mock exam', 'due' => $f['revision_from'], 'description' => 'gaps, past papers, a mock exam in the real time'];
		}
		return ['weeks' => $weeks, 'final_phase' => $finals, 'tasks' => array_slice($tasks, 0, 100),
			'note' => 'Topics in the order given; covered ones can go into revision.'];
	}

	/** The grade words of German upper secondary points (sehr gut 15–13 … ungenügend 0). */
	public static function grade(int $points): string {
		return match (true) {
			$points >= 13 => 'sehr gut', $points >= 10 => 'gut', $points >= 7 => 'befriedigend',
			$points >= 4 => 'ausreichend', $points >= 1 => 'mangelhaft', default => 'ungenügend',
		} . ' (' . match ($points) {
			15 => '1+', 14 => '1', 13 => '1-', 12 => '2+', 11 => '2', 10 => '2-', 9 => '3+', 8 => '3', 7 => '3-',
			6 => '4+', 5 => '4', 4 => '4-', 3 => '5+', 2 => '5', 1 => '5-', default => '6',
		} . ')';
	}
}
