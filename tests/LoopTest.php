<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Loop;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\Turn;
use OCA\TalkAi\Ai\Usage;
use PHPUnit\Framework\TestCase;

/** An engine that answers with the turns it was given, in order, and keeps the requests. */
class ScriptEngine implements Engine {
	/** @var list<Request> */
	public array $requests = [];

	/** @param list<Turn|callable(Request): Turn> $turns */
	public function __construct(public array $turns, public int $maxImages = 4) {
	}

	public function status(): Status {
		return new Status(true, '', 'test', 'm1', $this->maxImages > 0, $this->maxImages, true, engine: 'script');
	}

	public function turn(Request $request): Turn {
		$this->requests[] = $request;
		$next = array_shift($this->turns) ?? new Turn('the end');
		return $next instanceof Turn ? $next : $next($request);
	}

	public function models(): array {
		return [];
	}
}

/** The tool loop: rounds, guards, limits and what goes back to the model. */
class LoopTest extends TestCase {
	private array $ran = [];

	private function tools(): array {
		return [
			'add' => ['description' => 'Adds.', 'input' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']], 'required' => ['a', 'b']],
				'run' => function (string $uid, array $args) {
					$this->ran[] = ['add', $uid, $args];
					return (string)($args['a'] + $args['b']);
				}],
			'big' => ['description' => 'A long result.', 'input' => ['type' => 'object'], 'run' => fn () => str_repeat('x', 5000)],
			'fail' => ['description' => 'Fails.', 'input' => ['type' => 'object'], 'run' => fn () => throw new \RuntimeException('disk full')],
			'look' => ['description' => 'An image.', 'input' => ['type' => 'object'], 'run' => fn () => ['text' => 'a chart', 'images' => [['type' => 'image/png', 'data' => 'UE5H']]]],
		];
	}

	private static function call(string $name, array $args = [], string $id = 'c1'): Turn {
		return new Turn(toolCalls: [['id' => $id, 'name' => $name, 'args' => $args]], stopReason: 'tools');
	}

	private static function last(Request $request): array {
		$messages = $request->messages;
		return end($messages);
	}

	private static function request(): Request {
		return new Request('You add.', [Request::message('user', [Request::text('What is 2 + 3?')])], ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object']]]);
	}

	private function loop(ScriptEngine $engine, array $options = []): array {
		return (new Loop($engine))->run('ewa', self::request(), $this->tools(), $options + ['pause' => function () {
		}]);
	}

	public function testAPlainAnswer(): void {
		$engine = new ScriptEngine([new Turn('Five.', usage: new Usage(100, 5, 80, reported: true))]);
		$result = $this->loop($engine);
		$this->assertSame('done', $result['state']);
		$this->assertSame('Five.', $result['text']);
		$this->assertSame([], $result['tools']);
		$this->assertSame(80, $result['usage']->cachedIn);
		$this->assertCount(1, $engine->requests);
	}

	public function testTwoToolRoundsWithTheAskingPersonsRights(): void {
		$engine = new ScriptEngine([self::call('add', ['a' => 2, 'b' => 3]), self::call('add', ['a' => 5, 'b' => 1], 'c2'), new Turn('Six.')]);
		$result = $this->loop($engine);
		$this->assertSame('Six.', $result['text']);
		$this->assertSame(['add', 'add'], $result['tools']);
		$this->assertSame('ewa', $this->ran[0][1]);
		// The second turn saw the first call and its result, paired by id.
		$messages = $engine->requests[1]->messages;
		$this->assertSame('assistant', $messages[1]['role']);
		$this->assertSame('c1', Request::parts($messages[1], 'tool_call')[0]['id']);
		$result1 = Request::parts($messages[2], 'tool_result')[0];
		$this->assertSame(['tool', 'c1', '5', false], [$messages[2]['role'], $result1['id'], $result1['content'], $result1['error']]);
		// The history ends with the answer, for a caller that goes on.
		$this->assertSame('Six.', Request::textOf(end($result['messages'])));
	}

	public function testAToolCallWrittenAsTextIsRunAndTheModelIsTold(): void {
		$engine = new ScriptEngine([new Turn('Let me add. {"tool": "add", "args": {"a": 2, "b": 3}}'), new Turn('Five.')]);
		$result = $this->loop($engine);
		$this->assertSame(['add'], $result['tools']);
		$last = self::last($engine->requests[1]);
		$this->assertSame('user', $last['role'], 'no tool_use block to answer: the result goes as words');
		$this->assertStringContainsString('came as plain text', Request::textOf($last));
		$this->assertStringContainsString('"content": "5"', Request::textOf($last));
		$this->assertSame('Let me add. {"tool": "add", "args": {"a": 2, "b": 3}}', Request::textOf($engine->requests[1]->messages[1]));
	}

	public function testTheSameCallIsRefusedAfterThreeRunsAndThenEndsTheLoop(): void {
		$same = fn () => self::call('add', ['a' => 1, 'b' => 1]);
		$engine = new ScriptEngine([$same(), $same(), $same(), $same(), $same()]);
		$result = $this->loop($engine);
		$this->assertCount(3, $this->ran);
		$this->assertSame('repeat', $result['stop']);
		$this->assertStringContainsString('ran three times already', Request::parts(end($engine->requests)->messages[8], 'tool_result')[0]['content']);
	}

	public function testRepeatableToolsAreNotRefused(): void {
		$same = fn () => self::call('add', ['a' => 1, 'b' => 1]);
		$this->loop(new ScriptEngine([$same(), $same(), $same(), $same(), $same(), new Turn('ok')]), ['repeatable' => ['add']]);
		$this->assertCount(5, $this->ran);
	}

	public function testTheBudgetLeavesOneTurnToAnswerAndThenStops(): void {
		$engine = new ScriptEngine([self::call('add', ['a' => 1, 'b' => 1]), self::call('add', ['a' => 2, 'b' => 2], 'c2'), new Turn('Two and four.')]);
		$result = $this->loop($engine, ['maxTools' => 1]);
		$this->assertCount(1, $this->ran);
		$this->assertStringContainsString('used up', Request::parts(self::last($engine->requests[2]), 'tool_result')[0]['content']);
		$this->assertSame('done', $result['state']);
		$this->assertSame('Two and four.', $result['text']);

		$this->ran = [];
		$engine = new ScriptEngine([self::call('add', ['a' => 1, 'b' => 1]), self::call('add', ['a' => 2, 'b' => 2], 'c2'), self::call('add', ['a' => 3, 'b' => 3], 'c3')]);
		$result = $this->loop($engine, ['maxTools' => 1]);
		$this->assertSame(['error', 'max-tools'], [$result['state'], $result['stop']]);
		$this->assertCount(1, $this->ran);
	}

	public function testTheHeartbeatCancelsBeforeTheNextTurn(): void {
		$engine = new ScriptEngine([self::call('add', ['a' => 1, 'b' => 1]), new Turn('never')]);
		$result = $this->loop($engine, ['heartbeat' => fn (int $round) => $round === 0]);
		$this->assertSame([Errors::CANCELLED, 'cancelled'], [$result['error'], $result['stop']]);
		$this->assertCount(1, $engine->requests);
	}

	public function testAResultIsCutAndAFailingToolIsReportedNotThrown(): void {
		$engine = new ScriptEngine([
			new Turn(toolCalls: [['id' => 'a', 'name' => 'big', 'args' => []], ['id' => 'b', 'name' => 'fail', 'args' => []], ['id' => 'c', 'name' => 'nope', 'args' => []],
				['id' => 'd', 'name' => 'add', 'args' => ['a' => 'x', 'b' => 1]]]),
			new Turn('Sorry.'),
		]);
		$this->loop($engine, ['resultChars' => 1000]);
		[$big, $fail, $nope, $bad] = Request::parts(self::last($engine->requests[1]), 'tool_result');
		$this->assertStringStartsWith(str_repeat('x', 1000) . "\n[… cut here: 4000 more", $big['content']);
		$this->assertSame(['The tool fail failed: disk full', true], [$fail['content'], $fail['error']]);
		$this->assertStringContainsString('There is no tool named "nope"', $nope['content']);
		$this->assertStringContainsString('$.a should be integer', $bad['content']);
	}

	public function testImagesFromAToolComeWithItsResultAndOldOnesAreDropped(): void {
		$engine = new ScriptEngine([self::call('look'), self::call('look', [], 'c2'), new Turn('Seen.')], maxImages: 1);
		$extra = 0;
		$this->loop($engine, ['repeatable' => ['look'], 'afterTools' => function () use (&$extra) {
			return [Request::text('note ' . ++$extra)];
		}]);
		$second = self::last($engine->requests[1]);
		$this->assertSame(['tool_result', 'image', 'text'], array_column($second['parts'], 'kind'));
		$this->assertSame('note 1', $second['parts'][2]['text']);
		// Only the latest image stays attached (the engine takes one).
		$kinds = array_merge(...array_map(fn ($m) => array_column($m['parts'], 'kind'), $engine->requests[2]->messages));
		$this->assertSame(1, count(array_keys($kinds, 'image')));
	}

	public function testABusyProviderIsAskedTheSameTurnAgain(): void {
		$retried = [];
		$engine = new ScriptEngine([self::call('add', ['a' => 1, 'b' => 1]), Turn::failed(Errors::RATE_LIMITED), Turn::failed('provider:HTTP 529 overloaded'), new Turn('Two.')]);
		$result = (new Loop($engine))->run('ewa', self::request(), $this->tools(), ['pause' => function () {
		}, 'onRetry' => function (string $error) use (&$retried) {
			$retried[] = $error;
		}]);
		$this->assertSame('Two.', $result['text']);
		$this->assertCount(1, $this->ran, 'the tool ran once');
		$this->assertSame([Errors::RATE_LIMITED, 'provider:HTTP 529 overloaded'], $retried);

		$result = $this->loop(new ScriptEngine([Turn::failed(Errors::NO_KEY)]));
		$this->assertSame(['error', Errors::NO_KEY], [$result['state'], $result['error']], 'a wrong key is not retried');
	}

	public function testAStructuredAnswerIsCheckedAndAskedForOnceMore(): void {
		$schema = ['type' => 'object', 'properties' => ['sum' => ['type' => 'integer']], 'required' => ['sum']];
		$request = new Request('s', [Request::message('user', [Request::text('2+3')])], answerSchema: $schema);
		$engine = new ScriptEngine([new Turn('x', answer: ['sum' => 'five']), new Turn('ok', answer: ['sum' => 5])]);
		$result = (new Loop($engine))->run('ewa', $request, []);
		$this->assertSame(['sum' => 5], $result['answer']);
		$this->assertStringContainsString('$.sum should be integer', Request::textOf(self::last($engine->requests[1])));

		$engine = new ScriptEngine([new Turn('x'), new Turn('y')]);
		$this->assertSame(Errors::BAD_ANSWER, (new Loop($engine))->run('ewa', $request, [])['error']);
	}

	public function testUsageIsSummedAndEstimatedWhereNotReported(): void {
		$engine = new ScriptEngine([
			new Turn(toolCalls: [['id' => 'a', 'name' => 'add', 'args' => ['a' => 1, 'b' => 2]]], usage: new Usage(1000, 10, 900, 0.01, true)),
			new Turn('Three.'),
		]);
		$usage = $this->loop($engine)['usage'];
		$this->assertGreaterThan(1000, $usage->in);
		$this->assertSame(900, $usage->cachedIn);
		$this->assertSame(0.01, $usage->cost);
		$this->assertTrue($usage->reported);
	}
}
