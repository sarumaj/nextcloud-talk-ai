<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/**
 * One model call. Messages are plain arrays, so they go into JSON and the app
 * config as they are:
 *
 *     ['role' => 'user'|'assistant'|'tool', 'parts' => [part, …]]
 *
 * and a part is one of ('kind' tells which; images and documents keep the app's
 * own {type, data} with the media type in 'type'):
 *
 *     ['kind' => 'text', 'text' => '…']
 *     ['kind' => 'image', 'type' => 'image/png', 'data' => base64]
 *     ['kind' => 'document', 'type' => 'application/pdf', 'data' => base64, 'name' => 'x.pdf']
 *     ['kind' => 'tool_call', 'id' => '…', 'name' => '…', 'args' => [...]]          (assistant)
 *     ['kind' => 'tool_result', 'id' => '…', 'name' => '…', 'content' => '…', 'error' => bool]  (tool)
 *
 * A tool message may also hold text and images: what came back besides the
 * results (a rendered page). Engines whose provider takes no images in a tool
 * result move them into a user message.
 */
final class Request {
	/**
	 * @param array<string, array{description: string, input: array}> $tools name => description and JSON schema
	 * @param list<array{role: string, parts: list<array>}> $messages
	 * @param ?array $answerSchema the final answer's JSON schema; Turn::$answer holds it decoded
	 * @param ?callable(string): void $onDelta text as it streams in, where the engine streams
	 * @param mixed $session the engine's own handle from the last Turn (a CLI session), or null
	 */
	public function __construct(
		public readonly string $system,
		public readonly array $messages,
		public readonly array $tools = [],
		public readonly bool $search = false,
		public readonly ?array $answerSchema = null,
		public readonly bool $thinking = false,
		public readonly int $maxOutputTokens = 8192,
		public readonly mixed $onDelta = null,
		public readonly bool $cache = true,
		public readonly mixed $session = null,
	) {
	}

	/** The same request with other messages (Loop's next turn). */
	public function with(array $messages, mixed $session = null): self {
		return new self($this->system, $messages, $this->tools, $this->search, $this->answerSchema, $this->thinking,
			$this->maxOutputTokens, $this->onDelta, $this->cache, $session ?? $this->session);
	}

	public static function message(string $role, array $parts): array {
		return ['role' => $role, 'parts' => $parts];
	}

	public static function text(string $text): array {
		return ['kind' => 'text', 'text' => $text];
	}

	public static function image(string $type, string $data): array {
		return ['kind' => 'image', 'type' => $type, 'data' => $data];
	}

	public static function document(string $type, string $data, string $name): array {
		return ['kind' => 'document', 'type' => $type, 'data' => $data, 'name' => $name];
	}

	public static function toolCall(string $id, string $name, array $args): array {
		return ['kind' => 'tool_call', 'id' => $id, 'name' => $name, 'args' => $args];
	}

	public static function toolResult(string $id, string $name, string $content, bool $error = false): array {
		return ['kind' => 'tool_result', 'id' => $id, 'name' => $name, 'content' => $content, 'error' => $error];
	}

	/** The parts of one kind in a message. */
	public static function parts(array $message, string $kind): array {
		return array_values(array_filter($message['parts'] ?? [], fn (array $p) => ($p['kind'] ?? '') === $kind));
	}

	/** A message's text parts joined. */
	public static function textOf(array $message): string {
		return implode("\n\n", array_map(fn (array $p) => (string)$p['text'], self::parts($message, 'text')));
	}
}
