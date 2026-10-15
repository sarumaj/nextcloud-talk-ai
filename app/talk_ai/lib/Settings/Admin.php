<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Settings;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Service\Settings as SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

/** The administration page (Administration → Artificial intelligence → AI in Talk). */
class Admin implements ISettings {
	public function __construct(
		private SettingsService $settings,
		private IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('settings', $this->settings->all());
		\OCP\Util::addScript(Application::APP_ID, 'admin');
		\OCP\Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', ['fields' => SettingsService::FIELDS]);
	}

	public function getSection(): string {
		return 'ai';
	}

	public function getPriority(): int {
		return 50;
	}
}
