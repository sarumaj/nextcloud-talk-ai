<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Admin;
use OCA\TalkAi\Ai\Config;
use OCA\TalkAi\Ai\Engine\ClaudeCli;
use OCA\TalkAi\Ai\Engine\Fallback;
use OCA\TalkAi\Ai\Engine\Watched;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Metrics;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Turn;
use OCA\TalkAi\Ai\Watch;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/** The engines' monitoring: every engine's turns recorded alike, and the metrics made from them. */
class MonitoringTest extends TestCase {
	use MemoryConfig;

	/** @var list<array{string, string}> engine and error word of each recorded turn */
	private array $recorded = [];

	private function watch(array $rows = []): Watch {
		$watch = $this->createStub(Watch::class);
		$watch->method('record')->willReturnCallback(function (string $engine, Turn $turn) {
			$this->recorded[] = [$engine, $turn->error];
		});
		$watch->method('all')->willReturn($rows);
		return $watch;
	}

	private function config(): Config {
		$crypto = $this->createStub(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn ($v) => 'enc:' . $v);
		$crypto->method('decrypt')->willReturnCallback(fn ($v) => substr($v, 4));
		return new Config($this->memoryConfig(), $crypto);
	}

	private function engines(Watch $watch): Engines {
		return new Engines($this->memoryConfig(), $this->config(), new \OCA\TalkAi\Ai\Http(new FakeHttp()), $this->createStub(\OCP\TaskProcessing\IManager::class), $watch);
	}

	/** A fallback chain's first engine failing is recorded under its own name, though the next one answered. */
	public function testEveryEngineOfAChainIsRecordedUnderItsName(): void {
		$watch = $this->watch();
		$chain = new Fallback([
			'claude-cli' => new Watched('claude-cli', new ScriptEngine([Turn::failed(Errors::NO_KEY)]), $watch),
			'anthropic' => new Watched('anthropic', new ScriptEngine([new Turn('OK')]), $watch),
		]);
		$this->assertSame('OK', $chain->turn(new Request('', [Request::message('user', [Request::text('Hi')])]))->text);
		$this->assertSame([['claude-cli', Errors::NO_KEY], ['anthropic', '']], $this->recorded);
	}

	/** Engines wraps what it builds; bare() gives the engine's own class back (a CLI's flags in the health check). */
	public function testEnginesWrapTheirEnginesAndUnwrapThem(): void {
		$engines = $this->engines($this->watch());
		$cli = $engines->get('claude-cli');
		$this->assertInstanceOf(Watched::class, $cli);
		$this->assertInstanceOf(ClaudeCli::class, Engines::bare($cli));
		$this->assertInstanceOf(ClaudeCli::class, (new Engines($this->memoryConfig(), $this->config()))->get('claude-cli'), 'without a watch, nothing is wrapped');
	}

	/** The metrics: every engine in use with its role, signed in or not, the times, the failure and the expiry. */
	public function testTheMetricsOfTheEnginesInUse(): void {
		$this->values['ai_engine'] = 'anthropic';
		$this->values['ai_fallback'] = '["openai"]';
		$this->values['ai_scenarios'] = '{"document": {"engine": "gemini"}}';
		$this->values['ai_engine_anthropic'] = '{"model": "claude-opus-5-5", "expires": "2027-11-04"}';
		$this->values['ai_secret_anthropic_key'] = 'enc:sk-ant-secret';
		$watch = $this->watch([
			'anthropic' => ['last_ok' => 2000, 'last_error_at' => 1000, 'last_error' => Errors::BUSY],
			'openai' => ['last_ok' => 1000, 'last_error_at' => 3000, 'last_error' => 'provider:HTTP 500: "oops"'],
		]);
		$metrics = new Metrics($this->engines($watch), $this->config(), $watch);
		$this->assertSame(['anthropic' => 'chosen', 'openai' => 'fallback', 'gemini' => 'scenario'], $metrics->inUse());
		$text = $metrics->render(5000);
		$this->assertStringContainsString('talk_ai_engine_info{engine="anthropic",role="chosen",provider="anthropic",model="claude-opus-5-5"}', $text);
		$this->assertStringContainsString("talk_ai_engine_ready{engine=\"anthropic\"} 1\n", $text);
		$this->assertStringContainsString("talk_ai_engine_auth_ok{engine=\"anthropic\"} 1\n", $text);
		$this->assertStringContainsString("talk_ai_engine_auth_ok{engine=\"gemini\"} 0\n", $text, 'no key: not signed in');
		$this->assertStringContainsString("talk_ai_engine_last_success_timestamp_seconds{engine=\"anthropic\"} 2000\n", $text);
		$this->assertStringContainsString("talk_ai_engine_last_success_timestamp_seconds{engine=\"gemini\"} 0\n", $text);
		$this->assertStringContainsString("talk_ai_engine_failing_info{engine=\"openai\",error=\"provider\"} 1\n", $text, 'the word, not the message');
		$this->assertStringNotContainsString('failing_info{engine="anthropic"', $text, 'it answered after its error');
		$this->assertStringContainsString('talk_ai_engine_credential_expiry_timestamp_seconds{engine="anthropic"} ' . (gmmktime(0, 0, 0, 11, 4, 2027) + 86399) . "\n", $text);
		$this->assertStringNotContainsString('credential_expiry_timestamp_seconds{engine="openai"', $text);
		$this->assertStringContainsString("talk_ai_metrics_timestamp_seconds 5000\n", $text);
		$this->assertStringContainsString("# TYPE talk_ai_engine_ready gauge\n", $text);
		$this->assertStringNotContainsString('sk-ant', $text);
	}

	/** A sign-in that ran out shows as no-key after the last answer, whatever the engine. */
	public function testASignInThatRanOutIsNotOk(): void {
		$this->values['ai_engine'] = 'claude-cli';
		$watch = $this->watch(['claude-cli' => ['last_ok' => 1000, 'last_error_at' => 2000, 'last_error' => Errors::NO_KEY]]);
		$text = (new Metrics($this->engines($watch), $this->config(), $watch))->render();
		$this->assertStringContainsString("talk_ai_engine_auth_ok{engine=\"claude-cli\"} 0\n", $text);
		$this->assertStringContainsString("talk_ai_engine_failing_info{engine=\"claude-cli\",error=\"no-key\"} 1\n", $text);
	}

	/** --probe-stale asks only the engines failing since their last answer or silent for a day. */
	public function testOnlyStaleEnginesAreProbed(): void {
		$this->values['ai_engine'] = 'anthropic';
		$this->values['ai_fallback'] = '["openai", "gemini", "claude-cli"]';
		$now = 1_000_000;
		$watch = $this->watch([
			'anthropic' => ['last_ok' => $now - 600, 'last_error_at' => $now - 900, 'last_error' => Errors::BUSY],
			'openai' => ['last_ok' => $now - 600, 'last_error_at' => $now - 60, 'last_error' => Errors::NO_KEY],
			'gemini' => ['last_ok' => $now - 90000, 'last_error_at' => 0, 'last_error' => ''],
		]);
		$this->assertSame(['openai', 'gemini', 'claude-cli'], (new Metrics($this->engines($watch), $this->config(), $watch))->stale($now));
	}

	public function testTheExpiryIsADate(): void {
		$this->assertSame(gmmktime(23, 59, 59, 2, 28, 2027), Metrics::expiry('2027-02-28'));
		$this->assertNull(Metrics::expiry('2027-02-30'));
		$this->assertNull(Metrics::expiry('next year'));
		$this->assertNull(Metrics::expiry(''));
		$admin = new Admin(new Engines($this->memoryConfig(), $this->config()), $this->config(), $this->memoryConfig());
		$this->assertSame(['expires' => 'a date (YYYY-MM-DD)'], $admin->save('claude-cli', ['expires' => '30.02.2027']));
		$this->assertSame([], $admin->save('claude-cli', ['expires' => '2027-11-04']));
		$this->assertSame('2027-11-04', json_decode($this->values['ai_engine_claude-cli'], true)['expires']);
	}
}
