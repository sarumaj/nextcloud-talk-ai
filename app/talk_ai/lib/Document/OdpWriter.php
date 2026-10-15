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
 * after "Notes:" are the speaker notes. Formulas ($…$) are shown in Unicode. A
 * ```chart block or an image ![caption](source) on a slide goes beside its
 * text, or fills a slide without text. A later "#" heading alone is a section
 * slide; "[AGENDA]" (or the agenda option) gives an agenda slide of the sections,
 * or of the slides' titles; every slide but the title slide is numbered.
 */
class OdpWriter {
	/** @return list<array{title: string, body: list<string>, notes: list<string>}> */
	public static function slides(string $markdown): array {
		$slides = [];
		$current = null;
		$notes = false;
		$start = function (string $title) use (&$slides, &$current, &$notes): void {
			if ($current !== null && ($current['title'] !== '' || $current['body'] !== [] || $current['notes'] !== [] || $current['pictures'] !== [] || $current['agenda'])) {
				$slides[] = $current;
			}
			$current = ['title' => $title, 'level' => 2, 'body' => [], 'notes' => [], 'pictures' => [], 'agenda' => false];
			$notes = false;
		};
		$start('');
		$fence = false;
		$chart = null;
		foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $line) {
			if ($chart !== null) {
				// Inside a chart block: its JSON up to the closing fence.
				if (preg_match('/^(```|~~~)\s*$/', $line) === 1) {
					$current['pictures'][] = ['chart' => implode("\n", $chart)];
					$chart = null;
				} else {
					$chart[] = $line;
				}
				continue;
			}
			if (!$fence && preg_match('/^(```|~~~)(chart|plot)\s*$/', $line) === 1) {
				$chart = [];
				continue;
			}
			if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
				$fence = !$fence;
				continue;
			}
			if (!$fence && preg_match(Figures::IMAGE, $line, $image) === 1) {
				$current['pictures'][] = ['source' => $image[2], 'caption' => $image[1]];
				continue;
			}
			if (!$fence && preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $line) === 1) {
				$start('');
			} elseif (!$fence && preg_match('/^(#{1,2})\s+(.*)$/', $line, $m) === 1) {
				if ($current['title'] !== '' || $current['body'] !== [] || $current['pictures'] !== [] || $current['agenda']) {
					$start('');
				}
				$current['title'] = trim($m[2], ' #');
				$current['level'] = strlen($m[1]);
			} elseif (!$fence && preg_match('/^\s*\[\[?(AGENDA|TOC)\]\]?\s*$/i', $line) === 1) {
				$current['agenda'] = true;
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

	/** @param (callable(string): ?array)|null $images an image's data, mime, width and height by its source */
	public function write(string $markdown, string $title = '', ?callable $images = null, string $theme = 'modern', bool $agenda = false, string $agendaTitle = 'Agenda'): string {
		$pages = '';
		$pictures = [];
		$slides = self::slides($markdown);
		// Section slides: a "#" heading after the first slide, with nothing else on it.
		foreach ($slides as $n => &$slide) {
			$slide['section'] = $n > 0 && $slide['level'] === 1 && $slide['body'] === [] && $slide['pictures'] === [] && !$slide['agenda'];
		}
		unset($slide);
		// The agenda: the sections, else every titled slide but the first and a closing one.
		$sections = array_values(array_map(fn ($s) => $s['title'], array_filter($slides, fn ($s) => $s['section'])));
		$items = $sections !== [] ? $sections : array_values(array_filter(array_map(fn ($s) => $s['title'], array_slice($slides, 1, max(0, count($slides) - 2))), fn ($t) => $t !== ''));
		$marked = array_filter($slides, fn ($s) => $s['agenda']) !== [];
		if ($agenda && !$marked && count($items) >= 2) {
			array_splice($slides, 1, 0, [['title' => $agendaTitle, 'level' => 2, 'body' => [], 'notes' => [], 'pictures' => [], 'agenda' => true, 'section' => false]]);
		}
		$sectionNumber = 0;
		foreach ($slides as $n => $slide) {
			if ($slide['agenda']) {
				$slide['title'] = $slide['title'] !== '' ? $slide['title'] : $agendaTitle;
				$slide['body'] = array_map(fn ($i, $t) => ($i + 1) . '. ' . $t, array_keys($items), $items);
			}
			if ($slide['section']) {
				// A divider: the accent colour across the slide, the section's number and title.
				$sectionNumber++;
				$pages .= '<draw:page draw:name="' . Odf::xml('Slide ' . ($n + 1)) . '" draw:style-name="dpSection" draw:master-page-name="Default">'
					. '<draw:rect draw:style-name="grSection" draw:layer="layout" svg:x="0cm" svg:y="0cm" svg:width="28cm" svg:height="15.75cm"/>'
					. self::frame('title', 'prTitleSlide', '2cm', '4.6cm', '24cm', '1.6cm', '<text:p text:style-name="PSectionNumber">' . sprintf('%02d', $sectionNumber) . '</text:p>')
					. self::frame('subtitle', 'prSubtitle', '2cm', '6.4cm', '24cm', '3.4cm', '<text:p text:style-name="PSection">' . self::inline($slide['title']) . '</text:p>')
					. self::notesPage($n, $slide['notes']) . '</draw:page>';
				continue;
			}
			$figure = null;
			foreach ($slide['pictures'] as $p) {
				try {
					$figure = isset($p['chart']) ? Figures::chart($p['chart']) : ($images !== null ? $images($p['source']) : null);
				} catch (\Throwable) {
					$figure = null;
				}
				if ($figure !== null) {
					// One picture a slide: the first that can be had.
					$path = Odf::picturePath(count($pictures) + 1, $figure['mime']);
					$pictures[$path] = [$figure['data'], $figure['mime']];
					$figure['path'] = $path;
					break;
				}
			}
			// The first slide, and one without a heading (a closing "Thank you"), with a line or two: centred.
			$isTitle = $figure === null && ($n === 0 || $slide['title'] === '') && count($slide['body']) <= 2 && !preg_grep('/^\s*([-*+]|\d+[.)]|\|)\s?/', $slide['body']);
			if ($isTitle && $slide['title'] === '' && $slide['body'] !== []) {
				$slide['title'] = array_shift($slide['body']);
			}
			$pages .= '<draw:page draw:name="' . Odf::xml('Slide ' . ($n + 1)) . '" draw:style-name="' . ($n === 0 ? 'dpTitle' : 'dp1') . '" draw:master-page-name="Default">';
			if ($isTitle) {
				$pages .= self::frame('title', 'prTitleSlide', '2cm', '4.2cm', '24cm', '3.6cm', '<text:p text:style-name="PTitleSlide">' . self::inline($slide['title']) . '</text:p>');
				if ($slide['body'] !== []) {
					$pages .= self::frame('subtitle', 'prSubtitle', '2cm', '8.2cm', '24cm', '3cm', implode('', array_map(fn (string $l) => '<text:p text:style-name="PSubtitle">' . self::inline($l) . '</text:p>', $slide['body'])));
				}
			} else {
				if ($slide['title'] !== '') {
					$pages .= self::frame('title', 'prTitle', '1.4cm', '0.7cm', '25.2cm', '2.4cm', '<text:p text:style-name="PTitle">' . self::inline($slide['title']) . '</text:p>');
				}
				$top = $slide['title'] !== '' ? 3.4 : 1.2;
				$height = $slide['title'] !== '' ? 11.2 : 13.4;
				if ($figure === null) {
					$pages .= self::frame('outline', 'prBody', '1.4cm', $top . 'cm', '25.2cm', $height . 'cm', self::body($slide['body']));
				} elseif ($slide['body'] === []) {
					$pages .= self::picture($figure, 1.4, $top, 25.2, $height);
				} else {
					// Text on the left, the picture on the right.
					$pages .= self::frame('outline', 'prBody', '1.4cm', $top . 'cm', '12.6cm', $height . 'cm', self::body($slide['body']));
					$pages .= self::picture($figure, 14.4, $top, 12.2, $height);
				}
			}
			$pages .= self::notesPage($n, $slide['notes']) . '</draw:page>';
		}
		// The theme's colours and fonts in place of the default (modern) ones.
		$t = OdtWriter::THEMES[$theme] ?? OdtWriter::THEMES['modern'];
		$colors = ['#1f3864' => $t['headings'], '#2e75b6' => $t['accent'], 'Liberation Sans' => $t['heading']];
		$content = '<office:document-content ' . Odf::NAMESPACES . '><office:automatic-styles>' . strtr(self::AUTOMATIC, $colors)
			. '</office:automatic-styles><office:body><office:presentation>' . $pages . '</office:presentation></office:body></office:document-content>';
		return Odf::package(Odf::PRESENTATION, $content, strtr(self::STYLES, $colors), $title, [], $pictures);
	}

	/** A picture fitted into the box (x, y, width, height in cm), centred in it. */
	private static function picture(array $figure, float $x, float $y, float $width, float $height): string {
		[$w, $h] = [max(1, $figure['width']), max(1, $figure['height'])];
		[$fw, $fh] = $w / $h > $width / $height ? [$width, $width * $h / $w] : [$height * $w / $h, $height];
		return '<draw:frame draw:layer="layout" svg:x="' . round($x + ($width - $fw) / 2, 2) . 'cm" svg:y="' . round($y + ($height - $fh) / 2, 2) . 'cm" svg:width="' . round($fw, 2) . 'cm" svg:height="' . round($fh, 2) . 'cm">'
			. '<draw:image xlink:href="' . $figure['path'] . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad" draw:mime-type="' . $figure['mime'] . '"/></draw:frame>';
	}

	/** The speaker notes under the slide's thumbnail. */
	private static function notesPage(int $n, array $notes): string {
		$text = $notes === [] ? '<text:p/>' : implode('', array_map(fn (string $l) => '<text:p>' . self::inline($l) . '</text:p>', $notes));
		return '<presentation:notes draw:style-name="dp1"><draw:page-thumbnail presentation:class="page" svg:x="2cm" svg:y="1.5cm" svg:width="17cm" svg:height="9.6cm" draw:page-number="' . ($n + 1) . '"/>'
			. self::frame('notes', 'prNotes', '2cm', '12cm', '17cm', '14cm', $text) . '</presentation:notes>';
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
				$items[] = [strlen(str_replace("\t", '    ', $m[1])), $m[3], ctype_digit($m[2][0])];
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
		$out = '<text:list text:style-name="' . (!empty($items[0][2]) ? 'L2' : 'L1') . '">';
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

	private const AUTOMATIC = '<style:style style:name="dp1" style:family="drawing-page"><style:drawing-page-properties presentation:display-header="false" presentation:display-footer="false" presentation:display-page-number="true" presentation:display-date-time="false"/></style:style>'
		. '<style:style style:name="dpTitle" style:family="drawing-page"><style:drawing-page-properties presentation:display-header="false" presentation:display-footer="false" presentation:display-page-number="false" presentation:display-date-time="false"/></style:style>'
		. '<style:style style:name="dpSection" style:family="drawing-page"><style:drawing-page-properties presentation:display-header="false" presentation:display-footer="false" presentation:display-page-number="false" presentation:display-date-time="false"/></style:style>'
		. '<style:style style:name="grSection" style:family="graphic"><style:graphic-properties draw:stroke="none" draw:fill="solid" draw:fill-color="#2e75b6"/></style:style>'
		. '<style:style style:name="PSectionNumber" style:family="paragraph"><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="28pt" fo:font-weight="bold" fo:color="#ffffff" fo:opacity="70%"/></style:style>'
		. '<style:style style:name="PSection" style:family="paragraph"><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="40pt" fo:font-weight="bold" fo:color="#ffffff"/></style:style>'
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
		. '</text:list-style>'
		. '<text:list-style style:name="L2">'
		. '<text:list-level-style-number text:level="1" style:num-suffix="." style:num-format="1"><style:list-level-properties text:space-before="0cm" text:min-label-width="0.9cm"/><style:text-properties fo:color="#2e75b6" fo:font-weight="bold"/></text:list-level-style-number>'
		. '<text:list-level-style-number text:level="2" style:num-suffix="." style:num-format="a"><style:list-level-properties text:space-before="1.2cm" text:min-label-width="0.8cm"/><style:text-properties fo:color="#2e75b6"/></text:list-level-style-number>'
		. '<text:list-level-style-number text:level="3" style:num-suffix="." style:num-format="i"><style:list-level-properties text:space-before="2.3cm" text:min-label-width="0.7cm"/><style:text-properties fo:color="#2e75b6"/></text:list-level-style-number>'
		. '</text:list-style>';

	private const STYLES = '<office:document-styles ' . Odf::NAMESPACES . '><office:font-face-decls><style:font-face style:name="Liberation Sans" svg:font-family="&apos;Liberation Sans&apos;"/><style:font-face style:name="Liberation Mono" svg:font-family="&apos;Liberation Mono&apos;"/></office:font-face-decls>'
		. '<office:styles><style:default-style style:family="graphic"><style:text-properties style:font-name="Liberation Sans" fo:font-size="18pt"/></style:default-style></office:styles>'
		. '<office:automatic-styles><style:page-layout style:name="PM1"><style:page-layout-properties fo:margin-top="0cm" fo:margin-bottom="0cm" fo:margin-left="0cm" fo:margin-right="0cm" fo:page-width="28cm" fo:page-height="15.75cm" style:print-orientation="landscape"/></style:page-layout>'
		. '<style:style style:name="Mdp1" style:family="drawing-page"><style:drawing-page-properties draw:background-size="full" draw:fill="solid" draw:fill-color="#ffffff"/></style:style>'
		. '<style:style style:name="MprNum" style:family="presentation"><style:graphic-properties draw:stroke="none" draw:fill="none" draw:textarea-vertical-align="bottom"/></style:style>'
		. '<style:style style:name="MPNum" style:family="paragraph"><style:paragraph-properties fo:text-align="end"/><style:text-properties fo:font-size="12pt" fo:color="#888888"/></style:style>'
		. '<style:style style:name="Mgr1" style:family="graphic"><style:graphic-properties draw:stroke="none" draw:fill="solid" draw:fill-color="#2e75b6"/></style:style></office:automatic-styles>'
		. '<office:master-styles><style:master-page style:name="Default" style:page-layout-name="PM1" draw:style-name="Mdp1">'
		. '<draw:rect draw:style-name="Mgr1" draw:layer="backgroundobjects" svg:x="0cm" svg:y="15.35cm" svg:width="28cm" svg:height="0.4cm"/>'
		. '<draw:frame presentation:style-name="MprNum" draw:layer="backgroundobjects" presentation:class="page-number" svg:x="24.5cm" svg:y="14.3cm" svg:width="2.5cm" svg:height="0.9cm"><draw:text-box><text:p text:style-name="MPNum"><text:page-number>&lt;number&gt;</text:page-number></text:p></draw:text-box></draw:frame>'
		. '</style:master-page></office:master-styles></office:document-styles>';
}
