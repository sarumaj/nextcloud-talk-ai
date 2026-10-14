<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Markdown as an OpenDocument text (.odt), which Collabora turns into PDF:
 * headings, paragraphs, bullet and numbered lists (one level), **bold**,
 * *italic*, `code`, [links](url), code blocks, tables, block quotes and rules.
 */
class OdtWriter {
	public function write(string $markdown, string $title = ''): string {
		$body = self::blocks(str_replace("\r\n", "\n", $markdown));
		$zipPath = tempnam(sys_get_temp_dir(), 'talk-ai-odt');
		$zip = new \ZipArchive();
		try {
			$zip->open($zipPath, \ZipArchive::OVERWRITE);
			// The MIME type comes first and uncompressed, as ODF requires.
			$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
			$zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
			$zip->addFromString('META-INF/manifest.xml', self::MANIFEST);
			$zip->addFromString('styles.xml', self::STYLES);
			$zip->addFromString('meta.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" office:version="1.3"><office:meta><dc:title>' . self::xml($title) . '</dc:title></office:meta></office:document-meta>');
			$zip->addFromString('content.xml', self::CONTENT_HEAD . $body . '</office:text></office:body></office:document-content>');
			$zip->close();
			return (string)file_get_contents($zipPath);
		} finally {
			@unlink($zipPath);
		}
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
			if (preg_match('/^```/', $line) === 1) {
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
			} elseif (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $line) === 1) {
				$flush();
				$numbered = preg_match('/^\s*\d/', $line) === 1;
				$out .= '<text:list text:style-name="' . ($numbered ? 'Numbering_20_123' : 'List_20_Bullet') . '">';
				for (; $i < count($lines) && preg_match('/^\s*([-*+]|\d+[.)])\s+(.*)$/', $lines[$i], $item) === 1; $i++) {
					$out .= '<text:list-item><text:p text:style-name="List_20_Paragraph">' . self::inline($item[2]) . '</text:p></text:list-item>';
				}
				$i--;
				$out .= '</text:list>';
			} elseif (str_starts_with(ltrim($line), '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{2,}/', $lines[$i + 1]) === 1) {
				$flush();
				$rows = [self::cells($line)];
				for ($i += 2; $i < count($lines) && str_starts_with(ltrim($lines[$i]), '|'); $i++) {
					$rows[] = self::cells($lines[$i]);
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

	/** A table row's cells. */
	private static function cells(string $line): array {
		return array_map('trim', explode('|', trim(trim($line), '|')));
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
			$x = self::xml($part);
			$x = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<text:a xlink:type="simple" xlink:href="$2">$1</text:a>', $x) ?? $x;
			$x = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<text:span text:style-name="Strong">$1$2</text:span>', $x) ?? $x;
			$x = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])|(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/', '<text:span text:style-name="Emphasis">$1$2</text:span>', $x) ?? $x;
			$out .= $x;
		}
		return $out;
	}

	private static function xml(string $text): string {
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	/** Runs of spaces kept (code), as ODF collapses them otherwise. */
	private static function spaces(string $text): string {
		return preg_replace_callback('/ {2,}/', fn (array $m) => ' <text:s text:c="' . (strlen($m[0]) - 1) . '"/>', $text) ?? $text;
	}

	private const MANIFEST = '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/></manifest:manifest>';

	private const CONTENT_HEAD = '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:xlink="http://www.w3.org/1999/xlink" office:version="1.3"><office:automatic-styles><style:style style:name="Table" style:family="table"><style:table-properties style:width="17cm" table:align="margins"/></style:style><style:style style:name="Cell" style:family="table-cell"><style:table-cell-properties fo:padding="0.1cm" fo:border="0.5pt solid #999999"/></style:style></office:automatic-styles><office:body><office:text>';

	private const STYLES = '<?xml version="1.0" encoding="UTF-8"?><office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" office:version="1.3"><office:font-face-decls><style:font-face style:name="Liberation Sans" svg:font-family="&apos;Liberation Sans&apos;" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"/><style:font-face style:name="Liberation Mono" svg:font-family="&apos;Liberation Mono&apos;" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"/></office:font-face-decls><office:styles>'
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
		. '<style:style style:name="Strong" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
		. '<style:style style:name="Emphasis" style:family="text"><style:text-properties fo:font-style="italic"/></style:style>'
		. '<style:style style:name="Code" style:family="text"><style:text-properties style:font-name="Liberation Mono" fo:font-size="9.5pt" fo:background-color="#f2f2f2"/></style:style>'
		. '<text:list-style style:name="List_20_Bullet" style:display-name="List Bullet"><text:list-level-style-bullet text:level="1" text:bullet-char="•"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.5cm" fo:margin-left="1cm"/></style:list-level-properties></text:list-level-style-bullet></text:list-style>'
		. '<text:list-style style:name="Numbering_20_123" style:display-name="Numbering 123"><text:list-level-style-number text:level="1" style:num-suffix="." style:num-format="1"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.6cm" fo:margin-left="1cm"/></style:list-level-properties></text:list-level-style-number></text:list-style>'
		. '</office:styles><office:automatic-styles><style:page-layout style:name="Page"><style:page-layout-properties fo:page-width="21cm" fo:page-height="29.7cm" fo:margin-top="2cm" fo:margin-bottom="2cm" fo:margin-left="2cm" fo:margin-right="2cm"/></style:page-layout></office:automatic-styles><office:master-styles><style:master-page style:name="Standard" style:page-layout-name="Page"/></office:master-styles></office:document-styles>';
}
