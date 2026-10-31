<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\IAppConfig;

/**
 * The AI connection's administration, for the settings page and occ: the engines
 * with their settings (a key only as set or not), the choice, the fallback chain,
 * the scenarios' overrides, a connection test and a tool-calling probe per
 * engine, and the use per day.
 */
class Admin {
	/** name => [label, kind (api, cli, nextcloud), settings, flags] */
	public const ENGINES = [
		'anthropic' => ['Anthropic API (Claude)', 'api', ['key', 'model', 'baseUrl', 'timeout', 'maxImages'], ['search', 'caching', 'thinking', 'documents', 'streaming', 'fallbacks']],
		'gemini' => ['Google Gemini API', 'api', ['key', 'model', 'baseUrl', 'timeout', 'maxImages'], ['search', 'documents', 'streaming', 'thinking']],
		'openai' => ['OpenAI-compatible (OpenAI, OpenRouter, Mistral, Ollama …)', 'api', ['key', 'model', 'baseUrl', 'headers', 'provider', 'timeout', 'maxImages'],
			['tools', 'images', 'structured', 'streaming', 'search', 'documents']],
		'claude-cli' => ['Claude Code on the server (subscription)', 'cli', ['binary', 'home', 'model', 'timeout', 'php', 'path', 'maxBudgetUsd'], ['mcp', 'search', 'streaming']],
		'gemini-cli' => ['Gemini CLI on the server (subscription)', 'cli', ['binary', 'home', 'model', 'timeout', 'path'], []],
		'taskprocessing' => ['Nextcloud\'s AI (TaskProcessing providers)', 'nextcloud', ['taskType', 'timeout'], []],
	];
	/** The scenarios the app registers, for their overrides: name => label. */
	public const SCENARIOS = ['chat' => 'Answers in Talk', '__fold' => 'Memory of a conversation', 'document' => 'Document jobs',
		'probe' => 'Tool-calling probe', 'assistant' => 'OCS API (assistant)', 'nextcloud:summary' => 'Nextcloud: summaries',
		'nextcloud:headline' => 'Nextcloud: headlines', 'nextcloud:chat' => 'Nextcloud: chat', 'nextcloud:chatwithtools' => 'Nextcloud: chat with tools',
		'nextcloud:text' => 'Nextcloud: free prompt'];

	public function __construct(
		private Engines $engines,
		private Config $config,
		private IAppConfig $appConfig,
		private ?Counters $counters = null,
		private ?Limits $limits = null,
	) {
	}

	/** @return list<array{name: string, label: string, kind: string, fields: list<string>, flags: list<string>, settings: array, keySet: bool, status: array, chosen: bool}> */
	public function engines(): array {
		$out = [];
		foreach (self::ENGINES as $name => [$label, $kind, $fields, $flags]) {
			$settings = $this->config->engine($name);
			unset($settings['key']);
			$out[] = ['name' => $name, 'label' => $label, 'kind' => $kind, 'fields' => $fields, 'flags' => $flags, 'settings' => $settings,
				'keySet' => $this->config->hasSecret($name, 'key'), 'status' => $this->statusOf($name),
				'chosen' => $this->engines->chosen() === $name];
		}
		return $out;
	}

	/** An engine's state; one that cannot even be built (a missing service) is not ready, and the page still shows. */
	private function statusOf(string $name): array {
		try {
			return $this->engines->get($name)->status()->toArray();
		} catch (\Throwable $e) {
			return Status::notReady(Errors::provider($e->getMessage()), $name)->toArray();
		}
	}

	/**
	 * An engine's settings, checked: only its own fields and flags; a key goes apart, encrypted.
	 *
	 * @return array<string, string> errors by field
	 */
	public function save(string $name, array $input): array {
		if (!isset(self::ENGINES[$name])) {
			return ['engine' => 'unknown engine'];
		}
		[, , $fields, $flags] = self::ENGINES[$name];
		$errors = [];
		$settings = [];
		foreach ($fields as $field) {
			if (!array_key_exists($field, $input) || $field === 'key') {
				continue;
			}
			$value = $input[$field];
			$settings[$field] = match ($field) {
				'timeout', 'maxImages' => $value === '' || $value === null ? null : max(1, min(86400, (int)$value)),
				'maxBudgetUsd' => $value === '' || $value === null ? null : max(0.0, (float)$value),
				'headers' => is_array($value) ? array_map('strval', $value) : (json_decode((string)$value, true) ?: null),
				default => trim((string)$value) === '' ? null : mb_substr(trim((string)$value), 0, 1000),
			};
			if (in_array($field, ['baseUrl'], true) && $settings[$field] !== null && preg_match('#^https?://#', (string)$settings[$field]) !== 1) {
				$errors[$field] = 'an http(s) URL';
				unset($settings[$field]);
			}
			if (in_array($field, ['binary', 'home', 'php'], true) && $settings[$field] !== null && !str_starts_with((string)$settings[$field], '/')
				&& !($field === 'binary' && preg_match('/^[\w.-]+$/', (string)$settings[$field]) === 1)) {
				$errors[$field] = 'an absolute path';
				unset($settings[$field]);
			}
		}
		if (isset($input['flags']) && is_array($input['flags'])) {
			$settings['flags'] = [];
			foreach ($flags as $flag) {
				$settings['flags'][$flag] = in_array($input['flags'][$flag] ?? true, [true, 'yes', '1', 1], true);
			}
		}
		$this->config->set($name, $settings);
		if (isset($input['key']) && is_string($input['key']) && $input['key'] !== '') {
			$this->config->setSecret($name, 'key', trim($input['key']));
		}
		return $errors;
	}

	public function clearKey(string $name): void {
		$this->config->setSecret($name, 'key', '');
	}

	/** The engine questions go to, and the ones that answer when it cannot. */
	public function choose(string $name, ?array $fallback = null): void {
		if (!isset(self::ENGINES[$name])) {
			throw new \InvalidArgumentException('unknown engine: ' . $name);
		}
		$this->appConfig->setValueString(Engines::APP, 'ai_engine', $name);
		if ($fallback !== null) {
			$chain = array_values(array_unique(array_filter($fallback, fn ($n) => isset(self::ENGINES[$n]) && $n !== $name)));
			$this->appConfig->setValueString(Engines::APP, 'ai_fallback', (string)json_encode($chain));
		}
	}

	/** @return array<string, array{engine?: string, model?: string, prompt?: string}> */
	public function scenarios(): array {
		$all = json_decode($this->appConfig->getValueString(Engines::APP, 'ai_scenarios', '{}'), true);
		return is_array($all) ? $all : [];
	}

	public function setScenarios(array $input): void {
		$clean = [];
		foreach ($input as $scenario => $choice) {
			if (!isset(self::SCENARIOS[$scenario]) || !is_array($choice)) {
				continue;
			}
			$entry = array_filter([
				'engine' => isset(self::ENGINES[$choice['engine'] ?? '']) ? (string)$choice['engine'] : '',
				'model' => mb_substr(trim((string)($choice['model'] ?? '')), 0, 200),
				'prompt' => mb_substr(trim((string)($choice['prompt'] ?? '')), 0, 4000),
			], fn ($v) => $v !== '');
			if ($entry !== []) {
				$clean[$scenario] = $entry;
			}
		}
		$this->appConfig->setValueString(Engines::APP, 'ai_scenarios', (string)json_encode((object)$clean, JSON_UNESCAPED_UNICODE));
	}

	/** @return list<string> the engine's models, from the provider */
	public function models(string $name): array {
		return $this->engines->get($name)->models();
	}

	/** One short question: whether the engine answers, how fast, and what it said. */
	public function test(string $name): array {
		$engine = $this->engines->get($name);
		$status = $engine->status();
		if (!$status->ready) {
			return ['ok' => false, 'error' => $status->reason, 'status' => $status->toArray()];
		}
		$started = microtime(true);
		$turn = $engine->turn(new Request('You answer connection tests.', [Request::message('user', [Request::text('Answer with the word OK.')])], maxOutputTokens: 64));
		return ['ok' => $turn->ok(), 'error' => $turn->error, 'text' => mb_substr($turn->text, 0, 200), 'ms' => (int)((microtime(true) - $started) * 1000),
			'usage' => $turn->usage->toArray(), 'status' => $status->toArray()];
	}

	/** Whether the engine's model calls a tool: one question with the tool ping, through Loop. */
	public function probe(string $name): array {
		$called = false;
		$tools = ['ping' => ['description' => 'Answers pong.', 'input' => ['type' => 'object'], 'run' => function () use (&$called): string {
			$called = true;
			return 'pong';
		}]];
		$request = new Request('You test whether tools can be called.', [Request::message('user', [Request::text('Call the tool ping once, then answer with the word done.')])],
			['ping' => ['description' => 'Answers pong.', 'input' => ['type' => 'object']]]);
		$result = (new Loop($this->engines->get($name)))->run('admin', $request, $tools, ['maxTools' => 2, 'retries' => 0]);
		return ['ok' => $called, 'error' => $result['error'], 'text' => mb_substr($result['text'], 0, 200)];
	}

	/** The use per day and engine, and the questions running now. */
	public function usage(int $days = 30): array {
		return ['days' => $this->counters?->days($days) ?? [], 'running' => $this->limits?->running() ?? 0];
	}
}
