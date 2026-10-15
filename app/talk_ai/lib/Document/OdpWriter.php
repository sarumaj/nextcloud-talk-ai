<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * A presentation (.odp, 16:9), which Collabora turns into PowerPoint or PDF,
 * from Markdown slides, designed by a theme (see Theme: fonts, colours,
 * gradients, decorations).
 *
 * A line "---" or a heading (# or ##) starts a slide; the heading is its title,
 * and "{layout}" at its end chooses the layout: bullets, two-columns (split at a
 * line "|||" or by "### " headings), comparison, image-left, image-right,
 * image-full, chart, quote, stats ("97 % | of the water"), cards ("**Title**:
 * text"), steps, timeline ("1789: text"), agenda, table, sources. Without one the
 * layout follows the content. The first slide is the title slide; a later "#"
 * heading alone is a section slide; "[AGENDA]" (or the agenda option) gives an
 * agenda; a short last slide without heading is the closing slide. Lines after
 * "Notes:" are speaker notes. Formulas ($…$) are shown in Unicode; ~~~chart
 * blocks and images ![caption](source) are pictures.
 */
class OdpWriter {
	private const W = 28.0;
	private const H = 15.75;
	public const LAYOUTS = ['bullets', 'two-columns', 'comparison', 'image-left', 'image-right', 'image-full', 'chart', 'quote', 'stats',
		'cards', 'steps', 'timeline', 'agenda', 'table', 'sources'];

	private array $t = [];
	/** @var array<string, string> automatic styles by name */
	private array $styles = [];
	/** @var array<string, array{string, string}> pictures: path => [data, MIME type] */
	private array $pictures = [];

	/** @return list<array{title: string, level: int, layout: string, body: list<string>, notes: list<string>, pictures: list<array>, agenda: bool}> */
	public static function slides(string $markdown): array {
		$slides = [];
		$current = null;
		$notes = false;
		$start = function (string $title) use (&$slides, &$current, &$notes): void {
			if ($current !== null && ($current['title'] !== '' || $current['body'] !== [] || $current['notes'] !== [] || $current['pictures'] !== [] || $current['agenda'])) {
				$slides[] = $current;
			}
			$current = ['title' => $title, 'level' => 2, 'layout' => '', 'body' => [], 'notes' => [], 'pictures' => [], 'agenda' => false];
			$notes = false;
		};
		$start('');
		$fence = false;
		$chart = null;
		foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $line) {
			if ($chart !== null) {
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
			} elseif (!$fence && preg_match('/^(#{1,2})\s+(.*?)\s*(?:\{\.?([\w-]+)\})?\s*$/', $line, $m) === 1) {
				if ($current['title'] !== '' || $current['body'] !== [] || $current['pictures'] !== [] || $current['agenda']) {
					$start('');
				}
				$current['title'] = trim($m[2], ' #');
				$current['level'] = strlen($m[1]);
				$current['layout'] = strtolower($m[3] ?? '');
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
	public function write(string $markdown, string $title = '', ?callable $images = null, string|array $theme = 'modern', bool $agenda = false, string $agendaTitle = 'Agenda'): string {
		$this->t = Theme::resolve($theme);
		$this->styles = [];
		$this->pictures = [];
		$slides = self::slides($markdown);
		foreach ($slides as $n => &$slide) {
			$slide['section'] = $n > 0 && $slide['level'] === 1 && $slide['body'] === [] && $slide['pictures'] === [] && !$slide['agenda'];
		}
		unset($slide);
		$sections = array_values(array_map(fn ($s) => $s['title'], array_filter($slides, fn ($s) => $s['section'])));
		$items = $sections !== [] ? $sections : array_values(array_filter(array_map(fn ($s) => $s['title'], array_slice($slides, 1, max(0, count($slides) - 2))), fn ($t) => $t !== ''));
		if ($agenda && array_filter($slides, fn ($s) => $s['agenda']) === [] && count($items) >= 2) {
			array_splice($slides, 1, 0, [['title' => $agendaTitle, 'level' => 2, 'layout' => 'agenda', 'body' => [], 'notes' => [], 'pictures' => [], 'agenda' => true, 'section' => false]]);
		}
		$pages = '';
		$sectionNumber = 0;
		$last = count($slides) - 1;
		foreach ($slides as $n => $slide) {
			$figure = null;
			$caption = '';
			foreach ($slide['pictures'] as $p) {
				try {
					$figure = isset($p['chart']) ? Figures::chart($p['chart']) : ($images !== null ? $images($p['source']) : null);
				} catch (\Throwable) {
					$figure = null;
				}
				if ($figure !== null) {
					$path = Odf::picturePath(count($this->pictures) + 1, $figure['mime']);
					$this->pictures[$path] = [$figure['data'], $figure['mime']];
					$figure['path'] = $path;
					$figure['chart'] = isset($p['chart']);
					$caption = (string)($p['caption'] ?? '');
					break;
				}
			}
			if ($slide['agenda']) {
				$slide['title'] = $slide['title'] !== '' ? $slide['title'] : $agendaTitle;
				$slide['body'] = $items;
			}
			$layout = $this->layoutOf($slide, $n, $last, $figure);
			$shapes = match ($layout) {
				'title' => $this->titleSlide($slide),
				'section' => $this->sectionSlide($slide, ++$sectionNumber),
				'closing' => $this->closingSlide($slide),
				'agenda' => $this->agendaSlide($slide),
				'image-full' => $this->imageFull($slide, $figure, $caption),
				default => $this->bodySlide($slide, $layout, $figure, $caption),
			};
			$plain = in_array($layout, ['title', 'section', 'closing', 'image-full'], true);
			$pages .= '<draw:page draw:name="' . Odf::xml('Slide ' . ($n + 1)) . '" draw:style-name="' . ($plain && $layout !== 'image-full' ? 'dpGradient' : 'dpBody')
				. '" draw:master-page-name="' . ($plain ? 'Plain' : 'Body') . '">' . $shapes . $this->notesPage($n, $slide['notes']) . '</draw:page>';
		}
		$content = '<office:document-content ' . Odf::NAMESPACES . '><office:automatic-styles>' . $this->fixedStyles() . implode('', $this->styles)
			. '</office:automatic-styles><office:body><office:presentation>' . $pages . '</office:presentation></office:body></office:document-content>';
		return Odf::package(Odf::PRESENTATION, $content, $this->masterStyles(), $title, [], $this->pictures);
	}

	/** The slide's layout: given, or by its kind and content. */
	private function layoutOf(array $slide, int $n, int $last, ?array $figure): string {
		$plainLines = $slide['body'] === [] || !preg_grep('/^\s*([-*+]|\d+[.)]|\||#)/', $slide['body']);
		if ($n === 0 && $figure === null && count($slide['body']) <= 3 && $plainLines) {
			return 'title';
		}
		if ($slide['section'] ?? false) {
			return 'section';
		}
		if ($slide['agenda']) {
			return 'agenda';
		}
		if (in_array($slide['layout'], self::LAYOUTS, true)) {
			return $slide['layout'] === 'image-full' && $figure === null ? 'bullets' : $slide['layout'];
		}
		if ($n === $last && $n > 0 && $figure === null && count($slide['body']) <= 2 && ($slide['title'] === '' || $slide['body'] === []) && $plainLines) {
			return 'closing';
		}
		if ($figure !== null) {
			return $slide['body'] === [] ? ($figure['chart'] ? 'chart' : 'image-full') : 'image-right';
		}
		if (preg_grep('/^\s*\|.*\|/', $slide['body'])) {
			return 'table';
		}
		if (in_array('|||', array_map('trim', $slide['body']), true)) {
			return 'two-columns';
		}
		return 'bullets';
	}

	// --- Slide kinds ---------------------------------------------------------

	private function titleSlide(array $slide): string {
		$t = $this->t;
		$title = $slide['title'] !== '' ? $slide['title'] : (array_shift($slide['body']) ?? '');
		return $this->decorateTitle()
			. $this->text(2.2, 4.4, 22, 3.6, $this->p($title, 44, '#ffffff', true, 'start', $t['heading']), 'title', 'bottom')
			. $this->rect(2.2, 8.3, 3.2, 0.18, $t['accent2'])
			. ($slide['body'] !== [] ? $this->text(2.2, 8.8, 22, 3, implode('', array_map(fn ($l) => $this->p($l, 20, '#ffffff', false, 'start', $t['body'], 85), $slide['body'])), 'subtitle') : '');
	}

	private function sectionSlide(array $slide, int $number): string {
		$t = $this->t;
		return $this->decorateTitle()
			. $this->text(2.2, 3.2, 10, 4.2, $this->p(sprintf('%02d', $number), 96, '#ffffff', true, 'start', $t['heading'], 35), null, 'bottom', false)
			. $this->rect(2.2, 7.7, 2.6, 0.16, $t['accent2'])
			. $this->text(2.2, 8.1, 23, 3.2, $this->p($slide['title'], 38, '#ffffff', true, 'start', $t['heading']), 'title');
	}

	private function closingSlide(array $slide): string {
		$t = $this->t;
		$lines = array_values(array_filter([$slide['title'], ...$slide['body']], fn ($l) => trim($l) !== ''));
		$main = array_shift($lines) ?? '';
		return $this->decorateTitle()
			. $this->text(2, 5, 24, 3.4, $this->p($main, 44, '#ffffff', true, 'center', $t['heading']), 'title', 'bottom')
			. ($lines !== [] ? $this->text(2, 8.6, 24, 2.6, implode('', array_map(fn ($l) => $this->p($l, 20, '#ffffff', false, 'center', $t['body'], 85), $lines)), 'subtitle') : '');
	}

	private function agendaSlide(array $slide): string {
		$t = $this->t;
		$items = array_values(array_map(fn ($l) => preg_replace('/^\s*([-*+]|\d+[.)])\s+/', '', $l) ?? $l, array_filter($slide['body'], fn ($l) => trim($l) !== '')));
		$out = $this->titleText($slide['title']);
		$columns = count($items) > 5 ? 2 : 1;
		$perColumn = max(1, (int)ceil(count($items) / $columns));
		$rowHeight = min(2.0, 10.6 / $perColumn);
		foreach ($items as $i => $item) {
			$x = 1.6 + intdiv($i, $perColumn) * 12.8;
			$y = 3.6 + ($i % $perColumn) * $rowHeight;
			$size = min(1.25, $rowHeight * 0.75);
			$top = $y + ($rowHeight - $size) / 2 - 0.15;
			$out .= $this->ellipse($x, $top, $size, $size, $t['accent'])
				. $this->text($x, $top, $size, $size, $this->p((string)($i + 1), 16, $this->onColor($t['accent']), true, 'center', $t['heading']), null, 'middle', false)
				. $this->text($x + $size + 0.5, $y - 0.15, 11, $rowHeight, $this->p($item, 20, $t['text'], false, 'start', $t['body']), null, 'middle');
		}
		return $out;
	}

	private function imageFull(array $slide, array $figure, string $caption): string {
		$t = $this->t;
		$out = $this->picture($figure, 0, 0, self::W, self::H, true);
		if ($slide['title'] !== '' || $slide['body'] !== []) {
			$out .= $this->rect(0, self::H - 4.2, self::W, 4.2, '#000000', 0, 45)
				. $this->text(1.4, self::H - 3.9, 25, 2, $this->p($slide['title'], 32, '#ffffff', true, 'start', $t['heading']), 'title', 'bottom')
				. ($slide['body'] !== [] ? $this->text(1.4, self::H - 1.9, 25, 1.4, $this->p(implode(' ', array_map(fn ($l) => preg_replace('/^\s*[-*+]\s+/', '', $l) ?? $l, $slide['body'])), 16, '#ffffff', false, 'start', $t['body'], 90), null) : '');
		}
		return $out . ($caption !== '' ? $this->text(14, 0.25, 13.6, 0.8, $this->p($caption, 9, '#ffffff', false, 'end', $t['body'], 80), null, 'top', false) : '');
	}

	/** A slide with a title and a body laid out by its layout. */
	private function bodySlide(array $slide, string $layout, ?array $figure, string $caption): string {
		$t = $this->t;
		[$x, $y, $w, $h] = [1.4, 3.3, 25.2, 11.0];
		$out = $slide['title'] !== '' ? $this->titleText($slide['title']) : '';
		if ($slide['title'] === '') {
			[$y, $h] = [1.2, 13.1];
		}
		$body = $slide['body'];
		switch ($layout) {
			case 'image-left':
			case 'image-right':
				$left = $layout === 'image-left';
				if ($figure !== null) {
					$out .= $this->picture($figure, $left ? $x : 14.6, $y, 12.0, $h - ($caption !== '' ? 0.8 : 0), false, true);
					if ($caption !== '') {
						$out .= $this->text($left ? $x : 14.6, $y + $h - 0.7, 12.0, 0.7, $this->p($caption, 9, $this->muted(), false, 'start', $t['body']), null, 'top', false);
					}
				}
				return $out . $this->list($body, $left ? 14.2 : $x, $y, 12.4, $h);
			case 'chart':
				if ($figure !== null) {
					$out .= $this->picture($figure, $x, $y, $w, $h - ($body !== [] ? 2 : 0), false);
				}
				return $out . ($body !== [] ? $this->list($body, $x, $y + $h - 1.8, $w, 1.8, 16) : '');
			case 'two-columns':
			case 'comparison':
				return $out . $this->columns($body, $x, $y, $w, $h, $layout === 'comparison');
			case 'quote':
				return $out . $this->quote($body, $x, $y, $w, $h);
			case 'stats':
				return $out . $this->stats($body, $x, $y, $w, $h);
			case 'cards':
				return $out . $this->cards($body, $x, $y, $w, $h);
			case 'steps':
				return $out . $this->steps($body, $x, $y, $w, $h);
			case 'timeline':
				return $out . $this->timeline($body, $x, $y, $w, $h);
			case 'table':
				return $out . $this->table($body, $x, $y, $w, $h);
			case 'sources':
				return $out . $this->list($body, $x, $y, $w, $h, 12, count($body) > 8 ? 2 : 1);
			default:
				return $out . ($figure !== null ? $this->picture($figure, 14.6, $y, 12.0, $h, false) . $this->list($body, $x, $y, 12.4, $h) : $this->list($body, $x, $y, $w, $h));
		}
	}

	// --- Layout parts ---------------------------------------------------------

	private function titleText(string $title): string {
		return $this->text(1.4, 0.7, 25.2, 2.0, $this->p($title, 30, $this->t['headings'], true, 'start', $this->t['heading']), 'title', 'bottom')
			. $this->rect(1.45, 2.85, 2.2, 0.12, $this->t['accent']);
	}

	/** Bullets and paragraphs (nested by indent) in a text box that shrinks its text to fit. */
	private function list(array $lines, float $x, float $y, float $w, float $h, int $size = 20, int $columns = 1): string {
		if ($lines === []) {
			return '';
		}
		if ($columns > 1) {
			$out = '';
			foreach (array_chunk($lines, (int)ceil(count($lines) / $columns)) as $i => $chunk) {
				$out .= $this->list($chunk, $x + $i * ($w / $columns), $y, $w / $columns - 0.4, $h, $size);
			}
			return $out;
		}
		return $this->text($x, $y, $w, $h, $this->body($lines, $size), 'outline', 'top');
	}

	private function body(array $lines, int $size): string {
		$out = '';
		$items = [];
		$flush = function () use (&$items, &$out, $size): void {
			if ($items !== []) {
				$out .= $this->listOf($items, 1, $size);
				$items = [];
			}
		};
		foreach ($lines as $line) {
			if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $m) === 1) {
				$items[] = [strlen(str_replace("\t", '    ', $m[1])), $m[3], ctype_digit($m[2][0])];
			} elseif (trim($line) === '|||') {
				continue;
			} else {
				$flush();
				$sub = preg_match('/^#{3,6}\s+(.*)$/', $line, $h) === 1;
				$out .= $this->p($sub ? $h[1] : $line, $sub ? $size + 2 : $size, $sub ? $this->t['accent'] : $this->t['text'], $sub, 'start', $this->t['body'], 100, 0.25);
			}
		}
		$flush();
		return $out;
	}

	/** @param list<array{int, string, bool}> $items indent, text, numbered */
	private function listOf(array $items, int $level, int $size): string {
		$indent = $items[0][0];
		$out = '<text:list text:style-name="' . (!empty($items[0][2]) ? 'L2' : 'L1') . '">';
		for ($n = 0; $n < count($items); $n++) {
			$out .= '<text:list-item>' . $this->p($items[$n][1], max(12, $size - 3 * ($level - 1)), $this->t['text'], false, 'start', $this->t['body'], 100, $level === 1 ? 0.22 : 0.08);
			$children = [];
			while (isset($items[$n + 1]) && $items[$n + 1][0] > $indent) {
				$children[] = $items[++$n];
			}
			if ($children !== []) {
				$out .= $this->listOf($children, $level + 1, $size);
			}
			$out .= '</text:list-item>';
		}
		return $out . '</text:list>';
	}

	/** Two or three columns, split at "|||" or by "### " headings; in a comparison each under a coloured header. */
	private function columns(array $lines, float $x, float $y, float $w, float $h, bool $comparison): string {
		$parts = [[]];
		foreach ($lines as $line) {
			if (trim($line) === '|||') {
				$parts[] = [];
				continue;
			}
			if (preg_match('/^#{3,6}\s+/', $line) === 1 && $parts[count($parts) - 1] !== []) {
				$parts[] = [];
			}
			$parts[count($parts) - 1][] = $line;
		}
		$parts = array_slice(array_values(array_filter($parts, fn ($p) => $p !== [])), 0, 3);
		$count = max(1, count($parts));
		$gap = 0.8;
		$cw = ($w - $gap * ($count - 1)) / $count;
		$out = '';
		foreach ($parts as $i => $part) {
			$cx = $x + $i * ($cw + $gap);
			if (!$comparison) {
				$out .= $this->list($part, $cx, $y, $cw, $h, 19);
				continue;
			}
			$head = preg_match('/^#{3,6}\s+(.*)$/', $part[0], $m) === 1 ? $m[1] : '';
			if ($head !== '') {
				array_shift($part);
			}
			$color = $i === 1 ? $this->t['accent2'] : $this->t['accent'];
			$out .= $this->rect($cx, $y, $cw, $h, $this->t['light'], 0.25)
				. $this->rect($cx, $y, $cw, 1.4, $color, 0.25)
				. $this->text($cx + 0.3, $y, $cw - 0.6, 1.4, $this->p($head, 20, $this->onColor($color), true, 'start', $this->t['heading']), null, 'middle')
				. $this->list($part, $cx + 0.4, $y + 1.7, $cw - 0.8, $h - 1.9, 18);
		}
		return $out;
	}

	private function quote(array $lines, float $x, float $y, float $w, float $h): string {
		$author = '';
		$text = [];
		foreach ($lines as $line) {
			$line = preg_replace('/^>\s?/', '', $line) ?? $line;
			if (preg_match('/^\s*(—|–|-|~)\s*(.+)$/u', $line, $m) === 1 && $text !== []) {
				$author = $m[2];
			} else {
				$text[] = trim($line, ' "“”„');
			}
		}
		$t = $this->t;
		return $this->text($x, $y - 0.6, 3, 3.4, $this->p('“', 120, $t['accent'], true, 'start', 'Liberation Serif'), null, 'top', false)
			. $this->text($x + 2.6, $y + 0.6, $w - 3.4, $h - 3.2, $this->p(implode(' ', $text), 28, $t['headings'], false, 'start', $t['heading'], 100, 0, true), null, 'middle')
			. ($author !== '' ? $this->rect($x + 2.7, $y + $h - 2.1, 1.6, 0.1, $t['accent'])
				. $this->text($x + 2.6, $y + $h - 1.9, $w - 3.4, 1.2, $this->p('— ' . $author, 18, $t['accent'], true, 'start', $t['body']), null) : '');
	}

	/** Big numbers: "97 % | of the water is in the oceans" or "**97 %** of the water …". */
	private function stats(array $lines, float $x, float $y, float $w, float $h): string {
		$items = [];
		foreach ($lines as $line) {
			$line = preg_replace('/^\s*([-*+]|\d+[.)])\s+/', '', $line) ?? $line;
			if (preg_match('/^\s*\*\*(.+?)\*\*\s*[:–—-]?\s*(.*)$/u', $line, $m) === 1 || preg_match('/^\s*([^|]+?)\s*\|\s*(.+)$/', $line, $m) === 1) {
				$items[] = [trim($m[1]), trim($m[2])];
			} elseif (trim($line) !== '') {
				$items[] = ['', trim($line)];
			}
		}
		$items = array_slice($items, 0, 4);
		$count = max(1, count($items));
		$gap = 0.7;
		$cw = ($w - $gap * ($count - 1)) / $count;
		$out = '';
		foreach ($items as $i => [$value, $label]) {
			$cx = $x + $i * ($cw + $gap);
			$color = $i % 2 === 0 ? $this->t['accent'] : $this->t['accent2'];
			$out .= $this->rect($cx, $y + 1, $cw, $h - 2, $this->t['light'], 0.3)
				. $this->rect($cx, $y + 1, $cw, 0.2, $color)
				. $this->text($cx + 0.3, $y + 1.8, $cw - 0.6, 3.6, $this->p($value, $count > 3 ? 40 : 52, $color, true, 'center', $this->t['heading']), null, 'bottom')
				. $this->text($cx + 0.4, $y + 5.6, $cw - 0.8, $h - 7, $this->p($label, 17, $this->t['text'], false, 'center', $this->t['body']), null, 'top');
		}
		return $out;
	}

	/** Cards in a grid: "**Title**: text", "Title — text" or plain text. */
	private function cards(array $lines, float $x, float $y, float $w, float $h): string {
		$items = array_slice($this->titledItems($lines), 0, 6);
		$count = max(1, count($items));
		$perRow = $count <= 3 ? $count : ($count === 4 ? 2 : 3);
		$rows = (int)ceil($count / $perRow);
		$gap = 0.6;
		$cw = ($w - $gap * ($perRow - 1)) / $perRow;
		$ch = ($h - $gap * ($rows - 1)) / $rows;
		$out = '';
		foreach ($items as $i => [$title, $text]) {
			$cx = $x + ($i % $perRow) * ($cw + $gap);
			$cy = $y + intdiv($i, $perRow) * ($ch + $gap);
			$color = $i % 2 === 0 ? $this->t['accent'] : $this->t['accent2'];
			$out .= $this->rect($cx, $cy, $cw, $ch, $this->t['light'], 0.3)
				. $this->rect($cx, $cy, 0.22, $ch, $color)
				. $this->text($cx + 0.6, $cy + 0.3, $cw - 0.9, $ch - 0.6,
					($title !== '' ? $this->p($title, 19, $this->t['headings'], true, 'start', $this->t['heading'], 100, 0.15) : '') . $this->p($text, 15, $this->t['text'], false, 'start', $this->t['body']), null, 'top');
		}
		return $out;
	}

	/** Process steps: numbered circles on a line, a title and text under each. */
	private function steps(array $lines, float $x, float $y, float $w, float $h): string {
		$items = array_slice($this->titledItems($lines), 0, 6);
		$count = max(1, count($items));
		$cw = $w / $count;
		$out = $count > 1 ? $this->rect($x + $cw / 2, $y + 1.55, $w - $cw, 0.12, $this->t['accent2']) : '';
		foreach ($items as $i => [$title, $text]) {
			$cx = $x + $i * $cw;
			$out .= $this->ellipse($cx + $cw / 2 - 0.9, $y + 0.7, 1.8, 1.8, $this->t['accent'])
				. $this->text($cx + $cw / 2 - 0.9, $y + 0.7, 1.8, 1.8, $this->p((string)($i + 1), 22, $this->onColor($this->t['accent']), true, 'center', $this->t['heading']), null, 'middle', false)
				. $this->text($cx + 0.2, $y + 2.9, $cw - 0.4, $h - 3,
					($title !== '' ? $this->p($title, 17, $this->t['headings'], true, 'center', $this->t['heading'], 100, 0.15) : '') . $this->p($text, 14, $this->t['text'], false, 'center', $this->t['body']), null, 'top');
		}
		return $out;
	}

	/** A timeline: "1789: Storming of the Bastille" as dots on a line, dates above, text below. */
	private function timeline(array $lines, float $x, float $y, float $w, float $h): string {
		$items = [];
		foreach ($lines as $line) {
			$line = preg_replace('/^\s*([-*+]|\d+[.)])\s+/', '', $line) ?? $line;
			if (trim($line) !== '') {
				$items[] = preg_match('/^\s*\**([^:*]{1,30}?)\**\s*[:–—]\s*(.+)$/u', $line, $m) === 1 ? [trim($m[1]), trim($m[2])] : ['', trim($line)];
			}
		}
		$items = array_slice($items, 0, 7);
		$count = max(1, count($items));
		$cw = $w / $count;
		$lineY = $y + $h * 0.42;
		$out = $this->rect($x, $lineY, $w, 0.12, $this->t['accent2']);
		foreach ($items as $i => [$date, $text]) {
			$cx = $x + $i * $cw;
			$color = $i % 2 === 0 ? $this->t['accent'] : $this->t['accent2'];
			$out .= $this->ellipse($cx + $cw / 2 - 0.35, $lineY - 0.29, 0.7, 0.7, $color)
				. $this->text($cx + 0.1, $lineY - 2.4, $cw - 0.2, 1.9, $this->p($date, 20, $color, true, 'center', $this->t['heading']), null, 'bottom')
				. $this->text($cx + 0.1, $lineY + 0.8, $cw - 0.2, $h * 0.5, $this->p($text, 14, $this->t['text'], false, 'center', $this->t['body']), null, 'top');
		}
		return $out;
	}

	/** A Markdown table as rows of cells: the header row in the accent colour, the rows striped. */
	private function table(array $lines, float $x, float $y, float $w, float $h): string {
		$rows = [];
		$other = [];
		foreach ($lines as $line) {
			if (preg_match('/^\s*\|/', $line) === 1) {
				if (preg_match('/^\s*\|?\s*:?-{2,}/', $line) !== 1) {
					$rows[] = Odf::cells($line);
				}
			} else {
				$other[] = $line;
			}
		}
		$rows = array_slice($rows, 0, 12);
		$columns = max(1, ...array_map('count', $rows ?: [[]]));
		$tableHeight = $other !== [] ? $h - 2.2 : $h;
		$rh = min(1.3, $tableHeight / max(1, count($rows)));
		$cw = $w / $columns;
		$size = $rh < 0.9 ? 12 : 15;
		$out = '';
		foreach ($rows as $r => $cells) {
			$fill = $r === 0 ? $this->t['accent'] : ($r % 2 === 0 ? $this->t['light'] : $this->t['background']);
			$out .= $this->rect($x, $y + $r * $rh, $w, $rh, $fill);
			for ($c = 0; $c < $columns; $c++) {
				$out .= $this->text($x + $c * $cw + 0.2, $y + $r * $rh, $cw - 0.4, $rh,
					$this->p($cells[$c] ?? '', $size, $r === 0 ? $this->onColor($this->t['accent']) : $this->t['text'], $r === 0, 'start', $this->t['body']), null, 'middle');
			}
		}
		$out .= $this->rect($x, $y + count($rows) * $rh, $w, 0.05, $this->t['accent']);
		return $out . ($other !== [] ? $this->list($other, $x, $y + count($rows) * $rh + 0.4, $w, 1.8, 15) : '');
	}

	/** @return list<array{string, string}> title and text of list items */
	private function titledItems(array $lines): array {
		$items = [];
		foreach ($lines as $line) {
			$line = preg_replace('/^\s*([-*+]|\d+[.)])\s+/', '', $line) ?? $line;
			if (trim($line) === '') {
				continue;
			}
			if (preg_match('/^\s*\*\*(.+?)\*\*\s*[:–—-]?\s*(.*)$/u', $line, $m) === 1 || preg_match('/^\s*([^:]{1,40}?)\s*(?::|\s—\s|\s–\s)\s*(.+)$/u', $line, $m) === 1) {
				$items[] = [trim($m[1]), trim($m[2])];
			} else {
				$items[] = ['', trim($line)];
			}
		}
		return $items;
	}

	// --- Shapes -----------------------------------------------------------------

	/** Title and section slides: the theme's gradient (the page) and its decoration in white. */
	private function decorateTitle(): string {
		return match ($this->t['decoration']) {
			'circles' => $this->ellipse(19, -4, 13, 13, '#ffffff', 88) . $this->ellipse(23, 8.5, 9, 9, '#ffffff', 92),
			'corner' => $this->ellipse(20.5, -7.5, 14, 14, '#ffffff', 90),
			'band' => $this->rect(0, self::H - 0.5, self::W, 0.5, $this->t['accent2']),
			'bar' => $this->rect(0, 0, 0.6, self::H, $this->t['accent2']),
			default => '',
		};
	}

	private function rect(float $x, float $y, float $w, float $h, string $fill, float $radius = 0, int $transparency = 0): string {
		return '<draw:rect draw:style-name="' . $this->fill($fill, $transparency) . '" draw:layer="layout" svg:x="' . self::cm($x) . '" svg:y="' . self::cm($y)
			. '" svg:width="' . self::cm($w) . '" svg:height="' . self::cm($h) . '"' . ($radius > 0 ? ' draw:corner-radius="' . self::cm($radius) . '"' : '') . '/>';
	}

	private function ellipse(float $x, float $y, float $w, float $h, string $fill, int $transparency = 0): string {
		return '<draw:ellipse draw:style-name="' . $this->fill($fill, $transparency) . '" draw:layer="layout" svg:x="' . self::cm($x) . '" svg:y="' . self::cm($y)
			. '" svg:width="' . self::cm($w) . '" svg:height="' . self::cm($h) . '"/>';
	}

	/** A text box; with a presentation class (title, subtitle, outline) it is that placeholder in PowerPoint. */
	private function text(float $x, float $y, float $w, float $h, string $paragraphs, ?string $class, string $valign = 'top', bool $shrink = true): string {
		$style = $this->frameStyle($valign, $shrink, $class !== null);
		return '<draw:frame ' . ($class !== null ? 'presentation:class="' . $class . '" presentation:style-name="' . $style . '"' : 'draw:style-name="' . $style . '"')
			. ' draw:layer="layout" svg:x="' . self::cm($x) . '" svg:y="' . self::cm($y) . '" svg:width="' . self::cm($w) . '" svg:height="' . self::cm($h) . '">'
			. '<draw:text-box>' . $paragraphs . '</draw:text-box></draw:frame>';
	}

	/** A picture fitted into the box (or covering it, cropped by the slide's edge), centred. */
	private function picture(array $figure, float $x, float $y, float $w, float $h, bool $cover, bool $top = false): string {
		[$iw, $ih] = [max(1, $figure['width']), max(1, $figure['height'])];
		$scale = $cover ? max($w / $iw, $h / $ih) : min($w / $iw, $h / $ih);
		[$fw, $fh] = [$iw * $scale, $ih * $scale];
		$fy = $top ? $y : $y + ($h - $fh) / 2;
		return '<draw:frame draw:layer="layout" svg:x="' . self::cm($x + ($w - $fw) / 2) . '" svg:y="' . self::cm($fy) . '" svg:width="' . self::cm($fw) . '" svg:height="' . self::cm($fh) . '">'
			. '<draw:image xlink:href="' . $figure['path'] . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad" draw:mime-type="' . $figure['mime'] . '"/></draw:frame>';
	}

	/** A paragraph of Markdown inline text in this size, colour, weight, alignment and font. */
	private function p(string $text, float $size, string $color, bool $bold, string $align, string $font, int $opacity = 100, float $space = 0, bool $italic = false): string {
		if ($opacity < 100) {
			// Softer: mixed with the background it stands on (the gradient for white text).
			$color = self::mix($color, $color === '#ffffff' ? $this->t['gradient'][0] : $this->t['background'], $opacity / 100);
		}
		$name = 'P' . substr(md5(implode('|', [$size, $color, $bold, $align, $font, $space, $italic])), 0, 8);
		$this->styles[$name] ??= '<style:style style:name="' . $name . '" style:family="paragraph"><style:paragraph-properties fo:text-align="' . $align . '" fo:margin-top="' . $space . 'cm"/>'
			. '<style:text-properties style:font-name="' . Odf::xml($font) . '" fo:font-family="' . Odf::xml($font) . '" fo:font-size="' . $size . 'pt" fo:color="' . $color . '"'
			. ($bold ? ' fo:font-weight="bold"' : '') . ($italic ? ' fo:font-style="italic"' : '') . '/></style:style>';
		return '<text:p text:style-name="' . $name . '">' . self::inline($text) . '</text:p>';
	}

	private function fill(string $color, int $transparency): string {
		$name = 'gr' . substr(md5($color . $transparency), 0, 8);
		$this->styles[$name] ??= '<style:style style:name="' . $name . '" style:family="graphic"><style:graphic-properties draw:stroke="none" draw:fill="solid" draw:fill-color="' . $color . '"'
			. ($transparency > 0 ? ' draw:opacity="' . (100 - $transparency) . '%"' : '') . ' draw:shadow="hidden"/></style:style>';
		return $name;
	}

	private function frameStyle(string $valign, bool $shrink, bool $presentation): string {
		$name = ($presentation ? 'pr' : 'gf') . ucfirst($valign) . ($shrink ? 'S' : 'N');
		$this->styles[$name] ??= '<style:style style:name="' . $name . '" style:family="' . ($presentation ? 'presentation' : 'graphic') . '"><style:graphic-properties draw:stroke="none" draw:fill="none"'
			. ' draw:textarea-vertical-align="' . $valign . '" draw:auto-grow-height="false" fo:padding="0.05cm"' . ($shrink ? ' draw:fit-to-size="false" style:shrink-to-fit="true"' : '') . '/></style:style>';
		return $name;
	}

	/** White or near-black, whichever reads on this colour. */
	private function onColor(string $color): string {
		[$r, $g, $b] = array_map('hexdec', str_split(ltrim($color, '#'), 2));
		return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 165 ? '#111111' : '#ffffff';
	}

	private function muted(): string {
		return self::mix($this->t['text'], $this->t['background'], 0.6);
	}

	/** Colour A mixed with B, A by this weight. */
	private static function mix(string $a, string $b, float $weight): string {
		[$ar, $ag, $ab] = array_map('hexdec', str_split(ltrim($a, '#'), 2));
		[$br, $bg, $bb] = array_map('hexdec', str_split(ltrim($b, '#'), 2));
		return sprintf('#%02x%02x%02x', round($ar * $weight + $br * (1 - $weight)), round($ag * $weight + $bg * (1 - $weight)), round($ab * $weight + $bb * (1 - $weight)));
	}

	private static function cm(float $v): string {
		return round($v, 3) . 'cm';
	}

	/** Inline Markdown, formulas in Unicode (a slide's text box takes no formula objects). */
	private static function inline(string $text): string {
		return OdtWriter::inline(Math::replace($text, fn (string $latex) => Math::unicode($latex)));
	}

	/** The speaker notes under the slide's thumbnail. */
	private function notesPage(int $n, array $notes): string {
		$text = $notes === [] ? '<text:p/>' : implode('', array_map(fn (string $l) => '<text:p>' . self::inline($l) . '</text:p>', $notes));
		return '<presentation:notes draw:style-name="dpBody"><draw:page-thumbnail presentation:class="page" svg:x="2cm" svg:y="1.5cm" svg:width="17cm" svg:height="9.6cm" draw:page-number="' . ($n + 1) . '"/>'
			. '<draw:frame presentation:class="notes" presentation:style-name="' . $this->frameStyle('top', false, true) . '" svg:x="2cm" svg:y="12cm" svg:width="17cm" svg:height="14cm"><draw:text-box>' . $text . '</draw:text-box></draw:frame></presentation:notes>';
	}

	// --- Styles -----------------------------------------------------------------

	private function fixedStyles(): string {
		$t = $this->t;
		return '<style:style style:name="dpBody" style:family="drawing-page"><style:drawing-page-properties draw:background-size="full" draw:fill="solid" draw:fill-color="' . $t['background'] . '" presentation:display-page-number="true" presentation:display-footer="false" presentation:display-date-time="false" presentation:display-header="false"/></style:style>'
			. '<style:style style:name="dpGradient" style:family="drawing-page"><style:drawing-page-properties draw:background-size="full" draw:fill="gradient" draw:fill-gradient-name="ThemeGradient" presentation:display-page-number="false" presentation:display-footer="false" presentation:display-date-time="false" presentation:display-header="false"/></style:style>'
			. '<style:style style:name="Strong" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
			. '<style:style style:name="Emphasis" style:family="text"><style:text-properties fo:font-style="italic"/></style:style>'
			. '<style:style style:name="Code" style:family="text"><style:text-properties style:font-name="Liberation Mono" fo:font-family="Liberation Mono"/></style:style>'
			. '<style:style style:name="Deleted" style:family="text"><style:text-properties style:text-line-through-style="solid" style:text-line-through-type="single" fo:color="#c00000"/></style:style>'
			. '<style:style style:name="Inserted" style:family="text"><style:text-properties style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color" fo:color="#00804a"/></style:style>'
			. '<style:style style:name="Highlight" style:family="text"><style:text-properties fo:background-color="#fff59d"/></style:style>'
			. '<text:list-style style:name="L1">'
			. '<text:list-level-style-bullet text:level="1" text:bullet-char="●"><style:list-level-properties text:space-before="0cm" text:min-label-width="0.9cm"/><style:text-properties fo:color="' . $t['accent'] . '" fo:font-size="70%"/></text:list-level-style-bullet>'
			. '<text:list-level-style-bullet text:level="2" text:bullet-char="–"><style:list-level-properties text:space-before="1.2cm" text:min-label-width="0.8cm"/><style:text-properties fo:color="' . $t['accent'] . '"/></text:list-level-style-bullet>'
			. '<text:list-level-style-bullet text:level="3" text:bullet-char="●"><style:list-level-properties text:space-before="2.3cm" text:min-label-width="0.7cm"/><style:text-properties fo:color="' . $t['accent2'] . '" fo:font-size="55%"/></text:list-level-style-bullet>'
			. '</text:list-style>'
			. '<text:list-style style:name="L2">'
			. '<text:list-level-style-number text:level="1" style:num-suffix="." style:num-format="1"><style:list-level-properties text:space-before="0cm" text:min-label-width="0.9cm"/><style:text-properties fo:color="' . $t['accent'] . '" fo:font-weight="bold"/></text:list-level-style-number>'
			. '<text:list-level-style-number text:level="2" style:num-suffix="." style:num-format="a"><style:list-level-properties text:space-before="1.2cm" text:min-label-width="0.8cm"/><style:text-properties fo:color="' . $t['accent'] . '"/></text:list-level-style-number>'
			. '</text:list-style>';
	}

	/** The gradient, and the master pages: "Body" with the decoration and slide number, "Plain" without. */
	private function masterStyles(): string {
		$t = $this->t;
		$fonts = array_unique([$t['heading'], $t['body'], 'Liberation Serif', 'Liberation Mono']);
		$decoration = match ($t['decoration']) {
			'bar' => '<draw:rect draw:style-name="Mdeco1" draw:layer="backgroundobjects" svg:x="0cm" svg:y="0cm" svg:width="0.35cm" svg:height="15.75cm"/>',
			'band' => '<draw:rect draw:style-name="Mdeco1" draw:layer="backgroundobjects" svg:x="0cm" svg:y="15.35cm" svg:width="28cm" svg:height="0.4cm"/>'
				. '<draw:rect draw:style-name="Mdeco2" draw:layer="backgroundobjects" svg:x="0cm" svg:y="15.25cm" svg:width="28cm" svg:height="0.1cm"/>',
			'circles' => '<draw:ellipse draw:style-name="Mdeco3" draw:layer="backgroundobjects" svg:x="24.5cm" svg:y="-2.5cm" svg:width="6cm" svg:height="6cm"/>'
				. '<draw:ellipse draw:style-name="Mdeco4" draw:layer="backgroundobjects" svg:x="26.2cm" svg:y="1.8cm" svg:width="2.6cm" svg:height="2.6cm"/>',
			'corner' => '<draw:ellipse draw:style-name="Mdeco3" draw:layer="backgroundobjects" svg:x="23.5cm" svg:y="-5cm" svg:width="8cm" svg:height="8cm"/>',
			default => '',
		};
		$muted = self::mix($t['text'], $t['background'], 0.55);
		return '<office:document-styles ' . Odf::NAMESPACES . '><office:font-face-decls>'
			. implode('', array_map(fn (string $f) => '<style:font-face style:name="' . Odf::xml($f) . '" svg:font-family="&apos;' . Odf::xml($f) . '&apos;"/>', $fonts))
			. '</office:font-face-decls><office:styles>'
			. '<draw:gradient draw:name="ThemeGradient" draw:style="linear" draw:angle="1200" draw:start-color="' . $t['gradient'][0] . '" draw:end-color="' . $t['gradient'][1] . '" draw:start-intensity="100%" draw:end-intensity="100%" draw:border="0%"/>'
			. '<style:default-style style:family="graphic"><style:text-properties style:font-name="' . Odf::xml($t['body']) . '" fo:font-size="18pt" fo:color="' . $t['text'] . '"/></style:default-style>'
			. '</office:styles><office:automatic-styles>'
			. '<style:page-layout style:name="PM1"><style:page-layout-properties fo:margin-top="0cm" fo:margin-bottom="0cm" fo:margin-left="0cm" fo:margin-right="0cm" fo:page-width="28cm" fo:page-height="15.75cm" style:print-orientation="landscape"/></style:page-layout>'
			. '<style:style style:name="Mdp1" style:family="drawing-page"><style:drawing-page-properties draw:background-size="full" draw:fill="solid" draw:fill-color="' . $t['background'] . '"/></style:style>'
			. '<style:style style:name="Mdeco1" style:family="graphic"><style:graphic-properties draw:stroke="none" draw:fill="solid" draw:fill-color="' . $t['accent'] . '"/></style:style>'
			. '<style:style style:name="Mdeco2" style:family="graphic"><style:graphic-properties draw:stroke="none" draw:fill="solid" draw:fill-color="' . $t['accent2'] . '"/></style:style>'
			. '<style:style style:name="Mdeco3" style:family="graphic"><style:graphic-properties draw:stroke="none" draw:fill="solid" draw:fill-color="' . $t['accent'] . '" draw:opacity="14%"/></style:style>'
			. '<style:style style:name="Mdeco4" style:family="graphic"><style:graphic-properties draw:stroke="none" draw:fill="solid" draw:fill-color="' . $t['accent2'] . '" draw:opacity="30%"/></style:style>'
			. '<style:style style:name="MprNum" style:family="presentation"><style:graphic-properties draw:stroke="none" draw:fill="none" draw:textarea-vertical-align="bottom"/></style:style>'
			. '<style:style style:name="MPNum" style:family="paragraph"><style:paragraph-properties fo:text-align="end"/><style:text-properties style:font-name="' . Odf::xml($t['body']) . '" fo:font-size="11pt" fo:color="' . $muted . '"/></style:style>'
			. '</office:automatic-styles><office:master-styles>'
			. '<style:master-page style:name="Body" style:page-layout-name="PM1" draw:style-name="Mdp1">' . $decoration
			. '<draw:frame presentation:style-name="MprNum" draw:layer="backgroundobjects" presentation:class="page-number" svg:x="24.6cm" svg:y="14.3cm" svg:width="2.4cm" svg:height="0.9cm"><draw:text-box><text:p text:style-name="MPNum"><text:page-number>&lt;number&gt;</text:page-number></text:p></draw:text-box></draw:frame>'
			. '</style:master-page>'
			. '<style:master-page style:name="Plain" style:page-layout-name="PM1" draw:style-name="Mdp1"/>'
			. '</office:master-styles></office:document-styles>';
	}
}
