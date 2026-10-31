<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Engine\OpenAiCompatible;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Http;
use OCA\TalkAi\Ai\Loop;
use OCA\TalkAi\Ai\Request;
use PHPUnit\Framework\TestCase;

/** Chat completions as OpenAI, OpenRouter and local servers answer them (recorded shapes). */
class OpenAiCompatibleTest extends TestCase {
	private const TOOLS = ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]]]];

	private function engine(FakeHttp $http, array $settings = []): OpenAiCompatible {
		return new OpenAiCompatible(new Http($http), $settings + ['key' => 'sk-secret-123456789', 'model' => 'gpt-5.5']);
	}

	private static function completion(array $message, string $finish = 'stop', array $usage = ['prompt_tokens' => 120, 'completion_tokens' => 8,
		'prompt_tokens_details' => ['cached_tokens' => 64]]): array {
		return FakeHttp::json(200, ['id' => 'chatcmpl-1', 'object' => 'chat.completion', 'model' => 'gpt-5.5',
			'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'refusal' => null] + $message, 'finish_reason' => $finish]], 'usage' => $usage]);
	}

	private static function ask(string $text = 'What is 2 + 3?', array $tools = self::TOOLS, ?array $schema = null, ?callable $onDelta = null): Request {
		return new Request('You add.', [Request::message('user', [Request::text($text)])], $tools, answerSchema: $schema, onDelta: $onDelta);
	}

	public function testAPlainAnswerWithUsage(): void {
		$http = new FakeHttp([self::completion(['content' => 'Five.'])]);
		$turn = $this->engine($http)->turn(self::ask());
		$this->assertSame(['Five.', [], 'end', ''], [$turn->text, $turn->toolCalls, $turn->stopReason, $turn->error]);
		$this->assertSame([120, 8, 64, true], [$turn->usage->in, $turn->usage->out, $turn->usage->cachedIn, $turn->usage->reported]);
		$request = $http->requests[0];
		$this->assertSame('https://api.openai.com/v1/chat/completions', $request['url']);
		$this->assertSame('Bearer sk-secret-123456789', $request['options']['headers']['Authorization']);
		$this->assertSame(['role' => 'system', 'content' => 'You add.'], $request['json']['messages'][0]);
		$this->assertSame(['role' => 'user', 'content' => 'What is 2 + 3?'], $request['json']['messages'][1]);
		$this->assertSame(8192, $request['json']['max_completion_tokens']);
		$this->assertSame(['type' => 'function', 'function' => ['name' => 'add', 'description' => 'Adds.',
			'parameters' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]]]], $request['json']['tools'][0]);
	}

	public function testAToolRoundGoesBackAsToolMessagesAndImagesAfterThem(): void {
		$http = new FakeHttp([
			self::completion(['content' => null, 'tool_calls' => [['id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'add', 'arguments' => '{"a":2}']]]], 'tool_calls'),
		]);
		$engine = $this->engine($http);
		$turn = $engine->turn(self::ask());
		$this->assertSame([['id' => 'call_abc', 'name' => 'add', 'args' => ['a' => 2]]], $turn->toolCalls);
		$this->assertSame('tools', $turn->stopReason);

		$http->answers = [self::completion(['content' => 'Five.'])];
		$engine->turn(self::ask()->with([
			Request::message('user', [Request::text('What is 2 + 3?')]),
			$turn->message(),
			Request::message('tool', [Request::toolResult('call_abc', 'add', '5'), Request::text('A chart:'), Request::image('image/png', 'UE5H')]),
		]));
		$messages = $http->sent()['messages'];
		$this->assertSame(['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_abc', 'type' => 'function',
			'function' => ['name' => 'add', 'arguments' => '{"a":2}']]]], $messages[2]);
		$this->assertSame(['role' => 'tool', 'tool_call_id' => 'call_abc', 'content' => '5'], $messages[3]);
		$this->assertSame(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'A chart:'],
			['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,UE5H']]]], $messages[4]);
	}

	public function testAStructuredAnswerAndAPdf(): void {
		$schema = ['type' => 'object', 'properties' => ['sum' => ['type' => 'integer']], 'required' => ['sum']];
		$http = new FakeHttp([self::completion(['content' => '{"sum": 5}'])]);
		$turn = $this->engine($http)->turn(new Request('s', [Request::message('user', [Request::text('Sum?'), Request::document('application/pdf', 'JVBE', 'a.pdf')])],
			answerSchema: $schema));
		$this->assertSame(['sum' => 5], $turn->answer);
		$this->assertSame(['type' => 'json_schema', 'json_schema' => ['name' => 'answer', 'schema' => $schema, 'strict' => false]], $http->sent()['response_format']);
		$this->assertSame(['type' => 'file', 'file' => ['filename' => 'a.pdf', 'file_data' => 'data:application/pdf;base64,JVBE']], $http->sent()['messages'][1]['content'][1]);
	}

	public function testStreamingPutsTheDeltasTogether(): void {
		$deltas = [];
		$http = new FakeHttp([FakeHttp::events([
			['', ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Let me ']]]]],
			['', ['choices' => [['index' => 0, 'delta' => ['content' => 'add.']]]]],
			['', ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'add', 'arguments' => '']]]]]]]],
			['', ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"a"']]]]]]]],
			['', ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => ':2}']]]]]]]],
			['', ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]],
			['', ['choices' => [], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 12, 'prompt_tokens_details' => ['cached_tokens' => 0]]]],
			['', '[DONE]'],
		])]);
		$turn = $this->engine($http)->turn(self::ask(onDelta: function (string $d) use (&$deltas) {
			$deltas[] = $d;
		}));
		$this->assertSame(['Let me ', 'add.'], $deltas);
		$this->assertSame('Let me add.', $turn->text);
		$this->assertSame([['id' => 'call_1', 'name' => 'add', 'args' => ['a' => 2]]], $turn->toolCalls);
		$this->assertSame([50, 12], [$turn->usage->in, $turn->usage->out]);
		$this->assertTrue($http->sent()['stream']);
		$this->assertSame(['include_usage' => true], $http->sent()['stream_options']);
	}

	public function testErrorsInTheLayersWords(): void {
		$cases = [
			[FakeHttp::json(429, ['error' => ['message' => 'Rate limit reached', 'type' => 'requests']]), Errors::RATE_LIMITED],
			[FakeHttp::json(503, ['error' => ['message' => 'overloaded']]), Errors::BUSY],
			[FakeHttp::json(401, ['error' => ['message' => 'Incorrect API key provided: sk-secr****6789']]), Errors::NO_KEY],
			[new \RuntimeException('cURL error 28: Operation timed out after 600001 milliseconds'), Errors::TIMEOUT],
			[[200, '<html>Bad gateway</html>'], 'provider:the answer is not JSON'],
			[FakeHttp::json(200, ['choices' => []]), 'provider:no answer in the response'],
		];
		foreach ($cases as [$answer, $error]) {
			$this->assertSame($error, $this->engine(new FakeHttp([$answer]))->turn(self::ask())->error);
		}
		$error = $this->engine(new FakeHttp([FakeHttp::json(500, ['error' => ['message' => 'The server had an error']])]))->turn(self::ask())->error;
		$this->assertSame('provider:HTTP 500: The server had an error', $error);
		$this->assertTrue(Errors::transient($error));
	}

	public function testStatusAndModels(): void {
		$this->assertSame(Errors::NO_MODEL, (new OpenAiCompatible(new Http(new FakeHttp()), []))->status()->reason);
		$this->assertSame(Errors::NO_KEY, (new OpenAiCompatible(new Http(new FakeHttp()), ['model' => 'm', 'baseUrl' => 'https://openrouter.ai/api/v1']))->status()->reason);
		$local = (new OpenAiCompatible(new Http(new FakeHttp()), ['model' => 'qwen3', 'baseUrl' => 'http://localhost:11434/v1']))->status();
		$this->assertSame([true, 'ollama', false], [$local->ready, $local->provider, $local->documents]);

		$http = new FakeHttp([FakeHttp::json(200, ['object' => 'list', 'data' => [['id' => 'gpt-5.5', 'object' => 'model'], ['id' => 'gpt-4.1', 'object' => 'model']]])]);
		$this->assertSame(['gpt-4.1', 'gpt-5.5'], $this->engine($http)->models());
		$this->assertSame('https://api.openai.com/v1/models', $http->requests[0]['url']);
	}

	public function testALocalServerWithoutToolsGetsThemInWordsAndExtraHeaders(): void {
		$http = new FakeHttp([self::completion(['content' => "Adding.\n```json\n{\"tool_calls\": [{\"name\": \"add\", \"args\": {\"a\": 2}}]}\n```"], 'stop', [])]);
		$engine = new OpenAiCompatible(new Http($http), ['model' => 'llama3', 'baseUrl' => 'http://vllm:8000/v1/', 'flags' => ['tools' => false, 'structured' => false],
			'headers' => ['X-Title' => 'Talk']]);
		$turn = $engine->turn(self::ask());
		$this->assertSame('Adding.', $turn->text);
		$this->assertSame('add', $turn->toolCalls[0]['name']);
		$this->assertFalse($turn->usage->reported);
		$sent = $http->sent();
		$this->assertArrayNotHasKey('tools', $sent);
		$this->assertStringContainsString('"tool_calls"', $sent['messages'][0]['content']);
		$this->assertSame(8192, $sent['max_tokens']);
		$this->assertSame('http://vllm:8000/v1/chat/completions', $http->requests[0]['url']);
		$this->assertSame('Talk', $http->requests[0]['options']['headers']['X-Title']);
		$this->assertArrayNotHasKey('Authorization', $http->requests[0]['options']['headers']);
	}

	public function testALoopRunsThroughTheEngine(): void {
		$http = new FakeHttp([
			self::completion(['content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'add', 'arguments' => '{"a":2}']]]], 'tool_calls'),
			self::completion(['content' => 'It is 5.']),
		]);
		$result = (new Loop($this->engine($http)))->run('ewa', self::ask(), ['add' => self::TOOLS['add'] + ['run' => fn (string $uid, array $args) => (string)($args['a'] + 3)]]);
		$this->assertSame(['done', 'It is 5.', ['add']], [$result['state'], $result['text'], $result['tools']]);
		$this->assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '5'], $http->sent()['messages'][3]);
		$this->assertSame(240, $result['usage']->in);
	}
}
