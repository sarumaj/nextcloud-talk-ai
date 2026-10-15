<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Linear algebra and analytic geometry, exact with fractions: linear systems
 * (Gaussian elimination, also with infinitely many or no solutions), the
 * determinant, inverse, rank and product of matrices, and vectors (length, dot
 * and cross product, angle), lines and planes (intersection, distance, angle).
 * Numbers may be fractions ("1/3") or decimals.
 */
class LinearAlgebra {
	/** Solves A·x = b; "rows" as [[a11, a12, …, b1], …] (the augmented matrix). */
	public static function solve(array $augmented, array $names = []): array {
		$m = self::matrix($augmented);
		$columns = count($m[0]) - 1;
		$names = count($names) === $columns ? array_values(array_map('strval', $names))
			: ($columns <= 4 ? array_slice(['x', 'y', 'z', 'w'], 0, $columns) : array_map(fn ($i) => 'x' . ($i + 1), range(0, $columns - 1)));
		[$r, $pivots, $steps] = self::eliminate($m, $columns);
		foreach ($r as $row) {
			if (self::allZero(array_slice($row, 0, $columns)) && !self::isZero($row[$columns])) {
				return ['solution' => 'none (the system is inconsistent)', 'rank' => count($pivots), 'echelon_form' => self::show($r), 'steps' => $steps];
			}
		}
		$free = array_values(array_diff(range(0, $columns - 1), array_keys($pivots)));
		$solution = [];
		foreach ($pivots as $c => $row) {
			// x = constant − (coefficient · free variable) for each free variable.
			$terms = self::isZero($r[$row][$columns]) ? [] : [self::str($r[$row][$columns])];
			foreach ($free as $f) {
				if (!self::isZero($r[$row][$f])) {
					$coefficient = self::neg($r[$row][$f]);
					$magnitude = self::abs($coefficient);
					$term = ($magnitude === [1, 1] ? '' : self::str($magnitude) . '·') . $names[$f];
					$terms[] = ($coefficient[0] < 0 ? '− ' : ($terms === [] ? '' : '+ ')) . $term;
				}
			}
			$solution[$names[$c]] = $terms === [] ? '0' : implode(' ', $terms);
		}
		foreach ($free as $f) {
			$solution[$names[$f]] = $names[$f] . ' (free)';
		}
		ksort($solution);
		return ['solution' => $free === [] ? $solution : ['infinitely many' => $solution], 'rank' => count($pivots), 'echelon_form' => self::show($r), 'steps' => $steps];
	}

	public static function determinant(array $rows): array {
		$m = self::matrix($rows);
		$n = count($m);
		if ($n !== count($m[0])) {
			throw new \InvalidArgumentException('the determinant needs a square matrix');
		}
		$det = [1, 1];
		for ($c = 0; $c < $n; $c++) {
			$p = null;
			for ($i = $c; $i < $n; $i++) {
				if (!self::isZero($m[$i][$c])) {
					$p = $i;
					break;
				}
			}
			if ($p === null) {
				return ['determinant' => '0'];
			}
			if ($p !== $c) {
				[$m[$c], $m[$p]] = [$m[$p], $m[$c]];
				$det = self::neg($det);
			}
			$det = self::mul($det, $m[$c][$c]);
			for ($i = $c + 1; $i < $n; $i++) {
				$f = self::div($m[$i][$c], $m[$c][$c]);
				for ($k = $c; $k < $n; $k++) {
					$m[$i][$k] = self::sub($m[$i][$k], self::mul($f, $m[$c][$k]));
				}
			}
		}
		return ['determinant' => self::str($det)];
	}

	public static function inverse(array $rows): array {
		$m = self::matrix($rows);
		$n = count($m);
		if ($n !== count($m[0])) {
			throw new \InvalidArgumentException('only a square matrix has an inverse');
		}
		foreach ($m as $i => $row) {
			for ($k = 0; $k < $n; $k++) {
				$m[$i][] = [$i === $k ? 1 : 0, 1];
			}
		}
		[$r, $pivots] = self::eliminate($m, $n);
		if (count($pivots) < $n) {
			return ['inverse' => 'none (the matrix is singular, determinant 0)'];
		}
		return ['inverse' => self::show(array_map(fn ($row) => array_slice($row, $n), $r))];
	}

	public static function multiply(array $a, array $b): array {
		$a = self::matrix($a);
		$b = self::matrix($b);
		if (count($a[0]) !== count($b)) {
			throw new \InvalidArgumentException('A has ' . count($a[0]) . ' columns but B has ' . count($b) . ' rows');
		}
		$out = [];
		foreach ($a as $i => $row) {
			for ($j = 0; $j < count($b[0]); $j++) {
				$sum = [0, 1];
				foreach ($row as $k => $v) {
					$sum = self::add($sum, self::mul($v, $b[$k][$j]));
				}
				$out[$i][$j] = $sum;
			}
		}
		return ['product' => self::show($out)];
	}

	/** Vectors u (and v): lengths, dot and cross product, angle, and whether they are parallel or orthogonal. */
	public static function vectors(array $u, array $v = []): array {
		$u = array_map([self::class, 'num'], $u);
		$length = fn (array $x) => sqrt(array_sum(array_map(fn ($c) => self::float($c) ** 2, $x)));
		$out = ['|u|' => self::approx($length($u))];
		if ($v === []) {
			return $out;
		}
		$v = array_map([self::class, 'num'], $v);
		if (count($u) !== count($v)) {
			throw new \InvalidArgumentException('the vectors have different dimensions');
		}
		$dot = [0, 1];
		foreach ($u as $i => $c) {
			$dot = self::add($dot, self::mul($c, $v[$i]));
		}
		$out['|v|'] = self::approx($length($v));
		$out['u·v'] = self::str($dot);
		$lu = $length($u);
		$lv = $length($v);
		if ($lu > 0 && $lv > 0) {
			$cos = max(-1, min(1, self::float($dot) / ($lu * $lv)));
			$out['angle'] = self::approx(rad2deg(acos($cos))) . '°';
		}
		if (count($u) === 3) {
			$cross = [self::sub(self::mul($u[1], $v[2]), self::mul($u[2], $v[1])), self::sub(self::mul($u[2], $v[0]), self::mul($u[0], $v[2])), self::sub(self::mul($u[0], $v[1]), self::mul($u[1], $v[0]))];
			$out['u×v'] = '(' . implode(', ', array_map([self::class, 'str'], $cross)) . ')';
			$out['area_of_parallelogram'] = self::approx($length($cross));
			$out['parallel'] = self::allZero($cross);
		}
		$out['orthogonal'] = self::isZero($dot);
		return $out;
	}

	/**
	 * Lines (point and direction) and planes (point and normal, or a·x + b·y + c·z = d)
	 * in space: their position to each other, intersection, distance and angle.
	 */
	public static function geometry(array $a, array $b): array {
		$a = self::object($a);
		$b = self::object($b);
		if ($a['kind'] === 'plane' && $b['kind'] === 'line') {
			[$a, $b] = [$b, $a];
		}
		$f = fn (array $x) => array_map([self::class, 'float'], $x);
		$dot = fn (array $x, array $y) => array_sum(array_map(fn ($p, $q) => $p * $q, $x, $y));
		$cross = fn (array $x, array $y) => [$x[1] * $y[2] - $x[2] * $y[1], $x[2] * $y[0] - $x[0] * $y[2], $x[0] * $y[1] - $x[1] * $y[0]];
		$norm = fn (array $x) => sqrt($dot($x, $x));
		$point = fn (array $x) => '(' . implode(', ', array_map([self::class, 'approx'], $x)) . ')';
		if ($a['kind'] === 'point' && $b['kind'] === 'point') {
			return ['distance' => self::approx($norm(array_map(fn ($p, $q) => $p - $q, $f($a['p']), $f($b['p']))))];
		}
		if ($a['kind'] === 'point' || $b['kind'] === 'point') {
			[$p, $o] = $a['kind'] === 'point' ? [$a, $b] : [$b, $a];
			$pp = $f($p['p']);
			if ($o['kind'] === 'plane') {
				$n = $f($o['n']);
				return ['distance' => self::approx(abs($dot($n, $pp) - self::float($o['d'])) / $norm($n)), 'on_the_plane' => abs($dot($n, $pp) - self::float($o['d'])) < 1e-12];
			}
			$d = $f($o['d']);
			$ap = array_map(fn ($x, $y) => $x - $y, $pp, $f($o['p']));
			return ['distance' => self::approx($norm($cross($ap, $d)) / $norm($d))];
		}
		if ($a['kind'] === 'line' && $b['kind'] === 'line') {
			[$p1, $d1, $p2, $d2] = [$f($a['p']), $f($a['d']), $f($b['p']), $f($b['d'])];
			$n = $cross($d1, $d2);
			$w = array_map(fn ($x, $y) => $x - $y, $p2, $p1);
			if ($norm($n) < 1e-12) {
				$distance = $norm($cross($w, $d1)) / $norm($d1);
				return ['position' => $distance < 1e-12 ? 'identical' : 'parallel', 'distance' => self::approx($distance)];
			}
			$distance = abs($dot($w, $n)) / $norm($n);
			$angle = rad2deg(acos(min(1, abs($dot($d1, $d2)) / ($norm($d1) * $norm($d2)))));
			if ($distance > 1e-9) {
				return ['position' => 'skew', 'distance' => self::approx($distance), 'angle' => self::approx($angle) . '°'];
			}
			// Intersecting: p1 + s·d1 = p2 + t·d2, solved with the two best-conditioned rows.
			$s = $dot($cross($w, $d2), $n) / $dot($n, $n);
			return ['position' => 'intersecting', 'intersection' => $point(array_map(fn ($p, $d) => $p + $s * $d, $p1, $d1)), 'angle' => self::approx($angle) . '°'];
		}
		if ($a['kind'] === 'line') {
			[$p, $d, $n, $c] = [$f($a['p']), $f($a['d']), $f($b['n']), self::float($b['d'])];
			$nd = $dot($n, $d);
			if (abs($nd) < 1e-12) {
				$distance = abs($dot($n, $p) - $c) / $norm($n);
				return ['position' => $distance < 1e-12 ? 'the line lies in the plane' : 'parallel', 'distance' => self::approx($distance)];
			}
			$t = ($c - $dot($n, $p)) / $nd;
			return ['position' => 'intersecting', 'intersection' => $point(array_map(fn ($x, $y) => $x + $t * $y, $p, $d)), 'parameter' => self::approx($t),
				'angle' => self::approx(rad2deg(asin(min(1, abs($nd) / ($norm($n) * $norm($d)))))) . '°'];
		}
		[$n1, $c1, $n2, $c2] = [$f($a['n']), self::float($a['d']), $f($b['n']), self::float($b['d'])];
		$d = $cross($n1, $n2);
		if ($norm($d) < 1e-12) {
			$distance = abs($c2 / $norm($n2) * ($dot($n1, $n2) < 0 ? -1 : 1) - $c1 / $norm($n1));
			return ['position' => $distance < 1e-12 ? 'identical' : 'parallel', 'distance' => self::approx($distance)];
		}
		// A point on both: the one nearest the origin, from two equations and d·x = 0.
		$m = [[...$n1, $c1], [...$n2, $c2], [...$d, 0]];
		$x = self::solveFloat($m);
		return ['position' => 'intersecting in a line', 'line' => 'x = ' . $point($x) . ' + t·' . $point($d),
			'angle' => self::approx(rad2deg(acos(min(1, abs($dot($n1, $n2)) / ($norm($n1) * $norm($n2)))))) . '°'];
	}

	/** {point: [..]}, {point, direction} or {normal, d} / {point, normal} / {equation: "2x - y + z = 4"}. */
	private static function object(array $o): array {
		if (isset($o['equation'])) {
			if (preg_match('/^(.*)=(.*)$/', str_replace([' ', '−'], ['', '-'], (string)$o['equation']), $m) !== 1) {
				throw new \InvalidArgumentException('a plane as "a x + b y + c z = d"');
			}
			$n = [];
			foreach (['x', 'y', 'z'] as $axis) {
				$n[] = preg_match('/([+-]?[\d.\/]*)\*?' . $axis . '/', $m[1], $c) === 1 ? self::num(in_array($c[1], ['', '+', '-'], true) ? $c[1] . '1' : $c[1]) : [0, 1];
			}
			return ['kind' => 'plane', 'n' => $n, 'd' => self::num($m[2])];
		}
		if (isset($o['normal'])) {
			$n = array_map([self::class, 'num'], (array)$o['normal']);
			$d = isset($o['d']) ? self::num($o['d']) : array_reduce(array_keys($n), fn ($s, $i) => self::add($s, self::mul($n[$i], self::num($o['point'][$i] ?? 0))), [0, 1]);
			return ['kind' => 'plane', 'n' => $n, 'd' => $d];
		}
		if (isset($o['direction'])) {
			return ['kind' => 'line', 'p' => array_map([self::class, 'num'], (array)($o['point'] ?? [0, 0, 0])), 'd' => array_map([self::class, 'num'], (array)$o['direction'])];
		}
		if (isset($o['point'])) {
			return ['kind' => 'point', 'p' => array_map([self::class, 'num'], (array)$o['point'])];
		}
		throw new \InvalidArgumentException('give a point {point}, a line {point, direction} or a plane {equation} / {point, normal}');
	}

	/** Reduced row echelon form, with the pivot column => row, and the steps in words. */
	private static function eliminate(array $m, int $columns): array {
		$pivots = [];
		$steps = [];
		$r = 0;
		for ($c = 0; $c < $columns && $r < count($m); $c++) {
			$p = null;
			for ($i = $r; $i < count($m); $i++) {
				if (!self::isZero($m[$i][$c])) {
					$p = $i;
					break;
				}
			}
			if ($p === null) {
				continue;
			}
			if ($p !== $r) {
				[$m[$r], $m[$p]] = [$m[$p], $m[$r]];
				$steps[] = 'swap rows ' . ($r + 1) . ' and ' . ($p + 1);
			}
			$pivot = $m[$r][$c];
			if (!($pivot[0] === 1 && $pivot[1] === 1)) {
				$m[$r] = array_map(fn ($v) => self::div($v, $pivot), $m[$r]);
				$steps[] = 'row ' . ($r + 1) . ' ÷ ' . self::str($pivot);
			}
			for ($i = 0; $i < count($m); $i++) {
				if ($i !== $r && !self::isZero($m[$i][$c])) {
					$factor = $m[$i][$c];
					foreach ($m[$i] as $k => $v) {
						$m[$i][$k] = self::sub($v, self::mul($factor, $m[$r][$k]));
					}
					$steps[] = 'row ' . ($i + 1) . ' − ' . self::str($factor) . ' · row ' . ($r + 1);
				}
			}
			$pivots[$c] = $r++;
		}
		return [$m, $pivots, array_slice($steps, 0, 40)];
	}

	private static function solveFloat(array $m): array {
		$rational = array_map(fn ($row) => array_map(fn ($v) => self::num(rtrim(rtrim(sprintf('%.12F', $v), '0'), '.')), $row), $m);
		[$r, $pivots] = self::eliminate($rational, 3);
		$x = [0.0, 0.0, 0.0];
		foreach ($pivots as $c => $row) {
			$x[$c] = self::float($r[$row][3]);
		}
		return $x;
	}

	private static function matrix(array $rows): array {
		$m = array_map(fn ($row) => array_map([self::class, 'num'], array_values((array)$row)), array_values($rows));
		if ($m === [] || $m[0] === [] || count(array_unique(array_map('count', $m))) !== 1) {
			throw new \InvalidArgumentException('a matrix as rows of equal length');
		}
		if (count($m) > 12 || count($m[0]) > 13) {
			throw new \InvalidArgumentException('at most 12 rows');
		}
		return $m;
	}

	private static function show(array $m): string {
		return implode("\n", array_map(fn ($row) => '[' . implode(', ', array_map([self::class, 'str'], $row)) . ']', $m));
	}

	/** A number as a fraction [numerator, denominator]: 3, -2.5, "1/3", "0.125". */
	public static function num(mixed $v): array {
		$s = str_replace(['−', ' '], ['-', ''], trim((string)$v));
		if (preg_match('/^([+-]?\d+)\/(\d+)$/', $s, $m) === 1 && (int)$m[2] !== 0) {
			return self::norm((int)$m[1], (int)$m[2]);
		}
		if (preg_match('/^([+-]?)(\d*)\.(\d+)$/', $s, $m) === 1 && strlen($m[3]) <= 9) {
			$d = 10 ** strlen($m[3]);
			return self::norm((int)($m[1] . ($m[2] === '' ? '0' : $m[2])) * $d + ($m[1] === '-' ? -1 : 1) * (int)$m[3], $d);
		}
		if (preg_match('/^[+-]?\d+$/', $s) === 1) {
			return [(int)$s, 1];
		}
		throw new \InvalidArgumentException('not a number: ' . $s);
	}

	private static function norm(int $n, int $d): array {
		if ($d === 0) {
			throw new \InvalidArgumentException('division by zero');
		}
		if ($d < 0) {
			[$n, $d] = [-$n, -$d];
		}
		$g = self::gcd(abs($n), $d) ?: 1;
		return [intdiv($n, $g), intdiv($d, $g)];
	}

	private static function gcd(int $a, int $b): int {
		while ($b !== 0) {
			[$a, $b] = [$b, $a % $b];
		}
		return $a;
	}

	private static function add(array $a, array $b): array {
		return self::norm($a[0] * $b[1] + $b[0] * $a[1], $a[1] * $b[1]);
	}

	private static function sub(array $a, array $b): array {
		return self::norm($a[0] * $b[1] - $b[0] * $a[1], $a[1] * $b[1]);
	}

	private static function mul(array $a, array $b): array {
		return self::norm($a[0] * $b[0], $a[1] * $b[1]);
	}

	private static function div(array $a, array $b): array {
		return self::norm($a[0] * $b[1], $a[1] * $b[0]);
	}

	private static function neg(array $a): array {
		return [-$a[0], $a[1]];
	}

	private static function abs(array $a): array {
		return [abs($a[0]), $a[1]];
	}

	private static function isZero(array $a): bool {
		return $a[0] === 0;
	}

	private static function allZero(array $v): bool {
		return array_filter($v, fn ($x) => !self::isZero($x)) === [];
	}

	private static function float(array $a): float {
		return $a[0] / $a[1];
	}

	public static function str(array $a): string {
		return $a[1] === 1 ? (string)$a[0] : $a[0] . '/' . $a[1];
	}

	private static function approx(float $x): string {
		return Calculator::format(round($x, 6) + 0.0);
	}
}
