<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * The text of a file, for the model to read: text formats as they are, HTML
 * without its tags, office documents (Word, ODF, Excel, PowerPoint) from their
 * XML with sheet names, slide numbers and speaker notes, PDF with pdftotext
 * (Poppler), and text in images and scanned PDFs with Tesseract (OCR), where
 * the server has them. Null for what it cannot read (old binary Office files
 * are converted first).
 */
class TextExtractor {
	/** The most of one file handed to the model, in characters. */
	public const MAX = 90000;

	private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'yaml', 'yml', 'ini', 'log',
		'py', 'js', 'ts', 'php', 'java', 'c', 'h', 'cpp', 'go', 'rs', 'sh', 'sql', 'css', 'tex', 'vcf', 'ics'];

	/** Scanned pages read by OCR at most (about two seconds each). */
	private const OCR_PAGES = 15;
	private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff'];

	private ?string $languages = null;

	public function __construct(
		private string $pdftotext = '/usr/bin/pdftotext',
		private string $tesseract = '/usr/bin/tesseract',
		private string $pdftoppm = '/usr/bin/pdftoppm',
	) {
	}

	public function canReadPdf(): bool {
		return is_executable($this->pdftotext);
	}

	public function canOcr(): bool {
		return is_executable($this->tesseract);
	}

	/** The text in an image (OCR); null without Tesseract or text. */
	public function ocr(string $image): ?string {
		if (!$this->canOcr()) {
			return null;
		}
		$path = tempnam(sys_get_temp_dir(), 'talk-ai-ocr');
		try {
			file_put_contents($path, $image);
			$text = $this->run([$this->tesseract, $path, '-', '-l', $this->languages(), '--psm', '3']);
			return $text === null || trim($text) === '' ? null : trim($text);
		} finally {
			@unlink($path);
		}
	}

	/** The installed OCR languages, "deu+eng+pol" (English when none is listed). */
	private function languages(): string {
		if ($this->languages === null) {
			$list = preg_split('/\s+/', (string)$this->run([$this->tesseract, '--list-langs'])) ?: [];
			$languages = array_values(array_filter($list, fn (string $l) => preg_match('/^[a-z]{3}(_[a-z]+)?$/', $l) === 1 && $l !== 'osd'));
			$this->languages = $languages === [] ? 'eng' : implode('+', $languages);
		}
		return $this->languages;
	}

	public function extract(string $data, string $mime, string $name): ?string {
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$text = match (true) {
			$extension === 'docx' => $this->fromZip($data, 'word/document.xml', [$this, 'wordXml']),
			in_array($extension, ['odt', 'ods', 'odp', 'odg', 'ott', 'ots', 'otp'], true) => $this->fromZip($data, 'content.xml', [$this, 'odfXml']),
			$extension === 'xlsx' => $this->xlsx($data),
			$extension === 'pptx' => $this->pptx($data),
			$extension === 'pdf' || $mime === 'application/pdf' => $this->pdf($data),
			str_starts_with($mime, 'image/') || in_array($extension, self::IMAGE_EXTENSIONS, true) => $this->ocr($data),
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

	/**
	 * ODF: paragraphs and headings, table cells separated by tabs; a sheet's or
	 * slide's start as a heading ("# Sheet: …", "# Slide 2: …"), speaker notes
	 * as "Notes:".
	 */
	public static function odfXml(string $xml): string {
		$xml = preg_replace(['#<text:tab/>#', '#<text:line-break/>#', '#<text:s(?: text:c="(\d+)")?/>#'], ["\t", "\n", ' '], $xml) ?? $xml;
		// Styles hold text too (a percentage's "%"): only the body counts.
		$xml = preg_replace('#<office:(automatic-styles|font-face-decls|scripts)\b.*?</office:\1>#s', '', $xml) ?? $xml;
		$slide = 0;
		$xml = preg_replace_callback('#<draw:page(?=[\s>])[^>]*?(?:draw:name="([^"]*)")?[^>]*>#', function (array $m) use (&$slide): string {
			$slide++;
			$name = html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
			return "\n\n# Slide " . $slide . (preg_match('/^(page|slide)\s*\d+$/i', $name) === 1 || $name === '' ? '' : ': ' . $name) . "\n";
		}, $xml) ?? $xml;
		$xml = preg_replace(['#<presentation:notes\b[^>]*>#', '#<table:table\b[^>]*?table:name="([^"]*)"[^>]*>#'], ["\nNotes: ", "\n\n# Sheet: \$1\n"], $xml) ?? $xml;
		// Skip ODS's long runs of empty repeated cells and rows.
		$xml = preg_replace('#<table:table-(cell|row)\b[^>]*table:number-(columns|rows)-repeated="\d{3,}"[^>]*/>#', '', $xml) ?? $xml;
		// A cell's own paragraph end is the cell's end, not a line of its own.
		$xml = preg_replace(['#</text:p>\s*</table:table-cell>#', '#</text:(p|h)>#', '#</table:table-cell>#', '#\t?</table:table-row>#'], ['</table:table-cell>', "\n", "\t", "\n"], $xml) ?? $xml;
		$text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
		// Empty notes left out.
		return preg_replace(["/\t+\n/", "/\nNotes:\s*(?=\n# |$)/", "/\n{3,}/"], ["\n", '', "\n\n"], $text) ?? $text;
	}

	private function fromZip(string $data, string $entry, callable $read): ?string {
		$xml = $this->zipEntries($data, fn (string $name) => $name === $entry)[$entry] ?? null;
		return $xml === null ? null : $read($xml);
	}

	/** Excel: each sheet's rows, cells separated by tabs (shared strings resolved). */
	private function xlsx(string $data): ?string {
		$files = $this->zipEntries($data, fn (string $name) => in_array($name, ['xl/sharedStrings.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'], true) || preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1);
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
		$sheets = array_filter($files, fn (string $name) => str_starts_with($name, 'xl/worksheets/'), ARRAY_FILTER_USE_KEY);
		ksort($sheets, SORT_NATURAL);
		$names = self::sheetNames($files['xl/workbook.xml'] ?? '', $files['xl/_rels/workbook.xml.rels'] ?? '');
		// In the workbook's order where it is known.
		$order = array_flip(array_keys($names));
		uksort($sheets, fn (string $a, string $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);
		$out = [];
		foreach ($sheets as $name => $xml) {
			$out[] = "\n# Sheet: " . ($names[$name] ?? basename($name, '.xml'));
			preg_match_all('#<row\b[^>]*>(.*?)</row>#s', $xml, $rows);
			foreach ($rows[1] as $row) {
				preg_match_all('#<c\b([^>]*)>(.*?)</c>#s', $row, $cells, PREG_SET_ORDER);
				$values = [];
				foreach ($cells as [, $attributes, $inner]) {
					$value = preg_match('#<v>(.*?)</v>#s', $inner, $v) === 1 ? html_entity_decode($v[1], ENT_QUOTES | ENT_XML1, 'UTF-8')
						: (preg_match('#<t(?:\s[^>]*)?>(.*?)</t>#s', $inner, $t) === 1 ? html_entity_decode($t[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '');
					$value = str_contains($attributes, 't="s"') ? ($strings[(int)$value] ?? '') : $value;
					// The column from the reference (A1, C7): empty cells are left out of the XML.
					if (preg_match('#r="([A-Z]+)\d+"#', $attributes, $ref) === 1) {
						$column = 0;
						foreach (str_split($ref[1]) as $letter) {
							$column = $column * 26 + ord($letter) - 64;
						}
						while (count($values) < $column - 1) {
							$values[] = '';
						}
					}
					$values[] = $value;
				}
				$out[] = rtrim(implode("\t", $values), "\t");
			}
		}
		return implode("\n", $out);
	}

	/** The sheets' names by their part (xl/worksheets/sheet1.xml => "Budget"), in the workbook's order. */
	private static function sheetNames(string $workbook, string $rels): array {
		$targets = [];
		preg_match_all('#<Relationship\b[^>]*>#', $rels, $relations);
		foreach ($relations[0] as $relation) {
			if (preg_match('#\bId="([^"]+)"#', $relation, $id) === 1 && preg_match('#\bTarget="([^"]+)"#', $relation, $target) === 1) {
				$targets[$id[1]] = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', $target[1]) ?? $target[1], '/');
			}
		}
		$names = [];
		preg_match_all('#<sheet\b[^>]*>#', $workbook, $sheets);
		foreach ($sheets[0] as $sheet) {
			if (preg_match('#\bname="([^"]*)"#', $sheet, $name) === 1 && preg_match('#\br:id="([^"]+)"#', $sheet, $id) === 1 && isset($targets[$id[1]])) {
				$names[$targets[$id[1]]] = html_entity_decode($name[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
			}
		}
		return $names;
	}

	/** PowerPoint: each slide's text and its speaker notes. */
	private function pptx(string $data): ?string {
		$files = $this->zipEntries($data, fn (string $name) => preg_match('#^ppt/(slides|notesSlides)/(_rels/)?(slide|notesSlide)\d+\.xml(\.rels)?$#', $name) === 1);
		$slides = array_filter($files, fn (string $name) => preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1, ARRAY_FILTER_USE_KEY);
		if ($slides === []) {
			return null;
		}
		ksort($slides, SORT_NATURAL);
		$out = [];
		foreach ($slides as $name => $xml) {
			$text = '# Slide ' . preg_replace('/\D/', '', basename($name)) . "\n" . self::drawingText($xml);
			// The notes are the slide's related notesSlide part.
			$rels = $files['ppt/slides/_rels/' . basename($name) . '.rels'] ?? '';
			if (preg_match('#Target="\.\./notesSlides/(notesSlide\d+\.xml)"#', $rels, $notes) === 1 && isset($files['ppt/notesSlides/' . $notes[1]])) {
				// Without the slide's image and number placeholders' text: only the notes' body.
				$body = preg_replace('#<p:sp>(?:(?!</p:sp>).)*?<p:ph type="(sldImg|sldNum|hdr|ftr|dt)".*?</p:sp>#s', '', $files['ppt/notesSlides/' . $notes[1]]) ?? '';
				$noteText = trim(self::drawingText($body));
				if ($noteText !== '') {
					$text .= "\nNotes: " . $noteText;
				}
			}
			$out[] = $text;
		}
		return implode("\n\n", $out);
	}

	/** DrawingML's text: runs (a:t) by paragraph. */
	private static function drawingText(string $xml): string {
		$xml = preg_replace('#</a:p>#', "\n", $xml) ?? $xml;
		preg_match_all('#<a:t>(.*?)</a:t>|(\n)#s', $xml, $parts, PREG_SET_ORDER);
		return html_entity_decode(implode('', array_map(fn (array $p) => ($p[1] ?? '') . ($p[2] ?? ''), $parts)), ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	/** A PDF's text; a scan (next to no text) read by OCR, its first pages. */
	private function pdf(string $data): ?string {
		if (!$this->canReadPdf()) {
			return null;
		}
		$in = tempnam(sys_get_temp_dir(), 'talk-ai-pdf');
		$pages = $in . '-pages';
		try {
			file_put_contents($in, $data);
			$text = $this->run([$this->pdftotext, '-layout', '-enc', 'UTF-8', '-q', $in, '-']);
			if ($text !== null && mb_strlen((string)preg_replace('/\s+/u', '', $text)) >= 100 || !$this->canOcr() || !is_executable($this->pdftoppm)) {
				return $text;
			}
			mkdir($pages);
			$this->run([$this->pdftoppm, '-r', '200', '-gray', '-png', '-l', (string)self::OCR_PAGES, $in, $pages . '/p']);
			$images = glob($pages . '/p*.png') ?: [];
			natsort($images);
			$out = [];
			foreach ($images as $image) {
				$page = $this->ocr((string)file_get_contents($image));
				if ($page !== null) {
					$out[] = '# Page ' . (int)preg_replace('/^.*-(\d+)\.png$/', '$1', $image) . "\n" . $page;
				}
			}
			if ($out === []) {
				return $text;
			}
			return "[A scan: text recognised by OCR, may contain errors" . (count($images) >= self::OCR_PAGES ? '; the first ' . self::OCR_PAGES . ' pages' : '') . "]\n\n" . implode("\n\n", $out);
		} finally {
			@unlink($in);
			foreach (glob($pages . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($pages);
		}
	}

	/** A program's output, or null when it fails; at most two minutes. */
	private function run(array $command): ?string {
		if (is_executable('/usr/bin/timeout')) {
			array_unshift($command, '/usr/bin/timeout', '120');
		}
		$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
		if (!is_resource($process)) {
			return null;
		}
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		return proc_close($process) === 0 && $output !== false ? $output : null;
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
