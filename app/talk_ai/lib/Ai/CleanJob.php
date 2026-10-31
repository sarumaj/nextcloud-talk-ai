<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Hourly: tickets past their time and remembered conversations no longer used. */
class CleanJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Tickets $tickets,
		private Conversations $conversations,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void {
		$this->tickets->clean();
		$this->conversations->clean();
	}
}
