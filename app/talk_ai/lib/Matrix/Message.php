<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Matrix;

/**
 * A message of a Matrix room where Talk's comment (IComment) is expected: the bot keeps
 * the ones it posts to edit or remove them later, and looks one up by its ID.
 */
class Message {
	/**
	 * @param list<array{id: int, name: string, mime: string, size: int}> $files
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $token,
		public readonly string $eventId,
		public readonly string $userId,
		public readonly string $actor,
		public readonly bool $bot,
		public readonly string $text,
		public readonly array $files,
		public readonly int $replyTo,
		public readonly int $time,
		public readonly bool $deleted,
	) {
	}

	public function getId(): string {
		return (string)$this->id;
	}

	public function getParentId(): string {
		return (string)$this->replyTo;
	}

	public function getMessage(): string {
		return $this->text;
	}

	public function getActorType(): string {
		return $this->bot ? 'bots' : 'users';
	}

	public function getActorId(): string {
		return $this->bot ? 'bot' : $this->userId;
	}

	public function getVerb(): string {
		return $this->deleted ? 'comment_deleted' : 'comment';
	}

	public function getCreationDateTime(): \DateTime {
		return (new \DateTime())->setTimestamp($this->time);
	}
}
