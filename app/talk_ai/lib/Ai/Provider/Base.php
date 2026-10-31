<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Provider;

use OCA\TalkAi\Ai\Hub;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;

/**
 * The Hub as a provider of Nextcloud's TaskProcessing, so Assistant, Mail and
 * Talk's own features can use the engine set up here. One class per task type
 * (Nextcloud asks for that); each asks the Hub in a scenario of its own,
 * "nextcloud:<short>". A task without a user (a background feature) asks as
 * "nextcloud". The engine "taskprocessing" never asks these (it would ask itself).
 */
abstract class Base implements ISynchronousProvider {
	public function __construct(
		protected Hub $hub,
	) {
	}

	/** The short name in the provider's id and its scenario. */
	abstract protected function short(): string;

	/** The system prompt of its scenario; '' when the task brings its own. */
	abstract protected function system(): string;

	public function getId(): string {
		return 'talk_ai:' . $this->short();
	}

	public function getName(): string {
		// Nextcloud may ask for the name while the engine (taskprocessing) asks Nextcloud for its providers.
		static $naming = false;
		if ($naming) {
			return 'AI in Talk';
		}
		$naming = true;
		try {
			$status = $this->hub->status();
		} finally {
			$naming = false;
		}
		return 'AI in Talk' . ($status->ready ? ' (' . trim($status->provider . ' ' . $status->model) . ')' : '');
	}

	public function getExpectedRuntime(): int {
		return 30;
	}

	public function getOptionalInputShape(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$scenario = 'nextcloud:' . $this->short();
		if (!$this->hub->hasScenario($scenario) || $this->system() === '') {
			$this->hub->registerScenario($scenario, ['system' => $this->system() !== '' ? $this->system() : (string)($input['system_prompt'] ?? '')]);
		}
		$result = $this->hub->ask($userId ?? 'nextcloud', $scenario, $this->messages($input), ['search' => false]);
		if ($result['state'] !== 'done') {
			throw new ProcessingException((string)$result['error']);
		}
		return ['output' => (string)$result['text']];
	}

	/** The task's input as the Hub's messages. */
	protected function messages(array $input): array {
		return [['role' => 'user', 'text' => (string)($input['input'] ?? '')]];
	}
}
