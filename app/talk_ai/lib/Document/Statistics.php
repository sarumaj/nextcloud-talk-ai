<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Descriptive statistics of numbers (count, sum, mean, median, quartiles,
 * standard deviation, …) and, for pairs, the linear regression and
 * correlation: computed, not guessed.
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
