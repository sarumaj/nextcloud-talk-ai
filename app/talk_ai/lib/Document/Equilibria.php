<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Chemical equilibria computed exactly rather than by the school's shortcuts alone:
 * the pH of strong and weak acids and bases and of buffers (the quadratic, with the
 * usual approximation beside it), titration curves of a monoprotic acid or base
 * from the charge balance, and the law of mass action (Kc from concentrations, or
 * the concentrations at equilibrium from Kc and the start, by the extent of reaction).
 * At 25 °C: Kw = 10⁻¹⁴.
 */
class Equilibria {
	private const KW = 1e-14;

	/**
	 * The pH of a solution. kind: strong_acid, strong_base, weak_acid, weak_base
	 * (pk: pKs or pKb), buffer (pk: pKs of the acid, c the acid, c_base its base).
	 */
	public static function ph(string $kind, float $c, ?float $pk = null, ?float $cBase = null): array {
		if ($c <= 0) {
			throw new \InvalidArgumentException('the concentration must be positive (mol/L)');
		}
		$weak = in_array($kind, ['weak_acid', 'weak_base', 'buffer'], true);
		if ($weak && $pk === null) {
			throw new \InvalidArgumentException('a weak acid, base or buffer needs pk (pKs or pKb)');
		}
		$out = ['kind' => $kind, 'c' => $c . ' mol/L'];
		switch ($kind) {
			case 'strong_acid':
			case 'strong_base':
				// With water's own ions, which matter only below about 10⁻⁶ mol/L.
				$x = ($c + sqrt($c * $c + 4 * self::KW)) / 2;
				$p = -log10($x);
				$out += ['pH' => self::r($kind === 'strong_acid' ? $p : 14 - $p), 'formula' => $kind === 'strong_acid' ? 'pH = −lg c(H₃O⁺) = −lg c' : 'pOH = −lg c, pH = 14 − pOH'];
				break;
			case 'weak_acid':
			case 'weak_base':
				$k = 10 ** -$pk;
				$x = (-$k + sqrt($k * $k + 4 * $k * $c)) / 2;
				$p = -log10($x);
				$approx = 0.5 * ($pk - log10($c));
				$acid = $kind === 'weak_acid';
				$out += ['pH' => self::r($acid ? $p : 14 - $p), 'pH_approximation' => self::r($acid ? $approx : 14 - $approx),
					'degree_of_protolysis' => self::r($x / $c * 100) . ' %',
					'formula' => ($acid ? 'Ks' : 'Kb') . ' = x² / (c − x), solved exactly: x = (−K + √(K² + 4Kc)) / 2; approximation ' . ($acid ? 'pH' : 'pOH') . ' = ½ (p' . ($acid ? 'Ks' : 'Kb') . ' − lg c), good while the degree of protolysis stays small'];
				break;
			case 'buffer':
				if ($cBase === null || $cBase <= 0) {
					throw new \InvalidArgumentException('a buffer needs c_base, the concentration of the corresponding base');
				}
				$out += ['pH' => self::r($pk + log10($cBase / $c)), 'formula' => 'Henderson-Hasselbalch: pH = pKs + lg (c(A⁻) / c(HA))'];
				break;
			default:
				throw new \InvalidArgumentException('kind: strong_acid, strong_base, weak_acid, weak_base or buffer');
		}
		return $out;
	}

	/**
	 * A titration of a monoprotic acid with a strong base (or a base with a strong acid:
	 * analyte "base"): the pH at each added volume from the charge balance, and the
	 * start, half-equivalence and equivalence points. pk null: a strong analyte.
	 */
	public static function titration(string $analyte, float $c, float $volume, float $cTitrant, ?float $pk = null, ?float $until = null): array {
		if ($c <= 0 || $volume <= 0 || $cTitrant <= 0) {
			throw new \InvalidArgumentException('concentrations (mol/L) and the volume (mL) must be positive');
		}
		$base = $analyte === 'base';
		// The analyte's acid constant (of the acid, or of the base's conjugate acid); strong: very large or very small.
		$ka = $pk === null ? ($base ? 1e-30 : 1e30) : ($base ? self::KW / 10 ** -$pk : 10 ** -$pk);
		$equivalence = $c * $volume / $cTitrant;
		$until ??= 2 * $equivalence;
		$ph = fn (float $added) => self::chargeBalance($base, $c * $volume / ($volume + $added), $cTitrant * $added / ($volume + $added), $ka);
		$points = [];
		$steps = 40;
		for ($i = 0; $i <= $steps; $i++) {
			$v = $until * $i / $steps;
			$points[] = [round($v, 3), round($ph($v), 2)];
		}
		$key = ['start' => ['mL' => 0, 'pH' => self::r($ph(0))], 'equivalence' => ['mL' => self::r($equivalence), 'pH' => self::r($ph($equivalence))]];
		if ($pk !== null) {
			$key['half_equivalence'] = ['mL' => self::r($equivalence / 2), 'pH' => self::r($ph($equivalence / 2)), 'note' => $base ? 'pOH ≈ pKb here' : 'pH ≈ pKs here'];
		}
		return ['titration' => ($pk === null ? 'strong ' : 'weak ') . ($base ? 'base with a strong acid' : 'acid with a strong base'),
			'key_points' => $key, 'points' => $points,
			'plot' => 'plot type scatter or line with these points (x: added titrant in mL, y: pH)',
			'method' => 'charge balance c(H₃O⁺) + c(cation of the titrant) = c(OH⁻) + c(anion), solved for each volume; 25 °C'];
	}

	/** The pH from the charge balance of an analyte (total c) and the titrant's ions (t). */
	private static function chargeBalance(bool $base, float $c, float $t, float $ka): float {
		// f(h) rises with h; solved by bisection on lg h.
		$f = function (float $h) use ($base, $c, $t, $ka): float {
			$oh = self::KW / $h;
			return $base
				? $h + $c * $h / ($h + $ka) - $oh - $t      // H₃O⁺ + BH⁺ = OH⁻ + Cl⁻
				: $h + $t - $oh - $c * $ka / ($ka + $h);    // H₃O⁺ + Na⁺ = OH⁻ + A⁻
		};
		$lo = -15.0;
		$hi = 1.0;
		for ($i = 0; $i < 100; $i++) {
			$mid = ($lo + $hi) / 2;
			if ($f(10 ** $mid) > 0) {
				$hi = $mid;
			} else {
				$lo = $mid;
			}
		}
		return -($lo + $hi) / 2;
	}

	/**
	 * The law of mass action for a reaction like "N2 + 3 H2 <=> 2 NH3". With Kc and
	 * the start concentrations: those at equilibrium (by the extent of reaction);
	 * with concentrations at equilibrium only: Kc.
	 */
	public static function equilibrium(string $reaction, array $concentrations, ?float $kc = null): array {
		$species = self::species($reaction);
		$c = [];
		foreach ($species as $name => $nu) {
			$c[$name] = (float)($concentrations[$name] ?? 0);
			if ($c[$name] < 0) {
				throw new \InvalidArgumentException('concentrations cannot be negative');
			}
		}
		$q = function (array $at) use ($species): float {
			$num = $den = 1.0;
			foreach ($species as $name => $nu) {
				$nu > 0 ? $num *= $at[$name] ** $nu : $den *= $at[$name] ** -$nu;
			}
			return $den == 0 ? INF : $num / $den;
		};
		$expression = 'Kc = ' . implode(' · ', array_map(fn ($n, $nu) => 'c(' . $n . ')' . ($nu > 1 ? '^' . $nu : ''), array_keys(array_filter($species, fn ($v) => $v > 0)), array_filter($species, fn ($v) => $v > 0)))
			. ' / (' . implode(' · ', array_map(fn ($n, $nu) => 'c(' . $n . ')' . (-$nu > 1 ? '^' . -$nu : ''), array_keys(array_filter($species, fn ($v) => $v < 0)), array_filter($species, fn ($v) => $v < 0))) . ')';
		if ($kc === null) {
			return ['reaction' => Chemistry::pretty($reaction), 'law' => $expression, 'Kc' => self::r($q($c))];
		}
		// The extent x moves between the limits that keep every concentration ≥ 0.
		$lo = -INF;
		$hi = INF;
		foreach ($species as $name => $nu) {
			$nu > 0 ? $lo = max($lo, -$c[$name] / $nu) : $hi = min($hi, $c[$name] / -$nu);
		}
		$at = fn (float $x) => array_map(fn ($name) => max(0.0, $c[$name] + $species[$name] * $x), array_combine(array_keys($species), array_keys($species)));
		$g = fn (float $x) => log(max(1e-300, $q($at($x)))) - log($kc);
		for ($i = 0; $i < 200; $i++) {
			$mid = ($lo + $hi) / 2;
			$g($mid) > 0 ? $hi = $mid : $lo = $mid;
		}
		$x = ($lo + $hi) / 2;
		return ['reaction' => Chemistry::pretty($reaction), 'law' => $expression, 'Kc' => $kc, 'extent' => self::r($x) . ' mol/L',
			'start' => array_map(self::r(...), $c), 'equilibrium' => array_map(self::r(...), $at($x)),
			'check_Kc' => self::r($q($at($x))), 'method' => 'ICE table: c = c₀ + ν·x; x solved so that Q = Kc (for one unknown and small coefficients this is the quadratic the school solves)'];
	}

	/** @return array<string, int> each species with its stoichiometric number (reactants negative) */
	private static function species(string $reaction): array {
		$sides = preg_split('/\s*(?:<=>|<->|⇌|->|→|=)\s*/u', trim($reaction));
		if ($sides === false || count($sides) !== 2) {
			throw new \InvalidArgumentException('write the reaction as "A + 2 B <=> C"');
		}
		$out = [];
		foreach ($sides as $i => $side) {
			foreach (preg_split('/\s+\+\s+/', trim($side)) ?: [] as $term) {
				if (preg_match('/^(\d+)?\s*(\S.*)$/u', trim($term), $m) === 1) {
					$out[trim($m[2])] = ($out[trim($m[2])] ?? 0) + ($i === 0 ? -1 : 1) * (int)($m[1] !== '' ? $m[1] : 1);
				}
			}
		}
		return $out;
	}

	private static function r(float $v): string {
		return Calculator::format((float)sprintf('%.4g', $v));
	}
}
