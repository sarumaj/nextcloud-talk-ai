<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Unit conversion with dimension checks: SI units with prefixes (km, mA, µF,
 * kWh, GHz), compound units (km/h, m/s^2, g/cm^3, N*m, kg·m²/s²), common
 * non-SI ones (inch, foot, mile, pound, litre, bar, atm, eV, cal, hp, knot,
 * light year, …) and temperatures (°C, °F, K).
 */
class Units {
	/** Dimensions: length, mass, time, current, temperature, amount, luminosity, angle. */
	private const DIMS = 8;

	/** Unit => [factor to SI, [m, kg, s, A, K, mol, cd, rad]], and whether SI prefixes apply. */
	private const UNITS = [
		'm' => [1, [1, 0, 0, 0, 0, 0, 0, 0], true], 'g' => [1e-3, [0, 1, 0, 0, 0, 0, 0, 0], true], 's' => [1, [0, 0, 1, 0, 0, 0, 0, 0], true],
		'A' => [1, [0, 0, 0, 1, 0, 0, 0, 0], true], 'K' => [1, [0, 0, 0, 0, 1, 0, 0, 0], true], 'mol' => [1, [0, 0, 0, 0, 0, 1, 0, 0], true],
		'cd' => [1, [0, 0, 0, 0, 0, 0, 1, 0], true], 'rad' => [1, [0, 0, 0, 0, 0, 0, 0, 1], true],
		'Hz' => [1, [0, 0, -1, 0, 0, 0, 0, 0], true], 'N' => [1, [1, 1, -2, 0, 0, 0, 0, 0], true], 'Pa' => [1, [-1, 1, -2, 0, 0, 0, 0, 0], true],
		'J' => [1, [2, 1, -2, 0, 0, 0, 0, 0], true], 'W' => [1, [2, 1, -3, 0, 0, 0, 0, 0], true], 'C' => [1, [0, 0, 1, 1, 0, 0, 0, 0], true],
		'V' => [1, [2, 1, -3, -1, 0, 0, 0, 0], true], 'Ω' => [1, [2, 1, -3, -2, 0, 0, 0, 0], true], 'ohm' => [1, [2, 1, -3, -2, 0, 0, 0, 0], true],
		'F' => [1, [-2, -1, 4, 2, 0, 0, 0, 0], true], 'T' => [1, [0, 1, -2, -1, 0, 0, 0, 0], true], 'Wb' => [1, [2, 1, -2, -1, 0, 0, 0, 0], true],
		'H' => [1, [2, 1, -2, -2, 0, 0, 0, 0], true], 'S' => [1, [-2, -1, 3, 2, 0, 0, 0, 0], true], 'lm' => [1, [0, 0, 0, 0, 0, 0, 1, 0], true],
		'lx' => [1, [-2, 0, 0, 0, 0, 0, 1, 0], true], 'Bq' => [1, [0, 0, -1, 0, 0, 0, 0, 0], true], 'Gy' => [1, [2, 0, -2, 0, 0, 0, 0, 0], true],
		'Sv' => [1, [2, 0, -2, 0, 0, 0, 0, 0], true], 'Wh' => [3600, [2, 1, -3 + 1, 0, 0, 0, 0, 0], true], 'eV' => [1.602176634e-19, [2, 1, -2, 0, 0, 0, 0, 0], true],
		'L' => [1e-3, [3, 0, 0, 0, 0, 0, 0, 0], true], 'l' => [1e-3, [3, 0, 0, 0, 0, 0, 0, 0], true], 'bar' => [1e5, [-1, 1, -2, 0, 0, 0, 0, 0], true],
		'Ah' => [3600, [0, 0, 1, 1, 0, 0, 0, 0], true], 'cal' => [4.184, [2, 1, -2, 0, 0, 0, 0, 0], true], 't' => [1000, [0, 1, 0, 0, 0, 0, 0, 0], false],
		'min' => [60, [0, 0, 1, 0, 0, 0, 0, 0], false], 'h' => [3600, [0, 0, 1, 0, 0, 0, 0, 0], false], 'd' => [86400, [0, 0, 1, 0, 0, 0, 0, 0], false],
		'day' => [86400, [0, 0, 1, 0, 0, 0, 0, 0], false], 'week' => [604800, [0, 0, 1, 0, 0, 0, 0, 0], false],
		'year' => [31557600, [0, 0, 1, 0, 0, 0, 0, 0], false], 'a' => [31557600, [0, 0, 1, 0, 0, 0, 0, 0], false],
		'in' => [0.0254, [1, 0, 0, 0, 0, 0, 0, 0], false], 'inch' => [0.0254, [1, 0, 0, 0, 0, 0, 0, 0], false], '"' => [0.0254, [1, 0, 0, 0, 0, 0, 0, 0], false],
		'ft' => [0.3048, [1, 0, 0, 0, 0, 0, 0, 0], false], 'yd' => [0.9144, [1, 0, 0, 0, 0, 0, 0, 0], false], 'mi' => [1609.344, [1, 0, 0, 0, 0, 0, 0, 0], false],
		'nmi' => [1852, [1, 0, 0, 0, 0, 0, 0, 0], false], 'AU' => [1.495978707e11, [1, 0, 0, 0, 0, 0, 0, 0], false],
		'ly' => [9.4607304725808e15, [1, 0, 0, 0, 0, 0, 0, 0], false], 'pc' => [3.0856775814913673e16, [1, 0, 0, 0, 0, 0, 0, 0], false],
		'Å' => [1e-10, [1, 0, 0, 0, 0, 0, 0, 0], false], 'ha' => [1e4, [2, 0, 0, 0, 0, 0, 0, 0], false], 'acre' => [4046.8564224, [2, 0, 0, 0, 0, 0, 0, 0], false],
		'lb' => [0.45359237, [0, 1, 0, 0, 0, 0, 0, 0], false], 'oz' => [0.028349523125, [0, 1, 0, 0, 0, 0, 0, 0], false], 'st' => [6.35029318, [0, 1, 0, 0, 0, 0, 0, 0], false],
		'u' => [1.66053906660e-27, [0, 1, 0, 0, 0, 0, 0, 0], false], 'ct' => [2e-4, [0, 1, 0, 0, 0, 0, 0, 0], false],
		'gal' => [3.785411784e-3, [3, 0, 0, 0, 0, 0, 0, 0], false], 'floz' => [2.95735295625e-5, [3, 0, 0, 0, 0, 0, 0, 0], false],
		'cup' => [2.365882365e-4, [3, 0, 0, 0, 0, 0, 0, 0], false],
		'atm' => [101325, [-1, 1, -2, 0, 0, 0, 0, 0], false], 'mmHg' => [133.322387415, [-1, 1, -2, 0, 0, 0, 0, 0], false],
		'Torr' => [133.322368421, [-1, 1, -2, 0, 0, 0, 0, 0], false], 'psi' => [6894.757293168, [-1, 1, -2, 0, 0, 0, 0, 0], false],
		'hp' => [745.69987158227, [2, 1, -3, 0, 0, 0, 0, 0], false], 'PS' => [735.49875, [2, 1, -3, 0, 0, 0, 0, 0], false],
		'kn' => [1852 / 3600, [1, 0, -1, 0, 0, 0, 0, 0], false], 'mph' => [0.44704, [1, 0, -1, 0, 0, 0, 0, 0], false],
		'kmh' => [1 / 3.6, [1, 0, -1, 0, 0, 0, 0, 0], false], 'deg' => [M_PI / 180, [0, 0, 0, 0, 0, 0, 0, 1], false],
		'°' => [M_PI / 180, [0, 0, 0, 0, 0, 0, 0, 1], false], 'rev' => [2 * M_PI, [0, 0, 0, 0, 0, 0, 0, 1], false],
		'%' => [0.01, [0, 0, 0, 0, 0, 0, 0, 0], false], 'ppm' => [1e-6, [0, 0, 0, 0, 0, 0, 0, 0], false],
		'B' => [8, [0, 0, 0, 0, 0, 0, 0, 0], true], 'bit' => [1, [0, 0, 0, 0, 0, 0, 0, 0], true],
	];

	private const PREFIXES = ['Q' => 1e30, 'R' => 1e27, 'Y' => 1e24, 'Z' => 1e21, 'E' => 1e18, 'P' => 1e15, 'T' => 1e12, 'G' => 1e9, 'M' => 1e6,
		'k' => 1e3, 'h' => 1e2, 'da' => 1e1, 'd' => 1e-1, 'c' => 1e-2, 'm' => 1e-3, 'µ' => 1e-6, 'μ' => 1e-6, 'u' => 1e-6, 'n' => 1e-9,
		'p' => 1e-12, 'f' => 1e-15, 'a' => 1e-18, 'z' => 1e-21, 'y' => 1e-24, 'Ki' => 1024, 'Mi' => 1048576, 'Gi' => 1073741824, 'Ti' => 1099511627776];

	private const TEMPERATURES = ['°C' => 'C', 'degC' => 'C', '℃' => 'C', '°F' => 'F', 'degF' => 'F', '℉' => 'F', 'K' => 'K'];

	/** The value in another unit, with a check that both measure the same. */
	public static function convert(float $value, string $from, string $to): array {
		$from = trim($from);
		$to = trim($to);
		// Temperatures have an offset; plain C and F are coulomb and farad.
		$celsiusOrFahrenheit = fn (string $u): bool => in_array($u, ['°C', '°F', '℃', '℉', 'degC', 'degF'], true);
		if ($celsiusOrFahrenheit($from) || $celsiusOrFahrenheit($to)) {
			$kelvin = match (self::TEMPERATURES[$from] ?? '') {
				'C' => $value + 273.15,
				'F' => ($value - 32) * 5 / 9 + 273.15,
				'K' => $value,
				default => throw new \InvalidArgumentException('unknown temperature unit ' . $from),
			};
			$result = match (self::TEMPERATURES[$to] ?? '') {
				'C' => $kelvin - 273.15,
				'F' => ($kelvin - 273.15) * 9 / 5 + 32,
				'K' => $kelvin,
				default => throw new \InvalidArgumentException('a temperature converts only to °C, °F or K'),
			};
			return ['value' => Calculator::format(round($result, 10)), 'unit' => $to, 'text' => Calculator::format($value) . ' ' . $from . ' = ' . Calculator::format(round($result, 10)) . ' ' . $to];
		}
		[$f1, $d1] = self::parse($from);
		[$f2, $d2] = self::parse($to);
		if ($d1 !== $d2) {
			throw new \InvalidArgumentException($from . ' and ' . $to . ' do not measure the same thing');
		}
		$result = $value * $f1 / $f2;
		$shown = Calculator::format((float)sprintf('%.12g', $result));
		return ['value' => $shown, 'unit' => $to, 'text' => Calculator::format($value) . ' ' . $from . ' = ' . $shown . ' ' . $to];
	}

	/**
	 * A unit expression's factor to SI and its dimensions: "km/h", "kg*m^2/s^2", "g/cm³".
	 *
	 * @return array{float, list<int>}
	 */
	public static function parse(string $unit): array {
		$unit = strtr(trim($unit), ['²' => '^2', '³' => '^3', '⁻¹' => '^-1', '·' => '*', '⋅' => '*', '×' => '*', 'km/h' => 'kmh']);
		if ($unit === '' || $unit === '1') {
			return [1.0, array_fill(0, self::DIMS, 0)];
		}
		$factor = 1.0;
		$dims = array_fill(0, self::DIMS, 0);
		$sign = 1;
		foreach (preg_split('/\s*([*\/])\s*|\s+/', $unit, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
			if ($part === '*') {
				$sign = 1;
				continue;
			}
			if ($part === '/') {
				$sign = -1;
				continue;
			}
			$power = 1;
			if (preg_match('/^(.+?)\^?(-?\d+)$/u', $part, $m) === 1 && !isset(self::UNITS[$part])) {
				[$part, $power] = [$m[1], (int)$m[2]];
			}
			[$f, $d] = self::single($part);
			$factor *= $f ** ($power * $sign);
			foreach ($d as $i => $v) {
				$dims[$i] += $v * $power * $sign;
			}
			$sign = 1;
		}
		return [$factor, $dims];
	}

	/** @return array{float, list<int>} */
	private static function single(string $unit): array {
		if (isset(self::UNITS[$unit])) {
			return [(float)self::UNITS[$unit][0], self::UNITS[$unit][1]];
		}
		foreach (self::PREFIXES as $prefix => $p) {
			$rest = substr($unit, strlen($prefix));
			if (str_starts_with($unit, $prefix) && isset(self::UNITS[$rest]) && self::UNITS[$rest][2]) {
				return [$p * self::UNITS[$rest][0], self::UNITS[$rest][1]];
			}
		}
		throw new \InvalidArgumentException('unknown unit "' . $unit . '"');
	}
}
