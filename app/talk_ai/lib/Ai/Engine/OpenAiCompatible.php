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
use OCA\TalkAi\Ai\TextProtocol;
use OCA\TalkAi\Ai\Turn;
use OCA\TalkAi\Ai\Usage;

/**
 * Chat completions (POST {base}/chat/completions), the API OpenAI, OpenRouter,
 * DeepSeek, Mistral, Groq, Ollama, vLLM and LM Studio share. Servers differ in
 * what they really do, so the administrator's flags decide: without "tools" the
 * tools go by TextProtocol, without "structured" the answer block too.
 *
 * Settings: baseUrl, key (optional: local servers need none), model, headers
 * (extra, e.g. OpenRouter's HTTP-Referer), timeout, maxImages, provider, flags
 * {tools, images, structured, streaming, search, documents}.
 */
final class OpenAiCompatible implements Engine {
	private const BASE = 'https://api.openai.com/v1';
	/** Hosts that refuse a request without a key; any other may be a local server. */
	private const KEYED = ['api.openai.com' => 'openai', 'openrouter.ai' => 'openrouter', 'api.deepseek.com' => 'deepseek',
		'api.mistral.ai' => 'mistral', 'api.groq.com' => 'groq'];

	private string $base;
	private string $host;
	private array $flags;

	public function __construct(
		private Http $http,
		private array $settings,
	) {
		$this->base = rtrim((string)($settings['baseUrl'] ?? '') ?: self::BASE, '/');
		$this->host = strtolower((string)parse_url($this->base, PHP_URL_HOST));
		$this->flags = (array)($settings['flags'] ?? []) + ['tools' => true, 'images' => true, 'structured' => true, 'streaming' => true,
			'search' => false, 'documents' => $this->host === 'api.openai.com'];
	}

	public function status(): Status {
		$provider = $this->provider();
		$model = (string)($this->settings['model'] ?? '');
		if ($model === '') {
			return Status::notReady(Errors::NO_MODEL, 'openai', $provider);
		}
		if (isset(self::KEYED[$this->host]) && (string)($this->settings['key'] ?? '') === '') {
			return Status::notReady(Errors::NO_KEY, 'openai', $provider, $model);
		}
		$images = (bool)$this->flags['images'];
		return new Status(true, '', $provider, $model, $images, $images ? max(1, (int)($this->settings['maxImages'] ?? 10)) : 0,
			(bool)$this->flags['search'], (bool)$this->flags['documents'], (bool)$this->flags['structured'], (bool)$this->flags['streaming'],
			// Prompt caching is automatic where the provider has it (OpenAI, DeepSeek); usage says what it served.
			true, 'openai');
	}

	public function turn(Request $request): Turn {
		$native = (bool)$this->flags['tools'];
		$structured = (bool)$this->flags['structured'] && $request->answerSchema !== null;
		$system = $request->system;
		$text = (!$native ? TextProtocol::instructions($request->tools, null) : '')
			. ($request->answerSchema !== null && !$structured ? "\n\n" . TextProtocol::instructions([], $request->answerSchema) : '');
		if (trim($text) !== '') {
			$system = trim($system . "\n\n" . trim($text));
		}
		$body = [
			'model' => (string)$this->settings['model'],
			'messages' => [['role' => 'system', 'content' => $system], ...($native ? $this->messages($request->messages) : $this->flat($request->messages))],
			// OpenAI's newer models take only max_completion_tokens; the other servers know max_tokens.
			($this->host === 'api.openai.com' ? 'max_completion_tokens' : 'max_tokens') => $request->maxOutputTokens,
		];
		if ($native && $request->tools !== []) {
			$body['tools'] = array_values(array_map(fn (string $name, array $t) => ['type' => 'function', 'function' => [
				'name' => $name, 'description' => $t['description'], 'parameters' => Schema::object($t['input']),
			]], array_keys($request->tools), $request->tools));
		}
		if ($structured) {
			$body['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'answer', 'schema' => Schema::object($request->answerSchema), 'strict' => false]];
		}
		if ($request->search && $this->flags['search']) {
			// OpenRouter searches with its web plugin; OpenAI's search models take web_search_options.
			$body += $this->host === 'openrouter.ai' ? ['plugins' => [['id' => 'web']]] : ['web_search_options' => (object)[]];
		}
		$stream = $request->onDelta !== null && $this->flags['streaming'];
		$answer = $stream ? $this->stream($body, $request->onDelta) : $this->http->json('POST', $this->base . '/chat/completions', $this->headers(), $body, $this->timeout());
		if ($answer['error'] !== '') {
			return Turn::failed($answer['error']);
		}
		$choice = $answer['json']['choices'][0] ?? null;
		if (!is_array($choice) || !is_array($choice['message'] ?? null)) {
			return Turn::failed(isset($answer['json']['error']) ? Errors::provider((string)($answer['json']['error']['message'] ?? 'error')) : Errors::provider('no answer in the response'));
		}
		return $this->turnOf($choice, (array)($answer['json']['usage'] ?? []), $request, $native, $structured);
	}

	public function models(): array {
		$answer = $this->http->json('GET', $this->base . '/models', $this->headers(), null, 30);
		$ids = array_values(array_filter(array_map(fn ($m) => is_array($m) ? (string)($m['id'] ?? '') : '', (array)($answer['json']['data'] ?? [])), 'strlen'));
		sort($ids);
		return $ids;
	}

	private function turnOf(array $choice, array $usage, Request $request, bool $native, bool $structured): Turn {
		$message = $choice['message'];
		$content = is_string($message['content'] ?? null) ? $message['content'] : '';
		$calls = [];
		foreach ((array)($message['tool_calls'] ?? []) as $i => $call) {
			$arguments = $call['function']['arguments'] ?? '{}';
			// Ollama hands the arguments as an object, the others as a JSON string.
			$args = is_array($arguments) ? $arguments : json_decode((string)$arguments, true);
			$calls[] = ['id' => (string)($call['id'] ?? '') ?: 'call_' . ($i + 1), 'name' => (string)($call['function']['name'] ?? ''),
				'args' => is_array($args) ? $args : []];
		}
		$answer = null;
		if (!$native || ($request->answerSchema !== null && !$structured)) {
			$parsed = TextProtocol::parse($content, $native ? [] : array_keys($request->tools));
			$content = $parsed['text'];
			$calls = $native ? $calls : $parsed['calls'];
			$answer = $parsed['answer'];
		}
		if ($structured && $calls === []) {
			$answer = json_decode(trim($content), true);
		}
		$stop = match ((string)($choice['finish_reason'] ?? 'stop')) {
			'tool_calls', 'function_call' => 'tools',
			'length' => 'length',
			'content_filter' => 'refusal',
			default => $calls !== [] ? 'tools' : 'end',
		};
		$used = new Usage((int)($usage['prompt_tokens'] ?? 0), (int)($usage['completion_tokens'] ?? 0),
			(int)($usage['prompt_tokens_details']['cached_tokens'] ?? $usage['prompt_cache_hit_tokens'] ?? 0),
			isset($usage['cost']) ? (float)$usage['cost'] : null, isset($usage['prompt_tokens']));
		if ($content === '' && $calls === [] && is_string($message['refusal'] ?? null)) {
			$content = $message['refusal'];
		}
		return new Turn($content, $calls, $answer, $used, stopReason: $stop);
	}

	/** A streamed answer put together as the non-streamed one would be. */
	private function stream(array $body, callable $onDelta): array {
		$body['stream'] = true;
		$body['stream_options'] = ['include_usage' => true];
		$content = '';
		$calls = [];
		$finish = null;
		$usage = [];
		$failed = '';
		$result = $this->http->events($this->base . '/chat/completions', $this->headers(), $body, $this->timeout(),
			function (string $event, array $data) use (&$content, &$calls, &$finish, &$usage, &$failed, $onDelta): void {
				if (isset($data['error'])) {
					$failed = Errors::provider((string)($data['error']['message'] ?? 'error'));
					return;
				}
				if (is_array($data['usage'] ?? null)) {
					$usage = $data['usage'];
				}
				$choice = $data['choices'][0] ?? null;
				if (!is_array($choice)) {
					return;
				}
				$delta = (array)($choice['delta'] ?? []);
				if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
					$content .= $delta['content'];
					$onDelta($delta['content']);
				}
				// Tool calls come in pieces by index: the id and name first, the arguments in parts.
				foreach ((array)($delta['tool_calls'] ?? []) as $piece) {
					$i = (int)($piece['index'] ?? count($calls));
					$calls[$i] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
					$calls[$i]['id'] = (string)($piece['id'] ?? '') ?: $calls[$i]['id'];
					$calls[$i]['function']['name'] .= (string)($piece['function']['name'] ?? '');
					$arguments = $piece['function']['arguments'] ?? '';
					$calls[$i]['function']['arguments'] .= is_array($arguments) ? json_encode($arguments) : (string)$arguments;
				}
				$finish = $choice['finish_reason'] ?? $finish;
			});
		if ($result['error'] !== '' || $failed !== '') {
			return $result['error'] !== '' ? $result : ['status' => 200, 'json' => null, 'body' => '', 'error' => $failed];
		}
		ksort($calls);
		return ['status' => 200, 'body' => '', 'error' => '', 'json' => [
			'choices' => [['message' => ['content' => $content, 'tool_calls' => array_values($calls)], 'finish_reason' => $finish]],
			'usage' => $usage,
		]];
	}

	/** Request messages in the API's form. */
	private function messages(array $messages): array {
		$out = [];
		foreach ($messages as $message) {
			if ($message['role'] === 'assistant') {
				$calls = array_map(fn (array $c) => ['id' => $c['id'], 'type' => 'function',
					'function' => ['name' => $c['name'], 'arguments' => json_encode((object)$c['args'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
					Request::parts($message, 'tool_call'));
				$text = Request::textOf($message);
				$out[] = ['role' => 'assistant', 'content' => $text !== '' ? $text : null] + ($calls !== [] ? ['tool_calls' => $calls] : []);
				continue;
			}
			// One tool message per result; what came with them (a rendered page) after them, from the user.
			foreach (Request::parts($message, 'tool_result') as $result) {
				$out[] = ['role' => 'tool', 'tool_call_id' => $result['id'], 'content' => (string)$result['content']];
			}
			$content = $this->content(array_values(array_filter($message['parts'], fn (array $p) => $p['kind'] !== 'tool_result')));
			if ($content !== null) {
				$out[] = ['role' => 'user', 'content' => $content];
			}
		}
		return $out;
	}

	/** Without native tools: plain turns, calls and results written as TextProtocol. */
	private function flat(array $messages): array {
		$out = [];
		foreach (TextProtocol::flatten($messages) as $turn) {
			$parts = [Request::text($turn['text'])];
			foreach ($turn['images'] as $image) {
				$parts[] = Request::image($image['type'], $image['data']);
			}
			foreach ($turn['documents'] as $document) {
				$parts[] = $document;
			}
			$out[] = ['role' => $turn['role'], 'content' => $turn['role'] === 'assistant' ? $turn['text'] : ($this->content($parts) ?? '')];
		}
		return $out;
	}

	/** A user message's parts: a plain string where it is only text (some servers take nothing else). */
	private function content(array $parts): string|array|null {
		$out = [];
		foreach ($parts as $part) {
			$out[] = match ($part['kind']) {
				'text' => ['type' => 'text', 'text' => (string)$part['text']],
				'image' => $this->flags['images']
					? ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $part['type'] . ';base64,' . $part['data']]]
					: ['type' => 'text', 'text' => '[an image, not shown: this AI connection takes no images]'],
				'document' => $this->flags['documents']
					? ['type' => 'file', 'file' => ['filename' => (string)($part['name'] ?? 'document.pdf'), 'file_data' => 'data:' . $part['type'] . ';base64,' . $part['data']]]
					: ['type' => 'text', 'text' => '[the document ' . ($part['name'] ?? '') . ' is not attached: this AI connection takes no documents]'],
				default => null,
			};
		}
		$out = array_values(array_filter($out));
		if ($out === []) {
			return null;
		}
		return array_filter($out, fn (array $p) => $p['type'] !== 'text') === [] ? implode("\n\n", array_column($out, 'text')) : $out;
	}

	private function headers(): array {
		$key = (string)($this->settings['key'] ?? '');
		return ($key !== '' ? ['Authorization' => 'Bearer ' . $key] : []) + array_map('strval', (array)($this->settings['headers'] ?? []));
	}

	private function timeout(): int {
		return max(10, (int)($this->settings['timeout'] ?? 600));
	}

	private function provider(): string {
		if (($this->settings['provider'] ?? '') !== '') {
			return (string)$this->settings['provider'];
		}
		return self::KEYED[$this->host] ?? (str_contains($this->base, ':11434') || str_contains($this->host, 'ollama') ? 'ollama' : 'openai-compatible');
	}
}
