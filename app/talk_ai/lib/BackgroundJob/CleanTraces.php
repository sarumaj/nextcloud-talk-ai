<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\BackgroundJob;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Service\Actions;
use OCA\TalkAi\Service\Jobs;
use OCA\TalkAi\Service\Requests;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The retention of what the app keeps, every six hours:
 * - the protocols of document jobs after trace_days (default 14, at least 7);
 * - job records, answer records, daily use and conversation memories after
 *   retention_days (default 90);
 * - undo records after a week (undo works for a day);
 * - delivered documents after retention_files_days, when set (default 0: never;
 *   they are the people's files);
 * - hand-offs to background jobs never picked up, after a day.
 * Sandbox containers are removed by the runner itself when their time is up.
 */
class CleanTraces extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
		private Jobs $jobs,
		private Requests $requests,
		private Actions $actions,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 3600);
	}

	protected function run($argument): void {
		$now = $this->time->getTime();
		$traceDays = max(7, $this->days('trace_days', 14));
		$keepDays = max($traceDays, $this->days('retention_days', 90));
		$fileDays = $this->days('retention_files_days', 0);

		// Protocols (and their page images and previews).
		foreach ($this->jobs->older($now - $traceDays * 86400, 500) as $job) {
			if (($job['trace_path'] ?? '') !== '' && !($job['options']['trace_removed'] ?? false)) {
				$this->remove((string)$job['user_id'], (string)$job['trace_path']);
				$this->jobs->setOption($job['id'], 'trace_removed', true);
			}
		}
		// Older protocols listed by earlier versions of the app.
		$traces = json_decode($this->appConfig->getValueString(Application::APP_ID, 'traces', '[]'), true) ?: [];
		if ($traces !== []) {
			foreach ($traces as $trace) {
				if ((int)($trace['time'] ?? 0) < $now - $traceDays * 86400) {
					$this->remove((string)$trace['user'], (string)$trace['path']);
				}
			}
			$this->appConfig->deleteKey(Application::APP_ID, 'traces');
		}
		if ($fileDays > 0) {
			foreach ($this->jobs->older($now - $fileDays * 86400, 500) as $job) {
				if (!($job['options']['files_removed'] ?? false) && $job['delivered'] !== []) {
					foreach ($job['delivered'] as $file) {
						$this->remove((string)$job['user_id'], '', (int)$file['id']);
					}
					$this->jobs->setOption($job['id'], 'files_removed', true);
				}
			}
		}
		foreach ($this->jobs->older($now - $keepDays * 86400, 1000) as $job) {
			$this->jobs->delete($job['id']);
		}
		$this->requests->prune($now - $keepDays * 86400);
		$this->jobs->pruneUsage($keepDays);
		$this->actions->prune($now - 7 * 86400);
		foreach ($this->appConfig->getKeys(Application::APP_ID) as $key) {
			if (str_starts_with($key, 'memory_')) {
				$memory = json_decode($this->appConfig->getValueString(Application::APP_ID, $key, '{}', lazy: true), true);
				if ((int)($memory['time'] ?? $now) < $now - $keepDays * 86400) {
					$this->appConfig->deleteKey(Application::APP_ID, $key);
				}
			} elseif (preg_match('/^pending_(\d+)_/', $key, $m) === 1 && (int)$m[1] < $now - 86400) {
				$this->appConfig->deleteKey(Application::APP_ID, $key);
			}
		}
	}

	private function remove(string $userId, string $path, int $id = 0): void {
		try {
			$folder = $this->rootFolder->getUserFolder($userId);
			$node = $id > 0 ? $folder->getFirstNodeById($id) : ($folder->nodeExists($path) ? $folder->get($path) : null);
			$node?->delete();
		} catch (\Throwable $e) {
			$this->logger->info('AI bot: not removed under retention: ' . $e->getMessage());
		}
	}

	private function days(string $key, int $default): int {
		return max(0, (int)$this->appConfig->getValueString(Application::APP_ID, $key, (string)$default));
	}
}
