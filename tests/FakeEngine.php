<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\TextProtocol;
use OCA\TalkAi\Ai\Turn;
use OCA\TalkAi\Ai\Usage;
use OCP\IAppConfig;

/**
 * A scripted model behind the real Hub and Loop. Each segment is a function that
 * calls tools as the model would, $call(name, args) giving the tool's result, and
 * returns the model's words; a segment runs as a Fiber, so each call becomes a
 * turn with a tool call and the result comes back from Loop in the next turn. A
 * segment starts with each question the model answers in words (Loop's end).
 * The probe (one tool, ping) is answered by itself.
 */
class FakeEngine implements Engine {
	/** @var list<array{messages: list<array{role: string, text: string, images: list<array>}>}> the conversation as each segment began */
	public array $asks = [];
	/** @var list<Request> every turn's request */
	public array $requests = [];
	/** @var list<array{name: string, content: string, text: string, images: list<array>}> tool results as Loop gave them back */
	public array $results = [];
	/** @var list<string> error words to answer with first, one per turn (not the probe's) */
	public array $errors = [];
	public int $probes = 0;
	private ?\Fiber $fiber = null;
	private int $calls = 0;

	/** @param list<callable(callable(string, array): string): string> $segments */
	public function __construct(public array $segments, public bool $toolsWork = true, public bool $images = true, public ?Usage $usage = null) {
	}

	/** A Hub that asks this engine. */
	public function hub(IAppConfig $appConfig): Hub {
		$engines = new Engines($appConfig);
		$engines->fix($this);
		return new Hub($engines);
	}

	public function status(): Status {
		return new Status(true, '', 'test', 'm1', $this->images, $this->images ? 4 : 0, false, engine: 'fake');
	}

	public function models(): array {
		return ['m1'];
	}

	public function turn(Request $request): Turn {
		$this->requests[] = $request;
		$usage = $this->usage ?? new Usage();
		$messages = $request->messages;
		$last = end($messages);
		if (array_keys($request->tools) === ['ping']) {
			$this->probes++;
			$answered = Request::parts($last, 'tool_result') !== [];
			return $this->toolsWork && !$answered ? new Turn(toolCalls: [['id' => 'p1', 'name' => 'ping', 'args' => []]], usage: $usage) : new Turn('done', usage: $usage);
		}
		if ($this->errors !== []) {
			return Turn::failed(array_shift($this->errors));
		}
		if ($this->fiber !== null && $last['role'] === 'tool') {
			$result = Request::parts($last, 'tool_result')[0];
			$this->results[] = ['name' => $result['name'], 'content' => $result['content'], 'text' => Request::textOf($last),
				'images' => Request::parts($last, 'image')];
			$value = $this->fiber->resume($result['content']);
		} else {
			$this->asks[] = ['messages' => TextProtocol::flatten($request->messages)];
			$segment = array_shift($this->segments) ?? fn () => 'nothing to do';
			$this->fiber = new \Fiber(fn () => $segment(fn (string $name, array $args) => \Fiber::suspend([$name, $args])));
			$value = $this->fiber->start();
		}
		if ($this->fiber->isTerminated()) {
			$text = (string)$this->fiber->getReturn();
			$this->fiber = null;
			return new Turn($text, usage: $usage);
		}
		return new Turn(toolCalls: [['id' => 'c' . ++$this->calls, 'name' => $value[0], 'args' => $value[1]]], usage: $usage, stopReason: 'tools');
	}
}
