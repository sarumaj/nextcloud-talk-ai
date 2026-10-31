<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\Turn;

/**
 * An ordered chain of engines (ai_engine, then ai_fallback): a turn goes to the
 * first that is ready, and to the next when it answers not ready, busy,
 * rate-limited or with a provider's 5xx (Errors::passOn). A turn that goes on
 * from an engine's own session (a CLI's live process) stays with that engine.
 */
final class Fallback implements Engine {
	/** @param array<string, Engine> $engines name => engine, in order */
	public function __construct(
		private array $engines,
	) {
	}

	public function status(): Status {
		$first = null;
		foreach ($this->engines as $engine) {
			$status = $engine->status();
			if ($status->ready) {
				return $status;
			}
			$first ??= $status;
		}
		return $first ?? Status::notReady(Errors::NOT_READY);
	}

	public function turn(Request $request): Turn {
		$session = is_array($request->session) && isset($request->session['fallback']) ? $request->session : null;
		if ($session !== null && isset($this->engines[$session['fallback']])) {
			return $this->wrap($session['fallback'], $this->engines[$session['fallback']]->turn($request->with($request->messages, $session['inner'])));
		}
		$turn = Turn::failed(Errors::NOT_READY);
		foreach ($this->engines as $name => $engine) {
			if (!$engine->status()->ready) {
				continue;
			}
			$turn = $engine->turn($request->with($request->messages, null));
			if ($turn->ok() || !Errors::passOn($turn->error)) {
				return $this->wrap($name, $turn);
			}
		}
		return $turn;
	}

	public function models(): array {
		$first = reset($this->engines);
		return $first !== false ? $first->models() : [];
	}

	/** @return array<string, Engine> */
	public function members(): array {
		return $this->engines;
	}

	/** The session marked with the engine that made it. */
	private function wrap(string $name, Turn $turn): Turn {
		if ($turn->session === null) {
			return $turn;
		}
		return new Turn($turn->text, $turn->toolCalls, $turn->answer, $turn->usage, ['fallback' => $name, 'inner' => $turn->session],
			$turn->stopReason, $turn->error, $turn->raw);
	}
}
