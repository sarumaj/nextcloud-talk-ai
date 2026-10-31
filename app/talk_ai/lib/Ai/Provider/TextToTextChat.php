<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Provider;

use OCA\TalkAi\Ai\Engine\TaskProcessingFormat;
use OCP\TaskProcessing\TaskTypes\TextToTextChat as ChatType;

/** Nextcloud's chat: the caller's system prompt and history, answered by the Hub. */
class TextToTextChat extends Base {
	public function getTaskTypeId(): string {
		return ChatType::ID;
	}

	protected function short(): string {
		return 'chat';
	}

	protected function system(): string {
		// The task brings its own (system_prompt), registered with each task.
		return '';
	}

	protected function messages(array $input): array {
		return [...TaskProcessingFormat::messages((array)($input['history'] ?? [])), ['role' => 'user', 'text' => (string)($input['input'] ?? '')]];
	}
}
