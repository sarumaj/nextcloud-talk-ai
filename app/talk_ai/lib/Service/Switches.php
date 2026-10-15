<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The emergency stop and who may talk to the bot: off for everyone (enabled = no),
 * for conversations (rooms_off) or people (users_off), both JSON lists; guests and
 * federated participants only when allow_guests = yes.
 */
class Switches {
	public function __construct(
		private IAppConfig $appConfig,
		private Jobs $jobs,
	) {
	}

	/** Why the bot does not answer here ('' when it does): off, room, user. */
	public function off(string $token, string $userId): string {
		if ($this->appConfig->getValueString(Application::APP_ID, 'enabled', 'yes') === 'no') {
			return 'off';
		}
		if (in_array($token, $this->list('rooms_off'), true)) {
			return 'room';
		}
		if (in_array($userId, $this->list('users_off'), true)) {
			return 'user';
		}
		return '';
	}

	public function guestsAllowed(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'allow_guests', 'no') === 'yes';
	}

	/** Stops the bot for everyone, a conversation or a person; running jobs there are cancelled. */
	public function stop(string $scope, string $id = ''): int {
		if ($scope === 'all') {
			$this->appConfig->setValueString(Application::APP_ID, 'enabled', 'no');
		} else {
			$key = $scope === 'room' ? 'rooms_off' : 'users_off';
			$this->appConfig->setValueString(Application::APP_ID, $key, json_encode(array_values(array_unique([...$this->list($key), $id]))));
		}
		$cancelled = 0;
		foreach ($this->jobs->recent(500) as $job) {
			if (in_array($job['status'], [Jobs::QUEUED, Jobs::RUNNING], true)
				&& ($scope === 'all' || ($scope === 'room' && $job['token'] === $id) || ($scope === 'user' && $job['user_id'] === $id))) {
				$this->jobs->cancel($job['id']);
				$cancelled++;
			}
		}
		return $cancelled;
	}

	public function start(string $scope, string $id = ''): void {
		if ($scope === 'all') {
			$this->appConfig->setValueString(Application::APP_ID, 'enabled', 'yes');
			return;
		}
		$key = $scope === 'room' ? 'rooms_off' : 'users_off';
		$this->appConfig->setValueString(Application::APP_ID, $key, json_encode(array_values(array_diff($this->list($key), [$id]))));
	}

	/** @return list<string> */
	public function list(string $key): array {
		$list = json_decode($this->appConfig->getValueString(Application::APP_ID, $key, '[]'), true);
		return is_array($list) ? array_values(array_map('strval', $list)) : [];
	}
}
