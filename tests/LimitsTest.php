<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Config;
use OCA\TalkAi\Ai\Counters;
use OCA\TalkAi\Ai\Admin;
use OCA\TalkAi\Ai\Engine\Fallback;
use OCA\TalkAi\Ai\Engine\Missing;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\Ai\Limits;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\Turn;
use OCA\TalkAi\Ai\Usage;
use OCP\IGroupManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/** Limits over a table in memory: the same counting as over talk_ai_ai_slots. */
class MemoryLimits extends Limits {
	/** @var array<int, array{user: string, started: int, ended: int, lease: int}> */
	public array $rows = [];
	private int $next = 0;

	/** Without a database: the rows above are the table. */
	public function __construct(\OCP\IAppConfig $appConfig) {
		$this->appConfig = $appConfig;
	}

	protected function open(string $userId, string $scenario): int {
		$this->rows[++$this->next] = ['user' => $userId, 'started' => time(), 'ended' => 0, 'lease' => time() + self::LEASE];
		return $this->next;
	}

	protected function delete(int $id): void {
		unset($this->rows[$id]);
	}

	protected function count(?int $since = null, ?string $userId = null, bool $open = false, ?int $upTo = null): int {
		return count(array_filter($this->rows, fn ($r, $id) => ($since === null || $r['started'] > $since) && ($userId === null || $r['user'] === $userId)
			&& (!$open || ($r['ended'] === 0 && $r['lease'] > time())) && ($upTo === null || $id <= $upTo), ARRAY_FILTER_USE_BOTH));
	}

	public function renew(int $id): void {
		$this->rows[$id]['lease'] = time() + self::LEASE;
	}

	public function release(int $id): void {
		$this->rows[$id]['ended'] = time();
	}
}

/** Counters in memory. */
class MemoryCounters extends Counters {
	public array $added = [];

	public function __construct() {
	}

	public function add(string $engine, bool $ok, array $usage): void {
		$this->added[] = [$engine, $ok, $usage['in'], $usage['out']];
	}
}

/** Limits, the fallback chain, access, scenario overrides and the administration. */
class LimitsTest extends TestCase {
	use MemoryConfig;

	private function limits(): MemoryLimits {
		$limits = new MemoryLimits($this->memoryConfig());
		$limits->pause = function () {
		};
		return $limits;
	}

	public function testTheRatePerPersonAndMinute(): void {
		$this->values['ai_rate_per_minute'] = '2';
		$limits = $this->limits();
		$this->assertArrayHasKey('id', $limits->acquire('ewa', 'chat'));
		$this->assertArrayHasKey('id', $limits->acquire('ewa', 'chat'));
		$this->assertSame(['error' => Errors::RATE_LIMITED], $limits->acquire('ewa', 'chat'));
		$this->assertArrayHasKey('id', $limits->acquire('jan', 'chat'), 'per person');
		$limits->rows = array_map(fn ($r) => ['started' => time() - 61] + $r, $limits->rows);
		$this->assertArrayHasKey('id', $limits->acquire('ewa', 'chat'), 'a minute later');
	}

	public function testQuestionsAtOnceWaitForASlotAndADeadWorkersSlotRunsOut(): void {
		$this->values['ai_concurrent'] = '2';
		$this->values['ai_rate_per_minute'] = '0';
		$limits = $this->limits();
		$a = $limits->acquire('ewa', 'chat')['id'];
		$limits->acquire('jan', 'chat');
		$this->assertSame(['error' => Errors::BUSY], $limits->acquire('ola', 'chat', wait: 0));
		$this->assertCount(2, $limits->rows, 'the refused one left no slot');
		$limits->release($a);
		$this->assertArrayHasKey('id', $limits->acquire('ola', 'chat', wait: 0));
		// A worker that died never released its slot: the lease runs out.
		$limits->rows = array_map(fn ($r) => ['lease' => time() - 1] + $r, $limits->rows);
		$this->assertSame(0, $limits->running());
	}

	private function hub(ScriptEngine $engine, MemoryLimits $limits, ?MemoryCounters $counters = null, ?IGroupManager $groups = null): Hub {
		$engines = new Engines($this->memoryConfig());
		$engines->fix($engine);
		$hub = new Hub($engines, null, $limits, $counters, $groups);
		$hub->registerScenario('chat', ['system' => 'Be brief.']);
		return $hub;
	}

	public function testTheHubHoldsASlotPerQuestionAndCountsIt(): void {
		$limits = $this->limits();
		$counters = new MemoryCounters();
		$engine = new ScriptEngine([new Turn('Hi.', usage: new Usage(10, 2, reported: true))]);
		$result = $this->hub($engine, $limits, $counters)->ask('ewa', 'chat', [['role' => 'user', 'text' => 'Hello']]);
		$this->assertSame('done', $result['state']);
		$this->assertSame(0, $limits->running(), 'released');
		$this->assertCount(1, $limits->rows);
		$this->assertSame([['script', true, 10, 2]], $counters->added);

		$this->values['ai_rate_per_minute'] = '1';
		$this->assertSame(Errors::RATE_LIMITED, $this->hub(new ScriptEngine([]), $limits)->ask('ewa', 'chat', [['role' => 'user', 'text' => 'Again']])['error']);
		// The probe does not count.
		$this->assertSame('done', $this->hub(new ScriptEngine([new Turn('ok')]), $limits)->ask('ewa', 'chat', [['role' => 'user', 'text' => 'x']], ['limits' => false])['state']);
	}

	public function testOnlyTheGroupsMayAskWhenSome(): void {
		$this->values['ai_groups'] = '["staff"]';
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn ($uid, $group) => $uid === 'ewa' && $group === 'staff');
		$hub = $this->hub(new ScriptEngine([new Turn('a'), new Turn('b'), new Turn('c')]), $this->limits(), null, $groups);
		$this->assertSame('done', $hub->ask('ewa', 'chat', [['role' => 'user', 'text' => 'q']])['state']);
		$this->assertSame(Errors::USER_NOT_ALLOWED, $hub->ask('jan', 'chat', [['role' => 'user', 'text' => 'q']])['error']);
		$this->assertSame('done', $hub->ask('guest:abc', 'chat', [['role' => 'user', 'text' => 'q']])['state'], 'guests follow the bot\'s own rule');
		$this->assertSame('done', $hub->ask('nextcloud', 'chat', [['role' => 'user', 'text' => 'q']])['state']);
	}

	public function testAScenarioGetsTheAdministratorsPromptAndImageLimit(): void {
		$this->values['ai_scenarios'] = '{"chat": {"prompt": "Always answer in German."}}';
		$this->values['ai_max_images'] = '1';
		$engine = new ScriptEngine([new Turn('Hallo.')]);
		$hub = $this->hub($engine, $this->limits());
		$this->assertSame(1, $hub->status('chat')->maxImages);
		$hub->ask('ewa', 'chat', [['role' => 'user', 'text' => 'Hi']]);
		$this->assertStringEndsWith("administrator:\nAlways answer in German.", $engine->requests[0]->system);
		$png = ['type' => 'image/png', 'data' => 'UE5H'];
		$this->assertSame(Errors::TOO_MANY_IMAGES, $hub->ask('ewa', 'chat', [['role' => 'user', 'text' => 'q']], ['images' => [$png, $png]])['error']);
	}

	public function testTheFallbackChainPassesOnBusyAndKeepsASessionWithItsEngine(): void {
		$busy = new ScriptEngine([Turn::failed(Errors::BUSY)]);
		$second = new ScriptEngine([new Turn('From the second.', session: 'live'), new Turn('Still the second.')]);
		$chain = new Fallback(['first' => new Missing(Errors::NO_KEY), 'busy' => $busy, 'second' => $second]);
		$this->assertTrue($chain->status()->ready);
		$request = new Request('s', [Request::message('user', [Request::text('q')])]);
		$turn = $chain->turn($request);
		$this->assertSame('From the second.', $turn->text);
		$this->assertSame(['fallback' => 'second', 'inner' => 'live'], $turn->session);
		$next = $chain->turn($request->with($request->messages, $turn->session));
		$this->assertSame('Still the second.', $next->text);
		$this->assertSame('live', $second->requests[1]->session, 'the engine gets its own session back');
		$this->assertCount(1, $busy->requests, 'the busy one was not asked again');
		// A wrong answer is not passed on: the next engine would only hide it.
		$this->assertSame(Errors::BAD_ANSWER, (new Fallback(['a' => new ScriptEngine([Turn::failed(Errors::BAD_ANSWER)]), 'b' => new ScriptEngine([new Turn('x')])]))->turn($request)->error);
	}

	public function testTheChosenEngineWithItsFallbacks(): void {
		$this->values['ai_engine'] = 'anthropic';
		$this->values['ai_fallback'] = '["openai", "nonsense", "anthropic"]';
		$engines = new Engines($this->memoryConfig(), $this->config(), new \OCA\TalkAi\Ai\Http(new FakeHttp()));
		$this->assertSame(['openai', 'anthropic'], $engines->fallback());
		$chain = $engines->get();
		$this->assertInstanceOf(Fallback::class, $chain);
		$this->assertSame(['anthropic', 'openai'], array_keys($chain->members()));
	}

	private function config(): Config {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn ($v) => 'enc:' . $v);
		$crypto->method('decrypt')->willReturnCallback(fn ($v) => substr($v, 4));
		return new Config($this->memoryConfig(), $crypto);
	}

	public function testTheAdministrationKeepsKeysApartAndChecksWhatItSaves(): void {
		$admin = new Admin(new Engines($this->memoryConfig(), $this->config(), new \OCA\TalkAi\Ai\Http(new FakeHttp()), $this->createMock(\OCP\TaskProcessing\IManager::class)),
			$this->config(), $this->memoryConfig());
		$this->assertSame([], $admin->save('anthropic', ['key' => 'sk-ant-secret', 'model' => 'claude-opus-5-5', 'binary' => '/bin/sh', 'flags' => ['search' => false]]));
		$this->assertStringStartsWith('enc:', $this->values['ai_secret_anthropic_key']);
		$anthropic = array_values(array_filter($admin->engines(), fn ($e) => $e['name'] === 'anthropic'))[0];
		$this->assertTrue($anthropic['keySet']);
		$this->assertStringNotContainsString('sk-ant-secret', (string)json_encode($admin->engines()));
		$this->assertArrayNotHasKey('binary', $anthropic['settings'], 'only the engine\'s own fields');
		$this->assertFalse($anthropic['settings']['flags']['search']);
		$this->assertSame(['baseUrl' => 'an http(s) URL'], $admin->save('openai', ['baseUrl' => 'file:///etc/passwd']));
		$this->assertSame(['home' => 'an absolute path'], $admin->save('claude-cli', ['home' => '../x']));
		$admin->clearKey('anthropic');
		$this->assertArrayNotHasKey('ai_secret_anthropic_key', $this->values);

		$admin->choose('claude-cli', ['anthropic', 'claude-cli', 'nope']);
		$this->assertSame(['claude-cli', '["anthropic"]'], [$this->values['ai_engine'], $this->values['ai_fallback']]);
		$admin->setScenarios(['chat' => ['engine' => 'gemini', 'model' => 'x', 'prompt' => ''], 'nope' => ['model' => 'y'], 'document' => ['engine' => 'nope']]);
		$this->assertSame(['chat' => ['engine' => 'gemini', 'model' => 'x']], $admin->scenarios());
	}

	public function testTheConnectionTestAndTheProbe(): void {
		$engines = new Engines($this->memoryConfig());
		$admin = new Admin($engines, $this->config(), $this->memoryConfig());
		$engines->fix(new ScriptEngine([new Turn('OK'), new Turn(toolCalls: [['id' => 'p', 'name' => 'ping', 'args' => []]]), new Turn('done')]));
		$test = $admin->test('anthropic');
		$this->assertSame([true, 'OK'], [$test['ok'], $test['text']]);
		$this->assertTrue($admin->probe('anthropic')['ok']);
		$engines->fix(new Missing(Errors::NO_KEY));
		$this->assertSame([false, Errors::NO_KEY], [$admin->test('x')['ok'], $admin->test('x')['error']]);
	}
}
