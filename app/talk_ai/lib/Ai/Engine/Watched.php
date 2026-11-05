<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\Turn;
use OCA\TalkAi\Ai\Watch;

/**
 * An engine whose turns Watch records under its name (Engines wraps each one it
 * builds): monitoring sees every engine alike, also one of a fallback chain whose
 * error the next engine answered over.
 */
final class Watched implements Engine {
	public function __construct(
		private string $name,
		private Engine $inner,
		private Watch $watch,
	) {
	}

	public function status(): Status {
		return $this->inner->status();
	}

	public function turn(Request $request): Turn {
		$turn = $this->inner->turn($request);
		$this->watch->record($this->name, $turn);
		return $turn;
	}

	public function models(): array {
		return $this->inner->models();
	}

	/** The engine itself, for what only its own class offers (a CLI's flags). */
	public function inner(): Engine {
		return $this->inner;
	}
}
