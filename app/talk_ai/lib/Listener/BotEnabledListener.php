<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Listener;

use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Service\Bot;
use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Texts;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * When a moderator enables the bot in a conversation (BotEnabledEvent): a notice
 * that an AI answers here, which provider processes the messages and where
 * (transparency_text, else one made from the engine's provider and model and
 * the app's provider_location).
 *
 * @template-implements IEventListener<Event>
 */
class BotEnabledListener implements IEventListener {
	public function __construct(
		private Conversation $conversation,
		private Bot $bot,
		private IAppConfig $appConfig,
		private Texts $texts,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getBotServer') || $event->getBotServer()->getUrl() !== Application::BOT_URL) {
			return;
		}
		try {
			$this->conversation->postText($event->getRoom(), $this->notice(), 0);
		} catch (\Throwable $e) {
			$this->logger->warning('AI bot: no notice when enabled: ' . $e->getMessage());
		}
	}

	public function notice(): string {
		$custom = trim($this->appConfig->getValueString(Application::APP_ID, 'transparency_text', ''));
		if ($custom !== '') {
			return 'ℹ️ ' . $custom;
		}
		$provider = $this->texts->t('', 'the AI provider set up by the administrator');
		try {
			$status = \OCP\Server::get(Hub::class)->status();
			if ($status->provider !== '') {
				$provider = trim($status->provider . ' (' . $status->model . ')');
			}
		} catch (\Throwable) {
		}
		$where = trim($this->appConfig->getValueString(Application::APP_ID, 'provider_location', ''));
		return 'ℹ️ ' . $this->texts->t('', '%1$s is an AI assistant. Messages that address it, with the files and images it reads for the answer, are processed by %2$s. It answers with the rights of the person asking. Write "@%1$s help" to see what it can do.',
			[$this->bot->name(), $provider . ($where !== '' ? ' (' . $where . ')' : '')]);
	}
}
