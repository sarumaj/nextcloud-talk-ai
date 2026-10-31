<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Engine\GeminiCli;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Loop;
use OCA\TalkAi\Ai\Request;
use PHPUnit\Framework\TestCase;

/** Gemini CLI with a fake binary (tests/fixtures/fake-gemini): one run per turn, tools as text. */
class GeminiCliTest extends TestCase {
	private string $home;

	protected function setUp(): void {
		$this->home = sys_get_temp_dir() . '/talkai-test-' . bin2hex(random_bytes(4));
		mkdir($this->home, 0700);
		array_map('unlink', glob(sys_get_temp_dir() . '/talkai-cli-help-*') ?: []);
	}

	protected function tearDown(): void {
		array_map('unlink', glob($this->home . '/*') ?: []);
		rmdir($this->home);
	}

	private function engine(array $answers, array $settings = []): GeminiCli {
		file_put_contents($this->home . '/answers.json', json_encode($answers));
		return new GeminiCli($settings + ['binary' => dirname(__DIR__) . '/tests/fixtures/fake-gemini', 'home' => $this->home, 'timeout' => 20]);
	}

	private function log(): array {
		return array_map(fn ($l) => json_decode($l, true), file($this->home . '/log.jsonl', FILE_IGNORE_NEW_LINES) ?: []);
	}

	public function testAnAnswerWithItsUsageAndEveryToolOfTheCliDenied(): void {
		$engine = $this->engine([['response' => 'Hello.']]);
		$status = $engine->status();
		$this->assertTrue($status->ready);
		$this->assertSame([false, 0, false], [$status->images, $status->maxImages, $status->search]);
		$turn = $engine->turn(new Request('Be kind.', [Request::message('user', [Request::text('Hi')])]));
		$this->assertSame('Hello.', $turn->text);
		$this->assertSame([50, 8, 20], [$turn->usage->in, $turn->usage->out, $turn->usage->cachedIn]);
		$log = $this->log()[0];
		$this->assertStringContainsString('decision = "deny"', $log['policy']);
		$this->assertStringContainsString('toolName = "*"', $log['policy']);
		$this->assertSame(GeminiCli::POLICY, $log['argv'][array_search('--admin-policy', $log['argv'], true) + 1], 'a fixed file a sandbox can grant');
		$this->assertStringStartsWith('Be kind.', $log['prompt'], 'the prompt on stdin, not as an argument');
		$this->assertNotContains('Hi', $log['argv']);
	}

	public function testToolsGoByTheTextProtocol(): void {
		$engine = $this->engine([
			['response' => "Adding.\n```json\n{\"tool_calls\": [{\"name\": \"add\", \"args\": {\"a\": 2, \"b\": 3}}]}\n```"],
			['response' => 'Five.'],
		]);
		$ran = [];
		$tools = ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object'], 'run' => function (string $uid, array $args) use (&$ran) {
			$ran[] = $args;
			return (string)($args['a'] + $args['b']);
		}]];
		$request = new Request('s', [Request::message('user', [Request::text('2+3?')])], ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object']]]);
		$result = (new Loop($engine))->run('ewa', $request, $tools);
		$this->assertSame('Five.', $result['text']);
		$this->assertSame([['a' => 2, 'b' => 3]], $ran);
		$second = $this->log()[1]['prompt'];
		$this->assertStringContainsString('Earlier in this conversation', $second);
		$this->assertStringContainsString('"tool_results"', $second);
	}

	public function testErrorsTimeoutAndReadiness(): void {
		$this->assertSame(Errors::RATE_LIMITED, $this->engine([['error' => ['message' => 'Quota exceeded for quota metric']]])->turn(new Request('s', [Request::message('user', [Request::text('x')])]))->error);
		@unlink($this->home . '/turns');
		$this->assertSame(Errors::TIMEOUT, $this->engine([['sleep' => 5]], ['timeout' => 1])->turn(new Request('s', [Request::message('user', [Request::text('x')])]))->error);
		$this->assertSame(Errors::NO_CLI, (new GeminiCli(['binary' => '/nonexistent/gemini', 'home' => $this->home]))->status()->reason);
		$this->assertSame(Errors::NO_KEY, (new GeminiCli(['binary' => dirname(__DIR__) . '/tests/fixtures/fake-gemini']))->status()->reason);
		// A CLI that cannot deny its own tools is not used.
		array_map('unlink', glob(sys_get_temp_dir() . '/talkai-cli-help-*') ?: []);
		file_put_contents($this->home . '/help.txt', "  -m, --model\n  -o, --output-format\n");
		$this->assertSame(Errors::NO_CLI, $this->engine([])->status()->reason);
	}
}
