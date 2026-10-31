<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\Turn;

/** The engine when none is set up, or the one chosen cannot be built: never ready. */
final class Missing implements Engine {
	public function __construct(
		private string $reason = Errors::NOT_READY,
		private string $name = '',
	) {
	}

	public function status(): Status {
		return Status::notReady($this->reason, $this->name);
	}

	public function turn(Request $request): Turn {
		return Turn::failed($this->reason);
	}

	public function models(): array {
		return [];
	}
}
