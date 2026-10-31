<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\TextProtocol;
use OCA\TalkAi\Ai\Turn;
use OCP\TaskProcessing\Exception\PreConditionNotMetException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\Task;

/**
 * Whatever AI Nextcloud itself is set up with (Assistant's providers:
 * integration_openai, llm2, …), through its TaskProcessing API: chat with tools
 * where a provider has it, else chat or plain text, with tools and answers then
 * by TextProtocol. No images, no search: the task types carry neither.
 *
 * A task is made without a user: the tools run in Loop with the asking person's
 * rights, not in the provider, and the person's Assistant would otherwise list
 * every turn of every question as a task of theirs.
 *
 * Our own Provider (ids "talk_ai:…") is never used: asking it would ask us.
 *
 * Settings: taskType (core:text2text:chatwithtools), timeout (seconds a scheduled
 * task may take, 600).
 */
final class TaskProcessing implements Engine {
	private const WITH_TOOLS = 'core:text2text:chatwithtools';
	private const CHAT = 'core:text2text:chat';
	private const TEXT = 'core:text2text';

	/** @var array<string, ?IProvider> */
	private array $providers = [];

	public function __construct(
		private IManager $manager,
		private array $settings,
	) {
	}

	public function status(): Status {
		$type = $this->type();
		if ($type === null) {
			return Status::notReady(Errors::NOT_READY, 'taskprocessing');
		}
		$provider = $this->provider($type);
		return new Status(true, '', (string)$provider?->getName(), (string)$provider?->getId(), engine: 'taskprocessing');
	}

	public function models(): array {
		// The providers set up in Nextcloud, by task type: which answers is chosen there.
		$out = [];
		foreach ([self::WITH_TOOLS, self::CHAT, self::TEXT] as $type) {
			$provider = $this->provider($type);
			if ($provider !== null) {
				$out[] = $provider->getId();
			}
		}
		return array_values(array_unique($out));
	}

	public function turn(Request $request): Turn {
		$type = $this->type();
		if ($type === null) {
			return Turn::failed(Errors::NOT_READY);
		}
		$messages = $request->messages;
		$last = array_pop($messages) ?? Request::message('user', []);
		$native = $type === self::WITH_TOOLS;
		$system = trim($request->system . "\n\n" . TextProtocol::instructions($native ? [] : $request->tools, $request->answerSchema));
		if ($native) {
			$input = [
				'system_prompt' => $system,
				'input' => $last['role'] === 'tool' ? '' : Request::textOf($last),
				'tool_message' => $last['role'] === 'tool' ? TaskProcessingFormat::toolMessage($last) : '[]',
				'history' => TaskProcessingFormat::history($messages),
				'tools' => TaskProcessingFormat::tools($request->tools),
			];
		} else {
			$turns = TextProtocol::flatten($request->messages);
			$question = array_pop($turns);
			$history = array_map(fn (array $t) => (string)json_encode(['role' => $t['role'] === 'assistant' ? 'assistant' : 'human', 'content' => $t['text']],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $turns);
			$input = $type === self::CHAT
				? ['system_prompt' => $system, 'input' => (string)($question['text'] ?? ''), 'history' => $history]
				: ['input' => $system . "\n\n---\n\n" . ($turns !== [] ? "Earlier in this conversation:\n\n" . implode("\n\n", array_map(fn ($t) => '[' . $t['role'] . "]\n" . $t['text'], $turns)) . "\n\nNow:\n" : '')
					. (string)($question['text'] ?? '')];
		}
		[$output, $error] = $this->run($type, $input);
		if ($error !== '') {
			return Turn::failed($error);
		}
		$text = (string)($output['output'] ?? '');
		if ($native) {
			$calls = TaskProcessingFormat::calls($output['tool_calls'] ?? '');
			$parsed = TextProtocol::parse($text, []);
			return new Turn($parsed['text'], $calls, $parsed['answer'], stopReason: $calls !== [] ? 'tools' : 'end');
		}
		$parsed = TextProtocol::parse($text, array_keys($request->tools));
		return new Turn($parsed['text'], $parsed['calls'], $parsed['answer'], stopReason: $parsed['calls'] !== [] ? 'tools' : 'end');
	}

	/** @return array{0: array, 1: string} the task's output, or an error word */
	private function run(string $type, array $input): array {
		$task = new Task($type, $input, Engines::APP, null, 'talk_ai:' . bin2hex(random_bytes(6)));
		try {
			if ($this->provider($type) instanceof ISynchronousProvider) {
				$task = $this->manager->runTask($task);
			} else {
				// A provider that works in the background (an External App): scheduled and waited for.
				$this->manager->scheduleTask($task);
				$deadline = microtime(true) + max(1, (int)($this->settings['timeout'] ?? 600));
				while (!in_array($task->getStatus(), [Task::STATUS_SUCCESSFUL, Task::STATUS_FAILED, Task::STATUS_CANCELLED], true)) {
					if (microtime(true) > $deadline) {
						$this->manager->cancelTask((int)$task->getId());
						return [[], Errors::TIMEOUT];
					}
					usleep(500000);
					$task = $this->manager->getTask((int)$task->getId());
				}
			}
		} catch (PreConditionNotMetException) {
			return [[], Errors::NOT_READY];
		} catch (\Throwable $e) {
			return [[], Errors::provider(strtok($e->getMessage(), "\n") ?: get_class($e))];
		}
		return match ($task->getStatus()) {
			Task::STATUS_SUCCESSFUL => [(array)$task->getOutput(), ''],
			Task::STATUS_CANCELLED => [[], Errors::provider('the task was cancelled')],
			default => [[], self::error((string)$task->getErrorMessage())],
		};
	}

	/** The task type questions go to: the setting's, else the next one some other app provides. */
	private function type(): ?string {
		$wanted = (string)($this->settings['taskType'] ?? '') ?: self::WITH_TOOLS;
		foreach (array_unique([$wanted, self::WITH_TOOLS, self::CHAT, self::TEXT]) as $type) {
			if ($this->provider($type) !== null) {
				return $type;
			}
		}
		return null;
	}

	/** The preferred provider of a type, unless there is none or it is our own. */
	private function provider(string $type): ?IProvider {
		if (!array_key_exists($type, $this->providers)) {
			try {
				$provider = $this->manager->getPreferredProvider($type);
			} catch (\Throwable) {
				$provider = null;
			}
			$this->providers[$type] = $provider instanceof IProvider && !str_starts_with($provider->getId(), 'talk_ai:') ? $provider : null;
		}
		return $this->providers[$type];
	}

	private static function error(string $message): string {
		return match (true) {
			preg_match('/\b429\b|rate.?limit|quota/i', $message) === 1 => Errors::RATE_LIMITED,
			preg_match('/time(d)? ?out/i', $message) === 1 => Errors::TIMEOUT,
			default => Errors::provider($message !== '' ? strtok($message, "\n") : 'the task failed'),
		};
	}
}
