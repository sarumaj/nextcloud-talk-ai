<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Measurement uncertainty for lab reports (GUM, DIN 1319): the type A uncertainty
 * of repeated readings, the propagation through a formula (Gaussian or the maximum
 * error, with the partial derivatives and an uncertainty budget), results rounded
 * as the norms ask (the uncertainty to two significant digits, rounded up; the
 * value to the same place), and a straight-line fit weighted by the uncertainties.
 */
class Measurement {
	/** Repeated readings: mean, standard deviation, the uncertainty of the mean s/√n (times Student's t for a confidence level). */
	public static function typeA(array $values, float $confidence = 0.0): array {
		$n = count($values);
		if ($n < 2) {
			throw new \InvalidArgumentException('at least two readings');
		}
		$mean = array_sum($values) / $n;
		$s = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1));
		$u = $s / sqrt($n);
		$out = ['n' => $n, 'mean' => self::g($mean), 'stdev' => self::g($s), 'u_mean' => self::g($u)];
		if ($confidence > 0 && $confidence < 1) {
			$t = Statistics::tQuantile($confidence, $n - 1);
			$out += ['t' => self::g($t), 'expanded' => self::g($t * $u), 'result' => self::result($mean, $t * $u) . ' (' . ($confidence * 100) . ' %)'];
		} else {
			$out['result'] = self::result($mean, $u);
		}
		return $out;
	}

	/**
	 * The uncertainty of a formula's result: variables {name: {value, u}}, Gaussian
	 * (root of the sum of squares) or maximum (sum of the amounts) propagation, with
	 * the partial derivatives (numerical, central differences) and each share.
	 */
	public static function propagate(string $formula, array $variables, string $method = 'gauss'): array {
		$calc = new Calculator();
		$values = [];
		$us = [];
		foreach ($variables as $name => $v) {
			$values[$name] = (float)(is_array($v) ? ($v['value'] ?? 0) : $v);
			$us[$name] = abs((float)(is_array($v) ? ($v['u'] ?? 0) : 0));
		}
		$f = $calc->value($formula, $values);
		$budget = [];
		$total = 0.0;
		foreach ($values as $name => $x) {
			$h = max(abs($x), 1e-8) * 1e-6;
			$d = ($calc->value($formula, [$name => $x + $h] + $values) - $calc->value($formula, [$name => $x - $h] + $values)) / (2 * $h);
			$share = abs($d) * $us[$name];
			$total += $method === 'max' ? $share : $share ** 2;
			$budget[$name] = ['∂f/∂' . $name => self::g($d), 'u' => self::g($us[$name]), 'contribution' => self::g($share)];
		}
		$u = $method === 'max' ? $total : sqrt($total);
		foreach ($budget as $name => $b) {
			$budget[$name]['share'] = $u > 0 ? round(($method === 'max' ? (float)$b['contribution'] / $u : ((float)$b['contribution'] / $u) ** 2) * 100, 1) . ' %' : '0 %';
		}
		return ['value' => self::g($f), 'u' => self::g($u), 'relative' => $f != 0 ? self::g($u / abs($f) * 100) . ' %' : null, 'result' => self::result($f, $u),
			'budget' => $budget,
			'method' => $method === 'max' ? 'maximum error: Δf = Σ |∂f/∂xᵢ| Δxᵢ' : 'Gaussian propagation: u(f) = √(Σ (∂f/∂xᵢ · u(xᵢ))²), for independent quantities'];
	}

	/** A value with its uncertainty as the norms write it: u to two significant digits, rounded up; the value to that place. */
	public static function result(float $value, float $u): string {
		if ($u <= 0 || !is_finite($u)) {
			return self::g($value);
		}
		$place = (int)floor(log10($u)) - 1;
		$f = 10 ** -$place;
		$ru = ceil($u * $f - 1e-9) / $f;
		$rv = round($value * $f) / $f;
		$decimals = max(0, -$place);
		return number_format($rv, $decimals, '.', '') . ' ± ' . number_format($ru, $decimals, '.', '');
	}

	/** A straight line y = a + bx fitted with weights 1/u(y)², with the uncertainties of a and b and χ²/ndf. */
	public static function fit(array $x, array $y, array $uy): array {
		$n = count($x);
		if ($n < 3 || count($y) !== $n || count($uy) !== $n) {
			throw new \InvalidArgumentException('x, y and u(y) of the same length, at least three points');
		}
		$s = $sx = $sy = $sxx = $sxy = 0.0;
		foreach ($x as $i => $xi) {
			$w = 1 / max(1e-300, (float)$uy[$i]) ** 2;
			$s += $w;
			$sx += $w * $xi;
			$sy += $w * $y[$i];
			$sxx += $w * $xi * $xi;
			$sxy += $w * $xi * $y[$i];
		}
		$delta = $s * $sxx - $sx * $sx;
		$a = ($sxx * $sy - $sx * $sxy) / $delta;
		$b = ($s * $sxy - $sx * $sy) / $delta;
		$chi = 0.0;
		foreach ($x as $i => $xi) {
			$chi += (($y[$i] - $a - $b * $xi) / (float)$uy[$i]) ** 2;
		}
		return ['a' => self::result($a, sqrt($sxx / $delta)), 'b' => self::result($b, sqrt($s / $delta)), 'chi2_per_ndf' => self::g($chi / ($n - 2)),
			'reading' => 'χ²/ndf near 1: the line and the uncertainties fit; much larger: the uncertainties are too small or the model is wrong; much smaller: they are overestimated'];
	}

	private static function g(float $v): string {
		return Calculator::format((float)sprintf('%.6g', $v));
	}
}
