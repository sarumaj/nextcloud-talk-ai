<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * What the OpenDocument writers share: the package (a ZIP with the MIME type
 * first and uncompressed, a manifest, content, styles and meta) and escaping.
 */
final class Odf {
	public const TEXT = 'application/vnd.oasis.opendocument.text';
	public const SPREADSHEET = 'application/vnd.oasis.opendocument.spreadsheet';
	public const PRESENTATION = 'application/vnd.oasis.opendocument.presentation';

	/** The namespaces of content.xml and styles.xml, all writers' alike. */
	public const NAMESPACES = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
		. 'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
		. 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
		. 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
		. 'xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" '
		. 'xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" '
		. 'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" '
		. 'xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" '
		. 'xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" '
		. 'xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" '
		. 'xmlns:xlink="http://www.w3.org/1999/xlink" '
		. 'office:version="1.3"';

	/**
	 * The package of a document of this MIME type, with embedded formulas
	 * (name => MathML) as objects "./name", and pictures (path => [data, MIME type])
	 * at "Pictures/…".
	 */
	public static function package(string $mime, string $content, string $styles, string $title, array $formulas = [], array $pictures = []): string {
		$zipPath = tempnam(sys_get_temp_dir(), 'talk-ai-odf');
		$zip = new \ZipArchive();
		try {
			$zip->open($zipPath, \ZipArchive::OVERWRITE);
			$zip->addFromString('mimetype', $mime);
			$zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
			$zip->addFromString('META-INF/manifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
				. '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
				. '<manifest:file-entry manifest:full-path="/" manifest:media-type="' . $mime . '"/>'
				. '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
				. '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
				. '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'
				. implode('', array_map(fn (string $name) => '<manifest:file-entry manifest:full-path="' . $name . '/" manifest:media-type="application/vnd.oasis.opendocument.formula"/>'
					. '<manifest:file-entry manifest:full-path="' . $name . '/content.xml" manifest:media-type="text/xml"/>', array_keys($formulas)))
				. implode('', array_map(fn (string $path, array $p) => '<manifest:file-entry manifest:full-path="' . $path . '" manifest:media-type="' . $p[1] . '"/>', array_keys($pictures), $pictures))
				. '</manifest:manifest>');
			foreach ($pictures as $path => [$data]) {
				$zip->addFromString($path, $data);
			}
			foreach ($formulas as $name => $mathml) {
				$zip->addFromString($name . '/content.xml', '<?xml version="1.0" encoding="UTF-8"?>' . $mathml);
			}
			$zip->addFromString('styles.xml', '<?xml version="1.0" encoding="UTF-8"?>' . $styles);
			$zip->addFromString('meta.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" office:version="1.3"><office:meta><dc:title>' . self::xml($title) . '</dc:title></office:meta></office:document-meta>');
			$zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?>' . $content);
			$zip->close();
			return (string)file_get_contents($zipPath);
		} finally {
			@unlink($zipPath);
		}
	}

	/**
	 * A picture's frame: as a character in the text (centred by its paragraph),
	 * width at most $maxCm and height at most $maxHeightCm, keeping its proportions.
	 */
	public static function picture(string $path, array $picture, float $maxCm, float $maxHeightCm, string $extra = ''): string {
		[$w, $h] = [max(1, $picture['width'] ?? 4), max(1, $picture['height'] ?? 3)];
		$width = $maxCm;
		$height = $width * $h / $w;
		if ($height > $maxHeightCm) {
			[$height, $width] = [$maxHeightCm, $maxHeightCm * $w / $h];
		}
		return '<draw:frame draw:style-name="Picture" ' . $extra . 'svg:width="' . round($width, 2) . 'cm" svg:height="' . round($height, 2) . 'cm">'
			. '<draw:image xlink:href="' . $path . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad" draw:mime-type="' . $picture['mime'] . '"/></draw:frame>';
	}

	/** A picture's file name in the package by its MIME type. */
	public static function picturePath(int $n, string $mime): string {
		return 'Pictures/picture' . $n . '.' . (['image/svg+xml' => 'svg', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'png');
	}

	public static function xml(string $text): string {
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	/** A Markdown table row's cells. */
	public static function cells(string $line): array {
		return array_map('trim', explode('|', trim(trim($line), '|')));
	}

	/** Whether this line and the next start a Markdown table (a header row, then its rule). */
	public static function isTable(array $lines, int $i): bool {
		return str_starts_with(ltrim($lines[$i]), '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{2,}/', $lines[$i + 1]) === 1;
	}
}
