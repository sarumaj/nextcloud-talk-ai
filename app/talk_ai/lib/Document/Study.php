<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Grades and workload at German universities, by the rule of the person's
 * Prüfungsordnung rather than a guess: the weighted average (ECTS or own weights,
 * best-of selections, double weights, one or two decimals cut or rounded), a module
 * grade from its parts, the average still needed for a target, foreign grades by the
 * "bayerische Formel", the 0–18 points of law exams, and the workload of credits.
 */
class Study {
	/**
	 * The average of modules [{name, grade, ects, weight?, group?}] by the rule:
	 * decimals (1 or 2), mode ("truncate", as most POs, or "round"), best_of
	 * ({group: n}: only the n best of a group count), double ([names] counted twice).
	 */
	public static function average(array $modules, array $rule = []): array {
		$decimals = (int)($rule['decimals'] ?? 1);
		$truncate = ($rule['mode'] ?? 'truncate') !== 'round';
		$used = [];
		$dropped = [];
		$groups = [];
		foreach ($modules as $m) {
			$grade = self::grade($m['grade'] ?? null);
			if ($grade === null) {
				$dropped[] = ($m['name'] ?? '?') . ' (no grade)';
				continue;
			}
			$groups[(string)($m['group'] ?? '')][] = ['name' => (string)($m['name'] ?? '?'), 'grade' => $grade,
				'weight' => (float)($m['weight'] ?? $m['ects'] ?? 1)];
		}
		foreach ($groups as $group => $items) {
			$best = $rule['best_of'][$group] ?? null;
			if ($best !== null && count($items) > (int)$best) {
				usort($items, fn ($a, $b) => $a['grade'] <=> $b['grade']);
				foreach (array_slice($items, (int)$best) as $i) {
					$dropped[] = $i['name'] . ' (not among the best ' . (int)$best . ' of ' . $group . ')';
				}
				$items = array_slice($items, 0, (int)$best);
			}
			$used = [...$used, ...$items];
		}
		if ($used === []) {
			throw new \InvalidArgumentException('no graded modules');
		}
		$double = array_map('mb_strtolower', (array)($rule['double'] ?? []));
		$sum = $weights = 0.0;
		$terms = [];
		foreach ($used as $m) {
			$w = $m['weight'] * (in_array(mb_strtolower($m['name']), $double, true) ? 2 : 1);
			$sum += $m['grade'] * $w;
			$weights += $w;
			$terms[] = self::de($m['grade']) . ' × ' . Calculator::format($w);
		}
		if ($weights <= 0) {
			throw new \InvalidArgumentException('the weights must add up to more than 0');
		}
		$exact = $sum / $weights;
		$average = self::cut($exact, $decimals, $truncate);
		return array_filter(['average' => self::de($average, $decimals), 'exact' => self::de(round($exact, 6), 6),
			'rating' => self::rating($average, (float)($rule['pass_mark'] ?? 4.0)), 'weights' => Calculator::format($weights),
			'formula' => '(' . implode(' + ', array_slice($terms, 0, 40)) . (count($terms) > 40 ? ' + …' : '') . ') / ' . Calculator::format($weights),
			'not_counted' => $dropped,
			'rule' => 'weighted by ' . (isset($modules[0]['weight']) ? 'the given weights' : 'ECTS') . '; ' . $decimals . ' decimal' . ($decimals > 1 ? 's' : '')
				. ($truncate ? ', the rest cut off (not rounded)' : ', rounded') . ($double !== [] ? '; counted twice: ' . implode(', ', $double) : '')
				. '. Check this against your Prüfungsordnung.'], fn ($v) => $v !== []);
	}

	/** A module grade from weighted parts [{name, grade, weight}] (weights as % or shares). */
	public static function moduleGrade(array $parts, array $rule = []): array {
		return self::average(array_map(fn ($p) => ['name' => $p['name'] ?? '', 'grade' => $p['grade'] ?? null, 'weight' => $p['weight'] ?? 1], $parts), $rule)
			+ ['note' => 'some POs require each part to be passed on its own; then a 5,0 in one fails the module whatever the average'];
	}

	/** The average the remaining credits need for a target average. */
	public static function required(array $modules, float $remainingEcts, float $target, array $rule = []): array {
		$sum = $ects = 0.0;
		foreach ($modules as $m) {
			$g = self::grade($m['grade'] ?? null);
			if ($g !== null) {
				$w = (float)($m['weight'] ?? $m['ects'] ?? 1);
				$sum += $g * $w;
				$ects += $w;
			}
		}
		if ($remainingEcts <= 0) {
			throw new \InvalidArgumentException('remaining credits must be positive');
		}
		$decimals = (int)($rule['decimals'] ?? 1);
		$truncate = ($rule['mode'] ?? 'truncate') !== 'round';
		// The worst average that still gives the target: with cutting off, anything below target + one step.
		$limit = $truncate ? $target + 10 ** -$decimals - 1e-9 : $target + 0.5 * 10 ** -$decimals - 1e-9;
		$needed = ($limit * ($ects + $remainingEcts) - $sum) / $remainingEcts;
		$current = $ects > 0 ? $sum / $ects : null;
		return ['current' => $current === null ? 'none yet' : self::de(self::cut($current, $decimals, $truncate), $decimals),
			'target' => self::de($target, $decimals), 'remaining_ects' => Calculator::format($remainingEcts),
			'needed_average' => $needed < 1.0 ? 'not reachable (it would need better than 1,0: ' . self::de($needed, 2) . ')' : ($needed >= 4.0 ? 'any pass (4,0) is enough' : 'at most ' . self::de(floor($needed * 100) / 100, 2)),
			'method' => '(target × all credits − grade points so far) / remaining credits, with the rounding rule'];
	}

	/**
	 * A foreign grade by the "bayerische Formel" (KMK): N = 1 + 3 × (Nmax − Nd) / (Nmax − Nmin),
	 * Nmax the best and Nmin the lowest passing grade of the foreign scale.
	 */
	public static function foreign(float $grade, float $best, float $lowestPass): array {
		if ($best == $lowestPass) {
			throw new \InvalidArgumentException('best and lowest passing grade must differ');
		}
		$n = 1 + 3 * ($best - $grade) / ($best - $lowestPass);
		if ($n < 1 - 1e-9 || $n > 4 + 1e-9) {
			throw new \InvalidArgumentException('the grade lies outside the passing range of the scale');
		}
		return ['german_grade' => self::de(self::cut($n, 1, true), 1), 'exact' => self::de(round($n, 4), 4), 'rounded' => self::de(round($n, 1), 1),
			'formula' => 'N = 1 + 3 × (' . Calculator::format($best) . ' − ' . Calculator::format($grade) . ') / (' . Calculator::format($best) . ' − ' . Calculator::format($lowestPass) . ')',
			'note' => 'Universities cut or round differently, and anabin or the admissions office may convert by its own table; theirs decides.'];
	}

	/** Points of German law exams (0–18) with their Notenstufe, and their average. */
	public static function juraPoints(array $points): array {
		$label = fn (float $p) => match (true) {
			$p >= 14 => 'sehr gut', $p >= 11.5 => 'gut', $p >= 9 => 'vollbefriedigend', $p >= 6.5 => 'befriedigend',
			$p >= 4 => 'ausreichend', $p >= 1.5 => 'mangelhaft', default => 'ungenügend',
		};
		$list = array_map('floatval', $points);
		if ($list === []) {
			throw new \InvalidArgumentException('no points');
		}
		$avg = array_sum($list) / count($list);
		return ['each' => array_map(fn ($p) => Calculator::format($p) . ' P: ' . $label($p), $list),
			'average' => number_format(floor($avg * 100) / 100, 2, ',', '') . ' P: ' . $label($avg),
			'scale' => 'sehr gut 14–18, gut 11,5–13,99, vollbefriedigend 9–11,49, befriedigend 6,5–8,99, ausreichend 4–6,49, mangelhaft 1,5–3,99, ungenügend 0–1,49 (single grades are whole points: gut 11–13, vollbefriedigend 9–10, befriedigend 7–8, ausreichend 4–6, mangelhaft 1–3)'];
	}

	/**
	 * Workload and a plan backwards from a deadline: credits × hours per credit (30
	 * unless the PO says 25), spread over the weeks left, and phases for the kind of
	 * work (exam, term paper, thesis).
	 */
	public static function workload(float $ects, string $deadline, string $kind = 'exam', float $hoursPerEcts = 30, string $from = ''): array {
		$start = new \DateTimeImmutable($from !== '' ? $from : 'today');
		$end = new \DateTimeImmutable($deadline);
		$days = (int)$start->diff($end)->format('%r%a');
		if ($days <= 0) {
			throw new \InvalidArgumentException('the deadline must lie ahead');
		}
		$hours = $ects * $hoursPerEcts;
		$phases = match ($kind) {
			'paper', 'hausarbeit', 'term_paper' => ['topic and question, first literature' => 0.10, 'research and reading, excerpts' => 0.30, 'outline (Gliederung)' => 0.05,
				'writing the first draft' => 0.35, 'revising, citations, formatting' => 0.15, 'proofreading, printing, handing in' => 0.05],
			'thesis', 'bachelorarbeit', 'masterarbeit' => ['exposé and question' => 0.08, 'literature and state of research' => 0.20, 'method and material or experiments' => 0.25,
				'writing' => 0.30, 'revision with feedback' => 0.12, 'final checks, binding, handing in' => 0.05],
			default => ['overview: scripts, past exams, topic list' => 0.10, 'working through the topics' => 0.50, 'exercises and past exams' => 0.25, 'revision of weak topics, a mock exam' => 0.15],
		};
		$out = [];
		$at = $start;
		foreach ($phases as $name => $share) {
			$until = $at->modify('+' . max(1, (int)round($days * $share)) . ' days');
			$until = $until > $end ? $end : $until;
			$out[] = ['phase' => $name, 'from' => $at->format('Y-m-d'), 'until' => $until->format('Y-m-d'), 'hours' => (int)round($hours * $share)];
			$at = $until;
		}
		return ['workload_hours' => (int)$hours, 'days_left' => $days, 'hours_per_week' => round($hours / max(1, $days / 7), 1), 'phases' => $out,
			'note' => 'The calendar tool can take the phases as tasks (due = until). Hours per credit: 25–30 by the PO; ' . Calculator::format($hoursPerEcts) . ' taken.'];
	}

	/** Grade words of many POs (≤ 1,5 sehr gut, ≤ 2,5 gut, ≤ 3,5 befriedigend, ≤ 4,0 ausreichend). */
	private static function rating(float $g, float $pass): string {
		return match (true) {
			$g <= 1.5 => 'sehr gut', $g <= 2.5 => 'gut', $g <= 3.5 => 'befriedigend', $g <= $pass => 'ausreichend', default => 'nicht ausreichend',
		};
	}

	private static function grade(mixed $g): ?float {
		if ($g === null || $g === '') {
			return null;
		}
		$v = (float)str_replace(',', '.', (string)$g);
		return $v >= 1 && $v <= 5 ? $v : throw new \InvalidArgumentException('a grade from 1,0 to 5,0, not ' . $g);
	}

	private static function cut(float $v, int $decimals, bool $truncate): float {
		$f = 10 ** $decimals;
		return $truncate ? floor($v * $f + 1e-9) / $f : round($v, $decimals);
	}

	private static function de(float $v, int $decimals = 1): string {
		return number_format($v, $decimals, ',', '');
	}
}
