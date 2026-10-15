<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Who may use what: features switched on per group, and the house styles of the
 * groups.
 *
 * App settings: features, a JSON object of feature => list of groups (empty or
 * missing: everyone); the features are documents (files written in the chat),
 * sandbox (documents built with code), web (search, pages, citations) and calendar.
 * The older document_groups still limits the sandbox when features has no entry.
 * styles, a JSON list of house styles: {name, groups, description, palette (colours),
 * fonts {heading, body}, templates {format: file name}, logo (file name); the files are
 * in the app data (styles/<name>/), uploaded in the administration}.
 */
class Policy {
	public const FEATURES = ['documents', 'sandbox', 'web', 'calendar'];

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groups,
		private IUserManager $users,
	) {
	}

	public function allows(string $userId, string $feature): bool {
		$features = json_decode($this->appConfig->getValueString(Application::APP_ID, 'features', '{}'), true);
		$groups = is_array($features) && array_key_exists($feature, $features) ? $features[$feature] : null;
		if ($groups === null && $feature === 'sandbox') {
			$groups = json_decode($this->appConfig->getValueString(Application::APP_ID, 'document_groups', '[]'), true);
		}
		if (!is_array($groups) || $groups === []) {
			return true;
		}
		return array_intersect($groups, $this->userGroups($userId)) !== [];
	}

	/** Whether a feature is limited to some groups (for youth mode: on only where groups are named). */
	public function restricted(string $feature): bool {
		$features = json_decode($this->appConfig->getValueString(Application::APP_ID, 'features', '{}'), true);
		return is_array($features) && !empty($features[$feature]);
	}

	/** @return list<array> the house styles the person may use, theirs first */
	public function styles(string $userId): array {
		$styles = json_decode($this->appConfig->getValueString(Application::APP_ID, 'styles', '[]'), true);
		$mine = $this->userGroups($userId);
		$out = [];
		foreach (is_array($styles) ? $styles : [] as $style) {
			if (!is_array($style) || trim((string)($style['name'] ?? '')) === '') {
				continue;
			}
			$groups = (array)($style['groups'] ?? []);
			if ($groups === [] || array_intersect($groups, $mine) !== []) {
				$out[] = $style;
			}
		}
		return $out;
	}

	/** A house style by name (case-insensitive) or, without a name, the person's default; null if none. */
	public function style(string $userId, string $name = ''): ?array {
		foreach ($this->styles($userId) as $style) {
			if ($name === '' || mb_strtolower(trim((string)$style['name'])) === mb_strtolower(trim($name))) {
				return $style;
			}
		}
		return null;
	}

	private function userGroups(string $userId): array {
		$user = $this->users->get($userId);
		return $user === null ? [] : $this->groups->getUserGroupIds($user);
	}
}
