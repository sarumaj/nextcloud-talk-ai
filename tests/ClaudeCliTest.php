<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Engine\ClaudeCli;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\Ai\Loop;
use OCA\TalkAi\Ai\Request;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Claude Code with a fake binary (tests/fixtures/fake-claude) that speaks
 * stream-json and starts the real MCP bridge, which calls back over the socket.
 */
class ClaudeCliTest extends TestCase {
	private string $home;
	private array $ran = [];

	protected function setUp(): void {
		$this->home = sys_get_temp_dir() . '/talkai-test-' . bin2hex(random_bytes(4));
		mkdir($this->home, 0700);
	}

	protected function tearDown(): void {
		foreach (glob($this->home . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->home);
	}

	private function engine(array $script, array $settings = []): ClaudeCli {
		file_put_contents($this->home . '/script.json', json_encode($script));
		return new ClaudeCli($settings + ['binary' => dirname(__DIR__) . '/tests/fixtures/fake-claude', 'home' => $this->home, 'timeout' => 20]);
	}

	/** @return list<array> what the fake logged */
	private function log(): array {
		return array_map(fn ($l) => json_decode($l, true), file($this->home . '/log.jsonl', FILE_IGNORE_NEW_LINES) ?: []);
	}

	private function tools(): array {
		return ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']]],
			'run' => function (string $uid, array $args) {
				$this->ran[] = [$uid, $args];
				return (string)($args['a'] + $args['b']);
			}]];
	}

	private static function ask(string $text): Request {
		return new Request('You add.', [Request::message('user', [Request::text($text)])], ['add' => ['description' => 'Adds.', 'input' => ['type' => 'object']]]);
	}

	public function testAPlainAnswerStreamsAndReportsUsageAndCost(): void {
		$engine = $this->engine([[['text' => 'Hello there.']]]);
		$this->assertTrue($engine->status()->ready);
		$deltas = [];
		$request = new Request('Be kind.', [Request::message('user', [Request::text('Hi'), Request::image('image/png', 'UE5H')])], onDelta: function (string $d) use (&$deltas) {
			$deltas[] = $d;
		});
		$turn = $engine->turn($request);
		$this->assertSame('', $turn->error);
		$this->assertSame('Hello there.', $turn->text);
		$this->assertSame('Hello there.', implode('', $deltas));
		$this->assertSame([115, 7, 100, 0.01], [$turn->usage->in, $turn->usage->out, $turn->usage->cachedIn, $turn->usage->cost]);
		$log = $this->log();
		$start = $log[0];
		$user = array_values(array_filter($log, fn ($e) => isset($e['user'])))[0];
		foreach (['--print', '--no-session-persistence', '--strict-mcp-config', '--include-partial-messages'] as $flag) {
			$this->assertContains($flag, $start['argv']);
		}
		// No built-in tools, the prompt as given, and nothing of the server's environment.
		$this->assertSame('', $start['argv'][array_search('--tools', $start['argv'], true) + 1]);
		$this->assertSame('Be kind.', $start['argv'][array_search('--system-prompt', $start['argv'], true) + 1]);
		$this->assertEqualsCanonicalizing(['HOME', 'PATH', 'LANG', 'MCP_TOOL_TIMEOUT', 'DISABLE_AUTOUPDATER', 'CLAUDE_CODE_DISABLE_AUTO_MEMORY'], $start['env']);
		$this->assertSame(['text', 'image'], array_column($user['user'], 'type'));
	}

	public function testToolCallsGoThroughTheMcpBridgeAndLoopRunsThem(): void {
		$engine = $this->engine([[['call' => ['name' => 'add', 'args' => ['a' => 2, 'b' => 3]]], ['call' => ['name' => 'add', 'args' => ['a' => 4, 'b' => 5]]], ['text' => 'Five and nine.']]]);
		$result = (new Loop($engine))->run('ewa', self::ask('2+3 and 4+5?'), $this->tools());
		$this->assertSame(['done', 'Five and nine.'], [$result['state'], $result['text']]);
		$this->assertSame([['ewa', ['a' => 2, 'b' => 3]], ['ewa', ['a' => 4, 'b' => 5]]], $this->ran);
		$log = $this->log();
		$this->assertSame(['add'], $log[1]['tools'], 'the bridge listed the tools');
		$results = array_values(array_filter($log, fn ($e) => isset($e['result'])));
		$this->assertSame('5', $results[0]['result']['content'][0]['text']);
		$this->assertSame('9', $results[1]['result']['content'][0]['text']);
		$this->assertCount(1, array_filter($log, fn ($e) => isset($e['argv'])), 'one process for the whole run');
		$this->assertStringStartsWith('toolu_', Request::parts($result['messages'][1], 'tool_call')[0]['id'], 'calls keep the CLI\'s ids');
	}

	public function testParallelCallsComeToLoopTogether(): void {
		$engine = $this->engine([[['calls' => [['name' => 'add', 'args' => ['a' => 1, 'b' => 1]], ['name' => 'add', 'args' => ['a' => 2, 'b' => 2]]]], ['text' => 'Two and four.']]]);
		$result = (new Loop($engine))->run('ewa', self::ask('1+1, 2+2'), $this->tools());
		$this->assertSame('Two and four.', $result['text']);
		$this->assertCount(2, $this->ran);
	}

	public function testANextQuestionGoesToTheSameProcess(): void {
		$engine = $this->engine([[['text' => 'One.']], [['text' => 'Two.']]]);
		$engines = new Engines($this->createMock(IAppConfig::class));
		$engines->fix($engine);
		$hub = new Hub($engines);
		$hub->registerScenario('job', ['system' => 's']);
		$first = $hub->ask('ewa', 'job', [['role' => 'user', 'text' => 'first']]);
		$second = $hub->ask('ewa', 'job', [...$first['messages'], ['role' => 'user', 'text' => 'go on']], ['session' => $first['session']]);
		$this->assertSame('Two.', $second['text']);
		$log = $this->log();
		$this->assertCount(1, array_filter($log, fn ($e) => isset($e['argv'])));
		$this->assertSame('go on', end($log)['user'][0]['text'], 'only the new message, not the history again');
	}

	public function testTheTextProtocolWhenMcpIsOff(): void {
		$engine = $this->engine([[['call' => ['name' => 'add', 'args' => ['a' => 2, 'b' => 3]]], ['text' => 'Adding.']], [['text' => 'Five.']]], ['flags' => ['mcp' => false]]);
		$result = (new Loop($engine))->run('ewa', self::ask('2+3?'), $this->tools());
		$this->assertSame('Five.', $result['text']);
		$this->assertSame([['ewa', ['a' => 2, 'b' => 3]]], $this->ran);
		$log = $this->log();
		$this->assertNotContains('--mcp-config', $log[0]['argv']);
		$this->assertStringContainsString('"tool_calls"', $log[0]['argv'][array_search('--system-prompt', $log[0]['argv'], true) + 1]);
		// The second process got the history as a transcript and the result as words.
		$second = implode("\n", array_column(end($log)['user'], 'text'));
		$this->assertStringContainsString('Earlier in this conversation', $second);
		$this->assertStringContainsString('"tool_results"', $second);
	}

	public function testAStructuredAnswerUsesTheCliSchema(): void {
		$engine = $this->engine([[['text' => '{"sum":5}', 'structured' => ['sum' => 5]]]]);
		$turn = $engine->turn(new Request('s', [Request::message('user', [Request::text('2+3')])], answerSchema: ['type' => 'object']));
		$this->assertSame(['sum' => 5], $turn->answer);
		$this->assertContains('--json-schema', $this->log()[0]['argv']);
	}

	public function testATurnThatTakesTooLongIsKilled(): void {
		$engine = $this->engine([[['sleep' => 30]]], ['timeout' => 1]);
		$started = microtime(true);
		$turn = $engine->turn(self::ask('slow'));
		$this->assertSame(Errors::TIMEOUT, $turn->error);
		$this->assertLessThan(10, microtime(true) - $started);
	}

	public function testErrorsAndReadiness(): void {
		$this->assertSame(Errors::NO_KEY, $this->engine([[['error' => 'Invalid API key · Please run /login']]])->turn(self::ask('x'))->error);
		@unlink($this->home . '/turns');
		$this->assertSame(Errors::RATE_LIMITED, $this->engine([[['error' => 'Claude AI usage limit reached|1793452200']]])->turn(self::ask('x'))->error);

		$this->assertSame(Errors::NO_CLI, (new ClaudeCli(['binary' => '/nonexistent/claude', 'home' => $this->home]))->status()->reason);
		$this->assertSame(Errors::NO_KEY, (new ClaudeCli(['binary' => dirname(__DIR__) . '/tests/fixtures/fake-claude', 'home' => '']))->status()->reason);
		// A CLI without a flag the engine needs is not used; one without MCP falls back to text.
		$forget = fn () => array_map('unlink', glob(sys_get_temp_dir() . '/talkai-cli-help-*') ?: []);
		$forget();
		file_put_contents($this->home . '/help.txt', "  -p, --print\n  --input-format\n  --output-format\n  --verbose\n  --tools\n  --system-prompt\n");
		$old = $this->engine([]);
		$this->assertSame(Errors::NO_CLI, $old->status()->reason);
		file_put_contents($this->home . '/help.txt', "  -p, --print\n  --input-format\n  --output-format\n  --verbose\n  --no-session-persistence\n  --tools\n  --system-prompt\n  --allowedTools\n");
		$forget();
		$older = new ClaudeCli(['binary' => dirname(__DIR__) . '/tests/fixtures/fake-claude', 'home' => $this->home]);
		$this->assertTrue($older->status()->ready);
		$this->assertFalse($older->mcpOn());
		$this->assertContains('--mcp-config', $older->missingFlags());
	}
}
