<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Sport science numbers: heart-rate training zones (Karvonen), pace and speed,
 * race-time prediction (Riegel), BMI, and energy use by activity (MET).
 */
class Sport {
	/**
	 * Training zones by heart-rate reserve (Karvonen). The maximum, when not
	 * measured, is estimated as 208 − 0.7 × age (Tanaka).
	 */
	public static function heartRateZones(int $age, ?int $resting = null, ?int $maximum = null): array {
		$max = $maximum ?? (int)round(208 - 0.7 * $age);
		$rest = $resting ?? 60;
		$zone = fn (float $low, float $high) => (int)round($rest + ($max - $rest) * $low) . '–' . (int)round($rest + ($max - $rest) * $high) . ' bpm';
		return [
			'maximum' => $max . ' bpm' . ($maximum === null ? ' (estimated: 208 − 0.7 × age; a test measures it better)' : ''),
			'resting' => $rest . ' bpm' . ($resting === null ? ' (assumed; measure it in the morning)' : ''),
			'zones' => [
				'1 recovery (50–60 %)' => $zone(0.5, 0.6),
				'2 basic endurance (60–70 %)' => $zone(0.6, 0.7),
				'3 aerobic (70–80 %)' => $zone(0.7, 0.8),
				'4 threshold (80–90 %)' => $zone(0.8, 0.9),
				'5 maximum (90–100 %)' => $zone(0.9, 1.0),
			],
			'method' => 'Karvonen: resting + (maximum − resting) × intensity',
		];
	}

	/** Pace and speed from a distance (km) and a time ("25:30", "1:45:00" or minutes). */
	public static function pace(float $km, string $time): array {
		$seconds = self::seconds($time);
		if ($km <= 0 || $seconds <= 0) {
			throw new \InvalidArgumentException('distance and time must be positive');
		}
		return [
			'distance' => Calculator::format($km) . ' km',
			'time' => self::clock($seconds),
			'pace' => self::clock($seconds / $km) . ' min/km',
			'pace_per_mile' => self::clock($seconds / $km * 1.609344) . ' min/mi',
			'speed' => round($km / $seconds * 3600, 2) . ' km/h',
			'speed_m_s' => round($km * 1000 / $seconds, 2) . ' m/s',
		];
	}

	/** Riegel's prediction of a race time from another: t₂ = t₁ × (d₂/d₁)^1.06. */
	public static function predict(float $km, string $time, float $targetKm): array {
		$t = self::seconds($time) * ($targetKm / $km) ** 1.06;
		return ['prediction' => self::clock($t) . ' for ' . Calculator::format($targetKm) . ' km', 'pace' => self::clock($t / $targetKm) . ' min/km',
			'note' => 'Riegel\'s formula; it assumes training for the distance, and is less reliable beyond a marathon.'];
	}

	public static function bmi(float $kg, float $cm): array {
		$bmi = $kg / ($cm / 100) ** 2;
		$category = match (true) {
			$bmi < 18.5 => 'underweight',
			$bmi < 25 => 'normal weight',
			$bmi < 30 => 'overweight',
			default => 'obesity',
		};
		return ['bmi' => round($bmi, 1), 'category_adults' => $category,
			'note' => 'For adults (WHO). Under 18, BMI is judged by age percentiles; it does not tell muscle from fat.'];
	}

	/** Energy used: MET × 3.5 × kg / 200 per minute. */
	public static function calories(float $met, float $kg, float $minutes): array {
		$kcal = $met * 3.5 * $kg / 200 * $minutes;
		return ['kcal' => (int)round($kcal), 'kJ' => (int)round($kcal * 4.184), 'formula' => 'MET × 3.5 × kg / 200 × minutes',
			'examples_met' => 'walking 3.5, cycling leisure 6, running 10 km/h 9.8, swimming crawl 8, football 7, yoga 2.5'];
	}

	private static function seconds(string $time): float {
		$time = trim($time);
		if (preg_match('/^(\d+):(\d{1,2})(?::(\d{1,2}(?:\.\d+)?))?$/', $time, $m) === 1) {
			return isset($m[3]) ? $m[1] * 3600 + $m[2] * 60 + (float)$m[3] : $m[1] * 60 + (float)$m[2];
		}
		if (is_numeric($time)) {
			return (float)$time * 60;
		}
		throw new \InvalidArgumentException('a time as "mm:ss", "h:mm:ss" or minutes');
	}

	private static function clock(float $seconds): string {
		$s = (int)round($seconds);
		return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
	}
}
