<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Http;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Schema;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\Turn;
use OCA\TalkAi\Ai\Usage;

/**
 * Anthropic's Messages API: native tools, images, PDFs, prompt caching, web
 * search on Anthropic's side, structured answers (output_config.format), adaptive
 * thinking, streaming, and the reported usage.
 *
 * Thinking blocks (and server tool blocks) have to go back unchanged with the
 * next request, or the model loses its reasoning and newer models refuse an
 * edited history. Each answer's raw content goes into its message ('raw' =>
 * ['anthropic' => blocks], Turn::$raw), so it survives a caller that keeps the
 * messages across questions; Turn::$session keeps it too, keyed by its tool call
 * ids (or its words), for messages that lost it.
 */
final class Anthropic implements Engine {
	private const VERSION = '2023-06-01';
	/** Images per request: the API takes more, but a request is at most 32 MB and five large photos fill a fifth of it. */
	private const MAX_IMAGES = 20;
	/** Answers kept in the session for replay. */
	private const KEEP = 200;
	/** Models whose refusals another model may answer (server-side fallback, Claude API only). */
	private const FALLBACK_MODELS = '/^claude-(fable-5-1|opus-5-5|opus-5|sonnet-5-5)$/';
	/** Models without the dynamic-filtering web search (older than Opus/Sonnet 4.6). */
	private const BASIC_SEARCH = '/claude-(3|haiku|opus-4-[015]|sonnet-4-[05]|opus-4$|sonnet-4$)/';

	private string $key;
	private string $model;
	private int $maxImages;
	private string $baseUrl;
	private int $timeout;
	/** @var array<string, bool> */
	private array $flags;

	public function __construct(
		private Http $http,
		array $settings,
	) {
		$this->key = (string)($settings['key'] ?? '');
		$this->model = (string)($settings['model'] ?? '');
		$this->baseUrl = rtrim((string)($settings['baseUrl'] ?? '') ?: 'https://api.anthropic.com', '/');
		$this->timeout = max(10, (int)($settings['timeout'] ?? 600));
		$this->flags = array_map('boolval', (array)($settings['flags'] ?? [])) + [
			'search' => true, 'caching' => true, 'thinking' => true, 'documents' => true, 'streaming' => true, 'fallbacks' => true,
		];
		$this->maxImages = max(0, (int)($settings['maxImages'] ?? self::MAX_IMAGES));
	}

	public function status(): Status {
		if ($this->key === '') {
			return Status::notReady(Errors::NO_KEY, 'anthropic', 'anthropic');
		}
		if ($this->model === '') {
			return Status::notReady(Errors::NO_MODEL, 'anthropic', 'anthropic');
		}
		return new Status(true, '', 'anthropic', $this->model, $this->maxImages > 0, $this->maxImages, $this->flags['search'],
			$this->flags['documents'], true, $this->flags['streaming'], $this->flags['caching'], 'anthropic');
	}

	public function models(): array {
		if ($this->key === '') {
			return [];
		}
		$ids = [];
		$after = '';
		// The list is paged by after_id; a few pages at most.
		for ($page = 0; $page < 10; $page++) {
			$r = $this->http->json('GET', $this->baseUrl . '/v1/models?limit=1000' . ($after !== '' ? '&after_id=' . rawurlencode($after) : ''), $this->headers(), null, 30);
			if ($r['error'] !== '') {
				break;
			}
			foreach ((array)($r['json']['data'] ?? []) as $model) {
				if (is_string($model['id'] ?? null)) {
					$ids[] = $model['id'];
				}
			}
			$after = (string)($r['json']['last_id'] ?? '');
			if (!($r['json']['has_more'] ?? false) || $after === '') {
				break;
			}
		}
		$ids = array_values(array_unique($ids));
		sort($ids);
		return $ids;
	}

	public function turn(Request $request): Turn {
		$status = $this->status();
		if (!$status->ready) {
			return Turn::failed($status->reason);
		}
		$raw = (array)($request->session['anthropic'] ?? []);
		$body = $this->body($request, $raw);
		$usage = new Usage();
		$content = [];
		$stop = 'end_turn';
		// The server's own search loop may pause; it goes on where it stopped when asked again.
		for ($pause = 0; $pause < 4; $pause++) {
			$streaming = $request->onDelta !== null && $this->flags['streaming'];
			[$answer, $error] = $streaming ? $this->stream($body, $request->onDelta) : $this->post($body);
			if ($error !== '') {
				return Turn::failed($error, $usage);
			}
			$usage = $usage->add(self::usage((array)($answer['usage'] ?? [])));
			$content = [...$content, ...(array)($answer['content'] ?? [])];
			$stop = (string)($answer['stop_reason'] ?? 'end_turn');
			if ($stop !== 'pause_turn') {
				break;
			}
			$body['messages'][] = ['role' => 'assistant', 'content' => (array)($answer['content'] ?? [])];
		}
		return $this->result($request, $content, $stop, $usage, $raw);
	}

	/** The request body for the Messages API. */
	private function body(Request $request, array $raw): array {
		$cache = $request->cache && $this->flags['caching'];
		$body = ['model' => $this->model, 'max_tokens' => $request->maxOutputTokens, 'messages' => $this->messages($request->messages, $raw)];
		if ($request->system !== '') {
			// The system prompt and the tools are the same for every question: cached for an hour.
			$body['system'] = [['type' => 'text', 'text' => $request->system] + ($cache ? ['cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']] : [])];
		}
		$tools = [];
		foreach ($request->tools as $name => $tool) {
			$tools[] = ['name' => $name, 'description' => $tool['description'], 'input_schema' => Schema::object($tool['input'])];
		}
		if ($cache && $tools !== []) {
			$tools[count($tools) - 1]['cache_control'] = ['type' => 'ephemeral', 'ttl' => '1h'];
		}
		if ($request->search && $this->flags['search']) {
			$tools[] = ['type' => preg_match(self::BASIC_SEARCH, $this->model) === 1 ? 'web_search_20250305' : 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 5];
		}
		if ($tools !== []) {
			$body['tools'] = $tools;
		}
		// The latest message too (third breakpoint of four): a long tool loop reads its history from the cache.
		$last = count($body['messages']) - 1;
		if ($cache && $last >= 0) {
			$blocks = $body['messages'][$last]['content'];
			$i = count($blocks) - 1;
			if ($i >= 0 && !in_array($blocks[$i]['type'] ?? '', ['thinking', 'redacted_thinking'], true)) {
				$body['messages'][$last]['content'][$i]['cache_control'] = ['type' => 'ephemeral'];
			}
		}
		if ($request->thinking && $this->flags['thinking']) {
			$body['thinking'] = ['type' => 'adaptive'];
		}
		if ($request->answerSchema !== null) {
			// Forced tool use is refused by the newest models; structured outputs are taken by all current ones.
			$body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => self::strict($request->answerSchema)]];
		}
		if ($this->flags['fallbacks'] && preg_match(self::FALLBACK_MODELS, $this->model) === 1 && str_contains($this->baseUrl, 'api.anthropic.com')) {
			$body['fallbacks'] = 'default';
		}
		return $body;
	}

	/** Request messages as the API's: tool results first in a user message, same roles merged. */
	private function messages(array $messages, array $raw): array {
		$out = [];
		foreach ($messages as $message) {
			$role = $message['role'] === 'assistant' ? 'assistant' : 'user';
			$blocks = $role === 'assistant' ? ($message['raw']['anthropic'] ?? $raw[self::key($message)] ?? null) : null;
			$blocks ??= $this->blocks($message);
			if ($blocks === []) {
				continue;
			}
			$last = count($out) - 1;
			if ($last >= 0 && $out[$last]['role'] === $role) {
				$out[$last]['content'] = [...$out[$last]['content'], ...$blocks];
			} else {
				$out[] = ['role' => $role, 'content' => $blocks];
			}
		}
		// Tool results lead a user message (the API wants them right after the tool calls).
		foreach ($out as $i => $m) {
			if ($m['role'] === 'user') {
				usort($out[$i]['content'], fn ($a, $b) => (int)(($b['type'] ?? '') === 'tool_result') <=> (int)(($a['type'] ?? '') === 'tool_result'));
			}
		}
		return $out;
	}

	private function blocks(array $message): array {
		$blocks = [];
		$results = [];
		$attached = [];
		foreach ($message['parts'] as $part) {
			switch ($part['kind'] ?? '') {
				case 'text':
					if (trim((string)$part['text']) !== '') {
						$blocks[] = ['type' => 'text', 'text' => (string)$part['text']];
					}
					break;
				case 'image':
					$image = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $part['type'], 'data' => $part['data']]];
					if ($message['role'] === 'tool') {
						$attached[] = $image;
					} else {
						$blocks[] = $image;
					}
					break;
				case 'document':
					// Documents before the words that ask about them.
					array_unshift($blocks, $this->document($part));
					break;
				case 'tool_call':
					$blocks[] = ['type' => 'tool_use', 'id' => $part['id'], 'name' => $part['name'], 'input' => (object)$part['args']];
					break;
				case 'tool_result':
					$results[] = ['type' => 'tool_result', 'tool_use_id' => $part['id'], 'content' => [['type' => 'text', 'text' => $part['content'] !== '' ? $part['content'] : '(empty)']]]
						+ (($part['error'] ?? false) ? ['is_error' => true] : []);
					break;
			}
		}
		// Images that came back with tools belong to the last result: that is what the model asked to see.
		if ($attached !== []) {
			if ($results !== []) {
				$results[count($results) - 1]['content'] = [...$results[count($results) - 1]['content'], ...$attached];
			} else {
				$blocks = [...$attached, ...$blocks];
			}
		}
		return [...$results, ...$blocks];
	}

	private function document(array $part): array {
		if ($part['type'] === 'application/pdf' && $this->flags['documents']) {
			return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $part['data']], 'title' => (string)($part['name'] ?? '')];
		}
		if (str_starts_with((string)$part['type'], 'text/')) {
			return ['type' => 'document', 'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => (string)base64_decode((string)$part['data'])], 'title' => (string)($part['name'] ?? '')];
		}
		return ['type' => 'text', 'text' => '[the document ' . ($part['name'] ?? '') . ' (' . $part['type'] . ') could not be attached]'];
	}

	/** @return array{0: array, 1: string} the answer and an error word */
	private function post(array $body): array {
		$r = $this->http->json('POST', $this->baseUrl . '/v1/messages', $this->headers($body), $body, $this->timeout);
		if ($r['error'] !== '') {
			return [[], self::apiError($r)];
		}
		if (!is_array($r['json']['content'] ?? null)) {
			return [[], Errors::provider('the answer has no content')];
		}
		return [$r['json'], ''];
	}

	/** The streamed answer, put together as the non-streamed one would be. */
	private function stream(array $body, callable $onDelta): array {
		$answer = ['content' => [], 'usage' => [], 'stop_reason' => 'end_turn'];
		$json = [];
		$error = '';
		$r = $this->http->events($this->baseUrl . '/v1/messages', $this->headers($body), $body + ['stream' => true], $this->timeout,
			function (string $event, array $data) use (&$answer, &$json, &$error, $onDelta): void {
				switch ($data['type'] ?? $event) {
					case 'message_start':
						$answer['usage'] = (array)($data['message']['usage'] ?? []);
						break;
					case 'content_block_start':
						$answer['content'][(int)$data['index']] = (array)$data['content_block'];
						break;
					case 'content_block_delta':
						$i = (int)$data['index'];
						$delta = (array)$data['delta'];
						$type = (string)($delta['type'] ?? '');
						if ($type === 'text_delta') {
							$answer['content'][$i]['text'] = ($answer['content'][$i]['text'] ?? '') . $delta['text'];
							$onDelta((string)$delta['text']);
						} elseif ($type === 'input_json_delta') {
							$json[$i] = ($json[$i] ?? '') . $delta['partial_json'];
						} elseif ($type === 'thinking_delta') {
							$answer['content'][$i]['thinking'] = ($answer['content'][$i]['thinking'] ?? '') . $delta['thinking'];
						} elseif ($type === 'signature_delta') {
							$answer['content'][$i]['signature'] = $delta['signature'];
						} elseif ($type === 'citations_delta') {
							$answer['content'][$i]['citations'][] = $delta['citation'];
						}
						break;
					case 'message_delta':
						$answer['stop_reason'] = (string)($data['delta']['stop_reason'] ?? $answer['stop_reason']);
						$answer['usage'] = array_replace($answer['usage'], (array)($data['usage'] ?? []));
						break;
					case 'error':
						$error = self::streamError((array)($data['error'] ?? []));
						break;
				}
			});
		if ($r['error'] !== '') {
			return [[], self::apiError($r)];
		}
		if ($error !== '') {
			return [[], $error];
		}
		foreach ($json as $i => $text) {
			$input = $text === '' ? [] : json_decode($text, true);
			if (!is_array($input)) {
				return [[], Errors::provider('a tool call came incomplete')];
			}
			$answer['content'][$i]['input'] = $input;
		}
		ksort($answer['content']);
		$answer['content'] = array_values($answer['content']);
		return [$answer, ''];
	}

	private function result(Request $request, array $content, string $stop, Usage $usage, array $raw): Turn {
		$text = '';
		$calls = [];
		foreach ($content as $block) {
			match ($block['type'] ?? '') {
				'text' => $text .= (string)$block['text'],
				'tool_use' => $calls[] = ['id' => (string)$block['id'], 'name' => (string)$block['name'], 'args' => (array)($block['input'] ?? [])],
				default => null,
			};
		}
		$answer = null;
		if ($request->answerSchema !== null && $calls === []) {
			$answer = json_decode(trim($text), true);
		}
		$turn = new Turn($text, $calls, $answer);
		$raw[self::key($turn->message())] = self::replayable($content);
		$session = ['anthropic' => array_slice($raw, -self::KEEP, null, true)];
		$stopReason = match ($stop) {
			'end_turn', 'stop_sequence' => 'end',
			'tool_use' => 'tools',
			'max_tokens' => 'length',
			default => $stop,
		};
		return new Turn($text, $calls, $answer, $usage, $session, $stopReason, raw: ['anthropic' => self::replayable($content)]);
	}

	/** The blocks to send back: after a fallback, the declined model's thinking and calls stay out. */
	private static function replayable(array $content): array {
		$boundary = -1;
		foreach ($content as $i => $block) {
			if (($block['type'] ?? '') === 'fallback') {
				$boundary = $i;
			}
		}
		$out = [];
		foreach ($content as $i => $block) {
			$type = $block['type'] ?? '';
			if ($type === 'fallback' || ($i < $boundary && in_array($type, ['thinking', 'redacted_thinking', 'tool_use', 'server_tool_use'], true))) {
				continue;
			}
			if ($type === 'tool_use') {
				$block['input'] = (object)($block['input'] ?? []);
			}
			$out[] = $block;
		}
		return $out;
	}

	/** Which answer a history message is: its tool call ids, or its words. */
	private static function key(array $message): string {
		$ids = array_column(Request::parts($message, 'tool_call'), 'id');
		return $ids !== [] ? 'calls:' . implode(',', $ids) : 'text:' . md5(trim(Request::textOf($message)));
	}

	private function headers(?array $body = null): array {
		$headers = ['x-api-key' => $this->key, 'anthropic-version' => self::VERSION];
		if (isset($body['fallbacks'])) {
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}
		return $headers;
	}

	private static function usage(array $u): Usage {
		$read = (int)($u['cache_read_input_tokens'] ?? 0);
		return new Usage((int)($u['input_tokens'] ?? 0) + $read + (int)($u['cache_creation_input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0), $read, null, true);
	}

	/** An HTTP error with Anthropic's own error type, which says more than the status. */
	private static function apiError(array $r): string {
		$type = (string)($r['json']['error']['type'] ?? '');
		return $type !== '' && $r['status'] !== 0 ? self::streamError((array)$r['json']['error'], $r['error']) : $r['error'];
	}

	private static function streamError(array $error, string $fallback = ''): string {
		return match ((string)($error['type'] ?? '')) {
			'overloaded_error' => Errors::BUSY,
			'rate_limit_error' => Errors::RATE_LIMITED,
			'authentication_error', 'permission_error' => Errors::NO_KEY,
			'timeout_error' => Errors::TIMEOUT,
			default => $fallback !== '' ? $fallback : Errors::provider((string)($error['message'] ?? 'stream error')),
		};
	}

	/** A schema as structured outputs take it: closed objects, no number or length bounds. */
	private static function strict(array $schema): array {
		unset($schema['minimum'], $schema['maximum'], $schema['exclusiveMinimum'], $schema['exclusiveMaximum'], $schema['multipleOf'],
			$schema['minLength'], $schema['maxLength'], $schema['minItems'], $schema['maxItems']);
		if (($schema['type'] ?? null) === 'object' || isset($schema['properties'])) {
			$schema['additionalProperties'] = false;
			$schema['properties'] = (object)array_map(fn ($s) => is_array($s) ? self::strict($s) : $s, (array)($schema['properties'] ?? []));
		}
		if (is_array($schema['items'] ?? null)) {
			$schema['items'] = self::strict($schema['items']);
		}
		foreach (['anyOf', 'allOf', 'oneOf'] as $key) {
			if (is_array($schema[$key] ?? null)) {
				$schema[$key] = array_map(fn ($s) => is_array($s) ? self::strict($s) : $s, $schema[$key]);
			}
		}
		return $schema;
	}
}
