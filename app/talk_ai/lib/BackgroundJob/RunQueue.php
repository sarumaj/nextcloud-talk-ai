<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\BackgroundJob;

use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Dispatcher;
use OCA\TalkAi\Service\Jobs;
use OCA\TalkAi\Service\Sandbox;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * The document queue: jobs lost while running (a restart, a crash: no heartbeat for
 * fifteen minutes) go back into the queue once, and queued jobs start while the
 * sandbox has room.
 */
class RunQueue extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Jobs $jobs,
		private Sandbox $sandbox,
		private Dispatcher $dispatcher,
		private Conversation $conversation,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void {
		foreach ($this->jobs->stale() as $job) {
			$again = $this->jobs->recover($job);
			$this->logger->warning('AI bot: document job ' . $job['id'] . ' was lost while running; ' . ($again ? 'it starts again' : 'given up'));
			try {
				$this->conversation->postText($this->conversation->room($job['token']), ($again ? '🔁 ' : '⚠️ ') . '„' . $job['name'] . '“: '
					. ($again ? 'the server restarted during the job; it starts again.' : 'the job was lost twice and is given up.'), $job['message_id']);
			} catch (\Throwable) {
			}
		}
		$health = $this->sandbox->health();
		if ($health === null) {
			return;
		}
		$free = max(0, (int)$health['max_jobs'] - (int)$health['jobs']);
		foreach ($this->jobs->queued($free) as $job) {
			$this->dispatcher->dispatchDocument($job['id']);
		}
	}
}
