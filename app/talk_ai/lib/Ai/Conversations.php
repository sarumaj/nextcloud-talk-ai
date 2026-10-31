<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\Files\IRootFolder;
use OCP\IAppConfig;

/**
 * Remembered conversations: a caller names a conversation by a token of its own
 * and sends only the new message; the stored turns are the history. Kept per
 * person and token (app config "ai_conv_<hash>", lazy), the last turns only
 * (ai_conversation_turns, 40), and dropped when unused for ai_conversation_hours
 * (12). Only words are kept: an image is a mark in its place, never the image.
 */
class Conversations {
	public function __construct(
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
	) {
	}

	/** @return list<array{role: string, text: string}> the turns, oldest first; [] for a new or lapsed one */
	public function turns(string $userId, string $token): array {
		$stored = $this->load($userId, $token);
		return $stored['turns'] ?? [];
	}

	/** A question and its answer added; the oldest turns go beyond the limit. */
	public function append(string $userId, string $token, string $question, string $answer): void {
		$turns = [...$this->turns($userId, $token), ['role' => 'user', 'text' => $question], ['role' => 'assistant', 'text' => $answer]];
		$max = max(2, $this->appConfig->getValueInt(Engines::APP, 'ai_conversation_turns', 40, lazy: true));
		$turns = array_slice($turns, -$max);
		$this->appConfig->setValueString(Engines::APP, self::key($userId, $token),
			(string)json_encode(['turns' => $turns, 'updated' => time()], JSON_UNESCAPED_UNICODE), lazy: true);
	}

	public function forget(string $userId, string $token): void {
		$this->appConfig->deleteKey(Engines::APP, self::key($userId, $token));
	}

	/** The conversation as Markdown in the person's files; the file's path. */
	public function save(string $userId, string $token, string $title, ?string $summary = null): string {
		$turns = $this->turns($userId, $token);
		if ($turns === []) {
			throw new \RuntimeException('no such conversation');
		}
		$lines = ['# ' . $title, '', '*' . date('Y-m-d H:i') . '*', ''];
		if ($summary !== null) {
			$lines = [...$lines, '## Summary', '', $summary, '', '## Conversation', ''];
		}
		foreach ($turns as $turn) {
			$lines[] = '**' . ($turn['role'] === 'user' ? 'You' : 'AI') . ':** ' . $turn['text'];
			$lines[] = '';
		}
		$home = $this->rootFolder->getUserFolder($userId);
		$folder = $home->nodeExists('AI conversations') ? $home->get('AI conversations') : $home->newFolder('AI conversations');
		$name = trim(preg_replace('#[\\\\/:*?"<>|\x00-\x1f]+#u', ' ', date('Y-m-d H-i') . ' ' . mb_substr($title, 0, 60)) ?? '') . '.md';
		/** @var \OCP\Files\Folder $folder */
		$file = $folder->nodeExists($name) ? $folder->get($name) : $folder->newFile($name);
		$file->putContent(implode("\n", $lines));
		return $file->getPath();
	}

	/** Conversations unused for longer than they are kept. */
	public function clean(): void {
		foreach ($this->appConfig->getKeys(Engines::APP) as $key) {
			if (str_starts_with($key, 'ai_conv_')) {
				$stored = json_decode($this->appConfig->getValueString(Engines::APP, $key, '{}', lazy: true), true);
				if (!is_array($stored) || $this->lapsed($stored)) {
					$this->appConfig->deleteKey(Engines::APP, $key);
				}
			}
		}
	}

	private function load(string $userId, string $token): ?array {
		$stored = json_decode($this->appConfig->getValueString(Engines::APP, self::key($userId, $token), '', lazy: true), true);
		if (!is_array($stored) || $this->lapsed($stored)) {
			return null;
		}
		return $stored;
	}

	private function lapsed(array $stored): bool {
		$hours = max(1, $this->appConfig->getValueInt(Engines::APP, 'ai_conversation_hours', 12, lazy: true));
		return time() - (int)($stored['updated'] ?? 0) > $hours * 3600;
	}

	/** The person and the token hashed: a token is the caller's, any text, and keys are short. */
	private static function key(string $userId, string $token): string {
		return 'ai_conv_' . substr(hash('sha256', $userId . "\n" . $token), 0, 40);
	}
}
