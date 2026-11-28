<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Listener;

use OCA\TalkAi\Service\Calls;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Talk's calls (CallStartedEvent, CallEndedEvent, CallEndedForEveryoneEvent), while the
 * person joins or leaves: the bot joins a call it answers, and leaves one that ended
 * (Service\Calls). Nothing may escape into Talk's handling of the call.
 *
 * @template-implements IEventListener<Event>
 */
class CallListener implements IEventListener {
	public const STARTED = 'OCA\Talk\Events\CallStartedEvent';
	public const ENDED = ['OCA\Talk\Events\CallEndedEvent', 'OCA\Talk\Events\CallEndedForEveryoneEvent'];

	public function __construct(
		private Calls $calls,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getRoom')) {
			return;
		}
		try {
			if (is_a($event, self::STARTED)) {
				$this->calls->started($event->getRoom(), method_exists($event, 'getActor') ? $event->getActor() : null);
			} elseif (array_filter(self::ENDED, fn (string $class) => is_a($event, $class)) !== []) {
				$this->calls->ended($event->getRoom());
			}
		} catch (\Throwable $e) {
			$this->logger->error('AI bot: the call could not be handed on: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
