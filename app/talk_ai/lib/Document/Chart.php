<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Charts and graphs as SVG (sent as PNG, rendered by Collabora): graphs of functions of x, line,
 * bar, scatter and pie charts of data, with title, axis labels, grid and legend, timelines of
 * events and periods (years; BC as negative), and mind maps.
 */
class Chart {
	private const WIDTH = 1000;
	private const HEIGHT = 620;
	private const COLORS = ['#2e75b6', '#c0504d', '#59a14f', '#f28e2b', '#8064a2', '#4bacc6', '#e15759', '#76717a'];
	private const FONT = 'DejaVu Sans, Liberation Sans, Arial, sans-serif';
	/** The plot area: left, top, right, bottom. */
	private const AREA = [90, 70, 960, 540];

	/**
	 * @param array{type?: string, title?: string, x_label?: string, y_label?: string, x_min?: float, x_max?: float,
	 *   y_min?: float, y_max?: float, functions?: list<array{expression: string, label?: string}>,
	 *   labels?: list<string>, series?: list<array{label?: string, values?: list<float>, points?: list<array{float, float}>}>} $spec
	 */
	public static function svg(array $spec): string {
		$type = in_array($spec['type'] ?? '', ['function', 'line', 'bar', 'scatter', 'pie', 'timeline', 'mindmap'], true) ? $spec['type'] : (isset($spec['functions']) ? 'function' : 'line');
		$body = match ($type) {
			'pie' => self::pie($spec),
			'timeline' => self::timeline($spec),
			'mindmap' => self::mindmap($spec),
			'bar' => self::bars($spec),
			default => self::xy($spec, $type),
		};
		$title = trim((string)($spec['title'] ?? ''));
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . self::WIDTH . '" height="' . self::HEIGHT . '" viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '" font-family="' . self::FONT . '">'
			. '<rect width="100%" height="100%" fill="#ffffff"/>'
			. ($title !== '' ? self::text(self::WIDTH / 2, 40, $title, 24, 'middle', 'bold') : '')
			. $body . '</svg>';
	}

	/** Graphs of functions, lines and scatter plots: numeric axes. */
	private static function xy(array $spec, string $type): string {
		$series = [];
		if ($type === 'function') {
			$xMin = (float)($spec['x_min'] ?? -10);
			$xMax = (float)($spec['x_max'] ?? 10);
			if ($xMax <= $xMin) {
				throw new \InvalidArgumentException('x_max must be greater than x_min');
			}
			$calculator = new Calculator((bool)($spec['degrees'] ?? false));
			foreach (array_slice($spec['functions'] ?? [], 0, 8) as $f) {
				$expression = (string)($f['expression'] ?? '');
				$expression = preg_replace('/^\s*[a-z]\s*\(\s*x\s*\)\s*=|^\s*y\s*=/i', '', $expression) ?? $expression;
				$points = [];
				for ($i = 0; $i <= 600; $i++) {
					$x = $xMin + ($xMax - $xMin) * $i / 600;
					try {
						$points[] = [$x, $calculator->value($expression, ['x' => $x])];
					} catch (\Throwable $e) {
						if ($i === 0 && preg_match('/unknown|unexpected|missing|incomplete/', $e->getMessage()) === 1) {
							throw new \InvalidArgumentException('"' . $expression . '": ' . $e->getMessage());
						}
						$points[] = null;
					}
				}
				$series[] = ['label' => (string)($f['label'] ?? ('y = ' . trim($expression))), 'points' => $points];
			}
		} else {
			$labels = $spec['labels'] ?? [];
			foreach (array_slice($spec['series'] ?? [], 0, 8) as $s) {
				$points = isset($s['points'])
					? array_map(fn ($p) => [(float)$p[0], (float)$p[1]], (array)$s['points'])
					: array_map(fn ($v, $i) => [is_numeric($labels[$i] ?? null) ? (float)$labels[$i] : (float)$i, (float)$v], (array)($s['values'] ?? []), array_keys((array)($s['values'] ?? [])));
				$series[] = ['label' => (string)($s['label'] ?? ''), 'points' => $points];
			}
		}
		$all = array_merge(...array_map(fn ($s) => array_filter($s['points']), $series ?: [['points' => []]]));
		if ($all === []) {
			throw new \InvalidArgumentException('nothing to draw');
		}
		$xs = array_column($all, 0);
		$ys = array_filter(array_column($all, 1), fn ($y) => is_finite($y));
		$xMin = (float)($spec['x_min'] ?? min($xs));
		$xMax = (float)($spec['x_max'] ?? max($xs));
		[$yMin, $yMax] = isset($spec['y_min'], $spec['y_max']) ? [(float)$spec['y_min'], (float)$spec['y_max']] : self::yRange($ys, $type === 'function');
		$yMin = (float)($spec['y_min'] ?? $yMin);
		$yMax = (float)($spec['y_max'] ?? $yMax);
		if ($xMax <= $xMin) {
			[$xMin, $xMax] = [$xMin - 1, $xMax + 1];
		}
		if ($yMax <= $yMin) {
			[$yMin, $yMax] = [$yMin - 1, $yMax + 1];
		}
		[$l, $t, $r, $b] = self::AREA;
		$px = fn (float $x): float => $l + ($x - $xMin) / ($xMax - $xMin) * ($r - $l);
		$py = fn (float $y): float => $b - ($y - $yMin) / ($yMax - $yMin) * ($b - $t);
		$labels = $type !== 'function' && !isset($spec['series'][0]['points']) ? ($spec['labels'] ?? []) : [];
		$out = self::grid($xMin, $xMax, $yMin, $yMax, $px, $py, $labels);
		$out .= '<clipPath id="area"><rect x="' . $l . '" y="' . $t . '" width="' . ($r - $l) . '" height="' . ($b - $t) . '"/></clipPath><g clip-path="url(#area)">';
		foreach ($series as $n => $s) {
			$color = self::COLORS[$n % count(self::COLORS)];
			if ($type === 'scatter') {
				foreach ($s['points'] as $p) {
					$out .= '<circle cx="' . round($px($p[0]), 1) . '" cy="' . round($py($p[1]), 1) . '" r="5" fill="' . $color . '" fill-opacity="0.8"/>';
				}
				continue;
			}
			// A line, broken where the function is undefined or jumps (tan x at π/2).
			$path = '';
			$previous = null;
			$jump = ($yMax - $yMin) * 3;
			foreach ($s['points'] as $p) {
				if ($p === null || !is_finite($p[1])) {
					$previous = null;
					continue;
				}
				$move = $previous === null || ($type === 'function' && abs($p[1] - $previous[1]) > $jump);
				$path .= ($move ? 'M' : 'L') . round($px($p[0]), 1) . ' ' . round($py(max(min($p[1], $yMax + $jump), $yMin - $jump)), 1) . ' ';
				$previous = $p;
			}
			$out .= '<path d="' . trim($path) . '" fill="none" stroke="' . $color . '" stroke-width="3" stroke-linejoin="round"/>';
			if ($type === 'line' && count($s['points']) <= 40) {
				foreach ($s['points'] as $p) {
					$out .= '<circle cx="' . round($px($p[0]), 1) . '" cy="' . round($py($p[1]), 1) . '" r="4" fill="' . $color . '"/>';
				}
			}
		}
		$out .= '</g>';
		$out .= self::axisLabels((string)($spec['x_label'] ?? ($type === 'function' ? 'x' : '')), (string)($spec['y_label'] ?? ($type === 'function' ? 'y' : '')));
		return $out . self::legend(array_column($series, 'label'));
	}

	/** The y range of the data with a margin; for graphs without the far outliers (poles). */
	private static function yRange(array $ys, bool $robust): array {
		sort($ys);
		if ($robust && count($ys) > 20) {
			$cut = (int)(count($ys) * 0.03);
			$ys = array_slice($ys, $cut, count($ys) - 2 * $cut);
		}
		$min = (float)min($ys);
		$max = (float)max($ys);
		$pad = ($max - $min) * 0.08 ?: 1;
		return [$min >= 0 && $min - $pad < 0 && !$robust ? 0 : $min - $pad, $max + $pad];
	}

	private static function grid(float $xMin, float $xMax, float $yMin, float $yMax, callable $px, callable $py, array $labels): string {
		[$l, $t, $r, $b] = self::AREA;
		$out = '<rect x="' . $l . '" y="' . $t . '" width="' . ($r - $l) . '" height="' . ($b - $t) . '" fill="none" stroke="#bbbbbb"/>';
		foreach (self::ticks($yMin, $yMax) as $y) {
			$out .= '<line x1="' . $l . '" x2="' . $r . '" y1="' . round($py($y), 1) . '" y2="' . round($py($y), 1) . '" stroke="' . (abs($y) < 1e-12 ? '#555555' : '#e5e5e5') . '" stroke-width="' . (abs($y) < 1e-12 ? 1.5 : 1) . '"/>'
				. self::text($l - 10, $py($y) + 5, self::number($y), 15, 'end');
		}
		if ($labels !== []) {
			$step = max(1, (int)ceil(count($labels) / 14));
			foreach ($labels as $i => $label) {
				if ($i % $step === 0) {
					$out .= self::text($px((float)$i), $b + 24, (string)$label, 15, 'middle');
				}
			}
			return $out;
		}
		foreach (self::ticks($xMin, $xMax) as $x) {
			$out .= '<line y1="' . $t . '" y2="' . $b . '" x1="' . round($px($x), 1) . '" x2="' . round($px($x), 1) . '" stroke="' . (abs($x) < 1e-12 ? '#555555' : '#e5e5e5') . '" stroke-width="' . (abs($x) < 1e-12 ? 1.5 : 1) . '"/>'
				. self::text($px($x), $b + 24, self::number($x), 15, 'middle');
		}
		return $out;
	}

	private static function bars(array $spec): string {
		$labels = array_map('strval', $spec['labels'] ?? []);
		$series = array_slice($spec['series'] ?? [], 0, 8);
		$count = max(count($labels), ...array_map(fn ($s) => count((array)($s['values'] ?? [])), $series ?: [['values' => []]]));
		if ($count === 0) {
			throw new \InvalidArgumentException('nothing to draw');
		}
		$values = array_merge(...array_map(fn ($s) => array_map('floatval', (array)($s['values'] ?? [])), $series));
		$yMin = (float)($spec['y_min'] ?? min(0, ...$values));
		$yMax = (float)($spec['y_max'] ?? max(0, ...$values) * 1.08 ?: 1);
		[$l, $t, $r, $b] = self::AREA;
		$slot = ($r - $l) / $count;
		$py = fn (float $y): float => $b - ($y - $yMin) / (($yMax - $yMin) ?: 1) * ($b - $t);
		$out = '<rect x="' . $l . '" y="' . $t . '" width="' . ($r - $l) . '" height="' . ($b - $t) . '" fill="none" stroke="#bbbbbb"/>';
		foreach (self::ticks($yMin, $yMax) as $y) {
			$out .= '<line x1="' . $l . '" x2="' . $r . '" y1="' . round($py($y), 1) . '" y2="' . round($py($y), 1) . '" stroke="#e5e5e5"/>' . self::text($l - 10, $py($y) + 5, self::number($y), 15, 'end');
		}
		$width = $slot * 0.8 / max(1, count($series));
		foreach ($series as $n => $s) {
			foreach (array_values((array)($s['values'] ?? [])) as $i => $v) {
				$x = $l + $slot * $i + $slot * 0.1 + $width * $n;
				$y0 = $py(max($yMin, 0));
				$y1 = $py((float)$v);
				$out .= '<rect x="' . round($x, 1) . '" y="' . round(min($y0, $y1), 1) . '" width="' . round($width - 2, 1) . '" height="' . round(abs($y1 - $y0), 1) . '" fill="' . self::COLORS[$n % count(self::COLORS)] . '"/>';
				if ($count * count($series) <= 24) {
					$out .= self::text($x + $width / 2 - 1, min($y0, $y1) - 6, self::number((float)$v), 13, 'middle');
				}
			}
		}
		$step = max(1, (int)ceil($count / 14));
		foreach ($labels as $i => $label) {
			if ($i % $step === 0) {
				$out .= self::text($l + $slot * ($i + 0.5), $b + 24, mb_strimwidth($label, 0, (int)max(6, $slot * $step / 9), '…'), 15, 'middle');
			}
		}
		$out .= self::axisLabels((string)($spec['x_label'] ?? ''), (string)($spec['y_label'] ?? ''));
		return $out . (count($series) > 1 ? self::legend(array_map(fn ($s) => (string)($s['label'] ?? ''), $series)) : '');
	}

	private static function pie(array $spec): string {
		$values = array_map('floatval', (array)($spec['series'][0]['values'] ?? []));
		$labels = array_map('strval', $spec['labels'] ?? []);
		$total = array_sum(array_map('abs', $values));
		if ($total <= 0) {
			throw new \InvalidArgumentException('nothing to draw');
		}
		[$cx, $cy, $radius] = [360, 330, 230];
		$out = '';
		$angle = -M_PI / 2;
		foreach ($values as $i => $v) {
			$sweep = abs($v) / $total * 2 * M_PI;
			$color = self::COLORS[$i % count(self::COLORS)];
			if ($sweep >= 2 * M_PI - 1e-9) {
				$out .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $radius . '" fill="' . $color . '"/>';
			} else {
				$x1 = $cx + $radius * cos($angle);
				$y1 = $cy + $radius * sin($angle);
				$x2 = $cx + $radius * cos($angle + $sweep);
				$y2 = $cy + $radius * sin($angle + $sweep);
				$out .= '<path d="M' . $cx . ' ' . $cy . ' L' . round($x1, 1) . ' ' . round($y1, 1) . ' A' . $radius . ' ' . $radius . ' 0 ' . ($sweep > M_PI ? 1 : 0) . ' 1 ' . round($x2, 1) . ' ' . round($y2, 1) . ' Z" fill="' . $color . '" stroke="#ffffff" stroke-width="2"/>';
			}
			if ($sweep > 0.25) {
				$mid = $angle + $sweep / 2;
				$out .= self::text($cx + $radius * 0.65 * cos($mid), $cy + $radius * 0.65 * sin($mid) + 6, round(abs($v) / $total * 100) . ' %', 18, 'middle', 'bold', '#ffffff');
			}
			$angle += $sweep;
		}
		foreach ($values as $i => $v) {
			$y = 150 + $i * 34;
			$out .= '<rect x="650" y="' . ($y - 14) . '" width="18" height="18" fill="' . self::COLORS[$i % count(self::COLORS)] . '"/>'
				. self::text(678, $y, mb_strimwidth(($labels[$i] ?? '') . ' (' . self::number($v) . ')', 0, 34, '…'), 17, 'start');
		}
		return $out;
	}

	/**
	 * Events (a year or a date and a label) along a line, alternately above and
	 * below, and periods (from, to, label) as bars beneath.
	 */
	private static function timeline(array $spec): string {
		$events = [];
		foreach ((array)($spec['events'] ?? []) as $e) {
			$events[] = [self::year($e['date'] ?? $e['year'] ?? 0), (string)($e['label'] ?? ''), (string)($e['date'] ?? $e['year'] ?? '')];
		}
		$periods = [];
		foreach ((array)($spec['periods'] ?? []) as $p) {
			$periods[] = [self::year($p['from'] ?? 0), self::year($p['to'] ?? 0), (string)($p['label'] ?? '')];
		}
		$all = array_merge(array_column($events, 0), array_column($periods, 0), array_column($periods, 1));
		if ($all === []) {
			throw new \InvalidArgumentException('nothing to draw: give events or periods');
		}
		usort($events, fn ($a, $b) => $a[0] <=> $b[0]);
		$pad = (max($all) - min($all)) * 0.05 ?: 1;
		[$min, $max] = [min($all) - $pad, max($all) + $pad];
		[$l, , $r] = self::AREA;
		$px = fn (float $y): float => $l + ($y - $min) / ($max - $min) * ($r - $l);
		$axis = $periods === [] ? 330 : 280;
		$out = '<line x1="' . $l . '" x2="' . $r . '" y1="' . $axis . '" y2="' . $axis . '" stroke="#555555" stroke-width="3"/>';
		foreach (self::ticks($min, $max) as $t) {
			$out .= '<line x1="' . round($px($t), 1) . '" x2="' . round($px($t), 1) . '" y1="' . ($axis - 6) . '" y2="' . ($axis + 6) . '" stroke="#555555"/>'
				. self::text($px($t), $axis + 24, $t < 0 ? self::number(-$t) . ' BC' : self::number($t), 13, 'middle', 'normal', '#777777');
		}
		foreach ($events as $i => [$year, $label, $shown]) {
			$x = round($px($year), 1);
			$up = $i % 2 === 0;
			$level = intdiv($i, 2) % 3;
			$y = $up ? $axis - 50 - $level * 55 : $axis + 70 + $level * 55;
			$color = self::COLORS[$i % count(self::COLORS)];
			$out .= '<line x1="' . $x . '" x2="' . $x . '" y1="' . $axis . '" y2="' . ($up ? $y + 6 : $y - 18) . '" stroke="' . $color . '" stroke-width="1.5"/>'
				. '<circle cx="' . $x . '" cy="' . $axis . '" r="6" fill="' . $color . '"/>'
				. self::text($x, $up ? $y - 14 : $y, $shown, 13, 'middle', 'bold', $color)
				. self::text($x, $up ? $y + 2 : $y + 16, mb_strimwidth($label, 0, 30, '…'), 14, 'middle');
		}
		foreach ($periods as $i => [$from, $to, $label]) {
			$y = 500 + ($i % 4) * 28;
			$x1 = $px(min($from, $to));
			$x2 = $px(max($from, $to));
			$out .= '<rect x="' . round($x1, 1) . '" y="' . $y . '" width="' . round(max(4, $x2 - $x1), 1) . '" height="22" rx="4" fill="' . self::COLORS[($i + 3) % count(self::COLORS)] . '"/>'
				. self::text($x1 + 6, $y + 16, mb_strimwidth($label, 0, (int)max(4, ($x2 - $x1) / 7.5), '…'), 13, 'start', 'bold', '#ffffff');
		}
		return $out;
	}

	/** A year from "1789", "-44", "44 BC", "1789-07-14" or "14.07.1789" (a date as a fraction of its year). */
	private static function year(mixed $date): float {
		$d = trim((string)$date);
		if (preg_match('/^(\d+)\s*(BC|BCE|v\.?\s?Chr\.?|p\.n\.e\.)$/i', $d, $m) === 1) {
			return -(float)$m[1];
		}
		if (preg_match('/^(-?\d{1,4})-(\d{1,2})(?:-(\d{1,2}))?$/', $d, $m) === 1) {
			return (float)$m[1] + ((int)$m[2] - 1) / 12 + ((int)($m[3] ?? 1) - 1) / 365;
		}
		if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(-?\d{1,4})$/', $d, $m) === 1) {
			return (float)$m[3] + ((int)$m[2] - 1) / 12 + ((int)$m[1] - 1) / 365;
		}
		if (is_numeric($d)) {
			return (float)$d;
		}
		throw new \InvalidArgumentException('a date as a year (1789, -44 or 44 BC), 1789-07-14 or 14.07.1789: ' . $d);
	}

	/**
	 * A mind map: the topic in the middle, its branches around it (up to eight,
	 * each with up to six points), each branch in its own colour.
	 */
	private static function mindmap(array $spec): string {
		$root = (string)($spec['root'] ?? $spec['title'] ?? '');
		$branches = array_slice((array)($spec['branches'] ?? []), 0, 8);
		if ($branches === []) {
			throw new \InvalidArgumentException('a mind map needs branches: [{label, children: [...]}]');
		}
		[$cx, $cy] = [self::WIDTH / 2, self::HEIGHT / 2 + 20];
		$out = '';
		$n = count($branches);
		foreach ($branches as $i => $branch) {
			$label = is_array($branch) ? (string)($branch['label'] ?? '') : (string)$branch;
			$children = is_array($branch) ? array_slice(array_map('strval', (array)($branch['children'] ?? [])), 0, 6) : [];
			$angle = -M_PI / 2 + 2 * M_PI * $i / $n;
			$color = self::COLORS[$i % count(self::COLORS)];
			[$bx, $by] = [$cx + 250 * cos($angle), $cy + 165 * sin($angle)];
			$out .= '<path d="M' . $cx . ' ' . $cy . ' Q' . round(($cx + $bx) / 2, 1) . ' ' . round($cy + ($by - $cy) * 0.15, 1) . ' ' . round($bx, 1) . ' ' . round($by, 1) . '" fill="none" stroke="' . $color . '" stroke-width="5" stroke-linecap="round"/>';
			$right = cos($angle) >= -0.01;
			foreach ($children as $k => $child) {
				$spread = count($children) > 1 ? ($k / (count($children) - 1) - 0.5) : 0;
				$x = $bx + ($right ? 120 : -120);
				$y = $by + $spread * min(170, 34 * count($children)) + (abs(cos($angle)) < 0.3 ? (sin($angle) > 0 ? 40 : -40) : 0);
				$out .= '<path d="M' . round($bx, 1) . ' ' . round($by, 1) . ' C' . round(($bx + $x) / 2, 1) . ' ' . round($by, 1) . ' ' . round(($bx + $x) / 2, 1) . ' ' . round($y, 1) . ' ' . round($x, 1) . ' ' . round($y, 1) . '" fill="none" stroke="' . $color . '" stroke-width="2"/>'
					. self::text($x + ($right ? 6 : -6), $y + 5, mb_strimwidth($child, 0, 26, '…'), 14, $right ? 'start' : 'end', 'normal', '#333333');
			}
			$width = 24 + 9.5 * mb_strlen(mb_strimwidth($label, 0, 22, '…'));
			$out .= '<rect x="' . round($bx - $width / 2, 1) . '" y="' . round($by - 17, 1) . '" width="' . round($width, 1) . '" height="34" rx="17" fill="' . $color . '"/>'
				. self::text($bx, $by + 6, mb_strimwidth($label, 0, 22, '…'), 16, 'middle', 'bold', '#ffffff');
		}
		$width = 40 + 12 * mb_strlen(mb_strimwidth($root, 0, 24, '…'));
		return $out . '<ellipse cx="' . $cx . '" cy="' . $cy . '" rx="' . round($width / 2, 1) . '" ry="34" fill="#1f3864"/>'
			. self::text($cx, $cy + 7, mb_strimwidth($root, 0, 24, '…'), 20, 'middle', 'bold', '#ffffff');
	}

	private static function axisLabels(string $x, string $y): string {
		[$l, $t, $r, $b] = self::AREA;
		return ($x !== '' ? self::text(($l + $r) / 2, $b + 56, $x, 17, 'middle') : '')
			. ($y !== '' ? '<text x="28" y="' . (($t + $b) / 2) . '" font-size="17" text-anchor="middle" fill="#333333" transform="rotate(-90 28 ' . (($t + $b) / 2) . ')">' . self::xml($y) . '</text>' : '');
	}

	private static function legend(array $labels): string {
		$labels = array_filter($labels, fn ($label) => $label !== '');
		if ($labels === []) {
			return '';
		}
		[, $t, $r] = self::AREA;
		$width = 48 + 9 * max(array_map('mb_strlen', $labels));
		$out = '<rect x="' . ($r - $width - 10) . '" y="' . ($t + 10) . '" width="' . $width . '" height="' . (count($labels) * 26 + 12) . '" fill="#ffffff" fill-opacity="0.9" stroke="#cccccc"/>';
		foreach (array_values($labels) as $i => $label) {
			$y = $t + 32 + $i * 26;
			$out .= '<line x1="' . ($r - $width) . '" x2="' . ($r - $width + 20) . '" y1="' . ($y - 5) . '" y2="' . ($y - 5) . '" stroke="' . self::COLORS[$i % count(self::COLORS)] . '" stroke-width="4"/>'
				. self::text($r - $width + 28, $y, $label, 15, 'start');
		}
		return $out;
	}

	/** About eight round steps between the bounds. */
	public static function ticks(float $min, float $max): array {
		$raw = ($max - $min) / 8;
		$magnitude = 10 ** floor(log10($raw));
		$step = $magnitude * (($raw / $magnitude) < 1.5 ? 1 : (($raw / $magnitude) < 3.5 ? 2 : (($raw / $magnitude) < 7.5 ? 5 : 10)));
		$ticks = [];
		// Each from its index: adding steps up would add up float noise (0.6000000000000001).
		for ($i = (int)ceil($min / $step - 1e-9); $i * $step <= $max + $step * 1e-9; $i++) {
			$ticks[] = round($i * $step, 10) + 0.0;
		}
		return $ticks;
	}

	private static function number(float $v): string {
		return abs($v) >= 1e6 || (abs($v) < 1e-3 && $v != 0.0) ? sprintf('%.2g', $v) : rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
	}

	private static function text(float $x, float $y, string $text, int $size, string $anchor, string $weight = 'normal', string $color = '#333333'): string {
		return '<text x="' . round($x, 1) . '" y="' . round($y, 1) . '" font-size="' . $size . '" text-anchor="' . $anchor . '" font-weight="' . $weight . '" fill="' . $color . '">' . self::xml($text) . '</text>';
	}

	private static function xml(string $text): string {
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
