<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Http;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\TextProtocol;
use OCA\TalkAi\Ai\Turn;
use OCA\TalkAi\Ai\Usage;

/**
 * Google's Gemini API: generateContent, or streamGenerateContent?alt=sse when the
 * caller streams. The key goes in the x-goog-api-key header, never in the URL
 * (an URL ends up in logs and error messages).
 *
 * Settings: key, model, baseUrl, timeout, maxImages, flags {search, documents,
 * streaming, thinking}.
 *
 * Gemini 3 thinks before it calls a tool and signs that (thoughtSignature on the
 * first functionCall part of a reply); the signature must come back with the call
 * in the history, or the API answers 400. It travels in the call's part in the
 * history ('signature'), and by call id in the engine for the run.
 */
final class Gemini implements Engine {
	private const BASE = 'https://generativelanguage.googleapis.com/v1beta';
	/** Google's documented value for a call whose signature is lost: better than a 400. */
	private const NO_SIGNATURE = 'skip_thought_signature_validator';
	/** The JSON Schema words Gemini's schema (an OpenAPI subset) takes. */
	private const SCHEMA_WORDS = ['type', 'description', 'properties', 'required', 'items', 'enum', 'format', 'nullable', 'anyOf',
		'minimum', 'maximum', 'minItems', 'maxItems', 'title', 'propertyOrdering'];

	private string $base;
	private array $flags;
	/** @var array<string, string> call id => thoughtSignature */
	private array $signatures = [];
	private int $calls = 0;

	public function __construct(
		private Http $http,
		private array $settings,
	) {
		$this->base = rtrim((string)($settings['baseUrl'] ?? '') ?: self::BASE, '/');
		$this->flags = (array)($settings['flags'] ?? []) + ['search' => true, 'documents' => true, 'streaming' => true, 'thinking' => true];
	}

	public function status(): Status {
		$model = $this->model();
		if ((string)($this->settings['key'] ?? '') === '') {
			return Status::notReady(Errors::NO_KEY, 'gemini', 'google', $model);
		}
		if ($model === '') {
			return Status::notReady(Errors::NO_MODEL, 'gemini', 'google');
		}
		// Implicit caching on the provider's side: usage says what it served.
		return new Status(true, '', 'google', $model, true, max(1, (int)($this->settings['maxImages'] ?? 16)), (bool)$this->flags['search'],
			(bool)$this->flags['documents'], true, (bool)$this->flags['streaming'], true, 'gemini');
	}

	public function turn(Request $request): Turn {
		$tools = $request->tools !== [];
		// Gemini 2.x takes neither a search nor a JSON answer together with functions; 3 does.
		$combines = $this->generation() >= 3;
		$schema = $request->answerSchema !== null && (!$tools || $combines);
		$system = $request->system;
		if ($request->answerSchema !== null && !$schema) {
			$system = trim($system . "\n\n" . TextProtocol::instructions([], $request->answerSchema));
		}
		$body = ['contents' => $this->contents($request->messages), 'generationConfig' => ['maxOutputTokens' => $request->maxOutputTokens]];
		if (trim($system) !== '') {
			$body['systemInstruction'] = ['parts' => [['text' => $system]]];
		}
		if ($tools) {
			$body['tools'][] = ['functionDeclarations' => array_values(array_map(fn (string $name, array $t) => ['name' => $name,
				'description' => $t['description']] + $this->parameters($t['input']), array_keys($request->tools), $request->tools))];
		}
		if ($request->search && $this->flags['search'] && (!$tools || $combines)) {
			$body['tools'][] = ['googleSearch' => (object)[]];
		}
		if ($schema) {
			$body['generationConfig'] += ['responseMimeType' => 'application/json', 'responseSchema' => $this->schema($request->answerSchema)];
		}
		if ($request->thinking && $this->flags['thinking']) {
			// Gemini 3 takes a level, 2.5 a budget (-1: as much as the question needs).
			$body['generationConfig']['thinkingConfig'] = $this->generation() >= 3 ? ['thinkingLevel' => 'high'] : ['thinkingBudget' => -1];
		}
		$url = $this->base . '/models/' . rawurlencode($this->model());
		$headers = ['x-goog-api-key' => (string)$this->settings['key']];
		if ($request->onDelta !== null && $this->flags['streaming']) {
			$answer = $this->stream($url . ':streamGenerateContent?alt=sse', $headers, $body, $request->onDelta);
		} else {
			$answer = $this->http->json('POST', $url . ':generateContent', $headers, $body, $this->timeout());
		}
		if ($answer['error'] !== '') {
			return Turn::failed($answer['error']);
		}
		return $this->turnOf((array)$answer['json'], $request, $schema);
	}

	public function models(): array {
		$ids = [];
		$token = '';
		// Paged: a hundred or so models, at most a few pages.
		for ($page = 0; $page < 10; $page++) {
			$answer = $this->http->json('GET', $this->base . '/models?pageSize=1000' . ($token !== '' ? '&pageToken=' . rawurlencode($token) : ''),
				['x-goog-api-key' => (string)($this->settings['key'] ?? '')], null, 30);
			foreach ((array)($answer['json']['models'] ?? []) as $model) {
				if (in_array('generateContent', (array)($model['supportedGenerationMethods'] ?? []), true)) {
					$ids[] = preg_replace('#^models/#', '', (string)$model['name']);
				}
			}
			$token = (string)($answer['json']['nextPageToken'] ?? '');
			if ($token === '') {
				break;
			}
		}
		sort($ids);
		return $ids;
	}

	private function turnOf(array $json, Request $request, bool $schema): Turn {
		$usage = (array)($json['usageMetadata'] ?? []);
		$used = new Usage((int)($usage['promptTokenCount'] ?? 0) + (int)($usage['toolUsePromptTokenCount'] ?? 0),
			(int)($usage['candidatesTokenCount'] ?? 0) + (int)($usage['thoughtsTokenCount'] ?? 0), (int)($usage['cachedContentTokenCount'] ?? 0),
			reported: isset($usage['promptTokenCount']));
		if (isset($json['promptFeedback']['blockReason'])) {
			return Turn::failed(Errors::provider('blocked: ' . $json['promptFeedback']['blockReason']), $used);
		}
		$candidate = $json['candidates'][0] ?? null;
		if (!is_array($candidate)) {
			return Turn::failed(Errors::provider('no answer in the response'), $used);
		}
		$finish = (string)($candidate['finishReason'] ?? 'STOP');
		$text = '';
		$calls = [];
		foreach ((array)($candidate['content']['parts'] ?? []) as $part) {
			if (isset($part['functionCall'])) {
				$id = (string)($part['functionCall']['id'] ?? '');
				// An id of our own where the API gives none: 'g-' marks it, so it is not sent back as Gemini's.
				$id = $id !== '' ? $id : 'g-' . ++$this->calls;
				if (isset($part['thoughtSignature'])) {
					$this->signatures[$id] = (string)$part['thoughtSignature'];
				}
				$args = $part['functionCall']['args'] ?? [];
				$calls[] = ['id' => $id, 'name' => (string)$part['functionCall']['name'], 'args' => is_array($args) ? $args : []]
					+ (isset($part['thoughtSignature']) ? ['signature' => (string)$part['thoughtSignature']] : []);
			} elseif (isset($part['text']) && !($part['thought'] ?? false)) {
				$text .= $part['text'];
			}
		}
		if ($calls === [] && trim($text) === '' && in_array($finish, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'], true)) {
			return Turn::failed(Errors::provider('blocked: ' . $finish), $used);
		}
		$answer = null;
		if ($schema && $calls === []) {
			$answer = json_decode(trim($text), true);
		} elseif ($request->answerSchema !== null) {
			$parsed = TextProtocol::parse($text, []);
			[$text, $answer] = [$parsed['text'], $parsed['answer']];
		}
		$stop = match (true) {
			$calls !== [] => 'tools',
			$finish === 'MAX_TOKENS' => 'length',
			$finish === 'STOP' => 'end',
			default => strtolower($finish),
		};
		return new Turn($text, $calls, $answer, $used, stopReason: $stop);
	}

	/** Streamed chunks (each a whole GenerateContentResponse of the new parts) as one response. */
	private function stream(string $url, array $headers, array $body, callable $onDelta): array {
		$parts = [];
		$last = [];
		$result = $this->http->events($url, $headers, $body, $this->timeout(), function (string $event, array $data) use (&$parts, &$last, $onDelta): void {
			foreach ((array)($data['candidates'][0]['content']['parts'] ?? []) as $part) {
				if (isset($part['text']) && !($part['thought'] ?? false) && $part['text'] !== '') {
					$onDelta((string)$part['text']);
				}
				$parts[] = $part;
			}
			$last = $data;
		});
		if ($result['error'] !== '') {
			return $result;
		}
		if ($last === []) {
			return ['status' => 200, 'json' => null, 'body' => '', 'error' => Errors::provider('an empty stream')];
		}
		$last['candidates'][0]['content']['parts'] = $parts;
		return ['status' => 200, 'json' => $last, 'body' => '', 'error' => ''];
	}

	/** Request messages as Gemini's contents: user and model by turns, results as functionResponse. */
	private function contents(array $messages): array {
		$out = [];
		foreach ($messages as $message) {
			$role = $message['role'] === 'assistant' ? 'model' : 'user';
			$parts = [];
			$first = true;
			foreach ($message['parts'] as $part) {
				$converted = match ($part['kind']) {
					'text' => trim((string)$part['text']) !== '' ? ['text' => (string)$part['text']] : null,
					'image' => ['inlineData' => ['mimeType' => $part['type'], 'data' => $part['data']]],
					'document' => $this->flags['documents']
						? ['inlineData' => ['mimeType' => $part['type'], 'data' => $part['data']]]
						: ['text' => '[the document ' . ($part['name'] ?? '') . ' is not attached: this AI connection takes no documents]'],
					'tool_call' => ['functionCall' => (str_starts_with((string)$part['id'], 'g-') ? [] : ['id' => $part['id']])
						+ ['name' => $part['name'], 'args' => (object)$part['args']]] + $this->signature($part, $first),
					// Google's convention: the output under "output", a failure under "error".
					'tool_result' => ['functionResponse' => (str_starts_with((string)$part['id'], 'g-') ? [] : ['id' => $part['id']])
						+ ['name' => $part['name'], 'response' => [($part['error'] ?? false) ? 'error' : 'output' => (string)$part['content']]]],
					default => null,
				};
				if ($converted !== null) {
					$parts[] = $converted;
				}
			}
			if ($parts === []) {
				continue;
			}
			$end = count($out) - 1;
			if ($end >= 0 && $out[$end]['role'] === $role) {
				$out[$end]['parts'] = [...$out[$end]['parts'], ...$parts];
			} else {
				$out[] = ['role' => $role, 'parts' => $parts];
			}
		}
		return $out;
	}

	/** The thoughtSignature that goes back with the first call of a reply. */
	private function signature(array $part, bool &$first): array {
		$signature = $this->signatures[(string)$part['id']] ?? ($part['signature'] ?? null);
		if ($signature === null && $first && $this->generation() >= 3) {
			$signature = self::NO_SIGNATURE;
		}
		$first = false;
		return $signature !== null ? ['thoughtSignature' => $signature] : [];
	}

	/** A tool's input as a declaration's parameters; none for a tool without arguments (Gemini refuses empty objects). */
	private function parameters(array $input): array {
		$schema = $this->schema($input);
		return isset($schema['properties']) ? ['parameters' => $schema] : [];
	}

	/**
	 * A JSON schema in Gemini's subset: unknown words dropped, a type list made
	 * nullable or anyOf (Reply::lenient's ["integer", "string"]), enums as strings.
	 */
	private function schema(array $schema): array {
		$type = $schema['type'] ?? null;
		if (is_array($type)) {
			$types = array_values(array_filter($type, fn ($t) => $t !== 'null'));
			$nullable = count($types) < count($type);
			if (count($types) > 1) {
				$rest = $schema;
				unset($rest['type'], $rest['description']);
				return array_filter(['description' => $schema['description'] ?? null, 'nullable' => $nullable ?: null,
					'anyOf' => array_map(fn ($t) => $this->schema(['type' => $t] + ($t === 'object' || $t === 'array' ? $rest : array_intersect_key($rest, ['enum' => 1, 'format' => 1, 'minimum' => 1, 'maximum' => 1]))), $types)],
					fn ($v) => $v !== null);
			}
			$schema['type'] = $types[0] ?? 'string';
			if ($nullable) {
				$schema['nullable'] = true;
			}
		}
		$out = array_intersect_key($schema, array_flip(self::SCHEMA_WORDS));
		if (isset($out['enum'])) {
			$out['enum'] = array_values(array_map('strval', (array)$out['enum']));
			// Gemini's enums are of strings.
			$out['type'] = 'string';
		}
		if (isset($out['properties'])) {
			$out['properties'] = array_map(fn ($sub) => is_array($sub) ? $this->schema($sub) : ['type' => 'string'], (array)$out['properties']);
			if (isset($out['required'])) {
				$out['required'] = array_values(array_intersect((array)$out['required'], array_keys($out['properties'])));
			}
		}
		if (isset($out['items']) && is_array($out['items'])) {
			$out['items'] = $this->schema($out['items']);
		}
		if (isset($out['anyOf'])) {
			$out['anyOf'] = array_map(fn ($sub) => $this->schema((array)$sub), (array)$out['anyOf']);
		}
		if (($out['type'] ?? '') === 'object' && ($out['properties'] ?? []) === []) {
			unset($out['properties'], $out['required']);
		}
		return $out;
	}

	/** The model's major version (gemini-3-pro → 3), 0 when unknown. */
	private function generation(): int {
		return preg_match('/gemini-(\d+)/', $this->model(), $m) === 1 ? (int)$m[1] : 0;
	}

	private function model(): string {
		return preg_replace('#^models/#', '', (string)($this->settings['model'] ?? '')) ?? '';
	}

	private function timeout(): int {
		return max(10, (int)($this->settings['timeout'] ?? 600));
	}
}
