<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Engine\Anthropic;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Http;
use OCA\TalkAi\Ai\Loop;
use OCA\TalkAi\Ai\Request;
use PHPUnit\Framework\TestCase;

/** Anthropic's Messages API against recorded answers: what is sent, and what comes back. */
class AnthropicTest extends TestCase {
	private const KEY = 'sk-ant-api03-SECRETSECRETSECRET';

	private function engine(FakeHttp $http, array $settings = []): Anthropic {
		return new Anthropic(new Http($http), $settings + ['key' => self::KEY, 'model' => 'claude-opus-5-5']);
	}

	private static function message(array $content, string $stop = 'end_turn', array $usage = ['input_tokens' => 10, 'output_tokens' => 3]): array {
		return FakeHttp::json(200, ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
			'content' => $content, 'stop_reason' => $stop, 'usage' => $usage]);
	}

	private static function ask(string $text, array $tools = [], array $extra = []): Request {
		return new Request('You help.', [Request::message('user', [Request::text($text), ...$extra])], $tools);
	}

	public function testAPlainAnswer(): void {
		$http = new FakeHttp([self::message([['type' => 'text', 'text' => 'Paris.']])]);
		$turn = $this->engine($http)->turn(self::ask('Capital of France?'));
		$this->assertSame(['Paris.', [], 'end', ''], [$turn->text, $turn->toolCalls, $turn->stopReason, $turn->error]);
		$sent = $http->sent();
		$this->assertSame('https://api.anthropic.com/v1/messages', $http->requests[0]['url']);
		$this->assertSame(self::KEY, $http->requests[0]['options']['headers']['x-api-key']);
		$this->assertSame('2023-06-01', $http->requests[0]['options']['headers']['anthropic-version']);
		$this->assertSame('claude-opus-5-5', $sent['model']);
		$this->assertSame([['type' => 'text', 'text' => 'You help.', 'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']]], $sent['system']);
		$this->assertSame(['type' => 'ephemeral'], $sent['messages'][0]['content'][0]['cache_control']);
		$this->assertArrayNotHasKey('tools', $sent);
		// Opus 5.5 declines may go to another model, on Anthropic's own API.
		$this->assertSame('default', $sent['fallbacks']);
		$this->assertSame('server-side-fallback-2026-07-01', $http->requests[0]['options']['headers']['anthropic-beta']);
	}

	public function testAToolRoundKeepsThinkingAndPairsResults(): void {
		$tools = ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]]],
			'mul' => ['description' => 'Multiplies.', 'input' => ['type' => 'object']]];
		$http = new FakeHttp([
			self::message([['type' => 'thinking', 'thinking' => '', 'signature' => 'sig1'], ['type' => 'text', 'text' => 'Adding.'],
				['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'add', 'input' => ['a' => 2]]], 'tool_use'),
			self::message([['type' => 'text', 'text' => 'Four.']]),
		]);
		$engine = $this->engine($http);
		$request = self::ask('2+2?', $tools);
		$first = $engine->turn($request);
		$this->assertSame([['id' => 'toolu_1', 'name' => 'add', 'args' => ['a' => 2]]], $first->toolCalls);
		$this->assertSame('tools', $first->stopReason);
		$sent = $http->sent();
		$this->assertSame(['add', 'mul'], array_column($sent['tools'], 'name'));
		$this->assertArrayNotHasKey('cache_control', $sent['tools'][0]);
		$this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $sent['tools'][1]['cache_control']);

		$messages = [...$request->messages, $first->message(), Request::message('tool', [Request::toolResult('toolu_1', 'add', '4')])];
		$this->assertSame('Four.', $engine->turn($request->with($messages, $first->session))->text);
		$second = $http->sent();
		// The answer goes back as it came, its thinking block included.
		$this->assertSame(['thinking', 'text', 'tool_use'], array_column($second['messages'][1]['content'], 'type'));
		$this->assertSame('sig1', $second['messages'][1]['content'][0]['signature']);
		$result = $second['messages'][2]['content'][0];
		$this->assertSame(['tool_result', 'toolu_1', '4'], [$result['type'], $result['tool_use_id'], $result['content'][0]['text']]);
		$this->assertSame(['type' => 'ephemeral'], $result['cache_control']);
	}

	public function testImagesDocumentsAndErrorResults(): void {
		$http = new FakeHttp([self::message([['type' => 'text', 'text' => 'ok']])]);
		$request = new Request('', [
			Request::message('user', [Request::text('Read these.'), Request::image('image/png', 'UE5H'), Request::document('application/pdf', 'JVBE', 'a.pdf')]),
			Request::message('assistant', [Request::toolCall('t1', 'look', []), Request::toolCall('t2', 'fail', [])]),
			Request::message('tool', [Request::toolResult('t1', 'look', 'a chart'), Request::toolResult('t2', 'fail', 'disk full', true),
				Request::text('Attached are the images.'), Request::image('image/png', 'QUJD')]),
		]);
		$this->engine($http)->turn($request);
		$sent = $http->sent();
		$this->assertArrayNotHasKey('system', $sent);
		$this->assertSame(['document', 'text', 'image'], array_column($sent['messages'][0]['content'], 'type'));
		$this->assertSame(['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'JVBE'], $sent['messages'][0]['content'][0]['source']);
		$this->assertSame(['type' => 'base64', 'media_type' => 'image/png', 'data' => 'UE5H'], $sent['messages'][0]['content'][2]['source']);
		$tool = $sent['messages'][2]['content'];
		$this->assertSame(['tool_result', 'tool_result', 'text'], array_column($tool, 'type'));
		$this->assertTrue($tool[1]['is_error']);
		// The image goes with the result that brought it.
		$this->assertSame(['text', 'image'], array_column($tool[1]['content'], 'type'));
	}

	public function testAStructuredAnswer(): void {
		$schema = ['type' => 'object', 'properties' => ['sum' => ['type' => 'integer', 'minimum' => 0]], 'required' => ['sum']];
		$http = new FakeHttp([self::message([['type' => 'text', 'text' => '{"sum": 5}']])]);
		$turn = $this->engine($http)->turn(new Request('', [Request::message('user', [Request::text('2+3')])], answerSchema: $schema));
		$this->assertSame(['sum' => 5], $turn->answer);
		$format = $http->sent()['output_config']['format'];
		$this->assertSame('json_schema', $format['type']);
		$this->assertFalse($format['schema']['additionalProperties']);
		$this->assertArrayNotHasKey('minimum', $format['schema']['properties']['sum']);
		$this->assertTrue($this->engine(new FakeHttp())->status()->structured);
	}

	public function testStreamingWithAToolCallAndUsage(): void {
		$http = new FakeHttp([FakeHttp::events([
			['message_start', ['type' => 'message_start', 'message' => ['id' => 'm', 'content' => [], 'usage' => ['input_tokens' => 5, 'cache_read_input_tokens' => 100, 'output_tokens' => 1]]]],
			['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
			['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Let me ']]],
			['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'add.']]],
			['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
			['content_block_start', ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'add', 'input' => []]]],
			['content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"a": ']]],
			['content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '2}']]],
			['content_block_stop', ['type' => 'content_block_stop', 'index' => 1]],
			['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 20]]],
			['message_stop', ['type' => 'message_stop']],
		])]);
		$deltas = [];
		$request = new Request('s', [Request::message('user', [Request::text('2')])], ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object']]],
			onDelta: function (string $text) use (&$deltas) {
				$deltas[] = $text;
			});
		$turn = $this->engine($http)->turn($request);
		$this->assertSame(['Let me ', 'add.'], $deltas);
		$this->assertSame('Let me add.', $turn->text);
		$this->assertSame([['id' => 'toolu_9', 'name' => 'add', 'args' => ['a' => 2]]], $turn->toolCalls);
		$this->assertSame([105, 20, 100, true], [$turn->usage->in, $turn->usage->out, $turn->usage->cachedIn, $turn->usage->reported]);
		$this->assertTrue($http->sent()['stream']);
		$this->assertTrue($http->requests[0]['options']['stream']);

		$overloaded = new FakeHttp([FakeHttp::events([['error', ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]])]);
		$this->assertSame(Errors::BUSY, $this->engine($overloaded)->turn($request)->error);
	}

	public function testUsageCountsCacheReadsAndWrites(): void {
		$http = new FakeHttp([self::message([['type' => 'text', 'text' => 'x']], usage: ['input_tokens' => 50, 'cache_creation_input_tokens' => 1000,
			'cache_read_input_tokens' => 4000, 'output_tokens' => 7])]);
		$usage = $this->engine($http)->turn(self::ask('q'))->usage;
		$this->assertSame([5050, 7, 4000, null], [$usage->in, $usage->out, $usage->cachedIn, $usage->cost]);
	}

	public function testErrorsAreWordsWithoutTheKey(): void {
		$error = fn (array|\Throwable $answer) => $this->engine(new FakeHttp([$answer]))->turn(self::ask('q'))->error;
		$this->assertSame(Errors::RATE_LIMITED, $error(FakeHttp::json(429, ['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']])));
		$this->assertSame(Errors::BUSY, $error(FakeHttp::json(529, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']])));
		$this->assertSame(Errors::NO_KEY, $error(FakeHttp::json(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']])));
		$this->assertSame(Errors::TIMEOUT, $error(new \RuntimeException('cURL error 28: Operation timed out after 600000 milliseconds')));
		$server = $error(FakeHttp::json(500, ['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Internal error for key ' . self::KEY]]));
		$this->assertStringStartsWith('provider:HTTP 500', $server);
		$this->assertStringNotContainsString('SECRETSECRET', $server);
		$this->assertTrue(Errors::transient($server));
		$this->assertStringStartsWith('provider:', $error([200, '<html>proxy error</html>']));
		$this->assertStringStartsWith('provider:', $error(FakeHttp::json(200, ['type' => 'message'])));
	}

	public function testModelsArePagedAndSorted(): void {
		$http = new FakeHttp([
			FakeHttp::json(200, ['data' => [['id' => 'claude-sonnet-5-5'], ['id' => 'claude-opus-5-5']], 'has_more' => true, 'last_id' => 'claude-opus-5-5']),
			FakeHttp::json(200, ['data' => [['id' => 'claude-haiku-5-5']], 'has_more' => false, 'last_id' => 'claude-haiku-5-5']),
		]);
		$this->assertSame(['claude-haiku-5-5', 'claude-opus-5-5', 'claude-sonnet-5-5'], $this->engine($http)->models());
		$this->assertStringContainsString('after_id=claude-opus-5-5', $http->requests[1]['url']);
		$this->assertSame('GET', $http->requests[0]['method']);
	}

	public function testStatusAndSwitches(): void {
		$this->assertSame(Errors::NO_KEY, (new Anthropic(new Http(new FakeHttp()), ['model' => 'm']))->status()->reason);
		$this->assertSame(Errors::NO_MODEL, (new Anthropic(new Http(new FakeHttp()), ['key' => 'k']))->status()->reason);
		$status = $this->engine(new FakeHttp(), ['flags' => ['search' => false, 'caching' => false], 'maxImages' => 8]);
		$this->assertSame([true, 8, false, false, true, 'anthropic'], [$status->status()->ready, $status->status()->maxImages, $status->status()->search,
			$status->status()->caching, $status->status()->documents, $status->status()->provider]);

		// Search and thinking when asked for; nothing cached when switched off; no fallbacks through another base URL.
		$http = new FakeHttp([self::message([['type' => 'text', 'text' => 'x']])]);
		$this->engine($http, ['flags' => ['caching' => false], 'baseUrl' => 'https://proxy.example/'])
			->turn(new Request('s', [Request::message('user', [Request::text('q')])], search: true, thinking: true));
		$sent = $http->sent();
		$this->assertSame('https://proxy.example/v1/messages', $http->requests[0]['url']);
		$this->assertSame(['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 5], $sent['tools'][0]);
		$this->assertSame(['type' => 'adaptive'], $sent['thinking']);
		$this->assertArrayNotHasKey('cache_control', $sent['system'][0]);
		$this->assertArrayNotHasKey('fallbacks', $sent);
	}

	public function testAPausedSearchIsContinued(): void {
		$http = new FakeHttp([
			self::message([['type' => 'server_tool_use', 'id' => 'srv_1', 'name' => 'web_search', 'input' => ['query' => 'x']]], 'pause_turn'),
			self::message([['type' => 'web_search_tool_result', 'tool_use_id' => 'srv_1', 'content' => []], ['type' => 'text', 'text' => 'Found it.']]),
		]);
		$turn = $this->engine($http)->turn(new Request('s', [Request::message('user', [Request::text('q')])], search: true));
		$this->assertSame('Found it.', $turn->text);
		$this->assertSame('assistant', end($http->requests[1]['json']['messages'])['role']);
		$this->assertSame(20, $turn->usage->in);
	}

	public function testALoopRunReplaysTheToolTurns(): void {
		$http = new FakeHttp([
			self::message([['type' => 'thinking', 'thinking' => '', 'signature' => 's1'],
				['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'add', 'input' => ['a' => 1, 'b' => 2]]], 'tool_use'),
			self::message([['type' => 'tool_use', 'id' => 'toolu_b', 'name' => 'add', 'input' => ['a' => 3, 'b' => 3]]], 'tool_use'),
			self::message([['type' => 'text', 'text' => 'Three, then six.']]),
		]);
		$tools = ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']]],
			'run' => fn (string $uid, array $args) => (string)($args['a'] + $args['b'])]];
		$result = (new Loop($this->engine($http)))->run('ewa', self::ask('Add.', $tools), $tools, ['pause' => function () {
		}]);
		$this->assertSame(['done', 'Three, then six.', ['add', 'add']], [$result['state'], $result['text'], $result['tools']]);
		$last = $http->sent();
		$this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_column($last['messages'], 'role'));
		$this->assertSame('s1', $last['messages'][1]['content'][0]['signature'], 'the first answer is sent back unchanged');
		$this->assertSame(['toolu_a', '3'], [$last['messages'][2]['content'][0]['tool_use_id'], $last['messages'][2]['content'][0]['content'][0]['text']]);
		$this->assertSame(['toolu_b', '6'], [$last['messages'][4]['content'][0]['tool_use_id'], $last['messages'][4]['content'][0]['content'][0]['text']]);
		$this->assertSame(30, $result['usage']->in, 'three turns of ten');
	}
}
