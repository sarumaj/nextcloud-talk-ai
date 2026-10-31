<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/** A ticket whose hand-off over HTTP failed: worked out with the next cron run. */
class TicketJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Tickets $tickets,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$this->tickets->run((string)($argument['id'] ?? ''));
	}
}
