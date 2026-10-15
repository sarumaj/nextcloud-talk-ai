<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Curve sketching computed numerically, to check the working by hand: zeros,
 * extrema, inflection points, the y-intercept, symmetry, the integral and the
 * area between the graph and the x-axis, and derivatives at a point. Values are
 * found by sampling and refining (bisection), so they are exact to about 1e-9,
 * shown rounded; a sign change hidden between two samples can be missed.
 */
class Analysis {
	private const SAMPLES = 4000;

	public static function analyze(string $expression, float $from, float $to, ?float $at = null, bool $degrees = false): array {
		$expression = preg_replace('/^\s*[a-z]\s*\(\s*x\s*\)\s*=|^\s*y\s*=/i', '', $expression) ?? $expression;
		if ($to <= $from) {
			throw new \InvalidArgumentException('the interval needs from < to');
		}
		$calc = new Calculator($degrees);
		$f = function (float $x) use ($calc, $expression): ?float {
			try {
				$y = $calc->value($expression, ['x' => $x]);
				return is_finite($y) ? $y : null;
			} catch (\InvalidArgumentException $e) {
				if (preg_match('/unknown|unexpected|missing|incomplete/', $e->getMessage()) === 1) {
					throw $e;
				}
				return null;
			}
		};
		$h = 1e-5 * max(1, $to - $from);
		$d1 = fn (float $x): ?float => ($a = $f($x + $h)) !== null && ($b = $f($x - $h)) !== null ? ($a - $b) / (2 * $h) : null;
		$d2 = fn (float $x): ?float => ($a = $f($x + $h)) !== null && ($b = $f($x - $h)) !== null && ($c = $f($x)) !== null ? ($a - 2 * $c + $b) / ($h * $h) : null;

		$zeros = self::roots($f, $from, $to);
		$criticals = self::roots($d1, $from, $to);
		$extrema = [];
		foreach ($criticals as $x) {
			$y = $f($x);
			$curvature = $d2($x);
			if ($y === null || $curvature === null) {
				continue;
			}
			$kind = abs($curvature) < 1e-6 ? 'saddle point (f\'\' = 0: check the sign change of f\')' : ($curvature > 0 ? 'minimum' : 'maximum');
			$extrema[] = ['x' => self::show($x), 'y' => self::show($y), 'kind' => $kind];
		}
		$inflections = [];
		foreach (self::roots($d2, $from, $to) as $x) {
			$y = $f($x);
			if ($y !== null) {
				$inflections[] = ['x' => self::show($x), 'y' => self::show($y), 'slope' => self::show((float)$d1($x))];
			}
		}
		[$integral, $area] = self::integrate($f, $from, $to, $zeros);
		$out = [
			'function' => 'f(x) = ' . trim($expression),
			'interval' => '[' . self::show($from) . ', ' . self::show($to) . ']',
			'y_intercept' => $from <= 0 && $to >= 0 && ($y0 = $f(0.0)) !== null ? self::show($y0) : 'none in the interval',
			'zeros' => array_map([self::class, 'show'], $zeros) ?: 'none in the interval',
			'extrema' => $extrema ?: 'none in the interval',
			'inflection_points' => $inflections ?: 'none in the interval',
			'symmetry' => self::symmetry($f, $from, $to),
			'integral' => $integral === null ? 'not defined (the function is not defined throughout)' : self::show($integral),
			'area_to_x_axis' => $area === null ? 'not defined' : self::show($area),
			'note' => 'Numerical values; give exact ones from the working by hand. Behaviour outside the interval is not checked.',
		];
		if ($at !== null) {
			$out['at_x=' . self::show($at)] = ['f' => ($v = $f($at)) === null ? 'undefined' : self::show($v), 'f\'' => ($v = $d1($at)) === null ? 'undefined' : self::show($v),
				'f\'\'' => ($v = $d2($at)) === null ? 'undefined' : self::show($v),
				'tangent' => ($y = $f($at)) !== null && ($m = $d1($at)) !== null ? self::line($m, $y - $m * $at) : 'undefined'];
		}
		return $out;
	}

	/**
	 * The zeros of g in [a, b]: sign changes between samples, refined by
	 * bisection; touching zeros (a minimum of |g| at 0) as well.
	 *
	 * @return list<float>
	 */
	private static function roots(callable $g, float $a, float $b): array {
		$step = ($b - $a) / self::SAMPLES;
		$values = [];
		for ($i = 0; $i <= self::SAMPLES; $i++) {
			$x = $a + $i * $step;
			$values[] = [$x, $g($x)];
		}
		$found = [];
		$scale = max(1e-12, max(array_map(fn ($v) => $v[1] === null ? 0 : abs($v[1]), $values)));
		for ($i = 0; $i < self::SAMPLES; $i++) {
			[$x1, $y1] = $values[$i];
			[$x2, $y2] = $values[$i + 1];
			if ($y1 === null || $y2 === null) {
				continue;
			}
			if ($y1 == 0.0) {
				$found[] = $x1;
			} elseif ($y1 * $y2 < 0) {
				// A sign change across a pole (1/x at 0) is no zero: the values must shrink.
				$root = self::bisect($g, $x1, $x2, $y1);
				$yr = $g($root);
				if ($yr !== null && abs($yr) < 1e-6 * $scale + 1e-9) {
					$found[] = $root;
				}
			} elseif ($i > 0 && $values[$i - 1][1] !== null && abs($y1) < abs($values[$i - 1][1]) && abs($y1) <= abs($y2) && abs($y1) < 1e-7 * $scale) {
				$found[] = $x1;
			}
		}
		if (($last = end($values)) && $last[1] === 0.0) {
			$found[] = $last[0];
		}
		// Neighbours from one zero merged.
		$out = [];
		foreach ($found as $x) {
			if ($out === [] || abs($x - end($out)) > 2 * $step) {
				$out[] = abs($x) < 1e-9 ? 0.0 : $x;
			}
		}
		return array_slice($out, 0, 30);
	}

	private static function bisect(callable $g, float $a, float $b, float $ga): float {
		for ($i = 0; $i < 100 && $b - $a > 1e-13 * max(1, abs($a)); $i++) {
			$m = ($a + $b) / 2;
			$gm = $g($m);
			if ($gm === null) {
				break;
			}
			if ($gm == 0.0) {
				return $m;
			}
			if (($gm < 0) === ($ga < 0)) {
				[$a, $ga] = [$m, $gm];
			} else {
				$b = $m;
			}
		}
		return ($a + $b) / 2;
	}

	/** ∫ f and ∫ |f| over [a, b] by Simpson's rule on each piece between zeros; null where f is undefined. */
	private static function integrate(callable $f, float $a, float $b, array $zeros): array {
		$points = array_values(array_unique(array_merge([$a], array_filter($zeros, fn ($z) => $z > $a && $z < $b), [$b])));
		sort($points);
		$integral = 0.0;
		$area = 0.0;
		for ($k = 0; $k + 1 < count($points); $k++) {
			[$p, $q] = [$points[$k], $points[$k + 1]];
			$n = 2000;
			$hh = ($q - $p) / $n;
			$sum = 0.0;
			for ($i = 0; $i <= $n; $i++) {
				$y = $f($p + $i * $hh);
				if ($y === null) {
					return [null, null];
				}
				$sum += $y * ($i === 0 || $i === $n ? 1 : ($i % 2 === 1 ? 4 : 2));
			}
			$piece = $sum * $hh / 3;
			$integral += $piece;
			$area += abs($piece);
		}
		return [$integral, $area];
	}

	private static function symmetry(callable $f, float $a, float $b): string {
		$r = min(abs($a), abs($b));
		if ($a >= 0 || $b <= 0 || $r == 0.0) {
			return 'not checked (the interval is not symmetric about 0)';
		}
		$even = $odd = true;
		for ($i = 1; $i <= 50; $i++) {
			$x = $r * $i / 50;
			[$p, $m] = [$f($x), $f(-$x)];
			if ($p === null || $m === null) {
				continue;
			}
			$tolerance = 1e-9 * max(1, abs($p));
			$even = $even && abs($p - $m) < $tolerance;
			$odd = $odd && abs($p + $m) < $tolerance;
		}
		return $even ? 'axis-symmetric about the y-axis (even)' : ($odd ? 'point-symmetric about the origin (odd)' : 'no symmetry about the y-axis or the origin');
	}

	/** y = m·x + b, without a zero slope or offset. */
	private static function line(float $m, float $b): string {
		$m = round($m, 6);
		$b = round($b, 6);
		if ($m == 0.0) {
			return 'y = ' . self::show($b);
		}
		$slope = $m == 1.0 ? '' : ($m == -1.0 ? '−' : self::show($m));
		return 'y = ' . $slope . 'x' . ($b == 0.0 ? '' : ($b < 0 ? ' − ' : ' + ') . self::show(abs($b)));
	}

	private static function show(float $x): string {
		return Calculator::format(round($x, 6) + 0.0);
	}
}
