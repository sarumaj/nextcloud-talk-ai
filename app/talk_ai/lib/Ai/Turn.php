<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/** What one model call gave: words, tool calls, or an error (Errors' vocabulary). */
final class Turn {
	/**
	 * @param list<array{id: string, name: string, args: array}> $toolCalls and an engine's own fields (signature)
	 * @param mixed $answer the structured answer, decoded, when the request had an answerSchema
	 * @param string $stopReason end, tools, length, refusal, or the provider's own word
	 */
	public function __construct(
		public readonly string $text = '',
		public readonly array $toolCalls = [],
		public readonly mixed $answer = null,
		public readonly Usage $usage = new Usage(),
		public readonly mixed $session = null,
		public readonly string $stopReason = 'end',
		public readonly string $error = '',
		/** The provider's own blocks of this answer, by engine (thinking and its signature): they go back unchanged. */
		public readonly array $raw = [],
	) {
	}

	public static function failed(string $error, ?Usage $usage = null): self {
		return new self(usage: $usage ?? new Usage(), stopReason: 'error', error: $error);
	}

	public function ok(): bool {
		return $this->error === '';
	}

	/** The turn as the assistant's message in the history. */
	public function message(): array {
		$parts = trim($this->text) !== '' ? [Request::text($this->text)] : [];
		foreach ($this->toolCalls as $call) {
			// An engine's own fields of a call (Gemini's thought signature) stay with it.
			$parts[] = Request::toolCall($call['id'], $call['name'], $call['args']) + array_diff_key($call, ['id' => 0, 'name' => 0, 'args' => 0]);
		}
		return Request::message('assistant', $parts) + ($this->raw !== [] ? ['raw' => $this->raw] : []);
	}
}
