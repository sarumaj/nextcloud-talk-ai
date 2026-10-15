<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * A presentation (.odp, 16:9), which Collabora turns into PowerPoint or PDF,
 * from Markdown: a line "---" or a heading (# or ##) starts a slide, the
 * heading is its title, and lists and paragraphs fill it (text shrinks to fit).
 * A first slide with only a title and a line or two is the title slide, and so
 * is a slide of a line or two without a heading (a closing one). Lines
 * after "Notes:" are the speaker notes. Formulas ($…$) are shown in Unicode.
 */
class OdpWriter {
	/** @return list<array{title: string, body: list<string>, notes: list<string>}> */
	public static function slides(string $markdown): array {
		$slides = [];
		$current = null;
		$notes = false;
		$start = function (string $title) use (&$slides, &$current, &$notes): void {
			if ($current !== null && ($current['title'] !== '' || $current['body'] !== [] || $current['notes'] !== [])) {
				$slides[] = $current;
			}
			$current = ['title' => $title, 'body' => [], 'notes' => []];
			$notes = false;
		};
		$start('');
		$fence = false;
		foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $line) {
			if (str_starts_with(ltrim($line), '```')) {
				$fence = !$fence;
				continue;
			}
			if (!$fence && preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $line) === 1) {
				$start('');
			} elseif (!$fence && preg_match('/^#{1,2}\s+(.*)$/', $line, $m) === 1) {
				if ($current['title'] !== '' || $current['body'] !== []) {
					$start('');
				}
				$current['title'] = trim($m[1], ' #');
			} elseif (!$fence && preg_match('/^\s*\**(notes?|speaker notes|notizen|notatki)\**\s*:\s*(.*)$/i', $line, $m) === 1) {
				$notes = true;
				if (trim($m[2]) !== '') {
					$current['notes'][] = trim($m[2]);
				}
			} elseif (trim($line) !== '') {
				$current[$notes ? 'notes' : 'body'][] = $fence ? '`' . $line . '`' : rtrim($line);
			}
		}
		$start('');
		return $slides;
	}

	public function write(string $markdown, string $title = ''): string {
		$pages = '';
		foreach (self::slides($markdown) as $n => $slide) {
			// The first slide, and one without a heading (a closing "Thank you"), with a line or two: centred.
			$isTitle = ($n === 0 || $slide['title'] === '') && count($slide['body']) <= 2 && !preg_grep('/^\s*([-*+]|\d+[.)]|\|)\s?/', $slide['body']);
			if ($isTitle && $slide['title'] === '' && $slide['body'] !== []) {
				$slide['title'] = array_shift($slide['body']);
			}
			$pages .= '<draw:page draw:name="' . Odf::xml('Slide ' . ($n + 1)) . '" draw:style-name="dp1" draw:master-page-name="Default">';
			if ($isTitle) {
				$pages .= self::frame('title', 'prTitleSlide', '2cm', '4.2cm', '24cm', '3.6cm', '<text:p text:style-name="PTitleSlide">' . self::inline($slide['title']) . '</text:p>');
				if ($slide['body'] !== []) {
					$pages .= self::frame('subtitle', 'prSubtitle', '2cm', '8.2cm', '24cm', '3cm', implode('', array_map(fn (string $l) => '<text:p text:style-name="PSubtitle">' . self::inline($l) . '</text:p>', $slide['body'])));
				}
			} else {
				if ($slide['title'] !== '') {
					$pages .= self::frame('title', 'prTitle', '1.4cm', '0.7cm', '25.2cm', '2.4cm', '<text:p text:style-name="PTitle">' . self::inline($slide['title']) . '</text:p>');
				}
				$pages .= self::frame('outline', 'prBody', '1.4cm', $slide['title'] !== '' ? '3.4cm' : '1.2cm', '25.2cm', $slide['title'] !== '' ? '11.2cm' : '13.4cm', self::body($slide['body']));
			}
			$notes = $slide['notes'] === [] ? '<text:p/>' : implode('', array_map(fn (string $l) => '<text:p>' . self::inline($l) . '</text:p>', $slide['notes']));
			$pages .= '<presentation:notes draw:style-name="dp1"><draw:page-thumbnail presentation:class="page" svg:x="2cm" svg:y="1.5cm" svg:width="17cm" svg:height="9.6cm" draw:page-number="' . ($n + 1) . '"/>'
				. self::frame('notes', 'prNotes', '2cm', '12cm', '17cm', '14cm', $notes) . '</presentation:notes>';
			$pages .= '</draw:page>';
		}
		$content = '<office:document-content ' . Odf::NAMESPACES . '><office:automatic-styles>' . self::AUTOMATIC
			. '</office:automatic-styles><office:body><office:presentation>' . $pages . '</office:presentation></office:body></office:document-content>';
		return Odf::package(Odf::PRESENTATION, $content, self::STYLES, $title);
	}

	private static function frame(string $class, string $style, string $x, string $y, string $width, string $height, string $text): string {
		return '<draw:frame presentation:class="' . $class . '" presentation:style-name="' . $style . '" draw:layer="layout" svg:x="' . $x . '" svg:y="' . $y . '" svg:width="' . $width . '" svg:height="' . $height . '">'
			. '<draw:text-box>' . $text . '</draw:text-box></draw:frame>';
	}

	/** A slide's lines: list items (nested by indent) and paragraphs. */
	private static function body(array $lines): string {
		$out = '';
		$items = [];
		$flush = function () use (&$items, &$out): void {
			if ($items !== []) {
				$out .= self::listOf($items, 1);
				$items = [];
			}
		};
		foreach ($lines as $line) {
			if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $m) === 1) {
				$items[] = [strlen(str_replace("\t", '    ', $m[1])), $m[3]];
			} elseif (preg_match('/^\s*\|?\s*:?-{2,}/', $line) === 1) {
				continue;
			} else {
				$flush();
				$text = str_starts_with(ltrim($line), '|') ? implode('  ·  ', Odf::cells($line)) : (preg_replace('/^#{3,6}\s+/', '', $line) ?? $line);
				$out .= '<text:p text:style-name="' . (preg_match('/^#{3,6}\s/', $line) === 1 ? 'PSub' : 'P1') . '">' . self::inline($text) . '</text:p>';
			}
		}
		$flush();
		return $out === '' ? '<text:p/>' : $out;
	}

	/** @param list<array{int, string}> $items indent, text */
	private static function listOf(array $items, int $level): string {
		$indent = $items[0][0];
		$out = '<text:list text:style-name="L1">';
		for ($n = 0; $n < count($items); $n++) {
			$out .= '<text:list-item><text:p text:style-name="P' . min($level, 3) . '">' . self::inline($items[$n][1]) . '</text:p>';
			$children = [];
			while (isset($items[$n + 1]) && $items[$n + 1][0] > $indent) {
				$children[] = $items[++$n];
			}
			if ($children !== []) {
				$out .= self::listOf($children, $level + 1);
			}
			$out .= '</text:list-item>';
		}
		return $out . '</text:list>';
	}

	/** Inline Markdown, formulas in Unicode (a slide's text box takes no formula objects). */
	private static function inline(string $text): string {
		return OdtWriter::inline(Math::replace($text, fn (string $latex) => Math::unicode($latex)));
	}

	private const AUTOMATIC = '<style:style style:name="dp1" style:family="drawing-page"><style:drawing-page-properties presentation:display-header="false" presentation:display-footer="false" presentation:display-page-number="false" presentation:display-date-time="false"/></style:style>'
		. '<style:style style:name="prTitleSlide" style:family="presentation"><style:graphic-properties draw:stroke="none" draw:fill="none" draw:textarea-vertical-align="bottom" draw:auto-grow-height="false" draw:fit-to-size="false" style:shrink-to-fit="true"/><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="40pt" fo:font-weight="bold" fo:color="#1f3864" style:font-name="Liberation Sans"/></style:style>'
		. '<style:style style:name="prSubtitle" style:family="presentation"><style:graphic-properties draw:stroke="none" draw:fill="none" draw:textarea-vertical-align="top" draw:auto-grow-height="false" draw:fit-to-size="false" style:shrink-to-fit="true"/><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="22pt" fo:color="#595959" style:font-name="Liberation Sans"/></style:style>'
		. '<style:style style:name="prTitle" style:family="presentation"><style:graphic-properties draw:stroke="none" draw:fill="none" draw:textarea-vertical-align="middle" draw:auto-grow-height="false" draw:fit-to-size="false" style:shrink-to-fit="true"/><style:paragraph-properties fo:text-align="start"/><style:text-properties fo:font-size="32pt" fo:font-weight="bold" fo:color="#1f3864" style:font-name="Liberation Sans"/></style:style>'
		. '<style:style style:name="prBody" style:family="presentation"><style:graphic-properties draw:stroke="none" draw:fill="none" draw:textarea-vertical-align="top" draw:auto-grow-height="false" draw:fit-to-size="false" style:shrink-to-fit="true"/><style:text-properties fo:font-size="22pt" fo:color="#262626" style:font-name="Liberation Sans"/></style:style>'
		. '<style:style style:name="prNotes" style:family="presentation"><style:graphic-properties draw:stroke="none" draw:fill="none"/><style:text-properties fo:font-size="12pt" style:font-name="Liberation Sans"/></style:style>'
		. '<style:style style:name="PTitleSlide" style:family="paragraph"><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="40pt" fo:font-weight="bold" fo:color="#1f3864"/></style:style>'
		. '<style:style style:name="PSubtitle" style:family="paragraph"><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="22pt" fo:color="#595959"/></style:style>'
		. '<style:style style:name="PTitle" style:family="paragraph"><style:paragraph-properties fo:text-align="start"/><style:text-properties fo:font-size="32pt" fo:font-weight="bold" fo:color="#1f3864"/></style:style>'
		. '<style:style style:name="P1" style:family="paragraph"><style:paragraph-properties fo:margin-top="0.25cm" fo:margin-bottom="0.1cm"/><style:text-properties fo:font-size="22pt"/></style:style>'
		. '<style:style style:name="P2" style:family="paragraph"><style:paragraph-properties fo:margin-top="0.1cm"/><style:text-properties fo:font-size="19pt"/></style:style>'
		. '<style:style style:name="P3" style:family="paragraph"><style:paragraph-properties fo:margin-top="0.05cm"/><style:text-properties fo:font-size="17pt"/></style:style>'
		. '<style:style style:name="PSub" style:family="paragraph"><style:paragraph-properties fo:margin-top="0.3cm"/><style:text-properties fo:font-size="22pt" fo:font-weight="bold" fo:color="#1f3864"/></style:style>'
		. '<style:style style:name="Strong" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
		. '<style:style style:name="Emphasis" style:family="text"><style:text-properties fo:font-style="italic"/></style:style>'
		. '<style:style style:name="Deleted" style:family="text"><style:text-properties style:text-line-through-style="solid" style:text-line-through-type="single" fo:color="#c00000"/></style:style>'
		. '<style:style style:name="Inserted" style:family="text"><style:text-properties style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color" fo:color="#00804a"/></style:style>'
		. '<style:style style:name="Highlight" style:family="text"><style:text-properties fo:background-color="#fff59d"/></style:style>'
		. '<style:style style:name="Code" style:family="text"><style:text-properties style:font-name="Liberation Mono" fo:background-color="#f2f2f2"/></style:style>'
		. '<text:list-style style:name="L1">'
		. '<text:list-level-style-bullet text:level="1" text:bullet-char="●"><style:list-level-properties text:space-before="0cm" text:min-label-width="0.9cm"/><style:text-properties fo:color="#2e75b6" fo:font-size="75%"/></text:list-level-style-bullet>'
		. '<text:list-level-style-bullet text:level="2" text:bullet-char="–"><style:list-level-properties text:space-before="1.2cm" text:min-label-width="0.8cm"/><style:text-properties fo:color="#2e75b6"/></text:list-level-style-bullet>'
		. '<text:list-level-style-bullet text:level="3" text:bullet-char="●"><style:list-level-properties text:space-before="2.3cm" text:min-label-width="0.7cm"/><style:text-properties fo:color="#2e75b6" fo:font-size="60%"/></text:list-level-style-bullet>'
		. '</text:list-style>';

	private const STYLES = '<office:document-styles ' . Odf::NAMESPACES . '><office:font-face-decls><style:font-face style:name="Liberation Sans" svg:font-family="&apos;Liberation Sans&apos;"/><style:font-face style:name="Liberation Mono" svg:font-family="&apos;Liberation Mono&apos;"/></office:font-face-decls>'
		. '<office:styles><style:default-style style:family="graphic"><style:text-properties style:font-name="Liberation Sans" fo:font-size="18pt"/></style:default-style></office:styles>'
		. '<office:automatic-styles><style:page-layout style:name="PM1"><style:page-layout-properties fo:margin-top="0cm" fo:margin-bottom="0cm" fo:margin-left="0cm" fo:margin-right="0cm" fo:page-width="28cm" fo:page-height="15.75cm" style:print-orientation="landscape"/></style:page-layout>'
		. '<style:style style:name="Mdp1" style:family="drawing-page"><style:drawing-page-properties draw:background-size="full" draw:fill="solid" draw:fill-color="#ffffff"/></style:style>'
		. '<style:style style:name="Mgr1" style:family="graphic"><style:graphic-properties draw:stroke="none" draw:fill="solid" draw:fill-color="#2e75b6"/></style:style></office:automatic-styles>'
		. '<office:master-styles><style:master-page style:name="Default" style:page-layout-name="PM1" draw:style-name="Mdp1">'
		. '<draw:rect draw:style-name="Mgr1" draw:layer="backgroundobjects" svg:x="0cm" svg:y="15.35cm" svg:width="28cm" svg:height="0.4cm"/>'
		. '</style:master-page></office:master-styles></office:document-styles>';
}
