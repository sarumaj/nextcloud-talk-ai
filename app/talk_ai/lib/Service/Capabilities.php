<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\AppInfo\Application;
use OCP\IAppConfig;

/**
 * What the AI connection can do, checked when it changes and once a day: tool
 * calls (a probe with one tool, through Loop), image input, PDFs and structured
 * answers (the engine's status), its context size and model family (by the
 * model's name; context_tokens overrides). The family picks the prompt profile:
 * a common core with small additions per family.
 */
class Capabilities {
	/** Context sizes in tokens by model name, first match wins. */
	private const CONTEXT = [
		'/claude/i' => 200000, '/gemini/i' => 1000000, '/gpt-?5|o[34]-|gpt-4\.1/i' => 400000, '/gpt-?4o|gpt-4-turbo/i' => 128000,
		'/llama-?3\.[1-9]|llama-?4/i' => 128000, '/llama/i' => 8000, '/mistral-large|mixtral|mistral-medium/i' => 128000,
		'/mistral|ministral/i' => 32000, '/qwen/i' => 32000, '/deepseek/i' => 64000, '/phi/i' => 16000,
	];

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @return array{tools: bool, images: bool, documents: bool, structured: bool, context: int, family: string, model: string, provider: string, checked: int}
	 */
	public function of(Hub $hub, Status $status, string $userId): array {
		$engine = self::engineKey($status);
		$known = json_decode($this->appConfig->getValueString(Application::APP_ID, 'capabilities', '{}'), true);
		if (is_array($known) && ($known['engine'] ?? '') === $engine && time() - (int)($known['checked'] ?? 0) < 86400) {
			return $known['caps'];
		}
		$caps = [
			'tools' => self::probeTools($hub, $userId),
			'images' => $status->images,
			'documents' => $status->documents,
			'structured' => $status->structured,
			'context' => $this->context($status->model),
			'family' => self::family($status->provider . ' ' . $status->model),
			'model' => $status->model,
			'provider' => $status->provider,
			'checked' => time(),
		];
		$this->appConfig->setValueString(Application::APP_ID, 'capabilities', json_encode(['engine' => $engine, 'checked' => time(), 'caps' => $caps]));
		// The document jobs' own probe agrees with this one.
		$this->appConfig->setValueString(Application::APP_ID, 'tool_probe', json_encode(['engine' => $engine, 'ok' => $caps['tools'], 'time' => time()]));
		return $caps;
	}

	/** Which engine a check was made with: another engine, provider or model is checked anew. */
	public static function engineKey(Status $status): string {
		return $status->engine . '|' . $status->provider . '|' . $status->model;
	}

	/** Forgets the last check (after a change, or for the health check). */
	public function reset(): void {
		$this->appConfig->deleteKey(Application::APP_ID, 'capabilities');
		$this->appConfig->deleteKey(Application::APP_ID, 'tool_probe');
	}

	public function context(string $model): int {
		$override = (int)$this->appConfig->getValueString(Application::APP_ID, 'context_tokens', '0');
		if ($override > 0) {
			return $override;
		}
		foreach (self::CONTEXT as $pattern => $tokens) {
			if (preg_match($pattern, $model) === 1) {
				return $tokens;
			}
		}
		return 32000;
	}

	public static function family(string $name): string {
		return match (true) {
			preg_match('/claude|anthropic/i', $name) === 1 => 'claude',
			preg_match('/gemini|google/i', $name) === 1 => 'gemini',
			preg_match('/gpt|openai|o[34]-/i', $name) === 1 => 'gpt',
			default => 'open',
		};
	}

	/** The family's additions to the common prompt (P-02). */
	public static function profile(string $family): string {
		return match ($family) {
			'claude' => '',
			'gpt' => 'Prefer calling a tool over guessing; keep answers compact.',
			'gemini' => 'Call tools by their exact names with the arguments as listed; do not invent tools.',
			default => 'Tool calls: use exactly one tool block per step, with valid JSON arguments and the exact tool name; '
				. 'after the result, continue. Keep answers short and simple; do not invent facts.',
		};
	}

	/** Whether the model calls a tool: one question with the tool ping. Also used by the document jobs. */
	public static function probeTools(Hub $hub, string $userId): bool {
		$called = false;
		$hub->registerScenario('probe', [
			'system' => 'You test whether tools can be called.',
			'tools' => ['ping' => ['description' => 'Answers pong.', 'input' => ['type' => 'object'], 'run' => function () use (&$called): string {
				$called = true;
				return 'pong';
			}]],
			'search' => false,
			'maxTools' => 2,
		]);
		try {
			$hub->ask($userId, 'probe', [['role' => 'user', 'text' => 'Call the tool ping once, then answer with the word done.']], ['search' => false, 'retries' => 1, 'limits' => false]);
		} catch (\Throwable) {
		}
		return $called;
	}
}
