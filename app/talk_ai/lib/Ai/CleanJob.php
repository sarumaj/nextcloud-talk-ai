<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Hourly: tickets past their time, conversations no longer used, old slots and counters. */
class CleanJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Tickets $tickets,
		private Conversations $conversations,
		private Limits $limits,
		private Counters $counters,
		private \OCP\IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void {
		$this->tickets->clean();
		$this->conversations->clean();
		$this->limits->clean();
		$this->counters->clean(max(7, (int)$this->appConfig->getValueString(Engines::APP, 'retention_days', '90')));
	}
}
