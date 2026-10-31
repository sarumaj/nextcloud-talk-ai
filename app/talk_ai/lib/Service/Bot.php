<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use OCP\Server;

/**
 * The bot in Talk and its name. The app setting "name" fixes the name; without
 * it the bot is named after the AI that answers (the engine's provider and model:
 * Claude, Gemini, ChatGPT, …), and renamed when that changes. Talk renames
 * a bot when it is installed again with the same address and secret.
 */
class Bot {
	/** Talk's bot features: events in-process (4: BotInvokeEvent, with its reactions and answers) and reactions (8). */
	private const FEATURES = 4 | 8;
	private const DESCRIPTION = 'Answers with AI: reads the files shared here, writes documents, slides, spreadsheets and flash cards, does maths and searches the web.';

	/** Names by provider, and for OpenAI-compatible servers by the model's family. */
	private const PROVIDERS = ['claude' => 'Claude', 'anthropic' => 'Claude', 'gemini' => 'Gemini', 'google' => 'Gemini'];
	private const MODELS = ['gpt' => 'ChatGPT', 'o1' => 'ChatGPT', 'o3' => 'ChatGPT', 'o4' => 'ChatGPT', 'claude' => 'Claude', 'gemini' => 'Gemini',
		'gemma' => 'Gemma', 'llama' => 'Llama', 'mistral' => 'Mistral', 'mixtral' => 'Mistral', 'qwen' => 'Qwen', 'deepseek' => 'DeepSeek', 'phi' => 'Phi'];

	public function __construct(
		private IEventDispatcher $dispatcher,
		private IAppConfig $appConfig,
		private ISecureRandom $random,
		private Hub $hub,
	) {
	}

	/** The name the bot has in Talk now. */
	public function name(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'installed_name', '')
			?: $this->wanted();
	}

	/** The name the bot should have: the setting, else after the AI that answers. */
	public function wanted(?Status $status = null): string {
		$fixed = trim($this->appConfig->getValueString(Application::APP_ID, 'name', ''));
		if ($fixed !== '') {
			return $fixed;
		}
		$status ??= $this->hub->status();
		return self::nameFor($status->provider, $status->model);
	}

	public static function nameFor(string $provider, string $model): string {
		if (isset(self::PROVIDERS[$provider])) {
			return self::PROVIDERS[$provider];
		}
		$family = strtolower(preg_replace('#^.*/#', '', $model) ?? $model);
		foreach (self::MODELS as $prefix => $name) {
			if (str_starts_with($family, $prefix)) {
				return $name;
			}
		}
		return Application::DEFAULT_NAME;
	}

	/** Installs the bot in Talk, or renames it; false without Talk. */
	public function install(?Status $status = null): bool {
		$class = 'OCA\Talk\Events\BotInstallEvent';
		if (!class_exists($class)) {
			return false;
		}
		$secret = $this->appConfig->getValueString(Application::APP_ID, 'secret', '', lazy: true);
		if ($secret === '') {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, 'secret', $secret, lazy: true, sensitive: true);
		}
		$name = $this->wanted($status);
		$this->dispatcher->dispatchTyped(new $class($name, $secret, Application::BOT_URL, self::DESCRIPTION, self::FEATURES));
		$this->appConfig->setValueString(Application::APP_ID, 'installed_name', $name);
		$this->ensureFeatures();
		return true;
	}

	/**
	 * Talk keeps the features of a bot installed before (it updates only name and
	 * description), so newer features (reactions) are set here, as occ talk:bot:state does.
	 */
	private function ensureFeatures(): void {
		try {
			$mapper = Server::get('OCA\Talk\Model\BotServerMapper');
			$bot = $mapper->findByUrl(Application::BOT_URL);
			if (($bot->getFeatures() & self::FEATURES) !== self::FEATURES) {
				$bot->setFeatures($bot->getFeatures() | self::FEATURES);
				$mapper->update($bot);
			}
		} catch (\Throwable) {
			// Without Talk, or not installed yet: Talk sets them when it installs the bot.
		}
	}

	/** Renames the bot when the wanted name changed (a new setting, another AI). */
	public function sync(?Status $status = null): void {
		$installed = $this->appConfig->getValueString(Application::APP_ID, 'installed_name', '');
		if ($installed !== '' && $installed !== $this->wanted($status)) {
			$this->install($status);
		}
	}
}
