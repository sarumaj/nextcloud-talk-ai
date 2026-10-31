<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/**
 * What the app asks instead of a model. A scenario says what the assistant is and
 * which tools it may run; ask() runs a question through Loop on the chosen engine
 * and gives the result in the shape the app has used since AI-Hub:
 *
 *     $hub->registerScenario('helper', [
 *         'system' => 'You are …',
 *         'tools' => ['lookup' => ['description' => '…', 'input' => [JSON schema], 'run' => fn (string $uid, array $args) => …]],
 *         'search' => true,              // web search, where the engine has it
 *         'answer' => [JSON schema],     // a structured answer in 'answer'; optional
 *         'allow' => fn (string $uid) => true,   // who may ask; optional
 *         'maxTools' => 20,              // tool calls per question; options override
 *     ]);
 *     $result = $hub->ask($uid, 'helper', [['role' => 'user', 'text' => '…', 'images' => [...]]], ['search' => false]);
 *     // ['state' => 'done'|'error', 'text', 'answer', 'tools' => names run, 'usage' => [...], 'error']
 */
class Hub {
	/** The largest image the layer passes on (decoded bytes): what every provider takes. */
	public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
	public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

	/** @var array<string, array> name => spec */
	private array $scenarios = [];

	public function __construct(
		private Engines $engines,
	) {
	}

	/** @param array{system?: string, tools?: array, search?: bool, answer?: ?array, allow?: callable, maxTools?: int} $spec */
	public function registerScenario(string $name, array $spec): void {
		foreach ($spec['tools'] ?? [] as $tool => $def) {
			if (!is_callable($def['run'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string)$tool) !== 1) {
				throw new \InvalidArgumentException('tool ' . $tool . ': a name of letters, digits, _ and -, and a run callable');
			}
		}
		$this->scenarios[$name] = [
			'system' => (string)($spec['system'] ?? ''),
			'tools' => (array)($spec['tools'] ?? []),
			'search' => (bool)($spec['search'] ?? false),
			'answer' => $spec['answer'] ?? null,
			'allow' => $spec['allow'] ?? null,
			'maxTools' => (int)($spec['maxTools'] ?? 20),
		];
	}

	public function hasScenario(string $name): bool {
		return isset($this->scenarios[$name]);
	}

	/** The engine a scenario's questions go to. */
	public function engine(?string $scenario = null): Engine {
		return $this->engines->get();
	}

	public function status(?string $scenario = null): Status {
		return $this->engine($scenario)->status();
	}

	/**
	 * Asks and waits for the answer.
	 *
	 * @param list<array> $messages oldest first, the question last: {role, text, images?} as the app writes
	 *        them, or {role, parts} (Request)
	 * @param array{search?: bool, images?: list<array{type: string, data: string}>, context?: string, maxTools?: int,
	 *        thinking?: bool, onDelta?: callable, heartbeat?: callable, afterTools?: callable, onRetry?: callable,
	 *        pause?: callable, retries?: int, repeatable?: list<string>, resultChars?: int} $options
	 *        images go with the question; context goes before it, in the same message (the system prompt stays
	 *        the same for every question, so the provider's prompt cache serves it). The rest: Loop::run().
	 * @return array{state: string, text: string, answer: mixed, tools: list<string>, calls: list<array>,
	 *        usage: array, error: string, stop: string, messages: list<array>}
	 */
	public function ask(string $userId, string $scenario, array $messages, array $options = []): array {
		$spec = $this->scenarios[$scenario] ?? null;
		if ($spec === null) {
			return self::failed(Errors::NO_SCENARIO);
		}
		if (is_callable($spec['allow']) && !($spec['allow'])($userId)) {
			return self::failed(Errors::USER_NOT_ALLOWED);
		}
		$engine = $this->engine($scenario);
		$status = $engine->status();
		if (!$status->ready) {
			return self::failed($status->reason !== '' ? $status->reason : Errors::NOT_READY);
		}
		$messages = self::normalize($messages);
		$last = count($messages) - 1;
		if ($last < 0 || $messages[$last]['role'] !== 'user') {
			return self::failed(Errors::EMPTY);
		}
		$images = (array)($options['images'] ?? []);
		if (($why = self::refusedImages($images, $status)) !== '') {
			return self::failed($why);
		}
		$extra = array_map(fn (array $i) => Request::image((string)$i['type'], (string)$i['data']), $images);
		if (trim((string)($options['context'] ?? '')) !== '') {
			array_unshift($messages[$last]['parts'], Request::text("Context:\n" . trim((string)$options['context'])));
		}
		$messages[$last]['parts'] = [...$messages[$last]['parts'], ...$extra];
		if (array_filter($messages[$last]['parts'], fn (array $p) => $p['kind'] !== 'text' || trim((string)$p['text']) !== '') === []) {
			return self::failed(Errors::EMPTY);
		}
		$request = new Request(
			system: $spec['system'],
			messages: $messages,
			tools: array_map(fn (array $t) => ['description' => (string)($t['description'] ?? ''), 'input' => (array)($t['input'] ?? ['type' => 'object'])], $spec['tools']),
			search: ($options['search'] ?? $spec['search']) && $spec['search'] && $status->search,
			answerSchema: $spec['answer'],
			thinking: (bool)($options['thinking'] ?? false),
			onDelta: $status->streaming ? ($options['onDelta'] ?? null) : null,
		);
		$result = (new Loop($engine))->run($userId, $request, $spec['tools'], ['maxTools' => $spec['maxTools']] + $options);
		return ['usage' => $result['usage']->toArray()] + $result;
	}

	/** The app's {role, text, images?} messages as Request messages; {role, parts} pass as they are. */
	public static function normalize(array $messages): array {
		$out = [];
		foreach ($messages as $m) {
			$role = in_array($m['role'] ?? '', ['assistant', 'tool'], true) ? $m['role'] : 'user';
			if (isset($m['parts'])) {
				$out[] = ['role' => $role, 'parts' => array_values($m['parts'])];
				continue;
			}
			$parts = trim((string)($m['text'] ?? '')) !== '' ? [Request::text((string)$m['text'])] : [];
			foreach ((array)($m['images'] ?? []) as $image) {
				$parts[] = Request::image((string)$image['type'], (string)$image['data']);
			}
			$out[] = Request::message($role, $parts);
		}
		// An empty message says nothing, except the question: its images may come with the options.
		$last = array_pop($out);
		$out = array_values(array_filter($out, fn (array $m) => $m['parts'] !== []));
		return $last === null ? $out : [...$out, $last];
	}

	/** Why these images cannot go with a question, or ''. */
	private static function refusedImages(array $images, Status $status): string {
		if ($images === []) {
			return '';
		}
		if (!$status->images) {
			return Errors::NO_IMAGES;
		}
		if (count($images) > $status->maxImages) {
			return Errors::TOO_MANY_IMAGES;
		}
		foreach ($images as $image) {
			if (!in_array($image['type'] ?? '', self::IMAGE_TYPES, true)) {
				return Errors::IMAGE_TYPE;
			}
			if (intdiv(strlen((string)($image['data'] ?? '')) * 3, 4) > self::MAX_IMAGE_BYTES) {
				return Errors::IMAGE_TOO_LARGE;
			}
		}
		return '';
	}

	private static function failed(string $error): array {
		return ['state' => 'error', 'text' => '', 'answer' => null, 'tools' => [], 'calls' => [], 'usage' => (new Usage())->toArray(),
			'error' => $error, 'stop' => 'error', 'messages' => []];
	}
}
