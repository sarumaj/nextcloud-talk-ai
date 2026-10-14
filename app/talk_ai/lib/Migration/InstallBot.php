<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Migration;

use OCA\TalkAi\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Security\ISecureRandom;

/**
 * Installs the bot in Talk (an app bot: Talk calls it in-process), named after
 * the app setting "name". Its secret signs the hand-off of a question to the
 * process route. Without Talk nothing happens; installing again updates the bot.
 */
class InstallBot implements IRepairStep {
	/** Talk's bot features: 4, events in-process (BotInvokeEvent), with their reactions and answers. */
	private const FEATURES = 4;

	public function __construct(
		private IEventDispatcher $dispatcher,
		private IAppConfig $appConfig,
		private ISecureRandom $random,
	) {
	}

	public function getName(): string {
		return 'Install the AI bot in Talk';
	}

	public function run(IOutput $output): void {
		$class = 'OCA\Talk\Events\BotInstallEvent';
		if (!class_exists($class)) {
			$output->info('Talk is not installed: the bot is installed with Talk.');
			return;
		}
		$secret = $this->appConfig->getValueString(Application::APP_ID, 'secret', '', lazy: true);
		if ($secret === '') {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, 'secret', $secret, lazy: true, sensitive: true);
		}
		$name = $this->appConfig->getValueString(Application::APP_ID, 'name', Application::DEFAULT_NAME) ?: Application::DEFAULT_NAME;
		$this->dispatcher->dispatchTyped(new $class(
			$name,
			$secret,
			Application::BOT_URL,
			'Answers with AI: reads the files shared here, searches the web and sends files.',
			self::FEATURES,
		));
	}
}
