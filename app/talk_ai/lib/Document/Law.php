<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * German law for students: deadlines by §§ 187–193 BGB (also used by § 222 ZPO,
 * § 57 VwGO, § 31 VwVfG), with weekends and the public holidays of the federal
 * state, and the address of a norm on gesetze-im-internet.de (federal law), whose
 * text the web reader fetches.
 */
class Law {
	public const STATES = ['BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV', 'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH'];

	/**
	 * The end of a period: from an event (start "event": § 187 Abs. 1, the day of the
	 * event does not count) or from the beginning of a day ("day": § 187 Abs. 2, e.g.
	 * age), in days, weeks, months or years; § 188 for its end, § 193 moving an end on a
	 * Saturday, Sunday or holiday to the next working day.
	 */
	public static function deadline(string $date, int $amount, string $unit = 'days', string $start = 'event', string $state = '', bool $workingDayRule = true): array {
		$from = new \DateTimeImmutable($date);
		$unit = match (strtolower($unit)) {
			'day', 'days', 'tag', 'tage' => 'days',
			'week', 'weeks', 'woche', 'wochen' => 'weeks',
			'month', 'months', 'monat', 'monate' => 'months',
			'year', 'years', 'jahr', 'jahre' => 'years',
			default => throw new \InvalidArgumentException('unit: days, weeks, months or years'),
		};
		if ($amount < 1) {
			throw new \InvalidArgumentException('the period must be at least 1');
		}
		$steps = [];
		if ($unit === 'days') {
			$end = $start === 'day' ? $from->modify('+' . ($amount - 1) . ' days') : $from->modify('+' . $amount . ' days');
			$steps[] = $start === 'day' ? '§ 187 Abs. 2: the first day counts' : '§ 187 Abs. 1: the day of the event does not count; § 188 Abs. 1: the period ends with its last day';
		} else {
			if ($unit === 'weeks') {
				$end = $from->modify('+' . $amount . ' weeks');
			} else {
				// The same day number in the target month; if it has none, its last day (§ 188 Abs. 3).
				$months = $unit === 'years' ? 12 * $amount : $amount;
				$first = $from->modify('first day of this month')->modify('+' . $months . ' months');
				$day = min((int)$from->format('j'), (int)$first->format('t'));
				$end = $first->setDate((int)$first->format('Y'), (int)$first->format('n'), $day);
				if ($day < (int)$from->format('j')) {
					$steps[] = '§ 188 Abs. 3: the month has no such day, so its last day';
				}
			}
			if ($start === 'day') {
				$end = $end->modify('-1 day');
				$steps[] = '§ 187 Abs. 2 with § 188 Abs. 2: it ends the day before the day of the same name or number';
			} else {
				$steps[] = '§ 188 Abs. 2: it ends with the day whose name or number matches the day of the event';
			}
		}
		$plain = $end;
		$holidays = $state !== '' ? self::holidays((int)$end->format('Y'), $state) + self::holidays((int)$end->format('Y') + 1, $state) : self::holidays((int)$end->format('Y'), '') + self::holidays((int)$end->format('Y') + 1, '');
		if ($workingDayRule) {
			while ((int)$end->format('N') >= 6 || isset($holidays[$end->format('Y-m-d')])) {
				$end = $end->modify('+1 day');
			}
			if ($end != $plain) {
				$steps[] = '§ 193: ' . $plain->format('D d.m.Y') . (isset($holidays[$plain->format('Y-m-d')]) ? ' (' . $holidays[$plain->format('Y-m-d')] . ')' : '') . ' is no working day, so the next one';
			}
		}
		return ['start' => $from->format('D d.m.Y'), 'period' => $amount . ' ' . $unit, 'ends' => $end->format('l, d.m.Y') . ', 24:00',
			'steps' => $steps, 'holidays_of' => $state !== '' ? strtoupper($state) : 'nationwide holidays only (name the state for its own)',
			'note' => '§ 193 applies to declarations and performances, not e.g. to notice periods counted backwards; statutory exceptions (e.g. § 222 Abs. 2 ZPO) as stated. A check, not legal advice.'];
	}

	/** The public holidays of a year, nationwide and of a federal state: date => name. */
	public static function holidays(int $year, string $state = ''): array {
		$state = strtoupper($state);
		if ($state !== '' && !in_array($state, self::STATES, true)) {
			throw new \InvalidArgumentException('a federal state as ' . implode(', ', self::STATES));
		}
		$easter = self::easter($year);
		$e = fn (int $d) => $easter->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d');
		$list = [
			$year . '-01-01' => 'Neujahr', $e(-2) => 'Karfreitag', $e(1) => 'Ostermontag', $year . '-05-01' => 'Tag der Arbeit',
			$e(39) => 'Christi Himmelfahrt', $e(50) => 'Pfingstmontag', $year . '-10-03' => 'Tag der Deutschen Einheit',
			$year . '-12-25' => '1. Weihnachtstag', $year . '-12-26' => '2. Weihnachtstag',
		];
		$by = [
			'Heilige Drei Könige' => [$year . '-01-06', ['BW', 'BY', 'ST']],
			'Internationaler Frauentag' => [$year . '-03-08', ['BE', 'MV']],
			'Fronleichnam' => [$e(60), ['BW', 'BY', 'HE', 'NW', 'RP', 'SL']],
			'Mariä Himmelfahrt' => [$year . '-08-15', ['SL']],
			'Weltkindertag' => [$year . '-09-20', ['TH']],
			'Reformationstag' => [$year . '-10-31', ['BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'ST', 'SH', 'TH']],
			'Allerheiligen' => [$year . '-11-01', ['BW', 'BY', 'NW', 'RP', 'SL']],
		];
		foreach ($by as $name => [$date, $states]) {
			if (in_array($state, $states, true)) {
				$list[$date] = $name;
			}
		}
		if ($state === 'SN') {
			$list[(new \DateTimeImmutable($year . '-11-23'))->modify('last wednesday')->format('Y-m-d')] = 'Buß- und Bettag';
		}
		ksort($list);
		return $list;
	}

	/** Easter Sunday (Gregorian; the anonymous algorithm of Meeus/Jones/Butcher). */
	public static function easter(int $y): \DateTimeImmutable {
		$a = $y % 19;
		$b = intdiv($y, 100);
		$c = $y % 100;
		$h = (19 * $a + $b - intdiv($b, 4) - intdiv(8 * $b + 13, 25) + 15) % 30;
		$l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
		$m = intdiv($a + 11 * $h + 22 * $l, 451);
		$month = intdiv($h + $l - 7 * $m + 114, 31);
		return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $month, ($h + $l - 7 * $m + 114) % 31 + 1));
	}

	/**
	 * The page of a federal norm on gesetze-im-internet.de: law abbreviation ("BGB",
	 * "StGB", "GG") and paragraph or article ("823", "Art. 20", "433a").
	 */
	public static function normUrl(string $law, string $norm): string {
		$code = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $law) ?? '');
		$code = ['gg' => 'gg', 'sgbv' => 'sgb_5', 'sgbviii' => 'sgb_8', 'sgbxii' => 'sgb_12', 'sgbii' => 'sgb_2', 'sgbx' => 'sgb_10'][$code] ?? $code;
		if ($code === '' || preg_match('/^(art\.?\s*)?(\d+[a-z]?)$/i', trim(preg_replace('/^§+\s*/u', '', $norm) ?? $norm), $m) !== 1) {
			throw new \InvalidArgumentException('a law like BGB and a norm like 823 or Art. 20');
		}
		$article = $m[1] !== '' || $code === 'gg';
		return 'https://www.gesetze-im-internet.de/' . $code . '/' . ($article ? 'art_' : '__') . strtolower($m[2]) . '.html';
	}
}
