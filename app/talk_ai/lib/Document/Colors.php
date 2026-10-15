<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Colour for painters: the palette of an image (its dominant colours with
 * shares), harmonies of a colour (complementary, analogous, triadic,
 * split-complementary, tetradic, tints and shades), each colour as hex, RGB,
 * HSL and CMYK with the nearest colour name, and a swatch sheet (SVG).
 */
class Colors {
	/** Names for the nearest-colour lookup: CSS colours and a few painters' pigments. */
	private const NAMES = [
		'black' => '000000', 'white' => 'ffffff', 'gray' => '808080', 'silver' => 'c0c0c0', 'dim gray' => '696969', 'gainsboro' => 'dcdcdc',
		'red' => 'ff0000', 'dark red' => '8b0000', 'crimson' => 'dc143c', 'firebrick' => 'b22222', 'indian red' => 'cd5c5c', 'salmon' => 'fa8072',
		'coral' => 'ff7f50', 'tomato' => 'ff6347', 'orange red' => 'ff4500', 'orange' => 'ffa500', 'dark orange' => 'ff8c00', 'gold' => 'ffd700',
		'yellow' => 'ffff00', 'khaki' => 'f0e68c', 'lemon chiffon' => 'fffacd', 'olive' => '808000', 'yellow green' => '9acd32', 'olive drab' => '6b8e23',
		'lime green' => '32cd32', 'green' => '008000', 'dark green' => '006400', 'forest green' => '228b22', 'sea green' => '2e8b57', 'spring green' => '00ff7f',
		'mint' => '98ff98', 'teal' => '008080', 'turquoise' => '40e0d0', 'aquamarine' => '7fffd4', 'cyan' => '00ffff', 'cadet blue' => '5f9ea0',
		'sky blue' => '87ceeb', 'light blue' => 'add8e6', 'steel blue' => '4682b4', 'royal blue' => '4169e1', 'blue' => '0000ff', 'navy' => '000080',
		'midnight blue' => '191970', 'slate blue' => '6a5acd', 'indigo' => '4b0082', 'purple' => '800080', 'violet' => 'ee82ee', 'orchid' => 'da70d6',
		'plum' => 'dda0dd', 'magenta' => 'ff00ff', 'deep pink' => 'ff1493', 'hot pink' => 'ff69b4', 'pink' => 'ffc0cb', 'lavender' => 'e6e6fa',
		'brown' => 'a52a2a', 'saddle brown' => '8b4513', 'sienna' => 'a0522d', 'chocolate' => 'd2691e', 'peru' => 'cd853f', 'tan' => 'd2b48c',
		'beige' => 'f5f5dc', 'wheat' => 'f5deb3', 'ivory' => 'fffff0', 'linen' => 'faf0e6', 'maroon' => '800000', 'rosy brown' => 'bc8f8f',
		'slate gray' => '708090', 'dark slate gray' => '2f4f4f',
		'ultramarine' => '120a8f', 'cobalt blue' => '0047ab', 'prussian blue' => '003153', 'cerulean' => '007ba7', 'phthalo green' => '123524',
		'viridian' => '40826d', 'sap green' => '507d2a', 'cadmium yellow' => 'fff600', 'yellow ochre' => 'cb9d06', 'naples yellow' => 'fada5e',
		'cadmium red' => 'e30022', 'alizarin crimson' => 'e32636', 'vermilion' => 'e34234', 'burnt sienna' => 'e97451', 'raw sienna' => 'd68a59',
		'burnt umber' => '8a3324', 'raw umber' => '826644', 'payne\'s grey' => '536878', 'titanium white' => 'f4f6f0', 'ivory black' => '231f20',
		'terracotta' => 'e2725b', 'sage' => 'bcb88a', 'mauve' => 'e0b0ff', 'teal blue' => '367588', 'rust' => 'b7410e',
	];

	/** The dominant colours of an image (Imagick quantises it), with their shares. */
	public static function palette(string $image, int $count = 6): array {
		if (!class_exists(\Imagick::class)) {
			throw new \RuntimeException('Imagick is not available on this server');
		}
		$count = max(2, min(12, $count));
		$im = new \Imagick();
		$im->readImageBlob($image);
		$im->setIteratorIndex(0);
		$im->thumbnailImage(240, 240, true);
		$im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
		// In Lab where Imagick has it (closer to how colours are seen), else in sRGB.
		$space = defined('Imagick::COLORSPACE_LAB') ? constant('Imagick::COLORSPACE_LAB') : \Imagick::COLORSPACE_SRGB;
		// More colours than asked, then near-identical ones merged by how they look: a few
		// clusters alone merge small but distinct areas (a red and a green) into a brown.
		$im->quantizeImage(min(32, $count * 4), $space, 0, false, false);
		$pixels = 0;
		$found = [];
		foreach ($im->getImageHistogram() as $pixel) {
			$c = $pixel->getColor();
			$n = $pixel->getColorCount();
			$hex = sprintf('%02x%02x%02x', $c['r'], $c['g'], $c['b']);
			$found[$hex] = ($found[$hex] ?? 0) + $n;
			$pixels += $n;
		}
		$im->clear();
		arsort($found);
		$clusters = [];
		foreach ($found as $hex => $n) {
			$lab = self::lab($hex);
			foreach ($clusters as &$c) {
				if (($c['lab'][0] - $lab[0]) ** 2 + ($c['lab'][1] - $lab[1]) ** 2 + ($c['lab'][2] - $lab[2]) ** 2 < 15 ** 2) {
					$c['n'] += $n;
					continue 2;
				}
			}
			unset($c);
			$clusters[] = ['hex' => $hex, 'lab' => $lab, 'n' => $n];
		}
		usort($clusters, fn ($a, $b) => $b['n'] <=> $a['n']);
		$out = [];
		foreach (array_slice($clusters, 0, $count) as $c) {
			$out[] = ['share' => round($c['n'] / $pixels * 100, 1) . ' %'] + self::describe($c['hex']);
		}
		return $out;
	}

	/** A colour's harmonies, by turning its hue (HSL), and its tints and shades. */
	public static function harmonies(string $color): array {
		$hex = self::hex($color);
		[$h, $s, $l] = self::hsl($hex);
		$turn = fn (float $degrees) => self::fromHsl(fmod($h + $degrees + 360, 360), $s, $l);
		$names = fn (array $hexes) => array_map(fn (string $x) => self::describe($x), $hexes);
		return [
			'color' => self::describe($hex),
			'complementary' => $names([$turn(180)]),
			'analogous' => $names([$turn(-30), $turn(30)]),
			'triadic' => $names([$turn(120), $turn(240)]),
			'split_complementary' => $names([$turn(150), $turn(210)]),
			'tetradic' => $names([$turn(90), $turn(180), $turn(270)]),
			'tints' => array_map(fn (float $f) => '#' . self::fromHsl($h, $s, $l + (1 - $l) * $f), [0.25, 0.5, 0.75]),
			'shades' => array_map(fn (float $f) => '#' . self::fromHsl($h, $s, $l * (1 - $f)), [0.25, 0.5, 0.75]),
			'note' => 'Harmonies on the RGB/HSL colour wheel; the painters\' RYB wheel puts complements differently (e.g. red–green rather than red–cyan).',
		];
	}

	/** Swatches as an SVG sheet: each colour with its hex and name. */
	public static function swatches(array $colors, string $title = ''): string {
		$colors = array_slice(array_values($colors), 0, 16);
		$columns = min(4, max(1, count($colors)));
		$rows = (int)ceil(count($colors) / $columns);
		$w = 220;
		$top = $title !== '' ? 60 : 20;
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . ($columns * $w + 20) . '" height="' . ($top + $rows * 230) . '" font-family="DejaVu Sans, sans-serif">'
			. '<rect width="' . ($columns * $w + 20) . '" height="' . ($top + $rows * 230) . '" fill="#ffffff"/>'
			. ($title !== '' ? '<text x="20" y="40" font-size="24" font-weight="bold" fill="#333333">' . htmlspecialchars($title, ENT_XML1) . '</text>' : '');
		foreach ($colors as $i => $c) {
			$hex = self::hex(is_array($c) ? (string)($c['hex'] ?? '') : (string)$c);
			$d = self::describe($hex);
			$x = 20 + ($i % $columns) * $w;
			$y = $top + intdiv($i, $columns) * 230;
			$svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . ($w - 20) . '" height="150" rx="8" fill="#' . $hex . '" stroke="#dddddd"/>'
				. '<text x="' . $x . '" y="' . ($y + 175) . '" font-size="17" font-weight="bold" fill="#333333">#' . strtoupper($hex) . '</text>'
				. '<text x="' . $x . '" y="' . ($y + 197) . '" font-size="14" fill="#555555">' . htmlspecialchars($d['name'], ENT_XML1) . (is_array($c) && isset($c['share']) ? ' · ' . $c['share'] : '') . '</text>'
				. '<text x="' . $x . '" y="' . ($y + 216) . '" font-size="12" fill="#777777">' . $d['rgb'] . '</text>';
		}
		return $svg . '</svg>';
	}

	/** Hex, RGB, HSL, CMYK and the nearest name. */
	public static function describe(string $color): array {
		$hex = self::hex($color);
		[$r, $g, $b] = array_map('hexdec', str_split($hex, 2));
		[$h, $s, $l] = self::hsl($hex);
		$k = 1 - max($r, $g, $b) / 255;
		$cmyk = $k >= 1 ? [0, 0, 0, 100] : array_map(fn ($v) => (int)round($v * 100), [(1 - $r / 255 - $k) / (1 - $k), (1 - $g / 255 - $k) / (1 - $k), (1 - $b / 255 - $k) / (1 - $k), $k]);
		return ['hex' => '#' . strtoupper($hex), 'name' => self::nearest($hex), 'rgb' => "rgb($r, $g, $b)",
			'hsl' => sprintf('hsl(%d°, %d %%, %d %%)', round($h), round($s * 100), round($l * 100)), 'cmyk' => 'cmyk(' . implode(' %, ', $cmyk) . ' %)'];
	}

	/** The nearest named colour, by distance in a perceptual (Lab) space. */
	private static function nearest(string $hex): string {
		$lab = self::lab($hex);
		$best = '';
		$distance = INF;
		foreach (self::NAMES as $name => $value) {
			$other = self::lab($value);
			$d = ($lab[0] - $other[0]) ** 2 + ($lab[1] - $other[1]) ** 2 + ($lab[2] - $other[2]) ** 2;
			if ($d < $distance) {
				[$best, $distance] = [$name, $d];
			}
		}
		return $best;
	}

	private static function lab(string $hex): array {
		$rgb = array_map(function (string $c): float {
			$v = hexdec($c) / 255;
			return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
		}, str_split($hex, 2));
		$x = ($rgb[0] * 0.4124 + $rgb[1] * 0.3576 + $rgb[2] * 0.1805) / 0.95047;
		$y = $rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722;
		$z = ($rgb[0] * 0.0193 + $rgb[1] * 0.1192 + $rgb[2] * 0.9505) / 1.08883;
		$f = fn (float $t) => $t > 0.008856 ? $t ** (1 / 3) : 7.787 * $t + 16 / 116;
		return [116 * $f($y) - 16, 500 * ($f($x) - $f($y)), 200 * ($f($y) - $f($z))];
	}

	/** "#ff8800", "f80", "rgb(255,136,0)" or a name, as six hex digits. */
	public static function hex(string $color): string {
		$color = strtolower(trim($color));
		if (isset(self::NAMES[$color])) {
			return self::NAMES[$color];
		}
		if (preg_match('/^rgb\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/', $color, $m) === 1) {
			return sprintf('%02x%02x%02x', min(255, (int)$m[1]), min(255, (int)$m[2]), min(255, (int)$m[3]));
		}
		$color = ltrim($color, '#');
		if (preg_match('/^[0-9a-f]{3}$/', $color) === 1) {
			return $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}
		if (preg_match('/^[0-9a-f]{6}$/', $color) === 1) {
			return $color;
		}
		throw new \InvalidArgumentException('a colour as #RRGGBB, rgb(r, g, b) or a name');
	}

	/** @return array{float, float, float} hue in degrees, saturation and lightness 0–1 */
	private static function hsl(string $hex): array {
		[$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split($hex, 2));
		$max = max($r, $g, $b);
		$min = min($r, $g, $b);
		$l = ($max + $min) / 2;
		if ($max == $min) {
			return [0.0, 0.0, $l];
		}
		$d = $max - $min;
		$s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
		$h = match ($max) {
			$r => ($g - $b) / $d + ($g < $b ? 6 : 0),
			$g => ($b - $r) / $d + 2,
			default => ($r - $g) / $d + 4,
		};
		return [$h * 60, $s, $l];
	}

	private static function fromHsl(float $h, float $s, float $l): string {
		$c = (1 - abs(2 * $l - 1)) * $s;
		$x = $c * (1 - abs(fmod($h / 60, 2) - 1));
		$m = $l - $c / 2;
		[$r, $g, $b] = match ((int)floor($h / 60) % 6) {
			0 => [$c, $x, 0], 1 => [$x, $c, 0], 2 => [0, $c, $x], 3 => [0, $x, $c], 4 => [$x, 0, $c], default => [$c, 0, $x],
		};
		return sprintf('%02x%02x%02x', round(($r + $m) * 255), round(($g + $m) * 255), round(($b + $m) * 255));
	}
}
