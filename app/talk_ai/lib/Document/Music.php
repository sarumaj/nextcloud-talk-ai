<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Music theory with correct spelling: scales and modes, chords, the interval
 * between two notes, transposition, and the frequency of a note (A4 = 440 Hz,
 * equal temperament). Notes are written C, C#, Db, … with an optional octave
 * (C4 is middle C); German names are understood too (H, Fis, Es, As).
 */
class Music {
	private const LETTERS = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11];
	private const ORDER = ['C', 'D', 'E', 'F', 'G', 'A', 'B'];

	/** Scale steps in semitones, and the letter steps (always 1 for seven-note scales). */
	private const SCALES = [
		'major' => [0, 2, 4, 5, 7, 9, 11], 'minor' => [0, 2, 3, 5, 7, 8, 10], 'harmonic minor' => [0, 2, 3, 5, 7, 8, 11],
		'melodic minor' => [0, 2, 3, 5, 7, 9, 11], 'dorian' => [0, 2, 3, 5, 7, 9, 10], 'phrygian' => [0, 1, 3, 5, 7, 8, 10],
		'lydian' => [0, 2, 4, 6, 7, 9, 11], 'mixolydian' => [0, 2, 4, 5, 7, 9, 10], 'locrian' => [0, 1, 3, 5, 6, 8, 10],
		'major pentatonic' => [0, 2, 4, 7, 9], 'minor pentatonic' => [0, 3, 5, 7, 10], 'blues' => [0, 3, 5, 6, 7, 10],
	];
	/** Letter offsets for the scales that skip letters. */
	private const SCALE_LETTERS = ['major pentatonic' => [0, 1, 2, 4, 5], 'minor pentatonic' => [0, 2, 3, 4, 6], 'blues' => [0, 2, 3, 4, 4, 6]];

	/** Chords: [semitones, letter steps] of each tone above the root. */
	private const CHORDS = [
		'major' => [[0, 0], [4, 2], [7, 4]], 'minor' => [[0, 0], [3, 2], [7, 4]], 'diminished' => [[0, 0], [3, 2], [6, 4]],
		'augmented' => [[0, 0], [4, 2], [8, 4]], 'sus2' => [[0, 0], [2, 1], [7, 4]], 'sus4' => [[0, 0], [5, 3], [7, 4]],
		'7' => [[0, 0], [4, 2], [7, 4], [10, 6]], 'maj7' => [[0, 0], [4, 2], [7, 4], [11, 6]], 'm7' => [[0, 0], [3, 2], [7, 4], [10, 6]],
		'm7b5' => [[0, 0], [3, 2], [6, 4], [10, 6]], 'dim7' => [[0, 0], [3, 2], [6, 4], [9, 6]], '6' => [[0, 0], [4, 2], [7, 4], [9, 5]],
		'm6' => [[0, 0], [3, 2], [7, 4], [9, 5]], '9' => [[0, 0], [4, 2], [7, 4], [10, 6], [14, 8]], 'add9' => [[0, 0], [4, 2], [7, 4], [14, 8]],
	];

	private const INTERVALS = [
		'0/0' => 'perfect unison', '1/1' => 'minor second', '2/1' => 'major second', '3/2' => 'minor third', '4/2' => 'major third',
		'5/3' => 'perfect fourth', '6/3' => 'augmented fourth (tritone)', '6/4' => 'diminished fifth (tritone)', '7/4' => 'perfect fifth',
		'8/5' => 'minor sixth', '9/5' => 'major sixth', '10/6' => 'minor seventh', '11/6' => 'major seventh', '12/7' => 'perfect octave',
		'3/1' => 'augmented second', '8/4' => 'augmented fifth', '9/6' => 'diminished seventh', '4/3' => 'diminished fourth', '1/0' => 'augmented unison',
	];

	public static function scale(string $root, string $kind = 'major'): array {
		$kind = strtolower(trim($kind)) ?: 'major';
		$kind = ['ionian' => 'major', 'aeolian' => 'minor', 'natural minor' => 'minor', 'dur' => 'major', 'moll' => 'minor'][$kind] ?? $kind;
		if (!isset(self::SCALES[$kind])) {
			throw new \InvalidArgumentException('scales: ' . implode(', ', array_keys(self::SCALES)));
		}
		[$letter, $pitch] = self::parse($root);
		$letters = self::SCALE_LETTERS[$kind] ?? range(0, 6);
		$notes = [];
		foreach (self::SCALES[$kind] as $i => $semitones) {
			$notes[] = self::spell($letter + $letters[$i], $pitch + $semitones);
		}
		return ['scale' => self::name($letter, $pitch) . ' ' . $kind, 'notes' => implode(' – ', $notes),
			'key_signature' => in_array($kind, ['major', 'minor'], true) ? self::signature($notes) : null];
	}

	public static function chord(string $root, string $kind = 'major'): array {
		$kind = trim($kind) === '' ? 'major' : trim($kind);
		$kind = ['maj' => 'major', 'm' => 'minor', 'min' => 'minor', 'dim' => 'diminished', 'aug' => 'augmented', '+' => 'augmented', 'dom7' => '7', 'ø' => 'm7b5', 'half-diminished' => 'm7b5'][$kind] ?? strtolower($kind);
		if (!isset(self::CHORDS[$kind])) {
			throw new \InvalidArgumentException('chords: ' . implode(', ', array_keys(self::CHORDS)));
		}
		[$letter, $pitch] = self::parse($root);
		$notes = array_map(fn ($t) => self::spell($letter + $t[1], $pitch + $t[0]), self::CHORDS[$kind]);
		return ['chord' => self::name($letter, $pitch) . ' ' . $kind, 'notes' => implode(' – ', $notes)];
	}

	public static function interval(string $from, string $to): array {
		[$l1, $p1, $o1] = self::parse($from, true);
		[$l2, $p2, $o2] = self::parse($to, true);
		$semitones = ($p2 + 12 * ($o2 ?? 4)) - ($p1 + 12 * ($o1 ?? 4));
		$steps = ($l2 + 7 * ($o2 ?? 4)) - ($l1 + 7 * ($o1 ?? 4));
		if ($o1 === null || $o2 === null) {
			// Without octaves: the interval upwards within an octave.
			$semitones = (($semitones % 12) + 12) % 12;
			$steps = (($steps % 7) + 7) % 7;
		}
		$key = abs($semitones) % 12 . '/' . abs($steps) % 7;
		$octaves = intdiv(abs($steps), 7);
		$name = self::INTERVALS[$key] ?? (abs($semitones) . ' semitones');
		if (abs($semitones) % 12 === 0 && abs($steps) % 7 === 0 && $octaves > 0) {
			$name = $octaves === 1 ? 'perfect octave' : $octaves . ' octaves';
		} elseif ($octaves > 0) {
			$name = 'compound ' . $name . ' (an octave and more)';
		}
		return ['interval' => $name, 'semitones' => abs($semitones), 'direction' => $semitones < 0 ? 'down' : 'up'];
	}

	public static function transpose(string $notes, int $semitones, string $to = ''): array {
		$out = [];
		$letterShift = null;
		if ($to !== '') {
			[$tl, $tp] = self::parse($to);
			$first = preg_split('/[\s,–-]+/', trim($notes))[0] ?? '';
			[$fl, $fp] = self::parse($first);
			$semitones = ((($tp - $fp) % 12) + 12) % 12;
			$letterShift = ((($tl - $fl) % 7) + 7) % 7;
		}
		$letterShift ??= [0, 1, 1, 2, 2, 3, 3, 4, 5, 5, 6, 6][(($semitones % 12) + 12) % 12];
		foreach (preg_split('/[\s,–]+/', trim($notes)) ?: [] as $note) {
			if ($note === '') {
				continue;
			}
			[$letter, $pitch, $octave] = self::parse($note, true);
			$spelled = self::spell($letter + $letterShift, $pitch + $semitones);
			if ($octave !== null) {
				// The octave of the spelled note: its letter's pitch plus accidentals (B# is 12, Cb is −1).
				$absolute = $pitch + $semitones + 12 * $octave;
				$own = self::LETTERS[$spelled[0]] + substr_count($spelled, '#') - substr_count(substr($spelled, 1), 'b');
				$spelled .= intdiv($absolute - $own, 12);
			}
			$out[] = $spelled;
		}
		return ['transposed' => implode(' ', $out), 'by' => $semitones . ' semitones'];
	}

	public static function frequency(string $note, float $a4 = 440.0): array {
		[, $pitch, $octave] = self::parse($note, true);
		$midi = $pitch + 12 * (($octave ?? 4) + 1);
		return ['note' => $note, 'frequency' => round($a4 * 2 ** (($midi - 69) / 12), 2) . ' Hz', 'midi' => $midi, 'tuning' => 'A4 = ' . $a4 . ' Hz, equal temperament'];
	}

	/** @return array{int, int, ?int} letter index, pitch class (may leave 0–11 for B#, Cb), octave */
	private static function parse(string $note, bool $octave = false): array {
		$note = strtr(trim($note), ['♯' => '#', '♭' => 'b', '𝄪' => '##', '𝄫' => 'bb']);
		if (preg_match('/^([A-Ha-h])(is|es|s)?(#{1,2}|b{1,2})?(-?\d)?$/u', $note, $m) !== 1) {
			throw new \InvalidArgumentException('a note like C, F#, Bb, Eb4 (or German H, B, Fis, Es): ' . $note);
		}
		$letter = strtoupper($m[1]);
		$accidental = $m[3] ?? '';
		if ($letter === 'H') {
			$letter = 'B';
		}
		$shift = match (true) {
			($m[2] ?? '') === 'is' => 1,
			in_array($m[2] ?? '', ['es', 's'], true) => -1,
			default => 0,
		} + substr_count($accidental, '#') - substr_count($accidental, 'b');
		$index = array_search($letter, self::ORDER, true);
		return [$index, self::LETTERS[$letter] + $shift, isset($m[4]) && $m[4] !== '' ? (int)$m[4] : null];
	}

	/** The note on this letter (index, may exceed 6) with this pitch, with the accidentals it needs. */
	private static function spell(int $letterIndex, int $pitch): string {
		$letter = self::ORDER[$letterIndex % 7];
		$diff = ((($pitch - self::LETTERS[$letter]) % 12) + 18) % 12 - 6;
		return $letter . ($diff > 0 ? str_repeat('#', $diff) : str_repeat('b', -$diff));
	}

	private static function name(int $letterIndex, int $pitch): string {
		return self::spell($letterIndex, $pitch);
	}

	private static function signature(array $notes): string {
		$sharps = count(array_filter($notes, fn ($n) => str_contains($n, '#')));
		$flats = count(array_filter($notes, fn ($n) => str_contains(substr($n, 1), 'b')));
		return $sharps > 0 ? $sharps . ' sharp' . ($sharps > 1 ? 's' : '') : ($flats > 0 ? $flats . ' flat' . ($flats > 1 ? 's' : '') : 'none');
	}
}
