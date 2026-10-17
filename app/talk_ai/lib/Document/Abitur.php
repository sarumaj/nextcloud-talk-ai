<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * The Hessian Landesabitur 2027, from the official texts rather than memory:
 * the Abiturerlass of 2 July 2025 (subjects, Themenfelder, Pflichtlektüren,
 * dates, times, choice of tasks, Hilfsmittel; data/abitur/hessen-2027.json),
 * the operator lists of the ministry (Stand August 2025/2026; data/abitur/operators/)
 * and the OAVO in its version of September 2026 (§ 26 Gesamtqualifikation,
 * § 36 written and oral, Anlagen 9a, 9b, 9c, 10a, 10b), which are computed here.
 */
class Abitur {
	public const DIR = __DIR__ . '/../../data/abitur';

	private static ?array $data = null;

	public static function data(): array {
		return self::$data ??= json_decode((string)file_get_contents(self::DIR . '/hessen-2027.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/** The subject's key for a name as people write it ("Mathe", "PoWi", "Englisch LK"). */
	public static function subjectKey(string $name): string {
		$wanted = self::normal(preg_replace('/\b(LK|GK|Leistungskurs|Grundkurs|mündlich|schriftlich|oral|written|Präsentation\w*|presentation)\b|\(.*?\)/iu', '', $name) ?? $name);
		$subjects = self::data()['subjects'];
		foreach ($subjects as $key => $s) {
			$names = [$key, $s['name'], ...($s['aliases'] ?? [])];
			if (in_array($wanted, array_map(self::normal(...), $names), true)) {
				return $key;
			}
		}
		foreach ($subjects as $key => $s) {
			if ($wanted !== '' && str_starts_with(self::normal($s['name']), $wanted)) {
				return $key;
			}
		}
		throw new \InvalidArgumentException('no subject "' . $name . '" in the Landesabitur 2027; subjects: ' . implode(', ', array_column($subjects, 'name')));
	}

	/** "LK", "GK", or "" when the text names neither. */
	public static function level(string $text): string {
		return match (true) {
			preg_match('/\b(LK|Leistungskurs|erhöht)/iu', $text) === 1 => 'LK',
			preg_match('/\b(GK|Grundkurs|grundlegend)/iu', $text) === 1 => 'GK',
			default => '',
		};
	}

	/**
	 * A subject at a level: the exam (date, time, BE), the choice of tasks, the
	 * structure, Hilfsmittel, Pflichtlektüren and the Themenfelder with the
	 * bullets of that level (LK: also those of the erhöhtes Niveau).
	 */
	public static function subject(string $name, string $level = ''): array {
		$key = self::subjectKey($name);
		$s = self::data()['subjects'][$key];
		$level = $level !== '' ? strtoupper($level) : (self::level($name) ?: '');
		$topics = array_map(fn ($t) => ['id' => $t['id'], 'title' => $t['title'],
			'content' => $level === 'GK' ? $t['both'] : [...$t['both'], ...array_map(fn ($b) => 'LK: ' . $b, $t['lk'] ?? [])]], self::topics($s, $level));
		$texts = array_values(array_filter($s['required_texts'] ?? [], fn ($t) => $level === '' || in_array($level, $t['levels'] ?? ['GK', 'LK'], true)));
		return array_filter([
			'subject' => $s['name'] . ($level !== '' ? ' (' . $level . ')' : ''),
			'exam' => $level !== '' ? ($s['exam'][$level] ?? 'no central written exam at this level in 2027') : $s['exam'],
			'choice' => $s['choice'] ?? '',
			'structure' => $s['structure'] ?? [],
			'task_types' => $s['task_types'] ?? [],
			'aids' => $s['aids'] ?? [],
			'required_texts' => $texts,
			'topics' => $topics,
			'notes' => $s['notes'] ?? [],
			'note' => 'Abiturerlass 2027: the topics need not be exhaustive; tasks may also ask what the KCGO makes binding. "z. B." items are examples, "insbesondere" ones binding.',
		], fn ($v) => $v !== '' && $v !== []);
	}

	/**
	 * The written exams of the given subjects ("Mathematik LK", "Deutsch GK"), in
	 * order, with the times (start 9:00), the makeup dates and the key dates; the
	 * events fit the calendar tool as they are.
	 */
	public static function schedule(array $subjects): array {
		$d = self::data();
		$exams = [];
		foreach ($subjects as $entry) {
			$entry = is_array($entry) ? trim(($entry['subject'] ?? '') . ' ' . ($entry['level'] ?? '')) : (string)$entry;
			$key = self::subjectKey($entry);
			$level = self::level($entry);
			$s = $d['subjects'][$key];
			if ($level === '' || !isset($s['exam'][$level]['date'])) {
				$exams[] = ['subject' => $s['name'] . ($level !== '' ? ' ' . $level : ''),
					'note' => $level === '' ? 'say LK or GK' : 'no central written exam at this level; oral exams and Präsentationsprüfungen from ' . $d['dates']['oral_from']];
				continue;
			}
			$e = $s['exam'][$level];
			$start = new \DateTimeImmutable($e['date'] . ' ' . $d['dates']['start_time']);
			$exams[] = ['subject' => $s['name'] . ' ' . $level, 'date' => $e['date'], 'weekday' => $start->format('l'), 'start' => $start->format('H:i'),
				'end' => $start->modify('+' . $e['minutes'] . ' minutes')->format('H:i'), 'minutes' => $e['minutes'], 'makeup' => $e['makeup'] ?? null];
		}
		usort($exams, fn ($a, $b) => strcmp($a['date'] ?? '9', $b['date'] ?? '9'));
		$events = [];
		foreach ($exams as $e) {
			if (isset($e['date'])) {
				$events[] = ['title' => 'Abitur ' . $e['subject'], 'start' => $e['date'] . ' ' . $e['start'], 'end' => $e['date'] . ' ' . $e['end'],
					'description' => $e['minutes'] . ' min incl. choosing the tasks; Nachtermin ' . $e['makeup'], 'reminder_minutes' => 1440];
			}
		}
		return ['exams' => $exams, 'key_dates' => [
			'end of Q4 (last lessons)' => $d['dates']['q4_end'],
			'written exams' => implode(' to ', $d['dates']['written']),
			'first makeup dates (Nachtermin)' => implode(' to ', $d['dates']['makeup']),
			'oral exams, Präsentationsprüfungen, colloquia from' => $d['dates']['oral_from'],
			'practical exams (e.g. sport) from' => $d['dates']['practical_from'],
		], 'events' => $events, 'rules' => 'Start 9:00; phones and smartwatches are not allowed; there is no separate reading time (choosing is part of the time).'];
	}

	/** Anlage 9a OAVO: percent to points; the whole, unrounded percent counts. */
	public static function points(float $achieved, float $max = 100): array {
		if ($max <= 0 || $achieved < 0 || $achieved > $max) {
			throw new \InvalidArgumentException('achieved must lie between 0 and the maximum');
		}
		$percent = $achieved / $max * 100;
		$whole = (int)floor($percent + 1e-9);
		$points = 0;
		foreach ([20, 27, 33, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90, 95] as $i => $from) {
			if ($whole >= $from) {
				$points = $i + 1;
			}
		}
		$next = [20, 27, 33, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90, 95][$points] ?? null;
		return ['percent' => round($percent, 2), 'points' => $points, 'grade' => self::grade($points),
			'next_point_at' => $next === null ? null : $next . ' % (' . Calculator::format(ceil($next * $max / 100 * 2) / 2) . ' of ' . Calculator::format($max) . ')',
			'rule' => 'OAVO Anlage 9a: ' . implode(', ', array_map(fn ($p, $f) => $p . ' P ab ' . $f . ' %', range(1, 15), [20, 27, 33, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90, 95]))
				. '; unter 20 % 0 P. The whole percent is taken, not rounded.'];
	}

	/**
	 * Anlage 9b OAVO: the Fehlerindex (errors × 100 / words) and the deduction: from 3
	 * one point, from 6 two (the whole, unrounded index). Where the language part of
	 * the work is under half, 5 or 10 % of the BE of that part instead.
	 */
	public static function errorIndex(float $errors, int $words, ?int $points = null, ?float $languageBe = null): array {
		if ($words <= 0 || $errors < 0) {
			throw new \InvalidArgumentException('words must be positive and errors not negative');
		}
		$index = $errors * 100 / $words;
		$whole = (int)floor($index + 1e-9);
		$deduction = $whole >= 6 ? 2 : ($whole >= 3 ? 1 : 0);
		$out = ['error_index' => round($index, 2), 'deduction_points' => $deduction];
		if ($points !== null) {
			$out['points_after'] = max(0, $points - $deduction);
		}
		if ($languageBe !== null) {
			$out['if_language_part_under_half'] = ['deduction_be' => round($languageBe * ($whole >= 6 ? 0.10 : ($whole >= 3 ? 0.05 : 0)), 2),
				'rule' => '5 % (from index 3) or 10 % (from 6) of the BE of the language part'];
		}
		$out['counted'] = 'spelling (a repeated word once), punctuation (no repeats; a missing pair of commas around an insertion counts once), grammar (das/dass is grammar), lexis (wrong word, missing word); missing i dots are marked, not counted. Mistakes in subject terms count to content, not here.';
		$out['rule'] = 'OAVO Anlage 9b: Fehlerindex = errors × 100 / words; from 3: 1 point off, from 6: 2 points off.';
		return $out;
	}

	/** Anlage 9b II OAVO, Deutsch: Verstehensleistung 70 %, Darstellungsleistung 30 %, then the Fehlerindex. */
	public static function deutsch(float $understanding, float $presentation, ?float $errors = null, int $words = 0): array {
		foreach ([$understanding, $presentation] as $p) {
			if ($p < 0 || $p > 15) {
				throw new \InvalidArgumentException('points are 0 to 15');
			}
		}
		$raw = 0.7 * $understanding + 0.3 * $presentation;
		$points = (int)floor($raw + 0.5 + 1e-9);
		$caps = [];
		if ($presentation == 0) {
			$points = min($points, 3);
			$caps[] = 'Darstellungsleistung 0 points: at most 3 in all';
		}
		if ($understanding == 0) {
			$points = 0;
			$caps[] = 'Verstehensleistung 0 points: 0 in all';
		}
		$out = ['weighted' => round($raw, 2), 'points' => $points];
		if ($caps !== []) {
			$out['capped'] = $caps;
		}
		if ($errors !== null && $words > 0) {
			$index = self::errorIndex($errors, $words, $points);
			$out += ['error_index' => $index['error_index'], 'deduction' => $index['deduction_points'], 'final_points' => $index['points_after']];
			$points = $index['points_after'];
		}
		$out['grade'] = self::grade($points);
		$out['rule'] = 'OAVO Anlage 9b II: Verstehensleistung (content, subject terms) 70 %, Darstellungsleistung (expression, style, text type, quoting, materials) 30 %; rounded to whole points (.1–.4 down, .5–.9 up), small deviations in the weighting allowed; then 1 point off from Fehlerindex 3, 2 from 6.';
		return $out;
	}

	/**
	 * Anlage 9c OAVO: a Latin or Greek translation, errors per 100 words of the
	 * original text to points. Errors count half, whole, one and a half or double.
	 */
	public static function translation(float $errors, int $words): array {
		if ($words <= 0 || $errors < 0) {
			throw new \InvalidArgumentException('words must be positive and errors not negative');
		}
		$index = $errors * 100 / $words;
		$limits = [15 => 1, 14 => 2, 13 => 3, 12 => 4, 11 => 5, 10 => 6, 9 => 7, 8 => 8, 7 => 9, 6 => 10, 5 => 11, 4 => 12, 3 => 13.5, 2 => 15, 1 => 16.5];
		$points = 0;
		foreach ($limits as $p => $limit) {
			if ($index <= $limit + 1e-9) {
				$points = $p;
				break;
			}
		}
		return ['error_index' => round($index, 2), 'points' => $points, 'grade' => self::grade($points),
			'weights' => 'not counted: slips (as Anlage 9b); half: slight errors not distorting the sense (vocabulary, expression, forms, syntax, reflection); whole: errors distorting the sense, also in rendering a German sentence; one and a half: construction errors, worse reflection errors; double: severe construction and reflection errors; consequential errors are not counted again; at most one error off for especially apt renderings.',
			'rule' => 'OAVO Anlage 9c: 15 P up to index 1, 14 up to 2, … 5 up to 11, 4 up to 12, 3 up to 13.5, 2 up to 15, 1 up to 16.5, 0 above. 5 points may still be given above 11 errors per 100 words if the overall sense is understood (depending on the text\'s difficulty).'];
	}

	/** Anlage 10a OAVO (§ 36 Abs. 4): written and oral exam in one subject, counted four times. */
	public static function combined(int $written, int $oral): array {
		if ($written < 0 || $written > 15 || $oral < 0 || $oral > 15) {
			throw new \InvalidArgumentException('points are 0 to 15');
		}
		$p = intdiv((2 * $written + $oral) * 4, 3);
		return ['points_fourfold' => $p, 'of' => 60, 'single' => round($p / 4, 2),
			'rule' => 'OAVO § 36 Abs. 4, Anlage 10a: P = ((2s + m) × 4) : 3, the table taking the whole part.'];
	}

	/**
	 * The Gesamtqualifikation (§ 26 OAVO) and the Durchschnittsnote (Anlage 10b):
	 * Block I the 8 LK courses twice and 24 GK courses once (max 600), Block II the five
	 * exams four times (max 300), with the conditions for passing. An exam is its
	 * points, or {written, oral} for a subject also examined orally. With target (a
	 * grade like 1.5), what Block II needs for it.
	 */
	public static function total(array $lk = [], array $gk = [], array $exams = [], ?float $target = null): array {
		$lk = array_map('intval', $lk);
		$gk = array_map('intval', $gk);
		foreach ([...$lk, ...$gk] as $p) {
			if ($p < 0 || $p > 15) {
				throw new \InvalidArgumentException('course points are 0 to 15');
			}
		}
		$out = [];
		$problems = [];
		if ($lk !== [] || $gk !== []) {
			if (count($lk) !== 8 || count($gk) !== 24) {
				$problems[] = 'Block I takes 8 LK and 24 GK courses; given ' . count($lk) . ' and ' . count($gk) . ' (the result is for these only)';
			}
			$lkPoints = 2 * array_sum($lk);
			$gkPoints = array_sum($gk);
			$out['block_1'] = ['lk' => $lkPoints . ' / 240', 'gk' => $gkPoints . ' / 360', 'sum' => $lkPoints + $gkPoints];
			$all = [...$lk, ...$gk];
			if (in_array(0, $all, true)) {
				$problems[] = 'a course with 0 points may not be counted in';
			}
			$under = count(array_filter($all, fn ($p) => $p < 5));
			$underLk = count(array_filter($lk, fn ($p) => $p < 5));
			if ($under > 6 || $underLk > 2) {
				$problems[] = $under . ' courses under 5 points (' . $underLk . ' LK): at most 6, of them at most 2 LK';
			}
			if ($lkPoints < 80) {
				$problems[] = 'LK area under 80 points';
			}
			if ($gkPoints < 120) {
				$problems[] = 'GK area under 120 points';
			}
		}
		if ($exams !== []) {
			$block = 0;
			$singles = [];
			foreach (array_values($exams) as $i => $e) {
				$fourfold = is_array($e) ? self::combined((int)($e['written'] ?? 0), (int)($e['oral'] ?? 0))['points_fourfold'] : 4 * (int)$e;
				$block += $fourfold;
				$singles[$i] = $fourfold / 4;
			}
			$out['block_2'] = ['sum' => $block, 'of' => 300];
			if (count($exams) !== 5) {
				$problems[] = 'Block II takes the five exams; given ' . count($exams);
			}
			if ($block < 100) {
				$problems[] = 'Block II under 100 points';
			}
			$five = array_filter($singles, fn ($p) => $p >= 5);
			if (count($five) < 3 || !(isset($five[0]) || isset($five[1]))) {
				$problems[] = 'at least three exams with 5 points or more (single), one of them an LK (the first two exams)';
			}
			if (in_array(0.0, array_map('floatval', $singles), true)) {
				$problems[] = 'no exam may end with 0 points';
			}
		}
		if (isset($out['block_1'], $out['block_2'])) {
			$sum = $out['block_1']['sum'] + $out['block_2']['sum'];
			$out['total'] = $sum . ' / 900';
			$out['average_grade'] = $sum >= 300 ? self::average($sum) : 'under 300 points: no Abitur';
		}
		if ($target !== null && isset($out['block_1'])) {
			$needed = self::needed($target);
			$rest = $needed - $out['block_1']['sum'];
			$out['for_target'] = ['grade' => number_format($target, 1, ',', ''), 'total_needed' => $needed, 'block_2_needed' => max(100, $rest),
				'per_exam_single' => round(max(100, $rest) / 20, 2), 'reachable' => $rest <= 300];
		}
		$out['passed'] = $problems === [] && isset($out['total']);
		if ($problems !== []) {
			$out['problems'] = $problems;
		}
		$out['rule'] = 'OAVO § 26: Block I = 2 × the 8 LK + the 24 GK (max 600; LK ≥ 80, GK ≥ 120; no 0; at most 6 under 5, of them at most 2 LK); Block II = 4 × each of the 5 exams (max 300; ≥ 100; three exams ≥ 5 single incl. an LK; no 0); Anlage 10b: 823–900 = 1,0, then 0,1 per 18 points down to 300 = 4,0.';
		return $out;
	}

	/** Anlage 10b: the Durchschnittsnote of a total of 300 to 900. */
	public static function average(int $total): string {
		if ($total >= 823) {
			return '1,0';
		}
		$steps = min(30, (int)ceil((823 - $total) / 18));
		return number_format(1 + $steps / 10, 1, ',', '');
	}

	/** The least total for a grade (Anlage 10b). */
	public static function needed(float $grade): int {
		$steps = (int)round(($grade - 1) * 10);
		return $steps <= 0 ? 823 : ($steps >= 30 ? 300 : 823 - 18 * $steps);
	}

	/**
	 * A mock exam's frame: parts, choice, BE, time and aids of the real exam, and how
	 * the tasks and their Erwartungshorizont are to be written.
	 */
	public static function blueprint(string $subject, string $level, string $calculator = ''): array {
		$key = self::subjectKey($subject);
		$level = strtoupper($level ?: (self::level($subject) ?: 'GK'));
		$s = self::data()['subjects'][$key];
		$exam = $s['exam'][$level] ?? null;
		$out = ['subject' => $s['name'] . ' ' . $level, 'minutes' => $exam['minutes'] ?? null, 'be' => $exam['be'] ?? null,
			'choice' => $s['choice'] ?? '', 'structure' => $s['structure'] ?? [], 'task_types' => $s['task_types'] ?? [], 'aids' => $s['aids'] ?? []];
		if ($key === 'mathematik') {
			$lk = $level === 'LK';
			$out['parts'] = [
				['part' => 'Prüfungsteil 1 (hilfsmittelfrei)', 'tasks' => $lk
					? '10 tasks of 5 BE: 4 Pflicht at Niveau 1 (2 Analysis, 1 Lineare Algebra/Analytische Geometrie, 1 Stochastik) and 6 Wahl at Niveau 2 (2 per area), of which 2 are chosen: 6 × 5 = 30 BE'
					: '9 tasks of 5 BE: 3 Pflicht at Niveau 1 (one per area), 3 Wahl at Niveau 1 and 3 at Niveau 2, one of each chosen: 5 × 5 = 25 BE',
					'aids' => 'no calculator, no Formelsammlung', 'hand_in' => 'at the latest after ' . ($lk ? 110 : 100) . ' minutes'],
				['part' => 'Prüfungsteil 2 (' . ($calculator !== '' ? strtoupper($calculator) : 'WTR or CAS') . ')', 'tasks' => 'B1 or B2 Analysis (' . ($lk ? 30 : 25) . ' BE, one chosen), C Lineare Algebra/Analytische Geometrie (' . ($lk ? 20 : 15) . ' BE), D Stochastik (' . ($lk ? 20 : 15) . ' BE)',
					'aids' => 'the official mathematisch-naturwissenschaftliche Formelsammlung (no other), the introduced WTR (no graphs, no CAS) or CAS'],
			];
			$out['documenting'] = '"berechnen": a written way without the extended functions of the WTR/CAS; "bestimmen"/"ermitteln": any function, its use stated; mathematical, not calculator notation; no Stochastik tables (binomial values, LK also normal distribution, from the calculator).';
		}
		$out['writing_the_tasks'] = [
			'Formulate every task with an operator of the official list (operators tool) and keep to its meaning and Anforderungsbereich.',
			'Spread the BE over AFB I (reproduce), II (reorganise, transfer) and III (reflect, solve problems); the main weight lies on AFB II, and every task set reaches AFB III.',
			'Give each Teilaufgabe its BE; the sum is the exam\'s total.',
			'An Erwartungshorizont per Teilaufgabe: the expected points, BE and AFB, and what makes the work "ausreichend (5 Punkte)" and "gut (11 Punkte)", as the official Lösungshinweise do.',
			'Grade with the points tool (Anlage 9a) and, for language, the Fehlerindex (Anlage 9b; Deutsch 70/30 by deutsch).',
		];
		if ($exam === null) {
			$out['note'] = 'no central written exam in ' . $s['name'] . ' ' . $level . ' in 2027';
		}
		return array_filter($out, fn ($v) => $v !== null && $v !== '' && $v !== []);
	}

	/** The oral exam and the Präsentationsprüfung (§§ 22, 35–37 OAVO). */
	public static function oral(): array {
		return [
			'oral_exam' => ['minutes' => 20, 'preparation' => 'at least 20, as a rule not more than 30 minutes, notes allowed', 'form' => 'as a rule half the time a short, freely held talk, then questions; the task must not repeat one already solved in class'],
			'presentation' => ['minutes' => 30, 'task' => 'set by the examiner, as a rule on the last school day before the Easter holidays; at least four school weeks to prepare; a written plan of its course to the examiner at least a week before (not graded)',
				'criteria' => ['quality and scope of the subject information (completeness, exemplary approach, topicality, creativity)', 'structure (problem, ordered presentation, solutions, judgements, summarising end)',
					'appropriate use of media, quality of the audiovisual support', 'precision and logic', 'communicative and rhetorical skill', 'reflection on the method, the solutions and arguments'],
				'form' => 'a media-supported talk with a colloquium; may include experiments or artistic performances; may cross subjects with its weight in the chosen one'],
			'special_learning' => ['form' => 'a written work of two half-years, registered by the start of Q3, handed in on the last school day before the Easter holidays, with a colloquium of about 20 minutes'],
			'dates' => 'from ' . self::data()['dates']['oral_from'] . ' (presentations may come earlier, not before the end of Q4); results as a rule on the day',
			'combined' => 'a subject examined in writing and orally: ((2 × written + oral) × 4) : 3 (combined)',
		];
	}

	/**
	 * The official operators of a subject (definition and Anforderungsbereich), all
	 * or those matching a name ("erläutern", "analyse").
	 */
	public static function operators(string $subject, string $operator = ''): array {
		$key = self::subjectKey($subject);
		$file = self::DIR . '/operators/' . basename((string)(self::data()['subjects'][$key]['operators'] ?? '')) . '.json';
		if (!is_file($file)) {
			throw new \InvalidArgumentException('no operator list for ' . $subject);
		}
		$list = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		$ops = array_map(fn ($o) => array_filter(['names' => implode(' / ', $o['names']), 'de' => implode(' / ', $o['de'] ?? []), 'afb' => $o['afb'],
			'definition' => $o['definition'], 'subject_notes' => $o['subject_notes'] ?? null]), $list['operators']);
		if ($operator !== '') {
			$wanted = self::normal($operator);
			$ops = array_values(array_filter($ops, fn ($o) => str_contains(self::normal($o['names'] . ' ' . ($o['de'] ?? '')), $wanted)));
			if ($ops === []) {
				return ['list' => $list['title'], 'found' => 'no operator "' . $operator . '" in this list (it may still appear in tasks taken from the IQB pool, then explained there)'];
			}
		}
		return ['list' => $list['title'] . ' (Stand ' . $list['stand'] . ')', 'about' => $list['intro'] ?? '', 'operators' => $ops];
	}

	/** The catalogue of style devices for Latin (Stilmittelkatalog Latein). */
	public static function styleDevices(string $name = ''): array {
		$list = json_decode((string)file_get_contents(self::DIR . '/latein-stilmittel.json'), true, 512, JSON_THROW_ON_ERROR);
		$items = $list['items'];
		if ($name !== '') {
			$items = array_values(array_filter($items, fn ($i) => str_contains(self::normal($i['name']), self::normal($name))));
		}
		return ['list' => $list['title'] . ' (Stand ' . $list['stand'] . ')', 'items' => $items];
	}

	/**
	 * A study plan from a date to the exams: each subject's Themenfelder spread over
	 * the weeks up to two weeks before its exam, then a mock exam and revision. Oral
	 * subjects ("PoWi mündlich") aim at the start of the oral exams. One task per
	 * week, for the calendar tool.
	 */
	public static function plan(array $subjects, string $from = ''): array {
		$d = self::data();
		$start = new \DateTimeImmutable(($from !== '' ? $from : date('Y-m-d')) . ' 00:00');
		$start = $start->modify('monday this week');
		$weeks = [];
		$finals = [];
		foreach ($subjects as $entry) {
			$entry = (string)(is_array($entry) ? trim(($entry['subject'] ?? '') . ' ' . ($entry['level'] ?? '')) : $entry);
			$key = self::subjectKey($entry);
			$s = $d['subjects'][$key];
			$level = self::level($entry);
			$oral = preg_match('/mündlich|oral|präsentation|presentation|ustn/iu', $entry) === 1 || $level === '' || !isset($s['exam'][$level]['date']);
			$exam = new \DateTimeImmutable($oral ? $d['dates']['oral_from'] : $s['exam'][$level]['date']);
			$label = $s['name'] . ($oral ? ' (oral)' : ' ' . $level);
			$end = $exam->modify('-14 days');
			$count = max(1, (int)floor(($end->getTimestamp() - $start->getTimestamp()) / (7 * 86400)));
			$topics = array_map(fn ($t) => $t['id'] . ' ' . $t['title'], self::topics($s, $oral ? '' : $level));
			foreach ($topics as $i => $topic) {
				$week = $start->modify('+' . (int)floor($i * $count / max(1, count($topics))) . ' weeks')->format('Y-m-d');
				$weeks[$week][] = $label . ': ' . $topic;
			}
			$finals[] = ['subject' => $label, 'revision_from' => $end->format('Y-m-d'), 'exam' => $exam->format('Y-m-d'),
				'mock_exam' => $oral ? 'a mock oral exam (20 minutes, 20–30 minutes preparation)' : 'a full mock exam in ' . $s['exam'][$level]['minutes'] . ' minutes (blueprint)'];
		}
		ksort($weeks);
		$tasks = [];
		foreach ($weeks as $week => $items) {
			$tasks[] = ['title' => 'Abitur: ' . implode(', ', array_unique(array_map(fn ($i) => (string)strstr($i, ':', true), $items))),
				'due' =>(new \DateTimeImmutable($week))->modify('sunday this week')->format('Y-m-d'), 'description' => implode("\n", $items)];
		}
		foreach ($finals as $f) {
			$tasks[] = ['title' => 'Abitur ' . $f['subject'] . ': mock exam and revision', 'due' => $f['revision_from'], 'description' => $f['mock_exam']];
		}
		return ['weeks' => $weeks, 'final_phase' => $finals, 'tasks' => array_slice($tasks, 0, 100),
			'note' => 'Topics in the order of the Kurshalbjahre; already covered ones can go into revision. Keep the last two weeks for mock exams and gaps.'];
	}

	/** The grade words of a point value (sehr gut 15–13 … ungenügend 0). */
	public static function grade(int $points): string {
		return match (true) {
			$points >= 13 => 'sehr gut',
			$points >= 10 => 'gut',
			$points >= 7 => 'befriedigend',
			$points >= 4 => 'ausreichend',
			$points >= 1 => 'mangelhaft',
			default => 'ungenügend',
		} . ' (' . match ($points) {
			15 => '1+', 14 => '1', 13 => '1-', 12 => '2+', 11 => '2', 10 => '2-', 9 => '3+', 8 => '3', 7 => '3-',
			6 => '4+', 5 => '4', 4 => '4-', 3 => '5+', 2 => '5', 1 => '5-', default => '6',
		} . ')';
	}

	/** The Themenfelder of a subject at a level (some are only for the LK). */
	private static function topics(array $subject, string $level): array {
		return array_values(array_filter($subject['topics'] ?? [], fn ($t) => $level === '' || in_array($level, $t['levels'] ?? ['GK', 'LK'], true)));
	}

	private static function normal(string $text): string {
		$text = mb_strtolower(trim($text));
		$text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
		return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text;
	}
}
