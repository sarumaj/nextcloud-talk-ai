<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/** What one model call gave: words, tool calls, or an error (Errors' vocabulary). */
final class Turn {
	/**
	 * @param list<array{id: string, name: string, args: array}> $toolCalls
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
			$parts[] = Request::toolCall($call['id'], $call['name'], $call['args']);
		}
		return Request::message('assistant', $parts);
	}
}
