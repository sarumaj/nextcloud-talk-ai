<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Numerical methods as taught in the first semesters, with their tables so the
 * working can be followed: roots (bisection, Newton, regula falsi), integrals
 * (trapezoid, Simpson, compared), initial value problems y' = f(x, y) (Euler,
 * Heun, Runge-Kutta 4; also systems), eigenvalues of a matrix (QR iteration), and
 * derivatives at a point. Expressions in the syntax of calculate.
 */
class Numerics {
	/** A root of f in [a, b] (bisection, regula falsi) or from a start a (Newton), with the iterations. */
	public static function root(string $f, float $a, ?float $b = null, string $method = 'newton', float $tolerance = 1e-10): array {
		$calc = new Calculator();
		$y = fn (float $x) => $calc->value($f, ['x' => $x]);
		$rows = [];
		if ($method === 'newton' || $b === null) {
			$x = $a;
			for ($i = 1; $i <= 50; $i++) {
				$h = max(abs($x), 1) * 1e-6;
				$d = ($y($x + $h) - $y($x - $h)) / (2 * $h);
				if ($d == 0.0) {
					throw new \InvalidArgumentException('f\'(x) = 0 at x = ' . $x . ': Newton cannot go on; choose another start');
				}
				$next = $x - $y($x) / $d;
				$rows[] = [$i, self::g($x), self::g($y($x)), self::g($d), self::g($next)];
				if (abs($next - $x) < $tolerance) {
					$x = $next;
					break;
				}
				$x = $next;
			}
			return ['method' => 'Newton: xₙ₊₁ = xₙ − f(xₙ)/f\'(xₙ)', 'root' => self::g($x), 'f_at_root' => self::g($y($x)), 'table' => self::table(['n', 'xₙ', 'f(xₙ)', 'f\'(xₙ)', 'xₙ₊₁'], $rows)];
		}
		if ($y($a) * $y($b) > 0) {
			throw new \InvalidArgumentException('f(a) and f(b) need opposite signs');
		}
		for ($i = 1; $i <= 200 && abs($b - $a) > $tolerance; $i++) {
			$m = $method === 'regula_falsi' ? $a - $y($a) * ($b - $a) / ($y($b) - $y($a)) : ($a + $b) / 2;
			if ($i <= 40) {
				$rows[] = [$i, self::g($a), self::g($b), self::g($m), self::g($y($m))];
			}
			if ($y($m) == 0.0) {
				$a = $b = $m;
				break;
			}
			$y($a) * $y($m) < 0 ? $b = $m : $a = $m;
			if ($method === 'regula_falsi' && abs($y($m)) < $tolerance) {
				$a = $b = $m;
				break;
			}
		}
		$x = ($a + $b) / 2;
		return ['method' => $method === 'regula_falsi' ? 'regula falsi' : 'bisection', 'root' => self::g($x), 'f_at_root' => self::g($y($x)),
			'table' => self::table(['n', 'a', 'b', 'm', 'f(m)'], $rows)];
	}

	/** ∫ f dx over [a, b] by the trapezoid and Simpson rules with n strips, and a fine reference. */
	public static function integrate(string $f, float $a, float $b, int $n = 10): array {
		$calc = new Calculator();
		$y = fn (float $x) => $calc->value($f, ['x' => $x]);
		$n = max(2, min(100000, $n + $n % 2));
		$h = ($b - $a) / $n;
		$trap = ($y($a) + $y($b)) / 2;
		$simp = $y($a) + $y($b);
		for ($i = 1; $i < $n; $i++) {
			$v = $y($a + $i * $h);
			$trap += $v;
			$simp += ($i % 2 ? 4 : 2) * $v;
		}
		$fine = 0.0;
		$m = 20000;
		$hf = ($b - $a) / $m;
		for ($i = 0; $i <= $m; $i++) {
			$fine += ($i === 0 || $i === $m ? 1 : ($i % 2 ? 4 : 2)) * $y($a + $i * $hf);
		}
		return ['n' => $n, 'trapezoid' => self::g($trap * $h), 'simpson' => self::g($simp * $h / 3), 'reference' => self::g($fine * $hf / 3) . ' (Simpson, n = 20000)',
			'formulas' => 'trapezoid h·(f₀/2 + f₁ + … + fₙ/2); Simpson h/3·(f₀ + 4f₁ + 2f₂ + … + fₙ), h = (b − a)/n'];
	}

	/**
	 * An initial value problem y' = f(x, y) from (x0, y0) to x_end in n steps; a system
	 * with equations ["y2", "-y1"] for y1' and y2' and y0 a list.
	 */
	public static function ode(string|array $f, float $x0, float|array $y0, float $xEnd, int $n = 10, string $method = 'rk4'): array {
		$calc = new Calculator();
		$eqs = (array)$f;
		$single = !is_array($f);
		$y = array_values((array)$y0);
		if (count($y) !== count($eqs)) {
			throw new \InvalidArgumentException('one start value per equation');
		}
		$rate = function (float $x, array $y) use ($calc, $eqs, $single): array {
			$vars = ['x' => $x, 't' => $x];
			foreach ($y as $i => $v) {
				$vars['y' . ($i + 1)] = $v;
			}
			if ($single) {
				$vars['y'] = $y[0];
			}
			return array_map(fn ($e) => $calc->value($e, $vars), $eqs);
		};
		$add = fn (array $a, array $b, float $s) => array_map(fn ($u, $v) => $u + $s * $v, $a, $b);
		$n = max(1, min(10000, $n));
		$h = ($xEnd - $x0) / $n;
		$x = $x0;
		$rows = [[0, self::g($x), ...array_map(self::g(...), $y)]];
		for ($i = 1; $i <= $n; $i++) {
			$k1 = $rate($x, $y);
			$y = match ($method) {
				'euler' => $add($y, $k1, $h),
				'heun' => $add($y, $add($k1, $rate($x + $h, $add($y, $k1, $h)), 1), $h / 2),
				default => (function () use ($rate, $add, $x, $y, $h, $k1) {
					$k2 = $rate($x + $h / 2, $add($y, $k1, $h / 2));
					$k3 = $rate($x + $h / 2, $add($y, $k2, $h / 2));
					$k4 = $rate($x + $h, $add($y, $k3, $h));
					return array_map(fn ($v, $a, $b, $c, $d) => $v + $h / 6 * ($a + 2 * $b + 2 * $c + $d), $y, $k1, $k2, $k3, $k4);
				})(),
			};
			$x = $x0 + $i * $h;
			if ($n <= 50 || $i % (int)ceil($n / 50) === 0 || $i === $n) {
				$rows[] = [$i, self::g($x), ...array_map(self::g(...), $y)];
			}
		}
		$names = $single ? ['y'] : array_map(fn ($i) => 'y' . ($i + 1), array_keys($eqs));
		return ['method' => match ($method) { 'euler' => 'explicit Euler', 'heun' => 'Heun', default => 'classical Runge-Kutta (4th order)' },
			'h' => self::g($h), 'end' => array_combine($names, array_map(self::g(...), $y)), 'table' => self::table(['i', 'x', ...$names], $rows),
			'plot' => 'plot type line with x as labels and each y as a series shows the solution'];
	}

	/** Eigenvalues of a square matrix by the unshifted QR iteration (real ones; complex pairs are named). */
	public static function eigenvalues(array $matrix): array {
		$n = count($matrix);
		$a = array_map(fn ($row) => array_map(fn ($v) => (new Calculator())->value((string)$v, []), array_values($row)), array_values($matrix));
		if ($n < 2 || $n > 8 || array_filter($a, fn ($r) => count($r) !== $n) !== []) {
			throw new \InvalidArgumentException('a square matrix of 2 to 8 rows');
		}
		for ($it = 0; $it < 2000; $it++) {
			[$q, $r] = self::qr($a, $n);
			$a = self::mul($r, $q, $n);
		}
		$values = [];
		for ($i = 0; $i < $n; $i++) {
			if ($i < $n - 1 && abs($a[$i + 1][$i]) > 1e-6) {
				// A 2×2 block with complex eigenvalues.
				$tr = $a[$i][$i] + $a[$i + 1][$i + 1];
				$det = $a[$i][$i] * $a[$i + 1][$i + 1] - $a[$i][$i + 1] * $a[$i + 1][$i];
				$disc = $tr * $tr / 4 - $det;
				$values[] = $disc >= 0 ? self::g($tr / 2 + sqrt($disc)) : self::g($tr / 2) . ' + ' . self::g(sqrt(-$disc)) . 'i';
				$values[] = $disc >= 0 ? self::g($tr / 2 - sqrt($disc)) : self::g($tr / 2) . ' − ' . self::g(sqrt(-$disc)) . 'i';
				$i++;
				continue;
			}
			$values[] = self::g($a[$i][$i]);
		}
		return ['eigenvalues' => $values, 'method' => 'QR iteration (2000 steps); for 2×2 and 3×3 check with the characteristic polynomial det(A − λI) = 0'];
	}

	/** f(x0), f'(x0) and f''(x0) by central differences. */
	public static function derivative(string $f, float $x0): array {
		$calc = new Calculator();
		$y = fn (float $x) => $calc->value($f, ['x' => $x]);
		$h = max(abs($x0), 1) * 1e-4;
		return ['f' => self::g($y($x0)), 'f1' => self::g(($y($x0 + $h) - $y($x0 - $h)) / (2 * $h)),
			'f2' => self::g(($y($x0 + $h) - 2 * $y($x0) + $y($x0 - $h)) / ($h * $h)), 'method' => 'central differences'];
	}

	/** @return array{array, array} Q and R by Gram-Schmidt */
	private static function qr(array $a, int $n): array {
		$q = array_fill(0, $n, array_fill(0, $n, 0.0));
		$r = array_fill(0, $n, array_fill(0, $n, 0.0));
		for ($j = 0; $j < $n; $j++) {
			$v = array_column($a, $j);
			for ($i = 0; $i < $j; $i++) {
				$r[$i][$j] = array_sum(array_map(fn ($k) => $q[$k][$i] * $a[$k][$j], range(0, $n - 1)));
				for ($k = 0; $k < $n; $k++) {
					$v[$k] -= $r[$i][$j] * $q[$k][$i];
				}
			}
			$norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));
			$r[$j][$j] = $norm;
			for ($k = 0; $k < $n; $k++) {
				$q[$k][$j] = $norm > 1e-300 ? $v[$k] / $norm : 0.0;
			}
		}
		return [$q, $r];
	}

	private static function mul(array $a, array $b, int $n): array {
		$c = [];
		for ($i = 0; $i < $n; $i++) {
			for ($j = 0; $j < $n; $j++) {
				$c[$i][$j] = array_sum(array_map(fn ($k) => $a[$i][$k] * $b[$k][$j], range(0, $n - 1)));
			}
		}
		return $c;
	}

	private static function table(array $head, array $rows): string {
		return '| ' . implode(' | ', $head) . " |\n|" . str_repeat('---|', count($head)) . "\n" . implode("\n", array_map(fn ($r) => '| ' . implode(' | ', $r) . ' |', $rows));
	}

	private static function g(float $v): string {
		return Calculator::format((float)sprintf('%.10g', $v));
	}
}
