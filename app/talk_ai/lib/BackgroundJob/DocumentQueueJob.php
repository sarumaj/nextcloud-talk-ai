<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\BackgroundJob;

use OCA\TalkAi\Service\DocumentJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/** A document job the Dispatcher could not hand off over HTTP: run with the next cron run. */
class DocumentQueueJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private DocumentJob $documents,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		set_time_limit(0);
		$this->documents->run((int)($argument['job'] ?? 0));
	}
}
