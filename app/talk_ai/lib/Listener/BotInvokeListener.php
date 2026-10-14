<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Listener;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Dispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Talk's call for each message in a conversation where the bot is enabled
 * (BotInvokeEvent), while the message is still being posted: nothing slow
 * happens here. A message the bot answers gets 👀 at once and goes to the
 * Dispatcher. It answers people only, never bots, and in a conversation with one
 * person every message; with more, a message that names it ("@AI …", "AI, …") or
 * replies to it.
 *
 * @template-implements IEventListener<Event>
 */
class BotInvokeListener implements IEventListener {
	public function __construct(
		private Dispatcher $dispatcher,
		private Conversation $conversation,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getBotUrl') || $event->getBotUrl() !== Application::BOT_URL) {
			return;
		}
		$activity = $event->getMessage();
		$object = $activity['object'] ?? [];
		$actorId = (string)($activity['actor']['id'] ?? '');
		if (($activity['type'] ?? '') !== 'Create' || !is_array($object) || ($object['name'] ?? '') !== 'message'
			|| !str_starts_with($actorId, 'users/')) {
			return;
		}
		$userId = substr($actorId, strlen('users/'));
		$token = (string)($activity['target']['id'] ?? '');
		$messageId = (int)($object['id'] ?? 0);
		$text = self::plainText((string)($object['content'] ?? ''));
		if ($token === '' || $userId === '' || $messageId === 0 || trim($text) === '') {
			return;
		}
		// Nothing may escape into Talk's posting of the message.
		try {
			$repliesToBot = str_starts_with((string)($activity['inReplyTo']['actor']['id'] ?? ''), 'bots/');
			if (!$repliesToBot && !self::names($text, $this->name()) && $this->conversation->numberOfPeople($token) > 1) {
				return;
			}
			$event->addReaction('👀');
			$this->dispatcher->dispatch($token, $userId, $messageId, $text);
		} catch (\Throwable $e) {
			$this->logger->error('AI bot: the message could not be handed on: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	private function name(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'name', Application::DEFAULT_NAME) ?: Application::DEFAULT_NAME;
	}

	/** Whether the text addresses the bot by name: "@AI …", or "AI, …" / "AI: …" at its start. */
	public static function names(string $text, string $name): bool {
		$quoted = preg_quote($name, '/');
		return preg_match('/(^|\s)@"?' . $quoted . '"?(?![\p{L}\p{N}])|^\s*' . $quoted . '\s*[,:]/iu', $text) === 1;
	}

	/** The message's text: Talk sends a JSON document with the text and its mentions ({mention-user1}). */
	public static function plainText(string $content): string {
		$decoded = json_decode($content, true);
		if (!is_array($decoded) || !is_string($decoded['message'] ?? null)) {
			return $content;
		}
		$parameters = is_array($decoded['parameters'] ?? null) ? $decoded['parameters'] : [];
		return preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($parameters): string {
			$parameter = $parameters[trim($m[1])] ?? null;
			return is_array($parameter) && is_string($parameter['name'] ?? null) ? '@' . $parameter['name'] : $m[0];
		}, $decoded['message']) ?? $decoded['message'];
	}
}
