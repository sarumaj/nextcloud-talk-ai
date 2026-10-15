<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Chemistry the model should not guess: elements (number, name, standard atomic
 * weight, period, group), molar masses and composition of formulas
 * ("Ca(OH)2", "CuSO4·5H2O", "SO4^2-"), and reaction equations balanced exactly
 * (the null space of the element and charge balance, in integers). Formulas
 * are written with Unicode subscripts and charges: H₂SO₄, SO₄²⁻.
 */
class Chemistry {
	/** Symbol => [atomic number, name, standard atomic weight (IUPAC, abridged; mass number of the longest-lived isotope where none)]. */
	public const ELEMENTS = [
		'H' => [1, 'Hydrogen', 1.008], 'He' => [2, 'Helium', 4.0026], 'Li' => [3, 'Lithium', 6.94], 'Be' => [4, 'Beryllium', 9.0122],
		'B' => [5, 'Boron', 10.81], 'C' => [6, 'Carbon', 12.011], 'N' => [7, 'Nitrogen', 14.007], 'O' => [8, 'Oxygen', 15.999],
		'F' => [9, 'Fluorine', 18.998], 'Ne' => [10, 'Neon', 20.180], 'Na' => [11, 'Sodium', 22.990], 'Mg' => [12, 'Magnesium', 24.305],
		'Al' => [13, 'Aluminium', 26.982], 'Si' => [14, 'Silicon', 28.085], 'P' => [15, 'Phosphorus', 30.974], 'S' => [16, 'Sulfur', 32.06],
		'Cl' => [17, 'Chlorine', 35.45], 'Ar' => [18, 'Argon', 39.95], 'K' => [19, 'Potassium', 39.098], 'Ca' => [20, 'Calcium', 40.078],
		'Sc' => [21, 'Scandium', 44.956], 'Ti' => [22, 'Titanium', 47.867], 'V' => [23, 'Vanadium', 50.942], 'Cr' => [24, 'Chromium', 51.996],
		'Mn' => [25, 'Manganese', 54.938], 'Fe' => [26, 'Iron', 55.845], 'Co' => [27, 'Cobalt', 58.933], 'Ni' => [28, 'Nickel', 58.693],
		'Cu' => [29, 'Copper', 63.546], 'Zn' => [30, 'Zinc', 65.38], 'Ga' => [31, 'Gallium', 69.723], 'Ge' => [32, 'Germanium', 72.630],
		'As' => [33, 'Arsenic', 74.922], 'Se' => [34, 'Selenium', 78.971], 'Br' => [35, 'Bromine', 79.904], 'Kr' => [36, 'Krypton', 83.798],
		'Rb' => [37, 'Rubidium', 85.468], 'Sr' => [38, 'Strontium', 87.62], 'Y' => [39, 'Yttrium', 88.906], 'Zr' => [40, 'Zirconium', 91.224],
		'Nb' => [41, 'Niobium', 92.906], 'Mo' => [42, 'Molybdenum', 95.95], 'Tc' => [43, 'Technetium', 98], 'Ru' => [44, 'Ruthenium', 101.07],
		'Rh' => [45, 'Rhodium', 102.91], 'Pd' => [46, 'Palladium', 106.42], 'Ag' => [47, 'Silver', 107.87], 'Cd' => [48, 'Cadmium', 112.41],
		'In' => [49, 'Indium', 114.82], 'Sn' => [50, 'Tin', 118.71], 'Sb' => [51, 'Antimony', 121.76], 'Te' => [52, 'Tellurium', 127.60],
		'I' => [53, 'Iodine', 126.90], 'Xe' => [54, 'Xenon', 131.29], 'Cs' => [55, 'Caesium', 132.91], 'Ba' => [56, 'Barium', 137.33],
		'La' => [57, 'Lanthanum', 138.91], 'Ce' => [58, 'Cerium', 140.12], 'Pr' => [59, 'Praseodymium', 140.91], 'Nd' => [60, 'Neodymium', 144.24],
		'Pm' => [61, 'Promethium', 145], 'Sm' => [62, 'Samarium', 150.36], 'Eu' => [63, 'Europium', 151.96], 'Gd' => [64, 'Gadolinium', 157.25],
		'Tb' => [65, 'Terbium', 158.93], 'Dy' => [66, 'Dysprosium', 162.50], 'Ho' => [67, 'Holmium', 164.93], 'Er' => [68, 'Erbium', 167.26],
		'Tm' => [69, 'Thulium', 168.93], 'Yb' => [70, 'Ytterbium', 173.05], 'Lu' => [71, 'Lutetium', 174.97], 'Hf' => [72, 'Hafnium', 178.49],
		'Ta' => [73, 'Tantalum', 180.95], 'W' => [74, 'Tungsten', 183.84], 'Re' => [75, 'Rhenium', 186.21], 'Os' => [76, 'Osmium', 190.23],
		'Ir' => [77, 'Iridium', 192.22], 'Pt' => [78, 'Platinum', 195.08], 'Au' => [79, 'Gold', 196.97], 'Hg' => [80, 'Mercury', 200.59],
		'Tl' => [81, 'Thallium', 204.38], 'Pb' => [82, 'Lead', 207.2], 'Bi' => [83, 'Bismuth', 208.98], 'Po' => [84, 'Polonium', 209],
		'At' => [85, 'Astatine', 210], 'Rn' => [86, 'Radon', 222], 'Fr' => [87, 'Francium', 223], 'Ra' => [88, 'Radium', 226],
		'Ac' => [89, 'Actinium', 227], 'Th' => [90, 'Thorium', 232.04], 'Pa' => [91, 'Protactinium', 231.04], 'U' => [92, 'Uranium', 238.03],
		'Np' => [93, 'Neptunium', 237], 'Pu' => [94, 'Plutonium', 244], 'Am' => [95, 'Americium', 243], 'Cm' => [96, 'Curium', 247],
		'Bk' => [97, 'Berkelium', 247], 'Cf' => [98, 'Californium', 251], 'Es' => [99, 'Einsteinium', 252], 'Fm' => [100, 'Fermium', 257],
		'Md' => [101, 'Mendelevium', 258], 'No' => [102, 'Nobelium', 259], 'Lr' => [103, 'Lawrencium', 262], 'Rf' => [104, 'Rutherfordium', 267],
		'Db' => [105, 'Dubnium', 268], 'Sg' => [106, 'Seaborgium', 269], 'Bh' => [107, 'Bohrium', 270], 'Hs' => [108, 'Hassium', 269],
		'Mt' => [109, 'Meitnerium', 278], 'Ds' => [110, 'Darmstadtium', 281], 'Rg' => [111, 'Roentgenium', 282], 'Cn' => [112, 'Copernicium', 285],
		'Nh' => [113, 'Nihonium', 286], 'Fl' => [114, 'Flerovium', 289], 'Mc' => [115, 'Moscovium', 290], 'Lv' => [116, 'Livermorium', 293],
		'Ts' => [117, 'Tennessine', 294], 'Og' => [118, 'Oganesson', 294],
	];

	private const SUB = ['0' => '₀', '1' => '₁', '2' => '₂', '3' => '₃', '4' => '₄', '5' => '₅', '6' => '₆', '7' => '₇', '8' => '₈', '9' => '₉'];
	private const SUP = ['0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹', '+' => '⁺', '-' => '⁻'];

	/** An element by symbol, name (English) or atomic number. */
	public static function element(string $query): array {
		$query = trim($query);
		foreach (self::ELEMENTS as $symbol => [$z, $name, $mass]) {
			if ($query === $symbol || strcasecmp($query, $name) === 0 || $query === (string)$z || (strcasecmp($query, 'aluminum') === 0 && $symbol === 'Al')
				|| (strcasecmp($query, 'cesium') === 0 && $symbol === 'Cs') || (strcasecmp($query, 'sulphur') === 0 && $symbol === 'S')) {
				[$period, $group] = self::position($z);
				return ['symbol' => $symbol, 'name' => $name, 'atomic_number' => $z, 'atomic_mass' => $mass,
					'period' => $period, 'group' => $group, 'block' => self::block($z)];
			}
		}
		throw new \InvalidArgumentException('no element "' . $query . '" (use the symbol, the English name or the atomic number)');
	}

	/** Period and group (null for lanthanides and actinides) of an atomic number. */
	private static function position(int $z): array {
		foreach ([[2, 1], [10, 2], [18, 3], [36, 4], [54, 5], [86, 6], [118, 7]] as [$last, $period]) {
			if ($z <= $last) {
				break;
			}
		}
		$first = [1 => 1, 2 => 3, 3 => 11, 4 => 19, 5 => 37, 6 => 55, 7 => 87][$period];
		$i = $z - $first;
		$group = match (true) {
			$period === 1 => $z === 1 ? 1 : 18,
			$period <= 3 => $i < 2 ? $i + 1 : $i + 11,
			$period <= 5 => $i + 1,
			default => $i < 2 ? $i + 1 : ($i < 17 ? ($i === 2 ? 3 : null) : $i - 13),
		};
		return [$period, $group];
	}

	private static function block(int $z): string {
		return match (true) {
			in_array($z, [1, 2, 3, 4, 11, 12, 19, 20, 37, 38, 55, 56, 87, 88], true) => $z === 2 ? 's' : 's',
			($z >= 57 && $z <= 70) || ($z >= 89 && $z <= 102) => 'f',
			($z >= 21 && $z <= 30) || ($z >= 39 && $z <= 48) || ($z >= 71 && $z <= 80) || ($z >= 103 && $z <= 112) => 'd',
			default => 'p',
		};
	}

	/**
	 * A formula's atoms and charge: "Ca(OH)2" => [[Ca => 1, O => 2, H => 2], 0].
	 *
	 * @return array{array<string, int>, int}
	 */
	public static function parse(string $formula): array {
		$formula = trim(strtr($formula, ['₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4', '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9',
			'⁺' => '+', '⁻' => '-', '⁰' => '0', '¹' => '1', '²' => '2', '³' => '3', '⁴' => '4', '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9',
			'·' => '*', '•' => '*', '∙' => '*', '[' => '(', ']' => ')', '{' => '(', '}' => ')', '−' => '-']));
		$charge = 0;
		// A charge at the end: "^2-", "2+", "+", "3-" after a letter or bracket, "(aq)" and the like ignored.
		$formula = preg_replace('/\((s|l|g|aq)\)$/', '', $formula) ?? $formula;
		if (preg_match('/\^?(\d*)([+-])$/', $formula, $m) === 1 && strlen($formula) > strlen($m[0])) {
			$charge = ($m[2] === '+' ? 1 : -1) * ($m[1] === '' ? 1 : (int)$m[1]);
			$formula = substr($formula, 0, -strlen($m[0]));
			// "Fe3+": the 3 is the charge, not an index; "SO4 2-" or "SO4^2-" likewise.
		}
		$total = [];
		foreach (preg_split('/\s*[*.]\s*(?=\d*[A-Z(])/', $formula) ?: [] as $part) {
			$factor = 1;
			if (preg_match('/^(\d+)(.*)$/', $part, $m) === 1) {
				$factor = (int)$m[1];
				$part = $m[2];
			}
			$at = 0;
			foreach (self::group($part, $at) as $element => $n) {
				$total[$element] = ($total[$element] ?? 0) + $n * $factor;
			}
			if ($at < strlen($part)) {
				throw new \InvalidArgumentException('cannot read "' . substr($part, $at) . '" in ' . $formula);
			}
		}
		if ($total === []) {
			throw new \InvalidArgumentException('no formula');
		}
		return [$total, $charge];
	}

	/** @return array<string, int> */
	private static function group(string $s, int &$at): array {
		$counts = [];
		while ($at < strlen($s)) {
			$c = $s[$at];
			if ($c === '(') {
				$at++;
				$inner = self::group($s, $at);
				if (($s[$at] ?? '') !== ')') {
					throw new \InvalidArgumentException('a bracket is not closed in ' . $s);
				}
				$at++;
				$n = self::number($s, $at);
				foreach ($inner as $e => $k) {
					$counts[$e] = ($counts[$e] ?? 0) + $k * $n;
				}
			} elseif ($c === ')') {
				return $counts;
			} elseif (preg_match('/^[A-Z][a-z]?/', substr($s, $at), $m) === 1) {
				$symbol = isset(self::ELEMENTS[$m[0]]) ? $m[0] : $m[0][0];
				if (!isset(self::ELEMENTS[$symbol])) {
					throw new \InvalidArgumentException('no element "' . $m[0] . '"');
				}
				$at += strlen($symbol);
				$counts[$symbol] = ($counts[$symbol] ?? 0) + self::number($s, $at);
			} else {
				return $counts;
			}
		}
		return $counts;
	}

	private static function number(string $s, int &$at): int {
		if (preg_match('/^\d+/', substr($s, $at), $m) === 1) {
			$at += strlen($m[0]);
			return (int)$m[0];
		}
		return 1;
	}

	/** Molar mass and composition by mass. */
	public static function molarMass(string $formula): array {
		[$atoms, $charge] = self::parse($formula);
		$mass = 0.0;
		foreach ($atoms as $e => $n) {
			$mass += self::ELEMENTS[$e][2] * $n;
		}
		$composition = [];
		foreach ($atoms as $e => $n) {
			$composition[$e] = ['atoms' => $n, 'mass_percent' => round(self::ELEMENTS[$e][2] * $n / $mass * 100, 2)];
		}
		return ['formula' => self::pretty($formula), 'molar_mass' => round($mass, 3) . ' g/mol', 'charge' => $charge, 'composition' => $composition];
	}

	/**
	 * A reaction equation balanced: the smallest whole coefficients that keep
	 * every element and the charge. "Fe + O2 -> Fe2O3" => "4 Fe + 3 O₂ → 2 Fe₂O₃".
	 */
	public static function balance(string $equation): array {
		$sides = preg_split('/\s*(?:<=>|<->|⇌|⟶|->|→|=)\s*/u', trim($equation));
		if ($sides === false || count($sides) !== 2) {
			throw new \InvalidArgumentException('write the reaction as "reactants -> products"');
		}
		$species = [];
		foreach ($sides as $side => $text) {
			foreach (preg_split('/\s+\+\s+/', trim($text)) ?: [] as $s) {
				$s = preg_replace('/^\d+\s*(?=[A-Z(\[])/', '', trim($s)) ?? $s;
				if ($s !== '') {
					$species[] = ['formula' => $s, 'side' => $side, 'parsed' => self::parse($s)];
				}
			}
		}
		$elements = [];
		foreach ($species as $s) {
			$elements += array_fill_keys(array_keys($s['parsed'][0]), true);
		}
		// One row per element and one for the charge; products count negatively.
		$rows = [];
		foreach (array_keys($elements) as $e) {
			$rows[] = array_map(fn ($s) => ($s['side'] === 0 ? 1 : -1) * ($s['parsed'][0][$e] ?? 0), $species);
		}
		$rows[] = array_map(fn ($s) => ($s['side'] === 0 ? 1 : -1) * $s['parsed'][1], $species);
		$null = self::nullSpace($rows, count($species));
		if (count($null) !== 1) {
			throw new \InvalidArgumentException(count($null) === 0
				? 'this reaction cannot be balanced (check the formulas)'
				: 'this reaction has several independent balances: give more detail (e.g. which products form)');
		}
		$coefficients = $null[0];
		if (min($coefficients) <= 0) {
			throw new \InvalidArgumentException('no balance with all substances taking part: check the formulas');
		}
		$out = [[], []];
		foreach ($species as $i => $s) {
			$out[$s['side']][] = ($coefficients[$i] === 1 ? '' : $coefficients[$i] . ' ') . self::pretty($s['formula']);
		}
		return ['balanced' => implode(' + ', $out[0]) . ' → ' . implode(' + ', $out[1]),
			'coefficients' => array_combine(array_map(fn ($s) => $s['formula'], $species), $coefficients)];
	}

	/**
	 * The null space of an integer matrix, as integer vectors (exact: fractions
	 * of integers throughout).
	 *
	 * @return list<list<int>>
	 */
	private static function nullSpace(array $rows, int $columns): array {
		$m = array_map(fn (array $r) => array_map(fn (int $v) => [$v, 1], $r), $rows);
		$pivots = [];
		$r = 0;
		for ($c = 0; $c < $columns && $r < count($m); $c++) {
			$p = null;
			for ($i = $r; $i < count($m); $i++) {
				if ($m[$i][$c][0] !== 0) {
					$p = $i;
					break;
				}
			}
			if ($p === null) {
				continue;
			}
			[$m[$r], $m[$p]] = [$m[$p], $m[$r]];
			$pivot = $m[$r][$c];
			$m[$r] = array_map(fn ($v) => self::div($v, $pivot), $m[$r]);
			for ($i = 0; $i < count($m); $i++) {
				if ($i !== $r && $m[$i][$c][0] !== 0) {
					$f = $m[$i][$c];
					foreach ($m[$i] as $k => $v) {
						$m[$i][$k] = self::sub($v, self::mul($f, $m[$r][$k]));
					}
				}
			}
			$pivots[$c] = $r++;
		}
		$vectors = [];
		for ($free = 0; $free < $columns; $free++) {
			if (isset($pivots[$free])) {
				continue;
			}
			$v = array_fill(0, $columns, [0, 1]);
			$v[$free] = [1, 1];
			foreach ($pivots as $c => $row) {
				$v[$c] = self::mul([-1, 1], $m[$row][$free]);
			}
			// Whole numbers: times the denominators' lcm, divided by the gcd; positive.
			$lcm = 1;
			foreach ($v as [, $d]) {
				$lcm = intdiv($lcm * $d, self::gcd($lcm, $d));
			}
			$ints = array_map(fn ($x) => intdiv($x[0] * $lcm, $x[1]), $v);
			$g = array_reduce($ints, fn ($g, $x) => self::gcd($g, abs($x)), 0) ?: 1;
			$ints = array_map(fn ($x) => intdiv($x, $g), $ints);
			if (array_sum($ints) < 0) {
				$ints = array_map(fn ($x) => -$x, $ints);
			}
			$vectors[] = $ints;
		}
		return $vectors;
	}

	private static function norm(int $n, int $d): array {
		if ($d < 0) {
			[$n, $d] = [-$n, -$d];
		}
		$g = self::gcd(abs($n), $d) ?: 1;
		return [intdiv($n, $g), intdiv($d, $g)];
	}

	private static function mul(array $a, array $b): array {
		return self::norm($a[0] * $b[0], $a[1] * $b[1]);
	}

	private static function div(array $a, array $b): array {
		return self::norm($a[0] * $b[1], $a[1] * $b[0]);
	}

	private static function sub(array $a, array $b): array {
		return self::norm($a[0] * $b[1] - $b[0] * $a[1], $a[1] * $b[1]);
	}

	private static function gcd(int $a, int $b): int {
		while ($b !== 0) {
			[$a, $b] = [$b, $a % $b];
		}
		return $a;
	}

	/**
	 * A formula or equation with subscripts and charges: H2SO4 → H₂SO₄, SO4^2- → SO₄²⁻,
	 * "->" → "→". Coefficients (a number at a species' start) stay as they are.
	 */
	public static function pretty(string $text): string {
		$text = strtr($text, ['<=>' => '⇌', '<->' => '⇌', '->' => '→']);
		// Charges: ^2-, ^+, and a final + or - right after an element, a digit or a bracket.
		$text = preg_replace_callback('/\^(\d*[+-])|(?<=[A-Za-z\d)\]])(\d?[+-])(?=\s|$|\))/', fn (array $m) => strtr($m[1] !== '' ? $m[1] : $m[2], self::SUP), $text) ?? $text;
		// Indices: digits after an element or a closing bracket.
		return preg_replace_callback('/(?<=[A-Za-z)\]])\d+/', fn (array $m) => strtr($m[0], self::SUB), $text) ?? $text;
	}
}
