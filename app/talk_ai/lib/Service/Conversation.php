<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Listener\QuietFolderShareListener;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\L10N\IFactory;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * What the bot does in Talk: the conversation's recent messages with their
 * files, and the bot's own messages, as text or as a file. Talk's classes are
 * taken by name (the app installs without Talk); the parts that are not Talk's
 * public API are those of Talk 25 (ChatManager, MessageParser, the conversation
 * folders), as the bot's own messages in Talk's bot API are.
 */
class Conversation {
	/** How many recent messages the bot reads. */
	public const HISTORY = 40;

	public function __construct(
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
	}

	public function room(string $token): object {
		return Server::get('OCA\Talk\Manager')->getRoomByToken($token);
	}

	/** How many people (not bots) take part. */
	public function numberOfPeople(string $token): int {
		return Server::get('OCA\Talk\Service\ParticipantService')->getNumberOfUsers($this->room($token));
	}

	/**
	 * The recent messages, oldest first, as the person sees them:
	 * [{id, actor (name), bot (bool), text, files: [{id, name, mime, size}], replyTo}].
	 *
	 * @return list<array{id: int, actor: string, time: string, bot: bool, text: string, files: list<array{id: int, name: string, mime: string, size: int}>}>
	 */
	public function history(object $room, string $userId, int $limit = self::HISTORY): array {
		$chat = Server::get('OCA\Talk\Chat\ChatManager');
		$parser = Server::get('OCA\Talk\Chat\MessageParser');
		$participant = Server::get('OCA\Talk\Service\ParticipantService')->getParticipant($room, $userId);
		$l = $this->l10nFactory->get('spreed', $this->l10nFactory->getUserLanguage(Server::get(\OCP\IUserManager::class)->get($userId)));
		$messages = [];
		foreach ($chat->getHistory($room, 0, $limit, false) as $comment) {
			try {
				$message = $parser->createMessage($room, $participant, $comment, $l);
				$parser->parseMessage($message);
			} catch (\Throwable) {
				continue;
			}
			if (!$message->getVisibility() || in_array($message->getMessageType(), ['system', 'command', 'comment_deleted'], true)) {
				continue;
			}
			$files = [];
			$parameters = $message->getMessageParameters();
			foreach ($parameters as $parameter) {
				if (($parameter['type'] ?? '') === 'file' && isset($parameter['id'])) {
					$files[] = [
						'id' => (int)$parameter['id'],
						'name' => (string)($parameter['name'] ?? ''),
						'mime' => (string)($parameter['mimetype'] ?? ''),
						'size' => (int)($parameter['size'] ?? 0),
					];
				}
			}
			$messages[] = [
				'id' => (int)$comment->getId(),
				'actor' => $message->getActorDisplayName(),
				'time' => $comment->getCreationDateTime()->format('Y-m-d H:i'),
				'bot' => $message->getActorType() === 'bots',
				'text' => self::render($message->getMessage(), $parameters),
				'files' => $files,
			];
		}
		return array_reverse($messages);
	}

	/** A message's text with its {placeholders} written out: names, and files as [file "name"]. */
	public static function render(string $text, array $parameters): string {
		return preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($parameters): string {
			$parameter = $parameters[$m[1]] ?? null;
			if (!is_array($parameter)) {
				return $m[0];
			}
			if (($parameter['type'] ?? '') === 'file') {
				return '[file "' . ($parameter['name'] ?? '') . '", id ' . ($parameter['id'] ?? '?') . ']';
			}
			return (($parameter['type'] ?? '') === 'user' ? '@' : '') . (string)($parameter['name'] ?? $m[0]);
		}, $text) ?? $text;
	}

	/** Posts the bot's text (Markdown) in reply to a message. */
	public function postText(object $room, string $text, int $replyTo): void {
		$chat = Server::get('OCA\Talk\Chat\ChatManager');
		$parent = $replyTo > 0 ? $this->comment($room, $replyTo) : null;
		$chat->sendMessage($room, null, 'bots', Application::botActorId(), $text, new \DateTime(), $parent, '', false, false);
	}

	/**
	 * Saves a file in the asking person's folder of the conversation (shared with
	 * it; made like for a first attachment when missing, without Talk's "shared a
	 * folder"), under the name or "name (2)" when that is taken.
	 */
	public function saveFile(object $room, string $userId, string $name, string $data): File {
		$folder = $this->sharedFolder($room, $userId);
		if ($folder === null) {
			throw new \RuntimeException('Files cannot be sent in this conversation (no conversation folder; is the storage quota full?)');
		}
		return $folder->newFile($this->freeName($folder, $name), $data);
	}

	/** The path a new file of this name would get in that folder (for a conversion's result). */
	public function freePath(File $beside, string $name): string {
		return $beside->getParent()->getFullPath($this->freeName($beside->getParent(), $name));
	}

	/** Posts a saved file as the bot's message: Talk's file message by file id, as Talk 25's attachments are. */
	public function postFile(object $room, File $file, int $replyTo): void {
		$chat = Server::get('OCA\Talk\Chat\ChatManager');
		$parent = $replyTo > 0 ? $this->comment($room, $replyTo) : null;
		$chat->addSystemMessage(
			$room, null, 'bots', Application::botActorId(),
			json_encode(['message' => 'file_shared', 'parameters' => ['fileId' => (string)$file->getId(), 'metaData' => ['mimeType' => $file->getMimetype()]]], JSON_THROW_ON_ERROR),
			new \DateTime(), true, null, $parent,
		);
	}

	private function comment(object $room, int $id): ?object {
		try {
			return Server::get('OCA\Talk\Chat\ChatManager')->getComment($room, (string)$id);
		} catch (\Throwable) {
			return null;
		}
	}

	/** The person's folder of the conversation, shared with it (made now when missing). */
	private function sharedFolder(object $room, string $userId): ?Folder {
		$config = Server::get('OCA\Talk\Config');
		if (!$config->isConversationSubfoldersEnabled()) {
			return null;
		}
		$folder = $this->existingSharedFolder($config, $room, $userId);
		if ($folder !== null) {
			return $folder;
		}
		QuietFolderShareListener::$quietFor = $room->getToken();
		try {
			Server::get('OCA\Talk\Service\ConversationFolderService')->getOrCreateSubfolder($userId, $room, false);
		} catch (\Throwable $e) {
			$this->logger->info('AI bot: no conversation folder: ' . $e->getMessage());
			return null;
		} finally {
			QuietFolderShareListener::$quietFor = null;
		}
		return $this->existingSharedFolder($config, $room, $userId);
	}

	private function existingSharedFolder(object $config, object $room, string $userId): ?Folder {
		$path = ltrim($config->getAttachmentFolder($userId), '/') . '/' . $config->getConversationFolderName($room, $userId)
			. '/' . $config->getConversationSubfolderName($userId, false);
		try {
			$folder = $this->rootFolder->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			return null;
		}
		if (!$folder instanceof Folder) {
			return null;
		}
		foreach ($this->shareManager->getSharesBy($userId, IShare::TYPE_ROOM, $folder, false, -1) as $share) {
			if ($share->getSharedWith() === $room->getToken()) {
				return $folder;
			}
		}
		return null;
	}

	/** The name, or "name (2).ext" and so on when it is taken. */
	private function freeName(Folder $folder, string $name): string {
		$name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', ' ', $name) ?? '') ?: 'file';
		if (!$folder->nodeExists($name)) {
			return $name;
		}
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
		for ($i = 2; ; $i++) {
			$candidate = $base . ' (' . $i . ')' . ($extension === '' ? '' : '.' . $extension);
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}
	}

	/** A file the person can read, by its id, or null. */
	public function userFile(string $userId, int $id): ?File {
		$node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($id);
		return $node instanceof File ? $node : null;
	}

	/**
	 * The person's files whose names contain the words, newest first.
	 *
	 * @return list<array{id: int, path: string, mime: string, size: int}>
	 */
	public function findFiles(string $userId, string $query, int $limit = 20): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$found = [];
		foreach ($userFolder->search($query) as $node) {
			if ($node instanceof File) {
				$found[] = ['id' => $node->getId(), 'path' => $userFolder->getRelativePath($node->getPath()) ?? $node->getName(), 'mime' => $node->getMimetype(), 'size' => $node->getSize(), 'mtime' => $node->getMTime()];
			}
		}
		usort($found, fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);
		return array_map(fn (array $f) => array_diff_key($f, ['mtime' => 0]), array_slice($found, 0, $limit));
	}
}
