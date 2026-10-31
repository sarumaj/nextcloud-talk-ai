<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/**
 * Runs one question to its answer: a turn, the tools it asked for (closures that
 * run with the asking person's rights), their results back, and again, until the
 * model calls no tool, the caller's budget is used, or it repeats itself.
 *
 * The caller sets the budget (AI-Hub's fixed eight calls made the document jobs ask
 * in segments). A failing tool is reported to the model, not thrown. A busy or
 * failing provider is asked again after a pause, the same turn only: asking the
 * whole question again would run its tools (a calendar entry) twice.
 */
final class Loop {
	/** Characters of one tool result the model gets. */
	public const RESULT_CHARS = 100000;
	/** How often one call (tool and arguments alike) runs before it is refused. */
	public const REPEATS = 3;

	public function __construct(
		private Engine $engine,
	) {
	}

	/**
	 * @param array<string, array{description: string, input: array, run: callable(string, array): (string|array)}> $tools
	 * @param array{maxTools?: int, resultChars?: int, repeatable?: list<string>, retries?: int,
	 *        heartbeat?: callable(int $round, int $calls): bool, afterTools?: callable(list<array> $ran): list<array>,
	 *        pause?: callable(int $seconds): void, onRetry?: callable(string $error, int $attempt): void} $options
	 *        maxTools: tool calls in all (default 20); the turn after the last one may only answer.
	 *        heartbeat: before each turn; false cancels. afterTools: parts (text, images) for the message with the
	 *        results, e.g. the pages a render made. A tool may also return ['text' => …, 'images' => [{type, data}]].
	 * @return array{state: string, text: string, answer: mixed, tools: list<string>, calls: list<array>, usage: Usage,
	 *        messages: list<array>, error: string, stop: string, session: mixed, rounds: int}
	 *        state done or error; stop end, max-tools, repeat, cancelled or error
	 */
	public function run(string $userId, Request $request, array $tools, array $options = []): array {
		$status = $this->engine->status();
		$maxTools = max(0, (int)($options['maxTools'] ?? 20));
		$resultChars = max(1000, (int)($options['resultChars'] ?? self::RESULT_CHARS));
		$repeatable = (array)($options['repeatable'] ?? []);
		$messages = $request->messages;
		$session = $request->session;
		$usage = new Usage();
		$ran = [];
		$calls = [];
		$seen = [];
		$askedForShape = false;
		$lastText = '';
		$final = false;
		$round = 0;
		// By reference: the result is what the run has come to when it ends.
		$out = function (string $state, string $stop, string $text = '', mixed $answer = null, string $error = '') use (&$ran, &$calls, &$usage, &$messages, &$session, &$round): array {
			return ['state' => $state, 'text' => $text, 'answer' => $answer, 'tools' => $ran, 'calls' => $calls, 'usage' => $usage,
				'messages' => $messages, 'error' => $error, 'stop' => $stop, 'session' => $session, 'rounds' => $round + 1];
		};
		for ($round = 0; ; $round++) {
			if (isset($options['heartbeat']) && ($options['heartbeat'])($round, count($ran)) === false) {
				return $out('error', 'cancelled', $lastText, error: Errors::CANCELLED);
			}
			$messages = self::fewerImages($messages, $status->images ? max(1, $status->maxImages) : 0);
			$turn = $this->turn($request->with($messages, $session), $options);
			$usage = $usage->add($turn->usage->reported ? $turn->usage : self::estimate($request, $messages, $turn));
			if (!$turn->ok()) {
				return $out('error', 'error', $lastText, error: $turn->error);
			}
			$session = $turn->session ?? $session;
			$wanted = $turn->toolCalls;
			$stray = false;
			$text = $turn->text;
			// A call written as text (the model lost the tool format): run all the same, and say so.
			if ($wanted === [] && $tools !== []) {
				[$rest, $wanted] = TextProtocol::calls($text, array_keys($tools));
				$stray = $wanted !== [];
				$text = $stray ? trim($rest) : $text;
			}
			$lastText = trim($text) !== '' ? trim($text) : $lastText;
			if ($wanted === []) {
				$answer = $turn->answer;
				if ($request->answerSchema !== null) {
					$fail = $answer === null ? 'there was no answer block' : Schema::check($answer, $request->answerSchema);
					if ($fail !== '') {
						if ($askedForShape) {
							return $out('error', 'error', $turn->text, error: Errors::BAD_ANSWER);
						}
						$askedForShape = true;
						$messages[] = $turn->message();
						$messages[] = Request::message('user', [Request::text('Your answer does not fit the schema (' . $fail . '). Answer again, fitting it.')]);
						continue;
					}
				}
				$messages[] = $turn->message();
				return $out('done', 'end', trim($turn->text), $answer);
			}
			$messages[] = $stray ? Request::message('assistant', [Request::text($turn->text)]) : $turn->message();
			$results = [];
			$extra = [];
			$stop = '';
			foreach ($wanted as $call) {
				$name = (string)$call['name'];
				$args = is_array($call['args']) ? $call['args'] : [];
				$key = $name . ' ' . json_encode($args);
				$seen[$key] = ($seen[$key] ?? 0) + 1;
				if (count($ran) >= $maxTools) {
					[$content, $error] = ['Not run: the tool calls for this question are used up. Answer now with what you have.', true];
					$stop = 'max-tools';
				} elseif ($seen[$key] > self::REPEATS + 1 && !in_array($name, $repeatable, true)) {
					[$content, $error] = ['Refused again: this exact call ran three times already.', true];
					$stop = 'repeat';
				} elseif ($seen[$key] > self::REPEATS && !in_array($name, $repeatable, true)) {
					[$content, $error] = ['Refused: this exact call ran three times already. Do something different (read the error, change the input).', true];
				} else {
					[$content, $images, $error] = $this->call($userId, $tools, $name, $args);
					$extra = [...$extra, ...$images];
					$ran[] = $name;
				}
				$content = self::cut($content, $resultChars);
				$calls[] = ['name' => $name, 'args' => $args, 'result' => mb_substr($content, 0, 2000), 'error' => $error];
				$results[] = Request::toolResult((string)$call['id'], $name, $content, $error);
			}
			if (isset($options['afterTools'])) {
				$extra = [...$extra, ...($options['afterTools'])($calls)];
			}
			if ($stray) {
				// No tool_use block to answer: the results go back as words.
				$messages[] = Request::message('user', [Request::text('Your last tool call came as plain text; it was run anyway. '
					. "Use the tool call format for the next ones.\n" . TextProtocol::results($results)), ...$extra]);
			} else {
				$messages[] = Request::message('tool', [...$results, ...$extra]);
			}
			if ($stop === 'repeat') {
				return $out('error', 'repeat', $lastText, error: 'repeat');
			}
			// One more turn after the budget, to answer with what the tools gave; a further call ends it.
			if ($stop === 'max-tools' && $final) {
				return $out('error', 'max-tools', $lastText, error: 'max-tools');
			}
			$final = $final || $stop === 'max-tools';
		}
	}

	/** One turn, asked again after a pause while the provider is busy or failing. */
	private function turn(Request $request, array $options): Turn {
		$retries = max(0, (int)($options['retries'] ?? 3));
		for ($attempt = 0; ; $attempt++) {
			$turn = $this->engine->turn($request);
			if ($turn->ok() || $attempt >= $retries || !Errors::transient($turn->error)) {
				return $turn;
			}
			if (isset($options['onRetry'])) {
				($options['onRetry'])($turn->error, $attempt + 1);
			}
			isset($options['pause']) ? ($options['pause'])(5 * 2 ** $attempt) : sleep(5 * 2 ** $attempt);
		}
	}

	/** @return array{0: string, 1: list<array>, 2: bool} content, image parts, error */
	private function call(string $userId, array $tools, string $name, array $args): array {
		$tool = $tools[$name] ?? null;
		if ($tool === null) {
			return ['There is no tool named ' . json_encode($name) . '. The tools are: ' . implode(', ', array_keys($tools)) . '.', [], true];
		}
		$fail = Schema::check($args, (array)($tool['input'] ?? []));
		if ($fail !== '') {
			return ['The arguments do not fit the tool ' . $name . ': ' . $fail, [], true];
		}
		try {
			$got = ($tool['run'])($userId, $args);
		} catch (\Throwable $e) {
			return ['The tool ' . $name . ' failed: ' . $e->getMessage(), [], true];
		}
		if (is_array($got) && isset($got['images']) && is_array($got['images'])) {
			return [(string)($got['text'] ?? ''), array_map(fn (array $i) => Request::image((string)$i['type'], (string)$i['data']), $got['images']), false];
		}
		return [is_string($got) ? $got : (string)json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), [], false];
	}

	private static function cut(string $text, int $chars): string {
		$length = mb_strlen($text);
		return $length <= $chars ? $text : mb_substr($text, 0, $chars) . "\n[… cut here: " . ($length - $chars) . ' more characters]';
	}

	/**
	 * Only the latest images stay attached (the engine's limit); older ones become a
	 * note. Earlier images are what the model already looked at, and a provider refuses
	 * a request with more than it takes. Over the limit, half of it stays: the history
	 * changes once every few renders, not with each, so the prompt cache serves the rest.
	 */
	public static function fewerImages(array $messages, int $limit): array {
		$count = 0;
		foreach ($messages as $message) {
			$count += count(array_filter($message['parts'], fn (array $p) => ($p['kind'] ?? '') === 'image'));
		}
		if ($count <= $limit) {
			return $messages;
		}
		$keep = $limit > 1 ? intdiv($limit, 2) : $limit;
		for ($m = count($messages) - 1; $m >= 0; $m--) {
			foreach ($messages[$m]['parts'] as $p => $part) {
				if (($part['kind'] ?? '') !== 'image') {
					continue;
				}
				if ($keep > 0) {
					$keep--;
					continue;
				}
				$messages[$m]['parts'][$p] = Request::text('[an image shown earlier, no longer attached]');
			}
		}
		return $messages;
	}

	/** Tokens when the provider reports none: characters by four, about 1 500 per image. */
	private static function estimate(Request $request, array $messages, Turn $turn): Usage {
		$chars = mb_strlen($request->system) + mb_strlen((string)json_encode($request->tools, JSON_UNESCAPED_UNICODE));
		$images = 0;
		foreach ($messages as $message) {
			foreach ($message['parts'] as $part) {
				match ($part['kind'] ?? '') {
					'image', 'document' => $images++,
					'text' => $chars += mb_strlen((string)$part['text']),
					'tool_result' => $chars += mb_strlen((string)$part['content']),
					'tool_call' => $chars += mb_strlen((string)json_encode($part['args'], JSON_UNESCAPED_UNICODE)),
					default => null,
				};
			}
		}
		return new Usage(intdiv($chars, 4) + $images * 1500, intdiv(mb_strlen($turn->text) + mb_strlen((string)json_encode($turn->toolCalls)), 4));
	}
}
