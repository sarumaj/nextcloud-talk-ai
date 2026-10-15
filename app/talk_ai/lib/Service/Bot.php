<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use OCP\Server;

/**
 * The bot in Talk and its name. The app setting "name" fixes the name; without
 * it the bot is named after the AI that answers, as AI-Hub is set up for this
 * app (Claude, Gemini, ChatGPT, …), and renamed when that changes. Talk renames
 * a bot when it is installed again with the same address and secret.
 */
class Bot {
	/** Talk's bot features: 4, events in-process (BotInvokeEvent), with their reactions and answers. */
	private const FEATURES = 4;
	private const DESCRIPTION = 'Answers with AI: reads the files shared here, writes documents, slides, spreadsheets and flash cards, does maths and searches the web.';

	/** Names by AI-Hub provider, and for OpenAI-compatible servers by the model's family. */
	private const PROVIDERS = ['claude' => 'Claude', 'gemini' => 'Gemini'];
	private const MODELS = ['gpt' => 'ChatGPT', 'o1' => 'ChatGPT', 'o3' => 'ChatGPT', 'o4' => 'ChatGPT', 'claude' => 'Claude', 'gemini' => 'Gemini',
		'gemma' => 'Gemma', 'llama' => 'Llama', 'mistral' => 'Mistral', 'mixtral' => 'Mistral', 'qwen' => 'Qwen', 'deepseek' => 'DeepSeek', 'phi' => 'Phi'];

	public function __construct(
		private IEventDispatcher $dispatcher,
		private IAppConfig $appConfig,
		private ISecureRandom $random,
	) {
	}

	/** The name the bot has in Talk now. */
	public function name(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'installed_name', '')
			?: $this->wanted();
	}

	/** The name the bot should have: the setting, else after the AI that answers. */
	public function wanted(?array $status = null): string {
		$fixed = trim($this->appConfig->getValueString(Application::APP_ID, 'name', ''));
		if ($fixed !== '') {
			return $fixed;
		}
		$status ??= $this->status();
		return self::nameFor((string)($status['provider'] ?? ''), (string)($status['model'] ?? ''));
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
	public function install(?array $status = null): bool {
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
		return true;
	}

	/** Renames the bot when the wanted name changed (a new setting, another AI). */
	public function sync(?array $status = null): void {
		$installed = $this->appConfig->getValueString(Application::APP_ID, 'installed_name', '');
		if ($installed !== '' && $installed !== $this->wanted($status)) {
			$this->install($status);
		}
	}

	/** AI-Hub's engine for this app: provider and model; empty without AI-Hub. */
	private function status(): array {
		$class = 'OCA\AIHub\Service\HubService';
		try {
			return class_exists($class) ? Server::get($class)->status(Application::APP_ID) : [];
		} catch (\Throwable) {
			return [];
		}
	}
}
