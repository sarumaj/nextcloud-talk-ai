<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Descriptive statistics of numbers (count, sum, mean, median, quartiles,
 * standard deviation, …), for pairs the linear regression and correlation,
 * and hypothesis tests (t-tests, χ² tests, correlation): computed, not guessed.
 */
class Statistics {
	/** @param list<float> $values */
	public static function describe(array $values): array {
		$n = count($values);
		if ($n === 0) {
			throw new \InvalidArgumentException('no numbers');
		}
		sort($values);
		$mean = array_sum($values) / $n;
		$squares = array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $values));
		$counts = array_count_values(array_map(fn (float $v) => Calculator::format($v), $values));
		arsort($counts);
		$top = reset($counts);
		$f = fn (float $v): string => Calculator::format(round($v, 10));
		return [
			'count' => $n,
			'sum' => $f(array_sum($values)),
			'mean' => $f($mean),
			'median' => $f(self::quantile($values, 0.5)),
			// array_keys makes "4" an integer key: back to text.
			'mode' => $top > 1 ? array_map('strval', array_keys(array_filter($counts, fn (int $c) => $c === $top))) : 'none (all values differ)',
			'min' => $f($values[0]),
			'max' => $f($values[$n - 1]),
			'range' => $f($values[$n - 1] - $values[0]),
			'q1' => $f(self::quantile($values, 0.25)),
			'q3' => $f(self::quantile($values, 0.75)),
			'variance_sample' => $n > 1 ? $f($squares / ($n - 1)) : null,
			'stdev_sample' => $n > 1 ? $f(sqrt($squares / ($n - 1))) : null,
			'variance_population' => $f($squares / $n),
			'stdev_population' => $f(sqrt($squares / $n)),
		];
	}

	/**
	 * The least-squares line y = a + bx, and Pearson's r.
	 *
	 * @param list<float> $x
	 * @param list<float> $y
	 */
	public static function regression(array $x, array $y): array {
		$n = min(count($x), count($y));
		if ($n < 2) {
			throw new \InvalidArgumentException('at least two pairs');
		}
		$x = array_slice($x, 0, $n);
		$y = array_slice($y, 0, $n);
		$mx = array_sum($x) / $n;
		$my = array_sum($y) / $n;
		$sxy = $sxx = $syy = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$sxy += ($x[$i] - $mx) * ($y[$i] - $my);
			$sxx += ($x[$i] - $mx) ** 2;
			$syy += ($y[$i] - $my) ** 2;
		}
		if ($sxx == 0.0) {
			throw new \InvalidArgumentException('all x are equal');
		}
		$b = $sxy / $sxx;
		$a = $my - $b * $mx;
		$r = $syy == 0.0 ? null : $sxy / sqrt($sxx * $syy);
		$f = fn (float $v): string => Calculator::format(round($v, 10));
		return [
			'pairs' => $n,
			'line' => 'y = ' . $f($a) . ($b < 0 ? ' − ' : ' + ') . $f(abs($b)) . 'x',
			'intercept_a' => $f($a),
			'slope_b' => $f($b),
			'pearson_r' => $r === null ? null : $f($r),
			'r_squared' => $r === null ? null : $f($r ** 2),
		];
	}

	/**
	 * A t-test: one sample against a mean (y empty, mu given), two independent
	 * samples (Welch, unequal variances), or paired samples. Two-sided p-value,
	 * and one-sided for a direction.
	 */
	public static function tTest(array $x, array $y = [], float $mu = 0.0, bool $paired = false, float $alpha = 0.05): array {
		if ($paired) {
			if (count($x) !== count($y)) {
				throw new \InvalidArgumentException('paired samples need the same number of values');
			}
			$x = array_map(fn ($a, $b) => $a - $b, $x, $y);
			$y = [];
		}
		[$n1, $m1, $v1] = self::moments($x);
		if ($y === []) {
			$se = sqrt($v1 / $n1);
			$t = ($m1 - $mu) / $se;
			$df = $n1 - 1;
			$test = $paired ? 'paired t-test (differences x − y against 0)' : 'one-sample t-test against μ₀ = ' . Calculator::format($mu);
		} else {
			[$n2, $m2, $v2] = self::moments($y);
			$se = sqrt($v1 / $n1 + $v2 / $n2);
			$t = ($m1 - $m2) / $se;
			$df = ($v1 / $n1 + $v2 / $n2) ** 2 / (($v1 / $n1) ** 2 / ($n1 - 1) + ($v2 / $n2) ** 2 / ($n2 - 1));
			$test = 'Welch\'s two-sample t-test (unequal variances)';
		}
		$p = self::tTail($t, $df);
		return [
			'test' => $test,
			't' => self::r($t), 'degrees_of_freedom' => self::r($df),
			'p_two_sided' => self::r($p), 'p_one_sided' => self::r($p / 2) . ' (in the direction of the difference)',
			'mean_difference' => self::r($y === [] ? $m1 - $mu : $m1 - ($m2 ?? 0)),
			'significant_at_' . $alpha => $p < $alpha,
		];
	}

	/** χ² test of independence of a contingency table (rows of counts). */
	public static function chiSquareIndependence(array $table, float $alpha = 0.05): array {
		$rows = array_map(fn ($r) => array_map('floatval', array_values((array)$r)), array_values($table));
		if (count($rows) < 2 || count($rows[0]) < 2) {
			throw new \InvalidArgumentException('a table of at least 2 × 2 counts');
		}
		$total = array_sum(array_map('array_sum', $rows));
		$rowSums = array_map('array_sum', $rows);
		$colSums = array_map(fn ($j) => array_sum(array_column($rows, $j)), array_keys($rows[0]));
		$chi = 0.0;
		$smallest = INF;
		foreach ($rows as $i => $row) {
			foreach ($row as $j => $o) {
				$e = $rowSums[$i] * $colSums[$j] / $total;
				$smallest = min($smallest, $e);
				$chi += ($o - $e) ** 2 / $e;
			}
		}
		$df = (count($rows) - 1) * (count($rows[0]) - 1);
		$p = 1 - self::gammaP($df / 2, $chi / 2);
		return ['test' => 'χ² test of independence', 'chi_square' => self::r($chi), 'degrees_of_freedom' => $df, 'p' => self::r($p),
			'cramers_v' => self::r(sqrt($chi / ($total * (min(count($rows), count($rows[0])) - 1)))),
			'significant_at_' . $alpha => $p < $alpha,
			'note' => $smallest < 5 ? 'Some expected counts are below 5: the test is unreliable (Fisher\'s exact test is better).' : 'All expected counts are at least 5.'];
	}

	/** χ² goodness of fit: observed counts against expected counts or proportions (equal if none). */
	public static function chiSquareFit(array $observed, array $expected = [], float $alpha = 0.05): array {
		$o = array_map('floatval', array_values($observed));
		$total = array_sum($o);
		$e = $expected === [] ? array_fill(0, count($o), $total / count($o)) : array_map('floatval', array_values($expected));
		if (count($e) !== count($o)) {
			throw new \InvalidArgumentException('as many expected values as observed ones');
		}
		if (abs(array_sum($e) - 1) < 1e-9 || array_sum($e) <= 1.0001) {
			$e = array_map(fn ($p) => $p * $total, $e);
		}
		$chi = array_sum(array_map(fn ($a, $b) => ($a - $b) ** 2 / $b, $o, $e));
		$df = count($o) - 1;
		$p = 1 - self::gammaP($df / 2, $chi / 2);
		return ['test' => 'χ² goodness-of-fit test', 'chi_square' => self::r($chi), 'degrees_of_freedom' => $df, 'p' => self::r($p), 'significant_at_' . $alpha => $p < $alpha];
	}

	/** Whether a correlation r of n pairs differs from 0: t = r·√(n−2)/√(1−r²). */
	public static function correlationTest(float $r, int $n, float $alpha = 0.05): array {
		if ($n < 3 || abs($r) >= 1) {
			throw new \InvalidArgumentException('needs n ≥ 3 and −1 < r < 1');
		}
		$t = $r * sqrt($n - 2) / sqrt(1 - $r * $r);
		$p = self::tTail($t, $n - 2);
		return ['test' => 't-test of the correlation against 0', 't' => self::r($t), 'degrees_of_freedom' => $n - 2, 'p_two_sided' => self::r($p), 'significant_at_' . $alpha => $p < $alpha];
	}

	/** @return array{int, float, float} n, mean, sample variance */
	private static function moments(array $values): array {
		$n = count($values);
		if ($n < 2) {
			throw new \InvalidArgumentException('a sample needs at least two values');
		}
		$mean = array_sum($values) / $n;
		return [$n, $mean, array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1)];
	}

	/** Two-sided tail probability P(|T| ≥ |t|) of Student's t with df degrees of freedom. */
	public static function tTail(float $t, float $df): float {
		return self::betaI($df / ($df + $t * $t), $df / 2, 0.5);
	}

	/** The regularized incomplete beta function I_x(a, b) (continued fraction, Numerical Recipes). */
	private static function betaI(float $x, float $a, float $b): float {
		if ($x <= 0) {
			return 0.0;
		}
		if ($x >= 1) {
			return 1.0;
		}
		$front = exp(self::logGamma($a + $b) - self::logGamma($a) - self::logGamma($b) + $a * log($x) + $b * log(1 - $x));
		return $x < ($a + 1) / ($a + $b + 2) ? $front * self::betaFraction($x, $a, $b) / $a : 1 - $front * self::betaFraction(1 - $x, $b, $a) / $b;
	}

	private static function betaFraction(float $x, float $a, float $b): float {
		$tiny = 1e-300;
		$c = 1.0;
		$d = 1 - ($a + $b) * $x / ($a + 1);
		$d = 1 / (abs($d) < $tiny ? $tiny : $d);
		$h = $d;
		for ($m = 1; $m <= 300; $m++) {
			foreach ([$m * ($b - $m) * $x / (($a + 2 * $m - 1) * ($a + 2 * $m)), -($a + $m) * ($a + $b + $m) * $x / (($a + 2 * $m) * ($a + 2 * $m + 1))] as $k => $num) {
				$d = 1 + $num * $d;
				$d = 1 / (abs($d) < $tiny ? $tiny : $d);
				$c = 1 + $num / $c;
				$c = abs($c) < $tiny ? $tiny : $c;
				$h *= $d * $c;
				if ($k === 1 && abs($d * $c - 1) < 1e-15) {
					return $h;
				}
			}
		}
		return $h;
	}

	/** The regularized lower incomplete gamma function P(a, x). */
	private static function gammaP(float $a, float $x): float {
		if ($x <= 0) {
			return 0.0;
		}
		if ($x < $a + 1) {
			$sum = $term = 1 / $a;
			for ($n = 1; $n < 500 && abs($term) > abs($sum) * 1e-16; $n++) {
				$term *= $x / ($a + $n);
				$sum += $term;
			}
			return $sum * exp(-$x + $a * log($x) - self::logGamma($a));
		}
		// The upper function by Lentz's continued fraction.
		$tiny = 1e-300;
		$b = $x + 1 - $a;
		$c = 1 / $tiny;
		$d = 1 / $b;
		$h = $d;
		for ($i = 1; $i < 500; $i++) {
			$an = -$i * ($i - $a);
			$b += 2;
			$d = $an * $d + $b;
			$d = 1 / (abs($d) < $tiny ? $tiny : $d);
			$c = $b + $an / $c;
			$c = abs($c) < $tiny ? $tiny : $c;
			$h *= $d * $c;
			if (abs($d * $c - 1) < 1e-16) {
				break;
			}
		}
		return 1 - exp(-$x + $a * log($x) - self::logGamma($a)) * $h;
	}

	/** ln Γ(x), Lanczos approximation (g = 7). */
	private static function logGamma(float $x): float {
		$g = [0.99999999999980993, 676.5203681218851, -1259.1392167224028, 771.32342877765313, -176.61502916214059,
			12.507343278686905, -0.13857109526572012, 9.9843695780195716e-6, 1.5056327351493116e-7];
		if ($x < 0.5) {
			return log(M_PI / abs(sin(M_PI * $x))) - self::logGamma(1 - $x);
		}
		$x -= 1;
		$a = $g[0];
		$t = $x + 7.5;
		for ($i = 1; $i < 9; $i++) {
			$a += $g[$i] / ($x + $i);
		}
		return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($a);
	}

	private static function r(float $v): string {
		return Calculator::format((float)sprintf('%.6g', $v));
	}

	/** The quantile by linear interpolation (as Excel's QUARTILE.INC). */
	private static function quantile(array $sorted, float $q): float {
		$position = (count($sorted) - 1) * $q;
		$low = (int)floor($position);
		$high = (int)ceil($position);
		return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($position - $low);
	}

	/** Numbers from text: "1, 2.5; 3" or a column; "1,5" as a decimal comma when no other comma separates. */
	public static function numbers(string|array $input): array {
		if (is_array($input)) {
			return array_values(array_map('floatval', array_filter($input, 'is_numeric')));
		}
		$parts = preg_split('/[\s;]+|,(?=\s)|,(?=\D)/', trim($input)) ?: [];
		$out = [];
		foreach ($parts as $part) {
			foreach (str_contains($part, ',') && substr_count($part, ',') > 1 ? explode(',', $part) : [$part] as $p) {
				$number = OdsWriter::number($p);
				if ($number !== null) {
					$out[] = $number;
				}
			}
		}
		return $out;
	}
}
