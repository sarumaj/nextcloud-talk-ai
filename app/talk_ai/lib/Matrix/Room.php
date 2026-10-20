<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Matrix;

/**
 * A Matrix room where Talk's room object is expected: the bot uses only its token and
 * name (and an ID, which Matrix rooms do not need, for Talk's polls).
 */
class Room {
	public function __construct(
		private string $token,
		private string $roomId,
		private string $name,
		private int $people,
	) {
	}

	public function getToken(): string {
		return $this->token;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getId(): int {
		return 0;
	}

	public function roomId(): string {
		return $this->roomId;
	}

	public function people(): int {
		return $this->people;
	}
}
