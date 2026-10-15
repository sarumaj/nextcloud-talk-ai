<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * A spreadsheet (.ods), which Collabora turns into Excel or PDF, from Markdown
 * tables: each "## Name" heading starts a sheet of that name, and each table
 * under it fills the sheet (several are stacked with a blank row). Plain CSV
 * becomes one sheet. Numbers are numbers, "50%" a percentage, and a cell
 * starting with "=" an Excel-style formula (=SUM(B2:B5), =B2*C2); the first
 * row is bold.
 */
class OdsWriter {
	/** @return list<array{name: string, rows: list<list<string>>}> */
	public static function sheets(string $text): array {
		$lines = explode("\n", str_replace("\r\n", "\n", trim($text)));
		if (!preg_grep('/^\s*\|/', $lines)) {
			return [['name' => 'Sheet1', 'rows' => self::csv($text)]];
		}
		$sheets = [];
		$name = null;
		for ($i = 0; $i < count($lines); $i++) {
			if (preg_match('/^#{1,6}\s+(.*)$/', $lines[$i], $m) === 1) {
				$name = trim($m[1], ' #*');
			} elseif (Odf::isTable($lines, $i)) {
				$rows = [Odf::cells($lines[$i])];
				for ($i += 2; $i < count($lines) && str_starts_with(ltrim($lines[$i]), '|'); $i++) {
					$rows[] = Odf::cells($lines[$i]);
				}
				$i--;
				// A heading starts a sheet; a table without one joins the last.
				if ($name !== null || $sheets === []) {
					$sheets[] = ['name' => (string)$name, 'rows' => $rows];
					$name = null;
				} else {
					$last = count($sheets) - 1;
					$sheets[$last]['rows'] = [...$sheets[$last]['rows'], [], ...$rows];
				}
			}
		}
		$used = [];
		foreach ($sheets as $n => &$sheet) {
			// Sheet names are unique, at most 31 characters, without []*?:/\ (Excel's rules).
			$base = mb_substr(trim(preg_replace('#[\[\]*?:/\\\\]#', ' ', $sheet['name']) ?? ''), 0, 31) ?: 'Sheet' . ($n + 1);
			$unique = $base;
			for ($k = 2; isset($used[mb_strtolower($unique)]); $k++) {
				$unique = mb_substr($base, 0, 28) . ' ' . $k;
			}
			$used[mb_strtolower($unique)] = true;
			$sheet['name'] = $unique;
		}
		return $sheets;
	}

	public function write(string $text, string $title = ''): string {
		$body = '';
		foreach (self::sheets($text) as $sheet) {
			$columns = max(1, ...array_map('count', $sheet['rows'] ?: [[]]));
			$widths = array_fill(0, $columns, 6);
			foreach ($sheet['rows'] as $row) {
				foreach ($row as $c => $cell) {
					$widths[$c] = max($widths[$c], min(60, mb_strlen(self::plain($cell))));
				}
			}
			$body .= '<table:table table:name="' . Odf::xml($sheet['name']) . '">';
			foreach ($widths as $w) {
				$body .= '<table:table-column table:style-name="co' . self::width($w) . '" table:default-cell-style-name="Default"/>';
			}
			foreach ($sheet['rows'] as $r => $row) {
				$body .= '<table:table-row>';
				for ($c = 0; $c < $columns; $c++) {
					$body .= self::cell($row[$c] ?? '', $r === 0);
				}
				$body .= '</table:table-row>';
			}
			$body .= '</table:table>';
		}
		$columnStyles = '';
		foreach ([6, 10, 15, 20, 30, 45, 60] as $w) {
			$columnStyles .= '<style:style style:name="co' . $w . '" style:family="table-column"><style:table-column-properties style:column-width="' . round($w * 0.21 + 0.4, 2) . 'cm"/></style:style>';
		}
		$content = '<office:document-content ' . Odf::NAMESPACES . '><office:automatic-styles>' . $columnStyles
			. '<number:percentage-style style:name="N10"><number:number number:decimal-places="0" number:min-decimal-places="0" number:min-integer-digits="1"/><number:text>%</number:text></number:percentage-style>'
			. '<style:style style:name="Head" style:family="table-cell" style:parent-style-name="Default"><style:table-cell-properties fo:background-color="#e8eef7"/><style:text-properties fo:font-weight="bold"/></style:style>'
			. '<style:style style:name="Percent" style:family="table-cell" style:parent-style-name="Default" style:data-style-name="N10"/>'
			. '<style:style style:name="Wrap" style:family="table-cell" style:parent-style-name="Default"><style:table-cell-properties fo:wrap-option="wrap"/></style:style>'
			. '</office:automatic-styles><office:body><office:spreadsheet>' . $body . '</office:spreadsheet></office:body></office:document-content>';
		$styles = '<office:document-styles ' . Odf::NAMESPACES . '><office:font-face-decls><style:font-face style:name="Liberation Sans" svg:font-family="&apos;Liberation Sans&apos;"/></office:font-face-decls>'
			. '<office:styles><style:style style:name="Default" style:family="table-cell"><style:text-properties style:font-name="Liberation Sans" fo:font-size="10pt"/></style:style></office:styles></office:document-styles>';
		return Odf::package(Odf::SPREADSHEET, $content, $styles, $title);
	}

	/** One cell: a formula, a number, a percentage, or text. */
	public static function cell(string $value, bool $head = false): string {
		$value = self::plain($value);
		$style = $head ? ' table:style-name="Head"' : (mb_strlen($value) > 60 ? ' table:style-name="Wrap"' : '');
		if ($value === '') {
			return '<table:table-cell' . $style . '/>';
		}
		if (!$head && str_starts_with($value, '=') && strlen($value) > 1) {
			return '<table:table-cell' . $style . ' table:formula="of:' . Odf::xml(self::formula(substr($value, 1))) . '"/>';
		}
		$number = self::number($value);
		if (!$head && $number !== null) {
			if (str_ends_with($value, '%')) {
				return '<table:table-cell table:style-name="Percent" office:value-type="percentage" office:value="' . ($number / 100) . '"><text:p>' . Odf::xml($value) . '</text:p></table:table-cell>';
			}
			return '<table:table-cell' . $style . ' office:value-type="float" office:value="' . $number . '"><text:p>' . Odf::xml($value) . '</text:p></table:table-cell>';
		}
		return '<table:table-cell' . $style . ' office:value-type="string"><text:p>' . Odf::xml($value) . '</text:p></table:table-cell>';
	}

	/** "1,234.5", "1.234,5", "-3", "12 %" as a number; null for anything else. */
	public static function number(string $value): ?float {
		$v = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', rtrim($value, '% ')) ?? '';
		if (preg_match('/^[-+]?\d{1,3}(,\d{3})+(\.\d+)?$/', $v) === 1) {
			$v = str_replace(',', '', $v);
		} elseif (preg_match('/^[-+]?\d{1,3}(\.\d{3})+(,\d+)?$/', $v) === 1) {
			$v = str_replace(['.', ','], ['', '.'], $v);
		} elseif (preg_match('/^[-+]?\d+,\d+$/', $v) === 1) {
			$v = str_replace(',', '.', $v);
		}
		return preg_match('/^[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?$/', $v) === 1 ? (float)$v : null;
	}

	/**
	 * An Excel-style formula as OpenFormula: cell references in brackets
	 * ([.A1], [.A1:.B2], ['Sheet'.A1]) and arguments separated by ";".
	 * Strings in quotes stay as they are.
	 */
	public static function formula(string $excel): string {
		$parts = preg_split('/("[^"]*")/', $excel, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$excel];
		$out = '';
		foreach ($parts as $part) {
			if (str_starts_with($part, '"')) {
				$out .= $part;
				continue;
			}
			$ref = "(?:(?:'[^']+'|[A-Za-z_][\\w.]*)!)?\\$?[A-Z]{1,3}\\$?\\d+";
			$part = preg_replace_callback('/(?<![\w.$])(' . $ref . ')(?::(' . $ref . '))?(?![\w(])/', function (array $m): string {
				$one = fn (string $r): string => preg_match("/^(?:'?([^'!]+)'?!)?(.+)$/", $r, $x) === 1
					? ($x[1] !== '' ? "'" . str_replace("'", "''", $x[1]) . "'" : '') . '.' . $x[2] : $r;
				return '[' . $one($m[1]) . (isset($m[2]) && $m[2] !== '' ? ':' . $one($m[2]) : '') . ']';
			}, $part) ?? $part;
			$out .= str_replace(',', ';', $part);
		}
		return '=' . $out;
	}

	/** A cell's text without Markdown emphasis and code marks. */
	private static function plain(string $value): string {
		$value = preg_replace(['/\*\*(.+?)\*\*/', '/`([^`]+)`/', '/<br\s*\/?>/i'], ['$1', '$1', "\n"], trim($value)) ?? $value;
		return str_replace('\|', '|', $value);
	}

	/** The column width class nearest above this many characters. */
	private static function width(int $chars): int {
		foreach ([6, 10, 15, 20, 30, 45, 60] as $w) {
			if ($chars <= $w) {
				return $w;
			}
		}
		return 60;
	}

	/** @return list<list<string>> */
	private static function csv(string $text): array {
		$first = strtok($text, "\n") ?: '';
		$separator = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
		$rows = [];
		foreach (explode("\n", str_replace("\r\n", "\n", trim($text))) as $line) {
			$rows[] = str_getcsv($line, $separator, '"', '');
		}
		return $rows;
	}
}
