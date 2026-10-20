<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Listener\QuietFolderShareListener;
use OCA\TalkAi\Matrix\Message as MatrixMessage;
use OCA\TalkAi\Matrix\Room as MatrixRoom;
use OCA\TalkAi\Matrix\Store as MatrixStore;
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
 *
 * A Matrix room (a token "mx-…", MatrixStore) takes the same calls: its messages come
 * from the Matrix adapter, and what the bot posts, edits or removes there goes to the
 * adapter's outbox, which it carries out in Matrix. Files the bot makes for a Matrix room
 * are kept in the person's folder "Matrix/<room>", where the adapter also puts what
 * people send there.
 */
class Conversation {
	/** How many recent messages the bot reads. */
	/** Messages read per answer when not set otherwise (history_window). */
	public const HISTORY = 30;

	public function __construct(
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
		private ?MatrixStore $matrix = null,
		private ?\OCP\IAppConfig $appConfig = null,
	) {
	}

	private function appConfig(): \OCP\IAppConfig {
		return $this->appConfig ??= Server::get(\OCP\IAppConfig::class);
	}

	public function room(string $token): object {
		if (MatrixStore::isMatrix($token)) {
			return $this->matrix?->room($token) ?? throw new \RuntimeException('no Matrix room ' . $token);
		}
		return Server::get('OCA\Talk\Manager')->getRoomByToken($token);
	}

	/** How many people (not bots) take part. */
	public function numberOfPeople(string $token): int {
		if (MatrixStore::isMatrix($token)) {
			return $this->matrix?->room($token)?->people() ?? 0;
		}
		return Server::get('OCA\Talk\Service\ParticipantService')->getNumberOfUsers($this->room($token));
	}

	/** Whether the person may change the conversation's settings (a moderator). */
	public function isModerator(object $room, string $userId): bool {
		if ($room instanceof MatrixRoom) {
			return $this->matrix?->isModerator($room->getToken(), $userId) ?? false;
		}
		try {
			return Server::get('OCA\Talk\Service\ParticipantService')->getParticipant($room, $userId)->hasModeratorPermissions();
		} catch (\Throwable) {
			return false;
		}
	}

	/** A poll in the conversation, posted by the bot (named $botName). */
	public function postPoll(object $room, string $botName, string $question, array $options, bool $hidden, int $maxVotes): void {
		if ($room instanceof MatrixRoom) {
			$this->matrix?->push($room->getToken(), 'poll', ['question' => $question, 'options' => array_values($options),
				'hidden' => $hidden, 'max_votes' => $maxVotes]);
			return;
		}
		$poll = Server::get('OCA\Talk\Service\PollService')->createPoll($room->getId(), 'bots', Application::botActorId(), $botName,
			$question, $options, $hidden ? 1 : 0, $maxVotes, false);
		Server::get('OCA\Talk\Chat\ChatManager')->addSystemMessage($room, null, 'bots', Application::botActorId(),
			json_encode(['message' => 'object_shared', 'parameters' => ['objectType' => 'talk-poll', 'objectId' => (string)$poll->getId(),
				'metaData' => ['type' => 'talk-poll', 'id' => (string)$poll->getId(), 'name' => $poll->getQuestion()]]], JSON_THROW_ON_ERROR),
			new \DateTime(), true);
	}

	/**
	 * The recent messages, oldest first, as the person sees them:
	 * [{id, actor (name), bot (bool), text, files: [{id, name, mime, size}], replyTo}].
	 *
	 * @return list<array{id: int, actor: string, time: string, bot: bool, text: string, files: list<array{id: int, name: string, mime: string, size: int}>}>
	 */
	public function history(object $room, string $userId, int $limit = self::HISTORY): array {
		$from = $this->contextStart($room->getToken())['after'];
		if ($room instanceof MatrixRoom) {
			return array_values(array_filter($this->matrixHistory($room, $limit), fn (array $m) => $m['id'] > $from));
		}
		$chat = Server::get('OCA\Talk\Chat\ChatManager');
		$parser = Server::get('OCA\Talk\Chat\MessageParser');
		$participant = Server::get('OCA\Talk\Service\ParticipantService')->getParticipant($room, $userId);
		$l = $this->l10nFactory->get('spreed', $this->l10nFactory->getUserLanguage(Server::get(\OCP\IUserManager::class)->get($userId)));
		$messages = [];
		foreach ($chat->getHistory($room, 0, $limit, false) as $comment) {
			if ((int)$comment->getId() <= $from) {
				break; // newest first: the rest is from before the new chat
			}
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
				'replyTo' => (int)$comment->getParentId(),
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
	/** @return object the posted comment (IComment), to edit it later */
	public function postText(object $room, string $text, int $replyTo): object {
		if ($room instanceof MatrixRoom) {
			$message = $this->matrixStore()->addMessage($room->getToken(), '', '', $this->botName(), true, $text, [], $replyTo);
			$this->matrixStore()->push($room->getToken(), 'text', ['message_id' => $message->id, 'text' => $text] + $this->replyOf($room, $replyTo));
			return $message;
		}
		$chat = Server::get('OCA\Talk\Chat\ChatManager');
		$parent = $replyTo > 0 ? $this->comment($room, $replyTo) : null;
		return $chat->sendMessage($room, null, 'bots', Application::botActorId(), $text, new \DateTime(), $parent, '', false, false);
	}

	/**
	 * The message a system message is about (an edit or a deletion: its "message"
	 * parameter), with its author and current text; null if gone.
	 *
	 * @return array{id: int, author: string, text: string, time: int}|null
	 */
	public function editedMessage(object $room, int $systemMessageId): ?array {
		$system = $this->comment($room, $systemMessageId);
		$data = $system === null ? null : json_decode($system->getMessage(), true);
		$id = (int)($data['parameters']['message'] ?? 0);
		$target = $id > 0 ? $this->comment($room, $id) : null;
		if ($target === null) {
			return $id > 0 ? ['id' => $id, 'author' => '', 'text' => '', 'time' => 0] : null;
		}
		return ['id' => $id, 'author' => $target->getActorType() . '/' . $target->getActorId(), 'text' => $target->getMessage(),
			'time' => $target->getCreationDateTime()->getTimestamp()];
	}

	/** Removes what the bot posted in reply to a message (its answer, pictures, files); how many. */
	public function removeBotReplies(object $room, int $messageId): int {
		if ($room instanceof MatrixRoom) {
			$replies = $this->matrixStore()->botReplies($room->getToken(), $messageId);
			foreach ($replies as $reply) {
				$this->removeMessage($room, $reply);
			}
			return count($replies);
		}
		$chat = Server::get('OCA\Talk\Chat\ChatManager');
		$removed = 0;
		foreach ($chat->getHistory($room, 0, 100, false) as $comment) {
			if ($comment->getActorType() === 'bots' && $comment->getActorId() === Application::botActorId()
				&& (int)$comment->getParentId() === $messageId && $comment->getVerb() !== 'comment_deleted') {
				try {
					$chat->deleteMessage($room, $comment, $this->botParticipant($room), new \DateTime());
					$removed++;
				} catch (\Throwable $e) {
					$this->logger->info('AI bot: an answer could not be removed: ' . $e->getMessage());
				}
			}
		}
		return $removed;
	}

	/** Removes one of the bot's messages (a status that is no longer needed). */
	public function removeMessage(object $room, object $comment): void {
		if ($room instanceof MatrixRoom) {
			$this->matrixStore()->markDeleted($room->getToken(), (int)$comment->getId());
			$this->matrixStore()->push($room->getToken(), 'redact', ['message_id' => (int)$comment->getId(),
				'event_id' => $comment instanceof MatrixMessage ? $comment->eventId : '']);
			return;
		}
		try {
			Server::get('OCA\Talk\Chat\ChatManager')->deleteMessage($room, $comment, $this->botParticipant($room), new \DateTime());
		} catch (\Throwable $e) {
			$this->logger->info('AI bot: a status message could not be removed: ' . $e->getMessage());
		}
	}

	private function botParticipant(object $room): object {
		$attendee = new \OCA\Talk\Model\Attendee();
		$attendee->setActorType('bots');
		$attendee->setActorId(Application::botActorId());
		$attendee->setRoomId($room->getId());
		return new \OCA\Talk\Participant($room, $attendee, null);
	}

	/** Changes a message the bot posted (a status that moves on); false when Talk refuses. */
	public function editText(object $room, object $comment, string $text): bool {
		if ($room instanceof MatrixRoom) {
			$this->matrixStore()->setText($room->getToken(), (int)$comment->getId(), $text);
			$this->matrixStore()->push($room->getToken(), 'edit', ['message_id' => (int)$comment->getId(), 'text' => $text]);
			return true;
		}
		try {
			$attendee = new \OCA\Talk\Model\Attendee();
			$attendee->setActorType('bots');
			$attendee->setActorId(Application::botActorId());
			$attendee->setRoomId($room->getId());
			$participant = new \OCA\Talk\Participant($room, $attendee, null);
			Server::get('OCA\Talk\Chat\ChatManager')->editMessage($room, $comment, $participant, new \DateTime(), $text);
			return true;
		} catch (\Throwable $e) {
			$this->logger->info('AI bot: a status message could not be edited: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * A finished file in the person's folder (a path in their files, created when
	 * missing; they must be allowed to write there), shared into the conversation
	 * without Talk's own "shared a file" message, so the bot can post it.
	 */
	public function deliver(object $room, string $userId, string $folderPath, string $name, string $data): File {
		$folder = $this->rootFolder->getUserFolder($userId);
		foreach (array_filter(explode('/', trim($folderPath, '/')), fn ($p) => $p !== '' && $p !== '.' && $p !== '..') as $part) {
			$next = $folder->nodeExists($part) ? $folder->get($part) : $folder->newFolder($part);
			if (!$next instanceof Folder) {
				throw new \RuntimeException('"' . $part . '" is a file, not a folder');
			}
			$folder = $next;
		}
		if (!$folder->isCreatable()) {
			throw new \RuntimeException('you may not write to the folder ' . $folderPath);
		}
		$file = $folder->newFile($this->freeName($folder, $name), $data);
		if (!$room instanceof MatrixRoom) {
			$this->share($room, $userId, $file);
		}
		return $file;
	}

	/**
	 * Shares a file of the person into the conversation, without Talk's "shared a file"
	 * message: to read, and to share on, so its members can forward the bot's message (Talk
	 * shares the file again into the other conversation, as the one who forwards it); nobody
	 * can change it through the conversation.
	 */
	public function share(object $room, string $userId, File $file): void {
		if ($room instanceof MatrixRoom) {
			return; // the adapter posts the file itself, read with the person's own rights
		}
		QuietFolderShareListener::$quietFor = $room->getToken();
		try {
			$share = $this->shareManager->newShare();
			$share->setNode($file)->setShareType(\OCP\Share\IShare::TYPE_ROOM)->setSharedWith($room->getToken())
				->setSharedBy($userId)->setShareOwner($file->getOwner()?->getUID() ?? $userId)->setPermissions(\OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_SHARE);
			$this->shareManager->createShare($share);
		} finally {
			QuietFolderShareListener::$quietFor = null;
		}
	}

	/**
	 * Where the bot's memory of the conversation starts (a "new chat"): the messages after
	 * message `after` (0: all), since `time`. The chat itself keeps everything.
	 *
	 * @return array{after: int, time: int}
	 */
	public function contextStart(string $token): array {
		$start = json_decode($this->appConfig()->getValueString(Application::APP_ID, 'context_' . $token, '{}', lazy: true), true);
		return ['after' => (int)($start['after'] ?? 0), 'time' => (int)($start['time'] ?? 0)];
	}

	/** Starts a new chat after this message: the bot forgets the messages up to it and the conversation's summary. */
	public function newChat(object $room, int $after): void {
		$config = $this->appConfig();
		$config->setValueString(Application::APP_ID, 'context_' . $room->getToken(), json_encode(['after' => $after, 'time' => time()]), lazy: true);
		$config->deleteKey(Application::APP_ID, 'memory_' . $room->getToken());
	}

	/** Whether document jobs in this conversation show a preview before delivering (on unless turned off). */
	public function previewsOn(string $token): bool {
		return Server::get(\OCP\IAppConfig::class)->getValueString(Application::APP_ID, 'preview_off_' . $token, '', lazy: true) === '';
	}

	public function setPreviews(string $token, bool $on): void {
		$config = Server::get(\OCP\IAppConfig::class);
		if ($on) {
			$config->deleteKey(Application::APP_ID, 'preview_off_' . $token);
		} else {
			$config->setValueString(Application::APP_ID, 'preview_off_' . $token, '1', lazy: true);
		}
	}

	/**
	 * Saves a file in the asking person's folder of the conversation (shared with
	 * it; made like for a first attachment when missing, without Talk's "shared a
	 * folder"), under the name or "name (2)" when that is taken.
	 */
	public function saveFile(object $room, string $userId, string $name, string $data): File {
		if ($room instanceof MatrixRoom) {
			return $this->deliver($room, $userId, self::matrixFolder($room), $name, $data);
		}
		$config = Server::get('OCA\Talk\Config');
		if (!method_exists($config, 'isConversationSubfoldersEnabled')) {
			// Talk before conversation subfolders (Nextcloud 32, 33): the attachment folder, the file shared on its own.
			return $this->deliver($room, $userId, ltrim($config->getAttachmentFolder($userId), '/'), $name, $data);
		}
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

	/** Posts a saved file as the bot's message: Talk's file message by file id, as Talk 25's attachments are; the message. */
	public function postFile(object $room, File $file, int $replyTo): ?object {
		if ($room instanceof MatrixRoom) {
			$owner = $file->getOwner()?->getUID() ?? '';
			$entry = ['id' => $file->getId(), 'name' => $file->getName(), 'mime' => $file->getMimetype(), 'size' => $file->getSize()];
			$message = $this->matrixStore()->addMessage($room->getToken(), '', '', $this->botName(), true, '{file}', [$entry], $replyTo);
			$path = $owner === '' ? null : $this->rootFolder->getUserFolder($owner)->getRelativePath($file->getPath());
			$this->matrixStore()->push($room->getToken(), 'file', ['message_id' => $message->id, 'file_id' => $file->getId(), 'owner' => $owner,
				'path' => (string)$path, 'name' => $file->getName(), 'mime' => $file->getMimetype(), 'size' => $file->getSize()] + $this->replyOf($room, $replyTo));
			return $message;
		}
		$chat = Server::get('OCA\Talk\Chat\ChatManager');
		$parent = $replyTo > 0 ? $this->comment($room, $replyTo) : null;
		return $chat->addSystemMessage(
			$room, null, 'bots', Application::botActorId(),
			json_encode(['message' => 'file_shared', 'parameters' => ['fileId' => (string)$file->getId(), 'metaData' => ['mimeType' => $file->getMimetype()]]], JSON_THROW_ON_ERROR),
			new \DateTime(), true, null, $parent,
		);
	}

	public function comment(object $room, int $id): ?object {
		if ($room instanceof MatrixRoom) {
			return $this->matrixStore()->message($room->getToken(), $id);
		}
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

	/**
	 * The person's files changed in the last days, newest first; a part of the name
	 * narrows them.
	 *
	 * @return list<array{id: int, path: string, mime: string, size: int, modified: string}>
	 */
	public function recentFiles(string $userId, int $days, string $name = '', int $limit = 30): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$since = time() - max(1, $days) * 86400;
		$found = [];
		foreach ($userFolder->getRecent(200) as $node) {
			if (!$node instanceof File || $node->getMTime() < $since || ($name !== '' && mb_stripos($node->getName(), $name) === false)) {
				continue;
			}
			$found[] = ['id' => $node->getId(), 'path' => $userFolder->getRelativePath($node->getPath()) ?? $node->getName(), 'mime' => $node->getMimetype(),
				'size' => $node->getSize(), 'modified' => date('Y-m-d H:i', $node->getMTime())];
			if (count($found) >= $limit) {
				break;
			}
		}
		return $found;
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

	/** The folder of a Matrix room in the person's files: "Matrix/<room>", where the adapter also puts what people send. */
	public static function matrixFolder(object $room): string {
		$name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', ' ', $room->getName()) ?? '');
		return 'Matrix/' . ($name !== '' ? mb_substr($name, 0, 100) : 'Chat');
	}

	/**
	 * A Matrix room's recent messages, oldest first, as history() gives Talk's.
	 *
	 * @return list<array{id: int, actor: string, time: string, bot: bool, text: string, files: list<array{id: int, name: string, mime: string, size: int}>, replyTo: int}>
	 */
	private function matrixHistory(MatrixRoom $room, int $limit): array {
		$messages = [];
		foreach ($this->matrixStore()->recent($room->getToken(), $limit) as $m) {
			$messages[] = ['id' => $m->id, 'actor' => $m->actor, 'time' => date('Y-m-d H:i', $m->time), 'bot' => $m->bot,
				'text' => $m->text, 'files' => $m->files, 'replyTo' => $m->replyTo];
		}
		return array_reverse($messages);
	}

	/** What a Matrix reply answers: the message's ID and its event (once it has one). */
	private function replyOf(MatrixRoom $room, int $replyTo): array {
		$parent = $replyTo > 0 ? $this->matrixStore()->message($room->getToken(), $replyTo) : null;
		return ['reply_to' => $replyTo, 'reply_event' => $parent?->eventId ?? ''];
	}

	private function matrixStore(): MatrixStore {
		return $this->matrix ?? throw new \RuntimeException('Matrix is not available');
	}

	/** The bot's name in Matrix histories (its messages there are stored under it). */
	private function botName(): string {
		try {
			return Server::get(Bot::class)->name();
		} catch (\Throwable) {
			return 'AI';
		}
	}
}
