<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Engine\Gemini;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Http;
use OCA\TalkAi\Ai\Loop;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Service\Reply;
use PHPUnit\Framework\TestCase;

/** Gemini's generateContent as it answers (recorded shapes). */
class GeminiTest extends TestCase {
	private const TOOLS = ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a']]]];

	private function engine(FakeHttp $http, array $settings = []): Gemini {
		return new Gemini(new Http($http), $settings + ['key' => 'AIzaSyD-secret-key-1234', 'model' => 'gemini-3-pro']);
	}

	private static function answer(array $parts, string $finish = 'STOP', array $usage = ['promptTokenCount' => 100, 'candidatesTokenCount' => 10,
		'thoughtsTokenCount' => 30, 'cachedContentTokenCount' => 60, 'totalTokenCount' => 140]): array {
		return FakeHttp::json(200, ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $finish, 'index' => 0]],
			'usageMetadata' => $usage, 'modelVersion' => 'gemini-3-pro', 'responseId' => 'r1']);
	}

	private static function ask(array $tools = self::TOOLS, ?array $schema = null, ?callable $onDelta = null, bool $search = false): Request {
		return new Request('You add.', [Request::message('user', [Request::text('What is 2 + 3?')])], $tools, $search, $schema, onDelta: $onDelta);
	}

	public function testAPlainAnswerWithUsageAndTheKeyInAHeader(): void {
		$http = new FakeHttp([self::answer([['text' => 'thinking…', 'thought' => true], ['text' => 'Five.']])]);
		$turn = $this->engine($http)->turn(self::ask());
		$this->assertSame(['Five.', [], 'end'], [$turn->text, $turn->toolCalls, $turn->stopReason]);
		$this->assertSame([100, 40, 60, true], [$turn->usage->in, $turn->usage->out, $turn->usage->cachedIn, $turn->usage->reported]);
		$request = $http->requests[0];
		$this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro:generateContent', $request['url']);
		$this->assertSame('AIzaSyD-secret-key-1234', $request['options']['headers']['x-goog-api-key']);
		$this->assertSame(['parts' => [['text' => 'You add.']]], $request['json']['systemInstruction']);
		$this->assertSame([['role' => 'user', 'parts' => [['text' => 'What is 2 + 3?']]]], $request['json']['contents']);
		$this->assertSame(['name' => 'add', 'description' => 'Adds.', 'parameters' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a']]],
			$request['json']['tools'][0]['functionDeclarations'][0]);
	}

	public function testAToolRoundKeepsTheIdAndTheThoughtSignature(): void {
		$http = new FakeHttp([self::answer([['functionCall' => ['id' => 'fc-1', 'name' => 'add', 'args' => ['a' => 2]], 'thoughtSignature' => 'c2lnbmF0dXJl']])]);
		$engine = $this->engine($http);
		$turn = $engine->turn(self::ask());
		$this->assertSame([['id' => 'fc-1', 'name' => 'add', 'args' => ['a' => 2], 'signature' => 'c2lnbmF0dXJl']], $turn->toolCalls);
		$this->assertSame('tools', $turn->stopReason);

		$http->answers = [self::answer([['text' => 'Five.']])];
		$engine->turn(self::ask()->with([
			Request::message('user', [Request::text('What is 2 + 3?')]),
			$turn->message(),
			Request::message('tool', [Request::toolResult('fc-1', 'add', '5'), Request::image('image/png', 'UE5H')]),
		]));
		$contents = $http->sent()['contents'];
		$this->assertSame(['role' => 'model', 'parts' => [['functionCall' => ['id' => 'fc-1', 'name' => 'add', 'args' => ['a' => 2]], 'thoughtSignature' => 'c2lnbmF0dXJl']]], $contents[1]);
		$this->assertSame(['role' => 'user', 'parts' => [['functionResponse' => ['id' => 'fc-1', 'name' => 'add', 'response' => ['output' => '5']]],
			['inlineData' => ['mimeType' => 'image/png', 'data' => 'UE5H']]]], $contents[2]);
	}

	public function testACallWithoutIdGetsOneOfOursThatIsNotSentBack(): void {
		$http = new FakeHttp([self::answer([['functionCall' => ['name' => 'add', 'args' => ['a' => 2]]]]), self::answer([['text' => 'ok']])]);
		$engine = $this->engine($http, ['model' => 'gemini-2.5-flash']);
		$turn = $engine->turn(self::ask());
		$this->assertSame('g-1', $turn->toolCalls[0]['id']);
		$engine->turn(self::ask()->with([Request::message('user', [Request::text('q')]), $turn->message(),
			Request::message('tool', [Request::toolResult('g-1', 'add', 'failed', true)])]));
		$contents = $http->sent()['contents'];
		// Gemini 2.5 signs nothing it did not give; the result names the call by name.
		$this->assertSame(['functionCall' => ['name' => 'add', 'args' => ['a' => 2]]], $contents[1]['parts'][0]);
		$this->assertSame(['functionResponse' => ['name' => 'add', 'response' => ['error' => 'failed']]], $contents[2]['parts'][0]);
	}

	public function testImagesAndPdfsGoInline(): void {
		$http = new FakeHttp([self::answer([['text' => 'A cat on a form.']])]);
		$this->engine($http)->turn(new Request('s', [Request::message('user', [Request::text('What is it?'), Request::image('image/jpeg', '/9j/'),
			Request::document('application/pdf', 'JVBE', 'form.pdf')])]));
		$this->assertSame([['text' => 'What is it?'], ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => '/9j/']],
			['inlineData' => ['mimeType' => 'application/pdf', 'data' => 'JVBE']]], $http->sent()['contents'][0]['parts']);
		$this->assertTrue($this->engine($http)->status()->documents);
	}

	public function testAStructuredAnswerAndSearch(): void {
		$schema = ['type' => 'object', 'additionalProperties' => false, '$schema' => 'x', 'properties' => ['sum' => ['type' => 'integer']], 'required' => ['sum']];
		$http = new FakeHttp([self::answer([['text' => '{"sum": 5}']])]);
		$turn = $this->engine($http)->turn(self::ask([], $schema, search: true));
		$this->assertSame(['sum' => 5], $turn->answer);
		$config = $http->sent()['generationConfig'];
		$this->assertSame('application/json', $config['responseMimeType']);
		$this->assertSame(['type' => 'object', 'properties' => ['sum' => ['type' => 'integer']], 'required' => ['sum']], $config['responseSchema']);
		$this->assertSame([['googleSearch' => []]], $http->sent()['tools']);

		// Gemini 2.5 takes no JSON answer or search beside functions: the answer goes by words.
		$http = new FakeHttp([self::answer([['text' => "Here.\n```answer\n{\"sum\": 5}\n```"]])]);
		$turn = $this->engine($http, ['model' => 'gemini-2.5-pro'])->turn(self::ask(self::TOOLS, $schema, search: true));
		$this->assertSame([['sum' => 5], 'Here.'], [$turn->answer, $turn->text]);
		$this->assertArrayNotHasKey('responseSchema', $http->sent()['generationConfig']);
		$this->assertCount(1, $http->sent()['tools']);
		$this->assertStringContainsString('```answer', $http->sent()['systemInstruction']['parts'][0]['text']);
	}

	public function testLenientSchemasAndToolsWithoutArguments(): void {
		$http = new FakeHttp([self::answer([['text' => 'ok']])]);
		$input = Reply::lenient(['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'description' => 'How many.'], 'mode' => ['type' => 'string', 'enum' => ['a', 'b']],
			'when' => ['type' => ['string', 'null']]]]);
		$this->engine($http)->turn(self::ask(['count' => ['description' => 'Counts.', 'input' => $input], 'ping' => ['description' => 'Pong.', 'input' => ['type' => 'object']]]));
		[$count, $ping] = $http->sent()['tools'][0]['functionDeclarations'];
		$this->assertSame(['description' => 'How many.', 'anyOf' => [['type' => 'integer'], ['type' => 'string']]], $count['parameters']['properties']['n']);
		$this->assertSame(['type' => 'string', 'nullable' => true], $count['parameters']['properties']['when']);
		$this->assertSame(['name' => 'ping', 'description' => 'Pong.'], $ping);
	}

	public function testStreaming(): void {
		$deltas = [];
		$http = new FakeHttp([FakeHttp::events([
			['', ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Let me ']]]]]]],
			['', ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'add.']]]]]]],
			['', ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'add', 'args' => ['a' => 2]], 'thoughtSignature' => 'c2ln']]], 'finishReason' => 'STOP']],
				'usageMetadata' => ['promptTokenCount' => 70, 'candidatesTokenCount' => 9]]],
		])]);
		$turn = $this->engine($http)->turn(self::ask(onDelta: function (string $d) use (&$deltas) {
			$deltas[] = $d;
		}));
		$this->assertSame(['Let me ', 'add.'], $deltas);
		$this->assertSame(['Let me add.', 'add', 70, 9], [$turn->text, $turn->toolCalls[0]['name'], $turn->usage->in, $turn->usage->out]);
		$this->assertStringEndsWith(':streamGenerateContent?alt=sse', $http->requests[0]['url']);
	}

	public function testErrorsAndBlocks(): void {
		$cases = [
			[FakeHttp::json(429, ['error' => ['code' => 429, 'message' => 'Resource has been exhausted', 'status' => 'RESOURCE_EXHAUSTED']]), Errors::RATE_LIMITED],
			[FakeHttp::json(503, ['error' => ['code' => 503, 'message' => 'The model is overloaded.', 'status' => 'UNAVAILABLE']]), Errors::BUSY],
			[FakeHttp::json(403, ['error' => ['code' => 403, 'message' => 'API key not valid.', 'status' => 'PERMISSION_DENIED']]), Errors::NO_KEY],
			[FakeHttp::json(500, ['error' => ['code' => 500, 'message' => 'Internal error', 'status' => 'INTERNAL']]), 'provider:HTTP 500: Internal error'],
			[new \RuntimeException('cURL error 28: Operation timed out'), Errors::TIMEOUT],
			[[200, 'not json'], 'provider:the answer is not JSON'],
			[FakeHttp::json(200, ['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 5]]), 'provider:blocked: SAFETY'],
			[self::answer([], 'PROHIBITED_CONTENT'), 'provider:blocked: PROHIBITED_CONTENT'],
		];
		foreach ($cases as [$answer, $error]) {
			$turn = $this->engine(new FakeHttp([$answer]))->turn(self::ask());
			$this->assertSame($error, $turn->error);
			$this->assertStringNotContainsString('AIza', $turn->error);
		}
	}

	public function testStatusAndModels(): void {
		$this->assertSame(Errors::NO_KEY, (new Gemini(new Http(new FakeHttp()), ['model' => 'gemini-3-pro']))->status()->reason);
		$this->assertSame(Errors::NO_MODEL, (new Gemini(new Http(new FakeHttp()), ['key' => 'k']))->status()->reason);
		$http = new FakeHttp([
			FakeHttp::json(200, ['models' => [['name' => 'models/gemini-3-pro', 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
				['name' => 'models/text-embedding-004', 'supportedGenerationMethods' => ['embedContent']]], 'nextPageToken' => 'p2']),
			FakeHttp::json(200, ['models' => [['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']]]]),
		]);
		$this->assertSame(['gemini-2.5-flash', 'gemini-3-pro'], $this->engine($http)->models());
		$this->assertStringContainsString('pageToken=p2', $http->requests[1]['url']);
		$this->assertStringNotContainsString('key=', $http->requests[0]['url']);
	}

	public function testALoopRunsThroughTheEngine(): void {
		$http = new FakeHttp([
			self::answer([['functionCall' => ['id' => 'a1', 'name' => 'add', 'args' => ['a' => 2]], 'thoughtSignature' => 'c2ln']]),
			self::answer([['text' => 'It is 5.']]),
		]);
		$result = (new Loop($this->engine($http)))->run('ewa', self::ask(), ['add' => self::TOOLS['add'] + ['run' => fn (string $uid, array $args) => (string)($args['a'] + 3)]]);
		$this->assertSame(['done', 'It is 5.', ['add']], [$result['state'], $result['text'], $result['tools']]);
		$contents = $http->sent()['contents'];
		$this->assertSame('c2ln', $contents[1]['parts'][0]['thoughtSignature']);
		$this->assertSame(['id' => 'a1', 'name' => 'add', 'response' => ['output' => '5']], $contents[2]['parts'][0]['functionResponse']);
	}
}
