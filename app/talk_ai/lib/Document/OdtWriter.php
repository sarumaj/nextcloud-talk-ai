<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Markdown as an OpenDocument text (.odt), which Collabora turns into PDF:
 * headings, paragraphs, bullet and numbered lists (nested, with check boxes), **bold**,
 * *italic*, `code`, [links](url), code blocks, tables, block quotes and rules,
 * LaTeX formulas ($…$ inline, $$…$$ on their own lines) as formula objects, and
 * corrections: ~~deleted~~ (struck through, red), ++inserted++ (underlined,
 * green) and ==highlighted== text.
 */
class OdtWriter {
	/** @var list<string> the formulas of the document being written, as MathML */
	private static array $formulas = [];

	public function write(string $markdown, string $title = ''): string {
		self::$formulas = [];
		$body = self::blocks(str_replace("\r\n", "\n", $markdown));
		return self::package($body, $title);
	}

	private static function package(string $body, string $title): string {
		$objects = [];
		foreach (self::$formulas as $n => $mathml) {
			$objects['Formula' . ($n + 1)] = $mathml;
		}
		self::$formulas = [];
		return Odf::package(Odf::TEXT, self::CONTENT_HEAD . $body . '</office:text></office:body></office:document-content>', self::STYLES, $title, $objects);
	}

	/**
	 * Flash cards to print: a two-column table, the question on the left and the
	 * answer on the right, to fold or cut along the middle; a card never splits
	 * across pages.
	 *
	 * @param list<array{front: string, back: string}> $cards
	 */
	public function cards(array $cards, string $title): string {
		self::$formulas = [];
		$body = $title === '' ? '' : '<text:h text:style-name="Heading_20_1" text:outline-level="1">' . self::inline($title) . '</text:h>';
		$body .= '<table:table table:style-name="Table"><table:table-column table:style-name="Half" table:number-columns-repeated="2"/>';
		foreach ($cards as $n => $card) {
			$body .= '<table:table-row table:style-name="Card">';
			foreach ([$card['front'], $card['back']] as $side => $text) {
				$body .= '<table:table-cell table:style-name="CardCell" office:value-type="string">'
					. '<text:p text:style-name="Card_20_Number">' . ($n + 1) . ($side === 0 ? '' : ' ✓') . '</text:p>'
					. self::cellBlocks($text, $side === 0 ? 'Card_20_Front' : 'Card_20_Back')
					. '</table:table-cell>';
			}
			$body .= '</table:table-row>';
		}
		return self::package($body . '</table:table>', $title);
	}

	/** A card side's text: its lines as paragraphs of this style. */
	private static function cellBlocks(string $text, string $style): string {
		$out = '';
		foreach (preg_split('/\n+/', trim(str_replace("\r\n", "\n", $text))) ?: [''] as $line) {
			$out .= '<text:p text:style-name="' . $style . '">' . self::inline(preg_replace('/^\s*[-*+]\s+/', '• ', $line) ?? $line) . '</text:p>';
		}
		return $out;
	}

	/** The document's body: Markdown's blocks as ODF elements. */
	public static function blocks(string $markdown): string {
		$lines = explode("\n", $markdown);
		$out = '';
		$paragraph = [];
		$flush = function () use (&$paragraph, &$out): void {
			if ($paragraph !== []) {
				$out .= '<text:p text:style-name="Text_20_body">' . self::inline(implode(' ', $paragraph)) . '</text:p>';
				$paragraph = [];
			}
		};
		for ($i = 0; $i < count($lines); $i++) {
			$line = $lines[$i];
			if (preg_match('/^\s*(\$\$|\\\\\\[)/', $line, $open) === 1) {
				// A formula on its own lines, centred.
				$flush();
				$close = $open[1] === '$$' ? '$$' : '\\]';
				$latex = substr(ltrim($line), 2);
				while (!str_contains($latex, $close) && $i + 1 < count($lines)) {
					$latex .= "\n" . $lines[++$i];
				}
				[$latex, $after] = array_pad(explode($close, $latex, 2), 2, '');
				$out .= '<text:p text:style-name="Formula_20_Display">' . self::formula($latex, true) . '</text:p>';
				if (trim($after) !== '') {
					$paragraph[] = trim($after);
				}
			} elseif (preg_match('/^```/', $line) === 1) {
				$flush();
				$code = [];
				for ($i++; $i < count($lines) && preg_match('/^```/', $lines[$i]) !== 1; $i++) {
					$code[] = $lines[$i];
				}
				foreach ($code === [] ? [''] : $code as $c) {
					$out .= '<text:p text:style-name="Preformatted_20_Text">' . self::spaces(self::xml($c)) . '</text:p>';
				}
			} elseif (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
				$flush();
				$level = strlen($m[1]);
				$out .= '<text:h text:style-name="Heading_20_' . $level . '" text:outline-level="' . $level . '">' . self::inline(rtrim($m[2], ' #')) . '</text:h>';
			} elseif (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
				$flush();
				$out .= '<text:p text:style-name="Horizontal_20_Line"/>';
			} elseif (preg_match(self::ITEM, $line) === 1) {
				$flush();
				$items = [];
				for (; $i < count($lines) && (preg_match(self::ITEM, $lines[$i]) === 1 || ($items !== [] && preg_match('/^\s{2,}\S/', $lines[$i]) === 1)); $i++) {
					if (preg_match(self::ITEM, $lines[$i], $item) === 1) {
						$items[] = [strlen(str_replace("\t", '    ', $item[1])), ctype_digit($item[2][0]), $item[3]];
					} else {
						$items[count($items) - 1][2] .= ' ' . trim($lines[$i]);
					}
				}
				$i--;
				$out .= self::listOf($items);
			} elseif (Odf::isTable($lines, $i)) {
				$flush();
				$rows = [Odf::cells($line)];
				for ($i += 2; $i < count($lines) && str_starts_with(ltrim($lines[$i]), '|'); $i++) {
					$rows[] = Odf::cells($lines[$i]);
				}
				$i--;
				$columns = max(array_map('count', $rows));
				$out .= '<table:table table:style-name="Table"><table:table-column table:number-columns-repeated="' . $columns . '"/>';
				foreach ($rows as $r => $cells) {
					$out .= '<table:table-row>';
					for ($c = 0; $c < $columns; $c++) {
						$out .= '<table:table-cell table:style-name="Cell" office:value-type="string"><text:p text:style-name="' . ($r === 0 ? 'Table_20_Heading' : 'Table_20_Contents') . '">' . self::inline($cells[$c] ?? '') . '</text:p></table:table-cell>';
					}
					$out .= '</table:table-row>';
				}
				$out .= '</table:table>';
			} elseif (preg_match('/^>\s?(.*)$/', $line, $m) === 1) {
				$flush();
				$out .= '<text:p text:style-name="Quotations">' . self::inline($m[1]) . '</text:p>';
			} elseif (trim($line) === '') {
				$flush();
			} else {
				$paragraph[] = trim($line);
			}
		}
		$flush();
		return $out;
	}

	/** A list item: indent, marker, text. */
	private const ITEM = '/^(\s*)([-*+]|\d+[.)])\s+(.*)$/';

	/**
	 * Items as a list, nested by their indent; "[ ]" and "[x]" become boxes.
	 *
	 * @param list<array{int, bool, string}> $items indent, numbered, text
	 */
	private static function listOf(array $items): string {
		$indent = $items[0][0];
		$out = '<text:list text:style-name="' . ($items[0][1] ? 'Numbering_20_123' : 'List_20_Bullet') . '">';
		for ($n = 0; $n < count($items); $n++) {
			$text = preg_replace(['/^\[ \]\s*/', '/^\[[xX]\]\s*/'], ['☐ ', '☑ '], $items[$n][2]) ?? $items[$n][2];
			$out .= '<text:list-item><text:p text:style-name="List_20_Paragraph">' . self::inline($text) . '</text:p>';
			$children = [];
			while (isset($items[$n + 1]) && $items[$n + 1][0] > $indent) {
				$children[] = $items[++$n];
			}
			if ($children !== []) {
				$out .= self::listOf($children);
			}
			$out .= '</text:list-item>';
		}
		return $out . '</text:list>';
	}

	/** Inline Markdown: code first (nothing inside it is formatting), then links, bold and italic. */
	public static function inline(string $text): string {
		$parts = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
		$out = '';
		foreach ($parts as $part) {
			if (strlen($part) > 1 && $part[0] === '`' && str_ends_with($part, '`')) {
				$out .= '<text:span text:style-name="Code">' . self::xml(substr($part, 1, -1)) . '</text:span>';
				continue;
			}
			// Formulas first, kept out of the formatting below as placeholders.
			$formulas = [];
			$part = Math::replace($part, function (string $latex, bool $display) use (&$formulas): string {
				$formulas[] = self::formula($latex, false);
				return "\u{E000}" . (count($formulas) - 1) . "\u{E001}";
			});
			$x = self::xml($part);
			$x = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<text:a xlink:type="simple" xlink:href="$2">$1</text:a>', $x) ?? $x;
			$x = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<text:span text:style-name="Strong">$1$2</text:span>', $x) ?? $x;
			$x = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])|(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/', '<text:span text:style-name="Emphasis">$1$2</text:span>', $x) ?? $x;
			$x = preg_replace(['/~~(?!\s)(.+?)(?<!\s)~~/', '/(?<![\w+])\+\+(?![\s+])(.+?)(?<![\s+])\+\+(?![\w+])/', '/==(?!\s)(.+?)(?<!\s)==/'],
				['<text:span text:style-name="Deleted">$1</text:span>', '<text:span text:style-name="Inserted">$1</text:span>', '<text:span text:style-name="Highlight">$1</text:span>'], $x) ?? $x;
			$out .= preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', fn (array $m) => $formulas[(int)$m[1]], $x) ?? $x;
		}
		return $out;
	}

	/** A formula object in the text, anchored as a character; its MathML is an object of the package. */
	private static function formula(string $latex, bool $display): string {
		self::$formulas[] = Math::mathml(trim($latex), $display);
		$name = 'Formula' . count(self::$formulas);
		return '<draw:frame draw:style-name="Formula" draw:name="' . $name . '" text:anchor-type="as-char" draw:z-index="0">'
			. '<draw:object xlink:href="./' . $name . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/></draw:frame>';
	}

	private static function xml(string $text): string {
		return Odf::xml($text);
	}

	/** Runs of spaces kept (code), as ODF collapses them otherwise. */
	private static function spaces(string $text): string {
		return preg_replace_callback('/ {2,}/', fn (array $m) => ' <text:s text:c="' . (strlen($m[0]) - 1) . '"/>', $text) ?? $text;
	}

	/** Bullets and numbers for four levels of lists. */
	private const BULLETS = '<text:list-style style:name="List_20_Bullet" style:display-name="List Bullet">'
		. '<text:list-level-style-bullet text:level="1" text:bullet-char="•"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.5cm" fo:margin-left="1cm"/></style:list-level-properties></text:list-level-style-bullet>'
		. '<text:list-level-style-bullet text:level="2" text:bullet-char="◦"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.5cm" fo:margin-left="1.7cm"/></style:list-level-properties></text:list-level-style-bullet>'
		. '<text:list-level-style-bullet text:level="3" text:bullet-char="▪"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.5cm" fo:margin-left="2.4cm"/></style:list-level-properties></text:list-level-style-bullet>'
		. '<text:list-level-style-bullet text:level="4" text:bullet-char="–"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.5cm" fo:margin-left="3.1cm"/></style:list-level-properties></text:list-level-style-bullet>'
		. '</text:list-style>';

	private const NUMBERS = '<text:list-style style:name="Numbering_20_123" style:display-name="Numbering 123">'
		. '<text:list-level-style-number text:level="1" style:num-suffix="." style:num-format="1"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.6cm" fo:margin-left="1cm"/></style:list-level-properties></text:list-level-style-number>'
		. '<text:list-level-style-number text:level="2" style:num-suffix="." style:num-format="a"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.6cm" fo:margin-left="1.7cm"/></style:list-level-properties></text:list-level-style-number>'
		. '<text:list-level-style-number text:level="3" style:num-suffix="." style:num-format="i"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.6cm" fo:margin-left="2.4cm"/></style:list-level-properties></text:list-level-style-number>'
		. '<text:list-level-style-number text:level="4" style:num-suffix="." style:num-format="1"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.6cm" fo:margin-left="3.1cm"/></style:list-level-properties></text:list-level-style-number>'
		. '</text:list-style>';

	private const CONTENT_HEAD = '<office:document-content ' . Odf::NAMESPACES . '><office:automatic-styles><style:style style:name="Table" style:family="table"><style:table-properties style:width="17cm" table:align="margins"/></style:style><style:style style:name="Cell" style:family="table-cell"><style:table-cell-properties fo:padding="0.1cm" fo:border="0.5pt solid #999999"/></style:style>'
		. '<style:style style:name="Half" style:family="table-column"><style:table-column-properties style:rel-width="1*"/></style:style>'
		. '<style:style style:name="Card" style:family="table-row"><style:table-row-properties style:min-row-height="4.5cm" fo:keep-together="always"/></style:style>'
		. '<style:style style:name="CardCell" style:family="table-cell"><style:table-cell-properties fo:padding="0.4cm" fo:border="0.5pt dashed #888888" style:vertical-align="middle"/></style:style>'
		. '<style:style style:name="Formula" style:family="graphic"><style:graphic-properties style:vertical-pos="middle" style:vertical-rel="text" fo:margin-left="0.05cm" fo:margin-right="0.05cm" draw:ole-draw-aspect="1"/></style:style>'
		. '</office:automatic-styles><office:body><office:text>';

	private const STYLES = '<office:document-styles ' . Odf::NAMESPACES . '><office:font-face-decls><style:font-face style:name="Liberation Sans" svg:font-family="&apos;Liberation Sans&apos;"/><style:font-face style:name="Liberation Mono" svg:font-family="&apos;Liberation Mono&apos;"/></office:font-face-decls><office:styles>'
		. '<style:default-style style:family="paragraph"><style:paragraph-properties fo:margin-bottom="0.2cm"/><style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt"/></style:default-style>'
		. '<style:style style:name="Standard" style:family="paragraph"/>'
		. '<style:style style:name="Text_20_body" style:display-name="Text body" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-bottom="0.25cm" fo:line-height="115%"/></style:style>'
		. '<style:style style:name="Heading" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-top="0.4cm" fo:margin-bottom="0.2cm" fo:keep-with-next="always"/><style:text-properties fo:font-weight="bold"/></style:style>'
		. '<style:style style:name="Heading_20_1" style:display-name="Heading 1" style:family="paragraph" style:parent-style-name="Heading" style:default-outline-level="1"><style:text-properties fo:font-size="20pt"/></style:style>'
		. '<style:style style:name="Heading_20_2" style:display-name="Heading 2" style:family="paragraph" style:parent-style-name="Heading" style:default-outline-level="2"><style:text-properties fo:font-size="16pt"/></style:style>'
		. '<style:style style:name="Heading_20_3" style:display-name="Heading 3" style:family="paragraph" style:parent-style-name="Heading" style:default-outline-level="3"><style:text-properties fo:font-size="13pt"/></style:style>'
		. '<style:style style:name="Heading_20_4" style:display-name="Heading 4" style:family="paragraph" style:parent-style-name="Heading" style:default-outline-level="4"><style:text-properties fo:font-size="12pt"/></style:style>'
		. '<style:style style:name="Heading_20_5" style:display-name="Heading 5" style:family="paragraph" style:parent-style-name="Heading" style:default-outline-level="5"><style:text-properties fo:font-size="11pt"/></style:style>'
		. '<style:style style:name="Heading_20_6" style:display-name="Heading 6" style:family="paragraph" style:parent-style-name="Heading" style:default-outline-level="6"><style:text-properties fo:font-size="11pt" fo:font-style="italic"/></style:style>'
		. '<style:style style:name="List_20_Paragraph" style:display-name="List Paragraph" style:family="paragraph" style:parent-style-name="Text_20_body"><style:paragraph-properties fo:margin-bottom="0.1cm"/></style:style>'
		. '<style:style style:name="Preformatted_20_Text" style:display-name="Preformatted Text" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-bottom="0cm" fo:background-color="#f2f2f2"/><style:text-properties style:font-name="Liberation Mono" fo:font-size="9.5pt"/></style:style>'
		. '<style:style style:name="Quotations" style:family="paragraph" style:parent-style-name="Text_20_body"><style:paragraph-properties fo:margin-left="0.8cm" fo:border-left="1.5pt solid #bbbbbb" fo:padding-left="0.3cm"/><style:text-properties fo:font-style="italic"/></style:style>'
		. '<style:style style:name="Horizontal_20_Line" style:display-name="Horizontal Line" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:border-bottom="0.5pt solid #999999" fo:padding-bottom="0.1cm" fo:margin-bottom="0.3cm"/></style:style>'
		. '<style:style style:name="Table_20_Contents" style:display-name="Table Contents" style:family="paragraph" style:parent-style-name="Standard"/>'
		. '<style:style style:name="Table_20_Heading" style:display-name="Table Heading" style:family="paragraph" style:parent-style-name="Table_20_Contents"><style:text-properties fo:font-weight="bold"/></style:style>'
		. '<style:style style:name="Formula_20_Display" style:display-name="Formula Display" style:family="paragraph" style:parent-style-name="Text_20_body"><style:paragraph-properties fo:text-align="center" fo:margin-top="0.2cm" fo:margin-bottom="0.3cm"/></style:style>'
		. '<style:style style:name="Card_20_Number" style:display-name="Card Number" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:text-align="end"/><style:text-properties fo:font-size="8pt" fo:color="#888888"/></style:style>'
		. '<style:style style:name="Card_20_Front" style:display-name="Card Front" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="14pt" fo:font-weight="bold"/></style:style>'
		. '<style:style style:name="Card_20_Back" style:display-name="Card Back" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="12pt"/></style:style>'
		. '<style:style style:name="Strong" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
		. '<style:style style:name="Emphasis" style:family="text"><style:text-properties fo:font-style="italic"/></style:style>'
		. '<style:style style:name="Deleted" style:family="text"><style:text-properties style:text-line-through-style="solid" style:text-line-through-type="single" fo:color="#c00000"/></style:style>'
		. '<style:style style:name="Inserted" style:family="text"><style:text-properties style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color" fo:color="#00804a"/></style:style>'
		. '<style:style style:name="Highlight" style:family="text"><style:text-properties fo:background-color="#fff59d"/></style:style>'
		. '<style:style style:name="Code" style:family="text"><style:text-properties style:font-name="Liberation Mono" fo:font-size="9.5pt" fo:background-color="#f2f2f2"/></style:style>'
		. self::BULLETS
		. self::NUMBERS
		. '</office:styles><office:automatic-styles><style:page-layout style:name="Page"><style:page-layout-properties fo:page-width="21cm" fo:page-height="29.7cm" fo:margin-top="2cm" fo:margin-bottom="2cm" fo:margin-left="2cm" fo:margin-right="2cm"/></style:page-layout></office:automatic-styles><office:master-styles><style:master-page style:name="Standard" style:page-layout-name="Page"/></office:master-styles></office:document-styles>';
}
