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
	) {
	}

	/** The name of the engine questions go to. */
	public function chosen(): string {
		return $this->appConfig->getValueString(self::APP, 'ai_engine', 'aihub');
	}

	/** The engine by name, or the chosen one. */
	public function get(?string $name = null): Engine {
		if ($this->fixed !== null) {
			return $this->fixed;
		}
		$name ??= $this->chosen();
		return $this->built[$name] ??= $this->build($name);
	}

	/** Every question goes to this engine (tests, and `occ talk_ai:ask --engine`). */
	public function fix(Engine $engine): void {
		$this->fixed = $engine;
	}

	private function build(string $name): Engine {
		$class = self::HTTP[$name] ?? null;
		if ($class !== null && class_exists($class)) {
			$this->config ??= Server::get(Config::class);
			$this->http ??= Server::get(Http::class);
			return new $class($this->http, $this->config->engine($name));
		}
		return match ($name) {
			'aihub' => new AiHub(self::APP),
			'claude-cli' => new Engine\ClaudeCli(($this->config ??= Server::get(Config::class))->engine($name)),
			'gemini-cli' => new Engine\GeminiCli(($this->config ??= Server::get(Config::class))->engine($name)),
			default => new Missing(Errors::NOT_READY, $name),
		};
	}
}
