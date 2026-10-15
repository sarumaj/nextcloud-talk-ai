<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCP\Files\Conversion\IConversionManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * File conversion through Collabora (Nextcloud Office): the OpenDocument files
 * the bot writes into Word, Excel, PowerPoint or PDF, and old binary Office
 * files (.doc, .xls, .ppt, .rtf) into OpenDocument to read them, and charts
 * (SVG) into PNG.
 */
class Office {
	/** What a written OpenDocument file can become, by extension. */
	public const TARGETS = [
		'pdf' => 'application/pdf',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	];

	/** Old formats read through a conversion, and what they become. */
	public const LEGACY = ['doc' => 'odt', 'rtf' => 'odt', 'wpd' => 'odt', 'xls' => 'ods', 'ppt' => 'odp', 'pps' => 'odp', 'vsd' => 'odg'];

	public function __construct(
		private IRootFolder $rootFolder,
		private Conversation $conversation,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The file in another format, saved beside it under this name; the source is
	 * removed. Null when the server cannot convert (no Collabora).
	 */
	public function convert(File $source, string $extension, string $name): ?File {
		try {
			$manager = Server::get(IConversionManager::class);
			$path = $manager->convert($source, self::TARGETS[$extension], $this->conversation->freePath($source, $name));
			$converted = $this->rootFolder->get($path);
			if (!$converted instanceof File) {
				throw new \RuntimeException('the converted file was not written');
			}
			$source->delete();
			return $converted;
		} catch (\Throwable $e) {
			$this->logger->warning('AI bot: no ' . $extension . ' from ' . $source->getName() . ': ' . $e->getMessage());
			return null;
		}
	}

	/** An SVG image as PNG (LibreOffice renders SVG well; Imagick's own renderer does not); null without Collabora. */
	public function png(string $svg, string $name): ?string {
		$class = 'OCA\Richdocuments\Service\RemoteService';
		if (!class_exists($class)) {
			return null;
		}
		try {
			$stream = fopen('php://temp', 'r+');
			fwrite($stream, $svg);
			rewind($stream);
			$result = Server::get($class)->convertTo($name . '.svg', $stream, 'png');
			$data = is_resource($result) ? stream_get_contents($result) : (string)$result;
			return str_starts_with((string)$data, "\x89PNG") ? $data : null;
		} catch (\Throwable $e) {
			$this->logger->warning('AI bot: chart not rendered: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * An old binary Office file as OpenDocument, in memory (Collabora's
	 * conversion, through Nextcloud Office); null when it is not available.
	 *
	 * @return array{data: string, name: string}|null
	 */
	public function modernize(File $file): ?array {
		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		$target = self::LEGACY[$extension] ?? null;
		$class = 'OCA\Richdocuments\Service\RemoteService';
		if ($target === null || !class_exists($class)) {
			return null;
		}
		try {
			$result = Server::get($class)->convertTo($file->getName(), $file->fopen('rb'), $target);
			$data = is_resource($result) ? stream_get_contents($result) : (string)$result;
			return $data === '' || $data === false ? null : ['data' => $data, 'name' => pathinfo($file->getName(), PATHINFO_FILENAME) . '.' . $target];
		} catch (\Throwable $e) {
			$this->logger->warning('AI bot: cannot convert ' . $file->getName() . ' to read it: ' . $e->getMessage());
			return null;
		}
	}
}
