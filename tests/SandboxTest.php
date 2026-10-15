<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Service\Sandbox;
use PHPUnit\Framework\TestCase;

/** The runner's answers, scripted: a command that is still running after the first waits. */
class ScriptedSandbox extends Sandbox {
	public array $calls = [];

	public function __construct(private array $answers) {
	}

	protected function call(string $op, array $params, int $timeout): array {
		$this->calls[] = [$op, $params, $timeout];
		return $op === 'exec_stop' ? ['exit_code' => -9, 'stdout' => '', 'stderr' => '[stopped on request]', 'truncated' => false, 'timed_out' => false]
			: array_shift($this->answers);
	}
}

/** Commands in the sandbox are watched, not timed: polled while they run, stopped on request. */
class SandboxTest extends TestCase {
	private const RUNNING = ['running' => true, 'run' => 'r1', 'seconds' => 10, 'last' => 'page 3 of 9'];
	private const DONE = ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'truncated' => false, 'timed_out' => false, 'stalled' => false];

	public function testALongCommandIsPolledUntilItEnds(): void {
		$sandbox = new ScriptedSandbox([self::RUNNING, self::RUNNING, self::DONE]);
		$seen = [];
		$r = $sandbox->exec('job1', 'python3 build.py', 1800, function (int $seconds, string $last) use (&$seen): bool {
			$seen[] = $last;
			return true;
		});
		$this->assertSame('ok', $r['stdout']);
		$this->assertSame(['exec', 'exec_wait', 'exec_wait'], array_column($sandbox->calls, 0));
		$this->assertSame(['page 3 of 9', 'page 3 of 9'], $seen);
		// No request waits long on the runner: it answers within its wait.
		$this->assertLessThanOrEqual(630, max(array_column($sandbox->calls, 2)));
		$this->assertSame('r1', $sandbox->calls[1][1]['run']);
	}

	public function testACancelledJobStopsItsCommand(): void {
		$sandbox = new ScriptedSandbox([self::RUNNING, self::RUNNING, self::DONE]);
		$r = $sandbox->exec('job1', 'sleep 999', 1800, fn () => false);
		$this->assertSame(['exec', 'exec_stop'], array_column($sandbox->calls, 0));
		$this->assertStringContainsString('stopped', $r['stderr']);
	}

	public function testAnOlderRunnerAnswersAtTheEnd(): void {
		$sandbox = new ScriptedSandbox([['exit_code' => 0, 'stdout' => 'old', 'stderr' => '', 'truncated' => false, 'timed_out' => false]]);
		$this->assertSame('old', $sandbox->exec('job1', 'true', 300)['stdout']);
		// Long enough for a runner that answers only when the command is done.
		$this->assertGreaterThanOrEqual(330, $sandbox->calls[0][2]);
	}
}
