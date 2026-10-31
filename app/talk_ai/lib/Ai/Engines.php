<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCA\TalkAi\Ai\Engine\AiHub;
use OCA\TalkAi\Ai\Engine\Missing;
use OCP\IAppConfig;
use OCP\Server;

/**
 * Builds the engine the administrator chose (app config "ai_engine"), or one by
 * name, once per request; each with its settings from Config.
 */
class Engines {
	/** The app whose settings hold the layer's: the one place to change when lib/Ai/ becomes an app. */
	public const APP = 'talk_ai';
	/** Engines over HTTP: name => class, built with (Http, settings). */
	public const HTTP = [
		'anthropic' => Engine\Anthropic::class,
		'gemini' => Engine\Gemini::class,
		'openai' => Engine\OpenAiCompatible::class,
	];

	/** @var array<string, Engine> */
	private array $built = [];
	private ?Engine $fixed = null;

	public function __construct(
		private IAppConfig $appConfig,
		private ?Config $config = null,
		private ?Http $http = null,
		private ?\OCP\TaskProcessing\IManager $taskProcessing = null,
	) {
	}

	/** Every engine there is, in the order the settings page lists them. */
	public const NAMES = ['anthropic', 'gemini', 'openai', 'claude-cli', 'gemini-cli', 'taskprocessing', 'aihub'];

	/** The name of the engine questions go to. */
	public function chosen(): string {
		return $this->appConfig->getValueString(self::APP, 'ai_engine', 'aihub');
	}

	/** @return list<string> the engines that answer, in order, when the chosen one cannot (ai_fallback) */
	public function fallback(): array {
		$chain = json_decode($this->appConfig->getValueString(self::APP, 'ai_fallback', '[]'), true);
		return array_values(array_filter(is_array($chain) ? $chain : [], fn ($n) => is_string($n) && in_array($n, self::NAMES, true)));
	}

	/** An app setting of the layer's (stored as text, as the settings page stores them). */
	public function setting(string $key, string $default): string {
		// An emptied field on the settings page means the default too.
		$value = $this->appConfig->getValueString(self::APP, $key, $default);
		return $value !== '' ? $value : $default;
	}

	/** @return array{engine?: string, model?: string, prompt?: string} the administrator's choice for a scenario (ai_scenarios) */
	public function scenario(string $name): array {
		$all = json_decode($this->appConfig->getValueString(self::APP, 'ai_scenarios', '{}'), true);
		return is_array($all[$name] ?? null) ? $all[$name] : [];
	}

	/**
	 * The engine by name, or the chosen one followed by the fallback chain; with another
	 * model where a scenario asks for one.
	 */
	public function get(?string $name = null, ?string $model = null): Engine {
		if ($this->fixed !== null) {
			return $this->fixed;
		}
		if ($name !== null) {
			return $this->one($name, $model);
		}
		$chain = array_values(array_unique([$this->chosen(), ...$this->fallback()]));
		if (count($chain) === 1) {
			return $this->one($chain[0], $model);
		}
		$engines = [];
		foreach ($chain as $i => $member) {
			// The model of a scenario's choice is the first engine's; the others keep theirs.
			$engines[$member] = $this->one($member, $i === 0 ? $model : null);
		}
		return new Engine\Fallback($engines);
	}

	/** Every question goes to this engine (tests, and `occ talk_ai:ask --engine`). */
	public function fix(Engine $engine): void {
		$this->fixed = $engine;
	}

	private function one(string $name, ?string $model): Engine {
		return $this->built[$name . '|' . $model] ??= $this->build($name, $model);
	}

	private function build(string $name, ?string $model): Engine {
		$settings = $name === 'aihub' ? [] : ($this->config ??= Server::get(Config::class))->engine($name);
		if ($model !== null && $model !== '') {
			$settings['model'] = $model;
		}
		$class = self::HTTP[$name] ?? null;
		if ($class !== null) {
			return new $class($this->http ??= Server::get(Http::class), $settings);
		}
		return match ($name) {
			'aihub' => new AiHub(self::APP),
			'claude-cli' => new Engine\ClaudeCli($settings),
			'gemini-cli' => new Engine\GeminiCli($settings),
			'taskprocessing' => new Engine\TaskProcessing($this->taskProcessing ??= Server::get(\OCP\TaskProcessing\IManager::class), $settings),
			default => new Missing(Errors::NOT_READY, $name),
		};
	}
}
