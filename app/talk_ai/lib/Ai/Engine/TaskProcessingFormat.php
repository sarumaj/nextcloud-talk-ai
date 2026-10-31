<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Schema;

/**
 * Nextcloud's chat task types in our messages and back, as integration_openai
 * reads and writes them (lib/Service/OpenAiAPIService.php, createChatCompletion):
 *
 * - history: a list of JSON strings, one message each: {"role": "human"|"user"|
 *   "assistant"|"tool", "content": text (or a list of {"type": "text", "text"}),
 *   "tool_calls": [{"id", "name", "args"}] on an assistant's, "tool_call_id" on a tool's};
 * - tools: a JSON string, a list in OpenAI's format ({"type": "function", "function":
 *   {"name", "description", "parameters"}});
 * - tool_message: a JSON string, a list of {"tool_call_id", "name", "content"};
 * - tool_calls (output): a JSON string, a list of {"id", "name", "args"} (LangChain's).
 *
 * Reading is lenient (arguments as a string or an object, OpenAI's nested call form,
 * a single object for a list), because each provider app writes its own variant.
 */
final class TaskProcessingFormat {
	/** @return list<string> our messages as history entries */
	public static function history(array $messages): array {
		$out = [];
		foreach ($messages as $message) {
			$text = Request::textOf($message);
			if ($message['role'] === 'tool') {
				foreach (Request::parts($message, 'tool_result') as $result) {
					$out[] = self::json(['role' => 'tool', 'content' => (string)$result['content'], 'tool_call_id' => (string)$result['id'], 'name' => (string)$result['name']]);
				}
				// What came with the results (a note, a rendered page's description) as the person's words.
				if ($text !== '') {
					$out[] = self::json(['role' => 'human', 'content' => $text]);
				}
				continue;
			}
			$entry = ['role' => $message['role'] === 'assistant' ? 'assistant' : 'human', 'content' => $text];
			$calls = Request::parts($message, 'tool_call');
			if ($calls !== []) {
				$entry['tool_calls'] = array_map(fn (array $c) => ['id' => (string)$c['id'], 'name' => (string)$c['name'], 'args' => (object)$c['args']], $calls);
			}
			$out[] = self::json($entry);
		}
		return $out;
	}

	/** Tool results as tool_message. */
	public static function toolMessage(array $message): string {
		return self::json(array_map(fn (array $r) => ['tool_call_id' => (string)$r['id'], 'name' => (string)$r['name'], 'content' => (string)$r['content']],
			Request::parts($message, 'tool_result')));
	}

	/** Our tools in OpenAI's format. */
	public static function tools(array $tools): string {
		$list = [];
		foreach ($tools as $name => $tool) {
			$list[] = ['type' => 'function', 'function' => ['name' => (string)$name, 'description' => (string)($tool['description'] ?? ''),
				'parameters' => Schema::object((array)($tool['input'] ?? []))]];
		}
		return self::json($list);
	}

	/** @return list<array{role: string, parts: list<array>}> history entries as our messages */
	public static function messages(array $history): array {
		$out = [];
		foreach ($history as $i => $entry) {
			$m = is_array($entry) ? $entry : json_decode((string)$entry, true);
			if (!is_array($m)) {
				// Plain text, as some callers send it (AI-Hub took it): the person's and the
				// assistant's turns alternate, the person's first.
				if (!is_string($entry) || trim($entry) === '') {
					continue;
				}
				$m = ['role' => $i % 2 === 0 ? 'human' : 'assistant', 'content' => $entry];
			}
			$role = (string)($m['role'] ?? 'human');
			$text = self::content($m['content'] ?? '');
			if ($role === 'tool') {
				$part = Request::toolResult((string)($m['tool_call_id'] ?? ''), (string)($m['name'] ?? ''), $text);
				$last = count($out) - 1;
				// Several results of one round are one tool message.
				if ($last >= 0 && $out[$last]['role'] === 'tool') {
					$out[$last]['parts'][] = $part;
				} else {
					$out[] = Request::message('tool', [$part]);
				}
				continue;
			}
			$parts = trim($text) !== '' ? [Request::text($text)] : [];
			if (in_array($role, ['assistant', 'ai'], true)) {
				foreach (self::calls($m['tool_calls'] ?? []) as $call) {
					$parts[] = Request::toolCall($call['id'], $call['name'], $call['args']);
				}
				$out[] = Request::message('assistant', $parts);
			} elseif ($parts !== []) {
				$out[] = Request::message('user', $parts);
			}
		}
		return $out;
	}

	/** tool_message as a tool message, or null when it holds none. */
	public static function toolResults(string $json): ?array {
		$list = json_decode($json, true);
		if (!is_array($list) || $list === []) {
			return null;
		}
		$list = array_is_list($list) ? $list : [$list];
		$parts = [];
		foreach ($list as $r) {
			if (is_array($r)) {
				$parts[] = Request::toolResult((string)($r['tool_call_id'] ?? $r['id'] ?? ''), (string)($r['name'] ?? ''), self::content($r['content'] ?? ''));
			}
		}
		return $parts === [] ? null : Request::message('tool', $parts);
	}

	/** @return array<string, array{description: string, input: array}> tools from OpenAI's format (or bare {name, …}) */
	public static function toolsFrom(string $json): array {
		$list = json_decode($json, true);
		$out = [];
		foreach (is_array($list) ? $list : [] as $tool) {
			$f = is_array($tool['function'] ?? null) ? $tool['function'] : $tool;
			$name = (string)($f['name'] ?? '');
			if ($name !== '') {
				$out[$name] = ['description' => (string)($f['description'] ?? ''), 'input' => (array)($f['parameters'] ?? $f['input_schema'] ?? ['type' => 'object'])];
			}
		}
		return $out;
	}

	/**
	 * tool_calls in any of the forms providers write: {id, name, args}, args as a string,
	 * "arguments" instead of "args", or OpenAI's {id, function: {name, arguments}}.
	 *
	 * @return list<array{id: string, name: string, args: array}>
	 */
	public static function calls(mixed $calls): array {
		$calls = is_string($calls) ? json_decode($calls, true) : $calls;
		if (!is_array($calls) || $calls === []) {
			return [];
		}
		$calls = array_is_list($calls) ? $calls : [$calls];
		$out = [];
		foreach ($calls as $i => $call) {
			if (!is_array($call)) {
				continue;
			}
			$f = is_array($call['function'] ?? null) ? $call['function'] + ['id' => $call['id'] ?? null] : $call;
			$args = $f['args'] ?? $f['arguments'] ?? [];
			$args = is_string($args) ? json_decode($args, true) : $args;
			$name = (string)($f['name'] ?? '');
			if ($name !== '') {
				$out[] = ['id' => (string)($f['id'] ?? '') ?: 'tp-' . ($i + 1), 'name' => $name, 'args' => is_array($args) ? $args : []];
			}
		}
		return $out;
	}

	/** The words of a content: a text, or the text items of a list. */
	private static function content(mixed $content): string {
		if (is_string($content)) {
			return $content;
		}
		if (is_array($content)) {
			$texts = [];
			foreach ($content as $item) {
				if (is_array($item) && isset($item['text'])) {
					$texts[] = (string)$item['text'];
				}
			}
			return $texts !== [] ? implode("\n", $texts) : (string)json_encode($content, JSON_UNESCAPED_UNICODE);
		}
		return $content === null ? '' : (string)json_encode($content, JSON_UNESCAPED_UNICODE);
	}

	private static function json(mixed $value): string {
		return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
