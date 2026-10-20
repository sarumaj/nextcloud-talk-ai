<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Matrix;

use OCA\TalkAi\Service\Conversation;

/**
 * What Talk's BotInvokeEvent offers the listener for a message in a Matrix room: a quick
 * reaction to it (👀, ✋) and a short answer (the bot switched off), both carried out in
 * Matrix by the adapter.
 */
class Event {
	public function __construct(
		private Store $store,
		private Conversation $conversation,
		private Room $room,
		private Message $message,
	) {
	}

	public function addReaction(string $emoji): void {
		$this->store->push($this->room->getToken(), 'react', ['message_id' => $this->message->id, 'event_id' => $this->message->eventId, 'key' => $emoji]);
	}

	public function addAnswer(string $message, bool|int $reply = false): void {
		$this->conversation->postText($this->room, $message, $reply === false ? 0 : $this->message->id);
	}
}
