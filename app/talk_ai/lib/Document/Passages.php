<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * A long text in passages (pages where the text has them, form feeds from
 * pdftotext, "--- Slide n" or "## Sheet" markers; else about 3 000 characters at
 * paragraph ends), and the passages that best match a question, by BM25.
 */
class Passages {
	private const SIZE = 3000;
	private const K1 = 1.4;
	private const B = 0.75;

	/** @return list<array{label: string, text: string}> */
	public static function split(string $text): array {
		$parts = [];
		if (str_contains($text, "\f")) {
			foreach (explode("\f", $text) as $i => $page) {
				$parts[] = ['label' => 'page ' . ($i + 1), 'text' => $page];
			}
		} elseif (preg_match_all('/^(?:--- ?(?:Slide|Folie|Sheet|Page)|## )[^\n]*$/m', $text, $m, PREG_OFFSET_CAPTURE) >= 2) {
			$offsets = array_column($m[0], 1);
			$offsets[] = strlen($text);
			if ($offsets[0] > 0) {
				$parts[] = ['label' => 'start', 'text' => substr($text, 0, $offsets[0])];
			}
			for ($i = 0; $i < count($offsets) - 1; $i++) {
				$chunk = substr($text, $offsets[$i], $offsets[$i + 1] - $offsets[$i]);
				$parts[] = ['label' => trim(strtok($chunk, "\n") ?: ''), 'text' => $chunk];
			}
		} else {
			$buffer = '';
			$n = 1;
			foreach (preg_split('/\n\s*\n/', $text) ?: [] as $paragraph) {
				if (mb_strlen($buffer) + mb_strlen($paragraph) > self::SIZE && $buffer !== '') {
					$parts[] = ['label' => 'part ' . $n++, 'text' => $buffer];
					$buffer = '';
				}
				$buffer .= ($buffer === '' ? '' : "\n\n") . $paragraph;
			}
			if (trim($buffer) !== '') {
				$parts[] = ['label' => 'part ' . $n, 'text' => $buffer];
			}
		}
		// Very long pages are cut further, keeping their label.
		$out = [];
		foreach ($parts as $part) {
			if (trim($part['text']) === '') {
				continue;
			}
			$chunks = mb_str_split($part['text'], self::SIZE * 2);
			foreach ($chunks as $i => $chunk) {
				$out[] = ['label' => $part['label'] . (count($chunks) > 1 ? ' (' . ($i + 1) . ')' : ''), 'text' => $chunk];
			}
		}
		return $out;
	}

	/**
	 * The passages best matching the query, in document order, within a budget of
	 * characters; the first passage always (title, abstract).
	 *
	 * @param list<array{label: string, text: string}> $passages
	 * @return list<array{label: string, text: string, score: float}>
	 */
	public static function best(array $passages, string $query, int $budget): array {
		$terms = self::terms($query);
		$docs = array_map(fn ($p) => self::terms($p['text']), $passages);
		$n = count($docs);
		$avg = $n > 0 ? array_sum(array_map('count', $docs)) / $n : 1;
		$df = [];
		foreach ($docs as $doc) {
			foreach (array_unique($doc) as $term) {
				$df[$term] = ($df[$term] ?? 0) + 1;
			}
		}
		$scores = [];
		foreach ($docs as $i => $doc) {
			$tf = array_count_values($doc);
			$score = 0.0;
			foreach (array_unique($terms) as $term) {
				if (!isset($tf[$term])) {
					continue;
				}
				$idf = log(1 + ($n - $df[$term] + 0.5) / ($df[$term] + 0.5));
				$score += $idf * ($tf[$term] * (self::K1 + 1)) / ($tf[$term] + self::K1 * (1 - self::B + self::B * count($doc) / max(1, $avg)));
			}
			$scores[$i] = $score;
		}
		arsort($scores);
		$chosen = [0 => true];
		$used = mb_strlen($passages[0]['text'] ?? '');
		foreach ($scores as $i => $score) {
			if ($score <= 0 || isset($chosen[$i])) {
				continue;
			}
			if ($used + mb_strlen($passages[$i]['text']) > $budget) {
				continue;
			}
			$chosen[$i] = true;
			$used += mb_strlen($passages[$i]['text']);
		}
		ksort($chosen);
		$out = [];
		foreach (array_keys($chosen) as $i) {
			if (isset($passages[$i])) {
				$out[] = $passages[$i] + ['score' => round($scores[$i] ?? 0, 2)];
			}
		}
		return $out;
	}

	/** @return list<string> lower-case words of three letters or more, with simple stemming of common endings */
	private static function terms(string $text): array {
		preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $m);
		return array_map(fn (string $w) => mb_strlen($w) > 5 ? preg_replace('/(ungen|ung|en|er|es|e|s|ów|ach|ami|ie|y|ing|ed)$/u', '', $w) ?? $w : $w, $m[0]);
	}
}
