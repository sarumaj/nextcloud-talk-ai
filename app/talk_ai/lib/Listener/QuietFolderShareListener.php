<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\IShare;

/**
 * Keeps Talk from announcing the one share this app creates: when the bot sends
 * the first file of the asking person in a conversation, that person's folder
 * of the conversation is shared with it (by Talk's own ConversationFolderService,
 * as for an attachment), and Talk would post "shared a folder" in the chat. This
 * listener runs before Talk's and stops ShareCreatedEvent (after the share is
 * made) and Talk's BeforeDuplicateShareSentEvent, only for the conversation
 * Service\Conversation names while it creates that share.
 *
 * @template-implements IEventListener<Event>
 */
class QuietFolderShareListener implements IEventListener {
	/** The conversation's token while its folder share is created, else null. */
	public static ?string $quietFor = null;

	public function handle(Event $event): void {
		if (self::$quietFor === null || !method_exists($event, 'getShare')) {
			return;
		}
		$share = $event->getShare();
		if ($share instanceof IShare && $share->getShareType() === IShare::TYPE_ROOM && $share->getSharedWith() === self::$quietFor) {
			$event->stopPropagation();
		}
	}
}
