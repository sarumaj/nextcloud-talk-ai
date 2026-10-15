<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Pictures in Markdown, for documents and the chat: a fenced ```chart (or ~~~chart) block
 * with a chart's JSON (as the plot tool takes it) becomes the chart as SVG; an
 * image ![caption](source) is looked up by the given resolver (a file id
 * "file:123", a web address). Each picture: data, MIME type, width and height.
 */
class Figures {
	/** A chart block: ```chart, then JSON, then ```. */
	public const CHART = '/^(```|~~~)(?:chart|plot)\s*\n(.*?)\n\1\s*$/ms';
	/** An image alone on its line. */
	public const IMAGE = '/^\s*!\[([^\]]*)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)\s*$/m';

	/** @return array{data: string, mime: string, width: int, height: int, caption: string} */
	public static function chart(string $json): array {
		$spec = json_decode(trim($json), true);
		if (!is_array($spec)) {
			throw new \InvalidArgumentException('a chart block needs the chart as JSON');
		}
		$svg = Chart::svg($spec);
		[$w, $h] = self::svgSize($svg);
		return ['data' => $svg, 'mime' => 'image/svg+xml', 'width' => $w, 'height' => $h, 'caption' => (string)($spec['caption'] ?? '')];
	}

	/** The size of raster image data, or of an SVG by its width and height or viewBox. */
	public static function size(string $data, string $mime): array {
		if ($mime === 'image/svg+xml') {
			return self::svgSize($data);
		}
		$info = @getimagesizefromstring($data);
		return $info === false ? [800, 600] : [(int)$info[0], (int)$info[1]];
	}

	private static function svgSize(string $svg): array {
		if (preg_match('/<svg\b[^>]*\bwidth="([\d.]+)"[^>]*\bheight="([\d.]+)"/', $svg, $m) === 1) {
			return [(int)$m[1], (int)$m[2]];
		}
		if (preg_match('/viewBox="[\d.\-]+ [\d.\-]+ ([\d.]+) ([\d.]+)"/', $svg, $m) === 1) {
			return [(int)$m[1], (int)$m[2]];
		}
		return [1000, 620];
	}

	/**
	 * Markdown cut where pictures stand alone: text pieces and pictures in
	 * order, for the chat (each picture its own message).
	 *
	 * @return list<array{type: string, text?: string, chart?: string, source?: string, caption?: string}>
	 */
	public static function split(string $markdown): array {
		$pattern = '/(^(?:```|~~~)(?:chart|plot)\s*\n.*?\n(?:```|~~~)\s*$)|(^\s*!\[[^\]]*\]\(\s*<?[^)\s>]+>?(?:\s+"[^"]*")?\s*\)\s*$)/ms';
		$parts = preg_split($pattern, $markdown, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$markdown];
		$out = [];
		foreach ($parts as $part) {
			if (preg_match(self::CHART, $part, $m) === 1) {
				$out[] = ['type' => 'chart', 'chart' => $m[2]];
			} elseif (preg_match(self::IMAGE, $part, $m) === 1) {
				$out[] = ['type' => 'image', 'source' => $m[2], 'caption' => $m[1]];
			} elseif (trim($part) !== '') {
				$out[] = ['type' => 'text', 'text' => trim($part)];
			}
		}
		return $out;
	}
}
