<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * The text of a file, for the model to read: text formats as they are, HTML
 * without its tags, office documents (Word, ODF, Excel, PowerPoint) from their
 * XML, and PDF with pdftotext (Poppler) where the server has it. Null for what it
 * cannot read (images go to the model as images instead).
 */
class TextExtractor {
	/** The most of one file handed to the model, in characters. */
	public const MAX = 90000;

	private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'yaml', 'yml', 'ini', 'log',
		'py', 'js', 'ts', 'php', 'java', 'c', 'h', 'cpp', 'go', 'rs', 'sh', 'sql', 'css', 'tex', 'vcf', 'ics'];

	public function __construct(
		private string $pdftotext = '/usr/bin/pdftotext',
	) {
	}

	public function canReadPdf(): bool {
		return is_executable($this->pdftotext);
	}

	public function extract(string $data, string $mime, string $name): ?string {
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$text = match (true) {
			$extension === 'docx' => $this->fromZip($data, 'word/document.xml', [$this, 'wordXml']),
			in_array($extension, ['odt', 'ods', 'odp'], true) => $this->fromZip($data, 'content.xml', [$this, 'odfXml']),
			$extension === 'xlsx' => $this->xlsx($data),
			$extension === 'pptx' => $this->pptx($data),
			$extension === 'pdf' || $mime === 'application/pdf' => $this->pdf($data),
			in_array($extension, ['html', 'htm'], true) || $mime === 'text/html' => self::html($data),
			str_starts_with($mime, 'text/') || in_array($extension, self::TEXT_EXTENSIONS, true) => $data,
			default => null,
		};
		if ($text === null) {
			return null;
		}
		$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		$text = trim(preg_replace("/[ \t]+\n/", "\n", preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) ?? $text);
		return mb_strlen($text) > self::MAX ? mb_substr($text, 0, self::MAX) . "\n[… cut: the file is longer]" : $text;
	}

	public static function html(string $html): string {
		$html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
		$html = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)\b[^>]*>#i', "\n", $html) ?? $html;
		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/** Word: paragraphs (w:p) of runs' text (w:t), tabs and breaks. */
	public static function wordXml(string $xml): string {
		$xml = preg_replace(['#<w:tab/>#', '#<w:br[^>]*/>#', '#</w:p>#', '#</w:tc>#'], ["\t", "\n", "\n", "\t"], $xml) ?? $xml;
		preg_match_all('#<w:t(?:\s[^>]*)?>(.*?)</w:t>|(\n|\t)#s', $xml, $parts, PREG_SET_ORDER);
		return html_entity_decode(implode('', array_map(fn (array $p) => ($p[1] ?? '') . ($p[2] ?? ''), $parts)), ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	/** ODF: paragraphs and headings, table cells separated by tabs. */
	public static function odfXml(string $xml): string {
		$xml = preg_replace(['#<text:tab/>#', '#<text:line-break/>#', '#<text:s(?: text:c="(\d+)")?/>#'], ["\t", "\n", ' '], $xml) ?? $xml;
		// A cell's own paragraph end is the cell's end, not a line of its own.
		$xml = preg_replace(['#</text:p>\s*</table:table-cell>#', '#</text:(p|h)>#', '#</table:table-cell>#', '#\t?</table:table-row>#'], ['</table:table-cell>', "\n", "\t", "\n"], $xml) ?? $xml;
		return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	private function fromZip(string $data, string $entry, callable $read): ?string {
		$xml = $this->zipEntries($data, fn (string $name) => $name === $entry)[$entry] ?? null;
		return $xml === null ? null : $read($xml);
	}

	/** Excel: each sheet's rows, cells separated by tabs (shared strings resolved). */
	private function xlsx(string $data): ?string {
		$files = $this->zipEntries($data, fn (string $name) => $name === 'xl/sharedStrings.xml' || preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1);
		if ($files === []) {
			return null;
		}
		$strings = [];
		if (isset($files['xl/sharedStrings.xml'])) {
			preg_match_all('#<si>(.*?)</si>#s', $files['xl/sharedStrings.xml'], $items);
			foreach ($items[1] as $item) {
				preg_match_all('#<t(?:\s[^>]*)?>(.*?)</t>#s', $item, $t);
				$strings[] = html_entity_decode(implode('', $t[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
			}
		}
		$sheets = array_filter($files, fn (string $name) => $name !== 'xl/sharedStrings.xml', ARRAY_FILTER_USE_KEY);
		ksort($sheets, SORT_NATURAL);
		$out = [];
		foreach ($sheets as $name => $xml) {
			$out[] = '# ' . basename($name, '.xml');
			preg_match_all('#<row\b[^>]*>(.*?)</row>#s', $xml, $rows);
			foreach ($rows[1] as $row) {
				preg_match_all('#<c\b([^>]*)>(.*?)</c>#s', $row, $cells, PREG_SET_ORDER);
				$values = [];
				foreach ($cells as [, $attributes, $inner]) {
					$value = preg_match('#<v>(.*?)</v>#s', $inner, $v) === 1 ? html_entity_decode($v[1], ENT_QUOTES | ENT_XML1, 'UTF-8')
						: (preg_match('#<t(?:\s[^>]*)?>(.*?)</t>#s', $inner, $t) === 1 ? html_entity_decode($t[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '');
					$values[] = str_contains($attributes, 't="s"') ? ($strings[(int)$value] ?? '') : $value;
				}
				$out[] = implode("\t", $values);
			}
		}
		return implode("\n", $out);
	}

	/** PowerPoint: each slide's text. */
	private function pptx(string $data): ?string {
		$slides = $this->zipEntries($data, fn (string $name) => preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1);
		if ($slides === []) {
			return null;
		}
		ksort($slides, SORT_NATURAL);
		$out = [];
		foreach ($slides as $name => $xml) {
			$xml = preg_replace('#</a:p>#', "\n", $xml) ?? $xml;
			preg_match_all('#<a:t>(.*?)</a:t>|(\n)#s', $xml, $parts, PREG_SET_ORDER);
			$out[] = '# Slide ' . preg_replace('/\D/', '', basename($name)) . "\n"
				. html_entity_decode(implode('', array_map(fn (array $p) => ($p[1] ?? '') . ($p[2] ?? ''), $parts)), ENT_QUOTES | ENT_XML1, 'UTF-8');
		}
		return implode("\n\n", $out);
	}

	private function pdf(string $data): ?string {
		if (!$this->canReadPdf()) {
			return null;
		}
		$in = tempnam(sys_get_temp_dir(), 'talk-ai-pdf');
		try {
			file_put_contents($in, $data);
			$process = proc_open([$this->pdftotext, '-layout', '-enc', 'UTF-8', '-q', $in, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			if (!is_resource($process)) {
				return null;
			}
			$text = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			return proc_close($process) === 0 && $text !== false ? $text : null;
		} finally {
			@unlink($in);
		}
	}

	/** @return array<string, string> the wanted entries of a ZIP archive by name */
	private function zipEntries(string $data, callable $wanted): array {
		$path = tempnam(sys_get_temp_dir(), 'talk-ai-zip');
		$zip = new \ZipArchive();
		try {
			file_put_contents($path, $data);
			if ($zip->open($path) !== true) {
				return [];
			}
			$files = [];
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string)$zip->getNameIndex($i);
				if ($wanted($name)) {
					$files[$name] = (string)$zip->getFromIndex($i);
				}
			}
			$zip->close();
			return $files;
		} finally {
			@unlink($path);
		}
	}
}
