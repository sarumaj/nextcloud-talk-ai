<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/** Tokens of one or more model calls, as the provider reports them. */
final class Usage {
	public function __construct(
		public readonly int $in = 0,
		public readonly int $out = 0,
		/** Of $in, the tokens read from the provider's prompt cache. */
		public readonly int $cachedIn = 0,
		/** In US dollars where the provider says (a CLI's result), else null. */
		public readonly ?float $cost = null,
		/** Whether the provider reported it: false means the numbers are 0, not measured. */
		public readonly bool $reported = false,
	) {
	}

	public function add(self $other): self {
		return new self($this->in + $other->in, $this->out + $other->out, $this->cachedIn + $other->cachedIn,
			$this->cost === null && $other->cost === null ? null : (float)$this->cost + (float)$other->cost, $this->reported || $other->reported);
	}

	/** @return array{in: int, out: int, cachedIn: int, cost: ?float, reported: bool} */
	public function toArray(): array {
		return get_object_vars($this);
	}
}
