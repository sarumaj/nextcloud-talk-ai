<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCA\TalkAi\Ai\Engine\AiHub;
use OCA\TalkAi\Ai\Engine\Missing;
use OCP\IAppConfig;

/** Builds the engine the administrator chose (app config "ai_engine"), once per request. */
class Engines {
	/** The app whose settings hold the layer's: the one place to change when lib/Ai/ becomes an app. */
	public const APP = 'talk_ai';

	/** @var array<string, Engine> */
	private array $built = [];
	private ?Engine $fixed = null;

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/** The engine by name, or the chosen one. */
	public function get(?string $name = null): Engine {
		if ($this->fixed !== null) {
			return $this->fixed;
		}
		$name ??= $this->appConfig->getValueString(self::APP, 'ai_engine', 'aihub');
		return $this->built[$name] ??= match ($name) {
			'aihub' => new AiHub(self::APP),
			default => new Missing(Errors::NOT_READY, $name),
		};
	}

	/** Every question goes to this engine (tests, and `occ talk_ai:ask --engine`). */
	public function fix(Engine $engine): void {
		$this->fixed = $engine;
	}
}
