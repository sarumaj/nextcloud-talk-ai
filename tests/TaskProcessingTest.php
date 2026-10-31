<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Engine\TaskProcessing;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\Ai\Loop;
use OCA\TalkAi\Ai\Provider\TextToTextChatWithTools;
use OCA\TalkAi\Ai\Provider\TextToTextHeadline;
use OCA\TalkAi\Ai\Provider\TextToTextSummary;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Turn;
use OCP\IAppConfig;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;

/** Nextcloud's TaskProcessing as an engine, and the Hub as its provider. */
class TaskProcessingTest extends TestCase {
	/** @var list<Task> */
	private array $tasks = [];

	/**
	 * A manager whose providers are the given ids by task type, answering each task
	 * with the next of $outputs (an output array, or a \Throwable to throw).
	 *
	 * @param array<string, string> $providers type => provider id
	 */
	private function manager(array $providers, array $outputs, bool $sync = true): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('getPreferredProvider')->willReturnCallback(function (string $type) use ($providers, $sync) {
			if (!isset($providers[$type])) {
				throw new \OCP\TaskProcessing\Exception\Exception('no provider');
			}
			$provider = $this->createMock($sync ? ISynchronousProvider::class : IProvider::class);
			$provider->method('getId')->willReturn($providers[$type]);
			$provider->method('getName')->willReturn('OpenAI and LocalAI integration');
			return $provider;
		});
		$finish = function (Task $task) use (&$outputs): Task {
			$this->tasks[] = $task;
			$out = array_shift($outputs) ?? ['output' => 'nothing'];
			if ($out instanceof \Throwable) {
				throw $out;
			}
			if (isset($out['error'])) {
				$task->setStatus(Task::STATUS_FAILED);
				$task->setErrorMessage($out['error']);
			} else {
				$task->setOutput($out);
				$task->setStatus(Task::STATUS_SUCCESSFUL);
			}
			return $task;
		};
		$manager->method('runTask')->willReturnCallback($finish);
		$scheduled = null;
		$manager->method('scheduleTask')->willReturnCallback(function (Task $task) use (&$scheduled) {
			$task->setId(41);
			$scheduled = $task;
		});
		$manager->method('getTask')->willReturnCallback(function () use (&$scheduled, $finish) {
			return $finish($scheduled);
		});
		return $manager;
	}

	private static function tools(array &$ran): array {
		return ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']]],
			'run' => function (string $uid, array $args) use (&$ran) {
				$ran[] = [$uid, $args];
				return (string)($args['a'] + $args['b']);
			}]];
	}

	private static function request(): Request {
		return new Request('You add.', [Request::message('user', [Request::text('2+3?')])], ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object']]]);
	}

	public function testChatWithToolsRoundTripThroughLoop(): void {
		$engine = new TaskProcessing($this->manager(['core:text2text:chatwithtools' => 'integration_openai-text2text-chatwithtools'], [
			['output' => '', 'tool_calls' => '[{"name":"add","args":{"a":2,"b":3},"id":"call_1"}]'],
			['output' => 'Five.', 'tool_calls' => ''],
		]), []);
		$status = $engine->status();
		$this->assertTrue($status->ready);
		$this->assertSame(['OpenAI and LocalAI integration', 'integration_openai-text2text-chatwithtools', false, false], [$status->provider, $status->model, $status->images, $status->search]);
		$ran = [];
		$result = (new Loop($engine))->run('ewa', self::request(), self::tools($ran));
		$this->assertSame('Five.', $result['text']);
		$this->assertSame([['ewa', ['a' => 2, 'b' => 3]]], $ran);
		[$first, $second] = $this->tasks;
		$this->assertNull($first->getUserId(), 'the task is the app\'s, not the person\'s');
		$this->assertSame('talk_ai', $first->getAppId());
		$this->assertSame('2+3?', $first->getInput()['input']);
		$this->assertSame('[]', $first->getInput()['tool_message']);
		$this->assertSame('add', json_decode($first->getInput()['tools'], true)[0]['function']['name']);
		// The second task: the call in the history, its result as tool_message, no input.
		$input = $second->getInput();
		$this->assertSame('', $input['input']);
		$this->assertSame([['tool_call_id' => 'call_1', 'name' => 'add', 'content' => '5']], json_decode($input['tool_message'], true));
		$history = array_map(fn ($h) => json_decode($h, true), $input['history']);
		$this->assertSame(['human', 'assistant'], array_column($history, 'role'));
		$this->assertSame([['id' => 'call_1', 'name' => 'add', 'args' => ['a' => 2, 'b' => 3]]], $history[1]['tool_calls']);
	}

	public function testToolCallsInOtherFormsAreRead(): void {
		$engine = new TaskProcessing($this->manager(['core:text2text:chatwithtools' => 'llm2'], [
			['output' => 'x', 'tool_calls' => '[{"id":"a","type":"function","function":{"name":"add","arguments":"{\"a\":1,\"b\":2}"}}, {"name":"add","arguments":{"a":3,"b":4}}]'],
		]), []);
		$turn = $engine->turn(self::request());
		$this->assertSame([['id' => 'a', 'name' => 'add', 'args' => ['a' => 1, 'b' => 2]], ['id' => 'tp-2', 'name' => 'add', 'args' => ['a' => 3, 'b' => 4]]], $turn->toolCalls);
	}

	public function testWithoutChatWithToolsToolsGoAsText(): void {
		$engine = new TaskProcessing($this->manager(['core:text2text' => 'local'], [
			['output' => "```json\n{\"tool_calls\": [{\"name\": \"add\", \"args\": {\"a\": 2, \"b\": 3}}]}\n```"],
			['output' => 'Five.'],
		]), []);
		$ran = [];
		$result = (new Loop($engine))->run('ewa', self::request(), self::tools($ran));
		$this->assertSame('Five.', $result['text']);
		$this->assertCount(1, $ran);
		$this->assertSame('core:text2text', $this->tasks[0]->getTaskTypeId());
		$this->assertStringContainsString('"tool_calls"', $this->tasks[0]->getInput()['input'], 'the tools are described in the prompt');
		$this->assertStringContainsString('"tool_results"', $this->tasks[1]->getInput()['input']);
	}

	public function testAChatProviderTakesTheHistory(): void {
		$engine = new TaskProcessing($this->manager(['core:text2text:chat' => 'chat'], [['output' => 'Hi again.']]), []);
		$engine->turn(new Request('Be kind.', [Request::message('user', [Request::text('Hi')]), Request::message('assistant', [Request::text('Hello.')]),
			Request::message('user', [Request::text('Again')])]));
		$input = $this->tasks[0]->getInput();
		$this->assertSame('core:text2text:chat', $this->tasks[0]->getTaskTypeId());
		$this->assertSame(['Be kind.', 'Again'], [$input['system_prompt'], $input['input']]);
		$this->assertSame([['role' => 'human', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hello.']], array_map(fn ($h) => json_decode($h, true), $input['history']));
	}

	public function testNotReadyWithoutAProviderOrWithOnlyOurOwn(): void {
		$this->assertSame(Errors::NOT_READY, (new TaskProcessing($this->manager([], []), []))->status()->reason);
		$own = new TaskProcessing($this->manager(['core:text2text:chatwithtools' => 'talk_ai:chatwithtools', 'core:text2text:chat' => 'talk_ai:chat'], []), []);
		$this->assertFalse($own->status()->ready, 'asking our own provider would ask ourselves');
		$this->assertSame(Errors::NOT_READY, $own->turn(self::request())->error);
		// Our own for one type, another app's for the next: that one.
		$mixed = new TaskProcessing($this->manager(['core:text2text:chatwithtools' => 'talk_ai:chatwithtools', 'core:text2text' => 'local'], []), []);
		$this->assertSame('local', $mixed->status()->model);
	}

	public function testFailuresAsErrorWords(): void {
		$engine = new TaskProcessing($this->manager(['core:text2text' => 'local'], [
			new \RuntimeException("connection refused\n#0 /var/www/stack"), ['error' => 'Rate limit reached'], ['error' => 'model exploded'],
			new \OCP\TaskProcessing\Exception\PreConditionNotMetException('no provider'),
		]), []);
		$request = new Request('s', [Request::message('user', [Request::text('x')])]);
		$this->assertSame('provider:connection refused', $engine->turn($request)->error);
		$this->assertSame(Errors::RATE_LIMITED, $engine->turn($request)->error);
		$this->assertSame('provider:model exploded', $engine->turn($request)->error);
		$this->assertSame(Errors::NOT_READY, $engine->turn($request)->error);
	}

	public function testABackgroundProviderIsWaitedFor(): void {
		$engine = new TaskProcessing($this->manager(['core:text2text' => 'llm2'], [['output' => 'Later.']], sync: false), []);
		$this->assertSame('Later.', $engine->turn(new Request('s', [Request::message('user', [Request::text('x')])]))->text);
	}

	private function hub(ScriptEngine $engine): Hub {
		$engines = new Engines($this->createMock(IAppConfig::class));
		$engines->fix($engine);
		return new Hub($engines);
	}

	public function testTheProvidersAskTheHub(): void {
		$engine = new ScriptEngine([new Turn('A short summary.'), new Turn('A Headline')]);
		$hub = $this->hub($engine);
		$summary = new TextToTextSummary($hub);
		$this->assertSame('talk_ai:summary', $summary->getId());
		$this->assertSame('core:text2text:summary', $summary->getTaskTypeId());
		$this->assertStringStartsWith('AI in Talk (test m1)', $summary->getName());
		$this->assertSame(['output' => 'A short summary.'], $summary->process(null, ['input' => 'A long text.'], fn () => true));
		$this->assertStringContainsString('Summarize', $engine->requests[0]->system);
		$this->assertSame('A long text.', Request::textOf($engine->requests[0]->messages[0]));
		$this->assertSame(['output' => 'A Headline'], (new TextToTextHeadline($hub))->process('ewa', ['input' => 'Text.'], fn () => true));
		$this->assertStringContainsString('headline', $engine->requests[1]->system);
	}

	public function testChatWithToolsReturnsTheCallsWithoutRunningThem(): void {
		$engine = new ScriptEngine([new Turn(toolCalls: [['id' => 't1', 'name' => 'get_weather', 'args' => ['city' => 'Kassel']]])]);
		$provider = new TextToTextChatWithTools($this->hub($engine));
		$out = $provider->process(null, [
			'system_prompt' => 'You help.',
			'input' => '',
			'history' => ['{"role":"human","content":"Weather?"}', '{"role":"assistant","content":"","tool_calls":[{"id":"t0","name":"get_city","args":{}}]}'],
			'tool_message' => '[{"tool_call_id":"t0","name":"get_city","content":"Kassel"}]',
			'tools' => '[{"type":"function","function":{"name":"get_weather","description":"Weather.","parameters":{"type":"object","properties":{"city":{"type":"string"}}}}}]',
		], fn () => true);
		$this->assertSame([['id' => 't1', 'name' => 'get_weather', 'args' => ['city' => 'Kassel']]], json_decode($out['tool_calls'], true));
		$request = $engine->requests[0];
		$this->assertSame('You help.', $request->system);
		$this->assertSame(['get_weather'], array_keys($request->tools));
		$this->assertSame(['user', 'assistant', 'tool'], array_column($request->messages, 'role'));
		$this->assertSame('t0', Request::parts($request->messages[1], 'tool_call')[0]['id']);
		$this->assertSame(['t0', 'Kassel'], [Request::parts($request->messages[2], 'tool_result')[0]['id'], Request::parts($request->messages[2], 'tool_result')[0]['content']]);
		$this->assertCount(1, $engine->requests, 'one turn: the caller runs its tools');

		$this->expectException(ProcessingException::class);
		(new TextToTextChatWithTools($this->hub(new ScriptEngine([Turn::failed(Errors::BUSY)]))))->process('ewa', ['input' => 'x', 'history' => [], 'tool_message' => '', 'tools' => '[]'], fn () => true);
	}
}
