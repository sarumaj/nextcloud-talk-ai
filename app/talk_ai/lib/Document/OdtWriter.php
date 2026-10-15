<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Markdown as an OpenDocument text (.odt), which Collabora turns into PDF or Word.
 *
 * Text: headings, paragraphs, lists (nested, with check boxes), **bold**, *italic*,
 * `code`, [links](url), block quotes, rules, tables (header shaded, rows striped,
 * columns aligned by :--- / :---: / ---:), LaTeX formulas ($…$, $$…$$) as formula
 * objects, corrections (~~deleted~~, ++inserted++, ==highlighted==), footnotes
 * ([^1] and "[^1]: text"), and code blocks highlighted by language.
 *
 * Design: a theme (modern, classic, academic with numbered headings, minimal,
 * playful), a cover page, a footer with the title and page numbers, landscape
 * pages, callout boxes ("> [!NOTE] Title", also TIP, IMPORTANT, WARNING, CAUTION,
 * DEFINITION, THEOREM, EXAMPLE, SUMMARY, QUESTION), a table of contents ([TOC]),
 * page breaks (\pagebreak), columns (::: columns 2 … :::) and pictures (```chart
 * blocks and images ![caption](source) on their own lines).
 */
class OdtWriter {
	/** Themes: body font, heading font, size, accent, heading colour, light shade, justified, numbered headings, line height. */
	public const THEMES = [
		'modern' => ['body' => 'Carlito', 'heading' => 'Carlito', 'size' => 11, 'accent' => '#2e75b6', 'headings' => '#1f3864', 'light' => '#eaf1fb', 'justify' => false, 'numbered' => false, 'line' => 120],
		'classic' => ['body' => 'Liberation Serif', 'heading' => 'Liberation Serif', 'size' => 12, 'accent' => '#7b1e1e', 'headings' => '#3a2a1a', 'light' => '#f6efe6', 'justify' => true, 'numbered' => false, 'line' => 125],
		'academic' => ['body' => 'Liberation Serif', 'heading' => 'Liberation Sans', 'size' => 12, 'accent' => '#333333', 'headings' => '#000000', 'light' => '#efefef', 'justify' => true, 'numbered' => true, 'line' => 150],
		'minimal' => ['body' => 'Liberation Sans', 'heading' => 'Liberation Sans', 'size' => 10.5, 'accent' => '#555555', 'headings' => '#222222', 'light' => '#f4f4f4', 'justify' => false, 'numbered' => false, 'line' => 125],
		'playful' => ['body' => 'DejaVu Sans', 'heading' => 'DejaVu Sans', 'size' => 10.5, 'accent' => '#e15759', 'headings' => '#4e3a8f', 'light' => '#fff3e0', 'justify' => false, 'numbered' => false, 'line' => 125],
	];

	/** Callouts: border colour, background, label. */
	private const CALLOUTS = [
		'note' => ['#2e75b6', '#eaf1fb', 'ℹ Note'], 'info' => ['#2e75b6', '#eaf1fb', 'ℹ Info'], 'tip' => ['#2e8b57', '#e8f5ec', '✔ Tip'],
		'important' => ['#7b3fa0', '#f3eaf8', '★ Important'], 'warning' => ['#d4870b', '#fdf3e2', '⚠ Warning'], 'caution' => ['#c0392b', '#fbeaea', '⚠ Caution'],
		'definition' => ['#16808a', '#e6f4f5', '▸ Definition'], 'theorem' => ['#3949ab', '#ebedf8', '▸ Theorem'], 'example' => ['#6d6d6d', '#f2f2f2', '▸ Example'],
		'summary' => ['#1f3864', '#e9edf5', '≡ Summary'], 'question' => ['#b58900', '#fdf7e1', '? Question'],
	];

	/** @var list<string> the formulas of the document being written, as MathML */
	private static array $formulas = [];
	/** @var array<string, array{string, string}> its pictures: path => [data, MIME type] */
	private static array $pictures = [];
	/** @var (callable(string): ?array)|null finds an image by its source (file:123, a URL) */
	private static $images = null;
	/** @var array<string, string> footnotes by their label */
	private static array $notes = [];
	private static int $noteCount = 0;
	private static array $theme = self::THEMES['modern'];
	/** The text width in cm (pictures, tables). */
	private static float $width = 17.0;
	/** @var list<int> heading numbers (academic theme) */
	private static array $numbers = [];
	/** The level numbered 1 (the highest used), and whether to number at all (not when the headings carry numbers). */
	private static int $topLevel = 1;
	private static bool $numbering = true;

	/**
	 * @param (callable(string): ?array)|null $images an image's data, mime, width and height by its source
	 * @param array{theme?: string, cover?: array{title?: string, subtitle?: string, author?: string, date?: string}|null, landscape?: bool, footer?: bool} $options
	 */
	public function write(string $markdown, string $title = '', ?callable $images = null, array $options = []): string {
		self::reset($images, $options);
		$cover = self::cover($options['cover'] ?? null, $title);
		$body = self::blocks(str_replace("\r\n", "\n", $markdown));
		return self::package($cover . $body, $title, $options);
	}

	/**
	 * Flash cards to print: a two-column table, the question on the left and the
	 * answer on the right, to fold or cut along the middle; a card never splits
	 * across pages.
	 *
	 * @param list<array{front: string, back: string}> $cards
	 */
	public function cards(array $cards, string $title, string $theme = 'modern'): string {
		self::reset(null, ['theme' => $theme]);
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
		return self::package($body . '</table:table>', $title, ['footer' => true]);
	}

	private static function reset(?callable $images, array $options): void {
		self::$formulas = [];
		self::$pictures = [];
		self::$images = $images;
		self::$notes = [];
		self::$noteCount = 0;
		self::$numbers = [];
		self::$topLevel = 1;
		self::$numbering = true;
		self::$theme = self::THEMES[strtolower((string)($options['theme'] ?? 'modern'))] ?? self::THEMES['modern'];
		self::$width = !empty($options['landscape']) ? 25.7 : 17.0;
	}

	private static function package(string $body, string $title, array $options): string {
		$objects = [];
		foreach (self::$formulas as $n => $mathml) {
			$objects['Formula' . ($n + 1)] = $mathml;
		}
		$pictures = self::$pictures;
		$content = '<office:document-content ' . Odf::NAMESPACES . '><office:automatic-styles>' . self::automaticStyles() . '</office:automatic-styles>'
			. '<office:body><office:text>' . $body . '</office:text></office:body></office:document-content>';
		$styles = self::styles($title, !empty($options['landscape']), ($options['footer'] ?? true) !== false);
		// Back to the defaults: nothing of this document reaches the next.
		self::reset(null, []);
		return Odf::package(Odf::TEXT, $content, $styles, $title, $objects, $pictures);
	}

	/** A cover page (title, subtitle, author, date), on a page without footer. */
	private static function cover(?array $cover, string $title): string {
		if ($cover === null || $cover === [] || $cover === false) {
			return '';
		}
		$out = '<text:p text:style-name="Cover_20_Top"/>'
			. '<text:p text:style-name="Cover_20_Title">' . self::inline((string)($cover['title'] ?? $title)) . '</text:p>';
		if (trim((string)($cover['subtitle'] ?? '')) !== '') {
			$out .= '<text:p text:style-name="Cover_20_Subtitle">' . self::inline((string)$cover['subtitle']) . '</text:p>';
		}
		$out .= '<text:p text:style-name="Cover_20_Rule"/>';
		foreach (['author', 'date', 'institution'] as $key) {
			if (trim((string)($cover[$key] ?? '')) !== '') {
				$out .= '<text:p text:style-name="Cover_20_Meta">' . self::inline((string)$cover[$key]) . '</text:p>';
			}
		}
		// The first paragraph after the cover starts the pages with footer.
		return $out . '<text:p text:style-name="After_20_Cover"/>';
	}

	/** The document's body: Markdown's blocks as ODF elements. */
	public static function blocks(string $markdown): string {
		$lines = explode("\n", $markdown);
		// Footnote definitions ("[^1]: text") are taken out first; headings are collected for [TOC].
		$headings = [];
		$fence = false;
		foreach ($lines as $n => $line) {
			if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
				$fence = !$fence;
			} elseif (!$fence && preg_match('/^\[\^([^\]]+)\]:\s*(.*)$/', $line, $m) === 1) {
				self::$notes[$m[1]] = $m[2];
				unset($lines[$n]);
			} elseif (!$fence && preg_match('/^(#{1,3})\s+(.*)$/', $line, $m) === 1) {
				$headings[] = [strlen($m[1]), rtrim($m[2], ' #')];
			}
		}
		$lines = array_values($lines);
		if ($headings !== []) {
			self::$topLevel = min(array_column($headings, 0));
			self::$numbering = !array_filter($headings, fn ($h) => preg_match('/^(\d+(\.\d+)*\.?|[IVX]+\.)\s/', $h[1]) === 1);
		}
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
			if (preg_match('/^(```|~~~)(chart|plot)\s*$/', $line) === 1) {
				// A chart: its JSON up to the closing fence.
				$flush();
				$json = [];
				for ($i++; $i < count($lines) && preg_match('/^(```|~~~)\s*$/', $lines[$i]) !== 1; $i++) {
					$json[] = $lines[$i];
				}
				try {
					$out .= self::figure(Figures::chart(implode("\n", $json)), '', 'chart');
				} catch (\Throwable $e) {
					$out .= '<text:p text:style-name="Caption">[chart: ' . self::xml($e->getMessage()) . ']</text:p>';
				}
			} elseif (preg_match(Figures::IMAGE, $line, $image) === 1) {
				$flush();
				$picture = null;
				try {
					$picture = self::$images !== null ? (self::$images)($image[2]) : null;
				} catch (\Throwable) {
				}
				$out .= self::figure($picture, $image[1], $image[2]);
			} elseif (preg_match('/^\s*(\\\\pagebreak|\\\\newpage|<!--\s*pagebreak\s*-->|-{3}\s*pagebreak\s*-{3})\s*$/i', $line) === 1) {
				$flush();
				$out .= '<text:p text:style-name="Page_20_Break"/>';
			} elseif (preg_match('/^\s*\[\[?TOC\]\]?\s*$/i', $line) === 1) {
				$flush();
				$out .= self::contents($headings);
			} elseif (preg_match('/^:::\s*columns?\s*(\d)?\s*$/i', $line, $m) === 1) {
				// Columns: the lines up to ":::" in a section of 2 or 3 columns.
				$flush();
				$inner = [];
				for ($i++; $i < count($lines) && preg_match('/^:::\s*$/', $lines[$i]) !== 1; $i++) {
					$inner[] = $lines[$i];
				}
				$count = max(2, min(3, (int)($m[1] ?? 2) ?: 2));
				$out .= '<text:section text:style-name="Columns' . $count . '" text:name="Columns' . $i . '">' . self::blocks(implode("\n", $inner)) . '</text:section>';
			} elseif (preg_match('/^\s*(\$\$|\\\\\\[)/', $line, $open) === 1) {
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
			} elseif (preg_match('/^(?:```|~~~)\s*([\w#+.-]*)/', $line, $m) === 1) {
				$flush();
				$code = [];
				for ($i++; $i < count($lines) && preg_match('/^(```|~~~)\s*$/', $lines[$i]) !== 1; $i++) {
					$code[] = $lines[$i];
				}
				$label = Code::label($m[1]);
				if ($label !== '') {
					$out .= '<text:p text:style-name="Code_20_Label">' . self::xml($label) . '</text:p>';
				}
				foreach (Code::lines(implode("\n", $code === [] ? [''] : $code), $m[1]) as $c) {
					// An empty line keeps a space: an empty paragraph breaks the box's border.
					$out .= '<text:p text:style-name="Preformatted_20_Text">' . ($c === '' ? '<text:s/>' : $c) . '</text:p>';
				}
			} elseif (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
				$flush();
				$level = strlen($m[1]);
				$out .= '<text:h text:style-name="Heading_20_' . $level . '" text:outline-level="' . $level . '">' . self::number($level) . self::inline(rtrim($m[2], ' #')) . '</text:h>';
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
				$align = array_map(fn (string $c) => str_starts_with($c, ':') && str_ends_with($c, ':') ? 'Center' : (str_ends_with($c, ':') ? 'Right' : 'Left'), Odf::cells($lines[$i + 1]));
				for ($i += 2; $i < count($lines) && str_starts_with(ltrim($lines[$i]), '|'); $i++) {
					$rows[] = Odf::cells($lines[$i]);
				}
				$i--;
				$out .= self::table($rows, $align);
			} elseif (preg_match('/^>\s?\[!(\w+)\]\s*(.*)$/', $line, $m) === 1) {
				// A callout: its lines up to the end of the quote.
				$flush();
				$type = strtolower($m[1]);
				$type = isset(self::CALLOUTS[$type]) ? $type : 'note';
				$inner = [];
				for ($i++; $i < count($lines) && preg_match('/^>\s?(.*)$/', $lines[$i], $q) === 1; $i++) {
					$inner[] = $q[1];
				}
				$i--;
				$out .= self::callout($type, trim($m[2]), $inner);
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

	/** A heading's number in the academic theme: 1, 1.1, 1.1.1 (levels 1–3). */
	private static function number(int $level): string {
		$level -= self::$topLevel - 1;
		if (!self::$theme['numbered'] || !self::$numbering || $level < 1 || $level > 3) {
			return '';
		}
		self::$numbers = array_slice(self::$numbers, 0, $level);
		self::$numbers = array_pad(self::$numbers, $level, 0);
		self::$numbers[$level - 1]++;
		return implode('.', self::$numbers) . '  ';
	}

	/** The table of contents: the headings of levels 1–3 (numbered like them), indented by level. */
	private static function contents(array $headings): string {
		$out = '';
		$numbers = [];
		foreach ($headings as [$level, $text]) {
			$prefix = '';
			$level -= self::$topLevel - 1;
			if ($level < 1) {
				continue;
			}
			if (self::$theme['numbered'] && self::$numbering) {
				$numbers = array_pad(array_slice($numbers, 0, $level), $level, 0);
				$numbers[$level - 1]++;
				$prefix = implode('.', $numbers) . '  ';
			}
			$out .= '<text:p text:style-name="Contents_20_' . $level . '">' . self::xml($prefix) . self::inline($text) . '</text:p>';
		}
		return $out === '' ? '' : $out . '<text:p text:style-name="Contents_20_End"/>';
	}

	/** A table: the header row shaded, the rows striped, the columns aligned. */
	private static function table(array $rows, array $align): string {
		$columns = max(array_map('count', $rows));
		$out = '<table:table table:style-name="Table"><table:table-column table:number-columns-repeated="' . $columns . '"/>';
		foreach ($rows as $r => $cells) {
			$out .= $r === 0 ? '<table:table-header-rows><table:table-row>' : '<table:table-row>';
			for ($c = 0; $c < $columns; $c++) {
				$cell = $r === 0 ? 'HeadCell' : ($r % 2 === 0 ? 'CellAlt' : 'Cell');
				$paragraph = ($r === 0 ? 'Table_20_Heading_' : 'Table_20_Contents_') . ($align[$c] ?? 'Left');
				$out .= '<table:table-cell table:style-name="' . $cell . '" office:value-type="string"><text:p text:style-name="' . $paragraph . '">' . self::inline($cells[$c] ?? '') . '</text:p></table:table-cell>';
			}
			$out .= $r === 0 ? '</table:table-row></table:table-header-rows>' : '</table:table-row>';
		}
		return $out . '</table:table>';
	}

	/** A callout box: its title (or the type's label) in bold and colour, then its lines. */
	private static function callout(string $type, string $title, array $lines): string {
		$style = 'Callout_' . $type;
		$out = '<text:p text:style-name="' . $style . '"><text:span text:style-name="CalloutTitle_' . $type . '">' . self::inline($title !== '' ? $title : self::CALLOUTS[$type][2]) . '</text:span></text:p>';
		$paragraph = [];
		foreach ([...$lines, ''] as $line) {
			$item = [];
			if (trim($line) === '' || preg_match('/^\s*([-*+]|\d+[.)])\s+(.*)$/', $line, $item) === 1) {
				if ($paragraph !== []) {
					$out .= '<text:p text:style-name="' . $style . '">' . self::inline(implode(' ', $paragraph)) . '</text:p>';
					$paragraph = [];
				}
				if (isset($item[2]) && trim($line) !== '') {
					$out .= '<text:p text:style-name="' . $style . '">' . (ctype_digit($item[1][0]) ? self::xml($item[1]) : '•') . ' ' . self::inline($item[2]) . '</text:p>';
				}
				continue;
			}
			$paragraph[] = trim($line);
		}
		return $out;
	}

	/**
	 * A picture as a centred paragraph with its caption below; a picture that
	 * cannot be had is named instead.
	 */
	private static function figure(?array $picture, string $caption, string $source): string {
		if ($picture === null) {
			return '<text:p text:style-name="Caption">[' . self::xml($caption !== '' ? $caption : $source) . ': image not available]</text:p>';
		}
		$path = Odf::picturePath(count(self::$pictures) + 1, $picture['mime']);
		self::$pictures[$path] = [$picture['data'], $picture['mime']];
		$caption = $caption !== '' ? $caption : (string)($picture['caption'] ?? '');
		return '<text:p text:style-name="Figure">' . Odf::picture($path, $picture, self::$width - 1, 20, 'text:anchor-type="as-char" ') . '</text:p>'
			. ($caption !== '' ? '<text:p text:style-name="Caption">' . self::inline($caption) . '</text:p>' : '');
	}

	/** A card side's text: its lines as paragraphs of this style. */
	private static function cellBlocks(string $text, string $style): string {
		$out = '';
		foreach (preg_split('/\n+/', trim(str_replace("\r\n", "\n", $text))) ?: [''] as $line) {
			$out .= '<text:p text:style-name="' . $style . '">' . self::inline(preg_replace('/^\s*[-*+]\s+/', '• ', $line) ?? $line) . '</text:p>';
		}
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

	/** Inline Markdown: code first (nothing inside it is formatting), then formulas, links, emphasis, corrections and footnotes. */
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
			$x = preg_replace('/\[([^\]^][^\]]*)\]\((https?:\/\/[^)\s]+)\)/', '<text:a xlink:type="simple" xlink:href="$2">$1</text:a>', $x) ?? $x;
			$x = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<text:span text:style-name="Strong">$1$2</text:span>', $x) ?? $x;
			$x = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])|(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/', '<text:span text:style-name="Emphasis">$1$2</text:span>', $x) ?? $x;
			$x = preg_replace(['/~~(?!\s)(.+?)(?<!\s)~~/', '/(?<![\w+])\+\+(?![\s+])(.+?)(?<![\s+])\+\+(?![\w+])/', '/==(?!\s)(.+?)(?<!\s)==/'],
				['<text:span text:style-name="Deleted">$1</text:span>', '<text:span text:style-name="Inserted">$1</text:span>', '<text:span text:style-name="Highlight">$1</text:span>'], $x) ?? $x;
			// Footnotes: [^label] where its text was given.
			$x = preg_replace_callback('/\[\^([^\]]+)\]/', function (array $m): string {
				$label = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
				if (!isset(self::$notes[$label])) {
					return $m[0];
				}
				$n = ++self::$noteCount;
				return '<text:note text:id="ftn' . $n . '" text:note-class="footnote"><text:note-citation>' . $n . '</text:note-citation>'
					. '<text:note-body><text:p text:style-name="Footnote">' . self::inline(self::$notes[$label]) . '</text:p></text:note-body></text:note>';
			}, $x) ?? $x;
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

	/** Styles of this document's content: tables, cells, frames and sections. */
	private static function automaticStyles(): string {
		$t = self::$theme;
		$s = '<style:style style:name="Table" style:family="table"><style:table-properties style:width="' . self::$width . 'cm" table:align="margins" fo:margin-top="0.2cm" fo:margin-bottom="0.3cm"/></style:style>'
			. '<style:style style:name="HeadCell" style:family="table-cell"><style:table-cell-properties fo:padding="0.12cm" fo:background-color="' . $t['accent'] . '" fo:border="0.5pt solid ' . $t['accent'] . '"/></style:style>'
			. '<style:style style:name="Cell" style:family="table-cell"><style:table-cell-properties fo:padding="0.12cm" fo:border="0.5pt solid #c8c8c8"/></style:style>'
			. '<style:style style:name="CellAlt" style:family="table-cell"><style:table-cell-properties fo:padding="0.12cm" fo:background-color="' . $t['light'] . '" fo:border="0.5pt solid #c8c8c8"/></style:style>'
			. '<style:style style:name="Half" style:family="table-column"><style:table-column-properties style:rel-width="1*"/></style:style>'
			. '<style:style style:name="Card" style:family="table-row"><style:table-row-properties style:min-row-height="4.5cm" fo:keep-together="always"/></style:style>'
			. '<style:style style:name="CardCell" style:family="table-cell"><style:table-cell-properties fo:padding="0.4cm" fo:border="0.5pt dashed #888888" style:vertical-align="middle"/></style:style>'
			. '<style:style style:name="Picture" style:family="graphic"><style:graphic-properties style:vertical-pos="top" style:vertical-rel="baseline" fo:margin-top="0.1cm" fo:margin-bottom="0.1cm"/></style:style>'
			. '<style:style style:name="Formula" style:family="graphic"><style:graphic-properties style:vertical-pos="middle" style:vertical-rel="text" fo:margin-left="0.05cm" fo:margin-right="0.05cm" draw:ole-draw-aspect="1"/></style:style>';
		foreach ([2, 3] as $n) {
			$s .= '<style:style style:name="Columns' . $n . '" style:family="section"><style:section-properties text:dont-balance-text-columns="false"><style:columns fo:column-count="' . $n . '" fo:column-gap="0.8cm"><style:column-sep style:width="0.5pt" style:color="#cccccc" style:height="100%"/></style:columns></style:section-properties></style:style>';
		}
		return $s;
	}

	/** The common styles of the theme, the page layout and its footer. */
	private static function styles(string $title, bool $landscape, bool $footer): string {
		$t = self::$theme;
		$align = $t['justify'] ? 'justify' : 'start';
		$p = fn (string $name, string $parent, string $paragraph, string $text, string $display = '', string $extra = '') =>
			'<style:style style:name="' . $name . '"' . ($display !== '' ? ' style:display-name="' . $display . '"' : '') . ' style:family="paragraph" style:parent-style-name="' . $parent . '"' . $extra . '>'
			. ($paragraph !== '' ? '<style:paragraph-properties ' . $paragraph . '/>' : '') . ($text !== '' ? '<style:text-properties ' . $text . '/>' : '') . '</style:style>';
		$heading = fn (int $level, float $size, string $extra = '') => $p('Heading_20_' . $level, 'Heading', $level === 1 && !$t['numbered'] && $t['headings'] !== '#000000'
			? 'fo:padding-bottom="0.08cm" fo:border-bottom="1pt solid ' . $t['accent'] . '"' : '', 'fo:font-size="' . $size . 'pt" ' . $extra, 'Heading ' . $level, ' style:default-outline-level="' . $level . '"');
		$styles = '<style:default-style style:family="paragraph"><style:paragraph-properties fo:margin-bottom="0.2cm"/><style:text-properties style:font-name="' . $t['body'] . '" fo:font-size="' . $t['size'] . 'pt" fo:language="de" fo:country="DE" fo:hyphenate="' . ($t['justify'] ? 'true' : 'false') . '"/></style:default-style>'
			. '<style:style style:name="Standard" style:family="paragraph"/>'
			. $p('Text_20_body', 'Standard', 'fo:margin-bottom="0.25cm" fo:line-height="' . $t['line'] . '%" fo:text-align="' . $align . '"', '', 'Text body')
			. $p('Heading', 'Standard', 'fo:margin-top="0.45cm" fo:margin-bottom="0.2cm" fo:keep-with-next="always"', 'style:font-name="' . $t['heading'] . '" fo:font-weight="bold" fo:color="' . $t['headings'] . '"')
			. $heading(1, $t['size'] + 9) . $heading(2, $t['size'] + 4) . $heading(3, $t['size'] + 2) . $heading(4, $t['size'] + 1) . $heading(5, $t['size'])
			. $heading(6, $t['size'], 'fo:font-style="italic"')
			. $p('List_20_Paragraph', 'Text_20_body', 'fo:margin-bottom="0.1cm"', '', 'List Paragraph')
			. $p('Preformatted_20_Text', 'Standard', 'fo:margin-top="0cm" fo:margin-bottom="0cm" fo:margin-left="0.2cm" fo:margin-right="0.2cm" fo:padding-left="0.25cm" fo:padding-right="0.2cm" fo:background-color="#f6f8fa" fo:border-left="2.5pt solid ' . $t['accent'] . '" fo:line-height="115%"', 'style:font-name="Liberation Mono" fo:font-size="' . ($t['size'] - 1.5) . 'pt" fo:hyphenate="false"', 'Preformatted Text')
			. $p('Code_20_Label', 'Standard', 'fo:margin-top="0.25cm" fo:margin-bottom="0cm" fo:margin-left="0.2cm"', 'style:font-name="Liberation Sans" fo:font-size="8pt" fo:font-weight="bold" fo:color="' . $t['accent'] . '"', 'Code Label', ' style:next-style-name="Preformatted_20_Text"')
			. $p('Quotations', 'Text_20_body', 'fo:margin-left="0.8cm" fo:border-left="1.5pt solid #bbbbbb" fo:padding-left="0.3cm"', 'fo:font-style="italic"')
			. $p('Horizontal_20_Line', 'Standard', 'fo:border-bottom="0.5pt solid #999999" fo:padding-bottom="0.1cm" fo:margin-bottom="0.3cm"', '', 'Horizontal Line')
			. $p('Formula_20_Display', 'Text_20_body', 'fo:text-align="center" fo:margin-top="0.2cm" fo:margin-bottom="0.3cm"', '', 'Formula Display')
			. $p('Figure', 'Standard', 'fo:text-align="center" fo:margin-top="0.3cm" fo:margin-bottom="0.1cm" fo:keep-with-next="always"', '')
			. $p('Caption', 'Standard', 'fo:text-align="center" fo:margin-bottom="0.4cm"', 'fo:font-size="' . ($t['size'] - 1.5) . 'pt" fo:font-style="italic" fo:color="#555555"')
			. $p('Footnote', 'Standard', 'fo:margin-bottom="0.05cm"', 'fo:font-size="' . ($t['size'] - 2) . 'pt"')
			. $p('Footer', 'Standard', 'fo:text-align="end" fo:border-top="0.5pt solid #cccccc" fo:padding-top="0.1cm"', 'fo:font-size="8.5pt" fo:color="#777777"')
			. $p('Page_20_Break', 'Standard', 'fo:break-before="page" fo:margin-bottom="0cm"', '', 'Page Break')
			. $p('Contents_20_1', 'Standard', 'fo:margin-top="0.15cm" fo:margin-bottom="0.05cm"', 'fo:font-weight="bold"', 'Contents 1')
			. $p('Contents_20_2', 'Standard', 'fo:margin-left="0.6cm" fo:margin-bottom="0.03cm"', '', 'Contents 2')
			. $p('Contents_20_3', 'Standard', 'fo:margin-left="1.2cm" fo:margin-bottom="0.03cm"', 'fo:font-size="' . ($t['size'] - 1) . 'pt"', 'Contents 3')
			. $p('Contents_20_End', 'Standard', 'fo:border-bottom="0.5pt solid #cccccc" fo:margin-bottom="0.4cm"', '')
			. $p('Cover_20_Top', 'Standard', 'fo:margin-top="6cm"', '', '', ' style:master-page-name="Cover"')
			. $p('Cover_20_Title', 'Standard', 'fo:text-align="center" fo:margin-bottom="0.4cm"', 'style:font-name="' . $t['heading'] . '" fo:font-size="30pt" fo:font-weight="bold" fo:color="' . $t['headings'] . '"')
			. $p('Cover_20_Subtitle', 'Standard', 'fo:text-align="center" fo:margin-bottom="0.6cm"', 'fo:font-size="16pt" fo:color="' . $t['accent'] . '"')
			. $p('Cover_20_Rule', 'Standard', 'fo:margin-left="6cm" fo:margin-right="6cm" fo:border-bottom="1.5pt solid ' . $t['accent'] . '" fo:margin-bottom="1cm"', '')
			. $p('Cover_20_Meta', 'Standard', 'fo:text-align="center" fo:margin-bottom="0.2cm"', 'fo:font-size="13pt" fo:color="#444444"')
			. $p('After_20_Cover', 'Standard', 'fo:margin-bottom="0cm"', '', '', ' style:master-page-name="Standard"')
			. $p('Table_20_Contents', 'Standard', 'fo:margin-bottom="0cm"', '', 'Table Contents');
		foreach (['Left' => 'start', 'Center' => 'center', 'Right' => 'end'] as $name => $value) {
			$styles .= $p('Table_20_Contents_' . $name, 'Table_20_Contents', 'fo:text-align="' . $value . '"', '')
				. $p('Table_20_Heading_' . $name, 'Table_20_Contents', 'fo:text-align="' . $value . '"', 'fo:font-weight="bold" fo:color="#ffffff"');
		}
		$styles .= $p('Table_20_Heading', 'Table_20_Contents', 'fo:text-align="start"', 'fo:font-weight="bold"', 'Table Heading')
			. $p('Card_20_Number', 'Standard', 'fo:text-align="end"', 'fo:font-size="8pt" fo:color="#888888"', 'Card Number')
			. $p('Card_20_Front', 'Standard', 'fo:text-align="center"', 'fo:font-size="14pt" fo:font-weight="bold"', 'Card Front')
			. $p('Card_20_Back', 'Standard', 'fo:text-align="center"', 'fo:font-size="12pt"', 'Card Back');
		foreach (self::CALLOUTS as $type => [$border, $background]) {
			$styles .= $p('Callout_' . $type, 'Text_20_body', 'fo:margin-left="0.1cm" fo:margin-right="0.1cm" fo:margin-top="0cm" fo:margin-bottom="0cm" fo:padding-left="0.35cm" fo:padding-right="0.3cm" fo:padding-top="0.08cm" fo:padding-bottom="0.08cm" fo:background-color="' . $background . '" fo:border-left="3pt solid ' . $border . '" style:join-border="true"', '')
				. '<style:style style:name="CalloutTitle_' . $type . '" style:family="text"><style:text-properties fo:font-weight="bold" fo:color="' . $border . '"/></style:style>';
		}
		$styles .= '<style:style style:name="Strong" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
			. '<style:style style:name="Emphasis" style:family="text"><style:text-properties fo:font-style="italic"/></style:style>'
			. '<style:style style:name="Deleted" style:family="text"><style:text-properties style:text-line-through-style="solid" style:text-line-through-type="single" fo:color="#c00000"/></style:style>'
			. '<style:style style:name="Inserted" style:family="text"><style:text-properties style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color" fo:color="#00804a"/></style:style>'
			. '<style:style style:name="Highlight" style:family="text"><style:text-properties fo:background-color="#fff59d"/></style:style>'
			. '<style:style style:name="Code" style:family="text"><style:text-properties style:font-name="Liberation Mono" fo:font-size="' . ($t['size'] - 1.5) . 'pt" fo:background-color="#f2f2f2"/></style:style>'
			. '<style:style style:name="CodeKeyword" style:family="text"><style:text-properties fo:color="#a626a4" fo:font-weight="bold"/></style:style>'
			. '<style:style style:name="CodeType" style:family="text"><style:text-properties fo:color="#0184bc"/></style:style>'
			. '<style:style style:name="CodeString" style:family="text"><style:text-properties fo:color="#50a14f"/></style:style>'
			. '<style:style style:name="CodeComment" style:family="text"><style:text-properties fo:color="#8a8f98" fo:font-style="italic"/></style:style>'
			. '<style:style style:name="CodeNumber" style:family="text"><style:text-properties fo:color="#c18401"/></style:style>'
			. self::BULLETS . self::NUMBERS
			. '<text:notes-configuration text:note-class="footnote" style:num-format="1" text:start-value="0" text:footnotes-position="page" text:start-numbering-at="document"/>';
		[$w, $h] = $landscape ? ['29.7cm', '21cm'] : ['21cm', '29.7cm'];
		$fonts = array_unique([$t['body'], $t['heading'], 'Liberation Sans', 'Liberation Mono']);
		$footerText = '<text:p text:style-name="Footer">' . ($title !== '' ? self::xml($title) . ' · ' : '') . '<text:page-number text:select-page="current">1</text:page-number> / <text:page-count>1</text:page-count></text:p>';
		return '<office:document-styles ' . Odf::NAMESPACES . '><office:font-face-decls>'
			. implode('', array_map(fn (string $f) => '<style:font-face style:name="' . $f . '" svg:font-family="&apos;' . $f . '&apos;"/>', $fonts))
			. '</office:font-face-decls><office:styles>' . $styles . '</office:styles>'
			. '<office:automatic-styles>'
			. '<style:page-layout style:name="Page"><style:page-layout-properties fo:page-width="' . $w . '" fo:page-height="' . $h . '" style:print-orientation="' . ($landscape ? 'landscape' : 'portrait') . '" fo:margin-top="2cm" fo:margin-bottom="1.5cm" fo:margin-left="2cm" fo:margin-right="2cm"/>'
			. '<style:footer-style><style:header-footer-properties fo:min-height="0.6cm" fo:margin-top="0.4cm"/></style:footer-style></style:page-layout>'
			. '<style:page-layout style:name="CoverPage"><style:page-layout-properties fo:page-width="' . $w . '" fo:page-height="' . $h . '" fo:margin-top="2cm" fo:margin-bottom="2cm" fo:margin-left="2cm" fo:margin-right="2cm"/></style:page-layout>'
			. '</office:automatic-styles><office:master-styles>'
			. '<style:master-page style:name="Standard" style:page-layout-name="Page">' . ($footer ? '<style:footer>' . $footerText . '</style:footer>' : '') . '</style:master-page>'
			. '<style:master-page style:name="Cover" style:page-layout-name="CoverPage" style:next-style-name="Standard"/>'
			. '</office:master-styles></office:document-styles>';
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
}
