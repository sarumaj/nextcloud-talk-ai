<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/**
 * Tools and structured answers for engines without them (command lines, servers
 * without tool calls): the model writes {"tool_calls": [{"name", "args"}, …]} and gets
 * {"tool_results": [{"id", "name", "content"}, …]} back as the next user message.
 * Several calls in one reply run together.
 *
 * calls() also finds the formats a model slips into after many rounds: a fenced
 * {"tool": …, "args": …} (the format of an older tool loop), and the <invoke> markup of
 * Anthropic's own format (2026-10-15: a document job stopped on three of them).
 */
final class TextProtocol {
	/** The lines the system prompt gets for the tools and the answer's shape. */
	public static function instructions(array $tools, ?array $answerSchema): string {
		$parts = [];
		if ($tools !== []) {
			$lines = ['Tools. To use tools, answer with one fenced block and nothing after it:',
				"```json\n{\"tool_calls\": [{\"name\": \"<tool>\", \"args\": {…}}]}\n```",
				'Several calls in one block run in order. The results come back as the next message, '
				. '{"tool_results": [{"id", "name", "content"}]}; then go on. Use a tool only when the question needs it, '
				. 'and never invent what a tool would return. The tools:'];
			foreach ($tools as $name => $tool) {
				$lines[] = '- ' . $name . ': ' . $tool['description'] . ' Arguments (JSON Schema): '
					. json_encode($tool['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}
			$parts[] = implode("\n", $lines);
		}
		if ($answerSchema !== null) {
			$parts[] = "Your final answer, when you call no tool, ends with one fenced block of JSON that fits this schema:\n"
				. "```answer\n{…}\n```\nSchema: " . json_encode($answerSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		return implode("\n\n", $parts);
	}

	/**
	 * A reply split into its words, its tool calls and its answer block.
	 *
	 * @param list<string>|null $known tool names; a call to another name is not taken as one (null: all)
	 * @return array{text: string, calls: list<array{id: string, name: string, args: array}>, answer: mixed}
	 */
	public static function parse(string $reply, ?array $known = null): array {
		$answer = null;
		if (preg_match('/```[ \t]*answer[^\n]*\n(.*?)```/s', $reply, $m, PREG_OFFSET_CAPTURE) === 1) {
			$answer = json_decode(trim($m[1][0]), true);
			$reply = substr($reply, 0, $m[0][1]) . substr($reply, $m[0][1] + strlen($m[0][0]));
		}
		[$text, $calls] = self::calls($reply, $known);
		return ['text' => trim($text), 'calls' => $calls, 'answer' => $answer];
	}

	/**
	 * The tool calls written in a text, and the text without them.
	 *
	 * @return array{0: string, 1: list<array{id: string, name: string, args: array}>}
	 */
	public static function calls(string $text, ?array $known = null): array {
		$calls = [];
		$take = function (string $name, mixed $args) use (&$calls, $known): bool {
			if ($name === '' || !is_array($args) || ($known !== null && !in_array($name, $known, true))) {
				return false;
			}
			$calls[] = ['id' => 'text-' . (count($calls) + 1), 'name' => $name, 'args' => $args];
			return true;
		};
		// {"tool_calls": [...]}, fenced or bare.
		if (preg_match('/(?:```[a-z-]*\s*)?(\{\s*"tool_calls"\s*:\s*\[.*\]\s*\})\s*(?:```)?/s', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
			$decoded = json_decode($m[1][0], true);
			foreach (is_array($decoded) ? (array)($decoded['tool_calls'] ?? []) : [] as $call) {
				$take((string)($call['name'] ?? $call['tool'] ?? ''), $call['args'] ?? $call['arguments'] ?? []);
			}
			if ($calls !== []) {
				return [substr($text, 0, $m[0][1]) . substr($text, $m[0][1] + strlen($m[0][0])), $calls];
			}
		}
		// {"tool": …, "args": …}, fenced (with any language tag) or on its own.
		if (preg_match('/(?:```[ \t]*[\w-]*[^\n]*\n\s*)?(\{\s*"tool"\s*:\s*"[\w.-]+"\s*,\s*"args"\s*:\s*\{.*\}\s*\})\s*(?:```)?/s', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
			$decoded = json_decode($m[1][0], true);
			if (is_array($decoded) && $take((string)($decoded['tool'] ?? ''), $decoded['args'] ?? null)) {
				return [substr($text, 0, $m[0][1]) . substr($text, $m[0][1] + strlen($m[0][0])), $calls];
			}
		}
		// <invoke name="…"><parameter name="…">…</parameter></invoke>, possibly several.
		if (preg_match_all('/<invoke name="([\w.-]+)">(.*?)<\/invoke>/s', $text, $invokes, PREG_SET_ORDER) > 0) {
			foreach ($invokes as [$whole, $name, $body]) {
				$args = [];
				preg_match_all('/<parameter name="([\w.-]+)">(.*?)<\/parameter>/s', $body, $params, PREG_SET_ORDER);
				foreach ($params as [, $key, $value]) {
					// Numbers, lists and objects come as JSON; text as it is.
					$decoded = json_decode($value, true);
					$args[$key] = is_array($decoded) || is_int($decoded) || is_float($decoded) || is_bool($decoded) ? $decoded : $value;
				}
				if ($take($name, $args)) {
					$text = str_replace($whole, '', $text);
				}
			}
			$text = preg_replace('/<\/?(function_calls|antml:function_calls)>/', '', $text) ?? $text;
		}
		return [$text, $calls];
	}

	/** Tool results as the next user message's text. */
	public static function results(array $results): string {
		return json_encode(['tool_results' => array_map(fn (array $r) => ['id' => $r['id'], 'name' => $r['name'] ?? '',
			'content' => $r['content']] + (($r['error'] ?? false) ? ['error' => true] : []), $results)],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{"tool_results": []}';
	}

	/**
	 * Messages as plain text turns for an engine that only takes text and images:
	 * tool calls written as the model would write them, results as a user message.
	 *
	 * @return list<array{role: string, text: string, images: list<array{type: string, data: string}>, documents: list<array>}>
	 */
	public static function flatten(array $messages): array {
		$out = [];
		foreach ($messages as $message) {
			$role = $message['role'] === 'assistant' ? 'assistant' : 'user';
			$texts = [];
			$images = [];
			$documents = [];
			$calls = [];
			$results = [];
			foreach ($message['parts'] as $part) {
				match ($part['kind']) {
					'text' => $texts[] = (string)$part['text'],
					'image' => $images[] = ['type' => $part['type'], 'data' => $part['data']],
					'document' => $documents[] = $part,
					'tool_call' => $calls[] = ['name' => $part['name'], 'args' => (object)$part['args']],
					'tool_result' => $results[] = $part,
					default => null,
				};
			}
			if ($results !== []) {
				array_unshift($texts, self::results($results));
			}
			if ($calls !== []) {
				$texts[] = "```json\n" . json_encode(['tool_calls' => $calls], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```";
			}
			$turn = ['role' => $role, 'text' => implode("\n\n", $texts), 'images' => $images, 'documents' => $documents];
			// Two turns of one side in a row (a tool message, then the user's note) become one.
			$last = count($out) - 1;
			if ($last >= 0 && $out[$last]['role'] === $role) {
				$out[$last]['text'] = trim($out[$last]['text'] . "\n\n" . $turn['text']);
				$out[$last]['images'] = [...$out[$last]['images'], ...$images];
				$out[$last]['documents'] = [...$out[$last]['documents'], ...$documents];
			} else {
				$out[] = $turn;
			}
		}
		return $out;
	}
}
