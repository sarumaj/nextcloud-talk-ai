<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

/**
 * A live Claude Code process between Loop's turns (Turn::$session): its MCP
 * bridge's socket, the calls waiting there for their results, and what the
 * process was started with, so a request with another prompt or other tools gets
 * a new one. Dropping the session ends the process.
 */
final class ClaudeSession {
	/** @var array<string, resource> tool_use id => the bridge's connection waiting for the result */
	public array $waiting = [];
	/** @var array<string, array{name: string, args: array}> calls the model made, by tool_use id */
	public array $calls = [];
	/** Cost reported so far (the CLI reports it summed per process). */
	public float $cost = 0.0;
	/** Whether the process answered its last question and waits for the next. */
	public bool $idle = false;

	/** @param resource $server */
	public function __construct(
		public readonly CliProcess $process,
		public $server,
		public readonly string $token,
		public readonly string $fingerprint,
	) {
	}

	public function alive(): bool {
		return $this->process->running();
	}

	public function __destruct() {
		foreach ($this->waiting as $conn) {
			@fclose($conn);
		}
		if (is_resource($this->server)) {
			fclose($this->server);
		}
	}
}
