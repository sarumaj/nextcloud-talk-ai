<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Provider;

use OCA\TalkAi\Ai\Engine\TaskProcessingFormat;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\Ai\Request;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools as ChatWithToolsType;

/**
 * Nextcloud's chat with tools: the tools are the caller's (Assistant's agent),
 * and the caller runs them. So this is one turn of the engine, not a Loop: the
 * calls the model makes go back as tool_calls, and their results come with the
 * next task as tool_message.
 */
class TextToTextChatWithTools extends Base {
	public function getTaskTypeId(): string {
		return ChatWithToolsType::ID;
	}

	protected function short(): string {
		return 'chatwithtools';
	}

	protected function system(): string {
		return '';
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$scenario = 'nextcloud:chatwithtools';
		if (!$this->hub->hasScenario($scenario)) {
			// Only for who may ask and which engine: the prompt and tools come with each task.
			$this->hub->registerScenario($scenario, []);
		}
		$why = $this->hub->refused($userId ?? 'nextcloud', $scenario);
		$engine = $this->hub->engine($scenario);
		$status = $engine->status();
		if ($why === '' && !$status->ready) {
			$why = $status->reason ?: Errors::NOT_READY;
		}
		if ($why !== '') {
			throw new ProcessingException($why);
		}
		$turn = $engine->turn(new Request((string)($input['system_prompt'] ?? ''), self::request($input), TaskProcessingFormat::toolsFrom((string)($input['tools'] ?? '[]'))));
		if (!$turn->ok()) {
			throw new ProcessingException($turn->error);
		}
		return ['output' => $turn->text, 'tool_calls' => $turn->toolCalls === [] ? ''
			: (string)json_encode(array_map(fn (array $c) => ['id' => $c['id'], 'name' => $c['name'], 'args' => (object)$c['args']], $turn->toolCalls), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
	}

	/** History, then the new message: the tool results, or the person's words. */
	public static function request(array $input): array {
		$messages = TaskProcessingFormat::messages((array)($input['history'] ?? []));
		$results = TaskProcessingFormat::toolResults((string)($input['tool_message'] ?? ''));
		if ($results !== null) {
			$messages[] = $results;
		}
		if (trim((string)($input['input'] ?? '')) !== '') {
			$messages[] = Request::message('user', [Request::text((string)$input['input'])]);
		}
		return Hub::normalize($messages);
	}
}
