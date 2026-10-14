<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\AppInfo;

use OCA\TalkAi\Listener\BotInvokeListener;
use OCA\TalkAi\Listener\QuietFolderShareListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Share\Events\ShareCreatedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'talk_ai';
	/** The bot's address for Talk: an app bot, called in-process (BotInvokeEvent). */
	public const BOT_URL = 'nextcloudapp://' . self::APP_ID;
	/** The name the bot has unless the app setting "name" says otherwise (set before installing). */
	public const DEFAULT_NAME = 'AI';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Talk's classes by name: the app installs without Talk.
		$context->registerEventListener('OCA\Talk\Events\BotInvokeEvent', BotInvokeListener::class);
		// Before Talk's own listeners (priority 0): no "shared a folder" for the folder share a sent file needs.
		$context->registerEventListener(ShareCreatedEvent::class, QuietFolderShareListener::class, 100);
		$context->registerEventListener('OCA\Talk\Events\BeforeDuplicateShareSentEvent', QuietFolderShareListener::class, 100);
	}

	public function boot(IBootContext $context): void {
	}

	/** The id Talk gives the bot's messages: "bot-" and the SHA-1 of its address. */
	public static function botActorId(): string {
		return 'bot-' . sha1(self::BOT_URL);
	}
}
