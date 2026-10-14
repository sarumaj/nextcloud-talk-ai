<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Document\OdtWriter;
use OCA\TalkAi\Document\TextExtractor;
use OCP\Files\Conversion\IConversionManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Answers one message: reads the conversation, asks AI-Hub (scenario "chat",
 * with web search and the tools below, which run with the asking person's
 * rights), and posts the answer as the bot. Images shared lately by the asking
 * person go with the question; files are read and sent through the tools.
 */
class Reply {
	public const SCENARIO = 'chat';
	/** Images handed to the model with a question (AI-Hub takes four, of 5 MB each). */
	private const MAX_IMAGES = 4;
	private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
	private const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
	/** Formats send_file writes, by extension. */
	private const FORMATS = ['md' => 'text/markdown', 'txt' => 'text/plain', 'csv' => 'text/csv', 'html' => 'text/html',
		'json' => 'application/json', 'odt' => 'application/vnd.oasis.opendocument.text', 'pdf' => 'application/pdf'];

	/** The question being answered: what the tools work on. */
	private ?object $room = null;
	private string $userId = '';
	private int $messageId = 0;
	/** @var list<array{id: int, name: string, mime: string, size: int}> */
	private array $sharedFiles = [];

	public function __construct(
		private Conversation $conversation,
		private TextExtractor $extractor,
		private OdtWriter $odt,
		private IRootFolder $rootFolder,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function answer(string $token, string $userId, int $messageId, string $text): void {
		try {
			$room = $this->conversation->room($token);
		} catch (\Throwable $e) {
			$this->logger->warning('AI bot: no conversation ' . $token . ': ' . $e->getMessage());
			return;
		}
		try {
			$this->answerIn($room, $userId, $messageId, $text);
		} catch (\Throwable $e) {
			$this->logger->error('AI bot: no answer: ' . $e->getMessage(), ['exception' => $e]);
			$this->conversation->postText($room, '⚠️ I could not answer this time (' . mb_substr($e->getMessage(), 0, 200) . ').', $messageId);
		}
	}

	private function answerIn(object $room, string $userId, int $messageId, string $text): void {
		$hub = class_exists('OCA\AIHub\Service\HubService') ? Server::get('OCA\AIHub\Service\HubService') : null;
		if ($hub === null) {
			$this->conversation->postText($room, '⚠️ AI-Hub, my connection to the AI, is not installed.', $messageId);
			return;
		}
		$this->room = $room;
		$this->userId = $userId;
		$this->messageId = $messageId;
		$history = $this->conversation->history($room, $userId);
		$this->sharedFiles = array_merge(...array_map(fn (array $m) => $m['files'], $history) ?: [[]]);
		$hub->registerScenario(Application::APP_ID, self::SCENARIO, [
			'system' => $this->systemPrompt($room),
			'tools' => $this->tools(),
			'search' => true,
		]);
		$status = $hub->status(Application::APP_ID);
		$images = $status['images'] ? $this->recentImages($history, $userId, $messageId) : [];
		$question = trim(preg_replace('/^\s*@?"?' . preg_quote($this->name(), '/') . '"?\s*[,:]?\s*/iu', '', $text) ?? $text);
		$result = $hub->askNow($userId, Application::APP_ID, self::SCENARIO, [['role' => 'user', 'text' => $question !== '' ? $question : $text]], [
			'context' => $this->transcript($history, $messageId),
			'search' => (bool)$status['search'],
			'images' => $images,
		]);
		if (($result['state'] ?? '') === 'done' && trim((string)($result['text'] ?? '')) !== '') {
			$this->conversation->postText($room, trim((string)$result['text']), $messageId);
		} elseif (($result['state'] ?? '') !== 'done') {
			$this->conversation->postText($room, '⚠️ I could not answer: ' . self::explain((string)($result['error'] ?? 'unknown error')), $messageId);
		}
	}

	private function name(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'name', Application::DEFAULT_NAME) ?: Application::DEFAULT_NAME;
	}

	private function systemPrompt(object $room): string {
		$pdf = $this->extractor->canReadPdf() ? '' : ' PDF files cannot be read on this server.';
		return 'You are ' . $this->name() . ', an assistant in the Nextcloud Talk conversation "' . $room->getName() . '". '
			. 'Answer the last message, in the language it is written in, briefly and to the point; Talk shows Markdown. '
			. 'The context holds the conversation\'s recent messages; files shared there appear as [file "name", id N]. '
			. 'Use the tools to read a file (read_file), to list the files shared here (list_files), to find a file of the '
			. 'person by its name (find_files), and to send a file into the chat (send_file: Markdown, text, CSV, HTML, JSON, '
			. 'ODT or PDF), whenever the person asks for a document, a file or a PDF. Images shared lately come with the '
			. 'question. Search the web for current facts. You act with the rights of the person asking, and only see '
			. 'what they may see.' . $pdf;
	}

	/** The conversation as context: "[id] Name: text" lines, oldest first, the question marked. */
	private function transcript(array $history, int $messageId): string {
		$lines = ['Recent messages of this conversation (oldest first):'];
		foreach ($history as $m) {
			$lines[] = ($m['id'] === $messageId ? '>>> ' : '') . $m['actor'] . ($m['bot'] ? ' (you)' : '') . ': ' . mb_substr($m['text'], 0, 2000);
		}
		return implode("\n", $lines);
	}

	/**
	 * The images the asking person shared since the bot last spoke (the question's
	 * own first), for the model to see.
	 *
	 * @return list<array{type: string, data: string}>
	 */
	private function recentImages(array $history, string $userId, int $messageId): array {
		$candidates = [];
		foreach (array_reverse($history) as $m) {
			if ($m['bot'] && $m['id'] < $messageId) {
				break;
			}
			foreach ($m['files'] as $f) {
				if (in_array($f['mime'], self::IMAGE_TYPES, true) && $f['size'] <= self::MAX_IMAGE_BYTES) {
					$candidates[] = $f['id'];
				}
			}
		}
		$images = [];
		foreach (array_slice(array_unique($candidates), 0, self::MAX_IMAGES) as $id) {
			$file = $this->conversation->userFile($userId, $id);
			if ($file !== null && in_array($file->getMimetype(), self::IMAGE_TYPES, true)) {
				$images[] = ['type' => $file->getMimetype(), 'data' => base64_encode($file->getContent())];
			}
		}
		return $images;
	}

	/** @return array<string, array{description: string, input: array, run: callable}> */
	private function tools(): array {
		return [
			'list_files' => [
				'description' => 'The files shared in this conversation lately: id, name, type and size.',
				'input' => ['type' => 'object'],
				'run' => fn (string $uid, array $args) => $this->sharedFiles,
			],
			'read_file' => [
				'description' => 'The text of a file by its id (from the conversation or find_files): text, Markdown, CSV, HTML, Word, ODF, Excel, PowerPoint or PDF.',
				'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
				'run' => fn (string $uid, array $args) => $this->readFile($uid, (int)$args['id']),
			],
			'find_files' => [
				'description' => 'The person\'s files whose names contain the words: id, path, type and size, newest first.',
				'input' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
				'run' => fn (string $uid, array $args) => $this->conversation->findFiles($uid, (string)$args['query']),
			],
			'send_file' => [
				'description' => 'Sends a file into this chat. The name\'s extension sets the format: .md, .txt, .csv, .html, .json, '
					. 'or .odt / .pdf (written from Markdown: headings, lists, tables, bold, italic, code, links).',
				'input' => ['type' => 'object', 'properties' => [
					'name' => ['type' => 'string', 'description' => 'File name with extension, e.g. "Plan.pdf"'],
					'content' => ['type' => 'string', 'description' => 'The content; Markdown for .md, .odt and .pdf'],
				], 'required' => ['name', 'content']],
				'run' => fn (string $uid, array $args) => $this->sendFile($uid, (string)$args['name'], (string)$args['content']),
			],
		];
	}

	private function readFile(string $uid, int $id): string {
		$file = $this->conversation->userFile($uid, $id);
		if ($file === null) {
			return 'No file with this id that the person may read.';
		}
		if (in_array($file->getMimetype(), self::IMAGE_TYPES, true)) {
			return 'This is an image (' . $file->getName() . '). Images shared lately come with the question; ask the person to share it again if you do not see it.';
		}
		if ($file->getSize() > 50 * 1024 * 1024) {
			return 'The file is too large to read (' . round($file->getSize() / 1048576) . ' MB).';
		}
		$text = $this->extractor->extract($file->getContent(), $file->getMimetype(), $file->getName());
		return $text === null ? 'This kind of file (' . $file->getMimetype() . ') cannot be read as text.' : "File \"" . $file->getName() . "\":\n" . $text;
	}

	private function sendFile(string $uid, string $name, string $content): string {
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (!isset(self::FORMATS[$extension])) {
			$name .= '.md';
			$extension = 'md';
		}
		if ($extension === 'odt' || $extension === 'pdf') {
			$odt = $this->conversation->saveFile($this->room, $uid, pathinfo($name, PATHINFO_FILENAME) . '.odt', $this->odt->write($content, pathinfo($name, PATHINFO_FILENAME)));
			$file = $extension === 'pdf' ? $this->toPdf($odt, $name) : $odt;
		} else {
			$file = $this->conversation->saveFile($this->room, $uid, $name, $content);
		}
		$this->conversation->postFile($this->room, $file, $this->messageId);
		return 'Sent "' . $file->getName() . '" to the chat. Say so briefly; do not repeat its content.';
	}

	/** The ODT as PDF beside it (Collabora, through Nextcloud's file conversion); the ODT is removed. */
	private function toPdf(File $odt, string $name): File {
		try {
			$path = Server::get(IConversionManager::class)->convert($odt, 'application/pdf', $this->conversation->freePath($odt, pathinfo($name, PATHINFO_FILENAME) . '.pdf'));
			$pdf = $this->rootFolder->get($path);
			if (!$pdf instanceof File) {
				throw new \RuntimeException('The PDF was not written');
			}
			$odt->delete();
			return $pdf;
		} catch (\Throwable $e) {
			$this->logger->warning('AI bot: no PDF, sending the ODT: ' . $e->getMessage());
			return $odt;
		}
	}

	/** AI-Hub's error codes in words. */
	public static function explain(string $error): string {
		return match ($error) {
			'not-ready' => 'the AI connection is not set up (AI-Hub, admin settings).',
			'not-allowed', 'refused' => 'you may not use the AI here.',
			'rate-limited', 'busy' => 'too many questions at once; try again in a minute.',
			default => $error,
		};
	}
}
