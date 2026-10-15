<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\BackgroundJob;

use OCA\TalkAi\Service\Bot;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Renames the bot in Talk when AI-Hub's AI for it or the name setting changed. */
class SyncName extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Bot $bot,
	) {
		parent::__construct($time);
		$this->setInterval(10 * 60);
	}

	protected function run($argument): void {
		$this->bot->sync();
	}
}
