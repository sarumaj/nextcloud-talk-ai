<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Designs for documents and slides: presets, or the model's own design (any of
 * the keys below on top of a preset). Fonts are those Collabora has, so the PDF
 * looks as meant; Word and PowerPoint fall back to similar ones where missing.
 *
 * Keys: heading and body (fonts), size (body text, pt), text, headings,
 * background, accent, accent2, light (colours), dark (light text on a dark
 * background), gradient (two colours for title and section slides), decoration
 * (bar, band, circles, corner, none), justify, numbered (headings), line (%).
 */
final class Theme {
	public const FONTS = ['Open Sans', 'Source Sans Pro', 'Rubik', 'Karla', 'Noto Sans', 'Noto Serif', 'Carlito', 'Caladea', 'Liberation Sans',
		'Liberation Serif', 'DejaVu Sans', 'DejaVu Serif', 'Linux Libertine G', 'Linux Biolinum G', 'Caprasimo', 'Belanosima', 'Bacasime Antique',
		'Lunasima', 'Alef', 'OpenDyslexic'];

	private const BASE = ['heading' => 'Rubik', 'body' => 'Open Sans', 'size' => 11, 'text' => '#1f2937', 'headings' => '#111827',
		'background' => '#ffffff', 'accent' => '#2563eb', 'accent2' => '#06b6d4', 'light' => '#eff6ff', 'dark' => false,
		'gradient' => ['#1e3a8a', '#2563eb'], 'decoration' => 'bar', 'justify' => false, 'numbered' => false, 'line' => 125];

	public const PRESETS = [
		'modern' => [],
		'classic' => ['heading' => 'Linux Libertine G', 'body' => 'Liberation Serif', 'size' => 11.5, 'text' => '#2b2118', 'headings' => '#3a2a1a',
			'background' => '#fffdf8', 'accent' => '#7b1e1e', 'accent2' => '#b08d57', 'light' => '#f6efe6', 'gradient' => ['#4a1010', '#7b1e1e'],
			'decoration' => 'band', 'justify' => true],
		'academic' => ['heading' => 'Source Sans Pro', 'body' => 'Noto Serif', 'size' => 11, 'text' => '#111111', 'headings' => '#000000',
			'accent' => '#1e3a5f', 'accent2' => '#64748b', 'light' => '#eef2f6', 'gradient' => ['#0f2740', '#1e3a5f'], 'decoration' => 'band',
			'justify' => true, 'numbered' => true, 'line' => 145],
		'minimal' => ['heading' => 'Karla', 'body' => 'Karla', 'size' => 10.5, 'text' => '#222222', 'headings' => '#111111', 'accent' => '#111111',
			'accent2' => '#9ca3af', 'light' => '#f4f4f5', 'gradient' => ['#111111', '#3f3f46'], 'decoration' => 'none'],
		'playful' => ['heading' => 'Caprasimo', 'body' => 'Rubik', 'size' => 11, 'text' => '#2d2a32', 'headings' => '#3d1a78', 'background' => '#fffaf0',
			'accent' => '#ef476f', 'accent2' => '#06d6a0', 'light' => '#fff1e6', 'gradient' => ['#ef476f', '#ffd166'], 'decoration' => 'circles'],
		'dark' => ['heading' => 'Rubik', 'body' => 'Open Sans', 'text' => '#e2e8f0', 'headings' => '#ffffff', 'background' => '#0f172a',
			'accent' => '#38bdf8', 'accent2' => '#a78bfa', 'light' => '#1e293b', 'dark' => true, 'gradient' => ['#020617', '#1e293b'], 'decoration' => 'corner'],
		'gradient' => ['heading' => 'Source Sans Pro', 'body' => 'Open Sans', 'accent' => '#7c3aed', 'accent2' => '#ec4899', 'light' => '#f5f3ff',
			'headings' => '#2e1065', 'gradient' => ['#6d28d9', '#db2777'], 'decoration' => 'circles'],
		'bold' => ['heading' => 'Source Sans Pro', 'body' => 'Source Sans Pro', 'size' => 11.5, 'text' => '#111111', 'headings' => '#000000',
			'accent' => '#facc15', 'accent2' => '#111111', 'light' => '#fef9c3', 'gradient' => ['#000000', '#111111'], 'decoration' => 'band'],
		'nature' => ['heading' => 'Lunasima', 'body' => 'Noto Sans', 'text' => '#1f2a1f', 'headings' => '#1b4332', 'background' => '#fbfdf8',
			'accent' => '#2d6a4f', 'accent2' => '#95d5b2', 'light' => '#eef6ee', 'gradient' => ['#1b4332', '#40916c'], 'decoration' => 'corner'],
		'elegant' => ['heading' => 'Bacasime Antique', 'body' => 'Source Sans Pro', 'text' => '#1c1917', 'headings' => '#1c1917', 'background' => '#fcfbf9',
			'accent' => '#a16207', 'accent2' => '#1e293b', 'light' => '#f5f0e6', 'gradient' => ['#0f172a', '#1e293b'], 'decoration' => 'band'],
		'accessible' => ['heading' => 'OpenDyslexic', 'body' => 'OpenDyslexic', 'size' => 12, 'text' => '#000000', 'headings' => '#000000',
			'accent' => '#0050b3', 'accent2' => '#b35900', 'light' => '#fff8dc', 'gradient' => ['#002a5c', '#0050b3'], 'decoration' => 'none', 'line' => 150],
	];

	/**
	 * A complete theme: a preset by name, or a design {base, …keys} on top of one.
	 * Unknown fonts and malformed colours are ignored.
	 */
	public static function resolve(string|array|null $theme): array {
		if (is_string($theme) || $theme === null) {
			return array_merge(self::BASE, self::PRESETS[strtolower(trim((string)$theme))] ?? []);
		}
		$out = array_merge(self::BASE, self::PRESETS[strtolower((string)($theme['base'] ?? 'modern'))] ?? []);
		foreach (['text', 'headings', 'background', 'accent', 'accent2', 'light'] as $key) {
			if (isset($theme[$key]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$theme[$key]) === 1) {
				$out[$key] = strtolower((string)$theme[$key]);
			}
		}
		foreach (['heading', 'body'] as $key) {
			if (isset($theme[$key]) && in_array((string)$theme[$key], self::FONTS, true)) {
				$out[$key] = (string)$theme[$key];
			}
		}
		if (isset($theme['gradient']) && is_array($theme['gradient']) && count($theme['gradient']) === 2
			&& array_filter($theme['gradient'], fn ($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c) !== 1) === []) {
			$out['gradient'] = array_values($theme['gradient']);
		}
		if (isset($theme['decoration']) && in_array($theme['decoration'], ['bar', 'band', 'circles', 'corner', 'none'], true)) {
			$out['decoration'] = $theme['decoration'];
		}
		foreach (['dark', 'justify', 'numbered'] as $key) {
			if (isset($theme[$key])) {
				$out[$key] = filter_var($theme[$key], FILTER_VALIDATE_BOOLEAN);
			}
		}
		if (isset($theme['size']) && is_numeric($theme['size'])) {
			$out['size'] = max(8, min(16, (float)$theme['size']));
		}
		return $out;
	}

	/** The theme for print: a dark design keeps its colours and fonts but prints dark on white. */
	public static function forPrint(array $t): array {
		if (!$t['dark']) {
			return $t;
		}
		return array_merge($t, ['text' => '#1f2937', 'headings' => $t['gradient'][0] === '#020617' ? '#0f172a' : $t['gradient'][0], 'background' => '#ffffff', 'light' => '#f1f5f9', 'dark' => false]);
	}

	/** The JSON Schema of a design for the tools. */
	public static function schema(): array {
		return ['description' => 'A preset name (' . implode(', ', array_keys(self::PRESETS)) . '), or your own design: {base (a preset), heading and '
			. 'body (fonts: ' . implode(', ', self::FONTS) . '), text, headings, background, accent, accent2, light (#rrggbb), dark, '
			. 'gradient [#from, #to] (title and section slides), decoration (bar, band, circles, corner, none), size, justify, numbered}'];
	}
}
