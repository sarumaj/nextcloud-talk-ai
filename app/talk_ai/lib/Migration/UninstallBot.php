<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Migration;

use OCA\TalkAi\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Removes the bot from Talk when the app is removed. */
class UninstallBot implements IRepairStep {
	public function __construct(
		private IEventDispatcher $dispatcher,
		private IAppConfig $appConfig,
	) {
	}

	public function getName(): string {
		return 'Remove the AI bot from Talk';
	}

	public function run(IOutput $output): void {
		$class = 'OCA\Talk\Events\BotUninstallEvent';
		if (!class_exists($class)) {
			return;
		}
		$secret = $this->appConfig->getValueString(Application::APP_ID, 'secret', '', lazy: true);
		$this->dispatcher->dispatchTyped(new $class($secret, Application::BOT_URL));
	}
}
