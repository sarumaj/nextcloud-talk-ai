<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Studies of a painting or photo for painters (Imagick): grayscale to judge
 * values, value steps (a notan of 2 or more tones), a composition grid (thirds,
 * golden ratio, diagonals), the mirror image (fresh eyes on the composition),
 * and a blurred version (the big shapes only). Each comes back as PNG.
 */
class ImageStudy {
	public const KINDS = ['grayscale', 'values', 'grid', 'mirror', 'blur'];

	public static function make(string $image, string $kind, int $levels = 3, string $grid = 'thirds'): string {
		if (!class_exists(\Imagick::class)) {
			throw new \RuntimeException('Imagick is not available on this server');
		}
		$im = new \Imagick();
		$im->readImageBlob($image);
		$im->setIteratorIndex(0);
		$im = $im->getImage();
		if (method_exists($im, 'autoOrient')) {
			$im->autoOrient();
		}
		$im->thumbnailImage(1600, 1600, true);
		$im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
		switch ($kind) {
			case 'grayscale':
				$im->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
				break;
			case 'values':
				// Value steps of equal width on the lightness, each shown at its middle grey.
				$im->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
				$im->posterizeImage(max(2, min(9, $levels)), false);
				break;
			case 'mirror':
				$im->flopImage();
				break;
			case 'blur':
				$im->gaussianBlurImage(0, max(4, $im->getImageWidth() / 120));
				break;
			case 'grid':
				self::grid($im, $grid);
				break;
			default:
				throw new \InvalidArgumentException('a study: ' . implode(', ', self::KINDS));
		}
		$im->setImageFormat('png');
		$png = $im->getImageBlob();
		$im->clear();
		return $png;
	}

	private static function grid(\Imagick $im, string $kind): void {
		$w = $im->getImageWidth();
		$h = $im->getImageHeight();
		$draw = new \ImagickDraw();
		$draw->setStrokeWidth(max(2, $w / 500));
		$draw->setFillOpacity(0);
		$lines = [];
		$ratios = $kind === 'golden' ? [0.381966, 0.618034] : [1 / 3, 2 / 3];
		foreach ($ratios as $f) {
			$lines[] = [$w * $f, 0, $w * $f, $h];
			$lines[] = [0, $h * $f, $w, $h * $f];
		}
		if ($kind === 'diagonals') {
			$lines = [[0, 0, $w, $h], [$w, 0, 0, $h], [0, 0, $w * 0.5, $h], [$w, 0, $w * 0.5, $h]];
		}
		// Each line twice: dark under light, so it shows on any colour.
		foreach ([['#000000', 0.45, 1.8], ['#ffffff', 0.9, 1.0]] as [$color, $opacity, $width]) {
			$draw->setStrokeColor(new \ImagickPixel($color));
			$draw->setStrokeOpacity($opacity);
			$draw->setStrokeWidth(max(1.5, $w / 600) * $width);
			foreach ($lines as [$x1, $y1, $x2, $y2]) {
				$draw->line($x1, $y1, $x2, $y2);
			}
		}
		$im->drawImage($draw);
	}
}
