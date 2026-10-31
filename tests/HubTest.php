<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Engine\Missing;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Turn;
use OCA\TalkAi\Ai\Usage;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** What the app asks: scenarios, who may ask, what goes with a question, and the result's shape. */
class HubTest extends TestCase {
	private function hub(ScriptEngine|Missing $engine): Hub {
		$engines = new Engines($this->createMock(IAppConfig::class));
		$engines->fix($engine);
		return new Hub($engines);
	}

	public function testAQuestionWithItsToolsGivesTheAppsResult(): void {
		$engine = new ScriptEngine([new Turn(toolCalls: [['id' => 'a', 'name' => 'echo', 'args' => ['x' => 'hi']]], usage: new Usage(10, 2, reported: true)),
			new Turn('It said hi.', usage: new Usage(20, 4, 10, reported: true))]);
		$hub = $this->hub($engine);
		$hub->registerScenario('chat', ['system' => 'Be brief.', 'search' => true,
			'tools' => ['echo' => ['description' => 'Echoes.', 'input' => ['type' => 'object'], 'run' => fn (string $uid, array $args) => $uid . ': ' . $args['x']]]]);
		$result = $hub->ask('ewa', 'chat', [['role' => 'assistant', 'text' => 'Hello.'], ['role' => 'user', 'text' => 'Say hi.']], ['context' => 'Room: Maths']);
		$this->assertSame(['done', 'It said hi.', ['echo'], ''], [$result['state'], $result['text'], $result['tools'], $result['error']]);
		$this->assertSame(['in' => 30, 'out' => 6, 'cachedIn' => 10, 'cost' => null, 'reported' => true], $result['usage']);
		$this->assertSame('ewa: hi', $result['calls'][0]['result']);
		$first = $engine->requests[0];
		// The system prompt stays the same; the context goes with the question.
		$this->assertSame('Be brief.', $first->system);
		$this->assertSame(["Context:\nRoom: Maths", 'Say hi.'], array_column($first->messages[1]['parts'], 'text'));
		$this->assertTrue($first->search);
		$this->assertTrue($hub->status('chat')->ready);
	}

	public function testSearchOnlyWhereTheScenarioAndTheEngineHaveIt(): void {
		$engine = new ScriptEngine([new Turn('a'), new Turn('b')]);
		$hub = $this->hub($engine);
		$hub->registerScenario('plain', ['system' => 's']);
		$hub->registerScenario('web', ['system' => 's', 'search' => true]);
		$hub->ask('ewa', 'plain', [['role' => 'user', 'text' => 'q']], ['search' => true]);
		$hub->ask('ewa', 'web', [['role' => 'user', 'text' => 'q']], ['search' => false]);
		$this->assertSame([false, false], [$engine->requests[0]->search, $engine->requests[1]->search]);
	}

	public function testRefusals(): void {
		$hub = $this->hub(new ScriptEngine([new Turn('never')]));
		$hub->registerScenario('kids', ['system' => 's', 'allow' => fn (string $uid) => $uid === 'ewa']);
		$this->assertSame(Errors::NO_SCENARIO, $hub->ask('ewa', 'other', [['role' => 'user', 'text' => 'q']])['error']);
		$this->assertSame(Errors::USER_NOT_ALLOWED, $hub->ask('jan', 'kids', [['role' => 'user', 'text' => 'q']])['error']);
		$this->assertSame(Errors::EMPTY, $hub->ask('ewa', 'kids', [['role' => 'user', 'text' => '  ']])['error']);
		$this->assertSame(Errors::EMPTY, $hub->ask('ewa', 'kids', [['role' => 'assistant', 'text' => 'last word']])['error']);

		$missing = $this->hub(new Missing(Errors::NO_KEY, 'anthropic'));
		$missing->registerScenario('kids', ['system' => 's']);
		$this->assertSame(['error', Errors::NO_KEY], [$missing->ask('ewa', 'kids', [['role' => 'user', 'text' => 'q']])['state'], $missing->status()->reason]);
	}

	public function testImagesGoWithTheQuestionWithinTheEnginesLimits(): void {
		$png = ['type' => 'image/png', 'data' => base64_encode('PNG')];
		$engine = new ScriptEngine([new Turn('A cat.')], maxImages: 2);
		$hub = $this->hub($engine);
		$hub->registerScenario('chat', ['system' => 's']);
		$this->assertSame('done', $hub->ask('ewa', 'chat', [['role' => 'user', 'text' => 'What is it?']], ['images' => [$png, $png]])['state']);
		$this->assertSame(['text', 'image', 'image'], array_column($engine->requests[0]->messages[0]['parts'], 'kind'));
		// An image alone is a question too.
		$this->assertSame('done', $hub->ask('ewa', 'chat', [['role' => 'user', 'text' => '']], ['images' => [$png]])['state']);

		$ask = fn (array $images) => $hub->ask('ewa', 'chat', [['role' => 'user', 'text' => 'q']], ['images' => $images])['error'];
		$this->assertSame(Errors::TOO_MANY_IMAGES, $ask([$png, $png, $png]));
		$this->assertSame(Errors::IMAGE_TYPE, $ask([['type' => 'image/tiff', 'data' => 'AAAA']]));
		$this->assertSame(Errors::IMAGE_TOO_LARGE, $ask([['type' => 'image/png', 'data' => str_repeat('A', 8 * 1024 * 1024)]]));
		$blind = $this->hub(new ScriptEngine([], maxImages: 0));
		$blind->registerScenario('chat', ['system' => 's']);
		$this->assertSame(Errors::NO_IMAGES, $blind->ask('ewa', 'chat', [['role' => 'user', 'text' => 'q']], ['images' => [$png]])['error']);
	}

	public function testMessagesInRequestFormPassAsTheyAre(): void {
		$engine = new ScriptEngine([new Turn('ok')]);
		$hub = $this->hub($engine);
		$hub->registerScenario('chat', ['system' => 's']);
		$doc = Request::document('application/pdf', 'JVBE', 'a.pdf');
		$hub->ask('ewa', 'chat', [Request::message('user', [Request::text('Read this.'), $doc])]);
		$this->assertSame($doc, $engine->requests[0]->messages[0]['parts'][1]);
	}

	public function testAToolNameProvidersRefuseIsRefusedAtOnce(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->hub(new ScriptEngine([]))->registerScenario('x', ['tools' => ['read file' => ['run' => fn () => '']]]);
	}
}
