<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\IConfig;
use OCP\L10N\IFactory;

/**
 * The bot's own texts (status, notes, help, errors) in the person's language,
 * through Nextcloud's translations (l10n/, kept on Transifex like other apps):
 * German and Polish, English otherwise. The AI's answers follow the language of
 * the message instead.
 */
class Texts {
	public function __construct(
		private IFactory $l10n,
		private IConfig $config,
	) {
	}

	public function t(string $userId, string $text, array $parameters = []): string {
		$default = $this->config->getSystemValueString('default_language', 'en') ?: 'en';
		$language = $userId === '' || str_contains($userId, ':') ? $default : (string)$this->config->getUserValue($userId, 'core', 'lang', $default);
		return $this->l10n->get(Application::APP_ID, $language)->t($text, $parameters);
	}
}
