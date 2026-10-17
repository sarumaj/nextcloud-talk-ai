<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Arithmetic for the model, which miscounts: expressions with + - * / ^ %,
 * brackets, factorials, implicit products (2pi, 3(x+1)), the constants pi and
 * e, functions (sqrt, sin, log, round, gcd, binom, …) and variables
 * ("r = 2; pi r^2"). Several expressions are separated by ";" or new lines;
 * each gives a result. No code is run: the expression is parsed here.
 */
class Calculator {
	private const FUNCTIONS = ['sqrt', 'cbrt', 'abs', 'exp', 'ln', 'log', 'log2', 'log10', 'sin', 'cos', 'tan', 'asin', 'acos', 'atan',
		'atan2', 'sinh', 'cosh', 'tanh', 'floor', 'ceil', 'round', 'min', 'max', 'gcd', 'lcm', 'binom', 'ncr', 'npr', 'fact', 'mod',
		'hypot', 'root', 'sign', 'trunc', 'sum', 'mean', 'avg', 'median', 'stdev', 'stdevp', 'var', 'varp',
		'normpdf', 'normcdf', 'invnorm', 'binompdf', 'binomcdf', 'poissonpdf', 'poissoncdf', 'bin', 'hex', 'oct'];

	/** Physical constants (CODATA 2018), by names that do not clash with variables like c or h. */
	public const CONSTANTS = [
		'c0' => 299792458, 'g0' => 9.80665, 'h_planck' => 6.62607015e-34, 'hbar' => 1.054571817e-34, 'k_B' => 1.380649e-23,
		'N_A' => 6.02214076e23, 'R_gas' => 8.314462618, 'G_grav' => 6.67430e-11, 'q_e' => 1.602176634e-19, 'm_e' => 9.1093837015e-31,
		'm_p' => 1.67262192369e-27, 'm_n' => 1.67492749804e-27, 'eps0' => 8.8541878128e-12, 'mu0' => 1.25663706212e-6,
		'sigma_SB' => 5.670374419e-8, 'u_amu' => 1.66053906660e-27, 'atm' => 101325, 'F_faraday' => 96485.33212,
	];

	/** Whether the last expression asked for its result in other bases (bin, hex, oct or 0x/0b/0o numbers). */
	private bool $bases = false;

	/** @var list<string> */
	private array $tokens = [];
	private int $at = 0;
	/** @var array<string, float> */
	private array $variables = [];

	public function __construct(
		private bool $degrees = false,
	) {
	}

	/**
	 * Each expression's result, or its error.
	 *
	 * @return list<array{expression: string, result?: string, fraction?: string, error?: string}>
	 */
	public function evaluate(string $input): array {
		$results = [];
		foreach (preg_split('/[;\n]+/', $input) ?: [] as $expression) {
			$expression = trim($expression);
			if ($expression === '') {
				continue;
			}
			try {
				$this->bases = preg_match('/\b(bin|hex|oct)\s*\(|\b0[xbo][0-9a-f]/i', $expression) === 1;
				$value = $this->run($expression);
				$row = ['expression' => $expression, 'result' => self::format($value)];
				if ($this->bases && $value == floor($value) && abs($value) < 2 ** 53) {
					$n = (int)$value;
					$row += ['binary' => ($n < 0 ? '-' : '') . decbin(abs($n)), 'hex' => ($n < 0 ? '-' : '') . strtoupper(dechex(abs($n))), 'octal' => ($n < 0 ? '-' : '') . decoct(abs($n))];
				}
				$fraction = self::fraction($value);
				if ($fraction !== null) {
					$row['fraction'] = $fraction;
				}
				$results[] = $row;
			} catch (\Throwable $e) {
				$results[] = ['expression' => $expression, 'error' => $e->getMessage()];
			}
		}
		return $results;
	}

	/** One expression's value with these variables (x for a graph); an exception when it has none. */
	public function value(string $expression, array $variables): float {
		$this->variables = $variables;
		return $this->run($expression);
	}

	private function run(string $expression): float {
		$normal = strtr($expression, ['×' => '*', '·' => '*', '÷' => '/', '−' => '-', '**' => '^', 'π' => 'pi', '√' => 'sqrt', '²' => '^2', '³' => '^3']);
		if (preg_match_all('/0[xX][0-9a-fA-F]+|0[bB][01]+|0[oO][0-7]+|\d+(?:\.\d*)?(?:[eE][-+]?\d+)?|\.\d+|[A-Za-z_]\w*|[-+*\/^%(),!=]|\S/', $normal, $m) === false) {
			throw new \InvalidArgumentException('cannot read it');
		}
		$this->tokens = $m[0];
		$this->at = 0;
		$name = null;
		if (count($this->tokens) > 2 && preg_match('/^[A-Za-z_]\w*$/', $this->tokens[0]) === 1 && $this->tokens[1] === '='
			&& !in_array(strtolower($this->tokens[0]), [...self::FUNCTIONS, 'pi', 'e'], true)) {
			$name = $this->tokens[0];
			$this->at = 2;
		}
		$value = $this->sum();
		if ($this->at < count($this->tokens)) {
			throw new \InvalidArgumentException('unexpected "' . $this->tokens[$this->at] . '"');
		}
		if (is_nan($value) || is_infinite($value)) {
			throw new \InvalidArgumentException('no finite result (division by zero or out of range)');
		}
		if ($name !== null) {
			$this->variables[$name] = $value;
		}
		return $value;
	}

	private function peek(): ?string {
		return $this->tokens[$this->at] ?? null;
	}

	private function expect(string $token): void {
		if ($this->peek() !== $token) {
			throw new \InvalidArgumentException('"' . $token . '" missing');
		}
		$this->at++;
	}

	private function sum(): float {
		$value = $this->product();
		while (in_array($this->peek(), ['+', '-'], true)) {
			$value = $this->tokens[$this->at++] === '+' ? $value + $this->product() : $value - $this->product();
		}
		return $value;
	}

	private function product(): float {
		$value = $this->unary();
		while (true) {
			$t = $this->peek();
			if (in_array($t, ['*', '/', '%'], true)) {
				$this->at++;
				$right = $this->unary();
				$value = match ($t) {
					'*' => $value * $right,
					'/' => $right == 0.0 ? throw new \InvalidArgumentException('division by zero') : $value / $right,
					'%' => $right == 0.0 ? throw new \InvalidArgumentException('division by zero') : fmod($value, $right),
				};
			} elseif ($t !== null && ($t === '(' || preg_match('/^[\d.A-Za-z_]/', $t) === 1)) {
				// An implicit product: 2pi, 3(x + 1), 2 sqrt(2).
				$value *= $this->unary();
			} else {
				return $value;
			}
		}
	}

	private function unary(): float {
		if ($this->peek() === '-') {
			$this->at++;
			return -$this->unary();
		}
		if ($this->peek() === '+') {
			$this->at++;
			return $this->unary();
		}
		return $this->power();
	}

	private function power(): float {
		$base = $this->postfix();
		if ($this->peek() === '^') {
			$this->at++;
			// Right-associative, and binding the sign: 2^-1, 2^3^2 = 2^9.
			return $base ** $this->unary();
		}
		return $base;
	}

	private function postfix(): float {
		$value = $this->primary();
		while ($this->peek() === '!') {
			$this->at++;
			$value = self::factorial($value);
		}
		return $value;
	}

	private function primary(): float {
		$t = $this->tokens[$this->at++] ?? throw new \InvalidArgumentException('incomplete');
		if ($t === '(') {
			$value = $this->sum();
			$this->expect(')');
			return $value;
		}
		if (preg_match('/^0([xbo])(.+)$/i', $t, $m) === 1) {
			return (float)match (strtolower($m[1])) {
				'x' => hexdec($m[2]),
				'b' => bindec($m[2]),
				default => octdec($m[2]),
			};
		}
		if (preg_match('/^[\d.]/', $t) === 1) {
			return (float)$t;
		}
		if (preg_match('/^[A-Za-z_]\w*$/', $t) !== 1) {
			throw new \InvalidArgumentException('unexpected "' . $t . '"');
		}
		$name = strtolower($t);
		if (in_array($name, self::FUNCTIONS, true)) {
			$args = [];
			if ($this->peek() === '(') {
				$this->at++;
				if ($this->peek() !== ')') {
					$args[] = $this->sum();
					while ($this->peek() === ',') {
						$this->at++;
						$args[] = $this->sum();
					}
				}
				$this->expect(')');
			} else {
				// sqrt 2, sin x: one factor.
				$args[] = $this->power();
			}
			return $this->call($name, $args);
		}
		return match (true) {
			isset($this->variables[$t]) => $this->variables[$t],
			$name === 'pi' => M_PI,
			$name === 'e' => M_E,
			$name === 'tau' => 2 * M_PI,
			$name === 'phi' => (1 + sqrt(5)) / 2,
			isset(self::CONSTANTS[$t]) => (float)self::CONSTANTS[$t],
			default => throw new \InvalidArgumentException('unknown "' . $t . '"'),
		};
	}

	/** @param list<float> $a */
	private function call(string $name, array $a): float {
		$need = match ($name) {
			'atan2', 'gcd', 'lcm', 'binom', 'ncr', 'npr', 'mod', 'hypot', 'root', 'poissonpdf', 'poissoncdf' => 2,
			'binompdf' => 3,
			'min', 'max', 'round', 'sum', 'mean', 'avg', 'median', 'stdev', 'stdevp', 'var', 'varp', 'normpdf', 'normcdf', 'invnorm', 'binomcdf' => -1,
			default => 1,
		};
		if (($need > 0 && count($a) !== $need) || ($need < 0 && $a === [])) {
			throw new \InvalidArgumentException($name . ' needs ' . ($need > 0 ? $need : 'at least one') . ' argument' . ($need === 1 ? '' : 's'));
		}
		$in = fn (float $x): float => $this->degrees ? deg2rad($x) : $x;
		$out = fn (float $x): float => $this->degrees ? rad2deg($x) : $x;
		$x = $a[0];
		return match ($name) {
			'sqrt' => $x < 0 ? throw new \InvalidArgumentException('square root of a negative number') : sqrt($x),
			'cbrt' => $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3),
			'root' => $a[1] == 0.0 ? throw new \InvalidArgumentException('0th root') : ($x < 0 && fmod($a[1], 2) == 1.0 ? -((-$x) ** (1 / $a[1])) : $x ** (1 / $a[1])),
			'abs' => abs($x),
			'sign' => $x <=> 0,
			'exp' => exp($x),
			'ln' => $x <= 0 ? throw new \InvalidArgumentException('logarithm of a number ≤ 0') : log($x),
			'log', 'log10' => $x <= 0 ? throw new \InvalidArgumentException('logarithm of a number ≤ 0') : log10($x),
			'log2' => $x <= 0 ? throw new \InvalidArgumentException('logarithm of a number ≤ 0') : log($x, 2),
			'sin' => self::clean(sin($in($x))),
			'cos' => self::clean(cos($in($x))),
			'tan' => self::clean(tan($in($x))),
			'asin' => $out(asin($x)),
			'acos' => $out(acos($x)),
			'atan' => $out(atan($x)),
			'atan2' => $out(atan2($x, $a[1])),
			'sinh' => sinh($x),
			'cosh' => cosh($x),
			'tanh' => tanh($x),
			'floor' => floor($x),
			'ceil' => ceil($x),
			'trunc' => $x < 0 ? ceil($x) : floor($x),
			'round' => round($x, (int)($a[1] ?? 0)),
			'min' => min($a),
			'max' => max($a),
			'gcd' => (float)self::gcd((int)abs($x), (int)abs($a[1])),
			'lcm' => $x == 0.0 || $a[1] == 0.0 ? 0.0 : abs($x * $a[1]) / self::gcd((int)abs($x), (int)abs($a[1])),
			'binom', 'ncr' => self::choose($x, $a[1]),
			'npr' => self::factorial($x) / self::factorial($x - $a[1]),
			'fact' => self::factorial($x),
			'mod' => $a[1] == 0.0 ? throw new \InvalidArgumentException('division by zero') : $x - $a[1] * floor($x / $a[1]),
			'hypot' => hypot($x, $a[1]),
			'sum' => array_sum($a),
			'mean', 'avg' => array_sum($a) / count($a),
			'median' => (float)Statistics::describe($a)['median'],
			'stdev', 'var' => count($a) < 2 ? throw new \InvalidArgumentException($name . ' needs at least two values') : (float)Statistics::describe($a)[$name === 'var' ? 'variance_sample' : 'stdev_sample'],
			'stdevp' => (float)Statistics::describe($a)['stdev_population'],
			'varp' => (float)Statistics::describe($a)['variance_population'],
			'bin', 'hex', 'oct' => $x,
			// Normal distribution: normpdf(x, μ, σ), normcdf(x, μ, σ) = P(X ≤ x) or normcdf(a, b, μ, σ) = P(a ≤ X ≤ b), invnorm(p, μ, σ).
			'normpdf' => self::normalPdf(($x - ($a[1] ?? 0)) / ($a[2] ?? 1)) / ($a[2] ?? 1),
			'normcdf' => count($a) === 4
				? self::normalCdf(($a[1] - $a[2]) / $a[3]) - self::normalCdf(($x - $a[2]) / $a[3])
				: self::normalCdf(($x - ($a[1] ?? 0)) / ($a[2] ?? 1)),
			'invnorm' => $x <= 0 || $x >= 1 ? throw new \InvalidArgumentException('invnorm needs 0 < p < 1') : ($a[1] ?? 0) + ($a[2] ?? 1) * self::normalQuantile($x),
			// Binomial: binompdf(n, p, k) = P(X = k); binomcdf(n, p, k) = P(X ≤ k), binomcdf(n, p, a, b) = P(a ≤ X ≤ b).
			'binompdf' => self::binomialPdf((int)$x, $a[1], (int)$a[2]),
			'binomcdf' => count($a) === 4
				? array_sum(array_map(fn (int $k) => self::binomialPdf((int)$x, $a[1], $k), range(max(0, (int)ceil($a[2])), min((int)$x, (int)floor($a[3])))))
				: (count($a) === 3 ? array_sum(array_map(fn (int $k) => self::binomialPdf((int)$x, $a[1], $k), range(0, min((int)$x, (int)floor($a[2]))))) : throw new \InvalidArgumentException('binomcdf(n, p, k) or binomcdf(n, p, a, b)')),
			// Poisson: poissonpdf(λ, k), poissoncdf(λ, k) = P(X ≤ k).
			'poissonpdf' => exp(-$x + $a[1] * log($x) - self::logFactorial((int)$a[1])),
			'poissoncdf' => array_sum(array_map(fn (int $k) => exp(-$x + $k * log($x) - self::logFactorial($k)), range(0, (int)floor($a[1])))),
		};
	}

	private static function normalPdf(float $z): float {
		return exp(-$z * $z / 2) / sqrt(2 * M_PI);
	}

	/** Φ(z), through erfc (W. J. Cody's rational approximations, accurate to about 1e-15). */
	private static function normalCdf(float $z): float {
		return 0.5 * self::erfc(-$z / M_SQRT2);
	}

	private static function erfc(float $x): float {
		// erfc by its continued fraction for large |x|, by the series of erf for small.
		$ax = abs($x);
		if ($ax < 2.5) {
			$sum = $term = $ax;
			for ($n = 1; $n < 200 && abs($term) > 1e-17 * abs($sum); $n++) {
				$term *= -$ax * $ax / $n;
				$sum += $term / (2 * $n + 1);
			}
			$erf = 2 / sqrt(M_PI) * $sum;
			return $x >= 0 ? 1 - $erf : 1 + $erf;
		}
		// Lentz's continued fraction for erfc(x) = exp(-x²)/√π · 1/(x + 1/2/(x + 1/(x + 3/2/(x + …)))).
		$f = $ax;
		for ($n = 60; $n >= 1; $n--) {
			$f = $ax + ($n / 2) / $f;
		}
		$value = exp(-$ax * $ax) / sqrt(M_PI) / $f;
		return $x >= 0 ? $value : 2 - $value;
	}

	/** Φ⁻¹(p): Acklam's approximation, refined by one Newton step. */
	private static function normalQuantile(float $p): float {
		$a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
		$b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
		$c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
		$d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
		if ($p < 0.02425) {
			$q = sqrt(-2 * log($p));
			$z = ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
		} elseif ($p > 1 - 0.02425) {
			$q = sqrt(-2 * log(1 - $p));
			$z = -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
		} else {
			$q = $p - 0.5;
			$r = $q * $q;
			$z = ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
		}
		return $z - (self::normalCdf($z) - $p) / self::normalPdf($z);
	}

	public static function binomialPdf(int $n, float $p, int $k): float {
		if ($k < 0 || $k > $n || $p < 0 || $p > 1) {
			return 0.0;
		}
		if ($p == 0.0 || $p == 1.0) {
			return (float)(($p == 0.0 && $k === 0) || ($p == 1.0 && $k === $n));
		}
		$choose = self::choose($n, $k);
		// Directly while the binomial coefficient is a float; through logarithms beyond.
		return is_finite($choose) && $choose < 1e300
			? $choose * $p ** $k * (1 - $p) ** ($n - $k)
			: exp(self::logFactorial($n) - self::logFactorial($k) - self::logFactorial($n - $k) + $k * log($p) + ($n - $k) * log(1 - $p));
	}

	private static function logFactorial(int $n): float {
		$sum = 0.0;
		for ($i = 2; $i <= $n; $i++) {
			$sum += log($i);
		}
		return $sum;
	}

	/** sin(pi) is 1.2e-16 in floating point: 0. */
	private static function clean(float $x): float {
		return abs($x) < 1e-12 ? 0.0 : $x;
	}

	private static function factorial(float $n): float {
		if ($n < 0 || floor($n) != $n) {
			throw new \InvalidArgumentException('factorial of ' . self::format($n) . ' (only whole numbers ≥ 0)');
		}
		if ($n > 170) {
			throw new \InvalidArgumentException('factorial too large');
		}
		$result = 1.0;
		for ($i = 2; $i <= $n; $i++) {
			$result *= $i;
		}
		return $result;
	}

	private static function choose(float $n, float $k): float {
		if ($k < 0 || $k > $n || floor($k) != $k || floor($n) != $n) {
			return 0.0;
		}
		$k = min($k, $n - $k);
		$result = 1.0;
		for ($i = 1; $i <= $k; $i++) {
			$result = $result * ($n - $k + $i) / $i;
		}
		return round($result);
	}

	private static function gcd(int $a, int $b): int {
		while ($b !== 0) {
			[$a, $b] = [$b, $a % $b];
		}
		return $a;
	}

	/** At most 15 significant digits, without float noise (0.1 + 0.2 = 0.3). */
	public static function format(float $value): string {
		if ($value == floor($value) && abs($value) < 1e15) {
			return number_format($value, 0, '.', '');
		}
		$text = sprintf('%.15G', $value);
		if (str_contains($text, 'E')) {
			[$mantissa, $exponent] = explode('E', $text);
			return rtrim(rtrim($mantissa, '0'), '.') . ' × 10^' . (int)$exponent;
		}
		return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
	}

	/** A non-integer that is a simple fraction (denominator up to 1000), as "p/q"; else null. */
	public static function fraction(float $value): ?string {
		if ($value == floor($value) || abs($value) > 1e9) {
			return null;
		}
		$sign = $value < 0 ? '-' : '';
		$x = abs($value);
		[$h0, $h1, $k0, $k1] = [0, 1, 1, 0];
		$y = $x;
		for ($i = 0; $i < 20; $i++) {
			$a = (int)floor($y);
			[$h0, $h1] = [$h1, $a * $h1 + $h0];
			[$k0, $k1] = [$k1, $a * $k1 + $k0];
			if ($k1 > 1000) {
				return null;
			}
			if (abs($x - $h1 / $k1) < 1e-12 * max(1, $x)) {
				return $sign . $h1 . '/' . $k1;
			}
			$fractional = $y - $a;
			if ($fractional < 1e-15) {
				return null;
			}
			$y = 1 / $fractional;
		}
		return null;
	}
}
